<?php
/**
 * BOLU — Fungsi-fungsi pembantu (helpers)
 * Semua halaman dan API mengandalkan file ini.
 */

if (!defined('SMART_KJA_CONFIG_LOADED')) {
    exit('Akses langsung tidak diizinkan.');
}

require_once dirname(__DIR__) . '/config/database.php';

/* ============================================================
 * KONEKSI & OUTPUT
 * ============================================================ */

function db(): PDO
{
    global $PDO;
    return $PDO;
}

/** Escape output HTML (anti-XSS). Gunakan pada SEMUA output dinamis. */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Output JSON dan hentikan eksekusi. */
function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_fail(string $msg, int $code = 400): void
{
    json_out(['ok' => false, 'error' => $msg], $code);
}

/** Ambil input JSON body (untuk endpoint perangkat). */
function json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/* ============================================================
 * PENGATURAN SISTEM (tabel system_settings)
 * ============================================================ */

function setting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT setting_key, setting_value FROM system_settings') as $r) {
                $cache[$r['setting_key']] = $r['setting_value'];
            }
        } catch (Throwable $t) {
            // tabel belum ada (database belum diimpor) — kembalikan default
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function setting_int(string $key, int $default): int
{
    return (int) setting($key, $default);
}

function setting_float(string $key, float $default): float
{
    return (float) setting($key, $default);
}

function save_setting(string $key, string $value): void
{
    $st = db()->prepare(
        'INSERT INTO system_settings (setting_key, setting_value, updated_at)
         VALUES (:k, :v, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
    );
    $st->execute([':k' => $key, ':v' => $value]);
}

/* ============================================================
 * CSRF (proteksi untuk semua operasi perubahan data)
 * ============================================================ */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Verifikasi CSRF dari POST form atau header AJAX (X-CSRF-Token). */
function csrf_check(): void
{
    $token = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || $token === '' || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        if (is_api_request()) {
            json_fail('CSRF token tidak valid. Muat ulang halaman.', 419);
        }
        exit('CSRF token tidak valid. Silakan muat ulang halaman.');
    }
}

function is_api_request(): bool
{
    return str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')
        || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
            && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'json'));
}

/* ============================================================
 * FLASH MESSAGE
 * ============================================================ */

function flash_set(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['t' => $type, 'm' => $msg];
}

function flash_render(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $html = '<div class="flash-stack">';
    foreach ($_SESSION['flash'] as $f) {
        $cls  = $f['t'] === 'error' ? 'danger' : $f['t'];
        $icon = $f['t'] === 'success' ? 'fa-circle-check' : ($f['t'] === 'error' ? 'fa-circle-exclamation' : 'fa-circle-info');
        $html .= '<div class="callout callout-' . $cls . ' flash-item" role="alert">'
               . '<i class="fa-solid ' . $icon . '"></i><div>' . e($f['m']) . '</div>'
               . '<button type="button" class="flash-close" onclick="this.parentElement.remove()">&times;</button></div>';
    }
    $html .= '</div>';
    unset($_SESSION['flash']);
    return $html;
}

/* ============================================================
 * ROLE & HAK AKSES
 * ============================================================ */

function is_admin(): bool
{
    return ($_SESSION['role'] ?? '') === 'admin';
}

function can_manage(): bool // admin + operator boleh mengubah data
{
    return in_array($_SESSION['role'] ?? '', ['admin', 'operator'], true);
}

/* ============================================================
 * FORMAT ANGKA & WAKTU (lokal Indonesia)
 * ============================================================ */

function fmt_num($v, int $dec = 2): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    return number_format((float)$v, $dec, ',', '.');
}

const BULAN_ID = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
                  7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'];

function fmt_dt(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return '—';
    }
    return date('j', $ts) . ' ' . BULAN_ID[(int)date('n', $ts)] . ' ' . date('Y H:i', $ts);
}

function fmt_dt_short(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $ts = strtotime($dt);
    return $ts === false ? '—' : date('d/m', $ts) . ' ' . date('H:i', $ts);
}

function time_ago(?string $dt): string
{
    if (!$dt) {
        return 'belum pernah';
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return '—';
    }
    $diff = max(0, time() - $ts);
    if ($diff < 60)        return $diff . ' detik lalu';
    if ($diff < 3600)      return floor($diff / 60) . ' menit lalu';
    if ($diff < 86400)     return floor($diff / 3600) . ' jam lalu';
    return floor($diff / 86400) . ' hari lalu';
}

/* ============================================================
 * STATUS DATA: LIVE / DELAYED / OFFLINE
 * Berdasarkan usia data terakhir vs ambang pada system_settings.
 * ============================================================ */

