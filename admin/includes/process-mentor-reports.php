<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/mail-function.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/session-junctions.php';
require_once __DIR__ . '/report-schemas.php';
require_once __DIR__ . '/report-engine.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection error.');
}

$conn->set_charset('utf8mb4');

ms_auth_gate();

$IS_MENTOR        = ms_is_mentor();
$CAN_MANAGE       = ms_can_manage();
$MENTOR_RECORD_ID = ms_mentor_record_id($conn);


$CAN_VIEW_REPORTS = $CAN_MANAGE || rs_is_stakeholder_role();

$CURRENT_ROLE = function_exists('ms_current_role') ? ms_current_role() : '';
$PDF_UPLOAD_ROLES = [
    'admin', 'administrator', 'super-admin', 'superadmin', 'sup-admin', 'supper-admin', 'supperadmin',
    'programs-lead', 'program-lead', 'programs-director', 'program-director',
    'programs-manager', 'program-manager', 'consultant'
];
$CAN_UPLOAD_PDF = in_array($CURRENT_ROLE, $PDF_UPLOAD_ROLES, true);
$CAN_MANAGE = $CAN_MANAGE || $CAN_UPLOAD_PDF;
$CAN_VIEW_REPORTS = $CAN_MANAGE || rs_is_stakeholder_role();
$GLOBALS['MR_CAN_MANAGE'] = $CAN_MANAGE;

function mr_redirect(string $qs = ''): void
{
    header('Location: ../mentor-reports' . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

function mr_flash(string $msg, string $type = 'success'): void
{
    if (function_exists('flash')) {
        flash('mreports', $msg, $type);
        return;
    }
    $_SESSION['mreports_flash'] = ['msg' => $msg, 'type' => $type];
}

function mr_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function mr_owns_report(mysqli $conn, int $report_id, int $mentor_id): bool
{
    if (ms_can_manage() || !empty($GLOBALS['MR_CAN_MANAGE'])) return true;
    if ($report_id <= 0 || $mentor_id <= 0) return false;

    $st = $conn->prepare("SELECT 1 FROM mentor_reports WHERE id = ? AND mentor_id = ? LIMIT 1");
    if (!$st) return false;
    $st->bind_param('ii', $report_id, $mentor_id);
    $st->execute();
    $found = $st->get_result()->num_rows > 0;
    $st->close();

    return $found;
}

function mr_mentor_name(mysqli $conn, int $mentorId): string
{
    $st = $conn->prepare("SELECT full_name FROM mentors WHERE id = ? LIMIT 1");
    if (!$st) return 'Mentor';
    $st->bind_param('i', $mentorId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row['full_name'] ?? 'Mentor';
}

function mr_venture_name(mysqli $conn, int $ventureId): string
{
    if ($ventureId <= 0) return '';
    $st = $conn->prepare("SELECT name FROM ventures WHERE id = ? LIMIT 1");
    if (!$st) return '';
    $st->bind_param('i', $ventureId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row['name'] ?? '';
}

function mr_load_ventures_for_lookup(mysqli $conn): array
{
    $lookup = [];
    $res = $conn->query("SELECT id, name FROM ventures ORDER BY name");
    while ($r = $res->fetch_assoc()) $lookup[(int)$r['id']] = $r['name'];
    return $lookup;
}


function mr_allowed_venture_ids(mysqli $conn, bool $canManage, int $mentorId): array
{
    if ($canManage) {
        $res = $conn->query("SELECT id FROM ventures ORDER BY name");
        $ids = [];
        while ($r = $res->fetch_assoc()) $ids[] = (int)$r['id'];
        return $ids;
    }

    $ids = [];
    $st = $conn->prepare("
        SELECT DISTINCT v.id
        FROM ventures v
        WHERE EXISTS (
            SELECT 1 FROM mentor_sessions ms
            LEFT JOIN session_mentors sm ON sm.session_id = ms.id
            LEFT JOIN session_ventures sv ON sv.session_id = ms.id
            WHERE (sm.mentor_id = ? OR ms.mentor_id = ?)
              AND (sv.venture_id = v.id OR ms.venture_id = v.id)
        )
        ORDER BY v.name
    ");
    if ($st) {
        $st->bind_param('ii', $mentorId, $mentorId);
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) $ids[] = (int)$r['id'];
        $st->close();
    }
    return $ids;
}

/**
 * Return a unique, authorized list of venture IDs for one, selected, or all scope.
 * Never trust venture IDs submitted by the browser.
 */
function mr_selected_venture_ids(mysqli $conn, bool $canManage, int $mentorId): array
{
    $allowed = mr_allowed_venture_ids($conn, $canManage, $mentorId);
    $allowedMap = array_fill_keys($allowed, true);
    $scope = trim((string)($_POST['target_scope'] ?? 'selected'));

    if ($scope === 'all_ventures') return $allowed;

    $submitted = $_POST['venture_ids'] ?? [];
    if (!is_array($submitted)) $submitted = [$submitted];

    // Backward compatibility for the previous single-venture form.
    if (!$submitted && isset($_POST['venture_id'])) $submitted = [$_POST['venture_id']];

    $selected = [];
    foreach ($submitted as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id !== false && isset($allowedMap[(int)$id])) $selected[(int)$id] = (int)$id;
    }
    return array_values($selected);
}



function mr_selected_session_venture_id(mysqli $conn, bool $canManage, int $mentorId): int
{
    $allowed = mr_allowed_venture_ids($conn, $canManage, $mentorId);
    $allowedMap = array_fill_keys($allowed, true);

    $candidate = (int)($_POST['venture_id'] ?? 0);

    if ($candidate <= 0) {
        $submitted = $_POST['venture_ids'] ?? [];
        if (!is_array($submitted)) $submitted = [$submitted];

        foreach ($submitted as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false && isset($allowedMap[(int)$id])) {
                $candidate = (int)$id;
                break;
            }
        }
    }

    return ($candidate > 0 && isset($allowedMap[$candidate])) ? $candidate : 0;
}

function mr_normalize_files_array(array $files): array
{
    if (!isset($files['name'])) return [];

    if (!is_array($files['name'])) {
        return [[
            'name' => (string)($files['name'] ?? ''),
            'type' => (string)($files['type'] ?? ''),
            'tmp_name' => (string)($files['tmp_name'] ?? ''),
            'error' => (int)($files['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($files['size'] ?? 0),
        ]];
    }

    $normalized = [];
    foreach ($files['name'] as $index => $name) {
        $normalized[] = [
            'name' => (string)$name,
            'type' => (string)($files['type'][$index] ?? ''),
            'tmp_name' => (string)($files['tmp_name'][$index] ?? ''),
            'error' => (int)($files['error'][$index] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($files['size'][$index] ?? 0),
        ];
    }
    return $normalized;
}

function mr_store_uploaded_file(array $file, bool $pdfOnly = false): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed for "' . ($file['name'] ?? 'file') . '" with error code ' . (int)($file['error'] ?? -1) . '.');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 20 * 1024 * 1024) {
        throw new RuntimeException('Each file must be larger than 0 bytes and no more than 20 MB.');
    }

    $originalName = basename((string)($file['name'] ?? 'document'));
    $rawExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $ext = substr(preg_replace('/[^a-z0-9]/', '', $rawExt), 0, 10);

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string)$file['tmp_name']) ?: 'application/octet-stream';

    if ($pdfOnly && ($ext !== 'pdf' || $mime !== 'application/pdf')) {
        throw new RuntimeException('Only valid PDF files are allowed for report categories.');
    }

    $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt', 'jpg', 'jpeg', 'png', 'webp', 'zip'];
    if (!$pdfOnly && !in_array($ext, $allowedExtensions, true)) {
        throw new RuntimeException('Unsupported file type: ' . ($ext !== '' ? $ext : 'unknown') . '.');
    }

    $destDir = rtrim(defined('UPLOAD_PATH') ? UPLOAD_PATH : dirname(__DIR__, 2) . '/uploads', '/') . '/mentor-reports/';
    if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        throw new RuntimeException('Could not create the mentor report upload directory.');
    }

    $filename = bin2hex(random_bytes(12)) . ($ext !== '' ? '.' . $ext : '');
    $destination = $destDir . $filename;

    if (!move_uploaded_file((string)$file['tmp_name'], $destination)) {
        throw new RuntimeException('Failed to save "' . $originalName . '". Check directory permissions.');
    }

    return [
        'original_name' => $originalName,
        'extension' => $ext,
        'size' => $size,
        'mime' => $mime,
        'relative_path' => 'uploads/mentor-reports/' . $filename,
        'absolute_path' => $destination,
    ];
}

