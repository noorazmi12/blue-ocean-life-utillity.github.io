<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if (!in_array($action, ['resolve', 'reopen'], true) || $id <= 0) {
        flash_set('error', 'Aksi tidak valid.');
    } elseif (!can_manage()) {
        flash_set('error', 'Hanya admin/operator yang dapat mengubah status peringatan.');
    } else {
        $cu = current_user();
        $stChk = db()->prepare('SELECT id, status FROM alerts WHERE id = ? LIMIT 1');
        $stChk->execute([$id]);
        $al = $stChk->fetch();
        if ($action === 'resolve') {
            if ($al && $al['status'] === 'open') {
                db()->prepare("UPDATE alerts SET status = 'resolved', resolved_by = ?, resolved_at = ? WHERE id = ?")
                    ->execute([(int)$cu['id'], date('Y-m-d H:i:s'), $id]);
                flash_set('success', "Peringatan #{$id} ditandai sebagai ditangani.");
            } else {
                flash_set('error', 'Peringatan tidak ditemukan atau sudah ditangani.');
            }
        } else {
            if ($al && $al['status'] === 'resolved') {
                db()->prepare("UPDATE alerts SET status = 'open', resolved_by = NULL, resolved_at = NULL WHERE id = ?")
                    ->execute([$id]);
                flash_set('success', "Peringatan #{$id} dibuka kembali.");
            } else {
                flash_set('error', 'Peringatan tidak ditemukan atau masih terbuka.');
            }
        }
    }
    $params = [];
    foreach ($_GET as $k => $v) {
        if ($k !== 'id' && $v !== '' && $v !== null) {
            $params[$k] = $v;
        }
    }
    $qs = http_build_query($params);
    header('Location: peringatan.php' . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

$severity = isset($_GET['severity']) && in_array($_GET['severity'], ['info', 'warning', 'critical'], true) ? (string)$_GET['severity'] : '';
$status = isset($_GET['status']) && in_array($_GET['status'], ['open', 'resolved'], true) ? (string)$_GET['status'] : '';
$paramRaw = isset($_GET['param']) ? (string)$_GET['param'] : '';
$param = preg_match('/^[a-z0-9_]{1,40}$/', $paramRaw) ? $paramRaw : '';

$defFrom = date('Y-m-d', time() - 7 * 86400);
$defTo = date('Y-m-d');
[$from, $to] = date_range_from_query($_GET, $defFrom, $defTo);
$start = $from . ' 00:00:00';
$end = $to . ' 23:59:59';

$where = ['a.occurred_at BETWEEN ? AND ?'];
$args = [$start, $end];
if ($severity !== '') {
    $where[] = 'a.severity = ?';
    $args[] = $severity;
}
if ($status !== '') {
    $where[] = 'a.status = ?';
    $args[] = $status;
}
if ($param !== '') {
    $where[] = 'a.parameter = ?';
    $args[] = $param;
}
$whereSql = implode(' AND ', $where);

$cntStmt = db()->prepare("SELECT COUNT(*) FROM alerts a WHERE {$whereSql}");
$cntStmt->execute($args);
$total = (int)$cntStmt->fetchColumn();

$per = 12;
$page = max(1, (int)($_GET['page'] ?? 1));
$pages = max(1, (int)ceil($total / $per));
if ($page > $pages) {
    $page = $pages;
}
$offset = ($page - 1) * $per;

$rowsStmt = db()->prepare(
    "SELECT a.*, d.device_name, d.device_code
     FROM alerts a
     LEFT JOIN devices d ON d.id = a.device_id
     WHERE {$whereSql}
     ORDER BY CASE WHEN a.status = 'open' THEN 1 ELSE 0 END DESC, a.occurred_at DESC, a.id DESC
     LIMIT {$per} OFFSET {$offset}"
);
$rowsStmt->execute($args);
$rows = $rowsStmt->fetchAll();

$detail = null;
if (isset($_GET['id'])) {
    $did = (int)$_GET['id'];
    if ($did > 0) {
        $detStmt = db()->prepare(
            'SELECT a.*, d.device_name, d.device_code, u.username AS resolver
             FROM alerts a
             LEFT JOIN devices d ON d.id = a.device_id
             LEFT JOIN users u ON u.id = a.resolved_by
             WHERE a.id = ? LIMIT 1'
        );
        $detStmt->execute([$did]);
        $detail = $detStmt->fetch() ?: null;
    }
}

$sumRow = db()->query(
    "SELECT
        SUM(CASE WHEN severity = 'critical' AND status = 'open' THEN 1 ELSE 0 END) AS crit,
        SUM(CASE WHEN severity = 'warning' AND status = 'open' THEN 1 ELSE 0 END) AS warn,
        SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_cnt
     FROM alerts"
)->fetch();
$sum = [
    'crit' => (int)($sumRow['crit'] ?? 0),
    'warn' => (int)($sumRow['warn'] ?? 0),
    'open' => (int)($sumRow['open_cnt'] ?? 0),
];

$PAGE_ID = 'peringatan';
$PAGE_TITLE = 'Peringatan';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Peringatan', null]];
$PAGE_SCRIPTS = [];

require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-bell"></i> Peringatan</h1>
        <p class="page-desc">Semua peringatan otomatis saat parameter melewati ambang, perangkat offline,
            atau kejadian sistem lainnya. Atur ambang sesuai spesies di <a href="pengaturan.php">Pengaturan</a>.</p>
    </div>
    <div class="page-actions">
        <span class="badge badge-red"><i class="fa-solid fa-circle-exclamation"></i>Kritis: <?= $sum['crit'] ?></span>
        <span class="badge badge-yellow"><i class="fa-solid fa-triangle-exclamation"></i>Peringatan: <?= $sum['warn'] ?></span>
        <span class="badge badge-gray"><i class="fa-solid fa-inbox"></i>Terbuka: <?= $sum['open'] ?></span>
    </div>
</div>

<?php if ($detail): ?>
<div class="card" id="detailCard">
    <div class="card-header">
        <div><h3 class="card-title"><i class="fa-solid fa-circle-info"></i> Detail Peringatan #<?= (int)$detail['id'] ?></h3></div>
        <a class="btn btn-ghost btn-sm" href="peringatan.php"><i class="fa-solid fa-xmark"></i> Tutup</a>
    </div>
    <div class="card-body">
        <div class="grid-2">
            <dl class="kv">
                <dt>Judul</dt><dd><?= e($detail['title']) ?></dd>
                <dt>Prioritas</dt><dd><?= severity_badge($detail['severity']) ?></dd>
                <dt>Perangkat</dt><dd><?= e($detail['device_name'] ?: '—') ?> (<?= e($detail['device_code'] ?: '—') ?>)</dd>
                <dt>Parameter</dt><dd><?= e(param_label((string)$detail['parameter'])) ?></dd>
                <dt>Waktu kejadian</dt><dd><?= e(fmt_dt($detail['occurred_at'])) ?></dd>
                <dt>Status</dt><dd><?= $detail['status'] === 'open' ? '<span class="badge badge-red">Belum Ditangani</span>' : '<span class="badge badge-green">Selesai</span>' ?></dd>
                <?php if ($detail['status'] === 'resolved'): ?>
                <dt>Ditangani oleh</dt><dd><?= e($detail['resolver'] ?: '—') ?></dd>
                <dt>Waktu ditangani</dt><dd><?= e(fmt_dt($detail['resolved_at'])) ?></dd>
                <?php endif; ?>
            </dl>
            <div>
                <p style="margin-bottom:8px"><strong>Pesan:</strong></p>
                <div class="callout callout-<?= $detail['severity'] === 'critical' ? 'danger' : ($detail['severity'] === 'warning' ? 'warn' : 'info') ?>">
                    <i class="fa-solid <?= $detail['severity'] === 'critical' ? 'fa-circle-exclamation' : ($detail['severity'] === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-info') ?>"></i>
                    <div><?= e($detail['message']) ?></div>
                </div>
                <dl class="kv">
                    <dt>Nilai aktual</dt><dd><?= $detail['actual_value'] !== null ? fmt_num($detail['actual_value'], 3) : '—' ?></dd>
                    <dt>Nilai ambang</dt><dd><?= $detail['threshold_value'] !== null ? fmt_num($detail['threshold_value'], 3) : '—' ?></dd>
                </dl>
                <?php if (can_manage()): ?>
                <form method="post" style="margin-top:12px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
                    <?php if ($detail['status'] === 'open'): ?>
                        <button class="btn btn-success btn-sm" name="action" value="resolve"><i class="fa-solid fa-check"></i> Tandai Ditangani</button>
                    <?php else: ?>
                        <button class="btn btn-outline btn-sm" name="action" value="reopen"><i class="fa-solid fa-rotate-left"></i> Buka Kembali</button>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Filter -->
<div class="card no-print">
    <div class="card-body">
        <form method="get" action="peringatan.php" class="input-group">
            <input type="date" class="form-control" name="from" value="<?= e($from) ?>">
            <span>—</span>
            <input type="date" class="form-control" name="to" value="<?= e($to) ?>">
            <select class="form-select" name="severity">
                <option value="">Semua Prioritas</option>
                <option value="info" <?= $severity === 'info' ? 'selected' : '' ?>>Informasi</option>
                <option value="warning" <?= $severity === 'warning' ? 'selected' : '' ?>>Peringatan</option>
                <option value="critical" <?= $severity === 'critical' ? 'selected' : '' ?>>Kritis</option>
            </select>
            <select class="form-select" name="status">
                <option value="">Semua Status</option>
                <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Belum Ditangani</option>
                <option value="resolved" <?= $status === 'resolved' ? 'selected' : '' ?>>Selesai</option>
            </select>
            <select class="form-select" name="param">
                <option value="">Semua Parameter</option>
                <?php foreach (PARAM_LABELS as $k => $lbl): ?>
                    <option value="<?= e($k) ?>" <?= $param === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-filter"></i> Terapkan</button>
            <a class="btn btn-ghost btn-sm" href="peringatan.php"><i class="fa-solid fa-rotate-left"></i> Reset</a>
        </form>
    </div>
</div>

<!-- Daftar peringatan -->
<div class="card">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-list"></i> Daftar Peringatan</h3>
            <p class="card-sub">Total <?= fmt_num($total, 0) ?> peringatan pada filter aktif</p>
        </div>
    </div>
    <div class="alert-list">
        <?php if (!$rows): ?>
            <div class="empty-state"><i class="fa-regular fa-bell-slash"></i><p>Tidak ada peringatan sesuai filter.</p></div>
        <?php endif; ?>
        <?php foreach ($rows as $al): ?>
        <a class="alert-item <?= $al['status'] === 'resolved' ? 'resolved' : '' ?>" href="peringatan.php?id=<?= (int)$al['id'] ?>">
            <span class="a-icon <?= e($al['severity']) ?>">
                <i class="fa-solid <?= $al['severity'] === 'critical' ? 'fa-circle-exclamation' : ($al['severity'] === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-info') ?>"></i>
            </span>
            <span class="a-body">
                <span class="a-title">
                    <?= e($al['title']) ?>
                    <?= $al['status'] === 'open' ? '<span class="badge badge-gray">Belum ditangani</span>' : '<span class="badge badge-green"><i class="fa-solid fa-check"></i>Selesai</span>' ?>
                    <?= demo_badge($al['is_simulated']) ?>
                </span>
                <span class="a-msg"><?= e($al['message']) ?></span>
                <span class="a-meta">
                    <span><i class="fa-solid fa-microchip"></i> <?= e($al['device_name'] ?: '—') ?></span>
                    <span><i class="fa-solid fa-tag"></i> <?= e(param_label((string)$al['parameter'])) ?></span>
                    <span><i class="fa-regular fa-clock"></i> <?= e(fmt_dt($al['occurred_at'])) ?></span>
                    <span><i class="fa-solid fa-arrow-up-short-wide"></i> Aktual <?= $al['actual_value'] !== null ? fmt_num($al['actual_value'], 2) : '—' ?> / Ambang <?= $al['threshold_value'] !== null ? fmt_num($al['threshold_value'], 2) : '—' ?></span>
                </span>
            </span>
            <?php if (can_manage() && $al['status'] === 'open'): ?>
            <form method="post" class="td-actions" style="align-self:center">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$al['id'] ?>">
                <button class="btn btn-outline btn-sm" name="action" value="resolve" onclick="event.stopPropagation()"
                        title="Tandai peringatan ini sebagai ditangani"><i class="fa-solid fa-check"></i> Tangani</button>
            </form>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php if ($pages > 1): ?>
    <div class="pagination">
        <span class="page-info">Halaman <?= $page ?> dari <?= $pages ?></span>
        <?php if ($page > 1): ?><a href="<?= e(qs_merge(['page' => $page - 1])) ?>"><i class="fa-solid fa-angle-left"></i></a><?php endif; ?>
        <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>
            <?php if ($p === $page): ?><span class="page-cur"><?= $p ?></span>
            <?php else: ?><a href="<?= e(qs_merge(['page' => $p])) ?>"><?= $p ?></a><?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $pages): ?><a href="<?= e(qs_merge(['page' => $page + 1])) ?>"><i class="fa-solid fa-angle-right"></i></a><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
