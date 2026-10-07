<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$filter_status = trim($_GET['status'] ?? '');
$filter_stage  = trim($_GET['stage'] ?? '');
$filter_cohort = (int)($_GET['cohort_id'] ?? 0);
$search        = trim($_GET['q'] ?? '');

$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset   = ($page - 1) * $per_page;

$where  = ['1=1'];
$params = [];
$types  = '';

if ($filter_status !== '') {
    $where[] = 'v.status = ?';
    $params[] = $filter_status;
    $types .= 's';
}

if ($filter_stage !== '') {
    $where[] = 'v.stage = ?';
    $params[] = $filter_stage;
    $types .= 's';
}

if ($filter_cohort > 0) {
    $where[] = 'v.cohort_id = ?';
    $params[] = $filter_cohort;
    $types .= 'i';
}

if ($search !== '') {
    $where[] = "(
        v.name LIKE ? 
        OR v.sector LIKE ? 
        OR v.location LIKE ?
        OR v.cofounder1_name LIKE ? 
        OR v.cofounder2_name LIKE ? 
        OR v.cofounder1_email LIKE ? 
        OR v.cofounder2_email LIKE ?
    )";

    $s = '%' . $search . '%';

    for ($i = 0; $i < 7; $i++) {
        $params[] = $s;
        $types .= 's';
    }
}

$ws = implode(' AND ', $where);

/* Count */
$count_sql = "SELECT COUNT(*) FROM ventures v WHERE $ws";
$count_stmt = $conn->prepare($count_sql);

if (!$count_stmt) {
    die('Count query failed: ' . h($conn->error));
}

if ($types !== '') {
    $count_stmt->bind_param($types, ...$params);
}

$count_stmt->execute();
$total = (int)$count_stmt->get_result()->fetch_row()[0];
$count_stmt->close();

