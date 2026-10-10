<?php

$current_page = basename(
    (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH)
);


if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars(
            (string)$value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}


if (!function_exists('get_setting')) {
    function get_setting(
        mysqli $conn,
        string $key,
        string $default = ''
    ): string {
        $stmt = $conn->prepare("
            SELECT setting_value
            FROM site_settings
            WHERE setting_key = ?
            LIMIT 1
        ");

        if (!$stmt) {
            return $default;
        }

        $stmt->bind_param('s', $key);
        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;

        $stmt->close();

        return isset($row['setting_value'])
            ? (string)$row['setting_value']
            : $default;
    }
}

/**
 * Normalize a role key.
 */
if (!function_exists('sidebar_normalize_role')) {
    function sidebar_normalize_role(string $role): string
    {
        $role = strtolower(trim($role));
        $role = str_replace('-', '_', $role);
        $role = preg_replace('/[^a-z0-9_]/', '', $role);

        return trim((string)$role, '_');
    }
}

/**
 * Return possible role-key formats.
 */
if (!function_exists('sidebar_role_variants')) {
    function sidebar_role_variants(string $role): array
    {
        $role = sidebar_normalize_role($role);

        if ($role === '') {
            return [];
        }

        return array_values(
            array_unique([
                $role,
                str_replace('_', '-', $role),
                str_replace('_', '', $role),
            ])
        );
    }
}

/**
 * Get current logged-in role.
 */
if (!function_exists('sidebar_current_role')) {
    function sidebar_current_role(): string
    {
        $role = $_SESSION['admin_role']
            ?? $_SESSION['role']
            ?? '';

        return sidebar_normalize_role((string)$role);
    }
}

/**
 * Check whether the current user is super admin.
 */
if (!function_exists('sidebar_is_super')) {
    function sidebar_is_super(): bool
    {
        $role = sidebar_current_role();

        return in_array(
            $role,
            [
                'super_admin',
                'superadmin',
                'sup_admin',
            ],
            true
        );
    }
}

/**
 * Check page access from role_permissions.
 */
if (!function_exists('sidebar_can_access')) {
    function sidebar_can_access(
        mysqli $conn,
        string $pageKey
    ): bool {
        if (sidebar_is_super()) {
            return true;
        }

        $role = sidebar_current_role();

        if ($role === '' || trim($pageKey) === '') {
            return false;
        }

        /*
         * Use the main permission helper when available.
         */
        if (function_exists('admin_has_permission')) {
            return admin_has_permission($conn, $pageKey);
        }

        /*
         * Direct fallback lookup.
         */
        $variants = sidebar_role_variants($role);

        if (empty($variants)) {
            return false;
        }

        $placeholders = implode(
            ',',
            array_fill(0, count($variants), '?')
        );

        $sql = "
            SELECT can_access
            FROM role_permissions
            WHERE role_key IN ($placeholders)
              AND page_key = ?
            ORDER BY can_access DESC
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return false;
        }

        $params = $variants;
        $params[] = $pageKey;

        $types = str_repeat('s', count($params));

        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;

        $stmt->close();

        return isset($row['can_access'])
            && (int)$row['can_access'] === 1;
    }
}


