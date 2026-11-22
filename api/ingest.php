<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_raw(['ok' => false, 'error' => 'Hanya mendukung metode POST.'], 405);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '', true);
if (!is_array($input) || array_is_list($input) || count($input) === 0) {
    json_raw(['ok' => false, 'error' => 'Body JSON tidak valid atau kosong.'], 400);
}

$deviceCode = trim((string)($input['device_code'] ?? ''));
$apiKey = (string)($input['api_key'] ?? '');
if ($deviceCode === '' || $apiKey === '') {
    json_raw(['ok' => false, 'error' => 'device_code dan api_key wajib diisi.'], 401);
}
if (strlen($deviceCode) > 50 || strlen($apiKey) > 128) {
    json_raw(['ok' => false, 'error' => 'Format kredensial tidak valid.'], 401);
}

$stmt = db()->prepare('SELECT * FROM devices WHERE device_code = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$deviceCode]);
$device = $stmt->fetch();

$keyHash = hash('sha256', $apiKey);
$storedToken = (string)($device['api_token'] ?? '');
if (!$device || $storedToken === '' || !hash_equals($storedToken, $keyHash)) {
    json_raw(['ok' => false, 'error' => 'Autentikasi perangkat gagal.'], 401);
}

$ts = trim((string)($input['timestamp'] ?? ''));
if ($ts === '') {
    $ts = date('Y-m-d H:i:s');
} else {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $ts);
    if (!$dt || $dt->format('Y-m-d H:i:s') !== $ts) {
        json_raw(['ok' => false, 'error' => 'Format timestamp tidak valid (YYYY-MM-DD HH:MM:SS).'], 400);
    }
    if (abs(time() - $dt->getTimestamp()) > 86400) {
        json_raw(['ok' => false, 'error' => 'Timestamp terlalu jauh dari waktu server (maks 24 jam).'], 400);
    }
}

$data = $input['data'] ?? null;
if (!is_array($data) || array_is_list($data)) {
    json_raw(['ok' => false, 'error' => 'Field "data" harus berupa objek.'], 400);
}

$numOrNull = static function (mixed $val, float $min, float $max): ?float {
    if ($val === null || $val === '' || is_bool($val) || !is_numeric($val)) {
        return null;
    }
    $n = (float)$val;
    if (!is_finite($n) || $n < $min || $n > $max) {
        return null;
    }
    return $n;
};

$deviceId = (int)$device['id'];
$now = date('Y-m-d H:i:s');
$inserted = 0;
$alertsCreated = 0;
$warnings = [];
$insertId = 0;

