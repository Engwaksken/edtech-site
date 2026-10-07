<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';

/* Composer/vendor autoload (automatic when available). */
$venVendorCandidates = [
    dirname(__DIR__, 2) . '/vendor/autoload.php',
    dirname(__DIR__, 3) . '/vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];
foreach ($venVendorCandidates as $venVendorAutoload) {
    if (is_file($venVendorAutoload)) {
        require_once $venVendorAutoload;
        break;
    }
}
require_once __DIR__ . '/../../includes/mail-function.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/venture-doc-folders.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

ven_ensure_document_folders($conn);

function redir_ven(string $page = 'ventures', string $qs = ''): void
{
    header('Location: ../' . $page . '.php' . ($qs ? '?' . $qs : ''));
    exit;
}

function ven_flash(string $key, string $msg, string $type = 'success'): void
{
    if (function_exists('flash')) {
        flash($key, $msg, $type);
    }
}

function ven_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function clean_text(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

function post_int_nullable(string $key): ?int
{
    return (!isset($_POST[$key]) || $_POST[$key] === '') ? null : (int)$_POST[$key];
}

function make_slug(string $text): string
{
    if (function_exists('slugify')) {
        $slug = slugify($text);
    } else {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $text), '-'));
    }

    return $slug !== '' ? $slug : 'venture';
}

function ven_column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
    $stmt->close();

    return $exists;
}

function generate_temp_password(int $length = 12): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    $max = strlen($chars) - 1;
    $pass = '';

    for ($i = 0; $i < $length; $i++) {
        $pass .= $chars[random_int(0, $max)];
    }

    return $pass;
}

function normalize_url(string $url): string
{
    $url = trim($url);

    if ($url !== '' && !preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }

    return $url;
}

function venture_login_url(): string
{
    if (defined('SITE_URL')) {
        return rtrim((string)SITE_URL, '/') . '/login';
    }

    if (defined('BASE_URL')) {
        return rtrim((string)BASE_URL, '/') . '/login';
    }

    return '../login';
}

function send_venture_login_email(
    mysqli $conn,
    string $to,
    string $venture_name,
    string $password,
    bool $force_change
): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    if (!function_exists('sendEmail')) {
        return false;
    }

    $site_name = function_exists('get_setting')
        ? get_setting($conn, 'site_name', 'Venture Portal')
        : 'Venture Portal';

    $login_url = venture_login_url();

    $content = "
        <h3>Venture Portal Account Created</h3>
        <p>Hello,</p>
        <p>Your venture portal account has been created/updated for:</p>

        <div class='info-box'>
            <p><strong>Venture:</strong> " . ven_h($venture_name) . "</p>
            <p><strong>Email:</strong> " . ven_h($to) . "</p>
        </div>

        <div class='cred-box'>
            <h4>Your Temporary Password</h4>
            <div class='password'>" . ven_h($password) . "</div>
        </div>
    ";

    if ($force_change) {
        $content .= "
            <div class='warning'>
                For security, please change this password immediately after your first login.
            </div>
        ";
    }

    $content .= "
        <p>
            <a href='" . ven_h($login_url) . "' class='btn' target='_blank'>
                Login to Venture Portal
            </a>
        </p>
        <p>If the button does not work, copy and open this link:</p>
        <p>" . ven_h($login_url) . "</p>
        <p>Thank you,<br>" . ven_h($site_name) . " Team</p>
    ";

    $body = function_exists('email_wrapper') ? email_wrapper($content) : $content;

    $altBody =
        "Hello,\n\n"
        . "Your venture portal account has been created/updated for: {$venture_name}\n\n"
        . "Login URL: {$login_url}\n"
        . "Email: {$to}\n"
        . "Temporary Password: {$password}\n\n";

    if ($force_change) {
        $altBody .= "Please change this password immediately after login.\n\n";
    }

    return sendEmail($to, $site_name . ' - Venture Portal Login Details', $body, $altBody) === true;
}

