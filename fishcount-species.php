<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

$labels = [
    'bandeng' => 'Bandeng',
    'kerapu_macan' => 'Kerapu Macan',
    'kakap_putih' => 'Kakap Putih',
    'bawal_bintang' => 'Bawal Bintang',
];

$detectImageMime = static function (string $bytes): string {
    $len = strlen($bytes);
    if ($len >= 3 && ord($bytes[0]) === 0xFF && ord($bytes[1]) === 0xD8 && ord($bytes[2]) === 0xFF) {
        return 'image/jpeg';
    }
    if ($len >= 8 && substr($bytes, 0, 4) === "\x89PNG") {
        return 'image/png';
    }
    if ($len >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
        return 'image/webp';
    }
    return '';
};

$result = null;
$error = null;
$preview = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $file = $_FILES['fish_image'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = 'Pilih JPG/PNG/WEBP maksimal 5 MB.';
    } else {
        $size = (int)($file['size'] ?? 0);
        $tmpName = (string)($file['tmp_name'] ?? '');
        if ($size <= 0 || $size > UPLOAD_MAX_BYTES || $tmpName === '' || !is_readable($tmpName)) {
            $error = 'Pilih JPG/PNG/WEBP maksimal 5 MB.';
        } else {
            $bytes = file_get_contents($tmpName);
            $mime = $bytes !== false ? $detectImageMime($bytes) : '';
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                $error = 'Format bukan JPG/PNG/WEBP yang valid.';
            } else {
                $b64 = base64_encode($bytes);
                $preview = "data:{$mime};base64,{$b64}";
                $payload = json_encode(['image_base64' => $b64, 'mime' => $mime]);
                $raw = null;

                if (getenv('SMART_KJA_WASM') !== '1') {
                    if (function_exists('curl_init')) {
                        $ch = curl_init(FISHCOUNT_SPECIES_API_URL);
                        curl_setopt_array($ch, [
                            CURLOPT_POST => true,
                            CURLOPT_POSTFIELDS => $payload,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT => 90,
                            CURLOPT_CONNECTTIMEOUT => 10,
                            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                        ]);
                        $resp = curl_exec($ch);
                        curl_close($ch);
                        if (is_string($resp) && $resp !== '') {
                            $raw = $resp;
                        }
                    } else {
                        $ctx = stream_context_create([
                            'http' => [
                                'method' => 'POST',
                                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                                'content' => $payload,
                                'timeout' => 90,
                                'ignore_errors' => true,
                            ],
                        ]);
                        $resp = @file_get_contents(FISHCOUNT_SPECIES_API_URL, false, $ctx);
                        if (is_string($resp) && $resp !== '') {
                            $raw = $resp;
                        }
                    }
                }

                if ($raw === null) {
                    $error = 'Python API tidak dapat dihubungi';
                } else {
                    $json = json_decode($raw, true);
                    if (is_array($json) && !empty($json['ok']) && is_string($json['species'] ?? null)) {
                        $result = $json;
                    } elseif (is_array($json)) {
                        $detail = $json['detail'] ?? $json['error'] ?? 'Respons AI belum tersedia';
                        $error = is_string($detail) ? $detail : 'Respons server AI tidak valid';
                    } else {
                        $error = 'Python API tidak dapat dihubungi';
                    }
                }
            }
        }
    }
}

$PAGE_ID = 'fishcount';
$PAGE_TITLE = 'Klasifikasi Spesies — EfficientNetV2';
$BREADCRUMB = [
    ['Dashboard', 'dashboard.php'],
    ['FishCount AI', 'fishcount.php'],
    ['Klasifikasi Spesies', null],
];
$PAGE_SCRIPTS = [];

require __DIR__ . '/includes/header.php';
?>

<div class="page-header"><div class="page-title"><h1><i class="fa-solid fa-fish"></i> Klasifikasi Spesies — EfficientNetV2</h1><p class="page-desc">Unggah foto untuk memprediksi <strong>spesies dominan</strong> dari empat spesies BOLU. Tidak menghitung ikan.</p></div></div>
<div class="card"><div class="card-header"><h3 class="card-title">Uji foto ikan</h3></div><div class="card-body">
  <form method="post" enctype="multipart/form-data" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center">
    <?= csrf_field() ?>
    <input type="file" name="fish_image" accept="image/jpeg,image/png,image/webp" required>
    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-brain"></i> Kenali spesies</button>
    <a class="btn btn-outline" href="fishcount.php">Kembali ke FishCount</a>
  </form>
  <p style="margin-top:12px">Model: <strong>EfficientNetV2-S</strong> · Kelas: Bandeng, Kerapu Macan, Kakap Putih, Bawal Bintang.</p>
  <?php if ($error !== null): ?><div class="callout callout-info" style="margin-top:16px"><strong>Belum berhasil:</strong> <?= e($error) ?>. Pastikan API Python berjalan dan model <code>efficientnetv2_best.pt</code> tersedia.</div><?php endif; ?>
  <?php if ($result !== null): ?>
    <div class="callout callout-info" style="margin-top:16px"><h3>Hasil klasifikasi</h3><p><strong><?= e($labels[$result['species']] ?? $result['species']) ?></strong> — skor model <?= e((string)($result['confidence_pct'] ?? '')) ?>%</p><p><strong>Catatan:</strong> prediksi satu spesies dominan dari keseluruhan gambar; bukan jumlah ikan. Skor model bukan bukti akurasi pengujian.</p></div>
    <table class="table" style="margin-top:12px"><thead><tr><th>Spesies</th><th>Skor</th></tr></thead><tbody>
    <?php foreach ((array)($result['scores'] ?? []) as $species => $score): ?><tr><td><?= e($labels[(string)$species] ?? (string)$species) ?></td><td><?= e((string)$score) ?>%</td></tr><?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
  <?php if ($preview !== null): ?><div style="margin-top:15px"><img src="<?= e($preview) ?>" alt="Foto uji" style="max-height:320px;max-width:100%;object-fit:contain;border-radius:8px"></div><?php endif; ?>
</div></div>

<?php require __DIR__ . '/includes/footer.php'; ?>
