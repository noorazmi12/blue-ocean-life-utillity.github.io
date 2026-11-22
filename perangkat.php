<?php
/**
 * BOLU — Manajemen Perangkat
 * Daftar perangkat, tambah/edit/nonaktifkan (admin/operator), token API untuk
 * autentikasi perangkat, dan detail histori pengukuran per perangkat.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$PAGE_ID    = 'perangkat';
$PAGE_TITLE = 'Manajemen Perangkat';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Manajemen Perangkat', null]];
$PAGE_SCRIPTS = ['perangkat.js'];

$canEdit = can_manage();
$isAdm   = is_admin();

/* ================= POST: aksi perangkat ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit) {
        flash_set('error', 'Hanya admin/operator yang dapat mengubah perangkat.');
        header('Location: perangkat.php');
        exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $code = strtoupper(trim($_POST['device_code'] ?? ''));
        $name = trim($_POST['device_name'] ?? '');
        $type = $_POST['device_type'] ?? '';
        $stt  = $_POST['status'] ?? 'offline';
        $cal  = $_POST['calibration_status'] ?? 'none';
        $calDate = $_POST['calibration_date'] ?: null;
        $notes   = trim($_POST['notes'] ?? '') ?: null;
        $locName = trim($_POST['location_name'] ?? '') ?: null;
        $lat = ($_POST['latitude'] ?? '') !== '' ? (float)$_POST['latitude'] : null;
        $lng = ($_POST['longitude'] ?? '') !== '' ? (float)$_POST['longitude'] : null;

        $validTypes = ['owc', 'wave_sensor', 'water_sensor', 'camera', 'gateway'];
        if ($code === '' || !preg_match('/^[A-Z0-9_\-]{2,50}$/', $code) || $name === '' || !in_array($type, $validTypes, true) || !in_array($stt, ['online', 'offline', 'maintenance'], true)) {
            flash_set('error', 'Data perangkat tidak valid. Kode hanya huruf besar/angka/garis.');
            header('Location: perangkat.php');
            exit;
        }
        if ($lat !== null && ($lat < -90 || $lat > 90)) $lat = null;
        if ($lng !== null && ($lng < -180 || $lng > 180)) $lng = null;

        if ($action === 'add') {
            $token = bin2hex(random_bytes(24)); // token asli ditampilkan SEKALI
            try {
                $st = db()->prepare(
                    'INSERT INTO devices (device_code, device_name, device_type, status, is_active, api_token, calibration_status, calibration_date, notes)
                     VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)'
                );
                $st->execute([$code, $name, $type, $stt, hash('sha256', $token), $cal, $calDate, $notes]);
                $newId = (int)db()->lastInsertId();
                if ($lat !== null && $lng !== null) {
                    $li = db()->prepare('INSERT INTO device_locations (device_id, latitude, longitude, location_name) VALUES (?, ?, ?, ?)');
                    $li->execute([$newId, $lat, $lng, $locName]);
                }
                flash_set('success', 'Perangkat ' . $code . ' berhasil ditambahkan. '
                    . 'TOKEN API (tampilkan sekarang, jangan hilang): ' . $token);
            } catch (PDOException $ex) {
                flash_set('error', 'Gagal menambah perangkat — kode mungkin sudah dipakai.');
            }
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $st = db()->prepare(
                'UPDATE devices SET device_name = ?, device_type = ?, status = ?, calibration_status = ?, calibration_date = ?, notes = ? WHERE id = ?'
            );
            $st->execute([$name, $type, $stt, $cal, $calDate, $notes, $id]);
            if ($lat !== null && $lng !== null) {
                $li = db()->prepare(
                    'INSERT INTO device_locations (device_id, latitude, longitude, location_name)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE latitude = VALUES(latitude), longitude = VALUES(longitude), location_name = VALUES(location_name)'
                );
                $li->execute([$id, $lat, $lng, $locName]);
            }
            flash_set('success', 'Perangkat berhasil diperbarui.');
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('UPDATE devices SET is_active = 1 - is_active WHERE id = ?');
        $st->execute([$id]);
        flash_set('success', 'Status aktivasi perangkat diperbarui.');
    } elseif ($action === 'reset_token') {
        $id = (int)($_POST['id'] ?? 0);
        $token = bin2hex(random_bytes(24));
        $st = db()->prepare('UPDATE devices SET api_token = ? WHERE id = ?');
        $st->execute([hash('sha256', $token), $id]);
        flash_set('success', 'Token baru perangkat #' . $id . ' (tampilkan sekarang): ' . $token);
    } else {
        flash_set('error', 'Aksi tidak dikenal.');
    }
    header('Location: perangkat.php');
    exit;
}

/* ================= Data tampilan ================= */
$devices = db()->query(
    "SELECT d.*, l.latitude, l.longitude, l.location_name
     FROM devices d LEFT JOIN device_locations l ON l.device_id = d.id
     ORDER BY d.is_active DESC, d.device_code"
)->fetchAll();

