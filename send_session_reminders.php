<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';          
require_once __DIR__ . '/includes/mail-function.php';   

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection error.\n");
    exit(1);
}

$conn->set_charset('utf8mb4');

function get_due_sessions(mysqli $conn, int $window_minutes, string $sent_column): array
{
    $allowed_columns = ['reminder_60_sent_at', 'reminder_15_sent_at'];
    if (!in_array($sent_column, $allowed_columns, true)) {
        throw new InvalidArgumentException('Invalid sent column');
    }

    $sql = "
        SELECT ms.id, ms.title, ms.scheduled_at, ms.duration_minutes,
               ms.meeting_link, ms.meeting_platform, ms.mentor_id
        FROM mentor_sessions ms
        WHERE ms.status = 'confirmed'
          AND ms.scheduled_at IS NOT NULL
          AND ms.$sent_column IS NULL
          AND ms.scheduled_at > NOW()
          AND ms.scheduled_at <= (NOW() + INTERVAL ? MINUTE)
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $window_minutes);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}


function get_session_recipients(mysqli $conn, int $session_id, ?int $fallback_mentor_id): array
{
    $recipients = ['ventures' => [], 'mentor' => null];


    $stmt = $conn->prepare("
        SELECT v.id, v.name, v.email
        FROM session_ventures sv
        JOIN ventures v ON v.id = sv.venture_id
        WHERE sv.session_id = ?
    ");
    $stmt->bind_param('i', $session_id);
    $stmt->execute();
    $recipients['ventures'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();


    $stmt = $conn->prepare("
        SELECT m.id, m.full_name, m.email
        FROM mentors m
        LEFT JOIN session_mentors smo
               ON smo.session_id = ?
              AND smo.role = 'organizer'
        WHERE m.id = COALESCE(smo.mentor_id, ?)
        LIMIT 1
    ");
    $stmt->bind_param('ii', $session_id, $fallback_mentor_id);
    $stmt->execute();
    $recipients['mentor'] = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $recipients;
}

function send_reminder_email(string $to, string $subject, string $html_content): bool
{
    if ($to === '') {
        return false;
    }

    $body = email_wrapper($html_content);
    $result = sendEmail($to, $subject, $body);

    if ($result !== true) {
        error_log("send_session_reminders: failed to email $to — " . (is_string($result) ? $result : 'unknown error'));
        return false;
    }

    return true;
}

function build_reminder_html(string $recipient_name, string $title, DateTime $dt, int $minutes_before, ?string $meeting_link, ?string $meeting_platform): string
{
    $safe_title    = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safe_name     = htmlspecialchars($recipient_name, ENT_QUOTES, 'UTF-8');
    $safe_platform = $meeting_platform ? htmlspecialchars(ucfirst(str_replace('_', ' ', $meeting_platform)), ENT_QUOTES, 'UTF-8') : null;
    $safe_link     = $meeting_link ? htmlspecialchars($meeting_link, ENT_QUOTES, 'UTF-8') : null;

    $html  = "<h3>Upcoming session reminder</h3>";
    $html .= "<p>Hi" . ($safe_name !== '' ? " $safe_name" : '') . ",</p>";
    $html .= "<p>This is a reminder that your session <strong>\"$safe_title\"</strong> starts in <strong>$minutes_before minutes</strong>.</p>";

    $html .= "<div class='info-box'>";
    $html .= "<div><strong>When:</strong> " . htmlspecialchars($dt->format('l, F j, Y g:i A'), ENT_QUOTES, 'UTF-8') . "</div>";
    if ($safe_platform) {
        $html .= "<div><strong>Platform:</strong> $safe_platform</div>";
    }
    $html .= "</div>";

    if ($safe_link) {
        $html .= "<p><a href='$safe_link' class='btn'>Join Session</a></p>";
    }

    $html .= "<p>See you soon.</p>";

    return $html;
}

function mark_reminder_sent(mysqli $conn, int $session_id, string $sent_column): void
{
    $allowed_columns = ['reminder_60_sent_at', 'reminder_15_sent_at'];
    if (!in_array($sent_column, $allowed_columns, true)) {
        throw new InvalidArgumentException('Invalid sent column');
    }

    $stmt = $conn->prepare("UPDATE mentor_sessions SET $sent_column = NOW() WHERE id = ?");
    $stmt->bind_param('i', $session_id);
    $stmt->execute();
    $stmt->close();
}

function process_window(mysqli $conn, int $window_minutes, string $sent_column): void
{
    $sessions = get_due_sessions($conn, $window_minutes, $sent_column);

    foreach ($sessions as $s) {
        $session_id = (int)$s['id'];
        $title      = (string)($s['title'] ?? 'Mentoring Session');
        $dt         = new DateTime((string)$s['scheduled_at']);

        $recipients = get_session_recipients($conn, $session_id, (int)($s['mentor_id'] ?? 0) ?: null);

        $subject = "Reminder: \"$title\" starts in $window_minutes minutes";
        $sent_any = false;

        // Venture recipient(s)
        foreach ($recipients['ventures'] as $venture) {
            if (!empty($venture['email'])) {
                $html = build_reminder_html(
                    (string)($venture['name'] ?? ''),
                    $title,
                    $dt,
                    $window_minutes,
                    $s['meeting_link'] ?? null,
                    $s['meeting_platform'] ?? null
                );

                if (send_reminder_email($venture['email'], $subject, $html)) {
                    $sent_any = true;
                }
            }
        }

        // Mentor recipient
        $mentor = $recipients['mentor'];
        if ($mentor && !empty($mentor['email'])) {
            $html = build_reminder_html(
                (string)($mentor['full_name'] ?? ''),
                $title,
                $dt,
                $window_minutes,
                $s['meeting_link'] ?? null,
                $s['meeting_platform'] ?? null
            );

            if (send_reminder_email($mentor['email'], $subject, $html)) {
                $sent_any = true;
            }
        }


        mark_reminder_sent($conn, $session_id, $sent_column);

        echo "[$window_minutes-min] Session $session_id (\"$title\"): reminder processed" . ($sent_any ? "" : " (no valid recipient emails)") . "\n";
    }
}


process_window($conn, 60, 'reminder_60_sent_at');
process_window($conn, 15, 'reminder_15_sent_at');