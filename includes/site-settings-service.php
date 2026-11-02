<?php
declare(strict_types=1);

/*
 * Shared services for the admin Site Settings page and the runtime hooks that
 * consume those settings (theme/font output, S3 configuration, backups, cron).
 *
 * Every function here is defensive: a missing table, key or extension must
 * never break a public page. Admin callers surface the exceptions instead.
 */

require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/backup-encryption.php';

/* --------------------------------------------------------------------------
   Settings storage
   -------------------------------------------------------------------------- */

function ss_ensure_tables(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS site_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT NULL,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS uat_test_cases (
            id INT AUTO_INCREMENT PRIMARY KEY,
            module VARCHAR(120) NOT NULL,
            title VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'not_tested',
            tester VARCHAR(150) NULL,
            tested_at DATE NULL,
            notes TEXT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function ss_get_all(mysqli $conn): array
{
    $settings = [];
    $res = $conn->query('SELECT setting_key, setting_value FROM site_settings');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $settings[(string)$row['setting_key']] = (string)($row['setting_value'] ?? '');
        }
    }
    return $settings;
}

function ss_save(mysqli $conn, string $key, string $value): void
{
    $stmt = $conn->prepare('
        INSERT INTO site_settings (setting_key, setting_value, updated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare the settings update.');
    }
    $stmt->bind_param('ss', $key, $value);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Could not save setting ' . $key . '.');
    }
    $stmt->close();
}

function ss_secret_context(string $key): string
{
    return 'site_settings:' . $key;
}

/** Store a secret encrypted at rest. An empty value leaves the stored secret untouched. */
function ss_save_secret(mysqli $conn, string $key, string $plaintext): void
{
    if ($plaintext === '') {
        return;
    }
    ss_save($conn, $key, site_encrypt_secret($plaintext, ss_secret_context($key)));
}

function ss_secret(array $settings, string $key): string
{
    $stored = (string)($settings[$key] ?? '');
    if ($stored === '') {
        return '';
    }
    try {
        return site_decrypt_secret($stored, ss_secret_context($key));
    } catch (Throwable $e) {
        return '';
    }
}

function ss_mask(string $value): string
{
    if ($value === '') {
        return '';
    }
    return strlen($value) <= 4 ? str_repeat('•', 4) : str_repeat('•', 6) . substr($value, -4);
}

