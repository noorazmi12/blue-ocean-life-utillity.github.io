<?php
/**
 * BOLU — API Data Kualitas Air
 * GET:
 *   ?view=latest                                   -> pengukuran terbaru + evaluasi ambang
 *   ?view=range&param=ph&minutes=1440              -> titik grafik satu parameter
 *   ?view=multi&minutes=1440                       -> beberapa parameter sekaligus (dashboard)
 * Parameter: temp_sensor_c|ph  (alat hanya mengukur suhu air & pH)
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_login_api();

$view = $_GET['view'] ?? 'latest';

$ALLOWED = ['temp_sensor_c', 'ph', 'volt_ph_v'];

$dev = db()->query("SELECT id, device_code, device_name FROM devices WHERE device_type = 'water_sensor' AND is_active = 1 ORDER BY id LIMIT 1")->fetch();
if (!$dev) {
    json_out(['ok' => true, 'data' => ['points' => [], 'latest' => null, 'status' => 'offline', 'device' => null]]);
}

function water_row_shape(array $r): array
{
    return [
        'measured_at' => $r['measured_at'],
        'temp_sensor_c' => $r['temp_sensor_c'] !== null ? (float)$r['temp_sensor_c'] : null,
        'ph'            => $r['ph'] !== null ? (float)$r['ph'] : null,
        'volt_ph_v'     => $r['volt_ph_v'] !== null ? (float)$r['volt_ph_v'] : null,
        'is_simulated'  => (int)$r['is_simulated'],
    ];
}

if ($view === 'latest') {
    $st = db()->prepare("SELECT * FROM water_measurements WHERE device_id = ? ORDER BY measured_at DESC LIMIT 1");
    $st->execute([$dev['id']]);
    $r = $st->fetch();
    $data = ['device' => ['id' => (int)$dev['id'], 'code' => $dev['device_code'], 'name' => $dev['device_name']],
             'latest' => $r ? water_row_shape($r) : null,
             'status' => data_status($r['measured_at'] ?? null)];
    if ($r) {
        $evals = [];
        foreach (['temp_sensor_c', 'ph'] as $p) {
            if ($r[$p] !== null) {
                $e = evaluate_value($p, $r[$p]);
                $evals[$p] = ['status' => $e['status'], 'label' => $e['label']];
            }
        }
        $data['evaluation'] = $evals;
    }
    json_out(['ok' => true, 'data' => $data]);
}

if ($view === 'range') {
    $param = $_GET['param'] ?? 'ph';
    if (!in_array($param, $ALLOWED, true)) {
        json_fail('Parameter tidak dikenal.', 400);
    }
    $minutes = max(5, min(20160, (int)($_GET['minutes'] ?? 1440)));
    $st = db()->prepare(
        "SELECT measured_at, {$param} AS v FROM water_measurements
         WHERE device_id = ? AND measured_at >= (NOW() - INTERVAL {$minutes} MINUTE)
         ORDER BY measured_at DESC, id DESC LIMIT 3000"
    );
    $st->execute([$dev['id']]);
    $rows = array_reverse($st->fetchAll());
    $points = [];
    foreach ($rows as $r) {
        if ($r['v'] === null) continue;
        $points[] = ['t' => date('H:i:s', strtotime($r['measured_at'])), 'v' => (float)$r['v']];
    }
    $st2 = db()->prepare("SELECT * FROM water_measurements WHERE device_id = ? ORDER BY measured_at DESC LIMIT 1");
    $st2->execute([$dev['id']]);
    $latest = $st2->fetch();
    json_out(['ok' => true, 'data' => [
        'device' => ['id' => (int)$dev['id'], 'code' => $dev['device_code'], 'name' => $dev['device_name']],
        'param' => $param,
        'points' => $points,
        'latest' => $latest ? ['measured_at' => $latest['measured_at'], 'v' => $latest[$param] !== null ? (float)$latest[$param] : null] : null,
        'status' => data_status($latest['measured_at'] ?? null),
    ]]);
}

if ($view === 'multi') {
    $minutes = max(5, min(10080, (int)($_GET['minutes'] ?? 1440)));
    $st = db()->prepare(
        "SELECT measured_at, temp_sensor_c, ph, volt_ph_v
         FROM water_measurements WHERE device_id = ? AND measured_at >= (NOW() - INTERVAL {$minutes} MINUTE)
         ORDER BY measured_at ASC LIMIT 3000"
    );
    $st->execute([$dev['id']]);
    $rows = $st->fetchAll();
    $series = [];
    foreach ($rows as $r) {
        $t = date('H:i', strtotime($r['measured_at']));
        foreach (['temp_sensor_c', 'ph', 'volt_ph_v'] as $p) {
            if ($r[$p] !== null) {
                $series[$p][] = ['t' => $t, 'v' => (float)$r[$p]];
            }
        }
    }
    json_out(['ok' => true, 'data' => ['series' => $series]]);
}

json_fail('Parameter view tidak dikenal.', 400);
