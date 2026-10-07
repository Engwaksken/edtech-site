<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection error.');
}
$conn->set_charset('utf8mb4');

if (!isset($_SESSION['meal_report_token']) || strlen((string)$_SESSION['meal_report_token']) < 32) {
    $_SESSION['meal_report_token'] = bin2hex(random_bytes(32));
}
$mealReportToken = (string)$_SESSION['meal_report_token'];

$mealControls = [];

try {
    $mealControlsRes = $conn->query("
        SELECT
            venture_id,
            can_edit,
            can_delete
        FROM meal_venture_controls
    ");

    if ($mealControlsRes instanceof mysqli_result) {
        while ($control = $mealControlsRes->fetch_assoc()) {
            $mealControls[
                (int)$control['venture_id']
            ] = [
                'can_edit' =>
                    (int)$control['can_edit'],
                'can_delete' =>
                    (int)$control['can_delete'],
            ];
        }
    }
} catch (mysqli_sql_exception $e) {
    error_log(
        'meal_venture_controls read error: '
        . $e->getMessage()
    );
}

$tab = $_GET['tab'] ?? 'overview';


$f_venture  = (int)($_GET['venture_id'] ?? 0);
$f_cohort   = (int)($_GET['cohort_id']  ?? 0);
$f_from     = trim($_GET['from'] ?? '');
$f_to       = trim($_GET['to']   ?? '');

$meal_page = max(
    1,
    (int)(
        $_GET['page']
        ?? 1
    )
);

$meal_per_page_options = [10, 20, 30, 50, 100];

$meal_per_page = (int)(
    $_GET['per_page']
    ?? 20
);

if (
    !in_array(
        $meal_per_page,
        $meal_per_page_options,
        true
    )
) {
    $meal_per_page = 20;
}

$meal_offset =
    ($meal_page - 1)
    * $meal_per_page;


function meal_page_url(
    int $pageNumber,
    string $tab,
    int $perPage,
    int $ventureId,
    int $cohortId,
    string $from,
    string $to
): string {
    return '?'
        . http_build_query([
            'tab' => $tab,
            'page' => max(1, $pageNumber),
            'per_page' => $perPage,
            'venture_id' => $ventureId,
            'cohort_id' => $cohortId,
            'from' => $from,
            'to' => $to,
        ]);
}


function meal_pagination_html(
    int $totalRows,
    int $currentPage,
    int $perPage,
    string $tab,
    int $ventureId,
    int $cohortId,
    string $from,
    string $to
): string {
    if ($totalRows <= 0) {
        return '';
    }

    $totalPages = max(
        1,
        (int)ceil(
            $totalRows / $perPage
        )
    );

    $currentPage = min(
        max(1, $currentPage),
        $totalPages
    );

    $start = max(
        1,
        $currentPage - 2
    );

    $end = min(
        $totalPages,
        $currentPage + 2
    );

    ob_start();
    ?>
    <div class="meal-pagination-wrap">

      <div class="meal-pagination-summary">
        Page
        <strong><?= number_format($currentPage) ?></strong>
        of
        <strong><?= number_format($totalPages) ?></strong>
        &middot;
        <?= number_format($totalRows) ?>
        records
      </div>

      <div class="meal-pagination">

        <?php if ($currentPage > 1): ?>
          <a
            href="<?= h(
                meal_page_url(
                    $currentPage - 1,
                    $tab,
                    $perPage,
                    $ventureId,
                    $cohortId,
                    $from,
                    $to
                )
            ) ?>"
            title="Previous page"
          >
            <i class="fa fa-chevron-left"></i>
          </a>
        <?php endif; ?>

        <?php if ($start > 1): ?>
          <a href="<?= h(meal_page_url(1,$tab,$perPage,$ventureId,$cohortId,$from,$to)) ?>">1</a>
          <?php if ($start > 2): ?>
            <span class="meal-page-dots">...</span>
          <?php endif; ?>
        <?php endif; ?>

        <?php for ($p = $start; $p <= $end; $p++): ?>
          <?php if ($p === $currentPage): ?>
            <span class="current"><?= $p ?></span>
          <?php else: ?>
            <a href="<?= h(meal_page_url($p,$tab,$perPage,$ventureId,$cohortId,$from,$to)) ?>">
              <?= $p ?>
            </a>
          <?php endif; ?>
        <?php endfor; ?>

        <?php if ($end < $totalPages): ?>
          <?php if ($end < $totalPages - 1): ?>
            <span class="meal-page-dots">...</span>
          <?php endif; ?>
          <a href="<?= h(meal_page_url($totalPages,$tab,$perPage,$ventureId,$cohortId,$from,$to)) ?>">
            <?= $totalPages ?>
          </a>
        <?php endif; ?>

        <?php if ($currentPage < $totalPages): ?>
          <a
            href="<?= h(
                meal_page_url(
                    $currentPage + 1,
                    $tab,
                    $perPage,
                    $ventureId,
                    $cohortId,
                    $from,
                    $to
                )
            ) ?>"
            title="Next page"
          >
            <i class="fa fa-chevron-right"></i>
          </a>
        <?php endif; ?>

      </div>

    </div>
    <?php

    return (string)ob_get_clean();
}




function meal_table_exists(mysqli $conn, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $cache[$table] = ($res instanceof mysqli_result && $res->num_rows > 0);
}

function meal_column_exists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    if (!meal_table_exists($conn, $table)) {
        return $cache[$key] = false;
    }

    $tableSafe = str_replace('`', '``', $table);
    $columnSafe = $conn->real_escape_string($column);

    $res = $conn->query("SHOW COLUMNS FROM `{$tableSafe}` LIKE '{$columnSafe}'");
    return $cache[$key] = ($res instanceof mysqli_result && $res->num_rows > 0);
}

function meal_optional_select(
    mysqli $conn,
    string $table,
    string $alias,
    string $column,
    string $outputAlias
): string {
    if (meal_column_exists($conn, $table, $column)) {
        return "{$alias}.`{$column}` AS `{$outputAlias}`";
    }

    return "NULL AS `{$outputAlias}`";
}

function meal_ensure_supporting_documents(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS meal_supporting_documents (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            venture_id BIGINT NOT NULL,
            category VARCHAR(40) NOT NULL,
            document_name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            original_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_ext VARCHAR(20) NOT NULL DEFAULT '',
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            uploaded_by VARCHAR(180) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_meal_doc_venture (venture_id),
            INDEX idx_meal_doc_category (category),
            INDEX idx_meal_doc_created (created_at)
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
    ");
}

try {
    meal_ensure_supporting_documents($conn);
} catch (Throwable $e) {
    error_log('Admin M&E supporting documents table error: ' . $e->getMessage());
}

function date_col(string $type): string {
    return match ($type) {
        'participants' => 'entry_date',
        'teachers'      => 'entry_date',
        'schools'       => 'entry_date',
        'other_users'   => 'entry_date',
        'employment'    => 'placement_date',
        'finance'       => 'date_mobilised',
        'partnerships'  => 'partner_date',
        'revenue'       => 'revenue_date',
        default         => 'created_at',
    };
}

function export_config(mysqli $conn, string $type): array {
    $participantSelect = implode(', ', [
        'p.id',
        'v.name AS venture_name',
        'p.entry_date',
        'p.user_number',
        'p.full_name',
        meal_optional_select($conn, 'venture_participants', 'p', 'phone', 'phone'),
        meal_optional_select($conn, 'venture_participants', 'p', 'email', 'email'),
        meal_optional_select($conn, 'venture_participants', 'p', 'enrollment_category', 'enrollment_category'),
        'p.gender',
        'p.specific_location',
        'p.location_type',
        'p.age_category',
        'p.refugee_status',
        'p.refugee_settlement',
        'p.pwd_status',
        'p.impairment_type',
        'p.education_level',
        'p.user_status',
        meal_optional_select($conn, 'venture_participants', 'p', 'verified_learner_outcome', 'verified_learner_outcome'),
        meal_optional_select($conn, 'venture_participants', 'p', 'verified_learner_outcome_other', 'verified_learner_outcome_other'),
        'p.working_at_entry',
        'p.transformation_objective',
        'p.in_work_status',
        meal_optional_select($conn, 'venture_participants', 'p', 'after_work_pathway', 'after_work_pathway'),
        meal_optional_select($conn, 'venture_participants', 'p', 'created_at', 'created_at'),
        meal_optional_select($conn, 'venture_participants', 'p', 'updated_at', 'updated_at'),
    ]);

    return match ($type) {
        'participants' => [
            'label'   => 'Learners',
            'table'   => 'venture_participants',
            'alias'   => 'p',
            'datecol' => 'entry_date',
            'headers' => [
                'ID','Venture','Date','Learner No','Name','Phone','Email','Enrollment Category',
                'Gender','Location','Urban/Rural','Age of Learner',
                'Refugee','Settlement','PWD','Impairment','Education Level','Learner Status',
                'Verified Learner Outcomes','Other Verified Learner Outcome',
                'YIW (Youth In Work) Before','Transformation Objective',
                'After-work Status','After-work Pathway','Created At','Updated At'
            ],
            'select' => $participantSelect,
        ],
        'employment' => [
            'label'   => 'Employment',
            'table'   => 'meal_employment',
            'alias'   => 'me',
            'datecol' => 'placement_date',
            'headers' => ['ID','Venture','Placement Date','Name','Gender','Age','PWD','Emp Type','Role','Status','Created'],
            'select'  => "me.id, v.name AS venture_name, me.placement_date, me.full_name, me.gender, me.age_category, me.pwd_status, me.employment_type, me.job_title, me.status, me.created_at",
        ],
        'finance' => [
            'label'   => 'Finance',
            'table'   => 'meal_finance',
            'alias'   => 'mf',
            'datecol' => 'date_mobilised',
            'headers' => ['ID','Venture','Date','Amount USD','Funding Form','Source','Created'],
            'select'  => "mf.id, v.name AS venture_name, mf.date_mobilised, mf.finance_usd, mf.funding_form, mf.funding_source, mf.created_at",
        ],
        'partnerships' => [
            'label'   => 'Partnerships',
            'table'   => 'meal_partnerships',
            'alias'   => 'mp',
            'datecol' => 'partner_date',
            'headers' => ['ID','Venture','Date','Partner','Type','Status','Created'],
            'select'  => "mp.id, v.name AS venture_name, mp.partner_date, mp.partner_name, mp.partnership_type, mp.partnership_status, mp.created_at",
        ],
        'revenue' => [
            'label'   => 'Revenue',
            'table'   => 'meal_revenue',
            'alias'   => 'mr',
            'datecol' => 'revenue_date',
            'headers' => ['ID','Venture','Month-end','Gross Revenue UGX','Stream','Created'],
            'select'  => "mr.id, v.name AS venture_name, mr.revenue_date, mr.gross_revenue_ugx, mr.revenue_stream, mr.created_at",
        ],
        'teachers' => [
            'label'   => 'Teachers / Educators Reached',
            'table'   => 'meal_teachers',
            'alias'   => 'mt',
            'datecol' => 'entry_date',
            'headers' => ['ID','Venture','Date','Name','Gender','Age','Refugee/Displaced','Host Community','Location','PWD','Created'],
            'select'  => "mt.id, v.name AS venture_name, mt.entry_date, mt.teacher_name, mt.gender, mt.age_category, mt.refugee_status, mt.host_community, mt.location_type, mt.pwd_status, mt.created_at",
        ],
        'schools' => [
            'label'   => 'Institutional Level (Schools)',
            'table'   => 'meal_schools',
            'alias'   => 'ms',
            'datecol' => 'entry_date',
            'headers' => ['ID','Venture','Date','School / Institution','Location','Ownership','Level','Created'],
            'select'  => "ms.id, v.name AS venture_name, ms.entry_date, ms.school_name, ms.location_type, ms.ownership_type, ms.school_level, ms.created_at",
        ],
        'other_users' => [
            'label'   => 'Other Users',
            'table'   => 'meal_other_users',
            'alias'   => 'mo',
            'datecol' => 'entry_date',
            'headers' => ['ID','Venture','Date','Gender','Host Community','Location','PWD','Education','Created'],
            'select'  => "mo.id, v.name AS venture_name, mo.entry_date, mo.gender, mo.host_community, mo.location_type, mo.pwd_status, mo.education_level, mo.created_at",
        ],
        default => [],
    };
}

function build_export_sql(mysqli $conn, string $type, int $f_venture, int $f_cohort, string $f_from, string $f_to): array {
    $cfg = export_config($conn, $type);
    if (empty($cfg)) {
        return ['', []];
    }

    $alias = $cfg['alias'];
    $date  = $cfg['datecol'];

    $sql = "SELECT {$cfg['select']} FROM {$cfg['table']} {$alias} JOIN ventures v ON v.id = {$alias}.venture_id WHERE 1=1";

    if ($f_venture > 0) {
        $sql .= " AND {$alias}.venture_id = " . (int)$f_venture;
    }

    if ($f_cohort > 0) {
        $sql .= " AND v.cohort_id = " . (int)$f_cohort;
    }

    if ($f_from !== '') {
        $sql .= " AND DATE({$alias}.{$date}) >= '" . $conn->real_escape_string($f_from) . "'";
    }

    if ($f_to !== '') {
        $sql .= " AND DATE({$alias}.{$date}) <= '" . $conn->real_escape_string($f_to) . "'";
    }

    $sql .= " ORDER BY {$alias}.{$date} DESC, {$alias}.id DESC";

    return [$sql, $cfg];
}

function clean_export_value($value): string {
    if ($value === null) {
        return '';
    }

    return trim(strip_tags((string)$value));
}


function fmt_created(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return '-';
    }
    return date('d/m/Y H:i', $ts);
}

function learner_status_label(?string $value): string {
    $value = trim((string)$value);
    return match ($value) {
        'New User'       => 'New Learner',
        'Returning User' => 'Returning Learner',
        default          => $value !== '' ? str_replace('User', 'Learner', $value) : '-',
    };
}

function export_csv(mysqli $conn, string $type, array $cfg, string $sql): void {
    while (ob_get_level()) {
        ob_end_clean();
    }

    $filename = 'meal_' . $type . '_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";

    $fh = fopen('php://output', 'w');
    fputcsv($fh, $cfg['headers']);

    $res = $conn->query($sql);
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) {
            fputcsv($fh, array_map('clean_export_value', array_values($row)));
        }
    }

    fclose($fh);
    exit;
}

