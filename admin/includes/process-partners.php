<?php
require_once '../../includes/config.php';
require_once 'auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

function redirect_partners(string $extra = ''): void
{
    header('Location: ../partners.php' . $extra);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_partners();
}

$action = $_POST['action'] ?? '';

if ($action === 'save') {
    $id          = (int)($_POST['id'] ?? 0);
    $name        = trim($_POST['name'] ?? '');
    $website     = trim($_POST['website'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $sort_order  = (int)($_POST['sort_order'] ?? 0);
    $status      = (int)($_POST['status'] ?? 1);
    $logo        = trim($_POST['existing_logo'] ?? '');

    $status = $status === 1 ? 1 : 0;

    if ($name === '') {
        flash('partners', 'Partner name is required.', 'error');
        redirect_partners($id > 0 ? '?edit=' . $id : '');
    }

    if ($website !== '' && !preg_match('#^https?://#i', $website)) {
        $website = 'https://' . $website;
    }

    if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) {
        flash('partners', 'Enter a valid website URL.', 'error');
        redirect_partners($id > 0 ? '?edit=' . $id : '');
    }

    $err = '';

    if (!empty($_FILES['logo']['name']) && function_exists('upload_image')) {
        $newLogo = upload_image('logo', 'partners', $err);

        if ($newLogo) {
            if ($logo !== '' && function_exists('delete_image')) {
                delete_image($logo);
            }

            $logo = $newLogo;
        } else {
            flash('partners', $err ?: 'Logo upload failed.', 'error');
            redirect_partners($id > 0 ? '?edit=' . $id : '');
        }
    }

    if ($id > 0) {
        $stmt = $conn->prepare("
            UPDATE partners 
            SET name = ?, 
                logo = ?, 
                website = ?, 
                description = ?, 
                sort_order = ?, 
                status = ?
            WHERE id = ?
        ");

        if (!$stmt) {
            flash('partners', 'Database error: ' . $conn->error, 'error');
            redirect_partners();
        }

        $stmt->bind_param(
            "ssssiii",
            $name,
            $logo,
            $website,
            $description,
            $sort_order,
            $status,
            $id
        );

        if ($stmt->execute()) {
            flash('partners', 'Partner updated successfully.');
        } else {
            flash('partners', 'Failed to update partner: ' . $stmt->error, 'error');
        }

        $stmt->close();
        redirect_partners();
    }

    $stmt = $conn->prepare("
        INSERT INTO partners 
            (name, logo, website, description, sort_order, status)
        VALUES 
            (?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        flash('partners', 'Database error: ' . $conn->error, 'error');
        redirect_partners();
    }

    $stmt->bind_param(
        "ssssii",
        $name,
        $logo,
        $website,
        $description,
        $sort_order,
        $status
    );

    if ($stmt->execute()) {
        flash('partners', 'Partner added successfully.');
    } else {
        flash('partners', 'Failed to add partner: ' . $stmt->error, 'error');
    }

    $stmt->close();
    redirect_partners();
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        flash('partners', 'Invalid partner selected.', 'error');
        redirect_partners();
    }

    $stmt = $conn->prepare("SELECT logo FROM partners WHERE id = ? LIMIT 1");

    if (!$stmt) {
        flash('partners', 'Database error: ' . $conn->error, 'error');
        redirect_partners();
    }

    $stmt->bind_param("i", $id);
    $stmt->execute();
    $partner = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($partner && !empty($partner['logo']) && function_exists('delete_image')) {
        delete_image($partner['logo']);
    }

    $stmt = $conn->prepare("DELETE FROM partners WHERE id = ? LIMIT 1");

    if (!$stmt) {
        flash('partners', 'Database error: ' . $conn->error, 'error');
        redirect_partners();
    }

    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        flash('partners', 'Partner deleted successfully.');
    } else {
        flash('partners', 'Failed to delete partner: ' . $stmt->error, 'error');
    }

    $stmt->close();
    redirect_partners();
}

flash('partners', 'Invalid request action.', 'error');
redirect_partners();