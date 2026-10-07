<?php
declare(strict_types=1);

require_once '../includes/config.php';
require_once 'includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

ms_auth_gate();


if (!ms_has_page_permission($conn, 'mentor-assignments')) {
    http_response_code(403);
    include 'includes/sidebar.php'; ?>
    <div class="admin-main"><div class="admin-content legacy-style-5534667570">
    <h2>Access denied</h2>
    <p>Your role is not allowed to access Mentor Assignments.</p>
    </div></div><?php exit;
}

$IS_MENTOR = ms_is_mentor();


$CAN_MANAGE = !$IS_MENTOR;

$current_mentor_id = ms_mentor_record_id($conn);

if ($IS_MENTOR && $current_mentor_id <= 0) {
    include 'includes/sidebar.php'; ?>
    <div class="admin-main"><div class="admin-content legacy-style-5534667570">
    <h2>Account not linked</h2>
    <p>Your mentor account is not yet linked to a mentor profile. Please contact the programme administrator.</p>
    </div></div><?php exit;
}

if (!function_exists('safe_truncate')) {
    function safe_truncate($text, int $limit = 70): string {
        $text = trim((string)($text ?? ''));

        if ($text === '') {
            return '';
        }

        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($text, 0, $limit, '...');
        }

        return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
    }
}


$filter_cohort  = (int)($_GET['cohort_id']  ?? 0);
$filter_mentor  = (int)($_GET['mentor_id']  ?? 0);
$filter_venture = (int)($_GET['venture_id'] ?? 0);
$filter_scope   = trim((string)($_GET['scope'] ?? ''));
$search         = trim((string)($_GET['q'] ?? ''));

if (!in_array($filter_scope, ['', 'cohort', 'venture'], true)) {
    $filter_scope = '';
}

if ($IS_MENTOR) {
    $filter_mentor = $current_mentor_id;
}

$where  = ['1=1'];
$params = [];
$types  = '';

if ($IS_MENTOR) {
    $where[] = 'ma.mentor_id = ?';
    $params[] = $current_mentor_id;
    $types .= 'i';
}

if ($filter_cohort > 0) {
    $where[] = 'ma.cohort_id = ?';
    $params[] = $filter_cohort;
    $types .= 'i';
}

if (!$IS_MENTOR && $filter_mentor > 0) {
    $where[] = 'ma.mentor_id = ?';
    $params[] = $filter_mentor;
    $types .= 'i';
}

if ($filter_venture > 0) {
    $where[] = 'ma.venture_id = ?';
    $params[] = $filter_venture;
    $types .= 'i';
}

if ($filter_scope === 'cohort') {
    $where[] = 'ma.venture_id IS NULL';
}

if ($filter_scope === 'venture') {
    $where[] = 'ma.venture_id IS NOT NULL';
}

if ($search !== '') {
    $where[] = '(m.full_name LIKE ? OR v.name LIKE ? OR c.name LIKE ? OR ma.role LIKE ?)';
    $s = '%' . $search . '%';
    array_push($params, $s, $s, $s, $s);
    $types .= 'ssss';
}

$ws = implode(' AND ', $where);


