-- =====================================================================
-- BOLU — migrate_004_kja_sites.sql
-- Tabel titik lokasi yang cocok untuk budidaya KJA (karamba jaring apung)
-- di Provinsi Banten + data seed 9 titik usulan.
--
-- Kedalaman per titik ditarik langsung dari grid GEBCO 2020 (OpenTopoData,
-- probe per titik) dan dapat diverifikasi ulang secara live lewat
-- api/bathymetry-point.php (BATNAS SIBATNAS BIG + GEBCO).
--
-- Rubrik skor (total 100):
--   score_depth   40  kedalaman  : 5–15 m=40, 15–25 m=30, 3–5 m=22,
--                                  25–35 m=15, >35 m=8
--   score_shelter 25  kelindungan: teluk tertutup=25, semi=15, terbuka=8
--   score_access  15  akses      : <5 km=15, 5–15 km=12, >15 km/perahu=8
--   score_conflict 20 konflik    : bebas=18, minor=14/16, wisata=10,
--                                  jalur kapal/industri=5, konservasi=2
-- Kelas: S1 sangat cocok (>=90), S2 cukup cocok (70–89),
--        S3 kurang cocok (50–69), N tidak direkomendasikan (<50)
-- =====================================================================

CREATE TABLE IF NOT EXISTS kja_sites (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug             VARCHAR(64)  NOT NULL UNIQUE,
    site_name        VARCHAR(160) NOT NULL,
    kabupaten        VARCHAR(60)  NOT NULL,
    kecamatan        VARCHAR(60)  NULL,
    latitude         DECIMAL(9,6) NOT NULL,
    longitude        DECIMAL(9,6) NOT NULL,
    depth_m          DECIMAL(6,1) NULL,
    depth_source     VARCHAR(160) NULL,
    shelter          ENUM('teluk','semi','terbuka') NOT NULL DEFAULT 'semi',
    access_note      VARCHAR(255) NULL,
    recommended_species VARCHAR(255) NULL,
    score_depth      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    score_shelter    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    score_access     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    score_conflict   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    suitability_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
    suitability_class ENUM('S1','S2','S3','N') NOT NULL DEFAULT 'S3',
    constraints_note VARCHAR(255) NULL,
    notes            TEXT NULL,
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO kja_sites
    (slug, site_name, kabupaten, kecamatan, latitude, longitude, depth_m,
     depth_source, shelter, access_note, recommended_species,
     score_depth, score_shelter, score_access, score_conflict,
     suitability_score, suitability_class, constraints_note, notes)
VALUES
('teluk-banten-barat',
 'Teluk Banten Barat — depan Karangantu–Panisbang',
 'Kab. Serang', 'Kramatwatu/Karangantu', -5.985000, 106.155000, 8.0,
 'GEBCO 2020 via OpenTopoData (probe langsung)', 'teluk',
 '±5–6 km dari PPI Karangantu; perahu dari pantai Karangantu/Kemaren',
 'Kerapu macan, kerapu cantang, bandeng laut, kakap putih',
 40, 25, 12, 18, 95, 'S1',
 'Gelombang di mulut teluk naik saat musim barat — tetap jauh lebih terlindung dibanding pantai barat Banten.',
 'Teluk tertutup dengan kedalaman 7–8 m di bagian tengah — tepat pada rentang ideal KJA laut (5–15 m). Telah menjadi zona KJA masyarakat untuk kerapu dan bandeng; studi kesesuaian budidaya kerapu menandai Teluk Banten sebagai kawasan sangat sesuai. Dekat PPI Karangantu memudahkan logistik benih, pakan, dan panen.'),

('teluk-banten-timur',
 'Teluk Banten Timur — depan Bojonegara–Pulau Dua',
 'Kab. Serang', 'Bojonegara/Pulosari', -5.990000, 106.180000, 7.0,
 'GEBCO 2020 via OpenTopoData (probe langsung)', 'teluk',
 '±2–3 km dari pantai Bojonegara/Jarai, dekat jalan raya utara Kab. Serang',
 'Kerapu macan, kerapu cantang, kakap putih',
 40, 25, 15, 14, 94, 'S1',
 '±3 km dari Suaka Margasatwa Pulau Dua (hutan mangrove) — jaga jarak dari zona mangrove dan rutin pantau kualitas air.',
 'Bagian timur Teluk Banten dengan kedalaman ±7 m dan perairan terlindung. Akses darat paling mudah di antara titik Teluk Banten. Muara sungai di sekitar teluk membawa nutrien — sensor pH dan suhu BOLU cocok dipasang di zona ini untuk pemantauan rutin.'),

('teluk-lada',
 'Teluk Lada — mulut teluk (depan Menewu–Sindangsari)',
 'Kab. Pandeglang', 'Panimbang', -6.503000, 105.775000, 15.0,
 'GEBCO 2020 via OpenTopoData (probe langsung)', 'teluk',
 '±2–4 km dari jalan desa Menewu/Sindangsari; dermaga desa di pantai timur teluk',
 'Kerapu cantang, kakap putih, bawal laut',
 40, 25, 10, 18, 93, 'S1',
 'Akses jalan desa lebih terbatas; gelombang di mulut teluk saat angin barat daya.',
 'Teluk tertutup di barat daya Pandeglang yang terlindung dari gelombang Selat Sunda oleh ujung Tanjung Lesung. Kualitas perairannya sudah terkaji dalam publikasi riset perairan Teluk Lada. Kedalaman 12–15 m di mulut teluk — pas untuk KJA kerapu.'),

('panimbang-barat',
 'Perairan Panimbang Barat',
 'Kab. Pandeglang', 'Panimbang', -6.490000, 105.730000, 9.0,
 'GEBCO 2020 via OpenTopoData (probe langsung)', 'semi',
 '±3–5 km dari Pantai Panimbang & pelabuhan; perahu nelayan tersedia',
 'Kerapu cantang, kerapu macan, kakap putih',
 40, 15, 15, 18, 88, 'S2',
 'Menghadap barat laut — gelombang naik saat musim barat; bukan teluk tertutup penuh.',
 'Perairan di depan Kecamatan Panimbang yang sudah aktif berbudidaya KJA kerapu cantang. Kedalaman ±9 m ideal untuk jaring apung; ketersediaan benih, pakan, dan tenaga kerja budidaya setempat memudahkan operasional.'),

('teluk-menyawak-labuan',
 'Teluk Menyawak — Labuan',
 'Kab. Pandeglang', 'Labuan', -6.360000, 105.815000, 10.0,
 'GEBCO 2020 via OpenTopoData (probe langsung)', 'semi',
 '±2–3 km dari PPI Labuan & dermaga; jalan provinsi langsung',
 'Kerapu macan, kakap putih, bandeng',
 40, 15, 15, 16, 86, 'S2',
 'Transit kapal/perahu Labuan–Umang; pilih area di belakang gugus Pulau Menyawak.',
 'Studi tata ruang pesisir Pandeglang menandai perairan Teluk Kecamatan Labuan layak untuk budidaya kerangan dan keramba jaring apung. Kedalaman 10–14 m; kedekatan PPI Labuan memudahkan logistik ikan dan pakan.'),

('carita',
 'Perairan Carita',
 'Kab. Pandeglang', 'Carita', -6.330000, 105.820000, 12.0,
 'GEBCO 2020 via OpenTopoData (probe langsung)', 'semi',
 'Di depan pantai wisata Carita; jalan raya Anyer–Panimbang',
 'Kerapu macan, kakap putih',
 40, 15, 15, 10, 80, 'S2',
 'Tumpang tindih zona wisata bahari Carita — perlu koordinasi tata ruang perikanan–pariwisata.',
 'Kedalaman 12–16 m pada 1–2 km dari pantai. Gelombang Selat Sunda moderat; area lebih tenang di sisi utara dekat gugus karang. Layak budidaya dengan catatan tata ruang wisata.'),

('pulau-tunda',
 'Perairan Pulau Tunda',
 'Kab. Serang', 'Wargasara', -5.815000, 106.250000, 15.0,
 'GEBCO 2020 via OpenTopoData (probe langsung)', 'semi',
 'Akses perahu ±15 menit dari Desa Wargasara',
 'Kerapu cantang, kerapu macan',
 40, 15, 8, 16, 79, 'S2',
 'Perairan lebih terbuka (sisi barat menghadap laut lepas); logistik via perahu dari Wargasara.',
 'Analisis spasial menandai perairan Pulau Tunda berpotensi untuk budidaya kerapu (Epinephelus sp.) namun belum optimal karena minim informasi. Kedalaman 15–16 m di sisi barat daya pulau — batas atas rentang ideal; cocok untuk kerapu dengan sistem jangkar memadai.'),

('teluk-sumur',
 'Teluk Sumur — buffer TN Ujung Kulon',
 'Kab. Pandeglang', 'Sumur', -6.650000, 105.570000, 5.0,
 'GEBCO 2020 via OpenTopoData (probe langsung)', 'semi',
 'Dekat Desa Sumur/Sumberjaya; jalan menuju TNUK',
 '— (perlu perizinan konservasi)',
 40, 15, 12, 2, 69, 'S3',
 'Berbatasan Taman Nasional Ujung Kulon — tata ruang konservasi membatasi budidaya; perlu izin & koordinasi pengelola TNUK.',
 'Kedalaman ±5 m tepat di batas bawah ideal, namun kedekatan zona konservasi TNUK (Pulau Panaitan dan perairannya) menjadikan titik ini kurang cocok untuk KJA komersial tanpa proses perizinan panjang.'),

('anyer',
 'Perairan Anyer',
 'Kab. Serang', 'Anyer', -6.085000, 105.875000, 30.0,
 'GEBCO 2020 via OpenTopoData + survei batimetri Anyer (22–39 m)', 'terbuka',
 'Di depan pantai wisata Anyer; akses sangat mudah',
 '— (tidak direkomendasikan)',
 15, 8, 15, 5, 43, 'N',
 'Jalur pelayaran feri Merak–Bakauheni & lalu lintas kapal Selat Sunda; zona wisata pantai; dasar perairan curam.',
 'Lereng bawah laut Anyer menurun cepat: survei batimetri perairan Anyer mencatat 22–39 m dan GEBCO menunjukkan ±30 m hanya sekitar 1 km dari pantai. Kombinasi jalur kapal, zona wisata, dan kedalaman menjadikan titik ini tidak direkomendasikan untuk KJA.')
ON DUPLICATE KEY UPDATE
    site_name=VALUES(site_name), kabupaten=VALUES(kabupaten), kecamatan=VALUES(kecamatan),
    latitude=VALUES(latitude), longitude=VALUES(longitude), depth_m=VALUES(depth_m),
    depth_source=VALUES(depth_source), shelter=VALUES(shelter), access_note=VALUES(access_note),
    recommended_species=VALUES(recommended_species), score_depth=VALUES(score_depth),
    score_shelter=VALUES(score_shelter), score_access=VALUES(score_access),
    score_conflict=VALUES(score_conflict), suitability_score=VALUES(suitability_score),
    suitability_class=VALUES(suitability_class), constraints_note=VALUES(constraints_note),
    notes=VALUES(notes), updated_at=CURRENT_TIMESTAMP;
