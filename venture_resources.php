<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection failed.');
}

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['venture_login']) || empty($_SESSION['venture_id'])) {
    $redirect = 'venture_resources.php';

    if (!empty($_GET['request_resource_id'])) {
        $redirect .= '?request_resource_id=' . (int) $_GET['request_resource_id'];
    }

    header('Location: login.php?redirect=' . urlencode($redirect));
    exit;
}

$venture_id = (int) $_SESSION['venture_id'];

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

function dash_h(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function dash_asset_url(string $path): string
{
    $path = trim($path);

    if ($path === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    return rtrim((string) SITE_URL, '/') . '/' . ltrim($path, '/');
}

function dash_file_size(mixed $bytes): string
{
    $bytes = (float) $bytes;

    if ($bytes <= 0) {
        return '';
    }

    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 1) . ' GB';
    }

    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }

    if ($bytes >= 1024) {
        return round($bytes / 1024) . ' KB';
    }

    return round($bytes) . ' Bytes';
}

function dash_format_date(mixed $date, bool $includeTime = false): string
{
    $date = trim((string) ($date ?? ''));

    if ($date === '') {
        return 'Date unavailable';
    }

    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return 'Date unavailable';
    }

    return $includeTime
        ? date('d M Y, h:i A', $timestamp)
        : date('d M Y', $timestamp);
}

function dash_column_exists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $sql = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    $stmt->close();

    return (int) ($row['total'] ?? 0) > 0;
}

function dash_table_exists(mysqli $conn, string $table): bool
{
    $sql = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $table);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    $stmt->close();

    return (int) ($row['total'] ?? 0) > 0;
}

function dash_is_pdf_resource(array $resource): bool
{
    $type = strtolower(trim((string) ($resource['type'] ?? '')));
    $fileName = strtolower(trim((string) ($resource['file_name'] ?? '')));
    $filePath = strtolower(trim((string) ($resource['file_path'] ?? '')));

    if ($type === 'pdf') {
        return true;
    }

    if (
        $fileName !== ''
        && strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'pdf'
    ) {
        return true;
    }

    if ($filePath !== '') {
        $urlPath = parse_url($filePath, PHP_URL_PATH);

        $checkPath = is_string($urlPath) && $urlPath !== ''
            ? $urlPath
            : $filePath;

        return strtolower(pathinfo($checkPath, PATHINFO_EXTENSION)) === 'pdf';
    }

    return false;
}

function dash_bind_params(
    mysqli_stmt $stmt,
    string $types,
    array $params
): void {
    if ($types === '' || $params === []) {
        return;
    }

    if (strlen($types) !== count($params)) {
        throw new RuntimeException(
            'The number of bind parameter types does not match the number of values.'
        );
    }

    $references = [];

    foreach ($params as $index => $value) {
        $references[$index] = &$params[$index];
    }

    $stmt->bind_param($types, ...$references);
}

function dash_page_url(int $page): string
{
    $query = $_GET;

    unset($query['request_resource_id']);

    $query['page'] = max(1, $page);

    return 'venture_resources.php?' . http_build_query($query);
}

function dash_filter_url(array $changes = []): string
{
    $query = $_GET;

    unset(
        $query['page'],
        $query['request_resource_id']
    );

    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    return $query === []
        ? 'venture_resources.php'
        : 'venture_resources.php?' . http_build_query($query);
}



$hasRequestVentureId = dash_column_exists(
    $conn,
    'resource_requests',
    'venture_id'
);

$hasAdminUsersTable = dash_table_exists($conn, 'admin_users');


$uploaderColumn = null;

$possibleUploaderColumns = [
    'uploaded_by',
    'created_by',
    'uploaded_by_id',
    'user_id',
];

foreach ($possibleUploaderColumns as $possibleColumn) {
    if (dash_column_exists($conn, 'resources', $possibleColumn)) {
        $uploaderColumn = $possibleColumn;
        break;
    }
}

/*
|--------------------------------------------------------------------------
| Detect available admin user display columns
|--------------------------------------------------------------------------
*/

$adminHasFullName = $hasAdminUsersTable
    && dash_column_exists($conn, 'admin_users', 'full_name');

$adminHasUsername = $hasAdminUsersTable
    && dash_column_exists($conn, 'admin_users', 'username');

$adminHasEmail = $hasAdminUsersTable
    && dash_column_exists($conn, 'admin_users', 'email');

/*
|--------------------------------------------------------------------------
| Load venture
|--------------------------------------------------------------------------
*/

