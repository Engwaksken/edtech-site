<?php

require_once '../includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/session-junctions.php';
if (!isset($conn) || !($conn instanceof mysqli)) die('DB error');
$conn->set_charset('utf8mb4');


$IS_MENTOR        = ms_is_mentor();
$CAN_MANAGE       = ms_can_manage();
$MY_MENTOR_ID     = ms_mentor_record_id($conn);
$CAN_EDIT_AVAIL   = $CAN_MANAGE || ($IS_MENTOR && $MY_MENTOR_ID > 0);


$view         = $_GET['view']       ?? 'mentor';
$mentor_id    = (int)($_GET['mentor_id']  ?? 0);
$venture_id   = (int)($_GET['venture_id'] ?? 0);
$filter_month = $_GET['month'] ?? date('Y-m');


if ($IS_MENTOR && !$CAN_MANAGE) {
    $mentor_id = $MY_MENTOR_ID;
}


if ($mentor_id <= 0) {
    $first = $conn->query("SELECT id FROM mentors WHERE status='active' ORDER BY full_name LIMIT 1")->fetch_assoc();
    $mentor_id = $first ? (int)$first['id'] : 0;
}
$mentor = null;
if ($mentor_id) {
    $st = $conn->prepare("SELECT id,full_name,photo,organisation,email FROM mentors WHERE id=? LIMIT 1");
    $st->bind_param('i',$mentor_id); $st->execute();
    $mentor = $st->get_result()->fetch_assoc(); $st->close();
}


if ($venture_id <= 0) {
    $first = $conn->query("SELECT id FROM ventures ORDER BY name LIMIT 1")->fetch_assoc();
    $venture_id = $first ? (int)$first['id'] : 0;
}
$venture = null;
if ($venture_id) {
    $st = $conn->prepare("SELECT id,name,email AS founder_email FROM ventures WHERE id=? LIMIT 1");
    $st->bind_param('i',$venture_id); $st->execute();
    $venture = $st->get_result()->fetch_assoc(); $st->close();
}


$month_start = date('Y-m-01', strtotime($filter_month.'-01'));
$month_end   = date('Y-m-t',  strtotime($month_start));
$prev_month  = date('Y-m', strtotime('-1 month', strtotime($month_start)));
$next_month  = date('Y-m', strtotime('+1 month', strtotime($month_start)));


$sessions = [];
if ($mentor_id) {
    $st = $conn->prepare("
        SELECT DISTINCT ms.*, sm.invite_status AS my_invite_status, sm.role AS my_role
        FROM   mentor_sessions ms
        JOIN   session_mentors sm ON sm.session_id = ms.id AND sm.mentor_id = ?
        WHERE  DATE(ms.scheduled_at) BETWEEN ? AND ?
        ORDER  BY ms.scheduled_at ASC
    ");
    $st->bind_param('iss',$mentor_id,$month_start,$month_end);
    $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) $sessions[] = $r;
    $st->close();
}


$avail_blocks = [];
if ($mentor_id && $conn->query("SHOW TABLES LIKE 'mentor_availability'")->num_rows) {
    $has_link_col = $conn->query("SHOW COLUMNS FROM mentor_availability LIKE 'meeting_link'");
    $link_select = ($has_link_col instanceof mysqli_result && $has_link_col->num_rows > 0) ? ',meeting_link' : '';

    $st = $conn->prepare("
        SELECT avail_date,slot_time,is_blocked{$link_select}
        FROM   mentor_availability
        WHERE  mentor_id=? AND avail_date BETWEEN ? AND ?
        ORDER  BY avail_date,slot_time
    ");
    $st->bind_param('iss',$mentor_id,$month_start,$month_end);
    $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc())
        $avail_blocks[$r['avail_date']][] = [
            'time'         => $r['slot_time'],
            'blocked'      => (bool)$r['is_blocked'],
            'meeting_link' => $r['meeting_link'] ?? '',
        ];
    $st->close();
}


$venture_sessions = [];
if ($venture_id) {
    $st = $conn->prepare("
        SELECT DISTINCT ms.*
        FROM   mentor_sessions ms
        JOIN   session_ventures sv ON sv.session_id = ms.id AND sv.venture_id = ?
        WHERE  DATE(ms.scheduled_at) BETWEEN ? AND ?
        ORDER  BY ms.scheduled_at ASC
    ");
    $st->bind_param('iss',$venture_id,$month_start,$month_end);
    $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) $venture_sessions[] = $r;
    $st->close();
}


$all_session_ids   = array_unique(array_merge(array_column($sessions,'id'), array_column($venture_sessions,'id')));
$mentors_by_session = ms_get_mentors_for_sessions($conn, $all_session_ids);
$ventures_by_session = ms_get_ventures_for_sessions($conn, $all_session_ids);