/** Create an independent physical copy for another venture report record. */
function mr_clone_stored_file(array $stored): array
{
    $source = (string)($stored['absolute_path'] ?? '');
    if ($source === '' || !is_file($source)) {
        throw new RuntimeException('The uploaded source file is no longer available.');
    }

    $ext = (string)($stored['extension'] ?? '');
    $filename = bin2hex(random_bytes(12)) . ($ext !== '' ? '.' . $ext : '');
    $absolutePath = rtrim(dirname($source), '/') . '/' . $filename;

    if (!copy($source, $absolutePath)) {
        throw new RuntimeException('Could not create an independent report file for a selected venture.');
    }

    $copy = $stored;
    $copy['absolute_path'] = $absolutePath;
    $copy['relative_path'] = 'uploads/mentor-reports/' . $filename;
    return $copy;
}

function mr_safe_file_name(string $title, string $extension): string
{
    $name = trim((string)preg_replace('/[^A-Za-z0-9_\-. ]/', '_', $title));
    if ($name === '') $name = 'mentor-report';
    $extension = strtolower(trim($extension));
    if ($extension !== '' && strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $extension) {
        $name .= '.' . $extension;
    }
    return $name;
}

function mr_notify_reviewers(mysqli $conn, int $mentorId, string $title): array
{
    $mentorName = mr_mentor_name($conn, $mentorId);
    $recipients = rs_privileged_recipients($conn);
    $sent = 0;
    $failed = 0;

    if (function_exists('sendEmail') && $recipients) {
        $subject = 'New mentor report: ' . $title;
        $content = '<h3>New mentor report submitted</h3><p>' . mr_h($mentorName) . ' has uploaded: <strong>' . mr_h($title) . '</strong></p><p>Please log in to review.</p>';
        $body = function_exists('email_wrapper') ? email_wrapper($content) : $content;
        $altBody = "{$mentorName} has uploaded: {$title}\n\nPlease log in to review.";

        foreach ($recipients as $recipient) {
            $result = sendEmail($recipient['email'], $subject, $body, $altBody);
            if ($result === true) $sent++; else $failed++;
        }
    }

    return ['sent' => $sent, 'failed' => $failed];
}



function mr_structured_columns_edit(mysqli $conn): array
{
    static $cols = null;
    if (is_array($cols)) return $cols;
    $cols = [];
    $res = $conn->query("SHOW COLUMNS FROM structured_reports");
    if ($res) while ($row = $res->fetch_assoc()) $cols[(string)$row['Field']] = strtolower((string)($row['Type'] ?? ''));
    return $cols;
}

function mr_structured_payload_column_edit(mysqli $conn): string
{
    $cols = mr_structured_columns_edit($conn);
    foreach (['data_json','report_data','form_data','answers_json','content_json','payload_json','data'] as $name) if (isset($cols[$name])) return $name;
    $ignore = ['id','report_type','mentor_id','venture_id','cohort_id','session_id','period_label','cohort_label','title','status','pdf_path','reviewed_by','reviewed_at','submitted_at','created_at','updated_at'];
    foreach ($cols as $name => $type) {
        if (in_array($name,$ignore,true)) continue;
        if (str_contains($type,'json') || str_contains($type,'text')) return $name;
    }
    return '';
}

