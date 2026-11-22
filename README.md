# BOLU

**Sistem Monitoring Energi Gelombang, Kualitas Perairan, dan Biota pada Keramba Jaring Apung**

Aplikasi web monitoring kelautan berbasis **PHP native** (tanpa framework) + **MySQL** yang mendukung
pengelolaan budidaya pesisir berkelanjutan melalui pemanfaatan energi gelombang laut
(Oscillating Water Column), pemantauan kualitas perairan, pemantauan ikan dengan kamera & AI,
serta visualisasi berbasis WebGIS.

---

## 1. Ringkasan Fitur & Status Kejujuran Sistem

| Modul | Halaman | Status |
|---|---|---|
| Login & autentikasi sesi | `login.php` | ✅ Berfungsi penuh (MySQL + `password_hash`/`password_verify`) |
| Dashboard ringkasan | `dashboard.php` | ✅ Berfungsi penuh (data dari API, polling otomatis) |
| Penjelasan KJA (edukasi) | `penjelasan-kja.php` | ✅ Materi edukasi KJA: pengertian, skema SVG keramba + OWC, komponen, siklus budidaya, parameter kualitas air, alur OWC, FAQ |
| Monitoring gelombang | `gelombang.php` | ✅ Berfungsi penuh; data live dari perangkat via `api/ingest.php` |
| Monitoring energi OWC | `energi-owc.php` | ✅ Berfungsi penuh; data live dari unit OWC via `api/ingest.php` |
| Monitoring kualitas air | `kualitas-air.php` | ✅ Berfungsi penuh (suhu & pH); SST berlabel sumber satelit/model |
| FishCount AI | `fishcount.php` | ✅ Kamera laptop/HP (getUserMedia) + unggahan **gambar & video** berfungsi nyata; **analisis AI vision aktif bila layanan dikonfigurasi** (`FISHCOUNT_AI_URL` — sudah aktif di preview sandbox; video dianalisis per frame via ffmpeg), hasil selalu berlabel estimasi + validasi manual |
| WebGIS | `webgis.php` | ✅ Berfungsi penuh (Leaflet + Esri/OSM, koordinat dapat diubah) |
| Prediksi AI | `prediksi-ai.php` | ⚠️ Analisis statistik nyata (korelasi Pearson, z-score, tren linear); **model ML belum tersedia** (tersedia ekstrapolasi linear; tidak ada angka prediksi palsu) |
| Peringatan | `peringatan.php` | ✅ Berfungsi penuh (auto-generate saat ambang terlewati) |
| Histori & laporan | `laporan.php` | ✅ Berfungsi penuh (CSV, cetak, ringkasan harian/mingguan/bulanan) |
| Manajemen perangkat | `perangkat.php` | ✅ Berfungsi penuh (CRUD + token API per perangkat) |
| Jenis ikan (katalog) | `jenis-ikan.php` | ✅ Berfungsi penuh (CRUD jenis ikan: kategori, rentang air ideal, siklus panen, koef. panjang–berat; opsi simpan ambang kualitas air per spesies) |
| Pengaturan | `pengaturan.php` | ✅ Berfungsi penuh (profil, ambang per spesies, sistem, pengguna) |
| Interaktivitas site-wide | semua halaman | ✅ Scroll-reveal bertahap, count-up angka statistik, ripple tombol, back-to-top, bar progres baca — dekoratif saja, tidak menyentuh data, hormati `prefers-reduced-motion` |

> **Mode data real aktif**: `DEMO_MODE = false` dan `SHOW_DEMO_CREDENTIALS = false` di `config/config.php` —
> label demo & chip akun dimatikan, dan seluruh data pada database diperlakukan sebagai data perangkat
> (`is_simulated = 0`). Data baru dari sensor/perangkat masuk melalui `api/ingest.php`.

---

## 2. Persyaratan

