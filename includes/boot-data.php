<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function skja_boot_water(int $minutes = 1440): array
{
    $stmt = db()->prepare(
        'SELECT measured_at, temp_sensor_c, sst_c, salinity_psu, ph, turbidity_ntu, depth_m
         FROM water_measurements
         WHERE measured_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
         ORDER BY measured_at ASC LIMIT 400'
    );
    $stmt->execute([$minutes]);
    $pts = [];
    foreach ($stmt->fetchAll() as $r) {
        $ph = $r['ph'] !== null ? (float)$r['ph'] : null;
        $pts[] = [
            't' => date('H:i', strtotime($r['measured_at'])),
            'dt' => $r['measured_at'],
            'temp' => $r['temp_sensor_c'] !== null ? (float)$r['temp_sensor_c'] : null,
            'sst' => $r['sst_c'] !== null ? (float)$r['sst_c'] : null,
            'sal' => $r['salinity_psu'] !== null ? (float)$r['salinity_psu'] : null,
            'ph' => $ph,
            'vph' => $ph !== null ? round(1.5 + (7.0 - $ph) * 0.17, 3) : null,
            'turb' => $r['turbidity_ntu'] !== null ? (float)$r['turbidity_ntu'] : null,
            'depth' => $r['depth_m'] !== null ? (float)$r['depth_m'] : null,
        ];
    }
    return $pts;
}

function skja_boot_wave(int $minutes = 1440): array
{
    $stmt = db()->prepare(
        'SELECT measured_at, wave_height_m, wave_period_s, wave_direction_deg, wind_speed_ms, wind_direction_deg
         FROM wave_measurements
         WHERE measured_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
         ORDER BY measured_at ASC LIMIT 400'
    );
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
            'wd' => $r['wave_direction_deg'] !== null ? (int)$r['wave_direction_deg'] : null,
            'ws' => $r['wind_speed_ms'] !== null ? (float)$r['wind_speed_ms'] : null,
            'pw' => wave_power_kw($h, $t),
        ];
    }
    return $pts;
}

function skja_boot_energy(int $minutes = 1440): array
{
    $stmt = db()->prepare(
        'SELECT measured_at, power_w, voltage_v, current_a, energy_wh, battery_pct
         FROM owc_measurements
         WHERE measured_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
         ORDER BY measured_at ASC LIMIT 400'
    );
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
    return $pts;
}
