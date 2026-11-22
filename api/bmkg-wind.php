<?php
/** BOLU — BMKG wind forecast (coastal/village forecast, NOT marine observation). */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=120');
function wind_error($message, $status = 502) {
    http_response_code($status);
    echo json_encode(['ok'=>false, 'error'=>$message], JSON_UNESCAPED_UNICODE);
    exit;
}
$adm4 = (string)($_GET['adm4'] ?? '36.73.02.1001');
if (!preg_match('/^\d{2}\.\d{2}\.\d{2}\.\d{4}$/D', $adm4)) wind_error('Kode ADM4 tidak valid (contoh 36.73.02.1001).', 400);
$url = 'https://api.bmkg.go.id/publik/prakiraan-cuaca?adm4=' . rawurlencode($adm4);
$cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bolu_bmkg_angin_' . hash('sha256', $adm4) . '.json';
$raw = false;
if (is_file($cache) && time() - filemtime($cache) < 1800) $raw = @file_get_contents($cache);
if ($raw === false || $raw === '') {
    if (!function_exists('curl_init')) wind_error('Ekstensi PHP cURL belum aktif. Aktifkan extension=curl di php.ini.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>7, CURLOPT_TIMEOUT=>15, CURLOPT_USERAGENT=>'BOLU/1.0 (BMKG wind forecast)', CURLOPT_HTTPHEADER=>['Accept: application/json'], CURLOPT_SSL_VERIFYPEER=>true]);
    $response = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $curlError = curl_error($ch); curl_close($ch);
    if ($response === false || $status !== 200) {
        if (is_file($cache)) { $raw = @file_get_contents($cache); }
        if ($raw === false || $raw === '') wind_error('BMKG tidak dapat diakses (' . ($status ?: $curlError) . ').');
    } else {
        $raw = $response;
        if (strlen($raw) < 1500000) @file_put_contents($cache, $raw, LOCK_EX);
    }
}
$data = json_decode($raw, true);
if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) wind_error('Struktur respons BMKG tidak sesuai.');
$loc = is_array($data['lokasi'] ?? null) ? $data['lokasi'] : [];
$items = [];
// Official payload uses data: [{cuaca: [[{local_datetime, ws, wd}...], ...]}].
foreach ($data['data'] as $group) {
    if (!is_array($group)) continue;
    $days = $group['cuaca'] ?? [];
    if (!is_array($days)) continue;
    foreach ($days as $forecasts) {
        if (!is_array($forecasts)) continue;
        foreach ($forecasts as $it) {
            if (!is_array($it) || !isset($it['ws'], $it['local_datetime']) || !is_numeric($it['ws'])) continue;
            try { $stamp = (new DateTimeImmutable($it['local_datetime'], new DateTimeZone('Asia/Jakarta')))->getTimestamp(); } catch (Throwable $e) { continue; }
            $items[] = ['stamp'=>$stamp,'time'=>$it['local_datetime'],'speed'=>(float)$it['ws'],'direction'=>(string)($it['wd'] ?? '—')];
        }
    }
}
if (!$items) wind_error('Tidak ada data kecepatan angin untuk ADM4 ini.');
usort($items, fn($a,$b) => $a['stamp'] <=> $b['stamp']);
$choice = $items[count($items)-1];
$now = time();
foreach ($items as $it) { if ($it['stamp'] >= $now-3600) { $choice=$it; break; } }
echo json_encode([
    'ok'=>true,
    'source'=>'BMKG — Prakiraan Cuaca Terbuka',
    'type'=>'coastal_village_forecast',
    'adm4'=>$adm4,
    'place'=>implode(', ',array_filter([(string)($loc['desa'] ?? ''),(string)($loc['kecamatan'] ?? ''),(string)($loc['kotkab'] ?? ''),(string)($loc['provinsi'] ?? '')])),
    'forecast_local'=>$choice['time'],
    'speed_kmh'=>$choice['speed'],
    'speed_knots'=>round($choice['speed'] / 1.852, 1),
    'wind_from'=>$choice['direction'],
    'stale'=>$choice['stamp'] < $now - 3600,
    'retrieved_at'=>date(DATE_ATOM)
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
