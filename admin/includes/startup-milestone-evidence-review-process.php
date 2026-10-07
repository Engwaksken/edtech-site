<?php
declare(strict_types=1);

require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

check_role([
    'Administrator','Programs Lead','Program Director','Program Manager',
    'MEAL Lead','Project Officer','Reviewer'
]);

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}
$conn->set_charset('utf8mb4');

function redirect_with_message(string $message, string $type = 'danger', string $location = '../startup-milestones-dashboard.php?active_tab=evidence'): void
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

function evidence_exists(mysqli $conn, int $evidenceId): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM startup_milestone_evidence WHERE evidence_id = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('i', $evidenceId);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['review_milestone_evidence'])) {
    header('Location: ../startup-milestones-dashboard.php?active_tab=evidence');
    exit();
}

$evidenceId = (int)($_POST['evidence_id'] ?? 0);
$score = isset($_POST['reviewer_score']) && $_POST['reviewer_score'] !== '' ? (float)$_POST['reviewer_score'] : null;
$comments = clean_text((string)($_POST['reviewer_comments'] ?? ''));
$status = clean_text((string)($_POST['review_status'] ?? 'Reviewed')) ?? 'Reviewed';
$userId = (int)($_SESSION['user_id'] ?? 0);

$allowedStatus = ['Pending', 'Reviewed', 'Accepted', 'Needs Improvement', 'Rejected'];

if ($evidenceId <= 0 || !evidence_exists($conn, $evidenceId)) {
    redirect_with_message('Invalid evidence selected.');
}

if ($score !== null && ($score < 0 || $score > 10)) {
    redirect_with_message('Evidence score must be between 0 and 10.');
}

if (!in_array($status, $allowedStatus, true)) {
    redirect_with_message('Invalid review status selected.');
}

$stmt = $conn->prepare("
    UPDATE startup_milestone_evidence
    SET reviewer_id = ?,
        reviewer_comments = ?,
        reviewer_score = ?,
        review_status = ?,
        reviewed_at = NOW()
    WHERE evidence_id = ?
");

if (!$stmt) {
    redirect_with_message('Failed to prepare evidence review query: ' . $conn->error);
}

$stmt->bind_param('isdsi', $userId, $comments, $score, $status, $evidenceId);

if ($stmt->execute()) {
    if (function_exists('log_action')) {
        log_action($userId, 'Review Startup Milestone Evidence', 'startup_milestone_evidence', $evidenceId, 'Reviewed startup milestone evidence');
    }
    $stmt->close();
    redirect_with_message('Evidence reviewed successfully.', 'success');
}

$error = $stmt->error ?: $conn->error;
$stmt->close();
redirect_with_message('Error reviewing evidence: ' . $error);
