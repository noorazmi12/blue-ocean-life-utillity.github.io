<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

$PAGE_ID = 'batimetri';
$PAGE_TITLE = 'Peta Batimetri';
$BREADCRUMB = [['Dashboard', 'dashboard.php'], ['Peta Batimetri', null]];
$PAGE_SCRIPTS = ['batimetri-bmkg-v3.js', 'betri-layer.js', 'bmkg-wind.js'];
$NEED_LEAFLET = true;

require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div class="page-title">
        <h1><i class="fa-solid fa-mountain"></i> Peta Batimetri</h1>
        <p class="page-desc">Peta kedalaman laut (batimetri) Indonesia dengan hasil olahan BETRI lokal serta data yang ditarik langsung
            dari layanan <strong>BATNAS (SIBATNAS &mdash; BIG)</strong> dan <strong>GEBCO</strong>. Klik titik mana pun
            pada peta untuk menarik nilai kedalaman dari kedua sumber.</p>
    </div>
    <div class="page-actions">
        <span class="source-note"><i class="fa-solid fa-satellite-dish"></i> WMS BATNAS &middot; WMS GEBCO &middot; API GEBCO</span>
    </div>
</div>

<div class="callout callout-info"><i class="fa-solid fa-circle-info"></i>
    <div><strong>Sumber data langsung (API/WMS):</strong>
        <strong>BATNAS</strong> &mdash; GeoServer SIBATNAS BIG (<code>sibatnas.big.go.id/geoserver</code>, grid pemeruman
        100&nbsp;m di area survei) &nbsp;&bull;&nbsp;
        <strong>GEBCO</strong> &mdash; WMS resmi <code>wms.gebco.net</code> (GEBCO terbaru) dan grid GEBCO 2021
        (via OpenDEM) untuk cakupan seluruh Indonesia. Nilai kedalaman per titik memakai API GEBCO 2020
        (OpenTopoData) + GetFeatureInfo BATNAS, diambil sisi server dengan cache agar hemat kuota.</div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h3 class="card-title"><i class="fa-solid fa-water"></i> Peta Batimetri Indonesia + BETRI</h3>
            <p class="card-sub">Centang layer untuk menampilkan sumber batimetri &middot; klik titik pada peta untuk tarik kedalaman</p>
        </div>
        <div class="card-tools">
            <div class="input-group">
                <select class="form-control" id="bathyBasemap" style="width:170px">
                    <option value="imagery">Imagery (Esri)</option>
                    <option value="ocean">Laut (Esri Ocean)</option>
                    <option value="osm">OpenStreetMap</option>
                </select>
            </div>
        </div>
    </div>
    <div class="card-body" style="position:relative">
        <div class="map-box lg" id="bathyMap"><span class="map-coord" id="bathyCoord">—</span></div>

        <!-- Prakiraan angin resmi BMKG: stasiun/lokasi daratan pesisir, bukan wind field laut. -->
        <section class="no-print" id="bmkgWindPanel" aria-label="Prakiraan angin BMKG" style="margin-top:12px;padding:14px;border:1px solid #a8d1e4;border-radius:10px;background:#f1f9fd;color:#164459">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
                <strong><i class="fa-solid fa-wind"></i> Prakiraan Angin BMKG — Pesisir Banten</strong>
                <span id="bmkgWindStatus" role="status" style="font-size:12px">Mengambil data BMKG...</span>
            </div>
            <div style="display:flex;gap:22px;align-items:center;flex-wrap:wrap;margin-top:10px">
                <div><small>Kecepatan angin</small><div id="bmkgWindSpeed" style="font-weight:700;font-size:23px">— km/jam</div><small id="bmkgWindKnots">— knot</small></div>
                <div><small>Arah angin (asal)</small><div id="bmkgWindDir" style="font-size:19px;font-weight:700">—</div></div>
                <div><small>Lokasi prakiraan</small><div id="bmkgWindLocation" style="font-weight:600">Kasemen, Banten</div><small id="bmkgWindTime">—</small></div>
                <div style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
                    <label style="font-size:12px">Kode kelurahan (ADM4)<br><input id="bmkgWindAdm4" value="36.73.02.1001" type="text" inputmode="text" maxlength="13" aria-label="Kode wilayah BMKG" style="padding:7px;max-width:146px;border:1px solid #c5d8e1;border-radius:6px"></label>
                    <button id="bmkgWindRefresh" type="button" class="btn btn-outline btn-sm"><i class="fa-solid fa-rotate"></i> Perbarui</button>
                </div>
            </div>
            <div style="font-size:12px;margin-top:10px;line-height:1.5">
                <strong>Sumber: <a href="https://data.bmkg.go.id/prakiraan-cuaca/" target="_blank" rel="noopener noreferrer">BMKG — Prakiraan Cuaca Terbuka</a>.</strong>
                Prakiraan per 3 jam untuk kelurahan pesisir; <strong>bukan pengukuran angin real-time di laut</strong> dan tidak mewakili semua titik pada peta batimetri.
            </div>
        </section>

        <div class="no-print" style="display:flex;gap:16px;flex-wrap:wrap;margin-top:12px;align-items:center">
            <span class="form-label" style="margin:0">Layer Batimetri:</span>
            <label style="display:inline-flex;gap:7px;align-items:center;font-size:13px;font-weight:600">
                <input type="checkbox" class="bathy-chk" data-layer="batnas" checked>
                <i class="fa-solid fa-flag" style="color:#C62828"></i> BATNAS (SIBATNAS BIG)
                <span class="src-dot" id="dotBatnas"></span>
            </label>
            <label style="display:inline-flex;gap:7px;align-items:center;font-size:13px;font-weight:600">
                <input type="checkbox" class="bathy-chk" data-layer="gebco21" checked>
                <i class="fa-solid fa-oil-can" style="color:#2453C8"></i> GEBCO 2021 (grid global)
                <span class="src-dot" id="dotGebco21"></span>
            </label>
            <label style="display:inline-flex;gap:7px;align-items:center;font-size:13px;font-weight:600">
                <input type="checkbox" class="bathy-chk" data-layer="gebcoLatest">
                <i class="fa-solid fa-globe" style="color:#0E7C7B"></i> GEBCO terbaru (WMS resmi)
                <span class="src-dot" id="dotGebcoLatest"></span>
            </label>
        </div>

        <div class="no-print" style="display:flex;gap:16px;flex-wrap:wrap;margin:14px 0;align-items:center;padding:12px;border:1px solid #b7d9e9;border-radius:10px;background:rgba(40,154,193,.06)">
            <strong><i class="fa-solid fa-water"></i> Data BETRI (hasil olahan lokal):</strong>
            <label style="display:inline-flex;gap:7px;align-items:center;font-size:13px"><input type="checkbox" class="betri-chk" data-betri="raster" checked> Warna kedalaman BETRI</label>
            <label style="display:inline-flex;gap:7px;align-items:center;font-size:13px"><input type="checkbox" class="betri-chk" data-betri="contour"> Kontur kedalaman BETRI</label>
            <label style="display:inline-flex;gap:7px;align-items:center;font-size:13px"><input type="checkbox" class="betri-chk" data-betri="points"> Titik BETRI</label>
            <button type="button" id="betriZoom" class="btn btn-outline btn-sm">Fokus area BETRI</button>
            <span class="source-note" id="betriStatus">Raster lokal siap; kontur/titik dimuat ketika dipilih</span>
        </div>

        <div class="bathy-tools no-print">
            <span class="form-label" style="margin:0"><i class="fa-solid fa-crosshairs"></i> Arahkan ke:</span>
            <button type="button" class="btn btn-outline btn-sm bathy-preset" data-bounds="-11.3,93.0,7.2,141.8">Seluruh Indonesia</button>
            <button type="button" class="btn btn-outline btn-sm bathy-preset" data-bounds="6.5,94.5,-6.5,106.5">Sumatra</button>
            <button type="button" class="btn btn-outline btn-sm bathy-preset" data-bounds="-5.0,105.0,-7.5,115.5">Jawa</button>
            <button type="button" class="btn btn-outline btn-sm bathy-preset" data-bounds="4.5,108.5,-4.5,119.5">Kalimantan</button>
            <button type="button" class="btn btn-outline btn-sm bathy-preset" data-bounds="1.5,118.0,-6.5,125.5">Sulawesi</button>
            <button type="button" class="btn btn-outline btn-sm bathy-preset" data-bounds="-8.0,114.0,-11.5,125.5">Bali &amp; Nusa Tenggara</button>
            <button type="button" class="btn btn-outline btn-sm bathy-preset" data-bounds="2.5,124.0,-8.5,131.5">Maluku</button>
            <button type="button" class="btn btn-outline btn-sm bathy-preset" data-bounds="0.0,130.5,-9.5,141.5">Papua</button>
        </div>
    </div>
    <div class="map-legend">
        <span class="legend-item"><span class="legend-swatch batnas"></span> Grid BATNAS 100 m (area survei BIG)</span>
        <span class="legend-item">Skala kedalaman (GEBCO):
            <span class="depth-legend"></span>
            <span>0 m &rarr; 6.000 m</span>
        </span>
        <span class="legend-item"><i class="fa-solid fa-hand-pointer"></i> Klik peta &rarr; tarik kedalaman BATNAS &amp; GEBCO</span>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-top:16px">
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title"><i class="fa-solid fa-flag" style="color:#C62828"></i> BATNAS &mdash; SIBATNAS BIG</h3>
                <p class="card-sub">Sistem Batimetri Nasional, Badan Informasi Geospasial</p>
            </div>
        </div>
        <div class="card-body" style="font-size:13px;color:var(--muted);line-height:1.7">
            Data batimetri resmi nasional yang ditarik dari GeoServer SIBATNAS
            (<code>sibatnas.big.go.id/geoserver</code>). Layer memuat <strong>grid pemeruman 100&nbsp;m</strong> pada
            area survei (Balikpapan, Samarinda, Banjarmasin, TJK, Morowali, Sorong, Ternate, Morotai, Batam, MBTK,
            Bantaeng, Palembang, Sampit, LKI Barat Sumatera, dll) — sehingga tampil <em>patchy</em>: merah tebal
            = grid ter survei, kosong = belum ada grid terbuka. Nilai kedalaman per titik diambil melalui
            <strong>GetFeatureInfo</strong> (properti <code>NIKDLM</code> = kedalaman dalam meter).
        </div>
    </div>
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title"><i class="fa-solid fa-oil-can" style="color:#2453C8"></i> GEBCO &mdash; Grid Global</h3>
                <p class="card-sub">General Bathymetric Chart of the Oceans</p>
            </div>
        </div>
        <div class="card-body" style="font-size:13px;color:var(--muted);line-height:1.7">
            Grid topografi-batimetri global (resolusi &plusmn;450&nbsp;m) yang mencakup <strong>seluruh perairan
            Indonesia</strong>. Ditarik dari dua jalur: WMS resmi <code>wms.gebco.net</code> (GEBCO terbaru — bila
            server sedang sibuk, layer otomatis ditandai merah) dan grid <strong>GEBCO 2021</strong> via OpenDEM
            sebagai tampilan utama. Kedalaman per titik memakai API GEBCO 2020 (OpenTopoData) dengan cache sisi
            server.
        </div>
    </div>
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title"><i class="fa-solid fa-lightbulb"></i> Cara Pakai untuk KJA/OWC</h3>
                <p class="card-sub">Pertimbangan kedalaman untuk penempatan alat</p>
            </div>
        </div>
        <div class="card-body" style="font-size:13px;color:var(--muted);line-height:1.7">
            Gunakan peta ini untuk mencermati kedalaman di sekitar lokasi keramba/unit OWC: kedalaman menentukan
            jangkar, panjang tali, dan potensi energi gelombang. <strong>Klik titik</strong> untuk tarik kedalaman
            dari BATNAS (tingkat akurasi survei, area terbatas) dan GEBCO (seluruh wilayah, resolusi grid global).
            Bandingkan keduanya — bila beda jauh, prioritas data survei BATNAS.
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
