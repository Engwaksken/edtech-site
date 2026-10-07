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
        ];

        foreach ($text_settings as $key) {
            save_setting($conn, $key, trim($_POST[$key] ?? ''));
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