
<?php
//declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    empty($_SESSION['venture_login']) ||
    empty($_SESSION['venture_access_id'])
) {
    header('Location: login.php');
    exit;
}

if (!function_exists('h')) {
    function h($v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$error   = $_SESSION['cp_error'] ?? '';
$success = $_SESSION['cp_success'] ?? '';

unset(
    $_SESSION['cp_error'],
    $_SESSION['cp_success']
);

$logo     = 'assets/images/logo.png';
$favicon  = 'assets/images/favicon.png';

$logo_exists    = file_exists(__DIR__ . '/' . $logo);
$favicon_exists = file_exists(__DIR__ . '/' . $favicon);
?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">

<title>Change Password</title>

<meta
name="viewport"
content="width=device-width,initial-scale=1">

<?php if ($favicon_exists): ?>

<link
rel="icon"
type="image/png"
href="<?=h($favicon)?>">

<link
rel="shortcut icon"
href="<?=h($favicon)?>">

<?php endif; ?>

<link
rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

<style>

*{
    box-sizing:border-box;
}

body{

    margin:0;

    min-height:100vh;

    display:grid;

    place-items:center;

    background:
    radial-gradient(
        circle at top left,
        rgba(252,124,16,.15),
        transparent 40%
    ),

    #0f172a;

    font-family:
    Arial,
    sans-serif;

    padding:20px;

}

.card{

    width:100%;

    max-width:440px;

    background:#fff;

    border-radius:20px;

    padding:35px;

    box-shadow:

    0 35px 80px

    rgba(0,0,0,.28);

}

.logo-wrap{

    text-align:center;

    margin-bottom:25px;

}

.logo{

    max-width:220px;

    max-height:90px;

    width:auto;

    height:auto;

    object-fit:contain;

}

h1{

    margin:0 0 10px;

    font-size:28px;

    color:#0f172a;

    text-align:center;

}

p{

    margin:0 0 30px;

    color:#64748b;

    text-align:center;

    font-size:14px;

    line-height:1.6;

}

.group{

    margin-bottom:20px;

}

label{

    display:block;

    margin-bottom:8px;

    font-size:12px;

    font-weight:800;

    text-transform:uppercase;

    letter-spacing:.05em;

    color:#334155;

}

.input-wrap{

    position:relative;

}

input{

    width:100%;

    min-height:48px;

    border:

    1px solid

    #cbd5e1;

    border-radius:12px;

    padding:

    12px

    48px

    12px

    14px;

    font-size:14px;

    transition:

    border-color .2s,

    box-shadow .2s;

}

input:focus{

    outline:none;

    border-color:

    #fc7c10;

    box-shadow:

    0 0 0 3px

    rgba(252,124,16,.15);

}

.eye{

    position:absolute;

    right:14px;

    top:50%;

    transform:translateY(-50%);

    border:0;

    background:none;

    cursor:pointer;

    color:#64748b;

    font-size:15px;

}

.eye:hover{

    color:#fc7c10;

}

.btn{

    width:100%;

    border:0;

    border-radius:12px;

    background:#fc7c10;

    color:#fff;

    font-size:15px;

    font-weight:700;

    padding:14px;

    cursor:pointer;

    transition:

    transform .15s,

    box-shadow .15s,

    background .15s;

}

.btn:hover{

    background:#ea6d05;

    transform:translateY(-1px);

    box-shadow:

    0 12px 25px

    rgba(252,124,16,.28);

}

.alert{

    padding:14px;

    border-radius:12px;

    margin-bottom:22px;

    font-size:14px;

}

.alert.error{

    background:#fef2f2;

    border:1px solid #fecaca;

    color:#b91c1c;

}

.alert.success{

    background:#ecfdf5;

    border:1px solid #bbf7d0;

    color:#047857;

}

</style>

</head>

<body>

<div class="card">

    <div class="logo-wrap">

        <?php if($logo_exists): ?>

        <img

        src="<?=h($logo)?>"

        alt="Logo"

        class="logo">

        <?php endif; ?>

    </div>

    <h1>

    Change Password

    </h1>

    <p>

    Create a new password for your venture portal account.

    </p>

    <?php if($error): ?>

    <div class="alert error">

        <?=h($error)?>

    </div>

    <?php endif; ?>


    <?php if($success): ?>

    <div class="alert success">

        <?=h($success)?>

    </div>

    <?php endif; ?>


    <form

    method="POST"

    action="includes/process-change-password.php">
        <input type="hidden" name="site_csrf_token" value="<?= h(site_csrf_token()) ?>">

        <div class="group">

            <label>

            Current Password

            </label>

            <div class="input-wrap">

                <input

                type="password"

                name="current_password"

                required>

                <button

                type="button"

                class="eye"

                onclick="togglePw(this)">

                    <i class="fa fa-eye"></i>

                </button>

            </div>

        </div>


        <div class="group">

            <label>

            New Password

            </label>

            <div class="input-wrap">

                <input

                type="password"

                name="new_password"

                minlength="8"

                required>

                <button

                type="button"

                class="eye"

                onclick="togglePw(this)">

                    <i class="fa fa-eye"></i>

                </button>

            </div>

        </div>


        <div class="group">

            <label>

            Confirm Password

            </label>

            <div class="input-wrap">

                <input

                type="password"

                name="confirm_password"

                minlength="8"

                required>

                <button

                type="button"

                class="eye"

                onclick="togglePw(this)">

                    <i class="fa fa-eye"></i>

                </button>

            </div>

        </div>

        <button

        class="btn"

        type="submit">

        Update Password

        </button>

    </form>

</div>

<script>

function togglePw(btn){

    const input=

    btn.parentElement
       .querySelector('input');

    const icon=

    btn.querySelector('i');

    if(input.type==='password'){

        input.type='text';

        icon.className=

        'fa fa-eye-slash';

    }else{

        input.type='password';

        icon.className=

        'fa fa-eye';

    }

}

</script>

</body>

</html>

