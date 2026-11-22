<?php
/**
 * BOLU — Logout: akhiri sesi dan kembali ke halaman login.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

$_SESSION = [];

// Hapus cookie sesi agar benar-benar bersih
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

session_destroy();
header('Location: login.php');
exit;
