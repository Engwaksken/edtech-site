<?php
declare(strict_types=1);

$page_title = 'Milestone Details';
include 'includes/header.php';

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

/* -----------------------------------------------------------------------
 | Helpers
 ----------------------------------------------------------------------- */
function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function table_exists(mysqli $conn, string $table): bool
{
    $table = trim($table);
    if ($table === '') return false;
    $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    if (!$result) return false;
    $exists = $result->num_rows > 0;
    $result->close();
    return $exists;
}

function time_ago_safe(?string $datetime): string
{
    if (empty($datetime)) return 'Unknown';
    $timestamp = strtotime($datetime);
    if ($timestamp === false) return 'Unknown';
    $diff = max(0, time() - $timestamp);
    if ($diff < 60)       return $diff . ' sec ago';
    if ($diff < 3600)     return floor($diff / 60) . ' min ago';
    if ($diff < 86400)    return floor($diff / 3600) . ' hrs ago';
    if ($diff < 604800)   return floor($diff / 86400) . ' days ago';
    if ($diff < 2592000)  return floor($diff / 604800) . ' weeks ago';
    if ($diff < 31536000) return floor($diff / 2592000) . ' months ago';
    return floor($diff / 31536000) . ' years ago';
}

function format_date(?string $date, string $fallback = '-'): string
{
    if (empty($date)) return $fallback;
    $ts = strtotime($date);
    return $ts ? date('d M Y', $ts) : $fallback;
}

function initials(string $name): string
{
    $name  = trim($name);
    if ($name === '') return '?';
    $parts = preg_split('/\s+/', $name);
    if (count($parts) >= 2) {
        return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
    }
    return strtoupper(mb_substr($parts[0], 0, 1));
}

function progress_class(float $pct): string
{
    if ($pct >= 75) return 'high';
    if ($pct >= 50) return 'mid';
    return 'low';
}

function decode_list_field(?string $raw): array
{
    if (empty($raw)) return [];
    $decoded = json_decode($raw, true);
    $items   = is_array($decoded) ? $decoded : explode(',', $raw);
    $clean   = [];
    foreach ($items as $item) {
        if (is_array($item)) {
            $value = trim((string)($item['title'] ?? $item['name'] ?? $item['dependency'] ?? ''));
        } else {
            $value = trim((string)$item);
        }
        if ($value !== '') $clean[] = $value;
    }
    return $clean;
}

function status_badge_class(string $status): string
{
    return [
        'Not Started' => 'badge badge-not-started',
        'Pending'     => 'badge badge-not-started',
        'In Progress' => 'badge badge-in-progress',
        'Completed'   => 'badge badge-completed',
        'Delayed'     => 'badge badge-delayed',
        'Cancelled'   => 'badge badge-cancelled',
    ][$status] ?? 'badge badge-not-started';
}

function file_icon(string $path): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match ($ext) {
        'pdf'                       => 'fa-file-pdf',
        'doc', 'docx'               => 'fa-file-word',
        'xls', 'xlsx', 'csv'        => 'fa-file-excel',
        'jpg', 'jpeg', 'png', 'gif', 'webp' => 'fa-file-image',
        'zip', 'rar'                => 'fa-file-archive',
        default                     => 'fa-file',
    };
}

/* -----------------------------------------------------------------------
 | Validate request
 ----------------------------------------------------------------------- */
$milestone_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($milestone_id <= 0) {
    header('Location: milestones.php');
    exit();
}

$hasPrograms             = table_exists($conn, 'programs');
$hasMilestoneUpdates     = table_exists($conn, 'milestone_updates');
$hasMilestoneDocuments   = table_exists($conn, 'milestone_documents');
$hasMilestoneDeliverables = table_exists($conn, 'milestone_deliverables');

/* -----------------------------------------------------------------------
 | Fetch milestone
 ----------------------------------------------------------------------- */
$sql = "
    SELECT
        m.*,
        u.full_name AS created_by_name,
        CASE
            WHEN m.entity_type IN ('Project','Program') THEN m.entity_type
            WHEN m.project_id IS NOT NULL THEN 'Project'
            WHEN m.program_id IS NOT NULL THEN 'Program'
            ELSE 'Unknown'
        END AS display_entity_type,
        CASE
            WHEN (m.entity_type = 'Project' AND m.entity_id IS NOT NULL)
              OR (m.entity_type IS NULL AND m.project_id IS NOT NULL) THEN p.project_code
            WHEN (m.entity_type = 'Program' AND m.entity_id IS NOT NULL)
              OR (m.entity_type IS NULL AND m.program_id IS NOT NULL) THEN pr.program_code
            ELSE 'N/A'
        END AS entity_code,
        CASE
            WHEN (m.entity_type = 'Project' AND m.entity_id IS NOT NULL)
              OR (m.entity_type IS NULL AND m.project_id IS NOT NULL)
                THEN CONCAT(COALESCE(p.project_code,''), '  ', COALESCE(p.project_name,''))
            WHEN (m.entity_type = 'Program' AND m.entity_id IS NOT NULL)
              OR (m.entity_type IS NULL AND m.program_id IS NOT NULL)
                THEN CONCAT(COALESCE(pr.program_code,''), '  ', COALESCE(pr.program_name,''))
            ELSE 'Unknown'
        END AS entity_name,
        CASE
            WHEN m.status = 'Completed' THEN 100
            ELSE COALESCE(m.progress_percentage, 0)
        END AS display_progress,
        DATEDIFF(m.due_date, CURDATE()) AS days_until_due,
        CASE
            WHEN m.start_date IS NOT NULL THEN DATEDIFF(CURDATE(), m.start_date)
            ELSE NULL
        END AS days_since_start
    FROM milestones m
    LEFT JOIN users u ON u.user_id = m.created_by
    LEFT JOIN projects p ON (
        (m.entity_type = 'Project' AND p.project_id = m.entity_id)
        OR (m.entity_type IS NULL AND p.project_id = m.project_id)
    )
