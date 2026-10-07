<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/mail-function.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/session-junctions.php';
$googleCalendarConfigFile  = __DIR__ . '/../../includes/google-calendar-config.php';
$googleCalendarServiceFile = __DIR__ . '/../../includes/google-calendar-service.php';
$googleCalendarNotifyFile  = __DIR__ . '/../../includes/google-calendar-notify.php';

if (is_file($googleCalendarConfigFile)) {
    require_once $googleCalendarConfigFile;
}

if (is_file($googleCalendarServiceFile)) {
    require_once $googleCalendarServiceFile;
}

if (is_file($googleCalendarNotifyFile)) {
    require_once $googleCalendarNotifyFile;
}


$__report_engine = __DIR__ . '/report-engine.php';
if (file_exists($__report_engine)) {
    require_once $__report_engine;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection error.');
}

$conn->set_charset('utf8mb4');

ms_auth_gate();

$IS_MENTOR        = ms_is_mentor();
$CAN_MANAGE       = ms_can_manage();
$MENTOR_RECORD_ID = ms_mentor_record_id($conn);

function ms_redirect(string $qs = ''): void
{
    header('Location: ../mentor-sessions.php' . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

function ms_flash(string $msg, string $type = 'success'): void
{
    if (function_exists('flash')) {
        flash('sessions', $msg, $type);
        return;
    }

    $_SESSION['sessions_flash'] = [
        'msg'  => $msg,
        'type' => $type
    ];
}

function ms_post(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function ms_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ms_has_column(mysqli $conn, string $table, string $column): bool
{
    $st = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");

    if (!$st) {
        return false;
    }

    $st->bind_param('ss', $table, $column);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    return (int)($row['c'] ?? 0) > 0;
}

function ms_ensure_session_response_columns(mysqli $conn): void
{
    if (!ms_has_column($conn, 'mentor_sessions', 'cancel_reason')) {
        @$conn->query("ALTER TABLE mentor_sessions ADD COLUMN cancel_reason TEXT NULL");
    }

    if (!ms_has_column($conn, 'mentor_sessions', 'cancelled_by')) {
        @$conn->query("ALTER TABLE mentor_sessions ADD COLUMN cancelled_by VARCHAR(30) NULL");
    }
}

function ms_notify_ventures_session_decision(
    mysqli $conn,
    int $session_id,
    string $session_title,
    string $decision,
    string $reason = ''
): void {
    if (!function_exists('sendEmail')) {
        return;
    }

    $ventures = ms_get_session_ventures($conn, $session_id);
    if (!$ventures) {
        return;
    }

    $confirmed = $decision === 'confirmed';
    $subject = $confirmed
        ? 'Mentorship session confirmed: ' . $session_title
        : 'Mentorship session request rejected: ' . $session_title;

    foreach ($ventures as $v) {
        $email = trim((string)($v['founder_email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $name = ms_h((string)($v['name'] ?? 'Venture'));
        $title = ms_h($session_title);
        $safeReason = ms_h($reason);

        if ($confirmed) {
            $content = "
                <h3>Mentorship session confirmed</h3>
                <p>Hello {$name},</p>
                <p>Your mentor has confirmed the mentorship session <strong>{$title}</strong>.</p>
                <p>Please log in to your venture portal to review the session details and meeting information.</p>
            ";
            $alt = "Your mentor has confirmed the mentorship session: {$session_title}. Please log in to your venture portal for details.";
        } else {
            $content = "
                <h3>Mentorship session request rejected</h3>
                <p>Hello {$name},</p>
                <p>Your mentor is unable to accept the mentorship session request <strong>{$title}</strong>.</p>
                <div class='info-box'><p><strong>Reason:</strong><br>{$safeReason}</p></div>
                <p>Please use the venture portal to request another suitable session where appropriate.</p>
            ";
            $alt = "Your mentor rejected the mentorship session request: {$session_title}. Reason: {$reason}";
        }

        $body = function_exists('email_wrapper') ? email_wrapper($content) : $content;
        try {
            sendEmail($email, $subject, $body, $alt);
        } catch (Throwable $e) {
            error_log('Session decision email failed for ' . $email . ': ' . $e->getMessage());
        }
    }
}


/**
 * Push a confirmed session onto the organizing mentor's dedicated Google
 * Calendar (never their personal one - see GoogleCalendarService) and
 * store the resulting event id. If the mentor isn't connected at all,
 * this is a silent no-op. If they *are* connected but the sync fails
 * because their connection needs renewing, the mentor is asked to
 * reconnect and the ventures on the session are told the invite couldn't
 * be generated automatically, so nobody is left assuming it "just worked".
 */
function ms_sync_session_calendar(
    mysqli $conn,
    int $session_id,
    int $organizer_id,
    string $title,
    ?string $scheduled_at,
    int $duration_minutes,
    string $description,
    string $meeting_link,
    ?string $existing_event_id
): void {
    if ($organizer_id <= 0 || !$scheduled_at) {
        return;
    }

    /*
     * Google Calendar is an optional integration.
     * Session creation/update must not fail simply because the Calendar
     * service or notification helper is unavailable.
     */
    if (!class_exists('GoogleCalendarService')) {
        error_log(
            'Google Calendar sync skipped for mentor session '
            . $session_id
            . ': GoogleCalendarService is unavailable.'
        );
        return;
    }

    $attendees = [];

    foreach (ms_get_session_mentors($conn, $session_id) as $m) {
        $email = trim((string)($m['email'] ?? ''));

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $attendees[] = [
                'email' => $email,
                'name'  => trim((string)($m['full_name'] ?? ''))
            ];
        }
    }

    foreach (ms_get_session_ventures($conn, $session_id) as $v) {
        $email = trim((string)($v['founder_email'] ?? ''));

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $attendees[] = [
                'email' => $email,
                'name'  => trim((string)($v['name'] ?? ''))
            ];
        }
    }

    /*
     * Prevent duplicate attendee emails.
     */
    $uniqueAttendees = [];
    foreach ($attendees as $attendee) {
        $key = strtolower($attendee['email']);
        $uniqueAttendees[$key] = $attendee;
    }
    $attendees = array_values($uniqueAttendees);

    try {
        $svc = new GoogleCalendarService($conn);

        $eventId = $svc->upsertSessionEvent(
            $organizer_id,
            $existing_event_id,
            $title,
            $scheduled_at,
            $duration_minutes,
            $description,
            $meeting_link,
            $attendees
        );

        if ($eventId !== null && $eventId !== '') {
            $st = $conn->prepare("
                UPDATE mentor_sessions
                SET google_event_id = ?
                WHERE id = ?
                LIMIT 1
            ");

            if ($st) {
                $st->bind_param('si', $eventId, $session_id);
                $st->execute();
                $st->close();
            }

            return;
        }

        /*
         * If the mentor's Google connection needs reconnecting, send optional
         * notification emails only when the helper functions are available.
         */
        if (
            method_exists($svc, 'needsReconnect')
            && $svc->needsReconnect($organizer_id)
        ) {
            if (function_exists('gcal_notify_mentor_reconnect')) {
                try {
                    gcal_notify_mentor_reconnect(
                        $conn,
                        $organizer_id,
                        $title
                    );
                } catch (Throwable $e) {
                    error_log(
                        'Google Calendar reconnect notification failed for mentor '
                        . $organizer_id
                        . ': '
                        . $e->getMessage()
                    );
                }
            }

            if (function_exists('gcal_notify_ventures_sync_failed')) {
                try {
                    gcal_notify_ventures_sync_failed(
                        $conn,
                        $session_id,
                        $title,
                        $scheduled_at,
                        $meeting_link
                    );
                } catch (Throwable $e) {
                    error_log(
                        'Google Calendar venture notification failed for session '
                        . $session_id
                        . ': '
                        . $e->getMessage()
                    );
                }
            }
        }
    } catch (Throwable $e) {
        /*
         * Never allow a Calendar API/OAuth error to crash session saving.
         */
        error_log(
            'Google Calendar sync failed for mentor session '
            . $session_id
            . ': '
            . $e->getMessage()
        );
    }
}


function ms_notify_stakeholders(mysqli $conn, string $subject, string $summary, ?string $link = null): void
{
    if (!function_exists('rs_privileged_recipients') || !function_exists('sendEmail')) {
        return;
    }

    $recipients = rs_privileged_recipients($conn);
    if (!$recipients) {
        return;
    }

    $safeSummary = ms_h($summary);
    $content = "<h3>" . ms_h($subject) . "</h3><p>{$safeSummary}</p><p>Please log in to view more.</p>";
    $body = function_exists('email_wrapper') ? email_wrapper($content) : $content;
    $altBody = $subject . "\n\n" . $summary . "\n\nPlease log in to view more.";

    foreach ($recipients as $r) {
        sendEmail($r['email'], $subject, $body, $altBody);
    }

    if (function_exists('rs_get_notification_roles')) {
        foreach ($recipients as $r) {
            @$conn->query("
                INSERT INTO admin_notifications (role, title, body, link)
                VALUES ('" . $conn->real_escape_string($r['role']) . "', '" . $conn->real_escape_string($subject) . "', '"
                . $conn->real_escape_string($summary) . "', '" . $conn->real_escape_string((string)$link) . "')
            ");
        }
    }
}


function ms_notify_venture_feedback_reviewed(
    mysqli $conn,
    int $session_id,
    string $session_title,
    string $review_notes
): void {
    if (!function_exists('sendEmail')) {
        return;
    }

    $ventures = ms_get_session_ventures($conn, $session_id);

    if (!$ventures) {
        return;
    }

    $programme = defined('SITE_NAME') ? SITE_NAME : 'Hive Colab';
    $subject   = 'Your mentorship feedback report has been reviewed';
    $safeTitle = ms_h($session_title);
    $safeNotes = ms_h($review_notes);

    foreach ($ventures as $v) {
        if (empty($v['founder_email']) || !filter_var($v['founder_email'], FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $safeName = ms_h((string)($v['name'] ?? 'there'));

        $content = "
            <h3>Feedback report reviewed</h3>
            <p>Hello {$safeName},</p>
            <p>The programme team has reviewed the Venture Mentorship Feedback Report you submitted for the session \"{$safeTitle}\".</p>
        ";

        if ($review_notes !== '') {
            $content .= "
                <div class='info-box'>
                    <p><strong>Notes from the programme team:</strong></p>
                    <p>{$safeNotes}</p>
                </div>
            ";
        }

        $content .= "
            <p>Thank you,<br>" . ms_h($programme) . "</p>
        ";

        $body = function_exists('email_wrapper') ? email_wrapper($content) : $content;

        $altBody = "Hello {$v['name']},\n\n"
            . "The programme team has reviewed the Venture Mentorship Feedback Report you submitted for the session \"{$session_title}\".\n\n"
            . ($review_notes !== '' ? "Notes from the programme team:\n{$review_notes}\n\n" : '')
            . "Thank you,\n{$programme}";

        sendEmail($v['founder_email'], $subject, $body, $altBody);
    }
}

function ms_nullable_date(string $value): ?string
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    $time = strtotime($value);

    if ($time === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $time);
}

function ms_get_venture_cohort(mysqli $conn, int $venture_id): ?int
{
    $stmt = $conn->prepare("SELECT cohort_id FROM ventures WHERE id = ? LIMIT 1");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $venture_id);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    return !empty($row['cohort_id']) ? (int)$row['cohort_id'] : null;
}

function ms_log_engagement(
    mysqli $conn,
    int $mentor_id,
    int $venture_id,
    int $session_id,
    int $duration_minutes
): void {
    if ($mentor_id <= 0 || $venture_id <= 0 || $session_id <= 0) {
        return;
    }

    $chk = $conn->prepare("
        SELECT id
        FROM mentor_engagement_log
        WHERE session_id = ? AND mentor_id = ? AND venture_id = ?
        LIMIT 1
    ");

    if (!$chk) {
        return;
    }

    $chk->bind_param('iii', $session_id, $mentor_id, $venture_id);
    $chk->execute();

    if ($chk->get_result()->num_rows > 0) {
        $chk->close();
        return;
    }

    $chk->close();

    $hours    = round($duration_minutes / 60, 2);
    $log_type = 'session';

    $stmt = $conn->prepare("
        INSERT INTO mentor_engagement_log
            (mentor_id, venture_id, session_id, log_type, hours_logged)
        VALUES
            (?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        return;
    }

    $stmt->bind_param(
        'iiisd',
        $mentor_id,
        $venture_id,
        $session_id,
        $log_type,
        $hours
    );

    $stmt->execute();
    $stmt->close();
}


function ms_log_engagement_for_session(mysqli $conn, int $session_id, int $duration_minutes): void
{
    $mentors  = ms_get_session_mentors($conn, $session_id);
    $ventures = ms_get_session_ventures($conn, $session_id);

    foreach ($mentors as $m) {
        if ($m['invite_status'] !== 'accepted') {
            continue;
        }

        foreach ($ventures as $v) {
            ms_log_engagement(
                $conn,
                (int)$m['mentor_id'],
                (int)$v['venture_id'],
                $session_id,
                $duration_minutes
            );
        }
    }
}

function ms_send_session_reminder(
    mysqli $conn,
    int $session_id,
    string $title,
    ?string $scheduled_at,
    ?string $meeting_link
): array {
    $sent   = 0;
    $failed = 0;
    $errors = [];

    $mentors  = ms_get_session_mentors($conn, $session_id);
    $ventures = ms_get_session_ventures($conn, $session_id);

    $recipients = [];

    foreach ($mentors as $m) {
        if ($m['invite_status'] === 'declined') {
            continue;
        }
        if (!empty($m['email']) && filter_var($m['email'], FILTER_VALIDATE_EMAIL)) {
            $recipients[] = [
                'email' => trim((string)$m['email']),
                'name'  => trim((string)($m['full_name'] ?? 'Mentor')),
                'type'  => 'mentor'
            ];
        } else {
            $errors[] = 'Mentor email is missing or invalid for mentor #' . (int)$m['mentor_id'];
        }
    }

    foreach ($ventures as $v) {
        if (!empty($v['founder_email']) && filter_var($v['founder_email'], FILTER_VALIDATE_EMAIL)) {
            $recipients[] = [
                'email' => trim((string)$v['founder_email']),
                'name'  => trim((string)($v['name'] ?? 'Venture')),
                'type'  => 'venture'
            ];
        } else {
            $errors[] = 'Venture email is missing or invalid for venture #' . (int)$v['venture_id'];
        }
    }

    if (empty($recipients)) {
        error_log("Session reminder not sent for session {$session_id}: no valid recipients.");
        return [
            'sent'   => 0,
            'failed' => 0,
            'errors' => $errors
        ];
    }

    $dt = $scheduled_at
        ? date('l, F j, Y \a\t g:i A', strtotime($scheduled_at))
        : 'To be confirmed';

    $programme = defined('SITE_NAME') ? SITE_NAME : 'Hive Colab';
    $subject   = 'Reminder: Mentoring Session - ' . $title;

    foreach ($recipients as $recipient) {
        $safeName  = ms_h($recipient['name']);
        $safeTitle = ms_h($title);
        $safeDate  = ms_h($dt);
        $safeLink  = ms_h((string)$meeting_link);

        $content = "
            <h3>Mentoring Session Reminder</h3>

            <p>Hello {$safeName},</p>

            <p>This is a reminder for your upcoming mentoring session.</p>

            <div class='info-box'>
                <p><strong>Session:</strong> {$safeTitle}</p>
                <p><strong>Date:</strong> {$safeDate}</p>
        ";

        if (!empty($meeting_link)) {
            $content .= "
                <p>
                    <strong>Meeting Link:</strong>
                    <a href='{$safeLink}' target='_blank'>Join Session</a>
                </p>
            ";
        }

        $content .= "
            </div>

            <p>Thank you,<br>" . ms_h($programme) . "</p>
        ";

        $body = function_exists('email_wrapper')
            ? email_wrapper($content)
            : $content;

        $altBody = "Hello {$recipient['name']},\n\n"
            . "This is a reminder for your upcoming mentoring session.\n\n"
            . "Session: {$title}\n"
            . "Date: {$dt}\n";

        if (!empty($meeting_link)) {
            $altBody .= "Meeting Link: {$meeting_link}\n";
        }

        $altBody .= "\nThank you,\n{$programme}";

        if (!function_exists('sendEmail')) {
            $failed++;
            $errors[] = 'sendEmail() function not found.';
            error_log('Session reminder failed: sendEmail() function not found.');
            continue;
        }

        try {
            $result = sendEmail(
                $recipient['email'],
                $subject,
                $body,
                $altBody
            );

            if ($result === true) {
                $sent++;
            } else {
                $failed++;
                $errors[] = $recipient['email'] . ': ' . (string)$result;
                error_log("Session reminder failed for {$recipient['email']}: " . (string)$result);
            }
        } catch (Throwable $e) {
            $failed++;
            $errors[] = $recipient['email'] . ': ' . $e->getMessage();
            error_log(
                "Session reminder exception for {$recipient['email']}: "
                . $e->getMessage()
            );
        }
    }

    if ($sent > 0) {
        $st = $conn->prepare("
            UPDATE mentor_sessions
            SET reminder_24h_sent = 1
            WHERE id = ?
            LIMIT 1
        ");

        if ($st) {
            $st->bind_param('i', $session_id);
            $st->execute();
            $st->close();
        }
    }

    return [
        'sent'   => $sent,
        'failed' => $failed,
        'errors' => $errors
    ];
}

function ms_send_co_mentor_invite_email(
    mysqli $conn,
    int $session_id,
    int $mentor_id,
    string $title,
    ?string $scheduled_at,
    string $invited_by
): void {
    $st = $conn->prepare("SELECT full_name, email FROM mentors WHERE id = ? LIMIT 1");

    if (!$st) {
        return;
    }

    $st->bind_param('i', $mentor_id);
    $st->execute();

    $mentor = $st->get_result()->fetch_assoc();

    $st->close();

    if (!$mentor || empty($mentor['email']) || !filter_var($mentor['email'], FILTER_VALIDATE_EMAIL)) {
        return;
    }

    if (!function_exists('sendEmail')) {
        return;
    }

    $dt = $scheduled_at
        ? date('l, F j, Y \a\t g:i A', strtotime($scheduled_at))
        : 'To be confirmed';

    $programme = defined('SITE_NAME') ? SITE_NAME : 'Hive Colab';
    $subject   = 'Co-mentor invite: ' . $title;
    $safeName  = ms_h((string)$mentor['full_name']);
    $safeTitle = ms_h($title);
    $safeDate  = ms_h($dt);
    $safeBy    = ms_h($invited_by);

    $content = "
        <h3>You've been invited to co-mentor a session</h3>
        <p>Hello {$safeName},</p>
        <p>{$safeBy} has invited you to join the following mentoring session:</p>
        <div class='info-box'>
            <p><strong>Session:</strong> {$safeTitle}</p>
            <p><strong>Date:</strong> {$safeDate}</p>
        </div>
        <p>Please log in to accept or decline this invite.</p>
        <p>Thank you,<br>" . ms_h($programme) . "</p>
    ";

    $body = function_exists('email_wrapper') ? email_wrapper($content) : $content;

    $altBody = "Hello {$mentor['full_name']},\n\n"
        . "{$invited_by} has invited you to co-mentor: {$title}\n"
        . "Date: {$dt}\n\n"
        . "Please log in to accept or decline this invite.\n\n"
        . "Thank you,\n{$programme}";

    try {
        sendEmail($mentor['email'], $subject, $body, $altBody);
    } catch (Throwable $e) {
        error_log(
            'Co-mentor invite email failed for mentor '
            . $mentor_id
            . ': '
            . $e->getMessage()
        );
    }
}

$action = ms_post('action') ?: trim((string)($_GET['action'] ?? ''));
if ($action === 'download_report' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$CAN_MANAGE) {
        http_response_code(403);
        echo 'Access denied. Venture session reports are available only to the programme team.';
        exit;
    }

    $report_id = (int)($_GET['id'] ?? 0);

    if ($report_id <= 0) {
        http_response_code(404);
        echo 'Not found.';
        exit;
    }

    $st = $conn->prepare("
        SELECT sr.*, ms.id AS session_id
        FROM session_reports sr
        INNER JOIN mentor_sessions ms ON ms.id = sr.session_id
        WHERE sr.id = ?
        LIMIT 1
    ");

    if (!$st) {
        http_response_code(500);
        echo 'DB error.';
        exit;
    }

    $st->bind_param('i', $report_id);
    $st->execute();

    $report = $st->get_result()->fetch_assoc();

    $st->close();

    if (!$report) {
        http_response_code(404);
        echo 'Report not found.';
        exit;
    }

    if ($IS_MENTOR && !$CAN_MANAGE && !ms_owns_session($conn, (int)$report['session_id'], $MENTOR_RECORD_ID)) {
        http_response_code(403);
        echo 'Access denied.';
        exit;
    }

    $file_path = trim((string)($report['file_path'] ?? ''));

    if ($file_path === '') {
        http_response_code(404);
        echo 'File path missing.';
        exit;
    }

    $abs = rtrim(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2), '/')
        . '/'
        . ltrim($file_path, '/');

    if (!file_exists($abs) || !is_file($abs)) {
        http_response_code(404);
        echo 'File not found on server.';
        exit;
    }

    $download_name = preg_replace(
        '/[^A-Za-z0-9_\-. ]/',
        '_',
        (string)($report['report_title'] ?? basename($abs))
    );

    $mime = mime_content_type($abs) ?: 'application/octet-stream';

    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . basename($download_name) . '"');
    header('Content-Length: ' . filesize($abs));
    header('Cache-Control: private, no-store');

    readfile($abs);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_redirect();
}

if ($action === 'upload_report') {
    if (!$CAN_MANAGE) {
        ms_flash('Session report uploads on this page are restricted to the programme team. Use the mentor Reports Hub for mentor reports.', 'error');
        ms_redirect();
    }

    $session_id   = (int)($_POST['session_id'] ?? 0);
    $report_title = trim((string)($_POST['report_title'] ?? ''));
    $report_notes = trim((string)($_POST['report_notes'] ?? ''));

    if ($session_id <= 0) {
        ms_flash('Invalid session.', 'error');
        ms_redirect();
    }

    if ($IS_MENTOR && !$CAN_MANAGE && !ms_owns_session($conn, $session_id, $MENTOR_RECORD_ID)) {
        ms_flash('You can only upload reports for sessions you are part of.', 'error');
        ms_redirect();
    }

    $st = $conn->prepare("SELECT status, title FROM mentor_sessions WHERE id = ? LIMIT 1");

    if (!$st) {
        ms_flash('DB error: ' . $conn->error, 'error');
        ms_redirect();
    }

    $st->bind_param('i', $session_id);
    $st->execute();

    $sess_row = $st->get_result()->fetch_assoc();

    $st->close();

    if (!$sess_row || $sess_row['status'] !== 'completed') {
        ms_flash('Reports can only be uploaded for completed sessions.', 'error');
        ms_redirect();
    }

    if (empty($_FILES['report_file']['name'])) {
        ms_flash('Please select a file to upload.', 'error');
        ms_redirect();
    }

    $allowed_exts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
    $originalName  = (string)$_FILES['report_file']['name'];
    $ext           = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed_exts, true)) {
        ms_flash('File type not allowed. Use PDF, Word, Excel, or PowerPoint.', 'error');
        ms_redirect();
    }

    $max_bytes = 20 * 1024 * 1024;

    if ((int)$_FILES['report_file']['size'] > $max_bytes) {
        ms_flash('File too large. Maximum allowed size is 20 MB.', 'error');
        ms_redirect();
    }

    if ((int)$_FILES['report_file']['error'] !== UPLOAD_ERR_OK) {
        ms_flash('Upload error code: ' . (int)$_FILES['report_file']['error'], 'error');
        ms_redirect();
    }

    $dest_dir = rtrim(
        defined('UPLOAD_PATH') ? UPLOAD_PATH : dirname(__DIR__, 2) . '/uploads',
        '/'
    ) . '/session-reports/';

    if (!is_dir($dest_dir)) {
        mkdir($dest_dir, 0755, true);
    }

    $filename = uniqid('rpt_', true) . '.' . $ext;
    $destFile = $dest_dir . $filename;

    if (!move_uploaded_file($_FILES['report_file']['tmp_name'], $destFile)) {
        ms_flash('Failed to save file. Check directory permissions.', 'error');
        ms_redirect();
    }

    $file_path    = 'uploads/session-reports/' . $filename;
    $file_size    = (int)$_FILES['report_file']['size'];
    $file_name    = $originalName;
    $uploaded_by  = $GLOBALS['ADMIN']['full_name'] ?? 'mentor';
    $report_title = $report_title !== '' ? $report_title : 'Session report';

    $st = $conn->prepare("
        INSERT INTO session_reports
            (session_id, report_title, file_path, file_name, file_size, file_ext, report_notes, uploaded_by)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$st) {
        ms_flash('DB error: ' . $conn->error, 'error');
        ms_redirect();
    }

    $st->bind_param(
        'isssisss',
        $session_id,
        $report_title,
        $file_path,
        $file_name,
        $file_size,
        $ext,
        $report_notes,
        $uploaded_by
    );

    $ok  = $st->execute();
    $err = $st->error;

    $st->close();

    if ($ok) {
       
        ms_notify_stakeholders(
            $conn,
            'New session report uploaded: ' . ($sess_row['title'] ?? 'Session'),
            $uploaded_by . ' uploaded a report ("' . $report_title . '") for session "' . ($sess_row['title'] ?? '') . '".',
            'mentor-sessions.php'
        );
    }

    ms_flash(
        $ok ? 'Report uploaded successfully.' : 'Upload failed: ' . $err,
        $ok ? 'success' : 'error'
    );

    ms_redirect();
}

if ($action === 'mentor_rate_venture') {
    if (!$IS_MENTOR) {
        ms_flash('Only mentors can rate ventures.', 'error');
        ms_redirect();
    }

    $session_id      = (int)($_POST['id'] ?? 0);
    $venture_id      = (int)($_POST['venture_id'] ?? 0);
    $mentor_rating   = max(1, min(5, (int)($_POST['mentor_rating'] ?? 0)));
    $mentor_feedback = trim((string)($_POST['mentor_feedback'] ?? ''));

    if ($session_id <= 0 || $mentor_rating < 1) {
        ms_flash('Invalid rating.', 'error');
        ms_redirect();
    }

    if (!ms_owns_session($conn, $session_id, $MENTOR_RECORD_ID)) {
        ms_flash('You can only rate ventures for sessions you are part of.', 'error');
        ms_redirect();
    }

    $st = $conn->prepare("SELECT status FROM mentor_sessions WHERE id = ? LIMIT 1");

    if (!$st) {
        ms_flash('DB error: ' . $conn->error, 'error');
        ms_redirect();
    }

    $st->bind_param('i', $session_id);
    $st->execute();

    $row = $st->get_result()->fetch_assoc();

    $st->close();

    if (!$row || $row['status'] !== 'completed') {
        ms_flash('You can only rate ventures for completed sessions.', 'error');
        ms_redirect();
    }

    $st = $conn->prepare("
        UPDATE mentor_sessions
        SET mentor_rating = ?, mentor_feedback = ?
        WHERE id = ?
        LIMIT 1
    ");

    if (!$st) {
        ms_flash('DB error: ' . $conn->error, 'error');
        ms_redirect();
    }

    $st->bind_param(
        'isi',
        $mentor_rating,
        $mentor_feedback,
        $session_id
    );

    $ok  = $st->execute();
    $err = $st->error;

    $st->close();

    ms_flash(
        $ok ? 'Venture rated successfully. Thank you!' : 'Failed: ' . $err,
        $ok ? 'success' : 'error'
    );

    ms_redirect();
}

if ($action === 'add_comment') {
    $session_id   = (int)($_POST['session_id'] ?? 0);
    $comment_text = trim((string)($_POST['comment_text'] ?? ''));
    $author_type  = ms_is_mentor() ? 'mentor' : 'admin';
    $author_name  = trim((string)($_POST['author_name'] ?? ($GLOBALS['ADMIN']['full_name'] ?? 'Admin')));

    if ($session_id <= 0 || $comment_text === '') {
        ms_flash('Comment cannot be empty.', 'error');
        ms_redirect();
    }

    if ($IS_MENTOR && !$CAN_MANAGE && !ms_owns_session($conn, $session_id, $MENTOR_RECORD_ID)) {
        ms_flash('You can only comment on sessions you are part of.', 'error');
        ms_redirect();
    }

    $st = $conn->prepare("
        INSERT INTO session_comments
            (session_id, author_type, author_name, comment_text)
        VALUES
            (?, ?, ?, ?)
    ");

    if (!$st) {
        ms_flash('DB error: ' . $conn->error, 'error');
        ms_redirect();
    }

    $st->bind_param(
        'isss',
        $session_id,
        $author_type,
        $author_name,
        $comment_text
    );

    $ok  = $st->execute();
    $err = $st->error;

    $st->close();

    ms_flash(
        $ok ? 'Comment added.' : 'Failed: ' . $err,
        $ok ? 'success' : 'error'
    );

    ms_redirect();
}

if ($action === 'admin_rate_session') {
    if (!$CAN_MANAGE) {
        ms_flash('Access denied.', 'error');
        ms_redirect();
    }

    $session_id   = (int)($_POST['id'] ?? 0);
    $admin_rating = max(1, min(5, (int)($_POST['admin_rating'] ?? 0)));
    $admin_notes  = trim((string)($_POST['admin_notes'] ?? ''));

    if ($session_id <= 0 || $admin_rating < 1) {
        ms_flash('Invalid rating.', 'error');
        ms_redirect();
    }

    $st = $conn->prepare("
        UPDATE mentor_sessions
        SET admin_rating = ?, admin_notes = ?
        WHERE id = ?
        LIMIT 1
    ");

    if (!$st) {
        ms_flash('DB error: ' . $conn->error, 'error');
        ms_redirect();
    }

    $st->bind_param('isi', $admin_rating, $admin_notes, $session_id);

    $ok  = $st->execute();
    $err = $st->error;

    $st->close();

    ms_flash(
        $ok ? 'Rating saved.' : 'Failed: ' . $err,
        $ok ? 'success' : 'error'
    );

    ms_redirect();
}

if ($action === 'send_session_message') {
    if (!$CAN_MANAGE) {
        ms_flash('Access denied.', 'error');
        ms_redirect();
    }

    $session_id = (int)($_POST['session_id'] ?? 0);
    $message    = trim((string)($_POST['message'] ?? ''));

    if ($session_id <= 0 || $message === '') {
        ms_flash('Message cannot be empty.', 'error');
        ms_redirect();
    }

    $st = $conn->prepare("SELECT title FROM mentor_sessions WHERE id = ? LIMIT 1");

    if (!$st) {
        ms_flash('DB error: ' . $conn->error, 'error');
        ms_redirect();
    }

    $st->bind_param('i', $session_id);
    $st->execute();

    $sess = $st->get_result()->fetch_assoc();

    $st->close();

    if (!$sess) {
        ms_flash('Session not found.', 'error');
        ms_redirect();
    }

    $ventures = ms_get_session_ventures($conn, $session_id);

    if (!$ventures) {
        ms_flash('No ventures attached to this session.', 'error');
        ms_redirect();
    }

    $subject = 'Message about: ' . ($sess['title'] ?? 'Session');
    $sender  = $GLOBALS['ADMIN']['full_name'] ?? 'Programme Team';

    $st = $conn->prepare("
        INSERT INTO venture_messages
            (venture_id, sender, subject, body, is_read, created_at)
        VALUES
            (?, ?, ?, ?, 0, NOW())
    ");

    if (!$st) {
        ms_flash('DB error: ' . $conn->error, 'error');
        ms_redirect();
    }

    $sent_count = 0;
    $last_err   = '';

    foreach ($ventures as $v) {
        $vid = (int)$v['venture_id'];
        $st->bind_param('isss', $vid, $sender, $subject, $message);

        if ($st->execute()) {
            $sent_count++;
        } else {
            $last_err = $st->error;
        }
    }

    $st->close();

    ms_flash(
        $sent_count > 0
            ? "Message sent to {$sent_count} venture(s)."
            : 'Failed: ' . $last_err,
        $sent_count > 0 ? 'success' : 'error'
    );

    ms_redirect();
}

if ($action === 'save') {
    if (!$CAN_MANAGE && !$IS_MENTOR) {
        ms_flash('Access denied.', 'error');
        ms_redirect();
    }

    $id            = (int)($_POST['id'] ?? 0);
    $title         = ms_post('title');
    $description   = ms_post('description');
    $notes_shared  = ms_post('notes_shared');
    $action_items  = ms_post('action_items');
    $meeting_link  = ms_post('meeting_link');
    $duration      = max(15, (int)($_POST['duration_minutes'] ?? 60));
    $organizer_id  = (int)($_POST['mentor_id'] ?? 0);
    $cohort_id_raw = (int)($_POST['cohort_id'] ?? 0);
    $cohort_id     = $cohort_id_raw > 0 ? $cohort_id_raw : null;
    $scheduled_at  = ms_nullable_date(ms_post('scheduled_at'));
    $send_reminder = isset($_POST['send_reminder']);
    $all_ventures  = ($_POST['all_ventures'] ?? '0') === '1';

    $co_mentor_ids = array_map('intval', (array)($_POST['co_mentor_ids'] ?? []));
    $venture_ids   = array_map('intval', (array)($_POST['venture_id'] ?? []));

    if ($IS_MENTOR && !$CAN_MANAGE) {
        $organizer_id = $MENTOR_RECORD_ID;
    }

    if ($all_ventures) {
        $venture_ids = ms_all_venture_ids($conn);
    }

    $venture_ids = array_values(array_unique(array_filter($venture_ids, fn($v) => $v > 0)));

    $allowed_types = [
        'one_on_one',
        'group',
        'workshop',
        'review',
        'ad_hoc'
    ];

    $allowed_statuses = [
        'requested',
        'scheduled',
        'confirmed',
        'completed',
        'cancelled',
        'no_show'
    ];

    $allowed_platforms = [
        'google_meet',
        'zoom',
        'teams',
        'phone',
        'in_person',
        'other'
    ];

    $posted_type     = ms_post('session_type');
    $posted_status   = ms_post('status');
    $posted_platform = ms_post('meeting_platform');

    $session_type = in_array($posted_type, $allowed_types, true)
        ? $posted_type
        : 'one_on_one';

    $status = in_array($posted_status, $allowed_statuses, true)
        ? $posted_status
        : 'scheduled';

    $meeting_platform = in_array($posted_platform, $allowed_platforms, true)
        ? $posted_platform
        : 'zoom';

    if ($title === '') {
        ms_flash('Title is required.', 'error');
        ms_redirect();
    }

    if ($organizer_id <= 0) {
        ms_flash('An organizing mentor is required.', 'error');
        ms_redirect();
    }

    if (!$venture_ids) {
        ms_flash('At least one venture is required (or choose "All ventures").', 'error');
        ms_redirect();
    }

    $legacy_venture_id = $venture_ids[0];

    if ($cohort_id === null) {
        $cohort_id = ms_get_venture_cohort($conn, $legacy_venture_id);
    }

    ms_ensure_session_response_columns($conn);

    $confirmed_at = $status === 'confirmed' ? date('Y-m-d H:i:s') : null;
    $completed_at = $status === 'completed' ? date('Y-m-d H:i:s') : null;
    $cancelled_at = $status === 'cancelled' ? date('Y-m-d H:i:s') : null;

    $invited_by = $IS_MENTOR
        ? ($GLOBALS['ADMIN']['full_name'] ?? 'A mentor')
        : ($GLOBALS['ADMIN']['full_name'] ?? 'Programme Team');

    if ($id > 0) {
        if ($IS_MENTOR && !$CAN_MANAGE && !ms_is_session_organizer($conn, $id, $MENTOR_RECORD_ID)) {
            ms_flash('Only the session organizer can edit this session.', 'error');
            ms_redirect();
        }

        $st = $conn->prepare("
            UPDATE mentor_sessions
            SET
                title = ?,
                description = ?,
                session_type = ?,
                status = ?,
                scheduled_at = ?,
                duration_minutes = ?,
                meeting_platform = ?,
                meeting_link = ?,
                notes_shared = ?,
                action_items = ?,
                mentor_id = ?,
                venture_id = ?,
                cohort_id = ?,
                confirmed_at = COALESCE(confirmed_at, ?),
                completed_at = COALESCE(completed_at, ?),
                cancelled_at = COALESCE(cancelled_at, ?)
            WHERE id = ?
            LIMIT 1
        ");

        if (!$st) {
            ms_flash('DB error: ' . $conn->error, 'error');
            ms_redirect();
        }

        $st->bind_param(
            'sssssissssiiisssi',
            $title,
            $description,
            $session_type,
            $status,
            $scheduled_at,
            $duration,
            $meeting_platform,
            $meeting_link,
            $notes_shared,
            $action_items,
            $organizer_id,
            $legacy_venture_id,
            $cohort_id,
            $confirmed_at,
            $completed_at,
            $cancelled_at,
            $id
        );

        $ok  = $st->execute();
        $err = $st->error;

        $st->close();

        if ($ok) {
            $mentorSync  = ms_sync_session_mentors($conn, $id, $organizer_id, $co_mentor_ids, $invited_by);
            $ventureSync = ms_sync_session_ventures($conn, $id, $venture_ids, $invited_by);

            if (!$mentorSync['ok'] || !$ventureSync['ok']) {
                $syncErr = trim(($mentorSync['error'] ?? '') . ' ' . ($ventureSync['error'] ?? ''));
                ms_flash(
                    'Session details updated, but attendees failed to sync: ' . $syncErr,
                    'warning'
                );
                ms_redirect();
            }
        }

        if ($ok && $status === 'completed') {
            ms_log_engagement_for_session($conn, $id, $duration);
            ms_notify_stakeholders(
                $conn,
                'Session marked completed: ' . $title,
                $invited_by . ' marked "' . $title . '" as completed.',
                'mentor-sessions.php'
            );
        }

        if ($ok && $status === 'confirmed') {
            $existingEventRow = $conn->query(
                "SELECT google_event_id FROM mentor_sessions WHERE id = " . (int)$id . " LIMIT 1"
            )->fetch_assoc();

            ms_sync_session_calendar(
                $conn,
                $id,
                $organizer_id,
                $title,
                $scheduled_at,
                $duration,
                $description,
                $meeting_link,
                $existingEventRow['google_event_id'] ?? null
            );
        }

        if ($ok && $send_reminder && $scheduled_at) {
            ms_send_session_reminder($conn, $id, $title, $scheduled_at, $meeting_link);
        }

        ms_flash(
            $ok ? 'Session updated.' : 'Failed: ' . $err,
            $ok ? 'success' : 'error'
        );

        ms_redirect();
    }

    $requested_by = $IS_MENTOR && !$CAN_MANAGE ? 'mentor' : 'admin';

    $st = $conn->prepare("
        INSERT INTO mentor_sessions
            (
                title,
                description,
                session_type,
                status,
                scheduled_at,
                duration_minutes,
                meeting_platform,
                meeting_link,
                notes_shared,
                action_items,
                mentor_id,
                venture_id,
                cohort_id,
                requested_by,
                confirmed_at,
                completed_at,
                cancelled_at
            )
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$st) {
        ms_flash('DB error: ' . $conn->error, 'error');
        ms_redirect();
    }

    $st->bind_param(
        'sssssissssiiissss',
        $title,
        $description,
        $session_type,
        $status,
        $scheduled_at,
        $duration,
        $meeting_platform,
        $meeting_link,
        $notes_shared,
        $action_items,
        $organizer_id,
        $legacy_venture_id,
        $cohort_id,
        $requested_by,
        $confirmed_at,
        $completed_at,
        $cancelled_at
    );

    $ok     = $st->execute();
    $err    = $st->error;
    $new_id = $ok ? (int)$conn->insert_id : 0;

    $st->close();

    if (!$ok) {
        ms_flash('Failed: ' . $err, 'error');
        ms_redirect();
    }

    if ($new_id <= 0) {
        ms_flash('Session save did not return a valid ID; attendees were not attached. Please contact support.', 'error');
        ms_redirect();
    }

    $mentorSync  = ms_sync_session_mentors($conn, $new_id, $organizer_id, $co_mentor_ids, $invited_by);
    $ventureSync = ms_sync_session_ventures($conn, $new_id, $venture_ids, $invited_by);

    if (!$mentorSync['ok'] || !$ventureSync['ok']) {
        $syncErr = trim(($mentorSync['error'] ?? '') . ' ' . ($ventureSync['error'] ?? ''));
        ms_flash(
            'Session was created (ID ' . $new_id . '), but mentors/ventures failed to attach: ' . $syncErr
            . '. Edit the session and re-save to retry.',
            'warning'
        );
        ms_redirect();
    }

    if ($status === 'completed') {
        ms_log_engagement_for_session($conn, $new_id, $duration);
        ms_notify_stakeholders(
            $conn,
            'Session marked completed: ' . $title,
            $invited_by . ' marked "' . $title . '" as completed.',
            'mentor-sessions.php'
        );
    }

    if ($status === 'confirmed') {
        ms_sync_session_calendar(
            $conn,
            $new_id,
            $organizer_id,
            $title,
            $scheduled_at,
            $duration,
            $description,
            $meeting_link,
            null
        );
    }

    $mailResult = null;

    if ($send_reminder && $scheduled_at) {
        $mailResult = ms_send_session_reminder($conn, $new_id, $title, $scheduled_at, $meeting_link);
    }

    if ($co_mentor_ids) {
        foreach ($co_mentor_ids as $cmid) {
            if ($cmid > 0 && $cmid !== $organizer_id) {
                ms_send_co_mentor_invite_email($conn, $new_id, $cmid, $title, $scheduled_at, $invited_by);
            }
        }
    }

    if (is_array($mailResult) && $mailResult['failed'] > 0 && $mailResult['sent'] <= 0) {
        ms_flash('Session scheduled, but reminder email was not sent. Check email settings and recipient emails.', 'warning');
        ms_redirect();
    }

    ms_flash('Session scheduled.');
    ms_redirect();
}


if ($action === 'invite_mentor') {
    $session_id = (int)($_POST['session_id'] ?? 0);
    $new_mentor = (int)($_POST['mentor_id'] ?? 0);

    if ($session_id <= 0 || $new_mentor <= 0) {
        ms_flash('Invalid invite.', 'error');
        ms_redirect();
    }

    if (!$CAN_MANAGE && !ms_is_session_organizer($conn, $session_id, $MENTOR_RECORD_ID)) {
        ms_flash('Only the session organizer or a programme manager can invite mentors.', 'error');
        ms_redirect();
    }

    $st = $conn->prepare("SELECT id FROM session_mentors WHERE session_id = ? AND mentor_id = ? LIMIT 1");

    if ($st) {
        $st->bind_param('ii', $session_id, $new_mentor);
        $st->execute();

        if ($st->get_result()->num_rows > 0) {
            $st->close();
            ms_flash('That mentor is already on this session.', 'error');
            ms_redirect();
        }

        $st->close();
    }

    $invited_by = $GLOBALS['ADMIN']['full_name'] ?? 'Programme Team';

    $st = $conn->prepare("
        INSERT INTO session_mentors (session_id, mentor_id, role, invite_status, invited_by)
        VALUES (?, ?, 'co_mentor', 'invited', ?)
    ");

    if (!$st) {
        ms_flash('DB error: ' . $conn->error, 'error');
        ms_redirect();
    }

    $st->bind_param('iis', $session_id, $new_mentor, $invited_by);

    $ok  = $st->execute();
    $err = $st->error;

    $st->close();

    if ($ok) {
        $sess = $conn->prepare("SELECT title, scheduled_at FROM mentor_sessions WHERE id = ? LIMIT 1");
        if ($sess) {
            $sess->bind_param('i', $session_id);
            $sess->execute();
            $row = $sess->get_result()->fetch_assoc();
            $sess->close();

            if ($row) {
                ms_send_co_mentor_invite_email(
                    $conn,
                    $session_id,
                    $new_mentor,
                    (string)$row['title'],
                    $row['scheduled_at'] ?? null,
                    $invited_by
                );
            }
        }
    }

    ms_flash(
        $ok ? 'Mentor invited to the session.' : 'Failed: ' . $err,
        $ok ? 'success' : 'error'
    );

    ms_redirect();
}


if ($action === 'respond_invite') {
    if (!$IS_MENTOR) {
        ms_flash('Only mentors can respond to invites.', 'error');
        ms_redirect();
    }

    $session_id    = (int)($_POST['session_id'] ?? 0);
    $invite_status = ms_post('invite_status');

    if ($session_id <= 0 || !in_array($invite_status, ['accepted', 'declined'], true)) {
        ms_flash('Invalid response.', 'error');
        ms_redirect();
    }

    $st = $conn->prepare("
        UPDATE session_mentors
        SET invite_status = ?, responded_at = NOW()
        WHERE session_id = ? AND mentor_id = ? AND role = 'co_mentor'
        LIMIT 1
    ");

    if (!$st) {
        ms_flash('DB error: ' . $conn->error, 'error');
        ms_redirect();
    }

    $st->bind_param('sii', $invite_status, $session_id, $MENTOR_RECORD_ID);

    $ok       = $st->execute();
    $affected = $st->affected_rows;
    $err      = $st->error;

    $st->close();

    if ($ok && $affected === 0) {
        ms_flash('Invite not found for you on this session.', 'error');
        ms_redirect();
    }

    ms_flash(
        $ok
            ? ('Invite ' . ($invite_status === 'accepted' ? 'accepted.' : 'declined.'))
            : 'Failed: ' . $err,
        $ok ? 'success' : 'error'
    );

    ms_redirect();
}

if ($action === 'add_venture') {
    if (!$CAN_MANAGE) {
        ms_flash('Access denied.', 'error');
        ms_redirect();
    }

    $session_id = (int)($_POST['session_id'] ?? 0);
    $venture_id = (int)($_POST['venture_id'] ?? 0);

    if ($session_id <= 0 || $venture_id <= 0) {
        ms_flash('Invalid venture.', 'error');
        ms_redirect();
    }

    $added_by = $GLOBALS['ADMIN']['full_name'] ?? 'Programme Team';

    $st = $conn->prepare("
        INSERT INTO session_ventures (session_id, venture_id, added_by)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE session_ventures.session_id = session_ventures.session_id
    ");

    if (!$st) {
        ms_flash('DB error: ' . $conn->error, 'error');
        ms_redirect();
    }

    $st->bind_param('iis', $session_id, $venture_id, $added_by);

    $ok  = $st->execute();
    $err = $st->error;

    $st->close();

    ms_flash(
        $ok ? 'Venture added to session.' : 'Failed: ' . $err,
        $ok ? 'success' : 'error'
    );

    ms_redirect();
}

if ($action === 'update_status') {
    if (!$CAN_MANAGE && !$IS_MENTOR) {
        ms_flash('Access denied.', 'error');
        ms_redirect();
    }

    $id               = (int)($_POST['id'] ?? 0);
    $status           = ms_post('status');
    $rejection_reason = trim((string)($_POST['rejection_reason'] ?? ''));

    $allowed = [
        'requested',
        'scheduled',
        'confirmed',
        'completed',
        'cancelled',
        'no_show'
    ];

    if ($id <= 0 || !in_array($status, $allowed, true)) {
        ms_flash('Invalid status update.', 'error');
        ms_redirect();
    }

    $currentSt = $conn->prepare("
        SELECT id, title, status, mentor_id, scheduled_at, duration_minutes,
               description, meeting_link, google_event_id
        FROM mentor_sessions
        WHERE id = ?
        LIMIT 1
    ");

    if (!$currentSt) {
        ms_flash('DB error: ' . $conn->error, 'error');
        ms_redirect();
    }

    $currentSt->bind_param('i', $id);
    $currentSt->execute();
    $current = $currentSt->get_result()->fetch_assoc();
    $currentSt->close();

    if (!$current) {
        ms_flash('Session not found.', 'error');
        ms_redirect();
    }

    if ($IS_MENTOR && !$CAN_MANAGE) {
        if (!ms_owns_session($conn, $id, $MENTOR_RECORD_ID)) {
            ms_flash('You can only respond to sessions assigned to you.', 'error');
            ms_redirect();
        }

        $isOrganizer = ((int)($current['mentor_id'] ?? 0) === $MENTOR_RECORD_ID);
        if (function_exists('ms_is_session_organizer')) {
            $isOrganizer = $isOrganizer || ms_is_session_organizer($conn, $id, $MENTOR_RECORD_ID);
        }

        if (!$isOrganizer) {
            ms_flash('Only the organizing mentor can confirm or reject this session request.', 'error');
            ms_redirect();
        }

        if ((string)$current['status'] !== 'requested') {
            ms_flash('This session request has already been responded to.', 'error');
            ms_redirect();
        }

        if (!in_array($status, ['confirmed', 'cancelled'], true)) {
            ms_flash('Mentors can only confirm or reject a requested session.', 'error');
            ms_redirect();
        }

        if ($status === 'cancelled' && $rejection_reason === '') {
            ms_flash('Please provide a reason for rejecting the session request.', 'error');
            ms_redirect();
        }

        if (mb_strlen($rejection_reason) > 1000) {
            ms_flash('Rejection reason is too long (maximum 1000 characters).', 'error');
            ms_redirect();
        }
    }

    $confirmed_at = $status === 'confirmed' ? date('Y-m-d H:i:s') : null;
    $completed_at = $status === 'completed' ? date('Y-m-d H:i:s') : null;
    $cancelled_at = $status === 'cancelled' ? date('Y-m-d H:i:s') : null;

    $hasCancelReason = ms_has_column($conn, 'mentor_sessions', 'cancel_reason');
    $hasCancelledBy  = ms_has_column($conn, 'mentor_sessions', 'cancelled_by');

    $setParts = [
        'status = ?',
        'confirmed_at = COALESCE(confirmed_at, ?)',
        'completed_at = COALESCE(completed_at, ?)',
        'cancelled_at = COALESCE(cancelled_at, ?)'
    ];

    if ($status === 'cancelled' && $hasCancelReason) {
        $setParts[] = 'cancel_reason = ?';
    }
    if ($status === 'cancelled' && $hasCancelledBy) {
        $setParts[] = "cancelled_by = '" . (($IS_MENTOR && !$CAN_MANAGE) ? 'mentor' : 'admin') . "'";
    }

    $sql = "UPDATE mentor_sessions SET " . implode(', ', $setParts) . " WHERE id = ? LIMIT 1";
    $st = $conn->prepare($sql);

    if (!$st) {
        ms_flash('DB error: ' . $conn->error, 'error');
        ms_redirect();
    }

    if ($status === 'cancelled' && $hasCancelReason) {
        $reasonToSave = $rejection_reason !== '' ? $rejection_reason : 'Cancelled by programme team';
        $st->bind_param('sssssi', $status, $confirmed_at, $completed_at, $cancelled_at, $reasonToSave, $id);
    } else {
        $st->bind_param('ssssi', $status, $confirmed_at, $completed_at, $cancelled_at, $id);
    }

    $ok  = $st->execute();
    $err = $st->error;
    $st->close();

    if ($ok && $IS_MENTOR && !$CAN_MANAGE && in_array($status, ['confirmed', 'cancelled'], true)) {
        $inviteStatus = $status === 'confirmed' ? 'accepted' : 'declined';
        $sm = $conn->prepare("
            UPDATE session_mentors
            SET invite_status = ?, responded_at = NOW()
            WHERE session_id = ? AND mentor_id = ?
            LIMIT 1
        ");
        if ($sm) {
            $sm->bind_param('sii', $inviteStatus, $id, $MENTOR_RECORD_ID);
            $sm->execute();
            $sm->close();
        }

        ms_notify_ventures_session_decision(
            $conn,
            $id,
            (string)($current['title'] ?? 'Mentorship Session'),
            $status,
            $status === 'cancelled' ? $rejection_reason : ''
        );

        $actor = $GLOBALS['ADMIN']['full_name'] ?? 'Mentor';
        ms_notify_stakeholders(
            $conn,
            $status === 'confirmed'
                ? 'Mentor confirmed session: ' . (string)$current['title']
                : 'Mentor rejected session: ' . (string)$current['title'],
            $status === 'confirmed'
                ? $actor . ' confirmed the requested session "' . (string)$current['title'] . '".'
                : $actor . ' rejected the requested session "' . (string)$current['title'] . '". Reason: ' . $rejection_reason,
            'mentor-sessions.php'
        );
    }

    if ($ok && $status === 'completed') {
        ms_log_engagement_for_session($conn, $id, (int)($current['duration_minutes'] ?? 0));

        $actor = $GLOBALS['ADMIN']['full_name'] ?? 'Programme Team';
        ms_notify_stakeholders(
            $conn,
            'Session marked completed: ' . ($current['title'] ?? ''),
            $actor . ' marked "' . ($current['title'] ?? '') . '" as completed.',
            'mentor-sessions.php'
        );
    }

    if ($ok && $status === 'confirmed') {
        ms_sync_session_calendar(
            $conn,
            $id,
            (int)($current['mentor_id'] ?? 0),
            (string)($current['title'] ?? ''),
            $current['scheduled_at'] ?? null,
            (int)($current['duration_minutes'] ?? 60),
            (string)($current['description'] ?? ''),
            (string)($current['meeting_link'] ?? ''),
            $current['google_event_id'] ?? null
        );
    }

    if ($ok && $IS_MENTOR && !$CAN_MANAGE) {
        ms_flash(
            $status === 'confirmed'
                ? 'Session confirmed successfully. The venture has been notified.'
                : 'Session request rejected. Your reason has been recorded and the venture has been notified.',
            'success'
        );
        ms_redirect();
    }

    ms_flash(
        $ok
            ? 'Status updated to ' . ucfirst(str_replace('_', ' ', $status)) . '.'
            : 'Failed: ' . $err,
        $ok ? 'success' : 'error'
    );

    ms_redirect();
}

if ($action === 'delete') {
    if (!$CAN_MANAGE) {
        ms_flash('Access denied.', 'error');
        ms_redirect();
    }

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        ms_flash('Invalid session.', 'error');
        ms_redirect();
    }

    $conn->query("DELETE FROM session_mentors WHERE session_id = " . (int)$id);
    $conn->query("DELETE FROM session_ventures WHERE session_id = " . (int)$id);

    $st = $conn->prepare("DELETE FROM mentor_sessions WHERE id = ? LIMIT 1");

    if (!$st) {
        ms_flash('DB error: ' . $conn->error, 'error');
        ms_redirect();
    }

    $st->bind_param('i', $id);

    $ok  = $st->execute();
    $err = $st->error;

    $st->close();

    ms_flash(
        $ok ? 'Session deleted.' : 'Failed: ' . $err,
        $ok ? 'success' : 'error'
    );

    ms_redirect();
}

if ($action === 'send_reminder') {
    if (!$CAN_MANAGE) {
        ms_flash('Access denied.', 'error');
        ms_redirect();
    }

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        ms_flash('Invalid session.', 'error');
        ms_redirect();
    }

    $st = $conn->prepare("SELECT id, title, scheduled_at, meeting_link FROM mentor_sessions WHERE id = ? LIMIT 1");

    if (!$st) {
        ms_flash('DB error: ' . $conn->error, 'error');
        ms_redirect();
    }

    $st->bind_param('i', $id);
    $st->execute();

    $sess = $st->get_result()->fetch_assoc();

    $st->close();

    if (!$sess) {
        ms_flash('Session not found.', 'error');
        ms_redirect();
    }

    $result = ms_send_session_reminder(
        $conn,
        (int)$sess['id'],
        (string)$sess['title'],
        $sess['scheduled_at'] ?? null,
        $sess['meeting_link'] ?? null
    );

    if ($result['sent'] > 0 && $result['failed'] <= 0) {
        ms_flash('Reminder sent successfully.');
    } elseif ($result['sent'] > 0) {
        ms_flash('Reminder partly sent. Some recipients failed.', 'warning');
    } else {
        ms_flash('Reminder was not sent. Check mentor/venture emails and SMTP settings.', 'error');
    }

    ms_redirect();
}

// ==============================================================
//  REVIEW VENTURE MENTORSHIP FEEDBACK REPORT (admin/programme side)
// ==============================================================
//  Marks a venture-submitted session_feedback_reports row as
//  'reviewed', recording who reviewed it, when, and any notes for the
//  programme's own records / to relay back to the venture. Only rows
//  currently 'submitted' can be transitioned this way -- a venture's
//  own draft (not yet submitted) isn't visible to review here, and this
//  can be called again on an already-'reviewed' row purely to update the
//  review_notes (status/reviewed_at/reviewed_by are simply re-saved).
if ($action === 'review_feedback_report') {
    if (!$CAN_MANAGE) {
        ms_flash('Access denied.', 'error');
        ms_redirect();
    }

    $session_id   = (int)($_POST['session_id'] ?? 0);
    $review_notes = trim((string)($_POST['review_notes'] ?? ''));

    if ($session_id <= 0) {
        ms_flash('Invalid session.', 'error');
        ms_redirect();
    }

    if (!ms_tbl_exists($conn, 'session_feedback_reports')) {
        ms_flash('Feedback reports are not available on this install.', 'error');
        ms_redirect();
    }

    $st = $conn->prepare("
        SELECT sfr.status, ms.title
        FROM session_feedback_reports sfr
        INNER JOIN mentor_sessions ms ON ms.id = sfr.session_id
        WHERE sfr.session_id = ?
        LIMIT 1
    ");

    if (!$st) {
        ms_flash('DB error: ' . $conn->error, 'error');
        ms_redirect();
    }

    $st->bind_param('i', $session_id);
    $st->execute();

    $row = $st->get_result()->fetch_assoc();

    $st->close();

    if (!$row) {
        ms_flash('No feedback report found for this session.', 'error');
        ms_redirect();
    }

    if (!in_array($row['status'], ['submitted', 'reviewed'], true)) {
        ms_flash('Only submitted feedback reports can be reviewed.', 'error');
        ms_redirect();
    }

    $reviewer     = $GLOBALS['ADMIN']['full_name'] ?? 'Programme Team';
    $was_first_review = $row['status'] === 'submitted';

    $st = $conn->prepare("
        UPDATE session_feedback_reports
        SET status = 'reviewed',
            reviewed_at = NOW(),
            reviewed_by = ?,
            review_notes = ?
        WHERE session_id = ?
        LIMIT 1
    ");

    if (!$st) {
        ms_flash('DB error: ' . $conn->error, 'error');
        ms_redirect();
    }

    $st->bind_param('ssi', $reviewer, $review_notes, $session_id);

    $ok  = $st->execute();
    $err = $st->error;

    $st->close();

    if ($ok) {
        ms_notify_venture_feedback_reviewed(
            $conn,
            $session_id,
            (string)($row['title'] ?? 'Session'),
            $review_notes
        );
    }

    ms_flash(
        $ok
            ? ($was_first_review ? 'Feedback report marked as reviewed.' : 'Review notes updated.')
            : 'Failed: ' . $err,
        $ok ? 'success' : 'error'
    );

    ms_redirect();
}

ms_flash('Invalid action.', 'error');
ms_redirect();