<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fishcount-ai.php';
require_login_api();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    csrf_check();
    $action = (string)($_GET['action'] ?? '');
    $rawBody = file_get_contents('php://input');
    $body = json_decode($rawBody ?: '', true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    if ($action === 'snapshot') {
        $dataUrl = (string)($body['image'] ?? '');
        if (!str_starts_with($dataUrl, 'data:image/jpeg;base64,')) {
            json_raw(['ok' => false, 'error' => 'Format gambar tidak didukung (hanya JPEG dari kamera).'], 400);
        }
        $b64 = substr($dataUrl, strlen('data:image/jpeg;base64,'));
        $bin = base64_decode($b64, true);
        if ($bin === false || strlen($bin) === 0 || strlen($bin) > UPLOAD_MAX_BYTES) {
            json_raw(['ok' => false, 'error' => 'Gambar kosong atau melebihi batas ukuran.'], 400);
        }
        if (strlen($bin) < 2 || ord($bin[0]) !== 0xFF || ord($bin[1]) !== 0xD8) {
            json_raw(['ok' => false, 'error' => 'Berkas bukan gambar JPEG yang valid.'], 400);
        }

        if (!is_dir(FISHCOUNT_UPLOAD_DIR)) {
            @mkdir(FISHCOUNT_UPLOAD_DIR, 0775, true);
        }
        $name = 'snap_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
        $absFile = FISHCOUNT_UPLOAD_DIR . '/' . $name;
        if (file_put_contents($absFile, $bin) === false) {
            json_raw(['ok' => false, 'error' => 'Gagal menyimpan file gambar ke server.'], 500);
        }

        $devStmt = db()->prepare("SELECT id FROM devices WHERE device_type = 'camera' AND is_active = 1 ORDER BY id ASC LIMIT 1");
        $devStmt->execute();
        $devId = $devStmt->fetchColumn();
        $devId = $devId !== false ? (int)$devId : null;

        $notesRaw = trim((string)($body['notes'] ?? ''));
        $notes = $notesRaw !== '' ? str_trim_width($notesRaw, 255) : null;
        $statusMsg = fishcount_ai_enabled()
            ? 'Snapshot tersimpan, memproses analisis AI…'
            : 'Snapshot kamera — layanan AI belum dikonfigurasi.';
        $now = date('Y-m-d H:i:s');

        $ins = db()->prepare(
            'INSERT INTO fishcount_results (device_id, image_path, media_type, processing_status, status_message, is_simulated, is_manual, notes, created_at)
             VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?)'
        );
        $ins->execute([
            $devId,
            'uploads/fishcount/' . $name,
            'image',
            'no_ai',
            $statusMsg,
            $notes,
            $now,
        ]);
        $newId = (int)db()->lastInsertId();

        $aiOut = null;
        if (fishcount_ai_enabled()) {
            $ai = fishcount_ai_analyze($absFile, 'image/jpeg');
            if ($ai !== null) {
                $cnt = isset($ai['detected_count']) && is_numeric($ai['detected_count']) ? (int)$ai['detected_count'] : null;
                $len = isset($ai['avg_length_cm']) && is_numeric($ai['avg_length_cm']) ? (float)$ai['avg_length_cm'] : null;
                $bio = isset($ai['biomass_kg']) && is_numeric($ai['biomass_kg']) ? (float)$ai['biomass_kg'] : null;
                $conf = isset($ai['confidence_pct']) && is_numeric($ai['confidence_pct']) ? (float)$ai['confidence_pct'] : null;
                $msg = isset($ai['message']) ? str_trim_width((string)$ai['message'], 255) : 'Analisis AI selesai.';
                db()->prepare(
                    'UPDATE fishcount_results SET detected_count=?, avg_length_cm=?, biomass_kg=?, confidence_pct=?, processing_status=?, status_message=? WHERE id=?'
                )->execute([$cnt, $len, $bio, $conf, 'done', $msg, $newId]);
                $aiOut = $ai;
            } else {
                db()->prepare('UPDATE fishcount_results SET processing_status=?, status_message=? WHERE id=?')
                    ->execute(['failed', 'Analisis AI gagal atau tidak merespons.', $newId]);
            }
        }

        json_raw(['ok' => true, 'id' => $newId, 'image' => 'uploads/fishcount/' . $name, 'ai' => $aiOut]);
    }

    if ($action === 'validate') {
        if (!can_manage()) {
            json_raw(['ok' => false, 'error' => 'Hanya admin/operator yang dapat memvalidasi.'], 403);
        }
        $id = (int)($body['id'] ?? 0);
        $countRaw = $body['manual_count'] ?? null;
        if ($id <= 0 || $countRaw === null || !is_numeric($countRaw) || (string)(int)$countRaw !== trim((string)$countRaw)) {
            json_raw(['ok' => false, 'error' => 'Data validasi tidak valid.'], 400);
        }
        $count = (int)$countRaw;
        if ($count < 0 || $count > 100000) {
            json_raw(['ok' => false, 'error' => 'Data validasi tidak valid.'], 400);
        }

        $chk = db()->prepare('SELECT id FROM fishcount_results WHERE id = ? LIMIT 1');
        $chk->execute([$id]);
        if (!$chk->fetch()) {
            json_raw(['ok' => false]);
        }

        $cu = current_user();
        $notesRaw = trim((string)($body['notes'] ?? ''));
        $notes = $notesRaw !== '' ? str_trim_width($notesRaw, 255) : null;
        db()->prepare(
            'UPDATE fishcount_results SET manual_count = ?, is_manual = 1, validated_by = ?, validated_at = ?, notes = ? WHERE id = ?'
        )->execute([$count, (int)$cu['id'], date('Y-m-d H:i:s'), $notes, $id]);

        json_raw(['ok' => true]);
    }

    json_raw(['ok' => false, 'error' => 'Aksi tidak dikenal.'], 400);
}

