<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/functions.php';

$tituloPagina = 'Cierre Contable';
$activo = 'cierre';

$mensaje = '';
$error = '';

// Mensaje de éxito guardado antes de redirigir (evita reenviar el formulario al recargar).
if (isset($_SESSION['cierre_ok'])) {
    $mensaje = (string)$_SESSION['cierre_ok'];
    unset($_SESSION['cierre_ok']);
}

/** Id de una cuenta activa y con movimiento; falla si no existe. */
function cuentaIdPorCodigo(mysqli $conn, string $codigo): int
{
    $stmt = $conn->prepare(
        "SELECT id FROM cuentas WHERE codigo = ? AND permite_movimiento = 1 AND activa = 1 LIMIT 1"
    );
    $stmt->bind_param('s', $codigo);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new Exception("No se encontró la cuenta {$codigo} (activa y con movimiento).");
    }
    return (int)$row['id'];
}

// Procesar cierre si se envió el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenCsrf = $_POST['_csrf'] ?? null;
    if (!verificarCsrf(is_string($tokenCsrf) ? $tokenCsrf : null, 'cierre')) {
        $error = 'La sesión del formulario venció. Actualiza la página e intenta nuevamente.';
    } else {
        $fechaCierre = textoLimpio($_POST['fecha_cierre'] ?? '');
        $concepto = textoLimpio($_POST['concepto'] ?? '');

        if ($fechaCierre === '' || !fechaValida($fechaCierre)) {
            $error = 'Debe indicar una fecha de cierre válida.';
        } elseif ($concepto === '' || longitudTexto($concepto) > 230) {
            $error = 'Debe indicar un concepto para el asiento de cierre (máximo 230 caracteres).';
        } else {
            try {
                $conn->begin_transaction();

                // No se permite cerrar una fecha igual o anterior a un cierre existente.
                $stmt = $conn->prepare(
                    "SELECT COUNT(*) AS total FROM cierres_contables WHERE fecha_cierre >= ? FOR UPDATE"
                );
                $stmt->bind_param('s', $fechaCierre);
                $stmt->execute();
                $result = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ((int)$result['total'] > 0) {
                    throw new Exception('Ya existe un cierre contable en una fecha igual o posterior a la indicada.');
                }

                // Saldos acumulados hasta la fecha de cierre. Los cierres anteriores ya
                // dejaron en cero sus cuentas de resultado, por lo que no se duplican.
                $ingresos     = cuentasConSaldoPorPrefijo($conn, '5', null, null, $fechaCierre);
                $costosGastos = cuentasConSaldoPorPrefijo($conn, '4', null, null, $fechaCierre);

                $totalIngresos = sumarSaldos($ingresos);
                $totalCostosGastos = sumarSaldos($costosGastos);
                $utilidad = round($totalIngresos - $totalCostosGastos, 2);

                // Líneas del asiento: [cuenta_id, detalle, debe, haber]
                $lineas = [];
                $add = function (int $cuentaId, string $detalle, float $debe, float $haber) use (&$lineas) {
                    $debe = round($debe, 2);
                    $haber = round($haber, 2);
                    if ($debe > 0 || $haber > 0) {
                        $lineas[] = [$cuentaId, $detalle, $debe, $haber];
                    }
                };

                // 1) Resultado de ejercicios anteriores: 3202 -> 3201 y 3204 -> 3203.
                //    Así 3202/3204 sólo contienen el resultado del ejercicio que se cierra.
                foreach (cuentasConSaldoPorPrefijo($conn, '3202', null, null, $fechaCierre) as $c) {
                    $destino = cuentaIdPorCodigo($conn, '3201');
                    $c['saldo'] > 0
                        ? $add($c['id'], 'Traslado de utilidad de ejercicios anteriores', $c['saldo'], 0)
                        : $add($c['id'], 'Traslado de utilidad de ejercicios anteriores', 0, -$c['saldo']);
                    $c['saldo'] > 0
                        ? $add($destino, 'Utilidad de ejercicios anteriores', 0, $c['saldo'])
                        : $add($destino, 'Utilidad de ejercicios anteriores', -$c['saldo'], 0);
                }
                foreach (cuentasConSaldoPorPrefijo($conn, '3204', null, null, $fechaCierre) as $c) {
                    $destino = cuentaIdPorCodigo($conn, '3203');
                    // Déficit: saldo deudor => saldo (haber - debe) negativo
                    $c['saldo'] < 0
                        ? $add($c['id'], 'Traslado de déficit de ejercicios anteriores', 0, -$c['saldo'])
                        : $add($c['id'], 'Traslado de déficit de ejercicios anteriores', $c['saldo'], 0);
                    $c['saldo'] < 0
                        ? $add($destino, 'Déficit de ejercicios anteriores', -$c['saldo'], 0)
                        : $add($destino, 'Déficit de ejercicios anteriores', 0, $c['saldo']);
                }

                // 2) Cerrar ingresos: saldo > 0 (acreedor) va al Debe; una cuenta de
                //    naturaleza contraria (p. ej. rebajas y devoluciones) va al Haber.
                foreach ($ingresos as $c) {
                    $c['saldo'] > 0
                        ? $add($c['id'], 'Cierre de ingresos', $c['saldo'], 0)
                        : $add($c['id'], 'Cierre de ingresos', 0, -$c['saldo']);
                }

                // 3) Cerrar costos y gastos: saldo > 0 (deudor) va al Haber; una cuenta
                //    con saldo acreedor va al Debe.
                foreach ($costosGastos as $c) {
                    $c['saldo'] > 0
                        ? $add($c['id'], 'Cierre de costos y gastos', 0, $c['saldo'])
                        : $add($c['id'], 'Cierre de costos y gastos', -$c['saldo'], 0);
                }

                // 4) Pérdidas y Ganancias (6101) recibe la diferencia y se salda contra
                //    el patrimonio (3202 utilidad / 3204 déficit).
                if ($utilidad != 0.0) {
                    $pygId = cuentaIdPorCodigo($conn, '6101');
                    $patrimonioId = cuentaIdPorCodigo($conn, $utilidad > 0 ? '3202' : '3204');
                    $m = abs($utilidad);

                    if ($utilidad > 0) {
                        $add($pygId, 'Resultado del ejercicio a Pérdidas y Ganancias', 0, $m);
                        $add($pygId, 'Cierre de Pérdidas y Ganancias - Utilidad', $m, 0);
                        $add($patrimonioId, 'Resultado del ejercicio - Utilidad', 0, $m);
                    } else {
                        $add($pygId, 'Resultado del ejercicio a Pérdidas y Ganancias', $m, 0);
                        $add($pygId, 'Cierre de Pérdidas y Ganancias - Pérdida', 0, $m);
                        $add($patrimonioId, 'Resultado del ejercicio - Pérdida', $m, 0);
                    }
                }

                if (!$lineas) {
                    throw new Exception('No hay saldos de ingresos, costos o gastos que cerrar a la fecha indicada.');
                }

                // Partida doble: se valida SIEMPRE antes de confirmar.
                $totalDebeCent = 0;
                $totalHaberCent = 0;
                foreach ($lineas as $l) {
                    $totalDebeCent += (int)round($l[2] * 100);
                    $totalHaberCent += (int)round($l[3] * 100);
                }
                if ($totalDebeCent !== $totalHaberCent) {
                    throw new Exception('El asiento de cierre no cumple la partida doble; no se registró nada.');
                }

                // Encabezado del asiento
                $numeroPartida = siguienteNumeroPartida($conn);
                $conceptoAsiento = "Cierre contable - {$concepto}";
                $usuarioId = (int)$_SESSION['usuario_id'];

                $stmt = $conn->prepare(
                    "INSERT INTO asientos (numero_partida, fecha, concepto, usuario_id) VALUES (?, ?, ?, ?)"
                );
                $stmt->bind_param('issi', $numeroPartida, $fechaCierre, $conceptoAsiento, $usuarioId);
                if (!$stmt->execute()) {
                    throw new Exception('No se pudo guardar el encabezado del asiento de cierre.');
                }
                $asientoId = (int)$conn->insert_id;
                $stmt->close();

                // Detalle
                $stmtLinea = $conn->prepare(
                    "INSERT INTO detalle_asientos (asiento_id, cuenta_id, detalle, debe, haber) VALUES (?, ?, ?, ?, ?)"
                );
                foreach ($lineas as [$cuentaId, $detalle, $debe, $haber]) {
                    $stmtLinea->bind_param('iisdd', $asientoId, $cuentaId, $detalle, $debe, $haber);
                    if (!$stmtLinea->execute()) {
                        throw new Exception('No se pudo guardar una línea del asiento de cierre.');
                    }
                }
                $stmtLinea->close();

                // Registrar el cierre
                $stmt = $conn->prepare("
                    INSERT INTO cierres_contables (fecha_cierre, concepto, utilidad_perdida, asiento_id, usuario_id)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->bind_param('ssdii', $fechaCierre, $concepto, $utilidad, $asientoId, $usuarioId);
                if (!$stmt->execute()) {
                    throw new Exception('No se pudo registrar el cierre contable.');
                }
                $stmt->close();

                $conn->commit();

                $tipoResultado = $utilidad > 0 ? 'utilidad' : ($utilidad < 0 ? 'pérdida' : 'resultado nulo');
                $mensaje = "Cierre contable realizado exitosamente (partida No. {$numeroPartida}). "
                    . ($utilidad == 0.0
                        ? 'Los ingresos igualaron a los costos y gastos: resultado de $0.00.'
                        : "Se registró una {$tipoResultado} de " . fmt(abs($utilidad)) . '.');

                // Post/Redirect/Get: recargar la página no vuelve a procesar el cierre.
                $_SESSION['cierre_ok'] = $mensaje;
                header('Location: cierre_contable.php');
                exit;

            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                error_log('Cierre contable (BD): ' . $e->getMessage());
                $error = 'Error de base de datos al realizar el cierre. No se registró ningún cambio.';
            } catch (Throwable $e) {
                $conn->rollback();
                $error = 'Error al realizar el cierre: ' . $e->getMessage();
            }
        }
    }
}

