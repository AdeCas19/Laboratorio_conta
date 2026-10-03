<?php

require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/functions.php';
require_once 'includes/kardex_functions.php';

$tituloPagina = 'Kardex';
$activo = 'inventario';


/* ============================================================
   PRODUCTO SELECCIONADO
   ============================================================ */

$productoId = (int)($_GET['producto'] ?? 0);

if ($productoId <= 0) {
    header('Location: inventario.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT id, codigo, nombre, categoria
    FROM productos
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param('i', $productoId);
$stmt->execute();
$producto = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$producto) {
    header('Location: inventario.php');
    exit;
}


/* ============================================================
   FILTROS
   ============================================================ */

$filtroFechaDesde = textoLimpio($_GET['fecha_desde'] ?? '');
$filtroFechaHasta = textoLimpio($_GET['fecha_hasta'] ?? '');
$filtroTipo = textoLimpio($_GET['tipo'] ?? '');

// Validar fechas
if ($filtroFechaDesde !== '' && !fechaValida($filtroFechaDesde)) {
    $filtroFechaDesde = '';
}
if ($filtroFechaHasta !== '' && !fechaValida($filtroFechaHasta)) {
    $filtroFechaHasta = '';
}

$tiposPermitidos = ['COMPRA', 'VENTA', 'AJUSTE_POSITIVO', 'AJUSTE_NEGATIVO', 'DEVOLUCION_CLIENTE', 'DEVOLUCION_PROVEEDOR'];
if ($filtroTipo !== '' && !in_array($filtroTipo, $tiposPermitidos, true)) {
    $filtroTipo = '';
}

// Obtener todos los movimientos
$movimientosTodos = obtenerKardexProducto($conn, $productoId);

// Aplicar filtros
$movimientos = array_filter($movimientosTodos, function($mov) use ($filtroFechaDesde, $filtroFechaHasta, $filtroTipo) {
    if ($filtroFechaDesde !== '' && $mov['fecha'] < $filtroFechaDesde) {
        return false;
    }
    if ($filtroFechaHasta !== '' && $mov['fecha'] > $filtroFechaHasta) {
        return false;
    }
    if ($filtroTipo !== '' && $mov['tipo_movimiento'] !== $filtroTipo) {
        return false;
    }
    return true;
});

// Calcular estadísticas de todos los movimientos (sin filtro para el resumen)
$totalEntradasUnidades = 0;
$totalEntradasImporte = 0;
$totalSalidasUnidades = 0;
$totalSalidasImporte = 0;
$ultimaCompra = null;
$ultimaVenta = null;

foreach ($movimientosTodos as $mov) {
    if ((float)$mov['unidades_entrada'] > 0) {
        $totalEntradasUnidades += (float)$mov['unidades_entrada'];
        $totalEntradasImporte += (float)$mov['importe_entrada'];
        $ultimaCompra = $mov['fecha'];
    }
    if ((float)$mov['unidades_salida'] > 0) {
        $totalSalidasUnidades += (float)$mov['unidades_salida'];
        $totalSalidasImporte += (float)$mov['importe_salida'];
        $ultimaVenta = $mov['fecha'];
    }
}

$metodo = obtenerMetodoValuacion($conn);
$saldoActual = obtenerSaldoActual($conn, $productoId);

// Calcular rotación (veces que se ha vendido el inventario promedio)
$promedioInventario = ($saldoActual['unidades'] + $totalEntradasUnidades) / 2;
$rotacion = $promedioInventario > 0 ? $totalSalidasUnidades / $promedioInventario : 0;

$etiquetasMetodo = [
    'PEPS'               => 'PEPS (Primeras Entradas, Primeras Salidas)',
    'PROMEDIO'           => 'Costo Promedio Ponderado',
    'ULTIMA_COMPRA'      => 'Costo según Última Compra',
    'ASIGNACION_DIRECTA'  => 'Asignación directa (no disponible)',
];

$etiquetasTipo = [
    'INVENTARIO_INICIAL'   => ['texto' => 'Inventario inicial',   'color' => '#0ea5e9'],
    'COMPRA'                => ['texto' => 'Compra',               'color' => '#16a34a'],
    'VENTA'                 => ['texto' => 'Venta',                'color' => '#dc2626'],
    'AJUSTE_POSITIVO'       => ['texto' => 'Ajuste (sobrante)',    'color' => '#16a34a'],
    'AJUSTE_NEGATIVO'       => ['texto' => 'Ajuste (faltante)',    'color' => '#dc2626'],
    'DEVOLUCION_CLIENTE'    => ['texto' => 'Devolución de cliente','color' => '#16a34a'],
    'DEVOLUCION_PROVEEDOR'  => ['texto' => 'Devolución a proveedor','color' => '#dc2626'],
];

require_once 'includes/header.php';

?>

<style>

.kardex-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 20px;
}

