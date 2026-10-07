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

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

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

function vp_mask_email_view(string $email): string
{
    if (!str_contains($email, '@')) {
        return $email;
    }

    [$name, $domain] = explode('@', $email, 2);

    $length = strlen($name);

    if ($length <= 2) {
        $maskedName = substr($name, 0, 1) . '*';
    } else {
        $maskedName =
            substr($name, 0, 2)
            . str_repeat('*', max(1, $length - 2));
    }

    return $maskedName . '@' . $domain;
}

/*
|--------------------------------------------------------------------------
| Redirect logged-in venture
|--------------------------------------------------------------------------
*/

if (
    !empty($_SESSION['venture_login'])
    && !empty($_SESSION['venture_id'])
) {
    header('Location: dashboard');
    exit;
}

/*
|--------------------------------------------------------------------------
| Pending OTP session
|--------------------------------------------------------------------------
*/

$pending = $_SESSION['venture_otp_pending'] ?? null;

if (
    !is_array($pending)
    || empty($pending['access_id'])
    || empty($pending['login_email'])
) {
    $_SESSION['login_error'] =
        'Please sign in first to receive a verification code.';

    header('Location: login');
    exit;
}

/*
|--------------------------------------------------------------------------
| OTP validity: 5 minutes
|--------------------------------------------------------------------------
|
| The current OTP expires 300 seconds after it was sent.
|--------------------------------------------------------------------------
*/

$lastSentAt = (int)(
    $pending['last_sent_at']
    ?? 0
);

if ($lastSentAt > 0) {
    $otpExpiresAt = $lastSentAt + 300;
} else {
    /*
     * Fallback for older OTP sessions that only contain expires_at.
     */
    $storedExpiresAt = (int)(
        $pending['expires_at']
        ?? 0
    );

    $otpExpiresAt = $storedExpiresAt > 0
        ? min(
            $storedExpiresAt,
            time() + 300
        )
        : 0;
}

if (
    $otpExpiresAt <= 0
    || $otpExpiresAt <= time()
) {
    unset($_SESSION['venture_otp_pending']);

    $_SESSION['login_error'] =
        'Your verification code expired after 5 minutes. Please sign in again.';

    header('Location: login');
    exit;
}

/*
|--------------------------------------------------------------------------
| Flash messages
|--------------------------------------------------------------------------
*/

$error = (string)(
    $_SESSION['otp_error']
    ?? ''
);

$success = (string)(
    $_SESSION['otp_success']
    ?? $_SESSION['login_success']
    ?? ''
);

