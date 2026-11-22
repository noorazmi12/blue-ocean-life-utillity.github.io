<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/boot-data.php';
require_login();

$pdo = db();
if (($_GET['export'] ?? '') === 'csv') {
    $rows = $pdo->query('SELECT measured_at, wave_height_m, wave_period_s, wave_direction_deg, wind_speed_ms, wind_direction_deg FROM wave_measurements ORDER BY measured_at DESC LIMIT 2000')->fetchAll();
    $csvRows = array_map(static fn ($r) => [
        $r['measured_at'], $r['wave_height_m'], $r['wave_period_s'], $r['wave_direction_deg'], $r['wind_speed_ms'], $r['wind_direction_deg'],
    ], $rows);
    send_csv_download('gelombang_' . date('Ymd_His') . '.csv', ['Waktu', 'Tinggi (m)', 'Periode (s)', 'Arah Gelombang (deg)', 'Angin (m/s)', 'Arah Angin (deg)'], $csvRows);
}

$PAGE_ID = 'gelombang';
$PAGE_TITLE = 'Monitoring Gelombang';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Monitoring Gelombang', null]];
$PAGE_SCRIPTS = ['wave-chart.js'];

$latest = $pdo->query('SELECT * FROM wave_measurements ORDER BY measured_at DESC, id DESC LIMIT 1')->fetch();
$history = $pdo->query('SELECT * FROM wave_measurements ORDER BY measured_at DESC, id DESC LIMIT 40')->fetchAll();
$bootPayload = ['points' => skja_boot_wave(1440)];

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-water"></i> Monitoring Gelombang Laut</h1>
        <p class="page-desc">Tinggi gelombang, periode, arah, dan potensi daya gelombang real-time.</p>
    </div>
    <div class="page-actions">
        <?= status_pill($latest ? data_status($latest['measured_at']) : 'offline') ?>
        <a class="btn btn-outline btn-sm" href="?export=csv"><i class="fa-solid fa-file-csv"></i> Ekspor CSV</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card stat-blue"><span class="stat-icon"><i class="fa-solid fa-water"></i></span><span class="stat-info"><span class="stat-label">Tinggi Gelombang</span><span class="stat-value" id="wvHeight"><?= $latest ? fmt_num($latest['wave_height_m'], 2) : '—' ?><span class="stat-unit">m</span></span></span></div>
    <div class="stat-card stat-navy"><span class="stat-icon"><i class="fa-solid fa-stopwatch"></i></span><span class="stat-info"><span class="stat-label">Periode Gelombang</span><span class="stat-value" id="wvPeriod"><?= $latest ? fmt_num($latest['wave_period_s'], 2) : '—' ?><span class="stat-unit">detik</span></span></span></div>
    <div class="stat-card stat-green"><span class="stat-icon"><i class="fa-solid fa-bolt"></i></span><span class="stat-info"><span class="stat-label">Estimasi Wave Power</span><span class="stat-value"><?= $latest ? fmt_num(wave_power_kw((float)$latest['wave_height_m'], (float)$latest['wave_period_s']), 2) : '—' ?><span class="stat-unit">kW</span></span></span></div>
</div>

<div class="card">
    <div class="card-header"><div><h3 class="card-title"><i class="fa-solid fa-chart-line"></i> Grafik Gelombang</h3></div></div>
    <div class="card-body"><div class="chart-box lg"><canvas id="waveChart"></canvas></div></div>
</div>

<div class="card">
    <div class="card-header"><div><h3 class="card-title">Riwayat Pengukuran Gelombang</h3></div></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>Waktu</th><th>Tinggi (m)</th><th>Periode (s)</th><th>Angin (m/s)</th></tr></thead><tbody>
    <?php foreach ($history as $r): ?>
        <tr><td><?= e(fmt_dt($r['measured_at'])) ?></td><td class="num"><?= fmt_num($r['wave_height_m'], 3) ?></td><td class="num"><?= fmt_num($r['wave_period_s'], 2) ?></td><td class="num"><?= fmt_num($r['wind_speed_ms'], 2) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<script>window.SKJA_BOOT=<?= safe_json($bootPayload) ?>;</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