function mr_update_existing_structured_draft(mysqli $conn, int $id, array $data, string $status): bool
{
    $col = mr_structured_payload_column_edit($conn);
    if ($col === '') throw new RuntimeException('Could not identify the structured report data column.');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) throw new RuntimeException('Could not encode the report answers.');
    $cols = mr_structured_columns_edit($conn);
    $set = ["`{$col}` = ?", 'status = ?'];
    $types='ss'; $params=[$json,$status];
    if (isset($cols['submitted_at'])) $set[] = $status === 'submitted' ? 'submitted_at = NOW()' : 'submitted_at = NULL';
    if (isset($cols['updated_at'])) $set[] = 'updated_at = NOW()';
    if (isset($cols['reviewed_by'])) $set[] = "reviewed_by = ''";
    if (isset($cols['reviewed_at'])) $set[] = 'reviewed_at = NULL';
    $sql = 'UPDATE structured_reports SET '.implode(', ',$set)." WHERE id = ? AND status = 'draft' LIMIT 1";
    $types.='i'; $params[]=$id;
    $st=$conn->prepare($sql);
    if (!$st) throw new RuntimeException('Could not prepare the draft update: '.$conn->error);
    $st->bind_param($types,...$params);
    $ok=$st->execute(); $err=$st->error; $st->close();
    if (!$ok) throw new RuntimeException('Could not update the draft: '.$err);
    return true;
}

$action = $_POST['action'] ?? trim((string)($_GET['action'] ?? ''));


if (in_array($action, ['preview_report', 'download_report'], true) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $report_id = (int)($_GET['id'] ?? 0);
    if ($report_id <= 0) { http_response_code(404); echo 'Not found.'; exit; }

    $st = $conn->prepare("SELECT * FROM mentor_reports WHERE id = ? LIMIT 1");
    if (!$st) { http_response_code(500); echo 'DB error.'; exit; }
    $st->bind_param('i', $report_id);
    $st->execute();
    $report = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$report) { http_response_code(404); echo 'Report not found.'; exit; }
    if ($IS_MENTOR && !$CAN_VIEW_REPORTS && (int)$report['mentor_id'] !== $MENTOR_RECORD_ID) {
        http_response_code(403); echo 'Access denied.'; exit;
    }

    $file_path = trim((string)($report['file_path'] ?? ''));
    if ($file_path === '') { http_response_code(404); echo 'File path missing.'; exit; }

    $abs = rtrim(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2), '/') . '/' . ltrim($file_path, '/');
    if (!file_exists($abs) || !is_file($abs)) { http_response_code(404); echo 'File not found on server.'; exit; }

    $mime = mime_content_type($abs) ?: 'application/octet-stream';
    $extension = strtolower((string)($report['file_ext'] ?? pathinfo($abs, PATHINFO_EXTENSION)));
    if ($extension === 'pdf') $mime = 'application/pdf';
    $download_name = mr_safe_file_name((string)($report['title'] ?? basename($abs)), $extension);
    $isPreview = $action === 'preview_report' && $extension === 'pdf';

    while (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($isPreview ? 'inline' : 'attachment') . '; filename="' . basename($download_name) . '"');
    header('Content-Length: ' . filesize($abs));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    readfile($abs);
    exit;
}


if (in_array($action, ['preview_structured', 'download_structured'], true) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $report_id = (int)($_GET['id'] ?? 0);
    if ($report_id <= 0) { http_response_code(404); echo 'Not found.'; exit; }

    $st = $conn->prepare("SELECT * FROM structured_reports WHERE id = ? LIMIT 1");
    if (!$st) { http_response_code(500); echo 'DB error.'; exit; }
    $st->bind_param('i', $report_id);
    $st->execute();
    $report = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$report) { http_response_code(404); echo 'Report not found.'; exit; }
    if ($IS_MENTOR && !$CAN_VIEW_REPORTS && (int)$report['mentor_id'] !== $MENTOR_RECORD_ID) {
        http_response_code(403); echo 'Access denied.'; exit;
    }

    $pdf_path = trim((string)($report['pdf_path'] ?? ''));
    if ($pdf_path === '') { http_response_code(404); echo 'PDF was not generated for this report (dompdf missing on server?).'; exit; }

    $abs = rtrim(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2), '/') . '/' . ltrim($pdf_path, '/');
    if (!file_exists($abs) || !is_file($abs)) { http_response_code(404); echo 'File not found on server.'; exit; }

    $download_name = mr_safe_file_name((string)($report['title'] ?? 'report'), 'pdf');
    $isPreview = $action === 'preview_structured';

    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($isPreview ? 'inline' : 'attachment') . '; filename="' . $download_name . '"');
    header('Content-Length: ' . filesize($abs));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    readfile($abs);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mr_redirect();
}


