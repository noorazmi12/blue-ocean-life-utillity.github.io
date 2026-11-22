<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login_api();

$view = (string)($_GET['view'] ?? 'latest');
$allowed = ['temp_sensor_c', 'ph', 'volt_ph_v'];

$dev = db()->query("SELECT id, device_code, device_name FROM devices WHERE device_type = 'water_sensor' AND is_active = 1 ORDER BY id ASC LIMIT 1")->fetch();
if (!$dev) {
    json_response(true, ['points' => [], 'latest' => null, 'status' => 'offline', 'device' => null]);
}

$devId = (int)$dev['id'];
$devMeta = ['id' => $devId, 'code' => (string)$dev['device_code'], 'name' => (string)$dev['device_name']];

if ($view === 'latest') {
    $stmt = db()->prepare(
        'SELECT measured_at, temp_sensor_c, ph, volt_ph_v, is_simulated
         FROM water_measurements WHERE device_id = ? ORDER BY measured_at DESC, id DESC LIMIT 1'
    );
    $stmt->execute([$devId]);
    $r = $stmt->fetch();
    $latest = $r ? [
        'measured_at' => (string)$r['measured_at'],
        'temp_sensor_c' => $r['temp_sensor_c'] !== null ? (float)$r['temp_sensor_c'] : null,
        'ph' => $r['ph'] !== null ? (float)$r['ph'] : null,
        'volt_ph_v' => $r['volt_ph_v'] !== null ? (float)$r['volt_ph_v'] : null,
        'is_simulated' => (int)$r['is_simulated'],
    ] : null;
    $out = [
        'device' => $devMeta,
        'latest' => $latest,
        'status' => data_status($r['measured_at'] ?? null),
    ];
    if ($latest) {
        $evals = [];
        foreach (['temp_sensor_c', 'ph'] as $p) {
            if ($latest[$p] !== null) {
                $ev = evaluate_value_info($p, $latest[$p]);
                $evals[$p] = ['status' => $ev['status'], 'label' => $ev['label']];
            }
        }
        $out['evaluation'] = $evals;
    }
    json_response(true, $out);
}

if ($view === 'range') {
    $param = (string)($_GET['param'] ?? 'ph');
    if (!in_array($param, $allowed, true)) {
        json_response(false, 'Parameter tidak dikenal.', 400);
    }
    $minutes = max(5, min(20160, (int)($_GET['minutes'] ?? 1440) ?: 1440));
    $cutoff = date('Y-m-d H:i:s', time() - $minutes * 60);

    $stmt = db()->prepare(
        "SELECT measured_at, {$param} AS val
         FROM water_measurements
         WHERE device_id = ? AND measured_at >= ? AND {$param} IS NOT NULL
         ORDER BY measured_at DESC, id DESC LIMIT 3000"
    );
    $stmt->execute([$devId, $cutoff]);
    $rows = array_reverse($stmt->fetchAll());

    $points = [];
    foreach ($rows as $r) {
        $points[] = [
            't' => fmt_time_his($r['measured_at']),
            'v' => (float)$r['val'],
        ];
    }

    $lStmt = db()->prepare(
        "SELECT measured_at, {$param} AS val FROM water_measurements WHERE device_id = ? ORDER BY measured_at DESC, id DESC LIMIT 1"
    );
    $lStmt->execute([$devId]);
    $last = $lStmt->fetch();

    json_response(true, [
        'device' => $devMeta,
        'param' => $param,
        'points' => $points,
        'latest' => $last ? [
            'measured_at' => (string)$last['measured_at'],
            'v' => $last['val'] !== null ? (float)$last['val'] : null,
        ] : null,
        'status' => data_status($last['measured_at'] ?? null),
    ]);
}

if ($view === 'multi') {
    $minutes = max(5, min(10080, (int)($_GET['minutes'] ?? 1440) ?: 1440));
    $cutoff = date('Y-m-d H:i:s', time() - $minutes * 60);
    $stmt = db()->prepare(
        'SELECT measured_at, temp_sensor_c, ph, volt_ph_v
         FROM water_measurements
         WHERE device_id = ? AND measured_at >= ?
         ORDER BY measured_at ASC LIMIT 3000'
    );
    $stmt->execute([$devId, $cutoff]);
    $series = [];
    foreach ($stmt->fetchAll() as $r) {
        $t = fmt_time_hi($r['measured_at']);
        foreach ($allowed as $p) {
            if ($r[$p] !== null) {
                $series[$p][] = ['t' => $t, 'v' => (float)$r[$p]];
            }
        }
    }
    json_response(true, ['series' => $series]);
}

json_response(false, 'Parameter view tidak dikenal.', 400);
