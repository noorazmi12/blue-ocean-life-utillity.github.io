<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_login();

$PAGE_ID = 'batimetri';
$PAGE_TITLE = 'Peta Batimetri';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Peta Batimetri', null]];
$PAGE_SCRIPTS = ['betri-layer.js', 'batimetri.js', 'batimetri-bmkg-v3.js', 'bmkg-wind.js'];
$NEED_LEAFLET = true;

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-mountain"></i> Peta Batimetri &amp; Prakiraan Angin BMKG</h1>
        <p class="page-desc">Kontur kedalaman perairan BETRI/GEBCO serta informasi kecepatan dan arah angin BMKG.</p>
    </div>
</div>

<div class="card">
    <div class="card-header"><div><h3 class="card-title"><i class="fa-solid fa-layer-group"></i> Peta Batimetri Interaktif</h3></div></div>
    <div class="card-body"><div class="map-box lg" id="batimetriMap"></div></div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