if ($action === 'save_structured') {
    if (!$IS_MENTOR && !$CAN_MANAGE) {
        mr_flash('Access denied.', 'error');
        mr_redirect();
    }

    $report_type = trim((string)($_POST['report_type'] ?? ''));
    $types = rs_report_types();
    if (!isset($types[$report_type])) {
        mr_flash('Unknown report type.', 'error');
        mr_redirect('tab=' . urlencode($report_type));
    }

    $schema = rs_get_schema($report_type);
    $scope  = $types[$report_type]['scope'];
    $data   = rs_collect($schema);

    $mentorId = ($IS_MENTOR && !$CAN_MANAGE) ? $MENTOR_RECORD_ID : (int)($_POST['mentor_id'] ?? $MENTOR_RECORD_ID);
    if ($mentorId <= 0) {
        mr_flash('Could not determine mentor for this report.', 'error');
        mr_redirect('tab=' . $report_type);
    }

    $status = ($_POST['submit_mode'] ?? 'submit') === 'draft' ? 'draft' : 'submitted';
    $mentorName = mr_mentor_name($conn, $mentorId);
    $ventureLookup = mr_load_ventures_for_lookup($conn);
    $GLOBALS['RS_VENTURE_NAME_LOOKUP'] = $ventureLookup;

    $draftId = max(0, (int)($_POST['draft_id'] ?? 0));

    if ($draftId > 0) {
        $st = $conn->prepare("SELECT * FROM structured_reports WHERE id = ? LIMIT 1");
        if (!$st) { mr_flash('Could not load the draft.', 'error'); mr_redirect('tab=' . urlencode($report_type)); }
        $st->bind_param('i', $draftId);
        $st->execute();
        $draft = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$draft) { mr_flash('The draft report could not be found.', 'error'); mr_redirect('tab=' . urlencode($report_type)); }
        if (strtolower(trim((string)($draft['status'] ?? ''))) !== 'draft') { mr_flash('This report is no longer a draft and cannot be edited.', 'error'); mr_redirect('tab=' . urlencode($report_type)); }
        if (trim((string)($draft['report_type'] ?? '')) !== $report_type) { mr_flash('The draft does not match this report type.', 'error'); mr_redirect('tab=' . urlencode($report_type)); }
        if (!$CAN_MANAGE && (!$IS_MENTOR || (int)($draft['mentor_id'] ?? 0) !== $MENTOR_RECORD_ID)) { mr_flash('You can only continue your own draft reports.', 'error'); mr_redirect('tab=' . urlencode($report_type)); }

        $mentorId = (int)($draft['mentor_id'] ?? 0);
        $mentorName = mr_mentor_name($conn, $mentorId);
        $ventureId = (int)($draft['venture_id'] ?? 0);
        $ventureName = $ventureId > 0 ? ($ventureLookup[$ventureId] ?? mr_venture_name($conn,$ventureId)) : '';

        try {
            // Continue Draft always updates the same structured_reports row.
            mr_update_existing_structured_draft($conn, $draftId, $data, $status);
        } catch (Throwable $e) {
            mr_flash($e->getMessage(), 'error');
            mr_redirect('tab=' . urlencode($report_type) . '&edit_draft=' . $draftId);
        }

        if ($status === 'submitted') {
            $pdfMeta = ['Mentor' => $mentorName];
            if ($ventureName !== '') $pdfMeta['Venture'] = $ventureName;
            if ($scope === 'session') $pdfMeta['Session Date'] = $data['session_date'] ?? '';
            if ($scope === 'mentor') $pdfMeta['Cohort / Cycle'] = $data['cohort_cycle'] ?? '';
            $pdfPath = rs_generate_pdf($schema, $pdfMeta, $data, $draftId);
            if ($pdfPath) rs_save_pdf_path($conn, $draftId, $pdfPath);
            rs_notify_stakeholders($conn, $draftId, $types[$report_type]['label'], $mentorName, $ventureName);
            mr_flash($types[$report_type]['label'] . ' submitted and stakeholders notified.', 'success');
            mr_redirect('tab=' . urlencode($report_type));
        }

        mr_flash('Draft updated successfully. You can continue editing.', 'success');
        mr_redirect('tab=' . urlencode($report_type) . '&edit_draft=' . $draftId);
    }

    $createdReportIds = [];

    if ($scope === 'venture') {
        $ventureIds = mr_selected_venture_ids($conn, $CAN_MANAGE, $mentorId);

        if (!$ventureIds) {
            mr_flash('Select one or more ventures, or choose all ventures.', 'error');
            mr_redirect('tab=' . $report_type);
        }

        foreach ($ventureIds as $vid) {
            $vName = $ventureLookup[$vid] ?? mr_venture_name($conn, $vid);
            $title = $types[$report_type]['label'] . ' - ' . $vName;

            $id = rs_save_report($conn, $report_type, $mentorId, $vid, null, null, $data['evaluation_period'] ?? ($data['fellowship_period'] ?? null), $title, $data, $status);
            if ($id > 0) {
                $createdReportIds[] = ['id' => $id, 'venture' => $vName];
                if ($status === 'submitted') {
                    $meta = ['Mentor' => $mentorName, 'Venture' => $vName];
                    $pdfPath = rs_generate_pdf($schema, $meta, $data, $id);
                    if ($pdfPath) rs_save_pdf_path($conn, $id, $pdfPath);
                    rs_notify_stakeholders($conn, $id, $types[$report_type]['label'], $mentorName, $vName);
                }
            }
        }
    } elseif ($scope === 'mentor') {
        $title = $types[$report_type]['label'] . ' - ' . $mentorName;
        $cohortLabel = $data['cohort_cycle'] ?? null;

        $id = rs_save_report($conn, $report_type, $mentorId, null, null, null, $cohortLabel, $title, $data, $status);
        if ($id > 0) {
            $createdReportIds[] = ['id' => $id, 'venture' => ''];
            if ($status === 'submitted') {
                $meta = ['Mentor' => $mentorName, 'Cohort / Cycle' => $cohortLabel];
                $pdfPath = rs_generate_pdf($schema, $meta, $data, $id);
                if ($pdfPath) rs_save_pdf_path($conn, $id, $pdfPath);
                rs_notify_stakeholders($conn, $id, $types[$report_type]['label'], $mentorName);
            }
        }
    } elseif ($scope === 'session') {
        $sessionId = max(0, (int)($_POST['session_id'] ?? 0));

        // A session report draft may be linked directly to a venture
        // even when no completed session has been selected yet.
        $ventureId = mr_selected_session_venture_id(
            $conn,
            $CAN_MANAGE,
            $mentorId
        );

        if ($sessionId <= 0 && $ventureId <= 0) {
            mr_flash(
                $status === 'draft'
                    ? 'Select a venture before saving this session report draft.'
                    : 'Select a completed session or a venture before submitting this session report.',
                'error'
            );
            mr_redirect('tab=' . urlencode($report_type));
        }

        $ventureIds = [];

        if ($ventureId > 0) {
            $ventureIds = [$ventureId];
        } elseif ($sessionId > 0) {
            $vRows = ms_get_session_ventures($conn, $sessionId);
            foreach ($vRows as $vRow) {
                $vid = (int)($vRow['venture_id'] ?? 0);
                if ($vid > 0) $ventureIds[$vid] = $vid;
            }
            $ventureIds = array_values($ventureIds);
        }

        if (!$ventureIds) {
            mr_flash(
                'The selected session does not have a venture linked to it. Please choose a venture.',
                'error'
            );
            mr_redirect('tab=' . urlencode($report_type));
        }

        foreach ($ventureIds as $vid) {
            $vName = $vid > 0 ? ($ventureLookup[$vid] ?? mr_venture_name($conn, $vid)) : '';
            $title = $types[$report_type]['label'] . ($vName !== '' ? ' - ' . $vName : '') . ' (' . date('j M Y') . ')';

            $id = rs_save_report($conn, $report_type, $mentorId, $vid ?: null, null, $sessionId ?: null, $data['session_date'] ?? null, $title, $data, $status);
            if ($id > 0) {
                $createdReportIds[] = ['id' => $id, 'venture' => $vName];
                if ($status === 'submitted') {
                    $meta = ['Mentor' => $mentorName, 'Venture' => $vName, 'Session Date' => $data['session_date'] ?? ''];
                    $pdfPath = rs_generate_pdf($schema, $meta, $data, $id);
                    if ($pdfPath) rs_save_pdf_path($conn, $id, $pdfPath);
                    rs_notify_stakeholders($conn, $id, $types[$report_type]['label'], $mentorName, $vName);
                }
            }
        }
    }

    if (!$createdReportIds) {
        mr_flash('Could not save the report. Please try again.', 'error');
        mr_redirect('tab=' . $report_type);
    }

    $count = count($createdReportIds);
    $verb  = $status === 'draft' ? 'saved as a draft' : 'submitted and stakeholders notified';
    mr_flash(
        $count === 1
            ? ($types[$report_type]['label'] . ' ' . $verb . '.')
            : ($count . ' ' . $types[$report_type]['label'] . ' reports ' . $verb . '.'),
        'success'
    );

    if ($status === 'draft' && count($createdReportIds) === 1) {
        mr_redirect('tab=' . urlencode($report_type) . '&edit_draft=' . (int)$createdReportIds[0]['id']);
    }

    mr_redirect('tab=' . urlencode($report_type));
}


