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


$token = trim(
    (string)(
        $_GET['token']
        ?? $_POST['token']
        ?? ''
    )
);

$email = strtolower(
    trim(
        (string)(
            $_GET['email']
            ?? $_POST['email']
            ?? ''
        )
    )
);


$error = (string)(
    $_SESSION['reset_error']
    ?? ''
);

unset(
    $_SESSION['reset_error']
);


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
        'hello@example.com'
    )
    : 'hello@example.com';

$logo_path = 'assets/images/logo_white.png';

$logo_exists = file_exists(
    __DIR__ . '/' . $logo_path
);


/*
|--------------------------------------------------------------------------
| Validate token
|--------------------------------------------------------------------------
*/
$tokenValid = false;

if (
    $token !== '' &&
    $email !== '' &&
    filter_var(
        $email,
        FILTER_VALIDATE_EMAIL
    )
) {

    $tokenHash = hash(
        'sha256',
        $token
    );

    $stmt = $conn->prepare("
        SELECT
            vpr.id,
            vpr.access_id
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

    if ($stmt) {

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

        $tokenValid = !empty($reset);
    }
}
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
    Reset Password - <?= h($site_name) ?>
</title>

<link
    rel="icon"
    type="image/png"
    href="assets/images/favicon.png"
>

<link
    href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css"
>

<link
    rel="stylesheet"
    href="assets/css/ventures.css"
>

<style>

.reset-icon {
    width:64px;
    height:64px;
    border-radius:20px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:rgba(42,108,244,.1);
    color:var(--primary,#2a6cf4);
    font-size:25px;
    margin-bottom:22px;
}

.password-strength {
    margin-top:10px;
    display:none;
}

.password-strength.show {
    display:block;
}

.strength-bar {
    height:5px;
    border-radius:20px;
    background:#e5e7eb;
    overflow:hidden;
}

.strength-fill {
    display:block;
    width:0;
    height:100%;
    background:#94a3b8;
    transition:
        width .25s ease,
        background .25s ease;
}

.strength-text {
    display:block;
    margin-top:6px;
    color:#64748b;
    font-size:12px;
}

.password-rules {
    padding:14px 16px;
    border-radius:12px;
    background:#f8fafc;
    margin:15px 0 22px;
    font-size:12px;
    color:#64748b;
    line-height:1.8;
}

.password-rules div {
    display:flex;
    align-items:center;
    gap:8px;
}

.password-rules i {
    width:13px;
}

.password-rules .valid {
    color:#16a34a;
}

.invalid-reset {
    text-align:center;
    padding:15px 0;
}

.invalid-reset-icon {
    width:75px;
    height:75px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    margin:0 auto 20px;
    font-size:28px;
    background:#fef2f2;
    color:#dc2626;
}

.invalid-reset h3 {
    margin:0 0 10px;
    color:#111827;
}

.invalid-reset p {
    color:#64748b;
    line-height:1.6;
    margin-bottom:25px;
}

.back-login {
    display:inline-flex;
    gap:8px;
    align-items:center;
    text-decoration:none;
    font-weight:600;
    color:var(--primary,#2a6cf4);
    margin-top:20px;
}

</style>

</head>


<body>

<div class="left-panel">

    <div class="lp-top">

        <div class="lp-wordmark">

        <?php if ($logo_exists): ?>

            <img
                src="<?= h($logo_path) ?>"
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

        </div>


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

    </div>

</div>


<div class="right-panel">

<div class="login-box">


<?php if (!$tokenValid): ?>


    <div class="invalid-reset">

        <div class="invalid-reset-icon">
            <i class="fa fa-link"></i>
        </div>

        <h2 class="login-title">
            Reset link unavailable
        </h2>

        <p>
            This password reset link is invalid,
            has already been used or has expired.
            Request a new password reset link to continue.
        </p>

        <a
            href="forgot-password.php"
            class="btn-login"
            style="text-decoration:none;"
        >

            <span class="btn-text">
                Request New Reset Link
            </span>

            <i class="fa fa-arrow-right btn-arrow"></i>

        </a>


        <a
            href="login.php"
            class="back-login"
        >

            <i class="fa fa-arrow-left"></i>

            Back to Sign In

        </a>

    </div>


<?php else: ?>


    <div class="reset-icon">
        <i class="fa fa-lock"></i>
    </div>


    <div class="login-tag">

        <i class="fa fa-shield-alt"></i>

        Secure Password Reset

    </div>


    <h2 class="login-title">
        Create a new password
    </h2>


    <p class="login-subtitle">
        Choose a strong password that you have not
        previously used for your venture portal.
    </p>


    <?php if ($error !== ''): ?>

        <div class="error-box">

            <i class="fa fa-exclamation-circle"></i>

            <?= h($error) ?>

        </div>

    <?php endif; ?>


    <form
        method="POST"
        action="includes/process-reset-password.php"
        id="resetForm"
    >

        <input
            type="hidden"
            name="token"
            value="<?= h($token) ?>"
        >

        <input
            type="hidden"
            name="email"
            value="<?= h($email) ?>"
        >


        <div class="form-group">

            <label
                class="form-label"
                for="password"
            >
                New Password
            </label>


            <div class="password-wrap">

                <input
                    type="password"
                    id="password"
                    name="password"
                    class="form-control"
                    placeholder="Enter new password"
                    minlength="8"
                    autocomplete="new-password"
                    required
                >

                <button
                    type="button"
                    class="pw-toggle"
                    onclick="togglePassword(
                        'password',
                        'passwordIcon'
                    )"
                    aria-label="Show password"
                >

                    <i
                        class="fa fa-eye"
                        id="passwordIcon"
                    ></i>

                </button>

            </div>


            <div
                class="password-strength"
                id="passwordStrength"
            >

                <div class="strength-bar">

                    <span
                        class="strength-fill"
                        id="strengthFill"
                    ></span>

                </div>

                <span
                    class="strength-text"
                    id="strengthText"
                >
                    Password strength
                </span>

            </div>

        </div>


        <div class="form-group">

            <label
                class="form-label"
                for="password_confirmation"
            >
                Confirm New Password
            </label>


            <div class="password-wrap">

                <input
                    type="password"
                    id="password_confirmation"
                    name="password_confirmation"
                    class="form-control"
                    placeholder="Repeat new password"
                    minlength="8"
                    autocomplete="new-password"
                    required
                >

                <button
                    type="button"
                    class="pw-toggle"
                    onclick="togglePassword(
                        'password_confirmation',
                        'confirmIcon'
                    )"
                    aria-label="Show confirmation password"
                >

                    <i
                        class="fa fa-eye"
                        id="confirmIcon"
                    ></i>

                </button>

            </div>

        </div>


        <div class="password-rules">

            <div id="ruleLength">

                <i class="fa fa-circle"></i>

                At least 8 characters

            </div>

            <div id="ruleUpper">

                <i class="fa fa-circle"></i>

                At least one uppercase letter

            </div>

            <div id="ruleLower">

                <i class="fa fa-circle"></i>

                At least one lowercase letter

            </div>

            <div id="ruleNumber">

                <i class="fa fa-circle"></i>

                At least one number

            </div>

        </div>


        <button
            type="submit"
            class="btn-login"
            id="resetBtn"
        >

            <i class="fa fa-spinner spinner"></i>

            <span class="btn-text">
                Update Password
            </span>

            <i class="fa fa-check btn-arrow"></i>

        </button>

    </form>


    <div style="text-align:center;">

        <a
            href="login.php"
            class="back-login"
        >

            <i class="fa fa-arrow-left"></i>

            Back to Sign In

        </a>

    </div>


<?php endif; ?>


    <div class="login-divider">
        Need help?
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

function togglePassword(fieldId, iconId) {

    const field =
        document.getElementById(fieldId);

    const icon =
        document.getElementById(iconId);

    if (!field || !icon) {
        return;
    }

    if (field.type === 'password') {

        field.type = 'text';

        icon.className =
            'fa fa-eye-slash';

    } else {

        field.type = 'password';

        icon.className =
            'fa fa-eye';
    }
}


const password =
    document.getElementById('password');

const strength =
    document.getElementById('passwordStrength');

const fill =
    document.getElementById('strengthFill');

const strengthText =
    document.getElementById('strengthText');


function updateRule(id, valid) {

    const element =
        document.getElementById(id);

    if (!element) return;

    const icon =
        element.querySelector('i');

    if (valid) {

        element.classList.add('valid');

        if (icon) {
            icon.className =
                'fa fa-check-circle';
        }

    } else {

        element.classList.remove('valid');

        if (icon) {
            icon.className =
                'fa fa-circle';
        }
    }
}


password?.addEventListener(
    'input',
    function () {

        const value = this.value;

        if (value.length > 0) {
            strength?.classList.add('show');
        } else {
            strength?.classList.remove('show');
        }

        const lengthValid =
            value.length >= 8;

        const upperValid =
            /[A-Z]/.test(value);

        const lowerValid =
            /[a-z]/.test(value);

        const numberValid =
            /\d/.test(value);

        updateRule(
            'ruleLength',
            lengthValid
        );

        updateRule(
            'ruleUpper',
            upperValid
        );

        updateRule(
            'ruleLower',
            lowerValid
        );

        updateRule(
            'ruleNumber',
            numberValid
        );


        let score = 0;

        if (lengthValid) score++;
        if (upperValid) score++;
        if (lowerValid) score++;
        if (numberValid) score++;
        if (/[^A-Za-z0-9]/.test(value)) score++;


        let width = 0;
        let text = 'Weak';

        if (score <= 1) {

            width = 20;
            text = 'Very weak';

        } else if (score === 2) {

            width = 40;
            text = 'Weak';

        } else if (score === 3) {

            width = 60;
            text = 'Fair';

        } else if (score === 4) {

            width = 80;
            text = 'Strong';

        } else {

            width = 100;
            text = 'Very strong';
        }


        if (fill) {

            fill.style.width =
                width + '%';

            if (score <= 2) {

                fill.style.background =
                    '#dc2626';

            } else if (score === 3) {

                fill.style.background =
                    '#f59e0b';

            } else {

                fill.style.background =
                    '#16a34a';
            }
        }


        if (strengthText) {

            strengthText.textContent =
                'Password strength: '
                + text;
        }
    }
);


document
    .getElementById('resetForm')
    ?.addEventListener(
        'submit',
        function (event) {

            const newPassword =
                document
                    .getElementById('password')
                    ?.value ?? '';

            const confirmPassword =
                document
                    .getElementById(
                        'password_confirmation'
                    )
                    ?.value ?? '';

            if (
                newPassword !==
                confirmPassword
            ) {

                event.preventDefault();

                alert(
                    'The passwords do not match.'
                );

                return;
            }


            const btn =
                document.getElementById(
                    'resetBtn'
                );

            if (btn) {

                btn.classList.add(
                    'loading'
                );

                btn.disabled = true;
            }
        }
    );

</script>

</body>
</html>