if (!function_exists('sidebar_icon_class')) {
    function sidebar_icon_class(?string $icon): string
    {
        $fallback = 'fa-solid fa-circle';

        $icon = trim((string)$icon);

        if ($icon === '') {
            return $fallback;
        }

        /*
         * Remove HTML tags and unsafe characters.
         */
        $icon = strip_tags($icon);
        $icon = str_replace(',', ' ', $icon);
        $icon = preg_replace('/\s+/', ' ', $icon);
        $icon = trim((string)$icon);

        if ($icon === '') {
            return $fallback;
        }

        $rawClasses = explode(' ', $icon);
        $classes = [];

        foreach ($rawClasses as $class) {
            $class = preg_replace(
                '/[^a-zA-Z0-9_-]/',
                '',
                trim($class)
            );

            if ($class !== '') {
                $classes[] = $class;
            }
        }

        $classes = array_values(array_unique($classes));

        if (empty($classes)) {
            return $fallback;
        }

        $styleClasses = [
            'fa',
            'fas',
            'far',
            'fab',
            'fal',
            'fad',
            'fass',
            'fasr',
            'fasl',
            'fat',
            'fa-solid',
            'fa-regular',
            'fa-brands',
            'fa-light',
            'fa-thin',
            'fa-duotone',
            'fa-sharp',
        ];

     
        $styleAliasMap = [
            'fas' => 'fa-solid',
            'far' => 'fa-regular',
            'fab' => 'fa-brands',
            'fal' => 'fa-light',
            'fad' => 'fa-duotone',
        ];

        $resolvedStyle = null;
        $iconClasses = [];

        foreach ($classes as $class) {
            if (in_array($class, $styleClasses, true)) {
                if ($class !== 'fa') {
                    $resolvedStyle = $styleAliasMap[$class] ?? $class;
                }
                continue;
            }

            if (str_starts_with($class, 'fa-')) {
                $iconClasses[] = $class;
            }
        }

      
        if (
            empty($iconClasses)
            && count($classes) === 1
            && $resolvedStyle === null
            && !in_array($classes[0], $styleClasses, true)
        ) {
            $iconName = $classes[0];

            if (!str_starts_with($iconName, 'fa-')) {
                $iconName = 'fa-' . $iconName;
            }

            $iconClasses[] = $iconName;
        }

        if (empty($iconClasses)) {
            return $fallback;
        }

    
        static $legacyIconMap = null;

        if ($legacyIconMap === null) {
            $legacyIconMap = [
                'area-chart' => 'chart-area',
                'arrow-circle-o-down' => 'circle-down',
                'arrow-circle-o-left' => 'circle-left',
                'arrow-circle-o-right' => 'circle-right',
                'arrow-circle-o-up' => 'circle-up',
                'arrows' => 'up-down-left-right',
                'arrows-alt' => 'maximize',
                'arrows-h' => 'left-right',
                'arrows-v' => 'up-down',
                'asl-interpreting' => 'hands-asl-interpreting',
                'automobile' => 'car',
                'bank' => 'building-columns',
                'bar-chart' => 'chart-column',
                'bar-chart-o' => 'chart-column',
                'bathtub' => 'bath',
                'battery' => 'battery-full',
                'battery-0' => 'battery-empty',
                'battery-1' => 'battery-quarter',
                'battery-2' => 'battery-half',
                'battery-3' => 'battery-three-quarters',
                'battery-4' => 'battery-full',
                'behance-square' => 'square-behance',
                'bitbucket-square' => 'bitbucket',
                'bitcoin' => 'btc',
                'cab' => 'taxi',
                'calendar' => 'calendar-days',
                'calendar-times-o' => 'calendar-xmark',
                'caret-square-o-down' => 'square-caret-down',
                'caret-square-o-left' => 'square-caret-left',
                'caret-square-o-right' => 'square-caret-right',
                'caret-square-o-up' => 'square-caret-up',
                'cc' => 'closed-captioning',
                'chain' => 'link',
                'chain-broken' => 'link-slash',
                'check-circle-o' => 'circle-check',
                'check-square-o' => 'square-check',
                'circle-o-notch' => 'circle-notch',
                'circle-thin' => 'circle',
                'clipboard' => 'paste',
                'close' => 'xmark',
                'cloud-download' => 'cloud-arrow-down',
                'cloud-upload' => 'cloud-arrow-up',
                'cny' => 'yen-sign',
                'code-fork' => 'code-branch',
                'commenting' => 'comment-dots',
                'commenting-o' => 'comment-dots',
                'compress' => 'down-left-and-up-right-to-center',
                'credit-card-alt' => 'credit-card',
                'cut' => 'scissors',
                'cutlery' => 'utensils',
                'dashboard' => 'gauge-high',
                'deafness' => 'ear-deaf',
                'dedent' => 'outdent',
                'diamond' => 'gem',
                'dollar' => 'dollar-sign',
                'dot-circle-o' => 'circle-dot',
                'drivers-license' => 'id-card',
                'drivers-license-o' => 'id-card',
                'edit' => 'pen-to-square',
                'eercast' => 'sellcast',
                'eur' => 'euro-sign',
                'euro' => 'euro-sign',
                'exchange' => 'right-left',
                'expand' => 'up-right-and-down-left-from-center',
                'external-link' => 'up-right-from-square',
                'external-link-square' => 'square-up-right',
                'eyedropper' => 'eye-dropper',
                'facebook' => 'facebook-f',
                'facebook-official' => 'facebook',
                'facebook-square' => 'square-facebook',
                'feed' => 'rss',
                'file-archive-o' => 'file-zipper',
                'file-movie-o' => 'file-video',
                'file-photo-o' => 'file-image',
                'file-picture-o' => 'file-image',
                'file-sound-o' => 'file-audio',
                'file-text' => 'file-lines',
                'file-text-o' => 'file-lines',
                'file-zip-o' => 'file-zipper',
                'files-o' => 'copy',
                'flash' => 'bolt',
                'floppy-o' => 'floppy-disk',
                'frown-o' => 'face-frown',
                'gbp' => 'sterling-sign',
                'ge' => 'empire',
                'git-square' => 'square-git',
                'github-square' => 'square-github',
                'gittip' => 'gratipay',
                'glass' => 'martini-glass-empty',
                'globe' => 'earth-americas',
                'google-plus' => 'google-plus-g',
                'google-plus-circle' => 'google-plus',
                'google-plus-official' => 'google-plus',
                'google-plus-square' => 'square-google-plus',
                'group' => 'users',
                'hand-grab-o' => 'hand-back-fist',
                'hand-o-down' => 'hand-point-down',
                'hand-o-left' => 'hand-point-left',
                'hand-o-right' => 'hand-point-right',
                'hand-o-up' => 'hand-point-up',
                'hand-paper-o' => 'hand',
                'hand-rock-o' => 'hand-back-fist',
                'hand-stop-o' => 'hand',
                'hard-of-hearing' => 'ear-deaf',
                'hdd-o' => 'hard-drive',
                'header' => 'heading',
                'home' => 'house',
                'hotel' => 'bed',
                'hourglass-1' => 'hourglass-start',
                'hourglass-2' => 'hourglass-half',
                'hourglass-3' => 'hourglass-end',
                'hourglass-o' => 'hourglass',
                'ils' => 'shekel-sign',
                'inr' => 'indian-rupee-sign',
                'institution' => 'building-columns',
                'intersex' => 'mars-and-venus',
                'jpy' => 'yen-sign',
                'krw' => 'won-sign',
                'lastfm-square' => 'square-lastfm',
                'legal' => 'gavel',
                'level-down' => 'turn-down',
                'level-up' => 'turn-up',
                'life-bouy' => 'life-ring',
                'life-buoy' => 'life-ring',
                'life-saver' => 'life-ring',
                'line-chart' => 'chart-line',
                'linkedin' => 'linkedin-in',
                'linkedin-square' => 'linkedin',
                'list-alt' => 'rectangle-list',
                'long-arrow-down' => 'down-long',
                'long-arrow-left' => 'left-long',
                'long-arrow-right' => 'right-long',
                'long-arrow-up' => 'up-long',
                'magic' => 'wand-magic-sparkles',
                'mail-forward' => 'share',
                'mail-reply' => 'reply',
                'mail-reply-all' => 'reply-all',
                'map-marker' => 'location-dot',
                'meh-o' => 'face-meh',
                'minus-square-o' => 'square-minus',
                'mobile' => 'mobile-screen-button',
                'mobile-phone' => 'mobile-screen-button',
                'money' => 'money-bill-1',
                'mortar-board' => 'graduation-cap',
                'navicon' => 'bars',
                'odnoklassniki-square' => 'square-odnoklassniki',
                'pause-circle-o' => 'circle-pause',
                'pencil-square' => 'square-pen',
                'pencil-square-o' => 'pen-to-square',
                'photo' => 'image',
                'picture-o' => 'image',
                'pie-chart' => 'chart-pie',
                'pinterest-square' => 'square-pinterest',
                'play-circle-o' => 'circle-play',
                'plus-square-o' => 'square-plus',
                'question-circle-o' => 'circle-question',
                'ra' => 'rebel',
                'reddit-square' => 'square-reddit',
                'refresh' => 'arrows-rotate',
                'remove' => 'xmark',
                'reorder' => 'bars',
                'repeat' => 'arrow-rotate-right',
                'resistance' => 'rebel',
                'rmb' => 'yen-sign',
                'rotate-left' => 'arrow-rotate-left',
                'rotate-right' => 'arrow-rotate-right',
                'rouble' => 'ruble-sign',
                'rub' => 'ruble-sign',
                'ruble' => 'ruble-sign',
                'rupee' => 'indian-rupee-sign',
                's15' => 'bath',
                'save' => 'floppy-disk',
                'send' => 'paper-plane',
                'send-o' => 'paper-plane',
                'share-square-o' => 'share-from-square',
                'shekel' => 'shekel-sign',
                'sheqel' => 'shekel-sign',
                'sign-in' => 'right-to-bracket',
                'sign-out' => 'right-from-bracket',
                'signing' => 'hands',
                'smile-o' => 'face-smile',
                'snapchat-ghost' => 'snapchat',
                'snapchat-square' => 'square-snapchat',
                'soccer-ball-o' => 'futbol',
                'sort-alpha-asc' => 'arrow-down-a-z',
                'sort-alpha-desc' => 'arrow-down-z-a',
                'sort-amount-asc' => 'arrow-down-short-wide',
                'sort-amount-desc' => 'arrow-down-wide-short',
                'sort-asc' => 'sort-up',
                'sort-desc' => 'sort-down',
                'sort-numeric-asc' => 'arrow-down-1-9',
                'sort-numeric-desc' => 'arrow-down-9-1',
                'star-half-empty' => 'star-half-stroke',
                'star-half-full' => 'star-half-stroke',
                'star-half-o' => 'star-half-stroke',
                'steam-square' => 'square-steam',
                'sticky-note-o' => 'note-sticky',
                'stop-circle-o' => 'circle-stop',
                'support' => 'life-ring',
                'tablet' => 'tablet-screen-button',
                'tachometer' => 'gauge-high',
                'tasks' => 'bars-progress',
                'television' => 'tv',
                'thermometer' => 'temperature-full',
                'thermometer-0' => 'temperature-empty',
                'thermometer-1' => 'temperature-quarter',
                'thermometer-2' => 'temperature-half',
                'thermometer-3' => 'temperature-three-quarters',
                'thermometer-4' => 'temperature-full',
                'thumb-tack' => 'thumbtack',
                'thumbs-o-down' => 'thumbs-down',
                'thumbs-o-up' => 'thumbs-up',
                'times-circle-o' => 'circle-xmark',
                'times-rectangle' => 'rectangle-xmark',
                'times-rectangle-o' => 'rectangle-xmark',
                'toggle-down' => 'square-caret-down',
                'toggle-left' => 'square-caret-left',
                'toggle-right' => 'square-caret-right',
                'toggle-up' => 'square-caret-up',
                'transgender' => 'mars-and-venus',
                'transgender-alt' => 'transgender',
                'trash' => 'trash-can',
                'trash-o' => 'trash-can',
                'try' => 'turkish-lira-sign',
                'tumblr-square' => 'square-tumblr',
                'turkish-lira' => 'turkish-lira-sign',
                'twitter-square' => 'square-twitter',
                'unlink' => 'link-slash',
                'unlock-alt' => 'unlock',
                'unsorted' => 'sort',
                'usd' => 'dollar-sign',
                'user-circle-o' => 'circle-user',
                'vcard' => 'address-card',
                'vcard-o' => 'address-card',
                'viadeo-square' => 'square-viadeo',
                'video-camera' => 'video',
                'vimeo' => 'vimeo-v',
                'vimeo-square' => 'square-vimeo',
                'volume-control-phone' => 'phone-volume',
                'warning' => 'triangle-exclamation',
                'wechat' => 'weixin',
                'wheelchair-alt' => 'accessible-icon',
                'window-close-o' => 'rectangle-xmark',
                'won' => 'won-sign',
                'xing-square' => 'square-xing',
                'y-combinator-square' => 'hacker-news',
                'yc' => 'y-combinator',
                'yc-square' => 'hacker-news',
                'yen' => 'yen-sign',
                'youtube-play' => 'youtube',
                'youtube-square' => 'square-youtube',
            ];
        }

        $iconClasses = array_map(
            function (string $class) use ($legacyIconMap): string {
                $bareName = preg_replace('/^fa-/', '', $class);
                return isset($legacyIconMap[$bareName])
                    ? 'fa-' . $legacyIconMap[$bareName]
                    : $class;
            },
            $iconClasses
        );

        if ($resolvedStyle === null) {
            $resolvedStyle = 'fa-solid';
        }

        return implode(
            ' ',
            array_values(array_unique(array_merge(
                ['fa', $resolvedStyle],
                $iconClasses
            )))
        );
    }
}


