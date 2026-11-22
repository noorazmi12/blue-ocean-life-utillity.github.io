<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can_manage()) {
        flash_set('error', 'Hak akses tidak mencukupi.');
        header('Location: peringatan.php');
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'resolve' && $id > 0) {
        $pdo->prepare("UPDATE alerts SET status='resolved', resolved_by=?, resolved_at=NOW() WHERE id=?")
            ->execute([(int)(current_user()['id'] ?? 0), $id]);
        flash_set('success', 'Peringatan #' . $id . ' ditandai selesai.');
    }
    header('Location: peringatan.php');
    exit;
}

$PAGE_ID = 'peringatan';
$PAGE_TITLE = 'Peringatan Dini';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Peringatan', null]];

$alerts = $pdo->query('SELECT a.*, d.device_name FROM alerts a LEFT JOIN devices d ON d.id = a.device_id ORDER BY (a.status="open") DESC, a.occurred_at DESC')->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-bell"></i> Daftar Peringatan Sistem</h1>
        <p class="page-desc">Notifikasi otomatis saat nilai sensor melewati ambang normal.</p>
    </div>
</div>

<div class="card">
    <div class="card-header"><div><h3 class="card-title">Semua Peringatan (<?= count($alerts) ?>)</h3></div></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Waktu</th><th>Perangkat</th><th>Parameter</th><th>Keparahan</th><th>Pesan</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach ($alerts as $a): ?>
        <tr>
            <td class="num">#<?= (int)$a['id'] ?></td>
            <td><?= e(fmt_dt($a['occurred_at'])) ?></td>
            <td><?= e($a['device_name'] ?: '—') ?></td>
            <td><?= e(param_label($a['parameter'])) ?></td>
            <td><?= severity_badge($a['severity']) ?></td>
            <td><strong><?= e($a['title']) ?></strong><br><span class="source-note"><?= e($a['message']) ?></span></td>
            <td><span class="badge <?= $a['status'] === 'open' ? 'badge-yellow' : 'badge-green' ?>"><?= $a['status'] === 'open' ? 'Terbuka' : 'Selesai' ?></span></td>
            <td>
                <?php if ($a['status'] === 'open' && can_manage()): ?>
                <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="resolve">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <button class="btn btn-primary btn-sm">Selesaikan</button>
                </form>
                <?php else: ?>—<?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
