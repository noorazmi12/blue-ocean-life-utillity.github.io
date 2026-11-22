/* =====================================================================
   BOLU — lokasi-kja.js (lokasi-kja.php)
   Peta titik usulan lokasi KJA di Banten:
     - Marker berwarna per kelas kesesuaian (S1/S2/S3/N) dari window.BOLU_KJA_SITES
     - Layer batimetri GEBCO 2021 (OpenDEM) + opsi grid BATNAS (SIBATNAS BIG)
     - Popup detail + tombol "Cek kedalaman live" -> api/bathymetry-point.php
       (GetFeatureInfo BATNAS NIKDLM + API GEBCO 2020, server-side proxy)
     - Filter kelas, sinkron daftar titik <-> peta <-> tabel
   ===================================================================== */
(function () {
    'use strict';

    var mapEl = document.getElementById('kjaMap');
    if (!mapEl || !window.L) return;

    var SITES = window.BOLU_KJA_SITES || [];
    if (!SITES.length) return;

    var CLS_COLOR = { S1: '#2FA36B', S2: '#E8A13D', S3: '#E06A4C', N: '#8D95A1' };
    var SHELTER_LABEL = { teluk: 'Teluk tertutup', semi: 'Semi terlindung', terbuka: 'Perairan terbuka' };

    /* ---------- Peta + basemap ---------- */
    var map = L.map('kjaMap', { zoomControl: true, scrollWheelZoom: true, minZoom: 5 });
    var currentBase = SKJAtileLayer(map);
    window.addEventListener('load', function () { setTimeout(function () { map.invalidateSize(); }, 60); });

    var coordEl = document.getElementById('kjaCoord');
    map.on('mousemove', function (ev) {
        if (coordEl) coordEl.textContent = ev.latlng.lat.toFixed(5) + ', ' + ev.latlng.lng.toFixed(5);
    });
    map.on('mouseout', function () { if (coordEl) coordEl.textContent = '—'; });

    var OCEAN_BASE = 'https://server.arcgisonline.com/ArcGIS/rest/services/Ocean/World_Ocean_Base/MapServer/tile/{z}/{y}/{x}';
    var OCEAN_REF  = 'https://server.arcgisonline.com/ArcGIS/rest/services/Ocean/World_Ocean_Reference/MapServer/tile/{z}/{y}/{x}';
    var basemapSel = document.getElementById('kjaBasemap');
    if (basemapSel) {
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
    }

    /* ---------- Layer WMS batimetri ---------- */
    var overlays = {
        gebco21: L.tileLayer.wms('https://www.opendem.info/geoserver/opendem/wms', {
            layers: 'opendem:gebco_2021', version: '1.3.0', format: 'image/png',
            opacity: 0.8, crossOrigin: true,
            attribution: 'GEBCO 2021 &copy; BODC/GEBCO &mdash; via OpenDEM'
        }),
        batnas: L.tileLayer.wms('https://sibatnas.big.go.id/geoserver/ows', {
            layers: [
                'sibatnas:AREA_MBES_LKI_BARAT_SUMATERA', 'sibatnas:Batam',
                'sibatnas:TITIKDALAMPERUM_BTG_100M_GCS', 'sibatnas:Titik_Kedalaman_Pemeruman_100m',
                'sibatnas:big_tanjungpriok_000020190906161134', 'sibatnas:palembang_100m'
            ].join(','), version: '1.1.1', format: 'image/png',
            transparent: true, opacity: 0.85, crossOrigin: true,
            attribution: 'BATNAS &copy; BIG (SIBATNAS)'
        })
    };
    document.querySelectorAll('.kja-chk').forEach(function (chk) {
        chk.addEventListener('change', function () {
            var key = chk.dataset.layer;
            if (!overlays[key]) return;
            if (chk.checked) overlays[key].addTo(map);
            else map.removeLayer(overlays[key]);
        });
    });
    overlays.gebco21.addTo(map);   // default: batimetri global tampil

    /* ---------- Popup detail titik ---------- */
    function scoreBar(score, color) {
        return '<div style="background:#E9ECF2;border-radius:6px;height:10px;overflow:hidden;margin:6px 0 2px">'
            + '<span style="display:block;height:100%;width:' + Math.min(100, score) + '%;background:' + color + '"></span></div>';
    }
    function popupHtml(s) {
        var c = CLS_COLOR[s.cls] || '#888';
        var html = '<div style="min-width:250px">'
            + '<div style="display:flex;gap:8px;align-items:center">'
            + '<span class="kja-class-chip" style="background:' + c + '">' + s.cls + '</span>'
            + '<b>' + SKJAesc(s.name) + '</b></div>'
            + '<div class="source-note" style="margin-top:2px"><i class="fa-solid fa-location-dot"></i> '
            + SKJAesc(s.kab) + ' &middot; ' + SKJAesc(s.kec || '—') + '</div>'
            + '<div style="margin-top:6px;font-size:13px;line-height:1.6">'
            + '<i class="fa-solid fa-ruler-vertical"></i> <b>Kedalaman:</b> '
            + (s.depth !== null ? fmtNum(s.depth, 0) + ' m' : '—') + ' <span class="source-note">(GEBCO 2020)</span><br>'
            + '<i class="fa-solid fa-shield-halved"></i> <b>Kelindungan:</b> ' + SKJAesc(SHELTER_LABEL[s.shelter] || s.shelter) + '<br>'
            + '<i class="fa-solid fa-fish"></i> <b>Ikan usulan:</b> ' + SKJAesc(s.species || '—') + '</div>'
            + scoreBar(s.score, c)
            + '<div style="font-size:12px"><b>Skor kesesuaian: ' + s.score + '/100</b> &middot; '
            + 'kedalaman ' + s.sd + ' &middot; kelindungan ' + s.ss + ' &middot; akses ' + s.sa + ' &middot; konflik ' + s.sc + '</div>';
        if (s.constraint) {
            html += '<div style="margin-top:6px;font-size:12px;color:#B4632A"><i class="fa-solid fa-triangle-exclamation"></i> '
                + SKJAesc(s.constraint) + '</div>';
        }
        if (s.notes) {
            html += '<div style="margin-top:6px;font-size:12px;color:#556;line-height:1.5">' + SKJAesc(s.notes) + '</div>';
        }
        html += '<div class="kja-live-box" style="margin-top:8px">'
            + '<button type="button" class="btn btn-outline btn-sm kja-live-depth" data-lat="' + s.lat + '" data-lon="' + s.lon + '">'
            + '<i class="fa-solid fa-satellite-dish"></i> Cek kedalaman live (BATNAS &amp; GEBCO)</button>'
            + '<div class="kja-live-result" style="display:none;margin-top:6px;font-size:12px"></div></div>'
            + '<hr style="margin:8px 0 4px;border:0;border-top:1px solid #eee">'
            + '<small><i class="fa-solid fa-location-dot"></i> ' + s.lat.toFixed(4) + ', ' + s.lon.toFixed(4)
            + ' &middot; Usulan berbasis data publik — verifikasi lapangan diperlukan</small></div>';
        return html;
    }
    function fmtNum(v, dec) {
        return Number(v).toLocaleString('id-ID', { minimumFractionDigits: dec, maximumFractionDigits: dec });
    }

    /* ---------- Marker ---------- */
    var markers = {};
    SITES.forEach(function (s) {
        var c = CLS_COLOR[s.cls] || '#888';
        var icon = L.divIcon({
            className: '',
            html: '<span class="kja-pin" style="--pin:' + c + '"><span class="kja-pin-label">' + s.cls + '</span></span>',
            iconSize: [38, 38], iconAnchor: [19, 34], popupAnchor: [0, -30]
        });
        var mk = L.marker([s.lat, s.lon], { icon: icon, title: s.name + ' (' + s.cls + ')' })
            .bindPopup(popupHtml(s), { maxWidth: 360 })
            .addTo(map);
        markers[s.id] = mk;
        mk.on('popupopen', function (ev) { bindLiveDepth(ev.popup); });
    });

    /* ---------- Cek kedalaman live (BATNAS + GEBCO) ---------- */
    function bindLiveDepth(popup) {
        var btn = popup.getElement().querySelector('.kja-live-depth');
        if (!btn) return;
        var box = popup.getElement().querySelector('.kja-live-result');
        btn.addEventListener('click', function () {
            box.style.display = 'block';
            box.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Mengambil kedalaman dari BATNAS &amp; GEBCO…';
            fetch('api/bathymetry-point.php?lat=' + btn.dataset.lat + '&lon=' + btn.dataset.lon, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || d.ok !== true) {
                        box.innerHTML = '<span class="badge badge-red">' + SKJAesc((d && d.error) || 'Gagal mengambil data.') + '</span>';
                        return;
                    }
                    var parts = [];
                    var b = d.batnas || {};
                    parts.push('<span class="legend-swatch batnas" style="display:inline-block"></span> <b>BATNAS:</b> '
                        + (b.depth_m !== null && b.depth_m !== undefined
                            ? fmtNum(b.depth_m, 1) + ' m' + (b.layer ? ' <span class="source-note">(' + SKJAesc(b.layer) + ')</span>' : '')
                            : 'tidak ada grid survei di titik ini'));
                    var g = d.gebco || {};
                    parts.push('<span class="legend-swatch gebco" style="display:inline-block"></span> <b>GEBCO:</b> '
                        + (g.elev_m !== null && g.elev_m !== undefined
                            ? (g.elev_m < 0 ? fmtNum(-g.elev_m, 1) + ' m di bawah muka laut'
                                             : 'daratan (elevasi ' + fmtNum(g.elev_m, 1) + ' m)')
                            : 'tidak dapat diambil'));
                    box.innerHTML = parts.join('<br>');
                })
                .catch(function () {
                    box.innerHTML = '<span class="badge badge-red">API tidak dapat dihubungi — coba lagi.</span>';
                });
        });
    }

    /* ---------- Filter kelas ---------- */
    var currentFilter = 'all';
    function applyFilter() {
        document.querySelectorAll('.kja-site-item[data-id]').forEach(function (li) {
            var show = currentFilter === 'all' || li.dataset.cls === currentFilter;
            li.style.display = show ? '' : 'none';
        });
        SITES.forEach(function (s) {
            var mk = markers[s.id];
            if (!mk) return;
            if (currentFilter === 'all' || s.cls === currentFilter) {
                if (!map.hasLayer(mk)) mk.addTo(map);
            } else if (map.hasLayer(mk)) {
                map.removeLayer(mk);
            }
        });
    }
    document.querySelectorAll('.kja-filter').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.kja-filter').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            currentFilter = btn.dataset.cls || 'all';
            applyFilter();
        });
    });

    /* ---------- Daftar & tabel -> loncat ke peta ---------- */
    function flyToSite(id) {
        var mk = markers[id];
        if (!mk) return;
        if (!map.hasLayer(mk)) mk.addTo(map);
        map.flyTo(mk.getLatLng(), Math.max(map.getZoom(), 11), { duration: 0.7 });
        setTimeout(function () { mk.openPopup(); }, 750);
    }
    document.querySelectorAll('.kja-site-item[data-id]').forEach(function (li) {
        li.addEventListener('click', function () { flyToSite(parseInt(li.dataset.id, 10)); });
    });
    document.querySelectorAll('#kjaTable tbody tr[data-id]').forEach(function (tr) {
        tr.style.cursor = 'pointer';
        tr.addEventListener('click', function () { flyToSite(parseInt(tr.dataset.id, 10)); });
    });

    /* ---------- Fit Banten ---------- */
    map.fitBounds([[-6.72, 105.15], [-5.72, 106.35]], { padding: [16, 16] });
})();