$ventureSql = "
    SELECT
        id,
        name,
        email,
        name AS requester_org,
        logo
    FROM ventures
    WHERE id = ?
    LIMIT 1
";

$stmt = $conn->prepare($ventureSql);

if (!$stmt) {
    http_response_code(500);
    exit('The venture record could not be loaded.');
}

$stmt->bind_param('i', $venture_id);
$stmt->execute();

$ventureResult = $stmt->get_result();
$venture = $ventureResult ? $ventureResult->fetch_assoc() : null;

$stmt->close();

if (!$venture) {
    $_SESSION = [];
    session_destroy();

    header('Location: login.php');
    exit;
}

$venture_name = trim((string) ($venture['name'] ?? ''));

if ($venture_name === '') {
    $venture_name = 'Venture';
}

$venture_email = trim((string) ($venture['email'] ?? ''));
$venture_org = trim((string) ($venture['requester_org'] ?? ''));

if ($venture_org === '') {
    $venture_org = $venture_name;
}

/*
|--------------------------------------------------------------------------
| Flash message
|--------------------------------------------------------------------------
*/

$flash_msg = (string) ($_SESSION['dash_resource_flash'] ?? '');
$flash_type = (string) ($_SESSION['dash_resource_flash_type'] ?? 'success');

unset(
    $_SESSION['dash_resource_flash'],
    $_SESSION['dash_resource_flash_type']
);

$allowedFlashTypes = [
    'success',
    'error',
    'danger',
];

if (!in_array($flash_type, $allowedFlashTypes, true)) {
    $flash_type = 'success';
}

/*
|--------------------------------------------------------------------------
| Resource type configuration
|--------------------------------------------------------------------------
*/

$type_labels = [
    'pdf' => [
        'label' => 'PDF',
        'icon' => 'fa-file-pdf',
        'class' => 'res-type-pdf',
    ],
    'video' => [
        'label' => 'Video',
        'icon' => 'fa-film',
        'class' => 'res-type-video',
    ],
    'image' => [
        'label' => 'Image',
        'icon' => 'fa-image',
        'class' => 'res-type-image',
    ],
    'document' => [
        'label' => 'Document',
        'icon' => 'fa-file-word',
        'class' => 'res-type-document',
    ],
    'other' => [
        'label' => 'Other',
        'icon' => 'fa-file',
        'class' => 'res-type-other',
    ],
];

/*
|--------------------------------------------------------------------------
| Filters and pagination
|--------------------------------------------------------------------------
*/

$filter_type = trim((string) ($_GET['type'] ?? ''));
$filter_cat = trim((string) ($_GET['category'] ?? ''));
$search = trim((string) ($_GET['q'] ?? ''));

$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 12;
$offset = ($page - 1) * $per_page;

$where = [
    "r.status = 'active'",
];

$params = [];
$types = '';

if ($filter_type !== '' && isset($type_labels[$filter_type])) {
    $where[] = 'r.type = ?';
    $params[] = $filter_type;
    $types .= 's';
} else {
    $filter_type = '';
}

if ($filter_cat !== '') {
    $where[] = 'r.category = ?';
    $params[] = $filter_cat;
    $types .= 's';
}

if ($search !== '') {
    $where[] = "
        (
            r.title LIKE ?
            OR r.description LIKE ?
            OR r.tags LIKE ?
        )
    ";

    $searchTerm = '%' . $search . '%';

    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;

    $types .= 'sss';
}

$where_sql = implode(' AND ', $where);

/*
|--------------------------------------------------------------------------
| Count matching resources
|--------------------------------------------------------------------------
*/

$count_sql = "
    SELECT COUNT(*) AS total
    FROM resources r
    WHERE {$where_sql}
";

$count_stmt = $conn->prepare($count_sql);

if (!$count_stmt) {
    http_response_code(500);
    exit('The resource count could not be loaded.');
}

try {
    dash_bind_params($count_stmt, $types, $params);
} catch (Throwable $exception) {
    $count_stmt->close();

    http_response_code(500);
    exit('The resource filters could not be processed.');
}

$count_stmt->execute();

$countResult = $count_stmt->get_result();
$count_row = $countResult ? $countResult->fetch_assoc() : null;

$count_stmt->close();

