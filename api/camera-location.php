<?php
/**
 * BOLU — API posisi pemasangan kamera FishCount.
 * GET: posisi kamera aktif pertama.
 * POST JSON: {latitude, longitude, location_name}; admin/operator only.
 */
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_login_api();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $row = db()->query(
        "SELECT d.id, d.device_code, d.device_name, l.latitude, l.longitude, l.location_name
         FROM devices d
         LEFT JOIN device_locations l ON l.device_id = d.id
         WHERE d.device_type='camera' AND d.is_active=1
         ORDER BY d.id LIMIT 1"
    )->fetch();
    json_out(['ok' => true, 'data' => $row ?: null]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('Metode tidak didukung.', 405);
}
csrf_check();
if (!can_manage()) json_fail('Hanya admin/operator yang dapat mengubah posisi kamera.', 403);

$in = json_input();
$lat = filter_var($in['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$lon = filter_var($in['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
$name = trim((string)($in['location_name'] ?? ''));
if ($lat === false || $lat === null || $lat < -90 || $lat > 90 ||
    $lon === false || $lon === null || $lon < -180 || $lon > 180) {
    json_fail('Koordinat latitude/longitude tidak valid.', 400);
}
if ($name === '') $name = 'Titik pemasangan kamera';
$name = mb_substr($name, 0, 120);

$camera = db()->query(
    "SELECT id FROM devices WHERE device_type='camera' AND is_active=1 ORDER BY id LIMIT 1"
)->fetch();
if (!$camera) json_fail('Perangkat bertipe camera belum terdaftar.', 404);

$st = db()->prepare(
    'INSERT INTO device_locations (device_id, latitude, longitude, location_name, notes)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE latitude=VALUES(latitude), longitude=VALUES(longitude),
       location_name=VALUES(location_name), updated_at=CURRENT_TIMESTAMP'
);
$st->execute([(int)$camera['id'], $lat, $lon, $name, 'Posisi pemasangan ESP32-CAM FishCount']);
json_out(['ok' => true, 'message' => 'Posisi kamera berhasil disimpan.', 'data' => [
    'device_id' => (int)$camera['id'], 'latitude' => $lat, 'longitude' => $lon, 'location_name' => $name
]]);
