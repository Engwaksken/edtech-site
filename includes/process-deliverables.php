<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/deliverables-functions.php';

$action = trim(
    (string)(
        $_POST['action']
        ?? $_GET['action']
        ?? ''
    )
);

$adminActions = [
    'create_deliverable',
    'create_assignment',
    'create_bulk_assignments',
    'mentor_update',
];

$ventureActions = [
    'venture_submit',
    'submit_deliverable',
];

if (in_array($action, $adminActions, true)) {
    require_once __DIR__ . '/../admin/includes/auth.php';
} elseif (in_array($action, $ventureActions, true)) {
    require_once __DIR__ . '/auth.php';
} else {
    http_response_code(400);
    exit('Unknown deliverables action.');
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection error.');
}

$conn->set_charset('utf8mb4');

date_default_timezone_set('Africa/Kampala');
$conn->query("SET time_zone = '+03:00'");

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function dredirect(string $url, string $type, string $message): never
{
    $separator = str_contains($url, '?') ? '&' : '?';

    header(
        'Location: '
        . $url
        . $separator
        . http_build_query([
            'message_type' => $type,
            'message' => $message,
        ])
    );

    exit;
}

function deliverables_current_role(): string
{
    global $ADMIN;

    $roleCandidates = [
        $ADMIN['role'] ?? null,
        $_SESSION['role'] ?? null,
        $_SESSION['user_role'] ?? null,
        $_SESSION['admin_role'] ?? null,
        $_SESSION['role_name'] ?? null,
    ];

    foreach ($roleCandidates as $roleCandidate) {
        $value = strtolower(trim((string)$roleCandidate));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        $value = trim($value, '_');

        if ($value === '') {
            continue;
        }

        if (
            $value === 'admin'
            || str_contains($value, 'administrator')
            || str_contains($value, 'super_admin')
            || str_contains($value, 'system_admin')
        ) {
            return 'admin';
        }

        return match ($value) {
            'program_manager',
            'programme_manager',
            'programmanager',
            'program_manager_role' => 'program_manager',

            'business_consultant',
            'consultant_role' => 'consultant',

            'team_lead',
            'technical_lead',
            'program_lead',
            'programme_lead' => 'lead',

            default => $value,
        };
    }

    return '';
}

function deliverables_current_mentor_id(mysqli $conn): int
{
    global $ADMIN;

    $userId = deliverables_current_user_id();

    if ($userId > 0) {
        $stmt = $conn->prepare("
            SELECT id
            FROM mentors
            WHERE user_id = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                return (int)$row['id'];
            }
        }
    }

    $email = trim((string)($ADMIN['email'] ?? $_SESSION['email'] ?? ''));

    if ($email !== '') {
        $stmt = $conn->prepare("
            SELECT id
            FROM mentors
            WHERE email = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                return (int)$row['id'];
            }
        }
    }

    return 0;
}

function deliverables_assignment_roles(): array
{
    return [
        'admin',
        'consultant',
        'program_manager',
        'director',
        'lead',
        'mentor',
    ];
}

function deliverables_manager_roles(): array
{
    return [
        'admin',
        'consultant',
        'program_manager',
        'director',
        'lead',
    ];
}

function deliverables_can_create_assignment(): bool
{
    return in_array(
        deliverables_current_role(),
        deliverables_assignment_roles(),
        true
    );
}

function deliverables_can_manage_all(): bool
{
    return in_array(
        deliverables_current_role(),
        deliverables_manager_roles(),
        true
    );
}

function deliverables_current_user_id(): int
{
    global $ADMIN;

    return (int)(
        $ADMIN['id']
        ?? $_SESSION['admin_id']
        ?? $_SESSION['user_id']
        ?? 0
    );
}

function deliverables_valid_date(string $date): bool
{
    $value = DateTimeImmutable::createFromFormat('Y-m-d', $date);

    return $value !== false
        && $value->format('Y-m-d') === $date;
}

