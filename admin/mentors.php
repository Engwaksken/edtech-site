<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) die('DB error');
$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

function arr_val(array $row, array $keys, string $default = ''): string {
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return (string)$row[$key];
        }
    }
    return $default;
}

$filter_status    = $_GET['status'] ?? '';
$filter_expertise = $_GET['expertise'] ?? '';
$search           = trim($_GET['q'] ?? '');
$page             = max(1, (int)($_GET['page'] ?? 1));
$per_page         = 20;
$offset           = ($page - 1) * $per_page;

$where  = ['1=1'];
$params = [];
$types  = '';

if ($filter_status !== '') {
    $where[] = 'm.status = ?';
    $params[] = $filter_status;
    $types .= 's';
}

if ($filter_expertise !== '') {
    $where[] = 'FIND_IN_SET(?, m.expertise_areas)';
    $params[] = $filter_expertise;
    $types .= 's';
}

if ($search !== '') {
    $where[] = '(m.full_name LIKE ? OR m.email LIKE ? OR m.organisation LIKE ? OR m.bio LIKE ?)';
    $s = '%' . $search . '%';
    $params = array_merge($params, [$s, $s, $s, $s]);
    $types .= 'ssss';
}

$ws = implode(' AND ', $where);

$cnt_stmt = $conn->prepare("SELECT COUNT(*) FROM mentors m WHERE $ws");
if ($types) {
    $cnt_stmt->bind_param($types, ...$params);
}
$cnt_stmt->execute();
$total = (int)$cnt_stmt->get_result()->fetch_row()[0];
$cnt_stmt->close();

$total_pages = max(1, (int)ceil($total / $per_page));

