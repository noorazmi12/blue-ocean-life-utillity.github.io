<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/boot-data.php';
require_login();

$device = db()->query(
    "SELECT * FROM devices WHERE device_type = 'owc' AND is_active = 1 ORDER BY id ASC LIMIT 1"
)->fetch() ?: null;

$latest = null;
if ($device) {
    $st = db()->prepare('SELECT * FROM owc_measurements WHERE device_id = ? ORDER BY measured_at DESC, id DESC LIMIT 1');
    $st->execute([(int)$device['id']]);
    $latest = $st->fetch() ?: null;
}

if (($_GET['export'] ?? '') === 'csv') {
    $defFrom = date('Y-m-d', time() - 7 * 86400);
    $defTo = date('Y-m-d');
    [$hFrom, $hTo] = date_range_from_query($_GET, $defFrom, $defTo);
    $exportRows = [];
    if ($device) {
        $stCsv = db()->prepare(
            'SELECT measured_at, power_w, voltage_v, current_a, energy_wh, battery_pct, is_simulated
             FROM owc_measurements WHERE device_id = ? AND measured_at BETWEEN ? AND ? ORDER BY measured_at DESC'
        );
        $stCsv->execute([(int)$device['id'], $hFrom . ' 00:00:00', $hTo . ' 23:59:59']);
        foreach ($stCsv->fetchAll() as $r) {
            $exportRows[] = [
                $device['device_code'],
                $r['measured_at'],
                $r['power_w'],
                $r['voltage_v'],
                $r['current_a'],
                $r['energy_wh'],
                $r['battery_pct'],
                ((int)$r['is_simulated']) === 1 ? 'DEMO' : 'RIIL',
            ];
        }
    }
    send_csv_download(
        "data-energi-owc-{$hFrom}_{$hTo}.csv",
        ['Kode Perangkat', 'Waktu', 'Daya (W)', 'Tegangan (V)', 'Arus (A)', 'Energi Kumulatif (Wh)', 'Baterai (%)', 'Sumber'],
        $exportRows
    );
}

$dailyAsc = skja_boot_energy_daily(7);
$daily = array_reverse($dailyAsc);
usort($daily, static fn($a, $b) => strcmp($b['d'], $a['d']));
$daily = array_slice($daily, 0, 7);

$status = data_status($latest['measured_at'] ?? null);
$lowTh = setting_float('low_power_threshold_w', 150.0);
$prediksi = [
    'label' => 'Data belum cukup',
    'cls' => 'badge-gray',
    'desc' => 'Belum ada data daya untuk dianalisis.',
    'note' => null,
];

if ($device && $latest) {
    $cutoff3h = date('Y-m-d H:i:s', time() - 3 * 3600);
    $stAvg = db()->prepare('SELECT AVG(power_w) FROM owc_measurements WHERE device_id = ? AND measured_at >= ?');
    $stAvg->execute([(int)$device['id'], $cutoff3h]);
    $avgPower = $stAvg->fetchColumn();
    if ($avgPower !== false && $avgPower !== null) {
        $avgPower3h = (float)$avgPower;
        if ($avgPower3h >= $lowTh * 2) {
            $prediksi = [
                'label' => 'Ketersediaan Daya Baik',
                'cls' => 'badge-green',
                'desc' => 'Rata-rata daya 3 jam terakhir ' . fmt_num($avgPower3h, 0) . ' W berada jauh di atas ambang rendah (' . fmt_num($lowTh, 0) . ' W).',
                'note' => 'Indikasi berbasis aturan sederhana (rata-rata bergerak), bukan model AI.',
            ];
        } elseif ($avgPower3h >= $lowTh) {
            $prediksi = [
                'label' => 'Daya Moderat — Pantau Tren',
                'cls' => 'badge-yellow',
                'desc' => 'Rata-rata daya 3 jam terakhir ' . fmt_num($avgPower3h, 0) . ' W mendekati ambang rendah. Perhatikan tren gelombang pada beberapa jam ke depan.',
                'note' => 'Indikasi berbasis aturan sederhana (rata-rata bergerak), bukan model AI.',
            ];
        } else {
            $prediksi = [
                'label' => 'Risiko Daya Rendah Tinggi',
                'cls' => 'badge-red',
                'desc' => 'Rata-rata daya 3 jam terakhir ' . fmt_num($avgPower3h, 0) . ' W di bawah ambang ' . fmt_num($lowTh, 0) . ' W. Siapkan cadangan energi bila ada beban kritis.',
                'note' => 'Indikasi berbasis aturan sederhana (rata-rata bergerak), bukan model AI.',
            ];
        }
    }
}

