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
$refinements_version = @filemtime(dirname(__DIR__) . '/assets/css/refinements.css') ?: $style_version;

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
    <link
        rel="stylesheet"
        href="<?= h($site_url) ?>/assets/css/refinements.css?v=<?= (int)$refinements_version ?>"
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

<?php require_once __DIR__ . '/accessibility-widget.php'; ?>
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
<?php
$frontend_popup_enabled = function_exists('get_setting') && get_setting($conn, 'frontend_popup_enabled', '0') === '1';
$frontend_popup_title = $frontend_popup_enabled ? trim(get_setting($conn, 'frontend_popup_title', '')) : '';
$frontend_popup_message = $frontend_popup_enabled ? trim(get_setting($conn, 'frontend_popup_message', '')) : '';
$frontend_popup_image = $frontend_popup_enabled ? trim(get_setting($conn, 'frontend_popup_image', '')) : '';
$frontend_popup_button_text = $frontend_popup_enabled ? trim(get_setting($conn, 'frontend_popup_button_text', '')) : '';
$frontend_popup_button_url = $frontend_popup_enabled ? trim(get_setting($conn, 'frontend_popup_button_url', '')) : '';
$frontend_popup_start = $frontend_popup_enabled ? trim(get_setting($conn, 'frontend_popup_start', '')) : '';
$frontend_popup_stop = $frontend_popup_enabled ? trim(get_setting($conn, 'frontend_popup_stop', '')) : '';
$frontend_popup_priority = $frontend_popup_enabled ? max(0, (int)get_setting($conn, 'frontend_popup_priority', '0')) : 0;
$frontend_popup_frequency = $frontend_popup_enabled ? get_setting($conn, 'frontend_popup_frequency', 'session') : 'session';
$frontend_popup_delay = $frontend_popup_enabled ? max(0, min(3600, (int)get_setting($conn, 'frontend_popup_delay', '0'))) : 0;
$frontend_popup_scope = $frontend_popup_enabled ? get_setting($conn, 'frontend_popup_scope', 'all') : 'all';
$frontend_popup_pages = $frontend_popup_enabled ? get_setting($conn, 'frontend_popup_pages', '') : '';
$frontend_popup_new_tab = $frontend_popup_enabled && get_setting($conn, 'frontend_popup_new_tab', '0') === '1';
$popup_url_scheme = strtolower((string)parse_url($frontend_popup_button_url, PHP_URL_SCHEME));
$popup_url_is_safe = $frontend_popup_button_url === ''
    || (str_starts_with($frontend_popup_button_url, '/') && !str_starts_with($frontend_popup_button_url, '//'))
    || (in_array($popup_url_scheme, ['http', 'https'], true) && filter_var($frontend_popup_button_url, FILTER_VALIDATE_URL));
$popup_image_is_safe = preg_match('~^uploads/settings/[a-zA-Z0-9_.-]+\.(?:jpe?g|png|webp|gif)$~i', $frontend_popup_image) === 1;
$popup_image_only = $popup_image_is_safe
    && $frontend_popup_title === ''
    && $frontend_popup_message === '';
$popup_is_in_schedule = true;
try {
    $popup_now = new DateTimeImmutable('now');
    if ($frontend_popup_start !== '' && $popup_now < new DateTimeImmutable($frontend_popup_start)) $popup_is_in_schedule = false;
    if ($frontend_popup_stop !== '' && $popup_now > new DateTimeImmutable($frontend_popup_stop)) $popup_is_in_schedule = false;
} catch (Throwable $exception) {
    $popup_is_in_schedule = false;
}
$current_frontend_path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

