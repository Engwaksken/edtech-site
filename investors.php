<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}
$conn->set_charset('utf8mb4');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$venture_id    = (int)(   $_SESSION['venture_id'] ?? $_SESSION['user_id'] ?? 0);
$venture_email = trim(    $_SESSION['email']       ?? '');
$venture_name  = trim(    $_SESSION['full_name']   ?? $_SESSION['name'] ?? '');

// -- Filters -------------------------------------------------------------------
$allowed_types  = ['angel','vc','corporate','family_office','impact','accelerator'];
$allowed_stages = ['pre_seed','seed','series_a','series_b','growth'];

$filter_type    = in_array($_GET['type']  ?? '', $allowed_types,  true) ? $_GET['type']  : '';
$filter_stage   = in_array($_GET['stage'] ?? '', $allowed_stages, true) ? $_GET['stage'] : '';
$view_mode      = ($_GET['view'] ?? 'all') === 'mine' ? 'mine' : 'all';

$stat_filter = in_array(
    $_GET['stat'] ?? '',
    ['active', 'matches', 'types'],
    true
)
    ? (string)$_GET['stat']
    : '';

$search         = trim((string)($_GET['q'] ?? ''));
$page           = max(1, (int)($_GET['page'] ?? 1));
$per_page       = 12;
$offset         = ($page - 1) * $per_page;

// -- Query ---------------------------------------------------------------------
$where  = ["i.status = 'approved'"];
$params = [];
$types  = '';

if ($stat_filter === 'matches') {
    $view_mode = 'mine';
}

if ($stat_filter === 'active' || $stat_filter === 'types') {
    $view_mode = 'all';
}

if ($view_mode === 'mine' && $venture_id > 0) {
    $where[] = 'EXISTS (
        SELECT 1
        FROM investor_matches m
        WHERE m.investor_id = i.id
          AND m.venture_id = ?
    )';
    $params[] = $venture_id;
    $types   .= 'i';
}

if ($filter_type !== '') {
    $where[]  = 'i.investor_type = ?';
    $params[] = $filter_type;
    $types   .= 's';
}

if ($filter_stage !== '') {
    $where[]  = 'FIND_IN_SET(?, i.investment_stages)';
    $params[] = $filter_stage;
    $types   .= 's';
}

if ($search !== '') {
    $where[]  = '(i.name LIKE ? OR i.firm_name LIKE ? OR i.sector_focus LIKE ?)';
    $s        = '%' . $search . '%';
    $params[] = $s; $params[] = $s; $params[] = $s;
    $types   .= 'sss';
}

$ws = implode(' AND ', $where);

// Count
$count_stmt = $conn->prepare("SELECT COUNT(*) FROM investors i WHERE {$ws}");
if ($types) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total        = (int)$count_stmt->get_result()->fetch_row()[0];
$count_stmt->close();
$total_pages  = max(1, (int)ceil($total / $per_page));

// My-match status sub-query (if venture logged in)
$match_sub = $venture_id > 0
    ? "(SELECT m.status FROM investor_matches m
        WHERE m.investor_id = i.id AND m.venture_id = {$venture_id}
        LIMIT 1) AS my_match_status,
       (SELECT m.match_score FROM investor_matches m
        WHERE m.investor_id = i.id AND m.venture_id = {$venture_id}
        LIMIT 1) AS my_match_score,"
    : "NULL AS my_match_status, NULL AS my_match_score,";

