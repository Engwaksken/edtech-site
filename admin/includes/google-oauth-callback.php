<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../includes/google-calendar-config.php';
require_once __DIR__ . '/../../includes/google-calendar-service.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('DB error');
}
$conn->set_charset('utf8mb4');

function goog_flash(string $msg, string $type = 'success'): void
{
    if (function_exists('flash')) {
        flash('sessions', $msg, $type);
        return;
    }
    $_SESSION['sessions_flash'] = ['msg' => $msg, 'type' => $type];
}

$code  = trim((string)($_GET['code'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));

if ($error !== '') {
    goog_flash('Google Calendar connection was cancelled.', 'error');
    header('Location: ../mentor-calendar.php');
    exit;
}

if ($code === '') {
    goog_flash('Invalid Google Calendar callback (missing authorization code).', 'error');
    header('Location: ../mentor-calendar.php');
    exit;
}

$svc      = new GoogleCalendarService($conn);
$mentorId = $svc->handleCallback($code);

if ($mentorId) {
    goog_flash('Google Calendar connected. Availability and confirmed sessions will now sync automatically.', 'success');
} else {
    goog_flash('Failed to connect Google Calendar. Please try again.', 'error');
}

header('Location: ../mentor-calendar.php');
exit;
