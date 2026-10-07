<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/includes/auth.php';

/*
|--------------------------------------------------------------------------
| Newsletter action router
|--------------------------------------------------------------------------
|
| All browser requests post back to this same working page instead of
| requesting /admin/includes/process-newsletters.php directly.
|--------------------------------------------------------------------------
*/
$newsletter_action = trim((string)($_REQUEST['newsletter_action'] ?? $_REQUEST['action'] ?? ''));

$newsletter_actions = [
    'save',
    'duplicate_newsletter',
    'send_now',
    'delete',
    'remove_subscriber',
    'save_hero_banner',
    'save_pdf_newsletter',
    'preview',
    'get_newsletter',
    'upload_image',
    'test_email',
];

if (
    $newsletter_action !== ''
    && in_array($newsletter_action, $newsletter_actions, true)
) {
    require __DIR__ . '/includes/process-newsletters.php';
    exit;
}



if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection error.');
}
$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function nl_site_url(): string {
    return rtrim(defined('SITE_URL') ? (string)SITE_URL : '', '/');
}


function nl_format_bytes(int $bytes): string {
    $bytes = max(0, $bytes);

    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    if ($bytes < 1024 * 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 2) . ' MB';
    }

    return number_format($bytes / (1024 * 1024 * 1024), 2) . ' GB';
}


function nl_newsletter_attachment_bytes(array $newsletter): int {
    $storedPath = trim(
        (string)(
            $newsletter['pdf_file']
            ?? $newsletter['pdf_path']
            ?? ''
        )
    );

    if ($storedPath === '') {
        return 0;
    }

    $projectRoot = dirname(__DIR__);

    $candidate =
        rtrim($projectRoot, '/\\')
        . '/'
        . ltrim($storedPath, '/\\');

    if (
        is_file($candidate)
        && is_readable($candidate)
    ) {
        $size = @filesize($candidate);

        return $size !== false
            ? (int)$size
            : 0;
    }

    return 0;
}

function nl_asset_url(string $path): string {
    $path = trim($path);
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    $base = nl_site_url();
    return $base !== '' ? $base . '/' . ltrim($path, '/') : '../' . ltrim($path, '/');
}

function nl_get(mysqli $conn, string $key, string $default = ''): string {
    if (function_exists('get_setting')) {
        return (string)get_setting($conn, $key, $default);
    }
    $stmt = $conn->prepare("SELECT setting_value FROM site_settings WHERE setting_key=? LIMIT 1");
    if (!$stmt) return $default;
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return trim((string)($row['setting_value'] ?? $default));
}

function nl_safe_identifier(string $name): string {
    return preg_replace('/[^a-zA-Z0-9_]/', '', $name) ?? '';
}

function nl_table_exists(
    mysqli $conn,
    string $table
): bool {
    $table =
        nl_safe_identifier(
            $table
        );

    if ($table === '') {
        return false;
    }

    try {
        /*
         * A direct SELECT is more reliable than prepared SHOW TABLES
         * on shared cPanel/MySQL installations.
         *
         * The table name is safe because nl_safe_identifier() strips
         * everything except letters, digits and underscores.
         */
        $result =
            $conn->query(
                "SELECT 1 FROM `{$table}` LIMIT 1"
            );

        return
            $result instanceof mysqli_result;

    } catch (Throwable $e) {
        error_log(
            'Newsletter table check failed for '
            . $table
            . ': '
            . $e->getMessage()
        );

        return false;
    }
}


function nl_table_has_column(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $table =
        nl_safe_identifier(
            $table
        );

    if ($table === '') {
        return false;
    }

    try {
        $result =
            $conn->query(
                "SHOW COLUMNS FROM `{$table}`"
            );

        if (
            !($result instanceof mysqli_result)
        ) {
            return false;
        }

        while (
            $row =
                $result->fetch_assoc()
        ) {
            if (
                (string)(
                    $row['Field']
                    ?? ''
                )
                === $column
            ) {
                return true;
            }
        }

        return false;

    } catch (Throwable $e) {
        error_log(
            'Newsletter column check failed for '
            . $table
            . '.'
            . $column
            . ': '
            . $e->getMessage()
        );

        return false;
    }
}


function nl_query_count(
    mysqli $conn,
    string $sql,
    string $types = '',
    array $params = []
): int {
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return 0;
    }

    if ($types !== '' && $params) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['c'] ?? 0);
}

function nl_scalar(mysqli $conn, string $sql): int {
    $res = $conn->query($sql);
    if (!$res) return 0;
    $row = $res->fetch_assoc();
    return (int)($row['c'] ?? 0);
}

function nl_pdf_path(array $row): string {
    return trim((string)($row['pdf_file'] ?? $row['pdf_path'] ?? ''));
}

$site_name  = nl_get($conn, 'site_name', 'EdTech Fellowship');
$from_email = nl_get($conn, 'contact_email', nl_get($conn, 'site_email', 'no-reply@example.com'));
$newsletter_process_url = 'newsletters';

$active_tab = in_array((string)($_GET['tab'] ?? ''), ['newsletters','hero'], true)
    ? (string)$_GET['tab']
    : 'newsletters';

$hero_bg_type         = nl_get($conn, 'newsletter_hero_bg_type', 'color');
$hero_bg_color        = nl_get($conn, 'newsletter_hero_bg_color', '#0f172a');
$hero_bg_image        = nl_get($conn, 'newsletter_hero_bg_image', '');
$hero_bg_video        = nl_get($conn, 'newsletter_hero_bg_video', '');
$hero_overlay         = nl_get($conn, 'newsletter_hero_bg_overlay', '1');
$hero_overlay_color   = nl_get($conn, 'newsletter_hero_bg_overlay_color', '#000000');
$hero_overlay_opacity = max(0, min(100, (int)nl_get($conn, 'newsletter_hero_bg_overlay_opacity', '40')));
$hero_label           = nl_get($conn, 'newsletter_hero_label', 'Newsletter');
$hero_title           = nl_get($conn, 'newsletter_hero_title', 'Stay updated with our latest news');
$hero_subtitle        = nl_get($conn, 'newsletter_hero_subtitle', 'Subscribe to receive updates, opportunities, events, venture stories, and ecosystem news.');

if (!in_array($hero_bg_type, ['color','image','video'], true)) $hero_bg_type = 'color';
if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $hero_bg_color)) $hero_bg_color = '#0f172a';
if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $hero_overlay_color)) $hero_overlay_color = '#000000';

$hasDuplicatedFrom =
    nl_table_has_column(
        $conn,
        'newsletters',
        'duplicated_from'
    );

/*
|--------------------------------------------------------------------------
| Lightweight newsletter listing
|--------------------------------------------------------------------------
|
| Never SELECT body_json/body_html/recipient_emails on the list page.
| Those LONGTEXT fields are fetched only when a user clicks Edit, View,
| Duplicate or Send.
|--------------------------------------------------------------------------
*/

$nl_per_page = 20;
$nl_page = max(1, (int)($_GET['page'] ?? 1));
$nl_offset = ($nl_page - 1) * $nl_per_page;

$total_nl = nl_query_count(
    $conn,
    "SELECT COUNT(*) AS c FROM newsletters"
);

$sent_nl = nl_query_count(
    $conn,
    "SELECT COUNT(*) AS c
     FROM newsletters
     WHERE status='sent'"
);


$newsletter_storage_bytes = 0;

