<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) die('Database connection not found.');
$conn->set_charset('utf8mb4');
function status_is_active($status): bool {
    return in_array((string)$status, ['1', 'active'], true);
}

function status_label($status): string {
    return status_is_active($status) ? 'active' : 'inactive';
}

function status_badge_class($status): string {
    return status_is_active($status) ? 'active' : 'inactive';
}


$site_name = function_exists('get_setting') ? get_setting($conn, 'site_name', 'EdTech Fellowship') : 'EdTech Fellowship';

// -- Search / filter -------------------------------
$search     = trim($_GET['q']      ?? '');
$filter_role= trim($_GET['role']   ?? '');
$filter_stat= trim($_GET['status'] ?? '');

$where  = [];
$params = [];
$types  = '';

if ($search !== '') {
    $where[]  = '(full_name LIKE ? OR username LIKE ? OR email LIKE ?)';
    $s = '%' . $search . '%';
    $params   = array_merge($params, [$s, $s, $s]);
    $types   .= 'sss';
}
if ($filter_role !== '') {
    $where[]  = 'role = ?';
    $params[] = $filter_role;
    $types   .= 's';
}
if ($filter_stat !== '') {

    if ($filter_stat === 'active') {
        $where[] = "(status='active')";
    }

    if ($filter_stat === 'inactive') {
        $where[] = "(status='inactive')";
    }
}

$sql  = 'SELECT * FROM admin_users';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY created_at DESC';

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$users = $stmt->get_result();
$stmt->close();

// -- Stats -----------------------------------------
$total   = (int)($conn->query("SELECT COUNT(*) c FROM admin_users")->fetch_assoc()['c'] ?? 0);
$active = 0;

