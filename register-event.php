<?php
require_once __DIR__ . '/includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$event_id = (int)($_GET['event_id'] ?? 0);

if ($event_id <= 0) {
    die('Invalid event link.');
}

$stmt = $conn->prepare("
    SELECT 
        e.*,
        (
            SELECT COUNT(*) 
            FROM event_registrations r 
            WHERE r.event_id = e.id
        ) AS reg_count
    FROM events e
    WHERE e.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $event_id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) {
    die('Event not found.');
}

if ((int)$event['is_public'] !== 1) {
    die('This event is not open for public registration.');
}

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

$capacity = (int)($event['capacity'] ?? 0);
$reg_count = (int)($event['reg_count'] ?? 0);
$is_full = $capacity > 0 && $reg_count >= $capacity;

$deadline_passed = false;
if (!empty($event['registration_deadline'])) {
    $deadline_passed = strtotime($event['registration_deadline']) < time();
}

$site_name = function_exists('get_setting')
    ? get_setting($conn, 'site_name', 'EdTech Fellowship')
    : 'EdTech Fellowship';

$site_logo = function_exists('get_setting')
    ? get_setting($conn, 'site_logo', '')
    : '';

$site_favicon = function_exists('get_setting')
    ? get_setting($conn, 'site_favicon', '')
    : '';

$message = $_SESSION['register_message'] ?? '';
$message_type = $_SESSION['register_message_type'] ?? '';
$old = $_SESSION['register_old'] ?? [];

unset($_SESSION['register_message'], $_SESSION['register_message_type'], $_SESSION['register_old']);

$event_date = !empty($event['event_date'])
    ? date('l, F j, Y g:i A', strtotime($event['event_date']))
    : '-';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/includes/favicon.php'; ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title>Register - <?= h($event['title']) ?></title>

<?php if ($site_favicon !== ''): ?>
<link rel="icon" href="<?= h($site_favicon) ?>">
<?php endif; ?>

<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