function export_pdf(mysqli $conn, string $type, array $cfg, string $sql, string $site_name): void {
    $autoload_candidates = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php',
        dirname(__DIR__, 2) . '/vendor/autoload.php',
    ];

    $autoload = '';
    foreach ($autoload_candidates as $candidate) {
        if (is_file($candidate)) {
            $autoload = $candidate;
            break;
        }
    }

    if ($autoload === '') {
        die('DOMPDF autoload file not found. Install DOMPDF using Composer, then ensure vendor/autoload.php exists.');
    }

    require_once $autoload;

    if (!class_exists('\Dompdf\Dompdf')) {
        die('DOMPDF class not found. Please confirm dompdf/dompdf is installed.');
    }

    $rows_html = '';
    $res = $conn->query($sql);

    if ($res instanceof mysqli_result && $res->num_rows > 0) {
        while ($row = $res->fetch_assoc()) {
            $rows_html .= '<tr>';
            foreach ($row as $value) {
                $rows_html .= '<td>' . htmlspecialchars(clean_export_value($value), ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $rows_html .= '</tr>';
        }
    } else {
        $rows_html = '<tr><td colspan="' . count($cfg['headers']) . '" class="legacy-style-dac4fe6c9b">No records found.</td></tr>';
    }

    $headers_html = '';
    foreach ($cfg['headers'] as $header) {
        $headers_html .= '<th>' . htmlspecialchars($header, ENT_QUOTES, 'UTF-8') . '</th>';
    }

    $title = 'M&E ' . $cfg['label'] . ' Report';
    $generated = date('d M Y, H:i');

    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        
    <style>
.meal-bulk-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:12px 0;padding:10px 12px;border:1px solid #e2e8f0;border-radius:10px;background:#fff}
.meal-bulk-left,.meal-bulk-right,.meal-lock-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.meal-check{width:16px;height:16px;accent-color:var(--primary,#f97316);cursor:pointer}
.meal-selected-count,.meal-admin-note{font-size:.76rem;color:#64748b}
.meal-access-pill{display:inline-flex;align-items:center;gap:5px;padding:4px 8px;border-radius:999px;font-size:.68rem;font-weight:800}
.meal-access-pill.allowed{background:#dcfce7;color:#166534}.meal-access-pill.blocked{background:#fee2e2;color:#991b1b}
.meal-lock-form{display:inline-flex;margin:0}.meal-lock-btn{border:1px solid #d0d5dd;border-radius:7px;background:#fff;padding:5px 8px;font:inherit;font-size:.72rem;font-weight:700;cursor:pointer}
.meal-lock-btn.danger{border-color:#fecaca;color:#b91c1c}.meal-lock-btn.success{border-color:#bbf7d0;color:#166534;background:#f0fdf4}
.meal-empty-selection{opacity:.5;pointer-events:none}
.meal-select-all-matching-btn{
  border:1px dashed #fdba74;
  border-radius:7px;
  background:#fff7ed;
  color:#c2410c;
  padding:4px 9px;
  font:inherit;
  font-size:.72rem;
  font-weight:700;
  cursor:pointer;
}
.meal-select-all-matching-btn:hover{
  background:#ffedd5;
  border-color:#f97316;
}
.meal-select-all-matching-active{
  display:inline-flex;
  align-items:center;
  gap:7px;
  padding:4px 9px;
  border-radius:7px;
  background:#fff7ed;
  color:#9a3412;
  font-size:.72rem;
  font-weight:700;
}
.meal-clear-select-all{
  border:0;
  background:transparent;
  color:#c2410c;
  font:inherit;
  font-size:.72rem;
  font-weight:800;
  text-decoration:underline;
  cursor:pointer;
  padding:0;
}
.meal-panel-overview-table,
.vt-table{
  width:100%;
  table-layout:fixed;
}
.vt-table th,
.vt-table td{
  padding:10px 8px;
  vertical-align:middle;
}
.vt-table th{
  font-size:.69rem;
  white-space:nowrap;
}
.vt-table th:first-child,
.vt-table td:first-child{
  width:16%;
}
.vt-table th:nth-child(2),
.vt-table td:nth-child(2),
.vt-table th:nth-child(3),
.vt-table td:nth-child(3){
  width:11%;
}
.vt-table th:nth-child(4),
.vt-table td:nth-child(4),
.vt-table th:nth-child(5),
.vt-table td:nth-child(5),
.vt-table th:nth-child(6),
.vt-table td:nth-child(6){
  width:13%;
}
.vt-table th:nth-child(7),
.vt-table td:nth-child(7){
  width:8%;
}
.vt-table th:last-child,
.vt-table td:last-child{
  width:13%;
}

.vt-table .legacy-style-b88d1817be{
  display:flex;
  align-items:center;
  gap:6px;
  min-width:0;
}

.vt-table .prog-wrap{
  flex:0 0 72px;
  width:72px;
  max-width:72px;
  height:7px;
  overflow:hidden;
  border-radius:999px;
  background:#f1f5f9;
}

.vt-table .prog-bar{
  height:100%;
  border-radius:999px;
}

.vt-table .legacy-style-b88d1817be > span{
  flex:0 0 auto;
  min-width:34px;
  font-size:.75rem;
  font-weight:700;
  white-space:nowrap;
}

.meal-actions-cell{
  white-space:nowrap;
}

.meal-row-actions{
  display:flex;
  align-items:center;
  justify-content:flex-start;
  gap:5px;
  flex-wrap:nowrap;
}

.meal-row-actions .meal-lock-form{
  display:inline-flex;
  margin:0;
}

.meal-icon-action,
.meal-icon-status{
  width:30px;
  height:30px;
  flex:0 0 30px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:0;
  border-radius:8px;
  border:1px solid #dbe1e8;
  background:#fff;
  color:#475569;
  font-size:.76rem;
  line-height:1;
  text-decoration:none;
  cursor:pointer;
  transition:background .15s ease,border-color .15s ease,color .15s ease,transform .15s ease;
}

.meal-icon-action:hover{
  transform:translateY(-1px);
}

.meal-icon-action.neutral:hover{
  border-color:#94a3b8;
  background:#f8fafc;
  color:#0f172a;
}

.meal-icon-status.allowed{
  border-color:#bbf7d0;
  background:#f0fdf4;
  color:#15803d;
}

.meal-icon-status.blocked{
  border-color:#fecaca;
  background:#fef2f2;
  color:#b91c1c;
}

.meal-icon-action.danger{
  border-color:#fecaca;
  background:#fff;
  color:#b91c1c;
}

.meal-icon-action.danger:hover{
  background:#fef2f2;
  border-color:#fca5a5;
}

.meal-icon-action.success{
  border-color:#bbf7d0;
  background:#f0fdf4;
  color:#15803d;
}

.meal-icon-action.success:hover{
  background:#dcfce7;
  border-color:#86efac;
}

@media(max-width:1100px){
  .vt-table{
    table-layout:auto;
    min-width:980px;
  }
  .vt-table .prog-wrap{
    flex-basis:58px;
    width:58px;
    max-width:58px;
  }
}

@media(max-width:700px){.meal-bulk-toolbar{align-items:stretch;flex-direction:column}.meal-bulk-left,.meal-bulk-right{width:100%}.meal-bulk-right .btn{width:100%;justify-content:center}}

.meal-venture-name{
  min-width:0;
}

/* Professional M&E table layout */
.legacy-style-03359ca378,
.data-table-wrap{
  width:100%;
  max-width:100%;
  overflow-x:auto !important;
  overflow-y:hidden;
  -webkit-overflow-scrolling:touch;
  scrollbar-width:thin;
  padding-bottom:5px;
}

.legacy-style-03359ca378::-webkit-scrollbar,
.data-table-wrap::-webkit-scrollbar{
  height:9px;
}

.legacy-style-03359ca378::-webkit-scrollbar-thumb,
.data-table-wrap::-webkit-scrollbar-thumb{
  background:#cbd5e1;
  border-radius:999px;
}

.legacy-style-03359ca378::-webkit-scrollbar-track,
.data-table-wrap::-webkit-scrollbar-track{
  background:#f8fafc;
}

.vt-table{
  min-width:1120px !important;
  table-layout:auto !important;
}

.vt-table th:last-child,
.vt-table td:last-child{
  width:220px !important;
  min-width:220px !important;
}

.meal-actions-cell{
  min-width:220px !important;
  width:220px !important;
}

.meal-row-actions{
  width:max-content;
  min-width:190px;
  justify-content:flex-start;
  gap:7px;
}

.meal-icon-action,
.meal-icon-status{
  width:34px;
  height:34px;
  flex:0 0 34px;
  font-size:.82rem;
}

/* Individual icon colours */
.meal-icon-action.neutral{
  color:#2563eb;
  border-color:#bfdbfe;
  background:#eff6ff;
}
.meal-icon-action.neutral:hover{
  color:#1d4ed8;
  border-color:#93c5fd;
  background:#dbeafe;
}

.meal-icon-status.allowed{
  color:#15803d;
  border-color:#bbf7d0;
  background:#f0fdf4;
}

.meal-icon-status.blocked{
  color:#dc2626;
  border-color:#fecaca;
  background:#fef2f2;
}

.meal-icon-action.success{
  color:#047857;
  border-color:#a7f3d0;
  background:#ecfdf5;
}

.meal-icon-action.success:hover{
  color:#065f46;
  border-color:#6ee7b7;
  background:#d1fae5;
}

.meal-icon-action.danger{
  color:#dc2626;
  border-color:#fecaca;
  background:#fff1f2;
}

.meal-icon-action.danger:hover{
  color:#b91c1c;
  border-color:#fca5a5;
  background:#fee2e2;
}

/* Narrower progress indicators */
.vt-table .prog-wrap{
  flex:0 0 58px !important;
  width:58px !important;
  max-width:58px !important;
  height:6px !important;
}

/* General data tables must scroll instead of crushing columns */
.data-table{
  width:max-content;
  min-width:100%;
  white-space:nowrap;
}

.data-table th,
.data-table td{
  white-space:nowrap;
}

/* Pagination */
.meal-pagination-wrap{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:14px;
  padding:14px 2px 4px;
  flex-wrap:wrap;
}

.meal-pagination-summary{
  color:#64748b;
  font-size:.76rem;
}

.meal-pagination{
  display:flex;
  align-items:center;
  gap:5px;
  flex-wrap:wrap;
}

.meal-pagination a,
.meal-pagination .current,
.meal-page-dots{
  min-width:32px;
  height:32px;
  padding:0 9px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  border:1px solid #e2e8f0;
  border-radius:7px;
  background:#fff;
  color:#475569;
  font-size:.75rem;
  font-weight:700;
  text-decoration:none;
}

.meal-pagination a:hover{
  border-color:var(--primary,#f97316);
  color:var(--primary,#f97316);
  background:#fff7ed;
}

.meal-pagination .current{
  border-color:var(--primary,#f97316);
  background:var(--primary,#f97316);
  color:#fff;
}

.meal-page-dots{
  border-color:transparent;
  background:transparent;
}

@media(max-width:700px){
  .meal-pagination-wrap{
    align-items:stretch;
    flex-direction:column;
  }
  .meal-pagination{
    overflow-x:auto;
    flex-wrap:nowrap;
    padding-bottom:4px;
  }
}

.meal-venture-name strong{
  display:block;
  line-height:1.35;
  word-break:normal;
  overflow-wrap:anywhere;
}

.meal-doc-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin:0 0 14px;padding:14px 16px;border:1px solid #e2e8f0;border-radius:12px;background:#fff}
.meal-doc-head h3{margin:0 0 4px;color:#172033;font-size:1rem}.meal-doc-head p{margin:0;color:#64748b;font-size:.78rem}
.meal-doc-count,.meal-doc-category,.meal-file-pill{display:inline-flex;align-items:center;gap:6px;border-radius:999px;font-size:.7rem;font-weight:800;white-space:nowrap}
.meal-doc-count{padding:6px 10px;background:#fff7ed;color:#c2410c}.meal-doc-category{padding:5px 8px;background:#eff6ff;color:#1d4ed8}
.meal-file-pill{padding:5px 8px;background:#f8fafc;color:#475569;border:1px solid #e2e8f0;max-width:240px;overflow:hidden;text-overflow:ellipsis}
.meal-doc-description{min-width:220px;max-width:320px;white-space:normal!important}.meal-doc-table{min-width:1300px!important}.meal-empty-docs{text-align:center!important;padding:34px!important;color:#64748b}
#panel-participants .data-table{min-width:2700px!important}
.meal-doc-modal{position:fixed;inset:0;z-index:2147483645;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(15,23,42,.5);backdrop-filter:blur(3px)}
.meal-doc-modal.open{display:flex}.meal-doc-modal-card{width:min(430px,100%);padding:24px;border-radius:16px;background:#fff;box-shadow:0 24px 70px rgba(15,23,42,.28);text-align:center}
.meal-doc-modal-icon{width:48px;height:48px;margin:0 auto 12px;display:grid;place-items:center;border-radius:50%;background:#fef2f2;color:#dc2626}
.meal-doc-modal-card h3{margin:0 0 8px;color:#172033}.meal-doc-modal-card p{margin:0 0 20px;color:#64748b;font-size:.82rem;line-height:1.55}
.meal-doc-modal-actions{display:flex;justify-content:center;gap:9px}
@media(max-width:640px){.meal-doc-head{flex-direction:column}.meal-doc-modal-actions{flex-direction:column}.meal-doc-modal-actions .btn{width:100%;justify-content:center}}


/* Youth in Work reporting */
.meal-yiw-kpi{
  width:100%;
  border:1px solid #e2e8f0;
  background:#fff;
  text-align:left;
  font:inherit;
  cursor:pointer;
}
.meal-yiw-kpi:hover,
.meal-yiw-kpi:focus-visible{
  border-color:#fdba74;
  background:#fffaf5;
  transform:translateY(-2px);
  box-shadow:0 10px 26px rgba(15,23,42,.08);
}
.meal-yiw-view{
  margin-top:5px;
  color:#f97316;
  font-size:11px;
  font-weight:700;
}
.meal-yiw-row-btn{
  min-width:68px;
  padding:7px 9px;
  border:1px solid #fed7aa;
  border-radius:8px;
  background:#fff7ed;
  color:#9a3412;
  cursor:pointer;
}
.meal-yiw-row-btn strong,.meal-yiw-row-btn span{display:block}
.meal-yiw-row-btn strong{font-size:14px}
.meal-yiw-row-btn span{font-size:10px;font-weight:700}
.meal-yiw-row-btn:hover{background:#ffedd5}
.meal-admin-modal{
  position:fixed;
  inset:0;
  z-index:2147483646;
  display:none;
  align-items:center;
  justify-content:center;
  padding:20px;
  background:rgba(15,23,42,.68);
  backdrop-filter:blur(4px);
}
.meal-admin-modal.open{display:flex}
.meal-admin-modal-card{
  width:min(820px,100%);
  max-height:90vh;
  overflow:auto;
  background:#fff;
  border-radius:16px;
  box-shadow:0 30px 80px rgba(0,0,0,.28);
}
.meal-admin-modal-head{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  padding:17px 20px;
  border-bottom:1px solid #e5e7eb;
}
.meal-admin-modal-head h3{margin:0;color:#0f172a;font-size:17px}
.meal-admin-modal-close{
  width:34px;height:34px;border:0;border-radius:8px;
  background:#f8fafc;color:#64748b;cursor:pointer
}
.meal-admin-modal-body{padding:20px}
.meal-admin-yiw-intro{
  margin-bottom:14px;padding:11px 13px;border:1px solid #fed7aa;
  border-radius:10px;background:#fff7ed;color:#9a3412;
  font-size:12px;font-weight:700
}
.meal-admin-yiw-grid{
  display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px
}
.meal-admin-yiw-item{
  padding:14px;border:1px solid #e2e8f0;border-left:4px solid #f97316;
  border-radius:10px
}
.meal-admin-yiw-item strong{
  display:block;margin-bottom:5px;color:#0f172a;font-size:23px
}
.meal-admin-yiw-item span{color:#64748b;font-size:12px;line-height:1.4}
.meal-admin-yiw-wide{grid-column:1/-1}
.meal-admin-modal-foot{
  display:flex;justify-content:flex-end;padding:14px 20px;border-top:1px solid #e5e7eb
}
@media(max-width:650px){
  .meal-admin-yiw-grid{grid-template-columns:1fr}
  .meal-admin-yiw-wide{grid-column:auto}
}

</style>
</head>
    <body>
        <h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>
        <div class="meta">' . htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8') . ' Admin | Generated: ' . htmlspecialchars($generated, ENT_QUOTES, 'UTF-8') . '</div>
        <table>
            <thead><tr>' . $headers_html . '</tr></thead>
            <tbody>' . $rows_html . '</tbody>
        </table>
        <div class="footer">Generated by the M&E reporting module.</div>
    </body>
    </html>';

    while (ob_get_level()) {
        ob_end_clean();
    }

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    $filename = 'meal_' . $type . '_' . date('Ymd_His') . '.pdf';
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}

function export_url(string $tab, string $type, string $format, int $venture_id, int $cohort_id, string $from, string $to): string {
    return '?' . http_build_query([
        'tab'        => $tab,
        'export'     => $type,
        'format'     => $format,
        'venture_id' => $venture_id,
        'cohort_id'  => $cohort_id,
        'from'       => $from,
        'to'         => $to,
    ]);
}

$site_name = $conn->query("SELECT setting_value FROM site_settings WHERE setting_key='site_name' LIMIT 1")->fetch_row()[0] ?? 'EdTech Fellowship';

if (($_GET['export'] ?? '') !== '') {
    $export_type = trim((string)$_GET['export']);
    $format      = strtolower(trim((string)($_GET['format'] ?? 'csv')));

    $allowed_exp = ['participants', 'teachers', 'schools', 'other_users', 'employment', 'finance', 'partnerships', 'revenue'];
    if (!in_array($export_type, $allowed_exp, true)) {
        die('Invalid export type.');
    }

    if (!in_array($format, ['csv', 'pdf'], true)) {
        $format = 'csv';
    }

    [$sql, $cfg] = build_export_sql($conn, $export_type, $f_venture, $f_cohort, $f_from, $f_to);

    if ($sql === '' || empty($cfg)) {
        die('Export configuration not found.');
    }

    if ($format === 'pdf') {
        export_pdf($conn, $export_type, $cfg, $sql, $site_name);
    }

    export_csv($conn, $export_type, $cfg, $sql);
}



function build_where(mysqli $conn, string $alias, string $date_field,
                     int $f_venture, int $f_cohort, string $f_from, string $f_to): string {
    $w = " WHERE 1=1";
    if ($f_venture) $w .= " AND {$alias}.venture_id={$f_venture}";
    if ($f_cohort)  $w .= " AND v.cohort_id={$f_cohort}";
    if ($f_from)    $w .= " AND DATE({$alias}.{$date_field})>='".$conn->real_escape_string($f_from)."'";
    if ($f_to)      $w .= " AND DATE({$alias}.{$date_field})<='".$conn->real_escape_string($f_to)."'";
    return $w;
}


$bw = build_where($conn,'mb','entry_date',$f_venture,$f_cohort,$f_from,$f_to);
$ew = build_where($conn,'me','placement_date',$f_venture,$f_cohort,$f_from,$f_to);
$fw = build_where($conn,'mf','date_mobilised',$f_venture,$f_cohort,$f_from,$f_to);
$pw = build_where($conn,'mp','partner_date',$f_venture,$f_cohort,$f_from,$f_to);
$rw = build_where($conn,'mr','revenue_date',$f_venture,$f_cohort,$f_from,$f_to);
$tw = build_where($conn,'mt','entry_date',$f_venture,$f_cohort,$f_from,$f_to);
$sw = build_where($conn,'ms','entry_date',$f_venture,$f_cohort,$f_from,$f_to);
$ow = build_where($conn,'mo','entry_date',$f_venture,$f_cohort,$f_from,$f_to);

$base_b = "FROM venture_participants mb JOIN ventures v ON v.id=mb.venture_id{$bw}";
$base_e = "FROM meal_employment me JOIN ventures v ON v.id=me.venture_id{$ew}";
$base_f = "FROM meal_finance mf JOIN ventures v ON v.id=mf.venture_id{$fw}";
$base_p = "FROM meal_partnerships mp JOIN ventures v ON v.id=mp.venture_id{$pw}";
$base_r = "FROM meal_revenue mr JOIN ventures v ON v.id=mr.venture_id{$rw}";
$base_t = "FROM meal_teachers mt JOIN ventures v ON v.id=mt.venture_id{$tw}";
$base_s = "FROM meal_schools ms JOIN ventures v ON v.id=ms.venture_id{$sw}";
$base_o = "FROM meal_other_users mo JOIN ventures v ON v.id=mo.venture_id{$ow}";

$stat = fn(string $q) => (float)($conn->query($q)->fetch_row()[0] ?? 0);

$hasAfterWorkPathway =
    meal_column_exists($conn, 'venture_participants', 'after_work_pathway');

$afterWorkPathwayExpr =
    $hasAfterWorkPathway
        ? 'mb.after_work_pathway'
        : "''";

// Percentage indicators (Female %, Refugee %, PWD %, Youth %) use New
// Learners - i.e. venture_participants.enrollment_category = 'New' - as
// the denominator, per the programme team's reporting definition. Falls
// back to counting every learner (equivalent to the pre-migration
// behaviour) if the column hasn't been created yet on this install.
$hasEnrollmentCategory =
    meal_column_exists($conn, 'venture_participants', 'enrollment_category');

$newLearnerCondition =
    $hasEnrollmentCategory
        ? "mb.enrollment_category='New'"
        : '1=1';

$learner_youth_sql = "(mb.age_category REGEXP '^[0-9][0-9]*$' AND (mb.age_category + 0) BETWEEN 18 AND 34)";
$employment_youth_sql = "me.age_category IN ('13-20','21-35','18-34Yrs')";
$teacher_youth_sql = "mt.age_category IN ('13-20','21-35','18-34Yrs')";

$s = [
    // Gender, PWD and Refugee counts are captured across EVERY learner
    // record, not just ones tagged Enrollment Category = New - most
    // existing (and many new) records don't have that field filled in yet,
    // so filtering these counts by it was hiding real data on the
    // dashboard. Only the percentage denominator (new_learners) below is
    // restricted to New enrollments.
    'total'          => (int)$stat("SELECT COUNT(*) {$base_b}"),
    'new_learners'   => (int)$stat("SELECT COUNT(*) {$base_b} AND {$newLearnerCondition}"),
    'female'         => (int)$stat("SELECT COUNT(*) {$base_b} AND mb.gender='Female'"),
    'youth'          => (int)$stat("SELECT COUNT(*) {$base_b} AND {$learner_youth_sql}"),
    'refugee'        => (int)$stat("SELECT COUNT(*) {$base_b} AND mb.refugee_status='Yes'"),
    'pwd'            => (int)$stat("SELECT COUNT(*) {$base_b} AND mb.pwd_status='Yes'"),
    'emp'      => (int)$stat("SELECT COUNT(*) {$base_e}"),
    'emp_f'    => (int)$stat("SELECT COUNT(*) {$base_e} AND me.gender='Female'"),
    'emp_youth'=> (int)$stat("SELECT COUNT(*) {$base_e} AND {$employment_youth_sql}"),
    'fin_usd'  => $stat("SELECT COALESCE(SUM(mf.finance_usd),0) {$base_f}"),
    'partners' => (int)$stat("SELECT COUNT(*) {$base_p}"),
    'rev_ugx'  => $stat("SELECT COALESCE(SUM(mr.gross_revenue_ugx),0) {$base_r}"),
    'ventures_rep' => (int)$stat("SELECT COUNT(DISTINCT mb.venture_id) {$base_b}"),

    // Teachers / Educators Reached
    'teachers'         => (int)$stat("SELECT COUNT(*) {$base_t}"),
    'teachers_f'       => (int)$stat("SELECT COUNT(*) {$base_t} AND mt.gender='Female'"),
    'teachers_youth'   => (int)$stat("SELECT COUNT(*) {$base_t} AND {$teacher_youth_sql}"),
    'teachers_refugee' => (int)$stat("SELECT COUNT(*) {$base_t} AND mt.refugee_status='Yes'"),
    'teachers_host'    => (int)$stat("SELECT COUNT(*) {$base_t} AND mt.host_community='Yes'"),
    'teachers_pwd'     => (int)$stat("SELECT COUNT(*) {$base_t} AND mt.pwd_status='Yes'"),

    // Institutional Level (Schools)
    'schools'          => (int)$stat("SELECT COUNT(*) {$base_s}"),
    'schools_gov'      => (int)$stat("SELECT COUNT(*) {$base_s} AND ms.ownership_type='Government-aided'"),
    'schools_private'  => (int)$stat("SELECT COUNT(*) {$base_s} AND ms.ownership_type='Private'"),
    'schools_rural'    => (int)$stat("SELECT COUNT(*) {$base_s} AND ms.location_type='Rural'"),

    // Other Users (age category and refugee/displaced status are no longer
    // collected on the "Other Users" form, so those breakdowns are dropped
    // here to match. total/gender/host-community/PWD stay because those
    // fields are still collected.)
    'other'         => (int)$stat("SELECT COUNT(*) {$base_o}"),
    'other_f'       => (int)$stat("SELECT COUNT(*) {$base_o} AND mo.gender='Female'"),
    'other_host'    => (int)$stat("SELECT COUNT(*) {$base_o} AND mo.host_community='Yes'"),
    'other_pwd'     => (int)$stat("SELECT COUNT(*) {$base_o} AND mo.pwd_status='Yes'"),
];

// Enrollment Category is new and optional, so on filters where nobody has
// been tagged "New" yet, new_learners is 0 and every percentage below
// would show as 0% even though the Learners table clearly has data.
// Falling back to the overall learner count keeps these meaningful until
// Enrollment Category gets filled in going forward.
$s['pct_denominator'] = $s['new_learners'] > 0 ? $s['new_learners'] : $s['total'];

$yiw = [
    'new_self' => $hasAfterWorkPathway
        ? (int)$stat("SELECT COUNT(*) {$base_b} AND mb.in_work_status='New' AND mb.after_work_pathway='Self-employment'")
        : 0,
    'additional_self' => $hasAfterWorkPathway
        ? (int)$stat("SELECT COUNT(*) {$base_b} AND mb.in_work_status='Additional' AND mb.after_work_pathway='Self-employment'")
        : 0,
    'improved_self' => $hasAfterWorkPathway
        ? (int)$stat("SELECT COUNT(*) {$base_b} AND mb.in_work_status='Improved' AND mb.after_work_pathway='Self-employment'")
        : 0,
    'new_wage' => $hasAfterWorkPathway
        ? (int)$stat("SELECT COUNT(*) {$base_b} AND mb.in_work_status='New' AND mb.after_work_pathway='Wage employment'")
        : 0,
    'additional_wage' => $hasAfterWorkPathway
        ? (int)$stat("SELECT COUNT(*) {$base_b} AND mb.in_work_status='Additional' AND mb.after_work_pathway='Wage employment'")
        : 0,
    'improved_wage' => $hasAfterWorkPathway
        ? (int)$stat("SELECT COUNT(*) {$base_b} AND mb.in_work_status='Improved' AND mb.after_work_pathway='Wage employment'")
        : 0,
];

$yiw['total_outcomes'] =
    $yiw['new_self']
    + $yiw['additional_self']
    + $yiw['improved_self']
    + $yiw['new_wage']
    + $yiw['additional_wage']
    + $yiw['improved_wage'];

// Finance and Revenue don't have a dedicated row count in $s (it only
// tracks their sums), but the bulk "select all matching" control and the
// pager both need the total record count for the current filters, so it's
// computed once here and reused by both instead of querying twice.
$fin_total_count = (int)$stat("SELECT COUNT(*) {$base_f}");
$rev_total_count = (int)$stat("SELECT COUNT(*) {$base_r}");


$docWhere = " WHERE 1=1";
if ($f_venture > 0) {
    $docWhere .= " AND md.venture_id=" . (int)$f_venture;
}
if ($f_cohort > 0) {
    $docWhere .= " AND v.cohort_id=" . (int)$f_cohort;
}
if ($f_from !== '') {
    $docWhere .= " AND DATE(md.created_at)>='" . $conn->real_escape_string($f_from) . "'";
}
if ($f_to !== '') {
    $docWhere .= " AND DATE(md.created_at)<='" . $conn->real_escape_string($f_to) . "'";
}

$documentsTotal = 0;
$documentsRows = false;

if (meal_table_exists($conn, 'meal_supporting_documents')) {
    $documentsTotal = (int)$stat("
        SELECT COUNT(*)
        FROM meal_supporting_documents md
        JOIN ventures v ON v.id=md.venture_id
        {$docWhere}
    ");

    $documentsRows = $conn->query("
        SELECT
            md.id,
            md.venture_id,
            md.category,
            md.document_name,
            md.description,
            md.original_name,
            md.file_ext,
            md.file_size,
            md.uploaded_by,
            md.created_at,
            v.name AS venture_name
        FROM meal_supporting_documents md
        JOIN ventures v ON v.id=md.venture_id
        {$docWhere}
        ORDER BY md.created_at DESC, md.id DESC
        LIMIT {$meal_per_page} OFFSET {$meal_offset}
    ");
}

$pct = fn($n, $d) => $d > 0 ? round(($n/$d)*100) : 0;


$ventures = $conn->query("SELECT id, name FROM ventures ORDER BY name ASC");
$cohorts  = $conn->query("SELECT id, name FROM cohorts ORDER BY id DESC");


$venture_summary = $conn->query("
    SELECT v.id, v.name,
        COUNT(DISTINCT mb.id) AS total_learners,
        SUM({$newLearnerCondition}) AS new_learners,
        SUM(mb.gender='Female') AS female,
        SUM(mb.refugee_status='Yes') AS refugees,
        SUM(mb.pwd_status='Yes') AS pwds,
        SUM((mb.age_category REGEXP '^[0-9][0-9]*$' AND (mb.age_category + 0) BETWEEN 18 AND 34)) AS youth,
        SUM(mb.in_work_status='New' AND {$afterWorkPathwayExpr}='Self-employment') AS yiw_new_self,
        SUM(mb.in_work_status='Additional' AND {$afterWorkPathwayExpr}='Self-employment') AS yiw_additional_self,
        SUM(mb.in_work_status='Improved' AND {$afterWorkPathwayExpr}='Self-employment') AS yiw_improved_self,
        SUM(mb.in_work_status='New' AND {$afterWorkPathwayExpr}='Wage employment') AS yiw_new_wage,
        SUM(mb.in_work_status='Additional' AND {$afterWorkPathwayExpr}='Wage employment') AS yiw_additional_wage,
        SUM(mb.in_work_status='Improved' AND {$afterWorkPathwayExpr}='Wage employment') AS yiw_improved_wage
    FROM ventures v
    LEFT JOIN venture_participants mb ON mb.venture_id = v.id
    ".($f_cohort ? "WHERE v.cohort_id={$f_cohort}" : "")."
    GROUP BY v.id, v.name
    ORDER BY total_learners DESC
");


$participantOptionalSelect = implode(', ', [
    meal_optional_select($conn, 'venture_participants', 'mb', 'phone', 'phone'),
    meal_optional_select($conn, 'venture_participants', 'mb', 'email', 'email'),
    meal_optional_select($conn, 'venture_participants', 'mb', 'enrollment_category', 'enrollment_category'),
    meal_optional_select($conn, 'venture_participants', 'mb', 'verified_learner_outcome', 'verified_learner_outcome'),
    meal_optional_select($conn, 'venture_participants', 'mb', 'verified_learner_outcome_other', 'verified_learner_outcome_other'),
    meal_optional_select($conn, 'venture_participants', 'mb', 'after_work_pathway', 'after_work_pathway'),
    meal_optional_select($conn, 'venture_participants', 'mb', 'created_at', 'created_at'),
    meal_optional_select($conn, 'venture_participants', 'mb', 'updated_at', 'updated_at'),
]);

$recent_ben = $conn->query("
    SELECT
        mb.id,
        mb.venture_id,
        mb.entry_date,
        mb.user_number,
        mb.full_name,
        mb.user_status,
        mb.gender,
        mb.specific_location,
        mb.location_type,
        mb.age_category,
        mb.refugee_status,
        mb.refugee_settlement,
        mb.pwd_status,
        mb.impairment_type,
        mb.education_level,
        mb.working_at_entry,
        mb.transformation_objective,
        mb.in_work_status,
        {$participantOptionalSelect},
        v.name AS venture_name
    {$base_b}
    ORDER BY mb.id DESC
    LIMIT {$meal_per_page} OFFSET {$meal_offset}
");

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>M&amp;E Report - <?= h($site_name) ?> Admin</title>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="assets/css/admin.css">
  
<style>
.meal-bulk-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:12px 0;padding:10px 12px;border:1px solid #e2e8f0;border-radius:10px;background:#fff}
.meal-bulk-left,.meal-bulk-right,.meal-lock-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.meal-check{width:16px;height:16px;accent-color:var(--primary,#f97316);cursor:pointer}
.meal-selected-count,.meal-admin-note{font-size:.76rem;color:#64748b}
.meal-access-pill{display:inline-flex;align-items:center;gap:5px;padding:4px 8px;border-radius:999px;font-size:.68rem;font-weight:800}
.meal-access-pill.allowed{background:#dcfce7;color:#166534}.meal-access-pill.blocked{background:#fee2e2;color:#991b1b}
.meal-lock-form{display:inline-flex;margin:0}.meal-lock-btn{border:1px solid #d0d5dd;border-radius:7px;background:#fff;padding:5px 8px;font:inherit;font-size:.72rem;font-weight:700;cursor:pointer}
.meal-lock-btn.danger{border-color:#fecaca;color:#b91c1c}.meal-lock-btn.success{border-color:#bbf7d0;color:#166534;background:#f0fdf4}
.meal-empty-selection{opacity:.5;pointer-events:none}
@media(max-width:700px){.meal-bulk-toolbar{align-items:stretch;flex-direction:column}.meal-bulk-left,.meal-bulk-right{width:100%}.meal-bulk-right .btn{width:100%;justify-content:center}}

/* ============================================================
   ADMIN YIW MODAL - PAGE STYLES
============================================================ */
.meal-yiw-kpi{
  width:100% !important;
  border:1px solid #e2e8f0 !important;
  background:#fff !important;
  color:inherit !important;
  text-align:left !important;
  font:inherit !important;
  cursor:pointer !important;
}
.meal-yiw-kpi:hover,
.meal-yiw-kpi:focus-visible{
  border-color:#fdba74 !important;
  background:#fffaf5 !important;
  transform:translateY(-2px);
  box-shadow:0 10px 26px rgba(15,23,42,.08);
}
.meal-yiw-view{
  margin-top:5px;
  color:#f97316;
  font-size:11px;
  font-weight:700;
}
.meal-yiw-row-btn{
  min-width:68px;
  padding:7px 9px;
  border:1px solid #fed7aa;
  border-radius:8px;
  background:#fff7ed;
  color:#9a3412;
  cursor:pointer;
}
.meal-yiw-row-btn strong,
.meal-yiw-row-btn span{
  display:block;
}
.meal-yiw-row-btn strong{font-size:14px}
.meal-yiw-row-btn span{font-size:10px;font-weight:700}
.meal-yiw-row-btn:hover{background:#ffedd5}

body > #mealAdminYiwModal.meal-admin-modal{
  position:fixed !important;
  inset:0 !important;
  z-index:2147483646 !important;
  width:100vw !important;
  height:100vh !important;
  display:none !important;
  align-items:center !important;
  justify-content:center !important;
  padding:20px !important;
  overflow:auto !important;
  background:rgba(15,23,42,.68) !important;
  backdrop-filter:blur(4px);
  visibility:hidden !important;
  opacity:0 !important;
  pointer-events:none !important;
}

body > #mealAdminYiwModal.meal-admin-modal.open{
  display:flex !important;
  visibility:visible !important;
  opacity:1 !important;
  pointer-events:auto !important;
}

#mealAdminYiwModal .meal-admin-modal-card{
  position:relative !important;
  display:block !important;
  width:min(820px,calc(100vw - 32px)) !important;
  max-height:90vh !important;
  overflow:auto !important;
  margin:auto !important;
  background:#fff !important;
  border-radius:16px !important;
  box-shadow:0 30px 80px rgba(0,0,0,.28) !important;
}

#mealAdminYiwModal .meal-admin-modal-head{
  position:sticky;
  top:0;
  z-index:2;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  padding:17px 20px;
  border-bottom:1px solid #e5e7eb;
  background:#fff;
  border-radius:16px 16px 0 0;
}
#mealAdminYiwModal .meal-admin-modal-head h3{
  margin:0;
  color:#0f172a;
  font-size:17px;
}
#mealAdminYiwModal .meal-admin-modal-close{
  flex:0 0 auto;
  width:34px;
  height:34px;
  border:0;
  border-radius:8px;
  background:#f8fafc;
  color:#64748b;
  cursor:pointer;
}
#mealAdminYiwModal .meal-admin-modal-body{
  padding:20px;
}
#mealAdminYiwModal .meal-admin-yiw-intro{
  margin-bottom:14px;
  padding:11px 13px;
  border:1px solid #fed7aa;
  border-radius:10px;
  background:#fff7ed;
  color:#9a3412;
  font-size:12px;
  font-weight:700;
  line-height:1.5;
}
#mealAdminYiwModal .meal-admin-yiw-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:11px;
}
#mealAdminYiwModal .meal-admin-yiw-item{
  padding:14px;
  border:1px solid #e2e8f0;
  border-left:4px solid #f97316;
  border-radius:10px;
  background:#fff;
}
#mealAdminYiwModal .meal-admin-yiw-item strong{
  display:block;
  margin-bottom:5px;
  color:#0f172a;
  font-size:23px;
}
#mealAdminYiwModal .meal-admin-yiw-item span{
  color:#64748b;
  font-size:12px;
  line-height:1.4;
}
#mealAdminYiwModal .meal-admin-yiw-wide{
  grid-column:1/-1;
}
#mealAdminYiwModal .meal-admin-modal-foot{
  position:sticky;
  bottom:0;
  display:flex;
  justify-content:flex-end;
  padding:14px 20px;
  border-top:1px solid #e5e7eb;
  background:#fff;
  border-radius:0 0 16px 16px;
}
body.meal-admin-modal-open{
  overflow:hidden !important;
}
@media(max-width:650px){
  body > #mealAdminYiwModal.meal-admin-modal{
    padding:12px !important;
    align-items:flex-end !important;
  }
  #mealAdminYiwModal .meal-admin-modal-card{
    width:100% !important;
    max-height:92vh !important;
    border-radius:16px 16px 0 0 !important;
  }
  #mealAdminYiwModal .meal-admin-yiw-grid{
    grid-template-columns:1fr;
  }
  #mealAdminYiwModal .meal-admin-yiw-wide{
    grid-column:auto;
  }
}


/* ============================================================
   SUPPORTING DOCUMENT DELETE MODAL - PAGE STYLES
============================================================ */
body > #mealDocumentDeleteModal.meal-doc-modal{
  position:fixed !important;
  inset:0 !important;
  z-index:2147483647 !important;
  width:100vw !important;
  height:100vh !important;
  display:none !important;
  align-items:center !important;
  justify-content:center !important;
  padding:20px !important;
  overflow:auto !important;
  background:rgba(15,23,42,.68) !important;
  backdrop-filter:blur(4px);
  visibility:hidden !important;
  opacity:0 !important;
  pointer-events:none !important;
}

body > #mealDocumentDeleteModal.meal-doc-modal.open{
  display:flex !important;
  visibility:visible !important;
  opacity:1 !important;
  pointer-events:auto !important;
}

#mealDocumentDeleteModal .meal-doc-modal-card{
  position:relative !important;
  width:min(460px,calc(100vw - 32px)) !important;
  margin:auto !important;
  padding:26px !important;
  border-radius:16px !important;
  background:#fff !important;
  box-shadow:0 30px 80px rgba(0,0,0,.30) !important;
  text-align:center !important;
}

#mealDocumentDeleteModal .meal-doc-modal-icon{
  width:52px !important;
  height:52px !important;
  margin:0 auto 14px !important;
  display:grid !important;
  place-items:center !important;
  border-radius:50% !important;
  background:#fef2f2 !important;
  color:#dc2626 !important;
  font-size:20px !important;
}

#mealDocumentDeleteModal .meal-doc-modal-card h3{
  margin:0 0 8px !important;
  color:#172033 !important;
  font-size:18px !important;
}

#mealDocumentDeleteModal .meal-doc-modal-card p{
  margin:0 0 20px !important;
  color:#64748b !important;
  font-size:13px !important;
  line-height:1.6 !important;
}

#mealDocumentDeleteModal .meal-doc-modal-actions{
  display:flex !important;
  align-items:center !important;
  justify-content:center !important;
  gap:10px !important;
  margin-top:4px !important;
}

#mealDocumentDeleteModal .meal-doc-modal-actions .btn{
  min-width:110px !important;
  justify-content:center !important;
}

#mealDocumentDeleteModal .btn-danger{
  background:#dc2626 !important;
  border-color:#dc2626 !important;
  color:#fff !important;
}

#mealDocumentDeleteModal .btn-danger:hover{
  background:#b91c1c !important;
  border-color:#b91c1c !important;
}

body.meal-doc-modal-open{
  overflow:hidden !important;
}

@media(max-width:640px){
  body > #mealDocumentDeleteModal.meal-doc-modal{
    padding:12px !important;
    align-items:flex-end !important;
  }

  #mealDocumentDeleteModal .meal-doc-modal-card{
    width:100% !important;
    border-radius:16px 16px 0 0 !important;
  }

  #mealDocumentDeleteModal .meal-doc-modal-actions{
    flex-direction:column-reverse !important;
  }

  #mealDocumentDeleteModal .meal-doc-modal-actions .btn{
    width:100% !important;
  }
}

