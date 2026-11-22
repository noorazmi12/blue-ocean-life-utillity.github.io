<?php
/**
 * BOLU — Konfigurasi Aplikasi
 * Sistem Monitoring Energi Gelombang, Kualitas Perairan, dan Biota
 * pada Keramba Jaring Apung (OWC — Oscillating Water Column)
 */

// Cegah akses langsung ke file ini (file lain wajib meng-include config ini)
if (defined('SMART_KJA_CONFIG_LOADED')) {
    exit('Akses langsung tidak diizinkan.');
}
define('SMART_KJA_CONFIG_LOADED', true);

define('APP_NAME', 'BOLU');
define('APP_LONG_NAME', 'Sistem Monitoring Energi Gelombang, Kualitas Perairan, dan Biota pada Keramba Jaring Apung');
define('APP_VERSION', '1.0.0');

/*
 * ENVIRONMENT: 'development' -> error ditampilkan (untuk debugging XAMPP)
 *              'production'  -> error disembunyikan, tidak membocorkan detail DB
 */
define('ENVIRONMENT', 'development');

/*
 * MODE REAL (data nyata):
 * false -> sistem berjalan penuh tanpa label demo: seluruh data pada database
 *          diperlakukan sebagai data perangkat dan tampil tanpa lencana simulasi.
 * true  -> (opsional) menampilkan kembali label "DEMO" untuk data simulasi seeder.
 */
define('DEMO_MODE', false);

// Kotak akun cepat pada halaman login (klik untuk mengisi form otomatis)
define('SHOW_QUICK_ACCOUNTS', true);

// Upload gambar FishCount
define('UPLOAD_MAX_BYTES', 5 * 1024 * 1024); // 5 MB
define('UPLOAD_ALLOWED_MIME', ['image/jpeg', 'image/png', 'image/webp']);
define('UPLOAD_FISHCOUNT_DIR', dirname(__DIR__) . '/uploads/fishcount/');

// Upload video FishCount (batas efektif = min(nilai ini, upload_max_filesize &
// post_max_size php.ini). Untuk video besar, naikkan php.ini Anda.)
define('UPLOAD_VIDEO_MAX_BYTES', 50 * 1024 * 1024); // 50 MB
define('UPLOAD_VIDEO_ALLOWED_MIME', ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-matroska', 'video/x-msvideo']);
define('FISHCOUNT_VIDEO_FRAMES', 5); // jumlah frame yang diekstrak & dianalisis per video

// ---- Layanan AI FishCount (OPSIONAL) ----
/*
 * URL layanan analisis ikan (computer vision). Kontrak JSON:
 *   POST  { "image_base64": "<base64>", "mime": "image/jpeg" }
 *   <-    { "ok": true, "data": { "fish_count": int, "confidence_pct": int 0-100,
 *            "avg_length_cm": float|null, "notes": string, "model": string } }
 * Bila kosong (default), unggahan/snapshot tetap tersimpan dengan status
 * "Menunggu AI" — sistem TIDAK PERNAH mengarang hasil deteksi.
 * Dapat ditimpa lewat env: SMART_KJA_FISHCOUNT_AI_URL
 */
define('FISHCOUNT_AI_URL', getenv('SMART_KJA_FISHCOUNT_AI_URL') ?: '');
define('FISHCOUNT_AI_TIMEOUT', 90); // detik

// Zona waktu aplikasi (samakan dengan kebutuhan lokasi KJA)
date_default_timezone_set('Asia/Jakarta');

// Semua halaman & API bersifat dinamis (sesi login + data realtime) —
// larang caching agar Set-Cookie sesi tidak pernah ikut tersimpan di cache
// proxy/gateway/browser (mencegah token CSRF basi).
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
}

if (ENVIRONMENT === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('log_errors', '1');
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}