- **XAMPP** (Apache + MySQL/MariaDB) atau **Laragon** — direkomendasikan XAMPP 8.1+ dengan PHP 8.1/8.2
- Browser modern (Chrome, Edge, Firefox)
- Ekstensi PHP standar XAMPP: `pdo_mysql`, `fileinfo`, `gd`/`exif` (opsional) — sudah aktif bawaan
- **Opsional (video FishCount)**: `ffmpeg`/`ffprobe` di PATH server agar video otomatis
  dianalisis per frame; tanpa ffmpeg video tetap bisa diunggah (status "Menunggu AI")
  dan diisi lewat validasi manual. Untuk video besar naikkan `upload_max_filesize` &
  `post_max_size` di php.ini (batas tampil di kartu unggah mengikuti nilai efektif).

---

## 3. Instalasi dengan XAMPP

1. **Salin folder proyek** ke direktori web server:
   - XAMPP (Windows): `C:\xampp\htdocs\smart-kja-owc`
   - XAMPP (Linux): `/opt/lampp/htdocs/smart-kja-owc`
   - Laragon: `C:\laragon\www\smart-kja-owc`

2. **Jalankan Apache dan MySQL** dari XAMPP Control Panel (klik *Start* pada keduanya).

3. **Buat database** melalui phpMyAdmin (langkah di bagian 4).

4. **Periksa konfigurasi** `config/database.php`:
   ```php
   $DB_HOST = 'localhost';
   $DB_NAME = 'smart_kja_owc';
   $DB_USER = 'root';
   $DB_PASS = '';   // default XAMPP: kosong. Ganti bila MySQL Anda berpassword.
   ```
   > 💡 Alternatif tanpa menyunting berkas: kredensial dapat diambil dari
   > *environment variable* `SMART_KJA_DB_HOST`, `SMART_KJA_DB_PORT`,
   > `SMART_KJA_DB_NAME`, `SMART_KJA_DB_USER`, `SMART_KJA_DB_PASS`
   > (berguna untuk hosting/sandbox). Tanpa variable tersebut, nilai default
   > XAMPP di atas yang dipakai.

5. **Buka aplikasi** di browser:
   ```
   http://localhost/smart-kja-owc/
   ```
   Anda akan diarahkan otomatis ke halaman login.

---

## 4. Impor Database via phpMyAdmin

1. Buka `http://localhost/phpmyadmin`
2. Menu **Import** → **Choose File** → pilih `database/smart_kja_owc.sql`
3. Biarkan format **SQL** → klik **Go / Kirim**
4. Berhasil jika muncul: *"Import has been successfully finished"*.
   Database `smart_kja_owc` berisi 12 tabel + data awal (kolom media video FishCount
   sudah termasuk; DB lama cukup impor `migrate_002_video_media.sql`).

**Alternatif tanpa phpMyAdmin** (terminal/ CMD):
```bash
# Windows (XAMPP)
C:\xampp\mysql\bin\mysql -u root < database\smart_kja_owc.sql
# Linux
mysql -u root < database/smart_kja_owc.sql
```

> 💡 Data awal dibuat **relatif terhadap waktu impor** (`NOW()`), sehingga begitu diimpor,
> dashboard langsung menampilkan data segar dengan status LIVE.
> Jika data sudah lewat 24 jam dan grafik kosong, dashboard menampilkan
> overlay "Belum ada data dalam 24 jam terakhir" — bukan angka rekaan.

### Akun bawaan (segera ganti password!)

| Peran | Username | Password | Hak akses |
|---|---|---|---|
| Administrator | `admin` | `Admin@123` | Semua: kelola perangkat, pengguna, pengaturan sistem, ambang |
| Operator | `operator` | `Operator@123` | Monitoring + ubah data (perangkat/ambang/peringatan) |
| Viewer | `viewer` | `Viewer@123` | Lihat saja |

Tidak ada registrasi mandiri — pengguna baru dibuat oleh admin di **Pengaturan → Pengelolaan Pengguna**
(sesuai prinsip prioritas akses admin/operator). Password disimpan sebagai hash bcrypt,
tidak pernah menyimpan password asli di database.

---

## 5. Struktur Folder