";
$sql .= $hasPrograms
    ? "LEFT JOIN programs pr ON (
           (m.entity_type = 'Program' AND pr.id = m.entity_id)
           OR (m.entity_type IS NULL AND pr.id = m.program_id)
       )"
    : "LEFT JOIN (SELECT NULL AS id, NULL AS program_code, NULL AS program_name) pr ON 1=0";
$sql .= " WHERE m.milestone_id = ? LIMIT 1";

$stmt = $conn->prepare($sql);
if (!$stmt) die('Failed to prepare milestone query: ' . h($conn->error));
$stmt->bind_param('i', $milestone_id);
$stmt->execute();
$result    = $stmt->get_result();
$milestone = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$milestone) {
    header('Location: milestones.php');
    exit();
}

/* -----------------------------------------------------------------------
 | Fetch deliverables
 ----------------------------------------------------------------------- */
$deliverables = [];
if ($hasMilestoneDeliverables) {
    $stmt = $conn->prepare("
        SELECT deliverable_id, deliverable_title, start_date, end_date,
               completion_date, status, progress_percentage, responsible_person,
               budget_allocation, actual_cost, notes, created_at, updated_at
        FROM milestone_deliverables
        WHERE milestone_id = ?
        ORDER BY COALESCE(start_date, end_date), deliverable_id
    ");
    if ($stmt) {
        $stmt->bind_param('i', $milestone_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) $deliverables[] = $row;
        }
        $stmt->close();
    }
}

/* Backward-compat: old JSON/CSV deliverables field */
if (empty($deliverables) && !empty($milestone['deliverables'])) {
    $decoded = json_decode((string)$milestone['deliverables'], true);
    if (is_array($decoded)) {
        foreach ($decoded as $item) {
            if (is_array($item)) {
                $title    = trim((string)($item['title'] ?? $item['deliverable_title'] ?? $item['name'] ?? ''));
                $startDate = trim((string)($item['start_date'] ?? ''));
                $endDate   = trim((string)($item['end_date'] ?? ''));
                $status    = trim((string)($item['status'] ?? 'Pending'));
                $progress  = (float)($item['progress_percentage'] ?? $item['progress'] ?? 0);
            } else {
                $title = trim((string)$item);
                $startDate = $endDate = ''; $status = 'Pending'; $progress = 0;
            }
            if ($title !== '') {
                $deliverables[] = [
                    'deliverable_id' => null, 'deliverable_title' => $title,
                    'start_date' => $startDate, 'end_date' => $endDate,
                    'completion_date' => null, 'status' => $status ?: 'Pending',
                    'progress_percentage' => $progress, 'responsible_person' => null,
                    'budget_allocation' => null, 'actual_cost' => null, 'notes' => null,
                ];
            }
        }
    } else {
        foreach (explode(',', (string)$milestone['deliverables']) as $item) {
            $title = trim($item);
            if ($title !== '') {
                $deliverables[] = [
                    'deliverable_id' => null, 'deliverable_title' => $title,
                    'start_date' => null, 'end_date' => null, 'completion_date' => null,
                    'status' => 'Pending', 'progress_percentage' => 0,
                    'responsible_person' => null, 'budget_allocation' => null,
                    'actual_cost' => null, 'notes' => null,
                ];
            }
        }
    }
}

/* -----------------------------------------------------------------------
 | Fetch updates
 ----------------------------------------------------------------------- */
$updates = [];
if ($hasMilestoneUpdates) {
    $stmt = $conn->prepare("
        SELECT mu.*, u.full_name AS updated_by_name
        FROM milestone_updates mu
        LEFT JOIN users u ON u.user_id = mu.created_by
        WHERE mu.milestone_id = ?
        ORDER BY mu.created_at DESC, mu.id DESC
    ");
    if ($stmt) {
        $stmt->bind_param('i', $milestone_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) $updates[] = $row;
        }
        $stmt->close();
    }
}

/* -----------------------------------------------------------------------
 | Fetch documents
 ----------------------------------------------------------------------- */
$documents = [];
if ($hasMilestoneDocuments) {
    $stmt = $conn->prepare("
        SELECT md.*, u.full_name AS uploaded_by_name
        FROM milestone_documents md
        LEFT JOIN users u ON u.user_id = md.uploaded_by
        WHERE md.milestone_id = ?
        ORDER BY md.uploaded_at DESC, md.id DESC
    ");
    if ($stmt) {
        $stmt->bind_param('i', $milestone_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) $documents[] = $row;
        }
        $stmt->close();
    }
}

/* -----------------------------------------------------------------------
 | Calculations
 ----------------------------------------------------------------------- */
$displayProgress    = max(0, min(100, (float)($milestone['display_progress'] ?? 0)));
$dueTimestamp       = !empty($milestone['due_date'])        ? strtotime((string)$milestone['due_date'])        : false;
$startTimestamp     = !empty($milestone['start_date'])      ? strtotime((string)$milestone['start_date'])      : false;
$completionTimestamp = !empty($milestone['completion_date']) ? strtotime((string)$milestone['completion_date']) : false;

$is_overdue = $dueTimestamp !== false
    && !in_array((string)$milestone['status'], ['Completed', 'Cancelled'], true)
    && $dueTimestamp < strtotime(date('Y-m-d'));

$is_due_soon = isset($milestone['days_until_due'])
    && (int)$milestone['days_until_due'] >= 0
    && (int)$milestone['days_until_due'] <= 7
    && (string)$milestone['status'] !== 'Completed'
    && !$is_overdue;

$totalDays    = 0.0;
$elapsedDays  = 0.0;
$timeProgress = 0.0;
if ($startTimestamp !== false && $dueTimestamp !== false && $dueTimestamp >= $startTimestamp) {
    $totalDays    = max(1, ($dueTimestamp - $startTimestamp) / 86400);
    $elapsedDays  = max(0, (time() - $startTimestamp) / 86400);
    $timeProgress = min(100, ($elapsedDays / $totalDays) * 100);
}

$dependencies = decode_list_field($milestone['dependencies'] ?? null);

