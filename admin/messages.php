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

$filter_mentor  = (int)($_GET['mentor_id'] ?? 0);
$filter_venture = (int)($_GET['venture_id'] ?? 0);
$active_thread  = trim((string)($_GET['thread'] ?? ''));


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

if ($active_thread !== '' && $IS_MENTOR) {
    if (!mentor_owns_thread($conn, $active_thread, $MENTOR_RECORD_ID)) {
        $active_thread = '';
    }
}


if ($active_thread !== '') {
    if ($CAN_MANAGE) {
        $stmt = $conn->prepare("
            UPDATE mentor_messages
            SET is_read = 1
            WHERE thread_id = ?
              AND recipient_type = 'admin'
        ");

        if ($stmt) {
            $stmt->bind_param('s', $active_thread);
            $stmt->execute();
            $stmt->close();
        }
    } elseif ($IS_MENTOR) {
        $stmt = $conn->prepare("
            UPDATE mentor_messages
            SET is_read = 1
            WHERE thread_id = ?
              AND recipient_type = 'mentor'
              AND recipient_id = ?
        ");

        if ($stmt) {
            $stmt->bind_param('si', $active_thread, $MENTOR_RECORD_ID);
            $stmt->execute();
            $stmt->close();
        }
    }
}

$thread_filters = [];

if ($IS_MENTOR) {
    $thread_filters[] = "
        EXISTS (
            SELECT 1
            FROM mentor_messages m_scope
            WHERE m_scope.thread_id = mm.thread_id
              AND (
                    (m_scope.sender_type = 'mentor' AND m_scope.sender_id = {$MENTOR_RECORD_ID})
                 OR (m_scope.recipient_type = 'mentor' AND m_scope.recipient_id = {$MENTOR_RECORD_ID})
              )
        )
    ";
}

if ($filter_mentor > 0) {
    $thread_filters[] = "
        EXISTS (
            SELECT 1
            FROM mentor_messages m2
            WHERE m2.thread_id = mm.thread_id
              AND (
                    (m2.sender_type = 'mentor' AND m2.sender_id = {$filter_mentor})
                 OR (m2.recipient_type = 'mentor' AND m2.recipient_id = {$filter_mentor})
              )
        )
    ";
}

if ($filter_venture > 0) {
    $thread_filters[] = "
        EXISTS (
            SELECT 1
            FROM mentor_messages m2
            WHERE m2.thread_id = mm.thread_id
              AND (
                    (m2.sender_type = 'venture' AND m2.sender_id = {$filter_venture})
                 OR (m2.recipient_type = 'venture' AND m2.recipient_id = {$filter_venture})
              )
        )
    ";
}

$thread_where_sql = '';

if (!empty($thread_filters)) {
    $thread_where_sql = 'WHERE ' . implode(' AND ', $thread_filters);
}


$threads = $conn->query("
    SELECT
        mm.thread_id,
        MAX(mm.created_at) AS last_at,

        SUM(
            CASE
                WHEN mm.is_read = 0
                 AND mm.recipient_type = 'admin'
                THEN 1
                ELSE 0
            END
        ) AS unread_count_admin,

        SUM(
            CASE
                WHEN mm.is_read = 0
                 AND mm.recipient_type = 'mentor'
                 AND mm.recipient_id = {$MENTOR_RECORD_ID}
                THEN 1
                ELSE 0
            END
        ) AS unread_count_mentor,

        (
            SELECT m_subject.subject
            FROM mentor_messages m_subject
            WHERE m_subject.thread_id = mm.thread_id
            ORDER BY m_subject.created_at ASC, m_subject.id ASC
            LIMIT 1
        ) AS subject,

        (
            SELECT m_body.body
            FROM mentor_messages m_body
            WHERE m_body.thread_id = mm.thread_id
            ORDER BY m_body.created_at DESC, m_body.id DESC
            LIMIT 1
        ) AS last_body,

        (
            SELECT m_sender.sender_type
            FROM mentor_messages m_sender
            WHERE m_sender.thread_id = mm.thread_id
            ORDER BY m_sender.created_at DESC, m_sender.id DESC
            LIMIT 1
        ) AS last_sender_type,

        (
            SELECT m_sender_id.sender_id
            FROM mentor_messages m_sender_id
            WHERE m_sender_id.thread_id = mm.thread_id
            ORDER BY m_sender_id.created_at DESC, m_sender_id.id DESC
            LIMIT 1
        ) AS last_sender_id

    FROM mentor_messages mm

    {$thread_where_sql}

    GROUP BY mm.thread_id

    ORDER BY last_at DESC
");

if (!$threads) {
    die('Thread query failed: ' . h($conn->error));
}

/*
|--------------------------------------------------------------------------
| Open thread messages
|--------------------------------------------------------------------------
*/
$thread_messages = null;
$thread_subject  = '';

if ($active_thread !== '') {
    $tm_stmt = $conn->prepare("
        SELECT *
        FROM mentor_messages
        WHERE thread_id = ?
        ORDER BY created_at ASC, id ASC
    ");

    if ($tm_stmt) {
        $tm_stmt->bind_param('s', $active_thread);
        $tm_stmt->execute();
        $thread_messages = $tm_stmt->get_result();
        $tm_stmt->close();
    }

    $sub_stmt = $conn->prepare("
        SELECT subject
        FROM mentor_messages
        WHERE thread_id = ?
        ORDER BY created_at ASC, id ASC
        LIMIT 1
    ");

    if ($sub_stmt) {
        $sub_stmt->bind_param('s', $active_thread);
        $sub_stmt->execute();
        $thread_subject = (string)($sub_stmt->get_result()->fetch_row()[0] ?? '');
        $sub_stmt->close();
    }
}


$total_unread = 0;

if ($CAN_MANAGE) {
    $unread_rs = $conn->query("
        SELECT COUNT(*) AS total
        FROM mentor_messages
        WHERE recipient_type = 'admin'
          AND is_read = 0
    ");

    if ($unread_rs) {
        $total_unread = (int)($unread_rs->fetch_assoc()['total'] ?? 0);
    }
} elseif ($IS_MENTOR) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM mentor_messages
        WHERE recipient_type = 'mentor'
          AND recipient_id = ?
          AND is_read = 0
    ");

    if ($stmt) {
        $stmt->bind_param('i', $MENTOR_RECORD_ID);
        $stmt->execute();
        $total_unread = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
    }
}


function sender_name(mysqli $conn, string $type, int $id): string
{
    $type = strtolower(trim($type));

    if ($type === 'mentor') {
        $stmt = $conn->prepare("
            SELECT full_name
            FROM mentors
            WHERE id = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $name = $stmt->get_result()->fetch_row()[0] ?? '';
            $stmt->close();

            return $name !== '' ? (string)$name : 'Mentor';
        }

        return 'Mentor';
    }

    if ($type === 'venture') {
        $stmt = $conn->prepare("
            SELECT name
            FROM ventures
            WHERE id = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $name = $stmt->get_result()->fetch_row()[0] ?? '';
            $stmt->close();

            return $name !== '' ? (string)$name : 'Venture';
        }

        return 'Venture';
    }

    return 'Admin';
}


$mentor_options = [];
$venture_options = [];

if ($CAN_MANAGE) {
    $mentor_result = $conn->query("
        SELECT id, full_name
        FROM mentors
        WHERE status = 'active'
        ORDER BY full_name ASC
    ");

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
            SELECT mm_v.sender_id
            FROM mentor_messages mm_v
            WHERE mm_v.sender_type = 'venture'
              AND mm_v.thread_id IN (
                  SELECT thread_id
                  FROM mentor_messages
                  WHERE (sender_type = 'mentor' AND sender_id = {$MENTOR_RECORD_ID})
                     OR (recipient_type = 'mentor' AND recipient_id = {$MENTOR_RECORD_ID})
              )
        )
        OR v.id IN (
            SELECT mm_v.recipient_id
            FROM mentor_messages mm_v
            WHERE mm_v.recipient_type = 'venture'
              AND mm_v.thread_id IN (
                  SELECT thread_id
                  FROM mentor_messages
                  WHERE (sender_type = 'mentor' AND sender_id = {$MENTOR_RECORD_ID})
                     OR (recipient_type = 'mentor' AND recipient_id = {$MENTOR_RECORD_ID})
              )
        )
        ORDER BY v.name ASC
    ");
} else {
    $venture_result = $conn->query("
        SELECT id, name
        FROM ventures
        ORDER BY name ASC
    ");
}

if ($venture_result) {
    $venture_options = $venture_result->fetch_all(MYSQLI_ASSOC);
}

$site_name = function_exists('get_setting')
    ? get_setting($conn, 'site_name', 'EdTech Fellowship')
    : 'EdTech Fellowship';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Mentor Messages - <?= h($site_name) ?> Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="assets/css/admin.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/admin.css') ?>">
  
</head>
<body class="admin-system-page">
<?php include 'includes/sidebar.php'; ?>
<div class="admin-main" id="adminMain">
<header class="admin-topbar">
  <div class="topbar-left"><div class="topbar-breadcrumb"><a href="index.php">Dashboard</a> &rsaquo; <?php if($IS_PRIVILEGED): ?><a href="mentors.php">Mentors</a> &rsaquo; <?php endif; ?> <strong>Messages</strong></div></div>
  <div class="topbar-right">
    <button class="btn btn-primary btn-sm" onclick="openComposeModal()"><i class="fa fa-pen"></i> Compose</button>
    <div class="admin-avatar"><div class="avatar-circle"><?= strtoupper(substr($ADMIN['full_name'] ?? 'A',0,1)) ?></div></div>
  </div>
</header>
<div class="admin-content">
<?php show_flash('mmsg'); ?>
<div class="page-header">
  <div>
    <h1 class="page-title">Mentor Messages <?php if($total_unread): ?><span class="badge badge-danger legacy-style-5e0faad207"><?= $total_unread ?> unread</span><?php endif; ?></h1>
    <p class="page-subtitle"><?= $CAN_MANAGE ? 'Threaded conversations between mentors, ventures & programme admin' : 'Your conversations with the programme team and your ventures' ?></p>
  </div>
</div>



<!-- Filters -->
<div class="filter-bar">
  <form method="GET" class="legacy-style-ae125572d5">
    <?php if ($CAN_MANAGE): ?>
    <select name="mentor_id" onchange="this.form.submit()">
      <option value="">All Mentors</option>
      <?php foreach ($mentor_options as $r): ?>
        <option value="<?= (int)$r['id'] ?>" <?= $filter_mentor === (int)$r['id'] ? 'selected' : '' ?>><?= h($r['full_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select name="venture_id" onchange="this.form.submit()">
      <option value=""><?= $CAN_MANAGE ? 'All Ventures' : 'All my ventures' ?></option>
      <?php foreach ($venture_options as $r): ?>
        <option value="<?= (int)$r['id'] ?>" <?= $filter_venture === (int)$r['id'] ? 'selected' : '' ?>><?= h($r['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if($active_thread!=='') echo '<input type="hidden" name="thread" value="'.h($active_thread).'">'; ?>
    <?php if($filter_mentor||$filter_venture): ?>
      <a href="mentor-messages.php" class="btn btn-sm legacy-style-2af7847e65"><i class="fa fa-times"></i> Clear</a>
    <?php endif; ?>
  </form>
</div>

<div class="msg-layout">
  <!-- Thread list -->
  <div class="thread-list">
    <div class="thread-list-header">
      <strong class="legacy-style-5e0faad207">Conversations</strong>
      <button class="btn btn-sm btn-primary" onclick="openComposeModal()"><i class="fa fa-pen"></i></button>
    </div>
    <?php $has_threads=false; while($t=$threads->fetch_assoc()): $has_threads=true;
      $unread_count = $CAN_MANAGE ? (int)$t['unread_count_admin'] : (int)$t['unread_count_mentor'];
      $is_unread=$unread_count>0;
      $is_active=$t['thread_id']===$active_thread;
      $sender_label=ucfirst($t['last_sender_type']);
    ?>
    <a href="?thread=<?= urlencode($t['thread_id']) ?>&mentor_id=<?= $filter_mentor ?>&venture_id=<?= $filter_venture ?>"
       class="thread-item <?= $is_active?'active':'' ?> <?= $is_unread?'unread':'' ?>">
      <div class="thread-meta">
        <span class="legacy-style-919ae42573"><?= $sender_label ?></span>
        <div class="legacy-style-b88d1817be">
          <span class="thread-time"><?= date('M j', strtotime($t['last_at'])) ?></span>
          <?php if($is_unread): ?><span class="unread-dot"></span><?php endif; ?>
        </div>
      </div>
      <div class="thread-subj"><?= h($t['subject'] ?? '(no subject)') ?></div>
      <div class="thread-preview"><?= h(mb_strimwidth($t['last_body'],0,70,'...')) ?></div>
    </a>
    <?php endwhile; ?>
    <?php if(!$has_threads): ?>
      <div class="legacy-style-7897c2bf7f">
        <i class="fa fa-inbox legacy-style-e11fd67724"></i>
        <?= $CAN_MANAGE ? 'No conversations yet.' : 'You have no conversations yet.' ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Chat area -->
  <?php if($active_thread!==''&&$thread_messages): ?>
  <div class="chat-area">
    <div class="chat-header">
      <div class="chat-header-title"><?= h($thread_subject ?: '(no subject)') ?></div>
    </div>
    <div class="chat-messages" id="chatMessages">
      <?php while($msg=$thread_messages->fetch_assoc()):
      
        if ($CAN_MANAGE) {
            $is_self = $msg['sender_type'] === 'admin';
        } elseif ($IS_MENTOR) {
            $is_self = $msg['sender_type'] === 'mentor' && (int)$msg['sender_id'] === $MENTOR_RECORD_ID;
        } else {
            $is_self = false;
        }
        $sender_name=sender_name($conn,$msg['sender_type'],(int)$msg['sender_id']);
      ?>
      <div class="msg-bubble-wrap <?= $is_self?'from-self':'' ?>">
        <div class="msg-avatar <?= $is_self?'self-av':'' ?>"><?= strtoupper(substr($sender_name,0,2)) ?></div>
        <div>
          <div class="msg-bubble <?= $is_self?'from-self':'from-other' ?>">
            <?= nl2br(h($msg['body'])) ?>
          </div>
          <span class="msg-time"><?= h($sender_name) ?> &middot; <?= date('M j, g:ia',strtotime($msg['created_at'])) ?></span>
        </div>
      </div>
      <?php endwhile; ?>
    </div>
    <div class="chat-composer">
      <form method="POST" action="includes/process-mentors.php">
        <input type="hidden" name="action" value="reply_message">
        <input type="hidden" name="thread_id" value="<?= h($active_thread) ?>">
        <?php if ($IS_MENTOR): ?>
         
          <input type="hidden" name="reply_as" value="mentor">
          <input type="hidden" name="reply_as_mentor_id" value="<?= (int)$MENTOR_RECORD_ID ?>">
        <?php else: ?>
          <input type="hidden" name="reply_as" value="admin">
        <?php endif; ?>
        <div class="composer-row">
          <textarea name="body" class="composer-input" rows="2" placeholder="Write a reply..." required></textarea>
          <button type="submit" class="btn btn-primary"><i class="fa fa-paper-plane"></i></button>
        </div>
      </form>
    </div>
  </div>
  <?php else: ?>
  <div class="no-thread-selected">
    <i class="fa fa-comments legacy-style-6b2afd967b"></i>
    <p>Select a conversation to read it,<br>or compose a new message.</p>
    <button class="btn btn-primary" onclick="openComposeModal()"><i class="fa fa-pen"></i> Compose</button>
  </div>
  <?php endif; ?>
</div><!-- /msg-layout -->
</div>
</div>

<!-- ---------- COMPOSE MODAL ---------- -->
<div class="modal-overlay" id="composeModal" aria-hidden="true">
<div class="modal compose-modal" role="dialog" aria-modal="true" aria-labelledby="composeTitle">
  <div class="modal-header">
    <div>
      <h2 class="modal-title" id="composeTitle">New Message</h2>
      <div class="compose-help">Select one or more recipients using the checkboxes below.</div>
    </div>
    <button type="button" class="modal-close" onclick="closeComposeModal()" aria-label="Close">&times;</button>
  </div>

  <form method="POST" action="includes/process-mentors.php" id="composeMessageForm">
    <input type="hidden" name="action" value="send_bulk_message">

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
                    <input type="checkbox" class="recipient-checkbox mentor-checkbox" name="recipient_mentor_ids[]" value="<?= (int)$mentor['id'] ?>" onchange="updateRecipientCount()">
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
                    <input type="checkbox" class="recipient-checkbox venture-checkbox" name="recipient_venture_ids[]" value="<?= (int)$venture['id'] ?>" onchange="updateRecipientCount()">
                    <span><?= h($venture['name']) ?></span>
                  </label>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </section>
        </div>
      <?php elseif ($IS_MENTOR): ?>
        <label class="admin-recipient-check">
          <input type="checkbox" name="send_to_admin" id="sendToAdmin" value="1" checked onchange="updateRecipientCount()">
          <span><i class="fa fa-shield-alt"></i> Send to Programme Admin</span>
        </label>

        <section class="recipient-group legacy-style-1b0f4999d2">
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
                  <input type="checkbox" class="recipient-checkbox venture-checkbox" name="recipient_venture_ids[]" value="<?= (int)$venture['id'] ?>" onchange="updateRecipientCount()">
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
          <label>Subject <span class="req">*</span></label>
          <input type="text" name="subject" class="form-control" maxlength="180" required>
        </div>

        <div class="form-group full">
          <label>Message <span class="req">*</span></label>
          <textarea name="body" class="form-control" rows="7" maxlength="10000" required placeholder="Write your message..."></textarea>
          <div class="compose-help">A separate private conversation thread is created for every selected recipient.</div>
        </div>
      </div>
    </div>

    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeComposeModal()">Cancel</button>
      <button type="submit" class="btn btn-primary">
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
  const total = document.querySelectorAll('.recipient-checkbox:checked').length
    + (document.getElementById('sendToAdmin')?.checked ? 1 : 0);

  if (total < 1) {
    event.preventDefault();
    alert('Select at least one mentor, venture, or programme admin.');
  }
});

const chat = document.getElementById('chatMessages');
if (chat) chat.scrollTop = chat.scrollHeight;
</script>
</body>
</html>
