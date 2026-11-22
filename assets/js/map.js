/* =====================================================================
   BOLU — map.js (webgis.php + peta dashboard)
   Leaflet + OpenStreetMap: marker per jenis perangkat, filter layer,
   popup nilai terbaru, pencarian, tampilan koordinat.

   Titik perangkat digambar PERTAMA dari data boot sisi-server
   (window.SKJA_BOOT.geo, ditanam di HTML) sehingga peta + marker selalu
   tampil tanpa menunggu/menunggak fetch API. Panggilan api/devices-geo.php
   hanya menyegarkan popup dengan nilai terbaru; bila gagal, marker boot
   tetap terlihat.
   ===================================================================== */
(function () {
    'use strict';

    var mapEl = document.getElementById('webgisMap');
    if (!mapEl || !window.L) return;

    var map = L.map('webgisMap', { zoomControl: true, scrollWheelZoom: true });
    SKJAtileLayer(map);
    window.addEventListener('load', function () { setTimeout(function () { map.invalidateSize(); }, 60); });

    var coordEl = document.getElementById('mapCoord');
    map.on('mousemove', function (ev) {
        if (coordEl) coordEl.textContent = ev.latlng.lat.toFixed(6) + ', ' + ev.latlng.lng.toFixed(6);
    });
    map.on('mouseout', function () { if (coordEl) coordEl.textContent = '—'; });

    var ICON_MAP = {
        owc: ['fa-bolt', 'owc'],
        wave_sensor: ['fa-water', 'wave'],
        water_sensor: ['fa-droplet', 'water'],
        camera: ['fa-video', 'camera'],
        gateway: ['fa-tower-broadcast', 'wave']
    };
    var TYPE_LABEL = {
        owc: 'Unit OWC', wave_sensor: 'Sensor Gelombang',
        water_sensor: 'Sensor Kualitas Air', camera: 'Kamera FishCount', gateway: 'Gateway'
    };

    var layers = {};       // layerGroup per tipe
    var markers = [];      // {device, marker}
    var viewSet = false;

    function setMapView(center, zoom) {
        if (viewSet || !center) return;
        map.setView([center.lat, center.lng], zoom || 15);
        viewSet = true;
    }

    function buildPopup(d) {
        return '<b>' + SKJAesc(d.name) + '</b> <span class="source-note">(' + SKJAesc(d.code) + ')</span><br>'
            + '<small>' + SKJAesc(TYPE_LABEL[d.type] || d.type) + '</small>'
            + '<div class="pop-status">' + SKJAstatusPill(d.data_status) + '</div>'
            + '<div>' + (d.latest ? (SKJAformatLatest(d) || '<small>Belum ada data</small>') : '<small>Menunggu data pengukuran…</small>') + '</div>'
            + '<hr style="margin:8px 0;border:0;border-top:1px solid #eee">'
            + '<small><i class="fa-solid fa-location-dot"></i> ' + parseFloat(d.lat).toFixed(6) + ', ' + parseFloat(d.lng).toFixed(6) + '<br>'
            + SKJAesc(d.location_name || '') + '</small>';
    }

    /* Gambar ulang seluruh marker (panggil ulang = bersih tanpa duplikat). */
    function renderDevices(devices) {
        Object.keys(layers).forEach(function (k) { layers[k].clearLayers(); });
        markers.length = 0;
        (devices || []).forEach(function (d) {
            if (d.lat === null || d.lng === null) return;
            var type = d.type;
            if (!layers[type]) layers[type] = L.layerGroup().addTo(map);
            var ic = ICON_MAP[type] || ICON_MAP.gateway;
            var html = '<div class="device-marker ' + ic[1] + (d.status === 'online' ? '' : ' offline')
                     + '" style="width:36px;height:36px"><i class="fa-solid ' + ic[0] + '"></i></div>';
            var marker = L.marker([parseFloat(d.lat), parseFloat(d.lng)], {
                icon: L.divIcon({ html: html, className: '', iconSize: [36, 36], iconAnchor: [18, 18] })
            });
            marker.bindPopup(buildPopup(d));
            marker.addTo(layers[type]);
            markers.push({ d: d, marker: marker });
        });
    }

    /* ---------- 1) Render boot: peta + marker tampil seketi ---------- */
    var bootGeo = (window.SKJA_BOOT && window.SKJA_BOOT.geo) || null;
    if (bootGeo) {
        setMapView(bootGeo.center, bootGeo.zoom);
        renderDevices(bootGeo.devices);
    }

    /* ---------- 2) Polling API: perbarui popup dengan nilai terbaru ---------- */
    var dataPromise = SKJAfetch('api/devices-geo.php');
    dataPromise.then(function (j) {
        if (!j.ok) {
            if (!viewSet) {
                map.setView([-5.77, 105.264], 13);
                viewSet = true;
            }
            return; // marker boot tetap tampil, tanpa peta kosong
        }
        setMapView(j.data.center, j.data.zoom || 15);
        renderDevices(j.data.devices || []);
    });

    /* ---------- Filter layer (aktif kapan pun, ikut marker boot/api) ---------- */
    document.querySelectorAll('.layer-chk').forEach(function (chk) {
        chk.addEventListener('change', function () {
            var grp = layers[chk.dataset.type];
            if (!grp) return;
            if (chk.checked) grp.addTo(map); else map.removeLayer(grp);
        });
    });

    /* ---------- Pencarian perangkat (client-side) ---------- */
    function doSearch() {
        var q = (document.getElementById('searchDevice').value || '').toLowerCase().trim();
        if (!q) return;
        var found = markers.filter(function (m) {
            return (m.d.name + ' ' + m.d.code + ' ' + (m.d.location_name || '')).toLowerCase().indexOf(q) !== -1;
        });
        if (found.length) {
            var first = found[0];
            map.flyTo([parseFloat(first.d.lat), parseFloat(first.d.lng)], Math.max(map.getZoom(), 17));
            first.marker.openPopup();
            SKJAtoast(found.length + ' perangkat ditemukan.', 'success');
        } else {
            SKJAtoast('Tidak ada perangkat yang cocok dengan pencarian.', 'info');
        }
    }
    document.getElementById('btnSearch').addEventListener('click', doSearch);
    document.getElementById('searchDevice').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); doSearch(); }
    });

    /* ---------- Tombol "tampilkan di peta" pada tabel ---------- */
    document.querySelectorAll('.btn-fly').forEach(function (btn) {
        btn.addEventListener('click', function () {
            map.flyTo([parseFloat(btn.dataset.lat), parseFloat(btn.dataset.lng)], 18);
        });
    });

    /* ---------------- Form lokasi (admin) ---------------- */
    function locForm(action, d) {
        d = d || {};
        var opts = (window.SKJAdevices || []).map(function (dev) {
            return '<option value="' + dev.id + '"' + (d.id === dev.id ? ' selected' : '') + '>' + SKJAesc(dev.code + ' — ' + dev.name) + '</option>';
        }).join('');
        SKJAmodal(action === 'add' ? 'Tambah Lokasi Perangkat' : 'Ubah Lokasi — ' + SKJAesc(d.code || ''),
            '<form method="post" action="webgis.php" id="locForm">'
            + '<input type="hidden" name="csrf" value="' + document.body.dataset.csrf + '">'
            + '<input type="hidden" name="action" value="save_location">'
            + (action === 'edit' ? '<input type="hidden" name="device_id" value="' + SKJAesc(d.id) + '">' : '')
            + '<div class="form-grid">'
            + (action === 'add'
                ? '<div class="form-group span-2"><label class="form-label">Perangkat <span class="req">*</span></label>'
                  + '<select class="form-select" name="device_id" required>' + opts + '</select></div>'
                : '')
            + '<div class="form-group"><label class="form-label">Latitude <span class="req">*</span></label>'
            + '<input class="form-control" type="number" step="0.0000001" min="-90" max="90" name="latitude" required value="' + SKJAesc(d.lat || '') + '" placeholder="-5.7700000"></div>'
            + '<div class="form-group"><label class="form-label">Longitude <span class="req">*</span></label>'
            + '<input class="form-control" type="number" step="0.0000001" min="-180" max="180" name="longitude" required value="' + SKJAesc(d.lng || '') + '" placeholder="105.2640000"></div>'
            + '<div class="form-group span-2"><label class="form-label">Nama Lokasi</label>'
            + '<input class="form-control" type="text" name="location_name" maxlength="120" value="' + SKJAesc(d.loc || '') + '" placeholder="mis. Titik OWC Teluk Hurun"></div>'
            + '<div class="form-group"><label class="form-label">Tanggal Terpasang</label>'
            + '<input class="form-control" type="date" name="installed_at" value="' + SKJAesc(d.inst || '') + '"></div>'
            + '<div class="form-group"><label class="form-label">Catatan</label>'
            + '<input class="form-control" type="text" name="notes" maxlength="255" value="' + SKJAesc(d.notes || '') + '"></div>'
            + '</div>'
            + '<div style="display:flex;justify-content:flex-end;gap:10px;margin-top:10px">'
            + '<button type="button" class="btn btn-outline" id="locCancel">Batal</button>'
            + '<button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Simpan Lokasi</button>'
            + '</div></form>');
        document.getElementById('locCancel').addEventListener('click', SKJAmodalClose);
    }

    var btnAdd = document.getElementById('btnAddLocation');
    if (btnAdd) btnAdd.addEventListener('click', function () { locForm('add'); });

    document.querySelectorAll('.btn-edit-loc').forEach(function (btn) {
        btn.addEventListener('click', function () {
            locForm('edit', {
                id: btn.dataset.id, code: btn.dataset.code, lat: btn.dataset.lat,
                lng: btn.dataset.lng, loc: btn.dataset.loc, inst: btn.dataset.inst, notes: btn.dataset.notes
            });
        });
    });

    // Daftar perangkat untuk form (dari tabel koordinat yang sudah dirender server)
    window.SKJAdevices = Array.prototype.slice.call(document.querySelectorAll('.btn-edit-loc')).map(function (b) {
        return { id: b.dataset.id, code: b.dataset.code, name: '' };
    });
})();
