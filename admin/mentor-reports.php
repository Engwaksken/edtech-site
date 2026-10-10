<?php

require_once '../includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/session-junctions.php';
require_once 'includes/report-schemas.php';
require_once 'includes/report-engine.php';

if (!isset($conn) || !($conn instanceof mysqli)) die('DB error');
$conn->set_charset('utf8mb4');

ms_auth_gate();
$mrDeleteToken = $_SESSION['mr_delete_token'] ?? '';
if (!is_string($mrDeleteToken) || strlen($mrDeleteToken) < 32) {
    $mrDeleteToken = bin2hex(random_bytes(32));
    $_SESSION['mr_delete_token'] = $mrDeleteToken;
}
$IS_MENTOR        = ms_is_mentor();
$CAN_MANAGE       = ms_can_manage();
$MENTOR_RECORD_ID = ms_mentor_record_id($conn);


$CAN_VIEW_REPORTS = $CAN_MANAGE || rs_is_stakeholder_role();


$CAN_REVIEW = rs_can_review();

$CURRENT_ROLE = function_exists('ms_current_role') ? ms_current_role() : '';
$PDF_UPLOAD_ROLES = [
    'admin', 'administrator', 'super-admin', 'superadmin', 'sup-admin', 'supper-admin', 'supperadmin',
    'programs-lead', 'program-lead', 'programs-director', 'program-director',
    'programs-manager', 'program-manager', 'consultant'
];
$CAN_UPLOAD_PDF = in_array($CURRENT_ROLE, $PDF_UPLOAD_ROLES, true);
$CAN_MANAGE = $CAN_MANAGE || $CAN_UPLOAD_PDF;
$CAN_VIEW_REPORTS = $CAN_MANAGE || rs_is_stakeholder_role();

if ($IS_MENTOR && !$CAN_MANAGE && $MENTOR_RECORD_ID <= 0) {
    include 'includes/sidebar.php'; ?>
    <div class="admin-main"><div class="admin-content legacy-style-5534667570">
    <h2>Account not linked</h2>
    <p>Your mentor account is not yet linked to a mentor profile. Please contact the programme administrator.</p>
    </div></div><?php exit;
}
if (!$IS_MENTOR && !$CAN_VIEW_REPORTS) { http_response_code(403); die('Access denied.'); }

$site_name = function_exists('get_setting') ? get_setting($conn, 'site_name', 'EdTech Fellowship') : 'EdTech Fellowship';


$all_ventures_list = [];
$res = $conn->query("SELECT id, name, cohort_id FROM ventures ORDER BY name");
while ($r = $res->fetch_assoc()) $all_ventures_list[] = $r;

$all_cohorts_list = [];
$res = $conn->query("SHOW TABLES LIKE 'cohorts'");
if ($res->num_rows) {
    $res = $conn->query("SELECT id, name FROM cohorts ORDER BY sort_order, name");
    while ($r = $res->fetch_assoc()) $all_cohorts_list[] = $r;
}

$all_mentors_list = [];
if ($CAN_MANAGE) {
    $res = $conn->query("SELECT id, full_name FROM mentors WHERE status='active' ORDER BY full_name");
    while ($r = $res->fetch_assoc()) $all_mentors_list[] = $r;
}


function mr_venture_name(
    mysqli $conn,
    int $ventureId
): string {
    if ($ventureId <= 0) {
        return '';
    }

    $stmt = $conn->prepare("
        SELECT name
        FROM ventures
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return '';
    }

    $stmt->bind_param(
        'i',
        $ventureId
    );

    $stmt->execute();

    $row = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    return trim(
        (string)(
            $row['name']
            ?? ''
        )
    );
}


function mr_page_allowed_ventures(mysqli $conn, bool $canManage, int $mentorId, array $allVentures): array
{
    if ($canManage) return $allVentures;

    $ids = [];
    $st = $conn->prepare("
        SELECT DISTINCT v.id
        FROM ventures v
        WHERE EXISTS (
            SELECT 1 FROM mentor_sessions ms
            LEFT JOIN session_mentors sm ON sm.session_id = ms.id
            LEFT JOIN session_ventures sv ON sv.session_id = ms.id
            WHERE (sm.mentor_id = ? OR ms.mentor_id = ?)
              AND (sv.venture_id = v.id OR ms.venture_id = v.id)
        )
    ");
    if ($st) {
        $st->bind_param('ii', $mentorId, $mentorId);
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) $ids[(int)$r['id']] = true;
        $st->close();
    }
    return array_values(array_filter($allVentures, fn($v) => isset($ids[(int)$v['id']])));
}
$my_ventures = mr_page_allowed_ventures($conn, $CAN_VIEW_REPORTS, $MENTOR_RECORD_ID, $all_ventures_list);


$my_sessions = [];
if ($IS_MENTOR || $CAN_VIEW_REPORTS) {
    $sql = "
        SELECT ms.id, ms.title, ms.scheduled_at
        FROM mentor_sessions ms
        LEFT JOIN session_mentors sm ON sm.session_id = ms.id
        WHERE ms.status = 'completed'
    ";
    if (!$CAN_VIEW_REPORTS) {
        $sql .= " AND (sm.mentor_id = ? OR ms.mentor_id = ?)";
    }
    $sql .= " GROUP BY ms.id ORDER BY ms.scheduled_at DESC LIMIT 200";
    $st = $conn->prepare($sql);
    if ($st) {
        if (!$CAN_VIEW_REPORTS) $st->bind_param('ii', $MENTOR_RECORD_ID, $MENTOR_RECORD_ID);
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) $my_sessions[] = $r;
        $st->close();
    }
}

$report_types = rs_report_types();
$tab = trim((string)($_GET['tab'] ?? 'session_report'));
if (!isset($report_types[$tab]) && $tab !== 'documents') $tab = 'session_report';

/* ============================================================
   CONTINUE STRUCTURED DRAFT
============================================================ */
function mr_structured_payload_column(mysqli $conn): string
{
    $res = $conn->query(
        "SHOW COLUMNS FROM structured_reports"
    );

    if (!$res) {
        return '';
    }

    $cols = [];

    while ($row = $res->fetch_assoc()) {
        $cols[
            (string)$row['Field']
        ] = strtolower(
            (string)(
                $row['Type']
                ?? ''
            )
        );
    }

    /*
     * Prefer known report-engine payload names.
     */
    foreach ([
        'data_json',
        'report_data',
        'form_data',
        'answers_json',
        'content_json',
        'payload_json',
        'data'
    ] as $name) {
        if (isset($cols[$name])) {
            return $name;
        }
    }

    /*
     * Fallback for older schemas.
     */
    $ignore = [
        'id',
        'report_type',
        'mentor_id',
        'venture_id',
        'cohort_id',
        'session_id',
        'period_label',
        'cohort_label',
        'title',
        'status',
        'pdf_path',
        'reviewed_by',
        'reviewed_at',
        'submitted_at',
        'created_at',
        'updated_at'
    ];

    foreach ($cols as $name => $type) {
        if (
            in_array(
                $name,
                $ignore,
                true
            )
        ) {
            continue;
        }

        if (
            str_contains($type, 'json')
            || str_contains($type, 'text')
        ) {
            return $name;
        }
    }

    return '';
}


function mr_normalize_draft_payload(
    mixed $raw
): array {
    /*
     * The report engine expects the field values as one flat associative
     * array. Older/newer versions may have wrapped those answers inside
     * data, answers, fields, payload, form_data or report_data.
     */

    if (is_array($raw)) {
        $decoded = $raw;
    } else {
        $text = trim(
            (string)$raw
        );

        if ($text === '') {
            return [];
        }

        $decoded = json_decode(
            $text,
            true
        );

        if (!is_array($decoded)) {
            /*
             * Some database rows contain JSON that was encoded twice.
             */
            $decodedOnce =
                json_decode(
                    $text,
                    true
                );

            if (is_string($decodedOnce)) {
                $decoded =
                    json_decode(
                        $decodedOnce,
                        true
                    );
            }
        }
    }

    if (!is_array($decoded)) {
        return [];
    }

    /*
     * Unwrap common report payload containers.
     */
    for ($depth = 0; $depth < 4; $depth++) {
        $unwrapped = null;

        foreach ([
            'answers',
            'data',
            'fields',
            'values',
            'payload',
            'form_data',
            'report_data',
            'content'
        ] as $wrapper) {
            if (
                isset($decoded[$wrapper])
                && is_array(
                    $decoded[$wrapper]
                )
            ) {
                $unwrapped =
                    $decoded[$wrapper];

                break;
            }
        }

        if (!is_array($unwrapped)) {
            break;
        }

        $decoded = $unwrapped;
    }

    return $decoded;
}

