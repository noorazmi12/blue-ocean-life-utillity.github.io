<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/boot-data.php';
require_login();

$validMinutes = [
    '5' => 5,
    '60' => 60,
    '360' => 360,
    '1440' => 1440,
    '4320' => 4320,
    '10080' => 10080,
];
$rangeRaw = (string)($_GET['range'] ?? '');
$range = $validMinutes[$rangeRaw] ?? 5;
$param = (string)($_GET['param'] ?? 'temp_sensor_c');
if (!in_array($param, ['temp_sensor_c', 'ph', 'volt_ph_v'], true)) {
    $param = 'temp_sensor_c';
}

$device = db()->query(
    "SELECT * FROM devices WHERE device_type = 'water_sensor' AND is_active = 1 ORDER BY id ASC LIMIT 1"
)->fetch() ?: null;

$latest = null;
if ($device) {
    $stmt = db()->prepare(
        'SELECT * FROM water_measurements WHERE device_id = ? ORDER BY measured_at DESC, id DESC LIMIT 1'
    );
    $stmt->execute([(int)$device['id']]);
    $latest = $stmt->fetch() ?: null;
}

$defFrom = date('Y-m-d', time() - 86400);
$defTo = date('Y-m-d');
[$hFrom, $hTo] = date_range_from_query($_GET, $defFrom, $defTo);
$start = $hFrom . ' 00:00:00';
$end = $hTo . ' 23:59:59';

if (($_GET['export'] ?? '') === 'csv') {
    $exportRows = [];
    if ($device) {
        $st = db()->prepare(
            'SELECT measured_at, temp_sensor_c, ph, volt_ph_v, is_simulated
             FROM water_measurements
             WHERE device_id = ? AND measured_at BETWEEN ? AND ?
             ORDER BY measured_at DESC, id DESC'
        );
        $st->execute([(int)$device['id'], $start, $end]);
        foreach ($st->fetchAll() as $r) {
            $exportRows[] = [
                $device['device_code'],
                $r['measured_at'],
                $r['temp_sensor_c'],
                $r['ph'],
                $r['volt_ph_v'],
                ((int)$r['is_simulated']) === 1 ? 'DEMO' : 'RIIL',
            ];
        }
    }
    send_csv_download(
        "data-kualitas-air-{$hFrom}_{$hTo}.csv",
        ['Kode Perangkat', 'Waktu', 'Suhu Air (°C)', 'pH', 'Tegangan Modul pH (V)', 'Sumber'],
        $exportRows
    );
}

$total = 0;
$rows = [];
$per = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
if ($device) {
    $cntStmt = db()->prepare(
        'SELECT COUNT(*) FROM water_measurements WHERE device_id = ? AND measured_at BETWEEN ? AND ?'
    );
    $cntStmt->execute([(int)$device['id'], $start, $end]);
    $total = (int)$cntStmt->fetchColumn();
}
$pages = max(1, (int)ceil($total / $per));
if ($page > $pages) {
    $page = $pages;
}
if ($device && $total > 0) {
    $offset = ($page - 1) * $per;
    $rowStmt = db()->prepare(
        "SELECT * FROM water_measurements
         WHERE device_id = ? AND measured_at BETWEEN ? AND ?
         ORDER BY measured_at DESC, id DESC
         LIMIT {$per} OFFSET {$offset}"
    );
    $rowStmt->execute([(int)$device['id'], $start, $end]);
    $rows = $rowStmt->fetchAll();
}

$PARAMS = [
    'temp_sensor_c' => [
        'label' => 'Suhu Air',
        'unit' => '°C',
        'icon' => 'fa-temperature-half',
        'dec' => 1,
        'source' => 'Sensor suhu in-situ',
    ],
    'ph' => [
        'label' => 'pH',
        'unit' => '',
        'icon' => 'fa-vial',
        'dec' => 2,
        'source' => 'Sensor pH in-situ',
    ],
    'volt_ph_v' => [
        'label' => 'Tegangan Modul pH',
        'unit' => 'V',
        'icon' => 'fa-bolt',
        'dec' => 3,
        'source' => 'Output analog modul pH (bukan dinamo)',
    ],
];