function ss_encryption_available(): bool
{
    try {
        site_encryption_key();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/* --------------------------------------------------------------------------
   Theme colours and fonts
   -------------------------------------------------------------------------- */

function ss_theme_defaults(): array
{
    return [
        'theme_primary'       => '#fc7f10',
        'theme_primary_dark'  => '#e06900',
        'theme_accent'        => '#ffa85c',
        'theme_secondary'     => '#2f7b6b',
        'theme_text'          => '#1f2328',
        'theme_sidebar_bg'    => '#0d1117',
        'font_body'           => 'Outfit',
        'font_heading'        => 'Poppins',
        'font_base_size'      => '16',
        'font_heading_weight' => '600',
    ];
}

function ss_theme_presets(): array
{
    return [
        'Default (Orange)' => ['#fc7f10', '#e06900', '#ffa85c', '#2f7b6b', '#1f2328', '#0d1117'],
        'Ocean'            => ['#0369a1', '#075985', '#38bdf8', '#0f766e', '#0f172a', '#0b1b2b'],
        'Forest'           => ['#15803d', '#166534', '#4ade80', '#a16207', '#14211a', '#0f1f17'],
        'Royal'            => ['#6d28d9', '#5b21b6', '#a78bfa', '#db2777', '#1e1b2e', '#151225'],
        'Crimson'          => ['#be123c', '#9f1239', '#fb7185', '#334155', '#1f1416', '#1c1012'],
        'Slate'            => ['#334155', '#1e293b', '#94a3b8', '#0ea5e9', '#0f172a', '#0b1120'],
    ];
}

/** Font name => CSS fallback stack. 'System UI' needs no web font. */
function ss_font_choices(): array
{
    return [
        'Outfit'            => 'sans-serif',
        'Poppins'           => 'sans-serif',
        'Inter'             => 'sans-serif',
        'Roboto'            => 'sans-serif',
        'Open Sans'         => 'sans-serif',
        'Lato'              => 'sans-serif',
        'Montserrat'        => 'sans-serif',
        'Nunito'            => 'sans-serif',
        'DM Sans'           => 'sans-serif',
        'Source Sans 3'     => 'sans-serif',
        'Work Sans'         => 'sans-serif',
        'Raleway'           => 'sans-serif',
        'Merriweather'      => 'serif',
        'Playfair Display'  => 'serif',
        'Lora'              => 'serif',
        'System UI'         => 'system',
    ];
}

function ss_valid_hex(string $value): bool
{
    return (bool)preg_match('/^#[0-9a-fA-F]{6}$/', $value);
}

function ss_font_stack(string $font): string
{
    $choices = ss_font_choices();
    if (!isset($choices[$font]) || $choices[$font] === 'system') {
        return 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
    }
    return '"' . $font . '", system-ui, -apple-system, "Segoe UI", ' . $choices[$font];
}

/** Values that differ from the defaults; an untouched install renders no overrides at all. */
function ss_theme_overrides(array $settings): array
{
    $out = [];
    foreach (ss_theme_defaults() as $key => $default) {
        $value = trim((string)($settings[$key] ?? ''));
        if ($value !== '' && strcasecmp($value, $default) !== 0) {
            $out[$key] = $value;
        }
    }
    return $out;
}

function ss_google_fonts_url(array $settings): string
{
    $over = ss_theme_overrides($settings);
    $families = [];
    foreach (['font_body', 'font_heading'] as $key) {
        if (!isset($over[$key])) {
            continue;
        }
        $font = $over[$key];
        $choices = ss_font_choices();
        if (!isset($choices[$font]) || $choices[$font] === 'system') {
            continue;
        }
        $families[$font] = 'family=' . str_replace(' ', '+', $font) . ':wght@400;500;600;700';
    }
    if (!$families) {
        return '';
    }
    return 'https://fonts.googleapis.com/css2?' . implode('&', $families) . '&display=swap';
}

function ss_theme_css(array $settings, bool $is_admin): string
{
    $over = ss_theme_overrides($settings);
    if (!$over) {
        return '';
    }

    $vars = [];
    $map = [
        'theme_primary'      => ['--primary', '--primary-color', '--ui-primary', '--sidebar-active', '--blue'],
        'theme_primary_dark' => ['--primary-dark', '--ui-primary-dark'],
        'theme_accent'       => ['--accent-color', '--primary-2'],
        'theme_secondary'    => ['--teal', '--teal-color'],
        'theme_text'         => ['--text', '--ink', '--ui-text'],
    ];
    if ($is_admin) {
        $map['theme_sidebar_bg'] = ['--sidebar-bg'];
    }
    foreach ($map as $key => $props) {
        if (isset($over[$key]) && ss_valid_hex($over[$key])) {
            foreach ($props as $prop) {
                $vars[] = $prop . ':' . $over[$key];
            }
        }
    }

    $rules = [];
    if (isset($over['font_body'])) {
        $stack = ss_font_stack($over['font_body']);
        foreach (['--ui-body', '--font-body', '--ff-base', '--font'] as $prop) {
            $vars[] = $prop . ':' . $stack;
        }
        $rules[] = 'html body{font-family:' . $stack . '}';
    }
    if (isset($over['font_heading'])) {
        $stack = ss_font_stack($over['font_heading']);
        foreach (['--ui-heading', '--font-display', '--ff-display', '--ff-serif'] as $prop) {
            $vars[] = $prop . ':' . $stack;
        }
        $rules[] = 'html h1,html h2,html h3,html h4,html h5,html h6{font-family:' . $stack . '}';
    }
    if (isset($over['font_heading_weight']) && in_array($over['font_heading_weight'], ['400', '500', '600', '700', '800'], true)) {
        $rules[] = 'html h1,html h2,html h3,html h4,html h5,html h6{font-weight:' . $over['font_heading_weight'] . '}';
    }
    if (isset($over['font_base_size'])) {
        $size = (int)$over['font_base_size'];
        if ($size >= 12 && $size <= 20) {
            $rules[] = 'html{font-size:' . round($size / 16 * 100, 3) . '%}';
        }
    }

    $css = '';
    if ($vars) {
        // html:root outranks the plain :root blocks in the stylesheets regardless of load order.
        $css .= 'html:root{' . implode(';', $vars) . '}';
    }
    return $css . implode('', $rules);
}

/* --------------------------------------------------------------------------
   Amazon S3 (Signature V4) client
   -------------------------------------------------------------------------- */

/**
 * @param array{bucket:string,region:string,access_key:string,secret_key:string,endpoint?:string} $cfg
 * @return array{status:int,body:string,error:string}
 */
function ss_s3_request(array $cfg, string $method, string $key, ?string $file = null, string $content_type = 'application/octet-stream'): array
{
    foreach (['bucket', 'region', 'access_key', 'secret_key'] as $required) {
        if (trim((string)($cfg[$required] ?? '')) === '') {
            throw new RuntimeException('S3 ' . str_replace('_', ' ', $required) . ' is not configured.');
        }
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for S3 transfers.');
    }

    $bucket = trim($cfg['bucket']);
    $region = trim($cfg['region']);
    $endpoint = rtrim(trim((string)($cfg['endpoint'] ?? '')), '/');
    $encoded_key = implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));

    if ($endpoint !== '') {
        $parts = parse_url($endpoint);
        if (!$parts || empty($parts['host']) || ($parts['scheme'] ?? '') !== 'https') {
            throw new RuntimeException('The S3 endpoint must be an https:// URL.');
        }
        $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = '/' . rawurlencode($bucket) . '/' . $encoded_key;
    } else {
        $host = $bucket . '.s3.' . $region . '.amazonaws.com';
        $path = '/' . $encoded_key;
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $amz_date = $now->format('Ymd\THis\Z');
    $date = $now->format('Ymd');
    $payload_hash = $file !== null ? hash_file('sha256', $file) : hash('sha256', '');

    $headers = [
        'host' => $host,
        'x-amz-content-sha256' => $payload_hash,
        'x-amz-date' => $amz_date,
    ];
    if ($file !== null) {
        $headers['content-type'] = $content_type;
        $headers['x-amz-server-side-encryption'] = 'AES256';
    }
    ksort($headers);

    $canonical_headers = '';
    foreach ($headers as $name => $value) {
        $canonical_headers .= $name . ':' . trim($value) . "\n";
    }
    $signed_headers = implode(';', array_keys($headers));
    $canonical_request = implode("\n", [$method, $path, '', $canonical_headers, $signed_headers, $payload_hash]);
    $scope = $date . '/' . $region . '/s3/aws4_request';
    $string_to_sign = implode("\n", ['AWS4-HMAC-SHA256', $amz_date, $scope, hash('sha256', $canonical_request)]);

    $k_date = hash_hmac('sha256', $date, 'AWS4' . $cfg['secret_key'], true);
    $k_region = hash_hmac('sha256', $region, $k_date, true);
    $k_service = hash_hmac('sha256', 's3', $k_region, true);
    $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
    $signature = hash_hmac('sha256', $string_to_sign, $k_signing);

    $http_headers = [];
    foreach ($headers as $name => $value) {
        if ($name !== 'host') {
            $http_headers[] = $name . ': ' . $value;
        }
    }
    $http_headers[] = 'Authorization: AWS4-HMAC-SHA256 Credential=' . $cfg['access_key'] . '/' . $scope
        . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;

    $ch = curl_init('https://' . $host . $path);
    $fh = null;
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $file !== null ? 0 : 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($file !== null) {
        $fh = fopen($file, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Could not open ' . basename($file) . ' for upload.');
        }
        $size = filesize($file);
        $http_headers[] = 'Content-Length: ' . $size;
        curl_setopt_array($ch, [
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $fh,
            CURLOPT_INFILESIZE => $size,
        ]);
    } elseif ($method === 'HEAD') {
        curl_setopt($ch, CURLOPT_NOBODY, true);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $http_headers);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if (is_resource($fh)) {
        fclose($fh);
    }

    return ['status' => $status, 'body' => is_string($body) ? $body : '', 'error' => $error];
}

function ss_s3_error_message(array $response): string
{
    if ($response['error'] !== '') {
        return $response['error'];
    }
    if (preg_match('/<Code>([^<]+)<\/Code>.*?<Message>([^<]+)<\/Message>/s', $response['body'], $m)) {
        return $m[1] . ': ' . $m[2];
    }
    return 'HTTP ' . $response['status'];
}

/** Write, then delete, a small object: proves the credentials can store files in the bucket. */
function ss_s3_test(array $cfg, string $prefix = ''): void
{
    $tmp = tempnam(sys_get_temp_dir(), 's3t');
    file_put_contents($tmp, 'connection test ' . date('c'));
    $key = trim($prefix, '/') . ($prefix !== '' ? '/' : '') . '.connection-test-' . bin2hex(random_bytes(4)) . '.txt';
    try {
        $put = ss_s3_request($cfg, 'PUT', $key, $tmp, 'text/plain');
        if ($put['status'] < 200 || $put['status'] >= 300) {
            throw new RuntimeException('Upload test failed: ' . ss_s3_error_message($put));
        }
        $del = ss_s3_request($cfg, 'DELETE', $key);
        if ($del['status'] < 200 || $del['status'] >= 300) {
            throw new RuntimeException('Upload worked but cleanup failed (' . ss_s3_error_message($del) . '). Grant s3:DeleteObject or remove ' . $key . ' manually.');
        }
    } finally {
        @unlink($tmp);
    }
}

/** Document-storage credentials: environment variables take precedence over saved settings. */
function ss_aws_config(array $settings): array
{
    return [
        'bucket'     => (string)(getenv('AWS_S3_BUCKET') ?: ($settings['aws_s3_bucket'] ?? '')),
        'region'     => (string)(getenv('AWS_REGION') ?: ($settings['aws_region'] ?? '')),
        'access_key' => (string)(getenv('AWS_ACCESS_KEY_ID') ?: ss_secret($settings, 'aws_access_key')),
        'secret_key' => (string)(getenv('AWS_SECRET_ACCESS_KEY') ?: ss_secret($settings, 'aws_secret_key')),
        'endpoint'   => '',
    ];
}

function ss_backup_remote_config(array $settings): array
{
    $use_aws = ($settings['backup_remote_use_aws_creds'] ?? '1') === '1';
    $aws = ss_aws_config($settings);
    return [
        'bucket'     => (string)($settings['backup_remote_bucket'] ?? ''),
        'region'     => (string)(($settings['backup_remote_region'] ?? '') !== '' ? $settings['backup_remote_region'] : $aws['region']),
        'access_key' => $use_aws ? $aws['access_key'] : ss_secret($settings, 'backup_remote_access_key'),
        'secret_key' => $use_aws ? $aws['secret_key'] : ss_secret($settings, 'backup_remote_secret_key'),
        'endpoint'   => (string)($settings['backup_remote_endpoint'] ?? ''),
    ];
}

/**
 * Runtime hook used by includes/config.php: defines the AWS_* constants that the
 * existing venture/portal document upload code checks, when S3 storage is switched on.
 */
function ss_define_aws_constants(mysqli $conn): void
{
    if (defined('AWS_S3_BUCKET')) {
        return;
    }
    $keys = ['aws_s3_documents_enabled', 'aws_s3_bucket', 'aws_region', 'aws_access_key', 'aws_secret_key'];
    $in = "'" . implode("','", $keys) . "'";
    $res = @$conn->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($in)");
    if (!$res) {
        return;
    }
    $settings = [];
    while ($row = $res->fetch_assoc()) {
        $settings[(string)$row['setting_key']] = (string)($row['setting_value'] ?? '');
    }
    if (($settings['aws_s3_documents_enabled'] ?? '0') !== '1') {
        return;
    }
    $cfg = ss_aws_config($settings);
    if ($cfg['bucket'] === '' || $cfg['region'] === '' || $cfg['access_key'] === '' || $cfg['secret_key'] === '') {
        return;
    }
    define('AWS_S3_BUCKET', $cfg['bucket']);
    define('AWS_REGION', $cfg['region']);
    define('AWS_ACCESS_KEY', $cfg['access_key']);
    define('AWS_SECRET_KEY', $cfg['secret_key']);
}

/* --------------------------------------------------------------------------
   Backups
   -------------------------------------------------------------------------- */

function ss_public_root(): string
{
    return (string)realpath(dirname(__DIR__));
}

function ss_default_backup_dir(): string
{
    return dirname(ss_public_root()) . DIRECTORY_SEPARATOR . 'edtech-backups';
}

function ss_path_inside_public_root(string $path): bool
{
    $root = ss_public_root();
    $norm = static fn(string $p): string => PHP_OS_FAMILY === 'Windows' ? strtolower(rtrim(str_replace('/', '\\', $p), '\\')) : rtrim($p, '/');
    $p = $norm($path);
    $r = $norm($root);
    return $p === $r || str_starts_with($p, $r . DIRECTORY_SEPARATOR);
}

/** Resolve (and create) the local backup directory; it must live outside the web root. */
function ss_backup_dir(array $settings, bool $create = true): string
{
    $dir = trim((string)($settings['backup_local_path'] ?? ''));
    if ($dir === '') {
        $dir = ss_default_backup_dir();
    }
    if ($create && !is_dir($dir) && !@mkdir($dir, 0750, true)) {
        throw new RuntimeException('Could not create the backup folder. Check that PHP can write to its parent directory.');
    }
    $real = is_dir($dir) ? (string)realpath($dir) : $dir;
    if (ss_path_inside_public_root($real)) {
        throw new RuntimeException('The backup folder must be outside the public web root so backups cannot be downloaded directly.');
    }
    return $real;
}

const SS_BACKUP_NAME_PATTERN = '/^backup-\d{8}-\d{6}-(db\.sql\.gz|uploads\.zip)(\.edenc)?$/';

function ss_backup_list(array $settings): array
{
    try {
        $dir = ss_backup_dir($settings, false);
    } catch (Throwable $e) {
        return [];
    }
    if (!is_dir($dir)) {
        return [];
    }
    $files = [];
    foreach (scandir($dir) ?: [] as $name) {
        if (!preg_match(SS_BACKUP_NAME_PATTERN, $name)) {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        $files[] = [
            'name' => $name,
            'size' => (int)filesize($path),
            'mtime' => (int)filemtime($path),
            'type' => str_contains($name, '-db.') ? 'Database' : 'Uploads',
            'encrypted' => str_ends_with($name, '.edenc'),
        ];
    }
    usort($files, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $files;
}

function ss_backup_file_path(array $settings, string $name): string
{
    if (!preg_match(SS_BACKUP_NAME_PATTERN, $name)) {
        throw new RuntimeException('Invalid backup file name.');
    }
    $path = ss_backup_dir($settings, false) . DIRECTORY_SEPARATOR . $name;
    if (!is_file($path)) {
        throw new RuntimeException('Backup file not found.');
    }
    return $path;
}

function ss_sql_value(mysqli $conn, $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    return "'" . $conn->real_escape_string((string)$value) . "'";
}

/** Pure-PHP logical dump (no mysqldump binary needed on shared hosting). */
function ss_dump_database(mysqli $conn, string $destination): int
{
    $gz = gzopen($destination, 'wb6');
    if ($gz === false) {
        throw new RuntimeException('Could not create the database dump file.');
    }
    $tables = 0;
    try {
        $db = $conn->query('SELECT DATABASE()')->fetch_row()[0] ?? '';
        gzwrite($gz, "-- EdTech database backup\n-- Database: {$db}\n-- Created: " . date('c') . "\n\n");
        gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

        $views = [];
        $res = $conn->query('SHOW FULL TABLES');
        $names = [];
        while ($row = $res->fetch_row()) {
            if (strtoupper((string)$row[1]) === 'VIEW') {
                $views[] = (string)$row[0];
            } else {
                $names[] = (string)$row[0];
            }
        }
        $res->free();

        foreach ($names as $table) {
            $q = '`' . str_replace('`', '``', $table) . '`';
            $create = $conn->query("SHOW CREATE TABLE {$q}");
            if (!$create) {
                continue;
            }
            $ddl = $create->fetch_row()[1] ?? '';
            $create->free();
            gzwrite($gz, "DROP TABLE IF EXISTS {$q};\n{$ddl};\n\n");

            $rows = $conn->query("SELECT * FROM {$q}", MYSQLI_USE_RESULT);
            if ($rows) {
                $batch = [];
                $columns = null;
                while ($row = $rows->fetch_assoc()) {
                    if ($columns === null) {
                        $columns = '(' . implode(',', array_map(static fn($c) => '`' . str_replace('`', '``', (string)$c) . '`', array_keys($row))) . ')';
                    }
                    $batch[] = '(' . implode(',', array_map(static fn($v) => ss_sql_value($conn, $v), array_values($row))) . ')';
                    if (count($batch) >= 200) {
                        gzwrite($gz, "INSERT INTO {$q} {$columns} VALUES\n" . implode(",\n", $batch) . ";\n");
                        $batch = [];
                    }
                }
                if ($batch) {
                    gzwrite($gz, "INSERT INTO {$q} {$columns} VALUES\n" . implode(",\n", $batch) . ";\n");
                }
                $rows->free();
            }
            gzwrite($gz, "\n");
            $tables++;
        }

        foreach ($views as $view) {
            $q = '`' . str_replace('`', '``', $view) . '`';
            $create = $conn->query("SHOW CREATE VIEW {$q}");
            if ($create) {
                $ddl = $create->fetch_row()[1] ?? '';
                $create->free();
                // DEFINER names a server account that may not exist where the dump is restored.
                $ddl = (string)preg_replace('/\s+DEFINER=(`[^`]*`|[^\s@]+)(@(`[^`]*`|\S+))?(?=\s)/', '', $ddl);
                gzwrite($gz, "DROP VIEW IF EXISTS {$q};\n{$ddl};\n\n");
            }
        }

        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
    } finally {
        gzclose($gz);
    }
    return $tables;
}

function ss_zip_uploads(string $destination): int
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('The PHP zip extension is required to back up uploaded files.');
    }
    $source = ss_public_root() . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($source)) {
        throw new RuntimeException('The uploads folder was not found.');
    }
    $zip = new ZipArchive();
    if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException('Could not create the uploads archive.');
    }
    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relative = 'uploads/' . str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
        $zip->addFile($file->getPathname(), $relative);
        $count++;
    }
    if (!$zip->close()) {
        throw new RuntimeException('Could not finish the uploads archive.');
    }
    return $count;
}