$editingDraftId = max(0, (int)($_GET['edit_draft'] ?? 0));
$editingDraft = null;
$editingDraftData = [];
$draftEditError = '';

if ($editingDraftId > 0 && $tab !== 'documents') {
    $st = $conn->prepare("SELECT * FROM structured_reports WHERE id = ? LIMIT 1");
    if ($st) {
        $st->bind_param('i', $editingDraftId);
        $st->execute();
        $candidate = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$candidate) {
            $draftEditError = 'The draft report could not be found.';
        } elseif (strtolower(trim((string)($candidate['status'] ?? ''))) !== 'draft') {
            $draftEditError = 'Only draft reports can be continued.';
        } elseif (!$CAN_MANAGE && (!$IS_MENTOR || (int)($candidate['mentor_id'] ?? 0) !== $MENTOR_RECORD_ID)) {
            $draftEditError = 'You can only continue your own draft reports.';
        } else {
            $candidateType = trim((string)($candidate['report_type'] ?? ''));
            if (!isset($report_types[$candidateType])) {
                $draftEditError = 'This draft uses an unsupported report type.';
            } else {
                $tab = $candidateType;
                $editingDraft = $candidate;
                $payloadColumn =
                    mr_structured_payload_column(
                        $conn
                    );

                if (
                    $payloadColumn !== ''
                    && array_key_exists(
                        $payloadColumn,
                        $candidate
                    )
                ) {
                    $editingDraftData =
                        mr_normalize_draft_payload(
                            $candidate[
                                $payloadColumn
                            ]
                        );
                }

                /*
                 * If the report engine or an older migration stored answers
                 * directly on the row, merge matching schema field names as
                 * another compatibility fallback.
                 */
                if (!$editingDraftData) {
                    $schemaForDraft =
                        rs_get_schema(
                            $candidateType
                        );

                    $possibleFields = [];

                    $walker = function (
                        array $items
                    ) use (
                        &$walker,
                        &$possibleFields
                    ): void {
                        foreach ($items as $item) {
                            if (!is_array($item)) {
                                continue;
                            }

                            $name = trim(
                                (string)(
                                    $item['name']
                                    ?? $item['key']
                                    ?? $item['field']
                                    ?? ''
                                )
                            );

                            if ($name !== '') {
                                $possibleFields[
                                    $name
                                ] = true;
                            }

                            foreach ([
                                'fields',
                                'items',
                                'children',
                                'questions'
                            ] as $childKey) {
                                if (
                                    isset(
                                        $item[$childKey]
                                    )
                                    && is_array(
                                        $item[$childKey]
                                    )
                                ) {
                                    $walker(
                                        $item[$childKey]
                                    );
                                }
                            }
                        }
                    };

                    if (
                        is_array(
                            $schemaForDraft
                        )
                    ) {
                        $walker(
                            $schemaForDraft
                        );
                    }

                    foreach (
                        array_keys(
                            $possibleFields
                        )
                        as $fieldName
                    ) {
                        if (
                            array_key_exists(
                                $fieldName,
                                $candidate
                            )
                        ) {
                            $editingDraftData[
                                $fieldName
                            ] =
                                $candidate[
                                    $fieldName
                                ];
                        }
                    }
                }

                /*
                 * Make sure the venture saved on this draft is available in
                 * the dropdown even if the mentor's current venture filter no
                 * longer returns it. The saved draft remains authoritative.
                 */
                $draftVentureId = (int)($candidate['venture_id'] ?? 0);

                if ($draftVentureId > 0) {
                    $draftVentureFound = false;

                    foreach ($my_ventures as $ventureRow) {
                        if ((int)($ventureRow['id'] ?? 0) === $draftVentureId) {
                            $draftVentureFound = true;
                            break;
                        }
                    }

                    if (!$draftVentureFound) {
                        $ventureStmt = $conn->prepare("SELECT id, name, cohort_id FROM ventures WHERE id = ? LIMIT 1");

                        if ($ventureStmt) {
                            $ventureStmt->bind_param('i', $draftVentureId);
                            $ventureStmt->execute();
                            $savedVenture = $ventureStmt->get_result()->fetch_assoc();
                            $ventureStmt->close();

                            if ($savedVenture) {
                                $my_ventures[] = $savedVenture;
                            }
                        }
                    }
                }
            }
        }
    }
}


$paginationSizes = [10, 25, 50, 100];
$reportsPerPage = (int)($_GET['per_page'] ?? 10);
if (!in_array($reportsPerPage, $paginationSizes, true)) $reportsPerPage = 10;

function mr_paginate_rows(array $rows, string $pageKey, int $perPage): array
{
    $total = count($rows);
    $pages = max(1, (int)ceil($total / max(1, $perPage)));
    $page = max(1, min($pages, (int)($_GET[$pageKey] ?? 1)));
    $offset = ($page - 1) * $perPage;
    return [array_slice($rows, $offset, $perPage), $total, $page, $pages];
}

function mr_pagination_url(string $pageKey, int $page, string $anchor = ''): string
{
    $query = $_GET;
    $query[$pageKey] = max(1, $page);
    return '?' . http_build_query($query) . ($anchor !== '' ? '#' . rawurlencode($anchor) : '');
}

function mr_render_pagination(string $pageKey, int $page, int $pages, int $total, string $anchor = ''): void
{
    if ($total <= 0) return;
    $start = max(1, $page - 2);
    $end = min($pages, $page + 2);
    ?>
    <nav class="mr-pagination" aria-label="Report pagination">
      <span class="mr-page-summary"><?= number_format($total) ?> record<?= $total === 1 ? '' : 's' ?></span>
      <div class="mr-page-links">
        <a class="mr-page-link <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $page > 1 ? h(mr_pagination_url($pageKey, $page - 1, $anchor)) : '#' ?>" aria-label="Previous page"><i class="fas fa-chevron-left"></i></a>
        <?php if ($start > 1): ?>
          <a class="mr-page-link" href="<?= h(mr_pagination_url($pageKey, 1, $anchor)) ?>">1</a>
          <?php if ($start > 2): ?><span class="mr-page-ellipsis">&hellip;</span><?php endif; ?>
        <?php endif; ?>
        <?php for ($number = $start; $number <= $end; $number++): ?>
          <a class="mr-page-link <?= $number === $page ? 'active' : '' ?>" href="<?= h(mr_pagination_url($pageKey, $number, $anchor)) ?>" <?= $number === $page ? 'aria-current="page"' : '' ?>><?= $number ?></a>
        <?php endfor; ?>
        <?php if ($end < $pages): ?>
          <?php if ($end < $pages - 1): ?><span class="mr-page-ellipsis">&hellip;</span><?php endif; ?>
          <a class="mr-page-link" href="<?= h(mr_pagination_url($pageKey, $pages, $anchor)) ?>"><?= $pages ?></a>
        <?php endif; ?>
        <a class="mr-page-link <?= $page >= $pages ? 'disabled' : '' ?>" href="<?= $page < $pages ? h(mr_pagination_url($pageKey, $page + 1, $anchor)) : '#' ?>" aria-label="Next page"><i class="fas fa-chevron-right"></i></a>
      </div>
    </nav>
    <?php
}

$render_context = ['ventures' => $my_ventures];


