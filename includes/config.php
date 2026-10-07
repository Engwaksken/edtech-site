<?php
declare(strict_types=1);

require_once __DIR__ . '/environment.php';
require_once __DIR__ . '/security.php';

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: '');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: '');
define('DB_CHARSET', 'utf8mb4');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));

define('SITE_URL', rtrim(getenv('SITE_URL') ?: 'https://www.edtech.hivecolab.com', '/'));
define('ADMIN_URL', SITE_URL . '/admin');

define('BASE_PATH', dirname(__DIR__));
define('UPLOAD_DIR', BASE_PATH . '/uploads/');
define('UPLOAD_URL', SITE_URL . '/uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024);

site_validate_request_origin(SITE_URL);
if (site_is_unsafe_request()) {
    site_require_csrf();
}
site_enable_form_protection(SITE_URL);

try {
    if (DB_USER === '' || DB_NAME === '' || DB_PASS === '') {
        throw new RuntimeException('Required database environment variables are missing.');
    }
    $conn = mysqli_init();
    $sslCa = getenv('DB_SSL_CA') ?: '';
    if ($sslCa !== '') {
        $conn->ssl_set(null, null, $sslCa, null, null);
        $conn->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
    } elseif (!in_array(DB_HOST, ['localhost', '127.0.0.1', '::1'], true)) {
        throw new RuntimeException('Remote database connections require DB_SSL_CA.');
    }
    if (!$conn->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT, null, $sslCa !== '' ? MYSQLI_CLIENT_SSL : 0)) {
        throw new RuntimeException('Database connection failed.');
    }
} catch (Throwable $exception) {
    error_log('Database initialization failed: ' . $exception->getMessage());
    if (PHP_SAPI === 'cli') {
        throw new RuntimeException('Database initialization failed: ' . $exception->getMessage());
    }
    http_response_code(503);
    header('Retry-After: 60');
    exit('We are temporarily unable to load this page. Please try again shortly.');
}

mysqli_set_charset($conn, DB_CHARSET);
date_default_timezone_set('Africa/Kampala');

