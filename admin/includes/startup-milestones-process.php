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

function redirect_with_message(string $message, string $type = 'danger', string $location = '../startup-milestones.php'): void
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

function post_nullable_string(string $key): ?string
{
    return isset($_POST[$key]) ? clean_text((string)$_POST[$key]) : null;
}

function post_nullable_float(string $key): ?float
{
    if (!isset($_POST[$key])) {
        return null;
    }

    $value = trim((string)$_POST[$key]);

    return $value === '' ? null : (float)$value;
}

function is_valid_date(?string $date): bool
{
    if ($date === null || $date === '') {
        return true;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $date);

    return $dt instanceof DateTime && $dt->format('Y-m-d') === $date;
}


function get_review_application_id(mysqli $conn, int $reviewId): ?int
{
    $stmt = $conn->prepare("
        SELECT application_id
        FROM application_reviews
        WHERE review_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $reviewId);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    $stmt->close();

    if (!$row || empty($row['application_id'])) {
        return null;
    }

    return (int)$row['application_id'];
}

$userId = (int)($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../startup-milestones.php');
    exit();
}

/*
|--------------------------------------------------------------------------
| Save Startup Milestone
|--------------------------------------------------------------------------
*/
if (isset($_POST['save_startup_milestone'])) {
    $milestoneId = (int)post_string('milestone_id', '0');
    $reviewId = (int)post_string('review_id', '0');

    $applicationId = $reviewId > 0 ? get_review_application_id($conn, $reviewId) : null;

    $title = clean_text(post_string('milestone_title'));
    $description = post_nullable_string('milestone_description');
    $type = clean_text(post_string('milestone_type', 'General')) ?? 'General';
    $startDate = post_nullable_string('start_date');
    $dueDate = post_nullable_string('due_date');
    $completionDate = post_nullable_string('completion_date');
    $status = clean_text(post_string('status', 'Not Started')) ?? 'Not Started';
    $progress = post_nullable_float('progress_percentage') ?? 0.0;
    $responsible = post_nullable_string('responsible_person');
    $priority = clean_text(post_string('priority', 'Medium')) ?? 'Medium';
    $notes = post_nullable_string('notes');

    $allowedStatuses = ['Not Started', 'In Progress', 'Completed', 'Delayed', 'Cancelled'];
    $allowedTypes = [
        'Equity',
        'Video Intro',
        'Problem Depth TOC',
        'Product Quality Pedagogy',
        'Product Demo Link',
        'Scalability Traction Sustainability',
        'Team Capability Commitment',
        'URSB Registration',
        'General'
    ];
    $allowedPriorities = ['Low', 'Medium', 'High', 'Critical'];

    if ($title === null) {
        redirect_with_message('Milestone title is required.');
    }

    if ($reviewId <= 0 || $applicationId === null || $applicationId <= 0) {
        redirect_with_message('Please select a valid application review.');
    }

    if (!in_array($status, $allowedStatuses, true)) {
        redirect_with_message('Invalid status selected.');
    }

    if (!in_array($type, $allowedTypes, true)) {
        redirect_with_message('Invalid review area selected.');
    }

    if (!in_array($priority, $allowedPriorities, true)) {
        redirect_with_message('Invalid priority selected.');
    }

    if (!is_valid_date($startDate) || !is_valid_date($dueDate) || !is_valid_date($completionDate)) {
        redirect_with_message('Invalid date supplied.');
    }

    if ($startDate !== null && $dueDate !== null && $startDate > $dueDate) {
        redirect_with_message('Start date cannot be after due date.');
    }

    if ($completionDate !== null && $completionDate > date('Y-m-d')) {
        redirect_with_message('Completion date cannot be in the future.');
    }

    if ($progress < 0 || $progress > 100) {
        redirect_with_message('Progress must be between 0 and 100.');
    }

    if ($status === 'Completed') {
        $progress = 100.0;

        if ($completionDate === null) {
            $completionDate = date('Y-m-d');
        }
    } elseif ($status === 'Not Started') {
        $progress = 0.0;
        $completionDate = null;
    } else {
        $completionDate = null;
    }

    if ($milestoneId > 0) {
        $stmt = $conn->prepare("
            UPDATE startup_milestones SET
                application_id = ?,
                review_id = ?,
                milestone_title = ?,
                milestone_description = ?,
                milestone_type = ?,
                start_date = ?,
                due_date = ?,
                completion_date = ?,
                status = ?,
                progress_percentage = ?,
                responsible_person = ?,
                priority = ?,
                notes = ?,
                updated_by = ?
            WHERE milestone_id = ?
        ");

        if (!$stmt) {
            redirect_with_message('Failed to prepare update query: ' . $conn->error);
        }

        $stmt->bind_param(
            'iisssssssdsssii',
            $applicationId,
            $reviewId,
            $title,
            $description,
            $type,
            $startDate,
            $dueDate,
            $completionDate,
            $status,
            $progress,
            $responsible,
            $priority,
            $notes,
            $userId,
            $milestoneId
        );

        if ($stmt->execute()) {
            if (function_exists('log_action')) {
                log_action(
                    $userId,
                    'Update Startup Milestone',
                    'startup_milestones',
                    $milestoneId,
                    "Updated startup milestone: {$title}"
                );
            }

            $stmt->close();
            redirect_with_message('Startup milestone updated successfully.', 'success');
        }

        $error = $stmt->error ?: $conn->error;
        $stmt->close();

        redirect_with_message('Error updating startup milestone: ' . $error);
    }

    $stmt = $conn->prepare("
        INSERT INTO startup_milestones (
            application_id,
            review_id,
            milestone_title,
            milestone_description,
            milestone_type,
            start_date,
            due_date,
            completion_date,
            status,
            progress_percentage,
            responsible_person,
            priority,
            notes,
            created_by,
            updated_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        redirect_with_message('Failed to prepare insert query: ' . $conn->error);
    }

    $stmt->bind_param(
        'iisssssssdsssii',
        $applicationId,
        $reviewId,
        $title,
        $description,
        $type,
        $startDate,
        $dueDate,
        $completionDate,
        $status,
        $progress,
        $responsible,
        $priority,
        $notes,
        $userId,
        $userId
    );

    if ($stmt->execute()) {
        $newId = (int)$stmt->insert_id;

        if (function_exists('log_action')) {
            log_action(
                $userId,
                'Add Startup Milestone',
                'startup_milestones',
                $newId,
                "Added startup milestone: {$title}"
            );
        }

        $stmt->close();
        redirect_with_message('Startup milestone added successfully.', 'success');
    }

    $error = $stmt->error ?: $conn->error;
    $stmt->close();

    redirect_with_message('Error adding startup milestone: ' . $error);
}

/*
|--------------------------------------------------------------------------
| Delete Startup Milestone
|--------------------------------------------------------------------------
*/
if (isset($_POST['delete_startup_milestone'])) {
    $milestoneId = (int)post_string('milestone_id', '0');

    if ($milestoneId <= 0) {
        redirect_with_message('Invalid milestone selected.');
    }

    $stmt = $conn->prepare("DELETE FROM startup_milestones WHERE milestone_id = ?");

    if (!$stmt) {
        redirect_with_message('Failed to prepare delete query: ' . $conn->error);
    }

    $stmt->bind_param('i', $milestoneId);

    if ($stmt->execute()) {
        if (function_exists('log_action')) {
            log_action(
                $userId,
                'Delete Startup Milestone',
                'startup_milestones',
                $milestoneId,
                "Deleted startup milestone ID: {$milestoneId}"
            );
        }

        $stmt->close();
        redirect_with_message('Startup milestone deleted successfully.', 'success');
    }

    $error = $stmt->error ?: $conn->error;
    $stmt->close();

    redirect_with_message('Error deleting startup milestone: ' . $error);
}

header('Location: ../startup-milestones.php');
exit();
