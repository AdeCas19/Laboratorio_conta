<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/functions.php';

$tituloPagina = 'Liquidez';
$activo = 'liquidez';

/* El análisis de liquidez se calcula con todos los movimientos registrados. */

function totalCorriente($conn, $digitoRaiz, $excluirPrefijos = []) {
    $total = 0;
    $res = $conn->query("SELECT id, codigo, naturaleza, tipo FROM cuentas WHERE codigo LIKE '$digitoRaiz%' AND permite_movimiento = 1 AND activa = 1 AND corriente = 1");
    while ($c = $res->fetch_assoc()) {
        $excluir = false;
        foreach ($excluirPrefijos as $p) { if (strpos($c['codigo'], $p) === 0) { $excluir = true; break; } }
        if ($excluir) continue;
        $total += saldoClasificacion($conn, $c['id'], $c['tipo'], $c['naturaleza']);
    }
    return $total;
}

function totalEfectivo($conn) {
    $total = 0;
    $res = $conn->query("SELECT id, naturaleza, tipo FROM cuentas WHERE codigo LIKE '1101%' AND permite_movimiento = 1 AND activa = 1");
    while ($c = $res->fetch_assoc()) $total += saldoClasificacion($conn, $c['id'], $c['tipo'], $c['naturaleza']);
    return $total;
}
function totalInventarios($conn) {
    $total = 0;
    $res = $conn->query("SELECT id, naturaleza, tipo FROM cuentas WHERE codigo LIKE '1105%' AND permite_movimiento = 1 AND activa = 1");
    while ($c = $res->fetch_assoc()) $total += saldoClasificacion($conn, $c['id'], $c['tipo'], $c['naturaleza']);
    return $total;
}

$activoCorriente = totalCorriente($conn, '1', []);
$pasivoCorriente = totalCorriente($conn, '2', []);
$efectivo        = totalEfectivo($conn);
$inventarios     = totalInventarios($conn);

$razonCorriente = $pasivoCorriente != 0 ? $activoCorriente / $pasivoCorriente : null;
$pruebaAcida    = $pasivoCorriente != 0 ? ($activoCorriente - $inventarios) / $pasivoCorriente : null;
$razonEfectivo  = $pasivoCorriente != 0 ? $efectivo / $pasivoCorriente : null;
$capitalTrabajo = $activoCorriente - $pasivoCorriente;

function interpretar($valor, $tipo) {
    if ($valor === null) return ['—', 'gris'];
    if ($tipo === 'corriente') {
        if ($valor >= 1.5) return ['Buena capacidad de pago a corto plazo', 'ok'];
        if ($valor >= 1) return ['Capacidad de pago ajustada', 'warn'];
        return ['Riesgo de liquidez: el pasivo corriente supera al activo corriente', 'warn'];
    }
    if ($tipo === 'acida') {
        if ($valor >= 1) return ['Puede cubrir el pasivo corriente sin depender del inventario', 'ok'];
        return ['Depende del inventario para cubrir sus obligaciones a corto plazo', 'warn'];
    }
    return ['', 'gris'];
}
[$msgCorriente, $claseCorriente] = interpretar($razonCorriente, 'corriente');
[$msgAcida, $claseAcida] = interpretar($pruebaAcida, 'acida');

require_once 'includes/header.php';
?>

<h1 class="page-title">Análisis de Liquidez</h1>

<div class="card liquidez-hero-card">

    <div class="liquidez-hero-copy">

        <p class="diario-kicker">Capacidad de pago</p>

        <h2>Razones de Liquidez</h2>

        <p class="help-text">
            La liquidez mide la capacidad de la empresa para cubrir sus
            obligaciones a corto plazo con sus recursos más líquidos. El
            análisis utiliza todos los movimientos registrados en el catálogo
            de cuentas, sin filtros de fechas.
        </p>

        <div class="diario-compliance" aria-label="Normativa contable aplicada">
            <span>✔ NIIF para PYMES</span>
            <span>✔ El Salvador</span>
            <span>✔ Cálculo automático</span>
        </div>

    </div>

