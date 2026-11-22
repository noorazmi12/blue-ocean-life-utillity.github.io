<?php
/**
 * BOLU — Monitoring Gelombang
 * Grafik real-time (Chart.js), rentang waktu, statistik, histori, ekspor CSV.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$PAGE_ID    = 'gelombang';
$PAGE_TITLE = 'Monitoring Gelombang';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Monitoring Gelombang', null]];
$PAGE_SCRIPTS = ['wave-chart.js'];

$validMinutes = ['15' => 15, '60' => 60, '360' => 360, '1440' => 1440];
$range = isset($_GET['range']) && isset($validMinutes[$_GET['range']]) ? (int)$_GET['range'] : 60;
$customFrom = $_GET['from'] ?? '';
$customTo   = $_GET['to'] ?? '';
$isCustom   = $range === 0 || ($customFrom !== '' && $customTo !== '');
$range      = $range ?: 60;

/* ---------- Data terbaru (kartu) ---------- */
$wave = db()->query(
    "SELECT m.*, d.device_name, d.device_code FROM wave_measurements m
     JOIN devices d ON d.id = m.device_id
     WHERE d.device_type = 'wave_sensor' AND d.is_active = 1
     ORDER BY m.measured_at DESC LIMIT 1"
)->fetch() ?: null;

$status = data_status($wave['measured_at'] ?? null);

/* ---------- Statistik rentang aktif ---------- */
$fromSql = $isCustom
    ? (preg_match('/^\d{4}-\d{2}-\d{2}$/', $customFrom) ? $customFrom . ' 00:00:00' : date('Y-m-d 00:00:00', strtotime('-1 day')))
    : date('Y-m-d H:i:s', time() - $range * 60);
$toSql = $isCustom && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customTo)
    ? $customTo . ' 23:59:59'
    : date('Y-m-d H:i:s');

$st = db()->prepare(
    "SELECT MIN(wave_height_m) AS hmin, MAX(wave_height_m) AS hmax, AVG(wave_height_m) AS havg, COUNT(*) AS cnt
     FROM wave_measurements WHERE device_id = ? AND measured_at BETWEEN ? AND ?"
);
$st->execute([$wave['device_id'] ?? 0, $fromSql, $toSql]);
$stats = $st->fetch();

/* ---------- Tabel histori (paginasi + filter tanggal) ---------- */
[$hFrom, $hTo] = date_range_from_get(date('Y-m-d', strtotime('-1 day')), date('Y-m-d'));
$page  = max(1, (int)($_GET['page'] ?? 1));
$per   = 15;
$off   = ($page - 1) * $per;

$cnt = db()->prepare("SELECT COUNT(*) c FROM wave_measurements WHERE device_id = ? AND measured_at BETWEEN ? AND ?");
$cnt->execute([$wave['device_id'] ?? 0, $hFrom . ' 00:00:00', $hTo . ' 23:59:59']);
$total = (int)$cnt->fetch()['c'];
$pages = max(1, (int)ceil($total / $per));

$rows = [];
if ($total > 0) {
    $q = db()->prepare(
        "SELECT * FROM wave_measurements WHERE device_id = ? AND measured_at BETWEEN ? AND ?
         ORDER BY measured_at DESC LIMIT {$per} OFFSET {$off}"
    );
    $q->execute([$wave['device_id'] ?? 0, $hFrom . ' 00:00:00', $hTo . ' 23:59:59']);
    $rows = $q->fetchAll();
}

