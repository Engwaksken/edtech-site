<?php
declare(strict_types=1);

require_once '../includes/config.php';
require_once 'includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$event_id = (int)($_GET['event_id'] ?? 0);

/*
|--------------------------------------------------------------------------
| Load selected event
|--------------------------------------------------------------------------
*/
$event = null;

if ($event_id > 0) {
    $ev_stmt = $conn->prepare("SELECT * FROM events WHERE id=? LIMIT 1");
    $ev_stmt->bind_param('i', $event_id);
    $ev_stmt->execute();
    $event = $ev_stmt->get_result()->fetch_assoc();
    $ev_stmt->close();
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/
$filter_attended = $_GET['attended'] ?? '';
$search          = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];
$types  = '';

if ($event_id > 0) {
    $where[]  = 'r.event_id=?';
    $params[] = $event_id;
    $types   .= 'i';
}

if ($filter_attended !== '') {
    $where[]  = 'r.attended=?';
    $params[] = (int)$filter_attended;
    $types   .= 'i';
}

if ($search !== '') {
    $where[] = '(r.name LIKE ? OR r.email LIKE ? OR r.organisation LIKE ? OR r.role LIKE ?)';
    $s = '%' . $search . '%';
    $params[] = $s;
    $params[] = $s;
    $params[] = $s;
    $params[] = $s;
    $types .= 'ssss';
}

$ws = implode(' AND ', $where);

