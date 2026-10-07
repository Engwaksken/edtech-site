<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/mail-function.php';
require_once __DIR__ . '/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

$current_uid = (int)($_SESSION['admin_id'] ?? ($ADMIN['id'] ?? 0));

function users_redirect(string $msg = '', string $type = 'success'): void
{
    if ($msg !== '') {
        if (function_exists('flash')) {
            flash('users', $msg, $type);
        } else {
            $_SESSION['users_flash'] = [
                'msg'  => $msg,
                'type' => $type
            ];
        }
    }

    header('Location: ../users.php');
    exit;
}

function users_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function table_exists(mysqli $conn, string $table): bool
{
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $res && $res->num_rows > 0;
}

function column_type(mysqli $conn, string $table, string $column): string
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);

    $res = $conn->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");

    if ($res && $row = $res->fetch_assoc()) {
        return strtolower((string)($row['Type'] ?? ''));
    }

    return '';
}

function status_is_numeric(mysqli $conn): bool
{
    $type = column_type($conn, 'admin_users', 'status');

    return str_contains($type, 'tinyint')
        || str_contains($type, 'int')
        || str_contains($type, 'bool');
}

function normalize_status_for_db(mysqli $conn, string $status)
{
    $status = strtolower(trim($status));

    $is_active = in_array($status, ['1', 'active', 'yes', 'on'], true);

    if (status_is_numeric($conn)) {
        return $is_active ? 1 : 0;
    }

    return $is_active ? 'active' : 'inactive';
}

function is_active_status($status): bool
{
    $status = strtolower(trim((string)$status));
    return in_array($status, ['1', 'active'], true);
}

function clean_role(string $role): string
{
    $role = strtolower(trim($role));
    $role = str_replace('_', '-', $role);

    return preg_replace('/[^a-z0-9\-]/', '', $role);
}

function role_exists(mysqli $conn, string $role): bool
{
    if ($role === '') {
        return false;
    }

    if (!table_exists($conn, 'roles')) {
        $allowed = [
            'super-admin',
            'super_admin',
            'admin',
            'staff',
            'mentor',
            'consultant',
            'program-director',
            'program-manager',
            'meal-officer',
            'media',
            'programs-lead',
            'finance',
            'accountant',
            'program-officer'
        ];

        return in_array($role, $allowed, true);
    }

    $stmt = $conn->prepare("SELECT id FROM roles WHERE role_key = ? LIMIT 1");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $role);
    $stmt->execute();

    $ok = $stmt->get_result()->num_rows > 0;

    $stmt->close();

    return $ok;
}

function can_manage_users(mysqli $conn): bool
{
    global $ADMIN;

    $role = clean_role((string)($ADMIN['role'] ?? $_SESSION['admin_role'] ?? ''));

    if (in_array($role, ['super-admin', 'super_admin', 'admin'], true)) {
        return true;
    }

    try {
        if (function_exists('admin_can_manage_permissions') && admin_can_manage_permissions($conn)) {
            return true;
        }
    } catch (Throwable $e) {
    }

    if (function_exists('admin_has_permission') && admin_has_permission($conn, 'users')) {
        return true;
    }

    return false;
}

function upload_user_photo(string $field, string &$err = ''): string
{
    if (empty($_FILES[$field]['name'])) {
        return '';
    }

    $allowed_ext = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $max_size = 2 * 1024 * 1024;

    $ext = strtolower(pathinfo((string)$_FILES[$field]['name'], PATHINFO_EXTENSION));
    $size = (int)($_FILES[$field]['size'] ?? 0);

    if (!in_array($ext, $allowed_ext, true)) {
        $err = 'Invalid image type. Use JPG, PNG, WebP or GIF.';
        return '';
    }

    if ($size > $max_size) {
        $err = 'Photo must be under 2MB.';
        return '';
    }

    if (!is_uploaded_file((string)$_FILES[$field]['tmp_name'])) {
        $err = 'Invalid uploaded file.';
        return '';
    }

    $dir = __DIR__ . '/../../uploads/users/';

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $name = 'user-' . date('YmdHis') . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
    $target = $dir . $name;

    if (!move_uploaded_file((string)$_FILES[$field]['tmp_name'], $target)) {
        $err = 'Failed to save photo.';
        return '';
    }

    return 'uploads/users/' . $name;
}

