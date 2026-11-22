<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login_api();

$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can_manage()) {
        json_response(false, 'Hak akses tidak mencukupi.', 403);
    }
    $lat = (float)($_POST['latitude'] ?? 0);
    $lng = (float)($_POST['longitude'] ?? 0);
    $name = trim((string)($_POST['location_name'] ?? 'Kamera KJA'));
    $cam = $pdo->query("SELECT id FROM devices WHERE device_type='camera' AND is_active=1 ORDER BY id LIMIT 1")->fetch();
    if (!$cam) {
        json_response(false, 'Perangkat kamera tidak ditemukan.', 404);
    }
    $devId = (int)$cam['id'];
    $exists = $pdo->prepare('SELECT id FROM device_locations WHERE device_id=? LIMIT 1');
    $exists->execute([$devId]);
    if ($exists->fetch()) {
        $pdo->prepare('UPDATE device_locations SET latitude=?, longitude=?, location_name=?, updated_at=NOW() WHERE device_id=?')
            ->execute([$lat, $lng, $name, $devId]);
    } else {
        $pdo->prepare('INSERT INTO device_locations (device_id, latitude, longitude, location_name, installed_at, updated_at) VALUES (?, ?, ?, ?, CURDATE(), NOW())')
            ->execute([$devId, $lat, $lng, $name]);
    }
    json_response(true, ['device_id' => $devId, 'latitude' => $lat, 'longitude' => $lng, 'location_name' => $name]);
}

$cam = $pdo->query(
    "SELECT d.id, d.device_code, d.device_name, l.latitude, l.longitude, l.location_name
     FROM devices d LEFT JOIN device_locations l ON l.device_id = d.id
     WHERE d.device_type='camera' AND d.is_active=1 ORDER BY d.id LIMIT 1"
)->fetch();
json_response(true, $cam ?: null);
