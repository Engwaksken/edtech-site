<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

function staff_table_exists(mysqli $conn, string $table): bool {
    $table = trim($table);
    if ($table === '') return false;

    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$table}'");

    return $res && $res->num_rows > 0;
}

function staff_column_exists(mysqli $conn, string $table, string $column): bool {
    $table = trim($table);
    $column = trim($column);

    if ($table === '' || $column === '') return false;

    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);

    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");

    return $res && $res->num_rows > 0;
}

if (!function_exists('safe_show_flash')) {
    function safe_show_flash(string $key): void {
        if (function_exists('show_flash')) {
            show_flash($key);
            return;
        }

        if (!empty($_SESSION['flash'][$key])) {
            $f = $_SESSION['flash'][$key];
            $type = $f['type'] ?? 'success';

            echo '<div class="alert alert-' . h($type === 'error' ? 'error' : 'success') . '">';
            echo h($f['message'] ?? '');
            echo '</div>';

            unset($_SESSION['flash'][$key]);
        }
    }
}

$categories = [
    'program_staff' => 'Program Staff',
    'mentor'        => 'Mentor',
    'advisor'       => 'Advisor',
    'partner_staff' => 'Partner Staff',
];

$loginRoles = [
    'Program Staff'  => 'Program Staff',
    'Mentor'         => 'Mentor',
    'Advisor'        => 'Advisor',
    'Reviewer'       => 'Reviewer',
    'Programs Lead'  => 'Programs Lead',
    'Administrator'  => 'Administrator',
];

$filter_cat = trim($_GET['category'] ?? '');
$search = trim($_GET['q'] ?? '');

$whereParts = ['1=1'];
$params = [];
$types = '';

if ($filter_cat !== '' && array_key_exists($filter_cat, $categories)) {
    $whereParts[] = 's.category = ?';
    $params[] = $filter_cat;
    $types .= 's';
}

if ($search !== '') {
    $like = '%' . $search . '%';
    $whereParts[] = '(s.full_name LIKE ? OR s.title LIKE ? OR s.organization LIKE ? OR s.email LIKE ? OR s.expertise LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
    $types .= 'sssss';
}

$where = implode(' AND ', $whereParts);

$adminJoin = '';
$adminSelect = ', NULL AS admin_user_id, NULL AS admin_username, NULL AS admin_role, NULL AS admin_status';

if (staff_table_exists($conn, 'admin_users')) {
    if (staff_column_exists($conn, 'admin_users', 'staff_id')) {
        $adminJoin = ' LEFT JOIN admin_users au ON au.staff_id = s.id ';
    } else {
        $adminJoin = ' LEFT JOIN admin_users au ON au.email = s.email AND s.email <> "" ';
    }

    $adminSelect = ', au.id AS admin_user_id, au.username AS admin_username, au.role AS admin_role, au.status AS admin_status';
}

$sql = "
    SELECT 
        s.*,
        (
            SELECT COUNT(*) 
            FROM venture_staff ss 
            WHERE ss.staff_id = s.id
        ) AS assigned_count
        $adminSelect
    FROM staff s
    $adminJoin
    WHERE $where
    ORDER BY s.sort_order ASC, s.full_name ASC
";

$stmt = $conn->prepare($sql);

if ($stmt && $params) {
    $stmt->bind_param($types, ...$params);
}

if ($stmt) {
    $stmt->execute();
    $staff_list = $stmt->get_result();
} else {
    $staff_list = false;
}

$edit = null;

if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];

    $editSql = "
        SELECT s.* $adminSelect
        FROM staff s
        $adminJoin
        WHERE s.id = ?
        LIMIT 1
    ";

    $editStmt = $conn->prepare($editSql);

    if ($editStmt) {
        $editStmt->bind_param('i', $editId);
        $editStmt->execute();
        $edit = $editStmt->get_result()->fetch_assoc();
        $editStmt->close();
    }
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

  <title>Staff & Mentors - <?= h($site_name) ?> Admin</title>

  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="assets/css/admin.css">
