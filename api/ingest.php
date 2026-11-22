<?php
/**
 * BOLU — Endpoint Penerimaan Data Perangkat (Ingest)
 *
 * POST JSON dari mikrokontroler/gateway:
 * {
 *   "device_code": "WAV-001",
 *   "api_key": "token-asli-perangkat",     // dibandingkan dgn SHA-256 di DB
 *   "timestamp": "2026-10-30 10:30:00",    // opsional, default waktu server
 *   "data": { ...field sesuai jenis... }
 * }
 *
 * Field per jenis:
 *   wave_sensor  : wave_height_m*, wave_period_s*, wave_direction_deg, wind_speed_ms, wind_direction_deg
 *   water_sensor : temp_sensor_c*, ph*   (alat hanya mengukur suhu & pH; sst_c, salinity_psu, turbidity_ntu, depth_m opsional dan tidak ditampilkan)
 *   owc          : power_w*, voltage_v*, current_a*, energy_wh, battery_pct
 *   camera       : (hasil deteksi dari layanan AI eksternal)
 *                  detected_count, avg_length_cm, biomass_kg, confidence_pct
 *
 * Autentikasi perangkat: token asli HANYA diketahui perangkat; server menyimpan
 * hash SHA-256. Tidak ada kredensial di sisi JavaScript frontend.
 *
 * Respons JSON: {ok:true, id, message} atau {ok:false, error}
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* ---------- Ambil & validasi payload dasar ---------- */
$in = json_input();
if (!$in) {
    json_fail('Body JSON tidak valid atau kosong.', 400);
}

$deviceCode = trim((string)($in['device_code'] ?? ''));
$apiKey     = (string)($in['api_key'] ?? '');
if ($deviceCode === '' || $apiKey === '') {
    json_fail('device_code dan api_key wajib diisi.', 401);
}
if (mb_strlen($deviceCode) > 50 || mb_strlen($apiKey) > 128) {
    json_fail('Format kredensial tidak valid.', 401);
}

/* ---------- Autentikasi perangkat ---------- */
$st = db()->prepare('SELECT * FROM devices WHERE device_code = ? AND is_active = 1 LIMIT 1');
$st->execute([$deviceCode]);
$device = $st->fetch();
if (!$device || empty($device['api_token']) || !hash_equals($device['api_token'], hash('sha256', $apiKey))) {
    // Respons generik agar tidak membocorkan keberadaan kode perangkat
    json_fail('Autentikasi perangkat gagal.', 401);
}

/* ---------- Validasi timestamp ---------- */
$ts = trim((string)($in['timestamp'] ?? ''));
if ($ts === '') {
    $ts = date('Y-m-d H:i:s');
} elseif (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $ts) || strtotime($ts) === false) {
    json_fail('Format timestamp tidak valid (YYYY-MM-DD HH:MM:SS).', 400);
} elseif (abs(time() - strtotime($ts)) > 86400) {
    json_fail('Timestamp terlalu jauh dari waktu server (maks 24 jam).', 400);
}

/* ---------- Validasi angka bantu ---------- */
function num_or_null($v, float $min, float $max): ?float
{
    if ($v === null || $v === '') return null;
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    if ($f < $min || $f > $max) return null;
    return round($f, 4);
}

$data = $in['data'] ?? [];
if (!is_array($data)) {
    json_fail('Field "data" harus berupa objek.', 400);
}

$type = $device['device_type'];
$inserted = 0;
$alertsCreated = 0;
$warnings = [];
$insertId = 0;