</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">
  <header class="admin-topbar">
    <div class="topbar-left">
      <div class="topbar-breadcrumb"><a href="index.php">Dashboard</a> > <strong>M&amp;E Report</strong></div>
    </div>
    <div class="topbar-right">
      <a href="<?= SITE_URL ?>" target="_blank" class="btn btn-secondary btn-sm"><i class="fa fa-eye"></i> View Site</a>
      <div class="admin-avatar">
        <div class="avatar-circle"><?= strtoupper(substr($ADMIN['full_name'],0,1)) ?></div>
      </div>
    </div>
  </header>

  <div class="admin-content">
    <?php show_flash('admin_flash'); ?>
    <?php if (!empty($_SESSION['meal_report_success'])): ?>
      <div class="alert alert-success"><?= h((string)$_SESSION['meal_report_success']) ?></div>
      <?php unset($_SESSION['meal_report_success']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['meal_report_error'])): ?>
      <div class="alert alert-error"><?= h((string)$_SESSION['meal_report_error']) ?></div>
      <?php unset($_SESSION['meal_report_error']); ?>
    <?php endif; ?>

    <div class="page-header legacy-style-905d8b3da3">
      <div>
        <h1 class="page-title">M&amp;E Impact Report</h1>
        <p class="page-subtitle">Mastercard Foundation EdTech Fellowship - Monitoring, Evaluation &amp; Learning</p>
      </div>
    </div>

    <!-- -- Filter Bar -- -->
    <form method="GET" action="">
      <input type="hidden" name="tab" value="<?= h($tab) ?>">
      <input type="hidden" name="page" value="1">
      <div class="filter-bar">
        <div class="fb-group">
          <label>Venture</label>
          <select name="venture_id" class="form-control">
            <option value="0">All Ventures</option>
            <?php while ($v = $ventures->fetch_assoc()): ?>
              <option value="<?= $v['id'] ?>" <?= $f_venture===$v['id']?'selected':'' ?>><?= h($v['name']) ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="fb-group">
          <label>Cohort</label>
          <select name="cohort_id" class="form-control">
            <option value="0">All Cohorts</option>
            <?php while ($c = $cohorts->fetch_assoc()): ?>
              <option value="<?= $c['id'] ?>" <?= $f_cohort===$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="fb-group">
          <label>From</label>
          <input type="date" name="from" class="form-control" value="<?= h($f_from) ?>">
        </div>
        <div class="fb-group">
          <label>To</label>
          <input type="date" name="to" class="form-control" value="<?= h($f_to) ?>">
        </div>
        <div class="fb-group">
          <label>Rows</label>
          <select name="per_page" class="form-control">
            <?php foreach ($meal_per_page_options as $mealRows): ?>
              <option value="<?= $mealRows ?>" <?= $meal_per_page === $mealRows ? 'selected' : '' ?>>
                <?= $mealRows ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fa fa-filter"></i> Filter</button>
        <a href="meal-report" class="btn btn-secondary"><i class="fa fa-times"></i> Clear</a>
      </div>
    </form>

    <!-- -- KPI Row -- -->
    <div class="kpi-grid">
      <div class="kpi"><div class="val"><?= number_format($s['total']) ?></div><div class="lbl">Total Learners Reached</div></div>
      <div class="kpi <?= $pct($s['female'],$s['pct_denominator']) >= 70 ? 'ok' : 'warn' ?>">
        <div class="val"><?= $pct($s['female'],$s['pct_denominator']) ?>%</div>
        <div class="lbl">Female New Learners (Target 70%)</div>
      </div>
      <div class="kpi <?= $pct($s['refugee'],$s['pct_denominator']) >= 15 ? 'ok' : 'warn' ?>">
        <div class="val"><?= $pct($s['refugee'],$s['pct_denominator']) ?>%</div>
        <div class="lbl">Refugees (Target 15%)</div>
      </div>
      <div class="kpi <?= $pct($s['pwd'],$s['pct_denominator']) >= 15 ? 'ok' : 'warn' ?>">
        <div class="val"><?= $pct($s['pwd'],$s['pct_denominator']) ?>%</div>
        <div class="lbl">PWDs (Target 15%)</div>
      </div>
      <div class="kpi"><div class="val"><?= number_format($s['new_learners']) ?></div><div class="lbl">New Learners</div></div>
      <div class="kpi"><div class="val"><?= number_format($s['teachers']) ?></div><div class="lbl">Teachers/Educators Reached</div></div>
      <div class="kpi"><div class="val"><?= number_format($s['schools']) ?></div><div class="lbl">Schools Reached</div></div>
      <div class="kpi"><div class="val"><?= number_format($s['other']) ?></div><div class="lbl">Other Users Reached</div></div>
      <div class="kpi"><div class="val"><?= number_format($s['emp']) ?></div><div class="lbl">Employees Tracked</div></div>
      <div class="kpi"><div class="val">$<?= number_format($s['fin_usd'],0) ?></div><div class="lbl">Finance Raised (USD)</div></div>
      <div class="kpi"><div class="val"><?= number_format($s['partners']) ?></div><div class="lbl">Partnerships</div></div>
      <div class="kpi"><div class="val">UGX <?= $s['rev_ugx'] >= 1e9 ? round($s['rev_ugx']/1e9,1).'B' : ($s['rev_ugx'] >= 1e6 ? round($s['rev_ugx']/1e6,1).'M' : number_format($s['rev_ugx'],0)) ?></div><div class="lbl">Cumulative Revenue</div></div>
      <div class="kpi"><div class="val"><?= $s['ventures_rep'] ?></div><div class="lbl">Ventures Reporting</div></div>

      <button
        type="button"
        class="kpi meal-yiw-kpi"
        data-yiw-modal-trigger="1"
        data-venture-name="All selected ventures"
        data-new-self="<?= (int)$yiw['new_self'] ?>"
        data-additional-self="<?= (int)$yiw['additional_self'] ?>"
        data-improved-self="<?= (int)$yiw['improved_self'] ?>"
        data-new-wage="<?= (int)$yiw['new_wage'] ?>"
        data-additional-wage="<?= (int)$yiw['additional_wage'] ?>"
        data-improved-wage="<?= (int)$yiw['improved_wage'] ?>"
        title="Open Youth in Work outcome breakdown"
      >
        <div class="val"><?= number_format((int)$yiw['total_outcomes']) ?></div>
        <div class="lbl">Youth in Work (YIW) Outcomes</div>
        <div class="meal-yiw-view"><i class="fa fa-eye"></i> View breakdown</div>
      </button>
    </div>
    <p class="legacy-style-733a79c0ba">
      Percentage indicators below use <strong>New Learners</strong> (Enrollment Category = New) as the denominator,
      falling back to Total Learners where no records have an Enrollment Category set yet.
    </p>

    <!-- -- Compliance Targets -- -->
    <?php if ($s['pct_denominator'] > 0): ?>
    <div class="compliance">
      <?php
        $checks = [
            ['label'=>'Female New Learners', 'pct'=>$pct($s['female'],$s['pct_denominator']), 'target'=>70],
            ['label'=>'New Learner Refugees',    'pct'=>$pct($s['refugee'],$s['pct_denominator']),'target'=>15],
            ['label'=>'New Learners with PWD',        'pct'=>$pct($s['pwd'],$s['pct_denominator']),    'target'=>15],
            ['label'=>'New Learner Youth 18-34', 'pct'=>$pct($s['youth'],$s['pct_denominator']),  'target'=>70],
        ];
        foreach ($checks as $c):
            $diff = $c['pct'] - $c['target'];
            $cls  = $diff >= 0 ? 'ok' : ($diff >= -10 ? 'warn' : 'bad');
            $icon = $diff >= 0 ? 'check-circle' : 'exclamation-circle';
      ?>
      <span class="cpill <?= $cls ?>">
        <i class="fa fa-<?= $icon ?>"></i>
        <?= $c['label'] ?>: <?= $c['pct'] ?>% / <?= $c['target'] ?>% target
        <?= $diff >= 0 ? '(+'.$diff.'%)' : '('.$diff.'%)' ?>
      </span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- -- Tabs -- -->
    <div class="meal-tabs">
      <?php $tabs_menu = ['overview'=>'Overview','participants'=>'Learners','teachers'=>'Teachers/Educators',
                          'schools'=>'Schools','other_users'=>'Other Users','employment'=>'Employment',
                          'finance'=>'Finance','partnerships'=>'Partnerships','revenue'=>'Revenue','documents'=>'Supporting Documents']; ?>
      <?php foreach ($tabs_menu as $k => $l): ?>
      <button class="meal-tab <?= $tab===$k?'active':'' ?>" onclick="switchTab('<?= $k ?>', this)">
        <?= h($l) ?>
      </button>
      <?php endforeach; ?>
    </div>


    <!-- ------- OVERVIEW -------------------------------------- -->
    <div class="meal-panel <?= $tab==='overview'?'active':'' ?>" id="panel-overview">
      <h3 class="legacy-style-302d703f0b">Per-Venture Progress</h3><p class="meal-admin-note">Administrators can independently block venture users from editing or deleting their M&amp;E data. Admin reporting and bulk management remain available.</p>

      <div class="export-bar">
        <a href="<?= export_url('overview', 'participants', 'csv', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp"><i class="fa fa-file-csv"></i> All Learners</a>
        <a href="<?= export_url('overview', 'participants', 'pdf', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp btn-exp-pdf"><i class="fa fa-file-pdf"></i> All Learners</a>
        <a href="<?= export_url('overview', 'teachers', 'csv', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp"><i class="fa fa-file-csv"></i> Teachers/Educators</a>
        <a href="<?= export_url('overview', 'teachers', 'pdf', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp btn-exp-pdf"><i class="fa fa-file-pdf"></i> Teachers/Educators</a>
        <a href="<?= export_url('overview', 'schools', 'csv', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp"><i class="fa fa-file-csv"></i> Schools</a>
        <a href="<?= export_url('overview', 'schools', 'pdf', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp btn-exp-pdf"><i class="fa fa-file-pdf"></i> Schools</a>
        <a href="<?= export_url('overview', 'other_users', 'csv', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp"><i class="fa fa-file-csv"></i> Other Users </a>
        <a href="<?= export_url('overview', 'other_users', 'pdf', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp btn-exp-pdf"><i class="fa fa-file-pdf"></i> Other Users</a>
        <a href="<?= export_url('overview', 'employment', 'csv', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp"><i class="fa fa-file-csv"></i> Employment</a>
        <a href="<?= export_url('overview', 'employment', 'pdf', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp btn-exp-pdf"><i class="fa fa-file-pdf"></i> Employment</a>
        <a href="<?= export_url('overview', 'finance', 'csv', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp"><i class="fa fa-file-csv"></i> Finance</a>
        <a href="<?= export_url('overview', 'finance', 'pdf', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp btn-exp-pdf"><i class="fa fa-file-pdf"></i> Finance</a>
        <a href="<?= export_url('overview', 'partnerships', 'csv', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp"><i class="fa fa-file-csv"></i> Partnerships</a>
        <a href="<?= export_url('overview', 'partnerships', 'pdf', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp btn-exp-pdf"><i class="fa fa-file-pdf"></i> Partnerships </a>
        <a href="<?= export_url('overview', 'revenue', 'csv', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp"><i class="fa fa-file-csv"></i> Revenue</a>
        <a href="<?= export_url('overview', 'revenue', 'pdf', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp btn-exp-pdf"><i class="fa fa-file-pdf"></i> Revenue </a>
      </div>

      <div class="legacy-style-03359ca378">
        <table class="vt-table">
          <thead>
            <tr>
              <th>Venture</th>
              <th>Total Learners</th><th>New Learners</th>
              <th>Female %</th>
              <th>Refugees %</th>
              <th>PWDs %</th>
              <th>Youth %</th>
              <th>YIW Outcomes</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php while ($vr = $venture_summary->fetch_assoc()):
            $tot  = (int)$vr['total_learners'];
            $new  = (int)$vr['new_learners'];
            // Same fallback as the KPI row above: if this venture hasn't
            // tagged anyone as "New" yet, use its total learner count so
            // the row doesn't show a misleading 0% across the board.
            $pct_denom = $new > 0 ? $new : $tot;
            $f_p  = $pct($vr['female'],   $pct_denom);
            $r_p  = $pct($vr['refugees'], $pct_denom);
            $p_p  = $pct($vr['pwds'],     $pct_denom);
            $y_p  = $pct($vr['youth'],    $pct_denom);

            $ventureYiwTotal =
                (int)($vr['yiw_new_self'] ?? 0)
                + (int)($vr['yiw_additional_self'] ?? 0)
                + (int)($vr['yiw_improved_self'] ?? 0)
                + (int)($vr['yiw_new_wage'] ?? 0)
                + (int)($vr['yiw_additional_wage'] ?? 0)
                + (int)($vr['yiw_improved_wage'] ?? 0);
          ?>
          <tr>
            <td class="meal-venture-name"><strong><?= h($vr['name']) ?></strong></td>
            <td><?= number_format($tot) ?></td>
            <td><?= number_format($new) ?></td>
            <td>
              <div class="legacy-style-b88d1817be">
                <div class="prog-wrap">
                  <div class="prog-bar <?= $f_p>=70?'ok':($f_p>=50?'warn':'bad') ?>"
                       style="width:<?= min(100,$f_p) ?>%"></div>
                </div>
                <span><?= $f_p ?>%</span>
              </div>
            </td>
            <td>
              <div class="legacy-style-b88d1817be">
                <div class="prog-wrap">
                  <div class="prog-bar <?= $r_p>=15?'ok':($r_p>=8?'warn':'bad') ?>"
                       style="width:<?= min(100,$r_p) ?>%"></div>
                </div>
                <span><?= $r_p ?>%</span>
              </div>
            </td>
            <td>
              <div class="legacy-style-b88d1817be">
                <div class="prog-wrap">
                  <div class="prog-bar <?= $p_p>=15?'ok':($p_p>=8?'warn':'bad') ?>"
                       style="width:<?= min(100,$p_p) ?>%"></div>
                </div>
                <span><?= $p_p ?>%</span>
              </div>
            </td>
            <td><?= $y_p ?>%</td>
            <td>
              <button
                type="button"
                class="meal-yiw-row-btn"
                data-yiw-modal-trigger="1"
                data-venture-name="<?= h($vr['name']) ?>"
                data-new-self="<?= (int)($vr['yiw_new_self'] ?? 0) ?>"
                data-additional-self="<?= (int)($vr['yiw_additional_self'] ?? 0) ?>"
                data-improved-self="<?= (int)($vr['yiw_improved_self'] ?? 0) ?>"
                data-new-wage="<?= (int)($vr['yiw_new_wage'] ?? 0) ?>"
                data-additional-wage="<?= (int)($vr['yiw_additional_wage'] ?? 0) ?>"
                data-improved-wage="<?= (int)($vr['yiw_improved_wage'] ?? 0) ?>"
                title="View YIW outcomes for <?= h($vr['name']) ?>"
              >
                <strong><?= number_format($ventureYiwTotal) ?></strong>
                <span>View</span>
              </button>
            </td>
            <td class="meal-actions-cell">
              <?php
                $ventureId = (int)$vr['id'];
                $access = $mealControls[$ventureId] ?? ['can_edit'=>1,'can_delete'=>1];
                $editAllowed = (int)$access['can_edit'] === 1;
                $deleteAllowed = (int)$access['can_delete'] === 1;
              ?>

              <div class="meal-row-actions">

                <a
                  href="?tab=participants&venture_id=<?= $ventureId ?>"
                  class="meal-icon-action neutral"
                  title="View venture M&amp;E records"
                  aria-label="View venture M&E records"
                >
                  <i class="fa fa-list"></i>
                </a>

                <span
                  class="meal-icon-status <?= $editAllowed ? 'allowed' : 'blocked' ?>"
                  title="Editing is currently <?= $editAllowed ? 'allowed' : 'blocked' ?> for this venture"
                  aria-label="Editing <?= $editAllowed ? 'allowed' : 'blocked' ?>"
                >
                  <i class="fa <?= $editAllowed ? 'fa-pen' : 'fa-lock' ?>"></i>
                </span>

                <span
                  class="meal-icon-status <?= $deleteAllowed ? 'allowed' : 'blocked' ?>"
                  title="Deleting is currently <?= $deleteAllowed ? 'allowed' : 'blocked' ?> for this venture"
                  aria-label="Deleting <?= $deleteAllowed ? 'allowed' : 'blocked' ?>"
                >
                  <i class="fa <?= $deleteAllowed ? 'fa-trash' : 'fa-lock' ?>"></i>
                </span>

                <form
                  method="POST"
                  action="includes/process-meal-report.php"
                  class="meal-lock-form"
                >
                  <input type="hidden" name="action" value="set_venture_access">
                  <input type="hidden" name="token" value="<?= h($mealReportToken) ?>">
                  <input type="hidden" name="venture_id" value="<?= $ventureId ?>">
                  <input type="hidden" name="permission" value="edit">
                  <input type="hidden" name="allow" value="<?= $editAllowed ? 0 : 1 ?>">
                  <input type="hidden" name="return_to" value="<?= h($_SERVER['REQUEST_URI'] ?? '/admin/meal-report') ?>">

                  <button
                    type="submit"
                    class="meal-icon-action <?= $editAllowed ? 'danger' : 'success' ?>"
                    title="<?= $editAllowed ? 'Block venture from editing M&E data' : 'Allow venture to edit M&E data' ?>"
                    aria-label="<?= $editAllowed ? 'Block edit' : 'Allow edit' ?>"
                  >
                    <i class="fa <?= $editAllowed ? 'fa-user-lock' : 'fa-user-check' ?>"></i>
                  </button>
                </form>

                <form
                  method="POST"
                  action="includes/process-meal-report.php"
                  class="meal-lock-form"
                >
                  <input type="hidden" name="action" value="set_venture_access">
                  <input type="hidden" name="token" value="<?= h($mealReportToken) ?>">
                  <input type="hidden" name="venture_id" value="<?= $ventureId ?>">
                  <input type="hidden" name="permission" value="delete">
                  <input type="hidden" name="allow" value="<?= $deleteAllowed ? 0 : 1 ?>">
                  <input type="hidden" name="return_to" value="<?= h($_SERVER['REQUEST_URI'] ?? '/admin/meal-report') ?>">

                  <button
                    type="submit"
                    class="meal-icon-action <?= $deleteAllowed ? 'danger' : 'success' ?>"
                    title="<?= $deleteAllowed ? 'Block venture from deleting M&E data' : 'Allow venture to delete M&E data' ?>"
                    aria-label="<?= $deleteAllowed ? 'Block delete' : 'Allow delete' ?>"
                  >
                    <i class="fa <?= $deleteAllowed ? 'fa-trash-alt' : 'fa-trash-restore' ?>"></i>
                  </button>
                </form>

              </div>
            </td>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div><!-- /overview -->


    <!-- ------- LEARNERS --------------------------------------- -->
    <div class="meal-panel <?= $tab==='participants'?'active':'' ?>" id="panel-participants">
      <div class="export-bar">
        <a href="<?= export_url('participants', 'participants', 'csv', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp"><i class="fa fa-file-csv"></i> Export CSV</a>
        <a href="<?= export_url('participants', 'participants', 'pdf', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp btn-exp-pdf"><i class="fa fa-file-pdf"></i> Export PDF</a>
      </div>
      
      <form method="POST" action="includes/process-meal-report.php" id="mealBulkParticipants" class="meal-bulk-toolbar" data-total-matching="<?= (int)$s['total'] ?>" onsubmit="return mealConfirmBulkDelete(this);">
        <input type="hidden" name="action" value="bulk_delete">
        <input type="hidden" name="data_type" value="participants">
        <input type="hidden" name="token" value="<?= h($mealReportToken) ?>">
        <input type="hidden" name="venture_id_scope" value="<?= (int)$f_venture ?>">
        <input type="hidden" name="cohort_id_scope" value="<?= (int)$f_cohort ?>">
        <input type="hidden" name="from" value="<?= h($f_from) ?>">
        <input type="hidden" name="to" value="<?= h($f_to) ?>">
        <input type="hidden" name="select_all_matching" value="0" class="meal-select-all-matching-flag">
        <input type="hidden" name="return_to" value="<?= h($_SERVER['REQUEST_URI'] ?? '/admin/meal-report') ?>">
        <div class="meal-bulk-left">
          <label><input type="checkbox" class="meal-check meal-select-all" data-form="mealBulkParticipants"> Select all visible</label>
          <span class="meal-selected-count" data-count-for="mealBulkParticipants">0 selected</span>
          <button type="button" class="meal-select-all-matching-btn" data-form="mealBulkParticipants" style="display:none;">
            Select all <span class="meal-total-matching-num"></span> matching records
          </button>
          <span class="meal-select-all-matching-active" data-form="mealBulkParticipants" style="display:none;">
            All <strong class="meal-total-matching-num2"></strong> matching records selected.
            <button type="button" class="meal-clear-select-all" data-form="mealBulkParticipants">Clear</button>
          </span>
        </div>
        <div class="meal-bulk-right">
          <button type="submit" class="btn btn-danger btn-sm meal-bulk-delete-btn meal-empty-selection"><i class="fa fa-trash"></i> Delete Selected</button>
        </div>
      </form>

      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr><th><input type="checkbox" class="meal-check meal-select-all" data-form="mealBulkParticipants" aria-label="Select all"></th>
              <th>Venture</th><th>Date</th><th>Learner No</th><th>Name</th><th>Phone</th><th>Email</th><th>Enrollment Category</th>
              <th>Gender</th><th>Location</th><th>Urban/Rural</th><th>Age of Learner</th>
              <th>Refugee</th><th>Refugee Settlement</th><th>PWD</th>
              <th>Education Level</th><th>Learner Status</th><th>Verified Learner Outcomes</th><th>Other Verified Learner Outcome</th>
              <th>YIW Before</th><th>After-work Status</th>
              <th>Through</th><th>Created At</th><th>Updated At</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $recent_ben->data_seek(0);
            while ($r = $recent_ben->fetch_assoc()): ?>
            <tr>
              <td><input type="checkbox" class="meal-check meal-row-check" form="mealBulkParticipants" name="record_ids[]" value="<?= (int)$r['id'] ?>" aria-label="Select record"></td>
              <td><?= h($r['venture_name']) ?></td>
              <td><?= h(date('d/m/Y',strtotime($r['entry_date']))) ?></td>
              <td><code class="legacy-style-11a508128d"><?= h($r['user_number']) ?></code></td>
              <td><?= h($r['full_name'] ?: '-') ?></td>
              <td><?= h($r['phone'] ?: '-') ?></td>
              <td><?= h($r['email'] ?: '-') ?></td>
              <td><?= h(($r['enrollment_category'] ?? '') ?: '-') ?></td>
              <td><span class="badge-tag <?= $r['gender']==='Female'?'badge-f':'badge-m' ?>"><?= h($r['gender']) ?></span></td>
              <td><?= h($r['specific_location'] ?: '-') ?></td>
              <td><?= h($r['location_type'] ?: '-') ?></td>
              <td><?= h($r['age_category']) ?></td>
              <td>
                <span class="badge-tag <?= $r['refugee_status']==='Yes'?'badge-yes':'badge-no' ?>">
                  <?= $r['refugee_status'] === 'Yes' ? 'Yes' : 'No' ?>
                </span>
              </td>
              <td><?= h($r['refugee_status'] === 'Yes' ? ($r['refugee_settlement'] ?: '-') : '-') ?></td>
              <td><span class="badge-tag <?= $r['pwd_status']==='Yes'?'badge-yes':'badge-no' ?>"><?= $r['pwd_status'] === 'Yes' ? 'Yes' : 'No' ?></span></td>
              <td><?= h($r['education_level'] ?: '-') ?></td>
              <td><span style="font-size:11px;font-weight:600;color:<?= $r['user_status']==='New User'?'#1d4ed8':'#5b21b6' ?>"><?= h(learner_status_label($r['user_status'] ?? '')) ?></span></td>
              <td class="meal-wrap-cell"><?= h(($r['verified_learner_outcome'] ?? '') ?: '-') ?></td>
              <td class="meal-wrap-cell"><?= h(($r['verified_learner_outcome_other'] ?? '') ?: '-') ?></td>
              <td><?= h($r['working_at_entry'] ?: '-') ?></td>
              <td><?= h($r['in_work_status'] ?: '-') ?></td>
              <td><?= h(($r['after_work_pathway'] ?? '') ?: '-') ?></td>
              <td><?= h(fmt_created($r['created_at'] ?? null)) ?></td>
              <td><?= h(fmt_created($r['updated_at'] ?? null)) ?></td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
        <?php if ($s['total'] === 0): ?>
        <div class="legacy-style-55b117929b">No learner data found for the selected filters.</div>
        <?php endif; ?>
  
      <?= meal_pagination_html(
          (int)($s['total']),
          $meal_page,
          $meal_per_page,
          'participants',
          $f_venture,
          $f_cohort,
          $f_from,
          $f_to
      ) ?>
    </div>
      
    </div>


    <!-- ------- TEACHERS / EDUCATORS REACHED ------------------- -->
    <div class="meal-panel <?= $tab==='teachers'?'active':'' ?>" id="panel-teachers">
      <div class="export-bar">
        <a href="<?= export_url('teachers', 'teachers', 'csv', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp"><i class="fa fa-file-csv"></i> Export CSV</a>
        <a href="<?= export_url('teachers', 'teachers', 'pdf', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp btn-exp-pdf"><i class="fa fa-file-pdf"></i> Export PDF</a>
      </div>

      <div class="kpi-grid legacy-style-87c136dfd0">
        <div class="kpi"><div class="val"><?= number_format($s['teachers']) ?></div><div class="lbl">Total Reached</div></div>
        <div class="kpi"><div class="val"><?= number_format($s['teachers_f']) ?></div><div class="lbl">Female</div></div>
        <div class="kpi"><div class="val"><?= number_format($s['teachers_youth']) ?></div><div class="lbl">Youth (Under 35)</div></div>
        <div class="kpi"><div class="val"><?= number_format($s['teachers_refugee']) ?></div><div class="lbl">Refugees/Displaced</div></div>
        <div class="kpi"><div class="val"><?= number_format($s['teachers_host']) ?></div><div class="lbl">Host Community</div></div>
        <div class="kpi"><div class="val"><?= number_format($s['teachers_pwd']) ?></div><div class="lbl">PWDs</div></div>
      </div>

      
      <form method="POST" action="includes/process-meal-report.php" id="mealBulkTeachers" class="meal-bulk-toolbar" data-total-matching="<?= (int)$s['teachers'] ?>" onsubmit="return mealConfirmBulkDelete(this);">
        <input type="hidden" name="action" value="bulk_delete">
        <input type="hidden" name="data_type" value="teachers">
        <input type="hidden" name="token" value="<?= h($mealReportToken) ?>">
        <input type="hidden" name="venture_id_scope" value="<?= (int)$f_venture ?>">
        <input type="hidden" name="cohort_id_scope" value="<?= (int)$f_cohort ?>">
        <input type="hidden" name="from" value="<?= h($f_from) ?>">
        <input type="hidden" name="to" value="<?= h($f_to) ?>">
        <input type="hidden" name="select_all_matching" value="0" class="meal-select-all-matching-flag">
        <input type="hidden" name="return_to" value="<?= h($_SERVER['REQUEST_URI'] ?? '/admin/meal-report') ?>">
        <div class="meal-bulk-left">
          <label><input type="checkbox" class="meal-check meal-select-all" data-form="mealBulkTeachers"> Select all visible</label>
          <span class="meal-selected-count" data-count-for="mealBulkTeachers">0 selected</span>
          <button type="button" class="meal-select-all-matching-btn" data-form="mealBulkTeachers" style="display:none;">
            Select all <span class="meal-total-matching-num"></span> matching records
          </button>
          <span class="meal-select-all-matching-active" data-form="mealBulkTeachers" style="display:none;">
            All <strong class="meal-total-matching-num2"></strong> matching records selected.
            <button type="button" class="meal-clear-select-all" data-form="mealBulkTeachers">Clear</button>
          </span>
        </div>
        <div class="meal-bulk-right">
          <button type="submit" class="btn btn-danger btn-sm meal-bulk-delete-btn meal-empty-selection"><i class="fa fa-trash"></i> Delete Selected</button>
        </div>
      </form>

      <div class="data-table-wrap">
        <table class="data-table">
          <thead><tr><th><input type="checkbox" class="meal-check meal-select-all" data-form="mealBulkTeachers" aria-label="Select all"></th>
            <th>Venture</th><th>Date</th><th>Name</th><th>Gender</th><th>Age</th>
            <th>Refugee</th><th>Host Community</th><th>Urban/Rural</th><th>PWD</th><th>Created At</th>
          </tr></thead>
          <tbody>
            <?php
            $tr = $conn->query("SELECT mt.*, v.name AS venture_name {$base_t} ORDER BY mt.id DESC LIMIT {$meal_per_page} OFFSET {$meal_offset}");
            while ($r = $tr->fetch_assoc()): ?>
            <tr>
              <td><input type="checkbox" class="meal-check meal-row-check" form="mealBulkTeachers" name="record_ids[]" value="<?= (int)$r['id'] ?>" aria-label="Select record"></td>
              <td><?= h($r['venture_name']) ?></td>
              <td><?= h(date('d/m/Y',strtotime($r['entry_date']))) ?></td>
              <td><?= h($r['teacher_name'] ?: '-') ?></td>
              <td><span class="badge-tag <?= $r['gender']==='Female'?'badge-f':'badge-m' ?>"><?= h($r['gender'] ?: '-') ?></span></td>
              <td><?= h($r['age_category'] ?: '-') ?></td>
              <td><span class="badge-tag <?= $r['refugee_status']==='Yes'?'badge-yes':'badge-no' ?>"><?= h($r['refugee_status']) ?></span></td>
              <td><span class="badge-tag <?= $r['host_community']==='Yes'?'badge-yes':'badge-no' ?>"><?= h($r['host_community']) ?></span></td>
              <td><?= h($r['location_type'] ?: '-') ?></td>
              <td><span class="badge-tag <?= $r['pwd_status']==='Yes'?'badge-yes':'badge-no' ?>"><?= h($r['pwd_status']) ?></span></td>
              <td><?= h(fmt_created($r['created_at'] ?? null)) ?></td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
        <?php if ($s['teachers'] === 0): ?>
        <div class="legacy-style-55b117929b">No Teacher/Educator data found for the selected filters.</div>
        <?php endif; ?>
  
      <?= meal_pagination_html(
          (int)($s['teachers']),
          $meal_page,
          $meal_per_page,
          'teachers',
          $f_venture,
          $f_cohort,
          $f_from,
          $f_to
      ) ?>
    </div>
      
    </div>


    <!-- ------- INSTITUTIONAL LEVEL (SCHOOLS) ------------------- -->
    <div class="meal-panel <?= $tab==='schools'?'active':'' ?>" id="panel-schools">
      <div class="export-bar">
        <a href="<?= export_url('schools', 'schools', 'csv', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp"><i class="fa fa-file-csv"></i> Export CSV</a>
        <a href="<?= export_url('schools', 'schools', 'pdf', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp btn-exp-pdf"><i class="fa fa-file-pdf"></i> Export PDF</a>
      </div>

      <div class="kpi-grid legacy-style-87c136dfd0">
        <div class="kpi"><div class="val"><?= number_format($s['schools']) ?></div><div class="lbl">Total Institutions</div></div>
        <div class="kpi"><div class="val"><?= number_format($s['schools_gov']) ?></div><div class="lbl">Government-aided</div></div>
        <div class="kpi"><div class="val"><?= number_format($s['schools_private']) ?></div><div class="lbl">Private</div></div>
        <div class="kpi"><div class="val"><?= number_format($s['schools_rural']) ?></div><div class="lbl">Rural</div></div>
      </div>

      
      <form method="POST" action="includes/process-meal-report.php" id="mealBulkSchools" class="meal-bulk-toolbar" data-total-matching="<?= (int)$s['schools'] ?>" onsubmit="return mealConfirmBulkDelete(this);">
        <input type="hidden" name="action" value="bulk_delete">
        <input type="hidden" name="data_type" value="schools">
        <input type="hidden" name="token" value="<?= h($mealReportToken) ?>">
        <input type="hidden" name="venture_id_scope" value="<?= (int)$f_venture ?>">
        <input type="hidden" name="cohort_id_scope" value="<?= (int)$f_cohort ?>">
        <input type="hidden" name="from" value="<?= h($f_from) ?>">
        <input type="hidden" name="to" value="<?= h($f_to) ?>">
        <input type="hidden" name="select_all_matching" value="0" class="meal-select-all-matching-flag">
        <input type="hidden" name="return_to" value="<?= h($_SERVER['REQUEST_URI'] ?? '/admin/meal-report') ?>">
        <div class="meal-bulk-left">
          <label><input type="checkbox" class="meal-check meal-select-all" data-form="mealBulkSchools"> Select all visible</label>
          <span class="meal-selected-count" data-count-for="mealBulkSchools">0 selected</span>
          <button type="button" class="meal-select-all-matching-btn" data-form="mealBulkSchools" style="display:none;">
            Select all <span class="meal-total-matching-num"></span> matching records
          </button>
          <span class="meal-select-all-matching-active" data-form="mealBulkSchools" style="display:none;">
            All <strong class="meal-total-matching-num2"></strong> matching records selected.
            <button type="button" class="meal-clear-select-all" data-form="mealBulkSchools">Clear</button>
          </span>
        </div>
        <div class="meal-bulk-right">
          <button type="submit" class="btn btn-danger btn-sm meal-bulk-delete-btn meal-empty-selection"><i class="fa fa-trash"></i> Delete Selected</button>
        </div>
      </form>

      <div class="data-table-wrap">
        <table class="data-table">
          <thead><tr><th><input type="checkbox" class="meal-check meal-select-all" data-form="mealBulkSchools" aria-label="Select all"></th><th>Venture</th><th>Date</th><th>School / Institution</th><th>Urban/Rural</th><th>Ownership</th><th>Level</th><th>Created At</th></tr></thead>
          <tbody>
            <?php
            $sr = $conn->query("SELECT ms.*, v.name AS venture_name {$base_s} ORDER BY ms.id DESC LIMIT {$meal_per_page} OFFSET {$meal_offset}");
            while ($r = $sr->fetch_assoc()): ?>
            <tr>
              <td><input type="checkbox" class="meal-check meal-row-check" form="mealBulkSchools" name="record_ids[]" value="<?= (int)$r['id'] ?>" aria-label="Select record"></td>
              <td><?= h($r['venture_name']) ?></td>
              <td><?= h(date('d/m/Y',strtotime($r['entry_date']))) ?></td>
              <td><strong><?= h($r['school_name']) ?></strong></td>
              <td><?= h($r['location_type'] ?: '-') ?></td>
              <td><?= h($r['ownership_type'] ?: '-') ?></td>
              <td><?= h($r['school_level'] ?: '-') ?></td>
              <td><?= h(fmt_created($r['created_at'] ?? null)) ?></td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
        <?php if ($s['schools'] === 0): ?>
        <div class="legacy-style-55b117929b">No School/Institution data found for the selected filters.</div>
        <?php endif; ?>
  
      <?= meal_pagination_html(
          (int)($s['schools']),
          $meal_page,
          $meal_per_page,
          'schools',
          $f_venture,
          $f_cohort,
          $f_from,
          $f_to
      ) ?>
    </div>
      
    </div>


    <!-- ------- OTHER USERS ---------------------------------- -->
    <div class="meal-panel <?= $tab==='other_users'?'active':'' ?>" id="panel-other_users">
      <div class="export-bar">
        <a href="<?= export_url('other_users', 'other_users', 'csv', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp"><i class="fa fa-file-csv"></i> Export CSV</a>
        <a href="<?= export_url('other_users', 'other_users', 'pdf', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp btn-exp-pdf"><i class="fa fa-file-pdf"></i> Export PDF</a>
      </div>

      <div class="kpi-grid legacy-style-87c136dfd0">
        <div class="kpi"><div class="val"><?= number_format($s['other']) ?></div><div class="lbl">Total Reached</div></div>
        <div class="kpi"><div class="val"><?= number_format($s['other_f']) ?></div><div class="lbl">Female</div></div>
        <div class="kpi"><div class="val"><?= number_format($s['other_host']) ?></div><div class="lbl">Host Community</div></div>
        <div class="kpi"><div class="val"><?= number_format($s['other_pwd']) ?></div><div class="lbl">PWDs</div></div>
      </div>

      
      <form method="POST" action="includes/process-meal-report.php" id="mealBulkOtherUsers" class="meal-bulk-toolbar" data-total-matching="<?= (int)$s['other'] ?>" onsubmit="return mealConfirmBulkDelete(this);">
        <input type="hidden" name="action" value="bulk_delete">
        <input type="hidden" name="data_type" value="other_users">
        <input type="hidden" name="token" value="<?= h($mealReportToken) ?>">
        <input type="hidden" name="venture_id_scope" value="<?= (int)$f_venture ?>">
        <input type="hidden" name="cohort_id_scope" value="<?= (int)$f_cohort ?>">
        <input type="hidden" name="from" value="<?= h($f_from) ?>">
        <input type="hidden" name="to" value="<?= h($f_to) ?>">
        <input type="hidden" name="select_all_matching" value="0" class="meal-select-all-matching-flag">
        <input type="hidden" name="return_to" value="<?= h($_SERVER['REQUEST_URI'] ?? '/admin/meal-report') ?>">
        <div class="meal-bulk-left">
          <label><input type="checkbox" class="meal-check meal-select-all" data-form="mealBulkOtherUsers"> Select all visible</label>
          <span class="meal-selected-count" data-count-for="mealBulkOtherUsers">0 selected</span>
          <button type="button" class="meal-select-all-matching-btn" data-form="mealBulkOtherUsers" style="display:none;">
            Select all <span class="meal-total-matching-num"></span> matching records
          </button>
          <span class="meal-select-all-matching-active" data-form="mealBulkOtherUsers" style="display:none;">
            All <strong class="meal-total-matching-num2"></strong> matching records selected.
            <button type="button" class="meal-clear-select-all" data-form="mealBulkOtherUsers">Clear</button>
          </span>
        </div>
        <div class="meal-bulk-right">
          <button type="submit" class="btn btn-danger btn-sm meal-bulk-delete-btn meal-empty-selection"><i class="fa fa-trash"></i> Delete Selected</button>
        </div>
      </form>

      <div class="data-table-wrap">
        <table class="data-table">
          <thead><tr><th><input type="checkbox" class="meal-check meal-select-all" data-form="mealBulkOtherUsers" aria-label="Select all"></th>
            <th>Venture</th><th>Date</th><th>Gender</th>
            <th>Host Community</th><th>Urban/Rural</th><th>PWD</th>
            <th>Education</th><th>Created At</th>
          </tr></thead>
          <tbody>
            <?php
            $our = $conn->query("SELECT mo.*, v.name AS venture_name {$base_o} ORDER BY mo.id DESC LIMIT {$meal_per_page} OFFSET {$meal_offset}");
            while ($r = $our->fetch_assoc()): ?>
            <tr>
              <td><input type="checkbox" class="meal-check meal-row-check" form="mealBulkOtherUsers" name="record_ids[]" value="<?= (int)$r['id'] ?>" aria-label="Select record"></td>
              <td><?= h($r['venture_name']) ?></td>
              <td><?= h(date('d/m/Y',strtotime($r['entry_date']))) ?></td>
              <td><span class="badge-tag <?= $r['gender']==='Female'?'badge-f':'badge-m' ?>"><?= h($r['gender'] ?: '-') ?></span></td>
              <td><span class="badge-tag <?= $r['host_community']==='Yes'?'badge-yes':'badge-no' ?>"><?= h($r['host_community']) ?></span></td>
              <td><?= h($r['location_type'] ?: '-') ?></td>
              <td><span class="badge-tag <?= $r['pwd_status']==='Yes'?'badge-yes':'badge-no' ?>"><?= h($r['pwd_status']) ?></span></td>
              <td><?= h($r['education_level'] ?: '-') ?></td>
              <td><?= h(fmt_created($r['created_at'] ?? null)) ?></td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
        <?php if ($s['other'] === 0): ?>
        <div class="legacy-style-55b117929b">No Other Learner data found for the selected filters.</div>
        <?php endif; ?>
  
      <?= meal_pagination_html(
          (int)($s['other']),
          $meal_page,
          $meal_per_page,
          'other_users',
          $f_venture,
          $f_cohort,
          $f_from,
          $f_to
      ) ?>
    </div>
      
    </div>


    <!-- ------- EMPLOYMENT ------------------------------------ -->
    <div class="meal-panel <?= $tab==='employment'?'active':'' ?>" id="panel-employment">
      <div class="export-bar">
        <a href="<?= export_url('employment', 'employment', 'csv', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp"><i class="fa fa-file-csv"></i> Export CSV</a>
        <a href="<?= export_url('employment', 'employment', 'pdf', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp btn-exp-pdf"><i class="fa fa-file-pdf"></i> Export PDF</a>
      </div>
      
      <form method="POST" action="includes/process-meal-report.php" id="mealBulkEmployment" class="meal-bulk-toolbar" data-total-matching="<?= (int)$s['emp'] ?>" onsubmit="return mealConfirmBulkDelete(this);">
        <input type="hidden" name="action" value="bulk_delete">
        <input type="hidden" name="data_type" value="employment">
        <input type="hidden" name="token" value="<?= h($mealReportToken) ?>">
        <input type="hidden" name="venture_id_scope" value="<?= (int)$f_venture ?>">
        <input type="hidden" name="cohort_id_scope" value="<?= (int)$f_cohort ?>">
        <input type="hidden" name="from" value="<?= h($f_from) ?>">
        <input type="hidden" name="to" value="<?= h($f_to) ?>">
        <input type="hidden" name="select_all_matching" value="0" class="meal-select-all-matching-flag">
        <input type="hidden" name="return_to" value="<?= h($_SERVER['REQUEST_URI'] ?? '/admin/meal-report') ?>">
        <div class="meal-bulk-left">
          <label><input type="checkbox" class="meal-check meal-select-all" data-form="mealBulkEmployment"> Select all visible</label>
          <span class="meal-selected-count" data-count-for="mealBulkEmployment">0 selected</span>
          <button type="button" class="meal-select-all-matching-btn" data-form="mealBulkEmployment" style="display:none;">
            Select all <span class="meal-total-matching-num"></span> matching records
          </button>
          <span class="meal-select-all-matching-active" data-form="mealBulkEmployment" style="display:none;">
            All <strong class="meal-total-matching-num2"></strong> matching records selected.
            <button type="button" class="meal-clear-select-all" data-form="mealBulkEmployment">Clear</button>
          </span>
        </div>
        <div class="meal-bulk-right">
          <button type="submit" class="btn btn-danger btn-sm meal-bulk-delete-btn meal-empty-selection"><i class="fa fa-trash"></i> Delete Selected</button>
        </div>
      </form>

      <div class="data-table-wrap">
        <table class="data-table">
          <thead><tr><th><input type="checkbox" class="meal-check meal-select-all" data-form="mealBulkEmployment" aria-label="Select all"></th><th>Venture</th><th>Date</th><th>Name</th><th>Gender</th><th>Age</th><th>PWD</th><th>Type</th><th>Role</th><th>Status</th><th>Created At</th></tr></thead>
          <tbody>
            <?php
            $er = $conn->query("SELECT me.*, v.name AS venture_name {$base_e} ORDER BY me.id DESC LIMIT {$meal_per_page} OFFSET {$meal_offset}");
            while ($r = $er->fetch_assoc()): ?>
            <tr>
              <td><input type="checkbox" class="meal-check meal-row-check" form="mealBulkEmployment" name="record_ids[]" value="<?= (int)$r['id'] ?>" aria-label="Select record"></td>
              <td><?= h($r['venture_name']) ?></td>
              <td><?= h(date('d/m/Y',strtotime($r['placement_date']))) ?></td>
              <td><?= h($r['full_name']) ?></td>
              <td><span class="badge-tag <?= $r['gender']==='Female'?'badge-f':'badge-m' ?>"><?= h($r['gender']) ?></span></td>
              <td><?= h($r['age_category']) ?></td>
              <td><span class="badge-tag <?= $r['pwd_status']==='Yes'?'badge-yes':'badge-no' ?>"><?= h($r['pwd_status']) ?></span></td>
              <td><?= h($r['employment_type']) ?></td>
              <td><?= h($r['job_title']) ?></td>
              <td><?= h($r['status']) ?></td>
              <td><?= h(fmt_created($r['created_at'] ?? null)) ?></td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
        <?php if ($s['emp'] === 0): ?>
        <div class="legacy-style-2fde712c92">No employment data for selected filters.</div>
        <?php endif; ?>
  
      <?= meal_pagination_html(
          (int)($s['emp']),
          $meal_page,
          $meal_per_page,
          'employment',
          $f_venture,
          $f_cohort,
          $f_from,
          $f_to
      ) ?>
    </div>
    </div>


    <!-- ------- FINANCE --------------------------------------- -->
    <div class="meal-panel <?= $tab==='finance'?'active':'' ?>" id="panel-finance">
      <div class="export-bar">
        <a href="<?= export_url('finance', 'finance', 'csv', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp"><i class="fa fa-file-csv"></i> Export</a>
        <a href="<?= export_url('finance', 'finance', 'pdf', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp btn-exp-pdf"><i class="fa fa-file-pdf"></i> Export</a>
      </div>
      
      <form method="POST" action="includes/process-meal-report.php" id="mealBulkFinance" class="meal-bulk-toolbar" data-total-matching="<?= (int)$fin_total_count ?>" onsubmit="return mealConfirmBulkDelete(this);">
        <input type="hidden" name="action" value="bulk_delete">
        <input type="hidden" name="data_type" value="finance">
        <input type="hidden" name="token" value="<?= h($mealReportToken) ?>">
        <input type="hidden" name="venture_id_scope" value="<?= (int)$f_venture ?>">
        <input type="hidden" name="cohort_id_scope" value="<?= (int)$f_cohort ?>">
        <input type="hidden" name="from" value="<?= h($f_from) ?>">
        <input type="hidden" name="to" value="<?= h($f_to) ?>">
        <input type="hidden" name="select_all_matching" value="0" class="meal-select-all-matching-flag">
        <input type="hidden" name="return_to" value="<?= h($_SERVER['REQUEST_URI'] ?? '/admin/meal-report') ?>">
        <div class="meal-bulk-left">
          <label><input type="checkbox" class="meal-check meal-select-all" data-form="mealBulkFinance"> Select all visible</label>
          <span class="meal-selected-count" data-count-for="mealBulkFinance">0 selected</span>
          <button type="button" class="meal-select-all-matching-btn" data-form="mealBulkFinance" style="display:none;">
            Select all <span class="meal-total-matching-num"></span> matching records
          </button>
          <span class="meal-select-all-matching-active" data-form="mealBulkFinance" style="display:none;">
            All <strong class="meal-total-matching-num2"></strong> matching records selected.
            <button type="button" class="meal-clear-select-all" data-form="mealBulkFinance">Clear</button>
          </span>
        </div>
        <div class="meal-bulk-right">
          <button type="submit" class="btn btn-danger btn-sm meal-bulk-delete-btn meal-empty-selection"><i class="fa fa-trash"></i> Delete Selected</button>
        </div>
      </form>

      <div class="data-table-wrap">
        <table class="data-table">
          <thead><tr><th><input type="checkbox" class="meal-check meal-select-all" data-form="mealBulkFinance" aria-label="Select all"></th><th>Venture</th><th>Date</th><th>Amount USD</th><th>Form</th><th>Source</th><th>Created At</th></tr></thead>
          <tbody>
            <?php
            $fr = $conn->query("SELECT mf.*, v.name AS venture_name {$base_f} ORDER BY mf.id DESC LIMIT {$meal_per_page} OFFSET {$meal_offset}");
            while ($r = $fr->fetch_assoc()): ?>
            <tr>
              <td><input type="checkbox" class="meal-check meal-row-check" form="mealBulkFinance" name="record_ids[]" value="<?= (int)$r['id'] ?>" aria-label="Select record"></td>
              <td><?= h($r['venture_name']) ?></td>
              <td><?= h(date('d/m/Y',strtotime($r['date_mobilised']))) ?></td>
              <td><strong>$<?= number_format($r['finance_usd'],2) ?></strong></td>
              <td><?= h($r['funding_form']) ?></td>
              <td><?= h($r['funding_source']) ?></td>
              <td><?= h(fmt_created($r['created_at'] ?? null)) ?></td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
        <?php if ($s['fin_usd'] === 0.0): ?>
        <div class="legacy-style-2fde712c92">No finance data for selected filters.</div>
        <?php endif; ?>
  
      <?= meal_pagination_html(
          $fin_total_count,
          $meal_page,
          $meal_per_page,
          'finance',
          $f_venture,
          $f_cohort,
          $f_from,
          $f_to
      ) ?>
    </div>
    </div>


    <!-- ------- PARTNERSHIPS ---------------------------------- -->
    <div class="meal-panel <?= $tab==='partnerships'?'active':'' ?>" id="panel-partnerships">
      <div class="export-bar">
        <a href="<?= export_url('partnerships', 'partnerships', 'csv', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp"><i class="fa fa-file-csv"></i> Export CSV</a>
        <a href="<?= export_url('partnerships', 'partnerships', 'pdf', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp btn-exp-pdf"><i class="fa fa-file-pdf"></i> Export PDF</a>
      </div>
      
      <form method="POST" action="includes/process-meal-report.php" id="mealBulkPartnerships" class="meal-bulk-toolbar" data-total-matching="<?= (int)$s['partners'] ?>" onsubmit="return mealConfirmBulkDelete(this);">
        <input type="hidden" name="action" value="bulk_delete">
        <input type="hidden" name="data_type" value="partnerships">
        <input type="hidden" name="token" value="<?= h($mealReportToken) ?>">
        <input type="hidden" name="venture_id_scope" value="<?= (int)$f_venture ?>">
        <input type="hidden" name="cohort_id_scope" value="<?= (int)$f_cohort ?>">
        <input type="hidden" name="from" value="<?= h($f_from) ?>">
        <input type="hidden" name="to" value="<?= h($f_to) ?>">
        <input type="hidden" name="select_all_matching" value="0" class="meal-select-all-matching-flag">
        <input type="hidden" name="return_to" value="<?= h($_SERVER['REQUEST_URI'] ?? '/admin/meal-report') ?>">
        <div class="meal-bulk-left">
          <label><input type="checkbox" class="meal-check meal-select-all" data-form="mealBulkPartnerships"> Select all visible</label>
          <span class="meal-selected-count" data-count-for="mealBulkPartnerships">0 selected</span>
          <button type="button" class="meal-select-all-matching-btn" data-form="mealBulkPartnerships" style="display:none;">
            Select all <span class="meal-total-matching-num"></span> matching records
          </button>
          <span class="meal-select-all-matching-active" data-form="mealBulkPartnerships" style="display:none;">
            All <strong class="meal-total-matching-num2"></strong> matching records selected.
            <button type="button" class="meal-clear-select-all" data-form="mealBulkPartnerships">Clear</button>
          </span>
        </div>
        <div class="meal-bulk-right">
          <button type="submit" class="btn btn-danger btn-sm meal-bulk-delete-btn meal-empty-selection"><i class="fa fa-trash"></i> Delete Selected</button>
        </div>
      </form>

      <div class="data-table-wrap">
        <table class="data-table">
          <thead><tr><th><input type="checkbox" class="meal-check meal-select-all" data-form="mealBulkPartnerships" aria-label="Select all"></th><th>Venture</th><th>Date</th><th>Partner</th><th>Type</th><th>Status</th><th>Created At</th></tr></thead>
          <tbody>
            <?php
            $pr = $conn->query("SELECT mp.*, v.name AS venture_name {$base_p} ORDER BY mp.id DESC LIMIT {$meal_per_page} OFFSET {$meal_offset}");
            while ($r = $pr->fetch_assoc()): ?>
            <tr>
              <td><input type="checkbox" class="meal-check meal-row-check" form="mealBulkPartnerships" name="record_ids[]" value="<?= (int)$r['id'] ?>" aria-label="Select record"></td>
              <td><?= h($r['venture_name']) ?></td>
              <td><?= h(date('d/m/Y',strtotime($r['partner_date']))) ?></td>
              <td><strong><?= h($r['partner_name']) ?></strong></td>
              <td><?= h($r['partnership_type']) ?></td>
              <td><span class="badge-tag <?= $r['partnership_status']==='Active'?'badge-yes':'badge-no' ?>"><?= h($r['partnership_status']) ?></span></td>
              <td><?= h(fmt_created($r['created_at'] ?? null)) ?></td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
        <?php if ($s['partners'] === 0): ?>
        <div class="legacy-style-2fde712c92">No partnerships for selected filters.</div>
        <?php endif; ?>
  
      <?= meal_pagination_html(
          (int)($s['partners']),
          $meal_page,
          $meal_per_page,
          'partnerships',
          $f_venture,
          $f_cohort,
          $f_from,
          $f_to
      ) ?>
    </div>
    </div>


    <!-- ------- REVENUE --------------------------------------- -->
    <div class="meal-panel <?= $tab==='revenue'?'active':'' ?>" id="panel-revenue">
      <div class="export-bar">
        <a href="<?= export_url('revenue', 'revenue', 'csv', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp"><i class="fa fa-file-csv"></i> Export CSV</a>
        <a href="<?= export_url('revenue', 'revenue', 'pdf', $f_venture, $f_cohort, $f_from, $f_to) ?>"
           class="btn-exp btn-exp-pdf"><i class="fa fa-file-pdf"></i> Export PDF</a>
      </div>
      
      <form method="POST" action="includes/process-meal-report.php" id="mealBulkRevenue" class="meal-bulk-toolbar" data-total-matching="<?= (int)$rev_total_count ?>" onsubmit="return mealConfirmBulkDelete(this);">
        <input type="hidden" name="action" value="bulk_delete">
        <input type="hidden" name="data_type" value="revenue">
        <input type="hidden" name="token" value="<?= h($mealReportToken) ?>">
        <input type="hidden" name="venture_id_scope" value="<?= (int)$f_venture ?>">
        <input type="hidden" name="cohort_id_scope" value="<?= (int)$f_cohort ?>">
        <input type="hidden" name="from" value="<?= h($f_from) ?>">
        <input type="hidden" name="to" value="<?= h($f_to) ?>">
        <input type="hidden" name="select_all_matching" value="0" class="meal-select-all-matching-flag">
        <input type="hidden" name="return_to" value="<?= h($_SERVER['REQUEST_URI'] ?? '/admin/meal-report') ?>">
        <div class="meal-bulk-left">
          <label><input type="checkbox" class="meal-check meal-select-all" data-form="mealBulkRevenue"> Select all visible</label>
          <span class="meal-selected-count" data-count-for="mealBulkRevenue">0 selected</span>
          <button type="button" class="meal-select-all-matching-btn" data-form="mealBulkRevenue" style="display:none;">
            Select all <span class="meal-total-matching-num"></span> matching records
          </button>
          <span class="meal-select-all-matching-active" data-form="mealBulkRevenue" style="display:none;">
            All <strong class="meal-total-matching-num2"></strong> matching records selected.
            <button type="button" class="meal-clear-select-all" data-form="mealBulkRevenue">Clear</button>
          </span>
        </div>
        <div class="meal-bulk-right">
          <button type="submit" class="btn btn-danger btn-sm meal-bulk-delete-btn meal-empty-selection"><i class="fa fa-trash"></i> Delete Selected</button>
        </div>
      </form>

      <div class="data-table-wrap">
        <table class="data-table">
          <thead><tr><th><input type="checkbox" class="meal-check meal-select-all" data-form="mealBulkRevenue" aria-label="Select all"></th><th>Venture</th><th>Month</th><th>Gross Revenue (UGX)</th><th>Stream</th><th>Created At</th></tr></thead>
          <tbody>
            <?php
            $rr = $conn->query("SELECT mr.*, v.name AS venture_name {$base_r} ORDER BY mr.revenue_date DESC LIMIT {$meal_per_page} OFFSET {$meal_offset}");
            while ($r = $rr->fetch_assoc()): ?>
            <tr>
              <td><input type="checkbox" class="meal-check meal-row-check" form="mealBulkRevenue" name="record_ids[]" value="<?= (int)$r['id'] ?>" aria-label="Select record"></td>
              <td><?= h($r['venture_name']) ?></td>
              <td><?= h(date('M Y',strtotime($r['revenue_date']))) ?></td>
              <td><strong>UGX <?= number_format($r['gross_revenue_ugx']) ?></strong></td>
              <td><?= h($r['revenue_stream'] ?: '-') ?></td>
              <td><?= h(fmt_created($r['created_at'] ?? null)) ?></td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
        <?php if ($s['rev_ugx'] === 0.0): ?>
        <div class="legacy-style-2fde712c92">No revenue data for selected filters.</div>
        <?php endif; ?>
      </div>

      <?= meal_pagination_html(
          $rev_total_count,
          $meal_page,
          $meal_per_page,
          'revenue',
          $f_venture,
          $f_cohort,
          $f_from,
          $f_to
      ) ?>

    </div>

    <!-- ------- SUPPORTING DOCUMENTS ---------------------------- -->
    <div class="meal-panel <?= $tab==='documents'?'active':'' ?>" id="panel-documents">
      <div class="meal-doc-head">
        <div>
          <h3>Supporting Documents</h3>
          <p>Evidence uploaded by ventures under each M&amp;E reporting category.</p>
        </div>
        <span class="meal-doc-count"><?= number_format($documentsTotal) ?> document<?= $documentsTotal===1?'':'s' ?></span>
      </div>

      <div class="data-table-wrap">
        <table class="data-table meal-doc-table">
          <thead>
            <tr>
              <th>Venture</th>
              <th>Category</th>
              <th>Document</th>
              <th>Description</th>
              <th>File</th>
              <th>Size</th>
              <th>Uploaded By</th>
              <th>Uploaded At</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($documentsRows instanceof mysqli_result && $documentsRows->num_rows > 0): ?>
            <?php while ($doc = $documentsRows->fetch_assoc()): ?>
              <?php
                $docCategoryLabels = [
                    'participants'=>'Learners',
                    'teachers'=>'Teachers / Educators',
                    'schools'=>'Schools / Institutions',
                    'other_users'=>'Other Users',
                    'employment'=>'Employment',
                    'finance'=>'Finance',
                    'partnerships'=>'Partnerships',
                    'revenue'=>'Revenue',
                ];
                $docCategory = (string)($doc['category'] ?? '');
                $docLabel = $docCategoryLabels[$docCategory] ?? ucwords(str_replace('_',' ',$docCategory));
                $docSize = (int)($doc['file_size'] ?? 0);
                $docSizeLabel = $docSize >= 1048576
                    ? number_format($docSize / 1048576, 2) . ' MB'
                    : number_format(max(0, $docSize) / 1024, 1) . ' KB';
              ?>
              <tr>
                <td><strong><?= h($doc['venture_name'] ?? '-') ?></strong></td>
                <td><span class="meal-doc-category"><?= h($docLabel) ?></span></td>
                <td><?= h($doc['document_name'] ?? '-') ?></td>
                <td class="meal-doc-description"><?= h($doc['description'] ?: '-') ?></td>
                <td>
                  <span class="meal-file-pill">
                    <i class="fa fa-paperclip"></i>
                    <?= h($doc['original_name'] ?? '-') ?>
                  </span>
                </td>
                <td><?= h($docSizeLabel) ?></td>
                <td><?= h($doc['uploaded_by'] ?: 'Venture') ?></td>
                <td><?= h(fmt_created($doc['created_at'] ?? null)) ?></td>
                <td>
                  <div class="meal-row-actions">
                    <a
                      class="meal-icon-action neutral"
                      href="includes/process-meal-report.php?action=download_supporting_document&amp;id=<?= (int)$doc['id'] ?>"
                      title="Download document"
                      aria-label="Download document"
                    >
                      <i class="fa fa-download"></i>
                    </a>
