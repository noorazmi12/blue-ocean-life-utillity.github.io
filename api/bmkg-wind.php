<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login_api();

header('Cache-Control: private, max-age=120');

$adm4 = (string)($_GET['adm4'] ?? '36.73.02.1001');
if (!preg_match('/^\d{2}\.\d{2}\.\d{2}\.\d{4}$/', $adm4)) {
    json_raw(['ok' => false, 'error' => 'Kode ADM4 tidak valid (contoh 36.73.02.1001).'], 400);
}

$cacheFile = sys_get_temp_dir() . '/bolu_bmkg_' . str_replace('.', '_', $adm4) . '.json';
$cachedRaw = is_file($cacheFile) ? @file_get_contents($cacheFile) : false;
$cacheFresh = is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < 1800;

$raw = ($cacheFresh && is_string($cachedRaw) && $cachedRaw !== '') ? $cachedRaw : null;

if ($raw === null) {
    $url = 'https://api.bmkg.go.id/publik/prakiraan-cuaca?adm4=' . rawurlencode($adm4);
    $fetched = null;
    $errCode = 0;

    if (getenv('SMART_KJA_WASM') !== '1') {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_USERAGENT => 'BOLU/1.0 (BMKG wind forecast)',
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            $res = curl_exec($ch);
            $errCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (is_string($res) && $errCode >= 200 && $errCode < 300) {
                $fetched = $res;
            }
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 15,
                    'header' => "User-Agent: BOLU/1.0 (BMKG wind forecast)\r\nAccept: application/json\r\n",
                ],
            ]);
            $res = @file_get_contents($url, false, $ctx);
            if (is_string($res) && $res !== '') {
                $fetched = $res;
            }
        }
    }

    if ($fetched !== null) {
        $raw = $fetched;
        if (strlen($raw) < 1500000) {
            @file_put_contents($cacheFile, $raw);
        }
    } elseif (is_string($cachedRaw) && $cachedRaw !== '') {
        $raw = $cachedRaw;
    } else {
        $detail = $errCode > 0 ? (string)$errCode : 'timeout';
        json_raw(['ok' => false, 'error' => "BMKG tidak dapat diakses ({$detail})."], 502);
    }
}

$data = json_decode($raw, true);
if (!is_array($data) || !is_array($data['data'] ?? null)) {
    json_raw(['ok' => false, 'error' => 'Struktur respons BMKG tidak sesuai.'], 502);
}

$loc = is_array($data['lokasi'] ?? null) ? $data['lokasi'] : [];
$items = [];
foreach ($data['data'] as $group) {
    if (!is_array($group) || !is_array($group['cuaca'] ?? null)) {
        continue;
    }
    foreach ($group['cuaca'] as $forecasts) {
        if (!is_array($forecasts)) {
            continue;
        }
        foreach ($forecasts as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (!isset($item['ws'], $item['local_datetime']) || !is_numeric($item['ws'])) {
                continue;
            }
            $stamp = strtotime((string)$item['local_datetime']);
            if ($stamp === false) {
                continue;
            }
            $items[] = [
                'stamp' => $stamp,
                'time' => (string)$item['local_datetime'],
                'speed' => (float)$item['ws'],
                'direction' => (string)($item['wd'] ?? '—'),
            ];
        }
    }
}

if (!$items) {
    json_raw(['ok' => false, 'error' => 'Tidak ada data kecepatan angin untuk ADM4 ini.'], 502);
}

usort($items, static fn($a, $b) => $a['stamp'] <=> $b['stamp']);
$nowSec = time();
$choice = $items[count($items) - 1];
foreach ($items as $it) {
    if ($it['stamp'] >= $nowSec - 3600) {
        $choice = $it;
        break;
    }
}

$placeParts = array_filter([
    trim((string)($loc['desa'] ?? '')),
    trim((string)($loc['kecamatan'] ?? '')),
    trim((string)($loc['kotkab'] ?? '')),
    trim((string)($loc['provinsi'] ?? '')),
], static fn($v) => $v !== '');

json_raw([
    'ok' => true,
    'source' => 'BMKG — Prakiraan Cuaca Terbuka',
    'type' => 'coastal_village_forecast',
    'adm4' => $adm4,
    'place' => implode(', ', $placeParts),
    'forecast_local' => $choice['time'],
    'speed_kmh' => $choice['speed'],
    'speed_knots' => round($choice['speed'] / 1.852, 1),
    'wind_from' => $choice['direction'],
    'stale' => $choice['stamp'] < ($nowSec - 3600),
    'retrieved_at' => gmdate('c'),
]);