</head>

<body>

<?php include 'includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">

<header class="admin-topbar">
  <div class="topbar-left">
    <div class="topbar-breadcrumb">
      <a href="index.php">Dashboard</a> › <strong>Staff & Mentors</strong>
    </div>
  </div>

  <div class="topbar-right">
    <div class="admin-avatar">
      <div class="avatar-circle">
        <?php if (!empty($ADMIN['photo']) && file_exists('../' . $ADMIN['photo'])): ?>
          <img src="<?= SITE_URL . '/' . h($ADMIN['photo']) ?>" alt="">
        <?php else: ?>
          <?= h(strtoupper(substr($ADMIN['full_name'] ?? 'A', 0, 1))) ?>
        <?php endif; ?>
      </div>

      <div class="avatar-info">
        <span class="avatar-name">
          <?= h(explode(' ', $ADMIN['full_name'] ?? 'Admin')[0]) ?>
        </span>
        <span class="avatar-role">
          <?= h(str_replace('_', ' ', $ADMIN['role'] ?? 'admin')) ?>
        </span>
      </div>
    </div>
  </div>
</header>

<div class="admin-content">

<?php safe_show_flash('staff'); ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Staff & Mentors</h1>
    <p class="page-subtitle">
      Manage program staff, mentors, advisors, and login accounts for startup management.
    </p>
  </div>

  <button class="btn btn-primary" type="button" onclick="openModal()">
    <i class="fa fa-plus"></i> Add Staff
  </button>
</div>

<form method="GET" class="filter-bar">
  <div class="search-bar">
    <i class="fa fa-search"></i>
    <input type="text" name="q" placeholder="Search staff…" value="<?= h($search) ?>">
  </div>

  <select name="category" class="form-control legacy-style-2acaf3b57e" onchange="this.form.submit()">
    <option value="">All Categories</option>

    <?php foreach ($categories as $key => $label): ?>
      <option value="<?= h($key) ?>" <?= $filter_cat === $key ? 'selected' : '' ?>>
        <?= h($label) ?>
      </option>
    <?php endforeach; ?>
  </select>

  <button type="submit" class="btn btn-secondary">
    <i class="fa fa-filter"></i> Filter
  </button>

  <a href="staff.php" class="btn btn-secondary">
    <i class="fa fa-times"></i> Clear
  </a>
</form>

<div class="card">
<div class="table-wrap">

<table>
<thead>
<tr>
  <th>Photo</th>
  <th>Name</th>
  <th>Title / Organization</th>
  <th>Category</th>
  <th>Expertise</th>
  <th>Assigned</th>
  <th>Login</th>
  <th>Status</th>
  <th>Actions</th>
</tr>
</thead>

<tbody>

<?php $has = false; ?>

<?php if ($staff_list): ?>
<?php while ($s = $staff_list->fetch_assoc()): $has = true; ?>

