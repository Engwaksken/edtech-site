<?php
declare(strict_types=1);


require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database connection error.');
}

$conn->set_charset('utf8mb4');

function meal_redirect(string $message, string $type = 'success'): never
{
    $_SESSION[$type === 'success' ? 'meal_report_success' : 'meal_report_error'] = $message;

    $target = '../meal-report';
    $returnTo = trim((string)($_POST['return_to'] ?? ''));

    if ($returnTo !== '') {
        $parts = parse_url(html_entity_decode($returnTo, ENT_QUOTES, 'UTF-8'));

        if ($parts !== false && !isset($parts['scheme']) && !isset($parts['host'])) {
            $path = (string)($parts['path'] ?? '');

            if (
                $path === '/admin/meal-report'
                || $path === 'meal-report'
                || str_ends_with($path, '/admin/meal-report')
            ) {
                $q = trim((string)($parts['query'] ?? ''));
                $target = '../meal-report' . ($q !== '' ? '?' . $q : '');
            }
        }
    }

    header('Location: ' . $target);
    exit;
}

function meal_require_token(): void
{
    $a = (string)($_POST['token'] ?? '');
    $b = (string)($_SESSION['meal_report_token'] ?? '');

    if ($a === '' || $b === '' || !hash_equals($b, $a)) {
        meal_redirect(
            'Your session expired. Refresh the M&E report page and try again.',
            'error'
        );
    }
}

