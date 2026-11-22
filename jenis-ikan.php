<?php
/**
 * BOLU — Jenis Ikan (katalog jenis ikan KJA)
 * CRUD jenis ikan: nama, latin, kategori, rentang parameter ideal,
 * siklus panen, koefisien panjang-berat (W = a·L^b) untuk estimasi biomassa.
 *
 * Integrasi:
 *  - Checkbox "ambang kualitas air" menyimpan ambang per spesies ke tabel
 *    `thresholds` yang dipakai evaluasi kualitas air & peringatan otomatis.
 *  - Nama jenis ikan tersedia sebagai saran (datalist) di Pengaturan.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$PAGE_ID    = 'jenis-ikan';
$PAGE_TITLE = 'Jenis Ikan';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Jenis Ikan', null]];
$PAGE_SCRIPTS = ['jenis-ikan.js'];

$canEdit = can_manage();

$CATEGORIES = ['laut' => 'Laut', 'tawar' => 'Tawar', 'brackish' => 'Rawa Payau'];
$CAT_BADGE  = ['laut' => 'badge-blue', 'tawar' => 'badge-green', 'brackish' => 'badge-yellow'];

/* ================= Helper validasi numerik ================= */
/** Angka opsional; kembalikan float|null dengan batas min/max. */
function fopt(string $key, ?float $min, ?float $max): ?float
{
    $raw = $_POST[$key] ?? '';
    if ($raw === '' || $raw === null) return null;
    if (!is_numeric($raw)) return null;
    $v = (float)$raw;
    if ($min !== null && $v < $min) return null;
    if ($max !== null && $v > $max) return null;
    return $v;
}

/* ================= POST: aksi CRUD ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit) {
        flash_set('error', 'Hanya admin/operator yang dapat mengubah jenis ikan.');
        header('Location: jenis-ikan.php');
        exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $name  = trim($_POST['name'] ?? '');
        $latin = trim($_POST['latin_name'] ?? '') ?: null;
        $cat   = $_POST['category'] ?? 'laut';
        if (!array_key_exists($cat, $CATEGORIES)) $cat = 'laut';
        $desc  = trim($_POST['description'] ?? '') ?: null;
        $makeThreshold = isset($_POST['make_threshold']);

        // Angka opsional (null bila kosong / di luar batas wajar)
        $cycMin = fopt('cycle_months_min', 1, 60);
        $cycMax = fopt('cycle_months_max', 1, 60);
        $tw     = fopt('target_weight_kg', 0.005, 500);
        $lwA    = fopt('lw_a', 0.0001, 1.0) ?? 0.015;
        $lwB    = fopt('lw_b', 2.0, 4.0) ?? 3.0;
        $tMin   = fopt('optimal_temp_min', -5, 45);
        $tMax   = fopt('optimal_temp_max', -5, 45);
        $phMin  = fopt('optimal_ph_min', 0, 14);
        $phMax  = fopt('optimal_ph_max', 0, 14);
        $sMin   = fopt('optimal_salinity_min', 0, 60);
        $sMax   = fopt('optimal_salinity_max', 0, 60);
        // Perbaiki pasangan min/max yang terbalik
        if ($tMin !== null && $tMax !== null && $tMin > $tMax) [$tMin, $tMax] = [$tMax, $tMin];
        if ($phMin !== null && $phMax !== null && $phMin > $phMax) [$phMin, $phMax] = [$phMax, $phMin];
        if ($sMin !== null && $sMax !== null && $sMin > $sMax) [$sMin, $sMax] = [$sMax, $sMin];
        if ($cycMin !== null && $cycMax !== null && $cycMin > $cycMax) [$cycMin, $cycMax] = [$cycMax, $cycMin];

        $ok = false;
        try {
            if ($name === '' || mb_strlen($name) > 100 || ($latin !== null && mb_strlen($latin) > 120)
                || ($desc !== null && mb_strlen($desc) > 500)) {
                flash_set('error', 'Nama wajib diisi (maks. 100 karakter); latin maks. 120; deskripsi maks. 500.');
            } elseif ($action === 'add') {
                $st = db()->prepare(
                    'INSERT INTO fish_species
                     (name, latin_name, category, cycle_months_min, cycle_months_max, target_weight_kg,
                      lw_a, lw_b, optimal_temp_min, optimal_temp_max,
                      optimal_ph_min, optimal_ph_max, optimal_salinity_min, optimal_salinity_max, description)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $st->execute([$name, $latin, $cat, $cycMin, $cycMax, $tw,
                              $lwA, $lwB, $tMin, $tMax, $phMin, $phMax, $sMin, $sMax, $desc]);
                flash_set('success', 'Jenis ikan "' . $name . '" berhasil ditambahkan.');
                $ok = true;
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $st = db()->prepare(
                    'UPDATE fish_species SET name=?, latin_name=?, category=?, cycle_months_min=?, cycle_months_max=?,
                     target_weight_kg=?, lw_a=?, lw_b=?, optimal_temp_min=?, optimal_temp_max=?,
                     optimal_ph_min=?, optimal_ph_max=?, optimal_salinity_min=?, optimal_salinity_max=?, description=?
                     WHERE id=?'
                );
                $st->execute([$name, $latin, $cat, $cycMin, $cycMax, $tw,
                              $lwA, $lwB, $tMin, $tMax, $phMin, $phMax, $sMin, $sMax, $desc, $id]);
                flash_set('success', 'Jenis ikan "' . $name . '" berhasil diperbarui.');
                $ok = true;
            }
        } catch (PDOException $ex) {
            flash_set('error', 'Gagal menyimpan — nama "' . $name . '" mungkin sudah dipakai jenis ikan lain.');
        }

        // Opsional: simpan ambang kualitas air default untuk spesies ini (dipakai kualitas-air & peringatan)
        if ($ok && $makeThreshold && $name !== '') {
            $cu = current_user();
            $rows = [
                ['temp_sensor_c', $tMin, $tMax, 1.0, '°C'],
                ['ph',            $phMin, $phMax, 0.3, ''],
                ['salinity_psu',  $sMin, $sMax, 1.5, 'PSU'],
            ];
            $st = db()->prepare(
                'INSERT INTO thresholds (parameter, species, min_value, max_value, warn_span, unit, updated_by)
                 VALUES (?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE min_value=VALUES(min_value), max_value=VALUES(max_value),
                                         warn_span=VALUES(warn_span), unit=VALUES(unit), updated_by=VALUES(updated_by)'
            );
            $n = 0;
            foreach ($rows as [$param, $mn, $mx, $span, $unit]) {
                if ($mn === null && $mx === null) continue;
                $st->execute([$param, $name, $mn, $mx, $span, $unit, $cu['id']]);
                $n++;
            }
            if ($n > 0) {
                flash_set('info', 'Ambang kualitas air untuk "' . $name . '" disimpan (' . $n
                    . ' parameter) — dipakai evaluasi Kualitas Air & Peringatan otomatis.');
            }
        }
        header('Location: jenis-ikan.php');
        exit;
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('UPDATE fish_species SET is_active = 1 - is_active WHERE id = ?');
        $st->execute([$id]);
        flash_set('success', 'Status jenis ikan diperbarui.');
        header('Location: jenis-ikan.php');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT name FROM fish_species WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $d = db()->prepare('DELETE FROM fish_species WHERE id = ?');
            $d->execute([$id]);
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

/* ================= Data tampilan ================= */
$species = db()->query(
    'SELECT fs.*,
            (SELECT COUNT(*) FROM thresholds th WHERE th.species = fs.name) AS threshold_count
     FROM fish_species fs
     ORDER BY fs.is_active DESC, fs.name ASC'
)->fetchAll();

