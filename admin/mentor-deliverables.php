<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/deliverables-functions.php';

/*
|--------------------------------------------------------------------------
| Database validation
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection error.');
}

if (!$conn->set_charset('utf8mb4')) {
    error_log(
        'Unable to set database charset: '
        . $conn->error
    );
}

date_default_timezone_set('Africa/Kampala');

if (!$conn->query("SET time_zone = '+03:00'")) {
    error_log(
        'Unable to set database timezone: '
        . $conn->error
    );
}

refresh_overdue_deliverables($conn);

/*
|--------------------------------------------------------------------------
| Page configuration
|--------------------------------------------------------------------------
*/

$page_title = 'Mentor Deliverables';
$current_nav = 'mentor-deliverables.php';

/*
|--------------------------------------------------------------------------
| Current authenticated user
|--------------------------------------------------------------------------
*/

$userId = (int)(
    $ADMIN['id']
    ?? $_SESSION['admin_id']
    ?? $_SESSION['user_id']
    ?? 0
);

$userEmail = trim(
    (string)(
        $ADMIN['email']
        ?? $_SESSION['email']
        ?? $_SESSION['user_email']
        ?? ''
    )
);

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

/**
 * Return the available columns for an approved table.
 *
 * @return array<string, true>
 */
function deliverables_table_columns(
    mysqli $conn,
    string $table
): array {
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $allowedTables = [
        'deliverables_catalogue',
        'deliverable_assignments',
        'mentors',
    ];

    if (!in_array($table, $allowedTables, true)) {
        return [];
    }

    $columns = [];

    $result = $conn->query(
        "SHOW COLUMNS FROM `{$table}`"
    );

    if (!($result instanceof mysqli_result)) {
        error_log(
            "Unable to read {$table} columns: "
            . $conn->error
        );

        $cache[$table] = [];

        return [];
    }

    while ($row = $result->fetch_assoc()) {
        $field = trim(
            (string)($row['Field'] ?? '')
        );

        if ($field !== '') {
            $columns[$field] = true;
        }
    }

    $result->free();

    $cache[$table] = $columns;

    return $columns;
}

/**
 * Build a safe text expression using only existing columns.
 *
 * @param list<string> $candidates
 */
function deliverables_text_expression(
    mysqli $conn,
    string $table,
    string $alias,
    array $candidates,
    string $fallbackExpression
): string {
    $columns = deliverables_table_columns(
        $conn,
        $table
    );

    $parts = [];

    foreach ($candidates as $candidate) {
        if (isset($columns[$candidate])) {
            $parts[] =
                "NULLIF(TRIM({$alias}.`{$candidate}`), '')";
        }
    }

    $parts[] = $fallbackExpression;

    return 'COALESCE('
        . implode(', ', $parts)
        . ')';
}

/**
 * Normalize system role values.
 */
function normalize_deliverables_role(mixed $role): string
{
    $value = strtolower(trim((string)$role));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    $value = trim($value, '_');

    if ($value === '') {
        return '';
    }

    return match ($value) {
        'admin',
        'administrator',
        'super_admin',
        'superadministrator',
        'system_admin',
        'systemadministrator'
            => 'admin',

        'program_manager',
        'programme_manager',
        'programmanager',
        'program_manager_role',
        'programme_manager_role'
            => 'program_manager',

        'program_director',
        'programme_director',
        'programdirector',
        'programme_director_role',
        'program_director_role',
        'director'
            => 'program_director',

        'program_lead',
        'programme_lead',
        'programlead',
        'programmelead',
        'team_lead',
        'technical_lead',
        'lead'
            => 'program_lead',

        'consultant',
        'business_consultant',
        'consultant_role',
        'business_advisor',
        'advisor'
            => 'consultant',

        'mentor',
        'mentor_role'
            => 'mentor',

        default => $value,
    };
}

/**
 * Execute a normal query and return all rows.
 *
 * @return list<array<string, mixed>>
 */
function deliverables_fetch_all(
    mysqli $conn,
    string $sql,
    string $context
): array {
    $result = $conn->query($sql);

    if (!($result instanceof mysqli_result)) {
        error_log(
            "{$context}: {$conn->error}"
        );

        return [];
    }

    $rows = $result->fetch_all(
        MYSQLI_ASSOC
    );

    $result->free();

    return $rows;
}

/**
 * Bind dynamic values to a mysqli prepared statement.
 *
 * @param list<mixed> $params
 */
function deliverables_bind_params(
    mysqli_stmt $stmt,
    string $types,
    array &$params
): bool {
    if ($types === '' || $params === []) {
        return true;
    }

    $bindValues = [];
    $bindValues[] = &$types;

    foreach ($params as $index => &$value) {
        $bindValues[] = &$value;
    }

    unset($value);

    return (bool)call_user_func_array(
        [$stmt, 'bind_param'],
        $bindValues
    );
}

/**
 * Convert a stored submission path into a safe browser URL.
 */
function deliverables_submission_url(string $storedPath): string
{
    $storedPath = trim(str_replace('\\', '/', $storedPath));

    if (
        $storedPath === ''
        || str_contains($storedPath, "\0")
        || preg_match('#(^|/)\.\.(/|$)#', $storedPath)
        || preg_match('#^[a-z][a-z0-9+.-]*://#i', $storedPath)
    ) {
        return '';
    }

    $pathOnly = parse_url($storedPath, PHP_URL_PATH);

    if (!is_string($pathOnly) || $pathOnly === '') {
        return '';
    }

    $extension = strtolower(pathinfo($pathOnly, PATHINFO_EXTENSION));

    $allowedExtensions = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'csv', 'zip', 'jpg', 'jpeg', 'png', 'webp',
    ];

    if (!in_array($extension, $allowedExtensions, true)) {
        return '';
    }

    $storedPath = ltrim($storedPath, '/');

    if (
        str_starts_with($storedPath, 'uploads/')
        || str_starts_with($storedPath, 'storage/')
        || str_starts_with($storedPath, 'assets/')
        || str_starts_with($storedPath, 'admin/')
    ) {
        return '../' . $storedPath;
    }

    return '../uploads/deliverables/' . rawurlencode(
        basename($storedPath)
    );
}