function delete_user_photo(string $path): void
{
    if ($path === '') {
        return;
    }

    $full = __DIR__ . '/../../' . ltrim($path, '/');

    if (is_file($full)) {
        @unlink($full);
    }
}

function users_login_url(): string
{
    if (defined('BASE_URL')) {
        return rtrim((string)BASE_URL, '/') . '/admin/login.php';
    }

    return '../login.php';
}

function send_new_user_registration_email(
    string $full_name,
    string $username,
    string $email,
    string $password,
    string $role
): bool {
    if (!function_exists('sendEmail')) {
        error_log('New user email failed: sendEmail() function not found.');
        return false;
    }

    $siteName = defined('SITE_NAME') ? SITE_NAME : 'Hive Colab';
    //$loginUrl = users_login_url();
    $loginUrl = "https://www.edtech.hivecolab.com/admin/login";

    $safeName     = users_h($full_name);
    $safeUsername = users_h($username);
    $safeEmail    = users_h($email);
    $safePassword = users_h($password);
    $safeRole     = users_h(ucwords(str_replace('-', ' ', $role)));
    $safeLoginUrl = users_h($loginUrl);
    $safeSiteName = users_h($siteName);

    $content = "
        <h3>Welcome to {$safeSiteName}</h3>

        <p>Hello {$safeName},</p>

        <p>Your admin account has been created successfully. Use the login details below to access the system.</p>

        <div class='info-box'>
            <p><strong>Full Name:</strong> {$safeName}</p>
            <p><strong>Username:</strong> {$safeUsername}</p>
            <p><strong>Email:</strong> {$safeEmail}</p>
            <p><strong>Role:</strong> {$safeRole}</p>
        </div>

        <div class='cred-box'>
            <h4>Your Login Password</h4>
            <div class='password'>{$safePassword}</div>

            <div class='warning'>
                For security, please change this password immediately after your first login.
            </div>
        </div>

        <p>
            <a href='{$safeLoginUrl}' class='btn' target='_blank'>
                Login to Your Account
            </a>
        </p>

        <p>If the button does not work, copy and open this link:</p>
        <p>{$safeLoginUrl}</p>

        <p>Thank you,<br>{$safeSiteName}</p>
    ";

    $body = function_exists('email_wrapper')
        ? email_wrapper($content)
        : $content;

    $altBody =
        "Hello {$full_name},\n\n"
        . "Your admin account has been created successfully.\n\n"
        . "Login details:\n"
        . "Full Name: {$full_name}\n"
        . "Username: {$username}\n"
        . "Email: {$email}\n"
        . "Role: " . ucwords(str_replace('-', ' ', $role)) . "\n"
        . "Password: {$password}\n\n"
        . "Login URL: {$loginUrl}\n\n"
        . "For security, please change this password immediately after your first login.\n\n"
        . "Thank you,\n{$siteName}";

    $result = sendEmail(
        $email,
        'Your Admin Account Has Been Created',
        $body,
        $altBody
    );

    if ($result !== true) {
        error_log('New user registration email failed for ' . $email . ': ' . (string)$result);
        return false;
    }

    return true;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    users_redirect('Invalid request.', 'error');
}

$action = $_POST['action'] ?? '';

if (!can_manage_users($conn)) {
    users_redirect('You are not allowed to manage users.', 'error');
}

