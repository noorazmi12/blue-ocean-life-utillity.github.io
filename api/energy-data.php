<?php
/**
 * BOLU — API Data Energi OWC
 * GET:
 *   ?view=latest                -> daya/tegangan/arus/energi/baterai terbaru
 *   ?view=range&minutes=1440    -> titik grafik (daya, estimasi potensi, energi kumulatif)
 *   ?view=daily&days=7          -> produksi harian
 *   ?view=summary               -> ringkasan hari ini
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_login_api();

$view = $_GET['view'] ?? 'latest';

$dev = db()->query("SELECT id, device_code, device_name FROM devices WHERE device_type = 'owc' AND is_active = 1 ORDER BY id LIMIT 1")->fetch();
if (!$dev) {
    json_out(['ok' => true, 'data' => ['points' => [], 'latest' => null, 'status' => 'offline', 'device' => null]]);
}

if ($view === 'latest') {
    $st = db()->prepare("SELECT * FROM owc_measurements WHERE device_id = ? ORDER BY measured_at DESC LIMIT 1");
    $st->execute([$dev['id']]);
    $r = $st->fetch();
    json_out(['ok' => true, 'data' => [
        'device' => ['id' => (int)$dev['id'], 'code' => $dev['device_code'], 'name' => $dev['device_name']],
        'latest' => $r ? [
            'measured_at' => $r['measured_at'],
            'p'    => (float)$r['power_w'],
            'v'    => (float)$r['voltage_v'],
            'c'    => (float)$r['current_a'],
            'e'    => (float)$r['energy_wh'],
            'batt' => $r['battery_pct'] !== null ? (float)$r['battery_pct'] : null,
            'is_simulated' => (int)$r['is_simulated'],
        ] : null,
        'status' => data_status($r['measured_at'] ?? null),
    ]]);
}

if ($view === 'range') {
    $minutes = max(5, min(10080, (int)($_GET['minutes'] ?? 1440)));
    $st = db()->prepare(
        "SELECT measured_at, power_w, energy_wh, battery_pct
         FROM owc_measurements WHERE device_id = ? AND measured_at >= (NOW() - INTERVAL {$minutes} MINUTE)
         ORDER BY measured_at ASC LIMIT 3000"
    );
    $st->execute([$dev['id']]);
    $rows = $st->fetchAll();

    // Estimasi potensi gelombang pada waktu terdekat (toleransi 5 menit)
    $waveRows = db()->query(
        "SELECT measured_at, wave_height_m, wave_period_s FROM wave_measurements
         WHERE measured_at >= (NOW() - INTERVAL " . ($minutes + 10) . " MINUTE)
         ORDER BY measured_at ASC LIMIT 3000"
    )->fetchAll();
    $waveByTime = [];
    foreach ($waveRows as $w) {
        $waveByTime[strtotime($w['measured_at'])] = $w;
    }

    $points = [];
    foreach ($rows as $r) {
        $ts = strtotime($r['measured_at']);
        $est = null;
        foreach ([$ts, $ts - 300, $ts + 300, $ts - 600] as $cand) {
            if (isset($waveByTime[$cand])) {
                $w = $waveByTime[$cand];
                $width = setting_float('owc_capture_width_m', 3.0);
                $eff = setting_float('owc_efficiency', 0.30);
                $est = round(wave_power_kw($w['wave_height_m'], $w['wave_period_s']) * 1000 * $width * $eff, 1);
                break;
            }
        }
        $points[] = [
            't'   => date('H:i', strtotime($r['measured_at'])),
            'p'   => (float)$r['power_w'],
            'e'   => (float)$r['energy_wh'],
            'batt'=> $r['battery_pct'] !== null ? (float)$r['battery_pct'] : null,
            'est' => $est,
        ];
    }
    $latest = $rows ? end($rows) : null;
    json_out(['ok' => true, 'data' => [
        'device' => ['id' => (int)$dev['id'], 'code' => $dev['device_code'], 'name' => $dev['device_name']],
        'points' => $points,
        'latest' => $latest ? ['measured_at' => $latest['measured_at'], 'p' => (float)$latest['power_w'], 'batt' => $latest['battery_pct'] !== null ? (float)$latest['battery_pct'] : null] : null,
        'status' => data_status($latest['measured_at'] ?? null),
    ]]);
}

if ($view === 'daily') {
    $days = max(1, min(90, (int)($_GET['days'] ?? 7)));
    $st = db()->prepare(
        "SELECT DATE(measured_at) AS d,
                ROUND(MAX(energy_wh) - MIN(energy_wh), 1) AS energy_wh,
                MAX(power_w) AS peak_w,
                ROUND(AVG(power_w), 1) AS avg_w
         FROM owc_measurements
         WHERE device_id = ? AND measured_at >= (NOW() - INTERVAL {$days} DAY)
         GROUP BY DATE(measured_at) ORDER BY d ASC"
    );
    $st->execute([$dev['id']]);
    json_out(['ok' => true, 'data' => ['rows' => $st->fetchAll()]]);
}

if ($view === 'summary') {
    $st = db()->prepare(
        "SELECT ROUND(MAX(energy_wh) - MIN(energy_wh), 1) AS energy_today,
                MAX(power_w) AS peak_today
         FROM owc_measurements WHERE device_id = ? AND measured_at >= CURDATE()"
    );
    $st->execute([$dev['id']]);
    $today = $st->fetch();
    $st2 = db()->prepare("SELECT AVG(power_w) AS a FROM owc_measurements WHERE device_id = ? AND measured_at >= (NOW() - INTERVAL 3 HOUR)");
    $st2->execute([$dev['id']]);
    $avg3h = $st2->fetch()['a'];
    json_out(['ok' => true, 'data' => [
        'energy_today' => $today['energy_today'] !== null ? (float)$today['energy_today'] : null,
        'peak_today'   => $today['peak_today'] !== null ? (float)$today['peak_today'] : null,
        'avg_power_3h' => $avg3h !== null ? round((float)$avg3h, 1) : null,
        'low_power_threshold_w' => setting_float('low_power_threshold_w', 150),
        'status' => null,
    ]]);
}

json_fail('Parameter view tidak dikenal.', 400);
