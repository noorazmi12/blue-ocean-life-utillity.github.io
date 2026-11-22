/* =====================================================================
   BOLU — fishcount.js
   - Kamera getUserMedia (mulai/hentikan/snapshot periodik)
   - Unggah drag & drop
   - Modal validasi manual
   - Grafik tren jumlah ikan & distribusi ukuran (data dari API PHP)
   ===================================================================== */
(function () {
    'use strict';

    /* ---------------- Kamera ---------------- */
    var video = document.getElementById('camVideo');
    var canvas = document.getElementById('camCanvas');
    var camArea = document.getElementById('cameraArea');
    var camIdle = document.getElementById('camIdle');
    var btnStart = document.getElementById('btnCamStart');
    var btnStop = document.getElementById('btnCamStop');
    var btnSnap = document.getElementById('btnSnapshot');
    var stream = null;
    var monitorTimer = null;

    function setCamIdle(on) {
        if (camIdle) camIdle.hidden = !on;
        if (video) video.hidden = on;
    }

    btnStart.addEventListener('click', function () {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            SKJAtoast('Peramban/perangkat tidak mendukung akses kamera.', 'error');
            return;
        }
        var tryCam = function (c) {
            var o = c || {}; o.audio = false;
            return navigator.mediaDevices.getUserMedia(o);
        };
        // Utamakan kamera belakang (HP); bila tidak ada, pakai kamera laptop.
        tryCam({ video: { facingMode: 'environment' } })
            .catch(function () { return tryCam({ video: true }); })
            .then(function (s) {
                stream = s;
                video.srcObject = s;
                setCamIdle(false);
                btnStart.disabled = true;
                btnStop.disabled = false;
                btnSnap.disabled = false;
                var tag = camArea.querySelector('.cam-tag');
                if (tag) tag.innerHTML = '<span class="dot dot-live"></span> KAMERA LOKAL · AKTIF';
                SKJAtoast('Kamera aktif. Gunakan Snapshot untuk mengambil gambar.', 'success');
            })
            .catch(function (err) {
                var name = err && err.name ? err.name : 'tidak diketahui';
                var hint = (err && err.name === 'NotAllowedError')
                    ? ' Izin kamera diblokir. Bila berada di panel preview ter-embed, buka aplikasi di tab baru (tombol "Buka Tab Baru") atau gunakan unggahan gambar.'
                    : '';
                SKJAtoast('Akses kamera gagal (' + name + ').' + hint, 'error');
            });
    });

    btnStop.addEventListener('click', function () {
        stopMonitor();
        if (stream) {
            stream.getTracks().forEach(function (t) { t.stop(); });
            stream = null;
        }
        setCamIdle(true);
        btnStart.disabled = false;
        btnStop.disabled = true;
        btnSnap.disabled = true;
        var tag = camArea.querySelector('.cam-tag');
        if (tag) tag.innerHTML = '<span class="dot dot-offline"></span> CAM-001 · OFFLINE';
    });

    function captureFrame() {
        if (!stream || !video.videoWidth) return null;
        var w = Math.min(1280, video.videoWidth);
        var h = Math.round(w * video.videoHeight / video.videoWidth);
        canvas.width = w; canvas.height = h;
        canvas.getContext('2d').drawImage(video, 0, 0, w, h);
        return canvas.toDataURL('image/jpeg', 0.85);
    }

    function sendSnapshot(dataUrl) {
        return SKJAfetch('api/fishcount-data.php?action=snapshot', {
            method: 'POST',
            body: { image: dataUrl, notes: 'Snapshot kamera' }
        }).then(function (j) {
            if (j.ok) {
                var ai = j.ai;
                if (ai) {
                    SKJAtoast('AI (' + ai.model + '): ' + ai.fish_count + ' ekor · keyakinan '
                        + ai.confidence_pct + '%. Ini estimasi — validasi manual tersedia. Menyegarkan…', 'success');
                } else {
                    SKJAtoast('Snapshot tersimpan (status: Menunggu AI).', 'info');
                }
                setTimeout(function () { window.location.reload(); }, 1600);
            } else {
                SKJAtoast('Snapshot gagal: ' + (j.error || ''), 'error');
            }
        });
    }

    btnSnap.addEventListener('click', function () {
        var d = captureFrame();
        if (!d) { SKJAtoast('Kamera belum siap.', 'error'); return; }
        sendSnapshot(d);
    });

    /* Pemantauan periodik */
    var monitorState = false;
    function startMonitor() {
        if (monitorState) return;
        monitorState = true;
        SKJAtoast('Pemantauan periodik dimulai (snapshot tiap 30 detik).', 'info');
        monitorTimer = setInterval(function () {
            var d = captureFrame();
            if (d) sendSnapshot(d);
        }, 30000);
    }
    function stopMonitor() {
        if (!monitorState) return;
        monitorState = false;
        clearInterval(monitorTimer);
    }
    // Mulai/hentikan pemantauan: klik ganda tombol Mulai/Hentikan
    btnStart.addEventListener('dblclick', startMonitor);
    btnStop.addEventListener('click', stopMonitor);

    window.addEventListener('beforeunload', function () {
        stopMonitor();
        if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
    });

    /* ---------------- Unggah drag & drop + pratinjau media ---------------- */
    var dropZone = document.getElementById('dropZone');
    var fileInput = document.getElementById('mediaInput');
    var prevBox = document.getElementById('mediaPreview');
    if (dropZone && fileInput) {
        ['dragenter', 'dragover'].forEach(function (ev) {
            dropZone.addEventListener(ev, function (e) { e.preventDefault(); dropZone.classList.add('dragover'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            dropZone.addEventListener(ev, function (e) { e.preventDefault(); dropZone.classList.remove('dragover'); });
        });
        dropZone.addEventListener('drop', function (e) {
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                showMediaPreview(fileInput.files);
            }
        });
        fileInput.addEventListener('change', function () {
            showMediaPreview(fileInput.files);
        });
    }

    /* Tampilkan nama, ukuran, dan pratinjau gambar/video yang dipilih */
    function showMediaPreview(files) {
        if (!prevBox || !files || !files.length) return;
        var f = files[0];
        prevBox.hidden = false;
        prevBox.innerHTML = '';
        var isVid = (f.type || '').indexOf('video/') === 0;
        var info = document.createElement('div');
        info.className = 'media-file';
        info.innerHTML = '<i class="fa-solid ' + (isVid ? 'fa-film' : 'fa-image') + '"></i> '
            + SKJAesc(f.name || 'berkas') + ' <small>(' + (f.size / 1048576).toFixed(2) + ' MB)</small>';
        prevBox.appendChild(info);
        if (isVid) {
            var v = document.createElement('video');
            v.controls = true;
            v.preload = 'metadata';
            v.className = 'media-video';
            v.src = URL.createObjectURL(f);
            prevBox.appendChild(v);
        } else if ((f.type || '').indexOf('image/') === 0) {
            var im = document.createElement('img');
            im.className = 'media-img';
            im.alt = 'Pratinjau gambar';
            im.src = URL.createObjectURL(f);
            prevBox.appendChild(im);
        }
    }

    /* ---------------- Modal validasi manual ---------------- */
    document.querySelectorAll('.btn-validate').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.dataset.id;
            var time = btn.dataset.time;
            SKJAmodal('Validasi Manual — Hasil #' + SKJAesc(id),
                '<p class="form-hint" style="margin-bottom:12px">Hasil AI pada ' + SKJAesc(time) + '. Masukkan jumlah ikan hasil penghitungan manual Anda sebagai pembanding/koreksi.</p>'
                + '<form method="post" action="fishcount.php" id="validateForm">'
                + '<input type="hidden" name="csrf" value="' + document.body.dataset.csrf + '">'
                + '<input type="hidden" name="action" value="validate">'
                + '<input type="hidden" name="result_id" value="' + SKJAesc(id) + '">'
                + '<div class="form-grid">'
                + '<div class="form-group"><label class="form-label">Jumlah Manual <span class="req">*</span></label>'
                + '<input class="form-control" type="number" name="manual_count" min="0" max="100000" required placeholder="mis. 42"></div>'
                + '<div class="form-group"><label class="form-label">Catatan</label>'
                + '<input class="form-control" type="text" name="notes" maxlength="255" placeholder="opsional"></div>'
                + '</div>'
                + '<div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px">'
                + '<button type="button" class="btn btn-outline" id="valCancel">Batal</button>'
                + '<button type="submit" class="btn btn-success"><i class="fa-solid fa-check"></i> Simpan Validasi</button></div>'
                + '</form>');
            document.getElementById('valCancel').addEventListener('click', SKJAmodalClose);
            var f = document.getElementById('validateForm');
            f.addEventListener('submit', function () {
                var btnSub = f.querySelector('button[type=submit]');
                btnSub.disabled = true;
                btnSub.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyimpan...';
            });
        });
    });

    /* ---------------- Grafik ---------------- */
    SKJAfetch('api/fishcount-data.php?view=stats&days=7').then(function (j) {
        if (!j.ok) return;
        var s = j.data;

        var c1 = document.getElementById('chartFishCount');
        if (c1) {
            new Chart(c1, {
                type: 'line',
                data: {
                    labels: s.series.map(function (r) { return r.t; }),
                    datasets: [{
                        label: 'Ikan Terdeteksi (ekor)',
                        data: s.series.map(function (r) { return r.c; }),
                        borderColor: '#22A06B',
                        backgroundColor: 'rgba(34,160,107,.10)',
                        fill: true, tension: .35, borderWidth: 2, pointRadius: 2, pointHoverRadius: 5
                    }]
                },
                options: SKJAbaseChartOpts({
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, grid: { drawTicks: false }, border: { display: false } }
                    }
                })
            });
        }

        var c2 = document.getElementById('chartFishSize');
        if (c2) {
            new Chart(c2, {
                type: 'bar',
                data: {
                    labels: s.sizes.map(function (r) { return r.bucket; }),
                    datasets: [{
                        label: 'Frekuensi',
                        data: s.sizes.map(function (r) { return r.n; }),
                        backgroundColor: 'rgba(48,144,168,.75)',
                        borderRadius: 7, maxBarThickness: 40
                    }]
                },
                options: SKJAbaseChartOpts({
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, title: { display: true, text: 'Panjang (cm)' } },
                        y: { beginAtZero: true, grid: { drawTicks: false }, border: { display: false }, ticks: { precision: 0 } }
                    }
                })
            });
        }
    });
})();
