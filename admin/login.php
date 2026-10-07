<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/process-login-view.php';

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

function admin_asset_url(?string $path, string $fallback = ''): string
{
    $path = trim((string)$path);

    if ($path === '') {
        return $fallback;
    }

    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#^\.\./#', '', $path);
    $path = ltrim($path, '/');

    if (defined('SITE_URL') && SITE_URL !== '') {
        return asset_url($path);
    }

    return '../' . $path;
}

function admin_get_setting(mysqli $conn, string $key, string $default = ''): string
{
    $stmt = $conn->prepare("\n        SELECT setting_value\n        FROM site_settings\n        WHERE setting_key = ?\n        LIMIT 1\n    ");

    if (!$stmt) {
        return $default;
    }

    $stmt->bind_param('s', $key);
    $stmt->execute();

    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return trim((string)($row['setting_value'] ?? $default));
}

$site_name_db = admin_get_setting($conn, 'site_name', $site_name ?? 'Admin Panel');
$logo_db      = admin_get_setting($conn, 'site_logo', '');
$favicon_db   = admin_get_setting($conn, 'site_favicon', '');

$site_name        = $site_name_db;
$site_logo_url    = admin_asset_url($logo_db);
$site_favicon_url = $favicon_db !== '' ? admin_asset_url($favicon_db) : SITE_URL . '/assets/images/favicon.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Login - <?= h($site_name) ?></title>

  <?php if ($site_favicon_url !== ''): ?>
    <link rel="icon" href="<?= h($site_favicon_url) ?>">
  <?php endif; ?>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= h(asset_url('assets/vendor/fontawesome/css/all.min.css')) ?>">
  <link rel="stylesheet" href="assets/css/admin.css?v=<?= (int)@filemtime(__DIR__ . '/assets/css/admin.css') ?>">
</head>

<body class="admin-login-page">
<div class="login-wrap">
  <div class="login-card">

    <div class="login-logo">
      <?php if ($site_logo_url !== ''): ?>
        <img
          src="<?= h($site_logo_url) ?>"
          alt="<?= h($site_name) ?>"
          class="login-site-logo"
          loading="eager"
          onerror="this.style.display='none';document.getElementById('fallbackLogo').style.display='flex';"
        >

        <div class="login-logo-icon legacy-style-c8be1ccba6" id="fallbackLogo" style="display:none">
          <i class="fa fa-graduation-cap"></i>
        </div>
      <?php else: ?>
        <div class="login-logo-icon">
          <i class="fa fa-graduation-cap"></i>
        </div>
      <?php endif; ?>

      <p class="subtitle">Sign in to manage the fellowship platform</p>
    </div>

    <?php if (!empty($error)): ?>
      <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="includes/process-login.php" autocomplete="on">
      <input type="hidden" name="site_csrf_token" value="<?= h(site_csrf_token()) ?>">
      <input type="hidden" name="redirect" value="<?= h($redirect ?? '') ?>">

      <div class="form-group legacy-style-87c136dfd0">
        <label for="username">Username or Email</label>
        <input
          type="text"
          id="username"
          name="username"
          class="form-control"
          placeholder="admin@example.com"
          value="<?= h($old_username ?? '') ?>"
          autocomplete="username"
          required
          autofocus
        >
      </div>

      <div class="form-group legacy-style-8677744d08">
        <label for="password">Password</label>

        <div class="legacy-style-d461c96de5">
          <input
            type="password"
            id="password"
            name="password"
            class="form-control legacy-style-f69c0b61c6"
            placeholder="Password"
            autocomplete="current-password"
            required
          >

          <button
            type="button"
            onclick="togglePwd()"
            aria-label="Show or hide password"
            class="legacy-style-a62cc2b1bc"
          >
            <i class="fa fa-eye" id="pwdIcon"></i>
          </button>
        </div>
      </div>

      <button type="submit" class="btn btn-primary legacy-style-74008003b2">
        <i class="fa fa-sign-in-alt"></i> Continue
      </button>
    </form>

    <p class="legacy-style-597cef8b33">
      <a href="<?= h(defined('SITE_URL') ? SITE_URL : '../') ?>">
        <i class="fa fa-arrow-left"></i> Back to website
      </a>
    </p>
  </div>
</div>

<script>
function togglePwd() {
  const pwd = document.getElementById('password');
  const icon = document.getElementById('pwdIcon');

  if (!pwd || !icon) return;

  if (pwd.type === 'password') {
    pwd.type = 'text';
    icon.classList.remove('fa-eye');
    icon.classList.add('fa-eye-slash');
  } else {
    pwd.type = 'password';
    icon.classList.remove('fa-eye-slash');
    icon.classList.add('fa-eye');
  }
}
</script>

<script src="assets/js/admin.js"></script>
</body>
</html>
