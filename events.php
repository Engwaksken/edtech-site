<?php
declare(strict_types=1);


require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}
$conn->set_charset('utf8mb4');

// -- Session & CSRF ------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token    = $_SESSION['csrf_token'];
$venture_id    = (int)(   $_SESSION['venture_id'] ?? $_SESSION['user_id'] ?? 0);
$venture_email = trim(    $_SESSION['email']       ?? '');
$venture_name  = trim(    $_SESSION['full_name']   ?? $_SESSION['name'] ?? 'Participant');

// -- Flash message -------------------------------------------------------------
$flash_msg  = '';
$flash_type = 'success';

foreach (['flash_events', 'events'] as $fk) {
    if (!empty($_SESSION[$fk])) {
        $f = $_SESSION[$fk];
        if (is_array($f)) {
            $flash_msg  = (string)($f['message'] ?? '');
            $flash_type = (string)($f['type']    ?? 'success');
        } else {
            $flash_msg  = (string)$f;
            $flash_type = 'success';
        }
        unset($_SESSION[$fk]);
        break;
    }
}

// -- Filters -------------------------------------------------------------------
$allowed_types = ['webinar','workshop','summit','demo_day','networking','mentorship'];

$filter_time = in_array($_GET['time'] ?? '', ['upcoming','past','all'], true)
    ? $_GET['time']
    : 'upcoming';

$filter_type = in_array($_GET['type'] ?? '', $allowed_types, true)
    ? $_GET['type']
    : '';

$filter_stat = in_array(
    $_GET['stat'] ?? '',
    ['upcoming', 'ongoing', 'registered'],
    true
)
    ? (string)$_GET['stat']
    : '';

$search = trim((string)($_GET['q'] ?? ''));

// -- Query ---------------------------------------------------------------------
$where  = ["e.status NOT IN ('draft','cancelled')", 'e.is_public = 1'];
$params = [];
$types  = '';

if ($filter_stat === 'upcoming') {
    $where[] = "e.status = 'upcoming'";
    $where[] = 'e.event_date >= NOW()';
} elseif ($filter_stat === 'ongoing') {
    $where[] = "e.status = 'ongoing'";
} elseif ($filter_stat === 'registered') {
    $where[] = "EXISTS (
        SELECT 1
        FROM event_registrations er_stat
        WHERE er_stat.event_id = e.id
          AND er_stat.user_id = ?
    )";
    $params[] = $venture_id;
    $types .= 'i';
} elseif ($filter_time === 'upcoming') {
    $where[] = 'e.event_date >= NOW()';
} elseif ($filter_time === 'past') {
    $where[] = 'e.event_date < NOW()';
}

if ($filter_type !== '') {
    $where[]  = 'e.event_type = ?';
    $params[] = $filter_type;
    $types   .= 's';
}

if ($search !== '') {
    $where[]  = '(e.title LIKE ? OR e.location LIKE ? OR e.description LIKE ?)';
    $s        = '%' . $search . '%';
    $params[] = $s;
    $params[] = $s;
    $params[] = $s;
    $types   .= 'sss';
}

$order = $filter_time === 'past' ? 'DESC' : 'ASC';
$ws    = implode(' AND ', $where);

$sql = "
    SELECT
        e.*,
        (SELECT COUNT(*)
         FROM   event_registrations r
         WHERE  r.event_id = e.id)                             AS reg_count,
        (SELECT COUNT(*)
         FROM   event_registrations r
         WHERE  r.event_id = e.id AND r.user_id = ?)           AS i_registered,
        (SELECT r.checkin_code
         FROM   event_registrations r
         WHERE  r.event_id = e.id AND r.user_id = ?
         LIMIT  1)                                             AS my_checkin_code
    FROM events e
    WHERE {$ws}
    ORDER BY e.event_date {$order}, e.id DESC
";

$events_stmt = $conn->prepare($sql);
if (!$events_stmt) {
    die('Query preparation failed: ' . htmlspecialchars($conn->error));
}

