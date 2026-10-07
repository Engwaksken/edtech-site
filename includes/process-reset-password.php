<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {

    $_SESSION['reset_error'] =
        'Database connection error.';

    header('Location: ../login.php');
    exit;
}

$conn->set_charset('utf8mb4');


/*
|--------------------------------------------------------------------------
| Input
|--------------------------------------------------------------------------
*/
$token = trim(
    (string)($_POST['token'] ?? '')
);

$email = strtolower(
    trim(
        (string)($_POST['email'] ?? '')
    )
);

$password =
    (string)($_POST['password'] ?? '');

$passwordConfirmation =
    (string)(
        $_POST['password_confirmation']
        ?? ''
    );


/*
|--------------------------------------------------------------------------
| Return to reset form
|--------------------------------------------------------------------------
*/
function vp_reset_back(
    string $token,
    string $email
): never {

    $url =
        '../reset-password.php?token='
        . urlencode($token)
        . '&email='
        . urlencode($email);

    header('Location: ' . $url);
    exit;
}


/*
|--------------------------------------------------------------------------
| POST only
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header('Location: ../forgot-password.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Validate basic values
|--------------------------------------------------------------------------
*/
if (
    $token === '' ||
    $email === ''
) {

    $_SESSION['forgot_error'] =
        'The password reset link is invalid. '
        . 'Please request a new one.';

    header('Location: ../forgot-password.php');
    exit;
}