<button
                      type="button"
                      class="meal-icon-action danger"
                      title="Delete document"
                      aria-label="Delete document"
                      data-document-delete-trigger="1"
                      data-document-id="<?= (int)$doc['id'] ?>"
                      data-document-name="<?= h((string)($doc['document_name'] ?? 'Document')) ?>"
                    >
                      <i class="fa fa-trash"></i>
                    </button>
                  </div>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr>
              <td colspan="9" class="meal-empty-docs">
                No supporting documents found for the selected filters.
              </td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?= meal_pagination_html(
          $documentsTotal,
          $meal_page,
          $meal_per_page,
          'documents',
          $f_venture,
          $f_cohort,
          $f_from,
          $f_to
      ) ?>
    </div>

  </div><!-- /admin-content -->
</div>


<div class="meal-doc-modal" id="mealDocumentDeleteModal" aria-hidden="true" style="display:none !important;visibility:hidden !important;opacity:0 !important;pointer-events:none !important;">
  <div class="meal-doc-modal-card" role="dialog" aria-modal="true" aria-labelledby="mealDocumentDeleteTitle">
    <div class="meal-doc-modal-icon"><i class="fa fa-trash"></i></div>
    <h3 id="mealDocumentDeleteTitle">Delete supporting document?</h3>
    <p id="mealDocumentDeleteText">This document and its uploaded file will be permanently deleted.</p>
    <form method="POST" action="includes/process-meal-report.php">
      <input type="hidden" name="action" value="delete_supporting_document">
      <input type="hidden" name="token" value="<?= h($mealReportToken) ?>">
      <input type="hidden" name="document_id" id="mealDocumentDeleteId" value="">
      <input type="hidden" name="return_to" value="<?= h($_SERVER['REQUEST_URI'] ?? '/admin/meal-report?tab=documents') ?>">
      <div class="meal-doc-modal-actions">
        <button type="button" class="btn btn-outline" data-document-delete-close="1">Cancel</button>
        <button type="submit" class="btn btn-danger"><i class="fa fa-trash"></i> Delete</button>
      </div>
    </form>
  </div>