$list_stmt = $conn->prepare("
    SELECT i.*,
           {$match_sub}
           (SELECT COUNT(*) FROM investor_matches m WHERE m.investor_id = i.id) AS total_matches
    FROM investors i
    WHERE {$ws}
    ORDER BY i.name ASC
    LIMIT ? OFFSET ?
");

$all_params = array_merge($params, [$per_page, $offset]);
$all_types  = $types . 'ii';
$list_stmt->bind_param($all_types, ...$all_params);
$list_stmt->execute();
$investors_result = $list_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$list_stmt->close();

// -- My-match count for hero stat ----------------------------------------------
$my_match_count = 0;
if ($venture_id > 0) {
    $mc = $conn->prepare("SELECT COUNT(*) FROM investor_matches WHERE venture_id = ?");
    $mc->bind_param('i', $venture_id);
    $mc->execute();
    $my_match_count = (int)$mc->get_result()->fetch_row()[0];
    $mc->close();
}

// -- Total approved investors stat ---------------------------------------------
$total_approved = (int)$conn->query("SELECT COUNT(*) FROM investors WHERE status='approved'")->fetch_row()[0];

// -- Look-ups ------------------------------------------------------------------
$investor_types = [
    'angel'         => 'Angel',
    'vc'            => 'VC',
    'corporate'     => 'Corporate',
    'family_office' => 'Family Office',
    'impact'        => 'Impact Fund',
    'accelerator'   => 'Accelerator',
];

$stages = [
    'pre_seed' => 'Pre-Seed',
    'seed'     => 'Seed',
    'series_a' => 'Series A',
    'series_b' => 'Series B',
    'growth'   => 'Growth',
];

$match_statuses = [
    'pending'           => 'Intro Pending',
    'interested'        => 'Interested',
    'meeting_scheduled' => 'Meeting Scheduled',
    'passed'            => 'Passed',
    'invested'          => 'Invested',
];

$match_status_style = [
    'pending'           => ['cls' => 'ms-pending',  'icon' => 'fas fa-clock'],
    'interested'        => ['cls' => 'ms-interested','icon' => 'fas fa-star'],
    'meeting_scheduled' => ['cls' => 'ms-meeting',  'icon' => 'fas fa-calendar-check'],
    'passed'            => ['cls' => 'ms-passed',   'icon' => 'fas fa-times-circle'],
    'invested'          => ['cls' => 'ms-invested', 'icon' => 'fas fa-check-circle'],
];

$type_icon = [
    'angel'         => 'fas fa-user-circle',
    'vc'            => 'fas fa-chart-line',
    'corporate'     => 'fas fa-building',
    'family_office' => 'fas fa-home',
    'impact'        => 'fas fa-leaf',
    'accelerator'   => 'fas fa-rocket',
];

$type_accent = [
    'angel'         => '#6366f1',
    'vc'            => '#0ea5e9',
    'corporate'     => '#64748b',
    'family_office' => '#d97706',
    'impact'        => '#10b981',
    'accelerator'   => '#f43f5e',
];

$site_name     = function_exists('get_setting')
    ? get_setting($conn, 'site_name', 'EdTech Fellowship')
    : 'EdTech Fellowship';
$base_site_url = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';



function fmt_currency(mixed $n): string
{
    if (!$n) return '';
    $v = (int)$n;
    if ($v >= 1_000_000) return '$' . round($v / 1_000_000, 1) . 'M';
    if ($v >= 1_000)     return '$' . round($v / 1_000) . 'K';
    return '$' . number_format($v);
}
include __DIR__ . '/layout.php';
?>

<style>
/* ============================================================
   INVESTORS PAGE LAYOUT + CLICKABLE STATISTICS
============================================================ */

.investor-stat-link{
  min-width:0;
  display:block;
  color:inherit;
  text-decoration:none!important;
  border-radius:14px;
}

.investor-stat-link .stat-tile{
  position:relative;
  height:100%;
  cursor:pointer;
  transition:
    transform .18s ease,
    box-shadow .18s ease,
    border-color .18s ease,
    background .18s ease;
}

.investor-stat-link:hover .stat-tile,
.investor-stat-link:focus-visible .stat-tile{
  transform:translateY(-2px);
  border-color:#fdba74;
  background:#fffaf5;
  box-shadow:0 10px 26px rgba(15,23,42,.10);
}

.investor-stat-link:focus-visible{
  outline:3px solid rgba(255,87,34,.18);
  outline-offset:3px;
}

.investor-stat-link.active .stat-tile{
  border-color:#ff5722;
  background:#fff7ed;
  box-shadow:0 0 0 2px rgba(255,87,34,.10);
}

.investor-stat-arrow{
  margin-left:auto;
  color:#94a3b8;
  font-size:11px;
  transition:
    color .18s ease,
    transform .18s ease;
}

.investor-stat-link:hover .investor-stat-arrow,
.investor-stat-link:focus-visible .investor-stat-arrow{
  color:#ff5722;
  transform:translateX(2px);
}

/* Three investor cards per row on desktop */
.grid-wrap{
  display:grid!important;
  grid-template-columns:repeat(3,minmax(0,1fr))!important;
  gap:16px!important;
  align-items:stretch;
}

.icard{
  min-width:0;
  height:100%;
  display:flex;
  flex-direction:column;
}

.icard-footer{
  margin-top:auto;
}

/* Filters in one row on desktop */
.toolbar-inner{
  display:grid!important;
  grid-template-columns:
    auto
    minmax(220px,1fr)
    minmax(150px,190px)
    minmax(150px,190px)
    auto
    auto!important;
  align-items:center!important;
  gap:10px!important;
}

.view-toggle{
  display:flex;
  align-items:center;
  gap:6px;
  white-space:nowrap;
}

.search-wrap{
  min-width:0;
  width:100%;
}

.search-wrap input{
  width:100%;
  min-width:0;
}

.tb-select{
  width:100%;
  min-width:0;
}

.toolbar-inner .tb-btn{
  white-space:nowrap;
}

/* Font Awesome compatibility */
.shell .fas,
.shell .far,
.shell .fab{
  display:inline-block;
  line-height:1;
  vertical-align:-.125em;
}

@media(max-width:1200px){
  .grid-wrap{
    grid-template-columns:repeat(2,minmax(0,1fr))!important;
  }

  .toolbar-inner{
    grid-template-columns:
      auto
      minmax(180px,1fr)
      minmax(140px,170px)
      minmax(140px,170px)
      auto
      auto!important;
  }
}

@media(max-width:980px){
  .toolbar-inner{
    display:flex!important;
    flex-wrap:wrap!important;
  }

  .view-toggle{
    flex:1 1 100%;
  }

  .search-wrap{
    flex:1 1 280px;
  }

  .tb-select{
    flex:1 1 170px;
  }
}

@media(max-width:700px){
  .grid-wrap{
    grid-template-columns:1fr!important;
  }

  .investor-stat-arrow{
    display:none;
  }
}

@media(max-width:600px){
  .toolbar-inner{
    align-items:stretch!important;
  }

  .view-toggle{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    width:100%;
  }

  .search-wrap,
  .tb-select,
  .toolbar-inner .tb-btn{
    width:100%;
  }
}
</style>

<div class="shell">


<div class="main">


<div class="dashboard-tab-content active" id="tab-overview">
  <div class="stats-row">

    <a
      href="?stat=active&amp;view=all"
      class="investor-stat-link <?= $stat_filter === 'active' ? 'active' : '' ?>"
      aria-label="View all active investors"
      title="View Active Investors"
    >
      <div class="stat-tile">
        <div class="stat-tile-icon icon-doc"><i class="fas fa-globe"></i></div>
        <div>
          <div class="stat-tile-num"><?= $total_approved ?></div>
          <div class="stat-tile-lbl">Active Investors</div>
        </div>
        <i class="fas fa-chevron-right investor-stat-arrow" aria-hidden="true"></i>
      </div>
    </a>

    <a
      href="?stat=matches&amp;view=mine"
      class="investor-stat-link <?= $stat_filter === 'matches' || $view_mode === 'mine' ? 'active' : '' ?>"
      aria-label="View your investor matches"
      title="View Your Matches"
    >
      <div class="stat-tile">
        <div class="stat-tile-icon icon-team"><i class="fas fa-star"></i></div>
        <div>
          <div class="stat-tile-num"><?= $my_match_count ?></div>
          <div class="stat-tile-lbl">Your Matches</div>
        </div>
        <i class="fas fa-chevron-right investor-stat-arrow" aria-hidden="true"></i>
      </div>
    </a>

    <a
      href="?stat=types&amp;view=all"
      class="investor-stat-link <?= $stat_filter === 'types' ? 'active' : '' ?>"
      aria-label="Browse investor types"
      title="Browse Investor Types"
    >
      <div class="stat-tile">
        <div class="stat-tile-icon icon-investor"><i class="fas fa-layer-group"></i></div>
        <div>
          <div class="stat-tile-num"><?= count($investor_types) ?></div>
          <div class="stat-tile-lbl">Investor Types</div>
        </div>
        <i class="fas fa-chevron-right investor-stat-arrow" aria-hidden="true"></i>
      </div>
    </a>

  </div>
</div>


<div class="toolbar">
    <form method="GET" class="toolbar-inner">

        <div class="view-toggle">
            <button type="submit" name="view" value="all"
                    class="vt-btn <?= $view_mode === 'all' ? 'active' : '' ?>">
                <i class="fas fa-globe"></i> All Investors
            </button>
            <?php if ($venture_id > 0): ?>
                <button type="submit" name="view" value="mine"
                        class="vt-btn <?= $view_mode === 'mine' ? 'active' : '' ?>">
                    <i class="fas fa-star"></i> My Matches
                    <?php if ($my_match_count > 0): ?>
                        (<?= $my_match_count ?>)
                    <?php endif; ?>
                </button>
            <?php endif; ?>
        </div>

        <div class="search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" name="q"
                   placeholder="Search by name, firm or sector..."
                   value="<?= h($search) ?>">
        </div>

        <select name="type" class="tb-select" onchange="this.form.submit()">
            <option value="">All Types</option>
            <?php foreach ($investor_types as $tk => $tv): ?>
                <option value="<?= h($tk) ?>"
                    <?= $filter_type === $tk ? 'selected' : '' ?>>
                    <?= h($tv) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="stage" class="tb-select" onchange="this.form.submit()">
            <option value="">All Stages</option>
            <?php foreach ($stages as $sk => $sv): ?>
                <option value="<?= h($sk) ?>"
                    <?= $filter_stage === $sk ? 'selected' : '' ?>>
                    <?= h($sv) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <!-- preserve view/stat mode on submit -->
        <input type="hidden" name="view" value="<?= h($view_mode) ?>">
        <input type="hidden" name="stat" value="<?= h($stat_filter) ?>">

        <button type="submit" class="tb-btn tb-primary">
            <i class="fas fa-search"></i> Search
        </button>

        <?php if ($search !== '' || $filter_type !== '' || $filter_stage !== ''): ?>
            <a href="investors.php?view=<?= h($view_mode) ?>" class="tb-btn tb-ghost">
                <i class="fas fa-times"></i> Clear
            </a>
        <?php endif; ?>

    </form>
</div>

<!-- -- RESULTS HEADER --------------------------------------------------------- -->
<div class="results-bar">
    <span class="results-label">
        <?= number_format($total) ?> investor<?= $total !== 1 ? 's' : '' ?>
        <?php if ($view_mode === 'mine'): ?> &middot; Your Matches<?php endif; ?>
        <?php if ($filter_type !== ''): ?> &middot; <?= h($investor_types[$filter_type]) ?><?php endif; ?>
        <?php if ($filter_stage !== ''): ?> &middot; <?= h($stages[$filter_stage]) ?><?php endif; ?>
        <?php if ($search !== ''): ?> &middot; "<?= h($search) ?>"<?php endif; ?>
    </span>
    <div class="results-rule"></div>
</div>

<!-- -- GRID ------------------------------------------------------------------- -->
<div class="grid-wrap">

<?php if (empty($investors_result)): ?>

    <div class="empty-state">
        <div class="empty-icon">
            <i class="fas fa-user-tie"></i>
        </div>
        <h3>
            <?= $view_mode === 'mine'
                ? 'No matches yet'
                : 'No investors found' ?>
        </h3>
        <p>
            <?= $view_mode === 'mine'
                ? 'Your venture hasn\'t been matched with any investors yet. Browse all investors to explore the network.'
                : 'Try adjusting your search or filters.' ?>
        </p>
    </div>

<?php else: ?>

    <?php foreach ($investors_result as $inv):
        $type_key   = $inv['investor_type'] ?? 'angel';
        $accent     = $type_accent[$type_key]  ?? '#1e2d5a';
        $fa_icon    = $type_icon[$type_key]    ?? 'fas fa-user-circle';
        $initials   = strtoupper(mb_substr($inv['name'] ?? 'I', 0, 2));
        $has_match  = !empty($inv['my_match_status']);
        $mst        = $inv['my_match_status'] ?? '';
        $mst_cfg    = $match_status_style[$mst] ?? null;
        $mst_label  = $match_statuses[$mst] ?? '';
        $score      = $inv['my_match_score'] ?? null;

        // Ticket range
        $ticket = '';
        if (!empty($inv['min_ticket']) || !empty($inv['max_ticket'])) {
            $ticket = fmt_currency($inv['min_ticket']);
            if (!empty($inv['max_ticket'])) {
                $ticket .= ($ticket ? ' – ' : '') . fmt_currency($inv['max_ticket']);
            }
        }

        // Stage list
        $stage_list = array_filter(
            array_map('trim', explode(',', $inv['investment_stages'] ?? ''))
        );
    ?>

    <div class="icard"
         onclick='openInvestor(<?= json_encode($inv, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'
         role="button" tabindex="0"
         onkeydown="if(event.key==='Enter'||event.key===' ')this.click()">

        <div class="icard-accent" style="background:<?= h($accent) ?>"></div>

        <!-- Score badge (only if matched with score) -->
        <?php if ($has_match && $score !== null && $score !== ''): ?>
            <div class="score-badge">
                <i class="fas fa-star"></i>
                <?= (int)$score ?>% match
            </div>
        <?php endif; ?>

        <div class="icard-head">

            <?php if (!empty($inv['photo'])): ?>
                <img class="icard-avatar"
                     src="<?= h($base_site_url . '/' . $inv['photo']) ?>"
                     alt="<?= h($inv['name']) ?>">
            <?php else: ?>
                <div class="icard-initials"
                     style="background:<?= h($accent) ?>">
                    <?= h($initials) ?>
                </div>
            <?php endif; ?>

            <div class="icard-info">
                <div class="icard-name"><?= h($inv['name']) ?></div>
                <?php if (!empty($inv['firm_name'])): ?>
                    <div class="icard-firm"><?= h($inv['firm_name']) ?></div>
                <?php endif; ?>
                <span class="icard-type-pill">
                    <i class="<?= h($fa_icon) ?>"></i>
                    <?= h($investor_types[$type_key] ?? $type_key) ?>
                </span>
            </div>

        </div><!-- .icard-head -->

        <!-- Match status banner -->
        <?php if ($has_match && $mst_cfg): ?>
            <div class="match-banner <?= h($mst_cfg['cls']) ?>">
                <i class="<?= h($mst_cfg['icon']) ?>"></i>
                <?= h($mst_label) ?>
            </div>
        <?php endif; ?>

        <!-- Bio snippet -->
        <?php if (!empty($inv['bio'])): ?>
            <p class="icard-bio"><?= h($inv['bio']) ?></p>
        <?php endif; ?>

        <!-- Meta -->
        <div class="icard-meta">
            <?php if (!empty($inv['location'])): ?>
                <div class="imeta-row">
                    <i class="fas fa-map-marker-alt"></i>
                    <span><?= h($inv['location']) ?></span>
                </div>
            <?php endif; ?>
            <?php if (!empty($inv['sector_focus'])): ?>
                <div class="imeta-row">
                    <i class="fas fa-bullseye"></i>
                    <span><?= h(mb_strimwidth($inv['sector_focus'], 0, 55, '...')) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Stage chips -->
        <?php if (!empty($stage_list)): ?>
            <div class="stage-chips">
                <?php foreach ($stage_list as $s): ?>
                    <span class="stage-chip"><?= h($stages[$s] ?? $s) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="icard-footer">
            <div>
                <?php if ($ticket !== ''): ?>
                    <span class="icard-ticket-label">Ticket Size</span>
                    <span class="icard-ticket"><?= h($ticket) ?></span>
                <?php endif; ?>
            </div>
            <button class="btn-profile"
                    onclick='event.stopPropagation();openInvestor(<?= json_encode($inv, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>
                View Profile <i class="fas fa-arrow-right"></i>
            </button>
        </div>

    </div>

    <?php endforeach; ?>

<?php endif; ?>
</div><!-- .grid-wrap -->

<!-- -- PAGINATION ------------------------------------------------------------- -->
<?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?<?= h(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>"
               class="pg-btn">
                <i class="fas fa-chevron-left"></i>
            </a>
        <?php endif; ?>

        <?php
        // Smart window: show at most 7 pages
        $pg_start = max(1, $page - 3);
        $pg_end   = min($total_pages, $page + 3);
        if ($pg_start > 1): ?>
            <a href="?<?= h(http_build_query(array_merge($_GET, ['page' => 1]))) ?>" class="pg-btn">1</a>
            <?php if ($pg_start > 2): ?><span class="pg-btn" style="border:none;color:var(--ink-3)">...</span><?php endif; ?>
        <?php endif; ?>

        <?php for ($p = $pg_start; $p <= $pg_end; $p++): ?>
            <a href="?<?= h(http_build_query(array_merge($_GET, ['page' => $p]))) ?>"
               class="pg-btn <?= $p === $page ? 'current' : '' ?>">
                <?= $p ?>
            </a>
        <?php endfor; ?>

        <?php if ($pg_end < $total_pages): ?>
            <?php if ($pg_end < $total_pages - 1): ?><span class="pg-btn" style="border:none;color:var(--ink-3)">...</span><?php endif; ?>
            <a href="?<?= h(http_build_query(array_merge($_GET, ['page' => $total_pages]))) ?>" class="pg-btn"><?= $total_pages ?></a>
        <?php endif; ?>

        <?php if ($page < $total_pages): ?>
            <a href="?<?= h(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>"
               class="pg-btn">
                <i class="fas fa-chevron-right"></i>
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

</div><!-- .main -->
</div><!-- .shell -->

<!-- --------------------------------------------------------------------------
     INVESTOR DETAIL MODAL
------------------------------------------------------------------------------ -->
<div class="modal-overlay" id="invOverlay">
    <div class="inv-modal" role="dialog" aria-modal="true" aria-labelledby="imName">

        <div class="im-band" id="imBand"></div>

        <div class="im-head">
            <div id="imAvatarWrap"></div>
            <div class="im-head-info">
                <div class="im-pills" id="imPills"></div>
                <h2 class="im-name" id="imName"></h2>
                <p class="im-firm" id="imFirm"></p>
            </div>
            <button class="im-close" onclick="closeInvestor()" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="im-body">

            <div id="imMatchBox" style="display:none" class="im-match-box">
                <h4>Your Match Status</h4>
                <div id="imMatchStatus"></div>
                <div class="im-match-score" id="imMatchScore"></div>
            </div>

            <div id="imBioWrap">
                <div class="im-section-label">About</div>
                <p class="im-bio" id="imBio"></p>
            </div>

            <div class="im-section-label">Details</div>
            <div class="im-details" id="imDetails"></div>

            <div id="imStagesWrap" style="display:none">
                <div class="im-section-label">Investment Stages</div>
                <div class="im-stages" id="imStages"></div>
            </div>

            <div id="imSectorsWrap" style="display:none">
                <div class="im-section-label">Sector Focus</div>
                <div class="im-sector-tags" id="imSectors"></div>
            </div>

        </div>

        <div class="im-footer" id="imFooter"></div>

    </div>
</div>

<script>
/* -- Constants -------------------------------------------------------------- */
const INVESTOR_TYPES  = <?= json_encode($investor_types,    JSON_HEX_TAG) ?>;
const TYPE_ICON       = <?= json_encode($type_icon,         JSON_HEX_TAG) ?>;
const TYPE_ACCENT     = <?= json_encode($type_accent,       JSON_HEX_TAG) ?>;
const STAGES          = <?= json_encode($stages,            JSON_HEX_TAG) ?>;
const MATCH_STATUSES  = <?= json_encode($match_statuses,    JSON_HEX_TAG) ?>;
const MATCH_STYLE     = <?= json_encode($match_status_style,JSON_HEX_TAG) ?>;
const BASE_URL        = <?= json_encode($base_site_url,     JSON_HEX_TAG) ?>;

/* -- Utility ---------------------------------------------------------------- */
const esc = s => String(s ?? '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');

function fmtCurrency(n) {
    const v = parseInt(n) || 0;
    if (!v) return '';
    if (v >= 1_000_000) return '$' + (v/1_000_000).toFixed(1).replace(/\.0$/,'') + 'M';
    if (v >= 1_000)     return '$' + Math.round(v/1_000) + 'K';
    return '$' + v.toLocaleString();
}

/* -- Modal ------------------------------------------------------------------ */
const overlay = document.getElementById('invOverlay');

function openInvestor(inv) {
    const typeKey  = inv.investor_type || 'angel';
    const accent   = TYPE_ACCENT[typeKey]  || '#1e2d5a';
    const faIcon   = TYPE_ICON[typeKey]    || 'fas fa-user-circle';
    const initials = (inv.name || 'I').substring(0,2).toUpperCase();

    /* band */
    document.getElementById('imBand').style.background = accent;

    /* avatar */
    const avatarWrap = document.getElementById('imAvatarWrap');
    if (inv.photo) {
        avatarWrap.innerHTML =
            `<img class="im-avatar-lg" src="${esc(BASE_URL)}/${esc(inv.photo)}" alt="">`;
    } else {
        avatarWrap.innerHTML =
            `<div class="im-initials-lg" style="background:${esc(accent)}">${esc(initials)}</div>`;
    }

    /* pills */
    document.getElementById('imPills').innerHTML =
        `<span class="icard-type-pill"><i class="${esc(faIcon)}"></i> ${esc(INVESTOR_TYPES[typeKey]||typeKey)}</span>`;

    /* name & firm */
    document.getElementById('imName').textContent = inv.name || '';
    document.getElementById('imFirm').textContent = inv.firm_name || '';
    document.getElementById('imFirm').style.display = inv.firm_name ? '' : 'none';

    /* match box */
    const matchBox = document.getElementById('imMatchBox');
    if (inv.my_match_status) {
        const mst    = inv.my_match_status;
        const mcfg   = MATCH_STYLE[mst] || {};
        const mlabel = MATCH_STATUSES[mst] || mst;
        const score  = parseInt(inv.my_match_score);

        document.getElementById('imMatchStatus').innerHTML =
            `<span class="im-match-status ${esc(mcfg.cls||'')}">
                <i class="${esc(mcfg.icon||'fas fa-circle')}"></i> ${esc(mlabel)}
            </span>`;

        document.getElementById('imMatchScore').innerHTML = !isNaN(score)
            ? `Match score: <strong>${score}%</strong>`
            : '';

        matchBox.style.display = '';
    } else {
        matchBox.style.display = 'none';
    }

    /* bio */
    const bioWrap = document.getElementById('imBioWrap');
    if (inv.bio && inv.bio.trim()) {
        document.getElementById('imBio').textContent = inv.bio;
        bioWrap.style.display = '';
    } else {
        bioWrap.style.display = 'none';
    }

    /* details */
    const di = (ic, html) =>
        `<div class="im-detail"><i class="${ic}"></i><span>${html}</span></div>`;

    let det = '';
    if (inv.location)
        det += di('fas fa-map-marker-alt', esc(inv.location));
    if (inv.min_ticket || inv.max_ticket) {
        const t = [fmtCurrency(inv.min_ticket), fmtCurrency(inv.max_ticket)].filter(Boolean).join(' – ');
        det += di('fas fa-dollar-sign', esc(t) + ' ticket size');
    }
    if (inv.linkedin_url)
        det += di('fab fa-linkedin',
            `<a href="${esc(inv.linkedin_url)}" target="_blank" rel="noopener">LinkedIn Profile &rarr;</a>`);
    if (inv.website)
        det += di('fas fa-globe',
            `<a href="${esc(inv.website)}" target="_blank" rel="noopener">${esc(inv.website.replace(/^https?:\/\//,''))} &rarr;</a>`);
    if (inv.phone)
        det += di('fas fa-phone', esc(inv.phone));
    if (inv.job_title)
        det += di('fas fa-briefcase', esc(inv.job_title));
    document.getElementById('imDetails').innerHTML = det;

    /* stages */
    const stWrap  = document.getElementById('imStagesWrap');
    const stList  = (inv.investment_stages || '').split(',').map(s=>s.trim()).filter(Boolean);
    if (stList.length) {
        document.getElementById('imStages').innerHTML =
            stList.map(s => `<span class="im-stage-chip">${esc(STAGES[s]||s)}</span>`).join('');
        stWrap.style.display = '';
    } else {
        stWrap.style.display = 'none';
    }

    /* sectors */
    const secWrap = document.getElementById('imSectorsWrap');
    if (inv.sector_focus && inv.sector_focus.trim()) {
        document.getElementById('imSectors').innerHTML =
            inv.sector_focus.split(',')
                .map(s => `<span class="im-sector-tag">${esc(s.trim())}</span>`)
                .join('');
        secWrap.style.display = '';
    } else {
        secWrap.style.display = 'none';
    }

    /* footer */
    let footer = '';
    if (inv.linkedin_url) {
        footer += `<a href="${esc(inv.linkedin_url)}" target="_blank" rel="noopener"
                      class="btn-ext btn-navy">
            <i class="fab fa-linkedin"></i> LinkedIn
        </a>`;
    }
    if (inv.website) {
        footer += `<a href="${esc(inv.website)}" target="_blank" rel="noopener"
                      class="btn-ext btn-outline">
            <i class="fas fa-external-link-alt"></i> Website
        </a>`;
    }
    footer += `<button class="btn-ext btn-outline" onclick="closeInvestor()" style="margin-left:auto">
        Close
    </button>`;

    document.getElementById('imFooter').innerHTML = footer;

    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeInvestor() {
    overlay.classList.remove('open');
    document.body.style.overflow = '';
}

overlay.addEventListener('click', e => { if (e.target === overlay) closeInvestor(); });

document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && overlay.classList.contains('open')) closeInvestor();
});
</script>

</body>
</html>