$legacy_reports = [];
$legacy_reviews_by_report = [];
$legacy_stats = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'changes_requested' => 0];
if ($tab === 'documents') {
    $where = [];
    $params = []; $types = '';
    if ($IS_MENTOR && !$CAN_VIEW_REPORTS) { $where[] = 'mr.mentor_id = ?'; $params[] = $MENTOR_RECORD_ID; $types .= 'i'; }
    $ws = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $st = $conn->prepare("
        SELECT mr.*, COALESCE(m.full_name, '(Unknown mentor)') AS mentor_name
        FROM mentor_reports mr
        LEFT JOIN mentors m ON m.id = mr.mentor_id
        $ws
        ORDER BY mr.created_at DESC
    ");
    if ($types) $st->bind_param($types, ...$params);
    $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) $legacy_reports[] = $r;
    $st->close();

    [$legacy_reports, $legacyTotal, $legacyPage, $legacyPages] = mr_paginate_rows($legacy_reports, 'documents_page', $reportsPerPage);

    $ids = array_column($legacy_reports, 'id');
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $res = $conn->query("SELECT * FROM mentor_report_reviews WHERE report_id IN ($in) ORDER BY created_at ASC, id ASC");
        while ($r = $res->fetch_assoc()) $legacy_reviews_by_report[(int)$r['report_id']][] = $r;
    }

    $scope = ($IS_MENTOR && !$CAN_VIEW_REPORTS) ? "WHERE mentor_id = $MENTOR_RECORD_ID" : '';
    $row = $conn->query("SELECT SUM(status='pending') p, SUM(status='approved') a, SUM(status='rejected') r, SUM(status='changes_requested') c FROM mentor_reports $scope")->fetch_assoc();
    $legacy_stats = ['pending' => (int)$row['p'], 'approved' => (int)$row['a'], 'rejected' => (int)$row['r'], 'changes_requested' => (int)$row['c']];
}


$structured_list = [];
if (isset($report_types[$tab])) {
    $filters = ['report_type' => $tab];
    if ($IS_MENTOR && !$CAN_VIEW_REPORTS) $filters['mentor_id'] = $MENTOR_RECORD_ID;
    $structured_list = rs_list_reports($conn, $filters);
}
[$structured_list, $structuredTotal, $structuredPage, $structuredPages] = mr_paginate_rows($structured_list, 'structured_page', $reportsPerPage);

function mr_column_exists(mysqli $conn, string $table, string $column): bool
{
    $st = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    if (!$st) return false;
    $st->bind_param('ss', $table, $column);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return (int)($row['c'] ?? 0) > 0;
}

$mentor_reports_has_type = mr_column_exists($conn, 'mentor_reports', 'report_type');
$uploaded_pdf_reports = [];

