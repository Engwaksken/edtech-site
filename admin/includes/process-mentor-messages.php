<?php
declare(strict_types=1);

require_once '../../includes/config.php';
require_once 'auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection error.');
}

$conn->set_charset('utf8mb4');

ms_auth_gate();

$IS_MENTOR = ms_is_mentor();

function mmsg_redirect(): void
{
    header('Location: ../mentor-calendar.php');
    exit;
}

function mmsg_flash(string $msg, string $type = 'success'): void
{
    if (function_exists('flash')) {
        flash('sessions', $msg, $type);
        return;
    }

    $_SESSION['sessions_flash'] = [
        'msg'  => $msg,
        'type' => $type,
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mmsg_redirect();
}

$action = $_POST['action'] ?? '';

/* --------------------------------------------------------------
   REPLY TO A VENTURE MESSAGE (from the calendar messages panel)
-------------------------------------------------------------- */
if ($action === 'admin_reply_venture') {
    if ($IS_MENTOR) {
        mmsg_flash('Access denied.', 'error');
        mmsg_redirect();
    }

    $message_id = (int)($_POST['message_id'] ?? 0);
    $reply      = trim((string)($_POST['reply'] ?? ''));

    if ($message_id <= 0 || $reply === '') {
        mmsg_flash('Reply message is required.', 'error');
        mmsg_redirect();
    }

    $stmt = $conn->prepare("
        SELECT venture_id, subject
        FROM venture_messages
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        mmsg_flash('DB error: ' . $conn->error, 'error');
        mmsg_redirect();
    }

    $stmt->bind_param('i', $message_id);
    $stmt->execute();
    $orig = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$orig) {
        mmsg_flash('Message not found.', 'error');
        mmsg_redirect();
    }

    $venture_id = (int)($orig['venture_id'] ?? 0);
    $subject    = trim((string)($orig['subject'] ?? ''));

    if ($venture_id <= 0) {
        mmsg_flash('Message is not linked to a venture.', 'error');
        mmsg_redirect();
    }

    if ($subject === '' || stripos($subject, 'Re:') !== 0) {
        $subject = 'Re: ' . $subject;
    }

    $sender = trim((string)($ADMIN['full_name'] ?? ($_SESSION['admin_name'] ?? '')));
    if ($sender === '') {
        $sender = 'Programme Team';
    }

    $insert = $conn->prepare("
        INSERT INTO venture_messages (venture_id, sender, subject, body, is_read, created_at)
        VALUES (?, ?, ?, ?, 0, NOW())
    ");

    if (!$insert) {
        mmsg_flash('DB error: ' . $conn->error, 'error');
        mmsg_redirect();
    }

    $insert->bind_param('isss', $venture_id, $sender, $subject, $reply);
    $ok  = $insert->execute();
    $err = $insert->error;
    $insert->close();

    if ($ok) {
        $upd = $conn->prepare("UPDATE venture_messages SET is_read = 1 WHERE id = ?");
        if ($upd) {
            $upd->bind_param('i', $message_id);
            $upd->execute();
            $upd->close();
        }

        mmsg_flash('Reply sent to the venture.');
    } else {
        mmsg_flash('Failed to send reply: ' . $err, 'error');
    }

    mmsg_redirect();
}

mmsg_flash('Invalid action.', 'error');
mmsg_redirect();
