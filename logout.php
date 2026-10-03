<?php
require_once __DIR__ . '/includes/security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verificarCsrf($_POST['_csrf'] ?? null, 'logout')) {
    header('Location: dashboard.php');
    exit;
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'] ?? '',
        (bool)$params['secure'],
        (bool)$params['httponly']
    );
}
session_destroy();

header('Location: login.php');
exit;