function sync_venture_portal_access(mysqli $conn, int $venture_id, string $venture_name): array
{
    $portal_email = clean_text('portal_email');
    $portal_password = trim((string)($_POST['portal_password'] ?? ''));
    $portal_is_active = isset($_POST['portal_is_active']) ? 1 : 0;
    $force_change = isset($_POST['force_password_change']) ? 1 : 0;
    $send_email = isset($_POST['send_login_email']);

    if ($portal_email === '') {
        return ['ok' => true, 'message' => '', 'sent' => false];
    }

    if (!filter_var($portal_email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Enter a valid portal login email.', 'sent' => false];
    }

    $has_force_column = ven_column_exists($conn, 'venture_portal_access', 'force_password_change');

    $stmt = $conn->prepare("
        SELECT id, password_hash
        FROM venture_portal_access
        WHERE venture_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $venture_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $plain_password_for_email = '';
    $password_hash = '';

    if ($portal_password !== '') {
        $plain_password_for_email = $portal_password;
        $password_hash = password_hash($portal_password, PASSWORD_DEFAULT);
    } elseif (!$existing) {
        $plain_password_for_email = generate_temp_password();
        $password_hash = password_hash($plain_password_for_email, PASSWORD_DEFAULT);
        $send_email = true;
        $force_change = 1;
    }

    if ($existing) {
        if ($password_hash !== '') {
            if ($has_force_column) {
                $stmt = $conn->prepare("
                    UPDATE venture_portal_access
                    SET email = ?, password_hash = ?, is_active = ?, force_password_change = ?
                    WHERE venture_id = ?
                ");
                $stmt->bind_param('ssiii', $portal_email, $password_hash, $portal_is_active, $force_change, $venture_id);
            } else {
                $stmt = $conn->prepare("
                    UPDATE venture_portal_access
                    SET email = ?, password_hash = ?, is_active = ?
                    WHERE venture_id = ?
                ");
                $stmt->bind_param('ssii', $portal_email, $password_hash, $portal_is_active, $venture_id);
            }
        } else {
            if ($has_force_column) {
                $stmt = $conn->prepare("
                    UPDATE venture_portal_access
                    SET email = ?, is_active = ?, force_password_change = ?
                    WHERE venture_id = ?
                ");
                $stmt->bind_param('siii', $portal_email, $portal_is_active, $force_change, $venture_id);
            } else {
                $stmt = $conn->prepare("
                    UPDATE venture_portal_access
                    SET email = ?, is_active = ?
                    WHERE venture_id = ?
                ");
                $stmt->bind_param('sii', $portal_email, $portal_is_active, $venture_id);
            }
        }
    } else {
        if ($has_force_column) {
            $stmt = $conn->prepare("
                INSERT INTO venture_portal_access
                    (venture_id, email, password_hash, is_active, force_password_change)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('issii', $venture_id, $portal_email, $password_hash, $portal_is_active, $force_change);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO venture_portal_access
                    (venture_id, email, password_hash, is_active)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->bind_param('issi', $venture_id, $portal_email, $password_hash, $portal_is_active);
        }
    }

    if (!$stmt) {
        return ['ok' => false, 'message' => 'Portal DB error: ' . $conn->error, 'sent' => false];
    }

    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    if (!$ok) {
        return ['ok' => false, 'message' => 'Portal login failed: ' . $err, 'sent' => false];
    }

    $sent = false;

    if ($send_email && $plain_password_for_email !== '') {
        $sent = send_venture_login_email($conn, $portal_email, $venture_name, $plain_password_for_email, (bool)$force_change);
    }

    $message = 'Portal login saved.';

    if ($send_email && $plain_password_for_email !== '') {
        $message .= $sent ? ' Login details emailed.' : ' Login email could not be sent.';
    }

    if ($send_email && $plain_password_for_email === '') {
        $message .= ' Password unchanged, so login email was not sent.';
    }

    return ['ok' => true, 'message' => $message, 'sent' => $sent];
}


/**
 * Return the application/public root.
 *
 * process-ventures.php is inside /admin/includes, therefore two levels up
 * normally points to the website root. DOCUMENT_ROOT and configured paths are
 * also checked because hosting configurations differ.
 */
function ven_project_roots(): array
{
    $roots = [];

    $add = static function (?string $path) use (&$roots): void {
        $path = trim((string)$path);

        if ($path === '') {
            return;
        }

        $path = rtrim(str_replace('\\', '/', $path), '/');

        if ($path !== '' && !in_array($path, $roots, true)) {
            $roots[] = $path;
        }
    };

    if (defined('BASE_PATH')) {
        $add((string)BASE_PATH);
    }

    if (defined('ROOT_PATH')) {
        $add((string)ROOT_PATH);
    }

    if (defined('PUBLIC_PATH')) {
        $add((string)PUBLIC_PATH);
    }

    if (defined('UPLOAD_PATH')) {
        /*
         * UPLOAD_PATH may point to either the website root or /uploads.
         * Add both possibilities.
         */
        $uploadPath = rtrim(str_replace('\\', '/', (string)UPLOAD_PATH), '/');
        $add($uploadPath);

        if (basename($uploadPath) === 'uploads') {
            $add(dirname($uploadPath));
        }
    }

    $add($_SERVER['DOCUMENT_ROOT'] ?? '');
    $add(dirname(__DIR__, 2));

    return $roots;
}

/**
 * Normalise a database file path without allowing traversal outside the site.
 */
function ven_normalize_relative_path(string $filePath): string
{
    $filePath = trim(str_replace('\\', '/', $filePath));

    if ($filePath === '') {
        return '';
    }

    $urlPath = parse_url($filePath, PHP_URL_PATH);

    if (is_string($urlPath) && $urlPath !== '') {
        $filePath = $urlPath;
    }

    $parts = [];

    foreach (explode('/', ltrim($filePath, '/')) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }

        if ($part === '..') {
            array_pop($parts);
            continue;
        }

        $parts[] = $part;
    }

    return implode('/', $parts);
}

/**
 * Resolve a locally stored document.
 *
 * The database contains paths such as:
 * uploads/ventures/docs/doc_xxx.pdf
 *
 * This method supports different cPanel/Apache layouts and older rows that
 * may contain only a filename or an absolute filesystem path.
 */
function ven_resolve_local_document_path(string $storedPath): string|false
{
    $storedPath = trim($storedPath);

    if ($storedPath === '') {
        return false;
    }

    $candidates = [];

    $addCandidate = static function (string $path) use (&$candidates): void {
        $path = str_replace('\\', '/', trim($path));

        if ($path !== '' && !in_array($path, $candidates, true)) {
            $candidates[] = $path;
        }
    };

    /*
     * Support legacy rows containing an absolute server path.
     */
    if (
        str_starts_with($storedPath, '/') ||
        preg_match('/^[A-Za-z]:[\\\\\/]/', $storedPath) === 1
    ) {
        $addCandidate($storedPath);
    }

    $relative = ven_normalize_relative_path($storedPath);
    $filename = basename($relative);

    foreach (ven_project_roots() as $root) {
        if ($relative !== '') {
            $addCandidate($root . '/' . $relative);
        }

        /*
         * Older records may contain only a filename or may have been saved
         * while UPLOAD_PATH used a different base.
         */
        if ($filename !== '' && $filename !== '.' && $filename !== '..') {
            $addCandidate($root . '/uploads/ventures/docs/' . $filename);
            $addCandidate($root . '/ventures/docs/' . $filename);
            $addCandidate($root . '/uploads/' . $filename);
        }
    }

    if (defined('UPLOAD_PATH')) {
        $uploadRoot = rtrim(str_replace('\\', '/', (string)UPLOAD_PATH), '/');

        if ($relative !== '') {
            if (str_starts_with($relative, 'uploads/')) {
                $withoutUploads = substr($relative, strlen('uploads/'));
                $addCandidate($uploadRoot . '/' . $withoutUploads);
            }

            $addCandidate($uploadRoot . '/' . $relative);
        }

        if ($filename !== '') {
            $addCandidate($uploadRoot . '/ventures/docs/' . $filename);
            $addCandidate($uploadRoot . '/' . $filename);
        }
    }

    foreach ($candidates as $candidate) {
        $real = realpath($candidate);

        if ($real !== false && is_file($real) && is_readable($real)) {
            return $real;
        }
    }

    error_log(
        '[Venture document download] File could not be resolved. Stored path: '
        . $storedPath
        . ' | Candidates: '
        . implode(' | ', $candidates)
    );

    return false;
}

/**
 * Build the canonical local upload directory.
 */
function ven_document_upload_directory(): string
{
    if (defined('UPLOAD_PATH')) {
        $uploadRoot = rtrim(str_replace('\\', '/', (string)UPLOAD_PATH), '/');

        if (basename($uploadRoot) === 'uploads') {
            return $uploadRoot . '/ventures/docs';
        }

        return $uploadRoot . '/uploads/ventures/docs';
    }

    $documentRoot = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));

    if ($documentRoot !== '') {
        return rtrim(str_replace('\\', '/', $documentRoot), '/')
            . '/uploads/ventures/docs';
    }

    return rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/')
        . '/uploads/ventures/docs';
}

/**
 * Stream a local document to the browser.
 */
function ven_stream_download(string $absolutePath, string $downloadName = ''): never
{
    if (!is_file($absolutePath) || !is_readable($absolutePath)) {
        http_response_code(404);
        exit('File not found.');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $downloadName = trim($downloadName);

    if ($downloadName === '') {
        $downloadName = basename($absolutePath);
    }

    $downloadName = str_replace(["\r", "\n", '"'], '', basename($downloadName));

    $mime = 'application/octet-stream';

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo !== false) {
            $detected = finfo_file($finfo, $absolutePath);
            finfo_close($finfo);

            if (is_string($detected) && $detected !== '') {
                $mime = $detected;
            }
        }
    }

    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $downloadName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('Content-Length: ' . (string)filesize($absolutePath));
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $handle = fopen($absolutePath, 'rb');

    if ($handle === false) {
        http_response_code(500);
        exit('The document could not be opened.');
    }

    fpassthru($handle);
    fclose($handle);
    exit;
}

function ven_document_mime(string $absolutePath, string $extension = ''): string
{
    $extension = strtolower(trim($extension ?: pathinfo($absolutePath, PATHINFO_EXTENSION)));
    $map = [
        'pdf'=>'application/pdf','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp','svg'=>'image/svg+xml',
        'txt'=>'text/plain; charset=UTF-8','csv'=>'text/csv; charset=UTF-8','json'=>'application/json','xml'=>'application/xml','html'=>'text/html; charset=UTF-8','htm'=>'text/html; charset=UTF-8',
        'mp4'=>'video/mp4','webm'=>'video/webm','mov'=>'video/quicktime','mp3'=>'audio/mpeg','wav'=>'audio/wav','ogg'=>'audio/ogg',
        'doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'=>'application/vnd.ms-excel','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt'=>'application/vnd.ms-powerpoint','pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt'=>'application/vnd.oasis.opendocument.text','ods'=>'application/vnd.oasis.opendocument.spreadsheet','odp'=>'application/vnd.oasis.opendocument.presentation',
        'rtf'=>'application/rtf','zip'=>'application/zip','rar'=>'application/vnd.rar'
    ];
    if (isset($map[$extension])) return $map[$extension];
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_file($finfo, $absolutePath);
            finfo_close($finfo);
            if (is_string($detected) && $detected !== '') return $detected;
        }
    }
    return 'application/octet-stream';
}

function ven_stream_inline(string $absolutePath, string $displayName = '', string $extension = ''): never
{
    if (!is_file($absolutePath) || !is_readable($absolutePath)) {
        http_response_code(404); exit('File not found.');
    }
    while (ob_get_level() > 0) ob_end_clean();
    $displayName = trim($displayName) !== '' ? basename($displayName) : basename($absolutePath);
    $displayName = str_replace(["\r", "\n", '"'], '', $displayName);
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . ven_document_mime($absolutePath, $extension));
    header('Content-Disposition: inline; filename="' . $displayName . '"; filename*=UTF-8\'\'' . rawurlencode($displayName));
    header('Content-Length: ' . (string)filesize($absolutePath));
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    $handle = fopen($absolutePath, 'rb');
    if ($handle === false) { http_response_code(500); exit('The document could not be opened.'); }
    fpassthru($handle); fclose($handle); exit;
}

