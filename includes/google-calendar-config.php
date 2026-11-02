<?php
declare(strict_types=1);

require_once __DIR__ . '/environment.php';

// Site settings are database-backed and available to every calendar runtime.
require_once __DIR__ . '/site-settings-service.php';

function google_calendar_setting(string $key): string
{
    static $settings = null;
    if ($settings === null) {
        $settings = [];
        global $conn;
        if (isset($conn) && $conn instanceof mysqli) {
            try {
                $settings = ss_get_all($conn);
            } catch (Throwable $e) {
                $settings = [];
            }
        }
    }
    return (string)($settings[$key] ?? '');
}

if (!defined('GOOGLE_CALENDAR_CLIENT_ID')) {
    define('GOOGLE_CALENDAR_CLIENT_ID', (getenv('GOOGLE_CALENDAR_CLIENT_ID') ?: '') ?: google_calendar_setting('google_calendar_client_id'));
}
if (!defined('GOOGLE_CALENDAR_CLIENT_SECRET')) {
    define('GOOGLE_CALENDAR_CLIENT_SECRET', (getenv('GOOGLE_CALENDAR_CLIENT_SECRET') ?: '') ?: google_calendar_setting('google_calendar_client_secret'));
}
if (!defined('GOOGLE_CALENDAR_REDIRECT_URI')) {
    $base = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';
    define('GOOGLE_CALENDAR_REDIRECT_URI', $base . '/admin/includes/google-oauth-callback.php');
}


if (!defined('GOOGLE_CALENDAR_DEDICATED_NAME')) {
    define('GOOGLE_CALENDAR_DEDICATED_NAME', getenv('GOOGLE_CALENDAR_DEDICATED_NAME') ?: 'Mentoring Sessions');
}
