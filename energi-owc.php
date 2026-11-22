<?php
/**
 * BOLU — Monitoring Energi OWC (Oscillating Water Column)
 * Daya, tegangan, arus, energi kumulatif, baterai, riwayat harian,
 * perbandingan potensi gelombang vs output aktual, dan indikasi prediksi
 * ketersediaan daya berbasis aturan sederhana (bukan model AI).
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$PAGE_ID    = 'energi-owc';
$PAGE_TITLE = 'Monitoring Energi OWC';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Energi OWC', null]];
$PAGE_SCRIPTS = ['energy-chart.js'];

/* ---------- Perangkat OWC aktif ---------- */
$device = db()->query(
    "SELECT * FROM devices WHERE device_type = 'owc' AND is_active = 1 ORDER BY id LIMIT 1"
)->fetch() ?: null;

$latest = null;
$daily = [];
if ($device) {
    $q = db()->prepare("SELECT * FROM owc_measurements WHERE device_id = ? ORDER BY measured_at DESC LIMIT 1");
    $q->execute([$device['id']]);
    $latest = $q->fetch() ?: null;

    $q = db()->prepare(
        "SELECT DATE(measured_at) AS d,
                ROUND(MAX(energy_wh) - MIN(energy_wh), 1) AS energy_wh,
                MAX(power_w) AS peak_w,
                ROUND(AVG(power_w), 1) AS avg_w
         FROM owc_measurements
         WHERE device_id = ? AND measured_at >= (NOW() - INTERVAL 7 DAY)
         GROUP BY DATE(measured_at)
         ORDER BY d DESC
         LIMIT 7"
    );
    $q->execute([$device['id']]);
    $daily = $q->fetchAll();
}

$status = data_status($latest['measured_at'] ?? null);

/* ---------- Prediksi ketersediaan daya (aturan sederhana) ---------- */
$lowTh = setting_float('low_power_threshold_w', 150);
$avgPower3h = null;
$prediksi = ['label' => 'Data belum cukup', 'cls' => 'badge-gray', 'desc' => 'Belum ada data daya untuk dianalisis.'];
if ($device && $latest) {
    $q = db()->prepare(
        "SELECT AVG(power_w) AS a FROM owc_measurements
         WHERE device_id = ? AND measured_at >= (NOW() - INTERVAL 3 HOUR)"
    );
    $q->execute([$device['id']]);
    $avgPower3h = $q->fetch()['a'];
    if ($avgPower3h !== null) {
        if ($avgPower3h >= $lowTh * 2) {
            $prediksi = ['label' => 'Ketersediaan Daya Baik', 'cls' => 'badge-green',
                         'desc' => 'Rata-rata daya 3 jam terakhir ' . fmt_num($avgPower3h, 0) . ' W berada jauh di atas ambang rendah (' . fmt_num($lowTh, 0) . ' W).'];
        } elseif ($avgPower3h >= $lowTh) {
            $prediksi = ['label' => 'Daya Moderat — Pantau Tren', 'cls' => 'badge-yellow',
                         'desc' => 'Rata-rata daya 3 jam terakhir ' . fmt_num($avgPower3h, 0) . ' W mendekati ambang rendah. Perhatikan tren gelombang pada beberapa jam ke depan.'];
        } else {
            $prediksi = ['label' => 'Risiko Daya Rendah Tinggi', 'cls' => 'badge-red',
                         'desc' => 'Rata-rata daya 3 jam terakhir ' . fmt_num($avgPower3h, 0) . ' W di bawah ambang ' . fmt_num($lowTh, 0) . ' W. Siapkan cadangan energi bila ada beban kritis.'];
        }
        $prediksi['note'] = 'Indikasi berbasis aturan sederhana (rata-rata bergerak), bukan model AI.';
    }
}

/* ---------- Total energi terpasang (meter kumulatif) ---------- */
$totalEnergy = $latest ? (float)$latest['energy_wh'] : null;

include __DIR__ . '/includes/header.php';
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
            <span class="stat-value" style="font-size:16px"><?= $device ? e(ucfirst($device['status'])) : '—' ?></span>
            <span class="stat-foot"><?= $latest ? 'data ' . e(time_ago($latest['measured_at'])) : 'belum ada data' ?></span>
        </span>
    </div>
    <div class="stat-card stat-yellow">
        <span class="stat-icon"><i class="fa-solid fa-bolt"></i></span>
        <span class="stat-info">
            <span class="stat-label">Daya Saat Ini <?= $latest ? demo_badge((int)$latest['is_simulated']) : '' ?></span>
            <span class="stat-value" id="curPower"><?= $latest ? fmt_num($latest['power_w'], 1) : '—' ?><span class="stat-unit">W</span></span>
            <span class="stat-foot"><?= $prediksi['note'] ?? 'daya terukur generator' ?></span>
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
    <div class="stat-card <?= $latest && $latest['battery_pct'] !== null && (float)$latest['battery_pct'] < 30 ? 'stat-red' : 'stat-navy' ?>">
        <span class="stat-icon"><i class="fa-solid fa-car-battery"></i></span>
        <span class="stat-info">
            <span class="stat-label">Baterai</span>
            <span class="stat-value"><?= $latest && $latest['battery_pct'] !== null ? fmt_num($latest['battery_pct'], 0) . '<span class="stat-unit">%</span>' : '—' ?></span>
            <span class="stat-foot"><?= $latest && $latest['battery_pct'] !== null ? 'tersimpan pada sistem' : 'sensor baterai tidak tersedia' ?></span>
        </span>
    </div>
</div>

<!-- Prediksi ketersediaan daya -->
<div class="callout callout-<?= $prediksi['cls'] === 'badge-green' ? 'success' : ($prediksi['cls'] === 'badge-red' ? 'danger' : 'warn') ?>">
    <i class="fa-solid fa-gauge-simple-high"></i>
    <div>
        <strong>Prediksi ketersediaan daya: <?= e($prediksi['label']) ?></strong><br>
        <?= e($prediksi['desc']) ?> <?= isset($prediksi['note']) ? '<em>' . e($prediksi['note']) . '</em>' : '' ?>
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
                    <td class="num"><?= fmt_num($d['energy_wh'] / 1000, 3) ?></td>
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
        <code>P ≈ 0,49·H²·T</code> dikali lebar penangkap <code><?= fmt_num(setting('owc_capture_width_m', 3.0), 1) ?> m</code>
        dan efisiensi <code><?= fmt_num(setting('owc_efficiency', 0.30) * 100, 0) ?>%</code> (dapat diubah di
        <a href="pengaturan.php">Pengaturan</a>). Daya listrik aktual adalah pengukuran nyata dari generator OWC.
        Selisih keduanya menggambarkan rugi-rugi konversi.</div>
</div>

<?php
/* Boot grafik: daya/energi/produksi harian dihitung sisi-server */
require_once __DIR__ . '/includes/boot-data.php';
skja_boot_script([
    'points' => skja_boot_energy(1440),
    'daily'  => skja_boot_energy_daily(7),
]);
include __DIR__ . '/includes/footer.php';
?>
