<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/functions.php';

$tituloPagina = 'Estados Financieros';
$activo = 'estados';

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

/**
 * Devuelve las cuentas con movimiento cuyo código empieza con $prefijo, con su saldo.
 * $excluirCierres omite los asientos de cierre contable: se usa en el Estado de
 * Resultados para que un período ya cerrado siga mostrando sus ingresos y gastos.
 */
function cuentasConSaldo($conn, $prefijo, $soloCorriente = null, ?string $desde = null, ?string $hasta = null, bool $excluirCierres = false) {
    return cuentasConSaldoPorPrefijo(
        $conn,
        (string)$prefijo,
        $soloCorriente === null ? null : (int)$soloCorriente,
        $desde,
        $hasta,
        $excluirCierres
    );
}
function sumar($arr) { return sumarSaldos($arr); }

// ---------- BALANCE GENERAL: código 1 (activo) = 2 (pasivo) + 3 (patrimonio) ----------
// Incluye TODOS los asientos (también los de cierre): es un saldo acumulado.
$activoCte    = cuentasConSaldo($conn, '1', 1, null, $hasta);
$activoNoCte  = cuentasConSaldo($conn, '1', 0, null, $hasta);
$totalActivo  = sumar($activoCte) + sumar($activoNoCte);

$pasivoCte    = cuentasConSaldo($conn, '2', 1, null, $hasta);
$pasivoNoCte  = cuentasConSaldo($conn, '2', 0, null, $hasta);
$totalPasivo  = sumar($pasivoCte) + sumar($pasivoNoCte);

$patrimonioCuentas = cuentasConSaldo($conn, '3', null, null, $hasta);
$totalPatrimonioBase = sumar($patrimonioCuentas);

// ---------- ESTADO DE RESULTADOS: código 5 (ingresos) - 4 (costos y gastos) = utilidad ----------
// Se excluyen los asientos de cierre: de lo contrario, en un período ya cerrado
// los ingresos y gastos aparecerían en cero porque el cierre los salda.
$ingresos = cuentasConSaldo($conn, '5', null, $desde, $hasta, true);
$costosGastos = cuentasConSaldo($conn, '4', null, $desde, $hasta, true);
$totalIngresos = sumar($ingresos);
$totalCostosGastos = sumar($costosGastos);
$utilidadEjercicio = round($totalIngresos - $totalCostosGastos, 2);

// Resultado acumulado pendiente de cierre a la fecha de corte (incluye asientos de
// cierre). Tras un cierre queda en cero porque el resultado ya está en 3202/3204,
// así que no se cuenta dos veces.
$ingresosAcumulados = cuentasConSaldo($conn, '5', null, null, $hasta);
$costosGastosAcumulados = cuentasConSaldo($conn, '4', null, null, $hasta);
$utilidadAcumulada = round(sumar($ingresosAcumulados) - sumar($costosGastosAcumulados), 2);

$totalPatrimonio = round($totalPatrimonioBase + $utilidadAcumulada, 2);
$totalPasivoPatrimonio = round($totalPasivo + $totalPatrimonio, 2);

// ---------- Verificación contra el Libro Diario ----------
// El Balance General no incluye el grupo 6 ni cuentas fuera del catálogo de
// estados, por lo que puede verse cuadrado aunque existan partidas descuadradas.
$sqlTb = "SELECT COALESCE(SUM(d.debe), 0) AS td, COALESCE(SUM(d.haber), 0) AS th
          FROM detalle_asientos d INNER JOIN asientos a ON a.id = d.asiento_id";
if ($hasta !== null) {
    $stTb = $conn->prepare($sqlTb . " WHERE a.fecha <= ?");
    $stTb->bind_param('s', $hasta);
} else {
    $stTb = $conn->prepare($sqlTb);
}
$stTb->execute();
$tbRow = $stTb->get_result()->fetch_assoc();
$stTb->close();
$difLibroDiario = round((float)$tbRow['td'] - (float)$tbRow['th'], 2);

require_once 'includes/header.php';
?>

<h1 class="page-title">Estados Financieros</h1>

