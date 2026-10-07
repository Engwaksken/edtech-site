<?php
declare(strict_types=1);

/**
 * Notifications for Google Calendar sync outcomes on a confirmed session.
 * Requires ms_h(), ms_get_session_ventures() and sendEmail()/email_wrapper()
 * (from mentor-sessions-actions.php / session-junctions.php / mail-function.php)
 * to already be loaded.
 */

if (!function_exists('gcal_notify_mentor_reconnect')) {
    /**
     * Tell the organizing mentor their session was confirmed but couldn't
     * be synced because their Google Calendar connection needs renewing.
     */
    function gcal_notify_mentor_reconnect(
        mysqli $conn,
        int $mentorId,
        string $sessionTitle
    ): void {
        if (!function_exists('sendEmail') || $mentorId <= 0) {
            return;
        }

        $st = $conn->prepare("SELECT full_name, email FROM mentors WHERE id = ? LIMIT 1");
        if (!$st) {
            return;
        }
        $st->bind_param('i', $mentorId);
        $st->execute();
        $mentor = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$mentor || empty($mentor['email']) || !filter_var($mentor['email'], FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $programme = defined('SITE_NAME') ? SITE_NAME : 'Hive Colab';
        $subject   = 'Action needed: reconnect Google Calendar';
        $safeName  = ms_h((string)$mentor['full_name']);
        $safeTitle = ms_h($sessionTitle);

        $content = "
            <h3>Reconnect Google Calendar</h3>
            <p>Hello {$safeName},</p>
            <p>Your session \"{$safeTitle}\" was confirmed, but we couldn't add it to your Google Calendar
            because your calendar connection needs to be renewed.</p>
            <p>Please log in and reconnect Google Calendar from your mentor dashboard so this
            session (and future ones) sync automatically.</p>
            <p>Thank you,<br>" . ms_h($programme) . "</p>
        ";

        $body = function_exists('email_wrapper') ? email_wrapper($content) : $content;

        $altBody = "Hello {$mentor['full_name']},\n\n"
            . "Your session \"{$sessionTitle}\" was confirmed, but we couldn't add it to your Google Calendar "
            . "because your calendar connection needs to be renewed.\n\n"
            . "Please log in and reconnect Google Calendar from your mentor dashboard so this session "
            . "(and future ones) sync automatically.\n\n"
            . "Thank you,\n{$programme}";

        sendEmail($mentor['email'], $subject, $body, $altBody);
    }
}

if (!function_exists('gcal_notify_ventures_sync_failed')) {
    /**
     * Let the venture(s) on a confirmed session know the calendar invite
     * couldn't be generated automatically, so they don't just assume
     * it's on its way and miss the session.
     */
    function gcal_notify_ventures_sync_failed(
        mysqli $conn,
        int $sessionId,
        string $sessionTitle,
        ?string $scheduledAt,
        ?string $meetingLink
    ): void {
        if (!function_exists('sendEmail') || !function_exists('ms_get_session_ventures')) {
            return;
        }

        $ventures = ms_get_session_ventures($conn, $sessionId);
        if (!$ventures) {
            return;
        }

        $programme = defined('SITE_NAME') ? SITE_NAME : 'Hive Colab';
        $subject   = 'Your mentoring session is confirmed: ' . $sessionTitle;
        $safeTitle = ms_h($sessionTitle);
        $dt        = $scheduledAt
            ? date('l, F j, Y \a\t g:i A', strtotime($scheduledAt))
            : 'To be confirmed';
        $safeDate  = ms_h($dt);
        $safeLink  = ms_h((string)$meetingLink);

        foreach ($ventures as $v) {
            if (empty($v['founder_email']) || !filter_var($v['founder_email'], FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $safeName = ms_h((string)($v['name'] ?? 'there'));

            $content = "
                <h3>Session confirmed</h3>
                <p>Hello {$safeName},</p>
                <p>Your mentoring session \"{$safeTitle}\" has been confirmed.</p>
                <div class='info-box'>
                    <p><strong>Date:</strong> {$safeDate}</p>
            ";

            if (!empty($meetingLink)) {
                $content .= "<p><strong>Meeting Link:</strong> <a href='{$safeLink}' target='_blank'>Join Session</a></p>";
            }

            $content .= "
                </div>
                <p>Note: we weren't able to generate an automatic calendar invite for this session,
                so please make a note of the date and time above.</p>
                <p>Thank you,<br>" . ms_h($programme) . "</p>
            ";

            $body = function_exists('email_wrapper') ? email_wrapper($content) : $content;

            $altBody = "Hello {$v['name']},\n\n"
                . "Your mentoring session \"{$sessionTitle}\" has been confirmed.\n\n"
                . "Date: {$dt}\n"
                . (!empty($meetingLink) ? "Meeting Link: {$meetingLink}\n" : '')
                . "\nNote: we weren't able to generate an automatic calendar invite for this session, "
                . "so please make a note of the date and time above.\n\n"
                . "Thank you,\n{$programme}";

            sendEmail($v['founder_email'], $subject, $body, $altBody);
        }
    }
}