if ($action === 'delete_structured_draft') {
    $reportId = max(0, (int)($_POST['report_id'] ?? 0));
    $returnTab = trim((string)($_POST['return_tab'] ?? 'session_report'));
    $validTabs = array_keys(rs_report_types());

    if (!in_array($returnTab, $validTabs, true)) {
        $returnTab = 'session_report';
    }

    $redirectQuery = 'tab=' . urlencode($returnTab);

    $submittedToken = (string)($_POST['delete_token'] ?? '');
    $sessionToken = (string)($_SESSION['mr_delete_token'] ?? '');

    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        mr_flash('Your session expired. Refresh the page and try again.', 'error');
        mr_redirect($redirectQuery);
    }

    if ($reportId <= 0) {
        mr_flash('Invalid draft selected.', 'error');
        mr_redirect($redirectQuery);
    }

    $st = $conn->prepare("
        SELECT id, mentor_id, report_type, title, status, pdf_path
        FROM structured_reports
        WHERE id = ?
        LIMIT 1
    ");

    if (!$st) {
        mr_flash('Could not load the draft.', 'error');
        mr_redirect($redirectQuery);
    }

    $st->bind_param('i', $reportId);
    $st->execute();
    $draft = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$draft) {
        mr_flash('The draft report could not be found.', 'error');
        mr_redirect($redirectQuery);
    }

    if (strtolower(trim((string)($draft['status'] ?? ''))) !== 'draft') {
        mr_flash('Only draft reports can be deleted.', 'error');
        mr_redirect($redirectQuery);
    }

    if (!$CAN_MANAGE && (!$IS_MENTOR || (int)($draft['mentor_id'] ?? 0) !== $MENTOR_RECORD_ID)) {
        mr_flash('You can only delete your own draft reports.', 'error');
        mr_redirect($redirectQuery);
    }

    $actualType = trim((string)($draft['report_type'] ?? ''));
    if (in_array($actualType, $validTabs, true)) {
        $redirectQuery = 'tab=' . urlencode($actualType);
    }

    $pdfPath = trim((string)($draft['pdf_path'] ?? ''));

    $delete = $conn->prepare("
        DELETE FROM structured_reports
        WHERE id = ?
          AND status = 'draft'
        LIMIT 1
    ");

    if (!$delete) {
        mr_flash('Could not prepare the draft deletion.', 'error');
        mr_redirect($redirectQuery);
    }

    $delete->bind_param('i', $reportId);
    $ok = $delete->execute();
    $affected = $delete->affected_rows;
    $delete->close();

    if (!$ok || $affected !== 1) {
        mr_flash('The draft could not be deleted.', 'error');
        mr_redirect($redirectQuery);
    }

    if ($pdfPath !== '') {
        $basePath = rtrim(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2), '/');
        $absolute = realpath($basePath . '/' . ltrim($pdfPath, '/'));
        if ($absolute !== false && is_file($absolute)) {
            @unlink($absolute);
        }
    }

    mr_flash('Draft deleted successfully.', 'success');
    mr_redirect($redirectQuery);
}

