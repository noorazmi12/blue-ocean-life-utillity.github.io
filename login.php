<?php
/**
 * BOLU — Halaman Login
 * Autentikasi berbasis MySQL + password_hash()/password_verify() + session PHP.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

// Pengguna yang sudah login langsung diarahkan ke dashboard
if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $next     = $_GET['next'] ?? '';

    // ---- Validasi input di sisi server ----
    if ($username === '' || $password === '') {
        $error = 'Username/email dan password wajib diisi.';
    } elseif (mb_strlen($username) > 100 || mb_strlen($password) > 255) {
        $error = 'Format input tidak valid.';
    } else {
        // Pembatasan percobaan sederhana (throttle) — maks 5x, tunggu 30 detik
        $now       = time();
        $attempts  = $_SESSION['login_attempts'] ?? [];
        $attempts  = array_values(array_filter($attempts, fn($t) => ($now - $t) < 300));
        if (count($attempts) >= 5) {
            $error = 'Terlalu banyak percobaan gagal. Tunggu 30 detik lalu coba lagi.';
        } else {
            // Cari berdasarkan username ATAU email (prepared statement)
            $st = db()->prepare('SELECT * FROM users WHERE (username = :u OR email = :e) AND is_active = 1 LIMIT 1');
            $st->execute([':u' => $username, ':e' => $username]);
            $user = $st->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Regenerasi ID sesi untuk mencegah session fixation
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['role']    = $user['role'];
                $_SESSION['name']    = $user['full_name'] ?: $user['username'];
                unset($_SESSION['login_attempts']);

                $up = db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
                $up->execute([$user['id']]);

                // Validasi target redirect agar tidak terbuka lebar (open redirect)
                $target = 'dashboard.php';
                if ($next !== '' && preg_match('/^[a-z0-9_\-]+\.php$/', $next) && is_file(__DIR__ . '/' . $next)) {
                    $target = $next;
                }
                header('Location: ' . $target);
                exit;
            }

            $attempts[] = $now;
            $_SESSION['login_attempts'] = $attempts;
            $error = 'Username/email atau password salah.';
        }
    }
}

$siteLocation = setting('site_location', 'Lokasi KJA');

// Huruf judul splash → span berjenjang (delay via custom property --i).
// "BOLU" putih, "OCEAN" aksen cyan.
$splashTitle = '';
$si = 0;
foreach (['BOLU' => '', 'OCEAN' => 'accent'] as $w => $cls) {
    if ($splashTitle !== '') {
        $splashTitle .= '<span class="sp-gap" aria-hidden="true" style="--i:' . $si++ . '">&nbsp;</span>';
    }
    foreach (mb_str_split($w) as $ch) {
        $splashTitle .= '<span aria-hidden="true"' . ($cls !== '' ? ' class="' . $cls . '"' : '')
            . ' style="--i:' . $si++ . '">' . e($ch) . '</span>';
    }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — <?= e(APP_NAME) ?></title>
<link rel="icon" type="image/png" href="assets/images/favicon.png?v=2">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="assets/css/style.css?v=2">
<script>/* Tandai JS aktif untuk koreografi splash (tanpa JS: konten langsung tampil) */
try{if(!matchMedia('(prefers-reduced-motion: reduce)').matches)document.documentElement.classList.add('splash-js')}catch(e){}</script>
</head>
<body class="login-page">

<!-- ===== Splash intro: animasi pembuka sebelum form login tampil ===== -->
<div class="splash" id="splash" role="status" aria-label="Memuat BOLU">
    <span class="splash-bubble b1" aria-hidden="true"></span>
    <span class="splash-bubble b2" aria-hidden="true"></span>
    <span class="splash-bubble b3" aria-hidden="true"></span>
    <span class="splash-bubble b4" aria-hidden="true"></span>

    <div class="splash-core">
        <div class="splash-logo">
            <span class="splash-ring pr1"></span>
            <span class="splash-ring pr2"></span>
            <span class="splash-ring pr3"></span>
            <span class="splash-ring dash"></span>
            <img src="assets/images/logo-mark-white.png" alt="" width="62" height="62">
        </div>
        <h1 class="splash-title" aria-label="BOLU — Blue Ocean Life Utility"><?= $splashTitle ?></h1>
        <p class="splash-sub" aria-hidden="true">Blue Ocean Life Utility &middot; Monitoring Laut &amp; Energi Gelombang</p>
        <div class="splash-loader" aria-hidden="true"><span></span></div>
        <p class="splash-status" aria-hidden="true"><span class="dot dot-live"></span> Menghubungkan ke perairan <?= e($siteLocation) ?>&hellip;</p>
    </div>

    <p class="splash-skip" aria-hidden="true">Klik di mana saja untuk lewati</p>

    <div class="splash-waves" aria-hidden="true">
        <svg class="wave w1" viewBox="0 0 1440 120" preserveAspectRatio="none"><path d="M0,60 C180,100 540,20 720,60 C900,100 1260,20 1440,60 L1440,120 L0,120 Z"/></svg>
        <svg class="wave w2" viewBox="0 0 1440 120" preserveAspectRatio="none"><path d="M0,72 C240,32 480,112 720,72 C960,32 1200,112 1440,72 L1440,120 L0,120 Z"/></svg>
        <svg class="wave w3" viewBox="0 0 1440 120" preserveAspectRatio="none"><path d="M0,86 C180,53 540,118 720,86 C900,53 1260,118 1440,86 L1440,120 L0,120 Z"/></svg>
    </div>
