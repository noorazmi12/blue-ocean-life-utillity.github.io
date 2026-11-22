<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_login();

$PAGE_ID = 'lokasi-kja';
$PAGE_TITLE = 'Lokasi KJA Banten';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Lokasi KJA', null]];
$PAGE_SCRIPTS = ['lokasi-kja.js'];
$NEED_LEAFLET = true;

$sites = db()->query('SELECT * FROM kja_sites WHERE is_active = 1 ORDER BY suitability_score DESC, id ASC')->fetchAll();
$bootPayload = ['sites' => $sites];

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-anchor"></i> Rekomendasi Lokasi KJA Banten</h1>
        <p class="page-desc">Evaluasi kesesuaian lokasi budidaya keramba jaring apung berdasarkan kedalaman, keterlindungan, dan akses.</p>
    </div>
</div>

<div class="card">
    <div class="card-header"><div><h3 class="card-title"><i class="fa-solid fa-map"></i> Peta Titik Rekomendasi KJA</h3></div></div>
    <div class="card-body"><div class="map-box lg" id="kjaSitesMap"></div></div>
</div>

<div class="card">
    <div class="card-header"><div><h3 class="card-title">Daftar Lokasi (<?= count($sites) ?>)</h3></div></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>Lokasi</th><th>Kabupaten</th><th>Kedalaman</th><th>Keterlindungan</th><th>Skor</th><th>Kelas</th></tr></thead><tbody>
    <?php foreach ($sites as $s): ?>
        <tr>
            <td><strong><?= e($s['site_name']) ?></strong><br><span class="source-note"><?= e($s['recommended_species'] ?: '') ?></span></td>
            <td><?= e($s['kabupaten']) ?></td>
            <td class="num"><?= fmt_num($s['depth_m'], 1) ?> m</td>
            <td><?= e($s['shelter']) ?></td>
            <td class="num"><?= (int)$s['suitability_score'] ?></td>
            <td><span class="badge badge-green"><?= e($s['suitability_class']) ?></span></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<script>window.SKJA_BOOT=<?= safe_json($bootPayload) ?>;</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
