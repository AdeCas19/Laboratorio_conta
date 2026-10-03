<?php

require_once __DIR__ . '/includes/auth.php';

$tituloPagina = 'Manual de usuario';
$activo = 'manual';

$rutaManual = 'assets/pdf/manual-usuario.pdf';
$nombreManual = 'manual-usuario.pdf';
$versionManual = '1.1';
$fechaManual = 'Septiembre de 2026';
$totalPaginas = 6;

/** Contenido del manual, equivalente a las páginas del documento PDF. */
$seccionesManual = [
    [
        'numero'  => '01',
        'icono'   => '🔐',
        'titulo'  => 'Introducción y acceso',
        'resumen' => 'Qué organiza el sistema, los requisitos previos y los pasos para iniciar y cerrar sesión.',
        'pagina'  => 2,
    ],
    [
        'numero'  => '02',
        'icono'   => '🧭',
        'titulo'  => 'Menú lateral',
        'resumen' => 'Descripción de cada módulo del sistema y cómo identificar la sección activa.',
        'pagina'  => 3,
    ],
    [
        'numero'  => '03',
        'icono'   => '📚',
        'titulo'  => 'Gestión contable',
        'resumen' => 'Catálogo de cuentas, registro de asientos en el Libro Diario y consulta del Libro Mayor.',
        'pagina'  => 4,
    ],
    [
        'numero'  => '04',
        'icono'   => '📦',
        'titulo'  => 'Inventario y Kardex',
        'resumen' => 'Alta de productos, inventario inicial y consulta del historial de entradas y salidas.',
        'pagina'  => 5,
    ],
    [
        'numero'  => '05',
        'icono'   => '🛠️',
        'titulo'  => 'Buenas prácticas y solución de problemas',
        'resumen' => 'Recomendaciones de uso, respaldos y respuesta a los errores más frecuentes.',
        'pagina'  => 6,
    ],
];

$buenasPracticas = [
    'Realiza una copia de seguridad de la base de datos y de los archivos importantes.',
    'Revisa los movimientos del día antes de cerrar la sesión.',
    'Utiliza el Kardex para respaldar la trazabilidad de los productos.',
    'Guarda los comprobantes y documentos de respaldo de cada operación.',
];

$solucionProblemas = [
    'No puedes iniciar sesión: verifica el usuario, la contraseña y que tu cuenta esté activa.',
    'Una página no carga: actualiza con Ctrl + F5 y revisa que Apache y MySQL sigan iniciados.',
    'Un reporte no cuadra: revisa fechas, cuentas y el debe/haber de los asientos.',
    'Un producto no se elimina: revisa si todavía tiene existencias o movimientos.',
];

require_once __DIR__ . '/includes/header.php';

?>