function s3_upload(array $file, string $key): string|false
{
    if (!defined('AWS_S3_BUCKET') || !defined('AWS_ACCESS_KEY') || !defined('AWS_SECRET_KEY') || !defined('AWS_REGION')) {
        return false;
    }

    $bucket = AWS_S3_BUCKET;
    $region = AWS_REGION;
    $host = "{$bucket}.s3.{$region}.amazonaws.com";

    $now = new DateTime('UTC');
    $date_s = $now->format('Ymd');
    $date_ls = $now->format('Ymd\THis\Z');

    $payload_hash = hash_file('sha256', $file['tmp_name']);
    $content_type = mime_content_type($file['tmp_name']) ?: 'application/octet-stream';

    $headers = [
        'content-type' => $content_type,
        'host' => $host,
        'x-amz-content-sha256' => $payload_hash,
        'x-amz-date' => $date_ls,
    ];

    ksort($headers);

    $canonical_headers = '';
    $signed_headers = '';

    foreach ($headers as $k => $v) {
        $canonical_headers .= "{$k}:{$v}\n";
        $signed_headers .= ($signed_headers ? ';' : '') . $k;
    }

    $canonical_request = implode("\n", [
        'PUT',
        '/' . $key,
        '',
        $canonical_headers,
        $signed_headers,
        $payload_hash,
    ]);

    $credential_scope = "{$date_s}/{$region}/s3/aws4_request";

    $string_to_sign = implode("\n", [
        'AWS4-HMAC-SHA256',
        $date_ls,
        $credential_scope,
        hash('sha256', $canonical_request),
    ]);

    $signing_key = hash_hmac(
        'sha256',
        'aws4_request',
        hash_hmac(
            'sha256',
            's3',
            hash_hmac(
                'sha256',
                $region,
                hash_hmac('sha256', $date_s, 'AWS4' . AWS_SECRET_KEY, true),
                true
            ),
            true
        ),
        true
    );

    $signature = hash_hmac('sha256', $string_to_sign, $signing_key);

    $auth_header = "AWS4-HMAC-SHA256 Credential=" . AWS_ACCESS_KEY . "/{$credential_scope},SignedHeaders={$signed_headers},Signature={$signature}";

    $url = "https://{$host}/{$key}";
    $fh = fopen($file['tmp_name'], 'rb');

    if (!$fh) {
        return false;
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'PUT',
            'header' => implode("\r\n", [
                "Content-Type: {$content_type}",
                "x-amz-content-sha256: {$payload_hash}",
                "x-amz-date: {$date_ls}",
                "Authorization: {$auth_header}",
                "Content-Length: " . filesize($file['tmp_name']),
            ]),
            'content' => stream_get_contents($fh),
        ]
    ]);

    fclose($fh);

    $result = @file_get_contents($url, false, $ctx);

    return $result === false ? false : "https://{$host}/{$key}";
}

function s3_delete(string $s3_url): void
{
    if (!defined('AWS_S3_BUCKET') || !defined('AWS_ACCESS_KEY') || !defined('AWS_SECRET_KEY') || !defined('AWS_REGION')) {
        return;
    }

    $parsed = parse_url($s3_url);
    $key = ltrim($parsed['path'] ?? '', '/');

    if ($key === '') {
        return;
    }

    $bucket = AWS_S3_BUCKET;
    $region = AWS_REGION;
    $host = "{$bucket}.s3.{$region}.amazonaws.com";

    $now = new DateTime('UTC');
    $date_s = $now->format('Ymd');
    $date_ls = $now->format('Ymd\THis\Z');
    $empty_hash = hash('sha256', '');

    $canonical_headers = "host:{$host}\nx-amz-content-sha256:{$empty_hash}\nx-amz-date:{$date_ls}\n";
    $signed_headers = 'host;x-amz-content-sha256;x-amz-date';

    $canonical_request = "DELETE\n/{$key}\n\n{$canonical_headers}\n{$signed_headers}\n{$empty_hash}";
    $credential_scope = "{$date_s}/{$region}/s3/aws4_request";
    $string_to_sign = "AWS4-HMAC-SHA256\n{$date_ls}\n{$credential_scope}\n" . hash('sha256', $canonical_request);

    $signing_key = hash_hmac(
        'sha256',
        'aws4_request',
        hash_hmac(
            'sha256',
            's3',
            hash_hmac(
                'sha256',
                $region,
                hash_hmac('sha256', $date_s, 'AWS4' . AWS_SECRET_KEY, true),
                true
            ),
            true
        ),
        true
    );

    $signature = hash_hmac('sha256', $string_to_sign, $signing_key);

    $auth_header = "AWS4-HMAC-SHA256 Credential=" . AWS_ACCESS_KEY . "/{$credential_scope},SignedHeaders={$signed_headers},Signature={$signature}";

    $ctx = stream_context_create([
        'http' => [
            'method' => 'DELETE',
            'header' => implode("\r\n", [
                "x-amz-content-sha256:{$empty_hash}",
                "x-amz-date:{$date_ls}",
                "Authorization:{$auth_header}",
            ]),
        ]
    ]);

    @file_get_contents("https://{$host}/{$key}", false, $ctx);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* ============================================================
   VERSION HISTORY
============================================================ */

if ($action === 'version_history' && isset($_GET['doc_group'])) {
    $group = trim((string)$_GET['doc_group']);
    $venture_id = (int)($_GET['venture_id'] ?? 0);

    $stmt = $conn->prepare("
        SELECT *
        FROM venture_documents
        WHERE venture_id = ?
          AND doc_group = ?
        ORDER BY version_number DESC
    ");
    $stmt->bind_param('is', $venture_id, $group);
    $stmt->execute();
    $rows = $stmt->get_result();
    $stmt->close();

    echo '<ul class="version-list">';

    while ($r = $rows->fetch_assoc()) {
        $latest = !empty($r['is_latest'])
            ? '<span class="badge badge-success" style="font-size:10px">Latest</span>'
            : '';

        echo '<li>
            <div>
                <span class="version-num">v' . (int)$r['version_number'] . '</span>
                ' . $latest . '
                <span style="color:var(--text-muted);font-size:11.5px;margin-left:6px">'
                    . date('M j, Y g:ia', strtotime((string)$r['uploaded_at'])) .
                    ' · ' . round(((int)$r['file_size']) / 1048576, 2) . ' MB
                </span>
            </div>
            <div style="display:flex;gap:6px">
                <a href="includes/process-ventures.php?action=view&amp;id=' . (int)$r['id'] . '" class="btn btn-sm btn-primary" target="_blank" rel="noopener" title="View">
                    <i class="fa fa-eye"></i>
                </a>
                <a href="includes/process-ventures.php?action=download&amp;id=' . (int)$r['id'] . '" class="btn btn-sm btn-secondary" title="Download">
                    <i class="fa fa-download"></i>
                </a>';

        if (empty($r['is_latest'])) {
            echo '<form method="POST" action="includes/process-ventures.php" style="display:inline">
                    <input type="hidden" name="action" value="restore_version">
                    <input type="hidden" name="id" value="' . (int)$r['id'] . '">
                    <input type="hidden" name="venture_id" value="' . (int)$venture_id . '">
                    <input type="hidden" name="doc_group" value="' . ven_h($group) . '">
                    <button class="btn btn-sm btn-teal" title="Restore this version">
                        <i class="fa fa-undo"></i>
                    </button>
                  </form>';
        }

        echo '</div></li>';
    }

    echo '</ul>';
    exit;
}

/* ============================================================
   VIEW DOCUMENT INLINE
   Uses the installed Composer/vendor autoloader automatically when present.
   Browser-native formats are streamed inline. Office files are also served
   with their correct MIME type; browsers that cannot render them can still
   use the Download button.
============================================================ */

if ($action === 'view') {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id || $id <= 0) { http_response_code(400); exit('Invalid document ID.'); }

    $stmt = $conn->prepare("SELECT id, doc_name, file_path, file_ext, storage_type, s3_url FROM venture_documents WHERE id = ? LIMIT 1");
    if (!$stmt) { http_response_code(500); exit('The document could not be loaded.'); }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $document = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$document) { http_response_code(404); exit('Document record not found.'); }

    $storageType = strtolower(trim((string)($document['storage_type'] ?? 'local')));
    $s3Url = trim((string)($document['s3_url'] ?? ''));
    if ($storageType === 's3' && $s3Url !== '') {
        if (!filter_var($s3Url, FILTER_VALIDATE_URL)) { http_response_code(500); exit('The cloud document URL is invalid.'); }
        header('Location: ' . $s3Url, true, 302); exit;
    }

    $absolutePath = ven_resolve_local_document_path((string)($document['file_path'] ?? ''));
    if ($absolutePath === false) { http_response_code(404); exit('File not found on the server.'); }
    $extension = strtolower(trim((string)($document['file_ext'] ?? '')));
    $name = trim((string)($document['doc_name'] ?? ''));
    if ($name === '') $name = basename($absolutePath);
    elseif ($extension !== '' && strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $extension) $name .= '.' . $extension;
    ven_stream_inline($absolutePath, $name, $extension);
}

/* ============================================================
   DOWNLOAD DOCUMENT
============================================================ */

