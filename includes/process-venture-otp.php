<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mail-function.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    $_SESSION['otp_error'] = 'Database connection error.';
    header('Location: ../verify-otp');
    exit;
}

$conn->set_charset('utf8mb4');

function vp_otp_redirect(): void
{
    header('Location: ../verify-otp');
    exit;
}

function vp_otp_login_redirect(): void
{
    header('Location: ../login');
    exit;
}

function vp_otp_safe_redirect(string $redirect): string
{
    $redirect = trim($redirect);

    if ($redirect === '') {
        return '../dashboard';
    }

    if (preg_match('#^https?://#i', $redirect)) {
        return '../dashboard';
    }

    $redirect = ltrim($redirect, '/');

    if (
        $redirect === '' ||
        str_contains($redirect, '..') ||
        str_starts_with($redirect, '//') ||
        preg_match('/[\r\n]/', $redirect)
    ) {
        return '../dashboard';
    }

    return '../' . $redirect;
}

function vp_otp_mask_email(string $email): string
{
    if (!str_contains($email, '@')) return $email;

    [$name, $domain] = explode('@', $email, 2);
    $len = strlen($name);

    if ($len <= 2) {
        $name = substr($name, 0, 1) . '*';
    } else {
        $name = substr($name, 0, 2) . str_repeat('*', max(1, $len - 2));
    }

    return $name . '@' . $domain;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['otp_error'] = 'Invalid request.';
    vp_otp_redirect();
}

$pending = $_SESSION['venture_otp_pending'] ?? null;

if (!is_array($pending) || empty($pending['access_id']) || empty($pending['login_email'])) {
    $_SESSION['login_error'] = 'Your verification session has expired. Please sign in again.';
    unset($_SESSION['venture_otp_pending']);
    vp_otp_login_redirect();
}

$action = trim((string)($_POST['action'] ?? 'verify'));

