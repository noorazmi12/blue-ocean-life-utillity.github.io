<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

$CATEGORIES = [
    'laut' => 'Laut',
    'tawar' => 'Tawar',
    'brackish' => 'Rawa Payau',
];
$CAT_BADGE = [
    'laut' => 'badge-blue',
    'tawar' => 'badge-green',
    'brackish' => 'badge-yellow',
];

$fopt = static function (mixed $raw, ?float $min, ?float $max): ?float {
    if ($raw === null || trim((string)$raw) === '' || !is_numeric($raw)) {
        return null;
    }
    $v = (float)$raw;
    if (!is_finite($v)) {
        return null;
    }
    if ($min !== null && $v < $min) {
        return null;
    }
    if ($max !== null && $v > $max) {
        return null;
    }
    return $v;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    if (!can_manage()) {
        flash_set('error', 'Hanya admin/operator yang dapat mengubah jenis ikan.');
        header('Location: jenis-ikan.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add' || $action === 'edit') {
        $name = trim((string)($_POST['name'] ?? ''));
        $latinRaw = trim((string)($_POST['latin_name'] ?? ''));
        $latin = $latinRaw !== '' ? $latinRaw : null;
        $rawCat = (string)($_POST['category'] ?? 'laut');
        $cat = isset($CATEGORIES[$rawCat]) ? $rawCat : 'laut';
        $descRaw = trim((string)($_POST['description'] ?? ''));
        $desc = $descRaw !== '' ? $descRaw : null;
        $makeThreshold = isset($_POST['make_threshold']);

        $cycMin = $fopt($_POST['cycle_months_min'] ?? null, 1, 60);
        $cycMax = $fopt($_POST['cycle_months_max'] ?? null, 1, 60);
        $tw = $fopt($_POST['target_weight_kg'] ?? null, 0.005, 500);
        $lwA = $fopt($_POST['lw_a'] ?? null, 0.0001, 1.0) ?? 0.015;
        $lwB = $fopt($_POST['lw_b'] ?? null, 2.0, 4.0) ?? 3.0;
        $tMin = $fopt($_POST['optimal_temp_min'] ?? null, -5, 45);
        $tMax = $fopt($_POST['optimal_temp_max'] ?? null, -5, 45);
        $phMin = $fopt($_POST['optimal_ph_min'] ?? null, 0, 14);
        $phMax = $fopt($_POST['optimal_ph_max'] ?? null, 0, 14);
        $sMin = $fopt($_POST['optimal_salinity_min'] ?? null, 0, 60);
        $sMax = $fopt($_POST['optimal_salinity_max'] ?? null, 0, 60);

        if ($tMin !== null && $tMax !== null && $tMin > $tMax) {
            [$tMin, $tMax] = [$tMax, $tMin];
        }
        if ($phMin !== null && $phMax !== null && $phMin > $phMax) {
            [$phMin, $phMax] = [$phMax, $phMin];
        }
        if ($sMin !== null && $sMax !== null && $sMin > $sMax) {
            [$sMin, $sMax] = [$sMax, $sMin];
        }
        if ($cycMin !== null && $cycMax !== null && $cycMin > $cycMax) {
            [$cycMin, $cycMax] = [$cycMax, $cycMin];
        }

        $ok = false;
        $now = date('Y-m-d H:i:s');

        if (
            $name === '' ||
            mb_strlen($name) > 100 ||
            ($latin !== null && mb_strlen($latin) > 120) ||
            ($desc !== null && mb_strlen($desc) > 500)
        ) {
            flash_set('error', 'Nama wajib diisi (maks. 100 karakter); latin maks. 120; deskripsi maks. 500.');
        } elseif ($action === 'add') {
            $dup = db()->prepare('SELECT id FROM fish_species WHERE LOWER(name) = LOWER(?) LIMIT 1');
            $dup->execute([$name]);
            if ($dup->fetch()) {
                flash_set('error', "Gagal menyimpan — nama \"{$name}\" mungkin sudah dipakai jenis ikan lain.");
            } else {
                db()->prepare(
                    'INSERT INTO fish_species (name, latin_name, category, cycle_months_min, cycle_months_max, target_weight_kg, lw_a, lw_b,
                                               optimal_temp_min, optimal_temp_max, optimal_ph_min, optimal_ph_max, optimal_salinity_min, optimal_salinity_max,
                                               description, is_active, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
                )->execute([
                    $name, $latin, $cat,
                    $cycMin !== null ? (int)round($cycMin) : null,
                    $cycMax !== null ? (int)round($cycMax) : null,
                    $tw, $lwA, $lwB,
                    $tMin, $tMax, $phMin, $phMax, $sMin, $sMax,
                    $desc, $now, $now,
                ]);
                flash_set('success', "Jenis ikan \"{$name}\" berhasil ditambahkan.");
                $ok = true;
            }
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $chk = db()->prepare('SELECT id FROM fish_species WHERE id = ? LIMIT 1');
            $chk->execute([$id]);
            $dup = db()->prepare('SELECT id FROM fish_species WHERE id != ? AND LOWER(name) = LOWER(?) LIMIT 1');
            $dup->execute([$id, $name]);
            if (!$chk->fetch() || $dup->fetch()) {
                flash_set('error', "Gagal menyimpan — nama \"{$name}\" mungkin sudah dipakai jenis ikan lain.");
            } else {
                db()->prepare(
                    'UPDATE fish_species SET name=?, latin_name=?, category=?, cycle_months_min=?, cycle_months_max=?, target_weight_kg=?, lw_a=?, lw_b=?,
                                             optimal_temp_min=?, optimal_temp_max=?, optimal_ph_min=?, optimal_ph_max=?, optimal_salinity_min=?, optimal_salinity_max=?,
                                             description=?, updated_at=? WHERE id=?'
                )->execute([
                    $name, $latin, $cat,
                    $cycMin !== null ? (int)round($cycMin) : null,
                    $cycMax !== null ? (int)round($cycMax) : null,
                    $tw, $lwA, $lwB,
                    $tMin, $tMax, $phMin, $phMax, $sMin, $sMax,
                    $desc, $now, $id,
                ]);
                flash_set('success', "Jenis ikan \"{$name}\" berhasil diperbarui.");
                $ok = true;
            }
        }

        if ($ok && $makeThreshold && $name !== '') {
            $cu = current_user();
            $thRows = [
                ['temp_sensor_c', $tMin, $tMax, 1.0, '°C'],
                ['ph', $phMin, $phMax, 0.3, ''],
                ['salinity_psu', $sMin, $sMax, 1.5, 'PSU'],
            ];
            $n = 0;
            foreach ($thRows as [$p, $mn, $mx, $span, $unit]) {
                if ($mn === null && $mx === null) {
                    continue;
                }
                $ex = db()->prepare('SELECT id FROM thresholds WHERE parameter = ? AND species = ? LIMIT 1');
                $ex->execute([$p, $name]);
                $exRow = $ex->fetch();
                if ($exRow) {
                    db()->prepare(
                        'UPDATE thresholds SET min_value=?, max_value=?, warn_span=?, unit=?, updated_by=?, updated_at=? WHERE id=?'
                    )->execute([$mn, $mx, $span, $unit, (int)$cu['id'], $now, (int)$exRow['id']]);
                } else {
                    db()->prepare(
                        'INSERT INTO thresholds (parameter, species, min_value, max_value, warn_span, unit, updated_by, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([$p, $name, $mn, $mx, $span, $unit, (int)$cu['id'], $now]);
                }
                $n++;
            }
            if ($n > 0) {
                flash_set('info', "Ambang kualitas air untuk \"{$name}\" disimpan ({$n} parameter) — dipakai evaluasi Kualitas Air & Peringatan otomatis.");
            }
        }
        header('Location: jenis-ikan.php');
        exit;
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('UPDATE fish_species SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?')
            ->execute([$id]);
        flash_set('success', 'Status jenis ikan diperbarui.');
        header('Location: jenis-ikan.php');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT name FROM fish_species WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            db()->prepare('DELETE FROM fish_species WHERE id = ?')->execute([$id]);
            flash_set('success', 'Jenis ikan "' . $row['name'] . '" dihapus.');
        } else {
            flash_set('error', 'Jenis ikan tidak ditemukan.');
        }
        header('Location: jenis-ikan.php');
        exit;
    }

    header('Location: jenis-ikan.php');
    exit;
}

$species = db()->query(
    'SELECT f.*, (SELECT COUNT(*) FROM thresholds t WHERE t.species = f.name) AS threshold_count
     FROM fish_species f
     ORDER BY f.is_active DESC, f.name ASC'
)->fetchAll();

$nActive = 0;
foreach ($species as &$sp) {
    $sp['id'] = (int)$sp['id'];
    $sp['is_active'] = (int)$sp['is_active'];
    $sp['threshold_count'] = (int)$sp['threshold_count'];
    if ($sp['is_active'] === 1) {
        $nActive++;
    }
}
unset($sp);

$canEdit = can_manage();

$PAGE_ID = 'jenis-ikan';
$PAGE_TITLE = 'Jenis Ikan';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Jenis Ikan', null]];
$PAGE_SCRIPTS = ['jenis-ikan.js'];

require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-fish-fins"></i> Jenis Ikan</h1>
        <p class="page-desc">Katalog jenis ikan yang dipelihara di KJA Anda: rentang kualitas air ideal,
            durasi siklus panen, dan koefisien panjang–berat untuk estimasi biomassa FishCount.
            <?= $nActive ?> jenis aktif dari total <?= count($species) ?>.</p>
    </div>
    <div class="page-actions">
        <?php if ($canEdit): ?>
            <button type="button" class="btn btn-primary" id="btnAddSpecies">
                <i class="fa-solid fa-plus"></i> Tambah Jenis Ikan
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="callout callout-info">
    <i class="fa-solid fa-link"></i>
    <div><strong>Keterkaitan fitur:</strong> nama jenis ikan di sini bisa dipakai sebagai <em>Spesies</em> pada
        ambang parameter di <a href="pengaturan.php">Pengaturan</a> — centang <em>"Simpan ambang kualitas air"</em>
        saat menambah/mengedit agar rentang ideal di bawah otomatis menjadi ambang evaluasi
        <a href="kualitas-air.php">Kualitas Air</a> &amp; <a href="peringatan.php">Peringatan</a>.
        Koefisien panjang–berat <code>W(g) = a·L<sup>b</sup></code> dipakai FishCount mengubah panjang rata-rata
        hasil kamera menjadi estimasi biomassa.</div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-list"></i> Daftar Jenis Ikan KJA</h3>
            <p class="card-sub">Rentang ideal bersifat acuan budidaya — sesuaikan dengan kondisi lokasi Anda</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Jenis Ikan</th><th>Kategori</th><th>Suhu Ideal</th><th>pH Ideal</th>
                    <th>Salinitas</th><th>Siklus Panen</th><th>Koef. L–W</th><th>Ambang</th><th>Status</th>
                    <?php if ($canEdit): ?><th>Aksi</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (!$species): ?>
                <tr><td colspan="10"><div class="empty-state"><i class="fa-solid fa-fish"></i>
                    <p>Belum ada jenis ikan. Klik "Tambah Jenis Ikan" untuk memulai.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($species as $s): ?>
                <tr>
                    <td class="table-strong">
                        <?= e($s['name']) ?>
                        <?php if (!empty($s['latin_name'])): ?><br><small style="color:var(--muted);font-style:italic"><?= e($s['latin_name']) ?></small><?php endif; ?>
                        <?php if (!empty($s['description'])): ?><br><small style="color:var(--muted)"><?= e(str_trim_width((string)$s['description'], 70, '…')) ?></small><?php endif; ?>
                    </td>
                    <td><span class="badge <?= $CAT_BADGE[$s['category']] ?? 'badge-blue' ?>"><?= e($CATEGORIES[$s['category']] ?? $s['category']) ?></span></td>
                    <td class="num"><?= $s['optimal_temp_min'] !== null ? fmt_num($s['optimal_temp_min'], 1) . '–' . fmt_num($s['optimal_temp_max'], 1) . ' °C' : '—' ?></td>
                    <td class="num"><?= $s['optimal_ph_min'] !== null ? fmt_num($s['optimal_ph_min'], 1) . '–' . fmt_num($s['optimal_ph_max'], 1) : '—' ?></td>
                    <td class="num"><?= $s['optimal_salinity_min'] !== null ? fmt_num($s['optimal_salinity_min'], 0) . '–' . fmt_num($s['optimal_salinity_max'], 0) . ' ppt' : '—' ?></td>
                    <td class="num"><?= $s['cycle_months_min'] !== null ? fmt_num($s['cycle_months_min'], 0) . '–' . fmt_num($s['cycle_months_max'], 0) . ' bln' : '—' ?>
                        <?php if ($s['target_weight_kg'] !== null): ?><br><small style="color:var(--muted)">± <?= fmt_num($s['target_weight_kg'], 2) ?> kg/ekor</small><?php endif; ?>
                    </td>
                    <td class="num"><small>a = <?= fmt_num($s['lw_a'], 4) ?><br>b = <?= fmt_num($s['lw_b'], 3) ?></small></td>
                    <td><?= $s['threshold_count'] > 0
                        ? '<span class="badge badge-green"><i class="fa-solid fa-check"></i> ' . (int)$s['threshold_count'] . ' param</span>'
                        : '<span class="source-note">—</span>' ?></td>
                    <td><?= $s['is_active'] ? '<span class="badge badge-green">Aktif</span>' : '<span class="badge badge-gray">Nonaktif</span>' ?></td>
                    <?php if ($canEdit): ?>
                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <button type="button" class="btn btn-outline btn-sm btn-edit"
                                    data-species="<?= e((string)json_encode($s, JSON_UNESCAPED_UNICODE)) ?>">
                                <i class="fa-solid fa-pen"></i> Edit</button>
                            <form method="post" action="jenis-ikan.php" style="display:inline"
                                  onsubmit="return confirm('Ubah status aktif jenis ikan &quot;<?= e($s['name']) ?>&quot;?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm" title="Aktif/Nonaktifkan">
                                    <i class="fa-solid fa-power-off"></i></button>
                            </form>
                            <form method="post" action="jenis-ikan.php" style="display:inline"
                                  onsubmit="return confirm('HAPUS jenis ikan &quot;<?= e($s['name']) ?>&quot;? Tindakan ini tidak dapat dibatalkan.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm btn-danger">
                                    <i class="fa-solid fa-trash"></i></button>
                            </form>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="callout callout-warn">
    <i class="fa-solid fa-flask"></i>
    <div><strong>Catatan kejujuran data:</strong> empat jenis ikan bawaan (Kakap putih, Kerapu cantang, Bandeng,
        Nila) adalah <em>contoh umum budidaya Indonesia</em> sebagai titik awal — rentang idealnya merupakan
        acuan literatur budidaya, bukan hasil pengukuran di lokasi Anda. Semua nilai dapat Anda sunting agar
        sesuai kondisi keramba.</div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