if ($action === 'delete_report') {
    $reportId = (int)($_POST['report_id'] ?? 0);
    $returnTab = trim((string)($_POST['return_tab'] ?? 'documents'));
    $validTabs = array_keys(rs_report_types());
    if ($returnTab !== 'documents' && !in_array($returnTab, $validTabs, true)) {
        $returnTab = 'documents';
    }
    $redirectQuery = 'tab=' . urlencode($returnTab);

    $submittedToken = (string)($_POST['delete_token'] ?? '');
    $sessionToken = (string)($_SESSION['mr_delete_token'] ?? '');
    if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        mr_flash('Your session expired. Refresh the page and try again.', 'error');
        mr_redirect($redirectQuery);
    }

    $st = $conn->prepare("SELECT id, mentor_id, report_type, title, status, file_path FROM mentor_reports WHERE id = ? LIMIT 1");
    if (!$st) {
        mr_flash('Database error: ' . $conn->error, 'error');
        mr_redirect($redirectQuery);
    }
    $st->bind_param('i', $reportId);
    $st->execute();
    $report = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$report) {
        mr_flash('The report could not be found.', 'error');
        mr_redirect($redirectQuery);
    }
    if (!$CAN_MANAGE && (!$IS_MENTOR || (int)$report['mentor_id'] !== $MENTOR_RECORD_ID)) {
        mr_flash('You can only delete your own uploaded reports.', 'error');
        mr_redirect($redirectQuery);
    }
    if (strtolower(trim((string)$report['status'])) === 'approved') {
        mr_flash('Approved reports cannot be deleted.', 'error');
        mr_redirect($redirectQuery);
    }

    $filePath = trim((string)($report['file_path'] ?? ''));
    try {
        if (!$conn->begin_transaction()) {
            throw new RuntimeException('Could not start the delete transaction.');
        }

        $reviews = $conn->prepare("DELETE FROM mentor_report_reviews WHERE report_id = ?");
        if ($reviews) {
            $reviews->bind_param('i', $reportId);
            $reviews->execute();
            $reviews->close();
        }

        $delete = $conn->prepare("DELETE FROM mentor_reports WHERE id = ? AND LOWER(COALESCE(status, 'pending')) <> 'approved' LIMIT 1");
        if (!$delete) throw new RuntimeException('Database error: ' . $conn->error);
        $delete->bind_param('i', $reportId);
        if (!$delete->execute() || $delete->affected_rows !== 1) {
            $delete->close();
            throw new RuntimeException('The report is approved or could not be deleted.');
        }
        $delete->close();

        if (!$conn->commit()) throw new RuntimeException('The report deletion could not be committed.');
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        mr_flash($e->getMessage(), 'error');
        mr_redirect($redirectQuery);
    }

    if ($filePath !== '') {
        $check = $conn->prepare("SELECT COUNT(*) AS total FROM mentor_reports WHERE file_path = ?");
        $references = 1;
        if ($check) {
            $check->bind_param('s', $filePath);
            $check->execute();
            $references = (int)($check->get_result()->fetch_assoc()['total'] ?? 0);
            $check->close();
        }

        if ($references === 0) {
            $basePath = rtrim(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2), '/');
            $uploadRoot = realpath($basePath . '/uploads/mentor-reports');
            $absoluteFile = realpath($basePath . '/' . ltrim($filePath, '/'));
            if ($uploadRoot !== false && $absoluteFile !== false
                && str_starts_with($absoluteFile, rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
                && is_file($absoluteFile)) {
                @unlink($absoluteFile);
            }
        }
    }

    mr_flash('Uploaded report deleted successfully.', 'success');
    mr_redirect($redirectQuery);
}

if ($action === 'upload_pdf_report') {
    if (!$CAN_UPLOAD_PDF) {
        mr_flash('Only an administrator, program director, program manager, or consultant may upload categorized PDF reports.', 'error');
        mr_redirect();
    }

    $reportTypes = rs_report_types();
    $reportType = trim((string)($_POST['report_type'] ?? ''));
    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $mentorId = (int)($_POST['mentor_id'] ?? 0);
    $sessionId = (int)($_POST['session_id'] ?? 0);

    if (!isset($reportTypes[$reportType])) {
        mr_flash('Invalid report category.', 'error');
        mr_redirect();
    }
    if ($title === '' || $mentorId <= 0) {
        mr_flash('Report title and mentor are required.', 'error');
        mr_redirect('tab=' . urlencode($reportType));
    }
    $ventureIds = in_array($reportTypes[$reportType]['scope'], ['venture', 'session'], true)
        ? mr_selected_venture_ids($conn, true, $mentorId)
        : [];
    if ($reportTypes[$reportType]['scope'] === 'venture' && !$ventureIds) {
        mr_flash('Select one or more ventures, or choose all ventures.', 'error');
        mr_redirect('tab=' . urlencode($reportType));
    }
    if (!$ventureIds) $ventureIds = [0];
    if (empty($_FILES['report_file']['name'])) {
        mr_flash('Select a PDF report to upload.', 'error');
        mr_redirect('tab=' . urlencode($reportType));
    }

    $stored = null;
    $createdFiles = [];
    $inserted = 0;
    try {
        $stored = mr_store_uploaded_file(mr_normalize_files_array($_FILES['report_file'])[0], true);
        $createdFiles[] = $stored['absolute_path'];

        $uploadedById = (int)($GLOBALS['ADMIN']['id'] ?? $_SESSION['admin_id'] ?? 0);
        $uploadedByName = (string)($GLOBALS['ADMIN']['full_name'] ?? 'Programme Team');

        $ventureLookup = mr_load_ventures_for_lookup($conn);
        if (!$conn->begin_transaction()) {
            throw new RuntimeException('Could not start the report upload transaction.');
        }

        foreach ($ventureIds as $index => $ventureId) {
            // Each selected venture receives its own physical file. No existing
            // report row or uploaded file is updated or overwritten.
            $ventureFile = $index === 0 ? $stored : mr_clone_stored_file($stored);
            if ($index > 0) $createdFiles[] = $ventureFile['absolute_path'];

            $recordTitle = $title;
            if (count($ventureIds) > 1 && $ventureId > 0) {
                $recordTitle .= ' - ' . ($ventureLookup[$ventureId] ?? ('Venture #' . $ventureId));
            }

            $relativePath = (string)$ventureFile['relative_path'];
            $originalName = (string)$ventureFile['original_name'];
            $fileSize = (int)$ventureFile['size'];
            $fileExtension = (string)$ventureFile['extension'];
            $fileMime = (string)$ventureFile['mime'];

            $st = $conn->prepare("
                INSERT INTO mentor_reports
                    (mentor_id, venture_id, session_id, report_type, title, description,
                     file_path, file_name, file_size, file_ext, mime_type,
                     uploaded_by_user_id, uploaded_by_name, status)
                VALUES (?, NULLIF(?,0), NULLIF(?,0), ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?,0), ?, 'pending')
            ");
            if (!$st) throw new RuntimeException('Database error: ' . $conn->error);

            $st->bind_param(
                'iiisssssissis',
                $mentorId,
                $ventureId,
                $sessionId,
                $reportType,
                $recordTitle,
                $description,
                $relativePath,
                $originalName,
                $fileSize,
                $fileExtension,
                $fileMime,
                $uploadedById,
                $uploadedByName
            );
            if (!$st->execute()) throw new RuntimeException('Upload could not be recorded: ' . $st->error);
            $st->close();
            $inserted++;
        }

        if (!$conn->commit()) {
            throw new RuntimeException('The report upload could not be committed.');
        }
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        foreach (array_unique($createdFiles) as $createdFile) {
            if (is_file($createdFile)) @unlink($createdFile);
        }
        mr_flash($e->getMessage(), 'error');
        mr_redirect('tab=' . urlencode($reportType));
    }

    $notify = mr_notify_reviewers($conn, $mentorId, $title);
    mr_flash(
        $notify['sent'] > 0
            ? "{$inserted} new PDF report(s) uploaded without replacing existing files. {$notify['sent']} reviewer(s) notified."
            : "{$inserted} new PDF report(s) uploaded without replacing existing files.",
        'success'
    );

    mr_redirect('tab=' . urlencode($reportType));
}

