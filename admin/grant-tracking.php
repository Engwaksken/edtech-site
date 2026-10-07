<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/grant-functions.php';

$page_title  = 'Grant Tracking';
$current_nav = 'grant-tracking.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection error.');
}

$conn->set_charset('utf8mb4');

date_default_timezone_set('Africa/Kampala');
$conn->query("SET time_zone = '+03:00'");

$site_name    = 'Edtech Hivecolab';
$favicon_path = '';
$settings_result = $conn->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('site_name', 'site_favicon')");
if ($settings_result) {
    while ($row = $settings_result->fetch_assoc()) {
        if ($row['setting_key'] === 'site_name' && trim((string)$row['setting_value']) !== '') {
            $site_name = trim((string)$row['setting_value']);
        } elseif ($row['setting_key'] === 'site_favicon' && trim((string)$row['setting_value']) !== '') {
            $favicon_path = trim((string)$row['setting_value']);
        }
    }
}
if ($favicon_path === '') {
    $favicon_path = 'assets/images/favicon.png';
}
if (!preg_match('#^https?://#i', $favicon_path) && defined('SITE_URL')) {
    $favicon_path = rtrim((string)SITE_URL, '/') . '/' . ltrim($favicon_path, '/');
}

$admin_base_url = defined('SITE_URL')
    ? rtrim((string) SITE_URL, '/') . '/admin'
    : '/admin';
$admin_css_url = $admin_base_url . '/assets/css/admin.css?v=' . rawurlencode((string) @filemtime(__DIR__ . '/assets/css/admin.css'));
$grant_css_url = $admin_base_url . '/assets/css/grant-tracking.css?v=' . rawurlencode((string) @filemtime(__DIR__ . '/assets/css/grant-tracking.css'));

$selected_venture_id = (int)($_GET['venture_id'] ?? 0);
$selected_cohort_id  = (int)($_GET['cohort_id'] ?? 0);

