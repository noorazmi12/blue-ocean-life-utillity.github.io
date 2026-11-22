
<?php
/**
 * BOLU — Sidebar navigasi
 * Menu aktif sesuai $PAGE_ID.
 */

if (!defined('SMART_KJA_CONFIG_LOADED')) {
    exit('Akses langsung tidak diizinkan.');
}

$cu = current_user();
$unread = 0;

try {
    $unread = (int)db()->query(
        "SELECT COUNT(*) AS c FROM alerts WHERE status = 'open'"
    )->fetch()['c'];
} catch (Throwable $t) {
    /* Database belum siap */
}

function nav_active(string $pageId): string
{
    return $pageId === ($GLOBALS['PAGE_ID'] ?? '') ? ' active' : '';
}
?>

<aside class="sidebar" id="sidebar" aria-label="Navigasi utama">

    <div class="brand">
        <a href="dashboard.php" class="brand-link" title="Ke Dashboard">

            <!-- LOGO BOLU TERBARU -->
            <img
                src="assets/images/logo-mark-white.png?v=20261118-new"
                alt="Logo BOLU"
                class="brand-logo"
                width="48"
                height="48"
                style="object-fit:contain;"
            >

            <span class="brand-text">
                <span class="brand-name">BOLU</span>
                <span class="brand-sub">Blue Ocean Life Utility</span>
            </span>
        </a>

        <button
            type="button"
            class="sidebar-collapse"
            id="sidebarCollapse"
            aria-label="Perkecil sidebar"
            title="Perkecil / perbesar sidebar"
        >
            <i class="fa-solid fa-angles-left"></i>
        </button>
    </div>

    <nav class="sidebar-nav">

        <span class="nav-label">Menu Utama</span>

        <a class="nav-link<?= nav_active('dashboard') ?>"
           href="dashboard.php" title="Dashboard">
            <i class="fa-solid fa-gauge-high nav-icon"></i>
            <span class="nav-text">Dashboard</span>
        </a>

        <a class="nav-link<?= nav_active('penjelasan-kja') ?>"
           href="penjelasan-kja.php" title="Penjelasan KJA">
            <i class="fa-solid fa-book-open nav-icon"></i>
            <span class="nav-text">Penjelasan KJA</span>
        </a>

        <span class="nav-label">Monitoring</span>

        <a class="nav-link<?= nav_active('kualitas-air') ?>"
           href="kualitas-air.php" title="Monitoring Kualitas Air">
            <i class="fa-solid fa-droplet nav-icon"></i>
            <span class="nav-text">Kualitas Air</span>
        </a>

        <a class="nav-link<?= nav_active('fishcount') ?>"
           href="fishcount.php" title="FishCount AI">
            <i class="fa-solid fa-fish nav-icon"></i>
            <span class="nav-text">FishCount AI</span>
        </a>

        <a class="nav-link<?= nav_active('webgis') ?>"
           href="webgis.php" title="WebGIS">
            <i class="fa-solid fa-map-location-dot nav-icon"></i>
            <span class="nav-text">WebGIS</span>
        </a>

        <a class="nav-link<?= nav_active('batimetri') ?>"
           href="batimetri.php" title="Peta Batimetri">
            <i class="fa-solid fa-mountain nav-icon"></i>
            <span class="nav-text">Peta Batimetri</span>
        </a>

        <a class="nav-link<?= nav_active('lokasi-kja') ?>"
           href="lokasi-kja.php" title="Lokasi KJA Banten">
            <i class="fa-solid fa-anchor nav-icon"></i>
            <span class="nav-text">Lokasi KJA</span>
        </a>

        <a class="nav-link<?= nav_active('prediksi-ai') ?>"
           href="prediksi-ai.php" title="Prediksi AI">
            <i class="fa-solid fa-brain nav-icon"></i>
            <span class="nav-text">Prediksi AI</span>
        </a>

        <span class="nav-label">Manajemen</span>

        <a class="nav-link<?= nav_active('peringatan') ?>"
           href="peringatan.php" title="Peringatan">
            <i class="fa-solid fa-bell nav-icon"></i>
            <span class="nav-text">Peringatan</span>

            <?php if ($unread > 0): ?>
                <span class="nav-badge">
                    <?= $unread > 99 ? '99+' : $unread ?>
                </span>
            <?php endif; ?>
        </a>

        <a class="nav-link<?= nav_active('laporan') ?>"
           href="laporan.php" title="Histori & Laporan">
            <i class="fa-solid fa-clipboard-list nav-icon"></i>
            <span class="nav-text">Histori &amp; Laporan</span>
        </a>

        <a class="nav-link<?= nav_active('perangkat') ?>"
           href="perangkat.php" title="Manajemen Perangkat">
            <i class="fa-solid fa-microchip nav-icon"></i>
            <span class="nav-text">Manajemen Perangkat</span>
        </a>

        <a class="nav-link<?= nav_active('jenis-ikan') ?>"
           href="jenis-ikan.php" title="Jenis Ikan">
            <i class="fa-solid fa-fish-fins nav-icon"></i>
            <span class="nav-text">Jenis Ikan</span>
        </a>

        <a class="nav-link<?= nav_active('pengaturan') ?>"
           href="pengaturan.php" title="Pengaturan">
            <i class="fa-solid fa-gear nav-icon"></i>
            <span class="nav-text">Pengaturan</span>
        </a>

        <span class="nav-label">Sesi</span>

        <a class="nav-link nav-logout"
           href="logout.php" title="Keluar dari sistem">
            <i class="fa-solid fa-right-from-bracket nav-icon"></i>
            <span class="nav-text">Logout</span>
        </a>

    </nav>

    <div class="sidebar-footer">

        <div class="mini-profile">
            <span class="avatar avatar-sm">
                <?= e(strtoupper(mb_substr(
                    $cu['full_name'] ?: $cu['username'], 0, 1
                ))) ?>
            </span>

            <span class="mini-profile-text">
                <strong><?= e($cu['full_name'] ?: $cu['username']) ?></strong>
                <span><?= e(ucfirst($cu['role'])) ?></span>
            </span>
        </div>

        <div class="sidebar-ver">
            <span class="sidebar-ver-dot"></span>
            <span>BOLU v1.0.0<?= DEMO_MODE ? ' &middot; DEMO' : '' ?></span>
        </div>

    </div>

</aside>

<div class="sidebar-backdrop"
     id="sidebarBackdrop"
     aria-hidden="true"></div>
