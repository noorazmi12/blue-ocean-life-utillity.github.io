<?php
/**
 * BOLU — API Kedalaman Batimetri per Titik (proxy server-side)
 *
 * GET api/bathymetry-point.php?lat=..&lon=..
 *
 * Menarik kedalaman dari DUA sumber resmi dan menggabungkannya:
 *   1. BATNAS (SIBATNAS BIG) — WMS GetFeatureInfo pada seluruh layer grid
 *      pemeruman 100 m (properti NIKDLM = kedalaman dalam meter, positif).
 *      URL: https://sibatnas.big.go.id/geoserver/ows
 *   2. GEBCO 2020 — API OpenTopoData (grid GEBCO resmi, elevasi meter,
 *      negatif = di bawah muka laut). URL: https://api.opentopodata.org
 *
 * Respon JSON:
 *   { ok: true, lat, lon,
 *     batnas: { depth_m: float|null, layer: string|null },
 *     gebco:  { elev_m: float|null },
 *     cached: bool, fetched_at: "..." }
 *
 * Catatan desain:
 *  - Permintaan dilakukan SERVER-SIDE (menghindari CORS + menyembunyikan
 *    detail endpoint dari klien).
 *  - Cache file 6 jam per titik (grid batimetri bersifat statis) supaya
 *    hemat kuota API sumber (OpenTopoData free tier: 1 req/detik).
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_login_api();

/* ---------- Validasi input ---------- */
$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : 999;
$lon = isset($_GET['lon']) ? (float)$_GET['lon'] : 999;
if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
    json_fail('Parameter lat/lon tidak valid (lat -90..90, lon -180..180).');
}

/* ---------- Konstanta sumber ---------- */
const SIBATNAS_OWS = 'https://sibatnas.big.go.id/geoserver/ows';
const OPENTOPO_URL = 'https://api.opentopodata.org/v1/gebco2020';

/** Seluruh layer grid batimetri yang terbuka di SIBATNAS (probe GetCapabilities). */
const SIBATNAS_LAYERS = [
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
];

/* ---------- Cache file (6 jam) ---------- */
$cacheDir = sys_get_temp_dir() . '/bolu_bathy_cache';
if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0775, true); }
$cacheKey  = round($lat, 3) . '_' . round($lon, 3);
$cacheFile = $cacheDir . '/' . $cacheKey . '.json';
$cacheTtl  = 6 * 3600;

if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
    $hit = json_decode((string)file_get_contents($cacheFile), true);
    if (is_array($hit)) {
        $hit['cached'] = true;
        json_out($hit);
    }
}

/* ---------- cURL helper ---------- */
function bathy_http_get(string $url, int $timeout = 15): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'BOLU-Monitoring/1.0 (+bathymetry proxy)',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ($body !== false && $code >= 200 && $code < 300) ? $body : null;
}

/* ---------- 1) BATNAS: WMS GetFeatureInfo (v1.1.1) ---------- */
/* bbox ±0.2° dengan grid 101x101 px; x=y=50 -> tepat di titik klik. */
function bathy_batnas(float $lat, float $lon): array
{
    $d = 0.2;
    $bbox = ($lon - $d) . ',' . ($lat - $d) . ',' . ($lon + $d) . ',' . ($lat + $d);
    $layers = implode(',', SIBATNAS_LAYERS);
    $url = SIBATNAS_OWS . '?' . http_build_query([
        'service'     => 'WMS',
        'version'     => '1.1.1',
        'request'     => 'GetFeatureInfo',
        'layers'      => $layers,
        'query_layers' => $layers,
        'styles'      => '',
        'srs'         => 'EPSG:4326',
        'bbox'        => $bbox,
        'width'       => 101,
        'height'      => 101,
        'x'           => 50,
        'y'           => 50,
        'info_format' => 'application/json',
        'feature_count' => 20,
    ], '', '&', PHP_QUERY_RFC3986);

    $raw = bathy_http_get($url, 20);
    if ($raw === null) return ['depth_m' => null, 'layer' => null, 'error' => 'SIBATNAS tidak merespons'];

    $json = json_decode($raw, true);
    if (!is_array($json) || empty($json['features'])) {
        return ['depth_m' => null, 'layer' => null]; // di luar area grid ter-survei
    }
    foreach ($json['features'] as $f) {
        $props = $f['properties'] ?? [];
        foreach ($props as $k => $v) {
            if (stripos($k, 'NIKDLM') === 0 && is_numeric($v)) {
                // id feature: "sibatnas.LAYERNAME.NN" (ambil segmen layer)
                $layer = null;
                if (!empty($f['id']) && is_string($f['id'])) {
                    $parts = explode('.', $f['id']);
                    if (count($parts) >= 3) $layer = $parts[count($parts) - 2];
                    elseif (count($parts) === 2) $layer = $parts[0];
                }
                return ['depth_m' => round((float)$v, 2), 'layer' => $layer];
            }
        }
    }
    return ['depth_m' => null, 'layer' => null];
}

/* ---------- 2) GEBCO 2020 via OpenTopoData ---------- */
function bathy_gebco(float $lat, float $lon): array
{
    $url = OPENTOPO_URL . '?locations=' . rawurlencode(sprintf('%.6f,%.6f', $lat, $lon));
    $raw = bathy_http_get($url, 15);
    if ($raw === null) {
        // satu kali retry setelah jeda (free tier: 1 req/detik)
        sleep(1);
        $raw = bathy_http_get($url, 15);
    }
    if ($raw === null) return ['elev_m' => null, 'error' => 'API GEBCO tidak merespons'];
    $json = json_decode($raw, true);
    if (!is_array($json) || strtoupper((string)($json['status'] ?? '')) !== 'OK' || empty($json['results'][0])) {
        return ['elev_m' => null, 'error' => 'Respon API GEBCO tidak valid'];
    }
    $elev = $json['results'][0]['elevation'];
    return ['elev_m' => ($elev === null ? null : round((float)$elev, 1))];
}

$batnas = bathy_batnas($lat, $lon);
$gebco  = bathy_gebco($lat, $lon);

$out = [
    'ok'         => true,
    'lat'        => round($lat, 6),
    'lon'        => round($lon, 6),
    'batnas'     => $batnas,
    'gebco'      => $gebco,
    'cached'     => false,
    'fetched_at' => date('c'),
];
@file_put_contents($cacheFile, json_encode($out));
json_out($out);
