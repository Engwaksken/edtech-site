<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/loa-template-functions.php';

$page_title  = 'LoA Template';
$current_nav = 'loa-template.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database connection error.');
}

$conn->set_charset('utf8mb4');

// All dates/times display and store as Africa/Nairobi (EAT, UTC+3).
date_default_timezone_set('Africa/Nairobi');
$conn->query("SET time_zone = '+03:00'");

$active   = get_active_loa_template($conn, false);
$versions = list_loa_templates($conn);

/**
 * Safely format a database date.
 */
function format_loa_date(?string $date): string
{
    if ($date === null || trim($date) === '') {
        return 'Not available';
    }

    try {
        return (new DateTime($date))->format('j M Y, g:i a');
    } catch (Throwable $e) {
        return $date;
    }
}

/**
 * Format file size in kilobytes.
 */
function format_loa_file_size(mixed $size): string
{
    $bytes = is_numeric($size) ? (int)$size : 0;

    return number_format($bytes / 1024, 1) . ' KB';
}

$defaultTab = 'current-template';

if (isset($_GET['uploaded'])) {
    $defaultTab = 'current-template';
} elseif (isset($_GET['activated'])) {
    $defaultTab = 'current-template';
} elseif (isset($_GET['tab'])) {
    $requestedTab = trim((string)$_GET['tab']);

    $allowedTabs = [
        'current-template',
        'upload-template',
        'version-history',
    ];

    if (in_array($requestedTab, $allowedTabs, true)) {
        $defaultTab = $requestedTab;
    }
}

$activeIsPdf = $active ? loa_template_is_pdf((string)($active['mime_type'] ?? '')) : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title><?= h($page_title) ?> - Admin</title>

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

    <link
        rel="stylesheet"
        href="assets/css/admin.css"
    >

    
</head>

<body>

<?php include __DIR__ . '/includes/sidebar.php'; ?>

<div
    class="admin-main"
    id="adminMain"