$ultimoCierre = ultimaFechaCierre($conn);
$fechaMinima = $ultimoCierre !== null
    ? date('Y-m-d', strtotime($ultimoCierre . ' +1 day'))
    : '';

// Obtener cierres anteriores
$sqlCierres = "SELECT c.*, u.nombre_completo AS usuario_nombre
               FROM cierres_contables c
               INNER JOIN usuarios u ON u.id = c.usuario_id
               ORDER BY c.fecha_cierre DESC";
$resCierres = $conn->query($sqlCierres);
$cierresAnteriores = [];
while ($row = $resCierres->fetch_assoc()) {
    $cierresAnteriores[] = $row;
}

require_once 'includes/header.php';
?>

<h1 class="page-title">Cierre Contable</h1>

<div class="card diario-hero-card">

    <div class="diario-hero-copy">

        <p class="diario-kicker">Fin del ejercicio</p>

        <h2>Cierre Contable Automático</h2>

        <p class="help-text">
            Realiza el cierre del ejercicio contable generando automáticamente
            el asiento de cierre de cuentas de resultados y su transferencia
            a las cuentas de patrimonio.
        </p>

        <div class="diario-compliance" aria-label="Normativa contable aplicada">
            <span>✔ NIIF para PYMES</span>
            <span>✔ Código de Comercio</span>
            <span>✔ Partida doble</span>
        </div>

    </div>