```
smart-kja-owc/
├── index.php              # Landing page PUBLIK (sebelum login): navigasi atas,
│                            hero bawah laut, statistik dari database,
│                            ringkasan fitur — tautan aplikasi mengarah ke login
├── login.php              # Halaman login premium: splash intro animasi (logo sonar + judul
│                            huruf demi huruf, ±2,3 dtk, klik = lewati / ?nosplash=1),
│                            gelombang beranimasi, Caps Lock;
│                            sesi + CSRF + throttle
├── logout.php             # Penghapus sesi
├── dashboard.php          # Ringkasan sistem (judul seksi + kartu + grafik + peta mini)
├── penjelasan-kja.php     # Halaman edukasi: apa itu KJA + hubungan dengan OWC
├── gelombang.php          # Monitoring gelombang real-time
├── energi-owc.php         # Monitoring pembangkit OWC
├── kualitas-air.php       # Monitoring perairan (sensor vs SST)
├── fishcount.php          # Pemantauan ikan: kamera/unggah GAMBAR & VIDEO, analisis AI
│                            vision (video dianalisis per frame via ffmpeg) + validasi manual
├── webgis.php             # Peta Leaflet + kelola lokasi (admin)
├── prediksi-ai.php        # Analitik + status model AI yang jujur
├── peringatan.php         # Daftar & penanganan peringatan
├── laporan.php            # Histori, ringkasan, CSV, cetak
├── perangkat.php          # CRUD perangkat + token API
├── jenis-ikan.php         # Katalog jenis ikan KJA (CRUD + ambang per spesies)
├── pengaturan.php         # Profil, ambang, sistem, pengguna
├── config/
│   ├── config.php         # Konstanta aplikasi (DEMO_MODE, ENVIRONMENT, dst.)
│   └── database.php       # Kredensial MySQL (PDO)
├── includes/
│   ├── auth.php           # Sesi, require_login(), role
│   ├── functions.php      # Helper: e(), setting(), csrf, ambang, CSV, dst.
│   ├── header.php         # <head> + topbar (breadcrumb, lonceng, profil)
│   ├── sidebar.php        # Navigasi (aktif-highlight, collapsible)
│   └── footer.php         # Penutup + pemuatan JS
├── api/
│   ├── wave-data.php      # GET data gelombang (latest/range)
│   ├── water-data.php     # GET data kualitas air (latest/range/multi)
│   ├── energy-data.php    # GET data energi OWC (latest/range/daily/summary)
│   ├── fishcount-data.php # GET statistik + POST snapshot/validasi
│   ├── alerts-data.php    # GET notifikasi + POST resolve
│   ├── devices-geo.php    # GET geojson perangkat + detail
│   └── ingest.php         # POST penerimaan data perangkat (token)
├── assets/
│   ├── css/style.css      # Desain tema abu-abu konsisten (#3090A8/#22A06B/#F4C542)
│   │                        + latar "peta laut" berlapis (kontur bathymetri SVG, seksi 27)
│   │                        + login premium: gelombang beranimasi (seksi 28)
│   │                        + splash intro: sonar ping, judul per huruf, progress bar,
│   │                          koreografi masuk konten (seksi 30)
│   │                        + pratinjau unggahan & thumbnail video FishCount (seksi 31)
│   ├── js/app.js          # JS inti: sidebar, dropdown, toast, CSRF fetch
│   ├── js/interaktif.js   # Interaktivitas site-wide: scroll-reveal, ripple tombol,
│   │                        back-to-top, bar progres baca (hormati reduced-motion)
│   ├── js/dashboard.js    # Polling dashboard + grafik + peta mini + overlay grafik kosong
│   ├── js/wave-chart.js   # Grafik gelombang real-time
│   ├── js/water-chart.js  # Grafik kualitas air
│   ├── js/energy-chart.js # Grafik daya/energi/harian
│   ├── js/fishcount.js    # Kamera, unggah, validasi, grafik ikan
│   ├── js/map.js          # Leaflet WebGIS
│   ├── js/prediksi.js     # Grafik analitik
│   ├── js/laporan.js      # Grafik laporan
│   ├── js/perangkat.js    # Modal CRUD perangkat
│   ├── js/pengaturan.js   # Modal reset password
│   └── images/            # Logo baru (PNG: mark putih/hitam, favicon, logo penuh)
├── uploads/               # Gambar FishCount (eksekusi PHP dinonaktifkan)
├── database/
│   ├── smart_kja_owc.sql  # Skema + seeder (siap impor phpMyAdmin, sudah termasuk kolom video)
│   ├── migrate_001_fish_species.sql # Tambah katalog jenis ikan (untuk DB lama)
│   └── migrate_002_video_media.sql  # Tambah kolom media video FishCount (untuk DB lama)
├── tools/
│   └── device_simulator.py # Simulator perangkat untuk pengujian
└── README.md              # Dokumen ini
```