</div>


<div class="meal-admin-modal" id="mealAdminYiwModal" aria-hidden="true" style="display:none !important;visibility:hidden !important;opacity:0 !important;pointer-events:none !important;">
  <div class="meal-admin-modal-card" role="dialog" aria-modal="true" aria-labelledby="mealAdminYiwTitle">
    <div class="meal-admin-modal-head">
      <h3 id="mealAdminYiwTitle"><i class="fa fa-briefcase"></i> Youth in Work (YIW) Outcomes</h3>
      <button type="button" class="meal-admin-modal-close" data-yiw-modal-close="1" aria-label="Close">
        <i class="fa fa-times"></i>
      </button>
    </div>
    <div class="meal-admin-modal-body">
      <div class="meal-admin-yiw-intro">
        <span id="mealAdminYiwVenture">Selected report</span><br>
        Calculated automatically from learner After-work Status and pathway.
      </div>
      <div class="meal-admin-yiw-grid">
        <div class="meal-admin-yiw-item"><strong id="yiwNewSelf">0</strong><span># of YIW (New) through Self-employment</span></div>
        <div class="meal-admin-yiw-item"><strong id="yiwAdditionalSelf">0</strong><span># of YIW (Additional) through Self-employment</span></div>
        <div class="meal-admin-yiw-item"><strong id="yiwImprovedSelf">0</strong><span># of YIW (Improved) through Self-employment</span></div>
        <div class="meal-admin-yiw-item"><strong id="yiwNewWage">0</strong><span># of YIW (New) through Wage employment</span></div>
        <div class="meal-admin-yiw-item"><strong id="yiwAdditionalWage">0</strong><span># of YIW (Additional) through Wage employment</span></div>
        <div class="meal-admin-yiw-item"><strong id="yiwImprovedWage">0</strong><span># of YIW (Improved) through Wage employment</span></div>
      </div>
    </div>
    <div class="meal-admin-modal-foot">
      <button type="button" class="btn btn-secondary" data-yiw-modal-close="1">Close</button>
    </div>
  </div>
