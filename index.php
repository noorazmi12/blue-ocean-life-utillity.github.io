<?php
/**
 * BOLU — Landing page publik (tampil SEBELUM login)
 * Struktur: navigasi atas, hero bawah laut, statistik ringkas,
 * dan ringkasan fitur monitoring.
 *
 * Semua angka statistik dihitung dari database (bukan angka rekaan);
 * bila database belum siap, tampil "—". Halaman ini publik: tidak memaksa login,
 * namun setiap tautan menu aplikasi akan diarahkan ke login.php oleh require_login().
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

$loggedIn = (bool)current_user();

/* Statistik publik: hanya perangkat dan pengukuran kualitas air yang dipakai. */
$stats = ['devices' => null, 'active' => null, 'measure' => null];
try {
    $r = db()->query("SELECT COUNT(*) AS total, SUM(CASE WHEN status='online' THEN 1 ELSE 0 END) AS aktif FROM devices WHERE is_active = 1")->fetch();
    if ($r) {
        $stats['devices'] = (int)$r['total'];
        $stats['active'] = (int)($r['aktif'] ?? 0);
    }
    $r = db()->query("SELECT COUNT(*) AS total FROM water_measurements")->fetch();
    if ($r) $stats['measure'] = (int)$r['total'];
} catch (Throwable $t) {
    /* Saat database belum siap, jangan mengarang nilai. */
}

function lp_num($v): string
{
    return $v === null ? '—' : number_format((float)$v, 0, ',', '.');
}

/* Menu: [label, target] — target dijaga format .php agar aman */
$lpMenu = [
    ['Beranda', 'index.php', 'fa-house'],
    ['Dashboard', 'dashboard.php', 'fa-gauge-high'],
    ['Kualitas Air', 'kualitas-air.php', 'fa-droplet'],
    ['FishCount AI', 'fishcount.php', 'fa-fish'],
    ['WebGIS', 'webgis.php', 'fa-map-location-dot'],
    ['Batimetri', 'batimetri.php', 'fa-mountain'],
    ['Lokasi KJA', 'lokasi-kja.php', 'fa-anchor'],
    ['Prediksi AI', 'prediksi-ai.php', 'fa-brain'],
    ['Tim Kami', '#tim', 'fa-users'],
];
?>
<!doctype html>
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

<!-- ===== Navigasi ===== -->
<nav class="lp-nav" aria-label="Navigasi utama">
    <div class="container">
        <a class="brand" href="index.php">
            <img src="assets/images/logo-mark-black.png?v=20261118-beranda" alt="Logo BOLU">
            <span>
                <span class="brand-name">BOLU</span>
                <span class="brand-sub">Blue Ocean Life Utility</span>
            </span>
        </a>

        <button type="button" class="hamburger" id="lpHamburger" aria-label="Buka menu navigasi">
            <i class="fa-solid fa-bars"></i>
        </button>

        <div class="lp-menu" id="lpMenu">
            <?php foreach ($lpMenu as $m): ?>
                <a href="<?= e($m[1]) ?>" class="<?= $m[1] === 'index.php' ? 'active' : '' ?>"><?= e($m[0]) ?></a>
            <?php endforeach; ?>
        </div>

        <div class="lp-nav-cta">
            <?php if ($loggedIn): ?>
                <a class="btn-masuk" href="dashboard.php">
                    <i class="fa-solid fa-gauge-high"></i> <span>Buka Dashboard</span>
                </a>
                <span class="lp-avatar" title="<?= e($_SESSION['name'] ?? 'Pengguna') ?>">
                    <?= e(strtoupper(mb_substr($_SESSION['name'] ?? 'U', 0, 1))) ?>
                </span>
            <?php else: ?>
                <a class="btn-masuk" href="login.php">
                    <i class="fa-solid fa-right-to-bracket"></i> <span>Masuk</span>
                </a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<!-- ===== Hero ===== -->
