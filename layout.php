<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/includes/config.php';
}

if (!function_exists('h')) {
    function h($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$base_url = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';

$venture_id = (int)($_SESSION['venture_id'] ?? 0);

if ($venture_id <= 0) {
    header('Location: ' . $base_url . '/login');
    exit;
}


if (!isset($VENTURE) || !is_array($VENTURE)) {
    $stmt = $conn->prepare("SELECT * FROM ventures WHERE id = ? LIMIT 1");

    if (!$stmt) {
        http_response_code(500);
        exit('We could not load your workspace. Please try again shortly.');
    }

    $stmt->bind_param('i', $venture_id);
    $stmt->execute();
    $VENTURE = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$VENTURE) {
    session_destroy();
    header('Location: ' . $base_url . '/login');
    exit;
}


$current_nav = $current_nav ?? basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$current_nav = trim((string)$current_nav);

if ($current_nav === '' || $current_nav === '/') {
    $current_nav = 'dashboard';
}

$current_nav = preg_replace('/\.php$/i', '', $current_nav);

$page_title = $page_title ?? 'Portal';

$venture_name    = trim((string)($VENTURE['name'] ?? 'Venture'));
$venture_logo    = trim((string)($VENTURE['logo'] ?? ''));
$venture_stage   = trim((string)($VENTURE['stage'] ?? 'active'));
$venture_founder = trim((string)($VENTURE['founder_name'] ?? $venture_name));
$cohort_id       = (int)($VENTURE['cohort_id'] ?? 0);

if ($venture_founder === '') {
    $venture_founder = $venture_name;
}

$site_name = function_exists('get_setting')
    ? get_setting($conn, 'site_name', 'Mastercard Foundation EdTech Fellowship')
    : 'Mastercard Foundation EdTech Fellowship';


if (!function_exists('vp_url')) {
    function vp_url(string $path = ''): string
    {
        $base = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';
        $path = trim($path, '/');

        if ($path === '') {
            return $base . '/dashboard';
        }

        $path = preg_replace('/\.php$/i', '', $path);

        return $base . '/' . $path;
    }
}


if (!function_exists('vp_is_active')) {
    function vp_is_active(string $current, string $href): bool
    {
        $current = preg_replace('/\.php$/i', '', basename($current));
        $href    = preg_replace('/\.php$/i', '', basename($href));

        return $current === $href;
    }
}


$unread = 0;

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM venture_messages
    WHERE venture_id = ?
      AND COALESCE(is_read, 0) = 0
");

if ($stmt) {
    $stmt->bind_param('i', $venture_id);
    $stmt->execute();
    $unread += (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
}


$tbl_check = $conn->query("SHOW TABLES LIKE 'mentor_messages'");

if ($tbl_check && $tbl_check->num_rows > 0) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM mentor_messages
        WHERE recipient_type = 'venture'
          AND recipient_id = ?
          AND COALESCE(is_read, 0) = 0
    ");

    if ($stmt) {
        $stmt->bind_param('i', $venture_id);
        $stmt->execute();
        $unread += (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
    }
}


$session_pending = 0;

$tbl_ms = $conn->query("SHOW TABLES LIKE 'mentor_sessions'");

if ($tbl_ms && $tbl_ms->num_rows > 0) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM mentor_sessions
        WHERE venture_id = ?
          AND status IN ('requested', 'confirmed', 'scheduled')
    ");

    if ($stmt) {
        $stmt->bind_param('i', $venture_id);
        $stmt->execute();
        $session_pending = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
    }
}


$has_mentors = false;

$tbl_ma = $conn->query("SHOW TABLES LIKE 'mentor_assignments'");

if ($tbl_ma && $tbl_ma->num_rows > 0) {
    $stmt = $conn->prepare("
        SELECT 1
        FROM mentor_assignments
        WHERE venture_id = ?
           OR cohort_id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param('ii', $venture_id, $cohort_id);
        $stmt->execute();
        $has_mentors = $stmt->get_result()->num_rows > 0;
        $stmt->close();
    }
}

/* Logo */
$logo_exists = false;
$logo_url = '';

if ($venture_logo !== '') {
    if (preg_match('/^https?:\/\//i', $venture_logo)) {
        $logo_exists = true;
        $logo_url = $venture_logo;
    } else {
        $clean_logo = ltrim(str_replace('\\', '/', $venture_logo), '/');
        $local_logo = __DIR__ . '/' . $clean_logo;

        if (is_file($local_logo)) {
            $logo_exists = true;
            $logo_url = $base_url . '/' . $clean_logo;
        }
    }
}

/* Text helpers */
$initials     = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $venture_name), 0, 2)) ?: 'VP';
$user_initial = strtoupper(substr($venture_founder, 0, 1)) ?: 'V';
$user_first   = explode(' ', $venture_founder)[0] ?? $venture_founder;