if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

    $_SESSION['forgot_error'] =
        'The password reset link is invalid.';

    header('Location: ../forgot-password.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Password validation
|--------------------------------------------------------------------------
*/
if ($password === '') {

    $_SESSION['reset_error'] =
        'Please enter your new password.';

    vp_reset_back(
        $token,
        $email
    );
}


if (strlen($password) < 8) {

    $_SESSION['reset_error'] =
        'Your password must contain at least 8 characters.';

    vp_reset_back(
        $token,
        $email
    );
}


if (!preg_match('/[A-Z]/', $password)) {

    $_SESSION['reset_error'] =
        'Your password must contain at least one uppercase letter.';

    vp_reset_back(
        $token,
        $email
    );
}


if (!preg_match('/[a-z]/', $password)) {

    $_SESSION['reset_error'] =
        'Your password must contain at least one lowercase letter.';

    vp_reset_back(
        $token,
        $email
    );
}


if (!preg_match('/[0-9]/', $password)) {

    $_SESSION['reset_error'] =
        'Your password must contain at least one number.';

    vp_reset_back(
        $token,
        $email
    );
}


if ($password !== $passwordConfirmation) {

    $_SESSION['reset_error'] =
        'The passwords do not match.';

    vp_reset_back(
        $token,
        $email
    );
}


/*
|--------------------------------------------------------------------------
| Validate token
|--------------------------------------------------------------------------
*/
$tokenHash = hash(
    'sha256',
    $token
);


$stmt = $conn->prepare("
    SELECT
        vpr.id AS reset_id,
        vpr.access_id,
        vpr.email,
        vpr.expires_at,

        vpa.password_hash,
        vpa.is_active

    FROM venture_password_resets vpr

    INNER JOIN venture_portal_access vpa
        ON vpa.id = vpr.access_id

    WHERE LOWER(vpr.email) = LOWER(?)
      AND vpr.token_hash = ?
      AND vpr.used_at IS NULL
      AND vpr.expires_at > NOW()
      AND vpa.is_active = 1

    LIMIT 1
");

if (!$stmt) {

    error_log(
        'Reset lookup prepare error: '
        . $conn->error
    );

    $_SESSION['reset_error'] =
        'Unable to verify your reset request. '
        . 'Please try again.';

    vp_reset_back(
        $token,
        $email
    );
}


$stmt->bind_param(
    'ss',
    $email,
    $tokenHash
);

$stmt->execute();

$reset = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();


if (!$reset) {

    $_SESSION['forgot_error'] =
        'This password reset link has expired, '
        . 'has already been used or is invalid. '
        . 'Please request a new link.';

    header('Location: ../forgot-password.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Prevent reuse of same password
|--------------------------------------------------------------------------
*/
$currentHash = (string)(
    $reset['password_hash']
    ?? ''
);

if (
    $currentHash !== '' &&
    password_verify(
        $password,
        $currentHash
    )
) {

    $_SESSION['reset_error'] =
        'Your new password must be different '
        . 'from your current password.';

    vp_reset_back(
        $token,
        $email
    );
}


/*
|--------------------------------------------------------------------------
| Create password hash
|--------------------------------------------------------------------------
*/
$newHash = password_hash(
    $password,
    PASSWORD_DEFAULT
);

if ($newHash === false) {

    $_SESSION['reset_error'] =
        'Unable to secure your new password. '
        . 'Please try again.';

    vp_reset_back(
        $token,
        $email
    );
}


$accessId = (int)$reset['access_id'];
$resetId = (int)$reset['reset_id'];


/*
|--------------------------------------------------------------------------
| Transaction
|--------------------------------------------------------------------------
*/
$conn->begin_transaction();

try {

    /*
    |--------------------------------------------------------------------------
    | Update password
    |--------------------------------------------------------------------------
    */
    $update = $conn->prepare("
        UPDATE venture_portal_access
        SET
            password_hash = ?,
            force_password_change = 0
        WHERE id = ?
        LIMIT 1
    ");

    if (!$update) {
        throw new RuntimeException(
            'Unable to prepare password update.'
        );
    }


    $update->bind_param(
        'si',
        $newHash,
        $accessId
    );

    if (!$update->execute()) {

        $message = $update->error;

        $update->close();

        throw new RuntimeException(
            $message
        );
    }

    $update->close();


    /*
    |--------------------------------------------------------------------------
    | Mark token used
    |--------------------------------------------------------------------------
    */
    $used = $conn->prepare("
        UPDATE venture_password_resets
        SET used_at = NOW()
        WHERE id = ?
          AND used_at IS NULL
        LIMIT 1
    ");

    if (!$used) {

        throw new RuntimeException(
            'Unable to mark reset token as used.'
        );
    }


    $used->bind_param(
        'i',
        $resetId
    );

    if (!$used->execute()) {

        $message = $used->error;

        $used->close();

        throw new RuntimeException(
            $message
        );
    }

    $used->close();


    /*
    |--------------------------------------------------------------------------
    | Invalidate any other outstanding tokens
    |--------------------------------------------------------------------------
    */
    $invalidate = $conn->prepare("
        UPDATE venture_password_resets
        SET used_at = NOW()
        WHERE access_id = ?
          AND used_at IS NULL
    ");

    if ($invalidate) {

        $invalidate->bind_param(
            'i',
            $accessId
        );

        $invalidate->execute();
        $invalidate->close();
    }


    $conn->commit();

} catch (Throwable $e) {

    $conn->rollback();

    error_log(
        'Password reset failed: '
        . $e->getMessage()
    );

    $_SESSION['reset_error'] =
        'We could not update your password. '
        . 'Please try again.';

    vp_reset_back(
        $token,
        $email
    );
}


/*
|--------------------------------------------------------------------------
| Remove any venture authentication session
|--------------------------------------------------------------------------
*/
unset(
    $_SESSION['venture_login'],
    $_SESSION['venture_id'],
    $_SESSION['venture_access_id'],
    $_SESSION['venture_name'],
    $_SESSION['venture_email'],
    $_SESSION['venture_primary_email'],
    $_SESSION['venture_logo'],
    $_SESSION['venture_slug'],
    $_SESSION['venture_status'],
    $_SESSION['venture_stage'],
    $_SESSION['venture_sector'],
    $_SESSION['venture_country'],
    $_SESSION['cohort_id'],
    $_SESSION['last_activity']
);


/*
|--------------------------------------------------------------------------
| Login success message
|--------------------------------------------------------------------------
*/
$_SESSION['login_success'] =
    'Your password has been updated successfully. '
    . 'You can now sign in using your new password.';


header('Location: ../login.php');
exit;