try {
    $storageResult = $conn->query("
        SELECT
            COALESCE(
                SUM(
                    OCTET_LENGTH(COALESCE(subject, ''))
                    + OCTET_LENGTH(COALESCE(preheader, ''))
                    + OCTET_LENGTH(COALESCE(from_name, ''))
                    + OCTET_LENGTH(COALESCE(from_email, ''))
                    + OCTET_LENGTH(COALESCE(body_json, ''))
                    + OCTET_LENGTH(COALESCE(body_html, ''))
                    + OCTET_LENGTH(COALESCE(body_message, ''))
                    + OCTET_LENGTH(COALESCE(recipient_emails, ''))
                    + OCTET_LENGTH(COALESCE(pdf_path, ''))
                    + OCTET_LENGTH(COALESCE(pdf_file, ''))
                    + OCTET_LENGTH(COALESCE(pdf_name, ''))
                ),
                0
            ) AS total_bytes
        FROM newsletters
    ");

    if ($storageResult) {
        $storageRow = $storageResult->fetch_assoc();

        $newsletter_storage_bytes =
            (int)(
                $storageRow['total_bytes']
                ?? 0
            );
    }
} catch (Throwable $e) {
    error_log(
        'Newsletter storage calculation failed: '
        . $e->getMessage()
    );
}

$nl_pages = max(
    1,
    (int)ceil(
        $total_nl / $nl_per_page
    )
);

if ($nl_page > $nl_pages) {
    $nl_page = $nl_pages;
    $nl_offset = ($nl_page - 1) * $nl_per_page;
}

$listColumns = "
    id,
    subject,
    preheader,
    from_name,
    from_email,
    pdf_path,
    pdf_file,
    pdf_name,
    recipient_type,
    status,
    sent_at,
    total_sent,
    created_at,
    updated_at,

    (
        OCTET_LENGTH(COALESCE(subject, ''))
        + OCTET_LENGTH(COALESCE(preheader, ''))
        + OCTET_LENGTH(COALESCE(from_name, ''))
        + OCTET_LENGTH(COALESCE(from_email, ''))
        + OCTET_LENGTH(COALESCE(body_json, ''))
        + OCTET_LENGTH(COALESCE(body_html, ''))
        + OCTET_LENGTH(COALESCE(body_message, ''))
        + OCTET_LENGTH(COALESCE(recipient_emails, ''))
        + OCTET_LENGTH(COALESCE(pdf_path, ''))
        + OCTET_LENGTH(COALESCE(pdf_file, ''))
        + OCTET_LENGTH(COALESCE(pdf_name, ''))
    ) AS content_size_bytes
";

if ($hasDuplicatedFrom) {
    $listColumns .= ", duplicated_from";
}

$listSql = "
    SELECT {$listColumns}
    FROM newsletters
    ORDER BY created_at DESC, id DESC
    LIMIT ?
    OFFSET ?
";

$listStmt = $conn->prepare($listSql);

$nls = null;

if ($listStmt) {
    $listStmt->bind_param(
        'ii',
        $nl_per_page,
        $nl_offset
    );

    $listStmt->execute();
    $nls = $listStmt->get_result();
}

/*
|--------------------------------------------------------------------------
| Counts are loaded immediately; long recipient lists are lazy-loaded.
|--------------------------------------------------------------------------
*/

$sub_count =
    nl_table_exists(
        $conn,
        'newsletter_subscribers'
    )
        ? nl_query_count(
            $conn,
            "SELECT COUNT(*) AS c
             FROM newsletter_subscribers
             WHERE status='active'"
        )
        : 0;

$startup_count =
    nl_table_exists(
        $conn,
        'startups'
    )
        ? nl_query_count(
            $conn,
            "SELECT COUNT(*) AS c
             FROM startups
             WHERE email IS NOT NULL
               AND email<>''"
        )
        : 0;

$saved_count =
    nl_table_exists(
        $conn,
        'newsletter_saved_recipients'
    )
        ? nl_query_count(
            $conn,
            "SELECT COUNT(*) AS c
             FROM newsletter_saved_recipients"
        )
        : 0;

/*
 * Builder recipient lists are loaded only on the newsletter tab and are
 * bounded to avoid exhausting memory on very large installations.
 */
$recipient_list_limit = 1000;

$startups_emails = [];

if (
    $active_tab === 'newsletters'
    && $startup_count > 0
) {
    $stmt = $conn->prepare("
        SELECT id, name, email
        FROM startups
        WHERE email IS NOT NULL
          AND email <> ''
        ORDER BY name ASC
        LIMIT ?
    ");

    if ($stmt) {
        $stmt->bind_param(
            'i',
            $recipient_list_limit
        );

        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $startups_emails[] = $row;
        }

        $stmt->close();
    }
}

$subscriber_rows = [];

if (
    $active_tab === 'newsletters'
    && $sub_count > 0
) {
    $stmt = $conn->prepare("
        SELECT id, email, name, status, subscribed_at
        FROM newsletter_subscribers
        ORDER BY subscribed_at DESC
        LIMIT ?
    ");

    if ($stmt) {
        $stmt->bind_param(
            'i',
            $recipient_list_limit
        );

        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $subscriber_rows[] = $row;
        }

        $stmt->close();
    }
}

$saved_recipients = [];

if (
    $active_tab === 'newsletters'
    && $saved_count > 0
) {
    $stmt = $conn->prepare("
        SELECT id, email, name, source
        FROM newsletter_saved_recipients
        ORDER BY email ASC
        LIMIT ?
    ");

    if ($stmt) {
        $stmt->bind_param(
            'i',
            $recipient_list_limit
        );

        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $saved_recipients[] = $row;
        }

        $stmt->close();
    }
}

$palette_blocks = [
    ['logo','fa-cubes','Logo'],
    ['topbar','fa-columns','Issue Bar'],
    ['heading','fa-heading','Heading'],
    ['text','fa-align-left','Text'],
    ['image','fa-image','Image'],
    ['button','fa-link','Button'],
    ['divider','fa-minus','Divider'],
    ['section','fa-square','Story Section/Card'],
    ['cta','fa-bullhorn','Coming Up / CTA'],
    ['logoFooter','fa-address-card','Logo Footer'],
    ['columns','fa-th-large','Columns'],
    ['spacer','fa-arrows-alt-v','Spacer'],
];
?>
<!doctype html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Newsletters - <?= h($site_name) ?></title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;700;900&family=Figtree:wght@300;400;500;600;700;800&family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700;800;900&family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

<?php
$adminCss = __DIR__ . '/assets/css/admin.css';
$nlCss    = __DIR__ . '/assets/css/newsletters.css';
$adminUrl = nl_site_url() !== '' ? nl_site_url().'/admin/assets/css/admin.css' : 'assets/css/admin.css';
$nlUrl    = nl_site_url() !== '' ? nl_site_url().'/admin/assets/css/newsletters.css' : 'assets/css/newsletters.css';
?>
<link rel="stylesheet" href="<?= h($adminUrl) ?>?v=<?= is_file($adminCss) ? (int)filemtime($adminCss) : time() ?>">
<link rel="stylesheet" href="<?= h($nlUrl) ?>?v=<?= is_file($nlCss) ? (int)filemtime($nlCss) : time() ?>">

<style>
#previewModal{padding:16px!important}
#previewModal .nl-preview-dialog{
    width:min(1180px,calc(100vw - 32px))!important;
    max-width:1180px!important;
    height:calc(100vh - 32px)!important;
    max-height:calc(100vh - 32px)!important;
    display:flex!important;
    flex-direction:column!important;
    overflow:hidden!important
}
#previewModal .nl-preview-body{
    flex:1 1 auto!important;
    min-height:0!important;
    padding:0!important;
    overflow:hidden!important;
    background:#e8edf3!important
}
#previewFrame{
    width:100%!important;
    height:100%!important;
    border:0!important;
    display:block!important;
    background:#fff!important;
    transition:width .2s ease,margin .2s ease,border-radius .2s ease
}
#previewFrame.preview-mobile{
    width:390px!important;
    max-width:calc(100% - 20px)!important;
    height:calc(100% - 24px)!important;
    margin:12px auto!important;
    border-radius:12px!important;
    box-shadow:0 12px 34px rgba(15,23,42,.2)
}
.nl-preview-actions{display:flex;align-items:center;gap:7px;margin-left:auto}
.nl-preview-actions .active{background:#111827!important;color:#fff!important}
@media(max-width:640px){
  #previewModal{padding:7px!important}
  #previewModal .nl-preview-dialog{
      width:calc(100vw - 14px)!important;
      height:calc(100dvh - 14px)!important;
      max-height:calc(100dvh - 14px)!important
  }
  .nl-preview-device-label{display:none}
}

