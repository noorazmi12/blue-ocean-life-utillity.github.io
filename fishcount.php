<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

$camDev = db()->query(
    "SELECT * FROM devices WHERE device_type = 'camera' AND is_active = 1 ORDER BY id ASC LIMIT 1"
)->fetch() ?: null;

$camLoc = null;
if ($camDev) {
    $stLoc = db()->prepare('SELECT * FROM device_locations WHERE device_id = ? LIMIT 1');
    $stLoc->execute([(int)$camDev['id']]);
    $camLoc = $stLoc->fetch() ?: null;
}

$camera = $camDev ? [
    'id' => (int)$camDev['id'],
    'device_code' => $camDev['device_code'],
    'device_name' => $camDev['device_name'],
    'status' => $camDev['status'],
    'latitude' => $camLoc ? (float)$camLoc['latitude'] : null,
    'longitude' => $camLoc ? (float)$camLoc['longitude'] : null,
    'location_name' => $camLoc ? $camLoc['location_name'] : null,
    'notes' => $camLoc ? $camLoc['notes'] : null,
] : null;

$cameraObj = [
    'id' => $camera ? $camera['id'] : null,
    'code' => $camera ? $camera['device_code'] : 'CAM-001',
    'name' => $camera ? $camera['device_name'] : 'ESP32-CAM OV2640',
    'lat' => ($camera && $camera['latitude'] !== null) ? $camera['latitude'] : -5.7703,
    'lon' => ($camera && $camera['longitude'] !== null) ? $camera['longitude'] : 105.2642,
    'location_name' => ($camera && !empty($camera['location_name'])) ? $camera['location_name'] : 'Titik pemasangan kamera',
];

$PAGE_ID = 'fishcount';
$PAGE_TITLE = 'FishCount AI — Live Monitoring';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['FishCount AI', null]];
$PAGE_SCRIPTS = ['fishcount-live.js'];
$NEED_LEAFLET = true;

require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="assets/css/fishcount-live.css?v=1">

<div class="page-header">
  <div class="page-title">
    <h1><i class="fa-solid fa-fish"></i> FishCount AI</h1>
    <p class="page-desc">Pemantauan kamera ESP32-CAM OV2640, deteksi objek umum, pelacakan ikan, dan penghitungan ikan yang melintasi garis virtual.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline btn-sm" href="fishcount-species.php"><i class="fa-solid fa-brain"></i> Uji Klasifikasi Spesies</a>
    <span class="badge badge-outline" id="fcConnectionBadge"><span class="dot dot-offline"></span> Menghubungkan bridge</span>
  </div>
</div>

<div class="fc-live-grid">
  <section class="card fc-camera-card">
    <div class="card-header">
      <div>
        <h3 class="card-title"><i class="fa-solid fa-video"></i> Live Camera Monitoring</h3>
        <p class="card-sub" id="fcCameraCaption">ESP32-CAM OV2640 · YOLO11 dual-model</p>
      </div>
      <span class="badge badge-gray" id="fcFpsBadge">0 FPS</span>
    </div>
    <div class="card-body">
      <div class="fc-stream-frame">
      <img
    id="fcLiveStream"
    src="http://127.0.0.1:5000/video_feed"
    alt="Live stream ESP32-CAM dengan anotasi YOLO11"
    style="display:block; width:100%; height:100%; object-fit:contain;"
>
        <div class="fc-stream-placeholder" id="fcStreamPlaceholder">
          <i class="fa-solid fa-camera"></i>
          <strong>Menunggu stream kamera</strong>
          <span>Pastikan ESP32-CAM dan Python bridge sedang aktif.</span>
        </div>
      </div>
      <div class="fc-stream-foot">
        <span id="fcBridgeStatus"><span class="dot dot-offline"></span> Bridge belum terhubung</span>
        <span id="fcLastRefresh">Menunggu data...</span>
      </div>
    </div>
  </section>

  <section class="fc-stat-column">
    <div class="card fc-total-card">
      <div class="card-body">
        <span class="fc-stat-label">Total ikan melewati garis</span>
        <strong class="fc-total-number" id="fcTotal">—</strong>
        <span class="fc-stat-unit">ekor · hitungan kumulatif sesi bridge</span>
        <div class="fc-direction-grid">
          <div><span>Kiri → kanan</span><strong id="fcLeftRight">0</strong></div>
          <div><span>Kanan → kiri</span><strong id="fcRightLeft">0</strong></div>
        </div>
        <button type="button" class="btn btn-outline btn-sm" id="fcResetCount"><i class="fa-solid fa-rotate-left"></i> Reset hitungan</button>
      </div>
    </div>
    <div class="card">
      <div class="card-body fc-mini-stats">
        <div><span>Ikan terlihat sekarang</span><strong id="fcVisibleFish">—</strong></div>
        <div><span>Objek umum terlihat</span><strong id="fcVisibleGeneral">—</strong></div>
        <div><span>FPS pemrosesan</span><strong id="fcFps">—</strong></div>
        <div><span>Model</span><strong>YOLO11 + ByteTrack</strong></div>
      </div>
    </div>
    <div class="callout callout-info fc-note">
      <i class="fa-solid fa-circle-info"></i>
      <div><strong>Aturan hitung:</strong> hanya deteksi dari model ikan yang melintasi garis virtual yang menambah total. Deteksi laptop, motor, orang, dan objek umum lain tidak ikut dihitung.</div>
    </div>
  </section>
