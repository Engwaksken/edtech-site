<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

function sr_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$survey_id = (int)($_GET['id'] ?? 0);

if ($survey_id <= 0) {
    header('Location: surveys.php');
    exit;
}

/* -- Survey ---------------------------------------- */
$stmt = $conn->prepare("SELECT * FROM surveys WHERE id=? LIMIT 1");
$stmt->bind_param('i', $survey_id);
$stmt->execute();
$survey = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$survey) { header('Location: surveys.php'); exit; }

/* -- Questions ------------------------------------- */
$questions = [];
$stmt = $conn->prepare("SELECT * FROM survey_questions WHERE survey_id=? ORDER BY sort_order ASC, id ASC");
$stmt->bind_param('i', $survey_id);
$stmt->execute();
$qres = $stmt->get_result();
while ($q = $qres->fetch_assoc()) {
    $q['options'] = [];
    $opt = $conn->prepare("SELECT * FROM survey_question_options WHERE question_id=? ORDER BY sort_order ASC");
    $qid = (int)$q['id'];
    $opt->bind_param('i', $qid);
    $opt->execute();
    $ores = $opt->get_result();
    while ($o = $ores->fetch_assoc()) $q['options'][] = $o;
    $opt->close();
    $questions[] = $q;
}
$stmt->close();

/* -- Responses (with pagination) ------------------- */
$per_page = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

$search = trim((string)($_GET['q'] ?? ''));
$filter_date = trim((string)($_GET['date'] ?? ''));

$where_clauses = ["r.survey_id = ?"];
$bind_types    = "i";
$bind_vals     = [$survey_id];

if ($search !== '') {
    $where_clauses[] = "(r.respondent_name LIKE ? OR r.respondent_email LIKE ? OR r.venture_name LIKE ?)";
    $bind_types .= "sss";
    $s = "%{$search}%";
    $bind_vals[] = $s; $bind_vals[] = $s; $bind_vals[] = $s;
}
if ($filter_date !== '') {
    $where_clauses[] = "DATE(r.submitted_at) = ?";
    $bind_types .= "s";
    $bind_vals[] = $filter_date;
}

$where_sql = implode(' AND ', $where_clauses);

// Total count
$count_stmt = $conn->prepare("SELECT COUNT(*) FROM survey_responses r WHERE {$where_sql}");
$count_stmt->bind_param($bind_types, ...$bind_vals);
$count_stmt->execute();
$total_responses = (int)$count_stmt->get_result()->fetch_row()[0];
$count_stmt->close();
$total_pages = max(1, (int)ceil($total_responses / $per_page));