$total_resources = (int) ($count_row['total'] ?? 0);
$total_pages = max(1, (int) ceil($total_resources / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $per_page;
}

/*
|--------------------------------------------------------------------------
| Build latest request join
|--------------------------------------------------------------------------
*/

if ($hasRequestVentureId) {
    $join_condition = "
        rr.resource_id = r.id
        AND (
            rr.venture_id = ?
            OR rr.requester_email = ?
        )
        AND rr.id = (
            SELECT MAX(rr2.id)
            FROM resource_requests rr2
            WHERE rr2.resource_id = r.id
              AND (
                  rr2.venture_id = ?
                  OR rr2.requester_email = ?
              )
        )
    ";

    $requestTypes = 'isis';

    $requestParams = [
        $venture_id,
        $venture_email,
        $venture_id,
        $venture_email,
    ];
} else {
    $join_condition = "
        rr.resource_id = r.id
        AND rr.requester_email = ?
        AND rr.id = (
            SELECT MAX(rr2.id)
            FROM resource_requests rr2
            WHERE rr2.resource_id = r.id
              AND rr2.requester_email = ?
        )
    ";

    $requestTypes = 'ss';

    $requestParams = [
        $venture_email,
        $venture_email,
    ];
}

/*
|--------------------------------------------------------------------------
| Build uploader join and display expression
|--------------------------------------------------------------------------
*/

$uploaderJoin = '';
$uploaderSelect = "'System' AS uploaded_by_name";
$uploaderIdSelect = 'NULL AS uploaded_by_user_id';

if ($uploaderColumn !== null && $hasAdminUsersTable) {
    $uploaderJoin = "
        LEFT JOIN admin_users au
            ON au.id = r.`{$uploaderColumn}`
    ";

    $displayColumns = [];

    if ($adminHasFullName) {
        $displayColumns[] = "NULLIF(TRIM(au.full_name), '')";
    }

    if ($adminHasUsername) {
        $displayColumns[] = "NULLIF(TRIM(au.username), '')";
    }

    if ($adminHasEmail) {
        $displayColumns[] = "NULLIF(TRIM(au.email), '')";
    }

    $displayColumns[] = "'System'";

    $uploaderSelect = "
        COALESCE(
            " . implode(",\n            ", $displayColumns) . "
        ) AS uploaded_by_name
    ";

    $uploaderIdSelect = "r.`{$uploaderColumn}` AS uploaded_by_user_id";
} elseif ($uploaderColumn !== null) {
    $uploaderSelect = "
        CASE
            WHEN r.`{$uploaderColumn}` IS NULL
                 OR r.`{$uploaderColumn}` = 0
            THEN 'System'
            ELSE CONCAT('User #', r.`{$uploaderColumn}`)
        END AS uploaded_by_name
    ";

    $uploaderIdSelect = "r.`{$uploaderColumn}` AS uploaded_by_user_id";
}

/*
|--------------------------------------------------------------------------
| Load resources
|--------------------------------------------------------------------------
*/

$finalTypes = $requestTypes . $types . 'ii';

$finalParams = array_merge(
    $requestParams,
    $params,
    [
        $per_page,
        $offset,
    ]
);

$sql = "
    SELECT
        r.*,
        {$uploaderSelect},
        {$uploaderIdSelect},

        rr.id AS request_id,
        rr.status AS request_status,
        rr.admin_note,
        rr.download_token,
        rr.token_expires,
        rr.created_at AS requested_at,
        rr.approved_at

    FROM resources r

    {$uploaderJoin}

    LEFT JOIN resource_requests rr
        ON {$join_condition}

    WHERE {$where_sql}

    ORDER BY
        uploaded_by_name ASC,
        r.created_at DESC,
        r.id DESC

    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log(
        '[venture_resources] Resource query preparation failed: '
        . $conn->error
    );

    http_response_code(500);
    exit('The resources could not be loaded.');
}

try {
    dash_bind_params($stmt, $finalTypes, $finalParams);
} catch (Throwable $exception) {
    $stmt->close();

    error_log(
        '[venture_resources] Resource binding error: '
        . $exception->getMessage()
    );

    http_response_code(500);
    exit('The resource query could not be processed.');
}

$stmt->execute();

$resourceResult = $stmt->get_result();

$resources = $resourceResult
    ? $resourceResult->fetch_all(MYSQLI_ASSOC)
    : [];

$stmt->close();

/*
|--------------------------------------------------------------------------
| Group resources by uploader
|--------------------------------------------------------------------------
*/

$resourcesByUploader = [];

foreach ($resources as $resource) {
    $uploaderName = trim(
        (string) ($resource['uploaded_by_name'] ?? '')
    );

    if ($uploaderName === '') {
        $uploaderName = 'System';
    }

    if (!isset($resourcesByUploader[$uploaderName])) {
        $resourcesByUploader[$uploaderName] = [];
    }

    $resourcesByUploader[$uploaderName][] = $resource;
}

