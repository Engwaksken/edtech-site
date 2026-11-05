<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../includes/site-settings-service.php';
require_once __DIR__ . '/../../includes/legal-pages.php';
require_once __DIR__ . '/../../includes/rich-html.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

/* --------------------------------------------------------------------------
   Access: super admins, or roles granted the Site Settings page.
   -------------------------------------------------------------------------- */

(static function (mysqli $conn): void {
    if (admin_is_super()) {
        return;
    }
    $page_key = '';
    $res = @$conn->query("SELECT page_key FROM admin_pages WHERE page_url LIKE '%site-settings%' LIMIT 1");
    if ($res && ($row = $res->fetch_assoc())) {
        $page_key = (string)$row['page_key'];
    }
    if ($page_key === '' || !admin_has_permission($conn, $page_key)) {
        http_response_code(403);
        exit('<h2 style="font-family:Arial;margin:40px">Access denied</h2><p style="font-family:Arial;margin:40px">Only administrators can manage site settings.</p>');
    }
})($conn);

ss_ensure_tables($conn);

const SS_TABS = ['general', 'theme', 'fonts', 'popup', 'legal', 'backups', 'integrations', 'security', 'golive'];

$active_tab = (string)($_GET['tab'] ?? $_POST['settings_active_tab'] ?? 'general');
if (!in_array($active_tab, SS_TABS, true)) {
    $active_tab = 'general';
}

$success = (string)($_SESSION['ss_flash_success'] ?? '');
$error = (string)($_SESSION['ss_flash_error'] ?? '');
unset($_SESSION['ss_flash_success'], $_SESSION['ss_flash_error']);

function ss_redirect(string $tab, string $message, bool $ok = true): void
{
    $_SESSION[$ok ? 'ss_flash_success' : 'ss_flash_error'] = $message;
    header('Location: site-settings.php?tab=' . rawurlencode($tab));
    exit;
}

function ss_upload_file(string $field, array $allowed_exts, string $folder = 'uploads/settings'): ?string
{
    if (empty($_FILES[$field]['name'])) {
        return null;
    }
    if (($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Failed to upload ' . str_replace('_', ' ', $field) . '.');
    }
    $ext = strtolower(pathinfo((string)$_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) {
        throw new RuntimeException('Invalid file type for ' . str_replace('_', ' ', $field) . '.');
    }
    if ($ext !== 'ico' && @getimagesize((string)$_FILES[$field]['tmp_name']) === false) {
        throw new RuntimeException('The ' . str_replace('_', ' ', $field) . ' is not a valid image.');
    }
    if ((int)$_FILES[$field]['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('The ' . str_replace('_', ' ', $field) . ' must be 5 MB or smaller.');
    }
    $upload_dir = dirname(__DIR__, 2) . '/' . $folder;
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0775, true);
    }
    $filename = $field . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file((string)$_FILES[$field]['tmp_name'], $upload_dir . '/' . $filename)) {
        throw new RuntimeException('Could not save ' . str_replace('_', ' ', $field) . '.');
    }
    return $folder . '/' . $filename;
}

function ss_post(string $key, int $max = 2000): string
{
    return mb_substr(trim((string)($_POST[$key] ?? '')), 0, $max);
}

function ss_post_flag(string $key): string
{
    return isset($_POST[$key]) ? '1' : '0';
}

function ss_post_int(string $key, int $min, int $max, string $label): int
{
    $value = filter_var($_POST[$key] ?? '', FILTER_VALIDATE_INT);
    if ($value === false || $value < $min || $value > $max) {
        throw new RuntimeException("{$label} must be between {$min} and {$max}.");
    }
    return $value;
}

function ss_post_date(string $key, string $label): string
{
    $value = trim((string)($_POST[$key] ?? ''));
    if ($value === '') {
        return '';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new RuntimeException("{$label} must be a valid date.");
    }
    return $value;
}

