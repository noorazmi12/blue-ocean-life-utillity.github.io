<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

$types = [
    'wave' => 'Gelombang',
    'energy' => 'Energi OWC',
    'water' => 'Kualitas Air',
    'fish' => 'FishCount',
];
$rawType = (string)($_GET['type'] ?? 'wave');
$type = isset($types[$rawType]) ? $rawType : 'wave';

$validPeriods = [
    'today' => 'Hari Ini',
    '7d' => '7 Hari',
    '30d' => '30 Hari',
    'custom' => 'Khusus',
];
$rawPeriod = (string)($_GET['period'] ?? '7d');
$period = isset($validPeriods[$rawPeriod]) ? $rawPeriod : '7d';

$defFrom = date('Y-m-d', time() - 7 * 86400);
$defTo = date('Y-m-d');
[$from, $to] = date_range_from_query($_GET, $defFrom, $defTo);
if ($period === 'today') {
    $from = $to = date('Y-m-d');
}

if (($_GET['export'] ?? '') === 'csv') {
    $start = $from . ' 00:00:00';
    $end = $to . ' 23:59:59';
    if ($type === 'wave') {
        $st = db()->prepare(
            'SELECT d.device_code, w.measured_at, w.wave_height_m, w.wave_period_s, w.wind_speed_ms, w.is_simulated
             FROM wave_measurements w LEFT JOIN devices d ON d.id = w.device_id
             WHERE w.measured_at BETWEEN ? AND ? ORDER BY w.measured_at DESC'
        );
        $st->execute([$start, $end]);
        $out = array_map(static fn($r) => [
            $r['device_code'] ?? '', $r['measured_at'], $r['wave_height_m'], $r['wave_period_s'], $r['wind_speed_ms'],
            ((int)$r['is_simulated']) === 1 ? 'DEMO' : 'RIIL',
        ], $st->fetchAll());
        send_csv_download("laporan-gelombang-{$from}_{$to}.csv", ['Perangkat', 'Waktu', 'Tinggi (m)', 'Periode (s)', 'Angin (m/s)', 'Sumber'], $out);
    }
    if ($type === 'energy') {
        $st = db()->prepare(
            'SELECT d.device_code, o.measured_at, o.power_w, o.voltage_v, o.current_a, o.energy_wh, o.is_simulated
             FROM owc_measurements o LEFT JOIN devices d ON d.id = o.device_id
             WHERE o.measured_at BETWEEN ? AND ? ORDER BY o.measured_at DESC'
        );
        $st->execute([$start, $end]);
        $out = array_map(static fn($r) => [
            $r['device_code'] ?? '', $r['measured_at'], $r['power_w'], $r['voltage_v'], $r['current_a'], $r['energy_wh'],
            ((int)$r['is_simulated']) === 1 ? 'DEMO' : 'RIIL',
        ], $st->fetchAll());
        send_csv_download("laporan-energi-owc-{$from}_{$to}.csv", ['Perangkat', 'Waktu', 'Daya (W)', 'Tegangan (V)', 'Arus (A)', 'Energi Kumulatif (Wh)', 'Sumber'], $out);
    }
    if ($type === 'water') {
        $st = db()->prepare(
            'SELECT d.device_code, w.measured_at, w.temp_sensor_c, w.ph, w.is_simulated
             FROM water_measurements w LEFT JOIN devices d ON d.id = w.device_id
             WHERE w.measured_at BETWEEN ? AND ? ORDER BY w.measured_at DESC'
        );
        $st->execute([$start, $end]);
        $out = array_map(static fn($r) => [
            $r['device_code'] ?? '', $r['measured_at'], $r['temp_sensor_c'], $r['ph'],
            ((int)$r['is_simulated']) === 1 ? 'DEMO' : 'RIIL',
        ], $st->fetchAll());
        send_csv_download("laporan-kualitas-air-{$from}_{$to}.csv", ['Perangkat', 'Waktu', 'Suhu (°C)', 'pH', 'Sumber'], $out);
    }
    $st = db()->prepare(
        'SELECT d.device_code, f.created_at, f.detected_count, f.manual_count, f.avg_length_cm, f.biomass_kg, f.confidence_pct, f.processing_status, f.is_simulated
         FROM fishcount_results f LEFT JOIN devices d ON d.id = f.device_id
         WHERE f.created_at BETWEEN ? AND ? ORDER BY f.created_at DESC'
    );
    $st->execute([$start, $end]);
    $out = array_map(static fn($r) => [
        $r['device_code'] ?? '', $r['created_at'], $r['detected_count'], $r['manual_count'], $r['avg_length_cm'],
        $r['biomass_kg'], $r['confidence_pct'], $r['processing_status'], ((int)$r['is_simulated']) === 1 ? 'DEMO' : 'RIIL',
    ], $st->fetchAll());
    send_csv_download(
        "laporan-fishcount-{$from}_{$to}.csv",
        ['Perangkat', 'Waktu', 'Deteksi AI', 'Hitung Manual', 'Panjang Rata² (cm)', 'Biomassa (kg)', 'Keyakinan (%)', 'Status', 'Sumber'],
        $out
    );
}

