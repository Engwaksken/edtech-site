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

if (!function_exists('truncate')) {
    function truncate(string $text, int $limit = 80): string {
        $text = trim($text);
        return mb_strlen($text) > $limit
            ? mb_substr($text, 0, $limit - 3) . '...'
            : $text;
    }
}

function dashboard_table_exists(mysqli $conn, string $table): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);

    $sql = "SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'";
    $res = $conn->query($sql);

    return $res && $res->num_rows > 0;
}

function dashboard_count_table(mysqli $conn, string $table, string $where = '1'): int {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);

    if (!dashboard_table_exists($conn, $table)) {
        return 0;
    }

    $sql = "SELECT COUNT(*) AS c FROM `$table` WHERE $where";
    $res = $conn->query($sql);

    if (!$res) {
        return 0;
    }

    $row = $res->fetch_assoc();
    return (int)($row['c'] ?? 0);
}

$site_name = function_exists('get_setting')
    ? get_setting($conn, 'site_name', 'EdTech Fellowship')
    : 'EdTech Fellowship';

$stats = [
    'cohorts'     => dashboard_count_table($conn, 'cohorts'),
    'ventures'    => dashboard_count_table($conn, 'ventures'),
    'team'        => dashboard_count_table($conn, 'venture_team'),
    'staff'       => dashboard_count_table($conn, 'staff'),
    'assignments' => dashboard_count_table($conn, 'venture_staff'),
    'messages'    => dashboard_count_table($conn, 'contact_messages', "status='unread'"),
];

$recent_cohorts = false;
if (dashboard_table_exists($conn, 'cohorts')) {
    $recent_cohorts = $conn->query("
        SELECT c.*,
               (
                   SELECT COUNT(*)
                   FROM ventures s
                   WHERE s.cohort_id = c.id
               ) AS venture_count
        FROM cohorts c
        ORDER BY c.created_at DESC
        LIMIT 8
    ");
}

$recent_ventures = false;
if (dashboard_table_exists($conn, 'ventures')) {
    $recent_ventures = $conn->query("
        SELECT s.*, c.name AS cohort_name
        FROM ventures s
        LEFT JOIN cohorts c ON c.id = s.cohort_id
        ORDER BY s.created_at DESC
        LIMIT 8
    ");
}

$recent_msgs = false;
if (dashboard_table_exists($conn, 'contact_messages')) {
    $recent_msgs = $conn->query("
        SELECT *
        FROM contact_messages
        ORDER BY created_at DESC
        LIMIT 8
    ");
}

$admin_first_name = !empty($ADMIN['full_name'])
    ? explode(' ', $ADMIN['full_name'])[0]
    : 'Admin';

$greeting = date('H') < 12
    ? 'morning'
    : (date('H') < 17 ? 'afternoon' : 'evening');