$nActive = db()->query('SELECT COUNT(*) AS c FROM fish_species WHERE is_active = 1')->fetch()['c'];

include __DIR__ . '/includes/header.php';
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
                        <?php if ($s['latin_name']): ?><br><small style="color:var(--muted);font-style:italic"><?= e($s['latin_name']) ?></small><?php endif; ?>
                        <?php if ($s['description']): ?><br><small style="color:var(--muted)"><?= e(mb_strimwidth($s['description'], 0, 70, '…')) ?></small><?php endif; ?>
                    </td>
                    <td><span class="badge <?= $CAT_BADGE[$s['category']] ?>"><?= e($CATEGORIES[$s['category']]) ?></span></td>
                    <td class="num"><?= $s['optimal_temp_min'] !== null ? fmt_num((float)$s['optimal_temp_min'], 1) . '–' . fmt_num((float)$s['optimal_temp_max'], 1) . ' °C' : '—' ?></td>
                    <td class="num"><?= $s['optimal_ph_min'] !== null ? fmt_num((float)$s['optimal_ph_min'], 1) . '–' . fmt_num((float)$s['optimal_ph_max'], 1) : '—' ?></td>
                    <td class="num"><?= $s['optimal_salinity_min'] !== null ? fmt_num((float)$s['optimal_salinity_min'], 0) . '–' . fmt_num((float)$s['optimal_salinity_max'], 0) . ' ppt' : '—' ?></td>
                    <td class="num"><?= $s['cycle_months_min'] !== null ? fmt_num((float)$s['cycle_months_min'], 0) . '–' . fmt_num((float)$s['cycle_months_max'], 0) . ' bln' : '—' ?>
                        <?php if ($s['target_weight_kg'] !== null): ?><br><small style="color:var(--muted)">± <?= fmt_num((float)$s['target_weight_kg'], 2) ?> kg/ekor</small><?php endif; ?>
                    </td>
                    <td class="num"><small>a = <?= fmt_num((float)$s['lw_a'], 4) ?><br>b = <?= fmt_num((float)$s['lw_b'], 3) ?></small></td>
                    <td><?= (int)$s['threshold_count'] > 0
                        ? '<span class="badge badge-green"><i class="fa-solid fa-check"></i> ' . (int)$s['threshold_count'] . ' param</span>'
                        : '<span class="source-note">—</span>' ?></td>
                    <td><?= $s['is_active'] ? '<span class="badge badge-green">Aktif</span>' : '<span class="badge badge-gray">Nonaktif</span>' ?></td>
                    <?php if ($canEdit): ?>
                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <button type="button" class="btn btn-outline btn-sm btn-edit"
                                    data-species='<?= e(json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>'>
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

<?php include __DIR__ . '/includes/footer.php'; ?>
<!--PARTJS-->
