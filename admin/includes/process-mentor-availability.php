<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/session-junctions.php';
require_once __DIR__ . '/../../includes/google-calendar-config.php';
require_once __DIR__ . '/../../includes/google-calendar-service.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('DB error');
}
$conn->set_charset('utf8mb4');

$IS_MENTOR    = ms_is_mentor();
$CAN_MANAGE   = ms_can_manage();
$MY_MENTOR_ID = ms_mentor_record_id($conn);

function av_redirect(string $qs = ''): void
{
    header('Location: ../mentor-calendar.php' . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

function av_flash(string $msg, string $type = 'success'): void
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

function av_valid_time(string $time): bool
{
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        return false;
    }

    [$hour, $minute] = array_map('intval', explode(':', $time));

    return $hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    av_redirect();
}

$action    = trim((string)($_POST['action'] ?? ''));
$mentor_id = (int)($_POST['mentor_id'] ?? 0);
$date      = trim((string)($_POST['date'] ?? ''));

if ($action !== 'save' || $mentor_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    av_flash('Invalid availability request.', 'error');
    av_redirect();
}

$dateCheck = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if (!$dateCheck || $dateCheck->format('Y-m-d') !== $date) {
    av_flash('Invalid availability date.', 'error');
    av_redirect();
}

if (!$IS_MENTOR && !$CAN_MANAGE) {
    av_flash('Access denied.', 'error');
    av_redirect();
}

if ($IS_MENTOR && !$CAN_MANAGE) {
    if ($MY_MENTOR_ID <= 0) {
        av_flash('Your user account is not linked to a mentor profile.', 'error');
        av_redirect();
    }

    if ($mentor_id !== $MY_MENTOR_ID) {
        av_flash('You can only manage your own availability.', 'error');
        av_redirect();
    }
}

/*
|--------------------------------------------------------------------------
| Ensure availability schema exists
|--------------------------------------------------------------------------
*/
$conn->query("
    CREATE TABLE IF NOT EXISTS mentor_availability (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        mentor_id       INT UNSIGNED NOT NULL,
        avail_date      DATE NOT NULL,
        slot_time       TIME NOT NULL,
        is_blocked      TINYINT(1) NOT NULL DEFAULT 0,
        meeting_link    VARCHAR(500) NOT NULL DEFAULT '',
        google_event_id VARCHAR(255) NULL DEFAULT NULL,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_mentor_slot (mentor_id, avail_date, slot_time),
        KEY idx_mentor_availability_date (mentor_id, avail_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$requiredColumns = [
    'meeting_link' => "ALTER TABLE mentor_availability ADD COLUMN meeting_link VARCHAR(500) NOT NULL DEFAULT '' AFTER is_blocked",
    'google_event_id' => "ALTER TABLE mentor_availability ADD COLUMN google_event_id VARCHAR(255) NULL DEFAULT NULL AFTER meeting_link",
    'updated_at' => "ALTER TABLE mentor_availability ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
];

foreach ($requiredColumns as $column => $sql) {
    $check = $conn->query("SHOW COLUMNS FROM mentor_availability LIKE '" . $conn->real_escape_string($column) . "'");
    if ($check instanceof mysqli_result && $check->num_rows === 0) {
        $conn->query($sql);
    }
}

/*
|--------------------------------------------------------------------------
| Parse submitted selected slots
|--------------------------------------------------------------------------
| slots[] and links[] are parallel arrays.
| An empty slots[] collection intentionally means "clear all slots that day".
*/
$raw_slots = isset($_POST['slots']) && is_array($_POST['slots']) ? $_POST['slots'] : [];
$raw_links = isset($_POST['links']) && is_array($_POST['links']) ? $_POST['links'] : [];

$submitted = [];

foreach ($raw_slots as $i => $rawTime) {
    $time = substr(trim((string)$rawTime), 0, 5);

    if (!av_valid_time($time)) {
        continue;
    }

    $link = trim((string)($raw_links[$i] ?? ''));

    // Keep links bounded to the DB column size.
    if (mb_strlen($link) > 500) {
        $link = mb_substr($link, 0, 500);
    }

    // Associative key prevents duplicate slot rows.
    $submitted[$time] = $link;
}

/*
|--------------------------------------------------------------------------
| Load existing rows
|--------------------------------------------------------------------------
*/
$existing = [];

$st = $conn->prepare("
    SELECT id, mentor_id, avail_date, slot_time, is_blocked, meeting_link, google_event_id
    FROM mentor_availability
    WHERE mentor_id = ? AND avail_date = ?
");
if (!$st) {
    av_flash('Unable to prepare availability query: ' . $conn->error, 'error');
    av_redirect('mentor_id=' . $mentor_id . '&month=' . substr($date, 0, 7));
}

$st->bind_param('is', $mentor_id, $date);
$st->execute();
$res = $st->get_result();

while ($row = $res->fetch_assoc()) {
    $existing[substr((string)$row['slot_time'], 0, 5)] = $row;
}
$st->close();

/*
|--------------------------------------------------------------------------
| Google Calendar connection
|--------------------------------------------------------------------------
| A Google Calendar sync failure should not prevent the local availability
| selection from being saved.
*/
$gcal = null;
$gcalConnected = false;
$googleWarnings = [];

try {
    $gcal = new GoogleCalendarService($conn);
    $gcalConnected = $gcal->isConnected($mentor_id);
} catch (Throwable $e) {
    $googleWarnings[] = 'Google Calendar could not be initialized.';
}

/*
|--------------------------------------------------------------------------
| Save atomically to local database
|--------------------------------------------------------------------------
*/
try {
    $conn->begin_transaction();

    // Remove slots that are no longer selected.
    foreach ($existing as $time => $row) {
        if (array_key_exists($time, $submitted)) {
            continue;
        }

        if ($gcalConnected && $gcal && !empty($row['google_event_id'])) {
            try {
                $gcal->deleteEvent($mentor_id, (string)$row['google_event_id']);
            } catch (Throwable $e) {
                $googleWarnings[] = 'One removed slot could not be deleted from Google Calendar.';
            }
        }

        $rowId = (int)$row['id'];
        $del = $conn->prepare("DELETE FROM mentor_availability WHERE id = ? LIMIT 1");
        if (!$del) {
            throw new RuntimeException($conn->error);
        }

        $del->bind_param('i', $rowId);
        if (!$del->execute()) {
            throw new RuntimeException($del->error);
        }
        $del->close();
    }

    // Insert/update selected slots.
    foreach ($submitted as $time => $link) {
        $row = $existing[$time] ?? null;
        $eventId = $row['google_event_id'] ?? null;

        if ($gcalConnected && $gcal) {
            try {
                $eventId = $gcal->upsertAvailabilityEvent(
                    $mentor_id,
                    $eventId ? (string)$eventId : null,
                    $date,
                    $time,
                    60,
                    $link
                );
            } catch (Throwable $e) {
                $googleWarnings[] = 'A selected slot could not be synced to Google Calendar.';
                // Preserve previous event id if sync failed.
                $eventId = $row['google_event_id'] ?? null;
            }
        }

        if ($row) {
            $rowId = (int)$row['id'];

            $upd = $conn->prepare("
                UPDATE mentor_availability
                SET is_blocked = 0,
                    meeting_link = ?,
                    google_event_id = ?
                WHERE id = ?
                LIMIT 1
            ");
            if (!$upd) {
                throw new RuntimeException($conn->error);
            }

            $upd->bind_param('ssi', $link, $eventId, $rowId);

            if (!$upd->execute()) {
                throw new RuntimeException($upd->error);
            }
            $upd->close();
        } else {
            $slotTimeFull = $time . ':00';

            $ins = $conn->prepare("
                INSERT INTO mentor_availability
                    (mentor_id, avail_date, slot_time, is_blocked, meeting_link, google_event_id)
                VALUES
                    (?, ?, ?, 0, ?, ?)
            ");
            if (!$ins) {
                throw new RuntimeException($conn->error);
            }

            $ins->bind_param(
                'issss',
                $mentor_id,
                $date,
                $slotTimeFull,
                $link,
                $eventId
            );

            if (!$ins->execute()) {
                throw new RuntimeException($ins->error);
            }
            $ins->close();
        }
    }

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();

    error_log('Mentor availability save failed: ' . $e->getMessage());

    av_flash(
        'Availability could not be saved. Please try again. If the problem continues, check the server error log.',
        'error'
    );

    av_redirect('mentor_id=' . $mentor_id . '&month=' . substr($date, 0, 7));
}

/*
|--------------------------------------------------------------------------
| Success feedback
|--------------------------------------------------------------------------
*/
if ($gcalConnected && !$googleWarnings) {
    av_flash('Availability saved and synced to your Google Calendar.');
} elseif ($gcalConnected && $googleWarnings) {
    av_flash('Availability saved, but one or more Google Calendar updates could not be completed.', 'warning');
} else {
    av_flash('Availability saved successfully.');
}

av_redirect('mentor_id=' . $mentor_id . '&month=' . substr($date, 0, 7));
