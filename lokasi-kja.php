<?php
/**
 * BOLU — Lokasi KJA (Rekomendasi titik budidaya karamba jaring apung di Banten)
 *
 * Menampilkan peta titik-titik usulan lokasi KJA di Provinsi Banten yang
 * dinilai dari rubrik kesesuaian (kedalaman, kelindungan, akses, konflik
 * ruang). Kedalaman per titik ditarik dari grid GEBCO 2020 dan dapat
 * diverifikasi ulang secara LIVE (BATNAS SIBATNAS BIG + GEBCO) melalui
 * api/bathymetry-point.php — sama seperti pada Peta Batimetri.
 *
 * Data titik: tabel kja_sites (database/migrate_004_kja_sites.sql).
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$PAGE_ID      = 'lokasi-kja';
$PAGE_TITLE   = 'Lokasi KJA';
$BREADCRUMB   = [['Dashboard', 'dashboard.php'], ['Lokasi KJA', null]];
$PAGE_SCRIPTS = ['lokasi-kja.js'];
$NEED_LEAFLET = true;

/* ---------- Muat titik usulan dari database ---------- */
$sites = [];
try {
    $sites = db()->query(
        'SELECT * FROM kja_sites WHERE is_active = 1 ORDER BY suitability_score DESC, site_name'
    )->fetchAll();
} catch (Throwable $t) {
    $sites = [];
}

/** Metadata kelas kesesuaian untuk badge & legend. */
$CLASS_META = [
    'S1' => ['label' => 'Sangat cocok (S1)',          'color' => '#2FA36B'],
    'S2' => ['label' => 'Cukup cocok (S2)',           'color' => '#E8A13D'],
    'S3' => ['label' => 'Kurang cocok (S3)',          'color' => '#E06A4C'],
    'N'  => ['label' => 'Tidak direkomendasikan (N)', 'color' => '#8D95A1'],
];
$SHELTER_LABEL = ['teluk' => 'Teluk tertutup', 'semi' => 'Semi terlindung', 'terbuka' => 'Perairan terbuka'];