</div>

<div class="diario-summary-grid liquidez-summary-grid">

    <div class="diario-summary-card">
        <span class="diario-summary-label">Activo corriente</span>
        <strong class="diario-summary-value"><?php echo fmt($activoCorriente); ?></strong>
        <small>Recursos de corto plazo</small>
    </div>

    <div class="diario-summary-card">
        <span class="diario-summary-label">Pasivo corriente</span>
        <strong class="diario-summary-value"><?php echo fmt($pasivoCorriente); ?></strong>
        <small>Obligaciones de corto plazo</small>
    </div>

    <div class="diario-summary-card <?php echo $capitalTrabajo >= 0 ? 'diario-summary-next' : ''; ?>">
        <span class="diario-summary-label">Capital de trabajo</span>
        <strong class="diario-summary-value"><?php echo fmt($capitalTrabajo); ?></strong>
        <small><?php echo $capitalTrabajo >= 0 ? 'Recursos disponibles' : 'Requiere atención'; ?></small>
    </div>

    <div class="diario-summary-card">
        <span class="diario-summary-label">Razón corriente</span>
        <strong class="diario-summary-value">
            <?php echo $razonCorriente !== null ? number_format($razonCorriente, 2) : 'N/D'; ?>
        </strong>
        <small>Activo corriente / Pasivo corriente</small>
    </div>

</div>

<div class="card liquidez-scope-card">
    <strong>Alcance del análisis:</strong>
    Se están considerando todos los asientos registrados hasta la fecha
    más reciente disponible en el Libro Diario.
</div>

<div class="card liquidez-ratio-card">
    <div class="diario-card-header">
        <div>
            <p class="diario-kicker">Indicadores financieros</p>
            <h2>Razones de Liquidez</h2>
        </div>
        <span class="diario-badge">Cálculo automático</span>
    </div>
    <table class="liquidez-table">
        <thead>
        <tr><th>Indicador</th><th>Fórmula</th><th class="text-right">Resultado</th><th>Interpretación</th></tr>
        </thead>
        <tbody>
        <tr>
            <td><strong>Razón Corriente</strong></td>
            <td>Activo Corriente ÷ Pasivo Corriente</td>
            <td class="text-right"><?php echo $razonCorriente !== null ? number_format($razonCorriente,2) : 'N/D'; ?></td>
            <td><span class="badge <?php echo $claseCorriente=='ok'?'badge-azul':'badge-gris'; ?>"><?php echo $msgCorriente; ?></span></td>
        </tr>
        <tr>
            <td><strong>Prueba Ácida</strong></td>
            <td>(Activo Corriente − Inventarios) ÷ Pasivo Corriente</td>
            <td class="text-right"><?php echo $pruebaAcida !== null ? number_format($pruebaAcida,2) : 'N/D'; ?></td>
            <td><span class="badge <?php echo $claseAcida=='ok'?'badge-azul':'badge-gris'; ?>"><?php echo $msgAcida; ?></span></td>
        </tr>
        <tr>
            <td><strong>Razón de Efectivo</strong></td>
            <td>Efectivo y Equivalentes ÷ Pasivo Corriente</td>
            <td class="text-right"><?php echo $razonEfectivo !== null ? number_format($razonEfectivo,2) : 'N/D'; ?></td>
            <td><span class="badge badge-gris">Liquidez inmediata disponible</span></td>
        </tr>
        <tr>
            <td><strong>Capital de Trabajo Neto</strong></td>
            <td>Activo Corriente − Pasivo Corriente</td>
            <td class="text-right"><?php echo fmt($capitalTrabajo); ?></td>
            <td><span class="badge badge-gris">Recursos disponibles tras cubrir el pasivo corriente</span></td>
        </tr>
        </tbody>
    </table>
</div>

<?php require_once 'includes/footer.php'; ?>
