<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login_api();

$view = (string)($_GET['view'] ?? 'latest');
$pdo = db();

if ($view === 'latest') {
    $dev = $pdo->query("SELECT id, device_code, device_name FROM devices WHERE device_type='owc' AND is_active=1 ORDER BY id LIMIT 1")->fetch();
    $row = $pdo->query('SELECT measured_at, power_w, voltage_v, current_a, energy_wh, battery_pct FROM owc_measurements ORDER BY measured_at DESC, id DESC LIMIT 1')->fetch();
    json_response(true, [
        'device' => $dev ? ['id' => (int)$dev['id'], 'code' => $dev['device_code'], 'name' => $dev['device_name']] : null,
        'latest' => $row ?: null,
        'status' => $row ? data_status($row['measured_at']) : 'offline',
    ]);
}

if ($view === 'daily') {
    $days = max(1, min(90, (int)($_GET['days'] ?? 7)));
    $stmt = $pdo->prepare('SELECT DATE(measured_at) AS d, SUM(energy_wh) AS energy_wh, MAX(power_w) AS peak_w, AVG(power_w) AS avg_w FROM owc_measurements WHERE measured_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY) GROUP BY DATE(measured_at) ORDER BY d ASC');
    $stmt->execute([$days]);
    json_response(true, ['rows' => $stmt->fetchAll()]);
}

if ($view === 'range') {
    $minutes = max(10, min(43200, (int)($_GET['minutes'] ?? 1440)));
    $stmt = $pdo->prepare('SELECT measured_at, power_w, voltage_v, current_a, energy_wh, battery_pct FROM owc_measurements WHERE measured_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE) ORDER BY measured_at ASC LIMIT 500');
    $stmt->execute([$minutes]);
    $pts = [];
    foreach ($stmt->fetchAll() as $r) {
        $pts[] = [
            't' => date('H:i', strtotime($r['measured_at'])),
            'dt' => $r['measured_at'],
            'pw' => (float)$r['power_w'],
            'v' => (float)$r['voltage_v'],
            'a' => (float)$r['current_a'],
            'wh' => (float)$r['energy_wh'],
            'bat' => $r['battery_pct'] !== null ? (float)$r['battery_pct'] : null,
        ];
    }
    json_response(true, ['points' => $pts]);
}

json_response(false, 'Parameter view tidak valid.', 400);
