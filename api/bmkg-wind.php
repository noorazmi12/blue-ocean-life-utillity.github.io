<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login_api();

$row = db()->query('SELECT measured_at, wind_speed_ms, wind_direction_deg FROM wave_measurements ORDER BY measured_at DESC, id DESC LIMIT 1')->fetch();
json_response(true, [
    'source' => 'sensor-buoy',
    'measured_at' => $row['measured_at'] ?? null,
    'wind_speed_ms' => $row && $row['wind_speed_ms'] !== null ? (float)$row['wind_speed_ms'] : null,
    'wind_direction_deg' => $row && $row['wind_direction_deg'] !== null ? (int)$row['wind_direction_deg'] : null,
]);
