<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can_manage()) {
        flash_set('error', 'Hak akses tidak mencukupi.');
        header('Location: perangkat.php');
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'toggle' && is_admin()) {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE devices SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?')->execute([$id]);
        flash_set('success', 'Status aktif perangkat #' . $id . ' diperbarui.');
    } elseif ($action === 'reset_token' && is_admin()) {
        $id = (int)($_POST['id'] ?? 0);
        $plain = bin2hex(random_bytes(24));
        $pdo->prepare('UPDATE devices SET api_token = ? WHERE id = ?')->execute([hash('sha256', $plain), $id]);
        flash_set('success', 'Token API baru untuk perangkat #' . $id . ': ' . $plain);
    }
    header('Location: perangkat.php');
    exit;
}

$PAGE_ID = 'perangkat';
$PAGE_TITLE = 'Manajemen Perangkat';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Manajemen Perangkat', null]];
$PAGE_SCRIPTS = ['perangkat.js'];

$devices = $pdo->query(
    'SELECT d.*, l.latitude, l.longitude, l.location_name FROM devices d LEFT JOIN device_locations l ON l.device_id = d.id ORDER BY d.id ASC'
)->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-microchip"></i> Manajemen Perangkat</h1>
        <p class="page-desc">Kelola sensor gelombang, sensor kualitas air, unit OWC, dan kamera FishCount.</p>
    </div>
</div>

<div class="card">
    <div class="card-header"><div><h3 class="card-title">Daftar Perangkat (<?= count($devices) ?>)</h3></div></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Kode / Nama</th><th>Jenis</th><th>Lokasi</th><th>Status</th><th>Komunikasi Terakhir</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach ($devices as $d): ?>
        <tr>
            <td class="num">#<?= (int)$d['id'] ?></td>
            <td><strong><?= e($d['device_code']) ?></strong><br><span class="source-note"><?= e($d['device_name']) ?></span></td>
            <td><span class="badge badge-outline"><?= e(str_replace('_', ' ', (string)$d['device_type'])) ?></span></td>
            <td><?= e($d['location_name'] ?: '—') ?></td>
            <td><?= device_status_badge((string)$d['status']) ?></td>
            <td><?= e(time_ago($d['last_communication'])) ?></td>
            <td>
                <?php if (is_admin()): ?>
                <form method="post" style="display:inline">
                    <?= csrf_field() ?><input type="hidden" name="action" value="reset_token"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                    <button class="btn btn-ghost btn-sm" title="Reset token API"><i class="fa-solid fa-key"></i></button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
