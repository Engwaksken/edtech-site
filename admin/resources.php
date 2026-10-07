<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('DB error.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$site_url = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';

function table_has_column(mysqli $conn, string $table, string $column): bool {
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $q && $q->num_rows > 0;
}

function table_exists(mysqli $conn, string $table): bool {
    $table = $conn->real_escape_string($table);
    $q = $conn->query("SHOW TABLES LIKE '$table'");
    return $q && $q->num_rows > 0;
}

function admin_res_setting(mysqli $conn, string $key, string $default = ''): string {
    if (function_exists('get_setting')) {
        return (string)get_setting($conn, $key, $default);
    }
    $safe = $conn->real_escape_string($key);
    $q = $conn->query("SELECT setting_value FROM site_settings WHERE setting_key='$safe' LIMIT 1");
    if ($q && ($row = $q->fetch_assoc())) {
        return (string)($row['setting_value'] ?? $default);
    }
    return $default;
}

/*
|--------------------------------------------------------------------------
| Download tracking table
|--------------------------------------------------------------------------
*/
$conn->query("
CREATE TABLE IF NOT EXISTS resource_download_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    resource_id INT UNSIGNED NOT NULL,
    venture_id INT UNSIGNED NULL,
    downloaded_by_name VARCHAR(190) NULL,
    downloaded_by_email VARCHAR(190) NULL,
    ip_address VARCHAR(80) NULL,
    user_agent TEXT NULL,
    downloaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_resource_id (resource_id),
    KEY idx_venture_id (venture_id),
    KEY idx_downloaded_at (downloaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$site_name = admin_res_setting($conn, 'site_name', 'EdTech Fellowship');

$tab = $_GET['tab'] ?? 'resources';
$allowed_tabs = ['resources', 'requests', 'settings'];
if (!in_array($tab, $allowed_tabs, true)) {
    $tab = 'resources';
}

$hasUploadedBy = table_has_column($conn, 'resources', 'uploaded_by');
$hasUpdatedBy  = table_has_column($conn, 'resources', 'updated_by');
$hasUpdatedAt  = table_has_column($conn, 'resources', 'updated_at');

$adminTable = 'admins';
$adminNameCol = 'full_name';

if (!table_exists($conn, 'admins')) {
    $adminTable = 'admin_users';
}
if (!table_has_column($conn, $adminTable, 'full_name')) {
    $adminNameCol = table_has_column($conn, $adminTable, 'name') ? 'name' : 'username';
}

$res_count = (int)($conn->query("SELECT COUNT(*) c FROM resources")->fetch_assoc()['c'] ?? 0);
$pending   = (int)($conn->query("SELECT COUNT(*) c FROM resource_requests WHERE status='pending'")->fetch_assoc()['c'] ?? 0);
$approved  = (int)($conn->query("SELECT COUNT(*) c FROM resource_requests WHERE status='approved'")->fetch_assoc()['c'] ?? 0);
$downloads = (int)($conn->query("SELECT COALESCE(SUM(download_count),0) c FROM resources")->fetch_assoc()['c'] ?? 0);

$uploadedJoin   = $hasUploadedBy ? "LEFT JOIN `$adminTable` up ON up.id = r.uploaded_by" : "";
$updatedJoin    = $hasUpdatedBy  ? "LEFT JOIN `$adminTable` ud ON ud.id = r.updated_by" : "";
$uploadedSelect = $hasUploadedBy ? ", up.`$adminNameCol` AS uploaded_by_name" : ", NULL AS uploaded_by_name";
$updatedSelect  = $hasUpdatedBy  ? ", ud.`$adminNameCol` AS updated_by_name" : ", NULL AS updated_by_name";

$resources = $conn->query("
    SELECT
        r.*
        $uploadedSelect
        $updatedSelect,
        (SELECT COUNT(*) FROM resource_download_logs rdl WHERE rdl.resource_id = r.id) AS tracked_downloads
    FROM resources r
    $uploadedJoin
    $updatedJoin
    ORDER BY r.sort_order ASC, r.created_at DESC
");

$requests = $conn->query("
    SELECT rr.*, r.title resource_title, r.type resource_type, r.file_name
    FROM resource_requests rr
    LEFT JOIN resources r ON r.id = rr.resource_id
    ORDER BY rr.created_at DESC
");

$cats_res = $conn->query("SELECT DISTINCT category FROM resources WHERE category!='' ORDER BY category");
$categories = [];
if ($cats_res) {
    while ($c = $cats_res->fetch_assoc()) {
        $categories[] = $c['category'];
    }
}

/* --- Hero settings --- */
$hero_bg_type = admin_res_setting($conn, 'resources_hero_bg_type', 'color');
if (!in_array($hero_bg_type, ['color', 'image', 'video'], true)) {
    $hero_bg_type = 'color';
}

$hero_bg_color = admin_res_setting($conn, 'resources_hero_bg_color', '#fff5eb');
if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $hero_bg_color)) {
    $hero_bg_color = '#fff5eb';
}

$hero_bg_image        = admin_res_setting($conn, 'resources_hero_bg_image', '');
$hero_bg_video        = admin_res_setting($conn, 'resources_hero_bg_video', '');
$hero_overlay         = admin_res_setting($conn, 'resources_hero_bg_overlay', '1');
$hero_overlay_color   = admin_res_setting($conn, 'resources_hero_bg_overlay_color', '#000000');
if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $hero_overlay_color)) {
    $hero_overlay_color = '#000000';
}
$hero_overlay_opacity = (int)admin_res_setting($conn, 'resources_hero_bg_overlay_opacity', '35');
$hero_overlay_opacity = max(0, min(100, $hero_overlay_opacity));
$hero_label    = admin_res_setting($conn, 'resources_hero_label', 'Resources');
$hero_title    = admin_res_setting($conn, 'resources_hero_title', 'Venture Resources');
$hero_subtitle = admin_res_setting($conn, 'resources_hero_subtitle', 'Access guides, templates, videos, and documents curated for ' . $site_name . ' ventures.');

$preview_style = 'background:' . h($hero_bg_color) . ';';
if ($hero_bg_type === 'image' && $hero_bg_image !== '') {
    $preview_style = "background-image:url('" . rtrim(SITE_URL, '/') . '/' . h($hero_bg_image) . "');background-size:cover;background-position:center;";
}

$favicon_path = rtrim(SITE_URL, '/') . '/assets/images/favicon.png';

/* --- Download logs --- */
$downloadLogs = [];
$logsRes = $conn->query("
    SELECT rdl.*,
        r.title AS resource_title, r.file_name,
        v.name AS venture_name, v.logo AS venture_logo,
        v.cofounder1_name, v.cofounder1_email,
        v.cofounder2_name, v.cofounder2_email
    FROM resource_download_logs rdl
    LEFT JOIN resources r ON r.id = rdl.resource_id
    LEFT JOIN ventures v  ON v.id = rdl.venture_id
    ORDER BY rdl.downloaded_at DESC
");
if ($logsRes) {
    while ($log = $logsRes->fetch_assoc()) {
        $rid = (int)$log['resource_id'];
        if (!isset($downloadLogs[$rid])) $downloadLogs[$rid] = [];
        $downloadLogs[$rid][] = $log;
    }
}

/* --- Collect rows into JS-friendly arrays --- */
$resourceRows  = [];
$requestRows   = [];

if ($resources) {
    while ($r = $resources->fetch_assoc()) {
        $type  = $r['type'] ?? 'other';
        $icons = ['pdf'=>'fa-file-pdf','video'=>'fa-film','image'=>'fa-image','document'=>'fa-file-word','other'=>'fa-file'];
        $icon  = $icons[$type] ?? 'fa-file';

        $fileSize = (int)($r['file_size'] ?? 0);
        $fsize = '';
        if ($fileSize > 0) {
            $fsize = $fileSize > 1048576
                ? round($fileSize / 1048576, 1) . 'MB'
                : round($fileSize / 1024) . 'KB';
        }

        $realPath = !empty($r['file_path']) ? realpath(__DIR__ . '/../' . $r['file_path']) : false;
        $fileOk   = !empty($r['file_path']) && $realPath && is_file($realPath);

        $trackedDownloads = (int)($r['tracked_downloads'] ?? 0);
        $storedDownloads  = (int)($r['download_count'] ?? 0);
        $displayDownloads = max($trackedDownloads, $storedDownloads);

        $resourceRows[] = [
            'id'               => (int)$r['id'],
            'title'            => $r['title'] ?? '',
            'description'      => $r['description'] ?? '',
            'type'             => $type,
            'icon'             => $icon,
            'category'         => $r['category'] ?? '',
            'access_level'     => $r['access_level'] ?? '',
            'status'           => $r['status'] ?? '',
            'sort_order'       => (int)($r['sort_order'] ?? 0),
            'file_name'        => $r['file_name'] ?? '',
            'file_path'        => $r['file_path'] ?? '',
            'file_ext'         => strtolower(pathinfo((string)($r['file_name'] ?? $r['file_path'] ?? ''), PATHINFO_EXTENSION)),
            'file_ok'          => $fileOk,
            'fsize'            => $fsize,
            'tags'             => $r['tags'] ?? '',
            'uploaded_by_name' => $r['uploaded_by_name'] ?? '',
            'updated_by_name'  => $r['updated_by_name'] ?? '',
            'created_at'       => $r['created_at'] ?? '',
            'updated_at'       => ($hasUpdatedAt ? ($r['updated_at'] ?? '') : ''),
            'tracked_downloads'=> $trackedDownloads,
            'display_downloads'=> $displayDownloads,
            'row_data'         => $r,
        ];
    }
}

if ($requests) {
    while ($req = $requests->fetch_assoc()) {
        $requestRows[] = $req;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Resources - <?= h($site_name) ?> Admin</title>
<link rel="icon" href="<?= h($favicon_path) ?>" type="image/png">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="assets/css/admin.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<style>
/* Resources tables: keep columns readable and scroll on narrow screens. */
.resources-card { overflow: hidden; }
.resources-table-wrap {
    width: 100%;
    overflow-x: auto;
    overflow-y: visible;
    -webkit-overflow-scrolling: touch;
    scrollbar-gutter: stable;
}
.resources-table {
    width: 100%;
    min-width: 1180px;
    table-layout: fixed;
    border-collapse: separate;
    border-spacing: 0;
}
.requests-table { min-width: 960px; }
.resources-table th,
.resources-table td {
    box-sizing: border-box;
    padding: 14px 12px;
    vertical-align: middle;
    text-align: left;
    overflow: visible;
    overflow-wrap: normal;
    word-break: normal;
    white-space: normal;
}
.resources-table th {
    white-space: nowrap;
}
.resource-cell {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    min-width: 0;
}
.resource-cell__content { min-width: 0; flex: 1; }
.resource-cell__title,
.resource-cell__description,
.table-text-short {
    display: block;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.resource-cell__description {
    margin-top: 4px;
    color: #64748b;
    font-size: 12px;
    line-height: 1.4;
}
.table-text-tooltip {
    position: relative;
    cursor: help;
    outline: none;
}
.table-text-tooltip::before,
.table-text-tooltip::after {
    position: absolute;
    left: 0;
    z-index: 1000;
    visibility: hidden;
    opacity: 0;
    pointer-events: none;
    transition: opacity .16s ease, transform .16s ease, visibility .16s ease;
}
.table-text-tooltip::before {
    content: attr(data-full-text);
    bottom: calc(100% + 9px);
    width: max-content;
    min-width: 140px;
    max-width: min(360px, 70vw);
    padding: 9px 11px;
    border-radius: 8px;
    background: #172033;
    color: #fff;
    box-shadow: 0 10px 30px rgba(15, 23, 42, .2);
    font-size: 12px;
    font-weight: 500;
    line-height: 1.45;
    white-space: normal;
    overflow-wrap: anywhere;
    transform: translateY(4px);
}
.table-text-tooltip::after {
    content: '';
    bottom: calc(100% + 3px);
    border: 6px solid transparent;
    border-top-color: #172033;
}
.table-text-tooltip:hover::before,
.table-text-tooltip:hover::after,
.table-text-tooltip:focus-visible::before,
.table-text-tooltip:focus-visible::after {
    visibility: visible;
    opacity: 1;
    transform: translateY(0);
}
.resources-table .tbl-actions {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: nowrap;
}
.resources-table .tbl-actions .btn { flex: 0 0 auto; }
.resources-table .meta-small {
    display: block;
    margin-top: 3px;
    white-space: nowrap;
}
@media (max-width: 767px) {
    .resources-table-wrap { margin: 0; }
    .resources-table th,
    .resources-table td { padding: 12px 10px; }
    .table-text-tooltip::before { position: fixed; left: 16px; right: 16px; bottom: 18px; width: auto; max-width: none; }
    .table-text-tooltip::after { display: none; }
}
</style>

</head>

<body>
<?php include 'includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">
<header class="admin-topbar">
    <div class="topbar-left">
        <div class="topbar-breadcrumb">
            <a href="index.php">Dashboard</a> &gt; <strong>Resources</strong>
        </div>
    </div>
    <div class="topbar-right">
        <a href="<?= h(rtrim(SITE_URL, '/')) ?>/resources" target="_blank" class="btn btn-secondary btn-sm">
            <i class="fa fa-eye"></i> View Resources
        </a>
    </div>
</header>

<div class="admin-content">
<?php show_flash('resources'); ?>

<div class="stats-grid legacy-style-8677744d08">
    <div class="stat-card">
        <div class="stat-icon teal"><i class="fa fa-folder-open"></i></div>
        <div><div class="stat-val"><?= $res_count ?></div><div class="stat-label">Resources</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange"><i class="fa fa-clock"></i></div>
        <div><div class="stat-val"><?= $pending ?></div><div class="stat-label">Pending Requests</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fa fa-check-circle"></i></div>
        <div><div class="stat-val"><?= $approved ?></div><div class="stat-label">Approved</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fa fa-download"></i></div>
        <div><div class="stat-val"><?= $downloads ?></div><div class="stat-label">Total Downloads</div></div>
    </div>
</div>

<div class="page-header">
    <div>
        <h1 class="page-title">Resources</h1>
        <p class="page-subtitle">Upload resources, review access requests, and manage the frontend hero banner.</p>
    </div>
    <button class="btn btn-primary" onclick="openResourceModal()" type="button">
        <i class="fa fa-plus"></i> Upload Resource
    </button>
</div>

<div class="tabs legacy-style-13f24e7378">
    <button class="tab-btn <?= $tab === 'resources' ? 'active' : '' ?>" onclick="setTab('resources',this)" type="button">
        <i class="fa fa-folder"></i> Resources
        <span class="badge badge-gray legacy-style-391ef1246f"><?= $res_count ?></span>
    </button>
    <button class="tab-btn <?= $tab === 'requests' ? 'active' : '' ?>" onclick="setTab('requests',this)" type="button">
        <i class="fa fa-inbox"></i> Access Requests
        <?php if ($pending): ?>
            <span class="badge badge-warning legacy-style-391ef1246f"><?= $pending ?></span>
        <?php endif; ?>
    </button>
    <button class="tab-btn <?= $tab === 'settings' ? 'active' : '' ?>" onclick="setTab('settings',this)" type="button">
        <i class="fa fa-image"></i> Hero Banner
    </button>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB: Resources
     ══════════════════════════════════════════════════════════════════════ -->
<div id="tab_resources" class="tab-content <?= $tab === 'resources' ? 'active' : '' ?>">

    <div class="tab-toolbar">
        <div class="search-wrap">
            <i class="fa fa-search"></i>
            <input type="text" id="resSearch" placeholder="Search title, category, tag..." oninput="resFilter()" autocomplete="off">
        </div>

        <select class="filter-select" id="resTypeFilter" onchange="resFilter()">
            <option value="">All types</option>
            <option value="pdf">PDF</option>
            <option value="video">Video</option>
            <option value="image">Image</option>
            <option value="document">Document</option>
            <option value="other">Other</option>
        </select>

        <select class="filter-select" id="resStatusFilter" onchange="resFilter()">
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>

        <select class="filter-select" id="resAccessFilter" onchange="resFilter()">
            <option value="">All access</option>
            <option value="public">Public</option>
            <option value="request_required">Request Required</option>
        </select>

        <span class="result-count" id="resResultCount"></span>
    </div>

    <div class="card resources-card">
        <div class="table-wrap resources-table-wrap">
            <table id="resTable" class="resources-table">
                <colgroup>
                    <col style="width:270px"><col style="width:90px"><col style="width:125px">
                    <col style="width:145px"><col style="width:95px"><col style="width:105px">
                    <col style="width:125px"><col style="width:125px"><col style="width:100px">
                </colgroup>
                <thead>
                    <tr>
                        <th>Resource</th>
                        <th>Type</th>
                        <th>Category</th>
                        <th>Access</th>
                        <th>Status</th>
                        <th>Downloads</th>
                        <th>Uploaded By</th>
                        <th>Updated By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="resTableBody"></tbody>
            </table>
        </div>

        <div class="pagination-bar legacy-style-30031eba09" id="resPagBar">
            <div class="per-page-wrap">
                Show
                <select onchange="resSetPerPage(+this.value)">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
                per page
            </div>
            <div class="page-info" id="resPageInfo"></div>
            <div class="pagination-btns" id="resPagBtns"></div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB: Requests
     ══════════════════════════════════════════════════════════════════════ -->
<div id="tab_requests" class="tab-content <?= $tab === 'requests' ? 'active' : '' ?>">

    <div class="tab-toolbar">
        <div class="search-wrap">
            <i class="fa fa-search"></i>
            <input type="text" id="reqSearch" placeholder="Search requester, email, resource..." oninput="reqFilter()" autocomplete="off">
        </div>

        <select class="filter-select" id="reqStatusFilter" onchange="reqFilter()">
            <option value="">All statuses</option>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
        </select>

        <span class="result-count" id="reqResultCount"></span>
    </div>

    <div class="card resources-card">
        <div class="table-wrap resources-table-wrap">
            <table id="reqTable" class="resources-table requests-table">
                <colgroup>
                    <col style="width:190px"><col style="width:190px"><col style="width:230px">
                    <col style="width:125px"><col style="width:135px"><col style="width:110px">
                </colgroup>
                <thead>
                    <tr>
                        <th>Requester</th>
                        <th>Resource</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Requested</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="reqTableBody"></tbody>
            </table>
        </div>

        <div class="pagination-bar legacy-style-30031eba09" id="reqPagBar">
            <div class="per-page-wrap">
                Show
                <select onchange="reqSetPerPage(+this.value)">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
                per page
            </div>
            <div class="page-info" id="reqPageInfo"></div>
            <div class="pagination-btns" id="reqPagBtns"></div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB: Settings / Hero Banner
     ══════════════════════════════════════════════════════════════════════ -->
<div id="tab_settings" class="tab-content <?= $tab === 'settings' ? 'active' : '' ?>">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fa fa-image legacy-style-ac3e88a561"></i>
                Resources Hero Banner
            </h3>
        </div>

        <div class="card-body">
            <div class="hero-settings-preview" style="<?= $preview_style ?>">
                <?php if ($hero_bg_type === 'video' && $hero_bg_video !== ''): ?>
                    <video autoplay muted loop playsinline>
                        <source src="<?= h(rtrim(SITE_URL, '/') . '/' . $hero_bg_video) ?>">
                    </video>
                <?php endif; ?>
                <div class="hero-settings-preview__overlay"></div>
                <div class="hero-settings-preview__content">
                    <span class="hero-settings-preview__badge">
                        <i class="fa fa-folder-open"></i><?= h(strtoupper($hero_bg_type)) ?> Hero
                    </span>
                    <h2><?= h($hero_title ?: 'Venture Resources') ?></h2>
                    <p><?= h($hero_subtitle ?: 'Preview how the resources page hero appears on the frontend.') ?></p>
                </div>
            </div>

            <form method="POST" action="includes/process-resources.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_hero_banner">

                <div class="hero-settings-grid">
                    <div class="form-group full">
                        <label>Hero Background Type</label>
                        <div class="bg-type-options">
                            <label class="bg-type-option">
                                <input type="radio" name="resources_hero_bg_type" value="color" <?= $hero_bg_type === 'color' ? 'checked' : '' ?>>
                                <span><i class="fa fa-palette"></i> Solid Color</span>
                            </label>
                            <label class="bg-type-option">
                                <input type="radio" name="resources_hero_bg_type" value="image" <?= $hero_bg_type === 'image' ? 'checked' : '' ?>>
                                <span><i class="fa fa-image"></i> Image</span>
                            </label>
                            <label class="bg-type-option">
                                <input type="radio" name="resources_hero_bg_type" value="video" <?= $hero_bg_type === 'video' ? 'checked' : '' ?>>
                                <span><i class="fa fa-film"></i> Video</span>
                            </label>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Hero Label</label>
                        <input type="text" name="resources_hero_label" class="form-control" value="<?= h($hero_label) ?>">
                    </div>

                    <div class="form-group">
                        <label>Solid Background Color</label>
                        <div class="color-row">
                            <input type="color" value="<?= h($hero_bg_color) ?>" id="heroBgColorPicker" oninput="syncHeroBgColor()">
                            <input type="text" name="resources_hero_bg_color" id="heroBgColorText" class="form-control" value="<?= h($hero_bg_color) ?>" oninput="syncHeroBgColorText()">
                        </div>
                    </div>

                    <div class="form-group full">
                        <label>Hero Title</label>
                        <input type="text" name="resources_hero_title" class="form-control" value="<?= h($hero_title) ?>">
                    </div>

                    <div class="form-group full">
                        <label>Hero Subtitle</label>
                        <textarea name="resources_hero_subtitle" class="form-control" rows="3"><?= h($hero_subtitle) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label>Upload Hero Banner Image</label>
                        <?php if ($hero_bg_image !== ''): ?>
                            <img src="<?= h(rtrim(SITE_URL, '/') . '/' . $hero_bg_image) ?>" class="hero-mini-preview" alt="Current hero image">
                        <?php endif; ?>
                        <input type="file" name="resources_hero_bg_image_file" class="form-control" accept="image/*">
                        <span class="form-hint">Recommended: 1920x700px or wider.</span>
                    </div>

                    <div class="form-group">
                        <label>Upload Hero Banner Video</label>
                        <?php if ($hero_bg_video !== ''): ?>
                            <div class="hero-video-preview">
                                <video autoplay muted loop playsinline>
                                    <source src="<?= h(rtrim(SITE_URL, '/') . '/' . $hero_bg_video) ?>">
                                </video>
                            </div>
                        <?php endif; ?>
                        <input type="file" name="resources_hero_bg_video_file" class="form-control" accept="video/mp4,video/webm,video/ogg">
                    </div>

                    <div class="form-group">
                        <label>Overlay</label>
                        <label class="legacy-style-cda9eb406d">
                            <input type="checkbox" name="resources_hero_bg_overlay" value="1" <?= $hero_overlay === '1' ? 'checked' : '' ?>>
                            Enable overlay for image/video
                        </label>
                    </div>

                    <div class="form-group">
                        <label>Overlay Color</label>
                        <div class="color-row">
                            <input type="color" value="<?= h($hero_overlay_color) ?>" id="heroOverlayColorPicker" oninput="syncHeroOverlayColor()">
                            <input type="text" name="resources_hero_bg_overlay_color" id="heroOverlayColorText" class="form-control" value="<?= h($hero_overlay_color) ?>" oninput="syncHeroOverlayColorText()">
                        </div>
                    </div>

                    <div class="form-group full">
                        <label>Overlay Opacity (%)</label>
                        <div class="range-row">
                            <input type="range" min="0" max="100" step="1" value="<?= h((string)$hero_overlay_opacity) ?>" id="heroOverlayRange" oninput="syncHeroOverlayOpacity()">
                            <input type="number" min="0" max="100" name="resources_hero_bg_overlay_opacity" class="form-control" value="<?= h((string)$hero_overlay_opacity) ?>" id="heroOverlayNumber" oninput="syncHeroOverlayOpacityNumber()">
                        </div>
                    </div>
                </div>

                <div class="modal-footer legacy-style-d2755826fa">
                    <?php if ($hero_bg_image !== ''): ?>
                        <button type="submit" name="action" value="remove_hero_image" class="btn btn-danger" onclick="return confirm('Remove hero image?')">
                            <i class="fa fa-times"></i> Remove Image
                        </button>
                    <?php endif; ?>
                    <?php if ($hero_bg_video !== ''): ?>
                        <button type="submit" name="action" value="remove_hero_video" class="btn btn-danger" onclick="return confirm('Remove hero video?')">
                            <i class="fa fa-times"></i> Remove Video
                        </button>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-save"></i> Save Hero Banner
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

</div><!-- /admin-content -->
</div><!-- /admin-main -->

<!-- ══════════════════════════════════════════════════════════════════════
     MODALS
     ══════════════════════════════════════════════════════════════════════ -->

<!-- Upload / Edit Resource -->
<div class="modal-overlay" id="resourceModal">
<div class="modal modal-lg">
<div class="modal-header">
    <h2 class="modal-title" id="resModalTitle">Upload Resource</h2>
    <button class="modal-close" onclick="document.getElementById('resourceModal').classList.remove('open')" type="button">x</button>
</div>
<form method="POST" action="includes/process-resources.php" enctype="multipart/form-data">
<input type="hidden" name="action" value="save">
<input type="hidden" name="id" id="res_id">
<input type="hidden" name="existing_file" id="res_existing_file">
<div class="modal-body">
<div class="form-grid form-grid-2">
    <div class="form-group full">
        <label>Title <span class="req">*</span></label>
        <input type="text" name="title" id="res_title" class="form-control" required>
    </div>
    <div class="form-group full">
        <label>Description</label>
        <textarea name="description" id="res_description" class="form-control" rows="3"></textarea>
    </div>
    <div class="form-group full" id="fileDropWrap">
        <label>File <span class="req" id="fileReq">*</span></label>
        <div class="file-drop-zone" id="fileDropZone" onclick="document.getElementById('resFile').click()" ondragover="this.classList.add('drag-over');event.preventDefault()" ondragleave="this.classList.remove('drag-over')" ondrop="handleFileDrop(event)">
            <i class="fa fa-cloud-upload-alt"></i>
            <p>Drag &amp; drop or <strong>click to browse</strong></p>
            <p class="legacy-style-413c9d6fea">PDF . Video . Images . Word . Excel . PowerPoint . ZIP . Text</p>
        </div>
        <input type="file" id="resFile" name="resource_file" accept=".pdf,.mp4,.mov,.avi,.webm,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.odt,.rtf,.ppt,.pptx,.xls,.xlsx,.ods,.csv,.zip,.txt" onchange="handleFileSelect(this)" class="legacy-style-6b99de8b69">
        <div id="fileInfo" class="alert alert-info legacy-style-66253c00ea"></div>
        <div id="editingFile" class="legacy-style-e9a3df4c2f"></div>
    </div>
    <div class="form-group">
        <label>Type</label>
        <select name="type" id="res_type" class="form-control">
            <option value="pdf">PDF</option>
            <option value="video">Video</option>
            <option value="image">Image</option>
            <option value="document">Document</option>
            <option value="other">Other</option>
        </select>
    </div>
    <div class="form-group">
        <label>Category</label>
        <input type="text" name="category" id="res_category" class="form-control" list="categoryList">
        <datalist id="categoryList">
            <?php foreach ($categories as $cat): ?>
                <option value="<?= h($cat) ?>">
            <?php endforeach; ?>
        </datalist>
    </div>
    <div class="form-group">
        <label>Tags</label>
        <input type="text" name="tags" id="res_tags" class="form-control">
    </div>
    <div class="form-group">
        <label>Access Level</label>
        <select name="access_level" id="res_access" class="form-control">
            <option value="request_required">Request Required</option>
            <option value="public">Public - free download</option>
        </select>
    </div>
    <div class="form-group">
        <label>Status</label>
        <select name="status" id="res_status" class="form-control">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>
    </div>
    <div class="form-group">
        <label>Sort Order</label>
        <input type="number" name="sort_order" id="res_sort_order" class="form-control" value="0">
    </div>
    <div class="form-group">
        <label>Thumbnail <em class="legacy-style-7e3a94476f">(optional)</em></label>
        <input type="file" name="thumbnail" class="form-control" accept="image/*" onchange="prevThumb(this)">
        <img id="thumbPreview" class="img-preview legacy-style-9714b43b47" alt="">
    </div>
</div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" onclick="document.getElementById('resourceModal').classList.remove('open')">Cancel</button>
    <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Resource</button>
</div>
</form>
</div>
</div>

<!-- Review Request -->
<div class="modal-overlay" id="reviewModal">
<div class="modal">
<div class="modal-header">
    <h2 class="modal-title">Review Access Request</h2>
    <button class="modal-close" onclick="document.getElementById('reviewModal').classList.remove('open')" type="button">x</button>
</div>
<div class="modal-body" id="reviewBody"></div>
<div class="modal-footer" id="reviewFooter"></div>
</div>
</div>

<!-- Download Details -->
<div class="modal-overlay" id="downloadsModal">
<div class="modal modal-xl">
<div class="modal-header">
    <h2 class="modal-title" id="downloadsModalTitle">Download Details</h2>
    <button class="modal-close" onclick="document.getElementById('downloadsModal').classList.remove('open')" type="button">x</button>
</div>
<div class="modal-body"><div id="downloadsModalBody"></div></div>
<div class="modal-footer">
    <button class="btn btn-secondary" onclick="document.getElementById('downloadsModal').classList.remove('open')" type="button">Close</button>
</div>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     JAVASCRIPT
     ══════════════════════════════════════════════════════════════════════ -->
<script>
/* ── PHP data injected as JS ─────────────────────────────────────────── */
const downloadLogs = <?= json_encode($downloadLogs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const SITE_URL     = <?= json_encode(rtrim(SITE_URL, '/'), JSON_HEX_TAG) ?>;
const HAS_UPDATED_AT = <?= $hasUpdatedAt ? 'true' : 'false' ?>;

const ALL_RESOURCES = <?= json_encode(array_map(function($r) {
    return [
        'id'               => $r['id'],
        'title'            => $r['title'],
        'description'      => $r['description'],
        'type'             => $r['type'],
        'icon'             => $r['icon'],
        'category'         => $r['category'],
        'access_level'     => $r['access_level'],
        'status'           => $r['status'],
        'sort_order'       => $r['sort_order'],
        'file_name'        => $r['file_name'],
        'file_path'        => $r['file_path'],
        'file_ext'         => $r['file_ext'],
        'file_ok'          => $r['file_ok'],
        'fsize'            => $r['fsize'],
        'tags'             => $r['tags'],
        'uploaded_by_name' => $r['uploaded_by_name'],
        'updated_by_name'  => $r['updated_by_name'],
        'created_at'       => $r['created_at'],
        'updated_at'       => $r['updated_at'],
        'tracked_downloads'=> $r['tracked_downloads'],
        'display_downloads'=> $r['display_downloads'],
        'row_data'         => $r['row_data'],
    ];
}, $resourceRows), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

const ALL_REQUESTS = <?= json_encode($requestRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

/* ── Helpers ──────────────────────────────────────────────────────────── */
function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
}
function escAttr(s) { return escHtml(s); }

function formatDate(ds) {
    if (!ds) return '-';
    const d = new Date(String(ds).replace(' ', 'T'));
    return isNaN(d.getTime()) ? ds : d.toLocaleString();
}

function formatDateShort(ds) {
    if (!ds) return '-';
    const d = new Date(String(ds).replace(' ', 'T'));
    if (isNaN(d.getTime())) return ds;
    return d.toLocaleDateString(undefined, {month:'short', day:'numeric', year:'numeric'}) + ' ' +
           d.toLocaleTimeString(undefined, {hour:'2-digit', minute:'2-digit'});
}

function getInitials(name) {
    return String(name || 'U').trim().split(/\s+/).slice(0,2).map(w => w[0].toUpperCase()).join('');
}

function highlight(text, query) {
    if (!query) return escHtml(text);
    const safe = escHtml(text);
    const q = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return safe.replace(new RegExp('(' + q + ')', 'gi'), '<mark class="hl">$1</mark>');
}

function truncate(s, n) {
    s = String(s || '');
    return s.length > n ? s.slice(0, n) + '...' : s;
}

function shortText(text, limit, query = '', className = 'table-text-short') {
    const full = String(text || '').trim();
    if (!full) return `<span class="${className}">-</span>`;
    const isLong = full.length > limit;
    const classes = className + (isLong ? ' table-text-tooltip' : '');
    const attrs = isLong ? ` tabindex="0" title="${escAttr(full)}" data-full-text="${escAttr(full)}"` : '';
    return `<span class="${classes}"${attrs}>${highlight(truncate(full, limit), query)}</span>`;
}

/* ── Tab switching ────────────────────────────────────────────────────── */
function setTab(id, btn) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab_' + id).classList.add('active');
    const url = new URL(window.location.href);
    url.searchParams.set('tab', id);
    history.replaceState(null, '', url.toString());
}

/* ══════════════════════════════════════════════════════════════════════
   RESOURCES TABLE
   ══════════════════════════════════════════════════════════════════════ */
const resState = { page: 1, perPage: 25, filtered: [] };

function resFilter() {
    const q      = document.getElementById('resSearch').value.trim().toLowerCase();
    const type   = document.getElementById('resTypeFilter').value;
    const status = document.getElementById('resStatusFilter').value;
    const access = document.getElementById('resAccessFilter').value;

    resState.filtered = ALL_RESOURCES.filter(r => {
        if (type   && r.type         !== type)   return false;
        if (status && r.status       !== status) return false;
        if (access && r.access_level !== access) return false;
        if (q) {
            const hay = [r.title, r.category, r.tags, r.description, r.file_name].join(' ').toLowerCase();
            if (!hay.includes(q)) return false;
        }
        return true;
    });

    resState.page = 1;
    resRender();
}

function resSetPerPage(n) {
    resState.perPage = n;
    resState.page = 1;
    resRender();
}

function resGoPage(p) {
    resState.page = p;
    resRender();
}

function resRender() {
    const q    = document.getElementById('resSearch').value.trim();
    const { filtered, page, perPage } = resState;
    const total = filtered.length;
    const pages = Math.max(1, Math.ceil(total / perPage));
    const p     = Math.min(page, pages);
    const start = (p - 1) * perPage;
    const slice = filtered.slice(start, start + perPage);

    document.getElementById('resResultCount').textContent =
        total === ALL_RESOURCES.length
            ? total + ' resource' + (total !== 1 ? 's' : '')
            : total + ' of ' + ALL_RESOURCES.length + ' resource' + (ALL_RESOURCES.length !== 1 ? 's' : '');

    const tbody = document.getElementById('resTableBody');

    if (!slice.length) {
        tbody.innerHTML = `<tr class="no-results-row"><td colspan="9">
            <i class="fa fa-search"></i>
            No resources match your search. <a href="#" onclick="resClear();return false">Clear filters</a>
        </td></tr>`;
    } else {
        tbody.innerHTML = slice.map(r => buildResRow(r, q)).join('');
    }

    // Page info
    const showing_from = total ? start + 1 : 0;
    const showing_to   = Math.min(start + perPage, total);
    document.getElementById('resPageInfo').textContent =
        total ? `${showing_from}-${showing_to} of ${total}` : '0 results';

    // Pagination buttons
    renderPagBtns('resPagBtns', p, pages, 'resGoPage');
}

function resClear() {
    document.getElementById('resSearch').value = '';
    document.getElementById('resTypeFilter').value = '';
    document.getElementById('resStatusFilter').value = '';
    document.getElementById('resAccessFilter').value = '';
    resFilter();
}

function buildResRow(r, q) {
    const typeColors = {
        pdf:'res-type-pdf', video:'res-type-video', image:'res-type-image',
        document:'res-type-document', other:'res-type-other'
    };
    const iconClass = typeColors[r.type] || 'res-type-other';

    const isPublic  = r.access_level === 'public';
    const isActive  = r.status === 'active';


    const ext = String(r.file_ext || '').toLowerCase();

    const previewableExts = [
        'pdf',
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'mp4', 'webm',
        'txt',
        'docx', 'odt', 'rtf',
        'xls', 'xlsx', 'ods', 'csv'
    ];

    const canPreview = r.file_ok && previewableExts.includes(ext);

    const viewBtn = !r.file_ok
        ? `<button class="btn btn-sm btn-secondary" disabled title="File missing"><i class="fa fa-eye-slash"></i></button>`
        : canPreview
            ? `<a href="resource-file.php?id=${r.id}&mode=view" target="_blank" rel="noopener" class="btn btn-sm btn-teal" title="Preview"><i class="fa fa-eye"></i></a>`
            : `<a href="resource-file.php?id=${r.id}&mode=download" class="btn btn-sm btn-secondary" title="Preview unavailable - download file"><i class="fa fa-download"></i></a>`;

    const rowDataJson = escAttr(JSON.stringify(r.row_data));

    return `
    <tr>
        <td>
            <div class="resource-cell">
                <div class="res-type-icon ${iconClass}"><i class="fa ${escHtml(r.icon)}"></i></div>
                <div class="resource-cell__content">
                    <strong>${shortText(r.title, 38, q, 'resource-cell__title')}</strong>
                    ${r.description ? shortText(r.description, 58, q, 'resource-cell__description') : ''}
                    ${r.fsize ? `<small class="meta-small">${escHtml(r.fsize)}</small>` : ''}
                    ${!r.file_ok && r.file_path ? `<div class="broken-file"><i class="fa fa-exclamation-triangle"></i> File missing on server</div>` : ''}
                </div>
            </div>
        </td>
        <td><span class="badge badge-gray">${escHtml(r.type.charAt(0).toUpperCase() + r.type.slice(1))}</span></td>
        <td>${shortText(r.category, 22, q)}</td>
        <td>
            ${isPublic
                ? `<span class="access-badge access-public"><i class="fa fa-globe"></i> Public</span>`
                : `<span class="access-badge access-request"><i class="fa fa-lock"></i> Request Required</span>`}
        </td>
        <td>
            <span class="badge ${isActive ? 'badge-success' : 'badge-gray'}">
                ${escHtml(r.status.charAt(0).toUpperCase() + r.status.slice(1))}
            </span>
        </td>
        <td>
            <button class="download-count-link" type="button" onclick="openDownloadsModal(${r.id})">
                <i class="fa fa-download"></i> ${r.display_downloads}
            </button>
            <span class="download-count-muted">${r.tracked_downloads} tracked</span>
        </td>
        <td>
            ${shortText(r.uploaded_by_name, 22)}
            ${r.created_at ? `<span class="meta-small">${escHtml(formatDateShort(r.created_at))}</span>` : ''}
        </td>
        <td>
            ${shortText(r.updated_by_name, 22)}
            ${(HAS_UPDATED_AT && r.updated_at) ? `<span class="meta-small">${escHtml(formatDateShort(r.updated_at))}</span>` : ''}
        </td>
        <td>
            <div class="tbl-actions">
                ${viewBtn}
                <button class="btn btn-sm btn-secondary" onclick='openEditResource(${JSON.stringify(r.row_data).replace(/'/g,"&#39;")})' type="button">
                    <i class="fa fa-edit"></i>
                </button>
                <form method="POST" action="includes/process-resources.php" onsubmit="return confirm('Delete this resource?')" class="legacy-style-cccfa4560d">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="${r.id}">
                    <button class="btn btn-sm btn-danger"><i class="fa fa-trash"></i></button>
                </form>
            </div>
        </td>
    </tr>`;
}

/* ══════════════════════════════════════════════════════════════════════
   REQUESTS TABLE
   ══════════════════════════════════════════════════════════════════════ */
const reqState = { page: 1, perPage: 25, filtered: [] };

function reqFilter() {
    const q      = document.getElementById('reqSearch').value.trim().toLowerCase();
    const status = document.getElementById('reqStatusFilter').value;

    reqState.filtered = ALL_REQUESTS.filter(r => {
        if (status && r.status !== status) return false;
        if (q) {
            const hay = [r.requester_name, r.requester_email, r.requester_org,
                         r.resource_title, r.reason].join(' ').toLowerCase();
            if (!hay.includes(q)) return false;
        }
        return true;
    });

    reqState.page = 1;
    reqRender();
}

function reqSetPerPage(n) {
    reqState.perPage = n;
    reqState.page = 1;
    reqRender();
}

function reqGoPage(p) {
    reqState.page = p;
    reqRender();
}

function reqRender() {
    const q    = document.getElementById('reqSearch').value.trim();
    const { filtered, page, perPage } = reqState;
    const total = filtered.length;
    const pages = Math.max(1, Math.ceil(total / perPage));
    const p     = Math.min(page, pages);
    const start = (p - 1) * perPage;
    const slice = filtered.slice(start, start + perPage);

    document.getElementById('reqResultCount').textContent =
        total === ALL_REQUESTS.length
            ? total + ' request' + (total !== 1 ? 's' : '')
            : total + ' of ' + ALL_REQUESTS.length + ' request' + (ALL_REQUESTS.length !== 1 ? 's' : '');

    const tbody = document.getElementById('reqTableBody');

    if (!slice.length) {
        tbody.innerHTML = `<tr class="no-results-row"><td colspan="6">
            <i class="fa fa-search"></i>
            No requests match your search. <a href="#" onclick="reqClear();return false">Clear filters</a>
        </td></tr>`;
    } else {
        tbody.innerHTML = slice.map(r => buildReqRow(r, q)).join('');
    }

    const showing_from = total ? start + 1 : 0;
    const showing_to   = Math.min(start + perPage, total);
    document.getElementById('reqPageInfo').textContent =
        total ? `${showing_from}-${showing_to} of ${total}` : '0 results';

    renderPagBtns('reqPagBtns', p, pages, 'reqGoPage');
}

function reqClear() {
    document.getElementById('reqSearch').value = '';
    document.getElementById('reqStatusFilter').value = '';
    reqFilter();
}

function buildReqRow(req, q) {
    const pillClass = {pending:'pill-pending', approved:'pill-approved', rejected:'pill-rejected'}[req.status] || 'pill-pending';
    const isPending = req.status === 'pending';

    return `
    <tr class="request-row ${escHtml(req.status)}">
        <td>
            <strong>${shortText(req.requester_name, 28, q)}</strong>
            ${shortText(req.requester_email, 30, q, 'resource-cell__description')}
            ${req.requester_org ? shortText(req.requester_org, 26, q, 'resource-cell__description') : ''}
        </td>
        <td>
            <strong>${shortText(req.resource_title || 'Deleted', 30, q)}</strong>
            ${shortText(((req.resource_type || '').charAt(0).toUpperCase() + (req.resource_type || '').slice(1)) + (req.file_name ? ' . ' + req.file_name : ''), 34, '', 'resource-cell__description')}
        </td>
        <td>${shortText(req.reason || 'No reason provided', 55, q)}</td>
        <td>
            <span class="status-pill ${pillClass}">
                ${escHtml(req.status.charAt(0).toUpperCase() + req.status.slice(1))}
            </span>
            ${req.admin_note ? shortText(req.admin_note, 38, '', 'resource-cell__description') : ''}
        </td>
        <td>${escHtml(formatDateShort(req.created_at))}</td>
        <td>
            <div class="tbl-actions">
                <button class="btn btn-sm btn-secondary" onclick='openReviewModal(${JSON.stringify(req).replace(/'/g,"&#39;")})' title="Review" type="button">
                    <i class="fa fa-search"></i>
                </button>
                ${isPending ? `
                <form method="POST" action="includes/process-resources.php" class="legacy-style-cccfa4560d">
                    <input type="hidden" name="action" value="approve_request">
                    <input type="hidden" name="id" value="${req.id}">
                    <button class="btn btn-sm btn-success" title="Approve"><i class="fa fa-check"></i></button>
                </form>
                <form method="POST" action="includes/process-resources.php" class="legacy-style-cccfa4560d">
                    <input type="hidden" name="action" value="reject_request">
                    <input type="hidden" name="id" value="${req.id}">
                    <button class="btn btn-sm btn-danger" title="Reject"><i class="fa fa-times"></i></button>
                </form>` : ''}
            </div>
        </td>
    </tr>`;
}

/* ── Generic pagination button renderer ───────────────────────────────── */
function renderPagBtns(containerId, current, total, callbackName) {
    const container = document.getElementById(containerId);
    if (total <= 1) { container.innerHTML = ''; return; }

    const MAX_VISIBLE = 7;
    let pages = [];

    if (total <= MAX_VISIBLE) {
        pages = Array.from({length: total}, (_, i) => i + 1);
    } else {
        pages = [1];
        let lo = Math.max(2, current - 2);
        let hi = Math.min(total - 1, current + 2);

        if (lo > 2)        pages.push('...');
        for (let i = lo; i <= hi; i++) pages.push(i);
        if (hi < total - 1) pages.push('...');
        pages.push(total);
    }

    container.innerHTML =
        `<button class="page-btn" onclick="${callbackName}(${current - 1})" ${current === 1 ? 'disabled' : ''}>
            <i class="fa fa-chevron-left"></i>
        </button>` +
        pages.map(p =>
            p === '...'
                ? `<span class="legacy-style-34fee43f6a">...</span>`
                : `<button class="page-btn ${p === current ? 'active' : ''}" onclick="${callbackName}(${p})">${p}</button>`
        ).join('') +
        `<button class="page-btn" onclick="${callbackName}(${current + 1})" ${current === total ? 'disabled' : ''}>
            <i class="fa fa-chevron-right"></i>
        </button>`;
}

/* ── Downloads modal ──────────────────────────────────────────────────── */
function openDownloadsModal(resourceId) {
    const modal = document.getElementById('downloadsModal');
    const title = document.getElementById('downloadsModalTitle');
    const body  = document.getElementById('downloadsModalBody');
    const logs  = downloadLogs[String(resourceId)] || downloadLogs[resourceId] || [];

    if (!logs.length) {
        title.textContent = 'Download Details';
        body.innerHTML = `<div class="download-empty">
            <i class="fa fa-download legacy-style-4fc3260d23"></i>
            <h3>No tracked download details yet</h3>
            <p>The download count may come from the old counter before tracking was added.</p>
        </div>`;
        modal.classList.add('open');
        return;
    }

    title.textContent = 'Downloaded: ' + (logs[0].resource_title || 'Resource');
    body.innerHTML = `<div class="download-detail-list">${logs.map(log => {
        const ventureName = log.venture_name || log.downloaded_by_name || 'Unknown';
        const email = log.downloaded_by_email || log.cofounder1_email || '';
        const initials = getInitials(ventureName);
        const logo = log.venture_logo ? SITE_URL + '/' + String(log.venture_logo).replace(/^\/+/, '') : '';
        return `
        <div class="download-detail-item">
            <div class="download-avatar">
                ${logo ? `<img src="${escAttr(logo)}" alt="">` : escHtml(initials)}
            </div>
            <div>
                <div class="download-detail-title">${escHtml(ventureName)}</div>
                <div class="download-detail-meta">
                    ${email ? `<i class="fa fa-envelope"></i> ${escHtml(email)}<br>` : ''}
                    <i class="fa fa-clock"></i> ${escHtml(formatDate(log.downloaded_at))}
                </div>
                <div class="download-detail-badges">
                    ${log.venture_name ? `<span class="badge badge-success">Venture</span>` : `<span class="badge badge-gray">User</span>`}
                    ${log.ip_address ? `<span class="badge badge-gray">IP: ${escHtml(log.ip_address)}</span>` : ''}
                </div>
            </div>
        </div>`;
    }).join('')}</div>`;
    modal.classList.add('open');
}

/* ── Resource modal ───────────────────────────────────────────────────── */
function openResourceModal() {
    document.getElementById('resModalTitle').textContent = 'Upload Resource';
    document.getElementById('resourceModal').querySelector('form').reset();
    document.getElementById('res_id').value = '';
    document.getElementById('res_existing_file').value = '';
    document.getElementById('fileInfo').style.display = 'none';
    document.getElementById('editingFile').style.display = 'none';
    document.getElementById('fileReq').style.display = '';
    document.getElementById('thumbPreview').style.display = 'none';
    document.getElementById('resourceModal').classList.add('open');
}

function openEditResource(d) {
    document.getElementById('resModalTitle').textContent = 'Edit Resource';
    document.getElementById('res_id').value            = d.id || '';
    document.getElementById('res_title').value         = d.title || '';
    document.getElementById('res_description').value   = d.description || '';
    document.getElementById('res_type').value          = d.type || 'pdf';
    document.getElementById('res_category').value      = d.category || '';
    document.getElementById('res_tags').value          = d.tags || '';
    document.getElementById('res_access').value        = d.access_level || 'request_required';
    document.getElementById('res_status').value        = d.status || 'active';
    document.getElementById('res_sort_order').value    = d.sort_order || 0;
    document.getElementById('res_existing_file').value = d.file_path || '';
    document.getElementById('fileReq').style.display   = 'none';
    if (d.file_name) {
        document.getElementById('editingFile').style.display = 'block';
        document.getElementById('editingFile').innerHTML = '<i class="fa fa-file"></i> Current file: <strong>' + escHtml(d.file_name) + '</strong> (leave blank to keep)';
    }
    document.getElementById('resourceModal').classList.add('open');
}

function handleFileSelect(input) {
    if (input.files && input.files[0]) {
        const f  = input.files[0];
        const sz = f.size > 1048576
            ? Math.round(f.size / 1048576 * 10) / 10 + 'MB'
            : Math.round(f.size / 1024) + 'KB';
        document.getElementById('fileInfo').style.display = 'block';
        document.getElementById('fileInfo').innerHTML = '<i class="fa fa-check-circle"></i> <strong>' + escHtml(f.name) + '</strong> (' + sz + ')';
        autoDetectType(f.name);
    }
}

function handleFileDrop(e) {
    e.preventDefault();
    document.getElementById('fileDropZone').classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (!file) return;
    const dt = new DataTransfer();
    dt.items.add(file);
    const inp = document.getElementById('resFile');
    inp.files = dt.files;
    handleFileSelect(inp);
}

function autoDetectType(name) {
    const ext = name.split('.').pop().toLowerCase();
    const map = {pdf:'pdf',mp4:'video',mov:'video',avi:'video',webm:'video',jpg:'image',jpeg:'image',png:'image',gif:'image',webp:'image',doc:'document',docx:'document',ppt:'document',pptx:'document',xls:'document',xlsx:'document',zip:'other',txt:'other'};
    if (map[ext]) document.getElementById('res_type').value = map[ext];
}

function prevThumb(input) {
    if (input.files && input.files[0]) {
        const r = new FileReader();
        r.onload = e => {
            const i = document.getElementById('thumbPreview');
            i.src = e.target.result;
            i.style.display = 'block';
        };
        r.readAsDataURL(input.files[0]);
    }
}

 
function openReviewModal(req) {
    document.getElementById('reviewBody').innerHTML = `
        <div class="form-grid legacy-style-34d1e8e86f">
            <div>
                <label class="legacy-style-348c650d01">Requester</label>
                <p><strong>${escHtml(req.requester_name)}</strong> &lt;${escHtml(req.requester_email)}&gt;</p>
                ${req.requester_org ? `<p class="legacy-style-8532e885f0">${escHtml(req.requester_org)}</p>` : ''}
            </div>
            <div>
                <label class="legacy-style-348c650d01">Resource</label>
                <p><strong>${escHtml(req.resource_title || 'Deleted')}</strong></p>
            </div>
            <div class="full">
                <label class="legacy-style-348c650d01">Reason for Access</label>
                <p class="legacy-style-e4584fa7f1">${escHtml(req.reason || 'No reason provided.')}</p>
            </div>
            <div class="full">
                <label class="legacy-style-348c650d01">Admin Note <em class="legacy-style-7e3a94476f">(optional)</em></label>
                <textarea id="reviewNote" class="form-control" rows="2">${escHtml(req.admin_note || '')}</textarea>
            </div>
        </div>`;

    document.getElementById('reviewFooter').innerHTML = req.status === 'pending'
        ? `<button class="btn btn-secondary" onclick="document.getElementById('reviewModal').classList.remove('open')">Close</button>
           <button class="btn btn-danger" onclick="submitReview(${req.id},${req.resource_id},'reject')"><i class="fa fa-times"></i> Reject</button>
           <button class="btn btn-success" onclick="submitReview(${req.id},${req.resource_id},'approve')"><i class="fa fa-check"></i> Approve &amp; Notify</button>`
        : `<button class="btn btn-secondary" onclick="document.getElementById('reviewModal').classList.remove('open')">Close</button>`;

    document.getElementById('reviewModal').classList.add('open');
}

function submitReview(id, resId, act) {
    const note = document.getElementById('reviewNote')?.value || '';
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'includes/process-resources.php';
    const fields = {
        action: act === 'approve' ? 'approve_request' : 'reject_request',
        id, resource_id: resId, admin_note: note
    };
    Object.keys(fields).forEach(k => {
        const input = document.createElement('input');
        input.type = 'hidden'; input.name = k; input.value = fields[k];
        form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
}


function syncHeroBgColor()            { document.getElementById('heroBgColorText').value = document.getElementById('heroBgColorPicker').value; }
function syncHeroBgColorText()        { const p=document.getElementById('heroBgColorPicker'),t=document.getElementById('heroBgColorText'); if(/^#[0-9A-Fa-f]{6}$/.test(t.value))p.value=t.value; }
function syncHeroOverlayColor()       { document.getElementById('heroOverlayColorText').value = document.getElementById('heroOverlayColorPicker').value; }
function syncHeroOverlayColorText()   { const p=document.getElementById('heroOverlayColorPicker'),t=document.getElementById('heroOverlayColorText'); if(/^#[0-9A-Fa-f]{6}$/.test(t.value))p.value=t.value; }
function syncHeroOverlayOpacity()     { document.getElementById('heroOverlayNumber').value = document.getElementById('heroOverlayRange').value; }
function syncHeroOverlayOpacityNumber(){ document.getElementById('heroOverlayRange').value = document.getElementById('heroOverlayNumber').value; }

(function init() {
    resState.filtered = ALL_RESOURCES.slice();
    reqState.filtered = ALL_REQUESTS.slice();
    resRender();
    reqRender();
})();
</script>

</body>
</html>
