<?php

require_once __DIR__ . '/includes/security.php';

if (
    isset($_SESSION['usuario_id']) &&
    ($_SESSION['rol'] ?? '') === 'contador'
) {
    header('Location: dashboard.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Productos - Electrodomésticos Santa Ana</title>
    <meta name="description" content="Catálogo de electrodomésticos Santa Ana - refrigeradoras, lavadoras, Smart TV, microondas, cocinas y más.">
    <link rel="icon" href="assets/img/logo.png">
    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        :root {
            --azul: #0b4f8a;
            --azul-oscuro: #062b4c;
            --azul-claro: #27a9df;
            --azul-suave: #eaf7fc;
            --blanco: #ffffff;
            --gris-fondo: #f5f8fb;
            --gris: #667585;
            --gris-claro: #e3e9ee;
            --negro: #122333;
            --sombra: 0 15px 40px rgba(6, 43, 76, 0.10);
            --radio: 20px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }

        body {
            font-family: "Segoe UI", Arial, Helvetica, sans-serif;
            color: var(--negro);
            background: var(--gris-fondo);
        }

        a { text-decoration: none; color: inherit; }
        img { max-width: 100%; display: block; }

        .container {
            width: min(1180px, calc(100% - 40px));
            margin: auto;
        }

        /* NAVBAR */
        .navbar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(255,255,255,.96);
            border-bottom: 1px solid var(--gris-claro);
            box-shadow: 0 4px 20px rgba(0,0,0,.05);
        }

        .navbar-content {
            min-height: 78px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 25px;
        }

        .logo-area { display: flex; align-items: center; gap: 13px; }

        .logo {
            width: 48px;
            height: 48px;
            object-fit: contain;
            padding: 5px;
            border-radius: 12px;
            background: var(--azul-suave);
        }

        .logo-text strong { display: block; font-size: 15px; color: var(--azul-oscuro); }
        .logo-text span { display: block; margin-top: 3px; font-size: 10px; letter-spacing: .12em; text-transform: uppercase; color: var(--gris); }

        .nav-links { display: flex; align-items: center; gap: 28px; }
        .nav-links a { font-size: 13px; font-weight: 600; color: var(--gris); transition: .2s; }
        .nav-links a:hover { color: var(--azul); }

        .login-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 22px;
            color: white;
            background: linear-gradient(135deg, var(--azul), var(--azul-oscuro));
            border-radius: 30px;
            font-size: 13px;
            font-weight: 700;
            box-shadow: 0 8px 20px rgba(11,79,138,.20);
            transition: .2s;
        }

        .login-btn:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(11,79,138,.30); }

        /* PAGE HERO */
        .page-hero {
            padding: 70px 0;
            background:
                radial-gradient(circle at 80% 20%, rgba(39,169,223,.25), transparent 40%),
                linear-gradient(135deg, var(--azul-oscuro) 0%, #0a3d6b 50%, var(--azul) 100%);
            color: white;
            text-align: center;
        }

        .page-hero h1 {
            font-size: clamp(32px, 5vw, 52px);
            margin-bottom: 14px;
        }

        .page-hero p {
            color: rgba(255,255,255,.80);
            font-size: 16px;
            max-width: 600px;
            margin: 0 auto;
            line-height: 1.6;
        }

        /* PRODUCTS */
        .products-section { padding: 80px 0; }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 25px;
        }

        .product-card {
            overflow: hidden;
            background: white;
            border: 1px solid var(--gris-claro);
            border-radius: var(--radio);
            box-shadow: 0 8px 25px rgba(0,0,0,.05);
            transition: .25s;
        }

        .product-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 18px 40px rgba(6,43,76,.12);
        }

        .product-image {
            height: 260px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(145deg, #eaf7fc, #f8fbfd);
            padding: 25px;
        }

        .product-image img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .product-info { padding: 24px; }

        .product-tag {
            display: inline-block;
            padding: 5px 12px;
            color: var(--azul);
            background: var(--azul-suave);
            border-radius: 20px;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .product-info h3 {
            margin: 12px 0 8px;
            color: var(--azul-oscuro);
            font-size: 19px;
        }

        .product-info p {
            color: var(--gris);
            line-height: 1.6;
            font-size: 13px;
        }

        .product-price {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--gris-claro);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .product-price strong {
            font-size: 20px;
            color: var(--azul);
        }

        .product-price span {
            font-size: 12px;
            color: var(--gris);
        }

        /* CATEGORY FILTER */
        .filter-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 40px;
            flex-wrap: wrap;
        }

        .filter-btn {
            padding: 10px 22px;
            background: white;
            border: 1px solid var(--gris-claro);
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
            color: var(--gris);
            cursor: pointer;
            transition: .2s;
        }

        .filter-btn:hover,
        .filter-btn.active {
            background: var(--azul);
            border-color: var(--azul);
            color: white;
        }

        /* FOOTER */
        footer {
            padding: 55px 0 0;
            background: linear-gradient(180deg, var(--azul-oscuro), #041e38);
            color: white;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr 1fr;
            gap: 50px;
            padding-bottom: 40px;
        }

        .footer-logo { display: flex; align-items: center; gap: 14px; margin-bottom: 18px; }
        .footer-logo img { width: 52px; height: 52px; background: white; border-radius: 12px; padding: 6px; }
        .footer-logo strong { font-size: 17px; }
        .footer-logo span { display: block; margin-top: 3px; font-size: 10px; letter-spacing: .12em; text-transform: uppercase; color: rgba(255,255,255,.50); }

        footer h3 { font-size: 15px; margin-bottom: 18px; }
        footer p, footer span { color: rgba(255,255,255,.65); font-size: 13px; line-height: 1.8; }

        .footer-bottom {
            padding: 22px 0;
            border-top: 1px solid rgba(255,255,255,.10);
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: rgba(255,255,255,.50);
            font-size: 12px;
        }

        /* RESPONSIVE */
        @media(max-width: 950px) {
            .nav-links { display: none; }
            .products-grid { grid-template-columns: repeat(2, 1fr); }
            .footer-grid { grid-template-columns: 1fr 1fr; }
            .footer-bottom { flex-direction: column; gap: 8px; text-align: center; }
        }

        @media(max-width: 650px) {
            .products-grid { grid-template-columns: 1fr; }
            .footer-grid { grid-template-columns: 1fr; gap: 30px; }
        }
    </style>
</head>
<body>

<header class="navbar">
    <div class="container navbar-content">
        <a href="index.php" class="logo-area">
            <img src="assets/img/logo.png" alt="Electrodomésticos Santa Ana" class="logo">
            <div class="logo-text">
                <strong>Electrodomésticos Santa Ana</strong>
                <span>Gestión y contabilidad</span>
            </div>
        </a>
        <nav class="nav-links">
            <a href="index.php#nosotros">Nosotros</a>
            <a href="index.php#categorias">Categorías</a>
            <a href="productos.php">Productos</a>
            <a href="index.php#ofertas">Ofertas</a>
        </nav>
        <a href="login.php" class="login-btn">Ingresar</a>
    </div>
</header>

<section class="page-hero">
    <div class="container">
        <h1>Nuestros Productos</h1>
        <p>Descubre nuestro catálogo completo de electrodomésticos con las mejores marcas y precios del mercado.</p>
    </div>
</section>

<section class="products-section">
    <div class="container">

        <div class="filter-bar">
            <button class="filter-btn active">Todos</button>
            <button class="filter-btn">Batidora</button>
            <button class="filter-btn">Cafetera</button>
            <button class="filter-btn">Freidora</button>
            <button class="filter-btn">Licuadora</button>
            <button class="filter-btn">Plancha</button>
            <button class="filter-btn">Radio</button>
        </div>

        <div class="products-grid">

            <article class="product-card">
                <div class="product-image">
                    <img src="assets/img/batidora.png" alt="Batidora">
                </div>
                <div class="product-info">
                    <span class="product-tag">Cocina</span>
                    <h3>Batidora</h3>
                    <p>Prepara masas, salsas y postres con la batidora ideal para tu cocina.</p>
                    <div class="product-price">
                        <strong>$35</strong>
                        <span>Desde</span>
                    </div>
                </div>
            </article>

            <article class="product-card">
                <div class="product-image">
                    <img src="assets/img/cafetera.png" alt="Cafetera">
                </div>
                <div class="product-info">
                    <span class="product-tag">Cocina</span>
                    <h3>Cafetera</h3>
                    <p>Disfruta del mejor café cada mañana con nuestras cafeteras de calidad.</p>
                    <div class="product-price">
                        <strong>$40</strong>
                        <span>Desde</span>
                    </div>
                </div>
            </article>

            <article class="product-card">
                <div class="product-image">
                    <img src="assets/img/freidora.png" alt="Freidora">
                </div>
                <div class="product-info">
                    <span class="product-tag">Cocina</span>
                    <h3>Freidora</h3>
                    <p>Fríe tus alimentos favoritos de forma rápida y crujiente.</p>
                    <div class="product-price">
                        <strong>$55</strong>
                        <span>Desde</span>
                    </div>
                </div>
            </article>

            <article class="product-card">
                <div class="product-image">
                    <img src="assets/img/licuadora.png" alt="Licuadora">
                </div>
                <div class="product-info">
                    <span class="product-tag">Cocina</span>
                    <h3>Licuadora</h3>
                    <p>Prepara licuados, jugos y sopas al instante con máxima potencia.</p>
                    <div class="product-price">
                        <strong>$30</strong>
                        <span>Desde</span>
                    </div>
                </div>
            </article>

            <article class="product-card">
                <div class="product-image">
                    <img src="assets/img/plancha.png" alt="Plancha">
                </div>
                <div class="product-info">
                    <span class="product-tag">Hogar</span>
                    <h3>Plancha</h3>
                    <p>Deja tu ropa impecable con nuestras planchas de vapor y seco.</p>
                    <div class="product-price">
                        <strong>$25</strong>
                        <span>Desde</span>
                    </div>
                </div>
            </article>

            <article class="product-card">
                <div class="product-image">
                    <img src="assets/img/radio.png" alt="Radio">
                </div>
                <div class="product-info">
                    <span class="product-tag">Tecnología</span>
                    <h3>Radio</h3>
                    <p>Escucha tu música y programas favoritos con nuestros radios.</p>
                    <div class="product-price">
                        <strong>$20</strong>
                        <span>Desde</span>
                    </div>
                </div>
            </article>

        </div>

    </div>
</section>

<footer>
    <div class="container">
        <div class="footer-grid">
            <div>
                <div class="footer-logo">
                    <img src="assets/img/logo.png" alt="Logo">
                    <div>
                        <strong>Electrodomésticos Santa Ana</strong>
                        <span>Gestión y contabilidad</span>
                    </div>
                </div>
                <p>Tu hogar, nuestra prioridad.</p>
            </div>
            <div>
                <h3>Contacto</h3>
                <span>📞 Teléfono<br>+503 0000-0000</span>
                <br><br>
                <span>💬 WhatsApp directo<br>Escríbenos por WhatsApp</span>
            </div>
            <div>
                <h3>Horario de atención</h3>
                <span>Lun–Vie: 8:00–18:00<br>Sáb: 8:00–14:00<br>Dom: cerrado</span>
            </div>
        </div>
        <div class="footer-bottom">
            <span>© <?php echo date('Y'); ?> Electrodomésticos Santa Ana</span>
            <span>Tu hogar, nuestra prioridad</span>
        </div>
    </div>
</footer>

</body>
</html>
