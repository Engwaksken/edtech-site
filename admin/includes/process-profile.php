<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

$adminId = (int)($ADMIN['id'] ?? $_SESSION['admin_id'] ?? 0);

if ($adminId <= 0) {
    die('Invalid admin session.');
}

function profile_redirect(string $msg, string $type = 'success'): void
{
    $_SESSION['profile_flash'] = [
        'msg'  => $msg,
        'type' => $type
    ];

    header('Location: ../profile.php');
    exit;
}

function profile_admin_value(array $admin, string $key, string $default = ''): string
{
    return isset($admin[$key]) && $admin[$key] !== null ? (string)$admin[$key] : $default;
}

function verify_admin_password(string $currentPassword, string $storedPassword): bool
{
    if ($storedPassword === '') {
        return false;
    }

    $info = password_get_info($storedPassword);

    if (($info['algo'] ?? 0) !== 0) {
        return password_verify($currentPassword, $storedPassword);
    }

    return hash_equals($storedPassword, $currentPassword);
}

function get_fresh_admin(mysqli $conn, int $adminId): array
{
    $stmt = $conn->prepare("SELECT * FROM admin_users WHERE id = ? LIMIT 1");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $adminId);
    $stmt->execute();

    $admin = $stmt->get_result()->fetch_assoc() ?: [];

    $stmt->close();

    return $admin;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    profile_redirect('Invalid request.', 'error');
}

$action = $_POST['action'] ?? '';

if ($action === 'update_profile') {
    $full_name = trim((string)($_POST['full_name'] ?? ''));
    $email     = trim((string)($_POST['email'] ?? ''));

    if ($full_name === '') {
        profile_redirect('Full name is required.', 'error');
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        profile_redirect('A valid email address is required.', 'error');
    }

    $photo = profile_admin_value($ADMIN, 'photo');
    $err   = '';

    $new_photo = function_exists('upload_image')
        ? upload_image('photo', 'admin', $err)
        : '';

    if ($err !== '') {
        profile_redirect($err, 'error');
    }

    if ($new_photo) {
        if ($photo && file_exists(__DIR__ . '/../../' . ltrim($photo, '/'))) {
            if (function_exists('delete_image')) {
                delete_image($photo);
            } else {
                @unlink(__DIR__ . '/../../' . ltrim($photo, '/'));
            }
        }

        $photo = $new_photo;
    }

    $stmt = $conn->prepare("
        UPDATE admin_users
        SET full_name = ?,
            email = ?,
            photo = ?
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        profile_redirect('Failed to prepare profile update.', 'error');
    }

    $stmt->bind_param('sssi', $full_name, $email, $photo, $adminId);

    if (!$stmt->execute()) {
        $stmt->close();
        profile_redirect('Failed to update profile. Please try again.', 'error');
    }

    $stmt->close();

    $_SESSION['admin_name'] = $full_name;

    profile_redirect('Profile updated successfully.');
}

if ($action === 'change_password') {
    $current = (string)($_POST['current_password'] ?? '');
    $new_pwd = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    $freshAdmin = get_fresh_admin($conn, $adminId);
    $storedPassword = profile_admin_value($freshAdmin ?: $ADMIN, 'password');

    if ($current === '') {
        profile_redirect('Current password is required.', 'error');
    }

    if (!verify_admin_password($current, $storedPassword)) {
        profile_redirect('Current password is incorrect.', 'error');
    }

    if (strlen($new_pwd) < 8) {
        profile_redirect('New password must be at least 8 characters.', 'error');
    }

    if ($new_pwd !== $confirm) {
        profile_redirect('New passwords do not match.', 'error');
    }

    $hash = password_hash($new_pwd, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("
        UPDATE admin_users
        SET password = ?
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        profile_redirect('Failed to prepare password update.', 'error');
    }

    $stmt->bind_param('si', $hash, $adminId);

    if (!$stmt->execute()) {
        $stmt->close();
        profile_redirect('Failed to change password. Please try again.', 'error');
    }

    $stmt->close();

    profile_redirect('Password changed successfully.');
}

profile_redirect('Invalid action.', 'error');