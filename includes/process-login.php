<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mail-function.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    $_SESSION['login_error'] = 'Database connection error.';
    header('Location: ../login');
    exit;
}

$conn->set_charset('utf8mb4');

function vp_login_back(): void
{
    header('Location: ../login');
    exit;
}

function vp_safe_redirect(string $redirect): string
{
    $redirect = trim($redirect);

    if ($redirect === '') {
        return 'dashboard.php';
    }

    if (preg_match('#^https?://#i', $redirect)) {
        return 'dashboard.php';
    }

    $redirect = ltrim($redirect, '/');

    if (
        $redirect === '' ||
        str_contains($redirect, '..') ||
        str_starts_with($redirect, '//') ||
        preg_match('/[\r\n]/', $redirect)
    ) {
        return 'dashboard.php';
    }

    return $redirect;
}

function vp_mask_email(string $email): string
{
    if (!str_contains($email, '@')) {
        return $email;
    }

    [$name, $domain] = explode('@', $email, 2);
    $len = strlen($name);

    if ($len <= 2) {
        $masked = substr($name, 0, 1) . '*';
    } else {
        $masked = substr($name, 0, 2) . str_repeat('*', max(1, $len - 2));
    }

    return $masked . '@' . $domain;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['login_error'] = 'Invalid request.';
    vp_login_back();
}

$email    = trim((string)($_POST['email'] ?? ''));
site_require_csrf();
$password = (string)($_POST['password'] ?? '');
$redirect = vp_safe_redirect((string)($_POST['redirect'] ?? 'dashboard.php'));
$remember = isset($_POST['remember']) ? 1 : 0;

$_SESSION['login_old_email'] = $email;

if ($email === '' || $password === '') {
    $_SESSION['login_error'] = 'Please enter your email and password.';
    vp_login_back();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['login_error'] = 'Please enter a valid email address.';
    vp_login_back();
}

$sql = "
    SELECT
        vpa.id AS access_id,
        vpa.venture_id,
        vpa.email AS login_email,
        vpa.password_hash,
        vpa.is_active,
        vpa.force_password_change,
        vpa.last_login,

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
    WHERE vpa.email = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    $_SESSION['login_error'] = 'Login query failed.';
    vp_login_back();
}

$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    $_SESSION['login_error'] = 'Invalid email or password.';
    vp_login_back();
}

if ((int)$user['is_active'] !== 1) {
    $_SESSION['login_error'] = 'Your portal access is inactive. Please contact the programme team.';
    vp_login_back();
}

$venture_status = strtolower(trim((string)($user['venture_status'] ?? '')));

if ($venture_status !== '' && !in_array($venture_status, ['active', 'approved'], true)) {
    $_SESSION['login_error'] = 'Your venture account is not active.';
    vp_login_back();
}

$stored_hash = (string)($user['password_hash'] ?? '');

if ($stored_hash === '' || !password_verify($password, $stored_hash)) {
    $_SESSION['login_error'] = 'Invalid email or password.';
    vp_login_back();
}

$otp        = (string)random_int(100000, 999999);
$otp_hash   = password_hash($otp, PASSWORD_DEFAULT);
$expires_at = time() + 600;

$_SESSION['venture_otp_pending'] = [
    'access_id'             => (int)$user['access_id'],
    'venture_id'            => (int)$user['venture_id'],
    'venture_name'          => (string)$user['venture_name'],
    'login_email'           => (string)$user['login_email'],
    'venture_primary_email' => (string)$user['venture_primary_email'],
    'logo'                  => (string)($user['logo'] ?? ''),
    'slug'                  => (string)($user['slug'] ?? ''),
    'venture_status'        => (string)($user['venture_status'] ?? ''),
    'stage'                 => (string)($user['stage'] ?? ''),
    'sector'                => (string)($user['sector'] ?? ''),
    'country'               => (string)($user['country'] ?? ''),
    'cohort_id'             => (int)($user['cohort_id'] ?? 0),
    'force_password_change' => (int)($user['force_password_change'] ?? 0),
    'redirect'              => $redirect,
    'remember'              => $remember,
    'otp_hash'              => $otp_hash,
    'expires_at'            => $expires_at,
    'attempts'              => 0,
    'last_sent_at'          => time(),
];

$programme = function_exists('get_setting')
    ? get_setting($conn, 'site_name', 'Hive Colab')
    : 'Hive Colab';

$safeName = htmlspecialchars((string)$user['venture_name'], ENT_QUOTES, 'UTF-8');
$safeOtp  = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');

$content = "
    <h3>Verify your venture portal login</h3>
    <p>Hello {$safeName},</p>
    <p>Use the one-time verification code below to complete your sign in:</p>

    <div class='cred-box'>
        <h4>Your verification code</h4>
        <div class='password'>{$safeOtp}</div>
    </div>

    <p>This code expires in <strong>10 minutes</strong>.</p>

    <div class='warning'>
        If you did not attempt to sign in to your venture portal, you can ignore this email.
    </div>

    <p>Thank you,<br>" . htmlspecialchars($programme, ENT_QUOTES, 'UTF-8') . "</p>
";

$subject = 'Your venture portal verification code';
$body = function_exists('email_wrapper') ? email_wrapper($content) : $content;
$altBody = "Hello {$user['venture_name']},\n\n"
    . "Your venture portal verification code is: {$otp}\n\n"
    . "This code expires in 10 minutes.\n\n"
    . "If you did not attempt to sign in, ignore this email.\n\n"
    . $programme;

$result = sendEmail((string)$user['login_email'], $subject, $body, $altBody);

if ($result !== true) {
    unset($_SESSION['venture_otp_pending']);
    $_SESSION['login_error'] = 'We could not send your verification code. Please try again or contact the programme team.';
    vp_login_back();
}

unset($_SESSION['login_error']);
$_SESSION['login_success'] = 'A verification code was sent to ' . vp_mask_email((string)$user['login_email']) . '.';

header('Location: ../verify-otp');
exit;