$stmt = $conn->prepare("
    SELECT ma.*,
           m.full_name AS mentor_name,
           m.photo AS mentor_photo,
           m.job_title AS mentor_title,
           m.organisation AS mentor_org,
           m.expertise AS mentor_expertise,
           m.status AS mentor_status,
           c.name AS cohort_name,
           c.status AS cohort_status,
           v.name AS venture_name,
           v.stage AS venture_stage,
           v.sector AS venture_sector,
           v.logo AS venture_logo,
           (
               SELECT COUNT(*)
               FROM mentor_sessions ms
               WHERE ms.mentor_id = ma.mentor_id
                 AND (ma.venture_id IS NULL OR ms.venture_id = ma.venture_id)
                 AND ms.status = 'completed'
           ) AS sessions_done,
           (
               SELECT COUNT(*)
               FROM mentor_sessions ms
               WHERE ms.mentor_id = ma.mentor_id
                 AND (ma.venture_id IS NULL OR ms.venture_id = ma.venture_id)
                 AND ms.status IN('scheduled','confirmed')
           ) AS sessions_upcoming
    FROM mentor_assignments ma
    JOIN mentors m ON m.id = ma.mentor_id
    JOIN cohorts c ON c.id = ma.cohort_id
    LEFT JOIN ventures v ON v.id = ma.venture_id
    WHERE $ws
    ORDER BY c.sort_order ASC, m.full_name ASC, v.name ASC
");

if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$assignments = $stmt->get_result();


if ($IS_MENTOR) {
    $stats_stmt = $conn->prepare("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(venture_id IS NULL), 0) AS cohort_wide,
            COALESCE(SUM(venture_id IS NOT NULL), 0) AS venture_specific,
            COUNT(DISTINCT mentor_id) AS unique_mentors,
            COUNT(DISTINCT cohort_id) AS unique_cohorts
        FROM mentor_assignments
        WHERE mentor_id = ?
    ");
    $stats_stmt->bind_param('i', $current_mentor_id);
} else {
    $stats_stmt = $conn->prepare("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(venture_id IS NULL), 0) AS cohort_wide,
            COALESCE(SUM(venture_id IS NOT NULL), 0) AS venture_specific,
            COUNT(DISTINCT mentor_id) AS unique_mentors,
            COUNT(DISTINCT cohort_id) AS unique_cohorts
        FROM mentor_assignments
    ");
}

$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc() ?: [
    'total' => 0,
    'cohort_wide' => 0,
    'venture_specific' => 0,
    'unique_mentors' => 0,
    'unique_cohorts' => 0,
];
$stats_stmt->close();


if ($IS_MENTOR) {
    $mentors_dd_stmt = $conn->prepare("
        SELECT id, full_name, job_title, organisation, photo, expertise
        FROM mentors
        WHERE id = ?
        ORDER BY full_name
    ");
    $mentors_dd_stmt->bind_param('i', $current_mentor_id);
    $mentors_dd_stmt->execute();
    $mentors_dd = $mentors_dd_stmt->get_result();

    $cohorts_dd_stmt = $conn->prepare("
        SELECT DISTINCT c.id, c.name, c.sort_order
        FROM cohorts c
        JOIN mentor_assignments ma ON ma.cohort_id = c.id
        WHERE ma.mentor_id = ?
        ORDER BY c.sort_order, c.name
    ");
    $cohorts_dd_stmt->bind_param('i', $current_mentor_id);
    $cohorts_dd_stmt->execute();
    $cohorts_dd = $cohorts_dd_stmt->get_result();

    $ventures_filter_stmt = $conn->prepare("
        SELECT DISTINCT v.id, v.name, c.name AS cohort_name, c.sort_order
        FROM ventures v
        JOIN cohorts c ON c.id = v.cohort_id
        JOIN mentor_assignments ma
          ON ma.cohort_id = v.cohort_id
         AND (ma.venture_id IS NULL OR ma.venture_id = v.id)
        WHERE ma.mentor_id = ?
        ORDER BY c.sort_order, v.name
    ");
    $ventures_filter_stmt->bind_param('i', $current_mentor_id);
    $ventures_filter_stmt->execute();
    $ventures_filter = $ventures_filter_stmt->get_result();

    $ventures_all_stmt = $conn->prepare("
        SELECT DISTINCT v.id, v.name, v.cohort_id, v.stage, v.sector
        FROM ventures v
        JOIN mentor_assignments ma
          ON ma.cohort_id = v.cohort_id
         AND (ma.venture_id IS NULL OR ma.venture_id = v.id)
        WHERE ma.mentor_id = ?
        ORDER BY v.name
    ");
    $ventures_all_stmt->bind_param('i', $current_mentor_id);
    $ventures_all_stmt->execute();
    $ventures_all = $ventures_all_stmt->get_result();
} else {
    $mentors_dd = $conn->query("
        SELECT id, full_name, job_title, organisation, photo, expertise
        FROM mentors
        WHERE status = 'active'
        ORDER BY full_name
    ");

    $cohorts_dd = $conn->query("
        SELECT id, name
        FROM cohorts
        ORDER BY sort_order, name
    ");

    $ventures_filter = $conn->query("
        SELECT v.id, v.name, c.name AS cohort_name
        FROM ventures v
        LEFT JOIN cohorts c ON c.id = v.cohort_id
        ORDER BY c.sort_order, v.name
    ");

    $ventures_all = $conn->query("
        SELECT v.id, v.name, v.cohort_id, v.stage, v.sector
        FROM ventures v
        ORDER BY v.name
    ");
}

$ventures_for_js = [];
while ($vr = $ventures_all->fetch_assoc()) {
    $ventures_for_js[] = $vr;
}


$expertise_labels = [
    'fundraising' => 'Fundraising',
    'product' => 'Product',
    'marketing' => 'Marketing',
    'tech' => 'Technology',
    'ops' => 'Operations',
    'legal' => 'Legal',
    'finance' => 'Finance',
    'sales' => 'Sales',
    'strategy' => 'Strategy',
    'impact' => 'Impact',
    'design' => 'Design',
    'hr' => 'HR',
    'growth' => 'Growth',
];

$stage_labels = [
    'idea' => 'Idea',
    'mvp' => 'MVP',
    'pre_seed' => 'Pre-Seed',
    'seed' => 'Seed',
    'series_a' => 'Series A',
    'growth' => 'Growth',
];

$site_name = function_exists('get_setting')
    ? get_setting($conn, 'site_name', 'EdTech Fellowship')
    : 'EdTech Fellowship';


$rows = [];
while ($row = $assignments->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

$grouped = [];
foreach ($rows as $row) {
    $key = (int)$row['cohort_id'];
    $grouped[$key]['label'] = $row['cohort_name'];
    $grouped[$key]['status'] = $row['cohort_status'];
    $grouped[$key]['rows'][] = $row;
}

$clear_url = 'mentor-assignments.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Mentor Assignments - <?= h($site_name) ?> Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="assets/css/admin.css">

  
</head>
<body>
<?php include 'includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">

<header class="admin-topbar">
  <div class="topbar-left">
    <div class="topbar-breadcrumb">
      <a href="index.php">Dashboard</a> &gt;
      <a href="mentors.php">Mentors</a> &gt;
      <strong>Assignments</strong>
    </div>
  </div>

  <div class="topbar-right">
   
    <a href="mentor-sessions.php" class="btn btn-secondary btn-sm"><i class="fa fa-calendar-alt"></i> Sessions</a>
    <a href="mentor-messages.php" class="btn btn-secondary btn-sm"><i class="fa fa-envelope"></i> Messages</a>
    <div class="admin-avatar">
      <div class="avatar-circle"><?= h(strtoupper(substr($ADMIN['full_name'] ?? 'A', 0, 1))) ?></div>
    </div>
  </div>
</header>

<div class="admin-content">
<?php show_flash('assignments'); ?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= $IS_MENTOR ? 'My Mentor Assignments' : 'Mentor Assignments' ?></h1>
    <p class="page-subtitle">
      <?= $IS_MENTOR
          ? 'View cohorts and ventures assigned to you'
          : 'Manage which mentors are assigned to cohorts and individual ventures' ?>
    </p>
  </div>

  <?php if (!$IS_MENTOR): ?>
    <div class="page-actions">
      <button class="btn btn-primary" onclick="openAssignModal()">
        <i class="fa fa-plus"></i> New Assignment
      </button>
    </div>
  <?php endif; ?>
</div>


<!-- Stats -->
<div class="stat-ribbon">
  <div class="stat-tile">
    <div class="sn"><?= (int)$stats['total'] ?></div>
    <div class="sl">Total Assignments</div>
  </div>

  <div class="stat-tile">
    <div class="sn legacy-style-a8a7a0900c"><?= (int)$stats['cohort_wide'] ?></div>
    <div class="sl">Cohort-Wide</div>
  </div>

  <div class="stat-tile">
    <div class="sn legacy-style-b2fecbdad8"><?= (int)$stats['venture_specific'] ?></div>
    <div class="sl">Venture-Specific</div>
  </div>

  <?php if (!$IS_MENTOR): ?>
    <div class="stat-tile">
      <div class="sn legacy-style-f60ddf1b95"><?= (int)$stats['unique_mentors'] ?></div>
      <div class="sl">Mentors Assigned</div>
    </div>
  <?php endif; ?>

  <div class="stat-tile">
    <div class="sn legacy-style-488b9b9757"><?= (int)$stats['unique_cohorts'] ?></div>
    <div class="sl">Cohorts Covered</div>
  </div>
</div>

<!-- Filters -->
<div class="filter-bar">
  <form method="GET" class="legacy-style-ae125572d5">
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="<?= $IS_MENTOR ? 'Search venture, cohort, role...' : 'Search mentor, venture, cohort, role...' ?>">

    <select name="cohort_id" onchange="this.form.submit()">
      <option value="">All Cohorts</option>
      <?php $cohorts_dd->data_seek(0); while ($c = $cohorts_dd->fetch_assoc()): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $filter_cohort === (int)$c['id'] ? 'selected' : '' ?>>
          <?= h($c['name']) ?>
        </option>
      <?php endwhile; ?>
    </select>

    <?php if (!$IS_MENTOR): ?>
      <select name="mentor_id" onchange="this.form.submit()">
        <option value="">All Mentors</option>
        <?php $mentors_dd->data_seek(0); while ($m = $mentors_dd->fetch_assoc()): ?>
          <option value="<?= (int)$m['id'] ?>" <?= $filter_mentor === (int)$m['id'] ? 'selected' : '' ?>>
            <?= h($m['full_name']) ?>
          </option>
        <?php endwhile; ?>
      </select>
    <?php endif; ?>

    <select name="venture_id" onchange="this.form.submit()">
      <option value="">All Ventures</option>
      <?php
      $ventures_filter->data_seek(0);
      $last_cname = '';
      while ($v = $ventures_filter->fetch_assoc()):
          $cn = $v['cohort_name'] ?? 'No Cohort';
          if ($cn !== $last_cname) {
              if ($last_cname !== '') echo '</optgroup>';
              echo '<optgroup label="' . h($cn) . '">';
              $last_cname = $cn;
          }
      ?>
        <option value="<?= (int)$v['id'] ?>" <?= $filter_venture === (int)$v['id'] ? 'selected' : '' ?>>
          <?= h($v['name']) ?>
        </option>
      <?php endwhile; if ($last_cname !== '') echo '</optgroup>'; ?>
    </select>

    <div class="scope-pills">
      <a href="?<?= h(http_build_query(array_merge($_GET, ['scope' => '', 'q' => $search]))) ?>"
         class="scope-pill <?= $filter_scope === '' ? 'active' : '' ?>">
        <i class="fa fa-list"></i> All
      </a>

      <a href="?<?= h(http_build_query(array_merge($_GET, ['scope' => 'cohort', 'q' => $search]))) ?>"
         class="scope-pill <?= $filter_scope === 'cohort' ? 'active' : '' ?>">
        <i class="fa fa-layer-group"></i> Cohort-Wide
      </a>

      <a href="?<?= h(http_build_query(array_merge($_GET, ['scope' => 'venture', 'q' => $search]))) ?>"
         class="scope-pill <?= $filter_scope === 'venture' ? 'active' : '' ?>">
        <i class="fa fa-rocket"></i> Venture-Specific
      </a>
    </div>

    <button type="submit" class="btn btn-secondary btn-sm"><i class="fa fa-search"></i></button>

    <?php if ($search || $filter_cohort || $filter_venture || $filter_scope || (!$IS_MENTOR && $filter_mentor)): ?>
      <a href="<?= h($clear_url) ?>" class="btn btn-sm" style="color:var(--danger,#ef4444)">
        <i class="fa fa-times"></i> Clear
      </a>
    <?php endif; ?>
  </form>
</div>

<!-- Assignment list -->
<?php if (empty($grouped)): ?>
  <div class="assign-empty">
    <i class="fa fa-link"></i>
    <h3>No assignments found</h3>
    <p><?= $IS_MENTOR ? 'No assignments have been linked to your mentor profile yet.' : 'Assign mentors to cohorts or individual ventures to get started.' ?></p>

    <?php if (!$IS_MENTOR): ?>
      <button class="btn btn-primary" onclick="openAssignModal()">Create First Assignment</button>
    <?php endif; ?>
  </div>
<?php else: ?>

<div class="legacy-style-29fcfed456">
<?php foreach ($grouped as $cohort_id => $group): ?>

  <div class="group-header">
    <i class="fa fa-layer-group legacy-style-116e23f96e"></i>
    <?= h($group['label']) ?>

    <?php
      $cs = (string)($group['status'] ?? '');
      $csb = $cs === 'active' ? 'badge-success' : ($cs === 'draft' ? 'badge-warning' : 'badge-gray');
    ?>
    <span class="badge <?= h($csb) ?>" style="font-size:10.5px"><?= h(ucfirst($cs)) ?></span>
    <span class="group-count"><?= count($group['rows']) ?> assignment<?= count($group['rows']) !== 1 ? 's' : '' ?></span>
  </div>

  <?php foreach ($group['rows'] as $row): ?>
  <div class="assignment-row">

    <!-- Mentor -->
    <div class="ar-cell legacy-style-b87ca3241d">
      <?php if (!empty($row['mentor_photo'])): ?>
        <img src="<?= SITE_URL . '/' . h($row['mentor_photo']) ?>" class="m-av" alt="">
      <?php else: ?>
        <div class="m-ini"><?= h(strtoupper(substr((string)$row['mentor_name'], 0, 2))) ?></div>
      <?php endif; ?>

      <div>
        <div class="m-name"><?= h($row['mentor_name']) ?></div>
        <div class="m-sub">
          <?= h($row['mentor_title'] ?? '') ?>
          <?= !empty($row['mentor_org']) ? ' &middot; ' . h($row['mentor_org']) : '' ?>
        </div>

        <?php if (!empty($row['mentor_expertise'])): ?>
          <div class="exp-tags">
            <?php foreach (array_slice(explode(',', (string)$row['mentor_expertise']), 0, 3) as $e): ?>
              <?php $e = trim($e); if (!$e) continue; ?>
              <span class="exp-tag"><?= h($expertise_labels[$e] ?? $e) ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Scope / Venture -->
    <div class="ar-cell legacy-style-b87ca3241d">
      <?php if (empty($row['venture_id'])): ?>
        <span class="cohort-scope-chip">
          <i class="fa fa-layer-group legacy-style-0d5be05fd9"></i> Whole Cohort
        </span>
      <?php else: ?>
        <?php if (!empty($row['venture_logo'])): ?>
          <img src="<?= SITE_URL . '/' . h($row['venture_logo']) ?>" class="v-logo" alt="">
        <?php else: ?>
          <div class="v-ini"><?= h(strtoupper(substr((string)($row['venture_name'] ?? ''), 0, 2))) ?></div>
        <?php endif; ?>

        <div>
          <div class="legacy-style-abb308a475">
            <?= h($row['venture_name']) ?>
          </div>
          <div class="legacy-style-b3c990677b">
            <?= h($stage_labels[$row['venture_stage']] ?? $row['venture_stage'] ?? '') ?>
            <?= !empty($row['venture_sector']) ? ' &middot; ' . h($row['venture_sector']) : '' ?>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Role + Sessions + Date -->
    <div class="ar-cell legacy-style-47b618fc66">
      <?php if (!empty($row['role'])): ?>
        <div class="role-badge"><i class="fa fa-id-badge"></i> <?= h($row['role']) ?></div>
      <?php endif; ?>

      <?php if (!empty($row['notes']) && !$IS_MENTOR): ?>
        <div class="legacy-style-248401b9f8">
          <?= h(safe_truncate($row['notes'], 70)) ?>
        </div>
      <?php endif; ?>

      <div class="legacy-style-c158a9e2a1">
        <?php if ((int)$row['sessions_done'] > 0): ?>
          <span class="sess-chip sess-done">
            <i class="fa fa-check-circle"></i> <?= (int)$row['sessions_done'] ?> done
          </span>
        <?php endif; ?>

        <?php if ((int)$row['sessions_upcoming'] > 0): ?>
          <span class="sess-chip sess-upcoming">
            <i class="fa fa-clock"></i> <?= (int)$row['sessions_upcoming'] ?> upcoming
          </span>
        <?php endif; ?>

        <?php if (!(int)$row['sessions_done'] && !(int)$row['sessions_upcoming'] && !empty($row['venture_id'])): ?>
          <span class="legacy-style-7491483f4d">No sessions yet</span>
        <?php endif; ?>
      </div>

      <div class="legacy-style-c957b49f54">
        Assigned <?= !empty($row['assigned_at']) ? h(date('M j, Y', strtotime((string)$row['assigned_at']))) : 'N/A' ?>
      </div>
    </div>

    <!-- Actions -->
    <div class="ar-cell legacy-style-9944357314">
      <div class="tbl-actions">
        <?php if (!empty($row['venture_id'])): ?>
          <a href="mentor-sessions.php?mentor_id=<?= (int)$row['mentor_id'] ?>&venture_id=<?= (int)$row['venture_id'] ?>"
             class="btn btn-sm btn-teal" title="Sessions for this pair">
            <i class="fa fa-calendar-alt"></i>
          </a>
        <?php endif; ?>

        <?php if (!$IS_MENTOR): ?>
          <button class="btn btn-sm btn-secondary" title="Edit"
                  onclick='openEditModal(<?= json_encode($row, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'>
            <i class="fa fa-edit"></i>
          </button>

          <form method="POST" action="includes/process-mentor-assignments.php"
                onsubmit="return confirm('Remove this assignment? Existing sessions are kept.')" class="legacy-style-cccfa4560d">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
            <button class="btn btn-sm btn-danger" title="Remove"><i class="fa fa-unlink"></i></button>
          </form>
        <?php endif; ?>
      </div>
    </div>

  </div>
  <?php endforeach; ?>
<?php endforeach; ?>
</div>
<?php endif; ?>

</div>
</div>

<?php if (!$IS_MENTOR): ?>
<!-- ----------- NEW ASSIGNMENT MODAL ----------- -->
<div class="modal-overlay" id="assignModal">
<div class="modal modal-lg">
  <div class="modal-header">
    <h2 class="modal-title">New Assignment</h2>
    <button type="button" class="modal-close" onclick="closeAssignModal()">x</button>
  </div>

  <form method="POST" action="includes/process-mentor-assignments.php" id="assignForm">
    <input type="hidden" name="action" value="create">

    <div class="modal-body">

      <div class="legacy-style-13f24e7378">
        <label class="step-label"><span class="step-num">1</span> Select Mentor <span class="req">*</span></label>
        <input type="text" id="mentorSearch" class="form-control legacy-style-761d3addb2" placeholder="Search mentors by name or organisation..."
               oninput="filterMentorCards(this.value)">

        <div class="mentor-picker" id="mentorPicker">
          <?php $mentors_dd->data_seek(0); while ($m = $mentors_dd->fetch_assoc()): ?>
          <label class="mentor-pick-card" id="mpc_<?= (int)$m['id'] ?>" onclick="selectMentor(<?= (int)$m['id'] ?>)">
            <input type="radio" name="mentor_id" value="<?= (int)$m['id'] ?>" required>

            <?php if (!empty($m['photo'])): ?>
              <img src="<?= SITE_URL . '/' . h($m['photo']) ?>" class="m-av" style="width:32px;height:32px" alt="">
            <?php else: ?>
              <div class="m-ini legacy-style-1ddf4ea50c">
                <?= h(strtoupper(substr((string)$m['full_name'], 0, 2))) ?>
              </div>
            <?php endif; ?>

            <div class="legacy-style-3922d32600">
              <div class="mpc-name"><?= h($m['full_name']) ?></div>
              <div class="mpc-sub">
                <?= h($m['job_title'] ?? '') ?>
                <?= !empty($m['organisation']) ? ' &middot; ' . h($m['organisation']) : '' ?>
              </div>
            </div>
          </label>
          <?php endwhile; ?>
        </div>
      </div>

      <div class="legacy-style-49f14f8f83">
        <label class="step-label"><span class="step-num">2</span> Select Cohort <span class="req">*</span></label>
        <select name="cohort_id" id="modal_cohort" class="form-control" required onchange="loadVenturesByCohort(this.value)">
          <option value="">Select a Cohort</option>
          <?php $cohorts_dd->data_seek(0); while ($c = $cohorts_dd->fetch_assoc()): ?>
            <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
          <?php endwhile; ?>
        </select>
      </div>

      <div class="legacy-style-49f14f8f83">
        <label class="step-label"><span class="step-num">3</span> Assignment Scope</label>

        <div class="scope-toggle">
          <button type="button" class="scope-btn active" id="scopeBtnCohort" onclick="setScope('cohort')">
            <i class="fa fa-layer-group"></i>&nbsp; Whole Cohort
          </button>
          <button type="button" class="scope-btn" id="scopeBtnVenture" onclick="setScope('venture')">
            <i class="fa fa-rocket"></i>&nbsp; Specific Venture(s)
          </button>
        </div>

        <input type="hidden" name="scope" id="modal_scope" value="cohort">

        <div class="scope-panel" id="ventureScopePanel">
          <div class="venture-picker-list" id="venturePicker">
            <div class="legacy-style-cbc45642bd">
              Select a cohort first.
            </div>
          </div>
          <span class="form-hint legacy-style-0accd93366">
            Select one or more ventures. Leave blank to assign to the whole cohort instead.
          </span>
        </div>
      </div>

      <div class="form-grid form-grid-2">
        <div class="form-group">
          <label>Role / Title <span class="legacy-style-7491483f4d">(optional)</span></label>
          <input type="text" name="role" class="form-control" placeholder="e.g. Lead Mentor, Technical Advisor, Industry Expert">
        </div>

        <div class="form-group full">
          <label>Internal Notes <span class="legacy-style-7491483f4d">(optional)</span></label>
          <textarea name="notes" class="form-control" rows="2" placeholder="Any context about this assignment..."></textarea>
        </div>
      </div>

    </div>

    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeAssignModal()">Cancel</button>
      <button type="submit" class="btn btn-primary"><i class="fa fa-link"></i> Create Assignment</button>
    </div>
  </form>
</div>
</div>

<!-- ----------- EDIT ASSIGNMENT MODAL ----------- -->
<div class="modal-overlay" id="editModal">
<div class="modal">
  <div class="modal-header">
    <h2 class="modal-title" id="editModalTitle">Edit Assignment</h2>
    <button type="button" class="modal-close" onclick="closeEditModal()">x</button>
  </div>

  <form method="POST" action="includes/process-mentor-assignments.php">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="id" id="edit_id">

    <div class="modal-body">
      <div id="edit_context"
           class="legacy-style-4a73d516ac">
      </div>

      <div class="form-grid form-grid-2">
        <div class="form-group">
          <label>Role / Title</label>
          <input type="text" name="role" id="edit_role" class="form-control" placeholder="e.g. Lead Mentor">
        </div>

        <div class="form-group full">
          <label>Internal Notes</label>
          <textarea name="notes" id="edit_notes" class="form-control" rows="3"></textarea>
        </div>
      </div>
    </div>

    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
      <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Changes</button>
    </div>
  </form>
</div>
</div>
<?php endif; ?>

<script>
document.getElementById('sidebarToggle')?.addEventListener('click', () => {
  document.getElementById('adminSidebar')?.classList.toggle('collapsed');
  document.getElementById('adminMain')?.classList.toggle('collapsed');
});

<?php if (!$IS_MENTOR): ?>
const assignModal = document.getElementById('assignModal');

function openAssignModal() {
  assignModal.querySelector('form').reset();
  document.querySelectorAll('.mentor-pick-card').forEach(c => c.classList.remove('selected'));
  setScope('cohort');
  document.getElementById('venturePicker').innerHTML =
    '<div class="legacy-style-cbc45642bd">Select a cohort first.</div>';
  assignModal.classList.add('open');
}

function closeAssignModal() {
  assignModal.classList.remove('open');
}

assignModal.addEventListener('click', e => {
  if (e.target === assignModal) closeAssignModal();
});

function selectMentor(id) {
  document.querySelectorAll('.mentor-pick-card').forEach(c => c.classList.remove('selected'));
  const card = document.getElementById('mpc_' + id);

  if (card) {
    card.classList.add('selected');
    card.querySelector('input').checked = true;
  }
}

function filterMentorCards(q) {
  q = q.toLowerCase();

  document.querySelectorAll('.mentor-pick-card').forEach(card => {
    card.style.display = card.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}

function setScope(scope) {
  document.getElementById('modal_scope').value = scope;
  document.getElementById('scopeBtnCohort').classList.toggle('active', scope === 'cohort');
  document.getElementById('scopeBtnVenture').classList.toggle('active', scope === 'venture');
  document.getElementById('ventureScopePanel').classList.toggle('active', scope === 'venture');
}

const allVentures = <?= json_encode($ventures_for_js, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
const stageLabels = <?= json_encode($stage_labels, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;

function loadVenturesByCohort(cohortId) {
  const picker = document.getElementById('venturePicker');
  cohortId = parseInt(cohortId);

  if (!cohortId) {
    picker.innerHTML =
      '<div class="legacy-style-cbc45642bd">Select a cohort first.</div>';
    return;
  }

  const filtered = allVentures.filter(v => parseInt(v.cohort_id) === cohortId);

  if (!filtered.length) {
    picker.innerHTML =
      '<div class="legacy-style-cbc45642bd">No ventures in this cohort yet.</div>';
    return;
  }

  picker.innerHTML = filtered.map(v => `
    <label class="vpl-row">
      <input type="checkbox" name="venture_ids[]" value="${v.id}"
             onchange="this.closest('.vpl-row').classList.toggle('selected', this.checked)">
      <span class="legacy-style-98cdf86ca1">${esc(v.name)}</span>
      <span class="legacy-style-93f7d3a811">
        ${esc(stageLabels[v.stage] || v.stage || '')}${v.sector ? ' &middot; ' + esc(v.sector) : ''}
      </span>
    </label>
  `).join('');
}

function esc(str) {
  const d = document.createElement('div');
  d.textContent = str || '';
  return d.innerHTML;
}

const editModal = document.getElementById('editModal');

function openEditModal(d) {
  document.getElementById('edit_id').value = d.id || '';
  document.getElementById('edit_role').value = d.role || '';
  document.getElementById('edit_notes').value = d.notes || '';

  const scope = d.venture_id
    ? `<strong>${esc(d.mentor_name)}</strong> &rarr; Venture: <strong>${esc(d.venture_name)}</strong>`
    : `<strong>${esc(d.mentor_name)}</strong> &rarr; Whole Cohort: <em>${esc(d.cohort_name)}</em>`;

  document.getElementById('edit_context').innerHTML =
    '<span class="legacy-style-93f7d3a811">Assignment:</span><br>' + scope;

  document.getElementById('editModalTitle').textContent = 'Edit: ' + (d.mentor_name || 'Assignment');
  editModal.classList.add('open');
}

function closeEditModal() {
  editModal.classList.remove('open');
}

editModal.addEventListener('click', e => {
  if (e.target === editModal) closeEditModal();
});
<?php endif; ?>
</script>

</body>
</html>
