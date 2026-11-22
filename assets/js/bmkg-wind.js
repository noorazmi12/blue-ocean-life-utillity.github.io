/* BOLU — Angin BMKG pada halaman Peta Batimetri. Tidak mengubah layer kedalaman. */
(function () {
    'use strict';
    var panel = document.getElementById('bmkgWindPanel');
    if (!panel) return;
    var get = function (id) { return document.getElementById(id); };
    function show(id, value) { var el = get(id); if (el) el.textContent = value; }
    function load() {
        var adm4 = get('bmkgWindAdm4').value.trim();
        if (!/^\d{2}\.\d{2}\.\d{2}\.\d{4}$/.test(adm4)) {
            show('bmkgWindStatus', 'Kode ADM4 harus 00.00.00.0000'); return;
        }
        show('bmkgWindStatus', 'Memuat prakiraan BMKG...');
        get('bmkgWindRefresh').disabled = true;
        fetch('api/bmkg-wind.php?adm4=' + encodeURIComponent(adm4), {credentials:'same-origin'})
          .then(function (r) { return r.json(); })
          .then(function (d) {
              if (!d || !d.ok) throw new Error((d && d.error) || 'Respons tidak valid');
              show('bmkgWindSpeed', d.speed_kmh.toLocaleString('id-ID', {maximumFractionDigits:1}) + ' km/jam');
              show('bmkgWindKnots', d.speed_knots.toLocaleString('id-ID', {maximumFractionDigits:1}) + ' knot');
              show('bmkgWindDir', d.wind_from || '—');
              show('bmkgWindLocation', d.place || ('Wilayah ' + adm4));
              show('bmkgWindTime', 'Prakiraan: ' + d.forecast_local + ' WIB');
              show('bmkgWindStatus', d.stale ? 'Data prakiraan terakhir (waktu terlewati)' : 'Prakiraan BMKG tersedia');
          })
          .catch(function (err) {
              show('bmkgWindSpeed', '— km/jam'); show('bmkgWindKnots', '— knot');
              show('bmkgWindDir', '—'); show('bmkgWindTime','—');
              show('bmkgWindStatus', 'Gagal: ' + err.message);
          })
          .finally(function () { get('bmkgWindRefresh').disabled = false; });
    }
    get('bmkgWindRefresh').addEventListener('click', load);
    get('bmkgWindAdm4').addEventListener('keydown', function(e) { if (e.key === 'Enter') load(); });
    load();
})();