// Normalize a path so admin-entered values and the live request path are
// compared against the same canonical form. This tolerates absolute URLs,
// query strings/fragments, a trailing ".php", repeated/trailing slashes and
// case differences, so the "Selected pages only" scope matches reliably.
$normalize_popup_path = static function (string $path): string {
    $path = trim((string)$path);
    if ($path === '') {
        return '/';
    }
    // Accept a full URL (or a path that carries a query string / fragment).
    $path = (string)(parse_url($path, PHP_URL_PATH) ?: $path);
    // "/apply.php" and the clean "/apply" refer to the same page.
    $path = preg_replace('/\.php$/i', '', $path) ?? $path;
    // Force a single leading slash, collapse repeats and drop a trailing slash.
    $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
    $path = preg_replace('~/+~', '/', $path) ?? '/';
    $path = $path === '/' ? '/' : rtrim($path, '/');
    return strtolower($path);
};

// Strip the site's base path when the app is served from a sub-directory,
// so paths entered relative to the site root also match during local
// development (e.g. /edtech-site/apply vs /apply).
$popup_base_path = '/' . trim((string)(parse_url((string)$site_url, PHP_URL_PATH) ?: ''), '/');
$popup_relative_path = static function (string $path) use ($popup_base_path): string {
    if ($popup_base_path === '/') {
        return $path;
    }
    if ($path === $popup_base_path) {
        return '/';
    }
    return str_starts_with($path, $popup_base_path . '/') ? substr($path, strlen($popup_base_path)) : $path;
};