<tr>
  <td>
    <?php if (!empty($s['photo']) && file_exists('../' . $s['photo'])): ?>
      <img src="<?= SITE_URL . '/' . h($s['photo']) ?>" class="tbl-avatar" alt="">
    <?php else: ?>
      <div class="tbl-avatar legacy-style-89bad37616">
        <?= h(strtoupper(substr($s['full_name'] ?? 'S', 0, 1))) ?>
      </div>
    <?php endif; ?>
  </td>

  <td>
    <strong><?= h($s['full_name']) ?></strong><br>

    <?php if (!empty($s['email'])): ?>
      <small class="legacy-style-5872de20d5">
        <?= h($s['email']) ?>
      </small>
    <?php endif; ?>
  </td>

  <td>
    <?= h($s['title'] ?: '-') ?><br>

    <?php if (!empty($s['organization'])): ?>
      <small class="legacy-style-5872de20d5">
        <?= h($s['organization']) ?>
      </small>
    <?php endif; ?>
  </td>

  <td>
    <?php
      $categoryClass = match ($s['category'] ?? '') {
          'mentor' => 'badge-orange',
          'program_staff' => 'badge-info',
          default => 'badge-gray',
      };
    ?>

    <span class="badge <?= h($categoryClass) ?>">
      <?= h($categories[$s['category']] ?? $s['category']) ?>
    </span>
  </td>

  <td>
    <small>
      <?= h(function_exists('truncate')
          ? truncate($s['expertise'] ?: '-', 40)
          : mb_strimwidth($s['expertise'] ?: '-', 0, 40, '…')) ?>
    </small>
  </td>

  <td>
    <span class="badge badge-info">
      <?= (int)$s['assigned_count'] ?> startups
    </span>
  </td>

  <td>
    <?php if (!empty($s['admin_user_id'])): ?>
      <span class="badge <?= ((int)$s['admin_status'] === 1) ? 'badge-success' : 'badge-danger' ?>">
        <?= h($s['admin_username'] ?: 'Login') ?>
      </span><br>

      <small class="legacy-style-5872de20d5">
        <?= h($s['admin_role'] ?: '') ?>
      </small>
    <?php else: ?>
      <span class="badge badge-gray">No login</span>
    <?php endif; ?>
  </td>

  <td>
    <form method="POST" action="includes/process-staff.php" class="legacy-style-cccfa4560d">
      <input type="hidden" name="action" value="toggle">
      <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">

      <button type="submit"
              class="badge <?= (int)$s['status'] ? 'badge-success' : 'badge-danger' ?>"
              style="border:none;cursor:pointer;font-size:.72rem">
        <?= (int)$s['status'] ? 'Active' : 'Inactive' ?>
      </button>
    </form>
  </td>

  <td>
    <div class="tbl-actions">
      <a href="assign-staff.php?staff_id=<?= (int)$s['id'] ?>"
         class="btn btn-sm btn-teal"
         title="View assignments">
        <i class="fa fa-link"></i>
      </a>

      <button type="button"
              onclick="editStaff(<?= h(json_encode($s, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>)"
              class="btn btn-sm btn-secondary">
        <i class="fa fa-edit"></i>
      </button>

      <form method="POST"
            action="includes/process-staff.php"
           
            onsubmit="return confirm('Delete this staff member?')" class="legacy-style-cccfa4560d">

        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">

        <button class="btn btn-sm btn-danger" type="submit">
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
  <td colspan="9">
    <div class="empty-state">
      <i class="fa fa-user-tie"></i>
      <h3>No staff yet</h3>
      <p>Add program staff, mentors, and advisors.</p>

      <button type="button" class="btn btn-primary" onclick="openModal()">
        <i class="fa fa-plus"></i> Add Staff
      </button>
    </div>
  </td>
</tr>
<?php endif; ?>

</tbody>
</table>

</div>
</div>

</div>
</div>

<div class="modal-overlay" id="staffModal">
<div class="modal modal-lg">

<div class="modal-header">
  <h2 class="modal-title" id="modalTitle">Add Staff Member</h2>
  <button class="modal-close" type="button" onclick="closeModal()">x</button>
</div>

