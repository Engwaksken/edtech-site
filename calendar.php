<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['venture_id'])) {
    header('Location: login.php');
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection error.');
}

$conn->set_charset('utf8mb4');
$venture_id = (int)$_SESSION['venture_id'];

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('initials')) {
    function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        return strtoupper(substr($parts[0] ?? '', 0, 1) . substr($parts[1] ?? '', 0, 1));
    }
}

if (!function_exists('status_class')) {
    function status_class(string $status): string
    {
        return 'status-' . str_replace('_', '-', strtolower(trim($status)));
    }
}

if (!function_exists('safe_image_url')) {
    function safe_image_url(?string $path): string
    {
        $path = trim((string)$path);
        if ($path === '') return '';
        if (preg_match('~^https?://~i', $path)) return $path;
        $base = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') . '/' : '';
        return $base . ltrim($path, '/');
    }
}

$venture_stmt = $conn->prepare('SELECT * FROM ventures WHERE id = ? LIMIT 1');
$venture_stmt->bind_param('i', $venture_id);
$venture_stmt->execute();
$VENTURE = $venture_stmt->get_result()->fetch_assoc() ?: [];
$venture_stmt->close();

$cohort_id    = (int)($VENTURE['cohort_id'] ?? 0);
$venture_name = (string)($VENTURE['name'] ?? $_SESSION['venture_name'] ?? 'Your Venture');

$filter_month  = trim((string)($_GET['month'] ?? date('Y-m')));
$filter_status = trim((string)($_GET['status'] ?? ''));
$filter_group  = trim((string)($_GET['group'] ?? ''));
$filter_mentor = (int)($_GET['mentor_id'] ?? 0);
$view          = trim((string)($_GET['view'] ?? 'calendar'));

if (!in_array($view, ['calendar', 'list'], true)) {
    $view = 'calendar';
}

if (!preg_match('/^\d{4}-\d{2}$/', $filter_month)) {
    $filter_month = date('Y-m');
}

$month_start = date('Y-m-01', strtotime($filter_month . '-01'));
$month_end   = date('Y-m-t', strtotime($month_start));
$prev_month  = date('Y-m', strtotime('-1 month', strtotime($month_start)));
$next_month  = date('Y-m', strtotime('+1 month', strtotime($month_start)));

$status_map = [
    'requested' => 'Requested',
    'scheduled' => 'Scheduled',
    'confirmed' => 'Confirmed',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'no_show' => 'No Show',
];

$type_map = [
    'one_on_one' => '1-on-1',
    'group' => 'Group',
    'workshop' => 'Workshop',
    'review' => 'Review',
    'ad_hoc' => 'Ad Hoc',
];

$platform_map = [
    'google_meet' => 'Google Meet',
    'zoom' => 'Zoom',
    'teams' => 'MS Teams',
    'phone' => 'Phone',
    'in_person' => 'In Person',
    'other' => 'Other',
];

$platform_icons = [
    'google_meet' => 'fa-video',
    'zoom' => 'fa-video',
    'teams' => 'fa-video',
    'phone' => 'fa-phone',
    'in_person' => 'fa-map-marker-alt',
    'other' => 'fa-link',
];

if ($filter_status !== '' && !array_key_exists($filter_status, $status_map)) {
    $filter_status = '';
}


$allowed_groups = [
    'requested',
    'upcoming',
    'completed',
    'cancelled',
];

if (
    $filter_group !== ''
    && !in_array(
        $filter_group,
        $allowed_groups,
        true
    )
) {
    $filter_group = '';
}

/*
 * A statistics-card group takes precedence over a single status filter.
 * This allows Upcoming to represent both scheduled and confirmed sessions.
 */
if ($filter_group !== '') {
    $filter_status = '';
}

$mentors = [];
$mentor_sql = "
    SELECT 
        m.id,
        m.full_name,
        m.photo,
        m.organisation,
        m.email,
        m.expertise AS expertise_areas,
        CASE WHEN MAX(ma.id) IS NULL THEN 0 ELSE 1 END AS is_assigned
    FROM mentors m
    LEFT JOIN mentor_assignments ma 
        ON ma.mentor_id = m.id 
       AND (ma.venture_id = ? OR ma.cohort_id = ?)
    WHERE m.status = 'active'
    GROUP BY m.id, m.full_name, m.photo, m.organisation, m.email, m.expertise
    ORDER BY is_assigned DESC, m.full_name ASC
";
$mentor_stmt = $conn->prepare($mentor_sql);
$mentor_stmt->bind_param('ii', $venture_id, $cohort_id);
$mentor_stmt->execute();
$mentor_res = $mentor_stmt->get_result();
while ($m = $mentor_res->fetch_assoc()) {
    $mentors[(int)$m['id']] = $m;
}
$mentor_stmt->close();

if ($filter_mentor > 0 && !isset($mentors[$filter_mentor])) {
    $filter_mentor = 0;
}

$where  = ['ms.venture_id = ?'];
$params = [$venture_id];
$types  = 'i';

if ($filter_group !== '') {
    if ($filter_group === 'requested') {
        $where[] = "ms.status = 'requested'";
    } elseif ($filter_group === 'upcoming') {
        $where[] = "ms.status IN ('scheduled','confirmed')";
    } elseif ($filter_group === 'completed') {
        $where[] = "ms.status = 'completed'";
    } elseif ($filter_group === 'cancelled') {
        $where[] = "ms.status IN ('cancelled','no_show')";
    }
} elseif ($filter_status !== '') {
    $where[] = 'ms.status = ?';
    $params[] = $filter_status;
    $types .= 's';
}

if ($filter_mentor > 0) {
    $where[] = 'ms.mentor_id = ?';
    $params[] = $filter_mentor;
    $types .= 'i';
}

if ($view === 'calendar') {
    $where[] = 'DATE(ms.scheduled_at) BETWEEN ? AND ?';
    $params[] = $month_start;
    $params[] = $month_end;
    $types .= 'ss';
}

$order = $view === 'calendar' ? 'ms.scheduled_at ASC, ms.id ASC' : 'ms.scheduled_at DESC, ms.id DESC';

