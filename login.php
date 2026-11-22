<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$username = '';
$next = (string)($_GET['next'] ?? '');
$validNext = [
    'dashboard.php', 'penjelasan-kja.php', 'kualitas-air.php', 'gelombang.php',
    'energi-owc.php', 'fishcount.php', 'fishcount-species.php', 'webgis.php',
    'batimetri.php', 'lokasi-kja.php', 'prediksi-ai.php', 'peringatan.php',
    'laporan.php', 'perangkat.php', 'jenis-ikan.php', 'pengaturan.php',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        $error = 'Username/email dan password wajib diisi.';
    } else {
        $stmt = db()->prepare('SELECT * FROM users WHERE is_active = 1 AND (username = ? OR email = ?) LIMIT 1');
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, (string)$user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['name'] = $user['full_name'] ?: $user['username'];
            db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([(int)$user['id']]);
            $target = in_array($next, $validNext, true) ? $next : 'dashboard.php';
            header('Location: ' . $target);
            exit;
        }
        $error = 'Username/email atau password salah.';
    }
}
$siteLocation = setting('site_location', 'Lokasi KJA') ?? 'Lokasi KJA';
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — <?= e(APP_NAME) ?></title>
<link rel="icon" type="image/png" href="assets/images/favicon.png?v=2">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="assets/css/style.css?v=2">
</head>
<body class="login-page">
<div class="login-wrap">
    <section class="login-hero" aria-hidden="true">
        <div class="login-hero-inner">
            <div class="login-logo">
                <img src="assets/images/logo-bolu.png" alt="BOLU" height="52">
                <div><strong>BOLU</strong><span>Sistem Monitoring Keramba Jaring Apung</span></div>
            </div>
            <h1>Pantau laut, energi, dan biota dalam satu dashboard.</h1>
            <p>Monitoring Energi Gelombang (OWC), Kualitas Perairan, dan Biota untuk budidaya pesisir berkelanjutan.</p>
        </div>
    </section>
    <section class="login-panel">
        <div class="login-card">
            <div class="login-card-head">
                <span class="login-badge-logo"><img src="assets/images/logo-bolu.png" alt="BOLU" height="44"></span>
                <h2>Selamat Datang Kembali</h2>
                <p>Masuk untuk memantau KJA &amp; energi OWC Anda</p>
            </div>
            <?php if ($error): ?>
                <div class="callout callout-danger" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div><?= e($error) ?></div></div>
            <?php endif; ?>
            <form method="post" action="login.php<?= $next ? '?next=' . urlencode($next) : '' ?>" novalidate>
                <?= csrf_field() ?>
                <div class="form-group">
                    <label class="form-label" for="username">Username atau Email</label>
                    <input class="form-control" type="text" id="username" name="username" value="<?= e($username) ?>" required autofocus>
                </div>
                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control" type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block btn-lg"><i class="fa-solid fa-right-to-bracket"></i> Masuk</button>
            </form>
            <p class="login-foot">
                <span class="loc-chip"><i class="fa-solid fa-location-dot"></i> <?= e($siteLocation) ?></span>
                <span class="ver-chip">v<?= e(APP_VERSION) ?></span>
            </p>
        </div>
    </section>
</div>
<script src="assets/js/embed-helper.js?v=1"></script>
</body>
</html>
