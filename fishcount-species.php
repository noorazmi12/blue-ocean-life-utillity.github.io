<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_login();

$PAGE_ID = 'fishcount';
$PAGE_TITLE = 'Klasifikasi Spesies Ikan';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['FishCount AI', 'fishcount.php'], ['Klasifikasi Spesies', null]];

$result = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $file = $_FILES['photo'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = 'Silakan pilih berkas gambar yang valid.';
    } elseif (($file['size'] ?? 0) > UPLOAD_MAX_BYTES) {
        $error = 'Ukuran gambar melebihi batas 5 MB.';
    } else {
        $mime = mime_content_type($file['tmp_name']) ?: '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $error = 'Format gambar harus JPG, PNG, atau WEBP.';
        } else {
            $ch = curl_init(FISHCOUNT_SPECIES_API_URL);
            if ($ch) {
                $cfile = new CURLFile($file['tmp_name'], $mime, basename((string)$file['name']));
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => ['file' => $cfile],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 20,
                ]);
                $resp = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($resp && $code >= 200 && $code < 300) {
                    $result = json_decode($resp, true);
                } else {
                    $error = 'Layanan klasifikasi spesies (' . FISHCOUNT_SPECIES_API_URL . ') tidak merespons.';
                }
            }
        }
    }
}

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-microscope"></i> Klasifikasi Spesies Ikan (EfficientNetV2-S)</h1>
        <p class="page-desc">Identifikasi spesies Bandeng, Kerapu Macan, Kakap Putih, dan Bawal Bintang dari foto.</p>
    </div>
    <div class="page-actions"><a class="btn btn-outline btn-sm" href="fishcount.php"><i class="fa-solid fa-arrow-left"></i> Kembali ke FishCount</a></div>
</div>

<?php if ($error): ?><div class="callout callout-danger"><i class="fa-solid fa-circle-exclamation"></i><div><?= e($error) ?></div></div><?php endif; ?>
<?php if ($result): ?>
    <div class="callout callout-success"><i class="fa-solid fa-circle-check"></i><div>Hasil klasifikasi: <strong><?= e((string)($result['label'] ?? $result['species'] ?? 'Terdeteksi')) ?></strong></div></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><div><h3 class="card-title">Unggah Foto Spesies</h3></div></div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="form-group">
                <label class="form-label" for="photo">Berkas Foto (JPG/PNG/WEBP, maks 5 MB)</label>
                <input class="form-control" type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp" required>
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-magnifying-glass"></i> Analisis Spesies</button>
        </form>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
