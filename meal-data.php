<?php
declare(strict_types=1);



require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database connection error.');
}

$conn->set_charset('utf8mb4');

if (empty($_SESSION['venture_id'])) {
    header('Location: login');
    exit;
}

$venture_id = (int)$_SESSION['venture_id'];

if ($venture_id <= 0) {
    header('Location: login');
    exit;
}

$venture =
    isset($VENTURE) && is_array($VENTURE)
        ? $VENTURE
        : [];


/* ============================================================
   VENTURE M&E ACCESS CONTROL
============================================================ */

if (!function_exists('meal_portal_controls')) {
    function meal_portal_controls(
        mysqli $conn,
        int $ventureId
    ): array {
        $defaults = [
            'can_edit' => 1,
            'can_delete' => 1,
        ];

        try {
            $exists = $conn->query(
                "SHOW TABLES LIKE 'meal_venture_controls'"
            );

            if (
                !($exists instanceof mysqli_result)
                || $exists->num_rows === 0
            ) {
                return $defaults;
            }

            $stmt = $conn->prepare("
                SELECT
                    can_edit,
                    can_delete
                FROM meal_venture_controls
                WHERE venture_id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                return $defaults;
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

            if (!$row) {
                return $defaults;
            }

            return [
                'can_edit' =>
                    (int)($row['can_edit'] ?? 1),
                'can_delete' =>
                    (int)($row['can_delete'] ?? 1),
            ];

        } catch (Throwable $e) {
            error_log(
                'meal-data access control read failed: '
                . $e->getMessage()
            );

            return $defaults;
        }
    }
}

$mealAccess =
    meal_portal_controls(
        $conn,
        $venture_id
    );

$MEAL_CAN_EDIT =
    (int)$mealAccess['can_edit'] === 1;

$MEAL_CAN_DELETE =
    (int)$mealAccess['can_delete'] === 1;


/* ============================================================
   SUPPORTING DOCUMENTS
============================================================ */

if (!function_exists('meal_supporting_documents_table')) {
    function meal_supporting_documents_table(
        mysqli $conn
    ): void {
        /*
         * No foreign key is used deliberately. Existing installations
         * differ in the exact ventures.id integer type.
         */
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
}

try {
    meal_supporting_documents_table(
        $conn
    );
} catch (Throwable $e) {
    error_log(
        'meal supporting documents table error: '
        . $e->getMessage()
    );
}

$mealDocumentCategories = [
    'participants' => 'Learners',
    'teachers' => 'Teachers / Educators',
    'schools' => 'Schools / Institutions',
    'other_users' => 'Other Users',
    'employment' => 'Employment',
    'finance' => 'External Finance',
    'partnerships' => 'Partnerships',
    'revenue' => 'Revenue',
];

$mealDocuments = [];

try {
    $stmt = $conn->prepare("
        SELECT
            id,
            category,
            document_name,
            description,
            original_name,
            file_path,
            file_ext,
            file_size,
            uploaded_by,
            created_at
        FROM meal_supporting_documents
        WHERE venture_id = ?
        ORDER BY created_at DESC, id DESC
    ");

    if ($stmt) {
        $stmt->bind_param(
            'i',
            $venture_id
        );

        $stmt->execute();

        $res =
            $stmt->get_result();

        while (
            $row =
                $res->fetch_assoc()
        ) {
            $mealDocuments[] = $row;
        }

        $stmt->close();
    }
} catch (Throwable $e) {
    error_log(
        'meal supporting documents load error: '
        . $e->getMessage()
    );
}


if (!function_exists('meal_participant_column_exists')) {
    function meal_participant_column_exists(
        mysqli $conn,
        string $column
    ): bool {
        $safe = $conn->real_escape_string($column);
        $res = $conn->query(
            "SHOW COLUMNS FROM venture_participants LIKE '{$safe}'"
        );

        return $res instanceof mysqli_result
            && $res->num_rows > 0;
    }
}

if (!function_exists('meal_ensure_participant_reporting_columns')) {
    function meal_ensure_participant_reporting_columns(
        mysqli $conn
    ): void {
        $definitions = [
            'phone' => 'VARCHAR(50) NULL',
            'email' => 'VARCHAR(190) NULL',
            'enrollment_category' => 'VARCHAR(20) NULL',
            'verified_learner_outcome' => 'VARCHAR(100) NULL',
            'verified_learner_outcome_other' => 'VARCHAR(255) NULL',
            'after_work_pathway' => 'VARCHAR(50) NULL',
        ];

        foreach ($definitions as $column => $definition) {
            if (meal_participant_column_exists($conn, $column)) {
                continue;
            }

            try {
                $conn->query(
                    "ALTER TABLE venture_participants ADD COLUMN `{$column}` {$definition}"
                );
            } catch (Throwable $e) {
                error_log(
                    'M&E learner column migration failed for '
                    . $column
                    . ': '
                    . $e->getMessage()
                );
            }
        }
    }
}

meal_ensure_participant_reporting_columns($conn);

if (!function_exists('meal_template_field_specs')) {
    function meal_template_field_specs(string $type): array
    {
        $yes_no = ['Yes', 'No'];

        $specs = [
            'participants' => [
                ['label' => 'Date (DD/MM/YYYY)',                      'options' => null, 'example' => date('d/m/Y')],
                ['label' => 'Learner Number (Unique ID)',            'options' => null, 'example' => 'UG-EDT-001'],
                ['label' => 'Full Name (First Name, Surname)',       'options' => null, 'example' => 'Jane Namukasa'],
                ['label' => 'Phone',                                 'options' => null, 'example' => '+256700000000'],
                ['label' => 'Email',                                 'options' => null, 'example' => 'jane@example.com'],
                ['label' => 'Enrollment Category',                   'options' => ['New', 'Returning'], 'example' => 'New'],
                ['label' => 'Gender',                                'options' => ['Male', 'Female'], 'example' => 'Female'],
                ['label' => 'Specific Location (District/Village)',  'options' => null, 'example' => 'Kampala / Makindye'],
                ['label' => 'Urban/Rural',                           'options' => ['Urban', 'Peri-Urban', 'Rural'], 'example' => 'Urban'],
                ['label' => 'Age of Learner',                        'options' => null, 'example' => '24'],
                ['label' => 'Displaced/Refugee Status',              'options' => $yes_no, 'example' => 'No'],
                ['label' => 'Refugee Settlement',                    'options' => [
                    'Bidi bidi Refugee Settlement',
                    'Nakivale Refugee Settlement',
                    'Rhino Camp Refugee Settlement',
                    'Palorinya Refugee Settlement',
                    'Kyangwali Refugee Settlement',
                    'Adjumani Settlements',
                    'Kyaka II Refugee Settlement',
                    'Other (please specify)'
                ], 'example' => 'Nakivale Refugee Settlement'],
                ['label' => 'Other Refugee Settlement (please specify)', 'options' => null, 'example' => ''],
                ['label' => 'PWD Status',                            'options' => $yes_no, 'example' => 'No'],
                ['label' => 'Type of Impairment',                    'options' => null, 'example' => ''],
                ['label' => 'Education Level',                       'options' => ['Primary', 'Secondary', 'TVET', 'Tertiary'], 'example' => 'TVET'],
                ['label' => 'Learner Status',                        'options' => ['Active', 'On hold', 'Dropped', 'Completed', 'Certified'], 'example' => 'Active'],
                ['label' => 'Verified Learner Outcomes',              'options' => ['Increased pass rates', 'Improved grades in STEM', 'Increased Agency and Voice', 'Others please specify'], 'example' => 'Increased pass rates'],
                ['label' => 'Other Verified Learner Outcome',         'options' => null, 'example' => ''],
                ['label' => 'YIW (Youth In Work) Before',            'options' => ['Self-employment', 'Wage employment', 'No'], 'example' => 'No'],
                ['label' => 'Transformation Objective',              'options' => null, 'example' => 'Education & Skilling'],
                ['label' => 'After-work Status',                     'options' => ['New', 'Additional', 'Improved'], 'example' => 'New'],
                ['label' => 'After-work Pathway',                    'options' => ['Self-employment', 'Wage employment'], 'example' => 'Self-employment'],
            ],
            'teachers' => [
                ['label' => 'Date (DD/MM/YYYY)',           'options' => null, 'example' => date('d/m/Y')],
                ['label' => 'Teacher/Educator Name',       'options' => null, 'example' => 'Grace Achieng'],
                ['label' => 'Gender',                      'options' => ['Male', 'Female', 'Other'], 'example' => 'Female'],
                ['label' => 'Age Category',                'options' => ['13-20', '21-35', 'Above 35'], 'example' => '21-35'],
                ['label' => 'Refugee/Displaced Status',    'options' => $yes_no, 'example' => 'No'],
                ['label' => 'Host Community',              'options' => $yes_no, 'example' => 'Yes'],
                ['label' => 'Urban/Rural',               'options' => ['Rural', 'Urban', 'Peri-Urban'], 'example' => 'Rural'],
                ['label' => 'PWD Status',                  'options' => $yes_no, 'example' => 'No'],
            ],
            'schools' => [
                ['label' => 'Date (DD/MM/YYYY)',           'options' => null, 'example' => date('d/m/Y')],
                ['label' => 'School / Institution Name',  'options' => null, 'example' => "St. Mary's Secondary School"],
                ['label' => 'Geographical Location',       'options' => ['Rural', 'Urban', 'Peri-Urban'], 'example' => 'Rural'],
                ['label' => 'Ownership Type',              'options' => ['Government-aided', 'Private'], 'example' => 'Government-aided'],
                ['label' => 'Level of School',             'options' => ['Secondary', 'Tertiary', 'BTVET'], 'example' => 'Secondary'],
            ],
            'other_users' => [
                ['label' => 'Date (DD/MM/YYYY)',                       'options' => null, 'example' => date('d/m/Y')],
                ['label' => 'Gender',                                  'options' => ['Male', 'Female', 'Other'], 'example' => 'Male'],
                ['label' => 'Host Community',                          'options' => $yes_no, 'example' => 'No'],
                ['label' => 'Urban/Rural',                           'options' => ['Rural', 'Urban', 'Peri-Urban'], 'example' => 'Urban'],
                ['label' => 'PWD Status',                              'options' => $yes_no, 'example' => 'No'],
                ['label' => 'Education Level',                         'options' => ['Primary', 'Secondary', 'Tertiary'], 'example' => 'Tertiary'],
            ],
            'employment' => [
                ['label' => 'Placement Date (DD/MM/YYYY)',             'options' => null, 'example' => date('d/m/Y')],
                ['label' => 'Full Name (First Name, Surname)',         'options' => null, 'example' => 'David Ochieng'],
                ['label' => 'Gender',                                  'options' => ['Male', 'Female'], 'example' => 'Male'],
                ['label' => 'Age Category',                            'options' => ['13-20', '21-35', 'Above 35'], 'example' => '21-35'],
                ['label' => 'PWD Status',                              'options' => $yes_no, 'example' => 'No'],
                ['label' => 'Employment Type',                        'options' => ['Full-Time', 'Part-Time', 'Contract', 'Volunteer'], 'example' => 'Full-Time'],
                ['label' => 'Job Title / Role',                       'options' => null, 'example' => 'Software Developer'],
                ['label' => 'Status',                                 'options' => ['Active', 'Suspended', 'Terminated', 'On Leave'], 'example' => 'Active'],
            ],
            'finance' => [
                ['label' => 'Date Mobilised (DD/MM/YYYY)', 'options' => null, 'example' => date('d/m/Y')],
                ['label' => 'Amount in USD',               'options' => null, 'example' => '50000'],
                ['label' => 'Funding Form',                'options' => ['Debt / Loan', 'Grant', 'Equity', 'Convertible Note', 'Blended Finance', 'Other'], 'example' => 'Grant'],
                ['label' => 'Funding Source',              'options' => null, 'example' => 'Village Capital'],
            ],
            'partnerships' => [
                ['label' => 'Partnership Date (DD/MM/YYYY)', 'options' => null, 'example' => date('d/m/Y')],
                ['label' => 'Key Partner Name',              'options' => null, 'example' => 'Google.org'],
                ['label' => 'Type of Partnership',           'options' => ['Financial', 'Technical', 'Distribution', 'Academic', 'Government', 'Other'], 'example' => 'Financial'],
                ['label' => 'Partnership Status',            'options' => ['Active', 'Pending', 'Inactive', 'Completed'], 'example' => 'Active'],
            ],
            'revenue' => [
                ['label' => 'Revenue Month-end Date (DD/MM/YYYY)', 'options' => null, 'example' => date('d/m/Y')],
                ['label' => 'Gross Revenue Generated (UGX)',       'options' => null, 'example' => '12500000'],
                ['label' => 'Primary Revenue Stream',              'options' => null, 'example' => 'Subscription fees'],
            ],
        ];

        return $specs[$type] ?? [];
    }
}

/**
 * Streams an .xlsx template with a bold header row, an italic example row,
 * and a real Excel dropdown (data validation list) on every column that
 * has 'options' set - so filling the template is "pick from the list"
 * instead of "remember the exact spelling". Falls back to a plain CSV
 * (no dropdowns, since CSV has no concept of cell validation) if
 * PhpSpreadsheet isn't installed, so downloads never hard-fail.
 */
if (!function_exists('meal_stream_xlsx_template')) {
    function meal_stream_xlsx_template(array $fields, string $baseFilename): void
    {
        $autoload_candidates = [
            __DIR__ . '/vendor/autoload.php',
            dirname(__DIR__) . '/vendor/autoload.php',
        ];
        $loaded = false;
        foreach ($autoload_candidates as $autoload) {
            if (file_exists($autoload)) {
                require_once $autoload;
                $loaded = true;
                break;
            }
        }

        if (!$loaded || !class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            meal_stream_csv_template_fallback($fields, $baseFilename);
            return;
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Template');

        $lastValidationRow = 500; // rows pre-loaded with the dropdown

        foreach ($fields as $i => $field) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);

            $sheet->setCellValue("{$col}1", $field['label']);
            $sheet->getStyle("{$col}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle("{$col}1")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('FC7F10');
            $sheet->getColumnDimension($col)->setWidth(max(20, (int)(strlen((string)$field['label']) * 0.95)));

            if (isset($field['example']) && $field['example'] !== '') {
                $sheet->setCellValueExplicit("{$col}2", (string)$field['example'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->getStyle("{$col}2")->getFont()->setItalic(true);
                $sheet->getStyle("{$col}2")->getFont()->getColor()->setRGB('9CA3AF');
            }

            if (!empty($field['options'])) {
                $optionsList = '"' . implode(',', $field['options']) . '"';

                for ($row = 2; $row <= $lastValidationRow; $row++) {
                    $validation = $sheet->getCell("{$col}{$row}")->getDataValidation();
                    $validation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
                    $validation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
                    $validation->setAllowBlank(true);
                    $validation->setShowInputMessage(true);
                    $validation->setShowErrorMessage(true);
                    $validation->setShowDropDown(true);
                    $validation->setErrorTitle('Invalid entry');
                    $validation->setError('Please choose one of the listed options.');
                    $validation->setPromptTitle('Pick from the list');
                    $validation->setPrompt('Click the cell, then use the dropdown arrow to choose a value.');
                    $validation->setFormula1($optionsList);
                }
            }
        }

        $sheet->freezePane('A2');

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $baseFilename . '.xlsx"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}

/**
 * No-dropdown fallback used only if PhpSpreadsheet isn't installed
 * (composer require phpoffice/phpspreadsheet). Keeps downloads working,
 * just without the Excel dropdown convenience.
 */
if (!function_exists('meal_stream_csv_template_fallback')) {
    function meal_stream_csv_template_fallback(array $fields, string $baseFilename): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $baseFilename . '.csv"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
        $fh = fopen('php://output', 'w');
        fputcsv($fh, array_column($fields, 'label'));
        fputcsv($fh, array_map(fn($f) => $f['example'] ?? '', $fields));
        fclose($fh);
        exit;
    }
}

if (!function_exists('meal_download_csv_template')) {
    function meal_download_csv_template(string $type): void
    {
        $fields = meal_template_field_specs($type);

        if (!$fields) {
            http_response_code(400);
            echo 'Invalid template type.';
            exit;
        }

        meal_stream_xlsx_template($fields, $type . '_template');
    }
}

if (isset($_GET['download_template'])) {
    $download_type = trim((string)$_GET['download_template']);
    $download_venture_id = (int)($_GET['venture_id'] ?? $venture_id);

    if ($download_venture_id !== $venture_id) {
        http_response_code(403);
        echo 'Permission denied.';
        exit;
    }

    meal_download_csv_template($download_type);
}


$allowed_tabs = ['participants','teachers','schools','other_users','employment','finance','partnerships','revenue','documents'];
$tab = strtolower((string)($_GET['tab'] ?? 'participants'));
if (!in_array($tab, $allowed_tabs, true)) {
    $tab = 'participants';
}


function meal_page(string $t): int { return max(1,(int)($_GET[$t.'_page'] ?? 1)); }
$PER = 20;


if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Formats a record's created_at timestamp for display in the data tables
 * below. Returns '-' if the value is missing or unparseable, rather than
 * letting strtotime()/date() emit a warning or a bogus 1970 placeholder.
 */
if (!function_exists('fmt_created')) {
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
}


if (!function_exists('meal_export_config')) {
    function meal_export_config(string $type): array
    {
        $configs = [
            'participants' => [
                'title' => 'Learners Export',
                'filename' => 'learners',
                'table' => 'venture_participants',
                'order' => 'entry_date DESC, id DESC',
                'columns' => [
                    'entry_date' => 'Entry Date',
                    'user_number' => 'Learner Number',
                    'full_name' => 'Full Name',
                    'phone' => 'Phone',
                    'email' => 'Email',
                    'enrollment_category' => 'Enrollment Category',
                    'gender' => 'Gender',
                    'specific_location' => 'Specific Location',
                    'location_type' => 'Urban/Rural',
                    'age_category' => 'Age of Learner',
                    'refugee_status' => 'Refugee Status',
                    'refugee_settlement' => 'Refugee Settlement',
                    'pwd_status' => 'PWD Status',
                    'impairment_type' => 'Type of Impairment',
                    'education_level' => 'Education Level',
                    'user_status' => 'Learner Status',
                    'verified_learner_outcome' => 'Verified Learner Outcomes',
                    'verified_learner_outcome_other' => 'Other Verified Learner Outcome',
                    'working_at_entry' => 'YIW Before',
                    'transformation_objective' => 'Transformation Objective',
                    'in_work_status' => 'After-work Status',
                    'after_work_pathway' => 'After-work Pathway',
                ],
            ],
            'teachers' => [
                'title' => 'Teachers / Educators Reached Export',
                'filename' => 'teachers_educators',
                'table' => 'meal_teachers',
                'order' => 'entry_date DESC, id DESC',
                'columns' => [
                    'entry_date' => 'Date',
                    'teacher_name' => 'Teacher/Educator Name',
                    'gender' => 'Gender',
                    'age_category' => 'Age Category',
                    'refugee_status' => 'Refugee/Displaced Status',
                    'host_community' => 'Host Community',
                    'location_type' => 'Urban/Rural',
                    'pwd_status' => 'PWD Status',
                ],
            ],
            'schools' => [
                'title' => 'Institutional Level (Schools) Export',
                'filename' => 'schools',
                'table' => 'meal_schools',
                'order' => 'entry_date DESC, id DESC',
                'columns' => [
                    'entry_date' => 'Date',
                    'school_name' => 'School / Institution Name',
                    'location_type' => 'Geographical Location',
                    'ownership_type' => 'Ownership Type',
                    'school_level' => 'Level of School',
                ],
            ],
            'other_users' => [
                'title' => 'Other Users Export',
                'filename' => 'other_users',
                'table' => 'meal_other_users',
                'order' => 'entry_date DESC, id DESC',
                'columns' => [
                    'entry_date' => 'Date',
                    'gender' => 'Gender',
                    'host_community' => 'Host Community',
                    'location_type' => 'Urban/Rural',
                    'pwd_status' => 'PWD Status',
                    'education_level' => 'Education Level',
                ],
            ],
            'employment' => [
                'title' => 'Employment Export',
                'filename' => 'employment',
                'table' => 'meal_employment',
                'order' => 'placement_date DESC, id DESC',
                'columns' => [
                    'placement_date' => 'Placement Date',
                    'full_name' => 'Full Name',
                    'gender' => 'Gender',
                    'age_category' => 'Age Category',
                    'pwd_status' => 'PWD Status',
                    'employment_type' => 'Employment Type',
                    'job_title' => 'Job Title / Role',
                    'status' => 'Status',
                ],
            ],
            'finance' => [
                'title' => 'External Finance Export',
                'filename' => 'external_finance',
                'table' => 'meal_finance',
                'order' => 'date_mobilised DESC, id DESC',
                'columns' => [
                    'date_mobilised' => 'Date Mobilised',
                    'finance_usd' => 'Amount USD',
                    'funding_form' => 'Funding Form',
                    'funding_source' => 'Funding Source',
                ],
            ],
            'partnerships' => [
                'title' => 'Partnerships Export',
                'filename' => 'partnerships',
                'table' => 'meal_partnerships',
                'order' => 'partner_date DESC, id DESC',
                'columns' => [
                    'partner_date' => 'Partnership Date',
                    'partner_name' => 'Partner Name',
                    'partnership_type' => 'Partnership Type',
                    'partnership_status' => 'Partnership Status',
                ],
            ],
            'revenue' => [
                'title' => 'Revenue Export',
                'filename' => 'revenue',
                'table' => 'meal_revenue',
                'order' => 'revenue_date DESC, id DESC',
                'columns' => [
                    'revenue_date' => 'Revenue Month-end Date',
                    'gross_revenue_ugx' => 'Gross Revenue UGX',
                    'revenue_stream' => 'Revenue Stream',
                ],
            ],
        ];

        return $configs[$type] ?? [];
    }
}

if (!function_exists('meal_fetch_export_rows')) {
    function meal_fetch_export_rows(mysqli $conn, int $venture_id, string $type): array
    {
        $cfg = meal_export_config($type);
        if (!$cfg) return [];

        $table = preg_replace('/[^a-z_]/', '', $cfg['table']);
        $order = preg_replace('/[^a-z0-9_, .]/i', '', $cfg['order']);
        $columns = array_keys($cfg['columns']);
        $select = '`' . implode('`,`', array_map(static fn($c) => preg_replace('/[^a-z0-9_]/i', '', $c), $columns)) . '`';

        $stmt = $conn->prepare("SELECT {$select} FROM `{$table}` WHERE venture_id = ? ORDER BY {$order}");
        if (!$stmt) return [];
        $stmt->bind_param('i', $venture_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('meal_export_csv')) {
    function meal_export_csv(mysqli $conn, int $venture_id, string $type): void
    {
        $cfg = meal_export_config($type);
        if (!$cfg) {
            http_response_code(400);
            echo 'Invalid export type.';
            exit;
        }

        $rows = meal_fetch_export_rows($conn, $venture_id, $type);
        $filename = $cfg['filename'] . '_export_' . date('Ymd_His') . '.csv';

        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo "\xEF\xBB\xBF";
        $fh = fopen('php://output', 'w');
        fputcsv($fh, array_values($cfg['columns']));
        foreach ($rows as $row) {
            $line = [];
            foreach (array_keys($cfg['columns']) as $col) {
                $line[] = $row[$col] ?? '';
            }
            fputcsv($fh, $line);
        }
        fclose($fh);
        exit;
    }
}

if (!function_exists('meal_export_pdf')) {
    function meal_export_pdf(mysqli $conn, int $venture_id, string $type, string $site_name = 'EdTech Fellowship'): void
    {
        $cfg = meal_export_config($type);
        if (!$cfg) {
            http_response_code(400);
            echo 'Invalid export type.';
            exit;
        }

        $autoload_candidates = [
            __DIR__ . '/vendor/autoload.php',
            dirname(__DIR__) . '/vendor/autoload.php',
        ];
        $autoload_loaded = false;
        foreach ($autoload_candidates as $autoload) {
            if (file_exists($autoload)) {
                require_once $autoload;
                $autoload_loaded = true;
                break;
            }
        }

        if (!$autoload_loaded || !class_exists('Dompdf\\Dompdf')) {
            $_SESSION['vp_flash_msg'] = 'DOMPDF is not installed. Run composer require dompdf/dompdf, then try PDF export again.';
            $_SESSION['vp_flash_type'] = 'error';
            header('Location: meal-data.php?tab=' . urlencode($type));
            exit;
        }

        $rows = meal_fetch_export_rows($conn, $venture_id, $type);
        $title = $cfg['title'];
        $generated = date('d M Y, g:i A');

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">

<style>
            body{font-family:DejaVu Sans,Arial,sans-serif;font-size:10px;color:#111827;}
            h1{font-size:18px;margin:0 0 4px 0;color:#111827;}
            .meta{font-size:10px;color:#6b7280;margin-bottom:14px;}
            table{width:100%;border-collapse:collapse;}
            th{background:#f3f4f6;color:#111827;font-weight:bold;}
            th,td{border:1px solid #e5e7eb;padding:5px 6px;text-align:left;vertical-align:top;}
            tr:nth-child(even) td{background:#fafafa;}
            .empty{text-align:center;color:#6b7280;padding:20px;border:1px solid #e5e7eb;}
        
#panel-participants .data-table{min-width:2300px!important}
#panel-participants .meal-wrap-cell{min-width:220px;max-width:320px;white-space:normal!important;line-height:1.45}


.yiw-stat-trigger{
  width:100%;
  appearance:none;
  border:1px solid #e2e8f0;
  background:#fff;
  color:inherit;
  text-align:left;
  font:inherit;
  cursor:pointer;
}
.yiw-stat-trigger:hover,
.yiw-stat-trigger:focus-visible{
  border-color:#fdba74;
  background:#fffaf5;
  transform:translateY(-2px);
  box-shadow:0 10px 26px rgba(15,23,42,.10);
}
.yiw-stat-trigger:focus-visible{
  outline:3px solid rgba(249,115,22,.18);
  outline-offset:2px;
}
.yiw-outcomes-card{
  width:min(820px,calc(100vw - 32px));
  max-height:90vh;
}
.yiw-modal-intro{
  margin-bottom:14px;
  padding:11px 13px;
  border:1px solid #fed7aa;
  border-radius:10px;
  background:#fff7ed;
  color:#9a3412;
  font-size:.78rem;
  font-weight:700;
}
.yiw-modal-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:11px;
}
.yiw-modal-item{
  min-height:92px;
  padding:14px;
  border:1px solid #e2e8f0;
  border-left:4px solid #ff6b1a;
  border-radius:11px;
  background:#fff;
}
.yiw-modal-item strong{
  display:block;
  margin-bottom:6px;
  color:#172033;
  font-size:1.5rem;
  line-height:1;
}
.yiw-modal-item span{
  display:block;
  color:#64748b;
  font-size:.74rem;
  line-height:1.45;
}
.yiw-modal-item-wide{
  grid-column:1 / -1;
}
@media(max-width:620px){
  .yiw-modal-grid{
    grid-template-columns:1fr;
  }
  .yiw-modal-item-wide{
    grid-column:auto;
  }
}


/* ============================================================
   UNIVERSAL M&E MODAL VISIBILITY FIX
============================================================ */
body > .meal-modal{
  position:fixed !important;
  inset:0 !important;
  width:100vw !important;
  height:100vh !important;
  z-index:2147483646 !important;
  display:none !important;
  align-items:center !important;
  justify-content:center !important;
  padding:20px !important;
  overflow:auto !important;
  background:rgba(15,23,42,.68) !important;
  visibility:hidden !important;
  opacity:0 !important;
  pointer-events:none !important;
}

body > .meal-modal.open,
body > .meal-modal.show,
body > .meal-modal.active{
  display:flex !important;
  visibility:visible !important;
  opacity:1 !important;
  pointer-events:auto !important;
}

body > .meal-modal > .meal-modal-card{
  position:relative !important;
  display:block !important;
  visibility:visible !important;
  opacity:1 !important;
  width:min(1080px,calc(100vw - 32px)) !important;
  max-height:90vh !important;
  overflow:auto !important;
  margin:auto !important;
  background:#fff !important;
  border-radius:18px !important;
  box-shadow:0 30px 80px rgba(0,0,0,.30) !important;
}

body.meal-modal-open{
  overflow:hidden !important;
}


#mealDirectRecordModal .meal-modal-body{
  overflow:auto !important;
}
#mealDirectRecordModal .form-grid-2{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:14px;
}
#mealDirectRecordModal .meal-field-full{
  grid-column:1 / -1;
}
#mealDirectRecordModal .meal-modal-footer form{
  margin:0;
}
@media(max-width:700px){
  #mealDirectRecordModal .form-grid-2{
    grid-template-columns:1fr;
  }
  #mealDirectRecordModal .meal-field-full{
    grid-column:auto;
  }
}

</style></head><body>';
        $html .= '<h1>' . h($title) . '</h1>';
        $html .= '<div class="meta">' . h($site_name) . ' &bull; Generated: ' . h($generated) . ' &bull; Records: ' . number_format(count($rows)) . '</div>';

        if (!$rows) {
            $html .= '<div class="empty">No records found.</div>';
        } else {
            $html .= '<table><thead><tr>';
            foreach ($cfg['columns'] as $label) {
                $html .= '<th>' . h($label) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $html .= '<tr>';
                foreach (array_keys($cfg['columns']) as $col) {
                    $value = $row[$col] ?? '';
                    if (is_numeric($value) && in_array($col, ['finance_usd', 'gross_revenue_ugx'], true)) {
                        $value = number_format((float)$value, 2);
                    }
                    $html .= '<td>' . nl2br(h((string)$value)) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= '</body></html>';

        $dompdf = new Dompdf\Dompdf(['isRemoteEnabled' => true]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $dompdf->stream($cfg['filename'] . '_export_' . date('Ymd_His') . '.pdf', ['Attachment' => true]);
        exit;
    }
}

if (isset($_GET['export'], $_GET['type'])) {
    $export_format = strtolower(trim((string)$_GET['export']));
    $export_type = strtolower(trim((string)$_GET['type']));
    $export_venture_id = (int)($_GET['venture_id'] ?? $venture_id);

    if ($export_venture_id !== $venture_id) {
        http_response_code(403);
        echo 'Permission denied.';
        exit;
    }

    if ($export_format === 'csv') {
        meal_export_csv($conn, $venture_id, $export_type);
    }

    if ($export_format === 'pdf') {
        $site_for_pdf = $conn->query("SELECT setting_value FROM site_settings WHERE setting_key='site_name' LIMIT 1")->fetch_row()[0] ?? 'EdTech Fellowship';
        meal_export_pdf($conn, $venture_id, $export_type, (string)$site_for_pdf);
    }

    http_response_code(400);
    echo 'Invalid export format.';
    exit;
}

/* Participant editing is handled by includes/process-meal-data.php. */



$ben_page  = meal_page('ben');
$ben_off   = ($ben_page - 1) * $PER;
$ben_total = (int)$conn->query("SELECT COUNT(*) FROM venture_participants  WHERE venture_id=$venture_id")->fetch_row()[0];
$ben_pages = max(1, (int)ceil($ben_total / $PER));
$ben_rows  = $conn->query("SELECT * FROM venture_participants  WHERE venture_id=$venture_id ORDER BY entry_date DESC, id DESC LIMIT $PER OFFSET $ben_off");


/* -- TEACHERS / EDUCATORS REACHED -------------------------- */
$tea_page  = meal_page('tea');
$tea_off   = ($tea_page - 1) * $PER;
$tea_total = (int)($conn->query("SELECT COUNT(*) FROM meal_teachers WHERE venture_id=$venture_id")->fetch_row()[0] ?? 0);
$tea_pages = max(1, (int)ceil($tea_total / $PER));
$tea_rows  = $conn->query("SELECT * FROM meal_teachers WHERE venture_id=$venture_id ORDER BY entry_date DESC, id DESC LIMIT $PER OFFSET $tea_off");

/* -- INSTITUTIONAL LEVEL (SCHOOLS) -------------------------- */
$sch_page  = meal_page('sch');
$sch_off   = ($sch_page - 1) * $PER;
$sch_total = (int)($conn->query("SELECT COUNT(*) FROM meal_schools WHERE venture_id=$venture_id")->fetch_row()[0] ?? 0);
$sch_pages = max(1, (int)ceil($sch_total / $PER));
$sch_rows  = $conn->query("SELECT * FROM meal_schools WHERE venture_id=$venture_id ORDER BY entry_date DESC, id DESC LIMIT $PER OFFSET $sch_off");

/* -- OTHER USERS --------------------------------------------- */
$oth_page  = meal_page('oth');
$oth_off   = ($oth_page - 1) * $PER;
$oth_total = (int)($conn->query("SELECT COUNT(*) FROM meal_other_users WHERE venture_id=$venture_id")->fetch_row()[0] ?? 0);
$oth_pages = max(1, (int)ceil($oth_total / $PER));
$oth_rows  = $conn->query("SELECT * FROM meal_other_users WHERE venture_id=$venture_id ORDER BY entry_date DESC, id DESC LIMIT $PER OFFSET $oth_off");


$emp_page  = meal_page('emp');
$emp_off   = ($emp_page - 1) * $PER;
$emp_total = (int)$conn->query("SELECT COUNT(*) FROM meal_employment WHERE venture_id=$venture_id")->fetch_row()[0];
$emp_pages = max(1, (int)ceil($emp_total / $PER));
$emp_rows  = $conn->query("SELECT * FROM meal_employment WHERE venture_id=$venture_id ORDER BY placement_date DESC, id DESC LIMIT $PER OFFSET $emp_off");

$fin_page  = meal_page('fin');
$fin_off   = ($fin_page - 1) * $PER;
$fin_total = (int)$conn->query("SELECT COUNT(*) FROM meal_finance WHERE venture_id=$venture_id")->fetch_row()[0];
$fin_pages = max(1, (int)ceil($fin_total / $PER));
$fin_rows  = $conn->query("SELECT * FROM meal_finance WHERE venture_id=$venture_id ORDER BY date_mobilised DESC, id DESC LIMIT $PER OFFSET $fin_off");


$par_page  = meal_page('par');
$par_off   = ($par_page - 1) * $PER;
$par_total = (int)$conn->query("SELECT COUNT(*) FROM meal_partnerships WHERE venture_id=$venture_id")->fetch_row()[0];
$par_pages = max(1, (int)ceil($par_total / $PER));
$par_rows  = $conn->query("SELECT * FROM meal_partnerships WHERE venture_id=$venture_id ORDER BY partner_date DESC, id DESC LIMIT $PER OFFSET $par_off");

$rev_page  = meal_page('rev');
$rev_off   = ($rev_page - 1) * $PER;
$rev_total = (int)$conn->query("SELECT COUNT(*) FROM meal_revenue WHERE venture_id=$venture_id")->fetch_row()[0];
$rev_pages = max(1, (int)ceil($rev_total / $PER));
$rev_rows  = $conn->query("SELECT * FROM meal_revenue WHERE venture_id=$venture_id ORDER BY revenue_date DESC, id DESC LIMIT $PER OFFSET $rev_off");


$stats = [
    'total_users'  => $ben_total,
    'new_learners' => (int)$conn->query("SELECT COUNT(*) FROM venture_participants  WHERE venture_id=$venture_id AND enrollment_category='New'")->fetch_row()[0],
    // Gender, PWD and Refugee counts are captured across EVERY learner
    // record, not just ones tagged Enrollment Category = New - most
    // existing (and many new) records don't have that field filled in yet,
    // so filtering these counts by it was hiding real data on the
    // dashboard. Only the percentage denominator below uses New Learners.
    'female'       => (int)$conn->query("SELECT COUNT(*) FROM venture_participants  WHERE venture_id=$venture_id AND gender='Female'")->fetch_row()[0],
    'youth'        => (int)$conn->query("SELECT COUNT(*) FROM venture_participants  WHERE venture_id=$venture_id AND CAST(age_category AS UNSIGNED) BETWEEN 18 AND 34")->fetch_row()[0],
    'refugee'      => (int)$conn->query("SELECT COUNT(*) FROM venture_participants  WHERE venture_id=$venture_id AND refugee_status='Yes'")->fetch_row()[0],
    'pwd'          => (int)$conn->query("SELECT COUNT(*) FROM venture_participants  WHERE venture_id=$venture_id AND pwd_status='Yes'")->fetch_row()[0],
    'employees'    => $emp_total,
    'total_rev'    => (float)($conn->query("SELECT SUM(gross_revenue_ugx) FROM meal_revenue WHERE venture_id=$venture_id")->fetch_row()[0] ?? 0),
    'total_fin'    => (float)($conn->query("SELECT SUM(finance_usd) FROM meal_finance WHERE venture_id=$venture_id")->fetch_row()[0] ?? 0),
    'partners'     => $par_total,
    'teachers'     => $tea_total,
    'schools'      => $sch_total,
    'other_users'  => $oth_total,
];
// Percentage indicators use New Learners (Enrollment Category = New) as the
// denominator, per the programme team's reporting definition. But that
// field is new and optional, so on a venture where nobody has been tagged
// "New" yet, new_learners is 0 and every percentage would show as 0%
// even though the table clearly has female/male and other data in it.
// Falling back to Total Learners in that case keeps the dashboard
// meaningful until Enrollment Category gets filled in going forward.
$stats['pct_denominator'] =
    $stats['new_learners'] > 0
        ? $stats['new_learners']
        : $stats['total_users'];

$female_pct = $stats['pct_denominator'] ? round(($stats['female'] / $stats['pct_denominator']) * 100) : 0;
$youth_pct  = $stats['pct_denominator'] ? round(($stats['youth']  / $stats['pct_denominator']) * 100) : 0;

$hasAfterWorkPathway = meal_participant_column_exists($conn, 'after_work_pathway');
$hasDignifiedWork = meal_participant_column_exists($conn, 'dignified_fulfilling_work');

$yiwStats = [
    'new_self' => 0,
    'additional_self' => 0,
    'improved_self' => 0,
    'new_wage' => 0,
    'additional_wage' => 0,
    'improved_wage' => 0,
    'dignified' => 0,
];

if ($hasAfterWorkPathway) {
    $yiwStats['new_self'] = (int)$conn->query("SELECT COUNT(*) FROM venture_participants WHERE venture_id={$venture_id} AND in_work_status='New' AND after_work_pathway='Self-employment'")->fetch_row()[0];
    $yiwStats['additional_self'] = (int)$conn->query("SELECT COUNT(*) FROM venture_participants WHERE venture_id={$venture_id} AND in_work_status='Additional' AND after_work_pathway='Self-employment'")->fetch_row()[0];
    $yiwStats['improved_self'] = (int)$conn->query("SELECT COUNT(*) FROM venture_participants WHERE venture_id={$venture_id} AND in_work_status='Improved' AND after_work_pathway='Self-employment'")->fetch_row()[0];
    $yiwStats['new_wage'] = (int)$conn->query("SELECT COUNT(*) FROM venture_participants WHERE venture_id={$venture_id} AND in_work_status='New' AND after_work_pathway='Wage employment'")->fetch_row()[0];
    $yiwStats['additional_wage'] = (int)$conn->query("SELECT COUNT(*) FROM venture_participants WHERE venture_id={$venture_id} AND in_work_status='Additional' AND after_work_pathway='Wage employment'")->fetch_row()[0];
    $yiwStats['improved_wage'] = (int)$conn->query("SELECT COUNT(*) FROM venture_participants WHERE venture_id={$venture_id} AND in_work_status='Improved' AND after_work_pathway='Wage employment'")->fetch_row()[0];
}

if ($hasDignifiedWork) {
    $yiwStats['dignified'] = (int)$conn->query("SELECT COUNT(*) FROM venture_participants WHERE venture_id={$venture_id} AND dignified_fulfilling_work='Yes'")->fetch_row()[0];
}

$site_name = $conn->query("SELECT setting_value FROM site_settings WHERE setting_key='site_name' LIMIT 1")->fetch_row()[0] ?? 'EdTech Fellowship';
?>

<?php include 'layout.php'; ?>

<?php
/* ============================================================
   GENERIC VIEW / EDIT SUPPORT FOR M&E CATEGORIES
============================================================ */

$mealCrudSchemas = [
    'participants' => [
        'label' => 'Learner',
        'table' => 'venture_participants',
        'fields' => [
            'entry_date' => ['label'=>'Entry Date','type'=>'date','required'=>true],
            'user_number' => ['label'=>'Learner Number','type'=>'text','required'=>true],
            'full_name' => ['label'=>'Full Name','type'=>'text'],
            'phone' => ['label'=>'Phone','type'=>'text'],
            'email' => ['label'=>'Email','type'=>'email'],
            'enrollment_category' => ['label'=>'Enrollment Category','type'=>'select','options'=>[
                ''=>'Select',
                'New'=>'New',
                'Returning'=>'Returning'
            ]],
            'gender' => ['label'=>'Gender','type'=>'select','options'=>[
                ''=>'Select',
                'Male'=>'Male',
                'Female'=>'Female'
            ]],
            'specific_location' => ['label'=>'Specific Location','type'=>'text'],
            'location_type' => ['label'=>'Urban/Rural','type'=>'select','options'=>[
                ''=>'Select',
                'Urban'=>'Urban',
                'Peri-Urban'=>'Peri-Urban',
                'Rural'=>'Rural'
            ]],
            'age_category' => ['label'=>'Age of Learner','type'=>'number'],
            'refugee_status' => ['label'=>'Refugee / Displaced','type'=>'select','options'=>[
                'No'=>'No',
                'Yes'=>'Yes'
            ]],
            'refugee_settlement' => ['label'=>'Refugee Settlement','type'=>'select','options'=>[
                ''=>'Select settlement',
                'Bidi bidi Refugee Settlement'=>'Bidi bidi Refugee Settlement',
                'Nakivale Refugee Settlement'=>'Nakivale Refugee Settlement',
                'Rhino Camp Refugee Settlement'=>'Rhino Camp Refugee Settlement',
                'Palorinya Refugee Settlement'=>'Palorinya Refugee Settlement',
                'Kyangwali Refugee Settlement'=>'Kyangwali Refugee Settlement',
                'Adjumani Settlements'=>'Adjumani Settlements',
                'Kyaka II Refugee Settlement'=>'Kyaka II Refugee Settlement',
                'Other (please specify)'=>'Other (please specify)'
            ]],
            'pwd_status' => ['label'=>'PWD Status','type'=>'select','options'=>[
                'No'=>'No',
                'Yes'=>'Yes'
            ]],
            'impairment_type' => ['label'=>'Type of Impairment','type'=>'text'],
            'education_level' => ['label'=>'Education Level','type'=>'select','options'=>[
                ''=>'Select',
                'Primary'=>'Primary',
                'Secondary'=>'Secondary',
                'TVET'=>'TVET',
                'Tertiary'=>'Tertiary'
            ]],
            'user_status' => ['label'=>'Learner Status','type'=>'select','options'=>[
                ''=>'Select',
                'Active'=>'Active',
                'On hold'=>'On hold',
                'Dropped'=>'Dropped',
                'Completed'=>'Completed',
                'Certified'=>'Certified'
            ]],
            'verified_learner_outcome' => ['label'=>'Verified Learner Outcomes','type'=>'select','options'=>[
                ''=>'Select',
                'Increased pass rates'=>'Increased pass rates',
                'Improved grades in STEM'=>'Improved grades in STEM',
                'Increased Agency and Voice'=>'Increased Agency and Voice',
                'Others please specify'=>'Others please specify'
            ]],
            'verified_learner_outcome_other' => ['label'=>'Other Verified Learner Outcome','type'=>'text'],
            'working_at_entry' => ['label'=>'YIW (Youth In Work) Before','type'=>'select','options'=>[
                ''=>'Select',
                'Self-employment'=>'Self-employment',
                'Wage employment'=>'Wage employment',
                'No'=>'No'
            ]],
            'transformation_objective' => ['label'=>'Transformation Objective','type'=>'text'],
            'in_work_status' => ['label'=>'After-work Status','type'=>'select','options'=>[
                ''=>'Select',
                'New'=>'New',
                'Additional'=>'Additional',
                'Improved'=>'Improved'
            ]],
            'after_work_pathway' => ['label'=>'After-work Pathway','type'=>'select','options'=>[
                ''=>'Select pathway',
                'Self-employment'=>'Self-employment',
                'Wage employment'=>'Wage employment'
            ]],
        ],
    ],
    'teachers' => [
        'label' => 'Teacher / Educator',
        'table' => 'meal_teachers',
        'fields' => [
            'entry_date' => ['label'=>'Date','type'=>'date'],
            'teacher_name' => ['label'=>'Name','type'=>'text','required'=>true],
            'gender' => ['label'=>'Gender','type'=>'select','options'=>[''=>'Select','Male'=>'Male','Female'=>'Female','Other'=>'Other']],
            'age_category' => ['label'=>'Age Category','type'=>'select','options'=>[''=>'Select','13-20'=>'13-20','21-35'=>'21-35','Above 35'=>'Above 35']],
            'refugee_status' => ['label'=>'Refugee / Displaced','type'=>'select','options'=>['No'=>'No','Yes'=>'Yes']],
            'host_community' => ['label'=>'Host Community','type'=>'select','options'=>['No'=>'No','Yes'=>'Yes']],
            'location_type' => ['label'=>'Location Type','type'=>'select','options'=>[''=>'Select','Rural'=>'Rural','Urban'=>'Urban','Peri-Urban'=>'Peri-Urban']],
            'pwd_status' => ['label'=>'PWD Status','type'=>'select','options'=>['No'=>'No','Yes'=>'Yes']],
        ],
    ],
    'schools' => [
        'label' => 'School / Institution',
        'table' => 'meal_schools',
        'fields' => [
            'entry_date' => ['label'=>'Date','type'=>'date'],
            'school_name' => ['label'=>'School / Institution','type'=>'text','required'=>true],
            'location_type' => ['label'=>'Location Type','type'=>'select','options'=>[''=>'Select','Rural'=>'Rural','Urban'=>'Urban','Peri-Urban'=>'Peri-Urban']],
            'ownership_type' => ['label'=>'Ownership','type'=>'select','options'=>[''=>'Select','Government-aided'=>'Government-aided','Private'=>'Private']],
            'school_level' => ['label'=>'Level','type'=>'select','options'=>[''=>'Select','Secondary'=>'Secondary','Tertiary'=>'Tertiary','BTVET'=>'BTVET']],
        ],
    ],
    'other_users' => [
        'label' => 'Other User',
        'table' => 'meal_other_users',
        'fields' => [
            'entry_date' => ['label'=>'Date','type'=>'date'],
            'gender' => ['label'=>'Gender','type'=>'select','options'=>[''=>'Select','Male'=>'Male','Female'=>'Female','Other'=>'Other']],
            'host_community' => ['label'=>'Host Community','type'=>'select','options'=>['No'=>'No','Yes'=>'Yes']],
            'location_type' => ['label'=>'Location Type','type'=>'select','options'=>[''=>'Select','Rural'=>'Rural','Urban'=>'Urban','Peri-Urban'=>'Peri-Urban']],
            'pwd_status' => ['label'=>'PWD Status','type'=>'select','options'=>['No'=>'No','Yes'=>'Yes']],
            'education_level' => ['label'=>'Education Level','type'=>'select','options'=>[''=>'Select','Primary'=>'Primary','Secondary'=>'Secondary','Tertiary'=>'Tertiary']],
        ],
    ],
    'employment' => [
        'label' => 'Employment Record',
        'table' => 'meal_employment',
        'fields' => [
            'placement_date' => ['label'=>'Placement Date','type'=>'date'],
            'full_name' => ['label'=>'Name','type'=>'text','required'=>true],
            'gender' => ['label'=>'Gender','type'=>'select','options'=>[''=>'Select','Male'=>'Male','Female'=>'Female','Other'=>'Other']],
            'age_category' => ['label'=>'Age Category','type'=>'select','options'=>[''=>'Select','13-20'=>'13-20','21-35'=>'21-35','Above 35'=>'Above 35']],
            'pwd_status' => ['label'=>'PWD Status','type'=>'select','options'=>['No'=>'No','Yes'=>'Yes']],
            'employment_type' => ['label'=>'Employment Type','type'=>'text'],
            'job_title' => ['label'=>'Job Title','type'=>'text','required'=>true],
            'status' => ['label'=>'Status','type'=>'select','options'=>['Active'=>'Active','Inactive'=>'Inactive','Ended'=>'Ended']],
        ],
    ],
    'finance' => [
        'label' => 'Finance Record',
        'table' => 'meal_finance',
        'fields' => [
            'date_mobilised' => ['label'=>'Date Mobilised','type'=>'date'],
            'finance_usd' => ['label'=>'Amount (USD)','type'=>'number','step'=>'0.01','required'=>true],
            'funding_form' => ['label'=>'Funding Form','type'=>'select','required'=>true,'options'=>[
                ''=>'Select','Debt / Loan'=>'Debt / Loan','Grant'=>'Grant','Equity'=>'Equity',
                'Convertible Note'=>'Convertible Note','Blended Finance'=>'Blended Finance','Other'=>'Other'
            ]],
            'funding_source' => ['label'=>'Funding Source','type'=>'text','required'=>true],
        ],
    ],
    'partnerships' => [
        'label' => 'Partnership',
        'table' => 'meal_partnerships',
        'fields' => [
            'partner_date' => ['label'=>'Partnership Date','type'=>'date'],
            'partner_name' => ['label'=>'Partner Name','type'=>'text','required'=>true],
            'partnership_type' => ['label'=>'Partnership Type','type'=>'select','options'=>[
                ''=>'Select','Financial'=>'Financial','Technical'=>'Technical','Distribution'=>'Distribution',
                'Academic'=>'Academic','Government'=>'Government','Other'=>'Other'
            ]],
            'partnership_status' => ['label'=>'Status','type'=>'select','options'=>[
                'Active'=>'Active','Pending'=>'Pending','Inactive'=>'Inactive','Completed'=>'Completed'
            ]],
        ],
    ],
    'revenue' => [
        'label' => 'Revenue Record',
        'table' => 'meal_revenue',
        'fields' => [
            'revenue_date' => ['label'=>'Month-end Date','type'=>'date','required'=>true],
            'gross_revenue_ugx' => ['label'=>'Gross Revenue (UGX)','type'=>'number','step'=>'1','required'=>true],
            'revenue_stream' => ['label'=>'Revenue Stream','type'=>'text'],
        ],
    ],
    'documents' => [
        'label' => 'Supporting Document',
        'table' => 'meal_supporting_documents',
        'fields' => [
            'category' => ['label'=>'M&E Category','type'=>'select','required'=>true,'options'=>$mealDocumentCategories],
            'document_name' => ['label'=>'Document Name','type'=>'text','required'=>true],
            'description' => ['label'=>'Description','type'=>'textarea'],
        ],
    ],
];

$mealModalMode = '';
$mealModalRecord = null;
$mealModalSchema = null;
$mealModalType = '';

$requestedViewId = max(0, (int)($_GET['view_id'] ?? 0));
$requestedEditId = max(0, (int)($_GET['edit_id'] ?? 0));

$requestedDeleteId =
    max(
        0,
        (int)(
            $_GET['delete_id']
            ?? 0
        )
    );

$requestedDeleteType =
    trim(
        (string)(
            $_GET['delete_type']
            ?? ''
        )
    );

$mealDeleteRecord = null;
$mealDeleteLabel = 'record';

if (
    $requestedDeleteId > 0
    && isset(
        $mealCrudSchemas[
            $requestedDeleteType
        ]
    )
) {
    $deleteSchema =
        $mealCrudSchemas[
            $requestedDeleteType
        ];

    $deleteTable =
        $deleteSchema['table'];

    $stmt =
        $conn->prepare("
            SELECT *
            FROM `{$deleteTable}`
            WHERE id = ?
              AND venture_id = ?
            LIMIT 1
        ");

    if ($stmt) {
        $stmt->bind_param(
            'ii',
            $requestedDeleteId,
            $venture_id
        );

        $stmt->execute();

        $mealDeleteRecord =
            $stmt
                ->get_result()
                ->fetch_assoc()
            ?: null;

        $stmt->close();
    }

    $mealDeleteLabel =
        strtolower(
            (string)(
                $deleteSchema['label']
                ?? 'record'
            )
        );
}


if (
    isset($mealCrudSchemas[$tab])
    && ($requestedViewId > 0 || $requestedEditId > 0)
) {
    $mealModalMode =
        $requestedEditId > 0
            ? 'edit'
            : 'view';

    $mealModalType = $tab;
    $mealModalSchema = $mealCrudSchemas[$tab];

    $recordId =
        $requestedEditId > 0
            ? $requestedEditId
            : $requestedViewId;

    $tableName =
        $mealModalSchema['table'];

    $stmt = $conn->prepare("
        SELECT *
        FROM `{$tableName}`
        WHERE id = ?
          AND venture_id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param(
            'ii',
            $recordId,
            $venture_id
        );

        $stmt->execute();

        $mealModalRecord =
            $stmt
                ->get_result()
                ->fetch_assoc()
            ?: null;

        $stmt->close();
    }

    if (
        $mealModalMode === 'edit'
        && !$MEAL_CAN_EDIT
    ) {
        $mealModalMode = 'view';
    }
}
?>


<style>

.meal-modal{
  position:fixed !important;
  inset:0 !important;
  z-index:999999 !important;
  display:none !important;
  align-items:center !important;
  justify-content:center !important;
  width:100vw !important;
  height:100vh !important;
  padding:20px !important;
  background:rgba(15,23,42,.68) !important;
  backdrop-filter:blur(4px) !important;
}
.meal-modal.open,
.meal-modal.show,
.meal-modal.active{
  display:flex !important;
}
.meal-modal-card{
  position:relative !important;
  width:min(1080px,100%) !important;
  max-height:90vh !important;
  overflow-y:auto !important;
  background:#fff !important;
  border-radius:18px !important;
  box-shadow:0 30px 70px rgba(0,0,0,.25) !important;
}

.meal-modal-header{
  position:sticky !important;
  top:0 !important;
  z-index:2 !important;
  background:#fff !important;
  display:flex !important;
  align-items:center !important;
  justify-content:space-between !important;
  gap:12px !important;
  padding:18px 24px !important;
  border-bottom:1px solid #e5e7eb !important;
  border-radius:18px 18px 0 0 !important;
}
.meal-modal-title{
  display:flex !important;
  align-items:center !important;
  gap:8px !important;
  margin:0 !important;
  font-size:1.05rem !important;
  font-weight:700 !important;
  color:#0f172a !important;
  white-space:nowrap !important;
}
.meal-modal-close{
  flex-shrink:0 !important;
  width:32px !important;
  height:32px !important;
  display:flex !important;
  align-items:center !important;
  justify-content:center !important;
  border:none !important;
  background:transparent !important;
  border-radius:8px !important;
  color:#64748b !important;
  cursor:pointer !important;
  font-size:1rem !important;
  line-height:1 !important;
  padding:0 !important;
}
.meal-modal-close:hover{
  background:#f1f5f9 !important;
  color:#0f172a !important;
}
.meal-modal-body{
  padding:22px 24px !important;
}
.meal-modal-footer{
  display:flex !important;
  align-items:center !important;
  justify-content:flex-end !important;
  gap:10px !important;
  padding:16px 24px !important;
  border-top:1px solid #e5e7eb !important;
  background:#fff !important;
  border-radius:0 0 18px 18px !important;
}
body.meal-modal-open{overflow:hidden !important;}

.vp-flash{
  transition:opacity .6s ease, transform .6s ease, margin .6s ease, padding .6s ease;
  opacity:1;
}
.vp-flash.vp-flash-hide{
  opacity:0 !important;
  transform:translateY(-6px);
  pointer-events:none;
}

.meal-access-notice{
  display:flex;
  align-items:flex-start;
  gap:12px;
  margin:0 0 16px;
  padding:13px 15px;
  border:1px solid #fed7aa;
  border-left:4px solid #f97316;
  border-radius:10px;
  background:#fff7ed;
  color:#9a3412;
}
.meal-access-notice>i{
  margin-top:2px;
  color:#ea580c;
}
.meal-access-notice strong{
  display:block;
  margin-bottom:3px;
}
.meal-access-notice span{
  display:block;
  font-size:.8rem;
  line-height:1.5;
}
.meal-write-blocked{
  opacity:.72;
}
.meal-write-blocked .add-card-header{
  cursor:not-allowed !important;
}
.is-disabled,
button:disabled{
  opacity:.5 !important;
  cursor:not-allowed !important;
  pointer-events:none !important;
}
.meal-block-message{
  display:flex;
  align-items:center;
  gap:8px;
  padding:12px;
  border:1px solid #fecaca;
  border-radius:8px;
  background:#fef2f2;
  color:#991b1b;
  font-size:.8rem;
}
.meal-doc-header{
  margin-bottom:14px;
}
.meal-doc-header h3{
  display:flex;
  align-items:center;
  gap:8px;
  margin:0 0 5px;
}
.meal-doc-header h3 i{
  color:#f97316;
}
.meal-doc-header p{
  margin:0;
  max-width:800px;
  color:#64748b;
  font-size:.8rem;
  line-height:1.6;
}
.meal-doc-filter{
  display:flex;
  align-items:center;
  justify-content:flex-end;
  gap:8px;
  margin:14px 0 10px;
}
.meal-doc-filter label{
  font-size:.75rem;
  font-weight:700;
  color:#64748b;
}
.meal-doc-filter select{
  width:auto;
  min-width:210px;
}
.meal-doc-table{
  min-width:980px;
}
.meal-doc-uploader{
  display:block;
  margin-top:3px;
  color:#94a3b8;
  font-size:.7rem;
}


/* ============================================================
   M&E INTERACTION FIXES
============================================================ */

.upload-form{
  display:none;
  width:100%;
  margin:12px 0 16px;
  padding:16px;
  border:1px solid #e2e8f0;
  border-radius:10px;
  background:#f8fafc;
}

.upload-form.open{
  display:block !important;
}

.add-card-body{
  display:none;
}

.add-card-body.open{
  display:block !important;
}

.add-card-body:target{
  display:block !important;
}

.add-card-header{
  cursor:pointer;
  user-select:none;
}

.add-card-header.open .toggle{
  transform:rotate(180deg);
}

.add-card-header .toggle{
  transition:transform .2s ease;
}

.btn-dl-upload:disabled,
.btn-dl-upload.is-disabled{
  opacity:.5;
  cursor:not-allowed !important;
  pointer-events:none;
}

/*
 * Modals are appended directly to <body> by JavaScript.
 * This avoids transformed/overflow-hidden portal containers preventing
 * position:fixed overlays from covering the viewport.
 */
body > .meal-modal{
  position:fixed !important;
  inset:0 !important;
  z-index:2147483000 !important;
}

.meal-modal{
  visibility:hidden !important;
  opacity:0 !important;
  pointer-events:none !important;
  display:flex !important;
  transition:opacity .16s ease, visibility .16s ease;
}

.meal-modal.open{
  visibility:visible !important;
  opacity:1 !important;
  pointer-events:auto !important;
}

.meal-modal-card{
  transform:translateY(8px) scale(.99);
  transition:transform .16s ease;
}

.meal-modal.open .meal-modal-card{
  transform:translateY(0) scale(1);
}

.meal-modal form{
  margin:0;
}

.meal-upload-status{
  display:none;
  align-items:center;
  gap:8px;
  margin-top:10px;
  padding:9px 11px;
  border-radius:8px;
  background:#eff6ff;
  color:#1d4ed8;
  font-size:.78rem;
  font-weight:700;
}

.meal-upload-status.show{
  display:flex;
}

.meal-file-name{
  display:block;
  margin-top:5px;
  color:#475569;
  font-size:.72rem;
}


/* ============================================================
   M&E TABS / PROGRESSIVE FALLBACK
============================================================ */
.meal-tabs{
  display:flex;
  align-items:center;
  gap:7px;
  width:100%;
  overflow-x:auto;
  overflow-y:hidden;
  margin:0 0 18px;
  padding:2px 0 8px;
  -webkit-overflow-scrolling:touch;
  scrollbar-width:thin;
  /*
   * FIX: force this bar - and everything in it - above any sibling
   * element (fixed sidebars, floating widgets, decorative overlays,
   * etc.) that layout.php might place with its own z-index. Tabs at
   * the end of this scrollable row were reported as completely
   * unclickable (no navigation at all, desktop and mobile), which is
   * the signature of an invisible element sitting on top of them.
   */
  position:relative;
  z-index:20;
}
.meal-tab{
  flex:0 0 auto;
  min-height:38px;
  padding:8px 12px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:7px;
  border:1px solid #e2e8f0;
  border-radius:9px;
  background:#fff;
  color:#475569;
  font-size:.78rem;
  font-weight:700;
  line-height:1;
  text-decoration:none !important;
  white-space:nowrap;
  cursor:pointer;
  position:relative;
  z-index:21;
  pointer-events:auto !important;
}
.meal-tab:hover{
  border-color:#fdba74;
  background:#fff7ed;
  color:#c2410c;
}
.meal-tab.active{
  border-color:#f97316;
  background:#f97316;
  color:#fff;
}
.meal-tab .badge{
  min-width:20px;
  height:20px;
  padding:0 6px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  border-radius:999px;
  background:#f1f5f9;
  color:#475569;
  font-size:.66rem;
  font-weight:800;
}
.meal-tab.active .badge{
  background:rgba(255,255,255,.22);
  color:#fff;
}
.meal-panel{
  display:none !important;
  width:100%;
}
.meal-panel.active{
  display:block !important;
}
.upload-form{
  display:none !important;
}
.upload-form.open,
.upload-form:target{
  display:block !important;
}
.meal-upload-head{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  margin:0 0 12px;
  padding-bottom:9px;
  border-bottom:1px solid #e2e8f0;
}
.meal-upload-head strong{
  display:flex;
  align-items:center;
  gap:7px;
  color:#334155;
  font-size:.8rem;
}
.meal-upload-head strong i{
  color:#f97316;
}
.meal-upload-close{
  width:29px;
  height:29px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  border:1px solid #e2e8f0;
  border-radius:7px;
  background:#fff;
  color:#64748b;
  text-decoration:none;
}
.meal-upload-close:hover{
  border-color:#fecaca;
  background:#fef2f2;
  color:#dc2626;
}
@media(max-width:700px){
  .meal-tab{
    min-height:36px;
    padding:7px 10px;
    font-size:.74rem;
  }
}


/* ============================================================
   ADD / VIEW / EDIT / CUSTOM POPUPS
============================================================ */

.add-card-header{
  text-decoration:none !important;
  color:inherit;
}

.add-card-header[aria-disabled="true"]{
  opacity:.55;
  cursor:not-allowed;
  pointer-events:none;
}

.add-card-body:target{
  display:block !important;
}

.meal-confirm-card,
.meal-message-card{
  width:min(92vw,520px);
}

.meal-confirm-content{
  display:flex;
  align-items:flex-start;
  gap:14px;
}

.meal-confirm-icon{
  width:46px;
  height:46px;
  flex:0 0 46px;
  display:flex;
  align-items:center;
  justify-content:center;
  border-radius:12px;
  font-size:18px;
}

.meal-confirm-icon.danger{
  background:#fef2f2;
  color:#dc2626;
}

.meal-confirm-content strong{
  display:block;
  margin-bottom:5px;
  color:#172033;
  font-size:.95rem;
}

.meal-confirm-content p,
.meal-message-text{
  margin:0;
  color:#64748b;
  font-size:.82rem;
  line-height:1.6;
}

.btn-danger{
  background:#dc2626;
  border-color:#dc2626;
  color:#fff;
}

.btn-danger:hover{
  background:#b91c1c;
  border-color:#b91c1c;
}

.icon-btn-view{
  color:#2563eb !important;
  background:#eff6ff !important;
  border-color:#bfdbfe !important;
}

.icon-btn-edit{
  color:#d97706 !important;
  background:#fffbeb !important;
  border-color:#fde68a !important;
}

.icon-btn-delete{
  color:#dc2626 !important;
  background:#fef2f2 !important;
  border-color:#fecaca !important;
}

.action-buttons{
  display:flex;
  align-items:center;
  gap:5px;
  flex-wrap:nowrap;
}


/* ============================================================
   GENERIC M&E VIEW / EDIT
============================================================ */

.meal-generic-card{
  width:min(94vw,780px);
}

.meal-view-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:12px;
}

.meal-view-item{
  min-width:0;
  padding:11px 12px;
  border:1px solid #e2e8f0;
  border-radius:9px;
  background:#f8fafc;
}

.meal-view-item span{
  display:block;
  margin-bottom:4px;
  color:#64748b;
  font-size:.68rem;
  font-weight:800;
  text-transform:uppercase;
  letter-spacing:.04em;
}

.meal-view-item strong{
  display:block;
  color:#172033;
  font-size:.82rem;
  line-height:1.45;
  word-break:break-word;
}

.meal-field-full{
  grid-column:1/-1;
}

.meal-document-current{
  display:flex;
  align-items:center;
  gap:7px;
  flex-wrap:wrap;
  margin-top:14px;
  padding:10px 12px;
  border:1px solid #bfdbfe;
  border-radius:8px;
  background:#eff6ff;
  color:#1d4ed8;
  font-size:.76rem;
}

.meal-document-current span{
  width:100%;
  color:#64748b;
}

.data-table td:last-child{
  min-width:108px;
}

.action-buttons{
  display:flex !important;
  align-items:center;
  gap:5px;
  flex-wrap:nowrap !important;
}

@media(max-width:640px){
  .meal-view-grid{
    grid-template-columns:1fr;
  }

  .meal-field-full{
    grid-column:auto;
  }
}


/* ============================================================
   FINAL M&E VISIBILITY / MODAL FIX
============================================================ */

.meal-panel[style*="display:block"]{
  display:block !important;
  visibility:visible !important;
  opacity:1 !important;
}

body > .meal-modal.open,
#genericMealRecordModal.open,
#mealDeleteServerModal.open{
  display:flex !important;
  visibility:visible !important;
  opacity:1 !important;
  pointer-events:auto !important;
  z-index:2147483640 !important;
}

#genericMealRecordModal .meal-modal-card,
#mealDeleteServerModal .meal-modal-card{
  display:block !important;
  visibility:visible !important;
  opacity:1 !important;
}

.meal-tabs a.meal-tab{
  pointer-events:auto !important;
  position:relative !important;
  z-index:21 !important;
}

/*
 * Extra insurance specifically for the last few tabs in the scrollable
 * row (Partnerships / Revenue / Documents in the current tab set) - if
 * something outside this file overlaps the tail end of the tab bar
 * (e.g. a fixed sidebar toggle, notification icon, or floating widget
 * from layout.php), this pushes those tabs above it too.
 */
.meal-tabs a.meal-tab:nth-last-child(-n+4){
  z-index:30 !important;
}

.meal-panel .add-card-header{
  pointer-events:auto;
}

</style>


  <div class="portal-content meal-page">
    <?php
      if (function_exists('show_flash')) {
          show_flash('vp_flash');
      }
      if (!empty($_SESSION['vp_flash_msg'])) {
          $flashType = $_SESSION['vp_flash_type'] ?? 'success';
          echo '<div class="vp-flash '.h($flashType).'" id="vpFlash"><i class="fa fa-info-circle"></i><span>'.h($_SESSION['vp_flash_msg']).'</span></div>';
          unset($_SESSION['vp_flash_msg'], $_SESSION['vp_flash_type']);
      }
    ?>

    <div class="page-header" style="margin-bottom:18px">
      <div>
        <h1 class="page-title">M&amp;E Data Tracker</h1>
        <p class="page-subtitle">Mastercard Foundation EdTech Fellowship - Learners &amp; Impact Data</p>
      </div>
    </div>

    <?php if (!$MEAL_CAN_EDIT || !$MEAL_CAN_DELETE): ?>
      <div class="meal-access-notice">
        <i class="fa fa-lock"></i>
        <div>
          <strong>M&amp;E access restrictions are active.</strong>
          <span>
            <?= !$MEAL_CAN_EDIT
                ? 'Editing, adding, importing and document uploads are currently disabled by the programme team. '
                : '' ?>
            <?= !$MEAL_CAN_DELETE
                ? 'Deleting M&amp;E records and supporting documents is currently disabled.'
                : '' ?>
          </span>
        </div>
      </div>
    <?php endif; ?>

    <!-- -- Summary Stats -- -->
    <div class="meal-stats">
      <div class="meal-stat">
        <div class="val"><?= number_format($stats['total_users']) ?></div>
        <div class="lbl">Total Learners</div>
        <div class="sub">Learners reached</div>
      </div>
      <div class="meal-stat">
        <div class="val" style="color:<?= $female_pct >= 70 ? '#059669' : '#d97706' ?>"><?= $female_pct ?>%</div>
        <div class="lbl">Female Learners</div>
        <div class="sub">Target: =70%</div>
      </div>
      <div class="meal-stat">
        <div class="val"><?= number_format($stats['refugee']) ?></div>
        <div class="lbl">Refugees</div>
        <div class="sub">Target: =15%</div>
      </div>
      <div class="meal-stat">
        <div class="val"><?= number_format($stats['pwd']) ?></div>
        <div class="lbl">PWDs</div>
        <div class="sub">Target: =15%</div>
      </div>
      <div class="meal-stat">
        <div class="val"><?= number_format($stats['teachers']) ?></div>
        <div class="lbl">Teachers/Educators</div>
        <div class="sub">Reached</div>
      </div>
      <div class="meal-stat">
        <div class="val"><?= number_format($stats['schools']) ?></div>
        <div class="lbl">Schools</div>
        <div class="sub">Institutions reached</div>
      </div>
      <div class="meal-stat">
        <div class="val"><?= number_format($stats['employees']) ?></div>
        <div class="lbl">Staff</div>
        <div class="sub">Employment records</div>
      </div>
      <div class="meal-stat">
        <div class="val">$<?= number_format($stats['total_fin'], 0) ?></div>
        <div class="lbl">Finance (USD)</div>
        <div class="sub">External capital raised</div>
      </div>
      <div class="meal-stat">
        <div class="val"><?= number_format($stats['partners']) ?></div>
        <div class="lbl">Partners</div>
        <div class="sub">Active partnerships</div>
      </div>
      <div class="meal-stat">
        <div class="val">UGX <?= $stats['total_rev'] >= 1000000 ? round($stats['total_rev']/1000000,1).'M' : number_format($stats['total_rev'],0) ?></div>
        <div class="lbl">Revenue</div>
        <div class="sub">Cumulative gross</div>
      </div>

      <button
        type="button"
        class="meal-stat yiw-stat-trigger"
        onclick="openMealModal('yiwOutcomesModal')"
        aria-haspopup="dialog"
        aria-controls="yiwOutcomesModal"
        title="Open Youth in Work outcomes"
      >
        <div class="val">
          <?= number_format(
              $yiwStats['new_self']
              + $yiwStats['additional_self']
              + $yiwStats['improved_self']
              + $yiwStats['new_wage']
              + $yiwStats['additional_wage']
              + $yiwStats['improved_wage']
          ) ?>
        </div>
        <div class="lbl">Youth in Work (YIW) Outcomes</div>
        <div class="sub">
          <i class="fa fa-eye"></i>
          View outcome breakdown
        </div>
      </button>
    </div>

    <!-- -- Compliance Targets -- -->
    <?php if ($stats['pct_denominator'] > 0): ?>
    <div class="target-bar">
      <span class="target-pill <?= $female_pct >= 70 ? 'met' : 'unmet' ?>">
        <i class="fa <?= $female_pct >= 70 ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
        Female Learners <?= $female_pct ?>% (Target 70%)
      </span>
      <?php
        $ref_pct = round(($stats['refugee']/$stats['pct_denominator'])*100);
        $pwd_pct = round(($stats['pwd']/$stats['pct_denominator'])*100);
      ?>
      <span class="target-pill <?= $ref_pct >= 15 ? 'met' : 'unmet' ?>">
        <i class="fa <?= $ref_pct >= 15 ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
        Refugees <?= $ref_pct ?>% (Target 15%)
      </span>
      <span class="target-pill <?= $pwd_pct >= 15 ? 'met' : 'unmet' ?>">
        <i class="fa <?= $pwd_pct >= 15 ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
        PWDs <?= $pwd_pct ?>% (Target 15%)
      </span>
      <?php
        $youth_pct2 = $stats['pct_denominator'] ? round(($stats['youth']/$stats['pct_denominator'])*100) : 0;
      ?>
      <span class="target-pill <?= $youth_pct2 >= 70 ? 'met' : 'unmet' ?>">
        <i class="fa fa-users"></i> 21-35: <?= $youth_pct2 ?>%
      </span>
    </div>
    <?php endif; ?>

    <!-- -- Tabs -- -->
    <div class="meal-tabs" id="mealTabsBar">
      <?php
      $tabs_def = [
        'participants' => ['icon'=>'fa-users',       'label'=>'Learners',           'count'=>$ben_total],
        'teachers'     => ['icon'=>'fa-chalkboard-teacher', 'label'=>'Teachers/Educators','count'=>$tea_total],
        'schools'      => ['icon'=>'fa-school',      'label'=>'Schools',                'count'=>$sch_total],
        'other_users'  => ['icon'=>'fa-user-friends','label'=>'Other Users',            'count'=>$oth_total],
        'employment'   => ['icon'=>'fa-briefcase',   'label'=>'Employment',             'count'=>$emp_total],
        'finance'      => ['icon'=>'fa-dollar-sign', 'label'=>'External Finance',       'count'=>$fin_total],
        'partnerships' => ['icon'=>'fa-handshake',   'label'=>'Partnerships',           'count'=>$par_total],
        'revenue'      => ['icon'=>'fa-chart-line',  'label'=>'Revenue',                'count'=>$rev_total],
        'documents'    => ['icon'=>'fa-paperclip',   'label'=>'Documents',              'count'=>count($mealDocuments)],
      ];
      foreach ($tabs_def as $k => $t):
      ?>
      <a
        href="?tab=<?= urlencode($k) ?>"
        class="meal-tab <?= $tab === $k ? 'active' : '' ?>"
        aria-current="<?= $tab === $k ? 'page' : 'false' ?>"
      >
        <i class="fa <?= $t['icon'] ?>"></i>
        <?= h($t['label']) ?>
        <span class="badge"><?= (int)$t['count'] ?></span>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- ------------------- participants ----------------------- -->
    <div
      class="meal-panel <?= $tab==='participants'?'active':'' ?>"
      id="panel-participants"
      style="<?= $tab==='participants' ? 'display:block !important;' : 'display:none !important;' ?>"
    >

      <!-- Download / Upload bar -->
      <div class="dl-bar">
        <a href="meal-data.php?download_template=participants&venture_id=<?= $venture_id ?>"
           class="btn-dl btn-dl-csv"><i class="fa fa-file-excel"></i> Download Template</a>
        <a href="meal-data.php?export=csv&type=participants&venture_id=<?= $venture_id ?>"
           class="btn-dl btn-dl-export"><i class="fa fa-file-export"></i> Export CSV</a>
        <a href="meal-data.php?export=pdf&type=participants&venture_id=<?= $venture_id ?>"
           class="btn-dl btn-dl-pdf"><i class="fa fa-file-pdf"></i> Export PDF</a>
        <?php if ($MEAL_CAN_EDIT): ?>
        <a
          href="#ben-upload"
          class="btn-dl btn-dl-upload"
          data-upload-target="ben-upload"
          title="Open file upload"
        >
          <i class="fa fa-upload"></i>
          Upload File
        </a>
        <?php else: ?>
        <span
          class="btn-dl btn-dl-upload is-disabled"
          title="File uploads are blocked by the programme team"
          aria-disabled="true"
        >
          <i class="fa fa-lock"></i>
          Upload Blocked
        </span>
        <?php endif; ?>
      </div>

      <div class="upload-form" id="ben-upload">
        <div class="meal-upload-head">
          <strong><i class="fa fa-file-upload"></i> Import File</strong>
          <a
            href="?tab=<?= urlencode($tab) ?>"
            class="meal-upload-close"
            title="Close upload form"
            aria-label="Close upload form"
          >
            <i class="fa fa-times"></i>
          </a>
        </div>
        <form method="POST" action="includes/process-meal-data.php" enctype="multipart/form-data">
          <input type="hidden" name="action" value="upload_meal_csv">
          <input type="hidden" name="type" value="participants">
          <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
          <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
            <div>
              <label>Select File (Excel or CSV)</label>
              <input type="file" name="csv_file" accept=".csv,.xlsx,.xls" required class="form-control" style="width:auto">
              <p class="form-hint">Download the template first (it has dropdowns for you to pick from), fill it in, then upload it here. Max 5MB.</p>
            </div>
            <button type="submit" class="btn btn-primary" <?= !$MEAL_CAN_EDIT ? 'disabled title="Editing is blocked by the programme team"' : '' ?> style="align-self:flex-end">
              <i class="fa fa-upload"></i> Upload &amp; Import
            </button>
          </div>
        </form>
      </div>

      <!-- Add single row form -->
      <div class="add-card <?= !$MEAL_CAN_EDIT ? 'meal-write-blocked' : '' ?>">
        <a
          href="#add-ben"
          class="add-card-header"
          data-add-target="add-ben"
          aria-controls="add-ben"
          aria-expanded="false"
        >
          <i class="fa fa-plus-circle"></i>
          <h4>Add Learner Record</h4>
          <i class="fa fa-chevron-down toggle"></i>
        </a>
        <div class="add-card-body" id="add-ben">
          <form method="POST" action="includes/process-meal-data.php">
            <input type="hidden" name="action" value="add_meal_beneficiary">
            <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
            <div class="form-grid-3">
              <div class="form-group">
                <label>Entry Date <span style="color:red">*</span></label>
                <input type="date" name="entry_date" class="form-control" required value="<?= date('Y-m-d') ?>">
              </div>
              <div class="form-group">
                <label>Learner Number (Unique ID) <span style="color:red">*</span></label>
                <input type="text" name="user_number" class="form-control" required placeholder="UG-EDT-001">
                <span class="form-hint">e.g. UG-EDT-001. Must be unique.</span>
              </div>
              <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="full_name" class="form-control" placeholder="First Surname">
              </div>
              <div class="form-group">
                <label>Phone</label>
                <input type="tel" name="phone" class="form-control" maxlength="50" placeholder="+256700000000">
              </div>
              <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" class="form-control" maxlength="190" placeholder="learner@example.com">
              </div>
              <div class="form-group">
                <label>Enrollment Category</label>
                <select name="enrollment_category" class="form-control">
                  <option value="">Select</option>
                  <option value="New">New</option>
                  <option value="Returning">Returning</option>
                </select>
              </div>
              <div class="form-group">
                <label>Gender <span style="color:red">*</span></label>
                <select name="gender" class="form-control" required>
                  <option value="">Select</option>
                  <option>Male</option>
                  <option>Female</option>
                </select>
              </div>
              <div class="form-group">
                <label>Specific Location (District/Village)</label>
                <input type="text" name="specific_location" class="form-control" placeholder="Kampala / Kiira">
              </div>
              <div class="form-group">
                <label>Urban/Rural</label>
                <select name="location_type" class="form-control">
                  <option value="">Select</option>
                  <option>Urban</option>
                  <option>Peri-Urban</option>
                  <option>Rural</option>
                </select>
              </div>
              <div class="form-group">
                <label>Age of Learner</label>
                <input type="number" name="age_category" class="form-control" step="1" placeholder="e.g. 24">
              </div>
              <div class="form-group">
                <label>Refugee / Displaced Status</label>
                <select name="refugee_status" class="form-control" onchange="toggleField('refugee-detail','Yes',this.value)">
                  <option value="No">No</option>
                  <option value="Yes">Yes</option>
                </select>
              </div>
              <div class="form-group" id="refugee-detail" style="display:none">
                <label>Refugee Settlement</label>
                <select
                  name="refugee_settlement"
                  id="refugeeSettlement"
                  class="form-control"
                  onchange="toggleRefugeeSettlementOther(this)"
                >
                  <option value="">Select settlement</option>
                  <option value="Bidi bidi Refugee Settlement">Bidi bidi Refugee Settlement</option>
                  <option value="Nakivale Refugee Settlement">Nakivale Refugee Settlement</option>
                  <option value="Rhino Camp Refugee Settlement">Rhino Camp Refugee Settlement</option>
                  <option value="Palorinya Refugee Settlement">Palorinya Refugee Settlement</option>
                  <option value="Kyangwali Refugee Settlement">Kyangwali Refugee Settlement</option>
                  <option value="Adjumani Settlements">Adjumani Settlements</option>
                  <option value="Kyaka II Refugee Settlement">Kyaka II Refugee Settlement</option>
                  <option value="Other (please specify)">Other (please specify)</option>
                </select>
                <input
                  type="text"
                  name="refugee_settlement_other"
                  id="refugeeSettlementOther"
                  class="form-control"
                  maxlength="255"
                  placeholder="Please specify the refugee settlement"
                  style="display:none;margin-top:8px"
                >
              </div>
              <div class="form-group">
                <label>PWD Status</label>
                <select name="pwd_status" class="form-control" onchange="toggleField('pwd-detail','Yes',this.value)">
                  <option value="No">No</option>
                  <option value="Yes">Yes</option>
                </select>
              </div>
              <div class="form-group" id="pwd-detail" style="display:none">
                <label>Type of Impairment</label>
                <input type="text" name="impairment_type" class="form-control" placeholder="e.g. Visual, Hearing">
              </div>
              <div class="form-group">
                <label>Education Level</label>
                <select name="education_level" class="form-control">
                  <option value="">Select</option>
                  <option>Primary</option>
                  <option>Secondary</option>
                  <option>TVET</option>
                  <option>Tertiary</option>
                </select>
              </div>
              <div class="form-group">
                <label>Learner Status <span style="color:red">*</span></label>
                <select name="user_status" class="form-control" required>
                  <option value="">Select</option>
                  <option>Active</option>
                  <option>On hold</option>
                  <option>Dropped</option>
                  <option>Completed</option>
                  <option>Certified</option>
                </select>
              </div>
              <div class="form-group">
                <label>Verified Learner Outcomes</label>
                <select name="verified_learner_outcome" id="verifiedLearnerOutcome" class="form-control" onchange="toggleVerifiedLearnerOutcomeOther(this)">
                  <option value="">Select</option>
                  <option>Increased pass rates</option>
                  <option>Improved grades in STEM</option>
                  <option>Increased Agency and Voice</option>
                  <option>Others please specify</option>
                </select>
              </div>
              <div class="form-group" id="verifiedLearnerOutcomeOtherWrap" style="display:none">
                <label>Other Verified Learner Outcome</label>
                <input type="text" name="verified_learner_outcome_other" id="verifiedLearnerOutcomeOther" class="form-control" maxlength="255" placeholder="Please specify the verified learner outcome">
              </div>
              <div class="form-group">
                <label>YIW (Youth In Work) Before</label>
                <select name="working_at_entry" class="form-control">
                  <option value="">Select</option>
                  <option value="Self-employment">Self-employment</option>
                  <option value="Wage employment">Wage employment</option>
                  <option value="No">No</option>
                </select>
              </div>
              <div class="form-group" style="grid-column:span 2">
                <label>Transformation Objective</label>
                <input type="text" name="transformation_objective" class="form-control"
                       placeholder="e.g. Education, Scholarships & Skilling">
              </div>
              <div class="form-group">
                <label>After-work Status</label>
                <select
                  name="in_work_status"
                  id="afterWorkStatus"
                  class="form-control"
                  onchange="toggleAfterWorkPathway(this)"
                >
                  <option value="">Select</option>
                  <option value="New">New</option>
                  <option value="Additional">Additional</option>
                  <option value="Improved">Improved</option>
                </select>
              </div>
              <div class="form-group" id="afterWorkPathwayWrap" style="display:none">
                <label>Through</label>
                <select name="after_work_pathway" id="afterWorkPathway" class="form-control">
                  <option value="">Select pathway</option>
                  <option value="Self-employment">Self-employment</option>
                  <option value="Wage employment">Wage employment</option>
                </select>
              </div>

            </div>
            <div style="margin-top:16px;text-align:right">
              <button type="submit" class="btn btn-primary" <?= !$MEAL_CAN_EDIT ? 'disabled title="Editing is blocked by the programme team"' : '' ?>><i class="fa fa-save"></i> Save Learner</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Table -->
      <div class="data-table-wrap">
        <?php if ($ben_total === 0): ?>
          <div class="no-data"><i class="fa fa-users" style="font-size:2rem;margin-bottom:8px;display:block;color:#d1d5db"></i>
            No learner records yet.<br>Use the form above or upload a CSV to get started.</div>
        <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>Date</th><th>Learner ID</th><th>Name</th><th>Phone</th><th>Email</th><th>Enrollment</th>
              <th>Gender</th><th>Location</th><th>Urban/Rural</th><th>Age</th>
              <th>Refugee</th><th>PWD</th><th>Education</th><th>Status</th><th>Verified Learner Outcomes</th>
              <th>YIW Before</th><th>After-work Status</th><th>Through</th>
              <th>Created At</th><th>Updated At</th><th></th>
            </tr>
          </thead>
          <tbody>
            <?php while ($r = $ben_rows->fetch_assoc()): ?>
            <tr>
              <td><?= h(date('d/m/Y', strtotime($r['entry_date']))) ?></td>
              <td><code style="font-size:11px"><?= h($r['user_number']) ?></code></td>
              <td><?= h($r['full_name'] ?: '-') ?></td>
              <td><?= h($r['phone'] ?? '-') ?: '-' ?></td>
              <td><?= h($r['email'] ?? '-') ?: '-' ?></td>
              <td><?= h($r['enrollment_category'] ?? '-') ?: '-' ?></td>
              <td><span class="badge-tag <?= $r['gender']==='Female'?'badge-f':'badge-m' ?>"><?= h($r['gender']) ?></span></td>
              <td><?= h($r['specific_location'] ?: '-') ?></td>
              <td><?= h($r['location_type'] ?: '-') ?></td>
              <td><?= h($r['age_category']) ?></td>
              <td><span class="badge-tag <?= $r['refugee_status']==='Yes'?'badge-yes':'badge-no' ?>"><?= $r['refugee_status']==='Yes' ? '? '.h($r['refugee_settlement']) : 'No' ?></span></td>
              <td><span class="badge-tag <?= $r['pwd_status']==='Yes'?'badge-yes':'badge-no' ?>"><?= $r['pwd_status']==='Yes' ? '? '.h($r['impairment_type']) : 'No' ?></span></td>
              <td><?= h($r['education_level'] ?: '-') ?></td>
              <td><span class="badge-tag"><?= h($r['user_status'] ?? '-') ?></span></td>
              <td class="meal-wrap-cell">
                <?= h(($r['verified_learner_outcome'] ?? '') ?: '-') ?>
                <?php if (($r['verified_learner_outcome'] ?? '') === 'Others please specify' && !empty($r['verified_learner_outcome_other'])): ?>
                  <br><small><?= h($r['verified_learner_outcome_other']) ?></small>
                <?php endif; ?>
              </td>
              <td><?= h($r['working_at_entry'] ?? '-') ?: '-' ?></td>
              <td><?= h($r['in_work_status'] ?: '-') ?></td>
              <td><?= h($r['after_work_pathway'] ?? '-') ?: '-' ?></td>
              <td><?= h(fmt_created($r['created_at'] ?? null)) ?></td>
              <td><?= h(fmt_created($r['updated_at'] ?? null)) ?></td>
              <td>
                <div class="action-buttons">
                  <a
                    href="#" data-meal-row-action="view" data-meal-type="participants" data-meal-record='<?= h(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                    class="icon-btn icon-btn-view"
                    title="View learner"
                  >
                    <i class="fa fa-eye"></i>
                  </a>
                  <a
                    href="#" data-meal-row-action="edit" data-meal-type="participants" data-meal-record='<?= h(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                    class="icon-btn icon-btn-edit <?= !$MEAL_CAN_EDIT ? 'is-disabled' : '' ?>"
                    title="<?= $MEAL_CAN_EDIT ? 'Edit learner' : 'Editing blocked by programme team' ?>"
                    <?= !$MEAL_CAN_EDIT ? 'aria-disabled="true"' : '' ?>
                  >
                    <i class="fa fa-edit"></i>
                  </a>
                  <a
                    href="#" data-meal-row-action="delete" data-meal-type="participants" data-meal-record='<?= h(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                    class="icon-btn icon-btn-delete <?= !$MEAL_CAN_DELETE ? 'is-disabled' : '' ?>"
                    title="<?= $MEAL_CAN_DELETE ? 'Delete record' : 'Deleting blocked by programme team' ?>"
                    <?= !$MEAL_CAN_DELETE ? 'aria-disabled="true"' : '' ?>
                  >
                    <i class="fa fa-trash"></i>
                  </a>
                </div>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <?= render_pagination($ben_page, $ben_pages, 'ben') ?>

    </div><!-- /participants panel -->


    <!-- ------------------- TEACHERS / EDUCATORS ----------------- -->
    <div
      class="meal-panel <?= $tab==='teachers'?'active':'' ?>"
      id="panel-teachers"
      style="<?= $tab==='teachers' ? 'display:block !important;' : 'display:none !important;' ?>"
    >
      <div class="dl-bar">
        <a href="meal-data.php?download_template=teachers&venture_id=<?= $venture_id ?>"
           class="btn-dl btn-dl-csv"><i class="fa fa-file-excel"></i> Download Template</a>
        <a href="meal-data.php?export=csv&type=teachers&venture_id=<?= $venture_id ?>"
           class="btn-dl btn-dl-export"><i class="fa fa-file-export"></i> Export CSV</a>
        <a href="meal-data.php?export=pdf&type=teachers&venture_id=<?= $venture_id ?>"
           class="btn-dl btn-dl-pdf"><i class="fa fa-file-pdf"></i> Export PDF</a>
        <?php if ($MEAL_CAN_EDIT): ?>
        <a
          href="#tea-upload"
          class="btn-dl btn-dl-upload"
          data-upload-target="tea-upload"
          title="Open file upload"
        >
          <i class="fa fa-upload"></i>
          Upload File
        </a>
        <?php else: ?>
        <span
          class="btn-dl btn-dl-upload is-disabled"
          title="File uploads are blocked by the programme team"
          aria-disabled="true"
        >
          <i class="fa fa-lock"></i>
          Upload Blocked
        </span>
        <?php endif; ?>
      </div>
      <div class="upload-form" id="tea-upload">
        <div class="meal-upload-head">
          <strong><i class="fa fa-file-upload"></i> Import File</strong>
          <a
            href="?tab=<?= urlencode($tab) ?>"
            class="meal-upload-close"
            title="Close upload form"
            aria-label="Close upload form"
          >
            <i class="fa fa-times"></i>
          </a>
        </div>
        <form method="POST" action="includes/process-meal-data.php" enctype="multipart/form-data">
          <input type="hidden" name="action" value="upload_meal_csv">
          <input type="hidden" name="type" value="teachers">
          <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
          <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
            <div>
              <label>Select File (Excel or CSV)</label>
              <input type="file" name="csv_file" accept=".csv,.xlsx,.xls" required class="form-control" style="width:auto">
              <p class="form-hint">Download the template first (it has dropdowns for you to pick from), fill it in, then upload it here. Max 5MB.</p>
            </div>
            <button type="submit" class="btn btn-primary" <?= !$MEAL_CAN_EDIT ? 'disabled title="Editing is blocked by the programme team"' : '' ?> style="align-self:flex-end">
              <i class="fa fa-upload"></i> Upload &amp; Import
            </button>
          </div>
        </form>
      </div>

      <div class="add-card <?= !$MEAL_CAN_EDIT ? 'meal-write-blocked' : '' ?>">
        <a
          href="#add-tea"
          class="add-card-header"
          data-add-target="add-tea"
          aria-controls="add-tea"
          aria-expanded="false"
        >
          <i class="fa fa-plus-circle"></i>
          <h4>Add Teacher / Educator Record</h4>
          <i class="fa fa-chevron-down toggle"></i>
        </a>
        <div class="add-card-body" id="add-tea">
          <form method="POST" action="includes/process-meal-data.php">
            <input type="hidden" name="action" value="add_meal_teacher">
            <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
            <div class="form-grid-3">
              <div class="form-group">
                <label>Date <span style="color:red">*</span></label>
                <input type="date" name="entry_date" class="form-control" required value="<?= date('Y-m-d') ?>">
              </div>
              <div class="form-group">
                <label>Teacher / Educator Name</label>
                <input type="text" name="teacher_name" class="form-control" placeholder="First Surname">
              </div>
              <div class="form-group">
                <label>Gender</label>
                <select name="gender" class="form-control">
                  <option value="">Select</option>
                  <option>Male</option><option>Female</option><option>Other</option>
                </select>
              </div>
              <div class="form-group">
                <label>Age Category <span class="form-hint" style="display:inline">(Youth focus: Under 35)</span></label>
                <select name="age_category" class="form-control">
                  <option value="">Select</option>
                  <option>13-20</option>
                  <option>21-35</option>
                  <option>Above 35</option>
                </select>
              </div>
              <div class="form-group">
                <label>Refugee / Displaced Status</label>
                <select name="refugee_status" class="form-control">
                  <option value="No">No</option>
                  <option value="Yes">Yes</option>
                </select>
              </div>
              <div class="form-group">
                <label>Host Community</label>
                <select name="host_community" class="form-control">
                  <option value="No">No</option>
                  <option value="Yes">Yes</option>
                </select>
              </div>
              <div class="form-group">
                <label>Urban/Rural</label>
                <select name="location_type" class="form-control">
                  <option value="">Select</option>
                  <option>Rural</option>
                  <option>Urban</option>
                  <option>Peri-Urban</option>
                </select>
              </div>
              <div class="form-group">
                <label>PWD Status</label>
                <select name="pwd_status" class="form-control">
                  <option value="No">No</option>
                  <option value="Yes">Yes</option>
                </select>
              </div>
            </div>
            <div style="margin-top:16px;text-align:right">
              <button type="submit" class="btn btn-primary" <?= !$MEAL_CAN_EDIT ? 'disabled title="Editing is blocked by the programme team"' : '' ?>><i class="fa fa-save"></i> Save Teacher / Educator</button>
            </div>
          </form>
        </div>
      </div>

      <div class="data-table-wrap">
        <?php if ($tea_total === 0): ?>
          <div class="no-data"><i class="fa fa-chalkboard-teacher" style="font-size:2rem;margin-bottom:8px;display:block;color:#d1d5db"></i>No teacher/educator records yet.</div>
        <?php else: ?>
        <table class="data-table">
          <thead><tr>
            <th>Date</th><th>Name</th><th>Gender</th><th>Age</th>
            <th>Refugee</th><th>Host Community</th><th>Location</th><th>PWD</th><th>Created At</th><th></th>
          </tr></thead>
          <tbody>
            <?php while ($r = $tea_rows->fetch_assoc()): ?>
            <tr>
              <td><?= h(date('d/m/Y',strtotime($r['entry_date']))) ?></td>
              <td><?= h($r['teacher_name'] ?: '-') ?></td>
              <td><span class="badge-tag <?= $r['gender']==='Female'?'badge-f':'badge-m' ?>"><?= h($r['gender'] ?: '-') ?></span></td>
              <td><?= h($r['age_category'] ?: '-') ?></td>
              <td><span class="badge-tag <?= $r['refugee_status']==='Yes'?'badge-yes':'badge-no' ?>"><?= h($r['refugee_status']) ?></span></td>
              <td><span class="badge-tag <?= $r['host_community']==='Yes'?'badge-yes':'badge-no' ?>"><?= h($r['host_community']) ?></span></td>
              <td><?= h($r['location_type'] ?: '-') ?></td>
              <td><span class="badge-tag <?= $r['pwd_status']==='Yes'?'badge-yes':'badge-no' ?>"><?= h($r['pwd_status']) ?></span></td>
              <td><?= h(fmt_created($r['created_at'] ?? null)) ?></td>
              <td>
                <div class="action-buttons">
                  <a
                    href="#" data-meal-row-action="view" data-meal-type="teachers" data-meal-record='<?= h(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                    class="icon-btn icon-btn-view"
                    title="View record"
                  >
                    <i class="fa fa-eye"></i>
                  </a>

                  <a
                    href="#" data-meal-row-action="edit" data-meal-type="teachers" data-meal-record='<?= h(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                    class="icon-btn icon-btn-edit <?= !$MEAL_CAN_EDIT ? 'is-disabled' : '' ?>"
                    title="<?= $MEAL_CAN_EDIT ? 'Edit record' : 'Editing blocked by programme team' ?>"
                    <?= !$MEAL_CAN_EDIT ? 'aria-disabled="true"' : '' ?>
                  >
                    <i class="fa fa-edit"></i>
                  </a>

                  <a
                    href="#" data-meal-row-action="delete" data-meal-type="teachers" data-meal-record='<?= h(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                    class="icon-btn icon-btn-delete <?= !$MEAL_CAN_DELETE ? 'is-disabled' : '' ?>"
                    title="<?= $MEAL_CAN_DELETE ? 'Delete record' : 'Deleting blocked by programme team' ?>"
                    <?= !$MEAL_CAN_DELETE ? 'aria-disabled="true"' : '' ?>
                  >
                    <i class="fa fa-trash"></i>
                  </a>
                </div>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <?= render_pagination($tea_page, $tea_pages, 'tea') ?>
    </div>


    <!-- ------------------- INSTITUTIONAL LEVEL (SCHOOLS) --------- -->
    <div
      class="meal-panel <?= $tab==='schools'?'active':'' ?>"
      id="panel-schools"
      style="<?= $tab==='schools' ? 'display:block !important;' : 'display:none !important;' ?>"
    >
      <div class="dl-bar">
        <a href="meal-data.php?download_template=schools&venture_id=<?= $venture_id ?>"
           class="btn-dl btn-dl-csv"><i class="fa fa-file-excel"></i> Download Template</a>
        <a href="meal-data.php?export=csv&type=schools&venture_id=<?= $venture_id ?>"
           class="btn-dl btn-dl-export"><i class="fa fa-file-export"></i> Export CSV</a>
        <a href="meal-data.php?export=pdf&type=schools&venture_id=<?= $venture_id ?>"
           class="btn-dl btn-dl-pdf"><i class="fa fa-file-pdf"></i> Export PDF</a>
        <?php if ($MEAL_CAN_EDIT): ?>
        <a
          href="#sch-upload"
          class="btn-dl btn-dl-upload"
          data-upload-target="sch-upload"
          title="Open file upload"
        >
          <i class="fa fa-upload"></i>
          Upload File
        </a>
        <?php else: ?>
        <span
          class="btn-dl btn-dl-upload is-disabled"
          title="File uploads are blocked by the programme team"
          aria-disabled="true"
        >
          <i class="fa fa-lock"></i>
          Upload Blocked
        </span>
        <?php endif; ?>
      </div>
      <div class="upload-form" id="sch-upload">
        <div class="meal-upload-head">
          <strong><i class="fa fa-file-upload"></i> Import File</strong>
          <a
            href="?tab=<?= urlencode($tab) ?>"
            class="meal-upload-close"
            title="Close upload form"
            aria-label="Close upload form"
          >
            <i class="fa fa-times"></i>
          </a>
        </div>
        <form method="POST" action="includes/process-meal-data.php" enctype="multipart/form-data">
          <input type="hidden" name="action" value="upload_meal_csv">
          <input type="hidden" name="type" value="schools">
          <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
          <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
            <div>
              <label>Select File (Excel or CSV)</label>
              <input type="file" name="csv_file" accept=".csv,.xlsx,.xls" required class="form-control" style="width:auto">
            </div>
            <button type="submit" class="btn btn-primary" <?= !$MEAL_CAN_EDIT ? 'disabled title="Editing is blocked by the programme team"' : '' ?> style="align-self:flex-end">
              <i class="fa fa-upload"></i> Import
            </button>
          </div>
        </form>
      </div>

      <div class="add-card <?= !$MEAL_CAN_EDIT ? 'meal-write-blocked' : '' ?>">
        <a
          href="#add-sch"
          class="add-card-header"
          data-add-target="add-sch"
          aria-controls="add-sch"
          aria-expanded="false"
        >
          <i class="fa fa-plus-circle"></i><h4>Add School / Institution Record</h4>
          <i class="fa fa-chevron-down toggle"></i>
        </a>
        <div class="add-card-body" id="add-sch">
          <form method="POST" action="includes/process-meal-data.php">
            <input type="hidden" name="action" value="add_meal_school">
            <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
            <div class="form-grid-3">
              <div class="form-group">
                <label>Date <span style="color:red">*</span></label>
                <input type="date" name="entry_date" class="form-control" required value="<?= date('Y-m-d') ?>">
              </div>
              <div class="form-group">
                <label>School / Institution Name <span style="color:red">*</span></label>
                <input type="text" name="school_name" class="form-control" required placeholder="e.g. St. Mary's Secondary School">
              </div>
              <div class="form-group">
                <label>Geographical Location</label>
                <select name="location_type" class="form-control">
                  <option value="">Select</option>
                  <option>Rural</option>
                  <option>Urban</option>
                  <option>Peri-Urban</option>
                </select>
              </div>
              <div class="form-group">
                <label>Ownership Type</label>
                <select name="ownership_type" class="form-control">
                  <option value="">Select</option>
                  <option>Government-aided</option>
                  <option>Private</option>
                </select>
              </div>
              <div class="form-group">
                <label>Level of School</label>
                <select name="school_level" class="form-control">
                  <option value="">Select</option>
                  <option>Secondary</option>
                  <option>Tertiary</option>
                  <option>BTVET</option>
                </select>
              </div>
            </div>
            <div style="margin-top:16px;text-align:right">
              <button type="submit" class="btn btn-primary" <?= !$MEAL_CAN_EDIT ? 'disabled title="Editing is blocked by the programme team"' : '' ?>><i class="fa fa-save"></i> Save School Record</button>
            </div>
          </form>
        </div>
      </div>

      <div class="data-table-wrap">
        <?php if ($sch_total === 0): ?>
          <div class="no-data"><i class="fa fa-school" style="font-size:2rem;margin-bottom:8px;display:block;color:#d1d5db"></i>No school/institution records yet.</div>
        <?php else: ?>
        <table class="data-table">
          <thead><tr>
            <th>Date</th><th>School / Institution</th><th>Location</th><th>Ownership</th><th>Level</th><th>Created At</th><th></th>
          </tr></thead>
          <tbody>
            <?php while ($r = $sch_rows->fetch_assoc()): ?>
            <tr>
              <td><?= h(date('d/m/Y',strtotime($r['entry_date']))) ?></td>
              <td><strong><?= h($r['school_name']) ?></strong></td>
              <td><?= h($r['location_type'] ?: '-') ?></td>
              <td><?= h($r['ownership_type'] ?: '-') ?></td>
              <td><?= h($r['school_level'] ?: '-') ?></td>
              <td><?= h(fmt_created($r['created_at'] ?? null)) ?></td>
              <td>
                <div class="action-buttons">
                  <a
                    href="#" data-meal-row-action="view" data-meal-type="schools" data-meal-record='<?= h(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                    class="icon-btn icon-btn-view"
                    title="View record"
                  >
                    <i class="fa fa-eye"></i>
                  </a>

                  <a
                    href="#" data-meal-row-action="edit" data-meal-type="schools" data-meal-record='<?= h(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                    class="icon-btn icon-btn-edit <?= !$MEAL_CAN_EDIT ? 'is-disabled' : '' ?>"
                    title="<?= $MEAL_CAN_EDIT ? 'Edit record' : 'Editing blocked by programme team' ?>"
                    <?= !$MEAL_CAN_EDIT ? 'aria-disabled="true"' : '' ?>
                  >
                    <i class="fa fa-edit"></i>
                  </a>

                  <a
                    href="#" data-meal-row-action="delete" data-meal-type="schools" data-meal-record='<?= h(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                    class="icon-btn icon-btn-delete <?= !$MEAL_CAN_DELETE ? 'is-disabled' : '' ?>"
                    title="<?= $MEAL_CAN_DELETE ? 'Delete record' : 'Deleting blocked by programme team' ?>"
                    <?= !$MEAL_CAN_DELETE ? 'aria-disabled="true"' : '' ?>
                  >
                    <i class="fa fa-trash"></i>
                  </a>
                </div>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <?= render_pagination($sch_page, $sch_pages, 'sch') ?>
    </div>


    <!-- ------------------- OTHER USERS --------------------------- -->
    <div
      class="meal-panel <?= $tab==='other_users'?'active':'' ?>"
      id="panel-other_users"
      style="<?= $tab==='other_users' ? 'display:block !important;' : 'display:none !important;' ?>"
    >
      <div class="dl-bar">
        <a href="meal-data.php?download_template=other_users&venture_id=<?= $venture_id ?>"
           class="btn-dl btn-dl-csv"><i class="fa fa-file-excel"></i> Download Template</a>
        <a href="meal-data.php?export=csv&type=other_users&venture_id=<?= $venture_id ?>"
           class="btn-dl btn-dl-export"><i class="fa fa-file-export"></i> Export CSV</a>
        <a href="meal-data.php?export=pdf&type=other_users&venture_id=<?= $venture_id ?>"
           class="btn-dl btn-dl-pdf"><i class="fa fa-file-pdf"></i> Export PDF</a>
        <?php if ($MEAL_CAN_EDIT): ?>
        <a
          href="#oth-upload"
          class="btn-dl btn-dl-upload"
          data-upload-target="oth-upload"
          title="Open file upload"
        >
          <i class="fa fa-upload"></i>
          Upload File
        </a>
        <?php else: ?>
        <span
          class="btn-dl btn-dl-upload is-disabled"
          title="File uploads are blocked by the programme team"
          aria-disabled="true"
        >
          <i class="fa fa-lock"></i>
          Upload Blocked
        </span>
        <?php endif; ?>
      </div>
      <div class="upload-form" id="oth-upload">
        <div class="meal-upload-head">
          <strong><i class="fa fa-file-upload"></i> Import File</strong>
          <a
            href="?tab=<?= urlencode($tab) ?>"
            class="meal-upload-close"
            title="Close upload form"
            aria-label="Close upload form"
          >
            <i class="fa fa-times"></i>
          </a>
        </div>
        <form method="POST" action="includes/process-meal-data.php" enctype="multipart/form-data">
          <input type="hidden" name="action" value="upload_meal_csv">
          <input type="hidden" name="type" value="other_users">
          <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
          <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
            <div>
              <label>Select File (Excel or CSV)</label>
              <input type="file" name="csv_file" accept=".csv,.xlsx,.xls" required class="form-control" style="width:auto">
            </div>
            <button type="submit" class="btn btn-primary" <?= !$MEAL_CAN_EDIT ? 'disabled title="Editing is blocked by the programme team"' : '' ?> style="align-self:flex-end">
              <i class="fa fa-upload"></i> Import
            </button>
          </div>
        </form>
      </div>

      <div class="add-card <?= !$MEAL_CAN_EDIT ? 'meal-write-blocked' : '' ?>">
        <a
          href="#add-oth"
          class="add-card-header"
          data-add-target="add-oth"
          aria-controls="add-oth"
          aria-expanded="false"
        >
          <i class="fa fa-plus-circle"></i><h4>Add Other User Record</h4>
          <i class="fa fa-chevron-down toggle"></i>
        </a>
        <div class="add-card-body" id="add-oth">
          <form method="POST" action="includes/process-meal-data.php">
            <input type="hidden" name="action" value="add_meal_other_user">
            <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
            <div class="form-grid-3">
              <div class="form-group">
                <label>Date <span style="color:red">*</span></label>
                <input type="date" name="entry_date" class="form-control" required value="<?= date('Y-m-d') ?>">
              </div>
              <div class="form-group">
                <label>Gender</label>
                <select name="gender" class="form-control">
                  <option value="">Select</option>
                  <option>Male</option><option>Female</option><option>Other</option>
                </select>
              </div>
              <div class="form-group">
                <label>Host Community</label>
                <select name="host_community" class="form-control">
                  <option value="No">No</option>
                  <option value="Yes">Yes</option>
                </select>
              </div>
              <div class="form-group">
                <label>Urban/Rural</label>
                <select name="location_type" class="form-control">
                  <option value="">Select</option>
                  <option>Rural</option>
                  <option>Urban</option>
                  <option>Peri-Urban</option>
                </select>
              </div>
              <div class="form-group">
                <label>PWD Status</label>
                <select name="pwd_status" class="form-control">
                  <option value="No">No</option>
                  <option value="Yes">Yes</option>
                </select>
              </div>
              <div class="form-group">
                <label>Education Level</label>
                <select name="education_level" class="form-control">
                  <option value="">Select</option>
                  <option>Primary</option>
                  <option>Secondary</option>
                  <option>Tertiary</option>
                </select>
              </div>
            </div>
            <div style="margin-top:16px;text-align:right">
              <button type="submit" class="btn btn-primary" <?= !$MEAL_CAN_EDIT ? 'disabled title="Editing is blocked by the programme team"' : '' ?>><i class="fa fa-save"></i> Save Other User</button>
            </div>
          </form>
        </div>
      </div>

      <div class="data-table-wrap">
        <?php if ($oth_total === 0): ?>
          <div class="no-data"><i class="fa fa-user-friends" style="font-size:2rem;margin-bottom:8px;display:block;color:#d1d5db"></i>No other user records yet.</div>
        <?php else: ?>
        <table class="data-table">
          <thead><tr>
            <th>Date</th><th>Gender</th>
            <th>Host Community</th><th>Location</th><th>PWD</th>
            <th>Education</th><th>Created At</th><th></th>
          </tr></thead>
          <tbody>
            <?php while ($r = $oth_rows->fetch_assoc()): ?>
            <tr>
              <td><?= h(date('d/m/Y',strtotime($r['entry_date']))) ?></td>
              <td><span class="badge-tag <?= $r['gender']==='Female'?'badge-f':'badge-m' ?>"><?= h($r['gender'] ?: '-') ?></span></td>
              <td><span class="badge-tag <?= $r['host_community']==='Yes'?'badge-yes':'badge-no' ?>"><?= h($r['host_community']) ?></span></td>
              <td><?= h($r['location_type'] ?: '-') ?></td>
              <td><span class="badge-tag <?= $r['pwd_status']==='Yes'?'badge-yes':'badge-no' ?>"><?= h($r['pwd_status']) ?></span></td>
              <td><?= h($r['education_level'] ?: '-') ?></td>
              <td><?= h(fmt_created($r['created_at'] ?? null)) ?></td>
              <td>
                <div class="action-buttons">
                  <a
                    href="#" data-meal-row-action="view" data-meal-type="other_users" data-meal-record='<?= h(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                    class="icon-btn icon-btn-view"
                    title="View record"
                  >
                    <i class="fa fa-eye"></i>
                  </a>

                  <a
                    href="#" data-meal-row-action="edit" data-meal-type="other_users" data-meal-record='<?= h(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                    class="icon-btn icon-btn-edit <?= !$MEAL_CAN_EDIT ? 'is-disabled' : '' ?>"
                    title="<?= $MEAL_CAN_EDIT ? 'Edit record' : 'Editing blocked by programme team' ?>"
                    <?= !$MEAL_CAN_EDIT ? 'aria-disabled="true"' : '' ?>
                  >
                    <i class="fa fa-edit"></i>
                  </a>

                  <a
                    href="#" data-meal-row-action="delete" data-meal-type="other_users" data-meal-record='<?= h(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                    class="icon-btn icon-btn-delete <?= !$MEAL_CAN_DELETE ? 'is-disabled' : '' ?>"
                    title="<?= $MEAL_CAN_DELETE ? 'Delete record' : 'Deleting blocked by programme team' ?>"
                    <?= !$MEAL_CAN_DELETE ? 'aria-disabled="true"' : '' ?>
                  >
                    <i class="fa fa-trash"></i>
                  </a>
                </div>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <?= render_pagination($oth_page, $oth_pages, 'oth') ?>
    </div>


    <!-- ------------------- EMPLOYMENT --------------------------- -->
    <div
      class="meal-panel <?= $tab==='employment'?'active':'' ?>"
      id="panel-employment"
      style="<?= $tab==='employment' ? 'display:block !important;' : 'display:none !important;' ?>"
    >
      <div class="dl-bar">
        <a href="meal-data.php?download_template=employment&venture_id=<?= $venture_id ?>"
           class="btn-dl btn-dl-csv"><i class="fa fa-file-excel"></i> Download Template</a>
        <a href="meal-data.php?export=csv&type=employment&venture_id=<?= $venture_id ?>"
           class="btn-dl btn-dl-export"><i class="fa fa-file-export"></i> Export CSV</a>
        <a href="meal-data.php?export=pdf&type=employment&venture_id=<?= $venture_id ?>"
           class="btn-dl btn-dl-pdf"><i class="fa fa-file-pdf"></i> Export PDF</a>
        <?php if ($MEAL_CAN_EDIT): ?>
        <a
          href="#emp-upload"
          class="btn-dl btn-dl-upload"
          data-upload-target="emp-upload"
          title="Open file upload"
        >
          <i class="fa fa-upload"></i>
          Upload File
        </a>
        <?php else: ?>
        <span
          class="btn-dl btn-dl-upload is-disabled"
          title="File uploads are blocked by the programme team"
          aria-disabled="true"
        >
          <i class="fa fa-lock"></i>
          Upload Blocked
        </span>
        <?php endif; ?>
      </div>
      <div class="upload-form" id="emp-upload">
        <div class="meal-upload-head">
          <strong><i class="fa fa-file-upload"></i> Import File</strong>
          <a
            href="?tab=<?= urlencode($tab) ?>"
            class="meal-upload-close"
            title="Close upload form"
            aria-label="Close upload form"
          >
            <i class="fa fa-times"></i>
          </a>
        </div>
        <form method="POST" action="includes/process-meal-data.php" enctype="multipart/form-data">
          <input type="hidden" name="action" value="upload_meal_csv">
          <input type="hidden" name="type" value="employment">
          <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
          <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
            <div>
              <label>CSV file</label>
              <input type="file" name="csv_file" accept=".csv,.xlsx,.xls" required class="form-control" style="width:auto">
            </div>
            <button type="submit" class="btn btn-primary" <?= !$MEAL_CAN_EDIT ? 'disabled title="Editing is blocked by the programme team"' : '' ?> style="align-self:flex-end">
              <i class="fa fa-upload"></i> Import
            </button>
          </div>
        </form>
      </div>

      <div class="add-card <?= !$MEAL_CAN_EDIT ? 'meal-write-blocked' : '' ?>">
        <a
          href="#add-emp"
          class="add-card-header"
          data-add-target="add-emp"
          aria-controls="add-emp"
          aria-expanded="false"
        >
          <i class="fa fa-plus-circle"></i><h4>Add Employment Record</h4>
          <i class="fa fa-chevron-down toggle"></i>
        </a>
        <div class="add-card-body" id="add-emp">
          <form method="POST" action="includes/process-meal-data.php">
            <input type="hidden" name="action" value="add_meal_employment">
            <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
            <div class="form-grid-3">
              <div class="form-group">
                <label>Placement Date <span style="color:red">*</span></label>
                <input type="date" name="placement_date" class="form-control" required value="<?= date('Y-m-d') ?>">
              </div>
              <div class="form-group">
                <label>Full Name <span style="color:red">*</span></label>
                <input type="text" name="full_name" class="form-control" required placeholder="First Surname">
              </div>
              <div class="form-group">
                <label>Gender</label>
                <select name="gender" class="form-control">
                  <option value="">Select</option>
                  <option>Male</option><option>Female</option>
                </select>
              </div>
              <div class="form-group">
                <label>Age Category</label>
                <select name="age_category" class="form-control">
                  <option value="">Select</option>
                  <option>13-20</option>
                  <option>21-35</option>
                  <option>Above 35</option>
                </select>
              </div>
              <div class="form-group">
                <label>PWD Status</label>
                <select name="pwd_status" class="form-control">
                  <option value="No">No</option><option value="Yes">Yes</option>
                </select>
              </div>
              <div class="form-group">
                <label>Employment Type</label>
                <select name="employment_type" class="form-control">
                  <option value="">Select</option>
                  <option>Full-Time</option><option>Part-Time</option>
                  <option>Contract</option><option>Volunteer</option>
                </select>
              </div>
              <div class="form-group">
                <label>Job Title / Role <span style="color:red">*</span></label>
                <input type="text" name="job_title" class="form-control" required placeholder="e.g. Software Developer">
              </div>
              <div class="form-group">
                <label>Status</label>
                <select name="status" class="form-control">
                  <option>Active</option><option>Suspended</option>
                  <option>Terminated</option><option>On Leave</option>
                </select>
              </div>
            </div>
            <div style="margin-top:16px;text-align:right">
              <button type="submit" class="btn btn-primary" <?= !$MEAL_CAN_EDIT ? 'disabled title="Editing is blocked by the programme team"' : '' ?>><i class="fa fa-save"></i> Save Staff Record</button>
            </div>
          </form>
        </div>
      </div>

      <div class="data-table-wrap">
        <?php if ($emp_total === 0): ?>
          <div class="no-data"><i class="fa fa-briefcase" style="font-size:2rem;margin-bottom:8px;display:block;color:#d1d5db"></i>No employment records yet.</div>
        <?php else: ?>
        <table class="data-table">
          <thead><tr>
            <th>Date</th><th>Name</th><th>Gender</th><th>Age</th>
            <th>PWD</th><th>Type</th><th>Role</th><th>Status</th><th>Created At</th><th></th>
          </tr></thead>
          <tbody>
            <?php while ($r = $emp_rows->fetch_assoc()): ?>
            <tr>
              <td><?= h(date('d/m/Y',strtotime($r['placement_date']))) ?></td>
              <td><?= h($r['full_name']) ?></td>
              <td><span class="badge-tag <?= $r['gender']==='Female'?'badge-f':'badge-m' ?>"><?= h($r['gender']) ?></span></td>
              <td><?= h($r['age_category']) ?></td>
              <td><span class="badge-tag <?= $r['pwd_status']==='Yes'?'badge-yes':'badge-no' ?>"><?= h($r['pwd_status']) ?></span></td>
              <td><?= h($r['employment_type']) ?></td>
              <td><?= h($r['job_title']) ?></td>
              <td><?= h($r['status']) ?></td>
              <td><?= h(fmt_created($r['created_at'] ?? null)) ?></td>
              <td>
                <div class="action-buttons">
                  <a
                    href="#" data-meal-row-action="view" data-meal-type="employment" data-meal-record='<?= h(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                    class="icon-btn icon-btn-view"
                    title="View record"
                  >
                    <i class="fa fa-eye"></i>
                  </a>

                  <a
                    href="#" data-meal-row-action="edit" data-meal-type="employment" data-meal-record='<?= h(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                    class="icon-btn icon-btn-edit <?= !$MEAL_CAN_EDIT ? 'is-disabled' : '' ?>"
                    title="<?= $MEAL_CAN_EDIT ? 'Edit record' : 'Editing blocked by programme team' ?>"
                    <?= !$MEAL_CAN_EDIT ? 'aria-disabled="true"' : '' ?>
                  >
                    <i class="fa fa-edit"></i>
                  </a>

                  <a
                    href="#" data-meal-row-action="delete" data-meal-type="employment" data-meal-record='<?= h(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                    class="icon-btn icon-btn-delete <?= !$MEAL_CAN_DELETE ? 'is-disabled' : '' ?>"
                    title="<?= $MEAL_CAN_DELETE ? 'Delete record' : 'Deleting blocked by programme team' ?>"
                    <?= !$MEAL_CAN_DELETE ? 'aria-disabled="true"' : '' ?>
                  >
                    <i class="fa fa-trash"></i>
                  </a>
                </div>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <?= render_pagination($emp_page, $emp_pages, 'emp') ?>
    </div>


    <!-- ------------------- FINANCE ------------------------------ -->
    <div
      class="meal-panel <?= $tab==='finance'?'active':'' ?>"
      id="panel-finance"
      style="<?= $tab==='finance' ? 'display:block !important;' : 'display:none !important;' ?>"
    >
      <div class="dl-bar">
        <a href="meal-data.php?download_template=finance&venture_id=<?= $venture_id ?>"
           class="btn-dl btn-dl-csv"><i class="fa fa-file-excel"></i> Download Template</a>
        <a href="meal-data.php?export=csv&type=finance&venture_id=<?= $venture_id ?>"
           class="btn-dl btn-dl-export"><i class="fa fa-file-export"></i> Export CSV</a>
        <a href="meal-data.php?export=pdf&type=finance&venture_id=<?= $venture_id ?>"
           class="btn-dl btn-dl-pdf"><i class="fa fa-file-pdf"></i> Export PDF</a>
        <?php if ($MEAL_CAN_EDIT): ?>
        <a
          href="#fin-upload"
          class="btn-dl btn-dl-upload"
          data-upload-target="fin-upload"
          title="Open file upload"
        >
          <i class="fa fa-upload"></i>
          Upload File
        </a>
        <?php else: ?>
        <span
          class="btn-dl btn-dl-upload is-disabled"
          title="File uploads are blocked by the programme team"
          aria-disabled="true"
        >
          <i class="fa fa-lock"></i>
          Upload Blocked
        </span>
        <?php endif; ?>
      </div>
      <div class="upload-form" id="fin-upload">
        <div class="meal-upload-head">
          <strong><i class="fa fa-file-upload"></i> Import File</strong>
          <a
            href="?tab=<?= urlencode($tab) ?>"
            class="meal-upload-close"
            title="Close upload form"
            aria-label="Close upload form"
          >
            <i class="fa fa-times"></i>
          </a>
        </div>
        <form method="POST" action="includes/process-meal-data.php" enctype="multipart/form-data">
          <input type="hidden" name="action" value="upload_meal_csv">
          <input type="hidden" name="type" value="finance">
          <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
          <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
            <div><label>File (Excel or CSV)</label><input type="file" name="csv_file" accept=".csv,.xlsx,.xls" required class="form-control" style="width:auto"></div>
            <button type="submit" class="btn btn-primary" <?= !$MEAL_CAN_EDIT ? 'disabled title="Editing is blocked by the programme team"' : '' ?> style="align-self:flex-end"><i class="fa fa-upload"></i> Import</button>
          </div>
        </form>
      </div>

      <div class="add-card <?= !$MEAL_CAN_EDIT ? 'meal-write-blocked' : '' ?>">
        <a
          href="#add-fin"
          class="add-card-header"
          data-add-target="add-fin"
          aria-controls="add-fin"
          aria-expanded="false"
        >
          <i class="fa fa-plus-circle"></i><h4>Add External Finance Record</h4>
          <i class="fa fa-chevron-down toggle"></i>
        </a>
        <div class="add-card-body" id="add-fin">
          <form method="POST" action="includes/process-meal-data.php">
            <input type="hidden" name="action" value="add_meal_finance">
            <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
            <div class="form-grid-3">
              <div class="form-group">
                <label>Date Mobilised <span style="color:red">*</span></label>
                <input type="date" name="date_mobilised" class="form-control" required value="<?= date('Y-m-d') ?>">
              </div>
              <div class="form-group">
                <label>Amount (USD) <span style="color:red">*</span></label>
                <input type="number" name="finance_usd" class="form-control" required min="0" step="0.01" placeholder="0.00">
              </div>
              <div class="form-group">
                <label>Funding Form <span style="color:red">*</span></label>
                <select name="funding_form" class="form-control" required>
                  <option value="">Select</option>
                  <option>Debt / Loan</option><option>Grant</option>
                  <option>Equity</option><option>Convertible Note</option>
                  <option>Blended Finance</option><option>Other</option>
                </select>
              </div>
              <div class="form-group">
                <label>Funding Source <span style="color:red">*</span></label>
                <input type="text" name="funding_source" class="form-control" required placeholder="e.g. Village Capital">
              </div>
            </div>
            <div style="margin-top:16px;text-align:right">
              <button type="submit" class="btn btn-primary" <?= !$MEAL_CAN_EDIT ? 'disabled title="Editing is blocked by the programme team"' : '' ?>><i class="fa fa-save"></i> Save Finance Record</button>
            </div>
          </form>
        </div>
      </div>

      <div class="data-table-wrap">
        <?php if ($fin_total === 0): ?>
          <div class="no-data"><i class="fa fa-money-bill-wave" style="font-size:2rem;margin-bottom:8px;display:block;color:#d1d5db"></i>No finance records yet.</div>
        <?php else: ?>
        <table class="data-table">
          <thead><tr><th>Date</th><th>Amount (USD)</th><th>Form</th><th>Source</th><th>Created At</th><th></th></tr></thead>
          <tbody>
            <?php while ($r = $fin_rows->fetch_assoc()): ?>
            <tr>
              <td><?= h(date('d/m/Y',strtotime($r['date_mobilised']))) ?></td>
              <td><strong>$<?= number_format((float)($r['finance_usd'] ?? 0), 2) ?></strong></td>
              <td><?= h($r['funding_form']) ?></td>
              <td><?= h($r['funding_source']) ?></td>
              <td><?= h(fmt_created($r['created_at'] ?? null)) ?></td>
              <td>
                <div class="action-buttons">
                  <a
                    href="#" data-meal-row-action="view" data-meal-type="finance" data-meal-record='<?= h(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                    class="icon-btn icon-btn-view"
                    title="View record"
                  >
                    <i class="fa fa-eye"></i>
                  </a>

                  <a
                    href="#" data-meal-row-action="edit" data-meal-type="finance" data-meal-record='<?= h(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                    class="icon-btn icon-btn-edit <?= !$MEAL_CAN_EDIT ? 'is-disabled' : '' ?>"
                    title="<?= $MEAL_CAN_EDIT ? 'Edit record' : 'Editing blocked by programme team' ?>"
                    <?= !$MEAL_CAN_EDIT ? 'aria-disabled="true"' : '' ?>
                  >
                    <i class="fa fa-edit"></i>
                  </a>

                  <a
                    href="#" data-meal-row-action="delete" data-meal-type="finance" data-meal-record='<?= h(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                    class="icon-btn icon-btn-delete <?= !$MEAL_CAN_DELETE ? 'is-disabled' : '' ?>"
                    title="<?= $MEAL_CAN_DELETE ? 'Delete record' : 'Deleting blocked by programme team' ?>"
                    <?= !$MEAL_CAN_DELETE ? 'aria-disabled="true"' : '' ?>
                  >
                    <i class="fa fa-trash"></i>
                  </a>
                </div>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <?= render_pagination($fin_page, $fin_pages, 'fin') ?>
    </div>


    <!-- ------------------- PARTNERSHIPS ------------------------- -->
    <div
      class="meal-panel <?= $tab==='partnerships'?'active':'' ?>"
      id="panel-partnerships"
      style="<?= $tab==='partnerships' ? 'display:block !important;' : 'display:none !important;' ?>"
    >
      <div class="dl-bar">
        <a href="meal-data.php?download_template=partnerships&venture_id=<?= $venture_id ?>"
           class="btn-dl btn-dl-csv"><i class="fa fa-file-excel"></i> Download Template</a>
        <a href="meal-data.php?export=csv&type=partnerships&venture_id=<?= $venture_id ?>"
           class="btn-dl btn-dl-export"><i class="fa fa-file-export"></i> Export CSV</a>
        <a href="meal-data.php?export=pdf&type=partnerships&venture_id=<?= $venture_id ?>"
           class="btn-dl btn-dl-pdf"><i class="fa fa-file-pdf"></i> Export PDF</a>
        <?php if ($MEAL_CAN_EDIT): ?>
        <a
          href="#par-upload"
          class="btn-dl btn-dl-upload"
          data-upload-target="par-upload"
          title="Open file upload"
        >
          <i class="fa fa-upload"></i>
          Upload File
        </a>
        <?php else: ?>
        <span
          class="btn-dl btn-dl-upload is-disabled"
          title="File uploads are blocked by the programme team"
          aria-disabled="true"
        >
          <i class="fa fa-lock"></i>
          Upload Blocked
        </span>
        <?php endif; ?>
      </div>
      <div class="upload-form" id="par-upload">
        <div class="meal-upload-head">
          <strong><i class="fa fa-file-upload"></i> Import File</strong>
          <a
            href="?tab=<?= urlencode($tab) ?>"
            class="meal-upload-close"
            title="Close upload form"
            aria-label="Close upload form"
          >
            <i class="fa fa-times"></i>
          </a>
        </div>
        <form method="POST" action="includes/process-meal-data.php" enctype="multipart/form-data">
          <input type="hidden" name="action" value="upload_meal_csv">
          <input type="hidden" name="type" value="partnerships">
          <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
          <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
            <div><label>File (Excel or CSV)</label><input type="file" name="csv_file" accept=".csv,.xlsx,.xls" required class="form-control" style="width:auto"></div>
            <button type="submit" class="btn btn-primary" <?= !$MEAL_CAN_EDIT ? 'disabled title="Editing is blocked by the programme team"' : '' ?> style="align-self:flex-end"><i class="fa fa-upload"></i> Import</button>
          </div>
        </form>
      </div>

      <div class="add-card <?= !$MEAL_CAN_EDIT ? 'meal-write-blocked' : '' ?>">
        <a
          href="#add-par"
          class="add-card-header"
          data-add-target="add-par"
          aria-controls="add-par"
          aria-expanded="false"
        >
          <i class="fa fa-plus-circle"></i><h4>Add Partnership Record</h4>
          <i class="fa fa-chevron-down toggle"></i>
        </a>
        <div class="add-card-body" id="add-par">
          <form method="POST" action="includes/process-meal-data.php">
            <input type="hidden" name="action" value="add_meal_partnership">
            <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
            <div class="form-grid-3">
              <div class="form-group">
                <label>Partnership Date <span style="color:red">*</span></label>
                <input type="date" name="partner_date" class="form-control" required value="<?= date('Y-m-d') ?>">
              </div>
              <div class="form-group">
                <label>Partner Name <span style="color:red">*</span></label>
                <input type="text" name="partner_name" class="form-control" required placeholder="Organisation name">
              </div>
              <div class="form-group">
                <label>Type of Partnership</label>
                <select name="partnership_type" class="form-control">
                  <option value="">Select</option>
                  <option>Financial</option><option>Technical</option>
                  <option>Distribution</option><option>Academic</option>
                  <option>Government</option><option>Other</option>
                </select>
              </div>
              <div class="form-group">
                <label>Status</label>
                <select name="partnership_status" class="form-control">
                  <option>Active</option><option>Pending</option>
                  <option>Inactive</option><option>Completed</option>
                </select>
              </div>
            </div>
            <div style="margin-top:16px;text-align:right">
              <button type="submit" class="btn btn-primary" <?= !$MEAL_CAN_EDIT ? 'disabled title="Editing is blocked by the programme team"' : '' ?>><i class="fa fa-save"></i> Save Partnership</button>
            </div>
          </form>
        </div>
      </div>

      <div class="data-table-wrap">
        <?php if ($par_total === 0): ?>
          <div class="no-data"><i class="fa fa-handshake" style="font-size:2rem;margin-bottom:8px;display:block;color:#d1d5db"></i>No partnership records yet.</div>
        <?php else: ?>
        <table class="data-table">
          <thead><tr><th>Date</th><th>Partner</th><th>Type</th><th>Status</th><th>Created At</th><th></th></tr></thead>
          <tbody>
            <?php while ($r = $par_rows->fetch_assoc()): ?>
            <tr>
              <td><?= h(date('d/m/Y',strtotime($r['partner_date']))) ?></td>
              <td><strong><?= h($r['partner_name']) ?></strong></td>
              <td><?= h($r['partnership_type']) ?></td>
              <td><span class="badge-tag <?= $r['partnership_status']==='Active'?'badge-yes':'badge-no' ?>"><?= h($r['partnership_status']) ?></span></td>
              <td><?= h(fmt_created($r['created_at'] ?? null)) ?></td>
              <td>
                <div class="action-buttons">
                  <a
                    href="#" data-meal-row-action="view" data-meal-type="partnerships" data-meal-record='<?= h(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                    class="icon-btn icon-btn-view"
                    title="View record"
                  >
                    <i class="fa fa-eye"></i>
                  </a>

                  <a
                    href="#" data-meal-row-action="edit" data-meal-type="partnerships" data-meal-record='<?= h(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                    class="icon-btn icon-btn-edit <?= !$MEAL_CAN_EDIT ? 'is-disabled' : '' ?>"
                    title="<?= $MEAL_CAN_EDIT ? 'Edit record' : 'Editing blocked by programme team' ?>"
                    <?= !$MEAL_CAN_EDIT ? 'aria-disabled="true"' : '' ?>
                  >
                    <i class="fa fa-edit"></i>
                  </a>

                  <a
                    href="#" data-meal-row-action="delete" data-meal-type="partnerships" data-meal-record='<?= h(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                    class="icon-btn icon-btn-delete <?= !$MEAL_CAN_DELETE ? 'is-disabled' : '' ?>"
                    title="<?= $MEAL_CAN_DELETE ? 'Delete record' : 'Deleting blocked by programme team' ?>"
                    <?= !$MEAL_CAN_DELETE ? 'aria-disabled="true"' : '' ?>
                  >
                    <i class="fa fa-trash"></i>
                  </a>
                </div>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <?= render_pagination($par_page, $par_pages, 'par') ?>
    </div>


    <!-- ------------------- REVENUE ------------------------------ -->
    <div
      class="meal-panel <?= $tab==='revenue'?'active':'' ?>"
      id="panel-revenue"
      style="<?= $tab==='revenue' ? 'display:block !important;' : 'display:none !important;' ?>"
    >
      <div class="dl-bar">
        <a href="meal-data.php?download_template=revenue&venture_id=<?= $venture_id ?>"
           class="btn-dl btn-dl-csv"><i class="fa fa-file-excel"></i> Download Template</a>
        <a href="meal-data.php?export=csv&type=revenue&venture_id=<?= $venture_id ?>"
           class="btn-dl btn-dl-export"><i class="fa fa-file-export"></i> Export CSV</a>
        <a href="meal-data.php?export=pdf&type=revenue&venture_id=<?= $venture_id ?>"
           class="btn-dl btn-dl-pdf"><i class="fa fa-file-pdf"></i> Export PDF</a>
        <?php if ($MEAL_CAN_EDIT): ?>
        <a
          href="#rev-upload"
          class="btn-dl btn-dl-upload"
          data-upload-target="rev-upload"
          title="Open file upload"
        >
          <i class="fa fa-upload"></i>
          Upload File
        </a>
        <?php else: ?>
        <span
          class="btn-dl btn-dl-upload is-disabled"
          title="File uploads are blocked by the programme team"
          aria-disabled="true"
        >
          <i class="fa fa-lock"></i>
          Upload Blocked
        </span>
        <?php endif; ?>
      </div>
      <div class="upload-form" id="rev-upload">
        <div class="meal-upload-head">
          <strong><i class="fa fa-file-upload"></i> Import File</strong>
          <a
            href="?tab=<?= urlencode($tab) ?>"
            class="meal-upload-close"
            title="Close upload form"
            aria-label="Close upload form"
          >
            <i class="fa fa-times"></i>
          </a>
        </div>
        <form method="POST" action="includes/process-meal-data.php" enctype="multipart/form-data">
          <input type="hidden" name="action" value="upload_meal_csv">
          <input type="hidden" name="type" value="revenue">
          <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
          <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
            <div><label>File (Excel or CSV)</label><input type="file" name="csv_file" accept=".csv,.xlsx,.xls" required class="form-control" style="width:auto"></div>
            <button type="submit" class="btn btn-primary" <?= !$MEAL_CAN_EDIT ? 'disabled title="Editing is blocked by the programme team"' : '' ?> style="align-self:flex-end"><i class="fa fa-upload"></i> Import</button>
          </div>
        </form>
      </div>

      <div class="add-card <?= !$MEAL_CAN_EDIT ? 'meal-write-blocked' : '' ?>">
        <a
          href="#add-rev"
          class="add-card-header"
          data-add-target="add-rev"
          aria-controls="add-rev"
          aria-expanded="false"
        >
          <i class="fa fa-plus-circle"></i><h4>Add Revenue Record</h4>
          <i class="fa fa-chevron-down toggle"></i>
        </a>
        <div class="add-card-body" id="add-rev">
          <form method="POST" action="includes/process-meal-data.php">
            <input type="hidden" name="action" value="add_meal_revenue">
            <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
            <div class="form-grid-3">
              <div class="form-group">
                <label>Month-end Date <span style="color:red">*</span></label>
                <input type="date" name="revenue_date" class="form-control" required>
                <span class="form-hint">Select the last date of the reporting month.</span>
              </div>
              <div class="form-group">
                <label>Gross Revenue (UGX) <span style="color:red">*</span></label>
                <input type="number" name="gross_revenue_ugx" class="form-control" required min="0" step="1" placeholder="0">
              </div>
              <div class="form-group">
                <label>Primary Revenue Stream</label>
                <input type="text" name="revenue_stream" class="form-control" placeholder="e.g. Subscriptions, Training fees">
              </div>
            </div>
            <div style="margin-top:16px;text-align:right">
              <button type="submit" class="btn btn-primary" <?= !$MEAL_CAN_EDIT ? 'disabled title="Editing is blocked by the programme team"' : '' ?>><i class="fa fa-save"></i> Save Revenue</button>
            </div>
          </form>
        </div>
      </div>

      <div class="data-table-wrap">
        <?php if ($rev_total === 0): ?>
          <div class="no-data"><i class="fa fa-chart-line" style="font-size:2rem;margin-bottom:8px;display:block;color:#d1d5db"></i>No revenue records yet.</div>
        <?php else: ?>
        <table class="data-table">
          <thead><tr><th>Month-end</th><th>Gross Revenue (UGX)</th><th>Revenue Stream</th><th>Created At</th><th></th></tr></thead>
          <tbody>
            <?php while ($r = $rev_rows->fetch_assoc()): ?>
            <tr>
              <td><?= h(date('M Y',strtotime($r['revenue_date']))) ?></td>
              <td><strong>UGX <?= number_format((float)($r['gross_revenue_ugx'] ?? 0)) ?></strong></td>
              <td><?= h($r['revenue_stream'] ?: '-') ?></td>
              <td><?= h(fmt_created($r['created_at'] ?? null)) ?></td>
              <td>
                <div class="action-buttons">
                  <a
                    href="#" data-meal-row-action="view" data-meal-type="revenue" data-meal-record='<?= h(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                    class="icon-btn icon-btn-view"
                    title="View record"
                  >
                    <i class="fa fa-eye"></i>
                  </a>

                  <a
                    href="#" data-meal-row-action="edit" data-meal-type="revenue" data-meal-record='<?= h(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                    class="icon-btn icon-btn-edit <?= !$MEAL_CAN_EDIT ? 'is-disabled' : '' ?>"
                    title="<?= $MEAL_CAN_EDIT ? 'Edit record' : 'Editing blocked by programme team' ?>"
                    <?= !$MEAL_CAN_EDIT ? 'aria-disabled="true"' : '' ?>
                  >
                    <i class="fa fa-edit"></i>
                  </a>

                  <a
                    href="#" data-meal-row-action="delete" data-meal-type="revenue" data-meal-record='<?= h(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                    class="icon-btn icon-btn-delete <?= !$MEAL_CAN_DELETE ? 'is-disabled' : '' ?>"
                    title="<?= $MEAL_CAN_DELETE ? 'Delete record' : 'Deleting blocked by programme team' ?>"
                    <?= !$MEAL_CAN_DELETE ? 'aria-disabled="true"' : '' ?>
                  >
                    <i class="fa fa-trash"></i>
                  </a>
                </div>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <?= render_pagination($rev_page, $rev_pages, 'rev') ?>
    </div>


    <!-- Learner View Modal -->
    
    <!-- ------------------- SUPPORTING DOCUMENTS ---------------- -->
    <div
      class="meal-panel <?= $tab==='documents'?'active':'' ?>"
      id="panel-documents"
      style="<?= $tab==='documents' ? 'display:block !important;' : 'display:none !important;' ?>"
    >

      <div class="meal-doc-header">

        <div>
          <h3>
            <i class="fa fa-paperclip"></i>
            Supporting Documents
          </h3>

          <p>
            Upload evidence and supporting files under each M&amp;E category.
            Examples include attendance sheets, beneficiary lists, finance evidence,
            partnership agreements, employment evidence and revenue records.
          </p>
        </div>

      </div>


      <div class="add-card <?= !$MEAL_CAN_EDIT ? 'meal-write-blocked' : '' ?>">

        <a
          href="<?= $MEAL_CAN_EDIT ? '#add-meal-doc' : '#' ?>"
          class="add-card-header"
          data-add-target="add-meal-doc"
          aria-controls="add-meal-doc"
          aria-expanded="false"
          <?= !$MEAL_CAN_EDIT ? 'aria-disabled="true" tabindex="-1"' : '' ?>
        >
          <i class="fa fa-file-upload"></i>
          <h4>Upload Supporting Document</h4>
          <i class="fa fa-chevron-down toggle"></i>
        </a>

        <div
          class="add-card-body"
          id="add-meal-doc"
        >

          <?php if (!$MEAL_CAN_EDIT): ?>

            <div class="meal-block-message">
              <i class="fa fa-lock"></i>
              Document uploads are currently blocked by the programme team.
            </div>

          <?php else: ?>

            <form
              method="POST"
              action="includes/process-meal-data.php"
              enctype="multipart/form-data"
            >
              <input
                type="hidden"
                name="action"
                value="upload_meal_supporting_document"
              >

              <input
                type="hidden"
                name="venture_id"
                value="<?= $venture_id ?>"
              >

              <div class="form-grid-3">

                <div class="form-group">
                  <label>
                    M&amp;E Category
                    <span style="color:red">*</span>
                  </label>

                  <select
                    name="category"
                    class="form-control"
                    required
                  >
                    <option value="">Select what this document supports</option>

                    <?php foreach ($mealDocumentCategories as $key => $label): ?>
                      <option value="<?= h($key) ?>">
                        <?= h($label) ?>
                      </option>
                    <?php endforeach; ?>

                  </select>
                </div>

                <div class="form-group">
                  <label>
                    Document Name
                    <span style="color:red">*</span>
                  </label>

                  <input
                    type="text"
                    name="document_name"
                    class="form-control"
                    required
                    maxlength="255"
                    placeholder="e.g. Learner attendance sheet"
                  >
                </div>

                <div class="form-group">
                  <label>
                    Supporting File
                    <span style="color:red">*</span>
                  </label>

                  <input
                    type="file"
                    name="supporting_document"
                    class="form-control"
                    required
                    accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.ppt,.pptx,.png,.jpg,.jpeg,.webp,.zip"
                  >

                  <span class="form-hint">
                    PDF, Word, Excel, CSV, PowerPoint, image or ZIP. Maximum 20 MB.
                  </span>
                </div>

                <div
                  class="form-group"
                  style="grid-column:1/-1"
                >
                  <label>Description</label>

                  <textarea
                    name="description"
                    class="form-control"
                    rows="3"
                    maxlength="1500"
                    placeholder="Briefly explain what this document supports..."
                  ></textarea>
                </div>

              </div>

              <div style="margin-top:16px;text-align:right">

                <button
                  type="submit"
                  class="btn btn-primary"
                >
                  <i class="fa fa-upload"></i>
                  Upload Document
                </button>

              </div>

            </form>

          <?php endif; ?>

        </div>

      </div>


      <div class="meal-doc-filter">

        <label for="mealDocCategoryFilter">
          Filter category
        </label>

        <select
          id="mealDocCategoryFilter"
          class="form-control"

        >
          <option value="">All categories</option>

          <?php foreach ($mealDocumentCategories as $key => $label): ?>
            <option value="<?= h($key) ?>">
              <?= h($label) ?>
            </option>
          <?php endforeach; ?>

        </select>

      </div>


      <?php if (!$mealDocuments): ?>

        <div class="no-data">
          <i
            class="fa fa-folder-open"
            style="font-size:2rem;margin-bottom:8px;display:block;color:#d1d5db"
          ></i>
          No supporting documents have been uploaded yet.
        </div>

      <?php else: ?>

        <div class="data-table-wrap">

          <table class="data-table meal-doc-table">

            <thead>
              <tr>
                <th>Category</th>
                <th>Document</th>
                <th>Description</th>
                <th>File</th>
                <th>Size</th>
                <th>Uploaded</th>
                <th>Actions</th>
              </tr>
            </thead>

            <tbody>

              <?php foreach ($mealDocuments as $doc): ?>

                <?php
                  $docCategory =
                      (string)($doc['category'] ?? '');

                  $size =
                      (int)($doc['file_size'] ?? 0);

                  $sizeLabel =
                      $size >= 1048576
                          ? number_format($size / 1048576, 2) . ' MB'
                          : number_format(max(0, $size) / 1024, 1) . ' KB';
                ?>

                <tr
                  class="meal-doc-row"
                  data-category="<?= h($docCategory) ?>"
                >

                  <td>
                    <span class="badge-tag badge-new">
                      <?= h(
                          $mealDocumentCategories[$docCategory]
                          ?? ucfirst(
                              str_replace(
                                  '_',
                                  ' ',
                                  $docCategory
                              )
                          )
                      ) ?>
                    </span>
                  </td>

                  <td>
                    <strong>
                      <?= h($doc['document_name']) ?>
                    </strong>

                    <small class="meal-doc-uploader">
                      <?= h($doc['uploaded_by'] ?: 'Venture') ?>
                    </small>
                  </td>

                  <td>
                    <?= h(
                        $doc['description']
                        ?: '-'
                    ) ?>
                  </td>

                  <td>
                    <i class="fa fa-file"></i>
                    <?= h($doc['original_name']) ?>
                  </td>

                  <td>
                    <?= h($sizeLabel) ?>
                  </td>

                  <td>
                    <?= h(
                        fmt_created(
                            $doc['created_at']
                            ?? null
                        )
                    ) ?>
                  </td>

                  <td>

                    <div class="action-buttons">

                      <a
                        class="icon-btn icon-btn-view"
                        title="View document details"
                        href="#" data-meal-row-action="view" data-meal-type="documents" data-meal-record='<?= h(json_encode($doc, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                      >
                        <i class="fa fa-eye"></i>
                      </a>

                      <a
                        class="icon-btn icon-btn-edit <?= !$MEAL_CAN_EDIT ? 'is-disabled' : '' ?>"
                        title="<?= $MEAL_CAN_EDIT ? 'Edit document details' : 'Editing blocked by programme team' ?>"
                        href="#" data-meal-row-action="edit" data-meal-type="documents" data-meal-record='<?= h(json_encode($doc, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                        <?= !$MEAL_CAN_EDIT ? 'aria-disabled="true"' : '' ?>
                      >
                        <i class="fa fa-edit"></i>
                      </a>

                      <a
                        class="icon-btn icon-btn-view"
                        title="Download document"
                        href="includes/process-meal-data.php?action=download_meal_supporting_document&amp;id=<?= (int)$doc['id'] ?>"
                      >
                        <i class="fa fa-download"></i>
                      </a>

                      <a
                        href="#" data-meal-row-action="delete" data-meal-type="documents" data-meal-record='<?= h(json_encode($doc, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                        class="icon-btn icon-btn-delete <?= !$MEAL_CAN_DELETE ? 'is-disabled' : '' ?>"
                        title="<?= $MEAL_CAN_DELETE ? 'Delete supporting document' : 'Deleting blocked by programme team' ?>"
                        <?= !$MEAL_CAN_DELETE ? 'aria-disabled="true"' : '' ?>
                      >
                        <i class="fa fa-trash"></i>
                      </a>

                    </div>

                  </td>

                </tr>

              <?php endforeach; ?>

            </tbody>

          </table>

        </div>

      <?php endif; ?>

    </div><!-- /documents panel -->

<?php
function render_pagination(int $page, int $total_pages, string $prefix): string {
    if ($total_pages <= 1) return '';
    $tab = $_GET['tab'] ?? 'participants';
    $out = '<div class="pagination">';
    $prev = $page > 1 ? '?tab='.$tab.'&'.$prefix.'_page='.($page-1) : '#';
    $out .= '<a href="'.$prev.'" class="page-btn '.($page<=1?'disabled':'').'">&lsaquo;</a>';
    for ($i = max(1,$page-2); $i <= min($total_pages,$page+2); $i++) {
        $out .= '<a href="?tab='.$tab.'&'.$prefix.'_page='.$i.'" class="page-btn '.($i===$page?'active':'').'">'.$i.'</a>';
    }
    $next = $page < $total_pages ? '?tab='.$tab.'&'.$prefix.'_page='.($page+1) : '#';
    $out .= '<a href="'.$next.'" class="page-btn '.($page>=$total_pages?'disabled':'').'">&rsaquo;</a>';
    $out .= '</div>';
    return $out;
}
?>




<!-- ============================================================
     DIRECT IN-PAGE RECORD MODAL
     View / Edit / Delete no longer depends on query-string reloads.
============================================================ -->
<div
  class="meal-modal"
  id="mealDirectRecordModal"
  aria-hidden="true"
>
  <div
    class="meal-modal-card meal-generic-card"
    role="dialog"
    aria-modal="true"
    aria-labelledby="mealDirectRecordTitle"
  >
    <div class="meal-modal-header">
      <h3 class="meal-modal-title" id="mealDirectRecordTitle">
        <i class="fa fa-eye"></i>
        Record
      </h3>

      <button
        type="button"
        class="meal-modal-close"
        data-meal-modal-close="1"
        aria-label="Close"
        title="Close"
      >
        <i class="fa fa-times"></i>
      </button>
    </div>

    <div class="meal-modal-body" id="mealDirectRecordBody"></div>

    <div class="meal-modal-footer" id="mealDirectRecordFooter"></div>
  </div>
</div>


<div
  class="meal-modal yiw-outcomes-modal"
  id="yiwOutcomesModal"
  aria-hidden="true"
  style="display:none !important;visibility:hidden !important;opacity:0 !important;pointer-events:none !important;"
>
  <div
    class="meal-modal-card yiw-outcomes-card"
    role="dialog"
    aria-modal="true"
    aria-labelledby="yiwOutcomesTitle"
  >
    <div class="meal-modal-header">
      <h3 class="meal-modal-title" id="yiwOutcomesTitle">
        <i class="fa fa-briefcase"></i>
        Youth in Work (YIW) Outcomes
      </h3>

      <button
        type="button"
        class="meal-modal-close"
        onclick="closeMealModal('yiwOutcomesModal')"
        aria-label="Close Youth in Work outcomes"
        title="Close"
      >
        <i class="fa fa-times"></i>
      </button>
    </div>

    <div class="meal-modal-body">
      <div class="yiw-modal-intro">
        Calculated automatically from learner After-work Status and pathway.
      </div>

      <div class="yiw-modal-grid">
        <div class="yiw-modal-item">
          <strong><?= number_format($yiwStats['new_self']) ?></strong>
          <span># of YIW (New) through Self-employment</span>
        </div>

        <div class="yiw-modal-item">
          <strong><?= number_format($yiwStats['additional_self']) ?></strong>
          <span># of YIW (Additional) through Self-employment</span>
        </div>

        <div class="yiw-modal-item">
          <strong><?= number_format($yiwStats['improved_self']) ?></strong>
          <span># of YIW (Improved) through Self-employment</span>
        </div>

        <div class="yiw-modal-item">
          <strong><?= number_format($yiwStats['new_wage']) ?></strong>
          <span># of YIW (New) through Wage employment</span>
        </div>

        <div class="yiw-modal-item">
          <strong><?= number_format($yiwStats['additional_wage']) ?></strong>
          <span># of YIW (Additional) through Wage employment</span>
        </div>

        <div class="yiw-modal-item">
          <strong><?= number_format($yiwStats['improved_wage']) ?></strong>
          <span># of YIW (Improved) through Wage employment</span>
        </div>

      </div>
    </div>

    <div class="meal-modal-footer">
      <button
        type="button"
        class="btn btn-outline"
        onclick="closeMealModal('yiwOutcomesModal')"
      >
        Close
      </button>
    </div>
  </div>
</div>


<?php if ($mealModalRecord && $mealModalSchema): ?>

<div
  class="meal-modal open"
  id="genericMealRecordModal"
  aria-hidden="false"
  style="display:flex !important;visibility:visible !important;opacity:1 !important;pointer-events:auto !important;"
>
  <div class="meal-modal-card meal-generic-card">

    <div class="meal-modal-header">

      <h3 class="meal-modal-title">
        <i class="fa <?= $mealModalMode === 'edit' ? 'fa-edit' : 'fa-eye' ?>"></i>
        <?= h(
            ($mealModalMode === 'edit' ? 'Edit ' : 'View ')
            . $mealModalSchema['label']
        ) ?>
      </h3>

      <a
        href="?tab=<?= urlencode($mealModalType) ?>"
        class="meal-modal-close"
        aria-label="Close"
        title="Close"
      >
        <i class="fa fa-times"></i>
      </a>

    </div>

    <?php if ($mealModalMode === 'edit'): ?>

      <form
        method="POST"
        action="includes/process-meal-data.php"
      >

        <input
          type="hidden"
          name="action"
          value="edit_meal_record"
        >

        <input
          type="hidden"
          name="type"
          value="<?= h($mealModalType) ?>"
        >

        <input
          type="hidden"
          name="id"
          value="<?= (int)$mealModalRecord['id'] ?>"
        >

        <input
          type="hidden"
          name="venture_id"
          value="<?= $venture_id ?>"
        >

        <div class="meal-modal-body">

          <div class="form-grid-2">

            <?php foreach ($mealModalSchema['fields'] as $fieldName => $field): ?>

              <?php
                $fieldType =
                    (string)(
                        $field['type']
                        ?? 'text'
                    );

                $fieldValue =
                    (string)(
                        $mealModalRecord[
                            $fieldName
                        ]
                        ?? ''
                    );

                $required =
                    !empty(
                        $field['required']
                    );
              ?>

              <?php
                $conditionalClass = '';

                if ($mealModalType === 'participants' && $fieldName === 'refugee_settlement') {
                    $conditionalClass = ' meal-refugee-settlement-group';
                }

                if ($mealModalType === 'participants' && $fieldName === 'after_work_pathway') {
                    $conditionalClass = ' meal-after-work-pathway-group';
                }
              ?>
              <div class="form-group <?= $fieldType === 'textarea' ? 'meal-field-full' : '' ?><?= $conditionalClass ?>">

                <label>
                  <?= h($field['label'] ?? $fieldName) ?>
                  <?php if ($required): ?>
                    <span style="color:red">*</span>
                  <?php endif; ?>
                </label>

                <?php if ($fieldType === 'select'): ?>

                  <select
                    name="<?= h($fieldName) ?>"
                    class="form-control"
                    <?= $mealModalType === 'participants' && $fieldName === 'refugee_status' ? 'data-meal-refugee-status="1"' : '' ?>
                    <?= $mealModalType === 'participants' && $fieldName === 'refugee_settlement' ? 'data-meal-refugee-settlement="1"' : '' ?>
                    <?= $mealModalType === 'participants' && $fieldName === 'in_work_status' ? 'data-meal-after-status="1"' : '' ?>
                    <?= $mealModalType === 'participants' && $fieldName === 'after_work_pathway' ? 'data-meal-after-pathway="1"' : '' ?>
                    <?= $required ? 'required' : '' ?>
                  >

                    <?php foreach (($field['options'] ?? []) as $optionValue => $optionLabel): ?>

                      <option
                        value="<?= h($optionValue) ?>"
                        <?php
                          $isSelected = $fieldValue === (string)$optionValue;

                          if (
                              $mealModalType === 'participants'
                              && $fieldName === 'refugee_settlement'
                              && (string)$optionValue === 'Other (please specify)'
                          ) {
                              $knownSettlementValues = [
                                  '',
                                  'Bidi bidi Refugee Settlement',
                                  'Nakivale Refugee Settlement',
                                  'Rhino Camp Refugee Settlement',
                                  'Palorinya Refugee Settlement',
                                  'Kyangwali Refugee Settlement',
                                  'Adjumani Settlements',
                                  'Kyaka II Refugee Settlement',
                                  'Other (please specify)'
                              ];

                              if (!in_array($fieldValue, $knownSettlementValues, true)) {
                                  $isSelected = true;
                              }
                          }
                        ?>
                        <?= $isSelected ? 'selected' : '' ?>
                      >
                        <?= h($optionLabel) ?>
                      </option>

                    <?php endforeach; ?>

                  </select>

                  <?php if ($mealModalType === 'participants' && $fieldName === 'refugee_settlement'): ?>
                    <?php
                      $knownSettlements = [
                        'Bidi bidi Refugee Settlement',
                        'Nakivale Refugee Settlement',
                        'Rhino Camp Refugee Settlement',
                        'Palorinya Refugee Settlement',
                        'Kyangwali Refugee Settlement',
                        'Adjumani Settlements',
                        'Kyaka II Refugee Settlement',
                        'Other (please specify)',
                        ''
                      ];
                      $customSettlementValue = in_array($fieldValue, $knownSettlements, true) ? '' : $fieldValue;
                    ?>
                    <input
                      type="text"
                      name="refugee_settlement_other"
                      class="form-control meal-refugee-settlement-other"
                      value="<?= h($customSettlementValue) ?>"
                      placeholder="Please specify the refugee settlement"
                      style="margin-top:8px;<?= $customSettlementValue !== '' ? '' : 'display:none;' ?>"
                    >
                  <?php endif; ?>

                <?php elseif ($fieldType === 'textarea'): ?>

                  <textarea
                    name="<?= h($fieldName) ?>"
                    class="form-control"
                    rows="4"
                    <?= $required ? 'required' : '' ?>
                  ><?= h($fieldValue) ?></textarea>

                <?php else: ?>

                  <input
                    type="<?= h($fieldType) ?>"
                    name="<?= h($fieldName) ?>"
                    class="form-control"
                    value="<?= h($fieldValue) ?>"
                    <?= isset($field['step']) ? 'step="' . h($field['step']) . '"' : '' ?>
                    <?= $required ? 'required' : '' ?>
                  >

                <?php endif; ?>

              </div>

            <?php endforeach; ?>

          </div>

          <?php if ($mealModalType === 'documents'): ?>

            <div class="meal-document-current">
              <i class="fa fa-paperclip"></i>
              Current file:
              <strong><?= h($mealModalRecord['original_name'] ?? '') ?></strong>
              <span>
                To replace the actual file, delete this document and upload a new file.
              </span>
            </div>

          <?php endif; ?>

        </div>

        <div class="meal-modal-footer">

          <a
            href="?tab=<?= urlencode($mealModalType) ?>"
            class="btn btn-outline"
          >
            Cancel
          </a>

          <button
            type="submit"
            class="btn btn-primary"
          >
            <i class="fa fa-save"></i>
            Save Changes
          </button>

        </div>

      </form>

    <?php else: ?>

      <div class="meal-modal-body">

        <div class="meal-view-grid">

          <?php foreach ($mealModalSchema['fields'] as $fieldName => $field): ?>

            <div class="meal-view-item">
              <span>
                <?= h($field['label'] ?? $fieldName) ?>
              </span>

              <strong>
                <?php
                  $value =
                      (string)(
                          $mealModalRecord[
                              $fieldName
                          ]
                          ?? ''
                      );

                  if (
                      $mealModalType === 'documents'
                      && $fieldName === 'category'
                  ) {
                      $value =
                          $mealDocumentCategories[$value]
                          ?? $value;
                  }

                  echo h(
                      $value !== ''
                          ? $value
                          : '-'
                  );
                ?>
              </strong>
            </div>

          <?php endforeach; ?>

          <?php if ($mealModalType === 'documents'): ?>

            <div class="meal-view-item meal-field-full">
              <span>Uploaded File</span>
              <strong><?= h($mealModalRecord['original_name'] ?? '-') ?></strong>
            </div>

          <?php endif; ?>

        </div>

      </div>

      <div class="meal-modal-footer">

        <a
          href="?tab=<?= urlencode($mealModalType) ?>"
          class="btn btn-outline"
        >
          Close
        </a>

        <?php if ($MEAL_CAN_EDIT): ?>

          <a
            href="?tab=<?= urlencode($mealModalType) ?>&amp;edit_id=<?= (int)$mealModalRecord['id'] ?>"
            class="btn btn-primary"
          >
            <i class="fa fa-edit"></i>
            Edit
          </a>

        <?php endif; ?>

      </div>

    <?php endif; ?>

  </div>
</div>

<?php endif; ?>


<?php if (
    $mealDeleteRecord
    && $requestedDeleteId > 0
    && $MEAL_CAN_DELETE
): ?>

<div
  class="meal-modal open"
  id="mealDeleteServerModal"
  aria-hidden="false"
  style="display:flex !important;visibility:visible !important;opacity:1 !important;pointer-events:auto !important;"
>
  <div class="meal-modal-card meal-confirm-card">

    <div class="meal-modal-header">

      <h3 class="meal-modal-title">
        <i class="fa fa-exclamation-triangle"></i>
        Confirm Delete
      </h3>

      <a
        href="?tab=<?= urlencode($requestedDeleteType) ?>"
        class="meal-modal-close"
        title="Close"
      >
        <i class="fa fa-times"></i>
      </a>

    </div>

    <div class="meal-modal-body">

      <div class="meal-confirm-content">

        <div class="meal-confirm-icon danger">
          <i class="fa fa-trash"></i>
        </div>

        <div>
          <strong>
            Delete this <?= h($mealDeleteLabel) ?>?
          </strong>

          <p>
            This item will be permanently removed.
            This action cannot be undone.
          </p>
        </div>

      </div>

    </div>

    <div class="meal-modal-footer">

      <a
        href="?tab=<?= urlencode($requestedDeleteType) ?>"
        class="btn btn-outline"
      >
        Cancel
      </a>

      <form
        method="POST"
        action="includes/process-meal-data.php"
      >

        <input
          type="hidden"
          name="venture_id"
          value="<?= $venture_id ?>"
        >

        <input
          type="hidden"
          name="id"
          value="<?= $requestedDeleteId ?>"
        >

        <?php if ($requestedDeleteType === 'documents'): ?>

          <input
            type="hidden"
            name="action"
            value="delete_meal_supporting_document"
          >

        <?php else: ?>

          <input
            type="hidden"
            name="action"
            value="delete_meal_row"
          >

          <input
            type="hidden"
            name="type"
            value="<?= h($requestedDeleteType) ?>"
          >

        <?php endif; ?>

        <button
          type="submit"
          class="btn btn-danger"
        >
          <i class="fa fa-trash"></i>
          Delete
        </button>

      </form>

    </div>

  </div>
</div>

<?php endif; ?>


<!-- ============================================================
     CUSTOM MESSAGE POPUP
============================================================ -->
<div
  class="meal-modal"
  id="mealMessageModal"
  aria-hidden="true"
>
  <div class="meal-modal-card meal-message-card">

    <div class="meal-modal-header">
      <h3 class="meal-modal-title" id="mealMessageTitle">
        <i class="fa fa-info-circle"></i>
        Message
      </h3>

      <button
        type="button"
        class="meal-modal-close"
        data-message-close="1"
        aria-label="Close"
      >
        <i class="fa fa-times"></i>
      </button>
    </div>

    <div class="meal-modal-body">
      <p id="mealMessageText" class="meal-message-text"></p>
    </div>

    <div class="meal-modal-footer">
      <button
        type="button"
        class="btn btn-primary"
        data-message-close="1"
      >
        OK
      </button>
    </div>

  </div>
</div>

<script>
/* -- FLASH MESSAGE AUTO-DISMISS -------------------
   Shows the message for 5 seconds, then fades it out
   (matching the CSS transition above) and removes it
   from the DOM once the fade finishes. */
(function(){
  const flash = document.getElementById('vpFlash');
  if (!flash) return;
  setTimeout(() => {
    flash.classList.add('vp-flash-hide');
    flash.addEventListener('transitionend', () => flash.remove(), { once: true });
  }, 5000);
})();

document.getElementById('sidebarToggle')?.addEventListener('click',function(){
  document.getElementById('portalMain')?.classList.toggle('collapsed');
});

function toggleAddCard(id){
  const body = document.getElementById(id);
  if (!body) return;

  const header = body.previousElementSibling;

  body.classList.toggle('open');

  if (header) {
    header.classList.toggle(
      'open',
      body.classList.contains('open')
    );
  }
}

function toggleUpload(id){
  const panel = document.getElementById(id);
  if (!panel) return;

  const willOpen = !panel.classList.contains('open');

  /*
   * Keep one import panel open at a time.
   */
  document.querySelectorAll('.upload-form.open').forEach(item=>{
    if (item !== panel) {
      item.classList.remove('open');
    }
  });

  panel.classList.toggle('open', willOpen);

  if (willOpen) {
    setTimeout(()=>{
      panel.scrollIntoView({
        behavior:'smooth',
        block:'nearest'
      });
    }, 50);
  }
}


function toggleRefugeeSettlementOther(select){
  if(!select) return;
  const root = select.closest('form') || document;
  const other = root.querySelector('[name="refugee_settlement_other"]');
  if(!other) return;
  const show = select.value === 'Other (please specify)';
  other.style.display = show ? '' : 'none';
  if(!show) other.value = '';
}

function toggleAfterWorkPathway(select){
  if(!select) return;
  const root = select.closest('form') || document;
  const pathway = root.querySelector('[name="after_work_pathway"]');
  if(!pathway) return;
  const wrap = pathway.closest('.form-group');
  const show = ['New','Additional','Improved'].includes(select.value);
  if(wrap) wrap.style.display = show ? '' : 'none';
  pathway.required = show;
  if(!show) pathway.value = '';
}

function initParticipantConditionalFields(root){
  root = root || document;

  root.querySelectorAll('[name="refugee_status"]').forEach(function(status){
    const form = status.closest('form') || root;
    const settlement = form.querySelector('[name="refugee_settlement"]');
    if(!settlement) return;
    const group = settlement.closest('.form-group');
    const show = status.value === 'Yes';
    if(group) group.style.display = show ? '' : 'none';
    settlement.required = show;

    if(!show){
      settlement.value = '';
      const other = form.querySelector('[name="refugee_settlement_other"]');
      if(other){ other.value=''; other.style.display='none'; }
    }else{
      toggleRefugeeSettlementOther(settlement);
    }
  });

  root.querySelectorAll('[name="in_work_status"]').forEach(function(status){
    toggleAfterWorkPathway(status);
  });
}

document.addEventListener('DOMContentLoaded', function(){
  initParticipantConditionalFields(document);
});

/*
 * Delegated on `document` (not bound to specific elements) so this keeps
 * working for the "Add Learner" form AND for the Edit modal's fields,
 * even though the Edit modal's <select name="refugee_status"> etc. don't
 * exist yet at DOMContentLoaded time - they're built later via innerHTML
 * (see mealBuildEdit/mealOpenDirectRecordModal below). Binding listeners
 * to specific elements up front, like this used to, misses anything
 * added to the page afterward; delegation on `document` catches it
 * regardless of when the field was created.
 */
document.addEventListener('change', function(event){
  const status = event.target.closest('[name="refugee_status"]');
  if(status){
    initParticipantConditionalFields(status.closest('form') || document);
    return;
  }

  const settlement = event.target.closest('[name="refugee_settlement"]');
  if(settlement){
    toggleRefugeeSettlementOther(settlement);
    return;
  }

  const workStatus = event.target.closest('[name="in_work_status"]');
  if(workStatus){
    toggleAfterWorkPathway(workStatus);
  }
});

function toggleField(id, showVal, curVal){
  const el = document.getElementById(id);
  if (el) el.style.display = curVal === showVal ? '' : 'none';
}

function filterMealDocuments(category){
  document.querySelectorAll('.meal-doc-row').forEach(row=>{
    row.style.display =
      !category || row.dataset.category === category
        ? ''
        : 'none';
  });
}




/* ============================================================
   M&E LIGHTWEIGHT INTERACTIONS
============================================================ */
document.addEventListener('click', function(event){

  const addLink = event.target.closest('a[data-add-target]');

  if (addLink) {
    if (addLink.getAttribute('aria-disabled') === 'true') {
      event.preventDefault();
      openMealMessage(
        'Editing Blocked',
        'Adding or editing M&E records is currently blocked by the programme team.'
      );
      return;
    }

    const target = addLink.getAttribute('data-add-target');
    const body = target ? document.getElementById(target) : null;

    if (body) {
      event.preventDefault();
      body.classList.toggle('open');
      addLink.classList.toggle('open', body.classList.contains('open'));
      addLink.setAttribute(
        'aria-expanded',
        body.classList.contains('open') ? 'true' : 'false'
      );
      if (body.classList.contains('open')) {
        setTimeout(function(){
          body.scrollIntoView({behavior:'smooth', block:'nearest'});
        }, 40);
      }
    }
    return;
  }

  const uploadLink = event.target.closest('a[data-upload-target]');

  if (uploadLink) {
    const target = uploadLink.getAttribute('data-upload-target');
    const panel = target ? document.getElementById(target) : null;

    if (panel) {
      event.preventDefault();
      document.querySelectorAll('.upload-form.open').forEach(function(item){
        if (item !== panel) item.classList.remove('open');
      });
      panel.classList.toggle('open');
      if (panel.classList.contains('open')) {
        setTimeout(function(){
          panel.scrollIntoView({behavior:'smooth', block:'nearest'});
        }, 40);
      }
    }
    return;
  }

  const messageClose = event.target.closest('[data-message-close]');
  if (messageClose) {
    event.preventDefault();
    closeMealModal('mealMessageModal');
  }
});

function openMealMessage(title, message){
  const titleEl = document.getElementById('mealMessageTitle');
  const textEl = document.getElementById('mealMessageText');

  if (titleEl) {
    titleEl.innerHTML =
      '<i class="fa fa-info-circle"></i> '
      + String(title || 'Message');
  }

  if (textEl) {
    textEl.textContent = String(message || '');
  }

  openMealModal('mealMessageModal');
}

function openMealModal(id){
  const modal = document.getElementById(id);

  if (!modal) {
    console.error('M&E modal not found:', id);
    return false;
  }

  /*
   * Move every modal directly under BODY so no parent panel, grid,
   * transform or overflow rule can clip or hide it.
   */
  if (modal.parentElement !== document.body) {
    document.body.appendChild(modal);
  }

  modal.classList.add('open');
  modal.classList.add('show');
  modal.setAttribute('aria-hidden', 'false');

  /*
   * Force overlay visibility even when venture.css contains older
   * modal rules using !important.
   */
  modal.style.setProperty('display', 'flex', 'important');
  modal.style.setProperty('visibility', 'visible', 'important');
  modal.style.setProperty('opacity', '1', 'important');
  modal.style.setProperty('pointer-events', 'auto', 'important');
  modal.style.setProperty('z-index', '2147483646', 'important');

  document.body.classList.add('meal-modal-open');

  const card = modal.querySelector('.meal-modal-card');

  if (card) {
    card.style.setProperty('display', 'block', 'important');
    card.style.setProperty('visibility', 'visible', 'important');
    card.style.setProperty('opacity', '1', 'important');
  }

  const focusTarget =
    modal.querySelector(
      'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href]'
    );

  if (focusTarget) {
    setTimeout(function(){
      try {
        focusTarget.focus({preventScroll:true});
      } catch (error) {
        focusTarget.focus();
      }
    }, 50);
  }

  return true;
}

function closeMealModal(id){
  const modal = document.getElementById(id);

  if (!modal) return false;

  modal.classList.remove('open');
  modal.classList.remove('show');
  modal.classList.remove('active');
  modal.setAttribute('aria-hidden', 'true');

  modal.style.setProperty('display', 'none', 'important');
  modal.style.setProperty('visibility', 'hidden', 'important');
  modal.style.setProperty('opacity', '0', 'important');
  modal.style.setProperty('pointer-events', 'none', 'important');

  if (!document.querySelector('.meal-modal.open, .meal-modal.show')) {
    document.body.classList.remove('meal-modal-open');
  }

  return true;
}



const MEAL_DIRECT_SCHEMAS = <?= json_encode(
    $mealCrudSchemas,
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
    | JSON_HEX_AMP
) ?>;

const MEAL_DIRECT_VENTURE_ID = <?= (int)$venture_id ?>;
const MEAL_DIRECT_CAN_EDIT = <?= $MEAL_CAN_EDIT ? 'true' : 'false' ?>;
const MEAL_DIRECT_CAN_DELETE = <?= $MEAL_CAN_DELETE ? 'true' : 'false' ?>;

function mealEsc(value){
  return String(value ?? '')
    .replace(/&/g,'&amp;')
    .replace(/</g,'&lt;')
    .replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;')
    .replace(/'/g,'&#039;');
}

function mealPrettyValue(value){
  if(value === null || typeof value === 'undefined' || value === ''){
    return '-';
  }

  return String(value);
}

function mealDirectSchema(type){
  return MEAL_DIRECT_SCHEMAS[type] || null;
}

function mealBuildView(type, record){
  const schema = mealDirectSchema(type);

  if(!schema) return '<div class="no-data">Record details are unavailable.</div>';

  let html = '<div class="meal-view-grid">';

  Object.entries(schema.fields || {}).forEach(([name, field])=>{
    let value = record[name];

    if(type === 'documents' && name === 'category'){
      const categories = <?= json_encode(
          $mealDocumentCategories,
          JSON_UNESCAPED_UNICODE
          | JSON_UNESCAPED_SLASHES
          | JSON_HEX_TAG
          | JSON_HEX_APOS
          | JSON_HEX_QUOT
          | JSON_HEX_AMP
      ) ?>;
      value = categories[value] || value;
    }

    html +=
      '<div class="meal-view-item">'
      + '<span>' + mealEsc(field.label || name) + '</span>'
      + '<strong>' + mealEsc(mealPrettyValue(value)) + '</strong>'
      + '</div>';
  });

  if(type === 'documents'){
    html +=
      '<div class="meal-view-item meal-field-full">'
      + '<span>Uploaded File</span>'
      + '<strong>' + mealEsc(mealPrettyValue(record.original_name)) + '</strong>'
      + '</div>';
  }

  html += '</div>';

  return html;
}

function mealBuildField(type, name, field, record){
  const value = record[name] ?? '';
  const required = field.required ? ' required' : '';
  const label =
    '<label>'
    + mealEsc(field.label || name)
    + (field.required ? ' <span style="color:red">*</span>' : '')
    + '</label>';

  if(field.type === 'select'){
    let options = '';

    Object.entries(field.options || {}).forEach(([optionValue, optionLabel])=>{
      const selected =
        String(value) === String(optionValue)
          ? ' selected'
          : '';

      options +=
        '<option value="' + mealEsc(optionValue) + '"' + selected + '>'
        + mealEsc(optionLabel)
        + '</option>';
    });

    let attrs = '';

    if(type === 'participants' && name === 'refugee_status'){
      attrs += ' data-meal-refugee-status="1"';
    }

    if(type === 'participants' && name === 'refugee_settlement'){
      attrs += ' data-meal-refugee-settlement="1"';
    }

    if(type === 'participants' && name === 'in_work_status'){
      attrs += ' data-meal-after-status="1"';
    }

    if(type === 'participants' && name === 'after_work_pathway'){
      attrs += ' data-meal-after-pathway="1"';
    }

    let extra = '';

    if(type === 'participants' && name === 'refugee_settlement'){
      const known = [
        '',
        'Bidi bidi Refugee Settlement',
        'Nakivale Refugee Settlement',
        'Rhino Camp Refugee Settlement',
        'Palorinya Refugee Settlement',
        'Kyangwali Refugee Settlement',
        'Adjumani Settlements',
        'Kyaka II Refugee Settlement',
        'Other (please specify)'
      ];

      const custom = value && !known.includes(String(value))
        ? String(value)
        : '';

      if(custom){
        options = options.replace(
          'value="Other (please specify)"',
          'value="Other (please specify)" selected'
        );
      }

      extra =
        '<input type="text" '
        + 'name="refugee_settlement_other" '
        + 'class="form-control meal-refugee-settlement-other" '
        + 'value="' + mealEsc(custom) + '" '
        + 'placeholder="Please specify the refugee settlement" '
        + 'style="margin-top:8px;' + (custom ? '' : 'display:none;') + '">';
    }

    const conditionalClass =
      type === 'participants' && name === 'refugee_settlement'
        ? ' meal-refugee-settlement-group'
        : (
            type === 'participants' && name === 'after_work_pathway'
              ? ' meal-after-work-pathway-group'
              : ''
          );

    return (
      '<div class="form-group' + conditionalClass + '">'
      + label
      + '<select name="' + mealEsc(name) + '" class="form-control"'
      + attrs + required + '>'
      + options
      + '</select>'
      + extra
      + '</div>'
    );
  }

  if(field.type === 'textarea'){
    return (
      '<div class="form-group meal-field-full">'
      + label
      + '<textarea name="' + mealEsc(name) + '" class="form-control" rows="4"'
      + required + '>'
      + mealEsc(value)
      + '</textarea>'
      + '</div>'
    );
  }

  const inputType =
    ['date','email','number','tel','text'].includes(field.type)
      ? field.type
      : 'text';

  const step =
    typeof field.step !== 'undefined'
      ? ' step="' + mealEsc(field.step) + '"'
      : '';

  return (
    '<div class="form-group">'
    + label
    + '<input type="' + mealEsc(inputType) + '" '
    + 'name="' + mealEsc(name) + '" '
    + 'class="form-control" '
    + 'value="' + mealEsc(value) + '"'
    + step + required + '>'
    + '</div>'
  );
}

function mealBuildEdit(type, record){
  const schema = mealDirectSchema(type);

  if(!schema) return '';

  let fields = '';

  Object.entries(schema.fields || {}).forEach(([name, field])=>{
    fields += mealBuildField(type, name, field, record);
  });

  let documentNote = '';

  if(type === 'documents'){
    documentNote =
      '<div class="meal-document-current">'
      + '<i class="fa fa-paperclip"></i> Current file: '
      + '<strong>' + mealEsc(mealPrettyValue(record.original_name)) + '</strong>'
      + '<span>To replace the actual file, delete this document and upload a new file.</span>'
      + '</div>';
  }

  return (
    '<form method="POST" action="includes/process-meal-data.php" id="mealDirectEditForm">'
    + '<input type="hidden" name="action" value="edit_meal_record">'
    + '<input type="hidden" name="type" value="' + mealEsc(type) + '">'
    + '<input type="hidden" name="id" value="' + mealEsc(record.id) + '">'
    + '<input type="hidden" name="venture_id" value="' + mealEsc(MEAL_DIRECT_VENTURE_ID) + '">'
    + '<div class="form-grid-2">'
    + fields
    + '</div>'
    + documentNote
    + '</form>'
  );
}

function mealOpenDirectRecordModal(action, type, record){
  const schema = mealDirectSchema(type);
  const modal = document.getElementById('mealDirectRecordModal');
  const title = document.getElementById('mealDirectRecordTitle');
  const body = document.getElementById('mealDirectRecordBody');
  const footer = document.getElementById('mealDirectRecordFooter');

  if(!modal || !title || !body || !footer || !schema){
    openMealMessage(
      'Unable to Open',
      'The selected M&E record could not be opened.'
    );
    return;
  }

  const label = schema.label || 'Record';

  if(action === 'view'){
    title.innerHTML =
      '<i class="fa fa-eye"></i> View ' + mealEsc(label);

    body.innerHTML = mealBuildView(type, record);

    footer.innerHTML =
      '<button type="button" class="btn btn-outline" '
      + 'onclick="closeMealModal(\'mealDirectRecordModal\')">Close</button>'
      + (
          MEAL_DIRECT_CAN_EDIT
            ? '<button type="button" class="btn btn-primary" id="mealDirectSwitchEdit">'
              + '<i class="fa fa-edit"></i> Edit</button>'
            : ''
        );

    openMealModal('mealDirectRecordModal');

    const editButton = document.getElementById('mealDirectSwitchEdit');

    if(editButton){
      editButton.addEventListener('click', function(){
        mealOpenDirectRecordModal('edit', type, record);
      });
    }

    return;
  }

  if(action === 'edit'){
    if(!MEAL_DIRECT_CAN_EDIT){
      openMealMessage(
        'Editing Blocked',
        'Editing M&E records is currently blocked by the programme team.'
      );
      return;
    }

    title.innerHTML =
      '<i class="fa fa-edit"></i> Edit ' + mealEsc(label);

    body.innerHTML = mealBuildEdit(type, record);

    footer.innerHTML =
      '<button type="button" class="btn btn-outline" '
      + 'onclick="closeMealModal(\'mealDirectRecordModal\')">Cancel</button>'
      + '<button type="submit" form="mealDirectEditForm" class="btn btn-primary">'
      + '<i class="fa fa-save"></i> Save Changes</button>';

    openMealModal('mealDirectRecordModal');
    initParticipantConditionalFields(
      document.getElementById('mealDirectEditForm') || document
    );

    return;
  }

  if(action === 'delete'){
    if(!MEAL_DIRECT_CAN_DELETE){
      openMealMessage(
        'Deleting Blocked',
        'Deleting M&E records is currently blocked by the programme team.'
      );
      return;
    }

    title.innerHTML =
      '<i class="fa fa-exclamation-triangle"></i> Delete ' + mealEsc(label);

    body.innerHTML =
      '<div class="meal-confirm-content">'
      + '<div class="meal-confirm-icon danger"><i class="fa fa-trash"></i></div>'
      + '<div><strong>Delete this ' + mealEsc(label.toLowerCase()) + '?</strong>'
      + '<p>This item will be permanently removed. This action cannot be undone.</p>'
      + '</div></div>';

    const deleteAction =
      type === 'documents'
        ? 'delete_meal_supporting_document'
        : 'delete_meal_row';

    footer.innerHTML =
      '<button type="button" class="btn btn-outline" '
      + 'onclick="closeMealModal(\'mealDirectRecordModal\')">Cancel</button>'
      + '<form method="POST" action="includes/process-meal-data.php" style="display:inline">'
      + '<input type="hidden" name="action" value="' + mealEsc(deleteAction) + '">'
      + (
          type === 'documents'
            ? ''
            : '<input type="hidden" name="type" value="' + mealEsc(type) + '">'
        )
      + '<input type="hidden" name="id" value="' + mealEsc(record.id) + '">'
      + '<input type="hidden" name="venture_id" value="' + mealEsc(MEAL_DIRECT_VENTURE_ID) + '">'
      + '<button type="submit" class="btn btn-danger">'
      + '<i class="fa fa-trash"></i> Delete</button>'
      + '</form>';

    openMealModal('mealDirectRecordModal');
  }
}

document.addEventListener('click', function(event){
  const actionLink = event.target.closest('[data-meal-row-action]');

  if(!actionLink) return;

  event.preventDefault();

  if(actionLink.getAttribute('aria-disabled') === 'true'){
    const action = actionLink.getAttribute('data-meal-row-action');

    openMealMessage(
      action === 'delete' ? 'Deleting Blocked' : 'Editing Blocked',
      action === 'delete'
        ? 'Deleting M&E records is currently blocked by the programme team.'
        : 'Editing M&E records is currently blocked by the programme team.'
    );

    return;
  }

  const action = actionLink.getAttribute('data-meal-row-action') || '';
  const type = actionLink.getAttribute('data-meal-type') || '';
  const rawRecord = actionLink.getAttribute('data-meal-record') || '';

  let record = null;

  try{
    record = JSON.parse(rawRecord);
  }catch(error){
    console.error('M&E row JSON error:', error, rawRecord);
  }

  if(!record){
    openMealMessage(
      'Unable to Open',
      'The selected M&E record data could not be loaded.'
    );
    return;
  }

  mealOpenDirectRecordModal(action, type, record);
});

/* ============================================================
   UNIVERSAL MODAL EVENTS
============================================================ */
document.addEventListener('click', function(event){
  const closeButton = event.target.closest(
    '[data-meal-modal-close], .meal-modal-close'
  );

  if (closeButton) {
    const modal = closeButton.closest('.meal-modal');

    if (modal && modal.id) {
      event.preventDefault();
      closeMealModal(modal.id);
      return;
    }
  }

  const overlay = event.target;

  if (
    overlay
    && overlay.classList
    && overlay.classList.contains('meal-modal')
    && overlay.id
  ) {
    closeMealModal(overlay.id);
  }
});

document.addEventListener('keydown', function(event){
  if (event.key !== 'Escape') return;

  const opened = document.querySelector(
    '.meal-modal.open, .meal-modal.show'
  );

  if (opened && opened.id) {
    closeMealModal(opened.id);
  }
});

/* ============================================================
   M&E PAGE INITIALISATION
============================================================ */

document.addEventListener('DOMContentLoaded', function(){

  const documentFilter = document.getElementById('mealDocCategoryFilter');
  if (documentFilter) {
    documentFilter.addEventListener('change', function(){
      filterMealDocuments(this.value);
    });
  }


  document
    .querySelectorAll('.meal-modal')
    .forEach(function(modal){
      if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
      }
    });

  document
    .querySelectorAll(
      '#genericMealRecordModal.open, #mealDeleteServerModal.open'
    )
    .forEach(function(modal){
      if (modal.id) {
        openMealModal(modal.id);
      }
    });


  if (window.location.hash) {
    const hashTarget = document.getElementById(
      window.location.hash.substring(1)
    );

    if (
      hashTarget
      && hashTarget.classList.contains('upload-form')
    ) {
      hashTarget.classList.add('open');
    }
  }

  /*
   * Detach modals from layout wrappers immediately.
   */
  document.querySelectorAll('.meal-modal').forEach(modal=>{
    if (modal.parentElement !== document.body) {
      document.body.appendChild(modal);
    }
  });

  /*
   * Show the selected filename for every upload input.
   */
  document.querySelectorAll(
    '.upload-form input[type="file"], #panel-documents input[type="file"]'
  ).forEach(input=>{
    input.addEventListener('change', function(){
      let label = this.parentElement?.querySelector('.meal-file-name');

      if (!label) {
        label = document.createElement('span');
        label.className = 'meal-file-name';
        this.insertAdjacentElement('afterend', label);
      }

      label.textContent =
        this.files && this.files[0]
          ? 'Selected: ' + this.files[0].name
          : '';
    });
  });

  /*
   * Prevent double-click uploads and clearly show that submission started.
   */
  document.querySelectorAll(
    '.upload-form form, #panel-documents form[enctype="multipart/form-data"]'
  ).forEach(form=>{
    form.addEventListener('submit', function(event){
      const fileInput = this.querySelector('input[type="file"]');

      if (!fileInput || !fileInput.files || !fileInput.files.length) {
        event.preventDefault();
        openMealMessage(
          'Select a File',
          'Please select a file before starting the upload.'
        );
        return;
      }

      const button = this.querySelector('button[type="submit"]');

      if (button) {
        button.disabled = true;
        button.dataset.originalHtml = button.innerHTML;
        button.innerHTML =
          '<i class="fa fa-spinner fa-spin"></i> Uploading...';
      }
    });
  });

});


function toggleVerifiedLearnerOutcomeOther(select) {
    var wrap = document.getElementById('verifiedLearnerOutcomeOtherWrap');
    var input = document.getElementById('verifiedLearnerOutcomeOther');
    if (!wrap || !input) return;
    var show = select && select.value === 'Others please specify';
    wrap.style.display = show ? '' : 'none';
    input.required = show;
    if (!show) input.value = '';
}
</script>
</body>
</html>
