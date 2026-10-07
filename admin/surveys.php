<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

function survey_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function survey_base_url(): string
{
    return rtrim(SITE_URL, '/');
}

$surveys = $conn->query("
    SELECT
        s.*,
        COUNT(DISTINCT q.id)                                        AS questions_count,
        COUNT(DISTINCT r.id)                                        AS responses_count,
        SUM(CASE WHEN q.field_type = 'file_upload' THEN 1 ELSE 0 END) AS file_questions_count
    FROM surveys s
    LEFT JOIN survey_questions q ON q.survey_id = s.id
    LEFT JOIN survey_responses r ON r.survey_id = s.id
    GROUP BY s.id
    ORDER BY s.created_at DESC
");

$site_name = function_exists('get_setting')
    ? get_setting($conn, 'site_name', 'EdTech Fellowship')
    : 'EdTech Fellowship';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
<meta charset="UTF-8">
<title>Surveys - <?= survey_h($site_name) ?> Admin</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="assets/css/admin.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body class="surveys-page">

<?php include 'includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">

  <header class="admin-topbar">
    <div class="topbar-left">
      <div class="topbar-breadcrumb">
        <a href="index.php">Dashboard</a> &rsaquo; <strong>Surveys</strong>
      </div>
    </div>
    <div class="topbar-right">
      <a href="<?= survey_h(survey_base_url()) ?>" target="_blank" class="btn btn-secondary btn-sm">
        <i class="fa fa-eye"></i> View Site
      </a>
    </div>
  </header>

  <div class="admin-content">
    <?php show_flash('surveys'); ?>

    <div class="page-header">
      <div>
        <h1 class="page-title">Survey Manager</h1>
        <p class="page-subtitle">Create Typeform-style surveys and share external links with ventures.</p>
      </div>
      <button class="btn btn-primary" onclick="openSurveyModal()" type="button">
        <i class="fa fa-plus"></i> New Survey
      </button>
    </div>

    <div class="card">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Survey</th>
              <th class="text-center">Status</th>
              <th class="text-center">Questions</th>
              <th class="text-center">Responses</th>
              <th>External Link</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>

          <?php
          $has = false;
          if ($surveys):
            while ($s = $surveys->fetch_assoc()):
              $has = true;
              $link        = survey_base_url() . '/survey.php?t=' . urlencode($s['external_token']);
              $statusClass = 'status-' . survey_h($s['status']);
              $has_files   = (int)$s['file_questions_count'] > 0;
          ?>
            <tr>

              <!-- Survey name + description -->
              <td class="survey-link-cell">
                <div class="survey-title-wrap">
                  <strong><?= survey_h($s['title']) ?></strong>
                  <?php if (!empty($s['description'])): ?>
                    <small class="survey-description">
                      <?= survey_h(mb_strimwidth((string)$s['description'], 0, 90, '...')) ?>
                    </small>
                  <?php endif; ?>
                  <?php if ($has_files): ?>
                    <span class="file-badge">
                      <i class="fa fa-paperclip"></i>
                      File upload
                    </span>
                  <?php endif; ?>
                </div>
              </td>

              <!-- Status -->
              <td class="text-center">
                <span class="badge <?= $statusClass ?>">
                  <?= survey_h(ucfirst($s['status'])) ?>
                </span>
              </td>

              <!-- Questions count -->
              <td class="text-center">
                <div class="stat-cell">
                  <span class="stat-num"><?= (int)$s['questions_count'] ?></span>
                  <span class="stat-label">question<?= (int)$s['questions_count'] !== 1 ? 's' : '' ?></span>
                </div>
              </td>

              <!-- Responses count -->
              <td class="text-center">
                <div class="stat-cell">
                  <span class="stat-num"><?= (int)$s['responses_count'] ?></span>
                  <span class="stat-label">response<?= (int)$s['responses_count'] !== 1 ? 's' : '' ?></span>
                </div>
              </td>

              <!-- External link -->
              <td class="survey-link-cell">
                <div class="survey-link-actions">
                  <button class="copy-btn"
                          id="copy-<?= (int)$s['id'] ?>"
                          type="button"
                          onclick="copyLink('<?= survey_h($link) ?>', <?= (int)$s['id'] ?>)">
                    <i class="fa fa-copy"></i> Copy
                  </button>
                  <a href="<?= survey_h($link) ?>" target="_blank" class="btn btn-sm btn-secondary">
                    <i class="fa fa-external-link-alt"></i> Open
                  </a>
                </div>
                <div class="survey-link-box"><?= survey_h($link) ?></div>
              </td>

              <!-- Actions -->
              <td>
                <div class="tbl-actions">
                  <a href="survey-builder.php?id=<?= (int)$s['id'] ?>"
                     class="btn btn-sm btn-primary" title="Open Builder">
                    <i class="fa fa-tools"></i> Builder
                  </a>

                  <a href="survey-responses.php?id=<?= (int)$s['id'] ?>"
                     class="btn btn-sm btn-teal" title="View Responses">
                    <i class="fa fa-chart-bar"></i>
                  </a>

                  <button class="btn btn-sm btn-secondary" type="button" title="Edit Settings"
                          onclick='editSurvey(<?= json_encode($s, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'>
                    <i class="fa fa-cog"></i>
                  </button>

                  <form method="POST" action="includes/process-surveys.php" class="inline-action-form"
                        onsubmit="return confirm('Delete this survey and ALL its responses? This cannot be undone.')">
                    <input type="hidden" name="action" value="delete_survey">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <button class="btn btn-sm btn-danger" title="Delete Survey">
                      <i class="fa fa-trash"></i>
                    </button>
                  </form>
                </div>
              </td>

            </tr>
          <?php
            endwhile;
          endif;
          ?>

          <?php if (!$has): ?>
            <tr>
              <td colspan="6">
                <div class="survey-empty">
                  <div class="empty-icon"><i class="fa fa-poll"></i></div>
                  <h3>No surveys yet</h3>
                  <p>Create your first baseline, needs assessment, follow-up, or exit survey.</p>
                  <button class="btn btn-primary" onclick="openSurveyModal()" type="button">
                    <i class="fa fa-plus"></i> Create Survey
                  </button>
                </div>
              </td>
            </tr>
          <?php endif; ?>

          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>


<div class="modal-overlay" id="surveyModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h2 class="modal-title" id="surveyModalTitle">Create Survey</h2>
      <button class="modal-close" onclick="closeSurveyModal()" type="button" aria-label="Close modal"><i class="fa fa-times" aria-hidden="true"></i></button>
    </div>

    <form method="POST" action="includes/process-surveys.php">
      <input type="hidden" name="action" value="save_survey">
      <input type="hidden" name="id" id="survey_id">

      <div class="modal-body">
        <div class="form-grid form-grid-2">

          <!-- Title -->
          <div class="form-group full">
            <label>Survey Title <span class="req">*</span></label>
            <input type="text" name="title" id="survey_title" class="form-control"
                   required placeholder="e.g. Q1 Needs Assessment">
          </div>

          <!-- Description -->
          <div class="form-group full">
            <label>Description <em class="field-hint-inline">(shown on survey page)</em></label>
            <textarea name="description" id="survey_description" class="form-control" rows="2"
                      placeholder="Brief overview of what this survey covers..."></textarea>
          </div>

          <!-- Welcome text -->
          <div class="form-group full">
            <label>Welcome Text <em class="field-hint-inline">(shown on start screen)</em></label>
            <textarea name="welcome_text" id="survey_welcome" class="form-control" rows="3"
                      placeholder="Introduce the survey to respondents..."></textarea>
          </div>

          <!-- Thank you text -->
          <div class="form-group full">
            <label>Thank You Text <em class="field-hint-inline">(shown after submission)</em></label>
            <textarea name="thank_you_text" id="survey_thank_you" class="form-control" rows="2"
                      placeholder="Thank respondents and tell them what happens next..."></textarea>
          </div>

          <!-- Status -->
          <div class="form-group">
            <label>Status</label>
            <select name="status" id="survey_status" class="form-control">
              <option value="draft">Draft</option>
              <option value="published">Published</option>
              <option value="closed">Closed</option>
            </select>
          </div>

          <!-- Access rules -->
          <div class="form-group">
            <label>Access Rules</label>
            <label class="survey-check-row survey-check-row-first">
              <input type="checkbox" name="allow_multiple_responses" id="allow_multiple" value="1">
              <span>Allow multiple responses</span>
            </label>
            <label class="survey-check-row">
              <input type="checkbox" name="require_venture_login" id="require_login" value="1">
              <span>Require venture login</span>
            </label>
          </div>

          <!-- Opens / Closes -->
          <div class="form-group">
            <label>Opens At <em class="field-hint-inline">(optional)</em></label>
            <input type="datetime-local" name="starts_at" id="starts_at" class="form-control">
          </div>
          <div class="form-group">
            <label>Closes At <em class="field-hint-inline">(optional)</em></label>
            <input type="datetime-local" name="ends_at" id="ends_at" class="form-control">
          </div>

          <!-- Theme color (persisted via process-surveys.php save_survey action) -->
          <div class="form-group full">
            <label>Theme Colour</label>
            <div class="color-strip" id="colorStrip">
              <?php
              $swatches = [
                '#6c47ff','#2563eb','#059669','#dc2626',
                '#d97706','#db2777','#0891b2','#7c3aed',
                '#1d4ed8','#15803d','#b91c1c','#374151',
              ];
              foreach ($swatches as $index => $sw):
              ?>
                <button class="color-swatch-sm color-swatch-<?= (int)$index + 1 ?>"
                        type="button"
                        data-color="<?= survey_h($sw) ?>"
                        title="<?= survey_h($sw) ?>"
                        aria-label="Choose theme colour <?= survey_h($sw) ?>"
                        onclick="pickColor('<?= survey_h($sw) ?>', this)"></button>
              <?php endforeach; ?>
            </div>
            <div class="custom-color-row">
              <input type="color" id="customColorPicker" value="#6c47ff"
                     oninput="pickColor(this.value, null)">
              <input type="hidden" name="theme_color" id="theme_color_input" value="#6c47ff">
              <span id="colorHexLabel" class="color-hex-label">#6c47ff</span>
            </div>
          </div>

        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" onclick="closeSurveyModal()">Cancel</button>
        <button class="btn btn-primary" type="submit">
          <i class="fa fa-save"></i> Save Survey
        </button>
      </div>
    </form>
  </div>
</div>

<script>
/* -- Sidebar toggle ----------------------------------- */
document.getElementById('sidebarToggle')?.addEventListener('click', function () {
  document.getElementById('adminSidebar')?.classList.toggle('collapsed');
  document.getElementById('adminMain')?.classList.toggle('collapsed');
});

/* -- Modal open/close --------------------------------- */
function openSurveyModal() {
  document.getElementById('surveyModalTitle').textContent = 'Create Survey';
  document.getElementById('surveyModal').querySelector('form').reset();
  document.getElementById('survey_id').value = '';
  document.getElementById('survey_status').value = 'draft';
  pickColor('#6c47ff', document.querySelector('[data-color="#6c47ff"]'));
  document.getElementById('surveyModal').classList.add('open');
  document.body.classList.add('modal-open');
  window.setTimeout(function () {
    document.getElementById('survey_title')?.focus();
  }, 50);
}

function closeSurveyModal() {
  document.getElementById('surveyModal').classList.remove('open');
  document.body.classList.remove('modal-open');
}

/* Close on backdrop click */
document.getElementById('surveyModal').addEventListener('click', function (e) {
  if (e.target === this) closeSurveyModal();
});

document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape' && document.getElementById('surveyModal').classList.contains('open')) {
    closeSurveyModal();
  }
});