$submissionSelectExpr = "
    da.submission_file_name,
    da.submission_file_path
";

/*
|--------------------------------------------------------------------------
| Dynamic table expressions
|--------------------------------------------------------------------------
*/

$catalogueNameExpr =
    deliverables_text_expression(
        $conn,
        'deliverables_catalogue',
        'dc',
        [
            'name',
            'deliverable_name',
            'title',
        ],
        "CONCAT('Deliverable #', dc.id)"
    );

$mentorNameExpr =
    deliverables_text_expression(
        $conn,
        'mentors',
        'm',
        [
            'full_name',
        ],
        "
        COALESCE(
            NULLIF(TRIM(m.email), ''),
            CONCAT('Mentor #', m.id)
        )
        "
    );

/*
|--------------------------------------------------------------------------
| Current user role
|--------------------------------------------------------------------------
*/

$roleCandidates = [
    $ADMIN['role'] ?? null,
    $_SESSION['role'] ?? null,
    $_SESSION['user_role'] ?? null,
    $_SESSION['admin_role'] ?? null,
    $_SESSION['role_name'] ?? null,
];

$role = '';

foreach ($roleCandidates as $roleCandidate) {
    $normalizedRole =
        normalize_deliverables_role(
            $roleCandidate
        );

    if ($normalizedRole !== '') {
        $role = $normalizedRole;
        break;
    }
}

$creatorRoles = [
    'admin',
    'program_lead',
    'program_manager',
    'program_director',
    'mentor',
    'consultant',
];

$managerRoles = [
    'admin',
    'program_lead',
    'program_manager',
    'program_director',
    'consultant',
];

$canCreate = in_array(
    $role,
    $creatorRoles,
    true
);

$canViewAll = in_array(
    $role,
    $managerRoles,
    true
);

/*
|--------------------------------------------------------------------------
| Deliverable catalogue creation permission
|--------------------------------------------------------------------------
|
| Mentors may create assignments for themselves, but they must not be able
| to create new deliverable catalogue items.
|
*/
$canCreateDeliverable = in_array(
    $role,
    $managerRoles,
    true
);

/*
|--------------------------------------------------------------------------
| Resolve current mentor profile
|--------------------------------------------------------------------------
|
| admin_users.id links to mentors.user_id.
| Deliverable assignments use mentors.id.
|
*/

$mentorId = 0;

