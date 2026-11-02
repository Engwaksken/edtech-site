<?php
// Emits the admin-configured theme colours and fonts (Site Settings > Theme / Typography).
// Included from accessibility-widget.php, which every page head loads. It must never
// break a page: no database, no table or a bad value simply means no overrides.
if (defined('SITE_THEME_HEAD_INCLUDED')) {
    return;
}
define('SITE_THEME_HEAD_INCLUDED', true);

(static function (): void {
    $conn = $GLOBALS['conn'] ?? null;
    if (!($conn instanceof mysqli)) {
        return;
    }
    try {
        require_once __DIR__ . '/site-settings-service.php';
        $keys = array_merge(array_keys(ss_theme_defaults()), ['theme_apply_frontend', 'theme_apply_admin']);
        $in = "'" . implode("','", $keys) . "'";
        $res = @$conn->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($in)");
        if (!$res) {
            return;
        }
        $settings = [];
        while ($row = $res->fetch_assoc()) {
            $settings[(string)$row['setting_key']] = (string)($row['setting_value'] ?? '');
        }
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $is_admin = str_contains($script, '/admin/');
        if (($settings[$is_admin ? 'theme_apply_admin' : 'theme_apply_frontend'] ?? '1') !== '1') {
            return;
        }
        $fonts = ss_google_fonts_url($settings);
        $css = ss_theme_css($settings, $is_admin);
        if ($fonts !== '') {
            echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
            echo '<link rel="stylesheet" href="' . htmlspecialchars($fonts, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }
        if ($css !== '') {
            // Values are validated (hex colours, whitelisted fonts, numeric sizes) before reaching here.
            echo '<style id="site-theme-overrides">' . str_replace('<', '', $css) . '</style>' . "\n";
        }
    } catch (Throwable $e) {
        error_log('Theme overrides skipped: ' . $e->getMessage());
    }
})();