function deliverables_upload_error(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the server upload limit.',
        UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the form upload limit.',
        UPLOAD_ERR_PARTIAL => 'The file upload was interrupted.',
        UPLOAD_ERR_NO_FILE => 'No file was selected.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server temporary upload folder is missing.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
        default => 'The file upload failed.',
    };
}

function deliverables_log_activity(
    mysqli $conn,
    int $assignmentId,
    int $userId,
    string $action,
    ?string $oldStatus,
    ?string $newStatus,
    ?string $notes
): void {
    $stmt = $conn->prepare("
        INSERT INTO deliverable_activity_log (
            assignment_id,
            user_id,
            action,
            old_status,
            new_status,
            notes
        )
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        error_log(
            'Could not prepare deliverable activity log: '
            . $conn->error
        );
        return;
    }

    $stmt->bind_param(
        'iissss',
        $assignmentId,
        $userId,
        $action,
        $oldStatus,
        $newStatus,
        $notes
    );

    if (!$stmt->execute()) {
        error_log(
            'Could not save deliverable activity log: '
            . $stmt->error
        );
    }

    $stmt->close();
}

function deliverables_clean_ids(mixed $values): array
{
    if (!is_array($values)) {
        return [];
    }

    $ids = array_map('intval', $values);
    $ids = array_filter(
        $ids,
        static fn(int $id): bool => $id > 0
    );

    return array_values(array_unique($ids));
}

/*
|--------------------------------------------------------------------------
| Actions
|--------------------------------------------------------------------------
*/