$all_params = array_merge([$venture_id, $venture_id], $params);
$all_types  = 'ii' . $types;
$events_stmt->bind_param($all_types, ...$all_params);
$events_stmt->execute();
$all_events = $events_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$events_stmt->close();

// -- Stats ---------------------------------------------------------------------
$stats_stmt = $conn->prepare("
    SELECT
        COALESCE(SUM(e.status = 'upcoming' AND e.event_date >= NOW() AND e.is_public = 1), 0) AS upcoming,
        COALESCE(SUM(e.status = 'ongoing'  AND e.is_public = 1), 0)                          AS ongoing,
        (SELECT COUNT(*) FROM event_registrations WHERE user_id = ?)                          AS my_regs
    FROM events e
");
$stats_stmt->bind_param('i', $venture_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
$stats_stmt->close();

// -- Look-ups ------------------------------------------------------------------
$event_types = [
    'webinar'    => 'Webinar',
    'workshop'   => 'Workshop',
    'summit'     => 'Summit',
    'demo_day'   => 'Demo Day',
    'networking' => 'Networking',
    'mentorship' => 'Mentorship',
];

$type_fa = [
    'webinar'    => 'fas fa-microphone-alt',
    'workshop'   => 'fas fa-tools',
    'summit'     => 'fas fa-mountain',
    'demo_day'   => 'fas fa-rocket',
    'networking' => 'fas fa-handshake',
    'mentorship' => 'fas fa-seedling',
];

$type_accent = [
    'webinar'    => '#2d2aff',
    'workshop'   => '#F97360',
    'summit'     => '#ff3c5f',
    'demo_day'   => '#00c896',
    'networking' => '#7c3aed',
    'mentorship' => '#0ea5e9',
];

$status_cfg = [
    'upcoming'  => ['label' => 'Upcoming',  'cls' => 'st-upcoming'],
    'ongoing'   => ['label' => 'Live Now',  'cls' => 'st-ongoing'],
    'completed' => ['label' => 'Completed', 'cls' => 'st-completed'],
];

$flash_icons = [
    'success' => 'fas fa-check-circle',
    'error'   => 'fas fa-exclamation-circle',
    'info'    => 'fas fa-info-circle',
    'warning' => 'fas fa-exclamation-triangle',
];

$site_name     = function_exists('get_setting')
    ? get_setting($conn, 'site_name', 'EdTech Fellowship')
    : 'EdTech Fellowship';

$base_site_url = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';

include __DIR__ . '/layout.php';
?>

<style>
.event-stat-link{
  min-width:0;
  display:block;
  color:inherit;
  text-decoration:none!important;
  border-radius:14px;
}
.event-stat-link .stat-tile{
  position:relative;
  height:100%;
  cursor:pointer;
  transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease,background .18s ease;
}
.event-stat-link:hover .stat-tile,
.event-stat-link:focus-visible .stat-tile{
  transform:translateY(-2px);
  border-color:#fdba74;
  background:#fffaf5;
  box-shadow:0 10px 26px rgba(15,23,42,.10);
}
.event-stat-link:focus-visible{
  outline:3px solid rgba(255,87,34,.18);
  outline-offset:3px;
}
.event-stat-link.active .stat-tile{
  border-color:#ff5722;
  background:#fff7ed;
  box-shadow:0 0 0 2px rgba(255,87,34,.10);
}
.event-stat-arrow{
  margin-left:auto;
  color:#94a3b8;
  font-size:11px;
  transition:color .18s ease,transform .18s ease;
}
.event-stat-link:hover .event-stat-arrow,
.event-stat-link:focus-visible .event-stat-arrow{
  color:#ff5722;
  transform:translateX(2px);
}

.events-grid{
  display:grid!important;
  grid-template-columns:repeat(3,minmax(0,1fr))!important;
  gap:16px!important;
  align-items:stretch;
}
.ecard{
  min-width:0;
  height:100%;
  display:flex;
  flex-direction:column;
}
.ecard-body{flex:1 1 auto}
.ecard-footer{margin-top:auto}

.toolbar-inner{
  display:grid!important;
  grid-template-columns:auto minmax(220px,1fr) minmax(160px,220px) auto auto!important;
  align-items:center!important;
  gap:10px!important;
}
.search-wrap{min-width:0;width:100%}
.search-wrap input{width:100%;min-width:0}
.type-sel{width:100%;min-width:0}
.toolbar-inner .tb-btn{white-space:nowrap}

.shell .fas,
.shell .far,
.shell .fab{
  display:inline-block;
  line-height:1;
  vertical-align:-.125em;
}

@media(max-width:1200px){
  .events-grid{
    grid-template-columns:repeat(2,minmax(0,1fr))!important;
  }
}
@media(max-width:900px){
  .toolbar-inner{
    display:flex!important;
    flex-wrap:wrap!important;
  }
  .search-wrap{flex:1 1 260px}
  .type-sel{flex:1 1 180px}
}
@media(max-width:700px){
  .events-grid{grid-template-columns:1fr!important}
  .event-stat-arrow{display:none}
}
@media(max-width:600px){
  .toolbar-inner{align-items:stretch!important}
  .tab-group,
  .search-wrap,
  .type-sel,
  .toolbar-inner .tb-btn{width:100%}
  .tab-group{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
  }
}
</style>





<div class="shell">


<div class="main">

<div class="dashboard-tab-content active" id="tab-overview">
  <div class="stats-row">

    <a href="?stat=upcoming&amp;time=all"
       class="event-stat-link <?= $filter_stat === 'upcoming' ? 'active' : '' ?>"
       aria-label="View upcoming events"
       title="View Upcoming Events">
      <div class="stat-tile">
        <div class="stat-tile-icon icon-event"><i class="fas fa-calendar-alt"></i></div>
        <div>
          <div class="stat-tile-num"><?= (int)$stats['upcoming'] ?></div>
          <div class="stat-tile-lbl">Upcoming Events</div>
        </div>
        <i class="fas fa-chevron-right event-stat-arrow"></i>
      </div>
    </a>

    <a href="?stat=ongoing&amp;time=all"
       class="event-stat-link <?= $filter_stat === 'ongoing' ? 'active' : '' ?>"
       aria-label="View live events"
       title="View Live Events">
      <div class="stat-tile">
        <div class="stat-tile-icon icon-doc"><i class="fas fa-video"></i></div>
        <div>
          <div class="stat-tile-num"><?= (int)$stats['ongoing'] ?></div>
          <div class="stat-tile-lbl">Live Now</div>
        </div>
        <i class="fas fa-chevron-right event-stat-arrow"></i>
      </div>
    </a>

    <a href="?stat=registered&amp;time=all"
       class="event-stat-link <?= $filter_stat === 'registered' ? 'active' : '' ?>"
       aria-label="View my registered events"
       title="View My Registrations">
      <div class="stat-tile">
        <div class="stat-tile-icon icon-team"><i class="fas fa-ticket-alt"></i></div>
        <div>
          <div class="stat-tile-num"><?= (int)$stats['my_regs'] ?></div>
          <div class="stat-tile-lbl">My Registrations</div>
        </div>
        <i class="fas fa-chevron-right event-stat-arrow"></i>
      </div>
    </a>

  </div>
</div>


<?php if ($flash_msg !== ''): ?>
    <div class="flash-zone">
        <div class="flash flash-<?= h($flash_type) ?>">
            <i class="<?= h($flash_icons[$flash_type] ?? 'fas fa-info-circle') ?>"></i>
            <span><?= $flash_msg /* sanitised in processor */ ?></span>
        </div>
    </div>
<?php endif; ?>

<div class="toolbar">
    <form method="GET" class="toolbar-inner">

        <div class="tab-group">
            <?php foreach (['upcoming' => 'Upcoming', 'all' => 'All', 'past' => 'Past'] as $tk => $tv): ?>
                <button type="submit" name="time" value="<?= h($tk) ?>"
                        class="tab-btn <?= $filter_time === $tk ? 'active' : '' ?>">
                    <?= h($tv) ?>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" name="q"
                   placeholder="Search events..."
                   value="<?= h($search) ?>">
        </div>

        <select name="type" class="type-sel" onchange="this.form.submit()">
            <option value="">All Types</option>
            <?php foreach ($event_types as $tk => $tv): ?>
                <option value="<?= h($tk) ?>"
                    <?= $filter_type === $tk ? 'selected' : '' ?>>
                    <?= h($tv) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <input type="hidden" name="time" value="<?= h($filter_time) ?>">
        <input type="hidden" name="stat" value="<?= h($filter_stat) ?>">

        <button type="submit" class="tb-btn tb-primary">
            <i class="fas fa-search"></i> Search
        </button>

        <?php if ($search !== '' || $filter_type !== ''): ?>
            <a href="events.php?time=<?= h($filter_time) ?>"
               class="tb-btn tb-ghost">
                <i class="fas fa-times"></i> Clear
            </a>
        <?php endif; ?>

    </form>
</div>


<div class="results-hd">
    <span class="results-count">
        <?= count($all_events) ?> event<?= count($all_events) !== 1 ? 's' : '' ?>
        <?php if ($filter_stat !== ''): ?>
            &middot;
            <?= h(
                match ($filter_stat) {
                    'upcoming' => 'Upcoming Events',
                    'ongoing' => 'Live Now',
                    'registered' => 'My Registrations',
                    default => '',
                }
            ) ?>
        <?php elseif ($filter_time !== 'all'): ?>
            &middot; <?= h(ucfirst($filter_time)) ?>
        <?php endif; ?>
        <?php if ($filter_type !== ''): ?> &middot; <?= h($event_types[$filter_type] ?? $filter_type) ?><?php endif; ?>
        <?php if ($search !== ''): ?> &middot; "<?= h($search) ?>"<?php endif; ?>
    </span>
    <div class="results-rule"></div>
</div>

<div class="events-grid">

<?php if (empty($all_events)): ?>

    <div class="empty-wrap">
        <div class="empty-icon"><i class="fas fa-calendar-alt"></i></div>
        <h3>No events found</h3>
        <p>
            <?= $filter_time === 'upcoming'
                ? 'No upcoming events are scheduled yet - check back soon!'
                : 'Try adjusting your filters or search term.' ?>
        </p>
    </div>

<?php else: ?>

    <?php foreach ($all_events as $ev):

        $dt       = !empty($ev['event_date']) ? new DateTime($ev['event_date']) : null;
        $end_dt   = !empty($ev['end_date'])   ? new DateTime($ev['end_date'])   : null;
        $type_key = $ev['event_type'] ?? 'webinar';
        $cap      = (int)$ev['capacity'];
        $regs     = (int)$ev['reg_count'];
        $pct      = $cap > 0 ? min(100, (int)round(($regs / $cap) * 100)) : 0;
        $is_full  = $cap > 0 && $regs >= $cap;
        $is_reg   = (int)$ev['i_registered'] > 0;
        $completed = $ev['status'] === 'completed';
        $ongoing   = $ev['status'] === 'ongoing';
        $accent    = $type_accent[$type_key] ?? '#2d2aff';
        $fa        = $type_fa[$type_key] ?? 'fas fa-calendar';
        $stcfg     = $status_cfg[$ev['status']] ?? ['label' => ucfirst($ev['status']), 'cls' => 'st-upcoming'];
        $code      = $ev['my_checkin_code'] ?? '';

        $dl_passed = !empty($ev['registration_deadline'])
            && strtotime($ev['registration_deadline']) < time();

        $cap_cls = $pct >= 100 ? 'full' : ($pct >= 80 ? 'warn' : '');

    ?>

    <article class="ecard">

        <?php if (!empty($ev['cover_image'])): ?>
            <img class="ecard-cover"
                 src="<?= h($base_site_url . '/' . $ev['cover_image']) ?>"
                 alt="<?= h($ev['title']) ?>">
        <?php else: ?>
            <div class="ecard-band" style="background:<?= h($accent) ?>"></div>
        <?php endif; ?>

        <div class="ecard-body">

            <div class="ecard-top">
                <span class="type-pill">
                    <i class="<?= h($fa) ?>"></i>
                    <?= h($event_types[$type_key] ?? $type_key) ?>
                </span>
                <span class="st-badge <?= h($stcfg['cls']) ?>">
                    <?= h($stcfg['label']) ?>
                </span>
            </div>

            <?php if ($is_reg): ?>
                <div class="reg-badge">
                    <i class="fas fa-check-circle"></i>
                    <?php if ($code !== ''): ?>
                        Registered &middot; Code: <strong><?= h($code) ?></strong>
                    <?php else: ?>
                        You're Registered
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <h2 class="ecard-title"><?= h($ev['title']) ?></h2>

            <?php if (!empty($ev['description'])): ?>
                <p class="ecard-desc"><?= h($ev['description']) ?></p>
            <?php endif; ?>

            <div class="ecard-meta">

                <?php if ($dt): ?>
                    <div class="meta-row">
                        <i class="fas fa-calendar-alt"></i>
                        <span>
                            <?= h($dt->format('D, M j, Y')) ?>
                            &middot;
                            <?= h($dt->format('g:i A')) ?>
                            <?php if ($end_dt): ?>
                                &ndash; <?= h($end_dt->format('g:i A')) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($ev['location'])): ?>
                    <div class="meta-row">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?= h($ev['location']) ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($ev['meeting_link'])): ?>
                    <div class="meta-row">
                        <i class="fas fa-video"></i>
                        <a href="<?= h($ev['meeting_link']) ?>"
                           target="_blank" rel="noopener">Join Online</a>
                    </div>
                <?php endif; ?>

                <?php if ($dl_passed && !$is_reg && !$completed): ?>
                    <div class="meta-row meta-closed">
                        <i class="fas fa-lock"></i>
                        <span>Registration closed</span>
                    </div>
                <?php elseif (!empty($ev['registration_deadline']) && !$completed): ?>
                    <div class="meta-row">
                        <i class="fas fa-clock"></i>
                        <span>
                            Register by
                            <?= h(date('M j, Y', strtotime($ev['registration_deadline']))) ?>
                        </span>
                    </div>
                <?php endif; ?>

            </div><!-- .ecard-meta -->

            <?php if ($cap > 0): ?>
                <div class="cap-wrap">
                    <div class="cap-top">
                        <span><strong><?= $regs ?></strong> / <?= $cap ?> registered</span>
                        <span><?= $pct ?>%</span>
                    </div>
                    <div class="cap-bar">
                        <div class="cap-fill <?= h($cap_cls) ?>" style="width:<?= $pct ?>%"></div>
                    </div>
                </div>
            <?php endif; ?>

        </div><!-- .ecard-body -->

        <div class="ecard-footer">

            <?php if ($completed): ?>

                <button class="btn-reg s-ended" disabled>
                    <i class="fas fa-ban"></i> Event Ended
                </button>

            <?php elseif ($is_reg): ?>

                <button class="btn-reg s-registered"
                        style="flex:0 0 auto;padding-left:14px;padding-right:14px"
                        disabled>
                    <i class="fas fa-check-circle"></i> Registered
                </button>

                <?php if (!$ongoing): ?>
                    <form method="POST"
                          action="includes/process-event-register.php"
                          style="flex:1;display:flex"
                          onsubmit="return confirm('Cancel your registration for this event?')">
                        <input type="hidden" name="action"     value="cancel">
                        <input type="hidden" name="event_id"   value="<?= (int)$ev['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                        <button type="submit" class="btn-cancel">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($code !== ''): ?>
                    <button class="btn-icon" title="View check-in code"
                            onclick="openCheckin(
                                <?= json_encode($ev['title'],  JSON_HEX_TAG) ?>,
                                <?= json_encode($code,         JSON_HEX_TAG) ?>
                            )">
                        <i class="fas fa-ticket-alt"></i>
                    </button>
                <?php endif; ?>

            <?php elseif ($is_full): ?>

                <button class="btn-reg s-full" disabled>
                    <i class="fas fa-user-times"></i> Fully Booked
                </button>

            <?php elseif ($dl_passed): ?>

                <button class="btn-reg s-closed" disabled>
                    <i class="fas fa-lock"></i> Registration Closed
                </button>

            <?php else: ?>

                <form method="POST"
                      action="includes/process-event-register.php"
                      style="flex:1;display:flex">
                    <input type="hidden" name="action"     value="register">
                    <input type="hidden" name="event_id"   value="<?= (int)$ev['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                    <button type="submit" class="btn-reg">
                        <i class="fas fa-plus-circle"></i> Register Now
                    </button>
                </form>

            <?php endif; ?>

            <button class="btn-icon" title="View details"
                    onclick='openDetail(<?= json_encode(
                        $ev,
                        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
                    ) ?>)'>
                <i class="fas fa-info-circle"></i>
            </button>

        </div><!-- .ecard-footer -->

    </article>

    <?php endforeach; ?>