</div>

<div class="login-wrap">
    <!-- Panel kiri: identitas & fitur -->
    <section class="login-hero" aria-hidden="true">
        <span class="hero-orb o1"></span>
        <span class="hero-orb o2"></span>
        <span class="hero-orb o3"></span>
        <div class="login-hero-inner">
            <div class="login-logo">
                <img src="assets/images/logo-bolu.png" alt="BOLU — Blue Ocean Life Utility" height="52">
                <div>
                    <strong>BOLU</strong>
                    <span>Sistem Monitoring Keramba Jaring Apung</span>
                </div>
            </div>
            <h1>Pantau laut, energi, dan biota dalam satu dashboard.</h1>
            <p>Monitoring Energi Gelombang (OWC), Kualitas Perairan, dan Biota untuk mendukung budidaya pesisir berkelanjutan.</p>
            <ul class="login-features">
                <li><i class="fa-solid fa-water"></i><div><strong>Monitoring Gelombang</strong><span>Tinggi, periode, dan estimasi wave power secara real-time</span></div></li>
                <li><i class="fa-solid fa-bolt"></i><div><strong>Energi OWC</strong><span>Daya, tegangan, arus, dan akumulasi energi listrik</span></div></li>
                <li><i class="fa-solid fa-droplet"></i><div><strong>Kualitas Perairan</strong><span>Suhu, salinitas, pH, dan kekeruhan dengan ambang adaptif</span></div></li>
                <li><i class="fa-solid fa-fish"></i><div><strong>FishCount AI</strong><span>Estimasi jumlah ikan berbasis kamera (siap integrasi model)</span></div></li>
            </ul>
            <div class="hero-badges">
                <span><i class="fa-solid fa-shield-halved"></i> Login aman</span>
                <span><i class="fa-solid fa-wave-square"></i> Pantauan 24/7</span>
                <span><i class="fa-solid fa-bolt"></i> Energi gelombang</span>
            </div>
        </div>
        <div class="hero-waves" aria-hidden="true">
            <svg class="wave w1" viewBox="0 0 1440 120" preserveAspectRatio="none"><path d="M0,60 C180,100 540,20 720,60 C900,100 1260,20 1440,60 L1440,120 L0,120 Z"/></svg>
            <svg class="wave w2" viewBox="0 0 1440 120" preserveAspectRatio="none"><path d="M0,72 C240,32 480,112 720,72 C960,32 1200,112 1440,72 L1440,120 L0,120 Z"/></svg>
            <svg class="wave w3" viewBox="0 0 1440 120" preserveAspectRatio="none"><path d="M0,86 C180,53 540,118 720,86 C900,53 1260,118 1440,86 L1440,120 L0,120 Z"/></svg>
        </div>
    </section>

    <!-- Panel kanan: form login -->
    <section class="login-panel">
        <div class="login-card">
            <div class="login-card-head">
                <span class="login-badge-logo"><img src="assets/images/logo-bolu.png" alt="BOLU — Blue Ocean Life Utility" height="44"></span>
                <h2>Selamat Datang Kembali</h2>
                <p>Masuk untuk memantau KJA &amp; energi OWC Anda</p>
            </div>

            <?php if ($error): ?>
            <div class="callout callout-danger" role="alert">
                <i class="fa-solid fa-circle-exclamation"></i><div><?= e($error) ?></div>
            </div>
            <?php endif; ?>

            <form method="post" action="login.php<?= isset($_GET['next']) ? '?next=' . urlencode($_GET['next']) : '' ?>" autocomplete="off" novalidate>
                <?= csrf_field() ?>
                <div class="form-group">
                    <label class="form-label" for="username">Username atau Email</label>
                    <div class="input-icon">
                        <i class="fa-solid fa-user"></i>
                        <input class="form-control" type="text" id="username" name="username"
                               value="<?= e($username) ?>" maxlength="100" required autofocus
                               placeholder="mis. admin">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <div class="input-icon input-icon-right">
                        <i class="fa-solid fa-lock"></i>
                        <input class="form-control" type="password" id="password" name="password"
                               maxlength="255" required placeholder="••••••••">
                        <button type="button" class="toggle-pass" id="togglePass"
                                aria-label="Tampilkan / sembunyikan password" title="Tampilkan / sembunyikan password">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                </div>
                <p class="caps-hint" id="capsHint" hidden><i class="fa-solid fa-triangle-exclamation"></i> Caps Lock aktif</p>
                <button type="submit" class="btn btn-primary btn-block btn-lg">
                    <i class="fa-solid fa-right-to-bracket"></i> Masuk
                </button>
            </form>

            <?php if (SHOW_QUICK_ACCOUNTS): ?>
            <div class="demo-box">
                <div class="demo-divider"><span>Akun sistem &middot; klik untuk mengisi otomatis</span></div>
                <div class="demo-chips">
                    <button type="button" class="demo-chip" data-user="admin" data-pass="Admin@123">
                        <i class="fa-solid fa-user-gear"></i>
                        <span><strong>Admin</strong><small>Akses penuh</small></span>
                    </button>
                    <button type="button" class="demo-chip" data-user="operator" data-pass="Operator@123">
                        <i class="fa-solid fa-sliders"></i>
                        <span><strong>Operator</strong><small>Kelola data</small></span>
                    </button>
                    <button type="button" class="demo-chip" data-user="viewer" data-pass="Viewer@123">
                        <i class="fa-solid fa-eye"></i>
                        <span><strong>Viewer</strong><small>Lihat saja</small></span>
                    </button>
                </div>
                <p class="demo-note"><i class="fa-solid fa-circle-info"></i> Akun baru ditambahkan admin lewat <strong>Pengaturan &rarr; Pengelolaan Pengguna</strong>.</p>
            </div>
            <?php endif; ?>

            <p class="login-foot">
                <span class="loc-chip"><i class="fa-solid fa-location-dot"></i> <?= e($siteLocation) ?></span>
                <span class="ver-chip">v<?= e(APP_VERSION) ?></span>
            </p>
        </div>
        <p class="login-copy">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?> — Mendukung pengelolaan budidaya pesisir berkelanjutan.</p>
    </section>
