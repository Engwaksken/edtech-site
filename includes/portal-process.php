<?php

ob_start();

require_once __DIR__ . '/config.php';


function pp_require_mailer(): void {
    static $loaded = false;
    if ($loaded) return;
    require_once __DIR__ . '/mail-function.php';
    $loaded = true;
}

function pp_require_pdf(): void {
    static $loaded = false;
    if ($loaded) return;
    if (!class_exists('Dompdf\\Dompdf')) {
        require_once __DIR__ . '/../vendor/autoload.php';
    }
    require_once __DIR__ . '/pdf-function.php';
    $loaded = true;
}

function pp_stream_file_download(string $absolutePath, string $labelName, ?string $knownExt = null): void
{
    if (!file_exists($absolutePath) || !is_file($absolutePath)) {
        http_response_code(404);
        echo 'File not found on server.';
        exit;
    }

    $downloadName = preg_replace('/[^A-Za-z0-9_\-. ]/', '_', $labelName);

    $actualExt = strtolower($knownExt !== null && $knownExt !== ''
        ? $knownExt
        : pathinfo($absolutePath, PATHINFO_EXTENSION));
    $nameExt = strtolower(pathinfo($downloadName, PATHINFO_EXTENSION));

    if ($actualExt !== '' && $nameExt !== $actualExt) {
        $downloadName = pathinfo($downloadName, PATHINFO_FILENAME) . '.' . $actualExt;
    }

    $mime = function_exists('mime_content_type')
        ? mime_content_type($absolutePath)
        : false;
    if (!$mime) {
        $mime = 'application/octet-stream';
    }

    if (function_exists('ini_set')) {
        @ini_set('zlib.output_compression', '0');
    }
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }

    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . basename($downloadName) . '"');
    header('Content-Length: ' . filesize($absolutePath));
    header('Cache-Control: private, no-transform, no-store, must-revalidate');
    header('Pragma: public');

    readfile($absolutePath);
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();


if (empty($_SESSION['venture_id'])) {
    http_response_code(403);

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    } else {
        echo 'Unauthorized';
    }
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection error.');
}
$conn->set_charset('utf8mb4');

$venture_id = (int)$_SESSION['venture_id'];


$vc = $conn->prepare("SELECT * FROM ventures WHERE id = ? LIMIT 1");
$vc->bind_param('i', $venture_id);
$vc->execute();
$VENTURE = $vc->get_result()->fetch_assoc() ?? [];
$vc->close();



function vp_flash(string $msg, string $type = 'success'): void {
    $_SESSION['vp_flash_msg']  = $msg;
    $_SESSION['vp_flash_type'] = $type;
}

function vp_redir(string $page): void {
    header("Location: ../{$page}");
    exit;
}

function vp_owns_session(mysqli $conn, int $session_id, int $venture_id): bool {
    if ($session_id <= 0 || $venture_id <= 0) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT 1
        FROM session_ventures
        WHERE session_id = ? AND venture_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ii', $session_id, $venture_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $owns = $res && $res->num_rows > 0;
    $stmt->close();

    return $owns;
}

function upload_photo(string $file_key, string $sub_dir, string &$error = ''): string {
    if (empty($_FILES[$file_key]['name'])) return '';

    $allowed_ext  = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $max_bytes    = 2 * 1024 * 1024; // 2 MB
    $ext = strtolower(pathinfo($_FILES[$file_key]['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed_ext, true)) {
        $error = 'Photo must be JPG, PNG, WebP, or GIF.';
        return '';
    }
    if ($_FILES[$file_key]['size'] > $max_bytes) {
        $error = 'Photo must be under 2 MB.';
        return '';
    }
    if ($_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
        $error = 'Upload error code ' . $_FILES[$file_key]['error'] . '.';
        return '';
    }

    if (function_exists('upload_image')) {
        return upload_image($file_key, $sub_dir, $error) ?: '';
    }

    $dest_dir = rtrim(defined('UPLOAD_PATH') ? UPLOAD_PATH : (dirname(__DIR__) . '/uploads'), '/') . '/' . $sub_dir . '/';
    if (!is_dir($dest_dir)) mkdir($dest_dir, 0755, true);
    $filename = uniqid('vp_', true) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$file_key]['tmp_name'], $dest_dir . $filename)) {
        $error = 'Failed to save file. Check directory permissions.';
        return '';
    }
    return 'uploads/' . $sub_dir . '/' . $filename;
}


function delete_file(string $path): void {
    if ($path === '') return;
    if (function_exists('delete_image')) { delete_image($path); return; }
    $abs = rtrim(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__), '/') . '/' . ltrim($path, '/');
    if (file_exists($abs)) @unlink($abs);
}


function s3_upload(array $file, string $key): string|false {
    if (!defined('AWS_S3_BUCKET') || !defined('AWS_ACCESS_KEY') ||
        !defined('AWS_SECRET_KEY') || !defined('AWS_REGION')) return false;

    $bucket = AWS_S3_BUCKET;
    $region = AWS_REGION;
    $host   = "{$bucket}.s3.{$region}.amazonaws.com";
    $now    = new DateTime('UTC');
    $ds     = $now->format('Ymd');
    $dl     = $now->format('Ymd\THis\Z');
    $ph     = hash_file('sha256', $file['tmp_name']);
    $ct     = mime_content_type($file['tmp_name']) ?: 'application/octet-stream';
    $fsize  = filesize($file['tmp_name']);

    if ($fsize === false) return false;

    $hdrs = ['content-type' => $ct, 'host' => $host, 'x-amz-content-sha256' => $ph, 'x-amz-date' => $dl];
    ksort($hdrs);
    $ch = ''; $sh = '';
    foreach ($hdrs as $k => $v) { $ch .= "{$k}:{$v}\n"; $sh .= ($sh ? ';' : '') . $k; }

    $cr  = implode("\n", ['PUT', '/' . $key, '', $ch, $sh, $ph]);
    $cs  = "{$ds}/{$region}/s3/aws4_request";
    $sts = implode("\n", ['AWS4-HMAC-SHA256', $dl, $cs, hash('sha256', $cr)]);
    $sk  = hash_hmac('sha256', 'aws4_request',
               hash_hmac('sha256', 's3',
                   hash_hmac('sha256', $region,
                       hash_hmac('sha256', $ds, 'AWS4' . AWS_SECRET_KEY, true), true), true), true);
    $sig  = hash_hmac('sha256', $sts, $sk);
    $auth = "AWS4-HMAC-SHA256 Credential=" . AWS_ACCESS_KEY . "/{$cs},SignedHeaders={$sh},Signature={$sig}";

    $url = "https://{$host}/{$key}";

    $fh = fopen($file['tmp_name'], 'rb');
    if ($fh === false) return false;

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_PUT            => true,
        CURLOPT_INFILE         => $fh,
        CURLOPT_INFILESIZE     => $fsize,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: ' . $ct,
            'x-amz-content-sha256: ' . $ph,
            'x-amz-date: ' . $dl,
            'Authorization: ' . $auth,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 300,
    ]);

    $result   = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($curl);
    curl_close($curl);
    fclose($fh);

    if ($result === false || $httpCode < 200 || $httpCode >= 300) {
        error_log("s3_upload failed: HTTP {$httpCode} - {$curlErr}");
        return false;
    }

    return $url;
}



function pp_h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pp_column_exists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    // Only allow safe SQL identifier characters.
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? '';
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column) ?? '';

    if ($table === '' || $column === '') {
        return false;
    }

    try {
      
        $safeColumn = $conn->real_escape_string($column);

        $result = $conn->query(
            "SHOW COLUMNS FROM `{$table}` LIKE '{$safeColumn}'"
        );

        if (!$result instanceof mysqli_result) {
            return false;
        }

        $exists = $result->num_rows > 0;

        $result->free();

        return $exists;

    } catch (Throwable $e) {
        error_log(
            'Column check failed for '
            . $table
            . '.'
            . $column
            . ': '
            . $e->getMessage()
        );

        return false;
    }
}

/* ============================================================
   DOCUMENT FOLDER SUPPORT
============================================================ */

function pp_add_column_if_missing(
    mysqli $conn,
    string $table,
    string $column,
    string $definition
): void {
    if (pp_column_exists($conn, $table, $column)) {
        return;
    }

    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? '';
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column) ?? '';

    if ($table === '' || $column === '') {
        return;
    }

    try {
        $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    } catch (Throwable $e) {
        // 1060 = duplicate column; another request may have created it.
        if ((int)$e->getCode() !== 1060) {
            error_log("Failed to add {$table}.{$column}: " . $e->getMessage());
        }
    }
}


