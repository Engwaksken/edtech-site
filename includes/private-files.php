<?php
declare(strict_types=1);

function site_private_group(string $path): ?string
{
    foreach (['ventures/docs', 'ventures/meal-documents', 'loa-signed', 'loa', 'tranche-evidence', 'deliverables',
        'mentor-reports', 'structured-reports', 'session-reports', 'resources', 'responses', 'startup-evidence', 'startup-field-visits'] as $group) {
        if (str_starts_with($path, 'uploads/' . $group . '/')) {
            return $group;
        }
    }
    return null;
}

function site_private_resolve(string $path, string $root): ?string
{
    if (site_private_group($path) === null || preg_match('~[\\\\:\x00-\x1f%]~', $path)) {
        return null;
    }
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part[0] === '.') {
            return null;
        }
    }
    $uploadRoot = realpath($root . '/uploads');
    $file = realpath($root . '/' . $path);
    $inside = $uploadRoot !== false && $file !== false && (PHP_OS_FAMILY === 'Windows'
        ? strncasecmp($file, $uploadRoot . DIRECTORY_SEPARATOR, strlen($uploadRoot) + 1) === 0
        : strncmp($file, $uploadRoot . DIRECTORY_SEPARATOR, strlen($uploadRoot) + 1) === 0);
    if ($uploadRoot === false || $file === false || !is_file($file)
        || !$inside) {
        return null;
    }
    $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'zip', 'txt', 'csv'];
    return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $allowed, true) ? $file : null;
}

function site_private_record(mysqli $conn, string $path): ?array
{
    $group = site_private_group($path);
    $queries = [
        'ventures/docs' => 'SELECT venture_id FROM venture_documents WHERE file_path IN (?, ?, ?) LIMIT 1',
        'ventures/meal-documents' => 'SELECT venture_id FROM meal_supporting_documents WHERE file_path IN (?, ?, ?) LIMIT 1',
        'loa' => 'SELECT venture_id FROM loa_agreements WHERE generated_file_path IN (?, ?, ?) LIMIT 1',
        'loa-signed' => 'SELECT venture_id FROM loa_agreements WHERE signed_file_path IN (?, ?, ?) LIMIT 1',
        'tranche-evidence' => 'SELECT s.venture_id FROM tranche_submission_files f JOIN tranche_submissions s ON s.id=f.submission_id WHERE f.file_path IN (?, ?, ?) LIMIT 1',
        'deliverables' => 'SELECT venture_id, mentor_id FROM deliverable_assignments WHERE submission_file_path IN (?, ?, ?) LIMIT 1',
        'mentor-reports' => 'SELECT mentor_id FROM mentor_reports WHERE file_path IN (?, ?, ?) LIMIT 1',
        'structured-reports' => 'SELECT mentor_id FROM structured_reports WHERE pdf_path IN (?, ?, ?) LIMIT 1',
        'session-reports' => 'SELECT session_id FROM session_reports WHERE file_path IN (?, ?, ?) LIMIT 1',
        'resources' => "SELECT id AS resource_id, access_level FROM resources WHERE file_path IN (?, ?, ?) AND status='active' LIMIT 1",
        'startup-evidence' => 'SELECT submitted_by AS admin_owner FROM startup_milestone_evidence WHERE evidence_file IN (?, ?, ?) LIMIT 1',
        'startup-field-visits' => 'SELECT visit_id FROM startup_field_visits WHERE report_file IN (?, ?, ?) OR field_team_signature IN (?, ?, ?) OR supervisor_signature IN (?, ?, ?) LIMIT 1',
    ];
    try {
        if ($group === 'responses') {
            $surveyId = (int)(explode('/', $path)[2] ?? 0);
            $stmt = $conn->prepare('SELECT a.answer_value FROM survey_response_answers a JOIN survey_responses r ON r.id=a.response_id WHERE r.survey_id=?');
            $stmt->bind_param('i', $surveyId);
            $stmt->execute();
            $rows = $stmt->get_result();
            while ($row = $rows->fetch_assoc()) {
                $data = json_decode((string)$row['answer_value'], true);
                if (site_private_json_contains($data, $path)) {
                    $stmt->close();
                    return ['staff_only' => true];
                }
            }
            $stmt->close();
            return null;
        }
        if (!isset($queries[$group])) {
            return null;
        }
        $values = [$path, '/' . $path, basename($path)];
        if ($group === 'startup-field-visits') {
            $values = array_merge($values, $values, $values);
        }
        $stmt = $conn->prepare($queries[$group]);
        $stmt->bind_param(str_repeat('s', count($values)), ...$values);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    } catch (mysqli_sql_exception $exception) {
        // Older schemas must never cause an authorization bypass.
        error_log('Private-file lookup unavailable for group ' . $group . '.');
        return null;
    }
}

function site_private_json_contains($data, string $path): bool
{
    if (is_string($data)) {
        return ltrim($data, '/') === $path;
    }
    if (is_array($data)) {
        foreach ($data as $value) {
            if (site_private_json_contains($value, $path)) {
                return true;
            }
        }
    }
    return false;
}

function site_private_resource_approved(mysqli $conn, int $resourceId, int $ventureId): bool
{
    try {
        $stmt = $conn->prepare("SELECT 1 FROM resource_requests WHERE resource_id=? AND venture_id=? AND status='approved' LIMIT 1");
    } catch (mysqli_sql_exception $exception) {
        // Legacy schema links approved requests to the verified venture email.
        try {
            $stmt = $conn->prepare("SELECT 1 FROM resource_requests WHERE resource_id=? AND requester_email=(SELECT email FROM ventures WHERE id=?) AND status='approved' LIMIT 1");
        } catch (mysqli_sql_exception $exception) {
            return false;
        }
    }
    $stmt->bind_param('ii', $resourceId, $ventureId);
    $stmt->execute();
    $approved = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $approved;
}

function site_private_permissions(string $group): array
{
    return match ($group) {
        'ventures/docs' => ['venture-docs', 'ventures'],
        'ventures/meal-documents' => ['meal-report'],
        'loa', 'loa-signed', 'tranche-evidence' => ['grant-tracking'],
        'deliverables' => ['mentor-deliverables'],
        'mentor-reports', 'structured-reports' => ['mentor-reports'],
        'session-reports' => ['mentor-sessions'],
        'responses' => ['surveys', 'survey-responses'],
        'resources' => ['resources'],
        'startup-evidence' => ['venture-milestone-evidence', 'startup-milestones'],
        'startup-field-visits' => ['startup-field-visits', 'startup-milestones-dashboard'],
        default => [],
    };
}

function site_private_can_read(array $record, array $principal): bool
{
    if (isset($record['resource_id'])) {
        return ($record['access_level'] ?? '') === 'public'
            || !empty($principal['staff_permission']) || !empty($principal['resource_approved']);
    }
    if (!empty($principal['staff_permission'])) {
        return true;
    }
    if (!empty($record['staff_only'])) {
        return false;
    }
    if ((int)($principal['venture_id'] ?? 0) > 0
        && (int)($record['venture_id'] ?? 0) === (int)$principal['venture_id']) {
        return true;
    }
    return (int)($principal['mentor_id'] ?? 0) > 0
        && ((int)($record['mentor_id'] ?? 0) === (int)$principal['mentor_id']
            || !empty($principal['session_member']));
}
