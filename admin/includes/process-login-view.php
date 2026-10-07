<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

function login_setting(mysqli $conn, array $keys, string $default = ''): string
{
    if (function_exists('get_setting')) {
        foreach ($keys as $key) {
            $value = trim((string)get_setting($conn, $key, ''));
            if ($value !== '') {
                return $value;
            }
        }
    }

    $tables = ['site_settings', 'settings'];

    foreach ($tables as $table) {
        $tableEsc = $conn->real_escape_string($table);
        $exists = $conn->query("SHOW TABLES LIKE '{$tableEsc}'");

        if (!$exists || $exists->num_rows === 0) {
            continue;
        }

        foreach ($keys as $key) {
            $keyEsc = $conn->real_escape_string($key);

            $res = $conn->query("
                SELECT setting_value AS value
                FROM `{$table}`
                WHERE setting_key = '{$keyEsc}'
                LIMIT 1
            ");

            if ($res && ($row = $res->fetch_assoc())) {
                $value = trim((string)($row['value'] ?? ''));

                if ($value !== '') {
                    return $value;
                }
            }

            $res = $conn->query("
                SELECT value
                FROM `{$table}`
                WHERE name = '{$keyEsc}'
                LIMIT 1
            ");

            if ($res && ($row = $res->fetch_assoc())) {
                $value = trim((string)($row['value'] ?? ''));

                if ($value !== '') {
                    return $value;
                }
            }
        }
    }

    return $default;
}

function login_asset_url(string $path): string
{
    $path = trim($path);

    if ($path === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    return rtrim(SITE_URL, '/') . '/' . ltrim($path, '/');
}

function login_asset_exists(string $path): bool
{
    $path = trim($path);

    if ($path === '') {
        return false;
    }

    if (preg_match('/^https?:\/\//i', $path)) {
        return true;
    }

    return file_exists(__DIR__ . '/../../' . ltrim($path, '/'));
}

if (!empty($_SESSION['admin_id'])) {
    header('Location: ../index.php');
    exit;
}

$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);

$old_username = $_SESSION['login_username'] ?? '';
unset($_SESSION['login_username']);

$redirect = $_GET['redirect'] ?? ($_SESSION['login_redirect'] ?? 'index.php');

if (!preg_match('/^[a-zA-Z0-9_\-\/.?=&]+$/', $redirect) || str_contains($redirect, '..')) {
    $redirect = 'index.php';
}

$site_name = login_setting($conn, ['site_name', 'website_name', 'app_name'], 'EdTech Fellowship');

$site_logo = login_setting($conn, [
    'site_logo'
], '');

$site_favicon = login_setting($conn, [
    'site_favicon',
    'favicon',
    'website_favicon'
], '');

$site_logo_url = login_asset_exists($site_logo) ? login_asset_url($site_logo) : '';
$site_favicon_url = login_asset_exists($site_favicon) ? login_asset_url($site_favicon) : '';