/* -- Edit survey -------------------------------------- */
function editSurvey(s) {
  openSurveyModal();
  document.getElementById('surveyModalTitle').textContent = 'Edit Survey Settings';
  document.getElementById('survey_id').value          = s.id || '';
  document.getElementById('survey_title').value       = s.title || '';
  document.getElementById('survey_description').value = s.description || '';
  document.getElementById('survey_welcome').value     = s.welcome_text || '';
  document.getElementById('survey_thank_you').value   = s.thank_you_text || '';
  document.getElementById('survey_status').value      = s.status || 'draft';
  document.getElementById('allow_multiple').checked   = String(s.allow_multiple_responses || '0') === '1';
  document.getElementById('require_login').checked    = String(s.require_venture_login || '0') === '1';
  document.getElementById('starts_at').value          = toLocalInput(s.starts_at);
  document.getElementById('ends_at').value            = toLocalInput(s.ends_at);

  const color = s.theme_color || '#6c47ff';
  const swatch = document.querySelector('[data-color="' + color + '"]');
  pickColor(color, swatch || null);
}

/* -- Date/time helper --------------------------------- */
function toLocalInput(v) {
  if (!v) return '';
  return String(v).replace(' ', 'T').slice(0, 16);
}

/* -- Colour picker ------------------------------------ */
function pickColor(hex, swatchEl) {
  document.getElementById('theme_color_input').value = hex;
  document.getElementById('customColorPicker').value = hex;
  document.getElementById('colorHexLabel').textContent = hex;
  document.querySelectorAll('.color-swatch-sm').forEach(s => s.classList.remove('active'));
  if (swatchEl) swatchEl.classList.add('active');
}

/* -- Copy link ---------------------------------------- */
function copyLink(text, surveyId) {
  navigator.clipboard.writeText(text).then(function () {
    const btn = document.getElementById('copy-' + surveyId);
    if (!btn) return;
    btn.classList.add('copied');
    btn.innerHTML = '<i class="fa fa-check"></i> Copied!';
    setTimeout(function () {
      btn.classList.remove('copied');
      btn.innerHTML = '<i class="fa fa-copy"></i> Copy';
    }, 2200);
  }).catch(function () {
    // Fallback for browsers without clipboard API
    const ta = document.createElement('textarea');
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
    alert('Link copied to clipboard.');
  });
}
</script>
</body>
</html>