.nl-settings-group{
    margin-top:16px;
    padding-top:16px;
    border-top:1px solid #e5e7eb
}
.nl-settings-subtitle{
    display:flex;
    align-items:center;
    gap:8px;
    margin-bottom:10px;
    color:#111827;
    font-size:13px;
    font-weight:800
}
.nl-bg-choice-row{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:7px;
    margin-bottom:12px
}
.nl-bg-choice{
    appearance:none;
    border:1px solid #d9dee8;
    background:#fff;
    color:#4b5563;
    border-radius:9px;
    min-height:40px;
    padding:7px 8px;
    cursor:pointer;
    font:inherit;
    font-size:12px;
    font-weight:700
}
.nl-bg-choice i{margin-right:4px}
.nl-bg-choice:hover{
    border-color:#f4512c;
    color:#f4512c
}
.nl-bg-choice.active{
    border-color:#f4512c;
    background:#fff4ef;
    color:#d94720;
    box-shadow:0 0 0 2px rgba(244,81,44,.08)
}
.nl-colour-control{
    display:grid;
    grid-template-columns:48px 1fr;
    gap:8px;
    align-items:center
}
.nl-colour-picker{
    width:48px;
    height:42px;
    padding:3px;
    border:1px solid #d9dee8;
    border-radius:8px;
    background:#fff;
    cursor:pointer
}
.nl-background-upload small{
    display:block;
    margin-top:4px;
    color:#8b93a1;
    font-size:11px
}
.nl-bg-image-preview{
    margin-top:10px;
    padding:8px;
    border:1px solid #e5e7eb;
    border-radius:10px;
    background:#f8fafc
}
.nl-bg-image-preview img{
    display:block;
    width:100%;
    max-height:150px;
    object-fit:cover;
    border-radius:7px;
    margin-bottom:8px
}


.nl-settings-title{
    text-transform:capitalize
}
.nl-background-settings .nl-form-group{
    margin-bottom:11px
}