if ($action === 'resend') {
    $lastSent = (int)($pending['last_sent_at'] ?? 0);

    if ((time() - $lastSent) < 60) {
        $wait = max(1, 60 - (time() - $lastSent));
        $_SESSION['otp_error'] = "Please wait {$wait} seconds before requesting another code.";
        vp_otp_redirect();
    }

    $accessId = (int)$pending['access_id'];

    $st = $conn->prepare("
        SELECT
            vpa.id AS access_id,
            vpa.venture_id,
            vpa.email AS login_email,
            vpa.is_active,
            vpa.force_password_change,
            v.id AS venture_real_id,
            v.cohort_id,
            v.name AS venture_name,
            v.email AS venture_primary_email,
            v.slug,
            v.logo,
            v.status AS venture_status,
            v.stage,
            v.sector,
            v.country
        FROM venture_portal_access vpa
        INNER JOIN ventures v ON v.id = vpa.venture_id
        WHERE vpa.id = ?
        LIMIT 1
    ");

    if (!$st) {
        $_SESSION['otp_error'] = 'Could not prepare verification request.';
        vp_otp_redirect();
    }

    $st->bind_param('i', $accessId);
    $st->execute();
    $user = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$user || (int)$user['is_active'] !== 1) {
        unset($_SESSION['venture_otp_pending']);
        $_SESSION['login_error'] = 'Your portal access is inactive. Please contact the programme team.';
        vp_otp_login_redirect();
    }

    $ventureStatus = strtolower(trim((string)($user['venture_status'] ?? '')));

    if ($ventureStatus !== '' && !in_array($ventureStatus, ['active', 'approved'], true)) {
        unset($_SESSION['venture_otp_pending']);
        $_SESSION['login_error'] = 'Your venture account is not active.';
        vp_otp_login_redirect();
    }

    $otp      = (string)random_int(100000, 999999);
    $otpHash  = password_hash($otp, PASSWORD_DEFAULT);
    $expires  = time() + 600;

    $_SESSION['venture_otp_pending']['venture_id']            = (int)$user['venture_id'];
    $_SESSION['venture_otp_pending']['venture_name']          = (string)$user['venture_name'];
    $_SESSION['venture_otp_pending']['login_email']           = (string)$user['login_email'];
    $_SESSION['venture_otp_pending']['venture_primary_email'] = (string)$user['venture_primary_email'];
    $_SESSION['venture_otp_pending']['logo']                  = (string)($user['logo'] ?? '');
    $_SESSION['venture_otp_pending']['slug']                  = (string)($user['slug'] ?? '');
    $_SESSION['venture_otp_pending']['venture_status']        = (string)($user['venture_status'] ?? '');
    $_SESSION['venture_otp_pending']['stage']                 = (string)($user['stage'] ?? '');
    $_SESSION['venture_otp_pending']['sector']                = (string)($user['sector'] ?? '');
    $_SESSION['venture_otp_pending']['country']               = (string)($user['country'] ?? '');
    $_SESSION['venture_otp_pending']['cohort_id']             = (int)($user['cohort_id'] ?? 0);
    $_SESSION['venture_otp_pending']['force_password_change'] = (int)($user['force_password_change'] ?? 0);
    $_SESSION['venture_otp_pending']['otp_hash']              = $otpHash;
    $_SESSION['venture_otp_pending']['expires_at']            = $expires;
    $_SESSION['venture_otp_pending']['attempts']              = 0;
    $_SESSION['venture_otp_pending']['last_sent_at']          = time();

    $programme = function_exists('get_setting')
        ? get_setting($conn, 'site_name', 'Hive Colab')
        : 'Hive Colab';

    $safeName = htmlspecialchars((string)$user['venture_name'], ENT_QUOTES, 'UTF-8');
    $safeOtp  = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');

    $content = "
        <h3>Your new verification code</h3>
        <p>Hello {$safeName},</p>
        <p>Use the new one-time verification code below to complete your sign in:</p>

        <div class='cred-box'>
            <h4>Your verification code</h4>
            <div class='password'>{$safeOtp}</div>
        </div>

        <p>This code expires in <strong>10 minutes</strong>.</p>

        <div class='warning'>
            If you did not request this code, you can ignore this email.
        </div>

        <p>Thank you,<br>" . htmlspecialchars($programme, ENT_QUOTES, 'UTF-8') . "</p>
    ";

    $subject = 'Your new venture portal verification code';
    $body = function_exists('email_wrapper') ? email_wrapper($content) : $content;
    $altBody = "Hello {$user['venture_name']},\n\n"
        . "Your new venture portal verification code is: {$otp}\n\n"
        . "This code expires in 10 minutes.\n\n"
        . $programme;

    $result = sendEmail((string)$user['login_email'], $subject, $body, $altBody);

    if ($result !== true) {
        $_SESSION['otp_error'] = 'We could not resend the verification code. Please try again.';
        vp_otp_redirect();
    }

    $_SESSION['otp_success'] = 'A new verification code was sent to ' . vp_otp_mask_email((string)$user['login_email']) . '.';
    vp_otp_redirect();
}

if ($action !== 'verify') {
    $_SESSION['otp_error'] = 'Invalid verification action.';
    vp_otp_redirect();
}

if ((int)($pending['expires_at'] ?? 0) < time()) {
    unset($_SESSION['venture_otp_pending']);
    $_SESSION['login_error'] = 'Your verification code expired. Please sign in again.';
    vp_otp_login_redirect();
}

$attempts = (int)($pending['attempts'] ?? 0);

if ($attempts >= 5) {
    unset($_SESSION['venture_otp_pending']);
    $_SESSION['login_error'] = 'Too many incorrect verification attempts. Please sign in again.';
    vp_otp_login_redirect();
}

$otp = preg_replace('/\D+/', '', (string)($_POST['otp'] ?? ''));

if (strlen($otp) !== 6) {
    $_SESSION['venture_otp_pending']['attempts'] = $attempts + 1;
    $_SESSION['otp_error'] = 'Please enter the complete 6-digit verification code.';
    vp_otp_redirect();
}

