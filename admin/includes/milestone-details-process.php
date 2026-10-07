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
    'Project Officer'
]);

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function redirect_with_message(string $message, string $type = 'danger', string $location = '../milestones.php'): void
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

function table_exists(mysqli $conn, string $table): bool
{
    $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");

    if (!$result) {
        return false;
    }

    $exists = $result->num_rows > 0;
    $result->close();

    return $exists;
}

function milestone_exists(mysqli $conn, int $milestoneId): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM milestones WHERE milestone_id = ? LIMIT 1");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $milestoneId);
    $stmt->execute();
    $stmt->store_result();

    $exists = $stmt->num_rows > 0;
    $stmt->close();

    return $exists;
}

function deliverable_belongs_to_milestone(mysqli $conn, int $deliverableId, int $milestoneId): bool
{
    $stmt = $conn->prepare("
        SELECT 1
        FROM milestone_deliverables
        WHERE deliverable_id = ?
          AND milestone_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ii', $deliverableId, $milestoneId);
    $stmt->execute();
    $stmt->store_result();

    $exists = $stmt->num_rows > 0;
    $stmt->close();

    return $exists;
}

function refresh_milestone_deliverables_summary(mysqli $conn, int $milestoneId): void
{
    if (!table_exists($conn, 'milestone_deliverables')) {
        return;
    }

    $items = [];

    $stmt = $conn->prepare("
        SELECT deliverable_title, start_date, end_date, status, progress_percentage
        FROM milestone_deliverables
        WHERE milestone_id = ?
        ORDER BY COALESCE(start_date, end_date), deliverable_id
    ");

    if (!$stmt) {
        throw new RuntimeException('Failed to prepare deliverable summary query: ' . $conn->error);
    }

    $stmt->bind_param('i', $milestoneId);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'title'      => (string)($row['deliverable_title'] ?? ''),
                'start_date' => (string)($row['start_date'] ?? ''),
                'end_date'   => (string)($row['end_date'] ?? ''),
                'status'     => (string)($row['status'] ?? 'Pending'),
                'progress'   => (float)($row['progress_percentage'] ?? 0),
            ];
        }
    }

    $stmt->close();

    $summary = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $conn->prepare("UPDATE milestones SET deliverables = ? WHERE milestone_id = ?");

    if (!$stmt) {
        throw new RuntimeException('Failed to prepare milestone summary update: ' . $conn->error);
    }

    $stmt->bind_param('si', $summary, $milestoneId);

    if (!$stmt->execute()) {
        $error = $stmt->error ?: $conn->error;
        $stmt->close();

        throw new RuntimeException('Failed to refresh milestone deliverables summary: ' . $error);
    }

    $stmt->close();
}

