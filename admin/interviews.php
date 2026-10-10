<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) die('DB error.');
$conn->set_charset('utf8mb4');

ms_auth_gate();


if (!ms_has_page_permission($conn, 'interviews')) {
    http_response_code(403);
    include 'includes/sidebar.php'; ?>
    <div class="admin-main"><div class="admin-content legacy-style-5534667570">
    <h2>Access denied</h2>
    <p>Your role is not allowed to access Field Interviews.</p>
    </div></div><?php exit;
}

$IV_MANAGE_ROLES = [
    'admin', 'super-admin', 'superadmin', 'sup-admin', 'supper-admin', 'supperadmin',
    'programs-lead', 'program-lead', 'program-director', 'program-manager', 'meal-officer',
];

$IV_ROLE               = ms_current_role();
$CAN_MANAGE_INTERVIEWS = in_array($IV_ROLE, $IV_MANAGE_ROLES, true);
$MY_NAME               = trim((string)($ADMIN['full_name'] ?? ''));

function iv_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function iv_token(): string {
    return bin2hex(random_bytes(20));
}

$site_name = function_exists('get_setting') ? get_setting($conn, 'site_name', 'EdTech Fellowship') : 'EdTech Fellowship';
$site_url  = rtrim(SITE_URL, '/');


$ventures_res = $conn->query("SELECT id, name FROM ventures ORDER BY name ASC");
$ventures = [];
if ($ventures_res) while ($r = $ventures_res->fetch_assoc()) $ventures[] = $r;


