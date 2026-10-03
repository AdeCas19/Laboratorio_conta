<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/functions.php';

$tituloPagina = 'Cuentas por Cobrar/Pagar';
$activo = 'cxc_cxp';

$mensaje = '';
$tipoMensaje = '';

/*
|--------------------------------------------------------------------------
| FUNCIONES AUXILIARES
|--------------------------------------------------------------------------
*/

/**
 * Obtener una cuenta contable por código.
 */
function obtenerCuentaPorCodigo(mysqli $conn, string $codigo): array
{
    $stmt = $conn->prepare("
        SELECT id, codigo, nombre, activa, permite_movimiento
        FROM cuentas
        WHERE codigo = ?
          AND activa = 1
          AND permite_movimiento = 1
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception('No se pudo preparar la consulta de la cuenta contable.');
    }

    $stmt->bind_param('s', $codigo);
    $stmt->execute();

    $resultado = $stmt->get_result();
    $cuenta = $resultado->fetch_assoc();

    $stmt->close();

    if (!$cuenta) {
        throw new Exception(
            "No se encontró una cuenta contable activa y con movimiento para el código {$codigo}."
        );
    }

    return $cuenta;
}

/**
 * Crear encabezado de asiento.
 */
function crearAsiento(
    mysqli $conn,
    int $numeroPartida,
    string $fecha,
    string $concepto,
    int $usuarioId
): int {
    // No se crean asientos dentro de un período ya cerrado.
    exigirPeriodoAbierto($conn, $fecha);
    $stmt = $conn->prepare("
        INSERT INTO asientos
        (numero_partida, fecha, concepto, usuario_id)
        VALUES (?, ?, ?, ?)
    ");

    if (!$stmt) {
        throw new Exception('No se pudo preparar el asiento contable.');
    }

    $stmt->bind_param(
        'issi',
        $numeroPartida,
        $fecha,
        $concepto,
        $usuarioId
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        throw new Exception('No se pudo crear el asiento contable: ' . $error);
    }

    $asientoId = (int)$conn->insert_id;

    $stmt->close();

    if ($asientoId <= 0) {
        throw new Exception('No se pudo obtener el ID del asiento contable.');
    }

    return $asientoId;
}

/**
 * Agregar detalle de asiento.
 */
function agregarDetalleAsiento(
    mysqli $conn,
    int $asientoId,
    int $cuentaId,
    string $detalle,
    float $debe,
    float $haber
): void {
    $stmt = $conn->prepare("
        INSERT INTO detalle_asientos
        (asiento_id, cuenta_id, detalle, debe, haber)
        VALUES (?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        throw new Exception('No se pudo preparar el detalle del asiento.');
    }

    $stmt->bind_param(
        'iisdd',
        $asientoId,
        $cuentaId,
        $detalle,
        $debe,
        $haber
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        throw new Exception(
            'No se pudo registrar el detalle del asiento: ' . $error
        );
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| PROCESAR ACCIONES POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | VALIDAR CSRF
    |--------------------------------------------------------------------------
    */
    if (!verificarCsrf($_POST['_csrf'] ?? null, 'cxc_cxp')) {
        $mensaje = 'La solicitud no es válida o el token de seguridad ha expirado.';
        $tipoMensaje = 'error';
    } else {

        $accion = $_POST['accion'] ?? '';

        /*
        |--------------------------------------------------------------------------
        | AGREGAR CUENTA POR COBRAR
        |--------------------------------------------------------------------------
        */
        if ($accion === 'agregar_cxc') {

            $clienteNombre = textoLimpio($_POST['cliente_nombre'] ?? '');
            $clienteDocumento = textoLimpio($_POST['cliente_documento'] ?? '') ?: null;
            $tipoDocumento = textoLimpio($_POST['tipo_documento'] ?? 'FACTURA');
            $numeroDocumento = textoLimpio($_POST['numero_documento'] ?? '');
            $fechaEmision = textoLimpio($_POST['fecha_emision'] ?? '');
            $fechaVencimiento = textoLimpio($_POST['fecha_vencimiento'] ?? '') ?: null;
            $monto = (float)($_POST['monto'] ?? 0);
            $observaciones = textoLimpio($_POST['observaciones'] ?? '') ?: null;

            if (
                $clienteNombre === '' ||
                $numeroDocumento === '' ||
                !fechaValida($fechaEmision) ||
                $monto <= 0
            ) {
                $mensaje = 'Complete correctamente todos los campos obligatorios.';
                $tipoMensaje = 'error';

            } elseif (
                $fechaVencimiento !== null &&
                !fechaValida($fechaVencimiento)
            ) {
                $mensaje = 'La fecha de vencimiento no es válida.';
                $tipoMensaje = 'error';

            } else {

                try {

                    $conn->begin_transaction();

                    /*
                    |--------------------------------------------------------------------------
                    | CUENTAS CONTABLES
                    |--------------------------------------------------------------------------
                    */
                    $cuentaCxc = obtenerCuentaPorCodigo($conn, '110201');
                    $cuentaVentas = obtenerCuentaPorCodigo($conn, '510101');

                    /*
                    |--------------------------------------------------------------------------
                    | ASIENTO
                    |--------------------------------------------------------------------------
                    */
                    $numeroPartida = siguienteNumeroPartida($conn);
                    $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

                    if ($usuarioId <= 0) {
                        throw new Exception('No se pudo identificar al usuario de la sesión.');
                    }

                    $concepto =
                        "Cuenta por cobrar - {$clienteNombre} - {$numeroDocumento}";

                    $asientoId = crearAsiento(
                        $conn,
                        $numeroPartida,
                        $fechaEmision,
                        $concepto,
                        $usuarioId
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | DEBE: CUENTAS POR COBRAR
                    |--------------------------------------------------------------------------
                    */
                    agregarDetalleAsiento(
                        $conn,
                        $asientoId,
                        (int)$cuentaCxc['id'],
                        "CxC {$clienteNombre} - {$numeroDocumento}",
                        $monto,
                        0
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | HABER: VENTAS
                    |--------------------------------------------------------------------------
                    */
                    agregarDetalleAsiento(
                        $conn,
                        $asientoId,
                        (int)$cuentaVentas['id'],
                        "Venta a crédito - {$numeroDocumento}",
                        0,
                        $monto
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | REGISTRAR CxC
                    |--------------------------------------------------------------------------
                    */
                    $stmt = $conn->prepare("
                        INSERT INTO cuentas_cobrar
                        (
                            cliente_nombre,
                            cliente_documento,
                            tipo_documento,
                            numero_documento,
                            fecha_emision,
                            fecha_vencimiento,
                            monto_original,
                            saldo_pendiente,
                            monto_pagado,
                            asiento_id,
                            observaciones
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
                    ");

                    if (!$stmt) {
                        throw new Exception(
                            'No se pudo preparar el registro de la cuenta por cobrar.'
                        );
                    }

                    $stmt->bind_param(
                        'ssssssddis',
                        $clienteNombre,
                        $clienteDocumento,
                        $tipoDocumento,
                        $numeroDocumento,
                        $fechaEmision,
                        $fechaVencimiento,
                        $monto,
                        $monto,
                        $asientoId,
                        $observaciones
                    );

                    if (!$stmt->execute()) {
                        $error = $stmt->error;
                        $stmt->close();

                        throw new Exception(
                            'No se pudo registrar la cuenta por cobrar: ' . $error
                        );
                    }

                    $stmt->close();

                    $conn->commit();

                    $mensaje = 'Cuenta por cobrar registrada correctamente.';
                    $tipoMensaje = 'ok';

                } catch (Throwable $e) {

                    if ($conn->in_transaction) {
                        $conn->rollback();
                    }

                    $mensaje = 'Error: ' . $e->getMessage();
                    $tipoMensaje = 'error';
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | AGREGAR CUENTA POR PAGAR
        |--------------------------------------------------------------------------
        */
        elseif ($accion === 'agregar_cxp') {

            $proveedorNombre = textoLimpio($_POST['proveedor_nombre'] ?? '');
            $proveedorDocumento = textoLimpio($_POST['proveedor_documento'] ?? '') ?: null;
            $tipoDocumento = textoLimpio($_POST['tipo_documento'] ?? 'FACTURA');
            $numeroDocumento = textoLimpio($_POST['numero_documento'] ?? '');
            $fechaEmision = textoLimpio($_POST['fecha_emision'] ?? '');
            $fechaVencimiento = textoLimpio($_POST['fecha_vencimiento'] ?? '') ?: null;
            $monto = (float)($_POST['monto'] ?? 0);
            $observaciones = textoLimpio($_POST['observaciones'] ?? '') ?: null;

            if (
                $proveedorNombre === '' ||
                $numeroDocumento === '' ||
                !fechaValida($fechaEmision) ||
                $monto <= 0
            ) {
                $mensaje = 'Complete correctamente todos los campos obligatorios.';
                $tipoMensaje = 'error';

            } elseif (
                $fechaVencimiento !== null &&
                !fechaValida($fechaVencimiento)
            ) {
                $mensaje = 'La fecha de vencimiento no es válida.';
                $tipoMensaje = 'error';

            } else {

                try {

                    $conn->begin_transaction();

                    /*
                    |--------------------------------------------------------------------------
                    | CUENTAS CONTABLES
                    |--------------------------------------------------------------------------
                    */
                    $cuentaCxp = obtenerCuentaPorCodigo($conn, '210201');
                    $cuentaInventario = obtenerCuentaPorCodigo($conn, '1105');

                    /*
                    |--------------------------------------------------------------------------
                    | ASIENTO
                    |--------------------------------------------------------------------------
                    */
                    $numeroPartida = siguienteNumeroPartida($conn);
                    $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

                    if ($usuarioId <= 0) {
                        throw new Exception('No se pudo identificar al usuario de la sesión.');
                    }

                    $concepto =
                        "Cuenta por pagar - {$proveedorNombre} - {$numeroDocumento}";

                    $asientoId = crearAsiento(
                        $conn,
                        $numeroPartida,
                        $fechaEmision,
                        $concepto,
                        $usuarioId
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | DEBE: INVENTARIOS
                    |--------------------------------------------------------------------------
                    */
                    agregarDetalleAsiento(
                        $conn,
                        $asientoId,
                        (int)$cuentaInventario['id'],
                        "Compra a crédito - {$numeroDocumento}",
                        $monto,
                        0
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | HABER: PROVEEDORES
                    |--------------------------------------------------------------------------
                    */
                    agregarDetalleAsiento(
                        $conn,
                        $asientoId,
                        (int)$cuentaCxp['id'],
                        "CxP {$proveedorNombre} - {$numeroDocumento}",
                        0,
                        $monto
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | REGISTRAR CxP
                    |--------------------------------------------------------------------------
                    */
                    $stmt = $conn->prepare("
                        INSERT INTO cuentas_pagar
                        (
                            proveedor_nombre,
                            proveedor_documento,
                            tipo_documento,
                            numero_documento,
                            fecha_emision,
                            fecha_vencimiento,
                            monto_original,
                            saldo_pendiente,
                            monto_pagado,
                            asiento_id,
                            observaciones
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
                    ");

                    if (!$stmt) {
                        throw new Exception(
                            'No se pudo preparar el registro de la cuenta por pagar.'
                        );
                    }

                    $stmt->bind_param(
                        'ssssssddis',
                        $proveedorNombre,
                        $proveedorDocumento,
                        $tipoDocumento,
                        $numeroDocumento,
                        $fechaEmision,
                        $fechaVencimiento,
                        $monto,
                        $monto,
                        $asientoId,
                        $observaciones
                    );

                    if (!$stmt->execute()) {
                        $error = $stmt->error;
                        $stmt->close();

                        throw new Exception(
                            'No se pudo registrar la cuenta por pagar: ' . $error
                        );
                    }

                    $stmt->close();

                    $conn->commit();

                    $mensaje = 'Cuenta por pagar registrada correctamente.';
                    $tipoMensaje = 'ok';

                } catch (Throwable $e) {

                    if ($conn->in_transaction) {
                        $conn->rollback();
                    }

                    $mensaje = 'Error: ' . $e->getMessage();
                    $tipoMensaje = 'error';
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | REGISTRAR PAGO DE CxC
        |--------------------------------------------------------------------------
        */
        elseif ($accion === 'pagar_cxc') {

            $cxcId = (int)($_POST['cxc_id'] ?? 0);
            $fechaPago = textoLimpio($_POST['fecha_pago'] ?? '');
            $montoPago = (float)($_POST['monto_pago'] ?? 0);
            $metodoPago = textoLimpio($_POST['metodo_pago'] ?? 'EFECTIVO');
            $referenciaPago = textoLimpio($_POST['referencia_pago'] ?? '') ?: null;

            if (
                $cxcId <= 0 ||
                !fechaValida($fechaPago) ||
                $montoPago <= 0
            ) {
                $mensaje = 'Complete correctamente todos los campos obligatorios.';
                $tipoMensaje = 'error';

            } else {

                try {

                    $conn->begin_transaction();

                    /*
                    |--------------------------------------------------------------------------
                    | BLOQUEAR CxC
                    |--------------------------------------------------------------------------
                    */
                    $stmt = $conn->prepare("
                        SELECT *
                        FROM cuentas_cobrar
                        WHERE id = ?
                          AND estado <> 'PAGADA'
                        FOR UPDATE
                    ");

                    if (!$stmt) {
                        throw new Exception('No se pudo consultar la cuenta por cobrar.');
                    }

                    $stmt->bind_param('i', $cxcId);
                    $stmt->execute();

                    $cxc = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$cxc) {
                        throw new Exception(
                            'La cuenta por cobrar no existe o ya está pagada.'
                        );
                    }

                    $saldoActual = (float)$cxc['saldo_pendiente'];

                    if ($montoPago > $saldoActual) {
                        throw new Exception(
                            'El monto del pago excede el saldo pendiente.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | CUENTAS CONTABLES
                    |--------------------------------------------------------------------------
                    */
                    $cuentaCaja = obtenerCuentaPorCodigo($conn, '110101');
                    $cuentaCxc = obtenerCuentaPorCodigo($conn, '110201');

                    /*
                    |--------------------------------------------------------------------------
                    | ASIENTO
                    |--------------------------------------------------------------------------
                    */
                    $numeroPartida = siguienteNumeroPartida($conn);
                    $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

                    if ($usuarioId <= 0) {
                        throw new Exception('No se pudo identificar al usuario de la sesión.');
                    }

                    $concepto =
                        "Pago CxC - {$cxc['cliente_nombre']} - {$cxc['numero_documento']}";

                    $asientoId = crearAsiento(
                        $conn,
                        $numeroPartida,
                        $fechaPago,
                        $concepto,
                        $usuarioId
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | DEBE: CAJA
                    |--------------------------------------------------------------------------
                    */
                    agregarDetalleAsiento(
                        $conn,
                        $asientoId,
                        (int)$cuentaCaja['id'],
                        "Pago recibido - {$cxc['numero_documento']}",
                        $montoPago,
                        0
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | HABER: CxC
                    |--------------------------------------------------------------------------
                    */
                    agregarDetalleAsiento(
                        $conn,
                        $asientoId,
                        (int)$cuentaCxc['id'],
                        "Pago aplicado - {$cxc['numero_documento']}",
                        0,
                        $montoPago
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | REGISTRAR PAGO
                    |--------------------------------------------------------------------------
                    */
                    $stmt = $conn->prepare("
                        INSERT INTO pagos_cxc
                        (
                            cxc_id,
                            fecha_pago,
                            monto,
                            metodo_pago,
                            referencia_pago,
                            asiento_id,
                            usuario_id
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");

                    if (!$stmt) {
                        throw new Exception(
                            'No se pudo preparar el registro del pago de CxC.'
                        );
                    }

                    $stmt->bind_param(
                        'isdssii',
                        $cxcId,
                        $fechaPago,
                        $montoPago,
                        $metodoPago,
                        $referenciaPago,
                        $asientoId,
                        $usuarioId
                    );

                    if (!$stmt->execute()) {
                        $error = $stmt->error;
                        $stmt->close();

                        throw new Exception(
                            'No se pudo registrar el pago: ' . $error
                        );
                    }

                    $stmt->close();

                    /*
                    |--------------------------------------------------------------------------
                    | ACTUALIZAR SALDO
                    |--------------------------------------------------------------------------
                    */
                    $nuevoSaldo = round($saldoActual - $montoPago, 2);

                    if ($nuevoSaldo < 0.01) {
                        $nuevoSaldo = 0;
                    }

                    $nuevoEstado =
                        $nuevoSaldo <= 0
                            ? 'PAGADA'
                            : 'PARCIAL';

                    $montoPagadoActual = (float)($cxc['monto_pagado'] ?? 0);
                    $nuevoPagado = round(
                        $montoPagadoActual + $montoPago,
                        2
                    );

                    $stmt = $conn->prepare("
                        UPDATE cuentas_cobrar
                        SET
                            saldo_pendiente = ?,
                            monto_pagado = ?,
                            estado = ?
                        WHERE id = ?
                    ");

                    if (!$stmt) {
                        throw new Exception(
                            'No se pudo preparar la actualización de CxC.'
                        );
                    }

                    $stmt->bind_param(
                        'ddsi',
                        $nuevoSaldo,
                        $nuevoPagado,
                        $nuevoEstado,
                        $cxcId
                    );

                    if (!$stmt->execute()) {
                        $error = $stmt->error;
                        $stmt->close();

                        throw new Exception(
                            'No se pudo actualizar la cuenta por cobrar: ' . $error
                        );
                    }

                    $stmt->close();

                    $conn->commit();

                    $mensaje = 'Pago de cuenta por cobrar registrado correctamente.';
                    $tipoMensaje = 'ok';

                } catch (Throwable $e) {

                    if ($conn->in_transaction) {
                        $conn->rollback();
                    }

                    $mensaje = 'Error: ' . $e->getMessage();
                    $tipoMensaje = 'error';
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | REGISTRAR PAGO DE CxP
        |--------------------------------------------------------------------------
        */
        elseif ($accion === 'pagar_cxp') {

            $cxpId = (int)($_POST['cxp_id'] ?? 0);
            $fechaPago = textoLimpio($_POST['fecha_pago'] ?? '');
            $montoPago = (float)($_POST['monto_pago'] ?? 0);
            $metodoPago = textoLimpio($_POST['metodo_pago'] ?? 'EFECTIVO');
            $referenciaPago = textoLimpio($_POST['referencia_pago'] ?? '') ?: null;

            if (
                $cxpId <= 0 ||
                !fechaValida($fechaPago) ||
                $montoPago <= 0
            ) {
                $mensaje = 'Complete correctamente todos los campos obligatorios.';
                $tipoMensaje = 'error';

            } else {

                try {

                    $conn->begin_transaction();

                    /*
                    |--------------------------------------------------------------------------
                    | BLOQUEAR CxP
                    |--------------------------------------------------------------------------
                    */
                    $stmt = $conn->prepare("
                        SELECT *
                        FROM cuentas_pagar
                        WHERE id = ?
                          AND estado <> 'PAGADA'
                        FOR UPDATE
                    ");

                    if (!$stmt) {
                        throw new Exception('No se pudo consultar la cuenta por pagar.');
                    }

                    $stmt->bind_param('i', $cxpId);
                    $stmt->execute();

                    $cxp = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$cxp) {
                        throw new Exception(
                            'La cuenta por pagar no existe o ya está pagada.'
                        );
                    }

                    $saldoActual = (float)$cxp['saldo_pendiente'];

                    if ($montoPago > $saldoActual) {
                        throw new Exception(
                            'El monto del pago excede el saldo pendiente.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | CUENTAS CONTABLES
                    |--------------------------------------------------------------------------
                    */
                    $cuentaCxp = obtenerCuentaPorCodigo($conn, '210201');
                    $cuentaCaja = obtenerCuentaPorCodigo($conn, '110101');

                    /*
                    |--------------------------------------------------------------------------
                    | ASIENTO
                    |--------------------------------------------------------------------------
                    */
                    $numeroPartida = siguienteNumeroPartida($conn);
                    $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

                    if ($usuarioId <= 0) {
                        throw new Exception('No se pudo identificar al usuario de la sesión.');
                    }

                    $concepto =
                        "Pago CxP - {$cxp['proveedor_nombre']} - {$cxp['numero_documento']}";

                    $asientoId = crearAsiento(
                        $conn,
                        $numeroPartida,
                        $fechaPago,
                        $concepto,
                        $usuarioId
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | DEBE: PROVEEDORES
                    |--------------------------------------------------------------------------
                    */
                    agregarDetalleAsiento(
                        $conn,
                        $asientoId,
                        (int)$cuentaCxp['id'],
                        "Pago a proveedor - {$cxp['numero_documento']}",
                        $montoPago,
                        0
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | HABER: CAJA
                    |--------------------------------------------------------------------------
                    */
                    agregarDetalleAsiento(
                        $conn,
                        $asientoId,
                        (int)$cuentaCaja['id'],
                        "Pago realizado - {$cxp['numero_documento']}",
                        0,
                        $montoPago
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | REGISTRAR PAGO
                    |--------------------------------------------------------------------------
                    */
                    $stmt = $conn->prepare("
                        INSERT INTO pagos_cxp
                        (
                            cxp_id,
                            fecha_pago,
                            monto,
                            metodo_pago,
                            referencia_pago,
                            asiento_id,
                            usuario_id
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");

                    if (!$stmt) {
                        throw new Exception(
                            'No se pudo preparar el registro del pago de CxP.'
                        );
                    }

                    $stmt->bind_param(
                        'isdssii',
                        $cxpId,
                        $fechaPago,
                        $montoPago,
                        $metodoPago,
                        $referenciaPago,
                        $asientoId,
                        $usuarioId
                    );

                    if (!$stmt->execute()) {
                        $error = $stmt->error;
                        $stmt->close();

                        throw new Exception(
                            'No se pudo registrar el pago: ' . $error
                        );
                    }

                    $stmt->close();

                    /*
                    |--------------------------------------------------------------------------
                    | ACTUALIZAR SALDO
                    |--------------------------------------------------------------------------
                    */
                    $nuevoSaldo = round($saldoActual - $montoPago, 2);

                    if ($nuevoSaldo < 0.01) {
                        $nuevoSaldo = 0;
                    }

                    $nuevoEstado =
                        $nuevoSaldo <= 0
                            ? 'PAGADA'
                            : 'PARCIAL';

                    $montoPagadoActual = (float)($cxp['monto_pagado'] ?? 0);
                    $nuevoPagado = round(
                        $montoPagadoActual + $montoPago,
                        2
                    );

                    $stmt = $conn->prepare("
                        UPDATE cuentas_pagar
                        SET
                            saldo_pendiente = ?,
                            monto_pagado = ?,
                            estado = ?
                        WHERE id = ?
                    ");

                    if (!$stmt) {
                        throw new Exception(
                            'No se pudo preparar la actualización de CxP.'
                        );
                    }

                    $stmt->bind_param(
                        'ddsi',
                        $nuevoSaldo,
                        $nuevoPagado,
                        $nuevoEstado,
                        $cxpId
                    );

                    if (!$stmt->execute()) {
                        $error = $stmt->error;
                        $stmt->close();

                        throw new Exception(
                            'No se pudo actualizar la cuenta por pagar: ' . $error
                        );
                    }

                    $stmt->close();

                    $conn->commit();

                    $mensaje = 'Pago de cuenta por pagar registrado correctamente.';
                    $tipoMensaje = 'ok';

                } catch (Throwable $e) {

                    if ($conn->in_transaction) {
                        $conn->rollback();
                    }

                    $mensaje = 'Error: ' . $e->getMessage();
                    $tipoMensaje = 'error';
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| OBTENER CUENTAS POR COBRAR
|--------------------------------------------------------------------------
*/

$cuentasCobrar = [];

$res = $conn->query("
    SELECT *
    FROM cuentas_cobrar
    WHERE estado IN ('PENDIENTE', 'PARCIAL')
    ORDER BY
        fecha_vencimiento IS NULL,
        fecha_vencimiento ASC,
        id ASC
");

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $cuentasCobrar[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| OBTENER CUENTAS POR PAGAR
|--------------------------------------------------------------------------
*/

$cuentasPagar = [];

$res = $conn->query("
    SELECT *
    FROM cuentas_pagar
    WHERE estado IN ('PENDIENTE', 'PARCIAL')
    ORDER BY
        fecha_vencimiento IS NULL,
        fecha_vencimiento ASC,
        id ASC
");

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $cuentasPagar[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| TOTALES
|--------------------------------------------------------------------------
*/

$totalCxc = 0;

foreach ($cuentasCobrar as $cxc) {
    $totalCxc += (float)$cxc['saldo_pendiente'];
}

$totalCxp = 0;

foreach ($cuentasPagar as $cxp) {
    $totalCxp += (float)$cxp['saldo_pendiente'];
}

require_once 'includes/header.php';
?>

<h1 class="page-title">Cuentas por Cobrar y Pagar</h1>

<?php if ($mensaje !== ''): ?>

    <div class="mensaje-inventario <?php echo $tipoMensaje === 'ok'
        ? 'mensaje-ok'
        : 'mensaje-error'; ?>">

        <?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?>

    </div>

<?php endif; ?>


<!-- =========================================================
     RESUMEN
========================================================= -->

<div class="diario-summary-grid estados-summary-grid">

    <div class="diario-summary-card">

        <span class="diario-summary-label">
            Total por Cobrar
        </span>

        <strong class="diario-summary-value">
            <?php echo fmt($totalCxc); ?>
        </strong>

        <small>
            <?php echo count($cuentasCobrar); ?>
            cuenta(s) pendiente(s)
        </small>

    </div>


    <div class="diario-summary-card">

        <span class="diario-summary-label">
            Total por Pagar
        </span>

        <strong class="diario-summary-value">
            <?php echo fmt($totalCxp); ?>
        </strong>

        <small>
            <?php echo count($cuentasPagar); ?>
            cuenta(s) pendiente(s)
        </small>

    </div>

</div>


<!-- =========================================================
     PESTAÑAS PRINCIPALES
========================================================= -->

<div class="tabs main-tabs">

    <button
        type="button"
        class="tab-btn active"
        data-tab="cxc"
        onclick="showTab('cxc', this)"
    >
        Por Cobrar
    </button>

    <button
        type="button"
        class="tab-btn"
        data-tab="cxp"
        onclick="showTab('cxp', this)"
    >
        Por Pagar
    </button>

    <button
        type="button"
        class="tab-btn"
        data-tab="nueva"
        onclick="showTab('nueva', this)"
    >
        Nueva Cuenta
    </button>

</div>


<!-- =========================================================
     TAB CxC
========================================================= -->

<div id="tab-cxc" class="tab-content active">

    <div class="card">

        <div class="diario-card-header">

            <div>

                <p class="diario-kicker">
                    Cuentas por cobrar
                </p>

                <h2>
                    Clientes con saldo pendiente
                </h2>

            </div>

            <span class="diario-badge">
                Cuenta 110201
            </span>

        </div>


        <?php if (empty($cuentasCobrar)): ?>

            <p class="help-text">
                No hay cuentas por cobrar pendientes.
            </p>

        <?php else: ?>

            <div class="table-scroll">

                <table class="estados-table">

                    <thead>

                        <tr>

                            <th>Cliente</th>
                            <th>Documento</th>
                            <th>F. Emisión</th>
                            <th>F. Vencimiento</th>
                            <th class="text-right">Monto</th>
                            <th class="text-right">Saldo</th>
                            <th>Estado</th>
                            <th>Acción</th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($cuentasCobrar as $cxc): ?>

                        <?php
                        $clienteJs = (string)$cxc['cliente_nombre'];

                        $saldoJs = (float)$cxc['saldo_pendiente'];
                        ?>

                        <tr>

                            <td>
                                <?php echo htmlspecialchars(
                                    $cxc['cliente_nombre'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars(
                                    $cxc['tipo_documento'] . ' ' . $cxc['numero_documento'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                            </td>

                            <td>
                                <?php
                                echo !empty($cxc['fecha_emision'])
                                    ? date('d/m/Y', strtotime($cxc['fecha_emision']))
                                    : '-';
                                ?>
                            </td>

                            <td>
                                <?php
                                echo !empty($cxc['fecha_vencimiento'])
                                    ? date('d/m/Y', strtotime($cxc['fecha_vencimiento']))
                                    : '-';
                                ?>
                            </td>

                            <td class="text-right">
                                <?php echo fmt((float)$cxc['monto_original']); ?>
                            </td>

                            <td class="text-right">

                                <strong>
                                    <?php echo fmt($saldoJs); ?>
                                </strong>

                            </td>

                            <td>

                                <span class="estado-inventario <?php echo $cxc['estado'] === 'PENDIENTE'
                                    ? 'estado-alerta'
                                    : 'estado-ok'; ?>">

                                    <?php echo htmlspecialchars(
                                        $cxc['estado'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ); ?>

                                </span>

                            </td>

                            <td>

                                <button
                                    type="button"
                                    class="btn secondary"
                                    onclick='mostrarPagoCxc(
                                        <?php echo (int)$cxc['id']; ?>,
                                        <?php echo json_encode($clienteJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
                                        <?php echo $saldoJs; ?>
                                    )'
                                >
                                    Pagar
                                </button>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

</div>


<!-- =========================================================
     TAB CxP
========================================================= -->

<div id="tab-cxp" class="tab-content">

    <div class="card">

        <div class="diario-card-header">

            <div>

                <p class="diario-kicker">
                    Cuentas por pagar
                </p>

                <h2>
                    Proveedores con saldo pendiente
                </h2>

            </div>

            <span class="diario-badge">
                Cuenta 210201
            </span>

        </div>


        <?php if (empty($cuentasPagar)): ?>

            <p class="help-text">
                No hay cuentas por pagar pendientes.
            </p>

        <?php else: ?>

            <div class="table-scroll">

                <table class="estados-table">

                    <thead>

                        <tr>

                            <th>Proveedor</th>
                            <th>Documento</th>
                            <th>F. Emisión</th>
                            <th>F. Vencimiento</th>
                            <th class="text-right">Monto</th>
                            <th class="text-right">Saldo</th>
                            <th>Estado</th>
                            <th>Acción</th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($cuentasPagar as $cxp): ?>

                        <?php
                        $proveedorJs = (string)$cxp['proveedor_nombre'];

                        $saldoJs = (float)$cxp['saldo_pendiente'];
                        ?>

                        <tr>

                            <td>
                                <?php echo htmlspecialchars(
                                    $cxp['proveedor_nombre'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars(
                                    $cxp['tipo_documento'] . ' ' . $cxp['numero_documento'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                            </td>

                            <td>
                                <?php
                                echo !empty($cxp['fecha_emision'])
                                    ? date('d/m/Y', strtotime($cxp['fecha_emision']))
                                    : '-';
                                ?>
                            </td>

                            <td>
                                <?php
                                echo !empty($cxp['fecha_vencimiento'])
                                    ? date('d/m/Y', strtotime($cxp['fecha_vencimiento']))
                                    : '-';
                                ?>
                            </td>

                            <td class="text-right">
                                <?php echo fmt((float)$cxp['monto_original']); ?>
                            </td>

                            <td class="text-right">

                                <strong>
                                    <?php echo fmt($saldoJs); ?>
                                </strong>

                            </td>

                            <td>

                                <span class="estado-inventario <?php echo $cxp['estado'] === 'PENDIENTE'
                                    ? 'estado-alerta'
                                    : 'estado-ok'; ?>">

                                    <?php echo htmlspecialchars(
                                        $cxp['estado'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ); ?>

                                </span>

                            </td>

                            <td>

                                <button
                                    type="button"
                                    class="btn secondary"
                                    onclick='mostrarPagoCxp(
                                        <?php echo (int)$cxp['id']; ?>,
                                        <?php echo json_encode($proveedorJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
                                        <?php echo $saldoJs; ?>
                                    )'
                                >
                                    Pagar
                                </button>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

</div>


<!-- =========================================================
     TAB NUEVA CUENTA
========================================================= -->

<div id="tab-nueva" class="tab-content">

    <div class="card">

        <div class="diario-card-header">

            <div>

                <p class="diario-kicker">
                    Registrar
                </p>

                <h2>
                    Nueva Cuenta por Cobrar o Pagar
                </h2>

            </div>

        </div>


        <!-- SUBPESTAÑAS -->

        <div class="tabs sub-tabs">

            <button
                type="button"
                class="tab-btn subtab-btn active"
                onclick="showSubTab('nueva-cxc', this)"
            >
                Por Cobrar
            </button>

            <button
                type="button"
                class="tab-btn subtab-btn"
                onclick="showSubTab('nueva-cxp', this)"
            >
                Por Pagar
            </button>

        </div>


        <!-- =====================================================
             NUEVA CxC
        ====================================================== -->

        <div
            id="subtab-nueva-cxc"
            class="subtab-content active"
        >

            <form method="POST">

                <input
                    type="hidden"
                    name="_csrf"
                    value="<?php echo htmlspecialchars(
                        csrfToken('cxc_cxp'),
                        ENT_QUOTES,
                        'UTF-8'
                    ); ?>"
                >

                <input
                    type="hidden"
                    name="accion"
                    value="agregar_cxc"
                >


                <div class="inventario-form-grid">

                    <div class="campo">

                        <label>
                            Cliente *
                        </label>

                        <input
                            type="text"
                            name="cliente_nombre"
                            required
                            maxlength="180"
                        >

                    </div>


                    <div class="campo">

                        <label>
                            Documento del cliente
                        </label>

                        <input
                            type="text"
                            name="cliente_documento"
                            maxlength="50"
                        >

                    </div>


                    <div class="campo">

                        <label>
                            Tipo de documento *
                        </label>

                        <select
                            name="tipo_documento"
                            required
                        >

                            <option value="FACTURA">
                                Factura
                            </option>

                            <option value="CCF">
                                CCF
                            </option>

                            <option value="NOTA_CREDITO">
                                Nota de Crédito
                            </option>

                            <option value="DOC_SUJETO_EXCLUIDO">
                                Doc. Sujeto Excluido
                            </option>

                            <option value="OTRO">
                                Otro
                            </option>

                        </select>

                    </div>


                    <div class="campo">

                        <label>
                            Número de documento *
                        </label>

                        <input
                            type="text"
                            name="numero_documento"
                            required
                            maxlength="50"
                        >

                    </div>


                    <div class="campo">

                        <label>
                            Fecha de emisión *
                        </label>

                        <input
                            type="date"
                            name="fecha_emision"
                            required
                        >

                    </div>


                    <div class="campo">

                        <label>
                            Fecha de vencimiento
                        </label>

                        <input
                            type="date"
                            name="fecha_vencimiento"
                        >

                    </div>


                    <div class="campo">

                        <label>
                            Monto *
                        </label>

                        <input
                            type="number"
                            name="monto"
                            min="0.01"
                            step="0.01"
                            required
                        >

                    </div>


                    <div class="campo">

                        <label>
                            Observaciones
                        </label>

                        <input
                            type="text"
                            name="observaciones"
                            maxlength="255"
                        >

                    </div>

                </div>


                <div class="botones-form">

                    <button
                        type="submit"
                        class="btn-inventario btn-agregar"
                    >
                        Registrar CxC
                    </button>

                </div>

            </form>

        </div>


        <!-- =====================================================
             NUEVA CxP
        ====================================================== -->

        <div
            id="subtab-nueva-cxp"
            class="subtab-content"
        >

            <form method="POST">

                <input
                    type="hidden"
                    name="_csrf"
                    value="<?php echo htmlspecialchars(
                        csrfToken('cxc_cxp'),
                        ENT_QUOTES,
                        'UTF-8'
                    ); ?>"
                >

                <input
                    type="hidden"
                    name="accion"
                    value="agregar_cxp"
                >


                <div class="inventario-form-grid">

                    <div class="campo">

                        <label>
                            Proveedor *
                        </label>

                        <input
                            type="text"
                            name="proveedor_nombre"
                            required
                            maxlength="180"
                        >

                    </div>


                    <div class="campo">

                        <label>
                            Documento del proveedor
                        </label>

                        <input
                            type="text"
                            name="proveedor_documento"
                            maxlength="50"
                        >

                    </div>


                    <div class="campo">

                        <label>
                            Tipo de documento *
                        </label>

                        <select
                            name="tipo_documento"
                            required
                        >

                            <option value="FACTURA">
                                Factura
                            </option>

                            <option value="CCF">
                                CCF
                            </option>

                            <option value="NOTA_CREDITO">
                                Nota de Crédito
                            </option>

                            <option value="DOC_SUJETO_EXCLUIDO">
                                Doc. Sujeto Excluido
                            </option>

                            <option value="OTRO">
                                Otro
                            </option>

                        </select>

                    </div>


                    <div class="campo">

                        <label>
                            Número de documento *
                        </label>

                        <input
                            type="text"
                            name="numero_documento"
                            required
                            maxlength="50"
                        >

                    </div>


                    <div class="campo">

                        <label>
                            Fecha de emisión *
                        </label>

                        <input
                            type="date"
                            name="fecha_emision"
                            required
                        >

                    </div>


                    <div class="campo">

                        <label>
                            Fecha de vencimiento
                        </label>

                        <input
                            type="date"
                            name="fecha_vencimiento"
                        >

                    </div>


                    <div class="campo">

                        <label>
                            Monto *
                        </label>

                        <input
                            type="number"
                            name="monto"
                            min="0.01"
                            step="0.01"
                            required
                        >

                    </div>


                    <div class="campo">

                        <label>
                            Observaciones
                        </label>

                        <input
                            type="text"
                            name="observaciones"
                            maxlength="255"
                        >

                    </div>

                </div>


                <div class="botones-form">

                    <button
                        type="submit"
                        class="btn-inventario btn-agregar"
                    >
                        Registrar CxP
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<!-- =========================================================
     MODAL PAGO CxC
========================================================= -->

<div
    id="modal-pago-cxc"
    class="modal"
    style="display:none;"
>

    <div class="modal-content">

        <h3>
            Registrar Pago - Cuenta por Cobrar
        </h3>

        <p id="modal-cxc-info"></p>


        <form method="POST">

            <input
                type="hidden"
                name="_csrf"
                value="<?php echo htmlspecialchars(
                    csrfToken('cxc_cxp'),
                    ENT_QUOTES,
                    'UTF-8'
                ); ?>"
            >

            <input
                type="hidden"
                name="accion"
                value="pagar_cxc"
            >

            <input
                type="hidden"
                name="cxc_id"
                id="modal-cxc-id"
            >


            <div class="inventario-form-grid">

                <div class="campo">

                    <label>
                        Fecha de pago *
                    </label>

                    <input
                        type="date"
                        name="fecha_pago"
                        id="modal-cxc-fecha"
                        required
                    >

                </div>


                <div class="campo">

                    <label>
                        Monto *
                    </label>

                    <input
                        type="number"
                        name="monto_pago"
                        id="modal-cxc-monto"
                        min="0.01"
                        step="0.01"
                        required
                    >

                </div>


                <div class="campo">

                    <label>
                        Método de pago
                    </label>

                    <select name="metodo_pago">

                        <option value="EFECTIVO">
                            Efectivo
                        </option>

                        <option value="TRANSFERENCIA">
                            Transferencia
                        </option>

                        <option value="CHEQUE">
                            Cheque
                        </option>

                        <option value="TARJETA">
                            Tarjeta
                        </option>

                        <option value="OTRO">
                            Otro
                        </option>

                    </select>

                </div>


                <div class="campo">

                    <label>
                        Referencia
                    </label>

                    <input
                        type="text"
                        name="referencia_pago"
                        maxlength="100"
                    >

                </div>

            </div>


            <div class="botones-form">

                <button
                    type="submit"
                    class="btn-inventario btn-agregar"
                >
                    Registrar pago
                </button>

                <button
                    type="button"
                    class="btn-inventario btn-cancelar"
                    onclick="cerrarModal('modal-pago-cxc')"
                >
                    Cancelar
                </button>

            </div>

        </form>

    </div>

</div>


<!-- =========================================================
     MODAL PAGO CxP
========================================================= -->

<div
    id="modal-pago-cxp"
    class="modal"
    style="display:none;"
>

    <div class="modal-content">

        <h3>
            Registrar Pago - Cuenta por Pagar
        </h3>

        <p id="modal-cxp-info"></p>


        <form method="POST">

            <input
                type="hidden"
                name="_csrf"
                value="<?php echo htmlspecialchars(
                    csrfToken('cxc_cxp'),
                    ENT_QUOTES,
                    'UTF-8'
                ); ?>"
            >

            <input
                type="hidden"
                name="accion"
                value="pagar_cxp"
            >

            <input
                type="hidden"
                name="cxp_id"
                id="modal-cxp-id"
            >


            <div class="inventario-form-grid">

                <div class="campo">

                    <label>
                        Fecha de pago *
                    </label>

                    <input
                        type="date"
                        name="fecha_pago"
                        id="modal-cxp-fecha"
                        required
                    >

                </div>


                <div class="campo">

                    <label>
                        Monto *
                    </label>

                    <input
                        type="number"
                        name="monto_pago"
                        id="modal-cxp-monto"
                        min="0.01"
                        step="0.01"
                        required
                    >

                </div>


                <div class="campo">

                    <label>
                        Método de pago
                    </label>

                    <select name="metodo_pago">

                        <option value="EFECTIVO">
                            Efectivo
                        </option>

                        <option value="TRANSFERENCIA">
                            Transferencia
                        </option>

                        <option value="CHEQUE">
                            Cheque
                        </option>

                        <option value="TARJETA">
                            Tarjeta
                        </option>

                        <option value="OTRO">
                            Otro
                        </option>

                    </select>

                </div>


                <div class="campo">

                    <label>
                        Referencia
                    </label>

                    <input
                        type="text"
                        name="referencia_pago"
                        maxlength="100"
                    >

                </div>

            </div>


            <div class="botones-form">

                <button
                    type="submit"
                    class="btn-inventario btn-agregar"
                >
                    Registrar pago
                </button>

                <button
                    type="button"
                    class="btn-inventario btn-cancelar"
                    onclick="cerrarModal('modal-pago-cxp')"
                >
                    Cancelar
                </button>

            </div>

        </form>

    </div>

</div>


<!-- =========================================================
     ESTILOS
========================================================= -->

<style>

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

/* Subpestañas de Nueva Cuenta: solo una visible a la vez */
.subtab-content {
    display: none;
}

.subtab-content.active {
    display: block;
}

.tab-btn {
    padding: 10px 20px;
    border: 1px solid #cbd5e1;
    background: white;
    cursor: pointer;
    border-radius: 8px 8px 0 0;
    font-weight: 600;
}

.tab-btn:hover {
    background: #f1f5f9;
}

.tab-btn.active {
    background: #176b87;
    color: white;
    border-color: #176b87;
}

.modal {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal-content {
    background: white;
    padding: 24px;
    border-radius: 12px;
    max-width: 600px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-content h3 {
    margin-top: 0;
}

.modal-content p {
    margin-bottom: 20px;
}

</style>


<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script>

/*
|--------------------------------------------------------------------------
| PESTAÑAS PRINCIPALES
|--------------------------------------------------------------------------
*/

function showTab(tab, boton) {

    // Ocultar únicamente las pestañas principales.
    document
        .querySelectorAll('#tab-cxc, #tab-cxp, #tab-nueva')
        .forEach(function(el) {
            el.classList.remove('active');
        });

    // Quitar active únicamente de los botones principales.
    document
        .querySelectorAll('.main-tabs > .tab-btn')
        .forEach(function(el) {
            el.classList.remove('active');
        });

    const contenido = document.getElementById('tab-' + tab);

    if (contenido) {
        contenido.classList.add('active');
    }

    if (boton) {
        boton.classList.add('active');
    }
}


/*
|--------------------------------------------------------------------------
| SUBPESTAÑAS
|--------------------------------------------------------------------------
*/

function showSubTab(tab, boton) {

    // Ocultar únicamente los formularios de las subpestañas.
    document
        .querySelectorAll('#tab-nueva .subtab-content')
        .forEach(function(el) {
            el.classList.remove('active');
        });

    // Quitar active únicamente de los botones Por Cobrar / Por Pagar.
    document
        .querySelectorAll('#tab-nueva .subtab-btn')
        .forEach(function(el) {
            el.classList.remove('active');
        });

    const contenido = document.getElementById('subtab-' + tab);

    if (contenido) {
        contenido.classList.add('active');
    }

    if (boton) {
        boton.classList.add('active');
    }
}


/*
|--------------------------------------------------------------------------
| MODAL CxC
|--------------------------------------------------------------------------
*/

function mostrarPagoCxc(id, cliente, saldo) {

    const modal = document.getElementById('modal-pago-cxc');

    const idInput = document.getElementById('modal-cxc-id');

    const info = document.getElementById('modal-cxc-info');

    const monto = document.getElementById('modal-cxc-monto');

    const fecha = document.getElementById('modal-cxc-fecha');


    idInput.value = id;

    info.textContent =
        'Cliente: ' +
        cliente +
        ' - Saldo pendiente: $' +
        Number(saldo).toFixed(2);

    monto.max = Number(saldo).toFixed(2);

    /*
     * Colocamos automáticamente el saldo completo.
     */
    monto.value = Number(saldo).toFixed(2);

    /*
     * Fecha actual.
     */
    if (fecha && !fecha.value) {
        fecha.value = obtenerFechaActual();
    }

    modal.style.display = 'flex';
}


/*
|--------------------------------------------------------------------------
| MODAL CxP
|--------------------------------------------------------------------------
*/

function mostrarPagoCxp(id, proveedor, saldo) {

    const modal = document.getElementById('modal-pago-cxp');

    const idInput = document.getElementById('modal-cxp-id');

    const info = document.getElementById('modal-cxp-info');

    const monto = document.getElementById('modal-cxp-monto');

    const fecha = document.getElementById('modal-cxp-fecha');


    idInput.value = id;

    info.textContent =
        'Proveedor: ' +
        proveedor +
        ' - Saldo pendiente: $' +
        Number(saldo).toFixed(2);

    monto.max = Number(saldo).toFixed(2);

    /*
     * Colocamos automáticamente el saldo completo.
     */
    monto.value = Number(saldo).toFixed(2);

    /*
     * Fecha actual.
     */
    if (fecha && !fecha.value) {
        fecha.value = obtenerFechaActual();
    }

    modal.style.display = 'flex';
}


/*
|--------------------------------------------------------------------------
| CERRAR MODAL
|--------------------------------------------------------------------------
*/

function cerrarModal(id) {

    const modal = document.getElementById(id);

    if (modal) {
        modal.style.display = 'none';
    }
}


/*
|--------------------------------------------------------------------------
| FECHA ACTUAL
|--------------------------------------------------------------------------
*/

function obtenerFechaActual() {

    const fecha = new Date();

    const anio = fecha.getFullYear();

    const mes = String(
        fecha.getMonth() + 1
    ).padStart(2, '0');

    const dia = String(
        fecha.getDate()
    ).padStart(2, '0');

    return anio + '-' + mes + '-' + dia;
}


/*
|--------------------------------------------------------------------------
| CERRAR MODAL AL HACER CLIC FUERA
|--------------------------------------------------------------------------
*/

document.addEventListener('click', function(event) {

    const modalCxc =
        document.getElementById('modal-pago-cxc');

    const modalCxp =
        document.getElementById('modal-pago-cxp');


    if (
        event.target === modalCxc
    ) {
        cerrarModal('modal-pago-cxc');
    }


    if (
        event.target === modalCxp
    ) {
        cerrarModal('modal-pago-cxp');
    }

});


/*
|--------------------------------------------------------------------------
| ESC PARA CERRAR MODAL
|--------------------------------------------------------------------------
*/

document.addEventListener('keydown', function(event) {

    if (event.key === 'Escape') {

        cerrarModal('modal-pago-cxc');

        cerrarModal('modal-pago-cxp');

    }

});


/*
|--------------------------------------------------------------------------
| VALIDACIÓN DEL MONTO DE PAGO
|--------------------------------------------------------------------------
*/

document.addEventListener('DOMContentLoaded', function() {

    const montoCxc =
        document.getElementById('modal-cxc-monto');

    const montoCxp =
        document.getElementById('modal-cxp-monto');


    if (montoCxc) {

        montoCxc.addEventListener('input', function() {

            const max =
                parseFloat(this.max);

            const valor =
                parseFloat(this.value);

            if (
                !isNaN(max) &&
                !isNaN(valor) &&
                valor > max
            ) {
                this.value = max.toFixed(2);
            }

        });

    }


    if (montoCxp) {

        montoCxp.addEventListener('input', function() {

            const max =
                parseFloat(this.max);

            const valor =
                parseFloat(this.value);

            if (
                !isNaN(max) &&
                !isNaN(valor) &&
                valor > max
            ) {
                this.value = max.toFixed(2);
            }

        });

    }

});

</script>


<?php require_once 'includes/footer.php'; ?>