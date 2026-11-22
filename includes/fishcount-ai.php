<?php
/**
 * BOLU — Integrasi Layanan AI FishCount (opsional)
 *
 * Kebijakan kejujuran sistem:
 *  - Bila FISHCOUNT_AI_URL tidak diset -> gambar tersimpan berstatus "no_ai"
 *    (Menunggu AI). TIDAK ADA hasil deteksi yang dikarang.
 *  - Bila layanan AI terhubung dan merespons valid -> hasil disimpan berstatus
 *    "ai_done" dan selalu ditandai sebagai ESTIMASI yang dapat dikoreksi
 *    melalui validasi manual.
 *
 * Kontrak layanan (JSON):
 *   POST  { "image_base64": "<base64 tanpa prefix data:>", "mime": "image/jpeg" }
 *   <-    { "ok": true, "data": { "fish_count": int, "confidence_pct": int 0-100,
 *            "avg_length_cm": float|null, "notes": string, "model": string } }
 *
 * Layanan apa pun yang mengikuti kontrak ini dapat dipakai (mis. server YOLO
 * lokal Anda sendiri, atau bridge z-ai vision pada lingkungan sandbox).
 */

if (!defined('SMART_KJA_CONFIG_LOADED')) {
    exit('Akses langsung tidak diizinkan.');
}

/** Apakah layanan AI FishCount telah dikonfigurasi? */
function fishcount_ai_enabled(): bool
{
    return FISHCOUNT_AI_URL !== '';
}

/**
 * Kirim gambar ke layanan AI dan kembalikan hasil terverifikasi.
 * @return array{ok:bool, data?:array, error?:string}
 */
function fishcount_ai_analyze(string $absPath, string $mime): array
{
    if (!fishcount_ai_enabled()) {
        return ['ok' => false, 'error' => 'Layanan AI belum dikonfigurasi.'];
    }
    if (!is_file($absPath)) {
        return ['ok' => false, 'error' => 'Berkas gambar tidak ditemukan.'];
    }

    $payload = json_encode([
        'image_base64' => base64_encode((string)file_get_contents($absPath)),
        'mime'         => $mime,
    ]);

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content'       => $payload,
        'timeout'       => FISHCOUNT_AI_TIMEOUT,
        'ignore_errors' => true,
    ]]);

    $raw = @file_get_contents(FISHCOUNT_AI_URL, false, $ctx);
    if ($raw === false) {
        return ['ok' => false, 'error' => 'Layanan AI tidak dapat dihubungi (tidak aktif / timeout).'];
    }

    $j = json_decode($raw, true);
    if (!is_array($j) || empty($j['ok']) || !isset($j['data']['fish_count'])) {
        $err = is_array($j) && isset($j['error']) ? (string)$j['error'] : 'Respons layanan tidak valid.';
        return ['ok' => false, 'error' => mb_substr($err, 0, 160)];
    }

    $d    = $j['data'];
    $conf = max(0, min(100, (int)($d['confidence_pct'] ?? 0)));
    $len  = isset($d['avg_length_cm']) && $d['avg_length_cm'] !== null ? (float)$d['avg_length_cm'] : null;
    if ($len !== null && ($len <= 0 || $len > 300)) {
        $len = null; // nilai tak masuk akal -> abaikan, jangan tampilkan
    }

    return ['ok' => true, 'data' => [
        'fish_count'     => max(0, (int)$d['fish_count']),
        'confidence_pct' => $conf,
        'avg_length_cm'  => $len,
        'notes'          => mb_substr(trim((string)($d['notes'] ?? '')), 0, 140),
        'model'          => mb_substr((string)($d['model'] ?? 'ai'), 0, 60),
    ]];
}

/**
 * Terapkan hasil AI ke baris fishcount_results yang sudah dibuat.
 * Biomassa dihitung dengan relasi panjang-berat W(g) = a·L^b per ekor.
 */