$activeRes = $conn->query("
    SELECT COUNT(*) c
    FROM admin_users
    WHERE status='active' OR status=1
");

if ($activeRes && $row = $activeRes->fetch_assoc()) {
    $active = (int)$row['c'];
}
$admins  = (int)($conn->query("SELECT COUNT(*) c FROM admin_users WHERE role='admin'")->fetch_assoc()['c'] ?? 0);

$today_logins = (int)($conn->query("SELECT COUNT(*) c FROM admin_users WHERE DATE(last_login) = CURDATE()")->fetch_assoc()['c'] ?? 0);

// -- Distinct roles for filter ---------------------
$roles_res = $conn->query("SELECT DISTINCT role FROM admin_users WHERE role != '' ORDER BY role");
$all_roles = [];
if ($roles_res) while ($r = $roles_res->fetch_assoc()) $all_roles[] = $r['role'];

// -- Current user id (to protect self-delete) -----
$current_uid = (int)($_SESSION['admin_id'] ?? $ADMIN['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
<meta charset="UTF-8">
<title>Users - <?= h($site_name) ?> Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="assets/css/admin.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<style>

</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">
<header class="admin-topbar">
  <div class="topbar-left">
    <div class="topbar-breadcrumb">
      <a href="index.php">Dashboard</a> > <strong>Users</strong>
    </div>
  </div>
  <div class="topbar-right">
    <div class="admin-avatar">
      <div class="avatar-circle">
        <?php if (!empty($ADMIN['photo']) && file_exists('../' . $ADMIN['photo'])): ?>
          <img src="<?= h(SITE_URL . '/' . $ADMIN['photo']) ?>" alt="">
        <?php else: ?>
          <?= mb_strtoupper(mb_substr($ADMIN['full_name'] ?? 'A', 0, 1)) ?>
        <?php endif; ?>
      </div>
      <div class="avatar-info">
        <span class="avatar-name"><?= h($ADMIN['full_name'] ?? '') ?></span>
        <span class="avatar-role"><?= h($ADMIN['role'] ?? '') ?></span>
      </div>
    </div>
  </div>
</header>

<div class="admin-content">
<?php show_flash('users'); ?>

<!-- Stats -->
<div class="stats-grid legacy-style-8677744d08">
  <div class="stat-card">
    <div class="stat-icon purple"><i class="fa fa-users"></i></div>
    <div><div class="stat-val"><?= $total ?></div><div class="stat-label">Total Users</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green"><i class="fa fa-user-check"></i></div>
    <div><div class="stat-val"><?= $active ?></div><div class="stat-label">Active</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon orange"><i class="fa fa-user-shield"></i></div>
    <div><div class="stat-val"><?= $admins ?></div><div class="stat-label">Admins</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue"><i class="fa fa-sign-in-alt"></i></div>
    <div><div class="stat-val"><?= $today_logins ?></div><div class="stat-label">Logged In Today</div></div>
  </div>
</div>

<div class="page-header">
  <div>
    <h1 class="page-title">Users</h1>
    <p class="page-subtitle">Manage admin panel user accounts and permissions.</p>
  </div>
  <button type="button" class="btn btn-primary" onclick="openAddModal()">
    <i class="fa fa-user-plus"></i> Add User
  </button>
</div>

<!-- Filter bar -->
<form method="GET" action="users.php" id="filterForm">
<div class="users-filter-bar">
  <div class="search-bar">
    <i class="fa fa-search"></i>
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search name, username, email..."
           onchange="document.getElementById('filterForm').submit()">
  </div>
  <select name="role" class="form-control legacy-style-d1f21cf35a" onchange="this.form.submit()">
    <option value="">All Roles</option>
    <?php foreach ($all_roles as $role): ?>
      <option value="<?= h($role) ?>" <?= $filter_role===$role?'selected':'' ?>><?= h(ucfirst($role)) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="status" class="form-control legacy-style-d1f21cf35a" onchange="this.form.submit()">
    <option value="">All Statuses</option>
    <option value="active"   <?= $filter_stat==='active'  ?'selected':'' ?>>Active</option>
    <option value="inactive" <?= $filter_stat==='inactive'?'selected':'' ?>>Inactive</option>
  </select>
  <?php if ($search || $filter_role || $filter_stat): ?>
    <a href="users.php" class="btn btn-secondary">
      <i class="fa fa-times"></i> Clear
    </a>
  <?php endif; ?>
  <span class="legacy-style-c2b4930308">
    <?= $users->num_rows ?> user<?= $users->num_rows !== 1 ? 's' : '' ?>
  </span>
</div>
</form>

<!-- Bulk action bar -->
<div class="bulk-bar" id="bulkBar">
  <span id="bulkCount">0 selected</span>
  <form method="POST" action="includes/process-users.php" onsubmit="return bulkConfirm()">
    <input type="hidden" name="action" value="bulk_delete">
    <input type="hidden" name="ids" id="bulkIds">
    <button type="submit" class="btn btn-sm btn-danger"><i class="fa fa-trash"></i> Delete Selected</button>
  </form>
  <button type="button" class="btn btn-sm btn-secondary" onclick="clearSelection()">
    <i class="fa fa-times"></i> Clear
  </button>
</div>

<!-- Users Table -->
<div class="card">
<div class="table-wrap">
<table id="usersTable">
<thead>
<tr>
  <th class="legacy-style-3872989743"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)" title="Select all"></th>
  <th>User</th>
  <th>Role</th>
  <th>Status</th>
  <th>Last Login</th>
  <th>Created</th>
  <th>Actions</th>
</tr>
</thead>
<tbody>
<?php
$has = false;
if ($users && $users->num_rows > 0):
  while ($u = $users->fetch_assoc()):
    $has = true;
    $initial = mb_strtoupper(mb_substr($u['full_name'] ?: $u['username'], 0, 1));
    $isMe    = (int)$u['id'] === $current_uid;
    $roleClass = match($u['role']) {
        'admin'     => 'role-admin',
        'editor'    => 'role-editor',
        'moderator' => 'role-moderator',
        'viewer'    => 'role-viewer',
        default     => 'role-default',
    };
    $lastLogin = $u['last_login']
        ? date('M j, Y H:i', strtotime($u['last_login']))
        : null;
?>
<tr id="row_<?= (int)$u['id'] ?>">
  <td>
    <?php if (!$isMe): ?>
      <input type="checkbox" class="row-check" value="<?= (int)$u['id'] ?>" onchange="updateBulk()">
    <?php else: ?>
      <span title="Can't select yourself" class="legacy-style-7863342096">-</span>
    <?php endif; ?>
  </td>
  <td>
    <div class="user-info-cell">
      <?php if (!empty($u['photo']) && file_exists('../' . $u['photo'])): ?>
        <img src="<?= h(SITE_URL . '/' . $u['photo']) ?>" alt="" class="user-avatar-lg">
      <?php else: ?>
        <div class="user-avatar-initials"><?= $initial ?></div>
      <?php endif; ?>
      <div class="user-info-meta">
        <strong>
          <?= h($u['full_name'] ?: $u['username']) ?>
          <?php if ($isMe): ?><span class="badge badge-info legacy-style-5ae11d81f2">You</span><?php endif; ?>
        </strong>
        <span><?= h($u['username']) ?> . <?= h($u['email']) ?></span>
      </div>
    </div>
  </td>
  <td>
    <span class="role-badge <?= $roleClass ?>">
      <i class="fa <?= match($u['role']){
        'admin'=>'fa-shield-alt','editor'=>'fa-edit',
        'moderator'=>'fa-gavel','viewer'=>'fa-eye',default=>'fa-user'} ?>"></i>
      <?= h(ucfirst($u['role'] ?: 'user')) ?>
    </span>
  </td>
  <td>
    <form method="POST" action="includes/process-users.php" class="legacy-style-cccfa4560d">
      <input type="hidden" name="action" value="toggle_status">
      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
      <button type="submit"
             class="status-toggle <?= status_badge_class($u['status']) ?>"
              <?= $isMe ? 'disabled title="Can\'t deactivate yourself"' : '' ?>>
      <i class="fa <?= status_is_active($u['status']) ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
        <?= ucfirst(status_label($u['status'])) ?>
      </button>
    </form>
  </td>
  <td class="last-login-cell">
    <?php if ($lastLogin): ?>
      <i class="fa fa-clock"></i><?= h($lastLogin) ?>
    <?php else: ?>
      <span class="legacy-style-453655ae74">Never</span>
    <?php endif; ?>
  </td>
  <td class="legacy-style-5293442b5d">
    <?= date('M j, Y', strtotime($u['created_at'])) ?>
  </td>
  <td>
    <div class="tbl-actions">
      <button type="button"
              class="btn btn-sm btn-secondary"
              title="Edit user"
              onclick='openEditModal(<?= json_encode($u, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'>
        <i class="fa fa-edit"></i>
      </button>
      <button type="button"
              class="btn btn-sm btn-secondary"
              title="Change password"
              onclick="openPasswordModal(<?= (int)$u['id'] ?>, '<?= h(addslashes($u['full_name'] ?: $u['username'])) ?>')">
        <i class="fa fa-key"></i>
      </button>
      <?php if (!$isMe): ?>
      <button type="button"
              class="btn btn-sm btn-danger"
              title="Delete user"
              onclick="openDeleteModal(<?= (int)$u['id'] ?>, '<?= h(addslashes($u['full_name'] ?: $u['username'])) ?>')">
        <i class="fa fa-trash"></i>
      </button>
      <?php endif; ?>
    </div>
  </td>
</tr>
<?php
  endwhile;
endif;
if (!$has): ?>
<tr>
  <td colspan="7">
    <div class="empty-state">
      <i class="fa fa-users"></i>
      <h3>No users found</h3>
      <?php if ($search || $filter_role || $filter_stat): ?>
        <p>Try adjusting your filters.</p>
        <a href="users.php" class="btn btn-secondary">Clear Filters</a>
      <?php else: ?>
        <p>Create the first admin user.</p>
        <button type="button" class="btn btn-primary" onclick="openAddModal()">
          <i class="fa fa-user-plus"></i> Add User
        </button>
      <?php endif; ?>
    </div>
  </td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

</div><!-- /admin-content -->
</div><!-- /admin-main -->

<!-- -------------------------------------------------------
     ADD / EDIT USER MODAL
------------------------------------------------------- -->
<div class="modal-overlay" id="userModal">
<div class="modal modal-lg">
<div class="modal-header">
  <h2 class="modal-title" id="userModalTitle">Add User</h2>
  <button type="button" class="modal-close" onclick="closeModal('userModal')">x</button>
</div>
<form method="POST" action="includes/process-users.php" enctype="multipart/form-data"
      onsubmit="return validateUserForm(this)">
<input type="hidden" name="action" value="save">
<input type="hidden" name="id" id="f_id">
<input type="hidden" name="existing_photo" id="f_existing_photo">

<div class="modal-body">
<div class="form-grid form-grid-2">

  <!-- Photo upload -->
  <div class="form-group full">
    <label>Profile Photo</label>
    <div class="photo-upload-wrap">
      <div class="photo-preview-circle" id="photoPreviewWrap">
        <img id="photoPreviewImg" src="" alt="" class="legacy-style-0c14a484c1">
        <i class="fa fa-user" id="photoPreviewIcon"></i>
      </div>
      <div class="photo-upload-right">
        <input type="file" name="photo" id="photoInput" class="form-control"
               accept="image/jpeg,image/png,image/webp,image/gif"
               onchange="previewPhoto(this)">
        <p class="form-hint legacy-style-fe7b4979fe">JPG, PNG or WebP . Max 2MB . Square recommended</p>
        <button type="button" class="btn btn-sm btn-secondary legacy-style-8a77e5a311" onclick="clearPhoto()">
          <i class="fa fa-times"></i> Remove photo
        </button>
      </div>
    </div>
  </div>

  <!-- Full name -->
  <div class="form-group">
    <label>Full Name <span class="req">*</span></label>
    <input type="text" name="full_name" id="f_full_name" class="form-control" required
           placeholder="John Doe" autocomplete="off">
  </div>

  <!-- Username -->
  <div class="form-group">
    <label>Username <span class="req">*</span></label>
    <div class="legacy-style-d461c96de5">
      <input type="text" name="username" id="f_username" class="form-control" required
             placeholder="johndoe" autocomplete="off"
             pattern="[a-zA-Z0-9_\-]+" title="Letters, numbers, underscores and hyphens only"
             oninput="slugifyUsername(this)">
      <span id="usernameStatus" class="legacy-style-412dd200de"></span>
    </div>
    <p class="form-hint">Letters, numbers, underscores and hyphens only.</p>
  </div>

  <!-- Email -->
  <div class="form-group">
    <label>Email Address <span class="req">*</span></label>
    <input type="email" name="email" id="f_email" class="form-control" required
           placeholder="john@example.com" autocomplete="off">
  </div>

  <!-- Role -->
  <div class="form-group">
    <label>Role <span class="req">*</span></label>
    <select name="role" id="f_role" class="form-control" required>
      <option value=""> Select role </option>
      <option value="admin">Admin</option>
       <option value="program-director">Program Director</option>
       <option value="program-manager">Program Manager</option>
        <option value="meal-officer">Meal Officer</option>
      <option value="media">Media</option>
       <option value="staff">Staff</option>
      <option value="mentor">Mentor</option>
      <option value="consultant">Consultant</option>
      
    </select>
  </div>

  <!-- Status -->
  <div class="form-group">
    <label>Status</label>
    <select name="status" id="f_status" class="form-control">
      <option value="active">Active</option>
      <option value="inactive">Inactive</option>
    </select>
  </div>

  <!-- Password (only required on add) -->
  <div class="form-group" id="pwGroupAdd">
    <label>Password <span class="req" id="pwReqStar">*</span></label>
    <div class="legacy-style-d461c96de5">
      <input type="password" name="password" id="f_password" class="form-control"
             placeholder="Min 8 characters" autocomplete="new-password"
             oninput="checkStrength(this.value,'addStrengthFill','addStrengthLabel')">
      <button type="button" class="btn-pw-toggle" onclick="togglePw('f_password',this)" tabindex="-1">
        <i class="fa fa-eye"></i>
      </button>
    </div>
    <div class="pw-strength-bar"><div class="pw-strength-fill" id="addStrengthFill"></div></div>
    <p class="pw-strength-label" id="addStrengthLabel"></p>
  </div>

  <div class="form-group" id="pwConfirmGroupAdd">
    <label>Confirm Password <span class="req" id="pwConfReqStar">*</span></label>
    <div class="legacy-style-d461c96de5">
      <input type="password" name="password_confirm" id="f_password_confirm" class="form-control"
             placeholder="Repeat password" autocomplete="new-password">
      <button type="button" class="btn-pw-toggle" onclick="togglePw('f_password_confirm',this)" tabindex="-1">
        <i class="fa fa-eye"></i>
      </button>
    </div>
    <p class="form-hint legacy-style-6b99de8b69" id="pwMatchHint"></p>
  </div>

  <div class="form-group full legacy-style-6b99de8b69" id="editPwHint">
    <div class="alert alert-info legacy-style-1169661891">
      <i class="fa fa-info-circle"></i>
      Leave the password field blank to keep the current password.
      Use the <strong><i class="fa fa-key"></i> Key button</strong> to change it separately.
    </div>
  </div>

</div>
</div><!-- /modal-body -->

<div class="modal-footer">
  <button type="button" class="btn btn-secondary" onclick="closeModal('userModal')">Cancel</button>
  <button type="submit" class="btn btn-primary" id="saveUserBtn">
    <i class="fa fa-save"></i> Save User
  </button>
</div>
</form>
</div>
</div>

<!-- -------------------------------------------------------
     CHANGE PASSWORD MODAL
------------------------------------------------------- -->
<div class="modal-overlay" id="passwordModal">
<div class="modal legacy-style-d2a263560b">
<div class="modal-header">
  <h2 class="modal-title">Change Password</h2>
  <button type="button" class="modal-close" onclick="closeModal('passwordModal')">x</button>
</div>
<form method="POST" action="includes/process-users.php" onsubmit="return validatePasswordForm(this)">
<input type="hidden" name="action" value="change_password">
<input type="hidden" name="id" id="pw_user_id">

<div class="modal-body">
  <p class="form-hint legacy-style-905d8b3da3">
    Changing password for: <strong id="pw_user_name"></strong>
  </p>
  <div class="form-grid legacy-style-f6ce4d1e15">

    <div class="form-group">
      <label>New Password <span class="req">*</span></label>
      <div class="legacy-style-d461c96de5">
        <input type="password" name="new_password" id="chg_password" class="form-control"
               placeholder="Min 8 characters" required autocomplete="new-password"
               oninput="checkStrength(this.value,'chgStrengthFill','chgStrengthLabel')">
        <button type="button" class="btn-pw-toggle" onclick="togglePw('chg_password',this)" tabindex="-1">
          <i class="fa fa-eye"></i>
        </button>
      </div>
      <div class="pw-strength-bar"><div class="pw-strength-fill" id="chgStrengthFill"></div></div>
      <p class="pw-strength-label" id="chgStrengthLabel"></p>
    </div>

    <div class="form-group">
      <label>Confirm New Password <span class="req">*</span></label>
      <div class="legacy-style-d461c96de5">
        <input type="password" name="new_password_confirm" id="chg_password_confirm" class="form-control"
               placeholder="Repeat password" required autocomplete="new-password">
        <button type="button" class="btn-pw-toggle" onclick="togglePw('chg_password_confirm',this)" tabindex="-1">
          <i class="fa fa-eye"></i>
        </button>
      </div>
    </div>

  </div>
</div>

<div class="modal-footer">
  <button type="button" class="btn btn-secondary" onclick="closeModal('passwordModal')">Cancel</button>
  <button type="submit" class="btn btn-primary"><i class="fa fa-key"></i> Update Password</button>
</div>
</form>
</div>
</div>


<div class="modal-overlay" id="deleteModal">
<div class="modal danger-modal legacy-style-5521062821">
<div class="modal-header">
  <h2 class="modal-title"><i class="fa fa-exclamation-triangle legacy-style-497726e8c9"></i> Delete User</h2>
  <button type="button" class="modal-close" onclick="closeModal('deleteModal')">x</button>
</div>
<form method="POST" action="includes/process-users.php">
<input type="hidden" name="action" value="delete">
<input type="hidden" name="id" id="del_user_id">
<div class="modal-body">
  <p>Are you sure you want to permanently delete <strong id="del_user_name"></strong>?</p>
  <p class="legacy-style-b10b707993">
    <i class="fa fa-exclamation-circle"></i>
    This action cannot be undone. The user's account and profile photo will be permanently removed.
  </p>
</div>
<div class="modal-footer">
  <button type="button" class="btn btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
  <button type="submit" class="btn btn-danger"><i class="fa fa-trash"></i> Yes, Delete</button>
</div>
</form>
</div>
</div>



<script>

function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}
document.querySelectorAll('.modal-overlay').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});

