<?php
declare(strict_types=1);

$page_title = 'Milestones Management';
//include 'includes/header.php';

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


function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function table_exists(mysqli $conn, string $table): bool
{
    $table = trim($table);

    if ($table === '') {
        return false;
    }

    $res = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");

    if (!$res) {
        return false;
    }

    $exists = $res->num_rows > 0;
    $res->close();

    return $exists;
}

function bind_params_dynamic(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '' || empty($params)) {
        return;
    }

    $refs = [&$types];

    foreach ($params as $key => $value) {
        $refs[] = &$params[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function initials(string $name): string
{
    $name = trim($name);

    if ($name === '') {
        return '?';
    }

    $parts = preg_split('/\s+/', $name);

    if (count($parts) >= 2) {
        return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
    }

    return strtoupper(mb_substr($parts[0], 0, 1));
}

function prog_class(float $pct): string
{
    if ($pct >= 75) {
        return 'high';
    }

    if ($pct >= 50) {
        return 'mid';
    }

    return 'low';
}

function format_date(?string $date, string $fallback = '-'): string
{
    if (empty($date)) {
        return $fallback;
    }

    $ts = strtotime($date);

    return $ts ? date('d M Y', $ts) : $fallback;
}


$hasPrograms = table_exists($conn, 'programs');
$hasDeliverablesTable = table_exists($conn, 'milestone_deliverables');


$projects = [];

$res = $conn->query("
    SELECT project_id, project_code, project_name
    FROM projects
    ORDER BY project_code, project_name
");

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $projects[] = $row;
    }

    $res->close();
}

$programs = [];

if ($hasPrograms) {
    $res = $conn->query("
        SELECT id, program_code, program_name
        FROM programs
        ORDER BY program_code, program_name
    ");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $programs[] = $row;
        }

        $res->close();
    }
}


$edit_milestone = null;

