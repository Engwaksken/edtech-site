<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page_title  = 'Sessions';
$current_nav = 'sessions.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection error.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}


$venture_id = (int)($venture_id ?? ($_SESSION['venture_id'] ?? 0));

if ($venture_id <= 0) {
    header('Location: login.php');
    exit;
}

$venture_stmt = $conn->prepare("SELECT * FROM ventures WHERE id = ? LIMIT 1");
$venture_stmt->bind_param('i', $venture_id);
$venture_stmt->execute();
$VENTURE = $venture_stmt->get_result()->fetch_assoc() ?: [];
$venture_stmt->close();

$filter = $_GET['status'] ?? 'upcoming';

$allowed_filters = ['upcoming', 'completed', 'cancelled', 'all'];
if (!in_array($filter, $allowed_filters, true)) {
    $filter = 'upcoming';
}

$status_groups = [
    'upcoming'  => ['requested', 'scheduled', 'confirmed'],
    'completed' => ['completed'],
    'cancelled' => ['cancelled', 'no_show'],
    'all'       => ['requested', 'scheduled', 'confirmed', 'completed', 'cancelled', 'no_show'],
];

$selected_statuses = $status_groups[$filter];
$status_placeholders = implode(',', array_fill(0, count($selected_statuses), '?'));

$status_labels = [
    'requested' => 'Requested',
    'scheduled' => 'Scheduled',
    'confirmed' => 'Confirmed',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'no_show'   => 'No Show',
];

$status_badges = [
    'requested' => 'badge-warning',
    'scheduled' => 'badge-muted',
    'confirmed' => 'badge-gold',
    'completed' => 'badge-success',
    'cancelled' => 'badge-danger',
    'no_show'   => 'badge-danger',
];

$type_labels = [
    'one_on_one' => '1-on-1',
    'group'      => 'Group',
    'workshop'   => 'Workshop',
    'review'     => 'Review',
    'ad_hoc'     => 'Ad Hoc',
];

$platform_icons = [
    'zoom'        => 'fa-video',
    'google_meet' => 'fa-video',
    'teams'       => 'fa-video',
    'phone'       => 'fa-phone',
    'in_person'   => 'fa-map-marker-alt',
    'other'       => 'fa-link',
];


$order_dir = $filter === 'completed' ? 'DESC' : 'ASC';

$sql = "
    SELECT ms.*,
           m.full_name AS mentor_name,
           m.photo AS mentor_photo,
           m.job_title AS mentor_title,
           m.organisation AS mentor_org
    FROM mentor_sessions ms
    LEFT JOIN session_mentors smo
           ON smo.session_id = ms.id
          AND smo.role = 'organizer'
    LEFT JOIN mentors m
           ON m.id = COALESCE(smo.mentor_id, ms.mentor_id)
    WHERE EXISTS (
            SELECT 1
            FROM session_ventures sv
            WHERE sv.session_id = ms.id
              AND sv.venture_id = ?
          )
      AND ms.status IN ($status_placeholders)
    ORDER BY
      CASE WHEN ms.scheduled_at IS NULL THEN 1 ELSE 0 END,
      ms.scheduled_at $order_dir,
      ms.id DESC
";

$sessions_stmt = $conn->prepare($sql);

$types = 'i' . str_repeat('s', count($selected_statuses));
$params = array_merge([$venture_id], $selected_statuses);
$sessions_stmt->bind_param($types, ...$params);
$sessions_stmt->execute();
$sessions_rs = $sessions_stmt->get_result();


$session_rows = [];
while ($row = $sessions_rs->fetch_assoc()) {
    $session_rows[] = $row;
}
$sessions_stmt->close();


