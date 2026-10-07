<?php
declare(strict_types=1);

require_once '../includes/config.php';
require_once 'includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection error.');
}

$conn->set_charset('utf8mb4');


$adminReferralAction = trim(
    (string)(
        $_POST['referral_action']
        ?? $_POST['action']
        ?? ''
    )
);

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array(
        $adminReferralAction,
        ['update_status', 'delete'],
        true
    )
) {
    require __DIR__ . '/includes/process-referrals.php';
    exit;
}

/* ============================================================
   HELPERS
============================================================ */

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars(
            (string)($value ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

function ar_table_exists(mysqli $conn): bool
{
    $result = $conn->query(
        "SHOW TABLES LIKE 'venture_referrals'"
    );

    return $result instanceof mysqli_result
        && $result->num_rows > 0;
}

function ar_count(
    mysqli $conn,
    string $where = '1=1'
): int {
    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM venture_referrals
        WHERE {$where}
    ");

    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();

    return (int)($row['total'] ?? 0);
}

function ar_url(array $overrides = []): string
{
    $query = array_merge(
        $_GET,
        $overrides
    );

    foreach ($query as $key => $value) {
        if (
            $value === ''
            || $value === null
        ) {
            unset($query[$key]);
        }
    }

    return 'referrals'
        . (
            $query
                ? '?' . http_build_query($query)
                : ''
        );
}

function ar_status_label(string $status): string
{
    return match ($status) {
        'new'          => 'New',
        'contacted'    => 'Contacted',
        'reviewing'    => 'Reviewing',
        'invited'      => 'Invited',
        'not_eligible' => 'Not Eligible',
        'closed'       => 'Closed',
        default        => ucfirst(
            str_replace('_', ' ', $status)
        ),
    };
}

function ar_status_class(string $status): string
{
    return match ($status) {
        'new'          => 'ref-status-new',
        'contacted'    => 'ref-status-contacted',
        'reviewing'    => 'ref-status-reviewing',
        'invited'      => 'ref-status-invited',
        'not_eligible' => 'ref-status-not-eligible',
        'closed'       => 'ref-status-closed',
        default        => 'ref-status-closed',
    };
}

function ar_initials(string $name): string
{
    $parts = preg_split(
        '/\s+/',
        trim($name)
    ) ?: [];

    $initials = '';

    foreach (
        array_slice($parts, 0, 2)
        as $part
    ) {
        if ($part !== '') {
            $initials .= mb_strtoupper(
                mb_substr($part, 0, 1)
            );
        }
    }

    return $initials !== ''
        ? $initials
        : 'VR';
}

function ar_datetime(?string $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return '�';
    }

    try {
        return (
            new DateTime($value)
        )->format(
            'd M Y, g:i A'
        );
    } catch (Throwable $e) {
        return $value;
    }
}

/* ============================================================
   SUMMARY STATISTICS
============================================================ */

$tableExists = ar_table_exists($conn);

$totalAll = 0;
$totalToday = 0;
$totalWeek = 0;
$totalMonth = 0;
$totalNew = 0;

if ($tableExists) {
    $totalAll = ar_count($conn);

    $totalToday = ar_count(
        $conn,
        "DATE(created_at) = CURDATE()"
    );

    $totalWeek = ar_count(
        $conn,
        "YEARWEEK(created_at,1) = YEARWEEK(CURDATE(),1)"
    );

    $totalMonth = ar_count(
        $conn,
        "YEAR(created_at) = YEAR(CURDATE())
         AND MONTH(created_at) = MONTH(CURDATE())"
    );

    $totalNew = ar_count(
        $conn,
        "status = 'new'"
    );
}

/* ============================================================
   FILTERS
============================================================ */

$search = trim(
    (string)(
        $_GET['search']
        ?? ''
    )
);

$status = trim(
    (string)(
        $_GET['status']
        ?? ''
    )
);

$period = trim(
    (string)(
        $_GET['period']
        ?? 'all'
    )
);

$dateFrom = trim(
    (string)(
        $_GET['date_from']
        ?? ''
    )
);

$dateTo = trim(
    (string)(
        $_GET['date_to']
        ?? ''
    )
);

$statuses = [
    'new',
    'contacted',
    'reviewing',
    'invited',
    'not_eligible',
    'closed',
];

if (
    $status !== ''
    && !in_array(
        $status,
        $statuses,
        true
    )
) {
    $status = '';
}

$periods = [
    'all',
    'today',
    'week',
    'month',
    'custom',
];

if (
    !in_array(
        $period,
        $periods,
        true
    )
) {
    $period = 'all';
}

/* ============================================================
   PAGINATION
============================================================ */

$perPageOptions = [
    10,
    20,
    50,
    100,
];

$perPage = (int)(
    $_GET['per_page']
    ?? 20
);

if (
    !in_array(
        $perPage,
        $perPageOptions,
        true
    )
) {
    $perPage = 20;
}

$page = max(
    1,
    (int)(
        $_GET['page']
        ?? 1
    )
);

$referrals = [];
$totalFiltered = 0;
$totalPages = 1;
$offset = 0;

/* ============================================================
   FILTERED QUERY
============================================================ */

if ($tableExists) {
    $where = ['1=1'];
    $types = '';
    $params = [];

    if ($search !== '') {
        $like =
            '%' . $search . '%';

        $where[] = "(
            venture_name LIKE ?
            OR founder_name LIKE ?
            OR founder_email LIKE ?
            OR full_name LIKE ?
            OR email LIKE ?
            OR organisation LIKE ?
            OR telephone LIKE ?
            OR cohort_label LIKE ?
        )";

        $types .= 'ssssssss';

        for ($i = 0; $i < 8; $i++) {
            $params[] = $like;
        }
    }

    if ($status !== '') {
        $where[] = 'status = ?';
        $types .= 's';
        $params[] = $status;
    }

    if ($period === 'today') {
        $where[] =
            'DATE(created_at) = CURDATE()';
    }

    if ($period === 'week') {
        $where[] =
            'YEARWEEK(created_at,1) = YEARWEEK(CURDATE(),1)';
    }

    if ($period === 'month') {
        $where[] = "
            YEAR(created_at) = YEAR(CURDATE())
            AND MONTH(created_at) = MONTH(CURDATE())
        ";
    }

    if ($period === 'custom') {
        if (
            preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $dateFrom
            )
        ) {
            $where[] =
                'DATE(created_at) >= ?';

            $types .= 's';
            $params[] = $dateFrom;
        }

        if (
            preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $dateTo
            )
        ) {
            $where[] =
                'DATE(created_at) <= ?';

            $types .= 's';
            $params[] = $dateTo;
        }
    }

    $whereSql = implode(
        ' AND ',
        $where
    );

    /* Total matching rows */
    $countStmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM venture_referrals
        WHERE {$whereSql}
    ");

    if ($countStmt) {
        if ($types !== '') {
            $countStmt->bind_param(
                $types,
                ...$params
            );
        }

        $countStmt->execute();

        $row = $countStmt
            ->get_result()
            ->fetch_assoc();

        $totalFiltered = (int)(
            $row['total']
            ?? 0
        );

        $countStmt->close();
    }

    $totalPages = max(
        1,
        (int)ceil(
            $totalFiltered
            / $perPage
        )
    );

    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $offset =
        ($page - 1)
        * $perPage;

    /* Matching referral rows */
    $listStmt = $conn->prepare("
        SELECT
            id,
            full_name,
            organisation,
            role_position,
            email,
            telephone,
            relationship_to_venture,
            venture_name,
            founder_name,
            founder_email,
            website_link,
            referral_reason,
            venture_informed,
            information_accurate,
            cohort_label,
            status,
            ip_address,
            user_agent,
            created_at,
            updated_at
        FROM venture_referrals
        WHERE {$whereSql}
        ORDER BY created_at DESC, id DESC
        LIMIT ? OFFSET ?
    ");

    if ($listStmt) {
        $listTypes =
            $types . 'ii';

        $listParams = array_merge(
            $params,
            [
                $perPage,
                $offset,
            ]
        );

        $listStmt->bind_param(
            $listTypes,
            ...$listParams
        );

        $listStmt->execute();

        $result =
            $listStmt->get_result();

        while (
            $row =
                $result->fetch_assoc()
        ) {
            $referrals[] = $row;
        }

        $listStmt->close();
    }
}

