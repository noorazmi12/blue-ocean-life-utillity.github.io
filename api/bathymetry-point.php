<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login_api();

$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;
if ($lat === null || $lng === null || !is_finite($lat) || !is_finite($lng)) {
    json_response(false, 'Koordinat tidak valid.', 400);
}

$geojsonPath = __DIR__ . '/../assets/data/betri/betri-titik.geojson';
$best = null;
$bestDist = INF;
if (is_file($geojsonPath)) {
    $geo = json_decode((string)file_get_contents($geojsonPath), true);
    foreach (($geo['features'] ?? []) as $f) {
        $coords = $f['geometry']['coordinates'] ?? null;
        if (!is_array($coords) || count($coords) < 2) {
            continue;
        }
        $d = hypot($lat - (float)$coords[1], $lng - (float)$coords[0]);
        if ($d < $bestDist) {
            $bestDist = $d;
            $best = [
                'lat' => (float)$coords[1],
                'lng' => (float)$coords[0],
                'depth_m' => $f['properties']['depth_m'] ?? ($f['properties']['ELEV'] ?? null),
            ];
        }
    }
}

json_response(true, $best ?? ['lat' => $lat, 'lng' => $lng, 'depth_m' => null]);
