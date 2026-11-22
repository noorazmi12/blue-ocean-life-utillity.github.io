<?php
/**
 * BOLU — Footer global (penutup konten + pemuatan script)
 */
if (!defined('SMART_KJA_CONFIG_LOADED')) {
    exit('Akses langsung tidak diizinkan.');
}
$PAGE_SCRIPTS = $PAGE_SCRIPTS ?? [];
$NEED_LEAFLET = $NEED_LEAFLET ?? false;
$NEED_CHART   = $NEED_CHART   ?? true;
?>
        </main><!-- /content -->

        <footer class="site-footer">
            <div class="sf-inner">
                <div class="sf-brand">
                    <div class="sf-logo">
                        <img src="assets/images/logo-mark-white.png" alt="" class="sf-logo-img">
                        <span class="sf-logo-name">BOLU <em>Ocean Life Utility</em></span>
                    </div>
                    <p class="sf-tagline">Pantau laut, energi, dan biota dalam satu dashboard.</p>
                    <p class="sf-loc"><span class="dot dot-live"></span> KJA <?= e(setting('site_location', 'Lokasi KJA')) ?></p>
                </div>
                <nav class="sf-cols" aria-label="Tautan footer">
                    <div class="sf-col">
                        <h4>Monitoring</h4>
                        <a href="gelombang.php">Monitoring Gelombang</a>
                        <a href="energi-owc.php">Energi OWC</a>
                        <a href="kualitas-air.php">Kualitas Air</a>
                        <a href="fishcount.php">FishCount AI</a>
                    </div>
                    <div class="sf-col">
                        <h4>Data &amp; Peta</h4>
                        <a href="webgis.php">WebGIS</a>
                        <a href="batimetri.php">Peta Batimetri</a>
                        <a href="perangkat.php">Perangkat</a>
                        <a href="jenis-ikan.php">Jenis Ikan</a>
                        <a href="peringatan.php">Peringatan Dini</a>
                    </div>
                    <div class="sf-col">
                        <h4>Analitik</h4>
                        <a href="prediksi-ai.php">Prediksi AI</a>
                        <a href="laporan.php">Laporan</a>
                        <a href="penjelasan-kja.php">Penjelasan KJA</a>
                    </div>
                    <div class="sf-col">
                        <h4>Sistem</h4>
                        <a href="dashboard.php">Dashboard</a>
                        <a href="pengaturan.php">Pengaturan</a>
                    </div>
                </nav>
            </div>
            <div class="sf-bottom">
                <span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.</span>
                <span class="footer-update" id="lastUpdateStamp">
                    <span class="dot dot-live"></span> Pembaruan terakhir: <strong id="lastUpdateText">&mdash;</strong>
                </span>
            </div>
            <div class="sf-ghost" aria-hidden="true">BOLU</div>
        </footer>
    </div><!-- /main-area -->
</div><!-- /app-wrap -->

<!-- Modal generik (dipakai beberapa halaman) -->
<div class="modal-backdrop" id="modalBackdrop" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="modal-header">
            <h3 id="modalTitle">Judul Modal</h3>
            <button type="button" class="modal-close" id="modalClose" aria-label="Tutup">&times;</button>
        </div>
        <div class="modal-body" id="modalBody"></div>
    </div>
</div>

<div class="toast-wrap" id="toastWrap" aria-live="polite"></div>

<script src="assets/vendor/chartjs/chart.umd.min.js"></script>
<?php if ($NEED_LEAFLET): ?>
<script src="assets/vendor/leaflet/leaflet.js"></script>
<?php endif; ?>
<script src="assets/js/app.js?v=5"></script>
<script src="assets/js/interaktif.js?v=5"></script>
<script src="assets/js/embed-helper.js?v=1"></script>
<?php foreach ($PAGE_SCRIPTS as $script):
    $file = basename($script);
    if (is_file(__DIR__ . '/../assets/js/' . $file)): ?>
<script src="assets/js/<?= e($file) ?>?v=5"></script>
<?php endif; endforeach; ?>
<script>
/* Jaring pengaman: bila interaktif.js gagal dimuat, pastikan konten tetap tampil. */
window.addEventListener('load', function () {
    setTimeout(function () {
        if (window.__skjaRevealOK) return;
        document.querySelectorAll('html.js-anim .card:not(.in-view), html.js-anim .stat-card:not(.in-view), '
            + 'html.js-anim .page-header:not(.in-view), html.js-anim .empty-state:not(.in-view), '
            + 'html.js-anim .callout:not(.in-view)').forEach(function (el) { el.classList.add('in-view'); });
    }, 2500);
});
</script>
</body>
</html>
