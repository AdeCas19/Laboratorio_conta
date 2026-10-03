<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/functions.php';

$tituloPagina = 'Libro Mayor';
$activo = 'mayor';

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

$busqueda = textoLimpio($_GET['q'] ?? '');
$categoria = textoLimpio($_GET['categoria'] ?? '');
$categoriasContables = [
    '' => 'Todas las categorías',
    'activo' => 'Activo',
    'pasivo' => 'Pasivo',
    'patrimonio' => 'Patrimonio',
    'costo_gasto' => 'Costos y gastos',
    'ingreso' => 'Ingresos',
];
if (!array_key_exists($categoria, $categoriasContables)) {
    $categoria = '';
}
$hayFiltros = $busqueda !== '' || $categoria !== '';

/*
|--------------------------------------------------------------------------
| OBTENER CUENTAS CON MOVIMIENTOS
|--------------------------------------------------------------------------
*/
$sqlCuentas = "
    SELECT DISTINCT
        c.id,
        c.codigo,
        c.nombre,
        c.naturaleza,
        c.tipo
    FROM cuentas c
    INNER JOIN detalle_asientos d ON d.cuenta_id = c.id
    INNER JOIN asientos a ON a.id = d.asiento_id
    WHERE c.permite_movimiento = 1
      AND c.activa = 1
";
if ($desde !== null) {
    $sqlCuentas .= " AND a.fecha >= '{$desde}'";
}
if ($hasta !== null) {
    $sqlCuentas .= " AND a.fecha <= '{$hasta}'";
}
$sqlCuentas .= " ORDER BY c.codigo ASC";

$resCuentas = $conn->query($sqlCuentas);

$cuentasMayor = [];

if ($resCuentas) {
    while ($row = $resCuentas->fetch_assoc()) {
        $cuentasMayor[] = $row;
    }
    $resCuentas->free();
}

$cuentasFiltradas = [];

foreach ($cuentasMayor as $cuenta) {

    $coincide = true;

    if ($busqueda !== '') {
        $textoCuenta = $cuenta['codigo'] . ' ' . $cuenta['nombre'];
        $coincide = function_exists('mb_stripos')
            ? mb_stripos($textoCuenta, $busqueda) !== false
            : stripos($textoCuenta, $busqueda) !== false;
    }

    if ($categoria !== '' && ($cuenta['tipo'] ?? '') !== $categoria) {
        $coincide = false;
    }

    if ($coincide) {
        $cuentasFiltradas[] = $cuenta;
    }
}

$cuentasMayor = array_values($cuentasFiltradas);


/*
|--------------------------------------------------------------------------
| TOTALES GENERALES DEL PERÍODO
|--------------------------------------------------------------------------
*/

$totalDebeGeneral = 0;
$totalHaberGeneral = 0;
$idsCuentas = array_map('intval', array_column($cuentasMayor, 'id'));

if ($idsCuentas) {
    $idsCuentasSql = implode(',', $idsCuentas);
    $sqlTotales = "
        SELECT
            COALESCE(SUM(d.debe), 0) AS total_debe,
            COALESCE(SUM(d.haber), 0) AS total_haber
        FROM detalle_asientos d
        INNER JOIN asientos a ON a.id = d.asiento_id
        WHERE d.cuenta_id IN ({$idsCuentasSql})
    ";
    if ($desde !== null) {
        $sqlTotales .= " AND a.fecha >= '{$desde}'";
    }
    if ($hasta !== null) {
        $sqlTotales .= " AND a.fecha <= '{$hasta}'";
    }
    $resTotales = $conn->query($sqlTotales);
    if ($resTotales && ($totales = $resTotales->fetch_assoc())) {
        $totalDebeGeneral = (float)$totales['total_debe'];
        $totalHaberGeneral = (float)$totales['total_haber'];
        $resTotales->free();
    }
}

require_once 'includes/header.php';
?>

<style>
/* ============================================================
   NUEVO DISEÑO LIBRO MAYOR
   ============================================================ */

.page-title {
    font-size: 28px;
    font-weight: 800;
    color: #0f172a;
    margin: 0 0 24px;
    letter-spacing: -0.5px;
}

/* HERO CARD */
.mayor-hero-card {
    background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%);
    border-radius: 16px;
    padding: 32px;
    margin-bottom: 24px;
    color: white;
    border: none;
    box-shadow: 0 10px 25px rgba(37, 99, 235, 0.15);
}

