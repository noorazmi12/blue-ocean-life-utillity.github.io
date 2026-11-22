<?php
/**
 * BOLU — Histori & Laporan
 * Histori gelombang / energi OWC / kualitas air / FishCount dengan filter
 * periode, ringkasan harian-mingguan-bulanan, grafik, ekspor CSV, dan cetak.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$PAGE_ID    = 'laporan';
$PAGE_TITLE = 'Histori & Laporan';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Histori & Laporan', null]];
$PAGE_SCRIPTS = ['laporan.js'];

$types = ['wave' => 'Gelombang', 'energy' => 'Energi OWC', 'water' => 'Kualitas Air', 'fish' => 'FishCount'];
$type  = isset($_GET['type']) && isset($types[$_GET['type']]) ? $_GET['type'] : 'wave';

$period = $_GET['period'] ?? '7d';
$validPeriods = ['today' => 'Hari Ini', '7d' => '7 Hari', '30d' => '30 Hari', 'custom' => 'Khusus'];
if (!isset($validPeriods[$period])) $period = '7d';

[$from, $to] = date_range_from_get(date('Y-m-d', strtotime('-7 days')), date('Y-m-d'));
if ($period === 'today') { $from = $to = date('Y-m-d'); }

/* ---------- Ekspor CSV per jenis ---------- */
if (($_GET['export'] ?? '') === 'csv') {
    [$cFrom, $cTo] = date_range_from_get($from, $to);
    if ($type === 'wave') {
        $q = db()->prepare("SELECT m.*, d.device_code FROM wave_measurements m JOIN devices d ON d.id=m.device_id WHERE m.measured_at BETWEEN ? AND ? ORDER BY m.measured_at DESC");
        $q->execute([$cFrom . ' 00:00:00', $cTo . ' 23:59:59']);
        $out = [];
        foreach ($q->fetchAll() as $r) $out[] = [$r['device_code'], $r['measured_at'], $r['wave_height_m'], $r['wave_period_s'], $r['wind_speed_ms'], $r['is_simulated'] ? 'DEMO' : 'RIIL'];
        stream_csv('laporan-gelombang-' . $cFrom . '_' . $cTo . '.csv',
            ['Perangkat', 'Waktu', 'Tinggi (m)', 'Periode (s)', 'Angin (m/s)', 'Sumber'], $out);
    } elseif ($type === 'energy') {
        $q = db()->prepare("SELECT m.*, d.device_code FROM owc_measurements m JOIN devices d ON d.id=m.device_id WHERE m.measured_at BETWEEN ? AND ? ORDER BY m.measured_at DESC");
        $q->execute([$cFrom . ' 00:00:00', $cTo . ' 23:59:59']);
        $out = [];
        foreach ($q->fetchAll() as $r) $out[] = [$r['device_code'], $r['measured_at'], $r['power_w'], $r['voltage_v'], $r['current_a'], $r['energy_wh'], $r['is_simulated'] ? 'DEMO' : 'RIIL'];
        stream_csv('laporan-energi-owc-' . $cFrom . '_' . $cTo . '.csv',
            ['Perangkat', 'Waktu', 'Daya (W)', 'Tegangan (V)', 'Arus (A)', 'Energi Kumulatif (Wh)', 'Sumber'], $out);
    } elseif ($type === 'water') {
        $q = db()->prepare("SELECT m.*, d.device_code FROM water_measurements m JOIN devices d ON d.id=m.device_id WHERE m.measured_at BETWEEN ? AND ? ORDER BY m.measured_at DESC");
        $q->execute([$cFrom . ' 00:00:00', $cTo . ' 23:59:59']);
        $out = [];
        foreach ($q->fetchAll() as $r) $out[] = [$r['device_code'], $r['measured_at'], $r['temp_sensor_c'], $r['ph'], $r['is_simulated'] ? 'DEMO' : 'RIIL'];
        stream_csv('laporan-kualitas-air-' . $cFrom . '_' . $cTo . '.csv',
            ['Perangkat', 'Waktu', 'Suhu (°C)', 'pH', 'Sumber'], $out);
    } else {
        $q = db()->prepare("SELECT fr.*, d.device_code FROM fishcount_results fr LEFT JOIN devices d ON d.id=fr.device_id WHERE fr.created_at BETWEEN ? AND ? ORDER BY fr.created_at DESC");
        $q->execute([$cFrom . ' 00:00:00', $cTo . ' 23:59:59']);
        $out = [];
        foreach ($q->fetchAll() as $r) $out[] = [$r['device_code'], $r['created_at'], $r['detected_count'], $r['manual_count'], $r['avg_length_cm'], $r['biomass_kg'], $r['confidence_pct'], $r['processing_status'], $r['is_simulated'] ? 'DEMO' : 'RIIL'];
        stream_csv('laporan-fishcount-' . $cFrom . '_' . $cTo . '.csv',
            ['Perangkat', 'Waktu', 'Deteksi AI', 'Hitung Manual', 'Panjang Rata² (cm)', 'Biomassa (kg)', 'Keyakinan (%)', 'Status', 'Sumber'], $out);
    }
}