$all_ventures = $conn->query("SELECT id, name, email, cohort_id FROM ventures ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
$org_summary  = get_all_ventures_fund_summary($conn);


$cohorts_result = $conn->query("SELECT id, name FROM cohorts ORDER BY name ASC");
$all_cohorts = $cohorts_result ? $cohorts_result->fetch_all(MYSQLI_ASSOC) : [];

$venture_cohort_map = [];
foreach ($all_ventures as $v) {
    $venture_cohort_map[(int)$v['id']] = (int)($v['cohort_id'] ?? 0);
}

if ($selected_cohort_id > 0) {
    $org_summary = array_values(array_filter(
        $org_summary,
        fn($row) => ($venture_cohort_map[(int)$row['venture_id']] ?? 0) === $selected_cohort_id
    ));
}


$loa_tracking_rows = $conn->query("
    SELECT
        v.id AS venture_id,
        v.name AS venture_name,
        v.cohort_id,
        la.id AS loa_id,
        la.status,
        la.generated_file_path,
        la.download_count,
        la.last_downloaded_at,
        la.signed_status,
        la.signed_uploaded_at,
        la.signed_confirmed_at
    FROM loa_agreements la
    INNER JOIN ventures v ON v.id = la.venture_id
    ORDER BY v.name ASC
")->fetch_all(MYSQLI_ASSOC);

if ($selected_cohort_id > 0) {
    $loa_tracking_rows = array_values(array_filter(
        $loa_tracking_rows,
        fn($row) => (int)($row['cohort_id'] ?? 0) === $selected_cohort_id
    ));
}

$selected_venture = null;
$selected_loa = null;
$selected_tranches = [];
$selected_fund_summary = null;

if ($selected_venture_id > 0) {
    foreach ($all_ventures as $v) {
        if ((int)$v['id'] === $selected_venture_id) {
            $selected_venture = $v;
            break;
        }
    }

    if ($selected_venture && $selected_cohort_id > 0 && (int)($selected_venture['cohort_id'] ?? 0) !== $selected_cohort_id) {
        // Selected venture doesn't belong to the newly chosen cohort filter.
        $selected_venture = null;
        $selected_venture_id = 0;
    }

    if ($selected_venture) {
    $stmt = $conn->prepare("SELECT * FROM loa_agreements WHERE venture_id = ? LIMIT 1");
    $stmt->bind_param('i', $selected_venture_id);
    $stmt->execute();
    $selected_loa = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    $selected_tranches = get_venture_tranches($conn, $selected_venture_id);
    $selected_fund_summary = get_venture_fund_summary($conn, $selected_venture_id);
    }
}

$status_labels = [
    'locked' => 'Locked', 'open' => 'Open', 'submitted' => 'Submitted',
    'changes_requested' => 'Changes Requested', 'approved' => 'Approved',
    'rejected' => 'Rejected', 'disbursed' => 'Disbursed',
];
$status_badges = [
    'locked' => 'badge-gray', 'open' => 'badge-orange', 'submitted' => 'badge-warning',
    'changes_requested' => 'badge-danger', 'approved' => 'badge-success',
    'rejected' => 'badge-danger', 'disbursed' => 'badge-success',
];

$default_tranche_index = 0;
$has_pending_review = false;
foreach ($selected_tranches as $idx => $t) {
    if (!empty($t['latest_submission']) && $t['latest_submission']['review_status'] === 'pending') {
        $default_tranche_index = $idx;
        $has_pending_review = true;
        break;
    }
}


$default_main_tab = $has_pending_review ? 'tranches' : 'loa';


$grant_message_type = '';
$grant_message_text = '';

$grant_message_code = isset($_GET['message']) && is_string($_GET['message'])
    ? trim($_GET['message'])
    : '';

$grant_messages = [
    'loa_template_missing' => [
        'type' => 'warning',
        'text' => 'No LoA template has been uploaded yet. Upload and activate a template before generating agreements.',
    ],
    'loa_generation_failed' => [
        'type' => 'error',
        'text' => 'The Letter of Agreement could not be generated. Please review the details and try again.',
    ],
    'loa_generated' => [
        'type' => 'success',
        'text' => 'Letter of Agreement generated successfully.',
    ],
    'disbursement_recorded' => [
        'type' => 'success',
        'text' => 'Disbursement recorded successfully.',
    ],
    'tranche_status_updated' => [
        'type' => 'success',
        'text' => 'Tranche status updated successfully.',
    ],
    'tranche_resubmission_enabled' => [
        'type' => 'success',
        'text' => 'The tranche has been reopened and the venture can resubmit.',
    ],
    'tranche_file_deleted' => [
        'type' => 'success',
        'text' => 'Submitted file deleted successfully.',
    ],
    'tranche_submission_deleted' => [
        'type' => 'success',
        'text' => 'Tranche submission deleted successfully. The tranche status was recalculated.',
    ],
];

if (isset($grant_messages[$grant_message_code])) {
    $grant_message_type = $grant_messages[$grant_message_code]['type'];
    $grant_message_text = $grant_messages[$grant_message_code]['text'];
}

if ($grant_message_text === '' && isset($_GET['generated'])) {
    $grant_message_type = 'success';
    $grant_message_text = 'Letter of Agreement generated successfully.';
}

if ($grant_message_text === '' && isset($_GET['disbursed'])) {
    $grant_message_type = 'success';
    $grant_message_text = 'Disbursement recorded successfully.';
}

if (
    $grant_message_text === ''
    && isset($_GET['error'])
    && is_string($_GET['error'])
) {
    $grant_message_type = 'error';
    $grant_message_text = mb_substr(trim($_GET['error']), 0, 500);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Grant Tracking - <?= h($site_name) ?> Admin</title>
 <link rel="icon" href="<?= h($favicon_path) ?>" type="image/png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" referrerpolicy="no-referrer">
  <link rel="stylesheet" href="<?= h($admin_css_url) ?>">
  <link rel="stylesheet" href="<?= h($grant_css_url) ?>">
</head>
<body class="grant-tracking-page">
<?php include 'includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">
  <header class="admin-topbar">
    <div class="topbar-left">
      <div class="topbar-breadcrumb"><a href="index.php">Dashboard</a> > <strong>Grant Tracking</strong></div>
    </div>
    <div class="topbar-right">
      <a href="<?= h(rtrim((string) SITE_URL, '/')) ?>" target="_blank" class="btn btn-secondary btn-sm"><i class="fa fa-eye"></i> View Site</a>
      <div class="admin-avatar">
        <div class="avatar-circle"><?= strtoupper(substr((string)($ADMIN['full_name'] ?? $ADMIN['email'] ?? 'A'), 0, 1)) ?></div>
      </div>
    </div>
  </header>

  <div class="admin-content">

  <div class="stats-grid">
    <?php
      $total_committed = array_sum(array_column($org_summary, 'total_committed'));
      $total_disbursed = array_sum(array_column($org_summary, 'total_disbursed'));
      $total_pending = array_sum(array_column($org_summary, 'pending_review'));
      $total_awaiting = array_sum(array_column($org_summary, 'awaiting_disbursement'));
    ?>
    <div class="stat-mini"><div class="num">$<?= number_format($total_committed) ?></div><div class="label">Total Committed (All Ventures)</div></div>
    <div class="stat-mini"><div class="num">$<?= number_format($total_disbursed) ?></div><div class="label">Total Disbursed</div></div>
    <div class="stat-mini"><div class="num">$<?= number_format($total_pending) ?></div><div class="label">Pending Review</div></div>
    <div class="stat-mini"><div class="num">$<?= number_format($total_awaiting) ?></div><div class="label">Approved, Awaiting Payment</div></div>
  </div>

  <div class="filter-bar">
    <select class="form-control gt-cohort-select" onchange="location.href = buildGtUrl({ cohort_id: this.value, venture_id: null })">
      <option value="">All Cohorts</option>
      <?php foreach ($all_cohorts as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $selected_cohort_id === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>

    <div class="search-bar search-bar-flex">
      <i class="fa fa-magnifying-glass"></i>
      <input
        type="text"
        id="ventureSearchInput"
        placeholder="Search ventures..."
        autocomplete="off"
        value="<?= $selected_venture ? h($selected_venture['name']) : '' ?>"
        onfocus="openGtVentureDropdown()"
        oninput="filterGtVentureDropdown(this.value)"
      >
      <div class="search-dropdown" id="ventureDropdown">
        <?php foreach ($org_summary as $row): ?>
          <a href="?venture_id=<?= (int)$row['venture_id'] ?><?= $selected_cohort_id > 0 ? '&cohort_id=' . $selected_cohort_id : '' ?>"
             class="search-dropdown-item <?= $selected_venture_id === (int)$row['venture_id'] ? 'active' : '' ?>"
             data-name="<?= h(strtolower($row['venture_name'])) ?>">
            <div class="name"><?= h($row['venture_name']) ?></div>
            <div class="figures">
              <?= h($row['loa_status'] ? ucfirst($row['loa_status']) : 'No LoA') ?>
              &middot; $<?= number_format((float)$row['total_disbursed']) ?> disbursed
            </div>
          </a>
        <?php endforeach; ?>
        <div class="no-results is-hidden">No ventures match your search.</div>
      </div>
    </div>
  </div>

  <div class="bg-panel">
    <div class="bg-panel-header open" onclick="document.getElementById('gtTrackingBody').classList.toggle('open'); this.classList.toggle('open')">
      <div class="bp-icon"><i class="fa fa-list-check"></i></div>
      <div>
        <h4>Letter of Agreement Tracking</h4>
        <small class="gt-panel-subtitle">Downloads and signed-copy uploads across all ventures</small>
      </div>
      <i class="fa fa-chevron-down bp-toggle"></i>
    </div>
    <div class="bg-panel-body open" id="gtTrackingBody">
      <?php if (empty($loa_tracking_rows)): ?>
        <p class="text-muted">No agreements have been generated yet.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Venture</th>
                <th>Status</th>
                <th>Downloaded</th>
                <th>Signed Copy</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($loa_tracking_rows as $row): ?>
                <?php
                  $rowDownloadCount = (int)($row['download_count'] ?? 0);
                  $rowSignedStatus  = $row['signed_status'] ?? null;
                ?>
                <tr>
                  <td><strong><?= h($row['venture_name']) ?></strong></td>
                  <td>
                    <span class="badge <?= h($status_badges[$row['status']] ?? 'badge-gray') ?>">
                      <?= h($status_labels[$row['status']] ?? ucfirst((string)$row['status'])) ?>
                    </span>
                  </td>
                  <td>
                    <?php if ($rowDownloadCount > 0): ?>
                      <span class="track-yes"><i class="fa fa-circle-check"></i> <?= $rowDownloadCount ?>&times;</span>
                      <?php if (!empty($row['last_downloaded_at'])): ?>
                        <div class="track-date"><?= h((new DateTime($row['last_downloaded_at']))->format('j M Y')) ?></div>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="track-no"><i class="fa fa-circle-minus"></i> Not yet</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($rowSignedStatus === 'confirmed'): ?>
                      <span class="track-yes"><i class="fa fa-circle-check"></i> Confirmed</span>
                      <?php if (!empty($row['signed_confirmed_at'])): ?>
                        <div class="track-date"><?= h((new DateTime($row['signed_confirmed_at']))->format('j M Y')) ?></div>
                      <?php endif; ?>
                    <?php elseif ($rowSignedStatus === 'pending'): ?>
                      <span class="track-pending"><i class="fa fa-clock"></i> Awaiting confirmation</span>
                      <?php if (!empty($row['signed_uploaded_at'])): ?>
                        <div class="track-date"><?= h((new DateTime($row['signed_uploaded_at']))->format('j M Y')) ?></div>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="track-no"><i class="fa fa-circle-minus"></i> Not uploaded</span>
                    <?php endif; ?>
                  </td>
                  <td class="table-nowrap">
                    <a href="?venture_id=<?= (int)$row['venture_id'] ?><?= $selected_cohort_id > 0 ? '&cohort_id=' . $selected_cohort_id : '' ?>" class="btn btn-secondary btn-sm">
                      View
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div>
      <?php if (!$selected_venture): ?>
        <div class="card"><div class="card-body">
          <p class="text-muted">Select a venture above to manage their Letter of Agreement, tranches, and disbursements.</p>
        </div></div>
      <?php else: ?>

        <?php if ($grant_message_text !== ''): ?>
          <?php
          $grant_alert_class = match ($grant_message_type) {
              'success' => 'alert-success',
              'warning' => 'alert-warning',
              default => 'alert-error',
          };
          ?>
          <div
            class="alert <?= h($grant_alert_class) ?>"
            role="<?= $grant_message_type === 'success' ? 'status' : 'alert' ?>"
          >
            <?php if ($grant_message_type === 'warning'): ?>
              <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
            <?php endif; ?>

            <div>
              <?= h($grant_message_text) ?>

              <?php if ($grant_message_code === 'loa_template_missing'): ?>
                <div class="mt-7">
                  <a href="loa-template.php?tab=upload-template">
                    Open LoA Template Management
                  </a>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="card mb-20">

          <div class="tabs" role="tablist">
            <button type="button"
                    class="tab-btn<?= $default_main_tab === 'loa' ? ' active' : '' ?>"
                    id="gt-main-tab-btn-loa"
                    role="tab"
                    aria-selected="<?= $default_main_tab === 'loa' ? 'true' : 'false' ?>"
                    aria-controls="gt-main-panel-loa"
                    onclick="showGtMainPanel('loa')">
              <i class="fa fa-file-signature"></i> Letter of Agreement
            </button>
            <button type="button"
                    class="tab-btn<?= $default_main_tab === 'fund' ? ' active' : '' ?>"
                    id="gt-main-tab-btn-fund"
                    role="tab"
                    aria-selected="<?= $default_main_tab === 'fund' ? 'true' : 'false' ?>"
                    aria-controls="gt-main-panel-fund"
                    onclick="showGtMainPanel('fund')">
              <i class="fa fa-wallet"></i> Fund Summary
            </button>
            <button type="button"
                    class="tab-btn<?= $default_main_tab === 'tranches' ? ' active' : '' ?>"
                    id="gt-main-tab-btn-tranches"
                    role="tab"
                    aria-selected="<?= $default_main_tab === 'tranches' ? 'true' : 'false' ?>"
                    aria-controls="gt-main-panel-tranches"
                    onclick="showGtMainPanel('tranches')">
              <i class="fa fa-bars-progress"></i> Tranche Review &amp; Disbursement
              <?php if ($has_pending_review): ?><span class="tab-attn-dot" title="Awaiting review"></span><?php endif; ?>
            </button>
          </div>

          <div class="card-body">

          <!-- LoA panel -->
          <div class="tab-content<?= $default_main_tab === 'loa' ? ' active' : '' ?>" id="gt-main-panel-loa" role="tabpanel">
          <h3 class="card-title mb-16"><i class="fa fa-file-signature"></i> Letter of Agreement - <?= h($selected_venture['name']) ?></h3>

          <?php if ($selected_loa && !empty($selected_loa['generated_file_path'])): ?>
            <p class="mb-6">
              Status: <strong><?= h(ucfirst($selected_loa['status'])) ?></strong>
              &middot;
              <a href="../includes/grant-process.php?action=download_loa&id=<?= (int)$selected_loa['id'] ?>">
                <i class="fa fa-download"></i> Download current version (PDF)
              </a>
            </p>

            <p class="gt-meta-line">
              <?php $downloadCount = (int)($selected_loa['download_count'] ?? 0); ?>
              <?php if ($downloadCount > 0): ?>
                <i class="fa fa-circle-check text-success"></i>
                Downloaded by venture <?= $downloadCount ?> time<?= $downloadCount === 1 ? '' : 's' ?>
                <?php if (!empty($selected_loa['last_downloaded_at'])): ?>
                  &middot; last on <?= h((new DateTime($selected_loa['last_downloaded_at']))->format('j M Y, g:i A')) ?>
                <?php endif; ?>
              <?php else: ?>
                <i class="fa fa-circle-xmark text-border"></i>
                Not yet downloaded by the venture
              <?php endif; ?>
            </p>

            <?php $signedStatus = $selected_loa['signed_status'] ?? null; ?>
            <?php if ($signedStatus === 'confirmed'): ?>
              <div class="alert alert-success mb-14">
                <i class="fa fa-check" aria-hidden="true"></i>
                <div>
                  Signed agreement confirmed
                  <?php if (!empty($selected_loa['signed_confirmed_at'])): ?>
                    on <?= h((new DateTime($selected_loa['signed_confirmed_at']))->format('j M Y')) ?>
                  <?php endif; ?>
                  &middot;
                  <a href="../includes/grant-process.php?action=download_signed_loa&id=<?= (int)$selected_loa['id'] ?>" target="_blank" rel="noopener">
                    <i class="fa fa-eye"></i> View signed copy
                  </a>
                </div>
              </div>
            <?php elseif ($signedStatus === 'pending'): ?>
              <div class="alert alert-warning mb-14">
                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                <div>
                  <strong>Signed copy awaiting confirmation</strong>
                  <?php if (!empty($selected_loa['signed_uploaded_at'])): ?>
                    &middot; uploaded <?= h((new DateTime($selected_loa['signed_uploaded_at']))->format('j M Y')) ?>
                  <?php endif; ?>
                  <div class="inline-form-row">
                    <a href="../includes/grant-process.php?action=download_signed_loa&id=<?= (int)$selected_loa['id'] ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">
                      <i class="fa fa-eye"></i> Review Signed Copy
                    </a>
                    <form method="POST" action="../includes/grant-process.php" class="inline-form">
                      <input type="hidden" name="action" value="confirm_signed_loa">
                      <input type="hidden" name="loa_id" value="<?= (int)$selected_loa['id'] ?>">
                      <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-check"></i> Confirm Signed Agreement</button>
                    </form>
                  </div>
                </div>
              </div>
            <?php else: ?>
              <p class="gt-empty-note">The venture has not uploaded a signed copy yet.</p>
            <?php endif; ?>
          <?php endif; ?>

          <form method="POST" action="../includes/grant-process.php">
            <input type="hidden" name="action" value="save_and_generate_loa">
            <input type="hidden" name="venture_id" value="<?= (int)$selected_venture['id'] ?>">

            <div class="form-grid form-grid-2">
              <div class="form-group">
                <label>Fellow Legal Name</label>
                <input type="text" class="form-control" name="fellow_legal_name" value="<?= h($selected_loa['fellow_legal_name'] ?? $selected_venture['name']) ?>" required>
              </div>
              <div class="form-group">
                <label>Legal Entity Type</label>
                <input type="text" class="form-control" name="entity_type" placeholder="e.g. private limited company" value="<?= h($selected_loa['entity_type'] ?? '') ?>" required>
              </div>
              <div class="form-group full">
                <label>Registered Address</label>
                <input type="text" class="form-control" name="fellow_address" value="<?= h($selected_loa['fellow_address'] ?? '') ?>" required>
              </div>
              <div class="form-group">
                <label>Representative Title</label>
                <input type="text" class="form-control" name="rep_title" placeholder="e.g. Director/CEO" value="<?= h($selected_loa['rep_title'] ?? '') ?>" required>
              </div>
              <div class="form-group">
                <label>Representative Name</label>
                <input type="text" class="form-control" name="rep_name" value="<?= h($selected_loa['rep_name'] ?? '') ?>" required>
              </div>
              <div class="form-group">
                <label>Effective Date</label>
                <input type="date" class="form-control" name="effective_date" value="<?= h($selected_loa['effective_date'] ?? '') ?>" required>
              </div>
              <div class="form-group">
                <label>End Date</label>
                <input type="date" class="form-control" name="end_date" value="<?= h($selected_loa['end_date'] ?? '') ?>" required>
              </div>
              <div class="form-group">
                <label>Total Grant (USD)</label>
                <input type="number" step="0.01" class="form-control" name="grant_total" value="<?= h($selected_loa['grant_total'] ?? '70000.00') ?>" required>
              </div>
              <div class="form-group">
                <label>Total Grant (in words)</label>
                <input type="text" class="form-control" name="grant_total_words" value="<?= h($selected_loa['grant_total_words'] ?? 'Seventy Thousand') ?>" required>
              </div>
            </div>

            <button type="submit" class="btn btn-primary mt-16">
              <i class="fa fa-file-word"></i> Save &amp; Generate Agreement
            </button>
          </form>
          </div>

        <!-- Fund summary -->
          <div class="tab-content<?= $default_main_tab === 'fund' ? ' active' : '' ?>" id="gt-main-panel-fund" role="tabpanel">
            <h3 class="card-title mb-16"><i class="fa fa-wallet"></i> Fund Summary</h3>
            <?php if ($selected_fund_summary): ?>
              <div class="stats-grid mb-0">
                <div class="stat-mini"><div class="num">$<?= number_format((float)$selected_fund_summary['total_committed']) ?></div><div class="label">Committed</div></div>
                <div class="stat-mini"><div class="num">$<?= number_format((float)$selected_fund_summary['total_disbursed']) ?></div><div class="label">Disbursed</div></div>
                <div class="stat-mini"><div class="num">$<?= number_format((float)$selected_fund_summary['total_pending']) ?></div><div class="label">Pending</div></div>
                <div class="stat-mini"><div class="num">$<?= number_format((float)$selected_fund_summary['total_remaining']) ?></div><div class="label">Remaining</div></div>
              </div>
            <?php else: ?>
              <p class="text-muted">No fund data yet for this venture.</p>
            <?php endif; ?>
          </div>

        <!-- Tranches -->
          <div class="tab-content<?= $default_main_tab === 'tranches' ? ' active' : '' ?>" id="gt-main-panel-tranches" role="tabpanel">
          <h3 class="card-title mb-16"><i class="fa fa-bars-progress"></i> Tranche Review &amp; Disbursement</h3>

          <?php if (empty($selected_tranches)): ?>
            <p class="text-muted">No tranches yet - generate the Letter of Agreement above to seed the standard schedule.</p>
          <?php else: ?>

            <div class="card">

              <div class="tabs" role="tablist">
                <?php foreach ($selected_tranches as $idx => $t): ?>
                  <?php
                    $tid = (int)$t['id'];
                    $needsAttention = !empty($t['latest_submission']) && $t['latest_submission']['review_status'] === 'pending';
                  ?>
                  <button type="button"
                          class="tab-btn<?= $idx === $default_tranche_index ? ' active' : '' ?>"
                          id="gt-tab-btn-<?= $tid ?>"
                          role="tab"
                          aria-selected="<?= $idx === $default_tranche_index ? 'true' : 'false' ?>"
                          aria-controls="gt-tranche-panel-<?= $tid ?>"
                          onclick="showGtTranchePanel(<?= $tid ?>)">
                    <?php if ($needsAttention): ?><span class="tab-attn-dot" title="Awaiting review"></span><?php endif; ?>
                    <span><?= h($t['label']) ?></span>
                    <span class="gt-tab-amount">$<?= number_format((float)$t['amount']) ?></span>
                    <span class="badge <?= h($status_badges[$t['status']] ?? 'badge-gray') ?>">
                      <?= h($status_labels[$t['status']] ?? $t['status']) ?>
                    </span>
                  </button>
                <?php endforeach; ?>
              </div>

              <div class="card-body">
              <?php foreach ($selected_tranches as $idx => $t): ?>
                <?php $tid = (int)$t['id']; $latest = $t['latest_submission']; ?>
                <div class="tab-content<?= $idx === $default_tranche_index ? ' active' : '' ?>" id="gt-tranche-panel-<?= $tid ?>" role="tabpanel">

                  <div class="page-header mb-12">
                    <div>
                      <strong><?= h($t['label']) ?></strong>
                      <div class="gt-phase"><?= h($t['phase']) ?></div>
                    </div>
                    <div class="text-right">
                      <div class="gt-tranche-amount">$<?= number_format((float)$t['amount']) ?></div>
                      <span class="badge <?= h($status_badges[$t['status']] ?? 'badge-gray') ?>"><?= h($status_labels[$t['status']] ?? $t['status']) ?></span>
                    </div>
                  </div>

                  <div class="form-section mb-12">
                    <div class="gt-submission-meta"><strong>Administrator Controls</strong></div>

                    <form method="POST"
                          action="../includes/grant-process.php"
                          class="inline-form-row">
                      <input type="hidden" name="action" value="update_tranche_status">
                      <input type="hidden" name="tranche_id" value="<?= $tid ?>">
                      <input type="hidden" name="venture_id" value="<?= (int)$selected_venture['id'] ?>">

                      <select name="status" class="form-control gt-field-md" required>
                        <?php foreach ($status_labels as $statusValue => $statusLabel): ?>
                          <option
                            value="<?= h($statusValue) ?>"
                            <?= $t['status'] === $statusValue ? 'selected' : '' ?>
                          >
                            <?= h($statusLabel) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>

                      <button type="submit" class="btn btn-secondary btn-sm">
                        <i class="fa fa-floppy-disk"></i>
                        Save Status
                      </button>
                    </form>

                    <?php if ($t['status'] !== 'disbursed'): ?>
                      <form method="POST"
                            action="../includes/grant-process.php"
                            class="inline-form-row mt-8"
                            onsubmit="return confirm('Allow this venture to submit a new version for this tranche?');">
                        <input type="hidden" name="action" value="allow_tranche_resubmission">
                        <input type="hidden" name="tranche_id" value="<?= $tid ?>">
                        <input type="hidden" name="venture_id" value="<?= (int)$selected_venture['id'] ?>">

                        <input
                          type="text"
                          name="review_notes"
                          class="form-control gt-review-notes"
                          value="Please update the requested evidence and resubmit this tranche."
                          placeholder="Reason or instructions for resubmission"
                        >

                        <button type="submit" class="btn btn-secondary btn-sm">
                          <i class="fa fa-rotate-right"></i>
                          Allow Resubmission
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>

                  <?php if ($latest): ?>
                    <div class="form-section">
                      <div class="gt-submission-meta">
                        Submitted <?= h((new DateTime($latest['submitted_at']))->format('j M Y, g:i A')) ?>
                        &middot; Review status: <strong><?= h(ucfirst(str_replace('_', ' ', $latest['review_status']))) ?></strong>
                      </div>

                      <?php if (!empty($latest['narrative'])): ?>
                        <div class="resp-a mb-8"><span class="resp-label">Narrative</span><?= nl2br(h($latest['narrative'])) ?></div>
                      <?php endif; ?>

                      <?php foreach ($latest['responses'] as $r): ?>
                        <div class="resp-a mb-8">
                          <span class="resp-label"><?= h($r['requirement_text']) ?></span>
                          <?= nl2br(h($r['response_text'])) ?>
                        </div>
                      <?php endforeach; ?>

                      <?php if (!empty($latest['files'])): ?>
                        <div class="mt-8">
                          <?php foreach ($latest['files'] as $f): ?>
                            <div class="gt-file-row">
                              <i class="fa fa-paperclip"></i>
                              <span>
                                <?= h($f['file_label'] ?: $f['file_name']) ?>
                                -
                                <a
                                  href="/uploads/tranche-evidence/<?= rawurlencode((string)$f['file_path']) ?>"
                                  target="_blank"
                                  rel="noopener"
                                >
                                  view
                                </a>
                              </span>

                              <form
                                method="POST"
                                action="../includes/grant-process.php"
                                class="inline-form"
                                onsubmit="return confirm('Delete this submitted file permanently?');"
                              >
                                <input type="hidden" name="action" value="delete_tranche_submission_file">
                                <input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
                                <input type="hidden" name="venture_id" value="<?= (int)$selected_venture['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">
                                  <i class="fa fa-trash"></i>
                                  Delete
                                </button>
                              </form>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>

                      <?php if ($latest['review_status'] === 'pending'): ?>
                        <form method="POST" action="../includes/grant-process.php" class="inline-form-row">
                          <input type="hidden" name="action" value="review_tranche_submission">
                          <input type="hidden" name="submission_id" value="<?= (int)$latest['id'] ?>">
                          <input type="text" name="review_notes" placeholder="Review notes (required for changes/rejection)" class="form-control gt-review-notes">
                          <button type="submit" name="decision" value="approved" class="btn btn-primary btn-sm"><i class="fa fa-check"></i> Approve</button>
                          <button type="submit" name="decision" value="changes_requested" class="btn btn-secondary btn-sm"><i class="fa fa-rotate-right"></i> Request Changes</button>
                          <button type="submit" name="decision" value="rejected" class="btn btn-danger btn-sm"><i class="fa fa-xmark"></i> Reject</button>
                        </form>
                      <?php endif; ?>

                      <?php if ($t['status'] !== 'disbursed'): ?>
                        <form
                          method="POST"
                          action="../includes/grant-process.php"
                          class="inline-form-row mt-12"
                          onsubmit="return confirm('Delete this complete tranche submission, its responses and uploaded files? This cannot be undone.');"
                        >
                          <input type="hidden" name="action" value="delete_tranche_submission">
                          <input type="hidden" name="submission_id" value="<?= (int)$latest['id'] ?>">
                          <input type="hidden" name="venture_id" value="<?= (int)$selected_venture['id'] ?>">

                          <button type="submit" class="btn btn-danger btn-sm">
                            <i class="fa fa-trash"></i>
                            Delete Submission
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  <?php else: ?>
                    <p class="gt-empty-note mt-12">No submission yet for this tranche.</p>
                  <?php endif; ?>

                  <?php if ($t['status'] === 'approved' && !$t['disbursement']): ?>
                    <form method="POST" action="../includes/grant-process.php" class="inline-form-row">
                      <input type="hidden" name="action" value="record_disbursement">
                      <input type="hidden" name="tranche_id" value="<?= (int)$t['id'] ?>">
                      <input type="hidden" name="venture_id" value="<?= (int)$selected_venture['id'] ?>">
                      <input type="number" step="0.01" class="form-control" name="amount" value="<?= h($t['amount']) ?>" required placeholder="Amount" class="form-control gt-field-sm">
                      <input type="date" name="disbursed_date" required class="form-control gt-field-md">
                      <input type="text" name="transaction_ref" placeholder="Transaction ref" class="form-control gt-field-md">
                      <input type="text" name="payment_method" placeholder="Payment method" value="Bank Transfer" class="form-control gt-field-md">
                      <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-money-bill-wave"></i> Record Disbursement</button>
                    </form>
                  <?php endif; ?>

                </div>
              <?php endforeach; ?>
              </div>

            </div>

          <?php endif; ?>
          </div>

          </div><!-- /card-body -->
        </div><!-- /card -->

      <?php endif; ?>
  </div>

  </div><!-- /admin-content -->
</div>

<script>
function buildGtUrl(overrides) {
  var params = new URLSearchParams(window.location.search);
  Object.keys(overrides).forEach(function(key) {
    var val = overrides[key];
    if (val === null || val === '') {
      params.delete(key);
    } else {
      params.set(key, val);
    }
  });
  return window.location.pathname + '?' + params.toString();
}

function openGtVentureDropdown() {
  document.getElementById('ventureDropdown').classList.add('open');
}

function filterGtVentureDropdown(query) {
  var dropdown = document.getElementById('ventureDropdown');
  dropdown.classList.add('open');

  var q = query.trim().toLowerCase();
  var options = dropdown.querySelectorAll('.search-dropdown-item');
  var visibleCount = 0;

  options.forEach(function(opt) {
    var matches = opt.getAttribute('data-name').indexOf(q) !== -1;
    opt.style.display = matches ? '' : 'none';
    if (matches) visibleCount++;
  });

  dropdown.querySelector('.no-results').classList.toggle('is-hidden', visibleCount !== 0);
}

document.addEventListener('click', function(e) {
  var wrap = document.querySelector('.search-bar-flex');
  var dropdown = document.getElementById('ventureDropdown');
  if (wrap && dropdown && !wrap.contains(e.target)) {
    dropdown.classList.remove('open');
  }
});

function showGtMainPanel(id) {
  document.querySelectorAll('#gt-main-panel-loa, #gt-main-panel-fund, #gt-main-panel-tranches').forEach(function(panel) {
    panel.classList.remove('active');
  });
  document.querySelectorAll('#gt-main-tab-btn-loa, #gt-main-tab-btn-fund, #gt-main-tab-btn-tranches').forEach(function(tab) {
    tab.classList.remove('active');
    tab.setAttribute('aria-selected', 'false');
  });

  var panel = document.getElementById('gt-main-panel-' + id);
  var tabBtn = document.getElementById('gt-main-tab-btn-' + id);
  if (panel) panel.classList.add('active');
  if (tabBtn) {
    tabBtn.classList.add('active');
    tabBtn.setAttribute('aria-selected', 'true');
  }
}

function showGtTranchePanel(id) {
  document.querySelectorAll('[id^="gt-tranche-panel-"]').forEach(function(panel) {
    panel.classList.remove('active');
  });
  document.querySelectorAll('[id^="gt-tab-btn-"]').forEach(function(tab) {
    tab.classList.remove('active');
    tab.setAttribute('aria-selected', 'false');
  });

  var panel = document.getElementById('gt-tranche-panel-' + id);
  var tabBtn = document.getElementById('gt-tab-btn-' + id);
  if (panel) panel.classList.add('active');
  if (tabBtn) {
    tabBtn.classList.add('active');
    tabBtn.setAttribute('aria-selected', 'true');
  }
}
</script>
</body>
</html>