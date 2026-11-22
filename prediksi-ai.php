<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

$anomalyParams = ['temp_sensor_c', 'ph'];
$cutoff24h = date('Y-m-d H:i:s', time() - 24 * 3600);
$anomalies = [];
$anomalyChart = [];
$sampleCounts = [];

foreach ($anomalyParams as $p) {
    $st = db()->prepare(
        "SELECT measured_at, {$p} AS val FROM water_measurements
         WHERE {$p} IS NOT NULL AND measured_at >= ?
         ORDER BY measured_at ASC, id ASC"
    );
    $st->execute([$cutoff24h]);
    $pts = $st->fetchAll();
    $n = count($pts);
    $sampleCounts[$p] = $n;
    $anomalyChart[$p] = [];
    if ($n < 10) {
        continue;
    }

    $vals = array_map(static fn($r) => (float)$r['val'], $pts);
    $mean = array_sum($vals) / $n;
    $variance = 0.0;
    foreach ($vals as $v) {
        $variance += ($v - $mean) ** 2;
    }
    $variance /= $n;
    $sd = sqrt($variance);
    if ($sd <= 0.0) {
        continue;
    }

    foreach ($pts as $r) {
        $val = (float)$r['val'];
        $z = ($val - $mean) / $sd;
        $point = [
            'time' => $r['measured_at'],
            'value' => $val,
            'z' => round($z, 2),
        ];
        $anomalyChart[$p][] = $point;
        if (abs($z) > 2.5) {
            $anomalies[] = $point + [
                'param' => $p,
                'mean' => round($mean, 2),
                'sd' => round($sd, 2),
            ];
        }
    }
}

usort($anomalies, static fn($a, $b) => strcmp($b['time'], $a['time']));
$anomalies = array_slice($anomalies, 0, 6);
$hasChart = false;
foreach ($anomalyChart as $pts) {
    if (count($pts) > 0) {
        $hasChart = true;
        break;
    }
}

$PAGE_ID = 'prediksi-ai';
$PAGE_TITLE = 'Analitik Kualitas Air';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Analitik Kualitas Air', null]];
$PAGE_SCRIPTS = [];
$NEED_CHART = true;

require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-magnifying-glass-chart"></i> Analitik Kualitas Air</h1>
        <p class="page-desc">Analisis penyimpangan pembacaan <strong>suhu air dan pH</strong> dari data sensor dalam 24 jam terakhir, menggunakan metode statistik Z-Score.</p>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card stat-green">
        <span class="stat-icon"><i class="fa-solid fa-magnifying-glass-chart"></i></span>
        <span class="stat-info">
            <span class="stat-label">Deteksi Anomali Air</span>
            <span class="stat-value" style="font-size:16px">Z-Score Aktif</span>
            <span class="stat-foot">Ambang |Z| &gt; 2,5</span>
        </span>
    </div>
    <div class="stat-card stat-blue">
        <span class="stat-icon"><i class="fa-solid fa-temperature-half"></i></span>
        <span class="stat-info">
            <span class="stat-label">Data Suhu (24 jam)</span>
            <span class="stat-value"><?= (int)($sampleCounts['temp_sensor_c'] ?? 0) ?></span>
            <span class="stat-foot">pembacaan valid tersedia</span>
        </span>
    </div>
    <div class="stat-card stat-blue">
        <span class="stat-icon"><i class="fa-solid fa-vial"></i></span>
        <span class="stat-info">
            <span class="stat-label">Data pH (24 jam)</span>
            <span class="stat-value"><?= (int)($sampleCounts['ph'] ?? 0) ?></span>
            <span class="stat-foot">pembacaan valid tersedia</span>
        </span>
    </div>
</div>

<div class="callout callout-info" style="margin-top:16px">
    <i class="fa-solid fa-circle-info"></i>
    <div><strong>Transparansi metode:</strong> Z-Score adalah <strong>analisis statistik</strong>, bukan model machine learning yang dilatih. Anomali berarti nilai menyimpang dari pola 24 jam terakhir, bukan otomatis menunjukkan air tidak aman bagi ikan.</div>