function ss_backup_log(mysqli $conn, array $settings, array $entry): void
{
    $log = json_decode((string)($settings['backup_log'] ?? '[]'), true);
    $log = is_array($log) ? $log : [];
    array_unshift($log, $entry);
    ss_save($conn, 'backup_log', json_encode(array_slice($log, 0, 25), JSON_UNESCAPED_SLASHES));
    ss_save($conn, 'backup_last_run_at', $entry['at']);
    ss_save($conn, 'backup_last_status', $entry['ok'] ? 'success' : 'failed');
    ss_save($conn, 'backup_last_message', $entry['message']);
    if ($entry['ok']) {
        ss_save($conn, 'backup_last_success_at', $entry['at']);
    }
}

/**
 * Create a backup. Options: db (bool), uploads (bool), remote (bool|null = follow settings), trigger (string).
 * Returns ['ok'=>bool,'message'=>string,'files'=>string[]].
 */
function ss_run_backup(mysqli $conn, array $settings, array $options = []): array
{
    @set_time_limit(0);
    $include_db = $options['db'] ?? true;
    $include_uploads = $options['uploads'] ?? (($settings['backup_include_uploads'] ?? '0') === '1');
    $remote = $options['remote'] ?? (($settings['backup_remote_enabled'] ?? '0') === '1');
    $trigger = (string)($options['trigger'] ?? 'manual');
    $encrypt = ($settings['backup_encrypt'] ?? '1') === '1';

    $entry = ['at' => date('Y-m-d H:i:s'), 'trigger' => $trigger, 'ok' => false, 'message' => '', 'files' => [], 'remote' => ''];
    $lock = null;
    try {
        if (!$include_db && !$include_uploads) {
            throw new RuntimeException('Choose at least the database or uploaded files to back up.');
        }
        if ($encrypt && !ss_encryption_available()) {
            throw new RuntimeException('Encrypted backups need APP_ENCRYPTION_KEY. Set it in the server environment or turn off encryption.');
        }
        $dir = ss_backup_dir($settings, true);
        $lock = fopen($dir . DIRECTORY_SEPARATOR . '.backup.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another backup is already running.');
        }

        $stamp = date('Ymd-His');
        $parts = [];
        if ($include_db) {
            $parts['db.sql.gz'] = static function (string $path) use ($conn): string {
                return ss_dump_database($conn, $path) . ' tables';
            };
        }
        if ($include_uploads) {
            $parts['uploads.zip'] = static function (string $path): string {
                return ss_zip_uploads($path) . ' files';
            };
        }

        $summary = [];
        foreach ($parts as $suffix => $builder) {
            $plain = $dir . DIRECTORY_SEPARATOR . "backup-{$stamp}-{$suffix}";
            $summary[] = $builder($plain);
            @chmod($plain, 0600);
            if ($encrypt) {
                site_backup_transform($plain, $plain . '.edenc');
                @unlink($plain);
                $plain .= '.edenc';
            }
            $entry['files'][] = basename($plain);
        }

        if ($remote) {
            $cfg = ss_backup_remote_config($settings);
            $prefix = trim((string)($settings['backup_remote_prefix'] ?? 'backups'), '/');
            foreach ($entry['files'] as $name) {
                $key = ($prefix !== '' ? $prefix . '/' : '') . $name;
                $res = ss_s3_request($cfg, 'PUT', $key, $dir . DIRECTORY_SEPARATOR . $name);
                if ($res['status'] < 200 || $res['status'] >= 300) {
                    throw new RuntimeException('Local backup saved, but remote upload of ' . $name . ' failed: ' . ss_s3_error_message($res));
                }
            }
            $entry['remote'] = 's3://' . $cfg['bucket'] . '/' . $prefix;
        }

        $removed = ss_apply_retention($settings);
        $entry['ok'] = true;
        $entry['message'] = 'Backup completed (' . implode(', ', $summary) . ')'
            . ($remote ? ', copied to remote storage' : '')
            . ($removed ? ", {$removed} old file(s) removed" : '') . '.';
    } catch (Throwable $e) {
        $entry['message'] = $e->getMessage();
    } finally {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    try {
        ss_backup_log($conn, $settings, $entry);
    } catch (Throwable $e) {
        error_log('Backup log write failed: ' . $e->getMessage());
    }
    return $entry;
}