if ($action === 'download') {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

    if (!$id || $id <= 0) {
        http_response_code(400);
        exit('Invalid document ID.');
    }

    $stmt = $conn->prepare("
        SELECT id, doc_name, file_path, file_ext, storage_type, s3_url
        FROM venture_documents
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        http_response_code(500);
        error_log('[Venture document download] Prepare failed: ' . $conn->error);
        exit('The document could not be loaded.');
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $document = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$document) {
        http_response_code(404);
        exit('Document record not found.');
    }

    $storageType = strtolower(trim((string)($document['storage_type'] ?? 'local')));
    $s3Url = trim((string)($document['s3_url'] ?? ''));

    if ($storageType === 's3' && $s3Url !== '') {
        if (filter_var($s3Url, FILTER_VALIDATE_URL) === false) {
            http_response_code(500);
            exit('The cloud document URL is invalid.');
        }

        header('Location: ' . $s3Url, true, 302);
        exit;
    }

    $storedPath = trim((string)($document['file_path'] ?? ''));
    $absolutePath = ven_resolve_local_document_path($storedPath);

    if ($absolutePath === false) {
        http_response_code(404);
        exit(
            'File not found on the server. Confirm that '
            . ven_h($storedPath)
            . ' exists inside uploads/ventures/docs.'
        );
    }

    $extension = strtolower(trim((string)($document['file_ext'] ?? '')));
    $downloadName = trim((string)($document['doc_name'] ?? ''));

    if ($downloadName === '') {
        $downloadName = basename($absolutePath);
    } elseif ($extension !== '' && strtolower(pathinfo($downloadName, PATHINFO_EXTENSION)) !== $extension) {
        $downloadName .= '.' . $extension;
    }

    ven_stream_download($absolutePath, $downloadName);
}

/* ============================================================
   LIST FOLDERS (GET, JSON) - used by the Move modal
============================================================ */

