<?php
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

$token = $_POST['csrf_token'] ?? null;
if (!verificarCsrf(is_string($token) ? $token : null, 'login')) {
    header('Location: login.php?error=csrf');
    exit;
}

$usuario  = textoLimpio($_POST['usuario'] ?? '');
$password = $_POST['password'] ?? '';

if ($usuario === '' || !is_string($password) || $password === '') {
    header('Location: login.php?error=credenciales');
    exit;
}

// Solo se permite el acceso a usuarios activos con rol 'contador'.
$stmt = $conn->prepare("SELECT id, usuario, password, nombre_completo, rol
                         FROM usuarios
                         WHERE usuario = ? AND rol = 'contador' AND activo = 1
                         LIMIT 1");
$stmt->bind_param('s', $usuario);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $user = $result->fetch_assoc();
    $stmt->close();

    if (password_verify($password, $user['password'])) {
        // Evita que un identificador de sesión previo pueda reutilizarse.
        session_regenerate_id(true);
        $_SESSION['usuario_id']      = (int)$user['id'];
        $_SESSION['usuario']         = $user['usuario'];
        $_SESSION['nombre_completo'] = $user['nombre_completo'];
        $_SESSION['rol']             = $user['rol'];
        unset($_SESSION['_csrf']['login']);

        header('Location: dashboard.php');
        exit;
    }
} else {
    $stmt->close();
}

header('Location: login.php?error=credenciales');
exit;
