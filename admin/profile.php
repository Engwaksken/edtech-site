<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

$adminId = (int)($ADMIN['id'] ?? $_SESSION['admin_id'] ?? 0);

if ($adminId <= 0) {
    die('Invalid admin session.');
}

function admin_value(array $admin, string $key, string $default = ''): string
{
    return isset($admin[$key]) && $admin[$key] !== null ? (string)$admin[$key] : $default;
}

$stmt = $conn->prepare("SELECT * FROM admin_users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $adminId);
$stmt->execute();
$freshAdmin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($freshAdmin) {
    $ADMIN = $freshAdmin;
}

$flash = $_SESSION['profile_flash'] ?? null;
unset($_SESSION['profile_flash']);

$site_name = function_exists('get_setting')
    ? get_setting($conn, 'site_name')
    : 'Admin';

$adminFullName = admin_value($ADMIN, 'full_name', 'Admin');
$adminEmail    = admin_value($ADMIN, 'email');
$adminRole     = admin_value($ADMIN, 'role', 'admin');
$adminPhoto    = admin_value($ADMIN, 'photo');
$lastLogin     = admin_value($ADMIN, 'last_login');

$avatarLetter = strtoupper(substr($adminFullName, 0, 1));
$roleLabel    = ucwords(str_replace(['_', '-'], ' ', $adminRole));

$activeTab = ($flash['tab'] ?? '') === 'password' ? 'password' : 'profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Profile - <?= h($site_name) ?> Admin</title>

  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="assets/css/admin.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/admin.css') ?>">

 
</head>
<body class="admin-system-page">

<?php include __DIR__ . '/includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">
  <header class="admin-topbar">
    <div class="topbar-left">
      <div class="topbar-breadcrumb">
        <a href="index.php">Dashboard</a> › <strong>My Profile</strong>
      </div>
    </div>

    <div class="topbar-right">
      <div class="admin-avatar">
        <div class="avatar-circle"><?= h($avatarLetter) ?></div>
      </div>
    </div>
  </header>

  <div class="admin-content">
    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type'] === 'error' ? 'error' : 'success') ?>">
        <?= h($flash['msg']) ?>
      </div>
    <?php endif; ?>

    <div class="page-header">
      <div>
        <h1 class="page-title">My Profile</h1>
        <p class="page-subtitle">Manage your account settings</p>
      </div>
    </div>

    <div class="profile-card">
      <div class="profile-avatar-lg">
        <?php if ($adminPhoto && file_exists(__DIR__ . '/../' . ltrim($adminPhoto, '/'))): ?>
          <img src="<?= SITE_URL . '/' . h($adminPhoto) ?>" alt="Profile photo">
        <?php else: ?>
          <?= h($avatarLetter) ?>
        <?php endif; ?>
      </div>

      <div>
        <h2 class="legacy-style-1169661891"><?= h($adminFullName) ?></h2>

        <p class="legacy-style-06dd642fcb">
          <?= h($adminEmail) ?> · <?= h($roleLabel) ?>
        </p>

        <?php if ($lastLogin !== ''): ?>
          <p class="legacy-style-b0ed485f66">
            <i class="fa-solid fa-clock"></i>
            Last login: <?= h(date('M j, Y H:i', strtotime($lastLogin))) ?>
          </p>
        <?php endif; ?>
      </div>
    </div>

    <div class="settings-card">
      <div class="tabs-nav" role="tablist">
        <button
          type="button"
          class="tab-btn <?= $activeTab === 'profile' ? 'active' : '' ?>"
          data-tab="profile"
          id="tabBtnProfile"
          role="tab"
          aria-selected="<?= $activeTab === 'profile' ? 'true' : 'false' ?>"
          aria-controls="tabPanelProfile"
          onclick="switchTab('profile')">
          <i class="fa-solid fa-user"></i> <span>Update Profile</span>
        </button>
        <button
          type="button"
          class="tab-btn <?= $activeTab === 'password' ? 'active' : '' ?>"
          data-tab="password"
          id="tabBtnPassword"
          role="tab"
          aria-selected="<?= $activeTab === 'password' ? 'true' : 'false' ?>"
          aria-controls="tabPanelPassword"
          onclick="switchTab('password')">
          <i class="fa-solid fa-lock"></i> <span>Change Password</span>
        </button>
      </div>

      <div class="tab-panels">
        <div class="tab-panel <?= $activeTab === 'profile' ? 'active' : '' ?>" id="tabPanelProfile" role="tabpanel" aria-labelledby="tabBtnProfile">
          <form method="POST" action="includes/process-profile.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update_profile">

            <div class="card-body">
              <div class="form-grid">
                <div class="form-group">
                  <label>Full Name</label>
                  <input type="text" name="full_name" class="form-control" value="<?= h($adminFullName) ?>" required>
                </div>

                <div class="form-group">
                  <label>Email</label>
                  <input type="email" name="email" class="form-control" value="<?= h($adminEmail) ?>" required>
                </div>

                <div class="form-group full-width">
                  <label>Profile Photo</label>
                  <input type="file" name="photo" class="form-control" accept="image/*" onchange="previewImg(this,'prev_photo')">
                  <img id="prev_photo" class="img-preview circle legacy-style-66253c00ea" alt="Preview">
                </div>
              </div>
            </div>

            <div class="modal-footer">
              <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-save"></i> Update Profile
              </button>
            </div>
          </form>
        </div>

        <div class="tab-panel <?= $activeTab === 'password' ? 'active' : '' ?>" id="tabPanelPassword" role="tabpanel" aria-labelledby="tabBtnPassword">
          <form method="POST" action="includes/process-profile.php" autocomplete="off">
            <input type="hidden" name="action" value="change_password">

            <div class="card-body">
              <div class="form-grid">
                <div class="form-group full-width">
                  <label>Current Password</label>
                  <div class="password-wrap">
                    <input type="password" name="current_password" id="current_password" class="form-control" required autocomplete="current-password">
                    <button type="button" class="password-toggle" onclick="togglePassword('current_password', this)">
                      <i class="fa-solid fa-eye"></i>
                    </button>
                  </div>
                </div>

                <div class="form-group">
                  <label>
                    New Password
                    <span class="legacy-style-d04e38ecf5">(min 8 chars)</span>
                  </label>
                  <div class="password-wrap">
                    <input type="password" name="new_password" id="new_password" class="form-control" required minlength="8" autocomplete="new-password">
                    <button type="button" class="password-toggle" onclick="togglePassword('new_password', this)">
                      <i class="fa-solid fa-eye"></i>
                    </button>
                  </div>
                </div>

                <div class="form-group">
                  <label>Confirm New Password</label>
                  <div class="password-wrap">
                    <input type="password" name="confirm_password" id="confirm_password" class="form-control" required minlength="8" autocomplete="new-password">
                    <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', this)">
                      <i class="fa-solid fa-eye"></i>
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <div class="modal-footer">
              <button type="submit" class="btn btn-danger">
                <i class="fa-solid fa-lock"></i> Change Password
              </button>
            </div>
          </form>
        </div>
      </div>
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

function previewImg(input, id) {
  const preview = document.getElementById(id);

  if (!preview || !input.files || !input.files[0]) return;

  const reader = new FileReader();

  reader.onload = e => {
    preview.src = e.target.result;
    preview.style.display = 'block';
  };

  reader.readAsDataURL(input.files[0]);
}

function togglePassword(inputId, btn) {
  const input = document.getElementById(inputId);
  const icon = btn.querySelector('i');

  if (!input || !icon) return;

  if (input.type === 'password') {
    input.type = 'text';
    icon.classList.remove('fa-eye');
    icon.classList.add('fa-eye-slash');
  } else {
    input.type = 'password';
    icon.classList.remove('fa-eye-slash');
    icon.classList.add('fa-eye');
  }
}

function switchTab(tab) {
  document.querySelectorAll('.tab-btn').forEach(btn => {
    const isActive = btn.dataset.tab === tab;
    btn.classList.toggle('active', isActive);
    btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
  });

  document.querySelectorAll('.tab-panel').forEach(panel => {
    panel.classList.toggle('active', panel.id === 'tabPanel' + tab.charAt(0).toUpperCase() + tab.slice(1));
  });

  if (history.replaceState) {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    history.replaceState(null, '', url);
  }
}

// Respect a ?tab=password link (e.g. from another page) on load.
(function(){
  const params = new URLSearchParams(window.location.search);
  const requested = params.get('tab');
  if (requested === 'profile' || requested === 'password') {
    switchTab(requested);
  }
})();
</script>

</body>
</html>
