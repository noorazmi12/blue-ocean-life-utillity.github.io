<?php
/**
 * BOLU — Monitoring Kualitas Air
 * Monitoring suhu, pH, dan tegangan analog keluaran modul pH.
 * Status normal/peringatan/kritis memakai ambang yang dapat dikonfigurasi
 * per spesies melalui menu Pengaturan (tabel thresholds).
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$PAGE_ID    = 'kualitas-air';
$PAGE_TITLE = 'Monitoring Kualitas Air';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Kualitas Air', null]];
$PAGE_SCRIPTS = ['water-chart.js'];

$validMinutes = ['5' => 5, '60' => 60, '360' => 360, '1440' => 1440, '4320' => 4320, '10080' => 10080];
$range = isset($_GET['range']) && isset($validMinutes[$_GET['range']]) ? (int)$_GET['range'] : 5;
$param = $_GET['param'] ?? 'temp_sensor_c';
$allowedParams = ['temp_sensor_c', 'ph', 'volt_ph_v'];
if (!in_array($param, $allowedParams, true)) {
    $param = 'temp_sensor_c';
}

$device = db()->query(
    "SELECT * FROM devices WHERE device_type = 'water_sensor' AND is_active = 1 ORDER BY id LIMIT 1"
)->fetch() ?: null;

$latest = null;
if ($device) {
    $q = db()->prepare("SELECT * FROM water_measurements WHERE device_id = ? ORDER BY measured_at DESC LIMIT 1");
    $q->execute([$device['id']]);
    $latest = $q->fetch() ?: null;
}
$status = data_status($latest['measured_at'] ?? null);

const PARAMS = [
    'temp_sensor_c' => ['label' => 'Suhu Air',  'unit' => '°C', 'icon' => 'fa-temperature-half', 'dec' => 1, 'source' => 'Sensor suhu in-situ'],
    'ph'            => ['label' => 'pH',        'unit' => '',   'icon' => 'fa-vial',             'dec' => 2, 'source' => 'Sensor pH in-situ'],
    'volt_ph_v'     => ['label' => 'Tegangan Modul pH', 'unit' => 'V', 'icon' => 'fa-bolt', 'dec' => 3, 'source' => 'Output analog modul pH (bukan dinamo)'],
];

/* Evaluasi ambang tiap parameter */
$evals = [];
foreach (PARAMS as $key => $meta) {
    $evals[$key] = $key !== 'volt_ph_v' && $latest && $latest[$key] !== null ? evaluate_value($key, $latest[$key]) : null;
}

/* ---------- Tabel pengukuran ---------- */
[$hFrom, $hTo] = date_range_from_get(date('Y-m-d', strtotime('-1 day')), date('Y-m-d'));
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 15;
$off  = ($page - 1) * $per;

$cnt = db()->prepare("SELECT COUNT(*) c FROM water_measurements WHERE device_id = ? AND measured_at BETWEEN ? AND ?");
$cnt->execute([$device['id'] ?? 0, $hFrom . ' 00:00:00', $hTo . ' 23:59:59']);
$total = (int)$cnt->fetch()['c'];
$pages = max(1, (int)ceil($total / $per));

$rows = [];
if ($total > 0) {
    $q = db()->prepare(
        "SELECT * FROM water_measurements WHERE device_id = ? AND measured_at BETWEEN ? AND ?
         ORDER BY measured_at DESC LIMIT {$per} OFFSET {$off}"
    );
    $q->execute([$device['id'] ?? 0, $hFrom . ' 00:00:00', $hTo . ' 23:59:59']);
    $rows = $q->fetchAll();
}

if (($_GET['export'] ?? '') === 'csv') {
    [$cFrom, $cTo] = date_range_from_get(date('Y-m-d', strtotime('-1 day')), date('Y-m-d'));
    $q = db()->prepare(
        "SELECT m.*, d.device_code FROM water_measurements m JOIN devices d ON d.id = m.device_id
         WHERE m.device_id = ? AND m.measured_at BETWEEN ? AND ? ORDER BY m.measured_at DESC"
    );
    $q->execute([$device['id'] ?? 0, $cFrom . ' 00:00:00', $cTo . ' 23:59:59']);
    $out = [];
    foreach ($q->fetchAll() as $r) {
        $out[] = [$r['device_code'], $r['measured_at'], $r['temp_sensor_c'], $r['ph'], $r['volt_ph_v'],
                  $r['is_simulated'] ? 'DEMO' : 'RIIL'];
    }
    stream_csv('data-kualitas-air-' . $cFrom . '_' . $cTo . '.csv',
        ['Kode Perangkat', 'Waktu', 'Suhu Air (°C)', 'pH', 'Tegangan Modul pH (V)', 'Sumber'], $out);
}