/* ============================================================
   FLASH MESSAGES
============================================================ */

$success = trim(
    (string)(
        $_SESSION['referrals_success']
        ?? ''
    )
);

$error = trim(
    (string)(
        $_SESSION['referrals_error']
        ?? ''
    )
);

unset(
    $_SESSION['referrals_success'],
    $_SESSION['referrals_error']
);

$showingFrom =
    $totalFiltered > 0
        ? $offset + 1
        : 0;

$showingTo =
    $totalFiltered > 0
        ? min(
            $offset + $perPage,
            $totalFiltered
        )
        : 0;

$siteName =
    function_exists('get_setting')
        ? get_setting(
            $conn,
            'site_name',
            'EdTech Fellowship'
        )
        : 'EdTech Fellowship';

?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<title>
    Referrals - <?= h($siteName) ?> Admin
</title>

<link
    href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css"
>

<link
    rel="stylesheet"
    href="assets/css/admin.css"
>

</head>

<body>

<?php
include 'includes/sidebar.php';
?>

<div
    class="admin-main"
    id="adminMain"
>

<header class="admin-topbar">

    <div class="topbar-left">

        <div class="topbar-breadcrumb">

            <a href="index.php">
                Dashboard
            </a>

            <span>&rsaquo;</span>

            <strong>
                Referrals
            </strong>

        </div>

    </div>

