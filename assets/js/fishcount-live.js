/* BOLU FishCount Live — Flask bridge + penempatan kamera Leaflet */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var cfg = window.BOLU_FISHCOUNT_CAMERA || {};
  var bridgeInput = $('fcBridgeUrl'), stream = $('fcLiveStream');
  var placeholder = $('fcStreamPlaceholder');
  var bridgeUrl = localStorage.getItem('boluFishBridge') || 'http://127.0.0.1:5000';
  var timer = null, marker = null, map = null, selected = null;

  function cleanBase(v) { return String(v || '').trim().replace(/\/+$/, ''); }
  function setStatus(text, online) {
    $('fcBridgeStatus').innerHTML = '<span class="dot ' + (online ? 'dot-live' : 'dot-offline') + '"></span> ' + text;
    $('fcConnectionBadge').innerHTML = '<span class="dot ' + (online ? 'dot-live' : 'dot-offline') + '"></span> ' + (online ? 'AI Bridge Online' : 'Bridge Offline');
    $('fcConnectionBadge').className = 'badge ' + (online ? 'badge-green' : 'badge-gray');
  }
  function updateStream() {
    var base = cleanBase(bridgeInput.value);
    if (!base) return;
    localStorage.setItem('boluFishBridge', base);
    stream.src = base + '/video_feed?t=' + Date.now();
    stream.hidden = false;
    placeholder.hidden = true;
    stream.onerror = function () {
      stream.hidden = true; placeholder.hidden = false;
      placeholder.querySelector('strong').textContent = 'Stream belum tersedia';
      placeholder.querySelector('span').textContent = 'Pastikan Flask aktif dan endpoint /video_feed dapat dibuka.';
    };
    stream.onload = function () { stream.hidden = false; placeholder.hidden = true; };
  }
  function poll() {
    var base = cleanBase(bridgeInput.value);
    if (!base) return;
    fetch(base + '/api/counts', { cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (d) {
        setStatus(d.status || 'Terhubung', true);
        $('fcTotal').textContent = Number(d.total || 0).toLocaleString('id-ID');
        $('fcLeftRight').textContent = Number(d.left_to_right || 0).toLocaleString('id-ID');
        $('fcRightLeft').textContent = Number(d.right_to_left || 0).toLocaleString('id-ID');
        $('fcVisibleFish').textContent = Number(d.visible_now || 0);
        $('fcVisibleGeneral').textContent = Number(d.general_visible || 0);
        $('fcFps').textContent = Number(d.fps || 0).toFixed(2);
        $('fcFpsBadge').textContent = Number(d.fps || 0).toFixed(1) + ' FPS';
        $('fcLastRefresh').textContent = 'Diperbarui ' + new Date().toLocaleTimeString('id-ID');
        if (d.camera_url) $('fcCameraCaption').textContent = 'ESP32-CAM OV2640 · ' + d.camera_url;
      })
      .catch(function (err) {
        setStatus('Tidak dapat menghubungi Python bridge', false);
        $('fcLastRefresh').textContent = err.message;
      });
  }
  function connect() {
    updateStream();
    poll();
    if (timer) clearInterval(timer);
    timer = setInterval(poll, 1200);
  }
  bridgeInput.value = bridgeUrl;
  $('fcConnect').addEventListener('click', connect);
  $('fcOpenBridge').addEventListener('click', function () {
    window.open(cleanBase(bridgeInput.value) + '/', '_blank', 'noopener');
  });
  $('fcResetCount').addEventListener('click', function () {
    if (!window.confirm('Reset total hitungan ikan pada sesi Python bridge?')) return;
    fetch(cleanBase(bridgeInput.value) + '/api/reset', { method: 'POST' })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function () { poll(); if (window.SKJAtoast) SKJAtoast('Hitungan sesi berhasil direset.', 'success'); })
      .catch(function (e) { if (window.SKJAtoast) SKJAtoast('Reset gagal: ' + e.message, 'error'); });
  });
  connect();

  /* Map placement */
  var mapEl = $('fcCameraMap');
  if (mapEl && window.L) {
    var lat = Number(cfg.lat || -5.7703), lon = Number(cfg.lon || 105.2642);
    map = L.map(mapEl, { scrollWheelZoom: true }).setView([lat, lon], 15);
    if (window.SKJAtileLayer) SKJAtileLayer(map);
    else L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19, attribution: '&copy; OpenStreetMap'
    }).addTo(map);
    marker = L.marker([lat, lon], { draggable: true, title: 'Posisi ESP32-CAM' }).addTo(map);
    marker.bindPopup('<strong>ESP32-CAM</strong><br>Geser marker untuk mengubah posisi.');
    selected = { lat: lat, lon: lon };
    function setCoords(ll) {
      selected = { lat: ll.lat, lon: ll.lng };
      $('fcLatitude').value = ll.lat.toFixed(7);
      $('fcLongitude').value = ll.lng.toFixed(7);
    }
    map.on('click', function (e) { marker.setLatLng(e.latlng); setCoords(e.latlng); });
    marker.on('dragend', function (e) { setCoords(e.target.getLatLng()); });
    function syncInputs() {
      var a = Number($('fcLatitude').value), b = Number($('fcLongitude').value);
      if (!Number.isFinite(a) || !Number.isFinite(b) || a < -90 || a > 90 || b < -180 || b > 180) return;
      marker.setLatLng([a, b]); map.panTo([a, b]); selected = { lat: a, lon: b };
    }
    $('fcLatitude').addEventListener('change', syncInputs);
    $('fcLongitude').addEventListener('change', syncInputs);
    setTimeout(function () { map.invalidateSize(); }, 250);

    $('fcSaveLocation').addEventListener('click', function () {
      if (!selected) return;
      var payload = {
        latitude: selected.lat,
        longitude: selected.lon,
        location_name: $('fcLocationName').value.trim()
      };
      $('fcSaveLocation').disabled = true;
      fetch('api/camera-location.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': window.BOLU_FISHCOUNT_CSRF || '',
          'Accept': 'application/json'
        },
        body: JSON.stringify(payload)
      }).then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.ok) throw new Error(j.error || 'Gagal menyimpan'); return j; }); })
        .then(function (j) {
          $('fcLocationFeedback').textContent = j.message + ' (' + payload.latitude.toFixed(6) + ', ' + payload.longitude.toFixed(6) + ')';
          if (window.SKJAtoast) SKJAtoast(j.message, 'success');
        })
        .catch(function (e) {
          $('fcLocationFeedback').textContent = e.message;
          if (window.SKJAtoast) SKJAtoast('Posisi kamera gagal disimpan: ' + e.message, 'error');
        })
        .finally(function () { $('fcSaveLocation').disabled = false; });
    });
  }
})();