// Fetch page
$responses = [];
$list_stmt = $conn->prepare("
    SELECT r.*, v.name AS venture_display_name
    FROM survey_responses r
    LEFT JOIN ventures v ON v.id = r.venture_id
    WHERE {$where_sql}
    ORDER BY r.submitted_at DESC
    LIMIT ? OFFSET ?
");
$bind_types_pg = $bind_types . "ii";
$bind_vals_pg  = array_merge($bind_vals, [$per_page, $offset]);
$list_stmt->bind_param($bind_types_pg, ...$bind_vals_pg);
$list_stmt->execute();
$rres = $list_stmt->get_result();
while ($r = $rres->fetch_assoc()) $responses[] = $r;
$list_stmt->close();

/* -- Fetch all answers for listed responses -------- */
$answers_map = []; // [response_id][question_id] => answer_value(s)
if (!empty($responses)) {
    $rids = implode(',', array_map(fn($r) => (int)$r['id'], $responses));
    $ares = $conn->query("SELECT * FROM survey_response_answers WHERE response_id IN ({$rids})");
    while ($a = $ares->fetch_assoc()) {
        $rid = (int)$a['response_id'];
        $qid = (int)$a['question_id'];
        if (!isset($answers_map[$rid][$qid])) $answers_map[$rid][$qid] = [];
        $answers_map[$rid][$qid][] = $a['answer_value'];
    }
}

/* -- Summary stats (all time, no filter) ---------- */
$stats_stmt = $conn->prepare("
    SELECT COUNT(*) AS total,
           COUNT(DISTINCT DATE(submitted_at)) AS active_days,
           MIN(submitted_at) AS first_at,
           MAX(submitted_at) AS last_at
    FROM survey_responses WHERE survey_id=?
");
$stats_stmt->bind_param('i', $survey_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
$stats_stmt->close();

/* -- Per-question aggregate ------------------------ */
$q_stats = []; // [question_id] => ['counts'=>[val=>n], 'total'=>n, 'avg'=>x]
foreach ($questions as $q) {
    $qid = (int)$q['id'];
    $ans_stmt = $conn->prepare("SELECT answer_value FROM survey_response_answers WHERE question_id=? AND answer_value != ''");
    $ans_stmt->bind_param('i', $qid);
    $ans_stmt->execute();
    $ares2 = $ans_stmt->get_result();
    $counts = []; $total = 0; $sum = 0;
    while ($row = $ares2->fetch_row()) {
        $val = $row[0];
        $counts[$val] = ($counts[$val] ?? 0) + 1;
        $total++;
        if (is_numeric($val)) $sum += (float)$val;
    }
    $ans_stmt->close();
    arsort($counts);
    $q_stats[$qid] = [
        'counts' => $counts,
        'total'  => $total,
        'avg'    => $total > 0 && in_array($q['field_type'], ['rating','linear_scale','number']) ? round($sum / $total, 1) : null,
    ];
}

/* -- CSV export ------------------------------------ */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="survey-' . $survey_id . '-responses.csv"');
    $out = fopen('php://output', 'w');
    // Header row
    $headers = ['ID','Submitted At','Name','Email','Venture'];
    foreach ($questions as $q) $headers[] = $q['question_text'];
    fputcsv($out, $headers);

    $all_stmt = $conn->prepare("
        SELECT r.*, v.name AS venture_display_name
        FROM survey_responses r
        LEFT JOIN ventures v ON v.id = r.venture_id
        WHERE r.survey_id=?
        ORDER BY r.submitted_at DESC
    ");
    $all_stmt->bind_param('i', $survey_id);
    $all_stmt->execute();
    $all_res = $all_stmt->get_result();
    $all_responses = [];
    while ($r = $all_res->fetch_assoc()) $all_responses[] = $r;
    $all_stmt->close();

    $all_rids = implode(',', array_map(fn($r) => (int)$r['id'], $all_responses) ?: [0]);
    $all_answers_map = [];
    if (!empty($all_responses)) {
        $aall = $conn->query("SELECT * FROM survey_response_answers WHERE response_id IN ({$all_rids})");
        while ($a = $aall->fetch_assoc()) {
            $rid = (int)$a['response_id'];
            $qid = (int)$a['question_id'];
            if (!isset($all_answers_map[$rid][$qid])) $all_answers_map[$rid][$qid] = [];
            $all_answers_map[$rid][$qid][] = $a['answer_value'];
        }
    }

    foreach ($all_responses as $r) {
        $row = [
            $r['id'],
            $r['submitted_at'],
            $r['respondent_name'] ?: ($r['venture_display_name'] ?? ''),
            $r['respondent_email'] ?? '',
            $r['venture_name'] ?: ($r['venture_display_name'] ?? ''),
        ];
        foreach ($questions as $q) {
            $vals = $all_answers_map[(int)$r['id']][(int)$q['id']] ?? [];
            $row[] = implode('; ', $vals);
        }
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

$field_type_icons = [
    'short_text'=>'fa-font','long_text'=>'fa-align-left','email'=>'fa-envelope',
    'phone'=>'fa-phone','number'=>'fa-hashtag','currency'=>'fa-dollar-sign',
    'date'=>'fa-calendar','single_choice'=>'fa-dot-circle','multiple_choice'=>'fa-check-square',
    'dropdown'=>'fa-chevron-down','yes_no'=>'fa-toggle-on','rating'=>'fa-star',
    'linear_scale'=>'fa-sliders-h','consent'=>'fa-shield-alt'
];

$external_link = rtrim(SITE_URL, '/') . '/survey.php?t=' . urlencode($survey['external_token']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
<meta charset="UTF-8">
<title>Responses - <?= sr_h($survey['title']) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/admin.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

</head>
<body>
<?php include 'includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">

  <!-- --- Hero header ------------------------------- -->
  <div class="rp-main">
    <div class="rp-hero">
      <div class="rp-hero-top">
        <div>
          <div class="rp-hero-meta">
            <a href="index.php"><i class="fa fa-home"></i> Dashboard</a>
            <span>/</span>
            <a href="surveys.php">Surveys</a>
            <span>/</span>
            Responses
          </div>
          <h1 class="rp-hero-title"><?= sr_h($survey['title']) ?></h1>
          <div class="rp-hero-sub">
            <span class="status-pill sp-<?= sr_h($survey['status']) ?>"><?= sr_h(ucfirst($survey['status'])) ?></span>
            &nbsp;.&nbsp;
            <?= count($questions) ?> question<?= count($questions) !== 1 ? 's' : '' ?>
            &nbsp;.&nbsp;
            <?= (int)$stats['total'] ?> total response<?= (int)$stats['total'] !== 1 ? 's' : '' ?>
          </div>
        </div>
        <div class="rp-hero-actions">
          <a href="survey-builder.php?id=<?= $survey_id ?>" class="btn btn-ghost btn-sm">
            <i class="fa fa-edit"></i> Builder
          </a>
          <a href="?id=<?= $survey_id ?>&export=csv" class="btn btn-ghost btn-sm">
            <i class="fa fa-download"></i> Export CSV
          </a>
          <a href="<?= sr_h($external_link) ?>" target="_blank" class="btn btn-ghost btn-sm">
            <i class="fa fa-external-link-alt"></i> Preview
          </a>
        </div>
      </div>
    </div>

    <!-- --- Stat cards -------------------------------- -->
    <div class="stats-row">
      <div class="stat-cell">
        <div class="stat-label">Total Responses</div>
        <div class="stat-value"><?= number_format((int)$stats['total']) ?></div>
        <div class="stat-sub">All time</div>
      </div>
      <div class="stat-cell">
        <div class="stat-label">Active Days</div>
        <div class="stat-value"><?= (int)$stats['active_days'] ?></div>
        <div class="stat-sub">Days with submissions</div>
      </div>
      <div class="stat-cell">
        <div class="stat-label">First Response</div>
        <div class="stat-value legacy-style-87889f3a74">
          <?= $stats['first_at'] ? date('M j', strtotime($stats['first_at'])) : '-' ?>
        </div>
        <div class="stat-sub"><?= $stats['first_at'] ? date('Y', strtotime($stats['first_at'])) : 'No responses yet' ?></div>
      </div>
      <div class="stat-cell">
        <div class="stat-label">Latest Response</div>
        <div class="stat-value legacy-style-87889f3a74">
          <?= $stats['last_at'] ? date('M j', strtotime($stats['last_at'])) : '-' ?>
        </div>
        <div class="stat-sub"><?= $stats['last_at'] ? date('g:i A', strtotime($stats['last_at'])) : 'No responses yet' ?></div>
      </div>
    </div>

    <!-- --- Main grid --------------------------------- -->
    <div class="rp-grid">

      <!-- --- Left: response list -------------------- -->
      <div>
        <div class="rp-card">
          <div class="rp-card-header">
            <div class="rp-card-title">
              <i class="fa fa-list"></i>
              Individual Responses
              <?php if ($total_responses > 0): ?>
                <span class="legacy-style-8d886d22ce">
                  (<?= number_format($total_responses) ?> result<?= $total_responses !== 1 ? 's' : '' ?>)
                </span>
              <?php endif; ?>
            </div>
            <!-- Filter form -->
            <form method="GET" action="" class="filter-bar">
              <input type="hidden" name="id" value="<?= $survey_id ?>">
              <div class="filter-wrap">
                <i class="fa fa-search"></i>
                <input type="text" name="q" class="filter-input" placeholder="Search name, email, venture"
                       value="<?= sr_h($search) ?>">
              </div>
              <input type="date" name="date" class="filter-date"
                     value="<?= sr_h($filter_date) ?>" title="Filter by date">
              <button class="btn btn-secondary btn-sm" type="submit"><i class="fa fa-filter"></i></button>
              <?php if ($search || $filter_date): ?>
                <a href="?id=<?= $survey_id ?>" class="btn btn-secondary btn-sm"><i class="fa fa-times"></i></a>
              <?php endif; ?>
            </form>
          </div>

          <?php if (empty($responses)): ?>
            <div class="empty-resp">
              <div class="empty-icon"><i class="fa fa-inbox"></i></div>
              <h3><?= $search || $filter_date ? 'No matching responses' : 'No responses yet' ?></h3>
              <p><?= $search || $filter_date ? 'Try a different search or clear the filters.' : 'Share the survey link to start collecting responses.' ?></p>
            </div>
          <?php else: ?>
            <table class="resp-table">
              <thead>
                <tr>
                  <th>Respondent</th>
                  <th>Venture</th>
                  <th>Answers</th>
                  <th>Submitted</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($responses as $r): ?>
                  <?php
                  $display_name  = $r['respondent_name'] ?: ($r['venture_display_name'] ?? 'Anonymous');
                  $display_email = $r['respondent_email'] ?? '';
                  $venture_label = $r['venture_name'] ?: ($r['venture_display_name'] ?? '');
                  $initials = strtoupper(substr(preg_replace('/[^a-zA-Z ]/', '', $display_name), 0, 2));
                  if (strlen($initials) < 2 && strlen($display_name) > 0) $initials = strtoupper(substr($display_name, 0, 2));
                  $ans_count = isset($answers_map[(int)$r['id']]) ? count($answers_map[(int)$r['id']]) : 0;
                  ?>
                  <tr onclick="openDrawer(<?= (int)$r['id'] ?>)" data-rid="<?= (int)$r['id'] ?>">
                    <td>
                      <div class="resp-name-cell">
                        <div class="resp-avatar"><?= sr_h($initials) ?></div>
                        <div>
                          <div class="resp-name"><?= sr_h($display_name) ?></div>
                          <?php if ($display_email): ?>
                            <div class="resp-email"><?= sr_h($display_email) ?></div>
                          <?php endif; ?>
                        </div>
                      </div>
                    </td>
                    <td>
                      <?php if ($venture_label): ?>
                        <span class="resp-venture"><i class="fa fa-building"></i> <?= sr_h($venture_label) ?></span>
                      <?php else: ?>
                        <span class="legacy-style-9bb4e02037">-</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="resp-count-badge"><i class="fa fa-check"></i> <?= $ans_count ?> / <?= count($questions) ?></span>
                    </td>
                    <td class="resp-time">
                      <?= date('M j, Y', strtotime($r['submitted_at'])) ?><br>
                      <span class="legacy-style-652db75ef3"><?= date('g:i A', strtotime($r['submitted_at'])) ?></span>
                    </td>
                    <td onclick="event.stopPropagation()">
                      <form class="del-form" method="POST" action="includes/process-surveys.php"
                            onsubmit="return confirm('Delete this response? This cannot be undone.')">
                        <input type="hidden" name="action" value="delete_response">
                        <input type="hidden" name="survey_id" value="<?= $survey_id ?>">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-sm btn-danger" type="submit" title="Delete">
                          <i class="fa fa-trash"></i>
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
              <div class="pagination">
                <div class="page-info">
                  Showing <?= number_format($offset + 1) ?>-<?= number_format(min($offset + $per_page, $total_responses)) ?>
                  of <?= number_format($total_responses) ?>
                </div>
                <div class="page-links">
                  <?php
                  $base_url = '?id=' . $survey_id . ($search ? '&q=' . urlencode($search) : '') . ($filter_date ? '&date=' . urlencode($filter_date) : '');
                  ?>
                  <a class="page-link <?= $page <= 1 ? 'disabled' : '' ?>"
                     href="<?= $base_url ?>&page=<?= max(1, $page - 1) ?>">
                    <i class="fa fa-chevron-left legacy-style-7171988908"></i>
                  </a>
                  <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                    <a class="page-link <?= $p === $page ? 'active' : '' ?>"
                       href="<?= $base_url ?>&page=<?= $p ?>"><?= $p ?></a>
                  <?php endfor; ?>
                  <a class="page-link <?= $page >= $total_pages ? 'disabled' : '' ?>"
                     href="<?= $base_url ?>&page=<?= min($total_pages, $page + 1) ?>">
                    <i class="fa fa-chevron-right legacy-style-7171988908"></i>
                  </a>
                </div>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- --- Right: question summaries ---------------- -->
      <div>
        <div class="rp-card">
          <div class="rp-card-header">
            <div class="rp-card-title"><i class="fa fa-chart-bar"></i> Question Summaries</div>
          </div>
          <?php if (empty($questions)): ?>
            <div class="empty-resp legacy-style-e60a353027">
              <p>No questions in this survey.</p>
            </div>
          <?php else: ?>
            <?php foreach ($questions as $idx => $q): ?>
              <?php
              $qid   = (int)$q['id'];
              $qs    = $q_stats[$qid] ?? ['counts'=>[], 'total'=>0, 'avg'=>null];
              $total_q = $qs['total'];
              $ft    = $q['field_type'];
              $icon  = $field_type_icons[$ft] ?? 'fa-question';
              ?>
              <div class="q-summary-item">
                <div class="q-summary-top">
                  <div class="q-summary-text"><?= ($idx + 1) ?>. <?= sr_h($q['question_text']) ?></div>
                  <div class="q-type-icon"><i class="fa <?= $icon ?>"></i></div>
                </div>
                <div class="q-resp-count"><?= $total_q ?> response<?= $total_q !== 1 ? 's' : '' ?></div>

                <?php if ($qs['avg'] !== null): ?>
                  <div class="avg-badge">
                    <?= $qs['avg'] ?> <span>avg</span>
                  </div>
                <?php endif; ?>

                <?php if (in_array($ft, ['single_choice','multiple_choice','dropdown','yes_no'], true) && !empty($qs['counts'])): ?>
                  <?php
                  $max_count = max($qs['counts']);
                  $shown = 0;
                  foreach ($qs['counts'] as $label => $cnt):
                    if ($shown++ >= 5) break;
                    $pct = $total_q > 0 ? round(($cnt / $total_q) * 100) : 0;
                  ?>
                    <div class="bar-item">
                      <div class="bar-label-row">
                        <span class="bar-label"><?= sr_h($label) ?></span>
                        <span class="bar-pct"><?= $pct ?>%</span>
                      </div>
                      <div class="bar-track">
                        <div class="bar-fill" style="width:<?= $pct ?>%"></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                  <?php if (count($qs['counts']) > 5): ?>
                    <div class="legacy-style-be165fd577">+<?= count($qs['counts']) - 5 ?> more options</div>
                  <?php endif; ?>

                <?php elseif (in_array($ft, ['rating','linear_scale'], true) && !empty($qs['counts'])): ?>
                  <?php
                  $min_s = (int)($q['scale_min'] ?: 1);
                  $max_s = (int)($q['scale_max'] ?: 5);
                  ?>
                  <div class="legacy-style-04fb8090a2">
                    <?php for ($s = $min_s; $s <= $max_s; $s++): ?>
                      <?php
                      $cnt = $qs['counts'][(string)$s] ?? 0;
                      $pct = $total_q > 0 ? round(($cnt / $total_q) * 100) : 0;
                      $opacity = $pct > 0 ? max(.15, $pct / 100) : .08;
                      ?>
                      <div class="legacy-style-3a21395773">
                        <div class="legacy-style-04f38d0e92"><?= $s ?></div>
                        <div style="height:40px;background:var(--r-accent);border-radius:4px;opacity:<?= $opacity ?>;margin-bottom:3px"></div>
                        <div class="legacy-style-01aa435119"><?= $cnt ?></div>
                      </div>
                    <?php endfor; ?>
                  </div>

                <?php elseif ($total_q === 0): ?>
                  <div class="legacy-style-35a79c1391">No answers yet</div>

                <?php else: ?>
                  <div class="legacy-style-ed54dfa1c9">
                    <?= $total_q ?> text answer<?= $total_q !== 1 ? 's' : '' ?> recorded
                  </div>
                <?php endif; ?>

              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /rp-grid -->
  </div><!-- /rp-main -->
</div><!-- /admin-main -->

<!-- ------------------------------------------------
     Response Detail Drawer
------------------------------------------------ -->
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
<div class="drawer" id="responseDrawer">
  <div class="drawer-header">
    <h3 id="drawerTitle">Response Details</h3>
    <button class="drawer-close" onclick="closeDrawer()"><i class="fa fa-times"></i></button>
  </div>
  <div class="drawer-body" id="drawerBody">
    <div class="legacy-style-67ad544b35">
      <i class="fa fa-circle-notch fa-spin legacy-style-f98bc17a33"></i>
    </div>
  </div>
</div>

<!-- Embed response data for drawer JS -->
<script>
const RESPONSES_DATA = <?= json_encode(
  array_map(function($r) use ($answers_map, $questions) {
    return [
      'id'              => (int)$r['id'],
      'name'            => $r['respondent_name'] ?: ($r['venture_display_name'] ?? 'Anonymous'),
      'email'           => $r['respondent_email'] ?? '',
      'venture'         => $r['venture_name'] ?: ($r['venture_display_name'] ?? ''),
      'submitted_at'    => $r['submitted_at'],
      'answers'         => $answers_map[(int)$r['id']] ?? [],
    ];
  }, $responses)
, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;

const QUESTIONS_DATA = <?= json_encode(
  array_map(fn($q) => [
    'id'           => (int)$q['id'],
    'question_text'=> $q['question_text'],
    'field_type'   => $q['field_type'],
    'section_title'=> $q['section_title'],
  ], $questions)
, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;

/* -- Drawer --------------------------------------- */
function openDrawer(rid) {
  const resp = RESPONSES_DATA.find(r => r.id === rid);
  if (!resp) return;

  const overlay = document.getElementById('drawerOverlay');
  const drawer  = document.getElementById('responseDrawer');
  const body    = document.getElementById('drawerBody');
  const title   = document.getElementById('drawerTitle');

  const initials = (resp.name || 'AN').replace(/[^a-zA-Z ]/g,'').slice(0,2).toUpperCase();
  title.textContent = resp.name || 'Response';

  const date = new Date(resp.submitted_at);
  const fmt  = date.toLocaleDateString('en-US', { month:'long', day:'numeric', year:'numeric' });
  const time = date.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit' });

  let html = `
    <div class="drawer-resp-meta">
      <div class="meta-row">
        <span class="meta-label">Name</span>
        <span class="meta-val">${escH(resp.name)}</span>
      </div>
      <div class="meta-row">
        <span class="meta-label">Submitted</span>
        <span class="meta-val">${escH(fmt)} . ${escH(time)}</span>
      </div>
      ${resp.email ? `<div class="meta-row"><span class="meta-label">Email</span><span class="meta-val">${escH(resp.email)}</span></div>` : ''}
      ${resp.venture ? `<div class="meta-row"><span class="meta-label">Venture</span><span class="meta-val">${escH(resp.venture)}</span></div>` : ''}
    </div>`;

  QUESTIONS_DATA.forEach((q, idx) => {
    const vals = resp.answers[q.id] || [];
    let answerHtml;

    if (vals.length === 0) {
      answerHtml = `<div class="drawer-answer empty">No answer</div>`;
    } else if (vals.length === 1) {
      answerHtml = `<div class="drawer-answer">${escH(vals[0])}</div>`;
    } else {
      const chips = vals.map(v => `<span class="drawer-answer-chip"><i class="fa fa-check legacy-style-7624689853"></i>${escH(v)}</span>`).join('');
      answerHtml = `<div class="drawer-answer legacy-style-94b80e1f5f">${chips}</div>`;
    }

    html += `
      <div class="drawer-q-block">
        ${q.section_title ? `<div class="drawer-q-label">${escH(q.section_title)}</div>` : ''}
        <div class="drawer-q-text">${idx + 1}. ${escH(q.question_text)}</div>
        ${answerHtml}
      </div>`;
  });

  body.innerHTML = html;

  overlay.classList.add('open');
  setTimeout(() => drawer.classList.add('open'), 10);
}

function closeDrawer() {
  document.getElementById('responseDrawer').classList.remove('open');
  document.getElementById('drawerOverlay').classList.remove('open');
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDrawer(); });

function escH(v) {
  return String(v ?? '').replace(/[&<>"']/g, m =>
    ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m]);
}

/* Animate bars on load */
window.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.bar-fill').forEach(el => {
    const w = el.style.width;
    el.style.width = '0';
    setTimeout(() => el.style.width = w, 100);
  });
});
</script>

</body>
</html>