.nl-pagination{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:12px;
    flex-wrap:wrap;
    padding:16px 18px;
    border-top:1px solid #e5e7eb
}
.nl-pagination .disabled{
    opacity:.45;
    pointer-events:none
}
.nl-remote-loading{
    position:fixed;
    inset:0;
    z-index:999999;
    display:none;
    align-items:center;
    justify-content:center;
    background:rgba(15,23,42,.28);
    backdrop-filter:blur(2px)
}
.nl-remote-loading.show{display:flex}
.nl-remote-loading-box{
    display:flex;
    align-items:center;
    gap:10px;
    padding:13px 18px;
    border-radius:10px;
    background:#fff;
    color:#111827;
    font-weight:700;
    box-shadow:0 18px 50px rgba(15,23,42,.2)
}
.nl-remote-loading-box i{color:#f4512c}


.nl-size-cell{min-width:120px}
.nl-size-cell strong{
  display:block;
  color:#111827;
  font-size:12px;
  font-weight:800;
  white-space:nowrap
}
.nl-size-cell small{
  display:block;
  margin-top:2px;
  color:#94a3b8;
  font-size:9px;
  line-height:1.35;
  white-space:nowrap
}

</style>
</head>
<body class="admin-system-page">
<?php include 'includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">
  <div class="nl-topbar">
    <div class="nl-topbar-inner">
      <div>
        <div class="nl-crumb">
          <a href="index.php"><i class="fa fa-home"></i> Dashboard</a>
          <span>/</span><span>Newsletters</span>
        </div>
        <h1 class="nl-page-title">Newsletters</h1>
        <p class="nl-page-sub">Design, preview, duplicate and send newsletters or upload ready PDF newsletters.</p>
      </div>
      <div class="nl-topbar-actions">
        <button class="nl-btn nl-btn-ghost nl-btn-sm" type="button" onclick="NL.openSubsModal()"><i class="fa fa-users"></i> Subscribers</button>
        <button
          class="nl-btn nl-btn-ghost nl-btn-sm"
          type="button"
          onclick="document.getElementById('smtpTestModal').classList.add('active')"
        >
          <i class="fa fa-paper-plane"></i> Test SMTP
        </button>
        <button class="nl-btn nl-btn-ghost nl-btn-sm" type="button" onclick="NL.openPdfModal()"><i class="fa fa-file-pdf"></i> Upload PDF</button>
        <button class="nl-btn nl-btn-primary nl-btn-sm" type="button" onclick="NL.openBuilder()"><i class="fa fa-magic"></i> Design Newsletter</button>
      </div>
    </div>

    <div class="nl-tabs nl-tabs-on-dark">
      <button class="nl-tab <?= $active_tab==='newsletters'?'active':'' ?>" type="button" onclick="NL.switchTab('newsletters',this)"><i class="fa fa-envelope"></i> Newsletters</button>
      <button class="nl-tab <?= $active_tab==='hero'?'active':'' ?>" type="button" onclick="NL.switchTab('hero',this)"><i class="fa fa-image"></i> Hero Banner</button>
    </div>
  </div>

  <div class="nl-page-pad">
    <?php if (function_exists('show_flash')) show_flash('newsletters'); ?>

    <div class="nl-stats-grid">
      <?php foreach ([
          ['primary','fa-envelope','Total Newsletters',$total_nl,'Newsletters created'],
          ['success','fa-paper-plane','Sent',$sent_nl,'Campaigns delivered'],
          ['info','fa-database','Storage',nl_format_bytes($newsletter_storage_bytes),'Newsletter content size'],
          ['info','fa-users','Subscribers',$sub_count,'Active subscribers'],
          ['warning','fa-rocket','Startups',$startup_count,'With email on file'],
          ['purple','fa-address-book','Saved Recipients',$saved_count,'Remembered recipients'],
      ] as [$c,$i,$label,$value,$desc]): ?>
        <article class="nl-stat-card nl-stat-card-<?= h($c) ?>">
          <div class="nl-stat-icon"><i class="fa <?= h($i) ?>"></i></div>
          <div class="nl-stat-content">
            <div class="nl-stat-label"><?= h($label) ?></div>
            <div class="nl-stat-value"><?= number_format((int)$value) ?></div>
            <div class="nl-stat-description"><?= h($desc) ?></div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <div class="nl-tab-content <?= $active_tab==='newsletters'?'active':'' ?>" id="tc_newsletters">
      <div class="nl-card">
        <div class="nl-card-header">
          <div class="nl-card-title"><i class="fa fa-envelope"></i> All Newsletters</div>
          <div class="nl-card-actions">
            <button class="nl-btn nl-btn-secondary nl-btn-sm" type="button" onclick="NL.openPdfModal()"><i class="fa fa-file-pdf"></i> Upload PDF</button>
            <button class="nl-btn nl-btn-primary nl-btn-sm" type="button" onclick="NL.openBuilder()"><i class="fa fa-magic"></i> Design New</button>
          </div>
        </div>

        <?php if (!$nls || $nls->num_rows===0): ?>
          <div class="nl-empty">
            <i class="fa fa-envelope-open-text"></i>
            <h3>No newsletters yet</h3>
            <p>Create a designed newsletter or upload a PDF newsletter.</p>
          </div>
        <?php else: ?>
          <div class="nl-table-wrap">
            <table class="nl-table">
              <thead>
                <tr>
                  <th>Subject</th><th>Format</th><th>Size</th><th>Recipients</th>
                  <th>Status</th><th>Sent</th><th>Created</th><th>Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php while ($nl=$nls->fetch_assoc()): ?>
                <?php
                $pdfPath = nl_pdf_path($nl);
                $isPdf = $pdfPath !== '';
                $status = (string)($nl['status'] ?? 'draft');
                $bc = ['draft'=>'badge-draft','sent'=>'badge-sent','scheduled'=>'badge-sched'][$status] ?? 'badge-gray';
                $rtype = (string)($nl['recipient_type'] ?? 'manual');
                $labels = ['startups'=>'Startups','upload'=>'Uploaded CSV','subscribers'=>'Subscribers','manual'=>'Manual','saved'=>'Saved List'];


                $contentSizeBytes =
                    max(
                        0,
                        (int)(
                            $nl['content_size_bytes']
                            ?? 0
                        )
                    );

                $attachmentSizeBytes =
                    nl_newsletter_attachment_bytes(
                        $nl
                    );

                $newsletterSizeBytes =
                    $contentSizeBytes
                    + $attachmentSizeBytes;
                ?>
                <tr data-newsletter-id="<?= (int)$nl['id'] ?>">
                  <td>
                    <div class="nl-subject-main"><?= h($nl['subject'] ?? 'Untitled Newsletter') ?></div>
                    <?php if (!empty($nl['preheader'])): ?>
                      <div class="nl-subject-pre"><?= h(mb_strimwidth((string)$nl['preheader'],0,75,'...')) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($nl['duplicated_from'])): ?>
                      <div class="nl-muted-xs"><i class="fa fa-copy"></i> Copy of #<?= (int)$nl['duplicated_from'] ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="nl-badge <?= $isPdf?'badge-pdf':'badge-info' ?>">
                      <i class="fa <?= $isPdf?'fa-file-pdf':'fa-paint-brush' ?>"></i>
                      <?= $isPdf?'PDF':'Designed' ?>
                    </span>
                  </td>

                  <td>
                    <div class="nl-size-cell">
                      <strong><?= h(nl_format_bytes($newsletterSizeBytes)) ?></strong>

                      <?php if ($attachmentSizeBytes > 0): ?>
                        <small>
                          Content <?= h(nl_format_bytes($contentSizeBytes)) ?>
                          � File <?= h(nl_format_bytes($attachmentSizeBytes)) ?>
                        </small>
                      <?php else: ?>
                        <small>Stored content</small>
                      <?php endif; ?>
                    </div>
                  </td>

                  <td>
                    <span class="nl-badge badge-info"><?= h($labels[$rtype] ?? '-') ?></span>
                    <?php if (!empty($nl['total_sent'])): ?><small class="nl-muted">(<?= (int)$nl['total_sent'] ?> sent)</small><?php endif; ?>
                  </td>
                  <td><span class="nl-badge <?= h($bc) ?>"><?= h(ucfirst($status)) ?></span></td>
                  <td class="nl-muted-sm"><?= !empty($nl['sent_at']) ? h(date('M j, Y � g:i A',strtotime((string)$nl['sent_at']))) : '-' ?></td>
                  <td class="nl-muted-sm"><?= !empty($nl['created_at']) ? h(date('M j, Y',strtotime((string)$nl['created_at']))) : '-' ?></td>
                  <td>
                    <div class="nl-tbl-actions">
                      <?php if ($isPdf): ?>
                        <a href="<?= h(nl_asset_url($pdfPath)) ?>" target="_blank" rel="noopener" class="nl-btn nl-btn-sm nl-btn-secondary nl-btn-icon" title="View PDF"><i class="fa fa-eye"></i></a>
                      <?php else: ?>
                        <button
                          class="nl-btn nl-btn-sm nl-btn-secondary nl-btn-icon"
                          type="button"
                          onclick="NLRemote.edit(<?= (int)$nl['id'] ?>)"
                          title="Edit"
                        ><i class="fa fa-pen"></i></button>
                        <button
                          class="nl-btn nl-btn-sm nl-btn-secondary nl-btn-icon"
                          type="button"
                          onclick="NLRemote.preview(<?= (int)$nl['id'] ?>)"
                          title="View"
                        ><i class="fa fa-eye"></i></button>
                      <?php endif; ?>

                      <button
                        class="nl-btn nl-btn-sm nl-btn-secondary nl-btn-icon"
                        type="button"
                        onclick="NLRemote.duplicate(<?= (int)$nl['id'] ?>)"
                        title="Duplicate as new draft"
                      ><i class="fa fa-copy"></i></button>

                      <?php if ($status !== 'sent'): ?>
                        <button class="nl-btn nl-btn-sm nl-btn-primary nl-btn-icon" type="button"
                          onclick='NL.confirmSend(<?= (int)$nl['id'] ?>,<?= json_encode((string)($nl['subject'] ?? ''),JSON_HEX_TAG|JSON_HEX_QUOT|JSON_HEX_APOS|JSON_HEX_AMP) ?>)'
                          title="Send"><i class="fa fa-paper-plane"></i></button>
                      <?php endif; ?>

                      <form method="POST" action="newsletters" class="nl-inline-form" onsubmit="return NL.confirmDeleteNewsletter(this)">
                        <input type="hidden" name="newsletter_action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$nl['id'] ?>">
                        <button class="nl-btn nl-btn-sm nl-btn-danger nl-btn-icon" type="submit" title="Delete"><i class="fa fa-trash"></i></button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endwhile; ?>
              </tbody>
            </table>
          </div>

          <?php if ($nl_pages > 1): ?>
            <div class="nl-pagination" aria-label="Newsletter pages">
              <?php
                $prevPage = max(1, $nl_page - 1);
                $nextPage = min($nl_pages, $nl_page + 1);
              ?>

              <a
                class="nl-btn nl-btn-secondary nl-btn-sm <?= $nl_page <= 1 ? 'disabled' : '' ?>"
                href="?tab=newsletters&amp;page=<?= $prevPage ?>"
              >
                <i class="fa fa-chevron-left"></i>
                Previous
              </a>

              <span class="nl-muted-sm">
                Page <?= number_format($nl_page) ?>
                of <?= number_format($nl_pages) ?>
              </span>

              <a
                class="nl-btn nl-btn-secondary nl-btn-sm <?= $nl_page >= $nl_pages ? 'disabled' : '' ?>"
                href="?tab=newsletters&amp;page=<?= $nextPage ?>"
              >
                Next
                <i class="fa fa-chevron-right"></i>
              </a>
            </div>
          <?php endif; ?>

        <?php endif; ?>
      </div>
    </div>

    <div class="nl-tab-content <?= $active_tab==='hero'?'active':'' ?>" id="tc_hero">
      <div class="nl-card">
        <div class="nl-card-header">
          <div class="nl-card-title"><i class="fa fa-image"></i> Newsletter Hero Banner</div>
          <span class="nl-muted-sm">Shown on the public newsletter page</span>
        </div>

        <form method="POST" action="newsletters" enctype="multipart/form-data">
          <input type="hidden" name="newsletter_action" value="save_hero_banner">
          <div class="hero-editor-grid">
            <div>
              <p class="nl-preview-label"><i class="fa fa-eye"></i> Live Preview</p>
              <div class="hero-preview" id="heroPreview" style="<?php
                if ($hero_bg_type==='color') echo 'background:'.h($hero_bg_color);
                elseif ($hero_bg_type==='image' && $hero_bg_image) echo "background-image:url('".h(nl_asset_url($hero_bg_image))."');background-size:cover;background-position:center";
                else echo 'background:#0d1117';
              ?>">
                <?php if ($hero_bg_type==='video' && $hero_bg_video): ?>
                  <video autoplay muted loop playsinline id="heroPrevVideo"><source src="<?= h(nl_asset_url($hero_bg_video)) ?>"></video>
                <?php endif; ?>
                <div class="hero-preview-overlay" id="heroOverlayEl" style="background:<?= h($hero_overlay_color) ?>;opacity:<?= $hero_overlay==='1'?($hero_overlay_opacity/100):0 ?>"></div>
                <div class="hero-preview-content">
                  <div class="hero-preview-badge"><i class="fa fa-envelope"></i> <span id="prevLabel"><?= h($hero_label) ?></span></div>
                  <h2 id="prevTitle"><?= h($hero_title) ?></h2>
                  <p id="prevSubtitle"><?= h($hero_subtitle) ?></p>
                </div>
              </div>
            </div>

            <div class="hero-controls">
              <div class="hero-control-section">
                <div class="hero-section-title"><i class="fa fa-layer-group"></i> Background Type</div>
                <div class="bg-type-row">
                  <?php foreach (['color'=>'Color','image'=>'Image','video'=>'Video'] as $type=>$label): ?>
                    <label class="bg-type-chip <?= $hero_bg_type===$type?'active':'' ?>" id="bgt-<?= h($type) ?>">
                      <input type="radio" name="newsletter_hero_bg_type" value="<?= h($type) ?>" <?= $hero_bg_type===$type?'checked':'' ?> onchange="NL.setBgType('<?= h($type) ?>')">
                      <?= h($label) ?>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>

              <div class="hero-control-section" id="heroColorSection" style="<?= $hero_bg_type!=='color'?'display:none':'' ?>">
                <div class="hero-section-title">Background Color</div>
                <div class="color-pick-row">
                  <input type="color" id="heroBgColorPicker" value="<?= h($hero_bg_color) ?>" oninput="NL.applyHeroBgColor(this.value)">
                  <input type="text" name="newsletter_hero_bg_color" id="heroBgColorText" class="nl-form-control" value="<?= h($hero_bg_color) ?>" oninput="NL.syncHeroBgText()">
                </div>
              </div>

              <div class="hero-control-section" id="heroImageSection" style="<?= $hero_bg_type!=='image'?'display:none':'' ?>">
                <div class="hero-section-title">Hero Image</div>
                <input type="file" name="newsletter_hero_bg_image_file" accept="image/*" class="nl-form-control" onchange="NL.previewHeroImg(this)">
              </div>

              <div class="hero-control-section" id="heroVideoSection" style="<?= $hero_bg_type!=='video'?'display:none':'' ?>">
                <div class="hero-section-title">Hero Video</div>
                <input type="file" name="newsletter_hero_bg_video_file" accept="video/mp4,video/webm,video/ogg" class="nl-form-control">
              </div>

              <div class="hero-control-section">
                <div class="hero-section-title">Overlay</div>
                <label class="nl-check-row"><input type="checkbox" name="newsletter_hero_bg_overlay" value="1" id="heroOverlayCb" <?= $hero_overlay==='1'?'checked':'' ?> onchange="NL.updateOverlayPreview()"> Enable overlay</label>
                <div class="color-pick-row nl-mt-xs">
                  <input type="color" id="heroOverlayColorPicker" value="<?= h($hero_overlay_color) ?>" oninput="NL.applyHeroOverlayColor(this.value)">
                  <input type="text" name="newsletter_hero_bg_overlay_color" id="heroOverlayColorText" class="nl-form-control" value="<?= h($hero_overlay_color) ?>" oninput="NL.syncHeroOverlayText()">
                </div>
                <label class="nl-mt-xs">Opacity: <span id="opacityLabel"><?= $hero_overlay_opacity ?></span>%</label>
                <input type="range" min="0" max="100" value="<?= $hero_overlay_opacity ?>" id="heroOpacityRange" oninput="NL.syncOpacity(this.value)">
                <input type="hidden" name="newsletter_hero_bg_overlay_opacity" id="heroOpacityNum" value="<?= $hero_overlay_opacity ?>">
              </div>

              <div class="hero-control-section">
                <div class="hero-section-title">Text Content</div>
                <div class="nl-form-group"><label>Badge Label</label><input type="text" name="newsletter_hero_label" class="nl-form-control" value="<?= h($hero_label) ?>" oninput="$('prevLabel').textContent=this.value"></div>
                <div class="nl-form-group"><label>Title</label><input type="text" name="newsletter_hero_title" class="nl-form-control" value="<?= h($hero_title) ?>" oninput="$('prevTitle').textContent=this.value"></div>
                <div class="nl-form-group"><label>Subtitle</label><textarea name="newsletter_hero_subtitle" class="nl-form-control" rows="3" oninput="$('prevSubtitle').textContent=this.value"><?= h($hero_subtitle) ?></textarea></div>
              </div>

              <button type="submit" class="nl-btn nl-btn-primary nl-full"><i class="fa fa-save"></i> Save Hero Banner</button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- DESIGNER -->