<form method="POST" action="includes/process-staff.php" enctype="multipart/form-data">
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" id="f_id">
  <input type="hidden" name="existing_photo" id="f_existing_photo">

  <div class="modal-body">

    <div class="tabs legacy-style-905d8b3da3">
      <button type="button" class="tab-btn active" data-tab="profileTab">
        <i class="fa fa-user"></i> Profile
      </button>

      <button type="button" class="tab-btn" data-tab="loginTab">
        <i class="fa fa-key"></i> Login Account
      </button>
    </div>

    <div class="tab-content active" id="profileTab">
      <div class="form-grid form-grid-2">

        <div class="form-group">
          <label>Full Name <span class="req">*</span></label>
          <input type="text" name="full_name" id="f_full_name" class="form-control" required>
        </div>

        <div class="form-group">
          <label>Title / Role</label>
          <input type="text" name="title" id="f_title" class="form-control">
        </div>

        <div class="form-group">
          <label>Organization</label>
          <input type="text" name="organization" id="f_organization" class="form-control">
        </div>

        <div class="form-group">
          <label>Category</label>
          <select name="category" id="f_category" class="form-control" onchange="setDefaultLoginRole()">
            <?php foreach ($categories as $key => $label): ?>
              <option value="<?= h($key) ?>"><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group full">
          <label>Expertise / Skills</label>
          <input type="text" name="expertise" id="f_expertise" class="form-control">
        </div>

        <div class="form-group full">
          <label>Bio</label>
          <textarea name="bio" id="f_bio" class="form-control" rows="4"></textarea>
        </div>

        <div class="form-group">
          <label>Email</label>
          <input type="email" name="email" id="f_email" class="form-control" oninput="suggestUsername()">
        </div>

        <div class="form-group">
          <label>LinkedIn</label>
          <input type="url" name="linkedin" id="f_linkedin" class="form-control" placeholder="https://linkedin.com/in/...">
        </div>

        <div class="form-group">
          <label>Twitter</label>
          <input type="url" name="twitter" id="f_twitter" class="form-control" placeholder="https://twitter.com/...">
        </div>

        <div class="form-group">
          <label>Sort Order</label>
          <input type="number" name="sort_order" id="f_sort_order" class="form-control" value="0">
        </div>

        <div class="form-group">
          <label>Photo</label>
          <input type="file" name="photo" class="form-control" accept="image/*" onchange="previewImg(this,'prev_photo')">
          <img id="prev_photo" class="img-preview circle legacy-style-66253c00ea" alt="">
        </div>

        <div class="form-group legacy-style-9162a24597">
          <label class="legacy-style-c92fd9467d">
            <input type="checkbox" name="status" id="f_status" value="1" checked class="legacy-style-0e44bafd1c">
            Active / Visible
          </label>
        </div>

      </div>
    </div>

    <div class="tab-content" id="loginTab">
      <div class="alert alert-info legacy-style-905d8b3da3">
        Create or update a login account in <strong>admin_users</strong>.
        Leave password blank while editing to keep the current password.
      </div>

      <div class="form-grid form-grid-2">

        <div class="form-group full">
          <label class="legacy-style-c92fd9467d">
            <input type="checkbox" name="create_login" id="f_create_login" value="1" class="legacy-style-0e44bafd1c">
            Create / update login account for this staff member
          </label>

          <div class="form-hint">
            The account will be saved in admin_users and can be used to access the admin panel.
          </div>
        </div>

        <div class="form-group">
          <label>Username</label>
          <input type="text" name="username" id="f_username" class="form-control" placeholder="Auto generated if blank">
        </div>

        <div class="form-group">
          <label>Admin Role</label>
          <select name="login_role" id="f_login_role" class="form-control">
            <?php foreach ($loginRoles as $key => $label): ?>
              <option value="<?= h($key) ?>"><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group full">
          <label>Password</label>

          <div class="legacy-style-a76d597a07">
            <input type="password"
                   name="login_password"
                   id="f_login_password"
                   class="form-control"
                   placeholder="Required for new login account">

            <button type="button" class="btn btn-secondary" onclick="togglePassword()">
              <i class="fa fa-eye"></i>
            </button>
          </div>

          <div class="form-hint">
            For new login accounts, enter a password. For existing accounts, only enter a password when resetting it.
          </div>
        </div>

      </div>
    </div>

  </div>

  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>

    <button type="submit" class="btn btn-primary">
      <i class="fa fa-save"></i> Save
    </button>
  </div>
</form>

</div>
</div>

<script>
const sidebar = document.getElementById('adminSidebar');
const main = document.getElementById('adminMain');

document.getElementById('sidebarToggle')?.addEventListener('click', () => {
  sidebar?.classList.toggle('collapsed');
  main?.classList.toggle('collapsed');
});

const modal = document.getElementById('staffModal');

const defaultRoles = {
  program_staff: 'Program Staff',
  mentor: 'Mentor',
  advisor: 'Advisor',
  partner_staff: 'Program Staff'
};

