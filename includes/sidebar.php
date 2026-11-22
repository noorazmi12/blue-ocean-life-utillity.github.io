<?php
declare(strict_types=1);
$navActive = static fn (string $pid): string => $pid === ($PAGE_ID ?? '') ? ' active' : '';
?>
<aside class="sidebar" id="sidebar" aria-label="Navigasi utama">
    <div class="brand">
        <a href="dashboard.php" class="brand-link" title="Ke Dashboard">
            <img src="assets/images/logo-mark-white.png?v=20261118-new" alt="Logo BOLU" class="brand-logo" width="48" height="48" style="object-fit:contain;">
            <span class="brand-text">
                <span class="brand-name">BOLU</span>
                <span class="brand-sub">Blue Ocean Life Utility</span>
            </span>
        </a>
        <button type="button" class="sidebar-collapse" id="sidebarCollapse" aria-label="Perkecil sidebar" title="Perkecil / perbesar sidebar">
            <i class="fa-solid fa-angles-left"></i>
        </button>
    </div>
    <nav class="sidebar-nav">
        <span class="nav-label">Menu Utama</span>
        <a class="nav-link<?= $navActive('dashboard') ?>" href="dashboard.php"><i class="fa-solid fa-gauge-high nav-icon"></i><span class="nav-text">Dashboard</span></a>
        <a class="nav-link<?= $navActive('penjelasan-kja') ?>" href="penjelasan-kja.php"><i class="fa-solid fa-book-open nav-icon"></i><span class="nav-text">Penjelasan KJA</span></a>

        <span class="nav-label">Monitoring</span>
        <a class="nav-link<?= $navActive('kualitas-air') ?>" href="kualitas-air.php"><i class="fa-solid fa-droplet nav-icon"></i><span class="nav-text">Kualitas Air</span></a>
        <a class="nav-link<?= $navActive('gelombang') ?>" href="gelombang.php"><i class="fa-solid fa-water nav-icon"></i><span class="nav-text">Gelombang</span></a>
        <a class="nav-link<?= $navActive('energi-owc') ?>" href="energi-owc.php"><i class="fa-solid fa-bolt nav-icon"></i><span class="nav-text">Energi OWC</span></a>
        <a class="nav-link<?= $navActive('fishcount') ?>" href="fishcount.php"><i class="fa-solid fa-fish nav-icon"></i><span class="nav-text">FishCount AI</span></a>
        <a class="nav-link<?= $navActive('webgis') ?>" href="webgis.php"><i class="fa-solid fa-map-location-dot nav-icon"></i><span class="nav-text">WebGIS</span></a>
        <a class="nav-link<?= $navActive('batimetri') ?>" href="batimetri.php"><i class="fa-solid fa-mountain nav-icon"></i><span class="nav-text">Peta Batimetri</span></a>
        <a class="nav-link<?= $navActive('lokasi-kja') ?>" href="lokasi-kja.php"><i class="fa-solid fa-anchor nav-icon"></i><span class="nav-text">Lokasi KJA</span></a>
        <a class="nav-link<?= $navActive('prediksi-ai') ?>" href="prediksi-ai.php"><i class="fa-solid fa-brain nav-icon"></i><span class="nav-text">Prediksi AI</span></a>

        <span class="nav-label">Manajemen</span>
        <a class="nav-link<?= $navActive('peringatan') ?>" href="peringatan.php">
            <i class="fa-solid fa-bell nav-icon"></i><span class="nav-text">Peringatan</span>
            <?php if (($unreadAlerts ?? 0) > 0): ?><span class="nav-badge"><?= $unreadAlerts > 99 ? '99+' : $unreadAlerts ?></span><?php endif; ?>
        </a>
        <a class="nav-link<?= $navActive('laporan') ?>" href="laporan.php"><i class="fa-solid fa-clipboard-list nav-icon"></i><span class="nav-text">Histori &amp; Laporan</span></a>
        <a class="nav-link<?= $navActive('perangkat') ?>" href="perangkat.php"><i class="fa-solid fa-microchip nav-icon"></i><span class="nav-text">Manajemen Perangkat</span></a>
        <a class="nav-link<?= $navActive('jenis-ikan') ?>" href="jenis-ikan.php"><i class="fa-solid fa-fish-fins nav-icon"></i><span class="nav-text">Jenis Ikan</span></a>
        <a class="nav-link<?= $navActive('pengaturan') ?>" href="pengaturan.php"><i class="fa-solid fa-gear nav-icon"></i><span class="nav-text">Pengaturan</span></a>

        <span class="nav-label">Sesi</span>
        <a class="nav-link nav-logout" href="logout.php"><i class="fa-solid fa-right-from-bracket nav-icon"></i><span class="nav-text">Logout</span></a>
    </nav>
    <div class="sidebar-footer">
        <div class="mini-profile">
            <span class="avatar avatar-sm"><?= e($userInitials) ?></span>
            <span class="mini-profile-text">
                <strong><?= e($cu['full_name'] ?: $cu['username']) ?></strong>
                <span><?= e(ucfirst((string)$cu['role'])) ?></span>
            </span>
        </div>
        <div class="sidebar-ver"><span class="sidebar-ver-dot"></span><span>BOLU v<?= e(APP_VERSION) ?></span></div>
    </div>
</aside>
<div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>