<section class="manual-page">


    <!-- ===================================================
         PORTADA
         =================================================== -->

    <div class="hero manual-hero">

        <div class="hero-content">

            <span class="hero-tag">
                DOCUMENTACIÓN DEL SISTEMA
            </span>

            <h1>Manual de usuario</h1>

            <p>
                Guía práctica para organizar la información contable,
                registrar movimientos y controlar el inventario de
                <strong>Electrodomésticos Santa Ana</strong>.
                Consulta el documento completo sin salir del sistema.
            </p>

        </div>

        <div class="manual-hero-side">

            <div class="manual-hero-actions">

                <a
                    class="btn secondary"
                    href="<?php echo $rutaManual; ?>"
                    download
                >
                    ⬇ Descargar PDF
                </a>

                <a
                    class="btn"
                    href="#manual-documento"
                >
                    📖 Ver en pantalla
                </a>

            </div>

            <div class="hero-graphic">

                <div class="hero-icon">📘</div>

                <div class="hero-icon">🧭</div>

                <div class="hero-icon">📦</div>

            </div>

        </div>

    </div>


    <!-- ===================================================
         RESUMEN DEL DOCUMENTO
         =================================================== -->

    <div class="manual-stats">

        <div class="kpi">

            <div class="label">Secciones</div>

            <div class="value"><?php echo count($seccionesManual); ?></div>

        </div>

        <div class="kpi">

            <div class="label">Páginas</div>

            <div class="value"><?php echo $totalPaginas; ?></div>

        </div>

        <div class="kpi">

            <div class="label">Módulos cubiertos</div>

            <div class="value"><?php echo count($seccionesManual) + 1; ?></div>

        </div>

        <div class="kpi">

            <div class="label">Versión</div>

            <div class="value"><?php echo $versionManual; ?></div>

        </div>

    </div>


    <!-- ===================================================
         ÍNDICE
         =================================================== -->

    <section class="manual-index-section">

        <div class="section-heading">

            <div class="section-icon">🗂️</div>

            <div>

                <h2>Contenido del manual</h2>

                <p>
                    Selecciona una sección para abrir el documento
                    en la página correspondiente.
                </p>

            </div>

        </div>

        <div class="manual-index">

            <?php foreach ($seccionesManual as $seccion): ?>

                <a
                    class="manual-index-card"
                    href="<?php echo $rutaManual; ?>#page=<?php echo (int)$seccion['pagina']; ?>"
                    target="_blank"
                    rel="noopener"
                >

                    <div class="manual-index-top">

                        <span class="manual-index-num">
                            <?php echo $seccion['numero']; ?>
                        </span>

                        <span class="manual-index-icon">
                            <?php echo $seccion['icono']; ?>
                        </span>

                    </div>

                    <h3>
                        <?php echo htmlspecialchars($seccion['titulo']); ?>
                    </h3>

                    <p>
                        <?php echo htmlspecialchars($seccion['resumen']); ?>
                    </p>

                    <span class="manual-index-link">
                        Ver en la página <?php echo (int)$seccion['pagina']; ?> →
                    </span>

                </a>

            <?php endforeach; ?>

        </div>

    </section>


    <!-- ===================================================
         VISUALIZADOR DEL DOCUMENTO
         =================================================== -->

    <div class="manual-pdf-card" id="manual-documento">

        <div class="manual-pdf-header">

            <div class="manual-pdf-title">

                <strong>
                    Manual de usuario - Electrodomésticos Santa Ana
                </strong>

                <span>
                    Versión <?php echo $versionManual; ?>
                    · <?php echo $fechaManual; ?>
                    · <?php echo $totalPaginas; ?> páginas
                </span>

            </div>

            <div class="manual-pdf-tools">

                <span class="manual-pdf-format">PDF</span>

                <a
                    class="manual-pdf-btn"
                    href="<?php echo $rutaManual; ?>"
                    target="_blank"
                    rel="noopener"
                >
                    ↗ Abrir
                </a>

                <a
                    class="manual-pdf-btn primary"
                    href="<?php echo $rutaManual; ?>"
                    download
                >
                    ⬇ Descargar
                </a>

            </div>

        </div>

        <object
            class="manual-pdf-frame"
            data="<?php echo $rutaManual; ?>"
            type="application/pdf"
            aria-label="Manual de usuario en formato PDF"
        >

            <div class="manual-pdf-fallback">

                <span>📄</span>

                <p>
                    Tu navegador no puede mostrar el PDF directamente.
                </p>

                <a class="btn" href="<?php echo $rutaManual; ?>" download>
                    Descargar el manual
                </a>

            </div>

        </object>

    </div>


    <!-- ===================================================
         RECOMENDACIONES
         =================================================== -->

    <section class="manual-notes-section">

        <div class="section-heading">

            <div class="section-icon">💡</div>

            <div>

                <h2>Recomendaciones</h2>

                <p>
                    Buenas prácticas y respuestas rápidas a los
                    problemas más habituales del sistema.
                </p>

            </div>

        </div>

        <div class="manual-tips">

            <div class="manual-tip">

                <h3>✅ Buenas prácticas</h3>

                <p class="sub">
                    Acciones que ayudan a mantener la información
                    contable y de inventario en orden.
                </p>

                <ul>

                    <?php foreach ($buenasPracticas as $practica): ?>

                        <li>

                            <span class="mark">✓</span>

                            <span>
                                <?php echo htmlspecialchars($practica); ?>
                            </span>

                        </li>

                    <?php endforeach; ?>

                </ul>

            </div>

            <div class="manual-tip alerta">

                <h3>⚠️ Si ocurre un problema</h3>

                <p class="sub">
                    Revisa estas causas antes de solicitar ayuda
                    al administrador del sistema.
                </p>

                <ul>

                    <?php foreach ($solucionProblemas as $problema): ?>

                        <li>

                            <span class="mark">!</span>

                            <span>
                                <?php echo htmlspecialchars($problema); ?>
                            </span>

                        </li>

                    <?php endforeach; ?>

                </ul>

            </div>

        </div>

    </section>


</section>

<?php

require_once __DIR__ . '/includes/footer.php';

?>
