<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login_api();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $camStmt = db()->prepare("SELECT id, device_code, device_name FROM devices WHERE device_type = 'camera' AND is_active = 1 ORDER BY id ASC LIMIT 1");
    $camStmt->execute();
    $cam = $camStmt->fetch();
    if (!$cam) {
        json_raw(['ok' => true, 'data' => null]);
    }

    $locStmt = db()->prepare('SELECT latitude, longitude, location_name FROM device_locations WHERE device_id = ? LIMIT 1');
    $locStmt->execute([(int)$cam['id']]);
    $loc = $locStmt->fetch();

    json_raw([
        'ok' => true,
        'data' => [
            'id' => (int)$cam['id'],
            'device_code' => $cam['device_code'],
            'device_name' => $cam['device_name'],
            'latitude' => $loc ? (float)$loc['latitude'] : null,
            'longitude' => $loc ? (float)$loc['longitude'] : null,
            'location_name' => $loc ? $loc['location_name'] : null,
        ],
    ]);
}

if ($method === 'POST') {
    csrf_check();
    if (!can_manage()) {
        json_raw(['ok' => false, 'error' => 'Hanya admin/operator yang dapat mengubah posisi kamera.'], 403);
    }

    $rawBody = file_get_contents('php://input');
    $body = json_decode($rawBody ?: '', true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    $latRaw = $body['latitude'] ?? null;
    $lonRaw = $body['longitude'] ?? null;
    $lat = is_numeric($latRaw) ? (float)$latRaw : NAN;
    $lon = is_numeric($lonRaw) ? (float)$lonRaw : NAN;
    if (!is_finite($lat) || $lat < -90 || $lat > 90 || !is_finite($lon) || $lon < -180 || $lon > 180) {
        json_raw(['ok' => false, 'error' => 'Koordinat latitude/longitude tidak valid.'], 400);
    }

    $name = trim((string)($body['location_name'] ?? ''));
    if ($name === '') {
        $name = 'Titik pemasangan kamera';
    }
    $name = str_trim_width($name, 120);

    $camStmt = db()->prepare("SELECT id FROM devices WHERE device_type = 'camera' AND is_active = 1 ORDER BY id ASC LIMIT 1");
    $camStmt->execute();
    $cam = $camStmt->fetch();
    if (!$cam) {
        json_raw(['ok' => false, 'error' => 'Perangkat bertipe camera belum terdaftar.'], 404);
    }
    $camId = (int)$cam['id'];
    $now = date('Y-m-d H:i:s');

    $chk = db()->prepare('SELECT id FROM device_locations WHERE device_id = ? LIMIT 1');
    $chk->execute([$camId]);
    $existing = $chk->fetch();
    if ($existing) {
        db()->prepare('UPDATE device_locations SET latitude = ?, longitude = ?, location_name = ?, updated_at = ? WHERE id = ?')
            ->execute([$lat, $lon, $name, $now, (int)$existing['id']]);
    } else {
        db()->prepare(
            'INSERT INTO device_locations (device_id, latitude, longitude, location_name, installed_at, notes, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$camId, $lat, $lon, $name, date('Y-m-d'), 'Posisi pemasangan ESP32-CAM FishCount', $now]);
    }

    json_raw([
        'ok' => true,
        'message' => 'Posisi kamera berhasil disimpan.',
        'data' => [
            'device_id' => $camId,
            'latitude' => $lat,
            'longitude' => $lon,
            'location_name' => $name,
        ],
    ]);
}

json_raw(['ok' => false, 'error' => 'Metode tidak didukung.'], 405);