</div>

<script>
function mealYiwNumber(value){
  const number = Number(value || 0);
  return Number.isFinite(number) ? number.toLocaleString() : '0';
}

function mealOpenYiwModal(trigger){
  const modal = document.getElementById('mealAdminYiwModal');

  if(!modal || !trigger){
    console.error('Admin YIW modal or trigger not found.');
    return false;
  }

  const values = {
    yiwNewSelf: trigger.getAttribute('data-new-self'),
    yiwAdditionalSelf: trigger.getAttribute('data-additional-self'),
    yiwImprovedSelf: trigger.getAttribute('data-improved-self'),
    yiwNewWage: trigger.getAttribute('data-new-wage'),
    yiwAdditionalWage: trigger.getAttribute('data-additional-wage'),
    yiwImprovedWage: trigger.getAttribute('data-improved-wage')
  };

  Object.entries(values).forEach(function(entry){
    const id = entry[0];
    const value = entry[1];
    const el = document.getElementById(id);

    if(el){
      el.textContent = mealYiwNumber(value);
    }
  });

  const venture = document.getElementById('mealAdminYiwVenture');

  if(venture){
    venture.textContent =
      trigger.getAttribute('data-venture-name')
      || 'Selected report';
  }

  /*
   * Keep the modal outside admin wrappers that may use
   * overflow:hidden, transforms or stacking contexts.
   */
  if(modal.parentElement !== document.body){
    document.body.appendChild(modal);
  }

  modal.classList.add('open');
  modal.setAttribute('aria-hidden','false');

  modal.style.setProperty('display','flex','important');
  modal.style.setProperty('visibility','visible','important');
  modal.style.setProperty('opacity','1','important');
  modal.style.setProperty('pointer-events','auto','important');
  modal.style.setProperty('z-index','2147483646','important');

  document.body.classList.add('meal-admin-modal-open');

  const closeButton = modal.querySelector('.meal-admin-modal-close');

  if(closeButton){
    setTimeout(function(){
      try{
        closeButton.focus({preventScroll:true});
      }catch(error){
        closeButton.focus();
      }
    },30);
  }

  return true;
}