function ss_email_settings_columns(mysqli $conn): array
{
    $cols = [];
    $res = @$conn->query('SHOW COLUMNS FROM email_settings');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cols[] = (string)$row['Field'];
        }
    }
    return $cols;
}

function ss_email_settings_row(mysqli $conn): ?array
{
    $res = @$conn->query('SELECT * FROM email_settings ORDER BY status DESC, id ASC LIMIT 1');
    return $res ? ($res->fetch_assoc() ?: null) : null;
}

function ss_save_checklist(mysqli $conn, array $settings, string $setting_key, array $items): void
{
    $state = ss_checklist_state($settings, $setting_key);
    $posted = (array)($_POST['checklist'] ?? []);
    $out = [];
    foreach ($items as $key => $label) {
        $done = isset($posted[$key]['done']);
        $previous = $state[$key] ?? [];
        $out[$key] = [
            'done' => $done,
            'date' => $done ? (string)(($posted[$key]['date'] ?? '') !== '' ? ss_validate_date_value((string)$posted[$key]['date'], $label) : ($previous['date'] ?? date('Y-m-d'))) : '',
            'by'   => $done ? (string)(($previous['done'] ?? false) ? ($previous['by'] ?? '') : ($_SESSION['admin_name'] ?? '')) : '',
            'note' => mb_substr(trim((string)($posted[$key]['note'] ?? '')), 0, 500),
        ];
    }
    ss_save($conn, $setting_key, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function ss_validate_date_value(string $value, string $label): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new RuntimeException('Invalid date for: ' . $label);
    }
    return $value;
}

$settings = ss_get_all($conn);

/* --------------------------------------------------------------------------
   Backup download (GET)
   -------------------------------------------------------------------------- */