<style>
*{box-sizing:border-box}
body{margin:0;font-family:'DM Sans',Arial,sans-serif;background:#f3f4f6;color:#111827}
.page{min-height:100vh;padding:30px 16px;display:flex;justify-content:center;align-items:flex-start}
.card{width:100%;max-width:980px;background:#fff;border-radius:20px;box-shadow:0 20px 50px rgba(15,23,42,.08);overflow:hidden}
.hero{padding:28px;background:linear-gradient(135deg,#fc7c10,#ffc107);color:#fff}
.logo-wrap{margin-bottom:18px}
.logo-wrap img{max-height:70px;max-width:160px;background:#fff;padding:8px;border-radius:12px}
.hero h1{margin:0 0 10px;font-size:30px;line-height:1.15}
.hero p{margin:0;opacity:.92}
.content{display:grid;grid-template-columns:.9fr 1.1fr}
.event-info{padding:28px;border-right:1px solid #e5e7eb}
.form-wrap{padding:28px}
.info-item{display:flex;gap:10px;margin-bottom:14px;color:#374151;font-size:14px}
.info-item i{color:#fc7c10;margin-top:3px}
.badge{display:inline-flex;padding:5px 10px;border-radius:999px;background:#eef2ff;color:#fc7c10;font-size:12px;font-weight:700;margin-bottom:16px}
.description{margin-top:18px;font-size:14px;line-height:1.7;color:#4b5563}
.alert{padding:12px 14px;border-radius:10px;margin-bottom:16px;font-size:14px;font-weight:600}
.alert-success{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}
.alert-danger{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
.alert-warning{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-group{margin-bottom:15px}
.form-group.full{grid-column:1/-1}
label{display:block;margin-bottom:6px;font-size:13px;font-weight:700;color:#374151}
input,select,textarea{width:100%;padding:12px 13px;border:1.5px solid #d1d5db;border-radius:10px;font-size:14px;outline:none;background:#fff}
input:focus,select:focus,textarea:focus{border-color:#fc7c10}
.btn{width:100%;border:0;border-radius:10px;padding:13px 16px;background:#fc7c10;color:#fff;font-size:15px;font-weight:800;cursor:pointer}
.btn:disabled{background:#9ca3af;cursor:not-allowed}
.small{font-size:12px;color:#6b7280;margin-top:12px;line-height:1.5}
.capacity,.venture-preview{margin-top:16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px;padding:12px;font-size:13px;color:#374151}
.venture-preview{display:none}
.venture-preview.active{display:block}
.venture-preview strong{display:block;margin-bottom:4px;color:#111827}
.readonly-note{background:#f8fafc;color:#64748b}
@media(max-width:800px){
    .content{grid-template-columns:1fr}
    .event-info{border-right:0;border-bottom:1px solid #e5e7eb}
    .hero h1{font-size:24px}
    .form-grid{grid-template-columns:1fr}
}
</style>
</head>

<body>

<div class="page">
<div class="card">

<div class="hero">
    <?php if ($site_logo !== ''): ?>
        <div class="logo-wrap">
            <img src="<?= h($site_logo) ?>" alt="<?= h($site_name) ?>">
        </div>
    <?php endif; ?>

    <span class="badge">
        <i class="fa fa-calendar-alt"></i>&nbsp; Event Registration
    </span>

    <h1><?= h($event['title']) ?></h1>
    <p><?= h($site_name) ?></p>
</div>

<div class="content">

<div class="event-info">
    <div class="info-item">
        <i class="fa fa-clock"></i>
        <div>
            <strong>Date & Time</strong><br>
            <?= h($event_date) ?>
        </div>
    </div>

    <?php if (!empty($event['end_date'])): ?>
    <div class="info-item">
        <i class="fa fa-hourglass-end"></i>
        <div>
            <strong>Ends</strong><br>
            <?= h(date('l, F j, Y g:i A', strtotime($event['end_date']))) ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($event['location'])): ?>
    <div class="info-item">
        <i class="fa fa-map-marker-alt"></i>
        <div>
            <strong>Location</strong><br>
            <?= h($event['location']) ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($event['meeting_link'])): ?>
    <div class="info-item">
        <i class="fa fa-video"></i>
        <div>
            <strong>Online Meeting</strong><br>
            Link will be shared with registered attendees.
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($event['registration_deadline'])): ?>
    <div class="info-item">
        <i class="fa fa-calendar-times"></i>
        <div>
            <strong>Registration Deadline</strong><br>
            <?= h(date('F j, Y g:i A', strtotime($event['registration_deadline']))) ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="capacity">
        <strong>Registered:</strong> <?= (int)$reg_count ?>
        <?php if ($capacity > 0): ?>
            / <?= (int)$capacity ?>
        <?php else: ?>
            / Unlimited
        <?php endif; ?>
    </div>

    <?php if (!empty($event['description'])): ?>
        <div class="description">
            <?= nl2br(h($event['description'])) ?>
        </div>
    <?php endif; ?>
</div>

<div class="form-wrap">
    <h2 style="margin-top:0">Register Now</h2>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= h($message_type) ?>">
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($is_full): ?>
        <div class="alert alert-danger">
            This event is full. Registration is closed.
        </div>
    <?php elseif ($deadline_passed): ?>
        <div class="alert alert-danger">
            Registration deadline has passed.
        </div>
    <?php else: ?>

    <form method="POST" action="includes/process-register.php">
        <input type="hidden" name="event_id" value="<?= (int)$event_id ?>">
        <input type="hidden" name="venture_id" id="ventureId" value="<?= h($old['venture_id'] ?? '') ?>">
        <input type="hidden" name="founder_type" id="founderType" value="<?= h($old['founder_type'] ?? 'cofounder1') ?>">

        <div class="form-grid">

            <div class="form-group full">
                <label>Select Venture</label>
                <select id="ventureSelect">
                    <option value="">Select Venture to Autofill</option>

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
                                <?= (string)($old['venture_id'] ?? '') === (string)$v['id'] ? 'selected' : '' ?>
                            >
                                <?= h($v['name']) ?>
                                <?= !empty($v['cofounder1_name']) ? ' - ' . h($v['cofounder1_name']) : '' ?>
                            </option>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </select>

                <div class="venture-preview" id="venturePreview">
                    <strong id="vpName"></strong>
                    <span id="vpTagline"></span><br>
                    <small id="vpDetails"></small><br>
                    <small id="vpFunding"></small>
                </div>
            </div>

            <div class="form-group full">
                <label>Founder to Register</label>
                <select id="founderSelect">
                    <option value="cofounder1" <?= ($old['founder_type'] ?? '') === 'cofounder1' ? 'selected' : '' ?>>Cofounder 1</option>
                    <option value="cofounder2" <?= ($old['founder_type'] ?? '') === 'cofounder2' ? 'selected' : '' ?>>Cofounder 2</option>
                </select>
            </div>

            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" name="name" id="regName" required value="<?= h($old['name'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>Email Address *</label>
                <input type="email" name="email" id="regEmail" required value="<?= h($old['email'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>Organisation / Venture</label>
                <input type="text" name="organisation" id="regOrganisation" value="<?= h($old['organisation'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>Role / Position</label>
                <input type="text" name="role" id="regRole" value="<?= h($old['role'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>Contact</label>
                <input type="text" name="contact" id="regContact" value="<?= h($old['contact'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>Website</label>
                <input type="text" name="website" id="regWebsite" class="readonly-note" value="<?= h($old['website'] ?? '') ?>" readonly>
            </div>

            <div class="form-group full">
                <label>Venture Notes</label>
                <textarea id="ventureNotes" class="readonly-note" rows="3" readonly><?= h($old['venture_notes'] ?? '') ?></textarea>
            </div>

        </div>

        <button type="submit" class="btn">
            <i class="fa fa-check-circle"></i> Submit Registration
        </button>

        <div class="small">
            After registration, your check-in code will be displayed. Please keep it for attendance verification.
        </div>
    </form>

    <?php endif; ?>
</div>

</div>
</div>
</div>

<script>
const ventureSelect = document.getElementById('ventureSelect');
const founderSelect = document.getElementById('founderSelect');

function fillFromVenture() {
    const opt = ventureSelect.options[ventureSelect.selectedIndex];
    const preview = document.getElementById('venturePreview');

    if (!opt || !opt.value) {
        document.getElementById('ventureId').value = '';
        document.getElementById('founderType').value = founderSelect.value;
        preview.classList.remove('active');
        return;
    }

    document.getElementById('ventureId').value = opt.value;
    document.getElementById('founderType').value = founderSelect.value;

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
        founderSelect.value = 'cofounder1';
        document.getElementById('founderType').value = 'cofounder1';
        founderName = opt.dataset.cofounder1Name || '';
        founderEmail = opt.dataset.cofounder1Email || '';
        founderContact = opt.dataset.cofounder1Contact || '';
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
    document.getElementById('vpDetails').textContent = [sector, stage, country, location].filter(Boolean).join(' - ');
    document.getElementById('vpFunding').textContent = 'Raised: ' + (fundingRaised || '0') + ' | Sought: ' + (fundingSought || '0');

    preview.classList.add('active');
}

ventureSelect?.addEventListener('change', fillFromVenture);
founderSelect?.addEventListener('change', fillFromVenture);

if (ventureSelect && ventureSelect.value) {
    fillFromVenture();
}
</script>

</body>
</html>
