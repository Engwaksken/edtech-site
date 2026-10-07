<?php

require_once '../includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/session-junctions.php';

if (!isset($conn) || !($conn instanceof mysqli)) die('DB error');
$conn->set_charset('utf8mb4');

/* Auth */
ms_auth_gate();
$IS_MENTOR        = ms_is_mentor();
$CAN_MANAGE       = ms_can_manage();
$MENTOR_RECORD_ID = ms_mentor_record_id($conn);

if ($IS_MENTOR && $MENTOR_RECORD_ID <= 0) {
    include 'includes/sidebar.php'; ?>
    <div class="admin-main"><div class="admin-content legacy-style-5534667570">
    <h2>Account not linked</h2>
    <p>Your mentor account is not yet linked to a mentor profile. Please contact the programme administrator.</p>
    </div></div><?php exit;
}



function ms_table_has_column(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

function ms_add_column_if_missing(mysqli $conn, string $table, string $column, string $definition): void
{
    if (ms_table_has_column($conn, $table, $column)) return;
    $ok = @$conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    // Ignore duplicate-column races (e.g. two requests bootstrapping at once).
    if (!$ok && stripos($conn->error, 'Duplicate column') === false) {
        // Non-fatal: page still works, tracking column simply won't exist yet.
    }
}

function ms_bootstrap_schema(mysqli $conn): void
{
    // Step 1: make sure the column exists. Add it nullable first so the
    // backfill in step 2 can run safely even before we know every row has
    // a value.
    ms_add_column_if_missing(
        $conn,
        'session_mentors',
        'updated_at',
        'TIMESTAMP NULL DEFAULT NULL'
    );

    // Step 2: one-off backfill. Any row that has never actually been
    // updated (updated_at still NULL -- either brand new from step 1, or
    // left over from an earlier version of this bootstrap) should default
    // to its own created_at, since "created" counts as the first "update".
    // This only ever touches rows where updated_at IS NULL, so real
    // tracking data from genuine future edits is never overwritten.
    @$conn->query("
        UPDATE `session_mentors`
        SET `updated_at` = COALESCE(`created_at`, NOW())
        WHERE `updated_at` IS NULL
    ");

    // Step 3: now every row has a value, so it's safe to enforce NOT NULL
    // and give new rows a default of CURRENT_TIMESTAMP -- matching
    // created_at at insert time -- while still auto-refreshing on every
    // future UPDATE. Safe/idempotent to run on every request.
    @$conn->query("
        ALTER TABLE `session_mentors`
        MODIFY COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ");
}
ms_bootstrap_schema($conn);

/* =========================================================
   DISPLAY MAPS (moved up so the export handler can use them
   before any HTML is rendered)
========================================================= */
$status_map   = ['requested'=>['Requested','badge-warning'],'scheduled'=>['Scheduled','badge-info'],'confirmed'=>['Confirmed','badge-primary'],'completed'=>['Completed','badge-success'],'cancelled'=>['Cancelled','badge-danger'],'no_show'=>['No Show','badge-gray']];
$type_map     = ['one_on_one'=>'1-on-1','group'=>'Group','workshop'=>'Workshop','review'=>'Review','ad_hoc'=>'Ad Hoc'];
$platform_map = ['google_meet'=>'Google Meet', 'zoom'=>'Zoom','teams'=>'MS Teams','phone'=>'Phone','in_person'=>'In Person','other'=>'Other'];
$site_name    = function_exists('get_setting') ? get_setting($conn,'site_name','EdTech Fellowship') : 'EdTech Fellowship';

/* =========================================================
   VENTURE MENTORSHIP FEEDBACK REPORT -- display maps + helpers
   (mirrors the option sets used by the venture-facing form in
   sessions.php / includes/portal-process.php so labels line up
   1:1 with what the venture actually chose)
========================================================= */
$fb_mode_labels = [
    'physical' => 'Physical',
    'virtual'  => 'Virtual',
    'hybrid'   => 'Hybrid',
];
$fb_value_labels = [
    'extremely_valuable'  => 'Extremely Valuable',
    'very_valuable'       => 'Very Valuable',
    'moderately_valuable' => 'Moderately Valuable',
    'slightly_valuable'   => 'Slightly Valuable',
    'not_valuable'        => 'Not Valuable',
];
$fb_progress_labels = [
    'significant' => 'Significant Progress',
    'moderate'    => 'Moderate Progress',
    'slight'      => 'Slight Progress',
    'none'        => 'No Progress Yet',
];
$fb_recommend_labels = [
    'definitely'     => 'Definitely',
    'probably'       => 'Probably',
    'not_sure'       => 'Not Sure',
    'probably_not'   => 'Probably Not',
    'definitely_not' => 'Definitely Not',
];
$fb_confidence_labels = [
    'very_confident'      => 'Very Confident',
    'confident'           => 'Confident',
    'somewhat_confident'  => 'Somewhat Confident',
    'not_confident'       => 'Not Confident',
];
$fb_urgency_labels = [
    'high'   => 'High',
    'medium' => 'Medium',
    'low'    => 'Low',
];
$fb_status_labels = [
    'draft'     => ['Draft', 'badge-gray'],
    'submitted' => ['Submitted for Review', 'badge-warning'],
    'reviewed'  => ['Reviewed', 'badge-success'],
];

/** Safely json_decode a feedback-report repeatable-section column into an array of rows. */
function fb_json_rows(?string $json): array
{
    if (!$json) return [];
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

/* =========================================================
   ENRICHMENT + EXPORT HELPERS
========================================================= */

/**
 * Load mentor/venture attendee rows for a set of session ids, and merge in
 * the extra tracking fields (session_mentors.responded_at / .updated_at and
 * session_ventures.created_at) read straight from the junction tables.
 * Columns are probed first so this stays safe on installs where a column
 * hasn't been created yet.
 *
 * @return array{0: array<int, array>, 1: array<int, array>}
 */
function ms_enrich_sessions(mysqli $conn, array $session_ids): array
{
    $mentors_by_session  = ms_get_mentors_for_sessions($conn, $session_ids);
    $ventures_by_session = ms_get_ventures_for_sessions($conn, $session_ids);

    if (!$session_ids) {
        return [$mentors_by_session, $ventures_by_session];
    }

    $has_responded_at = ms_table_has_column($conn, 'session_mentors', 'responded_at');
    $has_updated_at   = ms_table_has_column($conn, 'session_mentors', 'updated_at');
    $has_sv_created   = ms_table_has_column($conn, 'session_ventures', 'created_at');

    $in = implode(',', array_map('intval', $session_ids));

    $mentor_meta = [];
    if ($has_responded_at || $has_updated_at) {
        $cols = ['session_id', 'mentor_id'];
        if ($has_responded_at) $cols[] = 'responded_at';
        if ($has_updated_at)   $cols[] = 'updated_at';
        $colSql = '`' . implode('`,`', $cols) . '`';
        $res = $conn->query("SELECT $colSql FROM session_mentors WHERE session_id IN ($in)");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $mentor_meta[(int)$r['session_id']][(int)$r['mentor_id']] = $r;
            }
        }
    }

    $venture_meta = [];
    if ($has_sv_created) {
        $res = $conn->query("SELECT session_id, venture_id, created_at FROM session_ventures WHERE session_id IN ($in)");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $venture_meta[(int)$r['session_id']][(int)$r['venture_id']] = $r['created_at'];
            }
        }
    }

    foreach ($mentors_by_session as $sid => &$rows) {
        foreach ($rows as &$m) {
            $meta = $mentor_meta[$sid][(int)$m['mentor_id']] ?? [];
            $m['responded_at'] = $meta['responded_at'] ?? null;
            $m['updated_at']   = $meta['updated_at'] ?? null;
        }
        unset($m);
    }
    unset($rows);

    foreach ($ventures_by_session as $sid => &$rows) {
        foreach ($rows as &$v) {
            $v['requested_at'] = $venture_meta[$sid][(int)$v['venture_id']] ?? null;
        }
        unset($v);
    }
    unset($rows);

    return [$mentors_by_session, $ventures_by_session];
}

function ms_find_organizer_id(array $mentors_all): int
{
    foreach ($mentors_all as $m) {
        if ($m['role'] === 'organizer') return (int)$m['mentor_id'];
    }
    return (int)($mentors_all[0]['mentor_id'] ?? 0);
}

/**
 * Attach the flattened summary fields (mentor_name, venture_name, joined
 * name lists, counts, and the first responded/requested timestamps) onto
 * each session row. Shared by both the normal list/calendar view and the
 * CSV/PDF export path so the two never drift apart.
 */
function ms_apply_session_summaries(array &$sessions_arr, array $mentors_by_session, array $ventures_by_session): void
{
    foreach ($sessions_arr as &$s) {
        $sid = (int)$s['id'];
        $m_rows = $mentors_by_session[$sid] ?? [];
        $v_rows = $ventures_by_session[$sid] ?? [];

        $organizer = null;
        foreach ($m_rows as $m) { if ($m['role'] === 'organizer') { $organizer = $m; break; } }

        $s['mentor_name']         = $organizer['full_name'] ?? ($m_rows[0]['full_name'] ?? '');
        $s['mentor_count']        = count($m_rows);
        $s['mentors_all']         = $m_rows;
        $s['mentor_names_joined'] = implode(', ', array_column($m_rows, 'full_name'));
        $s['organizer_responded_at'] = $organizer['responded_at'] ?? null;
        $s['organizer_updated_at']   = $organizer['updated_at'] ?? null;

        $s['venture_name']          = $v_rows[0]['name'] ?? '';
        $s['venture_count']         = count($v_rows);
        $s['ventures_all']          = $v_rows;
        $s['venture_names_joined']  = implode(', ', array_column($v_rows, 'name'));
        $s['first_venture_requested_at'] = $v_rows[0]['requested_at'] ?? null;
    }
    unset($s);
}

/** Flatten enriched session rows into plain export rows shared by CSV + PDF. */
function ms_build_export_rows(array $sessions, array $status_map, array $type_map, array $platform_map): array
{
    $out = [];
    foreach ($sessions as $s) {
        $status_label = $status_map[$s['status']][0] ?? $s['status'];

        $mentor_responded = '';
        foreach (($s['mentors_all'] ?? []) as $m) {
            if (!empty($m['responded_at'])) { $mentor_responded = date('j M Y g:ia', strtotime($m['responded_at'])); break; }
        }

        $venture_requested = '';
        foreach (($s['ventures_all'] ?? []) as $v) {
            if (!empty($v['requested_at'])) { $venture_requested = date('j M Y g:ia', strtotime($v['requested_at'])); break; }
        }

        // Most recent session_mentors.updated_at across all attendees on this session.
        $mentor_updated = '';
        $latest_update_ts = 0;
        foreach (($s['mentors_all'] ?? []) as $m) {
            if (!empty($m['updated_at'])) {
                $ts = strtotime($m['updated_at']);
                if ($ts > $latest_update_ts) {
                    $latest_update_ts = $ts;
                    $mentor_updated = date('j M Y g:ia', $ts);
                }
            }
        }

        $out[] = [
            'title'             => (string)($s['title'] ?? ''),
            'status_label'      => (string)$status_label,
            'date'              => !empty($s['scheduled_at']) ? date('j M Y', strtotime($s['scheduled_at'])) : 'TBD',
            'time'              => !empty($s['scheduled_at']) ? date('g:i A', strtotime($s['scheduled_at'])) : '',
            'duration'          => (int)($s['duration_minutes'] ?? 0),
            'type'              => (string)($type_map[$s['session_type']] ?? $s['session_type']),
            'platform'          => (string)($platform_map[$s['meeting_platform']] ?? $s['meeting_platform']),
            'mentor_names'      => (string)($s['mentor_names_joined'] ?? ''),
            'mentor_responded'  => $mentor_responded,
            'mentor_updated'    => $mentor_updated,
            'venture_names'     => (string)($s['venture_names_joined'] ?? ''),
            'venture_requested' => $venture_requested,
            'cohort'            => (string)($s['cohort_name'] ?? ''),
            'venture_rating'    => $s['venture_rating'] !== null ? (string)$s['venture_rating'] : '',
            'mentor_rating'     => $s['mentor_rating']  !== null ? (string)$s['mentor_rating']  : '',
            'meeting_link'      => (string)($s['meeting_link'] ?? ''),
        ];
    }
    return $out;
}

function ms_export_csv(array $rows): void
{
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="mentor-sessions-' . date('Y-m-d_His') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // UTF-8 BOM so Excel opens special characters correctly.
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Title', 'Status', 'Date', 'Time', 'Duration (min)', 'Type', 'Platform',
        'Mentor(s)', 'Mentor Responded', 'Mentor Updated', 'Venture(s)', 'Venture Requested',
        'Cohort', 'Venture Rating', 'Mentor Rating', 'Meeting Link'
    ]);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['title'], $r['status_label'], $r['date'], $r['time'], $r['duration'],
            $r['type'], $r['platform'], $r['mentor_names'], $r['mentor_responded'], $r['mentor_updated'],
            $r['venture_names'], $r['venture_requested'], $r['cohort'],
            $r['venture_rating'], $r['mentor_rating'], $r['meeting_link']
        ]);
    }
    fclose($out);
    exit;
}

