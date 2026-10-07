<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/mail-function.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

function back_login(string $msg): void {
    $_SESSION['login_error'] = $msg;
    header('Location: ../login.php');
    exit;
}

function is_active_value($value): bool {
    return in_array(strtolower((string)$value), ['1', 'active'], true);
}

function role_variants(string $role): array {
    $role = strtolower(trim($role));
    return array_values(array_unique([
        $role,
        str_replace('_', '-', $role),
        str_replace('-', '_', $role)
    ]));
}

function clear_pending_admin_otp(): void {
    unset(
        $_SESSION['admin_otp_pending'],
        $_SESSION['admin_otp_hash'],
        $_SESSION['admin_otp_expires_at'],
        $_SESSION['admin_otp_attempts'],
        $_SESSION['admin_otp_last_sent_at'],
        $_SESSION['admin_otp_redirect']
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back_login('Invalid request.');
}

$login    = trim((string)($_POST['username'] ?? $_POST['email'] ?? ''));
site_require_csrf();
$password = (string)($_POST['password'] ?? '');
$redirect = trim((string)($_POST['redirect'] ?? ''));

if ($login === '' || $password === '') {
    back_login('Enter username/email and password.');
}

$stmt = $conn->prepare("\n    SELECT id, full_name, username, email, password, role, status, can_manage_permissions\n    FROM admin_users\n    WHERE username = ? OR email = ?\n    LIMIT 1\n");
if (!$stmt) {
    back_login('Unable to process login right now.');
}
$stmt->bind_param('ss', $login, $login);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || !password_verify($password, (string)$user['password'])) {
    back_login('Invalid login details.');
}

if (!is_active_value($user['status'] ?? '')) {
    back_login('Your account is inactive.');
}

$email = trim((string)($user['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    back_login('Your account does not have a valid email address for OTP verification. Contact administrator.');
}

$role = strtolower(trim((string)($user['role'] ?? '')));
$roleList = role_variants($role);
$placeholders = implode(',', array_fill(0, count($roleList), '?'));
$types = str_repeat('s', count($roleList));

$stmt = $conn->prepare("\n    SELECT role_key, status, can_manage_permissions\n    FROM roles\n    WHERE role_key IN ($placeholders)\n    LIMIT 1\n");
if (!$stmt) {
    back_login('Unable to validate your role.');
}
$stmt->bind_param($types, ...$roleList);
$stmt->execute();
$roleRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$roleRow) {
    back_login('Your role was not found. Contact administrator.');
}

if (!is_active_value($roleRow['status'] ?? '')) {
    back_login('Your role is inactive. Contact administrator.');
}

clear_pending_admin_otp();

$otp = (string)random_int(100000, 999999);
$otpHash = password_hash($otp, PASSWORD_DEFAULT);
$expiresAt = time() + 600; // 10 minutes

$_SESSION['admin_otp_pending'] = [
    'id' => (int)$user['id'],
    'full_name' => (string)$user['full_name'],
    'username' => (string)$user['username'],
    'email' => $email,
    'role' => strtolower(trim((string)$roleRow['role_key'])),
    'can_manage_permissions' => (
        (int)($user['can_manage_permissions'] ?? 0) === 1 ||
        (int)($roleRow['can_manage_permissions'] ?? 0) === 1
    ) ? 1 : 0,
];
$_SESSION['admin_otp_hash'] = $otpHash;
$_SESSION['admin_otp_expires_at'] = $expiresAt;
$_SESSION['admin_otp_attempts'] = 0;
$_SESSION['admin_otp_last_sent_at'] = time();
$_SESSION['admin_otp_redirect'] = $redirect;

$subject = 'Your admin login verification code';
$content = "\n    <h3>Admin Login Verification</h3>\n    <p>Hello " . htmlspecialchars((string)$user['full_name'], ENT_QUOTES, 'UTF-8') . ",</p>\n    <p>Use the verification code below to complete your sign in.</p>\n    <div class='cred-box'>\n        <h4>Your one-time verification code</h4>\n        <div class='password'>{$otp}</div>\n        <p>This code expires in <strong>10 minutes</strong>.</p>\n    </div>\n    <div class='warning'>\n        If you did not attempt to sign in, you can ignore this email.\n    </div>\n";
$body = function_exists('email_wrapper') ? email_wrapper($content) : $content;
$altBody = "Hello {$user['full_name']},\n\nYour admin login verification code is: {$otp}\n\nThis code expires in 10 minutes.\n\nIf you did not attempt to sign in, ignore this email.";

$mailResult = sendEmail($email, $subject, $body, $altBody);
if ($mailResult !== true) {
    clear_pending_admin_otp();
    back_login('We could not send your verification code. Please try again.');
}

header('Location: ../verify-otp.php');
exit;