/* Riwayat peringatan parameter air */
$waterAlerts = db()->query(
    "SELECT a.*, d.device_name FROM alerts a LEFT JOIN devices d ON d.id = a.device_id
     WHERE a.parameter IN ('temp_sensor_c','ph')
     ORDER BY a.occurred_at DESC LIMIT 8"
)->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-droplet"></i> Monitoring Kualitas Air</h1>
        <p class="page-desc">Kondisi perairan pada lokasi KJA — alat hanya mengukur <strong>suhu air</strong> dan <strong>pH</strong>.
            Status <em>normal / peringatan / kritis</em> dihitung
            dari ambang yang dapat disesuaikan per spesies budidaya di <a href="pengaturan.php">Pengaturan &rarr; Ambang Parameter</a>
            — bukan ambang universal.</p>
    </div>
    <div class="page-actions">
        <span id="liveStatus"><?= status_pill($status) ?></span>
        <?php if ($device): ?><span class="source-note"><i class="fa-solid fa-microchip"></i> <?= e($device['device_name']) ?></span><?php endif; ?>
    </div>
</div>

<?php if (!$device || !$latest): ?>
<div class="callout callout-warn"><i class="fa-solid fa-triangle-exclamation"></i>
    <div>Belum ada data kualitas air. Pastikan sensor kualitas air terdaftar dan mengirim data melalui <code>api/ingest.php</code>.</div>
</div>
<?php endif; ?>

<!-- Kartu parameter -->
<div class="stat-grid">
    <?php foreach (PARAMS as $key => $m): $ev = $evals[$key]; $val = $latest ? $latest[$key] : null; ?>
    <a class="stat-card <?= $ev && $ev['status'] === 'critical' ? 'stat-red' : ($ev && $ev['status'] === 'warning' ? 'stat-yellow' : 'stat-green') ?>"
       href="kualitas-air.php?param=<?= e($key) ?>&range=<?= $range ?>" title="Lihat grafik historis <?= e($m['label']) ?>">
        <span class="stat-icon"><i class="fa-solid <?= e($m['icon']) ?>"></i></span>
        <span class="stat-info">
            <span class="stat-label"><?= e($m['label']) ?> <?= $latest ? demo_badge((int)$latest['is_simulated']) : '' ?></span>
            <span class="stat-value"><span data-water-value="<?= e($key) ?>"><?= $val !== null ? fmt_num($val, $m['dec']) : '—' ?></span><span class="stat-unit"><?= e($m['unit']) ?></span></span>
            <span class="stat-foot"><?= $ev ? e($ev['label']) : ($key === 'volt_ph_v' ? 'Pembacaan analog' : '—') ?> · <i class="fa-solid fa-database"></i> <?= e($m['source']) ?></span>
        </span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Grafik historis -->
