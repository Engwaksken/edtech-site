<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->set_charset('utf8mb4');
}

if (!function_exists('error_page_h')) {
    function error_page_h($value): string
    {
        return htmlspecialchars(
            (string)($value ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Error details
|--------------------------------------------------------------------------
|
| You may open:
|
| /error.php?code=404
| /error.php?code=403
| /error.php?code=419
| /error.php?code=429
| /error.php?code=500
|
*/

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
        'title' => 'Something went wrong',
        'heading' => 'We could not process that request.',
        'message' => 'The information sent to the website could not be processed. Please check your request and try again.',
        'icon' => 'fa-exclamation-circle',
        'action' => 'Try Again',
    ],

    401 => [
        'title' => 'Sign in required',
        'heading' => 'You need to sign in.',
        'message' => 'Please sign in to continue to this page.',
        'icon' => 'fa-user-lock',
        'action' => 'Sign In',
    ],

    403 => [
        'title' => 'Access denied',
        'heading' => 'You do not have access to this page.',
        'message' => 'The page may be restricted or you may not have permission to view it.',
        'icon' => 'fa-lock',
        'action' => 'Go Home',
    ],

    404 => [
        'title' => 'Page not found',
        'heading' => 'We could not find that page.',
        'message' => 'The page may have moved, been removed, or the link may be incorrect.',
        'icon' => 'fa-search',
        'action' => 'Go Home',
    ],

    419 => [
        'title' => 'Session expired',
        'heading' => 'Your session has expired.',
        'message' => 'For your security, your session timed out. Please refresh the page or sign in again.',
        'icon' => 'fa-clock',
        'action' => 'Refresh',
    ],

    429 => [
        'title' => 'Too many requests',
        'heading' => 'Please try again shortly.',
        'message' => 'Too many requests were received in a short time. Please wait a moment before trying again.',
        'icon' => 'fa-hourglass-half',
        'action' => 'Try Again',
    ],

    500 => [
        'title' => 'Server error',
        'heading' => 'Something went wrong on our side.',
        'message' => 'We could not complete your request right now. Please try again shortly.',
        'icon' => 'fa-tools',
        'action' => 'Try Again',
    ],

    502 => [
        'title' => 'Service unavailable',
        'heading' => 'The service is temporarily unavailable.',
        'message' => 'We are having trouble reaching one of our services. Please try again shortly.',
        'icon' => 'fa-plug',
        'action' => 'Try Again',
    ],

    503 => [
        'title' => 'Temporarily unavailable',
        'heading' => 'We will be back shortly.',
        'message' => 'The website is temporarily unavailable while maintenance or an update is being completed.',
        'icon' => 'fa-screwdriver',
        'action' => 'Try Again',
    ],
];

$error = $errors[$errorCode];

http_response_code(
    $errorCode === 419
        ? 419
        : $errorCode
);

$siteName = function_exists('get_setting') && isset($conn) && $conn instanceof mysqli
    ? get_setting(
        $conn,
        'site_name',
        'EdTech Fellowship'
    )
    : 'EdTech Fellowship';

$siteEmail = function_exists('get_setting') && isset($conn) && $conn instanceof mysqli
    ? get_setting(
        $conn,
        'site_email',
        ''
    )
    : '';

$homeUrl = defined('SITE_URL')
    ? rtrim((string)SITE_URL, '/') . '/'
    : '/';

$loginUrl = defined('SITE_URL')
    ? rtrim((string)SITE_URL, '/') . '/login'
    : 'login';

$primaryUrl = $homeUrl;

if ($errorCode === 401) {
    $primaryUrl = $loginUrl;
}

if ($errorCode === 419) {
    $primaryUrl = '#';
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
    <?= error_page_h($errorCode) ?> -
    <?= error_page_h($error['title']) ?> |
    <?= error_page_h($siteName) ?>
</title>

<link
    rel="icon"
    type="image/png"
    href="<?= error_page_h(
        defined('SITE_URL')
            ? rtrim((string)SITE_URL, '/') . '/assets/images/favicon.png'
            : 'assets/images/favicon.png'
    ) ?>"
>

<link
    href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css"
>

<style>

:root {
    --error-primary: #fc7f10;
    --error-primary-dark: #df6800;
    --error-ink: #111827;
    --error-muted: #6b7280;
    --error-border: #e5e7eb;
    --error-surface: #ffffff;
    --error-bg: #f7f8fb;
}

* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    min-height: 100%;
}

body {
    min-height: 100vh;
    font-family: 'Outfit', sans-serif;
    color: var(--error-ink);
    background:
        radial-gradient(
            circle at 10% 15%,
            rgba(252, 127, 16, .10),
            transparent 28%
        ),
        radial-gradient(
            circle at 90% 85%,
            rgba(47, 123, 107, .10),
            transparent 30%
        ),
        var(--error-bg);
}

.error-page {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 28px;
}

.error-card {
    width: min(100%, 760px);
    position: relative;
    overflow: hidden;
    background: rgba(255,255,255,.97);
    border: 1px solid var(--error-border);
    border-radius: 24px;
    padding: clamp(28px, 6vw, 58px);
    text-align: center;
    box-shadow:
        0 24px 70px
        rgba(15, 23, 42, .10);
}

.error-card::before {
    content: "";
    position: absolute;
    inset: 0 0 auto;
    height: 5px;
    background:
        linear-gradient(
            90deg,
            #fc7f10,
            #ff9d3f,
            #2f7b6b
        );
}

.error-brand {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    margin-bottom: 26px;
    color: var(--error-muted);
    font-size: .82rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
}

.error-brand i {
    color: var(--error-primary);
}

.error-code {
    margin: 0;
    font-size: clamp(4.5rem, 18vw, 9rem);
    line-height: .9;
    font-weight: 800;
    letter-spacing: -.07em;
    color: #eef0f4;
    user-select: none;
}

.error-icon {
    width: 72px;
    height: 72px;
    margin: -26px auto 20px;
    position: relative;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 20px;
    background: #fff4ea;
    color: var(--error-primary);
    font-size: 26px;
    box-shadow:
        0 8px 24px
        rgba(252,127,16,.15);
}

.error-card h1 {
    margin: 0;
    font-size: clamp(1.55rem, 4vw, 2.25rem);
    line-height: 1.2;
    letter-spacing: -.035em;
}

.error-message {
    max-width: 560px;
    margin: 13px auto 0;
    color: var(--error-muted);
    font-size: .98rem;
    line-height: 1.7;
}

.error-actions {
    display: flex;
    justify-content: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 28px;
}

.error-btn {
    min-height: 44px;
    padding: 11px 20px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border-radius: 10px;
    border: 1.5px solid transparent;
    font: inherit;
    font-size: .88rem;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    transition:
        transform .18s ease,
        background .18s ease,
        border-color .18s ease;
}

.error-btn:hover {
    transform: translateY(-1px);
}

.error-btn-primary {
    background: var(--error-primary);
    border-color: var(--error-primary);
    color: #fff;
}

.error-btn-primary:hover {
    background: var(--error-primary-dark);
    border-color: var(--error-primary-dark);
}

.error-btn-secondary {
    background: #fff;
    border-color: var(--error-border);
    color: var(--error-ink);
}

.error-btn-secondary:hover {
    background: #f8fafc;
}

.error-help {
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid var(--error-border);
    color: var(--error-muted);
    font-size: .78rem;
    line-height: 1.6;
}

.error-help a {
    color: var(--error-primary-dark);
    font-weight: 700;
    text-decoration: none;
}

.error-reference {
    display: block;
    margin-top: 6px;
    color: #9ca3af;
    font-size: .7rem;
    word-break: break-word;
}

@media (max-width: 560px) {

    .error-page {
        padding: 14px;
    }

    .error-card {
        padding: 28px 18px;
        border-radius: 18px;
    }

    .error-actions {
        flex-direction: column;
    }

    .error-btn {
        width: 100%;
    }

}

</style>

</head>

<body>

<main class="error-page">

    <section class="error-card">

        <div class="error-brand">
            <i class="fa fa-graduation-cap"></i>
            <?= error_page_h($siteName) ?>
        </div>

        <div class="error-code">
            <?= error_page_h($errorCode) ?>
        </div>

        <div class="error-icon">
            <i class="fa <?= error_page_h($error['icon']) ?>"></i>
        </div>

        <h1>
            <?= error_page_h($error['heading']) ?>
        </h1>

        <p class="error-message">
            <?= error_page_h($error['message']) ?>
        </p>

        <div class="error-actions">

            <?php if ($errorCode === 419): ?>

                <button
                    type="button"
                    class="error-btn error-btn-primary"
                    onclick="window.location.reload()"
                >
                    <i class="fa fa-sync-alt"></i>
                    Refresh Page
                </button>

            <?php elseif (in_array($errorCode, [500, 502, 503, 429, 400], true)): ?>

                <button
                    type="button"
                    class="error-btn error-btn-primary"
                    onclick="window.location.reload()"
                >
                    <i class="fa fa-sync-alt"></i>
                    <?= error_page_h($error['action']) ?>
                </button>

            <?php else: ?>

                <a
                    href="<?= error_page_h($primaryUrl) ?>"
                    class="error-btn error-btn-primary"
                >
                    <i class="fa <?= $errorCode === 401 ? 'fa-sign-in-alt' : 'fa-home' ?>"></i>
                    <?= error_page_h($error['action']) ?>
                </a>

            <?php endif; ?>


            <button
                type="button"
                class="error-btn error-btn-secondary"
                onclick="
                    if (window.history.length > 1) {
                        window.history.back();
                    } else {
                        window.location.href = <?= json_encode($homeUrl) ?>;
                    }
                "
            >
                <i class="fa fa-arrow-left"></i>
                Go Back
            </button>

        </div>


        <div class="error-help">

            <?php if (
                $siteEmail !== ''
                && filter_var(
                    $siteEmail,
                    FILTER_VALIDATE_EMAIL
                )
            ): ?>

                If the problem continues, contact
                <a
                    href="mailto:<?= error_page_h($siteEmail) ?>"
                >
                    <?= error_page_h($siteEmail) ?>
                </a>.

            <?php else: ?>

                If the problem continues,
                please contact the programme team.

            <?php endif; ?>

            <?php if ($requestUri !== ''): ?>

                <span class="error-reference">
                    Error reference:
                    <?= error_page_h($errorCode) ?>
                    .
                    <?= error_page_h($requestUri) ?>
                </span>

            <?php endif; ?>

        </div>

    </section>

</main>

</body>
</html>