if (!function_exists('sidebar_pages')) {
    function sidebar_pages(mysqli $conn): array
    {
        $groups = [];

      
        $sql = "
            SELECT
                page_key,
                page_title,
                page_url,
                group_name,
                icon,
                section_order,
                sort_order
            FROM admin_pages
            WHERE is_active = 1
            ORDER BY
                CASE
                    WHEN section_order IS NULL
                         OR section_order <= 0
                    THEN 999999
                    ELSE section_order
                END ASC,

                CASE
                    WHEN sort_order IS NULL
                         OR sort_order < 0
                    THEN 999999
                    ELSE sort_order
                END ASC,

                page_title ASC
        ";

        $result = $conn->query($sql);

        if (!$result) {
            return [];
        }

        while ($page = $result->fetch_assoc()) {
            $pageKey = trim(
                (string)($page['page_key'] ?? '')
            );

            if ($pageKey === '') {
                continue;
            }

            if (!sidebar_can_access($conn, $pageKey)) {
                continue;
            }

            $groupName = trim(
                (string)($page['group_name'] ?? '')
            );

            if ($groupName === '') {
                $groupName = 'General';
            }

            if (!isset($groups[$groupName])) {
                $groups[$groupName] = [];
            }

            $groups[$groupName][] = $page;
        }

        $result->free();

        return $groups;
    }
}


