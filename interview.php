<?php

require_once __DIR__ . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('System error. Please contact support.');
}
$conn->set_charset('utf8mb4');

function iv_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$site_name = function_exists('get_setting') ? get_setting($conn, 'site_name', 'EdTech Fellowship') : 'EdTech Fellowship';
$site_url  = rtrim(SITE_URL, '/');

$token = trim((string)($_GET['t'] ?? ''));
if ($token === '') { http_response_code(404); die('Interview not found.'); }

$stmt = $conn->prepare("SELECT * FROM interviews WHERE token=? LIMIT 1");
$stmt->bind_param('s', $token);
$stmt->execute();
$interview = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$interview) { http_response_code(404); die('Interview session not found.'); }
if ($interview['status'] === 'expired') {
    render_closed_page($site_name, 'Session Expired', 'This interview session has expired and is no longer accepting responses.', 'fa-hourglass-end', '#b45309');
}
if (!empty($interview['expires_at']) && strtotime($interview['expires_at']) < time()) {
    // Auto-mark expired
    $conn->query("UPDATE interviews SET status='expired' WHERE id=".(int)$interview['id']);
    render_closed_page($site_name, 'Session Expired', 'This interview session has closed. The deadline has passed.', 'fa-hourglass-end', '#b45309');
}

// PIN check
$pin_verified = isset($_SESSION['iv_pin_'.$interview['id']]) && $_SESSION['iv_pin_'.$interview['id']] === true;
$show_pin_form = !empty($interview['access_pin']) && !$pin_verified && ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') === 'verify_pin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_pin') {
    if (trim($_POST['pin'] ?? '') === $interview['access_pin']) {
        $_SESSION['iv_pin_'.$interview['id']] = true;
        $show_pin_form = false;
        $pin_verified  = true;
    } else {
        $pin_error = 'Incorrect PIN. Please try again.';
        $show_pin_form = true;
    }
}

// Get venture info
$venture = null;
if ($interview['venture_id']) {
    $vstmt = $conn->prepare("SELECT * FROM ventures WHERE id=? LIMIT 1");
    $vstmt->bind_param('i', $interview['venture_id']);
    $vstmt->execute();
    $venture = $vstmt->get_result()->fetch_assoc();
    $vstmt->close();
}

