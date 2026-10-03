<?php

require_once __DIR__ . '/includes/security.php';

/*
|--------------------------------------------------------------------------
| Verificar sesión existente
|--------------------------------------------------------------------------
| Si ya existe una sesión válida de contador, enviamos directamente
| al dashboard.
*/

if (
    isset($_SESSION['usuario_id']) &&
    ($_SESSION['rol'] ?? '') === 'contador'
) {
    header('Location: dashboard.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Token CSRF
|--------------------------------------------------------------------------
| El token se genera mediante el helper de seguridad.
*/
$csrfLogin = csrfToken('login');


/*
|--------------------------------------------------------------------------
| Mensajes de error
|--------------------------------------------------------------------------
*/

$error = '';

if (isset($_GET['error'])) {

    switch (is_string($_GET['error']) ? $_GET['error'] : '') {

        case 'sesion':
            $error = 'Debes iniciar sesión como contador para continuar.';
            break;

        case 'credenciales':
            $error = 'Usuario o contraseña incorrectos, o el usuario no tiene rol de contador.';
            break;

        case 'csrf':
            $error = 'La solicitud no es válida. Intenta nuevamente.';
            break;

        case 'inactivo':
            $error = 'El usuario se encuentra inactivo.';
            break;

        default:
            $error = '';
            break;
    }
}

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Iniciar sesión - Módulo Contable</title>

    <link
        rel="icon"
        href="assets/img/logo.png"
    >

    <link
        rel="stylesheet"
        href="assets/css/style.css"
    >



    <style>

        /* =========================================================
           VARIABLES LOCALES
           ========================================================= */

        :root {

            --lg-azul-oscuro: #07345d;
            --lg-azul: #0b4f8a;
            --lg-azul-medio: #1769aa;
            --lg-celeste: #27a9df;
            --lg-celeste-claro: #eaf8fd;
            --lg-borde: #dbe3ea;
            --lg-gris: #6b7683;
            --lg-gris-claro: #f5f8fa;
            --lg-radio: 14px;
            --lg-error: #a12626;
            --lg-error-fondo: #fdeaea;
            --lg-error-borde: #f3c9c9;

        }


        /* =========================================================
           BASE Y FONDO
           ========================================================= */

        body.login-body {

            margin: 0;

            min-height: 100vh;

            font-family:
                'Segoe UI',
                Arial,
                sans-serif;

            color: #111;

            background:
                linear-gradient(
                    160deg,
                    rgba(7, 52, 93, 0.72),
                    rgba(11, 79, 138, 0.62)
                ),
                url('assets/img/fondo-login.png') center / cover fixed no-repeat;

        }


        /* Halos decorativos de fondo */

        body.login-body::before,
        body.login-body::after {

            content: '';

            position: fixed;

            border-radius: 50%;

            pointer-events: none;

        }


        body.login-body::before {

            top: -190px;

            left: -140px;

            width: 520px;

            height: 520px;

            background:
                radial-gradient(
                    circle,
                    rgba(39, 169, 223, 0.45),
                    transparent 68%
                );

        }


        body.login-body::after {

            right: -160px;

            bottom: -200px;

            width: 580px;

            height: 580px;

            background:
                radial-gradient(
                    circle,
                    rgba(23, 105, 170, 0.40),
                    transparent 68%
                );

        }


        /* =========================================================
           ESCENARIO CENTRADO
           ========================================================= */

        .login-stage {

            position: relative;

            z-index: 1;

            display: flex;

            align-items: center;

            justify-content: center;

            min-height: 100vh;

            padding: 40px 20px;

        }


        /* =========================================================
           TARJETA DE CRISTAL
           ========================================================= */

        .login-card {

            position: relative;

            width: 100%;

            max-width: 442px;

            padding: 42px 40px 36px;

            overflow: hidden;

            text-align: center;

            background: rgba(255, 255, 255, 0.93);

            border: 1px solid rgba(255, 255, 255, 0.75);

            border-radius: 24px;

            box-shadow:
                0 30px 70px rgba(4, 28, 52, 0.42);

            backdrop-filter: blur(16px);

            animation: lgEntra 0.5s ease-out both;

        }


        /* Franja de color superior */

        .login-card::before {

            content: '';

            position: absolute;

            top: 0;

            left: 0;

            right: 0;

            height: 5px;

            background: linear-gradient(
                90deg,
                var(--lg-celeste),
                var(--lg-azul-medio),
                var(--lg-azul-oscuro)
            );

        }


        @keyframes lgEntra {

            from {
                opacity: 0;
                transform: translateY(16px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }

        }


        /* ---------- Encabezado ---------- */

        .login-head {

            margin-bottom: 28px;

        }


        .login-logo {

            display: flex;

            align-items: center;

            justify-content: center;

            width: 96px;

            height: 96px;

            margin: 0 auto 20px;

            background: linear-gradient(
                140deg,
                var(--lg-celeste-claro),
                #ffffff
            );

            border: 1px solid rgba(39, 169, 223, 0.35);

            border-radius: 50%;

            box-shadow:
                0 10px 26px rgba(11, 79, 138, 0.18);

        }


        .login-logo img {

            width: 64px;

            height: 64px;

            object-fit: contain;

        }


        .login-eyebrow {

            margin: 0 0 6px;

            font-size: 12px;

            font-weight: 700;

            letter-spacing: 1.7px;

            text-transform: uppercase;

            color: var(--lg-azul-medio);

        }


        .login-card h1 {

            margin: 0 0 8px;

            font-size: 28px;

            font-weight: 700;

            color: var(--lg-azul-oscuro);

        }


        .login-sub {

            margin: 0;

            font-size: 14.5px;

            color: var(--lg-gris);

        }


        /* =========================================================
           MENSAJE DE ERROR
           ========================================================= */

        .login-alert {

            display: flex;

            align-items: flex-start;

            gap: 11px;

            padding: 13px 15px;

            margin-bottom: 24px;

            font-size: 13.5px;

            line-height: 1.55;

            text-align: left;

            color: var(--lg-error);

            background: var(--lg-error-fondo);

            border: 1px solid var(--lg-error-borde);

            border-left: 4px solid var(--lg-error);

            border-radius: 12px;

        }


        .login-alert svg {

            flex: none;

            width: 18px;

            height: 18px;

            margin-top: 1px;

        }


        /* =========================================================
           CAMPOS
           ========================================================= */

        .field {

            margin-bottom: 17px;

            text-align: left;

        }


        .field label {

            display: block;

            margin-bottom: 7px;

            font-size: 12.5px;

            font-weight: 700;

            letter-spacing: 0.3px;

            text-transform: uppercase;

            color: var(--lg-azul-oscuro);

        }


        .field__box {

            position: relative;

            display: flex;

            align-items: center;

        }


        .field__icon {

            position: absolute;

            left: 15px;

            display: flex;

            color: #93a1ad;

            pointer-events: none;

            transition: color 0.2s;

        }


        .field__icon svg {

            width: 18px;

            height: 18px;

        }


        .field input {

            width: 100%;

            padding: 14px 15px 14px 45px;

            font-family: inherit;

            font-size: 15px;

            color: #111;

            background: var(--lg-gris-claro);

            border: 1.5px solid var(--lg-borde);

            border-radius: var(--lg-radio);

            outline: none;

            transition:
                border-color 0.2s,
                box-shadow 0.2s,
                background 0.2s;

        }


        .field input::placeholder {

            color: #a3adb7;

        }


        .field input:focus {

            background: #fff;

            border-color: var(--lg-celeste);

            box-shadow:
                0 0 0 4px rgba(39, 169, 223, 0.16);

        }


        .field input:focus + .field__toggle,
        .field__box:focus-within .field__icon {

            color: var(--lg-azul-medio);

        }


        /* Mostrar / ocultar contraseña */

        .field__toggle {

            position: absolute;

            right: 7px;

            display: flex;

            align-items: center;

            justify-content: center;

            width: 34px;

            height: 34px;

            padding: 0;

            color: #93a1ad;

            background: none;

            border: none;

            border-radius: 9px;

            cursor: pointer;

            transition:
                color 0.2s,
                background 0.2s;

        }


        .field__toggle:hover {

            color: var(--lg-azul-medio);

            background: var(--lg-celeste-claro);

        }


        .field__toggle:focus-visible {

            outline: 2px solid var(--lg-celeste);

            outline-offset: 1px;

        }


        .field__toggle svg {

            width: 19px;

            height: 19px;

        }


        /* =========================================================
           BOTÓN
           ========================================================= */

        .login-btn {

            display: flex;

            align-items: center;

            justify-content: center;

            gap: 10px;

            width: 100%;

            margin-top: 10px;

            padding: 15px;

            font-family: inherit;

            font-size: 16px;

            font-weight: 700;

            letter-spacing: 0.4px;

            color: #fff;

            background: linear-gradient(
                135deg,
                var(--lg-azul-medio),
                var(--lg-azul)
            );

            border: none;

            border-radius: var(--lg-radio);

            cursor: pointer;

            box-shadow:
                0 10px 22px rgba(11, 79, 138, 0.34);

            transition:
                transform 0.2s,
                box-shadow 0.2s,
                filter 0.2s;

        }


        .login-btn:hover {

            transform: translateY(-2px);

            filter: brightness(1.07);

            box-shadow:
                0 14px 28px rgba(11, 79, 138, 0.42);

        }


        .login-btn:active {

            transform: translateY(0);

        }


        .login-btn:focus-visible {

            outline: 3px solid rgba(39, 169, 223, 0.6);

            outline-offset: 2px;

        }


        .login-btn svg {

            width: 18px;

            height: 18px;

        }


        /* =========================================================
           PIE
           ========================================================= */

        .login-foot {

            margin: 26px 0 0;

            padding-top: 20px;

            font-size: 12.5px;

            line-height: 1.65;

            color: var(--lg-gris);

            border-top: 1px solid #e6ecf1;

        }


        .login-foot strong {

            color: var(--lg-azul);

        }


        .login-badge {

            display: inline-flex;

            align-items: center;

            gap: 6px;

            margin-bottom: 12px;

            padding: 5px 13px;

            font-size: 11.5px;

            font-weight: 700;

            letter-spacing: 0.8px;

            text-transform: uppercase;

            color: var(--lg-azul);

            background: var(--lg-celeste-claro);

            border: 1px solid #bde6f5;

            border-radius: 999px;

        }


        .login-badge svg {

            width: 12px;

            height: 12px;

            stroke: var(--lg-celeste);

        }


        /* =========================================================
           RESPONSIVE
           ========================================================= */

        @media (max-width: 520px) {

            .login-stage {
                padding: 22px 16px 32px;
            }

            .login-card {
                padding: 34px 24px 28px;
                border-radius: 20px;
            }

            .login-logo {
                width: 82px;
                height: 82px;
                margin-bottom: 16px;
            }

            .login-logo img {
                width: 55px;
                height: 55px;
            }

            .login-card h1 {
                font-size: 24px;
            }

            .login-sub {
                font-size: 13.5px;
            }

        }


        /* Accesibilidad: sin animaciones si el usuario las rechaza */

        @media (prefers-reduced-motion: reduce) {

            .login-card {
                animation: none;
            }

            .login-btn,
            .field input,
            .field__toggle {
                transition: none;
            }

            .login-btn:hover {
                transform: none;
            }

        }

    </style>

</head>


<body class="login-body">

<div class="login-stage">

    <div class="login-card">


        <!-- =====================================================
             ENCABEZADO
             ===================================================== -->

        <header class="login-head">

            <span class="login-badge">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="3" y="11" width="18" height="11" rx="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
                Sistema interno
            </span>

            <div class="login-logo">
                <img
                    src="assets/img/logo.png"
                    alt="Logo Electrodomésticos Santa Ana"
                >
            </div>

            <p class="login-eyebrow">
                Electrodomésticos Santa Ana
            </p>

            <h1>
                Módulo Contable
            </h1>

            <p class="login-sub">
                Acceso exclusivo para el Contador
            </p>

        </header>


        <!-- =====================================================
             MENSAJE DE ERROR
             ===================================================== -->

        <?php if ($error !== ''): ?>

            <div
                class="login-alert"
                role="alert"
            >

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M12 8v4"/>
                    <path d="M12 16h.01"/>
                </svg>

                <span>
                    <?php
                    echo htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        'UTF-8'
                    );
                    ?>
                </span>

            </div>

        <?php endif; ?>


        <!-- =====================================================
             FORMULARIO
             ===================================================== -->

        <form
            action="login_process.php"
            method="POST"
        >


            <!-- Token CSRF -->

            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo htmlspecialchars($csrfLogin, ENT_QUOTES, 'UTF-8'); ?>"
            >


            <!-- Usuario -->

            <div class="field">

                <label for="usuario">
                    Usuario
                </label>

                <div class="field__box">

                    <span class="field__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                    </span>

                    <input
                        type="text"
                        id="usuario"
                        name="usuario"
                        placeholder="Ingresa tu usuario"
                        required
                        autofocus
                        autocomplete="username"
                        maxlength="50"
                    >

                </div>

            </div>


            <!-- Contraseña -->

            <div class="field">

                <label for="password">
                    Contraseña
                </label>

                <div class="field__box">

                    <span class="field__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="3" y="11" width="18" height="11" rx="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                    </span>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Ingresa tu contraseña"
                        required
                        autocomplete="current-password"
                        maxlength="255"
                    >

                    <button
                        type="button"
                        class="field__toggle"
                        id="togglePassword"
                        aria-label="Mostrar contraseña"
                        aria-pressed="false"
                    >
                        <svg id="iconOjo" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>

                </div>

            </div>


            <!-- Botón -->

            <button
                type="submit"
                class="login-btn"
            >
                Ingresar
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M5 12h14"/>
                    <path d="m13 6 6 6-6 6"/>
                </svg>
            </button>

        </form>


        <!-- =====================================================
             PIE
             ===================================================== -->

        <p class="login-foot">
            Acceso restringido al usuario autorizado.
            <br>
            Solo el usuario con rol
            <strong>"contador"</strong>
            puede acceder al sistema.
        </p>

    </div>

</div>


<script>

    /*
    |--------------------------------------------------------------
    | Mostrar / ocultar contraseña
    |--------------------------------------------------------------
    */

    (function () {

        const input = document.getElementById('password');
        const boton = document.getElementById('togglePassword');
        const icono = document.getElementById('iconOjo');

        if (!input || !boton || !icono) {
            return;
        }

        const ojoAbierto =
            '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/>' +
            '<circle cx="12" cy="12" r="3"/>';

        const ojoCerrado =
            '<path d="M10.6 6.2A9.9 9.9 0 0 1 12 6c6.4 0 10 7 10 7a18 18 0 0 1-2.6 3.6"/>' +
            '<path d="M6.6 6.7A18 18 0 0 0 2 13s3.6 7 10 7a9.8 9.8 0 0 0 4.3-1"/>' +
            '<path d="m2 2 20 20"/>' +
            '<path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>';

        boton.addEventListener('click', function () {

            const visible = input.type === 'text';

            input.type = visible ? 'password' : 'text';
            icono.innerHTML = visible ? ojoAbierto : ojoCerrado;
            boton.setAttribute('aria-pressed', String(!visible));
            boton.setAttribute(
                'aria-label',
                visible ? 'Mostrar contraseña' : 'Ocultar contraseña'
            );

            input.focus();

        });

    })();

</script>

</body>

</html>