$total_pages = max(1, (int)ceil($total / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $per_page;
}

/* List */
$list_sql = "
    SELECT 
        v.*,
        c.name AS cohort_name,
        vp.email AS portal_email,
        vp.is_active AS portal_is_active,
        vp.last_login AS portal_last_login,
        COALESCE(vp.force_password_change, 0) AS force_password_change,
        (
            SELECT COUNT(*) 
            FROM venture_documents d 
            WHERE d.venture_id = v.id
        ) AS doc_count,
        (
            SELECT COUNT(*) 
            FROM investor_matches m 
            WHERE m.venture_id = v.id
        ) AS investor_count
    FROM ventures v
    LEFT JOIN cohorts c ON c.id = v.cohort_id
    LEFT JOIN venture_portal_access vp ON vp.venture_id = v.id
    WHERE $ws
    ORDER BY v.created_at DESC, v.id DESC
    LIMIT ? OFFSET ?
";

$list_stmt = $conn->prepare($list_sql);

if (!$list_stmt) {
    die('List query failed: ' . h($conn->error));
}

$list_params = array_merge($params, [$per_page, $offset]);
$list_types  = $types . 'ii';

$list_stmt->bind_param($list_types, ...$list_params);
$list_stmt->execute();
$ventures = $list_stmt->get_result();
$list_stmt->close();

$cohorts = $conn->query("SELECT id, name FROM cohorts ORDER BY sort_order ASC, name ASC");

$stages = [
    'idea'     => 'Idea',
    'mvp'      => 'MVP',
    'pre_seed' => 'Pre-Seed',
    'seed'     => 'Seed',
    'series_a' => 'Series A',
    'growth'   => 'Growth'
];

$ven_statuses = [
    'active'    => 'Active',
    'draft'     => 'Draft',
    'inactive'  => 'Inactive',
    'graduated' => 'Graduated'
];

$site_name = function_exists('get_setting')
    ? get_setting($conn, 'site_name', 'EdTech Fellowship')
    : 'EdTech Fellowship';

$s3_enabled = defined('AWS_S3_BUCKET') && defined('AWS_ACCESS_KEY');

function page_url(int $page): string
{
    $query = $_GET;
    $query['page'] = $page;
    return '?' . http_build_query($query);
}

function media_url(?string $path): string
{
    $path = trim((string)$path);

    if ($path === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    return rtrim(SITE_URL, '/') . '/' . ltrim($path, '/');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title>Ventures - <?= h($site_name) ?> Admin</title>

<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link rel="stylesheet" href="assets/css/admin.css">


</head>

<body>

<?php include 'includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">

<header class="admin-topbar">
    <div class="topbar-left">
        <div class="topbar-breadcrumb">
            <a href="index.php">Dashboard</a>
            &rsaquo;
            <strong>Venture Profiles</strong>
        </div>
    </div>

    <div class="topbar-right">
        <a href="<?= h(SITE_URL) ?>" target="_blank" class="btn btn-secondary btn-sm">
            <i class="fa fa-eye"></i>
            View Site
        </a>

        <div class="admin-avatar">
            <div class="avatar-circle">
                <?= h(strtoupper(substr($ADMIN['full_name'] ?? 'A', 0, 1))) ?>
            </div>
        </div>
    </div>
</header>

<div class="admin-content">

<?php show_flash('ventures'); ?>

<div class="page-header">
    <div>
        <h1 class="page-title">Venture Profiles</h1>
        <p class="page-subtitle">
            Document vault<?= $s3_enabled ? ' (AWS S3)' : '' ?>,
            portal access, version tracking &amp; investor matching
            &nbsp;&middot;&nbsp;
            <?= number_format($total) ?> ventures
        </p>
    </div>

    <div class="page-actions">
        <button class="btn btn-primary" onclick="openVentureModal()" type="button">
            <i class="fa fa-plus"></i>
            Add Venture
        </button>
    </div>
</div>

<div class="filter-bar">
    <form method="GET" class="legacy-style-ae125572d5">
        <input type="text" name="q" placeholder="Search name, sector, founder..." value="<?= h($search) ?>">

        <select name="status" onchange="this.form.submit()">
            <option value="">All Statuses</option>
            <?php foreach ($ven_statuses as $k => $v): ?>
                <option value="<?= h($k) ?>" <?= $filter_status === $k ? 'selected' : '' ?>>
                    <?= h($v) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="stage" onchange="this.form.submit()">
            <option value="">All Stages</option>
            <?php foreach ($stages as $k => $v): ?>
                <option value="<?= h($k) ?>" <?= $filter_stage === $k ? 'selected' : '' ?>>
                    <?= h($v) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="cohort_id" onchange="this.form.submit()">
            <option value="">All Cohorts</option>
            <?php if ($cohorts): ?>
                <?php $cohorts->data_seek(0); while ($c = $cohorts->fetch_assoc()): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $filter_cohort === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= h($c['name']) ?>
                    </option>
                <?php endwhile; ?>
            <?php endif; ?>
        </select>

        <button type="submit" class="btn btn-secondary btn-sm">
            <i class="fa fa-search"></i>
            Search
        </button>

        <?php if ($search || $filter_status || $filter_stage || $filter_cohort): ?>
            <a href="ventures.php" class="btn btn-sm legacy-style-2af7847e65">
                <i class="fa fa-times"></i>
                Clear
            </a>
        <?php endif; ?>
    </form>
</div>

<div class="card ventures-card">
    <div class="ventures-table-wrap">
        <table class="ventures-table">
            <thead>
                <tr>
                    <th>Logo</th>
                    <th>Venture</th>
                    <th>Co-Founders</th>
                    <th>Portal Login</th>
                    <th>Cohort</th>
                    <th>Stage</th>
                    <th>Sector</th>
                    <th>Location</th>
                    <th>Founded</th>
                    <th>Impact</th>
                    <th>Documents</th>
                    <th>Investors</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>
            <?php if ($ventures && $ventures->num_rows > 0): ?>
                <?php while ($v = $ventures->fetch_assoc()): ?>
                    <?php
                        $logoUrl = media_url($v['logo'] ?? '');
                        $websiteHost = !empty($v['website'])
                            ? (parse_url($v['website'], PHP_URL_HOST) ?: $v['website'])
                            : '';
                    ?>

                    <tr>
                        <td>
                            <div class="venture-profile-image-box">
                                <?php if ($logoUrl): ?>
                                    <img src="<?= h($logoUrl) ?>" class="venture-profile-image" alt="<?= h($v['name']) ?>">
                                <?php else: ?>
                                    <div class="venture-profile-initials">
                                        <?= h(strtoupper(substr($v['name'] ?? 'VN', 0, 2))) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </td>

                        <td>
                            <div class="venture-name-block">
                                <div class="venture-name-title"><?= h($v['name']) ?></div>

                                <?php if (!empty($v['website'])): ?>
                                    <a href="<?= h($v['website']) ?>" target="_blank" rel="noopener" class="venture-website">
                                        <?= h($websiteHost) ?>
                                    </a>
                                <?php endif; ?>

                                <?php if (!empty($v['tagline'])): ?>
                                    <small><?= h($v['tagline']) ?></small>
                                <?php endif; ?>
                            </div>
                        </td>

                        <td>
                            <div class="cofounder-names">
                                <?php if (!empty($v['cofounder1_name'])): ?>
                                    <div><i class="fa fa-user"></i> <?= h($v['cofounder1_name']) ?></div>
                                <?php endif; ?>

                                <?php if (!empty($v['cofounder2_name'])): ?>
                                    <div><i class="fa fa-user"></i> <?= h($v['cofounder2_name']) ?></div>
                                <?php endif; ?>

                                <?php if (empty($v['cofounder1_name']) && empty($v['cofounder2_name'])): ?>
                                    <span>-</span>
                                <?php endif; ?>
                            </div>
                        </td>

                        <td>
                            <div class="portal-cell">
                                <?php if (!empty($v['portal_email'])): ?>
                                    <div class="portal-email"><?= h($v['portal_email']) ?></div>

                                    <span class="portal-badge <?= (int)$v['portal_is_active'] === 1 ? 'portal-active' : 'portal-disabled' ?>">
                                        <i class="fa <?= (int)$v['portal_is_active'] === 1 ? 'fa-check-circle' : 'fa-ban' ?>"></i>
                                        <?= (int)$v['portal_is_active'] === 1 ? 'Active' : 'Disabled' ?>
                                    </span>

                                    <?php if ((int)$v['force_password_change'] === 1): ?>
                                        <span class="portal-badge portal-force">
                                            <i class="fa fa-key"></i>
                                            Reset Required
                                        </span>
                                    <?php endif; ?>

                                    <?php if (!empty($v['portal_last_login'])): ?>
                                        <div class="portal-muted">Last: <?= h(date('M j, Y', strtotime($v['portal_last_login']))) ?></div>
                                    <?php else: ?>
                                        <div class="portal-muted">Never logged in</div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="portal-muted">No login account</span>
                                <?php endif; ?>
                            </div>
                        </td>

                        <td><?= h($v['cohort_name'] ?? '-') ?></td>

                        <td>
                            <span class="badge badge-info">
                                <?= h($stages[$v['stage']] ?? $v['stage'] ?? '-') ?>
                            </span>
                        </td>

                        <td><?= h($v['sector'] ?? '-') ?></td>

                        <td><?= h($v['location'] ?? '-') ?></td>

                        <td><?= !empty($v['founded_year']) ? h($v['founded_year']) : '-' ?></td>

                        <td>
                            <div class="impact-text">
                                <?= !empty($v['impact_metric']) ? h($v['impact_metric']) : '-' ?>
                            </div>
                        </td>

                        <td>
                            <a href="venture-docs.php?venture_id=<?= (int)$v['id'] ?>" class="doc-chip">
                                <i class="fa fa-folder"></i>
                                <?= (int)$v['doc_count'] ?> docs
                            </a>

                            <?php if ($s3_enabled): ?>
                                <span class="s3-badge">S3</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="doc-chip inv-chip">
                                <i class="fa fa-user-tie"></i>
                                <?= (int)$v['investor_count'] ?>
                            </span>
                        </td>

                        <td>
                            <?php
                            $sc = [
                                'active'    => 'badge-success',
                                'draft'     => 'badge-warning',
                                'inactive'  => 'badge-gray',
                                'graduated' => 'badge-info'
                            ];
                            ?>
                            <span class="badge <?= h($sc[$v['status']] ?? 'badge-gray') ?>">
                                <?= h($ven_statuses[$v['status']] ?? $v['status'] ?? '-') ?>
                            </span>
                        </td>

                        <td>
                            <div class="tbl-actions">
                                <a href="venture-docs.php?venture_id=<?= (int)$v['id'] ?>" class="btn btn-sm btn-teal" title="Documents">
                                    <i class="fa fa-folder-open"></i>
                                </a>

                                <button
                                    class="btn btn-sm btn-primary"
                                    title="View venture details"
                                    type="button"
                                    onclick='viewVenture(<?= json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'
                                >
                                    <i class="fa fa-eye"></i>
                                </button>

                                <button
                                    class="btn btn-sm btn-secondary"
                                    title="Edit"
                                    type="button"
                                    onclick='editVenture(<?= json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'
                                >
                                    <i class="fa fa-edit"></i>
                                </button>

                                <form method="POST" action="includes/process-ventures.php" onsubmit="return confirm('Delete this venture and ALL its documents?')" class="legacy-style-cccfa4560d">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                                    <button class="btn btn-sm btn-danger" type="submit">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="14">
                        <div class="empty-state">
                            <i class="fa fa-rocket"></i>
                            <h3>No ventures found</h3>
                            <p>Add a venture profile or adjust your filters.</p>
                            <button class="btn btn-primary" onclick="openVentureModal()" type="button">
                                Add Venture
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="pagination-wrap">
        <div class="pagination-info">
            Showing <?= $total > 0 ? number_format($offset + 1) : 0 ?>
            -
            <?= number_format(min($offset + $per_page, $total)) ?>
            of <?= number_format($total) ?> ventures
        </div>

        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= h(page_url(max(1, $page - 1))) ?>">
                    Prev
                </a>

                <?php
                $start = max(1, $page - 2);
                $end   = min($total_pages, $page + 2);

                if ($start > 1) {
                    echo '<a href="' . h(page_url(1)) . '">1</a>';
                    if ($start > 2) {
                        echo '<span>...</span>';
                    }
                }

                for ($p = $start; $p <= $end; $p++):
                ?>
                    <?php if ($p === $page): ?>
                        <span class="current"><?= $p ?></span>
                    <?php else: ?>
                        <a href="<?= h(page_url($p)) ?>"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php
                if ($end < $total_pages) {
                    if ($end < $total_pages - 1) {
                        echo '<span>...</span>';
                    }
                    echo '<a href="' . h(page_url($total_pages)) . '">' . $total_pages . '</a>';
                }
                ?>

                <a class="<?= $page >= $total_pages ? 'disabled' : '' ?>" href="<?= h(page_url(min($total_pages, $page + 1))) ?>">
                    Next
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

</div>
</div>

<div class="modal-overlay" id="ventureModal">
<div class="modal modal-lg">

<div class="modal-header">
    <h2 class="modal-title" id="ventureModalTitle">Add Venture</h2>
    <button type="button" class="modal-close" onclick="closeVentureModal()">&times;</button>
</div>

<form method="POST" action="includes/process-ventures.php" enctype="multipart/form-data">
<input type="hidden" name="action" value="save">
<input type="hidden" name="id" id="ven_id">
<input type="hidden" name="existing_logo" id="ven_existing_logo">
<input type="hidden" name="existing_featured_image" id="ven_existing_featured_image">

<div class="modal-body">
<div class="form-grid form-grid-2">

<div class="section-label">Venture Info</div>

<div class="form-group">
    <label>Venture Name <span class="req">*</span></label>
    <input type="text" name="name" id="ven_name" class="form-control" required>
</div>

<div class="form-group">
    <label>Cohort</label>
    <select name="cohort_id" id="ven_cohort_id" class="form-control">
        <option value="">- None -</option>
        <?php if ($cohorts): ?>
            <?php $cohorts->data_seek(0); while ($c = $cohorts->fetch_assoc()): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
            <?php endwhile; ?>
        <?php endif; ?>
    </select>
</div>

<div class="form-group">
    <label>Stage</label>
    <select name="stage" id="ven_stage" class="form-control">
        <?php foreach ($stages as $k => $v): ?>
            <option value="<?= h($k) ?>"><?= h($v) ?></option>
        <?php endforeach; ?>
    </select>
</div>

<div class="form-group">
    <label>Status</label>
    <select name="status" id="ven_status" class="form-control">
        <?php foreach ($ven_statuses as $k => $v): ?>
            <option value="<?= h($k) ?>"><?= h($v) ?></option>
        <?php endforeach; ?>
    </select>
</div>

<div class="form-group">
    <label>Sector / Industry</label>
    <input type="text" name="sector" id="ven_sector" class="form-control">
</div>

<div class="form-group">
    <label>Country</label>
    <input type="text" name="country" id="ven_country" class="form-control">
</div>

<div class="form-group">
    <label>Founded Year</label>
    <input type="number" name="founded_year" id="ven_founded_year" class="form-control" min="1900" max="<?= date('Y') ?>">
</div>

<div class="form-group">
    <label>Location</label>
    <input type="text" name="location" id="ven_location" class="form-control">
</div>

<div class="form-group">
    <label>Website</label>
    <input type="url" name="website" id="ven_website" class="form-control">
</div>

<div class="form-group">
    <label>LinkedIn</label>
    <input type="url" name="linkedin_url" id="ven_linkedin" class="form-control">
</div>

<div class="form-group full">
    <label>Tagline</label>
    <input type="text" name="tagline" id="ven_tagline" class="form-control">
</div>

<div class="form-group full">
    <label>Description / Problem Statement</label>
    <textarea name="description" id="ven_description" class="form-control" rows="4"></textarea>
</div>

<div class="form-group full">
    <label>Impact Metric</label>
    <textarea name="impact_metric" id="ven_impact_metric" class="form-control" rows="2"></textarea>
</div>

<div class="form-group">
    <label>Funding Raised (USD)</label>
    <input type="number" name="funding_raised" id="ven_funding" class="form-control" min="0" step="1000">
</div>

<div class="form-group">
    <label>Funding Sought (USD)</label>
    <input type="number" name="funding_sought" id="ven_funding_sought" class="form-control" min="0" step="1000">
</div>

<hr class="section-divider">

<div class="section-label">Co-Founder 1</div>

<div class="form-group">
    <label>Co-Founder 1 Name</label>
    <input type="text" name="cofounder1_name" id="ven_cofounder1_name" class="form-control">
</div>

<div class="form-group">
    <label>Co-Founder 1 Email</label>
    <input type="email" name="cofounder1_email" id="ven_cofounder1_email" class="form-control">
</div>

<div class="form-group full">
    <label>Co-Founder 1 Contact / Phone</label>
    <input type="text" name="cofounder1_contact" id="ven_cofounder1_contact" class="form-control">
</div>

<hr class="section-divider">

<div class="section-label">Co-Founder 2</div>

<div class="form-group">
    <label>Co-Founder 2 Name</label>
    <input type="text" name="cofounder2_name" id="ven_cofounder2_name" class="form-control">
</div>

<div class="form-group">
    <label>Co-Founder 2 Email</label>
    <input type="email" name="cofounder2_email" id="ven_cofounder2_email" class="form-control">
</div>

<div class="form-group full">
    <label>Co-Founder 2 Contact / Phone</label>
    <input type="text" name="cofounder2_contact" id="ven_cofounder2_contact" class="form-control">
</div>

<div class="form-group full">
    <label>Venture is Youth-Led? <span class="req">*</span></label>
    <div class="radio-inline-group">
        <label class="radio-line">
            <input type="radio" name="youth_led" id="ven_youth_led_yes" value="1" required>
            Yes
        </label>
        <label class="radio-line">
            <input type="radio" name="youth_led" id="ven_youth_led_no" value="0" required>
            No
        </label>
    </div>
</div>

<div class="form-group full">
    <label>Venture Owner <span class="req">*</span></label>
    <div class="radio-inline-group">
        <label class="radio-line">
            <input type="radio" name="owner_gender" id="ven_owner_gender_male" value="male" required>
            Male
        </label>
        <label class="radio-line">
            <input type="radio" name="owner_gender" id="ven_owner_gender_female" value="female" required>
            Female
        </label>
    </div>
</div>

<hr class="section-divider">

<div class="section-label">Venture Portal Login</div>

<div class="hint-box full">
    <strong>Login account:</strong>
    Enter the email used to access the venture dashboard.
</div>

<div class="form-group">
    <label>Portal Login Email</label>
    <input type="email" name="portal_email" id="ven_portal_email" class="form-control">
</div>

<div class="form-group">
    <label>Temporary / New Password</label>
    <input type="text" name="portal_password" id="ven_portal_password" class="form-control" placeholder="Leave blank to keep existing">
    <button type="button" class="btn btn-sm btn-secondary legacy-style-8a77e5a311" onclick="generatePortalPassword()">
        <i class="fa fa-key"></i>
        Generate Password
    </button>
</div>

<div class="form-group">
    <label class="check-line">
        <input type="checkbox" name="portal_is_active" id="ven_portal_active" value="1" checked>
        Enable portal login
    </label>
</div>

<div class="form-group">
    <label class="check-line">
        <input type="checkbox" name="force_password_change" id="ven_force_password_change" value="1" checked>
        Require password change on next login
    </label>
</div>

<div class="form-group full">
    <label class="check-line">
        <input type="checkbox" name="send_login_email" id="ven_send_login_email" value="1" checked>
        Send login details to portal email
    </label>
</div>

<hr class="section-divider">

<div class="section-label">Media &amp; Notes</div>

<div class="form-group full">
    <label>Featured Image</label>
    <input type="file" name="featured_image" class="form-control" accept="image/*" onchange="previewFeaturedImage(this)">
    <img id="ven_featured_preview" class="img-preview featured-preview legacy-style-9714b43b47" alt="">
</div>

<div class="form-group full">
    <label>Profile Image / Logo</label>
    <input type="file" name="logo" class="form-control" accept="image/*" onchange="previewVenLogo(this)">
    <img id="ven_logo_preview" class="img-preview legacy-style-9714b43b47" alt="">
</div>

<div class="form-group full">
    <label>Admin Notes</label>
    <textarea name="admin_notes" id="ven_notes" class="form-control" rows="2"></textarea>
</div>

</div>
</div>

<div class="modal-footer">
    <button type="button" class="btn btn-secondary" onclick="closeVentureModal()">Cancel</button>
    <button type="submit" class="btn btn-primary">
        <i class="fa fa-save"></i>
        Save Venture
    </button>
</div>

</form>
</div>
</div>


<div class="modal-overlay" id="ventureViewModal" aria-hidden="true">
<div class="modal venture-view-modal" role="dialog" aria-modal="true" aria-labelledby="ventureViewTitle">
<div class="modal-header"><h2 class="modal-title" id="ventureViewTitle">Venture Details</h2><button type="button" class="modal-close" onclick="closeVentureViewModal()" aria-label="Close">&times;</button></div>
<div class="venture-view-body">
<section class="venture-view-hero">
<div class="venture-view-logo-wrap"><img id="view_logo" class="venture-view-logo legacy-style-6b99de8b69" alt=""><div id="view_initials" class="venture-view-initials">VN</div></div>
<div class="venture-view-heading"><h3 id="view_name">Venture</h3><p id="view_tagline" class="venture-view-tagline">No tagline provided.</p>
<div class="venture-view-badges"><span id="view_status_badge" class="badge badge-gray">Status</span><span id="view_stage_badge" class="badge badge-info">Stage</span><span id="view_cohort_badge" class="badge badge-warning">Cohort</span></div>
<div class="venture-view-links"><a id="view_website_link" href="#" target="_blank" rel="noopener" class="legacy-style-6b99de8b69"><i class="fa fa-globe"></i><span>Website</span></a><a id="view_linkedin_link" href="#" target="_blank" rel="noopener" class="legacy-style-6b99de8b69"><i class="fab fa-linkedin"></i><span>LinkedIn</span></a><a id="view_portal_email_link" href="#" class="legacy-style-6b99de8b69"><i class="fa fa-envelope"></i><span id="view_portal_email_text"></span></a></div>
</div></section>
<div class="venture-view-grid">
<section class="venture-detail-card"><h4><i class="fa fa-building"></i> Venture Information</h4><dl class="venture-detail-list"><dt>Sector</dt><dd id="view_sector">-</dd><dt>Country</dt><dd id="view_country">-</dd><dt>Location</dt><dd id="view_location">-</dd><dt>Founded</dt><dd id="view_founded_year">-</dd><dt>Youth-led</dt><dd id="view_youth_led">-</dd><dt>Owner</dt><dd id="view_owner_gender">-</dd></dl></section>
<section class="venture-detail-card"><h4><i class="fa fa-coins"></i> Funding &amp; Activity</h4><dl class="venture-detail-list"><dt>Funding raised</dt><dd id="view_funding_raised">-</dd><dt>Funding sought</dt><dd id="view_funding_sought">-</dd><dt>Documents</dt><dd id="view_doc_count">0</dd><dt>Investor matches</dt><dd id="view_investor_count">0</dd><dt>Created</dt><dd id="view_created_at">-</dd><dt>Last updated</dt><dd id="view_updated_at">-</dd></dl></section>
<section class="venture-detail-card"><h4><i class="fa fa-user"></i> Co-Founder 1</h4><dl class="venture-detail-list"><dt>Name</dt><dd id="view_cofounder1_name">-</dd><dt>Email</dt><dd id="view_cofounder1_email">-</dd><dt>Phone</dt><dd id="view_cofounder1_contact">-</dd></dl></section>
<section class="venture-detail-card"><h4><i class="fa fa-user"></i> Co-Founder 2</h4><dl class="venture-detail-list"><dt>Name</dt><dd id="view_cofounder2_name">-</dd><dt>Email</dt><dd id="view_cofounder2_email">-</dd><dt>Phone</dt><dd id="view_cofounder2_contact">-</dd></dl></section>
<section class="venture-detail-card"><h4><i class="fa fa-key"></i> Portal Access</h4><dl class="venture-detail-list"><dt>Portal email</dt><dd id="view_portal_email">-</dd><dt>Access status</dt><dd id="view_portal_status">-</dd><dt>Password reset</dt><dd id="view_force_password">-</dd><dt>Last login</dt><dd id="view_portal_last_login">-</dd></dl></section>
<section class="venture-detail-card"><h4><i class="fa fa-chart-line"></i> Impact</h4><p id="view_impact_metric" class="venture-detail-text venture-view-empty">No impact metric provided.</p></section>
<section class="venture-detail-card full"><h4><i class="fa fa-align-left"></i> Description / Problem Statement</h4><p id="view_description" class="venture-detail-text venture-view-empty">No description provided.</p></section>
<section class="venture-detail-card full legacy-style-6b99de8b69" id="view_featured_card"><h4><i class="fa fa-image"></i> Featured Image</h4><img id="view_featured_image" class="venture-featured-full" alt=""></section>
<section class="venture-detail-card full"><h4><i class="fa fa-sticky-note"></i> Admin Notes</h4><p id="view_admin_notes" class="venture-detail-text venture-view-empty">No admin notes.</p></section>
</div></div>
<div class="modal-footer"><a id="view_documents_link" class="btn btn-teal" href="#"><i class="fa fa-folder-open"></i> Documents</a><button type="button" class="btn btn-secondary" onclick="closeVentureViewModal()">Close</button><button type="button" class="btn btn-primary" id="view_edit_button"><i class="fa fa-edit"></i> Edit Venture</button></div>
</div></div>

<script>
document.getElementById('sidebarToggle')?.addEventListener('click', () => {
    document.getElementById('adminSidebar')?.classList.toggle('collapsed');
    document.getElementById('adminMain')?.classList.toggle('collapsed');
});

const venModal = document.getElementById('ventureModal');

const ventureViewModal = document.getElementById('ventureViewModal');
let currentViewedVenture = null;
function setViewText(id,value,fallback='-'){const el=document.getElementById(id);if(!el)return;const v=value===null||value===undefined?'':String(value).trim();el.textContent=v!==''?v:fallback}
function formatViewMoney(value){const n=Number(value);return Number.isFinite(n)?new Intl.NumberFormat('en-US',{style:'currency',currency:'USD',maximumFractionDigits:2}).format(n):'$0'}
function formatViewDate(value){if(!value)return '-';const d=new Date(String(value).replace(' ','T'));return Number.isNaN(d.getTime())?String(value):new Intl.DateTimeFormat('en-GB',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}).format(d)}
function viewMediaUrl(path){const v=String(path||'').trim();if(!v)return '';if(/^https?:\/\//i.test(v))return v;return '<?= h(rtrim(SITE_URL, '/')) ?>/'+v.replace(/^\/+/, '')}
function setViewLink(id,href,labelId,label){const a=document.getElementById(id);if(!a)return;if(href){a.href=href;a.style.display='inline-flex';if(labelId)document.getElementById(labelId).textContent=label}else{a.removeAttribute('href');a.style.display='none'}}
function viewVenture(d){currentViewedVenture=d||{};const name=String(d.name||'Venture');setViewText('view_name',name,'Venture');setViewText('ventureViewTitle',name+' - Venture Details','Venture Details');setViewText('view_tagline',d.tagline,'No tagline provided.');setViewText('view_sector',d.sector);setViewText('view_country',d.country);setViewText('view_location',d.location);setViewText('view_founded_year',d.founded_year);setViewText('view_youth_led',String(d.youth_led??'0')==='1'?'Yes':'No');setViewText('view_owner_gender',d.owner_gender?String(d.owner_gender).charAt(0).toUpperCase()+String(d.owner_gender).slice(1):'-');setViewText('view_funding_raised',formatViewMoney(d.funding_raised||0));setViewText('view_funding_sought',formatViewMoney(d.funding_sought||0));setViewText('view_doc_count',String(d.doc_count??0));setViewText('view_investor_count',String(d.investor_count??0));setViewText('view_created_at',formatViewDate(d.created_at));setViewText('view_updated_at',formatViewDate(d.updated_at));setViewText('view_cofounder1_name',d.cofounder1_name);setViewText('view_cofounder1_email',d.cofounder1_email);setViewText('view_cofounder1_contact',d.cofounder1_contact);setViewText('view_cofounder2_name',d.cofounder2_name);setViewText('view_cofounder2_email',d.cofounder2_email);setViewText('view_cofounder2_contact',d.cofounder2_contact);setViewText('view_portal_email',d.portal_email,'No login account');setViewText('view_portal_status',d.portal_email?(String(d.portal_is_active??'0')==='1'?'Active':'Disabled'):'No login account');setViewText('view_force_password',String(d.force_password_change??'0')==='1'?'Required':'Not required');setViewText('view_portal_last_login',d.portal_last_login?formatViewDate(d.portal_last_login):'Never logged in');setViewText('view_description',d.description,'No description provided.');setViewText('view_impact_metric',d.impact_metric,'No impact metric provided.');setViewText('view_admin_notes',d.admin_notes,'No admin notes.');const stages=<?= json_encode($stages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;const statuses=<?= json_encode($ven_statuses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;setViewText('view_stage_badge',stages[d.stage]||d.stage||'-');setViewText('view_status_badge',statuses[d.status]||d.status||'-');setViewText('view_cohort_badge',d.cohort_name||'No cohort');const sb=document.getElementById('view_status_badge');sb.className='badge '+({active:'badge-success',draft:'badge-warning',inactive:'badge-gray',graduated:'badge-info'}[d.status]||'badge-gray');const logo=document.getElementById('view_logo'),initials=document.getElementById('view_initials'),logoUrl=viewMediaUrl(d.logo);if(logoUrl){logo.src=logoUrl;logo.alt=name;logo.style.display='block';initials.style.display='none'}else{logo.removeAttribute('src');logo.style.display='none';initials.textContent=name.replace(/[^A-Za-z0-9 ]/g,'').split(/\s+/).filter(Boolean).slice(0,2).map(x=>x.charAt(0)).join('').toUpperCase()||'VN';initials.style.display='flex'}setViewLink('view_website_link',d.website||'',null,'');setViewLink('view_linkedin_link',d.linkedin_url||'',null,'');setViewLink('view_portal_email_link',d.portal_email?'mailto:'+d.portal_email:'','view_portal_email_text',d.portal_email||'');const fc=document.getElementById('view_featured_card'),fi=document.getElementById('view_featured_image'),fu=viewMediaUrl(d.featured_image);if(fu){fi.src=fu;fi.alt=name+' featured image';fc.style.display='block'}else{fi.removeAttribute('src');fc.style.display='none'}document.getElementById('view_documents_link').href='venture-docs.php?venture_id='+encodeURIComponent(String(d.id||''));document.getElementById('view_edit_button').onclick=function(){closeVentureViewModal();editVenture(currentViewedVenture)};ventureViewModal.classList.add('open');ventureViewModal.setAttribute('aria-hidden','false');document.body.style.overflow='hidden'}
function closeVentureViewModal(){ventureViewModal.classList.remove('open');ventureViewModal.setAttribute('aria-hidden','true');document.body.style.overflow='';currentViewedVenture=null}
ventureViewModal.addEventListener('click',e=>{if(e.target===ventureViewModal)closeVentureViewModal()});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){if(ventureViewModal.classList.contains('open'))closeVentureViewModal();else if(venModal.classList.contains('open'))closeVentureModal()}});


function openVentureModal() {
    resetVenForm();
    venModal.classList.add('open');
}

function closeVentureModal() {
    venModal.classList.remove('open');
}

venModal.addEventListener('click', function(e) {
    if (e.target === venModal) {
        closeVentureModal();
    }
});

function resetVenForm() {
    document.getElementById('ventureModalTitle').textContent = 'Add Venture';

    const form = venModal.querySelector('form');
    form.reset();

    document.getElementById('ven_id').value = '';
    document.getElementById('ven_existing_logo').value = '';
    document.getElementById('ven_existing_featured_image').value = '';

    document.getElementById('ven_logo_preview').style.display = 'none';
    document.getElementById('ven_logo_preview').src = '';

    document.getElementById('ven_featured_preview').style.display = 'none';
    document.getElementById('ven_featured_preview').src = '';

    document.getElementById('ven_youth_led_yes').checked = false;
    document.getElementById('ven_youth_led_no').checked = false;

    document.getElementById('ven_owner_gender_male').checked = false;
    document.getElementById('ven_owner_gender_female').checked = false;

    document.getElementById('ven_portal_active').checked = true;
    document.getElementById('ven_force_password_change').checked = true;
    document.getElementById('ven_send_login_email').checked = true;
    document.getElementById('ven_portal_password').placeholder = 'Set temporary password';
}

function editVenture(d) {
    resetVenForm();

    document.getElementById('ventureModalTitle').textContent = 'Edit Venture';

    document.getElementById('ven_id').value = d.id || '';
    document.getElementById('ven_name').value = d.name || '';
    document.getElementById('ven_cohort_id').value = d.cohort_id || '';
    document.getElementById('ven_stage').value = d.stage || 'idea';
    document.getElementById('ven_status').value = d.status || 'active';

    document.getElementById('ven_sector').value = d.sector || '';
    document.getElementById('ven_country').value = d.country || '';
    document.getElementById('ven_founded_year').value = d.founded_year || '';
    document.getElementById('ven_location').value = d.location || '';

    document.getElementById('ven_website').value = d.website || '';
    document.getElementById('ven_linkedin').value = d.linkedin_url || '';
    document.getElementById('ven_tagline').value = d.tagline || '';
    document.getElementById('ven_description').value = d.description || '';
    document.getElementById('ven_impact_metric').value = d.impact_metric || '';

    document.getElementById('ven_funding').value = d.funding_raised || '';
    document.getElementById('ven_funding_sought').value = d.funding_sought || '';

    document.getElementById('ven_cofounder1_name').value = d.cofounder1_name || '';
    document.getElementById('ven_cofounder1_email').value = d.cofounder1_email || '';
    document.getElementById('ven_cofounder1_contact').value = d.cofounder1_contact || '';

    document.getElementById('ven_cofounder2_name').value = d.cofounder2_name || '';
    document.getElementById('ven_cofounder2_email').value = d.cofounder2_email || '';
    document.getElementById('ven_cofounder2_contact').value = d.cofounder2_contact || '';

    document.getElementById('ven_notes').value = d.admin_notes || '';

    document.getElementById('ven_existing_logo').value = d.logo || '';
    document.getElementById('ven_existing_featured_image').value = d.featured_image || '';

    const isYouthLed = String(d.youth_led ?? '0') === '1';
    document.getElementById('ven_youth_led_yes').checked = isYouthLed;
    document.getElementById('ven_youth_led_no').checked = !isYouthLed;

    const ownerGender = String(d.owner_gender || '').toLowerCase();
    document.getElementById('ven_owner_gender_male').checked = ownerGender === 'male';
    document.getElementById('ven_owner_gender_female').checked = ownerGender === 'female';

    document.getElementById('ven_portal_email').value = d.portal_email || d.cofounder1_email || '';
    document.getElementById('ven_portal_password').value = '';
    document.getElementById('ven_portal_password').placeholder = 'Leave blank to keep existing password';

    document.getElementById('ven_portal_active').checked = String(d.portal_is_active ?? '1') === '1';
    document.getElementById('ven_force_password_change').checked = String(d.force_password_change ?? '0') === '1';
    document.getElementById('ven_send_login_email').checked = false;

    if (d.logo) {
        const logoPreview = document.getElementById('ven_logo_preview');
        logoPreview.src = '<?= h(rtrim(SITE_URL, '/')) ?>/' + String(d.logo).replace(/^\/+/, '');
        logoPreview.style.display = 'block';
    }

    if (d.featured_image) {
        const featuredPreview = document.getElementById('ven_featured_preview');
        featuredPreview.src = '<?= h(rtrim(SITE_URL, '/')) ?>/' + String(d.featured_image).replace(/^\/+/, '');
        featuredPreview.style.display = 'block';
    }

    venModal.classList.add('open');
}

function previewVenLogo(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();

        reader.onload = function(e) {
            const preview = document.getElementById('ven_logo_preview');
            preview.src = e.target.result;
            preview.style.display = 'block';
        };

        reader.readAsDataURL(input.files[0]);
    }
}

function previewFeaturedImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();

        reader.onload = function(e) {
            const preview = document.getElementById('ven_featured_preview');
            preview.src = e.target.result;
            preview.style.display = 'block';
        };

        reader.readAsDataURL(input.files[0]);
    }
}

function generatePortalPassword() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    let pass = '';

    for (let i = 0; i < 12; i++) {
        pass += chars.charAt(Math.floor(Math.random() * chars.length));
    }

    document.getElementById('ven_portal_password').value = pass;
    document.getElementById('ven_send_login_email').checked = true;
    document.getElementById('ven_force_password_change').checked = true;
}
</script>

</body>
</html>
