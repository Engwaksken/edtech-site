<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection error.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($value): string
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
| Already logged in
|--------------------------------------------------------------------------
*/
if (
    !empty($_SESSION['venture_login']) &&
    !empty($_SESSION['venture_id'])
) {
    header('Location: dashboard.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Flash messages
|--------------------------------------------------------------------------
*/
$error = (string)($_SESSION['forgot_error'] ?? '');
$success = (string)($_SESSION['forgot_success'] ?? '');
$old_email = (string)($_SESSION['forgot_old_email'] ?? '');

unset(
    $_SESSION['forgot_error'],
    $_SESSION['forgot_success'],
    $_SESSION['forgot_old_email']
);

/*
|--------------------------------------------------------------------------
| Site settings
|--------------------------------------------------------------------------
*/
$site_name = function_exists('get_setting')
    ? get_setting(
        $conn,
        'site_name',
        'EdTech Fellowship'
    )
    : 'EdTech Fellowship';

$hero_tagline = function_exists('get_setting')
    ? get_setting(
        $conn,
        'hero_subtitle',
        'Building Africa\'s next generation of EdTech ventures'
    )
    : 'Building Africa\'s next generation of EdTech ventures';

$contact_email = function_exists('get_setting')
    ? get_setting(
        $conn,
        'site_email',
        'edtech@hivecolab.com'
    )
    : 'edtech@hivecolab.com';

$logo_path = is_file(__DIR__ . '/assets/images/logo_white.webp') ? 'assets/images/logo_white.webp' : 'assets/images/logo_white.png';
$logo_exists = file_exists(__DIR__ . '/' . $logo_path);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Forgot Password - <?= h($site_name) ?>
    </title>

    <link
        rel="icon"
        type="image/png"
        href="assets/images/favicon.png"
    >

    <link
        rel="shortcut icon"
        type="image/png"
        href="assets/images/favicon.png"
    >

    <link
        rel="apple-touch-icon"
        href="assets/images/favicon.png"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="<?= h(asset_url('assets/vendor/fontawesome/css/all.min.css')) ?>"
    >

    <link
        rel="stylesheet"
        href="<?= h(asset_url('assets/css/ventures.css')) ?>?v=<?= (int)filemtime(__DIR__ . '/assets/css/ventures.css') ?>"
    >

    <style>
        .forgot-icon-wrap {
            width: 64px;
            height: 64px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(42, 108, 244, 0.10);
            color: var(--primary, #2a6cf4);
            font-size: 25px;
            margin-bottom: 22px;
        }

        .forgot-description {
            margin-bottom: 28px;
        }

        .forgot-description strong {
            color: #111827;
        }

        .back-login {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            margin-top: 20px;
            color: var(--primary, #2a6cf4);
            transition:
                transform .2s ease,
                opacity .2s ease;
        }

        .back-login:hover {
            transform: translateX(-3px);
        }

        .forgot-note {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 14px 16px;
            margin-top: 18px;
            border-radius: 12px;
            background: #f8fafc;
            color: #64748b;
            font-size: 13px;
            line-height: 1.6;
        }

        .forgot-note i {
            color: #64748b;
            margin-top: 3px;
        }

        .forgot-note p {
            margin: 0;
        }

        .btn-login.loading .btn-text,
        .btn-login.loading .btn-arrow {
            opacity: .65;
        }

        @media (max-width: 768px) {
            .forgot-icon-wrap {
                width: 56px;
                height: 56px;
                border-radius: 17px;
                font-size: 22px;
                margin-bottom: 18px;
            }

            .forgot-description {
                margin-bottom: 22px;
            }
        }
    </style>
</head>

<body>

<div class="left-panel">

    <div class="lp-top">

        <a class="lp-wordmark" href="<?= h(rtrim(SITE_URL, '/')) ?>/" aria-label="Website home">

            <?php if ($logo_exists): ?>

                <img
                    src="<?= h(asset_url($logo_path)) ?>"
                    alt="<?= h($site_name) ?>"
                    class="lp-logo"
                >

            <?php else: ?>

                <div class="lp-logo-fallback">

                    <i class="fa fa-graduation-cap"></i>

                    <span>
                        <?= h($site_name) ?>
                    </span>

                </div>

            <?php endif; ?>

        </a>

        <h1 class="lp-headline">
            Your venture.<br>
            <em>Your journey.</em><br>
            All in one place.
        </h1>

        <p class="lp-sub">
            <?= h($hero_tagline) ?>.
            Manage your profile, documents, team,
            mentorship sessions and investor connections
            from your dedicated dashboard.
        </p>

        <a class="lp-back-link" href="<?= h(rtrim(SITE_URL, '/')) ?>/">
            <i class="fas fa-arrow-left" aria-hidden="true"></i>
            Back to Website
        </a>

    </div>

</div>


<div class="right-panel">

    <div class="login-box">

        <div class="forgot-icon-wrap">
            <i class="fa fa-key"></i>
        </div>

        <div class="login-tag">

            <i class="fa fa-shield-alt"></i>

            Account Recovery

        </div>

        <h2 class="login-title">
            Forgot your password?
        </h2>

        <p class="login-subtitle forgot-description">
            Enter the email address associated with your
            venture account. We will send you a secure link
            to create a new password.
        </p>


        <?php if ($success !== ''): ?>

            <div
                class="success-box"
                role="alert"
            >

                <i class="fa fa-check-circle"></i>

                <div>
                    <?= h($success) ?>
                </div>

            </div>

        <?php endif; ?>


        <?php if ($error !== ''): ?>

            <div
                class="error-box"
                role="alert"
            >

                <i class="fa fa-exclamation-circle"></i>

                <div>
                    <?= h($error) ?>
                </div>

            </div>

        <?php endif; ?>


        <form
            method="POST"
            action="includes/process-forgot-password.php"
            id="forgotPasswordForm"
            autocomplete="on"
        >

            <div class="form-group">

                <label
                    class="form-label"
                    for="email"
                >
                    Email Address
                </label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    class="form-control"
                    placeholder="founder@venture.com"
                    value="<?= h($old_email) ?>"
                    autocomplete="email"
                    inputmode="email"
                    required
                    autofocus
                >

            </div>


            <button
                type="submit"
                class="btn-login"
                id="forgotBtn"
            >

                <i class="fa fa-spinner spinner"></i>

                <span class="btn-text">
                    Send Reset Link
                </span>

                <i class="fa fa-paper-plane btn-arrow"></i>

            </button>

        </form>


        <div class="forgot-note">

            <i class="fa fa-info-circle"></i>

            <p>
                For your security, the password reset link
                will expire after 60 minutes and can only be
                used once.
            </p>

        </div>


        <div style="text-align:center;">

            <a
                href="login.php"
                class="back-login"
            >

                <i class="fa fa-arrow-left"></i>

                Back to Sign In

            </a>

        </div>


        <div class="login-divider">
            Still having trouble?
        </div>


        <div class="login-help">

            Contact the programme team at

            <a
                href="mailto:<?= h($contact_email) ?>"
            >
                <?= h($contact_email) ?>
            </a>

        </div>

    </div>

</div>


<script>
(function () {
    const form = document.getElementById('forgotPasswordForm');
    const button = document.getElementById('forgotBtn');

    if (!form || !button) {
        return;
    }

    form.addEventListener('submit', function () {

        if (!form.checkValidity()) {
            return;
        }

        button.classList.add('loading');
        button.disabled = true;
    });
})();
</script>

</body>
</html>