if (isset($report_types[$tab]) && $mentor_reports_has_type) {
    $where = ["mr.report_type = ?"];
    $params = [$tab];
    $types = 's';

    if ($IS_MENTOR && !$CAN_VIEW_REPORTS) {
        $where[] = 'mr.mentor_id = ?';
        $params[] = $MENTOR_RECORD_ID;
        $types .= 'i';
    }

    $sql = "
        SELECT mr.*, COALESCE(m.full_name, '(Unknown mentor)') AS mentor_name,
               v.name AS venture_name
        FROM mentor_reports mr
        LEFT JOIN mentors m ON m.id = mr.mentor_id
        LEFT JOIN ventures v ON v.id = mr.venture_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY mr.created_at DESC, mr.id DESC
    ";
    $st = $conn->prepare($sql);
    if ($st) {
        $st->bind_param($types, ...$params);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) $uploaded_pdf_reports[] = $row;
        $st->close();
    }
}
[$uploaded_pdf_reports, $uploadedPdfTotal, $uploadedPdfPage, $uploadedPdfPages] = mr_paginate_rows($uploaded_pdf_reports, 'pdf_page', $reportsPerPage);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Mentor Reports - <?= h($site_name) ?> Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="assets/css/admin.css?v=<?= (int)@filemtime(__DIR__ . '/assets/css/admin.css') ?>">
  <link rel="stylesheet" href="assets/css/mentor-reports.css?v=<?= (int)@filemtime(__DIR__ . '/assets/css/mentor-reports.css') ?>">
  <?= rs_engine_css() ?>
  <style>
    .mr-venture-multi { min-height: 150px; padding: 8px; }
    .mr-venture-multi option { padding: 7px 9px; border-radius: 5px; }
    .mr-venture-multi option:checked { background: var(--primary, #2563eb); color: #fff; }
    .mr-venture-multi:disabled { opacity: .55; background: #f1f5f9; }
    .mr-list-controls { display:flex; align-items:center; justify-content:flex-end; gap:8px; margin:0 0 16px; }
    .mr-list-controls label { margin:0; font-size:.78rem; font-weight:700; color:#667085; }
    .mr-list-controls select { width:auto; min-width:78px; height:36px; padding:5px 28px 5px 9px; }
    .mr-pagination { display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; padding:16px 2px 2px; }
    .mr-page-summary { color:#667085; font-size:.78rem; }
    .mr-page-links { display:flex; align-items:center; gap:5px; flex-wrap:wrap; }
    .mr-page-link { min-width:34px; height:34px; padding:0 9px; display:inline-flex; align-items:center; justify-content:center; border:1px solid #d0d5dd; border-radius:8px; background:#fff; color:#344054; font-size:.78rem; font-weight:700; text-decoration:none; transition:.18s ease; }
    .mr-page-link:hover { border-color:var(--primary,#f97316); color:var(--primary,#f97316); transform:translateY(-1px); }
    .mr-page-link.active { border-color:var(--primary,#f97316); background:var(--primary,#f97316); color:#fff; }
    .mr-page-link.disabled { opacity:.45; pointer-events:none; }
    .mr-page-ellipsis { padding:0 3px; color:#98a2b3; }
    .mr-inline-delete { display:inline-flex; margin:0; vertical-align:middle; }
    .mr-locked-scope { opacity:.82; background:#f8fafc !important; cursor:not-allowed; }
    .mr-overview-card { margin-bottom:18px; overflow:hidden; }
    .mr-overview-summary { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 18px; cursor:pointer; list-style:none; }
    .mr-overview-summary::-webkit-details-marker { display:none; }
    .mr-overview-summary h3 { display:flex; align-items:center; gap:9px; margin:0; font-size:1rem; }
    .mr-overview-summary-meta { display:flex; align-items:center; gap:10px; color:#667085; font-size:.78rem; font-weight:700; }
    .mr-overview-summary-meta .fa-chevron-down { transition:transform .18s ease; }
    .mr-overview-card[open] .mr-overview-summary-meta .fa-chevron-down { transform:rotate(180deg); }
    .mr-overview-card[open] .mr-overview-summary { border-bottom:1px solid #eaecf0; }
    .mr-overview-card .stat-ribbon { padding:14px 18px 0; margin-bottom:14px; }
    .mr-table-scroll { max-height:380px; overflow:auto; overscroll-behavior:contain; }
    .mr-table-scroll .rl-table thead th { position:sticky; top:0; z-index:2; background:#f8fafc; }
    .mr-table-scroll .rl-table { min-width:700px; }
    .mr-section-tools { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-bottom:12px; }
    .mr-section-tools .mr-list-controls { margin:0; }

    /*
     * Continue Draft can be much taller than the collapsed New Report card.
     * Remove max-height/overflow clipping while a saved draft is open.
     */
    .form-card.mr-editing-draft,
    .form-card.mr-editing-draft.open {
      display:block !important;
      max-height:none !important;
      height:auto !important;
      overflow:visible !important;
      opacity:1 !important;
      visibility:visible !important;
    }
    .mr-draft-engine-wrap {
      display:block !important;
      width:100%;
      min-height:80px;
      overflow:visible !important;
      clear:both;
    }
    .mr-draft-engine-error {
      margin:14px 0;
      padding:12px 14px;
      border:1px solid #fecaca;
      border-radius:8px;
      background:#fef2f2;
      color:#991b1b;
      font-size:.8rem;
      line-height:1.5;
    }

    @media (max-width:640px) { .mr-pagination { align-items:flex-start; flex-direction:column; } .mr-page-links { width:100%; } }
  </style>
  
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="admin-main" id="adminMain">
<header class="admin-topbar">
  <div class="topbar-left"><div class="topbar-breadcrumb"><a href="index.php">Dashboard</a> &rsaquo; <strong>Mentor Reports</strong></div></div>
  <div class="topbar-right"><div class="admin-avatar"><div class="avatar-circle"><?= strtoupper(substr($ADMIN['full_name'] ?? 'A', 0, 1)) ?></div></div></div>
</header>
<div class="admin-content">
<?php show_flash('mreports'); ?>
<?php if ($draftEditError !== ''): ?><div class="alert alert-error"><i class="fa fa-exclamation-circle"></i> <?= h($draftEditError) ?></div><?php endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Mentor Reports</h1>
    <p class="page-subtitle">Session reports, final startup reports, portfolio reflections & cohort evaluations. </p>
  </div>
</div>

<form method="GET" class="mr-list-controls">
  <input type="hidden" name="tab" value="<?= h($tab) ?>">
  <label for="reportsPerPage">Records per page</label>
  <select name="per_page" id="reportsPerPage" onchange="this.form.submit()">
    <?php foreach ($paginationSizes as $size): ?>
      <option value="<?= $size ?>" <?= $reportsPerPage === $size ? 'selected' : '' ?>><?= $size ?></option>
    <?php endforeach; ?>
  </select>
</form>



<?php if ($CAN_VIEW_REPORTS && !$CAN_MANAGE && !$IS_MENTOR): ?>
<div class="role-banner">
  <i class="fa fa-eye"></i>
  You're viewing as a programme stakeholder: you can see and download every report<?= $CAN_REVIEW ? ', and approve, reject or request changes with a comment' : '' ?>.
</div>
<?php endif; ?>

<?php if ($CAN_VIEW_REPORTS && !$IS_MENTOR):
    $overview_stats = rs_overview_stats($conn);
    $overviewGrandTotal = (int)($overview_stats['pending'] ?? 0)
        + (int)($overview_stats['approved'] ?? 0)
        + (int)($overview_stats['rejected'] ?? 0)
        + (int)($overview_stats['changes_requested'] ?? 0)
        + (int)($overview_stats['draft'] ?? 0);
    $overview_list  = rs_overview_list($conn, 10000);
    [$overview_list, $overviewTotal, $overviewPage, $overviewPages] = mr_paginate_rows($overview_list, 'overview_page', $reportsPerPage);
?>
<details class="card mr-overview-card legacy-style-676666d3dd" id="reports-overview" <?= isset($_GET['overview_page']) ? 'open' : '' ?>>
  <summary class="mr-overview-summary">
    <h3><i class="fa fa-chart-simple legacy-style-c874923b8e"></i> All Reports Overview</h3>
    <span class="mr-overview-summary-meta"><?= number_format($overviewGrandTotal) ?> reports <span aria-hidden="true">·</span> <?= (int)$overview_stats['pending'] ?> pending <i class="fa fa-chevron-down" aria-hidden="true"></i></span>
  </summary>

  <div class="stat-ribbon">
    <div class="stat-rb"><div class="stat-rb-num"><?= number_format($overviewGrandTotal) ?></div><div class="stat-rb-lbl">Total reports</div></div>
    <div class="stat-rb"><div class="stat-rb-num legacy-style-6a6a237e2c"><?= (int)$overview_stats['pending'] ?></div><div class="stat-rb-lbl">Pending review</div></div>
    <div class="stat-rb"><div class="stat-rb-num legacy-style-acd97a46d0"><?= (int)$overview_stats['approved'] ?></div><div class="stat-rb-lbl">Approved</div></div>
    <div class="stat-rb"><div class="stat-rb-num legacy-style-b0eb59c131"><?= (int)$overview_stats['rejected'] ?></div><div class="stat-rb-lbl">Rejected</div></div>
    <div class="stat-rb"><div class="stat-rb-num legacy-style-14a59c6694"><?= (int)$overview_stats['changes_requested'] ?></div><div class="stat-rb-lbl">Changes requested</div></div>
    <div class="stat-rb"><div class="stat-rb-num legacy-style-db12fa5528"><?= (int)$overview_stats['draft'] ?></div><div class="stat-rb-lbl">Drafts</div></div>
  </div>

  <div class="overview-table-wrap">
  <table class="rl-table">
    <thead><tr><th>Type</th><th>Title</th><th>Mentor</th><th>Venture</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
    <tbody>
    <?php if (!$overview_list): ?>
      <tr><td colspan="7" class="legacy-style-21c9146d0b">No reports submitted yet.</td></tr>
    <?php else: foreach ($overview_list as $row):
        $isLegacy    = $row['kind'] === 'legacy_document';
        $typeLabel   = $isLegacy ? 'Document' : ($report_types[$row['kind']]['short'] ?? $row['kind']);
        $previewUrl = $isLegacy
            ? 'includes/process-mentor-reports.php?action=preview_report&id=' . (int)$row['id']
            : 'includes/process-mentor-reports.php?action=preview_structured&id=' . (int)$row['id'];
        $downloadUrl = $isLegacy
            ? 'includes/process-mentor-reports.php?action=download_report&id=' . (int)$row['id']
            : 'includes/process-mentor-reports.php?action=download_structured&id=' . (int)$row['id'];
        $tabLink     = $isLegacy
            ? '?tab=documents&per_page=' . $reportsPerPage
            : '?tab=' . urlencode($row['kind']) . '&per_page=' . $reportsPerPage;
        $statusKey   = $row['status'] === 'submitted' ? 'pending' : $row['status'];
    ?>
      <tr>
        <td><span class="badge badge-info"><?= h($typeLabel) ?></span></td>
        <td><?= h($row['title']) ?></td>
        <td><?= h($row['mentor_name'] ?? '') ?></td>
        <td><?= h($row['venture_name'] ?? '-') ?></td>
        <td><span class="status-pill <?= h($statusKey) ?>"><?= h(str_replace('_', ' ', ucfirst($row['status']))) ?></span></td>
        <td><?= $row['submitted_at'] ? h(date('j M Y', strtotime($row['submitted_at']))) : '-' ?></td>
        <td class="legacy-style-86abdab991">
          <?php if (!empty($row['pdf_path'])): ?>
            <a class="btn btn-sm btn-secondary" href="<?= h($previewUrl) ?>" target="_blank" rel="noopener" title="Preview PDF"><i class="fa fa-eye"></i></a>
            <a class="btn btn-sm btn-secondary" href="<?= h($downloadUrl) ?>" title="Download PDF"><i class="fa fa-download"></i></a>
          <?php endif; ?>
          <a class="btn btn-sm btn-secondary" href="<?= h($tabLink) ?>" title="Open"><i class="fa fa-arrow-right"></i></a>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
  <?php mr_render_pagination('overview_page', $overviewPage, $overviewPages, $overviewTotal, 'reports-overview'); ?>
</details>
<?php endif; ?>

<div class="ms-tabs">
  <?php foreach ($report_types as $slug => $meta): ?>
    <a class="ms-tab <?= $tab === $slug ? 'active' : '' ?>" href="?tab=<?= urlencode($slug) ?>&amp;per_page=<?= $reportsPerPage ?>">
      <i class="fa fa-file-alt"></i> <?= h($meta['short']) ?>
    </a>
  <?php endforeach; ?>
  <a class="ms-tab <?= $tab === 'documents' ? 'active' : '' ?>" href="?tab=documents&amp;per_page=<?= $reportsPerPage ?>"><i class="fa fa-paperclip"></i> Other Documents</a>
</div>

<?php if (isset($report_types[$tab])):
    $meta   = $report_types[$tab];
    $schema = rs_get_schema($tab);
?>

<!--
    Reports list now comes first so submitted work is the first thing
    anyone (mentor, admin, stakeholder) sees on this tab. The "New report"
    form is collapsed behind a toggle button below the list instead of
    always being the first thing on the page.
-->
<div class="card legacy-style-bb1d98a1da" id="structured-reports">
  <h3 class="legacy-style-6e4b76bb76"><i class="fa fa-folder-open"></i> Previously submitted &mdash; <?= h($meta['short']) ?> (<?= number_format($structuredTotal) ?>)</h3>
  <?php if (!$structured_list): ?>
    <p class="legacy-style-90dbc5db20">No reports of this type yet.</p>
  <?php else: ?>
    <div class="mr-table-scroll">
    <table class="rl-table">
      <thead><tr><th>Title</th><th>Mentor</th><th>Venture</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($structured_list as $r):
          $rid = (int)$r['id'];
          $statusKey = $r['status'] === 'submitted' ? 'pending' : $r['status'];
      ?>
        <tr>
          <td><?= h($r['title']) ?></td>
          <td><?= h($r['mentor_name'] ?? '') ?></td>
          <td><?= h($r['venture_name'] ?? '-') ?></td>
          <td>
            <span class="status-pill <?= h($statusKey) ?>"><?= h(str_replace('_', ' ', ucfirst($r['status']))) ?></span>
            <?php if (!empty($r['reviewed_by']) && !empty($r['reviewed_at'])): ?>
              <br><small class="legacy-style-5872de20d5">by <?= h($r['reviewed_by']) ?>, <?= h(date('j M Y', strtotime($r['reviewed_at']))) ?></small>
            <?php endif; ?>
          </td>
          <td><?= $r['submitted_at'] ? h(date('j M Y g:ia', strtotime($r['submitted_at']))) : '-' ?></td>
          <td class="legacy-style-86abdab991">
            <?php if (!empty($r['pdf_path'])): ?>
              <a class="btn btn-sm btn-secondary" href="includes/process-mentor-reports.php?action=preview_structured&id=<?= $rid ?>" target="_blank" rel="noopener"><i class="fa fa-eye"></i></a>
              <a class="btn btn-sm btn-secondary" href="includes/process-mentor-reports.php?action=download_structured&id=<?= $rid ?>" title="Download PDF"><i class="fa fa-download"></i></a>
            <?php else: ?>
              <span class="legacy-style-7491483f4d">no PDF</span>
            <?php endif; ?>
            <?php $canContinueDraft = strtolower(trim((string)($r['status'] ?? ''))) === 'draft' && ($CAN_MANAGE || ($IS_MENTOR && (int)($r['mentor_id'] ?? 0) === $MENTOR_RECORD_ID)); ?>
            <?php if ($canContinueDraft): ?>
              <a class="btn btn-sm btn-secondary" href="?tab=<?= urlencode($tab) ?>&amp;per_page=<?= $reportsPerPage ?>&amp;edit_draft=<?= $rid ?>#rs-form-card-<?= h($tab) ?>" title="Continue draft"><i class="fa fa-pen"></i> Continue Draft</a>

              <form method="POST" action="includes/process-mentor-reports.php" class="mr-inline-delete" onsubmit="return confirm('Delete this draft permanently? This action cannot be undone.');">
                <input type="hidden" name="action" value="delete_structured_draft">
                <input type="hidden" name="report_id" value="<?= $rid ?>">
                <input type="hidden" name="return_tab" value="<?= h($tab) ?>">
                <input type="hidden" name="delete_token" value="<?= h($mrDeleteToken) ?>">
                <button type="submit" class="btn btn-sm btn-danger" title="Delete draft"><i class="fa fa-trash"></i> Delete Draft</button>
              </form>
            <?php endif; ?>
            <?php if ($CAN_REVIEW && $r['status'] === 'submitted'): ?>
              <div class="legacy-style-eb273ae1fc">
                <form method="POST" action="includes/process-mentor-reports.php" class="review-form">
                  <input type="hidden" name="action" value="review_structured">
                  <input type="hidden" name="report_id" value="<?= $rid ?>">
                  <input type="hidden" name="decision" value="approved">
                  <input type="hidden" name="comment_text" class="decision-comment-field" value="">
                  <button type="button" class="btn-approve" onclick="openDecisionModal(this.form,'approved')" title="Approve"><i class="fa fa-check"></i></button>
                </form>
                <form method="POST" action="includes/process-mentor-reports.php" class="review-form">
                  <input type="hidden" name="action" value="review_structured">
                  <input type="hidden" name="report_id" value="<?= $rid ?>">
                  <input type="hidden" name="decision" value="changes_requested">
                  <input type="hidden" name="comment_text" class="decision-comment-field" value="">
                  <button type="button" class="btn-changes" onclick="openDecisionModal(this.form,'changes_requested')" title="Request changes"><i class="fas fa-undo-alt"></i></button>
                </form>
                <form method="POST" action="includes/process-mentor-reports.php" class="review-form">
                  <input type="hidden" name="action" value="review_structured">
                  <input type="hidden" name="report_id" value="<?= $rid ?>">
                  <input type="hidden" name="decision" value="rejected">
                  <input type="hidden" name="comment_text" class="decision-comment-field" value="">
                  <button type="button" class="btn-reject" onclick="openDecisionModal(this.form,'rejected')" title="Reject"><i class="fa fa-times"></i></button>
                </form>
              </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php mr_render_pagination('structured_page', $structuredPage, $structuredPages, $structuredTotal, 'structured-reports'); ?>
  <?php endif; ?>
</div>

<?php if ($CAN_UPLOAD_PDF): ?>
<div class="card mentor-pdf-upload-card" id="uploaded-pdf-reports">
  <div class="card-header-flex">
    <div>
      <h3><i class="fa fa-file-pdf"></i> Uploaded PDF Reports - <?= h($meta['short']) ?></h3>
      <p class="text-muted">Upload a completed PDF report on behalf of a mentor. Each selected venture receives a new independent report; existing reports and files are preserved.</p>
    </div>
    <button type="button" class="btn btn-primary btn-sm" onclick="toggleNewReportForm('pdf-<?= h($tab) ?>')">
      <i class="fa fa-upload"></i> Upload PDF Report
    </button>
  </div>

  <?php if (!$mentor_reports_has_type): ?>
   
  <?php elseif (!$uploaded_pdf_reports): ?>
    <div class="empty-state compact">
      <i class="fa fa-file-pdf"></i>
      <p>No uploaded PDF reports for this category yet.</p>
    </div>
  <?php else: ?>
    <div class="table-responsive mr-table-scroll">
      <table class="rl-table">
        <thead><tr><th>Title</th><th>Mentor</th><th>Venture</th><th>Status</th><th>Uploaded</th><th>File</th></tr></thead>
        <tbody>
        <?php foreach ($uploaded_pdf_reports as $pdfReport): ?>
          <tr>
            <td><strong><?= h($pdfReport['title']) ?></strong></td>
            <td><?= h($pdfReport['mentor_name'] ?? '-') ?></td>
            <td><?= h($pdfReport['venture_name'] ?? '-') ?></td>
            <td><span class="status-pill <?= h($pdfReport['status'] ?? 'pending') ?>"><?= h(ucfirst(str_replace('_', ' ', (string)($pdfReport['status'] ?? 'pending')))) ?></span></td>
            <td><?= !empty($pdfReport['created_at']) ? h(date('j M Y g:ia', strtotime($pdfReport['created_at']))) : '-' ?></td>
            <td>
              <a class="btn btn-sm btn-secondary" href="includes/process-mentor-reports.php?action=preview_report&id=<?= (int)$pdfReport['id'] ?>" target="_blank" rel="noopener"><i class="fa fa-eye"></i></a>
              <a class="btn btn-sm btn-secondary" href="includes/process-mentor-reports.php?action=download_report&id=<?= (int)$pdfReport['id'] ?>" title="Download PDF"><i class="fa fa-download"></i></a>
              <?php if (strtolower((string)($pdfReport['status'] ?? 'pending')) !== 'approved'): ?>
                <form method="POST" action="includes/process-mentor-reports.php" class="mr-inline-delete" onsubmit="return confirm('Delete this uploaded report permanently? This action cannot be undone.');">
                  <input type="hidden" name="action" value="delete_report">
                  <input type="hidden" name="report_id" value="<?= (int)$pdfReport['id'] ?>">
                  <input type="hidden" name="return_tab" value="<?= h($tab) ?>">
                  <input type="hidden" name="delete_token" value="<?= h($mrDeleteToken) ?>">
                  <button type="submit" class="btn btn-sm btn-danger" title="Delete report"><i class="fa fa-trash"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php mr_render_pagination('pdf_page', $uploadedPdfPage, $uploadedPdfPages, $uploadedPdfTotal, 'uploaded-pdf-reports'); ?>
  <?php endif; ?>
</div>

<div class="form-card" id="rs-form-card-pdf-<?= h($tab) ?>">
  <div class="form-card-header"><h2><i class="fa fa-file-pdf"></i> Upload <?= h($meta['label']) ?> PDF</h2></div>
  <form method="POST" action="includes/process-mentor-reports.php" enctype="multipart/form-data">
    <input type="hidden" name="action" value="upload_pdf_report">
    <input type="hidden" name="report_type" value="<?= h($tab) ?>">
    <div class="form-grid form-grid-2">
      <div class="form-group full"><label>Report title <span class="req">*</span></label><input type="text" name="title" class="form-control" value="<?= h($meta['label']) ?>" required></div>
      <div class="form-group"><label>Mentor <span class="req">*</span></label><select name="mentor_id" class="form-control" required><option value="">Select mentor</option><?php foreach ($all_mentors_list as $m): ?><option value="<?= (int)$m['id'] ?>"><?= h($m['full_name']) ?></option><?php endforeach; ?></select></div>
      <?php if ($meta['scope'] === 'venture' || $meta['scope'] === 'session'): ?>
      <div class="form-group">
        <label>Ventures<?= $meta['scope'] === 'venture' ? ' <span class="req">*</span>' : '' ?></label>
        <select name="venture_ids[]" class="form-control mr-venture-multi" multiple size="7" <?= $meta['scope'] === 'venture' ? 'required' : '' ?>>
          <?php foreach ($all_ventures_list as $v): ?><option value="<?= (int)$v['id'] ?>" <?= $editingDraft && (int)$v['id'] === (int)($editingDraft['venture_id'] ?? 0) ? 'selected' : '' ?>><?= h($v['name']) ?></option><?php endforeach; ?>
        </select>
        <label class="rs-scope-pill"><input type="checkbox" class="mr-all-ventures" data-target-scope="pdf-target-scope-<?= h($tab) ?>"> <i class="fa fa-layer-group"></i> Apply to all ventures</label>
        <input type="hidden" name="target_scope" id="pdf-target-scope-<?= h($tab) ?>" value="selected">
        <small class="form-help">Hold Ctrl (Windows) or Command (Mac) to select several ventures.</small>
      </div>
      <?php endif; ?>
      <?php if ($meta['scope'] === 'session'): ?>
      <div class="form-group"><label>Completed session</label><select name="session_id" class="form-control"><option value="">Not linked to a session</option><?php foreach ($my_sessions as $session): ?><option value="<?= (int)$session['id'] ?>"><?= h($session['title']) ?><?= !empty($session['scheduled_at']) ? ' - ' . h(date('j M Y', strtotime($session['scheduled_at']))) : '' ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <div class="form-group"><label>PDF file <span class="req">*</span></label><input type="file" name="report_file" class="form-control" accept="application/pdf,.pdf" required><small class="form-help">PDF only, maximum 20 MB. Uploading creates new records and never replaces existing files.</small></div>
      <div class="form-group full"><label>Notes</label><textarea name="description" class="form-control" rows="3"></textarea></div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary"><i class="fa fa-upload"></i> Upload PDF Report</button><button type="button" class="btn btn-secondary" onclick="toggleNewReportForm('pdf-<?= h($tab) ?>')">Cancel</button></div>
  </form>
</div>
<?php endif; ?>

<?php if ($IS_MENTOR || $CAN_MANAGE): ?>
<div class="new-report-toggle-row">
  <button type="button" class="btn btn-primary btn-sm" onclick="toggleNewReportForm('<?= h($tab) ?>')">
    <i class="fa fa-plus-circle"></i> New <?= h($meta['label']) ?>
  </button>
</div>

<div class="form-card <?= $editingDraft ? 'open mr-editing-draft' : '' ?>" id="rs-form-card-<?= h($tab) ?>">
  <div class="form-card-header">
    <h2><i class="fa <?= $editingDraft ? 'fa-pen' : 'fa-plus-circle' ?> legacy-style-c874923b8e"></i> <?= $editingDraft ? 'Continue Draft - ' : 'New ' ?><?= h($meta['label']) ?></h2>
  </div>

  <form method="POST" action="includes/process-mentor-reports.php" id="rs-form-<?= h($tab) ?>">
    <input type="hidden" name="action" value="save_structured">
    <input type="hidden" name="report_type" value="<?= h($tab) ?>">
    <input type="hidden" name="draft_id" value="<?= $editingDraft ? (int)$editingDraft['id'] : 0 ?>">

    <div class="report-scope-toggle">
      <?php if ($CAN_MANAGE): ?>
        <label class="legacy-style-f18eb62830">Mentor:</label>
        <select name="mentor_id" class="rs-input legacy-style-0fac9183d5">
          <?php foreach ($all_mentors_list as $m): ?>
            <option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === (int)($editingDraft['mentor_id'] ?? $MENTOR_RECORD_ID) ? 'selected' : '' ?>><?= h($m['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>

      <?php if ($meta['scope'] === 'venture'): ?>
        <label class="legacy-style-f18eb62830">Ventures:</label>
        <select name="venture_ids[]" class="rs-input mr-venture-multi" id="rs-venture-select-<?= h($tab) ?>" multiple size="6" required style="width:280px">
          <?php foreach ($my_ventures as $v): ?>
            <option value="<?= (int)$v['id'] ?>" <?= $editingDraft && (int)$v['id'] === (int)($editingDraft['venture_id'] ?? 0) ? 'selected' : '' ?>><?= h($v['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <label class="rs-scope-pill">
          <input type="checkbox" class="mr-all-ventures" data-target-scope="rs-target-scope-<?= h($tab) ?>">
          <i class="fa fa-layer-group"></i> Generate for all ventures
        </label>
        <input type="hidden" name="target_scope" id="rs-target-scope-<?= h($tab) ?>" value="selected">
      <?php elseif ($meta['scope'] === 'session'): ?>
        <label class="legacy-style-f18eb62830">Session:</label>
        <?php if ($editingDraft): ?>
          <input type="hidden" name="session_id" value="<?= (int)($editingDraft['session_id'] ?? 0) ?>">
        <?php endif; ?>
        <select
          <?= $editingDraft ? 'disabled' : 'name="session_id"' ?>
          class="rs-input legacy-style-a20ea0ded5 <?= $editingDraft ? 'mr-locked-scope' : '' ?>"
        >
          <option value="">(Ad-hoc - not tied to a scheduled session)</option>
          <?php foreach ($my_sessions as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $editingDraft && (int)$s['id'] === (int)($editingDraft['session_id'] ?? 0) ? 'selected' : '' ?>><?= h($s['title']) ?> &mdash; <?= !empty($s['scheduled_at']) ? h(date('j M Y', strtotime($s['scheduled_at']))) : 'TBD' ?></option>
          <?php endforeach; ?>
        </select>
        <label class="legacy-style-f18eb62830">Venture:</label>
        <?php if ($editingDraft): ?>
          <input type="hidden" name="venture_id" value="<?= (int)($editingDraft['venture_id'] ?? 0) ?>">
        <?php endif; ?>
        <select
          <?= $editingDraft ? 'disabled' : 'name="venture_id"' ?>
          class="rs-input legacy-style-0fac9183d5 <?= $editingDraft ? 'mr-locked-scope' : '' ?>"
        >
          <option value="">(Use session's venture)</option>
          <?php foreach ($my_ventures as $v): ?>
            <option
              value="<?= (int)$v['id'] ?>"
              <?= $editingDraft && (int)$v['id'] === (int)($editingDraft['venture_id'] ?? 0) ? 'selected' : '' ?>
            >
              <?= h($v['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <small class="form-help">
          <?php if ($editingDraft && (int)($editingDraft['venture_id'] ?? 0) > 0): ?>
            Saved draft venture:
            <strong>
              <?= h(
                  mr_venture_name(
                      $conn,
                      (int)($editingDraft['venture_id'] ?? 0)
                  )
                  ?: ('Venture #' . (int)$editingDraft['venture_id'])
              ) ?>
            </strong>.
          <?php else: ?>
            You can save this report as a draft using the selected venture even when no completed session is selected.
          <?php endif; ?>
        </small>
      <?php endif; ?>
    </div>

    <div class="mr-draft-engine-wrap">
      <?php
      /*
       * Render the report questions separately so a bad historical draft
       * payload can never terminate the page before Save/Submit controls.
       */
      try {
          echo rs_render_form(
              $schema,
              $editingDraftData,
              $render_context
          );
      } catch (Throwable $renderError) {
          error_log(
              'Mentor report draft render error: '
              . $renderError->getMessage()
          );

          if ($editingDraftData) {
              /*
               * The saved values may be from an older schema. Render the
               * current form empty rather than hiding the entire lower form.
               */
              try {
                  echo rs_render_form(
                      $schema,
                      [],
                      $render_context
                  );
              } catch (Throwable $fallbackError) {
                  error_log(
                      'Mentor report fallback render error: '
                      . $fallbackError->getMessage()
                  );
                  ?>
                  <div class="mr-draft-engine-error">
                    <i class="fa fa-exclamation-circle"></i>
                    The report questions could not be loaded.
                    Please refresh the page. If this continues, check
                    <code>includes/report-engine.php</code> and
                    <code>includes/report-schemas.php</code>.
                  </div>
                  <?php
              }
          } else {
              try {
                  echo rs_render_form(
                      $schema,
                      [],
                      $render_context
                  );
              } catch (Throwable $fallbackError) {
                  error_log(
                      'Mentor report empty-form render error: '
                      . $fallbackError->getMessage()
                  );
                  ?>
                  <div class="mr-draft-engine-error">
                    <i class="fa fa-exclamation-circle"></i>
                    The report questions could not be loaded.
                  </div>
                  <?php
              }
          }
      }
      ?>
    </div>

    <div class="legacy-style-c12dff4d2f">
      <button type="submit" name="submit_mode" value="submit" class="btn btn-primary"><i class="fa fa-paper-plane"></i> <?= $editingDraft ? 'Submit &amp; Notify' : 'Submit &amp; Notify' ?></button>
      <button type="submit" name="submit_mode" value="draft" class="btn btn-secondary"><i class="fa fa-save"></i> <?= $editingDraft ? 'Save Draft &amp; Continue' : 'Save as Draft' ?></button>
      <button type="button" class="btn btn-secondary" onclick="toggleNewReportForm('<?= h($tab) ?>')">Cancel</button>
    </div>
  </form>
</div>
<?php endif; ?>

<?php else:  ?>

<div class="stat-ribbon">
  <div class="stat-rb"><div class="stat-rb-num"><?= number_format((int)$legacyTotal) ?></div><div class="stat-rb-lbl">Total reports</div></div>
  <div class="stat-rb"><div class="stat-rb-num legacy-style-6a6a237e2c"><?= $legacy_stats['pending'] ?></div><div class="stat-rb-lbl">Pending</div></div>
  <div class="stat-rb"><div class="stat-rb-num legacy-style-acd97a46d0"><?= $legacy_stats['approved'] ?></div><div class="stat-rb-lbl">Approved</div></div>
  <div class="stat-rb"><div class="stat-rb-num legacy-style-b0eb59c131"><?= $legacy_stats['rejected'] ?></div><div class="stat-rb-lbl">Rejected</div></div>
  <div class="stat-rb"><div class="stat-rb-num legacy-style-14a59c6694"><?= $legacy_stats['changes_requested'] ?></div><div class="stat-rb-lbl">Changes requested</div></div>
</div>

<!-- Uploaded documents list now comes first, upload form is collapsed below it -->
<div class="reports-grid" id="other-documents">
<?php if (!$legacy_reports): ?>
  <div class="legacy-style-34c64b4e4b">No documents uploaded yet.</div>
<?php else: foreach ($legacy_reports as $rep):
    $rid = (int)$rep['id'];
    $thread = $legacy_reviews_by_report[$rid] ?? [];
    $can_resubmit = $IS_MENTOR && !$CAN_MANAGE && (int)$rep['mentor_id'] === $MENTOR_RECORD_ID && in_array($rep['status'], ['rejected', 'changes_requested'], true);
?>
  <div class="report-card">
    <div class="report-title"><?= h($rep['title']) ?></div>
    <div class="report-meta">
      <?= strtoupper(h($rep['file_ext'])) ?> &middot; Uploaded <?= date('M j, Y', strtotime($rep['created_at'])) ?>
      <?php if ($CAN_VIEW_REPORTS): ?> &middot; <?= h($rep['mentor_name']) ?><?php endif; ?>
    </div>
    <span class="status-pill <?= h($rep['status']) ?>"><?= h(str_replace('_', ' ', ucfirst($rep['status']))) ?></span>
    <?php if (!empty($rep['reviewed_by']) && !empty($rep['reviewed_at'])): ?>
      <small class="legacy-style-46da3d6224">by <?= h($rep['reviewed_by']) ?>, <?= h(date('j M Y', strtotime($rep['reviewed_at']))) ?></small>
    <?php endif; ?>
    <?php if (strtolower((string)$rep['file_ext']) === 'pdf'): ?>
      <a href="includes/process-mentor-reports.php?action=preview_report&id=<?= $rid ?>" target="_blank" rel="noopener" class="report-file-pill"><i class="fa fa-eye"></i> Preview <?= h($rep['file_name']) ?></a>
      <a href="includes/process-mentor-reports.php?action=download_report&id=<?= $rid ?>" class="btn btn-sm btn-secondary" title="Download PDF"><i class="fa fa-download"></i></a>
    <?php else: ?>
      <a href="includes/process-mentor-reports.php?action=download_report&id=<?= $rid ?>" class="report-file-pill"><i class="fa fa-file-download"></i> <?= h($rep['file_name']) ?></a>
    <?php endif; ?>

    <?php
      $can_delete_report = strtolower((string)$rep['status']) !== 'approved'
          && ($CAN_MANAGE || ($IS_MENTOR && (int)$rep['mentor_id'] === $MENTOR_RECORD_ID));
    ?>
    <?php if ($can_delete_report): ?>
      <form method="POST" action="includes/process-mentor-reports.php" class="mr-inline-delete" onsubmit="return confirm('Delete this uploaded report permanently? This action cannot be undone.');">
        <input type="hidden" name="action" value="delete_report">
        <input type="hidden" name="report_id" value="<?= $rid ?>">
        <input type="hidden" name="return_tab" value="documents">
        <input type="hidden" name="delete_token" value="<?= h($mrDeleteToken) ?>">
        <button type="submit" class="btn btn-sm btn-danger"><i class="fa fa-trash"></i> Delete</button>
      </form>
    <?php endif; ?>

    <?php if ($can_resubmit): ?>
      <form method="POST" action="includes/process-mentor-reports.php" enctype="multipart/form-data" class="legacy-style-27fd7d41f4">
        <input type="hidden" name="action" value="resubmit">
        <input type="hidden" name="report_id" value="<?= $rid ?>">
        <input type="file" name="report_file" class="form-control" required>
        <button class="btn btn-primary btn-sm"><i class="fa fa-upload"></i></button>
      </form>
    <?php endif; ?>

    <?php if ($CAN_REVIEW && $rep['status'] === 'pending'): ?>
      <div class="legacy-style-def5a445a2">
        <form method="POST" action="includes/process-mentor-reports.php" class="review-form">
          <input type="hidden" name="action" value="review">
          <input type="hidden" name="report_id" value="<?= $rid ?>">
          <input type="hidden" name="decision" value="approved">
          <input type="hidden" name="comment_text" class="decision-comment-field" value="">
          <button type="button" class="btn-approve" onclick="openDecisionModal(this.form,'approved')"><i class="fa fa-check"></i> Approve</button>
        </form>
        <form method="POST" action="includes/process-mentor-reports.php" class="review-form">
          <input type="hidden" name="action" value="review">
          <input type="hidden" name="report_id" value="<?= $rid ?>">
          <input type="hidden" name="decision" value="changes_requested">
          <input type="hidden" name="comment_text" class="decision-comment-field" value="">
          <button type="button" class="btn-changes" onclick="openDecisionModal(this.form,'changes_requested')"><i class="fas fa-undo-alt"></i> Request changes</button>
        </form>
        <form method="POST" action="includes/process-mentor-reports.php" class="review-form">
          <input type="hidden" name="action" value="review">
          <input type="hidden" name="report_id" value="<?= $rid ?>">
          <input type="hidden" name="decision" value="rejected">
          <input type="hidden" name="comment_text" class="decision-comment-field" value="">
          <button type="button" class="btn-reject" onclick="openDecisionModal(this.form,'rejected')"><i class="fa fa-times"></i> Reject</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
<?php endforeach; endif; ?>
</div>
<?php if (!empty($legacyTotal)): ?>
  <?php mr_render_pagination('documents_page', $legacyPage, $legacyPages, $legacyTotal, 'other-documents'); ?>
<?php endif; ?>

<?php if ($IS_MENTOR || $CAN_UPLOAD_PDF): ?>
<div class="new-report-toggle-row">
  <button type="button" class="btn btn-primary btn-sm" onclick="toggleNewReportForm('documents')">
    <i class="fa fa-upload"></i> Upload a document
  </button>
</div>

<div class="form-card" id="rs-form-card-documents">
  <h2 class="legacy-style-7972f11942"><i class="fa fa-upload legacy-style-c874923b8e"></i> Upload one or more other documents</h2>
  <form method="POST" action="includes/process-mentor-reports.php" enctype="multipart/form-data">
    <input type="hidden" name="action" value="upload">
    <div class="form-grid form-grid-2">
      <?php if ($CAN_UPLOAD_PDF): ?>
      <div class="form-group"><label>Mentor <span class="req">*</span></label><select name="mentor_id" class="form-control" required><option value="">Select mentor</option><?php foreach ($all_mentors_list as $m): ?><option value="<?= (int)$m['id'] ?>"><?= h($m['full_name']) ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <div class="form-group <?= $CAN_UPLOAD_PDF ? '' : 'full' ?>"><label>Document title</label><input type="text" name="title" class="form-control" placeholder="Optional. File name is used when left blank."></div>
      <div class="form-group full"><label>Files <span class="req">*</span></label><input type="file" name="report_files[]" class="form-control" multiple required><small class="form-help">Select one or several files. Maximum 20 MB per file.</small></div>
      <div class="form-group full"><label>Notes</label><textarea name="description" class="form-control" rows="2"></textarea></div>
    </div>
    <div class="legacy-style-c12dff4d2f">
      <button type="submit" class="btn btn-primary"><i class="fa fa-paper-plane"></i> Submit</button>
      <button type="button" class="btn btn-secondary" onclick="toggleNewReportForm('documents')">Cancel</button>
    </div>
  </form>
</div>
<?php endif; ?>

<?php endif; ?>

</div></div>


<div class="ms-modal-overlay" id="decisionModalOverlay">
  <div class="ms-modal" role="dialog" aria-modal="true" aria-labelledby="decisionModalTitle">
    <div class="ms-modal-icon" id="decisionModalIcon"><i class="fa fa-check"></i></div>
    <h4 id="decisionModalTitle">Approve report</h4>
    <p class="ms-modal-sub" id="decisionModalSub">Add an optional note for the mentor.</p>
    <textarea id="decisionModalTextarea" placeholder="Optional note..."></textarea>
    <div class="ms-modal-actions">
      <button type="button" class="ms-modal-cancel" onclick="closeDecisionModal()">Cancel</button>
      <button type="button" class="ms-modal-confirm" id="decisionModalConfirm" onclick="confirmDecisionModal()">Confirm</button>
    </div>
  </div>
</div>

<?= rs_engine_js() ?>
<script>
<?php if ($editingDraft): ?>
document.addEventListener('DOMContentLoaded', () => {
    const card = document.getElementById('rs-form-card-<?= h($tab) ?>');
    if (card) {
        card.classList.add('open');
        setTimeout(() => card.scrollIntoView({behavior:'smooth', block:'start'}), 80);
    }
});
<?php endif; ?>

document.getElementById('sidebarToggle')?.addEventListener('click', () => {
    document.getElementById('adminSidebar')?.classList.toggle('collapsed');
    document.getElementById('adminMain')?.classList.toggle('collapsed');
});

document.querySelectorAll('.mr-all-ventures').forEach((checkbox) => {
    checkbox.addEventListener('change', () => {
        const form = checkbox.closest('form');
        const select = form?.querySelector('.mr-venture-multi');
        const scope = document.getElementById(checkbox.dataset.targetScope || '');
        if (!select || !scope) return;

        scope.value = checkbox.checked ? 'all_ventures' : 'selected';
        select.disabled = checkbox.checked;
        select.required = !checkbox.checked;
        if (checkbox.checked) {
            Array.from(select.options).forEach((option) => { option.selected = false; });
        }
    });
});

function toggleNewReportForm(tab) {
    const card = document.getElementById('rs-form-card-' + tab);
    if (!card) return;
    const opening = !card.classList.contains('open');
    card.classList.toggle('open');
    if (opening) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

const decisionModal = { form: null, decision: null };

const decisionModalConfig = {
    approved: {
        title: 'Approve report',
        sub: 'Add an optional note for the mentor.',
        placeholder: 'e.g. Great work this period - thanks!',
        icon: 'fa-check',
        confirmLabel: 'Approve'
    },
    rejected: {
        title: 'Reject report',
        sub: 'Let the mentor know why this report is being rejected (optional).',
        placeholder: 'e.g. Wrong template used - please resubmit with the correct one.',
        icon: 'fa-times',
        confirmLabel: 'Reject'
    },
    changes_requested: {
        title: 'Request changes',
        sub: 'Describe what needs to change before this can be approved (optional).',
        placeholder: 'e.g. Please add the attendance figures for week 3.',
        icon: 'fa-undo-alt',
        confirmLabel: 'Request changes'
    }
};

function openDecisionModal(form, decision) {
    decisionModal.form = form;
    decisionModal.decision = decision;

    const cfg = decisionModalConfig[decision];
    const overlay   = document.getElementById('decisionModalOverlay');
    const icon      = document.getElementById('decisionModalIcon');
    const title     = document.getElementById('decisionModalTitle');
    const sub       = document.getElementById('decisionModalSub');
    const textarea  = document.getElementById('decisionModalTextarea');
    const confirmBt = document.getElementById('decisionModalConfirm');

    icon.className = 'ms-modal-icon ' + decision;
    icon.innerHTML = '<i class="fa ' + cfg.icon + '"></i>';
    title.textContent = cfg.title;
    sub.textContent = cfg.sub;
    textarea.value = '';
    textarea.placeholder = cfg.placeholder;
    confirmBt.className = 'ms-modal-confirm ' + decision;
    confirmBt.textContent = cfg.confirmLabel;

    overlay.classList.add('open');
    setTimeout(() => textarea.focus(), 50);
}

function closeDecisionModal() {
    document.getElementById('decisionModalOverlay').classList.remove('open');
    decisionModal.form = null;
    decisionModal.decision = null;
}

function confirmDecisionModal() {
    if (!decisionModal.form) return;
    const textarea = document.getElementById('decisionModalTextarea');
    const field = decisionModal.form.querySelector('.decision-comment-field');
    if (field) field.value = textarea.value;
    const form = decisionModal.form;
    closeDecisionModal();
    form.submit();
}

document.getElementById('decisionModalOverlay')?.addEventListener('click', (e) => {
    if (e.target.id === 'decisionModalOverlay') closeDecisionModal();
});
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeDecisionModal();
});
</script>
</body>
</html>
