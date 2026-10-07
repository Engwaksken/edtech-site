<?php
require_once '../../includes/config.php';
require_once 'auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

function redirect_messages(): void
{
    header('Location: ../messages.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_messages();
}

$action = $_POST['action'] ?? '';
$id = (int)($_POST['id'] ?? 0);

if ($action === 'mark_read') {
    if ($id <= 0) {
        flash('messages', 'Invalid message selected.', 'error');
        redirect_messages();
    }

    $stmt = $conn->prepare("UPDATE contact_messages SET status = 'read' WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        flash('messages', 'Message marked as read.');
    } else {
        flash('messages', 'Failed to mark message as read: ' . $stmt->error, 'error');
    }

    $stmt->close();
    redirect_messages();
}

if ($action === 'mark_replied') {
    if ($id <= 0) {
        flash('messages', 'Invalid message selected.', 'error');
        redirect_messages();
    }

    $stmt = $conn->prepare("UPDATE contact_messages SET status = 'replied' WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        flash('messages', 'Message marked as replied.');
    } else {
        flash('messages', 'Failed to mark message as replied: ' . $stmt->error, 'error');
    }

    $stmt->close();
    redirect_messages();
}

if ($action === 'delete') {
    if ($id <= 0) {
        flash('messages', 'Invalid message selected.', 'error');
        redirect_messages();
    }

    $stmt = $conn->prepare("DELETE FROM contact_messages WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        flash('messages', 'Message deleted.');
    } else {
        flash('messages', 'Failed to delete message: ' . $stmt->error, 'error');
    }

    $stmt->close();
    redirect_messages();
}

if ($action === 'mark_all_read') {
    $stmt = $conn->prepare("UPDATE contact_messages SET status = 'read' WHERE status = 'unread'");

    if ($stmt->execute()) {
        flash('messages', 'All messages marked as read.');
    } else {
        flash('messages', 'Failed to mark all messages as read: ' . $stmt->error, 'error');
    }

    $stmt->close();
    redirect_messages();
}

flash('messages', 'Invalid request action.', 'error');
redirect_messages();