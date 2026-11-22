<?php
/**
 * BOLU — Peringatan
 * Daftar peringatan dengan filter tanggal/parameter/prioritas/status,
 * detail nilai aktual vs ambang, dan aksi tandai selesai (CSRF).
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$PAGE_ID    = 'peringatan';
$PAGE_TITLE = 'Peringatan';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Peringatan', null]];
$PAGE_SCRIPTS = [];

/* ---------- POST: tandai ditangani / buka kembali ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if (!in_array($action, ['resolve', 'reopen'], true) || $id <= 0) {
        flash_set('error', 'Aksi tidak valid.');
    } elseif (!can_manage()) {
        flash_set('error', 'Hanya admin/operator yang dapat mengubah status peringatan.');
    } else {
        $cu = current_user();
        if ($action === 'resolve') {
            $st = db()->prepare("UPDATE alerts SET status='resolved', resolved_by=?, resolved_at=NOW() WHERE id=? AND status='open'");
            $st->execute([$cu['id'], $id]);
            flash_set($st->rowCount() ? 'success' : 'error', $st->rowCount()
                ? 'Peringatan #' . $id . ' ditandai sebagai ditangani.'
                : 'Peringatan tidak ditemukan atau sudah ditangani.');
        } else {
            $st = db()->prepare("UPDATE alerts SET status='open', resolved_by=NULL, resolved_at=NULL WHERE id=? AND status='resolved'");
            $st->execute([$id]);
            flash_set($st->rowCount() ? 'success' : 'error', $st->rowCount()
                ? 'Peringatan #' . $id . ' dibuka kembali.'
                : 'Peringatan tidak ditemukan atau masih terbuka.');
        }
    }
    header('Location: peringatan.php?' . http_build_query(array_filter($_GET, fn($k) => $k !== 'id', ARRAY_FILTER_USE_KEY)));
    exit;
}

/* ---------- Filter ---------- */
$severity = $_GET['severity'] ?? '';
$status   = $_GET['status'] ?? '';
$param    = $_GET['param'] ?? '';
[$from, $to] = date_range_from_get(date('Y-m-d', strtotime('-7 days')), date('Y-m-d'));

$where = ['a.occurred_at BETWEEN :from AND :to'];
$args = [':from' => $from . ' 00:00:00', ':to' => $to . ' 23:59:59'];
if (in_array($severity, ['info', 'warning', 'critical'], true)) { $where[] = 'a.severity = :sev'; $args[':sev'] = $severity; }
if (in_array($status, ['open', 'resolved'], true))                            { $where[] = 'a.status = :st';  $args[':st'] = $status; }
if ($param !== '' && preg_match('/^[a-z0-9_]{1,40}$/', $param))              { $where[] = 'a.parameter = :p'; $args[':p'] = $param; }
$whereSql = implode(' AND ', $where);

/* Highlight detail dari ?id= */
$detail = null;
if (isset($_GET['id'])) {
    $st = db()->prepare("SELECT a.*, d.device_name, d.device_code, u.username AS resolver
                         FROM alerts a LEFT JOIN devices d ON d.id = a.device_id
                         LEFT JOIN users u ON u.id = a.resolved_by WHERE a.id = ?");
    $st->execute([(int)$_GET['id']]);
    $detail = $st->fetch() ?: null;
}

/* ---------- Paginasi ---------- */
$cnt = db()->prepare("SELECT COUNT(*) c FROM alerts a WHERE {$whereSql}");
$cnt->execute($args);
$total = (int)$cnt->fetch()['c'];
$page  = max(1, (int)($_GET['page'] ?? 1));
$per   = 12;
$pages = max(1, (int)ceil($total / $per));
$off   = ($page - 1) * $per;

$q = db()->prepare(
    "SELECT a.*, d.device_name, d.device_code FROM alerts a LEFT JOIN devices d ON d.id = a.device_id
     WHERE {$whereSql} ORDER BY (a.status='open') DESC, a.occurred_at DESC LIMIT {$per} OFFSET {$off}"
);
$q->execute($args);
$rows = $q->fetchAll();

/* Ringkasan */
$sum = db()->query(
    "SELECT SUM(severity='critical' AND status='open') AS crit,
            SUM(severity='warning'  AND status='open') AS warn,
            SUM(status='open') AS open
     FROM alerts"
)->fetch();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-bell"></i> Peringatan</h1>
        <p class="page-desc">Semua peringatan otomatis saat parameter melewati ambang, perangkat offline,
            atau kejadian sistem lainnya. Atur ambang sesuai spesies di <a href="pengaturan.php">Pengaturan</a>.</p>
    </div>
    <div class="page-actions">
        <span class="badge badge-red"><i class="fa-solid fa-circle-exclamation"></i>Kritis: <?= (int)($sum['crit'] ?? 0) ?></span>
        <span class="badge badge-yellow"><i class="fa-solid fa-triangle-exclamation"></i>Peringatan: <?= (int)($sum['warn'] ?? 0) ?></span>
        <span class="badge badge-gray"><i class="fa-solid fa-inbox"></i>Terbuka: <?= (int)($sum['open'] ?? 0) ?></span>
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
                <dt>Perangkat</dt><dd><?= e($detail['device_name'] ?? '—') ?> (<?= e($detail['device_code'] ?? '—') ?>)</dd>
                <dt>Parameter</dt><dd><?= e(param_label($detail['parameter'])) ?></dd>
                <dt>Waktu kejadian</dt><dd><?= e(fmt_dt($detail['occurred_at'])) ?></dd>
                <dt>Status</dt><dd><?= $detail['status'] === 'open' ? '<span class="badge badge-red">Belum Ditangani</span>' : '<span class="badge badge-green">Selesai</span>' ?></dd>
                <?php if ($detail['status'] === 'resolved'): ?>
                <dt>Ditangani oleh</dt><dd><?= e($detail['resolver'] ?? '—') ?></dd>
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
                <option value="info"     <?= $severity === 'info' ? 'selected' : '' ?>>Informasi</option>
                <option value="warning"  <?= $severity === 'warning' ? 'selected' : '' ?>>Peringatan</option>
                <option value="critical" <?= $severity === 'critical' ? 'selected' : '' ?>>Kritis</option>
            </select>
            <select class="form-select" name="status">
                <option value="">Semua Status</option>
                <option value="open"     <?= $status === 'open' ? 'selected' : '' ?>>Belum Ditangani</option>
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
            <p class="card-sub">Total <?= number_format($total, 0, ',', '.') ?> peringatan pada filter aktif</p>
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
                    <?= demo_badge((bool)$al['is_simulated']) ?>
                </span>
                <span class="a-msg"><?= e($al['message']) ?></span>
                <span class="a-meta">
                    <span><i class="fa-solid fa-microchip"></i> <?= e($al['device_name'] ?? '—') ?></span>
                    <span><i class="fa-solid fa-tag"></i> <?= e(param_label($al['parameter'])) ?></span>
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

<?php include __DIR__ . '/includes/footer.php'; ?>
