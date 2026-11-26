<?php
declare(strict_types=1);

/**
 * Admin login – second factor (email OTP).
 *
 * Handles three POST actions from admin/verify-otp.php:
 *   - verify (default): check the 6-digit code and complete the login
 *   - resend:           email a fresh code (rate-limited to once per 60s)
 *   - cancel:           abandon the pending login and return to login.php
 *
 * CSRF is enforced globally by includes/config.php for every POST.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/mail-function.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const OTP_TTL_SECONDS     = 600;
const OTP_MAX_ATTEMPTS    = 5;
const OTP_RESEND_COOLDOWN = 60;

/* --------------------------------------------------------------------------
   Helpers
   -------------------------------------------------------------------------- */

function otp_redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function otp_back(string $msg): void
{
    $_SESSION['otp_error'] = $msg;
    otp_redirect('../verify-otp');
}

function otp_clear_pending(): void
{
    unset(
        $_SESSION['admin_otp_pending'],
        $_SESSION['admin_otp_hash'],
        $_SESSION['admin_otp_expires_at'],
        $_SESSION['admin_otp_attempts'],
        $_SESSION['admin_otp_last_sent_at'],
        $_SESSION['admin_otp_redirect']
    );
}

function otp_to_login(string $msg): void
{
    otp_clear_pending();
    $_SESSION['login_error'] = $msg;
    otp_redirect('../login');
}