<?php endif; ?>

</div><!-- .events-grid -->

</div><!-- .main -->
</div><!-- .shell -->

<!-- --------------------------------------------------------------------------
     DETAIL MODAL
------------------------------------------------------------------------------ -->
<div class="modal-overlay" id="detailOverlay">
    <div class="detail-modal" role="dialog" aria-modal="true" aria-labelledby="dmTitle">

        <div class="dm-band" id="dmBand"></div>

        <div class="dm-head">
            <div class="dm-head-left">
                <div class="dm-pills" id="dmPills"></div>
                <h2 class="dm-title" id="dmTitle"></h2>
            </div>
            <button class="dm-close" onclick="closeDetail()" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="dm-body">
            <p class="dm-desc" id="dmDesc"></p>

            <div class="dm-sec-lbl">Details</div>
            <div class="dm-list" id="dmDetails"></div>

            <div id="dmAgendaWrap" hidden>
                <div class="dm-sec-lbl">Agenda / Programme</div>
                <div class="dm-agenda" id="dmAgenda"></div>
            </div>

            <div id="dmSpeakersWrap" hidden>
                <div class="dm-sec-lbl">Speakers</div>
                <div class="dm-speakers" id="dmSpeakers"></div>
            </div>
        </div>

        <div class="dm-footer" id="dmFooter"></div>

    </div>
