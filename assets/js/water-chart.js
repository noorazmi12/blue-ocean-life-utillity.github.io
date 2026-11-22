/* =====================================================================
   BOLU — water-chart.js (halaman kualitas-air.php)
   Grafik historis parameter kualitas air dengan pemilih parameter & rentang.
   ===================================================================== */
(function () {
    'use strict';

    var qs = new URLSearchParams(window.location.search);
    var param = qs.get('param') || 'temp_sensor_c';
    var range = parseInt(qs.get('range') || '5', 10);
    var refreshSec = Math.max(5, parseInt(document.body.dataset.refresh || '15', 10));

    var META = {
        temp_sensor_c: { label: 'Suhu Air',    unit: '°C',   color: '#E5484D' },
        ph:            { label: 'pH',          unit: '',     color: '#3090A8' },
        volt_ph_v:     { label: 'Tegangan Modul pH', unit: 'V', color: '#8E44AD' }
    };

    var chart = null;
    var loading = false;

    function initChart(points, meta) {
        var ctx = document.getElementById('chartWater');
        if (!ctx) return;
        chart = new Chart(ctx, {
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
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 10, maxRotation: 0, autoSkip: true } },
                    y: { grid: { drawTicks: false }, border: { display: false } }
                }
            })
        });
    }

    function updateChart(points, meta) {
        if (!chart) { initChart(points, meta); return; }
        chart.data.labels = points.map(function (p) { return p.t; });
        chart.data.datasets[0].label = meta.label + (meta.unit ? ' (' + meta.unit + ')' : '');
        chart.data.datasets[0].data = points.map(function (p) { return p.v; });
        chart.data.datasets[0].borderColor = meta.color;
        chart.update('none');
    }

    function loadData(silent) {
        if (loading) return;
        loading = true;
        var meta = META[param] || META.temp_sensor_c;
        SKJAfetch('api/water-data.php?view=range&param=' + encodeURIComponent(param) + '&minutes=' + range)
            .then(function (j) {
                if (!j.ok) {
                    if (!silent) SKJAtoast('Gagal memuat data: ' + (j.error || ''), 'error');
                    return;
                }
                updateChart(j.data.points, meta);
                var pill = document.getElementById('liveStatus');
                if (pill) pill.innerHTML = SKJAstatusPill(j.data.status);
                SKJAmarkUpdated();
            })
            .catch(function () {})
            .finally(function () { loading = false; });
    }

    function refreshCards() {
        SKJAfetch('api/water-data.php?view=latest').then(function (j) {
            if (!j.ok || !j.data || !j.data.latest) return;
            var newest = j.data.latest;
            document.querySelectorAll('[data-water-value]').forEach(function (el) {
                var key = el.getAttribute('data-water-value');
                var value = newest[key];
                if (value === null || value === undefined) {
                    el.textContent = '—';
                } else {
                    var digits = key === 'volt_ph_v' ? 3 : (key === 'ph' ? 2 : 1);
                    el.textContent = Number(value).toLocaleString('id-ID', {
                        minimumFractionDigits: digits, maximumFractionDigits: digits
                    });
                }
            });
        }).catch(function () {});
    }

    /* Render awal dari data boot sisi-server (window.SKJA_BOOT): grafik langsung
     * tampil tanpa menunggu fetch API. Bila fetch gagal, grafik tetap terlihat. */
    (function () {
        var boot = window.SKJA_BOOT || null;
        if (boot && boot.param === param && boot.points && boot.points.length) {
            try { initChart(boot.points, META[param] || META.temp_sensor_c); } catch (e) { /* biarkan API mencoba */ }
        }
    })();

    loadData(false);
    // Mode 5 menit: cek setiap detik. Rentang historis memakai interval situs.
    var lastRefresh = Date.now();
    var lastCards = 0;
    setInterval(function () {
        var interval = range === 5 ? 1000 : refreshSec * 1000;
        if (Date.now() - lastRefresh >= interval) {
            lastRefresh = Date.now();
            loadData(true);
        }
        if (Date.now() - lastCards >= interval) {
            lastCards = Date.now();
            refreshCards();
        }
    }, 1000);

    var paramSel = document.getElementById('paramSel');
    var rangeSel = document.getElementById('rangeSel');

    function reloadUrl() {
        var url = 'kualitas-air.php?param=' + encodeURIComponent(param) + '&range=' + range;
        window.history.replaceState({}, '', url);
    }

    if (paramSel) {
        paramSel.value = param;
        paramSel.addEventListener('change', function () {
            param = paramSel.value;
            reloadUrl();
            loadData(false);
        });
    }
    if (rangeSel) {
        rangeSel.value = String(range);
        rangeSel.addEventListener('change', function () {
            range = parseInt(rangeSel.value, 10);
            reloadUrl();
            loadData(false);
        });
    }
})();
