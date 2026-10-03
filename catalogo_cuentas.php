<?php
require_once 'includes/auth.php';
require_once 'config/db.php';

$tituloPagina = 'Catálogo de Cuentas';
$activo = 'catalogo';

$busqueda = textoLimpio($_GET['q'] ?? '');
$tipoFiltro = textoLimpio($_GET['tipo'] ?? '');
$naturalezaFiltro = textoLimpio($_GET['naturaleza'] ?? '');

$tipoLabels = [
    'activo' => 'Activo',
    'pasivo' => 'Pasivo',
    'patrimonio' => 'Patrimonio',
    'costo_gasto' => 'Costo / Gasto',
    'ingreso' => 'Ingreso',
    'cierre' => 'Cierre',
    'orden' => 'Orden',
];
$naturalezaLabels = [
    'deudora' => 'Deudora',
    'acreedora' => 'Acreedora',
];
$tiposFiltro = ['' => 'Todos los tipos'] + $tipoLabels;
$naturalezasFiltro = ['' => 'Todas las naturalezas'] + $naturalezaLabels;

if (!array_key_exists($tipoFiltro, $tiposFiltro)) {
    $tipoFiltro = '';
}
if (!array_key_exists($naturalezaFiltro, $naturalezasFiltro)) {
    $naturalezaFiltro = '';
}
$hayFiltros = $busqueda !== '' || $tipoFiltro !== '' || $naturalezaFiltro !== '';