/* ---------- Ringkasan harian/mingguan/bulanan ---------- */
function summary_rows(string $type, string $from, string $to): array
{
    switch ($type) {
        case 'wave':
            $sql = "SELECT DATE(measured_at) d, COUNT(*) n, MIN(wave_height_m) v1, MAX(wave_height_m) v2, AVG(wave_height_m) v3
                    FROM wave_measurements WHERE measured_at BETWEEN ? AND ? GROUP BY DATE(measured_at) ORDER BY d DESC";
            $cols = ['n', 'v1', 'v2', 'v3'];
            break;
        case 'energy':
            $sql = "SELECT DATE(measured_at) d, COUNT(*) n, ROUND(MAX(energy_wh)-MIN(energy_wh),1) v1, MAX(power_w) v2, AVG(power_w) v3
                    FROM owc_measurements WHERE measured_at BETWEEN ? AND ? GROUP BY DATE(measured_at) ORDER BY d DESC";
            $cols = ['n', 'v1', 'v2', 'v3'];
            break;
        case 'water':
            $sql = "SELECT DATE(measured_at) d, COUNT(*) n, MIN(ph) v1, MAX(ph) v2, AVG(temp_sensor_c) v3
                    FROM water_measurements WHERE measured_at BETWEEN ? AND ? GROUP BY DATE(measured_at) ORDER BY d DESC";
            $cols = ['n', 'v1', 'v2', 'v3'];
            break;
        default:
            $sql = "SELECT DATE(created_at) d, COUNT(*) n, MAX(detected_count) v1, AVG(avg_length_cm) v2, SUM(biomass_kg) v3
                    FROM fishcount_results WHERE created_at BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY d DESC";
            $cols = ['n', 'v1', 'v2', 'v3'];
    }
    $st = db()->prepare($sql);
    $st->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    return $st->fetchAll();
}

$summary = summary_rows($type, $from, $to);

/* Ringkasan bulan berjalan & minggu berjalan (angka agregat) */
$monthAgg = summary_rows($type, date('Y-m-01'), date('Y-m-d'));
$monthN = array_sum(array_map(fn($r) => (int)$r['n'], $monthAgg));
$weekN = 0;
foreach ($summary as $r) {
    if (strtotime($r['d']) >= strtotime('-6 days')) $weekN += (int)$r['n'];
}

/* ---------- Tabel pengukuran terakhir (ringkas) ---------- */
$limit = 20;
$lastRows = [];
switch ($type) {
    case 'wave':
        $lastRows = db()->query("SELECT m.*, d.device_code FROM wave_measurements m JOIN devices d ON d.id=m.device_id ORDER BY m.measured_at DESC LIMIT {$limit}")->fetchAll();
        break;
    case 'energy':
        $lastRows = db()->query("SELECT m.*, d.device_code FROM owc_measurements m JOIN devices d ON d.id=m.device_id ORDER BY m.measured_at DESC LIMIT {$limit}")->fetchAll();
        break;
    case 'water':
        $lastRows = db()->query("SELECT m.*, d.device_code FROM water_measurements m JOIN devices d ON d.id=m.device_id ORDER BY m.measured_at DESC LIMIT {$limit}")->fetchAll();
        break;
    default:
        $lastRows = db()->query("SELECT fr.*, d.device_code FROM fishcount_results fr LEFT JOIN devices d ON d.id=fr.device_id ORDER BY fr.created_at DESC LIMIT {$limit}")->fetchAll();
}