function recalculate_milestone_progress_from_deliverables(mysqli $conn, int $milestoneId): void
{
    if (!table_exists($conn, 'milestone_deliverables')) {
        return;
    }

    $stmt = $conn->prepare("
        SELECT AVG(progress_percentage) AS avg_progress
        FROM milestone_deliverables
        WHERE milestone_id = ?
    ");

    if (!$stmt) {
        return;
    }

    $stmt->bind_param('i', $milestoneId);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    $stmt->close();

    if (!$row || $row['avg_progress'] === null) {
        return;
    }

    $avgProgress = max(0, min(100, (float)$row['avg_progress']));

    $status = 'In Progress';
    $completionDate = null;

    if ($avgProgress >= 100) {
        $avgProgress = 100.0;
        $status = 'Completed';
        $completionDate = date('Y-m-d');
    } elseif ($avgProgress <= 0) {
        $status = 'Not Started';
    }

    $stmt = $conn->prepare("
        UPDATE milestones
        SET progress_percentage = ?,
            status = CASE
                WHEN status IN ('Cancelled') THEN status
                ELSE ?
            END,
            completion_date = CASE
                WHEN status IN ('Cancelled') THEN completion_date
                ELSE ?
            END
        WHERE milestone_id = ?
    ");

    if (!$stmt) {
        return;
    }

    $stmt->bind_param('dssi', $avgProgress, $status, $completionDate, $milestoneId);
    $stmt->execute();
    $stmt->close();
}

function safe_upload_path(int $milestoneId, string $extension): array
{
    $baseDiskDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'milestones' . DIRECTORY_SEPARATOR . $milestoneId;
    $baseWebDir = 'uploads/milestones/' . $milestoneId;

    if (!is_dir($baseDiskDir)) {
        mkdir($baseDiskDir, 0755, true);
    }

    $filename = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;

    return [
        'disk_path' => $baseDiskDir . DIRECTORY_SEPARATOR . $filename,
        'web_path'  => $baseWebDir . '/' . $filename,
    ];
}

$userId = (int)($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../milestones.php');
    exit();
}

/*
|--------------------------------------------------------------------------
| Add Milestone Update
|--------------------------------------------------------------------------
*/
if (isset($_POST['add_update'])) {
    $milestoneId = (int)post_string('milestone_id', '0');
    $location = "../milestone-details.php?id={$milestoneId}";

    $updateType = clean_text(post_string('update_type'));
    $updateText = clean_text(post_string('update_text'));
    $progressPercentage = post_nullable_float('progress_percentage');

    $allowedTypes = ['General', 'Status Update', 'Achievement', 'Issue', 'Delay'];

    if ($milestoneId <= 0 || !milestone_exists($conn, $milestoneId)) {
        redirect_with_message('Milestone not found.', 'danger', '../milestones.php');
    }

    if ($updateType === null || !in_array($updateType, $allowedTypes, true)) {
        redirect_with_message('Please select a valid update type.', 'danger', $location);
    }

    if ($updateText === null) {
        redirect_with_message('Update details are required.', 'danger', $location);
    }

    if ($progressPercentage !== null && ($progressPercentage < 0 || $progressPercentage > 100)) {
        redirect_with_message('Progress percentage must be between 0 and 100.', 'danger', $location);
    }

    try {
        $conn->begin_transaction();

        $stmt = $conn->prepare("
            INSERT INTO milestone_updates (
                milestone_id,
                update_text,
                update_type,
                progress_percentage,
                created_by
            ) VALUES (?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            throw new RuntimeException('Failed to prepare update insert: ' . $conn->error);
        }

        $stmt->bind_param('issdi', $milestoneId, $updateText, $updateType, $progressPercentage, $userId);

        if (!$stmt->execute()) {
            $error = $stmt->error ?: $conn->error;
            $stmt->close();

            throw new RuntimeException('Error adding update: ' . $error);
        }

        $updateId = (int)$stmt->insert_id;
        $stmt->close();

        if ($progressPercentage !== null) {
            $completionDate = null;
            $status = null;

            if ($progressPercentage >= 100) {
                $progressPercentage = 100.0;
                $status = 'Completed';
                $completionDate = date('Y-m-d');
            } elseif ($progressPercentage <= 0) {
                $status = 'Not Started';
            } else {
                $status = 'In Progress';
            }

            $stmt = $conn->prepare("
                UPDATE milestones
                SET progress_percentage = ?,
                    status = CASE
                        WHEN status = 'Cancelled' THEN status
                        ELSE ?
                    END,
                    completion_date = CASE
                        WHEN status = 'Cancelled' THEN completion_date
                        ELSE ?
                    END
                WHERE milestone_id = ?
            ");

            if (!$stmt) {
                throw new RuntimeException('Failed to prepare milestone progress update: ' . $conn->error);
            }

            $stmt->bind_param('dssi', $progressPercentage, $status, $completionDate, $milestoneId);

            if (!$stmt->execute()) {
                $error = $stmt->error ?: $conn->error;
                $stmt->close();

                throw new RuntimeException('Error updating milestone progress: ' . $error);
            }

            $stmt->close();
        }

        if (function_exists('log_action')) {
            log_action(
                $userId,
                'Add Milestone Update',
                'milestone_updates',
                $updateId,
                "Added update to milestone ID: {$milestoneId}"
            );
        }

        $conn->commit();

        redirect_with_message('Update added successfully.', 'success', $location);
    } catch (Throwable $e) {
        $conn->rollback();
        redirect_with_message($e->getMessage(), 'danger', $location);
    }
}

/*
|--------------------------------------------------------------------------
| Update Deliverable
|--------------------------------------------------------------------------
*/
if (isset($_POST['update_deliverable'])) {
    $milestoneId = (int)post_string('milestone_id', '0');
    $deliverableId = (int)post_string('deliverable_id', '0');
    $location = "../milestone-details.php?id={$milestoneId}";

    if (!table_exists($conn, 'milestone_deliverables')) {
        redirect_with_message('milestone_deliverables table does not exist.', 'danger', $location);
    }

    if ($milestoneId <= 0 || !milestone_exists($conn, $milestoneId)) {
        redirect_with_message('Invalid milestone selected.', 'danger', '../milestones.php');
    }

    if ($deliverableId <= 0 || !deliverable_belongs_to_milestone($conn, $deliverableId, $milestoneId)) {
        redirect_with_message('Invalid deliverable selected.', 'danger', $location);
    }

    $title = clean_text(post_string('deliverable_title'));
    $startDate = post_nullable_string('start_date');
    $endDate = post_nullable_string('end_date');
    $completionDate = post_nullable_string('completion_date');
    $status = clean_text(post_string('status', 'Pending')) ?? 'Pending';
    $progress = post_nullable_float('progress_percentage') ?? 0.0;
    $responsiblePerson = post_nullable_string('responsible_person');
    $budgetAllocation = post_nullable_float('budget_allocation') ?? 0.0;
    $actualCost = post_nullable_float('actual_cost') ?? 0.0;
    $notes = post_nullable_string('notes');

    $allowedStatuses = ['Pending', 'In Progress', 'Completed', 'Delayed', 'Cancelled'];

    if ($title === null) {
        redirect_with_message('Deliverable title is required.', 'danger', $location);
    }

    if (!in_array($status, $allowedStatuses, true)) {
        redirect_with_message('Invalid deliverable status selected.', 'danger', $location);
    }

    if (!is_valid_date($startDate) || !is_valid_date($endDate) || !is_valid_date($completionDate)) {
        redirect_with_message('Invalid deliverable date supplied.', 'danger', $location);
    }

    if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
        redirect_with_message('Deliverable start date cannot be after end date.', 'danger', $location);
    }

    if ($completionDate !== null && $completionDate > date('Y-m-d')) {
        redirect_with_message('Completion date cannot be in the future.', 'danger', $location);
    }

    if ($progress < 0 || $progress > 100) {
        redirect_with_message('Deliverable progress must be between 0 and 100.', 'danger', $location);
    }

    if ($budgetAllocation < 0 || $actualCost < 0) {
        redirect_with_message('Budget and actual cost cannot be negative.', 'danger', $location);
    }

    if ($status === 'Completed') {
        $progress = 100.0;

        if ($completionDate === null) {
            $completionDate = date('Y-m-d');
        }
    } elseif ($status === 'Pending') {
        $progress = 0.0;
        $completionDate = null;
    } else {
        $completionDate = null;
    }

    try {
        $conn->begin_transaction();

        $stmt = $conn->prepare("
            UPDATE milestone_deliverables SET
                deliverable_title = ?,
                start_date = ?,
                end_date = ?,
                completion_date = ?,
                status = ?,
                progress_percentage = ?,
                responsible_person = ?,
                budget_allocation = ?,
                actual_cost = ?,
                notes = ?,
                updated_by = ?
            WHERE deliverable_id = ?
              AND milestone_id = ?
        ");

        if (!$stmt) {
            throw new RuntimeException('Failed to prepare deliverable update query: ' . $conn->error);
        }

        $stmt->bind_param(
            'sssssdsddsiii',
            $title,
            $startDate,
            $endDate,
            $completionDate,
            $status,
            $progress,
            $responsiblePerson,
            $budgetAllocation,
            $actualCost,
            $notes,
            $userId,
            $deliverableId,
            $milestoneId
        );

        if (!$stmt->execute()) {
            $error = $stmt->error ?: $conn->error;
            $stmt->close();

            throw new RuntimeException('Error updating deliverable: ' . $error);
        }

        $stmt->close();

        refresh_milestone_deliverables_summary($conn, $milestoneId);
        recalculate_milestone_progress_from_deliverables($conn, $milestoneId);

        if (function_exists('log_action')) {
            log_action(
                $userId,
                'Update Deliverable',
                'milestone_deliverables',
                $deliverableId,
                "Updated deliverable: {$title}"
            );
        }

        $conn->commit();

        redirect_with_message('Deliverable updated successfully.', 'success', $location);
    } catch (Throwable $e) {
        $conn->rollback();
        redirect_with_message($e->getMessage(), 'danger', $location);
    }
}

/*
|--------------------------------------------------------------------------
| Upload Milestone Document
|--------------------------------------------------------------------------
*/
if (isset($_POST['upload_document'])) {
    $milestoneId = (int)post_string('milestone_id', '0');
    $location = "../milestone-details.php?id={$milestoneId}";

    $documentName = clean_text(post_string('document_name'));

    if ($milestoneId <= 0 || !milestone_exists($conn, $milestoneId)) {
        redirect_with_message('Milestone not found.', 'danger', '../milestones.php');
    }

    if ($documentName === null) {
        redirect_with_message('Document name is required.', 'danger', $location);
    }

    if (
        !isset($_FILES['document_file'])
        || !is_array($_FILES['document_file'])
        || (int)($_FILES['document_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    ) {
        redirect_with_message('No file uploaded or upload error.', 'danger', $location);
    }

    $file = $_FILES['document_file'];
    $fileSize = (int)($file['size'] ?? 0);
    $tmpPath = (string)($file['tmp_name'] ?? '');
    $originalName = (string)($file['name'] ?? '');

    if ($fileSize <= 0) {
        redirect_with_message('Uploaded file is empty.', 'danger', $location);
    }

    if ($fileSize > 10485760) {
        redirect_with_message('File size must be less than 10MB.', 'danger', $location);
    }

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'jpg', 'jpeg', 'png', 'zip'];

    if (!in_array($extension, $allowedExtensions, true)) {
        redirect_with_message('File type not allowed.', 'danger', $location);
    }

    $paths = safe_upload_path($milestoneId, $extension);
    $diskPath = $paths['disk_path'];
    $webPath = $paths['web_path'];

    if (!move_uploaded_file($tmpPath, $diskPath)) {
        redirect_with_message('Error moving uploaded file.', 'danger', $location);
    }

    $documentType = function_exists('mime_content_type')
        ? (mime_content_type($diskPath) ?: 'application/octet-stream')
        : 'application/octet-stream';

    try {
        $stmt = $conn->prepare("
            INSERT INTO milestone_documents (
                milestone_id,
                document_name,
                document_path,
                document_type,
                file_size,
                uploaded_by
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            throw new RuntimeException('Failed to prepare document insert: ' . $conn->error);
        }

        $stmt->bind_param(
            'isssii',
            $milestoneId,
            $documentName,
            $webPath,
            $documentType,
            $fileSize,
            $userId
        );

        if (!$stmt->execute()) {
            $error = $stmt->error ?: $conn->error;
            $stmt->close();

            throw new RuntimeException('Error uploading document: ' . $error);
        }

        $documentId = (int)$stmt->insert_id;
        $stmt->close();

        if (function_exists('log_action')) {
            log_action(
                $userId,
                'Upload Milestone Document',
                'milestone_documents',
                $documentId,
                "Uploaded document: {$documentName} for milestone ID: {$milestoneId}"
            );
        }

        redirect_with_message('Document uploaded successfully.', 'success', $location);
    } catch (Throwable $e) {
        if (is_file($diskPath)) {
            unlink($diskPath);
        }

        redirect_with_message($e->getMessage(), 'danger', $location);
    }
}

header('Location: ../milestones.php');
exit();