</header>


<main
    class="admin-content referrals-admin-page"
>

    <div class="page-header">

        <div>

            <h1 class="page-title">
                Venture Referrals
            </h1>

            <p class="page-subtitle">
                Review and manage ventures referred for the Mastercard
                Foundation EdTech Fellowship.
            </p>

        </div>

        <div class="page-actions">

            <a
                href="../referral"
                target="_blank"
                rel="noopener"
                class="btn btn-secondary"
            >
                <i class="fa fa-external-link-alt"></i>
                Open Referral Form
            </a>

        </div>

    </div>


    <?php if ($success !== ''): ?>

        <div class="alert alert-success">
            <?= h($success) ?>
        </div>

    <?php endif; ?>


    <?php if ($error !== ''): ?>

        <div class="alert alert-error">
            <?= h($error) ?>
        </div>

    <?php endif; ?>


    <?php if (!$tableExists): ?>

        <div class="alert alert-error">
            The venture referrals table was not found.
            Submit a referral first or create the
            <strong>venture_referrals</strong> table.
        </div>

    <?php endif; ?>


    <!-- STATISTICS -->

    <section class="referrals-stats-grid">

        <article class="referral-stat-card is-today">

            <div class="referral-stat-icon">
                <i class="fa fa-calendar-day"></i>
            </div>

            <div>

                <div class="referral-stat-value">
                    <?= number_format($totalToday) ?>
                </div>

                <div class="referral-stat-label">
                    Daily
                </div>

                <div class="referral-stat-note">
                    Referrals received today
                </div>

            </div>

        </article>


        <article class="referral-stat-card is-week">

            <div class="referral-stat-icon">
                <i class="fa fa-calendar-week"></i>
            </div>

            <div>

                <div class="referral-stat-value">
                    <?= number_format($totalWeek) ?>
                </div>

                <div class="referral-stat-label">
                    Weekly
                </div>

                <div class="referral-stat-note">
                    Current week
                </div>

            </div>

        </article>


        <article class="referral-stat-card is-month">

            <div class="referral-stat-icon">
                <i class="fa fa-calendar-alt"></i>
            </div>

            <div>

                <div class="referral-stat-value">
                    <?= number_format($totalMonth) ?>
                </div>

                <div class="referral-stat-label">
                    Monthly
                </div>

                <div class="referral-stat-note">
                    Current month
                </div>

            </div>

        </article>


        <article class="referral-stat-card is-total">

            <div class="referral-stat-icon">
                <i class="fa fa-share-alt"></i>
            </div>

            <div>

                <div class="referral-stat-value">
                    <?= number_format($totalAll) ?>
                </div>

                <div class="referral-stat-label">
                    Total
                </div>

                <div class="referral-stat-note">
                    <?= number_format($totalNew) ?>
                    new referrals
                </div>

            </div>

        </article>

    </section>


    <!-- SEARCH + FILTERS -->

    <section class="referrals-filter-card">

        <form
            method="GET"
            action="referrals"
            class="referrals-filter-form"
        >

            <div class="referrals-filter-search">

                <i class="fa fa-search"></i>

                <input
                    type="search"
                    name="search"
                    value="<?= h($search) ?>"
                    placeholder="Search venture, founder, referrer or organisation..."
                >

            </div>


            <select
                name="status"
                class="form-control"
            >

                <option value="">
                    All Statuses
                </option>

                <?php foreach ($statuses as $option): ?>

                    <option
                        value="<?= h($option) ?>"
                        <?= $status === $option ? 'selected' : '' ?>
                    >
                        <?= h(ar_status_label($option)) ?>
                    </option>

                <?php endforeach; ?>

            </select>


            <select
                name="period"
                class="form-control"
                id="referralsPeriod"
            >

                <option value="all" <?= $period === 'all' ? 'selected' : '' ?>>
                    All Dates
                </option>

                <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>
                    Today
                </option>

                <option value="week" <?= $period === 'week' ? 'selected' : '' ?>>
                    This Week
                </option>

                <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>
                    This Month
                </option>

                <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>
                    Custom Range
                </option>

            </select>


            <div
                class="referrals-date-range <?= $period === 'custom' ? 'is-visible' : '' ?>"
                id="referralsDateRange"
            >

                <div>

                    <label>
                        From
                    </label>

                    <input
                        type="date"
                        name="date_from"
                        value="<?= h($dateFrom) ?>"
                    >

                </div>

                <div>

                    <label>
                        To
                    </label>

                    <input
                        type="date"
                        name="date_to"
                        value="<?= h($dateTo) ?>"
                    >

                </div>

            </div>


            <select
                name="per_page"
                class="form-control referrals-per-page"
            >

                <?php foreach ($perPageOptions as $option): ?>

                    <option
                        value="<?= $option ?>"
                        <?= $perPage === $option ? 'selected' : '' ?>
                    >
                        <?= $option ?> / page
                    </option>

                <?php endforeach; ?>

            </select>


            <div class="referrals-filter-actions">

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    <i class="fa fa-filter"></i>
                    Apply
                </button>

                <a
                    href="referrals"
                    class="btn btn-secondary"
                >
                    <i class="fa fa-times"></i>
                    Clear
                </a>

            </div>

        </form>

    </section>


    <!-- TABLE -->

    <section class="card referrals-table-card">

        <div class="card-header">

            <div>

                <h2 class="card-title">
                    Referral Records
                </h2>

                <p class="referrals-table-summary">
                    Showing
                    <strong><?= number_format($showingFrom) ?></strong>
                    -
                    <strong><?= number_format($showingTo) ?></strong>
                    of
                    <strong><?= number_format($totalFiltered) ?></strong>
                    matching referrals.
                </p>

            </div>

        </div>


        <?php if (!$referrals): ?>

            <div class="empty-state">

                <i class="fa fa-user-friends"></i>

                <h3>
                    No referrals found
                </h3>

                <p>
                    Try changing your search,
                    status or date filters.
                </p>

            </div>

        <?php else: ?>

            <div class="table-wrap">

                <table class="referrals-table">

                    <thead>

                        <tr>
                            <th>Venture</th>
                            <th>Founder</th>
                            <th>Referred By</th>
                            <th>Organisation</th>
                            <th>Informed</th>
                            <th>Status</th>
                            <th>Received</th>
                            <th>Actions</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($referrals as $row): ?>

                        <?php
                        $rowJson = json_encode(
                            $row,
                            JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES
                            | JSON_HEX_TAG
                            | JSON_HEX_APOS
                            | JSON_HEX_AMP
                            | JSON_HEX_QUOT
                        );
                        ?>

                        <tr>

                            <td>

                                <div class="referral-venture-cell">

                                    <div class="referral-venture-avatar">
                                        <?= h(
                                            ar_initials(
                                                (string)$row['venture_name']
                                            )
                                        ) ?>
                                    </div>

                                    <div>

                                        <strong>
                                            <?= h($row['venture_name']) ?>
                                        </strong>

                                        <span>
                                            <?= h($row['cohort_label']) ?>
                                        </span>

                                    </div>

                                </div>

                            </td>


                            <td>

                                <strong class="referral-cell-title">
                                    <?= h($row['founder_name']) ?>
                                </strong>

                                <a
                                    class="referral-cell-link"
                                    href="mailto:<?= h($row['founder_email']) ?>"
                                >
                                    <?= h($row['founder_email']) ?>
                                </a>

                            </td>


                            <td>

                                <strong class="referral-cell-title">
                                    <?= h($row['full_name']) ?>
                                </strong>

                                <a
                                    class="referral-cell-link"
                                    href="mailto:<?= h($row['email']) ?>"
                                >
                                    <?= h($row['email']) ?>
                                </a>

                                <span class="referral-cell-muted">
                                    <?= h($row['telephone']) ?>
                                </span>

                            </td>


                            <td>

                                <strong class="referral-cell-title">
                                    <?= h($row['organisation']) ?>
                                </strong>

                                <span class="referral-cell-muted">
                                    <?= h($row['role_position']) ?>
                                </span>

                            </td>


                            <td>

                                <?php if ($row['venture_informed'] === 'yes'): ?>

                                    <span class="badge badge-success">
                                        Yes
                                    </span>

                                <?php else: ?>

                                    <span class="badge badge-warning">
                                        No
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>

                                <span
                                    class="referral-status <?= h(ar_status_class((string)$row['status'])) ?>"
                                >
                                    <?= h(ar_status_label((string)$row['status'])) ?>
                                </span>

                            </td>


                            <td class="referral-date-cell">

                                <strong>
                                    <?= h(
                                        ar_datetime(
                                            (string)$row['created_at']
                                        )
                                    ) ?>
                                </strong>

                                <span>
                                    #<?= number_format((int)$row['id']) ?>
                                </span>

                            </td>


                            <td>

                                <div class="tbl-actions">

                                    <button
                                        type="button"
                                        class="btn btn-secondary btn-sm btn-icon"
                                        title="View referral"
                                        onclick='openReferralDetails(<?= $rowJson ?>)'
                                    >
                                        <i class="fa fa-eye"></i>
                                    </button>


                                    <button
                                        type="button"
                                        class="btn btn-secondary btn-sm btn-icon"
                                        title="Update status"
                                        onclick='openReferralStatus(<?= $rowJson ?>)'
                                    >
                                        <i class="fa fa-edit"></i>
                                    </button>


                                    <button
                                        type="button"
                                        class="btn btn-danger btn-sm btn-icon"
                                        title="Delete referral"
                                        onclick='openReferralDelete(<?= $rowJson ?>)'
                                    >
                                        <i class="fa fa-trash"></i>
                                    </button>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>


            <div class="referrals-pagination">

                <div class="referrals-pagination-summary">
                    Page
                    <strong><?= number_format($page) ?></strong>
                    of
                    <strong><?= number_format($totalPages) ?></strong>
                </div>


                <div class="pagination">

                    <?php if ($page > 1): ?>

                        <a
                            href="<?= h(
                                ar_url([
                                    'page' => $page - 1,
                                ])
                            ) ?>"
                        >
                            <i class="fa fa-chevron-left"></i>
                        </a>

                    <?php endif; ?>


                    <?php
                    $startPage = max(
                        1,
                        $page - 2
                    );

                    $endPage = min(
                        $totalPages,
                        $page + 2
                    );
                    ?>


                    <?php if ($startPage > 1): ?>

                        <a
                            href="<?= h(ar_url(['page' => 1])) ?>"
                        >
                            1
                        </a>

                        <?php if ($startPage > 2): ?>
                            <span class="referrals-page-dots">
                                �
                            </span>
                        <?php endif; ?>

                    <?php endif; ?>


                    <?php for ($p = $startPage; $p <= $endPage; $p++): ?>

                        <?php if ($p === $page): ?>

                            <span class="current">
                                <?= $p ?>
                            </span>

                        <?php else: ?>

                            <a
                                href="<?= h(
                                    ar_url([
                                        'page' => $p,
                                    ])
                                ) ?>"
                            >
                                <?= $p ?>
                            </a>

                        <?php endif; ?>

                    <?php endfor; ?>


                    <?php if ($endPage < $totalPages): ?>

                        <?php if ($endPage < $totalPages - 1): ?>
                            <span class="referrals-page-dots">
                                �
                            </span>
                        <?php endif; ?>

                        <a
                            href="<?= h(
                                ar_url([
                                    'page' => $totalPages,
                                ])
                            ) ?>"
                        >
                            <?= $totalPages ?>
                        </a>

                    <?php endif; ?>


                    <?php if ($page < $totalPages): ?>

                        <a
                            href="<?= h(
                                ar_url([
                                    'page' => $page + 1,
                                ])
                            ) ?>"
                        >
                            <i class="fa fa-chevron-right"></i>
                        </a>

                    <?php endif; ?>

                </div>

            </div>

        <?php endif; ?>

    </section>

