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
    'Reviewer'
]);

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

function redirect_with_message(string $message, string $type = 'danger', string $location = '../startup-milestones-dashboard.php?active_tab=reports'): void
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
    if ($value === null) {
        return null;
    }

    $value = trim($value);

    if ($value === '') {
        return null;
    }

    return function_exists('sanitize_input') ? sanitize_input($value) : $value;
}

function post_string(string $key, string $default = ''): string
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function visit_exists(mysqli $conn, int $visitId): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM startup_field_visits WHERE visit_id = ? LIMIT 1");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $visitId);
    $stmt->execute();
    $stmt->store_result();

    $exists = $stmt->num_rows > 0;
    $stmt->close();

    return $exists;
}

function signature_upload_path(int $visitId, string $extension): array
{
    $baseDiskDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'startup-field-visits' . DIRECTORY_SEPARATOR . $visitId . DIRECTORY_SEPARATOR . 'signatures';
    $baseWebDir = 'uploads/startup-field-visits/' . $visitId . '/signatures';

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['upload_report_signature'])) {
    header('Location: ../startup-milestones-dashboard.php?active_tab=reports');
    exit();
}

$visitId = (int)post_string('visit_id', '0');
$signatureType = clean_text(post_string('signature_type', 'field_team')) ?? 'field_team';
$approvalStatus = clean_text(post_string('report_approval_status', 'Signed')) ?? 'Signed';
$approvalNotes = clean_text(post_string('report_approval_notes', ''));

$location = '../startup-milestones-dashboard.php?active_tab=reports';

if ($visitId <= 0 || !visit_exists($conn, $visitId)) {
    redirect_with_message('Invalid field report selected.', 'danger', $location);
}

if (!in_array($signatureType, ['field_team', 'supervisor'], true)) {
    redirect_with_message('Invalid signature type selected.', 'danger', $location);
}

if (!in_array($approvalStatus, ['Pending', 'Signed', 'Reviewed', 'Approved', 'Rejected'], true)) {
    redirect_with_message('Invalid approval status selected.', 'danger', $location);
}

if (
    !isset($_FILES['signature_file'])
    || !is_array($_FILES['signature_file'])
    || (int)($_FILES['signature_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
) {
    redirect_with_message('Please upload a signature image/file.', 'danger', $location);
}

$file = $_FILES['signature_file'];
$fileSize = (int)($file['size'] ?? 0);
$tmpPath = (string)($file['tmp_name'] ?? '');
$originalName = (string)($file['name'] ?? '');

if ($fileSize <= 0) {
    redirect_with_message('Signature file is empty.', 'danger', $location);
}

if ($fileSize > 5242880) {
    redirect_with_message('Signature file must be less than 5MB.', 'danger', $location);
}

$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

if (!in_array($extension, $allowed, true)) {
    redirect_with_message('Signature file type is not allowed. Use JPG, PNG, WEBP, or PDF.', 'danger', $location);
}

$paths = signature_upload_path($visitId, $extension);

if (!move_uploaded_file($tmpPath, $paths['disk_path'])) {
    redirect_with_message('Failed to upload signature file.', 'danger', $location);
}

$signaturePath = $paths['web_path'];

if ($signatureType === 'field_team') {
    $sql = "
        UPDATE startup_field_visits
        SET field_team_signature = ?,
            field_team_signed_by = ?,
            field_team_signed_at = NOW(),
            report_approval_status = ?,
            report_approval_notes = ?
        WHERE visit_id = ?
    ";
} else {
    $sql = "
        UPDATE startup_field_visits
        SET supervisor_signature = ?,
            supervisor_signed_by = ?,
            supervisor_signed_at = NOW(),
            report_approval_status = ?,
            report_approval_notes = ?
        WHERE visit_id = ?
    ";
}

$stmt = $conn->prepare($sql);

if (!$stmt) {
    if (is_file($paths['disk_path'])) {
        unlink($paths['disk_path']);
    }

    redirect_with_message('Failed to prepare signature update. Run sql/startup_field_visit_signatures.sql first. Error: ' . $conn->error, 'danger', $location);
}

$stmt->bind_param('sissi', $signaturePath, $userId, $approvalStatus, $approvalNotes, $visitId);

if ($stmt->execute()) {
    if (function_exists('log_action')) {
        log_action(
            $userId,
            'Upload Startup Field Report Signature',
            'startup_field_visits',
            $visitId,
            "Uploaded {$signatureType} signature"
        );
    }

    $stmt->close();
    redirect_with_message('Signature uploaded successfully.', 'success', $location);
}

$error = $stmt->error ?: $conn->error;
$stmt->close();

if (is_file($paths['disk_path'])) {
    unlink($paths['disk_path']);
}

redirect_with_message('Error uploading signature: ' . $error, 'danger', $location);