</div>

<script>
// Tampilkan / sembunyikan password
(function () {
    var btn = document.getElementById('togglePass');
    var inp = document.getElementById('password');
    if (!btn || !inp) return;
    btn.addEventListener('click', function () {
        var show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        btn.querySelector('i').className = show ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
        inp.focus();
    });
})();
// Klik chip akun → isi form otomatis (kredensial memang ditampilkan di kotak akun sistem)
(function () {
    var chips = document.querySelectorAll('.demo-chip');
    if (!chips.length) return;
    var u = document.getElementById('username');
    var p = document.getElementById('password');
    chips.forEach(function (ch) {
        ch.addEventListener('click', function () {
            if (!u || !p) return;
            u.value = ch.dataset.user || '';
            p.value = ch.dataset.pass || '';
            chips.forEach(function (c) { c.classList.remove('active'); });
            ch.classList.add('active');
            [u, p].forEach(function (el) {
                el.classList.remove('filled');
                void el.offsetWidth; /* restart animasi */
                el.classList.add('filled');
            });
            p.focus();
        });
    });
})();
// Peringatan Caps Lock di kolom password
(function () {
    var inp = document.getElementById('password');
    var hint = document.getElementById('capsHint');
    if (!inp || !hint) return;
    function check(e) {
        var on = e.getModifierState && e.getModifierState('CapsLock');
        hint.hidden = !on;
    }
    inp.addEventListener('keydown', check);
    inp.addEventListener('keyup', check);
    inp.addEventListener('blur', function () { hint.hidden = true; });
})();
// Splash intro: mainkan animasi pembuka ±2.3 dtk, lalu tampilkan halaman
// login. Bisa dilewati dengan klik, ?nosplash=1, dan mati otomatis bila
// pengguna memilih pengurangan gerak (prefers-reduced-motion).
// Tanpa JS pun splash tetap hilang sendiri (fallback CSS splashAutoOut)
// dan konten login tetap tampil (delay tetap di CSS, tak tergantung JS).
(function () {
    var sp = document.getElementById('splash');
    if (!sp) return;
    var reduce = false;
    try { reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}
    if (reduce || /(?:^|[?&])nosplash=1/.test(location.search)) {
        document.body.classList.add('splash-done');
        if (sp.parentNode) sp.parentNode.removeChild(sp);
        return;
    }
    var finished = false;
    function finish() {
        if (finished) return;
        finished = true;
        document.body.classList.add('splash-done');   /* konten masuk berjenjang */
        document.body.classList.remove('splash-active'); /* buka kunci scroll */
        sp.classList.add('splash-hide');               /* splash memudar */
        setTimeout(function () { if (sp.parentNode) sp.parentNode.removeChild(sp); }, 750);
    }
    document.body.classList.add('splash-active');      /* kunci scroll saat splash */
    setTimeout(finish, 2300);   /* alur normal */
    setTimeout(finish, 5000);   /* jaring pengamat */
    sp.addEventListener('click', finish);              /* klik = lewati */
})();
// Saat aplikasi di-embed (panel preview / iframe lintas situs), browser dapat
// memblokir cookie sesi sehingga login gagal (CSRF). Bilah bantu ("Buka Tab
// Baru" + "Salin Tautan") kini dari assets/js/embed-helper.js — dimuat di bawah.
</script>
<script src="assets/js/embed-helper.js?v=1"></script>
</body>
</html>
