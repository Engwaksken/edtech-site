<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection failed.');
}

$conn->set_charset('utf8mb4');
date_default_timezone_set('Africa/Nairobi');

if (!function_exists('h')) {
    function h($v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('vr_column_exists')) {
    function vr_column_exists(mysqli $conn, string $table, string $column): bool
    {
        $stmt = $conn->prepare("\n            SELECT COUNT(*) AS total\n            FROM INFORMATION_SCHEMA.COLUMNS\n            WHERE TABLE_SCHEMA = DATABASE()\n              AND TABLE_NAME = ?\n              AND COLUMN_NAME = ?\n        ");
        if (!$stmt) return false;
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['total'] ?? 0) > 0;
    }
}

if (!function_exists('vr_asset_src')) {
    function vr_asset_src(string $path): string
    {
        $path = trim($path);
        if ($path === '') return '';
        if (preg_match('#^https?://#i', $path)) return $path;
        return rtrim((string)SITE_URL, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('vr_file_size')) {
    function vr_file_size($bytes): string
    {
        $bytes = (float)$bytes;
        if ($bytes <= 0) return '';
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024) . ' KB';
    }
}

if (!function_exists('vr_short')) {
    function vr_short(string $text, int $limit = 115): string
    {
        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($text, 0, $limit, '...', 'UTF-8');
        }
        return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
    }
}


foreach (['dash_resource_flash', 'resource_flash', 'flash_message', 'error', 'success'] as $k) {
    if (isset($_SESSION[$k]) && stripos((string)$_SESSION[$k], 'token expired') !== false) {
        unset($_SESSION[$k]);
    }
}

$type_labels = [
    'pdf'      => ['label' => 'PDF',      'icon' => 'fa-file-pdf'],
    'video'    => ['label' => 'Video',    'icon' => 'fa-film'],
    'image'    => ['label' => 'Image',    'icon' => 'fa-image'],
    'document' => ['label' => 'Document', 'icon' => 'fa-file-word'],
    'other'    => ['label' => 'Other',    'icon' => 'fa-file'],
];

$type_colors = [
    'pdf'      => 'res-c-pdf',
    'video'    => 'res-c-video',
    'image'    => 'res-c-image',
    'document' => 'res-c-doc',
    'other'    => 'res-c-other',
];

$filter_type = trim((string)($_GET['type'] ?? ''));
$filter_cat  = trim((string)($_GET['category'] ?? ''));
$search      = trim((string)($_GET['q'] ?? ''));
$page        = max(1, (int)($_GET['page'] ?? 1));
$allowed_per_pages = [6, 12, 24, 48];
$per_page = (int)($_GET['per_page'] ?? 12);
if (!in_array($per_page, $allowed_per_pages, true)) {
    $per_page = 12;
}

$where  = ["status = 'active'"];
$params = [];
$types  = '';

if ($filter_type !== '' && isset($type_labels[$filter_type])) {
    $where[] = 'type = ?';
    $params[] = $filter_type;
    $types .= 's';
}
if ($filter_cat !== '') {
    $where[] = 'category = ?';
    $params[] = $filter_cat;
    $types .= 's';
}
if ($search !== '') {
    $where[] = '(title LIKE ? OR description LIKE ? OR tags LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term);
    $types .= 'sss';
}
$where_sql = implode(' AND ', $where);

