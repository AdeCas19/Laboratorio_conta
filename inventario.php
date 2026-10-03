<?php

require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/functions.php';
require_once 'includes/kardex_functions.php';

// ⚠️ AJUSTA ESTA LÍNEA: usa el nombre real de la variable de sesión
// donde guardas el ID del usuario logueado (revisa includes/auth.php).
$usuarioActualId = (int)($_SESSION['usuario_id'] ?? 0);

$tituloPagina = 'Inventario';
$activo = 'inventario';

$mensaje = '';
$tipoMensaje = '';

if (isset($_GET['err']) && $_GET['err'] === 'csrf') {
    $mensaje = 'La sesión del formulario venció. Actualiza la página e intenta nuevamente.';
    $tipoMensaje = 'error';
}

// Todos los formularios de inventario deben superar la validación CSRF.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenInventario = $_POST['_csrf'] ?? null;
    if (!verificarCsrf(is_string($tokenInventario) ? $tokenInventario : null, 'inventario')) {
        header('Location: inventario.php?err=csrf');
        exit;
    }
}


/* ============================================================
   PROCESAR ACCIONES
   ============================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $accion = $_POST['accion'] ?? '';


    /* ========================================================
       AGREGAR PRODUCTO
       ======================================================== */

    if ($accion === 'agregar') {

        $codigo = textoLimpio($_POST['codigo'] ?? '');
        $nombre = textoLimpio($_POST['nombre'] ?? '');
        $categoria = textoLimpio($_POST['categoria'] ?? '');
        $cantidadTexto = textoLimpio($_POST['cantidad'] ?? '0');
        $valorTexto = textoLimpio($_POST['valor_unitario'] ?? '0');
        $stockTexto = textoLimpio($_POST['stock_minimo'] ?? '1');

        $errorValidacion = null;
        try {
            $cantidad = decimalKardex($cantidadTexto === '' ? '0' : $cantidadTexto, true, 2);
            $valorUnitario = decimalKardex($valorTexto === '' ? '0' : $valorTexto, true, 4);
            $stockMinimo = decimalKardex($stockTexto === '' ? '1' : $stockTexto, true, 2);
        } catch (Throwable $e) {
            $errorValidacion = $e->getMessage();
        }

        if (
            $codigo === '' || $nombre === '' || $categoria === '' ||
            longitudTexto($codigo) > 30 ||
            longitudTexto($nombre) > 150 ||
            longitudTexto($categoria) > 100 ||
            $errorValidacion !== null
        ) {
            $mensaje = $errorValidacion ?: 'Completa correctamente todos los campos obligatorios.';
            $tipoMensaje = 'error';
        } else {
            $conn->begin_transaction();
            try {
                // Se insertan en cero y el Kardex los calcula. Así nunca queda
                // un producto con cantidad contable sin un movimiento que la origine.
                $stmt = $conn->prepare("
                    INSERT INTO productos
                    (codigo, nombre, categoria, cantidad, valor_unitario, stock_minimo)
                    VALUES (?, ?, ?, 0, 0, ?)
                ");
                $stmt->bind_param('ssss', $codigo, $nombre, $categoria, $stockMinimo);
                if (!$stmt->execute()) {
                    throw new RuntimeException('No fue posible agregar el producto.');
                }

                $nuevoProductoId = (int)$conn->insert_id;
                $stmt->close();

                if ($cantidad > 0) {
                    registrarEntrada($conn, [
                        'producto_id'             => $nuevoProductoId,
                        'tipo_movimiento'         => 'INVENTARIO_INICIAL',
                        'fecha'                   => date('Y-m-d'),
                        'cantidad'                => $cantidad,
                        'costo_unitario'          => $valorUnitario,
                        'tipo_documento'          => 'NINGUNO',
                        'numero_documento'        => null,
                        'proveedor_nombre'        => null,
                        'proveedor_nacionalidad'  => null,
                        'referencia_fuente_costo' => 'Registro inicial en el sistema',
                        'usuario_id'              => $usuarioActualId,
                        'manejar_transaccion'     => true,
                    ]);
                }

                $conn->commit();
                $mensaje = $cantidad > 0
                    ? 'Producto agregado y existencia inicial registrada en el Kardex.'
                    : 'Producto agregado correctamente.';
                $tipoMensaje = 'ok';
            } catch (Throwable $e) {
                $conn->rollback();
                $mensaje = $conn->errno === 1062
                    ? 'El código del producto ya existe.'
                    : mensajeErrorOperacion($e);
                $tipoMensaje = 'error';
            }
        }
    }


    /* ========================================================
       EDITAR PRODUCTO
       ======================================================== */

    if ($accion === 'editar') {

        $id = (int)($_POST['id'] ?? 0);
        $codigo = textoLimpio($_POST['codigo'] ?? '');
        $nombre = textoLimpio($_POST['nombre'] ?? '');
        $categoria = textoLimpio($_POST['categoria'] ?? '');
        $cantidadTexto = textoLimpio($_POST['cantidad'] ?? '0');
        $valorTexto = textoLimpio($_POST['valor_unitario'] ?? '0');
        $stockTexto = textoLimpio($_POST['stock_minimo'] ?? '1');

        $errorValidacion = null;
        try {
            $cantidad = decimalKardex($cantidadTexto === '' ? '0' : $cantidadTexto, true, 2);
            $valorUnitario = decimalKardex($valorTexto === '' ? '0' : $valorTexto, true, 4);
            $stockMinimo = decimalKardex($stockTexto === '' ? '1' : $stockTexto, true, 2);
        } catch (Throwable $e) {
            $errorValidacion = $e->getMessage();
        }

        $datosBasicosValidos = $id > 0
            && $codigo !== '' && $nombre !== '' && $categoria !== ''
            && longitudTexto($codigo) <= 30
            && longitudTexto($nombre) <= 150
            && longitudTexto($categoria) <= 100
            && $errorValidacion === null;

        if (!$datosBasicosValidos) {
            $mensaje = $errorValidacion ?: 'Producto o datos inválidos.';
            $tipoMensaje = 'error';
        } else {
            $transaccionIniciada = false;
            try {
                if (!$conn->begin_transaction()) {
                    throw new RuntimeException('No se pudo iniciar la transacción de edición.');
                }
                $transaccionIniciada = true;

                // Se bloquea el producto antes de leer el saldo para evitar
                // que dos ediciones simultáneas calculen diferencias obsoletas.
                $stmt = $conn->prepare('
                    SELECT id, valor_unitario
                    FROM productos
                    WHERE id = ? AND activo = 1
                    LIMIT 1
                    FOR UPDATE
                ');
                if (!$stmt) {
                    throw new RuntimeException('No se pudo preparar el bloqueo del producto.');
                }
                $stmt->bind_param('i', $id);
                if (!$stmt->execute()) {
                    throw new RuntimeException('No se pudo bloquear el producto para edición.');
                }
                $productoActual = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$productoActual) {
                    throw new InvalidArgumentException('El producto no existe o está inactivo.');
                }

                $saldoKardex = obtenerSaldoActual($conn, $id);
                $diferencia = round($cantidad - $saldoKardex['unidades'], 2);
                $costoActual = $saldoKardex['unidades'] > 0
                    ? $saldoKardex['valor'] / $saldoKardex['unidades']
                    : (float)$productoActual['valor_unitario'];

                if ($diferencia > 0 && $valorUnitario <= 0) {
                    $conn->commit();
                    $mensaje = 'Para aumentar la existencia debes indicar un costo unitario mayor a cero.';
                    $tipoMensaje = 'error';
                } else {
                    if ($diferencia > 0) {
                        registrarEntrada($conn, [
                            'producto_id'             => $id,
                            'tipo_movimiento'         => 'COMPRA',
                            'fecha'                   => date('Y-m-d'),
                            'cantidad'                => $diferencia,
                            'costo_unitario'          => $valorUnitario,
                            'tipo_documento'          => 'NINGUNO',
                            'numero_documento'        => null,
                            'proveedor_nombre'        => null,
                            'proveedor_nacionalidad'  => null,
                            'referencia_fuente_costo' => 'Ajuste desde edición de producto',
                            'usuario_id'              => $usuarioActualId,
                            'manejar_transaccion'     => true,
                        ]);
                    } elseif ($diferencia < 0) {
                        registrarSalida($conn, [
                            'producto_id'             => $id,
                            'tipo_movimiento'         => 'AJUSTE_NEGATIVO',
                            'fecha'                   => date('Y-m-d'),
                            'cantidad'                => abs($diferencia),
                            'tipo_documento'          => 'NINGUNO',
                            'numero_documento'        => null,
                            'referencia_fuente_costo' => 'Ajuste desde edición de producto',
                            'usuario_id'              => $usuarioActualId,
                            'manejar_transaccion'     => true,
                        ]);
                    }

                    $stmt = $conn->prepare('
                        UPDATE productos
                        SET codigo = ?, nombre = ?, categoria = ?, stock_minimo = ?, valor_unitario = ?, costo_promedio_actual = ?
                        WHERE id = ?
                    ');
                    if (!$stmt) {
                        throw new RuntimeException('No se pudo preparar la actualización del producto.');
                    }

                    $nuevoCostoPromedio = $saldoKardex['unidades'] > 0
                        ? $saldoKardex['valor'] / $saldoKardex['unidades']
                        : $valorUnitario;

                    $stmt->bind_param('sssdddi', $codigo, $nombre, $categoria, $stockMinimo, $valorUnitario, $nuevoCostoPromedio, $id);
                    if (!$stmt->execute()) {
                        throw new RuntimeException('No fue posible actualizar los datos del producto.');
                    }
                    $stmt->close();
                    if (!$conn->commit()) {
                        throw new RuntimeException('No se pudo confirmar la edición.');
                    }
                    $transaccionIniciada = false;
                    $mensaje = 'Producto actualizado correctamente.';
                    $tipoMensaje = 'ok';
                }
            } catch (Throwable $e) {
                if ($transaccionIniciada) {
                    $conn->rollback();
                }
                $mensaje = $e instanceof InvalidArgumentException
                    ? $e->getMessage()
                    : ($conn->errno === 1062 ? 'El código del producto ya existe.' : mensajeErrorOperacion($e));
                $tipoMensaje = 'error';
            }
        }
    }


    /* ========================================================
       ELIMINAR PRODUCTO
       ======================================================== */

    /* ========================================================
       ENTRADA / SALIDA DE INVENTARIO
    ======================================================== */

    if ($accion === 'entrada' || $accion === 'salida') {
        $id = (int)($_POST['id'] ?? 0);
        $cantidadTexto = textoLimpio($_POST['cantidad_movimiento'] ?? '0');
        $fecha = textoLimpio($_POST['fecha_movimiento'] ?? date('Y-m-d'));
        $tipoDocumento = textoLimpio($_POST['tipo_documento'] ?? 'NINGUNO');
        $numeroDocumento = textoLimpio($_POST['numero_documento'] ?? '') ?: null;
        $referencia = textoLimpio($_POST['referencia_fuente_costo'] ?? '') ?: null;
        $documentosValidos = ['CCF', 'FACTURA', 'NOTA_CREDITO', 'NOTA_DEBITO', 'DOC_SUJETO_EXCLUIDO', 'DECLARACION_MERCANCIA', 'NINGUNO'];
        $movimientoIniciado = false;

        try {
            if ($id <= 0 || !in_array($tipoDocumento, $documentosValidos, true) || !fechaKardexValida($fecha)
                || longitudTexto($numeroDocumento ?? '') > 50
                || longitudTexto($referencia ?? '') > 150
                || longitudTexto(textoLimpio($_POST['proveedor_nombre'] ?? '')) > 150
                || longitudTexto(textoLimpio($_POST['proveedor_nacionalidad'] ?? '')) > 60) {
                throw new InvalidArgumentException('Datos del movimiento inválidos.');
            }
            $cantidadMovimiento = decimalKardex($cantidadTexto, false, 2);
            $conn->begin_transaction();
            $movimientoIniciado = true;

            if ($accion === 'entrada') {
                $costoMovimiento = decimalKardex($_POST['costo_movimiento'] ?? '0', false, 4);
                registrarEntrada($conn, [
                    'producto_id'             => $id,
                    'tipo_movimiento'         => (($_POST['tipo_movimiento'] ?? 'COMPRA') === 'AJUSTE_POSITIVO') ? 'AJUSTE_POSITIVO' : 'COMPRA',
                    'fecha'                   => $fecha,
                    'cantidad'                => $cantidadMovimiento,
                    'costo_unitario'          => $costoMovimiento,
                    'tipo_documento'          => $tipoDocumento,
                    'numero_documento'        => $numeroDocumento,
                    'proveedor_nombre'        => textoLimpio($_POST['proveedor_nombre'] ?? '') ?: null,
                    'proveedor_nacionalidad'  => textoLimpio($_POST['proveedor_nacionalidad'] ?? '') ?: null,
                    'referencia_fuente_costo' => $referencia ?: 'Entrada registrada desde inventario',
                    'usuario_id'              => $usuarioActualId,
                    'manejar_transaccion'     => true,
                ]);
                $mensaje = 'Entrada de inventario registrada correctamente.';
            } else {
                $tipoSalida = (($_POST['tipo_movimiento'] ?? 'AJUSTE_NEGATIVO') === 'VENTA')
                    ? 'VENTA' : 'AJUSTE_NEGATIVO';
                $salida = registrarSalida($conn, [
                    'producto_id'             => $id,
                    'tipo_movimiento'         => $tipoSalida,
                    'fecha'                   => $fecha,
                    'cantidad'                => $cantidadMovimiento,
                    'tipo_documento'          => $tipoDocumento,
                    'numero_documento'        => $numeroDocumento,
                    'referencia_fuente_costo' => $referencia ?: 'Salida registrada desde inventario',
                    'usuario_id'              => $usuarioActualId,
                    'manejar_transaccion'     => true,
                ]);

                // Si es una venta, generar automáticamente el asiento de costo de ventas
                if ($tipoSalida === 'VENTA') {
                    $asientoId = generarAsientoCostoVentas(
                        $conn,
                        $salida['movimiento_id'],
                        $fecha,
                        $salida['costo_total_salida'],
                        'Costo de ventas - ' . ($numeroDocumento ?: 'Venta')
                    );
                    $mensaje = 'Venta registrada. Costo de ventas: $' . number_format($salida['costo_total_salida'], 2) . '. Asiento contable generado automáticamente.';
                } else {
                    $mensaje = 'Salida registrada. Costo de salida: $' . number_format($salida['costo_total_salida'], 2);
                }
            }

            $conn->commit();
            $tipoMensaje = 'ok';
        } catch (Throwable $e) {
            if ($movimientoIniciado) {
                $conn->rollback();
            }
            $mensaje = mensajeErrorOperacion($e);
            $tipoMensaje = 'error';
        }
    }

    if ($accion === 'eliminar') {

        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $transaccionIniciada = false;
            try {
                if (!$conn->begin_transaction()) {
                    throw new RuntimeException('No se pudo iniciar la transacción de baja.');
                }
                $transaccionIniciada = true;

                $stmt = $conn->prepare('SELECT id FROM productos WHERE id = ? LIMIT 1 FOR UPDATE');
                if (!$stmt) {
                    throw new RuntimeException('No se pudo preparar el bloqueo del producto.');
                }
                $stmt->bind_param('i', $id);
                if (!$stmt->execute()) {
                    throw new RuntimeException('No se pudo bloquear el producto para desactivarlo.');
                }
                $producto = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$producto) {
                    throw new InvalidArgumentException('El producto no existe.');
                }

                $saldoKardex = obtenerSaldoActual($conn, $id);
                if ($saldoKardex['unidades'] > 0.0001) {
                    $conn->commit();
                    $transaccionIniciada = false;
                    $mensaje = 'No se puede desactivar: el producto todavía tiene '
                        . number_format($saldoKardex['unidades'], 2)
                        . ' unidades en existencia según el Kardex. Registra primero una salida o ajuste.';
                    $tipoMensaje = 'error';
                } else {
                    // Nunca se borra físicamente un producto con historial contable.
                    $stmt = $conn->prepare('UPDATE productos SET activo = 0 WHERE id = ?');
                    if (!$stmt) {
                        throw new RuntimeException('No se pudo preparar la desactivación del producto.');
                    }
                    $stmt->bind_param('i', $id);
                    if (!$stmt->execute()) {
                        throw new RuntimeException('No fue posible desactivar el producto.');
                    }
                    $stmt->close();
                    if (!$conn->commit()) {
                        throw new RuntimeException('No se pudo confirmar la desactivación.');
                    }
                    $transaccionIniciada = false;
                    $mensaje = 'Producto desactivado. Su historial del Kardex se conserva.';
                    $tipoMensaje = 'ok';
                }
            } catch (Throwable $e) {
                if ($transaccionIniciada) {
                    $conn->rollback();
                }
                $mensaje = $e instanceof InvalidArgumentException
                    ? $e->getMessage()
                    : mensajeErrorOperacion($e);
                $tipoMensaje = 'error';
            }
        }
    }
}