<div class="modal-overlay" id="nlModal">
  <div class="nl-modal nl-builder-modal">
    <div class="nl-modal-header">
      <span class="nl-modal-title" id="nlModalTitle"><i class="fa fa-magic"></i> Design Newsletter</span>
      <div class="nl-modal-head-actions">
        <button type="button" class="nl-btn nl-btn-secondary nl-btn-sm" onclick="NL.loadHiveTemplate()"><i class="fa fa-bolt"></i> Load Hive Template</button>
        <button class="nl-modal-close" type="button" onclick="NL.closeBuilder()">&times;</button>
      </div>
    </div>

    <form method="POST" action="newsletters" enctype="multipart/form-data" onsubmit="return NL.prepareNLSubmit(event)" id="nlBuilderForm">
      <input type="hidden" name="newsletter_action" value="save">
      <input type="hidden" name="id" id="nl_id">
      <input type="hidden" name="duplicated_from" id="nl_duplicated_from">
      <input type="hidden" name="body_json" id="nl_body_json">
      <input type="hidden" name="body_html" id="nl_body_html">
      <input type="hidden" name="recipient_emails" id="nl_recipient_emails">
      <input type="hidden" name="recipient_type" id="nl_recipient_type" value="startups">
      <input type="hidden" name="save_recipients" id="nl_save_recipients" value="0">

      <div class="nl-builder-top-fields">
        <div class="nl-form-group"><label>Subject <span class="nl-required">*</span></label><input type="text" name="subject" id="nl_subject" class="nl-form-control" required></div>
        <div class="nl-form-group"><label>Preheader</label><input type="text" name="preheader" id="nl_preheader" class="nl-form-control"></div>
        <div class="nl-form-group"><label>From Name</label><input type="text" name="from_name" id="nl_from_name" class="nl-form-control" value="<?= h($site_name) ?>"></div>
        <div class="nl-form-group"><label>From Email</label><input type="email" name="from_email" id="nl_from_email" class="nl-form-control" value="<?= h($from_email) ?>"></div>
      </div>

      <div class="nl-builder-workspace">
        <aside class="nl-toolbox">
          <div class="nl-toolbox-scroll">
            <div class="nl-palette-label">Drag Blocks</div>
            <?php foreach ($palette_blocks as [$type,$icon,$label]): ?>
              <button type="button" class="nl-palette-btn" draggable="true" data-block-type="<?= h($type) ?>" onclick="NL.paletteClick(event,'<?= h($type) ?>',this)">
                <span class="pal-icon"><i class="fa <?= h($icon) ?>"></i></span>
                <span><?= h($label) ?></span><i class="fa fa-grip-vertical nl-grip"></i>
              </button>
            <?php endforeach; ?>

            <div class="nl-palette-label nl-mt">Layouts</div>
            <button type="button" class="nl-palette-btn" onclick="NL.addContentLayout('image-above')"><span class="pal-icon"><i class="fa fa-image"></i></span><span>Image Above Text</span></button>
            <button type="button" class="nl-palette-btn" onclick="NL.addContentLayout('text-above')"><span class="pal-icon"><i class="fa fa-align-left"></i></span><span>Text Above Image</span></button>

            <div class="nl-palette-label nl-mt">Recipients</div>
            <div class="rcpt-tabs">
              <button type="button" class="rcpt-tab active" onclick="NL.switchRcpt('startups',this,'nl')">Startups</button>
              <button type="button" class="rcpt-tab" onclick="NL.switchRcpt('upload',this,'nl')">CSV</button>
              <button type="button" class="rcpt-tab" onclick="NL.switchRcpt('saved',this,'nl')">Saved</button>
              <button type="button" class="rcpt-tab" onclick="NL.switchRcpt('subscribers',this,'nl')">Subs</button>
              <button type="button" class="rcpt-tab" onclick="NL.switchRcpt('manual',this,'nl')">Manual</button>
            </div>

            <div class="rcpt-panel active" id="nl_rcpt_startups">
              <div class="nl-between"><span class="nl-muted-xs"><?= number_format($startup_count) ?> startup emails</span><button type="button" class="nl-btn nl-btn-secondary nl-btn-xs" onclick="NL.selectAllStartups('nl')">All</button></div>
              <div class="startup-list">
                <?php foreach ($startups_emails as $s): ?>
                  <label class="startup-item"><input type="checkbox" class="nl-startup-chk" value="<?= h($s['email']) ?>"><div><div class="startup-name"><?= h($s['name']) ?></div><div class="startup-email"><?= h($s['email']) ?></div></div></label>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="rcpt-panel" id="nl_rcpt_upload">
              <input type="file" class="nl-form-control" accept=".csv,.txt" onchange="NL.parseEmailFile(this,'nl')">
              <div id="nlUploadedEmailsInfo" class="nl-muted-xs nl-mt-xs"></div>
            </div>

            <div class="rcpt-panel" id="nl_rcpt_saved">
              <div class="startup-list">
                <?php foreach ($saved_recipients as $s): ?>
                  <label class="startup-item"><input type="checkbox" class="nl-saved-chk" value="<?= h($s['email']) ?>"><div><div class="startup-name"><?= h($s['name'] ?: $s['email']) ?></div><div class="startup-email"><?= h($s['email']) ?></div></div></label>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="rcpt-panel" id="nl_rcpt_subscribers"><p class="nl-muted-sm">Send to all <strong><?= $sub_count ?></strong> active subscribers.</p></div>

            <div class="rcpt-panel" id="nl_rcpt_manual">
              <div class="email-tags-box" id="nlEmailTagsBox" onclick="document.getElementById('nlEtInput').focus()">
                <input type="text" id="nlEtInput" class="etag-input" placeholder="you@example.com" onkeydown="NL.handleEmailTag(event,'nl')">
              </div>
              <div id="nlManualCount" class="nl-muted-xs">0 emails</div>
            </div>
          </div>
        </aside>

        <main class="nl-canvas-area">
          <div class="nl-canvas-toolbar">
            <span><i class="fa fa-mouse-pointer"></i> Drag blocks into the page or click to add.</span>
            <button type="button" class="nl-btn nl-btn-secondary nl-btn-xs" onclick="NL.clearCanvas()"><i class="fa fa-eraser"></i> Clear</button>
          </div>
          <div class="nl-canvas-wrap"><div class="nl-email-shell" id="nlCanvas"></div></div>
        </main>

        <aside class="nl-settings" id="nlSettings">
          <div class="nl-settings-empty" id="nlSettingsEmpty"><i class="fa fa-sliders-h"></i><p>Select a block to edit it.</p></div>
          <div id="nlSettingsContent" class="nl-settings-content"></div>
        </aside>
      </div>

      <div id="nlHiddenInputs"></div>

      <div class="nl-modal-footer">
        <span id="nlSaveStatus" class="nl-save-status"></span>
        <button type="button" class="nl-btn nl-btn-secondary" onclick="NL.closeBuilder()">Cancel</button>
        <button type="submit" name="save_action" value="draft" formnovalidate class="nl-btn nl-btn-secondary" data-ajax-save="1"><i class="fa fa-save"></i> Save Draft</button>
        <button type="submit" name="save_action" value="send" class="nl-btn nl-btn-primary"><i class="fa fa-paper-plane"></i> Save &amp; Send</button>
      </div>
    </form>
  </div>