/* -- OPEN ADD -------------------------- */
function openAddModal() {
  resetUserForm();
  document.getElementById('userModalTitle').textContent = 'Add User';
  document.getElementById('f_id').value = '';
  document.getElementById('pwGroupAdd').style.display    = '';
  document.getElementById('pwConfirmGroupAdd').style.display = '';
  document.getElementById('editPwHint').style.display    = 'none';
  document.getElementById('pwReqStar').style.display     = '';
  document.getElementById('pwConfReqStar').style.display = '';
  document.getElementById('f_password').required         = true;
  document.getElementById('f_password_confirm').required = true;
  document.getElementById('userModal').classList.add('open');
}

/* -- OPEN EDIT ------------------------- */
function openEditModal(u) {
  resetUserForm();
  document.getElementById('userModalTitle').textContent  = 'Edit User';
  document.getElementById('f_id').value                  = u.id || '';
  document.getElementById('f_full_name').value           = u.full_name || '';
  document.getElementById('f_username').value            = u.username  || '';
  document.getElementById('f_email').value               = u.email     || '';
  document.getElementById('f_role').value                = u.role      || '';
  document.getElementById('f_status').value              = u.status    || 'active';
  document.getElementById('f_existing_photo').value      = u.photo     || '';

  // Show existing photo
  if (u.photo) {
    const img = document.getElementById('photoPreviewImg');
    img.src  = '<?= rtrim(SITE_URL,'/') ?>/' + u.photo;
    img.style.display = 'block';
    document.getElementById('photoPreviewIcon').style.display = 'none';
  }

  // Edit mode: password not required
  document.getElementById('pwGroupAdd').style.display         = 'none';
  document.getElementById('pwConfirmGroupAdd').style.display  = 'none';
  document.getElementById('editPwHint').style.display         = '';
  document.getElementById('f_password').required              = false;
  document.getElementById('f_password_confirm').required      = false;

  document.getElementById('userModal').classList.add('open');
}

