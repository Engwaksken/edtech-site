<?php
require_once '../../includes/config.php';
require_once 'auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

function redirect_team(int $startupId = 0): void
{
    $url = '../venture-team.php';

    if ($startupId > 0) {
        $url .= '?venture_id=' . $startupId;
    }

    header('Location: ' . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_team();
}

$action = $_POST['action'] ?? '';

if ($action === 'save') {
    $id         = (int)($_POST['id'] ?? 0);
    $startup_id = (int)($_POST['startup_id'] ?? 0);
    $full_name  = trim($_POST['full_name'] ?? '');
    $role       = trim($_POST['role'] ?? '');
    $bio        = trim($_POST['bio'] ?? '');
    $linkedin   = trim($_POST['linkedin'] ?? '');
    $twitter    = trim($_POST['twitter'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $is_founder = isset($_POST['is_founder']) ? 1 : 0;
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $photo      = trim($_POST['existing_photo'] ?? '');

    if ($startup_id <= 0) {
        flash('team', 'Please select a startup.', 'error');
        redirect_team();
    }

    if ($full_name === '') {
        flash('team', 'Full name is required.', 'error');
        redirect_team($startup_id);
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('team', 'Enter a valid email address.', 'error');
        redirect_team($startup_id);
    }

    if ($linkedin !== '' && !filter_var($linkedin, FILTER_VALIDATE_URL)) {
        flash('team', 'Enter a valid LinkedIn URL.', 'error');
        redirect_team($startup_id);
    }

    if ($twitter !== '' && !filter_var($twitter, FILTER_VALIDATE_URL)) {
        flash('team', 'Enter a valid Twitter URL.', 'error');
        redirect_team($startup_id);
    }

    $err = '';

    if (function_exists('upload_image')) {
        $new_photo = upload_image('photo', 'team', $err);

        if ($new_photo) {
            if ($photo !== '' && function_exists('delete_image')) {
                delete_image($photo);
            }

            $photo = $new_photo;
        }
    }

    if ($id > 0) {
        $stmt = $conn->prepare("
            UPDATE venture_team
            SET startup_id = ?,
                full_name = ?,
                role = ?,
                bio = ?,
                photo = ?,
                linkedin = ?,
                twitter = ?,
                email = ?,
                is_founder = ?,
                sort_order = ?
            WHERE id = ?
        ");

        if (!$stmt) {
            flash('team', 'Database error: ' . $conn->error, 'error');
            redirect_team($startup_id);
        }

        $stmt->bind_param(
            "isssssssiii",
            $startup_id,
            $full_name,
            $role,
            $bio,
            $photo,
            $linkedin,
            $twitter,
            $email,
            $is_founder,
            $sort_order,
            $id
        );

        if ($stmt->execute()) {
            flash('team', 'Team member updated successfully.');
        } else {
            flash('team', 'Failed to update team member: ' . $stmt->error, 'error');
        }

        $stmt->close();
        redirect_team($startup_id);
    }

    $stmt = $conn->prepare("
        INSERT INTO venture_team (
            startup_id,
            full_name,
            role,
            bio,
            photo,
            linkedin,
            twitter,
            email,
            is_founder,
            sort_order
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        flash('team', 'Database error: ' . $conn->error, 'error');
        redirect_team($startup_id);
    }

    $stmt->bind_param(
        "isssssssii",
        $startup_id,
        $full_name,
        $role,
        $bio,
        $photo,
        $linkedin,
        $twitter,
        $email,
        $is_founder,
        $sort_order
    );

    if ($stmt->execute()) {
        flash('team', 'Team member added successfully.');
    } else {
        flash('team', 'Failed to add team member: ' . $stmt->error, 'error');
    }

    $stmt->close();
    redirect_team($startup_id);
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $startup_id = (int)($_POST['startup_id'] ?? 0);

    if ($id <= 0) {
        flash('team', 'Invalid team member selected.', 'error');
        redirect_team($startup_id);
    }

    $stmt = $conn->prepare("SELECT photo, venture_id FROM venture_team WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($member) {
        $startup_id = (int)$member['venture_id'];

        if (!empty($member['photo']) && function_exists('delete_image')) {
            delete_image($member['photo']);
        }
    }

    $stmt = $conn->prepare("DELETE FROM venture_team WHERE id = ? LIMIT 1");

    if (!$stmt) {
        flash('team', 'Database error: ' . $conn->error, 'error');
        redirect_team($startup_id);
    }

    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        flash('team', 'Team member deleted successfully.');
    } else {
        flash('team', 'Failed to delete team member: ' . $stmt->error, 'error');
    }

    $stmt->close();
    redirect_team($startup_id);
}

flash('team', 'Invalid request action.', 'error');
redirect_team();