$count_sql = "SELECT COUNT(*) AS total FROM resources WHERE $where_sql";
$stmt = $conn->prepare($count_sql);
if (!$stmt) die('Resource count query failed: ' . h($conn->error));
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total_resources = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();
$total_pages = max(1, (int)ceil($total_resources / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$categories = [];
$category_result = $conn->query("SELECT DISTINCT category FROM resources WHERE status = 'active' AND category IS NOT NULL AND TRIM(category) <> '' ORDER BY category ASC");
if ($category_result) {
    while ($category_row = $category_result->fetch_assoc()) {
        $categories[] = (string)$category_row['category'];
    }
    $category_result->free();
}

$sql = "\n    SELECT *\n    FROM resources\n    WHERE $where_sql\n    ORDER BY sort_order ASC, created_at DESC\n    LIMIT ? OFFSET ?\n";
$stmt = $conn->prepare($sql);
if (!$stmt) die('Resource query failed: ' . h($conn->error));
$params2 = $params;
$types2 = $types . 'ii';
$params2[] = $per_page;
$params2[] = $offset;
$stmt->bind_param($types2, ...$params2);
$stmt->execute();
$resources = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$current_venture_id = 0;
foreach (['venture_id', 'venture_user_id', 'user_id', 'id'] as $session_key) {
    if (!empty($_SESSION[$session_key]) && (int)$_SESSION[$session_key] > 0) {
        $current_venture_id = (int)$_SESSION[$session_key];
        break;
    }
}

$current_venture_email = '';
foreach (['venture_email', 'email', 'user_email'] as $session_key) {
    if (!empty($_SESSION[$session_key]) && filter_var((string)$_SESSION[$session_key], FILTER_VALIDATE_EMAIL)) {
        $current_venture_email = trim((string)$_SESSION[$session_key]);
        break;
    }
}

if ($current_venture_id > 0) {
    $stmt = $conn->prepare("SELECT id, email FROM ventures WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $current_venture_id);
        $stmt->execute();
        $venture = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($venture) {
            $current_venture_id = (int)$venture['id'];
            if ($current_venture_email === '') $current_venture_email = (string)$venture['email'];
        }
    }
}

if ($current_venture_email !== '' && $current_venture_id <= 0) {
    $stmt = $conn->prepare("SELECT id, email FROM ventures WHERE email = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $current_venture_email);
        $stmt->execute();
        $venture = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($venture) {
            $current_venture_id = (int)$venture['id'];
            $current_venture_email = (string)$venture['email'];
            $_SESSION['venture_id'] = $current_venture_id;
        }
    }
}

$has_request_venture_id = vr_column_exists($conn, 'resource_requests', 'venture_id');
$venture_request_status = [];

/* IMPORTANT: Approved access is permanent. Do NOT check token_expires here. */
if ($current_venture_id > 0 || $current_venture_email !== '') {
    if ($has_request_venture_id && $current_venture_id > 0 && $current_venture_email !== '') {
        $stmt = $conn->prepare("\n            SELECT resource_id, status\n            FROM resource_requests\n            WHERE venture_id = ? OR requester_email = ?\n            ORDER BY\n                CASE LOWER(TRIM(status))\n                    WHEN 'approved' THEN 1\n                    WHEN 'pending' THEN 2\n                    WHEN 'rejected' THEN 3\n                    ELSE 4\n                END ASC,\n                id DESC\n        ");
        if ($stmt) $stmt->bind_param('is', $current_venture_id, $current_venture_email);
    } elseif ($has_request_venture_id && $current_venture_id > 0) {
        $stmt = $conn->prepare("\n            SELECT resource_id, status\n            FROM resource_requests\n            WHERE venture_id = ?\n            ORDER BY CASE LOWER(TRIM(status)) WHEN 'approved' THEN 1 WHEN 'pending' THEN 2 WHEN 'rejected' THEN 3 ELSE 4 END ASC, id DESC\n        ");
        if ($stmt) $stmt->bind_param('i', $current_venture_id);
    } else {
        $stmt = $conn->prepare("\n            SELECT resource_id, status\n            FROM resource_requests\n            WHERE requester_email = ?\n            ORDER BY CASE LOWER(TRIM(status)) WHEN 'approved' THEN 1 WHEN 'pending' THEN 2 WHEN 'rejected' THEN 3 ELSE 4 END ASC, id DESC\n        ");
        if ($stmt) $stmt->bind_param('s', $current_venture_email);
    }

    if (isset($stmt) && $stmt) {
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($row = $rs->fetch_assoc()) {
            $rid = (int)$row['resource_id'];
            $status = strtolower(trim((string)$row['status']));
            if ($rid > 0 && !isset($venture_request_status[$rid])) {
                $venture_request_status[$rid] = $status;
            }
        }
        $stmt->close();
    }
}

$flash_msg  = $_SESSION['dash_resource_flash'] ?? '';
$flash_type = $_SESSION['dash_resource_flash_type'] ?? 'success';
unset($_SESSION['dash_resource_flash'], $_SESSION['dash_resource_flash_type']);

include __DIR__ . '/includes/header.php';
?>

<main class="res-main venture-resources-main" style="padding-top:72px">
    <section class="res-section">
        <div class="container">
            <form class="res-search-panel" method="GET" action="venture_resources" role="search">
                <div class="res-search-field">
                    <label for="resourceSearch">Search resources</label>
                    <div class="res-search-input">
                        <i class="fas fa-search" aria-hidden="true"></i>
                        <input
                            type="search"
                            id="resourceSearch"
                            name="q"
                            value="<?= h($search) ?>"
                            placeholder="Search title, description, or tags..."
                            autocomplete="off"
                        >
                    </div>
                </div>

                <div class="res-filter-field">
                    <label for="resourceType">Type</label>
                    <select id="resourceType" name="type">
                        <option value="">All types</option>
                        <?php foreach ($type_labels as $type_key => $type_data): ?>
                            <option value="<?= h($type_key) ?>" <?= $filter_type === $type_key ? 'selected' : '' ?>>
                                <?= h($type_data['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="res-filter-field">
                    <label for="resourceCategory">Category</label>
                    <select id="resourceCategory" name="category">
                        <option value="">All categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= h($category) ?>" <?= $filter_cat === $category ? 'selected' : '' ?>><?= h($category) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="res-filter-field res-per-page-field">
                    <label for="resourcesPerPage">Show</label>
                    <select id="resourcesPerPage" name="per_page">
                        <?php foreach ($allowed_per_pages as $size): ?>
                            <option value="<?= $size ?>" <?= $per_page === $size ? 'selected' : '' ?>><?= $size ?> per page</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="res-search-actions">
                    <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i> Search</button>
                    <?php if ($search !== '' || $filter_type !== '' || $filter_cat !== '' || $per_page !== 12): ?>
                        <a class="btn btn-secondary" href="venture_resources"><i class="fas fa-undo-alt"></i> Clear</a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="res-results-header">
                <span class="res-results-count">
                    <i class="fas fa-list"></i>
                    Showing <?= $total_resources > 0 ? (int)($offset + 1) : 0 ?> - <?= (int)min($offset + count($resources), $total_resources) ?> of <?= (int)$total_resources ?> resources
                </span>
            </div>

            <?php if ($flash_msg !== ''): ?>
                <div class="nl-alert nl-alert-<?= h($flash_type) ?>" style="margin-bottom:18px">
                    <i class="fas fa-info-circle"></i> <?= h($flash_msg) ?>
                </div>
            <?php endif; ?>

            <?php if (empty($resources)): ?>
                <div class="empty-cohorts">
                    <i class="fas fa-folder-open"></i>
                    <h3>No resources found</h3>
                    <p>Try adjusting your filters or search term.</p>
                </div>
            <?php else: ?>
                <div class="res-grid">
                    <?php foreach ($resources as $r): ?>
                        <?php
                        $resource_id  = (int)$r['id'];
                        $type         = strtolower(trim((string)($r['type'] ?? 'other')));
                        $icon         = $type_labels[$type]['icon'] ?? 'fa-file';
                        $label        = $type_labels[$type]['label'] ?? 'Other';
                        $color        = $type_colors[$type] ?? 'res-c-other';
                        $title        = (string)($r['title'] ?? 'Untitled Resource');
                        $desc         = trim((string)($r['description'] ?? ''));
                        $thumbnail    = trim((string)($r['thumbnail'] ?? ''));
                        $img_src      = $thumbnail !== '' ? vr_asset_src($thumbnail) : '';
                        $fsize        = vr_file_size($r['file_size'] ?? 0);
                        $access_level = strtolower(trim((string)($r['access_level'] ?? 'public')));
                        $status       = $venture_request_status[$resource_id] ?? '';

                        $request_url  = 'venture_resources?request_resource_id=' . $resource_id;
                        $login_url    = 'login.php?redirect=' . urlencode($request_url);
                        $download_url = 'includes/process-resources.php?action=download&resource_id=' . $resource_id;
                        $view_url     = $download_url . '&view=1';
                        ?>

                        <div class="res-card">
                            <div class="res-card__thumb <?= h($color) ?>">
                                <?php if ($img_src !== ''): ?>
                                    <img src="<?= h($img_src) ?>" alt="<?= h($title) ?>">
                                <?php else: ?>
                                    <i class="fas <?= h($icon) ?>"></i>
                                <?php endif; ?>

                                <?php if ($access_level === 'request_required'): ?>
                                    <span class="res-card__lock-badge"><i class="fas fa-lock"></i></span>
                                <?php else: ?>
                                    <span class="res-card__free-badge"><i class="fas fa-globe"></i></span>
                                <?php endif; ?>
                            </div>

                            <div class="res-card__body">
                                <?php if (!empty($r['category'])): ?>
                                    <span class="res-card__cat"><i class="fas fa-tag"></i> <?= h($r['category']) ?></span>
                                <?php endif; ?>

                                <h3 class="res-card__title"><?= h($title) ?></h3>

                                <?php if ($desc !== ''): ?>
                                    <p class="res-card__desc"><?= h(vr_short($desc, 120)) ?></p>
                                <?php endif; ?>

                                <div class="res-card__meta">
                                    <span><i class="fas <?= h($icon) ?>"></i> <?= h($label) ?></span>
                                    <?php if ($fsize !== ''): ?>
                                        <span><i class="fas fa-database"></i> <?= h($fsize) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="res-card__footer">
                                <?php if ($access_level !== 'request_required'): ?>
                                    <a href="<?= h($download_url) ?>" class="btn btn-primary res-card__dl-btn">
                                        <i class="fas fa-download"></i> Download Free
                                    </a>
                                <?php elseif ($status === 'approved'): ?>
                                    <div class="res-action-row">
                                        <a href="<?= h($view_url) ?>" class="btn btn-primary res-card__dl-btn">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="<?= h($download_url) ?>" class="btn btn-primary res-card__dl-btn">
                                            <i class="fas fa-download"></i> 
                                        </a>
                                    </div>
                                <?php elseif ($status === 'pending'): ?>
                                    <a href="<?= h($current_venture_id > 0 ? $request_url : $login_url) ?>" class="btn btn-primary res-card__dl-btn">
                                        <i class="fas fa-paper-plane"></i> Resend Request
                                    </a>
                                <?php else: ?>
                                    <a href="<?= h($current_venture_id > 0 ? $request_url : $login_url) ?>" class="btn btn-primary res-card__dl-btn">
                                        <i class="fas fa-lock"></i> Request Access
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($total_pages > 1): ?>
                    <?php
                    $page_url = static function (int $target_page): string {
                        $query = $_GET;
                        $query['page'] = $target_page;
                        return 'venture_resources?' . http_build_query($query);
                    };
                    $window_start = max(1, $page - 2);
                    $window_end = min($total_pages, $page + 2);
                    if ($window_end - $window_start < 4) {
                        $window_start = max(1, $window_end - 4);
                        $window_end = min($total_pages, $window_start + 4);
                    }
                    ?>
                    <nav class="pagination-wrap" aria-label="Resources pagination">
                        <span class="pagination-summary">Page <?= $page ?> of <?= $total_pages ?></span>
                        <div class="pagination">
                            <a href="<?= h($page_url(1)) ?>" class="page-link page-link-text <?= $page === 1 ? 'disabled' : '' ?>" aria-label="First page">
                                <i class="fas fa-angle-double-left"></i><span>First</span>
                            </a>
                            <a href="<?= h($page_url(max(1, $page - 1))) ?>" class="page-link page-link-text <?= $page === 1 ? 'disabled' : '' ?>" aria-label="Previous page">
                                <i class="fas fa-chevron-left"></i><span>Previous</span>
                            </a>

                            <?php if ($window_start > 1): ?><span class="page-ellipsis" aria-hidden="true">...</span><?php endif; ?>
                            <?php for ($p = $window_start; $p <= $window_end; $p++): ?>
                                <a
                                    href="<?= h($page_url($p)) ?>"
                                    class="page-link <?= $p === $page ? 'active' : '' ?>"
                                    <?= $p === $page ? 'aria-current="page"' : '' ?>
                                ><?= $p ?></a>
                            <?php endfor; ?>
                            <?php if ($window_end < $total_pages): ?><span class="page-ellipsis" aria-hidden="true">...</span><?php endif; ?>

                            <a href="<?= h($page_url(min($total_pages, $page + 1))) ?>" class="page-link page-link-text <?= $page === $total_pages ? 'disabled' : '' ?>" aria-label="Next page">
                                <span>Next</span><i class="fas fa-chevron-right"></i>
                            </a>
                            <a href="<?= h($page_url($total_pages)) ?>" class="page-link page-link-text <?= $page === $total_pages ? 'disabled' : '' ?>" aria-label="Last page">
                                <span>Last</span><i class="fas fa-angle-double-right"></i>
                            </a>
                        </div>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</main>

<style>
.venture-resources-main .res-section{padding:32px 0 70px;background:#f8fafc;min-height:70vh}.res-results-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}.res-results-count{font-weight:800;color:#64748b}.res-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px}.res-card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;overflow:hidden;box-shadow:0 10px 25px rgba(15,23,42,.05);display:flex;flex-direction:column}.res-card__thumb{height:150px;display:flex;align-items:center;justify-content:center;position:relative;background:#f8fafc}.res-card__thumb>i{font-size:48px}.res-c-pdf{background:#fee2e2;color:#b91c1c}.res-c-doc,.res-c-document{background:#fef3c7;color:#b45309}.res-c-video{background:#dbeafe;color:#1d4ed8}.res-c-image{background:#dcfce7;color:#15803d}.res-c-other{background:#f1f5f9;color:#334155}.res-card__lock-badge,.res-card__free-badge{position:absolute;top:14px;right:14px;background:#fff7cc;color:#92400e;border-radius:999px;padding:6px 10px;font-size:.75rem;font-weight:900}.res-card__free-badge{background:#dcfce7;color:#166534}.res-card__body{padding:20px;flex:1}.res-card__cat{display:inline-flex;gap:6px;align-items:center;font-size:.76rem;color:#64748b;margin-bottom:8px}.res-card__title{font-size:1.05rem;line-height:1.45;margin:0 0 8px;color:#020617;font-weight:900}.res-card__desc{color:#475569;line-height:1.55;margin:0 0 14px}.res-card__meta{display:flex;gap:10px;flex-wrap:wrap;color:#64748b;font-size:.84rem}.res-card__footer{padding:16px 18px;border-top:1px solid #e5e7eb}.res-action-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}.res-card__dl-btn{width:100%;justify-content:center}.pagination{display:flex;gap:8px;flex-wrap:wrap}.pagination a{padding:8px 12px;border:1px solid #e5e7eb;background:#fff;border-radius:10px;text-decoration:none;font-weight:800}.pagination a.active{background:#0f172a;color:#fff}@media(max-width:768px){.res-grid{grid-template-columns:1fr}.res-action-row{grid-template-columns:1fr}}
.res-search-panel{display:grid;grid-template-columns:minmax(260px,2fr) minmax(145px,1fr) minmax(170px,1fr) minmax(125px,.7fr) auto;gap:14px;align-items:end;padding:18px;margin-bottom:18px;background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 8px 24px rgba(15,23,42,.05)}
.res-search-field,.res-filter-field{min-width:0}.res-search-panel label{display:block;margin-bottom:7px;color:#334155;font-size:.78rem;font-weight:800}.res-search-input{position:relative}.res-search-input i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#94a3b8}.res-search-input input,.res-search-panel select{box-sizing:border-box;width:100%;height:44px;padding:0 13px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;color:#0f172a;font:inherit;outline:0;transition:border-color .2s ease,box-shadow .2s ease}.res-search-input input{padding-left:40px}.res-search-input input:focus,.res-search-panel select:focus{border-color:#0f766e;box-shadow:0 0 0 3px rgba(15,118,110,.13)}.res-search-actions{display:flex;gap:8px;align-items:center}.res-search-actions .btn{height:44px;white-space:nowrap}.pagination-wrap{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:26px;padding:15px 16px;background:#fff;border:1px solid #e2e8f0;border-radius:14px}.pagination-summary{color:#64748b;font-size:.88rem;font-weight:700;white-space:nowrap}.pagination{align-items:center;justify-content:flex-end}.pagination .page-link{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-width:40px;height:40px;box-sizing:border-box;padding:0 11px;border:1px solid #dbe3ec;background:#fff;border-radius:9px;color:#334155;text-decoration:none;font-weight:800;transition:transform .16s ease,border-color .16s ease,background .16s ease,color .16s ease}.pagination .page-link:hover{border-color:#0f766e;color:#0f766e;transform:translateY(-1px)}.pagination .page-link.active{border-color:#0f766e;background:#0f766e;color:#fff}.pagination .page-link.disabled{opacity:.45;pointer-events:none}.page-ellipsis{padding:0 3px;color:#94a3b8;font-weight:800}
@media(max-width:1100px){.res-search-panel{grid-template-columns:2fr 1fr 1fr}.res-per-page-field{grid-column:auto}.res-search-actions{grid-column:span 2}}
@media(max-width:768px){.res-search-panel{grid-template-columns:1fr;padding:14px}.res-search-actions{grid-column:auto;flex-wrap:wrap}.res-search-actions .btn{flex:1}.res-results-header{align-items:flex-start}.pagination-wrap{align-items:stretch;flex-direction:column}.pagination{justify-content:center}.pagination .page-link-text span{display:none}}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
