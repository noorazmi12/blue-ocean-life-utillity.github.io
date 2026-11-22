<?php
/**
 * BOLU — API Geolokasi Perangkat (untuk Leaflet/WebGIS)
 * GET:
 *   (default)           -> GeoJSON-like: pusat peta + daftar perangkat & nilai terbaru
 *   ?view=detail&id=N   -> detail perangkat + 20 pengukuran terakhir
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_login_api();

$view = $_GET['view'] ?? 'geo';

if ($view === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    $st = db()->prepare('SELECT * FROM devices WHERE id = ?');
    $st->execute([$id]);
    $d = $st->fetch();
    if (!$d) json_fail('Perangkat tidak ditemukan.', 404);

    $measurements = [];
    $columns = [];
    if ($d['device_type'] === 'wave_sensor') {
        $q = db()->prepare("SELECT * FROM wave_measurements WHERE device_id = ? ORDER BY measured_at DESC LIMIT 20");
        $q->execute([$id]);
        foreach ($q->fetchAll() as $r) {
            $measurements[] = ['time' => fmt_dt($r['measured_at']), 'vals' => [
                'Tinggi (m)' => fmt_num($r['wave_height_m'], 3),
                'Periode (s)' => fmt_num($r['wave_period_s'], 2),
                'Angin (m/s)' => $r['wind_speed_ms'] !== null ? fmt_num($r['wind_speed_ms'], 1) : '—',
            ]];
        }
        $columns = ['Tinggi (m)', 'Periode (s)', 'Angin (m/s)'];
    } elseif ($d['device_type'] === 'water_sensor') {
        $q = db()->prepare("SELECT * FROM water_measurements WHERE device_id = ? ORDER BY measured_at DESC LIMIT 20");
        $q->execute([$id]);
        foreach ($q->fetchAll() as $r) {
            $measurements[] = ['time' => fmt_dt($r['measured_at']), 'vals' => [
                'Suhu (°C)' => fmt_num($r['temp_sensor_c'], 1),
                'pH' => fmt_num($r['ph'], 2),
            ]];
        }
        $columns = ['Suhu (°C)', 'pH'];
    } elseif ($d['device_type'] === 'owc') {
        $q = db()->prepare("SELECT * FROM owc_measurements WHERE device_id = ? ORDER BY measured_at DESC LIMIT 20");
        $q->execute([$id]);
        foreach ($q->fetchAll() as $r) {
            $measurements[] = ['time' => fmt_dt($r['measured_at']), 'vals' => [
                'Daya (W)' => fmt_num($r['power_w'], 1),
                'Tegangan (V)' => fmt_num($r['voltage_v'], 1),
                'Arus (A)' => fmt_num($r['current_a'], 2),
            ]];
        }
        $columns = ['Daya (W)', 'Tegangan (V)', 'Arus (A)'];
    } elseif ($d['device_type'] === 'camera') {
        $q = db()->prepare("SELECT * FROM fishcount_results WHERE device_id = ? ORDER BY created_at DESC LIMIT 20");
        $q->execute([$id]);
        foreach ($q->fetchAll() as $r) {
            $measurements[] = ['time' => fmt_dt($r['created_at']), 'vals' => [
                'Jumlah' => $r['detected_count'] ?? '—',
                'Panjang (cm)' => $r['avg_length_cm'] !== null ? fmt_num($r['avg_length_cm'], 1) : '—',
                'Status' => $r['processing_status'],
            ]];
        }
        $columns = ['Jumlah', 'Panjang (cm)', 'Status'];
    }

    json_out(['ok' => true, 'data' => [
        'id' => (int)$d['id'], 'code' => $d['device_code'], 'name' => $d['device_name'], 'type' => $d['device_type'],
        'data_status' => data_status($d['last_communication']),
        'last_comm' => $d['last_communication'] ? fmt_dt($d['last_communication']) : '—',
        'calibration' => $d['calibration_status'],
        'columns' => $columns,
        'measurements' => $measurements,
    ]]);
}

/* ---------- Default: semua perangkat + nilai terbaru ---------- */
$devices = db()->query(
    "SELECT d.id, d.device_code, d.device_name, d.device_type, d.status, d.is_active,
            l.latitude, l.longitude, l.location_name
     FROM devices d LEFT JOIN device_locations l ON l.device_id = d.id
     WHERE d.is_active = 1
     ORDER BY d.id"
)->fetchAll();