$deliverableCount       = count($deliverables);
$completedDeliverables  = count(array_filter($deliverables, fn($d) => ($d['status'] ?? '') === 'Completed'));
$inProgressDeliverables = count(array_filter($deliverables, fn($d) => ($d['status'] ?? '') === 'In Progress'));
$deliverableProgress    = $deliverableCount > 0 ? ($completedDeliverables / $deliverableCount) * 100 : 0;

$totalBudget = (float)($milestone['budget_allocation'] ?? 0);
$totalActual = (float)($milestone['actual_cost'] ?? 0);
foreach ($deliverables as $d) {
    $totalBudget += (float)($d['budget_allocation'] ?? 0);
    $totalActual += (float)($d['actual_cost'] ?? 0);
}
$budgetVariance   = $totalBudget - $totalActual;
$budgetUtilPct    = $totalBudget > 0 ? min(100, ($totalActual / $totalBudget) * 100) : 0;

$entityType  = (string)($milestone['display_entity_type'] ?? 'Unknown');
$entityClass = strtolower($entityType) === 'project' ? 'project' : 'program';
$entityIcon  = strtolower($entityType) === 'project' ? 'fa-project-diagram' : 'fa-sitemap';
?>

<link rel="stylesheet" href="css/reports.css">
<link rel="stylesheet" href="css/milestones.css">