if (!function_exists('sidebar_is_active_url')) {
    function sidebar_is_active_url(
        string $pageUrl,
        string $currentPage
    ): bool {
        $pagePath = parse_url($pageUrl, PHP_URL_PATH);
        $pageFile = basename((string)$pagePath);

        if ($pageFile === '' || $currentPage === '') {
            return false;
        }

        return strtolower($pageFile)
            === strtolower($currentPage);
    }
}


if (!function_exists('sidebar_asset_url')) {
    function sidebar_asset_url(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return rtrim((string)SITE_URL, '/')
            . '/'
            . ltrim($path, '/');
    }
}


if (!function_exists('sidebar_logo_exists')) {
    function sidebar_logo_exists(string $logo): bool
    {
        $logo = trim($logo);

        if ($logo === '') {
            return false;
        }

        if (preg_match('#^https?://#i', $logo)) {
            return true;
        }

        $cleanPath = ltrim(
            str_replace(
                ['/', '\\'],
                DIRECTORY_SEPARATOR,
                $logo
            ),
            DIRECTORY_SEPARATOR
        );

        $possiblePaths = [
            dirname(__DIR__, 2)
                . DIRECTORY_SEPARATOR
                . $cleanPath,

            dirname(__DIR__)
                . DIRECTORY_SEPARATOR
                . $cleanPath,

            __DIR__
                . DIRECTORY_SEPARATOR
                . $cleanPath,
        ];

        foreach ($possiblePaths as $path) {
            if (is_file($path)) {
                return true;
            }
        }

        return false;
    }
}

