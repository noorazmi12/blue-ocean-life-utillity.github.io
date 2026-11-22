<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function e(mixed $v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function safe_json(mixed $v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}';
}

function setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $rows = db()->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll();
            foreach ($rows as $r) {
                $cache[(string)$r['setting_key']] = (string)($r['setting_value'] ?? '');
            }
        } catch (Throwable) {
            return $default;
        }
    }
    return array_key_exists($key, $cache) && $cache[$key] !== '' ? $cache[$key] : $default;
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
    $sql = 'INSERT INTO system_settings (setting_key, setting_value, description, updated_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()';
    db()->prepare($sql)->execute([$key, $val, $desc]);
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
    return $ts ? date('d M Y, H:i:s', $ts) : $dt;
}

function fmt_dt_short(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $ts = strtotime($dt);
    return $ts ? date('d/m H:i', $ts) : $dt;
}

function time_ago(?string $dt): string
{
    if (!$dt) {
        return 'belum ada data';
    }
    $ts = strtotime($dt);
    if (!$ts) {
        return '—';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'baru saja';
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
    $ageSec = time() - $ts;
    if ($ageSec <= $stale * 60) {
        return 'live';
    }
    if ($ageSec <= $stale * 180) {
        return 'delayed';
    }
    return 'offline';
}

function status_pill(string $st): string
{
    return match ($st) {
        'live' => '<span class="pill pill-live"><span class="dot dot-live"></span> LIVE</span>',
        'delayed' => '<span class="pill pill-delayed"><span class="dot dot-delayed"></span> DELAYED</span>',
        default => '<span class="pill pill-offline"><span class="dot dot-offline"></span> OFFLINE</span>',
    };
}

function device_status_badge(string $st): string
{
    return match ($st) {
        'online' => '<span class="badge badge-green">Online</span>',
        'maintenance' => '<span class="badge badge-yellow">Perawatan</span>',
        default => '<span class="badge badge-red">Offline</span>',
    };
}

function severity_badge(string $sev): string
{
    return match ($sev) {
        'critical' => '<span class="badge badge-red">Kritis</span>',
        'warning' => '<span class="badge badge-yellow">Peringatan</span>',
        default => '<span class="badge badge-blue">Info</span>',
    };
}

function param_label(string $param): string
{
    $map = [
        'temp_sensor_c' => 'Suhu Air (Sensor)',
        'sst_c' => 'Suhu Permukaan Laut (SST)',
        'ph' => 'pH Air',
        'wave_height_m' => 'Tinggi Gelombang',
        'wave_period_s' => 'Periode Gelombang',
        'power_w' => 'Daya OWC',
        'voltage_v' => 'Tegangan OWC',
        'current_a' => 'Arus OWC',
    ];
    return $map[$param] ?? $param;
}

function deg_to_compass(?float $deg): string
{
    if ($deg === null) {
        return '—';
    }
    $dirs = ['U', 'UTL', 'TL', 'TTL', 'T', 'TGG', 'TG', 'STG', 'S', 'SBD', 'BD', 'BBD', 'B', 'BBL', 'BL', 'UBL'];
    return $dirs[(int)round((fmod($deg, 360) + 360) % 360 / 22.5) % 16];
}

function wave_power_kw(?float $h, ?float $t, ?float $widthM = null, ?float $eff = null): float
{
    if (!$h || !$t || $h <= 0 || $t <= 0) {
        return 0.0;
    }
    $w = $widthM ?? setting_float('owc_chamber_width_m', 2.0);
    $e = $eff ?? setting_float('owc_efficiency', 0.35);
    return round(0.49 * $h * $h * $t * $w * $e, 3);
}

function evaluate_value(string $param, ?float $val): string
{
    if ($val === null) {
        return 'none';
    }
    $sp = setting('active_species', 'Umum') ?: 'Umum';
    $stmt = db()->prepare('SELECT min_value, max_value, warn_span FROM thresholds WHERE parameter = ? AND species IN (?, "Umum") ORDER BY (species = ?) DESC LIMIT 1');
    $stmt->execute([$param, $sp, $sp]);
    $th = $stmt->fetch();
    if (!$th) {
        return 'normal';
    }
    $min = $th['min_value'] !== null ? (float)$th['min_value'] : null;
    $max = $th['max_value'] !== null ? (float)$th['max_value'] : null;
    $span = $th['warn_span'] !== null ? (float)$th['warn_span'] : 0.0;
    if (($min !== null && $val < $min) || ($max !== null && $val > $max)) {
        return 'critical';
    }
    if (($min !== null && $val < $min + $span) || ($max !== null && $val > $max - $span)) {
        return 'warning';
    }
    return 'normal';
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

function json_response(bool $ok, mixed $dataOrError, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    if ($ok) {
        echo json_encode(['ok' => true, 'data' => $dataOrError], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => false, 'error' => $dataOrError], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