try {
    if ($type === 'wave_sensor') {
        $h = num_or_null($data['wave_height_m'] ?? null, 0, 15);
        $t = num_or_null($data['wave_period_s'] ?? null, 0, 30);
        if ($h === null || $t === null) {
            json_fail('wave_height_m dan wave_period_s wajib (angka dalam rentang wajar).', 400);
        }
        $st = db()->prepare(
            'INSERT INTO wave_measurements (device_id, measured_at, wave_height_m, wave_period_s, wave_direction_deg, wind_speed_ms, wind_direction_deg, is_simulated)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0)'
        );
        $st->execute([
            $device['id'], $ts, $h, $t,
            num_or_null($data['wave_direction_deg'] ?? null, 0, 360),
            num_or_null($data['wind_speed_ms'] ?? null, 0, 75),
            num_or_null($data['wind_direction_deg'] ?? null, 0, 360),
        ]);
        $inserted = 1;
        $insertId = (int)db()->lastInsertId();
        // Periksa ambang operasional gelombang
        $before = (int)db()->query("SELECT COUNT(*) c FROM alerts")->fetch()['c'];
        maybe_create_alert((int)$device['id'], 'wave_height_m', $h, false);
        $alertsCreated = (int)db()->query("SELECT COUNT(*) c FROM alerts")->fetch()['c'] - $before;
    } elseif ($type === 'water_sensor') {
        // Suhu & pH boleh datang terpisah (salah satu sensor bisa error/dicabut):
        // nilai di luar rentang disimpan NULL, syaratnya minimal satu valid.
        $temp = num_or_null($data['temp_sensor_c'] ?? null, -5, 45);
        // pH 0 dianggap tidak valid (probe pH tidak terpasang/flush sensor)
        $ph = num_or_null($data['ph'] ?? null, 0.05, 14);
        $voltPh = num_or_null($data['volt_ph_v'] ?? null, 0, 3.3);
        if ($temp === null && $ph === null) {
            json_fail('temp_sensor_c dan/atau ph wajib ada (minimal satu angka valid).', 400);
        }
        $st = db()->prepare(
            'INSERT INTO water_measurements (device_id, measured_at, temp_sensor_c, sst_c, salinity_psu, ph, volt_ph_v, turbidity_ntu, depth_m, is_simulated)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
        );
        $st->execute([
            $device['id'], $ts, $temp,
            num_or_null($data['sst_c'] ?? null, -5, 45),
            num_or_null($data['salinity_psu'] ?? null, 0, 45), $ph, $voltPh,
            num_or_null($data['turbidity_ntu'] ?? null, 0, 1000),
            num_or_null($data['depth_m'] ?? null, 0, 500),
        ]);
        $inserted = 1;
        $insertId = (int)db()->lastInsertId();
        $skipped = [];
        if ($temp === null) $skipped[] = 'temp_sensor_c';
        if ($ph === null)   $skipped[] = 'ph';
        if ($skipped) {
            $warnings[] = 'Nilai tidak valid, disimpan NULL: ' . implode(', ', $skipped) . '.';
        }
        foreach (['temp_sensor_c' => $temp, 'ph' => $ph] as $param => $val) {
            if ($val === null) continue;
            $before = (int)db()->query("SELECT COUNT(*) c FROM alerts")->fetch()['c'];
            maybe_create_alert((int)$device['id'], $param, $val, false);
            $alertsCreated += (int)db()->query("SELECT COUNT(*) c FROM alerts")->fetch()['c'] - $before;
        }
    } elseif ($type === 'owc') {
        $p = num_or_null($data['power_w'] ?? null, 0, 100000);
        $v = num_or_null($data['voltage_v'] ?? null, 0, 1000);
        $c = num_or_null($data['current_a'] ?? null, 0, 1000);
        if ($p === null || $v === null || $c === null) {
            json_fail('power_w, voltage_v, current_a wajib (angka valid).', 400);
        }
        $st = db()->prepare(
            'INSERT INTO owc_measurements (device_id, measured_at, power_w, voltage_v, current_a, energy_wh, battery_pct, is_simulated)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0)'
        );
        $st->execute([
            $device['id'], $ts, $p, $v, $c,
            num_or_null($data['energy_wh'] ?? null, 0, 1e9),
            num_or_null($data['battery_pct'] ?? null, 0, 100),
        ]);
        $inserted = 1;
        $insertId = (int)db()->lastInsertId();
    } elseif ($type === 'camera') {
        // Hasil dari layanan AI eksternal (integrasi via API), bukan deteksi palsu
        $cnt = num_or_null($data['detected_count'] ?? null, 0, 100000);
        $st = db()->prepare(
            'INSERT INTO fishcount_results (device_id, detected_count, avg_length_cm, biomass_kg, confidence_pct, processing_status, status_message)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $device['id'],
            $cnt !== null ? (int)$cnt : null,
            num_or_null($data['avg_length_cm'] ?? null, 0, 300),
            num_or_null($data['biomass_kg'] ?? null, 0, 100000),
            num_or_null($data['confidence_pct'] ?? null, 0, 100),
            $cnt !== null ? 'done' : 'failed',
            'Hasil dari layanan AI eksternal via api/ingest.php',
        ]);
        $inserted = 1;
        $insertId = (int)db()->lastInsertId();
    } else {
        json_fail('Jenis perangkat tidak dikenal.', 400);
    }

    /* ---------- Perbarui status komunikasi perangkat ---------- */
    db()->prepare("UPDATE devices SET status = 'online', last_communication = ? WHERE id = ?")
        ->execute([$ts, $device['id']]);

    json_out([
        'ok' => true,
        'id' => $insertId,
        'device' => $device['device_code'],
        'inserted' => $inserted,
        'alerts_created' => $alertsCreated,
        'warnings' => $warnings,
        'message' => 'Data diterima dan disimpan.',
    ]);
} catch (PDOException $e) {
    // Jangan bocorkan detail database
    error_log('Ingest error: ' . $e->getMessage());
    json_fail('Terjadi kesalahan internal saat menyimpan data.', 500);
}