</div>

<div class="card" style="margin-top:18px">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-chart-line"></i> Deteksi Anomali Kualitas Air (|Z| &gt; 2,5)</h3>
            <p class="card-sub">Suhu dan pH · data 24 jam terakhir · garis putus-putus adalah batas statistik ±2,5</p>
        </div>
    </div>
    <div class="card-body">
        <?php if ($hasChart): ?>
            <div class="chart-box xs"><canvas id="chartAnomaly"></canvas></div>
        <?php else: ?>
            <div class="empty-state"><i class="fa-solid fa-chart-line"></i><p>Belum cukup data untuk menampilkan Z-Score. Diperlukan minimal 10 pembacaan per parameter dengan variasi nilai.</p></div>
        <?php endif; ?>
        <div class="table-wrap" style="margin-top:14px">
            <table class="table">
                <thead><tr><th>Waktu</th><th>Parameter</th><th>Nilai</th><th>Z-Score</th><th>Rata-rata / SD</th></tr></thead>
                <tbody>
                <?php if (!$anomalies): ?>
                    <tr><td colspan="5"><div class="empty-state"><i class="fa-regular fa-circle-check"></i><p>Tidak ada anomali terdeteksi atau data belum cukup dalam 24 jam terakhir.</p></div></td></tr>
                <?php endif; ?>
                <?php foreach ($anomalies as $a): ?>
                    <tr>
                        <td class="table-strong"><?= e(fmt_dt($a['time'])) ?></td>
                        <td><?= e(param_label($a['param'])) ?></td>
                        <td class="num"><?= fmt_num($a['value'], 2) ?></td>
                        <td><span class="badge <?= abs((float)$a['z']) > 3 ? 'badge-red' : 'badge-yellow' ?>">z = <?= fmt_num($a['z'], 2) ?></span></td>
                        <td class="num"><?= fmt_num($a['mean'], 2) ?> / <?= fmt_num($a['sd'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script id="waterAnomalyData" type="application/json"><?= safe_json($anomalyChart) ?></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const canvas = document.getElementById('chartAnomaly');
    const dataTag = document.getElementById('waterAnomalyData');
    if (!canvas || !dataTag) return;
    if (!window.Chart) {
        console.warn('Chart.js belum tersedia untuk grafik anomali.');
        return;
    }
    const data = JSON.parse(dataTag.textContent);
    const items = [];
    Object.entries({temp_sensor_c:'Suhu', ph:'pH'}).forEach(([key, label]) => {
        (data[key] || []).forEach(p => items.push({...p, label}));
    });
    items.sort((a, b) => a.time.localeCompare(b.time));
    if (!items.length) return;
    const labels = items.map(p => p.time + ' · ' + p.label);
    const values = items.map(p => p.z);
    new Chart(canvas, {
        type: 'line',
        data: {
            labels,
            datasets: [
                {label:'Z-Score pembacaan', data:values, borderColor:'#3090A8', backgroundColor:'rgba(48,144,168,.1)', pointRadius:2, tension:.1},
                {label:'Batas +2,5', data:labels.map(() => 2.5), borderColor:'#E5484D', borderDash:[6,5], pointRadius:0, borderWidth:1},
                {label:'Batas -2,5', data:labels.map(() => -2.5), borderColor:'#E5484D', borderDash:[6,5], pointRadius:0, borderWidth:1}
            ]
        },
        options: {responsive:true, maintainAspectRatio:false, animation:false,
            plugins:{legend:{display:true}},
            scales:{x:{ticks:{maxTicksLimit:8,maxRotation:0,callback:function(value){const label=this.getLabelForValue(value);return label.split(' · ')[0].slice(11,16);}}}, y:{title:{display:true,text:'Z-Score'}}}}
    });
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
