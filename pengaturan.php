<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

$cu = current_user();
$isAdm = is_admin();
$canMgr = can_manage();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'update_profile') {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        if ($fullName === '' || mb_strlen($fullName) > 100) {
            flash_set('error', 'Nama lengkap tidak valid.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
            flash_set('error', 'Alamat email tidak valid.');
        } else {
            $dup = db()->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
            $dup->execute([$email, (int)$cu['id']]);
            if ($dup->fetch()) {
                flash_set('error', 'Email sudah dipakai akun lain.');
            } else {
                db()->prepare('UPDATE users SET full_name = ?, email = ? WHERE id = ?')
                    ->execute([$fullName, $email, (int)$cu['id']]);
                $_SESSION['name'] = $fullName;
                flash_set('success', 'Profil berhasil diperbarui.');
            }
        }
        header('Location: pengaturan.php');
        exit;
    }

    if ($action === 'change_password') {
        $cur = (string)($_POST['current_password'] ?? '');
        $nw = (string)($_POST['new_password'] ?? '');
        $cfm = (string)($_POST['confirm_password'] ?? '');
        $pwRow = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $pwRow->execute([(int)$cu['id']]);
        $hash = (string)$pwRow->fetchColumn();

        if (!password_verify($cur, $hash)) {
            flash_set('error', 'Password saat ini salah.');
        } elseif (strlen($nw) < 8 || strlen($nw) > 255) {
            flash_set('error', 'Password baru minimal 8 karakter.');
        } elseif ($nw !== $cfm) {
            flash_set('error', 'Konfirmasi password tidak cocok.');
        } elseif ($cur === $nw) {
            flash_set('error', 'Password baru harus berbeda dari yang lama.');
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($nw, PASSWORD_BCRYPT), (int)$cu['id']]);
            flash_set('success', 'Password berhasil diubah.');
        }
        header('Location: pengaturan.php');
        exit;
    }

    if ($action === 'save_thresholds' && $canMgr) {
        $ids = as_arr($_POST['th_id'] ?? []);
        $mins = as_arr($_POST['th_min'] ?? []);
        $maxs = as_arr($_POST['th_max'] ?? []);
        $spans = as_arr($_POST['th_span'] ?? []);
        $now = date('Y-m-d H:i:s');

        $updTh = db()->prepare('UPDATE thresholds SET min_value = ?, max_value = ?, warn_span = ?, updated_by = ?, updated_at = ? WHERE id = ?');
        foreach ($ids as $i => $idRaw) {
            $id = (int)$idRaw;
            $min = (!isset($mins[$i]) || $mins[$i] === '' || !is_numeric($mins[$i])) ? null : (float)$mins[$i];
            $max = (!isset($maxs[$i]) || $maxs[$i] === '' || !is_numeric($maxs[$i])) ? null : (float)$maxs[$i];
            $span = (isset($spans[$i]) && is_numeric($spans[$i])) ? (float)$spans[$i] : 0.0;
            if ($id <= 0 || ($min !== null && $max !== null && $min > $max)) {
                continue;
            }
            $updTh->execute([$min, $max, $span, (int)$cu['id'], $now, $id]);
        }

        $speciesNew = trim((string)($_POST['th_species'] ?? ''));
        $paramNew = (string)($_POST['th_param'] ?? '');
        $minNewRaw = $_POST['th_min_new'] ?? '';
        $maxNewRaw = $_POST['th_max_new'] ?? '';
        $spanNewRaw = $_POST['th_span_new'] ?? '';
        $unitNew = trim((string)($_POST['th_unit_new'] ?? ''));

        if (
            $speciesNew !== '' &&
            $paramNew !== '' &&
            $minNewRaw !== '' && is_numeric($minNewRaw) &&
            $maxNewRaw !== '' && is_numeric($maxNewRaw) &&
            mb_strlen($speciesNew) <= 100
        ) {
            $minNew = (float)$minNewRaw;
            $maxNew = (float)$maxNewRaw;
            $spanNew = is_numeric($spanNewRaw) ? (float)$spanNewRaw : 0.0;
            $chk = db()->prepare('SELECT id FROM thresholds WHERE parameter = ? AND species = ? LIMIT 1');
            $chk->execute([$paramNew, $speciesNew]);
            $exRow = $chk->fetch();
            if ($exRow) {
                db()->prepare('UPDATE thresholds SET min_value=?, max_value=?, warn_span=?, unit=?, updated_by=?, updated_at=? WHERE id=?')
                    ->execute([$minNew, $maxNew, $spanNew, $unitNew, (int)$cu['id'], $now, (int)$exRow['id']]);
            } else {
                db()->prepare('INSERT INTO thresholds (parameter, species, min_value, max_value, warn_span, unit, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$paramNew, $speciesNew, $minNew, $maxNew, $spanNew, $unitNew, (int)$cu['id'], $now]);
            }
            flash_set('success', "Ambang baru untuk \"{$speciesNew}\" disimpan.");
        }

        flash_set('success', 'Ambang parameter berhasil diperbarui.');
        header('Location: pengaturan.php');
        exit;
    }

    if ($action === 'save_settings' && $isAdm) {
        foreach (['site_name', 'site_location', 'map_center_lat', 'map_center_lng', 'map_zoom'] as $k) {
            if (isset($_POST[$k]) && is_string($_POST[$k])) {
                save_setting($k, trim($_POST[$k]));
            }
        }
        if (isset($_POST['dashboard_refresh_seconds'])) {
            $sec = max(5, min(600, (int)$_POST['dashboard_refresh_seconds'] ?: 15));
            save_setting('dashboard_refresh_seconds', (string)$sec);
        }
        if (isset($_POST['data_stale_minutes'])) {
            $mn = max(1, min(120, (int)$_POST['data_stale_minutes'] ?: 10));
            save_setting('data_stale_minutes', (string)$mn);
        }
        save_setting('notify_browser', isset($_POST['notify_browser']) ? '1' : '0');
        save_setting('notify_email', isset($_POST['notify_email']) ? '1' : '0');
        if (isset($_POST['low_power_threshold_w'])) {
            save_setting('low_power_threshold_w', (string)max(0.0, (float)$_POST['low_power_threshold_w']));
        }
        if (isset($_POST['owc_capture_width_m'])) {
            $w = (float)$_POST['owc_capture_width_m'];
            save_setting('owc_capture_width_m', (string)max(0.1, $w > 0 ? $w : 3.0));
        }
        if (isset($_POST['owc_efficiency'])) {
            $eff = (float)$_POST['owc_efficiency'];
            save_setting('owc_efficiency', (string)min(1.0, max(0.01, $eff > 0 ? $eff : 0.3)));
        }
        if (isset($_POST['fish_lw_a'])) {
            $a = (float)$_POST['fish_lw_a'];
            save_setting('fish_lw_a', (string)max(0.0001, $a > 0 ? $a : 0.015));
        }
        if (isset($_POST['fish_lw_b'])) {
            $b = (float)$_POST['fish_lw_b'];
            save_setting('fish_lw_b', (string)max(1.0, min(5.0, $b > 0 ? $b : 3.0)));
        }
        flash_set('success', 'Pengaturan sistem berhasil disimpan.');
        header('Location: pengaturan.php');
        exit;
    }

    if ($action === 'add_user' && $isAdm) {
        $username = strtolower(trim((string)($_POST['username'] ?? '')));
        $email = trim((string)($_POST['email'] ?? ''));
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $rawRole = (string)($_POST['role'] ?? 'viewer');
        $role = in_array($rawRole, ['admin', 'operator', 'viewer'], true) ? $rawRole : 'viewer';
        $password = (string)($_POST['password'] ?? '');

        if (!preg_match('/^[a-z0-9_.]{3,50}$/', $username)) {
            flash_set('error', 'Username 3-50 karakter (huruf kecil, angka, titik, underscore).');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('error', 'Email tidak valid.');
        } elseif (strlen($password) < 8) {
            flash_set('error', 'Password minimal 8 karakter.');
        } else {
            $dup = db()->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
            $dup->execute([$username, $email]);
            if ($dup->fetch()) {
                flash_set('error', 'Username atau email sudah digunakan.');
            } else {
                db()->prepare(
                    'INSERT INTO users (username, email, password_hash, full_name, role, is_active, last_login, created_at)
                     VALUES (?, ?, ?, ?, ?, 1, NULL, ?)'
                )->execute([$username, $email, password_hash($password, PASSWORD_BCRYPT), $fullName !== '' ? $fullName : $username, $role, date('Y-m-d H:i:s')]);
                flash_set('success', "Pengguna {$username} ({$role}) berhasil dibuat.");
            }
        }
        header('Location: pengaturan.php');
        exit;
    }

    if ($action === 'toggle_user' && $isAdm) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$cu['id']) {
            flash_set('error', 'Tidak dapat menonaktifkan akun sendiri.');
        } else {
            db()->prepare('UPDATE users SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?')
                ->execute([$id]);
            flash_set('success', 'Status pengguna diperbarui.');
        }
        header('Location: pengaturan.php');
        exit;
    }

    if ($action === 'reset_password' && $isAdm) {
        $id = (int)($_POST['id'] ?? 0);
        $nw = (string)($_POST['new_password'] ?? '');
        if (strlen($nw) < 8) {
            flash_set('error', 'Password baru minimal 8 karakter.');
        } else {
            $chk = db()->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
            $chk->execute([$id]);
            if ($chk->fetch()) {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($nw, PASSWORD_BCRYPT), $id]);
                flash_set('success', "Password pengguna #{$id} berhasil direset.");
            } else {
                flash_set('error', 'Pengguna tidak ditemukan.');
            }
        }
        header('Location: pengaturan.php');
        exit;
    }

    flash_set('error', 'Aksi tidak dikenal atau tidak diizinkan.');
    header('Location: pengaturan.php');
    exit;
}

