<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'httponly' => true,
        'samesite' => $isHttps ? 'None' : 'Lax',
        'secure' => $isHttps,
    ]);
    session_name('bolu_sid');
    session_start();
}

function current_user(): ?array
{
    static $cached = false;
    if ($cached !== false) {
        return $cached;
    }
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        $cached = null;
        return null;
    }
    $stmt = db()->prepare('SELECT id, username, email, full_name, role, is_active FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$uid]);
    $u = $stmt->fetch();
    if (!$u || (int)$u['is_active'] !== 1) {
        $_SESSION = [];
        $cached = null;
        return null;
    }
    $_SESSION['role'] = $u['role'];
    $cached = $u;
    return $u;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

function can_manage(): bool
{
    return in_array(current_user()['role'] ?? '', ['admin', 'operator'], true);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $tok = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($tok === '' || !hash_equals(csrf_token(), $tok)) {
        http_response_code(419);
        if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
            json_response(false, 'CSRF token tidak valid. Muat ulang halaman.', 419);
        }
        exit('CSRF token tidak valid. Silakan muat ulang halaman.');
    }
}

function flash_set(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['t' => $type, 'm' => $msg];
}

function flash_render(): string
{
    $list = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    if (!$list) {
        return '';
    }
    $html = '<div class="flash-stack">';
    foreach ($list as $f) {
        $cls = $f['t'] === 'error' ? 'danger' : $f['t'];
        $icon = match ($f['t']) {
            'success' => 'fa-circle-check',
            'error' => 'fa-circle-exclamation',
            default => 'fa-circle-info',
        };
        $html .= '<div class="callout callout-' . e($cls) . ' flash-item" role="alert">'
            . '<i class="fa-solid ' . $icon . '"></i><div>' . e($f['m']) . '</div>'
            . '<button type="button" class="flash-close" onclick="this.parentElement.remove()">&times;</button></div>';
    }
    return $html . '</div>';
}

function require_login(): void
{
    if (current_user()) {
        return;
    }
    $script = basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php');
    $next = ($script && $script !== 'login.php' && $script !== 'index.php')
        ? '?next=' . urlencode($script)
        : '';
    header('Location: login.php' . $next);
    exit;
}

function require_login_api(): void
{
    if (current_user()) {
        return;
    }
    json_response(false, 'Tidak terautentikasi. Silakan login kembali.', 401);
}
