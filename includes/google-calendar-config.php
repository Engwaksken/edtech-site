<?php
declare(strict_types=1);

if (!defined('GOOGLE_CALENDAR_CLIENT_ID')) {
    define('GOOGLE_CALENDAR_CLIENT_ID', getenv('GOOGLE_CALENDAR_CLIENT_ID') ?: '');
}
if (!defined('GOOGLE_CALENDAR_CLIENT_SECRET')) {
    define('GOOGLE_CALENDAR_CLIENT_SECRET', getenv('GOOGLE_CALENDAR_CLIENT_SECRET') ?: '');
}
if (!defined('GOOGLE_CALENDAR_REDIRECT_URI')) {
    $base = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';
    define('GOOGLE_CALENDAR_REDIRECT_URI', $base . '/admin/includes/google-oauth-callback.php');
}


if (!defined('GOOGLE_CALENDAR_DEDICATED_NAME')) {
    define('GOOGLE_CALENDAR_DEDICATED_NAME', getenv('GOOGLE_CALENDAR_DEDICATED_NAME') ?: 'Mentoring Sessions');
}