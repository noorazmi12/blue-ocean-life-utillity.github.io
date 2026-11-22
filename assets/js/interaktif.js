/* =====================================================================
   BOLU — interaktif.js (lapisan interaktivitas site-wide)
   - Scroll-reveal bertahap (IntersectionObserver)
   - Ripple pada tombol
   - Tombol kembali ke atas
   - Bar progres baca (garis tipis di atas layar)
   Semua dekoratif: tidak menyentuh data, tidak mengubah angka apa pun,
   dan dilewati bila pengguna memilih pengurangan gerak (reduced motion).
   ===================================================================== */
(function () {
    'use strict';

    var docEl = document.documentElement;
    var reduce = false;
    try { reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}
    if (reduce) { docEl.classList.remove('js-anim'); return; }

    /* ---------- 1. Scroll-reveal bertahap ---------- */
    var SELECTOR = '.card, .stat-card, .page-header, .empty-state, .callout';
    var els = Array.prototype.slice.call(document.querySelectorAll(SELECTOR));

    function reveal(el, i) {
        if (el.dataset.revealed) return;
        el.dataset.revealed = '1';
        el.style.animationDelay = Math.min(i * 60, 420) + 'ms';
        el.classList.add('in-view');
    }

    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            var n = 0;
            entries.forEach(function (en) {
                if (!en.isIntersecting) return;
                reveal(en.target, n++);
                io.unobserve(en.target);
            });
        }, { threshold: 0.06, rootMargin: '0px 0px -4% 0px' });
        els.forEach(function (el) { io.observe(el); });

        // Jaring pengaman: elemen yang terlihat di layar tapi belum muncul
        window.addEventListener('load', function () {
            setTimeout(function () {
                els.forEach(function (el) {
                    if (el.dataset.revealed) return;
                    var r = el.getBoundingClientRect();
                    if (r.top < window.innerHeight && r.bottom > 0) reveal(el, 0);
                });
            }, 3000);
        });
    } else {
        els.forEach(function (el, i) { reveal(el, i); });
    }
    window.__skjaRevealOK = true; /* penanda bagi failsafe di footer.php */

    /* ---------- 2. Ripple pada tombol ---------- */
    document.addEventListener('click', function (ev) {
        if (ev.button !== 0) return;
        var btn = ev.target.closest('.btn');
        if (!btn || btn.disabled) return;
        var r = btn.getBoundingClientRect();
        if (!r.width || !r.height) return;
        var d = Math.max(r.width, r.height) * 2.1;
        var s = document.createElement('span');
        s.className = 'ripple';
        s.style.width = s.style.height = d + 'px';
        s.style.left = (ev.clientX - r.left - d / 2) + 'px';
        s.style.top = (ev.clientY - r.top - d / 2) + 'px';
        btn.appendChild(s);
        window.setTimeout(function () { s.remove(); }, 700);
    });

    /* ---------- 3. Tombol kembali ke atas ---------- */
    var btt = document.createElement('button');
    btt.type = 'button';
    btt.className = 'back-to-top';
    btt.setAttribute('aria-label', 'Kembali ke atas');
    btt.title = 'Kembali ke atas';
    btt.innerHTML = '<i class="fa-solid fa-arrow-up" aria-hidden="true"></i>';
    document.body.appendChild(btt);

    /* ---------- 4. Bar progres baca ---------- */
    var prog = document.createElement('div');
    prog.className = 'read-progress';
    prog.setAttribute('aria-hidden', 'true');
    document.body.appendChild(prog);

    var ticking = false;
    function syncScrollUI() {
        var y = window.scrollY || docEl.scrollTop || 0;
        btt.classList.toggle('show', y > 360);
        var h = docEl.scrollHeight - window.innerHeight;
        prog.style.width = h > 40 ? Math.min(100, (y / h) * 100) + '%' : '0%';
    }
    function onScroll() {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(function () { syncScrollUI(); ticking = false; });
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    syncScrollUI();

    btt.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
})();