/*
|--------------------------------------------------------------------------
| Load categories
|--------------------------------------------------------------------------
*/

$all_cats = [];

$categoriesQuery = $conn->query("
    SELECT DISTINCT category
    FROM resources
    WHERE status = 'active'
      AND category IS NOT NULL
      AND TRIM(category) != ''
    ORDER BY category ASC
");

if ($categoriesQuery) {
    while ($row = $categoriesQuery->fetch_assoc()) {
        $category = trim((string) ($row['category'] ?? ''));

        if ($category !== '') {
            $all_cats[] = $category;
        }
    }

    $categoriesQuery->free();
}

/*
|--------------------------------------------------------------------------
| Load resource type totals
|--------------------------------------------------------------------------
*/

$type_counts = [];

$typeQuery = $conn->query("
    SELECT
        type,
        COUNT(*) AS total
    FROM resources
    WHERE status = 'active'
    GROUP BY type
");

if ($typeQuery) {
    while ($row = $typeQuery->fetch_assoc()) {
        $resourceType = trim((string) ($row['type'] ?? ''));

        if ($resourceType !== '') {
            $type_counts[$resourceType] = (int) ($row['total'] ?? 0);
        }
    }

    $typeQuery->free();
}

/*
|--------------------------------------------------------------------------
| Open requested resource modal
|--------------------------------------------------------------------------
*/

$request_resource = null;

if (!empty($_GET['request_resource_id'])) {
    $request_resource_id = (int) $_GET['request_resource_id'];

    if ($request_resource_id > 0) {
        $requestSql = "
            SELECT *
            FROM resources
            WHERE id = ?
              AND status = 'active'
            LIMIT 1
        ";

        $stmt = $conn->prepare($requestSql);

        if ($stmt) {
            $stmt->bind_param('i', $request_resource_id);
            $stmt->execute();

            $requestResult = $stmt->get_result();

            $request_resource = $requestResult
                ? $requestResult->fetch_assoc()
                : null;

            $stmt->close();
        }
    }
}

$from_record = $total_resources > 0
    ? $offset + 1
    : 0;

$to_record = min(
    $offset + $per_page,
    $total_resources
);

/*
|--------------------------------------------------------------------------
| Page layout
|--------------------------------------------------------------------------
*/

include __DIR__ . '/layout.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/includes/favicon.php'; ?>
    <meta charset="UTF-8">

    <title>Resources - Venture Dashboard</title>

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <link
        rel="stylesheet"
        href="assets/css/portal.css"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css"
        referrerpolicy="no-referrer"
    >

    <style>
        .resource-uploader-section {
            margin-bottom: 38px;
        }

        .resource-uploader-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin: 0 0 18px;
            padding: 14px 18px;
            border: 1px solid rgba(102, 0, 51, 0.12);
            border-left: 4px solid #660033;
            border-radius: 12px;
            background: #ffffff;
            box-shadow: 0 8px 24px rgba(34, 24, 29, 0.05);
        }

        .resource-uploader-profile {
            display: flex;
            align-items: center;
            min-width: 0;
            gap: 12px;
        }

        .resource-uploader-avatar {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(102, 0, 51, 0.1);
            color: #660033;
            font-size: 1rem;
        }

        .resource-uploader-text {
            min-width: 0;
        }

        .resource-uploader-text h2 {
            margin: 0;
            color: #2d1722;
            font-size: 1rem;
            font-weight: 800;
            line-height: 1.3;
            overflow-wrap: anywhere;
        }

        .resource-uploader-text p {
            margin: 3px 0 0;
            color: #7b6b73;
            font-size: 0.8rem;
        }

        .resource-uploader-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            min-width: 36px;
            height: 30px;
            padding: 0 10px;
            border-radius: 999px;
            background: #660033;
            color: #ffffff;
            font-size: 0.78rem;
            font-weight: 800;
        }

        .resource-upload-info {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px 14px;
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid rgba(42, 27, 34, 0.08);
            color: #75656d;
            font-size: 0.78rem;
        }

        .resource-upload-info span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .resource-upload-info i {
            color: #660033;
        }

        @media (max-width: 640px) {
            .resource-uploader-heading {
                align-items: flex-start;
            }

            .resource-uploader-count {
                margin-top: 5px;
            }
        }
    </style>
</head>

<body>

