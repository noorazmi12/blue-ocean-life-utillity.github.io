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

$staleMin = max(1, setting_int('data_stale_minutes', 10));
$cutoff = date('Y-m-d H:i:s', time() - $staleMin * 60);
$devStmt = db()->prepare(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = 'online' AND last_communication IS NOT NULL AND last_communication >= ? THEN 1 ELSE 0 END) AS online_cnt
     FROM devices WHERE is_active = 1"
);
$devStmt->execute([$cutoff]);
$devRow = $devStmt->fetch();
$devTotal = (int)($devRow['total'] ?? 0);
$devOnline = (int)($devRow['online_cnt'] ?? 0);

$water = db()->query(
    "SELECT wm.*
     FROM water_measurements wm
     JOIN devices d ON d.id = wm.device_id
     WHERE d.device_type = 'water_sensor' AND d.is_active = 1
     ORDER BY wm.measured_at DESC, wm.id DESC
     LIMIT 1"
)->fetch() ?: null;

$recentAlerts = db()->query(
    "SELECT a.*, d.device_name
     FROM alerts a
     LEFT JOIN devices d ON d.id = a.device_id
     WHERE a.parameter NOT IN ('salinity_psu', 'turbidity_ntu')
     ORDER BY CASE WHEN a.status = 'open' THEN 1 ELSE 0 END DESC, a.occurred_at DESC, a.id DESC
     LIMIT 6"
)->fetchAll();

$lastUpdateCol = db()->query('SELECT MAX(measured_at) FROM water_measurements')->fetchColumn();
$lastUpdate = $lastUpdateCol !== false ? $lastUpdateCol : null;

$waterStatus = data_status($water['measured_at'] ?? null);
$wEval = [
    'temp_sensor_c' => $water ? evaluate_value_info('temp_sensor_c', $water['temp_sensor_c'] !== null ? (float)$water['temp_sensor_c'] : null) : null,
    'ph' => $water ? evaluate_value_info('ph', $water['ph'] !== null ? (float)$water['ph'] : null) : null,
];

$bootPayload = [
    'wave' => skja_boot_wave(1440),
    'power' => skja_boot_energy(1440),
    'water' => [
        'ph' => skja_boot_water('ph', 1440),
        'temp_sensor_c' => skja_boot_water('temp_sensor_c', 1440),
    ],
    'geo' => skja_boot_geo(),
];

require __DIR__ . '/includes/header.php';
?>