/* Sidebar item */
if (!function_exists('vp_sidebar_item')) {
    function vp_sidebar_item(
        string $href,
        string $icon,
        string $label,
        string $current,
        int $badge = 0,
        bool $new_tab = false
    ): void {
        $active = vp_is_active($current, $href) ? ' active' : '';
        $target = $new_tab ? ' target="_blank" rel="noopener noreferrer"' : '';
        ?>
        <a href="<?= h(vp_url($href)) ?>" class="vp-nav-link<?= h($active) ?>"<?= $active !== '' ? ' aria-current="page"' : '' ?><?= $target ?>>
            <span class="vp-nav-icon">
                <i class="fas <?= h($icon) ?>"></i>
            </span>

            <span class="vp-nav-text"><?= h($label) ?></span>

            <?php if ($badge > 0): ?>
                <span class="vp-nav-badge"><?= $badge > 99 ? '99+' : (int)$badge ?></span>
            <?php endif; ?>
        </a>
        <?php
    }
}

if (!function_exists('vp_flash')) {
    function vp_flash(string $msg, string $type = 'success'): void
    {
        $_SESSION['vp_flash_msg'] = $msg;
        $_SESSION['vp_flash_type'] = $type;
    }
}

if (!function_exists('vp_redir')) {
    function vp_redir(string $page): void
    {
        header('Location: ' . vp_url($page));
        exit;
    }
}

/* Navigation */
$nav_sections = [
    'Overview' => [
        ['dashboard', 'fa-th-large', 'Dashboard', 0],
    ],

    'Venture Workspace' => [
        ['profile', 'fa-rocket', 'Venture Profile', 0],
        ['team', 'fa-users', 'Team', 0],
        ['meal-data', 'fa-clipboard-list', 'M&E Reports', 0],
        ['documents', 'fa-folder-open', 'Documents', 0],
        ['venture_resources', 'fa-book-open', 'Resources', 0],
      
        ['tranches', 'fa-file', 'Tranches', 0],
    ],

    'Mentorship' => [
        ['mentors', 'fa-user-tie', 'My Mentors', 0],
        ['calendar', 'fa-calendar-alt', 'Calendar', $session_pending],
        ['sessions', 'fa-comments', 'Sessions', $session_pending],
        ['deliverables', 'fa-file', 'Deliverables', 0],
    ],

    'Engagement' => [
        ['events', 'fa-calendar-check', 'Events', 0],
        ['investors', 'fa-handshake', 'Investors', 0],
        ['messages', 'fa-comment-dots', 'Messages', $unread],
    ],
];

if (!$has_mentors) {
    unset($nav_sections['Mentorship']);
}

/* Assets */
$css_file = __DIR__ . '/assets/css/ventures.css';
$css_version = is_file($css_file) ? filemtime($css_file) : time();

$favicon = $base_url . '/assets/images/favicon.png';

/* Flash */
$flash_msg  = $_SESSION['vp_flash_msg'] ?? '';
$flash_type = $_SESSION['vp_flash_type'] ?? 'success';

unset($_SESSION['vp_flash_msg'], $_SESSION['vp_flash_type']);

$flash_icon = match ($flash_type) {
    'success' => 'fa-check-circle',
    'error', 'danger' => 'fa-exclamation-circle',
    'warning' => 'fa-exclamation-triangle',
    default => 'fa-info-circle',
};


$portal_logo = is_file(__DIR__ . '/assets/images/logo_white.webp') ? 'assets/images/logo_white.webp' : 'assets/images/logo_white.png';

$portal_logo_exists = file_exists(__DIR__ . '/../' . $portal_logo)
   || file_exists(__DIR__ . '/' . $portal_logo);

