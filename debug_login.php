<?php
/**
 * El diagnóstico de credenciales queda deshabilitado en producción.
 * No debe mostrar hashes, contraseñas ni información de usuarios.
 */
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Recurso no disponible.\n";
