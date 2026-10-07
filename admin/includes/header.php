<?php
$admin_base_url = isset($admin_base_url) ? rtrim((string)$admin_base_url, '/') : (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') ? rtrim(substr((string)$_SERVER['SCRIPT_NAME'], 0, strpos((string)$_SERVER['SCRIPT_NAME'], '/admin/') + 6), '/') : '.');
$favicon_path = rtrim(defined('SITE_URL') ? SITE_URL : dirname($admin_base_url), '/') . '/assets/images/favicon.png';
$document_title = $document_title ?? (($page_title ?? 'Admin') . ' - Admin');
$page_title = $page_title ?? 'Admin'; $page_description = $page_description ?? ''; $page_icon = $page_icon ?? 'fa-solid fa-grid-2';
$extra_css = isset($extra_css) && is_array($extra_css) ? $extra_css : []; $extra_head = $extra_head ?? ''; $body_class = $body_class ?? '';
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title><?= h($document_title) ?></title>

    <link rel="icon" type="image/png" href="<?= h($favicon_path) ?>">
    <link rel="shortcut icon" href="<?= h($favicon_path) ?>">
    <link rel="apple-touch-icon" href="<?= h($favicon_path) ?>">

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

  
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
        referrerpolicy="no-referrer"
    >

    <link rel="stylesheet" href="<?= h($admin_base_url) ?>/assets/css/admin.css?v=<?= (int)filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
    <meta name="robots" content="noindex,nofollow">

    <?php foreach ($extra_css as $stylesheet): ?>
        <?php
        $stylesheet = trim((string)$stylesheet);

        if ($stylesheet === '') {
            continue;
        }
        ?>

        <link
            rel="stylesheet"
            href="<?= h($stylesheet) ?>"
        >
    <?php endforeach; ?>

    <?= $extra_head ?>

</head>

<body class="<?= h($body_class) ?>">

<?php
$sidebarFile = __DIR__ . '/sidebar.php';

if (is_file($sidebarFile)) {
    require $sidebarFile;
}
?>

<div
    class="admin-main"
    id="adminMain"
>

    <header class="admin-topbar">

        <div class="topbar-left">

          

            <div class="topbar-breadcrumb">

                <i
                    class="<?= h($page_icon) ?>"
                    aria-hidden="true"
                ></i>

                <div>

                    <strong>
                        <?= h($page_title) ?>
                    </strong>

                    <?php if ($page_description !== ''): ?>

                        <small>
                            <?= h($page_description) ?>
                        </small>

                    <?php endif; ?>

                </div>

            </div>

        </div>

        <div class="topbar-right">

            <a
                href="messages.php"
                class="topbar-btn"
                title="Messages"
                aria-label="Messages"
            >

                <i
                    class="fa-solid fa-envelope"
                    aria-hidden="true"
                ></i>

                <?php if ($unread_messages > 0): ?>

                    <span class="badge-count">
                        <?= $unread_messages ?>
                    </span>

                <?php endif; ?>

            </a>

            <?php if ($site_url !== ''): ?>

                <a
                    href="<?= h($site_url) ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="topbar-btn"
                    title="View website"
                    aria-label="View website"
                >

                    <i
                        class="fa-solid fa-arrow-up-right-from-square"
                        aria-hidden="true"
                    ></i>

                </a>

            <?php endif; ?>

            <div class="admin-avatar">

                <div class="avatar-circle">

                    <?php if (
                        $admin_photo !== ''
                        && admin_file_exists($admin_photo)
                    ): ?>

                        <img
                            src="<?= h(admin_asset_url($admin_photo)) ?>"
                            alt="<?= h($admin_full_name) ?>"
                        >

                    <?php else: ?>

                        <?= h($admin_initial) ?>

                    <?php endif; ?>

                </div>

                <div class="avatar-info">

                    <span class="avatar-name">
                        <?= h($admin_first_name) ?>
                    </span>

                    <span class="avatar-role">
                        <?= h($admin_role_label) ?>
                    </span>

                </div>

            </div>

        </div>

    </header>

    <main class="admin-content">

        <?php
        if (function_exists('show_flash')) {
            show_flash('global');
        }
        ?>

<script>

document.addEventListener('DOMContentLoaded', function () {
    var sidebar     = document.getElementById('adminSidebar');
    var main        = document.getElementById('adminMain');
    var collapseBtn = document.getElementById('sidebarToggle');

    if (!sidebar) {
        return;
    }

    var mobileQuery = window.matchMedia('(max-width: 900px)');

    if (collapseBtn && main) {
        var stored = null;
        try {
            stored = localStorage.getItem('adminSidebarCollapsed');
        } catch (e) {
            stored = null;
        }

        if (stored === '1' && !mobileQuery.matches) {
            sidebar.classList.add('collapsed');
            main.classList.add('collapsed');
            collapseBtn.setAttribute('aria-expanded', 'false');
        }

        collapseBtn.addEventListener('click', function () {
            var isCollapsed = sidebar.classList.toggle('collapsed');
            main.classList.toggle('collapsed', isCollapsed);
            collapseBtn.setAttribute('aria-expanded', String(!isCollapsed));

            try {
                localStorage.setItem('adminSidebarCollapsed', isCollapsed ? '1' : '0');
            } catch (e) {
                /* localStorage unavailable (private mode, etc.)  ignore */
            }
        });
    }
});
</script>
