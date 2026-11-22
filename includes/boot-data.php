<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function skja_downsample(array $points, int $max = 120): array
{
    $n = count($points);
    if ($n === 0 || $n <= $max) {
        return $points;
    }
    $step = (int)ceil($n / $max);
    $out = [];
    for ($i = 0; $i < $n; $i += $step) {
        $out[] = $points[$i];
    }
    $last = $points[$n - 1];
    if ($out[count($out) - 1] !== $last) {
        $out[] = $last;
    }
    return $out;
}

function skja_boot_device_id(string $type): int
{
    $stmt = db()->prepare('SELECT id FROM devices WHERE device_type = ? AND is_active = 1 ORDER BY id ASC LIMIT 1');
    $stmt->execute([$type]);
    return (int)$stmt->fetchColumn();
}

function skja_boot_wave(int $minutes = 1440, string $from = '', string $to = ''): array
{
    $devId = skja_boot_device_id('wave_sensor');
    if ($devId <= 0) {
        return ['points' => [], 'stats' => null];
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $stmt = db()->prepare(
            'SELECT measured_at, wave_height_m, wave_period_s, wind_speed_ms
             FROM wave_measurements
             WHERE device_id = ? AND measured_at BETWEEN ? AND ?
             ORDER BY measured_at ASC LIMIT 3000'
        );
        $stmt->execute([$devId, $from . ' 00:00:00', $to . ' 23:59:59']);
    } else {
        $mins = max(5, min(10080, $minutes));
        $cutoff = date('Y-m-d H:i:s', time() - $mins * 60);
        $stmt = db()->prepare(
            'SELECT measured_at, wave_height_m, wave_period_s, wind_speed_ms
             FROM wave_measurements
             WHERE device_id = ? AND measured_at >= ?
             ORDER BY measured_at ASC LIMIT 3000'
        );
        $stmt->execute([$devId, $cutoff]);
    }

    $points = [];
    $hs = [];
    foreach ($stmt->fetchAll() as $r) {
        $h = (float)$r['wave_height_m'];
        $hs[] = $h;
        $points[] = [
            't' => fmt_time_hi($r['measured_at']),
            'h' => $h,
            'p' => (float)$r['wave_period_s'],
            'w' => $r['wind_speed_ms'] !== null ? (float)$r['wind_speed_ms'] : null,
        ];
    }

    $stats = null;
    if ($hs) {
        $stats = [
            'min' => round(min($hs), 3),
            'max' => round(max($hs), 3),
            'avg' => round(array_sum($hs) / count($hs), 3),
            'count' => count($hs),
        ];
    }

    return [
        'points' => skja_downsample($points),
        'stats' => $stats,
    ];
}

function skja_boot_water(string $param = 'ph', int $minutes = 1440): array
{
    $allowed = ['temp_sensor_c', 'ph', 'volt_ph_v'];
    if (!in_array($param, $allowed, true)) {
        $param = 'ph';
    }
    $devId = skja_boot_device_id('water_sensor');
    if ($devId <= 0) {
        return [];
    }
    $mins = max(5, min(20160, $minutes));
    $cutoff = date('Y-m-d H:i:s', time() - $mins * 60);

    $stmt = db()->prepare(
        "SELECT measured_at, {$param} AS val
         FROM water_measurements
         WHERE device_id = ? AND measured_at >= ? AND {$param} IS NOT NULL
         ORDER BY measured_at DESC, id DESC LIMIT 3000"
    );
    $stmt->execute([$devId, $cutoff]);
    $rows = array_reverse($stmt->fetchAll());

    $points = [];
    foreach ($rows as $r) {
        $points[] = [
            't' => fmt_time_his($r['measured_at']),
            'v' => (float)$r['val'],
        ];
    }
    return skja_downsample($points);
}

