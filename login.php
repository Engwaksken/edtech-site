<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection error.');
}

$conn->set_charset('utf8mb4');



if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return htmlspecialchars(
            (string)($value ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}



if (!function_exists('safe_redirect_path')) {
    function safe_redirect_path(string $redirect): string
    {
        $redirect = trim($redirect);

        if ($redirect === '') {
            return '/dashboard';
        }

       
        $decoded = rawurldecode($redirect);

        if ($decoded !== '') {
            $redirect = $decoded;
        }

        if (preg_match('/[\r\n]/', $redirect)) {
            return '/dashboard';
        }

       
        if (preg_match('#^https?://#i', $redirect)) {

            $redirectHost = strtolower(
                (string)(parse_url($redirect, PHP_URL_HOST) ?? '')
            );

            $siteHost = '';

            if (
                defined('SITE_URL')
                && trim((string)SITE_URL) !== ''
            ) {
                $siteHost = strtolower(
                    (string)(parse_url(
                        (string)SITE_URL,
                        PHP_URL_HOST
                    ) ?? '')
                );
            }

         
            if ($siteHost === '') {
                $siteHost = strtolower(
                    preg_replace(
                        '/:\d+$/',
                        '',
                        (string)($_SERVER['HTTP_HOST'] ?? '')
                    )
                );
            }

            if (
                $redirectHost === ''
                || $siteHost === ''
                || $redirectHost !== $siteHost
            ) {
                return '/dashboard';
            }

            $path = (string)(
                parse_url($redirect, PHP_URL_PATH)
                ?? ''
            );

            $query = (string)(
                parse_url($redirect, PHP_URL_QUERY)
                ?? ''
            );

            $redirect = $path;

            if ($query !== '') {
                $redirect .= '?' . $query;
            }
        }

       
        if (str_starts_with($redirect, '//')) {
            return '/dashboard';
        }

      
        $parts = parse_url($redirect);

        if ($parts === false) {
            return '/dashboard';
        }

        $path = trim(
            (string)($parts['path'] ?? '')
        );

        $query = (string)(
            $parts['query'] ?? ''
        );

        if ($path === '') {
            return '/dashboard';
        }

       
        $decodedPath = rawurldecode($path);

        if (
            str_contains($path, '..')
            || str_contains($decodedPath, '..')
            || str_contains($path, '\\')
        ) {
            return '/dashboard';
        }

      
        $path = '/' . ltrim($path, '/');

    
        $path = preg_replace(
            '#\.php$#i',
            '',
            $path
        ) ?? $path;

      
        if (
            preg_match(
                '#^/(?:includes|admin/includes)(?:/|$)#i',
                $path
            )
        ) {
            return '/dashboard';
        }

     
        if (
            preg_match(
                '#^/(?:login|verify-otp|forgot-password)(?:/|$)#i',
                $path
            )
        ) {
            return '/dashboard';
        }

        $result = $path;

        if ($query !== '') {
            $result .= '?' . $query;
        }

        return $result;
    }
}


$requestedRedirect = '';

if (
    isset($_GET['next'])
    && trim((string)$_GET['next']) !== ''
) {
    $requestedRedirect =
        (string)$_GET['next'];

} elseif (
    isset($_GET['redirect'])
    && trim((string)$_GET['redirect']) !== ''
) {
    $requestedRedirect =
        (string)$_GET['redirect'];

} elseif (
    isset($_POST['next'])
    && trim((string)$_POST['next']) !== ''
) {
    $requestedRedirect =
        (string)$_POST['next'];

} elseif (
    isset($_POST['redirect'])
    && trim((string)$_POST['redirect']) !== ''
) {
    $requestedRedirect =
        (string)$_POST['redirect'];

} elseif (
    !empty($_SESSION['after_login_redirect'])
) {
    $requestedRedirect =
        (string)$_SESSION['after_login_redirect'];

} else {
    $requestedRedirect = '/dashboard';
}


$redirect =
    safe_redirect_path(
        $requestedRedirect
    );



$_SESSION['after_login_redirect'] =
    $redirect;


if (
    !empty($_SESSION['venture_login'])
    && !empty($_SESSION['venture_id'])
) {
    $destination =
        safe_redirect_path(
            (string)(
                $_SESSION['after_login_redirect']
                ?? $redirect
            )
        );

    unset(
        $_SESSION['after_login_redirect']
    );

    header(
        'Location: ' . $destination
    );

    exit;
}



$error =
    (string)(
        $_SESSION['login_error']
        ?? ''
    );

$success =
    (string)(
        $_SESSION['login_success']
        ?? ''
    );

$old_email =
    (string)(
        $_SESSION['login_old_email']
        ?? ''
    );

unset(
    $_SESSION['login_error'],
    $_SESSION['login_success'],
    $_SESSION['login_old_email']
);



$site_name =
    function_exists('get_setting')
        ? get_setting(
            $conn,
            'site_name',
            'EdTech Fellowship'
        )
        : 'EdTech Fellowship';


$hero_tagline =
    function_exists('get_setting')
        ? get_setting(
            $conn,
            'hero_subtitle',
            'Building Africa\'s next generation of EdTech ventures'
        )
        : 'Building Africa\'s next generation of EdTech ventures';


$contact_email =
    function_exists('get_setting')
        ? get_setting(
            $conn,
            'site_email',
            'edtech@hivecolab.com'
        )
        : 'edtech@hivecolab.com';


$logo_path =
    is_file(__DIR__ . '/assets/images/logo_white.webp') ? 'assets/images/logo_white.webp' : 'assets/images/logo_white.png';

$logo_exists =
    file_exists(
        __DIR__ . '/' . $logo_path
    );

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1.0"
>

<title>
    Venture Portal - <?= h($site_name) ?>
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
    href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=Instrument+Serif:ital@0;1&display=swap"
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

</head>


<body>


<!-- ==========================================================
     LEFT PANEL
     ========================================================== -->

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

            <em>
                Your journey.
            </em><br>

            All in one place.

        </h1>


        <p class="lp-sub">

            <?= h($hero_tagline) ?>.

            Manage your profile, documents,
            team, mentorship sessions, and
            investor connections from your
            dedicated dashboard.

        </p>

        <a class="lp-back-link" href="<?= h(rtrim(SITE_URL, '/')) ?>/">
            <i class="fas fa-arrow-left" aria-hidden="true"></i>
            Back to Website
        </a>

    </div>

</div>


<!-- ==========================================================
     LOGIN PANEL
     ========================================================== -->

<div class="right-panel">

    <div class="login-box">


        <div class="login-tag">

            <i class="fa fa-lock"></i>

            Founder Access

        </div>


        <h2 class="login-title">
            Welcome back
        </h2>


        <p class="login-subtitle">

            Sign in with your email and password.

            We will then send a one-time
            verification code to your email
            before opening your venture dashboard.

        </p>


        <!-- ==================================================
             FLASH MESSAGES
             ================================================== -->

        <?php if ($success !== ''): ?>

            <div class="success-box">

                <i class="fa fa-check-circle"></i>

                <?= h($success) ?>

            </div>

        <?php endif; ?>


        <?php if ($error !== ''): ?>

            <div class="error-box">

                <i class="fa fa-exclamation-circle"></i>

                <?= h($error) ?>

            </div>

        <?php endif; ?>


        <!-- ==================================================
             LOGIN FORM
             ================================================== -->

        <form
            method="POST"
            action="includes/process-login.php"
            id="loginForm"
            autocomplete="on"
        >
            <input type="hidden" name="site_csrf_token" value="<?= h(site_csrf_token()) ?>">

      
            <input
                type="hidden"
                name="next"
                value="<?= h($redirect) ?>"
            >

            <input
                type="hidden"
                name="redirect"
                value="<?= h($redirect) ?>"
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
                    required
                    autofocus
                >

            </div>


            <div class="form-group">

                <label
                    class="form-label"
                    for="password"
                >
                    Password
                </label>


                <div class="password-wrap">

                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control"
                        placeholder="Password"
                        autocomplete="current-password"
                        required
                    >


                    <button
                        type="button"
                        class="pw-toggle"
                        id="passwordToggle"
                        aria-label="Show password"
                        aria-pressed="false"
                    >

                        <i
                            class="fa fa-eye"
                            id="pwIcon"
                        ></i>

                    </button>

                </div>

            </div>


            <div class="form-footer">

                <label class="remember-label">

                    <input
                        type="checkbox"
                        name="remember"
                        value="1"
                    >

                    Remember me

                </label>


                <a
                    href="/forgot-password"
                    class="forgot-link"
                >
                    Forgot password?
                </a>

            </div>


            <button
                type="submit"
                class="btn-login"
                id="loginBtn"
            >

                <i
                    class="fa fa-spinner fa-spin spinner"
                    aria-hidden="true"
                ></i>

                <span class="btn-text">
                    Continue
                </span>

                <i
                    class="fa fa-arrow-right btn-arrow"
                    aria-hidden="true"
                ></i>

            </button>

        </form>


        <div class="login-divider">

            Secure two-step verification

        </div>


        <div class="login-help">

            After your password is confirmed,
            a 6-digit OTP will be sent to your
            registered email address.

            Need help? Contact

            <a href="mailto:<?= h($contact_email) ?>">
                <?= h($contact_email) ?>
            </a>.

        </div>


    </div>

</div>


<script>
(function () {

    'use strict';


   
    const passwordField =
        document.getElementById('password');

    const passwordToggle =
        document.getElementById('passwordToggle');

    const passwordIcon =
        document.getElementById('pwIcon');


    if (
        passwordField &&
        passwordToggle &&
        passwordIcon
    ) {

        passwordToggle.addEventListener(
            'click',
            function () {

                const showing =
                    passwordField.type === 'text';


                passwordField.type =
                    showing
                        ? 'password'
                        : 'text';


                passwordIcon.className =
                    showing
                        ? 'fa fa-eye'
                        : 'fa fa-eye-slash';


                passwordToggle.setAttribute(
                    'aria-pressed',
                    showing
                        ? 'false'
                        : 'true'
                );


                passwordToggle.setAttribute(
                    'aria-label',
                    showing
                        ? 'Show password'
                        : 'Hide password'
                );

            }
        );

    }


   
    const loginForm =
        document.getElementById('loginForm');

    const loginButton =
        document.getElementById('loginBtn');


    if (
        loginForm &&
        loginButton
    ) {

        loginForm.addEventListener(
            'submit',
            function () {

            
                loginButton.classList.add(
                    'loading'
                );

                loginButton.disabled = true;

                loginButton.setAttribute(
                    'aria-busy',
                    'true'
                );

            }
        );

    }

})();
</script>


</body>

</html>