if (!function_exists('h')) {
    function h($str): string {
        return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

function esc(mysqli $conn, $val): string {
    return mysqli_real_escape_string($conn, trim((string)$val));
}

function get_setting(mysqli $conn, string $key, string $default = ''): string {
    static $cache = [];
    $cache_key = spl_object_id($conn) . ':' . $key;
    if (array_key_exists($cache_key, $cache)) {
        return $cache[$cache_key] ?? $default;
    }
    $stmt = $conn->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1");

    if (!$stmt) {
        return $default;
    }

    $stmt->bind_param("s", $key);
    $stmt->execute();

    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;

    $stmt->close();

    $cache[$cache_key] = isset($row['setting_value']) ? trim((string)$row['setting_value']) : null;
    return $cache[$cache_key] ?? $default;
}

function get_settings(mysqli $conn, array $keys): array {
    $out = [];

    foreach ($keys as $key) {
        $out[$key] = get_setting($conn, $key);
    }

    return $out;
}

function asset_url(?string $path, string $fallback = ''): string {
    $path = trim((string)$path);

    if ($path === '') {
        return $fallback !== '' ? asset_url($fallback) : '';
    }

    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#^\.\./#', '', $path);
    $path = ltrim($path, '/');

    $optimized = [
        'assets/images/logo.png' => 'assets/images/logo.webp',
        'assets/images/logo_white.png' => 'assets/images/logo_white.webp',
        'assets/images/masteercard_foundation.png' => 'assets/images/masteercard_foundation.webp',
        'assets/images/foundation_white.png' => 'assets/images/foundation_white.webp',
        'assets/images/edtech-hero.jpg' => 'assets/images/edtech-hero.webp',
    ];
    if (isset($optimized[$path]) && is_file(BASE_PATH . '/' . $optimized[$path])) {
        $path = $optimized[$path];
    }

    return rtrim(SITE_URL, '/') . '/' . $path;
}

function img_src(?string $path, string $fallback = 'assets/images/placeholder.png'): string {
    return asset_url($path, $fallback);
}

function upload_image(string $file_key, string $sub_dir, string &$error = ''): string {
    if (!isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
        return '';
    }

    $file = $_FILES[$file_key];

    if ($file['size'] > MAX_FILE_SIZE) {
        $error = 'Image too large. Maximum size is 5 MB.';
        return '';
    }

    $mime = mime_content_type($file['tmp_name']);
    $allowed = [
        'image/jpeg'    => 'jpg',
        'image/png'     => 'png',
        'image/gif'     => 'gif',
        'image/webp'    => 'webp'
    ];

    if (!isset($allowed[$mime])) {
        $error = 'Please choose a JPG, PNG, GIF or WEBP image.';
        return '';
    }

    $safe_sub_dir = trim(str_replace(['..', '\\'], ['', '/'], $sub_dir), '/');
    $dir = UPLOAD_DIR . $safe_sub_dir . '/';

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $ext = $allowed[$mime];
    $name = 'img_' . bin2hex(random_bytes(16)) . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) {
        $error = 'Failed to save image.';
        return '';
    }

    return 'uploads/' . $safe_sub_dir . '/' . $name;
}

function delete_image(?string $path): void {
    $path = trim((string)$path);

    if ($path === '' || preg_match('/^https?:\/\//i', $path)) {
        return;
    }

    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#^\.\./#', '', $path);
    $path = ltrim($path, '/');

    $full_path = realpath(BASE_PATH . '/' . $path);
    $upload_root = realpath(UPLOAD_DIR);

    if ($full_path !== false && $upload_root !== false
        && str_starts_with($full_path, $upload_root . DIRECTORY_SEPARATOR)
        && is_file($full_path)) {
        unlink($full_path);
    }
}

function slugify(string $str): string {
    $str = strtolower(trim($str));
    $str = preg_replace('/[^a-z0-9\s-]/', '', $str);
    $str = preg_replace('/[\s-]+/', '-', $str);
    return trim($str, '-');
}

function unique_slug(mysqli $conn, string $table, string $col, string $slug, int $exclude_id = 0): string {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $col   = preg_replace('/[^a-zA-Z0-9_]/', '', $col);

    $base = slugify($slug);
    $slug_value = $base;
    $i = 1;

    while (true) {
        $sql = "SELECT id FROM `$table` WHERE `$col` = ?";
        if ($exclude_id > 0) {
            $sql .= " AND id != ?";
        }
        $sql .= " LIMIT 1";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return $slug_value;
        }

        if ($exclude_id > 0) {
            $stmt->bind_param("si", $slug_value, $exclude_id);
        } else {
            $stmt->bind_param("s", $slug_value);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $exists = $res && $res->num_rows > 0;

        $stmt->close();

        if (!$exists) {
            break;
        }

        $slug_value = $base . '-' . $i++;
    }

    return $slug_value;
}

function flash(string $key, ?string $msg = null, string $type = 'success') {
    if ($msg !== null) {
        $_SESSION['flash'][$key] = [
            'msg' => $msg,
            'type' => $type
        ];
        return null;
    }

    if (isset($_SESSION['flash'][$key])) {
        $flash = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $flash;
    }

    return null;
}

function show_flash(string $key): void {
    $flash = flash($key);

    if ($flash) {
        $cls = ($flash['type'] ?? '') === 'error' ? 'alert-error' : 'alert-success';
        echo '<div class="alert ' . h($cls) . '">' . h($flash['msg'] ?? '') . '</div>';
    }
}

function truncate(string $str, int $len = 120, string $suffix = '…'): string {
    if (mb_strlen($str) <= $len) {
        return $str;
    }

    return mb_substr($str, 0, $len) . $suffix;
}

function paginate(mysqli $conn, string $table, string $where, int $per_page): array {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $page = max(1, (int)($_GET['page'] ?? 1));

    $res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM `$table` WHERE $where");
    $total = $res ? (int)mysqli_fetch_assoc($res)['c'] : 0;

    $pages = max(1, (int)ceil($total / $per_page));
    $page = min($page, $pages);

    return [
        (($page - 1) * $per_page),
        $pages,
        $page,
        $total
    ];
}
