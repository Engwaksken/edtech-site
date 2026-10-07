<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

function sb_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$survey_id = (int)($_GET['id'] ?? 0);

if ($survey_id <= 0) {
    header('Location: surveys.php');
    exit;
}

$stmt = $conn->prepare("SELECT * FROM surveys WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $survey_id);
$stmt->execute();
$survey = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$survey) {
    header('Location: surveys.php');
    exit;
}

$questions = [];
$stmt = $conn->prepare("SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC, id ASC");
$stmt->bind_param('i', $survey_id);
$stmt->execute();
$qres = $stmt->get_result();

while ($q = $qres->fetch_assoc()) {
    $q['options'] = [];
    $opt = $conn->prepare("SELECT * FROM survey_question_options WHERE question_id = ? ORDER BY sort_order ASC, id ASC");
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


$conditional_parent_questions = [];
foreach ($questions as $cq) {
    $ctype = (string)($cq['field_type'] ?? '');
    if (in_array($ctype, ['yes_no', 'rating', 'linear_scale', 'single_choice', 'dropdown'], true)) {
        $conditional_parent_questions[] = $cq;
    }
}

/**
 * Read a nullable conditional field safely. This keeps the page working even
 * before you run the ALTER TABLE statements for conditional questions.
 */
function sb_qval(array $q, string $key, $default = '')
{
    return array_key_exists($key, $q) ? $q[$key] : $default;
}

$field_types = [
    'short_text'      => 'Short Text',
    'long_text'       => 'Long Text',
    'email'           => 'Email',
    'phone'           => 'Phone',
    'number'          => 'Number',
    'currency'        => 'Currency',
    'date'            => 'Date',
    'single_choice'   => 'Single Choice',
    'multiple_choice' => 'Multiple Choice',
    'dropdown'        => 'Dropdown',
    'yes_no'          => 'Yes / No',
    'rating'          => 'Rating',
    'linear_scale'    => 'Linear Scale',
    'file_upload'     => 'File Upload',
    'consent'         => 'Consent',
];

$field_icons = [
    'short_text'      => 'fa-font',
    'long_text'       => 'fa-align-left',
    'email'           => 'fa-envelope',
    'phone'           => 'fa-phone',
    'number'          => 'fa-hashtag',
    'currency'        => 'fa-dollar-sign',
    'date'            => 'fa-calendar',
    'single_choice'   => 'fa-dot-circle',
    'multiple_choice' => 'fa-check-square',
    'dropdown'        => 'fa-chevron-down',
    'yes_no'          => 'fa-toggle-on',
    'rating'          => 'fa-star',
    'linear_scale'    => 'fa-sliders-h',
    'file_upload'     => 'fa-cloud-upload-alt',
    'consent'         => 'fa-shield-alt',
];

// Accepted file type groups for the UI
$file_type_groups = [
    'images'      => 'Images (jpg, png, gif, webp)',
    'documents'   => 'Documents (pdf, doc, docx, txt)',
    'spreadsheets'=> 'Spreadsheets (xls, xlsx, csv)',
    'videos'      => 'Videos (mp4, mov, avi)',
    'audio'       => 'Audio (mp3, wav, ogg)',
    'any'         => 'Any file type',
];

$external_link = rtrim(SITE_URL, '/') . '/survey.php?t=' . urlencode($survey['external_token']);

$header_image = $survey['header_image'] ?? '';
$theme_color  = $survey['theme_color'] ?? '#fc7c10';
$header_style = $survey['header_style'] ?? 'color';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
<meta charset="UTF-8">
<title>Survey Builder - <?= sb_h($survey['title']) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="assets/css/admin.css">
<link rel="stylesheet" href="assets/css/survey.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

</head>
<body>

<div class="sb-layout">

  <!-- -- Top bar -------------------------------------------- -->
  <header class="sb-topbar">
    <div class="topbar-brand">
      <i class="fa fa-poll legacy-style-a805525bfd"></i>
      Survey Builder
      <span class="pill">BETA</span>
    </div>
    <nav class="topbar-breadcrumb">
      <a href="index.php">Dashboard</a>
      <span>/</span>
      <a href="surveys.php">Surveys</a>
      <span>/</span>
      <strong><?= sb_h($survey['title']) ?></strong>
    </nav>
    <div class="topbar-actions">
      <a href="<?= sb_h($external_link) ?>" target="_blank" class="btn btn-secondary btn-sm">
        <i class="fa fa-eye"></i> Preview
      </a>
      <button class="btn btn-primary btn-sm" type="button" onclick="openQuestionModal()">
        <i class="fa fa-plus"></i> Add Question
      </button>
    </div>
  </header>

  <!-- -- Left sidebar: question types ---------------------- -->
  <aside class="sb-sidebar">

    <div class="sidebar-section">
      <div class="sidebar-label">Text</div>
      <?php foreach (['short_text','long_text','email','phone','number','currency','date'] as $t): ?>
        <button class="type-btn" type="button" onclick="openQuestionModal('<?= $t ?>')">
          <span class="type-icon"><i class="fa <?= $field_icons[$t] ?>"></i></span>
          <?= $field_types[$t] ?>
        </button>
      <?php endforeach; ?>
    </div>

    <div class="sidebar-section">
      <div class="sidebar-label">Choice</div>
      <?php foreach (['single_choice','multiple_choice','dropdown','yes_no'] as $t): ?>
        <button class="type-btn" type="button" onclick="openQuestionModal('<?= $t ?>')">
          <span class="type-icon"><i class="fa <?= $field_icons[$t] ?>"></i></span>
          <?= $field_types[$t] ?>
        </button>
      <?php endforeach; ?>
    </div>

    <div class="sidebar-section">
      <div class="sidebar-label">Scale</div>
      <?php foreach (['rating','linear_scale'] as $t): ?>
        <button class="type-btn" type="button" onclick="openQuestionModal('<?= $t ?>')">
          <span class="type-icon"><i class="fa <?= $field_icons[$t] ?>"></i></span>
          <?= $field_types[$t] ?>
        </button>
      <?php endforeach; ?>
    </div>

    <div class="sidebar-section">
      <div class="sidebar-label">Upload &amp; Other</div>
      <!-- File Upload - highlighted -->
      <button class="type-btn legacy-style-456fefd66d" type="button" onclick="openQuestionModal('file_upload')"
             >
        <span class="type-icon legacy-style-9881221c92">
          <i class="fa fa-cloud-upload-alt"></i>
        </span>
        File Upload
      </button>
      <button class="type-btn" type="button" onclick="openQuestionModal('consent')">
        <span class="type-icon"><i class="fa fa-shield-alt"></i></span>
        Consent
      </button>
      <button class="type-btn legacy-style-a805525bfd" type="button" onclick="openBulkModal()">
        <span class="type-icon legacy-style-9881221c92"><i class="fa fa-layer-group"></i></span>
        Bulk Add
      </button>
    </div>

  </aside>

  <!-- -- Main canvas ---------------------------------------- -->
  <main class="sb-canvas">

    <div class="flash-wrap" id="flashWrap">
      <?php show_flash('surveys'); ?>
    </div>

    <div class="survey-card" id="surveyCard">

      <!-- Header banner -->
      <div class="survey-header <?= $header_image ? 'has-image' : '' ?>" id="surveyHeader"
           onclick="document.getElementById('rightHeaderTab').click(); scrollPanel()">
        <?php if ($header_image): ?>
          <img src="<?= sb_h($header_image) ?>" alt="header" id="headerImgEl">
        <?php else: ?>
          <div id="headerColorEl" class="legacy-style-98877c43b2"></div>
        <?php endif; ?>
        <div class="header-overlay">
          <i class="fa fa-image"></i>
          Click to change header
        </div>
      </div>

      <!-- Survey title/description -->
      <div class="survey-meta-block">
        <h1><?= sb_h($survey['title']) ?></h1>
        <?php if (!empty($survey['description'])): ?>
          <p><?= sb_h($survey['description']) ?></p>
        <?php endif; ?>
      </div>

      <!-- Questions -->
      <div class="order-save-strip" id="orderSaveStrip" style="<?= empty($questions) ? 'display:none' : '' ?>">
        <span><i class="fa fa-grip-vertical"></i> Drag questions to rearrange. Order saves automatically.</span>
        <strong id="orderStatus" class="order-status">Saved</strong>
      </div>

      <div class="questions-wrap" id="questionsWrap">

        <?php if (empty($questions)): ?>
          <div class="empty-canvas" id="emptyState">
            <div class="empty-icon"><i class="fa fa-question-circle"></i></div>
            <h3>No questions yet</h3>
            <p>Click a type on the left, or use bulk add to start building your survey.</p>
            <div class="empty-actions">
              <button class="btn btn-primary" type="button" onclick="openBulkModal()">
                <i class="fa fa-layer-group"></i> Bulk Add
              </button>
              <button class="btn btn-secondary" type="button" onclick="openQuestionModal()">
                <i class="fa fa-plus"></i> Add One
              </button>
            </div>
          </div>
        <?php else: ?>
          <?php foreach ($questions as $q): ?>
            <div class="q-card" data-question-id="<?= (int)$q['id'] ?>" draggable="true" onclick="selectCard(this)">
              <span class="q-order-badge">0</span>
              <div class="q-card-drag" title="Drag to rearrange"><i class="fa fa-grip-vertical"></i></div>
              <div class="q-card-inner">
                <?php if (!empty($q['section_title'])): ?>
                  <div class="legacy-style-1a87aa89a3"><?= sb_h($q['section_title']) ?></div>
                <?php endif; ?>
                <div class="q-type-badge">
                  <i class="fa <?= $field_icons[$q['field_type']] ?? 'fa-question' ?>"></i>
                  <?= sb_h($field_types[$q['field_type']] ?? $q['field_type']) ?>
                </div>
                <div class="q-text"><?= sb_h($q['question_text']) ?></div>
                <?php if (!empty($q['help_text'])): ?>
                  <div class="q-help"><?= sb_h($q['help_text']) ?></div>
                <?php endif; ?>

                <?php if (!empty($q['options'])): ?>
                  <div class="q-options">
                    <?php foreach ($q['options'] as $o): ?>
                      <span class="q-option-chip">
                        <i class="fa <?= in_array($q['field_type'],['single_choice','yes_no']) ? 'fa-circle' : 'fa-check-square' ?>"></i>
                        <?= sb_h($o['option_label']) ?>
                      </span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

                <?php if (in_array($q['field_type'], ['linear_scale','rating'])): ?>
                  <div class="q-options">
                    <span class="q-option-chip">
                      <i class="fa fa-arrow-left"></i>
                      <?= (int)($q['scale_min'] ?: 1) ?> - <?= sb_h($q['scale_min_label'] ?: 'Low') ?>
                    </span>
                    <span class="q-option-chip">
                      <i class="fa fa-arrow-right"></i>
                      <?= (int)($q['scale_max'] ?: 5) ?> - <?= sb_h($q['scale_max_label'] ?: 'High') ?>
                    </span>
                  </div>
                <?php endif; ?>

                <?php if ($q['field_type'] === 'file_upload'): ?>
                  <?php
                    $fu_types   = !empty($q['file_allowed_types'])  ? explode(',', $q['file_allowed_types'])  : ['any'];
                    $fu_max_mb  = !empty($q['file_max_size_mb'])    ? (int)$q['file_max_size_mb']             : 10;
                    $fu_max_num = !empty($q['file_max_count'])      ? (int)$q['file_max_count']               : 1;
                    $type_labels = [
                        'images'       => 'Images',
                        'documents'    => 'Documents',
                        'spreadsheets' => 'Spreadsheets',
                        'videos'       => 'Videos',
                        'audio'        => 'Audio',
                        'any'          => 'Any file',
                    ];
                  ?>
                  <div class="q-file-meta">
                    <?php foreach ($fu_types as $ft): ?>
                      <span class="q-file-chip">
                        <i class="fa fa-file"></i>
                        <?= sb_h($type_labels[trim($ft)] ?? trim($ft)) ?>
                      </span>
                    <?php endforeach; ?>
                    <span class="q-file-chip">
                      <i class="fa fa-weight"></i>
                      Max <?= $fu_max_mb ?> MB
                    </span>
                    <span class="q-file-chip">
                      <i class="fa fa-copy"></i>
                      Up to <?= $fu_max_num ?> file<?= $fu_max_num !== 1 ? 's' : '' ?>
                    </span>
                  </div>
                <?php endif; ?>

                <?php if ((int)sb_qval($q, 'condition_parent_id', 0) > 0): ?>
                  <?php
                    $cond_parent_text = 'Previous question';
                    foreach ($questions as $pq) {
                        if ((int)$pq['id'] === (int)sb_qval($q, 'condition_parent_id', 0)) {
                            $cond_parent_text = (string)$pq['question_text'];
                            break;
                        }
                    }
                    $cond_operator_label = [
                        'equals' => 'equals',
                        'not_equals' => 'does not equal',
                        'greater_equal' => 'is greater than or equal to',
                        'less_equal' => 'is less than or equal to',
                        'greater_than' => 'is greater than',
                        'less_than' => 'is less than',
                    ][(string)sb_qval($q, 'condition_operator', 'equals')] ?? 'equals';
                  ?>
                  <div class="condition-preview">
                    <i class="fa fa-code-branch"></i>
                    Show if "<?= sb_h(mb_strimwidth($cond_parent_text, 0, 55, '...')) ?>"
                    <?= sb_h($cond_operator_label) ?>
                    "<?= sb_h(sb_qval($q, 'condition_value', '')) ?>"
                  </div>
                <?php endif; ?>

                <div class="q-footer">
                  <div class="q-meta-tags">
                    <span class="q-meta-tag order-tag">Order: <b class="q-order-text"><?= (int)$q['sort_order'] ?></b></span>
                    <?php if ((int)$q['is_required']): ?><span class="q-meta-tag required">Required</span><?php endif; ?>
                    <?php if (!(int)$q['status']): ?><span class="q-meta-tag hidden">Hidden</span><?php endif; ?>
                    <?php if ((int)sb_qval($q, 'condition_parent_id', 0) > 0): ?>
                      <span class="q-meta-tag legacy-style-62de896f04">
                        <i class="fa fa-code-branch"></i>
                        Conditional
                      </span>
                    <?php endif; ?>
                  </div>
                  <div class="q-actions">
                    <button class="btn btn-ghost btn-sm btn-icon" type="button"
                            onclick='event.stopPropagation(); editQuestion(<?= json_encode($q, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'
                            title="Edit">
                      <i class="fa fa-pen"></i>
                    </button>
                    <form method="POST" action="includes/process-surveys.php"
                          onsubmit="event.stopPropagation(); return confirm('Delete this question?')" class="legacy-style-cccfa4560d">
                      <input type="hidden" name="action" value="delete_question">
                      <input type="hidden" name="survey_id" value="<?= (int)$survey_id ?>">
                      <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
                      <button class="btn btn-danger btn-sm btn-icon" title="Delete"><i class="fa fa-trash"></i></button>
                    </form>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <div class="add-question-row" onclick="openQuestionModal()">
          <i class="fa fa-plus"></i> Add a question
        </div>

      </div><!-- /questions-wrap -->
    </div><!-- /survey-card -->

  </main>

  <!-- -- Right panel ---------------------------------------- -->
  <aside class="sb-panel" id="rightPanel">

    <!-- Header customization -->
    <div class="panel-section">
      <div class="panel-section-title">
        <i class="fa fa-paint-brush legacy-style-823466db3d"></i>
        Header Style
      </div>

      <div class="header-style-tabs">
        <button class="header-style-tab <?= $header_style === 'color' ? 'active' : '' ?>"
                id="rightHeaderTab"
                onclick="setHeaderStyle('color')" type="button">
          <i class="fa fa-palette"></i> Color
        </button>
        <button class="header-style-tab <?= $header_style === 'image' ? 'active' : '' ?>"
                onclick="setHeaderStyle('image')" type="button">
          <i class="fa fa-image"></i> Image
        </button>
      </div>

      <!-- Color picker -->
      <div id="colorSection" style="<?= $header_style !== 'color' ? 'display:none' : '' ?>">
        <div class="color-grid">
          <?php
          $swatches = ['#6c47ff','#2563eb','#059669','#dc2626','#d97706','#db2777','#0891b2','#7c3aed','#1d4ed8','#15803d','#b91c1c','#374151'];
          foreach ($swatches as $sw): ?>
            <div class="color-swatch <?= $theme_color === $sw ? 'active' : '' ?>"
                 style="background:<?= sb_h($sw) ?>"
                 onclick="applyColor('<?= sb_h($sw) ?>', this)"
                 title="<?= sb_h($sw) ?>"></div>
          <?php endforeach; ?>
        </div>
        <div class="color-custom-row">
          <label for="customColorInput">Custom</label>
          <input type="color" id="customColorInput" value="<?= sb_h($theme_color) ?>" oninput="applyColor(this.value, null)">
          <span id="colorHexDisplay" class="legacy-style-3fcc41039d"><?= sb_h($theme_color) ?></span>
        </div>
        <form method="POST" action="includes/process-surveys.php" id="colorSaveForm" class="legacy-style-d2c171b18b">
          <input type="hidden" name="action" value="save_survey_appearance">
          <input type="hidden" name="survey_id" value="<?= (int)$survey_id ?>">
          <input type="hidden" name="theme_color" id="colorFormInput" value="<?= sb_h($theme_color) ?>">
          <input type="hidden" name="header_style" value="color">
          <button class="btn btn-primary btn-sm legacy-style-0466783d98" type="submit">
            <i class="fa fa-save"></i> Save Color
          </button>
        </form>
      </div>

      <!-- Image upload -->
      <div id="imageSection" style="<?= $header_style !== 'image' ? 'display:none' : '' ?>">

        <?php if ($header_image): ?>
          <div class="upload-preview" id="uploadPreview">
            <img src="<?= sb_h($header_image) ?>" alt="header" id="previewImg">
            <button class="remove-img" type="button" onclick="removeHeaderImage()">
              <i class="fa fa-times"></i>
            </button>
          </div>
        <?php else: ?>
          <div id="uploadPreview" class="legacy-style-6b99de8b69">
            <div class="upload-preview">
              <img src="" alt="preview" id="previewImg">
              <button class="remove-img" type="button" onclick="removeHeaderImage()">
                <i class="fa fa-times"></i>
              </button>
            </div>
          </div>
        <?php endif; ?>

        <form method="POST" action="includes/process-surveys.php"
              enctype="multipart/form-data" id="headerImageForm"
              <?= $header_image ? 'style="display:none"' : '' ?>>
          <input type="hidden" name="action" value="save_survey_header_image">
          <input type="hidden" name="survey_id" value="<?= (int)$survey_id ?>">
          <div class="upload-zone" id="uploadZone">
            <input type="file" name="header_image" id="headerImageFile" accept="image/*" onchange="previewHeaderImage(this)">
            <i class="fa fa-cloud-upload-alt"></i>
            <strong>Upload header image</strong>
            JPG, PNG, WebP . Max 5 MB
          </div>
          <button class="btn btn-primary btn-sm legacy-style-c6bd5ad812" type="submit" id="uploadSubmitBtn"
                 >
            <i class="fa fa-upload"></i> Upload &amp; Save
          </button>
        </form>

        <?php if ($header_image): ?>
          <form method="POST" action="includes/process-surveys.php" id="removeImageForm">
            <input type="hidden" name="action" value="remove_survey_header_image">
            <input type="hidden" name="survey_id" value="<?= (int)$survey_id ?>">
          </form>
        <?php endif; ?>

      </div>
    </div>

    <!-- Survey info -->
    <div class="panel-section">
      <div class="panel-section-title">
        <i class="fa fa-info-circle legacy-style-823466db3d"></i>
        Survey Info
      </div>
      <div class="info-card">
        <div class="info-row">
          <span class="label">Status</span>
          <span class="val badge badge-<?= sb_h($survey['status']) ?>"><?= sb_h(ucfirst($survey['status'])) ?></span>
        </div>
        <div class="info-row">
          <span class="label">Questions</span>
          <span class="val"><?= count($questions) ?></span>
        </div>
        <div class="info-row">
          <span class="label">Multi-response</span>
          <span class="val"><?= (int)$survey['allow_multiple_responses'] ? 'Yes' : 'No' ?></span>
        </div>
        <div class="info-row">
          <span class="label">Login required</span>
          <span class="val"><?= (int)$survey['require_venture_login'] ? 'Yes' : 'No' ?></span>
        </div>
      </div>
    </div>

    <!-- Share link -->
    <div class="panel-section">
      <div class="panel-section-title">
        <i class="fa fa-link legacy-style-823466db3d"></i>
        Share Link
      </div>
      <div class="external-link-box"><?= sb_h($external_link) ?></div>
      <div class="legacy-style-a76d597a07">
        <button class="btn btn-secondary btn-sm legacy-style-97445a8d93" type="button"
                onclick="copyText('<?= sb_h($external_link) ?>')">
          <i class="fa fa-copy"></i> Copy
        </button>
        <a class="btn btn-primary btn-sm legacy-style-97445a8d93" href="<?= sb_h($external_link) ?>" target="_blank">
          <i class="fa fa-external-link-alt"></i> Open
        </a>
      </div>
    </div>

  </aside>

</div><!-- /sb-layout -->

<!-- --------------------------------------------------------
     MODAL: Add / Edit Single Question
--------------------------------------------------------- -->
<div class="modal-overlay" id="questionModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h2 class="modal-title" id="questionModalTitle">Add Question</h2>
      <button class="modal-close" type="button" onclick="closeQuestionModal()">x</button>
    </div>
    <form method="POST" action="includes/process-surveys.php">
      <input type="hidden" name="action" value="save_question">
      <input type="hidden" name="survey_id" value="<?= (int)$survey_id ?>">
      <input type="hidden" name="id" id="q_id">
      <div class="modal-body">
        <div class="form-grid form-grid-2">

          <div class="form-group">
            <label>Section Title <em class="legacy-style-f065b45a89">(optional)</em></label>
            <input type="text" name="section_title" id="q_section" class="form-control" placeholder="e.g. Personal Info">
          </div>
          <div class="form-group">
            <label>Sort Order</label>
            <input type="number" name="sort_order" id="q_sort" class="form-control" value="0">
          </div>
          <div class="form-group full">
            <label>Question <span class="req">*</span></label>
            <textarea name="question_text" id="q_text" class="form-control" rows="3"
                      required placeholder="e.g. What is your name?"></textarea>
          </div>
          <div class="form-group full">
            <label>Help Text <em class="legacy-style-f065b45a89">(optional)</em></label>
            <textarea name="help_text" id="q_help" class="form-control" rows="2"
                      placeholder="Additional instructions for respondents..."></textarea>
          </div>
          <div class="form-group">
            <label>Field Type</label>
            <select name="field_type" id="q_type" class="form-control"
                    onchange="toggleQuestionConfig('q')">
              <?php foreach ($field_types as $key => $label): ?>
                <option value="<?= sb_h($key) ?>"><?= sb_h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Status</label>
            <select name="status" id="q_status" class="form-control">
              <option value="1">Active</option>
              <option value="0">Hidden</option>
            </select>
          </div>
          <div class="form-group full">
            <label class="toggle-row legacy-style-3b6a3a65d8">
              <input type="checkbox" name="is_required" id="q_required" value="1">
              <span>Mark as required question</span>
            </label>
          </div>

          <!-- Conditional logic -->
          <div class="form-group full">
            <label class="toggle-row legacy-style-3b6a3a65d8">
              <input type="checkbox" name="has_condition" id="q_has_condition" value="1"
                     onchange="toggleConditionConfig('q')">
              <span>Show this question only after a specific answer</span>
            </label>
            <div class="condition-parent-note">
              Example: Ask "Are you a refugee?" as Yes/No. Then set the next question to show only when the answer is Yes.
              For scale/rating questions, use values 1-5 with equals, greater than, or less than rules.
            </div>
          </div>

          <div class="form-group condition-config-q full legacy-style-6b99de8b69">
            <div class="condition-config-wrap">
              <p class="condition-help">
                <i class="fa fa-code-branch"></i>
                Conditional questions are hidden until the respondent gives the matching answer.
                If the answer does not match, the system skips this question and continues to the next one.
              </p>

              <div class="condition-inline-grid">
                <div>
                  <label>Parent Question</label>
                  <select name="condition_parent_id" id="q_condition_parent_id" class="form-control"
                          onchange="refreshConditionValueOptions('q')">
                    <option value="0">Select parent question</option>
                    <?php foreach ($conditional_parent_questions as $parentQ): ?>
                      <option value="<?= (int)$parentQ['id'] ?>"
                              data-type="<?= sb_h($parentQ['field_type']) ?>"
                              data-options="<?= sb_h(json_encode(array_map(fn($o) => $o['option_label'], $parentQ['options'] ?? []), JSON_UNESCAPED_UNICODE)) ?>">
                        #<?= (int)$parentQ['sort_order'] ?> - <?= sb_h(mb_strimwidth($parentQ['question_text'], 0, 80, '...')) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div>
                  <label>Condition</label>
                  <select name="condition_operator" id="q_condition_operator" class="form-control">
                    <option value="equals">Equals</option>
                    <option value="not_equals">Does not equal</option>
                    <option value="greater_equal">Greater than / equal</option>
                    <option value="less_equal">Less than / equal</option>
                    <option value="greater_than">Greater than</option>
                    <option value="less_than">Less than</option>
                  </select>
                </div>

                <div>
                  <label>Answer Value</label>
                  <select name="condition_value_select" id="q_condition_value_select" class="form-control"
                          onchange="syncConditionValue('q')">
                    <option value="">Select answer</option>
                  </select>
                  <input type="text" name="condition_value" id="q_condition_value" class="form-control legacy-style-fe7b4979fe"
                         placeholder="Yes, No, 1, 2, 3, 4, 5">
                </div>
              </div>
            </div>
          </div>

          <!-- Options (single/multiple/dropdown) -->
          <div class="form-group option-config-q full legacy-style-6b99de8b69">
            <label>Options <small class="legacy-style-f065b45a89">- one per line</small></label>
            <textarea name="options_text" id="q_options" class="form-control" rows="5"
                      placeholder="Yes&#10;No&#10;Maybe&#10;Not sure"></textarea>
          </div>

          <!-- Scale config (rating / linear_scale) -->
          <div class="form-group scale-config-q legacy-style-6b99de8b69">
            <label>Scale Min</label>
            <input type="number" name="scale_min" id="q_scale_min" class="form-control" value="1">
          </div>
          <div class="form-group scale-config-q legacy-style-6b99de8b69">
            <label>Scale Max</label>
            <input type="number" name="scale_max" id="q_scale_max" class="form-control" value="5">
          </div>
          <div class="form-group scale-config-q legacy-style-6b99de8b69">
            <label>Min Label</label>
            <input type="text" name="scale_min_label" id="q_scale_min_label" class="form-control" placeholder="e.g. Not at all">
          </div>
          <div class="form-group scale-config-q legacy-style-6b99de8b69">
            <label>Max Label</label>
            <input type="text" name="scale_max_label" id="q_scale_max_label" class="form-control" placeholder="e.g. Extremely">
          </div>

          <!-- -- File upload config ----------------------- -->
          <div class="form-group file-config-q full legacy-style-6b99de8b69">
            <label class="legacy-style-fdf33f2304">File Upload Settings</label>
            <div class="file-config-wrap">

              <div>
                <div class="legacy-style-439bba4e63">
                  Accepted File Types
                </div>
                <div class="file-type-grid">
                  <?php foreach ($file_type_groups as $ftKey => $ftLabel): ?>
                    <label class="file-type-chip">
                      <input type="checkbox"
                             name="file_allowed_types[]"
                             id="q_ft_<?= sb_h($ftKey) ?>"
                             value="<?= sb_h($ftKey) ?>">
                      <?= sb_h($ftLabel) ?>
                    </label>
                  <?php endforeach; ?>
                </div>
                <p class="legacy-style-ce262f0abf">
                  Select "Any file type" to allow all formats, or pick specific groups.
                </p>
              </div>

              <div class="file-config-row">
                <label for="q_file_max_size">Max file size (MB)</label>
                <input type="number" name="file_max_size_mb" id="q_file_max_size"
                       class="form-control" value="10" min="1" max="500">
              </div>

              <div class="file-config-row">
                <label for="q_file_max_count">Max number of files</label>
                <input type="number" name="file_max_count" id="q_file_max_count"
                       class="form-control" value="1" min="1" max="20">
              </div>

            </div>
          </div><!-- /file-config-q -->

        </div>
      </div><!-- /modal-body -->
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" onclick="closeQuestionModal()">Cancel</button>
        <button class="btn btn-primary" type="submit"><i class="fa fa-save"></i> Save Question</button>
      </div>
    </form>
  </div>
</div>

<!-- --------------------------------------------------------
     MODAL: Bulk Add Questions
--------------------------------------------------------- -->
<div class="modal-overlay" id="bulkModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h2 class="modal-title">Bulk Add Questions</h2>
      <button class="modal-close" type="button" onclick="closeBulkModal()">x</button>
    </div>
    <form method="POST" action="includes/process-surveys.php" id="bulkQuestionForm">
      <input type="hidden" name="action" value="save_multiple_questions">
      <input type="hidden" name="survey_id" value="<?= (int)$survey_id ?>">
      <div class="modal-body">
        <p class="legacy-style-9fd0bb77c0">
          Add several questions at once. Pick a type to get started:
        </p>
        <div class="quick-type-strip">
          <button type="button" class="quick-type-chip" onclick="addQuestionBlock('yes_no')">
            <i class="fa fa-toggle-on"></i> Yes/No
          </button>
          <button type="button" class="quick-type-chip" onclick="addQuestionBlock('number')">
            <i class="fa fa-hashtag"></i> Number
          </button>
          <button type="button" class="quick-type-chip" onclick="addQuestionBlock('multiple_choice')">
            <i class="fa fa-check-square"></i> Multiple Choice
          </button>
          <button type="button" class="quick-type-chip" onclick="addQuestionBlock('linear_scale')">
            <i class="fa fa-sliders-h"></i> Scale
          </button>
          <button type="button" class="quick-type-chip" onclick="addQuestionBlock('short_text')">
            <i class="fa fa-font"></i> Short Text
          </button>
          <button type="button" class="quick-type-chip" onclick="addQuestionBlock('rating')">
            <i class="fa fa-star"></i> Rating
          </button>
          <button type="button" class="quick-type-chip legacy-style-da5a660958" onclick="addQuestionBlock('file_upload')"
                 >
            <i class="fa fa-cloud-upload-alt"></i> File Upload
          </button>
        </div>
        <div id="bulkQuestionsWrap"></div>
        <button type="button" class="add-question-row legacy-style-0466783d98" onclick="addQuestionBlock()">
          <i class="fa fa-plus"></i> Add another question
        </button>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" onclick="closeBulkModal()">Cancel</button>
        <button class="btn btn-primary" type="submit"><i class="fa fa-save"></i> Save All Questions</button>
      </div>
    </form>
  </div>
</div>

<script>
/* -- Constants ------------------------------------------- */
let bulkIndex = 0;
let draggedQuestionCard = null;
let orderSaveTimer = null;
const SURVEY_ID = <?= (int)$survey_id ?>;
const PROCESS_URL = 'includes/process-surveys.php';
const fieldTypeOptions = <?= json_encode($field_types) ?>;
const fileTypeGroups   = <?= json_encode($file_type_groups) ?>;
const conditionalParents = <?= json_encode(array_map(function($q){ return ['id'=>(int)$q['id'], 'text'=>(string)$q['question_text'], 'type'=>(string)$q['field_type'], 'sort_order'=>(int)$q['sort_order'], 'options'=>array_map(fn($o)=>(string)$o['option_label'], $q['options'] ?? [])]; }, $conditional_parent_questions), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;

/* -- Single question modal -------------------------------- */
function openQuestionModal(defaultType = 'short_text') {
  const modal = document.getElementById('questionModal');
  document.getElementById('questionModalTitle').textContent = 'Add Question';
  modal.querySelector('form').reset();
  document.getElementById('q_id').value = '';
  document.getElementById('q_status').value = '1';
  document.getElementById('q_scale_min').value = '1';
  document.getElementById('q_scale_max').value = '5';
  document.getElementById('q_file_max_size').value = '10';
  document.getElementById('q_file_max_count').value = '1';
  document.getElementById('q_has_condition').checked = false;
  document.getElementById('q_condition_parent_id').value = '0';
  document.getElementById('q_condition_operator').value = 'equals';
  document.getElementById('q_condition_value').value = '';
  refreshConditionValueOptions('q');
  toggleConditionConfig('q');
  document.getElementById('q_type').value = defaultType;
  toggleQuestionConfig('q');
  modal.classList.add('open');
}

function closeQuestionModal() {
  document.getElementById('questionModal').classList.remove('open');
}

function editQuestion(q) {
  openQuestionModal();
  document.getElementById('questionModalTitle').textContent = 'Edit Question';
  document.getElementById('q_id').value            = q.id || '';
  document.getElementById('q_section').value       = q.section_title || '';
  document.getElementById('q_sort').value          = q.sort_order || 0;
  document.getElementById('q_text').value          = q.question_text || '';
  document.getElementById('q_help').value          = q.help_text || '';
  document.getElementById('q_type').value          = q.field_type || 'short_text';
  document.getElementById('q_status').value        = q.status || 1;
  document.getElementById('q_required').checked   = String(q.is_required || '0') === '1';
  document.getElementById('q_scale_min').value     = q.scale_min || 1;
  document.getElementById('q_scale_max').value     = q.scale_max || 5;
  document.getElementById('q_scale_min_label').value = q.scale_min_label || '';
  document.getElementById('q_scale_max_label').value = q.scale_max_label || '';

  const hasCondition = parseInt(q.condition_parent_id || 0, 10) > 0;
  document.getElementById('q_has_condition').checked = hasCondition;
  document.getElementById('q_condition_parent_id').value = q.condition_parent_id || '0';
  document.getElementById('q_condition_operator').value = q.condition_operator || 'equals';
  refreshConditionValueOptions('q');
  document.getElementById('q_condition_value').value = q.condition_value || '';
  const condSelect = document.getElementById('q_condition_value_select');
  if (condSelect) condSelect.value = q.condition_value || '';
  toggleConditionConfig('q');

  if (Array.isArray(q.options)) {
    document.getElementById('q_options').value = q.options.map(o => o.option_label).join('\n');
  }

  // Restore file upload settings
  document.getElementById('q_file_max_size').value  = q.file_max_size_mb  || 10;
  document.getElementById('q_file_max_count').value = q.file_max_count    || 1;

  // Restore checked file type checkboxes
  document.querySelectorAll('input[name="file_allowed_types[]"]').forEach(cb => {
    cb.checked = false;
  });
  if (q.file_allowed_types) {
    const allowed = q.file_allowed_types.split(',').map(s => s.trim());
    allowed.forEach(val => {
      const cb = document.getElementById('q_ft_' + val);
      if (cb) cb.checked = true;
    });
  }

  toggleQuestionConfig('q');
}

function toggleQuestionConfig(prefix) {
  const type = document.getElementById(prefix + '_type').value;
  const optionTypes = ['single_choice', 'multiple_choice', 'dropdown'];

  document.querySelectorAll('.option-config-' + prefix).forEach(el => {
    el.style.display = optionTypes.includes(type) ? '' : 'none';
  });
  document.querySelectorAll('.scale-config-' + prefix).forEach(el => {
    el.style.display = (type === 'linear_scale' || type === 'rating') ? '' : 'none';
  });
  document.querySelectorAll('.file-config-' + prefix).forEach(el => {
    el.style.display = type === 'file_upload' ? '' : 'none';
  });
}


/* -- Conditional question settings ------------------------ */
function toggleConditionConfig(prefix) {
  const cb = document.getElementById(prefix + '_has_condition');
  document.querySelectorAll('.condition-config-' + prefix).forEach(el => {
    el.style.display = cb && cb.checked ? '' : 'none';
  });

  if (!cb || !cb.checked) {
    const parent = document.getElementById(prefix + '_condition_parent_id');
    const value  = document.getElementById(prefix + '_condition_value');
    if (parent) parent.value = '0';
    if (value) value.value = '';
  }
}

function refreshConditionValueOptions(prefix) {
  const parentSelect = document.getElementById(prefix + '_condition_parent_id');
  const valueSelect  = document.getElementById(prefix + '_condition_value_select');
  const valueInput   = document.getElementById(prefix + '_condition_value');
  const opSelect     = document.getElementById(prefix + '_condition_operator');

  if (!parentSelect || !valueSelect || !valueInput) return;

  const selected = parentSelect.options[parentSelect.selectedIndex];
  const type = selected ? selected.dataset.type : '';
  let options = [];

  try {
    options = selected && selected.dataset.options ? JSON.parse(selected.dataset.options) : [];
  } catch (e) {
    options = [];
  }

  valueSelect.innerHTML = '<option value="">Select answer</option>';

  if (type === 'yes_no') {
    options = ['Yes', 'No'];
  }

  if (type === 'rating' || type === 'linear_scale') {
    options = ['1', '2', '3', '4', '5'];
  }

  if (Array.isArray(options) && options.length) {
    options.forEach(opt => {
      if (String(opt).trim() === '') return;
      const option = document.createElement('option');
      option.value = String(opt);
      option.textContent = String(opt);
      valueSelect.appendChild(option);
    });
    valueSelect.style.display = '';
  } else {
    valueSelect.style.display = 'none';
  }

  if (type === 'yes_no') {
    if (opSelect) opSelect.value = 'equals';
    valueInput.placeholder = 'Yes or No';
  } else if (type === 'rating' || type === 'linear_scale') {
    valueInput.placeholder = '1, 2, 3, 4, or 5';
  } else {
    valueInput.placeholder = 'Matching answer value';
  }
}

function syncConditionValue(prefix) {
  const select = document.getElementById(prefix + '_condition_value_select');
  const input  = document.getElementById(prefix + '_condition_value');
  if (select && input && select.value !== '') input.value = select.value;
}

/* -- Bulk modal ------------------------------------------- */
function openBulkModal() {
  document.getElementById('bulkQuestionsWrap').innerHTML = '';
  bulkIndex = 0;
  addQuestionBlock();
  document.getElementById('bulkModal').classList.add('open');
}

function closeBulkModal() {
  document.getElementById('bulkModal').classList.remove('open');
}

function addQuestionBlock(defaultType = 'short_text') {
  bulkIndex++;
  const wrap   = document.getElementById('bulkQuestionsWrap');
  const prefix = 'bq_' + bulkIndex;
  const n      = bulkIndex;

  const typeOptions = Object.entries(fieldTypeOptions)
    .map(([k, l]) => `<option value="${escH(k)}"${k === defaultType ? ' selected' : ''}>${escH(l)}</option>`)
    .join('');

  // Build file-type checkboxes for bulk
  const ftCheckboxes = Object.entries(fileTypeGroups)
    .map(([k, l]) => `
      <label class="file-type-chip">
        <input type="checkbox" name="questions[${n}][file_allowed_types][]" value="${escH(k)}">
        ${escH(l)}
      </label>`)
    .join('');

  const isFileUpload = defaultType === 'file_upload';

  const block = document.createElement('div');
  block.className = 'bulk-block';
  block.innerHTML = `
    <div class="bulk-block-head">
      <span class="bulk-block-num">Question ${n}</span>
      <button type="button" class="btn btn-danger btn-sm btn-icon" onclick="removeQuestionBlock(this)">
        <i class="fa fa-times"></i>
      </button>
    </div>
    <div class="form-grid form-grid-2">
      <div class="form-group">
        <label>Section Title <em class="legacy-style-7e3a94476f">(optional)</em></label>
        <input type="text" name="questions[${n}][section_title]" class="form-control">
      </div>
      <div class="form-group">
        <label>Sort Order</label>
        <input type="number" name="questions[${n}][sort_order]" class="form-control" value="${n}">
      </div>
      <div class="form-group full">
        <label>Question <span class="req">*</span></label>
        <textarea name="questions[${n}][question_text]" class="form-control" rows="2" required
                  placeholder="Type your question here..."></textarea>
      </div>
      <div class="form-group full">
        <label>Help Text <em class="legacy-style-7e3a94476f">(optional)</em></label>
        <textarea name="questions[${n}][help_text]" class="form-control" rows="1"></textarea>
      </div>
      <div class="form-group">
        <label>Field Type</label>
        <select name="questions[${n}][field_type]" id="${prefix}_type" class="form-control"
                onchange="toggleQuestionConfig('${prefix}')">${typeOptions}</select>
      </div>
      <div class="form-group">
        <label>Status</label>
        <select name="questions[${n}][status]" class="form-control">
          <option value="1">Active</option>
          <option value="0">Hidden</option>
        </select>
      </div>
      <div class="form-group full">
        <label class="toggle-row legacy-style-3b6a3a65d8">
          <input type="checkbox" name="questions[${n}][is_required]" value="1">
          <span>Required question</span>
        </label>
      </div>

      <!-- Options -->
      <div class="form-group option-config-${prefix} full legacy-style-6b99de8b69">
        <label>Options - one per line</label>
        <textarea name="questions[${n}][options_text]" class="form-control" rows="4"
                  placeholder="Option 1&#10;Option 2&#10;Option 3"></textarea>
      </div>

      <!-- Scale -->
      <div class="form-group scale-config-${prefix} legacy-style-6b99de8b69">
        <label>Scale Min</label>
        <input type="number" name="questions[${n}][scale_min]" class="form-control" value="1">
      </div>
      <div class="form-group scale-config-${prefix} legacy-style-6b99de8b69">
        <label>Scale Max</label>
        <input type="number" name="questions[${n}][scale_max]" class="form-control" value="5">
      </div>
      <div class="form-group scale-config-${prefix} legacy-style-6b99de8b69">
        <label>Min Label</label>
        <input type="text" name="questions[${n}][scale_min_label]" class="form-control" placeholder="Not at all">
      </div>
      <div class="form-group scale-config-${prefix} legacy-style-6b99de8b69">
        <label>Max Label</label>
        <input type="text" name="questions[${n}][scale_max_label]" class="form-control" placeholder="Extremely">
      </div>

      <!-- File upload config -->
      <div class="form-group file-config-${prefix} full legacy-style-b179fcafbd">
        <label class="legacy-style-fdf33f2304">File Upload Settings</label>
        <div class="file-config-wrap">
          <div>
            <div class="legacy-style-439bba4e63">Accepted File Types</div>
            <div class="file-type-grid">${ftCheckboxes}</div>
            <p class="legacy-style-ce262f0abf">
              Select "Any file type" to allow all formats.
            </p>
          </div>
          <div class="file-config-row">
            <label>Max file size (MB)</label>
            <input type="number" name="questions[${n}][file_max_size_mb]" class="form-control" value="10" min="1" max="500">
          </div>
          <div class="file-config-row">
            <label>Max number of files</label>
            <input type="number" name="questions[${n}][file_max_count]" class="form-control" value="1" min="1" max="20">
          </div>
        </div>
      </div>

    </div>`;

  wrap.appendChild(block);
  toggleQuestionConfig(prefix);
}

function removeQuestionBlock(btn) {
  const blocks = document.querySelectorAll('.bulk-block');
  if (blocks.length <= 1) { alert('At least one question is required.'); return; }
  btn.closest('.bulk-block').remove();
}

/* -- Header customization --------------------------------- */
function setHeaderStyle(style) {
  document.getElementById('colorSection').style.display = style === 'color' ? '' : 'none';
  document.getElementById('imageSection').style.display = style === 'image' ? '' : 'none';
  document.querySelectorAll('.header-style-tab').forEach((t, i) => {
    t.classList.toggle('active', (i === 0 && style === 'color') || (i === 1 && style === 'image'));
  });
}

function applyColor(hex, swatchEl) {
  document.documentElement.style.setProperty('--accent', hex);
  document.documentElement.style.setProperty('--accent-light', hex + '1e');
  document.getElementById('colorFormInput').value  = hex;
  document.getElementById('customColorInput').value = hex;
  document.getElementById('colorHexDisplay').textContent = hex;
  const colorEl = document.getElementById('headerColorEl');
  if (colorEl) colorEl.style.background = hex;
  document.querySelectorAll('.color-swatch').forEach(s => s.classList.remove('active'));
  if (swatchEl) swatchEl.classList.add('active');
}

function previewHeaderImage(input) {
  if (!input.files || !input.files[0]) return;
  const reader = new FileReader();
  reader.onload = function(e) {
    const preview = document.getElementById('uploadPreview');
    const img     = document.getElementById('previewImg');
    const hdr     = document.getElementById('surveyHeader');
    img.src = e.target.result;
    preview.style.display = '';
    hdr.classList.add('has-image');
    let existingImg = hdr.querySelector('img:not([style])') || document.createElement('img');
    existingImg.src = e.target.result;
    existingImg.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;opacity:.85';
    const colorEl = document.getElementById('headerColorEl');
    if (colorEl) colorEl.style.display = 'none';
    if (!hdr.contains(existingImg)) hdr.prepend(existingImg);
    document.getElementById('uploadZone').style.display = 'none';
    document.getElementById('uploadSubmitBtn').style.display = '';
  };
  reader.readAsDataURL(input.files[0]);
}

function removeHeaderImage() {
  const form = document.getElementById('removeImageForm');
  if (form && confirm('Remove header image?')) { form.submit(); }
}

function scrollPanel() {
  document.getElementById('rightPanel').scrollTo({ top: 0, behavior: 'smooth' });
}

/* -- Card selection --------------------------------------- */
function selectCard(card) {
  document.querySelectorAll('.q-card').forEach(c => c.classList.remove('active'));
  card.classList.add('active');
}

/* -- Clipboard -------------------------------------------- */
function copyText(text) {
  navigator.clipboard.writeText(text).then(() => {
    const btn = event.currentTarget;
    const original = btn.innerHTML;
    btn.innerHTML = '<i class="fa fa-check"></i> Copied!';
    setTimeout(() => btn.innerHTML = original, 2000);
  });
}



/* -- Drag and drop question ordering ---------------------- */
function getQuestionCards() {
  return Array.from(document.querySelectorAll('#questionsWrap .q-card[data-question-id]'));
}

function updateLiveOrderBadges() {
  getQuestionCards().forEach((card, index) => {
    const order = index + 1;
    const badge = card.querySelector('.q-order-badge');
    const text  = card.querySelector('.q-order-text');
    if (badge) badge.textContent = order;
    if (text) text.textContent = order;
  });
}

function setOrderStatus(message, state = 'info') {
  const el = document.getElementById('orderStatus');
  if (!el) return;
  el.textContent = message;
  el.dataset.state = state;
  if (state === 'saving') el.style.color = '#d97706';
  else if (state === 'error') el.style.color = '#dc2626';
  else el.style.color = 'var(--accent,#fc7c10)';
}

function getDragAfterElement(container, y) {
  const cards = [...container.querySelectorAll('.q-card[data-question-id]:not(.dragging)')];
  return cards.reduce((closest, child) => {
    const box = child.getBoundingClientRect();
    const offset = y - box.top - box.height / 2;
    if (offset < 0 && offset > closest.offset) {
      return { offset, element: child };
    }
    return closest;
  }, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
}

function initQuestionDragSorting() {
  const wrap = document.getElementById('questionsWrap');
  if (!wrap) return;

  getQuestionCards().forEach(card => {
    card.addEventListener('dragstart', e => {
      draggedQuestionCard = card;
      card.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', card.dataset.questionId || '');
    });

    card.addEventListener('dragend', () => {
      card.classList.remove('dragging');
      getQuestionCards().forEach(c => c.classList.remove('drag-over'));
      draggedQuestionCard = null;
      updateLiveOrderBadges();
      scheduleQuestionOrderSave();
    });
  });

  wrap.addEventListener('dragover', e => {
    if (!draggedQuestionCard) return;
    e.preventDefault();
    const afterElement = getDragAfterElement(wrap, e.clientY);
    if (afterElement == null) {
      const addRow = wrap.querySelector('.add-question-row');
      wrap.insertBefore(draggedQuestionCard, addRow || null);
    } else {
      wrap.insertBefore(draggedQuestionCard, afterElement);
    }
    getQuestionCards().forEach(c => c.classList.remove('drag-over'));
    if (afterElement) afterElement.classList.add('drag-over');
    updateLiveOrderBadges();
  });

  wrap.addEventListener('drop', e => {
    if (!draggedQuestionCard) return;
    e.preventDefault();
    getQuestionCards().forEach(c => c.classList.remove('drag-over'));
    updateLiveOrderBadges();
    scheduleQuestionOrderSave();
  });

  updateLiveOrderBadges();
}

function scheduleQuestionOrderSave() {
  window.clearTimeout(orderSaveTimer);
  setOrderStatus('Saving...', 'saving');
  orderSaveTimer = window.setTimeout(saveQuestionOrderAjax, 350);
}

async function saveQuestionOrderAjax() {
  const order = getQuestionCards()
    .map((card, index) => ({ id: parseInt(card.dataset.questionId, 10), sort_order: index + 1 }))
    .filter(item => Number.isInteger(item.id) && item.id > 0);

  if (!order.length) {
    setOrderStatus('Saved', 'success');
    return;
  }

  const formData = new FormData();
  formData.append('action', 'reorder_questions');
  formData.append('survey_id', String(SURVEY_ID));
  formData.append('order_json', JSON.stringify(order));

  try {
    const response = await fetch(PROCESS_URL, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });

    const raw = await response.text();
    let data = null;
    try { data = JSON.parse(raw); } catch (e) {}

    if (!response.ok || !data || data.success !== true) {
      throw new Error((data && data.error) ? data.error : (raw || 'Failed to save order.'));
    }

    setOrderStatus('Saved', 'success');
  } catch (err) {
    console.error(err);
    setOrderStatus('Order not saved', 'error');
    alert('Question order was not saved: ' + err.message);
  }
}

/* -- Utility ---------------------------------------------- */
function escH(v) {
  return String(v ?? '').replace(/[&<>"']/g, m =>
    ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' })[m]);
}

/* Close modals on backdrop click */
document.querySelectorAll('.modal-overlay').forEach(overlay => {
  overlay.addEventListener('click', e => { if (e.target === overlay) overlay.classList.remove('open'); });
});

document.addEventListener('DOMContentLoaded', initQuestionDragSorting);
</script>

</body>
</html>