// Section definitions + their default questions
$SECTION_QUESTIONS = [
    'venture_profile' => [
        ['q'=>'Venture name',                                      'type'=>'text',     'req'=>true],
        ['q'=>'Date of interview',                                  'type'=>'date',     'req'=>true],
        ['q'=>'Name of person interviewed (venture representative)','type'=>'text',     'req'=>true],
        ['q'=>'Role / position of interviewee',                    'type'=>'text',     'req'=>false],
        ['q'=>'Year the venture was founded',                       'type'=>'number',   'req'=>false],
        ['q'=>'Current stage of the venture',                       'type'=>'select',   'req'=>false,
         'options'=>['Ideation','MVP / Prototype','Early growth','Growth','Scale']],
    ],
    'product_service' => [
        ['q'=>'Describe your core product or service',             'type'=>'textarea', 'req'=>true],
        ['q'=>'Who is your primary target user / beneficiary?',    'type'=>'textarea', 'req'=>true],
        ['q'=>'What problem does your product solve?',             'type'=>'textarea', 'req'=>true],
        ['q'=>'How is your product differentiated from alternatives?','type'=>'textarea','req'=>false],
        ['q'=>'What stage is your product development at?',        'type'=>'select',   'req'=>false,
         'options'=>['Idea','Prototype','Pilot','Live / scaled']],
    ],
    'market_customers' => [
        ['q'=>'Who are your key customer segments?',               'type'=>'textarea', 'req'=>true],
        ['q'=>'How many active users / customers do you have?',    'type'=>'text',     'req'=>false],
        ['q'=>'What is your current geographic reach?',            'type'=>'text',     'req'=>false],
        ['q'=>'How do you currently acquire customers?',           'type'=>'textarea', 'req'=>false],
        ['q'=>'What is your customer retention rate?',             'type'=>'text',     'req'=>false],
    ],
    'business_model' => [
        ['q'=>'Describe your revenue model',                       'type'=>'textarea', 'req'=>true],
        ['q'=>'What are your primary revenue streams?',            'type'=>'textarea', 'req'=>false],
        ['q'=>'What is your average revenue per user / customer?', 'type'=>'text',     'req'=>false],
        ['q'=>'Are your unit economics positive? Explain.',        'type'=>'textarea', 'req'=>false],
        ['q'=>'What are your key cost drivers?',                   'type'=>'textarea', 'req'=>false],
    ],
    'team' => [
        ['q'=>'How many full-time team members do you have?',      'type'=>'number',   'req'=>false],
        ['q'=>'How many part-time / contractors?',                 'type'=>'number',   'req'=>false],
        ['q'=>'Describe the founding team\'s backgrounds',         'type'=>'textarea', 'req'=>false],
        ['q'=>'What key roles are currently missing?',             'type'=>'textarea', 'req'=>false],
        ['q'=>'Do you have a formal HR policy or handbook?',       'type'=>'radio',    'req'=>false,
         'options'=>['Yes','No','In development']],
    ],
    'finance' => [
        ['q'=>'What is your total funding raised to date?',        'type'=>'text',     'req'=>false],
        ['q'=>'What are your current funding sources?',            'type'=>'textarea', 'req'=>false],
        ['q'=>'What is your monthly burn rate?',                   'type'=>'text',     'req'=>false],
        ['q'=>'How many months of runway do you have?',            'type'=>'number',   'req'=>false],
        ['q'=>'Are your financial records / books up to date?',    'type'=>'radio',    'req'=>false,
         'options'=>['Yes, fully','Partially','No']],
        ['q'=>'What funding are you currently seeking?',           'type'=>'textarea', 'req'=>false],
    ],
    'technology' => [
        ['q'=>'What technology stack does your product use?',      'type'=>'textarea', 'req'=>false],
        ['q'=>'Is your technology built in-house or outsourced?',  'type'=>'radio',    'req'=>false,
         'options'=>['Fully in-house','Outsourced','Hybrid']],
        ['q'=>'What are your main technical limitations?',         'type'=>'textarea', 'req'=>false],
        ['q'=>'Does your platform work on low-bandwidth / 2G?',    'type'=>'radio',    'req'=>false,
         'options'=>['Yes','No','Partially']],
        ['q'=>'How do you handle data security and privacy?',      'type'=>'textarea', 'req'=>false],
    ],
    'pedagogy' => [
        ['q'=>'Is your curriculum aligned to national standards?', 'type'=>'radio',    'req'=>false,
         'options'=>['Yes','No','Partially']],
        ['q'=>'Describe the learning methodology used',            'type'=>'textarea', 'req'=>false],
        ['q'=>'How do you measure learning outcomes?',             'type'=>'textarea', 'req'=>false],
        ['q'=>'Do you have qualified curriculum or education staff?','type'=>'radio',  'req'=>false,
         'options'=>['Yes','No','Outsourced']],
    ],
    'partnerships' => [
        ['q'=>'List your key partners or institutional relationships','type'=>'textarea','req'=>false],
        ['q'=>'Do you have government or regulatory relationships?','type'=>'radio',   'req'=>false,
         'options'=>['Yes','No','In development']],
        ['q'=>'What partnerships are you actively pursuing?',      'type'=>'textarea', 'req'=>false],
    ],
    'monitoring_eval' => [
        ['q'=>'Do you have a formal M&E framework?',               'type'=>'radio',    'req'=>false,
         'options'=>['Yes','No','In development']],
        ['q'=>'How do you track and report impact?',               'type'=>'textarea', 'req'=>false],
        ['q'=>'What are your key performance indicators (KPIs)?',  'type'=>'textarea', 'req'=>false],
        ['q'=>'How frequently do you report to donors / funders?', 'type'=>'select',   'req'=>false,
         'options'=>['Monthly','Quarterly','Bi-annually','Annually','Ad hoc']],
    ],
    'challenges' => [
        ['q'=>'What are the top 3 challenges the venture is facing?','type'=>'textarea','req'=>true],
        ['q'=>'Which challenge is most urgent to resolve?',        'type'=>'textarea', 'req'=>true],
        ['q'=>'What is currently blocking growth?',                'type'=>'textarea', 'req'=>false],
        ['q'=>'Are there safeguarding or duty-of-care risks?',     'type'=>'radio',    'req'=>false,
         'options'=>['Yes - describe','No','Unsure']],
        ['q'=>'Describe any safeguarding concerns if applicable',  'type'=>'textarea', 'req'=>false],
    ],
    'support_needs' => [
        ['q'=>'What type of support is most needed right now?',    'type'=>'checkbox', 'req'=>true,
         'options'=>['Technical assistance','Financial/Funding','Mentorship','Market access','Legal/Compliance','Training','Partnerships','Other']],
        ['q'=>'What would have the biggest impact in the next 90 days?','type'=>'textarea','req'=>true],
        ['q'=>'What does the venture need from the program?',      'type'=>'textarea', 'req'=>false],
        ['q'=>'Any other comments or observations?',               'type'=>'textarea', 'req'=>false],
    ],
];