<div class="milestones-wrap">

    <!-- ================================================================
         HERO
    ================================================================ -->
    <div class="milestones-hero">
        <div class="milestones-hero-text">
            <h1>
                <i class="fas fa-flag-checkered legacy-style-5d12f43764"></i>
                <?= h($milestone['milestone_name'] ?? 'Milestone') ?>
            </h1>

            <p><?= h($milestone['entity_name'] ?? 'Project / Program') ?></p>

            <div class="milestone-meta-row legacy-style-88b9d5c614">
                <span class="entity-chip <?= h($entityClass) ?>">
                    <i class="fas <?= h($entityIcon) ?>"></i>
                    <?= h($entityType) ?>: <?= h($milestone['entity_code'] ?? 'N/A') ?>
                </span>

                <?php if (!empty($milestone['milestone_type'])): ?>
                <span class="badge badge-secondary legacy-style-4be6ccc850">
                    <i class="fas fa-layer-group"></i>
                    <?= h($milestone['milestone_type']) ?>
                </span>
                <?php endif; ?>

                <span class="<?= h(status_badge_class((string)($milestone['status'] ?? 'Not Started'))) ?>">
                    <?= h($milestone['status'] ?? 'Not Started') ?>
                </span>

                <?php if ($is_overdue): ?>
                    <span class="badge badge-overdue">
                        <i class="fas fa-exclamation-circle"></i> Overdue
                    </span>
                <?php elseif ($is_due_soon): ?>
                    <span class="badge badge-due-soon">
                        <i class="fas fa-clock"></i> Due Soon
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="hero-actions">
            <a href="milestones.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <a href="milestones.php?edit=<?= (int)$milestone_id ?>" class="btn btn-dark">
                <i class="fas fa-edit"></i> Edit Milestone
            </a>
        </div>
    </div>

    <!-- ================================================================
         SUMMARY CARDS
    ================================================================ -->
    <div class="detail-summary-grid">

        <!-- Progress -->
        <div class="detail-summary-card">
            <div class="label"><i class="fas fa-chart-pie legacy-style-cb2388ff18"></i> Milestone Progress</div>
            <div class="value"><?= number_format($displayProgress, 0) ?>%</div>
            <div class="prog-track legacy-style-210c01a60d">
                <div class="prog-fill <?= h(progress_class($displayProgress)) ?>" style="width:<?= $displayProgress ?>%;"></div>
            </div>
            <div class="hint">
                <?php if ($milestone['status'] === 'Completed'): ?>
                    <span class="legacy-style-901b0f0c8b"><i class="fas fa-check-circle"></i> Completed</span>
                <?php elseif ($displayProgress > 0): ?>
                    <?= number_format(100 - $displayProgress, 0) ?>% remaining
                <?php else: ?>
                    Not yet started
                <?php endif; ?>
            </div>
        </div>

        <!-- Due Date -->
        <div class="detail-summary-card">
            <div class="label"><i class="fas fa-calendar-alt legacy-style-cb2388ff18"></i> Due Date</div>
            <div class="value legacy-style-9b49c245f0"><?= h(format_date($milestone['due_date'] ?? null)) ?></div>
            <div class="hint">
                <?php if (isset($milestone['days_until_due'])): ?>
                    <?php if ((int)$milestone['days_until_due'] >= 0): ?>
                        <span class="legacy-style-fc0285fd88">
                            <i class="fas fa-hourglass-half"></i>
                            <?= (int)$milestone['days_until_due'] ?> days remaining
                        </span>
                    <?php else: ?>
                        <span class="legacy-style-5d08ecd3b8">
                            <i class="fas fa-exclamation-triangle"></i>
                            <?= abs((int)$milestone['days_until_due']) ?> days overdue
                        </span>
                    <?php endif; ?>
                <?php else: ?>
                    No due date set
                <?php endif; ?>
            </div>
        </div>

        <!-- Deliverables -->
        <div class="detail-summary-card">
            <div class="label"><i class="fas fa-list-check legacy-style-cb2388ff18"></i> Deliverables</div>
            <div class="value"><?= (int)$completedDeliverables ?><span class="legacy-style-d441a63abc"> / <?= (int)$deliverableCount ?></span></div>
            <div class="prog-track legacy-style-210c01a60d">
                <div class="prog-fill <?= h(progress_class($deliverableProgress)) ?>" style="width:<?= $deliverableProgress ?>%;"></div>
            </div>
            <div class="hint">
                <?php if ($inProgressDeliverables > 0): ?>
                    <span class="legacy-style-69bc668d6b"><?= $inProgressDeliverables ?> in progress</span>
                <?php else: ?>
                    <?= number_format($deliverableProgress, 0) ?>% completed
                <?php endif; ?>
            </div>
        </div>

        <!-- Budget -->
        <div class="detail-summary-card">
            <div class="label"><i class="fas fa-coins legacy-style-cb2388ff18"></i> Budget vs Actual</div>
            <div class="value legacy-style-4f1925a8a6"><?= number_format($totalBudget, 2) ?></div>
            <div class="prog-track legacy-style-210c01a60d">
                <div class="prog-fill <?= $budgetVariance < 0 ? 'low' : 'high' ?>" style="width:<?= $budgetUtilPct ?>%;"></div>
            </div>
            <div class="hint">
                Actual: <?= number_format($totalActual, 2) ?>
                <?php if ($totalBudget > 0): ?>
                    &nbsp;.&nbsp;
                    <?php if ($budgetVariance >= 0): ?>
                        <span class="legacy-style-901b0f0c8b">
                            <?= number_format($budgetVariance, 2) ?> under
                        </span>
                    <?php else: ?>
                        <span class="legacy-style-5d08ecd3b8">
                            <?= number_format(abs($budgetVariance), 2) ?> over
                        </span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ================================================================
         TABS
    ================================================================ -->
    <div class="detail-tabs">
        <div class="tab-nav" role="tablist">
            <button type="button" class="tab-btn active" data-tab="overview">
                <i class="fas fa-info-circle"></i> Overview
            </button>
            <button type="button" class="tab-btn" data-tab="deliverables">
                <i class="fas fa-list-check"></i> Deliverables
                <?php if ($deliverableCount > 0): ?>
                    <span class="badge badge-secondary legacy-style-ae1cc426c8"><?= $deliverableCount ?></span>
                <?php endif; ?>
            </button>
            <button type="button" class="tab-btn" data-tab="dependencies">
                <i class="fas fa-link"></i> Dependencies
                <?php if (count($dependencies) > 0): ?>
                    <span class="badge badge-secondary legacy-style-ae1cc426c8"><?= count($dependencies) ?></span>
                <?php endif; ?>
            </button>
            <button type="button" class="tab-btn" data-tab="updates">
                <i class="fas fa-comments"></i> Updates
                <?php if (count($updates) > 0): ?>
                    <span class="badge badge-available legacy-style-ae1cc426c8"><?= count($updates) ?></span>
                <?php endif; ?>
            </button>
            <button type="button" class="tab-btn" data-tab="documents">
                <i class="fas fa-file-alt"></i> Documents
                <?php if (count($documents) > 0): ?>
                    <span class="badge badge-secondary legacy-style-ae1cc426c8"><?= count($documents) ?></span>
                <?php endif; ?>
            </button>
        </div>

        <!-- ============================================================
             TAB: OVERVIEW
        ============================================================ -->
        <section id="tab-overview" class="tab-panel active">
            <div class="detail-grid-2">

                <!-- Left column -->
                <div>
                    <!-- Description -->
                    <div class="section-title">
                        <h3><i class="fas fa-align-left legacy-style-e9ed853f19"></i> Description</h3>
                    </div>
                    <div class="soft-box legacy-style-7dde5e56b3">
                        <?php if (!empty($milestone['milestone_description'])): ?>
                            <p class="legacy-style-e5019fa1ba">
                                <?= nl2br(h($milestone['milestone_description'])) ?>
                            </p>
                        <?php else: ?>
                            <p class="legacy-style-76fe7aec95">No description provided.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Progress Tracking -->
                    <div class="section-title">
                        <h3><i class="fas fa-chart-line legacy-style-e9ed853f19"></i> Progress Tracking</h3>
                    </div>
                    <div class="soft-box">
                        <!-- Task completion -->
                        <div class="legacy-style-3913868043">
                            <span class="legacy-style-c022bbb9ac">Task Completion</span>
                            <strong class="legacy-style-c01e26521b"><?= number_format($displayProgress, 1) ?>%</strong>
                        </div>
                        <div class="prog-track legacy-style-06ba35f99b">
                            <div class="prog-fill <?= h(progress_class($displayProgress)) ?>" style="width:<?= $displayProgress ?>%;"></div>
                        </div>

                        <!-- Deliverable completion -->
                        <?php if ($deliverableCount > 0): ?>
                        <div class="legacy-style-3913868043">
                            <span class="legacy-style-c022bbb9ac">Deliverable Completion</span>
                            <strong class="legacy-style-c01e26521b"><?= $completedDeliverables ?> / <?= $deliverableCount ?></strong>
                        </div>
                        <div class="prog-track legacy-style-06ba35f99b">
                            <div class="prog-fill <?= h(progress_class($deliverableProgress)) ?>" style="width:<?= $deliverableProgress ?>%;"></div>
                        </div>
                        <?php endif; ?>

                        <!-- Time elapsed -->
                        <?php if ($startTimestamp !== false && $totalDays > 0): ?>
                        <div class="legacy-style-3913868043">
                            <span class="legacy-style-c022bbb9ac">Time Elapsed</span>
                            <strong class="legacy-style-c01e26521b"><?= number_format($timeProgress, 1) ?>%</strong>
                        </div>
                        <div class="prog-track legacy-style-9363c9bda4">
                            <div class="prog-fill mid" style="width:<?= min(100, $timeProgress) ?>%;"></div>
                        </div>
                        <p class="legacy-style-edda1839ce">
                            <?= round($elapsedDays) ?> of <?= round($totalDays) ?> days elapsed
                            &nbsp;.&nbsp; <?= h(format_date($milestone['start_date'])) ?> ? <?= h(format_date($milestone['due_date'])) ?>
                        </p>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($milestone['notes'])): ?>
                    <div class="section-title legacy-style-9eb125f52f">
                        <h3><i class="fas fa-sticky-note legacy-style-e9ed853f19"></i> Notes</h3>
                    </div>
                    <div class="soft-box">
                        <p class="legacy-style-e5019fa1ba">
                            <?= nl2br(h($milestone['notes'])) ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Right column -->
                <div>
                    <div class="section-title">
                        <h3><i class="fas fa-table legacy-style-e9ed853f19"></i> Milestone Information</h3>
                    </div>

                    <table class="info-table legacy-style-730765f368">
                        <tr>
                            <th><i class="fas fa-sitemap legacy-style-b213cf4f17"></i> Entity</th>
                            <td>
                                <span class="entity-chip <?= h($entityClass) ?>"><?= h($entityType) ?></span>
                                <span class="legacy-style-92e40db53c">
                                    <?= h($milestone['entity_name'] ?? 'Unknown') ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th><i class="fas fa-calendar-plus legacy-style-b213cf4f17"></i> Start Date</th>
                            <td><?= h(format_date($milestone['start_date'] ?? null)) ?></td>
                        </tr>
                        <tr>
                            <th><i class="fas fa-calendar-times legacy-style-b213cf4f17"></i> Due Date</th>
                            <td>
                                <span class="<?= $is_overdue ? 'due-date overdue' : 'due-date' ?>">
                                    <?= h(format_date($milestone['due_date'] ?? null)) ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th><i class="fas fa-check-double legacy-style-b213cf4f17"></i> Completed</th>
                            <td><?= h(format_date($milestone['completion_date'] ?? null)) ?></td>
                        </tr>
                        <tr>
                            <th><i class="fas fa-info-circle legacy-style-b213cf4f17"></i> Status</th>
                            <td>
                                <span class="<?= h(status_badge_class((string)($milestone['status'] ?? 'Not Started'))) ?>">
                                    <?= h($milestone['status'] ?? 'Not Started') ?>
                                </span>
                            </td>
                        </tr>
                        <?php if (!empty($milestone['responsible_person'])): ?>
                        <tr>
                            <th><i class="fas fa-user legacy-style-b213cf4f17"></i> Responsible</th>
                            <td>
                                <span class="responsible-cell">
                                    <span class="responsible-avatar"><?= h(initials((string)$milestone['responsible_person'])) ?></span>
                                    <span><?= h($milestone['responsible_person']) ?></span>
                                </span>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th><i class="fas fa-user-plus legacy-style-b213cf4f17"></i> Created</th>
                            <td>
                                <?= h(format_date($milestone['created_at'] ?? null)) ?>
                                <br><small class="legacy-style-6d930ad79a">by <?= h($milestone['created_by_name'] ?? 'Unknown') ?></small>
                            </td>
                        </tr>
                        <tr>
                            <th><i class="fas fa-clock legacy-style-b213cf4f17"></i> Last Updated</th>
                            <td><?= h(time_ago_safe($milestone['updated_at'] ?? null)) ?></td>
                        </tr>
                    </table>

                    <!-- Budget Breakdown -->
                    <?php if ($totalBudget > 0 || $totalActual > 0): ?>
                    <div class="section-title legacy-style-9eb125f52f">
                        <h3><i class="fas fa-coins legacy-style-e9ed853f19"></i> Budget Overview</h3>
                    </div>
                    <div class="legacy-style-730765f368">
                        <div class="breakdown-row legacy-style-3c7b0f27b6">
                            <span class="breakdown-label">Allocated Budget</span>
                            <span class="breakdown-value"><?= number_format($totalBudget, 2) ?></span>
                        </div>
                        <div class="breakdown-row legacy-style-3c7b0f27b6">
                            <span class="breakdown-label">Actual Cost</span>
                            <span class="breakdown-value"><?= number_format($totalActual, 2) ?></span>
                        </div>
                        <div class="breakdown-row" style="padding:11px 14px;background:<?= $budgetVariance >= 0 ? 'var(--green-bg)' : 'var(--red-bg)' ?>;">
                            <span class="breakdown-label" style="color:<?= $budgetVariance >= 0 ? 'var(--green-fg)' : 'var(--red-fg)' ?>;font-weight:700;">
                                <?= $budgetVariance >= 0 ? 'Under Budget' : 'Over Budget' ?>
                            </span>
                            <span class="breakdown-value" style="color:<?= $budgetVariance >= 0 ? 'var(--green-fg)' : 'var(--red-fg)' ?>;">
                                <?= $budgetVariance >= 0 ? '' : '-' ?><?= number_format(abs($budgetVariance), 2) ?>
                            </span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- ============================================================
             TAB: DELIVERABLES
        ============================================================ -->
        <section id="tab-deliverables" class="tab-panel">
            <div class="section-title">
                <h3><i class="fas fa-list-check legacy-style-e9ed853f19"></i> Deliverables</h3>
                <span class="badge badge-available">
                    <?= (int)$completedDeliverables ?> of <?= (int)$deliverableCount ?> completed
                </span>
            </div>

            <?php if (empty($deliverables)): ?>
                <div class="empty-state">
                    <i class="fas fa-list-check"></i>
                    <strong>No deliverables recorded.</strong>
                    <p>Add deliverables from the milestone edit form.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="deliverables-table">
                        <thead>
                            <tr>
                                <th>Deliverable</th>
                                <th>Date Range</th>
                                <th>Status</th>
                                <th>Progress</th>
                                <th>Responsible</th>
                                <th>Budget / Actual</th>
                                <th class="legacy-style-dac4fe6c9b">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($deliverables as $deliverable): ?>
                            <?php
                            $dProgress = max(0, min(100, (float)($deliverable['progress_percentage'] ?? 0)));
                            $dStatus   = (string)($deliverable['status'] ?? 'Pending');
                            $dBudget   = (float)($deliverable['budget_allocation'] ?? 0);
                            $dActual   = (float)($deliverable['actual_cost'] ?? 0);
                            ?>
                            <tr>
                                <td>
                                    <div class="deliverable-title">
                                        <?= h($deliverable['deliverable_title'] ?? 'Untitled Deliverable') ?>
                                    </div>
                                    <?php if (!empty($deliverable['notes'])): ?>
                                        <div class="deliverable-note">
                                            <?= nl2br(h($deliverable['notes'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="due-cell">
                                        <?php if (!empty($deliverable['start_date'])): ?>
                                            <span class="legacy-style-7fe55ae764">
                                                <i class="fas fa-play-circle legacy-style-189f6df7fa"></i>
                                                <?= h(format_date($deliverable['start_date'])) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($deliverable['end_date'])): ?>
                                            <span class="legacy-style-7fe55ae764">
                                                <i class="fas fa-flag legacy-style-4204fedc93"></i>
                                                <?= h(format_date($deliverable['end_date'])) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($deliverable['completion_date'])): ?>
                                            <span class="legacy-style-5306daadf7">
                                                <i class="fas fa-check-circle"></i>
                                                <?= h(format_date($deliverable['completion_date'])) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="<?= h(status_badge_class($dStatus)) ?>">
                                        <?= h($dStatus) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="progress-cell">
                                        <div class="progress-header">
                                            <span class="progress-pct"><?= number_format($dProgress, 0) ?>%</span>
                                        </div>
                                        <div class="prog-track">
                                            <div class="prog-fill <?= h(progress_class($dProgress)) ?>" style="width:<?= $dProgress ?>%;"></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($deliverable['responsible_person'])): ?>
                                        <span class="responsible-cell">
                                            <span class="responsible-avatar"><?= h(initials((string)$deliverable['responsible_person'])) ?></span>
                                            <span><?= h($deliverable['responsible_person']) ?></span>
                                        </span>
                                    <?php else: ?>
                                        <span class="legacy-style-d16341b8d6">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="legacy-style-5635981420"><?= number_format($dBudget, 2) ?></span>
                                    <br>
                                    <small class="legacy-style-6d930ad79a">Actual: <?= number_format($dActual, 2) ?></small>
                                </td>
                                <td class="legacy-style-dac4fe6c9b">
                                    <?php if (!empty($deliverable['deliverable_id'])): ?>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-soft"
                                            onclick='openDeliverableUpdateModal(<?= json_encode([
                                                "deliverable_id"      => (int)($deliverable["deliverable_id"] ?? 0),
                                                "title"               => (string)($deliverable["deliverable_title"] ?? ""),
                                                "start_date"          => (string)($deliverable["start_date"] ?? ""),
                                                "end_date"            => (string)($deliverable["end_date"] ?? ""),
                                                "completion_date"     => (string)($deliverable["completion_date"] ?? ""),
                                                "status"              => (string)($deliverable["status"] ?? "Pending"),
                                                "progress_percentage" => (string)($deliverable["progress_percentage"] ?? "0"),
                                                "responsible_person"  => (string)($deliverable["responsible_person"] ?? ""),
                                                "budget_allocation"   => (string)($deliverable["budget_allocation"] ?? "0"),
                                                "actual_cost"         => (string)($deliverable["actual_cost"] ?? "0"),
                                                "notes"               => (string)($deliverable["notes"] ?? ""),
                                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'
                                        >
                                            <i class="fas fa-edit"></i> Update
                                        </button>
                                    <?php else: ?>
                                        <span class="legacy-style-7d4f2327d1">Legacy</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <!-- ============================================================
            // TAB: DEPENDENCIES
        ============================================================ -->
        <section id="tab-dependencies" class="tab-panel">
            <div class="section-title">
                <h3><i class="fas fa-link legacy-style-e9ed853f19"></i> Dependencies</h3>
                <span class="badge badge-secondary">
                    <?= count($dependencies) ?> item<?= count($dependencies) !== 1 ? 's' : '' ?>
                </span>
            </div>

            <?php if (empty($dependencies)): ?>
                <div class="empty-state">
                    <i class="fas fa-link"></i>
                    <strong>No dependencies recorded.</strong>
                    <p>Add dependency items from the milestone edit form.</p>
                </div>
            <?php else: ?>
                <div class="dependency-list">
                    <?php foreach ($dependencies as $index => $dependency): ?>
                        <div class="dependency-item">
                            <i class="fas fa-check-circle legacy-style-53d0b687ac"></i>
                            <div>
                                <small class="legacy-style-b29fb3d76c">
                                    Dependency <?= $index + 1 ?>
                                </small>
                                <div class="legacy-style-6b9dd8598f">
                                    <?= h($dependency) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- ============================================================
             TAB: UPDATES
        ============================================================ -->
        <section id="tab-updates" class="tab-panel">
            <div class="section-title">
                <h3><i class="fas fa-comments legacy-style-e9ed853f19"></i> Updates &amp; Activity</h3>
                <button type="button" onclick="openModal('addUpdateModal')" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> Add Update
                </button>
            </div>

            <?php if (empty($updates)): ?>
                <div class="empty-state">
                    <i class="fas fa-comments"></i>
                    <strong>No updates yet.</strong>
                    <p>Use the Add Update button to record milestone progress.</p>
                </div>
            <?php else: ?>
                <div class="timeline">
                    <?php
                    $updateTypeBadge = [
                        'Status Update' => 'badge badge-available',
                        'Issue'         => 'badge badge-overdue',
                        'Achievement'   => 'badge badge-completed',
                        'Delay'         => 'badge badge-delayed',
                        'General'       => 'badge badge-secondary',
                    ];
                    $updateTypeIcon = [
                        'Status Update' => 'fa-sync-alt',
                        'Issue'         => 'fa-exclamation-triangle',
                        'Achievement'   => 'fa-trophy',
                        'Delay'         => 'fa-hourglass-half',
                        'General'       => 'fa-comment',
                    ];
                    foreach ($updates as $update):
                        $updateType = (string)($update['update_type'] ?? 'General');
                        $icon       = $updateTypeIcon[$updateType] ?? 'fa-comment';
                    ?>
                    <div class="timeline-item">
                        <div class="timeline-marker"></div>
                        <div class="timeline-content">
                            <div class="legacy-style-ce7d9951ed">
                                <div class="legacy-style-6b6f9f12cc">
                                    <span class="responsible-cell legacy-style-d45c7097fc">
                                        <span class="responsible-avatar">
                                            <?= h(initials((string)($update['updated_by_name'] ?? '?'))) ?>
                                        </span>
                                        <strong class="legacy-style-324225213c"><?= h($update['updated_by_name'] ?? 'Unknown') ?></strong>
                                    </span>
                                    <span class="<?= h($updateTypeBadge[$updateType] ?? 'badge badge-secondary') ?>">
                                        <i class="fas <?= h($icon) ?>"></i> <?= h($updateType) ?>
                                    </span>
                                </div>
                                <small class="legacy-style-748f8781d8">
                                    <i class="fas fa-clock legacy-style-1638ceff12"></i>
                                    <?= h(time_ago_safe($update['created_at'] ?? null)) ?>
                                </small>
                            </div>

                            <p class="legacy-style-c088e03c5c">
                                <?= nl2br(h($update['update_text'] ?? '')) ?>
                            </p>

                            <?php if (isset($update['progress_percentage']) && $update['progress_percentage'] !== null): ?>
                                <div class="legacy-style-1e5fe6d7bc">
                                    <div class="legacy-style-8e820a47a7">
                                        <small class="legacy-style-a3a780aa4e">
                                            Progress recorded
                                        </small>
                                        <small class="legacy-style-15af827119">
                                            <?= number_format((float)$update['progress_percentage'], 1) ?>%
                                        </small>
                                    </div>
                                    <div class="prog-track legacy-style-10feee100a">
                                        <?php $upPct = min(100, (float)$update['progress_percentage']); ?>
                                        <div class="prog-fill <?= h(progress_class($upPct)) ?>" style="width:<?= $upPct ?>%;"></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- ============================================================
             TAB: DOCUMENTS
        ============================================================ -->
        <section id="tab-documents" class="tab-panel">
            <div class="section-title">
                <h3><i class="fas fa-file-alt legacy-style-e9ed853f19"></i> Documents</h3>
                <button type="button" onclick="openModal('uploadDocModal')" class="btn btn-primary btn-sm">
                    <i class="fas fa-upload"></i> Upload
                </button>
            </div>

            <?php if (empty($documents)): ?>
                <div class="empty-state">
                    <i class="fas fa-file-alt"></i>
                    <strong>No documents uploaded.</strong>
                    <p>Upload milestone reports, evidence, or supporting files.</p>
                </div>
            <?php else: ?>
                <div class="document-list">
                    <?php foreach ($documents as $doc):
                        $docPath = (string)($doc['document_path'] ?? '');
                    ?>
                    <div class="document-item">
                        <div class="document-icon">
                            <i class="fas <?= h(file_icon($docPath)) ?>"></i>
                        </div>
                        <div class="legacy-style-a07604d334">
                            <strong class="legacy-style-eab223c3b9">
                                <?= h($doc['document_name'] ?? 'Document') ?>
                            </strong>
                            <span class="legacy-style-daddeb801c">
                                <i class="fas fa-clock legacy-style-1638ceff12"></i>
                                <?= h(time_ago_safe($doc['uploaded_at'] ?? null)) ?>
                                &nbsp;.&nbsp;
                                <i class="fas fa-user legacy-style-1638ceff12"></i>
                                <?= h($doc['uploaded_by_name'] ?? 'Unknown') ?>
                            </span>
                        </div>
                        <?php if ($docPath !== ''): ?>
                            <a href="<?= h($docPath) ?>" class="btn btn-sm btn-soft" download title="Download">
                                <i class="fas fa-download"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div><!-- /detail-tabs -->

