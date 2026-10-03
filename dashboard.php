<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/kardex_functions.php';

$tituloPagina = 'Inicio';
$activo = 'dashboard';

/** Suma las cuentas de un grupo usando el signo de clasificación contable (una sola consulta). */
function totalGrupoDashboard(mysqli $conn, string $digito): float
{
    return sumarSaldos(cuentasConSaldoPorPrefijo($conn, $digito));
}

$totalActivo = totalGrupoDashboard($conn, '1');
$totalPasivo = totalGrupoDashboard($conn, '2');
$totalPatrimonioBase = totalGrupoDashboard($conn, '3');
$totalCostos = totalGrupoDashboard($conn, '4');
$totalIngresos = totalGrupoDashboard($conn, '5');
$utilidadEjercicio = round($totalIngresos - $totalCostos, 2);
$totalPatrimonio = round($totalPatrimonioBase + $utilidadEjercicio, 2);
$cuadre = round($totalActivo - ($totalPasivo + $totalPatrimonio), 2);

$numAsientos = 0;
$res = $conn->query('SELECT COUNT(*) AS n FROM asientos');
if ($res && ($fila = $res->fetch_assoc())) {
    $numAsientos = (int)$fila['n'];
    $res->free();
}

$numProductos = 0;
$productosStockBajo = 0;
$totalUnidades = 0.0;
$totalValorKardex = 0.0;
$res = $conn->query('SELECT id, cantidad, stock_minimo FROM productos WHERE activo = 1');
if ($res) {
    while ($producto = $res->fetch_assoc()) {
        $numProductos++;
        $saldo = obtenerSaldoActual($conn, (int)$producto['id']);
        $totalUnidades += $saldo['unidades'];
        $totalValorKardex += $saldo['valor'];
        if ($saldo['unidades'] <= (float)$producto['stock_minimo']) {
            $productosStockBajo++;
        }
    }
    $res->free();
}
$totalValorKardex = round($totalValorKardex, 2);

$saldoContableInventario = sumarSaldos(cuentasConSaldoPorPrefijo($conn, '1105'));
$saldoContableInventario = round($saldoContableInventario, 2);
$diferenciaInventario = round($saldoContableInventario - $totalValorKardex, 2);