</div>

<!-- --------------------------------------------------------------------------
     CHECK-IN CODE MODAL
------------------------------------------------------------------------------ -->
<div class="modal-overlay" id="checkinOverlay">
    <div class="ci-modal" role="dialog" aria-modal="true">
        <div class="ci-icon"><i class="fas fa-ticket-alt"></i></div>
        <h3 id="ciTitle">Your Check-in Code</h3>
        <p>Present this code at the event entrance or use it to self check-in via the link in your confirmation email.</p>
        <div class="ci-code-box">
            <span class="ci-code" id="ciCode"></span>
            <span class="ci-code-lbl">Check-in Code</span>
        </div>
        <button class="btn-ci-close" onclick="closeCheckin()">
            <i class="fas fa-check"></i>&nbsp; Got it
        </button>
    </div>
</div>

<script>
/* -- Constants -------------------------------------------------------------- */
const EVENT_TYPES  = <?= json_encode($event_types,  JSON_HEX_TAG) ?>;
const TYPE_FA      = <?= json_encode($type_fa,      JSON_HEX_TAG) ?>;
const TYPE_ACCENT  = <?= json_encode($type_accent,  JSON_HEX_TAG) ?>;
const CSRF_TOKEN   = <?= json_encode($csrf_token,   JSON_HEX_TAG) ?>;

