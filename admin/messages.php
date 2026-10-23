<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('DB error');
}

$conn->set_charset('utf8mb4');

ms_auth_gate();

$IS_MENTOR     = ms_is_mentor();
$IS_PRIVILEGED = ms_can_manage();

if (!$IS_MENTOR && !ms_has_page_permission($conn, 'mentor-messages')) {
    http_response_code(403);
    include 'includes/sidebar.php'; ?>
    <div class="admin-main"><div class="admin-content legacy-style-5534667570">
    <h2>Access denied</h2>
    <p>Your role is not allowed to access mentor messages.</p>
    </div></div><?php exit;
}

$CAN_MANAGE = !$IS_MENTOR;

$MENTOR_RECORD_ID = ms_mentor_record_id($conn);

if ($IS_MENTOR && $MENTOR_RECORD_ID <= 0) {
    include 'includes/sidebar.php'; ?>
    <div class="admin-main"><div class="admin-content legacy-style-5534667570">
    <h2>Account not linked</h2>
    <p>Your mentor account is not yet linked to a mentor profile. Please contact the programme administrator.</p>
    </div></div><?php exit;
}

/* --------------------------------------------------------------------------
   Ensure drafts table
   -------------------------------------------------------------------------- */
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

$conn->query("
    CREATE TABLE IF NOT EXISTS mentor_message_thread_hidden (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        owner_type VARCHAR(20) NOT NULL DEFAULT 'admin',
        owner_id INT UNSIGNED NOT NULL DEFAULT 0,
        thread_id VARCHAR(64) NOT NULL,
        hidden_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_mmth_owner_thread (owner_type, owner_id, thread_id),
        KEY idx_mmth_owner (owner_type, owner_id, hidden_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Verify the hidden-threads table is usable; if not, the page still renders
// and simply shows all threads (deletion filtering is skipped).
$hidden_table_exists = false;
$ht = $conn->query("SHOW TABLES LIKE 'mentor_message_thread_hidden'");
if ($ht && $ht->num_rows > 0) {
    $hidden_table_exists = true;
}

/* --------------------------------------------------------------------------
   Request state
   -------------------------------------------------------------------------- */
$folder = strtolower(trim((string)($_GET['folder'] ?? 'inbox')));
if (!in_array($folder, ['inbox', 'outbox', 'drafts'], true)) {
    $folder = 'inbox';
}

$search        = trim((string)($_GET['q'] ?? ''));
$active_thread = trim((string)($_GET['thread'] ?? ''));
$draft_id      = max(0, (int)($_GET['draft'] ?? 0));

/* --------------------------------------------------------------------------
   Identity ("me") for direction, unread and folder scoping
   -------------------------------------------------------------------------- */
if ($CAN_MANAGE) {
    $me_type = 'admin';
    $me_id   = (int)($ADMIN['id'] ?? ($_SESSION['admin_id'] ?? 0));
} else {
    $me_type = 'mentor';
    $me_id   = $MENTOR_RECORD_ID;
}

$msg_is_me = static function (string $type, int $id) use ($me_type, $me_id): bool {
    if ($type === 'admin') {
        return $me_type === 'admin';
    }
    if ($type === 'mentor') {
        return $me_type === 'mentor' && $id === $me_id;
    }
    return false;
};

/* --------------------------------------------------------------------------
   Helpers
   -------------------------------------------------------------------------- */
function msg_date(?string $date): string
{
    if (!$date) {
        return '';
    }
    $ts = strtotime($date);
    if (!$ts) {
        return '';
    }
    if (date('Y-m-d', $ts) === date('Y-m-d')) {
        return date('g:i A', $ts);
    }
    if (date('Y', $ts) === date('Y')) {
        return date('M j', $ts);
    }
    return date('M j, Y', $ts);
}

function msg_full_date(?string $date): string
{
    if (!$date) {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date('M j, Y, g:i A', $ts) : '';
}

function msg_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '?';
    }
    $parts = preg_split('/\s+/', $name) ?: [$name];
    if (count($parts) >= 2) {
        return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
    }
    return strtoupper(mb_substr($name, 0, 2));
}

function msg_party_name(mysqli $conn, string $type, int $id): string
{
    $type = strtolower(trim($type));

    if ($type === 'mentor' && $id > 0) {
        $stmt = $conn->prepare("SELECT full_name FROM mentors WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $name = $stmt->get_result()->fetch_row()[0] ?? '';
            $stmt->close();
            return $name !== '' ? (string)$name : 'Mentor';
        }
        return 'Mentor';
    }

    if ($type === 'venture' && $id > 0) {
        $stmt = $conn->prepare("SELECT name FROM ventures WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $name = $stmt->get_result()->fetch_row()[0] ?? '';
            $stmt->close();
            return $name !== '' ? (string)$name : 'Venture';
        }
        return 'Venture';
    }

    return 'Programme Team';
}

function mentor_owns_thread(mysqli $conn, string $thread_id, int $mentor_id): bool
{
    if ($thread_id === '' || $mentor_id <= 0) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT 1
        FROM mentor_messages
        WHERE thread_id = ?
          AND (
                (sender_type = 'mentor' AND sender_id = ?)
             OR (recipient_type = 'mentor' AND recipient_id = ?)
          )
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('sii', $thread_id, $mentor_id, $mentor_id);
    $stmt->execute();
    $found = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $found;
}

if ($active_thread !== '' && $IS_MENTOR && !mentor_owns_thread($conn, $active_thread, $MENTOR_RECORD_ID)) {
    $active_thread = '';
}

/* --------------------------------------------------------------------------
   Mark opened thread as read
   -------------------------------------------------------------------------- */
if ($active_thread !== '') {
    if ($CAN_MANAGE) {
        $stmt = $conn->prepare("UPDATE mentor_messages SET is_read = 1 WHERE thread_id = ? AND recipient_type = 'admin'");
        if ($stmt) {
            $stmt->bind_param('s', $active_thread);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        $stmt = $conn->prepare("UPDATE mentor_messages SET is_read = 1 WHERE thread_id = ? AND recipient_type = 'mentor' AND recipient_id = ?");
        if ($stmt) {
            $stmt->bind_param('si', $active_thread, $MENTOR_RECORD_ID);
            $stmt->execute();
            $stmt->close();
        }
    }
}

/* --------------------------------------------------------------------------
   Folder counts
   -------------------------------------------------------------------------- */
$count_inbox  = 0;
$count_outbox = 0;
$count_drafts = 0;
$total_unread = 0;

if ($CAN_MANAGE) {
    $hidden_where = $hidden_table_exists
        ? " WHERE NOT EXISTS (SELECT 1 FROM mentor_message_thread_hidden h WHERE h.owner_type = 'admin' AND h.owner_id = {$me_id} AND h.thread_id = mm.thread_id)"
        : '';
    $res = $conn->query("
        SELECT
            COUNT(DISTINCT CASE WHEN mm.recipient_type = 'admin' THEN mm.thread_id END) AS inbox,
            COUNT(DISTINCT CASE WHEN mm.sender_type = 'admin' THEN mm.thread_id END) AS outbox,
            SUM(mm.is_read = 0 AND mm.recipient_type = 'admin') AS unread
        FROM mentor_messages mm
        {$hidden_where}
    ");
    if ($res) {
        $row = $res->fetch_assoc() ?: [];
        $count_inbox  = (int)($row['inbox'] ?? 0);
        $count_outbox = (int)($row['outbox'] ?? 0);
        $total_unread = (int)($row['unread'] ?? 0);
    }
} else {
    $hidden_where = $hidden_table_exists
        ? " AND NOT EXISTS (SELECT 1 FROM mentor_message_thread_hidden h WHERE h.owner_type = 'mentor' AND h.owner_id = {$me_id} AND h.thread_id = mm.thread_id)"
        : '';
    $res = $conn->query("
        SELECT
            COUNT(DISTINCT CASE WHEN mm.recipient_type = 'mentor' AND mm.recipient_id = {$me_id} THEN mm.thread_id END) AS inbox,
            COUNT(DISTINCT CASE WHEN mm.sender_type = 'mentor' AND mm.sender_id = {$me_id} THEN mm.thread_id END) AS outbox,
            SUM(mm.is_read = 0 AND mm.recipient_type = 'mentor' AND mm.recipient_id = {$me_id}) AS unread
        FROM mentor_messages mm
        WHERE ((mm.sender_type = 'mentor' AND mm.sender_id = {$me_id}) OR (mm.recipient_type = 'mentor' AND mm.recipient_id = {$me_id}))
        {$hidden_where}
    ");
    if ($res) {
        $row = $res->fetch_assoc() ?: [];
        $count_inbox  = (int)($row['inbox'] ?? 0);
        $count_outbox = (int)($row['outbox'] ?? 0);
        $total_unread = (int)($row['unread'] ?? 0);
    }
}

$stmt = $conn->prepare("SELECT COUNT(*) FROM mentor_message_drafts WHERE owner_type = ? AND owner_id = ?");
if ($stmt) {
    $stmt->bind_param('si', $me_type, $me_id);
    $stmt->execute();
    $count_drafts = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
    $stmt->close();
}

/* --------------------------------------------------------------------------
   Thread rows (inbox / outbox)
   -------------------------------------------------------------------------- */
$thread_rows = [];

if ($folder !== 'drafts') {
    if ($CAN_MANAGE) {
        $unread_expr   = "CASE WHEN mm.is_read = 0 AND mm.recipient_type = 'admin' THEN 1 ELSE 0 END";
        $scope_sql     = '';
        $inbox_exists  = "EXISTS (SELECT 1 FROM mentor_messages f WHERE f.thread_id = mm.thread_id AND f.recipient_type = 'admin')";
        $outbox_exists = "EXISTS (SELECT 1 FROM mentor_messages f WHERE f.thread_id = mm.thread_id AND f.sender_type = 'admin')";
    } else {
        $unread_expr   = "CASE WHEN mm.is_read = 0 AND mm.recipient_type = 'mentor' AND mm.recipient_id = {$me_id} THEN 1 ELSE 0 END";
        $scope_sql     = " AND EXISTS (
            SELECT 1 FROM mentor_messages s
            WHERE s.thread_id = mm.thread_id
              AND ((s.sender_type = 'mentor' AND s.sender_id = {$me_id}) OR (s.recipient_type = 'mentor' AND s.recipient_id = {$me_id}))
        )";
        $inbox_exists  = "EXISTS (SELECT 1 FROM mentor_messages f WHERE f.thread_id = mm.thread_id AND f.recipient_type = 'mentor' AND f.recipient_id = {$me_id})";
        $outbox_exists = "EXISTS (SELECT 1 FROM mentor_messages f WHERE f.thread_id = mm.thread_id AND f.sender_type = 'mentor' AND f.sender_id = {$me_id})";
    }

    $folder_sql = '';
    if ($folder === 'inbox') {
        $folder_sql = " AND {$inbox_exists}";
    } elseif ($folder === 'outbox') {
        $folder_sql = " AND {$outbox_exists}";
    }

    $hidden_sql = $hidden_table_exists
        ? " AND NOT EXISTS (
            SELECT 1 FROM mentor_message_thread_hidden h
            WHERE h.owner_type = '{$me_type}' AND h.owner_id = {$me_id} AND h.thread_id = mm.thread_id
        )"
        : '';

    $search_sql    = '';
    $search_params  = [];
    $search_types   = '';

    if ($search !== '') {
        $search_sql = " AND EXISTS (
            SELECT 1 FROM mentor_messages q
            WHERE q.thread_id = mm.thread_id
              AND (q.subject LIKE ? OR q.body LIKE ?)
        )";
        $like = '%' . $search . '%';
        $search_params = [$like, $like];
        $search_types  = 'ss';
    }

    $thread_sql = "
        SELECT
            mm.thread_id,
            MAX(mm.created_at) AS last_at,
            SUM({$unread_expr}) AS unread,
            (
                SELECT m1.subject FROM mentor_messages m1
                WHERE m1.thread_id = mm.thread_id AND m1.subject IS NOT NULL AND m1.subject <> ''
                ORDER BY m1.id ASC LIMIT 1
            ) AS subject,
            (
                SELECT m2.body FROM mentor_messages m2
                WHERE m2.thread_id = mm.thread_id ORDER BY m2.id DESC LIMIT 1
            ) AS last_body,
            (
                SELECT m3.sender_type FROM mentor_messages m3
                WHERE m3.thread_id = mm.thread_id ORDER BY m3.id DESC LIMIT 1
            ) AS last_sender_type,
            (
                SELECT m4.sender_id FROM mentor_messages m4
                WHERE m4.thread_id = mm.thread_id ORDER BY m4.id DESC LIMIT 1
            ) AS last_sender_id,
            (
                SELECT m5.recipient_type FROM mentor_messages m5
                WHERE m5.thread_id = mm.thread_id ORDER BY m5.id DESC LIMIT 1
            ) AS last_recipient_type,
            (
                SELECT m6.recipient_id FROM mentor_messages m6
                WHERE m6.thread_id = mm.thread_id ORDER BY m6.id DESC LIMIT 1
            ) AS last_recipient_id
        FROM mentor_messages mm
        WHERE 1 = 1
        {$scope_sql}
        {$folder_sql}
        {$hidden_sql}
        {$search_sql}
        GROUP BY mm.thread_id
        ORDER BY last_at DESC
        LIMIT 200
    ";

    $stmt = $conn->prepare($thread_sql);
    if ($stmt) {
        if ($search_params) {
            $stmt->bind_param($search_types, ...$search_params);
        }
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            while ($res && ($t = $res->fetch_assoc())) {
                $last_sender_type = (string)($t['last_sender_type'] ?? '');
                $last_sender_id   = (int)($t['last_sender_id'] ?? 0);
                $last_recipient_type = (string)($t['last_recipient_type'] ?? '');
                $last_recipient_id   = (int)($t['last_recipient_id'] ?? 0);

                if ($msg_is_me($last_sender_type, $last_sender_id)) {
                    $party_type = $last_recipient_type;
                    $party_id   = $last_recipient_id;
                    $direction  = 'sent';
                } else {
                    $party_type = $last_sender_type;
                    $party_id   = $last_sender_id;
                    $direction  = 'received';
                }

                $t['party_name'] = msg_party_name($conn, $party_type, $party_id);
                $t['direction']  = $direction;
                $thread_rows[]   = $t;
            }
        }
        $stmt->close();
    }
}

/* --------------------------------------------------------------------------
   Draft rows
   -------------------------------------------------------------------------- */
$draft_rows = [];

if ($folder === 'drafts') {
    $draft_sql = "SELECT * FROM mentor_message_drafts WHERE owner_type = ? AND owner_id = ?";
    $draft_params = [$me_type, $me_id];
    $draft_types  = 'si';

    if ($search !== '') {
        $draft_sql .= " AND (subject LIKE ? OR body LIKE ?)";
        $like = '%' . $search . '%';
        $draft_params[] = $like;
        $draft_params[] = $like;
        $draft_types   .= 'ss';
    }

    $draft_sql .= " ORDER BY updated_at DESC LIMIT 100";

    $stmt = $conn->prepare($draft_sql);
    if ($stmt) {
        $stmt->bind_param($draft_types, ...$draft_params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($d = $res->fetch_assoc()) {
            $d['party_label'] = msg_draft_label($conn, (string)$d['recipient_mentor_ids'], (string)$d['recipient_venture_ids'], (int)$d['send_to_admin']);
            $draft_rows[] = $d;
        }
        $stmt->close();
    }
}

function msg_draft_label(mysqli $conn, string $mentor_ids, string $venture_ids, int $send_to_admin): string
{
    $parts = [];

    $mids = array_values(array_filter(array_map('intval', explode(',', $mentor_ids))));
    $vids = array_values(array_filter(array_map('intval', explode(',', $venture_ids))));

    if ($send_to_admin === 1) {
        $parts[] = 'Programme Team';
    }
    if (count($mids) > 0) {
        $parts[] = count($mids) . ' mentor' . (count($mids) === 1 ? '' : 's');
    }
    if (count($vids) > 0) {
        $parts[] = count($vids) . ' venture' . (count($vids) === 1 ? '' : 's');
    }

    return $parts ? implode(', ', $parts) : 'No recipients';
}

/* --------------------------------------------------------------------------
   Active thread messages
   -------------------------------------------------------------------------- */
$thread_messages = [];
$thread_subject  = '';
$thread_party    = '';

if ($active_thread !== '') {
    $stmt = $conn->prepare("
        SELECT * FROM mentor_messages
        WHERE thread_id = ?
        ORDER BY created_at ASC, id ASC
    ");
    if ($stmt) {
        $stmt->bind_param('s', $active_thread);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($m = $res->fetch_assoc()) {
            if ($thread_subject === '' && !empty($m['subject'])) {
                $thread_subject = (string)$m['subject'];
            }
            if ($thread_party === '') {
                $s_type = (string)$m['sender_type'];
                $s_id   = (int)$m['sender_id'];
                if (!$msg_is_me($s_type, $s_id)) {
                    $thread_party = msg_party_name($conn, $s_type, $s_id);
                }
            }
            $thread_messages[] = $m;
        }
        $stmt->close();
    }
}

/* --------------------------------------------------------------------------
   Active draft
   -------------------------------------------------------------------------- */
$active_draft = null;

if ($folder === 'drafts' && $draft_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM mentor_message_drafts WHERE id = ? AND owner_type = ? AND owner_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('isi', $draft_id, $me_type, $me_id);
        $stmt->execute();
        $active_draft = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
}

/* --------------------------------------------------------------------------
   Compose recipients
   -------------------------------------------------------------------------- */
$mentor_options  = [];
$venture_options = [];

if ($CAN_MANAGE) {
    $mentor_result = $conn->query("SELECT id, full_name FROM mentors WHERE status = 'active' ORDER BY full_name ASC");
    if ($mentor_result) {
        $mentor_options = $mentor_result->fetch_all(MYSQLI_ASSOC);
    }
}

if ($IS_MENTOR) {
    $venture_result = $conn->query("
        SELECT DISTINCT v.id, v.name
        FROM ventures v
        WHERE v.id IN (
            SELECT sv.venture_id
            FROM session_ventures sv
            JOIN session_mentors sm ON sm.session_id = sv.session_id
            WHERE sm.mentor_id = {$MENTOR_RECORD_ID}
        )
        OR v.id IN (
            SELECT mm_v.sender_id FROM mentor_messages mm_v
            WHERE mm_v.sender_type = 'venture'
              AND mm_v.thread_id IN (
                  SELECT thread_id FROM mentor_messages
                  WHERE (sender_type = 'mentor' AND sender_id = {$MENTOR_RECORD_ID})
                     OR (recipient_type = 'mentor' AND recipient_id = {$MENTOR_RECORD_ID})
              )
        )
        OR v.id IN (
            SELECT mm_v.recipient_id FROM mentor_messages mm_v
            WHERE mm_v.recipient_type = 'venture'
              AND mm_v.thread_id IN (
                  SELECT thread_id FROM mentor_messages
                  WHERE (sender_type = 'mentor' AND sender_id = {$MENTOR_RECORD_ID})
                     OR (recipient_type = 'mentor' AND recipient_id = {$MENTOR_RECORD_ID})
              )
        )
        ORDER BY v.name ASC
    ");
} else {
    $venture_result = $conn->query("SELECT id, name FROM ventures ORDER BY name ASC");
}

if ($venture_result) {
    $venture_options = $venture_result->fetch_all(MYSQLI_ASSOC);
}

/* --------------------------------------------------------------------------
   Compose prefill (when editing a draft)
   -------------------------------------------------------------------------- */
$draft_mentor_ids  = [];
$draft_venture_ids = [];
$draft_send_admin  = 0;
$draft_subject     = '';
$draft_body        = '';

if ($active_draft) {
    $draft_subject     = (string)$active_draft['subject'];
    $draft_body        = (string)$active_draft['body'];
    $draft_send_admin  = (int)$active_draft['send_to_admin'];
    $draft_mentor_ids  = array_values(array_filter(array_map('intval', explode(',', (string)$active_draft['recipient_mentor_ids']))));
    $draft_venture_ids = array_values(array_filter(array_map('intval', explode(',', (string)$active_draft['recipient_venture_ids']))));
}

$send_admin_checked = $active_draft ? ($draft_send_admin ? 'checked' : '') : ($IS_MENTOR ? 'checked' : '');

$site_name = function_exists('get_setting')
    ? get_setting($conn, 'site_name', 'EdTech Fellowship')
    : 'EdTech Fellowship';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Messages - <?= h($site_name) ?> Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="assets/css/admin.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/admin.css') ?>">
  <link rel="stylesheet" href="assets/css/messages.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/messages.css') ?>">
</head>
<body class="admin-system-page">
<?php include 'includes/sidebar.php'; ?>
<div class="admin-main" id="adminMain">
<header class="admin-topbar">
  <div class="topbar-left"><div class="topbar-breadcrumb"><a href="index.php">Dashboard</a> &rsaquo; <?php if($IS_PRIVILEGED): ?><a href="mentors.php">Mentors</a> &rsaquo; <?php endif; ?> <strong>Messages</strong></div></div>
  <div class="topbar-right">
    <button class="btn btn-primary btn-sm" onclick="openComposeModal()"><i class="fa fa-pen"></i> Compose</button>
    <div class="admin-avatar"><div class="avatar-circle"><?= h(strtoupper(substr($ADMIN['full_name'] ?? 'A', 0, 1))) ?></div></div>
  </div>
</header>

<div class="admin-content">
<?php show_flash('mmsg'); ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Messages <?php if($total_unread): ?><span class="badge badge-danger legacy-style-5e0faad207"><?= $total_unread ?> unread</span><?php endif; ?></h1>
    <p class="page-subtitle"><?= $CAN_MANAGE ? 'Conversations between mentors, ventures & programme admin' : 'Your conversations with the programme team and your ventures' ?></p>
  </div>
</div>

<div class="mail-client">

  <!-- Folder sidebar -->
  <aside class="mail-sidebar">
    <button type="button" class="mail-compose" onclick="openComposeModal()">
      <i class="fa fa-pen"></i> Compose
    </button>

    <nav class="mail-nav">
      <a href="messages.php?folder=inbox" class="mail-nav-link <?= $folder === 'inbox' ? 'active' : '' ?>">
        <i class="fa fa-inbox"></i> Inbox
        <?php if ($total_unread > 0): ?>
          <span class="mail-nav-count is-unread"><?= $total_unread ?></span>
        <?php elseif ($count_inbox > 0): ?>
          <span class="mail-nav-count"><?= $count_inbox ?></span>
        <?php endif; ?>
      </a>

      <a href="messages.php?folder=outbox" class="mail-nav-link <?= $folder === 'outbox' ? 'active' : '' ?>">
        <i class="fa fa-paper-plane"></i> Outbox
        <?php if ($count_outbox > 0): ?>
          <span class="mail-nav-count"><?= $count_outbox ?></span>
        <?php endif; ?>
      </a>

      <a href="messages.php?folder=drafts" class="mail-nav-link <?= $folder === 'drafts' ? 'active' : '' ?>">
        <i class="fa fa-file-alt"></i> Drafts
        <?php if ($count_drafts > 0): ?>
          <span class="mail-nav-count"><?= $count_drafts ?></span>
        <?php endif; ?>
      </a>
    </nav>
  </aside>

  <!-- List pane -->
  <section class="mail-list-pane">
    <header class="mail-list-head">
      <h2><?= ucfirst($folder) ?><span class="mail-count"><?= $folder === 'drafts' ? $count_drafts : ($folder === 'outbox' ? $count_outbox : $count_inbox) ?></span></h2>

      <form method="GET" class="mail-search">
        <input type="hidden" name="folder" value="<?= h($folder) ?>">
        <i class="fa fa-search"></i>
        <input type="search" name="q" value="<?= h($search) ?>" placeholder="Search <?= strtolower($folder) ?>" autocomplete="off">
      </form>

      <?php if ($search !== ''): ?>
        <a href="messages.php?folder=<?= h($folder) ?>" class="mail-search-clear"><i class="fa fa-times"></i> Clear</a>
      <?php endif; ?>
    </header>

    <div class="mail-list-scroll">
      <?php if ($folder === 'drafts'): ?>

        <?php if (!$draft_rows): ?>
          <div class="mail-empty">
            <i class="fa fa-file-alt"></i>
            <strong>No drafts</strong>
            <p>Drafts you save will appear here.</p>
          </div>
        <?php else: ?>
          <?php foreach ($draft_rows as $draft): ?>
            <a href="messages.php?folder=drafts&amp;draft=<?= (int)$draft['id'] ?>" class="mail-row <?= $draft_id === (int)$draft['id'] ? 'active' : '' ?>">
              <div class="mail-avatar"><?= h(msg_initials((string)$draft['party_label'])) ?></div>
              <div class="mail-row-body">
                <div class="mail-row-top">
                  <span class="mail-row-party"><?= h((string)$draft['party_label']) ?></span>
                  <span class="mail-draft-badge">Draft</span>
                </div>
                <div class="mail-row-subject"><?= h(trim((string)$draft['subject']) !== '' ? (string)$draft['subject'] : '(no subject)') ?></div>
                <div class="mail-row-preview"><?= h(mb_strimwidth(trim((string)$draft['body']), 0, 90, '...')) ?></div>
              </div>
              <div class="mail-row-date"><?= h(msg_date((string)$draft['updated_at'])) ?></div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>

      <?php else: ?>

        <?php if (!$thread_rows): ?>
          <div class="mail-empty">
            <i class="fa fa-envelope-open"></i>
            <strong>No messages</strong>
            <p><?= $search !== '' ? 'No conversations match your search.' : ($folder === 'outbox' ? 'You have not sent any messages yet.' : 'No messages in your inbox.') ?></p>
          </div>
        <?php else: ?>
          <?php foreach ($thread_rows as $thread): $unread = (int)($thread['unread'] ?? 0) > 0; $is_active = $active_thread === (string)$thread['thread_id']; ?>
            <a href="messages.php?folder=<?= h($folder) ?>&amp;thread=<?= rawurlencode((string)$thread['thread_id']) ?>" class="mail-row <?= $unread ? 'unread' : '' ?> <?= $is_active ? 'active' : '' ?>">
              <div class="mail-avatar"><?= h(msg_initials((string)$thread['party_name'])) ?></div>
              <div class="mail-row-body">
                <div class="mail-row-top">
                  <span class="mail-row-party"><?= h((string)$thread['party_name']) ?></span>
                  <?php if ($unread): ?><span class="mail-unread-dot" title="Unread"></span><?php endif; ?>
                  <?php if (($thread['direction'] ?? '') === 'sent'): ?><span class="mail-direction">Sent</span><?php endif; ?>
                </div>
                <div class="mail-row-subject"><?= h(trim((string)($thread['subject'] ?? '')) !== '' ? (string)$thread['subject'] : '(no subject)') ?></div>
                <div class="mail-row-preview"><?= h(mb_strimwidth(trim((string)($thread['last_body'] ?? '')), 0, 90, '...')) ?></div>
              </div>
              <div class="mail-row-date"><?= h(msg_date((string)$thread['last_at'])) ?></div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>

      <?php endif; ?>
    </div>
  </section>

  <!-- Reader pane -->
  <?php if ($folder === 'drafts' && $active_draft): ?>

    <div class="mail-reader">
      <div class="mail-reader-head">
        <h2><?= h(trim((string)$active_draft['subject']) !== '' ? (string)$active_draft['subject'] : '(no subject)') ?></h2>
        <div class="mail-reader-meta">
          <span class="mail-draft-badge">Draft</span>
          <span class="sep">·</span>
          <span>Updated <?= h(msg_date((string)$active_draft['updated_at'])) ?></span>
          <span class="sep">·</span>
          <span><?= h(msg_draft_label($conn, (string)$active_draft['recipient_mentor_ids'], (string)$active_draft['recipient_venture_ids'], (int)$active_draft['send_to_admin'])) ?></span>
        </div>
      </div>

      <div class="mail-messages">
        <article class="mail-message">
          <div class="mail-message-head">
            <div class="mail-avatar"><?= h(msg_initials($ADMIN['full_name'] ?? 'A')) ?></div>
            <div class="mail-message-meta">
              <div class="mail-message-name"><?= h($ADMIN['full_name'] ?? 'You') ?></div>
              <div class="mail-message-to">Draft message</div>
            </div>
          </div>
          <div class="mail-message-body"><?= nl2br(h((string)$active_draft['body'])) ?></div>
        </article>
      </div>

      <div class="mail-reader-actions">
        <form method="POST" action="includes/process-mentors.php" onsubmit="return confirm('Delete this draft?')">
          <input type="hidden" name="action" value="delete_draft">
          <input type="hidden" name="draft_id" value="<?= (int)$active_draft['id'] ?>">
          <?php if ($IS_MENTOR): ?>
            <input type="hidden" name="reply_as" value="mentor">
            <input type="hidden" name="reply_as_mentor_id" value="<?= (int)$MENTOR_RECORD_ID ?>">
          <?php endif; ?>
          <button type="submit" class="mail-btn danger"><i class="fa fa-trash"></i> Delete</button>
        </form>
        <button type="button" class="mail-btn primary" onclick="openComposeModal()"><i class="fa fa-pen"></i> Continue editing</button>
      </div>
    </div>

  <?php elseif ($active_thread !== '' && $thread_messages): ?>

    <div class="mail-reader">
      <div class="mail-reader-head">
        <div class="mail-reader-head-row">
          <h2><?= h($thread_subject !== '' ? $thread_subject : 'Conversation') ?></h2>
          <form method="POST" action="includes/process-mentors.php" onsubmit="return confirm('Delete this conversation? It will be removed only for you.')">
            <input type="hidden" name="action" value="delete_thread">
            <input type="hidden" name="thread_id" value="<?= h($active_thread) ?>">
            <input type="hidden" name="folder" value="<?= h($folder) ?>">
            <?php if ($IS_MENTOR): ?>
              <input type="hidden" name="reply_as" value="mentor">
              <input type="hidden" name="reply_as_mentor_id" value="<?= (int)$MENTOR_RECORD_ID ?>">
            <?php endif; ?>
            <button type="submit" class="mail-btn danger" title="Delete conversation"><i class="fa fa-trash"></i></button>
          </form>
        </div>
        <div class="mail-reader-meta">
          <i class="fa fa-user-circle"></i>
          <?= h($thread_party !== '' ? $thread_party : 'Conversation') ?>
          <span class="sep">·</span>
          <?= count($thread_messages) ?> message<?= count($thread_messages) === 1 ? '' : 's' ?>
        </div>
      </div>

      <div class="mail-messages">
        <?php foreach ($thread_messages as $message):
          $sender_name = msg_party_name($conn, (string)$message['sender_type'], (int)$message['sender_id']);
          $recipient_name = msg_party_name($conn, (string)$message['recipient_type'], (int)$message['recipient_id']);
        ?>
          <article class="mail-message">
            <div class="mail-message-head">
              <div class="mail-avatar"><?= h(msg_initials($sender_name)) ?></div>
              <div class="mail-message-meta">
                <div class="mail-message-name"><?= h($sender_name) ?></div>
                <div class="mail-message-to">to <?= h($recipient_name) ?></div>
              </div>
              <div class="mail-message-date"><?= h(msg_full_date((string)$message['created_at'])) ?></div>
            </div>
            <div class="mail-message-body"><?= nl2br(h((string)$message['body'])) ?></div>
          </article>
        <?php endforeach; ?>
      </div>

      <div class="mail-reply">
        <form method="POST" action="includes/process-mentors.php">
          <input type="hidden" name="action" value="reply_message">
          <input type="hidden" name="thread_id" value="<?= h($active_thread) ?>">
          <?php if ($IS_MENTOR): ?>
            <input type="hidden" name="reply_as" value="mentor">
            <input type="hidden" name="reply_as_mentor_id" value="<?= (int)$MENTOR_RECORD_ID ?>">
          <?php else: ?>
            <input type="hidden" name="reply_as" value="admin">
          <?php endif; ?>
          <textarea name="body" rows="2" placeholder="Write a reply..." required></textarea>
          <div class="mail-reply-actions">
            <button type="submit" class="mail-btn primary"><i class="fa fa-paper-plane"></i> Send reply</button>
          </div>
        </form>
      </div>
    </div>

  <?php else: ?>

    <div class="mail-reader">
      <div class="mail-reader-empty">
        <i class="fa fa-comments"></i>
        <strong>Select a message to read</strong>
        <span>Choose a conversation from the list, or compose a new message.</span>
        <button class="mail-btn primary" onclick="openComposeModal()"><i class="fa fa-pen"></i> Compose</button>
      </div>
    </div>

  <?php endif; ?>

</div><!-- /mail-client -->
</div>
</div>

<!-- ---------- COMPOSE MODAL ---------- -->
<div class="modal-overlay <?= $active_draft ? 'open' : '' ?>" id="composeModal" aria-hidden="<?= $active_draft ? 'false' : 'true' ?>">
<div class="modal compose-modal" role="dialog" aria-modal="true" aria-labelledby="composeTitle">
  <div class="modal-header">
    <div>
      <h2 class="modal-title" id="composeTitle"><?= $active_draft ? 'Edit Draft' : 'New Message' ?></h2>
      <div class="compose-help">Select one or more recipients using the checkboxes below.</div>
    </div>
    <button type="button" class="modal-close" onclick="closeComposeModal()" aria-label="Close">&times;</button>
  </div>

  <form method="POST" action="includes/process-mentors.php" id="composeMessageForm">
    <input type="hidden" name="draft_id" value="<?= $active_draft ? (int)$active_draft['id'] : 0 ?>">

    <?php if ($IS_MENTOR): ?>
      <input type="hidden" name="reply_as" value="mentor">
      <input type="hidden" name="reply_as_mentor_id" value="<?= (int)$MENTOR_RECORD_ID ?>">
    <?php endif; ?>

    <div class="modal-body">
      <?php if ($CAN_MANAGE): ?>
        <div class="recipient-columns">
          <section class="recipient-group">
            <div class="recipient-group-head">
              <div class="recipient-group-title">
                <span><i class="fa fa-user-tie"></i> Mentors</span>
                <span id="mentorSelectedCount">0 selected</span>
              </div>
              <div class="recipient-tools">
                <input type="search" class="recipient-search" placeholder="Search mentors..." oninput="filterRecipientCheckboxes('mentor',this.value)">
                <button type="button" class="btn btn-sm btn-secondary" onclick="toggleRecipientCheckboxes('mentor',true)">Select visible</button>
                <button type="button" class="btn btn-sm" onclick="toggleRecipientCheckboxes('mentor',false)">Clear visible</button>
              </div>
            </div>
            <div class="recipient-list">
              <?php if (empty($mentor_options)): ?>
                <div class="recipient-empty">No active mentors found.</div>
              <?php else: ?>
                <?php foreach ($mentor_options as $mentor): ?>
                  <label class="recipient-option" data-recipient-type="mentor" data-search="<?= h(mb_strtolower($mentor['full_name'])) ?>">
                    <input type="checkbox" class="recipient-checkbox mentor-checkbox" name="recipient_mentor_ids[]" value="<?= (int)$mentor['id'] ?>" <?= in_array((int)$mentor['id'], $draft_mentor_ids, true) ? 'checked' : '' ?> onchange="updateRecipientCount()">
                    <span><?= h($mentor['full_name']) ?></span>
                  </label>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </section>

          <section class="recipient-group">
            <div class="recipient-group-head">
              <div class="recipient-group-title">
                <span><i class="fa fa-rocket"></i> Ventures</span>
                <span id="ventureSelectedCount">0 selected</span>
              </div>
              <div class="recipient-tools">
                <input type="search" class="recipient-search" placeholder="Search ventures..." oninput="filterRecipientCheckboxes('venture',this.value)">
                <button type="button" class="btn btn-sm btn-secondary" onclick="toggleRecipientCheckboxes('venture',true)">Select visible</button>
                <button type="button" class="btn btn-sm" onclick="toggleRecipientCheckboxes('venture',false)">Clear visible</button>
              </div>
            </div>
            <div class="recipient-list">
              <?php if (empty($venture_options)): ?>
                <div class="recipient-empty">No ventures found.</div>
              <?php else: ?>
                <?php foreach ($venture_options as $venture): ?>
                  <label class="recipient-option" data-recipient-type="venture" data-search="<?= h(mb_strtolower($venture['name'])) ?>">
                    <input type="checkbox" class="recipient-checkbox venture-checkbox" name="recipient_venture_ids[]" value="<?= (int)$venture['id'] ?>" <?= in_array((int)$venture['id'], $draft_venture_ids, true) ? 'checked' : '' ?> onchange="updateRecipientCount()">
                    <span><?= h($venture['name']) ?></span>
                  </label>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </section>
        </div>
      <?php elseif ($IS_MENTOR): ?>
        <label class="admin-recipient-check">
          <input type="checkbox" name="send_to_admin" id="sendToAdmin" value="1" <?= $send_admin_checked ?> onchange="updateRecipientCount()">
          <span><i class="fa fa-shield-alt"></i> Send to Programme Admin</span>
        </label>

        <section class="recipient-group legacy-style-1b0f4999d2" style="margin-top:14px">
          <div class="recipient-group-head">
            <div class="recipient-group-title">
              <span><i class="fa fa-rocket"></i> My Ventures</span>
              <span id="ventureSelectedCount">0 selected</span>
            </div>
            <div class="recipient-tools">
              <input type="search" class="recipient-search" placeholder="Search ventures..." oninput="filterRecipientCheckboxes('venture',this.value)">
              <button type="button" class="btn btn-sm btn-secondary" onclick="toggleRecipientCheckboxes('venture',true)">Select visible</button>
              <button type="button" class="btn btn-sm" onclick="toggleRecipientCheckboxes('venture',false)">Clear visible</button>
            </div>
          </div>
          <div class="recipient-list">
            <?php if (empty($venture_options)): ?>
              <div class="recipient-empty">No linked ventures found.</div>
            <?php else: ?>
              <?php foreach ($venture_options as $venture): ?>
                <label class="recipient-option" data-recipient-type="venture" data-search="<?= h(mb_strtolower($venture['name'])) ?>">
                  <input type="checkbox" class="recipient-checkbox venture-checkbox" name="recipient_venture_ids[]" value="<?= (int)$venture['id'] ?>" <?= in_array((int)$venture['id'], $draft_venture_ids, true) ? 'checked' : '' ?> onchange="updateRecipientCount()">
                  <span><?= h($venture['name']) ?></span>
                </label>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </section>
      <?php endif; ?>

      <div class="recipient-summary">
        <span><i class="fa fa-users"></i> Total recipients</span>
        <strong id="totalRecipientCount">0</strong>
      </div>

      <div class="form-grid form-grid-2 legacy-style-86de7ac63a">
        <div class="form-group full">
          <label>Subject</label>
          <input type="text" name="subject" class="form-control" maxlength="180" value="<?= h($draft_subject) ?>" placeholder="Message subject">
        </div>

        <div class="form-group full">
          <label>Message</label>
          <textarea name="body" class="form-control" rows="7" maxlength="10000" placeholder="Write your message..."><?= h($draft_body) ?></textarea>
          <div class="compose-help">A separate private conversation thread is created for every selected recipient.</div>
        </div>
      </div>
    </div>

    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeComposeModal()">Cancel</button>
      <button type="submit" name="action" value="save_draft" class="btn btn-secondary" formnovalidate>
        <i class="fa fa-save"></i> Save Draft
      </button>
      <button type="submit" name="action" value="send_bulk_message" class="btn btn-primary">
        <i class="fa fa-paper-plane"></i> Send Message
      </button>
    </div>
  </form>
</div>
</div>

<script>
const composeModal = document.getElementById('composeModal');
const composeForm = document.getElementById('composeMessageForm');

document.getElementById('sidebarToggle')?.addEventListener('click', () => {
  document.getElementById('adminSidebar')?.classList.toggle('collapsed');
  document.getElementById('adminMain')?.classList.toggle('collapsed');
});

function openComposeModal() {
  composeModal.classList.add('open');
  composeModal.setAttribute('aria-hidden', 'false');
  document.body.style.overflow = 'hidden';
  updateRecipientCount();
}

function closeComposeModal() {
  composeModal.classList.remove('open');
  composeModal.setAttribute('aria-hidden', 'true');
  document.body.style.overflow = '';
}

composeModal?.addEventListener('click', event => {
  if (event.target === composeModal) closeComposeModal();
});

document.addEventListener('keydown', event => {
  if (event.key === 'Escape' && composeModal?.classList.contains('open')) {
    closeComposeModal();
  }
});

function filterRecipientCheckboxes(type, query) {
  const q = String(query || '').trim().toLowerCase();
  document.querySelectorAll(`[data-recipient-type="${type}"]`).forEach(item => {
    item.style.display = (item.dataset.search || '').includes(q) ? 'flex' : 'none';
  });
}

function toggleRecipientCheckboxes(type, checked) {
  document.querySelectorAll(`[data-recipient-type="${type}"]`).forEach(item => {
    if (item.style.display !== 'none') {
      const checkbox = item.querySelector('input[type="checkbox"]');
      if (checkbox) checkbox.checked = checked;
    }
  });
  updateRecipientCount();
}

function updateRecipientCount() {
  const mentorCount = document.querySelectorAll('.mentor-checkbox:checked').length;
  const ventureCount = document.querySelectorAll('.venture-checkbox:checked').length;
  const adminCount = document.getElementById('sendToAdmin')?.checked ? 1 : 0;

  const mentorLabel = document.getElementById('mentorSelectedCount');
  const ventureLabel = document.getElementById('ventureSelectedCount');

  if (mentorLabel) mentorLabel.textContent = `${mentorCount} selected`;
  if (ventureLabel) ventureLabel.textContent = `${ventureCount} selected`;

  document.getElementById('totalRecipientCount').textContent =
    String(mentorCount + ventureCount + adminCount);
}

composeForm?.addEventListener('submit', event => {
  const action = (event.submitter && event.submitter.value) || 'send_bulk_message';

  if (action === 'send_bulk_message') {
    const total = document.querySelectorAll('.recipient-checkbox:checked').length
      + (document.getElementById('sendToAdmin')?.checked ? 1 : 0);

    if (total < 1) {
      event.preventDefault();
      alert('Select at least one mentor, venture, or programme admin.');
    }
  }
});

// If a draft is being edited on load, the modal is already open.
if (composeModal?.classList.contains('open')) {
  document.body.style.overflow = 'hidden';
}

updateRecipientCount();
</script>
</body>
</html>
