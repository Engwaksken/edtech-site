<?php
declare(strict_types=1);

require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

check_role([
    'Administrator',
    'Programs Lead',
    'Program Director',
    'Program Manager',
    'MEAL Lead',
    'Project Officer',
       'Consultant',
    'Reviewer'
]);

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

function redirect_with_message(string $message, string $type = 'danger', string $location = '../startup-field-visits.php'): void
{
    if (function_exists('send_notification') && isset($_SESSION['user_id'])) {
        send_notification((int)$_SESSION['user_id'], $message, $type);
    } else {
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = $type;
    }

    header("Location: {$location}");
    exit();
}

function clean_text(?string $value): ?string
{
    if ($value === null) return null;
    $value = trim($value);
    if ($value === '') return null;
    return function_exists('sanitize_input') ? sanitize_input($value) : $value;
}

function post_string(string $key, string $default = ''): string
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function post_nullable_string(string $key): ?string
{
    return isset($_POST[$key]) ? clean_text((string)$_POST[$key]) : null;
}

function post_float(string $key): float
{
    if (!isset($_POST[$key])) return 0.0;
    $value = trim((string)$_POST[$key]);
    return $value === '' ? 0.0 : (float)$value;
}

function is_valid_date(?string $date): bool
{
    if ($date === null || $date === '') return true;
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt instanceof DateTime && $dt->format('Y-m-d') === $date;
}

function get_startup_milestone(mysqli $conn, int $milestoneId): ?array
{
    $stmt = $conn->prepare("
        SELECT
            sm.*,
            COALESCE(a.startup_name, CONCAT('Application #', sm.application_id)) AS startup_name
        FROM startup_milestones sm
        LEFT JOIN applications a ON a.application_id = sm.application_id
        WHERE sm.milestone_id = ?
        LIMIT 1
    ");

    if (!$stmt) return null;

    $stmt->bind_param('i', $milestoneId);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    $stmt->close();

    return $row ?: null;
}

function visit_exists(mysqli $conn, int $visitId): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM startup_field_visits WHERE visit_id = ? LIMIT 1");
    if (!$stmt) return false;

    $stmt->bind_param('i', $visitId);
    $stmt->execute();
    $stmt->store_result();

    $ok = $stmt->num_rows > 0;
    $stmt->close();

    return $ok;
}

function generate_visit_code(mysqli $conn): string
{
    do {
        $code = 'SFV-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

        $stmt = $conn->prepare("SELECT 1 FROM startup_field_visits WHERE visit_code = ? LIMIT 1");
        if (!$stmt) return $code;

        $stmt->bind_param('s', $code);
        $stmt->execute();
        $stmt->store_result();

        $exists = $stmt->num_rows > 0;
        $stmt->close();
    } while ($exists);

    return $code;
}

function calculate_overall_score(array $scores): float
{
    if (empty($scores)) return 0.0;
    return round(array_sum($scores) / count($scores), 2);
}

function safe_report_upload_path(int $visitId, string $extension): array
{
    $baseDiskDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'startup-field-visits' . DIRECTORY_SEPARATOR . $visitId;
    $baseWebDir = 'uploads/startup-field-visits/' . $visitId;

    if (!is_dir($baseDiskDir)) {
        mkdir($baseDiskDir, 0755, true);
    }

    $filename = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;

    return [
        'disk_path' => $baseDiskDir . DIRECTORY_SEPARATOR . $filename,
        'web_path' => $baseWebDir . '/' . $filename,
    ];
}

$userId = (int)($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../startup-field-visits.php');
    exit();
}