/* Data grafik untuk JS (ringkas per jam) */
$chartData = [];
foreach (array_reverse($summary) as $r) {
    $chartData[] = ['t' => $r['d'], 'v' => match ($type) {
        'wave' => round((float)$r['v3'], 3),
        'energy' => round((float)$r['v1'] / 1000, 3),
        'water' => round((float)$r['v3'], 1),
        default => round((float)$r['v1'] ?? 0, 0),
    }];
}

include __DIR__ . '/includes/header.php';
?>

<div class="print-header">
    <h2 style="margin:0"><?= e(APP_NAME) ?> — Laporan <?= e($types[$type]) ?></h2>
    <p style="margin:4px 0">Periode: <?= e(fmt_dt($from . ' 00:00:00')) ?> s.d. <?= e(fmt_dt($to . ' 23:59:59')) ?> · Dicetak: <?= e(date('d/m/Y H:i')) ?></p>
</div>

<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-clipboard-list"></i> Histori &amp; Laporan</h1>
        <p class="page-desc">Riwayat pengukuran seluruh sistem dengan ringkasan harian, mingguan, dan bulanan.
            Ekspor CSV untuk analisis lanjutan atau gunakan tombol cetak untuk laporan kertas/PDF.</p>
    </div>
    <div class="page-actions no-print">
        <a class="btn btn-outline btn-sm" href="laporan.php?export=csv&type=<?= e($type) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>&period=<?= e($period) ?>">
            <i class="fa-solid fa-file-csv"></i> Ekspor CSV
        </a>
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
            <i class="fa-solid fa-print"></i> Cetak Laporan
        </button>
    </div>
</div>

<!-- Filter laporan -->
<div class="card no-print">
    <div class="card-body">
        <form method="get" action="laporan.php" class="input-group">
            <select class="form-select" name="type">
                <?php foreach ($types as $k => $lbl): ?>
                    <option value="<?= e($k) ?>" <?= $type === $k ? 'selected' : '' ?>>Histori <?= e($lbl) ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-select" name="period" id="periodSel">
                <?php foreach ($validPeriods as $k => $lbl): ?>
                    <option value="<?= e($k) ?>" <?= $period === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
            </select>
            <span id="customRange" <?= $period === 'custom' ? '' : 'hidden' ?>>
                <input type="date" class="form-control" name="from" value="<?= e($from) ?>"> —
                <input type="date" class="form-control" name="to" value="<?= e($to) ?>">
            </span>
            <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Tampilkan</button>
        </form>
    </div>
</div>

<!-- Ringkasan -->
<div class="stat-grid cols-5">
    <div class="stat-card stat-navy">
        <span class="stat-icon"><i class="fa-solid fa-database"></i></span>
        <span class="stat-info">
            <span class="stat-label">Total Data Periode</span>
            <span class="stat-value"><?= number_format(array_sum(array_map(fn($r) => (int)$r['n'], $summary)), 0, ',', '.') ?></span>
            <span class="stat-foot"><?= e($validPeriods[$period]) ?></span>
        </span>
    </div>
    <div class="stat-card stat-blue">
        <span class="stat-icon"><i class="fa-solid fa-calendar-day"></i></span>
        <span class="stat-info">
            <span class="stat-label">Hari Ini</span>
            <span class="stat-value"><?= number_format((int)(array_values(array_filter($summary, fn($r) => $r['d'] === date('Y-m-d')))[0]['n'] ?? 0), 0, ',', '.') ?></span>
            <span class="stat-foot">pengukuran</span>
        </span>
    </div>
    <div class="stat-card stat-green">
        <span class="stat-icon"><i class="fa-solid fa-calendar-week"></i></span>
        <span class="stat-info">
            <span class="stat-label">Minggu Ini (7 hari)</span>
            <span class="stat-value"><?= number_format($weekN, 0, ',', '.') ?></span>
            <span class="stat-foot">pengukuran</span>
        </span>
    </div>
    <div class="stat-card stat-yellow">
        <span class="stat-icon"><i class="fa-solid fa-calendar"></i></span>
        <span class="stat-info">
            <span class="stat-label">Bulan Ini</span>
            <span class="stat-value"><?= number_format($monthN, 0, ',', '.') ?></span>
            <span class="stat-foot">pengukuran sejak tgl 1</span>
        </span>
    </div>
    <div class="stat-card stat-blue">
        <span class="stat-icon"><i class="fa-solid fa-hourglass-end"></i></span>
        <span class="stat-info">
            <span class="stat-label">Rentang Laporan</span>
            <span class="stat-value" style="font-size:14px"><?= e($from) ?><br>s.d. <?= e($to) ?></span>
        </span>
    </div>