/* ==========================================================
   SAVE USER
========================================================== */
if ($action === 'save') {
    $id = (int)($_POST['id'] ?? 0);

    $full_name = trim((string)($_POST['full_name'] ?? ''));
    $username = strtolower(trim((string)($_POST['username'] ?? '')));
    $email = trim((string)($_POST['email'] ?? ''));
    $role = clean_role((string)($_POST['role'] ?? ''));
    $status = normalize_status_for_db($conn, (string)($_POST['status'] ?? 'active'));

    $password = (string)($_POST['password'] ?? '');
    $password_confirm = (string)($_POST['password_confirm'] ?? '');
    $existing_photo = trim((string)($_POST['existing_photo'] ?? ''));

    if ($full_name === '') {
        users_redirect('Full name is required.', 'error');
    }

    $username = preg_replace('/[^a-z0-9_\-]/', '', $username);

    if ($username === '') {
        users_redirect('Username is required.', 'error');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        users_redirect('Invalid email address.', 'error');
    }

    if (!role_exists($conn, $role)) {
        users_redirect('Selected role does not exist.', 'error');
    }

    if ($id === 0 && strlen($password) < 8) {
        users_redirect('Password must be at least 8 characters.', 'error');
    }

    if ($password !== '' && $password !== $password_confirm) {
        users_redirect('Passwords do not match.', 'error');
    }

    $stmt = $conn->prepare("SELECT id FROM admin_users WHERE username = ? AND id <> ? LIMIT 1");
    $stmt->bind_param('si', $username, $id);
    $stmt->execute();

    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        users_redirect('Username is already taken.', 'error');
    }

    $stmt->close();

    $stmt = $conn->prepare("SELECT id FROM admin_users WHERE email = ? AND id <> ? LIMIT 1");
    $stmt->bind_param('si', $email, $id);
    $stmt->execute();

    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        users_redirect('Email address is already in use.', 'error');
    }

    $stmt->close();

    $photo = $existing_photo;

    if (!empty($_FILES['photo']['name'])) {
        $err = '';
        $new_photo = upload_user_photo('photo', $err);

        if ($err !== '') {
            users_redirect($err, 'error');
        }

        if ($new_photo !== '') {
            if ($existing_photo !== '') {
                delete_user_photo($existing_photo);
            }

            $photo = $new_photo;
        }
    }

    if ($id > 0 && $existing_photo === '') {
        $stmt = $conn->prepare("SELECT photo FROM admin_users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();

        $old = $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if (!empty($old['photo'])) {
            delete_user_photo((string)$old['photo']);
        }

        $photo = '';
    }

    if ($id === 0) {
        $hash = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $conn->prepare("
            INSERT INTO admin_users
                (full_name, username, email, password, role, photo, status)
            VALUES
                (?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            users_redirect('Failed to prepare user creation: ' . $conn->error, 'error');
        }

        if (status_is_numeric($conn)) {
            $stmt->bind_param(
                'ssssssi',
                $full_name,
                $username,
                $email,
                $hash,
                $role,
                $photo,
                $status
            );
        } else {
            $stmt->bind_param(
                'sssssss',
                $full_name,
                $username,
                $email,
                $hash,
                $role,
                $photo,
                $status
            );
        }

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            users_redirect('Failed to create user: ' . $err, 'error');
        }

        $stmt->close();

        $emailSent = send_new_user_registration_email(
            $full_name,
            $username,
            $email,
            $password,
            $role
        );

        if ($emailSent) {
            users_redirect('User created successfully and registration email sent.');
        }

        users_redirect('User created successfully, but registration email was not sent. Check email settings.', 'warning');
    }

    if ($password !== '') {
        $hash = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $conn->prepare("
            UPDATE admin_users
            SET full_name = ?,
                username = ?,
                email = ?,
                password = ?,
                role = ?,
                photo = ?,
                status = ?
            WHERE id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            users_redirect('Failed to prepare user update: ' . $conn->error, 'error');
        }

        if (status_is_numeric($conn)) {
            $stmt->bind_param(
                'ssssssii',
                $full_name,
                $username,
                $email,
                $hash,
                $role,
                $photo,
                $status,
                $id
            );
        } else {
            $stmt->bind_param(
                'sssssssi',
                $full_name,
                $username,
                $email,
                $hash,
                $role,
                $photo,
                $status,
                $id
            );
        }
    } else {
        $stmt = $conn->prepare("
            UPDATE admin_users
            SET full_name = ?,
                username = ?,
                email = ?,
                role = ?,
                photo = ?,
                status = ?
            WHERE id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            users_redirect('Failed to prepare user update: ' . $conn->error, 'error');
        }

        if (status_is_numeric($conn)) {
            $stmt->bind_param(
                'sssssii',
                $full_name,
                $username,
                $email,
                $role,
                $photo,
                $status,
                $id
            );
        } else {
            $stmt->bind_param(
                'ssssssi',
                $full_name,
                $username,
                $email,
                $role,
                $photo,
                $status,
                $id
            );
        }
    }

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        users_redirect('Failed to update user: ' . $err, 'error');
    }

    $stmt->close();

    users_redirect('User updated successfully.');
}