if (isset($_GET['edit']) && (int)$_GET['edit'] > 0) {
    $mid = (int)$_GET['edit'];

    $stmt = $conn->prepare("SELECT * FROM milestones WHERE milestone_id = ? LIMIT 1");

    if ($stmt) {
        $stmt->bind_param('i', $mid);
        $stmt->execute();

        $result = $stmt->get_result();
        $edit_milestone = $result ? $result->fetch_assoc() : null;

        $stmt->close();
    }

    if ($edit_milestone && $hasDeliverablesTable) {
        $stmt = $conn->prepare("
            SELECT
                deliverable_id,
                deliverable_title,
                start_date,
                end_date,
                completion_date,
                status,
                progress_percentage,
                responsible_person,
                budget_allocation,
                actual_cost,
                notes
            FROM milestone_deliverables
            WHERE milestone_id = ?
            ORDER BY COALESCE(start_date, end_date), deliverable_id
        ");

        if ($stmt) {
            $stmt->bind_param('i', $mid);
            $stmt->execute();

            $result = $stmt->get_result();
            $editDeliverables = [];

            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $editDeliverables[] = [
                        'deliverable_id' => (int)$row['deliverable_id'],
                        'title' => (string)($row['deliverable_title'] ?? ''),
                        'start_date' => (string)($row['start_date'] ?? ''),
                        'end_date' => (string)($row['end_date'] ?? ''),
                        'completion_date' => (string)($row['completion_date'] ?? ''),
                        'status' => (string)($row['status'] ?? 'Pending'),
                        'progress_percentage' => (string)($row['progress_percentage'] ?? '0'),
                        'responsible_person' => (string)($row['responsible_person'] ?? ''),
                        'budget_allocation' => (string)($row['budget_allocation'] ?? '0'),
                        'actual_cost' => (string)($row['actual_cost'] ?? '0'),
                        'notes' => (string)($row['notes'] ?? ''),
                    ];
                }
            }

       
            if (!empty($editDeliverables)) {
                $edit_milestone['deliverables'] = json_encode($editDeliverables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $stmt->close();
        }
    }
}


$filter_entity_type = trim((string)($_GET['entity_type'] ?? ''));
$filter_entity      = (int)($_GET['entity'] ?? 0);
$filter_status      = trim((string)($_GET['status'] ?? ''));
$filter_type        = trim((string)($_GET['type'] ?? ''));
$search             = trim((string)($_GET['search'] ?? ''));

$allowed_statuses = ['Not Started', 'In Progress', 'Completed', 'Delayed', 'Cancelled'];
$allowed_types    = ['Planning', 'Implementation', 'Monitoring', 'Evaluation', 'Reporting', 'Other'];

if (!in_array($filter_entity_type, ['Project', 'Program'], true)) {
    $filter_entity_type = '';
}

if (!in_array($filter_status, $allowed_statuses, true)) {
    $filter_status = '';
}

if (!in_array($filter_type, $allowed_types, true)) {
    $filter_type = '';
}


$deliverableSelect = $hasDeliverablesTable
    ? ",
        COUNT(md.deliverable_id) AS deliverable_count,
        MIN(md.start_date) AS first_deliverable_start,
        MAX(md.end_date) AS last_deliverable_end,
        SUM(CASE WHEN md.status = 'Completed' THEN 1 ELSE 0 END) AS completed_deliverables"
    : ",
        0 AS deliverable_count,
        NULL AS first_deliverable_start,
        NULL AS last_deliverable_end,
        0 AS completed_deliverables";

$sql = "
    SELECT
        m.*,
        CASE
            WHEN m.entity_type IN ('Project','Program') THEN m.entity_type
            WHEN m.project_id IS NOT NULL THEN 'Project'
            WHEN m.program_id IS NOT NULL THEN 'Program'
            ELSE 'Unknown'
        END AS display_entity_type,
        CASE
            WHEN (m.entity_type = 'Project' AND m.entity_id IS NOT NULL) OR (m.entity_type IS NULL AND m.project_id IS NOT NULL)
                THEN p.project_code
            WHEN (m.entity_type = 'Program' AND m.entity_id IS NOT NULL) OR (m.entity_type IS NULL AND m.program_id IS NOT NULL)
                THEN pr.program_code
            ELSE 'N/A'
        END AS entity_code,
        CASE
            WHEN (m.entity_type = 'Project' AND m.entity_id IS NOT NULL) OR (m.entity_type IS NULL AND m.project_id IS NOT NULL)
                THEN CONCAT(COALESCE(p.project_code, ''), ' - ', COALESCE(p.project_name, ''))
            WHEN (m.entity_type = 'Program' AND m.entity_id IS NOT NULL) OR (m.entity_type IS NULL AND m.program_id IS NOT NULL)
                THEN CONCAT(COALESCE(pr.program_code, ''), ' - ', COALESCE(pr.program_name, ''))
            ELSE 'Unknown'
        END AS entity_name,
        CASE WHEN m.status = 'Completed' THEN 100 ELSE COALESCE(m.progress_percentage, 0) END AS display_progress,
        DATEDIFF(m.due_date, CURDATE()) AS days_until_due
        {$deliverableSelect}
    FROM milestones m
    LEFT JOIN projects p ON (
        (m.entity_type = 'Project' AND p.project_id = m.entity_id)
        OR (m.entity_type IS NULL AND p.project_id = m.project_id)
    )
";

$sql .= $hasPrograms
    ? " LEFT JOIN programs pr ON ((m.entity_type = 'Program' AND pr.id = m.entity_id) OR (m.entity_type IS NULL AND pr.id = m.program_id))"
    : " LEFT JOIN (SELECT NULL AS id, NULL AS program_code, NULL AS program_name) pr ON 1 = 0";

if ($hasDeliverablesTable) {
    $sql .= " LEFT JOIN milestone_deliverables md ON md.milestone_id = m.milestone_id";
}

$where  = [];
$types  = '';
$params = [];

if ($filter_entity_type === 'Project') {
    if ($filter_entity > 0) {
        $where[] = "((m.entity_type = 'Project' AND m.entity_id = ?) OR (m.entity_type IS NULL AND m.project_id = ?))";
        $types .= 'ii';
        $params[] = $filter_entity;
        $params[] = $filter_entity;
    } else {
        $where[] = "(m.entity_type = 'Project' OR (m.entity_type IS NULL AND m.project_id IS NOT NULL))";
    }
} elseif ($filter_entity_type === 'Program') {
    if ($filter_entity > 0) {
        $where[] = "((m.entity_type = 'Program' AND m.entity_id = ?) OR (m.entity_type IS NULL AND m.program_id = ?))";
        $types .= 'ii';
        $params[] = $filter_entity;
        $params[] = $filter_entity;
    } else {
        $where[] = "(m.entity_type = 'Program' OR (m.entity_type IS NULL AND m.program_id IS NOT NULL))";
    }
}

if ($filter_status !== '') {
    $where[] = "m.status = ?";
    $types .= 's';
    $params[] = $filter_status;
}

if ($filter_type !== '') {
    $where[] = "m.milestone_type = ?";
    $types .= 's';
    $params[] = $filter_type;
}

if ($search !== '') {
    $like = '%' . $search . '%';

    if ($hasDeliverablesTable) {
        $where[] = "(
            m.milestone_name LIKE ?
            OR m.milestone_description LIKE ?
            OR m.responsible_person LIKE ?
            OR p.project_name LIKE ?
            OR p.project_code LIKE ?
            OR pr.program_name LIKE ?
            OR pr.program_code LIKE ?
            OR md.deliverable_title LIKE ?
        )";
        $types .= 'ssssssss';
        for ($i = 0; $i < 8; $i++) {
            $params[] = $like;
        }
    } else {
        $where[] = "(
            m.milestone_name LIKE ?
            OR m.milestone_description LIKE ?
            OR m.responsible_person LIKE ?
            OR p.project_name LIKE ?
            OR p.project_code LIKE ?
            OR pr.program_name LIKE ?
            OR pr.program_code LIKE ?
        )";
        $types .= 'sssssss';
        for ($i = 0; $i < 7; $i++) {
            $params[] = $like;
        }
    }
}

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= "
    GROUP BY m.milestone_id
    ORDER BY m.due_date ASC, m.created_at DESC, m.milestone_id DESC