if ($userId > 0) {
    $mentorLookup = $conn->prepare("
        SELECT
            m.id
        FROM mentors m
        WHERE m.user_id = ?
        LIMIT 1
    ");

    if ($mentorLookup) {
        $mentorLookup->bind_param(
            'i',
            $userId
        );

        if ($mentorLookup->execute()) {
            $mentorResult =
                $mentorLookup->get_result();

            $mentorRecord =
                $mentorResult->fetch_assoc();

            $mentorId = (int)(
                $mentorRecord['id'] ?? 0
            );

            $mentorResult->free();
        } else {
            error_log(
                'Mentor lookup by user ID failed: '
                . $mentorLookup->error
            );
        }

        $mentorLookup->close();
    } else {
        error_log(
            'Unable to prepare mentor user lookup: '
            . $conn->error
        );
    }
}

if (
    $mentorId <= 0
    && $userEmail !== ''
) {
    $mentorLookup = $conn->prepare("
        SELECT
            m.id
        FROM mentors m
        WHERE LOWER(TRIM(m.email))
            = LOWER(TRIM(?))
        LIMIT 1
    ");

    if ($mentorLookup) {
        $mentorLookup->bind_param(
            's',
            $userEmail
        );

        if ($mentorLookup->execute()) {
            $mentorResult =
                $mentorLookup->get_result();

            $mentorRecord =
                $mentorResult->fetch_assoc();

            $mentorId = (int)(
                $mentorRecord['id'] ?? 0
            );

            $mentorResult->free();
        } else {
            error_log(
                'Mentor lookup by email failed: '
                . $mentorLookup->error
            );
        }

        $mentorLookup->close();
    } else {
        error_log(
            'Unable to prepare mentor email lookup: '
            . $conn->error
        );
    }
}

/*
|--------------------------------------------------------------------------
| Request filters
|--------------------------------------------------------------------------
*/

$status = trim(
    (string)($_GET['status'] ?? '')
);

$theme = trim(
    (string)($_GET['theme'] ?? '')
);

$ventureId = max(
    0,
    (int)($_GET['venture_id'] ?? 0)
);

$mentorFilter = max(
    0,
    (int)($_GET['mentor_id'] ?? 0)
);

$cohortId = max(
    0,
    (int)($_GET['cohort_id'] ?? 0)
);

$dueWeek = isset(
    $_GET['due_this_week']
);

$overdue = isset(
    $_GET['overdue']
);

/*
|--------------------------------------------------------------------------
| Build assignment filters
|--------------------------------------------------------------------------
*/

$where = [];
$types = '';
$params = [];

if ($canViewAll) {
    if ($mentorFilter > 0) {
        $where[] = 'da.mentor_id = ?';
        $types .= 'i';
        $params[] = $mentorFilter;
    }
} else {
    /*
     * Mentors only see assignments linked to
     * their mentors.id profile.
     */
    $where[] = 'da.mentor_id = ?';
    $types .= 'i';
    $params[] = $mentorId;
}

if ($status !== '') {
    $where[] = 'da.status = ?';
    $types .= 's';
    $params[] = $status;
}

if ($theme !== '') {
    $where[] = 'dc.theme = ?';
    $types .= 's';
    $params[] = $theme;
}

if ($ventureId > 0) {
    $where[] = 'da.venture_id = ?';
    $types .= 'i';
    $params[] = $ventureId;
}

if ($cohortId > 0) {
    $where[] = 'da.cohort_id = ?';
    $types .= 'i';
    $params[] = $cohortId;
}

if ($dueWeek) {
    $where[] = "
        YEARWEEK(da.due_date, 1)
        = YEARWEEK(CURDATE(), 1)
    ";
}

if ($overdue) {
    $where[] = "da.status = 'overdue'";
}

$whereSql = $where !== []
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

/*
|--------------------------------------------------------------------------
| Load deliverable assignments
|--------------------------------------------------------------------------
*/

$assignmentSql = "
    SELECT
        da.*,

        {$submissionSelectExpr},

        {$catalogueNameExpr}
            AS deliverable_name,

        COALESCE(
            NULLIF(TRIM(dc.theme), ''),
            'General'
        ) AS theme,

        dc.expected_completion_hours,

        v.name AS venture_name,

        c.name AS cohort_name,

        ac.phase,

        {$mentorNameExpr}
            AS mentor_name

    FROM deliverable_assignments da

    INNER JOIN deliverables_catalogue dc
        ON dc.id = da.catalogue_id

    INNER JOIN ventures v
        ON v.id = da.venture_id

    LEFT JOIN cohorts c
        ON c.id = da.cohort_id

    LEFT JOIN accelerator_cycles ac
        ON ac.id = da.cycle_id

    LEFT JOIN mentors m
        ON m.id = da.mentor_id

    {$whereSql}

    ORDER BY
        CASE
            WHEN da.status = 'overdue'
                THEN 0
            ELSE 1
        END ASC,

        da.due_date ASC,
        v.name ASC
";

$rows = [];
$assignmentsError = '';

$assignmentStatement =
    $conn->prepare($assignmentSql);

if (!$assignmentStatement) {
    $assignmentsError =
        'Deliverable assignments could not be loaded.';

    error_log(
        'Unable to prepare assignment query: '
        . $conn->error
    );
} else {
    if (
        $types !== ''
        && !deliverables_bind_params(
            $assignmentStatement,
            $types,
            $params
        )
    ) {
        $assignmentsError =
            'Deliverable assignment filters could not be applied.';

        error_log(
            'Unable to bind assignment filters: '
            . $assignmentStatement->error
        );
    } elseif (!$assignmentStatement->execute()) {
        $assignmentsError =
            'Deliverable assignments could not be loaded.';

        error_log(
            'Assignment query execution failed: '
            . $assignmentStatement->error
        );
    } else {
        $assignmentResult =
            $assignmentStatement->get_result();

        if ($assignmentResult instanceof mysqli_result) {
            $rows =
                $assignmentResult->fetch_all(
                    MYSQLI_ASSOC
                );

            $assignmentResult->free();
        }
    }

    $assignmentStatement->close();
}

$summary = deliverable_summary($rows);

/*
|--------------------------------------------------------------------------
| Load ventures
|--------------------------------------------------------------------------
*/

$ventures = deliverables_fetch_all(
    $conn,
    "
    SELECT
        id,
        name,
        cohort_id
    FROM ventures
    ORDER BY name ASC
    ",
    'Unable to load ventures'
);

/*
|--------------------------------------------------------------------------
| Load cohorts
|--------------------------------------------------------------------------
*/

$cohorts = deliverables_fetch_all(
    $conn,
    "
    SELECT
        id,
        name
    FROM cohorts
    ORDER BY name ASC
    ",
    'Unable to load cohorts'
);

/*
|--------------------------------------------------------------------------
| Load deliverables catalogue
|--------------------------------------------------------------------------
*/

$catalogue = [];
$catalogueError = '';

$catalogueSql = "
    SELECT
        dc.id,

        {$catalogueNameExpr}
            AS display_name,

        COALESCE(
            NULLIF(TRIM(dc.theme), ''),
            'General'
        ) AS theme

    FROM deliverables_catalogue dc

    WHERE
        dc.is_active IS NULL

        OR dc.is_active = 1

        OR LOWER(
            TRIM(
                CAST(dc.is_active AS CHAR)
            )
        ) IN (
            '1',
            'active',
            'enabled',
            'yes',
            'true'
        )

    ORDER BY
        theme ASC,
        display_name ASC
";

$catalogueResult =
    $conn->query($catalogueSql);

if ($catalogueResult instanceof mysqli_result) {
    $catalogue =
        $catalogueResult->fetch_all(
            MYSQLI_ASSOC
        );

    $catalogueResult->free();
} else {
    $catalogueError =
        'Deliverables catalogue could not be loaded.';

    error_log(
        'Deliverables catalogue query failed: '
        . $conn->error
    );
}

/*
|--------------------------------------------------------------------------
| Load catalogue themes
|--------------------------------------------------------------------------
*/

$themes = deliverables_fetch_all(
    $conn,
    "
    SELECT DISTINCT
        COALESCE(
            NULLIF(TRIM(theme), ''),
            'General'
        ) AS theme

    FROM deliverables_catalogue

    WHERE
        is_active IS NULL

        OR is_active = 1

        OR LOWER(
            TRIM(
                CAST(is_active AS CHAR)
            )
        ) IN (
            '1',
            'active',
            'enabled',
            'yes',
            'true'
        )

    ORDER BY theme ASC
    ",
    'Unable to load deliverable themes'
);

/*
|--------------------------------------------------------------------------
| Load mentors
|--------------------------------------------------------------------------
|
| Relationship:
|
| admin_users.id = mentors.user_id
|
| The assignment value must be mentors.id because:
|
| deliverable_assignments.mentor_id = mentors.id
|
*/

$mentors = [];
$mentorsError = '';

$mentorsSql = "
    SELECT
        m.id,
        m.user_id,

        COALESCE(
            NULLIF(TRIM(m.full_name), ''),
            NULLIF(TRIM(au.full_name), ''),
            NULLIF(TRIM(m.email), ''),
            NULLIF(TRIM(au.email), ''),
            CONCAT('Mentor #', m.id)
        ) AS display_name,

        COALESCE(
            NULLIF(TRIM(m.email), ''),
            NULLIF(TRIM(au.email), ''),
            ''
        ) AS email,

        COALESCE(
            NULLIF(TRIM(m.phone), ''),
            ''
        ) AS phone,

        COALESCE(
            NULLIF(TRIM(m.job_title), ''),
            ''
        ) AS job_title,

        COALESCE(
            NULLIF(TRIM(m.organisation), ''),
            ''
        ) AS organisation,

        COALESCE(
            NULLIF(TRIM(m.bio), ''),
            ''
        ) AS bio,

        COALESCE(
            NULLIF(TRIM(m.photo), ''),
            NULLIF(TRIM(au.photo), ''),
            ''
        ) AS photo,

        COALESCE(
            NULLIF(TRIM(m.location), ''),
            ''
        ) AS location,

        COALESCE(
            NULLIF(TRIM(m.expertise), ''),
            ''
        ) AS expertise,

        COALESCE(
            NULLIF(TRIM(m.industries), ''),
            ''
        ) AS industries,

        COALESCE(
            m.weekly_hours,
            0
        ) AS weekly_hours,

        COALESCE(
            NULLIF(TRIM(m.status), ''),
            'active'
        ) AS mentor_status,

        COALESCE(
            NULLIF(TRIM(au.status), ''),
            ''
        ) AS account_status,

        COALESCE(
            NULLIF(TRIM(au.role), ''),
            'mentor'
        ) AS account_role

    FROM mentors m

    LEFT JOIN admin_users au
        ON au.id = m.user_id

    WHERE
        m.id > 0

        AND LOWER(
            COALESCE(
                NULLIF(TRIM(m.status), ''),
                'active'
            )
        ) NOT IN (
            'inactive',
            'disabled',
            'deleted',
            'rejected',
            'suspended',
            'blocked'
        )

    ORDER BY
        display_name ASC,
        m.id ASC
";

$mentorsResult = $conn->query($mentorsSql);

if ($mentorsResult instanceof mysqli_result) {
    $mentors = $mentorsResult->fetch_all(MYSQLI_ASSOC);
    $mentorsResult->free();
} else {
    $mentorsError =
        'Mentors could not be loaded: '
        . $conn->error;

    error_log($mentorsError);
}

foreach ($mentors as &$mentorRow) {
    $mentorRow['id'] = (int)($mentorRow['id'] ?? 0);
    $mentorRow['user_id'] = (int)($mentorRow['user_id'] ?? 0);
    $mentorRow['weekly_hours'] = (float)($mentorRow['weekly_hours'] ?? 0);

    $mentorRow['full_name'] = trim(
        (string)($mentorRow['display_name'] ?? '')
    );

    if ($mentorRow['full_name'] === '') {
        $mentorRow['full_name'] =
            'Mentor #' . $mentorRow['id'];
    }

    foreach (
        [
            'email',
            'phone',
            'job_title',
            'organisation',
            'bio',
            'photo',
            'location',
            'expertise',
            'industries',
            'mentor_status',
            'account_status',
            'account_role',
        ] as $mentorTextField
    ) {
        $mentorRow[$mentorTextField] = trim(
            (string)($mentorRow[$mentorTextField] ?? '')
        );
    }
}

unset($mentorRow);

/*
|--------------------------------------------------------------------------
| Load accelerator cycles
|--------------------------------------------------------------------------
*/

$cycles = deliverables_fetch_all(
    $conn,
    "
    SELECT
        ac.id,
        ac.cohort_id,
        ac.phase,
        ac.theme,
        c.name AS cohort_name

    FROM accelerator_cycles ac

    LEFT JOIN cohorts c
        ON c.id = ac.cohort_id

    WHERE
        ac.status IN (
            'planned',
            'active'
        )

    ORDER BY
        ac.start_date ASC,
        ac.theme ASC
    ",
    'Unable to load accelerator cycles'
);

/*
|--------------------------------------------------------------------------
| Flash messages
|--------------------------------------------------------------------------
*/

$message = trim(
    (string)($_GET['message'] ?? '')
);

$messageType = trim(
    (string)($_GET['message_type'] ?? '')
);

/*
|--------------------------------------------------------------------------
| Include page layout or continue with the page HTML
|--------------------------------------------------------------------------
|
| The checkbox value must use $mentor['id'], not $mentor['user_id'].
|
*/
?>

<!doctype html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($page_title) ?> - Admin</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="assets/css/admin.css">


<style>
.md-action-bar{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin:16px 0 18px;
}

.md-modal-overlay{
    position:fixed;
    inset:0;
    z-index:10000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:20px;
    background:rgba(15,23,42,.60);
    backdrop-filter:blur(3px);
}

.md-modal-overlay.open{
    display:flex;
}

.md-modal{
    width:min(760px,100%);
    max-height:90vh;
    background:#fff;
    border-radius:16px;
    box-shadow:0 24px 70px rgba(15,23,42,.30);
    overflow:hidden;
    display:flex;
    flex-direction:column;
}

.md-modal-lg{
    width:min(1080px,100%);
}

.md-modal-header,
.md-modal-footer{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    padding:18px 22px;
    background:#fff;
}

.md-modal-header{
    border-bottom:1px solid #e5e7eb;
}

.md-modal-footer{
    border-top:1px solid #e5e7eb;
    justify-content:flex-end;
}

.md-modal-header h2{
    margin:0;
}

.md-modal-header .md-help{
    margin:5px 0 0;
}

.md-modal-body{
    padding:22px;
    overflow-y:auto;
}

.md-modal-close{
    border:0;
    background:#f3f4f6;
    width:38px;
    height:38px;
    min-width:38px;
    border-radius:50%;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:16px;
}

.md-modal-close:hover{
    background:#e5e7eb;
}

body.md-modal-open{
    overflow:hidden;
}

@media (max-width:768px){
    .md-modal-overlay{
        padding:8px;
        align-items:flex-end;
    }

    .md-modal{
        width:100%;
        max-height:94vh;
        border-radius:16px 16px 0 0;
    }

    .md-modal-header,
    .md-modal-body,
    .md-modal-footer{
        padding:16px;
    }

    .md-modal-footer{
        flex-wrap:wrap;
    }

    .md-modal-footer .btn{
        flex:1;
    }

    .md-action-bar .btn{
        flex:1 1 180px;
    }
}
</style>

</head>
<body>
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<div class="admin-main">
<header class="admin-topbar"><div class="topbar-breadcrumb"><a href="index.php">Dashboard</a> &gt; <strong>Mentor Deliverables</strong></div></header>
<main class="admin-content">
<div class="md-head"><div><h1>Mentor Deliverables &amp; Deadline Tracker</h1><p>Assign deliverables to ventures and track mentor workload, progress, and deadlines.</p></div></div>

<?php if ($message !== ''): ?>
<div class="notice <?= $messageType === 'success' ? 'success' : 'error' ?>"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($canCreate): ?>
<div class="md-action-bar">
    <button
        type="button"
        class="btn btn-primary"
        onclick="openMdModal('assignmentModal')"
    >
        <i class="fa-solid fa-list-check"></i>
        Create Assignment
    </button>

    <?php if ($canCreateDeliverable): ?>
    <button
        type="button"
        class="btn btn-outline"
        onclick="openMdModal('deliverableModal')"
    >
        <i class="fa-solid fa-file-circle-plus"></i>
        Add New Deliverable
    </button>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="notice error">
    Your current role
    <strong><?= h($role !== '' ? $role : 'unknown') ?></strong>
    is not permitted to create assignments.
</div>
<?php endif; ?>

<?php if ($canCreate): ?>
<div
    class="md-modal-overlay"
    id="assignmentModal"
    aria-hidden="true"
>
    <div
        class="md-modal md-modal-lg"
        role="dialog"
        aria-modal="true"
        aria-labelledby="assignmentModalTitle"
    >
        <div class="md-modal-header">
            <div>
                <h2 id="assignmentModalTitle">Create Venture Assignment</h2>
                <p class="md-help">
                    Create one assignment for every selected mentor and venture combination.
                </p>
            </div>

            <button
                type="button"
                class="md-modal-close"
                onclick="closeMdModal('assignmentModal')"
                aria-label="Close"
            >
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form
            method="post"
            action="../includes/process-deliverables.php"
        >
            <input
                type="hidden"
                name="action"
                value="create_bulk_assignments"
            >

            <div class="md-modal-body">
                <div class="md-grid">
                    <div class="md-field span2">
                        <label>Deliverable</label>

                        <?php if ($catalogueError !== ''): ?>
                            <div class="notice error">
                                <?= h($catalogueError) ?>
                            </div>
                        <?php endif; ?>

                        <select
                            name="catalogue_id"
                            required
                            <?= !$catalogue ? 'disabled' : '' ?>
                        >
                            <option value="">
                                <?= $catalogue
                                    ? 'Select deliverable'
                                    : 'No active deliverables available' ?>
                            </option>

                            <?php foreach ($catalogue as $d): ?>
                                <option value="<?= (int)$d['id'] ?>">
                                    <?= h(
                                        (string)(
                                            $d['display_name']
                                            ?? ('Deliverable #' . (int)$d['id'])
                                        )
                                        . ' - '
                                        . (string)($d['theme'] ?? 'General')
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <?php if (!$catalogue): ?>
                            <div class="md-help">
                                <?php if ($canCreateDeliverable): ?>
                                    Add a deliverable first using the
                                    <strong>Add New Deliverable</strong> button.
                                <?php else: ?>
                                    No active deliverables are currently available.
                                    Please contact a programme manager or administrator.
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="md-field span2">
                        <label>Accelerator Cycle</label>

                        <select name="cycle_id">
                            <option value="">Select cycle</option>

                            <?php foreach ($cycles as $c): ?>
                                <option value="<?= (int)$c['id'] ?>">
                                    <?= h(
                                        ($c['cohort_name'] ?? 'Cohort')
                                        . ' - '
                                        . ($c['phase'] ?? '')
                                        . ' - '
                                        . ($c['theme'] ?? '')
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="md-field span2">
                        <label>Ventures</label>

                        <div class="md-checklist-tools">
                            <input
                                type="search"
                                class="md-checklist-search"
                                data-checklist-search="ventureChecklist"
                                placeholder="Search ventures..."
                            >

                            <button
                                type="button"
                                class="btn btn-outline btn-sm"
                                data-check-all="ventureChecklist"
                            >
                                Select All
                            </button>

                            <button
                                type="button"
                                class="btn btn-outline btn-sm"
                                data-uncheck-all="ventureChecklist"
                            >
                                Clear
                            </button>
                        </div>

                        <div
                            class="md-checklist"
                            id="ventureChecklist"
                        >
                            <?php if (!$ventures): ?>
                                <div class="md-help">
                                    No ventures were found.
                                </div>
                            <?php endif; ?>

                            <?php foreach ($ventures as $v): ?>
                                <label
                                    class="md-check-option"
                                    data-search-text="<?= h(strtolower($v['name'])) ?>"
                                >
                                    <input
                                        type="checkbox"
                                        name="venture_ids[]"
                                        value="<?= (int)$v['id'] ?>"
                                    >
                                    <span><?= h($v['name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="md-help">
                            Select one or more ventures.
                        </div>
                    </div>

                    <div class="md-field span2">
                        <label>Responsible Mentors</label>

                        <?php if ($canViewAll): ?>
                            <div class="md-checklist-tools">
                                <input
                                    type="search"
                                    class="md-checklist-search"
                                    data-checklist-search="mentorChecklist"
                                    placeholder="Search mentors..."
                                >

                                <button
                                    type="button"
                                    class="btn btn-outline btn-sm"
                                    data-check-all="mentorChecklist"
                                >
                                    Select All
                                </button>

                                <button
                                    type="button"
                                    class="btn btn-outline btn-sm"
                                    data-uncheck-all="mentorChecklist"
                                >
                                    Clear
                                </button>
                            </div>

                            <div
                                class="md-checklist"
                                id="mentorChecklist"
                            >
                                <?php if (!$mentors): ?>
                                    <div class="notice error">
                                        <?= h(
                                            $mentorsError !== ''
                                                ? $mentorsError
                                                : 'No available mentor profiles were found.'
                                        ) ?>
                                    </div>
                                <?php endif; ?>

                                <?php foreach ($mentors as $m): ?>
                                    <?php
                                    $mentorLabel = (string)$m['full_name'];

                                    if (!empty($m['job_title'])) {
                                        $mentorLabel .= ' - ' . $m['job_title'];
                                    }

                                    if (!empty($m['organisation'])) {
                                        $mentorLabel .= ' - ' . $m['organisation'];
                                    }

                                    if (!empty($m['email'])) {
                                        $mentorLabel .= ' - ' . $m['email'];
                                    }
                                    ?>

                                    <label
                                        class="md-check-option"
                                        data-search-text="<?= h(strtolower($mentorLabel)) ?>"
                                    >
                                        <input
                                            type="checkbox"
                                            name="mentor_ids[]"
                                            value="<?= (int)$m['id'] ?>"
                                        >
                                        <span><?= h($mentorLabel) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <?php
                            $currentMentorName = 'Current mentor';

                            foreach ($mentors as $m) {
                                if ((int)$m['id'] === $mentorId) {
                                    $currentMentorName = (string)$m['full_name'];
                                    break;
                                }
                            }
                            ?>

                            <label class="md-check-option md-single-mentor">
                                <input
                                    type="checkbox"
                                    checked
                                    disabled
                                >
                                <span><?= h($currentMentorName) ?></span>
                            </label>

                            <?php if ($mentorId > 0): ?>
                                <input
                                    type="hidden"
                                    name="mentor_ids[]"
                                    value="<?= (int)$mentorId ?>"
                                >
                            <?php else: ?>
                                <div class="notice error">
                                    Your administrator account is not linked to a mentor profile.
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <div class="md-help">
                            Managers may select several mentors.
                            Mentors are assigned only to themselves.
                        </div>
                    </div>

                    <div class="md-field">
                        <label>Cohort Override</label>

                        <select name="cohort_id">
                            <option value="">
                                Use each venture's cohort
                            </option>

                            <?php foreach ($cohorts as $c): ?>
                                <option value="<?= (int)$c['id'] ?>">
                                    <?= h($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="md-field">
                        <label>Priority</label>

                        <select name="priority">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>

                    <div class="md-field">
                        <label>Assigned Date</label>

                        <input
                            type="date"
                            name="assigned_date"
                            value="<?= date('Y-m-d') ?>"
                            required
                        >
                    </div>

                    <div class="md-field">
                        <label>Due Date</label>

                        <input
                            type="date"
                            name="due_date"
                            value="<?= date('Y-m-d', strtotime('+7 days')) ?>"
                            required
                        >
                    </div>

                    <div class="md-field">
                        <label>Reminder Date</label>

                        <input
                            type="date"
                            name="reminder_date"
                            value="<?= date('Y-m-d', strtotime('+4 days')) ?>"
                        >
                    </div>

                    <div class="md-field">
                        <label>Escalation Date</label>

                        <input
                            type="date"
                            name="escalation_date"
                            value="<?= date('Y-m-d', strtotime('+8 days')) ?>"
                        >
                    </div>

                    <div class="md-field">
                        <label>Initial Status</label>

                        <select name="status">
                            <option value="not_started">Not Started</option>
                            <option value="in_progress">In Progress</option>
                        </select>
                    </div>

                    <div class="md-field full">
                        <label>Assignment Notes</label>

                        <textarea
                            name="notes"
                            maxlength="2000"
                            placeholder="Instructions and expected output"
                        ></textarea>
                    </div>
                </div>
            </div>

            <div class="md-modal-footer">
                <button
                    type="button"
                    class="btn btn-outline"
                    onclick="closeMdModal('assignmentModal')"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn btn-primary"
                    <?= (
                        (!$canViewAll && $mentorId <= 0)
                        || !$catalogue
                        || !$ventures
                    ) ? 'disabled' : '' ?>
                >
                    <i class="fa-solid fa-plus"></i>
                    Create Assignment
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canCreateDeliverable): ?>
<div
    class="md-modal-overlay"
    id="deliverableModal"
    aria-hidden="true"
>
    <div
        class="md-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="deliverableModalTitle"
    >
        <div class="md-modal-header">
            <div>
                <h2 id="deliverableModalTitle">
                    Add New Deliverable
                </h2>

                <p class="md-help">
                    Create a reusable deliverable catalogue item.
                </p>
            </div>

            <button
                type="button"
                class="md-modal-close"
                onclick="closeMdModal('deliverableModal')"
                aria-label="Close"
            >
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form
            method="post"
            action="../includes/process-deliverables.php"
            enctype="multipart/form-data"
        >
            <input
                type="hidden"
                name="action"
                value="create_deliverable"
            >

            <div class="md-modal-body">
                <div class="md-grid">
                    <div class="md-field span2">
                        <label>Deliverable Name</label>

                        <input
                            type="text"
                            name="name"
                            maxlength="180"
                            required
                        >
                    </div>

                    <div class="md-field span2">
                        <label>Theme</label>

                        <input
                            type="text"
                            name="theme"
                            maxlength="150"
                            list="deliverableThemeList"
                            required
                        >

                        <datalist id="deliverableThemeList">
                            <?php foreach ($themes as $t): ?>
                                <option value="<?= h($t['theme']) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="md-field">
                        <label>Frequency</label>

                        <select name="frequency">
                            <option value="once">Once</option>
                            <option value="weekly">Weekly</option>
                            <option value="biweekly">Biweekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="per_phase">Per Phase</option>
                            <option value="per_sprint">Per Sprint</option>
                            <option value="custom">Custom</option>
                        </select>
                    </div>

                    <div class="md-field">
                        <label>Expected Completion Hours</label>

                        <input
                            type="number"
                            name="expected_completion_hours"
                            min="0"
                            max="9999"
                            step="0.25"
                        >
                    </div>

                    <div class="md-field span2">
                        <label>Template File</label>

                        <input
                            type="file"
                            name="template_file"
                            accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx"
                        >

                        <div class="md-help">
                            Optional. Maximum size: 20 MB.
                        </div>
                    </div>

                    <div class="md-field full">
                        <label>Description</label>

                        <textarea
                            name="description"
                            maxlength="3000"
                            placeholder="Purpose, expected output, and completion guidance"
                        ></textarea>
                    </div>
                </div>
            </div>

            <div class="md-modal-footer">
                <button
                    type="button"
                    class="btn btn-outline"
                    onclick="closeMdModal('deliverableModal')"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    <i class="fa-solid fa-file-circle-plus"></i>
                    Add Deliverable
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="md-stats">
<div class="md-stat"><strong><?= (int)$summary['total'] ?></strong><span>Total Tasks</span></div>
<div class="md-stat"><strong><?= (int)$summary['completed'] ?></strong><span>Completed</span></div>
<div class="md-stat"><strong><?= (int)$summary['submitted'] ?></strong><span>Submitted</span></div>
<div class="md-stat"><strong><?= (int)$summary['due_week'] ?></strong><span>Due This Week</span></div>
<div class="md-stat"><strong><?= (int)$summary['overdue'] ?></strong><span>Overdue</span></div>
</div>

<form class="md-filter">
<select name="venture_id"><option value="">All Ventures</option><?php foreach($ventures as $v):?><option value="<?= (int)$v['id'] ?>" <?= $ventureId===(int)$v['id']?'selected':'' ?>><?= h($v['name']) ?></option><?php endforeach;?></select>
<?php if($canViewAll):?><select name="mentor_id"><option value="">All Mentors</option><?php foreach($mentors as $m):?><option value="<?= (int)$m['id'] ?>" <?= $mentorFilter===(int)$m['id']?'selected':'' ?>><?= h($m['full_name']) ?></option><?php endforeach;?></select><?php endif;?>
<select name="theme"><option value="">All Themes</option><?php foreach($themes as $t):?><option value="<?= h($t['theme']) ?>" <?= $theme===$t['theme']?'selected':'' ?>><?= h($t['theme']) ?></option><?php endforeach;?></select>
<select name="status"><option value="">All Statuses</option><?php foreach(['not_started','in_progress','submitted','returned_for_revision','approved','overdue','completed'] as $s):?><option value="<?= h($s) ?>" <?= $status===$s?'selected':'' ?>><?= h(deliverable_status_label($s)) ?></option><?php endforeach;?></select>
<label><input type="checkbox" name="due_this_week" value="1" <?= $dueWeek?'checked':'' ?>> Due this week</label>
<label><input type="checkbox" name="overdue" value="1" <?= $overdue?'checked':'' ?>> Overdue</label>
<button class="btn btn-primary btn-sm">Filter</button><a href="mentor-deliverables.php" class="btn btn-outline btn-sm">Reset</a>
</form>

<div class="md-box md-wrap">
<table class="md-table">
<thead>
<tr>
<th>Deliverable</th>
<th>Venture</th>
<th>Mentor</th>
<th>Theme</th>
<th>Due</th>
<th>Status</th>
<th>Submitted File</th>
<th>Progress Update</th>
</tr>
</thead>
<tbody>
<?php if (!$rows): ?>
<tr>
<td colspan="8">
<?= h($assignmentsError !== '' ? $assignmentsError : 'No deliverables found.') ?>
</td>
</tr>
<?php endif; ?>

<?php foreach ($rows as $r): ?>
<?php
$submissionFileName = trim(
    (string)($r['submission_file_name'] ?? '')
);

$submissionFilePath = trim(
    (string)($r['submission_file_path'] ?? '')
);

$submissionUrl = deliverables_submission_url(
    $submissionFilePath
);

$canViewSubmission = $canViewAll
    || (
        $role === 'mentor'
        && (int)($r['mentor_id'] ?? 0) === $mentorId
    );
?>
<tr>
<td>
<strong><?= h((string)$r['deliverable_name']) ?></strong><br>
<small><?= h((string)($r['expected_completion_hours'] ?? '')) ?> hours</small>
</td>

<td>
<?= h((string)$r['venture_name']) ?><br>
<small><?= h((string)($r['cohort_name'] ?? '')) ?></small>
</td>

<td><?= h((string)($r['mentor_name'] ?? 'Not assigned')) ?></td>

<td>
<?= h((string)$r['theme']) ?><br>
<small><?= h((string)($r['phase'] ?? '')) ?></small>
</td>

<td>
<strong><?= h(deliverable_due_label((string)$r['due_date'])) ?></strong><br>
<small><?= h(date('j M Y', strtotime((string)$r['due_date']))) ?></small>
</td>

<td>
<span class="tag <?= h(deliverable_status_class((string)$r['status'], (string)$r['due_date'])) ?>">
<?= h(deliverable_status_label((string)$r['status'])) ?>
</span>
</td>

<td>
<?php if (!$canViewSubmission): ?>
<span class="md-no-file">Restricted</span>
<?php elseif ($submissionUrl !== ''): ?>
<div class="md-file-actions">
<a class="md-file-link" href="<?= h($submissionUrl) ?>" target="_blank" rel="noopener noreferrer">
<i class="fa-solid fa-eye"></i> View File
</a>

</div>
<small>
<?= h(
    $submissionFileName !== ''
        ? $submissionFileName
        : basename($submissionFilePath)
) ?>
</small>
<?php elseif ($submissionFilePath !== ''): ?>
<span class="md-no-file">Invalid or unsupported file path</span>
<?php else: ?>
<span class="md-no-file">No file submitted</span>
<?php endif; ?>
</td>

<td>
<?php if ($canViewAll || (int)$r['mentor_id'] === $mentorId): ?>
<form class="update" method="post" action="../includes/process-deliverables.php">
<input type="hidden" name="action" value="mentor_update">
<input type="hidden" name="assignment_id" value="<?= (int)$r['id'] ?>">

<select name="status">
<?php foreach (['not_started','in_progress','returned_for_revision','completed'] as $updateStatus): ?>
<option value="<?= h($updateStatus) ?>" <?= $r['status'] === $updateStatus ? 'selected' : '' ?>>
<?= h(deliverable_status_label($updateStatus)) ?>
</option>
<?php endforeach; ?>
</select>

<input type="number" name="progress_percent" min="0" max="100" value="<?= (int)($r['progress_percent'] ?? 0) ?>">

<input name="notes" value="<?= h((string)($r['submission_notes'] ?? '')) ?>" placeholder="Notes">

<button class="btn btn-primary btn-sm">Save</button>
</form>
<?php else: ?>
<small>View only</small>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</main></div>
<script>
function openMdModal(id) {
    const modal = document.getElementById(id);

    if (!modal) {
        return;
    }

    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('md-modal-open');

    const firstField = modal.querySelector(
        'input:not([type="hidden"]):not([disabled]),'
        + 'select:not([disabled]),'
        + 'textarea:not([disabled]),'
        + 'button:not([disabled])'
    );

    if (firstField) {
        window.setTimeout(function () {
            firstField.focus();
        }, 50);
    }
}

function closeMdModal(id) {
    const modal = document.getElementById(id);

    if (!modal) {
        return;
    }

    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');

    if (!document.querySelector('.md-modal-overlay.open')) {
        document.body.classList.remove('md-modal-open');
    }
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.md-modal-overlay').forEach(function (overlay) {
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) {
                closeMdModal(overlay.id);
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        const openModal = document.querySelector('.md-modal-overlay.open');

        if (openModal) {
            closeMdModal(openModal.id);
        }
    });

    document.querySelectorAll('[data-check-all]').forEach(function (button) {
        button.addEventListener('click', function () {
            const container = document.getElementById(
                button.getAttribute('data-check-all')
            );

            if (!container) {
                return;
            }

            container
                .querySelectorAll('input[type="checkbox"]')
                .forEach(function (checkbox) {
                    if (!checkbox.disabled) {
                        checkbox.checked = true;
                    }
                });
        });
    });

    document.querySelectorAll('[data-uncheck-all]').forEach(function (button) {
        button.addEventListener('click', function () {
            const container = document.getElementById(
                button.getAttribute('data-uncheck-all')
            );

            if (!container) {
                return;
            }

            container
                .querySelectorAll('input[type="checkbox"]')
                .forEach(function (checkbox) {
                    if (!checkbox.disabled) {
                        checkbox.checked = false;
                    }
                });
        });
    });

    document.querySelectorAll('[data-checklist-search]').forEach(function (input) {
        input.addEventListener('input', function () {
            const container = document.getElementById(
                input.getAttribute('data-checklist-search')
            );

            if (!container) {
                return;
            }

            const query = input.value.trim().toLowerCase();

            container
                .querySelectorAll('.md-check-option')
                .forEach(function (option) {
                    const text = option.getAttribute('data-search-text') || '';
                    option.style.display = text.includes(query) ? '' : 'none';
                });
        });
    });
});
</script>
</body></html>
