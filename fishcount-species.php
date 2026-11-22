<?php
/** BOLU: klasifikasi 4 spesies gambar utuh, TANPA hitung ikan. */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_login();
$PAGE_ID = 'fishcount';
$PAGE_TITLE = 'Klasifikasi Spesies — EfficientNetV2';
$BREADCRUMB = [['Dashboard','dashboard.php'], ['FishCount AI','fishcount.php'], ['Klasifikasi Spesies',null]];
$result=null; $error=null; $preview=null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $file=$_FILES['fish_image']??null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5*1024*1024) {
        $error='Pilih JPG/PNG/WEBP maksimal 5 MB.';
    } else {
        $info=@getimagesize($file['tmp_name']);
        $mime=$info['mime']??'';
        if (!in_array($mime,['image/jpeg','image/png','image/webp'],true)) {
            $error='Format bukan JPG/PNG/WEBP yang valid.';
        } else {
            $bytes=file_get_contents($file['tmp_name']);
            $preview='data:'.$mime.';base64,'.base64_encode($bytes);
            $payload=json_encode(['image_base64'=>base64_encode($bytes),'mime'=>$mime]);
            $apiUrl='http://127.0.0.1:8000/classify'; // Python berjalan pada komputer XAMPP yang sama
            $ctx=stream_context_create(['http'=>[
                'method'=>'POST','header'=>"Content-Type: application/json\r\nAccept: application/json\r\n",
                'content'=>$payload,'timeout'=>90,'ignore_errors'=>true,
            ]]);
            $raw=@file_get_contents($apiUrl,false,$ctx);
            $json=($raw!==false)?json_decode($raw,true):null;
            if (is_array($json) && !empty($json['ok']) && isset($json['species'])) {
                $result=$json;
            } else {
                $detail=is_array($json)?($json['detail']??$json['error']??'Respons AI belum tersedia'): 'Python API tidak dapat dihubungi';
                $error=is_string($detail)?$detail:'Respons server AI tidak valid';
            }
        }
    }
}
$labels=['bandeng'=>'Bandeng','kerapu_macan'=>'Kerapu Macan','kakap_putih'=>'Kakap Putih','bawal_bintang'=>'Bawal Bintang'];
include __DIR__ . '/includes/header.php';
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
  <?php if ($error): ?><div class="callout callout-info" style="margin-top:16px"><strong>Belum berhasil:</strong> <?= e($error) ?>. Pastikan API Python berjalan dan model <code>efficientnetv2_best.pt</code> tersedia.</div><?php endif; ?>
  <?php if ($result): ?>
    <div class="callout callout-info" style="margin-top:16px"><h3>Hasil klasifikasi</h3><p><strong><?= e($labels[$result['species']]??$result['species']) ?></strong> — skor model <?= e((string)$result['confidence_pct']) ?>%</p><p><strong>Catatan:</strong> prediksi satu spesies dominan dari keseluruhan gambar; bukan jumlah ikan. Skor model bukan bukti akurasi pengujian.</p></div>
    <table class="table" style="margin-top:12px"><thead><tr><th>Spesies</th><th>Skor</th></tr></thead><tbody>
    <?php foreach (($result['scores']??[]) as $species=>$score): ?><tr><td><?= e($labels[$species]??$species) ?></td><td><?= e((string)$score) ?>%</td></tr><?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
  <?php if ($preview): ?><div style="margin-top:15px"><img src="<?= e($preview) ?>" alt="Foto uji" style="max-height:320px;max-width:100%;object-fit:contain;border-radius:8px"></div><?php endif; ?>
</div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
