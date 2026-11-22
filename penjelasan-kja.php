<?php
/**
 * BOLU — Penjelasan KJA
 * Halaman edukasi: apa itu Keramba Jaring Apung, anatomi, siklus budidaya,
 * parameter kualitas air, sistem pemantauan IoT, serta dukungan energi angin.
 * Konten bersifat informatif (bukan data sensor) — tidak ada angka terukur palsu.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$PAGE_ID    = 'penjelasan-kja';
$PAGE_TITLE = 'Penjelasan KJA';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Penjelasan KJA', null]];
$PAGE_SCRIPTS = [];
$NEED_CHART = false;

// Jenis ikan katalog (menu Jenis Ikan) — dipakai pada seksi "Ikan yang Umum Dibudidayakan"
try {
    $speciesList = db()->query(
        "SELECT * FROM fish_species WHERE is_active = 1 ORDER BY name ASC LIMIT 8"
    )->fetchAll() ?: [];
} catch (Throwable $t) {
    $speciesList = []; // database belum siap
}
$SP_CAT = ['laut' => 'laut', 'tawar' => 'tawar/waduk', 'brackish' => 'payau/muara'];
$SP_ICON_COLOR = ['#3090A8', '#178456', '#0276A0', '#E5484D', '#16394D', '#22A06B'];

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-book-open"></i> Penjelasan KJA</h1>
        <p class="page-desc">Panduan lengkap <strong>Keramba Jaring Apung (KJA)</strong>: pengertian, anatomi,
            siklus budidaya, kualitas air, sampai cara sistem <strong>BOLU</strong> membantu pembudidaya.</p>
    </div>
    <div class="page-actions">
        <span class="badge badge-blue"><i class="fa-solid fa-graduation-cap"></i> Materi Edukasi</span>
    </div>
</div>

<!-- NAVIGASI SEKSI -->
<div class="card">
    <div class="card-body kja-toc">
        <a href="#sek-definisi"><i class="fa-solid fa-circle-info"></i> Apa itu KJA</a>
        <a href="#sek-anatomi"><i class="fa-solid fa-diagram-project"></i> Anatomi Keramba</a>
        <a href="#sek-siklus"><i class="fa-solid fa-arrows-rotate"></i> Siklus Budidaya</a>
        <a href="#sek-ikan"><i class="fa-solid fa-fish"></i> Ikan Budidaya</a>
        <a href="#sek-air"><i class="fa-solid fa-droplet"></i> Kualitas Air</a>
        <a href="#sek-angin"><i class="fa-solid fa-wind"></i> KJA &amp; Energi Angin</a>
        <a href="#sek-manfaat"><i class="fa-solid fa-scale-balanced"></i> Manfaat &amp; Tantangan</a>
        <a href="#sek-faq"><i class="fa-solid fa-circle-question"></i> FAQ</a>
    </div>
</div>

<!-- ============ 1. DEFINISI ============ -->
<div class="card kja-section" id="sek-definisi">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-circle-info"></i> Apa itu KJA (Keramba Jaring Apung)?</h3>
        </div>
    </div>
    <div class="card-body">
        <p style="margin-top:0"><strong>Keramba Jaring Apung (KJA)</strong> adalah sistem budidaya ikan di mana ikan
            dipelihara di dalam <strong>wadah jaring yang mengapung</strong> di badan air — laut, teluk, waduk, atau
            danau. Berbeda dengan tambak (kolam yang digali di darat pesisir), keramba ditempatkan langsung di atas
            air sehingga air di dalam jaring <strong>selalu bertukar secara alami</strong> dengan air di sekitarnya.
            Pertukaran air inilah yang membuat ikan mendapat oksigen segar terus-menerus tanpa perlu aerator,
            sekaligus membuang sisa pakan dan kotoran keluar dari jaring.</p>
        <p>Konstruksi keramba terdiri atas <strong>kerangka apung</strong> (biasanya pipa HDPE, bambu, atau galvanis)
            yang menahan <strong>jaring budidaya</strong> terendang ke bawah air, <strong>pelampung</strong> agar
            seluruh sistem tetap di permukaan, serta <strong>jangkar dan tali tambat</strong> yang memegang keramba
            pada posisinya agar tidak hanyut. Di Indonesia pesisir, KJA menjadi salah satu model budidaya paling
            populer karena <em>lahan</em>-nya adalah permukaan air yang luas dan murah, panennya terjadwal, serta
            hasilnya mudah dipantau setiap hari.</p>
        <div class="callout callout-info">
            <i class="fa-solid fa-lightbulb"></i>
            <div>Istilah <strong>keramba</strong> lebih umum dipakai di Indonesia bagian barat, sementara
                <strong>karamba</strong> dipakai di Indonesia timur — keduanya merujuk pada konstruksi serupa.
                Nama proyek ini, <strong>BOLU</strong>, berarti keramba yang "pintar": dilengkapi sensor
                untuk suhu, pH, dan tegangan serta rancangan energi angin, sehingga kondisi keramba dapat dipantau lewat web.</div>
        </div>
    </div>
</div>

<!-- ============ 2. ANATOMI ============ -->
<div class="card kja-section" id="sek-anatomi">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-diagram-project"></i> Anatomi Keramba &amp; Sistem Energi Angin</h3>
            <p class="card-sub">Skema ilustratif: keramba jaring apung dan turbin angin untuk mendukung kelistrikan perangkat IoT</p>
        </div>
    </div>
    <div class="card-body">
        <figure class="kja-figure">
            <svg viewBox="0 0 960 470" role="img" aria-label="Skema keramba jaring apung dan turbin angin" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <marker id="arrB" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                        <path d="M0,0 L10,5 L0,10 z" fill="#3090A8"/>
                    </marker>
                    <marker id="arrG" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                        <path d="M0,0 L10,5 L0,10 z" fill="#5E7E90"/>
                    </marker>
                </defs>

                <!-- Langit & laut -->
                <rect x="0" y="0" width="960" height="235" fill="#EAF5FA"/>
                <rect x="0" y="235" width="960" height="235" fill="#D8DCDF"/>
                <path d="M0,235 Q30,227 60,235 T120,235 T180,235 T240,235 T300,235 T360,235 T420,235 T480,235 T540,235 T600,235 T660,235 T720,235 T780,235 T840,235 T900,235 T960,235"
                      fill="none" stroke="#3090A8" stroke-width="2.5"/>

                <!-- ============ KERAMBA (kiri) ============ -->
                <!-- Tali tambat & jangkar -->
                <line x1="150" y1="240" x2="255" y2="440" stroke="#5E7E90" stroke-width="2" stroke-dasharray="5 4"/>
                <line x1="370" y1="240" x2="265" y2="440" stroke="#5E7E90" stroke-width="2" stroke-dasharray="5 4"/>
                <rect x="238" y="436" width="42" height="14" rx="4" fill="#546E7A"/>
                <text x="259" y="461" text-anchor="middle" font-size="11.5" fill="#3090A8" font-weight="600">Jangkar</text>

                <!-- Jaring budidaya -->
                <path d="M152,246 L368,246 L330,425 L190,425 Z" fill="rgba(34,160,107,.10)" stroke="#22A06B" stroke-width="2.5" stroke-dasharray="7 5"/>
                <g stroke="#22A06B" stroke-width="1" opacity=".45" stroke-dasharray="4 4">
                    <line x1="192" y1="246" x2="215" y2="425"/>
                    <line x1="236" y1="246" x2="243" y2="425"/>
                    <line x1="282" y1="246" x2="278" y2="425"/>
                    <line x1="328" y1="246" x2="308" y2="425"/>
                    <line x1="168" y1="300" x2="352" y2="300"/>
                    <line x1="178" y1="350" x2="342" y2="350"/>
                    <line x1="184" y1="400" x2="336" y2="400"/>
                </g>

                <!-- Ikan di dalam jaring -->
                <g fill="#3090A8">
                    <g transform="translate(230,295)">
                        <ellipse rx="15" ry="7"/><polygon points="13,0 24,-8 24,8"/>
                        <circle cx="-7" cy="-2" r="1.6" fill="#0C2534"/>
                    </g>
                    <g transform="translate(300,330) scale(-1,1)">
                        <ellipse rx="17" ry="8"/><polygon points="15,0 27,-9 27,9"/>
                        <circle cx="-8" cy="-2" r="1.8" fill="#0C2534"/>
                    </g>
                    <g transform="translate(255,375) scale(-1,.9)">
                        <ellipse rx="12" ry="6"/><polygon points="10,0 20,-7 20,7"/>
                        <circle cx="-5" cy="-1.5" r="1.4" fill="#0C2534"/>
                    </g>
                    <g transform="translate(210,345)">
                        <ellipse rx="10" ry="5"/><polygon points="8,0 17,-6 17,6"/>
                    </g>
                </g>

                <!-- Pelampung -->
                <g fill="#0296C6" stroke="#0276A0" stroke-width="2">
                    <circle cx="140" cy="238" r="15"/>
                    <circle cx="230" cy="240" r="15"/>
                    <circle cx="320" cy="240" r="15"/>
                    <circle cx="388" cy="238" r="15"/>
                </g>

                <!-- Kerangka / jalan pit -->
                <rect x="112" y="216" width="304" height="17" rx="5" fill="#B08850" stroke="#8A683B" stroke-width="1.5"/>
                <rect x="104" y="200" width="320" height="8" rx="4" fill="#C99B62"/>
                <line x1="128" y1="216" x2="128" y2="208" stroke="#8A683B" stroke-width="4"/>
                <line x1="262" y1="216" x2="262" y2="208" stroke="#8A683B" stroke-width="4"/>
                <line x1="396" y1="216" x2="396" y2="208" stroke="#8A683B" stroke-width="4"/>

                <!-- ============ TURBIN ANGIN (kanan), ilustrasi konsep ============ -->
                <!-- Tiang turbin dan fondasi apung, bukan gambar ukuran teknik -->
                <rect x="630" y="229" width="118" height="19" rx="6" fill="#B08850" stroke="#8A683B" stroke-width="2"/>
                <circle cx="642" cy="246" r="12" fill="#0296C6" stroke="#0276A0" stroke-width="2"/>
                <circle cx="738" cy="246" r="12" fill="#0296C6" stroke="#0276A0" stroke-width="2"/>
                <path d="M646,257 L618,433 M732,257 L760,433" fill="none" stroke="#5E7E90" stroke-width="2" stroke-dasharray="5 4"/>
                <rect x="613" y="430" width="24" height="12" rx="3" fill="#546E7A"/>
                <rect x="749" y="430" width="24" height="12" rx="3" fill="#546E7A"/>
                <path d="M683,228 L680,123 L698,123 L695,228 Z" fill="#DDE9F0" stroke="#16394D" stroke-width="2"/>
                <rect x="680" y="109" width="40" height="18" rx="8" fill="#16394D"/>
                <circle cx="694" cy="118" r="9" fill="#0296C6" stroke="#0276A0" stroke-width="2"/>
                <!-- Tiga bilah turbin -->
                <path d="M694,118 L686,29 Q682,13 696,28 L706,111 Z" fill="#FFFFFF" stroke="#3090A8" stroke-width="2"/>
                <path d="M694,118 L774,151 Q788,158 767,159 L693,127 Z" fill="#FFFFFF" stroke="#3090A8" stroke-width="2"/>
                <path d="M694,118 L640,181 Q626,195 634,176 L683,113 Z" fill="#FFFFFF" stroke="#3090A8" stroke-width="2"/>
                <!-- Arah angin, ilustratif -->
                <path d="M486,81 Q529,61 578,81" fill="none" stroke="#3090A8" stroke-width="3" marker-end="url(#arrB)"/>
                <path d="M492,110 Q530,94 572,108" fill="none" stroke="#3090A8" stroke-width="2" marker-end="url(#arrB)"/>
                <!-- Jalur listrik ke unit kontrol -->
                <polyline points="700,212 802,212 802,277" fill="none" stroke="#16394D" stroke-width="2" stroke-dasharray="5 4"/>
                <rect x="761" y="277" width="85" height="58" rx="8" fill="#EDF7FA" stroke="#16394D" stroke-width="2"/>
                <text x="803" y="297" text-anchor="middle" font-size="11.5" fill="#16394D" font-weight="600">Kontrol IoT</text>
                <text x="803" y="314" text-anchor="middle" font-size="10" fill="#16394D">Suhu / pH / V</text>

                <!-- Label keramba -->
                <g font-size="12" font-weight="600" fill="#16394D">
                    <text x="264" y="52" text-anchor="middle">Kerangka &amp; jalan pit (loopa)</text>
                    <line x1="264" y1="58" x2="264" y2="196" stroke="#3090A8" stroke-width="1.4" stroke-dasharray="3 3"/>
                    <text x="104" y="296" text-anchor="end">Jaring budidaya</text>
                    <line x1="109" y1="292" x2="178" y2="292" stroke="#3090A8" stroke-width="1.4" stroke-dasharray="3 3"/>
                    <text x="118" y="254" text-anchor="end">Pelampung</text>
                    <line x1="122" y1="250" x2="129" y2="242" stroke="#3090A8" stroke-width="1.4" stroke-dasharray="3 3"/>
                    <text x="430" y="390" text-anchor="start">Ikan budidaya</text>
                    <line x1="425" y1="386" x2="322" y2="352" stroke="#3090A8" stroke-width="1.4" stroke-dasharray="3 3"/>
                </g>

                <!-- Label energi angin -->
                <g font-size="12" font-weight="600" fill="#16394D">
                    <text x="695" y="352" text-anchor="middle">Turbin angin (ilustrasi)</text>
                    <text x="544" y="44" text-anchor="middle">Aliran angin</text>
                    <text x="803" y="354" text-anchor="middle">Pemantauan suhu, pH, tegangan</text>
                </g>

                <!-- Watermark penjelasan -->
                <text x="480" y="20" text-anchor="middle" font-size="13" font-weight="700" fill="#16394D">SKEMA BOLU — Keramba Jaring Apung + Energi Angin (Ilustrasi)</text>
            </svg>
            <figcaption class="kja-legend">
                <span class="legend-chip"><span class="legend-swatch" style="background:#B08850"></span> Kerangka / loopa</span>
                <span class="legend-chip"><span class="legend-swatch" style="background:#22A06B"></span> Jaring budidaya</span>
                <span class="legend-chip"><span class="legend-swatch" style="background:#0296C6"></span> Pelampung</span>
                <span class="legend-chip"><span class="legend-swatch" style="background:#546E7A"></span> Jangkar &amp; tali</span>
                <span class="legend-chip"><span class="legend-swatch" style="background:#16394D"></span> Turbin angin</span>
                <span class="legend-chip"><span class="legend-swatch" style="background:#22A06B"></span> Sensor (hijau)</span>
            </figcaption>
        </figure>
        <!-- Model KJA 3D milik pengguna, diletakkan persis di bawah skema 2D. -->
        <section class="kja-3d" aria-labelledby="kja-3d-title">
            <div class="kja-3d-heading">
                <div>
                    <h4 id="kja-3d-title"><i class="fa-solid fa-cube"></i> Model 3D Keramba Jaring Apung</h4>
                    <p>Putar model dengan mouse atau jari, scroll/cubit untuk zoom, dan geser untuk melihat detailnya.</p>
                </div>
                <button type="button" class="btn btn-outline btn-sm" id="kja-reset-camera">
                    <i class="fa-solid fa-arrows-rotate"></i> Reset Tampilan
                </button>
            </div>
            <model-viewer id="kja-model" src="assets/models/kja-3d.glb"
                alt="Model tiga dimensi keramba jaring apung"
                camera-controls auto-rotate rotation-per-second="15deg"
                shadow-intensity="0.8" exposure="1"
                interaction-prompt="auto" loading="lazy"
                touch-action="pan-y" ar-status="not-presenting">
                <div class="kja-3d-progress" slot="progress-bar">Memuat model 3D KJA…</div>
            </model-viewer>
            <p class="kja-3d-note"><i class="fa-solid fa-circle-info"></i> Model interaktif · format GLB · bisa dilihat melalui laptop maupun HP.</p>
            <p id="kja-3d-error" class="kja-3d-error" role="alert" hidden>Model tidak dapat dimuat. Periksa apakah <code>assets/models/kja-3d.glb</code> sudah disalin dan koneksi internet tersedia.</p>
        </section>
    </div>
</div>
<!-- ============ 3. KOMPONEN ============ -->
<div class="card kja-section">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-cubes"></i> Komponen Utama Keramba</h3>
            <p class="card-sub">Enam bagian yang membangun satu unit keramba jaring apung yang aman</p>
        </div>
    </div>
    <div class="card-body">
        <div class="kja-grid">
            <div class="kja-comp"><i class="fa-solid fa-grip-lines" style="color:#B08850"></i>
                <h4>Kerangka &amp; jalan pit</h4>
                <p>Rangka apung berbentuk persegi/keliling dari pipa HDPE, bambu, atau besi galvanis. Berfungsi
                    sebagai bibir keramba sekaligus jalan kerja petani (loopa) untuk memberi pakan dan memantau ikan.</p></div>
            <div class="kja-comp"><i class="fa-solid fa-border-none" style="color:#22A06B"></i>
                <h4>Jaring budidaya</h4>
                <p>Jaring nylon/PE dengan ukuran mata jaring (mesh) menyesuaikan ukuran ikan. Bagian ini menjadi
                    "ruangan" ikan: air bebas mengalir masuk-keluar sambil menahan ikan tetap di dalam.</p></div>
            <div class="kja-comp"><i class="fa-solid fa-circle" style="color:#0296C6"></i>
                <h4>Pelampung</h4>
                <p>Drum plastik, styrofoam berbungkus, atau buoy HDPE yang dipasang mengelilingi kerangka agar
                    seluruh konstruksi tetap mengapung walau dibebani pakan, jaring, dan petani.</p></div>
            <div class="kja-comp"><i class="fa-solid fa-anchor" style="color:#546E7A"></i>
                <h4>Jangkar &amp; tali tambat</h4>
                <p>Beton, batu, atau jangkar besi di dasar perairan dengan tali tambat ke tiap sudut keramba agar
                    posisi tidak bergeser saat arus dan gelombang datang.</p></div>
            <div class="kja-comp"><i class="fa-solid fa-shield-halved" style="color:#E5484D"></i>
                <h4>Jaring pelindung</h4>
                <p>Lapisan jaring luar (antipredator) dan jaring penutup atas untuk menghalau predator seperti
                    predator ikan besar, burung, serta mencegah ikan melompat keluar.</p></div>
            <div class="kja-comp"><i class="fa-solid fa-house-tsunami" style="color:#3090A8"></i>
                <h4>Rumah jaga &amp; fasilitas</h4>
                <p>Bangunan apung untuk penyimpanan pakan, alat panen, dan tempat berjaga malam hari. Pada KJA
                    pintar, fasilitas dapat menjadi tempat perangkat sensor dan kendali energi angin.</p></div>
        </div>
    </div>
</div>

<!-- ============ 4. SIKLUS ============ -->
<div class="card kja-section" id="sek-siklus">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-arrows-rotate"></i> Siklus Budidaya KJA</h3>
            <p class="card-sub">Enam tahap yang berulang setiap musim produksi</p>
        </div>
    </div>
    <div class="card-body">
        <div class="timeline">
            <div class="tl-item"><div class="tl-dot">1</div><div class="tl-body">
                <h4>Persiapan keramba</h4>
                <p>Pemasangan kerangka, pelampung, dan jangkar; jaring dijemur dan diperiksa dari lubang. Air di
                    sekitar lokasi dipastikan bersih dan kedalaman cukup (umumnya minimal 3–5 meter di bawah dasar jaring).</p></div></div>
            <div class="tl-item"><div class="tl-dot">2</div><div class="tl-body">
                <h4>Penebaran benih</h4>
                <p>Benih berkualitas (ukuran seragam, gerak lincah) diaklimatisasi dengan menyamakan suhu wadah
                    angkut dan air keramba sebelum dilepas. Kepadatan tebar menyesuaikan volume jaring agar ikan tidak sesak.</p></div></div>
            <div class="tl-item"><div class="tl-dot">3</div><div class="tl-body">
                <h4>Pemeliharaan &amp; pemberian pakan</h4>
                <p>Pakan diberikan teratur (umumnya 2–3 kali sehari) dengan dosis menurun persentase biomassa.
                    Pakan adalah komponen biaya terbesar (60–70%) sehingga takaran yang tepat sangat menentukan untung-rugi.</p></div></div>
            <div class="tl-item"><div class="tl-dot">4</div><div class="tl-body">
                <h4>Monitoring kualitas air &amp; ikan</h4>
                <p>Suhu dan pH dipantau oleh sensor IoT BOLU dan ditampilkan pada halaman
                    <a href="kualitas-air.php">Kualitas Air</a>; tegangan digunakan untuk memantau kelistrikan perangkat.
                    Salinitas dan kejernihan dapat diperiksa terpisah bila tersedia alat ukur. Sampel ikan diperiksa secara berkala untuk pertumbuhan dan kesehatan.</p></div></div>
            <div class="tl-item"><div class="tl-dot">5</div><div class="tl-body">
                <h4>Sortir &amp; grading</h4>
                <p>Ikan disortir berdasarkan ukuran agar pertumbuhan merata dan mengurangi kanibalisme. Ikan dipindah
                    antar keramba sesuai ukuran; jaring dibersihkan dari kerak (fouling) agar air tetap mengalir lancar.</p></div></div>
            <div class="tl-item"><div class="tl-dot">6</div><div class="tl-body">
                <h4>Panen</h4>
                <p>Panen parsial (ambil yang besar, pelihara yang kecil) atau panen penuh sesuai permintaan pasar.
                    Durasi satu siklus tergantung spesies — lihat tabel ikan budidaya di bawah.</p></div></div>
        </div>
    </div>
</div>

<!-- ============ 5. IKAN ============ -->
<div class="card kja-section" id="sek-ikan">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-fish"></i> Jenis Ikan di KJA Anda</h3>
            <p class="card-sub">Katalog dari menu <a href="jenis-ikan.php">Jenis Ikan</a> — durasi adalah acuan umum budidaya Indonesia, dapat disesuaikan per jenis</p>
        </div>
        <div class="card-tools">
            <a class="btn btn-outline btn-sm" href="jenis-ikan.php"><i class="fa-solid fa-pen"></i> Kelola Jenis Ikan</a>
        </div>
    </div>
    <div class="card-body">
        <?php if (!$speciesList): ?>
            <div class="empty-state"><i class="fa-solid fa-fish"></i>
                <p>Belum ada jenis ikan pada katalog. Tambahkan lewat menu <a href="jenis-ikan.php">Jenis Ikan</a>.</p></div>
        <?php else: ?>
        <div class="kja-grid">
            <?php foreach ($speciesList as $i => $sp): $clr = $SP_ICON_COLOR[$i % count($SP_ICON_COLOR)]; ?>
            <div class="kja-comp kja-fish"><i class="fa-solid fa-<?= $i % 2 === 0 ? 'fish-fins' : 'fish' ?>" style="color:<?= e($clr) ?>"></i>
                <h4><?= e($sp['name']) ?> <small>(<?= e($SP_CAT[$sp['category']] ?? $sp['category']) ?>)</small></h4>
                <?php if (!empty($sp['latin_name'])): ?><p style="font-style:italic;margin-bottom:4px"><?= e($sp['latin_name']) ?></p><?php endif; ?>
                <p><?= $sp['description'] ? e($sp['description']) : 'Belum ada deskripsi.' ?></p>
                <?php if ($sp['cycle_months_min'] !== null): ?>
                <p style="margin-top:6px"><strong>Siklus panen:</strong> <?= (int)$sp['cycle_months_min'] ?>–<?= (int)$sp['cycle_months_max'] ?> bulan
                    <?= $sp['target_weight_kg'] !== null ? ' · ± ' . fmt_num((float)$sp['target_weight_kg'], 2) . ' kg/ekor' : '' ?></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <p class="form-hint" style="margin-top:12px"><i class="fa-solid fa-circle-info"></i>
            Pilihan spesies menyesuaikan lokasi keramba Anda (laut, teluk, waduk). Parameter kualitas air
            ideal pada tabel di bawah adalah acuan umum budidaya laut tropis.</p>
    </div>
</div>
<!-- ============ 6. KUALITAS AIR ============ -->
<div class="card kja-section" id="sek-air">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-droplet"></i> Parameter Kualitas Air yang Penting</h3>
            <p class="card-sub">Acuan umum budidaya laut tropis — angka ideal dapat berbeda per spesies</p>
        </div>
    </div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Parameter</th><th>Rentang Ideal</th><th>Arti bagi Ikan</th><th>Pantau di Sistem</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="table-strong"><i class="fa-solid fa-temperature-half" style="color:#E5484D"></i> Suhu air</td>
                        <td class="num">27–31 °C</td>
                        <td>Mengatur metabolisme &amp; nafsu makan; perubahan mendadak membuat ikan stres.</td>
                        <td><a href="kualitas-air.php">Kualitas Air</a></td>
                    </tr>
                    <tr>
                        <td class="table-strong"><i class="fa-solid fa-flask" style="color:#22A06B"></i> pH</td>
                        <td class="num">7,5–8,5</td>
                        <td>Keseimbangan asam-basa air. Nilai di luar toleransi spesies dapat memengaruhi fungsi insang dan kondisi fisiologis ikan.</td>
                        <td><a href="kualitas-air.php">Kualitas Air</a></td>
                    </tr>
                    <tr>
                        <td class="table-strong"><i class="fa-solid fa-water" style="color:#16394D"></i> Salinitas</td>
                        <td class="num">25–32 ppt</td>
                        <td>Osmoregulasi tubuh ikan laut. Berubah drastis saat hujan lebat menurunkan salinitas permukaan.</td>
                        <td><span class="source-note">Belum dipantau sensor BOLU</span></td>
                    </tr>
                    <tr>
                        <td class="table-strong"><i class="fa-solid fa-eye" style="color:#5E7E90"></i> Kekeruhan</td>
                        <td class="num">&lt; 20 NTU</td>
                        <td>Air keruh menutup cahaya &amp; menyumbat insang; biasanya naik setelah gelombang besar mengaduk dasar.</td>
                        <td><span class="source-note">Belum dipantau sensor BOLU</span></td>
                    </tr>
                    <tr>
                        <td class="table-strong"><i class="fa-solid fa-biohazard" style="color:#0276A0"></i> Amonia (NH<sub>3</sub>)</td>
                        <td class="num">&lt; 0,1 mg/L</td>
                        <td>Racun dari sisa pakan &amp; kotoran. Pertukaran air alami keramba membantu menjaganya rendah.</td>
                        <td><span class="source-note">butuh sensor khusus</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="callout callout-info" style="margin-top:14px">
            <i class="fa-solid fa-circle-info"></i>
            <div><strong>Parameter perangkat BOLU saat ini:</strong> suhu air, pH, dan tegangan listrik. Suhu dan pH digunakan untuk
                pemantauan kualitas air, sedangkan tegangan untuk memeriksa kondisi pasokan listrik perangkat.
                Salinitas, kekeruhan, dan amonia merupakan parameter penting budidaya, tetapi <strong>belum diukur oleh perangkat BOLU</strong>.
                Informasi angin BMKG di Peta Batimetri merupakan data eksternal, bukan sensor angin IoT.</div>
        </div>

    </div>
</div>

<!-- ============ 7. KJA & ENERGI ANGIN ============ -->
<div class="card kja-section" id="sek-angin">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-wind"></i> KJA + Energi Angin: Mendukung Monitoring IoT</h3>
            <p class="card-sub">Konsep sumber listrik terbarukan untuk sensor suhu, pH, dan tegangan</p>
        </div>
    </div>
    <div class="card-body">
        <p style="margin-top:0">Sistem BOLU dikembangkan untuk mendukung pemantauan kondisi KJA dengan perangkat IoT.
            <strong>Energi angin</strong> memanfaatkan putaran turbin akibat pergerakan udara dan mengubahnya menjadi listrik melalui generator.
            Tegangan listrik dipantau agar perubahan kondisi suplai energi dapat diketahui.
            Ketersediaan daya bergantung pada angin, karakteristik turbin, rangkaian kendali, dan penyimpanan energi bila terpasang.</p>
        <div class="flow-steps">
            <div class="flow-step"><span class="fs-num">1</span><i class="fa-solid fa-wind"></i><h5>Angin bertiup</h5><p>Aliran udara menggerakkan bilah turbin.</p></div>
            <div class="flow-arrow"><i class="fa-solid fa-angle-right"></i></div>
            <div class="flow-step"><span class="fs-num">2</span><i class="fa-solid fa-fan"></i><h5>Turbin berputar</h5><p>Energi kinetik angin menjadi gerakan mekanis.</p></div>
            <div class="flow-arrow"><i class="fa-solid fa-angle-right"></i></div>
            <div class="flow-step"><span class="fs-num">3</span><i class="fa-solid fa-bolt"></i><h5>Generator</h5><p>Gerakan diubah menjadi energi listrik.</p></div>
            <div class="flow-arrow"><i class="fa-solid fa-angle-right"></i></div>
            <div class="flow-step"><span class="fs-num">4</span><i class="fa-solid fa-sliders"></i><h5>Pengaturan daya</h5><p>Tegangan diatur sesuai kebutuhan perangkat; penyimpanan bersifat opsional.</p></div>
            <div class="flow-arrow"><i class="fa-solid fa-angle-right"></i></div>
            <div class="flow-step"><span class="fs-num">5</span><i class="fa-solid fa-temperature-half"></i><h5>Sensor bekerja</h5><p>Perangkat memantau suhu air, pH, dan tegangan.</p></div>
            <div class="flow-arrow"><i class="fa-solid fa-angle-right"></i></div>
            <div class="flow-step"><span class="fs-num">6</span><i class="fa-solid fa-tower-broadcast"></i><h5>Data ke BOLU</h5><p>Data sensor dikirim untuk ditampilkan di website dan dianalisis.</p></div>
        </div>
        <div class="grid-2" style="margin-top:16px">
            <div class="callout callout-success">
                <i class="fa-solid fa-microchip"></i>
                <div><strong>Parameter yang dipantau perangkat:</strong> suhu air (°C), pH, dan tegangan (V).
                    Hasil monitoring ditampilkan pada halaman <a href="kualitas-air.php">Kualitas Air</a> dan modul terkait.</div>
            </div>
            <div class="callout callout-info">
                <i class="fa-solid fa-cloud-sun"></i>
                <div><strong>Informasi angin dari BMKG:</strong> prakiraan kecepatan dan arah angin dapat ditinjau di
                    <a href="batimetri.php">Peta Batimetri</a>. Data tersebut berasal dari layanan eksternal dan
                    tidak sama dengan pengukuran angin di lokasi keramba.</div>
            </div>
        </div>
    </div>
</div>

<!-- ============ 8. MANFAAT & TANTANGAN ============ -->
<div class="card kja-section" id="sek-manfaat">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-scale-balanced"></i> Manfaat &amp; Tantangan KJA</h3>
        </div>
    </div>
    <div class="card-body">
        <div class="grid-2">
            <div>
                <h4 class="kja-h4" style="color:var(--green-dark)"><i class="fa-solid fa-circle-check"></i> Manfaat</h4>
                <ul class="kja-list kja-list-good">
                    <li><strong>Hemat lahan darat</strong> — memanfaatkan permukaan air yang luas &amp; gratis.</li>
                    <li><strong>Air bertukar alami</strong> — oksigen segar terus mengalir tanpa aerator listrik besar.</li>
                    <li><strong>Panen terjadwal &amp; mudah</strong> — ikan terkurung di satu wadah, bisa panen parsial.</li>
                    <li><strong>Produktivitas tinggi</strong> per m<sup>2</sup> dibanding tangkapan liar.</li>
                    <li><strong>Mudah dipantau harian</strong> — sifat khas keluarga nelayan pesisir.</li>
                    <li><strong>Mendukung energi terbarukan</strong> — perangkat monitoring dapat dirancang memakai suplai tenaga angin.</li>
                </ul>
            </div>
            <div>
                <h4 class="kja-h4" style="color:var(--red-dark)"><i class="fa-solid fa-triangle-exclamation"></i> Tantangan</h4>
                <ul class="kja-list kja-list-risk">
                    <li><strong>Gelombang &amp; cuaca ekstrem</strong> — badai dapat merusak jaring/kerangka; informasi prakiraan cuaca membantu kewaspadaan.</li>
                    <li><strong>Penyakit &amp; hama</strong> — kepadatan tinggi mempermudah penularan; butuh pengawasan ketat.</li>
                    <li><strong>Biaya pakan</strong> — 60–70% biaya produksi; takaran salah langsung menggerus untung.</li>
                    <li><strong>Kualitas air berubah cepat</strong> — hujan lebat menurunkan salinitas &amp; pH mendadak.</li>
                    <li><strong>Pencemaran dari luar</strong> — limbah yang lewat dapat menimpa lokasi keramba.</li>
                    <li><strong>Investasi awal</strong> — kerangka, jaring, dan jangkar menuntut modal di awal musim.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- ============ 9. FAQ ============ -->
<div class="card kja-section" id="sek-faq">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-circle-question"></i> Pertanyaan Umum (FAQ)</h3>
        </div>
    </div>
    <div class="card-body">
        <div class="faq">
            <details open>
                <summary>Apa perbedaan keramba, karamba, dan tambak? <i class="fa-solid fa-chevron-down chev"></i></summary>
                <div class="faq-a">Keramba dan karamba adalah budidaya dalam <strong>jaring yang diletakkan di badan air</strong>
                    (laut/teluk/waduk) — beda istilah hanya karena kebiasaan daerah. Tambak adalah <strong>kolam yang digali
                    di tanah pesisir</strong> dengan pintu air untuk pergantian air pasang-surut. Karena berada di badan
                    air terbuka, keramba tidak memerlukan pemompaan air seperti tambak.</div>
            </details>
            <details>
                <summary>Berapa lama satu siklus budidaya di KJA? <i class="fa-solid fa-chevron-down chev"></i></summary>
                <div class="faq-a">Tergantung spesies dan suhu air: bandeng, kakap putih, kerapu macan, dan bawal bintang memiliki durasi berbeda-beda
                    menurut ukuran benih, kondisi air, pola pemberian pakan, dan target bobot panen. Banyak pembudidaya memakai panen parsial agar arus kas lebih lancar
                    dan keramba tidak kosong terlalu lama.</div>
            </details>
            <details>
                <summary>Apa itu energi angin dan apa manfaatnya untuk KJA? <i class="fa-solid fa-chevron-down chev"></i></summary>
                <div class="faq-a">Energi angin menggerakkan bilah turbin yang terhubung ke generator listrik.
                    Pada rancangan BOLU, pasokan energi ini mendukung pengoperasian perangkat IoT dan pemantauan kondisi KJA.
                    Daya yang tersedia bergantung pada kondisi angin dan spesifikasi kelistrikan.</div>
            </details>
            <details>
                <summary>Parameter apa saja yang diukur perangkat BOLU? <i class="fa-solid fa-chevron-down chev"></i></summary>
                <div class="faq-a">Perangkat BOLU mengukur <strong>suhu air (°C), pH, dan tegangan listrik (V)</strong>.
                    Suhu dan pH menggambarkan kondisi kualitas air, sedangkan tegangan menunjukkan kondisi kelistrikan perangkat.
                    Kecepatan dan arah angin dari BMKG adalah <strong>prakiraan eksternal</strong>, bukan pembacaan sensor perangkat.</div>
            </details>
            <details>
                <summary>Bagaimana jika angin melemah? <i class="fa-solid fa-chevron-down chev"></i></summary>
                <div class="faq-a">Saat angin melemah, energi dari turbin dapat berkurang. Jika terdapat baterai penyimpanan,
                    cadangan energi dapat membantu menjalankan sistem. Pemantauan tegangan membantu melihat perubahan kondisi suplai,
                    sedangkan status usia data pada website membantu mengetahui apakah pengukuran masih terbaru.</div>
            </details>

            <details>
                <summary>Apakah angka-angka di dashboard ini data nyata? <i class="fa-solid fa-chevron-down chev"></i></summary>
                <div class="faq-a">Angka pada BOLU dapat berasal dari sensor IoT, data historis atau simulasi, validasi manual, dan sumber eksternal.
                    Data suhu, pH, dan tegangan berasal dari perangkat ketika terhubung; periksa sumber serta waktu pembaruan setiap data. Setiap data
                    memiliki indikator usia: <span class="badge badge-green">LIVE</span> saat segar dan
                    <span class="badge badge-gray">DELAYED/OFFLINE</span> ketika sudah lama tidak diperbarui.
                    Klasifikasi FishCount menggunakan EfficientNetV2 untuk mengenali spesies pada gambar,
                    <strong>bukan menghitung jumlah ikan secara otomatis</strong>. Hasil harus dibaca sesuai jenis pengukuran dan status sumber datanya.</div>
            </details>
        </div>
    </div>
</div>

<div class="callout callout-success" style="margin-top:20px">
    <i class="fa-solid fa-seedling"></i>
    <div>Mau lanjut mempraktikkan? Buka <a href="dashboard.php">Dashboard</a> untuk ringkasan kondisi terkini,
        <a href="kualitas-air.php">Kualitas Air</a> untuk memeriksa suhu dan pH,
        <a href="batimetri.php">Peta Batimetri</a> untuk melihat informasi angin BMKG,
        atau <a href="fishcount.php">FishCount AI</a> untuk mencoba klasifikasi foto ikan.</div>
</div>

<style>
.kja-3d { margin-top:24px; border:1px solid #d6e5ed; background:linear-gradient(155deg,#f4fbff,#e8f3f8); border-radius:14px; overflow:hidden; }
.kja-3d-heading { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:18px 20px 10px; flex-wrap:wrap; }
.kja-3d-heading h4 { margin:0 0 5px; color:#16394d; font-size:17px; }
.kja-3d-heading h4 i { color:#0296c6; margin-right:7px; }
.kja-3d-heading p,.kja-3d-note { margin:0; font-size:13px; color:#5e7e90; }
.kja-3d model-viewer { display:block; width:100%; height:480px; background:radial-gradient(ellipse at center,#fff 0%,#e0eef5 100%); --poster-color:transparent; }
.kja-3d-progress { padding:12px; text-align:center; color:#476777; font-size:14px; }
.kja-3d-note { padding:12px 20px 17px; }
.kja-3d-error { margin:0 20px 18px; padding:12px; border-radius:8px; background:#fff1f1; color:#a22; }
@media (max-width:650px) { .kja-3d model-viewer { height:340px; } .kja-3d-heading { padding:15px; } }
</style>
<!-- model-viewer memakai WebGL dan JavaScript module; model GLB tetap tersimpan lokal di XAMPP. -->
<script type="module" src="https://ajax.googleapis.com/ajax/libs/model-viewer/3.5.0/model-viewer.min.js"></script>
<script>
(function () {
    var model = document.getElementById('kja-model');
    var reset = document.getElementById('kja-reset-camera');
    var error = document.getElementById('kja-3d-error');
    if (!model) return;
    if (reset) reset.addEventListener('click', function () {
        model.cameraOrbit = 'auto auto auto';
        model.fieldOfView = 'auto';
        model.jumpCameraToGoal();
    });
    model.addEventListener('error', function () { if (error) error.hidden = false; });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
