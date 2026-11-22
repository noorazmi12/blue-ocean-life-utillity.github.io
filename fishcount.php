<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/fishcount-ai.php';
require_login();

$PAGE_ID = 'fishcount';
$PAGE_TITLE = 'FishCount AI';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['FishCount AI', null]];
$PAGE_SCRIPTS = ['fishcount.js', 'fishcount-live.js'];

$pdo = db();
$results = $pdo->query('SELECT * FROM fishcount_results ORDER BY created_at DESC, id DESC LIMIT 20')->fetchAll();
$speciesList = $pdo->query('SELECT id, name, latin_name, lw_a, lw_b FROM fish_species WHERE is_active = 1 ORDER BY name')->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="assets/css/fishcount-live.css">
<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-fish"></i> FishCount AI — Pemantauan Biota</h1>
        <p class="page-desc">Kamera langsung, unggahan foto/video ikan, analisis visi AI, dan validasi manual operator.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline btn-sm" href="fishcount-species.php"><i class="fa-solid fa-microscope"></i> Klasifikasi Spesies</a>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><div><h3 class="card-title"><i class="fa-solid fa-camera"></i> Kamera &amp; Pengambilan Gambar</h3></div></div>
        <div class="card-body">
            <div class="fc-cam-box"><video id="fcVideo" autoplay playsinline muted></video><canvas id="fcCanvas" hidden></canvas></div>
            <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
                <button type="button" class="btn btn-primary btn-sm" id="btnCamStart"><i class="fa-solid fa-video"></i> Mulai Kamera</button>
                <button type="button" class="btn btn-outline btn-sm" id="btnCamSnap"><i class="fa-solid fa-camera"></i> Ambil Snapshot</button>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><div><h3 class="card-title"><i class="fa-solid fa-upload"></i> Unggah Media FishCount</h3></div></div>
        <div class="card-body">
            <form method="post" action="fishcount-species.php" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label class="form-label" for="fcSpeciesSel">Spesies Acuan</label>
                    <select class="form-select" id="fcSpeciesSel" name="species_id">
                        <?php foreach ($speciesList as $sp): ?>
                            <option value="<?= (int)$sp['id'] ?>"><?= e($sp['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="fcFile">Pilih Foto Ikan</label>
                    <input class="form-control" type="file" id="fcFile" name="photo" accept="image/jpeg,image/png,image/webp" required>
                </div>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-wand-magic-sparkles"></i> Klasifikasi Spesies</button>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><div><h3 class="card-title">Riwayat Deteksi FishCount (<?= count($results) ?>)</h3></div></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Waktu</th><th>Media</th><th>Estimasi AI</th><th>Validasi Manual</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($results as $r): ?>
        <tr>
            <td class="num">#<?= (int)$r['id'] ?></td>
            <td><?= e(fmt_dt($r['created_at'])) ?></td>
            <td><?= e($r['media_type']) ?></td>
            <td class="num"><?= $r['detected_count'] !== null ? (int)$r['detected_count'] . ' ekor' : '—' ?></td>
            <td class="num"><?= $r['manual_count'] !== null ? (int)$r['manual_count'] . ' ekor' : '—' ?></td>
            <td><span class="badge badge-outline"><?= e($r['processing_status']) ?></span></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
