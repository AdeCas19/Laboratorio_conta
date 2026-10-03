<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/functions.php';

$tituloPagina = 'Balance de Comprobación';
$activo = 'balance';

$desde = textoLimpio($_GET['desde'] ?? '');
$hasta = textoLimpio($_GET['hasta'] ?? '');
$periodoInvalido = ($desde !== '' && !fechaValida($desde))
    || ($hasta !== '' && !fechaValida($hasta))
    || ($desde !== '' && $hasta !== '' && $desde > $hasta);
if ($periodoInvalido) {
    $desde = '';
    $hasta = '';
}
$desde = $desde !== '' ? $desde : null;
$hasta = $hasta !== '' ? $hasta : null;

// Obtener todas las cuentas con movimiento en el período
$sql = "
    SELECT DISTINCT
        c.id,
        c.codigo,
        c.nombre,
        c.naturaleza,
        c.tipo,
        c.nivel
    FROM cuentas c
    INNER JOIN detalle_asientos d ON d.cuenta_id = c.id
    INNER JOIN asientos a ON a.id = d.asiento_id
    WHERE c.permite_movimiento = 1
      AND c.activa = 1
";
if ($desde !== null) {
    $sql .= " AND a.fecha >= '{$desde}'";
}
if ($hasta !== null) {
    $sql .= " AND a.fecha <= '{$hasta}'";
}
$sql .= " ORDER BY c.codigo ASC";

$res = $conn->query($sql);
$cuentas = [];
while ($row = $res->fetch_assoc()) {
    $cuentas[] = $row;
}

// Calcular saldos para cada cuenta
$balance = [];
$totalDebe = 0;
$totalHaber = 0;

foreach ($cuentas as $cuenta) {
    $saldos = saldoCuenta($conn, $cuenta['id'], $cuenta['naturaleza'], $desde, $hasta);
    $debe = $saldos['debe'];
    $haber = $saldos['haber'];
    $saldo = $saldos['saldo'];

    // En el balance de comprobación, mostramos el saldo en Debe o Haber según su naturaleza
    if ($cuenta['naturaleza'] === 'deudora') {
        $saldoDebe = $saldo > 0 ? $saldo : 0;
        $saldoHaber = $saldo < 0 ? abs($saldo) : 0;
    } else {
        $saldoDebe = $saldo < 0 ? abs($saldo) : 0;
        $saldoHaber = $saldo > 0 ? $saldo : 0;
    }

    // Solo incluir cuentas con saldo
    if (abs($saldo) < 0.005) continue;

    $balance[] = [
        'codigo' => $cuenta['codigo'],
        'nombre' => $cuenta['nombre'],
        'naturaleza' => $cuenta['naturaleza'],
        'tipo' => $cuenta['tipo'],
        'nivel' => $cuenta['nivel'],
        'debe' => $saldoDebe,
        'haber' => $saldoHaber,
        'saldo' => $saldo,
    ];

    $totalDebe += $saldoDebe;
    $totalHaber += $saldoHaber;
}

$totalDebe = round($totalDebe, 2);
$totalHaber = round($totalHaber, 2);
$diferencia = round($totalDebe - $totalHaber, 2);
$cuadrado = abs($diferencia) < 0.01;

require_once 'includes/header.php';
?>

<h1 class="page-title">Balance de Comprobación</h1>

<div class="card diario-hero-card">

    <div class="diario-hero-copy">

        <p class="diario-kicker">Verificación contable</p>

        <h2>Balance de Comprobación</h2>

        <p class="help-text">
            Verifica que la suma de saldos deudores sea igual a la suma de saldos
            acreedores. Es el control fundamental del sistema de partida doble.
        </p>

        <div class="diario-compliance" aria-label="Normativa contable aplicada">
            <span>✔ Partida doble</span>
            <span>✔ NIIF para PYMES</span>
            <span>✔ Código de Comercio</span>
        </div>

    </div>

</div>