$ultimosAsientos = [];
$res = $conn->query("
    SELECT a.id, a.numero_partida, a.fecha, a.concepto,
           COALESCE(SUM(d.debe), 0) AS total_debe
    FROM asientos a
    INNER JOIN detalle_asientos d ON d.asiento_id = a.id
    GROUP BY a.id, a.numero_partida, a.fecha, a.concepto
    ORDER BY a.fecha DESC, a.numero_partida DESC
    LIMIT 5
");
if ($res) {
    $ultimosAsientos = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();
}

// Porcentajes para las barras de estado visual.
// La barra compara el activo contra pasivo + patrimonio, así que ambos
// segmentos se normalizan contra la suma para que llenen siempre el 100%
// y la proporción siga siendo legible aunque el balance esté descuadrado.
$totalPasivoPatrimonio = round($totalPasivo + $totalPatrimonio, 2);
$baseBarra = abs($totalActivo) + abs($totalPasivoPatrimonio);
$pctActivo = $baseBarra > 0
    ? round(abs($totalActivo) / $baseBarra * 100, 1)
    : 0.0;
$pctPasivoPatrimonio = $baseBarra > 0
    ? round(abs($totalPasivoPatrimonio) / $baseBarra * 100, 1)
    : 0.0;
$pctStockBajo = $numProductos > 0
    ? min(100, round($productosStockBajo / $numProductos * 100, 1))
    : 0.0;

require_once __DIR__ . '/includes/header.php';
?>

<section class="dash-page">

    <!-- ===================================================
         PORTADA
         =================================================== -->

    <div class="hero dash-hero">

        <div class="hero-content">

            <span class="hero-tag">MÓDULO DE CONTABILIDAD</span>

            <h1>Bienvenido, <?php echo htmlspecialchars($_SESSION['nombre_completo'] ?? 'Contador'); ?></h1>

            <p>
                Consulta tus saldos, registra partidas y mantén el inventario y los
                estados financieros bajo control.
            </p>

            <div class="dash-hero-actions">

                <a href="libro_diario.php" class="btn">✍ Registrar asiento</a>

                <a href="inventario.php" class="btn secondary">📦 Administrar inventario</a>

                <a href="estados_financieros.php" class="btn celeste">📑 Estados financieros</a>

            </div>

        </div>

        <div class="dash-hero-side">

            <div class="dash-hero-date">

                <span>Hoy</span>

                <strong><?php echo date('d/m/Y'); ?></strong>

            </div>

            <div class="hero-graphic">

                <div class="hero-icon">📒</div>

                <div class="hero-icon">🧮</div>

                <div class="hero-icon">📊</div>

            </div>

        </div>

    </div>


    <!-- ===================================================
         INDICADORES
         =================================================== -->

    <div class="dash-kpis">

        <div class="kpi dash-kpi">

            <span class="dash-kpi-icon">🧾</span>

            <div class="label">Partidas registradas</div>

            <div class="value"><?php echo number_format($numAsientos); ?></div>

            <div class="dash-kpi-note">Movimientos en el libro diario</div>

        </div>

        <div class="kpi dash-kpi">

            <span class="dash-kpi-icon">💼</span>

            <div class="label">Total activo</div>

            <div class="value"><?php echo fmt($totalActivo); ?></div>

            <div class="dash-kpi-note">Recursos y existencias</div>

        </div>

        <div class="kpi dash-kpi">

            <span class="dash-kpi-icon">📄</span>

            <div class="label">Total pasivo</div>

            <div class="value"><?php echo fmt($totalPasivo); ?></div>

            <div class="dash-kpi-note">Obligaciones con terceros</div>

        </div>

        <div class="kpi dash-kpi">

            <span class="dash-kpi-icon">🏛️</span>

            <div class="label">Patrimonio</div>

            <div class="value"><?php echo fmt($totalPatrimonio); ?></div>

            <div class="dash-kpi-note">Capital más utilidad</div>

        </div>

        <div class="kpi dash-kpi">

            <span class="dash-kpi-icon">📦</span>

            <div class="label">Productos activos</div>

            <div class="value"><?php echo number_format($numProductos); ?></div>

            <div class="dash-kpi-note"><?php echo number_format($totalUnidades, 2); ?> unidades en existencia</div>

        </div>

        <div class="kpi dash-kpi <?php echo $utilidadEjercicio >= 0 ? 'ok' : 'warn'; ?>">

            <span class="dash-kpi-icon"><?php echo $utilidadEjercicio >= 0 ? '📈' : '📉'; ?></span>

            <div class="label"><?php echo $utilidadEjercicio >= 0 ? 'Utilidad' : 'Pérdida'; ?> del ejercicio</div>

            <div class="value"><?php echo fmt(abs($utilidadEjercicio)); ?></div>

            <div class="dash-kpi-note">Costos <?php echo fmt($totalCostos); ?></div>

        </div>

    </div>


    <!-- ===================================================
         ESTADO FINANCIERO E INVENTARIO
         =================================================== -->

    <div class="dash-grid">

        <div class="card dash-card">

            <div class="dash-card-head">

                <div>

                    <h2>Estado financiero</h2>

                    <p>Activo frente a pasivo más patrimonio</p>

                </div>

                <a class="dash-card-link" href="estados_financieros.php">
                    Ver reporte →
                </a>

            </div>

            <div class="dash-meter">

                <div class="dash-meter-bar">

                    <div class="dash-meter-fill activo" style="width:<?php echo $pctActivo; ?>%"></div>

                    <div class="dash-meter-fill pasivo" style="width:<?php echo $pctPasivoPatrimonio; ?>%"></div>

                </div>

            </div>

            <div class="dash-legend">

                <div class="dash-legend-item">

                    <span class="dot activo"></span>

                    <span>Total activo</span>

                    <strong><?php echo fmt($totalActivo); ?></strong>

                </div>

                <div class="dash-legend-item">

                    <span class="dot pasivo"></span>

                    <span>Pasivo + patrimonio</span>

                    <strong><?php echo fmt($totalPasivoPatrimonio); ?></strong>

                </div>

            </div>

            <?php if (abs($cuadre) < 0.01): ?>

                <p class="dash-status ok">✔ La contabilidad está cuadrada</p>

            <?php else: ?>

                <p class="dash-status warn">
                    ✕ Revisa los asientos: diferencia de <?php echo fmt($cuadre); ?>
                </p>

            <?php endif; ?>

        </div>

        <div class="card dash-card">

            <div class="dash-card-head">

                <div>

                    <h2>Inventario</h2>

                    <p>Existencias valuadas según el Kardex</p>

                </div>

                <a class="dash-card-link" href="inventario.php">
                    Administrar →
                </a>

            </div>

            <div class="dash-metrics">

                <div class="dash-metric">

                    <span>Productos activos</span>

                    <strong><?php echo number_format($numProductos); ?></strong>

                </div>

                <div class="dash-metric">

                    <span>Unidades</span>

                    <strong><?php echo number_format($totalUnidades, 2); ?></strong>

                </div>

                <div class="dash-metric">

                    <span>Valor Kardex</span>

                    <strong><?php echo fmt($totalValorKardex); ?></strong>

                </div>

            </div>

            <div class="dash-stock">

                <div class="dash-stock-head">

                    <span>Productos en stock bajo o igual al mínimo</span>

                    <strong><?php echo (int)$productosStockBajo; ?> de <?php echo (int)$numProductos; ?></strong>

                </div>

                <div class="dash-meter-bar small">

                    <div
                        class="dash-meter-fill stock <?php echo $pctStockBajo > 0 ? 'warn' : 'ok'; ?>"
                        style="width:<?php echo $pctStockBajo; ?>%"
                    ></div>

                </div>

            </div>

            <?php if (abs($diferenciaInventario) < 0.01): ?>

                <p class="dash-status ok">✔ Inventario conciliado con la contabilidad</p>

            <?php else: ?>

                <p class="dash-status warn">
                    Diferencia pendiente de asiento: <?php echo fmt($diferenciaInventario); ?>
                </p>

            <?php endif; ?>

        </div>

    </div>


    <!-- ===================================================
         ÚLTIMOS ASIENTOS
         =================================================== -->

    <div class="card dash-card">

        <div class="dash-card-head">

            <div>

                <h2>Últimos asientos</h2>

                <p>Los 5 movimientos más recientes del libro diario</p>

            </div>

            <a class="dash-card-link" href="libro_diario.php">
                Ver Libro Diario →
            </a>

        </div>

        <?php if ($ultimosAsientos): ?>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Partida</th>
                            <th>Fecha</th>
                            <th>Concepto</th>
                            <th class="text-right">Debe</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ultimosAsientos as $asiento): ?>
                            <tr>
                                <td>#<?php echo (int)$asiento['numero_partida']; ?></td>
                                <td><?php echo htmlspecialchars(date('d/m/Y', strtotime($asiento['fecha']))); ?></td>
                                <td><?php echo htmlspecialchars($asiento['concepto']); ?></td>
                                <td class="text-right"><?php echo fmt($asiento['total_debe']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>

            <p class="help-text">Todavía no existen asientos registrados.</p>

        <?php endif; ?>

    </div>


</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>