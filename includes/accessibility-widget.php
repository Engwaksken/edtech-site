<?php
// Accessibility widget assets. Include inside <head>; safe to include more
// than once and has no database dependency (works on standalone/error pages).
if (defined('A11Y_WIDGET_INCLUDED')) {
    return;
}
define('A11Y_WIDGET_INCLUDED', true);

$a11yw_base = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';
$a11yw_css  = dirname(__DIR__) . '/assets/css/accessibility-widget.css';
$a11yw_js   = dirname(__DIR__) . '/assets/js/accessibility-widget.js';
$a11yw_css_href = htmlspecialchars($a11yw_base . '/assets/css/accessibility-widget.css?v=' . (is_file($a11yw_css) ? filemtime($a11yw_css) : 1), ENT_QUOTES, 'UTF-8');
$a11yw_js_src   = htmlspecialchars($a11yw_base . '/assets/js/accessibility-widget.js?v=' . (is_file($a11yw_js) ? filemtime($a11yw_js) : 1), ENT_QUOTES, 'UTF-8');
// Large-screen scaling rides on this include because it is loaded by every page head.
$a11yw_ls_css = dirname(__DIR__) . '/assets/css/large-screens.css';
$a11yw_ls_href = htmlspecialchars($a11yw_base . '/assets/css/large-screens.css?v=' . (is_file($a11yw_ls_css) ? filemtime($a11yw_ls_css) : 1), ENT_QUOTES, 'UTF-8');
?>
<link rel="stylesheet" href="<?= $a11yw_ls_href ?>">
<link rel="stylesheet" href="<?= $a11yw_css_href ?>">
<script src="<?= $a11yw_js_src ?>"></script>