<div class="dashboard-page">
    <!-- HERO DASHBOARD -->
    <section class="dashboard-hero">
        <div class="dashboard-hero-content">
            <div class="dashboard-hero-kicker">
                <span class="dashboard-live-dot"></span>
                SISTEM MONITORING BOLU
            </div>
            <h1>BOLU <span>Blue Ocean Life Utility</span></h1>
            <p>Integrasi energi hibrida laut dengan pemantauan kualitas air dan visualisasi kondisi perairan untuk mendukung kegiatan Keramba Jaring Apung.</p>
            <div class="dashboard-hero-meta">
                <?= status_pill($waterStatus) ?>
                <span><i class="fa-regular fa-clock"></i> Pembaruan data: <strong><?= e($lastUpdate ? fmt_dt($lastUpdate) : 'Menunggu data') ?></strong></span>
            </div>
        </div>
        <div class="dashboard-hero-chip"><i class="fa-solid fa-wave-square"></i> Monitoring aktif</div>
    </section>

    <!-- RINGKASAN SENSOR -->
    <div class="stat-section-title dashboard-section-title"><i class="fa-solid fa-tower-broadcast"></i> Status Perangkat</div>
    <div class="stat-grid dashboard-stat-grid">
        <a class="stat-card stat-blue" href="perangkat.php" title="Lihat daftar perangkat">
            <span class="stat-icon"><i class="fa-solid fa-microchip"></i></span>
            <span class="stat-info">
                <span class="stat-label">Perangkat Online</span>
                <span class="stat-value" id="stDevices"><?= $devOnline ?>/<?= $devTotal ?></span>
                <span class="stat-foot">Perangkat IoT aktif</span>
            </span>
        </a>
        <a class="stat-card stat-navy" href="kualitas-air.php">
            <span class="stat-icon"><i class="fa-solid fa-temperature-half"></i></span>
            <span class="stat-info">
                <span class="stat-label">Suhu Air <?= $water ? demo_badge($water['is_simulated']) : '' ?></span>
                <span class="stat-value"><span id="stTemp"><?= $water ? fmt_num($water['temp_sensor_c'], 1) : '—' ?></span><span class="stat-unit">°C</span></span>
                <span class="stat-foot">Sensor suhu air</span>
            </span>
        </a>
        <a class="stat-card <?= ($wEval['ph'] && $wEval['ph']['status'] !== 'normal') ? 'stat-yellow' : 'stat-green' ?>" href="kualitas-air.php">
            <span class="stat-icon"><i class="fa-solid fa-vial"></i></span>
            <span class="stat-info">
                <span class="stat-label">pH Air</span>
                <span class="stat-value" id="stPh"><?= $water ? fmt_num($water['ph'], 2) : '—' ?></span>
                <span class="stat-foot"><?= $wEval['ph'] ? e($wEval['ph']['label']) : 'Menunggu data' ?></span>
            </span>
        </a>
    </div>

    <!-- GRAFIK DAN PETA -->
    <div class="stat-section-title dashboard-section-title"><i class="fa-solid fa-chart-line"></i> Pemantauan Kualitas Perairan</div>
    <div class="grid-2 dash-top dashboard-middle-grid">
        <div class="card dashboard-glass-card">
            <div class="card-header">
                <div>
                    <h3 class="card-title"><i class="fa-solid fa-chart-area"></i> Grafik Kualitas Air</h3>
                    <p class="card-sub">Riwayat perubahan parameter selama 24 jam</p>
                </div>
                <div class="card-tools">
                    <select class="form-select" id="waterParamSel" style="width:auto">
                        <option value="ph">pH Air</option>
                        <option value="temp_sensor_c">Suhu Air (°C)</option>
                    </select>
                </div>
            </div>
            <div class="card-body">
                <div class="chart-box"><canvas id="chartWater"></canvas></div>
            </div>
        </div>

        <div class="card dashboard-glass-card dashboard-map-card">
            <div class="card-header">
                <div>
                    <h3 class="card-title"><i class="fa-solid fa-map-location-dot"></i> Peta Lokasi KJA</h3>
                    <p class="card-sub">Lokasi perangkat dan sensor pemantauan</p>
                </div>
                <a class="btn btn-outline btn-sm" href="webgis.php">Lihat Detail <i class="fa-solid fa-arrow-up-right-from-square"></i></a>
            </div>
            <div class="card-body">
                <div class="map-box sm" id="miniMap"><span class="map-coord" id="miniMapCoord">—</span></div>
            </div>
            <div class="map-legend">
                <span class="legend-item"><span class="legend-swatch water"></span> Sensor Kualitas Air</span>
                <span class="legend-item"><span class="legend-swatch camera"></span> Kamera FishCount</span>
                <span class="legend-item"><i class="fa-solid fa-circle-info"></i> Perangkat offline</span>
            </div>
        </div>
    </div>

    <!-- PERINGATAN DAN RINGKASAN -->
    <div class="grid-2 dashboard-bottom-grid">
        <div class="card dashboard-glass-card">
            <div class="card-header">
                <div>
                    <h3 class="card-title"><i class="fa-solid fa-bell"></i> Peringatan Terbaru</h3>
                    <p class="card-sub">Informasi yang perlu diperhatikan operator</p>
                </div>
                <a class="btn btn-ghost btn-sm" href="peringatan.php">Lihat Semua <i class="fa-solid fa-angle-right"></i></a>
            </div>
            <div class="alert-list" id="dashAlerts">
                <?php if (!$recentAlerts): ?>
                    <div class="empty-state"><i class="fa-regular fa-bell-slash"></i><p>Tidak ada peringatan.</p></div>
                <?php endif; ?>
                <?php foreach ($recentAlerts as $al): ?>
                <a class="alert-item <?= $al['status'] === 'resolved' ? 'resolved' : '' ?>" href="peringatan.php?id=<?= (int)$al['id'] ?>">
                    <span class="a-icon <?= e($al['severity']) ?>">
                        <i class="fa-solid <?= $al['severity'] === 'critical' ? 'fa-circle-exclamation' : ($al['severity'] === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-info') ?>"></i>
                    </span>
                    <span class="a-body">
                        <span class="a-title"><?= e($al['title']) ?> <?= $al['status'] === 'open' ? '<span class="badge badge-gray">Terbuka</span>' : '<span class="badge badge-green">Selesai</span>' ?></span>
                        <span class="a-msg"><?= e($al['message']) ?></span>
                        <span class="a-meta"><span><i class="fa-solid fa-microchip"></i> <?= e($al['device_name'] ?? '—') ?></span><span><i class="fa-regular fa-clock"></i> <?= e(time_ago($al['occurred_at'])) ?></span></span>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card dashboard-glass-card dashboard-summary-card">
            <div class="card-header">
                <div>
                    <h3 class="card-title"><i class="fa-solid fa-layer-group"></i> Ringkasan Sistem</h3>
                    <p class="card-sub">Status singkat pemantauan BOLU</p>
                </div>
            </div>
            <div class="card-body dashboard-summary-body">
                <div class="dashboard-summary-row">
                    <span><i class="fa-solid fa-microchip"></i> Perangkat terhubung</span>
                    <strong><?= $devOnline ?>/<?= $devTotal ?></strong>
                </div>
                <div class="dashboard-summary-row">
                    <span><i class="fa-solid fa-water"></i> Status data kualitas air</span>
                    <strong><?= e(ucfirst($waterStatus)) ?></strong>
                </div>
                <div class="dashboard-summary-row">
                    <span><i class="fa-solid fa-temperature-half"></i> Suhu air</span>
                    <strong><?= $water ? e(fmt_num($water['temp_sensor_c'], 1)) . ' °C' : 'Menunggu data' ?></strong>
                </div>
                <div class="dashboard-summary-row">
                    <span><i class="fa-solid fa-vial"></i> Nilai pH</span>
                    <strong><?= $water ? e(fmt_num($water['ph'], 2)) : 'Menunggu data' ?></strong>
                </div>
                <a class="dashboard-summary-link" href="kualitas-air.php">Lihat detail kualitas air <i class="fa-solid fa-arrow-right"></i></a>
            </div>
        </div>
    </div>
</div>

<!-- Kompatibilitas dashboard.js lama: elemen tidak ditampilkan ke pengguna. -->
<div style="display:none" aria-hidden="true">
    <canvas id="chartWave"></canvas>
    <canvas id="chartPower"></canvas>
    <span id="stWave"></span><span id="stWavePower"></span>
    <span id="stPower"></span><span id="stBatt"></span>
    <span id="stFish"></span><span id="stWind"></span>
    <span id="waveStatusSlot"></span>
</div>

<script>window.SKJA_BOOT=<?= safe_json($bootPayload) ?>;</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
