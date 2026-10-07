<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/mail-function.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection unavailable.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$page_title  = 'Bulk Emails';
$current_nav = 'bulk-emails.php';

if (empty($_SESSION['bulk_email_csrf'])) {
    $_SESSION['bulk_email_csrf'] = bin2hex(random_bytes(32));
}
$bulk_email_csrf = (string) $_SESSION['bulk_email_csrf'];

$mentor_rows = [];
$venture_rows = [];

$mentor_result = $conn->query("
    SELECT id, full_name, email, status
    FROM mentors
    WHERE email IS NOT NULL
      AND email <> ''
    ORDER BY full_name ASC
");

if ($mentor_result) {
    $mentor_rows = $mentor_result->fetch_all(MYSQLI_ASSOC);
}

$venture_result = $conn->query("
    SELECT
        v.id,
        v.name,
        COALESCE(NULLIF(vp.email, ''), NULLIF(v.cofounder1_email, ''), NULLIF(v.email, '')) AS email,
        v.status,
        c.name AS cohort_name
    FROM ventures v
    LEFT JOIN venture_portal_access vp ON vp.venture_id = v.id
    LEFT JOIN cohorts c ON c.id = v.cohort_id
    HAVING email IS NOT NULL AND email <> ''
    ORDER BY v.name ASC
");

if ($venture_result) {
    $venture_rows = $venture_result->fetch_all(MYSQLI_ASSOC);
}

/*
|--------------------------------------------------------------------------
| Email statistics
|--------------------------------------------------------------------------
*/
$stats = [
    'campaigns'       => 0,
    'recipients'      => 0,
    'sent'            => 0,
    'failed'          => 0,
    'today_sent'      => 0,
    'delivery_rate'   => 0.0,
];

$table_check = $conn->query("SHOW TABLES LIKE 'bulk_email_logs'");
$logs_table_exists = $table_check && $table_check->num_rows > 0;

if ($logs_table_exists) {
    $stats_result = $conn->query("
        SELECT
            COUNT(DISTINCT campaign_id) AS campaigns,
            COUNT(*) AS recipients,
            SUM(status = 'sent') AS sent,
            SUM(status = 'failed') AS failed,
            SUM(status = 'sent' AND DATE(created_at) = CURDATE()) AS today_sent
        FROM bulk_email_logs
    ");

    if ($stats_result) {
        $row = $stats_result->fetch_assoc() ?: [];
        $stats['campaigns']  = (int)($row['campaigns'] ?? 0);
        $stats['recipients'] = (int)($row['recipients'] ?? 0);
        $stats['sent']       = (int)($row['sent'] ?? 0);
        $stats['failed']     = (int)($row['failed'] ?? 0);
        $stats['today_sent'] = (int)($row['today_sent'] ?? 0);
    }

    if ($stats['recipients'] > 0) {
        $stats['delivery_rate'] = round(($stats['sent'] / $stats['recipients']) * 100, 1);
    }
}

$recent_campaigns = [];

if ($logs_table_exists) {
    $recent_result = $conn->query("
        SELECT
            campaign_id,
            subject,
            MAX(created_at) AS sent_at,
            COUNT(*) AS recipients,
            SUM(status = 'sent') AS sent_count,
            SUM(status = 'failed') AS failed_count,
            MAX(sent_by_name) AS sent_by_name
        FROM bulk_email_logs
        GROUP BY campaign_id, subject
        ORDER BY sent_at DESC
        LIMIT 15
    ");

    if ($recent_result) {
        $recent_campaigns = $recent_result->fetch_all(MYSQLI_ASSOC);
    }
}

$site_name = function_exists('get_setting')
    ? get_setting($conn, 'site_name', 'Elevate Accelerator')
    : 'Elevate Accelerator';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($page_title) ?> - <?= h($site_name) ?> Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="assets/css/admin.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/admin.css') ?>">
    <link rel="stylesheet" href="assets/css/bulk-emails.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/bulk-emails.css') ?>">

    
</head>
<body class="admin-system-page">
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">
    <header class="admin-topbar">
        <div class="topbar-left">
            <div class="topbar-breadcrumb">
                <a href="index.php">Dashboard</a> &rsaquo; <strong>Bulk Emails</strong>
            </div>
        </div>
        <div class="topbar-right">
            <div class="admin-avatar">
                <div class="avatar-circle"><?= h(strtoupper(substr((string)($ADMIN['full_name'] ?? 'A'), 0, 1))) ?></div>
            </div>
        </div>
    </header>

    <div class="admin-content bulk-email-page">
        <?php if (function_exists('show_flash')) show_flash('bulk_email'); ?>

        <div class="page-header">
            <div>
                <h1 class="page-title">Bulk Emails</h1>
                <p class="page-subtitle">Send one personalised email campaign to selected mentors and ventures.</p>
            </div>
        </div>

        <section class="email-stats">
            <div class="email-stat"><div class="email-stat-icon"><i class="fa fa-layer-group"></i></div><div class="email-stat-value"><?= number_format($stats['campaigns']) ?></div><div class="email-stat-label">Campaigns sent</div></div>
            <div class="email-stat"><div class="email-stat-icon"><i class="fa fa-users"></i></div><div class="email-stat-value"><?= number_format($stats['recipients']) ?></div><div class="email-stat-label">Total recipients</div></div>
            <div class="email-stat"><div class="email-stat-icon"><i class="fa fa-circle-check"></i></div><div class="email-stat-value"><?= number_format($stats['sent']) ?></div><div class="email-stat-label">Emails delivered</div></div>
            <div class="email-stat"><div class="email-stat-icon"><i class="fa fa-circle-xmark"></i></div><div class="email-stat-value"><?= number_format($stats['failed']) ?></div><div class="email-stat-label">Failed emails</div></div>
            <div class="email-stat"><div class="email-stat-icon"><i class="fa fa-chart-line"></i></div><div class="email-stat-value"><?= h(number_format($stats['delivery_rate'], 1)) ?>%</div><div class="email-stat-label">Delivery success rate</div></div>
            <div class="email-stat"><div class="email-stat-icon"><i class="fa fa-calendar-day"></i></div><div class="email-stat-value"><?= number_format($stats['today_sent']) ?></div><div class="email-stat-label">Sent today</div></div>
        </section>

        <?php if (!$logs_table_exists): ?>
            <div class="warning-box">
                <i class="fa fa-triangle-exclamation"></i>
                Email statistics will appear after you run the supplied SQL migration for <code>bulk_email_logs</code>.
            </div>
        <?php endif; ?>

        <div class="bulk-tabs-wrap">
            <div class="bulk-main-tabs" role="tablist">
                <button type="button"
                        class="bulk-main-tab active"
                        id="bulkTabCompose"
                        role="tab"
                        aria-selected="true"
                        aria-controls="bulkPanelCompose"
                        onclick="showBulkMainTab('compose')">
                    <i class="fa fa-paper-plane"></i> Compose Campaign
                </button>
                <button type="button"
                        class="bulk-main-tab"
                        id="bulkTabRecent"
                        role="tab"
                        aria-selected="false"
                        aria-controls="bulkPanelRecent"
                        onclick="showBulkMainTab('recent')">
                    <i class="fa fa-clock-rotate-left"></i> Recent Campaigns
                </button>
            </div>

            <div class="bulk-main-panel active" id="bulkPanelCompose" role="tabpanel">
                <section class="bulk-card">
                <div class="bulk-card-head">
                    <h2><i class="fa fa-paper-plane"></i> Compose Campaign</h2>
                </div>

                <div class="bulk-card-body">
                    <div id="bulkEmailInlineAlert" class="bulk-alert bulk-alert-error" role="alert" aria-live="polite">
                            <i class="fa fa-circle-exclamation"></i>
                            <div id="bulkEmailInlineAlertText"></div>
                        </div>

                        <form method="POST" action="includes/process-bulk-emails.php" id="bulkEmailForm" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="send_bulk_email">
                        <input type="hidden" name="csrf_token" value="<?= h($bulk_email_csrf) ?>">

                        <div class="recipient-tabs">
                            <button type="button" class="recipient-tab active" id="tabMentors" onclick="showRecipientPanel('mentors')">
                                <i class="fa fa-user-tie"></i> Mentors
                            </button>
                            <button type="button" class="recipient-tab" id="tabVentures" onclick="showRecipientPanel('ventures')">
                                <i class="fa fa-rocket"></i> Ventures
                            </button>
                        </div>

                        <div class="recipient-panel active" id="panelMentors">
                            <div class="recipient-toolbar">
                                <input type="search" class="recipient-search" placeholder="Search mentors..." oninput="filterRecipients('mentor',this.value)">
                                <button type="button" class="btn btn-sm btn-secondary" onclick="toggleRecipients('mentor',true)">Select visible</button>
                                <button type="button" class="btn btn-sm" onclick="toggleRecipients('mentor',false)">Clear visible</button>
                            </div>

                            <div class="recipient-list">
                                <?php if (empty($mentor_rows)): ?>
                                    <div class="empty-info">No mentor email addresses found.</div>
                                <?php else: ?>
                                    <?php foreach ($mentor_rows as $mentor): ?>
                                        <label class="recipient-row" data-type="mentor" data-search="<?= h(mb_strtolower($mentor['full_name'] . ' ' . $mentor['email'])) ?>">
                                            <input type="checkbox" name="mentor_ids[]" value="<?= (int)$mentor['id'] ?>" onchange="updateRecipientCount()">
                                            <span class="recipient-main">
                                                <span class="recipient-name"><?= h($mentor['full_name']) ?></span>
                                                <span class="recipient-email"><?= h($mentor['email']) ?></span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="recipient-panel" id="panelVentures">
                            <div class="recipient-toolbar">
                                <input type="search" class="recipient-search" placeholder="Search ventures..." oninput="filterRecipients('venture',this.value)">
                                <button type="button" class="btn btn-sm btn-secondary" onclick="toggleRecipients('venture',true)">Select visible</button>
                                <button type="button" class="btn btn-sm" onclick="toggleRecipients('venture',false)">Clear visible</button>
                            </div>

                            <div class="recipient-list">
                                <?php if (empty($venture_rows)): ?>
                                    <div class="empty-info">No venture email addresses found.</div>
                                <?php else: ?>
                                    <?php foreach ($venture_rows as $venture): ?>
                                        <label class="recipient-row" data-type="venture" data-search="<?= h(mb_strtolower($venture['name'] . ' ' . $venture['email'] . ' ' . ($venture['cohort_name'] ?? ''))) ?>">
                                            <input type="checkbox" name="venture_ids[]" value="<?= (int)$venture['id'] ?>" onchange="updateRecipientCount()">
                                            <span class="recipient-main">
                                                <span class="recipient-name"><?= h($venture['name']) ?></span>
                                                <span class="recipient-email"><?= h($venture['email']) ?><?= !empty($venture['cohort_name']) ? ' . ' . h($venture['cohort_name']) : '' ?></span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="recipient-summary">
                            <span><i class="fa fa-users"></i> Selected recipients</span>
                            <strong id="recipientCount">0</strong>
                        </div>

                        <div class="compose-grid">
                            <div>
                                <label>Email Subject</label>
                                <input type="text" name="subject" maxlength="180" required placeholder="Enter email subject">
                            </div>

                            <div>
                                <label>Email Message</label>
                                <textarea name="message" rows="10" maxlength="20000" required placeholder="Write your email message..."></textarea>
                                <div class="compose-help">Use <code>{{name}}</code>, <code>{{email}}</code>, and <code>{{type}}</code> to personalise the message.</div>
                            </div>

                            <div class="bulk-attachment-panel">
                                <div class="bulk-attachment-head">
                                    <div>
                                        <h3><i class="fa fa-paperclip"></i> Attachments</h3>
                                        <p>Add files or clickable web links to the campaign. Both are optional.</p>
                                    </div>
                                </div>
                                <div class="bulk-attachment-grid">
                                    <div class="bulk-upload-box">
                                        <label for="campaignAttachments">File attachments</label>
                                        <input type="file" id="campaignAttachments" name="attachments[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.csv,.txt,.jpg,.jpeg,.png,.webp,.zip" onchange="renderSelectedAttachments(this.files)">
                                        <small>Up to 5 files, 10 MB each. PDF, Office, CSV, text, image, or ZIP files.</small>
                                        <div id="selectedAttachmentList" class="selected-attachment-list"></div>
                                    </div>
                                    <div class="bulk-link-box">
                                        <label for="attachmentLinks">Hyperlink attachments</label>
                                        <textarea id="attachmentLinks" name="attachment_links" rows="6" maxlength="5000" placeholder="Project report | https://example.com/report.pdf&#10;Registration form | https://example.com/register&#10;https://example.com/resource"></textarea>
                                        <small>Enter one link per line. Use <strong>Label | URL</strong> or paste a URL only.</small>
                                    </div>
                                </div>
                            </div>

                            <label class="legacy-style-e44e81e8bc">
                                <input type="checkbox" name="confirm_bulk_send" value="1" required class="legacy-style-bc43be3139">
                                I confirm that the selected recipients should receive this email.
                            </label>

                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-paper-plane"></i> Send Email Campaign
                            </button>
                        </div>
                    </form>
                </div>
            </section>
            </div>

            <div class="bulk-main-panel" id="bulkPanelRecent" role="tabpanel">
                <section class="bulk-card">
                <div class="bulk-card-head">
                    <h2><i class="fa fa-clock-rotate-left"></i> Recent Campaigns</h2>
                </div>

                <?php if (empty($recent_campaigns)): ?>
                    <div class="empty-info">
                        <i class="fa fa-envelope-open-text legacy-style-5a4ec4bfa4"></i>
                        No bulk email campaigns have been recorded yet.
                    </div>
                <?php else: ?>
                    <div class="legacy-style-0ddf068bfb">
                        <table class="history-table">
                            <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Recipients</th>
                                <th>Result</th>
                                <th>Sent</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recent_campaigns as $campaign): ?>
                                <tr>
                                    <td>
                                        <strong><?= h($campaign['subject']) ?></strong>
                                        <div class="legacy-style-2f03bac0e0"><?= h($campaign['sent_by_name'] ?: 'Administrator') ?></div>
                                    </td>
                                    <td><?= number_format((int)$campaign['recipients']) ?></td>
                                    <td>
                                        <div class="campaign-result">
                                            <span class="campaign-result-pill campaign-result-success">
                                                <i class="fa fa-circle-check"></i>
                                                <?= (int)$campaign['sent_count'] ?> sent
                                            </span>
                                            <span class="campaign-result-pill campaign-result-failed">
                                                <i class="fa fa-circle-xmark"></i>
                                                <?= (int)$campaign['failed_count'] ?> failed
                                            </span>
                                        </div>
                                    </td>
                                    <td><?= h(date('M j, Y g:i A', strtotime($campaign['sent_at']))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('sidebarToggle')?.addEventListener('click', () => {
    document.getElementById('adminSidebar')?.classList.toggle('collapsed');
    document.getElementById('adminMain')?.classList.toggle('collapsed');
});

function showBulkMainTab(tab) {
    const composeActive = tab === 'compose';

    document.getElementById('bulkPanelCompose')?.classList.toggle('active', composeActive);
    document.getElementById('bulkPanelRecent')?.classList.toggle('active', !composeActive);

    document.getElementById('bulkTabCompose')?.classList.toggle('active', composeActive);
    document.getElementById('bulkTabRecent')?.classList.toggle('active', !composeActive);

    document.getElementById('bulkTabCompose')?.setAttribute('aria-selected', composeActive ? 'true' : 'false');
    document.getElementById('bulkTabRecent')?.setAttribute('aria-selected', composeActive ? 'false' : 'true');
}

function showRecipientPanel(type) {
    document.getElementById('panelMentors').classList.toggle('active', type === 'mentors');
    document.getElementById('panelVentures').classList.toggle('active', type === 'ventures');
    document.getElementById('tabMentors').classList.toggle('active', type === 'mentors');
    document.getElementById('tabVentures').classList.toggle('active', type === 'ventures');
}

function filterRecipients(type, query) {
    const q = String(query || '').trim().toLowerCase();

    document.querySelectorAll(`[data-type="${type}"]`).forEach(row => {
        row.style.display = (row.dataset.search || '').includes(q) ? 'flex' : 'none';
    });
}

function toggleRecipients(type, checked) {
    document.querySelectorAll(`[data-type="${type}"]`).forEach(row => {
        if (row.style.display !== 'none') {
            const checkbox = row.querySelector('input[type="checkbox"]');
            if (checkbox) checkbox.checked = checked;
        }
    });

    updateRecipientCount();
}

function updateRecipientCount() {
    const total = document.querySelectorAll('.recipient-row input[type="checkbox"]:checked').length;
    document.getElementById('recipientCount').textContent = String(total);
}

function showBulkInlineAlert(message, type = 'error') {
    const alertBox = document.getElementById('bulkEmailInlineAlert');
    const alertText = document.getElementById('bulkEmailInlineAlertText');

    if (!alertBox || !alertText) return;

    alertBox.className = 'bulk-alert show bulk-alert-' + type;
    alertText.textContent = message;
    alertBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function clearBulkInlineAlert() {
    const alertBox = document.getElementById('bulkEmailInlineAlert');
    const alertText = document.getElementById('bulkEmailInlineAlertText');

    if (!alertBox || !alertText) return;

    alertBox.className = 'bulk-alert bulk-alert-error';
    alertText.textContent = '';
}

function renderSelectedAttachments(fileList) {
    const list = document.getElementById('selectedAttachmentList');
    if (!list) return;
    list.innerHTML = '';
    Array.from(fileList || []).forEach(file => {
        const item = document.createElement('div');
        item.className = 'selected-attachment-item';
        item.textContent = file.name + ' (' + (file.size / (1024 * 1024)).toFixed(2) + ' MB)';
        list.appendChild(item);
    });
}

document.getElementById('bulkEmailForm').addEventListener('submit', event => {
    clearBulkInlineAlert();

    const total = document.querySelectorAll('.recipient-row input[type="checkbox"]:checked').length;
    const subject = document.querySelector('input[name="subject"]')?.value.trim() || '';
    const message = document.querySelector('textarea[name="message"]')?.value.trim() || '';
    const confirmed = document.querySelector('input[name="confirm_bulk_send"]')?.checked || false;
    const files = Array.from(document.getElementById('campaignAttachments')?.files || []);
    const oversized = files.find(file => file.size > 10 * 1024 * 1024);

    if (files.length > 5) {
        event.preventDefault();
        showBulkInlineAlert('You can attach a maximum of 5 files.');
        return;
    }

    if (oversized) {
        event.preventDefault();
        showBulkInlineAlert('The file "' + oversized.name + '" is larger than 10 MB.');
        return;
    }

    if (total < 1) {
        event.preventDefault();
        showBulkInlineAlert('Select at least one mentor or venture before sending.');
        return;
    }

    if (subject === '') {
        event.preventDefault();
        showBulkInlineAlert('Enter an email subject.');
        return;
    }

    if (message === '') {
        event.preventDefault();
        showBulkInlineAlert('Enter the email message.');
        return;
    }

    if (!confirmed) {
        event.preventDefault();
        showBulkInlineAlert('Confirm that the selected recipients should receive this email.');
        return;
    }

    const submitButton = event.currentTarget.querySelector('button[type="submit"]');
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Sending to ' + total + ' recipient' + (total === 1 ? '' : 's') + '...';
    }
});
</script>
</body>
</html>
