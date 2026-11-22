<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login_api();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_GET['action'] ?? '');
    $raw = file_get_contents('php://input');
    $body = json_decode((string)$raw, true);
    $id = (int)($body['id'] ?? $_POST['id'] ?? 0);

    if ($action !== 'resolve' || $id <= 0) {
        json_response(false, 'Aksi tidak valid.', 400);
    }
    if (!can_manage()) {
        json_response(false, 'Hanya admin/operator yang dapat menutup peringatan.', 403);
    }

    $cu = current_user();
    $now = date('Y-m-d H:i:s');
    $stmt = db()->prepare("UPDATE alerts SET status = 'resolved', resolved_by = ?, resolved_at = ? WHERE id = ? AND status = 'open'");
    $stmt->execute([(int)$cu['id'], $now, $id]);
    json_raw(['ok' => $stmt->rowCount() > 0]);
}

$view = (string)($_GET['view'] ?? 'recent');

if ($view === 'recent') {
    $limit = max(1, min(30, (int)($_GET['limit'] ?? 8) ?: 8));
    $rows = db()->query(
        "SELECT a.id, a.parameter, a.severity, a.title, a.occurred_at, a.status, d.device_name
         FROM alerts a
         LEFT JOIN devices d ON d.id = a.device_id
         ORDER BY (CASE WHEN a.status = 'open' THEN 1 ELSE 0 END) DESC, a.occurred_at DESC
         LIMIT {$limit}"
    )->fetchAll();
    $openCount = (int)db()->query("SELECT COUNT(*) FROM alerts WHERE status = 'open'")->fetchColumn();
    json_raw(['ok' => true, 'data' => $rows, 'open_count' => $openCount]);
}

if ($view === 'list') {
    $from = (string)($_GET['from'] ?? '');
    $to = (string)($_GET['to'] ?? '');
    $severity = (string)($_GET['severity'] ?? '');
    $status = (string)($_GET['status'] ?? '');
    $param = (string)($_GET['param'] ?? '');
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50) ?: 50));

    $where = ['1=1'];
    $bind = [];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $where[] = 'a.occurred_at >= ?';
        $bind[] = $from . ' 00:00:00';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $where[] = 'a.occurred_at <= ?';
        $bind[] = $to . ' 23:59:59';
    }
    if (in_array($severity, ['info', 'warning', 'critical'], true)) {
        $where[] = 'a.severity = ?';
        $bind[] = $severity;
    }
    if (in_array($status, ['open', 'resolved'], true)) {
        $where[] = 'a.status = ?';
        $bind[] = $status;
    }
    if (preg_match('/^[a-z0-9_]{1,40}$/', $param)) {
        $where[] = 'a.parameter = ?';
        $bind[] = $param;
    }

    $stmt = db()->prepare(
        'SELECT a.*, d.device_name
         FROM alerts a
         LEFT JOIN devices d ON d.id = a.device_id
         WHERE ' . implode(' AND ', $where) . "
         ORDER BY a.occurred_at DESC LIMIT {$limit}"
    );
    $stmt->execute($bind);
    json_response(true, $stmt->fetchAll());
}

json_response(false, 'Parameter view tidak dikenal.', 400);