</div>

<?php if ($mensaje !== ''): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($mensaje); ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="card">

    <div class="diario-card-header">
        <div>
            <p class="diario-kicker">Nuevo cierre</p>
            <h2>Realizar Cierre Contable</h2>
        </div>
        <span class="diario-badge">Asiento automático</span>
    </div>

    <form method="POST" class="row-flex estados-filter-form"
          onsubmit="return confirm('El cierre es definitivo: bloqueará todos los asientos con fecha igual o anterior a la fecha indicada. ¿Deseas continuar?');">
        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrfToken('cierre'), ENT_QUOTES, 'UTF-8'); ?>">

        <div>
            <label for="fecha_cierre">Fecha de cierre</label>
            <input type="date" id="fecha_cierre" name="fecha_cierre" required<?php echo $fechaMinima !== '' ? ' min="' . htmlspecialchars($fechaMinima, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
        </div>

        <div style="flex: 1;">
            <label for="concepto">Concepto</label>
            <input type="text" id="concepto" name="concepto" placeholder="Ej: Cierre ejercicio 2024" required>
        </div>

        <div class="estados-filter-actions">
            <button type="submit" class="btn">Realizar cierre</button>
        </div>
    </form>

    <?php if ($ultimoCierre !== null): ?>
        <p class="help-text" style="margin-top: 12px;">Último cierre: <strong><?php echo htmlspecialchars(date('d/m/Y', strtotime($ultimoCierre))); ?></strong>. El período hasta esa fecha está bloqueado para nuevos asientos.</p>
    <?php endif; ?>

    <div class="help-text" style="margin-top: 16px;">
        <strong>El cierre contable:</strong>
        <ul style="margin: 8px 0 0 20px;">
            <li>Cierra todas las cuentas de ingresos (grupo 5) y de costos y gastos (grupo 4), incluidas las de saldo contrario como rebajas y devoluciones</li>
            <li>Transfiere el resultado a Pérdidas y Ganancias (6101) y lo salda contra el patrimonio (3202 utilidad o 3204 déficit)</li>
            <li>Traslada el resultado de cierres anteriores a Utilidades/Déficit de ejercicios anteriores (3201 / 3203)</li>
            <li>Valida que el asiento cumpla la partida doble antes de guardarlo y lo registra en el Libro Diario</li>
            <li>Bloquea el registro de asientos con fecha igual o anterior al último cierre</li>
        </ul>
    </div>

</div>

<?php if (!empty($cierresAnteriores)): ?>

<div class="card">

    <div class="diario-card-header">
        <div>
            <p class="diario-kicker">Historial</p>
            <h2>Cierres Anteriores</h2>
        </div>
        <span class="diario-badge"><?php echo count($cierresAnteriores); ?> cierre(s)</span>
    </div>

    <div class="table-scroll">
        <table class="estados-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Concepto</th>
                    <th>Resultado</th>
                    <th>Asiento</th>
                    <th>Usuario</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cierresAnteriores as $cierre): ?>
                    <tr>
                        <td><?php echo date('d/m/Y', strtotime($cierre['fecha_cierre'])); ?></td>
                        <td><?php echo htmlspecialchars($cierre['concepto']); ?></td>
                        <td class="text-right">
                            <?php
                            $esUtilidad = (float)$cierre['utilidad_perdida'] >= 0;
                            echo '<span style="color: ' . ($esUtilidad ? '#16a34a' : '#dc2626') . ';">';
                            echo fmt((float)$cierre['utilidad_perdida']);
                            echo '</span>';
                            ?>
                        </td>
                        <td>#<?php echo (int)$cierre['asiento_id']; ?></td>
                        <td><?php echo htmlspecialchars($cierre['usuario_nombre']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>

<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>