</main>

</div>


<!-- VIEW MODAL -->

<div
    class="modal-overlay"
    id="referralViewModal"
>

    <div class="modal modal-xl">

        <div class="modal-header">

            <div>

                <h3 class="modal-title">
                    Referral Details
                </h3>

                <div
                    class="referral-modal-subtitle"
                    id="referralViewSubtitle"
                ></div>

            </div>

            <button
                type="button"
                class="modal-close"
                onclick="closeReferralModal('referralViewModal')"
            >
                &times;
            </button>

        </div>

        <div
            class="modal-body"
            id="referralViewBody"
        ></div>

        <div class="modal-footer">

            <button
                type="button"
                class="btn btn-secondary"
                onclick="closeReferralModal('referralViewModal')"
            >
                Close
            </button>

        </div>

    </div>

</div>


<!-- STATUS MODAL -->

<div
    class="modal-overlay"
    id="referralStatusModal"
>

    <div class="modal">

        <form
            method="POST"
            action="referrals"
        >

            <input
                type="hidden"
                name="referral_action"
                value="update_status"
            >

            <input
                type="hidden"
                name="return_to"
                value="<?= h(
                'referrals'
                . (
                    $_SERVER['QUERY_STRING'] !== ''
                        ? '?' . $_SERVER['QUERY_STRING']
                        : ''
                )
            ) ?>"
            >

            <input
                type="hidden"
                name="id"
                id="referralStatusId"
            >


            <div class="modal-header">

                <div>

                    <h3 class="modal-title">
                        Update Referral Status
                    </h3>

                    <div
                        class="referral-modal-subtitle"
                        id="referralStatusVenture"
                    ></div>

                </div>

                <button
                    type="button"
                    class="modal-close"
                    onclick="closeReferralModal('referralStatusModal')"
                >
                    &times;
                </button>

            </div>


            <div class="modal-body">

                <div class="form-group">

                    <label>
                        Status
                    </label>

                    <select
                        name="status"
                        id="referralStatusSelect"
                        class="form-control"
                        required
                    >

                        <?php foreach ($statuses as $option): ?>

                            <option value="<?= h($option) ?>">
                                <?= h(ar_status_label($option)) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

            </div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="closeReferralModal('referralStatusModal')"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    <i class="fa fa-save"></i>
                    Save Status
                </button>

            </div>

        </form>

    </div>

