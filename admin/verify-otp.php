<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection error.');
}
$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

function admin_asset_url(?string $path, string $fallback = ''): string
{
    $path = trim((string)$path);
    if ($path === '') return $fallback;
    if (preg_match('/^https?:\/\//i', $path)) return $path;
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#^\.\./#', '', $path);
    $path = ltrim($path, '/');
    return (defined('SITE_URL') && SITE_URL !== '')
        ? rtrim(SITE_URL, '/') . '/' . $path
        : '../' . $path;
}

function admin_get_setting(mysqli $conn, string $key, string $default = ''): string
{
    $stmt = $conn->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1");
    if (!$stmt) return $default;
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return trim((string)($row['setting_value'] ?? $default));
}

$pending = $_SESSION['admin_otp_pending'] ?? null;
if (!is_array($pending) || empty($pending['id']) || empty($_SESSION['admin_otp_hash'])) {
    $_SESSION['login_error'] = 'Your verification session has expired. Please sign in again.';
    header('Location: login.php');
    exit;
}

$expiresAt = (int)($_SESSION['admin_otp_expires_at'] ?? 0);
if ($expiresAt <= time()) {
    unset($_SESSION['admin_otp_pending'], $_SESSION['admin_otp_hash'], $_SESSION['admin_otp_expires_at'], $_SESSION['admin_otp_attempts']);
    $_SESSION['login_error'] = 'Your verification code has expired. Please sign in again.';
    header('Location: login.php');
    exit;
}

$site_name = admin_get_setting($conn, 'site_name', 'Admin Panel');
$logo_db = admin_get_setting($conn, 'site_logo', '');
$favicon_db = admin_get_setting($conn, 'site_favicon', '');
$site_logo_url = admin_asset_url($logo_db);
$site_favicon_url = admin_asset_url($favicon_db);
$error = $_SESSION['otp_error'] ?? '';
$success = $_SESSION['otp_success'] ?? '';
unset($_SESSION['otp_error'], $_SESSION['otp_success']);
$maskedEmail = preg_replace('/(^.).*(@.*$)/', '$1***$2', (string)($pending['email'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Verify OTP - <?= h($site_name) ?></title>
  <?php if ($site_favicon_url !== ''): ?><link rel="icon" href="<?= h($site_favicon_url) ?>"><?php endif; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="assets/css/admin.css?v=<?= (int)@filemtime(__DIR__ . '/assets/css/admin.css') ?>">
  <style>
    .otp-input{letter-spacing:.55rem;text-align:center;font-size:1.7rem;font-weight:700}
    .otp-meta{text-align:center;color:#6b7280;font-size:13px;margin:8px 0 20px}
    .otp-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:14px}
    .otp-actions form{margin:0}
  </style>
</head>
<body class="admin-login-page">
<div class="login-wrap">
  <div class="login-card">
    <div class="login-logo">
      <?php if ($site_logo_url !== ''): ?>
        <img src="<?= h($site_logo_url) ?>" alt="<?= h($site_name) ?>" class="login-site-logo" loading="eager" onerror="this.style.display='none';document.getElementById('fallbackLogo').style.display='flex';">
        <div class="login-logo-icon legacy-style-c8be1ccba6" id="fallbackLogo" style="display:none"><i class="fa fa-shield-alt"></i></div>
      <?php else: ?>
        <div class="login-logo-icon"><i class="fa fa-shield-alt"></i></div>
      <?php endif; ?>
      <p class="subtitle">Verify your sign in</p>
    </div>

    <?php if ($error !== ''): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>

    <p class="otp-meta">We sent a 6-digit verification code to <strong><?= h($maskedEmail) ?></strong>. The code expires in 10 minutes.</p>

    <form method="POST" action="includes/process-otp.php" autocomplete="one-time-code">
      <div class="form-group">
        <label for="otp">Verification Code</label>
        <input type="text" id="otp" name="otp" class="form-control otp-input" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required autofocus autocomplete="one-time-code">
      </div>
      <button type="submit" class="btn btn-primary legacy-style-74008003b2"><i class="fa fa-check-circle"></i> Verify &amp; Continue</button>
    </form>

    <div class="otp-actions">
      <form method="POST" action="includes/process-otp.php">
        <input type="hidden" name="action" value="resend">
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fa fa-redo"></i> Resend Code</button>
      </form>
      <form method="POST" action="includes/process-otp.php"><input type="hidden" name="action" value="cancel"><button type="submit" class="btn btn-secondary btn-sm"><i class="fa fa-arrow-left"></i> Back to Login</button></form>
    </div>
  </div>
</div>
<script>
const otp = document.getElementById('otp');
otp?.addEventListener('input', () => { otp.value = otp.value.replace(/\D/g, '').slice(0, 6); });
</script>
<script src="assets/js/admin.js"></script>
</body>
</html>
