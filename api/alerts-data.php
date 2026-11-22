<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login_api();

$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can_manage()) {
        json_response(false, 'Hak akses tidak mencukupi.', 403);
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE alerts SET status='resolved', resolved_by=?, resolved_at=NOW() WHERE id=?");
        $stmt->execute([(int)(current_user()['id'] ?? 0), $id]);
        json_response(true, ['resolved_id' => $id]);
    }
    json_response(false, 'ID peringatan tidak valid.', 400);
}

$limit = max(1, min(50, (int)($_GET['limit'] ?? 8)));
$stmt = $pdo->prepare(
    'SELECT a.id, a.parameter, a.severity, a.title, a.occurred_at, a.status, d.device_name
     FROM alerts a LEFT JOIN devices d ON d.id = a.device_id
     ORDER BY (a.status = "open") DESC, a.occurred_at DESC LIMIT ?'
);
$stmt->execute([$limit]);
json_response(true, $stmt->fetchAll());