function ss_apply_retention(array $settings): int
{
    $keep = max(1, (int)($settings['backup_retention_count'] ?? 7));
    $by_type = [];
    foreach (ss_backup_list($settings) as $file) {
        $by_type[$file['type']][] = $file;
    }
    $removed = 0;
    $dir = ss_backup_dir($settings, false);
    foreach ($by_type as $files) {
        foreach (array_slice($files, $keep) as $old) {
            if (@unlink($dir . DIRECTORY_SEPARATOR . $old['name'])) {
                $removed++;
            }
        }
    }
    return $removed;
}

/** Whether a scheduled backup is due now (used by the cron script). */
function ss_backup_due(array $settings): bool
{
    $schedule = (string)($settings['backup_schedule'] ?? 'off');
    $interval = ['daily' => 86400, 'weekly' => 604800, 'monthly' => 2592000][$schedule] ?? 0;
    if ($interval === 0) {
        return false;
    }
    $last = strtotime((string)($settings['backup_last_run_at'] ?? '')) ?: 0;
    // Small grace so a daily cron at a fixed time does not drift past its slot.
    return time() - $last >= $interval - 600;
}

function ss_format_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $value = (float)$bytes;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return round($value, $i ? 1 : 0) . ' ' . $units[$i];
}

/* --------------------------------------------------------------------------
   UAT, security and go-live checklists
   -------------------------------------------------------------------------- */