/*
|--------------------------------------------------------------------------
| Load registrations
|--------------------------------------------------------------------------
*/
$regs_stmt = $conn->prepare("
    SELECT 
        r.*,
        e.title AS event_title,
        e.event_date
    FROM event_registrations r
    INNER JOIN events e ON e.id = r.event_id
    WHERE {$ws}
    ORDER BY r.registered_at DESC, r.id DESC
");

if ($types !== '') {
    $regs_stmt->bind_param($types, ...$params);
}

$regs_stmt->execute();
$regs = $regs_stmt->get_result();

/*
|--------------------------------------------------------------------------
| Counts
|--------------------------------------------------------------------------
*/
if ($event_id > 0) {
    $count_stmt = $conn->prepare("
        SELECT 
            COUNT(*) AS total,
            COALESCE(SUM(attended=1),0) AS attended,
            COALESCE(SUM(attended=0),0) AS not_attended
        FROM event_registrations
        WHERE event_id=?
    ");
    $count_stmt->bind_param('i', $event_id);
    $count_stmt->execute();
    $count_row = $count_stmt->get_result()->fetch_assoc();
    $count_stmt->close();
} else {
    $count_row = $conn->query("
        SELECT 
            COUNT(*) AS total,
            COALESCE(SUM(attended=1),0) AS attended,
            COALESCE(SUM(attended=0),0) AS not_attended
        FROM event_registrations
    ")->fetch_assoc();
}

$total_registered = (int)($count_row['total'] ?? 0);
$total_attended   = (int)($count_row['attended'] ?? 0);
$total_absent     = (int)($count_row['not_attended'] ?? 0);
$attendance_rate  = $total_registered > 0 ? round(($total_attended / $total_registered) * 100) : 0;

/*
|--------------------------------------------------------------------------
| Events dropdown
|--------------------------------------------------------------------------
*/
$all_events = $conn->query("
    SELECT id, title, event_date 
    FROM events 
    ORDER BY event_date DESC, id DESC
");

/*
|--------------------------------------------------------------------------
| Ventures dropdown
|--------------------------------------------------------------------------
*/
$ventures = $conn->query("
    SELECT 
        id,
        cohort_id,
        name,
        slug,
        cofounder1_name,
        cofounder1_email,
        cofounder1_contact,
        cofounder2_name,
        cofounder2_email,
        cofounder2_contact,
        tagline,
        description,
        founded_year,
        location,
        impact_metric,
        featured_image,
        stage,
        status,
        sector,
        country,
        website,
        linkedin_url,
        logo,
        funding_raised,
        funding_sought,
        admin_notes,
        sort_order,
        created_at,
        updated_at
    FROM ventures
    ORDER BY sort_order ASC, name ASC
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
<meta name="viewport" content="width=device-width,initial-scale=1">

<title>Attendees - <?= h($site_name) ?> Admin</title>

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
            <a href="index.php">Dashboard</a> &gt;
            <a href="events.php">Events</a> &gt;
            <strong><?= $event ? h($event['title']) : 'All Attendees' ?></strong>
        </div>
    </div>

    <div class="topbar-right">
        <div class="admin-avatar">
            <div class="avatar-circle">
                <?= strtoupper(substr((string)($ADMIN['full_name'] ?? 'A'), 0, 1)) ?>
            </div>
        </div>
    </div>
</header>

<div class="admin-content">

<?php if (function_exists('show_flash')): ?>
    <?php show_flash('attendees'); ?>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <?= $event ? h($event['title']) . ' - Attendees' : 'All Registrations' ?>
        </h1>
        <p class="page-subtitle">Registration, venture selection, and attendance tracking</p>
    </div>

    <div class="page-actions">
        <?php if ($event_id > 0): ?>
            <form method="POST" action="includes/process-events.php" class="legacy-style-cccfa4560d">
                <input type="hidden" name="action" value="mark_all_attended">
                <input type="hidden" name="event_id" value="<?= (int)$event_id ?>">
                <button class="btn btn-secondary" onclick="return confirm('Mark ALL registrants as attended?')">
                    <i class="fa fa-check-double"></i> Mark All Attended
                </button>
            </form>
        <?php endif; ?>

        <button class="btn btn-primary" onclick="openRegModal()">
            <i class="fa fa-plus"></i> Add Registration
        </button>

        <a href="?event_id=<?= (int)$event_id ?>&export=csv" class="btn btn-secondary">
            <i class="fa fa-download"></i> Export CSV
        </a>
    </div>
</div>

<div class="att-stats">
    <div class="att-stat">
        <div class="sn"><?= $total_registered ?></div>
        <div class="sl">Registered</div>
    </div>

    <div class="att-stat legacy-style-5d87957581">
        <div class="sn legacy-style-acd97a46d0"><?= $total_attended ?></div>
        <div class="sl">Attended</div>
    </div>

    <div class="att-stat legacy-style-1f867fb1c5">
        <div class="sn legacy-style-6a6a237e2c"><?= $total_absent ?></div>
        <div class="sl">Absent</div>
    </div>

    <div class="att-stat legacy-style-d2428eb450">
        <div class="sn"><?= $attendance_rate ?>%</div>
        <div class="sl">Attendance Rate</div>
    </div>
</div>

<div class="filter-bar">
    <form method="GET">
        <input type="text" name="q" placeholder="Search name, email, organisation..."
               value="<?= h($search) ?>">

        <select name="event_id" onchange="this.form.submit()">
            <option value="">All Events</option>
            <?php if ($all_events): ?>
                <?php while ($ev = $all_events->fetch_assoc()): ?>
                    <option value="<?= (int)$ev['id'] ?>" <?= $event_id === (int)$ev['id'] ? 'selected' : '' ?>>
                        <?= h($ev['title']) ?>
                    </option>
                <?php endwhile; ?>
            <?php endif; ?>
        </select>

        <select name="attended" onchange="this.form.submit()">
            <option value="">All Attendance</option>
            <option value="1" <?= $filter_attended === '1' ? 'selected' : '' ?>>Attended</option>
            <option value="0" <?= $filter_attended === '0' ? 'selected' : '' ?>>Not Attended</option>
        </select>

        <button type="submit" class="btn btn-secondary btn-sm">
            <i class="fa fa-search"></i> Search
        </button>

        <?php if ($search !== '' || $filter_attended !== '' || $event_id > 0): ?>
            <a href="event-attendees.php" class="btn btn-sm legacy-style-2af7847e65">
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
                <?php if (!$event_id): ?>
                    <th>Event</th>
                <?php endif; ?>
                <th>Attendee</th>
                <th>Organisation</th>
                <th>Role</th>
                <th>Registered</th>
                <th>Check-in Code</th>
                <th>Attended</th>
                <th>Actions</th>
            </tr>
            </thead>

            <tbody>
            <?php $has = false; ?>
            <?php while ($r = $regs->fetch_assoc()): ?>
                <?php $has = true; ?>
                <tr id="reg_row_<?= (int)$r['id'] ?>">
                    <?php if (!$event_id): ?>
                        <td>
                            <small>
                                <?= h($r['event_title']) ?><br>
                                <?= !empty($r['event_date']) ? date('M j, Y', strtotime($r['event_date'])) : '-' ?>
                            </small>
                        </td>
                    <?php endif; ?>

                    <td>
                        <strong><?= h($r['name']) ?></strong><br>
                        <small class="legacy-style-5872de20d5"><?= h($r['email']) ?></small>
                    </td>

                    <td class="legacy-style-1fe8667280">
                        <?= h($r['organisation'] ?? '-') ?>
                    </td>

                    <td class="legacy-style-1fe8667280">
                        <?= h($r['role'] ?? '-') ?>
                    </td>

                    <td class="legacy-style-c85ef718a8">
                        <?= !empty($r['registered_at']) ? date('M j, Y g:ia', strtotime($r['registered_at'])) : '-' ?>
                    </td>

                    <td>
                        <?php if (!empty($r['checkin_code'])): ?>
                            <code class="legacy-style-28f66f8b5b">
                                <?= h($r['checkin_code']) ?>
                            </code>
                        <?php else: ?>
                            <span class="legacy-style-5872de20d5">-</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <label class="att-toggle">
                            <label class="toggle-switch">
                                <input type="checkbox"
                                       <?= !empty($r['attended']) ? 'checked' : '' ?>
                                       onchange="toggleAttended(<?= (int)$r['id'] ?>, this.checked)">
                                <span class="toggle-track"></span>
                                <span class="toggle-knob"></span>
                            </label>

                            <span id="att_label_<?= (int)$r['id'] ?>" style="font-size:12px;color:var(--text-muted)">
                                <?= !empty($r['attended']) ? 'Yes' : 'No' ?>
                            </span>
                        </label>
                    </td>

                    <td>
                        <div class="tbl-actions">
                            <form method="POST" action="includes/process-events.php"
                                  onsubmit="return confirm('Remove this registration?')" class="legacy-style-cccfa4560d">
                                <input type="hidden" name="action" value="delete_registration">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <input type="hidden" name="event_id" value="<?= (int)$event_id ?>">
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
                    <td colspan="<?= $event_id ? 7 : 8 ?>">
                        <div class="empty-state">
                            <i class="fa fa-users"></i>
                            <h3>No registrations yet</h3>
                            <p>Registrations will appear here when people sign up.</p>
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

<div class="modal-overlay" id="regModal">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title">Add Registration</h2>
            <button type="button" class="modal-close" onclick="closeRegModal()">x</button>
        </div>

        <form method="POST" action="includes/process-events.php">
            <input type="hidden" name="action" value="add_registration">
            <input type="hidden" name="event_id" value="<?= (int)$event_id ?>">

            <div class="modal-body">
                <div class="form-grid form-grid-2">

                    <?php if (!$event_id): ?>
                        <div class="form-group full">
                            <label>Event <span class="req">*</span></label>
                            <select name="event_id_sel" class="form-control" required>
                                <option value="">- Select Event -</option>
                                <?php
                                $modal_events = $conn->query("
                                    SELECT id, title, event_date 
                                    FROM events 
                                    ORDER BY event_date DESC, id DESC
                                ");
                                ?>
                                <?php while ($ev = $modal_events->fetch_assoc()): ?>
                                    <option value="<?= (int)$ev['id'] ?>">
                                        <?= h($ev['title']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="form-group full">
                        <label>Select Venture</label>
                        <select id="ventureSelect" class="form-control">
                            <option value="">Select Venture to Autofill </option>

                            <?php if ($ventures): ?>
                                <?php while ($v = $ventures->fetch_assoc()): ?>
                                    <option
                                        value="<?= (int)$v['id'] ?>"
                                        data-name="<?= h($v['name']) ?>"
                                        data-cofounder1-name="<?= h($v['cofounder1_name']) ?>"
                                        data-cofounder1-email="<?= h($v['cofounder1_email']) ?>"
                                        data-cofounder1-contact="<?= h($v['cofounder1_contact']) ?>"
                                        data-cofounder2-name="<?= h($v['cofounder2_name']) ?>"
                                        data-cofounder2-email="<?= h($v['cofounder2_email']) ?>"
                                        data-cofounder2-contact="<?= h($v['cofounder2_contact']) ?>"
                                        data-tagline="<?= h($v['tagline']) ?>"
                                        data-description="<?= h($v['description']) ?>"
                                        data-founded-year="<?= h($v['founded_year']) ?>"
                                        data-location="<?= h($v['location']) ?>"
                                        data-impact-metric="<?= h($v['impact_metric']) ?>"
                                        data-stage="<?= h($v['stage']) ?>"
                                        data-status="<?= h($v['status']) ?>"
                                        data-sector="<?= h($v['sector']) ?>"
                                        data-country="<?= h($v['country']) ?>"
                                        data-website="<?= h($v['website']) ?>"
                                        data-linkedin-url="<?= h($v['linkedin_url']) ?>"
                                        data-funding-raised="<?= h($v['funding_raised']) ?>"
                                        data-funding-sought="<?= h($v['funding_sought']) ?>"
                                    >
                                        <?= h($v['name']) ?>
                                        <?= !empty($v['cofounder1_name']) ? ' - ' . h($v['cofounder1_name']) : '' ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>

                        <div class="venture-preview" id="venturePreview">
                            <h4 id="vpName"></h4>
                            <p id="vpTagline"></p>
                            <p id="vpDetails"></p>
                            <p id="vpFunding"></p>
                        </div>
                    </div>

                    <div class="form-group full">
                        <label>Founder to Register</label>
                        <select id="founderSelect" class="form-control">
                            <option value="cofounder1">Cofounder 1</option>
                            <option value="cofounder2">Cofounder 2</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Full Name <span class="req">*</span></label>
                        <input type="text" name="name" id="regName" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label>Email <span class="req">*</span></label>
                        <input type="email" name="email" id="regEmail" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label>Organisation / Venture</label>
                        <input type="text" name="organisation" id="regOrganisation" class="form-control">
                    </div>

                    <div class="form-group">
                        <label>Role / Position</label>
                        <input type="text" name="role" id="regRole" class="form-control">
                    </div>

                    <div class="form-group">
                        <label>Contact</label>
                        <input type="text" id="regContact" class="form-control" readonly>
                    </div>

                    <div class="form-group">
                        <label>Website</label>
                        <input type="text" id="regWebsite" class="form-control" readonly>
                    </div>

                    <div class="form-group full">
                        <label>Venture Notes</label>
                        <textarea id="ventureNotes" class="form-control" rows="3" readonly></textarea>
                    </div>

                    <div class="form-group full">
                        <label class="legacy-style-c92fd9467d">
                            <input type="checkbox" name="attended" value="1">
                            Mark as already attended
                        </label>
                    </div>

                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeRegModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-save"></i> Add Registration
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

const regModal = document.getElementById('regModal');

function openRegModal() {
    regModal.classList.add('open');
}

function closeRegModal() {
    regModal.classList.remove('open');
}

regModal.addEventListener('click', e => {
    if (e.target === regModal) {
        closeRegModal();
    }
});

async function toggleAttended(id, attended) {
    const lbl = document.getElementById('att_label_' + id);

    try {
        const fd = new FormData();
        fd.append('action', 'toggle_attended');
        fd.append('id', id);
        fd.append('attended', attended ? '1' : '0');

        const res = await fetch('includes/process-events.php', {
            method: 'POST',
            body: fd
        });

        const txt = await res.text();

        if (txt.trim() === 'ok') {
            lbl.textContent = attended ? 'Yes' : 'No';
        } else {
            alert('Failed to update attendance.');
        }
    } catch (e) {
        alert('Network error.');
    }
}

const ventureSelect = document.getElementById('ventureSelect');
const founderSelect = document.getElementById('founderSelect');

function fillFromVenture() {
    const opt = ventureSelect.options[ventureSelect.selectedIndex];

    const preview = document.getElementById('venturePreview');

    if (!opt || !opt.value) {
        document.getElementById('regName').value = '';
        document.getElementById('regEmail').value = '';
        document.getElementById('regOrganisation').value = '';
        document.getElementById('regRole').value = '';
        document.getElementById('regContact').value = '';
        document.getElementById('regWebsite').value = '';
        document.getElementById('ventureNotes').value = '';
        preview.classList.remove('active');
        return;
    }

    const founder = founderSelect.value;

    let founderName = '';
    let founderEmail = '';
    let founderContact = '';

    if (founder === 'cofounder2') {
        founderName = opt.dataset.cofounder2Name || '';
        founderEmail = opt.dataset.cofounder2Email || '';
        founderContact = opt.dataset.cofounder2Contact || '';
    } else {
        founderName = opt.dataset.cofounder1Name || '';
        founderEmail = opt.dataset.cofounder1Email || '';
        founderContact = opt.dataset.cofounder1Contact || '';
    }

    if (founder === 'cofounder2' && founderName.trim() === '' && founderEmail.trim() === '') {
        founderName = opt.dataset.cofounder1Name || '';
        founderEmail = opt.dataset.cofounder1Email || '';
        founderContact = opt.dataset.cofounder1Contact || '';
        founderSelect.value = 'cofounder1';
    }

    const ventureName = opt.dataset.name || '';
    const sector = opt.dataset.sector || '';
    const stage = opt.dataset.stage || '';
    const country = opt.dataset.country || '';
    const location = opt.dataset.location || '';
    const website = opt.dataset.website || '';
    const tagline = opt.dataset.tagline || '';
    const impact = opt.dataset.impactMetric || '';
    const fundingRaised = opt.dataset.fundingRaised || '';
    const fundingSought = opt.dataset.fundingSought || '';
    const foundedYear = opt.dataset.foundedYear || '';

    document.getElementById('regName').value = founderName;
    document.getElementById('regEmail').value = founderEmail;
    document.getElementById('regOrganisation').value = ventureName;
    document.getElementById('regRole').value = sector ? 'Founder - ' + sector : 'Founder';
    document.getElementById('regContact').value = founderContact;
    document.getElementById('regWebsite').value = website;

    document.getElementById('ventureNotes').value =
        'Venture: ' + ventureName + "\n" +
        'Stage: ' + stage + "\n" +
        'Sector: ' + sector + "\n" +
        'Country: ' + country + "\n" +
        'Location: ' + location + "\n" +
        'Founded Year: ' + foundedYear + "\n" +
        'Impact Metric: ' + impact + "\n" +
        'Website: ' + website;

    document.getElementById('vpName').textContent = ventureName;
    document.getElementById('vpTagline').textContent = tagline || 'No tagline added.';
    document.getElementById('vpDetails').textContent =
        [sector, stage, country, location].filter(Boolean).join(' - ');

    document.getElementById('vpFunding').textContent =
        'Raised: ' + (fundingRaised || '0') + ' | Sought: ' + (fundingSought || '0');

    preview.classList.add('active');
}

ventureSelect?.addEventListener('change', fillFromVenture);
founderSelect?.addEventListener('change', fillFromVenture);
</script>

</body>
</html>

<?php
$regs_stmt->close();
?>