/* -- Utility ---------------------------------------------------------------- */
const esc = s => String(s ?? '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');

const fmtDate = s => s
    ? new Date(s.replace(' ','T')).toLocaleDateString('en-US',
        {weekday:'long',year:'numeric',month:'long',day:'numeric'})
    : '';

const fmtTime = s => s
    ? new Date(s.replace(' ','T')).toLocaleTimeString('en-US',
        {hour:'numeric',minute:'2-digit'})
    : '';

/* -- Detail Modal ----------------------------------------------------------- */
const detailOverlay = document.getElementById('detailOverlay');

function openDetail(ev) {
    const typeKey    = ev.event_type || 'webinar';
    const cap        = parseInt(ev.capacity)     || 0;
    const regs       = parseInt(ev.reg_count)    || 0;
    const isReg      = parseInt(ev.i_registered) > 0;
    const completed  = ev.status === 'completed';
    const ongoing    = ev.status === 'ongoing';
    const isFull     = cap > 0 && regs >= cap;
    const accent     = TYPE_ACCENT[typeKey] || '#2d2aff';
    const faIcon     = TYPE_FA[typeKey]     || 'fas fa-calendar';

    const dlPassed = ev.registration_deadline
        ? new Date(ev.registration_deadline.replace(' ','T')) < new Date()
        : false;

    /* band */
    document.getElementById('dmBand').style.background = accent;

    /* pills */
    const stCls   = {upcoming:'st-upcoming', ongoing:'st-ongoing', completed:'st-completed'};
    const stLabel = {upcoming:'Upcoming', ongoing:'Live Now', completed:'Completed'};
    document.getElementById('dmPills').innerHTML =
        `<span class="type-pill"><i class="${esc(faIcon)}"></i> ${esc(EVENT_TYPES[typeKey]||typeKey)}</span>` +
        `<span class="st-badge ${esc(stCls[ev.status]||'st-upcoming')}">${esc(stLabel[ev.status]||ev.status)}</span>`;

    /* title & desc */
    document.getElementById('dmTitle').textContent = ev.title       || '';
    document.getElementById('dmDesc').textContent  = ev.description || '';

    /* detail rows */
    const ri = (ic, html) =>
        `<div class="dm-row"><i class="${ic}"></i><span>${html}</span></div>`;

    let rows = '';
    if (ev.event_date) {
        const endT = ev.end_date ? ` &ndash; ${esc(fmtTime(ev.end_date))}` : '';
        rows += ri('fas fa-calendar-alt',
            `${esc(fmtDate(ev.event_date))} &middot; ${esc(fmtTime(ev.event_date))}${endT}`);
    }
    if (ev.location)
        rows += ri('fas fa-map-marker-alt', esc(ev.location));
    if (ev.meeting_link)
        rows += ri('fas fa-video',
            `<a href="${esc(ev.meeting_link)}" target="_blank" rel="noopener"
               style="color:var(--blue);font-weight:700">Join Online &rarr;</a>`);
    if (cap > 0) {
        const pct = Math.min(100, Math.round((regs/cap)*100));
        rows += ri('fas fa-users', `${regs} / ${cap} registered (${pct}% full)`);
    }
    if (ev.registration_deadline && !completed) {
        const dlHtml = dlPassed
            ? `<span style="color:var(--red)">Registration closed</span>`
            : `Register by ${esc(new Date(ev.registration_deadline.replace(' ','T'))
                .toLocaleDateString('en-US',{month:'long',day:'numeric',year:'numeric'}))}`;
        rows += ri('fas fa-clock', dlHtml);
    }
    if (ev.tags)
        rows += ri('fas fa-tag', esc(ev.tags));

    document.getElementById('dmDetails').innerHTML = rows;

    /* agenda */
    const agWrap = document.getElementById('dmAgendaWrap');
    agWrap.hidden = !(ev.agenda && ev.agenda.trim());
    if (!agWrap.hidden)
        document.getElementById('dmAgenda').textContent = ev.agenda;

    /* speakers */
    const spWrap = document.getElementById('dmSpeakersWrap');
    spWrap.hidden = !(ev.speakers && ev.speakers.trim());
    if (!spWrap.hidden)
        document.getElementById('dmSpeakers').innerHTML = ev.speakers
            .split(',')
            .map(s => `<span class="spk-chip">${esc(s.trim())}</span>`)
            .join('');

    /* footer */
    let footer = '';
    if (completed) {
        footer = `<button class="btn-reg s-ended" style="flex:1" disabled>
            <i class="fas fa-ban"></i> Event Ended</button>`;
    } else if (isReg) {
        footer = `<button class="btn-reg s-registered" style="flex:1" disabled>
            <i class="fas fa-check-circle"></i> You're Registered</button>`;
        if (!ongoing) {
            footer += `
            <form method="POST" action="includes/process-event-register.php"
                  style="display:flex"
                  onsubmit="return confirm('Cancel your registration for this event?')">
                <input type="hidden" name="action"     value="cancel">
                <input type="hidden" name="event_id"   value="${parseInt(ev.id)}">
                <input type="hidden" name="csrf_token" value="${esc(CSRF_TOKEN)}">
                <button type="submit" class="btn-cancel" style="padding:10px 14px">
                    <i class="fas fa-times"></i> Cancel</button>
            </form>`;
        }
    } else if (isFull) {
        footer = `<button class="btn-reg s-full" style="flex:1" disabled>
            <i class="fas fa-user-times"></i> Fully Booked</button>`;
    } else if (dlPassed) {
        footer = `<button class="btn-reg s-closed" style="flex:1" disabled>
            <i class="fas fa-lock"></i> Registration Closed</button>`;
    } else {
        footer = `
        <form method="POST" action="includes/process-event-register.php" style="flex:1;display:flex">
            <input type="hidden" name="action"     value="register">
            <input type="hidden" name="event_id"   value="${parseInt(ev.id)}">
            <input type="hidden" name="csrf_token" value="${esc(CSRF_TOKEN)}">
            <button type="submit" class="btn-reg" style="flex:1">
                <i class="fas fa-plus-circle"></i> Register for this Event
            </button>
        </form>`;
    }
    footer += `<button class="btn-icon" onclick="closeDetail()" title="Close" style="flex-shrink:0">
        <i class="fas fa-times"></i></button>`;

    document.getElementById('dmFooter').innerHTML = footer;

    detailOverlay.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeDetail() {
    detailOverlay.classList.remove('open');
    document.body.style.overflow = '';
}
detailOverlay.addEventListener('click', e => { if (e.target === detailOverlay) closeDetail(); });

/* -- Check-in Modal --------------------------------------------------------- */
const checkinOverlay = document.getElementById('checkinOverlay');

function openCheckin(title, code) {
    document.getElementById('ciTitle').textContent = title;
    document.getElementById('ciCode').textContent  = code;
    checkinOverlay.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeCheckin() {
    checkinOverlay.classList.remove('open');
    document.body.style.overflow = '';
}
checkinOverlay.addEventListener('click', e => { if (e.target === checkinOverlay) closeCheckin(); });

/* -- ESC key ---------------------------------------------------------------- */
document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (detailOverlay.classList.contains('open'))  closeDetail();
    if (checkinOverlay.classList.contains('open')) closeCheckin();
});

/* -- Auto-scroll flash ------------------------------------------------------ */
const flashEl = document.querySelector('.flash');
if (flashEl) flashEl.scrollIntoView({ behavior:'smooth', block:'center' });
</script>

</body>
</html>