$site_logo = get_setting(
    $conn,
    'site_logo',
    ''
);

$site_name = get_setting(
    $conn,
    'site_name',
    'EdTech'
);

$site_subtitle = get_setting(
    $conn,
    'site_subtitle',
    ''
);

$menu_groups = sidebar_pages($conn);
$display_logo = sidebar_logo_exists($site_logo);
?>

<aside
    class="admin-sidebar"
    id="adminSidebar"
>

    <div class="sidebar-brand">

        <div class="brand-logo">

            <?php if ($display_logo): ?>

                <img
                    src="<?= h(sidebar_asset_url($site_logo)) ?>"
                    alt="<?= h($site_name) ?>"
                    class="sidebar-site-logo"
                >

            <?php else: ?>

                <div class="sidebar-logo-placeholder">
                    <i
                        class="fa-solid fa-graduation-cap"
                        aria-hidden="true"
                    ></i>
                </div>

            <?php endif; ?>

        </div>

        <div class="brand-text">

            <span class="brand-name">
                <?= h($site_name) ?>
            </span>

            <span class="brand-sub">
                <?= h($site_subtitle) ?>
            </span>

        </div>

        <button
            class="sidebar-toggle-btn"
            id="sidebarToggle"
            type="button"
            aria-label="Toggle sidebar"
            aria-controls="adminSidebar"
            aria-expanded="true"
        >
            <i
                class="fa-solid fa-bars"
                aria-hidden="true"
            ></i>
        </button>

    </div>

    <nav
        class="sidebar-nav"
        aria-label="Administration navigation"
    >

        <?php if (!empty($menu_groups)): ?>

            <?php foreach ($menu_groups as $groupName => $items): ?>

                <?php
                if (empty($items)) {
                    continue;
                }
                ?>

                <?php
                $groupId = 'nav-group-' . substr(sha1((string)$groupName), 0, 10);
                $groupIsActive = false;
                foreach ($items as $groupItem) {
                    if (sidebar_is_active_url((string)($groupItem['page_url'] ?? ''), $current_page)) {
                        $groupIsActive = true;
                        break;
                    }
                }
                ?>
                <details class="nav-group" <?= $groupIsActive ? 'open' : '' ?>>
                    <summary class="nav-section-label" aria-controls="<?= h($groupId) ?>">
                        <span><?= h($groupName) ?></span>
                        <i class="fa-solid fa-chevron-down nav-group-chevron" aria-hidden="true"></i>
                    </summary>

                <ul class="nav-list" id="<?= h($groupId) ?>">

                    <?php foreach ($items as $item): ?>

                        <?php
                        $pageUrl = trim(
                            (string)($item['page_url'] ?? '')
                        );

                        $pageTitle = trim(
                            (string)($item['page_title'] ?? '')
                        );

                        if (
                            $pageUrl === ''
                            || $pageTitle === ''
                        ) {
                            continue;
                        }

                        $iconClass = sidebar_icon_class(
                            $item['icon'] ?? ''
                        );

                        $isActive = sidebar_is_active_url(
                            $pageUrl,
                            $current_page
                        );
                        ?>

                        <li
                            class="nav-item<?= $isActive ? ' active' : '' ?>"
                        >

                            <a
                                href="<?= h($pageUrl) ?>"
                                <?= $isActive ? 'aria-current="page"' : '' ?>
                            >

                                <i
                                    class="<?= h($iconClass) ?>"
                                    aria-hidden="true"
                                ></i>

                                <span>
                                    <?= h($pageTitle) ?>
                                </span>

                            </a>

                        </li>

                    <?php endforeach; ?>

                </ul>
                </details>

            <?php endforeach; ?>

        <?php else: ?>

            <div class="nav-section-label">
                Navigation
            </div>

            <ul class="nav-list">

                <li class="nav-item">

                    <span class="nav-empty">
                        No accessible pages found.
                    </span>

                </li>

            </ul>

        <?php endif; ?>

        <details class="nav-group nav-account">
            <summary class="nav-section-label" aria-controls="nav-account-list">
                <span>Account</span>
                <i class="fa-solid fa-chevron-down nav-group-chevron" aria-hidden="true"></i>
            </summary>

        <ul class="nav-list" id="nav-account-list">

            <li class="nav-item">

                <form method="POST" action="logout.php">
                <button type="submit"
                    class="logout-link"
                >

                    <i
                        class="fa-solid fa-right-from-bracket"
                        aria-hidden="true"
                    ></i>

                    <span>
                        Logout
                    </span>

                </button>
                </form>

            </li>

        </ul>
        </details>

    </nav>

    <div class="sidebar-footer">

        <a
            href="<?= h((string)SITE_URL) ?>"
            target="_blank"
            rel="noopener noreferrer"
        >

            <i
                class="fa-solid fa-arrow-up-right-from-square"
                aria-hidden="true"
            ></i>

            <span>
                View Website
            </span>

        </a>

    </div>