$summaryRows = static function (string $t, string $fDate, string $tDate): array {
    $s = $fDate . ' 00:00:00';
    $e = $tDate . ' 23:59:59';
    $pdo = db();

    if ($t === 'wave') {
        $st = $pdo->prepare(
            'SELECT substr(measured_at, 1, 10) AS d, COUNT(*) AS n,
                    MIN(wave_height_m) AS v1, MAX(wave_height_m) AS v2, AVG(wave_height_m) AS v3
             FROM wave_measurements WHERE measured_at BETWEEN ? AND ?
             GROUP BY substr(measured_at, 1, 10) ORDER BY d DESC'
        );
        $st->execute([$s, $e]);
        return array_map(static fn($r) => [
            'd' => $r['d'],
            'n' => (int)$r['n'],
            'v1' => $r['v1'] !== null ? (float)$r['v1'] : null,
            'v2' => $r['v2'] !== null ? (float)$r['v2'] : null,
            'v3' => $r['v3'] !== null ? (float)$r['v3'] : null,
        ], $st->fetchAll());
    }
    if ($t === 'energy') {
        $st = $pdo->prepare(
            'SELECT substr(measured_at, 1, 10) AS d, COUNT(*) AS n,
                    ROUND(MAX(energy_wh) - MIN(energy_wh), 1) AS v1, MAX(power_w) AS v2, AVG(power_w) AS v3
             FROM owc_measurements WHERE measured_at BETWEEN ? AND ?
             GROUP BY substr(measured_at, 1, 10) ORDER BY d DESC'
        );
        $st->execute([$s, $e]);
        return array_map(static fn($r) => [
            'd' => $r['d'],
            'n' => (int)$r['n'],
            'v1' => $r['v1'] !== null ? (float)$r['v1'] : null,
            'v2' => $r['v2'] !== null ? (float)$r['v2'] : null,
            'v3' => $r['v3'] !== null ? (float)$r['v3'] : null,
        ], $st->fetchAll());
    }
    if ($t === 'water') {
        $st = $pdo->prepare(
            'SELECT substr(measured_at, 1, 10) AS d, COUNT(*) AS n,
                    MIN(ph) AS v1, MAX(ph) AS v2, AVG(temp_sensor_c) AS v3
             FROM water_measurements WHERE measured_at BETWEEN ? AND ?
             GROUP BY substr(measured_at, 1, 10) ORDER BY d DESC'
        );
        $st->execute([$s, $e]);
        return array_map(static fn($r) => [
            'd' => $r['d'],
            'n' => (int)$r['n'],
            'v1' => $r['v1'] !== null ? (float)$r['v1'] : null,
            'v2' => $r['v2'] !== null ? (float)$r['v2'] : null,
            'v3' => $r['v3'] !== null ? (float)$r['v3'] : null,
        ], $st->fetchAll());
    }
    $st = $pdo->prepare(
        'SELECT substr(created_at, 1, 10) AS d, COUNT(*) AS n,
                MAX(detected_count) AS v1, AVG(avg_length_cm) AS v2, SUM(biomass_kg) AS v3
         FROM fishcount_results WHERE created_at BETWEEN ? AND ?
         GROUP BY substr(created_at, 1, 10) ORDER BY d DESC'
    );
    $st->execute([$s, $e]);
    return array_map(static fn($r) => [
        'd' => $r['d'],
        'n' => (int)$r['n'],
        'v1' => $r['v1'] !== null ? (float)$r['v1'] : null,
        'v2' => $r['v2'] !== null ? (float)$r['v2'] : null,
        'v3' => $r['v3'] !== null ? (float)$r['v3'] : null,
    ], $st->fetchAll());
};

