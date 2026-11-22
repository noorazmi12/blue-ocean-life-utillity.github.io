<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'update_system' && is_admin()) {
        foreach (['site_name', 'site_location', 'active_species', 'dashboard_refresh_seconds', 'data_stale_minutes'] as $k) {
            if (isset($_POST[$k])) {
                save_setting($k, trim((string)$_POST[$k]));
            }
        }
        flash_set('success', 'Pengaturan sistem berhasil disimpan.');
    }
    header('Location: pengaturan.php');
    exit;
}

$PAGE_ID = 'pengaturan';
$PAGE_TITLE = 'Pengaturan Sistem';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Pengaturan', null]];
$PAGE_SCRIPTS = ['pengaturan.js'];

$thresholds = $pdo->query('SELECT * FROM thresholds ORDER BY id ASC')->fetchAll();
$users = is_admin() ? $pdo->query('SELECT id, username, email, full_name, role, is_active, last_login FROM users ORDER BY id ASC')->fetchAll() : [];

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-gear"></i> Pengaturan &amp; Profil</h1>
        <p class="page-desc">Konfigurasi sistem BOLU, ambang batas parameter sensor, dan pengelolaan pengguna.</p>
    </div>
</div>

<?php if (is_admin()): ?>
<div class="card">
    <div class="card-header"><div><h3 class="card-title">Pengaturan Umum</h3></div></div>
    <div class="card-body">
        <form method="post" class="grid-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_system">
            <div class="form-group"><label class="form-label">Lokasi KJA</label><input class="form-control" name="site_location" value="<?= e(setting('site_location', '')) ?>"></div>
            <div class="form-group"><label class="form-label">Spesies Aktif</label><input class="form-control" name="active_species" value="<?= e(setting('active_species', 'Umum')) ?>"></div>
            <div class="form-group"><label class="form-label">Refresh Dashboard (detik)</label><input class="form-control" type="number" name="dashboard_refresh_seconds" value="<?= setting_int('dashboard_refresh_seconds', 15) ?>"></div>
            <div class="form-group"><label class="form-label">Ambang Kedaluwarsa Data (menit)</label><input class="form-control" type="number" name="data_stale_minutes" value="<?= setting_int('data_stale_minutes', 10) ?>"></div>
            <div><button type="submit" class="btn btn-primary btn-sm">Simpan Pengaturan</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><div><h3 class="card-title">Ambang Parameter Sensor (<?= count($thresholds) ?>)</h3></div></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>Parameter</th><th>Spesies</th><th>Minimum</th><th>Maksimum</th><th>Satuan</th></tr></thead><tbody>
    <?php foreach ($thresholds as $t): ?>
        <tr><td><?= e(param_label($t['parameter'])) ?></td><td><?= e($t['species']) ?></td><td class="num"><?= fmt_num($t['min_value'], 2) ?></td><td class="num"><?= fmt_num($t['max_value'], 2) ?></td><td><?= e($t['unit'] ?: '') ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