switch ($action) {
    /*
    |--------------------------------------------------------------------------
    | CREATE VENTURE ASSIGNMENT
    |--------------------------------------------------------------------------
    */
    case 'create_deliverable': {
        if (!deliverables_can_create_assignment()) {
            dredirect(
                '../admin/mentor-deliverables.php',
                'error',
                'Your role is not permitted to create deliverables.'
            );
        }

        $name = mb_substr(
            trim((string)($_POST['name'] ?? '')),
            0,
            180
        );

        $theme = mb_substr(
            trim((string)($_POST['theme'] ?? '')),
            0,
            150
        );

        $description = mb_substr(
            trim((string)($_POST['description'] ?? '')),
            0,
            3000
        );

        $frequency = trim(
            (string)($_POST['frequency'] ?? 'once')
        );

        $expectedHoursInput = trim(
            (string)($_POST['expected_completion_hours'] ?? '')
        );

        $expectedHours = $expectedHoursInput !== ''
            ? (float)$expectedHoursInput
            : null;

        if ($name === '' || $theme === '') {
            dredirect(
                '../admin/mentor-deliverables.php',
                'error',
                'Deliverable name and theme are required.'
            );
        }

        $allowedFrequencies = [
            'once',
            'weekly',
            'biweekly',
            'monthly',
            'per_phase',
            'per_sprint',
            'custom',
        ];

        if (!in_array($frequency, $allowedFrequencies, true)) {
            $frequency = 'once';
        }

        if (
            $expectedHours !== null
            && ($expectedHours < 0 || $expectedHours > 9999)
        ) {
            dredirect(
                '../admin/mentor-deliverables.php',
                'error',
                'Expected completion hours are invalid.'
            );
        }

        $duplicate = $conn->prepare("
            SELECT id
            FROM deliverables_catalogue
            WHERE name = ?
              AND theme = ?
            LIMIT 1
        ");

        $duplicate->bind_param('ss', $name, $theme);
        $duplicate->execute();
        $exists = $duplicate->get_result()->fetch_assoc();
        $duplicate->close();

        if ($exists) {
            dredirect(
                '../admin/mentor-deliverables.php',
                'error',
                'A deliverable with the same name and theme already exists.'
            );
        }

        $templateName = null;
        $templatePath = null;
        $storedName = null;

        if (
            isset($_FILES['template_file'])
            && is_array($_FILES['template_file'])
        ) {
            $uploadError = (int)(
                $_FILES['template_file']['error']
                ?? UPLOAD_ERR_NO_FILE
            );

            if (
                $uploadError !== UPLOAD_ERR_OK
                && $uploadError !== UPLOAD_ERR_NO_FILE
            ) {
                dredirect(
                    '../admin/mentor-deliverables.php',
                    'error',
                    deliverables_upload_error($uploadError)
                );
            }

            if ($uploadError === UPLOAD_ERR_OK) {
                $templateName = basename(
                    str_replace(
                        '\\',
                        '/',
                        (string)($_FILES['template_file']['name'] ?? '')
                    )
                );

                $extension = strtolower(
                    pathinfo($templateName, PATHINFO_EXTENSION)
                );

                $allowedExtensions = [
                    'pdf',
                    'doc',
                    'docx',
                    'xls',
                    'xlsx',
                    'ppt',
                    'pptx',
                ];

                if (!in_array($extension, $allowedExtensions, true)) {
                    dredirect(
                        '../admin/mentor-deliverables.php',
                        'error',
                        'Unsupported template file type.'
                    );
                }

                $temporaryFile = (string)(
                    $_FILES['template_file']['tmp_name']
                    ?? ''
                );

                $size = $temporaryFile !== ''
                    ? filesize($temporaryFile)
                    : false;

                if (
                    $temporaryFile === ''
                    || !is_uploaded_file($temporaryFile)
                    || $size === false
                    || $size <= 0
                    || $size > 20 * 1024 * 1024
                ) {
                    dredirect(
                        '../admin/mentor-deliverables.php',
                        'error',
                        'Template upload is invalid or exceeds 20 MB.'
                    );
                }

                $directory = __DIR__
                    . '/../uploads/deliverable-templates/';

                if (
                    !is_dir($directory)
                    && !mkdir($directory, 0755, true)
                ) {
                    dredirect(
                        '../admin/mentor-deliverables.php',
                        'error',
                        'Could not create the template upload directory.'
                    );
                }

                $storedName = 'template_'
                    . bin2hex(random_bytes(10))
                    . '.'
                    . $extension;

                if (
                    !move_uploaded_file(
                        $temporaryFile,
                        $directory . $storedName
                    )
                ) {
                    dredirect(
                        '../admin/mentor-deliverables.php',
                        'error',
                        'Could not save the template file.'
                    );
                }

                $templatePath = '../uploads/deliverable-templates/'
                    . $storedName;
            }
        }

        $descriptionValue = $description !== ''
            ? $description
            : null;

        $createdBy = deliverables_current_user_id();

        $stmt = $conn->prepare("
            INSERT INTO deliverables_catalogue (
                name,
                description,
                theme,
                template_name,
                template_path,
                frequency,
                expected_completion_hours,
                is_active,
                created_by
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)
        ");

        if (!$stmt) {
            dredirect(
                '../admin/mentor-deliverables.php',
                'error',
                'Could not prepare deliverable creation: '
                . $conn->error
            );
        }

        $stmt->bind_param(
            'ssssssdi',
            $name,
            $descriptionValue,
            $theme,
            $templateName,
            $templatePath,
            $frequency,
            $expectedHours,
            $createdBy
        );

        if (!$stmt->execute()) {
            if ($storedName !== null) {
                @unlink(
                    __DIR__
                    . '/../uploads/deliverable-templates/'
                    . $storedName
                );
            }

            $error = $stmt->error;
            $stmt->close();

            dredirect(
                '../admin/mentor-deliverables.php',
                'error',
                'Deliverable creation failed: ' . $error
            );
        }

        $stmt->close();

        dredirect(
            '../admin/mentor-deliverables.php',
            'success',
            'Deliverable created successfully.'
        );
    }

    case 'create_assignment':
    case 'create_bulk_assignments': {
        if (!deliverables_can_create_assignment()) {
            dredirect(
                '../admin/mentor-deliverables.php',
                'error',
                'Your role is not permitted to create assignments.'
            );
        }

        $catalogueId = (int)($_POST['catalogue_id'] ?? 0);

        $ventureIds = deliverables_clean_ids(
            $_POST['venture_ids']
            ?? (
                isset($_POST['venture_id'])
                    ? [$_POST['venture_id']]
                    : []
            )
        );

        $mentorIds = deliverables_clean_ids(
            $_POST['mentor_ids']
            ?? (
                isset($_POST['mentor_id'])
                    ? [$_POST['mentor_id']]
                    : []
            )
        );

        $cycleId = (int)($_POST['cycle_id'] ?? 0);
        $cohortOverride = (int)($_POST['cohort_id'] ?? 0);

        $assignedDate = trim(
            (string)($_POST['assigned_date'] ?? '')
        );

        $dueDate = trim(
            (string)($_POST['due_date'] ?? '')
        );

        $reminderDate = trim(
            (string)($_POST['reminder_date'] ?? '')
        );

        $escalationDate = trim(
            (string)($_POST['escalation_date'] ?? '')
        );

        $priority = trim(
            (string)($_POST['priority'] ?? 'medium')
        );

        $status = trim(
            (string)($_POST['status'] ?? 'not_started')
        );

        $notes = mb_substr(
            trim((string)($_POST['notes'] ?? '')),
            0,
            2000
        );

        if (
            $catalogueId <= 0
            || !$ventureIds
            || !$mentorIds
            || !deliverables_valid_date($assignedDate)
            || !deliverables_valid_date($dueDate)
        ) {
            dredirect(
                '../admin/mentor-deliverables.php',
                'error',
                'Select a deliverable, at least one venture, at least one mentor, and valid dates.'
            );
        }

        if ($dueDate < $assignedDate) {
            dredirect(
                '../admin/mentor-deliverables.php',
                'error',
                'The due date cannot be before the assigned date.'
            );
        }

        if (
            $reminderDate !== ''
            && (
                !deliverables_valid_date($reminderDate)
                || $reminderDate > $dueDate
            )
        ) {
            dredirect(
                '../admin/mentor-deliverables.php',
                'error',
                'Reminder date must be valid and cannot be after the due date.'
            );
        }

        if (
            $escalationDate !== ''
            && (
                !deliverables_valid_date($escalationDate)
                || $escalationDate < $dueDate
            )
        ) {
            dredirect(
                '../admin/mentor-deliverables.php',
                'error',
                'Escalation date must be valid and cannot be before the due date.'
            );
        }

        if (!in_array($priority, ['low','medium','high','critical'], true)) {
            $priority = 'medium';
        }

        if (!in_array($status, ['not_started','in_progress'], true)) {
            $status = 'not_started';
        }

        if (!deliverables_can_manage_all()) {
            $currentMentorId = deliverables_current_mentor_id($conn);

            if ($currentMentorId <= 0) {
                dredirect(
                    '../admin/mentor-deliverables.php',
                    'error',
                    'Your account is not linked to an active mentor.'
                );
            }

            $mentorIds = [$currentMentorId];
        }

        $catalogueCheck = $conn->prepare("
            SELECT id
            FROM deliverables_catalogue
            WHERE id = ?
              AND is_active = 1
            LIMIT 1
        ");

        $catalogueCheck->bind_param('i', $catalogueId);
        $catalogueCheck->execute();
        $catalogueExists = $catalogueCheck
            ->get_result()
            ->fetch_assoc();
        $catalogueCheck->close();

        if (!$catalogueExists) {
            dredirect(
                '../admin/mentor-deliverables.php',
                'error',
                'The selected deliverable does not exist.'
            );
        }

        $venturePlaceholders = implode(
            ',',
            array_fill(0, count($ventureIds), '?')
        );

        $ventureTypes = str_repeat('i', count($ventureIds));

        $ventureStmt = $conn->prepare("
            SELECT id, cohort_id
            FROM ventures
            WHERE id IN ({$venturePlaceholders})
        ");

        $ventureStmt->bind_param(
            $ventureTypes,
            ...$ventureIds
        );

        $ventureStmt->execute();
        $ventureRows = $ventureStmt
            ->get_result()
            ->fetch_all(MYSQLI_ASSOC);
        $ventureStmt->close();

        $ventureMap = [];

        foreach ($ventureRows as $ventureRow) {
            $ventureMap[(int)$ventureRow['id']] = (int)(
                $ventureRow['cohort_id']
                ?? 0
            );
        }

        if (count($ventureMap) !== count($ventureIds)) {
            dredirect(
                '../admin/mentor-deliverables.php',
                'error',
                'One or more selected ventures were not found.'
            );
        }

        $mentorPlaceholders = implode(
            ',',
            array_fill(0, count($mentorIds), '?')
        );

        $mentorTypes = str_repeat('i', count($mentorIds));

        $mentorStmt = $conn->prepare("
            SELECT id
            FROM mentors
            WHERE id IN ({$mentorPlaceholders})
              AND status = 'active'
        ");

        $mentorStmt->bind_param(
            $mentorTypes,
            ...$mentorIds
        );

        $mentorStmt->execute();
        $mentorRows = $mentorStmt
            ->get_result()
            ->fetch_all(MYSQLI_ASSOC);
        $mentorStmt->close();

        $validMentorIds = array_map(
            static fn(array $row): int => (int)$row['id'],
            $mentorRows
        );

        sort($validMentorIds);
        $expectedMentorIds = $mentorIds;
        sort($expectedMentorIds);

        if ($validMentorIds !== $expectedMentorIds) {
            dredirect(
                '../admin/mentor-deliverables.php',
                'error',
                'One or more selected mentors were not found or are inactive.'
            );
        }

        $assignedBy = deliverables_current_user_id();
        $cycleValue = $cycleId > 0 ? $cycleId : null;
        $reminderValue = $reminderDate !== ''
            ? $reminderDate
            : null;
        $escalationValue = $escalationDate !== ''
            ? $escalationDate
            : null;
        $notesValue = $notes !== ''
            ? $notes
            : null;
        $progress = $status === 'in_progress'
            ? 1
            : 0;

        $created = 0;
        $skipped = 0;

        $conn->begin_transaction();

        try {
            $duplicateStmt = $conn->prepare("
                SELECT id
                FROM deliverable_assignments
                WHERE catalogue_id = ?
                  AND venture_id = ?
                  AND mentor_id = ?
                  AND due_date = ?
                  AND status NOT IN ('completed','approved')
                LIMIT 1
            ");

            $insertStmt = $conn->prepare("
                INSERT INTO deliverable_assignments (
                    catalogue_id,
                    cycle_id,
                    cohort_id,
                    venture_id,
                    mentor_id,
                    assigned_by,
                    assigned_date,
                    due_date,
                    reminder_date,
                    escalation_date,
                    priority,
                    status,
                    progress_percent,
                    submission_notes
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$duplicateStmt || !$insertStmt) {
                throw new RuntimeException(
                    'Could not prepare bulk assignment statements: '
                    . $conn->error
                );
            }

            foreach ($ventureIds as $ventureId) {
                $cohortValue = $cohortOverride > 0
                    ? $cohortOverride
                    : (
                        ($ventureMap[$ventureId] ?? 0) > 0
                            ? $ventureMap[$ventureId]
                            : null
                    );

                foreach ($mentorIds as $mentorId) {
                    $duplicateStmt->bind_param(
                        'iiis',
                        $catalogueId,
                        $ventureId,
                        $mentorId,
                        $dueDate
                    );

                    $duplicateStmt->execute();
                    $duplicate = $duplicateStmt
                        ->get_result()
                        ->fetch_assoc();

                    if ($duplicate) {
                        $skipped++;
                        continue;
                    }

                    $insertStmt->bind_param(
                        'iiiiiissssssis',
                        $catalogueId,
                        $cycleValue,
                        $cohortValue,
                        $ventureId,
                        $mentorId,
                        $assignedBy,
                        $assignedDate,
                        $dueDate,
                        $reminderValue,
                        $escalationValue,
                        $priority,
                        $status,
                        $progress,
                        $notesValue
                    );

                    if (!$insertStmt->execute()) {
                        throw new RuntimeException(
                            'Could not create assignment: '
                            . $insertStmt->error
                        );
                    }

                    $assignmentId = (int)$insertStmt->insert_id;

                    deliverables_log_activity(
                        $conn,
                        $assignmentId,
                        $assignedBy,
                        'assignment_created',
                        null,
                        $status,
                        $notesValue
                    );

                    $created++;
                }
            }

            $duplicateStmt->close();
            $insertStmt->close();

            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();

            error_log(
                'Bulk assignment creation failed: '
                . $exception->getMessage()
            );

            dredirect(
                '../admin/mentor-deliverables.php',
                'error',
                'Assignment creation failed: '
                . $exception->getMessage()
            );
        }

        dredirect(
            '../admin/mentor-deliverables.php',
            'success',
            $created
            . ' assignment(s) created. '
            . $skipped
            . ' duplicate assignment(s) skipped.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | MENTOR / MANAGER PROGRESS UPDATE
    |--------------------------------------------------------------------------
    */
    case 'mentor_update': {
        $assignmentId = (int)(
            $_POST['assignment_id']
            ?? 0
        );

        $status = trim(
            (string)(
                $_POST['status']
                ?? ''
            )
        );

        $progress = max(
            0,
            min(
                100,
                (int)(
                    $_POST['progress_percent']
                    ?? 0
                )
            )
        );

        $notes = trim(
            (string)(
                $_POST['notes']
                ?? ''
            )
        );

        $allowedStatuses = [
            'not_started',
            'in_progress',
            'returned_for_revision',
            'completed',
        ];

        if (
            $assignmentId <= 0
            || !in_array(
                $status,
                $allowedStatuses,
                true
            )
        ) {
            dredirect(
                '../admin/mentor-deliverables.php',
                'error',
                'Invalid deliverable update.'
            );
        }

        $currentUserId = deliverables_current_user_id();
        $currentMentorId = deliverables_current_mentor_id($conn);

        $stmt = $conn->prepare("
            SELECT
                id,
                mentor_id,
                status
            FROM deliverable_assignments
            WHERE id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            dredirect(
                '../admin/mentor-deliverables.php',
                'error',
                'Could not validate the deliverable.'
            );
        }

        $stmt->bind_param(
            'i',
            $assignmentId
        );

        $stmt->execute();
        $assignment = $stmt
            ->get_result()
            ->fetch_assoc();
        $stmt->close();

        if (!$assignment) {
            dredirect(
                '../admin/mentor-deliverables.php',
                'error',
                'Deliverable assignment not found.'
            );
        }

        $canUpdate = deliverables_can_manage_all()
            || (
                $currentMentorId > 0
                && (int)$assignment['mentor_id']
                    === $currentMentorId
            );

        if (!$canUpdate) {
            dredirect(
                '../admin/mentor-deliverables.php',
                'error',
                'You are not permitted to update this deliverable.'
            );
        }

        if ($status === 'completed') {
            $progress = 100;
        }

        if ($status === 'not_started') {
            $progress = 0;
        }

        $oldStatus = (string)$assignment['status'];
        $notesValue = $notes !== ''
            ? $notes
            : null;

        $conn->begin_transaction();

        try {
            $update = $conn->prepare("
                UPDATE deliverable_assignments
                SET
                    status = ?,
                    progress_percent = ?,
                    submission_notes = ?,
                    completed_at = CASE
                        WHEN ? = 'completed'
                            THEN COALESCE(completed_at, NOW())
                        ELSE NULL
                    END
                WHERE id = ?
            ");

            if (!$update) {
                throw new RuntimeException(
                    'Could not prepare deliverable update: '
                    . $conn->error
                );
            }

            $update->bind_param(
                'sissi',
                $status,
                $progress,
                $notesValue,
                $status,
                $assignmentId
            );

            if (!$update->execute()) {
                throw new RuntimeException(
                    'Could not update deliverable: '
                    . $update->error
                );
            }

            $update->close();

            deliverables_log_activity(
                $conn,
                $assignmentId,
                $currentUserId,
                'progress_updated',
                $oldStatus,
                $status,
                $notesValue
            );

            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();

            error_log(
                'Deliverable update failed: '
                . $exception->getMessage()
            );

            dredirect(
                '../admin/mentor-deliverables.php',
                'error',
                'Deliverable could not be updated.'
            );
        }

        dredirect(
            '../admin/mentor-deliverables.php',
            'success',
            'Deliverable updated successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | VENTURE SUBMISSION
    |--------------------------------------------------------------------------
    */
    case 'venture_submit':
    case 'submit_deliverable': {
        $assignmentId = (int)(
            $_POST['assignment_id']
            ?? 0
        );

        $ventureId = (int)(
            $_SESSION['venture_id']
            ?? 0
        );

        $notes = trim(
            (string)(
                $_POST['submission_notes']
                ?? ''
            )
        );

        if (
            $assignmentId <= 0
            || $ventureId <= 0
        ) {
            dredirect(
                '../deliverables.php',
                'error',
                'Invalid deliverable submission.'
            );
        }

        $stmt = $conn->prepare("
            SELECT
                id,
                status,
                submission_file_path
            FROM deliverable_assignments
            WHERE id = ?
              AND venture_id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            dredirect(
                '../deliverables.php',
                'error',
                'Could not validate the deliverable.'
            );
        }

        $stmt->bind_param(
            'ii',
            $assignmentId,
            $ventureId
        );

        $stmt->execute();
        $assignment = $stmt
            ->get_result()
            ->fetch_assoc();
        $stmt->close();

        if (!$assignment) {
            dredirect(
                '../deliverables.php',
                'error',
                'This deliverable does not belong to your venture.'
            );
        }

        if (
            in_array(
                (string)$assignment['status'],
                ['approved', 'completed'],
                true
            )
        ) {
            dredirect(
                '../deliverables.php',
                'error',
                'This deliverable has already been approved or completed.'
            );
        }

        $originalName = null;
        $storedName = null;

        $upload = $_FILES['submission_file']
            ?? null;

        if (is_array($upload)) {
            $uploadError = (int)(
                $upload['error']
                ?? UPLOAD_ERR_NO_FILE
            );

            if (
                $uploadError !== UPLOAD_ERR_OK
                && $uploadError !== UPLOAD_ERR_NO_FILE
            ) {
                dredirect(
                    '../deliverables.php',
                    'error',
                    deliverables_upload_error(
                        $uploadError
                    )
                );
            }

            if ($uploadError === UPLOAD_ERR_OK) {
                $originalName = basename(
                    str_replace(
                        '\\',
                        '/',
                        (string)(
                            $upload['name']
                            ?? ''
                        )
                    )
                );

                $extension = strtolower(
                    pathinfo(
                        $originalName,
                        PATHINFO_EXTENSION
                    )
                );

                $allowedExtensions = [
                    'pdf',
                    'doc',
                    'docx',
                    'xls',
                    'xlsx',
                    'ppt',
                    'pptx',
                    'jpg',
                    'jpeg',
                    'png',
                ];

                if (
                    !in_array(
                        $extension,
                        $allowedExtensions,
                        true
                    )
                ) {
                    dredirect(
                        '../deliverables.php',
                        'error',
                        'Unsupported file type.'
                    );
                }

                $temporaryFile = (string)(
                    $upload['tmp_name']
                    ?? ''
                );

                if (
                    $temporaryFile === ''
                    || !is_uploaded_file(
                        $temporaryFile
                    )
                ) {
                    dredirect(
                        '../deliverables.php',
                        'error',
                        'The uploaded file is invalid.'
                    );
                }

                $actualSize = filesize(
                    $temporaryFile
                );

                $maximumSize = 20 * 1024 * 1024;

                if (
                    $actualSize === false
                    || $actualSize <= 0
                ) {
                    dredirect(
                        '../deliverables.php',
                        'error',
                        'The uploaded file is empty.'
                    );
                }

                if ($actualSize > $maximumSize) {
                    dredirect(
                        '../deliverables.php',
                        'error',
                        'The uploaded file exceeds the 20 MB limit.'
                    );
                }

                $directory = __DIR__
                    . '/../uploads/deliverables/';

                if (
                    !is_dir($directory)
                    && !mkdir(
                        $directory,
                        0755,
                        true
                    )
                ) {
                    dredirect(
                        '../deliverables.php',
                        'error',
                        'Could not create the deliverables upload directory.'
                    );
                }

                if (!is_writable($directory)) {
                    dredirect(
                        '../deliverables.php',
                        'error',
                        'The deliverables upload directory is not writable.'
                    );
                }

                $storedName = 'deliverable_'
                    . $assignmentId
                    . '_'
                    . bin2hex(
                        random_bytes(8)
                    )
                    . '.'
                    . $extension;

                if (
                    !move_uploaded_file(
                        $temporaryFile,
                        $directory . $storedName
                    )
                ) {
                    dredirect(
                        '../deliverables.php',
                        'error',
                        'Could not save the uploaded file.'
                    );
                }
            }
        }

        if (
            $notes === ''
            && $storedName === null
        ) {
            dredirect(
                '../deliverables.php',
                'error',
                'Add submission notes or attach a file.'
            );
        }

        $oldStatus = (string)$assignment['status'];
        $notesValue = $notes !== ''
            ? $notes
            : null;

        $conn->begin_transaction();

        try {
            $update = $conn->prepare("
                UPDATE deliverable_assignments
                SET
                    status = 'submitted',
                    progress_percent = 100,
                    submission_notes = ?,
                    submission_file_name = COALESCE(?, submission_file_name),
                    submission_file_path = COALESCE(?, submission_file_path),
                    submitted_at = NOW()
                WHERE id = ?
                  AND venture_id = ?
            ");

            if (!$update) {
                throw new RuntimeException(
                    'Could not prepare submission update: '
                    . $conn->error
                );
            }

            $update->bind_param(
                'sssii',
                $notesValue,
                $originalName,
                $storedName,
                $assignmentId,
                $ventureId
            );

            if (!$update->execute()) {
                throw new RuntimeException(
                    'Could not submit deliverable: '
                    . $update->error
                );
            }

            $update->close();

            deliverables_log_activity(
                $conn,
                $assignmentId,
                deliverables_current_user_id(),
                'venture_submitted',
                $oldStatus,
                'submitted',
                $notesValue
            );

            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();

            if (
                $storedName !== null
                && file_exists(
                    __DIR__
                    . '/../uploads/deliverables/'
                    . $storedName
                )
            ) {
                @unlink(
                    __DIR__
                    . '/../uploads/deliverables/'
                    . $storedName
                );
            }

            error_log(
                'Deliverable submission failed: '
                . $exception->getMessage()
            );

            dredirect(
                '../deliverables.php',
                'error',
                'Deliverable could not be submitted.'
            );
        }

        dredirect(
            '../deliverables.php',
            'success',
            'Deliverable submitted successfully.'
        );
    }

    default:
        http_response_code(400);
        exit('Unknown deliverables action.');
}