function ss_uat_modules(): array
{
    return [
        '1.1 Public website',
        '1.2 Online application portal',
        '1.3 Admin / back office',
        '1.4 Reviewer / scoring',
        '1.5 Venture profiles & documents',
        '1.6 Mentor engagement',
        '1.7 Venture directory',
        '1.8 Investor directory',
        '1.9 Events',
        '1.10 Content library',
        'Integrations',
        'Security',
    ];
}

function ss_uat_statuses(): array
{
    return [
        'not_tested' => ['Not tested', 'badge-gray', 'fa-circle'],
        'passed'     => ['Passed', 'badge-success', 'fa-check-circle'],
        'failed'     => ['Failed', 'badge-danger', 'fa-times-circle'],
        'blocked'    => ['Blocked', 'badge-warning', 'fa-ban'],
        'retest'     => ['Needs retest', 'badge-info', 'fa-redo'],
    ];
}

function ss_security_checklist_items(): array
{
    return [
        'vuln_scan'      => 'Automated vulnerability scan completed (e.g. OWASP ZAP) and high findings fixed',
        'pen_test'       => 'Penetration test / security review completed and signed off',
        'rbac_test'      => 'Role permissions tested on direct URLs and form handlers',
        'upload_test'    => 'File upload type, size and private-file access controls tested',
        'dependency'     => 'Dependencies audited (composer audit) with no known critical issues',
        'ssl_grade'      => 'TLS certificate valid and SSL Labs grade A or better',
        'secrets'        => 'Production secrets held in environment variables, not in the repository',
        'restore_test'   => 'Backup restore tested on a non-production database',
        'admin_accounts' => 'Default/test admin accounts removed and admin OTP login verified',
    ];
}