</div>


<!-- DELETE MODAL -->

<div
    class="modal-overlay"
    id="referralDeleteModal"
>

    <div class="modal danger-modal">

        <form
            method="POST"
            action="referrals"
        >

            <input
                type="hidden"
                name="referral_action"
                value="delete"
            >

            <input
                type="hidden"
                name="return_to"
                value="<?= h(
                'referrals'
                . (
                    $_SERVER['QUERY_STRING'] !== ''
                        ? '?' . $_SERVER['QUERY_STRING']
                        : ''
                )
            ) ?>"
            >

            <input
                type="hidden"
                name="id"
                id="referralDeleteId"
            >


            <div class="modal-header">

                <h3 class="modal-title">
                    Delete Referral
                </h3>

                <button
                    type="button"
                    class="modal-close"
                    onclick="closeReferralModal('referralDeleteModal')"
                >
                    &times;
                </button>

            </div>


            <div class="modal-body">

                <div class="referral-delete-warning">

                    <i class="fa fa-exclamation-triangle"></i>

                    <div>

                        <strong>
                            Delete this referral permanently?
                        </strong>

                        <p>
                            <span id="referralDeleteVenture"></span>
                            will be removed from the referral records.
                            This cannot be undone.
                        </p>

                    </div>

                </div>

            </div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="closeReferralModal('referralDeleteModal')"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn btn-danger"
                >
                    <i class="fa fa-trash"></i>
                    Delete Referral
                </button>

            </div>

        </form>

    </div>

