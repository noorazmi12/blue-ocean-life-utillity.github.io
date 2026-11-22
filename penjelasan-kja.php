<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_login();

$PAGE_ID = 'penjelasan-kja';
$PAGE_TITLE = 'Penjelasan KJA';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Penjelasan KJA', null]];

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-book-open"></i> Mengenal Keramba Jaring Apung &amp; Energi OWC</h1>
        <p class="page-desc">Materi edukasi mengenai sistem budidaya KJA terintegrasi energi terbarukan gelombang laut.</p>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><div><h3 class="card-title">Apa itu Keramba Jaring Apung (KJA)?</h3></div></div>
        <div class="card-body">
            <p>Keramba Jaring Apung (KJA) adalah metode budidaya ikan laut pada wadah jaring yang ditopang rangka apung dan jangkar di perairan terlindung dengan kedalaman ideal 5–15 meter.</p>
            <p>Pemantauan suhu air, pH, serta kualitas perairan secara berkala menjaga kelangsungan hidup dan pertumbuhan optimal komoditas seperti Kakap Putih, Kerapu, dan Bandeng.</p>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><div><h3 class="card-title">Oscillating Water Column (OWC)</h3></div></div>
        <div class="card-body">
            <p>OWC memanfaatkan naik-turunnya muka air gelombang di dalam chamber untuk menekan udara melalui turbin dan menghasilkan energi listrik mandiri bagi sensor IoT serta penerangan ponton KJA.</p>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