";

$milestones = [];

$stmt = $conn->prepare($sql);

if ($stmt) {
    bind_params_dynamic($stmt, $types, $params);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $milestones[] = $row;
        }
    }

    $stmt->close();
}


$todayTs = strtotime(date('Y-m-d'));

$stats = [
    'total' => count($milestones),
    'in_progress' => count(array_filter($milestones, static fn($m) => $m['status'] === 'In Progress')),
    'completed' => count(array_filter($milestones, static fn($m) => $m['status'] === 'Completed')),
    'delayed' => count(array_filter($milestones, static fn($m) => $m['status'] === 'Delayed')),
    'not_started' => count(array_filter($milestones, static fn($m) => $m['status'] === 'Not Started')),
    'overdue' => count(array_filter($milestones, static function ($m) use ($todayTs) {
        $due = !empty($m['due_date']) ? strtotime((string)$m['due_date']) : false;

        return $due && !in_array($m['status'], ['Completed', 'Cancelled'], true) && $due < $todayTs;
    })),
];

$status_badge = [
    'Not Started' => 'badge badge-not-started',
    'In Progress' => 'badge badge-in-progress',
    'Completed' => 'badge badge-completed',
    'Delayed' => 'badge badge-delayed',
    'Cancelled' => 'badge badge-cancelled',
];
?>

<link rel="stylesheet" href="css/reports.css">
<link rel="stylesheet" href="css/milestones.css">



