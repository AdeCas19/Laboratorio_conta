<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$tituloPagina = 'Cambiar contraseña';
$activo = '';
$mensaje = '';
$tipoMensaje = 'error';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verificarCsrf($_POST['_csrf'] ?? null, 'password')) {
            throw new InvalidArgumentException('La sesión del formulario venció. Actualiza la página.');
        }

        $actual = (string)($_POST['password_actual'] ?? '');
        $nueva = (string)($_POST['password_nueva'] ?? '');
        $confirmacion = (string)($_POST['password_confirmacion'] ?? '');

        if (strlen($nueva) < 8) {
            throw new InvalidArgumentException('La nueva contraseña debe tener al menos 8 caracteres.');
        }
        if ($nueva !== $confirmacion) {
            throw new InvalidArgumentException('La nueva contraseña y su confirmación no coinciden.');
        }

        $stmt = $conn->prepare('SELECT password FROM usuarios WHERE id = ? LIMIT 1');
        $usuarioId = (int)$_SESSION['usuario_id'];
        $stmt->bind_param('i', $usuarioId);
        if (!$stmt->execute()) {
            throw new RuntimeException('No se pudo validar la contraseña actual.');
        }
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$fila || !password_verify($actual, $fila['password'])) {
            throw new InvalidArgumentException('La contraseña actual no es correcta.');
        }

        $hash = password_hash($nueva, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE usuarios SET password = ? WHERE id = ?');
        $stmt->bind_param('si', $hash, $usuarioId);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('No se pudo actualizar la contraseña.');
        }
        $stmt->close();
        session_regenerate_id(true);
        $mensaje = 'La contraseña fue actualizada correctamente.';
        $tipoMensaje = 'ok';
    } catch (Throwable $e) {
        $mensaje = $e instanceof InvalidArgumentException
            ? $e->getMessage()
            : 'No se pudo cambiar la contraseña. Intenta nuevamente.';
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<h1 class="page-title">Cambiar contraseña</h1>
<div class="card" style="max-width:620px;">
    <p class="help-text">Usa una contraseña exclusiva para el sistema y no la compartas con otras personas.</p>
    <?php if ($mensaje !== ''): ?>
        <div class="alert alert-<?php echo $tipoMensaje === 'ok' ? 'success' : 'error'; ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>
    <form method="POST">
        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrfToken('password'), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="campo">
            <label for="password_actual">Contraseña actual</label>
            <input type="password" id="password_actual" name="password_actual" required autocomplete="current-password">
        </div>
        <div class="campo" style="margin-top:14px;">
            <label for="password_nueva">Nueva contraseña</label>
            <input type="password" id="password_nueva" name="password_nueva" minlength="8" required autocomplete="new-password">
        </div>
        <div class="campo" style="margin-top:14px;">
            <label for="password_confirmacion">Confirmar nueva contraseña</label>
            <input type="password" id="password_confirmacion" name="password_confirmacion" minlength="8" required autocomplete="new-password">
        </div>
        <button type="submit" class="btn" style="margin-top:18px;">Actualizar contraseña</button>
    </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