.kardex-header a.volver {
    display: inline-block;
    margin-bottom: 8px;
    color: #176b87;
    text-decoration: none;
    font-weight: 600;
    font-size: 14px;
}

.kardex-info {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 18px;
    margin-bottom: 24px;
}

.kardex-info-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 18px;
    box-shadow: 0 5px 15px rgba(0,0,0,.05);
}

.kardex-info-card .label {
    color: #64748b;
    font-size: 13px;
    margin-bottom: 6px;
}

.kardex-info-card .value {
    font-size: 20px;
    font-weight: 700;
    color: #123b5d;
}

.kardex-explicacion {
    background: #f0f9ff;
    border: 1px solid #bae6fd;
    border-radius: 12px;
    padding: 14px 18px;
    margin-bottom: 22px;
    font-size: 14px;
    color: #0c4a6e;
    line-height: 1.6;
}

.tabla-kardex {
    overflow-x: auto;
}

.tabla-kardex table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13.5px;
}

.tabla-kardex th {
    background: #f1f5f9;
    color: #334155;
    padding: 11px 9px;
    text-align: right;
    white-space: nowrap;
}

.tabla-kardex th:nth-child(1),
.tabla-kardex th:nth-child(2) {
    text-align: left;
}

.tabla-kardex td {
    padding: 11px 9px;
    border-bottom: 1px solid #e2e8f0;
    text-align: right;
    white-space: nowrap;
}

.tabla-kardex td:nth-child(1),
.tabla-kardex td:nth-child(2) {
    text-align: left;
}

.tabla-kardex tr.fila-entrada {
    background: #f0fdf4;
}

.tabla-kardex tr.fila-salida {
    background: #fef2f2;
}

.etiqueta-tipo {
    display: inline-block;
    padding: 3px 9px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    color: white;
}

.kardex-vacio {
    text-align: center;
    padding: 45px 20px;
    color: #64748b;
}

@media (max-width: 900px) {
    .kardex-info {
        grid-template-columns: 1fr;
    }
}

</style>


<div class="kardex-header">

    <div>

        <a href="inventario.php" class="volver">← Volver a Inventario</a>

        <h1 class="page-title">
            Kardex: <?php echo htmlspecialchars($producto['nombre']); ?>
        </h1>

        <p style="color:#64748b; margin:0;">
            Código: <strong><?php echo htmlspecialchars($producto['codigo']); ?></strong>
            &nbsp;·&nbsp;
            Categoría: <?php echo htmlspecialchars($producto['categoria']); ?>
        </p>

    </div>

</div>


<div class="kardex-explicacion">

    📖 <strong>¿Qué es esta tabla?</strong>
    Es el historial completo de este producto: cada vez que entró
    (compra) o salió (venta/ajuste), con su costo y el saldo que
    quedaba después. Esto es lo que exige el Código Tributario
    (Art. 142-A) para poder demostrar, en cualquier momento, cómo
    se llegó a la cantidad y el valor actual del producto — no
    basta con mostrar solo el número final.

</div>