</div>

<!-- PDF -->
<div class="modal-overlay" id="pdfModal">
  <div class="nl-modal pdf-modal">
    <div class="nl-modal-header"><span class="nl-modal-title"><i class="fa fa-file-pdf"></i> Upload PDF Newsletter</span><button class="nl-modal-close" type="button" onclick="NL.closePdfModal()">&times;</button></div>
    <form method="POST" action="newsletters" enctype="multipart/form-data" onsubmit="return NL.preparePdfSubmit(event)" id="pdfNewsletterForm">
      <input type="hidden" name="newsletter_action" value="save_pdf_newsletter">
      <input type="hidden" name="recipient_type" id="pdf_recipient_type" value="startups">
      <input type="hidden" name="recipient_emails" id="pdf_recipient_emails">
      <div class="nl-modal-body">
        <div class="nl-subject-row">
          <div class="nl-form-group"><label>Subject</label><input type="text" name="subject" class="nl-form-control" required></div>
          <div class="nl-form-group"><label>Preheader</label><input type="text" name="preheader" class="nl-form-control"></div>
          <div class="nl-form-group"><label>From Name</label><input type="text" name="from_name" class="nl-form-control" value="<?= h($site_name) ?>"></div>
          <div class="nl-form-group"><label>From Email</label><input type="email" name="from_email" class="nl-form-control" value="<?= h($from_email) ?>"></div>
        </div>
        <div class="nl-form-group"><label>Email Intro</label><textarea name="body_message" class="nl-form-control" rows="3"></textarea></div>
        <div class="pdf-drop-zone" id="pdfDropZone">
          <input type="file" name="newsletter_pdf" id="pdfFileInput" accept=".pdf,application/pdf" onchange="NL.handlePdfPick(this)">
          <div class="pdf-drop-icon"><i class="fa fa-file-pdf"></i></div><h3>Drop PDF here</h3><p>Or click to browse</p>
        </div>
        <div class="pdf-file-card" id="pdfFileCard" style="display:none"><div class="pdf-file-icon"><i class="fa fa-file-pdf"></i></div><div class="pdf-file-info"><div class="pdf-file-name" id="pdfFileName"></div><div class="pdf-file-size" id="pdfFileSize"></div></div><button type="button" class="pdf-file-remove" onclick="NL.removePdf()"><i class="fa fa-times"></i></button></div>
        <div class="pdf-preview-pane" id="pdfPreviewPane" style="display:none"><iframe id="pdfPreviewFrame" title="PDF Preview"></iframe></div>

        <div class="nl-palette-label nl-mt">Recipients</div>
        <div class="rcpt-tabs">
          <button type="button" class="rcpt-tab active" onclick="NL.switchRcpt('startups',this,'pdf')">Startups</button>
          <button type="button" class="rcpt-tab" onclick="NL.switchRcpt('upload',this,'pdf')">CSV</button>
          <button type="button" class="rcpt-tab" onclick="NL.switchRcpt('saved',this,'pdf')">Saved</button>
          <button type="button" class="rcpt-tab" onclick="NL.switchRcpt('subscribers',this,'pdf')">Subs</button>
          <button type="button" class="rcpt-tab" onclick="NL.switchRcpt('manual',this,'pdf')">Manual</button>
        </div>
        <div class="rcpt-panel active" id="pdf_rcpt_startups"><div class="startup-list"><?php foreach ($startups_emails as $s): ?><label class="startup-item"><input type="checkbox" class="pdf-startup-chk" value="<?= h($s['email']) ?>"><div><div class="startup-name"><?= h($s['name']) ?></div><div class="startup-email"><?= h($s['email']) ?></div></div></label><?php endforeach; ?></div></div>
        <div class="rcpt-panel" id="pdf_rcpt_upload"><input type="file" class="nl-form-control" accept=".csv,.txt" onchange="NL.parseEmailFile(this,'pdf')"><div id="pdfUploadedEmailsInfo" class="nl-muted-xs"></div></div>
        <div class="rcpt-panel" id="pdf_rcpt_saved"><div class="startup-list"><?php foreach ($saved_recipients as $s): ?><label class="startup-item"><input type="checkbox" class="pdf-saved-chk" value="<?= h($s['email']) ?>"><div><div class="startup-name"><?= h($s['name'] ?: $s['email']) ?></div><div class="startup-email"><?= h($s['email']) ?></div></div></label><?php endforeach; ?></div></div>
        <div class="rcpt-panel" id="pdf_rcpt_subscribers"><p class="nl-muted-sm">Send to all <?= $sub_count ?> active subscribers.</p></div>
        <div class="rcpt-panel" id="pdf_rcpt_manual"><div class="email-tags-box" id="pdfEmailTagsBox" onclick="document.getElementById('pdfEtInput').focus()"><input type="text" id="pdfEtInput" class="etag-input" placeholder="you@example.com" onkeydown="NL.handleEmailTag(event,'pdf')"></div><div id="pdfManualCount" class="nl-muted-xs">0 emails</div></div>
      </div>
      <div class="nl-modal-footer">
        <button type="button" class="nl-btn nl-btn-secondary" onclick="NL.closePdfModal()">Cancel</button>
        <button type="submit" name="save_action" value="draft" formnovalidate class="nl-btn nl-btn-secondary"><i class="fa fa-save"></i> Save Draft</button>
        <button type="submit" name="save_action" value="send" class="nl-btn nl-btn-primary"><i class="fa fa-paper-plane"></i> Save &amp; Send</button>
      </div>
    </form>
  </div>
