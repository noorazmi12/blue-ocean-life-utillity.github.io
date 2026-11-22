<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_login();

$PAGE_ID = 'prediksi-ai';
$PAGE_TITLE = 'Analitik Kualitas Air';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Analitik Kualitas Air', null]];
$PAGE_SCRIPTS = ['prediksi.js'];

$pdo = db();
$rows = $pdo->query('SELECT measured_at, temp_sensor_c, ph FROM water_measurements ORDER BY measured_at ASC LIMIT 500')->fetchAll();
$anomalies = [];
foreach (['temp_sensor_c', 'ph'] as $param) {
    $vals = array_values(array_filter(array_map(static fn ($r) => $r[$param] !== null ? (float)$r[$param] : null, $rows), static fn ($v) => $v !== null));
    $n = count($vals);
    if ($n < 10) {
        continue;
    }
    $mean = array_sum($vals) / $n;
    $var = array_sum(array_map(static fn ($v) => ($v - $mean) ** 2, $vals)) / $n;
    $sd = sqrt($var);
    if ($sd <= 0) {
        continue;
    }
    foreach ($rows as $r) {
        if ($r[$param] === null) {
            continue;
        }
        $z = ((float)$r[$param] - $mean) / $sd;
        if (abs($z) > 2.5) {
            $anomalies[] = ['time' => $r['measured_at'], 'param' => $param, 'value' => (float)$r[$param], 'z' => round($z, 2)];
        }
    }
}

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-brain"></i> Analitik Statistik &amp; Deteksi Anomali Z-Score</h1>
        <p class="page-desc">Analisis Z-score historis untuk mendeteksi lonjakan parameter suhu dan pH air.</p>
    </div>
</div>

<div class="card">
    <div class="card-header"><div><h3 class="card-title">Anomali Terdeteksi (|Z| &gt; 2,5)</h3></div></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>Waktu</th><th>Parameter</th><th>Nilai</th><th>Z-Score</th></tr></thead><tbody>
    <?php if (!$anomalies): ?><tr><td colspan="4">Tidak ada anomali signifikan dalam rentang data terbaru.</td></tr><?php endif; ?>
    <?php foreach (array_slice(array_reverse($anomalies), 0, 12) as $a): ?>
        <tr><td><?= e(fmt_dt($a['time'])) ?></td><td><?= e(param_label($a['param'])) ?></td><td class="num"><?= fmt_num($a['value'], 2) ?></td><td class="num"><?= fmt_num($a['z'], 2) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