$logout_url = vp_url('logout');


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0, viewport-fit=cover"
    >

    <title><?= h($page_title) ?> - <?= h($venture_name) ?> Portal</title>

    <meta name="theme-color" content="#fc7f10">

    <link rel="icon" type="image/png" href="<?= h($favicon) ?>">
    <link rel="shortcut icon" href="<?= h($favicon) ?>">
    <link rel="apple-touch-icon" href="<?= h($favicon) ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600&family=Poppins:wght@500;600;700&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css"
    >

    <link
        rel="stylesheet"
        href="<?= h($base_url) ?>/assets/css/ventures.css?v=<?= (int)$css_version ?>"
    >

    <?php if (vp_is_active($current_nav, 'calendar')): ?>
        <style>
            .vp-content {
                padding: 20px;
            }

            @media (max-width: 640px) {
                .vp-content {
                    padding: 14px;
                }
            }
        </style>
    <?php endif; ?>
</head>

<body class="vp-body" data-current-nav="<?= h($current_nav) ?>">
<a class="skip-link" href="#main-content">Skip to your workspace</a>



<div
    class="vp-mobile-overlay"
    id="vpMobileOverlay"
    onclick="closeSidebar()"
    aria-hidden="true"
></div>


<aside class="vp-sidebar" id="vpSidebar" aria-label="Venture portal navigation">

    <div class="vp-sidebar-brand">

        <a
            href="<?= h(vp_url('dashboard')) ?>"
            class="vp-brand-link"
            aria-label="Dashboard"
        >

            <?php if ($portal_logo_exists): ?>

                <img
                    src="<?= h($base_url . '/' . $portal_logo) ?>"
                    alt="<?= h($site_name) ?>"
                    class="vp-brand-logo"
                >

            <?php else: ?>

                <div class="vp-brand-fallback">

                    <i class="fas fa-graduation-cap"></i>

                    <span><?= h($site_name) ?></span>

                </div>

            <?php endif; ?>

        </a>

        <button
            class="vp-sidebar-close"
            type="button"
            onclick="closeSidebar()"
            aria-label="Close navigation"
        >
            <i class="fas fa-times"></i>
        </button>

    </div>

    <div class="vp-venture-info">

        <?php if ($logo_exists): ?>

            <img
                src="<?= h($logo_url) ?>"
                class="vp-venture-avatar"
                alt="<?= h($venture_name) ?> logo"
                loading="lazy"
                onerror="this.style.display='none'"
            >

        <?php else: ?>

            <div class="vp-venture-initials">

                <?= h($initials) ?>

            </div>

        <?php endif; ?>

        <div class="vp-venture-copy">

            <div
                class="vp-venture-name"
                title="<?= h($venture_name) ?>"
            >

                <?= h($venture_name) ?>

            </div>

            <div class="vp-venture-stage">

                <?= h(ucfirst(str_replace('_', ' ', $venture_stage))) ?>

            </div>

        </div>

    </div>

    <nav class="vp-nav" aria-label="Portal navigation">

        <?php foreach ($nav_sections as $section_label => $items): ?>

            <div class="vp-nav-section">

                <div class="vp-nav-label">

                    <?= h($section_label) ?>

                </div>

                <?php foreach ($items as [$href, $icon, $label, $badge]): ?>

                    <?php
                    vp_sidebar_item(
                        $href,
                        $icon,
                        $label,
                        $current_nav,
                        (int)$badge
                    );
                    ?>

                <?php endforeach; ?>

            </div>

        <?php endforeach; ?>

    </nav>

    <div class="vp-sidebar-footer">

        <form method="POST" action="<?= h($logout_url) ?>">
        <button type="submit"
            class="vp-logout-btn"
        >

            <i class="fas fa-sign-out-alt"></i>

            <span>

                Sign Out

            </span>

        </button>
        </form>

    </div>

</aside>



