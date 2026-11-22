<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

$cu = current_user();
$PAGE_ID = $PAGE_ID ?? 'dashboard';
$PAGE_TITLE = $PAGE_TITLE ?? APP_NAME;
$BREADCRUMB = $BREADCRUMB ?? [[$PAGE_TITLE, null]];
$PAGE_SCRIPTS = $PAGE_SCRIPTS ?? [];
$NEED_LEAFLET = $NEED_LEAFLET ?? false;

$staleMin = max(1, setting_int('data_stale_minutes', 10));
$refreshSec = max(5, setting_int('dashboard_refresh_seconds', 15));
$siteLocation = setting('site_location', 'Lokasi KJA') ?? 'Lokasi KJA';
$userInitials = $cu ? strtoupper(substr((string)($cu['full_name'] ?: $cu['username']), 0, 1)) : 'U';

$devTotal = (int)db()->query('SELECT COUNT(*) FROM devices WHERE is_active = 1')->fetchColumn();
$devCutoff = date('Y-m-d H:i:s', time() - $staleMin * 60);
$devOnlineStmt = db()->prepare("SELECT COUNT(*) FROM devices WHERE is_active = 1 AND status = 'online' AND last_communication >= ?");
$devOnlineStmt->execute([$devCutoff]);
$devOnline = (int)$devOnlineStmt->fetchColumn();
$unreadAlerts = (int)db()->query("SELECT COUNT(*) FROM alerts WHERE status = 'open'")->fetchColumn();
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<script>try{if(!matchMedia('(prefers-reduced-motion: reduce)').matches)document.documentElement.classList.add('js-anim')}catch(e){}</script>
<title><?= e($PAGE_TITLE) ?> — <?= e(APP_NAME) ?></title>
<link rel="icon" type="image/png" href="assets/images/favicon.png?v=2">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">
<link rel="stylesheet" href="assets/css/style.css?v=6">
</head>
<body class="app"
      data-page="<?= e($PAGE_ID) ?>"
      data-csrf="<?= e(csrf_token()) ?>"
      data-refresh="<?= $refreshSec ?>"
      data-stale="<?= $staleMin ?>"
      data-demo="<?= DEMO_MODE ? '1' : '0' ?>">

<div class="app-wrap">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="main-area">
        <header class="topbar">
            <div class="topbar-left">
                <button type="button" class="hamburger" id="sidebarToggle" aria-label="Buka/tutup menu navigasi">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <nav class="breadcrumb" aria-label="Breadcrumb">
                    <?php $lastIdx = count($BREADCRUMB) - 1; foreach ($BREADCRUMB as $i => $crumb): ?>
                        <?php if ($i === $lastIdx): ?>
                            <span class="bc-current"><?= e($crumb[0]) ?></span>
                        <?php elseif (!empty($crumb[1])): ?>
                            <a href="<?= e($crumb[1]) ?>"><?= e($crumb[0]) ?></a>
                            <i class="fa-solid fa-angle-right bc-sep"></i>
                        <?php else: ?>
                            <span><?= e($crumb[0]) ?></span>
                            <i class="fa-solid fa-angle-right bc-sep"></i>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </nav>
            </div>
            <div class="topbar-right">
                <?php if (DEMO_MODE): ?>
                <span class="badge badge-yellow badge-demo topbar-demo" title="Data berasal dari seeder simulasi hingga perangkat nyata terhubung">
                    <i class="fa-solid fa-flask"></i> Mode Demo
                </span>
                <?php endif; ?>
                <a class="chip-device" href="perangkat.php" title="Perangkat aktif yang terhubung — klik untuk kelola perangkat">
                    <span class="dot <?= $devOnline > 0 ? 'dot-live' : 'dot-offline' ?>"></span>
                    <span id="deviceChipText"><?= $devOnline ?>/<?= $devTotal ?> Perangkat Online</span>
                </a>
                <div class="dropdown notif-dropdown">
                    <button type="button" class="topbar-btn <?= $unreadAlerts > 0 ? 'has-badge' : '' ?>"
                            id="notifBtn" data-count="<?= $unreadAlerts ?>" aria-label="Notifikasi peringatan">
                        <i class="fa-regular fa-bell"></i>
                        <span class="notif-count" <?= $unreadAlerts === 0 ? 'style="display:none"' : '' ?>><?= $unreadAlerts > 99 ? '99+' : $unreadAlerts ?></span>
                    </button>
                    <div class="dropdown-menu notif-menu" id="notifMenu" hidden>
                        <div class="dropdown-head">
                            <strong>Peringatan Terbaru</strong>
                            <a href="peringatan.php">Lihat semua</a>
                        </div>
                        <div class="notif-list" id="notifList">
                            <div class="notif-empty"><i class="fa-solid fa-inbox"></i> Tidak ada peringatan terbuka</div>
                        </div>
                    </div>
                </div>
                <div class="dropdown profile-dropdown">
                    <button type="button" class="profile-btn" id="profileBtn" aria-label="Menu pengguna">
                        <span class="avatar"><?= e($userInitials) ?></span>
                        <span class="profile-meta">
                            <span class="profile-name"><?= e($cu['full_name'] ?: $cu['username']) ?></span>
                            <span class="profile-role"><?= e(ucfirst((string)$cu['role'])) ?></span>
                        </span>
                        <i class="fa-solid fa-angle-down"></i>
                    </button>
                    <div class="dropdown-menu profile-menu" id="profileMenu" hidden>
                        <div class="dropdown-user">
                            <span class="avatar"><?= e($userInitials) ?></span>
                            <div>
                                <strong><?= e($cu['full_name'] ?: $cu['username']) ?></strong>
                                <span><?= e($cu['email']) ?></span>
                            </div>
                        </div>
                        <a class="dropdown-item" href="pengaturan.php"><i class="fa-solid fa-gear"></i> Pengaturan &amp; Profil</a>
                        <a class="dropdown-item" href="perangkat.php"><i class="fa-solid fa-microchip"></i> Manajemen Perangkat</a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item dropdown-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Keluar</a>
                    </div>
                </div>
            </div>
        </header>
        <main class="content" id="content">
            <?= flash_render() ?>
