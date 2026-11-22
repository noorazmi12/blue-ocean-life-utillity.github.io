-- =====================================================================
-- SMART-KJA OWC — Migration 003: Hapus parameter DO (Dissolved Oxygen)
-- Parameter DO dikeluarkan dari sistem: kolom data, ambang, peringatan,
-- dan kolom referensi DO pada katalog jenis ikan.
-- Aman dijalankan ulang? Tidak — sekali saja (lihat catatan di bawah).
-- =====================================================================

-- 1. Hapus kolom DO dari tabel pengukuran kualitas air
ALTER TABLE `water_measurements` DROP COLUMN `do_mg_l`;

-- 2. Hapus ambang DO dari tabel thresholds (semua spesies)
DELETE FROM `thresholds` WHERE `parameter` = 'do_mg_l';

-- 3. Hapus riwayat peringatan yang merujuk parameter DO
DELETE FROM `alerts` WHERE `parameter` = 'do_mg_l';

-- 4. Hapus kolom referensi DO minimum pada katalog jenis ikan
ALTER TABLE `fish_species` DROP COLUMN `optimal_do_min`;