$sql = "
    SELECT 
        ms.*,
        m.full_name AS mentor_name,
        m.photo AS mentor_photo,
        m.organisation AS mentor_org
    FROM mentor_sessions ms
    INNER JOIN mentors m ON m.id = ms.mentor_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY {$order}
";

$sessions_stmt = $conn->prepare($sql);
$sessions_stmt->bind_param($types, ...$params);
$sessions_stmt->execute();
$sessions_res = $sessions_stmt->get_result();

$all_sessions = [];
$sessions_by_date = [];
while ($s = $sessions_res->fetch_assoc()) {
    $all_sessions[] = $s;
    if (!empty($s['scheduled_at'])) {
        $dk = date('Y-m-d', strtotime((string)$s['scheduled_at']));
        $sessions_by_date[$dk][] = $s;
    }
}
$sessions_stmt->close();

$booked_slots = [];
$booked_stmt = $conn->prepare("SELECT mentor_id, scheduled_at FROM mentor_sessions WHERE scheduled_at IS NOT NULL AND DATE(scheduled_at) BETWEEN ? AND ? AND status NOT IN ('cancelled', 'no_show')");
$booked_stmt->bind_param('ss', $month_start, $month_end);
$booked_stmt->execute();
$booked_res = $booked_stmt->get_result();
while ($b = $booked_res->fetch_assoc()) {
    $mentor_id = (int)$b['mentor_id'];
    $date = date('Y-m-d', strtotime((string)$b['scheduled_at']));
    $time = date('H:i', strtotime((string)$b['scheduled_at']));
    $booked_slots[$mentor_id . '|' . $date . '|' . $time] = true;
}
$booked_stmt->close();

$avail_by_date = [];
$has_availability_table = false;
$table_check = $conn->query("SHOW TABLES LIKE 'mentor_availability'");
if ($table_check instanceof mysqli_result) {
    $has_availability_table = $table_check->num_rows > 0;
}

if ($has_availability_table && $mentors) {
    $mentor_ids = array_keys($mentors);
    if ($filter_mentor > 0 && isset($mentors[$filter_mentor])) {
        $mentor_ids = [$filter_mentor];
    }

    if ($mentor_ids) {
        $placeholders = implode(',', array_fill(0, count($mentor_ids), '?'));
        $mentor_types = str_repeat('i', count($mentor_ids));
        $av_sql = "
            SELECT id, mentor_id, avail_date, slot_time, is_blocked, note
            FROM mentor_availability
            WHERE mentor_id IN ({$placeholders})
              AND avail_date BETWEEN ? AND ?
              AND is_blocked = 0
            ORDER BY avail_date ASC, slot_time ASC
        ";
        $av_stmt = $conn->prepare($av_sql);
        $av_types = $mentor_types . 'ss';
        $av_params = array_merge($mentor_ids, [$month_start, $month_end]);
        $av_stmt->bind_param($av_types, ...$av_params);
        $av_stmt->execute();
        $av_res = $av_stmt->get_result();

        while ($a = $av_res->fetch_assoc()) {
            $mentor_id = (int)$a['mentor_id'];
            $date = (string)$a['avail_date'];
            $time = substr((string)$a['slot_time'], 0, 5);
            $key = $mentor_id . '|' . $date . '|' . $time;
            if (isset($booked_slots[$key])) {
                continue;
            }
            $avail_by_date[$date][] = [
                'id' => (int)$a['id'],
                'mentor_id' => $mentor_id,
                'mentor_name' => (string)($mentors[$mentor_id]['full_name'] ?? 'Mentor'),
                'mentor_org' => (string)($mentors[$mentor_id]['organisation'] ?? ''),
                'slot_time' => $time,
                'duration' => 60,
                'note' => (string)($a['note'] ?? ''),
            ];
        }
        $av_stmt->close();
    }
}