$evals = [
    'temp_sensor_c' => ($latest && $latest['temp_sensor_c'] !== null) ? evaluate_value_info('temp_sensor_c', (float)$latest['temp_sensor_c']) : null,
    'ph' => ($latest && $latest['ph'] !== null) ? evaluate_value_info('ph', (float)$latest['ph']) : null,
    'volt_ph_v' => null,
];

$waterAlerts = db()->query(
    "SELECT a.*, d.device_name
     FROM alerts a
     LEFT JOIN devices d ON d.id = a.device_id
     WHERE a.parameter IN ('temp_sensor_c', 'ph')
     ORDER BY a.occurred_at DESC, a.id DESC
     LIMIT 8"
)->fetchAll();

$status = data_status($latest['measured_at'] ?? null);
$bootPayload = [
    'param' => $param,
    'points' => skja_boot_water($param, $range),
];

$PAGE_ID = 'kualitas-air';
$PAGE_TITLE = 'Monitoring Kualitas Air';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Kualitas Air', null]];
$PAGE_SCRIPTS = ['water-chart.js'];

require __DIR__ . '/includes/header.php';
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
    <?php foreach ($PARAMS as $key => $m):
        $ev = $evals[$key] ?? null;
        $val = $latest[$key] ?? null;
        $cardCls = ($ev && $ev['status'] === 'critical') ? 'stat-red' : (($ev && $ev['status'] === 'warning') ? 'stat-yellow' : 'stat-green');
    ?>
    <a class="stat-card <?= $cardCls ?>"
       href="kualitas-air.php?param=<?= e($key) ?>&range=<?= $range ?>" title="Lihat grafik historis <?= e($m['label']) ?>">
        <span class="stat-icon"><i class="fa-solid <?= e($m['icon']) ?>"></i></span>
        <span class="stat-info">
            <span class="stat-label"><?= e($m['label']) ?> <?= $latest ? demo_badge($latest['is_simulated']) : '' ?></span>
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
            <p class="card-sub"><?= e($PARAMS[$param]['label']) ?> · <?= e($PARAMS[$param]['source']) ?></p>
        </div>
        <div class="card-tools">
            <select class="form-select" id="paramSel" style="width:auto">
                <?php foreach ($PARAMS as $key => $m): ?>
                    <option value="<?= e($key) ?>" <?= $key === $param ? 'selected' : '' ?>><?= e($m['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-select" id="rangeSel" style="width:auto">
                <option value="5" <?= $range === 5 ? 'selected' : '' ?>>5 Menit (Detik)</option>
                <option value="60" <?= $range === 60 ? 'selected' : '' ?>>1 Jam</option>
                <option value="360" <?= $range === 360 ? 'selected' : '' ?>>6 Jam</option>
                <option value="1440" <?= $range === 1440 ? 'selected' : '' ?>>24 Jam</option>
                <option value="4320" <?= $range === 4320 ? 'selected' : '' ?>>3 Hari</option>
                <option value="10080" <?= $range === 10080 ? 'selected' : '' ?>>7 Hari</option>
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
            <p class="card-sub">Total <?= fmt_num($total, 0) ?> pengukuran pada rentang terpilih</p>
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
                    <td><?= demo_badge($r['is_simulated']) ?: '<span class="badge badge-green">Sensor</span>' ?></td>
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
                <?php if ($device):
                    $calMap = [
                        'calibrated' => ['badge-green', 'Terkalibrasi'],
                        'due' => ['badge-yellow', 'Jatuh tempo'],
                        'overdue' => ['badge-red', 'Terlambat'],
                        'none' => ['badge-gray', 'Tidak dicatat'],
                    ];
                    [$cls, $lbl] = $calMap[$device['calibration_status']] ?? $calMap['none'];
                ?>
                <dt>Perangkat</dt><dd><?= e($device['device_name']) ?> (<?= e($device['device_code']) ?>)</dd>
                <dt>Status kalibrasi</dt>
                <dd><span class="badge <?= $cls ?>"><i class="fa-solid fa-certificate"></i><?= e($lbl) ?></span></dd>
                <dt>Tanggal kalibrasi terakhir</dt><dd><?= !empty($device['calibration_date']) ? e(fmt_dt($device['calibration_date'])) : '—' ?></dd>
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

<script>window.SKJA_BOOT=<?= safe_json($bootPayload) ?>;</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
