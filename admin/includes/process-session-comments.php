<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/../../includes/config.php';
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection error.');
}
$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$is_admin_context   = !empty($_SESSION['admin_id']);
$is_venture_context = !empty($_SESSION['venture_id']);


function sc_redirect(bool $is_admin_context, string $qs = ''): void
{
    $target = $is_admin_context
        ? '../mentor-sessions.php'
        : '../../sessions.php';
    header('Location: ' . $target . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

function sc_flash(string $msg, string $type = 'success'): void
{
    if (function_exists('flash')) {
        flash('sessions', $msg, $type);
    } else {
        $_SESSION['vp_flash_msg']   = $msg;
        $_SESSION['vp_flash_type']  = $type;
        $_SESSION['sessions_flash'] = ['msg' => $msg, 'type' => $type];
    }
}


if (!$is_admin_context && !$is_venture_context) {
    http_response_code(403);
    die('Unauthorized.');
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sc_redirect($is_admin_context);
}

$action = trim((string)($_POST['action'] ?? ''));

$author_type       = 'admin';
$author_name        = 'Admin';
$mentor_record_id   = 0;  
$venture_record_id  = 0;   
$can_manage_all     = false;
$is_mentor          = false;

if ($is_admin_context) {

    if (!function_exists('require_admin_login')) {
        require_once __DIR__ . '/auth.php';
    }
   

    $is_mentor      = function_exists('ms_is_mentor')  ? ms_is_mentor()  : (ms_current_role() === 'mentor');
    $can_manage_all = function_exists('ms_can_manage') ? ms_can_manage() : false;

    $author_type = $is_mentor ? 'mentor' : 'admin';

    if ($is_mentor && function_exists('ms_mentor_record_id')) {
        $mentor_record_id = ms_mentor_record_id($conn);
    }

    $admin_name = (string)($GLOBALS['ADMIN']['full_name'] ?? '');
    if ($admin_name === '') {
        $uid = (int)($_SESSION['admin_id'] ?? 0);
        if ($uid > 0) {
            $st = $conn->prepare("SELECT full_name FROM admin_users WHERE id = ? LIMIT 1");
            if ($st) {
                $st->bind_param('i', $uid);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $st->close();
                $admin_name = (string)($row['full_name'] ?? '');
            }
        }
    }
    $author_name = $admin_name !== '' ? $admin_name : ($is_mentor ? 'Mentor' : 'Admin');

    // Allow an explicit author_name override typed in the admin UI
    $posted_name = trim((string)($_POST['author_name'] ?? ''));
    if ($posted_name !== '') {
        $author_name = $posted_name;
    }

} elseif ($is_venture_context) {
    $author_type        = 'venture';
    $venture_record_id  = (int)$_SESSION['venture_id'];

    $founder = trim((string)($_SESSION['venture_founder'] ?? ''));
    $vname   = trim((string)($_SESSION['venture_name']    ?? ''));
    $author_name = $founder !== '' ? $founder : ($vname !== '' ? $vname : 'Venture');
}




if ($action === 'add_comment') {
    $session_id   = (int)($_POST['session_id'] ?? 0);
    $comment_text = trim((string)($_POST['comment_text'] ?? ''));

    if ($session_id <= 0) {
        sc_flash('Invalid session.', 'error');
        sc_redirect($is_admin_context);
    }
    if ($comment_text === '') {
        sc_flash('Comment cannot be empty.', 'error');
        sc_redirect($is_admin_context);
    }
    if (mb_strlen($comment_text) > 4000) {
        sc_flash('Comment is too long (max 4000 characters).', 'error');
        sc_redirect($is_admin_context);
    }

    /* -- Ownership checks ------------------------------------------- */
    $st = $conn->prepare("SELECT mentor_id, venture_id FROM mentor_sessions WHERE id = ? LIMIT 1");
    if (!$st) { sc_flash('DB error: ' . $conn->error, 'error'); sc_redirect($is_admin_context); }
    $st->bind_param('i', $session_id);
    $st->execute();
    $session_row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$session_row) {
        sc_flash('Session not found.', 'error');
        sc_redirect($is_admin_context);
    }

    if ($is_admin_context) {
        // Mentor role: can only comment on their own sessions
        if ($is_mentor && !$can_manage_all) {
            if ($mentor_record_id <= 0 || (int)$session_row['mentor_id'] !== $mentor_record_id) {
                sc_flash('You can only comment on your own sessions.', 'error');
                sc_redirect($is_admin_context);
            }
        }
        // Privileged roles: no restriction
    } else {
        // Venture context: can only comment on their own sessions
        if ((int)$session_row['venture_id'] !== $venture_record_id) {
            sc_flash('You can only comment on your own sessions.', 'error');
            sc_redirect($is_admin_context);
        }
    }

    /* -- Insert -------------------------------------------------------- */
    $st = $conn->prepare("
        INSERT INTO session_comments (session_id, author_type, author_name, comment_text)
        VALUES (?, ?, ?, ?)
    ");
    if (!$st) {
        sc_flash('DB error: ' . $conn->error, 'error');
        sc_redirect($is_admin_context);
    }
    $st->bind_param('isss', $session_id, $author_type, $author_name, $comment_text);
    $ok  = $st->execute();
    $err = $st->error;
    $st->close();

    sc_flash($ok ? 'Comment added.' : 'Failed to add comment: ' . $err, $ok ? 'success' : 'error');

    // Preserve query string context (view, month, filters) on redirect if passed through
    $back_qs = '';
    $keep = ['view', 'month', 'status', 'mentor_id', 'venture_id', 'cohort_id'];
    $params = [];
    foreach ($keep as $k) {
        if (isset($_POST['_return_' . $k]) && $_POST['_return_' . $k] !== '') {
            $params[$k] = $_POST['_return_' . $k];
        }
    }
    if ($params) {
        $back_qs = http_build_query($params);
    }

    sc_redirect($is_admin_context, $back_qs);
}


if ($action === 'delete_comment') {
    if (!$is_admin_context || !$can_manage_all) {
        sc_flash('You do not have permission to delete comments.', 'error');
        sc_redirect($is_admin_context);
    }

    $comment_id = (int)($_POST['comment_id'] ?? 0);
    if ($comment_id <= 0) {
        sc_flash('Invalid comment.', 'error');
        sc_redirect($is_admin_context);
    }

    $st = $conn->prepare("DELETE FROM session_comments WHERE id = ? LIMIT 1");
    if (!$st) { sc_flash('DB error: ' . $conn->error, 'error'); sc_redirect($is_admin_context); }
    $st->bind_param('i', $comment_id);
    $ok  = $st->execute();
    $err = $st->error;
    $st->close();

    sc_flash($ok ? 'Comment deleted.' : 'Failed: ' . $err, $ok ? 'success' : 'error');
    sc_redirect($is_admin_context);
}


sc_flash('Invalid action.', 'error');
sc_redirect($is_admin_context);