function ms_export_pdf(array $rows, string $site_name): void
{
    $autoload = __DIR__ . '/../vendor/autoload.php';

    if (!file_exists($autoload)) {
        while (ob_get_level() > 0) @ob_end_clean();
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "PDF export unavailable: Composer autoload not found at vendor/autoload.php.\n";
        echo "Run: composer require dompdf/dompdf";
        exit;
    }

    require_once $autoload;

    if (!class_exists('\\Dompdf\\Dompdf')) {
        while (ob_get_level() > 0) @ob_end_clean();
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "PDF export unavailable: dompdf/dompdf is not installed.\n";
        echo "Run: composer require dompdf/dompdf";
        exit;
    }

    $esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

    $html  = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>';
    $html .= '<h1>' . $esc($site_name) . ' &ndash; Mentoring Sessions Export</h1>';
    $html .= '<p class="sub">Generated ' . $esc(date('j M Y, g:ia')) . ' &middot; ' . count($rows) . ' session(s)</p>';
    $html .= '<table><thead><tr>'
           . '<th>Title</th><th>Status</th><th>Date</th><th>Time</th><th>Dur.</th><th>Type</th><th>Platform</th>'
           . '<th>Mentor(s)</th><th>Mentor Responded</th><th>Mentor Updated</th><th>Venture(s)</th><th>Venture Requested</th>'
           . '<th>Cohort</th><th>V.Rating</th><th>M.Rating</th>'
           . '</tr></thead><tbody>';

    foreach ($rows as $r) {
        $html .= '<tr>'
            . '<td>' . $esc($r['title']) . '</td>'
            . '<td>' . $esc($r['status_label']) . '</td>'
            . '<td>' . $esc($r['date']) . '</td>'
            . '<td>' . $esc($r['time']) . '</td>'
            . '<td>' . $esc($r['duration']) . '</td>'
            . '<td>' . $esc($r['type']) . '</td>'
            . '<td>' . $esc($r['platform']) . '</td>'
            . '<td>' . $esc($r['mentor_names']) . '</td>'
            . '<td>' . $esc($r['mentor_responded']) . '</td>'
            . '<td>' . $esc($r['mentor_updated']) . '</td>'
            . '<td>' . $esc($r['venture_names']) . '</td>'
            . '<td>' . $esc($r['venture_requested']) . '</td>'
            . '<td>' . $esc($r['cohort']) . '</td>'
            . '<td>' . $esc($r['venture_rating']) . '</td>'
            . '<td>' . $esc($r['mentor_rating']) . '</td>'
            . '</tr>';
    }
    $html .= '</tbody></table></body></html>';

    $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->loadHtml($html);
    $dompdf->render();

    while (ob_get_level() > 0) @ob_end_clean();
    $dompdf->stream('mentor-sessions-' . date('Y-m-d_His') . '.pdf', ['Attachment' => true]);
    exit;
}


/* =========================================================
   VENTURE SESSION REPORT PDF
   Programme/admin only. Mentors must never see or download
   venture-submitted session reports.
========================================================= */
function ms_feedback_pdf_esc($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function ms_feedback_pdf_text($value): string
{
    $value = trim((string)($value ?? ''));
    return $value !== '' ? nl2br(ms_feedback_pdf_esc($value)) : '<span class="muted">Not provided.</span>';
}

function ms_feedback_pdf_table(array $rows, array $columns): string
{
    if (!$rows) {
        return '<p class="muted">None recorded.</p>';
    }

    $html = '<table class="report-table"><thead><tr>';

    foreach ($columns as $column) {
        $html .= '<th>' . ms_feedback_pdf_esc($column['label'] ?? '') . '</th>';
    }

    $html .= '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $html .= '<tr>';

        foreach ($columns as $column) {
            $key = (string)($column['key'] ?? '');
            $val = (string)($row[$key] ?? '');

            if (!empty($column['labels']) && is_array($column['labels']) && $val !== '') {
                $val = (string)($column['labels'][$val] ?? $val);
            }

            $html .= '<td>' . ($val !== '' ? ms_feedback_pdf_esc($val) : '<span class="muted">-</span>') . '</td>';
        }

        $html .= '</tr>';
    }

    $html .= '</tbody></table>';

    return $html;
}

function ms_download_feedback_report_pdf(
    mysqli $conn,
    int $session_id,
    string $site_name,
    array $fb_mode_labels,
    array $fb_value_labels,
    array $fb_progress_labels,
    array $fb_recommend_labels,
    array $fb_confidence_labels,
    array $fb_urgency_labels
): void {
    $autoload = __DIR__ . '/../vendor/autoload.php';

    if (!file_exists($autoload)) {
        while (ob_get_level() > 0) @ob_end_clean();
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "PDF download unavailable: Composer autoload was not found.\n";
        echo "Run: composer require dompdf/dompdf";
        exit;
    }

    require_once $autoload;

    if (!class_exists('\\Dompdf\\Dompdf')) {
        while (ob_get_level() > 0) @ob_end_clean();
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "PDF download unavailable: dompdf/dompdf is not installed.\n";
        echo "Run: composer require dompdf/dompdf";
        exit;
    }

    if (!$conn->query("SHOW TABLES LIKE 'session_feedback_reports'")->num_rows) {
        http_response_code(404);
        echo 'Session reports are not available.';
        exit;
    }

    $st = $conn->prepare("
        SELECT
            sfr.*,
            ms.title AS session_title,
            ms.scheduled_at,
            ms.duration_minutes,
            c.name AS cohort_name
        FROM session_feedback_reports sfr
        INNER JOIN mentor_sessions ms ON ms.id = sfr.session_id
        LEFT JOIN cohorts c ON c.id = ms.cohort_id
        WHERE sfr.session_id = ?
        LIMIT 1
    ");

    if (!$st) {
        http_response_code(500);
        echo 'Database error.';
        exit;
    }

    $st->bind_param('i', $session_id);
    $st->execute();
    $fb = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$fb) {
        http_response_code(404);
        echo 'Session report not found.';
        exit;
    }

    if (!in_array((string)$fb['status'], ['submitted', 'reviewed'], true)) {
        http_response_code(403);
        echo 'Only submitted session reports can be downloaded as PDF.';
        exit;
    }

    $discussion_rows = fb_json_rows($fb['discussion_summary'] ?? null);
    $assessment_rows = fb_json_rows($fb['mentor_assessment'] ?? null);
    $action_rows     = fb_json_rows($fb['action_plan'] ?? null);
    $challenge_rows  = fb_json_rows($fb['challenges'] ?? null);

    $session_date = trim((string)($fb['session_date'] ?? ''));
    if ($session_date === '' && !empty($fb['scheduled_at'])) {
        $session_date = date('j F Y', strtotime((string)$fb['scheduled_at']));
    } elseif ($session_date !== '') {
        $ts = strtotime($session_date);
        if ($ts !== false) {
            $session_date = date('j F Y', $ts);
        }
    }

    $session_duration = trim((string)($fb['session_duration'] ?? ''));
    if ($session_duration === '' && !empty($fb['duration_minutes'])) {
        $session_duration = (int)$fb['duration_minutes'] . ' minutes';
    }

    $mode = (string)($fb['mode_of_engagement'] ?? '');
    $value_rating = (string)($fb['value_rating'] ?? '');
    $progress_rating = (string)($fb['progress_rating'] ?? '');
    $recommend_mentor = (string)($fb['recommend_mentor'] ?? '');

    $status_label = (string)$fb['status'] === 'reviewed' ? 'Reviewed' : 'Submitted for Review';

    $submitted_at = !empty($fb['submitted_at'])
        ? date('j M Y, g:i A', strtotime((string)$fb['submitted_at']))
        : '';

    $reviewed_at = !empty($fb['reviewed_at'])
        ? date('j M Y, g:i A', strtotime((string)$fb['reviewed_at']))
        : '';

    $html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 28px 30px 34px; }
    body {
        font-family: DejaVu Sans, sans-serif;
        color: #1f2937;
        font-size: 10px;
        line-height: 1.45;
    }
    .header {
        border-bottom: 3px solid #f97316;
        padding-bottom: 12px;
        margin-bottom: 18px;
    }
    .brand {
        color: #f97316;
        font-size: 11px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: .5px;
    }
    h1 {
        font-size: 20px;
        margin: 5px 0 4px;
        color: #111827;
    }
    .sub {
        color: #6b7280;
        margin: 0;
    }
    .status {
        display: inline-block;
        background: #fff7ed;
        color: #c2410c;
        border: 1px solid #fed7aa;
        border-radius: 4px;
        padding: 3px 7px;
        margin-top: 8px;
        font-weight: bold;
    }
    .section {
        margin-bottom: 15px;
        page-break-inside: avoid;
    }
    .section-title {
        font-size: 12px;
        font-weight: bold;
        color: #111827;
        border-bottom: 1px solid #e5e7eb;
        padding-bottom: 5px;
        margin-bottom: 8px;
    }
    .section-number {
        display: inline-block;
        width: 17px;
        height: 17px;
        line-height: 17px;
        text-align: center;
        border-radius: 50%;
        background: #f97316;
        color: #ffffff;
        margin-right: 6px;
        font-size: 9px;
    }
    .info-table,
    .report-table {
        width: 100%;
        border-collapse: collapse;
    }
    .info-table td {
        width: 33.33%;
        vertical-align: top;
        border: 1px solid #e5e7eb;
        padding: 7px 8px;
    }
    .label {
        color: #6b7280;
        font-size: 8px;
        text-transform: uppercase;
        font-weight: bold;
        margin-bottom: 2px;
    }
    .value {
        color: #111827;
        font-size: 10px;
    }
    .report-table th {
        background: #f8fafc;
        border: 1px solid #dfe3e8;
        padding: 6px;
        text-align: left;
        color: #475569;
        font-size: 8px;
        text-transform: uppercase;
    }
    .report-table td {
        border: 1px solid #dfe3e8;
        padding: 6px;
        vertical-align: top;
    }
    .text-block {
        border: 1px solid #e5e7eb;
        background: #fbfcfd;
        padding: 8px 9px;
        border-radius: 4px;
    }
    .two-col {
        width: 100%;
        border-collapse: collapse;
    }
    .two-col td {
        width: 50%;
        vertical-align: top;
        padding-right: 8px;
    }
    .two-col td:last-child {
        padding-right: 0;
        padding-left: 8px;
    }
    .muted {
        color: #94a3b8;
        font-style: italic;
    }
    .review-box {
        margin-top: 10px;
        padding: 9px;
        border-left: 4px solid #16a34a;
        background: #f0fdf4;
    }
    .footer-note {
        color: #94a3b8;
        font-size: 8px;
        text-align: center;
        margin-top: 16px;
        border-top: 1px solid #e5e7eb;
        padding-top: 8px;
    }
