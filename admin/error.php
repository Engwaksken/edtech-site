<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->set_charset('utf8mb4');
}

if (!function_exists('admin_error_h')) {
    function admin_error_h($value): string
    {
        return htmlspecialchars(
            (string)($value ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

$requestedCode = (int)(
    $_GET['code']
    ?? http_response_code()
    ?? 500
);

$allowedCodes = [
    400,
    401,
    403,
    404,
    419,
    429,
    500,
    502,
    503,
];

$errorCode = in_array(
    $requestedCode,
    $allowedCodes,
    true
)
    ? $requestedCode
    : 500;

$errors = [
    400 => [
        'title' => 'Invalid request',
        'heading' => 'The request could not be processed.',
        'message' => 'Please review the information submitted and try again.',
        'icon' => 'fa-exclamation-circle',
    ],

    401 => [
        'title' => 'Authentication required',
        'heading' => 'Administrator sign-in required.',
        'message' => 'Your administrator session may have ended. Please sign in to continue.',
        'icon' => 'fa-user-lock',
    ],

    403 => [
        'title' => 'Access denied',
        'heading' => 'You do not have permission to access this area.',
        'message' => 'Your account does not currently have the required permission for this page or action.',
        'icon' => 'fa-lock',
    ],

    404 => [
        'title' => 'Page not found',
        'heading' => 'The admin page could not be found.',
        'message' => 'The page may have moved, been removed, or the URL may be incorrect.',
        'icon' => 'fa-search',
    ],

    419 => [
        'title' => 'Session expired',
        'heading' => 'Your admin session has expired.',
        'message' => 'For security, the session timed out. Refresh the page or sign in again.',
        'icon' => 'fa-clock',
    ],

    429 => [
        'title' => 'Too many requests',
        'heading' => 'Please wait before trying again.',
        'message' => 'Too many requests were received in a short period. Please wait briefly and retry.',
        'icon' => 'fa-hourglass-half',
    ],

    500 => [
        'title' => 'Server error',
        'heading' => 'An admin system error occurred.',
        'message' => 'The requested action could not be completed. No technical details have been exposed for security.',
        'icon' => 'fa-tools',
    ],

    502 => [
        'title' => 'Service unavailable',
        'heading' => 'A required service is unavailable.',
        'message' => 'The admin system could not reach one of its required services. Please try again shortly.',
        'icon' => 'fa-plug',
    ],

    503 => [
        'title' => 'Maintenance',
        'heading' => 'The admin system is temporarily unavailable.',
        'message' => 'Maintenance or an update may be in progress. Please try again shortly.',
        'icon' => 'fa-screwdriver',
    ],
];

$error = $errors[$errorCode];

http_response_code(
    $errorCode === 419
        ? 419
        : $errorCode
);

$siteName =
    function_exists('get_setting')
    && isset($conn)
    && $conn instanceof mysqli
        ? get_setting(
            $conn,
            'site_name',
            'EdTech Fellowship'
        )
        : 'EdTech Fellowship';

$dashboardUrl = 'index.php';
$loginUrl = 'login.php';

if (
    isset($_SESSION['admin_user'])
    || isset($_SESSION['user_id'])
    || isset($_SESSION['admin_id'])
) {
    $primaryUrl = $dashboardUrl;
    $primaryLabel = 'Admin Dashboard';
    $primaryIcon = 'fa-th-large';
} else {
    $primaryUrl = $loginUrl;
    $primaryLabel = 'Admin Sign In';
    $primaryIcon = 'fa-sign-in-alt';
}

if ($errorCode === 401) {
    $primaryUrl = $loginUrl;
    $primaryLabel = 'Admin Sign In';
    $primaryIcon = 'fa-sign-in-alt';
}

$requestUri = (string)(
    $_SERVER['REQUEST_URI']
    ?? ''
);

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<meta
    name="robots"
    content="noindex,nofollow"
>

<title>
    <?= admin_error_h($errorCode) ?> -
    <?= admin_error_h($error['title']) ?> |
    Admin
</title>

<link
    rel="icon"
    type="image/png"
    href="../assets/images/favicon.png"
>

<link
    href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css"
>

<link
    rel="stylesheet"
    href="assets/css/admin.css"
>

<style>

.admin-error-page {
    min-height: 100vh;
    margin: 0;
    padding: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    background:
        radial-gradient(
            circle at 8% 12%,
            rgba(252,127,16,.10),
            transparent 28%
        ),
        #f6f8fa;
}

.admin-error-card {
    width: min(100%, 780px);
    overflow: hidden;
    background: #fff;
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 18px;
    box-shadow:
        0 18px 55px
        rgba(15,23,42,.12);
}

.admin-error-top {
    min-height: 88px;
    padding: 20px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    background: #0d1117;
    color: #fff;
}

.admin-error-brand {
    display: flex;
    align-items: center;
    gap: 12px;
}

.admin-error-brand-icon {
    width: 44px;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 11px;
    background: var(--primary, #fc7f10);
    color: #fff;
}

.admin-error-brand strong {
    display: block;
    font-size: .95rem;
}

.admin-error-brand span {
    display: block;
    margin-top: 2px;
    color: #8b949e;
    font-size: .72rem;
}

.admin-error-code-mini {
    font-size: 1.15rem;
    font-weight: 800;
    color: #8b949e;
}

.admin-error-body {
    padding: clamp(28px, 6vw, 52px);
    text-align: center;
}

.admin-error-icon {
    width: 72px;
    height: 72px;
    margin: 0 auto 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 18px;
    background: var(--primary-light, #fff3e8);
    color: var(--primary, #fc7f10);
    font-size: 26px;
}

.admin-error-body h1 {
    margin: 0;
    font-size: clamp(1.45rem, 4vw, 2rem);
    line-height: 1.25;
    letter-spacing: -.03em;
}

.admin-error-body p {
    max-width: 570px;
    margin: 12px auto 0;
    color: var(--text-muted, #64748b);
    font-size: .9rem;
    line-height: 1.65;
}

.admin-error-actions {
    display: flex;
    justify-content: center;
    gap: 9px;
    flex-wrap: wrap;
    margin-top: 26px;
}

.admin-error-actions .btn {
    min-width: 145px;
    justify-content: center;
}

.admin-error-foot {
    margin-top: 28px;
    padding-top: 17px;
    border-top: 1px solid var(--border, #e2e8f0);
    color: var(--text-muted, #64748b);
    font-size: .72rem;
    line-height: 1.55;
}

.admin-error-path {
    display: block;
    margin-top: 5px;
    color: #94a3b8;
    word-break: break-word;
}

@media (max-width: 560px) {

    .admin-error-page {
        padding: 12px;
    }

    .admin-error-card {
        border-radius: 14px;
    }

    .admin-error-top {
        padding: 15px;
    }

    .admin-error-code-mini {
        display: none;
    }

    .admin-error-body {
        padding: 30px 18px;
    }

    .admin-error-actions {
        flex-direction: column;
    }

    .admin-error-actions .btn {
        width: 100%;
    }

}

</style>

</head>

<body>

<main class="admin-error-page">

    <section class="admin-error-card">

        <header class="admin-error-top">

            <div class="admin-error-brand">

                <div class="admin-error-brand-icon">
                    <i class="fa fa-cog"></i>
                </div>

                <div>

                    <strong>
                        <?= admin_error_h($siteName) ?>
                    </strong>

                    <span>
                        Administration
                    </span>

                </div>

            </div>

            <div class="admin-error-code-mini">
                Error <?= admin_error_h($errorCode) ?>
            </div>

        </header>


        <div class="admin-error-body">

            <div class="admin-error-icon">
                <i class="fa <?= admin_error_h($error['icon']) ?>"></i>
            </div>

            <h1>
                <?= admin_error_h($error['heading']) ?>
            </h1>

            <p>
                <?= admin_error_h($error['message']) ?>
            </p>


            <div class="admin-error-actions">

                <?php if (in_array($errorCode, [400, 419, 429, 500, 502, 503], true)): ?>

                    <button
                        type="button"
                        class="btn btn-primary"
                        onclick="window.location.reload()"
                    >
                        <i class="fa fa-sync-alt"></i>
                        Try Again
                    </button>

                <?php endif; ?>


                <a
                    href="<?= admin_error_h($primaryUrl) ?>"
                    class="btn btn-primary"
                >
                    <i class="fa <?= admin_error_h($primaryIcon) ?>"></i>
                    <?= admin_error_h($primaryLabel) ?>
                </a>


                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="
                        if (window.history.length > 1) {
                            window.history.back();
                        } else {
                            window.location.href = <?= json_encode($primaryUrl) ?>;
                        }
                    "
                >
                    <i class="fa fa-arrow-left"></i>
                    Go Back
                </button>

            </div>


            <div class="admin-error-foot">

                For security, detailed PHP or database error messages
                are not displayed on this page.

                <?php if ($requestUri !== ''): ?>

                    <span class="admin-error-path">
                        Reference:
                        <?= admin_error_h($errorCode) ?>
                        .
                        <?= admin_error_h($requestUri) ?>
                    </span>

                <?php endif; ?>

            </div>

        </div>

    </section>

</main>

</body>
</html>
