<?php
declare(strict_types=1);

require_once '../includes/config.php';
require_once __DIR__ . '/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$success = '';
$error = '';

function settings_table_exists(mysqli $conn): bool {
    $res = $conn->query("SHOW TABLES LIKE 'site_settings'");
    return $res && $res->num_rows > 0;
}

function ensure_site_settings_table(mysqli $conn): void {
    $conn->query("
        CREATE TABLE IF NOT EXISTS site_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT NULL,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function get_all_settings(mysqli $conn): array {
    $settings = [];

    $res = $conn->query("SELECT setting_key, setting_value FROM site_settings");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }

    return $settings;
}

function save_setting(mysqli $conn, string $key, string $value): bool {
    $stmt = $conn->prepare("
        INSERT INTO site_settings (setting_key, setting_value, updated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_at = NOW()
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("ss", $key, $value);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function upload_setting_file(string $field, array $allowed_exts, string $folder = 'uploads/settings'): ?string {
    if (empty($_FILES[$field]['name'])) {
        return null;
    }

    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Failed to upload {$field}.");
    }

    $original = $_FILES[$field]['name'];
    $tmp = $_FILES[$field]['tmp_name'];
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed_exts, true)) {
        throw new RuntimeException("Invalid file type for {$field}.");
    }

    $root = dirname(__DIR__, 2);
    $upload_dir = $root . '/' . $folder;

    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0775, true);
    }

    $filename = $field . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destination = $upload_dir . '/' . $filename;

    if (!move_uploaded_file($tmp, $destination)) {
        throw new RuntimeException("Could not save {$field}.");
    }

    return $folder . '/' . $filename;
}

ensure_site_settings_table($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    try {
        $text_settings = [
            'site_name',
            'site_email',
            'site_phone',
            'site_address',
            'frontend_popup_title',
            'frontend_popup_message',
            'frontend_popup_button_text',
        ];

        $popup_url = trim((string)($_POST['frontend_popup_button_url'] ?? ''));
        if ($popup_url !== '') {
            $scheme = strtolower((string)parse_url($popup_url, PHP_URL_SCHEME));
            $is_local_path = str_starts_with($popup_url, '/') && !str_starts_with($popup_url, '//');
            if (!$is_local_path && (!filter_var($popup_url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true))) {
                throw new RuntimeException('The popup button link must be a valid website URL or local path.');
            }
        }

        $popup_start = trim((string)($_POST['frontend_popup_start'] ?? ''));
        $popup_stop = trim((string)($_POST['frontend_popup_stop'] ?? ''));
        $normalize_popup_datetime = static function (string $value): string {
            if ($value === '') return '';
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
            $errors = DateTimeImmutable::getLastErrors();
            if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                throw new RuntimeException('Popup start and stop times must be valid dates and times.');
            }
            return $date->format('Y-m-d H:i:s');
        };
        $popup_start = $normalize_popup_datetime($popup_start);
        $popup_stop = $normalize_popup_datetime($popup_stop);
        if ($popup_start !== '' && $popup_stop !== '' && strtotime($popup_stop) <= strtotime($popup_start)) {
            throw new RuntimeException('Popup stop time must be later than its start time.');
        }

        $popup_priority = filter_var($_POST['frontend_popup_priority'] ?? '0', FILTER_VALIDATE_INT);
        $popup_delay = filter_var($_POST['frontend_popup_delay'] ?? '0', FILTER_VALIDATE_INT);
        if ($popup_priority === false || $popup_priority < 0 || $popup_priority > 1000) {
            throw new RuntimeException('Popup priority must be between 0 and 1000.');
        }
        if ($popup_delay === false || $popup_delay < 0 || $popup_delay > 3600) {
            throw new RuntimeException('Popup delay must be between 0 and 3600 seconds.');
        }
        $popup_frequency = (string)($_POST['frontend_popup_frequency'] ?? 'session');
        if (!in_array($popup_frequency, ['every_page', 'session', 'daily', 'once'], true)) {
            throw new RuntimeException('Select a valid popup display frequency.');
        }
        $popup_scope = (string)($_POST['frontend_popup_scope'] ?? 'all');
        if (!in_array($popup_scope, ['all', 'selected'], true)) {
            throw new RuntimeException('Select whether the popup appears on all or selected pages.');
        }
        $popup_pages = trim((string)($_POST['frontend_popup_pages'] ?? ''));

        if (!empty($_FILES['frontend_popup_image']['name'])) {
            $image_tmp = (string)($_FILES['frontend_popup_image']['tmp_name'] ?? '');
            $image_size = (int)($_FILES['frontend_popup_image']['size'] ?? 0);
            if (($_FILES['frontend_popup_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || $image_tmp === ''
                || $image_size > 5 * 1024 * 1024
                || @getimagesize($image_tmp) === false) {
                throw new RuntimeException('Upload a valid popup image no larger than 5 MB.');
            }
        }

        $popup_image = upload_setting_file('frontend_popup_image', ['jpg', 'jpeg', 'png', 'webp', 'gif']);

        foreach ($text_settings as $key) {
            save_setting($conn, $key, trim($_POST[$key] ?? ''));
        }

        save_setting($conn, 'frontend_popup_button_url', $popup_url);
        save_setting($conn, 'frontend_popup_enabled', isset($_POST['frontend_popup_enabled']) ? '1' : '0');
        save_setting($conn, 'frontend_popup_start', $popup_start);
        save_setting($conn, 'frontend_popup_stop', $popup_stop);
        save_setting($conn, 'frontend_popup_priority', (string)$popup_priority);
        save_setting($conn, 'frontend_popup_frequency', $popup_frequency);
        save_setting($conn, 'frontend_popup_delay', (string)$popup_delay);
        save_setting($conn, 'frontend_popup_scope', $popup_scope);
        save_setting($conn, 'frontend_popup_pages', $popup_pages);
        save_setting($conn, 'frontend_popup_new_tab', isset($_POST['frontend_popup_new_tab']) ? '1' : '0');
        if ($popup_image !== null) {
            save_setting($conn, 'frontend_popup_image', $popup_image);
        } elseif (isset($_POST['frontend_popup_remove_image'])) {
            save_setting($conn, 'frontend_popup_image', '');
        }

        $logo = upload_setting_file('site_logo', ['jpg', 'jpeg', 'png', 'webp', 'gif']);
        if ($logo !== null) {
            save_setting($conn, 'site_logo', $logo);
        }

        $favicon = upload_setting_file('site_favicon', ['ico', 'jpg', 'jpeg', 'png', 'webp', 'gif']);
        if ($favicon !== null) {
            save_setting($conn, 'site_favicon', $favicon);
        }

        $success = 'Site settings saved successfully.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$settings = get_all_settings($conn);
