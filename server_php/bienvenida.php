<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>SUPER_IA — Bienvenido</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />

  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --navy:       #0B1929;
      --navy-2:     #0F2440;
      --navy-3:     #163057;
      --navy-card:  #112035;
      --yellow:     #FFC800;
      --yellow-lt:  #FFD94D;
      --yellow-dk:  #E6A800;
      --slate:      #8B99AE;
      --slate-lt:   #B8C4D4;
      --slate-dk:   #4A5568;
      --white:      #FFFFFF;
      --border:     rgba(255,255,255,0.07);
      --border-y:   rgba(255,200,0,0.25);
      --border-b:   rgba(22,48,87,0.8);
    }

    html { scroll-behavior: smooth; }

    body {
      font-family: 'Inter', sans-serif;
      background: var(--navy);
      color: var(--white);
      min-height: 100vh;
      overflow-x: hidden;
    }

    /* ── FONDO CON CÍRCULOS (estilo auth) ────────────────── */
    .page-bg {
      position: fixed; inset: 0; z-index: 0; pointer-events: none;
      background: linear-gradient(160deg, #0B1929 0%, #0F2440 50%, #0A1520 100%);
      overflow: hidden;
    }
    .bg-circle {
      position: absolute; border-radius: 50%;
      background: radial-gradient(circle, var(--col) 0%, transparent 70%);
      filter: blur(1px);
    }
    .bg-circle-1 {
      --col: rgba(255,200,0,0.12);
      width: 600px; height: 600px;
      top: -200px; right: -150px;
    }
    .bg-circle-2 {
      --col: rgba(22,48,87,0.9);
      width: 500px; height: 500px;
      bottom: -180px; left: -120px;
    }
    .bg-circle-3 {
      --col: rgba(255,200,0,0.06);
      width: 350px; height: 350px;
      top: 50%; left: 50%;
      transform: translate(-50%, -50%);
    }
    .grid-lines {
      position: fixed; inset: 0; z-index: 0; pointer-events: none;
      background-image:
        linear-gradient(rgba(255,200,0,.025) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,200,0,.025) 1px, transparent 1px);
      background-size: 52px 52px;
    }

    /* ── PÁGINA ──────────────────────────────────────────── */
    .page {
      position: relative; z-index: 1;
      max-width: 1080px;
      margin: 0 auto;
      padding: 0 28px 80px;
    }

    /* ── NAVBAR ──────────────────────────────────────────── */
    .navbar {
      display: flex; align-items: center; justify-content: space-between;
      padding: 28px 0 48px;
      border-bottom: 1px solid var(--border);
      margin-bottom: 72px;
    }
    .nav-brand {
      display: flex; align-items: center; gap: 14px;
    }
    .nav-icon {
      width: 44px; height: 44px; border-radius: 14px;
      background: linear-gradient(135deg, var(--yellow), var(--yellow-dk));
      display: grid; place-items: center;
      box-shadow: 0 4px 20px rgba(255,200,0,.35);
      font-size: 20px; flex-shrink: 0;
    }
    .nav-name {
      font-size: 20px; font-weight: 900; letter-spacing: -.5px;
      color: var(--white);
    }
    .nav-name span { color: var(--yellow); }
    .nav-pills {
      display: flex; gap: 10px; align-items: center;
    }
    .pill {
      font-size: 11px; font-weight: 600;
      padding: 5px 14px; border-radius: 20px;
      letter-spacing: .3px;
    }
    .pill-version {
      background: rgba(255,200,0,.1);
      border: 1px solid var(--border-y);
      color: var(--yellow);
    }
    .pill-status {
      background: rgba(0,230,118,.08);
      border: 1px solid rgba(0,230,118,.25);
      color: #00E676;
      display: flex; align-items: center; gap: 6px;
    }
    .pill-status .dot {
      width: 6px; height: 6px; border-radius: 50%; background: #00E676;
      animation: blink 1.8s ease-in-out infinite;
    }
    @keyframes blink { 0%,100%{opacity:1} 50%{opacity:.3} }

    /* ── HERO ────────────────────────────────────────────── */
    .hero {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 56px;
      align-items: center;
      margin-bottom: 96px;
    }
    .hero-left {}
    .hero-eyebrow {
      display: inline-block;
      font-size: 11px; font-weight: 700; letter-spacing: 2.5px;
      text-transform: uppercase; color: var(--yellow);
      margin-bottom: 20px;
    }
    .hero-h1 {
      font-size: clamp(2rem, 4vw, 3.2rem);
      font-weight: 900; line-height: 1.1; letter-spacing: -1.5px;
      margin-bottom: 22px;
    }
    .hero-h1 em {
      font-style: normal; color: var(--yellow);
    }
    .hero-p {
      font-size: 1rem; color: var(--slate); line-height: 1.8;
      margin-bottom: 36px;
    }
    .btn-group { display: flex; gap: 12px; flex-wrap: wrap; }

    /* Botones */
    .btn {
      display: inline-flex; align-items: center; gap: 10px;
      padding: 14px 24px; border-radius: 14px;
      font-size: 14px; font-weight: 700;
      text-decoration: none; border: none; cursor: pointer;
      transition: transform .14s ease, box-shadow .14s ease;
      white-space: nowrap;
    }
    .btn:hover  { transform: translateY(-2px); }
    .btn:active { transform: scale(.97); }

    .btn-yellow {
      background: linear-gradient(135deg, var(--yellow), var(--yellow-dk));
      color: var(--navy);
      box-shadow: 0 6px 24px rgba(255,200,0,.35);
    }
    .btn-yellow:hover { box-shadow: 0 10px 32px rgba(255,200,0,.5); }

    .btn-outline {
      background: transparent;
      color: var(--slate-lt);
      border: 1px solid rgba(255,255,255,.15);
      box-shadow: none;
    }
    .btn-outline:hover {
      background: rgba(255,255,255,.05);
      border-color: rgba(255,255,255,.25);
      color: var(--white);
    }
    .btn i { font-size: 15px; }

    /* HERO derecha: tarjeta stat */
    .hero-right {
      display: flex; flex-direction: column; gap: 14px;
    }
    .stat-card {
      background: var(--navy-card);
      border: 1px solid var(--border-b);
      border-radius: 20px;
      padding: 22px 24px;
      display: flex; align-items: center; gap: 18px;
      transition: border-color .2s, transform .2s;
    }
    .stat-card:hover {
      border-color: rgba(255,200,0,.2);
      transform: translateX(4px);
    }
    .stat-icon {
      width: 50px; height: 50px; border-radius: 14px;
      display: grid; place-items: center; font-size: 22px; flex-shrink: 0;
    }
    .stat-icon.y  { background: rgba(255,200,0,.12); color: var(--yellow); }
    .stat-icon.b  { background: rgba(22,48,87,.6);   color: #60A5FA; }
    .stat-icon.g  { background: rgba(0,230,118,.10); color: #00E676; }
    .stat-icon.s  { background: rgba(139,153,174,.1);color: var(--slate-lt); }
    .stat-body h4  { font-size: 14px; font-weight: 700; margin-bottom: 3px; }
    .stat-body p   { font-size: 12px; color: var(--slate); line-height: 1.5; }
    .stat-arrow { margin-left: auto; color: var(--slate-dk); font-size: 13px; }

    /* ── SEPARADOR ───────────────────────────────────────── */
    .divider {
      display: flex; align-items: center; gap: 16px;
      margin: 64px 0;
    }
    .divider::before, .divider::after {
      content:''; flex:1; height:1px;
      background: linear-gradient(to right, transparent, var(--border-b));
    }
    .divider::after { background: linear-gradient(to left, transparent, var(--border-b)); }
    .divider-label {
      font-size: 11px; font-weight: 700; letter-spacing: 2px;
      text-transform: uppercase; color: var(--slate-dk);
      white-space: nowrap;
    }

    /* ── SECCIÓN ─────────────────────────────────────────── */
    .section { margin-bottom: 72px; }
    .section-header { text-align: center; margin-bottom: 40px; }
    .section-tag {
      display: inline-block;
      font-size: 11px; font-weight: 700; letter-spacing: 2px;
      text-transform: uppercase; color: var(--yellow);
      margin-bottom: 12px;
    }
    .section-title {
      font-size: clamp(1.5rem, 3vw, 2rem);
      font-weight: 800; letter-spacing: -.5px;
    }
    .section-sub {
      font-size: .95rem; color: var(--slate); margin-top: 10px; line-height: 1.7;
    }

    /* ── ACRÓNIMO ────────────────────────────────────────── */
    .acronym-wrap {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }
    .acr-row {
      background: var(--navy-card);
      border: 1px solid var(--border-b);
      border-radius: 14px;
      padding: 16px 20px;
      display: flex; align-items: center; gap: 14px;
      transition: border-color .2s, background .2s;
    }
    .acr-row:hover {
      border-color: var(--border-y);
      background: rgba(255,200,0,.04);
    }
    .acr-letter {
      font-size: 2rem; font-weight: 900;
      color: var(--yellow); line-height: 1; width: 36px; text-align: center; flex-shrink: 0;
    }
    .acr-letter.underscore { font-size: 1.8rem; color: var(--slate-dk); }
    .acr-word { font-size: .9rem; color: var(--slate-lt); line-height: 1.4; }
    .acr-word strong { color: var(--white); font-weight: 700; display: block; font-size: .95rem; }

    /* ── DESCRIPCIÓN ─────────────────────────────────────── */
    .desc-card {
      background: var(--navy-card);
      border: 1px solid var(--border-b);
      border-left: 3px solid var(--yellow);
      border-radius: 20px;
      padding: 36px 40px;
    }
    .desc-card p {
      font-size: 1rem; color: var(--slate); line-height: 1.85;
    }
    .desc-card p + p { margin-top: 18px; }
    .desc-card strong { color: var(--white); font-weight: 600; }
    .desc-card em { font-style: normal; color: var(--yellow); }

    /* ── FEATURES GRID ───────────────────────────────────── */
    .features-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
      gap: 14px;
    }
    .feat-card {
      background: var(--navy-card);
      border: 1px solid var(--border-b);
      border-radius: 18px;
      padding: 26px 22px;
      transition: border-color .2s, transform .2s, box-shadow .2s;
      position: relative; overflow: hidden;
    }
    .feat-card::before {
      content: '';
      position: absolute; top: 0; left: 0; right: 0; height: 2px;
      background: linear-gradient(90deg, transparent, var(--col, var(--yellow)), transparent);
      opacity: 0; transition: opacity .2s;
    }
    .feat-card:hover {
      border-color: rgba(255,200,0,.2);
      transform: translateY(-4px);
      box-shadow: 0 12px 32px rgba(0,0,0,.3);
    }
    .feat-card:hover::before { opacity: 1; }

    .feat-icon {
      width: 48px; height: 48px; border-radius: 14px;
      display: grid; place-items: center;
      font-size: 21px; margin-bottom: 16px;
    }
    .feat-icon.y  { background: rgba(255,200,0,.12); color: var(--yellow); }
    .feat-icon.b  { background: rgba(96,165,250,.10); color: #60A5FA; }
    .feat-icon.g  { background: rgba(0,230,118,.08);  color: #00E676; }
    .feat-icon.s  { background: rgba(139,153,174,.1); color: var(--slate-lt); }
    .feat-card h3  { font-size: 14px; font-weight: 700; margin-bottom: 8px; }
    .feat-card p   { font-size: 12.5px; color: var(--slate); line-height: 1.65; }

    /* ── CTA FINAL ───────────────────────────────────────── */
    .cta-box {
      background: linear-gradient(135deg, var(--navy-3) 0%, var(--navy-card) 100%);
      border: 1px solid var(--border-y);
      border-radius: 28px;
      padding: 60px 48px;
      text-align: center;
      position: relative; overflow: hidden;
    }
    .cta-box::before {
      content: '';
      position: absolute; top: -100px; left: 50%;
      transform: translateX(-50%);
      width: 400px; height: 400px; border-radius: 50%;
      background: radial-gradient(circle, rgba(255,200,0,.12) 0%, transparent 70%);
      pointer-events: none;
    }
    .cta-box h2 {
      font-size: clamp(1.6rem, 3vw, 2.2rem);
      font-weight: 900; letter-spacing: -.5px;
      margin-bottom: 14px;
    }
    .cta-box h2 em { font-style: normal; color: var(--yellow); }
    .cta-box p { color: var(--slate); font-size: .95rem; margin-bottom: 36px; line-height: 1.7; }
    .cta-box .btn-group { justify-content: center; }

    .btn-yellow-lg {
      padding: 17px 32px;
      font-size: 15px;
      border-radius: 16px;
    }
    .btn-outline-lg {
      padding: 17px 32px;
      font-size: 15px;
      border-radius: 16px;
    }

    /* ── FOOTER ──────────────────────────────────────────── */
    footer {
      text-align: center; margin-top: 64px; padding-top: 32px;
      border-top: 1px solid var(--border);
    }
    .footer-logo {
      display: inline-flex; align-items: center; gap: 10px;
      margin-bottom: 16px;
    }
    .footer-logo-icon {
      width: 32px; height: 32px; border-radius: 10px;
      background: linear-gradient(135deg, var(--yellow), var(--yellow-dk));
      display: grid; place-items: center; font-size: 15px;
    }
    .footer-logo-text { font-size: 15px; font-weight: 800; color: var(--white); }
    footer p { font-size: 12px; color: var(--slate-dk); line-height: 1.9; }

    /* ── ANIMACIONES ─────────────────────────────────────── */
    .fade-up {
      opacity: 0; transform: translateY(22px);
      animation: fadeUp .65s ease forwards;
    }
    @keyframes fadeUp { to { opacity:1; transform:translateY(0); } }

    .fade-up:nth-child(1) { animation-delay: .05s; }
    .fade-up:nth-child(2) { animation-delay: .15s; }
    .fade-up:nth-child(3) { animation-delay: .25s; }
    .fade-up:nth-child(4) { animation-delay: .35s; }
    .fade-up:nth-child(5) { animation-delay: .45s; }

    .reveal {
      opacity: 0; transform: translateY(20px);
      transition: opacity .65s ease, transform .65s ease;
    }
    .reveal.visible { opacity: 1; transform: translateY(0); }

    /* ── RESPONSIVE ──────────────────────────────────────── */
    @media (max-width: 768px) {
      .hero { grid-template-columns: 1fr; gap: 40px; }
      .hero-right { display: none; }
      .btn-group { flex-direction: column; }
      .btn { width: 100%; justify-content: center; }
      .acronym-wrap { grid-template-columns: 1fr; }
      .cta-box { padding: 40px 24px; }
      .cta-box .btn-group { flex-direction: column; align-items: center; }
      .desc-card { padding: 24px 22px; }
      .navbar { flex-wrap: wrap; gap: 12px; }
    }
  </style>
</head>
<body>

<!-- Fondo -->
<div class="page-bg">
  <div class="bg-circle bg-circle-1"></div>
  <div class="bg-circle bg-circle-2"></div>
  <div class="bg-circle bg-circle-3"></div>
</div>
<div class="grid-lines"></div>

<div class="page">

  <!-- ── NAVBAR ─────────────────────────────────────────── -->
  <nav class="navbar fade-up">
    <div class="nav-brand">
      <div class="nav-icon">🛰️</div>
      <span class="nav-name">SUPER<span>_IA</span></span>
    </div>
    <div class="nav-pills">
      <span class="pill pill-status"><span class="dot"></span> Activo</span>
      <span class="pill pill-version">v1.0 · 2026</span>
    </div>
  </nav>

  <!-- ── HERO ───────────────────────────────────────────── -->
  <section class="hero">
    <div class="hero-left">
      <div class="fade-up">
        <span class="hero-eyebrow">&#9654; Plataforma de Monitoreo y Gestión</span>
      </div>
      <div class="fade-up">
        <h1 class="hero-h1">
          Controla tu equipo<br>comercial con<br>
          <em>Inteligencia Artificial</em>
        </h1>
      </div>
      <div class="fade-up">
        <p class="hero-p">
          <strong>SUPER_IA</strong> centraliza el rastreo GPS de asesores,
          encuestas comerciales, gestión crediticia y alertas en tiempo real,
          todo desde un único panel web y app móvil.
        </p>
      </div>
      <div class="fade-up">
        <div class="btn-group">
          <a href="./admin/login_selector.php" class="btn btn-yellow btn-yellow-lg">
            <i class="fa-solid fa-users-gear"></i>
            Seleccionar Rol
          </a>
          <a href="./admin/login.php" class="btn btn-outline btn-outline-lg">
            <i class="fa-solid fa-right-to-bracket"></i>
            Iniciar Sesión
          </a>
        </div>
      </div>
    </div>

    <!-- Columna derecha: tarjetas de capacidades -->
    <div class="hero-right fade-up">
      <div class="stat-card">
        <div class="stat-icon y"><i class="fa-solid fa-location-dot"></i></div>
        <div class="stat-body">
          <h4>Rastreo GPS en vivo</h4>
          <p>Ubica cada asesor en el mapa en tiempo real.</p>
        </div>
        <i class="fa-solid fa-chevron-right stat-arrow"></i>
      </div>
      <div class="stat-card">
        <div class="stat-icon b"><i class="fa-solid fa-clipboard-list"></i></div>
        <div class="stat-body">
          <h4>Encuestas y Prospectos</h4>
          <p>Levantamiento comercial desde la app móvil.</p>
        </div>
        <i class="fa-solid fa-chevron-right stat-arrow"></i>
      </div>
      <div class="stat-card">
        <div class="stat-icon g"><i class="fa-solid fa-building-columns"></i></div>
        <div class="stat-body">
          <h4>Gestión Crediticia</h4>
          <p>Solicitudes de crédito con cooperativas.</p>
        </div>
        <i class="fa-solid fa-chevron-right stat-arrow"></i>
      </div>
      <div class="stat-card">
        <div class="stat-icon s"><i class="fa-solid fa-bell"></i></div>
        <div class="stat-body">
          <h4>Alertas Inteligentes</h4>
          <p>Notificaciones push automáticas por eventos.</p>
        </div>
        <i class="fa-solid fa-chevron-right stat-arrow"></i>
      </div>
    </div>
  </section>

  <!-- ── ACRÓNIMO ───────────────────────────────────────── -->
  <div class="divider reveal"><span class="divider-label">Significado del nombre</span></div>

  <section class="section reveal">
    <div class="section-header">
      <span class="section-tag">&#9670; ¿Qué significa?</span>
      <h2 class="section-title">Detrás de <strong style="color:var(--yellow)">SUPER_IA</strong></h2>
      <p class="section-sub">Cada letra representa un pilar fundamental del sistema.</p>
    </div>
    <div class="acronym-wrap">
      <div class="acr-row">
        <span class="acr-letter">S</span>
        <div class="acr-word"><strong>Sistema</strong>conjunto integrado de módulos y servicios</div>
      </div>
      <div class="acr-row">
        <span class="acr-letter">U</span>
        <div class="acr-word"><strong>Unificado</strong>todo en una sola plataforma centralizada</div>
      </div>
      <div class="acr-row">
        <span class="acr-letter">P</span>
        <div class="acr-word"><strong>Prospección</strong>identificación de nuevos clientes y empresas</div>
      </div>
      <div class="acr-row">
        <span class="acr-letter">E</span>
        <div class="acr-word"><strong>Evaluación</strong>análisis crediticio y comercial en tiempo real</div>
      </div>
      <div class="acr-row">
        <span class="acr-letter">R</span>
        <div class="acr-word"><strong>Rastreo</strong>monitoreo GPS continuo de asesores en campo</div>
      </div>
      <div class="acr-row">
        <span class="acr-letter underscore">_</span>
        <div class="acr-word"><strong style="color:var(--slate-dk)">Separador</strong>distinción entre sistema y tecnología</div>
      </div>
      <div class="acr-row">
        <span class="acr-letter">I</span>
        <div class="acr-word"><strong>Inteligencia</strong>procesamiento automático e inteligente de datos</div>
      </div>
      <div class="acr-row">
        <span class="acr-letter">A</span>
        <div class="acr-word"><strong>Artificial</strong>algoritmos y automatización basada en IA</div>
      </div>
    </div>
  </section>

  <!-- ── DESCRIPCIÓN ────────────────────────────────────── -->
  <div class="divider reveal"><span class="divider-label">Sobre el proyecto</span></div>

  <section class="section reveal">
    <div class="section-header">
      <span class="section-tag">&#9670; ¿De qué trata?</span>
      <h2 class="section-title">Un sistema pensado para el <em style="font-style:normal;color:var(--yellow)">campo</em></h2>
    </div>
    <div class="desc-card">
      <p>
        <strong>SUPER_IA</strong> nació para resolver un problema real: la <strong>falta de visibilidad y control</strong>
        sobre la actividad de los asesores comerciales que trabajan fuera de la oficina.
        Con esta plataforma, supervisores y administradores pueden ver <em>en tiempo real</em> dónde está
        cada asesor, qué encuestas completó, qué prospectos levantó y cuáles son sus métricas del día.
      </p>
      <p>
        La plataforma conecta una <strong>app móvil Flutter</strong> (para asesores en campo) con un
        <strong>panel web PHP + MySQL</strong> (para supervisores y administradores), respaldado por
        <strong>Firebase</strong> para autenticación y notificaciones push.
        Todo el ecosistema trabaja en sincronía para garantizar <em>gestión comercial</em> y
        <em>crédito cooperativo</em> eficientes, seguros y trazables.
      </p>
    </div>
  </section>

  <!-- ── FUNCIONALIDADES ────────────────────────────────── -->
  <div class="divider reveal"><span class="divider-label">Funcionalidades</span></div>

  <section class="section reveal">
    <div class="section-header">
      <span class="section-tag">&#9670; ¿Qué puedes hacer?</span>
      <h2 class="section-title">Todo lo que necesitas, en un solo lugar</h2>
    </div>
    <div class="features-grid">
      <div class="feat-card">
        <div class="feat-icon y"><i class="fa-solid fa-location-dot"></i></div>
        <h3>Rastreo GPS en vivo</h3>
        <p>Monitorea la ubicación exacta de cada asesor comercial sobre el mapa en tiempo real.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon b"><i class="fa-solid fa-clipboard-check"></i></div>
        <h3>Encuestas Comerciales</h3>
        <p>Levanta prospectos, evalúa empresas y registra encuestas directamente desde la app.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon g"><i class="fa-solid fa-building-columns"></i></div>
        <h3>Gestión Crediticia</h3>
        <p>Administra solicitudes de crédito integradas con cooperativas financieras locales.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon s"><i class="fa-solid fa-bell"></i></div>
        <h3>Alertas Inteligentes</h3>
        <p>Notificaciones push automáticas ante eventos críticos o actividades fuera de ruta.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon y"><i class="fa-solid fa-users-gear"></i></div>
        <h3>Gestión de Roles</h3>
        <p>Paneles diferenciados para Asesor, Supervisor, Administrador y Super Admin.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon b"><i class="fa-solid fa-chart-line"></i></div>
        <h3>Reportes y Métricas</h3>
        <p>Dashboards con estadísticas de rendimiento, metas diarias y seguimiento de actividad.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon g"><i class="fa-solid fa-mobile-screen-button"></i></div>
        <h3>App Móvil Flutter</h3>
        <p>Aplicación nativa Android para asesores con mapas, chat y gestión de visitas.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon s"><i class="fa-solid fa-shield-halved"></i></div>
        <h3>Seguridad y Sesiones</h3>
        <p>Autenticación segura con Firebase, OTP por correo y sesiones persistentes cifradas.</p>
      </div>
    </div>
  </section>

  <!-- ── CTA FINAL ──────────────────────────────────────── -->
  <div class="cta-box reveal">
    <h2>¿Listo para <em>comenzar</em>?</h2>
    <p>
      Selecciona tu rol de acceso o inicia sesión directamente.<br>
      El sistema está activo y listo para ti.
    </p>
    <div class="btn-group">
      <a href="./admin/login_selector.php" class="btn btn-yellow btn-yellow-lg">
        <i class="fa-solid fa-users-gear"></i>
        Seleccionar Rol
      </a>
      <a href="./admin/login.php" class="btn btn-outline btn-outline-lg">
        <i class="fa-solid fa-right-to-bracket"></i>
        Iniciar Sesión
      </a>
    </div>
  </div>

  <!-- ── FOOTER ─────────────────────────────────────────── -->
  <footer class="reveal">
    <div class="footer-logo">
      <div class="footer-logo-icon">🛰️</div>
      <span class="footer-logo-text">SUPER_IA</span>
    </div>
    <p>© 2026 · Sistema Unificado de Prospección, Evaluación y Rastreo con IA</p>
    <p>Plataforma de Monitoreo y Gestión Comercial y Crediticia Inteligente</p>
  </footer>

</div><!-- /page -->

<script>
  const io = new IntersectionObserver(
    entries => entries.forEach(e => {
      if (e.isIntersecting) { e.target.classList.add('visible'); io.unobserve(e.target); }
    }),
    { threshold: 0.1 }
  );
  document.querySelectorAll('.reveal').forEach(el => io.observe(el));
</script>
</body>
</html>