</div><!-- /milestones-wrap -->

<!-- ====================================================================
     MODAL: Update Deliverable
==================================================================== -->
<div id="updateDeliverableModal" class="modal">
    <div class="modal-content legacy-style-c1e19fb51a">
        <div class="modal-header">
            <h3><i class="fas fa-list-check legacy-style-cd44151082"></i> Update Deliverable</h3>
            <span class="close" onclick="closeModal('updateDeliverableModal')">-</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="includes/milestone-deliverables-process.php" id="updateDeliverableForm">
                <input type="hidden" name="milestone_id" value="<?= (int)$milestone_id ?>">
                <input type="hidden" name="deliverable_id" id="deliverable_id">

                <div class="form-group">
                    <label for="deliverable_title" class="required">Deliverable Title</label>
                    <input type="text" id="deliverable_title" name="deliverable_title" class="form-control" required>
                </div>

                <div class="form-row legacy-style-9f693216a7">
                    <div class="form-group">
                        <label for="deliverable_start_date">Start Date</label>
                        <input type="date" id="deliverable_start_date" name="start_date" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="deliverable_end_date">End Date</label>
                        <input type="date" id="deliverable_end_date" name="end_date" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="deliverable_completion_date">Completion Date</label>
                        <input type="date" id="deliverable_completion_date" name="completion_date" class="form-control">
                    </div>
                </div>

                <div class="form-row legacy-style-9f693216a7">
                    <div class="form-group">
                        <label for="deliverable_status" class="required">Status</label>
                        <select id="deliverable_status" name="status" class="form-control" required>
                            <option value="Pending">Pending</option>
                            <option value="In Progress">In Progress</option>
                            <option value="Completed">Completed</option>
                            <option value="Delayed">Delayed</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="deliverable_progress_percentage">Progress (%)</label>
                        <input type="number" id="deliverable_progress_percentage" name="progress_percentage"
                               class="form-control" min="0" max="100" step="0.01">
                    </div>
                    <div class="form-group">
                        <label for="deliverable_responsible_person">Responsible Person</label>
                        <input type="text" id="deliverable_responsible_person" name="responsible_person" class="form-control">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="deliverable_budget_allocation">Budget Allocation</label>
                        <input type="number" id="deliverable_budget_allocation" name="budget_allocation"
                               class="form-control" min="0" step="0.01">
                    </div>
                    <div class="form-group">
                        <label for="deliverable_actual_cost">Actual Cost</label>
                        <input type="number" id="deliverable_actual_cost" name="actual_cost"
                               class="form-control" min="0" step="0.01">
                    </div>
                </div>

                <div class="form-group">
                    <label for="deliverable_notes">Notes</label>
                    <textarea id="deliverable_notes" name="notes" class="form-control" rows="3"
                              placeholder="Progress notes or remarks..."></textarea>
                </div>

                <div class="modal-footer">
                    <button type="button" onclick="closeModal('updateDeliverableModal')" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="update_deliverable" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Deliverable
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ====================================================================
     MODAL: Add Update