</div>


<script>

(function () {

    const period =
        document.getElementById(
            'referralsPeriod'
        );

    const range =
        document.getElementById(
            'referralsDateRange'
        );

    function syncRange() {

        if (!period || !range) {
            return;
        }

        range.classList.toggle(
            'is-visible',
            period.value === 'custom'
        );

    }

    period?.addEventListener(
        'change',
        syncRange
    );

    syncRange();

})();


function refEsc(value) {

    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

}


function refEmail(value) {

    value =
        String(value || '').trim();

    if (!value) {
        return '<span class="referral-detail-empty">Not provided</span>';
    }

    return `
        <a
            class="referral-detail-link"
            href="mailto:${refEsc(value)}"
        >
            ${refEsc(value)}
        </a>
    `;

}


function refUrl(value) {

    value =
        String(value || '').trim();

    if (!value) {
        return '<span class="referral-detail-empty">Not provided</span>';
    }

    return `
        <a
            class="referral-detail-link"
            href="${refEsc(value)}"
            target="_blank"
            rel="noopener"
        >
            ${refEsc(value)}
            <i class="fa fa-external-link-alt"></i>
        </a>
    `;

}


function openReferralModal(id) {

    const modal =
        document.getElementById(id);

    if (!modal) {
        return;
    }

    modal.classList.add('open');

    document.body.classList.add(
        'modal-open'
    );

}