$comments_by_session = [];
if ($session_rows && $conn->query("SHOW TABLES LIKE 'session_comments'")->num_rows) {
    $ids = implode(',', array_map(fn($s) => (int)$s['id'], $session_rows));
    $cres = $conn->query("
        SELECT * , COALESCE(author_name,'Team') AS display_name
        FROM session_comments
        WHERE session_id IN ($ids)
        ORDER BY created_at ASC
    ");
    while ($r = $cres->fetch_assoc()) {
        $comments_by_session[(int)$r['session_id']][] = $r;
    }
}

// --------------------------------------------------------------
//  STRUCTURED SESSION REPORTS (structured template, one per session)
// --------------------------------------------------------------
$feedback_by_session = [];
if ($session_rows && $conn->query("SHOW TABLES LIKE 'session_feedback_reports'")->num_rows) {
    $ids = implode(',', array_map(fn($s) => (int)$s['id'], $session_rows));
    $fres = $conn->query("SELECT * FROM session_feedback_reports WHERE session_id IN ($ids)");
    while ($r = $fres->fetch_assoc()) {
        $feedback_by_session[(int)$r['session_id']] = $r;
    }
}

// Option lists used by the feedback report template (kept identical to the
// paper "Venture Mentorship Session Report" template).
$fb_mode_options        = ['physical' => 'Physical', 'virtual' => 'Virtual', 'hybrid' => 'Hybrid'];
$fb_helpful_options     = ['5' => '5 - Extremely Helpful', '4' => '4 - Very Helpful', '3' => '3 - Helpful', '2' => '2 - Slightly Helpful', '1' => '1 - Not Helpful'];
$fb_rating_options      = ['5' => '5', '4' => '4', '3' => '3', '2' => '2', '1' => '1'];
$fb_value_options       = ['extremely_valuable' => 'Extremely Valuable', 'very_valuable' => 'Very Valuable', 'moderately_valuable' => 'Moderately Valuable', 'slightly_valuable' => 'Slightly Valuable', 'not_valuable' => 'Not Valuable'];
$fb_confidence_options  = ['very_confident' => 'Very Confident', 'confident' => 'Confident', 'somewhat_confident' => 'Somewhat Confident', 'not_confident' => 'Not Confident'];
$fb_progress_options    = ['significant' => 'Significant Progress', 'moderate' => 'Moderate Progress', 'slight' => 'Slight Progress', 'none' => 'No Progress Yet'];
$fb_urgency_options     = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
$fb_recommend_options   = ['definitely' => 'Definitely', 'probably' => 'Probably', 'not_sure' => 'Not Sure', 'probably_not' => 'Probably Not', 'definitely_not' => 'Definitely Not'];
$fb_status_labels       = ['draft' => 'Draft', 'submitted' => 'Submitted for Review', 'reviewed' => 'Reviewed'];
$fb_status_badges       = ['draft' => 'badge-muted', 'submitted' => 'badge-warning', 'reviewed' => 'badge-success'];

if (!function_exists('fb_select')) {
    function fb_select(string $name, array $options, string $selected, bool $disabled = false, string $placeholder = 'Select...'): string {
        $out  = '<select name="' . h($name) . '" class="form-control"' . ($disabled ? ' disabled' : '') . '>';
        $out .= '<option value="">' . h($placeholder) . '</option>';
        foreach ($options as $val => $label) {
            $sel = ($selected === (string)$val) ? ' selected' : '';
            $out .= '<option value="' . h((string)$val) . '"' . $sel . '>' . h($label) . '</option>';
        }
        $out .= '</select>';
        return $out;
    }
}

if (!function_exists('fb_json_rows')) {
    function fb_json_rows(?string $json): array {
        if (!$json) return [];
        $rows = json_decode($json, true);
        return is_array($rows) ? $rows : [];
    }
}



$stats_stmt = $conn->prepare("
    SELECT
        COALESCE(SUM(status IN('requested','scheduled','confirmed')), 0) AS upcoming,
        COALESCE(SUM(status = 'completed'), 0) AS completed,
        COALESCE(SUM(status IN('cancelled','no_show')), 0) AS cancelled,
        COALESCE(ROUND(AVG(CASE WHEN status = 'completed' THEN venture_rating END), 1), 0) AS avg_rating
    FROM mentor_sessions ms
    WHERE EXISTS (
            SELECT 1
            FROM session_ventures sv
            WHERE sv.session_id = ms.id
              AND sv.venture_id = ?
          )
");
$stats_stmt->bind_param('i', $venture_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc() ?: [
    'upcoming' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'avg_rating' => 0,
];
$stats_stmt->close();

include __DIR__ . '/layout.php';
?>

<style>
  
  .comment-thread{max-height:220px;overflow-y:auto;margin:10px 0;padding-right:2px}
  .comment-bubble{margin-bottom:8px}
  .comment-bubble.admin .cb-inner,.comment-bubble.mentor .cb-inner{background:#f3f0ff;border-radius:10px 10px 10px 2px}
  .comment-bubble.venture .cb-inner,.comment-bubble.founder .cb-inner{background:#fff7e6;border-radius:10px 10px 2px 10px;margin-left:18px}
  .cb-inner{padding:9px 12px}
  .cb-author{font-size:10.5px;font-weight:700;color:var(--muted,#7f8c8d);margin-bottom:2px}
  .cb-text{font-size:13px;line-height:1.5;color:var(--ink,#2c3e50)}
  .cb-time{font-size:10.5px;color:var(--muted,#7f8c8d);margin-top:3px}
  .comment-compose{display:flex;gap:8px;align-items:flex-end;margin-top:8px}
  .comment-compose textarea{flex:1;padding:8px 11px;border:1.5px solid #e1e4e8;border-radius:8px;font-size:13px;font-family:inherit;resize:none;min-height:42px}

  /* Star pickers */
  .star-picker-row{display:flex;gap:6px;cursor:pointer;margin:8px 0}
  .star-picker-row i{font-size:26px;color:#d8dadd;transition:color .12s}
  .star-picker-row i.lit{color:#f0a500}


  /* Session card extra action buttons */
  .session-extra-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
  .session-extra-actions .btn{font-size:12px}

 
  .session-expand{display:none;margin-top:12px;padding-top:12px;border-top:1px dashed #e1e4e8}
  .session-expand.open{display:block}
  .session-expand-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted,#7f8c8d);margin-bottom:8px;display:flex;align-items:center;gap:6px}

 
  .modal-box{max-width:480px}
  .modal-box-body label.form-label{display:block;margin-bottom:6px;font-weight:600;font-size:13px;color:#34495e}

  /* Session report modal - same popup pattern as Rate Session / Rate Mentor */
  .modal-overlay[id^="fbModal-"]{
    animation:none!important;
    transition:none!important;
    transform:none!important;
    overscroll-behavior:contain;
  }
  .modal-overlay[id^="fbModal-"].open{animation:none!important}
  .fb-report-modal-box{
    width:min(980px,calc(100vw - 40px));
    max-width:980px;
    height:86vh;
    max-height:86vh;
    min-height:0;
    display:flex;
    flex-direction:column;
    overflow:hidden;
    animation:none!important;
    transition:none!important;
    transform:none!important;
    will-change:auto!important;
  }
  .fb-report-modal-box .modal-box-header{
    flex:0 0 auto;
    border-bottom:1px solid #e8edf3;
  }
  .fb-report-modal-body{
    flex:1 1 auto;
    min-height:0;
    height:100%;
    overflow-y:scroll;
    overflow-x:hidden;
    overscroll-behavior:contain;
    scrollbar-gutter:stable;
    -webkit-overflow-scrolling:touch;
    padding:18px 20px 22px;
    background:#fbfcfd;
  }
  .fb-report-subtitle{margin:4px 0 0;color:#7f8c8d;font-size:12px;line-height:1.45}
  .fb-section{margin-bottom:16px;padding:15px;border:1px solid #e7ebf0;border-radius:11px;background:#fff}
  .fb-section-title{font-size:13px;font-weight:700;color:#2c3e50;margin-bottom:10px;display:flex;align-items:center;gap:6px}
  .fb-section-title .fb-num{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:#fc7f10;color:#fff;font-size:11px;font-weight:700}
  .fb-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .fb-grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
  .fb-field{min-width:0}
  .fb-field label{display:block;font-size:11.5px;font-weight:600;color:#7f8c8d;margin-bottom:4px}
  .fb-field textarea,.fb-field input,.fb-field select{width:100%;max-width:100%;box-sizing:border-box;padding:8px 10px;border:1.5px solid #e1e4e8;border-radius:7px;font-size:13px;font-family:inherit}
  .fb-table-wrap{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
  .fb-repeat-table{width:100%;min-width:680px;border-collapse:collapse;margin-bottom:6px}
  .fb-repeat-table th{font-size:10.5px;text-transform:uppercase;letter-spacing:.03em;color:#7f8c8d;text-align:left;padding:6px;border-bottom:1px solid #eef0f2;white-space:nowrap}
  .fb-repeat-table td{padding:5px 6px;vertical-align:top;border-bottom:1px solid #f2f3f5}
  .fb-repeat-table input,.fb-repeat-table select,.fb-repeat-table textarea{width:100%;max-width:100%;box-sizing:border-box;padding:6px 8px;border:1.5px solid #e1e4e8;border-radius:6px;font-size:12.5px;font-family:inherit}
  .fb-repeat-remove{background:none;border:none;color:#e74c3c;cursor:pointer;font-size:14px;padding:4px}
  .fb-add-row{font-size:11.5px;color:#fc7f10;background:none;border:1px dashed #fc7f10;border-radius:6px;padding:5px 10px;cursor:pointer;margin-top:2px}
  .fb-radio-group{display:flex;flex-wrap:wrap;gap:10px;margin:6px 0}
  .fb-radio-group label{display:flex;align-items:center;gap:5px;font-size:12.5px;font-weight:500;color:#34495e;cursor:pointer}
  .fb-readonly-note{font-size:12px;color:#7f8c8d;background:#f7f8fa;border-radius:8px;padding:10px 12px;margin-bottom:12px}
  .fb-actions{
    position:static;
    z-index:auto;
    display:flex;
    gap:8px;
    justify-content:flex-end;
    margin:18px 0 0;
    padding:14px 0 0;
    border-top:1px solid #e7ebf0;
    background:#fff;
  }
  .fb-view-value{font-size:13px;color:#2c3e50;background:#f7f8fa;border-radius:7px;padding:8px 10px;min-height:20px;white-space:pre-wrap}
  @media(max-width:760px){
    .fb-report-modal-box{
      width:calc(100vw - 20px);
      max-width:calc(100vw - 20px);
      height:calc(100dvh - 20px);
      max-height:calc(100dvh - 20px);
    }
    .fb-report-modal-body{
      height:100%;
      overflow-y:scroll;
      padding:14px;
      scrollbar-gutter:auto;
    }
    .fb-section{padding:12px}
    .fb-grid-2,.fb-grid-3{grid-template-columns:1fr}
    .fb-repeat-table{min-width:620px}
    .fb-actions{
      position:static;
      margin:16px 0 0;
      padding:12px 0 0;
      flex-direction:column-reverse;
    }
    .fb-actions .btn{width:100%;justify-content:center}
  }

  .modal-overlay[id^="fbModal-"] *,
  .modal-overlay[id^="fbModal-"] *::before,
  .modal-overlay[id^="fbModal-"] *::after{
    transform:none!important;
  }
  .modal-overlay[id^="fbModal-"] input,
  .modal-overlay[id^="fbModal-"] textarea,
  .modal-overlay[id^="fbModal-"] select,
  .modal-overlay[id^="fbModal-"] button{
    animation:none!important;
  }


  /* Custom session-report confirmation popup */
  #reportConfirmModal .modal-box{
    animation:none!important;
    transform:none!important;
  }

  #reportConfirmModal .modal-box-body{
    padding:22px;
  }

  #reportConfirmModal .modal-box-footer{
    gap:10px;
  }

  @media(max-width:640px){
    #reportConfirmModal{
      padding:14px!important;
    }

    #reportConfirmModal .modal-box{
      width:100%!important;
      max-width:100%!important;
    }

    #reportConfirmModal .modal-box-footer{
      display:flex;
      flex-direction:column-reverse;
    }

    #reportConfirmModal .modal-box-footer .btn{
      width:100%;
      justify-content:center;
    }
  }


  /* Clickable statistics cards */
  .sessions-stat-link{
    display:block;
    min-width:0;
    color:inherit;
    text-decoration:none!important;
    border-radius:inherit;
  }

  .sessions-stat-link .sessions-stat-tile{
    height:100%;
    cursor:pointer;
    transition:
      transform .18s ease,
      box-shadow .18s ease,
      border-color .18s ease;
  }

  .sessions-stat-link:hover .sessions-stat-tile,
  .sessions-stat-link:focus-visible .sessions-stat-tile{
    transform:translateY(-2px);
    box-shadow:0 10px 26px rgba(15,23,42,.10);
  }

  .sessions-stat-link:focus-visible{
    outline:3px solid rgba(252,127,16,.22);
    outline-offset:3px;
  }

  .sessions-stat-link.active .sessions-stat-tile{
    box-shadow:0 0 0 2px rgba(252,127,16,.18);
  }

</style>

<div class="sessions-page">

  <div class="sessions-header">
    <div>
      <h2 class="sessions-title">Mentoring Sessions</h2>
      <p class="sessions-subtitle">Your session history and upcoming bookings. Contact the programme team if a session needs to be changed or cancelled.</p>
    </div>

    <a href="mentors.php" class="btn btn-primary">
      <i class="fa fa-calendar-plus"></i> Request Session
    </a>
  </div>

  <div class="sessions-stats-grid">
    <a
      href="?status=upcoming"
      class="sessions-stat-link <?= $filter === 'upcoming' ? 'active' : '' ?>"
      aria-label="View upcoming sessions"
    >
      <div class="sessions-stat-tile sessions-stat-upcoming">
        <div class="sessions-stat-num"><?= (int)$stats['upcoming'] ?></div>
        <div class="sessions-stat-label">Upcoming</div>
      </div>
    </a>

    <a
      href="?status=completed"
      class="sessions-stat-link <?= $filter === 'completed' ? 'active' : '' ?>"
      aria-label="View completed sessions"
    >
      <div class="sessions-stat-tile sessions-stat-completed">
        <div class="sessions-stat-num"><?= (int)$stats['completed'] ?></div>
        <div class="sessions-stat-label">Completed</div>
      </div>
    </a>

    <a
      href="?status=cancelled"
      class="sessions-stat-link <?= $filter === 'cancelled' ? 'active' : '' ?>"
      aria-label="View cancelled sessions"
    >
      <div class="sessions-stat-tile sessions-stat-cancelled">
        <div class="sessions-stat-num"><?= (int)$stats['cancelled'] ?></div>
        <div class="sessions-stat-label">Cancelled</div>
      </div>
    </a>

    <?php if ((float)$stats['avg_rating'] > 0): ?>
      <a
        href="?status=completed"
        class="sessions-stat-link"
        aria-label="View completed sessions and ratings"
      >
        <div class="sessions-stat-tile sessions-stat-rating">
          <div class="sessions-stat-num">
            <?= h($stats['avg_rating']) ?><span>/5</span>
          </div>
          <div class="sessions-stat-label">Avg Rating</div>
        </div>
      </a>
    <?php endif; ?>
  </div>

  <div class="sessions-tabs">
    <a href="?status=upcoming" class="sessions-tab <?= $filter === 'upcoming' ? 'active' : '' ?>">
      <i class="fa fa-clock"></i> Upcoming
    </a>
    <a href="?status=completed" class="sessions-tab <?= $filter === 'completed' ? 'active' : '' ?>">
      <i class="fa fa-check-circle"></i> Completed
    </a>
    <a href="?status=cancelled" class="sessions-tab <?= $filter === 'cancelled' ? 'active' : '' ?>">
      <i class="fa fa-times-circle"></i> Cancelled
    </a>
    <a href="?status=all" class="sessions-tab <?= $filter === 'all' ? 'active' : '' ?>">
      <i class="fa fa-list"></i> All
    </a>
  </div>

  <?php $has_sessions = false; ?>

  <div class="sessions-grid">
    <?php foreach ($session_rows as $s): ?>
      <?php
        $has_sessions = true;

        $sid = (int)$s['id'];
        $dt = !empty($s['scheduled_at']) ? new DateTime((string)$s['scheduled_at']) : null;

        $status = (string)($s['status'] ?? '');
        $is_upcoming = in_array($status, ['requested', 'scheduled', 'confirmed'], true);
        $is_done = $status === 'completed';
        $is_cancelled = in_array($status, ['cancelled', 'no_show'], true);

        $box_class = $is_done ? 'done' : ($is_upcoming ? 'pending' : 'dead');

        $mentor_name = (string)($s['mentor_name'] ?? 'Mentor');
        $mentor_initials = strtoupper(substr(trim($mentor_name), 0, 2));

        $meeting_platform = (string)($s['meeting_platform'] ?? 'other');
        $session_type = (string)($s['session_type'] ?? '');
        $rating = (int)($s['venture_rating'] ?? 0);
        $mentor_rating_given = (int)($s['mentor_rated_by_venture'] ?? 0);

        $cmt_count = count($comments_by_session[$sid] ?? []);

        $fb = $feedback_by_session[$sid] ?? null;
        $fb_status = (string)($fb['status'] ?? '');
        $fb_locked = in_array($fb_status, ['submitted', 'reviewed'], true);
      ?>

      <article class="session-grid-card">
        <div class="session-card-top">
          <div class="session-date-box <?= h($box_class) ?>">
            <span class="session-date-day"><?= $dt ? h($dt->format('j')) : '?' ?></span>
            <span class="session-date-month"><?= $dt ? h($dt->format('M')) : 'TBD' ?></span>
          </div>

          <div class="session-card-main">
            <div class="session-mentor-row">
              <?php if (!empty($s['mentor_photo'])): ?>
                <img src="<?= SITE_URL . '/' . h($s['mentor_photo']) ?>" class="session-mentor-photo" alt="<?= h($mentor_name) ?>">
              <?php else: ?>
                <div class="session-mentor-initials"><?= h($mentor_initials) ?></div>
              <?php endif; ?>

              <div class="session-mentor-text">
                <span class="session-mentor-name"><?= h($mentor_name) ?></span>
                <?php if (!empty($s['mentor_org'])): ?>
                  <span class="session-mentor-org"><?= h($s['mentor_org']) ?></span>
                <?php endif; ?>
              </div>
            </div>

            <h3 class="session-card-title"><?= h($s['title'] ?? 'Untitled Session') ?></h3>

            <div class="session-meta">
              <span>
                <i class="fa fa-clock"></i>
                <?= $dt ? h($dt->format('g:i A')) : 'Time TBC' ?>
              </span>

              <span>
                <i class="fa fa-hourglass-half"></i>
                <?= (int)($s['duration_minutes'] ?? 0) ?>min
              </span>

              <span>
                <i class="fa <?= h($platform_icons[$meeting_platform] ?? 'fa-video') ?>"></i>
                <?= h(ucfirst(str_replace('_', ' ', $meeting_platform))) ?>
              </span>
            </div>
          </div>

          <div class="session-badges">
            <span class="badge <?= h($status_badges[$status] ?? 'badge-muted') ?>">
              <?= h($status_labels[$status] ?? $status) ?>
            </span>

            <span class="badge badge-muted">
              <?= h($type_labels[$session_type] ?? $session_type) ?>
            </span>
          </div>
        </div>

        <?php if (!empty($s['notes_shared'])): ?>
          <div class="session-note-block">
            <strong>Notes</strong>
            <p><?= nl2br(h($s['notes_shared'])) ?></p>
          </div>
        <?php endif; ?>

        <?php if (!empty($s['action_items'])): ?>
          <div class="session-note-block">
            <strong>Action Items</strong>
            <p><?= nl2br(h($s['action_items'])) ?></p>
          </div>
        <?php endif; ?>

        <?php if ($is_cancelled && !empty($s['cancel_reason'])): ?>
          <div class="session-note-block" style="background:#fdecea;border-left:3px solid #e74c3c">
            <strong>Cancellation reason</strong>
            <p><?= nl2br(h($s['cancel_reason'])) ?></p>
          </div>
        <?php endif; ?>

        <div class="session-card-footer">
          <div class="session-date-full">
            <i class="fa fa-calendar-alt"></i>
            <?= $dt ? h($dt->format('l, F j, Y')) : 'Date TBC' ?>
          </div>

          <?php if (!empty($s['meeting_link']) && $is_upcoming): ?>
            <a href="<?= h($s['meeting_link']) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-sm">
              <i class="fa fa-external-link-alt"></i> Join
            </a>
          <?php endif; ?>
        </div>

        <div class="session-card-actions">
          <?php if ($is_done && $rating <= 0): ?>
            <button type="button" class="btn btn-gold btn-sm"
                    onclick="openRateModal(<?= $sid ?>, '<?= h(addslashes((string)($s['title'] ?? 'Session'))) ?>')">
              <i class="fa fa-star"></i> Rate Session
            </button>
          <?php elseif ($is_done && $rating > 0): ?>
            <div class="session-rating">
              <?php for ($i = 1; $i <= 5; $i++): ?>
                <i class="fa fa-star<?= $i <= $rating ? '' : '-o' ?>"></i>
              <?php endfor; ?>
              <span>Your session rating</span>
            </div>
          <?php endif; ?>

        </div>

  
        <div class="session-extra-actions">
          <button type="button" class="btn btn-outline btn-sm" onclick="toggleExpand(<?= $sid ?>)">
            <i class="fa fa-comments"></i> Comments<?= $cmt_count ? ' (' . $cmt_count . ')' : '' ?>
          </button>

          <?php if ($is_done): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="openReportModal(<?= $sid ?>)">
              <i class="fa fa-file-signature"></i>
              <?= $fb_status === '' ? 'Submit Session Report' : ($fb_status === 'draft' ? 'Continue Session Report' : 'View Session Report') ?>
              <?php if ($fb_status !== ''): ?>
                <span class="badge <?= h($fb_status_badges[$fb_status] ?? 'badge-muted') ?>" style="margin-left:5px">
                  <?= h($fb_status_labels[$fb_status] ?? $fb_status) ?>
                </span>
              <?php endif; ?>
            </button>

            <?php if ($mentor_rating_given <= 0): ?>
              <button type="button" class="btn btn-outline btn-sm" onclick="openMentorRateModal(<?= $sid ?>, '<?= h(addslashes($mentor_name)) ?>')">
                <i class="fa fa-user-tie"></i> Rate Mentor
              </button>
            <?php else: ?>
              <span class="badge badge-success" style="display:inline-flex;align-items:center;gap:5px">
                <i class="fa fa-check"></i> Mentor rated <?= $mentor_rating_given ?>/5
              </span>
            <?php endif; ?>
          <?php endif; ?>
        </div>

      
        <div class="session-expand" id="expand-<?= $sid ?>">

          <div class="session-expand-title"><i class="fa fa-comments"></i> Comments &amp; notes</div>
          <div class="comment-thread" id="comments-<?= $sid ?>">
            <?php if (!$cmt_count): ?>
              <p style="font-size:12.5px;color:var(--muted,#7f8c8d);padding:4px 0">No comments yet. Start the conversation below.</p>
            <?php else: foreach ($comments_by_session[$sid] as $c): ?>
              <div class="comment-bubble <?= h($c['author_type'] ?? 'admin') ?>">
                <div class="cb-inner">
                  <div class="cb-author"><?= h($c['display_name'] ?? 'Team') ?></div>
                  <div class="cb-text"><?= nl2br(h($c['comment_text'] ?? '')) ?></div>
                  <div class="cb-time"><?= h(date('M j, g:i A', strtotime((string)$c['created_at']))) ?></div>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>

          <form method="POST" action="includes/portal-process.php" class="comment-compose">
            <input type="hidden" name="action" value="add_session_comment">
            <input type="hidden" name="session_id" value="<?= $sid ?>">
            <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
            <textarea name="comment_text" placeholder="Add a comment or question for your mentor..." rows="2" required></textarea>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-paper-plane"></i></button>
          </form>
        </div>



      </article>
    <?php endforeach; ?>
  </div>

  <?php if (!$has_sessions): ?>
    <div class="sessions-empty">
  
      <h3>No <?= $filter === 'all' ? '' : h($filter) ?> sessions</h3>

      <?php if ($filter === 'upcoming' || $filter === 'all'): ?>
        <p>Request a session with one of your assigned mentors.</p>
        <a href="mentors.php" class="btn btn-primary">
          Request Session
        </a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

</div>



<?php
/* ============================================================
   SESSION REPORT POPUP MODALS
   Rendered outside the session cards so they behave exactly like
   Rate Session / Rate Mentor and cannot be clipped by card/grid CSS.
============================================================ */
foreach ($session_rows as $s):
    if ((string)($s['status'] ?? '') !== 'completed') {
        continue;
    }

    $sid         = (int)$s['id'];
    $dt          = !empty($s['scheduled_at']) ? new DateTime((string)$s['scheduled_at']) : null;
    $mentor_name = (string)($s['mentor_name'] ?? 'Mentor');

    $fb        = $feedback_by_session[$sid] ?? null;
    $fb_status = (string)($fb['status'] ?? '');
    $fb_locked = in_array($fb_status, ['submitted', 'reviewed'], true);

    $disc_rows = fb_json_rows($fb['discussion_summary'] ?? null);
    if (!$disc_rows) {
        $disc_rows = [['area' => '', 'helpful' => '', 'comments' => '']];
    }

    $assess_rows = fb_json_rows($fb['mentor_assessment'] ?? null);
    if (!$assess_rows) {
        $assess_rows = [['area' => '', 'rating' => '', 'comments' => '']];
    }

    $action_rows = fb_json_rows($fb['action_plan'] ?? null);
    if (!$action_rows) {
        $action_rows = [[
            'action'      => '',
            'responsible' => '',
            'timeline'    => '',
            'confidence'  => '',
        ]];
    }

    $challenge_rows = fb_json_rows($fb['challenges'] ?? null);
    if (!$challenge_rows) {
        $challenge_rows = [[
            'challenge' => '',
            'urgency'   => '',
            'support'   => '',
        ]];
    }
?>
        <?php if ($is_done): ?>
        <div class="modal-overlay" id="fbModal-<?= $sid ?>">
          <div class="modal-box fb-report-modal-box">
            <div class="modal-box-header">
              <div>
                <div class="modal-box-title"><i class="fa fa-clipboard-list"></i> Venture Mentorship Session Report</div>
                <p class="fb-report-subtitle"><?= h($s['title'] ?? 'Mentorship Session') ?> - complete the report, save a draft, or submit it for review.</p>
              </div>
              <button type="button" class="modal-box-close" onclick="closeModal('fbModal-<?= $sid ?>')">&times;</button>
            </div>
            <div class="fb-report-modal-body">

          <?php if ($fb_locked): ?>
            <div class="fb-readonly-note">
              <i class="fa fa-lock"></i>
              This session report was <strong><?= $fb_status === 'reviewed' ? 'reviewed' : 'submitted for review' ?></strong>
              <?= !empty($fb['submitted_at']) ? 'on ' . h(date('M j, Y g:i A', strtotime($fb['submitted_at']))) : '' ?>
              and can no longer be edited.
              <?php if ($fb_status === 'reviewed' && !empty($fb['review_notes'])): ?>
                <div style="margin-top:6px"><strong>Reviewer notes:</strong> <?= nl2br(h($fb['review_notes'])) ?></div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <form method="POST" action="includes/portal-process.php" id="fb-form-<?= $sid ?>" data-locked="<?= $fb_locked ? '1' : '0' ?>">
            <input type="hidden" name="action" value="save_session_feedback">
            <input type="hidden" name="session_id" value="<?= $sid ?>">
            <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
            <input type="hidden" name="submit_mode" value="draft" class="fb-submit-mode">

            <div class="fb-section">
              <div class="fb-section-title">Session Information</div>
              <div class="fb-grid-3">
                <div class="fb-field">
                  <label>Venture Name</label>
                  <input type="text" name="venture_name" value="<?= h($fb['venture_name'] ?? ($VENTURE['name'] ?? '')) ?>" <?= $fb_locked ? 'disabled' : '' ?>>
                </div>
                <div class="fb-field">
                  <label>Founder(s) / Representative(s) Present</label>
                  <input type="text" name="founders_present" value="<?= h($fb['founders_present'] ?? '') ?>" <?= $fb_locked ? 'disabled' : '' ?>>
                </div>
                <div class="fb-field">
                  <label>Mentor Name</label>
                  <input type="text" name="mentor_name" value="<?= h($fb['mentor_name'] ?? $mentor_name) ?>" <?= $fb_locked ? 'disabled' : '' ?>>
                </div>
                <div class="fb-field">
                  <label>Session Date</label>
                  <input type="date" name="session_date" value="<?= h($fb['session_date'] ?? ($dt ? $dt->format('Y-m-d') : '')) ?>" <?= $fb_locked ? 'disabled' : '' ?>>
                </div>
                <div class="fb-field">
                  <label>Session Duration</label>
                  <input type="text" name="session_duration" placeholder="e.g. 60 minutes" value="<?= h($fb['session_duration'] ?? (((int)($s['duration_minutes'] ?? 0)) . ' minutes')) ?>" <?= $fb_locked ? 'disabled' : '' ?>>
                </div>
                <div class="fb-field">
                  <label>Mode of Engagement</label>
                  <?= fb_select('mode_of_engagement', $fb_mode_options, (string)($fb['mode_of_engagement'] ?? ''), $fb_locked) ?>
                </div>
              </div>
            </div>

            <div class="fb-section">
              <div class="fb-section-title"><span class="fb-num">1</span> Session Objectives</div>
              <div class="fb-field">
                <label>What were you hoping to achieve during this mentorship session?</label>
                <textarea name="objectives" rows="3" <?= $fb_locked ? 'disabled' : '' ?>><?= h($fb['objectives'] ?? '') ?></textarea>
              </div>
            </div>

            <div class="fb-section">
              <div class="fb-section-title"><span class="fb-num">2</span> Session Discussion Summary</div>
              <div class="fb-table-wrap"><table class="fb-repeat-table" data-repeat="discussion">
                <thead><tr><th style="width:32%">Discussion Area</th><th style="width:28%">How Helpful</th><th>Comments</th><th style="width:24px"></th></tr></thead>
                <tbody>
                  <?php
                    $disc_rows = fb_json_rows($fb['discussion_summary'] ?? null);
                    if (!$disc_rows) $disc_rows = [['area' => '', 'helpful' => '', 'comments' => '']];
                    foreach ($disc_rows as $row):
                  ?>
                    <tr>
                      <td><input type="text" name="discussion_area[]" value="<?= h($row['area'] ?? '') ?>" <?= $fb_locked ? 'disabled' : '' ?>></td>
                      <td><?= fb_select('discussion_helpful[]', $fb_helpful_options, (string)($row['helpful'] ?? ''), $fb_locked, '-') ?></td>
                      <td><input type="text" name="discussion_comments[]" value="<?= h($row['comments'] ?? '') ?>" <?= $fb_locked ? 'disabled' : '' ?>></td>
                      <td><?php if (!$fb_locked): ?><button type="button" class="fb-repeat-remove" onclick="fbRemoveRow(this)"><i class="fa fa-times"></i></button><?php endif; ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table></div>
              <?php if (!$fb_locked): ?><button type="button" class="fb-add-row" onclick="fbAddRow(this,'discussion')"><i class="fa fa-plus"></i> Add discussion area</button><?php endif; ?>
            </div>

            <div class="fb-section">
              <div class="fb-section-title"><span class="fb-num">3</span> Mentor Assessment</div>
              <div class="fb-table-wrap"><table class="fb-repeat-table" data-repeat="assess">
                <thead><tr><th style="width:32%">Assessment Area</th><th style="width:20%">Rating (1-5)</th><th>Comments</th><th style="width:24px"></th></tr></thead>
                <tbody>
                  <?php
                    $assess_rows = fb_json_rows($fb['mentor_assessment'] ?? null);
                    if (!$assess_rows) $assess_rows = [['area' => '', 'rating' => '', 'comments' => '']];
                    foreach ($assess_rows as $row):
                  ?>
                    <tr>
                      <td><input type="text" name="assess_area[]" value="<?= h($row['area'] ?? '') ?>" <?= $fb_locked ? 'disabled' : '' ?>></td>
                      <td><?= fb_select('assess_rating[]', $fb_rating_options, (string)($row['rating'] ?? ''), $fb_locked, '-') ?></td>
                      <td><input type="text" name="assess_comments[]" value="<?= h($row['comments'] ?? '') ?>" <?= $fb_locked ? 'disabled' : '' ?>></td>
                      <td><?php if (!$fb_locked): ?><button type="button" class="fb-repeat-remove" onclick="fbRemoveRow(this)"><i class="fa fa-times"></i></button><?php endif; ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table></div>
              <?php if (!$fb_locked): ?><button type="button" class="fb-add-row" onclick="fbAddRow(this,'assess')"><i class="fa fa-plus"></i> Add assessment area</button><?php endif; ?>
            </div>

            <div class="fb-section">
              <div class="fb-section-title"><span class="fb-num">4</span> Value of the Session</div>
              <div class="fb-field">
                <label>Which of the following best describes today's session?</label>
                <div class="fb-radio-group">
                  <?php foreach ($fb_value_options as $val => $label): ?>
                    <label>
                      <input type="radio" name="value_rating" value="<?= h($val) ?>" <?= ((string)($fb['value_rating'] ?? '') === $val) ? 'checked' : '' ?> <?= $fb_locked ? 'disabled' : '' ?>>
                      <?= h($label) ?>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="fb-grid-2">
                <div class="fb-field">
                  <label>What was the most valuable insight you gained?</label>
                  <textarea name="valuable_insight" rows="3" <?= $fb_locked ? 'disabled' : '' ?>><?= h($fb['valuable_insight'] ?? '') ?></textarea>
                </div>
                <div class="fb-field">
                  <label>Which recommendations were made?</label>
                  <textarea name="recommendations" rows="3" <?= $fb_locked ? 'disabled' : '' ?>><?= h($fb['recommendations'] ?? '') ?></textarea>
                </div>
              </div>
            </div>

            <div class="fb-section">
              <div class="fb-section-title"><span class="fb-num">5</span> Action Plan</div>
              <div class="fb-table-wrap"><table class="fb-repeat-table" data-repeat="action">
                <thead><tr><th style="width:30%">Agreed Action</th><th style="width:20%">Responsible Person</th><th style="width:18%">Timeline</th><th style="width:20%">Confidence to Complete</th><th style="width:24px"></th></tr></thead>
                <tbody>
                  <?php
                    $action_rows = fb_json_rows($fb['action_plan'] ?? null);
                    if (!$action_rows) $action_rows = [['action' => '', 'responsible' => '', 'timeline' => '', 'confidence' => '']];
                    foreach ($action_rows as $row):
                  ?>
                    <tr>
                      <td><input type="text" name="action_item[]" value="<?= h($row['action'] ?? '') ?>" <?= $fb_locked ? 'disabled' : '' ?>></td>
                      <td><input type="text" name="action_responsible[]" value="<?= h($row['responsible'] ?? '') ?>" <?= $fb_locked ? 'disabled' : '' ?>></td>
                      <td><input type="text" name="action_timeline[]" value="<?= h($row['timeline'] ?? '') ?>" <?= $fb_locked ? 'disabled' : '' ?>></td>
                      <td><?= fb_select('action_confidence[]', $fb_confidence_options, (string)($row['confidence'] ?? ''), $fb_locked, '-') ?></td>
                      <td><?php if (!$fb_locked): ?><button type="button" class="fb-repeat-remove" onclick="fbRemoveRow(this)"><i class="fa fa-times"></i></button><?php endif; ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table></div>
              <?php if (!$fb_locked): ?><button type="button" class="fb-add-row" onclick="fbAddRow(this,'action')"><i class="fa fa-plus"></i> Add action</button><?php endif; ?>
            </div>

            <div class="fb-section">
              <div class="fb-section-title"><span class="fb-num">6</span> Progress Assessment</div>
              <div class="fb-field">
                <label>Compared to your previous mentorship session, do you feel your venture has progressed?</label>
                <div class="fb-radio-group">
                  <?php foreach ($fb_progress_options as $val => $label): ?>
                    <label>
                      <input type="radio" name="progress_rating" value="<?= h($val) ?>" <?= ((string)($fb['progress_rating'] ?? '') === $val) ? 'checked' : '' ?> <?= $fb_locked ? 'disabled' : '' ?>>
                      <?= h($label) ?>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="fb-field">
                <label>Please explain</label>
                <textarea name="progress_explain" rows="2" <?= $fb_locked ? 'disabled' : '' ?>><?= h($fb['progress_explain'] ?? '') ?></textarea>
              </div>
            </div>

            <div class="fb-section">
              <div class="fb-section-title"><span class="fb-num">7</span> Remaining Challenges</div>
              <div class="fb-table-wrap"><table class="fb-repeat-table" data-repeat="challenge">
                <thead><tr><th style="width:34%">Challenge</th><th style="width:20%">Level of Urgency</th><th>Support Needed</th><th style="width:24px"></th></tr></thead>
                <tbody>
                  <?php
                    $challenge_rows = fb_json_rows($fb['challenges'] ?? null);
                    if (!$challenge_rows) $challenge_rows = [['challenge' => '', 'urgency' => '', 'support' => '']];
                    foreach ($challenge_rows as $row):
                  ?>
                    <tr>
                      <td><input type="text" name="challenge_text[]" value="<?= h($row['challenge'] ?? '') ?>" <?= $fb_locked ? 'disabled' : '' ?>></td>
                      <td><?= fb_select('challenge_urgency[]', $fb_urgency_options, (string)($row['urgency'] ?? ''), $fb_locked, '-') ?></td>
                      <td><input type="text" name="challenge_support[]" value="<?= h($row['support'] ?? '') ?>" <?= $fb_locked ? 'disabled' : '' ?>></td>
                      <td><?php if (!$fb_locked): ?><button type="button" class="fb-repeat-remove" onclick="fbRemoveRow(this)"><i class="fa fa-times"></i></button><?php endif; ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table></div>
              <?php if (!$fb_locked): ?><button type="button" class="fb-add-row" onclick="fbAddRow(this,'challenge')"><i class="fa fa-plus"></i> Add challenge</button><?php endif; ?>
            </div>

            <div class="fb-section">
              <div class="fb-section-title"><span class="fb-num">8</span> Future Support Needs</div>
              <div class="fb-field">
                <label>Which areas would you like additional mentorship on?</label>
                <textarea name="future_support" rows="2" <?= $fb_locked ? 'disabled' : '' ?>><?= h($fb['future_support'] ?? '') ?></textarea>
              </div>
            </div>

            <div class="fb-section">
              <div class="fb-section-title"><span class="fb-num">9</span> Overall Session Satisfaction</div>
              <div class="fb-grid-2">
                <div class="fb-field">
                  <label>How satisfied were you with today's mentorship session? (1-5)</label>
                  <?= fb_select('satisfaction_rating', $fb_rating_options, (string)($fb['satisfaction_rating'] ?? ''), $fb_locked, '-') ?>
                </div>
                <div class="fb-field">
                  <label>Comments (optional)</label>
                  <input type="text" name="satisfaction_comments" value="<?= h($fb['satisfaction_comments'] ?? '') ?>" <?= $fb_locked ? 'disabled' : '' ?>>
                </div>
              </div>
              <div class="fb-field" style="margin-top:8px">
                <label>Would you recommend this mentor to another venture?</label>
                <div class="fb-radio-group">
                  <?php foreach ($fb_recommend_options as $val => $label): ?>
                    <label>
                      <input type="radio" name="recommend_mentor" value="<?= h($val) ?>" <?= ((string)($fb['recommend_mentor'] ?? '') === $val) ? 'checked' : '' ?> <?= $fb_locked ? 'disabled' : '' ?>>
                      <?= h($label) ?>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>

            <div class="fb-section">
              <div class="fb-section-title"><span class="fb-num">10</span> Additional Feedback for Hive Colab</div>
              <div class="fb-grid-2">
                <div class="fb-field">
                  <label>What could Hive Colab do to improve the mentorship programme?</label>
                  <textarea name="feedback_for_admin" rows="3" <?= $fb_locked ? 'disabled' : '' ?>><?= h($fb['feedback_for_admin'] ?? '') ?></textarea>
                </div>
                <div class="fb-field">
                  <label>What could the mentor do differently in future sessions?</label>
                  <textarea name="mentor_could_improve" rows="3" <?= $fb_locked ? 'disabled' : '' ?>><?= h($fb['mentor_could_improve'] ?? '') ?></textarea>
                </div>
              </div>
            </div>

            <?php if (!$fb_locked): ?>
              <div class="fb-actions">
                <button type="button" class="btn btn-outline btn-sm" onclick="fbSubmitAs(<?= $sid ?>,'draft')">
                  <i class="fa fa-save"></i> Save Draft
                </button>
                <button type="button" class="btn btn-primary btn-sm" onclick="fbSubmitAs(<?= $sid ?>,'submit')">
                  <i class="fa fa-paper-plane"></i> Submit for Review
                </button>
              </div>
            <?php endif; ?>
          </form>
            </div>
          </div>
        </div>
        <?php endif; ?>
<?php endforeach; ?>


<!-- ============================================================
     CUSTOM CONFIRMATION MODAL: SUBMIT SESSION REPORT
============================================================ -->
<div class="modal-overlay" id="reportConfirmModal">
    <div class="modal-box" style="max-width:460px">

        <div class="modal-box-header">
            <div class="modal-box-title">
                <i class="fa fa-paper-plane"></i>
                Submit Session Report
            </div>

            <button
                type="button"
                class="modal-box-close"
                onclick="closeReportConfirmModal()"
                aria-label="Close confirmation"
            >
                &times;
            </button>
        </div>

        <div class="modal-box-body">
            <div style="
                display:flex;
                align-items:flex-start;
                gap:14px;
            ">
                <div style="
                    width:44px;
                    height:44px;
                    min-width:44px;
                    border-radius:12px;
                    display:flex;
                    align-items:center;
                    justify-content:center;
                    background:#fff4e8;
                    color:#fc7f10;
                    font-size:18px;
                ">
                    <i class="fa fa-exclamation-triangle"></i>
                </div>

                <div>
                    <h3 style="
                        margin:0 0 7px;
                        font-size:16px;
                        color:#182230;
                    ">
                        Submit this report for review?
                    </h3>

                    <p style="
                        margin:0;
                        color:#667085;
                        font-size:13px;
                        line-height:1.6;
                    ">
                        Once submitted, you will not be able to edit the session report
                        unless the programme team returns it to you.
                    </p>
                </div>
            </div>
        </div>

        <div class="modal-box-footer">
            <button
                type="button"
                class="btn btn-outline"
                onclick="closeReportConfirmModal()"
            >
                Cancel
            </button>

            <button
                type="button"
                class="btn btn-primary"
                id="confirmSubmitReportBtn"
                onclick="confirmSessionReportSubmit()"
            >
                <i class="fa fa-paper-plane"></i>
                Submit Report
            </button>
        </div>

    </div>
</div>

<div class="modal-overlay" id="rateModal">
  <div class="modal-box">
    <div class="modal-box-header">
      <div class="modal-box-title">Rate Session</div>
      <button type="button" class="modal-box-close" onclick="closeModal('rateModal')">&times;</button>
    </div>

    <form method="POST" action="includes/portal-process.php">
      <input type="hidden" name="action" value="rate_session">
      <input type="hidden" name="session_id" id="rate_session_id">

      <div class="modal-box-body">
        <p class="rate-session-title" id="rate_session_title"></p>

        <div class="form-group">
          <label class="form-label">Your Rating</label>
          <div class="star-rater">
            <?php for ($i = 5; $i >= 1; $i--): ?>
              <input type="radio" name="rating" id="star<?= $i ?>" value="<?= $i ?>" required>
              <label for="star<?= $i ?>">&#9733;</label>
            <?php endfor; ?>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Feedback (optional)</label>
          <textarea name="feedback" class="form-control" rows="4" placeholder="What did you find most helpful? What could be improved?"></textarea>
        </div>
      </div>

      <div class="modal-box-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('rateModal')">Cancel</button>
        <button type="submit" class="btn btn-gold">
          <i class="fa fa-star"></i> Submit Rating
        </button>
      </div>
    </form>
  </div>
</div>



<div class="modal-overlay" id="mentorRateModal">
  <div class="modal-box">
    <div class="modal-box-header">
      <div class="modal-box-title">Rate Your Mentor</div>
      <button type="button" class="modal-box-close" onclick="closeModal('mentorRateModal')">&times;</button>
    </div>

    <form method="POST" action="includes/portal-process.php">
      <input type="hidden" name="action" value="rate_mentor">
      <input type="hidden" name="session_id" id="mrate_session_id">
      <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
      <input type="hidden" name="mentor_rating" id="mrate_value" value="0">

      <div class="modal-box-body">
        <p class="rate-session-title" id="mrate_mentor_name"></p>
        <div class="form-group">
          <label class="form-label">How would you rate this mentor overall?</label>
          <div class="star-picker-row" id="mrate-stars" onclick="pickMentorStar(event)">
            <i class="far fa-star" data-v="1"></i>
            <i class="far fa-star" data-v="2"></i>
            <i class="far fa-star" data-v="3"></i>
            <i class="far fa-star" data-v="4"></i>
            <i class="far fa-star" data-v="5"></i>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Feedback (optional)</label>
          <textarea name="mentor_feedback" class="form-control" rows="3" placeholder="Was the mentor helpful, responsive, knowledgeable?"></textarea>
        </div>
      </div>

      <div class="modal-box-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('mentorRateModal')">Cancel</button>
        <button type="submit" class="btn btn-gold" id="mrate-submit" disabled>
          <i class="fa fa-star"></i> Submit Rating
        </button>
      </div>
    </form>
  </div>
</div>



<script>
function toggleSidebar() {
  const sidebar = document.getElementById('vpSidebar');
  if (sidebar) sidebar.classList.toggle('open');
}


function closeModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.remove('open');

  if (!document.querySelector('.modal-overlay.open')) {
    document.body.style.overflow = '';
  }
}

function openReportModal(sessionId) {
  const modal = document.getElementById('fbModal-' + sessionId);
  if (!modal || modal.classList.contains('open')) return;

  document.querySelectorAll('[id^="fbModal-"].modal-overlay.open').forEach(other => {
    other.classList.remove('open');
  });

  document.body.style.overflow = 'hidden';
  modal.classList.add('open');

  const body = modal.querySelector('.fb-report-modal-body');
  if (body) body.scrollTop = 0;
}
['rateModal','mentorRateModal'].forEach(id => {
  const m = document.getElementById(id);
  if (m) {
    m.addEventListener('click', function (event) {
      if (event.target === m) closeModal(id);
    });
  }
});

document.querySelectorAll('[id^="fbModal-"].modal-overlay').forEach(m => {
  m.addEventListener('click', function (event) {
    if (event.target === m) closeModal(m.id);
  });
});


function openRateModal(id, title) {
  document.getElementById('rate_session_id').value = id;
  document.getElementById('rate_session_title').textContent = title;
  document.getElementById('rateModal').classList.add('open');
}


function openMentorRateModal(sessionId, mentorName) {
  document.getElementById('mrate_session_id').value = sessionId;
  document.getElementById('mrate_mentor_name').textContent = 'Rate ' + mentorName;
  document.getElementById('mrate_value').value = '0';
  document.getElementById('mrate-submit').disabled = true;
  document.querySelectorAll('#mrate-stars i').forEach(s => s.className = 'far fa-star');
  document.getElementById('mentorRateModal').classList.add('open');
}
function pickMentorStar(e) {
  const star = e.target.closest('i[data-v]');
  if (!star) return;
  const val = parseInt(star.dataset.v, 10);
  document.getElementById('mrate_value').value = val;
  document.getElementById('mrate-submit').disabled = false;
  document.querySelectorAll('#mrate-stars i').forEach((s, i) => {
    s.className = (i < val) ? 'fas fa-star lit' : 'far fa-star';
  });
}






function toggleExpand(sessionId, panelId) {
  const el = document.getElementById(panelId || ('expand-' + sessionId));
  if (el) el.classList.toggle('open');
}

// ---------------------------------------------------------------
//  Session report template: dynamic repeatable rows + draft/submit modes
// ---------------------------------------------------------------
const fbRowTemplates = {
  discussion: '<tr><td><input type="text" name="discussion_area[]"></td><td><select name="discussion_helpful[]" class="form-control"><option value="">&mdash;</option><option value="5">5 - Extremely Helpful</option><option value="4">4 - Very Helpful</option><option value="3">3 - Helpful</option><option value="2">2 - Slightly Helpful</option><option value="1">1 - Not Helpful</option></select></td><td><input type="text" name="discussion_comments[]"></td><td><button type="button" class="fb-repeat-remove" onclick="fbRemoveRow(this)"><i class="fa fa-times"></i></button></td></tr>',
  assess: '<tr><td><input type="text" name="assess_area[]"></td><td><select name="assess_rating[]" class="form-control"><option value="">&mdash;</option><option value="5">5</option><option value="4">4</option><option value="3">3</option><option value="2">2</option><option value="1">1</option></select></td><td><input type="text" name="assess_comments[]"></td><td><button type="button" class="fb-repeat-remove" onclick="fbRemoveRow(this)"><i class="fa fa-times"></i></button></td></tr>',
  action: '<tr><td><input type="text" name="action_item[]"></td><td><input type="text" name="action_responsible[]"></td><td><input type="text" name="action_timeline[]"></td><td><select name="action_confidence[]" class="form-control"><option value="">&mdash;</option><option value="very_confident">Very Confident</option><option value="confident">Confident</option><option value="somewhat_confident">Somewhat Confident</option><option value="not_confident">Not Confident</option></select></td><td><button type="button" class="fb-repeat-remove" onclick="fbRemoveRow(this)"><i class="fa fa-times"></i></button></td></tr>',
  challenge: '<tr><td><input type="text" name="challenge_text[]"></td><td><select name="challenge_urgency[]" class="form-control"><option value="">&mdash;</option><option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option></select></td><td><input type="text" name="challenge_support[]"></td><td><button type="button" class="fb-repeat-remove" onclick="fbRemoveRow(this)"><i class="fa fa-times"></i></button></td></tr>'
};

function fbAddRow(btn, type) {
  const table = btn.closest('.fb-section').querySelector('table[data-repeat="' + type + '"] tbody');
  if (!table || !fbRowTemplates[type]) return;
  table.insertAdjacentHTML('beforeend', fbRowTemplates[type]);
}

function fbRemoveRow(btn) {
  const row = btn.closest('tr');
  const tbody = row.closest('tbody');
  if (tbody.querySelectorAll('tr').length > 1) {
    row.remove();
  } else {
    row.querySelectorAll('input, select').forEach(el => el.value = '');
  }
}

let pendingReportSubmitSessionId = null;

function fbSubmitAs(sessionId, mode) {
    const form = document.getElementById('fb-form-' + sessionId);

    if (!form) {
        return;
    }

    if (form.dataset.locked === '1') {
        return;
    }

    if (mode === 'submit') {
        const objectives = form.querySelector('[name="objectives"]');
        const valueRating = form.querySelector('[name="value_rating"]:checked');

        if (!objectives || !objectives.value.trim()) {
            showFormMessage(
                'Please enter the session objectives before submitting.',
                objectives
            );
            return;
        }

        if (!valueRating) {
            showFormMessage(
                'Please select the value of the session before submitting.'
            );
            return;
        }

        pendingReportSubmitSessionId = sessionId;

        const confirmModal = document.getElementById('reportConfirmModal');

        if (confirmModal) {
            confirmModal.classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        return;
    }

    submitSessionReportForm(sessionId, 'draft');
}

function submitSessionReportForm(sessionId, mode) {
    const form = document.getElementById('fb-form-' + sessionId);

    if (!form || form.dataset.locked === '1') {
        return;
    }

    const modeInput = form.querySelector('.fb-submit-mode');

    if (modeInput) {
        modeInput.value = mode;
    }

    const buttons = form.querySelectorAll(
        '.fb-actions button, .fb-report-footer button'
    );

    buttons.forEach(button => {
        button.disabled = true;
    });

    form.submit();
}

function closeReportConfirmModal() {
    const modal = document.getElementById('reportConfirmModal');

    if (modal) {
        modal.classList.remove('open');
    }

    pendingReportSubmitSessionId = null;

    if (!document.querySelector('.modal-overlay.open')) {
        document.body.style.overflow = '';
    }
}

function confirmSessionReportSubmit() {
    if (pendingReportSubmitSessionId === null) {
        closeReportConfirmModal();
        return;
    }

    const sessionId = pendingReportSubmitSessionId;
    const btn = document.getElementById('confirmSubmitReportBtn');

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Submitting...';
    }

    const modal = document.getElementById('reportConfirmModal');

    if (modal) {
        modal.classList.remove('open');
    }

    pendingReportSubmitSessionId = null;

    submitSessionReportForm(sessionId, 'submit');
}

/* Lightweight custom validation message instead of browser alerts */
function showFormMessage(message, focusElement = null) {
    let notice = document.getElementById('reportFormNotice');

    if (!notice) {
        notice = document.createElement('div');
        notice.id = 'reportFormNotice';
        notice.style.position = 'fixed';
        notice.style.top = '20px';
        notice.style.left = '50%';
        notice.style.transform = 'translateX(-50%)';
        notice.style.zIndex = '1000000';
        notice.style.maxWidth = 'calc(100vw - 30px)';
        notice.style.padding = '11px 16px';
        notice.style.borderRadius = '10px';
        notice.style.background = '#fff4e8';
        notice.style.border = '1px solid #ffd9b3';
        notice.style.color = '#8a4b08';
        notice.style.fontSize = '13px';
        notice.style.fontWeight = '600';
        notice.style.boxShadow = '0 12px 30px rgba(15,23,42,.15)';
        document.body.appendChild(notice);
    }

    notice.textContent = message;
    notice.style.display = 'block';

    clearTimeout(window.__reportNoticeTimer);

    window.__reportNoticeTimer = setTimeout(() => {
        notice.style.display = 'none';
    }, 3500);

    if (focusElement) {
        focusElement.focus();
    }
}

document.addEventListener('keydown', function(event) {
  if (event.key !== 'Escape') return;

  const openReport = document.querySelector('[id^="fbModal-"].modal-overlay.open');
  if (openReport) closeModal(openReport.id);
});

const reportConfirmModal = document.getElementById('reportConfirmModal');

if (reportConfirmModal) {
    reportConfirmModal.addEventListener('click', function(event) {
        if (event.target === reportConfirmModal) {
            closeReportConfirmModal();
        }
    });
}

document.addEventListener('keydown', function(event) {
    if (
        event.key === 'Escape' &&
        document.getElementById('reportConfirmModal')?.classList.contains('open')
    ) {
        closeReportConfirmModal();
    }
});

</script>