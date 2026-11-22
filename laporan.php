<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
if (($_GET['export'] ?? '') === 'csv') {
    $rows = $pdo->query('SELECT measured_at, temp_sensor_c, ph, salinity_psu, turbidity_ntu FROM water_measurements ORDER BY measured_at DESC LIMIT 2000')->fetchAll();
    send_csv_download('laporan_bolu_' . date('Ymd_His') . '.csv', ['Waktu', 'Suhu (C)', 'pH', 'Salinitas (PSU)', 'Kekeruhan (NTU)'], array_map('array_values', $rows));
}

$PAGE_ID = 'laporan';
$PAGE_TITLE = 'Histori & Laporan';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Histori & Laporan', null]];
$PAGE_SCRIPTS = ['laporan.js'];

$daily = $pdo->query('SELECT DATE(measured_at) AS d, COUNT(*) AS cnt, AVG(temp_sensor_c) AS avg_temp, AVG(ph) AS avg_ph FROM water_measurements GROUP BY DATE(measured_at) ORDER BY d DESC LIMIT 14')->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-clipboard-list"></i> Histori &amp; Laporan Harian</h1>
        <p class="page-desc">Rekapitulasi data pengukuran kualitas air dan ekspor CSV siap cetak.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline btn-sm" href="?export=csv"><i class="fa-solid fa-file-csv"></i> Ekspor CSV</a>
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Cetak</button>
    </div>
</div>

<div class="card">
    <div class="card-header"><div><h3 class="card-title">Ringkasan Harian Kualitas Air</h3></div></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>Tanggal</th><th>Jumlah Sampel</th><th>Rata-rata Suhu (°C)</th><th>Rata-rata pH</th></tr></thead><tbody>
    <?php foreach ($daily as $r): ?>
        <tr><td><?= e($r['d']) ?></td><td class="num"><?= (int)$r['cnt'] ?></td><td class="num"><?= fmt_num($r['avg_temp'], 2) ?></td><td class="num"><?= fmt_num($r['avg_ph'], 2) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