function otp_send_code(array $pending): bool
{
    $email = trim((string)($pending['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $otp = (string)random_int(100000, 999999);

    $name    = htmlspecialchars((string)($pending['full_name'] ?? 'Admin'), ENT_QUOTES, 'UTF-8');
    $subject = 'Your admin login verification code';
    $content = "<h3>Admin Login Verification</h3>"
        . "<p>Hello {$name},</p>"
        . "<p>Use this verification code to complete your sign in:</p>"
        . "<div class='cred-box'><div class='password'>{$otp}</div>"
        . "<p>This code expires in <strong>10 minutes</strong>.</p></div>"
        . "<div class='warning'>If you did not attempt to sign in, you can ignore this email.</div>";
    $body = function_exists('email_wrapper') ? email_wrapper($content) : $content;
    $alt  = "Your admin login verification code is {$otp}. It expires in 10 minutes.";

    try {
        $sent = sendEmail($email, $subject, $body, $alt) === true;
    } catch (Throwable $e) {
        error_log('process-otp: resend failed: ' . $e->getMessage());
        $sent = false;
    }

    // Only replace the active code once the new one has actually been delivered,
    // so a failed resend does not invalidate the code already in the user's inbox.
    if ($sent) {
        $_SESSION['admin_otp_hash']         = password_hash($otp, PASSWORD_DEFAULT);
        $_SESSION['admin_otp_expires_at']   = time() + OTP_TTL_SECONDS;
        $_SESSION['admin_otp_attempts']     = 0;
        $_SESSION['admin_otp_last_sent_at'] = time();
    }
    return $sent;
}

function otp_safe_redirect(string $redirect): ?string
{
    $redirect = trim($redirect);
    if ($redirect === ''
        || !preg_match('#^[A-Za-z0-9_./?=&%-]+$#', $redirect)
        || str_contains($redirect, '://')
        || str_starts_with($redirect, '//')
        || str_contains($redirect, '..')) {
        return null;
    }
    return '../' . ltrim($redirect, '/');
}

/* --------------------------------------------------------------------------
   Request guards
   -------------------------------------------------------------------------- */

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    otp_redirect('../verify-otp');
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    error_log('process-otp: database connection not available.');
    otp_back('Unable to verify right now. Please try again shortly.');
}

$pending = $_SESSION['admin_otp_pending'] ?? null;
if (!is_array($pending) || empty($pending['id'])) {
    $_SESSION['login_error'] = 'Your verification session has expired. Please sign in again.';
    otp_redirect('../login');
}

$action = strtolower(trim((string)($_POST['action'] ?? 'verify')));

/* --------------------------------------------------------------------------
   Cancel
   -------------------------------------------------------------------------- */

if ($action === 'cancel') {
    otp_clear_pending();
    otp_redirect('../login');
}

/* --------------------------------------------------------------------------
   Resend
   -------------------------------------------------------------------------- */

if ($action === 'resend') {
    $lastSent = (int)($_SESSION['admin_otp_last_sent_at'] ?? 0);
    $wait = OTP_RESEND_COOLDOWN - (time() - $lastSent);
    if ($lastSent > 0 && $wait > 0) {
        otp_back("Please wait {$wait} second(s) before requesting another code.");
    }
    if (!otp_send_code($pending)) {
        otp_back('We could not resend the verification code. Please try again.');
    }
    unset($_SESSION['otp_error']);
    $_SESSION['otp_success'] = 'A new verification code has been sent.';
    otp_redirect('../verify-otp');
}

/* --------------------------------------------------------------------------
   Verify
   -------------------------------------------------------------------------- */

$expiresAt = (int)($_SESSION['admin_otp_expires_at'] ?? 0);
if ($expiresAt <= time()) {
    otp_to_login('Your verification code has expired. Please sign in again.');
}

$attempts = (int)($_SESSION['admin_otp_attempts'] ?? 0);
if ($attempts >= OTP_MAX_ATTEMPTS) {
    otp_to_login('Too many incorrect verification attempts. Please sign in again.');
}

$otp = (string)preg_replace('/\D+/', '', (string)($_POST['otp'] ?? ''));
if (strlen($otp) !== 6) {
    otp_back('Enter the 6-digit verification code.');
}

$hash = (string)($_SESSION['admin_otp_hash'] ?? '');
if ($hash === '' || !password_verify($otp, $hash)) {
    $attempts++;
    $_SESSION['admin_otp_attempts'] = $attempts;
    $remaining = OTP_MAX_ATTEMPTS - $attempts;
    if ($remaining <= 0) {
        otp_to_login('Too many incorrect verification attempts. Please sign in again.');
    }
    otp_back("Incorrect verification code. {$remaining} attempt(s) remaining.");
}

$adminId = (int)$pending['id'];

// Re-check the account is still active (PHP 8.1+ mysqli throws on failure).
try {
    $stmt = $conn->prepare('SELECT id, status FROM admin_users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (Throwable $e) {
    error_log('process-otp: account lookup failed: ' . $e->getMessage());
    otp_back('Unable to verify your account right now. Please try again.');
}

if (!$current || !in_array(strtolower((string)($current['status'] ?? '')), ['1', 'active'], true)) {
    otp_to_login('Your account is no longer active.');
}

$redirect = otp_safe_redirect((string)($_SESSION['admin_otp_redirect'] ?? ''));

// Complete the login.
otp_clear_pending();
session_regenerate_id(true);

$_SESSION['admin_id']                     = $adminId;
$_SESSION['admin_name']                   = (string)($pending['full_name'] ?? '');
$_SESSION['admin_username']               = (string)($pending['username'] ?? '');
$_SESSION['admin_email']                  = (string)($pending['email'] ?? '');
$_SESSION['admin_role']                   = (string)($pending['role'] ?? '');
$_SESSION['admin_can_manage_permissions'] = (int)($pending['can_manage_permissions'] ?? 0);
$_SESSION['admin_otp_verified_at']        = time();
// Reset the idle-timeout clock so a stale value from an earlier session
// does not immediately log the admin out again in auth.php.
$_SESSION['admin_last_activity']          = time();
unset($_SESSION['login_error'], $_SESSION['otp_error'], $_SESSION['otp_success']);

// Recording last_login is best-effort; never block a successful login on it.
try {
    $stmt = $conn->prepare('UPDATE admin_users SET last_login = NOW() WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $stmt->close();
} catch (Throwable $e) {
    error_log('process-otp: last_login update failed: ' . $e->getMessage());
}

session_write_close();
otp_redirect($redirect ?? '../');