<div class="card">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-chart-line"></i> Grafik Historis Parameter</h3>
            <p class="card-sub"><?= e(PARAMS[$param]['label']) ?> · <?= e(PARAMS[$param]['source']) ?></p>
        </div>
        <div class="card-tools">
            <select class="form-select" id="paramSel" style="width:auto">
                <?php foreach (PARAMS as $key => $m): ?>
                    <option value="<?= e($key) ?>" <?= $key === $param ? 'selected' : '' ?>><?= e($m['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-select" id="rangeSel" style="width:auto">
                <option value="5" <?= $range === 5 ? 'selected' : '' ?>>5 Menit (Detik)</option>
                <option value="60"   <?= $range === 60 ? 'selected' : '' ?>>1 Jam</option>
                <option value="360"  <?= $range === 360 ? 'selected' : '' ?>>6 Jam</option>
                <option value="1440" <?= $range === 1440 ? 'selected' : '' ?>>24 Jam</option>
                <option value="4320" <?= $range === 4320 ? 'selected' : '' ?>>3 Hari</option>
                <option value="10080"<?= $range === 10080 ? 'selected' : '' ?>>7 Hari</option>
            </select>
        </div>
    </div>
    <div class="card-body">
        <div class="chart-box"><canvas id="chartWater"></canvas></div>
    </div>
</div>

<!-- Tabel pengukuran -->
<div class="card">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-table-list"></i> Tabel Data Pengukuran</h3>
            <p class="card-sub">Total <?= number_format($total, 0, ',', '.') ?> pengukuran pada rentang terpilih</p>
        </div>
        <div class="card-tools no-print">
            <a class="btn btn-outline btn-sm" href="kualitas-air.php?export=csv&from=<?= e($hFrom) ?>&to=<?= e($hTo) ?>">
                <i class="fa-solid fa-file-csv"></i> Ekspor CSV
            </a>
        </div>
    </div>
    <div class="card-body no-print" style="padding-bottom:0">
        <form method="get" action="kualitas-air.php" class="input-group">
            <input type="hidden" name="param" value="<?= e($param) ?>">
            <input type="date" class="form-control" name="from" value="<?= e($hFrom) ?>">
            <span>—</span>
            <input type="date" class="form-control" name="to" value="<?= e($hTo) ?>">
            <input type="hidden" name="range" value="<?= $range ?>">
            <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
        </form>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Waktu</th><th>Suhu (°C)</th><th>pH</th><th>Tegangan Modul pH (V)</th><th>Sumber</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="5"><div class="empty-state"><i class="fa-regular fa-folder-open"></i><p>Tidak ada data pada rentang ini.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="table-strong"><?= e(fmt_dt($r['measured_at'])) ?></td>
                    <td class="num"><?= fmt_num($r['temp_sensor_c'], 1) ?></td>
                    <td class="num"><?= fmt_num($r['ph'], 2) ?></td>
                    <td class="num"><?= fmt_num($r['volt_ph_v'], 3) ?></td>
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

<div class="grid-2">
    <!-- Riwayat peringatan air -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title"><i class="fa-solid fa-bell"></i> Riwayat Peringatan Kualitas Air</h3>
                <p class="card-sub">8 kejadian terakhir berdasarkan ambang terkonfigurasi</p>
            </div>
        </div>
        <div class="alert-list">
            <?php if (!$waterAlerts): ?>
                <div class="empty-state"><i class="fa-regular fa-bell-slash"></i><p>Tidak ada peringatan kualitas air.</p></div>
            <?php endif; ?>
            <?php foreach ($waterAlerts as $al): ?>
            <a class="alert-item <?= $al['status'] === 'resolved' ? 'resolved' : '' ?>" href="peringatan.php?id=<?= (int)$al['id'] ?>">
                <span class="a-icon <?= e($al['severity']) ?>">
                    <i class="fa-solid <?= $al['severity'] === 'critical' ? 'fa-circle-exclamation' : ($al['severity'] === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-info') ?>"></i>
                </span>
                <span class="a-body">
                    <span class="a-title"><?= e($al['title']) ?></span>
                    <span class="a-msg"><?= e($al['message']) ?></span>
                    <span class="a-meta"><span><i class="fa-regular fa-clock"></i> <?= e(time_ago($al['occurred_at'])) ?></span><span><?= $al['status'] === 'open' ? 'Belum ditangani' : 'Selesai' ?></span></span>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Info kalibrasi -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title"><i class="fa-solid fa-screwdriver-wrench"></i> Status Kalibrasi Sensor</h3>
                <p class="card-sub">Waktu pembaruan data &amp; kondisi peralatan</p>
            </div>
        </div>
        <div class="card-body">
            <dl class="kv">
                <dt>Waktu pembaruan data</dt><dd><?= $latest ? e(fmt_dt($latest['measured_at'])) : '—' ?></dd>
                <dt>Status data</dt><dd><?= status_pill($status) ?></dd>
                <?php if ($device): ?>
                <dt>Perangkat</dt><dd><?= e($device['device_name']) ?> (<?= e($device['device_code']) ?>)</dd>
                <dt>Status kalibrasi</dt>
                <dd><?php
                    $calMap = ['calibrated' => ['badge-green', 'Terkalibrasi'], 'due' => ['badge-yellow', 'Jatuh tempo'], 'overdue' => ['badge-red', 'Terlambat'], 'none' => ['badge-gray', 'Tidak dicatat']];
                    [$cls, $lbl] = $calMap[$device['calibration_status']] ?? $calMap['none'];
                    echo '<span class="badge ' . $cls . '"><i class="fa-solid fa-certificate"></i>' . e($lbl) . '</span>';
                ?></dd>
                <dt>Tanggal kalibrasi terakhir</dt><dd><?= $device['calibration_date'] ? e(fmt_dt($device['calibration_date'])) : '—' ?></dd>
                <dt>Komunikasi terakhir</dt><dd><?= e(time_ago($device['last_communication'])) ?></dd>
                <?php endif; ?>
            </dl>
            <p class="form-hint" style="margin-top:12px">
                <i class="fa-solid fa-circle-question"></i> Ambang batas parameter dapat diubah per spesies pada
                <a href="pengaturan.php">Pengaturan &rarr; Ambang Parameter</a>.
            </p>
        </div>
    </div>
</div>

<?php
/* Boot grafik: titik parameter aktif dihitung sisi-server */
require_once __DIR__ . '/includes/boot-data.php';
skja_boot_script([
    'param'  => $param,
    'points' => skja_boot_water($param, $range),
]);
include __DIR__ . '/includes/footer.php';
?>