<div class="venture-wrap">
    <div class="venture-shell">

        <div class="venture-top">
            <div class="venture-title">
                <h1>Resources</h1>

                <p>
                    Welcome, <?= dash_h($venture_name) ?>.
                    Access public resources or request restricted downloads.
                </p>
            </div>
        </div>

        <div class="resource-filter-card">
            <form
                method="GET"
                action="venture_resources.php"
            >
                <?php if ($filter_type !== ''): ?>
                    <input
                        type="hidden"
                        name="type"
                        value="<?= dash_h($filter_type) ?>"
                    >
                <?php endif; ?>

                <?php if ($filter_cat !== ''): ?>
                    <input
                        type="hidden"
                        name="category"
                        value="<?= dash_h($filter_cat) ?>"
                    >
                <?php endif; ?>

                <div class="resource-search">
                    <input
                        type="text"
                        name="q"
                        value="<?= dash_h($search) ?>"
                        placeholder="Search resources..."
                        aria-label="Search resources"
                    >

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        <i class="fa fa-search"></i>
                        Search
                    </button>

                    <?php if (
                        $search !== ''
                        || $filter_type !== ''
                        || $filter_cat !== ''
                    ): ?>
                        <a
                            href="venture_resources.php"
                            class="btn btn-outline"
                        >
                            <i class="fa fa-times"></i>
                            Clear
                        </a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="filter-chips">
                <a
                    href="<?= dash_h(
                        dash_filter_url([
                            'type' => null,
                        ])
                    ) ?>"
                    class="filter-chip <?= $filter_type === '' ? 'active' : '' ?>"
                >
                    <i class="fa fa-layer-group"></i>
                    All Types
                </a>

                <?php foreach ($type_labels as $typeKey => $typeData): ?>
                    <?php
                    if (!isset($type_counts[$typeKey])) {
                        continue;
                    }
                    ?>

                    <a
                        href="<?= dash_h(
                            dash_filter_url([
                                'type' => $typeKey,
                            ])
                        ) ?>"
                        class="filter-chip <?= $filter_type === $typeKey ? 'active' : '' ?>"
                    >
                        <i class="fa <?= dash_h($typeData['icon']) ?>"></i>
                        <?= dash_h($typeData['label']) ?>
                    </a>
                <?php endforeach; ?>

                <?php foreach ($all_cats as $category): ?>
                    <a
                        href="<?= dash_h(
                            dash_filter_url([
                                'category' => $category,
                            ])
                        ) ?>"
                        class="filter-chip <?= $filter_cat === $category ? 'active' : '' ?>"
                    >
                        <i class="fa fa-folder"></i>
                        <?= dash_h($category) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($total_resources > 0): ?>
            <div class="resource-results-summary">
                Showing
                <?= (int) $from_record ?>
                -
                <?= (int) $to_record ?>
                of
                <?= (int) $total_resources ?>
                resources
            </div>
        <?php endif; ?>

        <?php if ($resourcesByUploader === []): ?>

            <div class="empty-box">
                <i class="fa fa-folder-open"></i>

                <h3>No resources found</h3>

                <p>
                    Try adjusting your filters or search term.
                </p>
            </div>

        <?php else: ?>

            <?php foreach ($resourcesByUploader as $uploaderName => $uploaderResources): ?>

                <section class="resource-uploader-section">

                    <div class="resource-uploader-heading">
                        <div class="resource-uploader-profile">

                            <div class="resource-uploader-avatar">
                                <i class="fa fa-user"></i>
                            </div>

                            <div class="resource-uploader-text">
                                <h2>
                                    <?= dash_h($uploaderName) ?>
                                </h2>

                                <p>
                                    Uploaded resources, newest first
                                </p>
                            </div>
                        </div>

                        <span class="resource-uploader-count">
                            <?= count($uploaderResources) ?>
                        </span>
                    </div>

                    <div class="resource-grid">

                        <?php foreach ($uploaderResources as $resource): ?>
                            <?php
                            $type = trim(
                                (string) ($resource['type'] ?? 'other')
                            );

                            if (!isset($type_labels[$type])) {
                                $type = 'other';
                            }

                            $typeData = $type_labels[$type];

                            $thumbnail = trim(
                                (string) ($resource['thumbnail'] ?? '')
                            );

                            $accessLevel = trim(
                                (string) (
                                    $resource['access_level']
                                    ?? 'request_required'
                                )
                            );

                            $requestStatus = trim(
                                (string) (
                                    $resource['request_status']
                                    ?? ''
                                )
                            );

                            $isApproved = $requestStatus === 'approved';
                            $isPending = $requestStatus === 'pending';
                            $isRejected = $requestStatus === 'rejected';

                            $canDownload = (
                                $accessLevel === 'public'
                                || $isApproved
                            );

                            $isPdf = dash_is_pdf_resource($resource);

                            $viewUrl = 'resource-file.php?id='
                                . (int) $resource['id']
                                . '&mode=view';

                            $downloadUrl = 'resource-file.php?id='
                                . (int) $resource['id']
                                . '&mode=download';

                            $resourceTitle = trim(
                                (string) (
                                    $resource['title']
                                    ?? 'Untitled resource'
                                )
                            );

                            $resourceDescription = trim(
                                (string) (
                                    $resource['description']
                                    ?? ''
                                )
                            );

                            $fileSize = dash_file_size(
                                $resource['file_size'] ?? 0
                            );

                            $uploadedDate = dash_format_date(
                                $resource['created_at'] ?? null,
                                true
                            );
                            ?>

                            <article class="resource-card">

                                <div class="resource-thumb <?= dash_h($typeData['class']) ?>">

                                    <?php if ($thumbnail !== ''): ?>

                                        <img
                                            src="<?= dash_h(
                                                dash_asset_url($thumbnail)
                                            ) ?>"
                                            alt="<?= dash_h($resourceTitle) ?>"
                                            loading="lazy"
                                        >

                                    <?php else: ?>

                                        <i class="fa <?= dash_h($typeData['icon']) ?>"></i>

                                    <?php endif; ?>

                                    <?php if ($accessLevel === 'public'): ?>

                                        <span class="resource-badge badge-public">
                                            <i class="fa fa-globe"></i>
                                            Public
                                        </span>

                                    <?php else: ?>

                                        <span class="resource-badge badge-locked">
                                            <i class="fa fa-lock"></i>
                                            Restricted
                                        </span>

                                    <?php endif; ?>

                                </div>

                                <div class="resource-body">

                                    <?php if (!empty($resource['category'])): ?>

                                        <span class="resource-category">
                                            <i class="fa fa-tag"></i>

                                            <?= dash_h(
                                                $resource['category']
                                            ) ?>
                                        </span>

                                    <?php endif; ?>

                                    <h3>
                                        <?= dash_h($resourceTitle) ?>
                                    </h3>

                                    <?php if ($resourceDescription !== ''): ?>

                                        <p>
                                            <?= dash_h(
                                                mb_strimwidth(
                                                    $resourceDescription,
                                                    0,
                                                    120,
                                                    '...'
                                                )
                                            ) ?>
                                        </p>

                                    <?php endif; ?>

                                    <div class="resource-meta">
                                        <span>
                                            <i class="fa <?= dash_h($typeData['icon']) ?>"></i>

                                            <?= dash_h($typeData['label']) ?>
                                        </span>

                                        <?php if ($fileSize !== ''): ?>
                                            <span>
                                                <i class="fa fa-database"></i>

                                                <?= dash_h($fileSize) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="resource-upload-info">
                                        <span>
                                            <i class="fa fa-user"></i>

                                            <?= dash_h(
                                                $resource['uploaded_by_name']
                                                ?? 'System'
                                            ) ?>
                                        </span>

                                        <span>
                                            <i class="fa fa-calendar-alt"></i>

                                            <?= dash_h($uploadedDate) ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="resource-footer">

                                    <?php if ($isPending): ?>

                                        <div class="request-status status-pending">
                                            <i class="fa fa-clock"></i>
                                            Request pending approval
                                        </div>

                                    <?php elseif ($isRejected): ?>

                                        <div class="request-status status-rejected">
                                            <i class="fa fa-times-circle"></i>
                                            Request rejected

                                            <?php if (!empty($resource['admin_note'])): ?>
                                                <br>

                                                <small>
                                                    <?= dash_h(
                                                        $resource['admin_note']
                                                    ) ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>

                                    <?php elseif ($isApproved): ?>

                                        <div class="request-status status-approved">
                                            <i class="fa fa-check-circle"></i>
                                            Access approved
                                        </div>

                                    <?php endif; ?>

                                    <?php if ($canDownload): ?>

                                        <?php if ($isPdf): ?>

                                            <div class="resource-actions">
                                                <a
                                                    href="<?= dash_h($viewUrl) ?>"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="btn btn-outline"
                                                >
                                                    <i class="fa fa-eye"></i>
                                                    View
                                                </a>

                                                <a
                                                    href="<?= dash_h($downloadUrl) ?>"
                                                    class="btn btn-primary"
                                                >
                                                    <i class="fa fa-download"></i>
                                                    Download
                                                </a>
                                            </div>

                                        <?php else: ?>

                                            <a
                                                href="<?= dash_h($downloadUrl) ?>"
                                                class="btn btn-primary w-100"
                                            >
                                                <i class="fa fa-download"></i>
                                                Download
                                            </a>

                                        <?php endif; ?>

                                    <?php elseif ($isPending): ?>

                                        <button
                                            type="button"
                                            class="btn btn-outline w-100"
                                            disabled
                                        >
                                            <i class="fa fa-clock"></i>
                                            Awaiting Approval
                                        </button>

                                    <?php else: ?>

                                        <button
                                            type="button"
                                            class="btn btn-primary w-100 request-access-btn"
                                            data-resource-id="<?= (int) $resource['id'] ?>"
                                            data-resource-title="<?= dash_h($resourceTitle) ?>"
                                        >
                                            <i class="fa fa-lock"></i>
                                            Request Access
                                        </button>

                                    <?php endif; ?>

                                </div>
                            </article>

                        <?php endforeach; ?>

                    </div>
                </section>

            <?php endforeach; ?>

            <?php if ($total_pages > 1): ?>

                <nav
                    class="pagination resource-pagination"
                    aria-label="Resources pagination"
                >
                    <?php if ($page > 1): ?>

                        <a
                            class="page-btn"
                            href="<?= dash_h(
                                dash_page_url($page - 1)
                            ) ?>"
                        >
                            <i class="fa fa-chevron-left"></i>
                            Prev
                        </a>

                    <?php else: ?>

                        <span class="page-btn disabled">
                            <i class="fa fa-chevron-left"></i>
                            Prev
                        </span>

                    <?php endif; ?>

                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($total_pages, $page + 2);
                    ?>

                    <?php if ($startPage > 1): ?>

                        <a
                            class="page-btn"
                            href="<?= dash_h(dash_page_url(1)) ?>"
                        >
                            1
                        </a>

                        <?php if ($startPage > 2): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>

                    <?php endif; ?>

                    <?php for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++): ?>

                        <a
                            class="page-btn <?= $pageNumber === $page ? 'active' : '' ?>"
                            href="<?= dash_h(
                                dash_page_url($pageNumber)
                            ) ?>"
                            <?= $pageNumber === $page ? 'aria-current="page"' : '' ?>
                        >
                            <?= (int) $pageNumber ?>
                        </a>

                    <?php endfor; ?>

                    <?php if ($endPage < $total_pages): ?>

                        <?php if ($endPage < $total_pages - 1): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>

                        <a
                            class="page-btn"
                            href="<?= dash_h(
                                dash_page_url($total_pages)
                            ) ?>"
                        >
                            <?= (int) $total_pages ?>
                        </a>

                    <?php endif; ?>

                    <?php if ($page < $total_pages): ?>

                        <a
                            class="page-btn"
                            href="<?= dash_h(
                                dash_page_url($page + 1)
                            ) ?>"
                        >
                            Next
                            <i class="fa fa-chevron-right"></i>
                        </a>

                    <?php else: ?>

                        <span class="page-btn disabled">
                            Next
                            <i class="fa fa-chevron-right"></i>
                        </span>

                    <?php endif; ?>
                </nav>

            <?php endif; ?>

        <?php endif; ?>

    </div>
