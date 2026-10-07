<?php
declare(strict_types=1);


require_once __DIR__ . '/config.php';

if (
    session_status()
    === PHP_SESSION_NONE
) {
    session_start();
}

if (empty($_SESSION['venture_id'])) {
    http_response_code(403);
    exit('Unauthorized');
}

if (
    !isset($conn)
    || !($conn instanceof mysqli)
) {
    die('Database connection error.');
}

$conn->set_charset('utf8mb4');

$venture_id =
    (int)$_SESSION['venture_id'];


function pmd_flash(
    string $message,
    string $type = 'success'
): void {
    $_SESSION['vp_flash_msg'] =
        $message;

    $_SESSION['vp_flash_type'] =
        $type;
}


function pmd_redirect(
    string $tab = 'participants'
): never {
    $allowed = [
        'participants',
        'teachers',
        'schools',
        'other_users',
        'employment',
        'finance',
        'partnerships',
        'revenue',
        'documents',
    ];

    if (
        !in_array(
            $tab,
            $allowed,
            true
        )
    ) {
        $tab = 'participants';
    }

    header(
        'Location: ../meal-data.php?tab='
        . urlencode($tab)
    );

    exit;
}


function pmd_access(
    mysqli $conn,
    int $ventureId
): array {
    $defaults = [
        'can_edit' => true,
        'can_delete' => true,
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
                (int)$row['can_edit'] === 1,
            'can_delete' =>
                (int)$row['can_delete'] === 1,
        ];

    } catch (Throwable $e) {
        error_log(
            'process-meal-data access read failed: '
            . $e->getMessage()
        );

        return $defaults;
    }
}


