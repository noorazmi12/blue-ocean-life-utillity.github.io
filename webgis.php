<?php
/**
 * BOLU — WebGIS (Leaflet.js + OpenStreetMap)
 * Peta perangkat KJA dengan filter layer, popup nilai terakhir, pencarian,
 * serta form tambah/ubah lokasi perangkat untuk administrator.
 *
 * Koordinat berasal dari tabel device_locations dan dapat diubah melalui
 * form di halaman ini (admin).
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$PAGE_ID    = 'webgis';
$PAGE_TITLE = 'WebGIS';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['WebGIS', null]];
$PAGE_SCRIPTS = ['map.js'];
$NEED_LEAFLET = true;

/* ================= POST: simpan lokasi perangkat (admin) ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_location') {
    csrf_check();
    if (!is_admin()) {
        flash_set('error', 'Hanya administrator yang dapat mengubah lokasi perangkat.');
        header('Location: webgis.php');
        exit;
    }
    $deviceId = (int)($_POST['device_id'] ?? 0);
    $lat = $_POST['latitude'] ?? '';
    $lng = $_POST['longitude'] ?? '';
    $locName = trim($_POST['location_name'] ?? '');
    $installed = ($_POST['installed_at'] ?? '') !== '' ? $_POST['installed_at'] : null;
    $notes = trim($_POST['notes'] ?? '') ?: null;

    $latOk = is_numeric($lat) && (float)$lat >= -90 && (float)$lat <= 90;
    $lngOk = is_numeric($lng) && (float)$lng >= -180 && (float)$lng <= 180;

    if ($deviceId <= 0 || !$latOk || !$lngOk) {
        flash_set('error', 'Data lokasi tidak valid (periksa perangkat, latitude -90..90, longitude -180..180).');
    } else {
        $st = db()->prepare(
            'INSERT INTO device_locations (device_id, latitude, longitude, location_name, installed_at, notes)
             VALUES (:d, :la, :lo, :ln, :ia, :no)
             ON DUPLICATE KEY UPDATE latitude = VALUES(latitude), longitude = VALUES(longitude),
                 location_name = VALUES(location_name), installed_at = VALUES(installed_at), notes = VALUES(notes)'
        );
        $st->execute([
            ':d' => $deviceId, ':la' => (float)$lat, ':lo' => (float)$lng,
            ':ln' => $locName, ':ia' => $installed, ':no' => $notes
        ]);
        flash_set('success', 'Lokasi perangkat berhasil disimpan.');
    }
    header('Location: webgis.php');
    exit;
}

/* ---------- Daftar perangkat untuk form admin ---------- */
$deviceList = db()->query(
    "SELECT d.id, d.device_code, d.device_name, d.device_type, d.status,
            l.latitude, l.longitude, l.location_name, l.installed_at, l.notes
     FROM devices d LEFT JOIN device_locations l ON l.device_id = d.id
     WHERE d.is_active = 1 ORDER BY d.device_name"
)->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-map-location-dot"></i> WebGIS</h1>
        <p class="page-desc">Peta interaktif lokasi KJA dan seluruh perangkat pemantauan (OWC, sensor gelombang,
            sensor kualitas air, kamera FishCount). Nilai pada popup diambil langsung dari pengukuran terbaru.</p>
    </div>
    <div class="page-actions">
        <?php if (is_admin()): ?>
        <button type="button" class="btn btn-primary btn-sm" id="btnAddLocation">
            <i class="fa-solid fa-plus"></i> Tambah / Ubah Lokasi
        </button>
        <?php endif; ?>
        <span class="source-note"><i class="fa-solid fa-map"></i> Imagery Esri &middot; &copy; OpenStreetMap</span>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-layer-group"></i> Peta Perangkat</h3>
            <p class="card-sub">Gunakan filter layer untuk menampilkan jenis perangkat tertentu</p>
        </div>
        <div class="card-tools">
            <div class="input-group">
                <input type="text" class="form-control" id="searchDevice" placeholder="Cari perangkat..." style="width:200px">
                <button type="button" class="btn btn-outline btn-sm" id="btnSearch"><i class="fa-solid fa-magnifying-glass"></i></button>
            </div>
        </div>
    </div>
    <div class="card-body" style="position:relative">
        <div class="map-box" id="webgisMap"><span class="map-coord" id="mapCoord">—</span></div>
        <div class="no-print" style="display:flex;gap:16px;flex-wrap:wrap;margin-top:12px;align-items:center">
            <span class="form-label" style="margin:0">Filter Layer:</span>
            <label style="display:inline-flex;gap:7px;align-items:center;font-size:13px;font-weight:600">
                <input type="checkbox" class="layer-chk" data-type="owc" checked> <i class="fa-solid fa-bolt" style="color:var(--accent-dark)"></i> Unit OWC
            </label>
            <label style="display:inline-flex;gap:7px;align-items:center;font-size:13px;font-weight:600">
                <input type="checkbox" class="layer-chk" data-type="wave_sensor" checked> <i class="fa-solid fa-water" style="color:var(--blue)"></i> Sensor Gelombang
            </label>
            <label style="display:inline-flex;gap:7px;align-items:center;font-size:13px;font-weight:600">
                <input type="checkbox" class="layer-chk" data-type="water_sensor" checked> <i class="fa-solid fa-droplet" style="color:var(--green)"></i> Sensor Kualitas Air
            </label>
            <label style="display:inline-flex;gap:7px;align-items:center;font-size:13px;font-weight:600">
                <input type="checkbox" class="layer-chk" data-type="camera" checked> <i class="fa-solid fa-video" style="color:#8E44AD"></i> Kamera FishCount
            </label>
        </div>
    </div>
    <div class="map-legend">
        <span class="legend-item"><span class="legend-swatch owc"></span> Unit OWC</span>
        <span class="legend-item"><span class="legend-swatch wave"></span> Sensor Gelombang</span>
        <span class="legend-item"><span class="legend-swatch water"></span> Sensor Kualitas Air</span>
        <span class="legend-item"><span class="legend-swatch camera"></span> Kamera FishCount</span>
        <span class="legend-item"><i class="fa-solid fa-circle-info"></i> Marker abu-abu = offline · Klik marker untuk nilai terbaru</span>
    </div>
