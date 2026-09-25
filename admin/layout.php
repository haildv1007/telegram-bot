<?php
require_once __DIR__ . '/auth.php';
require_login();

$page = $page ?? '';
$title = $title ?? 'Dashboard';
$breadcrumb = $breadcrumb ?? [$title];
$user = current_user();

function nav_active($current, $key) {
    return $current === $key ? 'active' : '';
}
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h($title) ?> — Ads Bot Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
</head>
<body>

<aside class="sidebar">
  <div class="sidebar-brand">
    <span class="sidebar-brand-dot"></span>
    Ads Bot Admin
  </div>

  <nav class="sidebar-nav">
    <div class="sidebar-nav-section">Tổng quan</div>
    <a href="index.php" class="<?= nav_active($page, 'dashboard') ?>">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12l9-9 9 9M5 10v10h14V10"/></svg>
      Dashboard
    </a>

    <div class="sidebar-nav-section">Cấu hình</div>
    <a href="channels.php" class="<?= nav_active($page, 'channels') ?>">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18"/></svg>
      Channels báo cáo
    </a>
    <a href="groups.php" class="<?= nav_active($page, 'groups') ?>">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 8V7l-3-4H6L3 7v1M3 8h18v11a2 2 0 01-2 2H5a2 2 0 01-2-2V8z"/></svg>
      Nhóm channel
    </a>
    <a href="rules.php" class="<?= nav_active($page, 'rules') ?>">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h10M4 18h16"/></svg>
      Rule parse lead
    </a>
    <a href="bots.php" class="<?= nav_active($page, 'bots') ?>">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="7" width="16" height="12" rx="2"/><path d="M8 3v4M16 3v4M9 13h.01M15 13h.01"/></svg>
      Bots Telegram
    </a>
    <a href="credentials.php" class="<?= nav_active($page, 'credentials') ?>">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M6 21v-2a6 6 0 0112 0v2"/></svg>
      Tài khoản Ads
    </a>

    <div class="sidebar-nav-section">Vận hành</div>
    <a href="unmatched.php" class="<?= nav_active($page, 'unmatched') ?>">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v4M12 16h.01"/><circle cx="12" cy="12" r="9"/></svg>
      Lead chưa map
    </a>
    <a href="reports.php" class="<?= nav_active($page, 'reports') ?>">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 3H6a2 2 0 00-2 2v14a2 2 0 002 2h12a2 2 0 002-2V9z"/><path d="M14 3v6h6"/></svg>
      Lịch sử báo cáo
    </a>
  </nav>

  <div class="sidebar-footer">
    v0.1 · local dev
  </div>
</aside>

<header class="topbar">
  <div class="topbar-breadcrumb">
    <?php $last = count($breadcrumb) - 1; foreach ($breadcrumb as $i => $crumb): ?>
      <?php if ($i === $last): ?>
        <span class="current"><?= h($crumb) ?></span>
      <?php else: ?>
        <span><?= h($crumb) ?></span><span class="sep">/</span>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <div class="topbar-user">
    <span class="text-muted"><?= h($user['username']) ?></span>
    <div class="avatar"><?= h(mb_strtoupper(mb_substr($user['username'], 0, 1))) ?></div>
    <a href="logout.php" class="btn btn-sm">Đăng xuất</a>
  </div>
</header>

<main class="main">
  <?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($msg = flash('error')): ?><div class="alert alert-danger"><?= h($msg) ?></div><?php endif; ?>