/* ==========================================================
   CHANGE PASSWORD
========================================================== */
if ($action === 'change_password') {
    $id = (int)($_POST['id'] ?? 0);
    $pw = (string)($_POST['new_password'] ?? '');
    $cpw = (string)($_POST['new_password_confirm'] ?? '');

    if ($id <= 0) {
        users_redirect('Invalid user.', 'error');
    }

    if (strlen($pw) < 8) {
        users_redirect('Password must be at least 8 characters.', 'error');
    }

    if ($pw !== $cpw) {
        users_redirect('Passwords do not match.', 'error');
    }

    $hash = password_hash($pw, PASSWORD_BCRYPT);

    $stmt = $conn->prepare("UPDATE admin_users SET password = ? WHERE id = ? LIMIT 1");
    $stmt->bind_param('si', $hash, $id);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        users_redirect('Failed to update password: ' . $err, 'error');
    }

    $stmt->close();

    users_redirect('Password updated successfully.');
}

/* ==========================================================
   TOGGLE STATUS
========================================================== */
if ($action === 'toggle_status') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        users_redirect('Invalid user.', 'error');
    }

    if ($id === $current_uid) {
        users_redirect('You cannot deactivate your own account.', 'error');
    }

    $stmt = $conn->prepare("SELECT status FROM admin_users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!$row) {
        users_redirect('User not found.', 'error');
    }

    $new_status = is_active_status($row['status'])
        ? normalize_status_for_db($conn, 'inactive')
        : normalize_status_for_db($conn, 'active');

    $stmt = $conn->prepare("UPDATE admin_users SET status = ? WHERE id = ? LIMIT 1");

    if (status_is_numeric($conn)) {
        $stmt->bind_param('ii', $new_status, $id);
    } else {
        $stmt->bind_param('si', $new_status, $id);
    }

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        users_redirect('Failed to update status: ' . $err, 'error');
    }

    $stmt->close();

    users_redirect('User status updated successfully.');
}

/* ==========================================================
   DELETE USER
========================================================== */
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        users_redirect('Invalid user.', 'error');
    }

    if ($id === $current_uid) {
        users_redirect('You cannot delete your own account.', 'error');
    }

    $stmt = $conn->prepare("SELECT photo FROM admin_users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!empty($row['photo'])) {
        delete_user_photo((string)$row['photo']);
    }

    $stmt = $conn->prepare("DELETE FROM admin_users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        users_redirect('Failed to delete user: ' . $err, 'error');
    }

    $stmt->close();

    users_redirect('User deleted successfully.');
}

/* ==========================================================
   BULK DELETE
========================================================== */
if ($action === 'bulk_delete') {
    $raw_ids = (string)($_POST['ids'] ?? '');

    $ids = array_values(array_filter(
        array_map('intval', explode(',', $raw_ids)),
        fn($id) => $id > 0 && $id !== $current_uid
    ));

    if (empty($ids)) {
        users_redirect('No valid users selected.', 'error');
    }

    $deleted = 0;

    foreach ($ids as $id) {
        $stmt = $conn->prepare("SELECT photo FROM admin_users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if (!empty($row['photo'])) {
            delete_user_photo((string)$row['photo']);
        }

        $stmt = $conn->prepare("DELETE FROM admin_users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);

        if ($stmt->execute()) {
            $deleted++;
        }

        $stmt->close();
    }

    users_redirect($deleted . ' user(s) deleted successfully.');
}

users_redirect('Unknown action.', 'error');