</aside>

<!--
    Mobile off-canvas backdrop. Sits outside the <aside> so its fixed
    positioning is never affected by the sidebar's own transform/overflow,
    and so it can capture a tap-to-close without any extra JS lookups.
-->
<div
    class="sidebar-overlay"
    id="sidebarOverlay"
    aria-hidden="true"
></div>

<!--
    Mobile menu toggle. Deliberately placed here (not in each page's own
    topbar markup) because sidebar.php is the one file every admin page
    actually includes � a page's hand-rolled <header class="admin-topbar">
    has no guarantee of containing this button itself. It's positioned as
    a fixed overlay via .sidebar-floating-toggle in admin.css so it works
    the same way regardless of what a given page's topbar looks like.
-->
<button
    type="button"
    class="topbar-mobile-toggle sidebar-floating-toggle"
    id="topbarMobileToggle"
    aria-label="Open navigation"
    aria-controls="adminSidebar"
    aria-expanded="false"
>
    <i
        class="fa-solid fa-bars"
        aria-hidden="true"
    ></i>
</button>

<script>
/*
 * Mobile off-canvas open/close only. Deliberately NOT handling the
 * desktop #sidebarToggle collapse button here � individual pages already
 * wire that up themselves, and binding it again here would double-toggle
 * (each click fires both handlers, so the class flips twice and appears
 * to do nothing).
 */