<div class="card diario-hero-card">

    <div class="diario-hero-copy">

        <p class="diario-kicker">Información financiera</p>

        <h2>Estados Financieros Automáticos</h2>

        <p class="help-text">
            Balance General y Estado de Resultados generados dinámicamente
            mediante la clasificación contable por dígitos. El Balance General
            es acumulado hasta la fecha de corte y el Estado de Resultados
            corresponde al período seleccionado.
        </p>

        <div class="diario-compliance" aria-label="Normativa contable aplicada">
            <span>✔ NIIF para PYMES</span>
            <span>✔ Código de Comercio</span>
            <span>✔ El Salvador</span>
        </div>

    </div>

</div>

<div class="diario-summary-grid estados-summary-grid">

    <div class="diario-summary-card">
        <span class="diario-summary-label">Total activo</span>
        <strong class="diario-summary-value"><?php echo fmt($totalActivo); ?></strong>
        <small>Recursos de la empresa</small>
    </div>

    <div class="diario-summary-card">
        <span class="diario-summary-label">Pasivo + patrimonio</span>
        <strong class="diario-summary-value"><?php echo fmt($totalPasivoPatrimonio); ?></strong>
        <small>Fuentes de financiamiento</small>
    </div>

    <div class="diario-summary-card">
        <span class="diario-summary-label">
            <?php echo $utilidadEjercicio >= 0 ? 'Utilidad del período' : 'Pérdida del período'; ?>
        </span>
        <strong class="diario-summary-value"><?php echo fmt($utilidadEjercicio); ?></strong>
        <small>Ingresos menos costos y gastos</small>
    </div>

    <div class="diario-summary-card diario-summary-next">
        <span class="diario-summary-label">Diferencia</span>
        <strong class="diario-summary-value">
            <?php echo fmt($totalActivo - $totalPasivoPatrimonio); ?>
        </strong>
        <small>Activo menos Pasivo + Patrimonio</small>
    </div>

</div>

<div class="card estados-filter-card">

    <div class="diario-card-header">

        <div>
            <p class="diario-kicker">Período de análisis</p>
            <h2>Filtrar estados financieros</h2>
        </div>

        <span class="diario-badge">Cierre por fecha</span>

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
            <a href="estados_financieros.php" class="btn secondary">Limpiar filtros</a>
        </div>

    </form>

    <?php if ($periodoInvalido): ?>
        <p class="descuadre">El período indicado no es válido. Se muestran todos los movimientos.</p>
    <?php elseif ($desde || $hasta): ?>
        <p class="help-text">Período: <?php echo htmlspecialchars(($desde ?? 'inicio') . ' al ' . ($hasta ?? 'hoy')); ?></p>
    <?php else: ?>
        <p class="help-text">Mostrando toda la información financiera acumulada.</p>
    <?php endif; ?>

</div>

<div class="row-flex estados-layout">

<div class="card estados-resultados-card">
    <div class="diario-card-header">
        <div>
            <p class="diario-kicker">Periodo seleccionado</p>
            <h2>Estado de Resultados</h2>
        </div>
        <span class="diario-badge">Ingresos - Costos</span>
    </div>
    <table class="estados-table">
        <tr><th colspan="2">Ingresos</th></tr>
        <?php foreach ($ingresos as $c): ?>
        <tr><td><?php echo htmlspecialchars($c['codigo'].' '.$c['nombre']); ?></td><td class="text-right"><?php echo fmt($c['saldo']); ?></td></tr>
        <?php endforeach; ?>
        <tr class="total-row"><td>Total Ingresos</td><td class="text-right"><?php echo fmt($totalIngresos); ?></td></tr>

        <tr><th colspan="2">Costos y Gastos</th></tr>
        <?php foreach ($costosGastos as $c): ?>
        <tr><td><?php echo htmlspecialchars($c['codigo'].' '.$c['nombre']); ?></td><td class="text-right"><?php echo fmt($c['saldo']); ?></td></tr>
        <?php endforeach; ?>
        <tr class="total-row"><td>Total Costos y Gastos</td><td class="text-right"><?php echo fmt($totalCostosGastos); ?></td></tr>

        <tr class="total-row estados-final-row">
            <td><?php echo $utilidadEjercicio >= 0 ? 'UTILIDAD DEL EJERCICIO' : 'PÉRDIDA DEL EJERCICIO'; ?></td>
            <td class="text-right"><?php echo fmt($utilidadEjercicio); ?></td>
        </tr>
    </table>
