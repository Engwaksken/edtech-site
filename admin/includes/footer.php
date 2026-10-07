<?php


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

if (!function_exists('admin_footer_asset_url')) {
    function admin_footer_asset_url(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        if (
            preg_match('#^(https?:)?//#i', $path)
            || str_starts_with($path, '/')
            || str_starts_with($path, '../')
            || str_starts_with($path, './')
        ) {
            return $path;
        }

        return $path;
    }
}


if (!function_exists('admin_footer_script_attributes')) {
    function admin_footer_script_attributes(
        array $attributes
    ): string {
        $allowed = [
            'async',
            'defer',
            'type',
            'integrity',
            'crossorigin',
            'referrerpolicy',
        ];

        $output = [];

        foreach ($attributes as $name => $value) {
            $name = strtolower(trim((string)$name));

            if (!in_array($name, $allowed, true)) {
                continue;
            }

            if (in_array($name, ['async', 'defer'], true)) {
                if ($value) {
                    $output[] = $name;
                }

                continue;
            }

            $value = trim((string)$value);

            if ($value !== '') {
                $output[] = $name . '="' . h($value) . '"';
            }
        }

        return implode(' ', $output);
    }
}



$extra_js = isset($extra_js) && is_array($extra_js)
    ? $extra_js
    : [];

$inline_js = isset($inline_js)
    ? trim((string)$inline_js)
    : '';

$footer_html = isset($footer_html)
    ? (string)$footer_html
    : '';


?>

    </main>

</div>

<?php if ($footer_html !== ''): ?>

    <?= $footer_html ?>

<?php endif; ?>

<!-- Mobile sidebar overlay -->
<div
    class="sidebar-overlay"
    id="sidebarOverlay"
    aria-hidden="true"
></div>

<!-- Shared admin JavaScript -->
<script src="assets/js/admin.js"></script>

<?php


foreach ($extra_js as $script):
    $scriptUrl = '';
    $attributes = [];

    if (is_string($script)) {
        $scriptUrl = admin_footer_asset_url($script);
    } elseif (is_array($script)) {
        $scriptUrl = admin_footer_asset_url(
            (string)($script['src'] ?? '')
        );

        $attributes = [
            'async' => !empty($script['async']),
            'defer' => !empty($script['defer']),
            'type' => $script['type'] ?? '',
            'integrity' => $script['integrity'] ?? '',
            'crossorigin' => $script['crossorigin'] ?? '',
            'referrerpolicy' => $script['referrerpolicy'] ?? '',
        ];
    }

    if ($scriptUrl === '') {
        continue;
    }

    $attributeString = admin_footer_script_attributes(
        $attributes
    );
    ?>

    <script
        src="<?= h($scriptUrl) ?>"
        <?= $attributeString ?>
    ></script>

<?php endforeach; ?>

<?php if ($inline_js !== ''): ?>

    <script>
        <?= $inline_js ?>
    </script>

<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('adminSidebar');
    const adminMain = document.getElementById('adminMain');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    const sidebarToggle = document.getElementById('sidebarToggle');
    const topbarMobileToggle = document.getElementById(
        'topbarMobileToggle'
    );

    /**
     * Determine whether the browser is using the mobile layout.
     */
    function isMobileSidebar() {
        return window.matchMedia('(max-width: 991px)').matches;
    }

    /**
     * Set accessibility state for sidebar toggle buttons.
     */
    function updateToggleState(isOpen) {
        const expanded = isOpen ? 'true' : 'false';

        if (sidebarToggle) {
            sidebarToggle.setAttribute(
                'aria-expanded',
                expanded
            );
        }

        if (topbarMobileToggle) {
            topbarMobileToggle.setAttribute(
                'aria-expanded',
                expanded
            );
        }

        if (sidebarOverlay) {
            sidebarOverlay.setAttribute(
                'aria-hidden',
                isOpen ? 'false' : 'true'
            );
        }
    }

    /**
     * Open sidebar on mobile.
     */
    function openMobileSidebar() {
        if (!sidebar) {
            return;
        }

        sidebar.classList.add('mobile-open');

        if (sidebarOverlay) {
            sidebarOverlay.classList.add('active');
        }

        document.body.classList.add('sidebar-open');

        updateToggleState(true);
    }

    /**
     * Close sidebar on mobile.
     */
    function closeMobileSidebar() {
        if (!sidebar) {
            return;
        }

        sidebar.classList.remove('mobile-open');

        if (sidebarOverlay) {
            sidebarOverlay.classList.remove('active');
        }

        document.body.classList.remove('sidebar-open');

        updateToggleState(false);
    }

    /**
     * Toggle collapsed desktop sidebar.
     */
    function toggleDesktopSidebar() {
        if (!sidebar) {
            return;
        }

        const collapsed = sidebar.classList.toggle(
            'collapsed'
        );

        if (adminMain) {
            adminMain.classList.toggle(
                'sidebar-collapsed',
                collapsed
            );
        }

        try {
            localStorage.setItem(
                'adminSidebarCollapsed',
                collapsed ? '1' : '0'
            );
        } catch (error) {
            // Ignore localStorage restrictions.
        }

        updateToggleState(!collapsed);
    }

    /**
     * Toggle mobile or desktop sidebar.
     */
    function toggleSidebar() {
        if (!sidebar) {
            return;
        }

        if (isMobileSidebar()) {
            if (sidebar.classList.contains('mobile-open')) {
                closeMobileSidebar();
            } else {
                openMobileSidebar();
            }

            return;
        }

        toggleDesktopSidebar();
    }

    /**
     * Restore the saved desktop sidebar state.
     */
    function restoreDesktopSidebarState() {
        if (!sidebar || isMobileSidebar()) {
            return;
        }

        let collapsed = false;

        try {
            collapsed =
                localStorage.getItem(
                    'adminSidebarCollapsed'
                ) === '1';
        } catch (error) {
            collapsed = false;
        }

        sidebar.classList.toggle(
            'collapsed',
            collapsed
        );

        if (adminMain) {
            adminMain.classList.toggle(
                'sidebar-collapsed',
                collapsed
            );
        }

        updateToggleState(!collapsed);
    }

    if (sidebarToggle) {
        sidebarToggle.addEventListener(
            'click',
            toggleSidebar
        );
    }

    if (topbarMobileToggle) {
        topbarMobileToggle.addEventListener(
            'click',
            toggleSidebar
        );
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener(
            'click',
            closeMobileSidebar
        );
    }

    /**
     * Close mobile sidebar after selecting a menu item.
     */
    if (sidebar) {
        const sidebarLinks = sidebar.querySelectorAll(
            '.sidebar-nav a'
        );

        sidebarLinks.forEach(function (link) {
            link.addEventListener('click', function () {
                if (isMobileSidebar()) {
                    closeMobileSidebar();
                }
            });
        });
    }

    /**
     * Close mobile sidebar when Escape is pressed.
     */
    document.addEventListener('keydown', function (event) {
        if (
            event.key === 'Escape'
            && sidebar
            && sidebar.classList.contains('mobile-open')
        ) {
            closeMobileSidebar();
        }
    });

    
    window.addEventListener('resize', function () {
        if (!sidebar) {
            return;
        }

        if (isMobileSidebar()) {
            sidebar.classList.remove('collapsed');

            if (adminMain) {
                adminMain.classList.remove(
                    'sidebar-collapsed'
                );
            }

            updateToggleState(
                sidebar.classList.contains('mobile-open')
            );
        } else {
            closeMobileSidebar();
            restoreDesktopSidebarState();
        }
    });

    restoreDesktopSidebarState();
});
</script>

</body>
</html>
