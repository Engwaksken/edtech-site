<?php

require_once 'includes/config.php';
require_once 'includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) die('DB error');
$conn->set_charset('utf8mb4');

// ── Handle actions ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'grant_access') {
        $venture_id = (int)($_POST['venture_id'] ?? 0);
        $email      = trim($_POST['email'] ?? '');
        $password   = trim($_POST['password'] ?? '');

        if ($venture_id <= 0 || $email === '' || $password === '') {
            flash('vpa', 'Venture, email and password are required.', 'error');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('vpa', 'Invalid email address.', 'error');
        } elseif (strlen($password) < 8) {
            flash('vpa', 'Password must be at least 8 characters.', 'error');
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("
                INSERT INTO venture_portal_access (venture_id, email, password_hash, is_active)
                VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE email=VALUES(email), password_hash=VALUES(password_hash), is_active=1
            ");
            $stmt->bind_param('iss', $venture_id, $email, $hash);
            flash('vpa', $stmt->execute() ? 'Portal access granted.' : 'Error: ' . $stmt->error,
                  $stmt->execute() ? 'success' : 'error');
            $stmt->close();
        }
        header('Location: venture-portal-access.php'); exit;
    }

    if ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        $is = (int)($_POST['is_active'] ?? 0);
        $conn->query("UPDATE venture_portal_access SET is_active=" . ($is ? 0 : 1) . " WHERE id=$id");
        flash('vpa', $is ? 'Account suspended.' : 'Account reactivated.');
        header('Location: venture-portal-access.php'); exit;
    }

    if ($action === 'reset_password') {
        $id       = (int)($_POST['id']       ?? 0);
        $password = trim($_POST['new_password'] ?? '');
        if (strlen($password) < 8) { flash('vpa','Password too short.','error'); header('Location: venture-portal-access.php'); exit; }
        $hash = $conn->real_escape_string(password_hash($password, PASSWORD_DEFAULT));
        $conn->query("UPDATE venture_portal_access SET password_hash='$hash' WHERE id=$id");
        flash('vpa', 'Password reset successfully.');
        header('Location: venture-portal-access.php'); exit;
    }

    if ($action === 'revoke_access') {
        $id = (int)($_POST['id'] ?? 0);
        $conn->query("DELETE FROM venture_portal_access WHERE id=$id LIMIT 1");
        flash('vpa', 'Portal access revoked.');
        header('Location: venture-portal-access.php'); exit;
    }
}