$out = [];
foreach ($devices as $d) {
    $latest = null;
    if ($d['device_type'] === 'wave_sensor') {
        $r = db()->prepare("SELECT measured_at, wave_height_m, wave_period_s, wind_speed_ms, is_simulated FROM wave_measurements WHERE device_id = ? ORDER BY measured_at DESC LIMIT 1");
        $r->execute([$d['id']]);
        $row = $r->fetch();
        if ($row) {
            $latest = ['measured_at' => $row['measured_at'], 'is_simulated' => (int)$row['is_simulated']];
            foreach (['Tinggi' => fmt_num($row['wave_height_m'], 2) . ' m', 'Periode' => fmt_num($row['wave_period_s'], 1) . ' s'] as $k => $v) $latest[$k] = $v;
            if ($row['wind_speed_ms'] !== null) $latest['Angin'] = fmt_num($row['wind_speed_ms'], 1) . ' m/s';
        }
    } elseif ($d['device_type'] === 'water_sensor') {
        $r = db()->prepare("SELECT measured_at, temp_sensor_c, ph, is_simulated FROM water_measurements WHERE device_id = ? ORDER BY measured_at DESC LIMIT 1");
        $r->execute([$d['id']]);
        $row = $r->fetch();
        if ($row) {
            $latest = ['measured_at' => $row['measured_at'], 'is_simulated' => (int)$row['is_simulated']];
            $latest['Suhu'] = fmt_num($row['temp_sensor_c'], 1) . ' °C';
            $latest['pH'] = fmt_num($row['ph'], 2);
        }
    } elseif ($d['device_type'] === 'owc') {
        $r = db()->prepare("SELECT measured_at, power_w, voltage_v, energy_wh, is_simulated FROM owc_measurements WHERE device_id = ? ORDER BY measured_at DESC LIMIT 1");
        $r->execute([$d['id']]);
        $row = $r->fetch();
        if ($row) {
            $latest = ['measured_at' => $row['measured_at'], 'is_simulated' => (int)$row['is_simulated']];
            $latest['Daya'] = fmt_num($row['power_w'], 1) . ' W';
            $latest['Tegangan'] = fmt_num($row['voltage_v'], 1) . ' V';
        }
    } elseif ($d['device_type'] === 'camera') {
        $r = db()->prepare("SELECT created_at, detected_count, processing_status, is_simulated FROM fishcount_results WHERE device_id = ? ORDER BY created_at DESC LIMIT 1");
        $r->execute([$d['id']]);
        $row = $r->fetch();
        if ($row) {
            $latest = ['measured_at' => $row['created_at'], 'is_simulated' => (int)$row['is_simulated']];
            $latest['Deteksi'] = $row['detected_count'] !== null ? $row['detected_count'] . ' ekor' : '—';
            $latest['Status'] = $row['processing_status'];
        }
    }
    $out[] = [
        'id' => (int)$d['id'],
        'code' => $d['device_code'],
        'name' => $d['device_name'],
        'type' => $d['device_type'],
        'status' => $d['status'],
        'lat' => $d['latitude'] !== null ? (float)$d['latitude'] : null,
        'lng' => $d['longitude'] !== null ? (float)$d['longitude'] : null,
        'location_name' => $d['location_name'],
        'latest' => $latest,
        'data_status' => data_status($latest['measured_at'] ?? null),
    ];
}

json_out(['ok' => true, 'data' => [
    'center' => [
        'lat' => (float)setting('map_center_lat', -5.77),
        'lng' => (float)setting('map_center_lng', 105.264),
    ],
    'zoom' => (int)setting('map_zoom', 15),
    'site_location' => setting('site_location', ''),
    'demo_mode' => DEMO_MODE,
    'devices' => $out,
]]);