---

## 6. Dokumentasi API

### 6.1 API pembacaan data (privat — butuh sesi login)

Semua mengembalikan JSON `{ok: true, data: {...}}` dan mengharuskan pengguna login
(kecuali `api/ingest.php` yang memakai token perangkat). Bila sesi habis → `401`.

| Endpoint | Contoh | Keterangan |
|---|---|---|
| `api/wave-data.php` | `?view=latest` / `?view=range&minutes=60` / `?view=range&from=2026-10-29&to=2026-10-30` | Gelombang: titik grafik, statistik min/max/avg, status LIVE/DELAYED/OFFLINE |
| `api/water-data.php` | `?view=range&param=ph&minutes=1440` | Parameter: `temp_sensor_c`, `ph` (alat hanya mengukur suhu & pH) |
| `api/energy-data.php` | `?view=range&minutes=1440` / `?view=daily&days=7` / `?view=summary` | Daya, energi kumulatif, estimasi potensi |
| `api/fishcount-data.php` | `?view=stats&days=7` | Seri jumlah ikan + distribusi ukuran |
| `api/alerts-data.php` | `?view=recent&limit=8` | Untuk lonceng notifikasi topbar |
| `api/devices-geo.php` | (tanpa param) / `?view=detail&id=2` | Posisi + nilai terakhir per perangkat |

### 6.2 Endpoint penerimaan data perangkat (publik, autentikasi token)

`POST /smart-kja-owc/api/ingest.php` — kirim JSON dengan `Content-Type: application/json`:

```json
{
  "device_code": "WAV-001",
  "api_key": "TOKEN_ASLI_PERANGKAT",
  "timestamp": "2026-10-30 10:30:00",
  "data": {
    "wave_height_m": 0.72,
    "wave_period_s": 4.8,
    "wave_direction_deg": 112,
    "wind_speed_ms": 3.2,
    "wind_direction_deg": 205
  }
}
```

Contoh `data` per jenis perangkat:

| Jenis | Field wajib | Field opsional |
|---|---|---|
| `wave_sensor` | `wave_height_m` (0–15), `wave_period_s` (0–30) | `wave_direction_deg` (0–360), `wind_speed_ms`, `wind_direction_deg` |
| `water_sensor` | minimal satu: `temp_sensor_c` (−5–45) atau `ph` (0,05–14) | `sst_c`, `salinity_psu`, `turbidity_ntu`, `depth_m` (tidak ditampilkan — alat hanya mengukur suhu & pH) |
| `owc` | `power_w`, `voltage_v`, `current_a` | `energy_wh`, `battery_pct` |
| `camera` | (hasil layanan AI eksternal) `detected_count` | `avg_length_cm`, `biomass_kg`, `confidence_pct` |

**Aturan validasi server:** angka di luar rentang wajar disimpan `NULL` (bukan menolak
seluruh payload) — untuk `water_sensor` cukup kirim salah satu field yang valid, sehingga
sensor yang error/tercabut tidak menggagalkan data sensor satunya. `ph = 0` dianggap
probe tidak terpasang dan disimpan `NULL`. Timestamp format `YYYY-MM-DD HH:MM:SS`
(opsional — bila kosong dipakai waktu server) dan maksimal selisih 24 jam.
Data valid disimpan dengan `is_simulated = 0`, memperbarui `last_communication`
(alat otomatis berstatus **Online**), dan otomatis membuat **peringatan** bila
melewati ambang (dengan dedupe 15 menit).

Contoh uji dengan curl:

```bash
curl -X POST http://localhost/smart-kja-owc/api/ingest.php \
  -H "Content-Type: application/json" \
  -d '{"device_code":"WAV-001","api_key":"demo-wave-token","data":{"wave_height_m":0.85,"wave_period_s":5.1,"wind_speed_ms":4.0}}'
```