<div class="kardex-info">

    <div class="kardex-info-card">
        <div class="label">Método de valuación</div>
        <div class="value" style="font-size:16px;">
            <?php echo $etiquetasMetodo[$metodo] ?? $metodo; ?>
        </div>
    </div>

    <div class="kardex-info-card">
        <div class="label">Existencia actual (unidades)</div>
        <div class="value"><?php echo number_format($saldoActual['unidades'], 2); ?></div>
    </div>

    <div class="kardex-info-card">
        <div class="label">Valor actual del inventario</div>
        <div class="value"><?php echo fmt($saldoActual['valor']); ?></div>
    </div>

    <div class="kardex-info-card">
        <div class="label">Total entradas (compras)</div>
        <div class="value"><?php echo number_format($totalEntradasUnidades, 2); ?> uds</div>
        <div style="font-size:13px;color:#64748b;"><?php echo fmt($totalEntradasImporte); ?></div>
    </div>

    <div class="kardex-info-card">
        <div class="label">Total salidas (ventas)</div>
        <div class="value"><?php echo number_format($totalSalidasUnidades, 2); ?> uds</div>
        <div style="font-size:13px;color:#64748b;"><?php echo fmt($totalSalidasImporte); ?></div>
    </div>

    <div class="kardex-info-card">
        <div class="label">Rotación del inventario</div>
        <div class="value"><?php echo number_format($rotacion, 2); ?>x</div>
        <div style="font-size:13px;color:#64748b;">
            Última compra: <?php echo $ultimaCompra ? date('d/m/Y', strtotime($ultimaCompra)) : '—'; ?><br>
            Última venta: <?php echo $ultimaVenta ? date('d/m/Y', strtotime($ultimaVenta)) : '—'; ?>
        </div>
    </div>

</div>


<!-- Filtros -->
<div class="card" style="margin-bottom: 20px;">
    <h3 style="margin-bottom: 12px;">🔍 Filtros</h3>
    <form method="GET" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
        <input type="hidden" name="producto" value="<?php echo $productoId; ?>">

        <div>
            <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px;">Desde</label>
            <input type="date" name="fecha_desde" value="<?php echo htmlspecialchars($filtroFechaDesde); ?>"
                   style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px;">
        </div>

        <div>
            <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px;">Hasta</label>
            <input type="date" name="fecha_hasta" value="<?php echo htmlspecialchars($filtroFechaHasta); ?>"
                   style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px;">
        </div>

        <div>
            <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px;">Tipo</label>
            <select name="tipo" style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; min-width: 180px;">
                <option value="">Todos los tipos</option>
                <option value="COMPRA" <?php echo $filtroTipo === 'COMPRA' ? 'selected' : ''; ?>>Compra</option>
                <option value="VENTA" <?php echo $filtroTipo === 'VENTA' ? 'selected' : ''; ?>>Venta</option>
                <option value="AJUSTE_POSITIVO" <?php echo $filtroTipo === 'AJUSTE_POSITIVO' ? 'selected' : ''; ?>>Ajuste positivo</option>
                <option value="AJUSTE_NEGATIVO" <?php echo $filtroTipo === 'AJUSTE_NEGATIVO' ? 'selected' : ''; ?>>Ajuste negativo</option>
                <option value="DEVOLUCION_CLIENTE" <?php echo $filtroTipo === 'DEVOLUCION_CLIENTE' ? 'selected' : ''; ?>>Devolución de cliente</option>
                <option value="DEVOLUCION_PROVEEDOR" <?php echo $filtroTipo === 'DEVOLUCION_PROVEEDOR' ? 'selected' : ''; ?>>Devolución a proveedor</option>
            </select>
        </div>

        <button type="submit" class="btn" style="padding: 8px 16px;">Aplicar filtros</button>
        <a href="kardex.php?producto=<?php echo $productoId; ?>" class="btn secondary" style="padding: 8px 16px;">Limpiar</a>
    </form>
</div>

