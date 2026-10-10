<?php
declare(strict_types=1);

if (!isset($conn)) {
    require_once __DIR__ . '/config.php';
}

if (!function_exists('h')) {
    function h($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$site_url = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';

$site_name = function_exists('get_setting')
    ? get_setting($conn, 'site_name', 'Mastercard Foundation EdTech Fellowship')
    : 'Mastercard Foundation EdTech Fellowship';

$site_email = function_exists('get_setting')
    ? get_setting($conn, 'site_email', 'edtech@hivecolab.com')
    : 'edtech@hivecolab.com';

$site_description = function_exists('get_setting')
    ? get_setting($conn, 'hero_subtitle', 'Empowering EdTech innovators across Africa.')
    : 'Empowering EdTech innovators across Africa.';

$request_path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$current_page = trim(basename((string)$request_path), '/');

if ($current_page === '' || $current_page === 'index') {
    $current_page = 'index';
}

$current_page = preg_replace('/\.php$/i', '', $current_page);

$page_title = $site_name;

if ($current_page !== 'index') {
    $page_title .= ' - ' . ucwords(str_replace(['-', '_'], ' ', $current_page));
}


$style_file_paths = [
    
    __DIR__ . '/assets/css/style.css',
    dirname(__DIR__) . '/assets/css/style.css',
];

$style_version = time();

foreach ($style_file_paths as $file_path) {
    if (is_file($file_path)) {
        $style_version = filemtime($file_path);
        break;
    }
}

$logo_path = asset_url('assets/images/logo.png');
$mastercard_logo_path = asset_url('assets/images/masteercard_foundation.png');
$favicon_path = $site_url . '/assets/images/favicon.png';

$current_url = $site_url . ($_SERVER['REQUEST_URI'] ?? '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title><?= h($page_title) ?></title>

    <meta name="description" content="<?= h($site_description) ?>">
    <meta name="author" content="<?= h($site_name) ?>">
    <meta name="robots" content="index,follow">

    <meta property="og:title" content="<?= h($page_title) ?>">
    <meta property="og:description" content="<?= h($site_description) ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= h($current_url) ?>">
    <meta property="og:image" content="<?= h($logo_path) ?>">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= h($page_title) ?>">
    <meta name="twitter:description" content="<?= h($site_description) ?>">
    <meta name="twitter:image" content="<?= h($logo_path) ?>">

    <link rel="canonical" href="<?= h($current_url) ?>">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600&family=Poppins:wght@500;600;700&display=swap"
        rel="stylesheet"
    >

    <!-- Font Awesome 6 -->
    <link
        rel="stylesheet"
        href="<?= h(asset_url('assets/vendor/fontawesome/css/all.min.css')) ?>"
        referrerpolicy="no-referrer"
    >

    <!-- Main stylesheet -->
    <link
        rel="stylesheet"
        href="<?= h($site_url) ?>/assets/css/style.css?v=<?= (int)$style_version ?>"
    >

    <!-- Favicon -->
    <link
        rel="icon"
        href="<?= h($favicon_path) ?>"
        type="image/png"
    >
    <link rel="apple-touch-icon" href="<?= h($favicon_path) ?>">
    <meta name="theme-color" content="#fc7f10">
    <!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-QY3FG79QBV"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-QY3FG79QBV');
</script>

</head>

<body>
<a class="skip-link" href="#main-content">Skip to main content</a>

<nav class="navbar" id="navbar">

    <div class="container">

        <div class="nav-content">

            <div class="left-logo">
                <a href="<?= h($site_url) ?>/">
                    <img
                        src="<?= h($logo_path) ?>"
                        alt="Hive Colab"
                        loading="eager"
                        decoding="async"
                        onerror="this.style.display='none'"
                    >
                </a>
            </div>

            <ul class="nav-menu" id="navMenu">

                <li>
                    <a href="<?= h($site_url) ?>/"
                       class="<?= $current_page === 'index' ? 'active' : '' ?>">
                        Home
                    </a>
                </li>

                <li>
                    <a href="<?= h($site_url) ?>/#about">About</a>
                </li>

                <li>
                    <a href="<?= h($site_url) ?>/#program">Programme</a>
                </li>

                <li>
                    <a href="<?= h($site_url) ?>/#eligibility">Eligibility</a>
                </li>

                <li>
                    <a href="<?= h($site_url) ?>/cohorts"
                       class="<?= in_array($current_page, ['cohorts', 'startup'], true) ? 'active' : '' ?>">
                        Cohorts
                    </a>
                </li>

                <li>
                    <a href="<?= h($site_url) ?>/faqs"
                       class="<?= $current_page === 'faqs' ? 'active' : '' ?>">
                        FAQs
                    </a>
                </li>

                <li>
                    <a href="<?= h($site_url) ?>/resources"
                       class="<?= $current_page === 'resources' ? 'active' : '' ?>">
                        Resources
                    </a>
                </li>

                <li>
                    <a href="<?= h($site_url) ?>/newsletter"
                       class="<?= $current_page === 'newsletter' ? 'active' : '' ?>">
                        Newsletters
                    </a>
                </li>

                <li>
                    <a href="<?= h($site_url) ?>/blogs"
                       class="<?= $current_page === 'blogs' ? 'active' : '' ?>">
                        Blogs
                    </a>
                </li>

                <li>
                    <a href="<?= h($site_url) ?>/#contact">Contact</a>
                </li>

                <li class="nav-login-item">
                    <a href="<?= h($site_url) ?>/login"
                       class="nav-login-btn <?= $current_page === 'login' ? 'active-login' : '' ?>">
                        <i class="fas fa-sign-in-alt"></i>
                        Login
                    </a>
                </li>

            </ul>

            <div class="right-logo">
                <a href="https://mastercardfdn.org"
                   target="_blank"
                   rel="noopener noreferrer">
                    <img
                        src="<?= h($mastercard_logo_path) ?>"
                        alt="Mastercard Foundation"
                        loading="eager"
                        decoding="async"
                        onerror="this.style.display='none'"
                    >
                </a>
            </div>

            <button
                class="nav-toggle"
                id="navToggle"
                type="button"
                aria-label="Toggle navigation"
                aria-controls="navMenu"
                aria-expanded="false"
            >
                <span></span>
                <span></span>
                <span></span>
            </button>

        </div>

    </div>

</nav>
<div id="main-content" tabindex="-1"></div>