>
    <header class="admin-topbar">
        <div class="topbar-left">
            <div class="topbar-breadcrumb">
                <a href="index.php">Dashboard</a>
                &gt;
                <strong>LoA Template</strong>
            </div>
        </div>

        <div class="topbar-right">
            <a
                href="<?= h((string)SITE_URL) ?>"
                target="_blank"
                rel="noopener noreferrer"
                class="btn btn-secondary btn-sm"
            >
                <i class="fa-solid fa-eye"></i>
                View Site
            </a>

            <div class="admin-avatar">
                <div class="avatar-circle">
                    <?= h(
                        strtoupper(
                            substr(
                                (string)(
                                    $ADMIN['full_name']
                                    ?? $ADMIN['email']
                                    ?? 'A'
                                ),
                                0,
                                1
                            )
                        )
                    ) ?>
                </div>
            </div>
        </div>
    </header>

    <main class="admin-content">

        <div class="loa-page-header">
            <div>
                <h1 class="loa-page-title">
                    LoA Template
                </h1>

                <p class="loa-page-subtitle">
                    Manage the document used to generate each venture's
                    Letter of Agreement.
                </p>
            </div>
        </div>

        <?php if (isset($_GET['uploaded'])): ?>
            <div class="loa-alert loa-alert-success">
                <i class="fa-solid fa-circle-check"></i>

                <div>
                    New template version uploaded and activated successfully.
                </div>
            </div>
        <?php elseif (isset($_GET['activated'])): ?>
            <div class="loa-alert loa-alert-success">
                <i class="fa-solid fa-circle-check"></i>

                <div>
                    Template version activated successfully.
                </div>
            </div>
        <?php elseif (isset($_GET['error'])): ?>
            <div class="loa-alert loa-alert-error">
                <i class="fa-solid fa-circle-exclamation"></i>

                <div>
                    <?= h((string)$_GET['error']) ?>
                </div>
            </div>
        <?php endif; ?>

       

        <section
            class="loa-tabs-wrapper"
            data-default-tab="<?= h($defaultTab) ?>"
        >
            <div
                class="loa-tabs-nav"
                role="tablist"
                aria-label="LoA template sections"
            >
                <button
                    type="button"
                    class="loa-tab-button<?= $defaultTab === 'current-template' ? ' active' : '' ?>"
                    id="current-template-tab"
                    data-tab-target="current-template"
                    role="tab"
                    aria-controls="current-template"
                    aria-selected="<?= $defaultTab === 'current-template' ? 'true' : 'false' ?>"
                >
                    <i class="fa-solid fa-file"></i>
                    Current Template
                </button>

                <button
                    type="button"
                    class="loa-tab-button<?= $defaultTab === 'upload-template' ? ' active' : '' ?>"
                    id="upload-template-tab"
                    data-tab-target="upload-template"
                    role="tab"
                    aria-controls="upload-template"
                    aria-selected="<?= $defaultTab === 'upload-template' ? 'true' : 'false' ?>"
                >
                    <i class="fa-solid fa-cloud-arrow-up"></i>
                    Upload New Version
                </button>

                <button
                    type="button"
                    class="loa-tab-button<?= $defaultTab === 'version-history' ? ' active' : '' ?>"
                    id="version-history-tab"
                    data-tab-target="version-history"
                    role="tab"
                    aria-controls="version-history"
                    aria-selected="<?= $defaultTab === 'version-history' ? 'true' : 'false' ?>"
                >
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    Version History

                    <span class="loa-tab-count">
                        <?= count($versions) ?>
                    </span>
                </button>
            </div>

            <!-- Current template tab -->
            <div
                class="loa-tab-panel<?= $defaultTab === 'current-template' ? ' active' : '' ?>"
                id="current-template"
                role="tabpanel"
                aria-labelledby="current-template-tab"
                <?= $defaultTab === 'current-template' ? '' : 'hidden' ?>
            >
                <div class="loa-section-header">
                    <div>
                        <h2 class="loa-section-title">
                            Current Active Template
                        </h2>

                        <p class="loa-section-description">
                            This template is currently used when generating new
                            Letters of Agreement.
                        </p>
                    </div>
                </div>

                <?php if ($active): ?>
                    <?php $activeMime = (string)($active['mime_type'] ?? ''); ?>
                    <div class="loa-active-template">
                        <div class="loa-template-summary">
                            <div class="loa-template-heading">
                                <i class="<?= h(loa_template_icon_class($activeMime)) ?> loa-file-type-icon"></i>

                                <h3>
                                    <?= h((string)$active['name']) ?>
                                </h3>

                                <span class="loa-version-badge">
                                    Version <?= (int)$active['version'] ?>
                                </span>

                                <span class="loa-filetype-badge <?= loa_template_is_pdf($activeMime) ? 'pdf' : 'docx' ?>">
                                    <?= loa_template_is_pdf($activeMime) ? 'PDF' : 'Word (.docx)' ?>
                                </span>

                                <span class="loa-active-badge">
                                    <i class="fa-solid fa-circle-check"></i>
                                    Active
                                </span>
                            </div>

                            <div class="loa-template-details">
                                <div class="loa-detail-item">
                                    <span class="loa-detail-label">
                                        File name
                                    </span>

                                    <span class="loa-detail-value">
                                        <?= h((string)$active['file_name']) ?>
                                    </span>
                                </div>

                                <div class="loa-detail-item">
                                    <span class="loa-detail-label">
                                        File size
                                    </span>

                                    <span class="loa-detail-value">
                                        <?= h(
                                            format_loa_file_size(
                                                $active['file_size'] ?? 0
                                            )
                                        ) ?>
                                    </span>
                                </div>

                                <div class="loa-detail-item">
                                    <span class="loa-detail-label">
                                        Uploaded
                                    </span>

                                    <span class="loa-detail-value">
                                        <?= h(
                                            format_loa_date(
                                                isset($active['created_at'])
                                                    ? (string)$active['created_at']
                                                    : null
                                            )
                                        ) ?>
                                    </span>
                                </div>
                            </div>

                            <?php if (loa_template_is_pdf($activeMime)): ?>
                                <div class="loa-template-notes">
                                    <strong>How PDF templates work</strong>
                                    <br>
                                    Ventures download this PDF exactly as
                                    uploaded, fill in their own details and
                                    sign it themselves, then upload the signed
                                    copy back on their tranches page for the
                                    team to confirm.
                                </div>
                            <?php endif; ?>

                            <?php if (
                                isset($active['notes'])
                                && trim((string)$active['notes']) !== ''
                            ): ?>
                                <div class="loa-template-notes">
                                    <strong>Change notes</strong>
                                    <br>

                                    <?= nl2br(
                                        h((string)$active['notes'])
                                    ) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="loa-template-actions">
                            <a
                                href="../includes/grant-process.php?action=download_loa_template"
                                class="btn btn-primary"
                            >
                                <i class="fa-solid fa-download"></i>
                                Download to Edit
                            </a>

                            <button
                                type="button"
                                class="btn btn-outline"
                                data-open-tab="upload-template"
                            >
                                <i class="fa-solid fa-upload"></i>
                                Upload Revision
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="loa-empty-state">
                        <div class="loa-empty-icon">
                            <i class="fa-solid fa-file-circle-plus"></i>
                        </div>

                        <h3>No template uploaded yet</h3>

                        <p>
                            The first generated LoA can seed a template from
                            <code>assets/loa_template.docx</code> when that file
                            exists. You can also upload the first template
                            manually — a .docx with placeholders filled in
                            automatically, or a PDF that ventures fill in and
                            sign themselves.
                        </p>

                        <button
                            type="button"
                            class="btn btn-primary legacy-style-1b0f4999d2"
                            data-open-tab="upload-template"
                           
                        >
                            <i class="fa-solid fa-upload"></i>
                            Upload First Template
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Upload tab -->
            <div
                class="loa-tab-panel<?= $defaultTab === 'upload-template' ? ' active' : '' ?>"
                id="upload-template"
                role="tabpanel"
                aria-labelledby="upload-template-tab"
                <?= $defaultTab === 'upload-template' ? '' : 'hidden' ?>
            >
                <div class="loa-section-header">
                    <div>
                        <h2 class="loa-section-title">
                            Upload New Template Version
                        </h2>

                      
                    </div>

                    <?php if ($active): ?>
                        <a
                            href="../includes/grant-process.php?action=download_loa_template"
                            class="btn btn-outline btn-sm"
                        >
                            <i class="fa-solid fa-download"></i>
                            Download Current Template
                        </a>
                    <?php endif; ?>
                </div>

               