<div class="card">

    <h2>📋 Movimientos registrados</h2>

    <p style="color:#64748b;">
        Ordenados del más antiguo al más reciente. Las filas verdes son
        entradas (aumentan el inventario), las rojas son salidas (lo
        disminuyen).
    </p>


    <?php if (count($movimientos) > 0): ?>

        <div class="tabla-kardex">

            <table>

                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Movimiento</th>
                        <th>Entra</th>
                        <th>Sale</th>
                        <th>Saldo (unidades)</th>
                        <th>Costo unitario</th>
                        <th>Valor entrada</th>
                        <th>Valor salida</th>
                        <th>Saldo (valor)</th>
                        <th>Asiento</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($movimientos as $mov):

                    $esEntrada = (float)$mov['unidades_entrada'] > 0;
                    $infoTipo = $etiquetasTipo[$mov['tipo_movimiento']]
                        ?? ['texto' => $mov['tipo_movimiento'], 'color' => '#64748b'];

                ?>

                    <tr class="<?php echo $esEntrada ? 'fila-entrada' : 'fila-salida'; ?>">

                        <td><?php echo htmlspecialchars($mov['fecha']); ?></td>

                        <td>
                            <span class="etiqueta-tipo" style="background: <?php echo $infoTipo['color']; ?>;">
                                <?php echo $infoTipo['texto']; ?>
                            </span>
                            <div style="font-size:11px;color:#64748b;margin-top:5px;line-height:1.4;">
                                Correlativo: <?php echo (int)$mov['correlativo']; ?><br>
                                Documento: <?php echo htmlspecialchars(($mov['tipo_documento'] ?: 'NINGUNO') . ($mov['numero_documento'] ? ' ' . $mov['numero_documento'] : '')); ?><?php if (!empty($mov['proveedor_nombre'])): ?><br>
                                Proveedor: <?php echo htmlspecialchars($mov['proveedor_nombre']); ?><?php endif; ?><?php if (!empty($mov['proveedor_nacionalidad'])): ?><br>
                                Nacionalidad: <?php echo htmlspecialchars($mov['proveedor_nacionalidad']); ?><?php endif; ?><?php if (!empty($mov['referencia_fuente_costo'])): ?><br>
                                Fuente del costo: <?php echo htmlspecialchars($mov['referencia_fuente_costo']); ?><?php endif; ?>
                            </div>
                        </td>

                        <td>
                            <?php echo $mov['unidades_entrada'] > 0
                                ? number_format($mov['unidades_entrada'], 2)
                                : '—'; ?>
                        </td>

                        <td>
                            <?php echo $mov['unidades_salida'] > 0
                                ? number_format($mov['unidades_salida'], 2)
                                : '—'; ?>
                        </td>

                        <td><strong><?php echo number_format($mov['saldo_unidades'], 2); ?></strong></td>

                        <td><?php echo fmt($mov['costo_unitario']); ?></td>

                        <td>
                            <?php echo $mov['importe_entrada'] > 0
                                ? fmt($mov['importe_entrada'])
                                : '—'; ?>
                        </td>

                        <td>
                            <?php echo $mov['importe_salida'] > 0
                                ? fmt($mov['importe_salida'])
                                : '—'; ?>
                        </td>

                        <td><strong><?php echo fmt($mov['saldo_monetario']); ?></strong></td>

                        <td>
                            <?php if (!empty($mov['asiento_id'])): ?>
                                <a href="libro_diario.php" class="etiqueta-tipo" style="background: #176b87; text-decoration: none;">
                                    #<?php echo (int)$mov['asiento_id']; ?>
                                </a>
                            <?php else: ?>
                                <span style="color: #94a3b8;">—</span>
                            <?php endif; ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php else: ?>

        <div class="kardex-vacio">
            <div style="font-size:48px; margin-bottom:10px;">📋</div>
            <h3>Este producto no tiene movimientos registrados todavía</h3>
            <p>
                Agrega existencia desde el formulario de Inventario para
                que aparezca aquí el primer movimiento.
            </p>
        </div>

    <?php endif; ?>

</div>


<?php require_once 'includes/footer.php'; ?>