function meal_ensure_controls(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS meal_venture_controls (
            venture_id BIGINT NOT NULL PRIMARY KEY,
            can_edit TINYINT(1) NOT NULL DEFAULT 1,
            can_delete TINYINT(1) NOT NULL DEFAULT 1,
            updated_by BIGINT NULL,
            updated_at TIMESTAMP NOT NULL
                DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_meal_controls_edit (can_edit),
            INDEX idx_meal_controls_delete (can_delete)
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
    ");
}

function meal_ensure_documents(mysqli $conn): void
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

function meal_venture_can_edit(mysqli $conn, int $ventureId): bool
{
    meal_ensure_controls($conn);

    $st = $conn->prepare("
        SELECT can_edit
        FROM meal_venture_controls
        WHERE venture_id=?
        LIMIT 1
    ");

    if (!$st) {
        return true;
    }

    $st->bind_param('i', $ventureId);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();

    return !$r || (int)$r['can_edit'] === 1;
}

function meal_venture_can_delete(mysqli $conn, int $ventureId): bool
{
    meal_ensure_controls($conn);

    $st = $conn->prepare("
        SELECT can_delete
        FROM meal_venture_controls
        WHERE venture_id=?
        LIMIT 1
    ");

    if (!$st) {
        return true;
    }

    $st->bind_param('i', $ventureId);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();

    return !$r || (int)$r['can_delete'] === 1;
}

function meal_document_absolute_path(string $storedPath): string
{
    $storedPath = ltrim(str_replace('\\', '/', trim($storedPath)), '/');
    $root = dirname(__DIR__, 2);

    return $root . '/' . $storedPath;
}

function meal_safe_download_name(string $name): string
{
    $name = basename(trim($name));
    $name = preg_replace('/[\r\n"]+/', '', $name) ?? 'document';

    return $name !== '' ? $name : 'document';
}

/*
|--------------------------------------------------------------------------
| Admin supporting-document download
|--------------------------------------------------------------------------
| Admin authentication is already enforced by auth.php. Download is GET and
| read-only, so it does not require the POST CSRF token.
|--------------------------------------------------------------------------
*/
if (
    strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET'
    && trim((string)($_GET['action'] ?? '')) === 'download_supporting_document'
) {
    try {
        meal_ensure_documents($conn);

        $id = (int)($_GET['id'] ?? 0);

        if ($id <= 0) {
            throw new RuntimeException('Invalid supporting document.');
        }

        $st = $conn->prepare("
            SELECT original_name, file_path
            FROM meal_supporting_documents
            WHERE id=?
            LIMIT 1
        ");

        if (!$st) {
            throw new RuntimeException('Could not load supporting document.');
        }

        $st->bind_param('i', $id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$row) {
            throw new RuntimeException('Supporting document not found.');
        }

        $absolute = meal_document_absolute_path((string)$row['file_path']);

        if (!is_file($absolute) || !is_readable($absolute)) {
            throw new RuntimeException('The uploaded file could not be found on the server.');
        }

        $downloadName = meal_safe_download_name((string)$row['original_name']);

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . (string)filesize($absolute));
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');

        readfile($absolute);
        exit;

    } catch (Throwable $e) {
        $_SESSION['meal_report_error'] = $e->getMessage();
        header('Location: ../meal-report?tab=documents');
        exit;
    }
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    meal_redirect('Invalid M&E report request.', 'error');
}

meal_require_token();

$action = trim((string)($_POST['action'] ?? ''));

if ($action === 'set_venture_access') {
    try {
        meal_ensure_controls($conn);

        $ventureId = (int)($_POST['venture_id'] ?? 0);
        $permission = trim((string)($_POST['permission'] ?? ''));
        $allow = (int)($_POST['allow'] ?? 0) === 1 ? 1 : 0;

        if ($ventureId <= 0) {
            throw new RuntimeException('Invalid venture selected.');
        }

        if (!in_array($permission, ['edit', 'delete'], true)) {
            throw new RuntimeException('Invalid access setting.');
        }

        $st = $conn->prepare("SELECT id FROM ventures WHERE id=? LIMIT 1");

        if (!$st) {
            throw new RuntimeException('Could not verify venture.');
        }

        $st->bind_param('i', $ventureId);
        $st->execute();
        $exists = $st->get_result()->num_rows > 0;
        $st->close();

        if (!$exists) {
            throw new RuntimeException('Venture not found.');
        }

        $adminId = (int)(
            $GLOBALS['ADMIN']['id']
            ?? $_SESSION['admin_id']
            ?? $_SESSION['user_id']
            ?? 0
        );

        $st = $conn->prepare("
            INSERT INTO meal_venture_controls
                (venture_id,can_edit,can_delete,updated_by)
            VALUES
                (?,1,1,NULLIF(?,0))
            ON DUPLICATE KEY UPDATE
                updated_by=NULLIF(VALUES(updated_by),0)
        ");

        if (!$st) {
            throw new RuntimeException('Could not initialise venture access.');
        }

        $st->bind_param('ii', $ventureId, $adminId);
        $st->execute();
        $st->close();

        $column = $permission === 'edit' ? 'can_edit' : 'can_delete';

        $st = $conn->prepare("
            UPDATE meal_venture_controls
            SET {$column}=?,
                updated_by=NULLIF(?,0),
                updated_at=NOW()
            WHERE venture_id=?
            LIMIT 1
        ");

        if (!$st) {
            throw new RuntimeException('Could not update venture access.');
        }

        $st->bind_param('iii', $allow, $adminId, $ventureId);

        if (!$st->execute()) {
            throw new RuntimeException('Could not update venture access: ' . $st->error);
        }

        $st->close();

        $label = $permission === 'edit' ? 'editing' : 'deleting';

        meal_redirect(
            $allow
                ? "Venture {$label} access enabled."
                : "Venture {$label} access blocked."
        );

    } catch (Throwable $e) {
        meal_redirect($e->getMessage(), 'error');
    }
}

if ($action === 'delete_supporting_document') {
    try {
        meal_ensure_documents($conn);

        $id = (int)($_POST['document_id'] ?? 0);

        if ($id <= 0) {
            throw new RuntimeException('Invalid supporting document.');
        }

        $st = $conn->prepare("
            SELECT id, file_path
            FROM meal_supporting_documents
            WHERE id=?
            LIMIT 1
        ");

        if (!$st) {
            throw new RuntimeException('Could not load supporting document.');
        }

        $st->bind_param('i', $id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$row) {
            throw new RuntimeException('Supporting document not found.');
        }

        $st = $conn->prepare("
            DELETE FROM meal_supporting_documents
            WHERE id=?
            LIMIT 1
        ");

        if (!$st) {
            throw new RuntimeException('Could not prepare document deletion.');
        }

        $st->bind_param('i', $id);

        if (!$st->execute()) {
            throw new RuntimeException('Could not delete supporting document: ' . $st->error);
        }

        $st->close();

        $absolute = meal_document_absolute_path((string)$row['file_path']);

        if (is_file($absolute)) {
            @unlink($absolute);
        }

        meal_redirect('Supporting document deleted successfully.');

    } catch (Throwable $e) {
        meal_redirect($e->getMessage(), 'error');
    }
}

if ($action === 'bulk_delete') {
    $map = [
        'participants' => 'venture_participants',
        'teachers' => 'meal_teachers',
        'schools' => 'meal_schools',
        'other_users' => 'meal_other_users',
        'employment' => 'meal_employment',
        'finance' => 'meal_finance',
        'partnerships' => 'meal_partnerships',
        'revenue' => 'meal_revenue',
    ];

    // Each data type's own "when did this happen" column, used only for
    // the "select all matching" path below to mirror the same from/to
    // date filter the report page applied when it built the table.
    $dateColumnMap = [
        'participants' => 'entry_date',
        'teachers' => 'entry_date',
        'schools' => 'entry_date',
        'other_users' => 'entry_date',
        'employment' => 'placement_date',
        'finance' => 'date_mobilised',
        'partnerships' => 'partner_date',
        'revenue' => 'revenue_date',
    ];

    $type = trim((string)($_POST['data_type'] ?? ''));

    if (!isset($map[$type])) {
        meal_redirect('Invalid M&E data type.', 'error');
    }

    $table = $map[$type];
    $dateColumn = $dateColumnMap[$type] ?? 'created_at';

    $ventureScope = max(0, (int)($_POST['venture_id_scope'] ?? 0));
    $selectAllMatching = (int)($_POST['select_all_matching'] ?? 0) === 1;

    /*
    |----------------------------------------------------------------
    | "Select all N matching records" - deletes every row for this
    | data type that matches the venture/cohort/date filters the
    | report page was showing, across every page, not just the rows
    | that happened to be rendered (and checkable) on the current
    | page. A JOIN against ventures is needed to filter by cohort;
    | mysqli's multi-table DELETE syntax (DELETE t FROM ... JOIN ...)
    | lets that still resolve to deleting only from the data table.
    |----------------------------------------------------------------
    */
    if ($selectAllMatching) {
        $cohortScope = max(0, (int)($_POST['cohort_id_scope'] ?? 0));
        $fromScope = trim((string)($_POST['from'] ?? ''));
        $toScope = trim((string)($_POST['to'] ?? ''));

        $sql = "DELETE t FROM `{$table}` AS t JOIN ventures v ON v.id = t.venture_id WHERE 1=1";
        $types = '';
        $params = [];

        if ($ventureScope > 0) {
            $sql .= ' AND t.venture_id = ?';
            $types .= 'i';
            $params[] = $ventureScope;
        }

        if ($cohortScope > 0) {
            $sql .= ' AND v.cohort_id = ?';
            $types .= 'i';
            $params[] = $cohortScope;
        }

        if ($fromScope !== '') {
            $sql .= " AND DATE(t.`{$dateColumn}`) >= ?";
            $types .= 's';
            $params[] = $fromScope;
        }

        if ($toScope !== '') {
            $sql .= " AND DATE(t.`{$dateColumn}`) <= ?";
            $types .= 's';
            $params[] = $toScope;
        }

        try {
            if (!$conn->begin_transaction()) {
                throw new RuntimeException('Could not start bulk deletion.');
            }

            $st = $conn->prepare($sql);

            if (!$st) {
                throw new RuntimeException('Could not prepare deletion: ' . $conn->error);
            }

            if ($types !== '') {
                $st->bind_param($types, ...$params);
            }

            if (!$st->execute()) {
                throw new RuntimeException('Bulk deletion failed: ' . $st->error);
            }

            $deleted = $st->affected_rows;
            $st->close();

            if (!$conn->commit()) {
                throw new RuntimeException('Could not complete deletion.');
            }

            meal_redirect(
                'All matching records deleted (' . $deleted . ' '
                . ($deleted === 1 ? 'record' : 'records') . ').'
            );

        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }

            meal_redirect($e->getMessage(), 'error');
        }
    }

    /*
    |----------------------------------------------------------------
    | Default path: delete exactly the rows the person checked on the
    | current page (unchanged from before).
    |----------------------------------------------------------------
    */
    $raw = $_POST['record_ids'] ?? [];

    if (!is_array($raw)) {
        $raw = [$raw];
    }

    $ids = [];

    foreach ($raw as $value) {
        $id = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($id !== false) {
            $ids[(int)$id] = (int)$id;
        }
    }

    $ids = array_values($ids);

    if (!$ids) {
        meal_redirect('Select at least one record to delete.', 'error');
    }

    if (count($ids) > 500) {
        meal_redirect('Delete a maximum of 500 records at a time, or use "Select all matching records" to delete more.', 'error');
    }

    $marks = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $params = $ids;

    $sql = "DELETE FROM `{$table}` WHERE id IN ({$marks})";

    if ($ventureScope > 0) {
        $sql .= " AND venture_id=?";
        $types .= 'i';
        $params[] = $ventureScope;
    }

    try {
        if (!$conn->begin_transaction()) {
            throw new RuntimeException('Could not start bulk deletion.');
        }

        $st = $conn->prepare($sql);

        if (!$st) {
            throw new RuntimeException('Could not prepare deletion: ' . $conn->error);
        }

        $st->bind_param($types, ...$params);

        if (!$st->execute()) {
            throw new RuntimeException('Bulk deletion failed: ' . $st->error);
        }

        $deleted = $st->affected_rows;
        $st->close();

        if (!$conn->commit()) {
            throw new RuntimeException('Could not complete deletion.');
        }

        meal_redirect(
            $deleted . ' ' . ($deleted === 1 ? 'record' : 'records')
            . ' deleted successfully.'
        );

    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }

        meal_redirect($e->getMessage(), 'error');
    }
}

meal_redirect('Unknown M&E report action.', 'error');
