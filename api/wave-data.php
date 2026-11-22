<?php
/**
 * BOLU — API Data Gelombang
 * GET:
 *   ?view=latest                      -> pengukuran terbaru + status
 *   ?view=range&minutes=60            -> titik grafik + statistik rentang
 *   ?view=range&from=YYYY-MM-DD&to=.. -> rentang khusus
 * Endpoint privat: membutuhkan sesi login.
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_login_api();

$view = $_GET['view'] ?? 'latest';

/* Ambil perangkat gelombang aktif pertama */
$dev = db()->query("SELECT id, device_code, device_name FROM devices WHERE device_type = 'wave_sensor' AND is_active = 1 ORDER BY id LIMIT 1")->fetch();
if (!$dev) {
    json_out(['ok' => true, 'data' => ['points' => [], 'latest' => null, 'stats' => null, 'status' => 'offline', 'device' => null]]);
}

if ($view === 'latest') {
    $st = db()->prepare("SELECT * FROM wave_measurements WHERE device_id = ? ORDER BY measured_at DESC LIMIT 1");
    $st->execute([$dev['id']]);
    $r = $st->fetch();
    json_out(['ok' => true, 'data' => [
        'device'   => ['id' => (int)$dev['id'], 'code' => $dev['device_code'], 'name' => $dev['device_name']],
        'latest'   => $r ? [
            'measured_at' => $r['measured_at'],
            'h'  => (float)$r['wave_height_m'],
            't'  => (float)$r['wave_period_s'],
            'wd' => $r['wave_direction_deg'] !== null ? (float)$r['wave_direction_deg'] : null,
            'w'  => $r['wind_speed_ms'] !== null ? (float)$r['wind_speed_ms'] : null,
            'wind_dir' => $r['wind_direction_deg'] !== null ? (float)$r['wind_direction_deg'] : null,
            'kw' => round(wave_power_kw($r['wave_height_m'], $r['wave_period_s']), 3),
            'is_simulated' => (int)$r['is_simulated'],
        ] : null,
        'status'   => data_status($r['measured_at'] ?? null),
    ]]);
}

if ($view === 'range') {
    $from = $_GET['from'] ?? '';
    $to   = $_GET['to'] ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $fromSql = $from . ' 00:00:00';
        $toSql   = $to . ' 23:59:59';
    } else {
        $minutes = max(5, min(10080, (int)($_GET['minutes'] ?? 60)));
        $fromSql = date('Y-m-d H:i:s', time() - $minutes * 60);
        $toSql   = date('Y-m-d H:i:s');
    }
    $st = db()->prepare(
        "SELECT measured_at, wave_height_m, wave_period_s, wind_speed_ms
         FROM wave_measurements WHERE device_id = ? AND measured_at BETWEEN ? AND ?
         ORDER BY measured_at ASC LIMIT 3000"
    );
    $st->execute([$dev['id'], $fromSql, $toSql]);
    $rows = $st->fetchAll();

    $points = [];
    $hs = [];
    foreach ($rows as $r) {
        $hs[] = (float)$r['wave_height_m'];
        $points[] = [
            't' => date('H:i', strtotime($r['measured_at'])),
            'h' => (float)$r['wave_height_m'],
            'p' => (float)$r['wave_period_s'],
            'w' => $r['wind_speed_ms'] !== null ? (float)$r['wind_speed_ms'] : null,
        ];
    }
    $latest = $rows ? end($rows) : null;
    $stats = null;
    if ($hs) {
        $stats = [
            'min' => round(min($hs), 3),
            'max' => round(max($hs), 3),
            'avg' => round(array_sum($hs) / count($hs), 3),
            'count' => count($hs),
        ];
    }
    json_out(['ok' => true, 'data' => [
        'device' => ['id' => (int)$dev['id'], 'code' => $dev['device_code'], 'name' => $dev['device_name']],
        'from' => $fromSql, 'to' => $toSql,
        'points' => $points,
        'stats'  => $stats,
        'latest' => $latest ? [
            'measured_at' => $latest['measured_at'],
            'h' => (float)$latest['wave_height_m'],
            't' => (float)$latest['wave_period_s'],
            'w' => $latest['wind_speed_ms'] !== null ? (float)$latest['wind_speed_ms'] : null,
            'kw' => round(wave_power_kw($latest['wave_height_m'], $latest['wave_period_s']), 3),
        ] : null,
        'status' => data_status($latest['measured_at'] ?? null),
    ]]);
}

json_fail('Parameter view tidak dikenal.', 400);