$interviews = [];
if ($CAN_MANAGE_INTERVIEWS) {
    $interviews_res = $conn->query("
        SELECT i.*, v.name AS venture_name
        FROM interviews i
        LEFT JOIN ventures v ON v.id = i.venture_id
        ORDER BY i.created_at DESC
    ");
    if ($interviews_res) while ($r = $interviews_res->fetch_assoc()) $interviews[] = $r;
} else {
    $stmt = $conn->prepare("
        SELECT i.*, v.name AS venture_name
        FROM interviews i
        LEFT JOIN ventures v ON v.id = i.venture_id
        WHERE LOWER(TRIM(i.assigned_to)) = LOWER(TRIM(?))
        ORDER BY i.created_at DESC
    ");
    if ($stmt && $MY_NAME !== '') {
        $stmt->bind_param('s', $MY_NAME);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $interviews[] = $r;
        $stmt->close();
    }
}


$total       = count($interviews);
$completed   = count(array_filter($interviews, fn($i) => $i['status'] === 'completed'));
$pending     = count(array_filter($interviews, fn($i) => $i['status'] === 'pending'));
$in_progress = count(array_filter($interviews, fn($i) => $i['status'] === 'in_progress'));


$sections = [
    'venture_profile'   => 'Venture Profile',
    'product_service'   => 'Product & Service',
    'market_customers'  => 'Market & Customers',
    'business_model'    => 'Business Model',
    'team'              => 'Team & Talent',
    'finance'           => 'Finance & Funding',
    'technology'        => 'Technology',
    'pedagogy'          => 'Pedagogy & Curriculum',
    'partnerships'      => 'Partnerships & Networks',
    'monitoring_eval'   => 'M&E & Impact',
    'challenges'        => 'Challenges & Gaps',
    'support_needs'     => 'Support Needs',
];

$active_tab = in_array($_GET['tab'] ?? '', ['list','create','responses']) ? $_GET['tab'] : 'list';
if (!$CAN_MANAGE_INTERVIEWS) $active_tab = 'list';

$edit_id    = $CAN_MANAGE_INTERVIEWS ? (int)($_GET['edit'] ?? 0) : 0;
$edit_iv    = null;
if ($edit_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM interviews WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $edit_id);
    $stmt->execute();
    $edit_iv = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($edit_iv) $active_tab = 'create';
}


$view_response_id = $CAN_MANAGE_INTERVIEWS ? (int)($_GET['response'] ?? 0) : 0;
$view_response    = null;
$response_answers = [];
if ($view_response_id > 0) {
    $stmt = $conn->prepare("
        SELECT ir.*, i.title AS interview_title, i.venture_id,
               v.name AS venture_name
        FROM interview_responses ir
        JOIN interviews i ON i.id = ir.interview_id
        LEFT JOIN ventures v ON v.id = i.venture_id
        WHERE ir.id=? LIMIT 1
    ");
    $stmt->bind_param('i', $view_response_id);
    $stmt->execute();
    $view_response = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($view_response) {
        $astmt = $conn->prepare("SELECT * FROM interview_answers WHERE response_id=? ORDER BY sort_order ASC");
        $astmt->bind_param('i', $view_response_id);
        $astmt->execute();
        $ares = $astmt->get_result();
        while ($a = $ares->fetch_assoc()) $response_answers[] = $a;
        $astmt->close();
        $active_tab = 'responses';
    }
}

$all_responses = [];
if ($CAN_MANAGE_INTERVIEWS) {
    $responses_res = $conn->query("
        SELECT ir.*, i.title AS interview_title, v.name AS venture_name
        FROM interview_responses ir
        JOIN interviews i ON i.id = ir.interview_id
        LEFT JOIN ventures v ON v.id = i.venture_id
        ORDER BY ir.submitted_at DESC
        LIMIT 100
    ");
    if ($responses_res) while ($r = $responses_res->fetch_assoc()) $all_responses[] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
<meta charset="UTF-8">
<title>Field Interviews - <?= iv_h($site_name) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Lora:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/admin.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/admin.css') ?>">
<link rel="stylesheet" href="assets/css/interviews.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/interviews.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

</head>
<body class="admin-system-page">
<?php include 'includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">

<!-- -- Topbar ---------------------------------- -->
<div class="iv-top">
  <a href="index.php" class="iv-top-brand"><span class="pulse"></span> <?= iv_h($site_name) ?></a>
  <div class="iv-crumb">
    <a href="index.php"><i class="fa fa-home"></i></a>
    <span>/</span> <a href="milestones.php">Milestones</a>
    <span>/</span> <strong>Field Interviews</strong>
  </div>
  <div class="legacy-style-a76d597a07">
    <?php if ($CAN_MANAGE_INTERVIEWS): ?>
      <a href="?tab=create" class="btn btn-ghost-w btn-sm"><i class="fa fa-plus"></i> New interview</a>
    <?php endif; ?>
  </div>
</div>

<!-- -- Hero ----------------------------------- -->
<div class="iv-hero">
  <div class="iv-hero-row">
    <div>
      <h1>Field Interview Manager</h1>
      <p><?= $CAN_MANAGE_INTERVIEWS
            ? 'Generate secure shareable links for staff and consultants to interview ventures in the field.'
            : 'Interview sessions assigned to you. Open a link below to complete it in the field.' ?></p>
    </div>
    <?php if ($CAN_MANAGE_INTERVIEWS): ?>
    <div class="legacy-style-e8cd79aabb">
      <a href="?tab=create" class="btn btn-primary btn-sm"><i class="fa fa-plus"></i> New session</a>
      <a href="?tab=responses" class="btn btn-ghost-w btn-sm"><i class="fa fa-inbox"></i> Responses</a>
    </div>
    <?php endif; ?>
  </div>
  <div class="iv-tabs">
    <a href="?tab=list"      class="iv-tab <?= $active_tab==='list'     ?'active':'' ?>"><i class="fa fa-list"></i> <?= $CAN_MANAGE_INTERVIEWS ? 'Interview sessions' : 'Assigned to you' ?></a>
    <?php if ($CAN_MANAGE_INTERVIEWS): ?>
    <a href="?tab=create"    class="iv-tab <?= $active_tab==='create'   ?'active':'' ?>"><i class="fa fa-edit"></i> <?= $edit_iv ? 'Edit session' : 'Create session' ?></a>
    <a href="?tab=responses" class="iv-tab <?= $active_tab==='responses'?'active':'' ?>"><i class="fa fa-inbox"></i> Responses <span class="legacy-style-55430eb86f"><?= count($all_responses) ?></span></a>
    <?php endif; ?>
  </div>
</div>

<div class="iv-body">
<?php show_flash('interviews'); ?>

<!-- Stat strip -->
<div class="iv-stats">
  <div class="iv-stat c1">
    <div class="iv-stat-lbl"><?= $CAN_MANAGE_INTERVIEWS ? 'Total sessions' : 'Assigned to you' ?></div>
    <div class="iv-stat-val"><?= $total ?></div>
    <div class="iv-stat-sub">created</div>
  </div>
  <div class="iv-stat c2">
    <div class="iv-stat-lbl">Completed</div>
    <div class="iv-stat-val"><?= $completed ?></div>
    <div class="iv-stat-sub">responses collected</div>
  </div>
  <div class="iv-stat c3">
    <div class="iv-stat-lbl">Pending</div>
    <div class="iv-stat-val"><?= $pending ?></div>
    <div class="iv-stat-sub">awaiting response</div>
  </div>
  <div class="iv-stat c4">
    <div class="iv-stat-lbl">In progress</div>
    <div class="iv-stat-val"><?= $in_progress ?></div>
    <div class="iv-stat-sub">being filled</div>
  </div>
</div>

<!-- ------------------------------------------
     TAB: SESSION LIST
------------------------------------------ -->
<?php if ($active_tab === 'list'): ?>

<?php if (empty($interviews)): ?>
  <div class="empty">
    <i class="fa fa-clipboard-list"></i>
    <?php if ($CAN_MANAGE_INTERVIEWS): ?>
      <h3>No interview sessions yet</h3>
      <p>Create your first session to generate a shareable link.</p>
      <a href="?tab=create" class="btn btn-primary legacy-style-d6f2af6e0a">Create first session</a>
    <?php else: ?>
      <h3>No interviews assigned to you</h3>
      <p>When someone assigns you a field interview, it'll show up here.</p>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="iv-list">
    <?php foreach ($interviews as $iv):
      $ext_link = $site_url . '/interview.php?t=' . urlencode($iv['token']);
      $status_badge = match($iv['status']) {
        'completed'   => ['b-green',  '<i class="fa fa-check-circle"></i> Completed'],
        'in_progress' => ['b-amber',  '<i class="fa fa-spinner"></i> In progress'],
        'expired'     => ['b-red',    '<i class="fa fa-ban"></i> Expired'],
        default       => ['b-gray',   '<i class="fa fa-clock"></i> Pending'],
      };
      $resp_count_stmt = $conn->prepare("SELECT COUNT(*) c FROM interview_responses WHERE interview_id=?");
      $resp_count_stmt->bind_param('i', $iv['id']);
      $resp_count_stmt->execute();
      $resp_count = (int)$resp_count_stmt->get_result()->fetch_assoc()['c'];
      $resp_count_stmt->close();
    ?>
      <div class="iv-list-item">
        <div>
          <div class="iv-li-title"><?= iv_h($iv['title']) ?></div>
          <div class="iv-li-meta">
            <span class="badge <?= $status_badge[0] ?>"><?= $status_badge[1] ?></span>
            <?php if ($iv['venture_name']): ?>
              <span><i class="fa fa-building legacy-style-9b919b9812"></i><?= iv_h($iv['venture_name']) ?></span>
            <?php endif; ?>
            <?php if ($iv['assigned_to']): ?>
              <span><i class="fa fa-user-circle legacy-style-9b919b9812"></i><?= iv_h($iv['assigned_to']) ?></span>
            <?php endif; ?>
            <span><i class="fa fa-calendar legacy-style-9b919b9812"></i>Created <?= date('M j, Y', strtotime($iv['created_at'])) ?></span>
            <?php if ($iv['expires_at']): ?>
              <span style="color:<?= strtotime($iv['expires_at']) < time() ? 'var(--red)' : 'var(--ink-soft)' ?>">
                <i class="fa fa-hourglass-half legacy-style-9aff94120f"></i>
                Expires <?= date('M j, Y', strtotime($iv['expires_at'])) ?>
              </span>
            <?php endif; ?>
            <span><i class="fa fa-inbox legacy-style-9b919b9812"></i><?= $resp_count ?> response<?= $resp_count !== 1 ? 's' : '' ?></span>
          </div>
          <div class="link-box legacy-style-8609575793">
            <i class="fa fa-link legacy-style-e293f77ae7"></i>
            <input type="text" value="<?= iv_h($ext_link) ?>" readonly onclick="this.select()">
            <button class="btn btn-teal btn-sm" onclick="copyLink('<?= iv_h($ext_link) ?>', this)">
              <i class="fa fa-copy"></i> Copy
            </button>
            <a href="<?= iv_h($ext_link) ?>" target="_blank" class="btn btn-secondary btn-sm">
              <i class="fa fa-external-link-alt"></i> Open
            </a>
          </div>
        </div>
        <?php if ($CAN_MANAGE_INTERVIEWS): ?>
        <div class="iv-li-actions">
          <a href="?tab=create&edit=<?= (int)$iv['id'] ?>" class="btn btn-secondary btn-sm btn-icon" title="Edit"><i class="fa fa-pen"></i></a>
          <?php if ($resp_count > 0): ?>
            <a href="?tab=responses&filter=<?= (int)$iv['id'] ?>" class="btn btn-teal btn-sm" title="View responses">
              <i class="fa fa-inbox"></i> <?= $resp_count ?>
            </a>
          <?php endif; ?>
          <button class="btn btn-sm btn-secondary btn-icon" onclick="openShareModal('<?= iv_h($ext_link) ?>', '<?= iv_h(addslashes($iv['title'])) ?>')" title="Share">
            <i class="fa fa-share-alt"></i>
          </button>
          <form method="POST" action="includes/process-interviews.php"
                onsubmit="return confirm('Delete this interview session and all its responses?')" class="legacy-style-cccfa4560d">
            <input type="hidden" name="action" value="delete_interview">
            <input type="hidden" name="id" value="<?= (int)$iv['id'] ?>">
            <button class="btn btn-sm btn-danger btn-icon" title="Delete"><i class="fa fa-trash"></i></button>
          </form>
        </div>
        <?php else: ?>
        <div class="iv-li-actions">
          <?php if ($resp_count > 0): ?>
            <span class="badge b-green" title="You've already submitted a response for this"><i class="fa fa-check-circle"></i> Submitted</span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- ------------------------------------------
     TAB: CREATE / EDIT SESSION
------------------------------------------ -->
<?php elseif ($active_tab === 'create'): ?>

<form method="POST" action="includes/process-interviews.php" id="ivForm" onsubmit="return prepareForm()">
  <input type="hidden" name="action" value="save_interview">
  <input type="hidden" name="id" value="<?= $edit_iv ? (int)$edit_iv['id'] : '' ?>">
  <input type="hidden" name="questions_json" id="questionsJson">
  <input type="hidden" name="sections_json" id="sectionsJson">

  <div class="legacy-style-d32d4d6626">

    <div>
      <!-- Session info -->
      <div class="iv-card">
        <div class="iv-card-head">
          <div class="iv-card-title"><i class="fa fa-clipboard"></i> Session Information</div>
        </div>
        <div class="legacy-style-8e11ed66d1">
          <div class="form-grid legacy-style-da12f2858b">
            <div class="form-group full">
              <label class="form-label">Interview title <span class="req">*</span></label>
              <input type="text" name="title" class="form-control" required
                     value="<?= iv_h($edit_iv['title'] ?? '') ?>"
                     placeholder="e.g. Q4 2024 Venture Gap Assessment">
            </div>
            <div class="form-group full">
              <label class="form-label">Description / instructions for interviewer</label>
              <textarea name="description" class="form-control" rows="2"
                        placeholder="Brief instructions for the person conducting the interview..."><?= iv_h($edit_iv['description'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
              <label class="form-label">Assigned venture</label>
              <select name="venture_id" class="form-control">
                <option value="">- Any venture (unassigned) -</option>
                <?php foreach ($ventures as $v): ?>
                  <option value="<?= (int)$v['id'] ?>"
                    <?= ($edit_iv['venture_id'] ?? 0) == $v['id'] ? 'selected' : '' ?>>
                    <?= iv_h($v['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Assigned to (staff / consultant)</label>
              <input type="text" name="assigned_to" class="form-control"
                     value="<?= iv_h($edit_iv['assigned_to'] ?? '') ?>"
                     placeholder="Full name of interviewer">
            </div>
            <div class="form-group">
              <label class="form-label">Interview type</label>
              <select name="interview_type" class="form-control">
                <?php foreach (['gap_analysis'=>'Gap Analysis','venture_assessment'=>'Venture Assessment','progress_check'=>'Progress Check-in','onboarding'=>'Onboarding','exit_interview'=>'Exit Interview','custom'=>'Custom'] as $k=>$l): ?>
                  <option value="<?= $k ?>" <?= ($edit_iv['interview_type'] ?? 'gap_analysis') === $k ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Expires at (optional)</label>
              <input type="datetime-local" name="expires_at" class="form-control"
                     value="<?= $edit_iv && $edit_iv['expires_at'] ? date('Y-m-d\TH:i', strtotime($edit_iv['expires_at'])) : '' ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Allow multiple submissions?</label>
              <select name="allow_multiple" class="form-control">
                <option value="0" <?= ($edit_iv['allow_multiple'] ?? 0) == 0 ? 'selected' : '' ?>>No - one response only</option>
                <option value="1" <?= ($edit_iv['allow_multiple'] ?? 0) == 1 ? 'selected' : '' ?>>Yes - multiple allowed</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Require PIN to access?</label>
              <input type="text" name="access_pin" class="form-control"
                     value="<?= iv_h($edit_iv['access_pin'] ?? '') ?>"
                     placeholder="Leave blank for open access" maxlength="10">
            </div>
          </div>
        </div>
      </div>

      <!-- Sections to include -->
      <div class="iv-card">
        <div class="iv-card-head">
          <div class="iv-card-title"><i class="fa fa-layer-group"></i> Interview Sections</div>
          <div class="legacy-style-49cd09213e">
            <button type="button" class="btn btn-sm btn-secondary" onclick="selectAllSections()">Select all</button>
            <button type="button" class="btn btn-sm btn-secondary" onclick="clearAllSections()">Clear</button>
          </div>
        </div>
        <div class="legacy-style-8ebc43724d">
          <p class="legacy-style-7d27271bd0">
            Select the topics to cover in this interview. Each section has pre-built questions you can customise below.
          </p>
          <div class="section-grid" id="sectionGrid">
            <?php
            $selected_sections = $edit_iv ? json_decode($edit_iv['sections_json'] ?? '[]', true) : array_keys($sections);
            if (!is_array($selected_sections)) $selected_sections = array_keys($sections);
            foreach ($sections as $skey => $slabel):
              $checked = in_array($skey, $selected_sections);
            ?>
              <label class="section-chip <?= $checked ? 'checked' : '' ?>" id="chip_<?= $skey ?>">
                <input type="checkbox" value="<?= $skey ?>" class="section-chk" <?= $checked ? 'checked' : '' ?>
                       onchange="toggleChip(this, '<?= $skey ?>')">
                <?= iv_h($slabel) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Custom questions -->
      <div class="iv-card">
        <div class="iv-card-head">
          <div class="iv-card-title"><i class="fa fa-question-circle"></i> Additional / Custom Questions</div>
          <button type="button" class="btn btn-sm btn-primary" onclick="addQuestion()">
            <i class="fa fa-plus"></i> Add question
          </button>
        </div>
        <div class="legacy-style-8ebc43724d">
          <p class="legacy-style-7d27271bd0">
            Add custom questions on top of the section defaults. These appear at the end of the form.
          </p>
          <div id="questionsWrap">
            <?php
            $existing_qs = $edit_iv ? json_decode($edit_iv['questions_json'] ?? '[]', true) : [];
            if (!is_array($existing_qs)) $existing_qs = [];
            foreach ($existing_qs as $qi => $q):
            ?>
              <div class="q-block" id="qBlock_<?= $qi ?>">
                <div class="q-block-head">
                  <span class="q-num">Custom question <?= $qi + 1 ?></span>
                  <button type="button" class="btn btn-sm btn-danger btn-icon" onclick="removeQuestion(<?= $qi ?>)"><i class="fa fa-times"></i></button>
                </div>
                <div class="form-grid">
                  <div class="form-group full">
                    <label class="form-label">Question text <span class="req">*</span></label>
                    <input type="text" class="form-control q-text" data-qi="<?= $qi ?>"
                           value="<?= iv_h($q['question'] ?? '') ?>" placeholder="Enter your question...">
                  </div>
                  <div class="form-group">
                    <label class="form-label">Field type</label>
                    <select class="form-control q-type" data-qi="<?= $qi ?>">
                      <?php foreach (['text'=>'Short text','textarea'=>'Long text','number'=>'Number','select'=>'Dropdown','radio'=>'Single choice','checkbox'=>'Multiple choice','date'=>'Date','file'=>'File upload'] as $tv=>$tl): ?>
                        <option value="<?= $tv ?>" <?= ($q['type'] ?? 'text') === $tv ? 'selected' : '' ?>><?= $tl ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="form-group">
                    <label class="form-label">Required?</label>
                    <select class="form-control q-req" data-qi="<?= $qi ?>">
                      <option value="0" <?= ($q['required'] ?? 0) == 0 ? 'selected' : '' ?>>Optional</option>
                      <option value="1" <?= ($q['required'] ?? 0) == 1 ? 'selected' : '' ?>>Required</option>
                    </select>
                  </div>
                  <div class="form-group full q-options-group" style="<?= in_array($q['type']??'', ['select','radio','checkbox']) ? '' : 'display:none' ?>">
                    <label class="form-label">Options (one per line)</label>
                    <textarea class="form-control q-options" data-qi="<?= $qi ?>" rows="3"><?= iv_h(implode("\n", $q['options'] ?? [])) ?></textarea>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <button type="button" class="add-q-btn" onclick="addQuestion()">
            <i class="fa fa-plus"></i> Add another question
          </button>
        </div>
      </div>
    </div>

    <!-- Side panel -->
    <div class="legacy-style-8be510b808">
      <div class="iv-card legacy-style-da12f2858b">
        <div class="iv-card-head">
          <div class="iv-card-title"><i class="fa fa-info-circle"></i> How it works</div>
        </div>
        <div class="legacy-style-5054abd8c6">
          <div class="legacy-style-e4840ffada">
            <div class="legacy-style-6b2fb8a4db">1</div>
            <span>Create a session and assign it to a venture and interviewer.</span>
          </div>
          <div class="legacy-style-e4840ffada">
            <div class="legacy-style-6b2fb8a4db">2</div>
            <span>A unique secure link is generated. Share it with the field consultant.</span>
          </div>
          <div class="legacy-style-e4840ffada">
            <div class="legacy-style-6b2fb8a4db">3</div>
            <span>The consultant opens the link on any device and fills in the interview on-site.</span>
          </div>
          <div class="legacy-style-0a9765dab3">
            <div class="legacy-style-987b40429c">4</div>
            <span>Responses appear here instantly and feed into the gap analysis dashboard.</span>
          </div>
        </div>
      </div>

      <?php if ($edit_iv): ?>
        <div class="iv-card legacy-style-da12f2858b">
          <div class="iv-card-head">
            <div class="iv-card-title"><i class="fa fa-link"></i> Shareable link</div>
          </div>
          <div class="legacy-style-8ebc43724d">
            <?php $ext = $site_url.'/interview.php?t='.urlencode($edit_iv['token']); ?>
            <div class="link-box legacy-style-fdf33f2304">
              <i class="fa fa-link legacy-style-e293f77ae7"></i>
              <input type="text" value="<?= iv_h($ext) ?>" readonly onclick="this.select()">
            </div>
            <div class="legacy-style-6157e89086">
              <button type="button" class="btn btn-teal btn-sm" onclick="copyLink('<?= iv_h($ext) ?>', this)" style="flex:1">
                <i class="fa fa-copy"></i> Copy link
              </button>
              <a href="<?= iv_h($ext) ?>" target="_blank" class="btn btn-secondary btn-sm" style="flex:1">
                <i class="fa fa-external-link-alt"></i> Open
              </a>
            </div>
            <button type="button" class="btn btn-sm btn-secondary legacy-style-a42d247acc"
                    onclick="openShareModal('<?= iv_h($ext) ?>', '<?= iv_h(addslashes($edit_iv['title'])) ?>')">
              <i class="fa fa-share-alt"></i> Share options
            </button>
          </div>
        </div>
      <?php endif; ?>

      <div class="legacy-style-3de8f987ba">
        <a href="?tab=list" class="btn btn-secondary legacy-style-97445a8d93"><i class="fa fa-arrow-left"></i> Cancel</a>
        <button type="submit" class="btn btn-primary legacy-style-4b3de78f23">
          <i class="fa fa-save"></i> <?= $edit_iv ? 'Update session' : 'Create &amp; get link' ?>
        </button>
      </div>
    </div>

  </div>
</form>

<!-- ------------------------------------------
     TAB: RESPONSES
------------------------------------------ -->
<?php elseif ($active_tab === 'responses'): ?>

<?php if ($view_response): ?>
  <!-- Single response detail -->
  <div class="legacy-style-f45af6574a">
    <a href="?tab=responses" class="btn btn-secondary btn-sm"><i class="fa fa-arrow-left"></i> All responses</a>
    <h2 class="legacy-style-8fbe3e165c">
      <?= iv_h($view_response['interview_title']) ?>
      <?php if ($view_response['venture_name']): ?>
        - <span class="legacy-style-9fe234fe02"><?= iv_h($view_response['venture_name']) ?></span>
      <?php endif; ?>
    </h2>
    <form method="POST" action="includes/process-interviews.php"
          onsubmit="return confirm('Delete this response?')" class="legacy-style-8c396d5716">
      <input type="hidden" name="action" value="delete_response">
      <input type="hidden" name="id" value="<?= (int)$view_response['id'] ?>">
      <button class="btn btn-danger btn-sm"><i class="fa fa-trash"></i> Delete</button>
    </form>
  </div>

  <div class="legacy-style-65b1b1a016">
    <div>
      <?php
      // Group answers by section
      $by_section = [];
      foreach ($response_answers as $a) {
          $sec = $a['section_key'] ?: 'custom';
          $by_section[$sec][] = $a;
      }
      foreach ($by_section as $sec => $answers):
        $sec_label = $sections[$sec] ?? 'Custom Questions';
      ?>
        <div class="resp-section">
          <div class="resp-section-title">
            <i class="fa fa-folder"></i> <?= iv_h($sec_label) ?>
          </div>
          <?php foreach ($answers as $a): ?>
            <div class="resp-qa">
              <div class="resp-q"><?= iv_h($a['question_text']) ?></div>
              <div class="resp-a <?= trim($a['answer_value'] ?? '') === '' ? 'empty' : '' ?>">
                <?= trim($a['answer_value'] ?? '') !== '' ? nl2br(iv_h($a['answer_value'])) : 'No answer provided' ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      <?php if (empty($response_answers)): ?>
        <div class="empty"><i class="fa fa-inbox"></i><h3>No answers recorded</h3></div>
      <?php endif; ?>
    </div>

    <div class="legacy-style-8be510b808">
      <div class="iv-card">
        <div class="iv-card-head"><div class="iv-card-title"><i class="fa fa-info-circle"></i> Response info</div></div>
        <div class="legacy-style-d7f106e132">
          <?php foreach ([
            ['Submitted by',  $view_response['interviewer_name'] ?: '-'],
            ['Submitted at',  $view_response['submitted_at'] ? date('M j, Y g:i A', strtotime($view_response['submitted_at'])) : '-'],
            ['Venture',       $view_response['venture_name'] ?: '-'],
            ['Venture contact', $view_response['venture_contact'] ?: '-'],
            ['Interview type',  $view_response['interview_type'] ?: '-'],
            ['Location',       $view_response['interview_location'] ?: '-'],
            ['Duration',       $view_response['duration_minutes'] ? $view_response['duration_minutes'].' min' : '-'],
          ] as [$l, $v]): ?>
            <div class="legacy-style-b576cfd0ff">
              <span class="legacy-style-f55e15c253"><?= iv_h($l) ?></span>
              <strong class="legacy-style-78f309759c"><?= iv_h($v) ?></strong>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

<?php else: ?>
  <!-- Response list -->
  <div class="legacy-style-f45af6574a">
    <div class="legacy-style-4930947ebb">
      <i class="fa fa-search legacy-style-28dc1feb56"></i>
      <input type="text" id="respSearch" class="form-control legacy-style-e8eafd7108"
             placeholder="Search responses..." oninput="filterResponses()">
    </div>
    <select id="respFilter" class="form-control legacy-style-30e741d98e" onchange="filterResponses()">
      <option value="">All sessions</option>
      <?php foreach ($interviews as $iv): ?>
        <option value="<?= (int)$iv['id'] ?>" <?= ($_GET['filter'] ?? '') == $iv['id'] ? 'selected' : '' ?>>
          <?= iv_h($iv['title']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <span id="respCount" class="legacy-style-c72ada055c"><?= count($all_responses) ?> responses</span>
  </div>

  <?php if (empty($all_responses)): ?>
    <div class="empty">
      <i class="fa fa-inbox"></i>
      <h3>No responses yet</h3>
      <p>Share interview links with your field consultants to start collecting data.</p>
    </div>
  <?php else: ?>
    <div class="iv-card">
      <div class="iv-table-wrap">
      <table class="iv-table" id="respTable">
        <thead><tr>
          <th>#</th><th>Session</th><th>Venture</th><th>Interviewer</th>
          <th>Venue contact</th><th>Submitted</th><th>Duration</th><th>Actions</th>
        </tr></thead>
        <tbody id="respBody">
          <?php foreach ($all_responses as $i => $r): ?>
            <tr data-session="<?= (int)$r['interview_id'] ?>"
                data-text="<?= strtolower(iv_h($r['interview_title'].'|'.$r['venture_name'].'|'.$r['interviewer_name'].'|'.$r['venture_contact'])) ?>">
              <td class="legacy-style-f55e15c253"><?= $i + 1 ?></td>
              <td class="legacy-style-eed0f8fb89"><?= iv_h($r['interview_title']) ?></td>
              <td><?= iv_h($r['venture_name'] ?: '-') ?></td>
              <td><?= iv_h($r['interviewer_name'] ?: '-') ?></td>
              <td><?= iv_h($r['venture_contact'] ?: '-') ?></td>
              <td class="legacy-style-760c39b92d">
                <?= $r['submitted_at'] ? date('M j, Y  g:ia', strtotime($r['submitted_at'])) : '-' ?>
              </td>
              <td class="legacy-style-fee23df2d7"><?= $r['duration_minutes'] ? $r['duration_minutes'].' min' : '-' ?></td>
              <td>
                <div class="legacy-style-74cac98b22">
                  <a href="?tab=responses&response=<?= (int)$r['id'] ?>" class="btn btn-sm btn-secondary btn-icon" title="View"><i class="fa fa-eye"></i></a>
                  <form method="POST" action="includes/process-interviews.php"
                        onsubmit="return confirm('Delete this response?')" class="legacy-style-cccfa4560d">
                    <input type="hidden" name="action" value="delete_response">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-danger btn-icon" title="Delete"><i class="fa fa-trash"></i></button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php endif; ?>
</div><!-- /iv-body -->
</div><!-- /admin-main -->

<!-- -- Share modal ---------------------------- -->
<div class="modal-overlay" id="shareModal">
  <div class="modal">
    <div class="modal-head">
      <h3>Share interview link</h3>
      <button class="modal-x" type="button" onclick="closeShareModal()"></button>
    </div>
    <div class="modal-body">
      <div id="shareTitle" class="legacy-style-0a9cd39516"></div>
      <div class="link-box legacy-style-2b583d7389">
        <i class="fa fa-link legacy-style-e293f77ae7"></i>
        <input type="text" id="shareLinkInput" readonly onclick="this.select()">
        <button class="btn btn-teal btn-sm" onclick="copyShareLink()"><i class="fa fa-copy"></i> Copy</button>
      </div>
      <p class="legacy-style-90ba6d16ad">Share via:</p>
      <div class="legacy-style-be26537941">
        <button class="btn btn-secondary" id="shareWhatsapp">
          <i class="fa fa-comment-dots"></i> WhatsApp
        </button>
        <button class="btn btn-secondary" id="shareEmail">
          <i class="fa fa-envelope"></i> Email
        </button>
        <button class="btn btn-secondary" id="shareSMS">
          <i class="fa fa-sms"></i> SMS
        </button>
      </div>
      <div class="legacy-style-d63e60cf5a">
        <i class="fa fa-info-circle legacy-style-8896c95d8b"></i>
        The link works on any device - mobile, tablet, or desktop. No login required for the interviewer.
      </div>
    </div>
  </div>
</div>

<script>
/* -- Question builder ------------------------ */
let qIndex = <?= count($existing_qs ?? []) ?>;

function addQuestion() {
  const wrap = document.getElementById('questionsWrap');
  const qi = qIndex++;
  const block = document.createElement('div');
  block.className = 'q-block';
  block.id = 'qBlock_' + qi;
  block.innerHTML = `
    <div class="q-block-head">
      <span class="q-num">Custom question ${qi + 1}</span>
      <button type="button" class="btn btn-sm btn-danger btn-icon" onclick="removeQuestion(${qi})"><i class="fa fa-times"></i></button>
    </div>
    <div class="form-grid">
      <div class="form-group full">
        <label class="form-label">Question text <span class="req">*</span></label>
        <input type="text" class="form-control q-text" data-qi="${qi}" placeholder="Enter your question...">
      </div>
      <div class="form-group">
        <label class="form-label">Field type</label>
        <select class="form-control q-type" data-qi="${qi}" onchange="toggleOptions(${qi},this.value)">
          <option value="text">Short text</option>
          <option value="textarea">Long text</option>
          <option value="number">Number</option>
          <option value="select">Dropdown</option>
          <option value="radio">Single choice</option>
          <option value="checkbox">Multiple choice</option>
          <option value="date">Date</option>
          <option value="file">File upload</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Required?</label>
        <select class="form-control q-req" data-qi="${qi}">
          <option value="0">Optional</option>
          <option value="1">Required</option>
        </select>
      </div>
      <div class="form-group full q-options-group legacy-style-6b99de8b69" id="qOpts_${qi}">
        <label class="form-label">Options (one per line)</label>
        <textarea class="form-control q-options" data-qi="${qi}" rows="3" placeholder="Option 1&#10;Option 2&#10;Option 3"></textarea>
      </div>
    </div>`;
  wrap.appendChild(block);
}

function removeQuestion(qi) {
  const el = document.getElementById('qBlock_' + qi);
  if (el) el.remove();
}

function toggleOptions(qi, type) {
  const el = document.getElementById('qOpts_' + qi);
  if (el) el.style.display = ['select','radio','checkbox'].includes(type) ? '' : 'none';
}

/* -- Section chips ---------------------------- */
function toggleChip(checkbox, key) {
  const chip = document.getElementById('chip_' + key);
  if (chip) chip.classList.toggle('checked', checkbox.checked);
}
function selectAllSections() {
  document.querySelectorAll('.section-chk').forEach(cb => {
    cb.checked = true;
    const chip = document.getElementById('chip_' + cb.value);
    if (chip) chip.classList.add('checked');
  });
}
function clearAllSections() {
  document.querySelectorAll('.section-chk').forEach(cb => {
    cb.checked = false;
    const chip = document.getElementById('chip_' + cb.value);
    if (chip) chip.classList.remove('checked');
  });
}

/* -- Collect form data ------------------------ */
function prepareForm() {
  // Collect questions
  const questions = [];
  document.querySelectorAll('.q-block').forEach(block => {
    const qi = block.querySelector('[data-qi]')?.dataset.qi;
    if (!qi) return;
    const text = block.querySelector('.q-text')?.value.trim();
    if (!text) return;
    const type = block.querySelector('.q-type')?.value || 'text';
    const req  = block.querySelector('.q-req')?.value || '0';
    const rawOpts = block.querySelector('.q-options')?.value || '';
    const opts = rawOpts.split('\n').map(o=>o.trim()).filter(Boolean);
    questions.push({ question: text, type, required: parseInt(req), options: opts });
  });
  document.getElementById('questionsJson').value = JSON.stringify(questions);

  // Collect sections
  const sections = [];
  document.querySelectorAll('.section-chk:checked').forEach(cb => sections.push(cb.value));
  document.getElementById('sectionsJson').value = JSON.stringify(sections);

  return true;
}

/* -- Copy link -------------------------------- */
function copyLink(url, btn) {
  navigator.clipboard.writeText(url).then(() => {
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fa fa-check"></i> Copied!';
    btn.className = btn.className.replace('btn-teal','btn-success');
    setTimeout(() => {
      btn.innerHTML = orig;
      btn.className = btn.className.replace('btn-success','btn-teal');
    }, 2000);
  });
}

/* -- Share modal ------------------------------ */
let _shareUrl = '';
function openShareModal(url, title) {
  _shareUrl = url;
  document.getElementById('shareLinkInput').value = url;
  document.getElementById('shareTitle').textContent = title;
  document.getElementById('shareWhatsapp').onclick = () => window.open('https://wa.me/?text=' + encodeURIComponent(title + '\n' + url));
  document.getElementById('shareEmail').onclick    = () => window.open('mailto:?subject=Interview session: ' + encodeURIComponent(title) + '&body=' + encodeURIComponent('Please use this link to complete the interview:\n\n' + url));
  document.getElementById('shareSMS').onclick      = () => window.open('sms:?body=' + encodeURIComponent('Interview link: ' + url));
  document.getElementById('shareModal').classList.add('open');
}
function closeShareModal() { document.getElementById('shareModal').classList.remove('open'); }
function copyShareLink() {
  const inp = document.getElementById('shareLinkInput');
  inp.select();
  navigator.clipboard.writeText(inp.value);
}

/* -- Response filter -------------------------- */
function filterResponses() {
  const search  = (document.getElementById('respSearch')?.value || '').toLowerCase();
  const session = document.getElementById('respFilter')?.value || '';
  const rows    = document.querySelectorAll('#respBody tr');
  let visible   = 0;
  rows.forEach(row => {
    const match = (!search || row.dataset.text?.includes(search))
               && (!session || row.dataset.session === session);
    row.style.display = match ? '' : 'none';
    if (match) visible++;
  });
  const lbl = document.getElementById('respCount');
  if (lbl) lbl.textContent = visible + ' response' + (visible !== 1 ? 's' : '');
}

/* Close modal on backdrop */
document.getElementById('shareModal').addEventListener('click', function(e) {
  if (e.target === this) closeShareModal();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeShareModal(); });

/* Auto-run filter if ?filter= present */
<?php if ($_GET['filter'] ?? ''): ?>
document.addEventListener('DOMContentLoaded', filterResponses);
<?php endif; ?>
</script>

</body>
</html>