/* ============================================================
   PRODUCTO PARA EDITAR
   ============================================================ */

$productoEditar = null;

if (
    isset($_GET['editar']) &&
    (int)$_GET['editar'] > 0
) {

    $idEditar = (int)$_GET['editar'];

    $stmt = $conn->prepare("
        SELECT
            id,
            codigo,
            nombre,
            categoria,
            cantidad,
            valor_unitario,
            stock_minimo
        FROM productos
        WHERE id = ?
          AND activo = 1
        LIMIT 1
    ");

    $stmt->bind_param('i', $idEditar);
    $stmt->execute();

    $resultadoEditar = $stmt->get_result();

    if ($resultadoEditar->num_rows > 0) {
        $productoEditar = $resultadoEditar->fetch_assoc();
        $saldoEditar = obtenerSaldoActual($conn, (int)$productoEditar['id']);
        $productoEditar['cantidad'] = number_format($saldoEditar['unidades'], 2, '.', '');
        $productoEditar['valor_unitario'] = $saldoEditar['unidades'] > 0
            ? number_format($saldoEditar['valor'] / $saldoEditar['unidades'], 2, '.', '')
            : '0.00';
    }

    $stmt->close();
}


/* ============================================================
   PRODUCTO PARA MOVIMIENTO
   ============================================================ */

$productoMovimiento = null;
$movimientoSolicitado = textoLimpio($_GET['movimiento'] ?? '');
$idMovimiento = (int)($_GET['producto'] ?? 0);

if (in_array($movimientoSolicitado, ['entrada', 'salida'], true) && $idMovimiento > 0) {
    $stmt = $conn->prepare('SELECT id, codigo, nombre, cantidad, valor_unitario FROM productos WHERE id = ? AND activo = 1 LIMIT 1');
    $stmt->bind_param('i', $idMovimiento);
    $stmt->execute();
    $productoMovimiento = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($productoMovimiento) {
        $saldoMovimiento = obtenerSaldoActual($conn, $idMovimiento);
        $productoMovimiento['cantidad'] = number_format($saldoMovimiento['unidades'], 2, '.', '');
        $productoMovimiento['valor_unitario'] = $saldoMovimiento['unidades'] > 0
            ? number_format($saldoMovimiento['valor'] / $saldoMovimiento['unidades'], 2, '.', '')
            : '0.00';
    }
}


/* ============================================================
   PRODUCTOS
   ============================================================ */

$productos = [];

$resProductos = $conn->query("
    SELECT
        id,
        codigo,
        nombre,
        categoria,
        cantidad,
        valor_unitario,
        stock_minimo,
        (cantidad * valor_unitario) AS valor_total
    FROM productos
    WHERE activo = 1
    ORDER BY codigo ASC
");

if ($resProductos) {

    while ($producto = $resProductos->fetch_assoc()) {
        // El saldo mostrado se obtiene del Kardex, no de una cantidad editable.
        $saldoKardexProducto = obtenerSaldoActual($conn, (int)$producto['id']);
        $producto['cantidad'] = number_format($saldoKardexProducto['unidades'], 2, '.', '');
        $producto['valor_unitario'] = $saldoKardexProducto['unidades'] > 0
            ? number_format($saldoKardexProducto['valor'] / $saldoKardexProducto['unidades'], 2, '.', '')
            : '0.00';
        $producto['valor_total'] = number_format($saldoKardexProducto['valor'], 2, '.', '');
        $productos[] = $producto;
    }
}


/* ============================================================
   TOTALES DE PRODUCTOS
   ============================================================ */

$totalProductos = count($productos);

$totalUnidades = 0;
$valorTotalProductos = 0;
$productosStockBajo = 0;


foreach ($productos as $producto) {

    $totalUnidades += (float)$producto['cantidad'];

    $valorTotalProductos +=
        (float)$producto['valor_total'];


    if (
        (float)$producto['cantidad'] <=
        (float)$producto['stock_minimo']
    ) {

        $productosStockBajo++;
    }
}


/* ============================================================
   CUENTA CONTABLE DE INVENTARIO
   ============================================================ */

$cuentaInventario = null;

$res = $conn->query("
    SELECT
        id,
        codigo,
        nombre,
        naturaleza
    FROM cuentas
    WHERE codigo = '1105'
      AND activa = 1
      AND permite_movimiento = 1
    LIMIT 1
");

if ($res && $res->num_rows > 0) {
    $cuentaInventario = $res->fetch_assoc();
}


/* ============================================================
   MOVIMIENTOS CONTABLES
   ============================================================ */

$totalDebe = 0;
$totalHaber = 0;
$saldoInventario = 0;
$movimientos = [];


if ($cuentaInventario) {

    $stmt = $conn->prepare("
        SELECT
            a.fecha,
            a.numero_partida,
            a.concepto,
            d.detalle,
            d.debe,
            d.haber
        FROM detalle_asientos d

        INNER JOIN asientos a
            ON a.id = d.asiento_id

        WHERE d.cuenta_id = ?

        ORDER BY
            a.fecha ASC,
            a.numero_partida ASC,
            d.id ASC
    ");

    $stmt->bind_param(
        'i',
        $cuentaInventario['id']
    );

    $stmt->execute();

    $resultado = $stmt->get_result();


    while ($mov = $resultado->fetch_assoc()) {

        $debe = (float)$mov['debe'];
        $haber = (float)$mov['haber'];

        $totalDebe += $debe;
        $totalHaber += $haber;

        $saldoInventario +=
            $debe - $haber;

        $mov['saldo'] =
            $saldoInventario;

        $movimientos[] = $mov;
    }

    $stmt->close();
}

$diferenciaInventarioContable = round($saldoInventario - $valorTotalProductos, 2);
$inventarioConciliado = abs($diferenciaInventarioContable) < 0.01;
$movimientosSinAsiento = 0;
$resSinAsiento = $conn->query('SELECT COUNT(*) AS n FROM movimientos_inventario WHERE asiento_id IS NULL');
if ($resSinAsiento && ($fila = $resSinAsiento->fetch_assoc())) {
    $movimientosSinAsiento = (int)$fila['n'];
    $resSinAsiento->free();
}


/* ============================================================
   ESTADO CONTABLE
   ============================================================ */

if ($saldoInventario > 0) {

    $estadoInventario = 'Con existencia';
    $claseEstado = 'estado-ok';

} elseif ($saldoInventario == 0) {

    $estadoInventario = 'Sin existencia';
    $claseEstado = 'estado-alerta';

} else {

    $estadoInventario = 'Revisar saldo';
    $claseEstado = 'estado-error';
}


require_once 'includes/header.php';

?>


<style>

/* ============================================================
   ENCABEZADO
   ============================================================ */

.inventario-header {

    display: flex;
    justify-content: space-between;
    align-items: center;

    gap: 20px;

    margin-bottom: 22px;

    flex-wrap: wrap;
}


.inventario-header h1 {
    margin-bottom: 5px;
}


.inventario-header p {

    margin: 0;

    color: #64748b;
}


.inventario-icon {

    width: 58px;
    height: 58px;

    border-radius: 15px;

    background:
        linear-gradient(
            135deg,
            #0f3d5e,
            #176b87
        );

    display: flex;

    align-items: center;
    justify-content: center;

    color: white;

    font-size: 28px;

    box-shadow:
        0 8px 20px
        rgba(15, 61, 94, .20);
}


/* ============================================================
   KPIs
   ============================================================ */

.inventario-resumen {

    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

    gap: 18px;

    margin-bottom: 24px;
}


.inventario-kpi {

    background: white;

    border-radius: 14px;

    padding: 20px;

    border: 1px solid #e2e8f0;

    box-shadow:
        0 5px 15px
        rgba(0,0,0,.05);
}


.inventario-kpi .kpi-label {

    color: #64748b;

    font-size: 14px;

    margin-bottom: 8px;
}


.inventario-kpi .kpi-value {

    font-size: 25px;

    font-weight: 700;

    color: #123b5d;
}


/* ============================================================
   FORMULARIO
   ============================================================ */

.inventario-form-grid {

    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

    gap: 16px;

    margin-bottom: 18px;
}


.campo {

    display: flex;

    flex-direction: column;

    gap: 6px;
}


.campo label {

    font-weight: 600;

    color: #334155;

    font-size: 14px;
}


.campo input,
.campo select {

    width: 100%;

    padding: 11px 12px;

    border: 1px solid #cbd5e1;

    border-radius: 8px;

    font-size: 14px;

    box-sizing: border-box;

    background: white;
}


.campo input:focus,
.campo select:focus {

    outline: none;

    border-color: #176b87;

    box-shadow:
        0 0 0 3px
        rgba(23,107,135,.10);
}


/* ============================================================
   BOTONES
   ============================================================ */

.botones-form {

    display: flex;

    gap: 10px;

    flex-wrap: wrap;
}


.btn-inventario {

    border: none;

    border-radius: 8px;

    padding: 11px 18px;

    cursor: pointer;

    font-weight: 600;

    text-decoration: none;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    gap: 6px;
}


.btn-agregar {

    background: #176b87;

    color: white;
}


.btn-agregar:hover {

    background: #0f5269;
}


.btn-cancelar {

    background: #e2e8f0;

    color: #334155;
}


.btn-editar {

    background: #dbeafe;

    color: #1d4ed8;
}


.btn-kardex {

    background: #ede9fe;

    color: #6d28d9;
}


.btn-eliminar {

    background: #fee2e2;

    color: #b91c1c;
}


/* ============================================================
   MENSAJES
   ============================================================ */

.mensaje-inventario {

    padding: 13px 16px;

    border-radius: 9px;

    margin-bottom: 20px;

    font-weight: 600;
}


.mensaje-ok {

    background: #dcfce7;

    color: #166534;

    border: 1px solid #bbf7d0;
}


.mensaje-error {

    background: #fee2e2;

    color: #991b1b;

    border: 1px solid #fecaca;
}


/* ============================================================
   ESTADOS
   ============================================================ */

.estado-inventario {

    display: inline-block;

    padding: 6px 10px;

    border-radius: 20px;

    font-size: 12px;

    font-weight: 700;
}


.estado-ok {

    background: #dcfce7;

    color: #166534;
}


.estado-alerta {

    background: #fef3c7;

    color: #92400e;
}


.estado-error {

    background: #fee2e2;

    color: #991b1b;
}


/* ============================================================
   TABLA
   ============================================================ */

.inventario-tabla {

    overflow-x: auto;
}


.inventario-tabla table {

    width: 100%;

    border-collapse: collapse;
}


.inventario-tabla th {

    background: #f1f5f9;

    color: #334155;

    font-size: 13px;

    padding: 13px 10px;

    text-align: left;

    white-space: nowrap;
}


.inventario-tabla td {

    padding: 13px 10px;

    border-bottom:
        1px solid #e2e8f0;

    font-size: 14px;

    vertical-align: middle;
}


.inventario-tabla tbody tr:hover {

    background: #f8fafc;
}


.text-right {

    text-align: right !important;
}


.text-center {

    text-align: center !important;
}


.acciones {

    display: flex;

    justify-content: center;

    gap: 7px;

    flex-wrap: wrap;
}


.acciones form {

    margin: 0;
}


/* ============================================================
   CUENTA CONTABLE
   ============================================================ */

.inventario-cuenta {

    background:
        linear-gradient(
            135deg,
            #f8fafc,
            #eef6fa
        );

    border: 1px solid #dbe7ee;

    border-radius: 14px;

    padding: 18px;

    margin-bottom: 24px;
}


.inventario-cuenta strong {

    color: #123b5d;
}


/* ============================================================
   VACÍO
   ============================================================ */

.inventario-vacio {

    text-align: center;

    padding: 45px 20px;

    color: #64748b;
}


.inventario-vacio .icon {

    font-size: 48px;

    margin-bottom: 10px;
}


/* ============================================================
   RESPONSIVE
   ============================================================ */

@media (max-width: 1000px) {

    .inventario-resumen {

        grid-template-columns:
            repeat(2, 1fr);
    }


    .inventario-form-grid {

        grid-template-columns:
            repeat(2, 1fr);
    }
}


@media (max-width: 600px) {

    .inventario-resumen {

        grid-template-columns: 1fr;
    }


    .inventario-form-grid {

        grid-template-columns: 1fr;
    }

}

/* ============================================================
   DISEÑO MODERNO
   ============================================================ */

.inventario-hero-card {

    padding: 26px 30px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 24px;

    border: 0;

    background:
        linear-gradient(
            120deg,
            #0a2948,
            #123b63 60%,
            #176b87
        );

    color: #fff;

}


.inventario-hero-copy {

    flex: 1;

}


.inventario-hero-card .page-title {

    margin: 0 0 8px;

    color: #fff;

}


.inventario-hero-card .diario-kicker {

    color: #8be8fa;

}


.inventario-hero-card .help-text {

    max-width: 720px;

    color: rgba(255, 255, 255, 0.72);

    font-size: 14px;

    line-height: 1.6;

}


.inventario-hero-card .diario-compliance span {

    background: rgba(53, 181, 223, 0.16);

    color: #8be8fa;

}


.inventario-summary-grid {

    grid-template-columns: repeat(4, minmax(0, 1fr));

    margin-bottom: 22px;

}


.inventario-accounting-card,

.inventario-productos-card {

    border-top: 4px solid var(--celeste);

}


.inventario-accounting-summary .inventario-kpi {

    border-left: 4px solid var(--celeste);

    border-radius: 9px;

}


.inventario-conciliacion-status {

    display: block;

    margin-bottom: 18px;

    padding: 12px 15px;

    border-radius: 8px;

}


.inventario-table th {

    position: sticky;

    top: 0;

    z-index: 1;

    background: var(--azul);

    color: #fff;

}


.inventario-table td {

    border-bottom-color: var(--borde);

}


.inventario-codigo {

    display: inline-block;

    padding: 5px 8px;

    border-radius: 999px;

    background: var(--azul-claro);

    color: var(--azul);

    font-size: 11px;

    font-weight: 800;

}


.inventario-producto-nombre {

    color: var(--negro-suave);

    font-weight: 600;

}


.inventario-categoria {

    display: inline-block;

    padding: 5px 8px;

    border-radius: 999px;

    background: var(--gris-claro);

    color: var(--gris-oscuro);

    font-size: 11px;

    font-weight: 700;

}


@media (max-width: 1000px) {

    .inventario-summary-grid {

        grid-template-columns: repeat(2, minmax(0, 1fr));

    }

}


@media (max-width: 600px) {

    .inventario-hero-card {

        align-items: flex-start;

        flex-direction: column;

    }


    .inventario-summary-grid {

        grid-template-columns: 1fr;

    }

}

</style>


<!-- ============================================================
     ENCABEZADO
     ============================================================ -->

<div class="card inventario-hero-card">

    <div class="inventario-hero-copy">

        <p class="diario-kicker">Control de existencias</p>

        <h1 class="page-title">Inventario</h1>

        <p class="help-text">
            Administra los productos y existencias de Electrodomésticos
            Santa Ana. El Kardex es la fuente de verdad y la página muestra
            la conciliación entre el inventario contable y las existencias.
        </p>

        <div class="diario-compliance" aria-label="Control contable del inventario">
            <span>✔ Kardex como fuente de verdad</span>
            <span>✔ NIIF para PYMES</span>
            <span>✔ El Salvador</span>
        </div>

    </div>

    <div class="inventario-icon">
        📦
    </div>

</div>


<?php if ($mensaje !== ''): ?>

    <div class="
        mensaje-inventario
        <?php echo $tipoMensaje === 'ok'
            ? 'mensaje-ok'
            : 'mensaje-error'; ?>
    ">

        <?php echo htmlspecialchars($mensaje); ?>

    </div>

<?php endif; ?>


<!-- ============================================================
     RESUMEN DE PRODUCTOS
     ============================================================ -->

<div class="diario-summary-grid inventario-summary-grid">

    <div class="diario-summary-card">
        <span class="diario-summary-label">Productos activos</span>
        <strong class="diario-summary-value"><?php echo $totalProductos; ?></strong>
        <small>Registrados en el catálogo</small>
    </div>

    <div class="diario-summary-card">
        <span class="diario-summary-label">Unidades en existencia</span>
        <strong class="diario-summary-value">
            <?php echo number_format($totalUnidades, 0); ?>
        </strong>
        <small>Según el Kardex</small>
    </div>

    <div class="diario-summary-card">
        <span class="diario-summary-label">Valor del inventario</span>
        <strong class="diario-summary-value">
            <?php echo fmt($valorTotalProductos); ?>
        </strong>
        <small>Según el Kardex</small>
    </div>

    <div class="diario-summary-card diario-summary-next">
        <span class="diario-summary-label">Stock bajo</span>
        <strong class="diario-summary-value"><?php echo $productosStockBajo; ?></strong>
        <small>Requieren revisión</small>
    </div>

</div>


<!-- ============================================================
     INVENTARIO CONTABLE
     ============================================================ -->

<div class="card inventario-accounting-card">

    <div class="diario-card-header">

        <div>
            <p class="diario-kicker">Control contable</p>
            <h2>Resumen contable del inventario</h2>
        </div>

        <span class="diario-badge">Cuenta 1105</span>

    </div>


    <?php if ($cuentaInventario): ?>

        <div class="inventario-cuenta">

            <strong>
                Cuenta:
            </strong>

            <?php echo htmlspecialchars(
                $cuentaInventario['codigo']
            ); ?>

            -

            <?php echo htmlspecialchars(
                $cuentaInventario['nombre']
            ); ?>

        </div>


        <div class="diario-summary-grid inventario-accounting-summary">


            <div class="inventario-kpi">

                <div class="kpi-label">
                    Total Debe
                </div>

                <div class="kpi-value">

                    <?php echo fmt(
                        $totalDebe
                    ); ?>

                </div>

            </div>


            <div class="inventario-kpi">

                <div class="kpi-label">
                    Total Haber
                </div>

                <div class="kpi-value">

                    <?php echo fmt(
                        $totalHaber
                    ); ?>

                </div>

            </div>


            <div class="inventario-kpi">

                <div class="kpi-label">
                    Saldo contable
                </div>

                <div class="kpi-value">

                    <?php echo fmt(
                        $saldoInventario
                    ); ?>

                </div>

            </div>


            <div class="inventario-kpi">

                <div class="kpi-label">
                    Estado
                </div>

                <div>

                    <span class="estado-inventario <?php echo $claseEstado; ?>">

                        <?php echo $estadoInventario; ?>

                    </span>

                </div>

            </div>


        </div>

        <?php if ($inventarioConciliado): ?>
            <div class="estado-inventario estado-ok inventario-conciliacion-status">
                ✔ El saldo contable coincide con el valor total del Kardex.
            </div>
        <?php else: ?>
            <div class="estado-inventario estado-error inventario-conciliacion-status">
                ✕ Inventario no conciliado: diferencia contable contra Kardex = <?php echo fmt($diferenciaInventarioContable); ?>.
                Revisa los movimientos sin contabilizar y registra su partida en el Libro Diario.
                Movimientos sin asiento enlazado: <?php echo $movimientosSinAsiento; ?>.
            </div>
        <?php endif; ?>


        <?php if (count($movimientos) > 0): ?>

            <div class="inventario-tabla">

                <table>

                    <thead>

                        <tr>

                            <th>
                                Fecha
                            </th>

                            <th>
                                Partida
                            </th>

                            <th>
                                Concepto
                            </th>

                            <th class="text-right">
                                Entrada
                            </th>

                            <th class="text-right">
                                Salida
                            </th>

                            <th class="text-right">
                                Saldo
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                    <?php foreach ($movimientos as $mov): ?>

                        <tr>

                            <td>
                                <?php echo htmlspecialchars(
                                    $mov['fecha']
                                ); ?>
                            </td>

                            <td>
                                #<?php echo (int)$mov['numero_partida']; ?>
                            </td>

                            <td>

                                <?php

                                echo htmlspecialchars(
                                    $mov['concepto']
                                    .
                                    (
                                        !empty($mov['detalle'])
                                            ? ' - ' . $mov['detalle']
                                            : ''
                                    )
                                );

                                ?>

                            </td>

                            <td class="text-right">

                                <?php

                                echo $mov['debe'] > 0
                                    ? fmt($mov['debe'])
                                    : '';

                                ?>

                            </td>

                            <td class="text-right">

                                <?php

                                echo $mov['haber'] > 0
                                    ? fmt($mov['haber'])
                                    : '';

                                ?>

                            </td>

                            <td class="text-right">

                                <strong>

                                    <?php echo fmt(
                                        $mov['saldo']
                                    ); ?>

                                </strong>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>


    <?php else: ?>

        <div class="inventario-vacio">

            <div class="icon">
                🧾
            </div>

            <h3>
                Cuenta contable de inventario no encontrada
            </h3>

            <p>
                Los productos sí pueden administrarse,
                pero debes crear una cuenta de Inventario
                o Mercaderías en el Catálogo de Cuentas
                para obtener el saldo contable.
            </p>
            <p class="help-text">
                <strong>Importante:</strong> el Kardex controla las existencias,
                pero los movimientos no crean asientos contables automáticamente.
                Registra el asiento correspondiente en el Libro Diario y concilia
                ambos saldos.
            </p>

        </div>

    <?php endif; ?>

</div>


<!-- ============================================================
     MOVIMIENTO DE INVENTARIO
     ============================================================ -->

<?php if ($productoMovimiento): ?>
<div class="card">
    <h2><?php echo $movimientoSolicitado === 'salida' ? '📤 Registrar salida' : '📥 Registrar entrada'; ?></h2>
    <p style="color:#64748b;">
        Producto: <strong><?php echo htmlspecialchars($productoMovimiento['codigo'] . ' - ' . $productoMovimiento['nombre']); ?></strong>
        · Existencia actual: <strong><?php echo htmlspecialchars($productoMovimiento['cantidad']); ?></strong>
    </p>
    <form method="POST">
        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrfToken('inventario'), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="accion" value="<?php echo $movimientoSolicitado === 'salida' ? 'salida' : 'entrada'; ?>">
        <input type="hidden" name="id" value="<?php echo (int)$productoMovimiento['id']; ?>">
        <div class="inventario-form-grid">
            <div class="campo">
                <label>Fecha</label>
                <input type="date" name="fecha_movimiento" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="campo">
                <label>Cantidad</label>
                <input type="number" name="cantidad_movimiento" min="0.01" step="0.01" value="0" required>
            </div>
            <?php if ($movimientoSolicitado === 'entrada'): ?>
            <div class="campo">
                <label>Costo unitario</label>
                <input type="number" name="costo_movimiento" min="0.0001" step="0.0001" value="0.00" required>
            </div>
            <div class="campo">
                <label>Tipo de movimiento</label>
                <select name="tipo_movimiento">
                    <option value="COMPRA">Compra</option>
                    <option value="AJUSTE_POSITIVO">Ajuste positivo</option>
                </select>
            </div>
            <?php else: ?>
            <div class="campo">
                <label>Tipo de salida</label>
                <select name="tipo_movimiento">
                    <option value="VENTA">Venta</option>
                    <option value="AJUSTE_NEGATIVO">Ajuste negativo / merma</option>
                </select>
            </div>
            <?php endif; ?>
            <div class="campo">
                <label>Tipo de documento</label>
                <select name="tipo_documento">
                    <option value="NINGUNO">Ninguno</option>
                    <option value="FACTURA">Factura</option>
                    <option value="CCF">Comprobante de crédito fiscal</option>
                    <option value="NOTA_CREDITO">Nota de crédito</option>
                    <option value="NOTA_DEBITO">Nota de débito</option>
                    <option value="DOC_SUJETO_EXCLUIDO">Documento sujeto a excluido</option>
                    <option value="DECLARACION_MERCANCIA">Declaración de mercancía</option>
                </select>
            </div>
            <div class="campo">
                <label>Número de documento</label>
                <input type="text" name="numero_documento" maxlength="50">
            </div>
            <?php if ($movimientoSolicitado === 'entrada'): ?>
            <div class="campo">
                <label>Proveedor</label>
                <input type="text" name="proveedor_nombre" maxlength="150">
            </div>
            <div class="campo">
                <label>Nacionalidad del proveedor</label>
                <input type="text" name="proveedor_nacionalidad" maxlength="60">
            </div>
            <?php endif; ?>
            <div class="campo">
                <label>Referencia del costo</label>
                <input type="text" name="referencia_fuente_costo" maxlength="150" placeholder="Opcional">
            </div>
        </div>
        <div class="botones-form">
            <button type="submit" class="btn-inventario btn-agregar">Guardar movimiento</button>
            <a href="inventario.php" class="btn-inventario btn-cancelar">Cancelar</a>
        </div>
    </form>
</div>
<?php endif; ?>


<!-- ============================================================
     FORMULARIO
     ============================================================ -->

<div class="card">

    <h2>

        <?php echo $productoEditar
            ? '✏️ Editar producto'
            : '➕ Agregar producto'; ?>

    </h2>


    <p style="color:#64748b;">

        <?php echo $productoEditar
            ? 'Modifica los datos del producto. La existencia y el costo se controlan mediante los movimientos registrados en el Kardex.'
            : 'Registra un nuevo producto. Si tiene existencia inicial, se creará su movimiento de Kardex automáticamente.'; ?>

    </p>


    <form method="POST">

        <input
            type="hidden"
            name="_csrf"
            value="<?php echo htmlspecialchars(csrfToken('inventario'), ENT_QUOTES, 'UTF-8'); ?>"
        >

        <input
            type="hidden"
            name="accion"
            value="<?php echo $productoEditar
                ? 'editar'
                : 'agregar'; ?>"
        >


        <?php if ($productoEditar): ?>

            <input
                type="hidden"
                name="id"
                value="<?php echo (int)$productoEditar['id']; ?>"
            >

        <?php endif; ?>


        <div class="inventario-form-grid">


            <div class="campo">

                <label>
                    Código
                </label>

                <input
                    type="text"
                    name="codigo"
                    required
                    value="<?php echo htmlspecialchars(
                        $productoEditar['codigo'] ?? ''
                    ); ?>"
                    placeholder="Ej: REF-001"
                >

            </div>


            <div class="campo">

                <label>
                    Producto
                </label>

                <input
                    type="text"
                    name="nombre"
                    required
                    value="<?php echo htmlspecialchars(
                        $productoEditar['nombre'] ?? ''
                    ); ?>"
                    placeholder="Nombre del producto"
                >

            </div>


            <div class="campo">

                <label>
                    Categoría
                </label>

                <select
                    name="categoria"
                    required
                >

                    <option value="">
                        Seleccionar categoría
                    </option>

                    <?php

                    $categorias = [
                        'Refrigeradoras',
                        'Televisores',
                        'Lavadoras',
                        'Cocina',
                        'Estufas',
                        'Ventiladores',
                        'Aire acondicionado',
                        'Otros'
                    ];

                    foreach ($categorias as $categoria):

                    ?>

                        <option
                            value="<?php echo htmlspecialchars($categoria); ?>"
                            <?php echo (
                                ($productoEditar['categoria'] ?? '')
                                === $categoria
                            )
                                ? 'selected'
                                : ''; ?>
                        >

                            <?php echo htmlspecialchars($categoria); ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="campo">

                <label>
                    Cantidad
                </label>

                <input
                    type="number"
                    name="cantidad"
                    min="0"
                    step="0.01"
                    required
                    <?php if ($productoEditar): ?><?php endif; ?>
                    value="<?php echo $productoEditar
                        ? htmlspecialchars($productoEditar['cantidad'])
                        : '0'; ?>"
                >

            </div>


            <div class="campo">

                <label>
                    Valor unitario
                </label>

                <input
                    type="number"
                    name="valor_unitario"
                    min="0"
                    step="0.01"
                    required
                    <?php if ($productoEditar): ?><?php endif; ?>
                    value="<?php echo $productoEditar
                        ? htmlspecialchars($productoEditar['valor_unitario'])
                        : '0.00'; ?>"
                >

            </div>


            <div class="campo">

                <label>
                    Stock mínimo
                </label>

                <input
                    type="number"
                    name="stock_minimo"
                    min="0"
                    step="0.01"
                    value="<?php echo $productoEditar
                        ? $productoEditar['stock_minimo']
                        : '1'; ?>"
                >

            </div>


        </div>


        <div class="botones-form">


            <button
                type="submit"
                class="btn-inventario btn-agregar"
            >

                <?php echo $productoEditar
                    ? '💾 Guardar cambios'
                    : '➕ Agregar producto'; ?>

            </button>


            <?php if ($productoEditar): ?>

                <a
                    href="inventario.php"
                    class="btn-inventario btn-cancelar"
                >

                    Cancelar

                </a>

            <?php endif; ?>


        </div>


    </form>

</div>


<!-- ============================================================
     LISTADO DE PRODUCTOS
     ============================================================ -->

<div class="card inventario-productos-card">

    <div class="diario-card-header">

        <div>
            <p class="diario-kicker">Catálogo de productos</p>
            <h2>Productos en inventario</h2>
        </div>

        <span class="diario-badge"><?php echo $totalProductos; ?> activos</span>

    </div>

    <p class="help-text">
        Consulta, modifica o elimina los productos registrados. El Kardex
        conserva el historial de cada existencia.
    </p>


    <?php if ($totalProductos > 0): ?>

        <div class="inventario-tabla">

            <table class="inventario-table">

                <thead>

                    <tr>

                        <th>
                            Código
                        </th>

                        <th>
                            Producto
                        </th>

                        <th>
                            Categoría
                        </th>

                        <th class="text-right">
                            Cantidad
                        </th>

                        <th class="text-right">
                            Valor unitario
                        </th>

                        <th class="text-right">
                            Valor total
                        </th>

                        <th class="text-center">
                            Estado
                        </th>

                        <th class="text-center">
                            Acciones
                        </th>

                    </tr>

                </thead>


                <tbody>

                <?php foreach ($productos as $producto): ?>


                    <?php

                    $stockBajo =
                        (float)$producto['cantidad']
                        <=
                        (float)$producto['stock_minimo'];

                    ?>


                    <tr>


                        <td>

                            <span class="inventario-codigo">

                                <?php echo htmlspecialchars(
                                    $producto['codigo']
                                ); ?>

                            </span>

                        </td>


                        <td class="inventario-producto-nombre">

                            <?php echo htmlspecialchars(
                                $producto['nombre']
                            ); ?>

                        </td>


                        <td>

                            <span class="inventario-categoria">
                                <?php echo htmlspecialchars(
                                    $producto['categoria']
                                ); ?>
                            </span>

                        </td>


                        <td class="text-right">

                            <?php echo number_format(
                                $producto['cantidad'],
                                2
                            ); ?>

                        </td>


                        <td class="text-right">

                            <?php echo fmt(
                                $producto['valor_unitario']
                            ); ?>

                        </td>


                        <td class="text-right">

                            <strong>

                                <?php echo fmt(
                                    $producto['valor_total']
                                ); ?>

                            </strong>

                        </td>


                        <td class="text-center">

                            <?php if ($stockBajo): ?>

                                <span class="estado-inventario estado-alerta">

                                    ⚠ Stock bajo

                                </span>

                            <?php else: ?>

                                <span class="estado-inventario estado-ok">

                                    ✓ Disponible

                                </span>

                            <?php endif; ?>

                        </td>


                        <td>

                            <div class="acciones">


                                <a
                                    href="kardex.php?producto=<?php echo (int)$producto['id']; ?>"
                                    class="btn-inventario btn-kardex"
                                >

                                    📋 Kardex

                                </a>


                                <a
                                    href="inventario.php?editar=<?php echo (int)$producto['id']; ?>"
                                    class="btn-inventario btn-editar"
                                >

                                    ✏️ Editar

                                </a>


                                <form
                                    method="POST"
                                    onsubmit="return confirm('¿Seguro que deseas desactivar este producto?');"
                                >

                                    <input
                                        type="hidden"
                                        name="_csrf"
                                        value="<?php echo htmlspecialchars(csrfToken('inventario'), ENT_QUOTES, 'UTF-8'); ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="accion"
                                        value="eliminar"
                                    >

                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?php echo (int)$producto['id']; ?>"
                                    >


                                    <button
                                        type="submit"
                                        class="btn-inventario btn-eliminar"
                                    >

                                        🗑️ Eliminar

                                    </button>

                                </form>


                            </div>

                        </td>


                    </tr>


                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php else: ?>

        <div class="inventario-vacio">

            <div class="icon">
                📦
            </div>

            <h3>
                No hay productos registrados
            </h3>

            <p>
                Agrega el primer producto utilizando el formulario.
            </p>

        </div>

    <?php endif; ?>

</div>


<?php

require_once 'includes/footer.php';

?>