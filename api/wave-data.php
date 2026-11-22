<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login_api();

$view = (string)($_GET['view'] ?? 'latest');
$pdo = db();

if ($view === 'latest') {
    $dev = $pdo->query("SELECT id, device_code, device_name FROM devices WHERE device_type='wave_sensor' AND is_active=1 ORDER BY id LIMIT 1")->fetch();
    $row = $pdo->query('SELECT measured_at, wave_height_m, wave_period_s, wave_direction_deg, wind_speed_ms, wind_direction_deg FROM wave_measurements ORDER BY measured_at DESC, id DESC LIMIT 1')->fetch();
    $latest = $row ? [
        'measured_at' => $row['measured_at'],
        'h' => (float)$row['wave_height_m'],
        't' => (float)$row['wave_period_s'],
        'wd' => $row['wave_direction_deg'] !== null ? (int)$row['wave_direction_deg'] : null,
        'ws' => $row['wind_speed_ms'] !== null ? (float)$row['wind_speed_ms'] : null,
        'pw' => wave_power_kw((float)$row['wave_height_m'], (float)$row['wave_period_s']),
    ] : null;
    json_response(true, [
        'device' => $dev ? ['id' => (int)$dev['id'], 'code' => $dev['device_code'], 'name' => $dev['device_name']] : null,
        'latest' => $latest,
        'status' => $row ? data_status($row['measured_at']) : 'offline',
    ]);
}

if ($view === 'range') {
    $minutes = max(10, min(43200, (int)($_GET['minutes'] ?? 1440)));
    $stmt = $pdo->prepare('SELECT measured_at, wave_height_m, wave_period_s, wave_direction_deg, wind_speed_ms FROM wave_measurements WHERE measured_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE) ORDER BY measured_at ASC LIMIT 500');
    $stmt->execute([$minutes]);
    $pts = [];
    foreach ($stmt->fetchAll() as $r) {
        $h = (float)$r['wave_height_m'];
        $t = (float)$r['wave_period_s'];
        $pts[] = [
            't' => date('H:i', strtotime($r['measured_at'])),
            'dt' => $r['measured_at'],
            'h' => $h,
            'p' => $t,
            'ws' => $r['wind_speed_ms'] !== null ? (float)$r['wind_speed_ms'] : null,
            'pw' => wave_power_kw($h, $t),
        ];
    }
    json_response(true, ['points' => $pts]);
}

json_response(false, 'Parameter view tidak valid.', 400);
