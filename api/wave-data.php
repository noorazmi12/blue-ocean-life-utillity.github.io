<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login_api();

$view = (string)($_GET['view'] ?? 'latest');
$dev = db()->query("SELECT id, device_code, device_name FROM devices WHERE device_type = 'wave_sensor' AND is_active = 1 ORDER BY id ASC LIMIT 1")->fetch();
if (!$dev) {
    json_response(true, ['points' => [], 'latest' => null, 'stats' => null, 'status' => 'offline', 'device' => null]);
}

$devId = (int)$dev['id'];
$devMeta = ['id' => $devId, 'code' => (string)$dev['device_code'], 'name' => (string)$dev['device_name']];

if ($view === 'latest') {
    $stmt = db()->prepare('SELECT * FROM wave_measurements WHERE device_id = ? ORDER BY measured_at DESC, id DESC LIMIT 1');
    $stmt->execute([$devId]);
    $r = $stmt->fetch();
    json_response(true, [
        'device' => $devMeta,
        'latest' => $r ? [
            'measured_at' => (string)$r['measured_at'],
            'h' => (float)$r['wave_height_m'],
            't' => (float)$r['wave_period_s'],
            'wd' => $r['wave_direction_deg'] !== null ? (int)$r['wave_direction_deg'] : null,
            'w' => $r['wind_speed_ms'] !== null ? (float)$r['wind_speed_ms'] : null,
            'wind_dir' => $r['wind_direction_deg'] !== null ? (int)$r['wind_direction_deg'] : null,
            'kw' => wave_power_kw((float)$r['wave_height_m'], (float)$r['wave_period_s']),
            'is_simulated' => (int)$r['is_simulated'],
        ] : null,
        'status' => data_status($r['measured_at'] ?? null),
    ]);
}

if ($view === 'range') {
    $from = (string)($_GET['from'] ?? '');
    $to = (string)($_GET['to'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $fromSql = $from . ' 00:00:00';
        $toSql = $to . ' 23:59:59';
    } else {
        $minutes = max(5, min(10080, (int)($_GET['minutes'] ?? 60) ?: 60));
        $fromSql = date('Y-m-d H:i:s', time() - $minutes * 60);
        $toSql = date('Y-m-d H:i:s');
    }

    $stmt = db()->prepare(
        'SELECT measured_at, wave_height_m, wave_period_s, wind_speed_ms
         FROM wave_measurements
         WHERE device_id = ? AND measured_at BETWEEN ? AND ?
         ORDER BY measured_at ASC LIMIT 3000'
    );
    $stmt->execute([$devId, $fromSql, $toSql]);
    $rows = $stmt->fetchAll();

    $hs = [];
    $points = [];
    foreach ($rows as $r) {
        $h = (float)$r['wave_height_m'];
        $hs[] = $h;
        $points[] = [
            't' => fmt_time_hi($r['measured_at']),
            'h' => $h,
            'p' => (float)$r['wave_period_s'],
            'w' => $r['wind_speed_ms'] !== null ? (float)$r['wind_speed_ms'] : null,
        ];
    }

    $latestRow = $rows ? $rows[count($rows) - 1] : null;
    $stats = $hs ? [
        'min' => round(min($hs), 3),
        'max' => round(max($hs), 3),
        'avg' => round(array_sum($hs) / count($hs), 3),
        'count' => count($hs),
    ] : null;

    json_response(true, [
        'device' => $devMeta,
        'from' => $fromSql,
        'to' => $toSql,
        'points' => $points,
        'stats' => $stats,
        'latest' => $latestRow ? [
            'measured_at' => (string)$latestRow['measured_at'],
            'h' => (float)$latestRow['wave_height_m'],
            't' => (float)$latestRow['wave_period_s'],
            'w' => $latestRow['wind_speed_ms'] !== null ? (float)$latestRow['wind_speed_ms'] : null,
            'kw' => wave_power_kw((float)$latestRow['wave_height_m'], (float)$latestRow['wave_period_s']),
        ] : null,
        'status' => data_status($latestRow['measured_at'] ?? null),
    ]);
}

json_response(false, 'Parameter view tidak dikenal.', 400);