$view = (string)($_GET['view'] ?? 'recent');

if ($view === 'recent') {
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
    $stmt = db()->prepare(
        "SELECT f.*, d.device_name
         FROM fishcount_results f
         LEFT JOIN devices d ON d.id = f.device_id
         ORDER BY f.created_at DESC
         LIMIT {$limit}"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['device_id'] = $r['device_id'] !== null ? (int)$r['device_id'] : null;
        $r['detected_count'] = $r['detected_count'] !== null ? (int)$r['detected_count'] : null;
        $r['avg_length_cm'] = $r['avg_length_cm'] !== null ? (float)$r['avg_length_cm'] : null;
        $r['biomass_kg'] = $r['biomass_kg'] !== null ? (float)$r['biomass_kg'] : null;
        $r['confidence_pct'] = $r['confidence_pct'] !== null ? (float)$r['confidence_pct'] : null;
        $r['is_simulated'] = (int)$r['is_simulated'];
        $r['is_manual'] = (int)$r['is_manual'];
        $r['manual_count'] = $r['manual_count'] !== null ? (int)$r['manual_count'] : null;
    }
    unset($r);
    json_raw(['ok' => true, 'data' => $rows]);
}

if ($view === 'stats') {
    $days = max(1, min(90, (int)($_GET['days'] ?? 7)));
    $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);

    $stmt = db()->prepare(
        'SELECT created_at, detected_count, manual_count
         FROM fishcount_results
         WHERE created_at >= ?
         ORDER BY created_at ASC'
    );
    $stmt->execute([$cutoff]);
    $byDay = [];
    foreach ($stmt->fetchAll() as $r) {
        $d = substr((string)$r['created_at'], 0, 10);
        $val = $r['detected_count'] !== null ? (int)$r['detected_count'] : ($r['manual_count'] !== null ? (int)$r['manual_count'] : null);
        if ($val !== null) {
            if (!array_key_exists($d, $byDay) || $byDay[$d] === null || $val > $byDay[$d]) {
                $byDay[$d] = $val;
            }
        } elseif (!array_key_exists($d, $byDay)) {
            $byDay[$d] = null;
        }
    }
    ksort($byDay);
    $series = [];
    foreach ($byDay as $t => $c) {
        $series[] = ['t' => $t, 'c' => $c];
    }

    $cutoff30 = date('Y-m-d H:i:s', time() - 30 * 86400);
    $stmtSizes = db()->prepare(
        'SELECT avg_length_cm FROM fishcount_results WHERE avg_length_cm IS NOT NULL AND created_at >= ?'
    );
    $stmtSizes->execute([$cutoff30]);
    $buckets = ['10-14' => 0, '15-19' => 0, '20-24' => 0, '25-29' => 0, '30+' => 0];
    foreach ($stmtSizes->fetchAll() as $r) {
        $l = (float)$r['avg_length_cm'];
        if ($l < 15) {
            $buckets['10-14']++;
        } elseif ($l < 20) {
            $buckets['15-19']++;
        } elseif ($l < 25) {
            $buckets['20-24']++;
        } elseif ($l < 30) {
            $buckets['25-29']++;
        } else {
            $buckets['30+']++;
        }
    }
    $sizes = [];
    foreach ($buckets as $bucket => $n) {
        $sizes[] = ['bucket' => $bucket, 'n' => $n];
    }

    json_raw(['ok' => true, 'data' => ['series' => $series, 'sizes' => $sizes]]);
}

json_raw(['ok' => false, 'error' => 'Parameter view tidak dikenal.'], 400);
