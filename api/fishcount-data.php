<?php
/**
 * BOLU — API FishCount
 * GET:
 *   ?view=recent&limit=20   -> riwayat hasil terbaru
 *   ?view=stats&days=7      -> seri jumlah ikan + distribusi ukuran
 * POST (butuh sesi + CSRF header X-CSRF-Token):
 *   ?action=snapshot  {image: dataURL}  -> simpan snapshot kamera
 *              (+ analisis AI vision bila layanan FISHCOUNT_AI_URL terkonfigurasi)
 *   ?action=validate  {id, manual_count, notes} -> validasi penghitungan manual
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/fishcount-ai.php';
require_login_api();

$method = $_SERVER['REQUEST_METHOD'];

/* ================= POST ================= */
if ($method === 'POST') {
    csrf_check();
    $action = $_GET['action'] ?? '';

    if ($action === 'snapshot') {
        $in = json_input();
        $dataUrl = $in['image'] ?? '';
        if (!preg_match('#^data:image/jpeg;base64,#', $dataUrl)) {
            json_fail('Format gambar tidak didukung (hanya JPEG dari kamera).', 400);
        }
        $b64 = substr($dataUrl, strlen('data:image/jpeg;base64,'));
        $bin = base64_decode($b64, true);
        if ($bin === false || strlen($bin) > UPLOAD_MAX_BYTES) {
            json_fail('Gambar kosong atau melebihi batas ukuran.', 400);
        }
        // Verifikasi benar-benar gambar
        $tmp = tempnam(sys_get_temp_dir(), 'snap');
        file_put_contents($tmp, $bin);
        $info = @getimagesize($tmp);
        unlink($tmp);
        if ($info === false || ($info['mime'] ?? '') !== 'image/jpeg') {
            json_fail('Berkas bukan gambar JPEG yang valid.', 400);
        }
        if (!is_dir(UPLOAD_FISHCOUNT_DIR)) @mkdir(UPLOAD_FISHCOUNT_DIR, 0775, true);
        $name = 'snap_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
        if (file_put_contents(UPLOAD_FISHCOUNT_DIR . $name, $bin) === false) {
            json_fail('Gagal menyimpan snapshot.', 500);
        }

        $dev = db()->query("SELECT id FROM devices WHERE device_type='camera' AND is_active=1 ORDER BY id LIMIT 1")->fetch();
        $st = db()->prepare('INSERT INTO fishcount_results (device_id, image_path, processing_status, status_message, notes) VALUES (?, ?, ?, ?, ?)');
        $st->execute([
            $dev ? (int)$dev['id'] : null,
            'uploads/fishcount/' . $name,
            'no_ai',
            fishcount_ai_enabled()
                ? 'Snapshot tersimpan, memproses analisis AI…'
                : 'Snapshot kamera — layanan AI belum dikonfigurasi.',
            ($in['notes'] ?? '') !== '' ? mb_substr(trim($in['notes']), 0, 255) : null
        ]);
        $id = (int)db()->lastInsertId();

        // Analisis AI vision (opsional) — hanya bila layanan dikonfigurasi.
        // Tanpa layanan, status tetap "no_ai" (Menunggu AI) — tidak ada hasil dikarang.
        $aiOut = null;
        if (fishcount_ai_enabled()) {
            $ai = fishcount_ai_analyze(UPLOAD_FISHCOUNT_DIR . $name, 'image/jpeg');
            if ($ai['ok'] && fishcount_ai_apply($id, $ai['data'])) {
                $aiOut = $ai['data'];
            } else {
                fishcount_ai_mark_failed($id, (string)($ai['error'] ?? 'tidak diketahui'));
            }
        }
        json_out(['ok' => true, 'id' => $id, 'image' => 'uploads/fishcount/' . $name, 'ai' => $aiOut]);
    }

    if ($action === 'validate') {
        if (!can_manage()) {
            json_fail('Hanya admin/operator yang dapat memvalidasi.', 403);
        }
        $in = json_input();
        $id = (int)($in['id'] ?? 0);
        $count = filter_var($in['manual_count'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000]]);
        if ($id <= 0 || $count === false) {
            json_fail('Data validasi tidak valid.', 400);
        }
        $cu = current_user();
        $st = db()->prepare('UPDATE fishcount_results SET manual_count = ?, is_manual = 1, validated_by = ?, validated_at = NOW(), notes = ? WHERE id = ?');
        $st->execute([$count, $cu['id'], ($in['notes'] ?? '') !== '' ? mb_substr(trim($in['notes']), 0, 255) : null, $id]);
        json_out(['ok' => (bool)$st->rowCount()]);
    }

    json_fail('Aksi tidak dikenal.', 400);
}

/* ================= GET ================= */
$view = $_GET['view'] ?? 'recent';

if ($view === 'recent') {
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
    $rows = db()->query(
        "SELECT fr.*, d.device_name FROM fishcount_results fr
         LEFT JOIN devices d ON d.id = fr.device_id
         ORDER BY fr.created_at DESC LIMIT {$limit}"
    )->fetchAll();
    json_out(['ok' => true, 'data' => $rows]);
}

if ($view === 'stats') {
    $days = max(1, min(90, (int)($_GET['days'] ?? 7)));

    $st = db()->prepare(
        "SELECT DATE(created_at) AS d, MAX(COALESCE(detected_count, manual_count)) AS c
         FROM fishcount_results
         WHERE created_at >= (NOW() - INTERVAL {$days} DAY)
         GROUP BY DATE(created_at) ORDER BY d ASC"
    );
    $st->execute();
    $series = [];
    foreach ($st->fetchAll() as $r) {
        $series[] = ['t' => $r['d'], 'c' => $r['c'] !== null ? (int)$r['c'] : null];
    }

    $rows = db()->query(
        "SELECT avg_length_cm FROM fishcount_results
         WHERE avg_length_cm IS NOT NULL AND created_at >= (NOW() - INTERVAL 30 DAY)"
    )->fetchAll();
    $buckets = ['10-14' => 0, '15-19' => 0, '20-24' => 0, '25-29' => 0, '30+' => 0];
    foreach ($rows as $r) {
        $l = (float)$r['avg_length_cm'];
        if ($l < 15)      $buckets['10-14']++;
        elseif ($l < 20)  $buckets['15-19']++;
        elseif ($l < 25)  $buckets['20-24']++;
        elseif ($l < 30)  $buckets['25-29']++;
        else              $buckets['30+']++;
    }
    $sizes = [];
    foreach ($buckets as $k => $v) $sizes[] = ['bucket' => $k, 'n' => $v];

    json_out(['ok' => true, 'data' => ['series' => $series, 'sizes' => $sizes]]);
}

json_fail('Parameter view tidak dikenal.', 400);
