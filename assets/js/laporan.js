/* =====================================================================
   BOLU — laporan.js
   Grafik laporan dari data ringkasan server + interaksi filter periode.
   ===================================================================== */
(function () {
    'use strict';

    var dataEl = document.getElementById('reportData');
    if (!dataEl) return;
    var rows = JSON.parse(dataEl.textContent);

    var page = document.body.dataset.page;
    var chartEl = document.getElementById('chartReport');
    if (!chartEl) return;

    var pageFile = (window.location.pathname.split('/').pop() || 'laporan.php');
    // Deteksi jenis laporan dari select
    var typeSel = document.querySelector('select[name=type]');
    var type = typeSel ? typeSel.value : 'wave';

    var meta = {
        wave:   { label: 'Rata-rata Tinggi Gelombang (m)', color: '#3090A8', fill: 'rgba(48,144,168,.10)' },
        energy: { label: 'Produksi Energi Harian (kWh)',   color: '#22A06B', fill: 'rgba(34,160,107,.10)' },
        water:  { label: 'Rata-rata Suhu Air Harian (°C)', color: '#0296C6', fill: 'rgba(2,150,198,.16)' },
        fish:   { label: 'Maksimum Ikan Terdeteksi',       color: '#8E44AD', fill: 'rgba(142,68,173,.10)' }
    }[type] || metaFallback();

    function metaFallback() {
        return { label: 'Nilai Harian', color: '#3090A8', fill: 'rgba(48,144,168,.10)' };
    }

    new Chart(chartEl, {
        type: type === 'energy' ? 'bar' : 'line',
        data: {
            labels: rows.map(function (r) { return r.t; }),
            datasets: [{
                label: meta.label,
                data: rows.map(function (r) { return r.v; }),
                borderColor: meta.color,
                backgroundColor: type === 'energy' ? 'rgba(34,160,107,.75)' : meta.fill,
                fill: type !== 'energy',
                tension: .35, borderWidth: 2, pointRadius: 2, pointHoverRadius: 5,
                borderRadius: type === 'energy' ? 6 : undefined,
                maxBarThickness: 40
            }]
        },
        options: SKJAbaseChartOpts({
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: type === 'energy' || type === 'fish', grid: { drawTicks: false }, border: { display: false } }
            }
        })
    });

    // Tampilkan input rentang khusus hanya bila periode = custom
    var periodSel = document.getElementById('periodSel');
    var customRange = document.getElementById('customRange');
    if (periodSel && customRange) {
        periodSel.addEventListener('change', function () {
            customRange.hidden = periodSel.value !== 'custom';
        });
    }
})();