function ss_golive_checklist_items(): array
{
    return [
        'infra'        => 'Production AWS environment provisioned (compute, database, storage)',
        's3_policy'    => 'S3 bucket: Block Public Access on, versioning on, default encryption on',
        'iam'          => 'IAM user/role scoped to the document and backup buckets only',
        'db_migrated'  => 'Production database migrated and verified against staging',
        'env'          => 'Production .env / environment variables set (DB, APP_ENCRYPTION_KEY, AWS, SMTP)',
        'dns'          => 'DNS cut over to production and propagation confirmed',
        'ssl'          => 'SSL certificate installed and HTTPS redirect active',
        'cron'         => 'Cron jobs scheduled (session reminders, event reminders, backups)',
        'monitoring'   => 'Monitoring and alerts configured (uptime, CloudWatch, error logs)',
        'smoke'        => 'Post-deployment smoke test passed',
        'rollback'     => 'Rollback plan documented and tested',
        'approval'     => 'Client go-live approval received',
        'hypercare'    => 'Hypercare / stabilisation period agreed and support contacts shared',
    ];
}

function ss_checklist_state(array $settings, string $key): array
{
    $state = json_decode((string)($settings[$key] ?? '{}'), true);
    return is_array($state) ? $state : [];
}

/** @return array<int,array{label:string,status:string,detail:string}> status: ok|warn|fail */
function ss_security_checks(array $settings): array
{
    $checks = [];
    $add = static function (string $label, string $status, string $detail) use (&$checks): void {
        $checks[] = ['label' => $label, 'status' => $status, 'detail' => $detail];
    };

    $site_url = defined('SITE_URL') ? (string)SITE_URL : '';
    $add('Site URL uses HTTPS', str_starts_with($site_url, 'https://') ? 'ok' : 'fail', $site_url ?: 'SITE_URL not set');
    $add('Encryption key configured', ss_encryption_available() ? 'ok' : 'fail', ss_encryption_available() ? 'APP_ENCRYPTION_KEY is valid' : 'APP_ENCRYPTION_KEY missing or invalid');

    $db_host = defined('DB_HOST') ? (string)DB_HOST : '';
    $local_db = in_array($db_host, ['localhost', '127.0.0.1', '::1'], true);
    $add('Database connection encrypted', (getenv('DB_SSL_CA') ?: '') !== '' ? 'ok' : ($local_db ? 'ok' : 'fail'), $local_db ? 'Local database socket' : ((getenv('DB_SSL_CA') ?: '') !== '' ? 'TLS with CA verification' : 'Remote DB without DB_SSL_CA'));

    $display = strtolower((string)ini_get('display_errors'));
    $add('PHP errors hidden from visitors', in_array($display, ['', '0', 'off', 'stderr'], true) ? 'ok' : 'warn', 'display_errors = ' . ($display === '' ? 'off' : $display));
    $add('PHP version supported', version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'warn', 'PHP ' . PHP_VERSION);

    $httponly = ini_get('session.cookie_httponly');
    $add('Session cookies HttpOnly', in_array(strtolower((string)$httponly), ['1', 'on', 'true'], true) ? 'ok' : 'warn', 'session.cookie_httponly = ' . ($httponly ?: 'off'));
    $secure = ini_get('session.cookie_secure');
    $add('Session cookies Secure', in_array(strtolower((string)$secure), ['1', 'on', 'true'], true) ? 'ok' : 'warn', 'session.cookie_secure = ' . ($secure ?: 'off'));

    $root = ss_public_root();
    $add('Uploads folder protected', is_file($root . '/uploads/.htaccess') ? 'ok' : 'warn', is_file($root . '/uploads/.htaccess') ? 'uploads/.htaccess present' : 'uploads/.htaccess missing');
    $env_in_root = is_file($root . '/.env');
    $add('Environment file outside web root', $env_in_root ? 'warn' : 'ok', $env_in_root ? '.env is inside the web root; confirm the web server blocks it or move it and set APP_ENV_FILE' : 'No .env in the web root');

    $timeout = (int)($settings['security_admin_idle_minutes'] ?? 0);
    $add('Admin idle timeout', $timeout > 0 ? 'ok' : 'warn', $timeout > 0 ? $timeout . ' minutes' : 'Disabled');

    return $checks;
}