$staleMin = max(1, setting_int('data_stale_minutes', 10));

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-microchip"></i> Manajemen Perangkat</h1>
        <p class="page-desc">Kelola sensor gelombang, sensor kualitas air, unit OWC, dan kamera FishCount.
            Setiap perangkat menerima token API untuk autentikasi pengiriman data melalui <code>api/ingest.php</code>.</p>
    </div>
    <div class="page-actions">
        <?php if ($canEdit): ?>
        <button type="button" class="btn btn-primary btn-sm" id="btnAddDevice"><i class="fa-solid fa-plus"></i> Tambah Perangkat</button>
        <?php endif; ?>
        <span class="source-note"><i class="fa-regular fa-clock"></i> Status dihitung dari komunikasi terakhir (ambang <?= $staleMin ?> menit)</span>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-list"></i> Daftar Perangkat (<?= count($devices) ?>)</h3>
            <p class="card-sub">Perangkat nonaktif disembunyikan dari halaman monitoring</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th><th>Kode / Nama</th><th>Jenis</th><th>Lokasi</th><th>Status</th>
                    <th>Komunikasi Terakhir</th><th>Kalibrasi</th><th class="td-actions">Aksi</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($devices as $d):
                $active = (int)$d['is_active'] === 1;
                $liveStatus = ($active && $d['status'] === 'online' && $d['last_communication'] && strtotime($d['last_communication']) > time() - $staleMin * 60) ? 'live' : (($d['status'] === 'online' && $d['last_communication'] && strtotime($d['last_communication']) > time() - $staleMin * 180) ? 'delayed' : 'offline');
                $calMap = ['calibrated' => ['badge-green', 'Terkalibrasi'], 'due' => ['badge-yellow', 'Jatuh tempo'], 'overdue' => ['badge-red', 'Terlambat'], 'none' => ['badge-gray', '—']];
                [$calCls, $calLbl] = $calMap[$d['calibration_status']] ?? $calMap['none'];
            ?>
                <tr>
                    <td class="num">#<?= (int)$d['id'] ?></td>
                    <td>
                        <span class="table-strong"><?= e($d['device_code']) ?></span><br>
                        <span class="source-note"><?= e($d['device_name']) ?></span>
                        <?= !$active ? '<span class="badge badge-gray">Nonaktif</span>' : '' ?>
                    </td>
                    <td><span class="badge badge-outline"><?= e(str_replace('_', ' ', $d['device_type'])) ?></span></td>
                    <td>
                        <?= e($d['location_name'] ?: '—') ?><br>
                        <?php if ($d['latitude'] !== null): ?><span class="source-note"><?= fmt_num($d['latitude'], 5) ?>, <?= fmt_num($d['longitude'], 5) ?></span><?php endif; ?>
                    </td>
                    <td><?= device_status_badge($active ? ($d['status'] === 'online' ? ($liveStatus === 'offline' ? 'offline' : 'online') : $d['status']) : 'offline') ?>
                        <?= status_pill($liveStatus) ?></td>
                    <td><?= $d['last_communication'] ? e(time_ago($d['last_communication'])) : 'belum pernah' ?></td>
                    <td><span class="badge <?= $calCls ?>"><?= e($calLbl) ?></span></td>
                    <td class="td-actions">
                        <button type="button" class="btn btn-outline btn-sm btn-detail" data-id="<?= (int)$d['id'] ?>" data-name="<?= e($d['device_name']) ?>">
                            <i class="fa-solid fa-eye"></i> Detail
                        </button>
                        <?php if ($canEdit): ?>
                            <button type="button" class="btn btn-ghost btn-sm btn-edit"
                                data-id="<?= (int)$d['id'] ?>" data-code="<?= e($d['device_code']) ?>" data-name="<?= e($d['device_name']) ?>"
                                data-type="<?= e($d['device_type']) ?>" data-status="<?= e($d['status']) ?>"
                                data-cal="<?= e($d['calibration_status']) ?>" data-caldate="<?= e($d['calibration_date'] ?? '') ?>"
                                data-notes="<?= e($d['notes'] ?? '') ?>" data-lat="<?= e($d['latitude'] ?? '') ?>"
                                data-lng="<?= e($d['longitude'] ?? '') ?>" data-loc="<?= e($d['location_name'] ?? '') ?>"
                                title="Edit perangkat"><i class="fa-solid fa-pen"></i></button>
                            <?php if ($isAdm): ?>
                            <form method="post" style="display:inline" onsubmit="return confirm('Nonaktifkan/aktifkan perangkat <?= e($d['device_code']) ?>?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                <button class="btn btn-ghost btn-sm" title="<?= $active ? 'Nonaktifkan' : 'Aktifkan' ?> perangkat">
                                    <i class="fa-solid <?= $active ? 'fa-power-off' : 'fa-check-circle' ?>"></i>
                                </button>
                            </form>
                            <form method="post" style="display:inline" onsubmit="return confirm('Buat token API baru? Token lama akan tidak berlaku.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reset_token">
                                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                <button class="btn btn-ghost btn-sm" title="Reset token API"><i class="fa-solid fa-key"></i></button>
                            </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="callout callout-info">
    <i class="fa-solid fa-plug"></i>
    <div><strong>Integrasi perangkat:</strong> kirim pengukuran melalui <code>POST api/ingest.php</code> dengan JSON
        berisi <code>device_code</code>, <code>api_key</code> (token asli), <code>timestamp</code>, dan <code>data</code>.
        Dokumentasi lengkap ada di <code>README.md</code> bagian API. Token disimpan sebagai hash SHA-256 —
        token asli hanya ditampilkan saat dibuat.</div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
