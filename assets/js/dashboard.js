/* =====================================================================
   BOLU — dashboard.js
   Polling data terbaru (gelombang, energi, kualitas air) + grafik Chart.js
   + peta mini Leaflet. Data diambil dari API PHP, bukan angka acak.
   ===================================================================== */
(function () {
    'use strict';

    var refreshSec = Math.max(5, parseInt(document.body.dataset.refresh || '15', 10));
    var timers = [];

    /* ---------- Helper warna ---------- */
    var C = {
        blue: '#3090A8', blueFill: 'rgba(48,144,168,.10)',
        green: '#22A06B', greenFill: 'rgba(34,160,107,.10)',
        yellow: '#0296C6', yellowFill: 'rgba(244,197,66,.16)',
        red: '#E5484D'
    };

    var PARAM_META = {
        ph:            { label: 'pH',        unit: '',     color: C.blue },
        temp_sensor_c: { label: 'Suhu Air',  unit: '°C',   color: C.red }
    };

    /* ============ OVERLAY "BELUM ADA DATA" (jujur, bukan angka rekaan) ============ */
    function chartEmpty(canvasId, isEmpty, msg) {
        var cv = document.getElementById(canvasId);
        var box = cv ? cv.closest('.chart-box') : null;
        if (!box) return;
        var el = box.querySelector('.chart-empty');
        if (isEmpty) {
            if (!el) {
                el = document.createElement('div');
                el.className = 'chart-empty';
                el.innerHTML = '<i class="fa-regular fa-chart-line"></i>'
                    + '<p>' + (msg || 'Belum ada data dalam 24 jam terakhir') + '</p>'
                    + '<span>Grafik akan terisi otomatis saat perangkat mengirim pengukuran.</span>';
                box.appendChild(el);
            }
        } else if (el) {
            el.remove();
        }
    }

    /* ============ GRAFIK GELOMBANG ============ */
    var chartWave = null;
    function initWaveChart(points) {
        var ctx = document.getElementById('chartWave');
        if (!ctx) return;
        chartWave = new Chart(ctx, {
            type: 'line',
            data: {
                labels: points.map(function (p) { return p.t; }),
                datasets: [{
                    label: 'Tinggi Gelombang (m)',
                    data: points.map(function (p) { return p.h; }),
                    borderColor: C.blue,
                    backgroundColor: C.blueFill,
                    fill: true,
                    tension: .35,
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 4
                }]
            },
            options: SKJAbaseChartOpts({
                scales: {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 8, maxRotation: 0, autoSkip: true } },
                    y: { beginAtZero: true, title: { display: true, text: 'Meter' }, grid: { drawTicks: false }, border: { display: false } }
                }
            })
        });
    }
    function updateWaveChart(points) {
        if (!chartWave) { initWaveChart(points); return; }
        chartWave.data.labels = points.map(function (p) { return p.t; });
        chartWave.data.datasets[0].data = points.map(function (p) { return p.h; });
        chartWave.update('none');
    }

    /* ============ GRAFIK DAYA ============ */
    var chartPower = null;
    function initPowerChart(points) {
        var ctx = document.getElementById('chartPower');
        if (!ctx) return;
        chartPower = new Chart(ctx, {
            type: 'line',
            data: {
                labels: points.map(function (p) { return p.t; }),
                datasets: [
                    {
                        label: 'Daya Terukur OWC (W)',
                        data: points.map(function (p) { return p.p; }),
                        borderColor: C.yellow,
                        backgroundColor: C.yellowFill,
                        fill: true, tension: .35, borderWidth: 2, pointRadius: 0, pointHoverRadius: 4
                    },
                    {
                        label: 'Estimasi Potensi Gelombang (W)',
                        data: points.map(function (p) { return p.est; }),
                        borderColor: C.blue,
                        borderDash: [6, 5],
                        fill: false, tension: .35, borderWidth: 1.6, pointRadius: 0, pointHoverRadius: 4
                    }
                ]
            },
            options: SKJAbaseChartOpts({
                scales: {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 8, maxRotation: 0, autoSkip: true } },
                    y: { beginAtZero: true, title: { display: true, text: 'Watt' }, grid: { drawTicks: false }, border: { display: false } }
                }
            })
        });
    }
    function updatePowerChart(points) {
        if (!chartPower) { initPowerChart(points); return; }
        chartPower.data.labels = points.map(function (p) { return p.t; });
        chartPower.data.datasets[0].data = points.map(function (p) { return p.p; });
        chartPower.data.datasets[1].data = points.map(function (p) { return p.est; });
        chartPower.update('none');
    }

    /* ============ GRAFIK KUALITAS AIR ============ */
    var chartWater = null;
    function initWaterChart(points, meta) {
        var ctx = document.getElementById('chartWater');
        if (!ctx) return;
        chartWater = new Chart(ctx, {
            type: 'line',
            data: {
                labels: points.map(function (p) { return p.t; }),
                datasets: [{
                    label: meta.label + (meta.unit ? ' (' + meta.unit + ')' : ''),
                    data: points.map(function (p) { return p.v; }),
                    borderColor: meta.color,
                    backgroundColor: 'transparent',
                    tension: .35, borderWidth: 2, pointRadius: 0, pointHoverRadius: 4
                }]
            },
            options: SKJAbaseChartOpts({
                scales: {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 8, maxRotation: 0, autoSkip: true } },
                    y: { grid: { drawTicks: false }, border: { display: false } }
                }
            })
        });
    }
    function updateWaterChart(points, meta) {
        if (!chartWater) { initWaterChart(points, meta); return; }
        chartWater.data.labels = points.map(function (p) { return p.t; });
        chartWater.data.datasets[0].label = meta.label + (meta.unit ? ' (' + meta.unit + ')' : '');
        chartWater.data.datasets[0].data = points.map(function (p) { return p.v; });
        chartWater.data.datasets[0].borderColor = meta.color;
        chartWater.update('none');
    }

    /* ============ PEMUATAN & POLLING DATA ============ */
    function loadWave() {
        return SKJAfetch('api/wave-data.php?view=range&minutes=1440').then(function (j) {
            if (!j.ok) return;
            chartEmpty('chartWave', !j.data.points.length);
            updateWaveChart(j.data.points);
            var pill = document.getElementById('waveStatusSlot');
            if (pill) pill.innerHTML = SKJAstatusPill(j.data.status);
            if (j.data.latest) {
                SKJAcountUp(document.getElementById('stWave'), parseFloat(j.data.latest.h), 2);
                SKJAcountUp(document.getElementById('stWavePower'), parseFloat(j.data.latest.kw), 2);
            }
            SKJAmarkUpdated();
        });
    }
    function loadEnergy() {
        return SKJAfetch('api/energy-data.php?view=range&minutes=1440').then(function (j) {
            if (!j.ok) return;
            chartEmpty('chartPower', !j.data.points.length, 'Belum ada data energi dalam 24 jam terakhir');
            updatePowerChart(j.data.points);
            if (j.data.latest) {
                SKJAcountUp(document.getElementById('stPower'), parseFloat(j.data.latest.p), 0);
                if (j.data.latest.batt !== null && j.data.latest.batt !== undefined) {
                    SKJAcountUp(document.getElementById('stBatt'), parseFloat(j.data.latest.batt), 0);
                }
            }
            SKJAmarkUpdated();
        });
    }
    function loadWater() {
        var sel = document.getElementById('waterParamSel');
        var param = sel ? sel.value : 'ph';
        var meta = PARAM_META[param] || PARAM_META.ph;
        return SKJAfetch('api/water-data.php?view=range&param=' + param + '&minutes=1440').then(function (j) {
            if (!j.ok) return;
            chartEmpty('chartWater', !j.data.points.length, 'Belum ada data ' + meta.label + ' dalam 24 jam terakhir');
            updateWaterChart(j.data.points, meta);
            var l = j.data.latest;
            if (l) {
                var dec = (param === 'ph') ? 2 : 1;
                var elMap = { temp_sensor_c: 'stTemp', ph: 'stPh' };
                var el = document.getElementById(elMap[param]);
                if (el && l.v !== null) SKJAcountUp(el, parseFloat(l.v), dec);
            }
            SKJAmarkUpdated();
        });
    }

    var sel = document.getElementById('waterParamSel');
    if (sel) sel.addEventListener('change', loadWater);

    function loadAll() {
        loadWave(); loadEnergy(); loadWater();
    }

    loadAll();
    timers.push(setInterval(loadAll, refreshSec * 1000));
    // (render awal dari window.SKJA_BOOT sudah dilakukan di atas sebelum polling)

    /* ============ RENDER AWAL DARI DATA BOOT (tanpa menunggu API) ============
     * Data dihitung sisi-server dan ditanam di HTML (window.SKJA_BOOT). Grafik
     * tampil seketi; polling API di bawah hanya menyegarkan nilai terbaru.
     * Bila fetch API gagal (sesi/jaringan/pratinjau ter-embed), grafik & peta
     * tetap terlihat. */
    (function bootRender() {
        var boot = window.SKJA_BOOT || null;
        if (!boot) return;
        try {
            if (boot.wave && boot.wave.points && boot.wave.points.length) {
                updateWaveChart(boot.wave.points);
                chartEmpty('chartWave', false);
            }
            // boot.power adalah ARRAY titik langsung (skja_boot_energy), bukan {points}
            var powerPts = Array.isArray(boot.power) ? boot.power
                         : (boot.power && boot.power.points) || [];
            if (powerPts.length) {
                updatePowerChart(powerPts);
                chartEmpty('chartPower', false);
            }
            if (boot.water) {
                var sel0 = document.getElementById('waterParamSel');
                var p0 = sel0 ? sel0.value : 'ph';
                if (boot.water[p0] && boot.water[p0].length) {
                    updateWaterChart(boot.water[p0], PARAM_META[p0] || PARAM_META.ph);
                    chartEmpty('chartWater', false);
                }
            }
        } catch (e) { /* jangan blokir polling API bila boot bermasalah */ }
    })();

    /* ============ PETA MINI (Leaflet) ============ */
    var mapEl = document.getElementById('miniMap');
    if (mapEl && window.L) {
        var map = L.map('miniMap', { zoomControl: true, attributionControl: true, scrollWheelZoom: false });
        SKJAtileLayer(map);
        window.addEventListener('load', function () { setTimeout(function () { map.invalidateSize(); }, 60); });

        var coordEl = document.getElementById('miniMapCoord');
        map.on('mousemove', function (ev) {
            if (coordEl) coordEl.textContent = ev.latlng.lat.toFixed(5) + ', ' + ev.latlng.lng.toFixed(5);
        });
        map.on('mouseout', function () { if (coordEl) coordEl.textContent = '—'; });

        var miniMarkers = L.layerGroup().addTo(map);
        var miniViewSet = false;
        var MINI_ICONS = {
            owc: ['fa-bolt', 'owc'],
            wave_sensor: ['fa-water', 'wave'],
            water_sensor: ['fa-droplet', 'water'],
            camera: ['fa-video', 'camera'],
            gateway: ['fa-tower-broadcast', 'wave']
        };

        function renderMiniDevices(devices) {
            miniMarkers.clearLayers();
            (devices || []).forEach(function (d) {
                if (d.lat === null || d.lng === null) return;
                var ic = MINI_ICONS[d.type] || MINI_ICONS.gateway;
                var html = '<div class="device-marker ' + ic[1] + (d.status === 'online' ? '' : ' offline') + '" style="width:34px;height:34px"><i class="fa-solid ' + ic[0] + '"></i></div>';
                var marker = L.marker([parseFloat(d.lat), parseFloat(d.lng)], {
                    icon: L.divIcon({ html: html, className: '', iconSize: [34, 34] })
                });
                var vals = d.latest ? SKJAformatLatest(d) : '<small>' + SKJAesc(d.location_name || '') + '</small>';
                marker.bindPopup('<b>' + SKJAesc(d.name) + '</b><br>'
                    + '<span class="pop-status">' + SKJAstatusPill(d.data_status) + '</span><br>'
                    + vals + '<br><small>' + SKJAesc(d.location_name || '') + '</small>');
                miniMarkers.addLayer(marker);
            });
        }

        function setMiniView(center, zoom) {
            if (miniViewSet || !center) return;
            map.setView([center.lat, center.lng], zoom || 15);
            miniViewSet = true;
        }

        /* 1) Render seketi dari boot (server-side) — peta tidak lagi butuh API */
        var bootGeo = (window.SKJA_BOOT && window.SKJA_BOOT.geo) || null;
        if (bootGeo) {
            setMiniView(bootGeo.center, bootGeo.zoom);
            renderMiniDevices(bootGeo.devices);
        }

        /* 2) Polling API: perbarui marker + popup dengan nilai terbaru */
        SKJAfetch('api/devices-geo.php').then(function (j) {
            if (!j.ok) return; // boot markers tetap tampil
            setMiniView(j.data.center, j.data.zoom);
            renderMiniDevices(j.data.devices);
        });
    }

    // Bersihkan timer saat berpindah halaman
    window.addEventListener('beforeunload', function () { timers.forEach(clearInterval); });
})();
