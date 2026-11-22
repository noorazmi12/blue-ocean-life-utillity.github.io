/* =====================================================================
   BOLU — jenis-ikan.js
   Modal tambah/edit jenis ikan katalog KJA.
   ===================================================================== */
(function () {
    'use strict';

    var CAT_OPTS = [['laut', 'Laut'], ['tawar', 'Tawar (danau/waduk/sungai)'], ['brackish', 'Rawa Payau / Muara']];

    function numField(name, label, val, ph, step, min, max) {
        return '<div class="form-group"><label class="form-label">' + label + '</label>'
            + '<input class="form-control" type="number" name="' + name + '" value="' + (val === null || val === undefined ? '' : SKJAesc(String(val))) + '"'
            + ' step="' + step + '"' + (min !== null ? ' min="' + min + '"' : '') + (max !== null ? ' max="' + max + '"' : '')
            + ' placeholder="' + ph + '"></div>';
    }

    function speciesForm(action, s) {
        s = s || {};
        var isEdit = action === 'edit';
        var makeThChecked = isEdit ? (parseInt(s.threshold_count, 10) > 0) : true;

        return '<form method="post" action="jenis-ikan.php" id="speciesForm">'
            + '<input type="hidden" name="csrf" value="' + document.body.dataset.csrf + '">'
            + '<input type="hidden" name="action" value="' + action + '">'
            + (isEdit ? '<input type="hidden" name="id" value="' + SKJAesc(s.id) + '">' : '')

            + '<div class="form-grid">'
            + '<div class="form-group"><label class="form-label">Nama Jenis Ikan <span class="req">*</span></label>'
            + '<input class="form-control" name="name" required maxlength="100" value="' + SKJAesc(s.name || '') + '" placeholder="mis. Kakap Merah"></div>'
            + '<div class="form-group"><label class="form-label">Nama Latin</label>'
            + '<input class="form-control" name="latin_name" maxlength="120" value="' + SKJAesc(s.latin_name || '') + '" placeholder="mis. Lates calcarifer" style="font-style:italic"></div>'

            + '<div class="form-group"><label class="form-label">Kategori Perairan <span class="req">*</span></label>'
            + '<select class="form-control" name="category">'
            + CAT_OPTS.map(function (o) {
                var sel = (s.category || 'laut') === o[0] ? ' selected' : '';
                return '<option value="' + o[0] + '"' + sel + '>' + o[1] + '</option>';
              }).join('')
            + '</select></div>'
            + '<div class="form-group"><label class="form-label">Bobot Panen Target (kg/ekor)</label>'
            + '<input class="form-control" type="number" name="target_weight_kg" step="0.005" min="0.005" max="500" value="' + SKJAesc(s.target_weight_kg || '') + '" placeholder="mis. 0.600"></div>'

            + '<div class="form-group"><label class="form-label">Siklus Panen Minimum (bulan)</label>'
            + '<input class="form-control" type="number" name="cycle_months_min" min="1" max="60" value="' + SKJAesc(s.cycle_months_min || '') + '" placeholder="mis. 5"></div>'
            + '<div class="form-group"><label class="form-label">Siklus Panen Maksimum (bulan)</label>'
            + '<input class="form-control" type="number" name="cycle_months_max" min="1" max="60" value="' + SKJAesc(s.cycle_months_max || '') + '" placeholder="mis. 8"></div>'
            + '</div>'

            + '<p class="form-label" style="margin-top:14px;margin-bottom:6px"><i class="fa-solid fa-droplet"></i> Rentang Kualitas Air Ideal (opsional)</p>'
            + '<div class="form-grid">'
            + numField('optimal_temp_min', 'Suhu Minimum (°C)', s.optimal_temp_min, 'mis. 27', '0.1', -5, 45)
            + numField('optimal_temp_max', 'Suhu Maksimum (°C)', s.optimal_temp_max, 'mis. 32', '0.1', -5, 45)
            + numField('optimal_salinity_min', 'Salinitas Minimum (ppt)', s.optimal_salinity_min, 'mis. 5', '0.01', 0, 60)
            + numField('optimal_salinity_max', 'Salinitas Maksimum (ppt)', s.optimal_salinity_max, 'mis. 35', '0.01', 0, 60)
            + numField('optimal_ph_min', 'pH Minimum', s.optimal_ph_min, 'mis. 7.5', '0.1', 0, 14)
            + numField('optimal_ph_max', 'pH Maksimum', s.optimal_ph_max, 'mis. 8.5', '0.1', 0, 14)
            + '</div>'

            + '<p class="form-label" style="margin-top:14px;margin-bottom:6px"><i class="fa-solid fa-weight-scale"></i> Koefisien Panjang–Berat: W(g) = a · L(cm)<sup>b</sup></p>'
            + '<div class="form-grid">'
            + numField('lw_a', 'Koefisien a', s.lw_a || '0.015', 'mis. 0.015', '0.00001', 0.0001, 1)
            + numField('lw_b', 'Eksponen b', s.lw_b || '3.0', 'mis. 3.0', '0.001', 2, 4)
            + '</div>'
            + '<p class="form-hint"><i class="fa-solid fa-circle-info"></i> Dipakai FishCount mengubah panjang rata-rata hasil kamera menjadi estimasi biomassa. Kosongkan untuk memakai nilai default.</p>'

            + '<div class="form-group"><label class="form-label">Deskripsi / Catatan</label>'
            + '<input class="form-control" name="description" maxlength="500" value="' + SKJAesc(s.description || '') + '" placeholder="mis. Favorit KJA laut tropis, tumbuh cepat"></div>'

            + '<label style="display:flex;align-items:flex-start;gap:9px;margin-top:10px;cursor:pointer">'
            + '<input type="checkbox" name="make_threshold" value="1" style="margin-top:3px"' + (makeThChecked ? ' checked' : '') + '>'
            + '<span style="font-size:12.8px">Simpan rentang di atas sebagai <strong>ambang kualitas air per spesies</strong> (dipakai evaluasi Kualitas Air &amp; Peringatan otomatis)</span></label>'

            + '<div style="display:flex;justify-content:flex-end;gap:10px;margin-top:14px">'
            + '<button type="button" class="btn btn-outline" id="spCancel">Batal</button>'
            + '<button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Simpan</button></div>'
            + '</form>';
    }

    /* ---------- Tambah ---------- */
    var btnAdd = document.getElementById('btnAddSpecies');
    if (btnAdd) {
        btnAdd.addEventListener('click', function () {
            SKJAmodal('Tambah Jenis Ikan Baru', speciesForm('add', null));
            document.getElementById('spCancel').addEventListener('click', SKJAmodalClose);
            var f = document.getElementById('speciesForm');
            f.addEventListener('submit', function () {
                var b = f.querySelector('button[type=submit]');
                b.disabled = true;
                b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyimpan...';
            });
        });
    }

    /* ---------- Edit ---------- */
    document.querySelectorAll('.btn-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var s;
            try { s = JSON.parse(btn.dataset.species); } catch (e) { s = null; }
            if (!s) { SKJAtoast('Data jenis ikan tidak dapat dibaca.', 'error'); return; }
            SKJAmodal('Edit Jenis Ikan — ' + SKJAesc(s.name || ''), speciesForm('edit', s));
            document.getElementById('spCancel').addEventListener('click', SKJAmodalClose);
            var f = document.getElementById('speciesForm');
            f.addEventListener('submit', function () {
                var b = f.querySelector('button[type=submit]');
                b.disabled = true;
                b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyimpan...';
            });
        });
    });
})();