$summary = $summaryRows($type, $from, $to);
$todayYmd = date('Y-m-d');
$monthStartYmd = substr($todayYmd, 0, 7) . '-01';
$monthAgg = $summaryRows($type, $monthStartYmd, $todayYmd);
$monthN = array_sum(array_column($monthAgg, 'n'));
$cutoff6d = date('Y-m-d', time() - 6 * 86400);
$weekN = 0;
$todayCount = 0;
$totalPeriodCount = 0;
foreach ($summary as $sr) {
    $totalPeriodCount += $sr['n'];
    if ($sr['d'] >= $cutoff6d) {
        $weekN += $sr['n'];
    }
    if ($sr['d'] === $todayYmd) {
        $todayCount = $sr['n'];
    }
}

$lastRows = [];
if ($type === 'wave') {
    $lastRows = db()->query(
        'SELECT w.*, COALESCE(d.device_code, \'—\') AS device_code FROM wave_measurements w LEFT JOIN devices d ON d.id = w.device_id ORDER BY w.measured_at DESC, w.id DESC LIMIT 20'
    )->fetchAll();
} elseif ($type === 'energy') {
    $lastRows = db()->query(
        'SELECT o.*, COALESCE(d.device_code, \'—\') AS device_code FROM owc_measurements o LEFT JOIN devices d ON d.id = o.device_id ORDER BY o.measured_at DESC, o.id DESC LIMIT 20'
    )->fetchAll();
} elseif ($type === 'water') {
    $lastRows = db()->query(
        'SELECT w.*, COALESCE(d.device_code, \'—\') AS device_code FROM water_measurements w LEFT JOIN devices d ON d.id = w.device_id ORDER BY w.measured_at DESC, w.id DESC LIMIT 20'
    )->fetchAll();
} else {
    $lastRows = db()->query(
        'SELECT f.*, COALESCE(d.device_code, \'—\') AS device_code FROM fishcount_results f LEFT JOIN devices d ON d.id = f.device_id ORDER BY f.created_at DESC, f.id DESC LIMIT 20'
    )->fetchAll();
}

$chartData = [];
foreach (array_reverse($summary) as $r) {
    $val = 0;
    if ($type === 'wave') {
        $val = round((float)($r['v3'] ?? 0), 3);
    } elseif ($type === 'energy') {
        $val = round(((float)($r['v1'] ?? 0)) / 1000, 3);
    } elseif ($type === 'water') {
        $val = round((float)($r['v3'] ?? 0), 1);
    } else {
        $val = (int)round((float)($r['v1'] ?? 0));
    }
    $chartData[] = ['t' => $r['d'], 'v' => $val];
}
$printedAt = date('d/m/Y H:i');

$PAGE_ID = 'laporan';
$PAGE_TITLE = 'Histori & Laporan';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Histori & Laporan', null]];
$PAGE_SCRIPTS = ['laporan.js'];

require __DIR__ . '/includes/header.php';
?>

