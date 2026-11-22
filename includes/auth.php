<?php
/**
 * BOLU — Autentikasi & Manajemen Sesi
 * Sertakan file ini di SEMUA halaman internal dan endpoint privat.
 */

if (!defined('SMART_KJA_CONFIG_LOADED')) {
    exit('Akses langsung tidak diizinkan.');
}

require_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    // Deteksi HTTPS — juga mendukung aplikasi berjalan di balik reverse proxy
    // / preview gateway yang meneruskan X-Forwarded-Proto: https
    $sk_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
             || (($_SERVER['SERVER_PORT'] ?? '') == '443');
    // Deteksi preview gateway: proxy menyuntikkan header "Abc" dan menulis-ulang
    // Host ke *.space-z.ai / *.fcapp.run. Pada konteks ini halaman tampil di
    // dalam iframe (konteks pihak ketiga), sehingga cookie sesi wajib
    // Secure + SameSite=None (+ Partitioned/CHIPS bila tersedia) atau browser
    // akan menolaknya → login terasa "reload terus".
    $sk_host  = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $sk_preview = $sk_https
        || isset($_SERVER['HTTP_ABC'])
        || str_ends_with($sk_host, '.space-z.ai')
        || str_ends_with($sk_host, '.fcapp.run');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $sk_preview, // Secure wajib bila HTTPS (dan untuk SameSite=None)
        'httponly' => true,        // cegah akses via JavaScript
        // None : agar sesi tetap berjalan saat aplikasi di-embed iframe
        //        (panel preview) — browser menuntut Secure bila None.
        // Lax  : di lokal HTTP (XAMPP) sebagai mitigasi CSRF standar.
        // Catatan: build PHP portable sandbox tidak mendukung opsi
        // 'partitioned' (CHIPS) pada session_set_cookie_params — memasang
        // kunci itu memicu warning yang tercetak sebelum headers terkirim
        // dan menggagalkan session_start (gejala: login reload terus).
        'samesite' => $sk_preview ? 'None' : 'Lax',
    ]);
    session_start();
}

/** Ambil data pengguna yang sedang login (dari sesi, diverifikasi ke database). */
function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        if (empty($_SESSION['user_id'])) {
            $user = null;
        } else {
            $st = db()->prepare('SELECT id, username, email, full_name, role, is_active FROM users WHERE id = ? LIMIT 1');
            $st->execute([$_SESSION['user_id']]);
            $u = $st->fetch();
            if (!$u || (int)$u['is_active'] !== 1) {
                // Akun dinonaktifkan di tengah sesi -> paksa keluar
                $_SESSION = [];
                session_destroy();
                $user = null;
            } else {
                $user = $u;
                $_SESSION['role'] = $u['role'];
            }
        }
    }
    return $user;
}

/** Wajib login untuk halaman web — redirect ke login.php bila belum. */
function require_login(): void
{
    if (!current_user()) {
        $url = 'login.php';
        if (($_SERVER['SCRIPT_NAME'] ?? '') !== '') {
            $script = basename($_SERVER['SCRIPT_NAME']);
            if ($script !== 'login.php' && $script !== 'index.php') {
                $url .= '?next=' . urlencode($script);
            }
        }
        header('Location: ' . $url);
        exit;
    }
}

/** Wajib login untuk endpoint API — balas 401 JSON bila belum. */
function require_login_api(): void
{
    if (!current_user()) {
        json_fail('Tidak terautentikasi. Silakan login kembali.', 401);
    }
}

/** Wajib peran tertentu untuk endpoint API. */
function require_role_api(array $roles): void
{
    $u = current_user();
    if (!$u || !in_array($u['role'], $roles, true)) {
        json_fail('Anda tidak memiliki hak akses untuk aksi ini.', 403);
    }
}