<header class="hero">
    <div class="hero-bg" role="img" aria-label="Foto pantai, dermaga dan kapal di laut"></div>
    <div class="hero-veil"></div>
    <div class="container hero-inner">
        <span class="loc-chip">
            <i class="fa-solid fa-location-dot"></i>
            Pemantauan KJA &bull; Pesisir Banten
        </span>
        <h1>BOLU<span class="glow">.</span></h1>
        <p class="hero-tagline">Recognize, Monitor, and Protect Our Ocean</p>
        <p class="hero-desc">
            BOLU (Blue Ocean Life Utility) memadukan monitoring IoT <strong>suhu air, pH, dan tegangan listrik</strong>,
            klasifikasi empat spesies ikan dengan <strong>EfficientNetV2-S</strong>, WebGIS,
            peta batimetri BETRI/GEBCO, rekomendasi lokasi KJA Banten, serta prakiraan angin BMKG.
            Tenaga angin menjadi konsep sumber energi pendukung perangkat KJA.
        </p>
        <div class="hero-actions">
            <a class="btn-primary" href="dashboard.php">
                <i class="fa-solid fa-chart-line"></i> Jelajahi Dashboard
            </a>
            <a class="btn-ghost" href="webgis.php">
                <i class="fa-solid fa-map"></i> Lihat Peta WebGIS
            </a>
        </div>
    </div>
</header>

<!-- ===== Statistik & fokus sistem ===== -->
<section class="stats-wrap" aria-label="Statistik ringkas">
    <div class="container stats-grid">
        <div class="stat-card reveal">
            <span class="stat-ico"><i class="fa-solid fa-microchip"></i></span>
            <div class="stat-val"><?= lp_num($stats['devices']) ?><small>/<?= lp_num($stats['active']) ?> online</small></div>
            <div class="stat-lbl">Perangkat IoT terdaftar / online</div>
        </div>
        <div class="stat-card reveal">
            <span class="stat-ico"><i class="fa-solid fa-database"></i></span>
            <div class="stat-val"><?= lp_num($stats['measure']) ?><small>rekaman</small></div>
            <div class="stat-lbl">Rekaman pengukuran kualitas air</div>
        </div>
        <div class="stat-card reveal">
            <span class="stat-ico"><i class="fa-solid fa-temperature-half"></i></span>
            <div class="stat-val">3<small>parameter</small></div>
            <div class="stat-lbl">Suhu air, pH, dan tegangan</div>
        </div>
        <div class="stat-card reveal">
            <span class="stat-ico"><i class="fa-solid fa-users"></i></span>
            <div class="stat-val">4<small>anggota</small></div>
            <div class="stat-lbl">Tim pengembang BOLU</div>
        </div>
    </div>
</section>