$popup_matches_page = $frontend_popup_scope !== 'selected';
if ($frontend_popup_scope === 'selected') {
    $current_relative = $popup_relative_path($normalize_popup_path($current_frontend_path));
    foreach (preg_split('/\R/', $frontend_popup_pages) ?: [] as $popup_page) {
        if (trim($popup_page) === '') {
            continue;
        }
        if ($popup_relative_path($normalize_popup_path($popup_page)) === $current_relative) {
            $popup_matches_page = true;
            break;
        }
    }
}
$popup_frequency = in_array($frontend_popup_frequency, ['every_page', 'session', 'daily', 'once'], true) ? $frontend_popup_frequency : 'session';
$popup_version = sha1(json_encode([$frontend_popup_title, $frontend_popup_message, $frontend_popup_image, $frontend_popup_button_url, $frontend_popup_start, $frontend_popup_stop], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'popup');
?>
<?php
$popup_has_cta = $frontend_popup_button_text !== '' && $frontend_popup_button_url !== '' && $popup_url_is_safe;
$popup_cta_attrs = $frontend_popup_new_tab ? ' target="_blank" rel="noopener noreferrer"' : '';
$popup_image_alt = $frontend_popup_title !== '' ? $frontend_popup_title : ($frontend_popup_button_text !== '' ? $frontend_popup_button_text : 'Announcement');
?>
<?php if ($frontend_popup_enabled && $popup_is_in_schedule && $popup_matches_page && ($frontend_popup_title !== '' || $frontend_popup_message !== '' || $popup_image_is_safe)): ?>
<div class="site-popup" id="sitePopup" data-frequency="<?= h($popup_frequency) ?>" data-delay="<?= (int)$frontend_popup_delay ?>" data-priority="<?= (int)$frontend_popup_priority ?>" data-version="<?= h($popup_version) ?>" hidden>
  <div class="site-popup__overlay" data-popup-close></div>
  <div class="site-popup__dialog<?= $popup_image_only ? ' site-popup__dialog--image' : '' ?>" role="dialog" aria-modal="true" tabindex="-1" aria-labelledby="sitePopupTitle"<?= $frontend_popup_message !== '' ? ' aria-describedby="sitePopupMessage"' : '' ?>>
    <button class="site-popup__close" type="button" aria-label="Close" data-popup-close>
      <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    </button>
    <?php if ($popup_image_is_safe): ?>
      <div class="site-popup__media">
        <?php if ($popup_image_only && $popup_has_cta): ?><a href="<?= h($frontend_popup_button_url) ?>"<?= $popup_cta_attrs ?> data-popup-action><?php endif; ?>
        <img src="<?= h($site_url . '/' . $frontend_popup_image) ?>" alt="<?= h($popup_image_alt) ?>" decoding="async">
        <?php if ($popup_image_only && $popup_has_cta): ?></a><?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($popup_image_only): ?>
      <h2 class="site-popup__sr-only" id="sitePopupTitle"><?= h($popup_image_alt) ?></h2>
    <?php else: ?>
      <div class="site-popup__body">
        <h2 class="site-popup__title" id="sitePopupTitle"><?= $frontend_popup_title !== '' ? h($frontend_popup_title) : 'Announcement' ?></h2>
        <?php if ($frontend_popup_message !== ''): ?><p class="site-popup__message" id="sitePopupMessage"><?= nl2br(h($frontend_popup_message)) ?></p><?php endif; ?>
      </div>
      <div class="site-popup__footer">
        <?php if ($popup_has_cta): ?>
          <button class="site-popup__btn site-popup__btn--secondary" type="button" data-popup-close>Not now</button>
          <a class="site-popup__btn site-popup__btn--primary" href="<?= h($frontend_popup_button_url) ?>"<?= $popup_cta_attrs ?> data-popup-action><?= h($frontend_popup_button_text) ?></a>
        <?php else: ?>
          <button class="site-popup__btn site-popup__btn--primary" type="button" data-popup-close>Got it</button>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<script>
(() => {
  const popup = document.getElementById('sitePopup');
  if (!popup) return;
  const dialog = popup.querySelector('.site-popup__dialog');
  const frequency = popup.dataset.frequency || 'session';
  const storageKey = 'hiveFrontendPopupDismissed:' + (popup.dataset.version || 'default');
  const dateKey = new Date().toISOString().slice(0, 10);
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const focusableSelector = 'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])';
  let lastFocused = null;
  const isDismissed = () => {
    try {
      if (frequency === 'session') return sessionStorage.getItem(storageKey) === '1';
      if (frequency === 'daily') return localStorage.getItem(storageKey) === dateKey;
      if (frequency === 'once') return localStorage.getItem(storageKey) === '1';
    } catch (_) {}
    return false;
  };
  const remember = () => {
    try {
      if (frequency === 'session') sessionStorage.setItem(storageKey, '1');
      else if (frequency === 'daily') localStorage.setItem(storageKey, dateKey);
      else if (frequency === 'once') localStorage.setItem(storageKey, '1');
    } catch (_) {}
  };
  const onKeydown = event => {
    if (event.key === 'Escape') { event.preventDefault(); close(); return; }
    if (event.key !== 'Tab') return;
    const items = [...dialog.querySelectorAll(focusableSelector)].filter(el => el.offsetParent !== null);
    if (!items.length) { event.preventDefault(); return; }
    const first = items[0];
    const last = items[items.length - 1];
    if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog)) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  };
  const open = () => {
    lastFocused = document.activeElement;
    popup.hidden = false;
    requestAnimationFrame(() => requestAnimationFrame(() => popup.classList.add('is-open')));
    dialog.focus({ preventScroll: true });
    document.addEventListener('keydown', onKeydown);
  };
  const close = () => {
    if (popup.hidden) return;
    remember();
    popup.classList.remove('is-open');
    document.removeEventListener('keydown', onKeydown);
    window.setTimeout(() => {
      popup.hidden = true;
      if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus({ preventScroll: true });
    }, reduceMotion ? 0 : 200);
  };
  popup.querySelectorAll('[data-popup-close]').forEach(el => el.addEventListener('click', close));
  popup.querySelectorAll('[data-popup-action]').forEach(el => el.addEventListener('click', remember));
  if (!isDismissed()) {
    const delay = Math.max(0, Math.min(3600, Number(popup.dataset.delay || 0))) * 1000;
    window.setTimeout(() => { if (!isDismissed()) open(); }, delay);
  }
})();
</script>
<?php endif; ?>
<div id="main-content" tabindex="-1"></div>
