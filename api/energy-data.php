<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login_api();

$view = (string)($_GET['view'] ?? 'latest');
$dev = db()->query("SELECT id, device_code, device_name FROM devices WHERE device_type = 'owc' AND is_active = 1 ORDER BY id ASC LIMIT 1")->fetch();
if (!$dev) {
    json_response(true, ['points' => [], 'latest' => null, 'status' => 'offline', 'device' => null]);
}

$devId = (int)$dev['id'];
$devMeta = ['id' => $devId, 'code' => (string)$dev['device_code'], 'name' => (string)$dev['device_name']];

if ($view === 'latest') {
    $stmt = db()->prepare('SELECT * FROM owc_measurements WHERE device_id = ? ORDER BY measured_at DESC, id DESC LIMIT 1');
    $stmt->execute([$devId]);
    $r = $stmt->fetch();
    json_response(true, [
        'device' => $devMeta,
        'latest' => $r ? [
            'measured_at' => (string)$r['measured_at'],
            'p' => (float)$r['power_w'],
            'v' => (float)$r['voltage_v'],
            'c' => (float)$r['current_a'],
            'e' => (float)$r['energy_wh'],
            'batt' => $r['battery_pct'] !== null ? (float)$r['battery_pct'] : null,
            'is_simulated' => (int)$r['is_simulated'],
        ] : null,
        'status' => data_status($r['measured_at'] ?? null),
    ]);
}

if ($view === 'range') {
    $minutes = max(5, min(10080, (int)($_GET['minutes'] ?? 1440) ?: 1440));
    $cutoff = date('Y-m-d H:i:s', time() - $minutes * 60);
    $waveCutoff = date('Y-m-d H:i:s', time() - ($minutes + 10) * 60);

    $stmt = db()->prepare(
        'SELECT measured_at, power_w, energy_wh, battery_pct
         FROM owc_measurements WHERE device_id = ? AND measured_at >= ?
         ORDER BY measured_at ASC LIMIT 3000'
    );
    $stmt->execute([$devId, $cutoff]);
    $rows = $stmt->fetchAll();

    $wStmt = db()->prepare('SELECT measured_at, wave_height_m, wave_period_s FROM wave_measurements WHERE measured_at >= ?');
    $wStmt->execute([$waveCutoff]);
    $waveBySec = [];
    foreach ($wStmt->fetchAll() as $w) {
        $ts = strtotime($w['measured_at']);
        if ($ts) {
            $waveBySec[$ts] = $w;
        }
    }

    $points = [];
    foreach ($rows as $r) {
        $sec = (int)strtotime($r['measured_at']);
        $est = null;
        foreach ([$sec, $sec - 300, $sec + 300, $sec - 600] as $cand) {
            if (isset($waveBySec[$cand])) {
                $w = $waveBySec[$cand];
                $est = electric_estimate_w((float)$w['wave_height_m'], (float)$w['wave_period_s']);
                break;
            }
        }
        $points[] = [
            't' => fmt_time_hi($r['measured_at']),
            'p' => (float)$r['power_w'],
            'e' => (float)$r['energy_wh'],
            'batt' => $r['battery_pct'] !== null ? (float)$r['battery_pct'] : null,
            'est' => $est,
        ];
    }

    $latest = $rows ? $rows[count($rows) - 1] : null;
    json_response(true, [
        'device' => $devMeta,
        'points' => $points,
        'latest' => $latest ? [
            'measured_at' => (string)$latest['measured_at'],
            'p' => (float)$latest['power_w'],
            'batt' => $latest['battery_pct'] !== null ? (float)$latest['battery_pct'] : null,
        ] : null,
        'status' => data_status($latest['measured_at'] ?? null),
    ]);
}

if ($view === 'daily') {
    $days = max(1, min(90, (int)($_GET['days'] ?? 7) ?: 7));
    $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
    $stmt = db()->prepare(
        'SELECT substr(measured_at, 1, 10) AS d,
                ROUND(MAX(energy_wh) - MIN(energy_wh), 1) AS energy_wh,
                MAX(power_w) AS peak_w,
                ROUND(AVG(power_w), 1) AS avg_w
         FROM owc_measurements WHERE device_id = ? AND measured_at >= ?
         GROUP BY substr(measured_at, 1, 10) ORDER BY d ASC'
    );
    $stmt->execute([$devId, $cutoff]);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $rows[] = [
            'd' => (string)$r['d'],
            'energy_wh' => (float)$r['energy_wh'],
            'peak_w' => (float)$r['peak_w'],
            'avg_w' => (float)$r['avg_w'],
        ];
    }
    json_response(true, ['rows' => $rows]);
}

if ($view === 'summary') {
    $today = date('Y-m-d');
    $tStmt = db()->prepare(
        'SELECT ROUND(MAX(energy_wh) - MIN(energy_wh), 1) AS energy_today, MAX(power_w) AS peak_today, COUNT(*) AS cnt
         FROM owc_measurements WHERE device_id = ? AND measured_at BETWEEN ? AND ?'
    );
    $tStmt->execute([$devId, $today . ' 00:00:00', $today . ' 23:59:59']);
    $tr = $tStmt->fetch();

    $cutoff3h = date('Y-m-d H:i:s', time() - 3 * 3600);
    $aStmt = db()->prepare('SELECT ROUND(AVG(power_w), 1) AS avg_w, COUNT(*) AS cnt FROM owc_measurements WHERE device_id = ? AND measured_at >= ?');
    $aStmt->execute([$devId, $cutoff3h]);
    $ar = $aStmt->fetch();

    $cntToday = (int)($tr['cnt'] ?? 0);
    $cnt3h = (int)($ar['cnt'] ?? 0);

    json_response(true, [
        'energy_today' => $cntToday > 0 ? (float)$tr['energy_today'] : null,
        'peak_today' => $cntToday > 0 ? (float)$tr['peak_today'] : null,
        'avg_power_3h' => $cnt3h > 0 ? (float)$ar['avg_w'] : null,
        'low_power_threshold_w' => setting_float('low_power_threshold_w', 150.0),
        'status' => null,
    ]);
}

json_response(false, 'Parameter view tidak dikenal.', 400);