<!-- ===== Fitur ===== -->
<section class="features" id="fitur">
    <div class="container">
        <span class="sec-eyebrow">Fitur Platform</span>
        <h2>Monitoring KJA dalam satu platform</h2>
        <p class="sec-sub">Sensor IoT, klasifikasi spesies, analitik kualitas air, dan pemetaan KJA terintegrasi — dengan sumber data ditampilkan sesuai fungsinya.</p>
        <div class="feat-grid">
            <a class="feat-card reveal" href="kualitas-air.php">
                <span class="feat-ico"><i class="fa-solid fa-droplet"></i></span>
                <h3>Kualitas Air</h3>
                <p>Monitoring suhu dan pH dari sensor IoT. Tegangan listrik dipantau sebagai parameter kelistrikan perangkat.</p>
                <span class="feat-link">Buka halaman <i class="fa-solid fa-arrow-right"></i></span>
            </a>
            <a class="feat-card reveal" href="fishcount.php">
                <span class="feat-ico"><i class="fa-solid fa-fish"></i></span>
                <h3>FishCount AI — Klasifikasi Spesies</h3>
                <p>EfficientNetV2-S mengklasifikasikan Bandeng, Kerapu Macan, Kakap Putih, dan Bawal Bintang dari foto. Belum menghitung jumlah ikan otomatis.</p>
                <span class="feat-link">Buka halaman <i class="fa-solid fa-arrow-right"></i></span>
            </a>
            <a class="feat-card reveal" href="webgis.php">
                <span class="feat-ico"><i class="fa-solid fa-map-location-dot"></i></span>
                <h3>WebGIS</h3>
                <p>Peta interaktif untuk melihat lokasi dan informasi spasial pendukung pemantauan KJA.</p>
                <span class="feat-link">Buka halaman <i class="fa-solid fa-arrow-right"></i></span>
            </a>
            <a class="feat-card reveal" href="batimetri.php">
                <span class="feat-ico"><i class="fa-solid fa-mountain"></i></span>
                <h3>Peta Batimetri &amp; BMKG</h3>
                <p>Data kedalaman BETRI/GEBCO serta prakiraan kecepatan dan arah angin dari BMKG untuk wilayah acuan pesisir.</p>
                <span class="feat-link">Buka halaman <i class="fa-solid fa-arrow-right"></i></span>
            </a>
            <a class="feat-card reveal" href="lokasi-kja.php">
                <span class="feat-ico"><i class="fa-solid fa-anchor"></i></span>
                <h3>Lokasi KJA Banten</h3>
                <p>Rekomendasi titik KJA berbasis kesesuaian kedalaman dan kriteria lokasi yang ditampilkan di peta.</p>
                <span class="feat-link">Buka halaman <i class="fa-solid fa-arrow-right"></i></span>
            </a>
            <a class="feat-card reveal" href="prediksi-ai.php">
                <span class="feat-ico"><i class="fa-solid fa-chart-line"></i></span>
                <h3>Analitik Z-Score</h3>
                <p>Deteksi anomali statistik suhu dan pH berdasarkan data historis, bukan klaim model machine learning prediktif.</p>
                <span class="feat-link">Buka halaman <i class="fa-solid fa-arrow-right"></i></span>
            </a>
        </div>
    </div>
</section>

<!-- ===== Tentang platform ===== -->
<section class="about-strip" id="tentang">
    <div class="container about-grid">
        <div>
            <h2>Mengapa BOLU?</h2>
            <p>BOLU membantu pembudidaya KJA membaca suhu dan pH air, memantau tegangan perangkat,
               menguji klasifikasi spesies ikan, serta melihat data lokasi dan kedalaman perairan.
               Konsep energi angin mendukung kemandirian pasokan listrik, sementara prakiraan angin BMKG
               digunakan sebagai informasi eksternal, bukan pengukuran sensor di laut.</p>
        </div>
        <div class="about-badges">
            <span class="about-badge"><i class="fa-solid fa-microchip"></i> Monitoring IoT suhu, pH, dan tegangan</span>
            <span class="about-badge"><i class="fa-solid fa-fish"></i> EfficientNetV2-S untuk spesies ikan</span>
            <span class="about-badge"><i class="fa-solid fa-map"></i> WebGIS &amp; batimetri</span>
            <span class="about-badge"><i class="fa-solid fa-wind"></i> Prakiraan angin BMKG</span>
        </div>
    </div>
</section>