function mealCloseYiwModal(){
  const modal = document.getElementById('mealAdminYiwModal');

  if(!modal) return false;

  modal.classList.remove('open');
  modal.setAttribute('aria-hidden','true');

  modal.style.setProperty('display','none','important');
  modal.style.setProperty('visibility','hidden','important');
  modal.style.setProperty('opacity','0','important');
  modal.style.setProperty('pointer-events','none','important');

  document.body.classList.remove('meal-admin-modal-open');

  return true;
}

document.addEventListener('click', function(event){
  const trigger = event.target.closest('[data-yiw-modal-trigger="1"]');

  if(trigger){
    event.preventDefault();
    event.stopPropagation();
    mealOpenYiwModal(trigger);
    return;
  }

  const closeTrigger = event.target.closest('[data-yiw-modal-close="1"]');

  if(closeTrigger){
    event.preventDefault();
    mealCloseYiwModal();
    return;
  }

  const modal = document.getElementById('mealAdminYiwModal');
  if(modal && event.target === modal){
    mealCloseYiwModal();
  }
});

document.addEventListener('keydown', function(event){
  if(event.key === 'Escape'){
    mealCloseYiwModal();
  }
});


document.getElementById('sidebarToggle')?.addEventListener('click',function(){
  const s = document.getElementById('adminSidebar');
  const m = document.getElementById('adminMain');
  s?.classList.toggle('collapsed'); m?.classList.toggle('collapsed');
});
function switchTab(t, trigger){
  document.querySelectorAll('.meal-panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.meal-tab').forEach(b=>b.classList.remove('active'));
  document.getElementById('panel-'+t)?.classList.add('active');
  (trigger || window.event?.target?.closest('.meal-tab'))?.classList.add('active');
  const url = new URL(window.location.href);
  url.searchParams.set('tab',t);
  url.searchParams.set('page','1');
  history.replaceState(null,'',url.toString());
}