</div>

<!-- SUBSCRIBERS -->
<div class="modal-overlay" id="subsModal">
  <div class="nl-modal subs-modal">
    <div class="nl-modal-header"><span class="nl-modal-title">Newsletter Subscribers</span><button class="nl-modal-close" type="button" onclick="NL.closeModal('subsModal')">&times;</button></div>
    <div class="nl-modal-body nl-no-pad">
      <table class="subs-table">
        <thead><tr><th>Email</th><th>Name</th><th>Status</th><th>Joined</th></tr></thead>
        <tbody>
        <?php foreach ($subscriber_rows as $s): ?>
          <tr><td><?= h($s['email']) ?></td><td><?= h($s['name'] ?? '-') ?></td><td><span class="nl-badge <?= ($s['status']??'')==='active'?'badge-sent':'badge-gray' ?>"><?= h(ucfirst((string)($s['status']??'inactive'))) ?></span></td><td><?= !empty($s['subscribed_at'])?h(date('M j, Y',strtotime((string)$s['subscribed_at']))):'-' ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$subscriber_rows): ?><tr><td colspan="4"><div class="nl-empty nl-empty-small">No subscribers yet.</div></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- DUPLICATE -->
<div class="modal-overlay" id="duplicateNewsletterModal">
  <div class="nl-modal" style="max-width:470px">
    <div class="nl-modal-header"><span class="nl-modal-title"><i class="fa fa-copy"></i> Duplicate Newsletter</span><button class="nl-modal-close" type="button" onclick="NL.closeDuplicateNewsletter()">&times;</button></div>
    <div class="nl-modal-body">
      <h3 style="margin:0 0 8px">Create a new draft copy?</h3>
      <p style="color:#6b7280;line-height:1.6">The design and content will be copied into the editor. The original newsletter will remain unchanged.</p>
      <div id="duplicateNewsletterSubject" style="padding:10px 12px;background:#f8fafc;border-radius:9px;font-weight:700"></div>
    </div>
    <div class="nl-modal-footer"><button type="button" class="nl-btn nl-btn-secondary" onclick="NL.closeDuplicateNewsletter()">Cancel</button><button type="button" class="nl-btn nl-btn-primary" onclick="NL.confirmDuplicateNewsletter()"><i class="fa fa-copy"></i> Duplicate</button></div>
  </div>
</div>

<!-- DELETE -->
<div class="modal-overlay" id="newsletterDeleteModal">
  <div class="nl-modal" style="max-width:430px">
    <div class="nl-modal-header"><span class="nl-modal-title"><i class="fa fa-trash"></i> Delete Newsletter</span><button class="nl-modal-close" type="button" onclick="NL.cancelDeleteNewsletter()">&times;</button></div>
    <div class="nl-modal-body"><p>Delete this newsletter permanently? This cannot be undone.</p></div>
    <div class="nl-modal-footer"><button type="button" class="nl-btn nl-btn-secondary" onclick="NL.cancelDeleteNewsletter()">Cancel</button><button type="button" class="nl-btn nl-btn-danger" onclick="NL.executeDeleteNewsletter()"><i class="fa fa-trash"></i> Delete</button></div>
  </div>
</div>

<!-- SMTP TEST -->
<div class="modal-overlay" id="smtpTestModal">
  <div class="nl-modal" style="max-width:460px">
    <div class="nl-modal-header">
      <span class="nl-modal-title">
        <i class="fa fa-paper-plane"></i>
        Test SMTP Email
      </span>

      <button
        class="nl-modal-close"
        type="button"
        onclick="document.getElementById('smtpTestModal').classList.remove('active')"
      >&times;</button>
    </div>

    <form
      method="POST"
      action="newsletters"
    >
      <input
        type="hidden"
        name="newsletter_action"
        value="test_email"
      >

      <div class="nl-modal-body">
        <div class="nl-form-group">
          <label>Recipient Email</label>
          <input
            type="email"
            name="email"
            class="nl-form-control"
            placeholder="you@example.com"
            required
          >
        </div>

        <p class="nl-muted-sm">
          This sends one test message using the active PHPMailer SMTP settings.
        </p>
      </div>

      <div class="nl-modal-footer">
        <button
          type="button"
          class="nl-btn nl-btn-secondary"
          onclick="document.getElementById('smtpTestModal').classList.remove('active')"
        >
          Cancel
        </button>

        <button
          type="submit"
          class="nl-btn nl-btn-primary"
        >
          <i class="fa fa-paper-plane"></i>
          Send Test Email
        </button>
      </div>
    </form>
  </div>
</div>

<!-- PREVIEW -->
<div class="modal-overlay" id="previewModal">
  <div class="nl-modal nl-preview-dialog">
    <div class="nl-modal-header">
      <span class="nl-modal-title"><i class="fa fa-eye"></i> <span id="previewTitle">Newsletter Preview</span></span>
      <div class="nl-preview-actions">
        <span class="nl-muted-xs nl-preview-device-label">View:</span>
        <button class="nl-btn nl-btn-secondary nl-btn-xs active" type="button" id="previewDesktopBtn" onclick="NL.setPreviewMode('desktop')"><i class="fa fa-desktop"></i> Desktop</button>
        <button class="nl-btn nl-btn-secondary nl-btn-xs" type="button" id="previewMobileBtn" onclick="NL.setPreviewMode('mobile')"><i class="fa fa-mobile-alt"></i> Mobile</button>
        <button class="nl-modal-close" type="button" onclick="NL.closePreview()">&times;</button>
      </div>
    </div>
    <div class="nl-preview-body"><iframe id="previewFrame" title="Newsletter Preview" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"></iframe></div>
  </div>
