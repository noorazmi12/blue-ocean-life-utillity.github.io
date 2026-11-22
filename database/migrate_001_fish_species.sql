-- =====================================================================
-- SMART-KJA OWC — Migrasi 001: Tabel `fish_species` (Jenis Ikan KJA)
-- Jalankan dari phpMyAdmin (dapat diulang):  mysql -u root smart_kja_owc < migrate_001_fish_species.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS `fish_species` (
  `id`                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`                 VARCHAR(100)  NOT NULL,
  `latin_name`           VARCHAR(120)  NULL DEFAULT NULL,
  `category`             ENUM('laut','tawar','brackish') NOT NULL DEFAULT 'laut',
  `cycle_months_min`     TINYINT UNSIGNED NULL DEFAULT NULL COMMENT 'Siklus panen minimum (bulan)',
  `cycle_months_max`     TINYINT UNSIGNED NULL DEFAULT NULL COMMENT 'Siklus panen maksimum (bulan)',
  `target_weight_kg`     DECIMAL(6,3)  NULL DEFAULT NULL COMMENT 'Bobot panen target per ekor',
  `lw_a`                 DECIMAL(8,5)  NOT NULL DEFAULT 0.01500 COMMENT 'Koefisien relasi panjang-berat W=a·L^b',
  `lw_b`                 DECIMAL(5,3)  NOT NULL DEFAULT 3.000  COMMENT 'Eksponen relasi panjang-berat',
  `optimal_temp_min`     DECIMAL(4,1)  NULL DEFAULT NULL,
  `optimal_temp_max`     DECIMAL(4,1)  NULL DEFAULT NULL,
  `optimal_ph_min`       DECIMAL(3,1)  NULL DEFAULT NULL,
  `optimal_ph_max`       DECIMAL(3,1)  NULL DEFAULT NULL,
  `optimal_salinity_min` DECIMAL(5,2)  NULL DEFAULT NULL,
  `optimal_salinity_max` DECIMAL(5,2)  NULL DEFAULT NULL,
  `description`          VARCHAR(500)  NULL DEFAULT NULL,
  `is_active`            TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fish_species_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Empat jenis ikan pilihan BOLU (aman dijalankan ulang)
-- Nilai parameter Bandeng/Kakap Putih dipertahankan dari versi 001 sebelumnya.
-- Parameter Bawal Bintang/Kerapu Macan sengaja NULL sampai divalidasi.
INSERT INTO `fish_species`
  (`name`,`latin_name`,`category`,`cycle_months_min`,`cycle_months_max`,`target_weight_kg`,
   `lw_a`,`lw_b`,`optimal_temp_min`,`optimal_temp_max`,
   `optimal_ph_min`,`optimal_ph_max`,`optimal_salinity_min`,`optimal_salinity_max`,`description`,`is_active`)
VALUES
('Bandeng','Chanos chanos','brackish',4,6,0.350,0.01210,2.980,26.0,32.0,7.5,8.5,10.00,30.00,
 'Ikan budidaya air payau dan laut; parameter bawaan migrasi sebelumnya.',1),
('Kerapu macan','Epinephelus fuscoguttatus','laut',NULL,NULL,NULL,0.01500,3.000,NULL,NULL,NULL,NULL,NULL,NULL,
 'Kerapu macan untuk budidaya KJA laut. Parameter biologis perlu validasi lokal.',1),
('Kakap putih','Lates calcarifer','laut',5,8,0.600,0.01060,3.050,27.0,32.0,7.5,8.5,5.00,35.00,
 'Kakap putih untuk budidaya KJA; parameter bawaan migrasi sebelumnya.',1),
('Bawal bintang','Trachinotus blochii','laut',NULL,NULL,NULL,0.01500,3.000,NULL,NULL,NULL,NULL,NULL,NULL,
 'Bawal bintang untuk budidaya KJA laut. Parameter biologis perlu validasi lokal.',1)
ON DUPLICATE KEY UPDATE
  `latin_name`=VALUES(`latin_name`),
  `category`=VALUES(`category`),
  `is_active`=1;

-- Nonaktifkan dua jenis ikan lama yang tidak lagi dipilih.
-- Data tidak dihapus agar relasi atau riwayat lama tetap aman.
UPDATE `fish_species` SET `is_active`=0 WHERE `name` IN ('Kerapu cantang','Nila');

-- Verifikasi (lihat hasil di phpMyAdmin)
SELECT `id`,`name`,`latin_name`,`category`,`is_active` FROM `fish_species` ORDER BY `is_active` DESC,`name`;