Respons sukses:

```json
{ "ok": true, "id": 1234, "device": "WAV-001", "inserted": 1, "alerts_created": 0, "message": "Data diterima dan disimpan." }
```

### 6.3 Token perangkat

Server menyimpan **hash SHA-256** token; token asli hanya diketahui perangkat dan
*tidak pernah* ditanam di JavaScript frontend. Token baru ditampilkan sekali saat dibuat.

Token sudah di-rotate dan tidak ada lagi token demo bawaan. Untuk melihat/mengganti
token sebuah perangkat: buka `perangkat.php` → klik ikon **kunci** pada baris perangkat
(token baru ditampilkan sekali, langsung pasang di perangkat).

### 6.4 Integrasi ESP32 OWC KJA (pH, suhu, dinamo, daya)

Sketch lengkap tersedia di **`owc_kja_bolu.ino`** (ESP32 + DS18B20 + probe pH analog +
INA219, WiFiManager + halaman monitor lokal + mDNS `owc.local`). Sketch mengirim data
ke `api/ingest.php` tiap 15 detik:

| Sensor di ESP32 | Field ke ingest | Perangkat tujuan |
|---|---|---|
| DS18B20 (GPIO4) — suhu air | `temp_sensor_c` | `WTR-001` (water_sensor) |
| Probe pH analog (GPIO34) | `ph` (dikirim bila > 0,01) | `WTR-001` (water_sensor) |
| Dinamo ADC (GPIO35) — tegangan | `voltage_v` | `OWC-001` (owc) |
| INA219 — daya (mW→W) & arus (mA→A) | `power_w`, `current_a` | `OWC-001` (owc) |
| Akumulasi daya sejak alat menyala | `energy_wh` | `OWC-001` (owc) |

Yang diubah di sketch: hanya blok `KONFIGURASI BOLU` — `BOLU_URL`, dua token API
(`KEY_WTR`, `KEY_OWC`), dan interval kirim (default 15 detik). Nilai sensor yang error
(suhu ≤ −100/≥ 84 °C, pH = 0) **tidak dikirim** sehingga server menyimpan `NULL`
dan alat tetap terhitung Online. Bila INA219 tidak terdeteksi, daya/arus dikirim `0`.
Setelah data masuk: dashboard, Kualitas Air, dan Energi OWC menampilkan status **LIVE**
secara otomatis (interval segar mengikuti pengaturan sistem).

### 6.5 Layanan AI FishCount (opsional)

`config/config.php` membaca `FISHCOUNT_AI_URL` (atau env `SMART_KJA_FISHCOUNT_AI_URL`).
Bila diisi, **setiap snapshot kamera laptop & unggahan gambar** otomatis dikirim ke layanan
analisis ikan tersebut lalu hasilnya disimpan ke tabel `fishcount_results`:

```
POST  { "image_base64": "<base64>", "mime": "image/jpeg" }
<-    { "ok": true, "data": { "fish_count": 7, "confidence_pct": 98,
                            "avg_length_cm": null, "notes": "...", "model": "..." } }
```

- **Tanpa layanan** → gambar tetap tersimpan berstatus *"Menunggu AI"*; sistem tidak pernah mengarang hasil.
- **Dengan layanan** → status berubah menjadi *"AI Selesai"* (`ai_done`), selalu diperlakukan sebagai
  ESTIMASI yang dapat dikoreksi lewat tombol **Validasi** (penghitungan manual).
- `avg_length_cm` (dan biomassa turunannya) hanya diisi bila ada acuan ukuran pada gambar.
- **Preview sandbox**: bridge z-ai vision (model GLM-5V) sudah tersambung otomatis.
- **XAMPP lokal**: jalankan layanan apa pun yang mengikuti kontrak di atas (mis. server YOLO
  Anda sendiri) lalu set `FISHCOUNT_AI_URL` di `config/config.php`.

---

## 7. Uji Coba Tanpa Sensor: Simulator Perangkat

```bash
# kirim satu paket dari ketiga perangkat utama
python tools/device_simulator.py --once

# kirim terus tiap 30 detik (indikator LIVE & grafik akan bergerak)
python tools/device_simulator.py --interval 30

# ganti URL bila folder/host berbeda
python tools/device_simulator.py --url http://localhost/smart-kja-owc
```