</div>

<div
    class="modal-overlay"
    id="requestModal"
    aria-hidden="true"
>
    <div
        class="request-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="requestModalTitle"
    >
        <div class="request-modal-header">
            <h2 id="requestModalTitle">
                <i class="fa fa-lock"></i>
                Request Resource Access
            </h2>

            <button
                type="button"
                class="modal-close"
                id="closeRequestModalBtn"
                aria-label="Close request modal"
            >
                &times;
            </button>
        </div>

        <form
            method="POST"
            action="includes/process-resources.php"
        >
            <input
                type="hidden"
                name="action"
                value="request_access"
            >

            <input
                type="hidden"
                name="resource_id"
                id="request_resource_id"
            >

            <input
                type="hidden"
                name="requester_name"
                value="<?= dash_h($venture_name) ?>"
            >

            <input
                type="hidden"
                name="requester_email"
                value="<?= dash_h($venture_email) ?>"
            >

            <input
                type="hidden"
                name="requester_org"
                value="<?= dash_h($venture_org) ?>"
            >

            <div class="request-modal-body">
                <p
                    id="requestIntro"
                    class="request-intro"
                ></p>

                <div class="form-group">
                    <label for="requestReason">
                        Reason for access
                        <span class="req">*</span>
                    </label>

                    <textarea
                        id="requestReason"
                        name="reason"
                        class="form-control"
                        rows="5"
                        required
                        placeholder="Briefly explain why you need this resource..."
                    ></textarea>
                </div>
            </div>

            <div class="request-modal-footer">
                <button
                    type="button"
                    class="btn btn-outline"
                    id="cancelRequestModalBtn"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    <i class="fa fa-paper-plane"></i>
                    Submit Request
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($flash_msg !== ''): ?>

    <div
        class="modal-overlay open"
        id="flashModal"
        aria-hidden="false"
    >
        <div
            class="request-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="flashModalTitle"
        >
            <div class="request-modal-header">

                <h2 id="flashModalTitle">
                    <?php if ($flash_type === 'success'): ?>

                        <i class="fa fa-check-circle flash-icon-success"></i>
                        Success

                    <?php else: ?>

                        <i class="fa fa-exclamation-circle flash-icon-error"></i>
                        Error

                    <?php endif; ?>
                </h2>

                <button
                    type="button"
                    class="modal-close"
                    id="closeFlashModalBtn"
                    aria-label="Close message"
                >
                    &times;
                </button>
            </div>

            <div class="request-modal-body">
                <p class="flash-message-text">
                    <?= dash_h($flash_msg) ?>
                </p>
            </div>

            <div class="request-modal-footer">
                <button
                    type="button"
                    class="btn btn-primary"
                    id="okFlashModalBtn"
                >
                    OK
                </button>
            </div>
        </div>
    </div>

