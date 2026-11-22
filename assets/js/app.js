/* =====================================================================
   BOLU — app.js (JS inti bersama semua halaman)
   - Sidebar (collapse & mobile) dengan persistensi localStorage
   - Dropdown topbar (notifikasi, profil)
   - Polling notifikasi & status perangkat
   - Helper fetch JSON + CSRF, format waktu, indikator LIVE/DELAYED/OFFLINE
   - Toast, modal, count-up angka
   ===================================================================== */
(function () {
    'use strict';

    var body = document.body;
    var CSRF = body.dataset.csrf || '';
    var STALE_MIN = parseInt(body.dataset.stale || '10', 10);
    var REFRESH_SEC = Math.max(5, parseInt(body.dataset.refresh || '15', 10));
    var DEMO = body.dataset.demo === '1';

    /* ---------------- Sidebar ---------------- */
    var toggle = document.getElementById('sidebarToggle');
    var collapse = document.getElementById('sidebarCollapse');
    var backdrop = document.getElementById('sidebarBackdrop');

    function setCollapsed(on) {
        body.classList.toggle('sidebar-collapsed', on);
        try { localStorage.setItem('skja_sidebar', on ? '1' : '0'); } catch (e) {}
    }
    function setMobileOpen(on) {
        body.classList.toggle('sidebar-open', on);
    }
    var isMobile = function () { return window.innerWidth <= 992; };

    if (toggle) {
        toggle.addEventListener('click', function () {
            if (isMobile()) setMobileOpen(!body.classList.contains('sidebar-open'));
            else setCollapsed(!body.classList.contains('sidebar-collapsed'));
        });
    }
    if (collapse) {
        collapse.addEventListener('click', function () { setCollapsed(!body.classList.contains('sidebar-collapsed')); });
    }
    if (backdrop) {
        backdrop.addEventListener('click', function () { setMobileOpen(false); });
    }
    // Pulihkan preferensi collapse
    try {
        if (localStorage.getItem('skja_sidebar') === '1' && !isMobile()) setCollapsed(true);
    } catch (e) {}
    window.addEventListener('resize', function () {
        if (!isMobile()) setMobileOpen(false);
    });

    /* ---------------- Dropdown ---------------- */
    var dropdowns = [];
    document.querySelectorAll('.dropdown').forEach(function (dd) { dropdowns.push(dd); });

    function closeAllDropdowns() {
        dropdowns.forEach(function (dd) {
            dd.classList.remove('open');
            var menu = dd.querySelector('.dropdown-menu');
            if (menu) menu.hidden = true;
        });
    }
    dropdowns.forEach(function (dd) {
        var btn = dd.querySelector('button');
        var menu = dd.querySelector('.dropdown-menu');
        if (!btn || !menu) return;
        btn.addEventListener('click', function (ev) {
            ev.stopPropagation();
            var willOpen = menu.hidden;
            closeAllDropdowns();
            if (willOpen) { menu.hidden = false; dd.classList.add('open'); }
        });
    });
    document.addEventListener('click', function (ev) {
        if (!ev.target.closest('.dropdown')) closeAllDropdowns();
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') { closeAllDropdowns(); closeModal(); }
    });

    /* ---------------- Notifikasi (lonceng) ---------------- */
    var notifList = document.getElementById('notifList');
    var notifBtn = document.getElementById('notifBtn');

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }
    window.SKJAesc = esc;

    function timeAgo(dt) {
        if (!dt) return '—';
        var t = new Date(dt.replace(' ', 'T'));
        if (isNaN(t.getTime())) return dt;
        var diff = Math.max(0, (Date.now() - t.getTime()) / 1000);
        if (diff < 60) return Math.floor(diff) + ' detik lalu';
        if (diff < 3600) return Math.floor(diff / 60) + ' menit lalu';
        if (diff < 86400) return Math.floor(diff / 3600) + ' jam lalu';
        return Math.floor(diff / 86400) + ' hari lalu';
    }
    window.SKJAtimeAgo = timeAgo;

    function loadNotifications() {
        fetch('api/alerts-data.php?view=recent&limit=8', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j || !j.ok) return;
                var items = j.data || [];
                var openCount = j.open_count || 0;
                if (notifList) {
                    if (!items.length) {
                        notifList.innerHTML = '<div class="notif-empty"><i class="fa-solid fa-inbox"></i> Tidak ada peringatan terbuka</div>';
                    } else {
                        notifList.innerHTML = items.map(function (a) {
                            return '<a class="notif-item" href="peringatan.php?id=' + a.id + '">'
                                + '<span class="n-icon ' + esc(a.severity) + '"><i class="fa-solid '
                                + (a.severity === 'critical' ? 'fa-circle-exclamation' : a.severity === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-info')
                                + '"></i></span>'
                                + '<span class="n-body"><span class="n-title">' + esc(a.title) + '</span>'
                                + '<span class="n-time">' + esc(a.device_name || '') + ' · ' + timeAgo(a.occurred_at) + '</span></span></a>';
                        }).join('');
                    }
                }
                if (notifBtn) {
                    notifBtn.dataset.count = openCount;
                    notifBtn.classList.toggle('has-badge', openCount > 0);
                    var c = notifBtn.querySelector('.notif-count');
                    if (c) {
                        c.textContent = openCount > 99 ? '99+' : openCount;
                        c.style.display = openCount > 0 ? '' : 'none';
                    }
                }
            })
            .catch(function () { /* diam: jangan ganggu pengguna */ });
    }
    loadNotifications();
    setInterval(loadNotifications, 60000);
    window.SKJArefreshNotifications = loadNotifications;

    /* ---------------- Fetch helper (JSON + CSRF) ---------------- */
    window.SKJAfetch = function (url, options) {
        options = options || {};
        options.headers = Object.assign({
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': CSRF
        }, options.headers || {});
        if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(options.body);
        }
        return fetch(url, options).then(function (r) {
            return r.json().catch(function () { return { ok: false, error: 'Respons server tidak valid' }; });
        });
    };

    /* ---------------- Indikator status data ---------------- */
    window.SKJAdataStatus = function (measuredAt) {
        if (!measuredAt) return 'offline';
        var t = new Date(String(measuredAt).replace(' ', 'T'));
        if (isNaN(t.getTime())) return 'offline';
        var ageMin = (Date.now() - t.getTime()) / 60000;
        if (ageMin <= STALE_MIN) return 'live';
        if (ageMin <= STALE_MIN * 3) return 'delayed';
        return 'offline';
    };
    window.SKJAstatusPill = function (status) {
        var label = { live: 'LIVE', delayed: 'DELAYED', offline: 'OFFLINE' }[status] || 'OFFLINE';
        return '<span class="status-pill st-' + status + '"><span class="dot"></span>' + label + '</span>';
    };

    /* ---------------- Format angka & tanggal (id-ID) ---------------- */
    var BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    window.SKJAnum = function (v, dec) {
        if (v === null || v === undefined || v === '' || isNaN(v)) return '—';
        return Number(v).toLocaleString('id-ID', { minimumFractionDigits: dec || 0, maximumFractionDigits: dec == null ? 2 : dec });
    };
    window.SKJAdt = function (dt) {
        if (!dt) return '—';
        var t = new Date(String(dt).replace(' ', 'T'));
        if (isNaN(t.getTime())) return '—';
        return t.getDate() + ' ' + BULAN[t.getMonth()] + ' ' + t.getFullYear() + ' '
            + String(t.getHours()).padStart(2, '0') + ':' + String(t.getMinutes()).padStart(2, '0');
    };
    window.SKJAtime = function (dt) {
        if (!dt) return '—';
        var t = new Date(String(dt).replace(' ', 'T'));
        if (isNaN(t.getTime())) return '—';
        return String(t.getHours()).padStart(2, '0') + ':' + String(t.getMinutes()).padStart(2, '0');
    };

    /* ---------------- Nilai terakhir perangkat untuk popup peta ---------------- */
    window.SKJAformatLatest = function (d) {
        if (!d.latest) return '<small>Belum ada data</small>';
        var skip = { measured_at: 1, is_simulated: 1 };
        var parts = [];
        Object.keys(d.latest).forEach(function (k) {
            if (skip[k]) return;
            var v = d.latest[k];
            if (v === null || v === undefined) return;
            parts.push('<small>' + SKJAesc(k) + ': <b>' + SKJAesc(v) + '</b></small>');
        });
        if (d.latest.measured_at) parts.push('<small><i class="fa-regular fa-clock"></i> ' + SKJAtime(d.latest.measured_at) + '</small>');
        return parts.join('<br>') || '<small>Belum ada data</small>';
    };

    /* ---------------- Stempel pembaruan terakhir (footer) ---------------- */
    var lastUpdateText = document.getElementById('lastUpdateText');
    window.SKJAmarkUpdated = function () {
        if (lastUpdateText) {
            var n = new Date();
            lastUpdateText.textContent = String(n.getHours()).padStart(2, '0') + ':' + String(n.getMinutes()).padStart(2, '0') + ':' + String(n.getSeconds()).padStart(2, '0');
        }
    };

    /* ---------------- Toast ---------------- */
    window.SKJAtoast = function (msg, type) {
        type = type || 'info';
        var wrap = document.getElementById('toastWrap');
        if (!wrap) return;
        var el = document.createElement('div');
        el.className = 'toast toast-' + type;
        var icon = type === 'success' ? 'fa-circle-check' : type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-info';
        el.innerHTML = '<i class="fa-solid ' + icon + '"></i><div>' + esc(msg) + '</div>';
        wrap.appendChild(el);
        setTimeout(function () {
            el.classList.add('leaving');
            setTimeout(function () { el.remove(); }, 260);
        }, 4200);
    };

    /* ---------------- Modal generik ---------------- */
    var mBackdrop = document.getElementById('modalBackdrop');
    var mTitle = document.getElementById('modalTitle');
    var mBody = document.getElementById('modalBody');

    window.SKJAmodal = function (title, html, opts) {
        opts = opts || {};
        if (!mBackdrop) return;
        mTitle.innerHTML = title;
        mBody.innerHTML = html;
        mBackdrop.hidden = false;
        mBackdrop.querySelector('.modal').classList.toggle('modal-lg', !!opts.large);
        // Fokus input pertama
        var f = mBody.querySelector('input,select,textarea,button.btn');
        if (f) setTimeout(function () { f.focus(); }, 60);
    };
    window.SKJAmodalClose = closeModal;
    function closeModal() {
        if (mBackdrop) mBackdrop.hidden = true;
    }
    if (mBackdrop) {
        mBackdrop.addEventListener('click', function (ev) {
            if (ev.target === mBackdrop) closeModal();
        });
        var x = document.getElementById('modalClose');
        if (x) x.addEventListener('click', closeModal);
    }

    /* ---------------- Count-up untuk nilai statistik ---------------- */
    window.SKJAcountUp = function (el, to, dec, dur) {
        if (!el) return;
        dec = dec == null ? 2 : dec;
        dur = dur || 700;
        var from = parseFloat(String(el.textContent).replace(/\./g, '').replace(',', '.')) || 0;
        var start = performance.now();
        function frame(now) {
            var p = Math.min(1, (now - start) / dur);
            var eased = 1 - Math.pow(1 - p, 3);
            var val = from + (to - from) * eased;
            el.textContent = SKJAnum(val, dec);
            if (p < 1) requestAnimationFrame(frame);
        }
        requestAnimationFrame(frame);
    };

    /* ---------------- Grafik: opsi default Chart.js ---------------- */
    if (window.Chart) {
        Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
        Chart.defaults.font.size = 11.5;
        Chart.defaults.color = '#5E7E90';
        Chart.defaults.borderColor = '#F1F2F3';
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.legend.labels.boxWidth = 7;
        Chart.defaults.plugins.tooltip.backgroundColor = '#16394D';
        Chart.defaults.plugins.tooltip.padding = 10;
        Chart.defaults.plugins.tooltip.cornerRadius = 8;
        Chart.defaults.plugins.tooltip.titleFont = { weight: '700' };
    }
    window.SKJAbaseChartOpts = function (extra) {
        return Object.assign({
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { padding: 14 } },
                tooltip: { intersect: false }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { maxTicksLimit: 8, maxRotation: 0, autoSkip: true }
                },
                y: {
                    grid: { drawTicks: false },
                    border: { display: false },
                    ticks: { padding: 8 }
                }
            }
        }, extra || {});
    };

    /* ---------------- Tile peta dengan fallback multi-provider ----------------
     * Jaringan seluler/kampus kadang memblokir satu domain tile. Bila 6 tile
     * gagal berturut-turut, otomatis pindah ke provider berikutnya:
     * Esri Imagery (tanpa API key, sangat stabil) -> OpenStreetMap -> CARTO. */
    window.SKJAtileLayer = function (map, opts) {
        opts = opts || {};
        var PROVIDERS = [
            {
                url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
                attribution: 'Imagery &copy; <a href="https://www.esri.com">Esri</a>',
                subdomains: 'abc', maxZoom: 19
            },
            {
                url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                subdomains: 'abc', maxZoom: 19
            },
            {
                url: 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}.png',
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>',
                subdomains: 'abcd', maxZoom: 20
            }
        ];
        var idx = 0, errors = 0, layer = null;
        function attach() {
            var p = PROVIDERS[idx];
            errors = 0;
            if (layer) map.removeLayer(layer);
            layer = L.tileLayer(p.url, {
                maxZoom: Math.max(p.maxZoom, opts.maxZoom || 19),
                attribution: p.attribution,
                subdomains: p.subdomains,
                crossOrigin: true
            });
            layer.on('tileerror', function () {
                errors++;
                if (errors >= 6 && idx < PROVIDERS.length - 1) { idx++; attach(); }
            });
            layer.addTo(map);
        }
        attach();
        return layer;
    };

    /* ---------------- Konfirmasi aksi berbahaya ---------------- */
    window.SKJAconfirm = function (msg, onYes) {
        SKJAmodal('Konfirmasi', '<p style="margin-bottom:18px">' + esc(msg) + '</p>'
            + '<div style="display:flex;justify-content:flex-end;gap:10px">'
            + '<button type="button" class="btn btn-outline" id="cfNo">Batal</button>'
            + '<button type="button" class="btn btn-danger" id="cfYes"><i class="fa-solid fa-check"></i> Ya, lanjutkan</button></div>');
        document.getElementById('cfNo').addEventListener('click', closeModal);
        document.getElementById('cfYes').addEventListener('click', function () { closeModal(); onYes(); });
    };

    /* ---------------- Tandai footer diperbarui saat halaman siap ---------------- */
    SKJAmarkUpdated();
    if (DEMO && document.querySelector('.topbar-demo')) {
        // tooltip sederhana via title sudah ada di markup
    }
})();

/* ---- Bilah bantu mode ter-embed (iframe) ----
 * Implementasi dipusatkan di assets/js/embed-helper.js (juga dipakai halaman
 * publik dan login) yang menawarkan "Buka Tab Baru" + "Salin Tautan".
 * Untuk halaman aplikasi, file itu dimuat bersama footer.php. */

