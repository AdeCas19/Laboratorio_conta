<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: libro_diario.php');
    exit;
}

$token = $_POST['_csrf'] ?? $_POST['csrf_token'] ?? null;
if (!verificarCsrf(is_string($token) ? $token : null, 'asientos')) {
    header('Location: libro_diario.php?err=csrf');
    exit;
}

$fecha    = textoLimpio($_POST['fecha'] ?? '');
$concepto = textoLimpio($_POST['concepto'] ?? '');

$cuentaIds = $_POST['cuenta_id'] ?? [];
$detalles  = $_POST['detalle'] ?? [];
$debes     = $_POST['debe'] ?? [];
$habers    = $_POST['haber'] ?? [];

if (!fechaValida($fecha)) {
    header('Location: libro_diario.php?err=fecha');
    exit;
}

// Período cerrado: no se admiten asientos con fecha igual o anterior al último cierre.
if (periodoCerrado($conn, $fecha) !== null) {
    header('Location: libro_diario.php?err=periodo_cerrado');
    exit;
}

if ($concepto === '' || longitudTexto($concepto) > 255) {
    header('Location: libro_diario.php?err=incompleto');
    exit;
}

if (!is_array($cuentaIds) || count($cuentaIds) < 2) {
    header('Location: libro_diario.php?err=incompleto');
    exit;
}

if (!is_array($detalles) || !is_array($debes) || !is_array($habers)) {
    header('Location: libro_diario.php?err=linea');
    exit;
}

// Construir únicamente líneas completas y válidas.
$lineas = [];
$totalDebeCentavos = 0;
$totalHaberCentavos = 0;

foreach ($cuentaIds as $i => $cuentaIdCrudo) {
    $cid = (int)$cuentaIdCrudo;
    $debe = montoContable($debes[$i] ?? 0);
    $haber = montoContable($habers[$i] ?? 0);

    if ($debe === null || $haber === null) {
        header('Location: libro_diario.php?err=monto');
        exit;
    }

    $debe = (float)$debe;
    $haber = (float)$haber;
    $tieneCuenta = $cid > 0;
    $tieneMonto = $debe > 0 || $haber > 0;

    // Filas vacías que usa el formulario para agregar líneas.
    if (!$tieneCuenta && !$tieneMonto) {
        continue;
    }

    if (!$tieneCuenta || !$tieneMonto) {
        header('Location: libro_diario.php?err=linea');
        exit;
    }

    // En una línea contable solo se permite un lado del movimiento.
    if ($debe > 0 && $haber > 0) {
        header('Location: libro_diario.php?err=linea');
        exit;
    }

    $detalle = textoLimpio($detalles[$i] ?? '');
    if (longitudTexto($detalle) > 255) {
        header('Location: libro_diario.php?err=incompleto');
        exit;
    }

    $lineas[] = [
        'cuenta_id' => $cid,
        'detalle'   => $detalle,
        'debe'      => $debe,
        'haber'     => $haber,
    ];

    $totalDebeCentavos += (int)round($debe * 100);
    $totalHaberCentavos += (int)round($haber * 100);
}

if (count($lineas) < 2 || $totalDebeCentavos <= 0) {
    header('Location: libro_diario.php?err=incompleto');
    exit;
}

// -------- VALIDACIÓN OBLIGATORIA DE PARTIDA DOBLE --------
if ($totalDebeCentavos !== $totalHaberCentavos) {
    header('Location: libro_diario.php?err=descuadre');
    exit;
}

// Validar que todas las cuentas existan, estén activas y admitan movimiento.
$idsValidos = [];
$res = $conn->query("
    SELECT id
    FROM cuentas
    WHERE permite_movimiento = 1 AND activa = 1
");
if (!$res) {
    header('Location: libro_diario.php?err=general');
    exit;
}

while ($fila = $res->fetch_assoc()) {
    $idsValidos[(int)$fila['id']] = true;
}
$res->free();

foreach ($lineas as $linea) {
    if (!isset($idsValidos[$linea['cuenta_id']])) {
        header('Location: libro_diario.php?err=cuenta');
        exit;
    }
}

// -------- Inserción transaccional --------
// Se reintenta únicamente si dos usuarios intentan usar el mismo correlativo
// al mismo tiempo. El índice único de asientos evita duplicados permanentes.
$intento = 0;
$ultimoError = null;

while ($intento < 3) {
    $conn->begin_transaction();

    try {
        $numeroPartida = siguienteNumeroPartida($conn);

        $stmt = $conn->prepare("
            INSERT INTO asientos (numero_partida, fecha, concepto, usuario_id)
            VALUES (?, ?, ?, ?)
        ");
        $usuarioId = (int)$_SESSION['usuario_id'];
        $stmt->bind_param('issi', $numeroPartida, $fecha, $concepto, $usuarioId);
        if (!$stmt->execute()) {
            throw new RuntimeException('No se pudo guardar el encabezado del asiento.');
        }
        $asientoId = (int)$conn->insert_id;
        $stmt->close();

        $stmtLinea = $conn->prepare("
            INSERT INTO detalle_asientos (asiento_id, cuenta_id, detalle, debe, haber)
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($lineas as $linea) {
            $stmtLinea->bind_param(
                'iisdd',
                $asientoId,
                $linea['cuenta_id'],
                $linea['detalle'],
                $linea['debe'],
                $linea['haber']
            );
            if (!$stmtLinea->execute()) {
                throw new RuntimeException('No se pudo guardar el detalle del asiento.');
            }
        }
        $stmtLinea->close();

        $conn->commit();
        header('Location: libro_diario.php?ok=1');
        exit;
    } catch (Throwable $e) {
        $ultimoError = $e;
        $errno = (int)$conn->errno;
        $conn->rollback();

        // 1062 = clave duplicada. Puede ocurrir por concurrencia al calcular
        // el correlativo; se vuelve a leer el último número y se intenta otra vez.
        if ($errno === 1062 && $intento < 2) {
            $intento++;
            usleep(10000);
            continue;
        }

        break;
    }
}

// No se muestra el detalle técnico de MySQL al usuario.
header('Location: libro_diario.php?err=general');
exit;