/* -- RESET FORM ------------------------ */
function resetUserForm() {
  document.querySelector('#userModal form').reset();
  document.getElementById('f_id').value           = '';
  document.getElementById('f_existing_photo').value = '';
  const img = document.getElementById('photoPreviewImg');
  img.src = ''; img.style.display = 'none';
  document.getElementById('photoPreviewIcon').style.display = '';
  document.getElementById('addStrengthFill').className  = 'pw-strength-fill';
  document.getElementById('addStrengthLabel').textContent = '';
  document.getElementById('pwMatchHint').style.display  = 'none';
}

/* -- PASSWORD MODAL -------------------- */
function openPasswordModal(id, name) {
  document.getElementById('pw_user_id').value   = id;
  document.getElementById('pw_user_name').textContent = name;
  document.getElementById('chg_password').value = '';
  document.getElementById('chg_password_confirm').value = '';
  document.getElementById('chgStrengthFill').className = 'pw-strength-fill';
  document.getElementById('chgStrengthLabel').textContent = '';
  document.getElementById('passwordModal').classList.add('open');
}

/* -- DELETE MODAL ---------------------- */
function openDeleteModal(id, name) {
  document.getElementById('del_user_id').value = id;
  document.getElementById('del_user_name').textContent = name;
  document.getElementById('deleteModal').classList.add('open');
}