document.addEventListener('DOMContentLoaded', function () {
    var sidebar   = document.getElementById('adminSidebar');
    var overlay   = document.getElementById('sidebarOverlay');
    var mobileBtn = document.getElementById('topbarMobileToggle');
    var body      = document.body;

    if (!sidebar || !overlay || !mobileBtn) {
        return;
    }

    var mobileQuery = window.matchMedia('(max-width: 900px)');

    function openMobileSidebar() {
        sidebar.classList.add('open');
        overlay.classList.add('open');
        body.classList.add('sidebar-open');
        body.style.overflow = 'hidden';
        mobileBtn.setAttribute('aria-expanded', 'true');
    }

    function closeMobileSidebar() {
        sidebar.classList.remove('open');
        overlay.classList.remove('open');
        body.classList.remove('sidebar-open');
        body.style.overflow = '';
        mobileBtn.setAttribute('aria-expanded', 'false');
    }

    function toggleMobileSidebar() {
        if (sidebar.classList.contains('open')) {
            closeMobileSidebar();
        } else {
            openMobileSidebar();
        }
    }

    mobileBtn.addEventListener('click', toggleMobileSidebar);
    overlay.addEventListener('click', closeMobileSidebar);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sidebar.classList.contains('open')) {
            closeMobileSidebar();
        }
    });

    var navLinks = sidebar.querySelectorAll('.nav-item a');
    for (var i = 0; i < navLinks.length; i++) {
        navLinks[i].addEventListener('click', function () {
            if (mobileQuery.matches) {
                closeMobileSidebar();
            }
        });
    }

    function handleBreakpointChange(e) {
        if (!e.matches) {
            closeMobileSidebar();
        }
    }
    if (typeof mobileQuery.addEventListener === 'function') {
        mobileQuery.addEventListener('change', handleBreakpointChange);
    } else if (typeof mobileQuery.addListener === 'function') {
        mobileQuery.addListener(handleBreakpointChange);
    }
});
</script>