if ($action === 'upload') {
    if (!$IS_MENTOR && !$CAN_UPLOAD_PDF) {
        mr_flash('You do not have permission to upload documents.', 'error');
        mr_redirect('tab=documents');
    }

    $mentorId = $IS_MENTOR && !$CAN_UPLOAD_PDF
        ? $MENTOR_RECORD_ID
        : (int)($_POST['mentor_id'] ?? 0);

    if ($mentorId <= 0) {
        mr_flash('Select the mentor who owns these documents.', 'error');
        mr_redirect('tab=documents');
    }

    $baseTitle = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $files = mr_normalize_files_array($_FILES['report_files'] ?? ($_FILES['report_file'] ?? []));

    $files = array_values(array_filter($files, fn(array $file): bool => ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
    if (!$files) {
        mr_flash('Select at least one file to upload.', 'error');
        mr_redirect('tab=documents');
    }

    $uploadedById = (int)($GLOBALS['ADMIN']['id'] ?? $_SESSION['admin_id'] ?? 0);
    $uploadedByName = (string)($GLOBALS['ADMIN']['full_name'] ?? ($IS_MENTOR ? mr_mentor_name($conn, $mentorId) : 'Programme Team'));
    $storedFiles = [];
    $inserted = 0;
    $errors = [];

    foreach ($files as $index => $file) {
        $stored = null;
        try {
            $stored = mr_store_uploaded_file($file, false);
            $storedFiles[] = $stored;

            $fileTitle = $baseTitle !== ''
                ? (count($files) > 1 ? $baseTitle . ' - ' . pathinfo($stored['original_name'], PATHINFO_FILENAME) : $baseTitle)
                : pathinfo($stored['original_name'], PATHINFO_FILENAME);

            $st = $conn->prepare("
                INSERT INTO mentor_reports
                    (mentor_id, report_type, title, description, file_path, file_name,
                     file_size, file_ext, mime_type, uploaded_by_user_id, uploaded_by_name, status)
                VALUES (?, 'documents', ?, ?, ?, ?, ?, ?, ?, NULLIF(?,0), ?, 'pending')
            ");
            if (!$st) throw new RuntimeException('Database error: ' . $conn->error);

            $st->bind_param(
                'issssissis',
                $mentorId,
                $fileTitle,
                $description,
                $stored['relative_path'],
                $stored['original_name'],
                $stored['size'],
                $stored['extension'],
                $stored['mime'],
                $uploadedById,
                $uploadedByName
            );
            if (!$st->execute()) throw new RuntimeException($st->error);
            $st->close();
            $inserted++;
        } catch (Throwable $e) {
            if ($stored && !empty($stored['absolute_path']) && is_file($stored['absolute_path'])) {
                @unlink($stored['absolute_path']);
            }
            $errors[] = ($file['name'] ?? 'File') . ': ' . $e->getMessage();
        }
    }

    if ($inserted > 0) {
        $notify = mr_notify_reviewers($conn, $mentorId, $inserted === 1 ? ($baseTitle ?: 'Other document') : $inserted . ' other documents');
        $message = $inserted . ' document' . ($inserted === 1 ? '' : 's') . ' uploaded successfully.';
        if ($errors) $message .= ' ' . count($errors) . ' file(s) could not be uploaded.';
        mr_flash($message, $errors ? 'warning' : 'success');
    } else {
        mr_flash('No files were uploaded. ' . implode(' ', $errors), 'error');
    }

    mr_redirect('tab=documents');
}

if ($action === 'comment') {
    $report_id    = (int)($_POST['report_id'] ?? 0);
    $comment_text = trim((string)($_POST['comment_text'] ?? ''));

    if ($report_id <= 0 || $comment_text === '') { mr_flash('Comment cannot be empty.', 'error'); mr_redirect(); }
    if ($IS_MENTOR && !$CAN_VIEW_REPORTS && !mr_owns_report($conn, $report_id, $MENTOR_RECORD_ID)) { mr_flash('You can only comment on your own reports.', 'error'); mr_redirect(); }
    if (!$IS_MENTOR && !$CAN_VIEW_REPORTS) { mr_flash('Access denied.', 'error'); mr_redirect(); }

    $author_type = ($IS_MENTOR && !$CAN_VIEW_REPORTS) ? 'mentor' : 'admin';
    $author_name = $GLOBALS['ADMIN']['full_name'] ?? ($author_type === 'mentor' ? 'Mentor' : 'Programme Team');

    $st = $conn->prepare("INSERT INTO mentor_report_reviews (report_id, author_type, author_name, action, comment_text) VALUES (?, ?, ?, 'comment', ?)");
    if (!$st) { mr_flash('DB error: ' . $conn->error, 'error'); mr_redirect(); }
    $st->bind_param('isss', $report_id, $author_type, $author_name, $comment_text);
    $ok = $st->execute();
    $err = $st->error;
    $st->close();

    mr_flash($ok ? 'Comment added.' : 'Failed: ' . $err, $ok ? 'success' : 'error');
    mr_redirect();
}

if ($action === 'review') {
    if (!rs_can_review()) { mr_flash('Access denied.', 'error'); mr_redirect(); }

    $report_id    = (int)($_POST['report_id'] ?? 0);
    $decision     = trim((string)($_POST['decision'] ?? ''));
    $comment_text = trim((string)($_POST['comment_text'] ?? ''));
    $allowed_decisions = ['approved', 'rejected', 'changes_requested'];

    if ($report_id <= 0 || !in_array($decision, $allowed_decisions, true)) { mr_flash('Invalid review decision.', 'error'); mr_redirect(); }

    $reviewer_name = $GLOBALS['ADMIN']['full_name'] ?? 'Programme Team';

    $st = $conn->prepare("SELECT mentor_id, title FROM mentor_reports WHERE id = ? LIMIT 1");
    $report_row = null;
    if ($st) {
        $st->bind_param('i', $report_id);
        $st->execute();
        $report_row = $st->get_result()->fetch_assoc();
        $st->close();
    }

    $st = $conn->prepare("UPDATE mentor_reports SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? LIMIT 1");
    if (!$st) { mr_flash('DB error: ' . $conn->error, 'error'); mr_redirect(); }
    $st->bind_param('ssi', $decision, $reviewer_name, $report_id);
    $ok = $st->execute();
    $err = $st->error;
    $st->close();

    if ($ok) {
        $st = $conn->prepare("INSERT INTO mentor_report_reviews (report_id, author_type, author_name, action, comment_text) VALUES (?, 'admin', ?, ?, ?)");
        if ($st) { $st->bind_param('isss', $report_id, $reviewer_name, $decision, $comment_text); $st->execute(); $st->close(); }

        if ($report_row) {
            rs_notify_mentor_of_decision(
                $conn,
                (int)$report_row['mentor_id'],
                'document',
                (string)$report_row['title'],
                $decision,
                $comment_text,
                $reviewer_name
            );
        }
    }

    $labels = ['approved' => 'Report approved.', 'rejected' => 'Report rejected.', 'changes_requested' => 'Changes requested.'];
    mr_flash($ok ? ($labels[$decision] ?? 'Review saved.') : 'Failed: ' . $err, $ok ? 'success' : 'error');
    mr_redirect();
}

if ($action === 'review_structured') {
    if (!rs_can_review()) { mr_flash('Access denied.', 'error'); mr_redirect(); }

    $report_id    = (int)($_POST['report_id'] ?? 0);
    $decision     = trim((string)($_POST['decision'] ?? ''));
    $comment_text = trim((string)($_POST['comment_text'] ?? ''));
    $allowed_decisions = ['approved', 'rejected', 'changes_requested'];

    if ($report_id <= 0 || !in_array($decision, $allowed_decisions, true)) { mr_flash('Invalid review decision.', 'error'); mr_redirect(); }

    $reviewer_name = $GLOBALS['ADMIN']['full_name'] ?? 'Programme Team';
    $report = rs_review_structured_report($conn, $report_id, $decision, $comment_text, $reviewer_name);

    $labels = ['approved' => 'Report approved.', 'rejected' => 'Report rejected.', 'changes_requested' => 'Changes requested.'];
    mr_flash(
        $report ? ($labels[$decision] ?? 'Review saved.') : 'Failed: report not found.',
        $report ? 'success' : 'error'
    );

    mr_redirect('tab=' . urlencode((string)($report['report_type'] ?? 'session_report')));
}

if ($action === 'resubmit') {
    if (!$IS_MENTOR || $MENTOR_RECORD_ID <= 0) { mr_flash('Only the report owner can resubmit.', 'error'); mr_redirect(); }

    $report_id = (int)($_POST['report_id'] ?? 0);
    if ($report_id <= 0 || !mr_owns_report($conn, $report_id, $MENTOR_RECORD_ID)) { mr_flash('You can only resubmit your own reports.', 'error'); mr_redirect(); }

    $st = $conn->prepare("SELECT status, title FROM mentor_reports WHERE id = ? LIMIT 1");
    if (!$st) { mr_flash('DB error: ' . $conn->error, 'error'); mr_redirect(); }
    $st->bind_param('i', $report_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row || !in_array($row['status'], ['rejected', 'changes_requested'], true)) { mr_flash('Only rejected reports or those with requested changes can be resubmitted.', 'error'); mr_redirect(); }
    if (empty($_FILES['report_file']['name'])) { mr_flash('Please select a replacement file.', 'error'); mr_redirect(); }

    $originalName = (string)$_FILES['report_file']['name'];
    $rawExt       = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $ext          = substr(preg_replace('/[^a-z0-9]/', '', $rawExt), 0, 10);

    if ((int)$_FILES['report_file']['size'] > 20 * 1024 * 1024) { mr_flash('File too large. Maximum allowed size is 20 MB.', 'error'); mr_redirect(); }
    if ((int)$_FILES['report_file']['error'] !== UPLOAD_ERR_OK) { mr_flash('Upload error code: ' . (int)$_FILES['report_file']['error'], 'error'); mr_redirect(); }

    $dest_dir = rtrim(defined('UPLOAD_PATH') ? UPLOAD_PATH : dirname(__DIR__, 2) . '/uploads', '/') . '/mentor-reports/';
    if (!is_dir($dest_dir)) mkdir($dest_dir, 0755, true);

    $filename = uniqid('mr_', true) . ($ext !== '' ? '.' . $ext : '');
    $destFile = $dest_dir . $filename;
    if (!move_uploaded_file($_FILES['report_file']['tmp_name'], $destFile)) { mr_flash('Failed to save replacement file.', 'error'); mr_redirect(); }

    $file_path = 'uploads/mentor-reports/' . $filename;
    $file_size = (int)$_FILES['report_file']['size'];

    $st = $conn->prepare("
        UPDATE mentor_reports
        SET file_path = ?, file_name = ?, file_size = ?, file_ext = ?, status = 'pending', reviewed_by = '', reviewed_at = NULL
        WHERE id = ? LIMIT 1
    ");
    if (!$st) { mr_flash('DB error: ' . $conn->error, 'error'); mr_redirect(); }
    $st->bind_param('ssisi', $file_path, $originalName, $file_size, $ext, $report_id);
    $ok = $st->execute();
    $err = $st->error;
    $st->close();

    if ($ok) {
        $mentor_name = mr_mentor_name($conn, $MENTOR_RECORD_ID);
        $log_st = $conn->prepare("INSERT INTO mentor_report_reviews (report_id, author_type, author_name, action, comment_text) VALUES (?, 'mentor', ?, 'comment', 'Resubmitted a new file for review.')");
        if ($log_st) { $log_st->bind_param('is', $report_id, $mentor_name); $log_st->execute(); $log_st->close(); }
    }

    mr_flash($ok ? 'Resubmitted for review.' : 'Failed: ' . $err, $ok ? 'success' : 'error');
    mr_redirect();
}

mr_flash('Invalid action.', 'error');
mr_redirect();