function closeReferralModal(id) {

    const modal =
        document.getElementById(id);

    if (!modal) {
        return;
    }

    modal.classList.remove('open');

    if (
        !document.querySelector(
            '.modal-overlay.open'
        )
    ) {
        document.body.classList.remove(
            'modal-open'
        );
    }

}


function openReferralDetails(data) {

    const body =
        document.getElementById(
            'referralViewBody'
        );

    const subtitle =
        document.getElementById(
            'referralViewSubtitle'
        );

    if (!body) {
        return;
    }

    if (subtitle) {
        subtitle.textContent =
            `${data.venture_name || 'Referral'} . #${data.id || ''}`;
    }

    body.innerHTML = `
        <div class="referral-detail-grid">

            <section class="referral-detail-section">

                <div class="referral-detail-section-title">
                    <i class="fa fa-user"></i>
                    Referrer Information
                </div>

                <div class="referral-detail-list">

                    <div>
                        <span>Full Name</span>
                        <strong>${refEsc(data.full_name)}</strong>
                    </div>

                    <div>
                        <span>Organisation</span>
                        <strong>${refEsc(data.organisation)}</strong>
                    </div>

                    <div>
                        <span>Role / Position</span>
                        <strong>${refEsc(data.role_position)}</strong>
                    </div>

                    <div>
                        <span>Email</span>
                        <strong>${refEmail(data.email)}</strong>
                    </div>

                    <div>
                        <span>Telephone</span>
                        <strong>${refEsc(data.telephone)}</strong>
                    </div>

                </div>

                <div class="referral-detail-text">

                    <span>
                        How the referrer knows the venture
                    </span>

                    <p>
                        ${refEsc(data.relationship_to_venture)}
                    </p>

                </div>

            </section>


            <section class="referral-detail-section">

                <div class="referral-detail-section-title">
                    <i class="fa fa-rocket"></i>
                    Venture Information
                </div>

                <div class="referral-detail-list">

                    <div>
                        <span>Venture Name</span>
                        <strong>${refEsc(data.venture_name)}</strong>
                    </div>

                    <div>
                        <span>Founder / Contact</span>
                        <strong>${refEsc(data.founder_name)}</strong>
                    </div>

                    <div>
                        <span>Founder Email</span>
                        <strong>${refEmail(data.founder_email)}</strong>
                    </div>

                    <div>
                        <span>Website / Product</span>
                        <strong>${refUrl(data.website_link)}</strong>
                    </div>

                    <div>
                        <span>Cohort</span>
                        <strong>${refEsc(data.cohort_label)}</strong>
                    </div>

                    <div>
                        <span>Venture Informed</span>
                        <strong>${data.venture_informed === 'yes' ? 'Yes' : 'No'}</strong>
                    </div>

                </div>

                <div class="referral-detail-text">

                    <span>
                        Why the venture was referred
                    </span>

                    <p>
                        ${refEsc(data.referral_reason)}
                    </p>

                </div>

            </section>

        </div>
    `;

    openReferralModal(
        'referralViewModal'
    );

}


