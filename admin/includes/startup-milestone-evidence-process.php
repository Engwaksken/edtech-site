<?php
declare(strict_types=1);

require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

check_role([
    'Administrator','Programs Lead','Program Director','Program Manager',
    'MEAL Lead','Project Officer','Reviewer','Applicant','applicant'
]);

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}
$conn->set_charset('utf8mb4');

function redirect_with_message(string $message, string $type = 'danger', string $location = '../venture-milestone-evidence.php'): void
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

function get_milestone(mysqli $conn, int $milestoneId): ?array
{
    $stmt = $conn->prepare("SELECT milestone_id, application_id, review_id FROM startup_milestones WHERE milestone_id = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $milestoneId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function evidence_upload_path(int $applicationId, int $milestoneId, string $extension): array
{
    $baseDiskDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'startup-evidence' . DIRECTORY_SEPARATOR . $applicationId . DIRECTORY_SEPARATOR . $milestoneId;
    $baseWebDir = 'uploads/startup-evidence/' . $applicationId . '/' . $milestoneId;

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['upload_milestone_evidence'])) {
    header('Location: ../venture-milestone-evidence.php');
    exit();
}

$milestoneId = (int)post_string('milestone_id', '0');
$postedApplicationId = (int)post_string('application_id', '0');
$milestone = get_milestone($conn, $milestoneId);

if (!$milestone) {
    redirect_with_message('Invalid milestone selected.');
}

$applicationId = (int)$milestone['application_id'];
$reviewId = !empty($milestone['review_id']) ? (int)$milestone['review_id'] : null;
$location = '../venture-milestone-evidence.php?application_id=' . $applicationId;

if ($postedApplicationId > 0 && $postedApplicationId !== $applicationId) {
    redirect_with_message('Selected milestone does not match the selected application.', 'danger', $location);
}

$title = clean_text(post_string('evidence_title'));
$description = post_nullable_string('evidence_description');
$submittedName = post_nullable_string('submitted_name');
$submittedEmail = post_nullable_string('submitted_email');
$submittedPhone = post_nullable_string('submitted_phone');

if ($title === null) {
    redirect_with_message('Evidence title is required.', 'danger', $location);
}

if (
    !isset($_FILES['evidence_file'])
    || !is_array($_FILES['evidence_file'])
    || (int)($_FILES['evidence_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
) {
    redirect_with_message('Please upload an evidence file.', 'danger', $location);
}

$file = $_FILES['evidence_file'];
$fileSize = (int)($file['size'] ?? 0);
$tmpPath = (string)($file['tmp_name'] ?? '');
$originalName = (string)($file['name'] ?? '');

if ($fileSize <= 0) {
    redirect_with_message('Uploaded evidence file is empty.', 'danger', $location);
}

if ($fileSize > 20971520) {
    redirect_with_message('Evidence file must be less than 20MB.', 'danger', $location);
}

$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'webp', 'zip'];

if (!in_array($extension, $allowed, true)) {
    redirect_with_message('Evidence file type is not allowed.', 'danger', $location);
}

$paths = evidence_upload_path($applicationId, $milestoneId, $extension);

if (!move_uploaded_file($tmpPath, $paths['disk_path'])) {
    redirect_with_message('Failed to upload evidence file.', 'danger', $location);
}

$mimeType = function_exists('mime_content_type')
    ? (mime_content_type($paths['disk_path']) ?: 'application/octet-stream')
    : 'application/octet-stream';

$stmt = $conn->prepare("
    INSERT INTO startup_milestone_evidence (
        milestone_id, application_id, review_id, evidence_title, evidence_description,
        evidence_file, original_file_name, mime_type, file_size, submitted_by,
        submitted_name, submitted_email, submitted_phone, review_status
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')
");

if (!$stmt) {
    if (is_file($paths['disk_path'])) unlink($paths['disk_path']);
    redirect_with_message('Failed to prepare evidence upload query: ' . $conn->error, 'danger', $location);
}

$stmt->bind_param(
    'iiisssssiisss',
    $milestoneId, $applicationId, $reviewId, $title, $description,
    $paths['web_path'], $originalName, $mimeType, $fileSize, $userId,
    $submittedName, $submittedEmail, $submittedPhone
);

if ($stmt->execute()) {
    $evidenceId = (int)$stmt->insert_id;
    if (function_exists('log_action')) {
        log_action($userId, 'Upload Startup Milestone Evidence', 'startup_milestone_evidence', $evidenceId, "Uploaded evidence: {$title}");
    }
    $stmt->close();
    redirect_with_message('Evidence uploaded successfully.', 'success', $location . '#history');
}

$error = $stmt->error ?: $conn->error;
$stmt->close();
if (is_file($paths['disk_path'])) unlink($paths['disk_path']);
redirect_with_message('Error uploading evidence: ' . $error, 'danger', $location);
