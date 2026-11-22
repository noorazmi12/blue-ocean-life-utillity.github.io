/* =====================================================================
   BOLU — prediksi.js
   Grafik: sebaran korelasi (scatter), tren + proyeksi linear, anomali
   z-score — semuanya dihitung dari data server (tanpa data sintetis).
   ===================================================================== */
(function () {
    'use strict';

    var dataEl = document.getElementById('prediksiData');
    var D = dataEl ? JSON.parse(dataEl.textContent) : { scatter: [], trendActual: [], trendForecast: [] };

    var BLUE = '#3090A8', YELLOW = '#0296C6', GREEN = '#22A06B', RED = '#E5484D';

    /* -------- Scatter: gelombang vs daya -------- */
    var sc = document.getElementById('chartScatter');
    if (sc && D.scatter.length) {
        new Chart(sc, {
            type: 'scatter',
            data: {
                datasets: [{
                    label: 'Pengukuran (H vs P)',
                    data: D.scatter.map(function (s) { return { x: s.h, y: s.p }; }),
                    backgroundColor: 'rgba(48,144,168,.55)',
                    pointRadius: 4, pointHoverRadius: 6,
                    borderColor: BLUE
                }]
            },
            options: SKJAbaseChartOpts({
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return 'H=' + c.parsed.x + ' m, P=' + c.parsed.y + ' W'; } } } },
                scales: {
                    x: { title: { display: true, text: 'Tinggi Gelombang (m)' }, grid: { display: false } },
                    y: { title: { display: true, text: 'Daya OWC (W)' }, beginAtZero: true, grid: { drawTicks: false }, border: { display: false } }
                }
            })
        });
    }

    /* -------- Tren aktual + proyeksi linear -------- */
    var tr = document.getElementById('chartTrend');
    if (tr) {
        var labels = D.trendActual.map(function (r) { return SKJAtime(r.t); });
        var actual = D.trendActual.map(function (r) { return r.v; });
        var forecast = D.trendActual.map(function () { return null; });
        // sambungkan garis proyeksi dari titik terakhir aktual
        D.trendForecast.forEach(function (r) {
            labels.push(SKJAtime(r.t));
            forecast.push(r.v);
        });
        if (forecast.length && D.trendActual.length) {
            forecast[D.trendActual.length - 1] = D.trendActual[D.trendActual.length - 1].v;
        }
        new Chart(tr, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Aktual (m)',
                        data: actual,
                        borderColor: BLUE, backgroundColor: 'rgba(48,144,168,.10)',
                        fill: true, tension: .35, borderWidth: 2, pointRadius: 0
                    },
                    {
                        label: 'Proyeksi Linear (m)',
                        data: forecast,
                        borderColor: YELLOW, borderDash: [7, 6],
                        fill: false, tension: .2, borderWidth: 2, pointRadius: 0
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

    /* -------- Anomali: garis z-score 0 ± 2.5 (ringkas dari API) -------- */
    SKJAfetch('api/water-data.php?view=range&param=ph&minutes=1440').then(function (j) {
        var el = document.getElementById('chartAnomaly');
        if (!el || !j.ok) return;
        var pts = j.data.points;
        var vals = pts.map(function (p) { return p.v; }).filter(function (v) { return v !== null; });
        if (vals.length < 10) return;
        var mean = vals.reduce(function (a, b) { return a + b; }, 0) / vals.length;
        var varSum = vals.reduce(function (a, b) { return a + (b - mean) * (b - mean); }, 0);
        var sd = Math.sqrt(varSum / vals.length) || 1;
        var z = pts.map(function (p) { return p.v === null ? null : (p.v - mean) / sd; });
        new Chart(el, {
            type: 'line',
            data: {
                labels: pts.map(function (p) { return p.t; }),
                datasets: [
                    {
                        label: 'z-score pH',
                        data: z,
                        borderColor: BLUE, borderWidth: 1.8, pointRadius: 0, tension: .3, fill: false
                    },
                    {
                        label: 'Batas ±2,5',
                        data: pts.map(function () { return 2.5; }),
                        borderColor: RED, borderDash: [6, 5], borderWidth: 1.2, pointRadius: 0
                    },
                    {
                        label: 'Batas -2,5',
                        data: pts.map(function () { return -2.5; }),
                        borderColor: RED, borderDash: [6, 5], borderWidth: 1.2, pointRadius: 0
                    }
                ]
            },
            options: SKJAbaseChartOpts({
                plugins: { legend: { position: 'bottom', labels: { padding: 10, boxWidth: 7 } } },
                scales: {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 6, maxRotation: 0, autoSkip: true } },
                    y: { grid: { drawTicks: false }, border: { display: false } }
                }
            })
        });
    });
})();
