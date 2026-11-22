<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

$cu = current_user();
$loggedIn = $cu !== null;
$sessionName = $cu ? (string)($cu['full_name'] ?: $cu['username']) : 'Pengguna';

$devCount = (int)db()->query('SELECT COUNT(*) FROM devices WHERE is_active = 1')->fetchColumn();
$staleMin = max(1, setting_int('data_stale_minutes', 10));
$onlineStmt = db()->prepare("SELECT COUNT(*) FROM devices WHERE is_active = 1 AND status = 'online' AND last_communication >= DATE_SUB(NOW(), INTERVAL ? MINUTE)");
$onlineStmt->execute([$staleMin]);
$activeCount = (int)$onlineStmt->fetchColumn();
$measureCount = (int)db()->query('SELECT COUNT(*) FROM water_measurements')->fetchColumn();

$lpMenu = [
    ['Beranda', 'index.php'],
    ['Dashboard', 'dashboard.php'],
    ['Kualitas Air', 'kualitas-air.php'],
    ['FishCount AI', 'fishcount.php'],
    ['WebGIS', 'webgis.php'],
    ['Batimetri', 'batimetri.php'],
    ['Lokasi KJA', 'lokasi-kja.php'],
    ['Prediksi AI', 'prediksi-ai.php'],
    ['Tim Kami', '#tim'],
];
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(APP_NAME) ?> — Monitoring IoT KJA &amp; Energi Angin</title>
<meta name="description" content="BOLU — monitoring suhu, pH, tegangan, klasifikasi spesies ikan EfficientNetV2, WebGIS, batimetri dan prakiraan angin BMKG untuk KJA.">
<link rel="icon" type="image/png" href="assets/images/favicon.png?v=2">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="assets/css/landing.css?v=20261122-2">
<script>try{if(!matchMedia('(prefers-reduced-motion: reduce)').matches)document.documentElement.classList.add('js-anim')}catch(e){}</script>
</head>
<body class="lp">
<nav class="lp-nav" aria-label="Navigasi utama">
    <div class="container">
        <a class="brand" href="index.php">
            <img src="assets/images/logo-mark-black.png?v=20261118-beranda" alt="Logo BOLU">
            <span><span class="brand-name">BOLU</span><span class="brand-sub">Blue Ocean Life Utility</span></span>
        </a>
        <button type="button" class="hamburger" id="lpHamburger" aria-label="Buka menu navigasi"><i class="fa-solid fa-bars"></i></button>
        <div class="lp-menu" id="lpMenu">
            <?php foreach ($lpMenu as $m): ?>
                <a href="<?= e($m[1]) ?>" class="<?= $m[1] === 'index.php' ? 'active' : '' ?>"><?= e($m[0]) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="lp-nav-cta">
            <?php if ($loggedIn): ?>
                <a class="btn-masuk" href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> <span>Buka Dashboard</span></a>
                <span class="lp-avatar" title="<?= e($sessionName) ?>"><?= e(strtoupper(substr($sessionName, 0, 1))) ?></span>
            <?php else: ?>
                <a class="btn-masuk" href="login.php"><i class="fa-solid fa-right-to-bracket"></i> <span>Masuk</span></a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<header class="hero">
    <div class="hero-bg" role="img" aria-label="Foto pantai, dermaga dan kapal di laut"></div>
    <div class="hero-veil"></div>
    <div class="container hero-inner">
        <span class="loc-chip"><i class="fa-solid fa-location-dot"></i> Pemantauan KJA &bull; Pesisir Banten</span>
        <h1>BOLU<span class="glow">.</span></h1>
        <p class="hero-tagline">Recognize, Monitor, and Protect Our Ocean</p>
        <p class="hero-desc">
            BOLU (Blue Ocean Life Utility) memadukan monitoring IoT <strong>suhu air, pH, dan tegangan listrik</strong>,
            klasifikasi empat spesies ikan dengan <strong>EfficientNetV2-S</strong>, WebGIS,
            peta batimetri BETRI/GEBCO, rekomendasi lokasi KJA Banten, serta prakiraan angin BMKG.
        </p>
        <div class="hero-actions">
            <a class="btn-primary" href="dashboard.php"><i class="fa-solid fa-chart-line"></i> Jelajahi Dashboard</a>
            <a class="btn-ghost" href="webgis.php"><i class="fa-solid fa-map"></i> Lihat Peta WebGIS</a>
        </div>
    </div>
</header>

<section class="stats-wrap" aria-label="Statistik ringkas">
    <div class="container stats-grid">
        <div class="stat-card reveal"><span class="stat-ico"><i class="fa-solid fa-microchip"></i></span><div class="stat-val"><?= fmt_num($devCount, 0) ?><small>/<?= fmt_num($activeCount, 0) ?> online</small></div><div class="stat-lbl">Perangkat IoT terdaftar / online</div></div>
        <div class="stat-card reveal"><span class="stat-ico"><i class="fa-solid fa-database"></i></span><div class="stat-val"><?= fmt_num($measureCount, 0) ?><small>rekaman</small></div><div class="stat-lbl">Rekaman pengukuran kualitas air</div></div>
        <div class="stat-card reveal"><span class="stat-ico"><i class="fa-solid fa-temperature-half"></i></span><div class="stat-val">3<small>parameter</small></div><div class="stat-lbl">Suhu air, pH, dan tegangan</div></div>
        <div class="stat-card reveal"><span class="stat-ico"><i class="fa-solid fa-users"></i></span><div class="stat-val">4<small>anggota</small></div><div class="stat-lbl">Tim pengembang BOLU</div></div>
    </div>
</section>

<footer class="lp-footer">
    <div class="container foot-row">
        <a class="foot-brand" href="index.php"><img src="assets/images/logo-mark-black.png?v=20261118-footer" alt="Logo BOLU"><span><strong>BOLU</strong><span>Blue Ocean Life Utility</span></span></a>
        <nav class="foot-links"><a href="dashboard.php">Dashboard</a><a href="webgis.php">WebGIS</a><a href="penjelasan-kja.php">Edukasi</a><a href="login.php">Masuk</a></nav>
        <span class="foot-copy">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. Seluruh hak dilindungi.</span>
    </div>
</footer>
<script src="assets/js/embed-helper.js?v=1"></script>
</body>
</html>
