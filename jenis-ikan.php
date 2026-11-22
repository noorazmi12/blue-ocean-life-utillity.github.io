<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_login();

$PAGE_ID = 'jenis-ikan';
$PAGE_TITLE = 'Jenis Ikan KJA';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Jenis Ikan', null]];
$PAGE_SCRIPTS = ['jenis-ikan.js'];

$species = db()->query('SELECT * FROM fish_species WHERE is_active = 1 ORDER BY id ASC')->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-fish-fins"></i> Katalog Jenis Ikan KJA</h1>
        <p class="page-desc">Parameter budidaya optimal suhu, pH, salinitas, siklus panen, dan koefisien allometrik.</p>
    </div>
</div>

<div class="card">
    <div class="card-header"><div><h3 class="card-title">Daftar Spesies (<?= count($species) ?>)</h3></div></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>Nama Spesies</th><th>Kategori</th><th>Suhu Optimal</th><th>pH Optimal</th><th>Salinitas Optimal</th><th>Siklus (bln)</th></tr></thead><tbody>
    <?php foreach ($species as $sp): ?>
        <tr>
            <td><strong><?= e($sp['name']) ?></strong><br><span class="source-note"><em><?= e($sp['latin_name'] ?: '') ?></em></span></td>
            <td><?= e($sp['category']) ?></td>
            <td class="num"><?= fmt_num($sp['optimal_temp_min'], 1) ?>–<?= fmt_num($sp['optimal_temp_max'], 1) ?> °C</td>
            <td class="num"><?= fmt_num($sp['optimal_ph_min'], 1) ?>–<?= fmt_num($sp['optimal_ph_max'], 1) ?></td>
            <td class="num"><?= fmt_num($sp['optimal_salinity_min'], 1) ?>–<?= fmt_num($sp['optimal_salinity_max'], 1) ?> PSU</td>
            <td class="num"><?= (int)$sp['cycle_months_min'] ?>–<?= (int)$sp['cycle_months_max'] ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