$resCuentas = $conn->query("
    SELECT *
    FROM cuentas
    WHERE activa = 1
    ORDER BY codigo ASC
");
$cuentas = [];
if ($resCuentas) {
    while ($fila = $resCuentas->fetch_assoc()) {
        $cuentas[] = $fila;
    }
    $resCuentas->free();
}

$cuentasFiltradas = [];
foreach ($cuentas as $cuenta) {
    $coincide = true;
    $textoCuenta = $cuenta['codigo'] . ' ' . $cuenta['nombre'];

    if ($busqueda !== '') {
        $coincide = function_exists('mb_stripos')
            ? mb_stripos($textoCuenta, $busqueda) !== false
            : stripos($textoCuenta, $busqueda) !== false;
    }
    if ($tipoFiltro !== '' && $cuenta['tipo'] !== $tipoFiltro) {
        $coincide = false;
    }
    if ($naturalezaFiltro !== '' && $cuenta['naturaleza'] !== $naturalezaFiltro) {
        $coincide = false;
    }
    if ($coincide) {
        $cuentasFiltradas[] = $cuenta;
    }
}
$cuentas = $cuentasFiltradas;

$totalMovimiento = 0;
$totalCorriente = 0;
foreach ($cuentas as $cuenta) {
    if ((int)$cuenta['permite_movimiento'] === 1) {
        $totalMovimiento++;
    }
    if ((int)$cuenta['corriente'] === 1) {
        $totalCorriente++;
    }
}

require_once 'includes/header.php';
?>

<h1 class="page-title">Catálogo de Cuentas</h1>

<div class="card catalogo-hero-card">

    <div class="catalogo-hero-copy">

        <p class="diario-kicker">Estructura contable</p>

        <h2>Directorio de cuentas</h2>

        <p class="help-text">
            Catálogo basado en el Manual de Aplicación de Cuentas Comercial y
            estructurado según las NIIF para las PYMES, normativa de aplicación
            en El Salvador.
        </p>

        <div class="diario-compliance" aria-label="Normativa contable aplicada">
            <span>✔ NIIF para PYMES</span>
            <span>✔ Código de Comercio</span>
            <span>✔ El Salvador</span>
        </div>

    </div>

</div>

<div class="diario-summary-grid catalogo-summary-grid">

    <div class="diario-summary-card">
        <span class="diario-summary-label">Cuentas encontradas</span>
        <strong class="diario-summary-value"><?php echo count($cuentas); ?></strong>
        <small>De un catálogo activo</small>
    </div>

    <div class="diario-summary-card">
        <span class="diario-summary-label">Con movimiento</span>
        <strong class="diario-summary-value"><?php echo $totalMovimiento; ?></strong>
        <small>Admiten registro contable</small>
    </div>

    <div class="diario-summary-card">
        <span class="diario-summary-label">Cuentas corrientes</span>
        <strong class="diario-summary-value"><?php echo $totalCorriente; ?></strong>
        <small>Para el análisis de liquidez</small>
    </div>

    <div class="diario-summary-card diario-summary-next">
        <span class="diario-summary-label">Categorías</span>
        <strong class="diario-summary-value"><?php echo count($tipoLabels); ?></strong>
        <small>Clasificación principal</small>
    </div>

</div>

<div class="card catalogo-filter-card">

    <div class="diario-card-header">

        <div>
            <p class="diario-kicker">Búsqueda y clasificación</p>
            <h2>Filtrar cuentas</h2>
        </div>

        <span class="diario-badge">Codificación por dígito</span>

    </div>

    <form method="GET" class="row-flex catalogo-filter-form">

        <div class="catalogo-search-field">
            <label for="q">Buscar cuenta</label>
            <input
                type="search"
                id="q"
                name="q"
                value="<?php echo htmlspecialchars($busqueda); ?>"
                placeholder="Código o nombre de cuenta"
            >
        </div>

        <div>
            <label for="tipo">Tipo</label>
            <select id="tipo" name="tipo">
                <?php foreach ($tiposFiltro as $valor => $etiqueta): ?>
                    <option
                        value="<?php echo htmlspecialchars($valor); ?>"
                        <?php echo $tipoFiltro === $valor ? 'selected' : ''; ?>
                    >
                        <?php echo htmlspecialchars($etiqueta); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="naturaleza">Naturaleza</label>
            <select id="naturaleza" name="naturaleza">
                <?php foreach ($naturalezasFiltro as $valor => $etiqueta): ?>
                    <option
                        value="<?php echo htmlspecialchars($valor); ?>"
                        <?php echo $naturalezaFiltro === $valor ? 'selected' : ''; ?>
                    >
                        <?php echo htmlspecialchars($etiqueta); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="catalogo-filter-actions">
            <button type="submit" class="btn">Aplicar filtros</button>
            <a href="catalogo_cuentas.php" class="btn secondary">Limpiar</a>
        </div>

    </form>

    <?php if ($hayFiltros): ?>
        <p class="catalogo-filter-result">
            <strong>Filtros activos:</strong>
            <?php if ($busqueda !== ''): ?>
                búsqueda “<?php echo htmlspecialchars($busqueda); ?>”
            <?php endif; ?>
            <?php if ($tipoFiltro !== ''): ?>
                tipo “<?php echo htmlspecialchars($tipoLabels[$tipoFiltro]); ?>”
            <?php endif; ?>
            <?php if ($naturalezaFiltro !== ''): ?>
                naturaleza “<?php echo htmlspecialchars($naturalezaLabels[$naturalezaFiltro]); ?>”
            <?php endif; ?>
        </p>
    <?php endif; ?>

</div>

<div class="card catalogo-table-card">

    <div class="diario-card-header">

        <div>
            <p class="diario-kicker">Directorio contable</p>
            <h2>Listado de cuentas</h2>
        </div>

        <span class="catalogo-count"><?php echo count($cuentas); ?> cuenta(s)</span>

    </div>

    <div class="table-scroll catalogo-table-scroll">

        <table class="catalogo-table">

            <thead>
                <tr>
                    <th>Código</th>
                    <th>Nombre de la cuenta</th>
                    <th>Tipo</th>
                    <th>Naturaleza</th>
                    <th class="text-center">Nivel</th>
                    <th class="text-center">Corriente</th>
                    <th class="text-center">Movimiento</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$cuentas): ?>
                    <tr>
                        <td colspan="7" class="catalogo-empty">
                            No se encontraron cuentas con esos filtros.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($cuentas as $c): ?>
                        <tr>
                            <td>
                                <span class="catalogo-code">
                                    <?php echo htmlspecialchars($c['codigo']); ?>
                                </span>
                            </td>
                            <td class="catalogo-name">
                                <?php
                                $sangria = str_repeat(
                                    '&nbsp;&nbsp;&nbsp;&nbsp;',
                                    max(0, ((int)$c['nivel'] / 2) - 1)
                                );
                                echo $sangria . htmlspecialchars($c['nombre']);
                                ?>
                            </td>
                            <td>
                                <span class="catalogo-type catalogo-type-<?php echo htmlspecialchars($c['tipo']); ?>">
                                    <?php echo htmlspecialchars($tipoLabels[$c['tipo']] ?? $c['tipo']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="catalogo-nature catalogo-nature-<?php echo htmlspecialchars($c['naturaleza']); ?>">
                                    <?php echo htmlspecialchars($naturalezaLabels[$c['naturaleza']] ?? $c['naturaleza']); ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="catalogo-level"><?php echo (int)$c['nivel']; ?></span>
                            </td>
                            <td class="text-center">
                                <?php echo $c['corriente']
                                    ? '<span class="catalogo-check">Sí</span>'
                                    : '<span class="catalogo-dash">—</span>'; ?>
                            </td>
                            <td class="text-center">
                                <?php echo $c['permite_movimiento']
                                    ? '<span class="catalogo-check">Sí</span>'
                                    : '<span class="catalogo-dash">—</span>'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>

        </table>

    </div>

</div>

<?php require_once 'includes/footer.php'; ?>
