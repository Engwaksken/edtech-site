<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    $_SESSION['forgot_error'] = 'Database connection error.';

    header('Location: ../forgot-password.php');
    exit;
}

$conn->set_charset('utf8mb4');


/*
|--------------------------------------------------------------------------
| Redirect helper
|--------------------------------------------------------------------------
*/
function vp_forgot_back(): never
{
    header('Location: ../forgot-password.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Only POST requests
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    vp_forgot_back();
}


/*
|--------------------------------------------------------------------------
| Email
|--------------------------------------------------------------------------
*/
$email = strtolower(
    trim((string)($_POST['email'] ?? ''))
);

$_SESSION['forgot_old_email'] = $email;


if ($email === '') {

    $_SESSION['forgot_error'] =
        'Please enter your email address.';

    vp_forgot_back();
}


if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

    $_SESSION['forgot_error'] =
        'Please enter a valid email address.';

    vp_forgot_back();
}


/*
|--------------------------------------------------------------------------
| Generic success response
|--------------------------------------------------------------------------
|
| Do not reveal whether an email exists.
|
*/
$genericMessage =
    'If an active venture account exists for that email address, '
    . 'we have sent password reset instructions. '
    . 'Please check your inbox and spam folder.';


/*
|--------------------------------------------------------------------------
| Look up venture access
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        vpa.id,
        vpa.venture_id,
        vpa.email,
        vpa.is_active,

        v.name AS venture_name,
        v.status AS venture_status

    FROM venture_portal_access vpa

    INNER JOIN ventures v
        ON v.id = vpa.venture_id

    WHERE LOWER(vpa.email) = LOWER(?)

    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {

    error_log(
        'Forgot password lookup prepare error: '
        . $conn->error
    );

    $_SESSION['forgot_error'] =
        'We could not process your request right now. '
        . 'Please try again.';

    vp_forgot_back();
}


$stmt->bind_param('s', $email);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

$stmt->close();


/*
|--------------------------------------------------------------------------
| Unknown user
|--------------------------------------------------------------------------
*/
if (!$user) {

    /*
     * Still return generic success message.
     */
    unset($_SESSION['forgot_old_email']);

    $_SESSION['forgot_success'] = $genericMessage;

    vp_forgot_back();
}


/*
|--------------------------------------------------------------------------
| Account status
|--------------------------------------------------------------------------
*/
$isActive = (int)($user['is_active'] ?? 0) === 1;

$ventureStatus = strtolower(
    trim((string)($user['venture_status'] ?? ''))
);

$ventureAllowed = (
    $ventureStatus === '' ||
    in_array(
        $ventureStatus,
        ['active', 'approved'],
        true
    )
);


if (!$isActive || !$ventureAllowed) {

    /*
     * Do not expose account status.
     */
    unset($_SESSION['forgot_old_email']);

    $_SESSION['forgot_success'] = $genericMessage;

    vp_forgot_back();
}


/*
|--------------------------------------------------------------------------
| Generate reset token
|--------------------------------------------------------------------------
*/
try {

    $plainToken = bin2hex(
        random_bytes(32)
    );

} catch (Throwable $e) {

    error_log(
        'Reset token generation failed: '
        . $e->getMessage()
    );

    $_SESSION['forgot_error'] =
        'We could not create your password reset request. '
        . 'Please try again.';

    vp_forgot_back();
}


/*
|--------------------------------------------------------------------------
| Hash token before storing
|--------------------------------------------------------------------------
*/
$tokenHash = hash(
    'sha256',
    $plainToken
);

$accessId = (int)$user['id'];