$list_stmt = $conn->prepare("
    SELECT 
        m.*,
        (SELECT COUNT(*) FROM mentor_assignments a WHERE a.mentor_id = m.id) AS assigned_count,
        (SELECT COUNT(*) FROM mentor_sessions s WHERE s.mentor_id = m.id) AS session_count,
        (SELECT COUNT(*) FROM mentor_sessions s WHERE s.mentor_id = m.id AND s.status = 'completed') AS completed_count,
        (SELECT AVG(s.venture_rating) FROM mentor_sessions s WHERE s.mentor_id = m.id AND s.venture_rating IS NOT NULL) AS avg_rating
    FROM mentors m
    WHERE $ws
    ORDER BY m.created_at DESC, m.id DESC
    LIMIT ? OFFSET ?
");

$all_params = array_merge($params, [$per_page, $offset]);
$all_types  = $types . 'ii';
$list_stmt->bind_param($all_types, ...$all_params);
$list_stmt->execute();
$mentors = $list_stmt->get_result();

$stats = $conn->query("
    SELECT 
        COUNT(*) AS total,
        COALESCE(SUM(status='active'),0) AS active,
        (SELECT COUNT(*) FROM mentor_sessions WHERE status='completed') AS sessions_done,
        (SELECT COUNT(*) FROM mentor_sessions WHERE status='scheduled' AND scheduled_at >= NOW()) AS upcoming_sessions
    FROM mentors
")->fetch_assoc();

$admin_users = $conn->query("
    SELECT *
    FROM admin_users
    ORDER BY full_name ASC, email ASC
");

$expertise_options = [
    'strategy'    => 'Strategy',
    'product'     => 'Product',
    'fundraising' => 'Fundraising',
    'marketing'   => 'Marketing',
    'technology'  => 'Technology',
    'operations'  => 'Operations',
    'legal'       => 'Legal',
    'finance'     => 'Finance',
    'hr'          => 'HR / People',
    'sales'       => 'Sales',
    'design'      => 'Design',
    'impact'      => 'Impact / ESG'
];

$statuses = [
    'active'   => 'Active',
    'inactive' => 'Inactive',
    'pending'  => 'Pending'
];

$site_name = function_exists('get_setting')
    ? get_setting($conn, 'site_name', 'EdTech Fellowship')
    : 'EdTech Fellowship';

$site_url = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '..';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mentors - <?= h($site_name) ?> Admin</title>

<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link rel="stylesheet" href="assets/css/admin.css">


</head>

<body>
<?php include 'includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">

<header class="admin-topbar">
    <div class="topbar-left">
        <div class="topbar-breadcrumb">
            <a href="index.php">Dashboard</a> &gt; <strong>Mentors</strong>
        </div>
    </div>

    <div class="topbar-right">
        <a href="<?= h($site_url) ?>" target="_blank" class="btn btn-secondary btn-sm">
            <i class="fa fa-eye"></i> View Site
        </a>
        <div class="admin-avatar">
            <div class="avatar-circle"><?= strtoupper(substr((string)($ADMIN['full_name'] ?? 'A'), 0, 1)) ?></div>
        </div>
    </div>
</header>

<div class="admin-content">

<?php if (function_exists('show_flash')) show_flash('mentors'); ?>

<div class="page-header">
    <div>
        <h1 class="page-title">Mentor Directory</h1>
        <p class="page-subtitle">
            Profiles, assignments, sessions &amp; engagement tracking &nbsp;.&nbsp; <?= number_format($total) ?> mentors
        </p>
    </div>

    <div class="page-actions">
        <a href="mentor-sessions.php" class="btn btn-secondary">
            <i class="fa fa-calendar-alt"></i> Sessions
        </a>
        <a href="mentor-assignments.php" class="btn btn-secondary">
            <i class="fa fa-link"></i> Assignments
        </a>
        <button class="btn btn-primary" onclick="openMentorModal()">
            <i class="fa fa-plus"></i> Add Mentor
        </button>
    </div>
</div>

<div class="mentor-stats">
    <div class="mstat">
        <div class="n"><?= (int)$stats['total'] ?></div>
        <div class="l">Total Mentors</div>
    </div>
    <div class="mstat legacy-style-5d87957581">
        <div class="n legacy-style-acd97a46d0"><?= (int)$stats['active'] ?></div>
        <div class="l">Active</div>
    </div>
    <div class="mstat">
        <div class="n"><?= (int)$stats['sessions_done'] ?></div>
        <div class="l">Sessions Done</div>
    </div>
    <div class="mstat legacy-style-1f867fb1c5">
        <div class="n legacy-style-6a6a237e2c"><?= (int)$stats['upcoming_sessions'] ?></div>
        <div class="l">Upcoming</div>
    </div>
</div>

<div class="filter-bar">
    <form method="GET">
        <input type="text" name="q" placeholder="Search name, org, bio..." value="<?= h($search) ?>">

        <select name="status" onchange="this.form.submit()">
            <option value="">All Statuses</option>
            <?php foreach ($statuses as $k => $v): ?>
                <option value="<?= h($k) ?>" <?= $filter_status === $k ? 'selected' : '' ?>>
                    <?= h($v) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="expertise" onchange="this.form.submit()">
            <option value="">All Expertise</option>
            <?php foreach ($expertise_options as $k => $v): ?>
                <option value="<?= h($k) ?>" <?= $filter_expertise === $k ? 'selected' : '' ?>>
                    <?= h($v) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn-secondary btn-sm">
            <i class="fa fa-search"></i> Search
        </button>

        <?php if ($search || $filter_status || $filter_expertise): ?>
            <a href="mentors.php" class="btn btn-sm legacy-style-2af7847e65">
                <i class="fa fa-times"></i> Clear
            </a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
<div class="table-wrap">
<table>
<thead>
<tr>
    <th>Mentor</th>
    <th>Expertise</th>
    <th>Availability</th>
    <th>Ventures</th>
    <th>Sessions</th>
    <th>Rating</th>
    <th>Status</th>
    <th>Actions</th>
</tr>
</thead>

<tbody>
<?php $has = false; ?>
<?php while ($m = $mentors->fetch_assoc()): ?>
<?php $has = true; ?>
<tr>
    <td>
        <div class="legacy-style-b87ca3241d">
            <?php if (!empty($m['photo'])): ?>
                <img src="<?= h($site_url . '/' . $m['photo']) ?>" class="mentor-avatar" alt="">
            <?php else: ?>
                <div class="mentor-initials"><?= strtoupper(substr((string)$m['full_name'], 0, 2)) ?></div>
            <?php endif; ?>

            <div>
                <strong><?= h($m['full_name']) ?></strong><br>
                <small class="legacy-style-5872de20d5">
                    <?= h($m['job_title'] ?? '') ?>
                    <?= !empty($m['organisation']) ? ' . ' . h($m['organisation']) : '' ?>
                </small><br>
                <small class="legacy-style-5872de20d5"><?= h($m['email']) ?></small>
            </div>
        </div>
    </td>

    <td>
        <div class="legacy-style-532b403970">
            <?php foreach (array_slice(explode(',', $m['expertise_areas'] ?? ''), 0, 3) as $ex): ?>
                <?php $ex = trim($ex); if (!$ex) continue; ?>
                <span class="expertise-tag"><?= h($expertise_options[$ex] ?? $ex) ?></span>
            <?php endforeach; ?>
        </div>
    </td>

    <td>
        <?php $av = $m['availability'] ?? 'available'; ?>
        <span>
            <span class="avail-dot avail-<?= h($av) ?>"></span><?= h(ucfirst($av)) ?>
        </span>

        <?php if (!empty($m['hours_per_month'])): ?>
            <small class="legacy-style-e879c9de21">
                <?= (int)$m['hours_per_month'] ?>h/mo
            </small>
        <?php endif; ?>
    </td>

    <td>
        <span class="badge badge-info"><?= (int)$m['assigned_count'] ?> ventures</span>
    </td>

    <td>
        <span><?= (int)$m['completed_count'] ?> / <?= (int)$m['session_count'] ?></span>
        <small class="legacy-style-e879c9de21">done / total</small>
    </td>

    <td>
        <?php if ($m['avg_rating'] !== null): ?>
            <?php $rating = max(0, min(5, (int)round((float)$m['avg_rating']))); ?>
            <div class="stars">
                <?= str_repeat('<i class="fa fa-star"></i>', $rating) ?>
                <?= str_repeat('<i class="far fa-star"></i>', 5 - $rating) ?>
            </div>
            <small class="legacy-style-5872de20d5"><?= number_format((float)$m['avg_rating'], 1) ?>/5</small>
        <?php else: ?>
            <small class="legacy-style-5872de20d5">No ratings</small>
        <?php endif; ?>
    </td>

    <td>
        <?php $sc = ['active'=>'badge-success','inactive'=>'badge-gray','pending'=>'badge-warning']; ?>
        <span class="badge <?= $sc[$m['status']] ?? 'badge-gray' ?>">
            <?= h(ucfirst($m['status'])) ?>
        </span>
    </td>

    <td>
        <div class="tbl-actions">
            <a href="mentor-sessions.php?mentor_id=<?= (int)$m['id'] ?>" class="btn btn-sm btn-teal" title="Sessions">
                <i class="fa fa-calendar-alt"></i>
            </a>

            <a href="mentor-assignments.php?mentor_id=<?= (int)$m['id'] ?>" class="btn btn-sm btn-secondary" title="Assignments">
                <i class="fa fa-link"></i>
            </a>

            <button class="btn btn-sm btn-secondary" title="Edit"
                    onclick='editMentor(<?= json_encode($m, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'>
                <i class="fa fa-edit"></i>
            </button>

            <form method="POST" action="includes/process-mentors.php"
                  onsubmit="return confirm('Delete this mentor?')" class="legacy-style-cccfa4560d">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                <button class="btn btn-sm btn-danger">
                    <i class="fa fa-trash"></i>
                </button>
            </form>
        </div>
    </td>
</tr>
<?php endwhile; ?>

<?php if (!$has): ?>
<tr>
    <td colspan="8">
        <div class="empty-state">
            <i class="fa fa-user-tie"></i>
            <h3>No mentors found</h3>
            <p>Add your first mentor to get started.</p>
            <button class="btn btn-primary" onclick="openMentorModal()">
                 Add Mentor
            </button>
        </div>
    </td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>

<?php if ($total_pages > 1): ?>
<div class="pagination">
    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
        <?php $qs = http_build_query(array_merge($_GET, ['page' => $p])); ?>
        <?php if ($p === $page): ?>
            <span class="current"><?= $p ?></span>
        <?php else: ?>
            <a href="?<?= h($qs) ?>"><?= $p ?></a>
        <?php endif; ?>
    <?php endfor; ?>
</div>
<?php endif; ?>

</div>
</div>
</div>

<div class="modal-overlay" id="mentorModal">
<div class="modal modal-lg">
    <div class="modal-header">
        <h2 class="modal-title" id="mentorModalTitle">Add Mentor</h2>
        <button type="button" class="modal-close" onclick="closeMentorModal()">x</button>
    </div>

    <form method="POST" action="includes/process-mentors.php" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="m_id">
        <input type="hidden" name="existing_photo" id="m_existing_photo">
        <input type="hidden" name="admin_user_id" id="m_admin_user_id">

        <div class="modal-body">
        <div class="form-grid form-grid-2">

            <div class="form-group full">
                <label>Select Admin User to Autofill</label>
                <select id="adminUserSelect" class="form-control">
                    <option value="">Select admin user</option>

                    <?php if ($admin_users): ?>
                        <?php while ($u = $admin_users->fetch_assoc()): ?>
                            <?php
                            $au_id       = (int)($u['id'] ?? 0);
                            $au_name     = arr_val($u, ['full_name', 'name', 'username']);
                            $au_email    = arr_val($u, ['email']);
                            $au_phone    = arr_val($u, ['phone', 'contact', 'mobile', 'telephone']);
                            $au_role     = arr_val($u, ['role_name', 'role', 'position', 'job_title', 'title']);
                            $au_org      = arr_val($u, ['organisation', 'organization', 'company', 'department']);
                            $au_location = arr_val($u, ['location', 'country', 'address']);
                            $au_photo    = arr_val($u, ['photo', 'avatar', 'profile_photo', 'profile_image', 'image']);
                            $au_bio      = arr_val($u, ['bio', 'about', 'description']);
                            $au_linkedin = arr_val($u, ['linkedin_url', 'linkedin']);
                            $au_website  = arr_val($u, ['website', 'portfolio']);
                            ?>
                            <option
                                value="<?= $au_id ?>"
                                data-full-name="<?= h($au_name) ?>"
                                data-email="<?= h($au_email) ?>"
                                data-phone="<?= h($au_phone) ?>"
                                data-role="<?= h($au_role) ?>"
                                data-organisation="<?= h($au_org) ?>"
                                data-location="<?= h($au_location) ?>"
                                data-photo="<?= h($au_photo) ?>"
                                data-bio="<?= h($au_bio) ?>"
                                data-linkedin="<?= h($au_linkedin) ?>"
                                data-website="<?= h($au_website) ?>"
                            >
                                <?= h($au_name ?: 'Admin User #' . $au_id) ?>
                                <?= $au_email ? ' - ' . h($au_email) : '' ?>
                            </option>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </select>

                <div class="admin-user-preview" id="adminUserPreview">
                    <div id="auPhotoWrap"></div>
                    <div>
                        <h4 id="auPreviewName"></h4>
                        <p id="auPreviewEmail"></p>
                        <p id="auPreviewMeta"></p>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Full Name <span class="req">*</span></label>
                <input type="text" name="full_name" id="m_name" class="form-control" required>
            </div>

            <div class="form-group">
                <label>Email <span class="req">*</span></label>
                <input type="email" name="email" id="m_email" class="form-control" required>
            </div>

            <div class="form-group">
                <label>Job Title</label>
                <input type="text" name="job_title" id="m_title" class="form-control">
            </div>

            <div class="form-group">
                <label>Organisation</label>
                <input type="text" name="organisation" id="m_org" class="form-control">
            </div>

            <div class="form-group">
                <label>Phone</label>
                <input type="text" name="phone" id="m_phone" class="form-control">
            </div>

            <div class="form-group">
                <label>Country / Location</label>
                <input type="text" name="location" id="m_location" class="form-control">
            </div>

            <div class="form-group">
                <label>LinkedIn</label>
                <input type="url" name="linkedin_url" id="m_linkedin" class="form-control">
            </div>

            <div class="form-group">
                <label>Website / Portfolio</label>
                <input type="url" name="website" id="m_website" class="form-control">
            </div>

            <div class="form-group full">
                <label>Bio / Background</label>
                <textarea name="bio" id="m_bio" class="form-control" rows="4"></textarea>
            </div>

            <div class="form-group full">
                <label>Expertise Areas</label>
                <div id="m_expertise_wrap" class="legacy-style-8fd176fdff">
                    <?php foreach ($expertise_options as $k => $v): ?>
                        <label class="legacy-style-fc3c70d825">
                            <input type="checkbox" name="expertise_areas[]" value="<?= h($k) ?>" class="m-exp-cb">
                            <?= h($v) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label>Availability</label>
                <select name="availability" id="m_availability" class="form-control">
                    <option value="available">Available</option>
                    <option value="busy">Busy</option>
                    <option value="unavailable">Unavailable</option>
                </select>
            </div>

            <div class="form-group">
                <label>Hours Available / Month</label>
                <input type="number" name="hours_per_month" id="m_hours" class="form-control" min="0" max="80" value="4">
            </div>

            <div class="form-group">
                <label>Preferred Session Format</label>
                <select name="session_format" id="m_format" class="form-control">
                    <option value="video">Video Call</option>
                    <option value="in_person">In Person</option>
                    <option value="async">Async Email / Chat</option>
                    <option value="any">Any</option>
                </select>
            </div>

            <div class="form-group">
                <label>Status</label>
                <select name="status" id="m_status" class="form-control">
                    <option value="active">Active</option>
                    <option value="pending">Pending</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div class="form-group full">
                <label>Admin Notes</label>
                <textarea name="admin_notes" id="m_notes" class="form-control" rows="2"></textarea>
            </div>

            <div class="form-group full">
                <label>Profile Photo</label>
                <input type="file" name="photo" class="form-control" accept="image/*" onchange="previewMentorPhoto(this)">
                <img id="m_photo_preview" class="img-preview legacy-style-9714b43b47" alt="">
            </div>

        </div>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeMentorModal()">Cancel</button>
            <button type="submit" class="btn btn-primary">
                <i class="fa fa-save"></i> Save Mentor
            </button>
        </div>
    </form>
</div>
</div>

<script>
document.getElementById('sidebarToggle')?.addEventListener('click', () => {
    document.getElementById('adminSidebar')?.classList.toggle('collapsed');
    document.getElementById('adminMain')?.classList.toggle('collapsed');
});

const mModal = document.getElementById('mentorModal');

function openMentorModal() {
    resetMentorForm();
    mModal.classList.add('open');
}

function closeMentorModal() {
    mModal.classList.remove('open');
}

mModal.addEventListener('click', e => {
    if (e.target === mModal) closeMentorModal();
});

function resetMentorForm() {
    document.getElementById('mentorModalTitle').textContent = 'Add Mentor';
    mModal.querySelector('form').reset();

    document.getElementById('m_id').value = '';
    document.getElementById('m_existing_photo').value = '';
    document.getElementById('m_admin_user_id').value = '';
    document.getElementById('m_photo_preview').style.display = 'none';

    document.querySelectorAll('.m-exp-cb').forEach(cb => cb.checked = false);

    document.getElementById('m_hours').value = 4;
    document.getElementById('m_availability').value = 'available';
    document.getElementById('m_format').value = 'video';
    document.getElementById('m_status').value = 'active';

    const preview = document.getElementById('adminUserPreview');
    preview.classList.remove('active');
    document.getElementById('auPhotoWrap').innerHTML = '';
    document.getElementById('auPreviewName').textContent = '';
    document.getElementById('auPreviewEmail').textContent = '';
    document.getElementById('auPreviewMeta').textContent = '';
}

function editMentor(d) {
    resetMentorForm();

    document.getElementById('mentorModalTitle').textContent = 'Edit Mentor';
    document.getElementById('m_id').value = d.id || '';
    document.getElementById('m_name').value = d.full_name || '';
    document.getElementById('m_email').value = d.email || '';
    document.getElementById('m_title').value = d.job_title || '';
    document.getElementById('m_org').value = d.organisation || '';
    document.getElementById('m_phone').value = d.phone || '';
    document.getElementById('m_location').value = d.location || '';
    document.getElementById('m_linkedin').value = d.linkedin_url || '';
    document.getElementById('m_website').value = d.website || '';
    document.getElementById('m_bio').value = d.bio || '';
    document.getElementById('m_availability').value = d.availability || 'available';
    document.getElementById('m_hours').value = d.hours_per_month || 4;
    document.getElementById('m_format').value = d.session_format || 'video';
    document.getElementById('m_status').value = d.status || 'active';
    document.getElementById('m_notes').value = d.admin_notes || '';
    document.getElementById('m_existing_photo').value = d.photo || '';

    const exp = (d.expertise_areas || '').split(',').map(s => s.trim());
    document.querySelectorAll('.m-exp-cb').forEach(cb => {
        cb.checked = exp.includes(cb.value);
    });

    if (d.photo) {
        const p = document.getElementById('m_photo_preview');
        p.src = '<?= h($site_url) ?>/' + d.photo;
        p.style.display = 'block';
    }

    mModal.classList.add('open');
}

function previewMentorPhoto(input) {
    if (input.files && input.files[0]) {
        const r = new FileReader();

        r.onload = e => {
            const p = document.getElementById('m_photo_preview');
            p.src = e.target.result;
            p.style.display = 'block';
        };

        r.readAsDataURL(input.files[0]);
    }
}

const adminUserSelect = document.getElementById('adminUserSelect');

adminUserSelect?.addEventListener('change', function () {
    const opt = this.options[this.selectedIndex];
    const preview = document.getElementById('adminUserPreview');

    if (!opt || !opt.value) {
        document.getElementById('m_admin_user_id').value = '';
        preview.classList.remove('active');
        return;
    }

    const fullName = opt.dataset.fullName || '';
    const email = opt.dataset.email || '';
    const phone = opt.dataset.phone || '';
    const role = opt.dataset.role || '';
    const organisation = opt.dataset.organisation || '';
    const location = opt.dataset.location || '';
    const photo = opt.dataset.photo || '';
    const bio = opt.dataset.bio || '';
    const linkedin = opt.dataset.linkedin || '';
    const website = opt.dataset.website || '';

    document.getElementById('m_admin_user_id').value = opt.value;
    document.getElementById('m_name').value = fullName;
    document.getElementById('m_email').value = email;
    document.getElementById('m_phone').value = phone;
    document.getElementById('m_title').value = role;
    document.getElementById('m_org').value = organisation;
    document.getElementById('m_location').value = location;
    document.getElementById('m_bio').value = bio;
    document.getElementById('m_linkedin').value = linkedin;
    document.getElementById('m_website').value = website;

    if (photo) {
        document.getElementById('m_existing_photo').value = photo;
        const p = document.getElementById('m_photo_preview');
        p.src = '<?= h($site_url) ?>/' + photo;
        p.style.display = 'block';
    }

    const photoWrap = document.getElementById('auPhotoWrap');

    if (photo) {
        photoWrap.innerHTML = '<img src="<?= h($site_url) ?>/' + photo + '" alt="">';
    } else {
        const initials = fullName ? fullName.substring(0, 2).toUpperCase() : 'AU';
        photoWrap.innerHTML = '<div class="au-avatar">' + initials + '</div>';
    }

    document.getElementById('auPreviewName').textContent = fullName || 'Admin User';
    document.getElementById('auPreviewEmail').textContent = email || 'No email available';
    document.getElementById('auPreviewMeta').textContent = [role, organisation, location].filter(Boolean).join('  ');

    preview.classList.add('active');
});
</script>

</body>
</html>

<?php
$list_stmt->close();
?>
