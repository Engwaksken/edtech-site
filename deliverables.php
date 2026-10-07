<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/deliverables-functions.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection error.');
}

$conn->set_charset('utf8mb4');

date_default_timezone_set('Africa/Kampala');
$conn->query("SET time_zone = '+03:00'");

refresh_overdue_deliverables($conn);

$ventureId = (int)($_SESSION['venture_id'] ?? 0);

if ($ventureId <= 0) {
    http_response_code(403);
    exit('Invalid venture session.');
}

$page_title = 'Venture Deliverables';
$current_nav = 'deliverables.php';

/*
|--------------------------------------------------------------------------
| Load venture information
|--------------------------------------------------------------------------
*/

$venture = null;

$ventureStmt = $conn->prepare("
    SELECT
        v.id,
        v.name,
        v.email,
        v.cohort_id,
        c.name AS cohort_name
    FROM ventures v
    LEFT JOIN cohorts c ON c.id = v.cohort_id
    WHERE v.id = ?
    LIMIT 1
");

if ($ventureStmt) {
    $ventureStmt->bind_param('i', $ventureId);
    $ventureStmt->execute();
    $venture = $ventureStmt->get_result()->fetch_assoc() ?: null;
    $ventureStmt->close();
}

if (!$venture) {
    http_response_code(404);
    exit('Venture account not found.');
}

/*
|--------------------------------------------------------------------------
| Load venture deliverables
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        da.*,
        dc.name AS deliverable_name,
        dc.description,
        dc.theme,
        dc.template_name,
        dc.template_path,
        dc.frequency,
        dc.expected_completion_hours,
        c.name AS cohort_name,
        ac.phase,
        ac.week_number,
        ac.sprint,
        u.full_name AS mentor_name
    FROM deliverable_assignments da
    INNER JOIN deliverables_catalogue dc
        ON dc.id = da.catalogue_id
    LEFT JOIN cohorts c
        ON c.id = da.cohort_id
    LEFT JOIN accelerator_cycles ac
        ON ac.id = da.cycle_id
    LEFT JOIN mentor_profiles mp
        ON mp.id = da.mentor_id
    LEFT JOIN admin_users u
        ON u.id = mp.user_id
    WHERE da.venture_id = ?
    ORDER BY
        CASE
            WHEN da.status = 'overdue' THEN 0
            WHEN da.due_date = CURDATE() THEN 1
            ELSE 2
        END,
        da.due_date ASC,
        dc.name ASC
");

if (!$stmt) {
    http_response_code(500);
    exit('Could not prepare deliverables query: ' . h($conn->error));
}

$stmt->bind_param('i', $ventureId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$summary = deliverable_summary($rows);

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$statusFilter = trim((string)($_GET['status'] ?? ''));
$themeFilter = trim((string)($_GET['theme'] ?? ''));
$deadlineFilter = trim((string)($_GET['deadline'] ?? ''));

$allowedStatuses = [
    'not_started',
    'in_progress',
    'submitted',
    'returned_for_revision',
    'approved',
    'overdue',
    'completed',
];

$allowedDeadlineFilters = [
    '',
    'due_today',
    'due_week',
    'overdue',
];

if (
    $statusFilter !== ''
    && !in_array($statusFilter, $allowedStatuses, true)
) {
    $statusFilter = '';
}

if (!in_array($deadlineFilter, $allowedDeadlineFilters, true)) {
    $deadlineFilter = '';
}

$themes = [];

foreach ($rows as $row) {
    $rowTheme = trim((string)($row['theme'] ?? ''));

    if ($rowTheme !== '') {
        $themes[$rowTheme] = $rowTheme;
    }
}

ksort($themes);

$filteredRows = array_values(
    array_filter(
        $rows,
        static function (array $row) use (
            $statusFilter,
            $themeFilter,
            $deadlineFilter
        ): bool {
            if (
                $statusFilter !== ''
                && (string)$row['status'] !== $statusFilter
            ) {
                return false;
            }

            if (
                $themeFilter !== ''
                && (string)$row['theme'] !== $themeFilter
            ) {
                return false;
            }

            if ($deadlineFilter === '') {
                return true;
            }

            $dueDate = (string)($row['due_date'] ?? '');

            if ($dueDate === '') {
                return false;
            }

            $today = new DateTimeImmutable('today');
            $due = new DateTimeImmutable($dueDate);
            $days = (int)$today->diff($due)->format('%r%a');

            return match ($deadlineFilter) {
                'due_today' => $days === 0,
                'due_week' => $days >= 0 && $days <= 7,
                'overdue' => $days < 0
                    && !in_array(
                        (string)$row['status'],
                        ['approved', 'completed'],
                        true
                    ),
                default => true,
            };
        }
    )
);

/*
|--------------------------------------------------------------------------
| Messages
|--------------------------------------------------------------------------
*/

$message = isset($_GET['message']) && is_string($_GET['message'])
    ? mb_substr(trim($_GET['message']), 0, 500)
    : '';

$messageType = isset($_GET['message_type']) && is_string($_GET['message_type'])
    ? trim($_GET['message_type'])
    : '';

$messageClass = $messageType === 'success'
    ? 'success'
    : 'error';

/*
|--------------------------------------------------------------------------
| Layout
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/layout.php';
?>

<div class="vd-page">
    <div class="vd-page-header">
        <div>
            <h1>Venture Deliverables</h1>

            <p>
                View deadlines, download templates, and submit completed
                accelerator work for
                <strong><?= h((string)$venture['name']) ?></strong>.
            </p>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div
            class="vd-notice <?= h($messageClass) ?>"
            role="<?= $messageType === 'success' ? 'status' : 'alert' ?>"
        >
            <i
                class="fas <?= $messageType === 'success'
                    ? 'fa-circle-check'
                    : 'fa-circle-exclamation' ?>"
                aria-hidden="true"
            ></i>

            <span><?= h($message) ?></span>
        </div>
    <?php endif; ?>

    <div class="vd-stats">
        <a
            href="deliverables.php"
            class="vd-stat-link <?= $statusFilter === '' && $themeFilter === '' && $deadlineFilter === '' ? 'active' : '' ?>"
            aria-label="View all deliverables"
            title="View all deliverables"
        >
            <div class="vd-stat">
                <strong><?= (int)$summary['total'] ?></strong>
                <span>Total Deliverables</span>
                <small>View all</small>
            </div>
        </a>

        <a
            href="?status=completed"
            class="vd-stat-link <?= $statusFilter === 'completed' ? 'active' : '' ?>"
            aria-label="Filter completed deliverables"
            title="Filter completed deliverables"
        >
            <div class="vd-stat">
                <strong><?= (int)$summary['completed'] ?></strong>
                <span>Completed</span>
                <small>Filter completed</small>
            </div>
        </a>

        <a
            href="?status=submitted"
            class="vd-stat-link <?= $statusFilter === 'submitted' ? 'active' : '' ?>"
            aria-label="Filter deliverables awaiting review"
            title="Filter awaiting review"
        >
            <div class="vd-stat">
                <strong><?= (int)$summary['submitted'] ?></strong>
                <span>Awaiting Review</span>
                <small>Filter submitted</small>
            </div>
        </a>

        <a
            href="?deadline=due_week"
            class="vd-stat-link <?= $deadlineFilter === 'due_week' ? 'active' : '' ?>"
            aria-label="Filter deliverables due this week"
            title="Filter due this week"
        >
            <div class="vd-stat">
                <strong><?= (int)$summary['due_week'] ?></strong>
                <span>Due This Week</span>
                <small>Filter next 7 days</small>
            </div>
        </a>

        <a
            href="?deadline=overdue"
            class="vd-stat-link <?= $deadlineFilter === 'overdue' ? 'active' : '' ?>"
            aria-label="Filter overdue deliverables"
            title="Filter overdue deliverables"
        >
            <div class="vd-stat">
                <strong><?= (int)$summary['overdue'] ?></strong>
                <span>Overdue</span>
                <small>Filter overdue</small>
            </div>
        </a>
    </div>

    <form
        method="get"
        class="vd-filters"
    >
        <div class="vd-filter-field vd-filter-theme">
            <select
                name="theme"
                aria-label="Filter by theme"
            >
            <option value="">All Themes</option>

            <?php foreach ($themes as $theme): ?>
                <option
                    value="<?= h($theme) ?>"
                    <?= $themeFilter === $theme ? 'selected' : '' ?>
                >
                    <?= h($theme) ?>
                </option>
            <?php endforeach; ?>
            </select>
        </div>

        <div class="vd-filter-field vd-filter-status">
            <select
                name="status"
                aria-label="Filter by status"
            >
            <option value="">All Statuses</option>

            <?php foreach ($allowedStatuses as $status): ?>
                <option
                    value="<?= h($status) ?>"
                    <?= $statusFilter === $status ? 'selected' : '' ?>
                >
                    <?= h(deliverable_status_label($status)) ?>
                </option>
            <?php endforeach; ?>
            </select>
        </div>

        <div class="vd-filter-field vd-filter-deadline">
            <select
                name="deadline"
                aria-label="Filter by deadline"
            >
            <option value="">All Deadlines</option>

            <option
                value="due_today"
                <?= $deadlineFilter === 'due_today' ? 'selected' : '' ?>
            >
                Due Today
            </option>

            <option
                value="due_week"
                <?= $deadlineFilter === 'due_week' ? 'selected' : '' ?>
            >
                Due Within 7 Days
            </option>

            <option
                value="overdue"
                <?= $deadlineFilter === 'overdue' ? 'selected' : '' ?>
            >
                Overdue
            </option>
            </select>
        </div>

        <div class="vd-filter-actions">
            <button
                type="submit"
                class="btn btn-primary btn-sm"
            >
                <i class="fas fa-filter"></i>
                Filter
            </button>

            <a
                href="deliverables.php"
                class="btn btn-outline btn-sm"
            >
                Reset
            </a>
        </div>
    </form>

    <div class="vd-grid">
        <?php if (!$filteredRows): ?>
            <div class="vd-empty">
                <div class="vd-empty-icon">
                    <i class="fas fa-clipboard-check"></i>
                </div>

                <h3>No deliverables found</h3>

                <p>
                    No deliverables match the selected filters, or no
                    deliverables have been assigned to this venture yet.
                </p>
            </div>
        <?php endif; ?>

        <?php foreach ($filteredRows as $row): ?>
            <?php
            $assignmentId = (int)$row['id'];
            $status = (string)$row['status'];
            $dueDate = (string)$row['due_date'];

            $statusClass = deliverable_status_class(
                $status,
                $dueDate
            );

            $isClosed = in_array(
                $status,
                ['approved', 'completed'],
                true
            );

            $isSubmitted = $status === 'submitted';

            $templatePath = trim(
                (string)($row['template_path'] ?? '')
            );

            $submittedFilePath = trim(
                (string)($row['submission_file_path'] ?? '')
            );

            $submittedFileName = trim(
                (string)($row['submission_file_name'] ?? '')
            );
            ?>

            <article class="vd-card">
                <div class="vd-card-top">
                    <div class="vd-card-heading">
                        <h2>
                            <?= h((string)$row['deliverable_name']) ?>
                        </h2>

                        <div class="vd-meta">
                            <span>
                                <i class="fas fa-layer-group"></i>
                                <?= h((string)$row['theme']) ?>
                            </span>

                            <span>
                                <i class="fas fa-user-tie"></i>
                                <?= h(
                                    (string)(
                                        $row['mentor_name']
                                        ?? 'Mentor not assigned'
                                    )
                                ) ?>
                            </span>

                            <?php if (!empty($row['phase'])): ?>
                                <span>
                                    <i class="fas fa-road"></i>
                                    <?= h((string)$row['phase']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <span class="vd-tag <?= h($statusClass) ?>">
                        <?= h(deliverable_status_label($status)) ?>
                    </span>
                </div>

                <div class="vd-deadline">
                    <div>
                        <span class="vd-label">Due date</span>

                        <strong>
                            <?= h(
                                date(
                                    'j M Y',
                                    strtotime($dueDate)
                                )
                            ) ?>
                        </strong>
                    </div>

                    <span class="vd-due-label <?= h($statusClass) ?>">
                        <?= h(deliverable_due_label($dueDate)) ?>
                    </span>
                </div>

                <div class="vd-progress-heading">
                    <span>Progress</span>
                    <strong>
                        <?= (int)$row['progress_percent'] ?>%
                    </strong>
                </div>

                <div
                    class="vd-progress"
                    aria-label="Progress <?= (int)$row['progress_percent'] ?> percent"
                >
                    <span
                        style="width:<?= (int)$row['progress_percent'] ?>%"
                    ></span>
                </div>

                <?php if (!empty($row['description'])): ?>
                    <div class="vd-description">
                        <?= nl2br(
                            h((string)$row['description'])
                        ) ?>
                    </div>
                <?php endif; ?>

                <div class="vd-information">
                    <?php if (!empty($row['expected_completion_hours'])): ?>
                        <span>
                            <i class="far fa-clock"></i>
                            <?= h(
                                (string)$row['expected_completion_hours']
                            ) ?>
                            expected hours
                        </span>
                    <?php endif; ?>

                    <?php if (!empty($row['frequency'])): ?>
                        <span>
                            <i class="fas fa-repeat"></i>
                            <?= h(
                                ucwords(
                                    str_replace(
                                        '_',
                                        ' ',
                                        (string)$row['frequency']
                                    )
                                )
                            ) ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if ($templatePath !== ''): ?>
                    <div class="vd-actions">
                        <a
                            class="btn btn-outline btn-sm"
                            href="<?= h($templatePath) ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <i class="fas fa-download"></i>
                            Download Template
                        </a>
                    </div>
                <?php endif; ?>

                <?php if ($submittedFilePath !== ''): ?>
                    <div class="vd-submitted-file">
                        <i class="fas fa-paperclip"></i>

                        <div>
                            <strong>Submitted file</strong>

                            <a
                                href="uploads/deliverables/<?= rawurlencode($submittedFilePath) ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <?= h(
                                    $submittedFileName !== ''
                                        ? $submittedFileName
                                        : $submittedFilePath
                                ) ?>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($isSubmitted): ?>
                    <div class="vd-review-note">
                        <i class="fas fa-clock"></i>

                        <div>
                            <strong>Awaiting review</strong>

                            <p>
                                Your submission has been received and is
                                waiting for mentor or program-team review.
                            </p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!$isClosed): ?>
                    <form
                        class="vd-submit"
                        method="post"
                        action="includes/process-deliverables.php"
                        enctype="multipart/form-data"
                    >
                        <input
                            type="hidden"
                            name="action"
                            value="venture_submit"
                        >

                        <input
                            type="hidden"
                            name="assignment_id"
                            value="<?= $assignmentId ?>"
                        >

                        <label
                            for="submissionNotes<?= $assignmentId ?>"
                        >
                            Submission notes or link
                        </label>

                        <textarea
                            id="submissionNotes<?= $assignmentId ?>"
                            name="submission_notes"
                            rows="3"
                            maxlength="2000"
                            placeholder="Describe the completed work or add a shared document link"
                        ><?= h(
                            (string)(
                                $row['submission_notes']
                                ?? ''
                            )
                        ) ?></textarea>

                        <label
                            for="submissionFile<?= $assignmentId ?>"
                        >
                            Supporting file
                        </label>

                        <input
                            type="file"
                            id="submissionFile<?= $assignmentId ?>"
                            name="submission_file"
                            accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png"
                        >

                        <div class="vd-file-help">
                            PDF, Word, Excel, PowerPoint, JPG, or PNG.
                            Maximum size: 20 MB.
                        </div>

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            <i class="fas fa-paper-plane"></i>

                            <?= $isSubmitted
                                ? 'Resubmit Deliverable'
                                : 'Submit Deliverable' ?>
                        </button>
                    </form>
                <?php else: ?>
                    <div class="vd-complete-note">
                        <i class="fas fa-circle-check"></i>

                        <div>
                            <strong>
                                <?= $status === 'approved'
                                    ? 'Deliverable approved'
                                    : 'Deliverable completed' ?>
                            </strong>

                            <p>
                                No further submission is required unless the
                                program team reopens this deliverable.
                            </p>
                        </div>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</div>

<style>
:root {
    --vd-primary: #660033;
    --vd-primary-dark: #430022;
    --vd-gold: #d4af37;
    --vd-border: #e5e7eb;
    --vd-muted: #64748b;
    --vd-text: #1f2937;
    --vd-surface: #f8fafc;
}

.vd-page {
    width: 100%;
}

.vd-page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.vd-page-header h1 {
    margin: 0 0 6px;
    color: var(--vd-text);
    font-size: 27px;
    font-weight: 800;
}

.vd-page-header p {
    margin: 0;
    color: var(--vd-muted);
    line-height: 1.6;
}

.vd-notice {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    padding: 12px 14px;
    margin-bottom: 16px;
    border-radius: 9px;
    font-size: 13.5px;
}

.vd-notice.success {
    color: #166534;
    background: #dcfce7;
    border: 1px solid #bbf7d0;
}

.vd-notice.error {
    color: #991b1b;
    background: #fee2e2;
    border: 1px solid #fecaca;
}

.vd-stats {
    display: grid;
    grid-template-columns: repeat(5, minmax(130px, 1fr));
    gap: 12px;
    margin-bottom: 18px;
}

.vd-stat-link {
    min-width: 0;
    display: block;
    color: inherit;
    text-decoration: none !important;
    border-radius: 12px;
}

.vd-stat {
    height: 100%;
    padding: 16px;
    position: relative;
    background: #fff;
    border: 1px solid var(--vd-border);
    border-radius: 12px;
    cursor: pointer;
    transition:
        transform .18s ease,
        box-shadow .18s ease,
        border-color .18s ease,
        background .18s ease;
}

.vd-stat-link:hover .vd-stat,
.vd-stat-link:focus-visible .vd-stat {
    transform: translateY(-2px);
    border-color: #f59e0b;
    background: #fffbeb;
    box-shadow: 0 9px 24px rgba(15, 23, 42, .09);
}

.vd-stat-link:focus-visible {
    outline: 3px solid rgba(245, 158, 11, .18);
    outline-offset: 3px;
}

.vd-stat-link.active .vd-stat {
    border-color: var(--vd-primary);
    background: #fff7fb;
    box-shadow: 0 0 0 2px rgba(102, 0, 51, .08);
}

.vd-stat strong {
    display: block;
    color: var(--vd-primary);
    font-size: 24px;
}

.vd-stat span {
    display: block;
    color: var(--vd-muted);
    font-size: 12px;
}

.vd-stat small {
    display: block;
    margin-top: 5px;
    color: #94a3b8;
    font-size: 10px;
    font-weight: 600;
}

.vd-stat-link.active .vd-stat small {
    color: var(--vd-primary);
}

.vd-filters {
    display: grid;
    grid-template-columns:
        minmax(180px, 1.2fr)
        minmax(170px, 1fr)
        minmax(170px, 1fr)
        auto;
    align-items: center;
    gap: 10px;
    padding: 14px;
    margin-bottom: 18px;
    background: #fff;
    border: 1px solid var(--vd-border);
    border-radius: 12px;
}

.vd-filter-field {
    min-width: 0;
}

.vd-filters select {
    width: 100%;
    min-width: 0;
    min-height: 40px;
    padding: 8px 34px 8px 10px;
    border: 1px solid var(--vd-border);
    border-radius: 8px;
    background: #fff;
    color: var(--vd-text);
    font: inherit;
    font-size: 13px;
}

.vd-filter-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    white-space: nowrap;
}

.vd-filter-actions .btn {
    min-height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding-inline: 14px;
    white-space: nowrap;
}

.vd-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
}

.vd-card {
    min-width: 0;
    height: 100%;
    padding: 16px;
    display: flex;
    flex-direction: column;
    background: #fff;
    border: 1px solid var(--vd-border);
    border-radius: 14px;
    box-shadow: 0 7px 20px rgba(15, 23, 42, 0.04);
}

.vd-card-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}

.vd-card-heading {
    min-width: 0;
}

.vd-card h2 {
    margin: 0 0 8px;
    color: var(--vd-text);
    font-size: 16px;
    line-height: 1.35;
}

.vd-meta {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px 12px;
    color: var(--vd-muted);
    font-size: 12px;
}

.vd-meta span {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.vd-tag,
.vd-due-label {
    display: inline-flex;
    align-items: center;
    padding: 5px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    white-space: nowrap;
}

.status-green {
    background: #dcfce7;
    color: #166534;
}

.status-blue {
    background: #dbeafe;
    color: #1d4ed8;
}

.status-yellow {
    background: #fef9c3;
    color: #854d0e;
}

.status-orange {
    background: #ffedd5;
    color: #9a3412;
}

.status-red {
    background: #fee2e2;
    color: #b91c1c;
}

.status-grey {
    background: #e5e7eb;
    color: #475569;
}

.vd-deadline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px;
    margin: 15px 0;
    background: var(--vd-surface);
    border-radius: 9px;
}

.vd-label {
    display: block;
    margin-bottom: 3px;
    color: var(--vd-muted);
    font-size: 11px;
    text-transform: uppercase;
}

.vd-progress-heading {
    display: flex;
    justify-content: space-between;
    margin-bottom: 6px;
    color: var(--vd-muted);
    font-size: 12px;
}

.vd-progress {
    height: 8px;
    overflow: hidden;
    background: #e5e7eb;
    border-radius: 999px;
}

.vd-progress span {
    display: block;
    height: 100%;
    background: var(--vd-primary);
}

.vd-description {
    margin-top: 14px;
    color: #475569;
    font-size: 13px;
    line-height: 1.65;
}

.vd-information {
    display: flex;
    flex-wrap: wrap;
    gap: 7px 14px;
    margin-top: 12px;
    color: var(--vd-muted);
    font-size: 12px;
}

.vd-actions {
    margin-top: 14px;
}

.vd-submitted-file,
.vd-review-note,
.vd-complete-note {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px;
    margin-top: 14px;
    border-radius: 9px;
    font-size: 13px;
}

.vd-submitted-file {
    background: #f8fafc;
    border: 1px solid var(--vd-border);
}

.vd-submitted-file a {
    display: block;
    margin-top: 3px;
    color: var(--vd-primary);
    overflow-wrap: anywhere;
}

.vd-review-note {
    color: #854d0e;
    background: #fef9c3;
}

.vd-complete-note {
    color: #166534;
    background: #dcfce7;
}

.vd-review-note p,
.vd-complete-note p {
    margin: 4px 0 0;
    line-height: 1.5;
}

.vd-submit {
    padding-top: 14px;
    margin-top: auto;
    border-top: 1px solid #f1f5f9;
}

.vd-submit label {
    display: block;
    margin-bottom: 6px;
    color: #334155;
    font-size: 12px;
    font-weight: 700;
}

.vd-submit textarea,
.vd-submit input[type="file"] {
    display: block;
    width: 100%;
    box-sizing: border-box;
    padding: 9px 10px;
    margin-bottom: 10px;
    border: 1px solid var(--vd-border);
    border-radius: 8px;
    background: #fff;
    font: inherit;
    font-size: 13px;
}

.vd-submit textarea {
    resize: vertical;
}

.vd-file-help {
    margin: -3px 0 11px;
    color: var(--vd-muted);
    font-size: 11.5px;
}

.vd-empty {
    grid-column: 1 / -1;
    padding: 40px 20px;
    text-align: center;
    background: #fff;
    border: 1px dashed #cbd5e1;
    border-radius: 13px;
}

.vd-empty-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 58px;
    height: 58px;
    margin-bottom: 12px;
    color: var(--vd-primary);
    background: #fce7f3;
    border-radius: 50%;
    font-size: 23px;
}

.vd-empty h3 {
    margin: 0 0 6px;
}

.vd-empty p {
    margin: 0;
    color: var(--vd-muted);
}

@media (max-width: 1200px) {
    .vd-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .vd-filters {
        grid-template-columns:
            minmax(160px, 1fr)
            minmax(150px, 1fr)
            minmax(150px, 1fr)
            auto;
    }
}

@media (max-width: 900px) {
    .vd-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .vd-filters {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .vd-filter-actions {
        justify-content: flex-start;
        grid-column: 1 / -1;
    }
}

@media (max-width: 700px) {
    .vd-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 600px) {
    .vd-stats {
        grid-template-columns: 1fr;
    }

    .vd-filters {
        grid-template-columns: 1fr;
        align-items: stretch;
    }

    .vd-filter-actions {
        grid-column: auto;
        width: 100%;
    }

    .vd-filter-actions .btn {
        flex: 1 1 0;
        width: auto;
    }

    .vd-card-top,
    .vd-deadline {
        flex-direction: column;
    }

    .vd-tag {
        align-self: flex-start;
    }
}

/* Font Awesome compatibility */
.vd-page .fas,
.vd-page .far,
.vd-page .fab {
    display: inline-block;
    width: auto;
    line-height: 1;
    vertical-align: -0.125em;
}

.vd-page .btn .fas,
.vd-page .btn .far,
.vd-page .vd-meta .fas,
.vd-page .vd-information .fas,
.vd-page .vd-information .far,
.vd-page .vd-notice .fas {
    flex: 0 0 auto;
}

</style>