</div>

<div class="card estados-balance-card">
    <div class="diario-card-header">
        <div>
            <p class="diario-kicker">Situación financiera</p>
            <h2>Balance General</h2>
        </div>
        <span class="diario-badge">Activo = Pasivo + Patrimonio</span>
    </div>
    <div class="row-flex">
        <div>
            <table class="estados-table">
                <tr><th colspan="2">Activo Corriente</th></tr>
                <?php foreach ($activoCte as $c): ?>
                <tr><td><?php echo htmlspecialchars($c['codigo'].' '.$c['nombre']); ?></td><td class="text-right"><?php echo fmt($c['saldo']); ?></td></tr>
                <?php endforeach; ?>
                <tr><th colspan="2">Activo No Corriente</th></tr>
                <?php foreach ($activoNoCte as $c): ?>
                <tr><td><?php echo htmlspecialchars($c['codigo'].' '.$c['nombre']); ?></td><td class="text-right"><?php echo fmt($c['saldo']); ?></td></tr>
                <?php endforeach; ?>
                <tr class="total-row"><td>TOTAL ACTIVO</td><td class="text-right"><?php echo fmt($totalActivo); ?></td></tr>
            </table>
        </div>
        <div>
            <table class="estados-table">
                <tr><th colspan="2">Pasivo Corriente</th></tr>
                <?php foreach ($pasivoCte as $c): ?>
                <tr><td><?php echo htmlspecialchars($c['codigo'].' '.$c['nombre']); ?></td><td class="text-right"><?php echo fmt($c['saldo']); ?></td></tr>
                <?php endforeach; ?>
                <tr><th colspan="2">Pasivo No Corriente</th></tr>
                <?php foreach ($pasivoNoCte as $c): ?>
                <tr><td><?php echo htmlspecialchars($c['codigo'].' '.$c['nombre']); ?></td><td class="text-right"><?php echo fmt($c['saldo']); ?></td></tr>
                <?php endforeach; ?>
                <tr class="total-row"><td>Total Pasivo</td><td class="text-right"><?php echo fmt($totalPasivo); ?></td></tr>

                <tr><th colspan="2">Patrimonio</th></tr>
                <?php foreach ($patrimonioCuentas as $c): ?>
                <tr><td><?php echo htmlspecialchars($c['codigo'].' '.$c['nombre']); ?></td><td class="text-right"><?php echo fmt($c['saldo']); ?></td></tr>
                <?php endforeach; ?>
                <tr><td>Resultado acumulado hasta el corte</td><td class="text-right"><?php echo fmt($utilidadAcumulada); ?></td></tr>
                <tr class="total-row"><td>Total Patrimonio</td><td class="text-right"><?php echo fmt($totalPatrimonio); ?></td></tr>

                <tr class="total-row estados-final-row"><td>TOTAL PASIVO + PATRIMONIO</td><td class="text-right"><?php echo fmt($totalPasivoPatrimonio); ?></td></tr>
            </table>
        </div>
    </div>

    <p class="estados-balance-status">
        <?php if (abs($totalActivo - $totalPasivoPatrimonio) < 0.01): ?>
            <span class="cuadrado">✔ El Balance General está cuadrado: Activo = Pasivo + Patrimonio</span>
        <?php else: ?>
            <span class="descuadre">✕ Descuadre de <?php echo fmt($totalActivo - $totalPasivoPatrimonio); ?> — revisa el Libro Diario</span>
        <?php endif; ?>
    </p>

    <?php if (abs($difLibroDiario) >= 0.01): ?>
    <p class="estados-balance-status">
        <span class="descuadre">⚠ El Libro Diario no está balanceado a la fecha de corte: Debe − Haber = <?php echo fmt($difLibroDiario); ?>. Revisa las partidas, aunque el Balance General se vea cuadrado.</span>
    </p>
    <?php endif; ?>
</div>
</div>

<?php require_once 'includes/footer.php'; ?>