==================================================================== -->
<div id="addUpdateModal" class="modal">
    <div class="modal-content legacy-style-c04fdcc96d">
        <div class="modal-header">
            <h3><i class="fas fa-plus legacy-style-cd44151082"></i> Add Update</h3>
            <span class="close" onclick="closeModal('addUpdateModal')">-</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="includes/milestone-details-process.php">
                <input type="hidden" name="milestone_id" value="<?= (int)$milestone_id ?>">

                <div class="form-group">
                    <label for="update_type" class="required">Update Type</label>
                    <select id="update_type" name="update_type" class="form-control" required>
                        <option value="General">General Update</option>
                        <option value="Status Update">Status Update</option>
                        <option value="Achievement">Achievement</option>
                        <option value="Issue">Issue</option>
                        <option value="Delay">Delay</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="update_text" class="required">Update Details</label>
                    <textarea id="update_text" name="update_text" class="form-control" rows="4"
                              required placeholder="Describe the update..."></textarea>
                </div>

                <div class="form-group">
                    <label for="update_progress_percentage">Progress (%)</label>
                    <input type="number" id="update_progress_percentage" name="progress_percentage"
                           class="form-control" min="0" max="100" step="0.01"
                           value="<?= number_format((float)($milestone['progress_percentage'] ?? 0), 2, '.', '') ?>">
                    <small class="legacy-style-6d930ad79a">Leave unchanged if progress has not changed</small>
                </div>

                <div class="modal-footer">
                    <button type="button" onclick="closeModal('addUpdateModal')" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="add_update" class="btn btn-primary">
                        <i class="fas fa-save"></i> Add Update
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ====================================================================
     MODAL: Upload Document