function pmd_ensure_documents_table(
    mysqli $conn
): void {
    $conn->query("
        CREATE TABLE IF NOT EXISTS meal_supporting_documents (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            venture_id INT UNSIGNED NOT NULL,
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


function pmd_upload_root(): string
{
    if (
        defined('UPLOAD_PATH')
        && trim((string)UPLOAD_PATH) !== ''
    ) {
        return rtrim(
            (string)UPLOAD_PATH,
            '/\\'
        );
    }

    /*
     * process-meal-data.php lives in /includes, so dirname(__DIR__)
     * is the project/document root.
     */
    return rtrim(
        dirname(__DIR__) . '/uploads',
        '/\\'
    );
}


function pmd_document_absolute_path(
    string $storedPath
): string {
    $storedPath =
        ltrim(
            str_replace(
                '\\',
                '/',
                trim($storedPath)
            ),
            '/'
        );

    if ($storedPath === '') {
        return '';
    }

    $base =
        rtrim(
            defined('BASE_PATH')
                ? BASE_PATH
                : dirname(__DIR__),
            '/'
        );

    return $base
        . '/'
        . $storedPath;
}



function pmd_column_exists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $safeTable = str_replace('`', '``', $table);
    $safeColumn = $conn->real_escape_string($column);

    $res = $conn->query(
        "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'"
    );

    return $res instanceof mysqli_result
        && $res->num_rows > 0;
}

function pmd_ensure_participant_reporting_columns(
    mysqli $conn
): void {
    $columns = [
        'phone' => 'VARCHAR(50) NULL',
        'email' => 'VARCHAR(190) NULL',
        'enrollment_category' => 'VARCHAR(20) NULL',
        'verified_learner_outcome' => 'VARCHAR(100) NULL',
        'verified_learner_outcome_other' => 'VARCHAR(255) NULL',
        'after_work_pathway' => 'VARCHAR(50) NULL',
    ];

    foreach ($columns as $column => $definition) {
        if (
            pmd_column_exists(
                $conn,
                'venture_participants',
                $column
            )
        ) {
            continue;
        }

        try {
            $conn->query(
                "ALTER TABLE `venture_participants`
                 ADD COLUMN `{$column}` {$definition}"
            );
        } catch (mysqli_sql_exception $e) {
            if ((int)$e->getCode() === 1060) {
                continue;
            }

            error_log(
                'M&E learner column migration failed for '
                . $column
                . ': '
                . $e->getMessage()
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

function pmd_normalize_participant_post(
    array &$post
): ?string {
    // Age restrictions (required, must be a whole number 1-120) are
    // removed for now - editing venture-side records was being blocked
    // whenever age was missing or didn't parse cleanly. Whatever is typed
    // is stored as-is; blank is fine too.
    $post['age_category'] = trim((string)($post['age_category'] ?? ''));

    $learnerStatus = trim((string)($post['user_status'] ?? ''));
    $validLearnerStatuses = ['Active', 'On hold', 'Dropped', 'Completed', 'Certified'];
    if (!in_array($learnerStatus, $validLearnerStatuses, true)) {
        return 'Select a valid learner status.';
    }

    $enrollmentCategory = trim((string)($post['enrollment_category'] ?? ''));
    $validEnrollmentCategories = ['', 'New', 'Returning'];
    if (!in_array($enrollmentCategory, $validEnrollmentCategories, true)) {
        return 'Select a valid enrollment category.';
    }
    $post['enrollment_category'] = $enrollmentCategory;

    $verifiedOutcome = trim((string)($post['verified_learner_outcome'] ?? ''));
    $validOutcomes = ['', 'Increased pass rates', 'Improved grades in STEM', 'Increased Agency and Voice', 'Others please specify'];
    if (!in_array($verifiedOutcome, $validOutcomes, true)) {
        return 'Select a valid verified learner outcome.';
    }
    if ($verifiedOutcome === 'Others please specify') {
        $otherOutcome = trim((string)($post['verified_learner_outcome_other'] ?? ''));
        if ($otherOutcome === '') {
            return 'Please specify the other verified learner outcome.';
        }
        $post['verified_learner_outcome_other'] = $otherOutcome;
    } else {
        $post['verified_learner_outcome_other'] = '';
    }

    $refugee = trim((string)($post['refugee_status'] ?? 'No'));
    $settlement = trim((string)($post['refugee_settlement'] ?? ''));

    $validSettlements = [
        'Bidi bidi Refugee Settlement',
        'Nakivale Refugee Settlement',
        'Rhino Camp Refugee Settlement',
        'Palorinya Refugee Settlement',
        'Kyangwali Refugee Settlement',
        'Adjumani Settlements',
        'Kyaka II Refugee Settlement',
        'Other (please specify)',
    ];

    if ($refugee !== 'Yes') {
        $post['refugee_status'] = 'No';
        $post['refugee_settlement'] = '';
    } else {
        $post['refugee_status'] = 'Yes';

        if ($settlement === 'Other (please specify)') {
            $custom = trim((string)($post['refugee_settlement_other'] ?? ''));

            if ($custom === '') {
                return 'Please specify the refugee settlement.';
            }

            $post['refugee_settlement'] = $custom;
        } elseif (!in_array($settlement, $validSettlements, true)) {
            if ($settlement === '') {
                return 'Select a refugee settlement.';
            }

            $post['refugee_settlement'] = $settlement;
        }
    }

    $before = trim((string)($post['working_at_entry'] ?? ''));

    if (
        $before !== ''
        && !in_array(
            $before,
            ['Self-employment', 'Wage employment', 'No'],
            true
        )
    ) {
        return 'Select a valid YIW Before status.';
    }

    $afterStatus = trim((string)($post['in_work_status'] ?? ''));
    $afterPathway = trim((string)($post['after_work_pathway'] ?? ''));

    if (
        $afterStatus !== ''
        && !in_array(
            $afterStatus,
            ['New', 'Additional', 'Improved'],
            true
        )
    ) {
        return 'Select a valid After-work Status.';
    }

    if ($afterStatus === '') {
        $post['after_work_pathway'] = '';
    } elseif (
        !in_array(
            $afterPathway,
            ['Self-employment', 'Wage employment'],
            true
        )
    ) {
        return 'Select whether the After-work Status is through Self-employment or Wage employment.';
    }

    return null;
}

pmd_ensure_participant_reporting_columns($conn);


$access =
    pmd_access(
        $conn,
        $venture_id
    );

$action =
    trim(
        (string)(
            $_POST['action']
            ?? $_GET['action']
            ?? ''
        )
    );

$tabByAction = [
    'add_meal_beneficiary' => 'participants',
    'edit_meal_beneficiary' => 'participants',
    'add_meal_teacher' => 'teachers',
    'add_meal_school' => 'schools',
    'add_meal_other_user' => 'other_users',
    'add_meal_employment' => 'employment',
    'add_meal_finance' => 'finance',
    'add_meal_partnership' => 'partnerships',
    'add_meal_revenue' => 'revenue',
];

/*
|--------------------------------------------------------------------------
| Add-record schemas
|--------------------------------------------------------------------------
| Field -> bind type ('s' string, 'd' double). Required fields mirror the
| red-asterisk fields on the "Add ..." forms in meal-data.php. Defaults are
| applied when a field is posted empty (e.g. toggled Yes/No selects that
| always submit a value, or optional selects with sensible fallbacks).
|--------------------------------------------------------------------------
*/
$mealAddSchemas = [
    'participants' => [
        'table' => 'venture_participants',
        'required' => ['entry_date', 'user_number', 'user_status', 'gender'],
        'fields' => [
            'entry_date' => 's',
            'user_number' => 's',
            'full_name' => 's',
            'phone' => 's',
            'email' => 's',
            'enrollment_category' => 's',
            'gender' => 's',
            'specific_location' => 's',
            'location_type' => 's',
            'age_category' => 's',
            'refugee_status' => 's',
            'refugee_settlement' => 's',
            'pwd_status' => 's',
            'impairment_type' => 's',
            'education_level' => 's',
            'user_status' => 's',
            'verified_learner_outcome' => 's',
            'verified_learner_outcome_other' => 's',
            'working_at_entry' => 's',
            'transformation_objective' => 's',
            'in_work_status' => 's',
            'after_work_pathway' => 's',
        ],
        'defaults' => [
            'refugee_status' => 'No',
            'pwd_status' => 'No',
        ],
    ],
    'teachers' => [
        'table' => 'meal_teachers',
        'required' => ['entry_date'],
        'fields' => [
            'entry_date' => 's',
            'teacher_name' => 's',
            'gender' => 's',
            'age_category' => 's',
            'refugee_status' => 's',
            'host_community' => 's',
            'location_type' => 's',
            'pwd_status' => 's',
        ],
        'defaults' => [
            'refugee_status' => 'No',
            'host_community' => 'No',
            'pwd_status' => 'No',
        ],
    ],
    'schools' => [
        'table' => 'meal_schools',
        'required' => ['entry_date', 'school_name'],
        'fields' => [
            'entry_date' => 's',
            'school_name' => 's',
            'location_type' => 's',
            'ownership_type' => 's',
            'school_level' => 's',
        ],
        'defaults' => [],
    ],
    'other_users' => [
        'table' => 'meal_other_users',
        'required' => ['entry_date'],
        'fields' => [
            'entry_date' => 's',
            'gender' => 's',
            'host_community' => 's',
            'location_type' => 's',
            'pwd_status' => 's',
            'education_level' => 's',
        ],
        'defaults' => [
            'host_community' => 'No',
            'pwd_status' => 'No',
        ],
    ],
    'employment' => [
        'table' => 'meal_employment',
        'required' => ['placement_date', 'full_name', 'job_title'],
        'fields' => [
            'placement_date' => 's',
            'full_name' => 's',
            'gender' => 's',
            'age_category' => 's',
            'pwd_status' => 's',
            'employment_type' => 's',
            'job_title' => 's',
            'status' => 's',
        ],
        'defaults' => [
            'pwd_status' => 'No',
            'status' => 'Active',
        ],
    ],
    'finance' => [
        'table' => 'meal_finance',
        'required' => ['date_mobilised', 'finance_usd', 'funding_form', 'funding_source'],
        'fields' => [
            'date_mobilised' => 's',
            'finance_usd' => 'd',
            'funding_form' => 's',
            'funding_source' => 's',
        ],
        'defaults' => [],
    ],
    'partnerships' => [
        'table' => 'meal_partnerships',
        'required' => ['partner_date', 'partner_name'],
        'fields' => [
            'partner_date' => 's',
            'partner_name' => 's',
            'partnership_type' => 's',
            'partnership_status' => 's',
        ],
        'defaults' => [
            'partnership_status' => 'Active',
        ],
    ],
    'revenue' => [
        'table' => 'meal_revenue',
        'required' => ['revenue_date', 'gross_revenue_ugx'],
        'fields' => [
            'revenue_date' => 's',
            'gross_revenue_ugx' => 'd',
            'revenue_stream' => 's',
        ],
        'defaults' => [],
    ],
];

$writeActions =
    array_keys(
        $tabByAction
    );

$writeActions[] =
    'upload_meal_csv';

$writeActions[] =
    'edit_meal_record';


/* ============================================================
   DOCUMENT DOWNLOAD
============================================================ */

if (
    $action ===
    'download_meal_supporting_document'
) {
    pmd_ensure_documents_table(
        $conn
    );

    $id =
        (int)(
            $_GET['id']
            ?? 0
        );

    $stmt = $conn->prepare("
        SELECT
            original_name,
            file_path,
            file_ext
        FROM meal_supporting_documents
        WHERE id = ?
          AND venture_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        http_response_code(404);
        exit('Document not found.');
    }

    $stmt->bind_param(
        'ii',
        $id,
        $venture_id
    );

    $stmt->execute();

    $doc = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    if (!$doc) {
        http_response_code(404);
        exit('Document not found.');
    }

    $absolute =
        pmd_document_absolute_path(
            (string)$doc['file_path']
        );

    if (
        $absolute === ''
        || !is_file($absolute)
    ) {
        http_response_code(404);
        exit('Document file was not found.');
    }

    $mime =
        function_exists(
            'mime_content_type'
        )
            ? mime_content_type($absolute)
            : false;

    if (!$mime) {
        $mime =
            'application/octet-stream';
    }

    $name =
        basename(
            (string)$doc['original_name']
        );

    while (ob_get_level()) {
        ob_end_clean();
    }

    header(
        'Content-Type: '
        . $mime
    );

    header(
        'Content-Disposition: attachment; filename="'
        . str_replace(
            '"',
            '',
            $name
        )
        . '"'
    );

    header(
        'Content-Length: '
        . filesize($absolute)
    );

    header(
        'Cache-Control: private, no-store'
    );

    readfile($absolute);
    exit;
}


/* ============================================================
   DOCUMENT UPLOAD
============================================================ */

if (
    $action ===
    'upload_meal_supporting_document'
) {
    if (!$access['can_edit']) {
        pmd_flash(
            'Document uploads are currently blocked by the programme team.',
            'error'
        );

        pmd_redirect(
            'documents'
        );
    }

    $postedVenture =
        (int)(
            $_POST['venture_id']
            ?? 0
        );

    if (
        $postedVenture !==
        $venture_id
    ) {
        pmd_flash(
            'Permission denied.',
            'error'
        );

        pmd_redirect(
            'documents'
        );
    }

    $categories = [
        'participants',
        'teachers',
        'schools',
        'other_users',
        'employment',
        'finance',
        'partnerships',
        'revenue',
    ];

    $category =
        trim(
            (string)(
                $_POST['category']
                ?? ''
            )
        );

    $documentName =
        trim(
            (string)(
                $_POST['document_name']
                ?? ''
            )
        );

    $description =
        trim(
            (string)(
                $_POST['description']
                ?? ''
            )
        );

    if (
        !in_array(
            $category,
            $categories,
            true
        )
    ) {
        pmd_flash(
            'Select a valid M&E category.',
            'error'
        );

        pmd_redirect(
            'documents'
        );
    }

    if ($documentName === '') {
        pmd_flash(
            'Document name is required.',
            'error'
        );

        pmd_redirect(
            'documents'
        );
    }

    if (
        !isset(
            $_FILES[
                'supporting_document'
            ]
        )
        || !is_array(
            $_FILES[
                'supporting_document'
            ]
        )
        || trim(
            (string)(
                $_FILES[
                    'supporting_document'
                ]['name']
                ?? ''
            )
        ) === ''
    ) {
        pmd_flash(
            'Select a supporting document.',
            'error'
        );

        pmd_redirect(
            'documents'
        );
    }

    $file =
        $_FILES[
            'supporting_document'
        ];

    if (
        (int)($file['error'] ?? UPLOAD_ERR_NO_FILE)
        !== UPLOAD_ERR_OK
    ) {
        $uploadError =
            (int)(
                $file['error']
                ?? UPLOAD_ERR_NO_FILE
            );

        $message = match ($uploadError) {
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE
                => 'The selected file is larger than the server upload limit.',

            UPLOAD_ERR_PARTIAL
                => 'The file upload was interrupted. Please try again.',

            UPLOAD_ERR_NO_FILE
                => 'Select a file to upload.',

            UPLOAD_ERR_NO_TMP_DIR
                => 'The server temporary upload folder is missing.',

            UPLOAD_ERR_CANT_WRITE
                => 'The server could not write the uploaded file.',

            UPLOAD_ERR_EXTENSION
                => 'A server extension stopped the file upload.',

            default
                => 'The supporting document could not be uploaded.',
        };

        error_log(
            'M&E supporting upload PHP error code: '
            . $uploadError
        );

        pmd_flash(
            $message,
            'error'
        );

        pmd_redirect(
            'documents'
        );
    }

    if (
        empty($file['tmp_name'])
        || !is_uploaded_file(
            (string)$file['tmp_name']
        )
    ) {
        pmd_flash(
            'The uploaded file could not be verified. Please select it again.',
            'error'
        );

        pmd_redirect(
            'documents'
        );
    }

    $maxBytes =
        20 * 1024 * 1024;

    if (
        (int)$file['size']
        > $maxBytes
    ) {
        pmd_flash(
            'Supporting documents must be 20 MB or smaller.',
            'error'
        );

        pmd_redirect(
            'documents'
        );
    }

    $ext =
        strtolower(
            pathinfo(
                (string)$file['name'],
                PATHINFO_EXTENSION
            )
        );

    $allowedExt = [
        'pdf',
        'doc',
        'docx',
        'xls',
        'xlsx',
        'csv',
        'ppt',
        'pptx',
        'png',
        'jpg',
        'jpeg',
        'webp',
        'zip',
    ];

    if (
        !in_array(
            $ext,
            $allowedExt,
            true
        )
    ) {
        pmd_flash(
            'This supporting document file type is not allowed.',
            'error'
        );

        pmd_redirect(
            'documents'
        );
    }

    pmd_ensure_documents_table(
        $conn
    );

    $uploadRoot =
        pmd_upload_root();

    $directory =
        $uploadRoot
        . '/ventures/meal-documents/'
        . $venture_id
        . '/';

    if (!is_dir($directory)) {
        if (
            !@mkdir(
                $directory,
                0755,
                true
            )
            && !is_dir($directory)
        ) {
            error_log(
                'M&E document directory creation failed: '
                . $directory
            );

            pmd_flash(
                'Could not create the M&E document upload folder. Please check the uploads folder permissions.',
                'error'
            );

            pmd_redirect(
                'documents'
            );
        }
    }

    if (!is_writable($directory)) {
        error_log(
            'M&E document directory is not writable: '
            . $directory
        );

        pmd_flash(
            'The M&E document upload folder is not writable. Please contact the administrator.',
            'error'
        );

        pmd_redirect(
            'documents'
        );
    }

    $filename =
        bin2hex(
            random_bytes(12)
        )
        . '.'
        . $ext;

    $absolute =
        $directory
        . $filename;

    if (
        !move_uploaded_file(
            (string)$file['tmp_name'],
            $absolute
        )
    ) {
        pmd_flash(
            'Could not save the supporting document.',
            'error'
        );

        pmd_redirect(
            'documents'
        );
    }

    $relative =
        'uploads/ventures/meal-documents/'
        . $venture_id
        . '/'
        . $filename;

    $original =
        basename(
            (string)$file['name']
        );

    $fileSize =
        (int)$file['size'];

    $uploadedBy =
        trim(
            (string)(
                $_SESSION[
                    'venture_founder'
                ]
                ?? $_SESSION[
                    'venture_name'
                ]
                ?? 'Venture'
            )
        );

    $stmt = $conn->prepare("
        INSERT INTO meal_supporting_documents
            (
                venture_id,
                category,
                document_name,
                description,
                original_name,
                file_path,
                file_ext,
                file_size,
                uploaded_by
            )
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        @unlink($absolute);

        pmd_flash(
            'Could not prepare the document record.',
            'error'
        );

        pmd_redirect(
            'documents'
        );
    }

    $stmt->bind_param(
        'issssssis',
        $venture_id,
        $category,
        $documentName,
        $description,
        $original,
        $relative,
        $ext,
        $fileSize,
        $uploadedBy
    );

    if (!$stmt->execute()) {
        $error =
            $stmt->error;

        $stmt->close();

        @unlink($absolute);

        pmd_flash(
            'Document upload failed: '
            . $error,
            'error'
        );

        pmd_redirect(
            'documents'
        );
    }

    $stmt->close();

    pmd_flash(
        'Supporting document uploaded successfully.'
    );

    pmd_redirect(
        'documents'
    );
}


/* ============================================================
   DOCUMENT DELETE
============================================================ */

if (
    $action ===
    'delete_meal_supporting_document'
) {
    if (!$access['can_delete']) {
        pmd_flash(
            'Deleting M&E supporting documents is currently blocked by the programme team.',
            'error'
        );

        pmd_redirect(
            'documents'
        );
    }

    $postedVenture =
        (int)(
            $_POST['venture_id']
            ?? 0
        );

    if (
        $postedVenture !==
        $venture_id
    ) {
        pmd_flash(
            'Permission denied.',
            'error'
        );

        pmd_redirect(
            'documents'
        );
    }

    pmd_ensure_documents_table(
        $conn
    );

    $id =
        (int)(
            $_POST['id']
            ?? 0
        );

    $stmt = $conn->prepare("
        SELECT
            file_path
        FROM meal_supporting_documents
        WHERE id = ?
          AND venture_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        pmd_flash(
            'Supporting document not found.',
            'error'
        );

        pmd_redirect(
            'documents'
        );
    }

    $stmt->bind_param(
        'ii',
        $id,
        $venture_id
    );

    $stmt->execute();

    $doc = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    if (!$doc) {
        pmd_flash(
            'Supporting document not found.',
            'error'
        );

        pmd_redirect(
            'documents'
        );
    }

    $delete = $conn->prepare("
        DELETE FROM meal_supporting_documents
        WHERE id = ?
          AND venture_id = ?
        LIMIT 1
    ");

    if (!$delete) {
        pmd_flash(
            'Could not prepare document deletion.',
            'error'
        );

        pmd_redirect(
            'documents'
        );
    }

    $delete->bind_param(
        'ii',
        $id,
        $venture_id
    );

    $ok =
        $delete->execute();

    $delete->close();

    if (!$ok) {
        pmd_flash(
            'Supporting document could not be deleted.',
            'error'
        );

        pmd_redirect(
            'documents'
        );
    }

    $absolute =
        pmd_document_absolute_path(
            (string)$doc['file_path']
        );

    if (
        $absolute !== ''
        && is_file($absolute)
    ) {
        @unlink($absolute);
    }

    pmd_flash(
        'Supporting document deleted.'
    );

    pmd_redirect(
        'documents'
    );
}


/* ============================================================
   ADMIN EDIT/DELETE CONTROL GATE
============================================================ */

if (
    in_array(
        $action,
        $writeActions,
        true
    )
    && !$access['can_edit']
) {
    $tab =
        $action ===
        'upload_meal_csv'
            ? trim(
                (string)(
                    $_POST['type']
                    ?? 'participants'
                )
            )
            : (
                $tabByAction[$action]
                ?? 'participants'
            );

    pmd_flash(
        'Editing, adding and importing M&E data is currently blocked by the programme team.',
        'error'
    );

    pmd_redirect(
        $tab
    );
}


if (
    $action ===
    'delete_meal_row'
    && !$access['can_delete']
) {
    $tab =
        trim(
            (string)(
                $_POST['type']
                ?? 'participants'
            )
        );

    pmd_flash(
        'Deleting M&E data is currently blocked by the programme team.',
        'error'
    );

    pmd_redirect(
        $tab
    );
}


/* ============================================================
   GENERIC M&E RECORD ADD  (fixes participants/teachers/schools/
   other_users/employment/finance/partnerships/revenue "Add" forms
   not saving — previously these all silently depended on
   portal-process.php having matching logic, which Partnerships
   and Revenue did not.)
============================================================ */

if (isset($tabByAction[$action]) && $action !== 'edit_meal_beneficiary') {

    $type =
        $tabByAction[$action];

    // Access already gated above, but keep a defensive check in case
    // this block is ever reached via a different code path.
    if (!$access['can_edit']) {
        pmd_flash(
            'Adding M&E records is currently blocked by the programme team.',
            'error'
        );

        pmd_redirect($type);
    }

    $postedVenture =
        (int)(
            $_POST['venture_id']
            ?? 0
        );

    if ($postedVenture !== $venture_id) {
        pmd_flash(
            'Permission denied.',
            'error'
        );

        pmd_redirect($type);
    }

    $schema =
        $mealAddSchemas[$type]
        ?? null;

    if (!$schema) {
        pmd_flash(
            'Unsupported M&E record type.',
            'error'
        );

        pmd_redirect($type);
    }

    if ($type === 'participants') {
        $participantError = pmd_normalize_participant_post($_POST);

        if ($participantError !== null) {
            pmd_flash($participantError, 'error');
            pmd_redirect('participants');
        }
    }

    foreach ($schema['required'] as $requiredField) {
        if (
            trim(
                (string)(
                    $_POST[$requiredField]
                    ?? ''
                )
            ) === ''
        ) {
            pmd_flash(
                'Please complete all required fields.',
                'error'
            );

            pmd_redirect($type);
        }
    }

    // Learner Number must be unique per venture.
    if ($type === 'participants') {
        $userNumber =
            trim(
                (string)(
                    $_POST['user_number']
                    ?? ''
                )
            );

        $dupCheck = $conn->prepare("
            SELECT id
            FROM venture_participants
            WHERE venture_id = ?
              AND user_number = ?
            LIMIT 1
        ");

        if ($dupCheck) {
            $dupCheck->bind_param(
                'is',
                $venture_id,
                $userNumber
            );

            $dupCheck->execute();

            $duplicateExists =
                $dupCheck
                    ->get_result()
                    ->num_rows > 0;

            $dupCheck->close();

            if ($duplicateExists) {
                pmd_flash(
                    'A learner with that Learner Number already exists.',
                    'error'
                );

                pmd_redirect($type);
            }
        }
    }

    $columns = ['venture_id'];
    $placeholders = ['?'];
    $bindTypes = 'i';
    $params = [$venture_id];

    foreach ($schema['fields'] as $field => $bindType) {
        $raw =
            $_POST[$field]
            ?? ($schema['defaults'][$field] ?? '');

        if ($bindType === 'd') {
            $value = (float)$raw;
        } else {
            $value = trim((string)$raw);

            if ($value === '' && isset($schema['defaults'][$field])) {
                $value = $schema['defaults'][$field];
            }
        }

        $columns[] = $field;
        $placeholders[] = '?';
        $bindTypes .= $bindType;
        $params[] = $value;
    }

    $table = $schema['table'];

    $sql =
        "INSERT INTO `{$table}` (`"
        . implode('`,`', $columns)
        . "`) VALUES ("
        . implode(',', $placeholders)
        . ")";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log(
            'M&E add-record prepare failed ('
            . $type . '): '
            . $conn->error
        );

        pmd_flash(
            'Could not prepare the record for saving.',
            'error'
        );

        pmd_redirect($type);
    }

    $stmt->bind_param($bindTypes, ...$params);

    if ($stmt->execute()) {
        pmd_flash(
            'Record saved successfully.'
        );
    } else {
        error_log(
            'M&E add-record insert failed ('
            . $type . '): '
            . $stmt->error
        );

        pmd_flash(
            'Could not save the record. Please check the form and try again.',
            'error'
        );
    }

    $stmt->close();

    pmd_redirect($type);
}


/* ============================================================
   GENERIC M&E RECORD EDIT
============================================================ */

if ($action === 'edit_meal_record') {
    if (!$access['can_edit']) {
        pmd_flash(
            'Editing M&E data is currently blocked by the programme team.',
            'error'
        );

        pmd_redirect(
            trim(
                (string)(
                    $_POST['type']
                    ?? 'participants'
                )
            )
        );
    }

    $postedVenture =
        (int)(
            $_POST['venture_id']
            ?? 0
        );

    if ($postedVenture !== $venture_id) {
        pmd_flash(
            'Permission denied.',
            'error'
        );

        pmd_redirect(
            'participants'
        );
    }

    $type =
        trim(
            (string)(
                $_POST['type']
                ?? ''
            )
        );

    $id =
        max(
            0,
            (int)(
                $_POST['id']
                ?? 0
            )
        );

    $schemas = [
        'participants' => [
            'table' => 'venture_participants',
            'fields' => [
                'entry_date'=>'s',
                'user_number'=>'s',
                'full_name'=>'s',
                'phone'=>'s',
                'email'=>'s',
                'enrollment_category'=>'s',
                'gender'=>'s',
                'specific_location'=>'s',
                'location_type'=>'s',
                'age_category'=>'s',
                'refugee_status'=>'s',
                'refugee_settlement'=>'s',
                'pwd_status'=>'s',
                'impairment_type'=>'s',
                'education_level'=>'s',
                'user_status'=>'s',
                'verified_learner_outcome'=>'s',
                'verified_learner_outcome_other'=>'s',
                'working_at_entry'=>'s',
                'transformation_objective'=>'s',
                'in_work_status'=>'s',
                'after_work_pathway'=>'s',
            ],
            'required' => [
                'entry_date',
                'user_number',
            ],
        ],

        'teachers' => [
            'table' => 'meal_teachers',
            'fields' => [
                'entry_date'=>'s',
                'teacher_name'=>'s',
                'gender'=>'s',
                'age_category'=>'s',
                'refugee_status'=>'s',
                'host_community'=>'s',
                'location_type'=>'s',
                'pwd_status'=>'s',
            ],
            'required' => ['teacher_name'],
        ],

        'schools' => [
            'table' => 'meal_schools',
            'fields' => [
                'entry_date'=>'s',
                'school_name'=>'s',
                'location_type'=>'s',
                'ownership_type'=>'s',
                'school_level'=>'s',
            ],
            'required' => ['school_name'],
        ],

        'other_users' => [
            'table' => 'meal_other_users',
            'fields' => [
                'entry_date'=>'s',
                'gender'=>'s',
                'host_community'=>'s',
                'location_type'=>'s',
                'pwd_status'=>'s',
                'education_level'=>'s',
            ],
            'required' => [],
        ],

        'employment' => [
            'table' => 'meal_employment',
            'fields' => [
                'placement_date'=>'s',
                'full_name'=>'s',
                'gender'=>'s',
                'age_category'=>'s',
                'pwd_status'=>'s',
                'employment_type'=>'s',
                'job_title'=>'s',
                'status'=>'s',
            ],
            'required' => [
                'full_name',
                'job_title',
            ],
        ],

        'finance' => [
            'table' => 'meal_finance',
            'fields' => [
                'date_mobilised'=>'s',
                'finance_usd'=>'d',
                'funding_form'=>'s',
                'funding_source'=>'s',
            ],
            'required' => [
                'funding_form',
                'funding_source',
            ],
        ],

        'partnerships' => [
            'table' => 'meal_partnerships',
            'fields' => [
                'partner_date'=>'s',
                'partner_name'=>'s',
                'partnership_type'=>'s',
                'partnership_status'=>'s',
            ],
            'required' => [
                'partner_name',
            ],
        ],

        'revenue' => [
            'table' => 'meal_revenue',
            'fields' => [
                'revenue_date'=>'s',
                'gross_revenue_ugx'=>'d',
                'revenue_stream'=>'s',
            ],
            'required' => [
                'revenue_date',
            ],
        ],

        'documents' => [
            'table' => 'meal_supporting_documents',
            'fields' => [
                'category'=>'s',
                'document_name'=>'s',
                'description'=>'s',
            ],
            'required' => [
                'category',
                'document_name',
            ],
        ],
    ];

    if (
        $id <= 0
        || !isset(
            $schemas[$type]
        )
    ) {
        pmd_flash(
            'Invalid M&E record selected.',
            'error'
        );

        pmd_redirect(
            in_array(
                $type,
                [
                    'teachers',
                    'schools',
                    'other_users',
                    'employment',
                    'finance',
                    'partnerships',
                    'revenue',
                    'documents',
                ],
                true
            )
                ? $type
                : 'participants'
        );
    }

    $schema =
        $schemas[$type];

    if ($type === 'participants') {
        $participantError = pmd_normalize_participant_post($_POST);

        if ($participantError !== null) {
            pmd_flash($participantError, 'error');
            pmd_redirect('participants');
        }
    }

    if ($type === 'documents') {
        $validCategories = [
            'participants',
            'teachers',
            'schools',
            'other_users',
            'employment',
            'finance',
            'partnerships',
            'revenue',
        ];

        if (
            !in_array(
                trim(
                    (string)(
                        $_POST['category']
                        ?? ''
                    )
                ),
                $validCategories,
                true
            )
        ) {
            pmd_flash(
                'Select a valid M&E document category.',
                'error'
            );

            pmd_redirect(
                'documents'
            );
        }
    }

    foreach (
        $schema['required']
        as $requiredField
    ) {
        if (
            trim(
                (string)(
                    $_POST[
                        $requiredField
                    ]
                    ?? ''
                )
            ) === ''
        ) {
            pmd_flash(
                'Please complete all required fields.',
                'error'
            );

            pmd_redirect(
                $type
            );
        }
    }

    $table =
        $schema['table'];

    $check = $conn->prepare("
        SELECT id
        FROM `{$table}`
        WHERE id = ?
          AND venture_id = ?
        LIMIT 1
    ");

    if (!$check) {
        pmd_flash(
            'Could not verify the record.',
            'error'
        );

        pmd_redirect(
            $type
        );
    }

    $check->bind_param(
        'ii',
        $id,
        $venture_id
    );

    $check->execute();

    $exists =
        $check
            ->get_result()
            ->num_rows > 0;

    $check->close();

    if (!$exists) {
        pmd_flash(
            'Record not found.',
            'error'
        );

        pmd_redirect(
            $type
        );
    }

    $sets = [];
    $types = '';
    $params = [];

    foreach (
        $schema['fields']
        as $field => $bindType
    ) {
        $sets[] =
            "`{$field}` = ?";

        if ($bindType === 'd') {
            $value =
                (float)(
                    $_POST[$field]
                    ?? 0
                );
        } else {
            $value =
                trim(
                    (string)(
                        $_POST[$field]
                        ?? ''
                    )
                );
        }

        $types .=
            $bindType;

        $params[] =
            $value;
    }

    $types .= 'ii';

    $params[] =
        $id;

    $params[] =
        $venture_id;

    $sql = "
        UPDATE `{$table}`
        SET "
        . implode(
            ', ',
            $sets
        )
        . "
        WHERE id = ?
          AND venture_id = ?
        LIMIT 1
    ";

    $stmt =
        $conn->prepare(
            $sql
        );

    if (!$stmt) {
        pmd_flash(
            'Could not prepare the update: '
            . $conn->error,
            'error'
        );

        pmd_redirect(
            $type
        );
    }

    $stmt->bind_param(
        $types,
        ...$params
    );

    if ($stmt->execute()) {
        pmd_flash(
            'M&E record updated successfully.'
        );
    } else {
        pmd_flash(
            'Update failed: '
            . $stmt->error,
            'error'
        );
    }

    $stmt->close();

    pmd_redirect(
        $type
    );
}




require __DIR__
    . '/portal-process.php';