/*
|--------------------------------------------------------------------------
| Delete previous unused reset requests
|--------------------------------------------------------------------------
*/
$delete = $conn->prepare("
    DELETE FROM venture_password_resets
    WHERE access_id = ?
      AND used_at IS NULL
");

if ($delete) {

    $delete->bind_param(
        'i',
        $accessId
    );

    $delete->execute();
    $delete->close();
}


/*
|--------------------------------------------------------------------------
| Store token
|--------------------------------------------------------------------------
*/
$insert = $conn->prepare("
    INSERT INTO venture_password_resets
    (
        access_id,
        email,
        token_hash,
        expires_at,
        created_at
    )
    VALUES
    (
        ?,
        ?,
        ?,
        DATE_ADD(NOW(), INTERVAL 60 MINUTE),
        NOW()
    )
");

if (!$insert) {

    error_log(
        'Password reset insert prepare failed: '
        . $conn->error
    );

    $_SESSION['forgot_error'] =
        'We could not create your password reset request. '
        . 'Please try again.';

    vp_forgot_back();
}


$insert->bind_param(
    'iss',
    $accessId,
    $email,
    $tokenHash
);

if (!$insert->execute()) {

    error_log(
        'Password reset insert failed: '
        . $insert->error
    );

    $insert->close();

    $_SESSION['forgot_error'] =
        'We could not create your password reset request. '
        . 'Please try again.';

    vp_forgot_back();
}

$insert->close();


/*
|--------------------------------------------------------------------------
| Build reset URL
|--------------------------------------------------------------------------
*/
if (defined('SITE_URL')) {

    $baseUrl = rtrim(
        (string)SITE_URL,
        '/'
    );

} else {

    $isHttps =
        !empty($_SERVER['HTTPS']) &&
        strtolower((string)$_SERVER['HTTPS']) !== 'off';

    $scheme = $isHttps
        ? 'https'
        : 'http';

    $host = (string)(
        $_SERVER['HTTP_HOST']
        ?? 'localhost'
    );

    /*
     * includes/process-forgot-password.php
     * -> project root
     */
    $scriptName = str_replace(
        '\\',
        '/',
        (string)($_SERVER['SCRIPT_NAME'] ?? '')
    );

    $rootPath = dirname(
        dirname($scriptName)
    );

    $rootPath = $rootPath === '/'
        ? ''
        : rtrim($rootPath, '/');

    $baseUrl =
        $scheme
        . '://'
        . $host
        . $rootPath;
}


$resetUrl =
    $baseUrl
    . '/reset-password.php?token='
    . urlencode($plainToken)
    . '&email='
    . urlencode($email);


/*
|--------------------------------------------------------------------------
| Email details
|--------------------------------------------------------------------------
*/
$ventureName = trim(
    (string)(
        $user['venture_name']
        ?? 'Venture'
    )
);

$siteName = function_exists('get_setting')
    ? get_setting(
        $conn,
        'site_name',
        'EdTech Fellowship'
    )
    : 'EdTech Fellowship';

$contactEmail = function_exists('get_setting')
    ? get_setting(
        $conn,
        'site_email',
        'hello@example.com'
    )
    : 'hello@example.com';


/*
|--------------------------------------------------------------------------
| Email subject
|--------------------------------------------------------------------------
*/
$subject =
    'Reset your '
    . $siteName
    . ' password';


/*
|--------------------------------------------------------------------------
| Email body
|--------------------------------------------------------------------------
*/
$safeVentureName = htmlspecialchars(
    $ventureName,
    ENT_QUOTES,
    'UTF-8'
);

$safeSiteName = htmlspecialchars(
    $siteName,
    ENT_QUOTES,
    'UTF-8'
);

$safeResetUrl = htmlspecialchars(
    $resetUrl,
    ENT_QUOTES,
    'UTF-8'
);

$safeContactEmail = htmlspecialchars(
    $contactEmail,
    ENT_QUOTES,
    'UTF-8'
);


$message = '
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>

<body style="
    margin:0;
    padding:0;
    background:#f3f6fb;
    font-family:Arial,Helvetica,sans-serif;
">

<table
    width="100%"
    cellpadding="0"
    cellspacing="0"
    border="0"
    style="padding:30px 15px;"
>
<tr>
<td align="center">

<table
    width="100%"
    cellpadding="0"
    cellspacing="0"
    border="0"
    style="
        max-width:600px;
        background:#ffffff;
        border-radius:16px;
        overflow:hidden;
        box-shadow:0 8px 30px rgba(15,23,42,.08);
    "
>

<tr>
<td style="
    padding:32px;
    background:#123c73;
    color:#ffffff;
">

    <div style="
        font-size:14px;
        opacity:.8;
        margin-bottom:8px;
    ">
        ' . $safeSiteName . '
    </div>

    <div style="
        font-size:27px;
        font-weight:700;
    ">
        Reset your password
    </div>

</td>
</tr>

<tr>
<td style="
    padding:35px 32px;
    color:#334155;
    font-size:15px;
    line-height:1.7;
">

    <p style="margin-top:0;">
        Hello ' . $safeVentureName . ',
    </p>

    <p>
        We received a request to reset the password
        for your venture portal account.
    </p>

    <p>
        Click the button below to create a new password.
    </p>

    <p style="
        text-align:center;
        margin:30px 0;
    ">

        <a
            href="' . $safeResetUrl . '"
            style="
                display:inline-block;
                background:#2563eb;
                color:#ffffff;
                padding:14px 24px;
                border-radius:9px;
                text-decoration:none;
                font-weight:700;
            "
        >
            Reset Password
        </a>

    </p>

    <p>
        This password reset link expires in
        <strong>60 minutes</strong>
        and can only be used once.
    </p>

    <p>
        If you did not request a password reset,
        you can safely ignore this email.
        Your password will remain unchanged.
    </p>

    <hr style="
        border:none;
        border-top:1px solid #e2e8f0;
        margin:30px 0;
    ">

    <p style="
        font-size:13px;
        color:#64748b;
    ">
        If the button does not work, copy and paste
        this link into your browser:
    </p>

    <p style="
        word-break:break-all;
        font-size:12px;
        color:#2563eb;
    ">
        ' . $safeResetUrl . '
    </p>

</td>
</tr>

<tr>
<td style="
    padding:22px 32px;
    background:#f8fafc;
    color:#64748b;
    font-size:12px;
    text-align:center;
">
    Need help?
    Contact
    <a
        href="mailto:' . $safeContactEmail . '"
        style="color:#2563eb;"
    >
        ' . $safeContactEmail . '
    </a>
</td>
</tr>

</table>

</td>
</tr>
</table>

</body>
</html>
';


/*
|--------------------------------------------------------------------------
| Send email
|--------------------------------------------------------------------------
|
| This supports common project mail helper names.
|
*/
$mailSent = false;

try {

    /*
     * OPTION 1
     * Existing sendEmail() helper.
     */
    if (function_exists('sendEmail')) {

        $result = sendEmail(
            $email,
            $subject,
            $message
        );

        $mailSent = $result !== false;

    /*
     * OPTION 2
     * Existing send_email() helper.
     */
    } elseif (function_exists('send_email')) {

        $result = send_email(
            $email,
            $subject,
            $message
        );

        $mailSent = $result !== false;

    } else {

        /*
         * Native mail fallback.
         */
        $headers = [];

        $headers[] =
            'MIME-Version: 1.0';

        $headers[] =
            'Content-type: text/html; charset=UTF-8';

        $headers[] =
            'From: '
            . $siteName
            . ' <'
            . $contactEmail
            . '>';

        $mailSent = mail(
            $email,
            $subject,
            $message,
            implode("\r\n", $headers)
        );
    }

} catch (Throwable $e) {

    error_log(
        'Password reset email error: '
        . $e->getMessage()
    );

    $mailSent = false;
}


/*
|--------------------------------------------------------------------------
| Handle send failure
|--------------------------------------------------------------------------
*/
if (!$mailSent) {

    /*
     * Delete reset record because email was not sent.
     */
    $cleanup = $conn->prepare("
        DELETE FROM venture_password_resets
        WHERE access_id = ?
          AND token_hash = ?
        LIMIT 1
    ");

    if ($cleanup) {

        $cleanup->bind_param(
            'is',
            $accessId,
            $tokenHash
        );

        $cleanup->execute();
        $cleanup->close();
    }

    $_SESSION['forgot_error'] =
        'We could not send the password reset email right now. '
        . 'Please try again or contact the programme team.';

    vp_forgot_back();
}


/*
|--------------------------------------------------------------------------
| Success
|--------------------------------------------------------------------------
*/
unset(
    $_SESSION['forgot_old_email']
);

$_SESSION['forgot_success'] =
    $genericMessage;

vp_forgot_back();