function pp_ensure_document_folders(mysqli $conn): void {
    $conn->query("
        CREATE TABLE IF NOT EXISTS venture_document_folders (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            venture_id  INT UNSIGNED NOT NULL,
            parent_id   INT UNSIGNED NULL,
            folder_name VARCHAR(255) NOT NULL,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_venture (venture_id),
            KEY idx_parent (parent_id),
            KEY idx_venture_parent (venture_id, parent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    pp_add_column_if_missing(
        $conn,
        'venture_documents',
        'folder_id',
        'INT UNSIGNED NULL AFTER `venture_id`'
    );

    pp_add_column_if_missing(
        $conn,
        'venture_documents',
        'other_category_name',
        'VARCHAR(120) NULL AFTER `category`'
    );

    try {
        $indexes = $conn->query(
            "SHOW INDEX FROM `venture_documents` WHERE Key_name = 'idx_folder'"
        );
        $hasIndex = $indexes instanceof mysqli_result && $indexes->num_rows > 0;
        if ($indexes instanceof mysqli_result) {
            $indexes->free();
        }

        if (!$hasIndex) {
            $conn->query(
                "ALTER TABLE `venture_documents` ADD KEY `idx_folder` (`folder_id`)"
            );
        }
    } catch (Throwable $e) {
        // 1061 = duplicate key name.
        if ((int)$e->getCode() !== 1061) {
            error_log(
                'Failed to ensure venture_documents.idx_folder: ' . $e->getMessage()
            );
        }
    }
}


function pp_folder_exists(
    mysqli $conn,
    int $venture_id,
    int $folder_id
): bool {
    if ($venture_id <= 0 || $folder_id <= 0) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT id
        FROM venture_document_folders
        WHERE id = ? AND venture_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ii', $folder_id, $venture_id);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}


function pp_folder_parent_id(
    mysqli $conn,
    int $venture_id,
    int $folder_id
): ?int {
    if (!pp_folder_exists($conn, $venture_id, $folder_id)) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT parent_id
        FROM venture_document_folders
        WHERE id = ? AND venture_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ii', $folder_id, $venture_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $parent = (int)($row['parent_id'] ?? 0);
    return $parent > 0 ? $parent : null;
}


function pp_folder_breadcrumb(
    mysqli $conn,
    int $venture_id,
    ?int $folder_id
): array {
    $trail = [];
    $guard = 0;
    $seen = [];

    while ($folder_id !== null && $folder_id > 0 && $guard < 50) {
        if (isset($seen[$folder_id])) {
            break;
        }
        $seen[$folder_id] = true;

        $stmt = $conn->prepare("
            SELECT id, parent_id, folder_name
            FROM venture_document_folders
            WHERE id = ? AND venture_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            break;
        }

        $stmt->bind_param('ii', $folder_id, $venture_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            break;
        }

        array_unshift($trail, $row);
        $parent = (int)($row['parent_id'] ?? 0);
        $folder_id = $parent > 0 ? $parent : null;
        $guard++;
    }

    return $trail;
}


function pp_folder_is_descendant(
    mysqli $conn,
    int $venture_id,
    int $folder_id,
    int $possible_ancestor_id
): bool {
    $current = $folder_id;
    $guard = 0;
    $seen = [];

    while ($current > 0 && $guard < 50) {
        if ($current === $possible_ancestor_id) {
            return true;
        }

        if (isset($seen[$current])) {
            break;
        }
        $seen[$current] = true;

        $stmt = $conn->prepare("
            SELECT parent_id
            FROM venture_document_folders
            WHERE id = ? AND venture_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            break;
        }

        $stmt->bind_param('ii', $current, $venture_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            break;
        }

        $parent = (int)($row['parent_id'] ?? 0);
        if ($parent <= 0) {
            break;
        }

        $current = $parent;
        $guard++;
    }

    return false;
}


function pp_resolve_folder_path(
    mysqli $conn,
    int $venture_id,
    ?int $root_folder_id,
    string $path
): ?int {
    $parent = ($root_folder_id !== null && $root_folder_id > 0)
        ? $root_folder_id
        : null;

    $normalised = str_replace('\\', '/', $path);
    $segments = array_values(array_filter(
        array_map('trim', explode('/', $normalised)),
        static fn($s) => $s !== '' && $s !== '.' && $s !== '..'
    ));

    foreach ($segments as $segment) {
        $segment = preg_replace('/[\x00-\x1F\x7F]/u', '', $segment) ?? '';
        $segment = trim(mb_substr($segment, 0, 255));

        if ($segment === '') {
            continue;
        }

        if ($parent === null) {
            $find = $conn->prepare("
                SELECT id
                FROM venture_document_folders
                WHERE venture_id = ?
                  AND (parent_id IS NULL OR parent_id = 0)
                  AND folder_name = ?
                LIMIT 1
            ");
            $find->bind_param('is', $venture_id, $segment);
        } else {
            $find = $conn->prepare("
                SELECT id
                FROM venture_document_folders
                WHERE venture_id = ?
                  AND parent_id = ?
                  AND folder_name = ?
                LIMIT 1
            ");
            $find->bind_param('iis', $venture_id, $parent, $segment);
        }

        $find->execute();
        $row = $find->get_result()->fetch_assoc();
        $find->close();

        if ($row) {
            $parent = (int)$row['id'];
            continue;
        }

        if ($parent === null) {
            $ins = $conn->prepare("
                INSERT INTO venture_document_folders
                    (venture_id, parent_id, folder_name)
                VALUES (?, NULL, ?)
            ");
            $ins->bind_param('is', $venture_id, $segment);
        } else {
            $ins = $conn->prepare("
                INSERT INTO venture_document_folders
                    (venture_id, parent_id, folder_name)
                VALUES (?, ?, ?)
            ");
            $ins->bind_param('iis', $venture_id, $parent, $segment);
        }

        if (!$ins->execute()) {
            $error = $ins->error;
            $ins->close();
            throw new RuntimeException('Failed to create folder: ' . $error);
        }

        $parent = (int)$conn->insert_id;
        $ins->close();
    }

    return $parent;
}


function pp_document_folder_redirect(?int $folder_id = null): void {
    $page = 'documents.php';
    if ($folder_id !== null && $folder_id > 0) {
        $page .= '?folder_id=' . urlencode((string)$folder_id);
    }
    vp_redir($page);
}

function pp_document_is_json_request(): bool
{
    $requestedWith = strtolower(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
}

function pp_document_json_response(bool $success, string $message = '', array $extra = []): void
{
    if (!$success) http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function pp_document_move_finish(bool $success, string $message, ?int $returnFolder = null): void
{
    if (pp_document_is_json_request()) {
        pp_document_json_response($success, $message);
    }
    vp_flash($message, $success ? 'success' : 'error');
    pp_document_folder_redirect($returnFolder);
}


function pp_clean_folder_name(string $name): string {
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', trim($name)) ?? '';
    return trim(mb_substr($name, 0, 255));
}


function pp_folder_name_exists(
    mysqli $conn,
    int $venture_id,
    ?int $parent_id,
    string $folder_name,
    int $exclude_id = 0
): bool {
    if ($parent_id !== null && $parent_id > 0) {
        $sql = "
            SELECT id
            FROM venture_document_folders
            WHERE venture_id = ?
              AND parent_id = ?
              AND folder_name = ?
        ";
        if ($exclude_id > 0) {
            $sql .= " AND id <> ?";
        }
        $sql .= " LIMIT 1";

        $stmt = $conn->prepare($sql);
        if ($exclude_id > 0) {
            $stmt->bind_param('iisi', $venture_id, $parent_id, $folder_name, $exclude_id);
        } else {
            $stmt->bind_param('iis', $venture_id, $parent_id, $folder_name);
        }
    } else {
        $sql = "
            SELECT id
            FROM venture_document_folders
            WHERE venture_id = ?
              AND (parent_id IS NULL OR parent_id = 0)
              AND folder_name = ?
        ";
        if ($exclude_id > 0) {
            $sql .= " AND id <> ?";
        }
        $sql .= " LIMIT 1";

        $stmt = $conn->prepare($sql);
        if ($exclude_id > 0) {
            $stmt->bind_param('isi', $venture_id, $folder_name, $exclude_id);
        } else {
            $stmt->bind_param('is', $venture_id, $folder_name);
        }
    }

    if (!$stmt) {
        return false;
    }

    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}


function pp_normalize_mysql_datetime(?string $value): ?string {
    $value = trim((string)($value ?? ''));

    if ($value === '') {
        return null;
    }

    try {
        $dt = new DateTime($value);
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}


function pp_admin_url(string $path = 'admin/messages.php'): string {
    if (defined('SITE_URL') && trim((string)SITE_URL) !== '') {
        $base = rtrim((string)SITE_URL, '/');
    } else {
        $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443);
        $scheme = $https ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base   = $scheme . '://' . $host;
    }

    return $base . '/' . ltrim($path, '/');
}

function pp_get_admin_recipients(mysqli $conn, int $admin_id = 0): array {
    $emails = [];

    if ($admin_id > 0) {
        $stmt = $conn->prepare("
            SELECT full_name, email
            FROM admin_users
            WHERE id = ?
              AND email <> ''
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('i', $admin_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                if (filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                    $emails[$row['email']] = $row['full_name'] ?: 'Admin';
                }
            }
            $stmt->close();
        }

        return $emails;
    }

    $sql = "
        SELECT full_name, email
        FROM admin_users
        WHERE email <> ''
          AND (
                status = 'active'
             OR status = 'Active'
             OR status = '1'
             OR status = 1
          )
        ORDER BY id ASC
    ";

    $res = $conn->query($sql);
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) {
            if (filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                $emails[$row['email']] = $row['full_name'] ?: 'Admin';
            }
        }
    }

    return $emails;
}

function pp_get_mentor_recipient(mysqli $conn, int $mentor_id): array {
    if ($mentor_id <= 0) return [];

    $emails = [];
    $stmt = $conn->prepare("
        SELECT full_name, email
        FROM mentors
        WHERE id = ?
          AND email <> ''
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param('i', $mentor_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if (filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                $emails[$row['email']] = $row['full_name'] ?: 'Mentor';
            }
        }
        $stmt->close();
    }

    return $emails;
}

function pp_get_session_mentor_ids_for_notify(mysqli $conn, int $session_id, int $legacy_mentor_id): array {
    $ids = [];

    $stmt = $conn->prepare("
        SELECT mentor_id
        FROM session_mentors
        WHERE session_id = ?
          AND invite_status = 'accepted'
    ");

    if ($stmt) {
        $stmt->bind_param('i', $session_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $ids[] = (int)$row['mentor_id'];
        }
        $stmt->close();
    }

    if (!$ids && $legacy_mentor_id > 0) {
        $ids[] = $legacy_mentor_id;
    }

    return array_values(array_unique(array_filter($ids, fn($id) => $id > 0)));
}


function pp_notify_message_receiver(
    mysqli $conn,
    string $recipient_type,
    int $recipient_id,
    string $subject,
    string $message_body,
    string $sender_name,
    string $venture_name = '',
    string $thread_id = ''
): void {
    pp_require_mailer();

    if (!function_exists('sendEmail')) {
        error_log('pp_notify_message_receiver: sendEmail() not found. Check includes/mail-function.php.');
        return;
    }

    $recipient_type = strtolower(trim($recipient_type));
    $recipients = [];

    if ($recipient_type === 'mentor') {
        $recipients = pp_get_mentor_recipient($conn, $recipient_id);
    } else {
        $recipients = pp_get_admin_recipients($conn, $recipient_id);
    }

    if (empty($recipients)) {
        error_log("pp_notify_message_receiver: no valid recipients for {$recipient_type}:{$recipient_id}");
        return;
    }

    $reply_url = pp_admin_url('admin/messages.php' . ($thread_id !== '' ? '?thread=' . urlencode($thread_id) : ''));
    $safe_subject = $subject !== '' ? $subject : 'New message notification';
    $preview = mb_substr(strip_tags($message_body), 0, 350);

    $content = "
        <h3>New message received</h3>
        <p>Hello,</p>
        <p>You have received a new message in the admin dashboard.</p>

        <div class='info-box'>
            <p><strong>From:</strong> " . pp_h($sender_name) . "</p>
            " . ($venture_name !== '' ? "<p><strong>Venture:</strong> " . pp_h($venture_name) . "</p>" : "") . "
            <p><strong>Subject:</strong> " . pp_h($safe_subject) . "</p>
        </div>

        <p><strong>Message preview:</strong></p>
        <div class='info-box'>" . nl2br(pp_h($preview)) . "</div>

        <p>Please login to the admin dashboard to read the full message and reply.</p>

        <p>
            <a class='btn' href='" . pp_h($reply_url) . "' target='_blank' rel='noopener'>
                Login to Admin Dashboard & Reply
            </a>
        </p>
    ";

    $html = function_exists('email_wrapper') ? email_wrapper($content) : $content;
    $email_subject = 'New message: ' . $safe_subject;

    foreach ($recipients as $email => $name) {
        $result = sendEmail($email, $email_subject, $html);
        if ($result !== true) {
            error_log('pp_notify_message_receiver: failed for ' . $email . ' - ' . (string)$result);
        }
    }
}

function pp_notify_mentor_session_update(
    mysqli $conn,
    int $mentor_id,
    int $session_id,
    string $event,
    string $session_title,
    ?string $scheduled_at = null,
    string $venture_name = '',
    string $extra_text = ''
): void {
    if ($mentor_id <= 0) return;

    pp_require_mailer();

    if (!function_exists('sendEmail')) {
        error_log('pp_notify_mentor_session_update: sendEmail() not available.');
        return;
    }

    $stmt = $conn->prepare("SELECT full_name, email FROM mentors WHERE id = ? AND email <> '' LIMIT 1");
    if (!$stmt) return;
    $stmt->bind_param('i', $mentor_id);
    $stmt->execute();
    $mentor = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$mentor || !filter_var($mentor['email'], FILTER_VALIDATE_EMAIL)) {
        error_log("pp_notify_mentor_session_update: no valid email for mentor {$mentor_id}");
        return;
    }

    $dt_str = $scheduled_at ? date('l, F j, Y \a\t g:i A', strtotime($scheduled_at)) : 'TBD';
    $mentor_name = $mentor['full_name'] ?: 'Mentor';

    $headline_map = [
        'cancelled'          => 'Session Cancelled',
        'completed'          => 'Session Marked Completed',
        'confirmed'          => 'Session Confirmed',
        'comment'            => 'New Comment on Your Session',
        'report'             => 'New Report Uploaded',
        'feedback_submitted' => 'Venture Session Report Submitted',
    ];
    $headline = $headline_map[$event] ?? 'Session Update';

    $body_map = [
        'cancelled'          => "The venture has cancelled an upcoming mentoring session.",
        'completed'          => "A mentoring session has been marked as completed.",
        'confirmed'          => "A mentoring session has been confirmed.",
        'comment'            => "The venture has left a new comment on a mentoring session.",
        'report'             => "The venture has uploaded a report related to a mentoring session.",
        'feedback_submitted' => "The venture has submitted their Venture Mentorship Session Report for this session.",
    ];
    $intro = $body_map[$event] ?? '';

    $content = "
        <h3>{$headline}</h3>
        <p>Hi " . pp_h($mentor_name) . ",</p>
        <p>{$intro}</p>

        <div class='info-box'>
            <p><strong>Session:</strong> " . pp_h($session_title) . "</p>
            " . ($venture_name !== '' ? "<p><strong>Venture:</strong> " . pp_h($venture_name) . "</p>" : "") . "
            <p><strong>Date:</strong> " . pp_h($dt_str) . "</p>
        </div>
    ";

    if ($extra_text !== '') {
        $label = $event === 'cancelled' ? 'Reason given' : 'Message';
        $content .= "
            <p><strong>{$label}:</strong></p>
            <div class='info-box'>" . nl2br(pp_h(mb_substr($extra_text, 0, 500))) . "</div>
        ";
    }

    $content .= "
        <p>
            <a class='btn' href='" . pp_h(pp_admin_url('admin/mentor-sessions.php')) . "' target='_blank' rel='noopener'>
                View Session Details
            </a>
        </p>
    ";

    $html = function_exists('email_wrapper') ? email_wrapper($content) : $content;

    $result = sendEmail($mentor['email'], $headline . ': ' . $session_title, $html);
    if ($result !== true) {
        error_log('pp_notify_mentor_session_update: failed for ' . $mentor['email'] . ' - ' . (string)$result);
    }
}

function pp_notify_session_mentors(
    mysqli $conn,
    int $session_id,
    int $legacy_mentor_id,
    string $event,
    string $session_title,
    ?string $scheduled_at = null,
    string $venture_name = '',
    string $extra_text = ''
): void {
    $mentor_ids = pp_get_session_mentor_ids_for_notify($conn, $session_id, $legacy_mentor_id);

    foreach ($mentor_ids as $mid) {
        pp_notify_mentor_session_update(
            $conn,
            $mid,
            $session_id,
            $event,
            $session_title,
            $scheduled_at,
            $venture_name,
            $extra_text
        );
    }
}


/**
 * Notify the programme admin team that a venture has submitted a
 * Venture Mentorship Session Report for review (session_feedback_reports,
 * status = 'submitted'). Mirrors pp_notify_message_receiver()'s recipient
 * handling but with copy specific to the session-report review queue.
 */
function pp_notify_admins_feedback_submitted(
    mysqli $conn,
    int $session_id,
    string $session_title,
    string $venture_name
): void {
    pp_require_mailer();

    if (!function_exists('sendEmail')) {
        error_log('pp_notify_admins_feedback_submitted: sendEmail() not available.');
        return;
    }

    $recipients = pp_get_admin_recipients($conn, 0);
    if (empty($recipients)) {
        error_log('pp_notify_admins_feedback_submitted: no admin recipients configured.');
        return;
    }

    $review_url = pp_admin_url('admin/mentor-sessions.php?session_id=' . $session_id . '#feedback');

    $content = "
        <h3>Venture Mentorship Session Report submitted for review</h3>
        <p>Hello,</p>
        <p>A venture has submitted a Venture Mentorship Session Report and it is now awaiting your review.</p>

        <div class='info-box'>
            <p><strong>Venture:</strong> " . pp_h($venture_name) . "</p>
            <p><strong>Session:</strong> " . pp_h($session_title) . "</p>
        </div>

        <p>
            <a class='btn' href='" . pp_h($review_url) . "' target='_blank' rel='noopener'>
                Review Session Report
            </a>
        </p>
    ";

    $html = function_exists('email_wrapper') ? email_wrapper($content) : $content;
    $subject = 'Session report submitted: ' . $session_title;

    foreach ($recipients as $email => $name) {
        $result = sendEmail($email, $subject, $html);
        if ($result !== true) {
            error_log('pp_notify_admins_feedback_submitted: failed for ' . $email . ' - ' . (string)$result);
        }
    }
}


/**
 * Ensure the session_feedback_reports table exists. Mirrors the lazy
 * CREATE TABLE IF NOT EXISTS pattern already used for session_comments /
 * the other lazy-created session tables in this file, so no separate migration step is needed.
 * One structured session report per session (UNIQUE KEY on session_id) -
 * this is the digital equivalent of the paper "Venture Mentorship
 * Feedback Report" template.
 */
function fb_ensure_table(mysqli $conn): void {
    $conn->query("
        CREATE TABLE IF NOT EXISTS session_feedback_reports (
            id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id             INT UNSIGNED NOT NULL,
            venture_id             INT UNSIGNED NOT NULL,
            venture_name           VARCHAR(255) NOT NULL DEFAULT '',
            founders_present       VARCHAR(255) NOT NULL DEFAULT '',
            mentor_name            VARCHAR(255) NOT NULL DEFAULT '',
            session_date           DATE NULL,
            session_duration       VARCHAR(50)  NOT NULL DEFAULT '',
            mode_of_engagement     VARCHAR(20)  NOT NULL DEFAULT '',
            objectives             TEXT,
            discussion_summary     TEXT,
            mentor_assessment      TEXT,
            value_rating           VARCHAR(40)  NOT NULL DEFAULT '',
            valuable_insight       TEXT,
            recommendations        TEXT,
            action_plan            TEXT,
            progress_rating        VARCHAR(40)  NOT NULL DEFAULT '',
            progress_explain       TEXT,
            challenges             TEXT,
            future_support         TEXT,
            satisfaction_rating    TINYINT UNSIGNED NULL,
            satisfaction_comments  VARCHAR(500) NOT NULL DEFAULT '',
            recommend_mentor       VARCHAR(20)  NOT NULL DEFAULT '',
            feedback_for_admin     TEXT,
            mentor_could_improve   TEXT,
            status                 ENUM('draft','submitted','reviewed') NOT NULL DEFAULT 'draft',
            submitted_at           TIMESTAMP NULL,
            reviewed_at            TIMESTAMP NULL,
            reviewed_by            VARCHAR(120) NOT NULL DEFAULT '',
            review_notes           TEXT,
            created_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_session (session_id),
            KEY idx_venture (venture_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Zip parallel POST arrays (one per column of a repeatable template
 * section, e.g. discussion_area[] / discussion_helpful[] /
 * discussion_comments[]) into a list of associative rows, trimming values
 * and dropping rows that are entirely empty.
 */
function fb_build_rows(array $columns): array {
    $length = 0;
    foreach ($columns as $col) {
        if (is_array($col)) {
            $length = max($length, count($col));
        }
    }

    $rows = [];
    for ($i = 0; $i < $length; $i++) {
        $row = [];
        $has_value = false;
        foreach ($columns as $key => $col) {
            $val = trim((string)($col[$i] ?? ''));
            $row[$key] = $val;
            if ($val !== '') {
                $has_value = true;
            }
        }
        if ($has_value) {
            $rows[] = $row;
        }
    }

    return $rows;
}



/* ============================================================
   VENTURE M&E ADMIN CONTROLS
============================================================ */

function pp_meal_access(
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
            SELECT can_edit, can_delete
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
            'portal-process M&E access check failed: '
            . $e->getMessage()
        );

        return $defaults;
    }
}


function pp_meal_action_tab(
    string $action,
    array $request
): string {
    if (
        $action === 'upload_meal_csv'
        || $action === 'delete_meal_row'
    ) {
        return trim(
            (string)(
                $request['type']
                ?? 'participants'
            )
        );
    }

    return match ($action) {
        'add_meal_beneficiary',
        'edit_meal_beneficiary'
            => 'participants',

        'add_meal_teacher'
            => 'teachers',

        'add_meal_school'
            => 'schools',

        'add_meal_other_user'
            => 'other_users',

        'add_meal_employment'
            => 'employment',

        'add_meal_finance'
            => 'finance',

        'add_meal_partnership'
            => 'partnerships',

        'add_meal_revenue'
            => 'revenue',

        'upload_meal_supporting_document'
            => 'documents',

        'edit_meal_record'
            => trim(
                (string)(
                    $request['type']
                    ?? 'participants'
                )
            ),

        'delete_meal_supporting_document'
            => 'documents',

        default
            => 'participants',
    };
}


/* ============================================================
   VENTURE TEAM LEGACY FK REPAIR
============================================================ */

function pp_repair_venture_team_foreign_key(
    mysqli $conn
): void {
    /*
     * Shared-hosting/cPanel database users may not be allowed to query
     * INFORMATION_SCHEMA directly.
     *
     * Use SHOW CREATE TABLE venture_team instead.
     */

    try {
        $result =
            $conn->query(
                "SHOW CREATE TABLE venture_team"
            );

        if (
            !($result instanceof mysqli_result)
        ) {
            return;
        }

        $row =
            $result->fetch_assoc();

        if (!$row) {
            return;
        }

        $createSql = '';

        foreach (
            $row
            as $columnName => $value
        ) {
            if (
                stripos(
                    (string)$columnName,
                    'Create Table'
                ) !== false
            ) {
                $createSql =
                    (string)$value;

                break;
            }
        }

        if ($createSql === '') {
            return;
        }

        /*
         * Detect only a legacy venture_team.venture_id FK pointing to
         * startups.id. Other foreign keys are left untouched.
         */
        $legacyPattern =
            '/CONSTRAINT\s+`([^`]+)`\s+FOREIGN\s+KEY\s*\(\s*`venture_id`\s*\)\s+REFERENCES\s+`startups`\s*\(\s*`id`\s*\)/i';

        if (
            !preg_match_all(
                $legacyPattern,
                $createSql,
                $matches
            )
        ) {
            return;
        }

        foreach (
            $matches[1]
            as $constraintName
        ) {
            $constraintName =
                trim(
                    (string)$constraintName
                );

            if ($constraintName === '') {
                continue;
            }

            $safeName =
                str_replace(
                    '`',
                    '``',
                    $constraintName
                );

            $conn->query(
                "ALTER TABLE venture_team "
                . "DROP FOREIGN KEY `{$safeName}`"
            );
        }

    } catch (Throwable $e) {
        error_log(
            'venture_team legacy FK repair failed: '
            . $e->getMessage()
        );
    }
}


/**
 * M&E (Monitoring, Evaluation & Learning) data import/entry actions.
 * Requires the following MySQL tables (see meal-schema.sql).
 */

function meal_owns(mysqli $conn, string $table, int $id, int $vid): bool {
    $safe_table = preg_replace('/[^a-z_]/', '', $table);
    $stmt = $conn->prepare("SELECT id FROM `{$safe_table}` WHERE id = ? AND venture_id = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('ii', $id, $vid);
    $stmt->execute();
    $res = $stmt->get_result();
    $owns = $res && $res->num_rows > 0;
    $stmt->close();
    return $owns;
}

/**
 * FIX: meal_partnerships and meal_revenue (and meal_finance /
 * meal_employment) were never created by any lazy CREATE TABLE, unlike
 * meal_teachers / meal_schools / meal_other_users. If a venture's database
 * was provisioned before Partnerships/Revenue were added to the M&E
 * tracker and meal-schema.sql was never (re-)run for these tables, every
 * query against them - including the plain SELECT COUNT(*) that
 * meal-data.php runs unconditionally for every tab on every page load -
 * fails, which is a fatal PHP error (calling ->fetch_row() on the `false`
 * that mysqli_query() returns for an unknown table). That single fatal
 * error is enough to blank the whole M&E page, including its modals.
 *
 * This function guarantees every M&E table meal-data.php / this file
 * touch actually exists, and is called once near the top of this file so
 * it runs before any M&E action (add/edit/delete/list) can hit a missing
 * table.
 */
function meal_ensure_all_meal_tables(mysqli $conn): void {
    $conn->query("
        CREATE TABLE IF NOT EXISTS meal_teachers (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            venture_id     INT UNSIGNED NOT NULL,
            entry_date     DATE NOT NULL,
            teacher_name   VARCHAR(255) NOT NULL DEFAULT '',
            gender         VARCHAR(20)  NOT NULL DEFAULT '',
            age_category   VARCHAR(40)  NOT NULL DEFAULT '',
            refugee_status VARCHAR(10)  NOT NULL DEFAULT 'No',
            host_community VARCHAR(10)  NOT NULL DEFAULT 'No',
            location_type  VARCHAR(20)  NOT NULL DEFAULT '',
            pwd_status     VARCHAR(10)  NOT NULL DEFAULT 'No',
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_venture (venture_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS meal_schools (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            venture_id     INT UNSIGNED NOT NULL,
            entry_date     DATE NOT NULL,
            school_name    VARCHAR(255) NOT NULL DEFAULT '',
            location_type  VARCHAR(20)  NOT NULL DEFAULT '',
            ownership_type VARCHAR(30)  NOT NULL DEFAULT '',
            school_level   VARCHAR(20)  NOT NULL DEFAULT '',
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_venture (venture_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS meal_other_users (
            id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            venture_id         INT UNSIGNED NOT NULL,
            entry_date         DATE NOT NULL,
            full_name          VARCHAR(255) NOT NULL DEFAULT '',
            gender             VARCHAR(20)  NOT NULL DEFAULT '',
            age_category       VARCHAR(40)  NOT NULL DEFAULT '',
            refugee_status     VARCHAR(10)  NOT NULL DEFAULT 'No',
            host_community     VARCHAR(10)  NOT NULL DEFAULT 'No',
            location_type      VARCHAR(20)  NOT NULL DEFAULT '',
            pwd_status         VARCHAR(10)  NOT NULL DEFAULT 'No',
            education_level    VARCHAR(20)  NOT NULL DEFAULT '',
            occupation_status  VARCHAR(255) NOT NULL DEFAULT '',
            created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_venture (venture_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS meal_employment (
            id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            venture_id       INT UNSIGNED NOT NULL,
            placement_date   DATE NOT NULL,
            full_name        VARCHAR(255) NOT NULL DEFAULT '',
            gender           VARCHAR(20)  NOT NULL DEFAULT '',
            age_category     VARCHAR(40)  NOT NULL DEFAULT '',
            pwd_status       VARCHAR(10)  NOT NULL DEFAULT 'No',
            employment_type  VARCHAR(30)  NOT NULL DEFAULT '',
            job_title        VARCHAR(255) NOT NULL DEFAULT '',
            status           VARCHAR(20)  NOT NULL DEFAULT 'Active',
            created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_venture (venture_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS meal_finance (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            venture_id      INT UNSIGNED NOT NULL,
            date_mobilised  DATE NOT NULL,
            finance_usd     DECIMAL(14,2) NOT NULL DEFAULT 0,
            funding_form    VARCHAR(40)  NOT NULL DEFAULT '',
            funding_source  VARCHAR(255) NOT NULL DEFAULT '',
            created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_venture (venture_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // meal_partnerships and meal_revenue were the two tables missing
    // entirely - this is the direct fix for "Partnerships and Revenue
    // are not opening / not adding new records".
    $conn->query("
        CREATE TABLE IF NOT EXISTS meal_partnerships (
            id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            venture_id          INT UNSIGNED NOT NULL,
            partner_date        DATE NOT NULL,
            partner_name        VARCHAR(255) NOT NULL DEFAULT '',
            partnership_type    VARCHAR(30)  NOT NULL DEFAULT '',
            partnership_status  VARCHAR(20)  NOT NULL DEFAULT 'Active',
            created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_venture (venture_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS meal_revenue (
            id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            venture_id          INT UNSIGNED NOT NULL,
            revenue_date        DATE NOT NULL,
            gross_revenue_ugx   DECIMAL(16,2) NOT NULL DEFAULT 0,
            revenue_stream      VARCHAR(255) NOT NULL DEFAULT '',
            created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_venture (venture_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS meal_supporting_documents (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            venture_id     INT UNSIGNED NOT NULL,
            category       VARCHAR(40) NOT NULL,
            document_name  VARCHAR(255) NOT NULL,
            description    TEXT NULL,
            original_name  VARCHAR(255) NOT NULL,
            file_path      VARCHAR(500) NOT NULL,
            file_ext       VARCHAR(20) NOT NULL DEFAULT '',
            file_size      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            uploaded_by    VARCHAR(180) NOT NULL DEFAULT '',
            created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                           ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_meal_doc_venture (venture_id),
            KEY idx_meal_doc_category (category),
            KEY idx_meal_doc_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

}

/**
 * @deprecated kept as a thin alias so any other call sites that still
 * reference the old, narrower function name keep working. New code
 * should call meal_ensure_all_meal_tables() directly.
 */
function meal_ensure_new_tables(mysqli $conn): void {
    meal_ensure_all_meal_tables($conn);
}


// --------------------------------------------------------------
//  ROUTE
// --------------------------------------------------------------
$action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));

// Keep old and new document UI action names compatible.
$ppDocumentActionAliases = [
    'create_folder'          => 'create_document_folder',
    'add_folder'             => 'create_document_folder',
    'new_folder'             => 'create_document_folder',
    'document_create_folder' => 'create_document_folder',

    'rename_folder'          => 'rename_document_folder',
    'edit_folder'            => 'rename_document_folder',
    'document_rename_folder' => 'rename_document_folder',

    'move_folder'            => 'move_document_folder',
    'document_move_folder'   => 'move_document_folder',

    'delete_folder'          => 'delete_document_folder',
    'remove_folder'          => 'delete_document_folder',
    'document_delete_folder' => 'delete_document_folder',

    'move_file'              => 'move_document',
    'move_doc'               => 'move_document',
    'move_document_file'     => 'move_document',

    // Current Documents UI uses this AJAX action name.
    // Route it through the existing, fully validated upload_doc handler.
    'upload_doc_ajax'        => 'upload_doc',
    'upload_document_ajax'   => 'upload_doc',
    'ajax_upload_doc'        => 'upload_doc',
];

if (isset($ppDocumentActionAliases[$action])) {
    $action = $ppDocumentActionAliases[$action];
}

// Make the folder schema available before any document action runs.
pp_ensure_document_folders($conn);

if ($action === 'list_folders') {
    $stmt = $conn->prepare("
        SELECT id, parent_id, folder_name
        FROM venture_document_folders
        WHERE venture_id = ?
        ORDER BY folder_name ASC, id ASC
    ");
    if (!$stmt) {
        pp_document_json_response(false, 'Could not load your folders.');
    }
    $stmt->bind_param('i', $venture_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $childrenByParent = [];
    while ($row = $result->fetch_assoc()) {
        $parentId = (int)($row['parent_id'] ?? 0);
        $childrenByParent[$parentId][] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['folder_name'],
        ];
    }
    $stmt->close();

    $flatFolders = [];
    $visitedFolders = [];
    $appendChildren = function (int $parentId, int $depth) use (&$appendChildren, &$flatFolders, &$visitedFolders, &$childrenByParent): void {
        foreach ($childrenByParent[$parentId] ?? [] as $folder) {
            $folderId = (int)$folder['id'];
            if (isset($visitedFolders[$folderId])) continue;
            $visitedFolders[$folderId] = true;
            $flatFolders[] = [
                'id' => $folderId,
                'name' => $folder['name'],
                'depth' => $depth,
            ];
            $appendChildren($folderId, $depth + 1);
        }
    };
    $appendChildren(0, 0);

    pp_document_json_response(true, '', ['folders' => $flatFolders]);
}


/*
|--------------------------------------------------------------------------
| Guarantee every M&E table exists before any M&E action, list query, or
| permission check touches them. See meal_ensure_all_meal_tables() above
| for why this matters (missing meal_partnerships / meal_revenue tables
| were causing fatal errors that broke the whole M&E page, not just those
| two tabs).
|--------------------------------------------------------------------------
*/
meal_ensure_all_meal_tables($conn);


/*
|--------------------------------------------------------------------------
| Direct-call M&E permission enforcement
|--------------------------------------------------------------------------
| process-meal-data.php performs the first check. This second check
| prevents users bypassing the controls by posting directly to
| portal-process.php.
|--------------------------------------------------------------------------
*/

$ppMealWriteActions = [
    'upload_meal_csv',
    'add_meal_beneficiary',
    'edit_meal_beneficiary',
    'add_meal_teacher',
    'add_meal_school',
    'add_meal_other_user',
    'add_meal_employment',
    'add_meal_finance',
    'add_meal_partnership',
    'add_meal_revenue',
    'upload_meal_supporting_document',
    'edit_meal_record',
];

if (
    in_array(
        $action,
        $ppMealWriteActions,
        true
    )
) {
    $access =
        pp_meal_access(
            $conn,
            $venture_id
        );

    if (!$access['can_edit']) {
        $tab =
            pp_meal_action_tab(
                $action,
                $_POST
            );

        vp_flash(
            'Editing, adding and importing M&E data is currently blocked by the programme team.',
            'error'
        );

        vp_redir(
            'meal-data.php?tab='
            . urlencode($tab)
        );
    }
}


if (
    in_array(
        $action,
        [
            'delete_meal_row',
            'delete_meal_supporting_document',
        ],
        true
    )
) {
    $access =
        pp_meal_access(
            $conn,
            $venture_id
        );

    if (!$access['can_delete']) {
        $tab =
            pp_meal_action_tab(
                $action,
                $_POST
            );

        vp_flash(
            'Deleting M&E data is currently blocked by the programme team.',
            'error'
        );

        vp_redir(
            'meal-data.php?tab='
            . urlencode($tab)
        );
    }
}


// --------------------------------------------------------------
//  GET: DOWNLOAD DOCUMENT
// --------------------------------------------------------------
if ($action === 'download_doc') {
    $id = (int)($_GET['id'] ?? 0);

    $stmt = $conn->prepare("
        SELECT *
        FROM venture_documents
        WHERE id = ? AND venture_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $id, $venture_id);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$doc) {
        http_response_code(404);
        echo 'Document not found.';
        exit;
    }

    if (($doc['storage_type'] ?? '') === 's3' && !empty($doc['s3_url'])) {
        header('Location: ' . $doc['s3_url']);
        exit;
    }

    $filePath = trim((string)($doc['file_path'] ?? ''));

    if ($filePath === '') {
        http_response_code(404);
        echo 'File path is empty.';
        exit;
    }

    $filePath = str_replace('\\', '/', $filePath);
    $filePath = preg_replace('#^(\./|\.\./)+#', '', $filePath);
    $filePath = ltrim($filePath, '/');

    $possiblePaths = [
        rtrim(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__), '/') . '/' . $filePath,
        rtrim(dirname(__DIR__), '/') . '/' . $filePath,
        rtrim(dirname(__DIR__, 2), '/') . '/' . $filePath,
        rtrim(defined('UPLOAD_PATH') ? UPLOAD_PATH : dirname(__DIR__) . '/uploads', '/') . '/' . basename($filePath),
        rtrim(defined('UPLOAD_PATH') ? UPLOAD_PATH : dirname(__DIR__) . '/uploads', '/') . '/ventures/docs/' . basename($filePath),
    ];

    $realFile = '';

    foreach ($possiblePaths as $path) {
        if ($path && file_exists($path) && is_file($path)) {
            $realFile = $path;
            break;
        }
    }

    if ($realFile === '') {
        http_response_code(404);
        echo 'File not found on server.';
        exit;
    }

    $labelName = (string)($doc['original_name'] ?? $doc['doc_name'] ?? basename($realFile));
    $knownExt  = $doc['file_ext'] ?? null;

    pp_stream_file_download($realFile, $labelName, $knownExt);
}


// --------------------------------------------------------------
//  GET: VERSION HISTORY  (AJAX - returns HTML fragment)
// --------------------------------------------------------------
if ($action === 'version_history') {
    $group = $_GET['doc_group'] ?? '';

    $stmt = $conn->prepare("
        SELECT * FROM venture_documents
        WHERE venture_id = ? AND doc_group = ?
        ORDER BY version_number DESC
    ");
    $stmt->bind_param('is', $venture_id, $group);
    $stmt->execute();
    $rows = $stmt->get_result();
    $stmt->close();

    $html = '<ul class="version-list">';

    while ($r = $rows->fetch_assoc()) {
        $latest = $r['is_latest']
            ? '<span class="badge badge-success" style="font-size:10px">Latest</span>'
            : '';

        $versionNum  = (int)$r['version_number'];
        $uploadedAt  = date('M j, Y g:ia', strtotime($r['uploaded_at']));
        $sizeMb      = number_format($r['file_size'] / 1048576, 2);
        $docId       = (int)$r['id'];

        $html .= '<li>'
               . '<div>'
               . '<strong style="color:var(--ink)">v' . $versionNum . '</strong>'
               . ' ' . $latest
               . '<span style="font-size:11.5px;color:var(--muted);margin-left:6px">'
               . $uploadedAt
               . ' &middot; ' . $sizeMb . ' MB'
               . '</span>'
               . '</div>'
               . '<a href="portal-process.php?action=download_doc&id=' . $docId . '" class="btn btn-outline btn-sm">'
               . '<i class="fa fa-download"></i>'
               . '</a>'
               . '</li>';
    }

    $html .= '</ul>';

    echo $html;
    exit;
}


// -- All remaining actions require POST -----------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../dashboard.php'); exit;
}

// --------------------------------------------------------------
//  DOCUMENT FOLDER - CREATE
// --------------------------------------------------------------
if ($action === 'create_document_folder') {
    $folder_name = pp_clean_folder_name(
        (string)($_POST['folder_name'] ?? $_POST['name'] ?? '')
    );
    $parent_id = (int)($_POST['parent_id'] ?? $_POST['folder_id'] ?? 0);
    $parent_id = $parent_id > 0 ? $parent_id : 0;

    if ($folder_name === '') {
        vp_flash('Folder name is required.', 'error');
        pp_document_folder_redirect($parent_id > 0 ? $parent_id : null);
    }

    if ($parent_id > 0 && !pp_folder_exists($conn, $venture_id, $parent_id)) {
        vp_flash('Parent folder not found.', 'error');
        pp_document_folder_redirect();
    }

    if (pp_folder_name_exists(
        $conn,
        $venture_id,
        $parent_id > 0 ? $parent_id : null,
        $folder_name
    )) {
        vp_flash('A folder with this name already exists here.', 'error');
        pp_document_folder_redirect($parent_id > 0 ? $parent_id : null);
    }

    if ($parent_id > 0) {
        $stmt = $conn->prepare("
            INSERT INTO venture_document_folders
                (venture_id, parent_id, folder_name)
            VALUES (?, ?, ?)
        ");
        $stmt->bind_param('iis', $venture_id, $parent_id, $folder_name);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO venture_document_folders
                (venture_id, parent_id, folder_name)
            VALUES (?, NULL, ?)
        ");
        $stmt->bind_param('is', $venture_id, $folder_name);
    }

    if ($stmt->execute()) {
        vp_flash('Folder created successfully.');
    } else {
        vp_flash('Failed to create folder: ' . $stmt->error, 'error');
    }
    $stmt->close();

    pp_document_folder_redirect($parent_id > 0 ? $parent_id : null);
}


// --------------------------------------------------------------
//  DOCUMENT FOLDER - RENAME
// --------------------------------------------------------------
if ($action === 'rename_document_folder') {
    $folder_id = (int)($_POST['folder_id'] ?? $_POST['id'] ?? 0);
    $folder_name = pp_clean_folder_name(
        (string)($_POST['folder_name'] ?? $_POST['name'] ?? '')
    );

    if ($folder_id <= 0 || !pp_folder_exists($conn, $venture_id, $folder_id)) {
        vp_flash('Folder not found.', 'error');
        pp_document_folder_redirect();
    }

    if ($folder_name === '') {
        vp_flash('Folder name is required.', 'error');
        pp_document_folder_redirect($folder_id);
    }

    $parent_id = pp_folder_parent_id($conn, $venture_id, $folder_id);

    if (pp_folder_name_exists(
        $conn,
        $venture_id,
        $parent_id,
        $folder_name,
        $folder_id
    )) {
        vp_flash('Another folder with this name already exists here.', 'error');
        pp_document_folder_redirect($parent_id);
    }

    $stmt = $conn->prepare("
        UPDATE venture_document_folders
        SET folder_name = ?
        WHERE id = ? AND venture_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('sii', $folder_name, $folder_id, $venture_id);

    if ($stmt->execute()) {
        vp_flash('Folder renamed successfully.');
    } else {
        vp_flash('Failed to rename folder: ' . $stmt->error, 'error');
    }
    $stmt->close();

    pp_document_folder_redirect($parent_id);
}


// --------------------------------------------------------------
//  DOCUMENT FOLDER - MOVE
// --------------------------------------------------------------
if ($action === 'move_document_folder') {
    $folder_id = (int)($_POST['folder_id'] ?? $_POST['id'] ?? 0);
    $target_folder_id = (int)(
        $_POST['target_folder_id'] ?? $_POST['new_parent_id'] ?? $_POST['parent_id'] ?? 0
    );

    if ($folder_id <= 0 || !pp_folder_exists($conn, $venture_id, $folder_id)) {
        pp_document_move_finish(false, 'Folder not found.');
    }

    if ($target_folder_id > 0 && !pp_folder_exists($conn, $venture_id, $target_folder_id)) {
        pp_document_move_finish(false, 'Destination folder not found.');
    }

    if ($target_folder_id === $folder_id) {
        pp_document_move_finish(false, 'A folder cannot be moved into itself.', $folder_id);
    }

    if (
        $target_folder_id > 0
        && pp_folder_is_descendant($conn, $venture_id, $target_folder_id, $folder_id)
    ) {
        pp_document_move_finish(false, 'A folder cannot be moved inside one of its own subfolders.', $folder_id);
    }

    if ($target_folder_id > 0) {
        $stmt = $conn->prepare("
            UPDATE venture_document_folders
            SET parent_id = ?
            WHERE id = ? AND venture_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('iii', $target_folder_id, $folder_id, $venture_id);
    } else {
        $stmt = $conn->prepare("
            UPDATE venture_document_folders
            SET parent_id = NULL
            WHERE id = ? AND venture_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('ii', $folder_id, $venture_id);
    }

    $moved = $stmt->execute();
    $error = $stmt->error;
    $stmt->close();
    pp_document_move_finish(
        $moved,
        $moved ? 'Folder moved successfully.' : 'Failed to move folder: ' . $error,
        $target_folder_id > 0 ? $target_folder_id : null
    );
}


// --------------------------------------------------------------
//  DOCUMENT - MOVE TO FOLDER / ROOT
// --------------------------------------------------------------
if ($action === 'move_document') {
    $document_id = (int)($_POST['document_id'] ?? $_POST['doc_id'] ?? $_POST['id'] ?? 0);
    $target_folder_id = (int)(
        $_POST['target_folder_id'] ?? $_POST['folder_id'] ?? 0
    );

    if ($document_id <= 0) {
        pp_document_move_finish(false, 'Invalid document.');
    }

    if ($target_folder_id > 0 && !pp_folder_exists($conn, $venture_id, $target_folder_id)) {
        pp_document_move_finish(false, 'Destination folder not found.');
    }

    $check = $conn->prepare("
        SELECT id
        FROM venture_documents
        WHERE id = ? AND venture_id = ?
        LIMIT 1
    ");
    $check->bind_param('ii', $document_id, $venture_id);
    $check->execute();
    $doc = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$doc) {
        pp_document_move_finish(false, 'Document not found.');
    }

    if ($target_folder_id > 0) {
        $stmt = $conn->prepare("
            UPDATE venture_documents
            SET folder_id = ?
            WHERE id = ? AND venture_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('iii', $target_folder_id, $document_id, $venture_id);
    } else {
        $stmt = $conn->prepare("
            UPDATE venture_documents
            SET folder_id = NULL
            WHERE id = ? AND venture_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('ii', $document_id, $venture_id);
    }

    $moved = $stmt->execute();
    $error = $stmt->error;
    $stmt->close();
    pp_document_move_finish(
        $moved,
        $moved ? 'Document moved successfully.' : 'Failed to move document: ' . $error,
        $target_folder_id > 0 ? $target_folder_id : null
    );
}


// --------------------------------------------------------------
//  DOCUMENT FOLDER - DELETE
// --------------------------------------------------------------
if ($action === 'delete_document_folder') {
    $folder_id = (int)($_POST['folder_id'] ?? $_POST['id'] ?? 0);

    if ($folder_id <= 0 || !pp_folder_exists($conn, $venture_id, $folder_id)) {
        vp_flash('Folder not found.', 'error');
        pp_document_folder_redirect();
    }

    $parent_id = pp_folder_parent_id($conn, $venture_id, $folder_id);

    // Protect against accidental data loss: only empty folders are deleted.
    $stmt = $conn->prepare("
        SELECT
            (SELECT COUNT(*)
             FROM venture_document_folders
             WHERE venture_id = ? AND parent_id = ?) AS child_folders,
            (SELECT COUNT(*)
             FROM venture_documents
             WHERE venture_id = ? AND folder_id = ?) AS documents_count
    ");
    $stmt->bind_param('iiii', $venture_id, $folder_id, $venture_id, $folder_id);
    $stmt->execute();
    $counts = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    if (
        (int)($counts['child_folders'] ?? 0) > 0
        || (int)($counts['documents_count'] ?? 0) > 0
    ) {
        vp_flash(
            'This folder is not empty. Move or delete its files and subfolders first.',
            'error'
        );
        pp_document_folder_redirect($folder_id);
    }

    $stmt = $conn->prepare("
        DELETE FROM venture_document_folders
        WHERE id = ? AND venture_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $folder_id, $venture_id);

    if ($stmt->execute()) {
        vp_flash('Folder deleted successfully.');
    } else {
        vp_flash('Failed to delete folder: ' . $stmt->error, 'error');
    }
    $stmt->close();

    pp_document_folder_redirect($parent_id);
}


if ($action === 'request_doc_change') {
    $document_id = (int)($_POST['document_id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));

    if ($document_id <= 0) {
        vp_flash('Invalid document.', 'error');
        vp_redir('documents.php');
    }

    if ($reason === '') {
        vp_flash('Please provide a reason for changing this approved document.', 'error');
        vp_redir('documents.php');
    }

    $stmt = $conn->prepare("
        SELECT id, doc_name, approval_status, change_request_status
        FROM venture_documents
        WHERE id = ?
          AND venture_id = ?
          AND is_latest = 1
        LIMIT 1
    ");
    $stmt->bind_param('ii', $document_id, $venture_id);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$doc) {
        vp_flash('Document not found.', 'error');
        vp_redir('documents.php');
    }

    if (($doc['approval_status'] ?? 'pending') !== 'approved') {
        vp_flash('This document is not approved yet. You can upload a new version directly.', 'info');
        vp_redir('documents.php');
    }

    if (($doc['change_request_status'] ?? 'none') === 'requested') {
        vp_flash('You already have a pending change request for this document.', 'info');
        vp_redir('documents.php');
    }

    $stmt = $conn->prepare("
        UPDATE venture_documents
        SET change_request_status = 'requested',
            change_request_reason = ?,
            change_request_at = NOW()
        WHERE id = ?
          AND venture_id = ?
    ");
    $stmt->bind_param('sii', $reason, $document_id, $venture_id);

    if ($stmt->execute()) {
        $subject = 'Document change request: ' . ($doc['doc_name'] ?? 'Document');
        $body = "A venture has requested permission to replace an approved document.\n\n"
              . "Document: " . ($doc['doc_name'] ?? 'Document') . "\n"
              . "Reason:\n" . $reason;

        $sender = $_SESSION['venture_founder'] ?? $_SESSION['venture_name'] ?? 'Founder';

        $msg = $conn->prepare("
            INSERT INTO venture_messages
                (venture_id, sender, subject, body, is_read, created_at)
            VALUES (?, ?, ?, ?, 0, NOW())
        ");

        if ($msg) {
            $msg->bind_param('isss', $venture_id, $sender, $subject, $body);
            $msg->execute();
            $msg->close();
        }

        vp_flash('Your change request has been sent to Admin.');
    } else {
        vp_flash('Failed to send change request: ' . $stmt->error, 'error');
    }

    $stmt->close();
    vp_redir('documents.php');
}


// --------------------------------------------------------------
//  UPLOAD DOCUMENT
// --------------------------------------------------------------
if ($action === 'upload_doc') {
    $ven_id      = (int)($_POST['venture_id'] ?? 0);
    $folder_id   = (int)($_POST['folder_id'] ?? 0);
    $doc_name    = trim((string)($_POST['doc_name'] ?? ''));
    $category    = trim((string)($_POST['category'] ?? 'other'));
    $other_cat   = trim((string)($_POST['other_category_name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $doc_group   = trim((string)($_POST['doc_group'] ?? ''));
    $visible     = (($_POST['is_visible_to_investors'] ?? '0') === '1') ? 1 : 0;

    if ($ven_id !== $venture_id) {
        vp_flash('Permission denied.', 'error');
        pp_document_folder_redirect($folder_id > 0 ? $folder_id : null);
    }

    if ($folder_id > 0 && !pp_folder_exists($conn, $venture_id, $folder_id)) {
        vp_flash('Selected folder does not exist.', 'error');
        pp_document_folder_redirect();
    }

    if ($doc_name === '') {
        vp_flash('Document name is required.', 'error');
        pp_document_folder_redirect($folder_id > 0 ? $folder_id : null);
    }

    if (empty($_FILES['doc_file']['name'])) {
        vp_flash('Please select a file.', 'error');
        pp_document_folder_redirect($folder_id > 0 ? $folder_id : null);
    }

    $allowed_cats = [
        'pitch_deck',
        'financial_model',
        'business_plan',
        'legal',
        'product_demo',
        'team_profile',
        'due_diligence',
        'impact_report',
        'other',
    ];

    if (!in_array($category, $allowed_cats, true)) {
        $category = 'other';
    }

    if ($category !== 'other') {
        $other_cat = '';
    } else {
        $other_cat = mb_substr($other_cat, 0, 120);
    }

    $original_name = basename((string)$_FILES['doc_file']['name']);
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $allowed_exts = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'mp4', 'mov', 'zip', 'rar', 'png', 'jpg', 'jpeg'
    ];

    if (!in_array($ext, $allowed_exts, true)) {
        vp_flash('File type not allowed.', 'error');
        pp_document_folder_redirect($folder_id > 0 ? $folder_id : null);
    }

    $s3_mode = defined('AWS_S3_BUCKET')
        && defined('AWS_ACCESS_KEY')
        && defined('AWS_SECRET_KEY')
        && defined('AWS_REGION');

    $max_bytes = $s3_mode
        ? 500 * 1024 * 1024
        : 50 * 1024 * 1024;

    if ((int)$_FILES['doc_file']['size'] > $max_bytes) {
        vp_flash(
            'File too large (max ' . ($s3_mode ? '500' : '50') . ' MB).',
            'error'
        );
        pp_document_folder_redirect($folder_id > 0 ? $folder_id : null);
    }

    if ((int)$_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
        vp_flash('Upload error. Please try again.', 'error');
        pp_document_folder_redirect($folder_id > 0 ? $folder_id : null);
    }

    $file_path = '';
    $s3_url = '';
    $storage_type = 'local';

    $conn->begin_transaction();

    try {
        if ($doc_group !== '') {
            $stmt = $conn->prepare("
                SELECT id, approval_status, change_request_status, folder_id
                FROM venture_documents
                WHERE venture_id = ?
                  AND doc_group = ?
                  AND is_latest = 1
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->bind_param('is', $venture_id, $doc_group);
            $stmt->execute();
            $current_doc = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($current_doc) {
                $approval_status = strtolower(
                    (string)($current_doc['approval_status'] ?? 'pending')
                );
                $change_status = strtolower(
                    (string)($current_doc['change_request_status'] ?? 'none')
                );

                if ($approval_status === 'approved' && $change_status !== 'allowed') {
                    throw new RuntimeException(
                        'This document has already been approved. '
                        . 'Please request Admin approval before uploading a new version.'
                    );
                }

                // If the version form does not send a folder, preserve its current folder.
                if ($folder_id <= 0 && !empty($current_doc['folder_id'])) {
                    $folder_id = (int)$current_doc['folder_id'];
                }
            }
        } else {
            $doc_group = uniqid('dg_', true);
        }

        $vstmt = $conn->prepare("
            SELECT MAX(version_number) AS max_v
            FROM venture_documents
            WHERE venture_id = ? AND doc_group = ?
            FOR UPDATE
        ");
        $vstmt->bind_param('is', $venture_id, $doc_group);
        $vstmt->execute();
        $vrow = $vstmt->get_result()->fetch_assoc();
        $vstmt->close();
        $version = ((int)($vrow['max_v'] ?? 0)) + 1;

        $ustmt = $conn->prepare("
            UPDATE venture_documents
            SET is_latest = 0
            WHERE venture_id = ? AND doc_group = ?
        ");
        $ustmt->bind_param('is', $venture_id, $doc_group);
        $ustmt->execute();
        $ustmt->close();

        $file_size = (int)$_FILES['doc_file']['size'];
        $filename = uniqid('doc_', true) . '.' . $ext;

        if ($s3_mode) {
            $url = s3_upload(
                $_FILES['doc_file'],
                "ventures/{$venture_id}/docs/{$filename}"
            );

            if (!$url) {
                throw new RuntimeException('S3 upload failed.');
            }

            $s3_url = $url;
            $storage_type = 's3';
        } else {
            $dest_dir = rtrim(
                defined('UPLOAD_PATH')
                    ? UPLOAD_PATH
                    : (dirname(__DIR__) . '/uploads'),
                '/'
            ) . '/ventures/docs/';

            if (!is_dir($dest_dir) && !mkdir($dest_dir, 0755, true) && !is_dir($dest_dir)) {
                throw new RuntimeException('Unable to create upload directory.');
            }

            if (!move_uploaded_file(
                $_FILES['doc_file']['tmp_name'],
                $dest_dir . $filename
            )) {
                throw new RuntimeException(
                    'Failed to save file. Check server permissions.'
                );
            }

            $file_path = 'uploads/ventures/docs/' . $filename;
        }

        $uploader = (string)(
            $_SESSION['venture_founder']
            ?? $_SESSION['venture_name']
            ?? 'Founder'
        );

        $stmt = $conn->prepare("
            INSERT INTO venture_documents
            (
                venture_id,
                folder_id,
                doc_group,
                doc_name,
                category,
                other_category_name,
                description,
                file_path,
                file_size,
                file_ext,
                storage_type,
                s3_url,
                version_number,
                is_latest,
                is_visible_to_investors,
                uploaded_by_name,
                uploaded_at
            )
            VALUES (
                ?, NULLIF(?, 0), ?, ?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, NOW()
            )
        ");

        $stmt->bind_param(
            'iissssssisssiis',
            $venture_id,
            $folder_id,
            $doc_group,
            $doc_name,
            $category,
            $other_cat,
            $description,
            $file_path,
            $file_size,
            $ext,
            $storage_type,
            $s3_url,
            $version,
            $visible,
            $uploader
        );

        if (!$stmt->execute()) {
            throw new RuntimeException('Upload failed: ' . $stmt->error);
        }
        $stmt->close();

        $conn->commit();

        vp_flash(
            'Document uploaded'
            . ($version > 1 ? " (v{$version})." : '.')
        );
    } catch (Throwable $e) {
        $conn->rollback();

        if ($file_path !== '' && $storage_type === 'local') {
            $abs = rtrim(
                defined('UPLOAD_PATH')
                    ? UPLOAD_PATH
                    : (dirname(__DIR__) . '/uploads'),
                '/'
            ) . '/ventures/docs/' . basename($file_path);

            if (file_exists($abs)) {
                @unlink($abs);
            }
        }

        vp_flash('Upload failed: ' . $e->getMessage(), 'error');
    }

    pp_document_folder_redirect($folder_id > 0 ? $folder_id : null);
}

// --------------------------------------------------------------
//  TEAM - ADD MEMBER
// --------------------------------------------------------------
if ($action === 'add_team_member') {
    pp_repair_venture_team_foreign_key(
        $conn
    );

    $ven_id     = (int)($_POST['venture_id'] ?? 0);
    $full_name  = trim($_POST['full_name']   ?? '');
    $role       = trim($_POST['role']        ?? '');
    $bio        = trim($_POST['bio']         ?? '');
    $email      = trim($_POST['email']       ?? '');
    $linkedin   = trim($_POST['linkedin']    ?? '');
    $twitter    = trim($_POST['twitter']     ?? '');
    $is_founder = isset($_POST['is_founder']) && $_POST['is_founder'] === '1' ? 1 : 0;

    if ($ven_id !== $venture_id) { vp_flash('Permission denied.', 'error');      vp_redir('team.php'); }
    if ($full_name === '')        { vp_flash('Full name is required.', 'error');   vp_redir('team.php'); }
    if ($role === '')             { vp_flash('Role / title is required.', 'error'); vp_redir('team.php'); }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        vp_flash('Please enter a valid email address.', 'error');
        vp_redir('team.php');
    }

    $cstmt = $conn->prepare("SELECT COUNT(*) AS c FROM venture_team WHERE venture_id = ?");
    $cstmt->bind_param('i', $venture_id);
    $cstmt->execute();
    $count = (int)($cstmt->get_result()->fetch_assoc()['c'] ?? 0);
    $cstmt->close();

    if ($count >= 4) {
        vp_flash('Your team is full (max 4 members). Remove a member to add a new one.', 'error');
        vp_redir('team.php');
    }

    if ($linkedin !== '' && !str_starts_with($linkedin, 'http')) {
        $linkedin = 'https://linkedin.com/in/' . ltrim($linkedin, '/');
    }

    $photo_err = '';
    $photo     = upload_photo('photo', 'ventures/team', $photo_err);
    if ($photo_err !== '' && !empty($_FILES['photo']['name'])) {
        vp_flash($photo_err, 'error');
        vp_redir('team.php');
    }

    $sstmt = $conn->prepare("SELECT MAX(sort_order) AS m FROM venture_team WHERE venture_id = ?");
    $sstmt->bind_param('i', $venture_id);
    $sstmt->execute();
    $sort = (int)($sstmt->get_result()->fetch_assoc()['m'] ?? 0) + 1;
    $sstmt->close();

    $stmt = $conn->prepare("
        INSERT INTO venture_team
            (venture_id, full_name, role, bio, photo, linkedin, twitter, email, is_founder, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('isssssssii',
        $venture_id, $full_name, $role, $bio,
        $photo, $linkedin, $twitter, $email,
        $is_founder, $sort
    );

    if ($stmt->execute()) {
        vp_flash("{$full_name} has been added to your team.");
    } else {
        vp_flash('Failed to add member: ' . $stmt->error, 'error');
    }
    $stmt->close();
    vp_redir('team.php');
}


// --------------------------------------------------------------
//  TEAM - EDIT MEMBER
// --------------------------------------------------------------
if ($action === 'edit_team_member') {
    pp_repair_venture_team_foreign_key(
        $conn
    );

    $id         = (int)($_POST['id']        ?? 0);
    $full_name  = trim($_POST['full_name']  ?? '');
    $role       = trim($_POST['role']       ?? '');
    $bio        = trim($_POST['bio']        ?? '');
    $email      = trim($_POST['email']      ?? '');
    $linkedin   = trim($_POST['linkedin']   ?? '');
    $twitter    = trim($_POST['twitter']    ?? '');
    $is_founder = isset($_POST['is_founder']) && $_POST['is_founder'] === '1' ? 1 : 0;

    if ($id <= 0)      { vp_flash('Invalid team member.', 'error'); vp_redir('team.php'); }
    if ($full_name === '') { vp_flash('Full name is required.', 'error');    vp_redir('team.php'); }
    if ($role === '')      { vp_flash('Role / title is required.', 'error'); vp_redir('team.php'); }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        vp_flash('Please enter a valid email address.', 'error');
        vp_redir('team.php');
    }

    $own = $conn->prepare("SELECT id, photo FROM venture_team WHERE id = ? AND venture_id = ? LIMIT 1");
    $own->bind_param('ii', $id, $venture_id);
    $own->execute();
    $current = $own->get_result()->fetch_assoc();
    $own->close();
    if (!$current) { vp_flash('Team member not found.', 'error'); vp_redir('team.php'); }

    if ($linkedin !== '' && !str_starts_with($linkedin, 'http')) {
        $linkedin = 'https://linkedin.com/in/' . ltrim($linkedin, '/');
    }

    $photo     = $current['photo'] ?? '';
    $photo_err = '';
    if (!empty($_FILES['photo']['name'])) {
        $new_photo = upload_photo('photo', 'ventures/team', $photo_err);
        if ($photo_err !== '') {
            vp_flash($photo_err, 'error');
            vp_redir('team.php');
        }
        if ($new_photo !== '') {
            delete_file($photo);
            $photo = $new_photo;
        }
    }

    $stmt = $conn->prepare("
        UPDATE venture_team
           SET full_name  = ?,
               role       = ?,
               bio        = ?,
               photo      = ?,
               linkedin   = ?,
               twitter    = ?,
               email      = ?,
               is_founder = ?
         WHERE id         = ?
           AND venture_id = ?
         LIMIT 1
    ");
    $stmt->bind_param('sssssssiii',
        $full_name, $role, $bio, $photo,
        $linkedin, $twitter, $email, $is_founder,
        $id, $venture_id
    );

    if ($stmt->execute()) {
        vp_flash("{$full_name} has been updated.");
    } else {
        vp_flash('Update failed: ' . $stmt->error, 'error');
    }
    $stmt->close();
    vp_redir('team.php');
}


// --------------------------------------------------------------
//  TEAM - REMOVE MEMBER
// --------------------------------------------------------------
if ($action === 'remove_team_member') {
    pp_repair_venture_team_foreign_key(
        $conn
    );

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { vp_flash('Invalid team member.', 'error'); vp_redir('team.php'); }

    $own = $conn->prepare("SELECT id, photo, full_name FROM venture_team WHERE id = ? AND venture_id = ? LIMIT 1");
    $own->bind_param('ii', $id, $venture_id);
    $own->execute();
    $current = $own->get_result()->fetch_assoc();
    $own->close();
    if (!$current) { vp_flash('Team member not found or permission denied.', 'error'); vp_redir('team.php'); }

    delete_file($current['photo'] ?? '');

    $stmt = $conn->prepare("DELETE FROM venture_team WHERE id = ? AND venture_id = ? LIMIT 1");
    $stmt->bind_param('ii', $id, $venture_id);
    if ($stmt->execute()) {
        vp_flash(($current['full_name'] ?? 'Member') . ' has been removed from your team.');
    } else {
        vp_flash('Remove failed: ' . $stmt->error, 'error');
    }
    $stmt->close();
    vp_redir('team.php');
}


// --------------------------------------------------------------
//  TEAM - REORDER  (AJAX - returns plain text)
// --------------------------------------------------------------
if ($action === 'reorder_team') {
    pp_repair_venture_team_foreign_key(
        $conn
    );

    $ven_id = (int)($_POST['venture_id'] ?? 0);
    $ids    = json_decode($_POST['ids'] ?? '[]', true);

    if ($ven_id !== $venture_id || !is_array($ids)) { echo 'error'; exit; }

    $stmt = $conn->prepare("UPDATE venture_team SET sort_order = ? WHERE id = ? AND venture_id = ? LIMIT 1");
    foreach ($ids as $order => $mid) {
        $mid  = (int)$mid;
        $sort = (int)$order + 1;
        $stmt->bind_param('iii', $sort, $mid, $venture_id);
        $stmt->execute();
    }
    $stmt->close();
    echo 'ok';
    exit;
}


// --------------------------------------------------------------
//  CHANGE PASSWORD
// --------------------------------------------------------------
if ($action === 'change_password') {
    $current = $_POST['current_password'] ?? '';
    $new_pw  = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($new_pw !== $confirm)   { vp_flash('Passwords do not match.', 'error');                 vp_redir('profile.php'); }
    if (strlen($new_pw) < 8)    { vp_flash('Password must be at least 8 characters.', 'error'); vp_redir('profile.php'); }

    $stmt = $conn->prepare("SELECT password_hash FROM venture_portal_access WHERE venture_id = ? LIMIT 1");
    $stmt->bind_param('i', $venture_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($current, $row['password_hash'])) {
        vp_flash('Current password is incorrect.', 'error');
        vp_redir('profile.php');
    }

    $hash = password_hash($new_pw, PASSWORD_DEFAULT);
    $upd  = $conn->prepare("UPDATE venture_portal_access SET password_hash = ? WHERE venture_id = ? LIMIT 1");
    $upd->bind_param('si', $hash, $venture_id);
    if ($upd->execute()) {
        vp_flash('Password changed successfully.');
    } else {
        vp_flash('Failed to change password: ' . $upd->error, 'error');
    }
    $upd->close();
    vp_redir('profile.php');
}


// --------------------------------------------------------------
//  SEND MESSAGE TO ADMIN
// --------------------------------------------------------------
if ($action === 'send_message') {
    $subject = trim($_POST['subject'] ?? '');
    $body    = trim($_POST['body']    ?? '');

    if ($subject === '' || $body === '') {
        vp_flash('Subject and message body are required.', 'error');
        vp_redir('../messages.php');
    }

    $sender = $_SESSION['venture_founder'] ?? 'Founder';
    $stmt   = $conn->prepare("
        INSERT INTO venture_messages (venture_id, sender, subject, body, is_read, created_at)
        VALUES (?, ?, ?, ?, 0, NOW())
    ");
    $stmt->bind_param('isss', $venture_id, $sender, $subject, $body);
    if ($stmt->execute()) {
        pp_notify_message_receiver(
            $conn,
            'admin',
            0,
            $subject,
            $body,
            $sender,
            (string)($VENTURE['name'] ?? $_SESSION['venture_name'] ?? ''),
            ''
        );
        vp_flash('Message sent successfully.');
    } else {
        vp_flash('Failed to send message: ' . $stmt->error, 'error');
    }
    $stmt->close();
    vp_redir('../messages.php');
}


// --------------------------------------------------------------
//  REGISTER FOR EVENT
// --------------------------------------------------------------
if ($action === 'register_event') {
    $event_id = (int)($_POST['event_id'] ?? 0);
    if ($event_id <= 0) { vp_flash('Invalid event.', 'error'); vp_redir('events.php'); }

    $evstmt = $conn->prepare("
        SELECT * FROM events
        WHERE id = ?
          AND status IN('upcoming','ongoing')
        LIMIT 1
    ");
    $evstmt->bind_param('i', $event_id);
    $evstmt->execute();
    $ev = $evstmt->get_result()->fetch_assoc();
    $evstmt->close();

    if (!$ev) {
        vp_flash('This event is not available for registration.', 'error');
        vp_redir('events.php');
    }

    if ((int)$ev['capacity'] > 0) {
        $cstmt = $conn->prepare("SELECT COUNT(*) AS c FROM event_registrations WHERE event_id = ?");
        $cstmt->bind_param('i', $event_id);
        $cstmt->execute();
        $reg_ct = (int)($cstmt->get_result()->fetch_assoc()['c'] ?? 0);
        $cstmt->close();

        if ($reg_ct >= (int)$ev['capacity']) {
            vp_flash('Sorry, this event is fully booked.', 'error');
            vp_redir('events.php');
        }
    }

    $name  = $_SESSION['venture_founder'] ?? $_SESSION['venture_name'] ?? 'Founder';
    $email = $_SESSION['venture_email']   ?? $VENTURE['founder_email'] ?? '';
    $org   = $_SESSION['venture_name']    ?? '';

    $dup = $conn->prepare("SELECT id FROM event_registrations WHERE event_id = ? AND email = ? LIMIT 1");
    $dup->bind_param('is', $event_id, $email);
    $dup->execute();
    if ($dup->get_result()->num_rows > 0) {
        vp_flash('You are already registered for this event.', 'info');
        vp_redir('events.php');
    }
    $dup->close();

    $code = strtoupper(substr(md5(uniqid($email . $event_id, true)), 0, 8));
    $stmt = $conn->prepare("
        INSERT INTO event_registrations (event_id, name, email, organisation, registered_at, checkin_code)
        VALUES (?, ?, ?, ?, NOW(), ?)
    ");
    $stmt->bind_param('issss', $event_id, $name, $email, $org, $code);
    if ($stmt->execute()) {
        vp_flash("Registered! Your check-in code: <strong>{$code}</strong>.");
    } else {
        vp_flash('Registration failed: ' . $stmt->error, 'error');
    }
    $stmt->close();
    vp_redir('events.php');
}


// --------------------------------------------------------------
//  REQUEST MENTOR SESSION
// --------------------------------------------------------------
if ($action === 'request_session') {
    $ven_id       = (int)($_POST['venture_id']      ?? 0);
    $mentor_id    = (int)($_POST['mentor_id']        ?? 0);
    $title        = trim($_POST['title']             ?? '');
    $description  = trim($_POST['description']       ?? '');
    $pref_at      = pp_normalize_mysql_datetime($_POST['preferred_at'] ?? null);
    $duration     = max(15, (int)($_POST['duration_minutes'] ?? 60));

    $allowed_platforms = ['zoom','google_meet','teams','phone','in_person','other'];
    $allowed_types     = ['one_on_one','group','workshop','review','ad_hoc'];

    $platform = in_array($_POST['meeting_platform'] ?? '', $allowed_platforms, true)
        ? $_POST['meeting_platform']
        : 'zoom';

    $session_type = in_array($_POST['session_type'] ?? '', $allowed_types, true)
        ? $_POST['session_type']
        : 'one_on_one';

    if ($ven_id !== $venture_id) {
        vp_flash('Permission denied.', 'error');
        vp_redir('mentors.php');
    }

    if ($mentor_id <= 0) {
        vp_flash('Invalid mentor selection.', 'error');
        vp_redir('mentors.php');
    }

    if ($title === '') {
        vp_flash('Session title is required.', 'error');
        vp_redir('mentors.php');
    }

    if ($duration < 15 || $duration > 480) {
        vp_flash('Session duration must be between 15 minutes and 8 hours.', 'error');
        vp_redir('mentors.php');
    }

    if (trim((string)($_POST['preferred_at'] ?? '')) !== '' && $pref_at === null) {
        vp_flash('Please choose a valid preferred date and time.', 'error');
        vp_redir('mentors.php');
    }

    if ($pref_at !== null && strtotime($pref_at) < time()) {
        vp_flash('Preferred date and time cannot be in the past.', 'error');
        vp_redir('mentors.php');
    }

    $cohort_id = (int)($VENTURE['cohort_id'] ?? 0);
    $chk = $conn->prepare("
        SELECT id
        FROM mentor_assignments
        WHERE mentor_id = ?
          AND (venture_id = ? OR cohort_id = ?)
        LIMIT 1
    ");

    if (!$chk) {
        vp_flash('Could not verify mentor assignment: ' . $conn->error, 'error');
        vp_redir('mentors.php');
    }

    $chk->bind_param('iii', $mentor_id, $venture_id, $cohort_id);
    $chk->execute();
    $assigned = $chk->get_result()->num_rows > 0;
    $chk->close();

    if (!$assigned) {
        vp_flash('This mentor is not assigned to your venture.', 'error');
        vp_redir('mentors.php');
    }

    $pending = $conn->prepare("
        SELECT ms.id
        FROM mentor_sessions ms
        LEFT JOIN session_ventures sv ON sv.session_id = ms.id
        WHERE ms.mentor_id = ?
          AND ms.status = 'requested'
          AND (
                ms.venture_id = ?
             OR sv.venture_id = ?
          )
        LIMIT 1
    ");

    if (!$pending) {
        vp_flash('Could not check pending requests: ' . $conn->error, 'error');
        vp_redir('mentors.php');
    }

    $pending->bind_param('iii', $mentor_id, $venture_id, $venture_id);
    $pending->execute();
    $has_pending = $pending->get_result()->num_rows > 0;
    $pending->close();

    if ($has_pending) {
        vp_flash('You already have a pending session request with this mentor. Please wait for it to be confirmed or cancelled before sending another request.', 'info');
        vp_redir('sessions.php?status=requested');
    }

    if ($pref_at !== null) {
        $new_start = $pref_at;
        $new_end = date('Y-m-d H:i:s', strtotime($pref_at) + ($duration * 60));

        $mentor_overlap = $conn->prepare("
            SELECT ms.id
            FROM mentor_sessions ms
            WHERE ms.mentor_id = ?
              AND ms.scheduled_at IS NOT NULL
              AND ms.status NOT IN ('cancelled','completed','no_show')
              AND ms.scheduled_at < ?
              AND DATE_ADD(ms.scheduled_at, INTERVAL COALESCE(ms.duration_minutes, 60) MINUTE) > ?
            LIMIT 1
        ");

        if (!$mentor_overlap) {
            vp_flash('Could not check mentor availability: ' . $conn->error, 'error');
            vp_redir('mentors.php');
        }

        $mentor_overlap->bind_param('iss', $mentor_id, $new_end, $new_start);
        $mentor_overlap->execute();
        $mentor_is_booked = $mentor_overlap->get_result()->num_rows > 0;
        $mentor_overlap->close();

        if ($mentor_is_booked) {
            vp_flash('This mentor already has a session during that time. Please choose another preferred time.', 'error');
            vp_redir('mentors.php');
        }

        $venture_overlap = $conn->prepare("
            SELECT ms.id
            FROM mentor_sessions ms
            LEFT JOIN session_ventures sv ON sv.session_id = ms.id
            WHERE ms.scheduled_at IS NOT NULL
              AND ms.status NOT IN ('cancelled','completed','no_show')
              AND (
                    ms.venture_id = ?
                 OR sv.venture_id = ?
              )
              AND ms.scheduled_at < ?
              AND DATE_ADD(ms.scheduled_at, INTERVAL COALESCE(ms.duration_minutes, 60) MINUTE) > ?
            LIMIT 1
        ");

        if (!$venture_overlap) {
            vp_flash('Could not check your existing bookings: ' . $conn->error, 'error');
            vp_redir('mentors.php');
        }

        $venture_overlap->bind_param('iiss', $venture_id, $venture_id, $new_end, $new_start);
        $venture_overlap->execute();
        $venture_is_booked = $venture_overlap->get_result()->num_rows > 0;
        $venture_overlap->close();

        if ($venture_is_booked) {
            vp_flash('You already have another session booked during that time. Please choose another preferred time.', 'error');
            vp_redir('sessions.php');
        }
    }

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("
            INSERT INTO mentor_sessions
                (mentor_id, venture_id, cohort_id, title, description,
                 session_type, status, scheduled_at, duration_minutes,
                 meeting_platform, requested_by)
            VALUES (?, ?, ?, ?, ?, ?, 'requested', ?, ?, ?, 'venture')
        ");

        if (!$stmt) {
            throw new RuntimeException('Prepare failed: ' . $conn->error);
        }

        $stmt->bind_param(
            'iiissssis',
            $mentor_id,
            $venture_id,
            $cohort_id,
            $title,
            $description,
            $session_type,
            $pref_at,
            $duration,
            $platform
        );

        if (!$stmt->execute()) {
            throw new RuntimeException($stmt->error);
        }

        $new_id = (int)$conn->insert_id;
        $stmt->close();

        if ($new_id <= 0) {
            throw new RuntimeException('Session request was not created.');
        }

        $requested_by_name = $_SESSION['venture_founder'] ?? $_SESSION['venture_name'] ?? 'Venture';

        $smt = $conn->prepare("
            INSERT INTO session_mentors (session_id, mentor_id, role, invite_status, invited_by, responded_at)
            VALUES (?, ?, 'organizer', 'accepted', ?, NOW())
            ON DUPLICATE KEY UPDATE
                role = 'organizer',
                invite_status = 'accepted',
                responded_at = NOW()
        ");

        if ($smt) {
            $smt->bind_param('iis', $new_id, $mentor_id, $requested_by_name);
            if (!$smt->execute()) {
                throw new RuntimeException($smt->error);
            }
            $smt->close();
        }

        $svt = $conn->prepare("
            INSERT INTO session_ventures (session_id, venture_id, added_by)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                added_by = VALUES(added_by)
        ");

        if ($svt) {
            $svt->bind_param('iis', $new_id, $venture_id, $requested_by_name);
            if (!$svt->execute()) {
                throw new RuntimeException($svt->error);
            }
            $svt->close();
        }

        $conn->commit();
        vp_flash('Session request submitted. The programme team will confirm the date and time.');
    } catch (Throwable $e) {
        $conn->rollback();
        vp_flash('Request failed: ' . $e->getMessage(), 'error');
    }

    vp_redir('sessions.php');
}

// --------------------------------------------------------------
//  RATE SESSION
// --------------------------------------------------------------
if ($action === 'rate_session') {
    $session_id = (int)($_POST['session_id'] ?? 0);
    $rating     = max(1, min(5, (int)($_POST['rating']  ?? 0)));
    $feedback   = trim($_POST['feedback'] ?? '');

    if ($session_id <= 0 || $rating < 1) {
        vp_flash('Invalid rating.', 'error');
        vp_redir('sessions.php?status=completed');
    }

    if (!vp_owns_session($conn, $session_id, $venture_id)) {
        vp_flash('Session not found.', 'error');
        vp_redir('sessions.php?status=completed');
    }

    $own = $conn->prepare("SELECT id FROM mentor_sessions WHERE id = ? AND status = 'completed' LIMIT 1");
    $own->bind_param('i', $session_id);
    $own->execute();
    if (!$own->get_result()->num_rows) {
        vp_flash('Session not found or not yet completed.', 'error');
        vp_redir('sessions.php?status=completed');
    }
    $own->close();

    $upd = $conn->prepare("
        UPDATE mentor_sessions
           SET venture_rating   = ?,
               venture_feedback = ?
         WHERE id = ?
         LIMIT 1
    ");
    $upd->bind_param('isi', $rating, $feedback, $session_id);
    if ($upd->execute()) {
        vp_flash('Thank you for your feedback!');
    } else {
        vp_flash('Failed to save rating: ' . $upd->error, 'error');
    }
    $upd->close();
    vp_redir('sessions.php?status=completed');
}


// --------------------------------------------------------------
//  SEND MENTOR MESSAGE
// --------------------------------------------------------------
if ($action === 'send_mentor_message') {
    $ven_id              = (int)($_POST['venture_id']          ?? 0);
    $recipient_mentor_id = (int)($_POST['recipient_mentor_id'] ?? 0);
    $subject             = trim($_POST['subject'] ?? '');
    $body                = trim($_POST['body']    ?? '');

    if ($ven_id !== $venture_id)     { vp_flash('Permission denied.', 'error');             vp_redir('../messages.php'); }
    if ($subject === '' || $body === '') { vp_flash('Subject and message are required.', 'error'); vp_redir('../messages.php'); }

    $thread_id = bin2hex(random_bytes(16));

    if ($recipient_mentor_id > 0) {
        $rt = 'mentor'; $ri = $recipient_mentor_id;
    } else {
        $rt = 'admin';  $ri = 0;
    }

    $stmt = $conn->prepare("
        INSERT INTO mentor_messages
            (thread_id, sender_type, sender_id, recipient_type, recipient_id, subject, body)
        VALUES (?, 'venture', ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('siisss', $thread_id, $venture_id, $rt, $ri, $subject, $body);
    if ($stmt->execute()) {
        pp_notify_message_receiver(
            $conn,
            $rt,
            $ri,
            $subject,
            $body,
            $_SESSION['venture_founder'] ?? $_SESSION['venture_name'] ?? 'Founder',
            (string)($VENTURE['name'] ?? $_SESSION['venture_name'] ?? ''),
            $thread_id
        );
        vp_flash('Message sent.');
    } else {
        vp_flash('Failed to send message: ' . $stmt->error, 'error');
    }
    $stmt->close();
    vp_redir('../messages.php?thread=' . urlencode($thread_id));
}


// --------------------------------------------------------------
//  REPLY TO MENTOR MESSAGE
// --------------------------------------------------------------
if ($action === 'reply_mentor_message') {
    $thread_id = trim($_POST['thread_id']   ?? '');
    $ven_id    = (int)($_POST['venture_id'] ?? 0);
    $body      = trim($_POST['body']        ?? '');

    if ($ven_id !== $venture_id || $thread_id === '') {
        vp_flash('Permission denied.', 'error');
        vp_redir('../messages.php');
    }
    if ($body === '') {
        vp_flash('Message body cannot be empty.', 'error');
        vp_redir('../messages.php?thread=' . urlencode($thread_id));
    }

    $ostmt = $conn->prepare("
        SELECT sender_type, sender_id, recipient_type, recipient_id
        FROM   mentor_messages
        WHERE  thread_id = ?
          AND  NOT (sender_type = 'venture' AND sender_id = ?)
        LIMIT 1
    ");
    $ostmt->bind_param('si', $thread_id, $venture_id);
    $ostmt->execute();
    $orig = $ostmt->get_result()->fetch_assoc();
    $ostmt->close();

    if (!$orig) {
        vp_flash('Thread not found.', 'error');
        vp_redir('../messages.php');
    }

    $rt = $orig['sender_type'] !== 'venture' ? $orig['sender_type'] : $orig['recipient_type'];
    $ri = (int)($orig['sender_type'] !== 'venture' ? $orig['sender_id'] : $orig['recipient_id']);

    $stmt = $conn->prepare("
        INSERT INTO mentor_messages
            (thread_id, sender_type, sender_id, recipient_type, recipient_id, body)
        VALUES (?, 'venture', ?, ?, ?, ?)
    ");
    $stmt->bind_param('siiss', $thread_id, $venture_id, $rt, $ri, $body);
    if ($stmt->execute()) {
        $reply_subject = 'New reply in your message thread';

        $sstmt = $conn->prepare("
            SELECT subject
            FROM mentor_messages
            WHERE thread_id = ?
              AND subject IS NOT NULL
              AND subject <> ''
            ORDER BY id ASC
            LIMIT 1
        ");
        $sstmt->bind_param('s', $thread_id);
        $sstmt->execute();
        $subject_data = $sstmt->get_result()->fetch_assoc();
        $sstmt->close();

        if (!empty($subject_data['subject'])) {
            $reply_subject = (string)$subject_data['subject'];
        }

        pp_notify_message_receiver(
            $conn,
            $rt,
            $ri,
            $reply_subject,
            $body,
            $_SESSION['venture_founder'] ?? $_SESSION['venture_name'] ?? 'Founder',
            (string)($VENTURE['name'] ?? $_SESSION['venture_name'] ?? ''),
            $thread_id
        );

        vp_flash('Reply sent.');
    } else {
        vp_flash('Failed to send reply: ' . $stmt->error, 'error');
    }
    $stmt->close();
    vp_redir('../messages.php?thread=' . urlencode($thread_id));
}


// ==============================================================
//  ADD SESSION COMMENT (venture side)
// ==============================================================
if ($action === 'add_session_comment') {
    $session_id   = (int)($_POST['session_id'] ?? 0);
    $ven_id       = (int)($_POST['venture_id'] ?? 0);
    $comment_text = trim($_POST['comment_text'] ?? '');

    if ($ven_id !== $venture_id) {
        vp_flash('Permission denied.', 'error');
        vp_redir('sessions.php');
    }
    if ($session_id <= 0 || $comment_text === '') {
        vp_flash('Comment cannot be empty.', 'error');
        vp_redir('sessions.php');
    }
    if (mb_strlen($comment_text) > 4000) {
        vp_flash('Comment is too long (max 4000 characters).', 'error');
        vp_redir('sessions.php');
    }

    if (!vp_owns_session($conn, $session_id, $venture_id)) {
        vp_flash('Session not found.', 'error');
        vp_redir('sessions.php');
    }

    $own = $conn->prepare("
        SELECT ms.mentor_id, ms.title, ms.scheduled_at
        FROM mentor_sessions ms
        WHERE ms.id = ?
        LIMIT 1
    ");
    $own->bind_param('i', $session_id);
    $own->execute();
    $sess = $own->get_result()->fetch_assoc();
    $own->close();

    if (!$sess) {
        vp_flash('Session not found.', 'error');
        vp_redir('sessions.php');
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS session_comments (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id   INT UNSIGNED NOT NULL,
            author_type  VARCHAR(20)  NOT NULL DEFAULT 'venture',
            author_name  VARCHAR(120) NOT NULL DEFAULT '',
            comment_text TEXT         NOT NULL,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_session (session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $author_name = $_SESSION['venture_founder'] ?? $_SESSION['venture_name'] ?? 'Venture';

    $stmt = $conn->prepare("
        INSERT INTO session_comments (session_id, author_type, author_name, comment_text)
        VALUES (?, 'venture', ?, ?)
    ");
    if (!$stmt) { vp_flash('DB error: ' . $conn->error, 'error'); vp_redir('sessions.php'); }
    $stmt->bind_param('iss', $session_id, $author_name, $comment_text);
    $ok  = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    if ($ok) {
        pp_notify_session_mentors(
            $conn,
            $session_id,
            (int)$sess['mentor_id'],
            'comment',
            (string)$sess['title'],
            $sess['scheduled_at'] ?? null,
            (string)($VENTURE['name'] ?? $_SESSION['venture_name'] ?? ''),
            $comment_text
        );
        vp_flash('Comment added.');
    } else {
        vp_flash('Failed to add comment: ' . $err, 'error');
    }

    vp_redir('sessions.php');
}


if ($action === 'rate_mentor') {
    $session_id      = (int)($_POST['session_id'] ?? 0);
    $ven_id          = (int)($_POST['venture_id'] ?? 0);
    $mentor_rating   = max(1, min(5, (int)($_POST['mentor_rating'] ?? 0)));
    $mentor_feedback = trim($_POST['mentor_feedback'] ?? '');

    if ($ven_id !== $venture_id) {
        vp_flash('Permission denied.', 'error');
        vp_redir('sessions.php?status=completed');
    }
    if ($session_id <= 0 || $mentor_rating < 1) {
        vp_flash('Invalid rating.', 'error');
        vp_redir('sessions.php?status=completed');
    }

    if (!vp_owns_session($conn, $session_id, $venture_id)) {
        vp_flash('Session not found.', 'error');
        vp_redir('sessions.php?status=completed');
    }

    $own = $conn->prepare("SELECT id FROM mentor_sessions WHERE id = ? AND status = 'completed' LIMIT 1");
    $own->bind_param('i', $session_id);
    $own->execute();
    if (!$own->get_result()->num_rows) {
        vp_flash('Session not found or not yet completed.', 'error');
        vp_redir('sessions.php?status=completed');
    }
    $own->close();

    $upd = $conn->prepare("
        UPDATE mentor_sessions
           SET mentor_rated_by_venture = ?,
               mentor_rating_feedback  = ?
         WHERE id = ?
         LIMIT 1
    ");
    if (!$upd) { vp_flash('DB error: ' . $conn->error, 'error'); vp_redir('sessions.php?status=completed'); }
    $upd->bind_param('isi', $mentor_rating, $mentor_feedback, $session_id);
    if ($upd->execute()) {
        vp_flash('Thank you for rating your mentor!');
    } else {
        vp_flash('Failed to save rating: ' . $upd->error, 'error');
    }
    $upd->close();

    vp_redir('sessions.php?status=completed');
}


// ==============================================================
//  SAVE / SUBMIT VENTURE MENTORSHIP SESSION REPORT
// ==============================================================
if ($action === 'save_session_feedback') {
    $session_id  = (int)($_POST['session_id'] ?? 0);
    $ven_id      = (int)($_POST['venture_id'] ?? 0);
    $submit_mode = ($_POST['submit_mode'] ?? 'draft') === 'submit' ? 'submit' : 'draft';

    if ($ven_id !== $venture_id) {
        vp_flash('Permission denied.', 'error');
        vp_redir('sessions.php?status=completed');
    }
    if ($session_id <= 0) {
        vp_flash('Invalid session.', 'error');
        vp_redir('sessions.php?status=completed');
    }

    if (!vp_owns_session($conn, $session_id, $venture_id)) {
        vp_flash('Session not found.', 'error');
        vp_redir('sessions.php?status=completed');
    }

    $sess_stmt = $conn->prepare("
        SELECT id, status, mentor_id, title, scheduled_at
        FROM mentor_sessions
        WHERE id = ?
        LIMIT 1
    ");
    $sess_stmt->bind_param('i', $session_id);
    $sess_stmt->execute();
    $sess = $sess_stmt->get_result()->fetch_assoc();
    $sess_stmt->close();

    if (!$sess) {
        vp_flash('Session not found.', 'error');
        vp_redir('sessions.php?status=completed');
    }

    if ((string)$sess['status'] !== 'completed') {
        vp_flash('Session reports can only be filed for completed sessions.', 'error');
        vp_redir('sessions.php?status=completed');
    }

    fb_ensure_table($conn);

    $existing_stmt = $conn->prepare("SELECT id, status FROM session_feedback_reports WHERE session_id = ? LIMIT 1");
    $existing_stmt->bind_param('i', $session_id);
    $existing_stmt->execute();
    $existing = $existing_stmt->get_result()->fetch_assoc();
    $existing_stmt->close();

    if ($existing && in_array((string)$existing['status'], ['submitted', 'reviewed'], true)) {
        vp_flash('This session report has already been submitted and can no longer be edited.', 'error');
        vp_redir('sessions.php?status=completed');
    }

    // --- Simple text / select fields -------------------------------------
    $venture_name      = trim((string)($_POST['venture_name'] ?? ''));
    $founders_present  = trim((string)($_POST['founders_present'] ?? ''));
    $mentor_name       = trim((string)($_POST['mentor_name'] ?? ''));
    $session_date_raw  = trim((string)($_POST['session_date'] ?? ''));
    $session_duration  = trim((string)($_POST['session_duration'] ?? ''));
    $mode_of_engagement = trim((string)($_POST['mode_of_engagement'] ?? ''));
    $objectives        = trim((string)($_POST['objectives'] ?? ''));
    $value_rating      = trim((string)($_POST['value_rating'] ?? ''));
    $valuable_insight  = trim((string)($_POST['valuable_insight'] ?? ''));
    $recommendations   = trim((string)($_POST['recommendations'] ?? ''));
    $progress_rating   = trim((string)($_POST['progress_rating'] ?? ''));
    $progress_explain  = trim((string)($_POST['progress_explain'] ?? ''));
    $future_support    = trim((string)($_POST['future_support'] ?? ''));
    $satisfaction_raw  = trim((string)($_POST['satisfaction_rating'] ?? ''));
    $satisfaction_comments = trim((string)($_POST['satisfaction_comments'] ?? ''));
    $recommend_mentor  = trim((string)($_POST['recommend_mentor'] ?? ''));
    $feedback_for_admin   = trim((string)($_POST['feedback_for_admin'] ?? ''));
    $mentor_could_improve = trim((string)($_POST['mentor_could_improve'] ?? ''));

    $allowed_modes      = ['physical', 'virtual', 'hybrid'];
    $allowed_values      = ['extremely_valuable', 'very_valuable', 'moderately_valuable', 'slightly_valuable', 'not_valuable'];
    $allowed_progress    = ['significant', 'moderate', 'slight', 'none'];
    $allowed_recommend   = ['definitely', 'probably', 'not_sure', 'probably_not', 'definitely_not'];

    if (!in_array($mode_of_engagement, $allowed_modes, true)) $mode_of_engagement = '';
    if (!in_array($value_rating, $allowed_values, true)) $value_rating = '';
    if (!in_array($progress_rating, $allowed_progress, true)) $progress_rating = '';
    if (!in_array($recommend_mentor, $allowed_recommend, true)) $recommend_mentor = '';

    $session_date = null;
    if ($session_date_raw !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $session_date_raw);
        $session_date = $d instanceof DateTime ? $d->format('Y-m-d') : null;
    }

    $satisfaction_rating = null;
    if ($satisfaction_raw !== '' && ctype_digit($satisfaction_raw)) {
        $satisfaction_rating = max(1, min(5, (int)$satisfaction_raw));
    }

    // --- Repeatable sections (zipped from parallel arrays) ---------------
    $discussion_rows = fb_build_rows([
        'area'     => $_POST['discussion_area'] ?? [],
        'helpful'  => $_POST['discussion_helpful'] ?? [],
        'comments' => $_POST['discussion_comments'] ?? [],
    ]);

    $assessment_rows = fb_build_rows([
        'area'     => $_POST['assess_area'] ?? [],
        'rating'   => $_POST['assess_rating'] ?? [],
        'comments' => $_POST['assess_comments'] ?? [],
    ]);

    $action_rows = fb_build_rows([
        'action'      => $_POST['action_item'] ?? [],
        'responsible' => $_POST['action_responsible'] ?? [],
        'timeline'    => $_POST['action_timeline'] ?? [],
        'confidence'  => $_POST['action_confidence'] ?? [],
    ]);

    $challenge_rows = fb_build_rows([
        'challenge' => $_POST['challenge_text'] ?? [],
        'urgency'   => $_POST['challenge_urgency'] ?? [],
        'support'   => $_POST['challenge_support'] ?? [],
    ]);

    if ($submit_mode === 'submit') {
        if ($objectives === '' || $value_rating === '') {
            vp_flash('Please fill in at least the session objectives and value rating before submitting for review. Your progress has been saved as a draft.', 'error');
            $submit_mode = 'draft';
        }
    }

    $status = $submit_mode === 'submit' ? 'submitted' : 'draft';

    $discussion_json = json_encode($discussion_rows, JSON_UNESCAPED_UNICODE);
    $assessment_json = json_encode($assessment_rows, JSON_UNESCAPED_UNICODE);
    $action_json     = json_encode($action_rows, JSON_UNESCAPED_UNICODE);
    $challenge_json  = json_encode($challenge_rows, JSON_UNESCAPED_UNICODE);

    $stmt = $conn->prepare("
        INSERT INTO session_feedback_reports
            (session_id, venture_id, venture_name, founders_present, mentor_name,
             session_date, session_duration, mode_of_engagement, objectives,
             discussion_summary, mentor_assessment, value_rating, valuable_insight,
             recommendations, action_plan, progress_rating, progress_explain,
             challenges, future_support, satisfaction_rating, satisfaction_comments,
             recommend_mentor, feedback_for_admin, mentor_could_improve, status,
             submitted_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            venture_name           = VALUES(venture_name),
            founders_present       = VALUES(founders_present),
            mentor_name            = VALUES(mentor_name),
            session_date           = VALUES(session_date),
            session_duration       = VALUES(session_duration),
            mode_of_engagement     = VALUES(mode_of_engagement),
            objectives             = VALUES(objectives),
            discussion_summary     = VALUES(discussion_summary),
            mentor_assessment      = VALUES(mentor_assessment),
            value_rating           = VALUES(value_rating),
            valuable_insight       = VALUES(valuable_insight),
            recommendations        = VALUES(recommendations),
            action_plan            = VALUES(action_plan),
            progress_rating        = VALUES(progress_rating),
            progress_explain       = VALUES(progress_explain),
            challenges             = VALUES(challenges),
            future_support         = VALUES(future_support),
            satisfaction_rating    = VALUES(satisfaction_rating),
            satisfaction_comments  = VALUES(satisfaction_comments),
            recommend_mentor       = VALUES(recommend_mentor),
            feedback_for_admin     = VALUES(feedback_for_admin),
            mentor_could_improve   = VALUES(mentor_could_improve),
            status                 = VALUES(status),
            submitted_at           = VALUES(submitted_at)
    ");

    if (!$stmt) {
        vp_flash('DB error: ' . $conn->error, 'error');
        vp_redir('sessions.php?status=completed');
    }

    $submitted_at = $status === 'submitted' ? date('Y-m-d H:i:s') : null;

    $stmt->bind_param(
        'iisssssssssssssssssissssss',
        $session_id,
        $venture_id,
        $venture_name,
        $founders_present,
        $mentor_name,
        $session_date,
        $session_duration,
        $mode_of_engagement,
        $objectives,
        $discussion_json,
        $assessment_json,
        $value_rating,
        $valuable_insight,
        $recommendations,
        $action_json,
        $progress_rating,
        $progress_explain,
        $challenge_json,
        $future_support,
        $satisfaction_rating,
        $satisfaction_comments,
        $recommend_mentor,
        $feedback_for_admin,
        $mentor_could_improve,
        $status,
        $submitted_at
    );

    $ok  = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    if ($ok) {
        if ($status === 'submitted') {
            $ventureNameForNotify = (string)($VENTURE['name'] ?? $_SESSION['venture_name'] ?? '');

            pp_notify_session_mentors(
                $conn,
                $session_id,
                (int)$sess['mentor_id'],
                'feedback_submitted',
                (string)$sess['title'],
                $sess['scheduled_at'] ?? null,
                $ventureNameForNotify,
                ''
            );

            pp_notify_admins_feedback_submitted(
                $conn,
                $session_id,
                (string)$sess['title'],
                $ventureNameForNotify
            );

            vp_flash('Session report submitted for review. Thank you!');
        } else {
            vp_flash('Session report saved as a draft.');
        }
    } else {
        vp_flash('Failed to save session report: ' . $err, 'error');
    }

    vp_redir('sessions.php?status=completed');
}


// ==============================================================
//  CANCEL SESSION (requires a reason; notifies mentor)
// ==============================================================
if ($action === 'cancel_session') {
    $session_id    = (int)($_POST['session_id'] ?? 0);
    $cancel_reason = trim($_POST['cancel_reason'] ?? '');

    if ($session_id <= 0) {
        vp_flash('Invalid session.', 'error');
        vp_redir('sessions.php');
    }
    if ($cancel_reason === '') {
        vp_flash('Please provide a reason for cancelling this session.', 'error');
        vp_redir('sessions.php');
    }
    if (mb_strlen($cancel_reason) > 1000) {
        vp_flash('Reason is too long (max 1000 characters).', 'error');
        vp_redir('sessions.php');
    }

    if (!vp_owns_session($conn, $session_id, $venture_id)) {
        vp_flash('Session not found.', 'error');
        vp_redir('sessions.php');
    }

    $own = $conn->prepare("
        SELECT id, status, mentor_id, title, scheduled_at
        FROM mentor_sessions
        WHERE id = ?
        LIMIT 1
    ");
    $own->bind_param('i', $session_id);
    $own->execute();
    $row = $own->get_result()->fetch_assoc();
    $own->close();

    if (!$row) {
        vp_flash('Session not found.', 'error');
        vp_redir('sessions.php');
    }

    $uncancellable = ['completed', 'cancelled', 'no_show'];
    if (in_array($row['status'], $uncancellable, true)) {
        vp_flash('This session cannot be cancelled.', 'error');
        vp_redir('sessions.php');
    }

    if (pp_column_exists($conn, 'mentor_sessions', 'cancelled_by')) {
        $upd = $conn->prepare("
            UPDATE mentor_sessions
               SET status        = 'cancelled',
                   cancelled_at  = NOW(),
                   cancel_reason = ?,
                   cancelled_by  = 'venture'
             WHERE id = ?
             LIMIT 1
        ");
    } else {
        $upd = $conn->prepare("
            UPDATE mentor_sessions
               SET status        = 'cancelled',
                   cancelled_at  = NOW(),
                   cancel_reason = ?
             WHERE id = ?
             LIMIT 1
        ");
    }

    if (!$upd) {
        vp_flash('Cancellation failed: ' . $conn->error, 'error');
        vp_redir('sessions.php');
    }

    $upd->bind_param('si', $cancel_reason, $session_id);
    $ok  = $upd->execute();
    $err = $upd->error;
    $upd->close();

    if ($ok) {
        pp_notify_session_mentors(
            $conn,
            $session_id,
            (int)$row['mentor_id'],
            'cancelled',
            (string)$row['title'],
            $row['scheduled_at'] ?? null,
            (string)($VENTURE['name'] ?? $_SESSION['venture_name'] ?? ''),
            $cancel_reason
        );
        vp_flash('Session cancelled. Your mentor has been notified.');
    } else {
        vp_flash('Cancellation failed: ' . $err, 'error');
    }

    vp_redir('sessions.php');
}


if (!function_exists('meal_template_field_specs')) {
    function meal_template_field_specs(string $type): array
    {
        $yes_no = ['Yes', 'No'];

        $specs = [
            'participants' => [
                ['label' => 'Date (DD/MM/YYYY)',                      'options' => null, 'example' => date('d/m/Y')],
                ['label' => 'Learner Number (Unique ID)',                'options' => null, 'example' => 'UG-EDT-001'],
                ['label' => 'Full Name (First Name, Surname)',        'options' => null, 'example' => 'Jane Namukasa'],
                ['label' => 'Learner Status',                            'options' => ['New Learner', 'Returning Learner'], 'example' => 'New Learner'],
                ['label' => 'Gender',                                 'options' => ['Male', 'Female'], 'example' => 'Female'],
                ['label' => 'Specific Location (District/Village)',   'options' => null, 'example' => 'Kampala / Makindye'],
                ['label' => 'Urban/Rural',                          'options' => ['Urban', 'Peri-Urban', 'Rural'], 'example' => 'Urban'],
                ['label' => 'Age Category',                           'options' => ['13-20', '21-35', 'Above 35'], 'example' => '21-35'],
                ['label' => 'Displaced/Refugee Status',               'options' => $yes_no, 'example' => 'No'],
                ['label' => 'Refugee Settlement Name',                'options' => null, 'example' => ''],
                ['label' => 'PWD Status',                             'options' => $yes_no, 'example' => 'No'],
                ['label' => 'Type of Impairment',                     'options' => null, 'example' => ''],
                ['label' => 'Education Level',                        'options' => ['Primary', 'Secondary', 'Tertiary'], 'example' => 'Secondary'],
                ['label' => 'Currently Working at Point of Entry (occupation or No)', 'options' => null, 'example' => 'No'],
                ['label' => 'Prior MCF Beneficiary',                  'options' => $yes_no, 'example' => 'No'],
                ['label' => 'Transformation Objective',               'options' => null, 'example' => 'Education & Skilling'],
                ['label' => 'After-work Status',      'options' => ['New', 'Additional', 'Improved'], 'example' => 'New'],
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
                ['label' => 'Full Name',                               'options' => null, 'example' => 'Peter Okello'],
                ['label' => 'Gender',                                  'options' => ['Male', 'Female', 'Other'], 'example' => 'Male'],
                ['label' => 'Age Category',                            'options' => ['13-20', '21-35', 'Above 35'], 'example' => '21-35'],
                ['label' => 'Refugee/Displaced Status',                'options' => $yes_no, 'example' => 'No'],
                ['label' => 'Host Community',                          'options' => $yes_no, 'example' => 'No'],
                ['label' => 'Urban/Rural',                           'options' => ['Rural', 'Urban', 'Peri-Urban'], 'example' => 'Urban'],
                ['label' => 'PWD Status',                              'options' => $yes_no, 'example' => 'No'],
                ['label' => 'Education Level',                         'options' => ['Primary', 'Secondary', 'Tertiary'], 'example' => 'Tertiary'],
                ['label' => 'Current Occupation / Employment Status',  'options' => null, 'example' => 'Self-employed'],
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

        $lastValidationRow = 500;

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

        echo "\xEF\xBB\xBF";
        $fh = fopen('php://output', 'w');
        fputcsv($fh, array_column($fields, 'label'));
        fputcsv($fh, array_map(fn($f) => $f['example'] ?? '', $fields));
        fclose($fh);
        exit;
    }
}

if ($action === 'download_meal_csv') {
    $type = $_GET['type'] ?? 'participants';
    $vid  = (int)($_GET['venture_id'] ?? $venture_id);

    if ($vid !== $venture_id) {
        http_response_code(403);
        exit;
    }

    $fields = meal_template_field_specs($type);

    if (!$fields) {
        http_response_code(400);
        echo 'Invalid type.';
        exit;
    }

    meal_stream_xlsx_template($fields, $type . '_template');
}



if ($action === 'upload_meal_csv') {
    $type   = trim($_POST['type'] ?? '');
    $ven_id = (int)($_POST['venture_id'] ?? 0);

    if ($ven_id !== $venture_id) {
        vp_flash('Permission denied.', 'error');
        vp_redir('meal-data.php');
    }

    $allowed_types = ['participants', 'teachers', 'schools', 'other_users', 'employment', 'finance', 'partnerships', 'revenue'];
    if (!in_array($type, $allowed_types, true)) {
        vp_flash('Invalid data type.', 'error');
        vp_redir('meal-data.php');
    }

    if (empty($_FILES['csv_file']['name'])) {
        vp_flash('Please select a CSV or Excel file.', 'error');
        vp_redir('meal-data.php?tab=' . urlencode($type));
    }

    if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        vp_flash('File upload failed. Error code: ' . (int)$_FILES['csv_file']['error'], 'error');
        vp_redir('meal-data.php?tab=' . urlencode($type));
    }

    $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
    $allowed_upload_exts = ['csv', 'xlsx', 'xls'];
    if (!in_array($ext, $allowed_upload_exts, true)) {
        vp_flash('Only CSV or Excel (.xlsx/.xls) files are allowed.', 'error');
        vp_redir('meal-data.php?tab=' . urlencode($type));
    }

    if ((int)$_FILES['csv_file']['size'] > 20 * 1024 * 1024) {
        vp_flash('File must be under 20 MB.', 'error');
        vp_redir('meal-data.php?tab=' . urlencode($type));
    }

    // Table existence is already guaranteed near the top of this file via
    // meal_ensure_all_meal_tables($conn), so no per-type call is needed here.

    $dataRows = [];

    if ($ext === 'csv') {
        $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$fh) {
            vp_flash('Could not read uploaded CSV file.', 'error');
            vp_redir('meal-data.php?tab=' . urlencode($type));
        }

        $headerRow = fgetcsv($fh);
        if (!$headerRow) {
            fclose($fh);
            vp_flash('Empty or invalid CSV.', 'error');
            vp_redir('meal-data.php?tab=' . urlencode($type));
        }

        while (($row = fgetcsv($fh)) !== false) {
            $dataRows[] = $row;
        }
        fclose($fh);
    } else {
        $autoload_candidates = [
            __DIR__ . '/vendor/autoload.php',
            dirname(__DIR__) . '/vendor/autoload.php',
        ];
        $spreadsheet_loaded = false;
        foreach ($autoload_candidates as $autoload) {
            if (file_exists($autoload)) {
                require_once $autoload;
                $spreadsheet_loaded = true;
                break;
            }
        }

        if (!$spreadsheet_loaded || !class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
            vp_flash('Excel upload support is not installed on the server (composer require phpoffice/phpspreadsheet). Please save your file as CSV and upload that instead.', 'error');
            vp_redir('meal-data.php?tab=' . urlencode($type));
        }

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($_FILES['csv_file']['tmp_name']);
            $sheetData   = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        } catch (Throwable $e) {
            vp_flash('Could not read the uploaded Excel file: ' . $e->getMessage(), 'error');
            vp_redir('meal-data.php?tab=' . urlencode($type));
        }

        if (empty($sheetData)) {
            vp_flash('Empty or invalid Excel file.', 'error');
            vp_redir('meal-data.php?tab=' . urlencode($type));
        }

        $dataRows = array_slice($sheetData, 1);
    }


    $inserted = 0;
    $skipped  = 0;
    $errors   = [];

    $parse_date = function ($raw): string {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return date('Y-m-d');
        }

        foreach (['d/m/Y', 'Y-m-d', 'm/d/Y', 'd-m-Y', 'd.m.Y'] as $fmt) {
            $d = DateTime::createFromFormat($fmt, $raw);
            if ($d instanceof DateTime) {
                return $d->format('Y-m-d');
            }
        }

        $time = strtotime($raw);
        return $time ? date('Y-m-d', $time) : date('Y-m-d');
    };

    $clean_money = function ($raw): float {
        $raw = str_replace([',', 'UGX', 'USD', 'ugx', 'usd', ' '], '', (string)$raw);
        return is_numeric($raw) ? (float)$raw : 0.0;
    };

    foreach ($dataRows as $row) {
        $row = array_map(static fn($v) => trim((string)($v ?? '')), $row);

        if (implode('', $row) === '') {
            continue;
        }

        try {
            if ($type === 'participants') {
                // Participant import order mirrors the current Learners template.
                $entry_date                     = $parse_date($row[0] ?? '');
                $user_number                    = $row[1] ?? '';
                $full_name                      = $row[2] ?? '';
                $phone                          = $row[3] ?? '';
                $email                          = $row[4] ?? '';
                $user_status                    = $row[5] ?? 'Active';
                $gender                         = $row[6] ?? '';
                $specific_location              = $row[7] ?? '';
                $location_type                  = $row[8] ?? '';
                $age_category                   = $row[9] ?? '';
                $refugee_status                 = $row[10] ?? 'No';
                $refugee_settlement             = $row[11] ?? '';
                $refugee_settlement_other       = $row[12] ?? '';
                $pwd_status                     = $row[13] ?? 'No';
                $impairment_type                = $row[14] ?? '';
                $education_level                = $row[15] ?? '';
                $verified_learner_outcome       = $row[16] ?? '';
                $verified_learner_outcome_other = $row[17] ?? '';
                $working_at_entry               = $row[18] ?? '';
                $prior_mcf                      = $row[19] ?? 'No';
                $transformation_objective       = $row[20] ?? '';
                $in_work_status                 = $row[21] ?? '';
                $after_work_pathway              = $row[22] ?? '';

                $valid_statuses = ['Active', 'On hold', 'Dropped', 'Completed', 'Certified'];
                if (!in_array($user_status, $valid_statuses, true)) {
                    $user_status = 'Active';
                }

                if ($age_category === '' || !ctype_digit((string)$age_category) || (int)$age_category < 1 || (int)$age_category > 120) {
                    $skipped++;
                    $errors[] = 'Skipped learner ' . ($user_number ?: '(no ID)') . ': invalid age.';
                    continue;
                }
                $age_category = (string)(int)$age_category;

                if ($education_level !== '' && !in_array($education_level, ['Primary', 'Secondary', 'TVET', 'Tertiary'], true)) {
                    $education_level = '';
                }

                $valid_outcomes = ['', 'Increased pass rates', 'Improved grades in STEM', 'Increased Agency and Voice', 'Others please specify'];
                if (!in_array($verified_learner_outcome, $valid_outcomes, true)) {
                    $verified_learner_outcome = '';
                    $verified_learner_outcome_other = '';
                } elseif ($verified_learner_outcome !== 'Others please specify') {
                    $verified_learner_outcome_other = '';
                }

                if ($refugee_status === 'Yes' && $refugee_settlement === 'Other (please specify)' && $refugee_settlement_other !== '') {
                    $refugee_settlement = $refugee_settlement_other;
                }

                if ($user_number === '') {
                    $skipped++;
                    continue;
                }

                $dup = $conn->prepare("
                    SELECT id
                    FROM venture_participants
                    WHERE venture_id = ? AND user_number = ?
                    LIMIT 1
                ");
                $dup->bind_param('is', $venture_id, $user_number);
                $dup->execute();
                $dup_res = $dup->get_result();
                $exists = $dup_res && $dup_res->num_rows > 0;
                $dup->close();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                $stmt = $conn->prepare("
                    INSERT INTO venture_participants
                        (venture_id, entry_date, user_number, full_name, phone, email, user_status, gender,
                         specific_location, location_type, age_category, refugee_status,
                         refugee_settlement, pwd_status, impairment_type, education_level,
                         verified_learner_outcome, verified_learner_outcome_other,
                         working_at_entry, prior_mcf, transformation_objective, in_work_status, after_work_pathway)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ");

                if (!$stmt) {
                    throw new Exception($conn->error);
                }

                $stmt->bind_param(
                    'issssssssssssssssssssss',
                    $venture_id,
                    $entry_date,
                    $user_number,
                    $full_name,
                    $phone,
                    $email,
                    $user_status,
                    $gender,
                    $specific_location,
                    $location_type,
                    $age_category,
                    $refugee_status,
                    $refugee_settlement,
                    $pwd_status,
                    $impairment_type,
                    $education_level,
                    $verified_learner_outcome,
                    $verified_learner_outcome_other,
                    $working_at_entry,
                    $prior_mcf,
                    $transformation_objective,
                    $in_work_status,
                    $after_work_pathway
                );

                if (!$stmt->execute()) {
                    throw new Exception($stmt->error);
                }

                $stmt->close();
                $inserted++;
                continue;
            }

            if ($type === 'teachers') {
                $entry_date     = $parse_date($row[0] ?? '');
                $teacher_name   = $row[1] ?? '';
                $gender         = $row[2] ?? '';
                $age_category   = trim((string)($row[3] ?? ''));
                $refugee_status = ($row[4] ?? 'No');
                $host_community = ($row[5] ?? 'No');
                $location_type  = $row[6] ?? '';
                $pwd_status     = ($row[7] ?? 'No');

                $refugee_status = strcasecmp(trim($refugee_status), 'yes') === 0 ? 'Yes' : 'No';
                $host_community = strcasecmp(trim($host_community), 'yes') === 0 ? 'Yes' : 'No';
                $pwd_status     = strcasecmp(trim($pwd_status), 'yes') === 0 ? 'Yes' : 'No';

                $stmt = $conn->prepare("
                    INSERT INTO meal_teachers
                        (venture_id, entry_date, teacher_name, gender, age_category,
                         refugee_status, host_community, location_type, pwd_status)
                    VALUES (?,?,?,?,?,?,?,?,?)
                ");

                if (!$stmt) {
                    throw new Exception($conn->error);
                }

                $stmt->bind_param(
                    'issssssss',
                    $venture_id,
                    $entry_date,
                    $teacher_name,
                    $gender,
                    $age_category,
                    $refugee_status,
                    $host_community,
                    $location_type,
                    $pwd_status
                );

                if (!$stmt->execute()) {
                    throw new Exception($stmt->error);
                }

                $stmt->close();
                $inserted++;
                continue;
            }

            if ($type === 'schools') {
                $entry_date     = $parse_date($row[0] ?? '');
                $school_name    = $row[1] ?? '';
                $location_type  = $row[2] ?? '';
                $ownership_type = $row[3] ?? '';
                $school_level   = $row[4] ?? '';

                if ($school_name === '') {
                    $skipped++;
                    continue;
                }

                $stmt = $conn->prepare("
                    INSERT INTO meal_schools
                        (venture_id, entry_date, school_name, location_type, ownership_type, school_level)
                    VALUES (?,?,?,?,?,?)
                ");

                if (!$stmt) {
                    throw new Exception($conn->error);
                }

                $stmt->bind_param(
                    'isssss',
                    $venture_id,
                    $entry_date,
                    $school_name,
                    $location_type,
                    $ownership_type,
                    $school_level
                );

                if (!$stmt->execute()) {
                    throw new Exception($stmt->error);
                }

                $stmt->close();
                $inserted++;
                continue;
            }

            if ($type === 'other_users') {
                $entry_date        = $parse_date($row[0] ?? '');
                $full_name         = $row[1] ?? '';
                $gender            = $row[2] ?? '';
                $age_category      = trim((string)($row[3] ?? ''));
                $refugee_status    = ($row[4] ?? 'No');
                $host_community    = ($row[5] ?? 'No');
                $location_type     = $row[6] ?? '';
                $pwd_status        = ($row[7] ?? 'No');
                $education_level   = $row[8] ?? '';
                $occupation_status = $row[9] ?? '';

                $refugee_status = strcasecmp(trim($refugee_status), 'yes') === 0 ? 'Yes' : 'No';
                $host_community = strcasecmp(trim($host_community), 'yes') === 0 ? 'Yes' : 'No';
                $pwd_status     = strcasecmp(trim($pwd_status), 'yes') === 0 ? 'Yes' : 'No';

                $stmt = $conn->prepare("
                    INSERT INTO meal_other_users
                        (venture_id, entry_date, full_name, gender, age_category,
                         refugee_status, host_community, location_type, pwd_status,
                         education_level, occupation_status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)
                ");

                if (!$stmt) {
                    throw new Exception($conn->error);
                }

                $stmt->bind_param(
                    'issssssssss',
                    $venture_id,
                    $entry_date,
                    $full_name,
                    $gender,
                    $age_category,
                    $refugee_status,
                    $host_community,
                    $location_type,
                    $pwd_status,
                    $education_level,
                    $occupation_status
                );

                if (!$stmt->execute()) {
                    throw new Exception($stmt->error);
                }

                $stmt->close();
                $inserted++;
                continue;
            }

            if ($type === 'employment') {
                $placement_date  = $parse_date($row[0] ?? '');
                $full_name       = $row[1] ?? '';
                $gender          = $row[2] ?? '';
                $age_category    = $row[3] ?? '';
                $pwd_status      = $row[4] ?? 'No';
                $employment_type = $row[5] ?? '';
                $job_title       = $row[6] ?? '';
                $status          = $row[7] ?? 'Active';

                if ($full_name === '' || $job_title === '') {
                    $skipped++;
                    continue;
                }

                $stmt = $conn->prepare("
                    INSERT INTO meal_employment
                        (venture_id, placement_date, full_name, gender, age_category,
                         pwd_status, employment_type, job_title, status)
                    VALUES (?,?,?,?,?,?,?,?,?)
                ");

                if (!$stmt) {
                    throw new Exception($conn->error);
                }

                $stmt->bind_param(
                    'issssssss',
                    $venture_id,
                    $placement_date,
                    $full_name,
                    $gender,
                    $age_category,
                    $pwd_status,
                    $employment_type,
                    $job_title,
                    $status
                );

                if (!$stmt->execute()) {
                    throw new Exception($stmt->error);
                }

                $stmt->close();
                $inserted++;
                continue;
            }

            if ($type === 'finance') {
                $date_mobilised = $parse_date($row[0] ?? '');
                $finance_usd    = $clean_money($row[1] ?? 0);
                $funding_form   = $row[2] ?? '';
                $funding_source = $row[3] ?? '';

                if ($finance_usd <= 0) {
                    $skipped++;
                    continue;
                }

                $stmt = $conn->prepare("
                    INSERT INTO meal_finance
                        (venture_id, date_mobilised, finance_usd, funding_form, funding_source)
                    VALUES (?,?,?,?,?)
                ");

                if (!$stmt) {
                    throw new Exception($conn->error);
                }

                $stmt->bind_param(
                    'isdss',
                    $venture_id,
                    $date_mobilised,
                    $finance_usd,
                    $funding_form,
                    $funding_source
                );

                if (!$stmt->execute()) {
                    throw new Exception($stmt->error);
                }

                $stmt->close();
                $inserted++;
                continue;
            }

            if ($type === 'partnerships') {
                $partner_date       = $parse_date($row[0] ?? '');
                $partner_name       = $row[1] ?? '';
                $partnership_type   = $row[2] ?? '';
                $partnership_status = $row[3] ?? 'Active';

                if ($partner_name === '') {
                    $skipped++;
                    continue;
                }

                $stmt = $conn->prepare("
                    INSERT INTO meal_partnerships
                        (venture_id, partner_date, partner_name, partnership_type, partnership_status)
                    VALUES (?,?,?,?,?)
                ");

                if (!$stmt) {
                    throw new Exception($conn->error);
                }

                $stmt->bind_param(
                    'issss',
                    $venture_id,
                    $partner_date,
                    $partner_name,
                    $partnership_type,
                    $partnership_status
                );

                if (!$stmt->execute()) {
                    throw new Exception($stmt->error);
                }

                $stmt->close();
                $inserted++;
                continue;
            }

            if ($type === 'revenue') {
                $revenue_date      = $parse_date($row[0] ?? '');
                $gross_revenue_ugx = $clean_money($row[1] ?? 0);
                $revenue_stream    = $row[2] ?? '';

                if ($gross_revenue_ugx <= 0) {
                    $skipped++;
                    continue;
                }

                $stmt = $conn->prepare("
                    INSERT INTO meal_revenue
                        (venture_id, revenue_date, gross_revenue_ugx, revenue_stream)
                    VALUES (?,?,?,?)
                ");

                if (!$stmt) {
                    throw new Exception($conn->error);
                }

                $stmt->bind_param(
                    'isds',
                    $venture_id,
                    $revenue_date,
                    $gross_revenue_ugx,
                    $revenue_stream
                );

                if (!$stmt->execute()) {
                    throw new Exception($stmt->error);
                }

                $stmt->close();
                $inserted++;
                continue;
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    $msg = "Imported {$inserted} record(s).";
    if ($skipped > 0) {
        $msg .= " {$skipped} row(s) skipped (missing required field or duplicate).";
    }
    if (!empty($errors)) {
        $msg .= ' Errors: ' . implode('; ', array_slice(array_unique($errors), 0, 3));
    }

    vp_flash($msg, $inserted > 0 ? 'success' : 'error');
    vp_redir('meal-data.php?tab=' . urlencode($type));
}


// ==============================================================
//  POST: ADD SINGLE BENEFICIARY
// ==============================================================
if ($action === 'add_meal_beneficiary') {
    $ven_id = (int)($_POST['venture_id'] ?? 0);
    if ($ven_id !== $venture_id) { vp_flash('Permission denied.', 'error'); vp_redir('meal-data.php'); }

    $user_number = trim($_POST['user_number'] ?? '');
    if ($user_number === '') { vp_flash('Learner number is required.', 'error'); vp_redir('meal-data.php'); }

    $dup = $conn->prepare("SELECT id FROM venture_participants WHERE venture_id = ? AND user_number = ? LIMIT 1");
    $dup->bind_param('is', $venture_id, $user_number);
    $dup->execute();
    $dup_exists = $dup->get_result()->num_rows > 0;
    $dup->close();

    if ($dup_exists) {
        vp_flash('A learner with that Learner Number already exists.', 'error');
        vp_redir('meal-data.php?tab=participants');
    }

    $entry_date  = trim($_POST['entry_date'] ?? date('Y-m-d'));
    $full_name   = trim($_POST['full_name'] ?? '');
    $user_status = trim($_POST['user_status'] ?? 'New User');
    if (strcasecmp($user_status, 'New Learner') === 0) $user_status = 'New User';
    if (strcasecmp($user_status, 'Returning Learner') === 0) $user_status = 'Returning User';
    $gender      = trim($_POST['gender'] ?? '');
    $location    = trim($_POST['specific_location'] ?? '');
    $loc_type    = trim($_POST['location_type'] ?? '');
    $age_cat     = trim($_POST['age_category'] ?? '');
    $refugee     = $_POST['refugee_status'] ?? 'No';
    $settlement  = trim($_POST['refugee_settlement'] ?? '');
    $pwd         = $_POST['pwd_status'] ?? 'No';
    $impairment  = trim($_POST['impairment_type'] ?? '');
    $education   = trim($_POST['education_level'] ?? '');
    $working     = trim($_POST['working_at_entry'] ?? '');
    $prior_mcf   = $_POST['prior_mcf'] ?? 'No';
    $trans_obj   = trim($_POST['transformation_objective'] ?? '');
    $in_work     = trim($_POST['in_work_status'] ?? '');

    $stmt = $conn->prepare("
        INSERT INTO venture_participants
            (venture_id, entry_date, user_number, full_name, user_status, gender,
             specific_location, location_type, age_category, refugee_status,
             refugee_settlement, pwd_status, impairment_type, education_level,
             working_at_entry, prior_mcf, transformation_objective, in_work_status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    if (!$stmt) { vp_flash('DB error: ' . $conn->error, 'error'); vp_redir('meal-data.php?tab=participants'); }
    $stmt->bind_param('isssssssssssssssss',
        $venture_id, $entry_date, $user_number, $full_name, $user_status, $gender,
        $location, $loc_type, $age_cat, $refugee, $settlement,
        $pwd, $impairment, $education, $working, $prior_mcf, $trans_obj, $in_work
    );
    if ($stmt->execute()) {
        vp_flash('Learner record saved.');
    } else {
        vp_flash('Save failed: ' . $stmt->error, 'error');
    }
    $stmt->close();
    vp_redir('meal-data.php?tab=participants');
}


// ==============================================================
//  POST: EDIT LEARNER / BENEFICIARY
// ==============================================================
if ($action === 'edit_meal_beneficiary') {
    $participant_id =
        (int)(
            $_POST['id']
            ?? 0
        );

    $ven_id =
        (int)(
            $_POST['venture_id']
            ?? 0
        );

    if (
        $ven_id !== $venture_id
        || $participant_id <= 0
    ) {
        vp_flash(
            'Permission denied or invalid learner.',
            'error'
        );

        vp_redir(
            'meal-data.php?tab=participants'
        );
    }

    $entry_date =
        trim(
            (string)(
                $_POST['entry_date']
                ?? date('Y-m-d')
            )
        );

    $user_number =
        trim(
            (string)(
                $_POST['user_number']
                ?? ''
            )
        );

    $full_name =
        trim(
            (string)(
                $_POST['full_name']
                ?? ''
            )
        );

    $user_status =
        trim(
            (string)(
                $_POST['user_status']
                ?? 'New User'
            )
        );

    $gender =
        trim(
            (string)(
                $_POST['gender']
                ?? ''
            )
        );

    $specific_location =
        trim(
            (string)(
                $_POST['specific_location']
                ?? ''
            )
        );

    $location_type =
        trim(
            (string)(
                $_POST['location_type']
                ?? ''
            )
        );

    $age_category =
        trim(
            (string)(
                $_POST['age_category']
                ?? ''
            )
        );

    $refugee_status =
        trim(
            (string)(
                $_POST['refugee_status']
                ?? 'No'
            )
        );

    $refugee_settlement =
        trim(
            (string)(
                $_POST['refugee_settlement']
                ?? ''
            )
        );

    $pwd_status =
        trim(
            (string)(
                $_POST['pwd_status']
                ?? 'No'
            )
        );

    $impairment_type =
        trim(
            (string)(
                $_POST['impairment_type']
                ?? ''
            )
        );

    $education_level =
        trim(
            (string)(
                $_POST['education_level']
                ?? ''
            )
        );

    $working_at_entry =
        trim(
            (string)(
                $_POST['working_at_entry']
                ?? ''
            )
        );

    $prior_mcf =
        trim(
            (string)(
                $_POST['prior_mcf']
                ?? 'No'
            )
        );

    $transformation_objective =
        trim(
            (string)(
                $_POST['transformation_objective']
                ?? ''
            )
        );

    $in_work_status =
        trim(
            (string)(
                $_POST['in_work_status']
                ?? ''
            )
        );

    if ($user_number === '') {
        vp_flash(
            'Learner number is required.',
            'error'
        );

        vp_redir(
            'meal-data.php?tab=participants'
        );
    }

    $allowedStatus = [
        'New User',
        'Returning User',
    ];

    if (
        !in_array(
            $user_status,
            $allowedStatus,
            true
        )
    ) {
        $user_status =
            'New User';
    }

    $dup = $conn->prepare("
        SELECT id
        FROM venture_participants
        WHERE venture_id = ?
          AND user_number = ?
          AND id <> ?
        LIMIT 1
    ");

    if ($dup) {
        $dup->bind_param(
            'isi',
            $venture_id,
            $user_number,
            $participant_id
        );

        $dup->execute();

        $duplicate =
            $dup
                ->get_result()
                ->fetch_assoc();

        $dup->close();

        if ($duplicate) {
            vp_flash(
                'Another learner already has that Learner Number.',
                'error'
            );

            vp_redir(
                'meal-data.php?tab=participants'
            );
        }
    }

    if (
        $refugee_status !== 'Yes'
    ) {
        $refugee_settlement =
            '';
    }

    if (
        $pwd_status !== 'Yes'
    ) {
        $impairment_type =
            '';
    }

    $stmt = $conn->prepare("
        UPDATE venture_participants
        SET
            entry_date = ?,
            user_number = ?,
            full_name = ?,
            user_status = ?,
            gender = ?,
            specific_location = ?,
            location_type = ?,
            age_category = ?,
            refugee_status = ?,
            refugee_settlement = ?,
            pwd_status = ?,
            impairment_type = ?,
            education_level = ?,
            working_at_entry = ?,
            prior_mcf = ?,
            transformation_objective = ?,
            in_work_status = ?
        WHERE id = ?
          AND venture_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        vp_flash(
            'Update failed: '
            . $conn->error,
            'error'
        );

        vp_redir(
            'meal-data.php?tab=participants'
        );
    }

    $stmt->bind_param(
        'sssssssssssssssssii',
        $entry_date,
        $user_number,
        $full_name,
        $user_status,
        $gender,
        $specific_location,
        $location_type,
        $age_category,
        $refugee_status,
        $refugee_settlement,
        $pwd_status,
        $impairment_type,
        $education_level,
        $working_at_entry,
        $prior_mcf,
        $transformation_objective,
        $in_work_status,
        $participant_id,
        $venture_id
    );

    if ($stmt->execute()) {
        vp_flash(
            'Learner record updated successfully.'
        );
    } else {
        vp_flash(
            'Update failed: '
            . $stmt->error,
            'error'
        );
    }

    $stmt->close();

    vp_redir(
        'meal-data.php?tab=participants'
    );
}


// ==============================================================
//  POST: ADD SINGLE TEACHER / EDUCATOR RECORD
// ==============================================================
if ($action === 'add_meal_teacher') {
    $ven_id = (int)($_POST['venture_id'] ?? 0);
    if ($ven_id !== $venture_id) { vp_flash('Permission denied.', 'error'); vp_redir('meal-data.php'); }

    $entry_date     = trim($_POST['entry_date'] ?? date('Y-m-d'));
    $teacher_name   = trim($_POST['teacher_name'] ?? '');
    $gender         = trim($_POST['gender'] ?? '');
    $age_category   = trim($_POST['age_category'] ?? '');
    $refugee_status = $_POST['refugee_status'] ?? 'No';
    $host_community = $_POST['host_community'] ?? 'No';
    $location_type  = trim($_POST['location_type'] ?? '');
    $pwd_status     = $_POST['pwd_status'] ?? 'No';

    $allowed_gender   = ['', 'Male', 'Female', 'Other'];
    $allowed_age      = ['', '13-20', '21-35', 'Above 35'];
    $allowed_location = ['', 'Rural', 'Urban', 'Peri-Urban'];
    $allowed_yes_no   = ['Yes', 'No'];

    if (!in_array($gender, $allowed_gender, true)) $gender = '';
    if (!in_array($age_category, $allowed_age, true)) $age_category = '';
    if (!in_array($location_type, $allowed_location, true)) $location_type = '';
    if (!in_array($refugee_status, $allowed_yes_no, true)) $refugee_status = 'No';
    if (!in_array($host_community, $allowed_yes_no, true)) $host_community = 'No';
    if (!in_array($pwd_status, $allowed_yes_no, true)) $pwd_status = 'No';

    $stmt = $conn->prepare("
        INSERT INTO meal_teachers
            (venture_id, entry_date, teacher_name, gender, age_category,
             refugee_status, host_community, location_type, pwd_status)
        VALUES (?,?,?,?,?,?,?,?,?)
    ");
    if (!$stmt) { vp_flash('DB error: ' . $conn->error, 'error'); vp_redir('meal-data.php?tab=teachers'); }
    $stmt->bind_param('issssssss',
        $venture_id, $entry_date, $teacher_name, $gender, $age_category,
        $refugee_status, $host_community, $location_type, $pwd_status
    );
    if ($stmt->execute()) {
        vp_flash('Teacher / Educator record saved.');
    } else {
        vp_flash('Save failed: ' . $stmt->error, 'error');
    }
    $stmt->close();
    vp_redir('meal-data.php?tab=teachers');
}


// ==============================================================
//  POST: ADD SINGLE SCHOOL / INSTITUTION RECORD
// ==============================================================
if ($action === 'add_meal_school') {
    $ven_id = (int)($_POST['venture_id'] ?? 0);
    if ($ven_id !== $venture_id) { vp_flash('Permission denied.', 'error'); vp_redir('meal-data.php'); }

    $school_name = trim($_POST['school_name'] ?? '');
    if ($school_name === '') {
        vp_flash('School / institution name is required.', 'error');
        vp_redir('meal-data.php?tab=schools');
    }

    $entry_date     = trim($_POST['entry_date'] ?? date('Y-m-d'));
    $location_type  = trim($_POST['location_type'] ?? '');
    $ownership_type = trim($_POST['ownership_type'] ?? '');
    $school_level   = trim($_POST['school_level'] ?? '');

    $allowed_location  = ['', 'Rural', 'Urban', 'Peri-Urban'];
    $allowed_ownership = ['', 'Government-aided', 'Private'];
    $allowed_level     = ['', 'Secondary', 'Tertiary', 'BTVET'];

    if (!in_array($location_type, $allowed_location, true)) $location_type = '';
    if (!in_array($ownership_type, $allowed_ownership, true)) $ownership_type = '';
    if (!in_array($school_level, $allowed_level, true)) $school_level = '';

    $stmt = $conn->prepare("
        INSERT INTO meal_schools
            (venture_id, entry_date, school_name, location_type, ownership_type, school_level)
        VALUES (?,?,?,?,?,?)
    ");
    if (!$stmt) { vp_flash('DB error: ' . $conn->error, 'error'); vp_redir('meal-data.php?tab=schools'); }
    $stmt->bind_param('isssss',
        $venture_id, $entry_date, $school_name, $location_type, $ownership_type, $school_level
    );
    if ($stmt->execute()) {
        vp_flash('School / institution record saved.');
    } else {
        vp_flash('Save failed: ' . $stmt->error, 'error');
    }
    $stmt->close();
    vp_redir('meal-data.php?tab=schools');
}


// ==============================================================
//  POST: ADD SINGLE OTHER USER RECORD
// ==============================================================
if ($action === 'add_meal_other_user') {
    $ven_id = (int)($_POST['venture_id'] ?? 0);
    if ($ven_id !== $venture_id) { vp_flash('Permission denied.', 'error'); vp_redir('meal-data.php'); }

    $entry_date        = trim($_POST['entry_date'] ?? date('Y-m-d'));
    $full_name         = trim($_POST['full_name'] ?? '');
    $gender            = trim($_POST['gender'] ?? '');
    $age_category      = trim($_POST['age_category'] ?? '');
    $refugee_status    = $_POST['refugee_status'] ?? 'No';
    $host_community    = $_POST['host_community'] ?? 'No';
    $location_type     = trim($_POST['location_type'] ?? '');
    $pwd_status        = $_POST['pwd_status'] ?? 'No';
    $education_level   = trim($_POST['education_level'] ?? '');
    $occupation_status = trim($_POST['occupation_status'] ?? '');

    $allowed_gender    = ['', 'Male', 'Female', 'Other'];
    $allowed_age       = ['', '13-20', '21-35', 'Above 35'];
    $allowed_location  = ['', 'Rural', 'Urban', 'Peri-Urban'];
    $allowed_yes_no    = ['Yes', 'No'];
    $allowed_education = ['', 'Primary', 'Secondary', 'Tertiary'];

    if (!in_array($gender, $allowed_gender, true)) $gender = '';
    if (!in_array($age_category, $allowed_age, true)) $age_category = '';
    if (!in_array($location_type, $allowed_location, true)) $location_type = '';
    if (!in_array($refugee_status, $allowed_yes_no, true)) $refugee_status = 'No';
    if (!in_array($host_community, $allowed_yes_no, true)) $host_community = 'No';
    if (!in_array($pwd_status, $allowed_yes_no, true)) $pwd_status = 'No';
    if (!in_array($education_level, $allowed_education, true)) $education_level = '';

    $stmt = $conn->prepare("
        INSERT INTO meal_other_users
            (venture_id, entry_date, full_name, gender, age_category,
             refugee_status, host_community, location_type, pwd_status,
             education_level, occupation_status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ");
    if (!$stmt) { vp_flash('DB error: ' . $conn->error, 'error'); vp_redir('meal-data.php?tab=other_users'); }
    $stmt->bind_param('issssssssss',
        $venture_id, $entry_date, $full_name, $gender, $age_category,
        $refugee_status, $host_community, $location_type, $pwd_status,
        $education_level, $occupation_status
    );
    if ($stmt->execute()) {
        vp_flash('Other user record saved.');
    } else {
        vp_flash('Save failed: ' . $stmt->error, 'error');
    }
    $stmt->close();
    vp_redir('meal-data.php?tab=other_users');
}


// ==============================================================
//  POST: ADD SINGLE EMPLOYMENT RECORD
// ==============================================================
if ($action === 'add_meal_employment') {
    $ven_id = (int)($_POST['venture_id'] ?? 0);
    if ($ven_id !== $venture_id) { vp_flash('Permission denied.', 'error'); vp_redir('meal-data.php'); }

    $full_name = trim($_POST['full_name'] ?? '');
    $job_title = trim($_POST['job_title'] ?? '');
    if ($full_name === '' || $job_title === '') {
        vp_flash('Name and job title are required.', 'error');
        vp_redir('meal-data.php?tab=employment');
    }

    $placement = trim($_POST['placement_date'] ?? date('Y-m-d'));
    $gender    = trim($_POST['gender'] ?? '');
    $age       = trim($_POST['age_category'] ?? '');
    $pwd       = $_POST['pwd_status'] ?? 'No';
    $emp_type  = trim($_POST['employment_type'] ?? '');
    $status    = trim($_POST['status'] ?? 'Active');

    $stmt = $conn->prepare("
        INSERT INTO meal_employment
            (venture_id, placement_date, full_name, gender, age_category,
             pwd_status, employment_type, job_title, status)
        VALUES (?,?,?,?,?,?,?,?,?)
    ");
    if (!$stmt) { vp_flash('DB error: ' . $conn->error, 'error'); vp_redir('meal-data.php?tab=employment'); }
    $stmt->bind_param('issssssss',
        $venture_id, $placement, $full_name, $gender, $age,
        $pwd, $emp_type, $job_title, $status
    );
    if ($stmt->execute()) {
        vp_flash('Employment record saved.');
    } else {
        vp_flash('Save failed: ' . $stmt->error, 'error');
    }
    $stmt->close();
    vp_redir('meal-data.php?tab=employment');
}


// ==============================================================
//  POST: ADD SINGLE FINANCE RECORD
// ==============================================================
if ($action === 'add_meal_finance') {
    $ven_id = (int)($_POST['venture_id'] ?? 0);
    if ($ven_id !== $venture_id) { vp_flash('Permission denied.', 'error'); vp_redir('meal-data.php'); }

    $date        = trim($_POST['date_mobilised'] ?? date('Y-m-d'));
    $amount      = (float)trim($_POST['finance_usd'] ?? 0);
    $fund_form   = trim($_POST['funding_form'] ?? '');
    $fund_source = trim($_POST['funding_source'] ?? '');

    if ($amount <= 0 || $fund_form === '' || $fund_source === '') {
        vp_flash('Amount, funding form, and source are required.', 'error');
        vp_redir('meal-data.php?tab=finance');
    }

    $stmt = $conn->prepare("
        INSERT INTO meal_finance (venture_id, date_mobilised, finance_usd, funding_form, funding_source)
        VALUES (?,?,?,?,?)
    ");
    if (!$stmt) { vp_flash('DB error: ' . $conn->error, 'error'); vp_redir('meal-data.php?tab=finance'); }
    $stmt->bind_param('isdss', $venture_id, $date, $amount, $fund_form, $fund_source);
    if ($stmt->execute()) {
        vp_flash('Finance record saved.');
    } else {
        vp_flash('Save failed: ' . $stmt->error, 'error');
    }
    $stmt->close();
    vp_redir('meal-data.php?tab=finance');
}


// ==============================================================
//  POST: ADD SINGLE PARTNERSHIP RECORD
// ==============================================================
if ($action === 'add_meal_partnership') {
    $ven_id = (int)($_POST['venture_id'] ?? 0);
    if ($ven_id !== $venture_id) { vp_flash('Permission denied.', 'error'); vp_redir('meal-data.php'); }

    $partner_name = trim($_POST['partner_name'] ?? '');
    if ($partner_name === '') {
        vp_flash('Partner name is required.', 'error');
        vp_redir('meal-data.php?tab=partnerships');
    }

    $date   = trim($_POST['partner_date'] ?? date('Y-m-d'));
    $p_type = trim($_POST['partnership_type'] ?? '');
    $p_stat = trim($_POST['partnership_status'] ?? 'Active');

    $stmt = $conn->prepare("
        INSERT INTO meal_partnerships (venture_id, partner_date, partner_name, partnership_type, partnership_status)
        VALUES (?,?,?,?,?)
    ");
    if (!$stmt) { vp_flash('DB error: ' . $conn->error, 'error'); vp_redir('meal-data.php?tab=partnerships'); }
    $stmt->bind_param('issss', $venture_id, $date, $partner_name, $p_type, $p_stat);
    if ($stmt->execute()) {
        vp_flash('Partnership record saved.');
    } else {
        vp_flash('Save failed: ' . $stmt->error, 'error');
    }
    $stmt->close();
    vp_redir('meal-data.php?tab=partnerships');
}


// ==============================================================
//  POST: ADD SINGLE REVENUE RECORD
// ==============================================================
if ($action === 'add_meal_revenue') {
    $ven_id = (int)($_POST['venture_id'] ?? 0);
    if ($ven_id !== $venture_id) { vp_flash('Permission denied.', 'error'); vp_redir('meal-data.php'); }

    $rev_date   = trim($_POST['revenue_date'] ?? date('Y-m-d'));
    $rev_amount = (float)trim($_POST['gross_revenue_ugx'] ?? 0);
    $rev_stream = trim($_POST['revenue_stream'] ?? '');

    if ($rev_amount < 0) {
        vp_flash('Revenue amount must be zero or positive.', 'error');
        vp_redir('meal-data.php?tab=revenue');
    }

    $stmt = $conn->prepare("
        INSERT INTO meal_revenue (venture_id, revenue_date, gross_revenue_ugx, revenue_stream)
        VALUES (?,?,?,?)
    ");
    if (!$stmt) { vp_flash('DB error: ' . $conn->error, 'error'); vp_redir('meal-data.php?tab=revenue'); }
    $stmt->bind_param('isds', $venture_id, $rev_date, $rev_amount, $rev_stream);
    if ($stmt->execute()) {
        vp_flash('Revenue record saved.');
    } else {
        vp_flash('Save failed: ' . $stmt->error, 'error');
    }
    $stmt->close();
    vp_redir('meal-data.php?tab=revenue');
}


// ==============================================================
//  POST: DELETE M&E ROW
// ==============================================================
if ($action === 'delete_meal_row') {
    $type   = $_POST['type'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $ven_id = (int)($_POST['venture_id'] ?? 0);

    if ($ven_id !== $venture_id) { vp_flash('Permission denied.', 'error'); vp_redir('meal-data.php'); }

    $table_map = [
        'participants' => 'venture_participants',
        'teachers'     => 'meal_teachers',
        'schools'      => 'meal_schools',
        'other_users'  => 'meal_other_users',
        'employment'   => 'meal_employment',
        'finance'      => 'meal_finance',
        'partnerships' => 'meal_partnerships',
        'revenue'      => 'meal_revenue',
    ];

    if (!isset($table_map[$type]) || $id <= 0) {
        vp_flash('Invalid request.', 'error');
        vp_redir('meal-data.php');
    }

    $table = $table_map[$type];
    if (!meal_owns($conn, $table, $id, $venture_id)) {
        vp_flash('Record not found.', 'error');
        vp_redir('meal-data.php?tab=' . $type);
    }

    $stmt = $conn->prepare("DELETE FROM `{$table}` WHERE id = ? AND venture_id = ? LIMIT 1");
    $stmt->bind_param('ii', $id, $venture_id);
    if ($stmt->execute()) {
        vp_flash('Record deleted.');
    } else {
        vp_flash('Delete failed: ' . $stmt->error, 'error');
    }
    $stmt->close();
    vp_redir('meal-data.php?tab=' . $type);
}


// --------------------------------------------------------------
//  UNKNOWN / UNMATCHED ACTION
// --------------------------------------------------------------
if ($action !== '') {
    error_log('portal-process.php: unknown action: ' . $action);
    vp_flash('The requested action is not available.', 'error');
} else {
    vp_flash('No action was supplied.', 'error');
}
header('Location: ../dashboard.php');
exit;