/* JSON untuk peta (aman-escape tags) */
$sitesJson = json_encode(
    array_map(function ($s) {
        return [
            'id'    => (int)$s['id'],
            'slug'  => $s['slug'],
            'name'  => $s['site_name'],
            'kab'   => $s['kabupaten'],
            'kec'   => $s['kecamatan'],
            'lat'   => (float)$s['latitude'],
            'lon'   => (float)$s['longitude'],
            'depth' => $s['depth_m'] !== null ? (float)$s['depth_m'] : null,
            'src'   => $s['depth_source'],
            'shelter' => $s['shelter'],
            'species' => $s['recommended_species'],
            'score' => (int)$s['suitability_score'],
            'cls'   => $s['suitability_class'],
            'sd'    => (int)$s['score_depth'],
            'ss'    => (int)$s['score_shelter'],
            'sa'    => (int)$s['score_access'],
            'sc'    => (int)$s['score_conflict'],
            'constraint' => $s['constraints_note'],
            'notes' => $s['notes'],
        ];
    }, $sites),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-anchor"></i> Lokasi KJA &mdash; Banten</h1>
        <p class="page-desc">Titik-titik usulan lokasi yang cocok untuk budidaya <strong>KJA (Karamba Jaring
            Apung)</strong> di Provinsi Banten, dinilai dengan rubrik kesesuaian (kedalaman, kelindungan,
            akses, konflik ruang). Kedalaman tiap titik ditarik dari grid <strong>GEBCO</strong> dan bisa
            dicek ulang secara langsung ke <strong>BATNAS</strong> &amp; GEBCO.</p>
    </div>
    <div class="page-actions">
        <span class="source-note"><i class="fa-solid fa-database"></i> tabel kja_sites &middot; GEBCO 2020 &middot; WMS BATNAS/GEBCO</span>
    </div>
</div>

<div class="callout callout-info"><i class="fa-solid fa-circle-info"></i>
    <div><strong>Ringkasan hasil:</strong> dari <strong><?= count($sites) ?> titik</strong> yang dinilai di Banten,
        <strong><?= count(array_filter($sites, fn($s) => $s['suitability_class'] === 'S1')) ?> titik sangat cocok
        (S1)</strong> &mdash; Teluk Banten Barat &amp; Timur (Kab. Serang) dan Teluk Lada (Kab. Pandeglang);
        <strong><?= count(array_filter($sites, fn($s) => $s['suitability_class'] === 'S2')) ?> cukup cocok (S2)</strong>
        (Panimbang, Teluk Menyawak&ndash;Labuan, Carita, Pulau Tunda);
        <strong><?= count(array_filter($sites, fn($s) => $s['suitability_class'] === 'S3')) ?> kurang cocok</strong> dan
        <strong><?= count(array_filter($sites, fn($s) => $s['suitability_class'] === 'N')) ?> tidak direkomendasikan</strong>.
        Kedalaman ideal KJA laut kerapu: <strong>5&ndash;25 m</strong> (ideal 5&ndash;15 m).</div>
</div>

<div class="kja-layout">
    <!-- ===== Daftar titik ===== -->
    <div class="card kja-list-card">
        <div class="card-header">
            <div>
                <h3 class="card-title"><i class="fa-solid fa-list-check"></i> Daftar Titik</h3>
                <p class="card-sub">Urut skor tertinggi &middot; klik untuk loncat ke peta</p>
            </div>
        </div>
        <div class="card-body kja-filters no-print">
            <button type="button" class="btn btn-outline btn-sm kja-filter active" data-cls="all">Semua</button>
            <button type="button" class="btn btn-outline btn-sm kja-filter" data-cls="S1">S1</button>
            <button type="button" class="btn btn-outline btn-sm kja-filter" data-cls="S2">S2</button>
            <button type="button" class="btn btn-outline btn-sm kja-filter" data-cls="S3">S3</button>
            <button type="button" class="btn btn-outline btn-sm kja-filter" data-cls="N">N</button>
        </div>
        <ul class="kja-site-list" id="kjaSiteList">
            <?php foreach ($sites as $s): $m = $CLASS_META[$s['suitability_class']] ?? $CLASS_META['S3']; ?>
            <li class="kja-site-item" data-id="<?= (int)$s['id'] ?>" data-cls="<?= e($s['suitability_class']) ?>">
                <div class="kja-site-head">
                    <span class="kja-class-chip" style="background:<?= $m['color'] ?>"><?= e($s['suitability_class']) ?></span>
                    <strong class="kja-site-name"><?= e($s['site_name']) ?></strong>
                    <span class="kja-site-score"><?= (int)$s['suitability_score'] ?><small>/100</small></span>
                </div>
                <div class="kja-site-meta">
                    <span><i class="fa-solid fa-location-dot"></i> <?= e($s['kabupaten']) ?> &middot; <?= e($s['kecamatan'] ?: '—') ?></span>
                    <span><i class="fa-solid fa-ruler-vertical"></i> <?= $s['depth_m'] !== null ? fmt_num($s['depth_m'], 0) . ' m' : '—' ?> (GEBCO)</span>
                    <span><i class="fa-solid fa-fish"></i> <?= e(mb_strimwidth((string)$s['recommended_species'], 0, 60, '…')) ?></span>
                </div>
                <div class="score-bar" title="Kedalaman <?= (int)$s['score_depth'] ?> · Kelindungan <?= (int)$s['score_shelter'] ?> · Akses <?= (int)$s['score_access'] ?> · Konflik <?= (int)$s['score_conflict'] ?>">
                    <span style="width:<?= (int)$s['suitability_score'] ?>%;background:<?= $m['color'] ?>"></span>
                </div>
            </li>
            <?php endforeach; ?>
            <?php if (!$sites): ?>
            <li class="kja-site-item"><em>Belum ada data titik — jalankan migrasi <code>migrate_004_kja_sites.sql</code>.</em></li>
            <?php endif; ?>
        </ul>
    </div>

    <!-- ===== Peta ===== -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title"><i class="fa-solid fa-map-location-dot"></i> Peta Titik Usulan KJA Banten</h3>
                <p class="card-sub">Layer batimetri GEBCO tampil sebagai latar &middot; klik marker untuk detail titik</p>
            </div>
            <div class="card-tools">
                <div class="input-group">
                    <select class="form-control" id="kjaBasemap" style="width:170px">
                        <option value="imagery">Imagery (Esri)</option>
                        <option value="ocean">Laut (Esri Ocean)</option>
                        <option value="osm">OpenStreetMap</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="card-body" style="position:relative">
            <div class="map-box lg" id="kjaMap"><span class="map-coord" id="kjaCoord">—</span></div>
            <div class="no-print" style="display:flex;gap:16px;flex-wrap:wrap;margin-top:12px;align-items:center">
                <span class="form-label" style="margin:0">Layer:</span>
                <label style="display:inline-flex;gap:7px;align-items:center;font-size:13px;font-weight:600">
                    <input type="checkbox" class="kja-chk" data-layer="gebco21" checked>
                    <i class="fa-solid fa-oil-can" style="color:#2453C8"></i> Batimetri GEBCO 2021
                </label>
                <label style="display:inline-flex;gap:7px;align-items:center;font-size:13px;font-weight:600">
                    <input type="checkbox" class="kja-chk" data-layer="batnas">
                    <i class="fa-solid fa-flag" style="color:#C62828"></i> Grid BATNAS (area survei BIG)
                </label>
            </div>
        </div>
        <div class="map-legend">
            <?php foreach ($CLASS_META as $c => $m): ?>
            <span class="legend-item"><span class="kja-legend-dot" style="background:<?= $m['color'] ?>"></span> <?= $m['label'] ?></span>
            <?php endforeach; ?>
            <span class="legend-item"><i class="fa-solid fa-hand-pointer"></i> Klik marker &rarr; detail + cek kedalaman live (BATNAS &amp; GEBCO)</span>
        </div>
    </div>
</div>

<!-- ===== Tabel detail ===== -->
<div class="card">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-table-list"></i> Detail Penilaian Tiap Titik</h3>
            <p class="card-sub">Skor rubrik: kedalaman (maks 40) &middot; kelindungan (25) &middot; akses (15) &middot; konflik ruang (20)</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="table" id="kjaTable">
            <thead>
                <tr>
                    <th>Titik Usulan</th><th>Kabupaten / Kec.</th><th class="num">Kedalaman</th>
                    <th>Kelindungan</th><th class="num">Ked.</th><th class="num">Kel.</th><th class="num">Aks.</th><th class="num">Kon.</th>
                    <th class="num">Total</th><th>Kelas</th><th>Ikan Usulan</th><th>Kendala Utama</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($sites as $s): $m = $CLASS_META[$s['suitability_class']] ?? $CLASS_META['S3']; ?>
                <tr data-id="<?= (int)$s['id'] ?>">
                    <td class="table-strong"><?= e($s['site_name']) ?></td>
                    <td><?= e($s['kabupaten']) ?> / <?= e($s['kecamatan'] ?: '—') ?></td>
                    <td class="num"><?= $s['depth_m'] !== null ? fmt_num($s['depth_m'], 0) . ' m' : '—' ?></td>
                    <td><?= e($SHELTER_LABEL[$s['shelter']] ?? $s['shelter']) ?></td>
                    <td class="num"><?= (int)$s['score_depth'] ?></td>
                    <td class="num"><?= (int)$s['score_shelter'] ?></td>
                    <td class="num"><?= (int)$s['score_access'] ?></td>
                    <td class="num"><?= (int)$s['score_conflict'] ?></td>
                    <td class="num"><b><?= (int)$s['suitability_score'] ?></b></td>
                    <td><span class="kja-class-chip" style="background:<?= $m['color'] ?>"><?= e($s['suitability_class']) ?></span></td>
                    <td><?= e($s['recommended_species'] ?: '—') ?></td>
                    <td class="kja-td-note"><?= e($s['constraints_note'] ?: '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ===== Catatan analisis per titik ===== -->
<div class="card">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-file-lines"></i> Catatan Analisis per Titik</h3>
            <p class="card-sub">Ringkasan kondisi lapangan, potensi, dan peringatan</p>
        </div>
    </div>
    <div class="card-body kja-notes-grid">
        <?php foreach ($sites as $s): $m = $CLASS_META[$s['suitability_class']] ?? $CLASS_META['S3']; ?>
        <div class="kja-note-item" data-id="<?= (int)$s['id'] ?>">
            <div class="kja-note-head">
                <span class="kja-class-chip" style="background:<?= $m['color'] ?>"><?= e($s['suitability_class']) ?></span>
                <strong><?= e($s['site_name']) ?></strong>
            </div>
            <p><?= e($s['notes'] ?: '') ?></p>
            <p class="kja-note-acc"><i class="fa-solid fa-road"></i> <?= e($s['access_note'] ?: '—') ?></p>
            <p class="source-note"><i class="fa-solid fa-ruler-vertical"></i>
                <?= $s['depth_m'] !== null ? fmt_num($s['depth_m'], 0) . ' m' : '—' ?> &middot; <?= e($s['depth_source'] ?: '') ?></p>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ===== Rubrik & sumber ===== -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-top:16px">
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title"><i class="fa-solid fa-calculator"></i> Rubrik Kesesuaian</h3>
                <p class="card-sub">Total 100 poin &mdash; S1 &ge;90 &middot; S2 70&ndash;89 &middot; S3 50&ndash;69 &middot; N &lt;50</p>
            </div>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Kriteria</th><th class="num">Bobot</th><th>Skala nilai</th></tr></thead>
                <tbody>
                    <tr><td class="table-strong">Kedalaman</td><td class="num">40</td>
                        <td>5&ndash;15 m = 40 &middot; 15&ndash;25 m = 30 &middot; 3&ndash;5 m = 22 &middot; 25&ndash;35 m = 15 &middot; &gt;35 m = 8</td></tr>
                    <tr><td class="table-strong">Kelindungan gelombang</td><td class="num">25</td>
                        <td>Teluk tertutup = 25 &middot; semi terlindung = 15 &middot; perairan terbuka = 8</td></tr>
                    <tr><td class="table-strong">Akses &amp; infrastruktur</td><td class="num">15</td>
                        <td>&lt;5 km dari PPI/jalan = 15 &middot; 5&ndash;15 km = 12 &middot; &gt;15 km/hanya perahu = 8</td></tr>
                    <tr><td class="table-strong">Konflik ruang &amp; lingkungan</td><td class="num">20</td>
                        <td>Bebas = 18 &middot; minor = 14&ndash;16 &middot; tumpang tindih wisata = 10 &middot; jalur kapal/industri = 5 &middot; konservasi = 2</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title"><i class="fa-solid fa-book-bookmark"></i> Dasar Penilaian &amp; Sumber</h3>
                <p class="card-sub">Data publik yang dipakai</p>
            </div>
        </div>
        <div class="card-body" style="font-size:13px;color:var(--muted);line-height:1.75">
            <ul style="margin:0;padding-left:18px">
                <li><strong>Kedalaman:</strong> grid GEBCO 2020 (via OpenTopoData) — probe per titik; verifikasi ulang live via BATNAS (SIBATNAS BIG) + GEBCO pada peta.</li>
                <li><strong>Teluk Banten:</strong> studi kesesuaian budidaya kerapu menandai perairan sangat sesuai; kedalaman 5&ndash;25 m sesuai standar struktur KJA.</li>
                <li><strong>Pulau Tunda:</strong> analisis spasial perairan berpotensi untuk budidaya kerapu <em>(Epinephelus sp.)</em>.</li>
                <li><strong>Teluk Kec. Labuan:</strong> analisis ruang pesisir Pandeglang menyatakan layak untuk budidaya kerangan &amp; KJA.</li>
                <li><strong>Teluk Lada:</strong> kualitas perairan terkaji dalam publikasi riset perairan Teluk Lada, Pandeglang.</li>
                <li><strong>Anyer:</strong> pemetaan batimetri mencatat kedalaman 22&ndash;39 m; diperkuat probe GEBCO ±30 m.</li>
            </ul>
            <p style="margin-top:10px"><i class="fa-solid fa-triangle-exclamation" style="color:#E8A13D"></i>
                Titik ini adalah <strong>usulan</strong> berbasis data publik &mdash; wajib verifikasi lapangan
                (arus, salinitas, substrat) dan perizinan (KKP / DKP Provinsi Banten / tata ruang) sebelum pemanfaatan.</p>
        </div>
    </div>
</div>

<script>window.BOLU_KJA_SITES = <?= $sitesJson ?>;</script>

<?php
include __DIR__ . '/includes/footer.php';
?>