.mayor-hero-copy .diario-kicker {
    color: #93c5fd;
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 8px;
}

.mayor-hero-copy h2 {
    font-size: 26px;
    font-weight: 800;
    margin: 0 0 12px;
    color: white;
}

.mayor-hero-copy .help-text {
    color: rgba(255,255,255,0.85);
    font-size: 15px;
    line-height: 1.6;
    max-width: 700px;
    margin-bottom: 16px;
}

.mayor-hero-card .diario-compliance {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.mayor-hero-card .diario-compliance span {
    background: rgba(255,255,255,0.15);
    color: white;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    backdrop-filter: blur(10px);
}

/* SUMMARY GRID */
.mayor-summary-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.mayor-summary-grid .diario-summary-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    transition: transform 0.2s, box-shadow 0.2s;
}

.mayor-summary-grid .diario-summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}

.mayor-summary-grid .diario-summary-label {
    color: #64748b;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}

.mayor-summary-grid .diario-summary-value {
    font-size: 24px;
    font-weight: 800;
    color: #0f172a;
}

.mayor-summary-grid .diario-summary-card small {
    color: #94a3b8;
    font-size: 12px;
    display: block;
    margin-top: 4px;
}

/* FILTER CARD */
.mayor-filter-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}

.mayor-filter-card .diario-card-header {
    margin-bottom: 20px;
}

.mayor-filter-card .diario-card-header h2 {
    font-size: 18px;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
}

.mayor-filter-form {
    display: flex;
    gap: 12px;
    align-items: flex-end;
    flex-wrap: wrap;
}

.mayor-filter-form label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #475569;
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.mayor-filter-form input,
.mayor-filter-form select {
    padding: 10px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 14px;
    min-width: 160px;
    background: white;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.mayor-filter-form input:focus,
.mayor-filter-form select:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}

.mayor-filter-actions {
    display: flex;
    gap: 8px;
}

/* ACCOUNT CARDS */
.mayor-account-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    margin-bottom: 24px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}

.mayor-account-header {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    padding: 20px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #e2e8f0;
}

.mayor-account-title {
    font-size: 20px;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
}

.mayor-account-nature {
    color: #64748b;
    font-size: 13px;
    margin-top: 4px;
}

.mayor-nature-badge {
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 0.5px;
}

.mayor-nature-deudora {
    background: #fef3c7;
    color: #92400e;
}

.mayor-nature-acreedora {
    background: #d1fae5;
    color: #065f46;
}

/* TABLE */
.mayor-table-scroll {
    overflow-x: auto;
}

.mayor-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

.mayor-table thead {
    background: #f8fafc;
}

.mayor-table th {
    padding: 12px 16px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid #e2e8f0;
    white-space: nowrap;
}

.mayor-table td {
    padding: 14px 16px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
}

.mayor-table tbody tr:hover {
    background: #f8fafc;
}

.mayor-table .text-right {
    text-align: right;
    font-variant-numeric: tabular-nums;
}

.mayor-total-row {
    background: #f8fafc;
    font-weight: 700;
}

.mayor-total-row td {
    border-top: 2px solid #e2e8f0;
    border-bottom: none;
    color: #0f172a;
}

.mayor-movement-detail {
    font-size: 12px;
    color: #64748b;
    margin-top: 4px;
    line-height: 1.4;
}

/* RESULT BOX */
.mayor-result-box {
    background: #f8fafc;
    padding: 20px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    border-top: 1px solid #e2e8f0;
}

.mayor-result-box strong {
    color: #475569;
    font-size: 13px;
}

.mayor-final-saldo {
    font-size: 20px;
    font-weight: 800;
    color: #0f172a;
}

.mayor-result-badge {
    padding: 6px 14px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 700;
    margin-left: 8px;
}

.mayor-result-deudora {
    background: #fef3c7;
    color: #92400e;
}

.mayor-result-acreedora {
    background: #d1fae5;
    color: #065f46;
}

.mayor-result-saldada {
    background: #e2e8f0;
    color: #475569;
}

/* FILTER RESULT */
.mayor-filter-result {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    padding: 12px 16px;
    margin-top: 16px;
    font-size: 14px;
    color: #1e40af;
}

.mayor-filter-result a {
    color: #2563eb;
    text-decoration: none;
    font-weight: 600;
    margin-left: 8px;
}

.mayor-filter-result a:hover {
    text-decoration: underline;
}