/** @return array<int,array{label:string,status:string,detail:string}> */
function ss_golive_checks(mysqli $conn, array $settings): array
{
    $checks = [];
    $add = static function (string $label, string $status, string $detail) use (&$checks): void {
        $checks[] = ['label' => $label, 'status' => $status, 'detail' => $detail];
    };

    $env = (string)($settings['app_environment'] ?? 'development');
    $add('Environment set to production', $env === 'production' ? 'ok' : 'warn', ucfirst($env));

    $aws = ss_aws_config($settings);
    $aws_ready = $aws['bucket'] !== '' && $aws['region'] !== '' && $aws['access_key'] !== '' && $aws['secret_key'] !== '';
    $add('AWS S3 credentials configured', $aws_ready ? 'ok' : 'fail', $aws_ready ? $aws['bucket'] . ' (' . $aws['region'] . ')' : 'Bucket, region or keys missing');
    $add('S3 document storage enabled', ($settings['aws_s3_documents_enabled'] ?? '0') === '1' ? 'ok' : 'warn', ($settings['aws_s3_documents_enabled'] ?? '0') === '1' ? 'Venture documents upload to S3' : 'Documents are stored on the web server');
    $s3_test = (string)($settings['aws_s3_last_test'] ?? '');
    $add('S3 connection test', str_starts_with($s3_test, 'ok') ? 'ok' : ($s3_test === '' ? 'warn' : 'fail'), $s3_test === '' ? 'Not tested yet' : $s3_test);

    $smtp = false;
    $res = @$conn->query("SELECT smtp_host FROM email_settings WHERE status = 1 LIMIT 1");
    if ($res && ($row = $res->fetch_assoc())) {
        $smtp = trim((string)$row['smtp_host']) !== '';
    }
    $add('Outgoing email (SMTP) configured', $smtp ? 'ok' : 'fail', $smtp ? 'Active SMTP profile found' : 'No active SMTP profile');

    $schedule = (string)($settings['backup_schedule'] ?? 'off');
    $add('Scheduled backups', $schedule !== 'off' ? 'ok' : 'fail', $schedule !== 'off' ? ucfirst($schedule) : 'Not scheduled');
    $add('Off-site (remote) backups', ($settings['backup_remote_enabled'] ?? '0') === '1' ? 'ok' : 'warn', ($settings['backup_remote_enabled'] ?? '0') === '1' ? 'Copied to S3' : 'Local copies only');
    $last_ok = strtotime((string)($settings['backup_last_success_at'] ?? '')) ?: 0;
    $fresh = $last_ok > 0 && time() - $last_ok < 26 * 3600;
    $add('Recent successful backup', $fresh ? 'ok' : 'warn', $last_ok ? 'Last success ' . date('j M Y, g:i a', $last_ok) : 'No successful backup yet');

    $gcal = (getenv('GOOGLE_CALENDAR_CLIENT_ID') ?: '') !== '' && (getenv('GOOGLE_CALENDAR_CLIENT_SECRET') ?: '') !== '';
    $add('Google Calendar credentials', $gcal ? 'ok' : 'warn', $gcal ? 'Client ID and secret present' : 'GOOGLE_CALENDAR_CLIENT_ID / SECRET not set');

    $failed = 0;
    $res = @$conn->query("SELECT SUM(status='failed') AS failed, SUM(status='not_tested') AS pending, COUNT(*) AS total FROM uat_test_cases");
    $uat = $res ? $res->fetch_assoc() : null;
    if ($uat && (int)$uat['total'] > 0) {
        $failed = (int)$uat['failed'];
        $pending = (int)$uat['pending'];
        $add('UAT test cases', $failed === 0 && $pending === 0 ? 'ok' : ($failed > 0 ? 'fail' : 'warn'), "{$failed} failed, {$pending} not tested of " . (int)$uat['total']);
    } else {
        $add('UAT test cases', 'warn', 'No UAT test cases recorded');
    }
    $add('UAT sign-off', ($settings['uat_signoff_name'] ?? '') !== '' ? 'ok' : 'fail', ($settings['uat_signoff_name'] ?? '') !== '' ? 'Signed by ' . $settings['uat_signoff_name'] . ' on ' . ($settings['uat_signoff_date'] ?? '') : 'Not signed off');

    return $checks;
}

function ss_check_summary(array $checks): array
{
    $sum = ['ok' => 0, 'warn' => 0, 'fail' => 0];
    foreach ($checks as $c) {
        $sum[$c['status']] = ($sum[$c['status']] ?? 0) + 1;
    }
    return $sum;
}