$totalEnergy = $latest ? (float)$latest['energy_wh'] : null;
$bootPayload = [
    'points' => skja_boot_energy(1440),
    'daily' => ['rows' => $dailyAsc],
];

$PAGE_ID = 'energi-owc';
$PAGE_TITLE = 'Monitoring Energi OWC';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Energi OWC', null]];
$PAGE_SCRIPTS = ['energy-chart.js'];

require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-bolt"></i> Monitoring Energi OWC</h1>
        <p class="page-desc">Performa pembangkit listrik gelombang Oscillating Water Column. Grafik membedakan antara
            <strong>estimasi potensi energi gelombang</strong> (teoretis) dan <strong>daya listrik aktual</strong> yang terukur pada generator.</p>
    </div>
    <div class="page-actions">
        <span id="liveStatus"><?= status_pill($status) ?></span>
        <?php if ($device): ?><span class="source-note"><i class="fa-solid fa-microchip"></i> <?= e($device['device_name']) ?></span><?php endif; ?>
    </div>
</div>

<?php if (!$device): ?>
<div class="callout callout-warn"><i class="fa-solid fa-triangle-exclamation"></i>
    <div>Belum ada perangkat OWC terdaftar. Tambahkan melalui <a href="perangkat.php">Manajemen Perangkat</a>.</div>
</div>
<?php endif; ?>

<!-- Status sistem -->
<div class="stat-grid cols-5">
    <div class="stat-card <?= $status === 'live' ? 'stat-green' : ($status === 'delayed' ? 'stat-yellow' : 'stat-red') ?>">
        <span class="stat-icon"><i class="fa-solid fa-circle-nodes"></i></span>
        <span class="stat-info">
            <span class="stat-label">Status Sistem OWC</span>
            <span class="stat-value" style="font-size:16px"><?= $device ? e(ucfirst((string)$device['status'])) : '—' ?></span>
            <span class="stat-foot"><?= $latest ? 'data ' . e(time_ago($latest['measured_at'])) : 'belum ada data' ?></span>
        </span>
    </div>
    <div class="stat-card stat-yellow">
        <span class="stat-icon"><i class="fa-solid fa-bolt"></i></span>
        <span class="stat-info">
            <span class="stat-label">Daya Saat Ini <?= $latest ? demo_badge($latest['is_simulated']) : '' ?></span>
            <span class="stat-value" id="curPower"><?= $latest ? fmt_num($latest['power_w'], 1) : '—' ?><span class="stat-unit">W</span></span>
            <span class="stat-foot"><?= e($prediksi['note'] ?? 'daya terukur generator') ?></span>
        </span>
    </div>
    <div class="stat-card stat-blue">
        <span class="stat-icon"><i class="fa-solid fa-plug-circle-bolt"></i></span>
        <span class="stat-info">
            <span class="stat-label">Tegangan / Arus</span>
            <span class="stat-value" style="font-size:17px"><?= $latest ? fmt_num($latest['voltage_v'], 1) . ' V' : '—' ?> / <?= $latest ? fmt_num($latest['current_a'], 2) . ' A' : '—' ?></span>
            <span class="stat-foot">keluaran pembangkit</span>
        </span>
    </div>
    <div class="stat-card stat-green">
        <span class="stat-icon"><i class="fa-solid fa-battery-full"></i></span>
        <span class="stat-info">
            <span class="stat-label">Energi Kumulatif</span>
            <span class="stat-value"><?= $totalEnergy !== null ? fmt_num($totalEnergy / 1000, 2) : '—' ?><span class="stat-unit">kWh</span></span>
            <span class="stat-foot">total <?= $totalEnergy !== null ? fmt_num($totalEnergy, 0) : '—' ?> Wh sejak meter nol</span>
        </span>
    </div>
    <div class="stat-card <?= ($latest && $latest['battery_pct'] !== null && (float)$latest['battery_pct'] < 30) ? 'stat-red' : 'stat-navy' ?>">
        <span class="stat-icon"><i class="fa-solid fa-car-battery"></i></span>
        <span class="stat-info">
            <span class="stat-label">Baterai</span>
            <span class="stat-value"><?= ($latest && $latest['battery_pct'] !== null) ? fmt_num($latest['battery_pct'], 0) . '<span class="stat-unit">%</span>' : '—' ?></span>
            <span class="stat-foot"><?= ($latest && $latest['battery_pct'] !== null) ? 'tersimpan pada sistem' : 'sensor baterai tidak tersedia' ?></span>
        </span>
    </div>
