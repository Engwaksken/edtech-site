<?php
// No database dependency: safe to include in standalone and error-page heads.
$icon_base = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';
$icon_href = htmlspecialchars($icon_base . '/assets/images/favicon.png', ENT_QUOTES, 'UTF-8');
?>
<link rel="icon" type="image/png" href="<?= $icon_href ?>">
<link rel="apple-touch-icon" href="<?= $icon_href ?>">
