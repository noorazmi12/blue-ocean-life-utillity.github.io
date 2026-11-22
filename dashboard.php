<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/boot-data.php';
require_login();

$PAGE_ID = 'dashboard';
$PAGE_TITLE = 'Dashboard';
$BREADCRUMB = [['Dashboard', null]];
$PAGE_SCRIPTS = ['dashboard.js'];
$NEED_LEAFLET = true;

$pdo = db();
$water = $pdo->query('SELECT * FROM water_measurements ORDER BY measured_at DESC, id DESC LIMIT 1')->fetch();
$waterStatus = $water ? data_status($water['measured_at']) : 'offline';
$lastUpdate = $water['measured_at'] ?? null;

$phStatus = $water && $water['ph'] !== null ? evaluate_value('ph', (float)$water['ph']) : 'none';
$phLbl = $phStatus === 'normal' ? 'Normal' : ($phStatus === 'warning' ? 'Mendekati batas' : ($phStatus === 'critical' ? 'Di luar ambang' : 'Menunggu data'));

$recentAlerts = $pdo->query('SELECT a.*, d.device_name FROM alerts a LEFT JOIN devices d ON d.id = a.device_id ORDER BY (a.status="open") DESC, a.occurred_at DESC LIMIT 6')->fetchAll();
$bootPayload = [
    'water' => skja_boot_water(1440),
    'wave' => skja_boot_wave(1440),
    'energy' => skja_boot_energy(1440),
];

include __DIR__ . '/includes/header.php';
?>
<div class="dashboard-page">
    <section class="dashboard-hero">
        <div class="dashboard-hero-content">
            <div class="dashboard-hero-kicker"><span class="dashboard-live-dot"></span> SISTEM MONITORING BOLU</div>
            <h1>BOLU <span>Blue Ocean Life Utility</span></h1>
            <p>Integrasi energi hibrida laut dengan pemantauan kualitas air dan visualisasi kondisi perairan untuk mendukung kegiatan Keramba Jaring Apung.</p>
            <div class="dashboard-hero-meta">
                <?= status_pill($waterStatus) ?>
                <span><i class="fa-regular fa-clock"></i> Pembaruan data: <strong><?= e($lastUpdate ? fmt_dt($lastUpdate) : 'Menunggu data') ?></strong></span>
            </div>
        </div>
        <div class="dashboard-hero-chip"><i class="fa-solid fa-wave-square"></i> Monitoring aktif</div>
    </section>

    <div class="stat-section-title dashboard-section-title"><i class="fa-solid fa-tower-broadcast"></i> Status Perangkat</div>
    <div class="stat-grid dashboard-stat-grid">
        <a class="stat-card stat-blue" href="perangkat.php">
            <span class="stat-icon"><i class="fa-solid fa-microchip"></i></span>
            <span class="stat-info"><span class="stat-label">Perangkat Online</span><span class="stat-value" id="stDevices"><?= $devOnline ?>/<?= $devTotal ?></span><span class="stat-foot">Perangkat IoT aktif</span></span>
        </a>
        <a class="stat-card stat-navy" href="kualitas-air.php">
            <span class="stat-icon"><i class="fa-solid fa-temperature-half"></i></span>
            <span class="stat-info"><span class="stat-label">Suhu Air</span><span class="stat-value"><span id="stTemp"><?= $water ? fmt_num($water['temp_sensor_c'], 1) : '—' ?></span><span class="stat-unit">°C</span></span><span class="stat-foot">Sensor suhu air</span></span>
        </a>
        <a class="stat-card <?= $phStatus !== 'normal' && $phStatus !== 'none' ? 'stat-yellow' : 'stat-green' ?>" href="kualitas-air.php">
            <span class="stat-icon"><i class="fa-solid fa-vial"></i></span>
            <span class="stat-info"><span class="stat-label">pH Air</span><span class="stat-value" id="stPh"><?= $water ? fmt_num($water['ph'], 2) : '—' ?></span><span class="stat-foot"><?= e($phLbl) ?></span></span>
        </a>
    </div>

    <div class="stat-section-title dashboard-section-title"><i class="fa-solid fa-chart-line"></i> Pemantauan Kualitas Perairan</div>
    <div class="grid-2 dash-top dashboard-middle-grid">
        <div class="card dashboard-glass-card">
            <div class="card-header">
                <div><h3 class="card-title"><i class="fa-solid fa-chart-area"></i> Grafik Kualitas Air</h3><p class="card-sub">Riwayat perubahan parameter selama 24 jam</p></div>
                <div class="card-tools"><select class="form-select" id="waterParamSel" style="width:auto"><option value="ph">pH Air</option><option value="temp_sensor_c">Suhu Air (°C)</option></select></div>
            </div>
            <div class="card-body"><div class="chart-box"><canvas id="chartWater"></canvas></div></div>
        </div>
        <div class="card dashboard-glass-card dashboard-map-card">
            <div class="card-header">
                <div><h3 class="card-title"><i class="fa-solid fa-map-location-dot"></i> Peta Lokasi KJA</h3><p class="card-sub">Lokasi perangkat dan sensor pemantauan</p></div>
                <a class="btn btn-outline btn-sm" href="webgis.php">Lihat Detail <i class="fa-solid fa-arrow-up-right-from-square"></i></a>
            </div>
            <div class="card-body"><div class="map-box sm" id="miniMap"><span class="map-coord" id="miniMapCoord">—</span></div></div>
        </div>
    </div>

    <div class="grid-2 dashboard-bottom-grid">
        <div class="card dashboard-glass-card">
            <div class="card-header"><div><h3 class="card-title"><i class="fa-solid fa-bell"></i> Peringatan Terbaru</h3></div><a class="btn btn-ghost btn-sm" href="peringatan.php">Lihat Semua</a></div>
            <div class="alert-list" id="dashAlerts">
                <?php if (!$recentAlerts): ?><div class="empty-state"><i class="fa-regular fa-bell-slash"></i><p>Tidak ada peringatan.</p></div><?php endif; ?>
                <?php foreach ($recentAlerts as $al): ?>
                    <a class="alert-item <?= $al['status'] === 'resolved' ? 'resolved' : '' ?>" href="peringatan.php?id=<?= (int)$al['id'] ?>">
                        <span class="a-body"><span class="a-title"><?= e($al['title']) ?></span><span class="a-msg"><?= e($al['message']) ?></span><span class="a-meta"><span><?= e($al['device_name'] ?: '—') ?></span><span><?= e(time_ago($al['occurred_at'])) ?></span></span></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<script>window.SKJA_BOOT=<?= safe_json($bootPayload) ?>;</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