function skja_boot_energy(int $minutes = 1440): array
{
    $devId = skja_boot_device_id('owc');
    if ($devId <= 0) {
        return [];
    }
    $mins = max(5, min(10080, $minutes));
    $cutoff = date('Y-m-d H:i:s', time() - $mins * 60);
    $waveCutoff = date('Y-m-d H:i:s', time() - ($mins + 10) * 60);

    $stmt = db()->prepare(
        'SELECT measured_at, power_w, energy_wh, battery_pct
         FROM owc_measurements
         WHERE device_id = ? AND measured_at >= ?
         ORDER BY measured_at ASC LIMIT 3000'
    );
    $stmt->execute([$devId, $cutoff]);
    $rows = $stmt->fetchAll();

    $wStmt = db()->prepare(
        'SELECT measured_at, wave_height_m, wave_period_s
         FROM wave_measurements
         WHERE measured_at >= ?'
    );
    $wStmt->execute([$waveCutoff]);
    $waveBySec = [];
    foreach ($wStmt->fetchAll() as $w) {
        $ts = strtotime($w['measured_at']);
        if ($ts) {
            $waveBySec[$ts] = $w;
        }
    }

    $points = [];
    foreach ($rows as $r) {
        $sec = (int)strtotime($r['measured_at']);
        $est = null;
        foreach ([$sec, $sec - 300, $sec + 300, $sec - 600] as $cand) {
            if (isset($waveBySec[$cand])) {
                $w = $waveBySec[$cand];
                $est = electric_estimate_w((float)$w['wave_height_m'], (float)$w['wave_period_s']);
                break;
            }
        }
        $points[] = [
            't' => fmt_time_hi($r['measured_at']),
            'p' => (float)$r['power_w'],
            'e' => (float)$r['energy_wh'],
            'batt' => $r['battery_pct'] !== null ? (float)$r['battery_pct'] : null,
            'est' => $est,
        ];
    }
    return skja_downsample($points);
}

function skja_boot_energy_daily(int $days = 7): array
{
    $devId = skja_boot_device_id('owc');
    if ($devId <= 0) {
        return [];
    }
    $dDays = max(1, min(90, $days));
    $cutoff = date('Y-m-d H:i:s', time() - $dDays * 86400);

    $stmt = db()->prepare(
        'SELECT substr(measured_at, 1, 10) AS d,
                ROUND(MAX(energy_wh) - MIN(energy_wh), 1) AS energy_wh,
                MAX(power_w) AS peak_w,
                ROUND(AVG(power_w), 1) AS avg_w
         FROM owc_measurements
         WHERE device_id = ? AND measured_at >= ?
         GROUP BY substr(measured_at, 1, 10)
         ORDER BY d ASC'
    );
    $stmt->execute([$devId, $cutoff]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[] = [
            'd' => (string)$r['d'],
            'energy_wh' => (float)$r['energy_wh'],
            'peak_w' => (float)$r['peak_w'],
            'avg_w' => (float)$r['avg_w'],
        ];
    }
    return $out;
}

function skja_boot_geo(): array
{
    $pdo = db();
    $devices = $pdo->query('SELECT * FROM devices WHERE is_active = 1 ORDER BY id ASC')->fetchAll();
    $locs = [];
    foreach ($pdo->query('SELECT * FROM device_locations')->fetchAll() as $l) {
        $locs[(int)$l['device_id']] = $l;
    }

    $lastByDev = [];
    foreach (['water_measurements', 'owc_measurements', 'wave_measurements'] as $tbl) {
        $rows = $pdo->query("SELECT device_id, MAX(measured_at) AS last_dt FROM {$tbl} GROUP BY device_id")->fetchAll();
        foreach ($rows as $r) {
            $did = (int)$r['device_id'];
            $dt = (string)($r['last_dt'] ?? '');
            if ($did > 0 && $dt !== '' && (!isset($lastByDev[$did]) || $dt > $lastByDev[$did])) {
                $lastByDev[$did] = $dt;
            }
        }
    }
    foreach ($pdo->query('SELECT device_id, MAX(created_at) AS last_dt FROM fishcount_results WHERE device_id IS NOT NULL GROUP BY device_id')->fetchAll() as $r) {
        $did = (int)$r['device_id'];
        $dt = (string)($r['last_dt'] ?? '');
        if ($did > 0 && $dt !== '' && (!isset($lastByDev[$did]) || $dt > $lastByDev[$did])) {
            $lastByDev[$did] = $dt;
        }
    }

    $out = [];
    foreach ($devices as $d) {
        $did = (int)$d['id'];
        $loc = $locs[$did] ?? null;
        $out[] = [
            'id' => $did,
            'code' => (string)$d['device_code'],
            'name' => (string)$d['device_name'],
            'type' => (string)$d['device_type'],
            'status' => (string)$d['status'],
            'lat' => $loc ? (float)$loc['latitude'] : null,
            'lng' => $loc ? (float)$loc['longitude'] : null,
            'location_name' => $loc ? $loc['location_name'] : null,
            'data_status' => data_status($lastByDev[$did] ?? null),
        ];
    }

    return [
        'center' => [
            'lat' => setting_float('map_center_lat', -5.77),
            'lng' => setting_float('map_center_lng', 105.264),
        ],
        'zoom' => setting_int('map_zoom', 15),
        'devices' => $out,
    ];
}