==================================================================== -->
<div id="uploadDocModal" class="modal">
    <div class="modal-content legacy-style-5c0843b73b">
        <div class="modal-header">
            <h3><i class="fas fa-upload legacy-style-cd44151082"></i> Upload Document</h3>
            <span class="close" onclick="closeModal('uploadDocModal')">-</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="includes/milestone-details-process.php" enctype="multipart/form-data">
                <input type="hidden" name="milestone_id" value="<?= (int)$milestone_id ?>">

                <div class="form-group">
                    <label for="document_name" class="required">Document Name</label>
                    <input type="text" id="document_name" name="document_name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="document_file" class="required">File</label>
                    <input type="file" id="document_file" name="document_file" class="form-control" required>
                    <small class="legacy-style-6d930ad79a">Accepted: PDF, Word, Excel, images . Max 10 MB</small>
                </div>

                <div class="modal-footer">
                    <button type="button" onclick="closeModal('uploadDocModal')" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="upload_document" class="btn btn-primary">
                        <i class="fas fa-upload"></i> Upload
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    /* ------------------------------------------------------------------
     | Modal helpers (re-used from layout)
     ------------------------------------------------------------------ */
    window.openModal = function (id) {
        const el = document.getElementById(id);
        if (el) { el.classList.add('show'); el.style.display = 'block'; }
    };

    window.closeModal = function (id) {
        const el = document.getElementById(id);
        if (el) { el.classList.remove('show'); el.style.display = ''; }
    };

    // Close on backdrop click
    document.querySelectorAll('.modal').forEach(function (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal(modal.id);
        });
    });

    /* ------------------------------------------------------------------
     | Populate deliverable update modal
     ------------------------------------------------------------------ */
    window.openDeliverableUpdateModal = function (d) {
        if (!d) return;
        const set = function (id, val) {
            const el = document.getElementById(id);
            if (el) el.value = val || '';
        };
        set('deliverable_id',                d.deliverable_id);
        set('deliverable_title',             d.title);
        set('deliverable_start_date',        d.start_date);
        set('deliverable_end_date',          d.end_date);
        set('deliverable_completion_date',   d.completion_date);
        set('deliverable_status',            d.status || 'Pending');
        set('deliverable_progress_percentage', d.progress_percentage || '0');
        set('deliverable_responsible_person',  d.responsible_person);
        set('deliverable_budget_allocation',   d.budget_allocation || '0');
        set('deliverable_actual_cost',         d.actual_cost || '0');
        set('deliverable_notes',               d.notes);
        openModal('updateDeliverableModal');
    };

    /* ------------------------------------------------------------------
     | Auto-fill progress + completion date on status change
     ------------------------------------------------------------------ */
    const deliverableStatus     = document.getElementById('deliverable_status');
    const deliverableProgress   = document.getElementById('deliverable_progress_percentage');
    const deliverableCompletion = document.getElementById('deliverable_completion_date');

    if (deliverableStatus && deliverableProgress) {
        deliverableStatus.addEventListener('change', function () {
            if (deliverableStatus.value === 'Completed') {
                deliverableProgress.value = '100';
                if (deliverableCompletion && !deliverableCompletion.value) {
                    deliverableCompletion.value = new Date().toISOString().split('T')[0];
                }
            } else if (deliverableStatus.value === 'Pending') {
                deliverableProgress.value = '0';
                if (deliverableCompletion) deliverableCompletion.value = '';
            }
        });
    }

    /* ------------------------------------------------------------------
     | Validate date order on deliverable form submit
     ------------------------------------------------------------------ */
    const updateDeliverableForm = document.getElementById('updateDeliverableForm');
    if (updateDeliverableForm) {
        updateDeliverableForm.addEventListener('submit', function (e) {
            const start = document.getElementById('deliverable_start_date')?.value || '';
            const end   = document.getElementById('deliverable_end_date')?.value || '';
            if (start && end && end < start) {
                e.preventDefault();
                alert('End date cannot be earlier than start date.');
            }
        });
    }

    /* ------------------------------------------------------------------
     | Tab switching
     ------------------------------------------------------------------ */
    document.querySelectorAll('.tab-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.remove('active'); });
            document.querySelectorAll('.tab-panel').forEach(function (p) { p.classList.remove('active'); });
            btn.classList.add('active');
            const panel = document.getElementById('tab-' + btn.dataset.tab);
            if (panel) panel.classList.add('active');
        });
    });
})();
</script>

<?php include 'includes/footer.php'; ?>