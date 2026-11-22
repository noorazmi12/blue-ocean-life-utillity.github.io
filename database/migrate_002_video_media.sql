-- ---------------------------------------------------------------------
-- SMART-KJA OWC — Migrasi 002: dukungan video FishCount
-- Menambah kolom media pada tabel fishcount_results:
--   media_type       : 'image' (bawaan) atau 'video'
--   media_duration_s : durasi video (detik) dari ffprobe, NULL utk gambar
--   frames_analyzed  : jumlah frame yang benar-benar dianalisis AI (jujur)
--   thumb_path       : poster/frame tengah video (JPG) utk thumbnail
-- Semua kolom boleh NULL / berdefault, aman untuk data lama.
-- ---------------------------------------------------------------------

ALTER TABLE `fishcount_results`
  ADD COLUMN `media_type`       ENUM('image','video') NOT NULL DEFAULT 'image' AFTER `image_path`,
  ADD COLUMN `media_duration_s` INT UNSIGNED NULL DEFAULT NULL AFTER `media_type`,
  ADD COLUMN `frames_analyzed`  INT UNSIGNED NULL DEFAULT NULL AFTER `media_duration_s`,
  ADD COLUMN `thumb_path`       VARCHAR(255) NULL DEFAULT NULL AFTER `frames_analyzed`;
