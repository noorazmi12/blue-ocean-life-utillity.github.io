<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login_api();

$latRaw = $_GET['lat'] ?? null;
$lonRaw = $_GET['lon'] ?? null;
$lat = is_numeric($latRaw) ? (float)$latRaw : NAN;
$lon = is_numeric($lonRaw) ? (float)$lonRaw : NAN;

if (!is_finite($lat) || !is_finite($lon) || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
    json_raw(['ok' => false, 'error' => 'Parameter lat/lon tidak valid (lat -90..90, lon -180..180).'], 400);
}

$cacheKey = sprintf('bolu_bathy_%.3f_%.3f.json', $lat, $lon);
$cacheFile = sys_get_temp_dir() . '/' . $cacheKey;
if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < 6 * 3600) {
    $cachedJson = @file_get_contents($cacheFile);
    $decoded = $cachedJson ? json_decode($cachedJson, true) : null;
    if (is_array($decoded)) {
        $decoded['cached'] = true;
        json_raw($decoded);
    }
}

function bathy_http_get(string $url, int $timeoutSec = 15): ?string
{
    if (getenv('SMART_KJA_WASM') === '1') {
        return null;
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeoutSec),
            CURLOPT_USERAGENT => 'BOLU-Monitoring/1.0 (+bathymetry proxy)',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($body) && $code >= 200 && $code < 300) {
            return $body;
        }
        return null;
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSec,
            'header' => "User-Agent: BOLU-Monitoring/1.0 (+bathymetry proxy)\r\nAccept: application/json\r\n",
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    return is_string($raw) ? $raw : null;
}

$sibatnasOws = 'https://sibatnas.big.go.id/geoserver/ows';
$sibatnasLayers = implode(',', [
    'sibatnas:AREA_MBES_LKI_BARAT_SUMATERA',
    'sibatnas:BALIKPAPAN_100M',
    'sibatnas:BANJARMASIN_100M',
    'sibatnas:Batam',
    'sibatnas:MBTK',
    'sibatnas:MOROWALI',
    'sibatnas:SAMARINDA_100M',
    'sibatnas:SAMPIT_100M',
    'sibatnas:SORONG_100M',
    'sibatnas:TERNATE_100m',
    'sibatnas:TITIKDALAMPERUM_BTG_100M_GCS',
    'sibatnas:TJK_100M',
    'sibatnas:Titik_Kedalaman_Pemeruman_100m',
    'sibatnas:bantaeng_100m',
    'sibatnas:big_tanjungpriuk_000020190906161134',
    'sibatnas:morotai_100m',
    'sibatnas:palembang_100m',
]);

$d = 0.2;
$bbox = ($lon - $d) . ',' . ($lat - $d) . ',' . ($lon + $d) . ',' . ($lat + $d);
$qs = http_build_query([
    'service' => 'WMS',
    'version' => '1.1.1',
    'request' => 'GetFeatureInfo',
    'layers' => $sibatnasLayers,
    'query_layers' => $sibatnasLayers,
    'styles' => '',
    'srs' => 'EPSG:4326',
    'bbox' => $bbox,
    'width' => '101',
    'height' => '101',
    'x' => '50',
    'y' => '50',
    'info_format' => 'application/json',
    'feature_count' => '20',
]);

$rawKey = sprintf('%.3f_%.3f', $lat, $lon);
$prefBatnasFile = sys_get_temp_dir() . '/bolu_raw_batnas_' . $rawKey . '.json';
$prefGebcoFile = sys_get_temp_dir() . '/bolu_raw_gebco_' . $rawKey . '.json';

$batnas = ['depth_m' => null, 'layer' => null];
$rawBatnas = is_file($prefBatnasFile) ? (@file_get_contents($prefBatnasFile) ?: null) : bathy_http_get($sibatnasOws . '?' . $qs, 20);
if ($rawBatnas === null) {
    $batnas['error'] = 'SIBATNAS tidak merespons';
} else {
    $jBatnas = json_decode($rawBatnas, true);
    if (!is_array($jBatnas)) {
        $batnas['error'] = 'Respon SIBATNAS tidak valid';
    } elseif (!empty($jBatnas['features']) && is_array($jBatnas['features'])) {
        foreach ($jBatnas['features'] as $f) {
            $props = is_array($f['properties'] ?? null) ? $f['properties'] : [];
            foreach ($props as $k => $v) {
                if (str_starts_with(strtoupper((string)$k), 'NIKDLM') && $v !== null && is_numeric($v)) {
                    $layer = null;
                    if (is_string($f['id'] ?? null)) {
                        $parts = explode('.', $f['id']);
                        if (count($parts) >= 3) {
                            $layer = $parts[count($parts) - 2];
                        } elseif (count($parts) === 2) {
                            $layer = $parts[0];
                        }
                    }
                    $batnas = ['depth_m' => round((float)$v, 2), 'layer' => $layer];
                    break 2;
                }
            }
        }
    }
}

$openTopoUrl = 'https://api.opentopodata.org/v1/gebco2020?locations=' . rawurlencode(sprintf('%.6f,%.6f', $lat, $lon));
$rawGebco = is_file($prefGebcoFile) ? (@file_get_contents($prefGebcoFile) ?: null) : bathy_http_get($openTopoUrl, 15);
if ($rawGebco === null && getenv('SMART_KJA_WASM') !== '1') {
    usleep(500000);
    $rawGebco = bathy_http_get($openTopoUrl, 15);
}
$gebco = ['elev_m' => null];
if ($rawGebco === null) {
    $gebco['error'] = 'API GEBCO tidak merespons';
} else {
    $jGebco = json_decode($rawGebco, true);
    if (!is_array($jGebco) || strtoupper((string)($jGebco['status'] ?? '')) !== 'OK' || empty($jGebco['results'][0])) {
        $gebco['error'] = 'Respon API GEBCO tidak valid';
    } else {
        $elev = $jGebco['results'][0]['elevation'] ?? null;
        $gebco['elev_m'] = ($elev !== null && is_numeric($elev)) ? round((float)$elev, 1) : null;
    }
}

$out = [
    'ok' => true,
    'lat' => round($lat, 6),
    'lon' => round($lon, 6),
    'batnas' => $batnas,
    'gebco' => $gebco,
    'cached' => false,
    'fetched_at' => gmdate('c'),
];

@file_put_contents($cacheFile, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
json_raw($out);