unset(
    $_SESSION['otp_error'],
    $_SESSION['otp_success'],
    $_SESSION['login_success']
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

$contact_email = function_exists('get_setting')
    ? get_setting(
        $conn,
        'site_email',
        'hello@example.com'
    )
    : 'hello@example.com';

/*
|--------------------------------------------------------------------------
| Logo
|--------------------------------------------------------------------------
*/

$logo_path = 'assets/images/logo_white.png';

$logo_exists = file_exists(
    __DIR__ . '/' . $logo_path
);

/*
|--------------------------------------------------------------------------
| User email
|--------------------------------------------------------------------------
*/

$email = (string)$pending['login_email'];

$maskedEmail = vp_mask_email_view(
    $email
);

/*
|--------------------------------------------------------------------------
| Resend cooldown: 60 seconds
|--------------------------------------------------------------------------
|
| The countdown is intentionally NOT shown to the user.
| The button stays disabled until the cooldown expires.
|--------------------------------------------------------------------------
*/

$resendSecondsLeft = $lastSentAt > 0
    ? max(
        0,
        60 - (
            time() - $lastSentAt
        )
    )
    : 0;

/*
|--------------------------------------------------------------------------
| Remaining OTP validity
|--------------------------------------------------------------------------
*/

$otpSecondsLeft = max(
    0,
    $otpExpiresAt - time()
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
    Verify Login - <?= h($site_name) ?>
</title>

<link
    href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=Instrument+Serif:ital@0;1&display=swap"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="assets/css/ventures.css"
>

<style>

.otp-input {
    width: 100%;
    height: 58px;
    border: 1px solid #d9dce3;
    border-radius: 12px;
    padding: 0 16px;
    letter-spacing: .35em;
    text-align: left;
    font-size: 24px;
    font-weight: 800;
    font-family: 'Outfit', sans-serif;
    outline: none;
    box-sizing: border-box;
    transition: border-color .2s ease, box-shadow .2s ease;
}

.otp-input:focus {
    border-color: #ff6b2c;
    box-shadow:
        0 0 0 3px rgba(255, 107, 44, .12);
}

.otp-input:disabled {
    background: #f3f4f6;
    color: #9ca3af;
    cursor: not-allowed;
}

.otp-meta {
    margin: 14px 0 0;
    color: #6b7280;
    font-size: 14px;
    line-height: 1.6;
}

.otp-expiry {
    margin-top: 6px;

    color: #e85b20;

    font-weight: 700;
}

.otp-expiry.expired {
    color: #b91c1c;
}

.otp-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 14px;

    margin-top: 18px;

    flex-wrap: wrap;
}

.otp-link-btn {
    border: 0;

    background: transparent;

    padding: 0;

    color: #e85b20;

    font: inherit;
    font-weight: 700;

    cursor: pointer;

    transition: opacity .2s ease;
}

.otp-link-btn:disabled {
    opacity: .5;
    cursor: not-allowed;
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

                    <span>
                        <?= h($site_name) ?>
                    </span>

                </div>

            <?php endif; ?>

        </div>

        <h1 class="lp-headline">

            Secure access.<br>

            <em>
                One more step.
            </em>

        </h1>

        <p class="lp-sub">
            We use email verification to help protect venture accounts
            and programme information.
        </p>

    </div>

</div>


<div class="right-panel">

    <div class="login-box">

        <div class="login-tag">

            Verification

        </div>

        <h2 class="login-title">
            Enter verification code
        </h2>

      <p class="login-subtitle">
    We sent a 6-digit verification code to
    <strong><?= h($maskedEmail) ?></strong>.
    The code expires in
    <strong>5 minutes</strong>.
</p>

        <?php if ($success !== ''): ?>

            <div class="success-box">

                <?= h($success) ?>

            </div>

        <?php endif; ?>


        <?php if ($error !== ''): ?>

            <div class="error-box">

                <?= h($error) ?>

            </div>

        <?php endif; ?>


        <form
            method="POST"
            action="includes/process-venture-otp.php"
            id="otpForm"
            autocomplete="one-time-code"
        >

            <input
                type="hidden"
                name="action"
                value="verify"
            >

            <div class="form-group">

                <label class="form-label">
                    Verification Code
                </label>


                <input
                    type="text"
                    name="otp"
                    id="otpInput"
                    class="otp-input"
                    inputmode="numeric"
                    pattern="[0-9]{6}"
                    maxlength="6"
                    autocomplete="one-time-code"
                    aria-label="6-digit verification code"
                    placeholder="Enter 6-digit code"
                    required
                    autofocus
                >


                <div class="otp-meta">

                    For your security, do not share this verification
                    code with anyone.

                    <div
                        class="otp-expiry"
                        id="otpExpiry"
                    >

                        <span id="otpExpiryText">

                            Code expires in

                            <?= (int)floor(
                                $otpSecondsLeft / 60
                            ) ?>:<?= str_pad(
                                (string)(
                                    $otpSecondsLeft % 60
                                ),
                                2,
                                '0',
                                STR_PAD_LEFT
                            ) ?>.

                        </span>

                    </div>

                </div>

            </div>


            <button
                type="submit"
                class="btn-login"
                id="verifyBtn"
            >

                <span class="btn-text">
                    Verify &amp; Continue
                </span>

            </button>

        </form>


        <div class="otp-actions">

            <form
                method="POST"
                action="includes/process-venture-otp.php"
                id="resendForm"
            >

                <input
                    type="hidden"
                    name="action"
                    value="resend"
                >

                <button
                    type="submit"
                    class="otp-link-btn"
                    id="resendBtn"
                    <?= $resendSecondsLeft > 0
                        ? 'disabled'
                        : '' ?>
                >

                    <span id="resendText">
                        Resend code
                    </span>

                </button>

            </form>


            <a
                href="login"
                class="forgot-link"
            >

                Back to login

            </a>

        </div>


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

const otpInput = document.getElementById('otpInput');

/*
|--------------------------------------------------------------------------
| Combine OTP digits
|--------------------------------------------------------------------------
*/

function syncOtp() {
    otpInput.value = otpInput.value.replace(/\D/g, '').slice(0, 6);
    return otpInput.value;
}


/*
|--------------------------------------------------------------------------
| OTP input behaviour
|--------------------------------------------------------------------------
*/

otpInput?.addEventListener('input', syncOtp);


/*
|--------------------------------------------------------------------------
| OTP validity countdown
|--------------------------------------------------------------------------
|
| This is the ONLY visible timer on the page.
|--------------------------------------------------------------------------
*/

let otpRemaining =
    <?= (int)$otpSecondsLeft ?>;

const otpExpiry =
    document.getElementById(
        'otpExpiry'
    );

const otpExpiryText =
    document.getElementById(
        'otpExpiryText'
    );


function updateOtpExpiry() {

    if (!otpExpiryText) {
        return;
    }


    if (otpRemaining <= 0) {

        otpRemaining = 0;

        otpExpiryText.textContent =
            'Verification code expired. Please request a new code.';

        if (otpExpiry) {
            otpExpiry.classList.add(
                'expired'
            );
        }


        if (otpInput) otpInput.disabled = true;


        const verifyBtn =
            document.getElementById(
                'verifyBtn'
            );

        if (verifyBtn) {
            verifyBtn.disabled = true;
        }

        return;
    }


    const minutes =
        Math.floor(
            otpRemaining / 60
        );


    const seconds =
        otpRemaining % 60;


    otpExpiryText.textContent =
        `Code expires in ${
            minutes
        }:${
            String(seconds)
                .padStart(2, '0')
        }.`;

}


updateOtpExpiry();


if (otpRemaining > 0) {

    const otpTimer =
        setInterval(
            () => {

                otpRemaining--;

                updateOtpExpiry();


                if (
                    otpRemaining <= 0
                ) {
                    clearInterval(
                        otpTimer
                    );
                }

            },
            1000
        );

}


/*
|--------------------------------------------------------------------------
| Verify form
|--------------------------------------------------------------------------
*/

document
    .getElementById(
        'otpForm'
    )
    ?.addEventListener(
        'submit',
        event => {

            const value =
                syncOtp();


            if (
                value.length !== 6
            ) {

                event.preventDefault();

                alert(
                    'Please enter the complete 6-digit verification code.'
                );

                return;

            }


            if (
                otpRemaining <= 0
            ) {

                event.preventDefault();

                alert(
                    'This verification code has expired. Please request a new code.'
                );

                return;

            }


            const btn =
                document.getElementById(
                    'verifyBtn'
                );

            if (btn) {

                btn.classList.add(
                    'loading'
                );

                btn.disabled = true;

            }

        }
    );


/*
|--------------------------------------------------------------------------
| Hidden resend cooldown
|--------------------------------------------------------------------------
|
| Keep the resend button disabled for 60 seconds, but do not display
| "Resend in 44s", "Resend in 43s", etc.
|--------------------------------------------------------------------------
*/

let resendRemaining =
    <?= (int)$resendSecondsLeft ?>;

const resendBtn =
    document.getElementById(
        'resendBtn'
    );


if (
    resendRemaining > 0
    && resendBtn
) {

    resendBtn.disabled = true;


    const resendTimer =
        setInterval(
            () => {

                resendRemaining--;


                if (
                    resendRemaining <= 0
                ) {

                    clearInterval(
                        resendTimer
                    );

                    resendBtn.disabled =
                        false;

                }

            },
            1000
        );

}


/*
|--------------------------------------------------------------------------
| Prevent repeated resend clicks
|--------------------------------------------------------------------------
*/

document
    .getElementById(
        'resendForm'
    )
    ?.addEventListener(
        'submit',
        () => {

            if (resendBtn) {
                resendBtn.disabled = true;
            }

        }
    );

</script>

</body>

</html>