function mealDeleteDocument(id, name){
  const modal = document.getElementById('mealDocumentDeleteModal');
  const input = document.getElementById('mealDocumentDeleteId');
  const message = document.getElementById('mealDocumentDeleteText');

  if(!modal){
    console.error('Supporting document delete modal not found.');
    return false;
  }

  if(input){
    input.value = String(id || '');
  }

  if(message){
    message.textContent =
      'Delete "' + (name || 'this document')
      + '"? The document and its uploaded file will be permanently deleted.';
  }

  if(modal.parentElement !== document.body){
    document.body.appendChild(modal);
  }

  modal.classList.add('open');
  modal.setAttribute('aria-hidden','false');

  modal.style.setProperty('display','flex','important');
  modal.style.setProperty('visibility','visible','important');
  modal.style.setProperty('opacity','1','important');
  modal.style.setProperty('pointer-events','auto','important');
  modal.style.setProperty('z-index','2147483647','important');

  document.body.classList.add('meal-doc-modal-open');

  const cancel = modal.querySelector('[data-document-delete-close="1"]');

  if(cancel){
    setTimeout(function(){
      try{
        cancel.focus({preventScroll:true});
      }catch(error){
        cancel.focus();
      }
    },30);
  }

  return true;
}

function mealCloseDocumentDelete(){
  const modal = document.getElementById('mealDocumentDeleteModal');

  if(!modal) return false;

  modal.classList.remove('open');
  modal.setAttribute('aria-hidden','true');

  modal.style.setProperty('display','none','important');
  modal.style.setProperty('visibility','hidden','important');
  modal.style.setProperty('opacity','0','important');
  modal.style.setProperty('pointer-events','none','important');

  document.body.classList.remove('meal-doc-modal-open');

  return true;
}

document.addEventListener('click', function(event){
  const deleteTrigger =
    event.target.closest('[data-document-delete-trigger="1"]');

  if(deleteTrigger){
    event.preventDefault();

    mealDeleteDocument(
      deleteTrigger.getAttribute('data-document-id'),
      deleteTrigger.getAttribute('data-document-name')
    );

    return;
  }

  const closeTrigger =
    event.target.closest('[data-document-delete-close="1"]');

  if(closeTrigger){
    event.preventDefault();
    mealCloseDocumentDelete();
    return;
  }

  const modal = document.getElementById('mealDocumentDeleteModal');

  if(modal && event.target === modal){
    mealCloseDocumentDelete();
  }
});

document.addEventListener('keydown', function(event){
  if(event.key === 'Escape'){
    mealCloseDocumentDelete();
  }
});

/*
 * Bulk selection has two modes per table:
 *  - normal: the checked boxes on the CURRENT page are what gets deleted
 *    (record_ids[] posted, matches the old behaviour exactly).
 *  - "select all matching": every row that matches the active filters
 *    across ALL pages gets deleted, not just the <=100 rows rendered on
 *    this page. The server re-runs the same venture/cohort/date filters
 *    used to build this table, so it doesn't depend on posting thousands
 *    of ids.
 * mealSetSelectAllMatching()/mealClearSelectAllMatching() toggle between
 * the two; mealUpdateSelection() keeps the visible counters and the
 * "select all N matching records" prompt in sync with what's checked.
 */
function mealGetSelectAllFlag(formId){
  return document.querySelector('#'+formId+' .meal-select-all-matching-flag');
}

function mealIsSelectAllMatchingActive(formId){
  const flag = mealGetSelectAllFlag(formId);
  return !!flag && flag.value === '1';
}

function mealSetSelectAllMatching(formId){
  const form = document.getElementById(formId);
  if(!form) return;

  const flag = mealGetSelectAllFlag(formId);
  if(flag) flag.value = '1';

  const total = parseInt(form.dataset.totalMatching || '0', 10) || 0;

  document.querySelectorAll('.meal-row-check[form="'+formId+'"]').forEach(cb=>{ cb.checked = true; });

  const promptBtn = document.querySelector('.meal-select-all-matching-btn[data-form="'+formId+'"]');
  if(promptBtn) promptBtn.style.display = 'none';

  const activeBanner = document.querySelector('.meal-select-all-matching-active[data-form="'+formId+'"]');
  if(activeBanner){
    activeBanner.style.display = 'inline-flex';
    const numEl = activeBanner.querySelector('.meal-total-matching-num2');
    if(numEl) numEl.textContent = total.toLocaleString();
  }

  const count = document.querySelector('[data-count-for="'+formId+'"]');
  if(count) count.textContent = total.toLocaleString() + ' selected (all matching)';

  form.querySelector('.meal-bulk-delete-btn')?.classList.toggle('meal-empty-selection', total === 0);
}

function mealClearSelectAllMatching(formId){
  const flag = mealGetSelectAllFlag(formId);
  if(flag) flag.value = '0';

  const activeBanner = document.querySelector('.meal-select-all-matching-active[data-form="'+formId+'"]');
  if(activeBanner) activeBanner.style.display = 'none';

  document.querySelectorAll('.meal-select-all[data-form="'+formId+'"]').forEach(master=>{ master.checked = false; master.indeterminate = false; });
  document.querySelectorAll('.meal-row-check[form="'+formId+'"]').forEach(cb=>{ cb.checked = false; });

  mealUpdateSelection(formId);
}

function mealUpdateSelection(formId){
  const form=document.getElementById(formId);
  if(!form)return;

  if(mealIsSelectAllMatchingActive(formId)){
    // Any manual click on a row/master checkbox while "all matching" is
    // active means the person wants to go back to picking rows by hand.
    mealClearSelectAllMatching(formId);
    return;
  }

  const rows=[...document.querySelectorAll('.meal-row-check[form="'+formId+'"]')];
  const chosen=rows.filter(cb=>cb.checked);
  const count=document.querySelector('[data-count-for="'+formId+'"]');
  if(count)count.textContent=chosen.length+' selected';
  form.querySelector('.meal-bulk-delete-btn')?.classList.toggle('meal-empty-selection',chosen.length===0);

  document.querySelectorAll('.meal-select-all[data-form="'+formId+'"]').forEach(master=>{
    master.checked=rows.length>0&&chosen.length===rows.length;
    master.indeterminate=chosen.length>0&&chosen.length<rows.length;
  });

  const total = parseInt(form.dataset.totalMatching || '0', 10) || 0;
  const promptBtn = document.querySelector('.meal-select-all-matching-btn[data-form="'+formId+'"]');
  const allVisibleChecked = rows.length>0 && chosen.length===rows.length;
  const moreRecordsExist = total > rows.length;

  if(promptBtn){
    promptBtn.style.display = (allVisibleChecked && moreRecordsExist) ? 'inline-block' : 'none';
    const numEl = promptBtn.querySelector('.meal-total-matching-num');
    if(numEl) numEl.textContent = total.toLocaleString();
  }
}

document.querySelectorAll('.meal-select-all').forEach(master=>master.addEventListener('change',()=>{
  const id=master.dataset.form;
  if(mealIsSelectAllMatchingActive(id)){
    mealClearSelectAllMatching(id);
    if(!master.checked) return;
  }
  document.querySelectorAll('.meal-row-check[form="'+id+'"]').forEach(cb=>cb.checked=master.checked);
  mealUpdateSelection(id);
}));
document.querySelectorAll('.meal-row-check').forEach(cb=>cb.addEventListener('change',()=>mealUpdateSelection(cb.getAttribute('form'))));
document.querySelectorAll('.meal-select-all-matching-btn').forEach(btn=>btn.addEventListener('click',()=>{
  mealSetSelectAllMatching(btn.dataset.form);
}));
document.querySelectorAll('.meal-clear-select-all').forEach(btn=>btn.addEventListener('click',()=>{
  mealClearSelectAllMatching(btn.dataset.form);
}));
document.querySelectorAll('.meal-bulk-toolbar').forEach(form=>mealUpdateSelection(form.id));

function mealConfirmBulkDelete(form){
  if(mealIsSelectAllMatchingActive(form.id)){
    const total = parseInt(form.dataset.totalMatching || '0', 10) || 0;
    if(total === 0){ alert('There are no matching records to delete.'); return false; }
    return confirm('Delete ALL '+total.toLocaleString()+' record'+(total===1?'':'s')+' matching the current filters, across every page? This action cannot be undone.');
  }

  const selected=document.querySelectorAll('.meal-row-check[form="'+form.id+'"]:checked');
  if(selected.length===0){alert('Select at least one record to delete.');return false;}
  return confirm('Delete '+selected.length+' selected record'+(selected.length===1?'':'s')+' permanently? This action cannot be undone.');
}
</script>
</body>
</html>
