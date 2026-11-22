/* =====================================================================
   BOLU — batimetri.js (batimetri.php)
   Peta batimetri seluruh Indonesia dengan layer WMS:
     1. BATNAS  — GeoServer SIBATNAS BIG (grid pemeruman 100 m, transparan)
     2. GEBCO 2021 — OpenDEM (grid global GEBCO 2021, tampilan utama)
     3. GEBCO terbaru — WMS resmi wms.gebco.net (bila server tersedia)
   Klik peta -> api/bathymetry-point.php menarik kedalaman (server-side)
   dari GetFeatureInfo BATNAS (NIKDLM) + API GEBCO 2020 (OpenTopoData).
   ===================================================================== */
(function () {
    'use strict';

    var mapEl = document.getElementById('bathyMap');
    if (!mapEl || !window.L) return;

    /* ---------- Peta + basemap ---------- */
    var map = L.map('bathyMap', { zoomControl: true, scrollWheelZoom: true, minZoom: 4 });
    var currentBase = SKJAtileLayer(map);   // Imagery Esri + fallback OSM/Carto
    window.addEventListener('load', function () {
        setTimeout(function () { map.invalidateSize(); }, 60);
    });

    var coordEl = document.getElementById('bathyCoord');
    map.on('mousemove', function (ev) {
        if (coordEl) coordEl.textContent = ev.latlng.lat.toFixed(5) + ', ' + ev.latlng.lng.toFixed(5);
    });
    map.on('mouseout', function () { if (coordEl) coordEl.textContent = '—'; });

    /* ---------- Pilihan basemap ---------- */
    var OCEAN_BASE = 'https://server.arcgisonline.com/ArcGIS/rest/services/Ocean/World_Ocean_Base/MapServer/tile/{z}/{y}/{x}';
    var OCEAN_REF  = 'https://server.arcgisonline.com/ArcGIS/rest/services/Ocean/World_Ocean_Reference/MapServer/tile/{z}/{y}/{x}';
    var basemapSel = document.getElementById('bathyBasemap');
    basemapSel.addEventListener('change', function () {
        if (currentBase) map.removeLayer(currentBase);
        var mode = basemapSel.value;
        if (mode === 'ocean') {
            currentBase = L.layerGroup([
                L.tileLayer(OCEAN_BASE, { maxZoom: 13, attribution: 'Esri Ocean &middot; GEBCO &middot; NOAA' }),
                L.tileLayer(OCEAN_REF, { maxZoom: 13 })
            ]).addTo(map);
        } else if (mode === 'osm') {
            currentBase = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);
        } else {
            currentBase = SKJAtileLayer(map);
        }
    });

    /* ---------- Layer WMS batimetri ---------- */
    var BATNAS_LAYERS = [
        'sibatnas:AREA_MBES_LKI_BARAT_SUMATERA', 'sibatnas:BALIKPAPAN_100M',
        'sibatnas:BANJARMASIN_100M', 'sibatnas:Batam', 'sibatnas:MBTK',
        'sibatnas:MOROWALI', 'sibatnas:SAMARINDA_100M', 'sibatnas:SAMPIT_100M',
        'sibatnas:SORONG_100M', 'sibatnas:TERNATE_100m',
        'sibatnas:TITIKDALAMPERUM_BTG_100M_GCS', 'sibatnas:TJK_100M',
        'sibatnas:Titik_Kedalaman_Pemeruman_100m', 'sibatnas:bantaeng_100m',
        'sibatnas:big_tanjungpriuk_000020190906161134', 'sibatnas:morotai_100m',
        'sibatnas:palembang_100m'
    ].join(',');

    var overlays = {
        batnas: L.tileLayer.wms('https://sibatnas.big.go.id/geoserver/ows', {
            layers: BATNAS_LAYERS, version: '1.1.1', format: 'image/png',
            transparent: true, opacity: 0.85, crossOrigin: true,
            attribution: 'BATNAS &copy; BIG (SIBATNAS)'
        }),
        gebco21: L.tileLayer.wms('https://www.opendem.info/geoserver/opendem/wms', {
            layers: 'opendem:gebco_2021', version: '1.3.0', format: 'image/png',
            opacity: 0.85, crossOrigin: true,
            attribution: 'GEBCO 2021 &copy; BODC/GEBCO &mdash; disajikan via OpenDEM'
        }),
        gebcoLatest: L.tileLayer.wms('https://wms.gebco.net/mapserv', {
            layers: 'GEBCO_LATEST', version: '1.3.0', format: 'image/png',
            opacity: 0.85, crossOrigin: true,
            attribution: 'GEBCO &copy; BODC (WMS resmi GEBCO)'
        })
    };

    /* ---------- Status sumber (dot hijau/merah) ---------- */
    var stat = { batnas: { load: 0, err: 0 }, gebco21: { load: 0, err: 0 }, gebcoLatest: { load: 0, err: 0 } };
    var dotId = { batnas: 'dotBatnas', gebco21: 'dotGebco21', gebcoLatest: 'dotGebcoLatest' };
    var dotWarn = {
        batnas: 'SIBATNAS BIG tidak merespons dari perangkat ini',
        gebco21: 'OpenDEM/GEBCO 2021 tidak merespons dari perangkat ini',
        gebcoLatest: 'WMS resmi GEBCO sedang tidak tersedia (server sumber bermasalah)'
    };
    function setDot(key) {
        var el = document.getElementById(dotId[key]);
        if (!el) return;
        var s = stat[key];
        el.classList.remove('src-ok', 'src-err');
        if (s.load > 0) { el.classList.add('src-ok'); el.title = 'Sumber aktif'; }
        else if (s.err >= 4) { el.classList.add('src-err'); el.title = dotWarn[key]; }
    }
    Object.keys(overlays).forEach(function (key) {
        var ly = overlays[key];
        ly.on('tileload', function () { stat[key].load++; setDot(key); });
        ly.on('tileerror', function () { stat[key].err++; setDot(key); });
    });

    /* ---------- Toggle checkbox ---------- */
    document.querySelectorAll('.bathy-chk').forEach(function (chk) {
        chk.addEventListener('change', function () {
            var key = chk.dataset.layer;
            if (chk.checked) overlays[key].addTo(map);
            else map.removeLayer(overlays[key]);
        });
    });

    /* ---------- Preset wilayah (bounds: s,w,n,e) ---------- */
    document.querySelectorAll('.bathy-preset').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var p = (btn.dataset.bounds || '').split(',').map(parseFloat);
            if (p.length !== 4 || p.some(isNaN)) return;
            map.flyToBounds([[p[0], p[1]], [p[2], p[3]]], { padding: [24, 24], duration: 0.8 });
        });
    });

    /* ---------- Tampilkan layer default ---------- */
    overlays.gebco21.addTo(map);
    overlays.batnas.addTo(map);

    /* ---------- Indonesia fit ---------- */
    map.fitBounds([[-11.3, 93.0], [7.2, 141.8]], { padding: [10, 10] });

    /* Extension BETRI: layer sumber lokal, aman tanpa mengubah WMS/API asli. */
    window.BOLU_BETRI_MAP = map;
    window.dispatchEvent(new Event('bolu:batimetri-ready'));

    /* ---------- Klik peta -> tarik kedalaman ---------- */
    function fmtNum(v, dec) {
        return Number(v).toLocaleString('id-ID', { minimumFractionDigits: dec, maximumFractionDigits: dec });
    }
    function depthHtml(d) {
        var html = '<b><i class="fa-solid fa-ruler-vertical"></i> Kedalaman di titik ini</b>';
        /* BATNAS */
        var b = d.batnas || {};
        if (b.depth_m !== null && b.depth_m !== undefined) {
            html += '<div style="margin-top:6px"><span class="legend-swatch batnas" style="display:inline-block"></span> '
                + '<b>BATNAS (SIBATNAS BIG):</b> ' + fmtNum(b.depth_m, 2) + ' m di bawah muka laut';
            if (b.layer) html += ' <span class="source-note">(' + SKJAesc(b.layer) + ')</span>';
            html += '</div>';
        } else {
            html += '<div style="margin-top:6px"><span class="legend-swatch batnas" style="display:inline-block"></span> '
                + '<b>BATNAS:</b> tidak ada grid survei 100 m di titik ini'
                + (b.error ? ' <span class="source-note">(' + SKJAesc(b.error) + ')</span>' : '') + '</div>';
        }
        /* GEBCO */
        var g = d.gebco || {};
        if (g.elev_m !== null && g.elev_m !== undefined) {
            html += (g.elev_m < 0)
                ? '<div style="margin-top:4px"><span class="legend-swatch gebco" style="display:inline-block"></span> '
                  + '<b>GEBCO:</b> ' + fmtNum(-g.elev_m, 1) + ' m di bawah muka laut</div>'
                : '<div style="margin-top:4px"><span class="legend-swatch gebco" style="display:inline-block"></span> '
                  + '<b>GEBCO:</b> daratan (elevasi ' + fmtNum(g.elev_m, 1) + ' m)</div>';
        } else {
            html += '<div style="margin-top:4px"><span class="legend-swatch gebco" style="display:inline-block"></span> '
                + '<b>GEBCO:</b> tidak dapat diambil'
                + (g.error ? ' <span class="source-note">(' + SKJAesc(g.error) + ')</span>' : '') + '</div>';
        }
        html += '<hr style="margin:8px 0 4px;border:0;border-top:1px solid #eee">'
            + '<small><i class="fa-solid fa-location-dot"></i> ' + Number(d.lat).toFixed(5) + ', '
            + Number(d.lon).toFixed(5) + (d.cached ? ' &middot; <span class="source-note">cache 6 jam</span>' : '')
            + '<br>Sumber: GetFeatureInfo SIBATNAS BIG &middot; API GEBCO 2020</small>';
        return html;
    }

    /* Klik peta: kedalaman pada koordinat klik + PRAKIRAAN ANGIN WILAYAH ACUAN.
       BMKG /publik/prakiraan-cuaca berbasis kode desa (ADM4), bukan API grid
       angin laut per koordinat. Jangan menampilkan prakiraan Kasemen sebagai
       pengukuran atau prakiraan tepat di laut pada koordinat yang diklik. */
    map.on('click', function (e) {
        var lat = e.latlng.lat, lon = e.latlng.lng;
        var active = true;
        var depth = '<small><i class="fa-solid fa-spinner fa-spin"></i> Mengambil data kedalaman...</small>';
        var wind = '<small><i class="fa-solid fa-spinner fa-spin"></i> Mengambil prakiraan angin BMKG...</small>';
        var pop = L.popup({ maxWidth: 400, minWidth: 265 })
            .setLatLng(e.latlng)
            .setContent('<b>Data titik peta</b>')
            .openOn(map);
        pop.on('remove', function () { active = false; });
        function draw() {
            if (!active) return;
            pop.setContent('<div style="min-width:250px;max-width:360px">'
                + '<b>Data titik peta</b><br><small>Koordinat klik: ' + lat.toFixed(5) + ', ' + lon.toFixed(5) + '</small>'
                + '<div style="margin-top:9px">' + depth + '</div>'
                + '<hr style="margin:9px 0;border:0;border-top:1px solid #ddd">'
                + '<div>' + wind + '</div></div>');
        }
        draw();

        fetch('api/bathymetry-point.php?lat=' + lat.toFixed(5) + '&lon=' + lon.toFixed(5), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin'
        }).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
          .then(function (d) {
              depth = d && d.ok === true ? depthHtml(d)
                  : '<b>Kedalaman</b><br><small>Data tidak tersedia: ' + SKJAesc((d && d.error) || 'Respons tidak valid') + '</small>';
              draw();
          }).catch(function () {
              depth = '<b>Kedalaman</b><br><small>API kedalaman tidak dapat dihubungi.</small>'; draw();
          });

        var input = document.getElementById('bmkgWindAdm4');
        var adm4 = input ? input.value.trim() : '36.73.02.1001';
        if (!/^\d{2}\.\d{2}\.\d{2}\.\d{4}$/.test(adm4)) {
            wind = '<b>Angin BMKG</b><br><small>Kode wilayah ADM4 tidak valid di panel angin.</small>';
            draw();
            return;
        }
        fetch('api/bmkg-wind.php?adm4=' + encodeURIComponent(adm4), {
            credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
          .then(function (d) {
              if (!d || d.ok !== true) throw new Error((d && d.error) || 'Data BMKG tidak tersedia');
              var place = SKJAesc(d.place || ('ADM4 ' + adm4));
              var speed = Number(d.speed_kmh);
              var knots = Number(d.speed_knots);
              wind = '<b><i class="fa-solid fa-wind"></i> Prakiraan angin BMKG — lokasi acuan</b>'
                  + '<div style="margin-top:5px"><b>'
                  + (Number.isFinite(speed) ? fmtNum(speed, 1) + ' km/jam' : '—') + '</b> ('
                  + (Number.isFinite(knots) ? fmtNum(knots, 1) + ' knot' : '—') + ')'
                  + ' &middot; Arah asal: <b>' + SKJAesc(d.wind_from || '—') + '</b></div>'
                  + '<small>Wilayah prakiraan: ' + place + '<br>'
                  + 'Waktu prakiraan: ' + SKJAesc(d.forecast_local || '—') + ' WIB'
                  + (d.stale ? '<br><b>Prakiraan telah lewat waktunya</b>' : '')
                  + '<br><b>Catatan:</b> angin ini untuk wilayah acuan, BUKAN nilai angin di koordinat klik atau pengukuran laut real-time.</small>';
              draw();
          }).catch(function (err) {
              wind = '<b>Prakiraan angin BMKG</b><br><small>Belum tersedia: ' + SKJAesc(err.message) + '</small>';
              draw();
          });
    });
})();
