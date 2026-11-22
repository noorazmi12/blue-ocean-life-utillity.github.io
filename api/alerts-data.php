<?php
/**
 * BOLU — API Data Peringatan
 * GET:
 *   ?view=recent&limit=10                        -> peringatan terbuka + terbaru (lonceng)
 *   ?view=list&from&to&severity&status&param     -> daftar dengan filter
 * POST (sesi + CSRF):
 *   ?action=resolve {id} -> tandai ditangani (admin/operator)
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_login_api();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    csrf_check();
    $action = $_GET['action'] ?? '';
    $in = json_input();
    $id = (int)($in['id'] ?? 0);
    if ($action !== 'resolve' || $id <= 0) {
        json_fail('Aksi tidak valid.', 400);
    }
    if (!can_manage()) {
        json_fail('Hanya admin/operator yang dapat menutup peringatan.', 403);
    }
    $cu = current_user();
    $st = db()->prepare("UPDATE alerts SET status='resolved', resolved_by=?, resolved_at=NOW() WHERE id=? AND status='open'");
    $st->execute([$cu['id'], $id]);
    json_out(['ok' => (bool)$st->rowCount()]);
}

$view = $_GET['view'] ?? 'recent';

if ($view === 'recent') {
    $limit = max(1, min(30, (int)($_GET['limit'] ?? 8)));
    $rows = db()->query(
        "SELECT a.id, a.parameter, a.severity, a.title, a.occurred_at, a.status, d.device_name
         FROM alerts a LEFT JOIN devices d ON d.id = a.device_id
         ORDER BY (a.status='open') DESC, a.occurred_at DESC LIMIT {$limit}"
    )->fetchAll();
    $open = (int)db()->query("SELECT COUNT(*) c FROM alerts WHERE status='open'")->fetch()['c'];
    json_out(['ok' => true, 'data' => $rows, 'open_count' => $open]);
}

if ($view === 'list') {
    $where = ['1=1'];
    $args = [];
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'a.occurred_at >= ?'; $args[] = $from . ' 00:00:00'; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where[] = 'a.occurred_at <= ?'; $args[] = $to . ' 23:59:59'; }
    if (in_array($_GET['severity'] ?? '', ['info', 'warning', 'critical'], true)) { $where[] = 'a.severity = ?'; $args[] = $_GET['severity']; }
    if (in_array($_GET['status'] ?? '', ['open', 'resolved'], true)) { $where[] = 'a.status = ?'; $args[] = $_GET['status']; }
    $param = $_GET['param'] ?? '';
    if ($param !== '' && preg_match('/^[a-z0-9_]{1,40}$/', $param)) { $where[] = 'a.parameter = ?'; $args[] = $param; }

    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $sql = "SELECT a.*, d.device_name FROM alerts a LEFT JOIN devices d ON d.id = a.device_id
            WHERE " . implode(' AND ', $where) . " ORDER BY a.occurred_at DESC LIMIT {$limit}";
    $st = db()->prepare($sql);
    $st->execute($args);
    json_out(['ok' => true, 'data' => $st->fetchAll()]);
}

json_fail('Parameter view tidak dikenal.', 400);