</style>
</head>
<body>';

    $html .= '<div class="header">';
    $html .= '<div class="brand">' . ms_feedback_pdf_esc($site_name) . '</div>';
    $html .= '<h1>Venture Mentorship Session Report</h1>';
    $html .= '<p class="sub">' . ms_feedback_pdf_esc($fb['session_title'] ?? 'Mentorship Session') . '</p>';
    $html .= '<span class="status">' . ms_feedback_pdf_esc($status_label) . '</span>';
    $html .= '</div>';

    $html .= '<div class="section">';
    $html .= '<div class="section-title">Session Information</div>';
    $html .= '<table class="info-table">';
    $html .= '<tr>';
    $html .= '<td><div class="label">Venture Name</div><div class="value">' . ms_feedback_pdf_esc($fb['venture_name'] ?? '-') . '</div></td>';
    $html .= '<td><div class="label">Founder(s) / Representative(s) Present</div><div class="value">' . ms_feedback_pdf_esc($fb['founders_present'] ?? '-') . '</div></td>';
    $html .= '<td><div class="label">Mentor Name</div><div class="value">' . ms_feedback_pdf_esc($fb['mentor_name'] ?? '-') . '</div></td>';
    $html .= '</tr>';
    $html .= '<tr>';
    $html .= '<td><div class="label">Session Date</div><div class="value">' . ms_feedback_pdf_esc($session_date ?: '-') . '</div></td>';
    $html .= '<td><div class="label">Session Duration</div><div class="value">' . ms_feedback_pdf_esc($session_duration ?: '-') . '</div></td>';
    $html .= '<td><div class="label">Mode of Engagement</div><div class="value">' . ms_feedback_pdf_esc($fb_mode_labels[$mode] ?? ($mode ?: '-')) . '</div></td>';
    $html .= '</tr>';
    if (!empty($fb['cohort_name'])) {
        $html .= '<tr><td colspan="3"><div class="label">Cohort</div><div class="value">' . ms_feedback_pdf_esc($fb['cohort_name']) . '</div></td></tr>';
    }
    $html .= '</table>';
    $html .= '</div>';

    $html .= '<div class="section"><div class="section-title"><span class="section-number">1</span>Session Objectives</div>';
    $html .= '<div class="text-block">' . ms_feedback_pdf_text($fb['objectives'] ?? '') . '</div></div>';

    $html .= '<div class="section"><div class="section-title"><span class="section-number">2</span>Session Discussion Summary</div>';
    $html .= ms_feedback_pdf_table($discussion_rows, [
        ['key' => 'area', 'label' => 'Discussion Area'],
        ['key' => 'helpful', 'label' => 'How Helpful (1-5)'],
        ['key' => 'comments', 'label' => 'Comments'],
    ]);
    $html .= '</div>';

    $html .= '<div class="section"><div class="section-title"><span class="section-number">3</span>Mentor Assessment</div>';
    $html .= ms_feedback_pdf_table($assessment_rows, [
        ['key' => 'area', 'label' => 'Assessment Area'],
        ['key' => 'rating', 'label' => 'Rating (1-5)'],
        ['key' => 'comments', 'label' => 'Comments'],
    ]);
    $html .= '</div>';

    $html .= '<div class="section"><div class="section-title"><span class="section-number">4</span>Value of the Session</div>';
    $html .= '<table class="two-col"><tr>';
    $html .= '<td><div class="label">Overall Value</div><div class="text-block">' . ms_feedback_pdf_esc($fb_value_labels[$value_rating] ?? ($value_rating ?: '-')) . '</div></td>';
    $html .= '<td><div class="label">Most Valuable Insight</div><div class="text-block">' . ms_feedback_pdf_text($fb['valuable_insight'] ?? '') . '</div></td>';
    $html .= '</tr></table>';
    $html .= '<div style="margin-top:8px"><div class="label">Recommendations Made</div><div class="text-block">' . ms_feedback_pdf_text($fb['recommendations'] ?? '') . '</div></div>';
    $html .= '</div>';

    $html .= '<div class="section"><div class="section-title"><span class="section-number">5</span>Action Plan</div>';
    $html .= ms_feedback_pdf_table($action_rows, [
        ['key' => 'action', 'label' => 'Agreed Action'],
        ['key' => 'responsible', 'label' => 'Responsible Person'],
        ['key' => 'timeline', 'label' => 'Timeline'],
        ['key' => 'confidence', 'label' => 'Confidence', 'labels' => $fb_confidence_labels],
    ]);
    $html .= '</div>';

    $html .= '<div class="section"><div class="section-title"><span class="section-number">6</span>Progress Assessment</div>';
    $html .= '<div class="label">Progress</div><div class="text-block">' . ms_feedback_pdf_esc($fb_progress_labels[$progress_rating] ?? ($progress_rating ?: '-')) . '</div>';
    $html .= '<div style="margin-top:8px"><div class="label">Explanation</div><div class="text-block">' . ms_feedback_pdf_text($fb['progress_explain'] ?? '') . '</div></div>';
    $html .= '</div>';

    $html .= '<div class="section"><div class="section-title"><span class="section-number">7</span>Remaining Challenges</div>';
    $html .= ms_feedback_pdf_table($challenge_rows, [
        ['key' => 'challenge', 'label' => 'Challenge'],
        ['key' => 'urgency', 'label' => 'Urgency', 'labels' => $fb_urgency_labels],
        ['key' => 'support', 'label' => 'Support Needed'],
    ]);
    $html .= '</div>';

    $html .= '<div class="section"><div class="section-title"><span class="section-number">8</span>Future Support Needs</div>';
    $html .= '<div class="text-block">' . ms_feedback_pdf_text($fb['future_support'] ?? '') . '</div></div>';

    $html .= '<div class="section"><div class="section-title"><span class="section-number">9</span>Overall Session Satisfaction</div>';
    $html .= '<table class="two-col"><tr>';
    $html .= '<td><div class="label">Satisfaction Rating</div><div class="text-block">' . ms_feedback_pdf_esc($fb['satisfaction_rating'] !== null ? ((string)$fb['satisfaction_rating'] . ' / 5') : '-') . '</div></td>';
    $html .= '<td><div class="label">Would Recommend Mentor?</div><div class="text-block">' . ms_feedback_pdf_esc($fb_recommend_labels[$recommend_mentor] ?? ($recommend_mentor ?: '-')) . '</div></td>';
    $html .= '</tr></table>';
    if (!empty($fb['satisfaction_comments'])) {
        $html .= '<div style="margin-top:8px"><div class="label">Comments</div><div class="text-block">' . ms_feedback_pdf_text($fb['satisfaction_comments']) . '</div></div>';
    }
    $html .= '</div>';

    $html .= '<div class="section"><div class="section-title"><span class="section-number">10</span>Additional Feedback for Hive Colab</div>';
    $html .= '<table class="two-col"><tr>';
    $html .= '<td><div class="label">How Could Hive Colab Improve the Programme?</div><div class="text-block">' . ms_feedback_pdf_text($fb['feedback_for_admin'] ?? '') . '</div></td>';
    $html .= '<td><div class="label">What Could the Mentor Do Differently?</div><div class="text-block">' . ms_feedback_pdf_text($fb['mentor_could_improve'] ?? '') . '</div></td>';
    $html .= '</tr></table>';
    $html .= '</div>';

    if ((string)$fb['status'] === 'reviewed') {
        $html .= '<div class="review-box">';
        $html .= '<strong>Programme Review</strong>';

        if (!empty($fb['reviewed_by'])) {
            $html .= '<br>Reviewed by: ' . ms_feedback_pdf_esc($fb['reviewed_by']);
        }

        if ($reviewed_at !== '') {
            $html .= '<br>Reviewed: ' . ms_feedback_pdf_esc($reviewed_at);
        }

        if (!empty($fb['review_notes'])) {
            $html .= '<br><br><strong>Review Notes:</strong><br>' . ms_feedback_pdf_text($fb['review_notes']);
        }

        $html .= '</div>';
    }

    $html .= '<div class="footer-note">';
    if ($submitted_at !== '') {
        $html .= 'Submitted ' . ms_feedback_pdf_esc($submitted_at) . ' &middot; ';
    }
    $html .= 'Generated ' . ms_feedback_pdf_esc(date('j M Y, g:i A'));
    $html .= '</div>';

    $html .= '</body></html>';

    $dompdf = new \Dompdf\Dompdf([
        'isRemoteEnabled' => false,
        'isHtml5ParserEnabled' => true,
    ]);

    $dompdf->setPaper('A4', 'portrait');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();

    $safeVenture = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim((string)($fb['venture_name'] ?? 'venture')));
    $safeVenture = trim((string)$safeVenture, '-');
    if ($safeVenture === '') {
        $safeVenture = 'venture';
    }

    $filename = 'venture-session-report-' . strtolower($safeVenture) . '-' . $session_id . '.pdf';

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}


/* Handle one Venture Mentorship Session Report PDF download.
   This deliberately runs before filters/HTML and is inaccessible
   to mentor accounts, even if somebody manually guesses the URL. */
if (
    isset($_GET['feedback_pdf']) &&
    (string)$_GET['feedback_pdf'] === '1'
) {
    if ($IS_MENTOR || !$CAN_MANAGE) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Access denied.';
        exit;
    }

    $feedback_pdf_session_id = (int)($_GET['session_id'] ?? 0);

    if ($feedback_pdf_session_id <= 0) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Invalid session.';
        exit;
    }

    ms_download_feedback_report_pdf(
        $conn,
        $feedback_pdf_session_id,
        $site_name,
        $fb_mode_labels,
        $fb_value_labels,
        $fb_progress_labels,
        $fb_recommend_labels,
        $fb_confidence_labels,
        $fb_urgency_labels
    );
}


/* Filters + Search + Pagination */
$filter_status  = trim((string)($_GET['status'] ?? ''));
$filter_mentor  = (int)($_GET['mentor_id']  ?? 0);
$filter_venture = (int)($_GET['venture_id'] ?? 0);
$filter_cohort  = (int)($_GET['cohort_id']  ?? 0);
$filter_month   = trim((string)($_GET['month'] ?? date('Y-m')));
$view           = trim((string)($_GET['view'] ?? 'list'));
$search         = trim((string)($_GET['q'] ?? ''));

if (!in_array($view, ['list', 'calendar'], true)) {
    $view = 'list';
}

if (!preg_match('/^\d{4}-\d{2}$/', $filter_month)) {
    $filter_month = date('Y-m');
}

$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = (int)($_GET['per_page'] ?? 10);
$allowed_per_pages = [10, 25, 50, 100];
if (!in_array($per_page, $allowed_per_pages, true)) {
    $per_page = 10;
}

$where = []; $params = []; $types = '';

/*
 * IMPORTANT FIX:
 * Some sessions (typically venture-initiated "requested" sessions) only ever
 * get `mentor_sessions.mentor_id` populated directly and never receive a
 * corresponding row in the `session_mentors` junction table until the
 * session is synced/edited (see ms_get_session_mentors()'s fallback logic in
 * includes/session-junctions.php, which reads ms.mentor_id directly when no
 * junction row exists). The mentor-scoped filter below used to ONLY check
 * the junction table via EXISTS(...), so any session that hadn't been
 * synced into session_mentors yet was invisible to the assigned mentor even
 * though it was visible to admins (who have no such restriction). We now
 * match on EITHER the junction table OR the legacy/direct mentor_id column.
 */
if ($IS_MENTOR) {
    $where[] = '(
        EXISTS (SELECT 1 FROM session_mentors sm WHERE sm.session_id = ms.id AND sm.mentor_id = ?)
        OR ms.mentor_id = ?
    )';
    $params[] = $MENTOR_RECORD_ID; $types .= 'i';
    $params[] = $MENTOR_RECORD_ID; $types .= 'i';
    if ($filter_status !== '') { $where[] = 'ms.status = ?'; $params[] = $filter_status; $types .= 's'; }
} else {
    if ($filter_status  !== '') { $where[] = 'ms.status = ?'; $params[] = $filter_status; $types .= 's'; }
    if ($filter_mentor  >    0) {
        $where[] = '(
            EXISTS (SELECT 1 FROM session_mentors sm WHERE sm.session_id = ms.id AND sm.mentor_id = ?)
            OR ms.mentor_id = ?
        )';
        $params[] = $filter_mentor; $types .= 'i';
        $params[] = $filter_mentor; $types .= 'i';
    }
    if ($filter_venture >    0) {
        $where[] = '(
            EXISTS (SELECT 1 FROM session_ventures sv WHERE sv.session_id = ms.id AND sv.venture_id = ?)
            OR ms.venture_id = ?
        )';
        $params[] = $filter_venture; $types .= 'i';
        $params[] = $filter_venture; $types .= 'i';
    }
    if ($filter_cohort  >    0) { $where[] = 'ms.cohort_id = ?'; $params[] = $filter_cohort; $types .= 'i'; }
}
if ($search !== '') {
    $search_like = '%' . $search . '%';
    $where[] = "(
        ms.title LIKE ?
        OR ms.description LIKE ?
        OR ms.notes_shared LIKE ?
        OR ms.action_items LIKE ?
        OR ms.meeting_platform LIKE ?
        OR ms.session_type LIKE ?
        OR ms.status LIKE ?
        OR c.name LIKE ?
        OR EXISTS (
            SELECT 1
            FROM session_mentors smq
            INNER JOIN mentors mq ON mq.id = smq.mentor_id
            WHERE smq.session_id = ms.id
              AND (
                  mq.full_name LIKE ?
                  OR mq.email LIKE ?
                  OR mq.organisation LIKE ?
              )
        )
        OR EXISTS (
            SELECT 1
            FROM session_ventures svq
            INNER JOIN ventures vq ON vq.id = svq.venture_id
            WHERE svq.session_id = ms.id
              AND vq.name LIKE ?
        )
    )";
    for ($i = 0; $i < 12; $i++) {
        $params[] = $search_like;
        $types .= 's';
    }
}

if ($view === 'calendar') {
    $month_start = $filter_month.'-01';
    $month_end   = date('Y-m-t', strtotime($month_start));
    $where[] = 'DATE(ms.scheduled_at) BETWEEN ? AND ?';
    $params[] = $month_start; $params[] = $month_end; $types .= 'ss';
}
$ws    = $where ? 'WHERE '.implode(' AND ',$where) : '';
$order = $view === 'calendar' ? 'ms.scheduled_at ASC' : 'ms.scheduled_at DESC, ms.id DESC';

/* =========================================================
   CSV / PDF EXPORT
   Runs before any HTML is emitted. Honours the same filters
   (status/mentor/venture/cohort/search/role-scope) as the
   current view, but ignores pagination so the whole filtered
   result set is exported.
========================================================= */
$export = trim((string)($_GET['export'] ?? ''));
if (in_array($export, ['csv', 'pdf'], true)) {
    $exp_sql = "
        SELECT ms.*, c.name AS cohort_name
        FROM mentor_sessions ms
        LEFT JOIN cohorts c ON c.id = ms.cohort_id
        $ws
        ORDER BY $order
    ";
    $exp_st = $conn->prepare($exp_sql);
    if (!$exp_st) {
        die('Database error: ' . $conn->error);
    }
    if ($types) {
        $exp_st->bind_param($types, ...$params);
    }
    $exp_st->execute();
    $exp_res = $exp_st->get_result();
    $exp_sessions = [];
    while ($r = $exp_res->fetch_assoc()) $exp_sessions[] = $r;
    $exp_st->close();

    $exp_ids = array_column($exp_sessions, 'id');
    [$exp_mentors_by_session, $exp_ventures_by_session] = ms_enrich_sessions($conn, $exp_ids);
    ms_apply_session_summaries($exp_sessions, $exp_mentors_by_session, $exp_ventures_by_session);

    $export_rows = ms_build_export_rows($exp_sessions, $status_map, $type_map, $platform_map);

    if ($export === 'csv') {
        ms_export_csv($export_rows);
    } else {
        ms_export_pdf($export_rows, $site_name);
    }
    exit;
}

/* Count for list pagination */
$total_records = 0;
$total_pages   = 1;
$offset        = 0;

if ($view === 'list') {
    $count_sql = "
        SELECT COUNT(*) AS total
        FROM mentor_sessions ms
        LEFT JOIN cohorts c ON c.id = ms.cohort_id
        $ws
    ";
    $count_st = $conn->prepare($count_sql);
    if (!$count_st) {
        die('Database error: '.$conn->error);
    }
    if ($types) {
        $count_st->bind_param($types, ...$params);
    }
    $count_st->execute();
    $count_row = $count_st->get_result()->fetch_assoc();
    $count_st->close();

    $total_records = (int)($count_row['total'] ?? 0);
    $total_pages   = max(1, (int)ceil($total_records / $per_page));
    if ($page > $total_pages) {
        $page = $total_pages;
    }
    $offset = ($page - 1) * $per_page;
}

$list_limit_sql = '';
$query_params = $params;
$query_types  = $types;

if ($view === 'list') {
    $list_limit_sql = ' LIMIT ? OFFSET ?';
    $query_params[] = $per_page;
    $query_params[] = $offset;
    $query_types   .= 'ii';
}

