<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/boot-data.php';
require_login();

$pdo = db();
if (($_GET['export'] ?? '') === 'csv') {
    $rows = $pdo->query('SELECT measured_at, power_w, voltage_v, current_a, energy_wh, battery_pct FROM owc_measurements ORDER BY measured_at DESC LIMIT 2000')->fetchAll();
    $csvRows = array_map(static fn ($r) => [
        $r['measured_at'], $r['power_w'], $r['voltage_v'], $r['current_a'], $r['energy_wh'], $r['battery_pct'],
    ], $rows);
    send_csv_download('energi_owc_' . date('Ymd_His') . '.csv', ['Waktu', 'Daya (W)', 'Tegangan (V)', 'Arus (A)', 'Energi (Wh)', 'Baterai (%)'], $csvRows);
}

$PAGE_ID = 'energi-owc';
$PAGE_TITLE = 'Energi OWC';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Energi OWC', null]];
$PAGE_SCRIPTS = ['energy-chart.js'];

$latest = $pdo->query('SELECT * FROM owc_measurements ORDER BY measured_at DESC, id DESC LIMIT 1')->fetch();
$dailyRows = $pdo->query('SELECT DATE(measured_at) AS d, SUM(energy_wh) AS energy_wh, MAX(power_w) AS peak_w, AVG(power_w) AS avg_w FROM owc_measurements WHERE measured_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(measured_at) ORDER BY d ASC')->fetchAll();
$history = $pdo->query('SELECT * FROM owc_measurements ORDER BY measured_at DESC, id DESC LIMIT 40')->fetchAll();
$bootPayload = ['points' => skja_boot_energy(1440), 'daily' => ['rows' => $dailyRows]];

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-bolt"></i> Monitoring Energi OWC</h1>
        <p class="page-desc">Daya listrik, tegangan, arus, dan akumulasi energi pembangkit Oscillating Water Column.</p>
    </div>
    <div class="page-actions">
        <?= status_pill($latest ? data_status($latest['measured_at']) : 'offline') ?>
        <a class="btn btn-outline btn-sm" href="?export=csv"><i class="fa-solid fa-file-csv"></i> Ekspor CSV</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card stat-yellow"><span class="stat-icon"><i class="fa-solid fa-bolt"></i></span><span class="stat-info"><span class="stat-label">Daya OWC</span><span class="stat-value"><?= $latest ? fmt_num($latest['power_w'], 1) : '—' ?><span class="stat-unit">W</span></span></span></div>
    <div class="stat-card stat-blue"><span class="stat-icon"><i class="fa-solid fa-plug"></i></span><span class="stat-info"><span class="stat-label">Tegangan</span><span class="stat-value"><?= $latest ? fmt_num($latest['voltage_v'], 2) : '—' ?><span class="stat-unit">V</span></span></span></div>
    <div class="stat-card stat-navy"><span class="stat-icon"><i class="fa-solid fa-gauge"></i></span><span class="stat-info"><span class="stat-label">Arus</span><span class="stat-value"><?= $latest ? fmt_num($latest['current_a'], 2) : '—' ?><span class="stat-unit">A</span></span></span></div>
    <div class="stat-card stat-green"><span class="stat-icon"><i class="fa-solid fa-battery-three-quarters"></i></span><span class="stat-info"><span class="stat-label">Baterai</span><span class="stat-value"><?= $latest ? fmt_num($latest['battery_pct'], 1) : '—' ?><span class="stat-unit">%</span></span></span></div>
</div>

<div class="card">
    <div class="card-header"><div><h3 class="card-title"><i class="fa-solid fa-chart-line"></i> Grafik Daya &amp; Energi OWC</h3></div></div>
    <div class="card-body"><div class="chart-box lg"><canvas id="energyChart"></canvas></div></div>
</div>

<div class="card">
    <div class="card-header"><div><h3 class="card-title">Riwayat Energi OWC</h3></div></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>Waktu</th><th>Daya (W)</th><th>Tegangan (V)</th><th>Arus (A)</th><th>Energi (Wh)</th></tr></thead><tbody>
    <?php foreach ($history as $r): ?>
        <tr><td><?= e(fmt_dt($r['measured_at'])) ?></td><td class="num"><?= fmt_num($r['power_w'], 2) ?></td><td class="num"><?= fmt_num($r['voltage_v'], 2) ?></td><td class="num"><?= fmt_num($r['current_a'], 3) ?></td><td class="num"><?= fmt_num($r['energy_wh'], 2) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<script>window.SKJA_BOOT=<?= safe_json($bootPayload) ?>;</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