<?php endif; ?>

<script>
(function () {
    'use strict';

    function getElement(id) {
        return document.getElementById(id);
    }

    function setBodyModalState() {
        var openModal = document.querySelector('.modal-overlay.open');

        document.body.classList.toggle(
            'vp-modal-open',
            Boolean(openModal)
        );
    }

    function openRequestModal(resourceId, resourceTitle) {
        var modal = getElement('requestModal');
        var resourceIdInput = getElement('request_resource_id');
        var intro = getElement('requestIntro');
        var reason = getElement('requestReason');

        if (!modal || !resourceIdInput || !intro) {
            return;
        }

        resourceIdInput.value = String(resourceId);
        intro.textContent =
            'You are requesting access to: ' + resourceTitle;

        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');

        setBodyModalState();

        window.setTimeout(function () {
            if (reason) {
                reason.focus();
            }
        }, 50);
    }

    function closeRequestModal() {
        var modal = getElement('requestModal');

        if (!modal) {
            return;
        }

        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');

        setBodyModalState();
    }

    function closeFlashModal() {
        var modal = getElement('flashModal');

        if (!modal) {
            return;
        }

        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');

        setBodyModalState();
    }

    document.addEventListener('DOMContentLoaded', function () {
        var requestButtons = document.querySelectorAll(
            '.request-access-btn'
        );

        requestButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                openRequestModal(
                    this.getAttribute('data-resource-id') || '',
                    this.getAttribute('data-resource-title')
                        || 'Selected resource'
                );
            });
        });

        var requestModal = getElement('requestModal');
        var closeRequestButton = getElement(
            'closeRequestModalBtn'
        );
        var cancelRequestButton = getElement(
            'cancelRequestModalBtn'
        );

        if (closeRequestButton) {
            closeRequestButton.addEventListener(
                'click',
                closeRequestModal
            );
        }

        if (cancelRequestButton) {
            cancelRequestButton.addEventListener(
                'click',
                closeRequestModal
            );
        }

        if (requestModal) {
            requestModal.addEventListener('click', function (event) {
                if (event.target === requestModal) {
                    closeRequestModal();
                }
            });
        }

        var flashModal = getElement('flashModal');
        var closeFlashButton = getElement(
            'closeFlashModalBtn'
        );
        var okayFlashButton = getElement(
            'okFlashModalBtn'
        );

        if (closeFlashButton) {
            closeFlashButton.addEventListener(
                'click',
                closeFlashModal
            );
        }

        if (okayFlashButton) {
            okayFlashButton.addEventListener(
                'click',
                closeFlashModal
            );
        }

        if (flashModal) {
            flashModal.addEventListener('click', function (event) {
                if (event.target === flashModal) {
                    closeFlashModal();
                }
            });
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeRequestModal();
                closeFlashModal();
            }
        });

        setBodyModalState();

        <?php if ($request_resource && $flash_msg === ''): ?>

        openRequestModal(
            <?= (int) $request_resource['id'] ?>,
            <?= json_encode(
                (string) ($request_resource['title'] ?? 'Selected resource'),
                JSON_HEX_TAG
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
                | JSON_HEX_AMP
            ) ?>
        );

        <?php endif; ?>
    });
})();
</script>

</body>
</html>
