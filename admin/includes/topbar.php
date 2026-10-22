<?php
declare(strict_types=1);

$global_topbar_title = trim((string)($global_topbar_title ?? 'Admin'));
$global_topbar_name = trim((string)($ADMIN['full_name'] ?? $_SESSION['admin_name'] ?? 'Administrator'));
$global_topbar_initial = strtoupper(substr($global_topbar_name !== '' ? $global_topbar_name : 'A', 0, 1));
$global_topbar_site_url = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';
?>
<header class="admin-topbar global-admin-topbar">
  <div class="topbar-left">
    <div class="topbar-breadcrumb">
      <a href="index.php">Dashboard</a>
      <span aria-hidden="true">›</span>
      <strong><?= h($global_topbar_title) ?></strong>
    </div>
  </div>
  <div class="topbar-right">
    <a href="messages.php" class="topbar-btn" title="Messages" aria-label="Messages">
      <i class="fas fa-envelope" aria-hidden="true"></i>
    </a>
    <?php if ($global_topbar_site_url !== ''): ?>
      <a href="<?= h($global_topbar_site_url) ?>" target="_blank" rel="noopener noreferrer" class="topbar-btn" title="View website" aria-label="View website">
        <i class="fas fa-external-link-alt" aria-hidden="true"></i>
      </a>
    <?php endif; ?>
    <div class="admin-avatar" title="<?= h($global_topbar_name) ?>">
      <div class="avatar-circle"><?= h($global_topbar_initial) ?></div>
    </div>
  </div>
</header>