<div class="diario-summary-grid estados-summary-grid">

    <div class="diario-summary-card">
        <span class="diario-summary-label">Cuentas con saldo</span>
        <strong class="diario-summary-value"><?php echo count($balance); ?></strong>
        <small>En el período seleccionado</small>
    </div>

    <div class="diario-summary-card">
        <span class="diario-summary-label">Total Debe</span>
        <strong class="diario-summary-value"><?php echo fmt($totalDebe); ?></strong>
        <small>Suma de saldos deudores</small>
    </div>

    <div class="diario-summary-card">
        <span class="diario-summary-label">Total Haber</span>
        <strong class="diario-summary-value"><?php echo fmt($totalHaber); ?></strong>
        <small>Suma de saldos acreedores</small>
    </div>

    <div class="diario-summary-card diario-summary-next">
        <span class="diario-summary-label">Diferencia</span>
        <strong class="diario-summary-value"><?php echo fmt($diferencia); ?></strong>
        <small><?php echo $cuadrado ? 'Balance cuadrado' : 'Descuadre detectado'; ?></small>
    </div>

</div>

<div class="card estados-filter-card">

    <div class="diario-card-header">

        <div>
            <p class="diario-kicker">Período de análisis</p>
            <h2>Filtrar balance</h2>
        </div>

        <span class="diario-badge">Control de partida doble</span>

    </div>

    <form method="GET" class="row-flex estados-filter-form">

        <div>
            <label for="desde">Desde</label>
            <input type="date" id="desde" name="desde" value="<?php echo htmlspecialchars($desde ?? ''); ?>">
        </div>

        <div>
            <label for="hasta">Hasta</label>
            <input type="date" id="hasta" name="hasta" value="<?php echo htmlspecialchars($hasta ?? ''); ?>">
        </div>

        <div class="estados-filter-actions">
            <button type="submit" class="btn">Aplicar período</button>
            <a href="balance_comprobacion.php" class="btn secondary">Limpiar filtros</a>
        </div>

    </form>

    <?php if ($periodoInvalido): ?>
        <p class="descuadre">El período indicado no es válido. Se muestran todos los movimientos.</p>
    <?php elseif ($desde || $hasta): ?>
        <p class="help-text">Período: <?php echo htmlspecialchars(($desde ?? 'inicio') . ' al ' . ($hasta ?? 'hoy')); ?></p>
    <?php else: ?>
        <p class="help-text">Mostrando todos los movimientos registrados.</p>
    <?php endif; ?>

</div>

<?php if (empty($balance)): ?>

    <div class="card mayor-empty-card">
        <p>No existen cuentas con movimientos en el período seleccionado.</p>
    </div>

<?php else: ?>

    <div class="card">

        <div class="diario-card-header">
            <div>
                <p class="diario-kicker">Detalle por cuenta</p>
                <h2>Balance de Comprobación</h2>
            </div>
            <span class="diario-badge"><?php echo $cuadrado ? '✔ Cuadrado' : '✕ Descuadrado'; ?></span>
        </div>

        <div class="table-scroll">
            <table class="estados-table">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Cuenta</th>
                        <th class="text-right">Debe</th>
                        <th class="text-right">Haber</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($balance as $b): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($b['codigo']); ?></td>
                            <td><?php echo htmlspecialchars($b['nombre']); ?></td>
                            <td class="text-right"><?php echo $b['debe'] > 0 ? fmt($b['debe']) : ''; ?></td>
                            <td class="text-right"><?php echo $b['haber'] > 0 ? fmt($b['haber']) : ''; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td colspan="2" class="text-right"><strong>TOTALES</strong></td>
                        <td class="text-right"><strong><?php echo fmt($totalDebe); ?></strong></td>
                        <td class="text-right"><strong><?php echo fmt($totalHaber); ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p class="estados-balance-status">
            <?php if ($cuadrado): ?>
                <span class="cuadrado">✔ El Balance de Comprobación está cuadrado: Total Debe = Total Haber</span>
            <?php else: ?>
                <span class="descuadre">✕ Descuadre de <?php echo fmt($diferencia); ?> — revisa el Libro Diario</span>
            <?php endif; ?>
        </p>

    </div>

<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
