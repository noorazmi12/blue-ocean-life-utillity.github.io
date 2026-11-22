/* =====================================================================
   BOLU — wave-chart.js (halaman gelombang.php)
   Grafik tinggi gelombang real-time + pembaruan kartu statistik.
   Rentang mengikuti parameter URL ?range= (15/60/360/1440) atau periode khusus.
   ===================================================================== */
(function () {
    'use strict';

    var qs = new URLSearchParams(window.location.search);
    var rangeMin = parseInt(qs.get('range') || '60', 10);
    var customFrom = qs.get('from') || '';
    var customTo = qs.get('to') || '';
    var isCustom = (qs.get('range') === '0') || (customFrom !== '' && customTo !== '');
    var refreshSec = Math.max(5, parseInt(document.body.dataset.refresh || '15', 10));

    var BLUE = '#3090A8', BLUE_FILL = 'rgba(48,144,168,.10)';
    var chart = null;

    function initChart(points) {
        var ctx = document.getElementById('chartWave');
        if (!ctx) return;
        chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: points.map(function (p) { return p.t; }),
                datasets: [
                    {
                        label: 'Tinggi Gelombang (m)',
                        data: points.map(function (p) { return p.h; }),
                        borderColor: BLUE, backgroundColor: BLUE_FILL,
                        fill: true, tension: .35, borderWidth: 2, pointRadius: 0, pointHoverRadius: 4
                    },
                    {
                        label: 'Ambang Operasional (1,5 m)',
                        data: points.map(function () { return 1.5; }),
                        borderColor: '#E5484D', borderDash: [7, 6], borderWidth: 1.4,
                        pointRadius: 0, fill: false
                    }
                ]
            },
            options: SKJAbaseChartOpts({
                scales: {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 10, maxRotation: 0, autoSkip: true } },
                    y: { beginAtZero: true, title: { display: true, text: 'Meter' }, grid: { drawTicks: false }, border: { display: false } }
                }
            })
        });
    }

    function updateChart(points) {
        if (!chart) { initChart(points); return; }
        chart.data.labels = points.map(function (p) { return p.t; });
        chart.data.datasets[0].data = points.map(function (p) { return p.h; });
        chart.update('none');
    }

    function apiUrl() {
        if (isCustom) {
            return 'api/wave-data.php?view=range&from=' + encodeURIComponent(customFrom) + '&to=' + encodeURIComponent(customTo);
        }
        return 'api/wave-data.php?view=range&minutes=' + rangeMin;
    }

    function loadData() {
        SKJAfetch(apiUrl()).then(function (j) {
            if (!j.ok) {
                SKJAtoast('Gagal memuat data gelombang: ' + (j.error || 'server tidak dapat diakses'), 'error');
                return;
            }
            updateChart(j.data.points);
            var st = j.data.status;
            var pill = document.getElementById('liveStatus');
            if (pill) pill.innerHTML = SKJAstatusPill(st);

            var l = j.data.latest;
            if (l) {
                var h = document.getElementById('curHeight');
                if (h) SKJAcountUp(h, parseFloat(l.h), 2);
                var per = document.getElementById('curPeriod');
                if (per) per.textContent = SKJAnum(l.t, 1);
                var wind = document.getElementById('curWind');
                if (wind && l.w !== null && l.w !== undefined) SKJAcountUp(wind, parseFloat(l.w), 1);
                var kw = document.getElementById('curKw');
                if (kw && l.kw !== undefined) SKJAcountUp(kw, parseFloat(l.kw), 2);
                var mm = document.getElementById('statMinMax');
                if (mm && j.data.stats) {
                    mm.textContent = SKJAnum(j.data.stats.min, 2) + ' / ' + SKJAnum(j.data.stats.max, 2) + ' / ' + SKJAnum(j.data.stats.avg, 2);
                }
                var ct = document.getElementById('curTime');
                if (ct) ct.textContent = SKJAtimeAgo(l.measured_at);
            }
            SKJAmarkUpdated();
        }).catch(function () {
            // server tidak terjangkau — biarkan indikator terakhir, coba lagi di polling berikutnya
        });
    }

    /* Render awal dari data boot sisi-server (window.SKJA_BOOT): grafik langsung
     * tampil tanpa menunggu fetch API — rentang boot = rentang URL yang sama.
     * Bila fetch gagal (sesi/jaringan/pratinjau ter-embed), grafik tetap terlihat. */
    (function () {
        var boot = window.SKJA_BOOT || null;
        if (boot && boot.points && boot.points.length) {
            try { initChart(boot.points); } catch (e) { /* biarkan API mencoba */ }
        }
    })();

    loadData();
    setInterval(loadData, refreshSec * 1000);

    // Tombol periode khusus
    var btnCustom = document.getElementById('btnCustom');
    var customForm = document.getElementById('customForm');
    if (btnCustom && customForm) {
        btnCustom.addEventListener('click', function () {
            customForm.hidden = !customForm.hidden;
        });
    }
})();