$thresholds = db()->query(
    "SELECT t.*, u.username AS updater
     FROM thresholds t
     LEFT JOIN users u ON u.id = t.updated_by
     WHERE t.parameter NOT IN ('salinity_psu', 'turbidity_ntu')
     ORDER BY t.parameter ASC, t.species ASC"
)->fetchAll();

$speciesNames = db()->query(
    'SELECT name FROM fish_species WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll(PDO::FETCH_COLUMN);

$users = db()->query(
    'SELECT id, username, email, full_name, role, is_active, last_login, created_at FROM users ORDER BY id ASC'
)->fetchAll();

$PAGE_ID = 'pengaturan';
$PAGE_TITLE = 'Pengaturan';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Pengaturan', null]];
$PAGE_SCRIPTS = ['pengaturan.js'];

require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-gear"></i> Pengaturan</h1>
        <p class="page-desc">Kelola profil, ambang batas parameter per spesies budidaya, pengaturan sistem,
        notifikasi, dan pengguna. Perubahan penting hanya dapat dilakukan sesuai hak akses.</p>
    </div>
    <div class="page-actions">
        <span class="badge <?= $isAdm ? 'badge-blue' : 'badge-gray' ?>"><i class="fa-solid fa-shield-halved"></i> Hak akses: <?= e(ucfirst((string)$cu['role'])) ?></span>
    </div>
</div>

<div class="grid-2">
    <!-- Profil -->
    <div class="card">
        <div class="card-header"><div><h3 class="card-title"><i class="fa-solid fa-user"></i> Profil Pengguna</h3></div></div>
        <div class="card-body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_profile">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input class="form-control" value="<?= e($cu['username']) ?>" disabled>
                        <p class="form-hint">Username tidak dapat diubah.</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nama Lengkap <span class="req">*</span></label>
                        <input class="form-control" name="full_name" required maxlength="100" value="<?= e($cu['full_name']) ?>">
                    </div>
                    <div class="form-group span-2">
                        <label class="form-label">Email <span class="req">*</span></label>
                        <input class="form-control" type="email" name="email" required maxlength="100" value="<?= e($cu['email']) ?>">
                    </div>
                </div>
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-save"></i> Simpan Profil</button>
            </form>
        </div>
    </div>

    <!-- Password -->
    <div class="card">
        <div class="card-header"><div><h3 class="card-title"><i class="fa-solid fa-lock"></i> Ubah Password</h3></div></div>
        <div class="card-body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_password">
                <div class="form-group">
                    <label class="form-label">Password Saat Ini <span class="req">*</span></label>
                    <input class="form-control" type="password" name="current_password" required autocomplete="current-password">
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Password Baru <span class="req">*</span></label>
                        <input class="form-control" type="password" name="new_password" required minlength="8" autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Konfirmasi <span class="req">*</span></label>
                        <input class="form-control" type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
                    </div>
                </div>
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-key"></i> Ubah Password</button>
            </form>
        </div>
    </div>
</div>

<!-- Ambang parameter -->
<div class="card">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-sliders"></i> Ambang Parameter per Spesies</h3>
            <p class="card-sub">Ambang <strong>tidak universal</strong> — sesuaikan dengan spesies dan kebutuhan budidaya Anda.
                Status: di luar batas normal ≤ jarak peringatan → <em>Warning</em>; melebihinya → <em>Kritis</em>.</p>
        </div>
    </div>
    <div class="card-body">
        <?php if ($canMgr): ?>
        <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_thresholds">
        <?php endif; ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Parameter</th><th>Spesies</th><th>Batas Min</th><th>Batas Maks</th><th>Jarak Peringatan</th><th>Unit</th><th>Pembaruan</th></tr></thead>
                <tbody>
                <?php foreach ($thresholds as $t): ?>
                    <tr>
                        <td class="table-strong"><?= e(param_label((string)$t['parameter'])) ?></td>
                        <td><?= e($t['species']) ?></td>
                        <?php if ($canMgr): ?>
                            <input type="hidden" name="th_id[]" value="<?= (int)$t['id'] ?>">
                            <td><input class="form-control" type="number" step="0.001" name="th_min[]" value="<?= e($t['min_value'] !== null ? $t['min_value'] : '') ?>" style="width:110px"></td>
                            <td><input class="form-control" type="number" step="0.001" name="th_max[]" value="<?= e($t['max_value'] !== null ? $t['max_value'] : '') ?>" style="width:110px"></td>
                            <td><input class="form-control" type="number" step="0.001" name="th_span[]" value="<?= e($t['warn_span'] !== null ? $t['warn_span'] : '') ?>" style="width:110px"></td>
                        <?php else: ?>
                            <td class="num"><?= fmt_num($t['min_value'], 2) ?></td>
                            <td class="num"><?= fmt_num($t['max_value'], 2) ?></td>
                            <td class="num"><?= fmt_num($t['warn_span'], 2) ?></td>
                        <?php endif; ?>
                        <td><?= e($t['unit'] ?? '') ?></td>
                        <td class="source-note"><?= e($t['updater'] ?: 'sistem') ?> · <?= e(time_ago($t['updated_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($canMgr): ?>
            <div style="display:flex;justify-content:flex-end;margin-top:12px">
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-save"></i> Simpan Semua Ambang</button>
            </div>
        </form>
        <?php endif; ?>

        <?php if ($canMgr): ?>
        <hr style="border:0;border-top:1px solid var(--border);margin:16px 0">
        <form method="post" class="input-group">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_thresholds">
            <select class="form-select" name="th_param" required>
                <?php foreach (['temp_sensor_c', 'ph', 'wave_height_m'] as $p): ?>
                    <option value="<?= e($p) ?>"><?= e(param_label($p)) ?></option>
                <?php endforeach; ?>
            </select>
            <input class="form-control" type="text" name="th_species" required maxlength="100" placeholder="Spesies baru, mis. Kakap Merah" style="width:220px" list="speciesSuggestions">
            <datalist id="speciesSuggestions">
                <?php foreach ($speciesNames as $sn): ?><option value="<?= e($sn) ?>"><?php endforeach; ?>
            </datalist>
            <input class="form-control" type="number" step="0.001" name="th_min_new" required placeholder="Min" style="width:100px">
            <input class="form-control" type="number" step="0.001" name="th_max_new" required placeholder="Maks" style="width:100px">
            <input class="form-control" type="number" step="0.001" name="th_span_new" required placeholder="Jarak" style="width:100px" title="Jarak di luar batas normal sebelum kritis">
            <input class="form-control" type="text" name="th_unit_new" placeholder="Unit" style="width:90px" maxlength="20">
            <button class="btn btn-outline btn-sm" type="submit"><i class="fa-solid fa-plus"></i> Tambah Ambang Spesies</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Pengaturan sistem -->
<div class="grid-2">
    <div class="card">
        <div class="card-header">
            <div><h3 class="card-title"><i class="fa-solid fa-sliders-h"></i> Pengaturan Sistem &amp; Lokasi</h3>
            <p class="card-sub">Nama lokasi penelitian, pusat peta, dan interval pembaruan dashboard</p></div>
        </div>
        <div class="card-body">
            <?php if ($isAdm): ?>
            <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_settings">
            <?php endif; ?>
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Nama Aplikasi</label>
                    <input class="form-control" name="site_name" value="<?= e((string)setting('site_name', APP_NAME)) ?>" <?= $isAdm ? '' : 'disabled' ?>>
                </div>
                <div class="form-group">
                    <label class="form-label">Nama Lokasi Penelitian</label>
                    <input class="form-control" name="site_location" value="<?= e((string)setting('site_location', '')) ?>" <?= $isAdm ? '' : 'disabled' ?>>
                </div>
                <div class="form-group">
                    <label class="form-label">Latitude Pusat Peta</label>
                    <input class="form-control" name="map_center_lat" value="<?= e((string)setting('map_center_lat', '-5.77')) ?>" <?= $isAdm ? '' : 'disabled' ?>>
                </div>
                <div class="form-group">
                    <label class="form-label">Longitude Pusat Peta</label>
                    <input class="form-control" name="map_center_lng" value="<?= e((string)setting('map_center_lng', '105.264')) ?>" <?= $isAdm ? '' : 'disabled' ?>>
                </div>
                <div class="form-group">
                    <label class="form-label">Interval Pembaruan Dashboard (detik)</label>
                    <select class="form-select" name="dashboard_refresh_seconds" <?= $isAdm ? '' : 'disabled' ?>>
                        <?php foreach ([10, 15, 30, 60] as $s): ?>
                            <option value="<?= $s ?>" <?= setting_int('dashboard_refresh_seconds', 15) === $s ? 'selected' : '' ?>><?= $s ?> detik</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Ambang Data Kedaluwarsa (menit)</label>
                    <input class="form-control" type="number" min="1" max="120" name="data_stale_minutes"
                           value="<?= e((string)setting('data_stale_minutes', '10')) ?>" <?= $isAdm ? '' : 'disabled' ?>>
                    <p class="form-hint">Data lebih tua dari ini berstatus DELAYED/OFFLINE.</p>
                </div>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Ambang Daya Rendah (W)</label>
                    <input class="form-control" type="number" step="1" min="0" name="low_power_threshold_w" value="<?= e((string)setting('low_power_threshold_w', '150')) ?>" <?= $isAdm ? '' : 'disabled' ?>>
                </div>
                <div class="form-group">
                    <label class="form-label">Lebar Penangkap OWC (m)</label>
                    <input class="form-control" type="number" step="0.1" min="0.1" name="owc_capture_width_m" value="<?= e((string)setting('owc_capture_width_m', '3')) ?>" <?= $isAdm ? '' : 'disabled' ?>>
                </div>
                <div class="form-group">
                    <label class="form-label">Efisiensi OWC (0–1)</label>
                    <input class="form-control" type="number" step="0.01" min="0.01" max="1" name="owc_efficiency" value="<?= e((string)setting('owc_efficiency', '0.3')) ?>" <?= $isAdm ? '' : 'disabled' ?>>
                </div>
                <div class="form-group">
                    <label class="form-label">Koefisien Ikan a (W = a·L<sup>b</sup>)</label>
                    <input class="form-control" type="number" step="0.001" min="0.001" name="fish_lw_a" value="<?= e((string)setting('fish_lw_a', '0.015')) ?>" <?= $isAdm ? '' : 'disabled' ?>>
                </div>
                <div class="form-group">
                    <label class="form-label">Koefisien Ikan b</label>
                    <input class="form-control" type="number" step="0.1" min="1" max="5" name="fish_lw_b" value="<?= e((string)setting('fish_lw_b', '3')) ?>" <?= $isAdm ? '' : 'disabled' ?>>
                </div>
            </div>
            <div style="display:flex;gap:20px;flex-wrap:wrap;margin:6px 0 14px">
                <label style="display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:600">
                    <input type="checkbox" name="notify_browser" <?= setting('notify_browser', '1') === '1' ? 'checked' : '' ?> <?= $isAdm ? '' : 'disabled' ?>>
                    Notifikasi browser/toast
                </label>
                <label style="display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:600">
                    <input type="checkbox" name="notify_email" <?= setting('notify_email', '0') === '1' ? 'checked' : '' ?> <?= $isAdm ? '' : 'disabled' ?>>
                    Notifikasi email (butuh SMTP)
                </label>
            </div>
            <?php if ($isAdm): ?>
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-save"></i> Simpan Pengaturan Sistem</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pengelolaan pengguna -->
    <div class="card">
        <div class="card-header">
            <div><h3 class="card-title"><i class="fa-solid fa-users-gear"></i> Pengelolaan Pengguna</h3>
            <p class="card-sub">Prioritas akses: administrator &amp; operator. Tidak ada registrasi mandiri.</p></div>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Pengguna</th><th>Peran</th><th>Login Terakhir</th><th>Status</th><?php if ($isAdm): ?><th class="td-actions">Aksi</th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td>
                            <span class="table-strong"><?= e($u['full_name'] ?: $u['username']) ?></span><br>
                            <span class="source-note"><?= e($u['username']) ?> · <?= e($u['email']) ?></span>
                        </td>
                        <td><span class="badge <?= $u['role'] === 'admin' ? 'badge-blue' : ($u['role'] === 'operator' ? 'badge-green' : 'badge-gray') ?>"><?= e(ucfirst((string)$u['role'])) ?></span></td>
                        <td class="source-note"><?= !empty($u['last_login']) ? e(time_ago($u['last_login'])) : 'belum pernah' ?></td>
                        <td><?= (int)$u['is_active'] === 1 ? '<span class="badge badge-green">Aktif</span>' : '<span class="badge badge-gray">Nonaktif</span>' ?></td>
                        <?php if ($isAdm): ?>
                        <td class="td-actions">
                            <?php if ((int)$u['id'] !== (int)$cu['id']): ?>
                            <form method="post" style="display:inline" onsubmit="return confirm('Ubah status aktif pengguna <?= e($u['username']) ?>?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_user">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-ghost btn-sm" title="Aktifkan/Nonaktifkan"><i class="fa-solid fa-power-off"></i></button>
                            </form>
                            <button type="button" class="btn btn-ghost btn-sm btn-reset-pass" data-id="<?= (int)$u['id'] ?>" data-name="<?= e($u['username']) ?>" title="Reset password">
                                <i class="fa-solid fa-key"></i>
                            </button>
                            <?php else: ?>
                            <span class="source-note">akun Anda</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($isAdm): ?>
        <div class="card-body" style="border-top:1px solid var(--border)">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_user">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Username <span class="req">*</span></label>
                        <input class="form-control" name="username" required pattern="[a-z0-9_.]{3,50}" placeholder="huruf kecil/angka">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nama Lengkap</label>
                        <input class="form-control" name="full_name" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email <span class="req">*</span></label>
                        <input class="form-control" type="email" name="email" required maxlength="100">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Peran <span class="req">*</span></label>
                        <select class="form-select" name="role">
                            <option value="operator">Operator</option>
                            <option value="admin">Administrator</option>
                            <option value="viewer">Viewer (lihat saja)</option>
                        </select>
                    </div>
                    <div class="form-group span-2">
                        <label class="form-label">Password <span class="req">*</span></label>
                        <input class="form-control" type="password" name="password" required minlength="8" autocomplete="new-password">
                        <p class="form-hint">Disimpan sebagai hash bcrypt — tidak pernah menyimpan password asli.</p>
                    </div>
                </div>
                <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-user-plus"></i> Tambah Pengguna</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
