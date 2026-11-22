<?php
/**
 * BOLU — Boot data (bootstrap sisi-server untuk grafik & peta)
 *
 * Masalah yang diselesaikan: sebelumnya grafik Chart.js dan marker peta Leaflet
 * HANYA digambar setelah fetch API sisi-browser berhasil. Bila fetch gagal
 * (sesi berakhir, pratinjau ter-embed memblokir cookie pihak ketiga, jaringan
 * lambat), halaman dibiarkan kosong: kotak grafik tanpa garis dan peta abu-abu
 * tanpa titik lokasi.
 *
 * Solusi: setiap halaman menanamkan data awal ke HTML (window.SKJA_BOOT) yang
 * dihitung sisi-server. Grafik & peta langsung digambar tanpa menunggu API;
 * polling API tetap berjalan untuk menyegarkan nilai terbaru.
 */

if (!defined('SMART_KJA_CONFIG_LOADED')) {
    exit('Akses langsung tidak diizinkan.');
}

/**
 * Ambil subset titik agar ukuran HTML tetap ringan (maks ~120 titik per seri).
 * Selalu menyimpan titik terakhir agar grafik berujung di waktu terkini.
 */
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

/** ID perangkat aktif pertama untuk satu jenis perangkat. */
function skja_boot_device_id(string $type): int
{
    $st = db()->prepare("SELECT id FROM devices WHERE device_type = ? AND is_active = 1 ORDER BY id LIMIT 1");
    $st->execute([$type]);
    $r = $st->fetch();
    return $r ? (int)$r['id'] : 0;
}

/**
 * Titik grafik gelombang — bentuk identik dengan api/wave-data.php view=range:
 * [{t:'H:i', h: tinggi(m), p: periode(s), w: angin(m/s)}]
 * $from/$to (YYYY-MM-DD) dipakai untuk rentang khusus, mengabaikan $minutes.
 */