$comments_by_session = [];
if ($conn->query("SHOW TABLES LIKE 'session_comments'")->num_rows) {
    if ($all_session_ids) {
        $in = implode(',',array_map('intval',$all_session_ids));
        $res = $conn->query("
            SELECT sc.*, COALESCE(sc.author_name,'Admin') AS display_name
            FROM session_comments sc
            WHERE sc.session_id IN($in)
            ORDER BY sc.created_at ASC
        ");
        while ($r = $res->fetch_assoc())
            $comments_by_session[(int)$r['session_id']][] = $r;
    }
}


$unread_messages = [];
if ($conn->query("SHOW TABLES LIKE 'venture_messages'")->num_rows) {
    $res = $conn->query("
        SELECT vm.*, v.name AS venture_name
        FROM   venture_messages vm
        JOIN   ventures v ON v.id = vm.venture_id
        WHERE  vm.is_read = 0
        ORDER  BY vm.created_at DESC
        LIMIT  20
    ");
    while ($r = $res->fetch_assoc()) $unread_messages[] = $r;
}


$mentors_dd  = $conn->query("SELECT id,full_name FROM mentors WHERE status='active' ORDER BY full_name");
$ventures_dd = $conn->query("SELECT id,name FROM ventures ORDER BY name");
$all_mentors_list  = [];
$mentors_dd->data_seek(0);
while ($r = $mentors_dd->fetch_assoc()) $all_mentors_list[] = $r;
$all_ventures_list = [];
$ventures_dd->data_seek(0);
while ($r = $ventures_dd->fetch_assoc()) $all_ventures_list[] = $r;

/* -- Stats ------------------------------------------------------ */
$stats = ['requested'=>0,'confirmed'=>0,'completed'=>0,'cancelled'=>0,'no_show'=>0];
foreach ($sessions as $s) {
    $k = $s['status'];
    if (isset($stats[$k])) $stats[$k]++;
    if ($k === 'scheduled') $stats['confirmed']++;
}


$my_pending_invites = [];
foreach ($sessions as $s) {
    if (($s['my_role'] ?? '') === 'co_mentor' && ($s['my_invite_status'] ?? '') === 'invited') {
        $my_pending_invites[] = $s;
    }
}

/* -- Maps --------------------------------------------------------- */
$status_map   = [
    'requested'=>['Requested','#FAEEDA','#633806'],
    'scheduled'=>['Scheduled','#E6F1FB','#185FA5'],
    'confirmed'=>['Confirmed','#EEEDFE','#3C3489'],
    'completed'=>['Completed','#EAF3DE','#27500A'],
    'cancelled'=>['Cancelled','#F1EFE8','#5F5E5A'],
    'no_show'  =>['No Show',  '#F1EFE8','#5F5E5A'],
];
$invite_status_map = [
    'invited'  => ['Invited','#FAEEDA','#633806'],
    'accepted' => ['Accepted','#EAF3DE','#27500A'],
    'declined' => ['Declined','#F1EFE8','#5F5E5A'],
];
$platform_map = ['zoom'=>'Zoom','google_meet'=>'Google Meet','teams'=>'MS Teams','phone'=>'Phone','in_person'=>'In Person','other'=>'Other'];
$SLOTS        = ['08:00','09:00','10:00','11:00','13:00','14:00','15:00','16:00','17:00'];

/* -- Calendar geometry -------------------------------------------- */
$sessions_by_date = [];
foreach ($sessions as $s) {
    if (empty($s['scheduled_at'])) continue;
    $sessions_by_date[date('Y-m-d',strtotime($s['scheduled_at']))][] = $s;
}
$venture_by_date = [];
foreach ($venture_sessions as $s) {
    if (empty($s['scheduled_at'])) continue;
    $venture_by_date[date('Y-m-d',strtotime($s['scheduled_at']))][] = $s;
}
$cal_first_dow     = (int)date('w',strtotime($month_start));
$cal_days_in_month = (int)date('t',strtotime($month_start));
$site_name = function_exists('get_setting') ? get_setting($conn,'site_name','EdTech Fellowship') : 'EdTech Fellowship';

/* -- A "display venture name" helper for multi-venture sessions -- */
function ms_venture_label(array $session, array $ventures_by_session): string
{
    $rows = $ventures_by_session[(int)$session['id']] ?? [];
    if (!$rows) return $session['venture_name'] ?? '';
    if (count($rows) === 1) return $rows[0]['name'];
    return $rows[0]['name'] . ' +' . (count($rows)-1) . ' more';
}
function ms_mentor_label(array $session, array $mentors_by_session): string
{
    $rows = $mentors_by_session[(int)$session['id']] ?? [];
    $organizer = null;
    foreach ($rows as $r) { if ($r['role']==='organizer') { $organizer = $r; break; } }
    $name = $organizer['full_name'] ?? ($session['mentor_name'] ?? '');
    $extra = count($rows) - 1;
    return $extra > 0 ? "$name +$extra" : $name;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Mentor Calendar -<?= h($site_name) ?> Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="assets/css/admin.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/admin.css') ?>">
  
</head>
<body class="admin-system-page">
<?php include 'includes/sidebar.php'; ?>
<div class="admin-main" id="adminMain">
<header class="admin-topbar">
  <div class="topbar-left">
    <div class="topbar-breadcrumb">
      <a href="index.php">Dashboard</a> &rsaquo; <a href="mentors.php">Mentors</a> &rsaquo; <strong>Calendar</strong>
    </div>
  </div>
  <div class="topbar-right">
    <?php if ($unread_messages): ?>
      <a href="#" onclick="openMsgPanel()" class="btn btn-sm btn-secondary legacy-style-d461c96de5">
        <i class="fa fa-envelope"></i> Messages
        <span class="msg-badge"><?= count($unread_messages) ?></span>
      </a>
    <?php endif; ?>
    <div class="admin-avatar"><div class="avatar-circle"><?= strtoupper(substr($ADMIN['full_name'] ?? 'A',0,1)) ?></div></div>
  </div>
</header>
<div class="admin-content">
<?php show_flash('sessions'); ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Mentor Calendar</h1>
    <p class="page-subtitle">Availability, multi-mentor sessions, venture assignment &amp; messages</p>
  </div>
  <div class="page-actions">
    <a href="mentor-sessions.php" class="btn btn-secondary"><i class="fa fa-list"></i> List view</a>
    <?php if ($CAN_MANAGE || $IS_MENTOR): ?>
    <button class="btn btn-primary" onclick="openScheduleModal()"><i class="fa fa-plus"></i> Schedule session</button>
    <?php endif; ?>
  </div>
</div>

<!-- Pending co-mentor invites for the mentor being viewed -->
<?php if ($my_pending_invites): ?>
<div class="side-card legacy-style-fa8c44db36">
  <div class="side-card-title legacy-style-91ee9a7572"><i class="fa fa-user-plus"></i> Session invites awaiting your response (<?= count($my_pending_invites) ?>)</div>
  <?php foreach ($my_pending_invites as $s):
      $organizer = null;
      foreach (($mentors_by_session[(int)$s['id']] ?? []) as $m) { if ($m['role']==='organizer') { $organizer=$m; break; } }
  ?>
    <div class="req-card">
      <div class="req-card-title"><?= h($s['title']) ?></div>
      <div class="req-card-meta">
        Organized by <?= h($organizer['full_name'] ?? 'a mentor') ?> &middot;
        <?= h(ms_venture_label($s,$ventures_by_session)) ?><br>
        <?= date('M j, Y',strtotime($s['scheduled_at'])) ?> &middot; <?= date('g:i A',strtotime($s['scheduled_at'])) ?>
      </div>
      <div class="req-actions">
        <form method="POST" action="includes/process-mentor-sessions.php" class="legacy-style-cccfa4560d">
          <input type="hidden" name="action" value="respond_invite">
          <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
          <input type="hidden" name="invite_status" value="accepted">
          <button class="btn-accept"><i class="fa fa-check"></i> Accept</button>
        </form>
        <form method="POST" action="includes/process-mentor-sessions.php" class="legacy-style-cccfa4560d">
          <input type="hidden" name="action" value="respond_invite">
          <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
          <input type="hidden" name="invite_status" value="declined">
          <button class="btn-decline"><i class="fa fa-times"></i> Decline</button>
        </form>
        <button class="btn btn-sm" onclick="openDetailModal(<?= (int)$s['id'] ?>)" style="padding:4px 8px;font-size:11px"><i class="fa fa-eye"></i></button>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Tabs -->
<div class="cal-tabs">
  <button class="cal-tab <?= $view==='mentor'?'active':'' ?>" onclick="switchView('mentor')">
    <i class="fa fa-user-tie"></i> Mentor view
  </button>
  <button class="cal-tab <?= $view==='venture'?'active':'' ?>" onclick="switchView('venture')">
    <i class="fa fa-rocket"></i> Venture view
  </button>
  <button class="cal-tab legacy-style-6d00061700" onclick="openMsgPanel()">
    <i class="fa fa-envelope"></i> Venture messages
    <?php if ($unread_messages): ?><span class="msg-badge"><?= count($unread_messages) ?></span><?php endif; ?>
  </button>
</div>

<!-- ------------------------------- MENTOR VIEW --------------- -->
<div id="view-mentor" style="<?= $view==='venture'?'display:none':'' ?>">

  <div class="filter-bar">
    <?php if ($CAN_MANAGE): ?>
    <select onchange="reloadWith('mentor_id',this.value)">
      <?php foreach($all_mentors_list as $r): ?>
        <option value="<?= $r['id'] ?>" <?= $r['id']==$mentor_id?'selected':'' ?>><?= h($r['full_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php else: ?>
      <strong class="legacy-style-5e0faad207"><?= h($mentor['full_name'] ?? '') ?></strong>
    <?php endif; ?>
    <input type="month" value="<?= h($filter_month) ?>" onchange="reloadWith('month',this.value)">
  </div>

  <div class="stat-ribbon">
    <div class="stat-rb"><div class="stat-rb-num legacy-style-2f6d55a210"><?= $stats['requested'] ?></div><div class="stat-rb-lbl">Requested</div></div>
    <div class="stat-rb"><div class="stat-rb-num legacy-style-ef849b9cb1"><?= $stats['confirmed'] ?></div><div class="stat-rb-lbl">Confirmed</div></div>
    <div class="stat-rb"><div class="stat-rb-num legacy-style-5fe5dcb4ef"><?= $stats['completed'] ?></div><div class="stat-rb-lbl">Completed</div></div>
    <div class="stat-rb"><div class="stat-rb-num legacy-style-c84eb7d98c"><?= $stats['cancelled'] ?></div><div class="stat-rb-lbl">Cancelled</div></div>
  </div>

  <div class="legend">
    <div class="leg"><div class="leg-sq legacy-style-c8ce0618ec"></div>Available (green top border)</div>
    <div class="leg"><div class="leg-sq legacy-style-216c8c21c7"></div>Confirmed</div>
    <div class="leg"><div class="leg-sq legacy-style-4d6882ae48"></div>Requested</div>
    <div class="leg"><div class="leg-sq legacy-style-7abeeead8e"></div>Completed</div>
  </div>

  <div class="month-nav">
    <span class="month-nav-title"><?= date('F Y',strtotime($month_start)) ?></span>
    <div class="month-nav-btns">
      <a href="?<?= http_build_query(array_merge($_GET,['month'=>$prev_month,'view'=>'mentor'])) ?>" class="btn btn-secondary btn-sm"><i class="fa fa-chevron-left"></i></a>
      <a href="?<?= http_build_query(array_merge($_GET,['month'=>date('Y-m'),'view'=>'mentor'])) ?>" class="btn btn-secondary btn-sm">Today</a>
      <a href="?<?= http_build_query(array_merge($_GET,['month'=>$next_month,'view'=>'mentor'])) ?>" class="btn btn-secondary btn-sm"><i class="fa fa-chevron-right"></i></a>
    </div>
  </div>

  <div class="cal-layout">
    <!-- Calendar grid -->
    <div>
      <div class="cal-wrap">
        <div class="cal-header">
          <?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
            <div class="cal-hday"><?= $d ?></div>
          <?php endforeach; ?>
        </div>
        <div class="cal-grid" id="mentor-cal-grid">
          <?php
          for($b=0;$b<$cal_first_dow;$b++): $pd=date('Y-m-d',strtotime($month_start.' -'.($cal_first_dow-$b).' days')); ?>
            <div class="cal-cell other"><div class="day-num"><?= (int)date('d',strtotime($pd)) ?></div></div>
          <?php endfor;
          for($day=1;$day<=$cal_days_in_month;$day++):
            $dk=$date_key=date('Y-m',strtotime($month_start)).'-'.str_pad($day,2,'0',STR_PAD_LEFT);
            $is_today=$dk===date('Y-m-d');
            $dow=(int)date('w',strtotime($dk));
            $is_wknd=$dow===0||$dow===6;
            $day_sessions=$sessions_by_date[$dk]??[];
            $day_avail=$avail_blocks[$dk]??[];
            $open_slots=array_filter($day_avail,fn($sl)=>!$sl['blocked']);
            $has_avail=count($open_slots)>0;
            $cls='cal-cell'.($is_today?' today':'').($is_wknd?' weekend':'').($has_avail?' has-avail':'');
          ?>
          <div class="<?= $cls ?>" onclick="selectMentorDay('<?= $dk ?>','<?= date('D j M',strtotime($dk)) ?>')">
            <div class="day-num"><?= $day ?></div>
            <?php if($has_avail && !count($day_sessions)): ?>
              <div class="cal-event legacy-style-dd3640b104"><?= count($open_slots) ?> slot<?= count($open_slots)!==1?'s':'' ?> open</div>
            <?php endif; ?>
            <?php foreach($day_sessions as $s):
              [$sl,$sbg,$stxt]=$status_map[$s['status']]??['-','#F1EFE8','#5F5E5A'];
              $has_cmt = !empty($comments_by_session[(int)$s['id']]);
              $venture_label = ms_venture_label($s,$ventures_by_session);
              $is_pending_invite = ($s['my_role']??'')==='co_mentor' && ($s['my_invite_status']??'')==='invited';
            ?>
              <span class="cal-event <?= $s['status'] ?>"
                    title="<?= h($venture_label) ?> -<?= h($s['title']) ?>"
                    style="<?= $is_pending_invite?'border:1.5px dashed #EF9F27':'' ?>"
                    onclick="event.stopPropagation();openDetailModal(<?= (int)$s['id'] ?>)">
                <?= date('g:ia',strtotime($s['scheduled_at'])) ?> <?= h(mb_strimwidth($venture_label,0,9,'')) ?>
                <?php if($has_cmt): ?><i class="fa fa-comment legacy-style-632ba6ff51"></i><?php endif; ?>
                <?php if($is_pending_invite): ?><i class="fa fa-clock legacy-style-39d75b373f"></i><?php endif; ?>
              </span>
            <?php endforeach; ?>
          </div>
          <?php endfor;
          $trailing=(7-(($cal_first_dow+$cal_days_in_month)%7))%7;
          for($t=0;$t<$trailing;$t++) echo '<div class="cal-cell other"></div>';
          ?>
        </div>
      </div>
    </div>

    <!-- Side panel -->
    <div>
      <!-- Availability slot manager -->
      <div class="side-card">
        <div class="side-card-title" id="slot-panel-title">Select a day to manage slots</div>
        <?php if ($CAN_MANAGE): ?>
          <p class="legacy-style-5cdf9bae21">Click a slot to mark it open, then add a meeting link &mdash; ventures booking that slot will see it.</p>
        <?php endif; ?>
        <div id="slot-grid-wrap" class="slot-list">
          <div class="empty-note">Click any day on the calendar</div>
        </div>

        <input type="hidden" id="selected-date-m" value="">

        <?php if ($CAN_EDIT_AVAIL): ?>
        <div id="slot-save-wrap" style="display:none; margin-top:14px;">
          <button
            type="button"
            id="save-availability-btn"
            class="btn btn-primary"
            onclick="saveAvailability()"
            style="width:100%; display:flex; align-items:center; justify-content:center; gap:8px;"
          >
            <i class="fa fa-save"></i>
            <span>Save availability</span>
          </button>
          <div id="availability-save-status" class="empty-note" style="display:none; margin-top:8px;"></div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Upcoming sessions -->
      <div class="side-card">
        <div class="side-card-title">Upcoming this month</div>
        <?php
        $upcoming=array_filter($sessions,fn($s)=>in_array($s['status'],['confirmed','scheduled','requested']));
        $upcoming=array_slice(array_values($upcoming),0,5);
        if(!$upcoming): ?>
          <div class="empty-note">No upcoming sessions</div>
        <?php else: foreach($upcoming as $s):
          [$sl,$sbg,$stxt]=$status_map[$s['status']]??['-','#F1EFE8','#5F5E5A'];
        ?>
          <div class="session-row legacy-style-3b6a3a65d8" onclick="openDetailModal(<?= (int)$s['id'] ?>)">
            <div class="sr-dot" style="background:<?= $sbg ?>;border:1.5px solid <?= $stxt ?>"></div>
            <div class="sr-body">
              <div class="sr-name"><?= h(ms_venture_label($s,$ventures_by_session)) ?></div>
              <div class="sr-meta"><?= date('M j',strtotime($s['scheduled_at'])) ?> . <?= date('g:i A',strtotime($s['scheduled_at'])) ?></div>
              <div class="sr-meta"><?= h($platform_map[$s['meeting_platform']]??'') ?> . <?= (int)$s['duration_minutes'] ?>min
                <?php $mc=count($mentors_by_session[(int)$s['id']]??[]); if($mc>1): ?> . <?= $mc ?> mentors<?php endif; ?>
              </div>
            </div>
            <span class="badge" style="background:<?= $sbg ?>;color:<?= $stxt ?>"><?= $sl ?></span>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- Pending requests -->
      <?php $pending=array_filter($sessions,fn($s)=>$s['status']==='requested'); ?>
      <?php if($pending): ?>
      <div class="side-card">
        <div class="side-card-title">Pending requests (<?= count($pending) ?>)</div>
        <?php foreach($pending as $s): ?>
          <div class="req-card">
            <div class="req-card-title"><?= h(ms_venture_label($s,$ventures_by_session)) ?></div>
            <div class="req-card-meta"><?= date('M j, Y',strtotime($s['scheduled_at'])) ?> . <?= date('g:i A',strtotime($s['scheduled_at'])) ?><br><?= h($s['title']) ?></div>
            <div class="req-actions">
              <form method="POST" action="includes/process-mentor-sessions.php" class="legacy-style-cccfa4560d">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                <input type="hidden" name="status" value="confirmed">
                <button class="btn-accept"><i class="fa fa-check"></i> Accept</button>
              </form>
              <form method="POST" action="includes/process-mentor-sessions.php" class="legacy-style-cccfa4560d">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                <input type="hidden" name="status" value="cancelled">
                <button class="btn-decline"><i class="fa fa-times"></i> Decline</button>
              </form>
              <button class="btn btn-sm" onclick="openDetailModal(<?= (int)$s['id'] ?>)" style="padding:4px 8px;font-size:11px">
                <i class="fa fa-comment"></i>
              </button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Sessions needing admin rating -->
      <?php $to_rate=array_filter($sessions,fn($s)=>$s['status']==='completed'&&!$s['admin_rating']); ?>
      <?php if($to_rate && $CAN_MANAGE): ?>
      <div class="side-card legacy-style-c8865d20dd">
        <div class="side-card-title legacy-style-91ee9a7572"><i class="fa fa-star"></i> Rate completed sessions</div>
        <?php foreach(array_slice(array_values($to_rate),0,4) as $s): ?>
          <div class="session-row legacy-style-3b6a3a65d8" onclick="openDetailModal(<?= (int)$s['id'] ?>)">
            <div class="sr-body">
              <div class="sr-name"><?= h(ms_venture_label($s,$ventures_by_session)) ?></div>
              <div class="sr-meta"><?= date('M j',strtotime($s['scheduled_at'])) ?></div>
            </div>
            <span class="legacy-style-7d1f19a17b">Rate</span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div><!-- /view-mentor -->


<!-- ------------------------------- VENTURE VIEW -------------- -->
<div id="view-venture" style="<?= $view==='mentor'?'display:none':'' ?>">

  <div class="filter-bar">
    <select onchange="reloadWith('venture_id',this.value,'venture')">
      <?php foreach($all_ventures_list as $r): ?>
        <option value="<?= $r['id'] ?>" <?= $r['id']==$venture_id?'selected':'' ?>><?= h($r['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="month" value="<?= h($filter_month) ?>" onchange="reloadWith('month',this.value,'venture')">
    <?php if ($CAN_MANAGE || $IS_MENTOR): ?>
    <button class="btn btn-primary btn-sm" onclick="openRequestModal()"><i class="fa fa-calendar-plus"></i> Request session</button>
    <?php endif; ?>
  </div>

  <div class="stat-ribbon">
    <?php
    $vstats=['confirmed'=>0,'requested'=>0,'completed'=>0,'cancelled'=>0];
    foreach($venture_sessions as $s){$k=$s['status'];if(isset($vstats[$k]))$vstats[$k]++;if($k==='scheduled')$vstats['confirmed']++;}
    ?>
    <div class="stat-rb"><div class="stat-rb-num legacy-style-ef849b9cb1"><?= $vstats['confirmed'] ?></div><div class="stat-rb-lbl">Confirmed</div></div>
    <div class="stat-rb"><div class="stat-rb-num legacy-style-2f6d55a210"><?= $vstats['requested'] ?></div><div class="stat-rb-lbl">Requested</div></div>
    <div class="stat-rb"><div class="stat-rb-num legacy-style-5fe5dcb4ef"><?= $vstats['completed'] ?></div><div class="stat-rb-lbl">Completed</div></div>
  </div>

  <div class="legend">
    <div class="leg"><div class="leg-sq legacy-style-c8ce0618ec"></div>Mentor open slots</div>
    <div class="leg"><div class="leg-sq legacy-style-216c8c21c7"></div>Confirmed</div>
    <div class="leg"><div class="leg-sq legacy-style-4d6882ae48"></div>Pending request</div>
    <div class="leg"><div class="leg-sq legacy-style-7abeeead8e"></div>Completed</div>
  </div>

  <div class="month-nav">
    <span class="month-nav-title"><?= date('F Y',strtotime($month_start)) ?></span>
    <div class="month-nav-btns">
      <a href="?<?= http_build_query(array_merge($_GET,['month'=>$prev_month,'view'=>'venture'])) ?>" class="btn btn-secondary btn-sm"><i class="fa fa-chevron-left"></i></a>
      <a href="?<?= http_build_query(array_merge($_GET,['month'=>date('Y-m'),'view'=>'venture'])) ?>" class="btn btn-secondary btn-sm">Today</a>
      <a href="?<?= http_build_query(array_merge($_GET,['month'=>$next_month,'view'=>'venture'])) ?>" class="btn btn-secondary btn-sm"><i class="fa fa-chevron-right"></i></a>
    </div>
  </div>

  <div class="cal-layout">
    <div>
      <div class="cal-wrap">
        <div class="cal-header">
          <?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
            <div class="cal-hday"><?= $d ?></div>
          <?php endforeach; ?>
        </div>
        <div class="cal-grid">
          <?php
          for($b=0;$b<$cal_first_dow;$b++) echo '<div class="cal-cell other"></div>';
          for($day=1;$day<=$cal_days_in_month;$day++):
            $dk=date('Y-m',strtotime($month_start)).'-'.str_pad($day,2,'0',STR_PAD_LEFT);
            $is_today=$dk===date('Y-m-d');
            $dow=(int)date('w',strtotime($dk));
            $is_wknd=$dow===0||$dow===6;
            $day_sessions=$venture_by_date[$dk]??[];
            $day_avail=$avail_blocks[$dk]??[];
            $open_slots=array_filter($day_avail,fn($sl)=>!$sl['blocked']);
            $has_avail=count($open_slots)>0&&!count($day_sessions);
            $cls='cal-cell'.($is_today?' today':'').($is_wknd?' weekend':'').($has_avail?' has-avail':'');
          ?>
          <div class="<?= $cls ?>" onclick="selectVentureDay('<?= $dk ?>','<?= date('D j M',strtotime($dk)) ?>')">
            <div class="day-num"><?= $day ?></div>
            <?php if($has_avail): ?>
              <div class="cal-event legacy-style-dd3640b104"><?= count($open_slots) ?> open</div>
            <?php endif; ?>
            <?php foreach($day_sessions as $s):
              [$sl,$sbg,$stxt]=$status_map[$s['status']]??['-','#F1EFE8','#5F5E5A'];
              $mentor_label = ms_mentor_label($s,$mentors_by_session);
            ?>
              <span class="cal-event <?= $s['status'] ?>"
                    title="<?= h($s['title']) ?>"
                    onclick="event.stopPropagation();openDetailModal(<?= (int)$s['id'] ?>)">
                <?= date('g:ia',strtotime($s['scheduled_at'])) ?> <?= h(mb_strimwidth($mentor_label,0,9,'')) ?>
              </span>
            <?php endforeach; ?>
          </div>
          <?php endfor;
          $trailing=(7-(($cal_first_dow+$cal_days_in_month)%7))%7;
          for($t=0;$t<$trailing;$t++) echo '<div class="cal-cell other"></div>'; ?>
        </div>
      </div>
    </div>

    <!-- Venture side panel -->
    <div>
      <div class="side-card">
        <div class="side-card-title" id="v-slot-panel-title">Select a day</div>
        <div id="v-slot-list"><div class="empty-note">Click a day to see open mentor slots</div></div>
      </div>

      <div class="side-card">
        <div class="side-card-title">Sessions this month</div>
        <?php if(!$venture_sessions): ?>
          <div class="empty-note">No sessions this month</div>
        <?php else: foreach($venture_sessions as $s):
          [$sl,$sbg,$stxt]=$status_map[$s['status']]??['-','#F1EFE8','#5F5E5A'];
          $mentor_label = ms_mentor_label($s,$mentors_by_session);
          $init=strtoupper(substr($mentor_label,0,2));
        ?>
          <div class="vs-card legacy-style-3b6a3a65d8" onclick="openDetailModal(<?= (int)$s['id'] ?>)">
            <div class="vs-avatar"><?= h($init) ?></div>
            <div class="sr-body">
              <div class="sr-name"><?= h($s['title']) ?></div>
              <div class="sr-meta"><?= h($mentor_label) ?> . <?= date('M j',strtotime($s['scheduled_at'])) ?></div>
              <span class="badge" style="background:<?= $sbg ?>;color:<?= $stxt ?>;margin-top:3px"><?= $sl ?></span>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div><!-- /view-venture -->

</div><!-- /admin-content -->
</div><!-- /admin-main -->


<div class="modal-overlay" id="scheduleModal">
<div class="modal modal-lg">
  <div class="modal-header">
    <h2 class="modal-title">Schedule Session</h2>
    <button class="modal-close" onclick="closeM('scheduleModal')">x</button>
  </div>
  <form method="POST" action="includes/process-mentor-sessions.php" id="schedule-form">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="all_ventures" id="sched-all-ventures-flag" value="0">
    <div class="modal-body">
      <div class="form-grid form-grid-2">
        <div class="form-group full"><label>Session title <span class="req">*</span></label><input type="text" name="title" class="form-control" required placeholder="e.g. Go-to-market strategy review"></div>

        <?php if ($CAN_MANAGE): ?>
        <div class="form-group full">
          <label>Organizing mentor <span class="req">*</span></label>
          <select name="mentor_id" class="form-control" required>
            <?php foreach($all_mentors_list as $r): ?>
              <option value="<?= $r['id'] ?>" <?= $r['id']==$mentor_id?'selected':'' ?>><?= h($r['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php else: ?>
          <input type="hidden" name="mentor_id" value="<?= $MY_MENTOR_ID ?>">
        <?php endif; ?>

        <!-- Co-mentor invite picker -->
        <div class="form-group full">
          <label>Invite co-mentors <span class="legacy-style-64865bc1f0">(optional - they'll need to accept)</span></label>
          <div class="ms-picker" id="sched-mentor-picker">
            <?php foreach($all_mentors_list as $r): ?>
              <label class="ms-picker-row" data-mentor-row="<?= $r['id'] ?>">
                <input type="checkbox" class="sched-co-mentor-cb" value="<?= $r['id'] ?>" data-name="<?= h($r['full_name']) ?>" onchange="renderCoMentorTags()">
                <?= h($r['full_name']) ?>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="ms-tag-list" id="sched-co-mentor-tags"></div>
          <div id="sched-co-mentor-hidden-inputs"></div>
        </div>

        <!-- Venture picker -->
        <div class="form-group full">
          <label>Ventures <span class="req">*</span></label>
          <div class="all-ventures-toggle">
            <input type="checkbox" id="sched-all-ventures-cb" onchange="toggleAllVentures()"> All ventures in the programme
          </div>
          <div class="ms-picker" id="sched-venture-picker">
            <?php foreach($all_ventures_list as $r): ?>
              <label class="ms-picker-row" data-venture-row="<?= $r['id'] ?>">
                <input type="checkbox" class="sched-venture-cb" value="<?= $r['id'] ?>" data-name="<?= h($r['name']) ?>" onchange="renderVentureTags()">
                <?= h($r['name']) ?>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="ms-tag-list" id="sched-venture-tags"></div>
          <div id="sched-venture-hidden-inputs"></div>
        </div>

        <div class="form-group"><label>Date &amp; time</label><input type="datetime-local" name="scheduled_at" id="sched-dt" class="form-control"></div>
        <div class="form-group"><label>Duration (min)</label><input type="number" name="duration_minutes" class="form-control" value="60" min="15" step="15"></div>
        <div class="form-group"><label>Platform</label>
          <select name="meeting_platform" class="form-control">
            <option value="zoom">Zoom</option><option value="google_meet">Google Meet</option>
            <option value="teams">MS Teams</option><option value="phone">Phone</option><option value="in_person">In Person</option>
          </select>
        </div>
        <div class="form-group"><label>Meeting link</label><input type="text" name="meeting_link" class="form-control" placeholder="https://..."></div>
        <div class="form-group full"><label>Agenda / description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
        <div class="form-group"><label class="legacy-style-c92fd9467d"><input type="checkbox" name="send_reminder" value="1" checked> Send reminder emails</label></div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeM('scheduleModal')">Cancel</button>
      <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Schedule</button>
    </div>
  </form>
</div>
</div>

<!-- ------------------------------------------------------------
     MODAL: Request session (venture side -> admin proxy)
------------------------------------------------------------- -->
<div class="modal-overlay" id="requestModal">
<div class="modal modal-lg">
  <div class="modal-header">
    <h2 class="modal-title">Request a Session</h2>
    <button class="modal-close" onclick="closeM('requestModal')">x</button>
  </div>
  <form method="POST" action="includes/process-mentor-sessions.php">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="status" value="requested">
    <input type="hidden" name="venture_id[]" value="<?= $venture_id ?>">
    <div class="modal-body">
      <div class="form-grid form-grid-2">
        <div class="form-group full"><label>Session title <span class="req">*</span></label><input type="text" name="title" class="form-control" required placeholder="What does the venture need help with?"></div>
        <div class="form-group"><label>Mentor <span class="req">*</span></label>
          <select name="mentor_id" class="form-control" required>
            <option value="">Select mentor</option>
            <?php foreach($all_mentors_list as $r): ?>
              <option value="<?= $r['id'] ?>" <?= $r['id']==$mentor_id?'selected':'' ?>><?= h($r['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Preferred date &amp; time</label><input type="datetime-local" name="scheduled_at" id="req-dt" class="form-control"></div>
        <div class="form-group"><label>Duration (min)</label><input type="number" name="duration_minutes" class="form-control" value="60" min="15" step="15"></div>
        <div class="form-group"><label>Session type</label>
          <select name="session_type" class="form-control">
            <option value="one_on_one">1-on-1</option><option value="group">Group</option>
            <option value="review">Review</option><option value="workshop">Workshop</option>
          </select>
        </div>
        <div class="form-group"><label>Platform</label>
          <select name="meeting_platform" class="form-control">
            <option value="zoom">Zoom</option><option value="google_meet">Google Meet</option>
            <option value="teams">MS Teams</option><option value="phone">Phone</option><option value="in_person">In Person</option>
          </select>
        </div>
        <div class="form-group full"><label>Description</label><textarea name="description" class="form-control" rows="3" placeholder="Topics to cover..."></textarea></div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeM('requestModal')">Cancel</button>
      <button type="submit" class="btn btn-primary"><i class="fa fa-paper-plane"></i> Send request</button>
    </div>
  </form>
</div>
</div>


<!-- ------------------------------------------------------------
     MODAL: Session detail + Attendees + Comments + Rating
------------------------------------------------------------- -->
<div class="modal-overlay" id="detailModal">
<div class="modal modal-lg">
  <div class="modal-header">
    <h2 class="modal-title" id="dm-title">Session</h2>
    <button class="modal-close" onclick="closeM('detailModal')">x</button>
  </div>
  <div class="modal-body">

    <!-- Session info -->
    <div class="detail-section" id="dm-info"></div>

    <!-- Attendees: mentors -->
    <div class="detail-section">
      <div class="detail-section-title"><i class="fa fa-user-tie"></i> Mentors</div>
      <div id="dm-mentor-chips"></div>
      <?php if ($CAN_MANAGE || $IS_MENTOR): ?>
      <div class="legacy-style-8a77e5a311">
        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAddMentorBox()"><i class="fa fa-user-plus"></i> Invite another mentor</button>
        <div id="dm-add-mentor-box" class="legacy-style-9714b43b47">
          <form method="POST" action="includes/process-mentor-sessions.php" class="legacy-style-49cd09213e">
            <input type="hidden" name="action" value="invite_mentor">
            <input type="hidden" name="session_id" id="dm-invite-session-id" value="">
            <select name="mentor_id" class="form-control legacy-style-5a95af4fdc" id="dm-invite-mentor-select" required>
              <option value="">Select mentor to invite</option>
              <?php foreach($all_mentors_list as $r): ?>
                <option value="<?= $r['id'] ?>"><?= h($r['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-primary btn-sm"><i class="fa fa-paper-plane"></i></button>
          </form>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Attendees: ventures -->
    <div class="detail-section">
      <div class="detail-section-title"><i class="fa fa-rocket"></i> Ventures</div>
      <div id="dm-venture-chips"></div>
      <?php if ($CAN_MANAGE): ?>
      <div class="legacy-style-8a77e5a311">
        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAddVentureBox()"><i class="fa fa-plus"></i> Add another venture</button>
        <div id="dm-add-venture-box" class="legacy-style-9714b43b47">
          <form method="POST" action="includes/process-mentor-sessions.php" class="legacy-style-49cd09213e">
            <input type="hidden" name="action" value="add_venture">
            <input type="hidden" name="session_id" id="dm-addv-session-id" value="">
            <select name="venture_id" class="form-control legacy-style-5a95af4fdc" required>
              <option value="">Select venture to add</option>
              <?php foreach($all_ventures_list as $r): ?>
                <option value="<?= $r['id'] ?>"><?= h($r['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-primary btn-sm"><i class="fa fa-plus"></i></button>
          </form>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Admin rating section -->
    <div class="detail-section" id="dm-rating-section">
      <div class="detail-section-title"><i class="fa fa-star legacy-style-6a6a237e2c"></i> Admin rating</div>
      <div id="dm-existing-rating"></div>
      <div id="dm-rating-form">
        <div class="admin-rating-picker" id="admin-star-picker" onclick="adminPickStar(event)">
          <i class="far fa-star" data-v="1"></i>
          <i class="far fa-star" data-v="2"></i>
          <i class="far fa-star" data-v="3"></i>
          <i class="far fa-star" data-v="4"></i>
          <i class="far fa-star" data-v="5"></i>
        </div>
        <input type="hidden" id="admin-star-value" value="0">
        <form method="POST" action="includes/process-mentor-sessions.php" id="admin-rate-form">
          <input type="hidden" name="action" value="admin_rate_session">
          <input type="hidden" name="id" id="admin-rate-session-id" value="">
          <input type="hidden" name="admin_rating" id="admin-rate-hidden" value="0">
          <textarea name="admin_notes" class="form-control legacy-style-1bd9332586" rows="2" placeholder="Internal notes on this session..."></textarea>
          <button type="submit" class="btn btn-primary btn-sm" id="admin-rate-btn" disabled>
            <i class="fa fa-star"></i> Save rating &amp; notes
          </button>
        </form>
      </div>
    </div>

    <!-- Venture rating display -->
    <div class="detail-section" id="dm-venture-rating">
      <div class="detail-section-title"><i class="fa fa-star legacy-style-6a6a237e2c"></i> Venture rating</div>
      <div id="dm-venture-rating-body"></div>
    </div>

    <!-- Comments / notes thread -->
    <div class="detail-section">
      <div class="detail-section-title"><i class="fa fa-comments"></i> Session comments &amp; notes</div>
      <div class="comment-thread" id="dm-comments"></div>
      <form method="POST" action="includes/process-session-comments.php" class="comment-form">
        <input type="hidden" name="action" value="add_comment">
        <input type="hidden" name="session_id" id="dm-comment-session-id" value="">
        <input type="hidden" name="author_type" value="<?= $IS_MENTOR ? 'mentor' : 'admin' ?>">
        <textarea name="comment_text" placeholder="Add a note or message visible to other attendees..." rows="2"></textarea>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-paper-plane"></i></button>
      </form>
    </div>

    <!-- Reply to venture message -->
    <div class="detail-section legacy-style-6b99de8b69" id="dm-reply-section">
      <div class="detail-section-title"><i class="fa fa-envelope"></i> Message venture(s)</div>
      <form method="POST" action="includes/process-mentor-sessions.php">
        <input type="hidden" name="action" value="send_session_message">
        <input type="hidden" name="session_id" id="dm-msg-session-id" value="">
        <textarea name="message" class="form-control legacy-style-1bd9332586" rows="2" placeholder="Send a message to the venture(s) about this session..."></textarea>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-paper-plane"></i> Send to venture(s)</button>
      </form>
    </div>

  </div>
  <div class="modal-footer" id="dm-footer">
    <button class="btn btn-secondary" onclick="closeM('detailModal')">Close</button>
    <a id="dm-edit-btn" href="#" class="btn btn-primary"><i class="fa fa-edit"></i> Edit session</a>
  </div>
</div>
</div>

<!-- ------------------------------------------------------------
     MODAL: Venture messages panel
------------------------------------------------------------- -->
<div class="modal-overlay" id="msgPanelModal">
<div class="modal modal-lg">
  <div class="modal-header">
    <h2 class="modal-title"><i class="fa fa-envelope legacy-style-118e1505a3"></i> Venture messages</h2>
    <button class="modal-close" onclick="closeM('msgPanelModal')">x</button>
  </div>
  <div class="modal-body">
    <?php if(!$unread_messages): ?>
      <div class="empty-note"><i class="fa fa-check-circle legacy-style-7b18418069"></i>No unread messages</div>
    <?php else: ?>
      <p class="legacy-style-fc7a2f23a3"><?= count($unread_messages) ?> unread message<?= count($unread_messages)!==1?'s':'' ?> from ventures</p>
      <?php foreach($unread_messages as $m): ?>
        <div class="msg-item unread" onclick="openMsgDetail(<?= (int)$m['id'] ?>)">
          <div class="legacy-style-1fadc7d7b3">
            <div class="legacy-style-59eddc679e">
              <div class="msg-venture"><?= h($m['venture_name']) ?></div>
              <div class="msg-subject"><?= h($m['subject']) ?></div>
              <div class="msg-preview"><?= h(mb_strimwidth(strip_tags($m['body']),0,80,'')) ?></div>
            </div>
            <div class="msg-time"><?= date('M j, g:i A',strtotime($m['created_at'])) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <div class="modal-footer">
    <button class="btn btn-secondary" onclick="closeM('msgPanelModal')">Close</button>
    <a href="messages.php" class="btn btn-primary"><i class="fa fa-external-link-alt"></i> Open messages</a>
  </div>
</div>
</div>

<!-- ------------------------------------------------------------
     MODAL: Single message detail + admin reply
------------------------------------------------------------- -->
<div class="modal-overlay" id="msgDetailModal">
<div class="modal modal-lg">
  <div class="modal-header">
    <h2 class="modal-title" id="msg-detail-title">Message</h2>
    <button class="modal-close" onclick="closeM('msgDetailModal')">x</button>
  </div>
  <div class="modal-body">
    <div id="msg-detail-body" class="legacy-style-68c6a44dc2"></div>
    <div class="detail-section-title legacy-style-fdf33f2304"><i class="fa fa-reply"></i> Reply to venture</div>
    <form method="POST" action="includes/process-mentor-messages.php">
      <input type="hidden" name="action" value="admin_reply_venture">
      <input type="hidden" name="message_id" id="msg-reply-id" value="">
      <textarea name="reply" class="form-control legacy-style-fdf33f2304" rows="3" placeholder="Write your reply..."></textarea>
      <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-paper-plane"></i> Send reply</button>
    </form>
  </div>
  <div class="modal-footer">
    <button class="btn btn-secondary" onclick="closeM('msgDetailModal')">Close</button>
    <a href="mentor-messages.php" class="btn btn-secondary btn-sm"><i class="fa fa-external-link-alt"></i> Full thread</a>
  </div>
</div>
</div>


<!-- ------------------------------------------------------------
     DATA + JS
------------------------------------------------------------- -->
<script>
const AVAIL_DATA        = <?= json_encode($avail_blocks, JSON_HEX_TAG) ?>;
const ALL_SLOTS         = <?= json_encode($SLOTS) ?>;
const COMMENTS_DATA     = <?= json_encode($comments_by_session, JSON_HEX_TAG) ?>;
const MESSAGES_DATA     = <?= json_encode(array_values($unread_messages), JSON_HEX_TAG) ?>;
const INVITE_STATUS_MAP = <?= json_encode($invite_status_map, JSON_HEX_TAG) ?>;
const CAN_MANAGE        = <?= $CAN_MANAGE ? 'true' : 'false' ?>;
const IS_MENTOR         = <?= $IS_MENTOR ? 'true' : 'false' ?>;
const MY_MENTOR_ID      = <?= (int)$MY_MENTOR_ID ?>;
const CAN_EDIT_AVAIL    = <?= $CAN_EDIT_AVAIL ? 'true' : 'false' ?>;
const VIEWED_MENTOR_ID  = <?= (int)$mentor_id ?>;

const MENTORS_BY_SESSION  = <?= json_encode(
    array_map(fn($rows) => array_map(fn($r) => [
        'mentor_id'     => (int)$r['mentor_id'],
        'full_name'     => $r['full_name'],
        'role'          => $r['role'],
        'invite_status' => $r['invite_status'],
    ], $rows), $mentors_by_session),
    JSON_HEX_TAG
) ?>;
const VENTURES_BY_SESSION = <?= json_encode(
    array_map(fn($rows) => array_map(fn($r) => [
        'venture_id' => (int)$r['venture_id'],
        'name'       => $r['name'],
    ], $rows), $ventures_by_session),
    JSON_HEX_TAG
) ?>;

const SESSION_DATA = <?= json_encode(array_values(array_map(function($s) use ($status_map,$platform_map){
    [$sl,$sbg,$stxt] = $status_map[$s['status']] ?? ['-','#F1EFE8','#5F5E5A'];
    return [
        'id'             => (int)$s['id'],
        'title'          => $s['title']            ?? '',
        'status'         => $s['status'],
        'status_label'   => $sl,
        'status_bg'      => $sbg,
        'status_txt'     => $stxt,
        'scheduled_at'   => $s['scheduled_at']     ?? '',
        'duration'       => (int)$s['duration_minutes'],
        'platform'       => $platform_map[$s['meeting_platform'] ?? ''] ?? ($s['meeting_platform'] ?? ''),
        'meeting_link'   => $s['meeting_link']     ?? '',
        'agenda'         => $s['description']      ?? '',
        'notes_shared'   => $s['notes_shared']     ?? '',
        'action_items'   => $s['action_items']     ?? '',
        'venture_rating' => (int)($s['venture_rating']  ?? 0),
        'venture_feedback'=> $s['venture_feedback'] ?? '',
        'admin_rating'   => (int)($s['admin_rating']    ?? 0),
        'admin_notes'    => $s['admin_notes']       ?? '',
    ];
}, array_merge($sessions, $venture_sessions))), JSON_HEX_TAG) ?>;
</script>

<script>
/* -- sidebar --------------------------------------------------- */
document.getElementById('sidebarToggle')?.addEventListener('click',()=>{
    document.getElementById('adminSidebar')?.classList.toggle('collapsed');
    document.getElementById('adminMain')?.classList.toggle('collapsed');
});

/* -- view switch ----------------------------------------------- */
function switchView(v){
    document.getElementById('view-mentor').style.display  = v==='mentor'?'':'none';
    document.getElementById('view-venture').style.display = v==='venture'?'':'none';
    document.querySelectorAll('.cal-tab').forEach((t,i)=>t.classList.toggle('active',i===(v==='mentor'?0:1)));
}

/* -- URL reload ------------------------------------------------ */
function reloadWith(key,val,view){
    const u=new URL(location.href);
    u.searchParams.set(key,val);
    if(view) u.searchParams.set('view',view);
    location.href=u.toString();
}

/* -- Modal helpers --------------------------------------------- */
function closeM(id){ document.getElementById(id).classList.remove('open'); }
function openM(id) { document.getElementById(id).classList.add('open'); }
document.querySelectorAll('.modal-overlay').forEach(m=>{
    m.addEventListener('click',e=>{if(e.target===m) m.classList.remove('open');});
});
function openScheduleModal(date=''){
    if(date) document.getElementById('sched-dt').value=date+'T09:00';
    resetSchedulePickers();
    openM('scheduleModal');
}
function openRequestModal(date='',slot=''){
    if(date) document.getElementById('req-dt').value=date+'T'+(slot||'09:00');
    openM('requestModal');
}
function openMsgPanel(){ openM('msgPanelModal'); }

/* ---------------------------------------------------------------
   Schedule modal: multi-select mentor / venture pickers
--------------------------------------------------------------- */
function resetSchedulePickers(){
    document.querySelectorAll('.sched-co-mentor-cb').forEach(cb=>cb.checked=false);
    document.querySelectorAll('.sched-venture-cb').forEach(cb=>cb.checked=false);
    document.getElementById('sched-all-ventures-cb').checked=false;
    document.getElementById('sched-venture-picker').style.display='';
    document.getElementById('sched-all-ventures-flag').value='0';
    renderCoMentorTags();
    renderVentureTags();
}

function renderCoMentorTags(){
    const checked=[...document.querySelectorAll('.sched-co-mentor-cb:checked')];
    const tagWrap=document.getElementById('sched-co-mentor-tags');
    const hiddenWrap=document.getElementById('sched-co-mentor-hidden-inputs');
    tagWrap.innerHTML=checked.map(cb=>`
        <span class="ms-tag">${esc(cb.dataset.name)}
          <button type="button" onclick="uncheckCoMentor('${cb.value}')"><i class="fa fa-times"></i></button>
        </span>`).join('');
    hiddenWrap.innerHTML=checked.map(cb=>`<input type="hidden" name="co_mentor_ids[]" value="${cb.value}">`).join('');
}
function uncheckCoMentor(id){
    const cb=document.querySelector(`.sched-co-mentor-cb[value="${id}"]`);
    if(cb){ cb.checked=false; renderCoMentorTags(); }
}

function renderVentureTags(){
    const checked=[...document.querySelectorAll('.sched-venture-cb:checked')];
    const tagWrap=document.getElementById('sched-venture-tags');
    const hiddenWrap=document.getElementById('sched-venture-hidden-inputs');
    tagWrap.innerHTML=checked.map(cb=>`
        <span class="ms-tag">${esc(cb.dataset.name)}
          <button type="button" onclick="uncheckVenture('${cb.value}')"><i class="fa fa-times"></i></button>
        </span>`).join('');
    hiddenWrap.innerHTML=checked.map(cb=>`<input type="hidden" name="venture_id[]" value="${cb.value}">`).join('');
}
function uncheckVenture(id){
    const cb=document.querySelector(`.sched-venture-cb[value="${id}"]`);
    if(cb){ cb.checked=false; renderVentureTags(); }
}
function toggleAllVentures(){
    const all=document.getElementById('sched-all-ventures-cb').checked;
    document.getElementById('sched-venture-picker').style.display=all?'none':'';
    document.getElementById('sched-all-ventures-flag').value=all?'1':'0';
    if(all){
        document.querySelectorAll('.sched-venture-cb').forEach(cb=>cb.checked=false);
        renderVentureTags();
        document.getElementById('sched-venture-tags').innerHTML='<span class="ms-tag organizer-tag"><i class="fa fa-globe"></i> All ventures in the programme</span>';
    } else {
        document.getElementById('sched-venture-tags').innerHTML='';
    }
}

/* -- Venture message detail ------------------------------------ */
function openMsgDetail(id){
    const m=MESSAGES_DATA.find(x=>x.id===id);
    if(!m) return;
    document.getElementById('msg-detail-title').textContent=m.subject||'Message';
    document.getElementById('msg-reply-id').value=id;
    document.getElementById('msg-detail-body').innerHTML=`
        <div class="legacy-style-fdf33f2304"><span class="legacy-style-919ae42573">From:</span> <strong>${esc(m.venture_name)}</strong>
        <span class="legacy-style-527e4c220d">${esc(new Date(m.created_at.replace(' ','T')).toLocaleString('en-GB'))}</span></div>
        <div class="legacy-style-cf400c0204">${esc(m.body)}</div>`;
    closeM('msgPanelModal');
    openM('msgDetailModal');
}

/* -- Session detail modal -------------------------------------- */
function openDetailModal(id){
    const s=SESSION_DATA.find(x=>x.id===id);
    if(!s) return;

    const mentors  = MENTORS_BY_SESSION[id]  || [];
    const ventures = VENTURES_BY_SESSION[id] || [];

    document.getElementById('dm-title').textContent=s.title||'Session details';
    document.getElementById('admin-rate-session-id').value=id;
    document.getElementById('dm-comment-session-id').value=id;
    document.getElementById('dm-msg-session-id').value=id;
    document.getElementById('dm-invite-session-id').value=id;
    document.getElementById('dm-addv-session-id') && (document.getElementById('dm-addv-session-id').value=id);
    document.getElementById('dm-add-mentor-box').style.display='none';
    document.getElementById('dm-add-venture-box') && (document.getElementById('dm-add-venture-box').style.display='none');

    const ventureNames=ventures.map(v=>v.name).join(', ')||'TBD';

    // -- Info block
    const dt=s.scheduled_at?new Date(s.scheduled_at.replace(' ','T')):null;
    const dtStr=dt?dt.toLocaleDateString('en-GB',{weekday:'short',day:'numeric',month:'short',year:'numeric'})+' at '+dt.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'}):'TBD';
    document.getElementById('dm-info').innerHTML=`
        <div class="detail-row"><span class="detail-lbl">Venture(s)</span><span><strong>${esc(ventureNames)}</strong></span></div>
        <div class="detail-row"><span class="detail-lbl">Status</span><span><span class="badge legacy-style-c577437f75">${esc(s.status_label)}</span></span></div>
        <div class="detail-row"><span class="detail-lbl">Date &amp; time</span><span class="legacy-style-6cb285c61d">${esc(dtStr)}</span></div>
        <div class="detail-row"><span class="detail-lbl">Duration</span><span>${s.duration} min . ${esc(s.platform)}</span></div>
        ${s.meeting_link?`<div class="detail-row"><span class="detail-lbl">Link</span><span><a href="${esc(s.meeting_link)}" target="_blank" class="legacy-style-118e1505a3">${esc(s.meeting_link)}</a></span></div>`:''}
        ${s.agenda?`<div class="detail-row"><span class="detail-lbl">Agenda</span><span class="legacy-style-6cb285c61d">${esc(s.agenda)}</span></div>`:''}
        ${s.notes_shared?`<div class="detail-row"><span class="detail-lbl">Shared notes</span><span class="legacy-style-a55150f6ce">${esc(s.notes_shared)}</span></div>`:''}
        ${s.action_items?`<div class="detail-row"><span class="detail-lbl">Action items</span><span class="legacy-style-6cb285c61d">${esc(s.action_items).replace(/\n/g,'<br>')}</span></div>`:''}`;

    // -- Mentor chips (organizer + co-mentors with invite status)
    document.getElementById('dm-mentor-chips').innerHTML = mentors.length
        ? mentors.map(m=>{
            const isOrganizer = m.role==='organizer';
            const st = INVITE_STATUS_MAP[m.invite_status] || ['', '#F1EFE8','#5F5E5A'];
            const bg = isOrganizer ? '#E1F5EE' : st[1];
            const tx = isOrganizer ? '#085041' : st[2];
            const label = isOrganizer ? 'Organizer' : st[0];
            return `<span class="attendee-chip legacy-style-cf4de0436b">
                       <i class="fa ${isOrganizer?'fa-star':'fa-user'}"></i> ${esc(m.full_name)} &middot; ${esc(label)}
                    </span>`;
          }).join('')
        : '<div class="empty-note">No mentors on this session</div>';

    // -- Venture chips
    document.getElementById('dm-venture-chips').innerHTML = ventures.length
        ? ventures.map(v=>`<span class="attendee-chip legacy-style-b7cf1e8dd5"><i class="fa fa-rocket"></i> ${esc(v.name)}</span>`).join('')
        : '<div class="empty-note">No ventures on this session</div>';

    // -- Admin rating
    resetAdminStars();
    document.getElementById('admin-star-value').value='0';
    document.getElementById('admin-rate-hidden').value='0';
    document.getElementById('admin-rate-btn').disabled=true;
    if(s.admin_rating>0){
        document.getElementById('dm-existing-rating').innerHTML=`<div class="star-row">${starHTML(s.admin_rating)}</div>${s.admin_notes?`<p class="legacy-style-b901fbde45">${esc(s.admin_notes)}</p>`:''}`;
        document.getElementById('dm-rating-form').style.display='none';
    } else {
        document.getElementById('dm-existing-rating').innerHTML='<p class="legacy-style-0ab96318d6">Not yet rated</p>';
        document.getElementById('dm-rating-form').style.display = CAN_MANAGE ? '' : 'none';
    }
    document.getElementById('dm-rating-section').style.display = CAN_MANAGE ? '' : 'none';

    // -- Venture rating
    if(s.venture_rating>0){
        document.getElementById('dm-venture-rating-body').innerHTML=`<div class="star-row">${starHTML(s.venture_rating)}</div>${s.venture_feedback?`<p class="legacy-style-3b1009881d">"${esc(s.venture_feedback)}"</p>`:''}`;
        document.getElementById('dm-venture-rating').style.display='';
    } else {
        document.getElementById('dm-venture-rating').style.display='none';
    }

    // -- Comments
    const cmts=(COMMENTS_DATA[id]||[]);
    const cmtHtml=cmts.length?cmts.map(c=>`
        <div class="comment-bubble ${esc(c.author_type||'admin')}">
          <div class="cb-inner">
            <div class="cb-author">${esc(c.display_name||c.author_name||'Admin')}</div>
            <div class="cb-text">${esc(c.comment_text||'').replace(/\n/g,'<br>')}</div>
            <div class="cb-time">${esc(new Date((c.created_at||'').replace(' ','T')).toLocaleString('en-GB',{day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'}))}</div>
          </div>
        </div>`).join(''):'<p class="legacy-style-a7a29563ea">No comments yet. Add the first note.</p>';
    document.getElementById('dm-comments').innerHTML=cmtHtml;
    document.getElementById('dm-comments').scrollTop=9999;

    // -- Message venture link (managers only)
    document.getElementById('dm-reply-section').style.display = CAN_MANAGE ? '' : 'none';

    // -- Edit link
    document.getElementById('dm-edit-btn').href='mentor-sessions.php';

    openM('detailModal');
}

function toggleAddMentorBox(){
    const box=document.getElementById('dm-add-mentor-box');
    box.style.display = box.style.display==='none' ? '' : 'none';
}
function toggleAddVentureBox(){
    const box=document.getElementById('dm-add-venture-box');
    if(box) box.style.display = box.style.display==='none' ? '' : 'none';
}

/* -- Admin star rating ----------------------------------------- */
function adminPickStar(e){
    const star=e.target.closest('i[data-v]');
    if(!star) return;
    const val=parseInt(star.dataset.v,10);
    document.getElementById('admin-star-value').value=val;
    document.getElementById('admin-rate-hidden').value=val;
    document.getElementById('admin-rate-btn').disabled=false;
    document.querySelectorAll('#admin-star-picker i').forEach((s,i)=>{
        s.className=(i<val)?'fas fa-star lit':'far fa-star';
    });
}
function resetAdminStars(){
    document.querySelectorAll('#admin-star-picker i').forEach(s=>s.className='far fa-star');
}

/* -- Star HTML helper ------------------------------------------ */
function starHTML(n){
    return Array.from({length:5},(_,i)=>
        `<i class="${i<n?'fas':'far'} fa-star ${i<n?'lit':''}" class="legacy-style-38f489940f"></i>`
    ).join('');
}

/* -- Availability ---------------------------------------------- */
let localAvail = JSON.parse(JSON.stringify(AVAIL_DATA || {}));

function normTime(t){
    return (t || '').toString().slice(0,5);
}

function findSlotEntry(dateKey, slotTime){
    const want = normTime(slotTime);
    return (localAvail[dateKey] || []).find(
        s => normTime(s.slot_time || s.time) === want
    );
}

function ensureDateArray(dateKey){
    if(!Array.isArray(localAvail[dateKey])){
        localAvail[dateKey] = [];
    }
    return localAvail[dateKey];
}

function selectMentorDay(dateKey,label){
    const dateInput = document.getElementById('selected-date-m');
    const title = document.getElementById('slot-panel-title');
    const wrap = document.getElementById('slot-grid-wrap');
    const saveWrap = document.getElementById('slot-save-wrap');

    if(dateInput) dateInput.value = dateKey;
    if(title) title.textContent = label;

    if(saveWrap){
        saveWrap.style.display = CAN_EDIT_AVAIL ? 'block' : 'none';
    }

    const entries = ensureDateArray(dateKey);

    wrap.innerHTML = ALL_SLOTS.map(t => {
        const entry = findSlotEntry(dateKey,t);
        const isOn = !!entry && !entry.blocked;
        const linkVal = entry && entry.meeting_link ? entry.meeting_link : '';

        const linkRowHtml = CAN_EDIT_AVAIL ? `
            <div class="slot-link-wrap" style="${isOn ? '' : 'display:none;'}">
              <div class="slot-link-row">
                <i class="fa fa-link"></i>
                <input
                  type="url"
                  class="slot-link-input"
                  placeholder="Meeting link for this slot (optional)"
                  data-slot="${t}"
                  value="${esc(linkVal)}"
                  onclick="event.stopPropagation()"
                  oninput="onSlotLinkInput('${dateKey}','${t}',this.value)"
                >
              </div>
            </div>` : '';

        return `
          <div class="slot-cell${isOn ? ' on' : ''}" data-slot="${t}">
            <button
              type="button"
              class="slot-btn"
              data-slot="${t}"
              ${CAN_EDIT_AVAIL ? `onclick="toggleSlot('${dateKey}','${t}',this)"` : 'disabled'}
            >
              <span>${fmtTime(t)}</span>
              <span class="slot-btn-check">${isOn ? '<i class="fa fa-check"></i>' : ''}</span>
            </button>
            ${linkRowHtml}
          </div>`;
    }).join('');

    document.querySelectorAll('#mentor-cal-grid .cal-cell')
        .forEach(c => c.classList.remove('selected'));

    // Mark the clicked date cell visually where possible.
    document.querySelectorAll('#mentor-cal-grid .cal-cell').forEach(cell => {
        const clickAttr = cell.getAttribute('onclick') || '';
        if(clickAttr.includes(dateKey)){
            cell.classList.add('selected');
        }
    });
}

function toggleSlot(dateKey,slotTime,btn){
    if(!CAN_EDIT_AVAIL) return;

    const dayEntries = ensureDateArray(dateKey);
    const want = normTime(slotTime);
    const idx = dayEntries.findIndex(
        s => normTime(s.slot_time || s.time) === want
    );

    const cell = btn.closest('.slot-cell');
    const checkSpan = btn.querySelector('.slot-btn-check');
    const linkWrap = cell ? cell.querySelector('.slot-link-wrap') : null;
    const linkInput = cell ? cell.querySelector('.slot-link-input') : null;

    if(idx >= 0){
        dayEntries.splice(idx,1);
        cell?.classList.remove('on');
        if(checkSpan) checkSpan.innerHTML = '';
        if(linkWrap) linkWrap.style.display = 'none';
    } else {
        dayEntries.push({
            time: want,
            slot_time: want + ':00',
            blocked: false,
            meeting_link: linkInput ? linkInput.value.trim() : ''
        });
        cell?.classList.add('on');
        if(checkSpan) checkSpan.innerHTML = '<i class="fa fa-check"></i>';
        if(linkWrap) linkWrap.style.display = '';
        if(linkInput) linkInput.focus();
    }
}

function onSlotLinkInput(dateKey,slotTime,value){
    if(!CAN_EDIT_AVAIL) return;

    const entry = findSlotEntry(dateKey,slotTime);
    if(entry){
        entry.meeting_link = value.trim();
    }
}

function setAvailabilitySaving(isSaving, message = ''){
    const btn = document.getElementById('save-availability-btn');
    const status = document.getElementById('availability-save-status');

    if(btn){
        btn.disabled = isSaving;
        btn.innerHTML = isSaving
            ? '<i class="fa fa-spinner fa-spin"></i><span>Saving...</span>'
            : '<i class="fa fa-save"></i><span>Save availability</span>';
    }

    if(status){
        if(message){
            status.textContent = message;
            status.style.display = 'block';
        } else {
            status.style.display = 'none';
        }
    }
}

function saveAvailability(){
    if(!CAN_EDIT_AVAIL){
        alert('You do not have permission to edit this availability.');
        return;
    }

    const dateKey = document.getElementById('selected-date-m')?.value || '';
    if(!dateKey){
        alert('Please select a calendar day first.');
        return;
    }

    const entries = (localAvail[dateKey] || [])
        .filter(s => !s.blocked)
        .map(s => ({
            time: normTime(s.slot_time || s.time),
            meeting_link: (s.meeting_link || '').trim()
        }))
        .filter(s => /^\d{2}:\d{2}$/.test(s.time));

    setAvailabilitySaving(true, '');

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'includes/process-mentor-availability.php';
    form.style.display = 'none';

    const addField = (name, value) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = String(value ?? '');
        form.appendChild(input);
    };

    addField('action', 'save');
    addField('date', dateKey);
    addField('mentor_id', VIEWED_MENTOR_ID);

    entries.forEach(entry => {
        addField('slots[]', entry.time);
        addField('links[]', entry.meeting_link);
    });

    document.body.appendChild(form);
    form.submit();
}

function selectVentureDay(dateKey,label){
    document.getElementById('v-slot-panel-title').textContent=label;
    const slots=(AVAIL_DATA[dateKey]||[]).filter(s=>!s.blocked);
    const el=document.getElementById('v-slot-list');
    el.innerHTML=slots.length
        ? slots.map(s=>{
            const t=s.slot_time||s.time||'09:00';
            const link=s.meeting_link?`<span class="avail-time-link"><i class="fa fa-link"></i> ${esc(s.meeting_link)}</span>`:'';
            return `<div class="avail-list-item">
                        <span><span class="avail-time">${fmtTime(t)}</span>${link}</span>
                        <button class="btn btn-primary btn-sm" onclick="openRequestModal('${dateKey}','${t.slice(0,5)}')"><i class="fa fa-calendar-plus"></i> Request</button>
                    </div>`;
          }).join('')
        : '<div class="empty-note">No open mentor slots on this day.</div>';
}

/* -- Helpers --------------------------------------------------- */
function fmtTime(t){const[h,m]=t.split(':');const hr=parseInt(h);return(hr>12?hr-12:hr)+':'+(m||'00')+(hr>=12?'pm':'am');}
function esc(str){if(str==null)return'';return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
</script>
</body>
</html>
