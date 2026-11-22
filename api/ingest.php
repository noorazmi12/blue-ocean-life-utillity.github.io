<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Gunakan metode POST.', 405);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);
if (!is_array($body)) {
    json_response(false, 'Payload JSON tidak valid.', 400);
}

$code = trim((string)($body['device_code'] ?? ''));
$apiKey = trim((string)($body['api_key'] ?? ''));
$data = $body['data'] ?? null;
if ($code === '' || $apiKey === '' || !is_array($data)) {
    json_response(false, 'device_code, api_key, dan data wajib diisi.', 400);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM devices WHERE device_code = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$code]);
$dev = $stmt->fetch();
if (!$dev || !$dev['api_token'] || !hash_equals((string)$dev['api_token'], hash('sha256', $apiKey))) {
    json_response(false, 'Autentikasi perangkat gagal.', 401);
}

$ts = trim((string)($body['timestamp'] ?? ''));
$measuredAt = ($ts !== '' && strtotime($ts)) ? date('Y-m-d H:i:s', strtotime($ts)) : date('Y-m-d H:i:s');
$devId = (int)$dev['id'];
$inserted = 0;

if ($dev['device_type'] === 'water_sensor') {
    $temp = isset($data['temp_sensor_c']) && is_numeric($data['temp_sensor_c']) ? (float)$data['temp_sensor_c'] : null;
    $ph = isset($data['ph']) && is_numeric($data['ph']) && (float)$data['ph'] > 0 ? (float)$data['ph'] : null;
    $ins = $pdo->prepare('INSERT INTO water_measurements (device_id, measured_at, temp_sensor_c, sst_c, salinity_psu, ph, turbidity_ntu, depth_m, is_simulated) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)');
    $ins->execute([
        $devId,
        $measuredAt,
        $temp,
        $data['sst_c'] ?? null,
        $data['salinity_psu'] ?? null,
        $ph,
        $data['turbidity_ntu'] ?? null,
        $data['depth_m'] ?? null,
    ]);
    $inserted = 1;
} elseif ($dev['device_type'] === 'owc') {
    $ins = $pdo->prepare('INSERT INTO owc_measurements (device_id, measured_at, power_w, voltage_v, current_a, energy_wh, battery_pct, is_simulated) VALUES (?, ?, ?, ?, ?, ?, ?, 0)');
    $ins->execute([
        $devId,
        $measuredAt,
        (float)($data['power_w'] ?? 0),
        (float)($data['voltage_v'] ?? 0),
        (float)($data['current_a'] ?? 0),
        (float)($data['energy_wh'] ?? 0),
        $data['battery_pct'] ?? null,
    ]);
    $inserted = 1;
} elseif ($dev['device_type'] === 'wave_sensor') {
    $ins = $pdo->prepare('INSERT INTO wave_measurements (device_id, measured_at, wave_height_m, wave_period_s, wave_direction_deg, wind_speed_ms, wind_direction_deg, is_simulated) VALUES (?, ?, ?, ?, ?, ?, ?, 0)');
    $ins->execute([
        $devId,
        $measuredAt,
        (float)($data['wave_height_m'] ?? 0),
        (float)($data['wave_period_s'] ?? 0),
        $data['wave_direction_deg'] ?? null,
        $data['wind_speed_ms'] ?? null,
        $data['wind_direction_deg'] ?? null,
    ]);
    $inserted = 1;
}

$pdo->prepare("UPDATE devices SET status='online', last_communication=NOW() WHERE id=?")->execute([$devId]);

json_response(true, [
    'id' => (int)$pdo->lastInsertId(),
    'device' => $code,
    'inserted' => $inserted,
    'message' => 'Data diterima dan disimpan.',
]);