// ── Load data ────────────────────────────────────────────────
$access_list = $conn->query("
    SELECT vpa.*, v.name AS venture_name, v.status AS venture_status
    FROM venture_portal_access vpa
    JOIN ventures v ON v.id = vpa.venture_id
    ORDER BY vpa.created_at DESC
");

// Ventures without access (for the grant form)
$ventures_without = $conn->query("
    SELECT v.id, v.name FROM ventures v
    WHERE v.id NOT IN (SELECT venture_id FROM venture_portal_access)
    ORDER BY v.name
");

$site_name = function_exists('get_setting') ? get_setting($conn,'site_name','EdTech Fellowship') : 'EdTech Fellowship';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/includes/favicon.php'; ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Venture Portal Access - <?= h($site_name) ?> Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="assets/css/admin.css">
  <style>
    .portal-url-box {
      display:flex;align-items:center;gap:10px;
      background:var(--surface-alt,#f8fafc);border:1.5px solid var(--border,#e5e7eb);
      border-radius:9px;padding:10px 16px;margin-bottom:24px;
    }
    .portal-url-box code { font-size:13px;color:var(--primary,#4f46e5);flex:1; }
    .pw-gen-btn { font-size:12px;color:var(--primary,#4f46e5);background:none;border:none;cursor:pointer;text-decoration:underline;padding:0; }
  </style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="admin-main" id="adminMain">

<header class="admin-topbar">
  <div class="topbar-left">
    <div class="topbar-breadcrumb"><a href="index.php">Dashboard</a> › <strong>Venture Portal Access</strong></div>
  </div>
  <div class="topbar-right">
    <div class="admin-avatar"><div class="avatar-circle"><?= strtoupper(substr($ADMIN['full_name'],0,1)) ?></div></div>
  </div>
</header>

<div class="admin-content">
<?php show_flash('vpa'); ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Venture Portal Access</h1>
    <p class="page-subtitle">Grant and manage founder login credentials for the venture portal</p>
  </div>
  <div class="page-actions">
    <a href="<?= SITE_URL ?>/venture/" target="_blank" class="btn btn-secondary"><i class="fa fa-external-link-alt"></i> Open Venture Portal</a>
  </div>
</div>

<!-- Portal URL -->
<div class="portal-url-box">
  <i class="fa fa-link" style="color:var(--primary,#4f46e5)"></i>
  <code><?= SITE_URL ?>/venture/</code>
  <button onclick="navigator.clipboard.writeText('<?= SITE_URL ?>/venture/').then(()=>alert('Copied!'))" class="btn btn-secondary btn-sm"><i class="fa fa-copy"></i> Copy</button>
</div>

<div style="display:grid;grid-template-columns:1fr 360px;gap:20px;align-items:start">

  <!-- Access list -->
  <div class="card">
    <div class="card-header"><h3 class="card-title">Active Accounts</h3></div>
    <div class="table-wrap">
    <table>
    <thead>
    <tr>
      <th>Venture</th>
      <th>Login Email</th>
      <th>Last Login</th>
      <th>Status</th>
      <th>Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php $has=false; while($row=$access_list->fetch_assoc()): $has=true; ?>
    <tr>
      <td>
        <strong><?= h($row['venture_name']) ?></strong>
        <small style="display:block;color:var(--text-muted,#6b7280)"><?= ucfirst($row['venture_status']) ?></small>
      </td>
      <td style="font-size:.85rem"><?= h($row['email']) ?></td>
      <td style="font-size:.8rem;white-space:nowrap">
        <?= $row['last_login'] ? date('M j, Y g:ia', strtotime($row['last_login'])) : '<span style="color:var(--text-muted)">Never</span>' ?>
      </td>
      <td>
        <span class="badge <?= $row['is_active']?'badge-success':'badge-danger' ?>"><?= $row['is_active']?'Active':'Suspended' ?></span>
      </td>
      <td>
        <div class="tbl-actions">
          <!-- Reset password -->
          <button class="btn btn-sm btn-secondary" title="Reset password"
                  onclick="openResetModal(<?= $row['id'] ?>, '<?= h(addslashes($row['venture_name'])) ?>')">
            <i class="fa fa-key"></i>
          </button>
          <!-- Suspend/activate -->
          <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="id" value="<?= $row['id'] ?>">
            <input type="hidden" name="is_active" value="<?= $row['is_active'] ?>">
            <button class="btn btn-sm <?= $row['is_active']?'btn-warning':'btn-success' ?>"
                    title="<?= $row['is_active']?'Suspend':'Reactivate' ?>">
              <i class="fa <?= $row['is_active']?'fa-pause':'fa-play' ?>"></i>
            </button>
          </form>
          <!-- Revoke -->
          <form method="POST" style="display:inline"
                onsubmit="return confirm('Revoke portal access for <?= h(addslashes($row['venture_name'])) ?>?')">
            <input type="hidden" name="action" value="revoke_access">
            <input type="hidden" name="id" value="<?= $row['id'] ?>">
            <button class="btn btn-sm btn-danger"><i class="fa fa-ban"></i></button>
          </form>
        </div>
      </td>
    </tr>
    <?php endwhile; ?>
    <?php if (!$has): ?>
    <tr><td colspan="5"><div class="empty-state"><i class="fa fa-lock"></i><h3>No portal accounts yet</h3><p>Grant access to a venture using the form.</p></div></td></tr>
    <?php endif; ?>
    </tbody>
    </table>
    </div>
  </div>

  <!-- Grant access form -->
  <div class="card">
    <div class="card-header"><h3 class="card-title">Grant Access</h3></div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="action" value="grant_access">
        <div class="form-group">
          <label>Venture <span class="req">*</span></label>
          <select name="venture_id" class="form-control" required>
            <option value="">- Select Venture -</option>
            <?php while($v=$ventures_without->fetch_assoc()): ?>
              <option value="<?= $v['id'] ?>"><?= h($v['name']) ?></option>
            <?php endwhile; ?>
          </select>
          <span class="form-hint">Only ventures without existing access are shown.</span>
        </div>
        <div class="form-group">
          <label>Login Email <span class="req">*</span></label>
          <input type="email" name="email" class="form-control" placeholder="founder@venture.com" required>
        </div>
        <div class="form-group">
          <label>Initial Password <span class="req">*</span></label>
          <div style="display:flex;gap:8px">
            <input type="text" name="password" id="newPwField" class="form-control" placeholder="Min. 8 characters" required>
            <button type="button" class="btn btn-secondary btn-sm" onclick="genPassword()" title="Generate password"><i class="fa fa-random"></i></button>
          </div>
          <span class="form-hint">Share this with the founder. They can change it after login.</span>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%"><i class="fa fa-key"></i> Grant Access</button>
      </form>
    </div>
  </div>

</div>
</div>
</div>

<!-- Reset Password Modal -->
<div class="modal-overlay" id="resetModal">
<div class="modal">
  <div class="modal-header">
    <h2 class="modal-title" id="resetModalTitle">Reset Password</h2>
    <button type="button" class="modal-close" onclick="closeResetModal()">×</button>
  </div>
  <form method="POST">
    <input type="hidden" name="action" value="reset_password">
    <input type="hidden" name="id" id="resetId">
    <div class="modal-body">
      <div class="form-group">
        <label>New Password <span class="req">*</span></label>
        <div style="display:flex;gap:8px">
          <input type="text" name="new_password" id="resetPwField" class="form-control" placeholder="Min. 8 characters" required>
          <button type="button" class="btn btn-secondary btn-sm" onclick="genResetPw()"><i class="fa fa-random"></i></button>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeResetModal()">Cancel</button>
      <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Reset</button>
    </div>
  </form>
</div>
</div>

<script>
document.getElementById('sidebarToggle')?.addEventListener('click',()=>{
  document.getElementById('adminSidebar')?.classList.toggle('collapsed');
  document.getElementById('adminMain')?.classList.toggle('collapsed');
});
const resetModal=document.getElementById('resetModal');
function openResetModal(id,name){
  document.getElementById('resetModalTitle').textContent='Reset Password - '+name;
  document.getElementById('resetId').value=id;
  document.getElementById('resetPwField').value='';
  resetModal.classList.add('open');
}
function closeResetModal(){resetModal.classList.remove('open');}
resetModal.addEventListener('click',e=>{if(e.target===resetModal)closeResetModal();});

function randPw(){
  const c='ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%';
  return Array.from({length:14},()=>c[Math.floor(Math.random()*c.length)]).join('');
}
function genPassword(){ document.getElementById('newPwField').value=randPw(); }
function genResetPw(){ document.getElementById('resetPwField').value=randPw(); }
</script>
</body>
</html>