function fishcount_ai_apply(int $resultId, array $ai): bool
{
    $a   = (float)setting('fish_lw_a', 0.015);
    $b   = (float)setting('fish_lw_b', 3.0);
    $bio = null;
    if ($ai['avg_length_cm'] !== null && $ai['fish_count'] > 0) {
        $bio = round(($a * pow($ai['avg_length_cm'], $b) * $ai['fish_count']) / 1000.0, 2);
    }

    $msg = 'Estimasi AI vision (' . $ai['model'] . '): ' . $ai['fish_count']
         . ' ekor · keyakinan ' . $ai['confidence_pct'] . '% — angka kasar, mohon validasi manual.'
         . ($ai['notes'] !== '' ? ' Catatan AI: ' . $ai['notes'] : '');
    $msg = mb_substr($msg, 0, 255);

    $st = db()->prepare(
        'UPDATE fishcount_results
         SET detected_count = ?, avg_length_cm = ?, biomass_kg = ?, confidence_pct = ?,
             processing_status = "ai_done", status_message = ?
         WHERE id = ?'
    );
    return $st->execute([
        $ai['fish_count'],
        $ai['avg_length_cm'],
        $bio,
        $ai['confidence_pct'],
        $msg,
        $resultId,
    ]);
}

/** Catat kegagalan layanan AI pada baris hasil (gambar tetap tersimpan). */
function fishcount_ai_mark_failed(int $resultId, string $error): void
{
    $st = db()->prepare(
        'UPDATE fishcount_results
         SET status_message = ?
         WHERE id = ?'
    );
    $st->execute([
        mb_substr('Layanan AI gagal diproses: ' . $error . ' — gambar tersimpan, status tetap Menunggu AI.', 0, 255),
        $resultId,
    ]);
}

/* =====================================================================
   Dukungan VIDEO (opsional, butuh ffmpeg/ffprobe di PATH server)
   Alur jujur: video dipecah menjadi N frame tengah (jpeg), SETIAP frame
   dianalisis layanan AI gambar yang sama, lalu hasilnya dirangkum
   sebagai RATA-RATA per frame + daftar per frame. Tidak ada angka yang
   dikarang; bila ffmpeg/AI tidak tersedia, video berstatus "no_ai".
   ===================================================================== */

/** Konversi notasi php.ini ("2M", "512K", "1G") ke byte. */
function fishcount_ini_bytes(string $v): int
{
    $v = trim($v);
    $l = strtolower(substr($v, -1));
    $n = (int)$v;
    return match ($l) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => (int)$v };
}

/** Binari ffmpeg/ffprobe tersedia di server? (hasil di-cache) */
function fishcount_video_tools_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        $out = [];
        @exec('ffprobe -version 2>' . (PHP_OS_FAMILY === 'Windows' ? ' NUL' : '/dev/null'), $out, $code);
        $ok = ($code === 0);
    }
    return $ok;
}

/** Durasi video dalam detik (ffprobe) atau null bila gagal. */
function fishcount_video_duration(string $absPath): ?float
{
    if (!fishcount_video_tools_available()) {
        return null;
    }
    $out = [];
    $cmd = 'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '
         . escapeshellarg($absPath) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? ' NUL' : '/dev/null');
    @exec($cmd, $out, $code);
    if ($code !== 0 || empty($out[0]) || !is_numeric($out[0])) {
        return null;
    }
    $d = (float)$out[0];
    return ($d > 0 && $d < 36000) ? $d : null;
}

/**
 * Ekstrak maksimal $n frame ke file sementara (jpeg), tersebar merata.
 * @return string[] daftar path absolut frame yang berhasil dibuat
 */
function fishcount_video_extract_frames(string $absPath, int $n): array
{
    $dur = fishcount_video_duration($absPath);
    if ($dur === null) {
        return [];
    }
    $frames = [];
    $span   = max(0.0, $dur - 0.2);
    for ($i = 0; $i < $n; $i++) {
        // hindari frame tepat 0.0 dtk (kadang hitam) dan ujug-ujug akhir
        $at   = $span * (($i + 0.5) / $n);
        $tmp  = tempnam(sys_get_temp_dir(), 'skja_f') . '.jpg';
        $cmd  = 'ffmpeg -v error -ss ' . sprintf('%.2f', $at) . ' -i ' . escapeshellarg($absPath)
              . ' -frames:v 1 -vf "scale=\'min(1280,iw)\':-2" -q:v 3 '
              . escapeshellarg($tmp) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? ' NUL' : '/dev/null');
        @exec($cmd, $o, $code);
        if ($code === 0 && is_file($tmp) && filesize($tmp) > 0) {
            $frames[] = $tmp;
        } elseif (is_file($tmp)) {
            @unlink($tmp);
        }
    }
    return $frames;
}