</div>

<!-- Grafik & ringkasan harian -->
<div class="grid-2-1">
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title"><i class="fa-solid fa-chart-line"></i> Grafik Historis — <?= e($types[$type]) ?></h3>
                <p class="card-sub">
                    <?php if ($type === 'wave'): ?>Rata-rata tinggi gelombang harian (m)
                    <?php elseif ($type === 'energy'): ?>Produksi energi harian (kWh)
                    <?php elseif ($type === 'water'): ?>Rata-rata suhu air harian (°C)
                    <?php else: ?>Maksimum jumlah ikan terdeteksi per hari<?php endif; ?>
                </p>
            </div>
        </div>
        <div class="card-body">
            <div class="chart-box sm"><canvas id="chartReport"></canvas></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div><h3 class="card-title"><i class="fa-solid fa-list-ol"></i> Ringkasan Harian</h3></div>
        </div>
        <div class="table-wrap" style="max-height:320px;overflow-y:auto">
            <table class="table">
                <thead><tr><th>Tanggal</th><th>Data</th>
                    <?php if ($type === 'wave'): ?><th>Min (m)</th><th>Maks (m)</th><th>Rata² (m)</th>
                    <?php elseif ($type === 'energy'): ?><th>Energi (Wh)</th><th>Puncak (W)</th><th>Rata² (W)</th>
                    <?php elseif ($type === 'water'): ?><th>pH Min</th><th>pH Maks</th><th>Suhu Rata²</th>
                    <?php else: ?><th>Maks Deteksi</th><th>Panjang Rata²</th><th>Biomassa (kg)</th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php if (!$summary): ?>
                    <tr><td colspan="6"><div class="empty-state"><i class="fa-regular fa-folder-open"></i><p>Tidak ada data.</p></div></td></tr>
                <?php endif; ?>
                <?php foreach ($summary as $r): ?>
                    <tr>
                        <td class="table-strong"><?= e($r['d']) ?></td>
                        <td class="num"><?= number_format((int)$r['n'], 0, ',', '.') ?></td>
                        <?php if ($type === 'wave'): ?>
                            <td class="num"><?= fmt_num($r['v1'], 2) ?></td><td class="num"><?= fmt_num($r['v2'], 2) ?></td><td class="num"><?= fmt_num($r['v3'], 2) ?></td>
                        <?php elseif ($type === 'energy'): ?>
                            <td class="num"><?= fmt_num($r['v1'], 1) ?></td><td class="num"><?= fmt_num($r['v2'], 1) ?></td><td class="num"><?= fmt_num($r['v3'], 1) ?></td>
                        <?php elseif ($type === 'water'): ?>
                            <td class="num"><?= fmt_num($r['v1'], 2) ?></td><td class="num"><?= fmt_num($r['v2'], 2) ?></td>
                            <td class="num"><?= fmt_num($r['v3'], 1) ?></td>
                        <?php else: ?>
                            <td class="num"><?= $r['v1'] !== null ? fmt_num($r['v1'], 0) : '—' ?></td>
                            <td class="num"><?= $r['v2'] !== null ? fmt_num($r['v2'], 1) : '—' ?></td>
                            <td class="num"><?= $r['v3'] !== null ? fmt_num($r['v3'], 2) : '—' ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Tabel pengukuran terakhir -->
