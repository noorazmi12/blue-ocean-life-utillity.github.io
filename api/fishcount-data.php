<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fishcount-ai.php';
require_login_api();

$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can_manage()) {
        json_response(false, 'Hak akses tidak mencukupi.', 403);
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'validate') {
        $id = (int)($_POST['id'] ?? 0);
        $cnt = (int)($_POST['manual_count'] ?? -1);
        $notes = trim((string)($_POST['notes'] ?? ''));
        if ($id <= 0 || $cnt < 0) {
            json_response(false, 'Input validasi tidak valid.', 400);
        }
        $stmt = $pdo->prepare("UPDATE fishcount_results SET is_manual=1, manual_count=?, validated_by=?, validated_at=NOW(), notes=? WHERE id=?");
        $stmt->execute([$cnt, (int)(current_user()['id'] ?? 0), $notes, $id]);
        json_response(true, ['id' => $id, 'manual_count' => $cnt]);
    }
    json_response(false, 'Aksi tidak dikenal.', 400);
}

$view = (string)($_GET['view'] ?? 'recent');
if ($view === 'stats') {
    $days = max(1, min(60, (int)($_GET['days'] ?? 7)));
    $stmt = $pdo->prepare('SELECT id, detected_count, manual_count, avg_length_cm, biomass_kg, confidence_pct, created_at FROM fishcount_results WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY created_at ASC');
    $stmt->execute([$days]);
    json_response(true, ['rows' => $stmt->fetchAll()]);
}

$limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
$stmt = $pdo->prepare('SELECT * FROM fishcount_results ORDER BY created_at DESC, id DESC LIMIT ?');
$stmt->execute([$limit]);
json_response(true, $stmt->fetchAll());