/* --------------------------------------
   PHOTO PREVIEW
-------------------------------------- */
function previewPhoto(input) {
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  if (file.size > 2 * 1024 * 1024) {
    alert('Photo must be under 2MB.');
    input.value = '';
    return;
  }
  const reader = new FileReader();
  reader.onload = e => {
    const img = document.getElementById('photoPreviewImg');
    img.src = e.target.result;
    img.style.display = 'block';
    document.getElementById('photoPreviewIcon').style.display = 'none';
  };
  reader.readAsDataURL(file);
}
function clearPhoto() {
  document.getElementById('photoInput').value = '';
  document.getElementById('f_existing_photo').value = '';
  const img = document.getElementById('photoPreviewImg');
  img.src = ''; img.style.display = 'none';
  document.getElementById('photoPreviewIcon').style.display = '';
}

/* --------------------------------------
   PASSWORD STRENGTH
-------------------------------------- */
function checkStrength(pw, fillId, labelId) {
  const fill  = document.getElementById(fillId);
  const label = document.getElementById(labelId);
  if (!pw) { fill.className = 'pw-strength-fill'; label.textContent = ''; return; }
  let score = 0;
  if (pw.length >= 8)  score++;
  if (/[A-Z]/.test(pw)) score++;
  if (/[0-9]/.test(pw)) score++;
  if (/[^A-Za-z0-9]/.test(pw)) score++;
  const labels = ['', 'Weak', 'Fair', 'Good', 'Strong'];
  const classes = ['', 's1', 's2', 's3', 's4'];
  fill.className  = 'pw-strength-fill ' + (classes[score] || 's1');
  label.textContent = labels[score] || '';
}