$stats_stmt = $conn->prepare("
    SELECT
        COALESCE(SUM(status = 'requested'), 0) AS requested,
        COALESCE(SUM(status IN ('scheduled','confirmed')), 0) AS upcoming,
        COALESCE(SUM(status = 'completed'), 0) AS completed,
        COALESCE(SUM(status IN ('cancelled','no_show')), 0) AS cancelled
    FROM mentor_sessions
    WHERE venture_id = ?
");
$stats_stmt->bind_param('i', $venture_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc() ?: [];
$stats_stmt->close();

$cal_first_dow = (int)date('w', strtotime($month_start));
$cal_days_in_month = (int)date('t', strtotime($month_start));

$flash = null;
if (!empty($_SESSION['vp_flash_msg'])) {
    $flash = [
        'msg' => (string)$_SESSION['vp_flash_msg'],
        'type' => (string)($_SESSION['vp_flash_type'] ?? 'success'),
    ];
    unset($_SESSION['vp_flash_msg'], $_SESSION['vp_flash_type']);
}

function calendar_query(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    foreach ($query as $k => $v) {
        if ($v === '' || $v === null) unset($query[$k]);
    }
    return http_build_query($query);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/includes/favicon.php'; ?>
    <meta charset="UTF-8">
    <title>Mentor Calendar - <?= h($venture_name) ?> Portal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="assets/css/venture.css">
    <style>
        body{background:#f8fafc;color:#0f172a;font-family:'DM Sans',Arial,sans-serif;}
        .mentor-calendar-page{min-height:100vh;}
        .mentor-calendar-content{padding:24px;max-width:1500px;margin:0 auto;}
        .page-header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:18px;}
        .page-title{font-size:28px;font-weight:800;margin:0;color:#0f172a;}
        .page-sub{margin:4px 0 0;color:#64748b;font-size:14px;}
        .page-actions{display:flex;gap:10px;flex-wrap:wrap;}
        .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:40px;padding:9px 14px;border-radius:10px;border:1px solid #dbe3ef;background:#fff;color:#0f172a;font-weight:700;font-size:13px;text-decoration:none;cursor:pointer;transition:.18s ease;}
        .btn:hover{transform:translateY(-1px);box-shadow:0 8px 18px rgba(15,23,42,.08);}
        .btn-primary{background:#ff5722;border-color:#ff5722;color:#fff;}
        .btn-danger{background:#fee2e2;border-color:#fecaca;color:#b91c1c;}
        .btn-sm{min-height:34px;padding:7px 11px;font-size:12px;}
        .btn-icon{width:36px;padding:0;}
        .btn-clear{color:#b91c1c;}
        .flash{display:flex;gap:10px;align-items:center;margin-bottom:16px;padding:12px 14px;border-radius:12px;border:1px solid #dbe3ef;background:#fff;font-weight:700;}
        .flash-success{background:#ecfdf5;border-color:#bbf7d0;color:#047857;}.flash-error{background:#fef2f2;border-color:#fecaca;color:#b91c1c;}.flash-info{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;}
        .stat-ribbon{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px;}
        .stat-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:18px;box-shadow:0 1px 3px rgba(15,23,42,.06);}
        .stat-link{display:block;color:inherit;text-decoration:none!important;border-radius:16px;min-width:0;}
        .stat-link .stat-card{height:100%;cursor:pointer;transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease,background .18s ease;}
        .stat-link:hover .stat-card,.stat-link:focus-visible .stat-card{transform:translateY(-2px);box-shadow:0 10px 26px rgba(15,23,42,.10);border-color:#fdba74;background:#fffaf5;}
        .stat-link:focus-visible{outline:3px solid rgba(255,87,34,.18);outline-offset:3px;}
        .stat-link.active .stat-card{border-color:#ff5722;box-shadow:0 0 0 2px rgba(255,87,34,.10);}
        .stat-num{font-size:28px;font-weight:900;line-height:1;}.stat-lbl{margin-top:7px;color:#64748b;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;}
        .stat-requested{color:#d97706}.stat-upcoming{color:#2563eb}.stat-completed{color:#059669}.stat-cancelled{color:#dc2626}
        .filter-bar{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:12px;margin-bottom:16px;box-shadow:0 1px 3px rgba(15,23,42,.06);}
        .filter-form{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
        .filter-form select,.filter-form input[type=month]{min-height:40px;border:1px solid #cbd5e1;border-radius:10px;padding:8px 11px;background:#fff;color:#0f172a;font-weight:650;}
        .view-toggle{display:flex;gap:6px;margin-left:auto;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:12px;padding:4px;}
        .view-btn{display:inline-flex;align-items:center;gap:7px;padding:8px 12px;border-radius:9px;text-decoration:none;color:#475569;font-size:13px;font-weight:800;}
        .view-btn.active{background:#0f172a;color:#fff;}
        .legend{display:flex;gap:12px;flex-wrap:wrap;margin:0 0 12px;color:#64748b;font-size:12px;font-weight:700;}
        .leg{display:flex;align-items:center;gap:6px;}.leg-sq{width:13px;height:13px;border-radius:4px}.leg-open{background:#fff3d6;border:1px solid #f3c56b}.leg-confirmed{background:#eeedfe}.leg-requested{background:#faeeda}.leg-completed{background:#eaf3de}.leg-cancelled{background:#f1efe8}
        .cal-layout{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:18px;align-items:start;}
        .cal-shell,.side-card,.list-shell{background:#fff;border:1px solid #e2e8f0;border-radius:18px;box-shadow:0 1px 3px rgba(15,23,42,.06);overflow:hidden;}
        .cal-month-nav{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px;border-bottom:1px solid #e2e8f0;}
        .cal-month-title{font-size:18px;font-weight:900;}.cal-month-btns{display:flex;gap:8px;}
        .cal-header,.cal-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));}
        .cal-hday{padding:10px;background:#f8fafc;border-bottom:1px solid #e2e8f0;text-align:center;color:#64748b;font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.05em;}
        .cal-cell{min-height:132px;padding:10px;border-right:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;background:#fff;position:relative;overflow:hidden;cursor:pointer;}
        .cal-cell:nth-child(7n){border-right:0}.cal-cell.other-month{background:#f8fafc;cursor:default}.cal-cell.weekend{background:#fbfdff}.cal-cell.today{box-shadow:inset 0 0 0 2px #ff5722}.cal-cell.sel-day{background:#fff7ed;box-shadow:inset 0 0 0 2px #fb923c}.cal-cell.has-avail{background:#fffdf7;border-color:#f3c56b;}
        .day-num-wrap{width:28px;height:28px;display:grid;place-items:center;border-radius:999px;font-weight:900;font-size:13px;color:#0f172a;}.today .day-num-wrap{background:#ff5722;color:#fff;}
        .cal-avail-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 7px;margin:5px 0 2px;border-radius:999px;background:#fff3d6;color:#8a5600;font-size:11px;font-weight:800;max-width:100%;}.cal-avail-dot{font-size:8px;color:#f0a000;}
        .cal-event{display:block;padding:5px 7px;margin-top:5px;border-radius:8px;font-size:11px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer;}
        .status-requested{background:#faeeda;color:#633806}.status-scheduled{background:#e6f1fb;color:#185fa5}.status-confirmed{background:#eeedfe;color:#3c3489}.status-completed{background:#eaf3de;color:#27500a}.status-cancelled{background:#f1efe8;color:#5f5e5a}.status-no-show{background:#faece7;color:#993c1d}
        .side-panel{display:grid;gap:14px;}.side-card{padding:16px;}.side-card-title{font-weight:900;font-size:16px;margin-bottom:12px;color:#0f172a;}.muted-sm{color:#64748b;font-size:13px;}.muted-xs,.text-muted{color:#64748b;font-size:12px;}.text-center{text-align:center}.py-10{padding:10px 0}.py-8{padding:8px 0}
        .slot-item{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:11px;border:1px solid #e2e8f0;border-radius:14px;margin-bottom:9px;background:#fff;}.slot-time{font-weight:900}.slot-mentor{font-size:13px;color:#475569;font-weight:700}.slot-org{font-size:11px;color:#94a3b8}.section-divider{margin:13px 0 9px;font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:#64748b;font-weight:900;}
        .session-item{display:flex;gap:11px;padding:11px;border:1px solid #e2e8f0;border-radius:14px;margin-bottom:10px;background:#fff;}.session-item.is-clickable{cursor:pointer}.session-item:hover{border-color:#ffb199;background:#fff7ed}.si-avatar,.mc-avatar{width:40px;height:40px;border-radius:12px;object-fit:cover;display:grid;place-items:center;background:#0f172a;color:#fff;font-size:12px;font-weight:900;flex:0 0 auto}.si-body,.mc-body{min-width:0;flex:1}.si-title,.mc-name{font-weight:900;font-size:13px;color:#0f172a}.si-meta,.mc-org{font-size:12px;color:#64748b;margin-top:2px}.si-badge{display:inline-flex;margin-top:6px;border-radius:999px;padding:4px 8px;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.04em;}
        .mentor-chips{display:grid;gap:9px}.mentor-chip{display:flex;align-items:center;gap:10px;padding:10px;border:1px solid #e2e8f0;border-radius:14px;cursor:pointer;background:#fff}.mentor-chip:hover{background:#fff7ed;border-color:#ffb199}.mc-btn{flex:0 0 auto;}
        .table-wrap{width:100%;overflow-x:auto}.list-shell{padding:0}table{width:100%;border-collapse:collapse;min-width:850px}th,td{padding:13px 14px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:middle}th{background:#f8fafc;font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.06em}td{font-size:13px}.empty-state{text-align:center;padding:40px 20px;color:#64748b}.empty-state i{font-size:34px;margin-bottom:10px;color:#cbd5e1}.empty-state h3{color:#0f172a;margin:0 0 4px;}
        .modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.62);backdrop-filter:blur(4px);z-index:99999;display:none;align-items:center;justify-content:center;padding:20px;}.modal-overlay.open{display:flex}.modal{width:100%;max-width:860px;max-height:90vh;overflow:auto;background:#fff;border-radius:20px;box-shadow:0 30px 80px rgba(0,0,0,.22);}.modal-header,.modal-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 18px;border-bottom:1px solid #e2e8f0}.modal-footer{border-top:1px solid #e2e8f0;border-bottom:0;justify-content:flex-end}.modal-title{font-size:18px;font-weight:900;margin:0}.modal-close{width:36px;height:36px;border:1px solid #e2e8f0;border-radius:999px;background:#fff;font-size:22px;line-height:1;cursor:pointer}.modal-body{padding:18px}.form-grid-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.form-group.full{grid-column:1/-1}.form-group label{display:block;margin-bottom:6px;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.06em;color:#475569}.form-control{width:100%;min-height:42px;border:1px solid #cbd5e1;border-radius:10px;padding:9px 11px;background:#fff;color:#0f172a}.form-control[readonly],.form-control:disabled{background:#f8fafc;color:#475569}.form-hint{font-size:12px;color:#64748b;margin-top:5px}.req{color:#dc2626}.inline-form{display:inline}.detail-row{display:grid;grid-template-columns:150px 1fr;gap:12px;padding:12px 0;border-bottom:1px solid #e2e8f0}.detail-row:last-child{border-bottom:0}.detail-lbl{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:900}.detail-val{font-size:14px;color:#0f172a}.detail-sm{line-height:1.6}.detail-note{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px}.meeting-link-badge{display:inline-flex;align-items:center;gap:7px;background:#e6f1fb;color:#185fa5;padding:8px 10px;border-radius:999px;text-decoration:none;font-weight:900;font-size:12px;}.meeting-link-badge:hover{background:#dbeafe;color:#1d4ed8}.join-mini{display:inline-flex;align-items:center;gap:5px;margin-top:6px;padding:5px 8px;border-radius:999px;background:#ecfdf5;color:#047857;text-decoration:none;font-size:11px;font-weight:900}.join-mini:hover{background:#d1fae5}.cal-event .fa-video{margin-left:4px;font-size:10px}.platform-cell{display:flex;flex-direction:column;align-items:flex-start;gap:5px}.status-help{display:block;margin-top:4px;color:#64748b;font-size:11px;font-weight:700}
        body.modal-open{overflow:hidden;}
        @media(max-width:1100px){.cal-layout{grid-template-columns:1fr}.side-panel{grid-template-columns:repeat(2,minmax(0,1fr));}.side-panel .side-card:first-child{grid-column:1/-1}}
        @media(max-width:760px){.mentor-calendar-content{padding:14px}.page-header{display:block}.page-actions{margin-top:12px}.stat-ribbon{grid-template-columns:repeat(2,minmax(0,1fr))}.filter-form{display:grid;grid-template-columns:1fr}.view-toggle{margin-left:0;width:100%;}.view-btn{flex:1;justify-content:center}.cal-header{display:none}.cal-grid{display:block}.cal-cell{min-height:auto;border-right:0}.cal-cell.other-month{display:none}.side-panel{grid-template-columns:1fr}.form-grid-2{grid-template-columns:1fr}.form-group.full{grid-column:auto}.detail-row{grid-template-columns:1fr;gap:5px}.modal{max-height:92vh}.cal-month-nav{flex-wrap:wrap}.cal-month-title{order:-1;width:100%;text-align:center}}
    </style>
</head>
<body>
<?php include __DIR__ . '/layout.php'; ?>

<div class="portal-main mentor-calendar-page" id="portalMain">
    <div class="portal-content mentor-calendar-content">
        <?php if ($flash): ?>
            <div class="flash flash-<?= h($flash['type']) ?>">
                <i class="fa <?= $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-info-circle' ?>"></i>
                <?= h($flash['msg']) ?>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <h1 class="page-title">Mentor Calendar</h1>
                <p class="page-sub">Browse mentor availability, request sessions, and manage bookings.</p>
            </div>
            <div class="page-actions">
                <?php if ($mentors): ?>
                    <button class="btn btn-primary" type="button" onclick="openRequestModal()"><i class="fa fa-calendar-plus"></i> Request session</button>
                <?php endif; ?>
                <a href="sessions.php" class="btn"><i class="fa fa-list"></i> All sessions</a>
            </div>
        </div>

        <div class="stat-ribbon">
            <a
                class="stat-link <?= $filter_group === 'requested' ? 'active' : '' ?>"
                href="?<?= h(calendar_query([
                    'view' => 'list',
                    'group' => 'requested',
                    'status' => '',
                    'mentor_id' => $filter_mentor ?: '',
                ])) ?>"
                aria-label="View requested sessions"
            >
                <div class="stat-card">
                    <div class="stat-num stat-requested"><?= (int)($stats['requested'] ?? 0) ?></div>
                    <div class="stat-lbl">Requested</div>
                </div>
            </a>

            <a
                class="stat-link <?= $filter_group === 'upcoming' ? 'active' : '' ?>"
                href="?<?= h(calendar_query([
                    'view' => 'list',
                    'group' => 'upcoming',
                    'status' => '',
                    'mentor_id' => $filter_mentor ?: '',
                ])) ?>"
                aria-label="View upcoming sessions"
            >
                <div class="stat-card">
                    <div class="stat-num stat-upcoming"><?= (int)($stats['upcoming'] ?? 0) ?></div>
                    <div class="stat-lbl">Upcoming</div>
                </div>
            </a>

            <a
                class="stat-link <?= $filter_group === 'completed' ? 'active' : '' ?>"
                href="?<?= h(calendar_query([
                    'view' => 'list',
                    'group' => 'completed',
                    'status' => '',
                    'mentor_id' => $filter_mentor ?: '',
                ])) ?>"
                aria-label="View completed sessions"
            >
                <div class="stat-card">
                    <div class="stat-num stat-completed"><?= (int)($stats['completed'] ?? 0) ?></div>
                    <div class="stat-lbl">Completed</div>
                </div>
            </a>

            <a
                class="stat-link <?= $filter_group === 'cancelled' ? 'active' : '' ?>"
                href="?<?= h(calendar_query([
                    'view' => 'list',
                    'group' => 'cancelled',
                    'status' => '',
                    'mentor_id' => $filter_mentor ?: '',
                ])) ?>"
                aria-label="View cancelled sessions"
            >
                <div class="stat-card">
                    <div class="stat-num stat-cancelled"><?= (int)($stats['cancelled'] ?? 0) ?></div>
                    <div class="stat-lbl">Cancelled</div>
                </div>
            </a>
        </div>

        <div class="filter-bar">
            <form method="GET" class="filter-form">
                <input type="hidden" name="view" value="<?= h($view) ?>">
                <input type="hidden" name="group" id="calendar-group-filter" value="<?= h($filter_group) ?>">
                <select name="status" onchange="document.getElementById('calendar-group-filter').value='';this.form.submit()">
                    <option value="">All statuses</option>
                    <?php foreach ($status_map as $key => $label): ?>
                        <option value="<?= h($key) ?>" <?= $filter_status === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="mentor_id" onchange="this.form.submit()">
                    <option value="">All mentors</option>
                    <?php foreach ($mentors as $m): ?>
                        <option value="<?= (int)$m['id'] ?>" <?= $filter_mentor === (int)$m['id'] ? 'selected' : '' ?>><?= h($m['full_name']) ?><?= (int)$m['is_assigned'] === 1 ? ' - Assigned' : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($view === 'calendar'): ?>
                    <input type="month" name="month" value="<?= h($filter_month) ?>" onchange="this.form.submit()">
                <?php endif; ?>
                <?php if ($filter_status !== '' || $filter_group !== '' || $filter_mentor > 0): ?>
                    <a href="?<?= h(calendar_query(['status' => '', 'group' => '', 'mentor_id' => '', 'view' => $view, 'month' => $filter_month])) ?>" class="btn btn-sm btn-clear"><i class="fa fa-times"></i> Clear</a>
                <?php endif; ?>
                <div class="view-toggle">
                    <a href="?<?= h(calendar_query(['view' => 'calendar', 'month' => $filter_month])) ?>" class="view-btn <?= $view === 'calendar' ? 'active' : '' ?>"><i class="fa fa-calendar"></i> Calendar</a>
                    <a href="?<?= h(calendar_query(['view' => 'list'])) ?>" class="view-btn <?= $view === 'list' ? 'active' : '' ?>"><i class="fa fa-list"></i> List</a>
                </div>
            </form>
        </div>

        <?php if ($view === 'calendar'): ?>
            <div class="legend">
                <div class="leg"><div class="leg-sq leg-open"></div>Open mentor slots</div>
                <div class="leg"><div class="leg-sq leg-confirmed"></div>Confirmed</div>
                <div class="leg"><div class="leg-sq leg-requested"></div>Requested</div>
                <div class="leg"><div class="leg-sq leg-completed"></div>Completed</div>
                <div class="leg"><div class="leg-sq leg-cancelled"></div>Cancelled</div>
                <div class="leg"><i class="fa fa-video" style="color:#047857"></i>Meeting link ready</div>
            </div>

            <div class="cal-layout">
                <div class="cal-shell">
                    <div class="cal-month-nav">
                        <a href="?<?= h(calendar_query(['month' => $prev_month, 'view' => 'calendar'])) ?>" class="btn btn-sm"><i class="fa fa-chevron-left"></i></a>
                        <span class="cal-month-title"><?= h(date('F Y', strtotime($month_start))) ?></span>
                        <div class="cal-month-btns">
                            <a href="?<?= h(calendar_query(['month' => date('Y-m'), 'view' => 'calendar'])) ?>" class="btn btn-sm">Today</a>
                            <a href="?<?= h(calendar_query(['month' => $next_month, 'view' => 'calendar'])) ?>" class="btn btn-sm"><i class="fa fa-chevron-right"></i></a>
                        </div>
                    </div>
                    <div class="cal-header">
                        <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?><div class="cal-hday"><?= h($d) ?></div><?php endforeach; ?>
                    </div>
                    <div class="cal-grid" id="cal-main">
                        <?php for ($b = 0; $b < $cal_first_dow; $b++): ?><div class="cal-cell other-month"></div><?php endfor; ?>
                        <?php for ($day = 1; $day <= $cal_days_in_month; $day++): ?>
                            <?php
                            $dk = date('Y-m', strtotime($month_start)) . '-' . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
                            $is_today = $dk === date('Y-m-d');
                            $dow = (int)date('w', strtotime($dk));
                            $is_weekend = in_array($dow, [0, 6], true);
                            $day_sessions = $sessions_by_date[$dk] ?? [];
                            $day_avail = $avail_by_date[$dk] ?? [];
                            $classes = 'cal-cell' . ($is_today ? ' today' : '') . ($is_weekend ? ' weekend' : '') . ($day_avail ? ' has-avail' : '');
                            ?>
                            <div class="<?= h($classes) ?>" onclick="selectDay('<?= h($dk) ?>', '<?= h(date('D j M', strtotime($dk))) ?>', event)">
                                <div class="day-num-wrap"><?= (int)$day ?></div>
                                <?php if ($day_avail): ?>
                                    <div class="cal-avail-badge"><i class="fa fa-circle cal-avail-dot"></i><?= count($day_avail) ?> open</div>
                                <?php endif; ?>
                                <?php foreach ($day_sessions as $s): ?>
                                    <?php $status = (string)($s['status'] ?? ''); ?>
                                    <span class="cal-event <?= h(status_class($status)) ?>" title="<?= h(($s['title'] ?? '') . ' - ' . ($s['mentor_name'] ?? '')) ?>" onclick="event.stopPropagation(); openDetailModal(<?= (int)$s['id'] ?>)">
                                        <?= h(date('g:ia', strtotime((string)$s['scheduled_at']))) ?> <?= h(mb_strimwidth((string)$s['mentor_name'], 0, 16, '...')) ?>
                                        <?php if (!empty($s['meeting_link']) && in_array($status, ['scheduled','confirmed'], true)): ?><i class="fa fa-video" title="Meeting link available"></i><?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endfor; ?>
                        <?php $trailing = (7 - (($cal_first_dow + $cal_days_in_month) % 7)) % 7; for ($t = 0; $t < $trailing; $t++): ?><div class="cal-cell other-month"></div><?php endfor; ?>
                    </div>
                </div>

                <div class="side-panel">
                    <div class="side-card" id="day-panel">
                        <div class="side-card-title" id="day-panel-title">Select a day</div>
                        <div id="day-panel-body"><p class="muted-sm">Click any day to see open mentor slots and your sessions.</p></div>
                    </div>
                    <div class="side-card">
                        <div class="side-card-title">Upcoming sessions</div>
                        <?php
                        $upcoming = array_filter($all_sessions, fn($s) => in_array((string)$s['status'], ['requested','scheduled','confirmed'], true));
                        $upcoming = array_slice(array_values($upcoming), 0, 5);
                        ?>
                        <?php if (!$upcoming): ?>
                            <p class="muted-sm text-center py-10">No upcoming sessions this month.</p>
                        <?php else: ?>
                            <?php foreach ($upcoming as $s): ?>
                                <?php $dt = !empty($s['scheduled_at']) ? strtotime((string)$s['scheduled_at']) : null; $status = (string)$s['status']; ?>
                                <div class="session-item is-clickable" onclick="openDetailModal(<?= (int)$s['id'] ?>)">
                                    <?php $avatar = safe_image_url($s['mentor_photo'] ?? ''); ?>
                                    <?php if ($avatar): ?><img src="<?= h($avatar) ?>" class="si-avatar" alt=""><?php else: ?><div class="si-avatar"><?= h(initials((string)$s['mentor_name'])) ?></div><?php endif; ?>
                                    <div class="si-body">
                                        <div class="si-title"><?= h($s['title']) ?></div>
                                        <div class="si-meta"><?= h($s['mentor_name']) ?></div>
                                        <div class="si-meta"><?= $dt ? h(date('M j, g:i A', $dt)) : 'TBD' ?></div>
                                        <span class="si-badge <?= h(status_class($status)) ?>"><?= h($status_map[$status] ?? ucfirst($status)) ?></span>
                                        <?php if (!empty($s['meeting_link']) && in_array($status, ['scheduled','confirmed'], true)): ?>
                                            <a class="join-mini" href="<?= h($s['meeting_link']) ?>" target="_blank" rel="noopener" onclick="event.stopPropagation();">
                                                <i class="fa fa-video"></i> Join meeting
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($mentors): ?>
                        <div class="side-card">
                            <div class="side-card-title">Mentors</div>
                            <div class="mentor-chips">
                                <?php foreach (array_slice($mentors, 0, 6) as $m): ?>
                                    <div class="mentor-chip" onclick="openRequestModalForMentor(<?= (int)$m['id'] ?>)">
                                        <?php $avatar = safe_image_url($m['photo'] ?? ''); ?>
                                        <?php if ($avatar): ?><img src="<?= h($avatar) ?>" class="mc-avatar" alt=""><?php else: ?><div class="mc-avatar"><?= h(initials((string)$m['full_name'])) ?></div><?php endif; ?>
                                        <div class="mc-body"><div class="mc-name"><?= h($m['full_name']) ?></div><div class="mc-org"><?= h($m['organisation'] ?? '') ?><?= (int)$m['is_assigned'] === 1 ? ' . Assigned' : '' ?></div></div>
                                        <button class="btn btn-sm btn-primary mc-btn" type="button">Request</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="list-shell">
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Session</th><th>Mentor</th><th>Date &amp; Time</th><th>Platform</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php if (!$all_sessions): ?>
                            <tr><td colspan="6"><div class="empty-state"><i class="fa fa-calendar-alt"></i><h3>No sessions yet</h3><p>Request your first mentor session to get started.</p></div></td></tr>
                        <?php else: ?>
                            <?php foreach ($all_sessions as $s): ?>
                                <?php $status = (string)$s['status']; $dt = !empty($s['scheduled_at']) ? strtotime((string)$s['scheduled_at']) : null; ?>
                                <tr>
                                    <td><strong><?= h($s['title']) ?></strong><br><span class="muted-xs"><?= (int)($s['duration_minutes'] ?? 60) ?> min</span></td>
                                    <td><?= h($s['mentor_name']) ?></td>
                                    <td><?= $dt ? h(date('M j, Y', $dt)) : 'TBD' ?><br><span class="text-muted"><?= $dt ? h(date('g:i A', $dt)) : '' ?></span></td>
                                    <td>
                                        <div class="platform-cell">
                                            <span><i class="fa <?= h($platform_icons[$s['meeting_platform']] ?? 'fa-link') ?>"></i> <?= h($platform_map[$s['meeting_platform']] ?? $s['meeting_platform']) ?></span>
                                            <?php if (!empty($s['meeting_link']) && in_array($status, ['scheduled','confirmed'], true)): ?>
                                                <a class="join-mini" href="<?= h($s['meeting_link']) ?>" target="_blank" rel="noopener">
                                                    <i class="fa fa-video"></i> Join meeting
                                                </a>
                                            <?php elseif (in_array($status, ['scheduled','confirmed'], true)): ?>
                                                <span class="status-help">Meeting link not added yet</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><span class="si-badge <?= h(status_class($status)) ?>"><?= h($status_map[$status] ?? ucfirst($status)) ?></span></td>
                                    <td><button class="btn btn-sm btn-icon" type="button" onclick="openDetailModal(<?= (int)$s['id'] ?>)"><i class="fa fa-eye"></i></button></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="requestModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="requestModalTitle">
        <div class="modal-header"><h2 class="modal-title" id="requestModalTitle">Request a mentor session</h2><button class="modal-close" onclick="closeModal('requestModal')" type="button">&times;</button></div>
        <form method="POST" action="includes/portal-process.php">
            <input type="hidden" name="action" value="request_session">
            <input type="hidden" name="venture_id" value="<?= (int)$venture_id ?>">
            <div class="modal-body">
                <div class="form-grid-2">
                    <div class="form-group full"><label>What do you need help with? <span class="req">*</span></label><input type="text" name="title" class="form-control" required id="req-title" placeholder="e.g. Fundraising deck review, GTM strategy"></div>
                    <div class="form-group"><label>Mentor <span class="req">*</span></label><select name="mentor_id" class="form-control" required id="req-mentor"><option value="">Select mentor</option><?php foreach ($mentors as $m): ?><option value="<?= (int)$m['id'] ?>"><?= h($m['full_name']) ?><?= (int)$m['is_assigned'] === 1 ? ' - Assigned' : '' ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Session type</label><select name="session_type" class="form-control"><option value="one_on_one">1-on-1</option><option value="review">Review</option><option value="workshop">Workshop</option><option value="group">Group</option><option value="ad_hoc">Ad Hoc</option></select></div>
                    <div class="form-group"><label>Preferred Date &amp; Time</label><input type="datetime-local" id="req-dt" class="form-control"><input type="hidden" name="preferred_at" id="req-dt-hidden"><div class="form-hint">Selected open slots will be filled automatically.</div></div>
                    <div class="form-group"><label>Duration</label><select id="req-duration" class="form-control"><option value="30">30 min</option><option value="45">45 min</option><option value="60" selected>60 min</option><option value="90">90 min</option><option value="120">2 hours</option></select><input type="hidden" name="duration_minutes" id="req-duration-hidden" value="60"></div>
                    <div class="form-group"><label>Platform preference</label><select name="meeting_platform" class="form-control"><option value="google_meet">Google Meet</option><option value="zoom">Zoom</option><option value="teams">MS Teams</option><option value="phone">Phone</option><option value="in_person">In Person</option><option value="other">Other</option></select></div>
                    <div class="form-group full"><label>Additional context</label><textarea name="description" class="form-control" rows="3" placeholder="Describe what you would like to cover."></textarea></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn" onclick="closeModal('requestModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fa fa-paper-plane"></i> Send request</button></div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="detailModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="dm-title">
        <div class="modal-header"><h2 class="modal-title" id="dm-title">Session</h2><button class="modal-close" onclick="closeModal('detailModal')" type="button">&times;</button></div>
        <div class="modal-body" id="dm-body"></div>
        <div class="modal-footer" id="dm-footer"></div>
    </div>
</div>

<script>
const SESSION_DATA = <?= json_encode(array_values(array_map(function ($s) use ($platform_map, $type_map, $status_map) {
    $status = (string)($s['status'] ?? '');
    return [
        'id' => (int)$s['id'],
        'title' => $s['title'] ?? '',
        'mentor_name' => $s['mentor_name'] ?? '',
        'mentor_org' => $s['mentor_org'] ?? '',
        'status' => $status,
        'status_label' => $status_map[$status] ?? ucfirst($status),
        'status_class' => status_class($status),
        'scheduled_at' => $s['scheduled_at'] ?? '',
        'duration' => (int)($s['duration_minutes'] ?? 60),
        'platform' => $platform_map[$s['meeting_platform']] ?? ($s['meeting_platform'] ?? ''),
        'meeting_link' => $s['meeting_link'] ?? '',
        'session_type' => $type_map[$s['session_type']] ?? ($s['session_type'] ?? ''),
        'description' => $s['description'] ?? '',
        'notes_shared' => $s['notes_shared'] ?? '',
        'action_items' => $s['action_items'] ?? '',
        'venture_rating' => (int)($s['venture_rating'] ?? 0),
    ];
}, $all_sessions)), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const AVAIL_BY_DATE = <?= json_encode($avail_by_date, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

function openModal(id){const el=document.getElementById(id);if(!el)return;el.classList.add('open');el.setAttribute('aria-hidden','false');document.body.classList.add('modal-open');}
function closeModal(id){const el=document.getElementById(id);if(!el)return;el.classList.remove('open');el.setAttribute('aria-hidden','true');if(!document.querySelector('.modal-overlay.open'))document.body.classList.remove('modal-open');}
document.querySelectorAll('.modal-overlay').forEach(modal=>{modal.addEventListener('click',e=>{if(e.target===modal)closeModal(modal.id);});});
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-overlay.open').forEach(m=>closeModal(m.id));});

function setRequestDateTime(v){const visible=document.getElementById('req-dt');const hidden=document.getElementById('req-dt-hidden');if(visible)visible.value=v||'';if(hidden)hidden.value=v||'';}
function setRequestDuration(v){const duration=v?String(v):'60';const visible=document.getElementById('req-duration');const hidden=document.getElementById('req-duration-hidden');if(visible)visible.value=duration;if(hidden)hidden.value=duration;}
document.getElementById('req-dt')?.addEventListener('change',e=>setRequestDateTime(e.target.value));
document.getElementById('req-duration')?.addEventListener('change',e=>setRequestDuration(e.target.value));

function openRequestModal(date='',slot='',duration='60'){
    const form=document.querySelector('#requestModal form');if(form)form.reset();setRequestDateTime('');setRequestDuration(duration);
    if(date){const cleanSlot=slot?String(slot).substring(0,5):'09:00';setRequestDateTime(date+'T'+cleanSlot);}openModal('requestModal');
}
function openRequestModalForMentor(mentorId,date='',slot='',duration='60'){openRequestModal(date,slot,duration);const mentorInput=document.getElementById('req-mentor');if(mentorInput)mentorInput.value=mentorId;}

function selectDay(dateKey,label,e){
    document.querySelectorAll('#cal-main .cal-cell').forEach(cell=>cell.classList.remove('sel-day'));
    if(e&&e.currentTarget&&!e.currentTarget.classList.contains('other-month'))e.currentTarget.classList.add('sel-day');
    document.getElementById('day-panel-title').textContent=label;
    const body=document.getElementById('day-panel-body');const avail=AVAIL_BY_DATE[dateKey]||[];const daySessions=SESSION_DATA.filter(s=>s.scheduled_at&&String(s.scheduled_at).startsWith(dateKey));let html='';
    if(avail.length){html+='<div class="section-divider">Open mentor slots</div>';avail.forEach(slot=>{const duration=slot.duration_minutes||slot.duration||60;html+=`<div class="slot-item"><div><div class="slot-time">${esc(fmtTime(slot.slot_time))}</div><div class="slot-mentor">${esc(slot.mentor_name)}</div>${slot.mentor_org?`<div class="slot-org">${esc(slot.mentor_org)}</div>`:''}</div><button class="btn btn-sm btn-primary" type="button" onclick="openRequestModalForMentor(${parseInt(slot.mentor_id,10)},'${escAttr(dateKey)}','${escAttr(slot.slot_time)}','${escAttr(duration)}')"><i class="fa fa-calendar-plus"></i> Book</button></div>`;});}
    if(daySessions.length){html+='<div class="section-divider">Your sessions</div>';daySessions.forEach(s=>{html+=`<div class="session-item is-clickable" onclick="openDetailModal(${parseInt(s.id,10)})"><div class="si-body"><div class="si-title">${esc(s.title)}</div><div class="si-meta">${esc(s.mentor_name)} . ${esc(getTimeFromDate(s.scheduled_at))}</div><span class="si-badge ${esc(s.status_class)}">${esc(s.status_label)}</span>${s.meeting_link && ['scheduled','confirmed'].includes(s.status) ? `<a class="join-mini" href="${escAttr(s.meeting_link)}" target="_blank" rel="noopener" onclick="event.stopPropagation();"><i class="fa fa-video"></i> Join meeting</a>` : ''}</div></div>`;});}
    if(!avail.length&&!daySessions.length)html='<p class="muted-sm py-8">No mentor availability or sessions on this day.</p>';body.innerHTML=html;
}
function openDetailModal(id){
    const s=SESSION_DATA.find(item=>parseInt(item.id,10)===parseInt(id,10));if(!s)return;document.getElementById('dm-title').textContent=s.title||'Session details';
    const dt=s.scheduled_at?new Date(String(s.scheduled_at).replace(' ','T')):null;const dtStr=dt&&!isNaN(dt.getTime())?dt.toLocaleDateString('en-GB',{weekday:'long',day:'numeric',month:'long',year:'numeric'})+' at '+dt.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'}):'TBD';
    document.getElementById('dm-body').innerHTML=`<div class="detail-row"><span class="detail-lbl">Mentor</span><span class="detail-val"><strong>${esc(s.mentor_name)}</strong><br><span class="muted-xs">${esc(s.mentor_org)}</span></span></div><div class="detail-row"><span class="detail-lbl">Status</span><span class="detail-val"><span class="si-badge ${esc(s.status_class)}">${esc(s.status_label)}</span></span></div><div class="detail-row"><span class="detail-lbl">Date &amp; time</span><span class="detail-val detail-sm">${esc(dtStr)}</span></div><div class="detail-row"><span class="detail-lbl">Duration</span><span class="detail-val">${parseInt(s.duration,10)} min . ${esc(s.session_type)}</span></div><div class="detail-row"><span class="detail-lbl">Platform</span><span class="detail-val">${esc(s.platform)}</span></div>${s.meeting_link?`<div class="detail-row"><span class="detail-lbl">Meeting link</span><span class="detail-val"><a href="${escAttr(s.meeting_link)}" target="_blank" rel="noopener" class="meeting-link-badge"><i class="fa fa-video"></i> Join meeting</a></span></div>`:((['scheduled','confirmed'].includes(s.status))?`<div class="detail-row"><span class="detail-lbl">Meeting link</span><span class="detail-val detail-sm">Meeting link not added yet.</span></div>`:'')}${s.description?`<div class="detail-row"><span class="detail-lbl">Agenda</span><span class="detail-val detail-sm">${esc(s.description).replace(/\n/g,'<br>')}</span></div>`:''}${s.notes_shared?`<div class="detail-row"><span class="detail-lbl">Shared notes</span><span class="detail-val detail-note">${esc(s.notes_shared).replace(/\n/g,'<br>')}</span></div>`:''}${s.action_items?`<div class="detail-row"><span class="detail-lbl">Action items</span><span class="detail-val detail-sm">${esc(s.action_items).replace(/\n/g,'<br>')}</span></div>`:''}`;
    let footer=`<button class="btn" type="button" onclick="closeModal('detailModal')">Close</button>`;

    if(['requested','scheduled','confirmed'].includes(s.status)){
        footer+=`<span class="status-help" style="margin-right:auto">Contact the programme team if this session needs to be changed or cancelled.</span>`;
    }

    document.getElementById('dm-footer').innerHTML=footer;
    openModal('detailModal');
}
function fmtTime(time){if(!time)return'';const parts=String(time).substring(0,5).split(':');let hour=parseInt(parts[0],10);const minute=parts[1]||'00';const ampm=hour>=12?'pm':'am';hour=hour%12;if(hour===0)hour=12;return `${hour}:${minute} ${ampm}`;}
function getTimeFromDate(dateTime){if(!dateTime)return'';const parts=String(dateTime).split(' ');return fmtTime(parts[1]||'');}
function esc(value){if(value===null||value===undefined)return'';return String(value).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function escAttr(value){return esc(value).replace(/`/g,'&#096;');}
</script>
</body>
</html>
