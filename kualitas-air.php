<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/boot-data.php';
require_login();

$pdo = db();
if (($_GET['export'] ?? '') === 'csv') {
    $rows = $pdo->query('SELECT measured_at, temp_sensor_c, sst_c, salinity_psu, ph, turbidity_ntu, depth_m FROM water_measurements ORDER BY measured_at DESC LIMIT 2000')->fetchAll();
    $csvRows = array_map(static fn ($r) => [
        $r['measured_at'], $r['temp_sensor_c'], $r['sst_c'], $r['salinity_psu'], $r['ph'], $r['turbidity_ntu'], $r['depth_m'],
    ], $rows);
    send_csv_download('kualitas_air_' . date('Ymd_His') . '.csv', ['Waktu', 'Suhu Sensor (C)', 'SST (C)', 'Salinitas (PSU)', 'pH', 'Kekeruhan (NTU)', 'Kedalaman (m)'], $csvRows);
}

$PAGE_ID = 'kualitas-air';
$PAGE_TITLE = 'Kualitas Air';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Kualitas Air', null]];
$PAGE_SCRIPTS = ['water-chart.js'];

$latest = $pdo->query('SELECT * FROM water_measurements ORDER BY measured_at DESC, id DESC LIMIT 1')->fetch();
$history = $pdo->query('SELECT * FROM water_measurements ORDER BY measured_at DESC, id DESC LIMIT 40')->fetchAll();
$bootPayload = ['points' => skja_boot_water(1440)];

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-droplet"></i> Monitoring Kualitas Air</h1>
        <p class="page-desc">Pemantauan suhu air dan pH dari sensor IoT di perairan KJA.</p>
    </div>
    <div class="page-actions">
        <?= status_pill($latest ? data_status($latest['measured_at']) : 'offline') ?>
        <a class="btn btn-outline btn-sm" href="?export=csv"><i class="fa-solid fa-file-csv"></i> Ekspor CSV</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card stat-navy"><span class="stat-icon"><i class="fa-solid fa-temperature-half"></i></span><span class="stat-info"><span class="stat-label">Suhu Air Sensor</span><span class="stat-value"><span id="wTemp"><?= $latest ? fmt_num($latest['temp_sensor_c'], 2) : '—' ?></span><span class="stat-unit">°C</span></span></span></div>
    <div class="stat-card stat-green"><span class="stat-icon"><i class="fa-solid fa-vial"></i></span><span class="stat-info"><span class="stat-label">pH Air</span><span class="stat-value" id="wPh"><?= $latest ? fmt_num($latest['ph'], 2) : '—' ?></span></span></div>
    <div class="stat-card stat-blue"><span class="stat-icon"><i class="fa-solid fa-water"></i></span><span class="stat-info"><span class="stat-label">Salinitas</span><span class="stat-value"><?= $latest ? fmt_num($latest['salinity_psu'], 2) : '—' ?><span class="stat-unit">PSU</span></span></span></div>
    <div class="stat-card stat-yellow"><span class="stat-icon"><i class="fa-solid fa-eye"></i></span><span class="stat-info"><span class="stat-label">Kekeruhan</span><span class="stat-value"><?= $latest ? fmt_num($latest['turbidity_ntu'], 2) : '—' ?><span class="stat-unit">NTU</span></span></span></div>
</div>

<div class="card">
    <div class="card-header"><div><h3 class="card-title"><i class="fa-solid fa-chart-area"></i> Grafik Kualitas Air</h3></div></div>
    <div class="card-body"><div class="chart-box lg"><canvas id="waterChart"></canvas></div></div>
</div>

<div class="card">
    <div class="card-header"><div><h3 class="card-title">Riwayat Pengukuran Terbaru</h3></div></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>Waktu</th><th>Suhu (°C)</th><th>pH</th><th>Salinitas (PSU)</th><th>Kekeruhan (NTU)</th></tr></thead><tbody>
    <?php foreach ($history as $r): ?>
        <tr><td><?= e(fmt_dt($r['measured_at'])) ?></td><td class="num"><?= fmt_num($r['temp_sensor_c'], 2) ?></td><td class="num"><?= fmt_num($r['ph'], 2) ?></td><td class="num"><?= fmt_num($r['salinity_psu'], 2) ?></td><td class="num"><?= fmt_num($r['turbidity_ntu'], 2) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<script>window.SKJA_BOOT=<?= safe_json($bootPayload) ?>;</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