</div>

<!-- Prediksi ketersediaan daya -->
<div class="callout callout-<?= $prediksi['cls'] === 'badge-green' ? 'success' : ($prediksi['cls'] === 'badge-red' ? 'danger' : 'warn') ?>">
    <i class="fa-solid fa-gauge-simple-high"></i>
    <div>
        <strong>Prediksi ketersediaan daya: <?= e($prediksi['label']) ?></strong><br>
        <?= e($prediksi['desc']) ?> <?= !empty($prediksi['note']) ? '<em>' . e($prediksi['note']) . '</em>' : '' ?>
        <em>Prediksi berbasis model AI belum tersedia — lihat halaman <a href="prediksi-ai.php">Prediksi AI</a>.</em>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title"><i class="fa-solid fa-chart-line"></i> Daya terhadap Waktu (24 Jam)</h3>
                <p class="card-sub">Garis kuning: daya terukur · garis biru putus-putus: estimasi potensi gelombang</p>
            </div>
        </div>
        <div class="card-body">
            <div class="chart-box"><canvas id="chartPower"></canvas></div>
        </div>
    </div>
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title"><i class="fa-solid fa-arrow-trend-up"></i> Energi Kumulatif (24 Jam)</h3>
                <p class="card-sub">Akumulasi energi pada meter (Wh)</p>
            </div>
        </div>
        <div class="card-body">
            <div class="chart-box"><canvas id="chartEnergy"></canvas></div>
        </div>
    </div>
</div>

<!-- Riwayat produksi harian -->
<div class="card">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-calendar-days"></i> Riwayat Produksi Listrik Harian (7 Hari)</h3>
            <p class="card-sub">Selisih meter energi kumulatif per hari</p>
        </div>
    </div>
    <div class="card-body">
        <div class="chart-box sm"><canvas id="chartDaily"></canvas></div>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Tanggal</th><th>Energi (Wh)</th><th>Energi (kWh)</th><th>Daya Puncak (W)</th><th>Daya Rata-rata (W)</th></tr></thead>
            <tbody>
            <?php if (!$daily): ?>
                <tr><td colspan="5"><div class="empty-state"><i class="fa-regular fa-folder-open"></i><p>Belum ada data produksi.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($daily as $d): ?>
                <tr>
                    <td class="table-strong"><?= e(fmt_dt($d['d'])) ?></td>
                    <td class="num"><?= fmt_num($d['energy_wh'], 1) ?></td>
                    <td class="num"><?= fmt_num(((float)$d['energy_wh']) / 1000, 3) ?></td>
                    <td class="num"><?= fmt_num($d['peak_w'], 1) ?></td>
                    <td class="num"><?= fmt_num($d['avg_w'], 1) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="callout callout-info">
    <i class="fa-solid fa-circle-info"></i>
    <div><strong>Cara membaca:</strong> Estimasi potensi gelombang dihitung dari rumus daya gelombang
        <code>P ≈ 0,49·H²·T</code> dikali lebar penangkap <code><?= fmt_num(setting_float('owc_capture_width_m', 3.0), 1) ?> m</code>
        dan efisiensi <code><?= fmt_num(setting_float('owc_efficiency', 0.30) * 100, 0) ?>%</code> (dapat diubah di
        <a href="pengaturan.php">Pengaturan</a>). Daya listrik aktual adalah pengukuran nyata dari generator OWC.
        Selisih keduanya menggambarkan rugi-rugi konversi.</div>
</div>

<script>window.SKJA_BOOT=<?= safe_json($bootPayload) ?>;</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