<div class="vp-main" id="vpMain">

    <header class="vp-topbar">

        <div class="vp-topbar-left">

            <button
                class="vp-menu-btn"
                type="button"
                id="vpMenuBtn"
                onclick="toggleSidebar()"
                aria-label="Open navigation"
                aria-controls="vpSidebar"
                aria-expanded="false"
            >
                <i class="fas fa-bars"></i>
            </button>

            <div class="vp-topbar-title-wrap">
                <div class="vp-page-title"><?= h($page_title) ?></div>
                <div class="vp-page-subtitle"><?= h($venture_name) ?></div>
            </div>

        </div>

        <div class="vp-topbar-right">

            <?php if ($session_pending > 0 && $has_mentors): ?>
                <a
                    href="<?= h(vp_url('calendar')) ?>"
                    class="vp-notif-btn"
                    title="<?= (int)$session_pending ?> pending session<?= $session_pending !== 1 ? 's' : '' ?>"
                    aria-label="Pending sessions"
                >
                    <i class="fas fa-calendar-check"></i>
                    <span class="vp-notif-dot vp-warning-dot"></span>
                </a>
            <?php endif; ?>

            <a
                href="<?= h(vp_url('messages')) ?>"
                class="vp-notif-btn"
                title="Messages"
                aria-label="Messages"
            >
                <i class="fas fa-comment-dots"></i>

                <?php if ($unread > 0): ?>
                    <span class="vp-notif-dot"></span>
                <?php endif; ?>
            </a>

            <div class="vp-user-chip">
                <div class="vp-user-chip-avatar">
                    <?= h($user_initial) ?>
                </div>

                <span class="vp-user-chip-name">
                    <?= h($user_first) ?>
                </span>
            </div>

        </div>

    </header>

    <?php if ($flash_msg !== ''): ?>
        <div class="vp-flash <?= h($flash_type) ?>" role="alert">
            <i class="fas <?= h($flash_icon) ?>"></i>
            <span><?= h($flash_msg) ?></span>
        </div>
    <?php endif; ?>

    <main class="vp-content" id="main-content" tabindex="-1">

<script>
(function () {
    const sidebar = document.getElementById('vpSidebar');
    const overlay = document.getElementById('vpMobileOverlay');
    const menuBtn = document.getElementById('vpMenuBtn');
    const main = document.getElementById('vpMain');
    const logoutBtn = document.getElementById('logoutBtn');
    const logoutModal = document.getElementById('logoutModal');
    const cancelLogout = document.getElementById('cancelLogout');

    function setExpanded(open) {
        if (menuBtn) {
            menuBtn.setAttribute('aria-expanded', String(open));
        }
    }

    window.openSidebar = function () {
        if (!sidebar) return;

        sidebar.inert = false;
        sidebar.classList.add('open');
        document.body.classList.add('vp-sidebar-open');

        if (overlay) {
            overlay.classList.add('open');
        }

        setExpanded(true);
        if (window.innerWidth <= 900) {
            if (main) main.inert = true;
            sidebar.querySelector('button, a[href]')?.focus();
        }
    };

    window.closeSidebar = function () {
        if (!sidebar) return;

        const wasOpen = sidebar.classList.contains('open');
        sidebar.classList.remove('open');
        sidebar.inert = window.innerWidth <= 900;
        if (main) main.inert = false;
        document.body.classList.remove('vp-sidebar-open');

        if (overlay) {
            overlay.classList.remove('open');
        }

        setExpanded(false);
        if (wasOpen && window.innerWidth <= 900) menuBtn?.focus();
    };

    window.toggleSidebar = function () {
        if (!sidebar) return;

        if (sidebar.classList.contains('open')) {
            window.closeSidebar();
        } else {
            window.openSidebar();
        }
    };

    function openLogoutModal() {
        if (!logoutModal) return;

        logoutModal.classList.add('show');
        logoutModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('vp-modal-open');
    }

    function closeLogoutModal() {
        if (!logoutModal) return;

        logoutModal.classList.remove('show');
        logoutModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('vp-modal-open');
    }

    if (logoutBtn) {
        logoutBtn.addEventListener('click', function (event) {
            event.preventDefault();
            openLogoutModal();
        });
    }

    if (cancelLogout) {
        cancelLogout.addEventListener('click', closeLogoutModal);
    }

    if (logoutModal) {
        logoutModal.addEventListener('click', function (event) {
            if (event.target === logoutModal) {
                closeLogoutModal();
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Tab' && sidebar?.classList.contains('open') && window.innerWidth <= 900) {
            const controls = Array.from(sidebar.querySelectorAll('a[href], button:not([disabled])'))
                .filter(function (control) { return control.getClientRects().length > 0; });
            const first = controls[0];
            const last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last?.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first?.focus();
            }
        }
        if (event.key === 'Escape') {
            window.closeSidebar();
            closeLogoutModal();
        }
    });

    document.querySelectorAll('.vp-nav-link').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth <= 900) {
                window.closeSidebar();
            }
        });
    });

    window.matchMedia('(max-width: 900px)').addEventListener('change', function () {
        window.closeSidebar();
    });
    window.closeSidebar();
}());
</script>