</div>

<div class="nl-remote-loading" id="nlRemoteLoading">
  <div class="nl-remote-loading-box">
    <i class="fa fa-spinner fa-spin"></i>
    <span>Loading newsletter...</span>
  </div>
</div>

<script>
window.NL_PROCESS_URL=window.location.pathname;
window.NL_SITE_URL=<?= json_encode(nl_site_url(),JSON_UNESCAPED_SLASHES) ?>;
window.NL_ADMIN_URL=<?= json_encode(
    (nl_site_url() !== ''
        ? nl_site_url() . '/admin/newsletters'
        : '/admin/newsletters'),
    JSON_UNESCAPED_SLASHES
) ?>;
window.$=function(id){return document.getElementById(id);};
</script>
<?php
/*
|--------------------------------------------------------------------------
| Newsletter JavaScript path
|--------------------------------------------------------------------------
|
| Some deployments store this file directly in:
|   /admin/assets/newsletters.js
|
| while older deployments store it in:
|   /admin/assets/js/newsletters.js
|
| Detect the real file from the server filesystem and use the matching URL.
|--------------------------------------------------------------------------
*/

$newsletter_js_candidates = [
    [
        'file' => __DIR__ . '/assets/newsletters.js',
        'url'  => 'assets/newsletters.js',
    ],
    [
        'file' => __DIR__ . '/assets/js/newsletters.js',
        'url'  => 'assets/js/newsletters.js',
    ],
];

$newsletter_js_file = '';
$newsletter_js_url  = '';

foreach ($newsletter_js_candidates as $candidate) {
    if (is_file($candidate['file'])) {
        $newsletter_js_file = $candidate['file'];
        $newsletter_js_url  = $candidate['url'];
        break;
    }
}

$newsletter_js_version = $newsletter_js_file !== ''
    ? (int)filemtime($newsletter_js_file)
    : time();
?>

<?php if ($newsletter_js_url !== ''): ?>
<script
    src="<?= h($newsletter_js_url) ?>?v=<?= $newsletter_js_version ?>"
    defer
></script>
<?php else: ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    console.error(
        'Newsletter JavaScript file was not found. Checked: ' +
        'admin/assets/newsletters.js and admin/assets/js/newsletters.js'
    );

    var main = document.getElementById('adminMain');

    if (main) {
        var warning = document.createElement('div');

        warning.style.cssText =
            'margin:12px 18px;padding:14px 16px;border-radius:8px;' +
            'background:#fef2f2;color:#991b1b;border:1px solid #fecaca;' +
            'font-size:13px;font-weight:600;line-height:1.5;';

        warning.innerHTML =
            '<strong>Newsletter controls could not load.</strong><br>' +
            'The server could not find <code>admin/assets/newsletters.js</code> ' +
            'or <code>admin/assets/js/newsletters.js</code>.';

        main.prepend(warning);
    }
});
</script>
<?php endif; ?>

<script>
/*
 * Verify that the newsletter controller loaded after the external script.
 * Because the script uses defer, this check runs on DOMContentLoaded after
 * deferred scripts have executed.
 */
document.addEventListener('DOMContentLoaded', function () {
    if (
        <?= $newsletter_js_url !== '' ? 'true' : 'false' ?> &&
        typeof window.NL === 'undefined'
    ) {
        console.error(
            'Newsletter JavaScript was found but window.NL was not created.'
        );

        var main = document.getElementById('adminMain');

        if (main) {
            var warning = document.createElement('div');

            warning.style.cssText =
                'margin:12px 18px;padding:14px 16px;border-radius:8px;' +
                'background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;' +
                'font-size:13px;font-weight:600;line-height:1.5;';

            warning.textContent =
                'Newsletter JavaScript loaded but could not initialise. ' +
                'Open the browser console for the exact JavaScript error.';

            main.prepend(warning);
        }
    }
});
</script>


<script>
window.NLRemote = (function () {
    'use strict';

    function loading(show) {
        var el = document.getElementById('nlRemoteLoading');

        if (el) {
            el.classList.toggle('show', !!show);
        }
    }

    async function getNewsletter(id) {
        loading(true);

        try {
            var url =
                window.location.pathname
                + '?newsletter_action=get_newsletter'
                + '&ajax=1'
                + '&id='
                + encodeURIComponent(id);

            var response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            });

            var data = await response.json();

            if (!response.ok || !data.ok || !data.newsletter) {
                throw new Error(
                    data.message
                    || 'Newsletter could not be loaded.'
                );
            }

            return data.newsletter;
        } finally {
            loading(false);
        }
    }

    function showError(error) {
        console.error(error);

        var message =
            error.message
            || 'Newsletter could not be loaded.';

        if (
            window.NL
            && typeof window.NL.showToast === 'function'
        ) {
            window.NL.showToast(
                message,
                'error'
            );
            return;
        }

        var old =
            document.getElementById(
                'nlRemoteErrorToast'
            );

        if (old) {
            old.remove();
        }

        var toast =
            document.createElement(
                'div'
            );

        toast.id =
            'nlRemoteErrorToast';

        toast.style.cssText =
            'position:fixed;right:20px;top:20px;z-index:1000000;' +
            'max-width:420px;padding:13px 16px;border-radius:10px;' +
            'background:#fef2f2;color:#991b1b;border:1px solid #fecaca;' +
            'box-shadow:0 14px 35px rgba(15,23,42,.18);' +
            'font:600 13px/1.5 Inter,Arial,sans-serif;';

        toast.innerHTML =
            '<div style="display:flex;gap:10px;align-items:flex-start">' +
            '<i class="fa fa-exclamation-circle" style="margin-top:2px"></i>' +
            '<div style="flex:1"></div>' +
            '<button type="button" aria-label="Close" ' +
            'style="border:0;background:transparent;color:#991b1b;cursor:pointer;font-size:18px;line-height:1">&times;</button>' +
            '</div>';

        toast.querySelector(
            'div > div'
        ).textContent =
            message;

        toast.querySelector(
            'button'
        ).addEventListener(
            'click',
            function () {
                toast.remove();
            }
        );

        document.body.appendChild(
            toast
        );

        setTimeout(
            function () {
                toast.remove();
            },
            6000
        );
    }

    return {
        edit: async function (id) {
            try {
                var newsletter = await getNewsletter(id);

                if (
                    window.NL
                    && typeof window.NL.editNewsletter === 'function'
                ) {
                    window.NL.editNewsletter(newsletter);
                }
            } catch (error) {
                showError(error);
            }
        },

        preview: async function (id) {
            try {
                var newsletter = await getNewsletter(id);

                if (
                    window.NL
                    && typeof window.NL.previewNewsletterData === 'function'
                ) {
                    window.NL.previewNewsletterData(newsletter);
                }
            } catch (error) {
                showError(error);
            }
        },

        duplicate: async function (id) {
            try {
                var newsletter = await getNewsletter(id);

                if (
                    window.NL
                    && typeof window.NL.openDuplicateNewsletter === 'function'
                ) {
                    window.NL.openDuplicateNewsletter(newsletter);
                }
            } catch (error) {
                showError(error);
            }
        }
    };
})();
</script>


<script>

window.NLGoToAdmin = function (tab) {
    tab =
        tab === 'hero'
            ? 'hero'
            : 'newsletters';

    var base =
        window.NL_ADMIN_URL
        || '/admin/newsletters';

    window.location.assign(
        base
        + '?tab='
        + encodeURIComponent(tab)
    );
};
</script>

</body>
</html>