/** Simpan satu frame poster (tengah video) sebagai thumbnail JPG. */
function fishcount_video_make_thumb(string $absPath, string $destAbs): bool
{
    $dur = fishcount_video_duration($absPath);
    if ($dur === null) {
        return false;
    }
    $at  = sprintf('%.2f', max(0.0, $dur / 2 - 0.1));
    $cmd = 'ffmpeg -v error -ss ' . $at . ' -i ' . escapeshellarg($absPath)
         . ' -frames:v 1 -vf "scale=\'min(640,iw)\':-2" -q:v 4 ' . escapeshellarg($destAbs)
         . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? ' NUL' : '/dev/null');
    @exec($cmd, $o, $code);
    return $code === 0 && is_file($destAbs) && filesize($destAbs) > 0;
}

/**
 * Analisis satu video: ekstrak frame → analisis per frame → rangkum jujur.
 * @return array{ok:bool, data?:array, error?:string}
 */
function fishcount_ai_analyze_video(string $absPath): array
{
    if (!fishcount_ai_enabled()) {
        return ['ok' => false, 'error' => 'Layanan AI belum dikonfigurasi.'];
    }
    if (!fishcount_video_tools_available()) {
        return ['ok' => false, 'error' => 'ffmpeg/ffprobe tidak tersedia di server.'];
    }

    $frames = fishcount_video_extract_frames($absPath, FISHCOUNT_VIDEO_FRAMES);
    if (!$frames) {
        return ['ok' => false, 'error' => 'Frame video tidak dapat diekstrak (codec tidak didukung?).'];
    }

    $counts = [];
    $confs  = [];
    $lens   = [];
    $model  = '';
    $note   = '';
    foreach ($frames as $fr) {
        $r = fishcount_ai_analyze($fr, 'image/jpeg');
        if ($r['ok']) {
            $counts[] = (int)$r['data']['fish_count'];
            $confs[]  = (int)$r['data']['confidence_pct'];
            if ($r['data']['avg_length_cm'] !== null) {
                $lens[] = (float)$r['data']['avg_length_cm'];
            }
            if ($model === '') {
                $model = $r['data']['model'];
            }
            if ($note === '' && $r['data']['notes'] !== '') {
                $note = $r['data']['notes'];
            }
        }
        @unlink($fr); // bersihkan frame sementara
    }

    if (!$counts) {
        return ['ok' => false, 'error' => 'Seluruh frame gagal dianalisis layanan AI.'];
    }

    $avg   = round(array_sum($counts) / count($counts), 1);
    $conf  = (int)round(array_sum($confs) / count($confs));
    $avgLn = $lens ? round(array_sum($lens) / count($lens), 1) : null;

    return ['ok' => true, 'data' => [
        'fish_count'     => (int)round($avg),        // rata-rata ikan per frame
        'confidence_pct' => max(0, min(100, $conf)),
        'avg_length_cm'  => $avgLn,
        'frames_total'   => count($frames),
        'frames_ok'      => count($counts),
        'per_frame'      => $counts,
        'notes'          => $note,
        'model'          => $model !== '' ? $model : 'ai',
    ]];
}

/** Terapkan hasil analisis VIDEO ke baris fishcount_results. */
function fishcount_ai_apply_video(int $resultId, array $ai, int $framesAnalyzed): bool
{
    $a   = (float)setting('fish_lw_a', 0.015);
    $b   = (float)setting('fish_lw_b', 3.0);
    $bio = null;
    if ($ai['avg_length_cm'] !== null && $ai['fish_count'] > 0) {
        $bio = round(($a * pow($ai['avg_length_cm'], $b) * $ai['fish_count']) / 1000.0, 2);
    }

    $msg = 'Estimasi AI video (' . $ai['model'] . '): rata-rata ' . $ai['fish_count']
         . ' ekor/frame dari ' . $ai['frames_ok'] . ' frame (' . implode(', ', $ai['per_frame'])
         . ') · keyakinan ' . $ai['confidence_pct'] . '% — estimasi kasar, mohon validasi manual.'
         . ($ai['notes'] !== '' ? ' Catatan AI: ' . $ai['notes'] : '');
    $msg = mb_substr($msg, 0, 255);

    $st = db()->prepare(
        'UPDATE fishcount_results
         SET detected_count = ?, avg_length_cm = ?, biomass_kg = ?, confidence_pct = ?,
             processing_status = "ai_done", status_message = ?, frames_analyzed = ?
         WHERE id = ?'
    );
    return $st->execute([
        $ai['fish_count'],
        $ai['avg_length_cm'],
        $bio,
        $ai['confidence_pct'],
        $msg,
        $framesAnalyzed,
        $resultId,
    ]);
}
