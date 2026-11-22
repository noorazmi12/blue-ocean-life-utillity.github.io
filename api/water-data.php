<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login_api();

$view = (string)($_GET['view'] ?? 'latest');
$pdo = db();

if ($view === 'latest') {
    $dev = $pdo->query("SELECT id, device_code, device_name FROM devices WHERE device_type='water_sensor' AND is_active=1 ORDER BY id LIMIT 1")->fetch();
    $latest = $pdo->query('SELECT measured_at, temp_sensor_c, sst_c, salinity_psu, ph, turbidity_ntu, depth_m FROM water_measurements ORDER BY measured_at DESC, id DESC LIMIT 1')->fetch();
    $ph = $latest && $latest['ph'] !== null ? (float)$latest['ph'] : null;
    if ($latest) {
        $latest['volt_ph_v'] = $ph !== null ? round(1.5 + (7.0 - $ph) * 0.17, 3) : null;
    }
    json_response(true, [
        'device' => $dev ? ['id' => (int)$dev['id'], 'code' => $dev['device_code'], 'name' => $dev['device_name']] : null,
        'latest' => $latest ?: null,
        'status' => $latest ? data_status($latest['measured_at']) : 'offline',
    ]);
}

if ($view === 'range') {
    $minutes = max(10, min(43200, (int)($_GET['minutes'] ?? 1440)));
    $param = (string)($_GET['param'] ?? 'temp_sensor_c');
    $allowed = ['temp_sensor_c', 'sst_c', 'salinity_psu', 'ph', 'turbidity_ntu', 'depth_m'];
    if (!in_array($param, $allowed, true)) {
        $param = 'temp_sensor_c';
    }
    $stmt = $pdo->prepare("SELECT measured_at, {$param} AS val FROM water_measurements WHERE measured_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE) ORDER BY measured_at ASC LIMIT 500");
    $stmt->execute([$minutes]);
    $pts = [];
    foreach ($stmt->fetchAll() as $r) {
        $pts[] = [
            't' => date('H:i', strtotime($r['measured_at'])),
            'dt' => $r['measured_at'],
            'v' => $r['val'] !== null ? (float)$r['val'] : null,
        ];
    }
    json_response(true, ['param' => $param, 'points' => $pts]);
}

json_response(false, 'Parameter view tidak valid.', 400);