<div class="print-header">
    <h2 style="margin:0"><?= e(APP_NAME) ?> — Laporan <?= e($types[$type]) ?></h2>
    <p style="margin:4px 0">Periode: <?= e(fmt_dt($from . ' 00:00:00')) ?> s.d. <?= e(fmt_dt($to . ' 23:59:59')) ?> · Dicetak: <?= e($printedAt) ?></p>
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
            <span class="stat-value"><?= fmt_num($totalPeriodCount, 0) ?></span>
            <span class="stat-foot"><?= e($validPeriods[$period]) ?></span>
        </span>
    </div>
    <div class="stat-card stat-blue">
        <span class="stat-icon"><i class="fa-solid fa-calendar-day"></i></span>
        <span class="stat-info">
            <span class="stat-label">Hari Ini</span>
            <span class="stat-value"><?= fmt_num($todayCount, 0) ?></span>
            <span class="stat-foot">pengukuran</span>
        </span>
    </div>
    <div class="stat-card stat-green">
        <span class="stat-icon"><i class="fa-solid fa-calendar-week"></i></span>
        <span class="stat-info">
            <span class="stat-label">Minggu Ini (7 hari)</span>
            <span class="stat-value"><?= fmt_num($weekN, 0) ?></span>
            <span class="stat-foot">pengukuran</span>
        </span>
    </div>
    <div class="stat-card stat-yellow">
        <span class="stat-icon"><i class="fa-solid fa-calendar"></i></span>
        <span class="stat-info">
            <span class="stat-label">Bulan Ini</span>
            <span class="stat-value"><?= fmt_num($monthN, 0) ?></span>
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
                        <td class="num"><?= fmt_num($r['n'], 0) ?></td>
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
                        <td class="num"><?= fmt_num($r['wind_speed_ms'], 2) ?></td><td><?= demo_badge($r['is_simulated']) ?: '<span class="badge badge-green">Sensor</span>' ?></td></tr>
                <?php elseif ($type === 'energy'): ?>
                    <tr><td class="table-strong"><?= e(fmt_dt($r['measured_at'])) ?></td><td><?= e($r['device_code']) ?></td>
                        <td class="num"><?= fmt_num($r['power_w'], 1) ?></td><td class="num"><?= fmt_num($r['voltage_v'], 1) ?></td>
                        <td class="num"><?= fmt_num($r['current_a'], 2) ?></td><td class="num"><?= fmt_num($r['energy_wh'], 1) ?></td>
                        <td><?= demo_badge($r['is_simulated']) ?: '<span class="badge badge-green">Sensor</span>' ?></td></tr>
                <?php elseif ($type === 'water'): ?>
                    <tr><td class="table-strong"><?= e(fmt_dt($r['measured_at'])) ?></td><td><?= e($r['device_code']) ?></td>
                        <td class="num"><?= fmt_num($r['temp_sensor_c'], 1) ?></td>
                        <td class="num"><?= fmt_num($r['ph'], 2) ?></td>
                        <td><?= demo_badge($r['is_simulated']) ?: '<span class="badge badge-green">Sensor</span>' ?></td></tr>
                <?php else: ?>
                    <tr><td class="table-strong"><?= e(fmt_dt($r['created_at'])) ?></td><td><?= e($r['device_code'] ?: '—') ?></td>
                        <td class="num"><?= $r['detected_count'] !== null ? fmt_num($r['detected_count'], 0) : '—' ?></td>
                        <td class="num"><?= $r['manual_count'] !== null ? fmt_num($r['manual_count'], 0) : '—' ?></td>
                        <td class="num"><?= $r['avg_length_cm'] !== null ? fmt_num($r['avg_length_cm'], 1) . ' cm' : '—' ?></td>
                        <td class="num"><?= $r['biomass_kg'] !== null ? fmt_num($r['biomass_kg'], 2) . ' kg' : '—' ?></td>
                        <td><span class="badge <?= $r['processing_status'] === 'done' ? 'badge-green' : ($r['processing_status'] === 'no_ai' ? 'badge-yellow' : 'badge-gray') ?>"><?= e($r['processing_status']) ?></span>
                        <?= demo_badge($r['is_simulated']) ?></td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script id="reportData" type="application/json"><?= safe_json($chartData) ?></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