if (isset($_GET['download_backup'])) {
    try {
        $path = ss_backup_file_path($settings, (string)$_GET['download_backup']);
    } catch (Throwable $e) {
        ss_redirect('backups', $e->getMessage(), false);
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

/* --------------------------------------------------------------------------
   POST actions
   -------------------------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['ss_action'] ?? '');
    $tab = $active_tab;

    try {
        switch ($action) {
            case 'save_general': {
                $email = ss_post('site_email', 190);
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Enter a valid site email address.');
                }
                $logo = ss_upload_file('site_logo', ['jpg', 'jpeg', 'png', 'webp', 'gif']);
                $favicon = ss_upload_file('site_favicon', ['ico', 'jpg', 'jpeg', 'png', 'webp', 'gif']);
                ss_save($conn, 'site_name', ss_post('site_name', 190));
                ss_save($conn, 'site_email', $email);
                ss_save($conn, 'site_phone', ss_post('site_phone', 60));
                ss_save($conn, 'site_address', ss_post('site_address', 255));
                if ($logo !== null) ss_save($conn, 'site_logo', $logo);
                if ($favicon !== null) ss_save($conn, 'site_favicon', $favicon);
                ss_redirect($tab, 'General settings saved.');
            }

            case 'save_theme': {
                $defaults = ss_theme_defaults();
                if (isset($_POST['reset_theme'])) {
                    foreach (['theme_primary', 'theme_primary_dark', 'theme_accent', 'theme_secondary', 'theme_text', 'theme_sidebar_bg'] as $key) {
                        ss_save($conn, $key, $defaults[$key]);
                    }
                    ss_redirect($tab, 'Theme colours reset to the defaults.');
                }
                foreach (['theme_primary', 'theme_primary_dark', 'theme_accent', 'theme_secondary', 'theme_text', 'theme_sidebar_bg'] as $key) {
                    $value = strtolower(ss_post($key, 7));
                    if (!ss_valid_hex($value)) {
                        throw new RuntimeException('Colours must be 6-digit hex values such as #fc7f10.');
                    }
                    ss_save($conn, $key, $value);
                }
                ss_save($conn, 'theme_apply_frontend', ss_post_flag('theme_apply_frontend'));
                ss_save($conn, 'theme_apply_admin', ss_post_flag('theme_apply_admin'));
                ss_redirect($tab, 'Theme colours saved. Reload any open page to see them.');
            }

            case 'save_fonts': {
                $defaults = ss_theme_defaults();
                if (isset($_POST['reset_fonts'])) {
                    foreach (['font_body', 'font_heading', 'font_base_size', 'font_heading_weight'] as $key) {
                        ss_save($conn, $key, $defaults[$key]);
                    }
                    ss_redirect($tab, 'Typography reset to the defaults.');
                }
                $fonts = ss_font_choices();
                foreach (['font_body' => 'Body font', 'font_heading' => 'Heading font'] as $key => $label) {
                    $value = ss_post($key, 60);
                    if (!isset($fonts[$value])) {
                        throw new RuntimeException("Choose a {$label} from the list.");
                    }
                    ss_save($conn, $key, $value);
                }
                $weight = ss_post('font_heading_weight', 3);
                if (!in_array($weight, ['400', '500', '600', '700', '800'], true)) {
                    throw new RuntimeException('Choose a valid heading weight.');
                }
                ss_save($conn, 'font_heading_weight', $weight);
                ss_save($conn, 'font_base_size', (string)ss_post_int('font_base_size', 12, 20, 'Base font size'));
                ss_redirect($tab, 'Typography saved.');
            }

            case 'save_popup': {
                $popup_url = ss_post('frontend_popup_button_url', 500);
                if ($popup_url !== '') {
                    $scheme = strtolower((string)parse_url($popup_url, PHP_URL_SCHEME));
                    $is_local_path = str_starts_with($popup_url, '/') && !str_starts_with($popup_url, '//');
                    if (!$is_local_path && (!filter_var($popup_url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true))) {
                        throw new RuntimeException('The popup button link must be a valid website URL or local path.');
                    }
                }
                $normalize = static function (string $value): string {
                    if ($value === '') return '';
                    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
                    $errors = DateTimeImmutable::getLastErrors();
                    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                        throw new RuntimeException('Popup start and stop times must be valid dates and times.');
                    }
                    return $date->format('Y-m-d H:i:s');
                };
                $start = $normalize(ss_post('frontend_popup_start', 20));
                $stop = $normalize(ss_post('frontend_popup_stop', 20));
                if ($start !== '' && $stop !== '' && strtotime($stop) <= strtotime($start)) {
                    throw new RuntimeException('Popup stop time must be later than its start time.');
                }
                $priority = ss_post_int('frontend_popup_priority', 0, 1000, 'Popup priority');
                $delay = ss_post_int('frontend_popup_delay', 0, 3600, 'Popup delay');
                $frequency = ss_post('frontend_popup_frequency', 20);
                if (!in_array($frequency, ['every_page', 'session', 'daily', 'once'], true)) {
                    throw new RuntimeException('Select a valid popup display frequency.');
                }
                $scope = ss_post('frontend_popup_scope', 20);
                if (!in_array($scope, ['all', 'selected'], true)) {
                    throw new RuntimeException('Select whether the popup appears on all or selected pages.');
                }
                $image = ss_upload_file('frontend_popup_image', ['jpg', 'jpeg', 'png', 'webp', 'gif']);

                ss_save($conn, 'frontend_popup_title', ss_post('frontend_popup_title', 160));
                ss_save($conn, 'frontend_popup_message', ss_post('frontend_popup_message', 2000));
                ss_save($conn, 'frontend_popup_button_text', ss_post('frontend_popup_button_text', 60));
                ss_save($conn, 'frontend_popup_button_url', $popup_url);
                ss_save($conn, 'frontend_popup_enabled', ss_post_flag('frontend_popup_enabled'));
                ss_save($conn, 'frontend_popup_start', $start);
                ss_save($conn, 'frontend_popup_stop', $stop);
                ss_save($conn, 'frontend_popup_priority', (string)$priority);
                ss_save($conn, 'frontend_popup_frequency', $frequency);
                ss_save($conn, 'frontend_popup_delay', (string)$delay);
                ss_save($conn, 'frontend_popup_scope', $scope);
                ss_save($conn, 'frontend_popup_pages', ss_post('frontend_popup_pages', 4000));
                ss_save($conn, 'frontend_popup_new_tab', ss_post_flag('frontend_popup_new_tab'));
                if ($image !== null) {
                    ss_save($conn, 'frontend_popup_image', $image);
                } elseif (isset($_POST['frontend_popup_remove_image'])) {
                    ss_save($conn, 'frontend_popup_image', '');
                }
                ss_redirect($tab, 'Frontend popup saved.');
            }

            case 'save_backup_settings': {
                $path = ss_post('backup_local_path', 500);
                if ($path !== '') {
                    if (ss_path_inside_public_root(is_dir($path) ? (string)realpath($path) : $path)) {
                        throw new RuntimeException('The backup folder must be outside the public web root.');
                    }
                }
                $schedule = ss_post('backup_schedule', 10);
                if (!in_array($schedule, ['off', 'daily', 'weekly', 'monthly'], true)) {
                    throw new RuntimeException('Choose a valid backup schedule.');
                }
                $bucket = ss_post('backup_remote_bucket', 63);
                if ($bucket !== '' && !preg_match('/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/', $bucket)) {
                    throw new RuntimeException('Enter a valid S3 bucket name (lowercase letters, numbers, dots and hyphens).');
                }
                $region = ss_post('backup_remote_region', 30);
                if ($region !== '' && !preg_match('/^[a-z]{2}(-[a-z]+)+-\d$/', $region)) {
                    throw new RuntimeException('Enter a valid AWS region such as eu-west-1.');
                }
                $endpoint = ss_post('backup_remote_endpoint', 255);
                if ($endpoint !== '' && (!filter_var($endpoint, FILTER_VALIDATE_URL) || !str_starts_with($endpoint, 'https://'))) {
                    throw new RuntimeException('The custom endpoint must be an https:// URL.');
                }
                $remote_enabled = ss_post_flag('backup_remote_enabled');
                if ($remote_enabled === '1' && $bucket === '') {
                    throw new RuntimeException('Enter the remote bucket name before enabling remote backups.');
                }
                $use_aws = ss_post_flag('backup_remote_use_aws_creds');
                $new_secret = (string)($_POST['backup_remote_secret_key'] ?? '');
                $new_access = ss_post('backup_remote_access_key', 128);
                if ($use_aws === '0' && ($new_secret !== '' || $new_access !== '') && !ss_encryption_available()) {
                    throw new RuntimeException('Saving remote credentials needs APP_ENCRYPTION_KEY so they can be encrypted.');
                }

                ss_save($conn, 'backup_local_path', $path);
                ss_save($conn, 'backup_schedule', $schedule);
                ss_save($conn, 'backup_retention_count', (string)ss_post_int('backup_retention_count', 1, 365, 'Local copies to keep'));
                ss_save($conn, 'backup_include_uploads', ss_post_flag('backup_include_uploads'));
                ss_save($conn, 'backup_encrypt', ss_post_flag('backup_encrypt'));
                ss_save($conn, 'backup_remote_enabled', $remote_enabled);
                ss_save($conn, 'backup_remote_bucket', $bucket);
                ss_save($conn, 'backup_remote_region', $region);
                ss_save($conn, 'backup_remote_prefix', trim(ss_post('backup_remote_prefix', 120), '/'));
                ss_save($conn, 'backup_remote_endpoint', $endpoint);
                ss_save($conn, 'backup_remote_use_aws_creds', $use_aws);
                if ($use_aws === '0') {
                    ss_save_secret($conn, 'backup_remote_access_key', $new_access);
                    ss_save_secret($conn, 'backup_remote_secret_key', $new_secret);
                }
                // Validate the folder now so a bad path is reported here, not at 2am in cron.
                ss_backup_dir(ss_get_all($conn), true);
                ss_redirect($tab, 'Backup settings saved.');
            }

            case 'run_backup': {
                $result = ss_run_backup($conn, $settings, [
                    'db' => isset($_POST['include_db']),
                    'uploads' => isset($_POST['include_uploads']),
                    'remote' => isset($_POST['push_remote']),
                    'trigger' => 'manual by ' . ($_SESSION['admin_name'] ?? 'admin'),
                ]);
                ss_redirect($tab, $result['message'], (bool)$result['ok']);
            }

            case 'delete_backup': {
                $path = ss_backup_file_path($settings, (string)($_POST['backup_name'] ?? ''));
                if (!@unlink($path)) {
                    throw new RuntimeException('Could not delete the backup file.');
                }
                ss_redirect($tab, 'Backup ' . basename($path) . ' deleted.');
            }

            case 'upload_backup_remote': {
                $path = ss_backup_file_path($settings, (string)($_POST['backup_name'] ?? ''));
                $prefix = trim((string)($settings['backup_remote_prefix'] ?? 'backups'), '/');
                $res = ss_s3_request(ss_backup_remote_config($settings), 'PUT', ($prefix !== '' ? $prefix . '/' : '') . basename($path), $path);
                if ($res['status'] < 200 || $res['status'] >= 300) {
                    throw new RuntimeException('Remote upload failed: ' . ss_s3_error_message($res));
                }
                ss_redirect($tab, basename($path) . ' copied to remote storage.');
            }

            case 'test_backup_remote': {
                ss_s3_test(ss_backup_remote_config($settings), (string)($settings['backup_remote_prefix'] ?? ''));
                ss_redirect($tab, 'Remote backup storage is reachable and writable.');
            }

            case 'save_integrations': {
                $ga = strtoupper(ss_post('analytics_ga_id', 30));
                if ($ga !== '' && !preg_match('/^G-[A-Z0-9]{4,20}$/', $ga)) {
                    throw new RuntimeException('The Google Analytics ID should look like G-XXXXXXXXXX (leave blank to turn analytics off).');
                }
                $ims = ss_post('integration_ims_url', 500);
                if ($ims !== '' && (!filter_var($ims, FILTER_VALIDATE_URL) || !str_starts_with($ims, 'https://'))) {
                    throw new RuntimeException('The application portal URL must be an https:// link.');
                }
                ss_save($conn, 'analytics_ga_id', $ga);
                ss_save($conn, 'integration_ims_url', $ims);
                ss_save($conn, 'integration_ims_contact', ss_post('integration_ims_contact', 255));

                $calendarId = ss_post('google_calendar_client_id', 255);
                if ($calendarId !== '' && !preg_match('/^[0-9]+-[A-Za-z0-9_-]+\.apps\.googleusercontent\.com$/', $calendarId)) {
                    throw new RuntimeException('Enter a valid Google OAuth client ID.');
                }
                ss_save($conn, 'google_calendar_client_id', $calendarId);
                ss_save_secret($conn, 'google_calendar_client_secret', (string)($_POST['google_calendar_client_secret'] ?? ''));

                $cols = ss_email_settings_columns($conn);
                if ($cols && isset($_POST['smtp_host'])) {
                    $from = ss_post('from_email', 190);
                    if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
                        throw new RuntimeException('Enter a valid sender email address.');
                    }
                    $secure = ss_post('smtp_secure', 10);
                    if (!in_array($secure, ['tls', 'ssl', ''], true)) {
                        throw new RuntimeException('Choose TLS, SSL or none for SMTP encryption.');
                    }
                    $values = [
                        'smtp_host' => ss_post('smtp_host', 190),
                        'smtp_port' => (string)ss_post_int('smtp_port', 1, 65535, 'SMTP port'),
                        'smtp_user' => ss_post('smtp_user', 190),
                        'smtp_secure' => $secure,
                        'from_email' => $from,
                        'from_name' => ss_post('from_name', 190),
                    ];
                    $values = array_intersect_key($values, array_flip($cols));
                    $row = ss_email_settings_row($conn);
                    if ($row) {
                        $id = (int)$row['id'];
                    } else {
                        $conn->query('INSERT INTO email_settings (' . (in_array('status', $cols, true) ? 'status' : 'smtp_host') . ') VALUES (' . (in_array('status', $cols, true) ? '1' : "''") . ')');
                        $id = (int)$conn->insert_id;
                    }
                    if (in_array('status', $cols, true)) {
                        $values['status'] = '1';
                    }
                    $password = (string)($_POST['smtp_password'] ?? '');
                    if ($password !== '' && in_array('smtp_password', $cols, true)) {
                        $values['smtp_password'] = site_encrypt_secret($password, 'smtp:password:' . $id);
                    }
                    $set = implode(', ', array_map(static fn($c) => "`{$c}` = ?", array_keys($values)));
                    $stmt = $conn->prepare("UPDATE email_settings SET {$set} WHERE id = ?");
                    $params = array_values($values);
                    $params[] = $id;
                    $stmt->bind_param(str_repeat('s', count($values)) . 'i', ...$params);
                    $stmt->execute();
                    $stmt->close();
                }
                ss_redirect($tab, 'Integration settings saved.');
            }

            case 'test_email': {
                require_once __DIR__ . '/../../includes/mail-function.php';
                $to = (string)($_SESSION['admin_email'] ?? '');
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Your admin account has no valid email address to send the test to.');
                }
                $result = sendEmail($to, 'Test email from Site Settings', '<p>This is a test message from the Site Settings integration panel. If you are reading it, outgoing email works.</p>');
                if ($result !== true) {
                    throw new RuntimeException('Test email failed: ' . (is_string($result) ? $result : 'unknown error'));
                }
                ss_save($conn, 'smtp_last_test', 'ok ' . date('Y-m-d H:i'));
                ss_redirect($tab, 'Test email sent to ' . $to . '.');
            }

            case 'save_security': {
                ss_save($conn, 'security_admin_idle_minutes', (string)ss_post_int('security_admin_idle_minutes', 0, 1440, 'Admin idle timeout'));
                ss_save_checklist($conn, $settings, 'security_checklist', ss_security_checklist_items());
                ss_redirect($tab, 'Security settings saved.');
            }

            case 'uat_add': {
                $module = ss_post('module', 120);
                $title = ss_post('title', 255);
                if (!in_array($module, ss_uat_modules(), true) || $title === '') {
                    throw new RuntimeException('Choose a module and describe the test case.');
                }
                $stmt = $conn->prepare("INSERT INTO uat_test_cases (module, title, status) VALUES (?, ?, 'not_tested')");
                $stmt->bind_param('ss', $module, $title);
                $stmt->execute();
                $stmt->close();
                ss_redirect($tab, 'Test case added.');
            }

            case 'uat_seed': {
                $count = (int)($conn->query('SELECT COUNT(*) FROM uat_test_cases')->fetch_row()[0] ?? 0);
                if ($count > 0) {
                    throw new RuntimeException('Test cases already exist.');
                }
                $stmt = $conn->prepare("INSERT INTO uat_test_cases (module, title, status) VALUES (?, ?, 'not_tested')");
                foreach (ss_uat_modules() as $module) {
                    $title = 'End-to-end acceptance of ' . preg_replace('/^[\d.]+\s*/', '', $module);
                    $stmt->bind_param('ss', $module, $title);
                    $stmt->execute();
                }
                $stmt->close();
                ss_redirect($tab, 'One starter test case was added per module. Edit or add detailed cases as needed.');
            }

            case 'uat_update': {
                $id = (int)($_POST['id'] ?? 0);
                $status = ss_post('status', 20);
                if (!isset(ss_uat_statuses()[$status])) {
                    throw new RuntimeException('Choose a valid test status.');
                }
                $tester = ss_post('tester', 150);
                if ($tester === '' && $status !== 'not_tested') {
                    $tester = (string)($_SESSION['admin_name'] ?? '');
                }
                $tested_at = ss_post_date('tested_at', 'Test date');
                if ($tested_at === '' && $status !== 'not_tested') {
                    $tested_at = date('Y-m-d');
                }
                $tested_at_param = $tested_at === '' ? null : $tested_at;
                $notes = ss_post('notes', 2000);
                $stmt = $conn->prepare('UPDATE uat_test_cases SET status = ?, tester = ?, tested_at = ?, notes = ? WHERE id = ?');
                $stmt->bind_param('ssssi', $status, $tester, $tested_at_param, $notes, $id);
                $stmt->execute();
                $stmt->close();
                ss_redirect($tab, 'Test case updated.');
            }

            case 'uat_delete': {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $conn->prepare('DELETE FROM uat_test_cases WHERE id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                ss_redirect($tab, 'Test case deleted.');
            }

            case 'uat_signoff': {
                $name = ss_post('uat_signoff_name', 150);
                $role = ss_post('uat_signoff_role', 150);
                $date = ss_post_date('uat_signoff_date', 'Sign-off date');
                if ($name === '' || $date === '') {
                    throw new RuntimeException('Enter the name and date of the person signing off UAT.');
                }
                ss_save($conn, 'uat_signoff_name', $name);
                ss_save($conn, 'uat_signoff_role', $role);
                ss_save($conn, 'uat_signoff_date', $date);
                ss_save($conn, 'uat_signoff_notes', ss_post('uat_signoff_notes', 2000));
                ss_save($conn, 'uat_signoff_recorded_by', (string)($_SESSION['admin_name'] ?? ''));
                ss_redirect($tab, 'UAT sign-off recorded.');
            }

            case 'uat_signoff_clear': {
                foreach (['uat_signoff_name', 'uat_signoff_role', 'uat_signoff_date', 'uat_signoff_notes', 'uat_signoff_recorded_by'] as $key) {
                    ss_save($conn, $key, '');
                }
                ss_redirect($tab, 'UAT sign-off cleared.');
            }

            case 'save_legal': {
                $changed = [];
                foreach (LEGAL_PAGES as $key => $meta) {
                    $field = 'legal_' . $key . '_html';
                    if (!isset($_POST[$field])) continue;
                    $html = trim((string)$_POST[$field]);
                    if (strlen($html) > 60000) {
                        throw new RuntimeException($meta['title'] . ' is too long (limit about 60,000 characters).');
                    }
                    // Store what the public page will show, so the editor reflects it.
                    $clean = site_rich_html($html);
                    if ($clean !== get_setting($conn, $meta['setting'], '')) {
                        ss_save($conn, $meta['setting'], $clean);
                        ss_save($conn, $meta['updated'], date('Y-m-d'));
                        $changed[] = $meta['title'];
                    }
                }
                ss_redirect($tab, $changed ? implode(' and ', $changed) . ' saved.' : 'No changes to save.');
            }

            case 'save_golive': {
                $env = ss_post('app_environment', 20);
                if (!in_array($env, ['development', 'staging', 'production'], true)) {
                    throw new RuntimeException('Choose a valid environment.');
                }
                $bucket = ss_post('aws_s3_bucket', 63);
                if ($bucket !== '' && !preg_match('/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/', $bucket)) {
                    throw new RuntimeException('Enter a valid S3 bucket name.');
                }
                $region = ss_post('aws_region', 30);
                if ($region !== '' && !preg_match('/^[a-z]{2}(-[a-z]+)+-\d$/', $region)) {
                    throw new RuntimeException('Enter a valid AWS region such as eu-west-1.');
                }
                $access = ss_post('aws_access_key', 128);
                $secret = (string)($_POST['aws_secret_key'] ?? '');
                if (($access !== '' || $secret !== '') && !ss_encryption_available()) {
                    throw new RuntimeException('Saving AWS keys needs APP_ENCRYPTION_KEY so they can be encrypted. Alternatively set AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY in the server environment.');
                }
                $docs = ss_post_flag('aws_s3_documents_enabled');
                ss_save($conn, 'app_environment', $env);
                ss_save($conn, 'aws_s3_bucket', $bucket);
                ss_save($conn, 'aws_region', $region);
                ss_save_secret($conn, 'aws_access_key', $access);
                ss_save_secret($conn, 'aws_secret_key', $secret);
                if ($docs === '1') {
                    $cfg = ss_aws_config(ss_get_all($conn));
                    if (in_array('', [$cfg['bucket'], $cfg['region'], $cfg['access_key'], $cfg['secret_key']], true)) {
                        throw new RuntimeException('Other settings saved, but S3 document storage needs a bucket, region and keys before it can be switched on.');
                    }
                }
                ss_save($conn, 'aws_s3_documents_enabled', $docs);
                ss_save($conn, 'golive_production_url', ss_post('golive_production_url', 255));
                ss_save($conn, 'golive_planned_date', ss_post_date('golive_planned_date', 'Planned go-live date'));
                ss_save($conn, 'golive_actual_date', ss_post_date('golive_actual_date', 'Actual go-live date'));
                ss_save($conn, 'golive_hypercare_end', ss_post_date('golive_hypercare_end', 'Hypercare end date'));
                ss_save($conn, 'golive_support_contact', ss_post('golive_support_contact', 255));
                ss_save_checklist($conn, $settings, 'golive_checklist', ss_golive_checklist_items());
                ss_redirect($tab, 'AWS go-live settings saved.');
            }

            case 'test_aws': {
                try {
                    ss_s3_test(ss_aws_config($settings), 'healthchecks');
                    ss_save($conn, 'aws_s3_last_test', 'ok ' . date('Y-m-d H:i'));
                } catch (Throwable $e) {
                    ss_save($conn, 'aws_s3_last_test', 'failed ' . date('Y-m-d H:i') . ': ' . mb_substr($e->getMessage(), 0, 200));
                    throw $e;
                }
                ss_redirect($tab, 'S3 bucket is reachable and writable.');
            }

            default:
                throw new RuntimeException('Unknown settings action.');
        }
    } catch (Throwable $e) {
        ss_redirect($tab, $e->getMessage(), false);
    }
}

