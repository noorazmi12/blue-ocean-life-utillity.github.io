<?php
/**
 * BOLU — Konfigurasi Koneksi Database (PDO)
 * Ubah kredensial di bawah sesuai instalasi XAMPP/Laragon Anda.
 */

if (!defined('SMART_KJA_CONFIG_LOADED')) {
    exit('Akses langsung tidak diizinkan.');
}

// ---- Konfigurasi MySQL (default XAMPP) ----
// Nilai bawaan mengikuti XAMPP/Laragon. Semua nilai dapat ditimpa lewat
// environment variable (mis. untuk sandbox/server hosting) TANPA mengubah
// perilaku default instalasi lokal:
//   SMART_KJA_DB_HOST / SMART_KJA_DB_PORT / SMART_KJA_DB_NAME /
//   SMART_KJA_DB_USER / SMART_KJA_DB_PASS
$DB_HOST    = getenv('SMART_KJA_DB_HOST') ?: 'localhost';
$DB_PORT    = getenv('SMART_KJA_DB_PORT') ?: '3306';
$DB_NAME    = getenv('SMART_KJA_DB_NAME') ?: 'smart_kja_owc';
$DB_USER    = getenv('SMART_KJA_DB_USER') ?: 'root';
$DB_PASS    = getenv('SMART_KJA_DB_PASS') !== false ? getenv('SMART_KJA_DB_PASS') : ''; // default XAMPP: kosong
$DB_CHARSET = 'utf8mb4';

$dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset={$DB_CHARSET}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false, // prepared statement asli (anti SQL injection)
    PDO::ATTR_STRINGIFY_FETCHES  => false,
];

try {
    $PDO = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
    if (ENVIRONMENT === 'development') {
        // Hanya tampilkan detail saat development
        http_response_code(500);
        die('<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Koneksi Gagal</title>'
            . '<style>body{font-family:system-ui;background:#F2F8FB;display:grid;place-items:center;margin:0}'
            . '.box{background:#fff;padding:32px 40px;border-radius:12px;box-shadow:0 2px 10px rgba(12, 37, 52,.1);max-width:560px}'
            . 'h1{color:#16394D;font-size:20px;margin:0 0 8px}code{background:#EAF5FA;padding:2px 6px;border-radius:4px;color:#E5484D}</style></head>'
            . '<body><div class="box"><h1>Koneksi database gagal</h1>'
            . '<p>Pastikan MySQL sudah berjalan di XAMPP dan database <code>smart_kja_owc</code> sudah diimpor melalui phpMyAdmin.</p>'
            . '<p>Detail teknis: <code>' . htmlspecialchars($e->getMessage()) . '</code></p></div></body></html>');
    }
    // Production: jangan bocorkan detail database
    http_response_code(500);
    die('Terjadi gangguan pada server. Silakan coba lagi nanti.');
}