if ($action === 'list_folders') {
    header('Content-Type: application/json');

    $venture_id = (int)($_GET['venture_id'] ?? 0);

    if ($venture_id <= 0) {
        echo json_encode(['success' => false, 'folders' => []]);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT id, parent_id, folder_name
        FROM venture_document_folders
        WHERE venture_id = ?
        ORDER BY folder_name ASC
    ");
    $stmt->bind_param('i', $venture_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $byParent = [];
    foreach ($rows as $r) {
        $p = $r['parent_id'] === null ? 0 : (int)$r['parent_id'];
        $byParent[$p][] = $r;
    }

    $flat = [];
    $walk = function (int $parent, int $depth) use (&$walk, &$byParent, &$flat): void {
        if (empty($byParent[$parent])) {
            return;
        }

        foreach ($byParent[$parent] as $f) {
            $flat[] = ['id' => (int)$f['id'], 'name' => (string)$f['folder_name'], 'depth' => $depth];
            $walk((int)$f['id'], $depth + 1);
        }
    };
    $walk(0, 0);

    echo json_encode(['success' => true, 'folders' => $flat]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redir_ven();
}

/* ============================================================
   APPROVE DOCUMENT
============================================================ */

if ($action === 'approve_doc') {
    $document_id = (int)($_POST['document_id'] ?? 0);
    $venture_id = (int)($_POST['venture_id'] ?? 0);
    $return_folder = (int)($_POST['return_folder'] ?? 0);

    $stmt = $conn->prepare("
        UPDATE venture_documents
        SET approval_status = 'approved',
            change_request_status = 'none',
            change_request_admin_note = NULL,
            change_request_reviewed_at = NOW()
        WHERE id = ?
          AND venture_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $document_id, $venture_id);
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    ven_flash('docs', $ok ? 'Document approved successfully.' : 'Approval failed: ' . $err, $ok ? 'success' : 'error');
    redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
}

if ($action === 'reject_doc') {
    $document_id = (int)($_POST['document_id'] ?? 0);
    $venture_id = (int)($_POST['venture_id'] ?? 0);
    $admin_note = trim((string)($_POST['admin_note'] ?? ''));
    $return_folder = (int)($_POST['return_folder'] ?? 0);

    $stmt = $conn->prepare("
        UPDATE venture_documents
        SET approval_status = 'rejected',
            change_request_status = 'none',
            change_request_admin_note = ?,
            change_request_reviewed_at = NOW()
        WHERE id = ?
          AND venture_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('sii', $admin_note, $document_id, $venture_id);
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    ven_flash('docs', $ok ? 'Document rejected successfully.' : 'Rejection failed: ' . $err, $ok ? 'success' : 'error');
    redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
}

if ($action === 'allow_doc_change') {
    $document_id = (int)($_POST['document_id'] ?? 0);
    $venture_id = (int)($_POST['venture_id'] ?? 0);
    $return_folder = (int)($_POST['return_folder'] ?? 0);

    $stmt = $conn->prepare("
        UPDATE venture_documents
        SET change_request_status = 'allowed',
            change_request_admin_note = 'Admin allowed venture to upload a new version.',
            change_request_reviewed_at = NOW()
        WHERE id = ?
          AND venture_id = ?
          AND approval_status = 'approved'
        LIMIT 1
    ");
    $stmt->bind_param('ii', $document_id, $venture_id);
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    ven_flash('docs', $ok ? 'Venture can now re-upload this document.' : 'Failed: ' . $err, $ok ? 'success' : 'error');
    redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
}

if ($action === 'reject_doc_change') {
    $document_id = (int)($_POST['document_id'] ?? 0);
    $venture_id = (int)($_POST['venture_id'] ?? 0);
    $admin_note = trim((string)($_POST['admin_note'] ?? ''));
    $return_folder = (int)($_POST['return_folder'] ?? 0);

    $stmt = $conn->prepare("
        UPDATE venture_documents
        SET change_request_status = 'rejected',
            change_request_admin_note = ?,
            change_request_reviewed_at = NOW()
        WHERE id = ?
          AND venture_id = ?
          AND approval_status = 'approved'
        LIMIT 1
    ");
    $stmt->bind_param('sii', $admin_note, $document_id, $venture_id);
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    ven_flash('docs', $ok ? 'Re-upload request rejected.' : 'Failed: ' . $err, $ok ? 'success' : 'error');
    redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
}

/* ============================================================
   FOLDER MANAGEMENT
============================================================ */

if ($action === 'create_folder') {
    $venture_id = (int)($_POST['venture_id'] ?? 0);
    $parent_id = (int)($_POST['parent_id'] ?? 0);
    $folder_name = clean_text('folder_name');

    if ($venture_id <= 0 || $folder_name === '') {
        ven_flash('docs', 'Folder name is required.', 'error');
        redir_ven('venture-docs', "venture_id={$venture_id}&folder={$parent_id}");
    }

    if ($parent_id > 0) {
        $stmt = $conn->prepare("SELECT id FROM venture_document_folders WHERE id = ? AND venture_id = ? LIMIT 1");
        $stmt->bind_param('ii', $parent_id, $venture_id);
        $stmt->execute();

        if (!$stmt->get_result()->fetch_assoc()) {
            $parent_id = 0;
        }

        $stmt->close();
    }

    if ($parent_id > 0) {
        $stmt = $conn->prepare("
            INSERT INTO venture_document_folders (venture_id, parent_id, folder_name)
            VALUES (?, ?, ?)
        ");
        $stmt->bind_param('iis', $venture_id, $parent_id, $folder_name);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO venture_document_folders (venture_id, parent_id, folder_name)
            VALUES (?, NULL, ?)
        ");
        $stmt->bind_param('is', $venture_id, $folder_name);
    }
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    ven_flash('docs', $ok ? 'Folder created successfully.' : 'Folder creation failed: ' . $err, $ok ? 'success' : 'error');
    redir_ven('venture-docs', "venture_id={$venture_id}&folder={$parent_id}");
}

if ($action === 'rename_folder') {
    $venture_id = (int)($_POST['venture_id'] ?? 0);
    $folder_id = (int)($_POST['folder_id'] ?? 0);
    $folder_name = clean_text('folder_name');
    $return_folder = (int)($_POST['return_folder'] ?? 0);

    if ($venture_id <= 0 || $folder_id <= 0 || $folder_name === '') {
        ven_flash('docs', 'Invalid rename request.', 'error');
        redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
    }

    $stmt = $conn->prepare("
        UPDATE venture_document_folders
        SET folder_name = ?
        WHERE id = ? AND venture_id = ?
    ");
    $stmt->bind_param('sii', $folder_name, $folder_id, $venture_id);
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    ven_flash('docs', $ok ? 'Folder renamed successfully.' : 'Rename failed: ' . $err, $ok ? 'success' : 'error');
    redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
}

if ($action === 'delete_folder') {
    $venture_id = (int)($_POST['venture_id'] ?? 0);
    $folder_id = (int)($_POST['folder_id'] ?? 0);
    $return_folder = (int)($_POST['return_folder'] ?? 0);

    if ($venture_id <= 0 || $folder_id <= 0) {
        ven_flash('docs', 'Invalid delete request.', 'error');
        redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM venture_document_folders WHERE parent_id = ? AND venture_id = ?");
    $stmt->bind_param('ii', $folder_id, $venture_id);
    $stmt->execute();
    $sub_count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM venture_documents WHERE folder_id = ? AND venture_id = ?");
    $stmt->bind_param('ii', $folder_id, $venture_id);
    $stmt->execute();
    $doc_count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    if ($sub_count > 0 || $doc_count > 0) {
        ven_flash('docs', 'This folder is not empty. Move or delete its contents first.', 'error');
        redir_ven('venture-docs', "venture_id={$venture_id}&folder={$folder_id}");
    }

    $stmt = $conn->prepare("DELETE FROM venture_document_folders WHERE id = ? AND venture_id = ?");
    $stmt->bind_param('ii', $folder_id, $venture_id);
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    ven_flash('docs', $ok ? 'Folder deleted successfully.' : 'Delete failed: ' . $err, $ok ? 'success' : 'error');
    redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
}

/* ============================================================
   MOVE DOCUMENT / FOLDER (AJAX, JSON)
============================================================ */

if ($action === 'move_document') {
    header('Content-Type: application/json');

    $venture_id = (int)($_POST['venture_id'] ?? 0);
    $doc_group = clean_text('doc_group');
    $target_folder_id = (int)($_POST['target_folder_id'] ?? 0);

    if ($venture_id <= 0 || $doc_group === '') {
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }

    if ($target_folder_id > 0) {
        $stmt = $conn->prepare("SELECT id FROM venture_document_folders WHERE id = ? AND venture_id = ? LIMIT 1");
        $stmt->bind_param('ii', $target_folder_id, $venture_id);
        $stmt->execute();

        if (!$stmt->get_result()->fetch_assoc()) {
            $stmt->close();
            echo json_encode(['success' => false, 'message' => 'Destination folder not found.']);
            exit;
        }

        $stmt->close();
    }

    if ($target_folder_id > 0) {
        $stmt = $conn->prepare("
            UPDATE venture_documents
            SET folder_id = ?
            WHERE venture_id = ? AND doc_group = ?
        ");
        $stmt->bind_param('iis', $target_folder_id, $venture_id, $doc_group);
    } else {
        $stmt = $conn->prepare("
            UPDATE venture_documents
            SET folder_id = NULL
            WHERE venture_id = ? AND doc_group = ?
        ");
        $stmt->bind_param('is', $venture_id, $doc_group);
    }
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    echo json_encode(['success' => $ok, 'message' => $ok ? 'Document moved.' : ('Move failed: ' . $err)]);
    exit;
}

if ($action === 'move_folder') {
    header('Content-Type: application/json');

    $venture_id = (int)($_POST['venture_id'] ?? 0);
    $folder_id = (int)($_POST['folder_id'] ?? 0);
    $target_folder_id = (int)($_POST['target_folder_id'] ?? 0);

    if ($venture_id <= 0 || $folder_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }

    if ($target_folder_id === $folder_id) {
        echo json_encode(['success' => false, 'message' => 'A folder cannot be moved into itself.']);
        exit;
    }

    if ($target_folder_id > 0) {
        $stmt = $conn->prepare("SELECT id FROM venture_document_folders WHERE id = ? AND venture_id = ? LIMIT 1");
        $stmt->bind_param('ii', $target_folder_id, $venture_id);
        $stmt->execute();

        if (!$stmt->get_result()->fetch_assoc()) {
            $stmt->close();
            echo json_encode(['success' => false, 'message' => 'Destination folder not found.']);
            exit;
        }

        $stmt->close();

        if (ven_folder_is_descendant($conn, $venture_id, $folder_id, $target_folder_id)) {
            echo json_encode(['success' => false, 'message' => 'A folder cannot be moved into its own subfolder.']);
            exit;
        }
    }

    if ($target_folder_id > 0) {
        $stmt = $conn->prepare("
            UPDATE venture_document_folders
            SET parent_id = ?
            WHERE id = ? AND venture_id = ?
        ");
        $stmt->bind_param('iii', $target_folder_id, $folder_id, $venture_id);
    } else {
        $stmt = $conn->prepare("
            UPDATE venture_document_folders
            SET parent_id = NULL
            WHERE id = ? AND venture_id = ?
        ");
        $stmt->bind_param('ii', $folder_id, $venture_id);
    }
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    echo json_encode(['success' => $ok, 'message' => $ok ? 'Folder moved.' : ('Move failed: ' . $err)]);
    exit;
}

/* ============================================================
   SAVE VENTURE
============================================================ */

if ($action === 'save') {
    $id = (int)($_POST['id'] ?? 0);

    $name = clean_text('name');
    $tagline = clean_text('tagline');
    $description = clean_text('description');
    $sector = clean_text('sector');
    $country = clean_text('country');
    $location = clean_text('location');
    $website = normalize_url(clean_text('website'));
    $linkedin_url = normalize_url(clean_text('linkedin_url'));
    $impact_metric = clean_text('impact_metric');
    $admin_notes = clean_text('admin_notes');

    $cohort_id = post_int_nullable('cohort_id');
    $founded_year = post_int_nullable('founded_year');
    $funding_raised = post_int_nullable('funding_raised');
    $funding_sought = post_int_nullable('funding_sought');

    $cofounder1_name = clean_text('cofounder1_name');
    $cofounder1_email = clean_text('cofounder1_email');
    $cofounder1_contact = clean_text('cofounder1_contact');

    $cofounder2_name = clean_text('cofounder2_name');
    $cofounder2_email = clean_text('cofounder2_email');
    $cofounder2_contact = clean_text('cofounder2_contact');

    $youth_led = (isset($_POST['youth_led']) && $_POST['youth_led'] === '1') ? 1 : 0;

    $allowed_genders = ['male', 'female'];
    $owner_gender = strtolower(trim((string)($_POST['owner_gender'] ?? '')));
    $owner_gender = in_array($owner_gender, $allowed_genders, true) ? $owner_gender : '';

    $allowed_stages = ['idea', 'mvp', 'pre_seed', 'seed', 'series_a', 'growth'];
    $allowed_statuses = ['active', 'draft', 'inactive', 'graduated'];

    $stage = in_array($_POST['stage'] ?? '', $allowed_stages, true) ? (string)$_POST['stage'] : 'idea';
    $status = in_array($_POST['status'] ?? '', $allowed_statuses, true) ? (string)$_POST['status'] : 'draft';

    if ($name === '') {
        ven_flash('ventures', 'Venture name is required.', 'error');
        redir_ven();
    }

    $logo = trim((string)($_POST['existing_logo'] ?? ''));
    $featured_image = trim((string)($_POST['existing_featured_image'] ?? ''));

    $upload_err = '';

    if (function_exists('upload_image')) {
        $new_logo = upload_image('logo', 'ventures', $upload_err);

        if ($new_logo) {
            if ($logo !== '' && function_exists('delete_image')) {
                delete_image($logo);
            }

            $logo = $new_logo;
        }

        $new_featured = upload_image('featured_image', 'ventures', $upload_err);

        if ($new_featured) {
            if ($featured_image !== '' && function_exists('delete_image')) {
                delete_image($featured_image);
            }

            $featured_image = $new_featured;
        }
    }

    $slug_base = make_slug($name);

    if ($id > 0) {
        $stmt = $conn->prepare("
            UPDATE ventures SET
                name = ?, slug = ?, tagline = ?, description = ?,
                cofounder1_name = ?, cofounder1_email = ?, cofounder1_contact = ?,
                cofounder2_name = ?, cofounder2_email = ?, cofounder2_contact = ?,
                stage = ?, status = ?, sector = ?, country = ?, location = ?,
                founded_year = ?, website = ?, linkedin_url = ?, logo = ?, featured_image = ?,
                cohort_id = ?, funding_raised = ?, funding_sought = ?, impact_metric = ?, admin_notes = ?,
                youth_led = ?, owner_gender = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            'sssssssssssssssissssiiissisi',
            $name,
            $slug_base,
            $tagline,
            $description,
            $cofounder1_name,
            $cofounder1_email,
            $cofounder1_contact,
            $cofounder2_name,
            $cofounder2_email,
            $cofounder2_contact,
            $stage,
            $status,
            $sector,
            $country,
            $location,
            $founded_year,
            $website,
            $linkedin_url,
            $logo,
            $featured_image,
            $cohort_id,
            $funding_raised,
            $funding_sought,
            $impact_metric,
            $admin_notes,
            $youth_led,
            $owner_gender,
            $id
        );

        $ok = $stmt->execute();
        $err = $stmt->error;
        $stmt->close();

        if (!$ok) {
            ven_flash('ventures', 'Update failed: ' . $err, 'error');
            redir_ven();
        }

        $portal = sync_venture_portal_access($conn, $id, $name);

        ven_flash(
            'ventures',
            $portal['ok']
                ? 'Venture updated successfully. ' . $portal['message']
                : 'Venture updated, but portal login failed: ' . $portal['message'],
            $portal['ok'] ? 'success' : 'error'
        );

        redir_ven();
    }

    $stmt = $conn->prepare("
        INSERT INTO ventures (
            name, slug, tagline, description,
            cofounder1_name, cofounder1_email, cofounder1_contact,
            cofounder2_name, cofounder2_email, cofounder2_contact,
            stage, status, sector, country, location,
            founded_year, website, linkedin_url, logo, featured_image,
            cohort_id, funding_raised, funding_sought, impact_metric, admin_notes,
            youth_led, owner_gender
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        'sssssssssssssssissssiiissis',
        $name,
        $slug_base,
        $tagline,
        $description,
        $cofounder1_name,
        $cofounder1_email,
        $cofounder1_contact,
        $cofounder2_name,
        $cofounder2_email,
        $cofounder2_contact,
        $stage,
        $status,
        $sector,
        $country,
        $location,
        $founded_year,
        $website,
        $linkedin_url,
        $logo,
        $featured_image,
        $cohort_id,
        $funding_raised,
        $funding_sought,
        $impact_metric,
        $admin_notes,
        $youth_led,
        $owner_gender
    );

    $ok = $stmt->execute();
    $new_id = (int)$stmt->insert_id;
    $err = $stmt->error;
    $stmt->close();

    if (!$ok) {
        ven_flash('ventures', 'Create failed: ' . $err, 'error');
        redir_ven();
    }

    $portal = sync_venture_portal_access($conn, $new_id, $name);

    ven_flash(
        'ventures',
        $portal['ok']
            ? 'Venture created successfully. ' . $portal['message']
            : 'Venture created, but portal login failed: ' . $portal['message'],
        $portal['ok'] ? 'success' : 'error'
    );

    redir_ven();
}

/* ============================================================
   UPLOAD DOCUMENT (single file - all categories, and versioning)
============================================================ */

if ($action === 'upload_doc') {
    $venture_id = (int)($_POST['venture_id'] ?? 0);
    $doc_name = clean_text('doc_name');
    $category = clean_text('category') ?: 'other';
    $description = clean_text('description');
    $doc_group = clean_text('doc_group');
    $visible = ($_POST['is_visible_to_investors'] ?? '0') === '1' ? 1 : 0;
    $folder_id = (int)($_POST['folder_id'] ?? 0);
    $return_folder = (int)($_POST['return_folder'] ?? $folder_id);

    if ($venture_id <= 0) {
        ven_flash('docs', 'Invalid venture.', 'error');
        redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
    }

    if ($doc_name === '') {
        ven_flash('docs', 'Document name is required.', 'error');
        redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
    }

    if (empty($_FILES['doc_file']['name'])) {
        ven_flash('docs', 'Please select a file.', 'error');
        redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
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
        'other'
    ];

    if (!in_array($category, $allowed_cats, true)) {
        $category = 'other';
    }

    $ext = strtolower(pathinfo((string)$_FILES['doc_file']['name'], PATHINFO_EXTENSION));
    $allowed_exts = ['pdf','doc','docx','xls','xlsx','ppt','pptx','mp4','mov','zip','rar','png','jpg','jpeg'];

    if (!in_array($ext, $allowed_exts, true)) {
        ven_flash('docs', 'File type not allowed.', 'error');
        redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
    }

    $s3_mode = defined('AWS_S3_BUCKET') && defined('AWS_ACCESS_KEY') && defined('AWS_SECRET_KEY') && defined('AWS_REGION');
    $max_bytes = $s3_mode ? 500 * 1024 * 1024 : 50 * 1024 * 1024;

    if ((int)$_FILES['doc_file']['size'] > $max_bytes) {
        ven_flash('docs', 'File too large. Max ' . ($s3_mode ? '500 MB' : '50 MB') . '.', 'error');
        redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
    }

    if ($_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
        ven_flash('docs', 'Upload error. Please try again.', 'error');
        redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
    }

    // Validate the requested folder belongs to this venture.
    if ($folder_id > 0) {
        $stmt = $conn->prepare("SELECT id FROM venture_document_folders WHERE id = ? AND venture_id = ? LIMIT 1");
        $stmt->bind_param('ii', $folder_id, $venture_id);
        $stmt->execute();

        if (!$stmt->get_result()->fetch_assoc()) {
            $folder_id = 0;
        }

        $stmt->close();
    }

    if ($doc_group === '') {
        $doc_group = uniqid('dg_', true);
    }

    $stmt = $conn->prepare("
        SELECT id, approval_status, change_request_status, folder_id
        FROM venture_documents
        WHERE venture_id = ?
          AND doc_group = ?
          AND is_latest = 1
        LIMIT 1
    ");
    $stmt->bind_param('is', $venture_id, $doc_group);
    $stmt->execute();
    $current_doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($current_doc) {
        $approval_status = strtolower((string)($current_doc['approval_status'] ?? 'pending'));
        $change_status = strtolower((string)($current_doc['change_request_status'] ?? 'none'));

        if ($approval_status === 'approved' && $change_status !== 'allowed') {
            ven_flash('docs', 'This document is approved and locked. The venture must request permission before re-uploading.', 'error');
            redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
        }

        /*
         * A re-uploaded version always stays in the folder the document is
         * already in - the upload modal's "current folder" is ignored here,
         * so bumping a version never silently relocates the file.
         */
        $folder_id = $current_doc['folder_id'] !== null ? (int)$current_doc['folder_id'] : 0;
    }

    $stmt = $conn->prepare("
        SELECT MAX(version_number) AS max_version
        FROM venture_documents
        WHERE venture_id = ?
          AND doc_group = ?
    ");
    $stmt->bind_param('is', $venture_id, $doc_group);
    $stmt->execute();
    $ver_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $version = ((int)($ver_row['max_version'] ?? 0)) + 1;

    $stmt = $conn->prepare("
        UPDATE venture_documents
        SET is_latest = 0
        WHERE venture_id = ?
          AND doc_group = ?
    ");
    $stmt->bind_param('is', $venture_id, $doc_group);
    $stmt->execute();
    $stmt->close();

    $file_path = '';
    $s3_url = '';
    $storage_type = 'local';
    $file_size = (int)$_FILES['doc_file']['size'];
    $filename = uniqid('doc_', true) . '.' . $ext;

    if ($s3_mode) {
        $s3_key = "ventures/{$venture_id}/docs/{$filename}";
        $url = s3_upload($_FILES['doc_file'], $s3_key);

        if (!$url) {
            ven_flash('docs', 'S3 upload failed. Check AWS credentials.', 'error');
            redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
        }

        $s3_url = $url;
        $storage_type = 's3';
    } else {
        $dest_dir = ven_document_upload_directory();

        if (!is_dir($dest_dir) && !mkdir($dest_dir, 0755, true) && !is_dir($dest_dir)) {
            ven_flash('docs', 'The document directory could not be created.', 'error');
            redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
        }

        if (!is_writable($dest_dir)) {
            ven_flash('docs', 'The document directory is not writable.', 'error');
            redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
        }

        $destination = rtrim($dest_dir, '/') . '/' . $filename;

        if (!move_uploaded_file($_FILES['doc_file']['tmp_name'], $destination)) {
            ven_flash('docs', 'File upload failed. Check the uploads/ventures/docs permissions.', 'error');
            redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
        }

        @chmod($destination, 0644);

        /*
         * Always store a portable path relative to the website root.
         */
        $file_path = 'uploads/ventures/docs/' . $filename;
    }

    $admin_id = (int)($ADMIN['id'] ?? 0);
    $admin_name = $ADMIN['full_name'] ?? 'Admin';

    $approval_status = $version > 1 ? 'pending' : 'pending';

    if ($folder_id > 0) {
        $stmt = $conn->prepare("
            INSERT INTO venture_documents (
                venture_id, folder_id, doc_group, doc_name, category, description,
                file_path, file_size, file_ext, storage_type, s3_url,
                version_number, is_latest, is_visible_to_investors,
                uploaded_by, uploaded_by_name, uploaded_at,
                approval_status, change_request_status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, NOW(), ?, 'none')
        ");
    } else {
        $stmt = $conn->prepare("
            INSERT INTO venture_documents (
                venture_id, folder_id, doc_group, doc_name, category, description,
                file_path, file_size, file_ext, storage_type, s3_url,
                version_number, is_latest, is_visible_to_investors,
                uploaded_by, uploaded_by_name, uploaded_at,
                approval_status, change_request_status
            ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, NOW(), ?, 'none')
        ");
    }

    if (!$stmt) {
        ven_flash('docs', 'DB error: ' . $conn->error, 'error');
        redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
    }

    if ($folder_id > 0) {
        $stmt->bind_param(
            'iisssssisssiiiss',
            $venture_id,
            $folder_id,
            $doc_group,
            $doc_name,
            $category,
            $description,
            $file_path,
            $file_size,
            $ext,
            $storage_type,
            $s3_url,
            $version,
            $visible,
            $admin_id,
            $admin_name,
            $approval_status
        );
    } else {
        $stmt->bind_param(
            'isssssisssiiiss',
            $venture_id,
            $doc_group,
            $doc_name,
            $category,
            $description,
            $file_path,
            $file_size,
            $ext,
            $storage_type,
            $s3_url,
            $version,
            $visible,
            $admin_id,
            $admin_name,
            $approval_status
        );
    }

    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    if ($ok && $version > 1) {
        $stmt = $conn->prepare("
            UPDATE venture_documents
            SET change_request_status = 'none',
                change_request_reason = NULL,
                change_request_admin_note = NULL,
                change_request_reviewed_at = NULL
            WHERE venture_id = ?
              AND doc_group = ?
        ");
        $stmt->bind_param('is', $venture_id, $doc_group);
        $stmt->execute();
        $stmt->close();
    }

    ven_flash(
        'docs',
        $ok ? 'Document uploaded' . ($version > 1 ? " (v{$version})." : '.') : 'Upload failed: ' . $err,
        $ok ? 'success' : 'error'
    );

    redir_ven('venture-docs', "venture_id={$venture_id}&folder={$folder_id}");
}

/* ============================================================
   UPLOAD MULTIPLE "OTHER" DOCUMENTS
   (each file becomes its own document/doc_group, with its own
   name and description, all uploaded in a single batch)
============================================================ */

if ($action === 'upload_other_docs') {
    $venture_id = (int)($_POST['venture_id'] ?? 0);
    $visible = ($_POST['is_visible_to_investors'] ?? '0') === '1' ? 1 : 0;
    $folder_id = (int)($_POST['folder_id'] ?? 0);
    $return_folder = (int)($_POST['return_folder'] ?? $folder_id);

    if ($venture_id <= 0) {
        ven_flash('docs', 'Invalid venture.', 'error');
        redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
    }

    if ($folder_id > 0) {
        $stmt = $conn->prepare("SELECT id FROM venture_document_folders WHERE id = ? AND venture_id = ? LIMIT 1");
        $stmt->bind_param('ii', $folder_id, $venture_id);
        $stmt->execute();

        if (!$stmt->get_result()->fetch_assoc()) {
            $folder_id = 0;
        }

        $stmt->close();
    }

    $files = $_FILES['other_files'] ?? null;

    $has_any_file = is_array($files)
        && !empty($files['name'])
        && is_array($files['name'])
        && count(array_filter($files['name'], static fn($n) => trim((string)$n) !== '')) > 0;

    if (!$has_any_file) {
        ven_flash('docs', 'Please select at least one file.', 'error');
        redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
    }

    $names = $_POST['other_names'] ?? [];
    $descriptions = $_POST['other_descriptions'] ?? [];

    $allowed_exts = ['pdf','doc','docx','xls','xlsx','ppt','pptx','mp4','mov','zip','rar','png','jpg','jpeg'];
    $s3_mode = defined('AWS_S3_BUCKET') && defined('AWS_ACCESS_KEY') && defined('AWS_SECRET_KEY') && defined('AWS_REGION');
    $max_bytes = $s3_mode ? 500 * 1024 * 1024 : 50 * 1024 * 1024;

    $admin_id = (int)($ADMIN['id'] ?? 0);
    $admin_name = $ADMIN['full_name'] ?? 'Admin';

    $file_count = count($files['name']);
    $uploaded_count = 0;
    $errors = [];

    for ($i = 0; $i < $file_count; $i++) {
        $orig_name = trim((string)($files['name'][$i] ?? ''));
        $tmp_name = (string)($files['tmp_name'][$i] ?? '');
        $error_code = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        $size = (int)($files['size'][$i] ?? 0);

        if ($orig_name === '' || $error_code === UPLOAD_ERR_NO_FILE) {
            // Empty row left in the form - skip silently.
            continue;
        }

        if ($error_code !== UPLOAD_ERR_OK) {
            $errors[] = "{$orig_name}: upload error.";
            continue;
        }

        $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed_exts, true)) {
            $errors[] = "{$orig_name}: file type not allowed.";
            continue;
        }

        if ($size > $max_bytes) {
            $errors[] = "{$orig_name}: file too large. Max " . ($s3_mode ? '500 MB' : '50 MB') . '.';
            continue;
        }

        $doc_name = trim((string)($names[$i] ?? ''));

        if ($doc_name === '') {
            $doc_name = pathinfo($orig_name, PATHINFO_FILENAME);
        }

        $description = trim((string)($descriptions[$i] ?? ''));

        $doc_group = uniqid('dg_', true);
        $filename = uniqid('doc_', true) . '.' . $ext;

        $file_path = '';
        $s3_url = '';
        $storage_type = 'local';

        if ($s3_mode) {
            $file_arr = [
                'name' => $orig_name,
                'tmp_name' => $tmp_name,
                'size' => $size,
                'error' => $error_code,
            ];

            $s3_key = "ventures/{$venture_id}/docs/{$filename}";
            $url = s3_upload($file_arr, $s3_key);

            if (!$url) {
                $errors[] = "{$orig_name}: S3 upload failed.";
                continue;
            }

            $s3_url = $url;
            $storage_type = 's3';
        } else {
            $dest_dir = ven_document_upload_directory();

            if (!is_dir($dest_dir) && !mkdir($dest_dir, 0755, true) && !is_dir($dest_dir)) {
                $errors[] = "{$orig_name}: document directory could not be created.";
                continue;
            }

            if (!is_writable($dest_dir)) {
                $errors[] = "{$orig_name}: document directory is not writable.";
                continue;
            }

            $destination = rtrim($dest_dir, '/') . '/' . $filename;

            if (!move_uploaded_file($tmp_name, $destination)) {
                $errors[] = "{$orig_name}: file upload failed.";
                continue;
            }

            @chmod($destination, 0644);

            $file_path = 'uploads/ventures/docs/' . $filename;
        }

        if ($folder_id > 0) {
            $stmt = $conn->prepare("
                INSERT INTO venture_documents (
                    venture_id, folder_id, doc_group, doc_name, category, description,
                    file_path, file_size, file_ext, storage_type, s3_url,
                    version_number, is_latest, is_visible_to_investors,
                    uploaded_by, uploaded_by_name, uploaded_at,
                    approval_status, change_request_status
                ) VALUES (?, ?, ?, ?, 'other', ?, ?, ?, ?, ?, ?, 1, 1, ?, ?, ?, NOW(), 'pending', 'none')
            ");
            $stmt->bind_param(
                'iissssisssiis',
                $venture_id,
                $folder_id,
                $doc_group,
                $doc_name,
                $description,
                $file_path,
                $size,
                $ext,
                $storage_type,
                $s3_url,
                $visible,
                $admin_id,
                $admin_name
            );
        } else {
            $stmt = $conn->prepare("
                INSERT INTO venture_documents (
                    venture_id, folder_id, doc_group, doc_name, category, description,
                    file_path, file_size, file_ext, storage_type, s3_url,
                    version_number, is_latest, is_visible_to_investors,
                    uploaded_by, uploaded_by_name, uploaded_at,
                    approval_status, change_request_status
                ) VALUES (?, NULL, ?, ?, 'other', ?, ?, ?, ?, ?, ?, 1, 1, ?, ?, ?, NOW(), 'pending', 'none')
            ");
            $stmt->bind_param(
                'issssisssiis',
                $venture_id,
                $doc_group,
                $doc_name,
                $description,
                $file_path,
                $size,
                $ext,
                $storage_type,
                $s3_url,
                $visible,
                $admin_id,
                $admin_name
            );
        }

        $row_ok = $stmt->execute();
        $row_err = $stmt->error;
        $stmt->close();

        if ($row_ok) {
            $uploaded_count++;
        } else {
            $errors[] = "{$orig_name}: {$row_err}";
        }
    }

    if ($uploaded_count === 0) {
        $message = 'No documents were uploaded.';

        if (!empty($errors)) {
            $message .= ' ' . implode(' ', $errors);
        }

        ven_flash('docs', $message, 'error');
        redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
    }

    $message = $uploaded_count === 1
        ? '1 document uploaded successfully.'
        : "{$uploaded_count} documents uploaded successfully.";

    if (!empty($errors)) {
        $message .= ' Some files could not be uploaded: ' . implode(' ', $errors);
    }

    ven_flash('docs', $message, empty($errors) ? 'success' : 'error');
    redir_ven('venture-docs', "venture_id={$venture_id}&folder={$folder_id}");
}

/* ============================================================
   UPLOAD DOCUMENT VIA AJAX
   (used by drag-and-drop of OS files/folders and the "Upload
   Folder" toolbar button; called once per file, never redirects)
============================================================ */

if ($action === 'upload_doc_ajax') {
    header('Content-Type: application/json');

    $venture_id = (int)($_POST['venture_id'] ?? 0);
    $folder_id = (int)($_POST['folder_id'] ?? 0);
    $folder_path = trim((string)($_POST['folder_path'] ?? ''));
    $category = clean_text('category') ?: 'other';
    $description = clean_text('description');
    $visible = ($_POST['is_visible_to_investors'] ?? '0') === '1' ? 1 : 0;

    if ($venture_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid venture.']);
        exit;
    }

    if (empty($_FILES['doc_file']['name'])) {
        echo json_encode(['success' => false, 'message' => 'No file received.']);
        exit;
    }

    $allowed_cats = [
        'pitch_deck','financial_model','business_plan','legal','product_demo',
        'team_profile','due_diligence','impact_report','other'
    ];

    if (!in_array($category, $allowed_cats, true)) {
        $category = 'other';
    }

    $orig_name = (string)$_FILES['doc_file']['name'];
    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    $allowed_exts = ['pdf','doc','docx','xls','xlsx','ppt','pptx','mp4','mov','zip','rar','png','jpg','jpeg'];

    if (!in_array($ext, $allowed_exts, true)) {
        echo json_encode(['success' => false, 'message' => $orig_name . ': file type not allowed.']);
        exit;
    }

    $s3_mode = defined('AWS_S3_BUCKET') && defined('AWS_ACCESS_KEY') && defined('AWS_SECRET_KEY') && defined('AWS_REGION');
    $max_bytes = $s3_mode ? 500 * 1024 * 1024 : 50 * 1024 * 1024;

    if ((int)$_FILES['doc_file']['size'] > $max_bytes) {
        echo json_encode(['success' => false, 'message' => $orig_name . ': file too large.']);
        exit;
    }

    if ($_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => $orig_name . ': upload error.']);
        exit;
    }

    if ($folder_id > 0) {
        $stmt = $conn->prepare("SELECT id FROM venture_document_folders WHERE id = ? AND venture_id = ? LIMIT 1");
        $stmt->bind_param('ii', $folder_id, $venture_id);
        $stmt->execute();

        if (!$stmt->get_result()->fetch_assoc()) {
            $folder_id = 0;
        }

        $stmt->close();
    }

    $target_folder_id = $folder_path !== ''
        ? ven_resolve_folder_path($conn, $venture_id, $folder_id, $folder_path)
        : $folder_id;

    $doc_name = pathinfo($orig_name, PATHINFO_FILENAME);
    $doc_group = uniqid('dg_', true);
    $filename = uniqid('doc_', true) . '.' . $ext;

    $file_path = '';
    $s3_url = '';
    $storage_type = 'local';
    $file_size = (int)$_FILES['doc_file']['size'];

    if ($s3_mode) {
        $s3_key = "ventures/{$venture_id}/docs/{$filename}";
        $url = s3_upload($_FILES['doc_file'], $s3_key);

        if (!$url) {
            echo json_encode(['success' => false, 'message' => $orig_name . ': S3 upload failed.']);
            exit;
        }

        $s3_url = $url;
        $storage_type = 's3';
    } else {
        $dest_dir = ven_document_upload_directory();

        if (!is_dir($dest_dir) && !mkdir($dest_dir, 0755, true) && !is_dir($dest_dir)) {
            echo json_encode(['success' => false, 'message' => 'Document directory could not be created.']);
            exit;
        }

        if (!is_writable($dest_dir)) {
            echo json_encode(['success' => false, 'message' => 'Document directory is not writable.']);
            exit;
        }

        $destination = rtrim($dest_dir, '/') . '/' . $filename;

        if (!move_uploaded_file($_FILES['doc_file']['tmp_name'], $destination)) {
            echo json_encode(['success' => false, 'message' => $orig_name . ': file upload failed.']);
            exit;
        }

        @chmod($destination, 0644);
        $file_path = 'uploads/ventures/docs/' . $filename;
    }

    $admin_id = (int)($ADMIN['id'] ?? 0);
    $admin_name = $ADMIN['full_name'] ?? 'Admin';

    if ($target_folder_id > 0) {
        $stmt = $conn->prepare("
            INSERT INTO venture_documents (
                venture_id, folder_id, doc_group, doc_name, category, description,
                file_path, file_size, file_ext, storage_type, s3_url,
                version_number, is_latest, is_visible_to_investors,
                uploaded_by, uploaded_by_name, uploaded_at,
                approval_status, change_request_status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, ?, ?, NOW(), 'pending', 'none')
        ");
    } else {
        $stmt = $conn->prepare("
            INSERT INTO venture_documents (
                venture_id, folder_id, doc_group, doc_name, category, description,
                file_path, file_size, file_ext, storage_type, s3_url,
                version_number, is_latest, is_visible_to_investors,
                uploaded_by, uploaded_by_name, uploaded_at,
                approval_status, change_request_status
            ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, ?, ?, NOW(), 'pending', 'none')
        ");
    }

    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $conn->error]);
        exit;
    }

    if ($target_folder_id > 0) {
        $stmt->bind_param(
            'iisssssisssiis',
            $venture_id,
            $target_folder_id,
            $doc_group,
            $doc_name,
            $category,
            $description,
            $file_path,
            $file_size,
            $ext,
            $storage_type,
            $s3_url,
            $visible,
            $admin_id,
            $admin_name
        );
    } else {
        $stmt->bind_param(
            'isssssisssiis',
            $venture_id,
            $doc_group,
            $doc_name,
            $category,
            $description,
            $file_path,
            $file_size,
            $ext,
            $storage_type,
            $s3_url,
            $visible,
            $admin_id,
            $admin_name
        );
    }

    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    echo json_encode([
        'success' => $ok,
        'message' => $ok ? 'Uploaded.' : ('Upload failed: ' . $err),
        'folder_id' => $target_folder_id,
    ]);
    exit;
}

/* ============================================================
   RESTORE VERSION
============================================================ */

if ($action === 'restore_version') {
    $id = (int)($_POST['id'] ?? 0);
    $venture_id = (int)($_POST['venture_id'] ?? 0);
    $doc_group = clean_text('doc_group');

    if ($id <= 0 || $venture_id <= 0 || $doc_group === '') {
        ven_flash('docs', 'Invalid restore request.', 'error');
        redir_ven('venture-docs', "venture_id={$venture_id}");
    }

    $stmt = $conn->prepare("UPDATE venture_documents SET is_latest = 0 WHERE venture_id = ? AND doc_group = ?");
    $stmt->bind_param('is', $venture_id, $doc_group);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("UPDATE venture_documents SET is_latest = 1 WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    ven_flash('docs', 'Version restored successfully.', 'success');
    redir_ven('venture-docs', "venture_id={$venture_id}");
}

/* ============================================================
   DELETE DOCUMENT
============================================================ */

if ($action === 'delete_doc') {
    $venture_id = (int)($_POST['venture_id'] ?? 0);
    $doc_group = clean_text('doc_group');
    $return_folder = (int)($_POST['return_folder'] ?? 0);

    if ($venture_id <= 0 || $doc_group === '') {
        ven_flash('docs', 'Invalid delete request.', 'error');
        redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
    }

    $stmt = $conn->prepare("SELECT * FROM venture_documents WHERE venture_id = ? AND doc_group = ?");
    $stmt->bind_param('is', $venture_id, $doc_group);
    $stmt->execute();
    $rows = $stmt->get_result();

    while ($d = $rows->fetch_assoc()) {
        if (($d['storage_type'] ?? '') === 's3' && !empty($d['s3_url'])) {
            s3_delete($d['s3_url']);
        } elseif (!empty($d['file_path'])) {
            $p = ven_resolve_local_document_path((string)$d['file_path']);

            if ($p !== false && is_file($p)) {
                @unlink($p);
            }
        }
    }

    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM venture_documents WHERE venture_id = ? AND doc_group = ?");
    $stmt->bind_param('is', $venture_id, $doc_group);
    $stmt->execute();
    $stmt->close();

    ven_flash('docs', 'Document deleted successfully.', 'success');
    redir_ven('venture-docs', "venture_id={$venture_id}&folder={$return_folder}");
}

ven_flash('ventures', 'Invalid action.', 'error');
redir_ven();