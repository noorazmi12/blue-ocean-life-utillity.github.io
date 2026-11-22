<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

const PARAM_LABELS = [
    'wave_height_m' => 'Tinggi Gelombang',
    'wave_period_s' => 'Periode Gelombang',
    'temp_sensor_c' => 'Suhu Air (Sensor)',
    'sst_c' => 'SST (Suhu Permukaan Laut)',
    'salinity_psu' => 'Salinitas',
    'ph' => 'pH',
    'volt_ph_v' => 'Tegangan Modul pH',
    'turbidity_ntu' => 'Kekeruhan',
    'depth_m' => 'Kedalaman',
    'power_w' => 'Daya OWC',
    'voltage_v' => 'Tegangan OWC',
    'current_a' => 'Arus OWC',
    'battery_pct' => 'Status Baterai',
    'device_offline' => 'Perangkat Offline',
    'fish_count' => 'Jumlah Ikan',
];

function e(mixed $v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function safe_json(mixed $v): string
{
    $json = json_encode(
        $v,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    return $json !== false ? $json : '{}';
}

function str_trim_width(?string $s, int $maxLen = 60, string $marker = '…'): string
{
    $str = (string)($s ?? '');
    if (mb_strlen($str, 'UTF-8') <= $maxLen) {
        return $str;
    }
    return mb_substr($str, 0, max(0, $maxLen - mb_strlen($marker, 'UTF-8')), 'UTF-8') . $marker;
}

function as_arr(mixed $val): array
{
    if (is_array($val)) {
        return array_map(static fn ($x): string => (string)($x ?? ''), $val);
    }
    if ($val === null) {
        return [];
    }
    return [(string)$val];
}

function setting(string $key, mixed $default = null): ?string
{
    global $_SKJA_SETTINGS_CACHE;
    if (!is_array($_SKJA_SETTINGS_CACHE)) {
        $_SKJA_SETTINGS_CACHE = [];
        try {
            $rows = db()->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll();
            foreach ($rows as $r) {
                $_SKJA_SETTINGS_CACHE[(string)$r['setting_key']] = (string)($r['setting_value'] ?? '');
            }
        } catch (Throwable) {
            return $default !== null ? (string)$default : null;
        }
    }
    if (array_key_exists($key, $_SKJA_SETTINGS_CACHE) && $_SKJA_SETTINGS_CACHE[$key] !== '') {
        return $_SKJA_SETTINGS_CACHE[$key];
    }
    return $default !== null ? (string)$default : null;
}

function setting_int(string $key, int $default = 0): int
{
    $v = setting($key);
    return $v !== null && is_numeric($v) ? (int)$v : $default;
}

function setting_float(string $key, float $default = 0.0): float
{
    $v = setting($key);
    return $v !== null && is_numeric($v) ? (float)$v : $default;
}

function save_setting(string $key, string $val, ?string $desc = null): void
{
    global $_SKJA_SETTINGS_CACHE;
    $pdo = db();
    $exists = $pdo->prepare('SELECT id FROM system_settings WHERE setting_key = ? LIMIT 1');
    $exists->execute([$key]);
    $now = date('Y-m-d H:i:s');
    if ($exists->fetchColumn()) {
        $pdo->prepare('UPDATE system_settings SET setting_value = ?, updated_at = ? WHERE setting_key = ?')
            ->execute([$val, $now, $key]);
    } else {
        $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value, description, updated_at) VALUES (?, ?, ?, ?)')
            ->execute([$key, $val, $desc, $now]);
    }
    if (is_array($_SKJA_SETTINGS_CACHE)) {
        $_SKJA_SETTINGS_CACHE[$key] = $val;
    }
}

function fmt_num(mixed $v, int $dec = 2, string $dash = '—'): string
{
    if ($v === null || $v === '' || !is_numeric($v)) {
        return $dash;
    }
    return number_format((float)$v, $dec, ',', '.');
}

function fmt_dt(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $ts = strtotime($dt);
    if (!$ts) {
        return $dt;
    }
    static $bulan = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $m = (int)date('n', $ts);
    return date('j', $ts) . ' ' . ($bulan[$m] ?? date('M', $ts)) . ' ' . date('Y H:i', $ts);
}

function fmt_dt_short(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $ts = strtotime($dt);
    return $ts ? date('d/m H:i', $ts) : $dt;
}

function fmt_time_hi(?string $dt): string
{
    if (!$dt) {
        return '00:00';
    }
    $ts = strtotime($dt);
    return $ts ? date('H:i', $ts) : '00:00';
}

function fmt_time_his(?string $dt): string
{
    if (!$dt) {
        return '00:00:00';
    }
    $ts = strtotime($dt);
    return $ts ? date('H:i:s', $ts) : '00:00:00';
}

function time_ago(?string $dt): string
{
    if (!$dt) {
        return 'belum pernah';
    }
    $ts = strtotime($dt);
    if (!$ts) {
        return '—';
    }
    $diff = max(0, time() - $ts);
    if ($diff < 60) {
        return $diff . ' detik lalu';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' menit lalu';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . ' jam lalu';
    }
    return floor($diff / 86400) . ' hari lalu';
}

function data_status(?string $dt, ?int $staleMin = null): string
{
    if (!$dt) {
        return 'offline';
    }
    $ts = strtotime($dt);
    if (!$ts) {
        return 'offline';
    }
    $stale = $staleMin ?? max(1, setting_int('data_stale_minutes', 10));
    $ageMin = (time() - $ts) / 60;
    if ($ageMin <= $stale) {
        return 'live';
    }
    if ($ageMin <= $stale * 3) {
        return 'delayed';
    }
    return 'offline';
}

function data_status_label(string $status): string
{
    return match ($status) {
        'live' => 'LIVE',
        'delayed' => 'DELAYED',
        default => 'OFFLINE',
    };
}

function status_pill(string $st): string
{
    $lbl = data_status_label($st);
    return '<span class="status-pill st-' . e($st) . '"><span class="dot"></span>' . $lbl . '</span>';
}

function device_status_badge(string $st): string
{
    return match ($st) {
        'online' => '<span class="badge badge-green"><i class="fa-solid fa-circle-check"></i>Online</span>',
        'maintenance' => '<span class="badge badge-yellow"><i class="fa-solid fa-screwdriver-wrench"></i>Perawatan</span>',
        default => '<span class="badge badge-gray"><i class="fa-solid fa-circle-xmark"></i>Offline</span>',
    };
}

function severity_badge(string $sev): string
{
    return match ($sev) {
        'critical' => '<span class="badge badge-red"><i class="fa-solid fa-circle-exclamation"></i>Kritis</span>',
        'warning' => '<span class="badge badge-yellow"><i class="fa-solid fa-triangle-exclamation"></i>Peringatan</span>',
        default => '<span class="badge badge-blue"><i class="fa-solid fa-circle-info"></i>Informasi</span>',
    };
}

function demo_badge(mixed $isSimulated): string
{
    if (!$isSimulated || !DEMO_MODE) {
        return '';
    }
    return '<span class="badge badge-yellow badge-demo" title="Data ini dihasilkan oleh seeder demo, bukan sensor nyata">'
        . '<i class="fa-solid fa-flask"></i>DEMO</span>';
}

function param_label(string $param): string
{
    return PARAM_LABELS[$param] ?? ucfirst(str_replace('_', ' ', $param));
}

function deg_to_compass(?float $deg): string
{
    if ($deg === null) {
        return '—';
    }
    $dirs = ['U', 'TL', 'T', 'TG', 'S', 'BD', 'B', 'BL'];
    $idx = ((int)round((fmod(fmod($deg, 360.0) + 360.0, 360.0)) / 45.0)) % 8;
    return $dirs[$idx];
}

function wave_power_kw(?float $h, ?float $t): float
{
    if ($h === null || $t === null || $h <= 0 || $t <= 0) {
        return 0.0;
    }
    return round(0.49 * $h * $h * $t, 3);
}

function electric_estimate_w(?float $h, ?float $t): float
{
    $width = setting_float('owc_capture_width_m', 3.0);
    $eff = setting_float('owc_efficiency', 0.30);
    return max(0.0, round(wave_power_kw($h, $t) * 1000.0 * $width * $eff, 1));
}

function get_threshold(string $param, string $species = 'Umum'): ?array
{
    $stmt = db()->prepare(
        "SELECT id, parameter, species, min_value, max_value, warn_span, unit
         FROM thresholds
         WHERE parameter = ? AND species IN (?, 'Umum')
         ORDER BY (CASE WHEN species = ? THEN 1 ELSE 0 END) DESC LIMIT 1"
    );
    $stmt->execute([$param, $species, $species]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function evaluate_value_info(string $param, mixed $val): array
{
    $sp = setting('default_species', setting('active_species', 'Umum') ?? 'Umum') ?: 'Umum';
    $th = get_threshold($param, $sp);
    if (!$th || $val === null || $val === '' || !is_numeric($val)) {
        return ['status' => 'normal', 'label' => 'Normal', 'threshold' => $th, 'dir' => null];
    }
    $v = (float)$val;
    $min = $th['min_value'] !== null ? (float)$th['min_value'] : -INF;
    $max = $th['max_value'] !== null ? (float)$th['max_value'] : INF;
    $span = $th['warn_span'] !== null ? (float)$th['warn_span'] : 0.0;
    if ($v >= $min && $v <= $max) {
        return ['status' => 'normal', 'label' => 'Normal', 'threshold' => $th, 'dir' => null];
    }
    $over = $v < $min ? ($min - $v) : ($v - $max);
    $dir = $v < $min ? 'below' : 'above';
    if ($over <= $span) {
        return ['status' => 'warning', 'label' => 'Peringatan', 'threshold' => $th, 'dir' => $dir];
    }
    return ['status' => 'critical', 'label' => 'Kritis', 'threshold' => $th, 'dir' => $dir];
}

function evaluate_value(string $param, ?float $val): string
{
    return evaluate_value_info($param, $val)['status'];
}

function maybe_create_alert(int $deviceId, string $param, float $value, bool $isSimulated = false): bool
{
    $ev = evaluate_value_info($param, $value);
    if ($ev['status'] === 'normal' || empty($ev['threshold']) || empty($ev['dir'])) {
        return false;
    }
    $cutoff = date('Y-m-d H:i:s', time() - 15 * 60);
    $chk = db()->prepare(
        "SELECT id FROM alerts WHERE device_id = ? AND parameter = ? AND status = 'open' AND occurred_at >= ? LIMIT 1"
    );
    $chk->execute([$deviceId, $param, $cutoff]);
    if ($chk->fetchColumn()) {
        return false;
    }

    $th = $ev['threshold'];
    $lim = $ev['dir'] === 'below'
        ? ($th['min_value'] !== null ? (float)$th['min_value'] : null)
        : ($th['max_value'] !== null ? (float)$th['max_value'] : null);
    $sev = $ev['status'] === 'critical' ? 'critical' : 'warning';
    $unit = (string)($th['unit'] ?? '');
    $title = param_label($param)
        . ($ev['dir'] === 'below' ? ' di bawah batas ' : ' di atas batas ')
        . ($sev === 'critical' ? 'kritis' : 'normal');
    $msg = 'Nilai terukur ' . fmt_num($value, 2)
        . ($unit !== '' ? ' ' . $unit : '')
        . ', batas ' . ($ev['dir'] === 'below' ? 'minimum' : 'maksimum')
        . ' ' . fmt_num($lim, 2) . '.';
    $now = date('Y-m-d H:i:s');

    db()->prepare(
        "INSERT INTO alerts (device_id, parameter, severity, title, message, actual_value, threshold_value, occurred_at, status, is_simulated, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open', ?, ?)"
    )->execute([
        $deviceId,
        $param,
        $sev,
        $title,
        $msg,
        $value,
        $lim,
        $now,
        $isSimulated ? 1 : 0,
        $now,
    ]);
    return true;
}

function date_range_from_query(array $query, ?string $defaultFrom = null, ?string $defaultTo = null): array
{
    $defFrom = $defaultFrom ?? date('Y-m-d', time() - 7 * 86400);
    $defTo = $defaultTo ?? date('Y-m-d');
    $from = isset($query['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$query['from'])
        ? (string)$query['from']
        : $defFrom;
    $to = isset($query['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$query['to'])
        ? (string)$query['to']
        : $defTo;
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    return [$from, $to];
}

function qs_merge(array $overrides, ?array $base = null): string
{
    $merged = array_merge($base ?? $_GET, $overrides);
    unset($merged['export']);
    $out = [];
    foreach ($merged as $k => $v) {
        if ($v !== null && $v !== '') {
            $out[$k] = (string)$v;
        }
    }
    return '?' . http_build_query($out);
}

function send_csv_download(string $filename, array $headers, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    if ($out) {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
    }
    exit;
}

function json_raw(array $payload, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_response(bool $ok, mixed $dataOrError, int $code = 200): never
{
    if ($ok) {
        json_raw(['ok' => true, 'data' => $dataOrError], $code);
    } else {
        json_raw(['ok' => false, 'error' => $dataOrError], $code);
    }
}
