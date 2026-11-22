/* =====================================================================
   BOLU — energy-chart.js (halaman energi-owc.php)
   Grafik daya vs waktu, energi kumulatif, dan produksi harian.
   ===================================================================== */
(function () {
    'use strict';

    var refreshSec = Math.max(5, parseInt(document.body.dataset.refresh || '15', 10));
    var YELLOW = '#0296C6', Y_FILL = 'rgba(244,197,66,.16)', BLUE = '#3090A8', GREEN = '#22A06B';

    var chartPower = null, chartEnergy = null, chartDaily = null;

    function initPower(points) {
        var ctx = document.getElementById('chartPower');
        if (!ctx) return;
        chartPower = new Chart(ctx, {
            type: 'line',
            data: {
                labels: points.map(function (p) { return p.t; }),
                datasets: [
                    {
                        label: 'Daya Terukur (W)',
                        data: points.map(function (p) { return p.p; }),
                        borderColor: YELLOW, backgroundColor: Y_FILL,
                        fill: true, tension: .35, borderWidth: 2, pointRadius: 0, pointHoverRadius: 4
                    },
                    {
                        label: 'Estimasi Potensi (W)',
                        data: points.map(function (p) { return p.est; }),
                        borderColor: BLUE, borderDash: [6, 5], fill: false,
                        tension: .35, borderWidth: 1.6, pointRadius: 0
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

    function initEnergy(points) {
        var ctx = document.getElementById('chartEnergy');
        if (!ctx) return;
        chartEnergy = new Chart(ctx, {
            type: 'line',
            data: {
                labels: points.map(function (p) { return p.t; }),
                datasets: [{
                    label: 'Energi Kumulatif (Wh)',
                    data: points.map(function (p) { return p.e; }),
                    borderColor: GREEN,
                    backgroundColor: 'rgba(34,160,107,.10)',
                    fill: true, tension: .2, borderWidth: 2, pointRadius: 0, pointHoverRadius: 4
                }]
            },
            options: SKJAbaseChartOpts({
                scales: {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 8, maxRotation: 0, autoSkip: true } },
                    y: { beginAtZero: false, title: { display: true, text: 'Wh' }, grid: { drawTicks: false }, border: { display: false } }
                }
            })
        });
    }

    function initDaily(rows) {
        var ctx = document.getElementById('chartDaily');
        if (!ctx) return;
        var labels = rows.map(function (r) { return r.d; }).reverse();
        var vals = rows.map(function (r) { return parseFloat(r.energy_wh) / 1000; }).reverse();
        chartDaily = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Produksi Harian (kWh)',
                    data: vals,
                    backgroundColor: 'rgba(34,160,107,.75)',
                    borderRadius: 7,
                    maxBarThickness: 44
                }]
            },
            options: SKJAbaseChartOpts({
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, title: { display: true, text: 'kWh' }, grid: { drawTicks: false }, border: { display: false } }
                }
            })
        });
    }

    function loadData() {
        SKJAfetch('api/energy-data.php?view=range&minutes=1440').then(function (j) {
            if (!j.ok) return;
            var pts = j.data.points;
            if (!chartPower) initPower(pts); else {
                chartPower.data.labels = pts.map(function (p) { return p.t; });
                chartPower.data.datasets[0].data = pts.map(function (p) { return p.p; });
                chartPower.data.datasets[1].data = pts.map(function (p) { return p.est; });
                chartPower.update('none');
            }
            if (!chartEnergy) initEnergy(pts); else {
                chartEnergy.data.labels = pts.map(function (p) { return p.t; });
                chartEnergy.data.datasets[0].data = pts.map(function (p) { return p.e; });
                chartEnergy.update('none');
            }
            var pill = document.getElementById('liveStatus');
            if (pill) pill.innerHTML = SKJAstatusPill(j.data.status);
            if (j.data.latest) {
                var el = document.getElementById('curPower');
                if (el) SKJAcountUp(el, parseFloat(j.data.latest.p), 1);
            }
            SKJAmarkUpdated();
        }).catch(function () {});
    }

    /* Render awal dari data boot sisi-server (window.SKJA_BOOT): grafik langsung
     * tampil tanpa menunggu fetch API. Bila fetch gagal, grafik tetap terlihat. */
    (function () {
        var boot = window.SKJA_BOOT || null;
        if (!boot) return;
        try {
            if (boot.points && boot.points.length) { initPower(boot.points); initEnergy(boot.points); }
            if (boot.daily && boot.daily.rows && boot.daily.rows.length) initDaily(boot.daily.rows);
        } catch (e) { /* biarkan API mencoba */ }
    })();

    loadData();
    setInterval(loadData, refreshSec * 1000);

    // Riwayat harian cukup dimuat sekali per kunjungan halaman
    SKJAfetch('api/energy-data.php?view=daily&days=7').then(function (j) {
        if (j.ok && j.data.rows) initDaily(j.data.rows);
    });
})();
