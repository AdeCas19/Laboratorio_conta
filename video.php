<?php

require_once __DIR__ . '/includes/auth.php';

$tituloPagina = 'Video';
$activo = 'video';

$rutaVideo = 'assets/video/Electrodomesticos_Santa_Ana_Video_Final.mp4';

require_once __DIR__ . '/includes/header.php';

?>


<section class="manual-page">


    <!-- ===================================================
         PORTADA
         =================================================== -->

    <div class="hero manual-hero">

        <div class="hero-content">

            <span class="hero-tag">
                MULTIMEDIA
            </span>

            <h1>Video</h1>

            <p>
                Video promocional de
                <strong>Electrodomésticos Santa Ana</strong>.
            </p>

        </div>

        <div class="manual-hero-side">

            <div class="hero-graphic">

                <div class="hero-icon">🎬</div>

                <div class="hero-icon">📺</div>

                <div class="hero-icon">▶️</div>

            </div>

        </div>

    </div>


    <!-- ===================================================
         REPRODUCTOR DE VIDEO
         =================================================== -->

    <div class="manual-pdf-card" id="video-reproductor">

        <div class="manual-pdf-header">

            <div class="manual-pdf-title">

                <strong>
                    Electrodomésticos Santa Ana - Video
                </strong>

                <span>
                    Video promocional
                </span>

            </div>

            <div class="manual-pdf-tools">

                <span class="manual-pdf-format">MP4</span>

                <a
                    class="manual-pdf-btn primary"
                    href="<?php echo $rutaVideo; ?>"
                    download
                >
                    ⬇ Descargar
                </a>

            </div>

        </div>

        <video
            class="manual-pdf-frame"
            src="<?php echo $rutaVideo; ?>"
            controls
            preload="metadata"
            playsinline
        >
            Tu navegador no soporta la reproducción de video.
        </video>

    </div>


</section>

<?php

require_once __DIR__ . '/includes/footer.php';

?>
