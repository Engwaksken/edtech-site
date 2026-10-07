<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function cp_back(): void
{
    header('Location: ../change-password.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cp_back();
}

if (empty($_SESSION['venture_login']) || empty($_SESSION['venture_access_id'])) {
    header('Location: ../login.php');
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    $_SESSION['cp_error'] = 'Database connection error.';
    cp_back();
}

$conn->set_charset('utf8mb4');

$access_id  = (int)$_SESSION['venture_access_id'];
site_require_csrf();
$venture_id = (int)($_SESSION['venture_id'] ?? 0);

$current = (string)($_POST['current_password'] ?? '');
$new     = (string)($_POST['new_password'] ?? '');
$confirm = (string)($_POST['confirm_password'] ?? '');

if ($current === '' || $new === '' || $confirm === '') {
    $_SESSION['cp_error'] = 'Please fill in all password fields.';
    cp_back();
}

if (strlen($new) < 8) {
    $_SESSION['cp_error'] = 'New password must be at least 8 characters.';
    cp_back();
}

if ($new !== $confirm) {
    $_SESSION['cp_error'] = 'New password and confirm password do not match.';
    cp_back();
}

$stmt = $conn->prepare("
    SELECT id, password_hash
    FROM venture_portal_access
    WHERE id = ?
      AND venture_id = ?
      AND is_active = 1
    LIMIT 1
");

if (!$stmt) {
    $_SESSION['cp_error'] = 'Password check failed.';
    cp_back();
}

$stmt->bind_param('ii', $access_id, $venture_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    $_SESSION['cp_error'] = 'Portal account not found or inactive.';
    cp_back();
}

if (!password_verify($current, (string)$user['password_hash'])) {
    $_SESSION['cp_error'] = 'Current password is incorrect.';
    cp_back();
}

$new_hash = password_hash($new, PASSWORD_DEFAULT);

$update = $conn->prepare("
    UPDATE venture_portal_access
    SET password_hash = ?,
        force_password_change = 0
    WHERE id = ?
      AND venture_id = ?
    LIMIT 1
");

if (!$update) {
    $_SESSION['cp_error'] = 'Password update failed.';
    cp_back();
}

$update->bind_param('sii', $new_hash, $access_id, $venture_id);

if ($update->execute()) {

    // Destroy current session so user logs in again
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {

        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();

    session_start();

    $_SESSION['login_success'] =
        'Password changed successfully. Please sign in again.';

    header('Location: ../login.php');

    exit;

} else {

    $_SESSION['cp_error'] = 'Unable to update password.';

    $update->close();

    cp_back();
}
