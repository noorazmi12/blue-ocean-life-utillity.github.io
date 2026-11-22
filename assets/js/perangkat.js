/* =====================================================================
   BOLU — perangkat.js
   Modal tambah/edit perangkat + detail histori pengukuran per perangkat.
   ===================================================================== */
(function () {
    'use strict';

    var TYPE_OPTS = [
        ['owc', 'Unit OWC'],
        ['wave_sensor', 'Sensor Gelombang'],
        ['water_sensor', 'Sensor Kualitas Air'],
        ['camera', 'Kamera FishCount'],
        ['gateway', 'Gateway']
    ];
    var STATUS_OPTS = [['online', 'Online'], ['offline', 'Offline'], ['maintenance', 'Perawatan']];
    var CAL_OPTS = [['calibrated', 'Terkalibrasi'], ['due', 'Jatuh tempo'], ['overdue', 'Terlambat'], ['none', 'Tidak dicatat']];

    function options(list, sel) {
        return list.map(function (o) {
            return '<option value="' + o[0] + '"' + (o[0] === sel ? ' selected' : '') + '>' + o[1] + '</option>';
        }).join('');
    }

    function deviceForm(action, d) {
        d = d || {};
        var isEdit = action === 'edit';
        SKJAmodal(isEdit ? 'Edit Perangkat — ' + SKJAesc(d.code || '') : 'Tambah Perangkat Baru',
            '<form method="post" action="perangkat.php" id="devForm">'
            + '<input type="hidden" name="csrf" value="' + document.body.dataset.csrf + '">'
            + '<input type="hidden" name="action" value="' + action + '">'
            + (isEdit ? '<input type="hidden" name="id" value="' + SKJAesc(d.id) + '">' : '')
            + '<div class="form-grid">'
            + (isEdit
                ? '<div class="form-group"><label class="form-label">Kode</label><input class="form-control" value="' + SKJAesc(d.code) + '" disabled>'
                  + '<p class="form-hint">Kode tidak dapat diubah.</p></div>'
                : '<div class="form-group"><label class="form-label">Kode Perangkat <span class="req">*</span></label>'
                  + '<input class="form-control" name="device_code" required maxlength="50" pattern="[A-Za-z0-9_\\-]{2,50}" placeholder="mis. WAV-003" style="text-transform:uppercase">'
                  + '<p class="form-hint">Huruf besar/angka/garis, 2-50 karakter.</p></div>')
            + '<div class="form-group"><label class="form-label">Nama Perangkat <span class="req">*</span></label>'
            + '<input class="form-control" name="device_name" required maxlength="100" value="' + SKJAesc(d.name || '') + '" placeholder="mis. Sensor Gelombang Buoy 2"></div>'
            + '<div class="form-group"><label class="form-label">Jenis <span class="req">*</span></label>'
            + '<select class="form-select" name="device_type" required>' + options(TYPE_OPTS, d.type || '') + '</select></div>'
            + '<div class="form-group"><label class="form-label">Status</label>'
            + '<select class="form-select" name="status">' + options(STATUS_OPTS, d.status || 'offline') + '</select></div>'
            + '<div class="form-group"><label class="form-label">Status Kalibrasi</label>'
            + '<select class="form-select" name="calibration_status">' + options(CAL_OPTS, d.cal || 'none') + '</select></div>'
            + '<div class="form-group"><label class="form-label">Tanggal Kalibrasi</label>'
            + '<input class="form-control" type="date" name="calibration_date" value="' + SKJAesc(d.caldate || '') + '"></div>'
            + '<div class="form-group"><label class="form-label">Nama Lokasi</label>'
            + '<input class="form-control" name="location_name" maxlength="120" value="' + SKJAesc(d.loc || '') + '" placeholder="mis. Buoy Gelombang C"></div>'
            + '<div class="form-group"><label class="form-label">Latitude</label>'
            + '<input class="form-control" type="number" step="0.0000001" min="-90" max="90" name="latitude" value="' + SKJAesc(d.lat || '') + '" placeholder="-5.7700000"></div>'
            + '<div class="form-group"><label class="form-label">Longitude</label>'
            + '<input class="form-control" type="number" step="0.0000001" min="-180" max="180" name="longitude" value="' + SKJAesc(d.lng || '') + '" placeholder="105.2640000"></div>'
            + '<div class="form-group span-2"><label class="form-label">Catatan</label>'
            + '<input class="form-control" name="notes" maxlength="255" value="' + SKJAesc(d.notes || '') + '"></div>'
            + '</div>'
            + '<div style="display:flex;justify-content:flex-end;gap:10px;margin-top:10px">'
            + '<button type="button" class="btn btn-outline" id="devCancel">Batal</button>'
            + '<button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Simpan Perangkat</button>'
            + '</div></form>');
        document.getElementById('devCancel').addEventListener('click', SKJAmodalClose);
    }

    var btnAdd = document.getElementById('btnAddDevice');
    if (btnAdd) btnAdd.addEventListener('click', function () { deviceForm('add'); });

    document.querySelectorAll('.btn-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            deviceForm('edit', {
                id: btn.dataset.id, code: btn.dataset.code, name: btn.dataset.name, type: btn.dataset.type,
                status: btn.dataset.status, cal: btn.dataset.cal, caldate: btn.dataset.caldate,
                notes: btn.dataset.notes, lat: btn.dataset.lat, lng: btn.dataset.lng, loc: btn.dataset.loc
            });
        });
    });

    /* -------- Detail perangkat + histori pengukuran -------- */
    document.querySelectorAll('.btn-detail').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.dataset.id;
            var name = btn.dataset.name;
            SKJAmodal('Detail Perangkat — ' + SKJAesc(name), '<div class="skeleton" style="height:120px"></div>', { large: true });
            SKJAfetch('api/devices-geo.php?view=detail&id=' + encodeURIComponent(id)).then(function (j) {
                if (!j.ok) {
                    SKJAmodal('Detail Perangkat', '<div class="empty-state"><i class="fa-solid fa-triangle-exclamation"></i><p>' + SKJAesc(j.error || 'Gagal memuat data.') + '</p></div>');
                    return;
                }
                var d = j.data;
                var rows = (d.measurements || []).map(function (m) {
                    return '<tr><td class="table-strong">' + SKJAesc(m.time) + '</td>'
                        + Object.keys(m.vals).map(function (k) { return '<td class="num">' + SKJAesc(m.vals[k]) + '</td>'; }).join('')
                        + '</tr>';
                }).join('');
                var headers = (d.columns || []).map(function (c) { return '<th>' + SKJAesc(c) + '</th>'; }).join('');
                SKJAmodal('Detail Perangkat — ' + SKJAesc(d.name),
                    '<dl class="kv" style="margin-bottom:16px">'
                    + '<dt>Kode</dt><dd>' + SKJAesc(d.code) + '</dd>'
                    + '<dt>Jenis</dt><dd>' + SKJAesc(d.type) + '</dd>'
                    + '<dt>Status</dt><dd>' + SKJAstatusPill(d.data_status) + '</dd>'
                    + '<dt>Komunikasi terakhir</dt><dd>' + SKJAesc(d.last_comm || '—') + '</dd>'
                    + '<dt>Kalibrasi</dt><dd>' + SKJAesc(d.calibration || '—') + '</dd>'
                    + '</dl>'
                    + '<h3 class="card-title" style="margin-bottom:10px"><i class="fa-solid fa-clock-rotate-left"></i> 20 Pengukuran Terakhir</h3>'
                    + '<div class="table-wrap" style="max-height:300px;overflow-y:auto"><table class="table"><thead><tr><th>Waktu</th>' + headers + '</tr></thead>'
                    + '<tbody>' + (rows || '<tr><td colspan="9"><div class="empty-state"><p>Belum ada pengukuran.</p></div></td></tr>') + '</tbody></table></div>',
                    { large: true });
            });
        });
    });
})();
