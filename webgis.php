<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!is_admin()) {
        flash_set('error', 'Hanya admin yang dapat memperbarui koordinat lokasi.');
        header('Location: webgis.php');
        exit;
    }
    $devId = (int)($_POST['device_id'] ?? 0);
    $lat = (float)($_POST['latitude'] ?? 0);
    $lng = (float)($_POST['longitude'] ?? 0);
    $name = trim((string)($_POST['location_name'] ?? ''));
    if ($devId > 0) {
        $exists = $pdo->prepare('SELECT id FROM device_locations WHERE device_id = ? LIMIT 1');
        $exists->execute([$devId]);
        if ($exists->fetch()) {
            $pdo->prepare('UPDATE device_locations SET latitude=?, longitude=?, location_name=?, updated_at=NOW() WHERE device_id=?')
                ->execute([$lat, $lng, $name, $devId]);
        } else {
            $pdo->prepare('INSERT INTO device_locations (device_id, latitude, longitude, location_name, installed_at, updated_at) VALUES (?, ?, ?, ?, CURDATE(), NOW())')
                ->execute([$devId, $lat, $lng, $name]);
        }
        flash_set('success', 'Lokasi perangkat berhasil diperbarui.');
    }
    header('Location: webgis.php');
    exit;
}

$PAGE_ID = 'webgis';
$PAGE_TITLE = 'WebGIS Lokasi KJA';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['WebGIS', null]];
$PAGE_SCRIPTS = ['map.js'];
$NEED_LEAFLET = true;

$devices = $pdo->query(
    'SELECT d.id, d.device_code AS code, d.device_name AS name, d.device_type AS type, d.status,
            l.latitude, l.longitude, l.location_name
     FROM devices d LEFT JOIN device_locations l ON l.device_id = d.id WHERE d.is_active = 1 ORDER BY d.id'
)->fetchAll();

$bootPayload = [
    'center' => ['lat' => setting_float('map_center_lat', -5.77), 'lng' => setting_float('map_center_lng', 105.264)],
    'zoom' => setting_int('map_default_zoom', 15),
    'site_location' => setting('site_location', 'Teluk Hurun, Lampung'),
    'devices' => $devices,
];

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-map-location-dot"></i> WebGIS &amp; Lokasi Perangkat KJA</h1>
        <p class="page-desc">Peta interaktif posisi ponton OWC, sensor kualitas air, buoy gelombang, dan kamera FishCount.</p>
    </div>
</div>

<div class="card">
    <div class="card-header"><div><h3 class="card-title"><i class="fa-solid fa-map"></i> Peta Spasial KJA</h3></div></div>
    <div class="card-body"><div class="map-box lg" id="webgisMap"></div></div>
</div>

<?php if (is_admin()): ?>
<div class="card">
    <div class="card-header"><div><h3 class="card-title">Ubah Koordinat Perangkat</h3></div></div>
    <div class="card-body">
        <form method="post" class="grid-2">
            <?= csrf_field() ?>
            <div class="form-group">
                <label class="form-label" for="device_id">Perangkat</label>
                <select class="form-select" id="device_id" name="device_id">
                    <?php foreach ($devices as $d): ?>
                        <option value="<?= (int)$d['id'] ?>"><?= e($d['code'] . ' — ' . $d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label class="form-label" for="location_name">Nama Lokasi</label><input class="form-control" id="location_name" name="location_name" required></div>
            <div class="form-group"><label class="form-label" for="latitude">Latitude</label><input class="form-control" id="latitude" name="latitude" type="number" step="0.000001" required></div>
            <div class="form-group"><label class="form-label" for="longitude">Longitude</label><input class="form-control" id="longitude" name="longitude" type="number" step="0.000001" required></div>
            <div><button type="submit" class="btn btn-primary btn-sm">Simpan Lokasi</button></div>
        </form>
    </div>
</div>
<?php endif; ?>
<script>window.SKJA_BOOT=<?= safe_json($bootPayload) ?>;</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