switch ($device['device_type']) {
    case 'wave_sensor': {
        $h = $numOrNull($data['wave_height_m'] ?? null, 0, 15);
        $t = $numOrNull($data['wave_period_s'] ?? null, 0, 30);
        if ($h === null || $t === null) {
            json_raw(['ok' => false, 'error' => 'wave_height_m dan wave_period_s wajib (angka dalam rentang wajar).'], 400);
        }
        $ins = db()->prepare(
            'INSERT INTO wave_measurements (device_id, measured_at, wave_height_m, wave_period_s, wave_direction_deg, wind_speed_ms, wind_direction_deg, is_simulated, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)'
        );
        $ins->execute([
            $deviceId,
            $ts,
            $h,
            $t,
            $numOrNull($data['wave_direction_deg'] ?? null, 0, 360),
            $numOrNull($data['wind_speed_ms'] ?? null, 0, 75),
            $numOrNull($data['wind_direction_deg'] ?? null, 0, 360),
            $now,
        ]);
        $insertId = (int)db()->lastInsertId();
        $inserted = 1;
        if (maybe_create_alert($deviceId, 'wave_height_m', $h, false)) {
            $alertsCreated++;
        }
        break;
    }

    case 'water_sensor': {
        $temp = $numOrNull($data['temp_sensor_c'] ?? null, -5, 45);
        $ph = $numOrNull($data['ph'] ?? null, 0.05, 14);
        $voltPh = $numOrNull($data['volt_ph_v'] ?? null, 0, 3.3);
        if ($temp === null && $ph === null) {
            json_raw(['ok' => false, 'error' => 'temp_sensor_c dan/atau ph wajib ada (minimal satu angka valid).'], 400);
        }
        $ins = db()->prepare(
            'INSERT INTO water_measurements (device_id, measured_at, temp_sensor_c, sst_c, salinity_psu, ph, volt_ph_v, turbidity_ntu, depth_m, is_simulated, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
        );
        $ins->execute([
            $deviceId,
            $ts,
            $temp,
            $numOrNull($data['sst_c'] ?? null, -5, 45),
            $numOrNull($data['salinity_psu'] ?? null, 0, 45),
            $ph,
            $voltPh,
            $numOrNull($data['turbidity_ntu'] ?? null, 0, 1000),
            $numOrNull($data['depth_m'] ?? null, 0, 500),
            $now,
        ]);
        $insertId = (int)db()->lastInsertId();
        $inserted = 1;
        $skipped = [];
        if ($temp === null) {
            $skipped[] = 'temp_sensor_c';
        }
        if ($ph === null) {
            $skipped[] = 'ph';
        }
        if ($skipped) {
            $warnings[] = 'Nilai tidak valid, disimpan NULL: ' . implode(', ', $skipped) . '.';
        }
        if ($temp !== null && maybe_create_alert($deviceId, 'temp_sensor_c', $temp, false)) {
            $alertsCreated++;
        }
        if ($ph !== null && maybe_create_alert($deviceId, 'ph', $ph, false)) {
            $alertsCreated++;
        }
        break;
    }

    case 'owc': {
        $p = $numOrNull($data['power_w'] ?? null, 0, 100000);
        $v = $numOrNull($data['voltage_v'] ?? null, 0, 1000);
        $c = $numOrNull($data['current_a'] ?? null, 0, 1000);
        if ($p === null || $v === null || $c === null) {
            json_raw(['ok' => false, 'error' => 'power_w, voltage_v, current_a wajib (angka valid).'], 400);
        }
        $ins = db()->prepare(
            'INSERT INTO owc_measurements (device_id, measured_at, power_w, voltage_v, current_a, energy_wh, battery_pct, is_simulated, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)'
        );
        $ins->execute([
            $deviceId,
            $ts,
            $p,
            $v,
            $c,
            $numOrNull($data['energy_wh'] ?? null, 0, 1000000000) ?? 0.0,
            $numOrNull($data['battery_pct'] ?? null, 0, 100),
            $now,
        ]);
        $insertId = (int)db()->lastInsertId();
        $inserted = 1;
        break;
    }

    case 'camera': {
        $cnt = $numOrNull($data['detected_count'] ?? null, 0, 100000);
        $ins = db()->prepare(
            'INSERT INTO fishcount_results (device_id, image_path, media_type, detected_count, avg_length_cm, biomass_kg, confidence_pct, processing_status, status_message, is_simulated, is_manual, created_at)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?)'
        );
        $ins->execute([
            $deviceId,
            'image',
            $cnt !== null ? (int)$cnt : null,
            $numOrNull($data['avg_length_cm'] ?? null, 0, 300),
            $numOrNull($data['biomass_kg'] ?? null, 0, 100000),
            $numOrNull($data['confidence_pct'] ?? null, 0, 100),
            $cnt !== null ? 'done' : 'failed',
            'Hasil dari layanan AI eksternal via api/ingest.php',
            $now,
        ]);
        $insertId = (int)db()->lastInsertId();
        $inserted = 1;
        break;
    }

    default:
        json_raw(['ok' => false, 'error' => 'Jenis perangkat tidak dikenal.'], 400);
}

db()->prepare("UPDATE devices SET status = 'online', last_communication = ? WHERE id = ?")
    ->execute([$ts, $deviceId]);

json_raw([
    'ok' => true,
    'id' => $insertId,
    'device' => $device['device_code'],
    'inserted' => $inserted,
    'alerts_created' => $alertsCreated,
    'warnings' => $warnings,
    'message' => 'Data diterima dan disimpan.',
]);