<div class="milestones-wrap">

    <!-- Hero -->
    <div class="milestones-hero">
        <div class="milestones-hero-text">
            <h1>
                <i class="fas fa-flag-checkered legacy-style-b41eb069bf"></i>
                Milestones
            </h1>
            <p>Track milestone progress, deliverables, date ranges, and responsibilities across projects and programmes.</p>
        </div>

        <div class="hero-actions">
            <button type="button" class="btn btn-primary" onclick="openModal('addMilestoneModal')">
                <i class="fas fa-plus"></i> Add Milestone
            </button>
        </div>
    </div>

    <!-- Stats -->
    <div class="milestones-stats">
        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class="fas fa-flag-checkered"></i></div>
            <div class="stat-info">
                <div class="stat-label">Total</div>
                <div class="stat-value"><?php echo (int)$stats['total']; ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-hourglass-half"></i></div>
            <div class="stat-info">
                <div class="stat-label">In Progress</div>
                <div class="stat-value"><?php echo (int)$stats['in_progress']; ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info">
                <div class="stat-label">Completed</div>
                <div class="stat-value"><?php echo (int)$stats['completed']; ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon legacy-style-1fdd5e47bc">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stat-info">
                <div class="stat-label">Overdue</div>
                <div class="stat-value legacy-style-fe6ab3088d"><?php echo (int)$stats['overdue']; ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-purple"><i class="fas fa-clock"></i></div>
            <div class="stat-info">
                <div class="stat-label">Delayed</div>
                <div class="stat-value"><?php echo (int)$stats['delayed']; ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon legacy-style-9f857e731d">
                <i class="fas fa-pause-circle"></i>
            </div>
            <div class="stat-info">
                <div class="stat-label">Not Started</div>
                <div class="stat-value"><?php echo (int)$stats['not_started']; ?></div>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <form method="GET" action="" class="filter-bar">
        <div class="form-group search-group">
            <label class="form-label">Search</label>
            <div class="search-input-wrap">
                <i class="fas fa-search"></i>
                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="Name, description, deliverable, responsible person..."
                    value="<?php echo h($search); ?>"
                >
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Entity Type</label>
            <select name="entity_type" id="entityTypeFilter" class="form-control" onchange="toggleEntityFilter()">
                <option value="">All Entities</option>
                <option value="Project" <?php echo $filter_entity_type === 'Project' ? 'selected' : ''; ?>>Projects</option>

                <?php if (!empty($programs)): ?>
                    <option value="Program" <?php echo $filter_entity_type === 'Program' ? 'selected' : ''; ?>>Programs</option>
                <?php endif; ?>
            </select>
        </div>

        <div
            class="form-group"
            id="projectFilter"
            style="display:<?php echo ($filter_entity_type === '' || $filter_entity_type === 'Project') ? 'flex' : 'none'; ?>;"
        >
            <label class="form-label">Project</label>
            <select name="entity" class="form-control">
                <option value="">All Projects</option>

                <?php foreach ($projects as $project): ?>
                    <option
                        value="<?php echo (int)$project['project_id']; ?>"
                        <?php echo ($filter_entity_type === 'Project' && $filter_entity === (int)$project['project_id']) ? 'selected' : ''; ?>
                    >
                        <?php echo h(($project['project_code'] ?? '') . ' - ' . ($project['project_name'] ?? '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if (!empty($programs)): ?>
            <div
                class="form-group"
                id="programFilter"
                style="display:<?php echo $filter_entity_type === 'Program' ? 'flex' : 'none'; ?>;"
            >
                <label class="form-label">Program</label>
                <select name="entity" class="form-control">
                    <option value="">All Programs</option>

                    <?php foreach ($programs as $program): ?>
                        <option
                            value="<?php echo (int)$program['id']; ?>"
                            <?php echo ($filter_entity_type === 'Program' && $filter_entity === (int)$program['id']) ? 'selected' : ''; ?>
                        >
                            <?php echo h(($program['program_code'] ?? '') . ' - ' . ($program['program_name'] ?? '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="form-group">
            <label class="form-label">Status</label>
            <select name="status" class="form-control">
                <option value="">All Statuses</option>

                <?php foreach ($allowed_statuses as $status): ?>
                    <option value="<?php echo h($status); ?>" <?php echo $filter_status === $status ? 'selected' : ''; ?>>
                        <?php echo h($status); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">Type</label>
            <select name="type" class="form-control">
                <option value="">All Types</option>

                <?php foreach ($allowed_types as $type): ?>
                    <option value="<?php echo h($type); ?>" <?php echo $filter_type === $type ? 'selected' : ''; ?>>
                        <?php echo h($type); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-actions">
            <button type="submit" class="btn btn-dark">
                <i class="fas fa-filter"></i> Filter
            </button>
            <a href="milestones.php" class="btn btn-gray">
                <i class="fas fa-times"></i> Clear
            </a>
        </div>
    </form>

    <!-- Milestones Table -->
    <div class="panel">
        <div class="panel-head">
            <h3>
                <i class="fas fa-flag-checkered legacy-style-a196051550"></i>
                Milestones
            </h3>

            <span class="badge <?php echo $stats['total'] > 0 ? 'badge-available' : 'badge-pending'; ?>">
                <?php echo (int)$stats['total']; ?> record<?php echo $stats['total'] !== 1 ? 's' : ''; ?>
            </span>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Milestone</th>
                        <th>Entity</th>
                        <th>Type</th>
                        <th>Deliverables</th>
                        <th>Due Date</th>
                        <th>Status</th>
                        <th>Progress</th>
                        <th>Responsible</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($milestones)): ?>
                        <tr>
                            <td colspan="9" class="legacy-style-c277398392">
                                <div class="legacy-style-33e4fdb56e">
                                    <i class="fas fa-flag-checkered legacy-style-905fa29570"></i>
                                    <span class="legacy-style-ea2b9949d0">No milestones found</span>
                                    <span class="note">Adjust your filters or add a new milestone</span>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($milestones as $milestone): ?>
                            <?php
                            $dueTs = !empty($milestone['due_date']) ? strtotime((string)$milestone['due_date']) : false;
                            $isOverdue = $dueTs && !in_array($milestone['status'], ['Completed', 'Cancelled'], true) && $dueTs < $todayTs;
                            $dueSoon = !$isOverdue
                                && $dueTs
                                && $milestone['status'] !== 'Completed'
                                && isset($milestone['days_until_due'])
                                && (int)$milestone['days_until_due'] >= 0
                                && (int)$milestone['days_until_due'] <= 7;

                            $progress = max(0.0, min(100.0, (float)($milestone['display_progress'] ?? 0)));
                            $entityType = $milestone['display_entity_type'] ?? 'Unknown';
                            $entityIcon = strtolower((string)$entityType) === 'project' ? 'fa-project-diagram' : 'fa-sitemap';
                            $entityClass = strtolower((string)$entityType) === 'project' ? 'project' : 'program';

                            $deliverableCount = (int)($milestone['deliverable_count'] ?? 0);
                            $completedDeliverables = (int)($milestone['completed_deliverables'] ?? 0);
                            ?>
                            <tr>
                                <td class="legacy-style-8cea5c0263">
                                    <div class="milestone-name"><?php echo h($milestone['milestone_name'] ?? ''); ?></div>

                                    <?php if (!empty($milestone['milestone_description'])): ?>
                                        <div class="milestone-desc">
                                            <?php
                                            $description = (string)$milestone['milestone_description'];
                                            echo h(mb_strlen($description) > 70 ? mb_substr($description, 0, 70) . '...' : $description);
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="entity-cell">
                                        <span class="entity-chip <?php echo h($entityClass); ?>">
                                            <i class="fas <?php echo h($entityIcon); ?>"></i>
                                            <?php echo h($entityType); ?>
                                        </span>

                                        <span class="entity-code" title="<?php echo h($milestone['entity_name'] ?? ''); ?>">
                                            <?php echo h($milestone['entity_code'] ?? 'N/A'); ?>
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <span class="legacy-style-60350cd4ef">
                                        <?php echo h($milestone['milestone_type'] ?? '-'); ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="deliverable-summary">
                                        <?php if ($deliverableCount > 0): ?>
                                            <span class="deliverable-count-pill">
                                                <i class="fas fa-list-check"></i>
                                                <?php echo $completedDeliverables; ?>/<?php echo $deliverableCount; ?>
                                            </span>

                                            <span class="deliverable-date-range">
                                                <i class="fas fa-calendar-alt"></i>
                                                <?php echo h(format_date($milestone['first_deliverable_start'] ?? null)); ?>
                                                -
                                                <?php echo h(format_date($milestone['last_deliverable_end'] ?? null)); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="legacy-style-bb9d5e65c5">No deliverables</span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td>
                                    <div class="due-cell">
                                        <span class="due-date <?php echo $isOverdue ? 'overdue' : ''; ?>">
                                            <?php echo $dueTs ? date('d M Y', $dueTs) : '-'; ?>
                                        </span>

                                        <?php if ($isOverdue): ?>
                                            <span class="badge badge-overdue legacy-style-1f3b2ba45a">
                                                <i class="fas fa-exclamation-circle"></i> Overdue
                                            </span>
                                        <?php elseif ($dueSoon): ?>
                                            <span class="badge badge-due-soon legacy-style-1f3b2ba45a">
                                                <i class="fas fa-clock"></i> Due soon
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td>
                                    <span class="<?php echo h($status_badge[$milestone['status']] ?? 'badge badge-not-started'); ?>">
                                        <?php echo h($milestone['status'] ?? 'Not Started'); ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="progress-cell">
                                        <div class="progress-header">
                                            <span class="progress-pct"><?php echo number_format($progress, 0); ?>%</span>
                                        </div>

                                        <div class="prog-track">
                                            <div
                                                class="prog-fill <?php echo h(prog_class($progress)); ?>"
                                                style="width:<?php echo $progress; ?>%;"
                                            ></div>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <?php if (!empty($milestone['responsible_person'])): ?>
                                        <div class="responsible-cell">
                                            <div class="responsible-avatar">
                                                <?php echo h(initials((string)$milestone['responsible_person'])); ?>
                                            </div>
                                            <span><?php echo h($milestone['responsible_person']); ?></span>
                                        </div>
                                    <?php else: ?>
                                        <span class="legacy-style-bb9d5e65c5">-</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="actions">
                                        <a
                                            href="milestone-details.php?id=<?php echo (int)$milestone['milestone_id']; ?>"
                                            class="btn btn-sm btn-soft"
                                            title="View details"
                                        >
                                            <i class="fas fa-eye"></i>
                                        </a>

                                        <a
                                            href="?edit=<?php echo (int)$milestone['milestone_id']; ?>"
                                            class="btn btn-sm btn-gray"
                                            title="Edit"
                                        >
                                            <i class="fas fa-edit"></i>
                                        </a>

                                        <?php if (in_array($_SESSION['role'] ?? '', ['Administrator', 'Programs Lead'], true)): ?>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-red"
                                                title="Delete"
                                                onclick="openDeleteModal(<?php echo (int)$milestone['milestone_id']; ?>, <?php echo json_encode((string)$milestone['milestone_name']); ?>)"
                                            >
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'includes/milestones-modals.php'; ?>

<script>
(function () {
    'use strict';

    window.toggleEntityFilter = function () {
        const type = document.getElementById('entityTypeFilter')?.value || '';
        const project = document.getElementById('projectFilter');
        const program = document.getElementById('programFilter');

        if (type === 'Program') {
            if (project) project.style.display = 'none';
            if (program) program.style.display = 'flex';
        } else {
            if (project) project.style.display = 'flex';
            if (program) program.style.display = 'none';
        }
    };

    window.openDeleteModal = function (id, name) {
        const idEl = document.getElementById('deleteMilestoneId');
        const nameEl = document.getElementById('deleteMilestoneName');

        if (idEl) {
            idEl.value = id;
        }

        if (nameEl) {
            nameEl.textContent = name;
        }

        openModal('deleteModal');
    };

    <?php if ($edit_milestone): ?>
    document.addEventListener('DOMContentLoaded', function () {
        openModal('editMilestoneModal');
    });
    <?php endif; ?>
})();
</script>

<?php include 'includes/footer.php'; ?>