</div>

<!-- Daftar koordinat -->
<div class="card">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-location-crosshairs"></i> Informasi Koordinat Perangkat</h3>
            <p class="card-sub">Sumber: tabel <code>device_locations</code></p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Perangkat</th><th>Jenis</th><th>Nama Lokasi</th><th>Latitude</th><th>Longitude</th><th>Terpasang</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($deviceList as $d): ?>
                <tr>
                    <td class="table-strong"><?= e($d['device_name']) ?> <span class="source-note">(<?= e($d['device_code']) ?>)</span></td>
                    <td><span class="badge badge-outline"><?= e(str_replace('_', ' ', $d['device_type'])) ?></span></td>
                    <td><?= e($d['location_name'] ?: '—') ?></td>
                    <td class="num"><?= $d['latitude'] !== null ? fmt_num($d['latitude'], 7) : '—' ?></td>
                    <td class="num"><?= $d['longitude'] !== null ? fmt_num($d['longitude'], 7) : '—' ?></td>
                    <td><?= $d['installed_at'] ? e(fmt_dt($d['installed_at'])) : '—' ?></td>
                    <td class="td-actions">
                        <?php if ($d['latitude'] !== null): ?>
                            <button type="button" class="btn btn-ghost btn-sm btn-fly" data-lat="<?= e($d['latitude']) ?>" data-lng="<?= e($d['longitude']) ?>" title="Tampilkan di peta"><i class="fa-solid fa-location-dot"></i></button>
                        <?php endif; ?>
                        <?php if (is_admin()): ?>
                            <button type="button" class="btn btn-outline btn-sm btn-edit-loc"
                                data-id="<?= (int)$d['id'] ?>" data-code="<?= e($d['device_code']) ?>"
                                data-lat="<?= e($d['latitude'] ?? '') ?>" data-lng="<?= e($d['longitude'] ?? '') ?>"
                                data-loc="<?= e($d['location_name'] ?? '') ?>" data-inst="<?= e($d['installed_at'] ?? '') ?>"
                                data-notes="<?= e($d['notes'] ?? '') ?>">
                                <i class="fa-solid fa-pen"></i> Ubah
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
/* Boot data peta: titik perangkat digambar langsung dari HTML (tanpa fetch API),
   sehingga peta + marker selalu tampil meski panggilan API gagal. */
require_once __DIR__ . '/includes/boot-data.php';
skja_boot_script(['geo' => skja_boot_geo()]);
include __DIR__ . '/includes/footer.php';
?>