<div class="card">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-table-list"></i> <?= count($lastRows) ?> Pengukuran Terakhir — <?= e($types[$type]) ?></h3>
            <p class="card-sub">Tabel lengkap tersedia melalui ekspor CSV</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
            <?php if ($type === 'wave'): ?>
                <tr><th>Waktu</th><th>Perangkat</th><th>Tinggi (m)</th><th>Periode (s)</th><th>Angin (m/s)</th><th>Sumber</th></tr>
            <?php elseif ($type === 'energy'): ?>
                <tr><th>Waktu</th><th>Perangkat</th><th>Daya (W)</th><th>Tegangan (V)</th><th>Arus (A)</th><th>Energi (Wh)</th><th>Sumber</th></tr>
            <?php elseif ($type === 'water'): ?>
                <tr><th>Waktu</th><th>Perangkat</th><th>Suhu (°C)</th><th>pH</th><th>Sumber</th></tr>
            <?php else: ?>
                <tr><th>Waktu</th><th>Perangkat</th><th>Deteksi</th><th>Manual</th><th>Panjang</th><th>Biomassa</th><th>Status</th></tr>
            <?php endif; ?>
            </thead>
            <tbody>
            <?php if (!$lastRows): ?>
                <tr><td colspan="8"><div class="empty-state"><i class="fa-regular fa-folder-open"></i><p>Tidak ada data.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($lastRows as $r): ?>
                <?php if ($type === 'wave'): ?>
                    <tr><td class="table-strong"><?= e(fmt_dt($r['measured_at'])) ?></td><td><?= e($r['device_code']) ?></td>
                        <td class="num"><?= fmt_num($r['wave_height_m'], 3) ?></td><td class="num"><?= fmt_num($r['wave_period_s'], 2) ?></td>
                        <td class="num"><?= fmt_num($r['wind_speed_ms'], 2) ?></td><td><?= demo_badge((bool)$r['is_simulated']) ?: '<span class="badge badge-green">Sensor</span>' ?></td></tr>
                <?php elseif ($type === 'energy'): ?>
                    <tr><td class="table-strong"><?= e(fmt_dt($r['measured_at'])) ?></td><td><?= e($r['device_code']) ?></td>
                        <td class="num"><?= fmt_num($r['power_w'], 1) ?></td><td class="num"><?= fmt_num($r['voltage_v'], 1) ?></td>
                        <td class="num"><?= fmt_num($r['current_a'], 2) ?></td><td class="num"><?= fmt_num($r['energy_wh'], 1) ?></td>
                        <td><?= demo_badge((bool)$r['is_simulated']) ?: '<span class="badge badge-green">Sensor</span>' ?></td></tr>
                <?php elseif ($type === 'water'): ?>
                    <tr><td class="table-strong"><?= e(fmt_dt($r['measured_at'])) ?></td><td><?= e($r['device_code']) ?></td>
                        <td class="num"><?= fmt_num($r['temp_sensor_c'], 1) ?></td>
                        <td class="num"><?= fmt_num($r['ph'], 2) ?></td>
                        <td><?= demo_badge((bool)$r['is_simulated']) ?: '<span class="badge badge-green">Sensor</span>' ?></td></tr>
                <?php else: ?>
                    <tr><td class="table-strong"><?= e(fmt_dt($r['created_at'])) ?></td><td><?= e($r['device_code'] ?? '—') ?></td>
                        <td class="num"><?= $r['detected_count'] !== null ? fmt_num($r['detected_count'], 0) : '—' ?></td>
                        <td class="num"><?= $r['manual_count'] !== null ? fmt_num($r['manual_count'], 0) : '—' ?></td>
                        <td class="num"><?= $r['avg_length_cm'] !== null ? fmt_num($r['avg_length_cm'], 1) . ' cm' : '—' ?></td>
                        <td class="num"><?= $r['biomass_kg'] !== null ? fmt_num($r['biomass_kg'], 2) . ' kg' : '—' ?></td>
                        <td><span class="badge <?= $r['processing_status'] === 'done' ? 'badge-green' : ($r['processing_status'] === 'no_ai' ? 'badge-yellow' : 'badge-gray') ?>"><?= e($r['processing_status']) ?></span>
                        <?= demo_badge((bool)$r['is_simulated']) ?></td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script id="reportData" type="application/json"><?= json_encode($chartData, JSON_UNESCAPED_UNICODE) ?></script>

<?php include __DIR__ . '/includes/footer.php'; ?>