function skja_boot_wave(int $minutes, string $from = '', string $to = ''): array
{
    $devId = skja_boot_device_id('wave_sensor');
    if (!$devId) {
        return ['points' => [], 'stats' => null];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $st = db()->prepare(
            "SELECT measured_at, wave_height_m, wave_period_s, wind_speed_ms
             FROM wave_measurements WHERE device_id = ? AND measured_at BETWEEN ? AND ?
             ORDER BY measured_at ASC LIMIT 3000"
        );
        $st->execute([$devId, $from . ' 00:00:00', $to . ' 23:59:59']);
    } else {
        $minutes = max(5, min(10080, $minutes));
        $st = db()->prepare(
            "SELECT measured_at, wave_height_m, wave_period_s, wind_speed_ms
             FROM wave_measurements WHERE device_id = ? AND measured_at >= (NOW() - INTERVAL {$minutes} MINUTE)
             ORDER BY measured_at ASC LIMIT 3000"
        );
        $st->execute([$devId]);
    }

    $points = [];
    $hs = [];
    foreach ($st->fetchAll() as $r) {
        $hs[] = (float)$r['wave_height_m'];
        $points[] = [
            't' => date('H:i', strtotime($r['measured_at'])),
            'h' => (float)$r['wave_height_m'],
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
    return ['points' => skja_downsample($points), 'stats' => $stats];
}

/**
 * Titik grafik kualitas air — bentuk identik dengan api/water-data.php view=range:
 * [{t:'H:i:s', v: nilai}]
 */
function skja_boot_water(string $param, int $minutes): array
{
    $allowed = ['temp_sensor_c', 'ph', 'volt_ph_v'];
    if (!in_array($param, $allowed, true)) {
        return [];
    }
    $devId = skja_boot_device_id('water_sensor');
    if (!$devId) {
        return [];
    }
    $minutes = max(5, min(20160, $minutes));
    $st = db()->prepare(
        "SELECT measured_at, {$param} AS v FROM water_measurements
         WHERE device_id = ? AND measured_at >= (NOW() - INTERVAL {$minutes} MINUTE) AND {$param} IS NOT NULL
         ORDER BY measured_at DESC, id DESC LIMIT 3000"
    );
    $st->execute([$devId]);
    $points = [];
    foreach (array_reverse($st->fetchAll()) as $r) {
        $points[] = [
            't' => date('H:i:s', strtotime($r['measured_at'])),
            'v' => (float)$r['v'],
        ];
    }
    return skja_downsample($points);
}

/**
 * Titik grafik energi OWC — bentuk identik dengan api/energy-data.php view=range:
 * [{t:'H:i', p: daya(W), e: energi(Wh), batt: %, est: estimasi potensi(W)}]
 */
function skja_boot_energy(int $minutes): array
{
    $devId = skja_boot_device_id('owc');
    if (!$devId) {
        return [];
    }
    $minutes = max(5, min(10080, $minutes));
    $st = db()->prepare(
        "SELECT measured_at, power_w, energy_wh, battery_pct
         FROM owc_measurements WHERE device_id = ? AND measured_at >= (NOW() - INTERVAL {$minutes} MINUTE)
         ORDER BY measured_at ASC LIMIT 3000"
    );
    $st->execute([$devId]);
    $rows = $st->fetchAll();

    $waveRows = db()->query(
        "SELECT measured_at, wave_height_m, wave_period_s FROM wave_measurements
         WHERE measured_at >= (NOW() - INTERVAL " . ($minutes + 10) . " MINUTE)
         ORDER BY measured_at ASC LIMIT 3000"
    )->fetchAll();
    $waveByTime = [];
    foreach ($waveRows as $w) {
        $waveByTime[strtotime($w['measured_at'])] = $w;
    }

    $width = setting_float('owc_capture_width_m', 3.0);
    $eff   = setting_float('owc_efficiency', 0.30);

    $points = [];
    foreach ($rows as $r) {
        $ts  = strtotime($r['measured_at']);
        $est = null;
        foreach ([$ts, $ts - 300, $ts + 300, $ts - 600] as $cand) {
            if (isset($waveByTime[$cand])) {
                $w = $waveByTime[$cand];
                $est = round(wave_power_kw($w['wave_height_m'], $w['wave_period_s']) * 1000 * $width * $eff, 1);
                break;
            }
        }
        $points[] = [
            't'    => date('H:i', $ts),
            'p'    => (float)$r['power_w'],
            'e'    => (float)$r['energy_wh'],
            'batt' => $r['battery_pct'] !== null ? (float)$r['battery_pct'] : null,
            'est'  => $est,
        ];
    }
    return skja_downsample($points);
}

/** Produksi harian OWC — bentuk identik dengan api/energy-data.php view=daily. */
function skja_boot_energy_daily(int $days): array
{
    $devId = skja_boot_device_id('owc');
    if (!$devId) {
        return [];
    }
    $days = max(1, min(90, $days));
    $st = db()->prepare(
        "SELECT DATE(measured_at) AS d,
                ROUND(MAX(energy_wh) - MIN(energy_wh), 1) AS energy_wh,
                MAX(power_w) AS peak_w,
                ROUND(AVG(power_w), 1) AS avg_w
         FROM owc_measurements
         WHERE device_id = ? AND measured_at >= (NOW() - INTERVAL {$days} DAY)
         GROUP BY DATE(measured_at) ORDER BY d ASC"
    );
    $st->execute([$devId]);
    return $st->fetchAll();
}

/**
 * Geolokasi seluruh perangkat — bentuk identik dengan api/devices-geo.php default.
 * Setiap perangkat dilengkapi data_status (LIVE/DELAYED/OFFLINE) yang dihitung
 * sisi-server agar popup peta tetap informatif tanpa API.
 */
function skja_boot_geo(): array
{
    $devices = db()->query(
        "SELECT d.id, d.device_code, d.device_name, d.device_type, d.status,
                l.latitude, l.longitude, l.location_name
         FROM devices d LEFT JOIN device_locations l ON l.device_id = d.id
         WHERE d.is_active = 1
         ORDER BY d.id"
    )->fetchAll();

    $lastByDev = [];
    $rows = db()->query(
        "SELECT device_id, MAX(m) AS m FROM (
            SELECT device_id, measured_at AS m FROM wave_measurements
            UNION ALL SELECT device_id, measured_at FROM owc_measurements
            UNION ALL SELECT device_id, measured_at FROM water_measurements
            UNION ALL SELECT device_id, created_at AS m FROM fishcount_results
        ) t GROUP BY device_id"
    )->fetchAll();
    foreach ($rows as $r) {
        $lastByDev[(int)$r['device_id']] = $r['m'];
    }

    $out = [];
    foreach ($devices as $d) {
        $out[] = [
            'id'            => (int)$d['id'],
            'code'          => $d['device_code'],
            'name'          => $d['device_name'],
            'type'          => $d['device_type'],
            'status'        => $d['status'],
            'lat'           => $d['latitude'] !== null ? (float)$d['latitude'] : null,
            'lng'           => $d['longitude'] !== null ? (float)$d['longitude'] : null,
            'location_name' => $d['location_name'],
            'data_status'   => data_status($lastByDev[(int)$d['id']] ?? null),
        ];
    }

    return [
        'center' => [
            'lat' => (float)setting('map_center_lat', -5.77),
            'lng' => (float)setting('map_center_lng', 105.264),
        ],
        'zoom'    => (int)setting('map_zoom', 15),
        'devices' => $out,
    ];
}

/** Cetak tag script berisi data boot sebelum script halaman dimuat. */
function skja_boot_script(array $boot): void
{
    echo '<script>window.SKJA_BOOT='
        . json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . ';</script>';
}
