<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection failed.');
}

$conn->set_charset('utf8mb4');

function ps_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ps_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function ps_error_page(string $title, string $message, string $home_url = '/'): void
{
    http_response_code(200);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= ps_h($title) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Favicon -->
<link rel="icon" type="image/png" sizes="32x32" href="assets/images/favicon.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/images/favicon.png">
<link rel="shortcut icon" href="assets/images/favicon.png" type="image/png">
<link rel="apple-touch-icon" href="assets/images/favicon.png">

<!-- Theme Color -->
<meta name="theme-color" content="#fc7c10">

<!-- Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f8fafc;font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#0f172a;padding:20px}
.card{max-width:520px;width:100%;background:#fff;border:1px solid #e5e7eb;border-radius:24px;padding:42px;text-align:center;box-shadow:0 18px 50px rgba(15,23,42,.10)}
.icon{width:78px;height:78px;border-radius:999px;background:#fee2e2;color:#dc2626;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:28px}
h1{margin:0 0 12px;font-size:28px}
p{margin:0 0 24px;color:#64748b;line-height:1.7}
a{display:inline-flex;align-items:center;gap:8px;background:#0f172a;color:#fff;text-decoration:none;border-radius:999px;padding:12px 22px;font-weight:700}
</style>
</head>
<body>
<div class="card">
  <div class="icon"><i class="fa fa-exclamation-triangle"></i></div>
  <h1><?= ps_h($title) ?></h1>
  <p><?= nl2br(ps_h($message)) ?></p>
  <a href="<?= ps_h($home_url) ?>"><i class="fa fa-home"></i> Back to Home</a>
</div>
</body>
</html>
    <?php
    exit;
}

/* -----------------------------------------------------------
   File helper labels
----------------------------------------------------------- */

function ps_file_accept(string $types_csv): string
{
    $map = [
        'images'       => 'image/*',
        'documents'    => '.pdf,.doc,.docx,.txt,.odt,.rtf',
        'spreadsheets' => '.xls,.xlsx,.csv,.ods',
        'videos'       => 'video/*',
        'audio'        => 'audio/*',
        'any'          => '*',
    ];

    $groups = array_filter(array_map('trim', explode(',', strtolower($types_csv ?: 'any'))));
    $accepts = [];

    foreach ($groups as $group) {
        if (isset($map[$group])) {
            $accepts[] = $map[$group];
        }
    }

    if (!$accepts || in_array('*', $accepts, true)) {
        return '*';
    }

    return implode(',', array_unique($accepts));
}

function ps_file_type_label(string $types_csv): string
{
    $labels = [
        'images'       => 'Images',
        'documents'    => 'Documents',
        'spreadsheets' => 'Spreadsheets',
        'videos'       => 'Videos',
        'audio'        => 'Audio',
        'any'          => 'Any file type',
    ];

    $groups = array_filter(array_map('trim', explode(',', strtolower($types_csv ?: 'any'))));
    if (!$groups) {
        return 'Any file type';
    }

    $out = [];
    foreach ($groups as $group) {
        $out[] = $labels[$group] ?? ucfirst($group);
    }

    return implode(', ', $out);
}

/* -----------------------------------------------------------
   Load survey
----------------------------------------------------------- */

$token = trim((string)($_GET['t'] ?? ''));

if ($token === '') {
    ps_error_page('Survey Not Found', 'The survey link is missing or invalid.');
}

$stmt = $conn->prepare('SELECT * FROM surveys WHERE external_token = ? LIMIT 1');
$stmt->bind_param('s', $token);
$stmt->execute();
$survey = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$survey) {
    ps_error_page('Survey Not Found', 'We could not find this survey. The link may be invalid or expired.');
}

$site_name = function_exists('get_setting')
    ? get_setting($conn, 'site_name', 'EdTech Fellowship')
    : 'EdTech Fellowship';


$official_website_url = function_exists('get_setting')
    ? get_setting($conn, 'website_url', 'https://www.edtech.hivecolab.com')
    : 'https://www.edtech.hivecolab.com';

if (($survey['status'] ?? '') !== 'published') {
    ps_error_page('Survey Unavailable', 'This survey is not currently accepting responses.');
}

$now = time();

if (!empty($survey['starts_at']) && strtotime((string)$survey['starts_at']) > $now) {
    ps_error_page('Survey Not Open Yet', 'This survey has not opened yet.');
}

if (!empty($survey['ends_at']) && strtotime((string)$survey['ends_at']) < $now) {
    ps_error_page('Survey Closed', 'This survey has closed and is no longer accepting responses.');
}

$survey_id = (int)$survey['id'];
$venture_id = !empty($_SESSION['venture_id']) ? (int)$_SESSION['venture_id'] : null;

if ((int)($survey['require_venture_login'] ?? 0) === 1 && !$venture_id) {
    ps_redirect('login.php?redirect=' . urlencode('survey.php?t=' . $token));
}

/* -----------------------------------------------------------
   Duplicate response check
----------------------------------------------------------- */

if ($venture_id && (int)($survey['allow_multiple_responses'] ?? 0) === 0) {
    $check = $conn->prepare('
        SELECT id, submitted_at
        FROM survey_responses
        WHERE survey_id = ? AND venture_id = ?
        LIMIT 1
    ');
    $check->bind_param('ii', $survey_id, $venture_id);
    $check->execute();
    $already = $check->get_result()->fetch_assoc();
    $check->close();

    if ($already) {
        ps_error_page('Already Submitted', 'You have already submitted this survey. Only one response is allowed.');
    }
}

/* -----------------------------------------------------------
   Venture details for logged-in ventures
----------------------------------------------------------- */

$venture_prefill = [
    'name' => '',
    'email' => '',
];

if ($venture_id) {
    $vq = $conn->prepare('SELECT name, email FROM ventures WHERE id = ? LIMIT 1');
    $vq->bind_param('i', $venture_id);
    $vq->execute();
    $venture_prefill = $vq->get_result()->fetch_assoc() ?: $venture_prefill;
    $vq->close();
}

/* -----------------------------------------------------------
   Load active questions and options
----------------------------------------------------------- */

$questions = [];

$stmt = $conn->prepare('
    SELECT *
    FROM survey_questions
    WHERE survey_id = ? AND status = 1
    ORDER BY sort_order ASC, id ASC
');
$stmt->bind_param('i', $survey_id);
$stmt->execute();
$qres = $stmt->get_result();

while ($q = $qres->fetch_assoc()) {
    $q['options'] = [];

    $opt = $conn->prepare('
        SELECT *
        FROM survey_question_options
        WHERE question_id = ?
        ORDER BY sort_order ASC, id ASC
    ');
    $qid = (int)$q['id'];
    $opt->bind_param('i', $qid);
    $opt->execute();
    $ores = $opt->get_result();

    while ($o = $ores->fetch_assoc()) {
        $q['options'][] = $o;
    }

    $opt->close();
    $questions[] = $q;
}

$stmt->close();

if (!$questions) {
    ps_error_page('No Questions Available', 'This survey has no active questions.');
}

/* -----------------------------------------------------------
   Build steps
----------------------------------------------------------- */

$steps = [];

/*
 * Identity is optional. Respondents may provide details or continue anonymously.
 */
$steps[] = ['type' => 'respondent_info'];

foreach ($questions as $q) {
    $steps[] = ['type' => 'question', 'data' => $q];
}

$total_steps = count($steps);
$letters = range('A', 'Z');

$theme_color = !empty($survey['theme_color']) ? (string)$survey['theme_color'] : '#0f172a';
$header_style = !empty($survey['header_style']) ? (string)$survey['header_style'] : 'color';
$header_image = !empty($survey['header_image']) ? (string)$survey['header_image'] : '';
$done = isset($_GET['done']) && $_GET['done'] === '1';

$intro_text = trim((string)($survey['welcome_text'] ?: ($survey['description'] ?? '')));
// Collapse 3+ consecutive newlines down to a single paragraph
// break, so stray blank lines in the stored text don't produce
// oversized gaps once rendered.
$intro_text = preg_replace('/\R{2,}/', "\n\n", $intro_text) ?? $intro_text;

$has_file_upload = false;
foreach ($questions as $q) {
    if (($q['field_type'] ?? '') === 'file_upload') {
        $has_file_upload = true;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= ps_h($survey['title']) ?> - <?= ps_h($site_name) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link rel="stylesheet" href="assets/css/survey.css">
<style>
:root{
  --accent: <?= ps_h($theme_color) ?>;
  --text:#101827;
  --muted:#64748b;
  --line:#e5e7eb;
  --card:#ffffff;
}
.optional-label{
  color:var(--muted);
  font-size:.82em;
  font-weight:500;
}
</style>
</head>
<body>

<div class="progress-track">
  <div class="progress-fill" id="progressFill"></div>
</div>

<div class="survey-topbar">
  <span class="topbar-brand"><span class="dot"></span><?= ps_h($site_name) ?></span>
  <span class="topbar-steps" id="topbarSteps"></span>
</div>

<div class="survey-shell">

  <div class="survey-hero">
    <?php if ($header_style === 'image' && $header_image !== ''): ?>
      <div class="hero-image-bg">
        <img src="<?= ps_h($header_image) ?>" alt="Survey header">
      </div>
    <?php else: ?>
      <div class="hero-color-bg">
        <div class="hero-deco hero-deco-1"></div>
        <div class="hero-deco hero-deco-2"></div>
        <div class="hero-deco hero-deco-3"></div>
      </div>
    <?php endif; ?>

    <div class="hero-content">
      <div class="hero-badge"><i class="fa fa-poll-h"></i><?= ps_h($site_name) ?></div>
      <h1 class="hero-title"><?= ps_h($survey['title'] ?? 'Survey') ?></h1>
    </div>
  </div>

  <?php if ($done): ?>
    <div class="thankyou-screen active">
      <div class="thankyou-card">
        <div class="ty-icon"><i class="fa fa-check"></i></div>
        <h2>Thank you!</h2>
        <p>
          <?php if (!empty($survey['thank_you_text'])): ?>
            <?= nl2br(ps_h($survey['thank_you_text'])) ?>
          <?php else: ?>
            Your response has been recorded. We really appreciate your time and input.
          <?php endif; ?>
        </p>
        <div class="ty-actions">
          <p class="ty-redirect-note">
            Redirecting you in <span id="tyCountdown">5</span> seconds...
          </p>
          <a href="<?= ps_h($official_website_url) ?>" class="btn-nav btn-next" id="tyCloseBtn">
            Close <i class="fa fa-arrow-right"></i>
          </a>
        </div>
      </div>
    </div>
    <script>
      (function(){
        var seconds = 5;
        var target = <?= json_encode($official_website_url) ?>;
        var el = document.getElementById('tyCountdown');
        var timer = setInterval(function(){
          seconds -= 1;
          if (el) el.textContent = Math.max(seconds, 0);
          if (seconds <= 0) {
            clearInterval(timer);
            window.location.href = target;
          }
        }, 1000);
      })();
    </script>
  <?php else: ?>

    <div class="start-screen" id="startScreen">
      <div class="start-card">
        <?php if ($intro_text !== ''): ?>
          <div class="start-card-intro">
            <p><?= nl2br(ps_h($intro_text)) ?></p>
          </div>
        <?php endif; ?>

        <h2>Ready to begin?</h2>
        <p>
          This survey has <strong><?= (int)$total_steps ?></strong>
          <?= $total_steps === 1 ? 'step' : 'steps' ?>.
          Take your time - your answers matter.
        </p>
        <div class="start-meta">
          <div class="start-meta-item"><i class="fa fa-clock"></i> ~<?= max(1, (int)ceil($total_steps * 0.5)) ?> min</div>
          <div class="start-meta-item"><i class="fa fa-shield-alt"></i> Secure</div>
          <?php if ((int)($survey['allow_multiple_responses'] ?? 0) === 0): ?>
            <div class="start-meta-item"><i class="fa fa-check-circle"></i> One response</div>
          <?php endif; ?>
          <?php if ($has_file_upload): ?>
            <div class="start-meta-item"><i class="fa fa-paperclip"></i> File upload</div>
          <?php endif; ?>
        </div>
        <button type="button" class="btn-nav btn-next" style="width:100%;justify-content:center;border-radius:16px;padding:16px" onclick="startSurvey()">
          Start Survey <i class="fa fa-arrow-right"></i>
        </button>
      </div>
    </div>

    <form method="POST"
          action="includes/process-survey-response.php"
          id="surveyForm"
          enctype="multipart/form-data"
          novalidate>
      <input type="hidden" name="survey_token" value="<?= ps_h($token) ?>">
<div class="alert-box" id="formAlert"></div>

      <div class="step-stage">
        <?php foreach ($steps as $index => $step): ?>
          <?php if ($step['type'] === 'respondent_info'): ?>
            <div class="step" id="step-<?= (int)$index ?>" data-index="<?= (int)$index ?>" data-type="respondent_info">
              <div class="step-num"><span><?= (int)($index + 1) ?></span> About you</div>
              <div class="step-question">Would you like to identify yourself?</div>
              <div class="step-help">
                This survey supports anonymous responses. Name, email and organisation are optional.
                Leave them blank if you prefer to remain anonymous.
              </div>

              <div class="info-grid">
                <div class="field-wrap">
                  <label class="field-label" for="respondent_name">Full name <span class="optional-label">(Optional)</span></label>
                  <input class="field-input" id="respondent_name" name="respondent_name" type="text" autocomplete="name" placeholder="Leave blank to remain anonymous">
                  <div class="field-error" id="err-respondent_name"></div>
                </div>

                <div class="field-wrap">
                  <label class="field-label" for="respondent_email">Email <span class="optional-label">(Optional)</span></label>
                  <input class="field-input" id="respondent_email" name="respondent_email" type="email" autocomplete="email" placeholder="Leave blank to remain anonymous">
                  <div class="field-error" id="err-respondent_email">Enter a valid email address or leave it blank.</div>
                </div>
              </div>

              <div class="field-wrap" style="margin-top:14px">
                <label class="field-label" for="venture_name">Venture / organisation name <span class="optional-label">(Optional)</span></label>
                <input class="field-input" id="venture_name" name="venture_name" type="text" autocomplete="organization" placeholder="Leave blank if not applicable">
                <div class="field-error" id="err-venture_name"></div>
              </div>
            </div>
          <?php else: ?>
            <?php
              $q = $step['data'];
              $qid = (int)$q['id'];
              $field_name = 'q_' . $qid;
              $field_type = (string)($q['field_type'] ?? 'short_text');
              $required = (int)($q['is_required'] ?? 0) === 1;
            ?>
            <div class="step"
                 id="step-<?= (int)$index ?>"
                 data-index="<?= (int)$index ?>"
                 data-type="<?= ps_h($field_type) ?>"
                 data-name="<?= ps_h($field_name) ?>"
                 data-required="<?= $required ? '1' : '0' ?>"
                 <?php if ($field_type === 'file_upload'): ?>
                   data-max-size="<?= (int)($q['file_max_size_mb'] ?? 10) ?>"
                   data-max-count="<?= (int)($q['file_max_count'] ?? 1) ?>"
                   data-allowed-types="<?= ps_h($q['file_allowed_types'] ?? 'any') ?>"
                 <?php endif; ?>>

              <?php if (!empty($q['section_title'])): ?>
                <div class="step-section-label"><?= ps_h($q['section_title']) ?></div>
              <?php endif; ?>

              <div class="step-num"><span><?= (int)($index + 1) ?></span> Question</div>

              <div class="step-question">
                <?= ps_h($q['question_text'] ?? '') ?>
                <?php if ($required): ?><span class="req-dot">*</span><?php endif; ?>
              </div>

              <?php if (!empty($q['help_text'])): ?>
                <div class="step-help"><?= nl2br(ps_h($q['help_text'])) ?></div>
              <?php endif; ?>

              <?php
                $input_type = match ($field_type) {
                    'email' => 'email',
                    'phone' => 'tel',
                    'number', 'currency' => 'number',
                    'date' => 'date',
                    default => 'text',
                };
              ?>

              <?php if (in_array($field_type, ['short_text','email','phone','number','currency','date'], true)): ?>
                <div class="field-wrap">
                  <input class="field-input"
                         type="<?= ps_h($input_type) ?>"
                         name="<?= ps_h($field_name) ?>"
                         <?= $required ? 'required' : '' ?>
                         <?= $field_type === 'currency' ? 'step="0.01" placeholder="0.00"' : '' ?>
                         <?= $field_type === 'email' ? 'placeholder="you@example.com"' : '' ?>>
                  <div class="field-error" id="err-<?= ps_h($field_name) ?>">This field is required.</div>
                </div>

              <?php elseif (in_array($field_type, ['long_text','consent'], true)): ?>
                <div class="field-wrap">
                  <textarea class="field-input" name="<?= ps_h($field_name) ?>" rows="5" <?= $required ? 'required' : '' ?> placeholder="Type your answer here..."></textarea>
                  <div class="field-error" id="err-<?= ps_h($field_name) ?>">This field is required.</div>
                </div>

              <?php elseif ($field_type === 'yes_no'): ?>
                <div class="yesno-grid" id="yesno-<?= ps_h($field_name) ?>">
                  <label class="yesno-btn" onclick="selectYesNo('<?= ps_h($field_name) ?>', this)">
                    <input type="radio" name="<?= ps_h($field_name) ?>" value="Yes" <?= $required ? 'required' : '' ?>>
                    <i class="fa fa-thumbs-up"></i> Yes
                  </label>
                  <label class="yesno-btn" onclick="selectYesNo('<?= ps_h($field_name) ?>', this)">
                    <input type="radio" name="<?= ps_h($field_name) ?>" value="No" <?= $required ? 'required' : '' ?>>
                    <i class="fa fa-thumbs-down"></i> No
                  </label>
                </div>
                <div class="field-error" id="err-<?= ps_h($field_name) ?>">Please select Yes or No.</div>

              <?php elseif ($field_type === 'single_choice'): ?>
                <div class="choice-grid" id="choices-<?= ps_h($field_name) ?>">
                  <?php foreach ($q['options'] as $oi => $o): ?>
                    <label class="choice-opt" onclick="selectChoice('<?= ps_h($field_name) ?>','single',this)">
                      <input type="radio" name="<?= ps_h($field_name) ?>" value="<?= ps_h($o['option_label'] ?? '') ?>" <?= $required ? 'required' : '' ?>>
                      <div class="choice-marker">
                        <span class="choice-letter"><?= ps_h($letters[$oi % 26]) ?></span>
                        <i class="fa fa-check" style="display:none"></i>
                      </div>
                      <?= ps_h($o['option_label'] ?? '') ?>
                    </label>
                  <?php endforeach; ?>
                </div>
                <div class="field-error" id="err-<?= ps_h($field_name) ?>">Please select an option.</div>

              <?php elseif ($field_type === 'multiple_choice'): ?>
                <div class="choice-grid" id="choices-<?= ps_h($field_name) ?>">
                  <?php foreach ($q['options'] as $oi => $o): ?>
                    <label class="choice-opt" onclick="selectChoice('<?= ps_h($field_name) ?>','multi',this)">
                      <input type="checkbox" name="<?= ps_h($field_name) ?>[]" value="<?= ps_h($o['option_label'] ?? '') ?>">
                      <div class="choice-marker">
                        <span class="choice-letter"><?= ps_h($letters[$oi % 26]) ?></span>
                        <i class="fa fa-check" style="display:none"></i>
                      </div>
                      <?= ps_h($o['option_label'] ?? '') ?>
                    </label>
                  <?php endforeach; ?>
                </div>
                <div class="field-error" id="err-<?= ps_h($field_name) ?>">Please select at least one option.</div>

              <?php elseif ($field_type === 'dropdown'): ?>
                <div class="field-wrap">
                  <select class="field-input" name="<?= ps_h($field_name) ?>" <?= $required ? 'required' : '' ?>>
                    <option value="">- Choose an option -</option>
                    <?php foreach ($q['options'] as $o): ?>
                      <option value="<?= ps_h($o['option_label'] ?? '') ?>"><?= ps_h($o['option_label'] ?? '') ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div class="field-error" id="err-<?= ps_h($field_name) ?>">Please select an option.</div>
                </div>

              <?php elseif ($field_type === 'rating'): ?>
                <?php
                  $rmin = (int)($q['scale_min'] ?: 1);
                  $rmax = (int)($q['scale_max'] ?: 5);
                ?>
                <div class="scale-wrap">
                  <div class="stars-wrap" id="stars-<?= ps_h($field_name) ?>">
                    <?php for ($i = $rmin; $i <= $rmax; $i++): ?>
                      <label class="star-btn" onclick="selectStar('<?= ps_h($field_name) ?>', <?= (int)$i ?>, this)">
                        <input type="radio" name="<?= ps_h($field_name) ?>" value="<?= (int)$i ?>" <?= $required ? 'required' : '' ?>>
                        <i class="fa fa-star"></i>
                      </label>
                    <?php endfor; ?>
                  </div>
                  <div class="scale-labels">
                    <span><?= ps_h($q['scale_min_label'] ?? '') ?></span>
                    <span><?= ps_h($q['scale_max_label'] ?? '') ?></span>
                  </div>
                  <div class="field-error" id="err-<?= ps_h($field_name) ?>">Please select a rating.</div>
                </div>

              <?php elseif ($field_type === 'linear_scale'): ?>
                <?php
                  $smin = (int)($q['scale_min'] ?: 1);
                  $smax = (int)($q['scale_max'] ?: 5);
                ?>
                <div class="scale-wrap">
                  <div class="scale-bubbles" id="scale-<?= ps_h($field_name) ?>">
                    <?php for ($i = $smin; $i <= $smax; $i++): ?>
                      <label class="scale-bubble" onclick="selectBubble('<?= ps_h($field_name) ?>', this)">
                        <input type="radio" name="<?= ps_h($field_name) ?>" value="<?= (int)$i ?>" <?= $required ? 'required' : '' ?>>
                        <?= (int)$i ?>
                      </label>
                    <?php endfor; ?>
                  </div>
                  <div class="scale-labels">
                    <span><?= ps_h($q['scale_min_label'] ?: $smin) ?></span>
                    <span><?= ps_h($q['scale_max_label'] ?: $smax) ?></span>
                  </div>
                  <div class="field-error" id="err-<?= ps_h($field_name) ?>">Please select a value.</div>
                </div>

              <?php elseif ($field_type === 'file_upload'): ?>
                <?php
                  $max_mb = max(1, (int)($q['file_max_size_mb'] ?? 10));
                  $max_count = max(1, (int)($q['file_max_count'] ?? 1));
                  $types_csv = (string)($q['file_allowed_types'] ?? 'any');
                  $accept = ps_file_accept($types_csv);
                  $type_label = ps_file_type_label($types_csv);
                  $multiple = $max_count > 1;
                ?>

                <input type="file"
                       id="file-input-<?= ps_h($field_name) ?>"
                       name="<?= ps_h($field_name) ?><?= $multiple ? '[]' : '' ?>"
                       <?= $accept !== '*' ? 'accept="' . ps_h($accept) . '"' : '' ?>
                       <?= $multiple ? 'multiple' : '' ?>
                       style="display:none"
                       onchange="handleFileSelect('<?= ps_h($field_name) ?>', this)">

                <div class="upload-zone"
                     id="zone-<?= ps_h($field_name) ?>"
                     onclick="document.getElementById('file-input-<?= ps_h($field_name) ?>').click()"
                     ondragover="zoneDragOver(event, '<?= ps_h($field_name) ?>')"
                     ondragleave="zoneDragLeave('<?= ps_h($field_name) ?>')"
                     ondrop="zoneDrop(event, '<?= ps_h($field_name) ?>')">
                  <div class="upload-zone-icon"><i class="fa fa-cloud-upload-alt"></i></div>
                  <div class="upload-zone-title">
                    <?= $multiple ? 'Drop files here or' : 'Drop a file here or' ?>
                    <span style="color:var(--accent)">browse</span>
                  </div>
                  <div class="upload-zone-hint">
                    <strong><?= ps_h($type_label) ?></strong>
                    &nbsp;·&nbsp; Max <?= (int)$max_mb ?> MB per file
                    <?php if ($multiple): ?>
                      &nbsp;·&nbsp; Up to <?= (int)$max_count ?> files
                    <?php endif; ?>
                  </div>
                </div>

                <div class="upload-constraints">
                  <span class="upload-constraint-chip"><i class="fa fa-file-alt"></i><?= ps_h($type_label) ?></span>
                  <span class="upload-constraint-chip"><i class="fa fa-weight"></i>Max <?= (int)$max_mb ?> MB</span>
                  <?php if ($multiple): ?>
                    <span class="upload-constraint-chip"><i class="fa fa-copy"></i>Up to <?= (int)$max_count ?> files</span>
                  <?php endif; ?>
                </div>

                <div class="file-list" id="files-<?= ps_h($field_name) ?>"></div>
                <div class="field-error" id="err-<?= ps_h($field_name) ?>"><?= $required ? 'Please upload at least one file.' : 'Please fix the selected files.' ?></div>

              <?php else: ?>
                <div class="field-wrap">
                  <input class="field-input" type="text" name="<?= ps_h($field_name) ?>" <?= $required ? 'required' : '' ?>>
                  <div class="field-error" id="err-<?= ps_h($field_name) ?>">This field is required.</div>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <div class="step-nav" id="stepNav">
        <button type="button" class="btn-nav btn-back" id="btnBack" onclick="prevStep()" style="visibility:hidden">
          <i class="fa fa-arrow-left"></i> Back
        </button>
        <div class="nav-hint"><kbd class="kbd">Enter</kbd> to continue</div>
        <button type="button" class="btn-nav btn-next" id="btnNext" onclick="nextStep()">
          Next <i class="fa fa-arrow-right"></i>
        </button>
      </div>
    </form>

    <div class="thankyou-screen" id="thankYouScreen">
      <div class="thankyou-card">
        <div class="ty-icon"><i class="fa fa-check"></i></div>
        <h2>Thank you!</h2>
        <p>
          <?php if (!empty($survey['thank_you_text'])): ?>
            <?= nl2br(ps_h($survey['thank_you_text'])) ?>
          <?php else: ?>
            Your response has been recorded. We really appreciate your time and input.
          <?php endif; ?>
        </p>
        <div class="ty-actions">
          <p class="ty-redirect-note">
            Redirecting you in <span id="tyCountdown">5</span> seconds...
          </p>
          <a href="<?= ps_h($official_website_url) ?>" class="btn-nav btn-next" id="tyCloseBtn">
            Close <i class="fa fa-arrow-right"></i>
          </a>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php if (!$done): ?>
<script>
(function(){
  const TOTAL = <?= (int)$total_steps ?>;
  let currentStep = 0;
  let started = false;
  const fileRegistry = {};

  const progressFill = document.getElementById('progressFill');
  const topbarSteps = document.getElementById('topbarSteps');
  const btnBack = document.getElementById('btnBack');
  const btnNext = document.getElementById('btnNext');
  const stepNav = document.getElementById('stepNav');
  const surveyForm = document.getElementById('surveyForm');
  const startScreen = document.getElementById('startScreen');
  const thankYouScreen = document.getElementById('thankYouScreen');
  const formAlert = document.getElementById('formAlert');

  topbarSteps.innerHTML = `<strong>0</strong> / ${TOTAL}`;

  window.startSurvey = function(){
    startScreen.style.display = 'none';
    surveyForm.classList.add('active');
    started = true;
    showStep(0);
  };

  function showStep(index){
    document.querySelectorAll('.step').forEach(step => step.classList.remove('active'));
    const el = document.getElementById('step-' + index);
    if (!el) return;

    el.classList.add('active');
    currentStep = index;

    const pct = TOTAL <= 1 ? 90 : Math.round((index / TOTAL) * 100);
    progressFill.style.width = pct + '%';
    topbarSteps.innerHTML = `<strong>${index + 1}</strong> / ${TOTAL}`;

    btnBack.style.visibility = index === 0 ? 'hidden' : 'visible';

    if (index === TOTAL - 1) {
      btnNext.innerHTML = 'Submit <i class="fa fa-check"></i>';
    } else {
      btnNext.innerHTML = 'Next <i class="fa fa-arrow-right"></i>';
    }

    hideFormAlert();

    setTimeout(() => {
      const input = el.querySelector('input:not([type=radio]):not([type=checkbox]):not([type=file]), textarea, select');
      if (input) input.focus();
    }, 250);
  }

  function validateStep(index){
    const el = document.getElementById('step-' + index);
    if (!el) return true;

    if (el.dataset.type === 'respondent_info') {
      const email = document.getElementById('respondent_email');
      const emailValue = email ? email.value.trim() : '';

      // All identity fields are optional. Validate email only when supplied.
      if (emailValue !== '' && !email.checkValidity()) {
        setError(email, 'err-respondent_email', true);
        return false;
      }

      if (email) setError(email, 'err-respondent_email', false);
      return true;
    }

    const required = el.dataset.required === '1';
    const fieldName = el.dataset.name;
    const type = el.dataset.type;
    const err = document.getElementById('err-' + fieldName);

    if (type === 'file_upload') {
      const files = fileRegistry[fieldName] || [];
      const maxSize = parseInt(el.dataset.maxSize || '10', 10);
      const maxCount = parseInt(el.dataset.maxCount || '1', 10);
      const zone = document.getElementById('zone-' + fieldName);

      if (required && files.length === 0) {
        if (zone) zone.classList.add('error-zone');
        showFieldError(err, 'Please upload at least one file.');
        return false;
      }

      if (files.length > maxCount) {
        if (zone) zone.classList.add('error-zone');
        showFieldError(err, `You can upload a maximum of ${maxCount} file${maxCount > 1 ? 's' : ''}.`);
        return false;
      }

      const oversized = files.find(file => file.size > maxSize * 1024 * 1024);
      if (oversized) {
        if (zone) zone.classList.add('error-zone');
        showFieldError(err, `"${oversized.name}" exceeds the ${maxSize} MB limit.`);
        return false;
      }

      if (zone) zone.classList.remove('error-zone');
      if (err) err.classList.remove('visible');
      return true;
    }

    if (!required) return true;

    if (['single_choice','yes_no','rating','linear_scale'].includes(type)) {
      const checked = el.querySelector('input[type=radio]:checked');
      if (!checked) {
        showFieldError(err, 'Please select an option.');
        return false;
      }
      if (err) err.classList.remove('visible');
      return true;
    }

    if (type === 'multiple_choice') {
      const checked = el.querySelector('input[type=checkbox]:checked');
      if (!checked) {
        showFieldError(err, 'Please select at least one option.');
        return false;
      }
      if (err) err.classList.remove('visible');
      return true;
    }

    const input = el.querySelector(`[name="${cssEscape(fieldName)}"]`);
    if (!input || !String(input.value || '').trim()) {
      if (input) input.classList.add('error');
      showFieldError(err, 'This field is required.');
      return false;
    }

    if (input) input.classList.remove('error');
    if (err) err.classList.remove('visible');
    return true;
  }

  function setError(input, errId, visible){
    if (!input) return;
    const err = document.getElementById(errId);
    input.classList.toggle('error', visible);
    if (err) err.classList.toggle('visible', visible);
  }

  function showFieldError(err, message){
    if (!err) return;
    err.textContent = message;
    err.classList.add('visible');
  }

  window.nextStep = function(){
    if (!validateStep(currentStep)) return;

    if (currentStep >= TOTAL - 1) {
      submitForm();
      return;
    }

    showStep(currentStep + 1);
  };

  window.prevStep = function(){
    if (currentStep <= 0) return;
    showStep(currentStep - 1);
  };

  async function submitForm(){
    btnNext.disabled = true;
    btnNext.innerHTML = '<i class="fa fa-circle-notch fa-spin"></i> Submitting...';
    hideFormAlert();

    const fd = new FormData(surveyForm);

    Object.entries(fileRegistry).forEach(([fieldName, files]) => {
      fd.delete(fieldName);
      fd.delete(fieldName + '[]');

      files.forEach(file => {
        fd.append(fieldName + (files.length > 1 ? '[]' : ''), file, file.name);
      });
    });

    try {
      const response = await fetch(surveyForm.action, {
        method: 'POST',
        body: fd,
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });

      let data = null;
      const text = await response.text();

      try {
        data = text ? JSON.parse(text) : null;
      } catch (e) {
        data = null;
      }

      if (!response.ok || !data || data.ok !== true) {
        const errors = data && Array.isArray(data.errors) ? data.errors : [];
        const message = (data && (data.message || data.error)) || errors.join('<br>') || 'Submission failed. Please check your answers and try again.';
        throw new Error(message);
      }

      surveyForm.classList.remove('active');
      stepNav.style.display = 'none';
      thankYouScreen.classList.add('active');
      progressFill.style.width = '100%';
      topbarSteps.textContent = 'Done';

      if (data.redirect) {
        window.history.replaceState(null, '', data.redirect);
      }

      startRedirectCountdown();

    } catch (error) {
      showFormAlert(error.message || 'Submission failed. Please try again.');
      btnNext.disabled = false;
      btnNext.innerHTML = 'Submit <i class="fa fa-check"></i>';
    }
  }

  function showFormAlert(message){
    if (!formAlert) return;
    formAlert.innerHTML = String(message);
    formAlert.classList.add('visible');
    formAlert.scrollIntoView({behavior:'smooth', block:'center'});
  }

  function hideFormAlert(){
    if (!formAlert) return;
    formAlert.classList.remove('visible');
    formAlert.innerHTML = '';
  }

  function startRedirectCountdown(){
    const target = <?= json_encode($official_website_url) ?>;
    const countdownEl = document.getElementById('tyCountdown');
    let seconds = 5;

    const timer = setInterval(function(){
      seconds -= 1;
      if (countdownEl) countdownEl.textContent = Math.max(seconds, 0);
      if (seconds <= 0) {
        clearInterval(timer);
        window.location.href = target;
      }
    }, 1000);
  }

  window.handleFileSelect = function(fieldName, input){
    if (!input.files || input.files.length === 0) return;
    addFiles(fieldName, Array.from(input.files));
    input.value = '';
  };

  window.zoneDragOver = function(event, fieldName){
    event.preventDefault();
    event.stopPropagation();
    const zone = document.getElementById('zone-' + fieldName);
    if (zone) zone.classList.add('dragover');
  };

  window.zoneDragLeave = function(fieldName){
    const zone = document.getElementById('zone-' + fieldName);
    if (zone) zone.classList.remove('dragover');
  };

  window.zoneDrop = function(event, fieldName){
    event.preventDefault();
    event.stopPropagation();

    const zone = document.getElementById('zone-' + fieldName);
    if (zone) zone.classList.remove('dragover');

    const files = Array.from(event.dataTransfer.files || []);
    if (files.length) addFiles(fieldName, files);
  };

  function addFiles(fieldName, incoming){
    if (!fileRegistry[fieldName]) fileRegistry[fieldName] = [];

    const step = document.querySelector(`.step[data-name="${cssEscape(fieldName)}"]`);
    const maxCount = step ? parseInt(step.dataset.maxCount || '1', 10) : 1;
    const maxSize = step ? parseInt(step.dataset.maxSize || '10', 10) : 10;

    incoming.forEach(file => {
      const duplicate = fileRegistry[fieldName].some(existing => existing.name === file.name && existing.size === file.size);
      if (duplicate) return;
      if (fileRegistry[fieldName].length >= maxCount) return;
      fileRegistry[fieldName].push(file);
    });

    renderFileList(fieldName, maxSize, maxCount);

    const zone = document.getElementById('zone-' + fieldName);
    const err = document.getElementById('err-' + fieldName);
    if (zone) zone.classList.remove('error-zone');
    if (err) err.classList.remove('visible');
  }

  function removeFile(fieldName, index){
    if (!fileRegistry[fieldName]) return;
    fileRegistry[fieldName].splice(index, 1);

    const step = document.querySelector(`.step[data-name="${cssEscape(fieldName)}"]`);
    const maxCount = step ? parseInt(step.dataset.maxCount || '1', 10) : 1;
    const maxSize = step ? parseInt(step.dataset.maxSize || '10', 10) : 10;

    renderFileList(fieldName, maxSize, maxCount);
  }

  function renderFileList(fieldName, maxSize, maxCount){
    const list = document.getElementById('files-' + fieldName);
    if (!list) return;

    const files = fileRegistry[fieldName] || [];
    list.innerHTML = '';

    files.forEach((file, index) => {
      const over = file.size > maxSize * 1024 * 1024;
      const item = document.createElement('div');
      item.className = 'file-item';
      if (over) item.style.borderColor = '#dc2626';

      item.appendChild(buildFileIcon(file));

      const info = document.createElement('div');
      info.className = 'file-item-info';
      info.innerHTML = `
        <div class="file-item-name" title="${esc(file.name)}">${esc(file.name)}</div>
        <div class="file-item-size" style="${over ? 'color:#dc2626;font-weight:800' : ''}">
          ${formatBytes(file.size)} ${over ? ' - exceeds ' + maxSize + ' MB limit' : ''}
        </div>
      `;
      item.appendChild(info);

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'file-item-remove';
      remove.innerHTML = '<i class="fa fa-times"></i>';
      remove.onclick = function(){ removeFile(fieldName, index); };
      item.appendChild(remove);

      list.appendChild(item);
    });

    const zone = document.getElementById('zone-' + fieldName);
    if (zone) zone.style.display = files.length >= maxCount ? 'none' : '';
  }

  function buildFileIcon(file){
    if (file.type && file.type.startsWith('image/')) {
      const img = document.createElement('img');
      img.className = 'file-item-thumb';
      img.alt = file.name;

      const reader = new FileReader();
      reader.onload = function(e){ img.src = e.target.result; };
      reader.readAsDataURL(file);

      return img;
    }

    const icon = document.createElement('div');
    icon.className = 'file-item-icon';
    icon.innerHTML = `<i class="fa ${getFileIcon(file)}"></i>`;
    return icon;
  }

  function getFileIcon(file){
    const name = String(file.name || '');
    const ext = name.split('.').pop().toLowerCase();
    const mime = String(file.type || '');

    if (mime.startsWith('video/')) return 'fa-file-video';
    if (mime.startsWith('audio/')) return 'fa-file-audio';
    if (mime === 'application/pdf') return 'fa-file-pdf';
    if (['doc','docx','odt','rtf'].includes(ext)) return 'fa-file-word';
    if (['xls','xlsx','csv','ods'].includes(ext)) return 'fa-file-excel';
    if (['zip','rar','tar','gz'].includes(ext)) return 'fa-file-archive';
    return 'fa-file-alt';
  }

  function formatBytes(bytes){
    bytes = Number(bytes || 0);
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1024 / 1024).toFixed(2) + ' MB';
  }

  window.selectChoice = function(name, mode, label){
    const container = label.closest('.choice-grid');
    if (!container) return;

    const input = label.querySelector('input');

    if (mode === 'single') {
      container.querySelectorAll('.choice-opt').forEach(opt => {
        opt.classList.remove('selected');
        const optInput = opt.querySelector('input');
        if (optInput) optInput.checked = false;
        const check = opt.querySelector('.fa-check');
        const letter = opt.querySelector('.choice-letter');
        if (check) check.style.display = 'none';
        if (letter) letter.style.display = '';
      });

      if (input) input.checked = true;
      label.classList.add('selected');

      const check = label.querySelector('.fa-check');
      const letter = label.querySelector('.choice-letter');
      if (check) check.style.display = '';
      if (letter) letter.style.display = 'none';

    } else {
      if (input) input.checked = !input.checked;
      const selected = input ? input.checked : false;
      label.classList.toggle('selected', selected);

      const check = label.querySelector('.fa-check');
      const letter = label.querySelector('.choice-letter');
      if (check) check.style.display = selected ? '' : 'none';
      if (letter) letter.style.display = selected ? 'none' : '';
    }

    const err = document.getElementById('err-' + name);
    if (err) err.classList.remove('visible');

    if (mode === 'single' && currentStep < TOTAL - 1) {
      setTimeout(() => nextStep(), 300);
    }
  };

  window.selectYesNo = function(name, label){
    const container = document.getElementById('yesno-' + name);
    if (!container) return;

    container.querySelectorAll('.yesno-btn').forEach(btn => {
      btn.classList.remove('selected');
      const input = btn.querySelector('input');
      if (input) input.checked = false;
    });

    label.classList.add('selected');
    const input = label.querySelector('input');
    if (input) input.checked = true;

    const err = document.getElementById('err-' + name);
    if (err) err.classList.remove('visible');

    if (currentStep < TOTAL - 1) {
      setTimeout(() => nextStep(), 300);
    }
  };

  window.selectStar = function(name, value, label){
    const container = document.getElementById('stars-' + name);
    if (!container) return;

    container.querySelectorAll('.star-btn').forEach((star, idx) => {
      star.classList.toggle('lit', idx < value);
      const input = star.querySelector('input');
      if (input) input.checked = false;
    });

    const input = label.querySelector('input');
    if (input) input.checked = true;

    const err = document.getElementById('err-' + name);
    if (err) err.classList.remove('visible');
  };

  window.selectBubble = function(name, label){
    const container = document.getElementById('scale-' + name);
    if (!container) return;

    container.querySelectorAll('.scale-bubble').forEach(bubble => {
      bubble.classList.remove('selected');
      const input = bubble.querySelector('input');
      if (input) input.checked = false;
    });

    label.classList.add('selected');
    const input = label.querySelector('input');
    if (input) input.checked = true;

    const err = document.getElementById('err-' + name);
    if (err) err.classList.remove('visible');
  };

  document.addEventListener('keydown', function(e){
    if (!started) return;

    if (e.key === 'Enter' && !e.shiftKey) {
      const tag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
      if (tag === 'textarea') return;
      e.preventDefault();
      nextStep();
    }
  });

  document.addEventListener('input', function(e){
    const input = e.target;
    if (!input || !input.name || input.type === 'file') return;

    input.classList.remove('error');
    const cleanName = input.name.replace('[]', '');
    const err = document.getElementById('err-' + cleanName);
    if (err) err.classList.remove('visible');
    hideFormAlert();
  });

  function esc(value){
    return String(value ?? '').replace(/[&<>"']/g, function(m){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m];
    });
  }

  function cssEscape(value){
    if (window.CSS && typeof window.CSS.escape === 'function') return CSS.escape(value);
    return String(value).replace(/"/g, '\\"');
  }
})();
</script>
<?php endif; ?>

</body>
</html>