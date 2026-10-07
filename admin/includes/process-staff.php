<?php
require_once '../../includes/config.php';
require_once '../includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

function redirect_staff(): void {
    header('Location: ../staff.php');
    exit;
}

if (!function_exists('safe_flash')) {
    function safe_flash(string $key, string $message, string $type = 'success'): void {
        if (function_exists('flash')) {
            flash($key, $message, $type);
        } else {
            $_SESSION['flash'][$key] = [
                'message' => $message,
                'type' => $type
            ];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_staff();
}

$action = $_POST['action'] ?? '';

if ($action === 'save') {

    $id           = (int)($_POST['id'] ?? 0);
    $full_name    = trim($_POST['full_name'] ?? '');
    $title        = trim($_POST['title'] ?? '');
    $organization = trim($_POST['organization'] ?? '');
    $bio          = trim($_POST['bio'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $linkedin     = trim($_POST['linkedin'] ?? '');
    $twitter      = trim($_POST['twitter'] ?? '');
    $category     = trim($_POST['category'] ?? 'program_staff');
    $expertise    = trim($_POST['expertise'] ?? '');
    $status       = isset($_POST['status']) ? 1 : 0;
    $sort_order   = (int)($_POST['sort_order'] ?? 0);

    $create_login = isset($_POST['create_login']) ? 1 : 0;
    $username     = trim($_POST['username'] ?? '');
    $login_role   = trim($_POST['login_role'] ?? '');
    $login_pass   = (string)($_POST['login_password'] ?? '');

    if ($full_name === '') {
        safe_flash('staff', 'Full name is required.', 'error');
        redirect_staff();
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        safe_flash('staff', 'Enter a valid email address.', 'error');
        redirect_staff();
    }

    $photo = trim($_POST['existing_photo'] ?? '');

    $err = '';

    if (function_exists('upload_image')) {
        $new_photo = upload_image('photo', 'staff', $err);

        if ($new_photo) {

            if ($photo !== '' && function_exists('delete_image')) {
                delete_image($photo);
            }

            $photo = $new_photo;
        }
    }

    $conn->begin_transaction();

    try {

        if ($id > 0) {

            $stmt = $conn->prepare("
                UPDATE staff
                SET full_name=?,
                    title=?,
                    organization=?,
                    bio=?,
                    photo=?,
                    email=?,
                    linkedin=?,
                    twitter=?,
                    category=?,
                    expertise=?,
                    status=?,
                    sort_order=?
                WHERE id=?
            ");

            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }

            $stmt->bind_param(
                'ssssssssssiii',
                $full_name,
                $title,
                $organization,
                $bio,
                $photo,
                $email,
                $linkedin,
                $twitter,
                $category,
                $expertise,
                $status,
                $sort_order,
                $id
            );

            if (!$stmt->execute()) {
                throw new RuntimeException($stmt->error);
            }

            $stmt->close();

            $staffId = $id;

        } else {

            $stmt = $conn->prepare("
                INSERT INTO staff (
                    full_name,
                    title,
                    organization,
                    bio,
                    photo,
                    email,
                    linkedin,
                    twitter,
                    category,
                    expertise,
                    status,
                    sort_order
                )
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ");

            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }

            $stmt->bind_param(
                'ssssssssssii',
                $full_name,
                $title,
                $organization,
                $bio,
                $photo,
                $email,
                $linkedin,
                $twitter,
                $category,
                $expertise,
                $status,
                $sort_order
            );

            if (!$stmt->execute()) {
                throw new RuntimeException($stmt->error);
            }

            $staffId = (int)$stmt->insert_id;

            $stmt->close();
        }

        if ($create_login && function_exists('staff_sync_admin_user')) {

            $sync = staff_sync_admin_user($conn, [
                'full_name'      => $full_name,
                'email'          => $email,
                'photo'          => $photo,
                'category'       => $category,
                'status'         => $status,
                'username'       => $username,
                'login_role'     => $login_role,
                'login_password' => $login_pass,
            ], $staffId);

            if (!$sync['ok']) {
                throw new RuntimeException($sync['message']);
            }

            $message = $sync['message'];

        } else {

            $message = $id > 0
                ? 'Staff member updated successfully.'
                : 'Staff member added successfully.';
        }

        $conn->commit();

        safe_flash('staff', $message);

    } catch (Throwable $e) {

        $conn->rollback();

        safe_flash('staff', $e->getMessage(), 'error');
    }

    redirect_staff();
}

if ($action === 'delete') {

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        safe_flash('staff', 'Invalid staff selected.', 'error');
        redirect_staff();
    }

    $stmt = $conn->prepare("
        SELECT photo,email
        FROM staff
        WHERE id=?
        LIMIT 1
    ");

    $stmt->bind_param('i', $id);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if ($row && !empty($row['photo']) && function_exists('delete_image')) {
        delete_image($row['photo']);
    }

    $stmt = $conn->prepare("
        DELETE FROM staff
        WHERE id=?
        LIMIT 1
    ");

    $stmt->bind_param('i', $id);

    if ($stmt->execute()) {
        safe_flash('staff', 'Staff member deleted.');
    } else {
        safe_flash('staff', 'Failed to delete staff: ' . $stmt->error, 'error');
    }

    $stmt->close();

    redirect_staff();
}

if ($action === 'toggle') {

    $id = (int)($_POST['id'] ?? 0);

    if ($id > 0) {

        $stmt = $conn->prepare("
            UPDATE staff
            SET status = IF(status=1,0,1)
            WHERE id=?
        ");

        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        safe_flash('staff', 'Status updated.');
    }

    redirect_staff();
}

safe_flash('staff', 'Invalid request action.', 'error');

redirect_staff();