Simulator mengirim gelombang sinus yang halus (meniru kondisi laut) — bukan angka acak —
sehingga pola grafik realistis. Selama simulator/sensor mengirim data < 10 menit terakhir,
status data adalah **LIVE**; setelah itu DELAYED lalu OFFLINE otomatis.

---

## 8. Mode Data Real (Aktif)

1. **Konfigurasi aktif** — `config/config.php` sudah diset `DEMO_MODE = false` dan
   `SHOW_DEMO_CREDENTIALS = false`: label demo disembunyikan dan seluruh data di database
   diperlakukan sebagai data perangkat (`is_simulated = 0`).
2. **Daftarkan perangkat nyata** — `perangkat.php` → *Tambah Perangkat*, isi kode, jenis,
   lokasi. Catat token API yang ditampilkan sekali.
3. **Hubungkan mikrokontroler/gateway** — kirim HTTP POST JSON ke `api/ingest.php`
   sesuai dokumentasi 6.2 (interval 1–5 menit disarankan).
4. **Bersihkan data lama (opsional)** via phpMyAdmin:
   ```sql
   USE smart_kja_owc;
   DELETE FROM wave_measurements  WHERE is_simulated = 1;
   DELETE FROM water_measurements WHERE is_simulated = 1;
   DELETE FROM owc_measurements   WHERE is_simulated = 1;
   DELETE FROM fishcount_results  WHERE is_simulated = 1;
   DELETE FROM alerts             WHERE is_simulated = 1;
   ```
5. **Atur ambang spesies** — `pengaturan.php` → *Ambang Parameter per Spesies* sesuai
   jenis ikan budidaya (bukan nilai universal).
6. **Perbarui koordinat** — `webgis.php` → *Tambah/Ubah Lokasi* (admin) dengan koordinat
   lokasi penelitian yang sebenarnya.
7. **Produksi** — `config/config.php`: `ENVIRONMENT = 'production'` agar detail error
   database tidak pernah tampil ke pengguna.

---

## 9. Keamanan yang Diterapkan

- ✅ **PDO prepared statements** untuk semua query (anti SQL injection)
- ✅ **`password_hash()` / `password_verify()`** (bcrypt) — tidak ada password plaintext
- ✅ **Session authentication** + `session_regenerate_id()` saat login (anti fixation) +
    cookie `HttpOnly` + `SameSite=Lax` + `Secure` bila HTTPS
- ✅ **CSRF token** pada semua POST (form & AJAX header `X-CSRF-Token`)
- ✅ **Otorisasi peran** (admin/operator/viewer) di setiap halaman & endpoint privat
- ✅ **Escape output XSS** via `e()` (`htmlspecialchars`) pada seluruh tampilan
- ✅ **Validasi unggahan**: tipe MIME nyata (`finfo` + `getimagesize`), batas 5 MB,
    nama acak, direktori uploads tanpa eksekusi PHP
- ✅ **Token perangkat SHA-256**, respons generik saat gagal (tidak membocorkan kode)
- ✅ **Throttle login** (5 percobaan / 5 menit) + validasi redirect anti *open redirect*
- ✅ **`.htaccess`**: blokir direktori `config/` & `database/`, nonaktifkan eksekusi PHP
    di `uploads/`, matikan *directory listing*
- ✅ **Error produksi**: `ENVIRONMENT=production` menyembunyikan detail database
- ✅ Kredensial perangkat **tidak pernah** ditempatkan di JavaScript frontend

**Checklist naik ke produksi:** ganti semua password akun, reset token perangkat,
set `ENVIRONMENT='production'`, gunakan HTTPS, dan batasi akses phpMyAdmin.

---

## 10. Bagian yang Sudah Berfungsi vs yang Membutuhkan Perangkat / Model AI

**Sudah berfungsi nyata (siap dipakai lokal):**
Login/logout, multi-halaman + navigasi, dashboard polling, semua grafik (Chart.js),
WebGIS (Leaflet), sistem peringatan otomatis + penanganan, histori & laporan + CSV + cetak,
CRUD perangkat + token, ambang per spesies, kelola pengguna, ekspor data, validasi
input server, CSRF, dan **endpoint ingest** untuk perangkat nyata.