$hash = (string)($pending['otp_hash'] ?? '');

if ($hash === '' || !password_verify($otp, $hash)) {
    $attempts++;
    $_SESSION['venture_otp_pending']['attempts'] = $attempts;

    if ($attempts >= 5) {
        unset($_SESSION['venture_otp_pending']);
        $_SESSION['login_error'] = 'Too many incorrect verification attempts. Please sign in again.';
        vp_otp_login_redirect();
    }

    $remaining = 5 - $attempts;
    $_SESSION['otp_error'] = 'Incorrect verification code. ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' remaining.';
    vp_otp_redirect();
}

$accessId = (int)$pending['access_id'];

$st = $conn->prepare("
    SELECT
        vpa.id AS access_id,
        vpa.venture_id,
        vpa.email AS login_email,
        vpa.is_active,
        vpa.force_password_change,
        v.id AS venture_real_id,
        v.cohort_id,
        v.name AS venture_name,
        v.email AS venture_primary_email,
        v.slug,
        v.logo,
        v.status AS venture_status,
        v.stage,
        v.sector,
        v.country
    FROM venture_portal_access vpa
    INNER JOIN ventures v ON v.id = vpa.venture_id
    WHERE vpa.id = ?
    LIMIT 1
");

if (!$st) {
    $_SESSION['otp_error'] = 'Could not complete verification.';
    vp_otp_redirect();
}

$st->bind_param('i', $accessId);
$st->execute();
$user = $st->get_result()->fetch_assoc();
$st->close();

if (!$user || (int)$user['is_active'] !== 1) {
    unset($_SESSION['venture_otp_pending']);
    $_SESSION['login_error'] = 'Your portal access is inactive. Please contact the programme team.';
    vp_otp_login_redirect();
}

$ventureStatus = strtolower(trim((string)($user['venture_status'] ?? '')));

if ($ventureStatus !== '' && !in_array($ventureStatus, ['active', 'approved'], true)) {
    unset($_SESSION['venture_otp_pending']);
    $_SESSION['login_error'] = 'Your venture account is not active.';
    vp_otp_login_redirect();
}

$redirect = vp_otp_safe_redirect((string)($pending['redirect'] ?? 'dashboard.php'));

session_regenerate_id(true);

$_SESSION['venture_login']         = true;
$_SESSION['venture_id']            = (int)$user['venture_id'];
$_SESSION['venture_access_id']     = (int)$user['access_id'];
$_SESSION['venture_name']          = (string)$user['venture_name'];
$_SESSION['venture_email']         = (string)$user['login_email'];
$_SESSION['venture_primary_email'] = (string)$user['venture_primary_email'];
$_SESSION['venture_logo']          = (string)($user['logo'] ?? '');
$_SESSION['venture_slug']          = (string)($user['slug'] ?? '');
$_SESSION['venture_status']        = (string)($user['venture_status'] ?? '');
$_SESSION['venture_stage']         = (string)($user['stage'] ?? '');
$_SESSION['venture_sector']        = (string)($user['sector'] ?? '');
$_SESSION['venture_country']       = (string)($user['country'] ?? '');
$_SESSION['cohort_id']             = (int)($user['cohort_id'] ?? 0);
$_SESSION['last_activity']         = time();

if (!empty($pending['remember'])) {
    $_SESSION['venture_remember_requested'] = true;
}

unset(
    $_SESSION['venture_otp_pending'],
    $_SESSION['login_error'],
    $_SESSION['login_old_email'],
    $_SESSION['login_success'],
    $_SESSION['otp_error'],
    $_SESSION['otp_success'],
    $_SESSION['after_login_redirect']
);

$update = $conn->prepare("
    UPDATE venture_portal_access
    SET last_login = NOW()
    WHERE id = ?
    LIMIT 1
");

if ($update) {
    $update->bind_param('i', $accessId);
    $update->execute();
    $update->close();
}

if ((int)$user['force_password_change'] === 1) {
    header('Location: ../change-password');
    exit;
}

header('Location: ' . $redirect);
exit;
