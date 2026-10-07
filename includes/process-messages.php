<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection not found.');
}

$conn->set_charset('utf8mb4');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$venture_id = (int)($venture_id ?? $_SESSION['venture_id'] ?? $_SESSION['user_id'] ?? 0);

if ($venture_id <= 0) {
    http_response_code(403);
    exit('Invalid venture session.');
}

function pm_flash(
    string $message,
    string $type = 'success'
): void {
    $_SESSION['messages_flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function pm_redirect(
    string $query = ''
): never {
    $base = defined('SITE_URL')
        ? rtrim((string)SITE_URL, '/') . '/messages.php'
        : '/messages.php';

    if ($query !== '') {
        $base .= '?' . ltrim($query, '?');
    }

    header('Location: ' . $base);
    exit;
}

function pm_ensure_draft_table(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS venture_message_drafts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            venture_id INT UNSIGNED NOT NULL,
            recipient_mentor_id INT UNSIGNED NOT NULL DEFAULT 0,
            subject VARCHAR(255) NOT NULL DEFAULT '',
            body TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_vmd_venture_updated (venture_id, updated_at),
            KEY idx_vmd_recipient (recipient_mentor_id)
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
    ");
}

function pm_valid_recipient(
    mysqli $conn,
    int $ventureId,
    int $mentorId,
    int $cohortId
): bool {
    /*
     * Programme Team is represented by recipient ID 0.
     */
    if ($mentorId === 0) {
        return true;
    }

    /*
     * Ventures may message any active mentor.
     * Assignment/cohort membership is intentionally not required here.
     */
    $stmt = $conn->prepare("
        SELECT 1
        FROM mentors
        WHERE id = ?
          AND status = 'active'
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        'i',
        $mentorId
    );

    $stmt->execute();

    $ok =
        $stmt
            ->get_result()
            ->num_rows > 0;

    $stmt->close();

    return $ok;
}


pm_ensure_draft_table($conn);

$action = trim((string)($_POST['action'] ?? ''));

$cohort_id = (int)($VENTURE['cohort_id'] ?? 0);

/* --------------------------------------------------------------------------
   Save draft
   -------------------------------------------------------------------------- */

if ($action === 'save_draft') {
    $draft_id = max(0, (int)($_POST['draft_id'] ?? 0));
    $recipient_id = max(0, (int)($_POST['recipient_mentor_id'] ?? 0));
    $subject = trim((string)($_POST['subject'] ?? ''));
    $body = trim((string)($_POST['body'] ?? ''));

    if (!pm_valid_recipient(
        $conn,
        $venture_id,
        $recipient_id,
        $cohort_id
    )) {
        pm_flash(
            'The selected recipient is not available.',
            'error'
        );

        pm_redirect('folder=drafts');
    }

    if ($draft_id > 0) {
        $stmt = $conn->prepare("
            UPDATE venture_message_drafts
            SET recipient_mentor_id = ?,
                subject = ?,
                body = ?
            WHERE id = ?
              AND venture_id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            pm_flash(
                'Draft could not be saved.',
                'error'
            );

            pm_redirect('folder=drafts');
        }

        $stmt->bind_param(
            'issii',
            $recipient_id,
            $subject,
            $body,
            $draft_id,
            $venture_id
        );

        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            pm_flash(
                'Draft could not be saved.',
                'error'
            );

            pm_redirect('folder=drafts');
        }

        pm_flash('Draft saved.');
        pm_redirect(
            'folder=drafts&draft='
            . $draft_id
        );
    }

    $stmt = $conn->prepare("
        INSERT INTO venture_message_drafts
            (
                venture_id,
                recipient_mentor_id,
                subject,
                body
            )
        VALUES (?, ?, ?, ?)
    ");

    if (!$stmt) {
        pm_flash(
            'Draft could not be saved.',
            'error'
        );

        pm_redirect('folder=drafts');
    }

    $stmt->bind_param(
        'iiss',
        $venture_id,
        $recipient_id,
        $subject,
        $body
    );

    $ok = $stmt->execute();
    $new_id = (int)$conn->insert_id;
    $stmt->close();

    if (!$ok) {
        pm_flash(
            'Draft could not be saved.',
            'error'
        );

        pm_redirect('folder=drafts');
    }

    pm_flash('Draft saved.');
    pm_redirect(
        'folder=drafts&draft='
        . $new_id
    );
}

/* --------------------------------------------------------------------------
   Delete draft
   -------------------------------------------------------------------------- */

if ($action === 'delete_draft') {
    $draft_id = max(0, (int)($_POST['draft_id'] ?? 0));

    if ($draft_id <= 0) {
        pm_flash(
            'Draft not found.',
            'error'
        );

        pm_redirect('folder=drafts');
    }

    $stmt = $conn->prepare("
        DELETE FROM venture_message_drafts
        WHERE id = ?
          AND venture_id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param(
            'ii',
            $draft_id,
            $venture_id
        );

        $stmt->execute();
        $stmt->close();
    }

    pm_flash('Draft deleted.');
    pm_redirect('folder=drafts');
}

/* --------------------------------------------------------------------------
   Send new message
   -------------------------------------------------------------------------- */

if ($action === 'send') {
    $draft_id = max(0, (int)($_POST['draft_id'] ?? 0));
    $recipient_id = max(0, (int)($_POST['recipient_mentor_id'] ?? 0));
    $subject = trim((string)($_POST['subject'] ?? ''));
    $body = trim((string)($_POST['body'] ?? ''));

    if ($subject === '' || $body === '') {
        pm_flash(
            'Subject and message are required.',
            'error'
        );

        pm_redirect(
            $draft_id > 0
                ? 'folder=drafts&draft=' . $draft_id
                : 'folder=inbox'
        );
    }

    if (!pm_valid_recipient(
        $conn,
        $venture_id,
        $recipient_id,
        $cohort_id
    )) {
        pm_flash(
            'The selected recipient is not available.',
            'error'
        );

        pm_redirect('folder=inbox');
    }

    $thread_id = bin2hex(random_bytes(16));

    $recipient_type =
        $recipient_id > 0
            ? 'mentor'
            : 'admin';

    $stmt = $conn->prepare("
        INSERT INTO mentor_messages
            (
                thread_id,
                sender_type,
                sender_id,
                recipient_type,
                recipient_id,
                subject,
                body
            )
        VALUES (
            ?,
            'venture',
            ?,
            ?,
            ?,
            ?,
            ?
        )
    ");

    if (!$stmt) {
        pm_flash(
            'Message could not be sent.',
            'error'
        );

        pm_redirect('folder=inbox');
    }

    $stmt->bind_param(
        'siisss',
        $thread_id,
        $venture_id,
        $recipient_type,
        $recipient_id,
        $subject,
        $body
    );

    $ok = $stmt->execute();
    $error = $stmt->error;
    $stmt->close();

    if (!$ok) {
        pm_flash(
            'Message could not be sent: '
            . $error,
            'error'
        );

        pm_redirect('folder=inbox');
    }

    if ($draft_id > 0) {
        $stmt = $conn->prepare("
            DELETE FROM venture_message_drafts
            WHERE id = ?
              AND venture_id = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param(
                'ii',
                $draft_id,
                $venture_id
            );

            $stmt->execute();
            $stmt->close();
        }
    }

    /*
     * If the common notification helper is loaded through config/bootstrap,
     * continue to notify the recipient exactly as the former portal processor
     * did. The message itself is already saved even if the helper is absent.
     */
    if (function_exists('pp_notify_message_receiver')) {
        pp_notify_message_receiver(
            $conn,
            $recipient_type,
            $recipient_id,
            $subject,
            $body,
            $_SESSION['venture_founder']
                ?? $_SESSION['venture_name']
                ?? 'Founder',
            (string)(
                $VENTURE['name']
                ?? $_SESSION['venture_name']
                ?? ''
            ),
            $thread_id
        );
    }

    pm_flash('Message sent.');
    pm_redirect(
        'folder=sent&thread='
        . rawurlencode($thread_id)
    );
}

/* --------------------------------------------------------------------------
   Reply
   -------------------------------------------------------------------------- */

if ($action === 'reply') {
    $thread_id = trim((string)($_POST['thread_id'] ?? ''));
    $body = trim((string)($_POST['body'] ?? ''));

    if ($thread_id === '' || $body === '') {
        pm_flash(
            'Reply cannot be empty.',
            'error'
        );

        pm_redirect('folder=inbox');
    }

    $stmt = $conn->prepare("
        SELECT
            sender_type,
            sender_id,
            recipient_type,
            recipient_id,
            subject
        FROM mentor_messages
        WHERE thread_id = ?
          AND (
                (
                    sender_type = 'venture'
                    AND sender_id = ?
                )
                OR
                (
                    recipient_type = 'venture'
                    AND recipient_id = ?
                )
          )
        ORDER BY id ASC
        LIMIT 1
    ");

    if (!$stmt) {
        pm_flash(
            'Conversation could not be loaded.',
            'error'
        );

        pm_redirect('folder=inbox');
    }

    $stmt->bind_param(
        'sii',
        $thread_id,
        $venture_id,
        $venture_id
    );

    $stmt->execute();
    $first = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$first) {
        pm_flash(
            'Conversation not found.',
            'error'
        );

        pm_redirect('folder=inbox');
    }

    if (
        (string)$first['sender_type'] === 'venture'
    ) {
        $recipient_type =
            (string)$first['recipient_type'];

        $recipient_id =
            (int)$first['recipient_id'];
    } else {
        $recipient_type =
            (string)$first['sender_type'];

        $recipient_id =
            (int)$first['sender_id'];
    }

    $stmt = $conn->prepare("
        INSERT INTO mentor_messages
            (
                thread_id,
                sender_type,
                sender_id,
                recipient_type,
                recipient_id,
                body
            )
        VALUES (
            ?,
            'venture',
            ?,
            ?,
            ?,
            ?
        )
    ");

    if (!$stmt) {
        pm_flash(
            'Reply could not be sent.',
            'error'
        );

        pm_redirect(
            'folder=inbox&thread='
            . rawurlencode($thread_id)
        );
    }

    $stmt->bind_param(
        'siiss',
        $thread_id,
        $venture_id,
        $recipient_type,
        $recipient_id,
        $body
    );

    $ok = $stmt->execute();
    $error = $stmt->error;
    $stmt->close();

    if (!$ok) {
        pm_flash(
            'Reply could not be sent: '
            . $error,
            'error'
        );

        pm_redirect(
            'folder=inbox&thread='
            . rawurlencode($thread_id)
        );
    }

    if (function_exists('pp_notify_message_receiver')) {
        $subject =
            trim(
                (string)(
                    $first['subject']
                    ?? 'New reply'
                )
            );

        pp_notify_message_receiver(
            $conn,
            $recipient_type,
            $recipient_id,
            $subject !== ''
                ? $subject
                : 'New reply',
            $body,
            $_SESSION['venture_founder']
                ?? $_SESSION['venture_name']
                ?? 'Founder',
            (string)(
                $VENTURE['name']
                ?? $_SESSION['venture_name']
                ?? ''
            ),
            $thread_id
        );
    }

    pm_flash('Reply sent.');
    pm_redirect(
        'folder=sent&thread='
        . rawurlencode($thread_id)
    );
}

pm_flash(
    'Unsupported message action.',
    'error'
);

pm_redirect('folder=inbox');
