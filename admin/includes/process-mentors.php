<?php
require_once '../../includes/config.php';
require_once 'auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('DB error');
}

$conn->set_charset('utf8mb4');

function redir_m(string $page = 'mentors', string $qs = ''): void
{
    header('Location: ../' . $page . '.php' . ($qs ? '?' . $qs : ''));
    exit;
}

function mentor_flash(string $key, string $msg, string $type = 'success'): void
{
    if (function_exists('flash')) {
        flash($key, $msg, $type);
    } else {
        $_SESSION[$key . '_flash'] = [
            'msg'  => $msg,
            'type' => $type
        ];
    }
}

function ensure_message_drafts_table(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS mentor_message_drafts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            owner_type VARCHAR(20) NOT NULL DEFAULT 'admin',
            owner_id INT UNSIGNED NOT NULL DEFAULT 0,
            subject VARCHAR(255) NOT NULL DEFAULT '',
            body TEXT NULL,
            recipient_mentor_ids VARCHAR(1000) NOT NULL DEFAULT '',
            recipient_venture_ids VARCHAR(1000) NOT NULL DEFAULT '',
            send_to_admin TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_mmd_owner (owner_type, owner_id, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function ensure_message_thread_hidden_table(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS mentor_message_thread_hidden (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            owner_type VARCHAR(20) NOT NULL DEFAULT 'admin',
            owner_id INT UNSIGNED NOT NULL DEFAULT 0,
            thread_id VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL,
            hidden_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_mmth_owner_thread (owner_type, owner_id, thread_id),
            KEY idx_mmth_owner (owner_type, owner_id, hidden_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redir_m();
}

$action = $_POST['action'] ?? '';

/* --------------------------------------------------------------
   SAVE MENTOR
-------------------------------------------------------------- */
if ($action === 'save') {
    $id           = (int)($_POST['id'] ?? 0);
    $full_name    = trim($_POST['full_name'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $job_title    = trim($_POST['job_title'] ?? '');
    $organisation = trim($_POST['organisation'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $location     = trim($_POST['location'] ?? '');
    $bio          = trim($_POST['bio'] ?? '');
    $industries   = trim($_POST['industries'] ?? '');
    $linkedin_url = trim($_POST['linkedin_url'] ?? '');
    $website      = trim($_POST['website'] ?? '');
    $admin_notes  = trim($_POST['admin_notes'] ?? '');
    $weekly_hours = max(0, min(40, (int)($_POST['weekly_hours'] ?? 4)));
    $is_featured  = isset($_POST['is_featured']) ? 1 : 0;
    $password_raw = trim($_POST['password'] ?? '');

    $allowed_statuses = ['active', 'pending', 'inactive'];
    $status = in_array($_POST['status'] ?? '', $allowed_statuses, true)
        ? $_POST['status']
        : 'pending';

    if ($full_name === '') {
        mentor_flash('mentors', 'Name is required.', 'error');
        redir_m();
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        mentor_flash('mentors', 'Valid email required.', 'error');
        redir_m();
    }

    if ($linkedin_url !== '' && !filter_var($linkedin_url, FILTER_VALIDATE_URL)) {
        mentor_flash('mentors', 'Invalid LinkedIn URL.', 'error');
        redir_m();
    }

    if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) {
        mentor_flash('mentors', 'Invalid website URL.', 'error');
        redir_m();
    }

    if ($password_raw !== '' && strlen($password_raw) < 8) {
        mentor_flash('mentors', 'Password must be at least 8 characters.', 'error');
        redir_m();
    }

    $allowed_exp = [
        'fundraising', 'product', 'marketing', 'tech', 'ops',
        'legal', 'finance', 'sales', 'strategy', 'impact',
        'design', 'hr', 'growth'
    ];

    $exp_arr = array_filter(array_map('trim', (array)($_POST['expertise'] ?? [])));
    $expertise = implode(',', array_values(array_intersect($exp_arr, $allowed_exp)));

    $photo = trim($_POST['existing_photo'] ?? '');
    $upload_err = '';

    if (function_exists('upload_image') && !empty($_FILES['photo']['name'])) {
        $new = upload_image('photo', 'mentors', $upload_err);

        if ($new) {
            if ($photo && function_exists('delete_image')) {
                delete_image($photo);
            }

            $photo = $new;
        }
    }

    if ($id > 0) {
        $sql = "
            UPDATE mentors SET
                full_name = ?,
                email = ?,
                job_title = ?,
                organisation = ?,
                phone = ?,
                location = ?,
                bio = ?,
                expertise = ?,
                industries = ?,
                linkedin_url = ?,
                website = ?,
                photo = ?,
                status = ?,
                weekly_hours = ?,
                is_featured = ?,
                admin_notes = ?
            WHERE id = ?
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            mentor_flash('mentors', 'DB error: ' . $conn->error, 'error');
            redir_m();
        }

        $stmt->bind_param(
            'sssssssssssssiiii',
            $full_name,
            $email,
            $job_title,
            $organisation,
            $phone,
            $location,
            $bio,
            $expertise,
            $industries,
            $linkedin_url,
            $website,
            $photo,
            $status,
            $weekly_hours,
            $is_featured,
            $admin_notes,
            $id
        );

        $ok = $stmt->execute();
        $err = $stmt->error;
        $stmt->close();

        if ($ok && $password_raw !== '') {
            $hash = password_hash($password_raw, PASSWORD_DEFAULT);

            $pw_stmt = $conn->prepare("UPDATE mentors SET password_hash = ? WHERE id = ?");
            if ($pw_stmt) {
                $pw_stmt->bind_param('si', $hash, $id);
                $pw_stmt->execute();
                $pw_stmt->close();
            }
        }

        mentor_flash('mentors', $ok ? 'Mentor updated.' : 'Failed: ' . $err, $ok ? 'success' : 'error');
        redir_m();
    }

    $chk = $conn->prepare("SELECT id FROM mentors WHERE email = ? LIMIT 1");

    if (!$chk) {
        mentor_flash('mentors', 'DB error: ' . $conn->error, 'error');
        redir_m();
    }

    $chk->bind_param('s', $email);
    $chk->execute();
    $exists = $chk->get_result()->num_rows > 0;
    $chk->close();

    if ($exists) {
        mentor_flash('mentors', 'Email already exists.', 'error');
        redir_m();
    }

    if ($password_raw !== '') {
        $hash = password_hash($password_raw, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("
            INSERT INTO mentors (
                full_name, email, job_title, organisation, phone, location,
                bio, expertise, industries, linkedin_url, website, photo,
                status, weekly_hours, is_featured, admin_notes, password_hash
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            mentor_flash('mentors', 'DB error: ' . $conn->error, 'error');
            redir_m();
        }

        $stmt->bind_param(
            'sssssssssssssiiis',
            $full_name,
            $email,
            $job_title,
            $organisation,
            $phone,
            $location,
            $bio,
            $expertise,
            $industries,
            $linkedin_url,
            $website,
            $photo,
            $status,
            $weekly_hours,
            $is_featured,
            $admin_notes,
            $hash
        );
    } else {
        $stmt = $conn->prepare("
            INSERT INTO mentors (
                full_name, email, job_title, organisation, phone, location,
                bio, expertise, industries, linkedin_url, website, photo,
                status, weekly_hours, is_featured, admin_notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            mentor_flash('mentors', 'DB error: ' . $conn->error, 'error');
            redir_m();
        }

        $stmt->bind_param(
            'sssssssssssssiii',
            $full_name,
            $email,
            $job_title,
            $organisation,
            $phone,
            $location,
            $bio,
            $expertise,
            $industries,
            $linkedin_url,
            $website,
            $photo,
            $status,
            $weekly_hours,
            $is_featured,
            $admin_notes
        );
    }

    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    mentor_flash('mentors', $ok ? 'Mentor created.' : 'Failed: ' . $err, $ok ? 'success' : 'error');
    redir_m();
}

/* --------------------------------------------------------------
   DELETE MENTOR
-------------------------------------------------------------- */
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        mentor_flash('mentors', 'Invalid mentor.', 'error');
        redir_m();
    }

    $photo = '';

    $stmt = $conn->prepare("SELECT photo FROM mentors WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $photo = $row['photo'] ?? '';
        $stmt->close();
    }

    if ($photo && function_exists('delete_image')) {
        delete_image($photo);
    }

    $stmt = $conn->prepare("DELETE FROM mentors WHERE id = ? LIMIT 1");

    if (!$stmt) {
        mentor_flash('mentors', 'DB error: ' . $conn->error, 'error');
        redir_m();
    }

    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    mentor_flash('mentors', $ok ? 'Mentor deleted.' : 'Failed: ' . $err, $ok ? 'success' : 'error');
    redir_m();
}


if ($action === 'assign') {
    $mentor_id  = (int)($_POST['mentor_id'] ?? 0);
    $cohort_id  = (int)($_POST['cohort_id'] ?? 0);
    $venture_id = (int)($_POST['venture_id'] ?? 0);
    $role       = trim($_POST['role'] ?? '');
    $notes      = trim($_POST['notes'] ?? '');

    if ($mentor_id <= 0 || $cohort_id <= 0) {
        mentor_flash('mentors', 'Mentor and cohort required.', 'error');
        redir_m();
    }

    $venture_id_db = $venture_id > 0 ? $venture_id : null;

    $stmt = $conn->prepare("
        INSERT INTO mentor_assignments 
            (mentor_id, cohort_id, venture_id, role, notes)
        VALUES (?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        mentor_flash('mentors', 'DB error: ' . $conn->error, 'error');
        redir_m();
    }

    $stmt->bind_param('iiiss', $mentor_id, $cohort_id, $venture_id_db, $role, $notes);
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    mentor_flash('mentors', $ok ? 'Mentor assigned.' : 'Failed: ' . $err, $ok ? 'success' : 'error');
    redir_m();
}


if ($action === 'send_bulk_message' || $action === 'send_message') {
    $subject = trim((string)($_POST['subject'] ?? ''));
    $body    = trim((string)($_POST['body'] ?? ''));
    $draft_id = (int)($_POST['draft_id'] ?? 0);

    if ($subject === '' || $body === '') {
        mentor_flash('mmsg', 'Subject and message are required.', 'error');
        redir_m('messages');
    }

    if (mb_strlen($subject) > 180) {
        mentor_flash('mmsg', 'The subject must not exceed 180 characters.', 'error');
        redir_m('messages');
    }

    if (mb_strlen($body) > 10000) {
        mentor_flash('mmsg', 'The message is too long.', 'error');
        redir_m('messages');
    }

    $mentor_ids = array_values(array_unique(array_filter(
        array_map('intval', (array)($_POST['recipient_mentor_ids'] ?? [])),
        static fn(int $id): bool => $id > 0
    )));

    $venture_ids = array_values(array_unique(array_filter(
        array_map('intval', (array)($_POST['recipient_venture_ids'] ?? [])),
        static fn(int $id): bool => $id > 0
    )));

   
    $legacy_mentor_id = (int)($_POST['recipient_mentor_id'] ?? 0);
    $legacy_venture_id = (int)($_POST['recipient_venture_id'] ?? 0);

    if ($legacy_mentor_id > 0) {
        $mentor_ids[] = $legacy_mentor_id;
    }

    if ($legacy_venture_id > 0) {
        $venture_ids[] = $legacy_venture_id;
    }

    $mentor_ids  = array_values(array_unique($mentor_ids));
    $venture_ids = array_values(array_unique($venture_ids));
    $send_to_admin = isset($_POST['send_to_admin']) && (string)$_POST['send_to_admin'] === '1';

    $reply_as = strtolower(trim((string)($_POST['reply_as'] ?? 'admin')));
    $sender_type = 'admin';
    $sender_id = (int)($ADMIN['id'] ?? ($_SESSION['admin_id'] ?? 0));

    if ($reply_as === 'mentor') {
        $requested_mentor_id = (int)($_POST['reply_as_mentor_id'] ?? 0);
        $logged_mentor_id = function_exists('ms_mentor_record_id')
            ? ms_mentor_record_id($conn)
            : 0;

        if ($requested_mentor_id <= 0 || $requested_mentor_id !== $logged_mentor_id) {
            mentor_flash('mmsg', 'Invalid mentor sender account.', 'error');
            redir_m('messages');
        }

        $sender_type = 'mentor';
        $sender_id = $requested_mentor_id;

        if (!empty($venture_ids)) {
            $allowed_ventures = [];

            $scope_sql = "
                SELECT DISTINCT v.id
                FROM ventures v
                WHERE v.id IN (
                    SELECT sv.venture_id
                    FROM session_ventures sv
                    JOIN session_mentors sm ON sm.session_id = sv.session_id
                    WHERE sm.mentor_id = ?
                )
                OR v.id IN (
                    SELECT mm_v.sender_id
                    FROM mentor_messages mm_v
                    WHERE mm_v.sender_type = 'venture'
                      AND mm_v.thread_id IN (
                          SELECT thread_id
                          FROM mentor_messages
                          WHERE (sender_type = 'mentor' AND sender_id = ?)
                             OR (recipient_type = 'mentor' AND recipient_id = ?)
                      )
                )
                OR v.id IN (
                    SELECT mm_v.recipient_id
                    FROM mentor_messages mm_v
                    WHERE mm_v.recipient_type = 'venture'
                      AND mm_v.thread_id IN (
                          SELECT thread_id
                          FROM mentor_messages
                          WHERE (sender_type = 'mentor' AND sender_id = ?)
                             OR (recipient_type = 'mentor' AND recipient_id = ?)
                      )
                )
            ";

            $scope_stmt = $conn->prepare($scope_sql);

            if ($scope_stmt) {
                $scope_stmt->bind_param(
                    'iiiii',
                    $sender_id,
                    $sender_id,
                    $sender_id,
                    $sender_id,
                    $sender_id
                );
                $scope_stmt->execute();
                $scope_result = $scope_stmt->get_result();

                while ($scope_row = $scope_result->fetch_assoc()) {
                    $allowed_ventures[] = (int)$scope_row['id'];
                }

                $scope_stmt->close();
            }

            $venture_ids = array_values(array_intersect($venture_ids, $allowed_ventures));
        }

        $mentor_ids = [];
    }

    $recipients = [];

    foreach ($mentor_ids as $mentor_id) {
        $recipients[] = ['mentor', $mentor_id];
    }

    foreach ($venture_ids as $venture_id) {
        $recipients[] = ['venture', $venture_id];
    }

    if ($send_to_admin && $sender_type === 'mentor') {
        $recipients[] = ['admin', 0];
    }

    if (empty($recipients)) {
        mentor_flash('mmsg', 'Select at least one valid recipient.', 'error');
        redir_m('messages');
    }

    $insert = $conn->prepare("
        INSERT INTO mentor_messages (
            thread_id,
            sender_type,
            sender_id,
            recipient_type,
            recipient_id,
            subject,
            body,
            is_read
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 0)
    ");

    if (!$insert) {
        mentor_flash('mmsg', 'DB error: ' . $conn->error, 'error');
        redir_m('messages');
    }

    $conn->begin_transaction();

    $sent_count = 0;
    $first_thread_id = '';
    $error_message = '';

    try {
        foreach ($recipients as [$recipient_type, $recipient_id]) {
            $thread_id = bin2hex(random_bytes(16));

            $insert->bind_param(
                'ssisiss',
                $thread_id,
                $sender_type,
                $sender_id,
                $recipient_type,
                $recipient_id,
                $subject,
                $body
            );

            if (!$insert->execute()) {
                throw new RuntimeException($insert->error ?: 'Could not insert message.');
            }

            if ($first_thread_id === '') {
                $first_thread_id = $thread_id;
            }

            $sent_count++;
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        $error_message = $e->getMessage();
        $sent_count = 0;
    }

    $insert->close();

    if ($sent_count > 0) {
        if ($draft_id > 0) {
            ensure_message_drafts_table($conn);

            $dstmt = $conn->prepare("DELETE FROM mentor_message_drafts WHERE id = ? LIMIT 1");
            if ($dstmt) {
                $dstmt->bind_param('i', $draft_id);
                $dstmt->execute();
                $dstmt->close();
            }
        }

        mentor_flash(
            'mmsg',
            'Message sent successfully to ' . $sent_count . ' recipient' . ($sent_count === 1 ? '' : 's') . '.',
            'success'
        );

        redir_m(
            'messages',
            $first_thread_id !== '' ? 'thread=' . urlencode($first_thread_id) : ''
        );
    }

    mentor_flash('mmsg', 'Message could not be sent: ' . $error_message, 'error');
    redir_m('messages');
}

/* --------------------------------------------------------------
   SAVE DRAFT
-------------------------------------------------------------- */
if ($action === 'save_draft') {
    ensure_message_drafts_table($conn);

    $draft_id = (int)($_POST['draft_id'] ?? 0);
    $subject  = trim((string)($_POST['subject'] ?? ''));
    $body     = trim((string)($_POST['body'] ?? ''));

    if (mb_strlen($subject) > 180) {
        mentor_flash('mmsg', 'The subject must not exceed 180 characters.', 'error');
        redir_m('messages', 'folder=drafts');
    }

    // The draft belongs to the acting sender (a mentor or the admin).
    $reply_as = strtolower(trim((string)($_POST['reply_as'] ?? 'admin')));

    if ($reply_as === 'mentor') {
        $logged_mentor_id   = function_exists('ms_mentor_record_id') ? ms_mentor_record_id($conn) : 0;
        $requested_mentor_id = (int)($_POST['reply_as_mentor_id'] ?? 0);

        if ($requested_mentor_id <= 0 || $requested_mentor_id !== $logged_mentor_id) {
            mentor_flash('mmsg', 'Invalid mentor sender account.', 'error');
            redir_m('messages', 'folder=drafts');
        }

        $owner_type = 'mentor';
        $owner_id   = $requested_mentor_id;
    } else {
        $owner_type = 'admin';
        $owner_id   = (int)($ADMIN['id'] ?? ($_SESSION['admin_id'] ?? 0));
    }

    $mentor_ids = implode(',', array_values(array_unique(array_filter(
        array_map('intval', (array)($_POST['recipient_mentor_ids'] ?? [])),
        static fn(int $id): bool => $id > 0
    ))));

    $venture_ids = implode(',', array_values(array_unique(array_filter(
        array_map('intval', (array)($_POST['recipient_venture_ids'] ?? [])),
        static fn(int $id): bool => $id > 0
    ))));

    $send_to_admin = isset($_POST['send_to_admin']) && (string)$_POST['send_to_admin'] === '1' ? 1 : 0;

    if ($draft_id > 0) {
        $stmt = $conn->prepare("
            UPDATE mentor_message_drafts
            SET subject = ?, body = ?, recipient_mentor_ids = ?, recipient_venture_ids = ?, send_to_admin = ?
            WHERE id = ? AND owner_type = ? AND owner_id = ?
        ");

        if (!$stmt) {
            mentor_flash('mmsg', 'DB error: ' . $conn->error, 'error');
            redir_m('messages', 'folder=drafts');
        }

        $stmt->bind_param('ssssiisi', $subject, $body, $mentor_ids, $venture_ids, $send_to_admin, $draft_id, $owner_type, $owner_id);
        $ok = $stmt->execute();
        $err = $stmt->error;
        $stmt->close();
    } else {
        $stmt = $conn->prepare("
            INSERT INTO mentor_message_drafts
                (owner_type, owner_id, subject, body, recipient_mentor_ids, recipient_venture_ids, send_to_admin)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            mentor_flash('mmsg', 'DB error: ' . $conn->error, 'error');
            redir_m('messages', 'folder=drafts');
        }

        $stmt->bind_param('sissssi', $owner_type, $owner_id, $subject, $body, $mentor_ids, $venture_ids, $send_to_admin);
        $ok = $stmt->execute();
        $err = $stmt->error;
        $stmt->close();
    }

    mentor_flash('mmsg', $ok ? 'Draft saved.' : 'Failed: ' . $err, $ok ? 'success' : 'error');
    redir_m('messages', 'folder=drafts');
}

/* --------------------------------------------------------------
   DELETE DRAFT
-------------------------------------------------------------- */
if ($action === 'delete_draft') {
    ensure_message_drafts_table($conn);

    $draft_id = (int)($_POST['draft_id'] ?? 0);

    if ($draft_id <= 0) {
        mentor_flash('mmsg', 'Invalid draft.', 'error');
        redir_m('messages', 'folder=drafts');
    }

    $reply_as = strtolower(trim((string)($_POST['reply_as'] ?? 'admin')));

    if ($reply_as === 'mentor') {
        $owner_type = 'mentor';
        $owner_id   = function_exists('ms_mentor_record_id') ? ms_mentor_record_id($conn) : 0;
    } else {
        $owner_type = 'admin';
        $owner_id   = (int)($ADMIN['id'] ?? ($_SESSION['admin_id'] ?? 0));
    }

    $stmt = $conn->prepare("DELETE FROM mentor_message_drafts WHERE id = ? AND owner_type = ? AND owner_id = ? LIMIT 1");

    if (!$stmt) {
        mentor_flash('mmsg', 'DB error: ' . $conn->error, 'error');
        redir_m('messages', 'folder=drafts');
    }

    $stmt->bind_param('isi', $draft_id, $owner_type, $owner_id);
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    mentor_flash('mmsg', $ok ? 'Draft deleted.' : 'Failed: ' . $err, $ok ? 'success' : 'error');
    redir_m('messages', 'folder=drafts');
}

/* --------------------------------------------------------------
   DELETE THREAD (owner-scoped — hides it only for the current user)
-------------------------------------------------------------- */
if ($action === 'delete_thread') {
    ensure_message_thread_hidden_table($conn);

    $thread_id = trim((string)($_POST['thread_id'] ?? ''));

    $folder = strtolower(trim((string)($_POST['folder'] ?? 'inbox')));
    if (!in_array($folder, ['inbox', 'outbox'], true)) {
        $folder = 'inbox';
    }

    if ($thread_id === '') {
        mentor_flash('mmsg', 'Invalid conversation.', 'error');
        redir_m('messages', 'folder=' . $folder);
    }

    $reply_as = strtolower(trim((string)($_POST['reply_as'] ?? 'admin')));

    if ($reply_as === 'mentor') {
        $logged_mentor_id    = function_exists('ms_mentor_record_id') ? ms_mentor_record_id($conn) : 0;
        $requested_mentor_id = (int)($_POST['reply_as_mentor_id'] ?? 0);

        if ($requested_mentor_id <= 0 || $requested_mentor_id !== $logged_mentor_id) {
            mentor_flash('mmsg', 'Invalid mentor sender account.', 'error');
            redir_m('messages', 'folder=' . $folder);
        }

        $own_stmt = $conn->prepare("
            SELECT 1 FROM mentor_messages
            WHERE thread_id = ?
              AND ((sender_type = 'mentor' AND sender_id = ?) OR (recipient_type = 'mentor' AND recipient_id = ?))
            LIMIT 1
        ");

        if ($own_stmt) {
            $own_stmt->bind_param('sii', $thread_id, $requested_mentor_id, $requested_mentor_id);
            $own_stmt->execute();
            $owns = $own_stmt->get_result()->num_rows > 0;
            $own_stmt->close();
        } else {
            $owns = false;
        }

        if (!$owns) {
            mentor_flash('mmsg', 'Conversation not found.', 'error');
            redir_m('messages', 'folder=' . $folder);
        }

        $owner_type = 'mentor';
        $owner_id   = $requested_mentor_id;
    } else {
        $owner_type = 'admin';
        $owner_id   = (int)($ADMIN['id'] ?? ($_SESSION['admin_id'] ?? 0));
    }

    $stmt = $conn->prepare("
        INSERT IGNORE INTO mentor_message_thread_hidden (owner_type, owner_id, thread_id)
        VALUES (?, ?, ?)
    ");

    if (!$stmt) {
        mentor_flash('mmsg', 'DB error: ' . $conn->error, 'error');
        redir_m('messages', 'folder=' . $folder);
    }

    $stmt->bind_param('sis', $owner_type, $owner_id, $thread_id);
    $ok  = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    mentor_flash('mmsg', $ok ? 'Conversation deleted.' : 'Failed: ' . $err, $ok ? 'success' : 'error');
    redir_m('messages', 'folder=' . $folder);
}

/* --------------------------------------------------------------
   REPLY MESSAGE
-------------------------------------------------------------- */
if ($action === 'reply_message') {
    $thread_id = trim($_POST['thread_id'] ?? '');
    $body      = trim($_POST['body'] ?? '');

    if ($thread_id === '' || $body === '') {
        mentor_flash('mmsg', 'Message body required.', 'error');
        redir_m('messages', 'thread=' . urlencode($thread_id));
    }

    $stmt = $conn->prepare("
        SELECT sender_type, sender_id, recipient_type, recipient_id
        FROM mentor_messages
        WHERE thread_id = ?
        ORDER BY id ASC
        LIMIT 1
    ");

    if (!$stmt) {
        mentor_flash('mmsg', 'DB error: ' . $conn->error, 'error');
        redir_m('messages');
    }

    $stmt->bind_param('s', $thread_id);
    $stmt->execute();
    $orig = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$orig) {
        mentor_flash('mmsg', 'Thread not found.', 'error');
        redir_m('messages');
    }

    if ($orig['sender_type'] === 'admin') {
        $recipient_type = $orig['recipient_type'];
        $recipient_id   = (int)$orig['recipient_id'];
    } else {
        $recipient_type = $orig['sender_type'];
        $recipient_id   = (int)$orig['sender_id'];
    }

    $reply_as = strtolower(trim((string)($_POST['reply_as'] ?? 'admin')));
    $sender_type = 'admin';
    $sender_id   = (int)($ADMIN['id'] ?? ($_SESSION['admin_id'] ?? 0));

    if ($reply_as === 'mentor') {
        $requested_mentor_id = (int)($_POST['reply_as_mentor_id'] ?? 0);
        $logged_mentor_id = function_exists('ms_mentor_record_id')
            ? ms_mentor_record_id($conn)
            : 0;

        if ($requested_mentor_id <= 0 || $requested_mentor_id !== $logged_mentor_id) {
            mentor_flash('mmsg', 'Invalid mentor sender account.', 'error');
            redir_m('messages', 'thread=' . urlencode($thread_id));
        }

        $sender_type = 'mentor';
        $sender_id = $requested_mentor_id;
    }

    $stmt = $conn->prepare("
        INSERT INTO mentor_messages (
            thread_id,
            sender_type,
            sender_id,
            recipient_type,
            recipient_id,
            body
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        mentor_flash('mmsg', 'DB error: ' . $conn->error, 'error');
        redir_m('messages');
    }

    $stmt->bind_param(
        'ssisis',
        $thread_id,
        $sender_type,
        $sender_id,
        $recipient_type,
        $recipient_id,
        $body
    );

    $ok  = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    mentor_flash(
        'mmsg',
        $ok ? 'Reply sent.' : 'Failed: ' . $err,
        $ok ? 'success' : 'error'
    );

    redir_m('messages', 'thread=' . urlencode($thread_id));
}

mentor_flash('mentors', 'Invalid action.', 'error');
redir_m();