**Membutuhkan perangkat keras (sensor/unit):**
Nilai terukur aktual gelombang, kualitas air, daya OWC, dan kamera. Selama perangkat belum
mengirim data baru, aplikasi menampilkan data terakhir yang tersimpan, dan indikator
LIVE/DELAYED/OFFLINE bekerja sesuai usia data (tidak pernah mengklaim LIVE untuk data kedaluwarsa).

**Membutuhkan pengembangan lanjutan:**
1. **Model AI khusus ikan (YOLO)** — integrasi layanan visi generik sudah siap: set `FISHCOUNT_AI_URL`
   ke layanan apa pun yang mengikuti kontrak 6.4 (di preview sandbox sudah tersambung ke model
   vision GLM). Untuk akurasi lebih tinggi di lapangan, sambungkan model terlatih khusus ikan
   (YOLO/aquarium dataset) pada server Anda — kontrak respons tetap sama, tanpa mengubah kode halaman.
2. **Model prediksi ML** (daya/gelombang) — butuh histori bulanan + fitur prakiraan
   eksternal (mis. BMKG). Halaman prediksi menampilkan analisis statistik nyata
   (Pearson, z-score, tren linear).
3. **Notifikasi email** — pengaturan tersedia, pengiriman memerlukan konfigurasi SMTP.
4. **SST satelit nyata** — kolom `sst_c` siap diisi dari API satelit/model (mis. NOAA
   Copernicus).

---

## 11. Pemecahan Masalah (Troubleshooting)

| Gejala | Penyebab | Solusi |
|---|---|---|
| **"CSRF token tidak valid" saat login** di panel preview yang ter-embed (iframe) | Browser memblokir cookie pihak ketiga di dalam iframe lintas situs, sehingga sesi PHP tidak tersimpan | Klik tombol **"Buka Tab Baru"** yang muncul di halaman (atau tombol *Open in New Tab* pada panel preview) sehingga aplikasi berjalan sebagai tab utuh; di XAMPP lokal hal ini tidak terjadi |
| **Kamera laptop tidak muncul / diblokir** di halaman FishCount | Browser hanya mengizinkan kamera pada HTTPS dan halaman tab penuh (bukan iframe tanpa izin) | Buka aplikasi di tab baru lalu tekan **Mulai** dan setujui izin kamera; alternatif: unggah foto melalui panel kanan — hasilnya sama dianalisis AI |
| "Koneksi database gagal" | MySQL belum jalan / database belum diimpor | Start MySQL di XAMPP, impor `database/smart_kja_owc.sql` via phpMyAdmin |
| Data tidak bergerak / status OFFLINE | Tidak ada perangkat yang mengirim data | Jalankan `python tools/device_simulator.py --interval 30` |
| Grafik kosong | Ekstensi PHP `pdo_mysql` nonaktif | Aktifkan `extension=pdo_mysql` di `php.ini` |

---

## 12. Catatan Teknis

- Grafik memperbarui otomatis via **polling JavaScript** ke API PHP (interval dapat
  diatur di *Pengaturan → Interval Pembaruan Dashboard*, default 15 detik). Tidak ada
  angka acak yang digenerate di browser.
- Logika status data: `LIVE` ≤ ambang kedaluwarsa (default 10 menit) ≤ `DELAYED` ≤ 3× → `OFFLINE`.
- Perhitungan fisika: daya gelombang `P ≈ 0,49·H²·T` (kW/m) × lebar penangkap × efisiensi
  untuk estimasi listrik — keduanya dibedakan tegas dari daya terukur generator.
- Semua halaman berbagi layout yang sama (`includes/header|sidebar|footer`) dan CSS tunggal
  sehingga tampilan konsisten; sidebar dapat diciutkan dan diingat via localStorage.
- Kompatibel PHP 8.1+ (memakai `match`, arrow function, `str_contains`).

Lisensi: bebas digunakan dan dikembangkan untuk keperluan penelitian & edukasi.
#   B O L U - B l u e - O c e a n - L i f e - U t i l i t y -  
 