function data_status(?string $measuredAt): string
{
    if (!$measuredAt) {
        return 'offline';
    }
    $ts  = strtotime($measuredAt);
    if ($ts === false) {
        return 'offline';
    }
    $stale = max(1, setting_int('data_stale_minutes', 10));
    $ageMin = (time() - $ts) / 60;
    if ($ageMin <= $stale)        return 'live';
    if ($ageMin <= $stale * 3)    return 'delayed';
    return 'offline';
}

function data_status_label(string $status): string
{
    return ['live' => 'LIVE', 'delayed' => 'DELAYED', 'offline' => 'OFFLINE'][$status] ?? 'OFFLINE';
}

function status_pill(string $status): string
{
    $label = data_status_label($status);
    return '<span class="status-pill st-' . e($status) . '"><span class="dot"></span>' . $label . '</span>';
}

function device_status_badge(string $status): string
{
    $map = [
        'online'      => ['badge-green', 'fa-circle-check', 'Online'],
        'offline'     => ['badge-gray', 'fa-circle-xmark', 'Offline'],
        'maintenance' => ['badge-yellow', 'fa-screwdriver-wrench', 'Perawatan'],
    ];
    [$cls, $icon, $label] = $map[$status] ?? $map['offline'];
    return '<span class="badge ' . $cls . '"><i class="fa-solid ' . $icon . '"></i>' . $label . '</span>';
}

function severity_badge(string $sev): string
{
    $map = [
        'info'     => ['badge-blue', 'fa-circle-info', 'Informasi'],
        'warning'  => ['badge-yellow', 'fa-triangle-exclamation', 'Peringatan'],
        'critical' => ['badge-red', 'fa-circle-exclamation', 'Kritis'],
    ];
    [$cls, $icon, $label] = $map[$sev] ?? $map['info'];
    return '<span class="badge ' . $cls . '"><i class="fa-solid ' . $icon . '"></i>' . $label . '</span>';
}

function demo_badge(bool $isSimulated): string
{
    if (!$isSimulated || !DEMO_MODE) {
        return '';
    }
    return '<span class="badge badge-yellow badge-demo" title="Data ini dihasilkan oleh seeder demo, bukan sensor nyata">'
         . '<i class="fa-solid fa-flask"></i>DEMO</span>';
}

/* ============================================================
 * AMBANG BATAS PARAMETER (tabel thresholds, per spesies)
 * ============================================================ */

function get_threshold(string $param, string $species = 'Umum'): ?array
{
    static $cache = [];
    $key = $param . '|' . $species;
    if (!array_key_exists($key, $cache)) {
        $st = db()->prepare('SELECT * FROM thresholds WHERE parameter = ? AND species = ? LIMIT 1');
        $st->execute([$param, $species]);
        $cache[$key] = $st->fetch() ?: null;
    }
    return $cache[$key];
}

/**
 * Evaluasi nilai parameter terhadap ambang.
 * Status: normal | warning | critical
 * - di luar [min,max] sebanyak <= warn_span  -> warning
 * - melewati batas normal lebih dari warn_span -> critical
 */
function evaluate_value(string $param, $value): array
{
    $th = get_threshold($param);
    $res = ['status' => 'normal', 'label' => 'Normal', 'threshold' => $th];
    if (!$th || $value === null || !is_numeric($value)) {
        return $res;
    }
    $v = (float)$value;
    $min  = $th['min_value'] !== null ? (float)$th['min_value'] : -INF;
    $max  = $th['max_value'] !== null ? (float)$th['max_value'] : INF;
    $span = $th['warn_span'] !== null ? (float)$th['warn_span'] : 0;

    if ($v >= $min && $v <= $max) {
        return $res;
    }
    $over = $v < $min ? ($min - $v) : ($v - $max);
    if ($over <= $span) {
        $res['status'] = 'warning';
        $res['label']  = 'Peringatan';
    } else {
        $res['status'] = 'critical';
        $res['label']  = 'Kritis';
    }
    $res['dir'] = $v < $min ? 'below' : 'above';
    return $res;
}

function param_status_class(string $status): string
{
    return ['normal' => 'badge-green', 'warning' => 'badge-yellow', 'critical' => 'badge-red'][$status] ?? 'badge-gray';
}

/** Nama ramah pengguna untuk parameter. */
const PARAM_LABELS = [
    'wave_height_m'   => 'Tinggi Gelombang',
    'wave_period_s'   => 'Periode Gelombang',
    'temp_sensor_c'   => 'Suhu Air (Sensor)',
    'sst_c'           => 'SST (Suhu Permukaan Laut)',
    'salinity_psu'    => 'Salinitas',
    'ph'              => 'pH',
    'turbidity_ntu'   => 'Kekeruhan',
    'depth_m'         => 'Kedalaman',
    'power_w'         => 'Daya OWC',
    'battery_pct'     => 'Status Baterai',
    'device_offline'  => 'Perangkat Offline',
    'fish_count'      => 'Jumlah Ikan',
];