/*
|--------------------------------------------------------------------------
| Generate Field Visit Form
|--------------------------------------------------------------------------
*/
if (isset($_POST['generate_visit_form'])) {
    $milestoneId = (int)post_string('milestone_id', '0');
    $milestone = get_startup_milestone($conn, $milestoneId);

    if (!$milestone) {
        redirect_with_message('Invalid startup milestone selected.', 'danger', '../startup-milestones.php');
    }

    $visitCode = generate_visit_code($conn);
    $visitTitle = 'Field Visit - ' . ($milestone['startup_name'] ?? ('Application #' . $milestone['application_id']));
    $plannedDate = post_nullable_string('planned_visit_date');
    $assignedTo = post_nullable_string('assigned_to');
    $location = post_nullable_string('location');
    $contactPerson = post_nullable_string('contact_person');
    $contactPhone = post_nullable_string('contact_phone');

    if (!is_valid_date($plannedDate)) {
        redirect_with_message('Invalid planned visit date.', 'danger', '../startup-milestones.php');
    }

    $stmt = $conn->prepare("
        INSERT INTO startup_field_visits (
            milestone_id,
            application_id,
            review_id,
            visit_code,
            visit_title,
            assigned_to,
            planned_visit_date,
            location,
            contact_person,
            contact_phone,
            status,
            generated_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Generated', ?)
    ");

    if (!$stmt) {
        redirect_with_message('Failed to prepare field visit form query: ' . $conn->error, 'danger', '../startup-milestones.php');
    }

    $applicationId = (int)$milestone['application_id'];
    $reviewId = !empty($milestone['review_id']) ? (int)$milestone['review_id'] : null;

    $stmt->bind_param(
        'iiisssssssi',
        $milestoneId,
        $applicationId,
        $reviewId,
        $visitCode,
        $visitTitle,
        $assignedTo,
        $plannedDate,
        $location,
        $contactPerson,
        $contactPhone,
        $userId
    );

    if ($stmt->execute()) {
        $visitId = (int)$stmt->insert_id;
        $stmt->close();

        if (function_exists('log_action')) {
            log_action($userId, 'Generate Startup Field Visit Form', 'startup_field_visits', $visitId, "Generated field visit form {$visitCode}");
        }

        redirect_with_message('Field visit form generated successfully.', 'success', '../startup-field-visits.php?visit_id=' . $visitId);
    }

    $error = $stmt->error ?: $conn->error;
    $stmt->close();

    redirect_with_message('Error generating field visit form: ' . $error, 'danger', '../startup-milestones.php');
}

/*
|--------------------------------------------------------------------------
| Submit Field Visit Scores and Report
|--------------------------------------------------------------------------
*/
if (isset($_POST['submit_field_visit'])) {
    $visitId = (int)post_string('visit_id', '0');
    $location = post_nullable_string('location');
    $actualVisitDate = post_nullable_string('actual_visit_date');
    $businessStatus = clean_text(post_string('business_status', 'Visited')) ?? 'Visited';
    $recommendation = clean_text(post_string('field_recommendation', 'Pending')) ?? 'Pending';

    if ($visitId <= 0 || !visit_exists($conn, $visitId)) {
        redirect_with_message('Invalid field visit selected.');
    }

    if (!is_valid_date($actualVisitDate)) {
        redirect_with_message('Invalid actual visit date.', 'danger', '../startup-field-visits.php?visit_id=' . $visitId);
    }

    $allowedBusinessStatuses = ['Not Visited', 'Visited', 'Could Not Locate', 'Closed', 'Needs Follow Up'];
    $allowedRecommendations = ['Strongly Recommend', 'Recommend', 'Needs Support', 'Do Not Recommend', 'Pending'];

    if (!in_array($businessStatus, $allowedBusinessStatuses, true)) {
        redirect_with_message('Invalid business status.', 'danger', '../startup-field-visits.php?visit_id=' . $visitId);
    }

    if (!in_array($recommendation, $allowedRecommendations, true)) {
        redirect_with_message('Invalid field recommendation.', 'danger', '../startup-field-visits.php?visit_id=' . $visitId);
    }

    $scores = [
        'equity_score' => post_float('equity_score'),
        'video_intro_score' => post_float('video_intro_score'),
        'problem_depth_toc_score' => post_float('problem_depth_toc_score'),
        'product_quality_pedagogy_score' => post_float('product_quality_pedagogy_score'),
        'product_demo_link_score' => post_float('product_demo_link_score'),
        'scalability_traction_sustainability_score' => post_float('scalability_traction_sustainability_score'),
        'team_capability_commitment_score' => post_float('team_capability_commitment_score'),
        'ursb_registration_score' => post_float('ursb_registration_score'),
    ];

    foreach ($scores as $label => $score) {
        if ($score < 0 || $score > 10) {
            redirect_with_message('All field scores must be between 0 and 10.', 'danger', '../startup-field-visits.php?visit_id=' . $visitId);
        }
    }

    $overall = calculate_overall_score($scores);

    $observations = post_nullable_string('observations');
    $risks = post_nullable_string('risks');
    $supportNeeded = post_nullable_string('support_needed');
    $nextSteps = post_nullable_string('next_steps');

    $reportFile = null;
    $reportOriginalName = null;
    $reportMimeType = null;
    $reportFileSize = null;

    if (isset($_FILES['report_file']) && is_array($_FILES['report_file']) && (int)$_FILES['report_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['report_file'];
        $fileSize = (int)($file['size'] ?? 0);
        $tmpPath = (string)($file['tmp_name'] ?? '');
        $originalName = (string)($file['name'] ?? '');

        if ($fileSize <= 0) {
            redirect_with_message('Uploaded report file is empty.', 'danger', '../startup-field-visits.php?visit_id=' . $visitId);
        }

        if ($fileSize > 15728640) {
            redirect_with_message('Report file must be less than 15MB.', 'danger', '../startup-field-visits.php?visit_id=' . $visitId);
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'zip'];

        if (!in_array($extension, $allowed, true)) {
            redirect_with_message('Report file type is not allowed.', 'danger', '../startup-field-visits.php?visit_id=' . $visitId);
        }

        $paths = safe_report_upload_path($visitId, $extension);

        if (!move_uploaded_file($tmpPath, $paths['disk_path'])) {
            redirect_with_message('Failed to upload report file.', 'danger', '../startup-field-visits.php?visit_id=' . $visitId);
        }

        $reportFile = $paths['web_path'];
        $reportOriginalName = $originalName;
        $reportMimeType = function_exists('mime_content_type') ? (mime_content_type($paths['disk_path']) ?: 'application/octet-stream') : 'application/octet-stream';
        $reportFileSize = $fileSize;
    }

    $status = $reportFile ? 'Report Uploaded' : 'Visited';

    $stmt = $conn->prepare("
        UPDATE startup_field_visits SET
            location = ?,
            actual_visit_date = ?,
            business_status = ?,

            equity_score = ?,
            video_intro_score = ?,
            problem_depth_toc_score = ?,
            product_quality_pedagogy_score = ?,
            product_demo_link_score = ?,
            scalability_traction_sustainability_score = ?,
            team_capability_commitment_score = ?,
            ursb_registration_score = ?,
            field_overall_score = ?,

            field_recommendation = ?,
            observations = ?,
            risks = ?,
            support_needed = ?,
            next_steps = ?,

            report_file = COALESCE(?, report_file),
            report_original_name = COALESCE(?, report_original_name),
            report_mime_type = COALESCE(?, report_mime_type),
            report_file_size = COALESCE(?, report_file_size),

            status = ?,
            submitted_by = ?,
            submitted_at = NOW()
        WHERE visit_id = ?
    ");

    if (!$stmt) {
        redirect_with_message('Failed to prepare field visit submission: ' . $conn->error, 'danger', '../startup-field-visits.php?visit_id=' . $visitId);
    }

    $stmt->bind_param(
        'sssddddddddsssssssssisii',
        $location,
        $actualVisitDate,
        $businessStatus,

        $scores['equity_score'],
        $scores['video_intro_score'],
        $scores['problem_depth_toc_score'],
        $scores['product_quality_pedagogy_score'],
        $scores['product_demo_link_score'],
        $scores['scalability_traction_sustainability_score'],
        $scores['team_capability_commitment_score'],
        $scores['ursb_registration_score'],
        $overall,

        $recommendation,
        $observations,
        $risks,
        $supportNeeded,
        $nextSteps,

        $reportFile,
        $reportOriginalName,
        $reportMimeType,
        $reportFileSize,

        $status,
        $userId,
        $visitId
    );

    if ($stmt->execute()) {
        $stmt->close();

        if (function_exists('log_action')) {
            log_action($userId, 'Submit Startup Field Visit Report', 'startup_field_visits', $visitId, "Submitted field visit report");
        }

        redirect_with_message('Field visit report submitted successfully.', 'success', '../startup-field-visits.php?visit_id=' . $visitId);
    }

    $error = $stmt->error ?: $conn->error;
    $stmt->close();

    redirect_with_message('Error submitting field visit report: ' . $error, 'danger', '../startup-field-visits.php?visit_id=' . $visitId);
}

header('Location: ../startup-field-visits.php');
exit();