</div>

<section class="card fc-bridge-card">
  <div class="card-header">
    <div>
      <h3 class="card-title"><i class="fa-solid fa-link"></i> Koneksi Python AI Bridge</h3>
      <p class="card-sub">Alamat Flask yang menjalankan YOLO11 dan membaca stream ESP32-CAM.</p>
    </div>
  </div>
  <div class="card-body">
    <div class="fc-bridge-controls">
      <label class="fc-field">
        <span>Alamat AI Bridge</span>
        <input class="form-control" id="fcBridgeUrl" type="url" value="http://127.0.0.1:5000" placeholder="http://127.0.0.1:5000">
      </label>
      <button type="button" class="btn btn-primary" id="fcConnect"><i class="fa-solid fa-plug"></i> Hubungkan</button>
      <button type="button" class="btn btn-outline" id="fcOpenBridge"><i class="fa-solid fa-arrow-up-right-from-square"></i> Buka bridge</button>
    </div>
    <p class="form-hint">Jika website dibuka pada laptop yang sama dengan Python, gunakan <code>http://127.0.0.1:5000</code>. Jika website dibuka dari perangkat lain, gunakan alamat IP laptop yang menjalankan Python bridge.</p>
  </div>
</section>

<section class="card fc-placement-card">
  <div class="card-header">
    <div>
      <h3 class="card-title"><i class="fa-solid fa-map-location-dot"></i> Penempatan ESP32-CAM</h3>
      <p class="card-sub">Klik peta atau geser marker untuk menentukan titik pemasangan kamera pada lokasi KJA.</p>
    </div>
    <span class="badge badge-outline"><?= $camera ? e($camera['device_code']) : 'Kamera belum terdaftar' ?></span>
  </div>
  <div class="card-body">
    <div class="fc-placement-layout">
      <div id="fcCameraMap" class="fc-camera-map" role="application" aria-label="Peta penempatan kamera"></div>
      <div class="fc-placement-form">
        <div class="fc-placement-help"><i class="fa-solid fa-hand-pointer"></i> Klik peta untuk memindahkan kamera. Marker juga bisa digeser langsung.</div>
        <label class="fc-field">
          <span>Nama titik pemasangan</span>
          <input class="form-control" id="fcLocationName" type="text" maxlength="120" value="<?= e($camera && !empty($camera['location_name']) ? $camera['location_name'] : 'Titik pemasangan kamera') ?>">
        </label>
        <div class="fc-coord-grid">
          <label class="fc-field"><span>Latitude</span><input class="form-control" id="fcLatitude" type="number" step="0.0000001" value="<?= e(number_format((float)($camera && $camera['latitude'] !== null ? $camera['latitude'] : -5.7703), 7, '.', '')) ?>"></label>
          <label class="fc-field"><span>Longitude</span><input class="form-control" id="fcLongitude" type="number" step="0.0000001" value="<?= e(number_format((float)($camera && $camera['longitude'] !== null ? $camera['longitude'] : 105.2642), 7, '.', '')) ?>"></label>
        </div>
        <button type="button" class="btn btn-primary" id="fcSaveLocation" <?= !$camera ? 'disabled' : '' ?>><i class="fa-solid fa-floppy-disk"></i> Simpan posisi kamera</button>
        <p class="form-hint" id="fcLocationFeedback"><?= $camera ? 'Posisi tersimpan pada data lokasi perangkat kamera.' : 'Tambahkan perangkat bertipe camera terlebih dahulu melalui Manajemen Perangkat.' ?></p>
        <?php if (!$camera): ?><a class="btn btn-outline btn-sm" href="perangkat.php">Buka Manajemen Perangkat</a><?php endif; ?>
      </div>
    </div>
  </div>
</section>

<section class="card">
  <div class="card-header">
    <div><h3 class="card-title"><i class="fa-solid fa-compass"></i> Panduan pemasangan kamera</h3><p class="card-sub">Acuan awal saat menempatkan ESP32-CAM pada keramba.</p></div>
  </div>
  <div class="card-body fc-install-grid">
    <div><i class="fa-solid fa-water"></i><strong>Area pengamatan</strong><span>Tempatkan kamera menghadap jalur ikan yang ingin diamati, bukan ke permukaan atau dinding keramba.</span></div>
    <div><i class="fa-solid fa-arrows-left-right"></i><strong>Lintasan ikan</strong><span>Usahakan lintasan ikan memotong garis virtual pada gambar. Jaga posisi kamera tetap stabil.</span></div>
    <div><i class="fa-solid fa-lightbulb"></i><strong>Pencahayaan</strong><span>Hindari lampu tepat di depan lensa dan pantulan kuat pada air yang dapat mengganggu deteksi.</span></div>
    <div><i class="fa-solid fa-ruler-combined"></i><strong>Jarak kamera</strong><span>Atur jarak agar tubuh ikan terlihat utuh dan tidak terlalu kecil atau saling menutupi.</span></div>
  </div>
</section>

<script>
window.BOLU_FISHCOUNT_CAMERA = <?= safe_json($cameraObj) ?>;
window.BOLU_FISHCOUNT_CSRF = <?= safe_json(csrf_token()) ?>;
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