function param_label(string $param): string
{
    return PARAM_LABELS[$param] ?? ucfirst(str_replace('_', ' ', $param));
}

/* ============================================================
 * PEMBUATAN PERINGATAN OTOMATIS (dipanggil saat ingest data)
 * ============================================================ */

function maybe_create_alert(int $deviceId, string $param, $value, bool $isSimulated = false): void
{
    $eval = evaluate_value($param, $value);
    if ($eval['status'] === 'normal') {
        return;
    }
    // Dedupe: jangan buat alert baru bila masih ada alert terbuka serupa < 15 menit
    $st = db()->prepare(
        'SELECT id FROM alerts
         WHERE device_id = ? AND parameter = ? AND status = "open" AND occurred_at >= (NOW() - INTERVAL 15 MINUTE)
         LIMIT 1'
    );
    $st->execute([$deviceId, $param]);
    if ($st->fetch()) {
        return;
    }
    $th   = $eval['threshold'];
    $lim  = $eval['dir'] === 'below' ? $th['min_value'] : $th['max_value'];
    $sev  = $eval['status'] === 'critical' ? 'critical' : 'warning';
    $unit = $th['unit'] ?? '';
    $title = param_label($param) . ($eval['dir'] === 'below' ? ' di bawah batas ' : ' di atas batas ') . ($sev === 'critical' ? 'kritis' : 'normal');
    $msg   = 'Nilai terukur ' . fmt_num($value, 2) . ($unit !== '' ? ' ' . $unit : '')
           . ', batas ' . ($eval['dir'] === 'below' ? 'minimum' : 'maksimum') . ' ' . fmt_num($lim, 2) . '.';

    $ins = db()->prepare(
        'INSERT INTO alerts (device_id, parameter, severity, title, message, actual_value, threshold_value, occurred_at, is_simulated)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
    );
    $ins->execute([$deviceId, $param, $sev, $title, $msg, $value, $lim, (int)$isSimulated]);
}

/* ============================================================
 * PERHITUNGAN ENERGI GELOMBANG (OWC)
 * ============================================================ */

/**
 * Daya gelombang teoretis per lebar meter (kW/m):
 * P = rho * g^2 * H^2 * T / (64 * PI)  ≈  0,49 * H^2 * T
 */
function wave_power_kw($h, $t): float
{
    if (!is_numeric($h) || !is_numeric($t)) {
        return 0.0;
    }
    return 0.49 * (float)$h * (float)$h * (float)$t;
}

/** Estimasi daya listrik OWC (W) dari potensi gelombang x lebar penangkap x efisiensi. */
function electric_estimate_w($h, $t): float
{
    $width = setting_float('owc_capture_width_m', 3.0);
    $eff   = setting_float('owc_efficiency', 0.30);
    return max(0.0, wave_power_kw($h, $t) * 1000.0 * $width * $eff);
}

/** Estimasi biomassa ikan (kg) memakai relasi panjang-berat W(g) = a * L^b. */
function estimate_biomass_kg($count, $avgLengthCm): ?float
{
    if (!is_numeric($count) || $count <= 0 || !is_numeric($avgLengthCm) || $avgLengthCm <= 0) {
        return null;
    }
    $a = setting_float('fish_lw_a', 0.015);
    $b = setting_float('fish_lw_b', 3.0);
    $weightG = $a * pow((float)$avgLengthCm, $b);
    return round($count * $weightG / 1000.0, 2);
}

/* ============================================================
 * EKSPOR CSV
 * ============================================================ */

function stream_csv(string $filename, array $header, array $rows): void
{
    if (ob_get_length()) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8
    fputcsv($out, $header);
    foreach ($rows as $r) {
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}

/** Bangun query string dengan menimpa sebagian parameter (untuk link filter). */
function qs_merge(array $overrides): string
{
    $q = array_merge($_GET, $overrides);
    unset($q['export']);
    return '?' . http_build_query($q);
}

/** Validasi & normalisasi rentang tanggal dari GET (filter). */
function date_range_from_get(?string $defaultFrom = null, ?string $defaultTo = null): array
{
    $from = $_GET['from'] ?? $defaultFrom ?? date('Y-m-d', strtotime('-7 days'));
    $to   = $_GET['to'] ?? $defaultTo ?? date('Y-m-d');
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : date('Y-m-d', strtotime('-7 days'));
    $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : date('Y-m-d');
    if (strtotime($from) > strtotime($to)) {
        [$from, $to] = [$to, $from];
    }
    return [$from, $to . ' 23:59:59'];
}

/** Arah mata angin dari derajat. */
function deg_to_compass($deg): string
{
    if ($deg === null || !is_numeric($deg)) {
        return '—';
    }
    $dirs = ['U', 'TL', 'T', 'TG', 'S', 'BD', 'B', 'BL'];
    return $dirs[(int)round(((float)$deg % 360) / 45) % 8];
}