<!-- ===== About Me / Tim Pengembang ===== -->
<section class="features" id="tim" aria-labelledby="team-title">
    <div class="container">
        <span class="sec-eyebrow">About Us</span>
        <h2 id="team-title">Tim Pengembang BOLU</h2>
        <p class="sec-sub">Proyek kolaboratif empat anggota dengan pembagian peran pengembangan website, perangkat IoT, dan pemetaan WebGIS. Isi nama sesuai anggota kelompok.</p>
        <div class="bolu-team-grid">
            <article class="bolu-team-card reveal">
                <div class="bolu-team-icon"><i class="fa-solid fa-laptop-code"></i></div>
                <span class="bolu-team-role">Pengembang Website</span>
                <h3>Noor Azmi Lang Lang Buana</h3>
                <p>Mengembangkan antarmuka dan integrasi fitur website BOLU, termasuk koneksi layanan AI.</p>
            </article>
            <article class="bolu-team-card reveal">
                <div class="bolu-team-icon"><i class="fa-solid fa-microchip"></i></div>
                <span class="bolu-team-role">Pengembang Perangkat IoT</span>
                <h3>Ardian Rifqi</h3>
                <p>Laki-laki · Bersama anggota perangkat lainnya merakit dan menguji sistem pembacaan sensor serta kelistrikan.</p>
            </article>
            <article class="bolu-team-card reveal">
                <div class="bolu-team-icon"><i class="fa-solid fa-screwdriver-wrench"></i></div>
                <span class="bolu-team-role">Pengembang Perangkat IoT</span>
                <h3>Nandita Rafanaura Fathuri</h3>
                <p>Bersama anggota perangkat lainnya mengembangkan dan menguji perangkat monitoring suhu, pH, dan tegangan.</p>
            </article>
            <article class="bolu-team-card reveal">
                <div class="bolu-team-icon"><i class="fa-solid fa-map-location-dot"></i></div>
                <span class="bolu-team-role">Pengembang WebGIS</span>
                <h3>Anisya Dwi Yuliantari</h3>
                <p>Mengelola pemetaan digital, informasi lokasi KJA, dan visualisasi data spasial dalam platform.</p>
            </article>
        </div>
    </div>
</section>
<style>
.bolu-team-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;margin-top:25px}
.bolu-team-card{background:#fff;border:1px solid #d8e9f0;border-radius:17px;padding:24px 20px;box-shadow:0 8px 25px rgba(13,57,78,.08);color:#173c51}
.bolu-team-icon{width:52px;height:52px;background:#eaf6fb;color:#0288b5;border-radius:15px;display:flex;align-items:center;justify-content:center;font-size:23px;margin-bottom:16px}
.bolu-team-role{display:block;color:#08799e;font-size:12px;font-weight:700;letter-spacing:.02em}
.bolu-team-card h3{font-size:18px;margin:9px 0;color:#12394f}
.bolu-team-card p{font-size:14px;line-height:1.7;color:#58778a;margin:0}
@media(max-width:1000px){.bolu-team-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.bolu-team-grid{grid-template-columns:1fr}}
</style>

<!-- ===== Footer ===== -->
<footer class="lp-footer">
    <div class="container foot-row">
        <a class="foot-brand" href="index.php">
            <img src="assets/images/logo-mark-black.png?v=20261118-footer" alt="Logo BOLU">
            <span>
                <strong>BOLU</strong>
                <span>Blue Ocean Life Utility</span>
            </span>
        </a>
        <nav class="foot-links" aria-label="Tautan footer">
            <a href="dashboard.php">Dashboard</a>
            <a href="webgis.php">WebGIS</a>
            <a href="penjelasan-kja.php">Edukasi</a>
            <a href="#tim">Tim Kami</a>
            <a href="login.php">Masuk</a>
        </nav>
        <span class="foot-copy">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. Seluruh hak dilindungi.</span>
    </div>
</footer>

<script>
(function () {
    'use strict';
    /* Menu mobile */
    var burger = document.getElementById('lpHamburger');
    var menu = document.getElementById('lpMenu');
    if (burger && menu) {
        burger.addEventListener('click', function () { menu.classList.toggle('open'); });
        document.addEventListener('click', function (ev) {
            if (!ev.target.closest('.lp-nav')) menu.classList.remove('open');
        });
    }
    /* Reveal on scroll */
    var els = document.querySelectorAll('.reveal');
    if (!('IntersectionObserver' in window)) {
        els.forEach(function (el) { el.classList.add('in'); });
        return;
    }
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
            if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); }
        });
    }, { threshold: .15 });
    els.forEach(function (el) { io.observe(el); });
})();
</script>
<script src="assets/js/embed-helper.js?v=1"></script>
</body>
</html>
