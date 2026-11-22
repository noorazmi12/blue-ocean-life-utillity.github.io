<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login_api();

$pdo = db();
$view = (string)($_GET['view'] ?? 'geo');

if ($view === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM devices WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $d = $stmt->fetch();
    if (!$d) {
        json_response(false, 'Perangkat tidak ditemukan.', 404);
    }

    $columns = [];
    $measurements = [];

    if ($d['device_type'] === 'wave_sensor') {
        $columns = ['Tinggi (m)', 'Periode (s)', 'Angin (m/s)'];
        $q = $pdo->prepare('SELECT * FROM wave_measurements WHERE device_id = ? ORDER BY measured_at DESC LIMIT 20');
        $q->execute([$id]);
        foreach ($q->fetchAll() as $r) {
            $measurements[] = [
                'time' => fmt_dt($r['measured_at']),
                'vals' => [
                    'Tinggi (m)' => fmt_num($r['wave_height_m'], 3),
                    'Periode (s)' => fmt_num($r['wave_period_s'], 2),
                    'Angin (m/s)' => $r['wind_speed_ms'] !== null ? fmt_num($r['wind_speed_ms'], 1) : '—',
                ],
            ];
        }
    } elseif ($d['device_type'] === 'water_sensor') {
        $columns = ['Suhu (°C)', 'pH'];
        $q = $pdo->prepare('SELECT * FROM water_measurements WHERE device_id = ? ORDER BY measured_at DESC LIMIT 20');
        $q->execute([$id]);
        foreach ($q->fetchAll() as $r) {
            $measurements[] = [
                'time' => fmt_dt($r['measured_at']),
                'vals' => [
                    'Suhu (°C)' => fmt_num($r['temp_sensor_c'], 1),
                    'pH' => fmt_num($r['ph'], 2),
                ],
            ];
        }
    } elseif ($d['device_type'] === 'owc') {
        $columns = ['Daya (W)', 'Tegangan (V)', 'Arus (A)'];
        $q = $pdo->prepare('SELECT * FROM owc_measurements WHERE device_id = ? ORDER BY measured_at DESC LIMIT 20');
        $q->execute([$id]);
        foreach ($q->fetchAll() as $r) {
            $measurements[] = [
                'time' => fmt_dt($r['measured_at']),
                'vals' => [
                    'Daya (W)' => fmt_num($r['power_w'], 1),
                    'Tegangan (V)' => fmt_num($r['voltage_v'], 1),
                    'Arus (A)' => fmt_num($r['current_a'], 2),
                ],
            ];
        }
    } elseif ($d['device_type'] === 'camera') {
        $columns = ['Jumlah', 'Panjang (cm)', 'Status'];
        $q = $pdo->prepare('SELECT * FROM fishcount_results WHERE device_id = ? ORDER BY created_at DESC LIMIT 20');
        $q->execute([$id]);
        foreach ($q->fetchAll() as $r) {
            $measurements[] = [
                'time' => fmt_dt($r['created_at']),
                'vals' => [
                    'Jumlah' => $r['detected_count'] !== null ? (int)$r['detected_count'] : '—',
                    'Panjang (cm)' => $r['avg_length_cm'] !== null ? fmt_num($r['avg_length_cm'], 1) : '—',
                    'Status' => (string)$r['processing_status'],
                ],
            ];
        }
    }

    json_response(true, [
        'id' => (int)$d['id'],
        'code' => (string)$d['device_code'],
        'name' => (string)$d['device_name'],
        'type' => (string)$d['device_type'],
        'data_status' => data_status($d['last_communication']),
        'last_comm' => $d['last_communication'] ? fmt_dt($d['last_communication']) : '—',
        'calibration' => (string)$d['calibration_status'],
        'columns' => $columns,
        'measurements' => $measurements,
    ]);
}

$devices = $pdo->query(
    'SELECT d.*, l.latitude, l.longitude, l.location_name
     FROM devices d
     LEFT JOIN device_locations l ON l.device_id = d.id
     WHERE d.is_active = 1
     ORDER BY d.id ASC'
)->fetchAll();

$out = [];
foreach ($devices as $d) {
    $did = (int)$d['id'];
    $latest = null;

    if ($d['device_type'] === 'wave_sensor') {
        $q = $pdo->prepare('SELECT * FROM wave_measurements WHERE device_id = ? ORDER BY measured_at DESC LIMIT 1');
        $q->execute([$did]);
        if ($row = $q->fetch()) {
            $latest = [
                'measured_at' => (string)$row['measured_at'],
                'is_simulated' => (int)$row['is_simulated'],
                'Tinggi' => fmt_num($row['wave_height_m'], 2) . ' m',
                'Periode' => fmt_num($row['wave_period_s'], 1) . ' s',
            ];
            if ($row['wind_speed_ms'] !== null) {
                $latest['Angin'] = fmt_num($row['wind_speed_ms'], 1) . ' m/s';
            }
        }
    } elseif ($d['device_type'] === 'water_sensor') {
        $q = $pdo->prepare('SELECT * FROM water_measurements WHERE device_id = ? ORDER BY measured_at DESC LIMIT 1');
        $q->execute([$did]);
        if ($row = $q->fetch()) {
            $latest = [
                'measured_at' => (string)$row['measured_at'],
                'is_simulated' => (int)$row['is_simulated'],
                'Suhu' => fmt_num($row['temp_sensor_c'], 1) . ' °C',
                'pH' => fmt_num($row['ph'], 2),
            ];
        }
    } elseif ($d['device_type'] === 'owc') {
        $q = $pdo->prepare('SELECT * FROM owc_measurements WHERE device_id = ? ORDER BY measured_at DESC LIMIT 1');
        $q->execute([$did]);
        if ($row = $q->fetch()) {
            $latest = [
                'measured_at' => (string)$row['measured_at'],
                'is_simulated' => (int)$row['is_simulated'],
                'Daya' => fmt_num($row['power_w'], 1) . ' W',
                'Tegangan' => fmt_num($row['voltage_v'], 1) . ' V',
            ];
        }
    } elseif ($d['device_type'] === 'camera') {
        $q = $pdo->prepare('SELECT * FROM fishcount_results WHERE device_id = ? ORDER BY created_at DESC LIMIT 1');
        $q->execute([$did]);
        if ($row = $q->fetch()) {
            $latest = [
                'measured_at' => (string)$row['created_at'],
                'is_simulated' => (int)$row['is_simulated'],
                'Deteksi' => $row['detected_count'] !== null ? ((int)$row['detected_count'] . ' ekor') : '—',
                'Status' => (string)$row['processing_status'],
            ];
        }
    }

    $out[] = [
        'id' => $did,
        'code' => (string)$d['device_code'],
        'name' => (string)$d['device_name'],
        'type' => (string)$d['device_type'],
        'status' => (string)$d['status'],
        'lat' => $d['latitude'] !== null ? (float)$d['latitude'] : null,
        'lng' => $d['longitude'] !== null ? (float)$d['longitude'] : null,
        'location_name' => $d['location_name'] !== null ? (string)$d['location_name'] : null,
        'latest' => $latest,
        'data_status' => data_status($latest['measured_at'] ?? null),
    ];
}

json_response(true, [
    'center' => [
        'lat' => setting_float('map_center_lat', -5.77),
        'lng' => setting_float('map_center_lng', 105.264),
    ],
    'zoom' => setting_int('map_zoom', 15),
    'site_location' => setting('site_location', '') ?? '',
    'demo_mode' => DEMO_MODE,
    'devices' => $out,
]);
