/* =====================================================================
   BOLU — embed-helper.js
   Saat aplikasi berjalan TEREMBED (panel pratinjau / iframe lintas situs),
   browser bisa memblokir cookie sesi dan/atau popup target="_blank".
   Bilah bantu menawarkan tiga jalan keluar:
     1. Tombol "Buka Tab Baru"   (jika popup diizinkan)
     2. Tombol "Salin Tautan"    (clipboard API + fallback execCommand)
     3. Teks URL yang bisa diseleksi sekali klik (user-select:all)
   Halaman normal (bukan iframe) tidak menampilkan apa pun.
   ===================================================================== */
(function () {
    'use strict';
    try {
        if (window.self === window.top) return; // bukan iframe — tidak perlu bantuan
    } catch (e) { return; }

    var url = location.href;

    var bar = document.createElement('div');
    bar.setAttribute('role', 'region');
    bar.setAttribute('aria-label', 'Bantuan pratinjau tersemat');
    bar.style.cssText =
        'position:fixed;left:12px;right:12px;bottom:12px;z-index:2147483000;'
        + 'background:#16394D;color:#fff;padding:12px 16px;border-radius:12px;'
        + 'font:14px/1.5 system-ui,sans-serif;box-shadow:0 10px 30px rgba(0,0,0,.35);'
        + 'display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;'
        + 'border:1px solid rgba(255,255,255,.15)';

    var txt = document.createElement('span');
    txt.innerHTML = 'Mode pratinjau tersemat dapat memblokir sesi. '
        + 'Buka di tab baru untuk pengalaman penuh:'
        + '<br><code style="display:inline-block;margin-top:4px;max-width:520px;overflow:hidden;'
        + 'text-overflow:ellipsis;white-space:nowrap;vertical-align:middle;background:rgba(255,255,255,.12);'
        + 'padding:4px 10px;border-radius:7px;font-size:12.5px;cursor:text;-webkit-user-select:all;user-select:all">'
        + url.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</code>';

    var actions = document.createElement('span');
    actions.style.cssText = 'display:flex;gap:10px;align-items:center;flex-wrap:wrap';

    /* --- Tombol 1: buka tab baru (pengalihan standar) --- */
    var a = document.createElement('a');
    a.href = url;
    a.target = '_blank';
    a.rel = 'noopener';
    a.textContent = 'Buka Tab Baru';
    a.style.cssText = 'background:#0296C6;color:#16394D;font-weight:700;padding:9px 16px;'
        + 'border-radius:9px;text-decoration:none;white-space:nowrap;display:inline-block';
    a.addEventListener('click', function () {
        // Jika iframe sandbox memblokir popup, tampilkan petunjuk manual.
        setTimeout(function () {
            note.textContent = 'Tidak terjadi apa-apa? Seleksi alamat di atas lalu salin manual ke browser.';
            note.style.display = 'block';
        }, 1200);
    });

    /* --- Tombol 2: salin tautan --- */
    var copy = document.createElement('button');
    copy.type = 'button';
    copy.textContent = 'Salin Tautan';
    copy.style.cssText = 'background:rgba(255,255,255,.14);color:#fff;font-weight:700;padding:9px 16px;'
        + 'border-radius:9px;border:1px solid rgba(255,255,255,.3);cursor:pointer;white-space:nowrap';
    copy.addEventListener('click', function () {
        function done(ok) {
            copy.textContent = ok ? 'Tersalin!' : 'Gagal — salin manual';
            setTimeout(function () { copy.textContent = 'Salin Tautan'; }, 2200);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function () { done(true); },
                function () { done(fallback()); });
        } else {
            done(fallback());
        }
        function fallback() {
            try {
                var ta = document.createElement('textarea');
                ta.value = url;
                ta.style.cssText = 'position:fixed;opacity:0';
                document.body.appendChild(ta);
                ta.select();
                var ok = document.execCommand('copy');
                document.body.removeChild(ta);
                return ok;
            } catch (e) { return false; }
        }
    });

    /* --- Catatan bantu (muncul jika popup diblokir) --- */
    var note = document.createElement('small');
    note.style.cssText = 'display:none;color:#CFEAF5;width:100%';
    note.textContent = '';

    actions.appendChild(a);
    actions.appendChild(copy);
    bar.appendChild(txt);
    bar.appendChild(actions);
    bar.appendChild(note);

    function mount() {
        if (document.body) document.body.appendChild(bar);
        else setTimeout(mount, 120);
    }
    mount();
})();