function resetStaffForm() {
  document.getElementById('modalTitle').textContent = 'Add Staff Member';
  document.querySelector('#staffModal form').reset();

  document.getElementById('f_id').value = '';
  document.getElementById('f_existing_photo').value = '';
  document.getElementById('f_status').checked = true;
  document.getElementById('f_create_login').checked = false;
  document.getElementById('f_login_password').placeholder = 'Required for new login account';

  const prev = document.getElementById('prev_photo');
  prev.style.display = 'none';
  prev.src = '';

  setDefaultLoginRole();
  activateTab('profileTab');
}

function openModal() {
  resetStaffForm();
  modal.classList.add('open');
}

function closeModal() {
  modal.classList.remove('open');
}

modal.addEventListener('click', e => {
  if (e.target === modal) closeModal();
});

document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => activateTab(btn.dataset.tab));
});

function activateTab(id) {
  document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.tab === id);
  });

  document.querySelectorAll('.tab-content').forEach(tab => {
    tab.classList.toggle('active', tab.id === id);
  });
}

function setDefaultLoginRole() {
  const category = document.getElementById('f_category').value || 'program_staff';
  const role = defaultRoles[category] || 'Program Staff';
  const roleEl = document.getElementById('f_login_role');

  if (roleEl && !roleEl.dataset.userChanged) {
    roleEl.value = role;
  }
}

document.getElementById('f_login_role')?.addEventListener('change', function () {
  this.dataset.userChanged = '1';
});

function suggestUsername() {
  const username = document.getElementById('f_username');
  const email = document.getElementById('f_email').value.trim();

  if (username.value.trim() !== '') return;

  if (email.includes('@')) {
    username.value = email
      .split('@')[0]
      .toLowerCase()
      .replace(/[^a-z0-9_.-]+/g, '.');
  }
}

function editStaff(d) {
  resetStaffForm();

  document.getElementById('modalTitle').textContent = 'Edit Staff Member';

  document.getElementById('f_id').value = d.id || '';
  document.getElementById('f_full_name').value = d.full_name || '';
  document.getElementById('f_title').value = d.title || '';
  document.getElementById('f_organization').value = d.organization || '';
  document.getElementById('f_category').value = d.category || 'program_staff';
  document.getElementById('f_expertise').value = d.expertise || '';
  document.getElementById('f_bio').value = d.bio || '';
  document.getElementById('f_email').value = d.email || '';
  document.getElementById('f_linkedin').value = d.linkedin || '';
  document.getElementById('f_twitter').value = d.twitter || '';
  document.getElementById('f_sort_order').value = d.sort_order || 0;
  document.getElementById('f_status').checked = String(d.status) === '1';
  document.getElementById('f_existing_photo').value = d.photo || '';

  if (d.photo) {
    const photo = document.getElementById('prev_photo');
    photo.src = '<?= SITE_URL ?>/' + d.photo;
    photo.style.display = 'block';
  }

  if (d.admin_user_id) {
    document.getElementById('f_create_login').checked = true;
    document.getElementById('f_username').value = d.admin_username || '';
    document.getElementById('f_login_role').value = d.admin_role || defaultRoles[d.category] || 'Program Staff';
    document.getElementById('f_login_password').placeholder = 'Leave blank to keep existing password';
  } else {
    setDefaultLoginRole();
  }

  modal.classList.add('open');
}

function previewImg(input, previewId) {
  const prev = document.getElementById(previewId);

  if (input.files && input.files[0]) {
    const reader = new FileReader();

    reader.onload = e => {
      prev.src = e.target.result;
      prev.style.display = 'block';
    };

    reader.readAsDataURL(input.files[0]);
  }
}

function togglePassword() {
  const password = document.getElementById('f_login_password');
  password.type = password.type === 'password' ? 'text' : 'password';
}

<?php if ($edit): ?>
editStaff(<?= json_encode($edit, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>);
<?php endif; ?>
</script>

</body>
</html>
