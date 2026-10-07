<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/session-junctions.php';
require_once __DIR__ . '/../../includes/google-calendar-config.php';
require_once __DIR__ . '/../../includes/google-calendar-service.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('DB error');
}
$conn->set_charset('utf8mb4');

$IS_MENTOR        = ms_is_mentor();
$CAN_MANAGE       = ms_can_manage();
$MENTOR_RECORD_ID = ms_mentor_record_id($conn);

$target_mentor_id = $MENTOR_RECORD_ID;
if ($CAN_MANAGE && !empty($_POST['mentor_id'])) {
    $target_mentor_id = (int)$_POST['mentor_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $target_mentor_id > 0 && ($IS_MENTOR || $CAN_MANAGE)) {
    (new GoogleCalendarService($conn))->disconnect($target_mentor_id);
    if (function_exists('flash')) {
        flash('sessions', 'Google Calendar disconnected.', 'success');
    }
}

header('Location: ../mentor-calendar.php');
exit;