<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

$allowedStatuses = ['unread', 'read', 'replied'];
$filter = trim($_GET['status'] ?? '');

if (!in_array($filter, $allowedStatuses, true)) {
    $filter = '';
}

$view_msg = null;

if (isset($_GET['view'])) {
    $mid = (int)$_GET['view'];

    $stmt = $conn->prepare("SELECT * FROM contact_messages WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $mid);
    $stmt->execute();
    $view_msg = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($view_msg && $view_msg['status'] === 'unread') {
        $stmt = $conn->prepare("UPDATE contact_messages SET status = 'read' WHERE id = ?");
        $stmt->bind_param("i", $mid);
        $stmt->execute();
        $stmt->close();

        $view_msg['status'] = 'read';
    }
}

if ($filter !== '') {
    $stmt = $conn->prepare("SELECT * FROM contact_messages WHERE status = ? ORDER BY created_at DESC");
    $stmt->bind_param("s", $filter);
    $stmt->execute();
    $messages = $stmt->get_result();
} else {
    $messages = $conn->query("SELECT * FROM contact_messages ORDER BY created_at DESC");
}

$unread = 0;
$unreadResult = $conn->query("SELECT COUNT(*) AS c FROM contact_messages WHERE status = 'unread'");
if ($unreadResult && ($row = $unreadResult->fetch_assoc())) {
    $unread = (int)$row['c'];
}

$site_name = function_exists('get_setting')
    ? get_setting($conn, 'site_name', 'EdTech Fellowship')
    : 'EdTech Fellowship';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <title>Messages - <?= h($site_name) ?> Admin</title>

  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="assets/css/admin.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/admin.css') ?>">
</head>

<body class="admin-system-page">

<?php include 'includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">

<header class="admin-topbar">
  <div class="topbar-left">
    <div class="topbar-breadcrumb">
      <a href="index.php">Dashboard</a> › <strong>Messages</strong>

      <?php if ($unread > 0): ?>
        <span class="badge badge-orange legacy-style-5dd2a67868">
          <?= (int)$unread ?> unread
        </span>
      <?php endif; ?>
    </div>
  </div>

  <div class="topbar-right">
    <?php if ($unread > 0): ?>
      <form method="POST" action="includes/process-messages.php" class="legacy-style-cccfa4560d">
        <input type="hidden" name="action" value="mark_all_read">
        <button type="submit" class="btn btn-secondary btn-sm">
          <i class="fa fa-check-double"></i> Mark All Read
        </button>
      </form>
    <?php endif; ?>

    <div class="admin-avatar">
      <div class="avatar-circle">
        <?= h(strtoupper(substr($ADMIN['full_name'] ?? 'A', 0, 1))) ?>
      </div>
    </div>
  </div>
</header>

<div class="admin-content">

<?php show_flash('messages'); ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Contact Messages</h1>
    <p class="page-subtitle">Messages from the website contact form.</p>
  </div>
</div>

<div class="tabs legacy-style-905d8b3da3">
  <a href="messages.php" class="tab-btn <?= $filter === '' ? 'active' : '' ?>">
    All Messages
  </a>

  <a href="messages.php?status=unread" class="tab-btn <?= $filter === 'unread' ? 'active' : '' ?>">
    Unread
    <?php if ($unread > 0): ?>
      <span class="badge badge-orange"><?= (int)$unread ?></span>
    <?php endif; ?>
  </a>

  <a href="messages.php?status=read" class="tab-btn <?= $filter === 'read' ? 'active' : '' ?>">
    Read
  </a>

  <a href="messages.php?status=replied" class="tab-btn <?= $filter === 'replied' ? 'active' : '' ?>">
    Replied
  </a>
</div>

<div style="display:grid;grid-template-columns:<?= $view_msg ? '1fr 1.4fr' : '1fr' ?>;gap:22px" class="msg-grid">

<div class="card">
<div class="table-wrap">

<table>
<thead>
<tr>
  <th>From</th>
  <th>Subject</th>
  <th>Date</th>
  <th>Status</th>
  <th>Actions</th>
</tr>
</thead>

<tbody>
<?php $has = false; ?>

<?php if ($messages): ?>
<?php while ($msg = $messages->fetch_assoc()): $has = true; ?>

<?php
$statusClass = $msg['status'] === 'unread'
    ? 'badge-orange'
    : ($msg['status'] === 'replied' ? 'badge-success' : 'badge-gray');

$isActive = $view_msg && (int)$view_msg['id'] === (int)$msg['id'];

$viewUrl = 'messages.php?view=' . (int)$msg['id'];

if ($filter !== '') {
    $viewUrl .= '&status=' . urlencode($filter);
}
?>

<tr style="<?= $isActive ? 'background:var(--primary-light)' : '' ?>; <?= $msg['status'] === 'unread' ? 'font-weight:700' : '' ?>">
  <td>
    <div><?= h($msg['full_name']) ?></div>
    <small class="legacy-style-5872de20d5">
      <?= h($msg['email']) ?>
    </small>
  </td>

  <td>
    <?= h(function_exists('truncate') ? truncate($msg['subject'], 40) : mb_strimwidth($msg['subject'], 0, 40, '…')) ?>
  </td>

  <td class="legacy-style-164b3e24d7">
    <?= !empty($msg['created_at']) ? date('M j, Y', strtotime($msg['created_at'])) : '-' ?><br>
    <small><?= !empty($msg['created_at']) ? date('H:i', strtotime($msg['created_at'])) : '' ?></small>
  </td>

  <td>
    <span class="badge <?= h($statusClass) ?>">
      <?= h(ucfirst($msg['status'])) ?>
    </span>
  </td>

  <td>
    <div class="tbl-actions">
      <a href="<?= h($viewUrl) ?>" class="btn btn-sm btn-primary btn-icon" title="View">
        <i class="fa fa-eye"></i>
      </a>

      <a href="mailto:<?= h($msg['email']) ?>?subject=Re:%20<?= rawurlencode($msg['subject']) ?>"
         class="btn btn-sm btn-teal btn-icon"
         title="Reply by email">
        <i class="fa fa-reply"></i>
      </a>

      <form method="POST" action="includes/process-messages.php" onsubmit="return confirm('Delete this message?')" class="legacy-style-cccfa4560d">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$msg['id'] ?>">
        <button type="submit" class="btn btn-sm btn-danger btn-icon">
          <i class="fa fa-trash"></i>
        </button>
      </form>
    </div>
  </td>
</tr>

<?php endwhile; ?>
<?php endif; ?>

<?php if (!$has): ?>
<tr>
  <td colspan="5">
    <div class="empty-state">
      <i class="fa fa-inbox"></i>
      <h3>No messages<?= $filter ? ' (' . h($filter) . ')' : '' ?></h3>
    </div>
  </td>
</tr>
<?php endif; ?>

</tbody>
</table>

</div>
</div>

<?php if ($view_msg): ?>
<div class="card">
  <div class="card-header">
    <div>
      <h3 class="card-title"><?= h($view_msg['subject']) ?></h3>
      <p class="legacy-style-4cfe384b44">
        From <strong><?= h($view_msg['full_name']) ?></strong> - <?= h($view_msg['email']) ?>
      </p>
    </div>

    <a href="messages.php<?= $filter ? '?status=' . urlencode($filter) : '' ?>" class="btn btn-sm btn-secondary">
      <i class="fa fa-times"></i>
    </a>
  </div>

  <div class="card-body">
    <div class="legacy-style-2f7e6b06df">
      <?= h($view_msg['message']) ?>
    </div>

    <div class="legacy-style-7eba6ef4b5">
      <i class="fa fa-clock"></i>
      Received <?= !empty($view_msg['created_at']) ? date('l, F j, Y H:i', strtotime($view_msg['created_at'])) : '-' ?>

      <?php if (!empty($view_msg['ip_address'])): ?>
        · IP: <?= h($view_msg['ip_address']) ?>
      <?php endif; ?>
    </div>

    <div class="legacy-style-93795365db">
      <a href="mailto:<?= h($view_msg['email']) ?>?subject=Re:%20<?= rawurlencode($view_msg['subject']) ?>" class="btn btn-primary">
        <i class="fa fa-reply"></i> Reply via Email
      </a>

      <?php if ($view_msg['status'] !== 'replied'): ?>
        <form method="POST" action="includes/process-messages.php" class="legacy-style-cccfa4560d">
          <input type="hidden" name="action" value="mark_replied">
          <input type="hidden" name="id" value="<?= (int)$view_msg['id'] ?>">
          <button type="submit" class="btn btn-teal">
            <i class="fa fa-check"></i> Mark as Replied
          </button>
        </form>
      <?php endif; ?>

      <form method="POST" action="includes/process-messages.php" onsubmit="return confirm('Delete this message?')" class="legacy-style-cccfa4560d">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$view_msg['id'] ?>">
        <button type="submit" class="btn btn-danger">
          <i class="fa fa-trash"></i> Delete
        </button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

</div>
</div>
</div>

<script>
const sidebar = document.getElementById('adminSidebar');
const main = document.getElementById('adminMain');

document.getElementById('sidebarToggle')?.addEventListener('click', () => {
  sidebar?.classList.toggle('collapsed');
  main?.classList.toggle('collapsed');
});

if (window.innerWidth < 900) {
  const grid = document.querySelector('.msg-grid');
  if (grid) grid.style.gridTemplateColumns = '1fr';
}
</script>

</body>
</html>