/* EMPTY CARD */
.mayor-empty-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 48px 24px;
    text-align: center;
    color: #64748b;
}

.mayor-empty-card p {
    font-size: 16px;
    margin: 0;
}

/* RESPONSIVE */
@media (max-width: 768px) {
    .mayor-summary-grid {
        grid-template-columns: 1fr;
    }

    .mayor-hero-card {
        padding: 24px;
    }

    .mayor-hero-copy h2 {
        font-size: 22px;
    }

    .mayor-filter-form {
        flex-direction: column;
        align-items: stretch;
    }

    .mayor-filter-form input,
    .mayor-filter-form select {
        min-width: 100%;
    }

    .mayor-filter-actions {
        justify-content: stretch;
    }

    .mayor-account-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }

    .mayor-result-box {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<h1 class="page-title">Libro Mayor</h1>

<div class="card mayor-hero-card">

    <div class="mayor-hero-copy">

        <p class="diario-kicker">Mayorización contable</p>

        <h2>Libro Mayor por cuentas</h2>

        <p class="help-text">
            Consulta los movimientos de cada cuenta, sus totales y el saldo
            final. La mayorización se calcula automáticamente desde el Libro
            Diario y la base de datos.
        </p>

        <div class="diario-compliance" aria-label="Criterios contables aplicados">
            <span>✔ Partida doble</span>
            <span>✔ NIIF para PYMES</span>
            <span>✔ El Salvador</span>
        </div>

    </div>

</div>

<div class="diario-summary-grid mayor-summary-grid">

    <div class="diario-summary-card">
        <span class="diario-summary-label">
            <?php echo $hayFiltros ? 'Cuentas encontradas' : 'Cuentas con movimiento'; ?>
        </span>
        <strong class="diario-summary-value"><?php echo count($cuentasMayor); ?></strong>
        <small>
            <?php echo $hayFiltros
                ? 'Según los filtros activos'
                : 'En el período seleccionado'; ?>
        </small>
    </div>

    <div class="diario-summary-card">
        <span class="diario-summary-label">Total Debe</span>
        <strong class="diario-summary-value"><?php echo fmt($totalDebeGeneral); ?></strong>
        <small>Movimientos deudores</small>
    </div>

    <div class="diario-summary-card diario-summary-next">
        <span class="diario-summary-label">Total Haber</span>
        <strong class="diario-summary-value"><?php echo fmt($totalHaberGeneral); ?></strong>
        <small>Movimientos acreedores</small>
    </div>

</div>

<div class="card mayor-filter-card">

    <div class="diario-card-header">

        <div>
            <p class="diario-kicker">Período de análisis</p>
            <h2>Filtrar movimientos</h2>
        </div>

        <span class="diario-badge">Mayorización automática</span>

    </div>

    <form method="GET" class="row-flex mayor-filter-form">

        <div>
            <label for="desde">Desde</label>
            <input type="date" id="desde" name="desde" value="<?php echo htmlspecialchars($desde ?? ''); ?>">
        </div>

        <div>
            <label for="hasta">Hasta</label>
            <input type="date" id="hasta" name="hasta" value="<?php echo htmlspecialchars($hasta ?? ''); ?>">
        </div>

        <div class="mayor-search-field">
            <label for="q">Buscar cuenta</label>
            <input
                type="search"
                id="q"
                name="q"
                value="<?php echo htmlspecialchars($busqueda); ?>"
                placeholder="Código o nombre de cuenta"
            >
        </div>

        <div class="mayor-filter-actions">
            <button type="submit" class="btn">Aplicar filtros</button>
            <a href="libro_mayor.php" class="btn secondary">Limpiar filtros</a>
        </div>

    </form>

    <?php if ($periodoInvalido): ?>
        <p class="descuadre">El período indicado no es válido. Se muestran todos los movimientos.</p>
    <?php elseif ($desde || $hasta): ?>
        <p class="help-text">Período: <?php echo htmlspecialchars(($desde ?? 'inicio') . ' al ' . ($hasta ?? 'hoy')); ?></p>
    <?php else: ?>
        <p class="help-text">Mostrando todos los movimientos registrados.</p>
    <?php endif; ?>

    <?php if ($hayFiltros): ?>
        <p class="mayor-filter-result">
            <strong>Filtros activos:</strong>
            <?php if ($busqueda !== ''): ?>
                búsqueda “<?php echo htmlspecialchars($busqueda); ?>”
            <?php endif; ?>
            <?php if ($categoria !== ''): ?>
                categoría “<?php echo htmlspecialchars($categoriasContables[$categoria]); ?>”
            <?php endif; ?>
            <a href="libro_mayor.php<?php echo $desde || $hasta ? '?desde=' . urlencode($desde ?? '') . '&amp;hasta=' . urlencode($hasta ?? '') : ''; ?>">
                Limpiar búsqueda
            </a>
        </p>
    <?php endif; ?>

</div>

<?php if (empty($cuentasMayor)): ?>

    <div class="card mayor-empty-card">
        <p>
            <?php echo $hayFiltros
                ? 'No se encontraron cuentas con esos filtros.'
                : 'No existen cuentas con movimientos registrados.'; ?>
        </p>
    </div>

<?php else: ?>


    <?php foreach ($cuentasMayor as $cuenta): ?>

        <?php

        /*
        |--------------------------------------------------------------------------
        | OBTENER MOVIMIENTOS DE LA CUENTA
        |--------------------------------------------------------------------------
        */

        $sqlMovimientos = "
            SELECT
                a.fecha,
                a.numero_partida,
                a.concepto,
                d.detalle,
                d.debe,
                d.haber,
                d.id AS detalle_id
            FROM detalle_asientos d
            INNER JOIN asientos a ON a.id = d.asiento_id
            WHERE d.cuenta_id = ?
        ";
        if ($desde !== null) {
            $sqlMovimientos .= " AND a.fecha >= '{$desde}'";
        }
        if ($hasta !== null) {
            $sqlMovimientos .= " AND a.fecha <= '{$hasta}'";
        }
        $sqlMovimientos .= "
            ORDER BY
                a.fecha ASC,
                a.numero_partida ASC,
                d.id ASC
        ";

        $stmt = $conn->prepare($sqlMovimientos);
        $stmt->bind_param('i', $cuenta['id']);
        $stmt->execute();

        $movimientos = $stmt->get_result();


        /*
        |--------------------------------------------------------------------------
        | VARIABLES PARA LOS TOTALES
        |--------------------------------------------------------------------------
        */

        $totalDebe = 0;
        $totalHaber = 0;
        $saldoAnterior = 0;
        $debeAnterior = 0;
        $haberAnterior = 0;
        $saldo = 0;

        if ($desde !== null) {
            $sqlAnterior = "
                SELECT
                    COALESCE(SUM(d.debe), 0) AS total_debe,
                    COALESCE(SUM(d.haber), 0) AS total_haber
                FROM detalle_asientos d
                INNER JOIN asientos a ON a.id = d.asiento_id
                WHERE d.cuenta_id = ? AND a.fecha < ?
            ";
            $stmtAnterior = $conn->prepare($sqlAnterior);
            $stmtAnterior->bind_param('is', $cuenta['id'], $desde);
            $stmtAnterior->execute();
            $anterior = $stmtAnterior->get_result()->fetch_assoc();
            $stmtAnterior->close();
            $debeAnterior = (float)($anterior['total_debe'] ?? 0);
            $haberAnterior = (float)($anterior['total_haber'] ?? 0);
            $saldoAnterior = $cuenta['naturaleza'] === 'deudora'
                ? $debeAnterior - $haberAnterior
                : $haberAnterior - $debeAnterior;
            $saldo = $saldoAnterior;
        }

        ?>

        <div class="card mayor-account-card">

            <!-- ENCABEZADO DE LA CUENTA -->

            <div class="mayor-account-header">

                <div>

                    <h2 class="mayor-account-title">
                        <?php
                        echo htmlspecialchars(
                            $cuenta['codigo'] . ' - ' . $cuenta['nombre']
                        );
                        ?>
                    </h2>

                    <div class="mayor-account-nature">
                        Naturaleza de la cuenta:
                    </div>

                </div>


                <?php if ($cuenta['naturaleza'] === 'deudora'): ?>

                    <span class="mayor-nature-badge mayor-nature-deudora">
                        DEUDORA
                    </span>

                <?php else: ?>

                    <span class="mayor-nature-badge mayor-nature-acreedora">
                        ACREEDORA
                    </span>

                <?php endif; ?>

            </div>


            <!-- TABLA DE MOVIMIENTOS -->

            <div class="table-scroll mayor-table-scroll">

                <table class="mayor-table">

                    <thead>

                        <tr>
                            <th># de asiento</th>
                            <th>Fecha</th>
                            <th>Concepto</th>
                            <th class="text-right">Debe</th>
                            <th class="text-right">Haber</th>
                            <th class="text-right">Saldo</th>
                        </tr>

                    </thead>

                    <tbody>


                    <?php while ($mov = $movimientos->fetch_assoc()): ?>

                        <?php

                        $debe = (float)$mov['debe'];
                        $haber = (float)$mov['haber'];

                        $totalDebe += $debe;
                        $totalHaber += $haber;


                        /*
                        |--------------------------------------------------------------------------
                        | CALCULAR SALDO SEGÚN NATURALEZA
                        |--------------------------------------------------------------------------
                        */

                        if ($cuenta['naturaleza'] === 'deudora') {

                            $saldo += $debe - $haber;

                        } else {

                            $saldo += $haber - $debe;

                        }

                        ?>


                        <tr>

                            <td>
                                #<?php echo htmlspecialchars($mov['numero_partida']); ?>
                            </td>


                            <td>
                                <?php
                                echo date(
                                    'd/m/Y',
                                    strtotime($mov['fecha'])
                                );
                                ?>
                            </td>


                            <td>

                                <?php
                                echo htmlspecialchars($mov['concepto']);
                                ?>

                                <?php if (!empty($mov['detalle'])): ?>

                                    <div class="mayor-movement-detail">
                                        <?php
                                        echo htmlspecialchars($mov['detalle']);
                                        ?>
                                    </div>

                                <?php endif; ?>

                            </td>


                            <td class="text-right">

                                <?php
                                echo $debe > 0
                                    ? fmt($debe)
                                    : '';
                                ?>

                            </td>


                            <td class="text-right">

                                <?php
                                echo $haber > 0
                                    ? fmt($haber)
                                    : '';
                                ?>

                            </td>


                            <td class="text-right">

                                <strong>
                                    <?php
                                    echo fmt(abs($saldo));
                                    ?>
                                </strong>

                            </td>

                        </tr>


                    <?php endwhile; ?>


                    <!-- TOTALES -->

                    <tr class="mayor-total-row">

                        <td colspan="3" class="text-right">
                            TOTALES
                        </td>

                        <td class="text-right">
                            <?php echo fmt($totalDebe); ?>
                        </td>

                        <td class="text-right">
                            <?php echo fmt($totalHaber); ?>
                        </td>

                        <td class="text-right">
                            <?php echo fmt(abs($saldo)); ?>
                        </td>

                    </tr>

                    </tbody>

                </table>

            </div>


            <!-- RESULTADO DE LA CUENTA -->

            <?php

            /*
            |--------------------------------------------------------------------------
            | DETERMINAR SI EL SALDO FINAL ES DEUDOR O ACREEDOR
            |--------------------------------------------------------------------------
            */

            $debeFinal = $debeAnterior + $totalDebe;
            $haberFinal = $haberAnterior + $totalHaber;

            if ($debeFinal > $haberFinal) {

                $resultado = 'Deudora';

            } elseif ($haberFinal > $debeFinal) {

                $resultado = 'Acreedora';

            } else {

                $resultado = 'Saldada';

            }

            ?>


            <div class="mayor-result-box">

                <div>

                    <strong>Total Debe:</strong>
                    <?php echo fmt($totalDebe); ?>

                    &nbsp;&nbsp;&nbsp;

                    <strong>Total Haber:</strong>
                    <?php echo fmt($totalHaber); ?>

                    &nbsp;&nbsp;&nbsp;

                    <strong>Saldo anterior:</strong>
                    <?php echo fmt($saldoAnterior); ?>

                </div>


                <div>

                    <strong>Saldo final:</strong>

                    <span class="mayor-final-saldo">
                        <?php echo fmt(abs($saldo)); ?>
                    </span>

                    &nbsp;

                    <?php if ($resultado === 'Deudora'): ?>

                        <span class="mayor-result-badge mayor-result-deudora">
                            DEUDORA
                        </span>

                    <?php elseif ($resultado === 'Acreedora'): ?>

                        <span class="mayor-result-badge mayor-result-acreedora">
                            ACREEDORA
                        </span>

                    <?php else: ?>

                        <span class="mayor-result-badge mayor-result-saldada">
                            SALDADA
                        </span>

                    <?php endif; ?>

                </div>

            </div>

        </div>


    <?php endforeach; ?>


<?php endif; ?>


<?php require_once 'includes/footer.php'; ?>