/* ---------- Ekspor CSV ---------- */
if (($_GET['export'] ?? '') === 'csv') {
    [$cFrom, $cTo] = date_range_from_get(date('Y-m-d', strtotime('-1 day')), date('Y-m-d'));
    $q = db()->prepare(
        "SELECT m.*, d.device_code FROM wave_measurements m JOIN devices d ON d.id = m.device_id
         WHERE m.device_id = ? AND m.measured_at BETWEEN ? AND ? ORDER BY m.measured_at DESC"
    );
    $q->execute([$wave['device_id'] ?? 0, $cFrom . ' 00:00:00', $cTo . ' 23:59:59']);
    $out = [];
    foreach ($q->fetchAll() as $r) {
        $out[] = [
            $r['device_code'], $r['measured_at'], $r['wave_height_m'], $r['wave_period_s'],
            $r['wave_direction_deg'], $r['wind_speed_ms'], $r['wind_direction_deg'],
            $r['is_simulated'] ? 'DEMO' : 'RIIL'
        ];
    }
    stream_csv('data-gelombang-' . $cFrom . '_' . $cTo . '.csv',
        ['Kode Perangkat', 'Waktu', 'Tinggi Gelombang (m)', 'Periode (s)', 'Arah Gelombang (deg)', 'Kecepatan Angin (m/s)', 'Arah Angin (deg)', 'Sumber'], $out);
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-water"></i> Monitoring Gelombang</h1>
        <p class="page-desc">Kondisi gelombang laut real-time pada lokasi KJA: tinggi, periode, arah gelombang, angin,
            serta estimasi wave power. Data diambil dari sensor melalui API (bukan simulasi angka di sisi browser).</p>
    </div>
    <div class="page-actions">
        <span id="liveStatus"><?= status_pill($status) ?></span>
        <span class="source-note"><i class="fa-solid fa-microchip"></i> <?= $wave ? e($wave['device_name']) : 'Sensor belum terhubung' ?></span>
    </div>
</div>

<?php if (!$wave): ?>
<div class="callout callout-warn"><i class="fa-solid fa-triangle-exclamation"></i>
    <div>Belum ada data gelombang. Pastikan sensor terdaftar di menu <a href="perangkat.php">Manajemen Perangkat</a>
    dan mengirim data melalui <code>api/ingest.php</code>.</div>
</div>
<?php elseif ($status !== 'live'): ?>
<div class="callout callout-warn"><i class="fa-solid fa-hourglass-half"></i>
    <div>Status sensor <strong><?= e(data_status_label($status)) ?></strong> — data terakhir diterima
        <?= e(time_ago($wave['measured_at'])) ?>. Grafik akan otomatis berubah ke LIVE saat data baru masuk.</div>
</div>
<?php endif; ?>

<!-- Kartu statistik gelombang -->
<div class="stat-grid cols-5">
    <div class="stat-card stat-blue">
        <span class="stat-icon"><i class="fa-solid fa-wave-square"></i></span>
        <span class="stat-info">
            <span class="stat-label">Tinggi Terakhir <?= $wave ? demo_badge((int)$wave['is_simulated']) : '' ?></span>
            <span class="stat-value" id="curHeight"><?= $wave ? fmt_num($wave['wave_height_m'], 2) : '—' ?><span class="stat-unit">m</span></span>
            <span class="stat-foot" id="curTime"><?= $wave ? e(time_ago($wave['measured_at'])) : '—' ?></span>
        </span>
    </div>
    <div class="stat-card stat-navy">
        <span class="stat-icon"><i class="fa-solid fa-arrows-up-down"></i></span>
        <span class="stat-info">
            <span class="stat-label">Min / Max / Rata²</span>
            <span class="stat-value" id="statMinMax" style="font-size:17px"><?= fmt_num($stats['hmin'], 2) ?> / <?= fmt_num($stats['hmax'], 2) ?> / <?= fmt_num($stats['havg'], 2) ?><span class="stat-unit">m</span></span>
            <span class="stat-foot">pada rentang terpilih</span>
        </span>
    </div>
    <div class="stat-card stat-green">
        <span class="stat-icon"><i class="fa-solid fa-hourglass-half"></i></span>
        <span class="stat-info">
            <span class="stat-label">Periode Gelombang</span>
            <span class="stat-value" id="curPeriod"><?= $wave ? fmt_num($wave['wave_period_s'], 1) : '—' ?><span class="stat-unit">s</span></span>
            <span class="stat-foot">arah <?= $wave ? e(deg_to_compass($wave['wave_direction_deg'])) : '—' ?> (<?= $wave && $wave['wave_direction_deg'] !== null ? fmt_num($wave['wave_direction_deg'], 0) . '°' : '—' ?>)</span>
        </span>
    </div>
    <div class="stat-card stat-navy">
        <span class="stat-icon"><i class="fa-solid fa-wind"></i></span>
        <span class="stat-info">
            <span class="stat-label">Angin</span>
            <span class="stat-value" id="curWind"><?= $wave && $wave['wind_speed_ms'] !== null ? fmt_num($wave['wind_speed_ms'], 1) : '—' ?><span class="stat-unit">m/s</span></span>
            <span class="stat-foot">arah <?= $wave ? e(deg_to_compass($wave['wind_direction_deg'])) : '—' ?></span>
        </span>
    </div>
    <div class="stat-card stat-yellow">
        <span class="stat-icon"><i class="fa-solid fa-water-cursor"></i></span>
        <span class="stat-info">
            <span class="stat-label">Wave Power (estimasi)</span>
            <span class="stat-value" id="curKw"><?= $wave ? fmt_num(wave_power_kw($wave['wave_height_m'], $wave['wave_period_s']), 2) : '—' ?><span class="stat-unit">kW/m</span></span>
            <span class="stat-foot">P ≈ 0,49 · H² · T</span>
        </span>
    </div>
</div>

<!-- Grafik real-time -->
<div class="card">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-chart-line"></i> Tinggi Gelombang terhadap Waktu</h3>
            <p class="card-sub">Grafik diperbarui otomatis setiap <?= (int)setting('dashboard_refresh_seconds', 15) ?> detik</p>
        </div>
        <div class="card-tools">
            <div class="filter-chips" id="rangeChips">
                <a class="chip <?= $range === 15 ? 'active' : '' ?>" href="gelombang.php?range=15">15 Menit</a>
                <a class="chip <?= $range === 60 ? 'active' : '' ?>" href="gelombang.php?range=60">1 Jam</a>
                <a class="chip <?= $range === 360 ? 'active' : '' ?>" href="gelombang.php?range=360">6 Jam</a>
                <a class="chip <?= $range === 1440 ? 'active' : '' ?>" href="gelombang.php?range=1440">24 Jam</a>
            </div>
            <button type="button" class="btn btn-outline btn-sm" id="btnCustom">
                <i class="fa-regular fa-calendar"></i> Periode Khusus
            </button>
        </div>
    </div>
    <div class="card-body">
        <form method="get" action="gelombang.php" id="customForm" hidden class="input-group no-print" style="margin-bottom:14px">
            <input type="date" class="form-control" name="from" value="<?= e($customFrom ?: date('Y-m-d', strtotime('-1 day'))) ?>" required>
            <span>—</span>
            <input type="date" class="form-control" name="to" value="<?= e($customTo ?: date('Y-m-d')) ?>" required>
            <input type="hidden" name="range" value="0">
            <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Terapkan</button>
        </form>
        <div class="chart-box" id="waveChartBox"><canvas id="chartWave"></canvas></div>
    </div>
</div>

<!-- Histori data -->
<div class="card">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-table-list"></i> Histori Data Gelombang</h3>
            <p class="card-sub">Total <?= number_format($total, 0, ',', '.') ?> pengukuran pada rentang terpilih</p>
        </div>
        <div class="card-tools no-print">
            <a class="btn btn-outline btn-sm" href="gelombang.php?export=csv&from=<?= e($hFrom) ?>&to=<?= e($hTo) ?>">
                <i class="fa-solid fa-file-csv"></i> Ekspor CSV
            </a>
        </div>
    </div>
    <div class="card-body no-print" style="padding-bottom:0">
        <form method="get" action="gelombang.php" class="input-group">
            <input type="date" class="form-control" name="from" value="<?= e($hFrom) ?>">
            <span>—</span>
            <input type="date" class="form-control" name="to" value="<?= e($hTo) ?>">
            <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
        </form>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Waktu</th><th>Tinggi (m)</th><th>Periode (s)</th><th>Arah Gelombang</th>
                    <th>Angin (m/s)</th><th>Arah Angin</th><th>Sumber</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7"><div class="empty-state"><i class="fa-regular fa-folder-open"></i><p>Tidak ada data pada rentang ini.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="table-strong"><?= e(fmt_dt($r['measured_at'])) ?></td>
                    <td class="num"><?= fmt_num($r['wave_height_m'], 3) ?></td>
                    <td class="num"><?= fmt_num($r['wave_period_s'], 2) ?></td>
                    <td><?= e(deg_to_compass($r['wave_direction_deg'])) ?> (<?= $r['wave_direction_deg'] !== null ? fmt_num($r['wave_direction_deg'], 0) . '°' : '—' ?>)</td>
                    <td class="num"><?= $r['wind_speed_ms'] !== null ? fmt_num($r['wind_speed_ms'], 2) : '—' ?></td>
                    <td><?= e(deg_to_compass($r['wind_direction_deg'])) ?></td>
                    <td><?= demo_badge((bool)$r['is_simulated']) ?: '<span class="badge badge-green">Sensor</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
    <div class="pagination">
        <span class="page-info">Halaman <?= $page ?> dari <?= $pages ?></span>
        <?php if ($page > 1): ?><a href="<?= e(qs_merge(['page' => $page - 1])) ?>"><i class="fa-solid fa-angle-left"></i></a><?php endif; ?>
        <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>
            <?php if ($p === $page): ?><span class="page-cur"><?= $p ?></span>
            <?php else: ?><a href="<?= e(qs_merge(['page' => $p])) ?>"><?= $p ?></a><?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $pages): ?><a href="<?= e(qs_merge(['page' => $page + 1])) ?>"><i class="fa-solid fa-angle-right"></i></a><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php
/* Boot grafik: titik gelombang untuk rentang aktif dihitung sisi-server */
require_once __DIR__ . '/includes/boot-data.php';
skja_boot_script($isCustom
    ? skja_boot_wave(0, $customFrom, $customTo)
    : skja_boot_wave($range));
include __DIR__ . '/includes/footer.php';
?>
