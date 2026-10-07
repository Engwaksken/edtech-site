<?php
declare(strict_types=1);

require_once '../includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/session-junctions.php';
require_once '../includes/google-calendar-config.php';
require_once '../includes/google-calendar-service.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('DB error');
}
$conn->set_charset('utf8mb4');

$IS_MENTOR        = ms_is_mentor();
$CAN_MANAGE       = ms_can_manage();
$MENTOR_RECORD_ID = ms_mentor_record_id($conn);


$target_mentor_id = $MENTOR_RECORD_ID;
if ($CAN_MANAGE && !empty($_GET['mentor_id'])) {
    $target_mentor_id = (int)$_GET['mentor_id'];
}

if ($target_mentor_id <= 0) {
    die('No mentor account to connect. If you are an admin, pass ?mentor_id=.');
}

if (GOOGLE_CALENDAR_CLIENT_ID === '' || GOOGLE_CALENDAR_CLIENT_SECRET === '') {
    die('Google Calendar is not configured yet. Set GOOGLE_CALENDAR_CLIENT_ID / GOOGLE_CALENDAR_CLIENT_SECRET.');
}

$svc = new GoogleCalendarService($conn);
header('Location: ' . $svc->getAuthUrl($target_mentor_id));
exit;