<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login_api();

$pdo = db();
$rows = $pdo->query(
    'SELECT d.id, d.device_code AS code, d.device_name AS name, d.device_type AS type,
            d.status, d.last_communication, l.latitude, l.longitude, l.location_name
     FROM devices d
     LEFT JOIN device_locations l ON l.device_id = d.id
     WHERE d.is_active = 1
     ORDER BY d.id'
)->fetchAll();

json_response(true, [
    'center' => [
        'lat' => setting_float('map_center_lat', -5.77),
        'lng' => setting_float('map_center_lng', 105.264),
    ],
    'zoom' => setting_int('map_default_zoom', 15),
    'site_location' => setting('site_location', 'Teluk Hurun, Lampung'),
    'demo_mode' => DEMO_MODE,
    'devices' => $rows,
]);