$selected_sections = json_decode($interview['sections_json'] ?? '[]', true) ?: array_keys($SECTION_QUESTIONS);
$custom_questions  = json_decode($interview['questions_json'] ?? '[]', true) ?: [];

$SECTION_LABELS = [
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

function render_closed_page($site_name, $title, $body, $icon, $accent) {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>'.iv_h($title).' - '.iv_h($site_name).'</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v='.(int)@filemtime(__DIR__.'/assets/css/style.css').'">
    <link rel="stylesheet" href="assets/css/interview.css?v='.(int)@filemtime(__DIR__.'/assets/css/interview.css').'">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    </head><body class="interview-page"><main class="iv-closed-page"><section class="iv-closed-card"><div class="iv-closed-icon" style="--closed-accent:'.iv_h($accent).'"><i class="fa '.iv_h($icon).'"></i></div><p class="iv-eyebrow">'.iv_h($site_name).' · Field Interview</p><h1>'.iv_h($title).'</h1><p>'.iv_h($body).'</p></section></main></body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/includes/favicon.php'; ?>
<meta charset="UTF-8">
<title><?= iv_h($interview['title']) ?> - <?= iv_h($site_name) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#fc7f10">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/style.css') ?>">
<link rel="stylesheet" href="assets/css/interview.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/interview.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">


</head>
<body class="interview-page">

<div class="prog-track"><div class="prog-fill" id="globalProg" style="width:0%"></div></div>

<?php if (isset($_GET['done'])): ?>
<!-- -- Thank you screen -- -->
<div class="thankyou">
  <div class="ty-card">
    <div class="ty-icon"><i class="fa fa-check"></i></div>
    <h2>Submitted!</h2>
    <p>Your interview responses have been recorded successfully. Thank you for conducting this field interview - the data will feed directly into the program's gap analysis.</p>
    <div style="margin-top:24px;padding:14px;background:var(--bg);border-radius:var(--r);font-size:.78rem;color:var(--soft)">
      <i class="fa fa-info-circle" style="margin-right:5px"></i>
      You may close this window. If you need to submit another interview, use the same link.
    </div>
  </div>
</div>

<?php elseif ($show_pin_form): ?>
<!-- -- PIN gate -- -->
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px">
  <div>
    <div style="text-align:center;margin-bottom:8px">
      <div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--soft)"><?= iv_h($site_name) ?></div>
    </div>
    <div class="pin-card">
      <div style="width:52px;height:52px;border-radius:50%;background:var(--a-l);display:flex;align-items:center;justify-content:center;margin:0 auto 18px;font-size:1.3rem;color:var(--a2)">
        <i class="fa fa-lock"></i>
      </div>
      <h2 style="font-family:var(--serif);font-style:italic;font-size:1.4rem;margin-bottom:8px">Access PIN required</h2>
      <p style="font-size:.82rem;color:var(--mid);margin-bottom:20px;line-height:1.6">
        This interview session is PIN-protected. Please enter the access PIN provided by your program coordinator.
      </p>
      <p style="font-size:.84rem;font-weight:600;color:var(--mid);margin-bottom:8px"><?= iv_h($interview['title']) ?></p>
      <form method="POST">
        <input type="hidden" name="action" value="verify_pin">
        <input type="hidden" name="t" value="<?= iv_h($token) ?>">
        <input type="text" name="pin" class="pin-input" maxlength="10"
               placeholder="� � � � �" autocomplete="off" required>
        <?php if (!empty($pin_error)): ?>
          <div class="pin-error"><i class="fa fa-exclamation-circle"></i> <?= iv_h($pin_error) ?></div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:14px;justify-content:center">
          <i class="fa fa-arrow-right"></i> Continue to interview
        </button>
      </form>
    </div>
  </div>
</div>

<?php else: ?>
<!-- -- Main interview form -- -->

<!-- Cover -->
<div class="cover">
  <div class="cover-inner" style="max-width:960px;margin:0 auto">
    <div class="cover-badge">
      <i class="fa fa-clipboard-list"></i>
      <?= iv_h($site_name) ?> . Field Interview
    </div>
    <h1><?= iv_h($interview['title']) ?></h1>
    <?php if ($interview['description']): ?>
      <p class="cover-desc"><?= nl2br(iv_h($interview['description'])) ?></p>
    <?php endif; ?>
    <div class="cover-meta">
      <?php if ($venture): ?>
        <span><i class="fa fa-building"></i> <?= iv_h($venture['name']) ?></span>
      <?php endif; ?>
      <?php if ($interview['assigned_to']): ?>
        <span><i class="fa fa-user-circle"></i> Assigned to <?= iv_h($interview['assigned_to']) ?></span>
      <?php endif; ?>
      <span><i class="fa fa-list-check"></i> <?= count($selected_sections) ?> sections</span>
      <?php if ($interview['expires_at']): ?>
        <span><i class="fa fa-clock"></i> Expires <?= date('M j, Y', strtotime($interview['expires_at'])) ?></span>
      <?php endif; ?>
    </div>
  </div>
</div>

<form method="POST" action="includes/process-interview-response.php" id="ivForm" novalidate>
  <input type="hidden" name="interview_token" value="<?= iv_h($token) ?>">

  <div class="layout">

    <!-- Side nav (desktop only) -->
    <nav class="side-nav" id="sideNav" aria-label="Interview sections">
      <div style="font-size:.66rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--soft);padding:0 12px;margin-bottom:4px">Sections</div>
      <!-- Interviewer info -->
      <button type="button" class="side-nav-item active" id="navInfo" onclick="scrollTo('infoCard')">
        <span class="nav-dot"></span> Interviewer info
      </button>
      <?php foreach ($selected_sections as $si => $skey):
        if (!isset($SECTION_LABELS[$skey])) continue;
      ?>
        <button type="button" class="side-nav-item" id="nav_<?= $skey ?>" onclick="scrollTo('sec_<?= $skey ?>')">
          <span class="nav-dot"></span> <?= iv_h($SECTION_LABELS[$skey]) ?>
        </button>
      <?php endforeach; ?>
      <?php if (!empty($custom_questions)): ?>
        <button type="button" class="side-nav-item" id="navCustom" onclick="scrollTo('sec_custom')">
          <span class="nav-dot"></span> Additional Questions
        </button>
      <?php endif; ?>
    </nav>

    <!-- Main form -->
    <div>

      <!-- Interviewer info block -->
      <div class="info-card" id="infoCard">
        <h3><i class="fa fa-user-circle" style="color:var(--a2);margin-right:6px"></i>Interviewer & Interview Details</h3>
        <div class="info-grid">
          <div class="info-group">
            <label class="info-label">Your name (interviewer) <span class="info-req">*</span></label>
            <input type="text" name="interviewer_name" class="info-control" required
                   placeholder="Full name"
                   value="<?= iv_h($interview['assigned_to'] ?? '') ?>">
          </div>
          <div class="info-group">
            <label class="info-label">Your role / title</label>
            <input type="text" name="interviewer_role" class="info-control" placeholder="e.g. Program Analyst">
          </div>
          <div class="info-group">
            <label class="info-label">Date of interview <span class="info-req">*</span></label>
            <input type="date" name="interview_date" class="info-control" required value="<?= date('Y-m-d') ?>">
          </div>
          <div class="info-group">
            <label class="info-label">Interview location / city</label>
            <input type="text" name="interview_location" class="info-control" placeholder="e.g. Nairobi, Kenya">
          </div>
          <div class="info-group">
            <label class="info-label">Name of venture contact interviewed <span class="info-req">*</span></label>
            <input type="text" name="venture_contact" class="info-control" required
                   placeholder="Person interviewed at the venture"
                   value="<?= iv_h($venture['name'] ?? '') ?>">
          </div>
          <div class="info-group">
            <label class="info-label">Interview duration (minutes)</label>
            <input type="number" name="duration_minutes" class="info-control" min="5" max="480" placeholder="e.g. 60">
          </div>
          <div class="info-group" style="grid-column:1/-1">
            <label class="info-label">Interview method</label>
            <select name="interview_method" class="info-control">
              <option value="in_person">In-person / on-site</option>
              <option value="phone">Phone call</option>
              <option value="video">Video call</option>
              <option value="written">Written / self-administered</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Sections -->
      <?php foreach ($selected_sections as $si => $skey):
        if (!isset($SECTION_QUESTIONS[$skey])) continue;
        $qs   = $SECTION_QUESTIONS[$skey];
        $lbl  = $SECTION_LABELS[$skey] ?? $skey;
        $qcount = count($qs);
      ?>
        <div class="section-block" id="sec_<?= iv_h($skey) ?>" style="animation-delay:<?= $si * 0.05 ?>s">
          <div class="section-head <?= $si === 0 ? 'open' : '' ?>" onclick="toggleSection('<?= $skey ?>')">
            <div class="section-head-left">
              <div class="section-num"><?= $si + 1 ?></div>
              <div>
                <div class="section-title"><?= iv_h($lbl) ?></div>
                <div class="section-subtitle"><?= $qcount ?> question<?= $qcount > 1 ? 's' : '' ?></div>
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:10px">
              <div class="section-completion" id="done_<?= $skey ?>">
                <i class="fa fa-check"></i>
              </div>
              <i class="fa fa-chevron-down section-toggle-icon"></i>
            </div>
          </div>
          <div class="section-body <?= $si === 0 ? 'open' : '' ?>" id="body_<?= $skey ?>">
            <?php foreach ($qs as $qi => $q):
              $fname = 'sec_'.$skey.'_q'.$qi;
              $is_req = $q['req'];
            ?>
              <div class="q-group" id="qg_<?= $fname ?>">
                <div class="q-label">
                  <?= iv_h($q['q']) ?>
                  <?php if ($is_req): ?><span class="q-req-dot">*</span><?php endif; ?>
                </div>

                <?php if ($q['type'] === 'textarea'): ?>
                  <textarea name="<?= $fname ?>" class="q-control" rows="3"
                            <?= $is_req ? 'required' : '' ?>
                            placeholder="Your answer..."
                            onchange="checkSectionDone('<?= $skey ?>')"></textarea>

                <?php elseif ($q['type'] === 'select'): ?>
                  <select name="<?= $fname ?>" class="q-control"
                          <?= $is_req ? 'required' : '' ?>
                          onchange="checkSectionDone('<?= $skey ?>')">
                    <option value="">- Select -</option>
                    <?php foreach ($q['options'] ?? [] as $opt): ?>
                      <option><?= iv_h($opt) ?></option>
                    <?php endforeach; ?>
                  </select>

                <?php elseif ($q['type'] === 'radio'): ?>
                  <div class="choice-list">
                    <?php foreach ($q['options'] ?? [] as $oi => $opt): ?>
                      <label class="choice-opt" onclick="selectChoice(this)">
                        <input type="radio" name="<?= $fname ?>" value="<?= iv_h($opt) ?>"
                               <?= $is_req ? 'required' : '' ?>
                               onchange="checkSectionDone('<?= $skey ?>')">
                        <?= iv_h($opt) ?>
                      </label>
                    <?php endforeach; ?>
                  </div>

                <?php elseif ($q['type'] === 'checkbox'): ?>
                  <div class="choice-list">
                    <?php foreach ($q['options'] ?? [] as $oi => $opt): ?>
                      <label class="choice-opt" onclick="toggleCheck(this)">
                        <input type="checkbox" name="<?= $fname ?>[]" value="<?= iv_h($opt) ?>"
                               onchange="checkSectionDone('<?= $skey ?>')">
                        <?= iv_h($opt) ?>
                      </label>
                    <?php endforeach; ?>
                  </div>

                <?php elseif ($q['type'] === 'number'): ?>
                  <input type="number" name="<?= $fname ?>" class="q-control"
                         <?= $is_req ? 'required' : '' ?>
                         oninput="checkSectionDone('<?= $skey ?>')">

                <?php elseif ($q['type'] === 'date'): ?>
                  <input type="date" name="<?= $fname ?>" class="q-control"
                         <?= $is_req ? 'required' : '' ?>
                         onchange="checkSectionDone('<?= $skey ?>')">

                <?php else: ?>
                  <input type="text" name="<?= $fname ?>" class="q-control"
                         <?= $is_req ? 'required' : '' ?> placeholder="Your answer..."
                         oninput="checkSectionDone('<?= $skey ?>')">
                <?php endif; ?>

                <div class="q-err" id="err_<?= $fname ?>" style="font-size:.72rem;color:var(--red);margin-top:4px;display:none">
                  <i class="fa fa-exclamation-circle"></i> This field is required.
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <!-- Custom questions -->
      <?php if (!empty($custom_questions)): ?>
        <div class="section-block" id="sec_custom" style="animation-delay:<?= count($selected_sections) * 0.05 ?>s">
          <div class="section-head" onclick="toggleSection('custom')">
            <div class="section-head-left">
              <div class="section-num"><?= count($selected_sections) + 1 ?></div>
              <div>
                <div class="section-title">Additional Questions</div>
                <div class="section-subtitle"><?= count($custom_questions) ?> question<?= count($custom_questions) > 1 ? 's' : '' ?></div>
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:10px">
              <div class="section-completion" id="done_custom"><i class="fa fa-check"></i></div>
              <i class="fa fa-chevron-down section-toggle-icon"></i>
            </div>
          </div>
          <div class="section-body" id="body_custom">
            <?php foreach ($custom_questions as $qi => $q):
              $fname = 'custom_q'.$qi;
              $is_req = (int)($q['required'] ?? 0) === 1;
            ?>
              <div class="q-group">
                <div class="q-label">
                  <?= iv_h($q['question'] ?? '') ?>
                  <?php if ($is_req): ?><span class="q-req-dot">*</span><?php endif; ?>
                </div>
                <?php $qtype = $q['type'] ?? 'text'; ?>
                <?php if ($qtype === 'textarea'): ?>
                  <textarea name="<?= $fname ?>" class="q-control" rows="3" <?= $is_req?'required':'' ?>></textarea>
                <?php elseif ($qtype === 'select'): ?>
                  <select name="<?= $fname ?>" class="q-control" <?= $is_req?'required':'' ?>>
                    <option value="">- Select -</option>
                    <?php foreach ($q['options']??[] as $opt): ?><option><?= iv_h($opt) ?></option><?php endforeach; ?>
                  </select>
                <?php elseif ($qtype === 'radio'): ?>
                  <div class="choice-list">
                    <?php foreach ($q['options']??[] as $opt): ?>
                      <label class="choice-opt" onclick="selectChoice(this)">
                        <input type="radio" name="<?= $fname ?>" value="<?= iv_h($opt) ?>" <?= $is_req?'required':'' ?>>
                        <?= iv_h($opt) ?>
                      </label>
                    <?php endforeach; ?>
                  </div>
                <?php elseif ($qtype === 'checkbox'): ?>
                  <div class="choice-list">
                    <?php foreach ($q['options']??[] as $opt): ?>
                      <label class="choice-opt" onclick="toggleCheck(this)">
                        <input type="checkbox" name="<?= $fname ?>[]" value="<?= iv_h($opt) ?>">
                        <?= iv_h($opt) ?>
                      </label>
                    <?php endforeach; ?>
                  </div>
                <?php elseif ($qtype === 'number'): ?>
                  <input type="number" name="<?= $fname ?>" class="q-control" <?= $is_req?'required':'' ?>>
                <?php else: ?>
                  <input type="text" name="<?= $fname ?>" class="q-control" <?= $is_req?'required':'' ?> placeholder="Your answer...">
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Submit button (large, inside form) -->
      <div style="text-align:center;padding:24px 0 100px">
        <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
          <i class="fa fa-paper-plane"></i> Submit interview
        </button>
        <p style="font-size:.72rem;color:var(--soft);margin-top:10px">
          All required fields (<span style="color:var(--red)">*</span>) must be filled before submitting.
        </p>
      </div>

    </div>
  </div>
</form>

<!-- Bottom bar -->
<div class="bottom-bar">
  <div class="bottom-progress">
    <span class="bottom-prog-text" id="progText">0% complete</span>
    <div class="bottom-prog-track"><div class="bottom-prog-fill" id="bottomProg" style="width:0%"></div></div>
  </div>
  <button type="submit" form="ivForm" class="btn btn-primary btn-sm">
    <i class="fa fa-paper-plane"></i> Submit
  </button>
</div>

<script>
/* -- Section accordion -- */
function toggleSection(key) {
  const head = document.querySelector(`#sec_${key} .section-head`);
  const body = document.getElementById('body_' + key);
  if (!head || !body) return;
  head.classList.toggle('open');
  body.classList.toggle('open');
}

/* -- Choice select -- */
function selectChoice(label) {
  const radio = label.querySelector('input[type=radio]');
  if (!radio) return;
  const group = label.closest('.choice-list');
  if (group) group.querySelectorAll('.choice-opt').forEach(l => l.classList.remove('selected'));
  label.classList.add('selected');
  radio.checked = true;
  const sec = label.closest('.section-block');
  if (sec) checkSectionDone(sec.id.replace('sec_', ''));
}

function toggleCheck(label) {
  const cb = label.querySelector('input[type=checkbox]');
  if (!cb) return;
  label.classList.toggle('selected', cb.checked);
  const sec = label.closest('.section-block');
  if (sec) checkSectionDone(sec.id.replace('sec_', ''));
}

/* -- Section done indicator -- */
function checkSectionDone(key) {
  const body   = document.getElementById('body_' + key);
  const dot    = document.getElementById('done_' + key);
  const navEl  = document.getElementById('nav_' + key);
  if (!body || !dot) return;

  const required = body.querySelectorAll('[required]');
  let allFilled = true;
  required.forEach(el => {
    if (el.type === 'radio') {
      const name = el.name;
      const checked = body.querySelector(`[name="${name}"]:checked`);
      if (!checked) allFilled = false;
    } else if (el.type === 'checkbox') {
      // optional check
    } else if (!el.value.trim()) {
      allFilled = false;
    }
  });

  dot.classList.toggle('done', allFilled && required.length > 0);
  if (navEl) navEl.classList.toggle('done', allFilled && required.length > 0);
  updateProgress();
}

/* -- Progress -- */
function updateProgress() {
  const all   = document.querySelectorAll('.section-block');
  const done  = document.querySelectorAll('.section-completion.done');
  const total = all.length + 1; // +1 for info card
  const pct   = Math.round((done.length / total) * 100);
  document.getElementById('globalProg').style.width  = pct + '%';
  document.getElementById('bottomProg').style.width  = pct + '%';
  document.getElementById('progText').textContent    = pct + '% complete';
}

/* -- Scroll nav -- */
function scrollTo(id) {
  const el = document.getElementById(id);
  if (el) el.scrollIntoView({ behavior:'smooth', block:'start' });
}

/* -- Intersection observer for side nav highlight -- */
const observer = new IntersectionObserver(entries => {
  entries.forEach(e => {
    if (e.isIntersecting) {
      const id  = e.target.id;
      const key = id === 'infoCard' ? 'Info' : id.replace('sec_', '');
      document.querySelectorAll('.side-nav-item').forEach(n => n.classList.remove('active'));
      const nav = document.getElementById(id === 'infoCard' ? 'navInfo' : 'nav_' + key);
      if (nav) nav.classList.add('active');
    }
  });
}, { threshold: 0.2 });

document.querySelectorAll('.section-block, #infoCard').forEach(el => observer.observe(el));

/* -- Form submit validation -- */
document.getElementById('ivForm').addEventListener('submit', function(e) {
  let valid = true;
  this.querySelectorAll('[required]').forEach(el => {
    const err = document.getElementById('err_' + el.name);
    if (el.type === 'radio') {
      const checked = this.querySelector(`[name="${el.name}"]:checked`);
      if (!checked) {
        valid = false;
        el.closest('.section-block')?.querySelector('.section-head')?.classList.add('open');
        document.getElementById('body_' + el.closest('.section-block')?.id?.replace('sec_',''))?.classList.add('open');
        if (err) err.style.display = '';
      } else { if (err) err.style.display = 'none'; }
    } else {
      if (!el.value.trim()) {
        el.classList.add('err');
        el.closest('.section-block')?.querySelector('.section-head')?.classList.add('open');
        document.getElementById('body_' + el.closest('.section-block')?.id?.replace('sec_',''))?.classList.add('open');
        if (err) err.style.display = '';
        valid = false;
      } else {
        el.classList.remove('err');
        if (err) err.style.display = 'none';
      }
    }
  });

  if (!valid) {
    e.preventDefault();
    const firstErr = document.querySelector('.err, .q-err:not([style*="none"])');
    if (firstErr) firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return false;
  }

  const btn = document.getElementById('submitBtn');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-circle-notch fa-spin"></i> Submitting...';
  }
});

/* Clear err on input */
document.querySelectorAll('.q-control, .info-control').forEach(el => {
  el.addEventListener('input', () => {
    el.classList.remove('err');
    const err = document.getElementById('err_' + el.name);
    if (err) err.style.display = 'none';
  });
});
</script>

<?php endif; ?>

</body>
</html>