<!--
                <div class="loa-placeholder-box">
                    <?= h(
                        implode(
                            ', ',
                            array_map(
                                static fn(string $placeholder): string =>
                                    '{{' . $placeholder . '}}',
                                LOA_TEMPLATE_REQUIRED_PLACEHOLDERS
                            )
                        )
                    ) ?>
                </div> -->

                <!-- <div class="loa-warning">
                    <i class="fa-solid fa-triangle-exclamation"></i>

                    <div>
                        .docx uploads missing one or more required
                        placeholders are rejected automatically — the current
                        active template remains unchanged when validation
                        fails. This check doesn't apply to PDF uploads: the
                        PDF is never edited by the system at all — ventures
                        download it, fill it in and sign it themselves, then
                        upload the signed copy back.
                    </div>
                </div> -->

                <form
                    method="POST"
                    action="../includes/grant-process.php"
                    enctype="multipart/form-data"
                    class="loa-form"
                    id="loaUploadForm"
                >
                    <input
                        type="hidden"
                        name="action"
                        value="upload_loa_template"
                    >

                    <div class="loa-form-group">
                        <label
                            class="loa-form-label"
                            for="templateName"
                        >
                            Template name
                            <span class="loa-required">*</span>
                        </label>

                        <input
                            type="text"
                            id="templateName"
                            name="name"
                            class="loa-form-control"
                            value="Standard LoA Template"
                            maxlength="150"
                            required
                        >
                    </div>

                    <div class="loa-form-group">
                        <label
                            class="loa-form-label"
                            for="templateNotes"
                        >
                            Change notes
                        </label>

                        <textarea
                            id="templateNotes"
                            name="notes"
                            class="loa-form-control"
                            rows="3"
                            maxlength="1000"
                            placeholder="Example: Updated clause 4.2 wording and refreshed the organisation logo."
                        ></textarea>

                        <div class="loa-form-help">
                            Briefly describe the changes made in this version.
                        </div>
                    </div>

                    <div class="loa-form-group">
                        <label
                            class="loa-form-label"
                            for="templateFile"
                        >
                            Template file
                            <span class="loa-required">*</span>
                        </label>

                        <input
                            type="file"
                            id="templateFile"
                            name="template_file"
                            class="loa-form-control"
                            accept=".docx,.pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/pdf"
                            required
                        >

                        <div class="loa-form-help">
                            Accepts Microsoft Word (.docx) or PDF (.pdf) files.
                            .docx templates get venture details filled into
                            their placeholders directly. PDF templates are
                            downloaded and filled in by the venture themselves
                            — no placeholders needed.
                        </div>
                    </div>

                    <div class="loa-form-actions">
                        <button
                            type="submit"
                            class="btn btn-primary"
                            id="loaUploadButton"
                        >
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            Upload and Activate
                        </button>

                        <button
                            type="reset"
                            class="btn btn-outline"
                        >
                            <i class="fa-solid fa-rotate-left"></i>
                            Reset
                        </button>
                    </div>
                </form>
            </div>

            <!-- History tab -->
            <div
                class="loa-tab-panel<?= $defaultTab === 'version-history' ? ' active' : '' ?>"
                id="version-history"
                role="tabpanel"
                aria-labelledby="version-history-tab"
                <?= $defaultTab === 'version-history' ? '' : 'hidden' ?>
            >
                <div class="loa-section-header">
                    <div>
                        <h2 class="loa-section-title">
                            Template Version History
                        </h2>

                       
                    </div>
                </div>

                <?php if (empty($versions)): ?>
                    <div class="loa-empty-state">
                        <div class="loa-empty-icon">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                        </div>

                        <h3>No template versions found</h3>

                        <p>
                            Uploaded template versions will appear here.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="loa-table-wrapper">
                        <table class="loa-history-table">
                            <thead>
                                <tr>
                                    <th>Version</th>
                                    <th>Name</th>
                                    <th>File</th>
                                    <th>Type</th>
                                    <th>Uploaded</th>
                                    <th>Notes</th>
                                    <th>Status</th>
                                    <th class="legacy-style-54c2afb7ba">Actions</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($versions as $version): ?>
                                    <?php
                                    $versionId = isset($version['id'])
                                        ? (int)$version['id']
                                        : 0;

                                    $isActive = isset($version['is_active'])
                                        && (int)$version['is_active'] === 1;

                                    $versionMime = (string)($version['mime_type'] ?? '');
                                    $versionIsPdf = loa_template_is_pdf($versionMime);
                                    ?>

                                    <tr>
                                        <td>
                                            <strong>
                                                v<?= (int)($version['version'] ?? 0) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= h(
                                                (string)(
                                                    $version['name']
                                                    ?? 'Unnamed Template'
                                                )
                                            ) ?>
                                        </td>

                                        <td class="loa-file-cell">
                                            <span
                                                class="loa-file-name"
                                                title="<?= h(
                                                    (string)(
                                                        $version['file_name']
                                                        ?? ''
                                                    )
                                                ) ?>"
                                            >
                                                <i class="<?= h(loa_template_icon_class($versionMime)) ?>"></i>
                                                <?= h(
                                                    (string)(
                                                        $version['file_name']
                                                        ?? 'Unknown file'
                                                    )
                                                ) ?>
                                            </span>

                                            <span class="loa-file-size">
                                                <?= h(
                                                    format_loa_file_size(
                                                        $version['file_size']
                                                        ?? 0
                                                    )
                                                ) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="loa-filetype-badge <?= $versionIsPdf ? 'pdf' : 'docx' ?>">
                                                <?= $versionIsPdf ? 'PDF' : 'Word' ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?= h(
                                                format_loa_date(
                                                    isset($version['created_at'])
                                                        ? (string)$version['created_at']
                                                        : null
                                                )
                                            ) ?>
                                        </td>

                                        <td class="loa-notes-cell">
                                            <?php if (
                                                isset($version['notes'])
                                                && trim((string)$version['notes']) !== ''
                                            ): ?>
                                                <?= nl2br(
                                                    h((string)$version['notes'])
                                                ) ?>
                                            <?php else: ?>
                                                <span class="legacy-style-5dcdf2dee8">
                                                    No notes
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?php if ($isActive): ?>
                                                <span class="loa-status-badge loa-status-active">
                                                    <i class="fa-solid fa-circle-check"></i>
                                                    Active
                                                </span>
                                            <?php else: ?>
                                                <span class="loa-status-badge loa-status-inactive">
                                                    Inactive
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <div class="loa-table-actions">
                                                <a
                                                    href="../includes/grant-process.php?action=download_loa_template&amp;id=<?= $versionId ?>"
                                                    class="btn btn-outline btn-sm loa-btn-icon"
                                                    title="Download template"
                                                    aria-label="Download template version <?= (int)($version['version'] ?? 0) ?>"
                                                >
                                                    <i class="fa-solid fa-download"></i>
                                                </a>

                                                <?php if (!$isActive): ?>
                                                    <form
                                                        method="POST"
                                                        action="../includes/grant-process.php"
                                                        class="loa-inline-form"
                                                        onsubmit="return confirm(<?= $versionIsPdf ? "'Activate this PDF template? Ventures will download this exact version to fill in and sign themselves.'" : "'Activate this LoA template version?'" ?>);"
                                                    >
                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="activate_loa_template"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="id"
                                                            value="<?= $versionId ?>"
                                                        >

                                                        <button
                                                            type="submit"
                                                            class="btn btn-primary btn-sm"
                                                        >
                                                            <i class="fa-solid fa-check"></i>
                                                            Activate
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const tabsWrapper = document.querySelector('.loa-tabs-wrapper');

    if (!tabsWrapper) {
        return;
    }

    const tabButtons = Array.from(
        tabsWrapper.querySelectorAll('.loa-tab-button')
    );

    const tabPanels = Array.from(
        tabsWrapper.querySelectorAll('.loa-tab-panel')
    );

    function activateTab(tabId, updateUrl) {
        const targetPanel = document.getElementById(tabId);
        const targetButton = tabsWrapper.querySelector(
            '[data-tab-target="' + tabId + '"]'
        );

        if (!targetPanel || !targetButton) {
            return;
        }

        tabButtons.forEach(function (button) {
            const isActive = button === targetButton;

            button.classList.toggle('active', isActive);
            button.setAttribute(
                'aria-selected',
                isActive ? 'true' : 'false'
            );
            button.setAttribute(
                'tabindex',
                isActive ? '0' : '-1'
            );
        });

        tabPanels.forEach(function (panel) {
            const isActive = panel === targetPanel;

            panel.classList.toggle('active', isActive);
            panel.hidden = !isActive;
        });

        if (updateUrl && window.history && window.history.replaceState) {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tabId);

            window.history.replaceState(
                {},
                '',
                url.toString()
            );
        }
    }

    tabButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            const tabId = button.getAttribute('data-tab-target');

            if (tabId) {
                activateTab(tabId, true);
            }
        });

        button.addEventListener('keydown', function (event) {
            const currentIndex = tabButtons.indexOf(button);
            let nextIndex = currentIndex;

            if (event.key === 'ArrowRight') {
                nextIndex = (currentIndex + 1) % tabButtons.length;
            } else if (event.key === 'ArrowLeft') {
                nextIndex = (
                    currentIndex - 1 + tabButtons.length
                ) % tabButtons.length;
            } else if (event.key === 'Home') {
                nextIndex = 0;
            } else if (event.key === 'End') {
                nextIndex = tabButtons.length - 1;
            } else {
                return;
            }

            event.preventDefault();

            const nextButton = tabButtons[nextIndex];
            const nextTabId = nextButton.getAttribute('data-tab-target');

            if (nextTabId) {
                activateTab(nextTabId, true);
                nextButton.focus();
            }
        });
    });

    document.querySelectorAll('[data-open-tab]').forEach(function (button) {
        button.addEventListener('click', function () {
            const tabId = button.getAttribute('data-open-tab');

            if (!tabId) {
                return;
            }

            activateTab(tabId, true);

            const tabButton = tabsWrapper.querySelector(
                '[data-tab-target="' + tabId + '"]'
            );

            if (tabButton) {
                tabButton.focus();
            }

            tabsWrapper.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        });
    });

    const uploadForm = document.getElementById('loaUploadForm');
    const uploadButton = document.getElementById('loaUploadButton');

    if (uploadForm && uploadButton) {
        uploadForm.addEventListener('submit', function () {
            uploadButton.disabled = true;
            uploadButton.innerHTML =
                '<i class="fa-solid fa-spinner fa-spin"></i> Uploading...';
        });
    }

    const initialTab =
        tabsWrapper.getAttribute('data-default-tab')
        || 'current-template';

    activateTab(initialTab, false);
});
</script>

</body>
</html>
