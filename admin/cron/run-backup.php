<?php
declare(strict_types=1);

/*
 * Scheduled backups (Site Settings > Backups).
 *
 * Run from cron, e.g. daily at 02:00:
 *   0 2 * * * php /path/to/site/admin/cron/run-backup.php
 *
 * The script only creates a backup when the configured schedule says one is due,
 * so it is safe to run more often than the schedule. Pass --force to run now.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/site-settings-service.php';

$force = in_array('--force', $argv ?? [], true);

ss_ensure_tables($conn);
$settings = ss_get_all($conn);

if (!$force && !ss_backup_due($settings)) {
    fwrite(STDOUT, 'No backup due (schedule: ' . ($settings['backup_schedule'] ?? 'off') . ").\n");
    exit(0);
}

$result = ss_run_backup($conn, $settings, ['trigger' => $force ? 'cron (forced)' : 'cron']);
fwrite($result['ok'] ? STDOUT : STDERR, $result['message'] . "\n");
exit($result['ok'] ? 0 : 1);