$st = $conn->prepare("
    SELECT ms.*, c.name AS cohort_name
    FROM mentor_sessions ms
    LEFT JOIN cohorts c ON c.id = ms.cohort_id
    $ws
    ORDER BY $order
    $list_limit_sql
");
if (!$st) {
    die('Database error: '.$conn->error);
}
if ($query_types) {
    $st->bind_param($query_types, ...$query_params);
}
$st->execute();
$sessions = $st->get_result(); $st->close();

/* Collect all visible sessions */
$all_sessions_arr = [];
while ($r = $sessions->fetch_assoc()) $all_sessions_arr[] = $r;

$all_session_ids = array_column($all_sessions_arr, 'id');

/* Bulk-load attendees (mentors + ventures) for every visible session, along
   with session_mentors.responded_at / .updated_at and session_ventures.created_at.
   ms_get_mentors_for_sessions() / ms_get_ventures_for_sessions() already fall
   back to ms.mentor_id / ms.venture_id when the junction tables have no rows
   for a session, so the chips/labels stay correct even for not-yet-synced
   sessions. */
[$mentors_by_session, $ventures_by_session] = ms_enrich_sessions($conn, $all_session_ids);

ms_apply_session_summaries($all_sessions_arr, $mentors_by_session, $ventures_by_session);

$organizer_ids = array_filter(array_unique(array_map(
    fn($s) => ms_find_organizer_id($s['mentors_all']),
    $all_sessions_arr
)));
$mentor_org_lookup = [];
if ($organizer_ids) {
    $in = implode(',', array_map('intval', $organizer_ids));
    $res = $conn->query("SELECT id, organisation, photo FROM mentors WHERE id IN ($in)");
    while ($r = $res->fetch_assoc()) $mentor_org_lookup[(int)$r['id']] = $r;
}
foreach ($all_sessions_arr as &$s) {
    $organizer_id = null;
    foreach (($s['mentors_all'] ?? []) as $m) { if ($m['role'] === 'organizer') { $organizer_id = (int)$m['mentor_id']; break; } }
    $lookup = $mentor_org_lookup[$organizer_id] ?? null;
    $s['mentor_org']    = $lookup['organisation'] ?? '';
    $s['mentor_photo']  = $lookup['photo'] ?? '';
}
unset($s);

/*
 * Stats scoped by role (via junction tables, same logic as the WHERE above).
 * FIX: same legacy/direct mentor_id fallback applied here as in the WHERE
 * clause above, so the stat counters match the list the mentor actually sees.
 */
if ($IS_MENTOR) {
    $stats_sql = "
        SELECT SUM(status='requested') AS requested,
               SUM(status='scheduled' OR status='confirmed') AS upcoming,
               SUM(status='completed') AS completed,
               SUM(status='cancelled') AS cancelled,
               SUM(status='no_show')   AS no_show
        FROM mentor_sessions ms
        WHERE (
            EXISTS (SELECT 1 FROM session_mentors sm WHERE sm.session_id = ms.id AND sm.mentor_id = ?)
            OR ms.mentor_id = ?
        )
    ";
    $stx = $conn->prepare($stats_sql);
    $stx->bind_param('ii', $MENTOR_RECORD_ID, $MENTOR_RECORD_ID);
    $stx->execute();
    $stats = $stx->get_result()->fetch_assoc();
    $stx->close();
} else {
    $stats = $conn->query("
        SELECT SUM(status='requested') AS requested,
               SUM(status='scheduled' OR status='confirmed') AS upcoming,
               SUM(status='completed') AS completed,
               SUM(status='cancelled') AS cancelled,
               SUM(status='no_show')   AS no_show
        FROM mentor_sessions
    ")->fetch_assoc();
}

/* Session reports (mentor sees own; privileged see all) */
$session_reports = [];
if (!$IS_MENTOR && $conn->query("SHOW TABLES LIKE 'session_reports'")->num_rows && $all_session_ids) {
    $vis_ids = implode(',', array_map('intval', $all_session_ids));
    $res = $conn->query("SELECT * FROM session_reports WHERE session_id IN($vis_ids) ORDER BY uploaded_at DESC");
    while ($r = $res->fetch_assoc()) $session_reports[(int)$r['session_id']][] = $r;
}

/* Venture Mentorship Feedback Reports (structured template submitted by
   ventures via sessions.php / includes/portal-process.php). One row per
   session (UNIQUE KEY on session_id), so this is keyed directly to the row
   rather than an array of rows. */
$feedback_reports = [];
if (!$IS_MENTOR && $conn->query("SHOW TABLES LIKE 'session_feedback_reports'")->num_rows && $all_session_ids) {
    $vis_ids = implode(',', array_map('intval', $all_session_ids));
    $res = $conn->query("SELECT * FROM session_feedback_reports WHERE session_id IN($vis_ids)");
    while ($r = $res->fetch_assoc()) $feedback_reports[(int)$r['session_id']] = $r;
}

/* Comments */
$comments_by_session = [];
if ($conn->query("SHOW TABLES LIKE 'session_comments'")->num_rows && $all_session_ids) {
    $vis_ids = implode(',', array_map('intval', $all_session_ids));
    $res = $conn->query("SELECT *, COALESCE(author_name,'Admin') AS display_name FROM session_comments WHERE session_id IN($vis_ids) ORDER BY created_at ASC");
    while ($r = $res->fetch_assoc()) $comments_by_session[(int)$r['session_id']][] = $r;
}

/* Dropdowns */
$mentors_dd  = $conn->query("SELECT id,full_name FROM mentors WHERE status='active' ORDER BY full_name");
$ventures_dd = $conn->query("SELECT id,name FROM ventures ORDER BY name");
$cohorts_dd  = $conn->query("SELECT id,name FROM cohorts ORDER BY sort_order,name");
$all_mentors_list = [];
$mentors_dd->data_seek(0);
while ($r = $mentors_dd->fetch_assoc()) $all_mentors_list[] = $r;
$all_ventures_list = [];
$ventures_dd->data_seek(0);
while ($r = $ventures_dd->fetch_assoc()) $all_ventures_list[] = $r;

function ms_page_url(array $override = []): string
{
    $query = array_merge($_GET, $override);

    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }

    return '?' . http_build_query($query);
}

$showing_from = $total_records > 0 ? ($offset + 1) : 0;
$showing_to   = $total_records > 0 ? min($offset + $per_page, $total_records) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Mentor Sessions - <?= h($site_name) ?> Admin</title>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
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
      <a href="index.php">Dashboard</a> &rsaquo;
      <?php if (!$IS_MENTOR): ?><a href="mentors.php">Mentors</a> &rsaquo; <?php endif; ?>
      <strong>Sessions</strong>
    </div>
  </div>
  <div class="topbar-right">
    <div class="admin-avatar"><div class="avatar-circle"><?= strtoupper(substr($ADMIN['full_name'],0,1)) ?></div></div>
  </div>
</header>

<div class="admin-content">
<?php show_flash('sessions'); ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Mentoring Sessions</h1>
    <p class="page-subtitle"><?= $IS_MENTOR ? 'Your sessions, reports &amp; venture ratings' : 'Scheduling, confirmation &amp; attendance tracking' ?></p>
  </div>
  <?php if ($CAN_MANAGE || $IS_MENTOR): ?>
  <div class="page-actions">
    <a class="btn btn-secondary" href="mentor-reports.php"><i class="fa fa-file-signature"></i> Reports Hub</a>
    <button class="btn btn-primary" onclick="openSessionModal()"><i class="fa fa-plus"></i> Schedule Session</button>
  </div>
  <?php endif; ?>
</div>

<!-- Stats -->
<div class="stat-ribbon">
  <div class="stat-rb"><div class="stat-rb-num legacy-style-6a6a237e2c"><?= (int)$stats['requested'] ?></div><div class="stat-rb-lbl">Requested</div></div>
  <div class="stat-rb"><div class="stat-rb-num legacy-style-14a59c6694"><?= (int)$stats['upcoming'] ?></div><div class="stat-rb-lbl">Upcoming</div></div>
  <div class="stat-rb"><div class="stat-rb-num legacy-style-acd97a46d0"><?= (int)$stats['completed'] ?></div><div class="stat-rb-lbl">Completed</div></div>
  <div class="stat-rb"><div class="stat-rb-num legacy-style-b0eb59c131"><?= (int)$stats['cancelled'] ?></div><div class="stat-rb-lbl">Cancelled</div></div>
  <div class="stat-rb"><div class="stat-rb-num legacy-style-db12fa5528"><?= (int)$stats['no_show'] ?></div><div class="stat-rb-lbl">No Shows</div></div>
</div>

<!-- Filters -->
<div class="filter-bar">
  <form method="GET" class="legacy-style-ae125572d5">
    <div class="search-box">
      <i class="fa fa-search"></i>
      <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search sessions, mentors, ventures...">
    </div>
    <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-search"></i> Search</button>

    <select name="status" onchange="this.form.submit()">
      <option value="">All Statuses</option>
      <?php foreach($status_map as $k=>[$l,$b]): ?>
        <option value="<?= $k ?>" <?= $filter_status===$k?'selected':'' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($CAN_MANAGE): ?>
    <select name="mentor_id" onchange="this.form.submit()">
      <option value="">All Mentors</option>
      <?php foreach($all_mentors_list as $r): ?>
        <option value="<?= $r['id'] ?>" <?= $filter_mentor==$r['id']?'selected':'' ?>><?= h($r['full_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="venture_id" onchange="this.form.submit()">
      <option value="">All Ventures</option>
      <?php foreach($all_ventures_list as $r): ?>
        <option value="<?= $r['id'] ?>" <?= $filter_venture==$r['id']?'selected':'' ?>><?= h($r['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <?php if ($view==='list'): ?>
      <select name="per_page" class="per-page-select" onchange="this.form.submit()">
        <?php foreach($allowed_per_pages as $pp): ?>
          <option value="<?= $pp ?>" <?= $per_page===$pp?'selected':'' ?>><?= $pp ?> / page</option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>

    <?php if ($view==='calendar'): ?>
      <input type="month" name="month" value="<?= h($filter_month) ?>" onchange="this.form.submit()">
    <?php endif; ?>

    <input type="hidden" name="view" value="<?= h($view) ?>">
    <input type="hidden" name="page" value="1">

    <div class="view-toggle">
      <a href="<?= h(ms_page_url(['view'=>'list','page'=>1])) ?>"     class="view-btn <?= $view==='list'?'active':'' ?>"><i class="fa fa-list"></i> List</a>
      <a href="<?= h(ms_page_url(['view'=>'calendar','page'=>1])) ?>" class="view-btn <?= $view==='calendar'?'active':'' ?>"><i class="fa fa-calendar"></i> Calendar</a>
    </div>

    <div class="export-toggle">
      <a href="<?= h(ms_page_url(['export'=>'csv'])) ?>" class="view-btn" title="Export current results to CSV"><i class="fa fa-file-csv"></i> CSV</a>
      <a href="<?= h(ms_page_url(['export'=>'pdf'])) ?>" class="view-btn" title="Export current results to PDF"><i class="fa fa-file-pdf"></i> PDF</a>
    </div>

    <?php if ($search || $filter_status || $filter_mentor || $filter_venture || $filter_cohort): ?>
      <a href="mentor-sessions.php?view=<?= h($view) ?>" class="btn btn-sm" style="color:var(--danger,#ef4444)"><i class="fa fa-times"></i> Clear</a>
    <?php endif; ?>
  </form>
</div>

<?php
/* -- CALENDAR VIEW ------------------------------------------- */
if ($view === 'calendar'):
    $month_start_cal    = date('Y-m-01', strtotime($filter_month.'-01'));
    $cal_first_dow_v    = (int)date('w', strtotime($month_start_cal));
    $cal_days_in_month_v= (int)date('t', strtotime($month_start_cal));
    $prev_m = date('Y-m', strtotime('-1 month', strtotime($month_start_cal)));
    $next_m = date('Y-m', strtotime('+1 month', strtotime($month_start_cal)));
    $cal_sess = [];
    foreach ($all_sessions_arr as $s) {
        if (empty($s['scheduled_at'])) continue;
        $cal_sess[date('Y-m-d',strtotime($s['scheduled_at']))][] = $s;
    }
?>
<div class="card legacy-style-8e11ed66d1">
  <div class="legacy-style-fb0da81244">
    <a href="?<?= http_build_query(array_merge($_GET,['month'=>$prev_m])) ?>" class="btn btn-secondary btn-sm"><i class="fa fa-chevron-left"></i></a>
    <strong class="legacy-style-1444c6eaca"><?= date('F Y',strtotime($month_start_cal)) ?></strong>
    <a href="?<?= http_build_query(array_merge($_GET,['month'=>$next_m])) ?>" class="btn btn-secondary btn-sm"><i class="fa fa-chevron-right"></i></a>
  </div>
  <div class="cal-header">
    <?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?><div class="cal-hday"><?= $d ?></div><?php endforeach; ?>
  </div>
  <div class="cal-grid">
    <?php
    for ($b=0;$b<$cal_first_dow_v;$b++) echo '<div class="cal-cell other-month"></div>';
    for ($day=1;$day<=$cal_days_in_month_v;$day++):
      $dk = date('Y-m',strtotime($month_start_cal)).'-'.str_pad($day,2,'0',STR_PAD_LEFT);
      $is_today = ($dk===date('Y-m-d'));
    ?>
    <div class="cal-cell <?= $is_today?'today':'' ?>">
      <div class="cal-day-num"><?= $day ?></div>
      <?php foreach($cal_sess[$dk]??[] as $s):
        $venture_label = $s['venture_count'] > 1
            ? $s['venture_name'].' +'.($s['venture_count']-1)
            : $s['venture_name'];
        $mentor_label = $s['mentor_count'] > 1
            ? $s['mentor_name'].' +'.($s['mentor_count']-1)
            : $s['mentor_name'];
      ?>
        <span class="cal-event <?= $s['status'] ?>"
              title="<?= h($s['title']) ?> - <?= h($s['mentor_names_joined']) ?>"
              onclick="openDetailModal(<?= (int)$s['id'] ?>)">
          <?= date('g:ia',strtotime($s['scheduled_at'])) ?> <?= h(mb_strimwidth($venture_label,0,10,'...')) ?>
        </span>
      <?php endforeach; ?>
    </div>
    <?php endfor;
    $trailing=(7-(($cal_first_dow_v+$cal_days_in_month_v)%7))%7;
    for($t=0;$t<$trailing;$t++) echo '<div class="cal-cell other-month"></div>'; ?>
  </div>
</div>

<?php else: /* -- LIST VIEW ----------------------------------- */ ?>
<div class="card">
<div class="table-wrap">
<table>
<thead>
<tr>
  <th>Session</th><th>Mentor(s)</th><th>Venture(s)</th><th>Date &amp; Time</th>
  <th>Updated At</th>
  <th>Type</th><th>Status</th><th>Ratings</th><th>Actions</th>
</tr>
</thead>
<tbody>
<?php $has=false; foreach ($all_sessions_arr as $s): $has=true;
  [$sl,$sb]=$status_map[$s['status']]??[$s['status'],'badge-gray'];
  if (($s['status'] ?? '') === 'cancelled' && ($s['cancelled_by'] ?? '') === 'mentor') { $sl = 'Rejected'; }
  $cmtCount=count($comments_by_session[(int)$s['id']]??[]);
  $repCount=$IS_MENTOR ? 0 : count($session_reports[(int)$s['id']]??[]);
  $fbRow = !$IS_MENTOR ? ($feedback_reports[(int)$s['id']] ?? null) : null;
?>
<tr>
  <td>
    <strong><?= h($s['title']) ?></strong><br>
    <small class="legacy-style-5872de20d5"><?= (int)$s['duration_minutes'] ?>min &middot; <?= h($platform_map[$s['meeting_platform']]??$s['meeting_platform']) ?></small>
    <?php if(!empty($s['meeting_link'])): ?><br><a href="<?= h($s['meeting_link']) ?>" target="_blank" style="font-size:11px"><i class="fa fa-video"></i> Join</a><?php endif; ?>
    <?php if($cmtCount>0): ?><br><span class="legacy-style-564550df91"><i class="fa fa-comment"></i> <?= $cmtCount ?></span><?php endif; ?>
    <?php if($repCount>0): ?><span class="legacy-style-d76c0866e4"><i class="fa fa-file-alt"></i> <?= $repCount ?></span><?php endif; ?>
    <?php if($fbRow): [$fbl,$fbb] = $fb_status_labels[$fbRow['status']] ?? [$fbRow['status'],'badge-gray']; ?>
      <br><span class="badge <?= $fbb ?>" style="font-size:10px;padding:2px 7px;margin-top:3px;display:inline-block"><i class="fa fa-clipboard-list"></i> Feedback: <?= h($fbl) ?></span>
    <?php endif; ?>
  </td>
  <td>
    <div class="stack-people">
      <?php foreach (array_slice($s['mentors_all'],0,3) as $m): ?>
        <div class="s-initials" title="<?= h($m['full_name']) ?><?= $m['role']==='co_mentor' ? ' ('.h($m['invite_status']).')' : ' (organizer)' ?>"><?= strtoupper(substr($m['full_name'],0,2)) ?></div>
      <?php endforeach; ?>
      <?php if ($s['mentor_count'] > 3): ?><span class="more-count">+<?= $s['mentor_count']-3 ?></span><?php endif; ?>
      <?php if ($s['mentor_count'] === 0): ?><span class="legacy-style-2bb5163fc3">No mentor</span><?php endif; ?>
    </div>
    <div class="legacy-style-96ad6099e2">
      <strong class="legacy-style-5e0faad207"><?= h($s['mentor_name']) ?></strong>
      <?php if ($s['mentor_count'] > 1): ?><span class="legacy-style-7491483f4d"> +<?= $s['mentor_count']-1 ?> more</span><?php endif; ?>
      <?php if ($s['mentor_org']): ?><br><small class="legacy-style-5872de20d5"><?= h($s['mentor_org']) ?></small><?php endif; ?>
      <?php if (!empty($s['organizer_responded_at'])): ?><br><small class="legacy-style-5872de20d5">Responded <?= h(date('j M Y, g:ia', strtotime($s['organizer_responded_at']))) ?></small><?php endif; ?>
    </div>
  </td>
  <td>
    <strong class="legacy-style-5e0faad207"><?= h($s['venture_name']) ?></strong>
    <?php if ($s['venture_count'] > 1): ?><span class="legacy-style-7491483f4d"> +<?= $s['venture_count']-1 ?> more</span><?php endif; ?>
    <?php if($s['venture_count'] === 0): ?><span class="legacy-style-2bb5163fc3">No venture</span><?php endif; ?>
    <?php if($s['cohort_name']): ?><br><small class="legacy-style-5872de20d5"><?= h($s['cohort_name']) ?></small><?php endif; ?>
    <?php if (!empty($s['first_venture_requested_at'])): ?><br><small class="legacy-style-5872de20d5">Requested <?= h(date('j M Y, g:ia', strtotime($s['first_venture_requested_at']))) ?></small><?php endif; ?>
  </td>
  <td class="legacy-style-0ea2eb29e2">
    <?= !empty($s['scheduled_at'])?date('M j, Y',strtotime($s['scheduled_at'])):'TBD' ?><br>
    <?= !empty($s['scheduled_at'])?date('g:i A',strtotime($s['scheduled_at'])):'' ?>
  </td>
  <td class="legacy-style-0ea2eb29e2">
    <?php if (!empty($s['organizer_updated_at'])): ?>
      <?= h(date('M j, Y',strtotime($s['organizer_updated_at']))) ?><br>
      <?= h(date('g:i A',strtotime($s['organizer_updated_at']))) ?>
    <?php else: ?>
      <span class="legacy-style-5872de20d5">-</span>
    <?php endif; ?>
  </td>
  <td><span class="badge badge-info"><?= h($type_map[$s['session_type']]??$s['session_type']) ?></span></td>
  <td>
    <span class="badge <?= $sb ?>"><?= h($sl) ?></span>
    <?php if(($s['status'] ?? '') === 'cancelled' && !empty($s['cancel_reason'])): ?>
      <br><small style="display:inline-block;margin-top:5px;color:var(--muted,#6b7280);max-width:220px">
        <strong><?= (($s['cancelled_by'] ?? '') === 'mentor') ? 'Rejection reason:' : 'Reason:' ?></strong> <?= h($s['cancel_reason']) ?>
      </small>
    <?php endif; ?>
    <?php if($s['status']==='requested' && ($CAN_MANAGE || $IS_MENTOR)): ?>
      <div class="legacy-style-18c18b3af0" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:7px">
        <form method="POST" action="includes/process-mentor-sessions.php" class="legacy-style-cccfa4560d">
          <input type="hidden" name="action" value="update_status">
          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <input type="hidden" name="status" value="confirmed">
          <button class="btn btn-sm btn-success legacy-style-e053a92b18" type="submit">
            <i class="fa fa-check"></i> Confirm
          </button>
        </form>

        <?php if($IS_MENTOR && !$CAN_MANAGE): ?>
          <button type="button" class="btn btn-sm btn-danger legacy-style-e053a92b18"
                  onclick="openRejectSessionModal(<?= (int)$s['id'] ?>, '<?= h(addslashes((string)$s['title'])) ?>')">
            <i class="fa fa-times"></i> Reject
          </button>
        <?php else: ?>
          <form method="POST" action="includes/process-mentor-sessions.php" class="legacy-style-cccfa4560d">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <input type="hidden" name="status" value="cancelled">
            <input type="hidden" name="rejection_reason" value="Cancelled by programme team">
            <button class="btn btn-sm btn-danger legacy-style-e053a92b18" type="submit" title="Cancel session">
              <i class="fa fa-times"></i> Cancel
            </button>
          </form>
        <?php endif; ?>
      </div>
    <?php elseif($CAN_MANAGE && in_array($s['status'],['confirmed','scheduled'])): ?>
      <div class="legacy-style-96ad6099e2">
        <form method="POST" action="includes/process-mentor-sessions.php" class="legacy-style-cccfa4560d">
          <input type="hidden" name="action" value="update_status">
          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <input type="hidden" name="status" value="completed">
          <button class="btn btn-sm btn-success legacy-style-e053a92b18" type="submit"><i class="fa fa-check-double"></i> Mark Done</button>
        </form>
      </div>
    <?php endif; ?>
  </td>
  <td>
    <?php if($s['venture_rating']): ?>
      <div class="star-disp" title="Venture rating">
        <?php for($i=1;$i<=5;$i++) echo '<i class="fa fa-star'.($i<=$s['venture_rating']?'':' empty').'"></i>'; ?>
        <small class="legacy-style-0b6514641b">V</small>
      </div>
    <?php endif; ?>
    <?php if(!empty($s['mentor_rating'])): ?>
      <div class="star-disp" title="Mentor rating of venture">
        <?php for($i=1;$i<=5;$i++) echo '<i class="fa fa-star'.($i<=$s['mentor_rating']?'':' empty').'"></i>'; ?>
        <small class="legacy-style-0b6514641b">M</small>
      </div>
    <?php elseif($IS_MENTOR && $s['status']==='completed'): ?>
      <button class="btn btn-sm legacy-style-57f7fda4e5"
              onclick="openRateVentureModal(<?= (int)$s['id'] ?>,'<?= h(addslashes($s['venture_name'])) ?>')">
        <i class="fa fa-star"></i> Rate
      </button>
    <?php else: ?><span class="legacy-style-5694e69345">-</span><?php endif; ?>
  </td>
  <td>
    <div class="tbl-actions">
      <button class="btn btn-sm btn-secondary" title="View details" onclick="openDetailModal(<?= (int)$s['id'] ?>)"><i class="fa fa-eye"></i></button>
      <?php if($IS_MENTOR && $s['status']==='completed'): ?>
        <a class="btn btn-sm btn-secondary" title="Create session report" href="mentor-reports.php?tab=session_report&session_id=<?= (int)$s['id'] ?>"><i class="fa fa-file-signature"></i></a>
      <?php endif; ?>
      <?php if ($CAN_MANAGE): ?>
        <button class="btn btn-sm btn-secondary" title="Edit" onclick='openSessionModal(<?= json_encode($s,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'><i class="fa fa-edit"></i></button>
        <?php if(in_array($s['status'],['confirmed','scheduled'])): ?>
          <form method="POST" action="includes/process-mentor-sessions.php" class="legacy-style-cccfa4560d">
            <input type="hidden" name="action" value="send_reminder"><input type="hidden" name="id" value="<?= $s['id'] ?>">
            <button class="btn btn-sm btn-secondary" title="Send reminder"><i class="fa fa-bell"></i></button>
          </form>
        <?php endif; ?>
        <form method="POST" action="includes/process-mentor-sessions.php" onsubmit="return confirm('Delete this session permanently?')" class="legacy-style-cccfa4560d">
          <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $s['id'] ?>">
          <button class="btn btn-sm btn-danger"><i class="fa fa-trash"></i></button>
        </form>
      <?php endif; ?>
    </div>
  </td>
</tr>
<?php endforeach; ?>
<?php if(!$has): ?>
<tr><td colspan="9"><div class="empty-state"><h3>No sessions found</h3>
  <p><?= $IS_MENTOR?'You have no sessions yet. Schedule one or ask your programme manager to set one up for you.':'Schedule a session or adjust your filters.' ?></p>
  <?php if($CAN_MANAGE || $IS_MENTOR): ?><button class="btn btn-primary" onclick="openSessionModal()"> Schedule Session</button><?php endif; ?>
</div></td></tr>
<?php endif; ?>
</tbody>
</table>
</div>

<?php if ($view === 'list'): ?>
<div class="pagination-wrap">
  <div class="pagination-info">
    Showing <?= number_format($showing_from) ?> - <?= number_format($showing_to) ?> of <?= number_format($total_records) ?> sessions
    <?php if ($search !== ''): ?>
      for "<strong><?= h($search) ?></strong>"
    <?php endif; ?>
  </div>

  <?php if ($total_pages > 1): ?>
  <div class="pagination">
    <a class="page-link <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= h(ms_page_url(['page'=>1])) ?>" title="First">&laquo;</a>
    <a class="page-link <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= h(ms_page_url(['page'=>max(1,$page-1)])) ?>" title="Previous">&lsaquo;</a>

    <?php
      $start_page = max(1, $page - 2);
      $end_page   = min($total_pages, $page + 2);

      if ($start_page > 1) {
          echo '<span class="page-link disabled">...</span>';
      }

      for ($pg = $start_page; $pg <= $end_page; $pg++):
    ?>
      <a class="page-link <?= $pg === $page ? 'active' : '' ?>" href="<?= h(ms_page_url(['page'=>$pg])) ?>"><?= $pg ?></a>
    <?php endfor;

      if ($end_page < $total_pages) {
          echo '<span class="page-link disabled">...</span>';
      }
    ?>

    <a class="page-link <?= $page >= $total_pages ? 'disabled' : '' ?>" href="<?= h(ms_page_url(['page'=>min($total_pages,$page+1)])) ?>" title="Next">&rsaquo;</a>
    <a class="page-link <?= $page >= $total_pages ? 'disabled' : '' ?>" href="<?= h(ms_page_url(['page'=>$total_pages])) ?>" title="Last">&raquo;</a>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

</div>
<?php endif; ?>
</div></div>


<!-- MODAL: Detail + Attendees + Comments + Reports ------------ -->
<div class="modal-overlay" id="detailModal">
<div class="modal modal-lg">
  <div class="modal-header">
    <h2 class="modal-title" id="dm-title">Session</h2>
    <button class="modal-close" onclick="closeM('detailModal')" type="button">&times;</button>
  </div>
  <div class="modal-body">
    <div class="detail-section" id="dm-info"></div>

    <!-- Mentor attendees -->
    <div class="detail-section">
      <div class="detail-section-title"><i class="fa fa-user-tie"></i> Mentors</div>
      <div id="dm-mentor-chips"></div>
      <?php if ($CAN_MANAGE || $IS_MENTOR): ?>
      <div class="legacy-style-8a77e5a311">
        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAddMentorBox()"><i class="fa fa-user-plus"></i> Invite another mentor</button>
        <div id="dm-add-mentor-box" class="legacy-style-9714b43b47">
          <form method="POST" action="includes/process-mentor-sessions.php" class="legacy-style-49cd09213e">
            <input type="hidden" name="action" value="invite_mentor">
            <input type="hidden" name="session_id" id="dm-invite-session-id" value="">
            <select name="mentor_id" class="form-control legacy-style-5a95af4fdc" required>
              <option value="">Select mentor to invite</option>
              <?php foreach($all_mentors_list as $r): ?>
                <option value="<?= $r['id'] ?>"><?= h($r['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-primary btn-sm"><i class="fa fa-paper-plane"></i></button>
          </form>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Venture attendees -->
    <div class="detail-section">
      <div class="detail-section-title"><i class="fa fa-rocket"></i> Ventures</div>
      <div id="dm-venture-chips"></div>
      <?php if ($CAN_MANAGE): ?>
      <div class="legacy-style-8a77e5a311">
        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAddVentureBox()"><i class="fa fa-plus"></i> Add another venture</button>
        <div id="dm-add-venture-box" class="legacy-style-9714b43b47">
          <form method="POST" action="includes/process-mentor-sessions.php" class="legacy-style-49cd09213e">
            <input type="hidden" name="action" value="add_venture">
            <input type="hidden" name="session_id" id="dm-addv-session-id" value="">
            <select name="venture_id" class="form-control legacy-style-5a95af4fdc" required>
              <option value="">Select venture to add</option>
              <?php foreach($all_ventures_list as $r): ?>
                <option value="<?= $r['id'] ?>"><?= h($r['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-primary btn-sm"><i class="fa fa-plus"></i></button>
          </form>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <div class="detail-section" id="dm-ratings">
      <div class="detail-section-title"><i class="fa fa-star legacy-style-6a6a237e2c"></i> Ratings</div>
      <div id="dm-ratings-body"></div>
    </div>
    <?php if(!$IS_MENTOR): ?>
    <div class="detail-section" id="dm-reports-section">
      <div class="detail-section-title"><i class="fa fa-file-alt"></i> Session reports</div>
      <div id="dm-reports-list"></div>
    </div>

    <div class="detail-section" id="dm-feedback-section">
      <div class="detail-section-title"><i class="fa fa-clipboard-list"></i> Venture Mentorship Session Report</div>
      <div id="dm-feedback-body"></div>
    </div>
    <?php endif; ?>

    <div class="detail-section">
      <div class="detail-section-title"><i class="fa fa-comments"></i> Comments &amp; notes</div>
      <div class="comment-thread" id="dm-comments"></div>
      <form method="POST" action="includes/process-session-comments.php" class="comment-compose">
        <input type="hidden" name="action" value="add_comment">
        <input type="hidden" name="session_id" id="dm-cmt-sid" value="">
        <input type="hidden" name="author_type" value="<?= $IS_MENTOR ? 'mentor' : 'admin' ?>">
        <input type="hidden" name="author_name" value="<?= h($ADMIN['full_name']??'') ?>">
        <textarea name="comment_text" placeholder="Add a note..." rows="2"></textarea>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-paper-plane"></i></button>
      </form>
    </div>
  </div>
  <div class="modal-footer" id="dm-footer"></div>
</div>
</div>

<!-- MODAL: Reject requested session (mentor only) ------------ -->
<?php if($IS_MENTOR && !$CAN_MANAGE): ?>
<div class="modal-overlay" id="rejectSessionModal">
<div class="modal legacy-style-5521062821">
  <div class="modal-header">
    <h2 class="modal-title">Reject Session Request</h2>
    <button class="modal-close" onclick="closeM('rejectSessionModal')" type="button">&times;</button>
  </div>
  <form method="POST" action="includes/process-mentor-sessions.php">
    <input type="hidden" name="action" value="update_status">
    <input type="hidden" name="id" id="reject-session-id" value="">
    <input type="hidden" name="status" value="cancelled">
    <div class="modal-body">
      <p id="reject-session-title" style="font-weight:700;margin-bottom:12px"></p>
      <div class="form-group">
        <label>Reason for rejection <span class="req">*</span></label>
        <textarea name="rejection_reason" id="reject-session-reason" class="form-control" rows="4" required maxlength="1000"
                  placeholder="Explain why you cannot accept this mentorship session request..."></textarea>
        <small style="color:var(--muted,#6b7280)">The reason will be recorded and shared with the programme team/venture.</small>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeM('rejectSessionModal')">Keep Request</button>
      <button type="submit" class="btn btn-danger"><i class="fa fa-times"></i> Reject Session</button>
    </div>
  </form>
</div>
</div>
<?php endif; ?>

<!-- MODAL: Rate venture (mentor only) ------------------------- -->
<?php if($IS_MENTOR): ?>
<div class="modal-overlay" id="rateVentureModal">
<div class="modal legacy-style-5521062821">
  <div class="modal-header"><h2 class="modal-title">Rate this venture</h2><button class="modal-close" onclick="closeM('rateVentureModal')" type="button">&times;</button></div>
  <form method="POST" action="includes/process-mentor-sessions.php">
    <input type="hidden" name="action" value="mentor_rate_venture">
    <input type="hidden" name="id" id="rv-sid" value="">
    <div class="modal-body">
      <p id="rv-vname" class="legacy-style-3622eb3abd"></p>
      <div class="form-group">
        <label class="legacy-style-04b999c603">Your rating of this venture</label>
        <div class="star-picker-row" id="rv-stars" onclick="rvPickStar(event)">
          <i class="far fa-star" data-v="1"></i><i class="far fa-star" data-v="2"></i><i class="far fa-star" data-v="3"></i><i class="far fa-star" data-v="4"></i><i class="far fa-star" data-v="5"></i>
        </div>
        <input type="hidden" name="mentor_rating" id="rv-val" value="0">
      </div>
      <div class="form-group">
        <label class="legacy-style-04b999c603">Feedback (optional)</label>
        <textarea name="mentor_feedback" class="form-control" rows="3" placeholder="Observations about this venture's progress, engagement..."></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeM('rateVentureModal')">Cancel</button>
      <button type="submit" class="btn btn-primary" id="rv-submit" disabled><i class="fa fa-star"></i> Save rating</button>
    </div>
  </form>
</div>
</div>

<?php endif; ?>

<!-- MODAL: Schedule/edit -- multi-mentor + multi-venture --------- -->
<?php if($CAN_MANAGE || $IS_MENTOR): ?>
<div class="modal-overlay" id="sessionModal">
<div class="modal modal-lg">
  <div class="modal-header"><h2 class="modal-title" id="sessionModalTitle">Schedule Session</h2><button type="button" class="modal-close" onclick="closeM('sessionModal')">&times;</button></div>
  <form method="POST" action="includes/process-mentor-sessions.php" id="session-form">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" id="s_id">
    <input type="hidden" name="all_ventures" id="s-all-ventures-flag" value="0">
    <div class="modal-body"><div class="form-grid form-grid-2">
      <div class="form-group full"><label>Session Title <span class="req">*</span></label><input type="text" name="title" id="s_title" class="form-control" required></div>

      <?php if ($CAN_MANAGE): ?>
      <div class="form-group full">
        <label>Organizing mentor <span class="req">*</span></label>
        <select name="mentor_id" id="s_mentor_id" class="form-control" required>
          <option value="">Select Mentor</option>
          <?php foreach($all_mentors_list as $r): ?><option value="<?= $r['id'] ?>"><?= h($r['full_name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php else: ?>
        <input type="hidden" name="mentor_id" value="<?= $MENTOR_RECORD_ID ?>">
      <?php endif; ?>

      <!-- Co-mentor invite picker -->
      <div class="form-group full">
        <label>Invite co-mentors <span class="legacy-style-64865bc1f0">(optional - they'll need to accept)</span></label>
        <div class="ms-picker" id="s-mentor-picker">
          <?php foreach($all_mentors_list as $r): ?>
            <label class="ms-picker-row">
              <input type="checkbox" class="s-co-mentor-cb" value="<?= $r['id'] ?>" data-name="<?= h($r['full_name']) ?>" onchange="renderCoMentorTags()">
              <?= h($r['full_name']) ?>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="ms-tag-list" id="s-co-mentor-tags"></div>
        <div id="s-co-mentor-hidden-inputs"></div>
      </div>

      <!-- Venture picker -->
      <div class="form-group full">
        <label>Ventures <span class="req">*</span></label>
        <div class="all-ventures-toggle">
          <input type="checkbox" id="s-all-ventures-cb" onchange="toggleAllVentures()"> All ventures in the programme
        </div>
        <div class="ms-picker" id="s-venture-picker">
          <?php foreach($all_ventures_list as $r): ?>
            <label class="ms-picker-row">
              <input type="checkbox" class="s-venture-cb" value="<?= $r['id'] ?>" data-name="<?= h($r['name']) ?>" onchange="renderVentureTags()">
              <?= h($r['name']) ?>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="ms-tag-list" id="s-venture-tags"></div>
        <div id="s-venture-hidden-inputs"></div>
      </div>

      <div class="form-group"><label>Date &amp; Time</label><input type="datetime-local" name="scheduled_at" id="s_scheduled_at" class="form-control"></div>
      <div class="form-group"><label>Duration (minutes)</label><input type="number" name="duration_minutes" id="s_duration" class="form-control" value="60" min="15" step="15"></div>
      <div class="form-group"><label>Session Type</label>
        <select name="session_type" id="s_type" class="form-control">
          <?php foreach($type_map as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php if ($CAN_MANAGE): ?>
      <div class="form-group"><label>Status</label>
        <select name="status" id="s_status" class="form-control">
          <?php foreach($status_map as $k=>[$l,$b]): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php else: ?>
        <input type="hidden" name="status" id="s_status" value="requested">
      <?php endif; ?>
      <div class="form-group"><label>Platform</label>
        <select name="meeting_platform" id="s_platform" class="form-control">
          <?php foreach($platform_map as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Meeting Link</label><input type="text" name="meeting_link" id="s_link" class="form-control" placeholder="https://zoom.us/..."></div>
      <div class="form-group full"><label>Description / Agenda</label><textarea name="description" id="s_description" class="form-control" rows="3"></textarea></div>
      <?php if ($CAN_MANAGE): ?>
      <div class="form-group full"><label>Shared Notes (visible to venture)</label><textarea name="notes_shared" id="s_notes_shared" class="form-control" rows="3"></textarea></div>
      <div class="form-group full"><label>Action Items</label><textarea name="action_items" id="s_action_items" class="form-control" rows="2" placeholder="- Item 1&#10;- Item 2"></textarea></div>
      <?php endif; ?>
      <div class="form-group"><label class="legacy-style-1303f3b328"><input type="checkbox" name="send_reminder" value="1" checked> Send reminder emails</label></div>
    </div></div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeM('sessionModal')">Cancel</button>
      <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Session</button>
    </div>
  </form>
</div>
</div>
<?php endif; ?>

<script>
const SESSION_DATA  = <?= json_encode(array_values(array_map(function($s) use ($status_map,$platform_map,$type_map){
    [$sl,$sb] = $status_map[$s['status']] ?? [$s['status'],'badge-gray'];
    return ['id'=>(int)$s['id'],'title'=>$s['title']??'',
            'mentor_name'=>$s['mentor_name']??'','mentor_org'=>$s['mentor_org']??'',
            'mentor_names_joined'=>$s['mentor_names_joined']??'','mentor_count'=>(int)$s['mentor_count'],
            'venture_name'=>$s['venture_name']??'','venture_names_joined'=>$s['venture_names_joined']??'',
            'venture_count'=>(int)$s['venture_count'],'cohort_name'=>$s['cohort_name']??'',
            'status'=>$s['status'],'status_label'=>$sl,'status_badge'=>$sb,
            'scheduled_at'=>$s['scheduled_at']??'','duration'=>(int)$s['duration_minutes'],
            'session_type'=>$type_map[$s['session_type']??'']??($s['session_type']??''),
            'platform'=>$platform_map[$s['meeting_platform']??'']??($s['meeting_platform']??''),
            'meeting_link'=>$s['meeting_link']??'','description'=>$s['description']??'',
            'notes_shared'=>$s['notes_shared']??'','action_items'=>$s['action_items']??'',
            'venture_rating'=>(int)($s['venture_rating']??0),'venture_feedback'=>$s['venture_feedback']??'',
            'mentor_rating'=>(int)($s['mentor_rating']??0),'mentor_feedback'=>$s['mentor_feedback']??'',
            'admin_rating'=>(int)($s['admin_rating']??0),'admin_notes'=>$s['admin_notes']??'',
            'meeting_platform'=>$s['meeting_platform']??'','session_type_raw'=>$s['session_type']??'',
            'duration_minutes'=>(int)$s['duration_minutes'],
            'organizer_responded_at'=>$s['organizer_responded_at']??null,
            'organizer_updated_at'=>$s['organizer_updated_at']??null,
            'first_venture_requested_at'=>$s['first_venture_requested_at']??null,
            'cancel_reason'=>$s['cancel_reason']??'',
            'cancelled_by'=>$s['cancelled_by']??''];
}, $all_sessions_arr)), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;

/* Per-session attendee lists (for chips + pre-populating the edit modal).
   Now includes session_mentors.responded_at / .updated_at and
   session_ventures.created_at (as requested_at). */
const MENTORS_BY_SESSION  = <?= json_encode(
    array_map(fn($rows) => array_map(fn($r) => [
        'mentor_id'     => (int)$r['mentor_id'],
        'full_name'     => $r['full_name'],
        'role'          => $r['role'],
        'invite_status' => $r['invite_status'],
        'responded_at'  => $r['responded_at'] ?? null,
        'updated_at'    => $r['updated_at'] ?? null,
    ], $rows), $mentors_by_session),
    JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT
) ?>;
const VENTURES_BY_SESSION = <?= json_encode(
    array_map(fn($rows) => array_map(fn($r) => [
        'venture_id'   => (int)$r['venture_id'],
        'name'         => $r['name'],
        'requested_at' => $r['requested_at'] ?? null,
    ], $rows), $ventures_by_session),
    JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT
) ?>;

const COMMENTS_DATA = <?= json_encode($comments_by_session, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const REPORTS_DATA  = <?= json_encode($IS_MENTOR ? [] : $session_reports, JSON_HEX_TAG|JSON_HEX_APOS) ?>;

/* Venture Mentorship Feedback Reports, keyed by session_id. Repeatable
   sections (discussion_summary, mentor_assessment, action_plan,
   challenges) are pre-decoded from JSON into arrays here so the JS
   renderer never has to touch JSON.parse on possibly-null strings. */
const FEEDBACK_DATA = <?= json_encode(array_map(function($r) {
    return [
        'status'                => $r['status'],
        'venture_name'          => $r['venture_name'] ?? '',
        'founders_present'      => $r['founders_present'] ?? '',
        'mentor_name'           => $r['mentor_name'] ?? '',
        'session_date'          => $r['session_date'] ?? '',
        'session_duration'      => $r['session_duration'] ?? '',
        'mode_of_engagement'    => $r['mode_of_engagement'] ?? '',
        'objectives'            => $r['objectives'] ?? '',
        'discussion_summary'    => fb_json_rows($r['discussion_summary'] ?? null),
        'mentor_assessment'     => fb_json_rows($r['mentor_assessment'] ?? null),
        'value_rating'          => $r['value_rating'] ?? '',
        'valuable_insight'      => $r['valuable_insight'] ?? '',
        'recommendations'       => $r['recommendations'] ?? '',
        'action_plan'           => fb_json_rows($r['action_plan'] ?? null),
        'progress_rating'       => $r['progress_rating'] ?? '',
        'progress_explain'      => $r['progress_explain'] ?? '',
        'challenges'            => fb_json_rows($r['challenges'] ?? null),
        'future_support'        => $r['future_support'] ?? '',
        'satisfaction_rating'   => $r['satisfaction_rating'] !== null ? (int)$r['satisfaction_rating'] : null,
        'satisfaction_comments' => $r['satisfaction_comments'] ?? '',
        'recommend_mentor'      => $r['recommend_mentor'] ?? '',
        'feedback_for_admin'    => $r['feedback_for_admin'] ?? '',
        'mentor_could_improve'  => $r['mentor_could_improve'] ?? '',
        'submitted_at'          => $r['submitted_at'] ?? null,
        'reviewed_at'           => $r['reviewed_at'] ?? null,
        'reviewed_by'           => $r['reviewed_by'] ?? '',
        'review_notes'          => $r['review_notes'] ?? '',
    ];
}, $IS_MENTOR ? [] : $feedback_reports), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;

const FB_MODE_LABELS       = <?= json_encode($fb_mode_labels, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const FB_VALUE_LABELS      = <?= json_encode($fb_value_labels, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const FB_PROGRESS_LABELS   = <?= json_encode($fb_progress_labels, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const FB_RECOMMEND_LABELS  = <?= json_encode($fb_recommend_labels, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const FB_CONFIDENCE_LABELS = <?= json_encode($fb_confidence_labels, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const FB_URGENCY_LABELS    = <?= json_encode($fb_urgency_labels, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const FB_STATUS_LABELS     = <?= json_encode($fb_status_labels, JSON_HEX_TAG|JSON_HEX_APOS) ?>;

const IS_MENTOR     = <?= $IS_MENTOR?'true':'false' ?>;
const CAN_MANAGE    = <?= $CAN_MANAGE?'true':'false' ?>;
</script>
<script>
document.getElementById('sidebarToggle')?.addEventListener('click',()=>{
    document.getElementById('adminSidebar')?.classList.toggle('collapsed');
    document.getElementById('adminMain')?.classList.toggle('collapsed');
});
function closeM(id){document.getElementById(id)?.classList.remove('open');}
function openM(id) {document.getElementById(id)?.classList.add('open');}
document.querySelectorAll('.modal-overlay').forEach(m=>{m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open');});});

/* ---------------------------------------------------------------
   Multi-select mentor / venture pickers (schedule/edit modal)
--------------------------------------------------------------- */
function renderCoMentorTags(){
    const checked=[...document.querySelectorAll('.s-co-mentor-cb:checked')];
    const tagWrap=document.getElementById('s-co-mentor-tags');
    const hiddenWrap=document.getElementById('s-co-mentor-hidden-inputs');
    tagWrap.innerHTML=checked.map(cb=>`
        <span class="ms-tag">${esc(cb.dataset.name)}
          <button type="button" onclick="uncheckCoMentor('${cb.value}')"><i class="fa fa-times"></i></button>
        </span>`).join('');
    hiddenWrap.innerHTML=checked.map(cb=>`<input type="hidden" name="co_mentor_ids[]" value="${cb.value}">`).join('');
}
function uncheckCoMentor(id){
    const cb=document.querySelector(`.s-co-mentor-cb[value="${id}"]`);
    if(cb){ cb.checked=false; renderCoMentorTags(); }
}
function renderVentureTags(){
    const checked=[...document.querySelectorAll('.s-venture-cb:checked')];
    const tagWrap=document.getElementById('s-venture-tags');
    const hiddenWrap=document.getElementById('s-venture-hidden-inputs');
    tagWrap.innerHTML=checked.map(cb=>`
        <span class="ms-tag">${esc(cb.dataset.name)}
          <button type="button" onclick="uncheckVenture('${cb.value}')"><i class="fa fa-times"></i></button>
        </span>`).join('');
    hiddenWrap.innerHTML=checked.map(cb=>`<input type="hidden" name="venture_id[]" value="${cb.value}">`).join('');
}
function uncheckVenture(id){
    const cb=document.querySelector(`.s-venture-cb[value="${id}"]`);
    if(cb){ cb.checked=false; renderVentureTags(); }
}
function toggleAllVentures(){
    const all=document.getElementById('s-all-ventures-cb').checked;
    document.getElementById('s-venture-picker').style.display=all?'none':'';
    document.getElementById('s-all-ventures-flag').value=all?'1':'0';
    if(all){
        document.querySelectorAll('.s-venture-cb').forEach(cb=>cb.checked=false);
        document.getElementById('s-venture-tags').innerHTML='<span class="ms-tag organizer-tag"><i class="fa fa-globe"></i> All ventures in the programme</span>';
    } else {
        document.getElementById('s-venture-tags').innerHTML='';
    }
}
function resetSessionPickers(){
    document.querySelectorAll('.s-co-mentor-cb').forEach(cb=>cb.checked=false);
    document.querySelectorAll('.s-venture-cb').forEach(cb=>cb.checked=false);
    const allCb=document.getElementById('s-all-ventures-cb');
    if(allCb) allCb.checked=false;
    const picker=document.getElementById('s-venture-picker');
    if(picker) picker.style.display='';
    document.getElementById('s-all-ventures-flag').value='0';
    renderCoMentorTags();
    renderVentureTags();
}

<?php if($CAN_MANAGE || $IS_MENTOR): ?>
function openSessionModal(d=null){
    document.getElementById('sessionModalTitle').textContent=d?'Edit Session':'Schedule Session';
    document.getElementById('sessionModal').querySelector('form').reset();
    resetSessionPickers();
    ['s_id','s_title','s_scheduled_at','s_link','s_description','s_notes_shared','s_action_items'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
    if(document.getElementById('s_mentor_id')) document.getElementById('s_mentor_id').value='';
    document.getElementById('s_duration').value=60;
    if(document.getElementById('s_status')) document.getElementById('s_status').value= d ? (d.status||'scheduled') : 'requested';

    if(d){
        document.getElementById('s_id').value=d.id||'';
        document.getElementById('s_title').value=d.title||'';
        document.getElementById('s_scheduled_at').value=(d.scheduled_at||'').replace(' ','T');
        document.getElementById('s_duration').value=d.duration_minutes||60;
        document.getElementById('s_type').value=d.session_type_raw||'one_on_one';
        if(document.getElementById('s_status')) document.getElementById('s_status').value=d.status||'scheduled';
        document.getElementById('s_platform').value=d.meeting_platform||'zoom';
        document.getElementById('s_link').value=d.meeting_link||'';
        document.getElementById('s_description').value=d.description||'';
        if(document.getElementById('s_notes_shared')) document.getElementById('s_notes_shared').value=d.notes_shared||'';
        if(document.getElementById('s_action_items')) document.getElementById('s_action_items').value=d.action_items||'';

        // Pre-populate organizer + co-mentor checkboxes + venture checkboxes
        // from the per-session attendee lists loaded above.
        const mentors  = MENTORS_BY_SESSION[d.id]  || [];
        const ventures = VENTURES_BY_SESSION[d.id] || [];
        const organizer = mentors.find(m=>m.role==='organizer');

        if(organizer && document.getElementById('s_mentor_id')) {
            document.getElementById('s_mentor_id').value=organizer.mentor_id;
        }
        mentors.filter(m=>m.role==='co_mentor').forEach(m=>{
            const cb=document.querySelector(`.s-co-mentor-cb[value="${m.mentor_id}"]`);
            if(cb) cb.checked=true;
        });
        ventures.forEach(v=>{
            const cb=document.querySelector(`.s-venture-cb[value="${v.venture_id}"]`);
            if(cb) cb.checked=true;
        });
        renderCoMentorTags();
        renderVentureTags();
    }
    openM('sessionModal');
}
<?php endif; ?>

<?php if($IS_MENTOR): ?>
function openRejectSessionModal(sid, title){
    const idEl=document.getElementById('reject-session-id');
    const titleEl=document.getElementById('reject-session-title');
    const reasonEl=document.getElementById('reject-session-reason');
    if(idEl) idEl.value=sid;
    if(titleEl) titleEl.textContent=title || 'Session request';
    if(reasonEl) reasonEl.value='';
    openM('rejectSessionModal');
}
function openRateVentureModal(sid,vname){
    document.getElementById('rv-sid').value=sid;
    document.getElementById('rv-vname').textContent=vname;
    document.getElementById('rv-val').value='0';
    document.getElementById('rv-submit').disabled=true;
    document.querySelectorAll('#rv-stars i').forEach(s=>s.className='far fa-star');
    openM('rateVentureModal');
}
function rvPickStar(e){
    const star=e.target.closest('i[data-v]'); if(!star) return;
    const val=parseInt(star.dataset.v,10);
    document.getElementById('rv-val').value=val;
    document.getElementById('rv-submit').disabled=false;
    document.querySelectorAll('#rv-stars i').forEach((s,i)=>{s.className=(i<val)?'fas fa-star lit':'far fa-star';});
}
<?php endif; ?>

function toggleAddMentorBox(){
    const box=document.getElementById('dm-add-mentor-box');
    if(box) box.style.display = box.style.display==='none' ? '' : 'none';
}
function toggleAddVentureBox(){
    const box=document.getElementById('dm-add-venture-box');
    if(box) box.style.display = box.style.display==='none' ? '' : 'none';
}

function fmtDateShort(str){
    if(!str) return '';
    const d = new Date(String(str).replace(' ','T'));
    if(isNaN(d.getTime())) return '';
    const datePart = d.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'});
    const timePart = d.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'});
    return `${datePart}, ${timePart}`;
}

/* ---------------------------------------------------------------
   Venture Mentorship Feedback Report -- render + review controls
--------------------------------------------------------------- */
function fbRowsTable(rows, columns){
    if(!rows || !rows.length){
        return '<p class="legacy-style-6bd7a31300">None recorded.</p>';
    }
    const head = columns.map(c=>`<th>${esc(c.label)}</th>`).join('');
    const body = rows.map(row=>{
        return '<tr>' + columns.map(c=>{
            let val = row[c.key] ?? '';
            if(c.labels && val) val = c.labels[val] || val;
            return `<td>${esc(val)}</td>`;
        }).join('') + '</tr>';
    }).join('');
    return `<table class="fb-table"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>`;
}

function renderFeedbackReport(sessionId){
    const body = document.getElementById('dm-feedback-body');
    const fb = FEEDBACK_DATA[sessionId];

    if(!fb){
        body.innerHTML = '<p class="fb-empty">No session report has been submitted by the venture for this session yet.</p>';
        return;
    }

    const statusInfo = FB_STATUS_LABELS[fb.status] || [fb.status, 'badge-gray'];
    let html = `<div class="fb-status-row" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span class="badge ${esc(statusInfo[1])}">${esc(statusInfo[0])}</span>
        ${fb.submitted_at ? `<span class="legacy-style-7491483f4d">Submitted ${fmtDateShort(fb.submitted_at)}</span>` : ''}
        ${(fb.status === 'submitted' || fb.status === 'reviewed')
            ? `<a class="btn btn-sm btn-secondary" href="mentor-sessions.php?feedback_pdf=1&session_id=${sessionId}" target="_blank" rel="noopener" style="margin-left:auto">
                    <i class="fa fa-file-pdf"></i> Download PDF
               </a>`
            : ''}
    </div>`;

    if(fb.status === 'draft'){
        html += '<p class="fb-empty">The venture has started this session report but has not submitted it for review yet.</p>';
        body.innerHTML = html;
        return;
    }

    html += `<div class="fb-sub">
        <div class="fb-sub-title">Session information</div>
        <div class="fb-grid">
            <div class="fb-kv"><span class="k">Venture</span><span class="v">${esc(fb.venture_name || '-')}</span></div>
            <div class="fb-kv"><span class="k">Founders / reps present</span><span class="v">${esc(fb.founders_present || '-')}</span></div>
            <div class="fb-kv"><span class="k">Mentor</span><span class="v">${esc(fb.mentor_name || '-')}</span></div>
            <div class="fb-kv"><span class="k">Session date</span><span class="v">${esc(fb.session_date || '-')}</span></div>
            <div class="fb-kv"><span class="k">Duration</span><span class="v">${esc(fb.session_duration || '-')}</span></div>
            <div class="fb-kv"><span class="k">Mode of engagement</span><span class="v">${esc(FB_MODE_LABELS[fb.mode_of_engagement] || fb.mode_of_engagement || '-')}</span></div>
        </div>
    </div>`;

    html += `<div class="fb-sub">
        <div class="fb-sub-title">Session objectives</div>
        <div class="fb-text-block">${esc(fb.objectives || 'Not provided.')}</div>
    </div>`;

    html += `<div class="fb-sub">
        <div class="fb-sub-title">Session discussion summary</div>
        ${fbRowsTable(fb.discussion_summary, [
            {key:'area', label:'Discussion area'},
            {key:'helpful', label:'How helpful (1-5)'},
            {key:'comments', label:'Comments'},
        ])}
    </div>`;

    html += `<div class="fb-sub">
        <div class="fb-sub-title">Mentor assessment</div>
        ${fbRowsTable(fb.mentor_assessment, [
            {key:'area', label:'Assessment area'},
            {key:'rating', label:'Rating (1-5)'},
            {key:'comments', label:'Comments'},
        ])}
    </div>`;

    html += `<div class="fb-sub">
        <div class="fb-sub-title">Value of the session</div>
        <div class="fb-grid">
            <div class="fb-kv"><span class="k">Overall value</span><span class="v">${esc(FB_VALUE_LABELS[fb.value_rating] || fb.value_rating || '-')}</span></div>
        </div>
        <div class="fb-kv legacy-style-fe7b4979fe"><span class="k">Most valuable insight</span></div>
        <div class="fb-text-block">${esc(fb.valuable_insight || 'Not provided.')}</div>
        <div class="fb-kv"><span class="k">Recommendations made</span></div>
        <div class="fb-text-block">${esc(fb.recommendations || 'Not provided.')}</div>
    </div>`;

    html += `<div class="fb-sub">
        <div class="fb-sub-title">Action plan</div>
        ${fbRowsTable(fb.action_plan, [
            {key:'action', label:'Agreed action'},
            {key:'responsible', label:'Responsible'},
            {key:'timeline', label:'Timeline'},
            {key:'confidence', label:'Confidence', labels: FB_CONFIDENCE_LABELS},
        ])}
    </div>`;

    html += `<div class="fb-sub">
        <div class="fb-sub-title">Progress assessment</div>
        <div class="fb-grid">
            <div class="fb-kv"><span class="k">Progress</span><span class="v">${esc(FB_PROGRESS_LABELS[fb.progress_rating] || fb.progress_rating || '-')}</span></div>
        </div>
        <div class="fb-text-block">${esc(fb.progress_explain || 'Not provided.')}</div>
    </div>`;

    html += `<div class="fb-sub">
        <div class="fb-sub-title">Remaining challenges</div>
        ${fbRowsTable(fb.challenges, [
            {key:'challenge', label:'Challenge'},
            {key:'urgency', label:'Urgency', labels: FB_URGENCY_LABELS},
            {key:'support', label:'Support needed'},
        ])}
    </div>`;

    html += `<div class="fb-sub">
        <div class="fb-sub-title">Future support needs</div>
        <div class="fb-text-block">${esc(fb.future_support || 'Not provided.')}</div>
    </div>`;

    html += `<div class="fb-sub">
        <div class="fb-sub-title">Overall session satisfaction</div>
        <div class="fb-grid">
            <div class="fb-kv"><span class="k">Satisfaction rating</span><span class="v">${fb.satisfaction_rating ? starHTML(fb.satisfaction_rating) : '-'}</span></div>
            <div class="fb-kv"><span class="k">Would recommend this mentor?</span><span class="v">${esc(FB_RECOMMEND_LABELS[fb.recommend_mentor] || fb.recommend_mentor || '-')}</span></div>
        </div>
        ${fb.satisfaction_comments ? `<div class="fb-text-block">${esc(fb.satisfaction_comments)}</div>` : ''}
    </div>`;

    html += `<div class="fb-sub">
        <div class="fb-sub-title">Additional feedback for the programme team</div>
        <div class="fb-kv"><span class="k">How could we improve the programme?</span></div>
        <div class="fb-text-block">${esc(fb.feedback_for_admin || 'Not provided.')}</div>
        <div class="fb-kv"><span class="k">What could the mentor do differently?</span></div>
        <div class="fb-text-block">${esc(fb.mentor_could_improve || 'Not provided.')}</div>
    </div>`;

    if(fb.status === 'reviewed'){
        html += `<div class="fb-reviewed-box">
            <strong class="legacy-style-633a7205fb"><i class="fa fa-check-circle"></i> Reviewed${fb.reviewed_by ? ' by ' + esc(fb.reviewed_by) : ''}${fb.reviewed_at ? ' &middot; ' + fmtDateShort(fb.reviewed_at) : ''}</strong>
            ${fb.review_notes ? `<div class="fb-review-notes">${esc(fb.review_notes)}</div>` : ''}
            ${CAN_MANAGE ? `<div class="legacy-style-8a77e5a311"><button type="button" class="btn btn-sm btn-secondary" onclick="fbToggleReviewForm(${sessionId})"><i class="fa fa-edit"></i> Update review notes</button></div>` : ''}
        </div>`;
    }

    if(CAN_MANAGE && (fb.status === 'submitted' || fb.status === 'reviewed')){
        html += `<div class="fb-review-box legacy-style-57fe6404fa" id="fb-review-form-${sessionId}">
            <strong class="legacy-style-9f57d88bbf"><i class="fa fa-clipboard-check"></i> ${fb.status==='reviewed' ? 'Update review' : 'Review this report'}</strong>
            <form method="POST" action="includes/process-mentor-sessions.php">
                <input type="hidden" name="action" value="review_feedback_report">
                <input type="hidden" name="session_id" value="${sessionId}">
                <textarea name="review_notes" placeholder="Notes for the venture and programme records (optional)...">${esc(fb.review_notes || '')}</textarea>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-check"></i> ${fb.status==='reviewed' ? 'Save notes' : 'Mark as reviewed'}</button>
            </form>
        </div>`;
    }

    body.innerHTML = html;
}

function fbToggleReviewForm(sessionId){
    const form = document.getElementById(`fb-review-form-${sessionId}`);
    if(form) form.style.display = form.style.display === 'none' ? '' : 'none';
}

function openDetailModal(id){
    const s=SESSION_DATA.find(x=>x.id===id); if(!s) return;
    const mentors  = MENTORS_BY_SESSION[id]  || [];
    const ventures = VENTURES_BY_SESSION[id] || [];

    document.getElementById('dm-title').textContent=s.title||'Session details';
    document.getElementById('dm-cmt-sid').value=id;
    if(document.getElementById('dm-invite-session-id')) document.getElementById('dm-invite-session-id').value=id;
    if(document.getElementById('dm-addv-session-id')) document.getElementById('dm-addv-session-id').value=id;
    if(document.getElementById('dm-add-mentor-box')) document.getElementById('dm-add-mentor-box').style.display='none';
    if(document.getElementById('dm-add-venture-box')) document.getElementById('dm-add-venture-box').style.display='none';

    const dt=s.scheduled_at?new Date(s.scheduled_at.replace(' ','T')):null;
    const dtStr=dt?dt.toLocaleDateString('en-GB',{weekday:'short',day:'numeric',month:'short',year:'numeric'})+' at '+dt.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'}):'TBD';
    document.getElementById('dm-info').innerHTML=`
        <div class="detail-row"><span class="detail-lbl">Status</span><span><span class="badge ${esc(s.status_badge)}">${esc(s.status_label)}</span></span></div>
        <div class="detail-row"><span class="detail-lbl">Date &amp; time</span><span class="legacy-style-6cb285c61d">${esc(dtStr)}</span></div>
        <div class="detail-row"><span class="detail-lbl">Duration</span><span>${s.duration} min &middot; ${esc(s.session_type)}</span></div>
        <div class="detail-row"><span class="detail-lbl">Platform</span><span>${esc(s.platform)}</span></div>
        ${s.meeting_link?`<div class="detail-row"><span class="detail-lbl">Link</span><span><a href="${esc(s.meeting_link)}" target="_blank" class="legacy-style-118e1505a3"><i class="fa fa-video"></i> ${esc(s.meeting_link)}</a></span></div>`:''}
        ${s.cohort_name?`<div class="detail-row"><span class="detail-lbl">Cohort</span><span class="legacy-style-6cb285c61d">${esc(s.cohort_name)}</span></div>`:''}
        ${s.description?`<div class="detail-row"><span class="detail-lbl">Agenda</span><span class="legacy-style-6cb285c61d">${esc(s.description).replace(/\n/g,'<br>')}</span></div>`:''}
        ${s.status==='cancelled' && s.cancel_reason ? `<div class="detail-row"><span class="detail-lbl">${s.cancelled_by==='mentor'?'Rejection reason':'Cancellation reason'}</span><span class="legacy-style-6cb285c61d">${esc(s.cancel_reason).replace(/\n/g,'<br>')}</span></div>` : ''}
        ${s.notes_shared?`<div class="detail-row"><span class="detail-lbl">Shared notes</span><span class="legacy-style-bcde519a7a">${esc(s.notes_shared).replace(/\n/g,'<br>')}</span></div>`:''}
        ${s.action_items?`<div class="detail-row"><span class="detail-lbl">Action items</span><span class="legacy-style-6cb285c61d">${esc(s.action_items).replace(/\n/g,'<br>')}</span></div>`:''}`;

    const INVITE_LABELS={invited:['Invited','#FAEEDA','#633806'],accepted:['Accepted','#EAF3DE','#27500A'],declined:['Declined','#F1EFE8','#5F5E5A']};
    document.getElementById('dm-mentor-chips').innerHTML = mentors.length
        ? mentors.map(m=>{
            const isOrganizer = m.role==='organizer';
            const st = INVITE_LABELS[m.invite_status] || ['', '#F1EFE8','#5F5E5A'];
            const bg = isOrganizer ? '#E1F5EE' : st[1];
            const tx = isOrganizer ? '#085041' : st[2];
            const label = isOrganizer ? 'Organizer' : st[0];
            const respondedTxt = m.responded_at ? ` &middot; Responded ${fmtDateShort(m.responded_at)}` : '';
            const updatedTxt = m.updated_at ? ` &middot; Updated ${fmtDateShort(m.updated_at)}` : '';
            return `<span class="attendee-chip legacy-style-cf4de0436b">
                       <i class="fa ${isOrganizer?'fa-star':'fa-user'}"></i> ${esc(m.full_name)} &middot; ${esc(label)}${respondedTxt}${updatedTxt}
                    </span>`;
          }).join('')
        : '<div class="legacy-style-865c33f0ef">No mentors on this session</div>';

    document.getElementById('dm-venture-chips').innerHTML = ventures.length
        ? ventures.map(v=>{
            const reqTxt = v.requested_at ? ` &middot; Requested ${fmtDateShort(v.requested_at)}` : '';
            return `<span class="attendee-chip venture-chip"><i class="fa fa-rocket"></i> ${esc(v.name)}${reqTxt}</span>`;
          }).join('')
        : '<div class="legacy-style-865c33f0ef">No ventures on this session</div>';

    const vr=s.venture_rating>0?`<div class="legacy-style-4e420aff3f"><span class="legacy-style-04b999c603">Venture: </span>${starHTML(s.venture_rating)}${s.venture_feedback?`<p class="legacy-style-875b876230">"${esc(s.venture_feedback)}"</p>`:''}</div>`:'<p class="legacy-style-865c33f0ef">Venture has not rated this session.</p>';
    const mr=s.mentor_rating>0?`<div class="legacy-style-4e420aff3f"><span class="legacy-style-04b999c603">Mentor: </span>${starHTML(s.mentor_rating)}${s.mentor_feedback?`<p class="legacy-style-875b876230">"${esc(s.mentor_feedback)}"</p>`:''}</div>`:'';
    const ar=s.admin_rating>0?`<div class="legacy-style-4e420aff3f"><span class="legacy-style-04b999c603">Admin: </span>${starHTML(s.admin_rating)}${s.admin_notes?`<p class="legacy-style-1f394abe26">${esc(s.admin_notes)}</p>`:''}</div>`:'';
    document.getElementById('dm-ratings-body').innerHTML=vr+mr+ar;

    if(!IS_MENTOR){
        const reps=REPORTS_DATA[id]||[];
        const reportList=document.getElementById('dm-reports-list');
        if(reportList){
            reportList.innerHTML=reps.length
                ?reps.map(r=>`<div class="report-item"><div class="report-icon"><i class="fa fa-file-pdf"></i></div><div class="report-meta"><div class="report-name">${esc(r.report_title||r.file_name||'Report')}</div><div class="report-date">${r.uploaded_at?new Date(r.uploaded_at.replace(' ','T')).toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}):''}</div></div><a href="includes/process-mentor-sessions.php?action=download_report&id=${r.id}" class="btn btn-sm"><i class="fa fa-download"></i></a></div>`).join('')
                :'<p class="legacy-style-dd257c2857">No reports uploaded yet.</p>';
        }
        renderFeedbackReport(id);
    }

    const cmts=COMMENTS_DATA[id]||[];
    document.getElementById('dm-comments').innerHTML=cmts.length
        ?cmts.map(c=>`<div class="comment-bubble ${esc(c.author_type||'admin')}"><div class="cb-inner"><div class="cb-author">${esc(c.display_name||c.author_name||'Team')}</div><div class="cb-text">${esc(c.comment_text||'').replace(/\n/g,'<br>')}</div><div class="cb-time">${new Date((c.created_at||'').replace(' ','T')).toLocaleString('en-GB',{day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'})}</div></div></div>`).join('')
        :'<p class="legacy-style-dd257c2857">No comments yet.</p>';
    document.getElementById('dm-comments').scrollTop=9999;

    const footer=document.getElementById('dm-footer');
    footer.innerHTML='<button class="btn btn-secondary" onclick="closeM(\'detailModal\')">Close</button>';
    footer.innerHTML+=`<a class="btn btn-secondary" href="mentor-reports.php?tab=session_report&session_id=${s.id}"><i class="fa fa-file-signature"></i> Session report</a>`;
    if (s.venture_count) {
        footer.innerHTML+=`<a class="btn btn-secondary" href="mentor-reports.php?tab=final_report"><i class="fa fa-file-alt"></i> Final report</a>`;
        footer.innerHTML+=`<a class="btn btn-secondary" href="mentor-reports.php?tab=cohort_evaluation"><i class="fa fa-clipboard-check"></i> Cohort evaluation</a>`;
    }
    if(IS_MENTOR&&s.status==='completed'&&!s.mentor_rating)
        footer.innerHTML+=`<button class="btn btn-primary" onclick="closeM('detailModal');openRateVentureModal(${s.id},'${esc(s.venture_name).replace(/'/g,"\\'")}')"><i class="fa fa-star"></i> Rate venture</button>`;
    if(CAN_MANAGE)
        footer.innerHTML+=`<button class="btn btn-primary" onclick="closeM('detailModal');openSessionModal(SESSION_DATA.find(x=>x.id===${id}))"><i class="fa fa-edit"></i> Edit</button>`;


    openM('detailModal');
}
function starHTML(n){return Array.from({length:5},(_,i)=>`<i class="${i<n?'fas':'far'} fa-star" class="legacy-style-733b3f4f15"></i>`).join('');}
function esc(str){if(str==null)return'';return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
</script>
</body>
</html>
