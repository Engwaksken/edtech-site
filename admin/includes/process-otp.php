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

function otp_back(string $msg): void {
    $_SESSION['otp_error'] = $msg;
    header('Location: ../verify-otp.php');
    exit;
}

function otp_clear_pending(): void {
    unset(
        $_SESSION['admin_otp_pending'],
        $_SESSION['admin_otp_hash'],
        $_SESSION['admin_otp_expires_at'],
        $_SESSION['admin_otp_attempts'],
        $_SESSION['admin_otp_last_sent_at'],
        $_SESSION['admin_otp_redirect']
    );
}

function otp_send_code(array $pending): bool {
    $email = trim((string)($pending['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

    $otp = (string)random_int(100000, 999999);
    $_SESSION['admin_otp_hash'] = password_hash($otp, PASSWORD_DEFAULT);
    $_SESSION['admin_otp_expires_at'] = time() + 600;
    $_SESSION['admin_otp_attempts'] = 0;
    $_SESSION['admin_otp_last_sent_at'] = time();

    $name = htmlspecialchars((string)($pending['full_name'] ?? 'Admin'), ENT_QUOTES, 'UTF-8');
    $subject = 'Your admin login verification code';
    $content = "<h3>Admin Login Verification</h3><p>Hello {$name},</p><p>Use this verification code to complete your sign in:</p><div class='cred-box'><div class='password'>{$otp}</div><p>This code expires in <strong>10 minutes</strong>.</p></div>";
    $body = function_exists('email_wrapper') ? email_wrapper($content) : $content;
    $alt = "Your admin login verification code is {$otp}. It expires in 10 minutes.";
    return sendEmail($email, $subject, $body, $alt) === true;
}

$pending = $_SESSION['admin_otp_pending'] ?? null;
if (!is_array($pending) || empty($pending['id'])) {
    $_SESSION['login_error'] = 'Your verification session has expired. Please sign in again.';
    header('Location: ../login.php');
    exit;
}

$action = trim((string)($_POST['action'] ?? $_GET['action'] ?? 'verify'));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Please use the verification form.');
}

if ($action === 'cancel') {
    otp_clear_pending();
    header('Location: ../login.php');
    exit;
}

if ($action === 'resend') {
    $lastSent = (int)($_SESSION['admin_otp_last_sent_at'] ?? 0);
    if ($lastSent > 0 && (time() - $lastSent) < 60) {
        otp_back('Please wait at least 60 seconds before requesting another code.');
    }
    if (!otp_send_code($pending)) {
        otp_back('We could not resend the verification code. Please try again.');
    }
    $_SESSION['otp_error'] = '';
    $_SESSION['otp_success'] = 'A new verification code has been sent.';
    header('Location: ../verify-otp.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    otp_back('Invalid request.');
}

$expiresAt = (int)($_SESSION['admin_otp_expires_at'] ?? 0);
if ($expiresAt <= time()) {
    otp_clear_pending();
    $_SESSION['login_error'] = 'Your verification code has expired. Please sign in again.';
    header('Location: ../login.php');
    exit;
}

$attempts = (int)($_SESSION['admin_otp_attempts'] ?? 0);
if ($attempts >= 5) {
    otp_clear_pending();
    $_SESSION['login_error'] = 'Too many incorrect verification attempts. Please sign in again.';
    header('Location: ../login.php');
    exit;
}

$otp = preg_replace('/\D+/', '', (string)($_POST['otp'] ?? ''));
if (strlen($otp) !== 6) {
    otp_back('Enter the 6-digit verification code.');
}

$hash = (string)($_SESSION['admin_otp_hash'] ?? '');
if ($hash === '' || !password_verify($otp, $hash)) {
    $_SESSION['admin_otp_attempts'] = $attempts + 1;
    $remaining = max(0, 5 - ($attempts + 1));
    otp_back('Incorrect verification code.' . ($remaining > 0 ? " {$remaining} attempt(s) remaining." : ''));
}

$adminId = (int)$pending['id'];
$stmt = $conn->prepare("SELECT id, status FROM admin_users WHERE id = ? LIMIT 1");
if (!$stmt) otp_back('Unable to verify account status.');
$stmt->bind_param('i', $adminId);
$stmt->execute();
$current = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$current || !in_array(strtolower((string)($current['status'] ?? '')), ['1', 'active'], true)) {
    otp_clear_pending();
    $_SESSION['login_error'] = 'Your account is no longer active.';
    header('Location: ../login.php');
    exit;
}

$redirect = trim((string)($_SESSION['admin_otp_redirect'] ?? ''));

session_regenerate_id(true);
$_SESSION['admin_id'] = $adminId;
$_SESSION['admin_name'] = (string)$pending['full_name'];
$_SESSION['admin_username'] = (string)$pending['username'];
$_SESSION['admin_email'] = (string)$pending['email'];
$_SESSION['admin_role'] = (string)$pending['role'];
$_SESSION['admin_can_manage_permissions'] = (int)$pending['can_manage_permissions'];
$_SESSION['admin_otp_verified_at'] = time();

otp_clear_pending();

$stmt = $conn->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $stmt->close();
}

if ($redirect !== '' && preg_match('#^[A-Za-z0-9_./?=&%-]+$#', $redirect) && !str_contains($redirect, '://') && !str_starts_with($redirect, '//')) {
    header('Location: ../' . ltrim($redirect, '/'));
    exit;
}

header('Location: ../index.php');
exit;