function openReferralStatus(data) {

    const id =
        document.getElementById(
            'referralStatusId'
        );

    const venture =
        document.getElementById(
            'referralStatusVenture'
        );

    const select =
        document.getElementById(
            'referralStatusSelect'
        );

    if (id) {
        id.value =
            data.id || '';
    }

    if (venture) {
        venture.textContent =
            data.venture_name || '';
    }

    if (select) {
        select.value =
            data.status || 'new';
    }

    openReferralModal(
        'referralStatusModal'
    );

}


function openReferralDelete(data) {

    const id =
        document.getElementById(
            'referralDeleteId'
        );

    const venture =
        document.getElementById(
            'referralDeleteVenture'
        );

    if (id) {
        id.value =
            data.id || '';
    }

    if (venture) {
        venture.textContent =
            data.venture_name
            || 'This referral';
    }

    openReferralModal(
        'referralDeleteModal'
    );

}


document
    .querySelectorAll(
        '.modal-overlay'
    )
    .forEach(modal => {

        modal.addEventListener(
            'click',
            event => {

                if (
                    event.target
                    === modal
                ) {
                    closeReferralModal(
                        modal.id
                    );
                }

            }
        );

    });


document.addEventListener(
    'keydown',
    event => {

        if (
            event.key
            !== 'Escape'
        ) {
            return;
        }

        document
            .querySelectorAll(
                '.modal-overlay.open'
            )
            .forEach(modal => {
                closeReferralModal(
                    modal.id
                );
            });

    }
);

</script>

</body>
</html>