/* --------------------------------------
   TOGGLE PASSWORD VISIBILITY
-------------------------------------- */
function togglePw(inputId, btn) {
  const inp = document.getElementById(inputId);
  const show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  btn.querySelector('i').className = show ? 'fa fa-eye-slash' : 'fa fa-eye';
}

/* --------------------------------------
   USERNAME SLUGIFY
-------------------------------------- */
function slugifyUsername(input) {
  input.value = input.value.toLowerCase().replace(/[^a-z0-9_\-]/g, '');
}

/* --------------------------------------
   FORM VALIDATION
-------------------------------------- */
function validateUserForm(form) {
  const pw  = form.password?.value || '';
  const cpw = form.password_confirm?.value || '';
  const isAdd = !document.getElementById('f_id').value;

  if (isAdd && pw.length < 8) {
    alert('Password must be at least 8 characters.');
    return false;
  }
  if (pw && pw !== cpw) {
    alert('Passwords do not match.');
    return false;
  }
  return true;
}
function validatePasswordForm(form) {
  const pw  = form.new_password.value;
  const cpw = form.new_password_confirm.value;
  if (pw.length < 8) { alert('Password must be at least 8 characters.'); return false; }
  if (pw !== cpw)    { alert('Passwords do not match.'); return false; }
  return true;
}

/* --------------------------------------
   BULK SELECT
-------------------------------------- */
function toggleSelectAll(cb) {
  document.querySelectorAll('.row-check').forEach(c => c.checked = cb.checked);
  updateBulk();
}
function updateBulk() {
  const checked = [...document.querySelectorAll('.row-check:checked')];
  const bar = document.getElementById('bulkBar');
  if (checked.length) {
    bar.classList.add('visible');
    document.getElementById('bulkCount').textContent = checked.length + ' selected';
    document.getElementById('bulkIds').value = checked.map(c => c.value).join(',');
  } else {
    bar.classList.remove('visible');
  }
  document.getElementById('selectAll').indeterminate =
    checked.length > 0 && checked.length < document.querySelectorAll('.row-check').length;
}
function clearSelection() {
  document.querySelectorAll('.row-check').forEach(c => c.checked = false);
  document.getElementById('selectAll').checked = false;
  document.getElementById('bulkBar').classList.remove('visible');
}
function bulkConfirm() {
  const n = document.querySelectorAll('.row-check:checked').length;
  return confirm(`Permanently delete ${n} user${n !== 1 ? 's' : ''}? This cannot be undone.`);
}
</script>

</body>
</html>
