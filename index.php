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
    <title>Electrodomésticos Santa Ana</title>
    <meta name="description" content="Electrodomésticos Santa Ana - productos, inventario y gestión empresarial.">
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

        /* HERO */
        .hero {
            padding: 90px 0;
            background:
                radial-gradient(circle at 80% 20%, rgba(39,169,223,.25), transparent 40%),
                linear-gradient(135deg, var(--azul-oscuro) 0%, #0a3d6b 50%, var(--azul) 100%);
            color: white;
            overflow: hidden;
        }

        .hero-grid {
            display: grid;
            grid-template-columns: 1fr 1.1fr;
            align-items: center;
            gap: 60px;
        }

        .hero-label {
            display: inline-flex;
            padding: 8px 16px;
            margin-bottom: 24px;
            color: var(--azul-claro);
            background: rgba(39,169,223,.12);
            border: 1px solid rgba(39,169,223,.25);
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .12em;
        }

        .hero h1 {
            font-size: clamp(38px, 5vw, 58px);
            line-height: 1.08;
            color: white;
            margin-bottom: 22px;
        }

        .hero h1 span { color: var(--azul-claro); }

        .hero-text {
            max-width: 520px;
            font-size: 16px;
            line-height: 1.7;
            color: #ffffff !important;
            margin-bottom: 32px;
        }

        .hero-buttons { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 28px; }

        .btn-primary {
            padding: 14px 28px;
            color: var(--azul-oscuro);
            background: white;
            border-radius: 30px;
            font-weight: 700;
            transition: .2s;
        }

        .btn-primary:hover { background: var(--azul-suave); transform: translateY(-2px); }

        .btn-secondary {
            padding: 14px 28px;
            color: white;
            border: 1.5px solid rgba(255,255,255,.40);
            border-radius: 30px;
            font-weight: 700;
            background: transparent;
            transition: .2s;
        }

        .btn-secondary:hover { background: rgba(255,255,255,.10); border-color: white; }

        .hero-trust { display: flex; align-items: center; gap: 10px; font-size: 13px; color: rgba(255,255,255,.70); }
        .hero-trust::before {
            content: "✓";
            display: flex; align-items: center; justify-content: center;
            width: 22px; height: 22px;
            background: var(--azul-claro);
            color: var(--azul-oscuro);
            border-radius: 50%;
            font-size: 12px; font-weight: 800;
        }

        /* HERO CAROUSEL */
        .hero-carousel {
            position: relative;
            overflow: hidden;
            border-radius: var(--radio);
        }

        .hero-carousel-track {
            display: flex;
            transition: transform .5s ease;
        }

        .hero-product {
            min-width: 100%;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background: rgba(255,255,255,.05);
            border: 1px solid rgba(255,255,255,.10);
            border-radius: var(--radio);
            backdrop-filter: blur(10px);
        }

        .hero-product-img {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 30px 20px;
            min-height: 300px;
        }

        .hero-product-img img {
            max-width: 100%;
            max-height: 280px;
            object-fit: contain;
            filter: drop-shadow(0 15px 25px rgba(0,0,0,.35));
        }

        .hero-product-info { padding: 20px 25px 25px; }
        .hero-product-info strong { display: block; color: white; font-size: 18px; }
        .hero-product-info small { display: block; margin-top: 5px; color: rgba(255,255,255,.60); font-size: 13px; }

        .carousel-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.20);
            border-radius: 50%;
            color: white;
            font-size: 18px;
            cursor: pointer;
            z-index: 10;
            transition: .2s;
            backdrop-filter: blur(10px);
        }

        .carousel-btn:hover { background: rgba(255,255,255,.22); }
        .carousel-btn.prev { left: 15px; }
        .carousel-btn.next { right: 15px; }

        .carousel-dots {
            position: absolute;
            bottom: 15px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 8px;
            z-index: 10;
        }

        .carousel-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: rgba(255,255,255,.30);
            cursor: pointer;
            transition: .2s;
        }

        .carousel-dot.active { background: white; transform: scale(1.2); }

        /* SECTIONS */
        .section { padding: 90px 0; }
        .section-white { background: white; }

        .section-header { max-width: 680px; margin-bottom: 45px; }

        .section-label {
            display: block;
            margin-bottom: 10px;
            color: var(--azul-claro);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .14em;
            text-transform: uppercase;
        }

        .section-header h2 { font-size: clamp(28px, 4vw, 42px); color: var(--azul-oscuro); margin-bottom: 14px; }
        .section-header p { color: var(--gris); line-height: 1.7; font-size: 15px; }

        /* CATEGORIES */
        .categories { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; }

        .category {
            padding: 30px 22px;
            background: white;
            border: 1px solid var(--gris-claro);
            border-radius: var(--radio);
            text-align: center;
            transition: .25s;
        }

        .category:hover { transform: translateY(-6px); border-color: var(--azul-claro); box-shadow: var(--sombra); }

        .category-icon {
            width: 70px; height: 70px;
            margin: 0 auto 18px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 20px;
            background: var(--azul-suave);
        }

        .category-icon img { width: 52px; height: 52px; object-fit: contain; }
        .category h3 { font-size: 17px; color: var(--azul-oscuro); margin-bottom: 7px; }
        .category p { font-size: 12px; line-height: 1.5; color: var(--gris); }

        /* PURPOSE */
        .purpose { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; }

        .purpose-card {
            position: relative;
            padding: 38px;
            background: white;
            border-radius: var(--radio);
            border: 1px solid var(--gris-claro);
            box-shadow: 0 10px 30px rgba(0,0,0,.04);
            overflow: hidden;
        }

        .purpose-card::before { content: ""; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: var(--azul-claro); }
        .purpose-card.vision::before { background: var(--azul); }

        .purpose-icon {
            width: 52px; height: 52px;
            display: flex; align-items: center; justify-content: center;
            background: var(--azul-suave);
            border-radius: 14px;
            font-size: 24px;
            margin-bottom: 20px;
        }

        .purpose-card span { color: var(--azul); font-size: 11px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
        .purpose-card h3 { margin: 13px 0; font-size: 23px; color: var(--azul-oscuro); }
        .purpose-card p { color: var(--gris); line-height: 1.7; font-size: 14px; }

        /* BRANDS */
        .brands { display: grid; grid-template-columns: repeat(6, 1fr); gap: 15px; }

        .brand {
            padding: 22px 14px;
            text-align: center;
            background: white;
            border: 1px solid var(--gris-claro);
            border-radius: 14px;
            transition: .2s;
        }

        .brand:hover { border-color: var(--azul-claro); transform: translateY(-3px); box-shadow: var(--sombra); }

        .brand-logo { height: 44px; display: flex; align-items: center; justify-content: center; margin-bottom: 12px; }
        .brand-logo svg { max-width: 100%; max-height: 44px; object-fit: contain; }

        .brand span { display: block; color: var(--azul-oscuro); font-weight: 800; font-size: 15px; }
        .brand small { display: block; margin-top: 4px; color: var(--gris); font-size: 10px; font-weight: 500; }

        /* PROMOTIONS */
        .promotion {
            padding: 50px;
            display: grid;
            grid-template-columns: 1fr 1.4fr;
            gap: 40px;
            align-items: center;
            background:
                radial-gradient(circle at 90% 10%, rgba(39,169,223,.20), transparent 40%),
                linear-gradient(135deg, var(--azul-oscuro), var(--azul));
            border-radius: 28px;
            color: white;
            overflow: hidden;
        }

        .promotion h2 { font-size: 32px; margin-bottom: 14px; }
        .promotion p { color: rgba(255,255,255,.75); line-height: 1.6; font-size: 14px; margin-bottom: 24px; }
        .promotion .btn-primary { display: inline-flex; }

        .offers { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }

        .offer {
            padding: 26px 14px;
            text-align: center;
            background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.15);
            border-radius: 16px;
            backdrop-filter: blur(10px);
            transition: .25s;
        }

        .offer:hover { background: rgba(255,255,255,.14); transform: translateY(-4px); }
        .offer strong { display: block; font-size: 30px; color: var(--azul-claro); }
        .offer span { display: block; margin-top: 8px; font-size: 11px; line-height: 1.5; color: rgba(255,255,255,.80); }

        /* BENEFITS */
        .benefits { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; }

        .benefit {
            padding: 30px 24px;
            background: white;
            border: 1px solid var(--gris-claro);
            border-radius: var(--radio);
            text-align: center;
            box-shadow: 0 8px 25px rgba(0,0,0,.04);
            transition: .25s;
        }

        .benefit:hover { transform: translateY(-6px); border-color: var(--azul-claro); box-shadow: var(--sombra); }

        .benefit-icon {
            width: 64px; height: 64px;
            margin: 0 auto 18px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 18px;
            background: var(--azul-suave);
            font-size: 28px;
        }

        .benefit h3 { color: var(--azul-oscuro); font-size: 16px; margin-bottom: 10px; }
        .benefit p { color: var(--gris); font-size: 13px; line-height: 1.6; }

        /* STEPS */
        .steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }

        .step {
            position: relative;
            padding: 34px;
            background: white;
            border: 1px solid var(--gris-claro);
            border-radius: var(--radio);
            box-shadow: 0 8px 25px rgba(0,0,0,.04);
        }

        .step-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 44px; height: 44px;
            background: var(--azul);
            color: white;
            border-radius: 12px;
            font-size: 16px; font-weight: 800;
            margin-bottom: 20px;
        }

        .step-icon {
            width: 55px; height: 55px;
            display: flex; align-items: center; justify-content: center;
            background: var(--azul-suave);
            border-radius: 16px;
            font-size: 25px;
            margin-bottom: 20px;
        }

        .step h3 { color: var(--azul-oscuro); font-size: 18px; margin-bottom: 10px; }
        .step p { color: var(--gris); font-size: 13px; line-height: 1.7; }

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
            .hero-grid { grid-template-columns: 1fr; }
            .hero-carousel { max-width: 500px; margin: auto; }
            .categories { grid-template-columns: repeat(2, 1fr); }
            .brands { grid-template-columns: repeat(3, 1fr); }
            .promotion { grid-template-columns: 1fr; }
            .benefits { grid-template-columns: repeat(2, 1fr); }
            .footer-grid { grid-template-columns: 1fr 1fr; }
            .footer-bottom { flex-direction: column; gap: 8px; text-align: center; }
        }

        @media(max-width: 650px) {
            .container { width: calc(100% - 28px); }
            .hero { padding: 60px 0; }
            .purpose, .steps { grid-template-columns: 1fr; }
            .categories { grid-template-columns: 1fr 1fr; }
            .brands { grid-template-columns: repeat(2, 1fr); }
            .promotion { padding: 30px 22px; }
            .offers { grid-template-columns: 1fr; }
            .footer-grid { grid-template-columns: 1fr; gap: 30px; }
        }

        @media(max-width: 430px) {
            .categories { grid-template-columns: 1fr; }
            .hero-buttons { flex-direction: column; }
            .btn-primary, .btn-secondary { text-align: center; width: 100%; }
            .carousel-btn { width: 36px; height: 36px; font-size: 14px; }
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
            <a href="#nosotros">Nosotros</a>
            <a href="#categorias">Categorías</a>
            <a href="#ofertas">Ofertas</a>
            <a href="#beneficios">Beneficios</a>
        </nav>
        <a href="login.php" class="login-btn">Ingresar</a>
    </div>
</header>

<section class="hero">
    <div class="container hero-grid">
        <div>
            <span class="hero-label">Gestión de electrodomésticos</span>
            <h1>Electrodomésticos <span>que mejoran tu vida.</span></h1>
            <p class="hero-text">Administra los productos, existencias, compras, ventas y finanzas de Electrodomésticos Santa Ana desde un sistema creado específicamente para este rubro.</p>
            <div class="hero-buttons">
                <a href="login.php" class="btn-primary">Administrar mi negocio →</a>
                <a href="productos.php" class="btn-secondary">Ver electrodomésticos</a>
            </div>
            <div class="hero-trust">Sistema especializado en la gestión de electrodomésticos.</div>
        </div>
        <div class="hero-carousel">
            <div class="hero-carousel-track">
                <article class="hero-product">
                    <div class="hero-product-img">
                        <img src="assets/img/refrigeradora.png" alt="Refrigeradora">
                    </div>
                    <div class="hero-product-info">
                        <strong>Refrigeradoras</strong>
                        <small>Conservación y tecnología</small>
                    </div>
                </article>
                <article class="hero-product">
                    <div class="hero-product-img">
                        <img src="assets/img/tv_smart.png" alt="Smart TV">
                    </div>
                    <div class="hero-product-info">
                        <strong>Smart TV</strong>
                        <small>Entretenimiento para todos</small>
                    </div>
                </article>
                <article class="hero-product">
                    <div class="hero-product-img">
                        <img src="assets/img/lavadoras.png" alt="Lavadora">
                    </div>
                    <div class="hero-product-info">
                        <strong>Lavadoras</strong>
                        <small>Comodidad para tu hogar</small>
                    </div>
                </article>
            </div>
            <button class="carousel-btn prev">&#8249;</button>
            <button class="carousel-btn next">&#8250;</button>
            <div class="carousel-dots">
                <span class="carousel-dot active"></span>
                <span class="carousel-dot"></span>
                <span class="carousel-dot"></span>
            </div>
        </div>
    </div>
</section>

<section class="section section-white" id="nosotros">
    <div class="container">
        <div class="section-header">
            <span class="section-label">Nuestra razón de ser</span>
            <h2>Misión y Visión</h2>
            <p>Nuestro compromiso es brindarte los mejores productos y un servicio de calidad.</p>
        </div>
        <div class="purpose">
            <article class="purpose-card">
                <div class="purpose-icon">🎯</div>
                <span>Misión</span>
                <h3>Comprar y vender con confianza</h3>
                <p>Comprar y vender electrodomésticos de calidad para el hogar, ofreciendo una experiencia cercana, transparente y segura, con un inventario actualizado y una gestión responsable de cada operación.</p>
            </article>
            <article class="purpose-card vision">
                <div class="purpose-icon">👁️</div>
                <span>Visión</span>
                <h3>Ser referencia en cada hogar</h3>
                <p>Ser reconocidos en Santa Ana como una tienda de confianza, referente en la variedad de electrodomésticos, el servicio y la eficiencia en cada compra y venta.</p>
            </article>
        </div>
    </div>
</section>

<section class="section" id="categorias">
    <div class="container">
        <div class="section-header">
            <span class="section-label">Categorías</span>
            <h2>Encuentra lo que necesitas</h2>
            <p>Conoce nuestras principales categorías de electrodomésticos.</p>
        </div>
        <div class="categories">
            <article class="category">
                <div class="category-icon"><img src="assets/img/refrigeradora.png" alt="Refrigeradoras"></div>
                <h3>Refrigeradoras</h3>
                <p>Equipos para conservar tus alimentos.</p>
            </article>
            <article class="category">
                <div class="category-icon"><img src="assets/img/lavadoras.png" alt="Lavadoras"></div>
                <h3>Lavadoras</h3>
                <p>Soluciones para el cuidado de tu ropa.</p>
            </article>
            <article class="category">
                <div class="category-icon"><img src="assets/img/tv_smart.png" alt="Smart TV"></div>
                <h3>Smart TV</h3>
                <p>Tecnología y entretenimiento para tu hogar.</p>
            </article>
            <article class="category">
                <div class="category-icon"><img src="assets/img/calentadora.png" alt="Climatización"></div>
                <h3>Climatización</h3>
                <p>Ventiladores y equipos para mantener tus espacios cómodos.</p>
            </article>
        </div>
    </div>
</section>

<section class="section section-white">
    <div class="container">
        <div class="section-header">
            <span class="section-label">Marcas que manejamos</span>
            <h2>Confianza en cada marca</h2>
            <p>Trabajamos con marcas reconocidas para brindarte variedad, garantía y respaldo en cada compra.</p>
        </div>
        <div class="brands">
            <div class="brand">
                <div class="brand-logo">
                    <svg viewBox="0 0 120 40" xmlns="http://www.w3.org/2000/svg"><text x="60" y="28" text-anchor="middle" font-family="Arial, sans-serif" font-size="20" font-weight="900" fill="#e60012">mabe</text></svg>
                </div>
                <small>Hogar</small>
            </div>
            <div class="brand">
                <div class="brand-logo">
                    <svg viewBox="0 0 120 40" xmlns="http://www.w3.org/2000/svg"><circle cx="60" cy="20" r="16" fill="#c4023c"/><text x="60" y="26" text-anchor="middle" font-family="Arial, sans-serif" font-size="16" font-weight="900" fill="#ffffff">LG</text></svg>
                </div>
                <small>Innovación</small>
            </div>
            <div class="brand">
                <div class="brand-logo">
                    <svg viewBox="0 0 140 40" xmlns="http://www.w3.org/2000/svg"><text x="70" y="28" text-anchor="middle" font-family="Arial, sans-serif" font-size="18" font-weight="900" fill="#1428a0" letter-spacing="2">SAMSUNG</text></svg>
                </div>
                <small>Tecnología</small>
            </div>
            <div class="brand">
                <div class="brand-logo">
                    <svg viewBox="0 0 140 40" xmlns="http://www.w3.org/2000/svg"><text x="70" y="28" text-anchor="middle" font-family="Georgia, serif" font-size="18" font-weight="700" fill="#003366" font-style="italic">Whirlpool</text></svg>
                </div>
                <small>Cuidado del hogar</small>
            </div>
            <div class="brand">
                <div class="brand-logo">
                    <svg viewBox="0 0 140 40" xmlns="http://www.w3.org/2000/svg"><text x="70" y="28" text-anchor="middle" font-family="Arial, sans-serif" font-size="14" font-weight="900" fill="#005baa" letter-spacing="1">FRIGIDAIRE</text></svg>
                </div>
                <small>Refrigeración</small>
            </div>
            <div class="brand">
                <div class="brand-logo">
                    <svg viewBox="0 0 120 40" xmlns="http://www.w3.org/2000/svg"><text x="60" y="28" text-anchor="middle" font-family="Georgia, serif" font-size="22" font-weight="700" fill="#e31837" font-style="italic">Oster</text></svg>
                </div>
                <small>Pequeños electrodomésticos</small>
            </div>
        </div>
    </div>
</section>

<section class="section" id="ofertas">
    <div class="container">
        <div class="promotion">
            <div>
                <span class="section-label">Promociones del mes</span>
                <h2>Descuentos vigentes en electrodomésticos seleccionados</h2>
                <p>Aprovecha nuestras ofertas del mes para equipar tu hogar con equipos de calidad a precios especiales.</p>
                <a href="productos.php" class="btn-primary">Ver productos →</a>
            </div>
            <div class="offers">
                <article class="offer">
                    <strong>15% OFF</strong>
                    <span>Refrigeradoras seleccionadas</span>
                </article>
                <article class="offer">
                    <strong>10% OFF</strong>
                    <span>Lavadoras seleccionadas</span>
                </article>
                <article class="offer">
                    <strong>12% OFF</strong>
                    <span>Televisores Smart TV</span>
                </article>
            </div>
        </div>
    </div>
</section>

<section class="section section-white" id="beneficios">
    <div class="container">
        <div class="section-header">
            <span class="section-label">¿Por qué elegirnos?</span>
            <h2>Beneficios de comprar en Santa Ana</h2>
            <p>Te brindamos una experiencia de compra confiable, con respaldo y calidad garantizada.</p>
        </div>
        <div class="benefits">
            <article class="benefit">
                <div class="benefit-icon">🚚</div>
                <h3>Entrega a domicilio</h3>
                <p>Recibe tu electrodoméstico directamente en tu hogar.</p>
            </article>
            <article class="benefit">
                <div class="benefit-icon">🛡️</div>
                <h3>Garantía y respaldo</h3>
                <p>Todos los productos cuentan con garantía del fabricante.</p>
            </article>
            <article class="benefit">
                <div class="benefit-icon">💳</div>
                <h3>Facilidades de pago</h3>
                <p>Opciones de pago flexibles para tu comodidad.</p>
            </article>
            <article class="benefit">
                <div class="benefit-icon">⭐</div>
                <h3>Atención personalizada</h3>
                <p>Te asesoramos para que elijas el equipo ideal para ti.</p>
            </article>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="section-header">
            <span class="section-label">Cómo comprar</span>
            <h2>Tu electrodoméstico en tres pasos</h2>
            <p>Comprar en Electrodomésticos Santa Ana es fácil, seguro y conveniente.</p>
        </div>
        <div class="steps">
            <article class="step">
                <span class="step-number">01</span>
                <div class="step-icon">🛒</div>
                <h3>Elige tu producto</h3>
                <p>Revisa nuestras categorías y modelos para encontrar el electrodoméstico ideal para tu hogar.</p>
            </article>
            <article class="step">
                <span class="step-number">02</span>
                <div class="step-icon">🔍</div>
                <h3>Consulta disponibilidad</h3>
                <p>Confirma las existencias, marcas y promociones vigentes antes de realizar tu compra.</p>
            </article>
            <article class="step">
                <span class="step-number">03</span>
                <div class="step-icon">🚚</div>
                <h3>Recíbelo en casa</h3>
                <p>Coordinamos la entrega de tu nuevo electrodoméstico directamente en tu hogar.</p>
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

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const track = document.querySelector('.hero-carousel-track');
        const slides = document.querySelectorAll('.hero-product');
        const dots = document.querySelectorAll('.carousel-dot');
        const prevBtn = document.querySelector('.carousel-btn.prev');
        const nextBtn = document.querySelector('.carousel-btn.next');
        let current = 0;

        function goTo(index) {
            current = (index + slides.length) % slides.length;
            track.style.transform = `translateX(-${current * 100}%)`;
            dots.forEach((d, i) => d.classList.toggle('active', i === current));
        }

        prevBtn.addEventListener('click', () => goTo(current - 1));
        nextBtn.addEventListener('click', () => goTo(current + 1));
        dots.forEach((d, i) => d.addEventListener('click', () => goTo(i)));

        setInterval(() => goTo(current + 1), 5000);
    });
</script>
</body>
</html>