/* --------------------------------------------------------------------------
   View data
   -------------------------------------------------------------------------- */

$settings = ss_get_all($conn);
$theme = array_merge(ss_theme_defaults(), array_filter(
    array_intersect_key($settings, ss_theme_defaults()),
    static fn($v) => $v !== ''
));

$backup_dir_error = '';
try {
    $backup_dir_display = ss_backup_dir($settings, false);
} catch (Throwable $e) {
    $backup_dir_display = (string)($settings['backup_local_path'] ?? '');
    $backup_dir_error = $e->getMessage();
}
$backups = ss_backup_list($settings);
$backup_log = json_decode((string)($settings['backup_log'] ?? '[]'), true) ?: [];

$email_cols = ss_email_settings_columns($conn);
$email_row = $email_cols ? (ss_email_settings_row($conn) ?? []) : [];
$encryption_ok = ss_encryption_available();

$uat_cases = [];
$res = $conn->query('SELECT * FROM uat_test_cases ORDER BY module, id');
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $uat_cases[] = $row;
    }
}
$uat_summary = array_fill_keys(array_keys(ss_uat_statuses()), 0);
foreach ($uat_cases as $case) {
    $uat_summary[$case['status']] = ($uat_summary[$case['status']] ?? 0) + 1;
}

$security_checks = ss_security_checks($settings);
$golive_checks = ss_golive_checks($conn, $settings);
$aws_cfg = ss_aws_config($settings);
$aws_env_keys = (getenv('AWS_ACCESS_KEY_ID') ?: '') !== '';
