<?php
declare(strict_types=1);

require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/grant-functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page_title  = 'Grant & Funding';
$current_nav = 'tranches.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection error.');
}

$conn->set_charset('utf8mb4');

// All dates/times display and store as Africa/Nairobi (EAT, UTC+3).
date_default_timezone_set('Africa/Nairobi');
$conn->query("SET time_zone = '+03:00'");

$venture_id = (int)($venture_id ?? ($_SESSION['venture_id'] ?? 0));

if ($venture_id <= 0) {
    header('Location: login.php');
    exit;
}

$stmt = $conn->prepare("SELECT * FROM loa_agreements WHERE venture_id = ? LIMIT 1");
$stmt->bind_param('i', $venture_id);
$stmt->execute();
$loa = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

$tranches = get_venture_tranches($conn, $venture_id);
$fund_summary = get_venture_fund_summary($conn, $venture_id);

$status_labels = [
    'locked'             => 'Locked',
    'open'               => 'Open for Submission',
    'submitted'          => 'Submitted - Under Review',
    'changes_requested'  => 'Changes Requested',
    'rejected'           => 'Rejected - Resubmission Allowed',
    'approved'           => 'Approved - Awaiting Disbursement',
    'disbursed'          => 'Disbursed',
];

$status_badges = [
    'locked'             => 'badge-muted',
    'open'               => 'badge-gold',
    'submitted'          => 'badge-warning',
    'changes_requested'  => 'badge-danger',
    'rejected'           => 'badge-danger',
    'approved'           => 'badge-success',
    'disbursed'          => 'badge-success',
];

include 'layout.php';
?>

<style>
  .funding-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px}
  .funding-stat{background:#fff;border:1px solid #eef0f2;border-radius:12px;padding:18px 20px}
  .funding-stat .num{font-size:26px;font-weight:800;color:#2c3e50}
  .funding-stat .label{font-size:12.5px;color:#7f8c8d;margin-top:4px}

  .loa-card{background:#fff;border:1px solid #eef0f2;border-radius:12px;padding:20px 22px;margin-bottom:24px}
  .loa-card-top{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}

  .signed-block{margin-top:16px;padding-top:16px;border-top:1px dashed #e1e4e8}
  .signed-status-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .signed-pill{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:20px;font-size:12px;font-weight:700}
  .signed-pill.pending{background:#fff7ea;color:#a5680a}
  .signed-pill.confirmed{background:#eafaf1;color:#1e7e46}
  .signed-upload-form{margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .signed-upload-form input[type=file]{flex:1;min-width:220px;padding:8px;border:1.5px solid #e1e4e8;border-radius:8px;font-size:13px}
  .signed-help{font-size:12px;color:#7f8c8d;margin-top:6px}

  /* Tab navigation */
  .tranche-tabs-wrap{background:#fff;border:1px solid #eef0f2;border-radius:12px;overflow:hidden}
  .tranche-tabs{display:flex;overflow-x:auto;border-bottom:1px solid #eef0f2;background:#fafbfc}
  .tranche-tab{flex:0 0 auto;display:flex;flex-direction:column;align-items:flex-start;gap:6px;padding:14px 20px;border:none;background:transparent;cursor:pointer;font-family:inherit;border-bottom:3px solid transparent;white-space:nowrap;text-align:left;transition:background .15s, border-color .15s}
  .tranche-tab:hover{background:#f2f4f6}
  .tranche-tab .tab-label{font-size:13.5px;font-weight:700;color:#2c3e50}
  .tranche-tab .tab-amount{font-size:12px;color:#ff5722;font-weight:700}
  .tranche-tab.active{background:#fff;border-bottom-color:#ff5722}
  .tranche-tab.active .tab-label{color:#ff5722}

  .tranche-panel{display:none;padding:24px}
  .tranche-panel.active{display:block}

  .tranche-panel-top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:8px}
  .tranche-title{font-size:17px;font-weight:700;color:#2c3e50;margin:0 0 4px}
  .tranche-meta{font-size:12.5px;color:#7f8c8d}
  .tranche-amount{font-size:22px;font-weight:800;color:#ff5722}

  .req-list{margin:14px 0;padding:0;list-style:none}
  .req-list li{display:flex;gap:8px;padding:6px 0;font-size:13.5px;color:#34495e;border-bottom:1px solid #f5f6fa}
  .req-list li:last-child{border-bottom:none}
  .req-list i{color:#f0a500;margin-top:2px}

  .tranche-form{display:none;margin-top:16px;padding-top:16px;border-top:1px dashed #e1e4e8}
  .tranche-form.open{display:block}
  .tranche-form textarea, .tranche-form input[type=text]{width:100%;padding:10px 12px;border:1.5px solid #e1e4e8;border-radius:8px;font-size:13.5px;font-family:inherit;margin-bottom:10px}
  .req-response-block{margin-bottom:14px}
  .req-response-block label{display:block;font-size:12.5px;font-weight:600;color:#34495e;margin-bottom:6px}

  .review-note{background:#fdecea;border-left:3px solid #e74c3c;border-radius:6px;padding:10px 14px;margin-top:10px;font-size:13px;color:#7d1a1a}
  .disbursed-note{background:#eafaf1;border-left:3px solid #27ae60;border-radius:6px;padding:10px 14px;margin-top:10px;font-size:13px;color:#1e7e46}

  @media (max-width: 640px){
    .tranche-tab{padding:12px 14px}
    .signed-upload-form{flex-direction:column;align-items:stretch}
  }
</style>

<div class="sessions-page">

  <div class="sessions-header">
    <div>
      <h2 class="sessions-title">Grant &amp; Funding</h2>
      <p class="sessions-subtitle">Your Letter of Agreement, tranche milestones, and disbursement history</p>
    </div>
  </div>

  <?php if (isset($_GET['submitted'])): ?>
    <div class="disbursed-note" style="margin-bottom:16px">
      <i class="fa fa-check"></i> Your tranche submission has been sent successfully and is now under review.
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['signed_uploaded'])): ?>
    <div class="disbursed-note" style="margin-bottom:16px">
      <i class="fa fa-check"></i> Signed agreement uploaded - the team will review and confirm receipt.
    </div>
  <?php endif; ?>

  <div class="funding-summary-grid">
    <div class="funding-stat">
      <div class="num">$<?= number_format((float)$fund_summary['total_committed']) ?></div>
      <div class="label">Total Committed</div>
    </div>
    <div class="funding-stat">
      <div class="num">$<?= number_format((float)$fund_summary['total_disbursed']) ?></div>
      <div class="label">Disbursed to Date</div>
    </div>
    <div class="funding-stat">
      <div class="num">$<?= number_format((float)$fund_summary['total_pending']) ?></div>
      <div class="label">Pending Review/Approval</div>
    </div>
    <div class="funding-stat">
      <div class="num">$<?= number_format((float)$fund_summary['total_remaining']) ?></div>
      <div class="label">Remaining to Disburse</div>
    </div>
  </div>

  <?php if ($loa): ?>
    <div class="loa-card">
      <div class="loa-card-top">
        <div>
          <strong>Letter of Agreement</strong>
          <div style="font-size:13px;color:#7f8c8d;margin-top:4px">
            Status: <?= h(ucfirst($loa['status'])) ?>
            <?php if (!empty($loa['effective_date'])): ?>
              &middot; Effective <?= h((new DateTime($loa['effective_date']))->format('j M Y')) ?>
              to <?= h((new DateTime($loa['end_date']))->format('j M Y')) ?>
            <?php endif; ?>
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <a href="/includes/grant-process.php?action=download_loa&id=<?= (int)$loa['id'] ?>&mode=preview" target="_blank" rel="noopener" class="btn btn-outline btn-sm">
            <i class="fa fa-eye"></i> Preview
          </a>
          <a href="/includes/grant-process.php?action=download_loa&id=<?= (int)$loa['id'] ?>&mode=download" class="btn btn-primary btn-sm">
            <i class="fa fa-download"></i> Download Agreement (PDF)
          </a>
        </div>
      </div>

      <?php if (!empty($loa['generated_file_path'])): ?>
        <div class="signed-block">
          <?php if (($loa['signed_status'] ?? null) === 'confirmed'): ?>
            <div class="signed-status-row">
              <span class="signed-pill confirmed">
                <i class="fa fa-check-circle"></i> Signed copy confirmed
                <?php if (!empty($loa['signed_confirmed_at'])): ?>
                  on <?= h((new DateTime($loa['signed_confirmed_at']))->format('j M Y')) ?>
                <?php endif; ?>
              </span>
              <a href="/includes/grant-process.php?action=download_signed_loa&id=<?= (int)$loa['id'] ?>" target="_blank" rel="noopener" class="btn btn-outline btn-sm">
                <i class="fa fa-eye"></i> View Signed Copy
              </a>
            </div>
          <?php elseif (($loa['signed_status'] ?? null) === 'pending'): ?>
            <div class="signed-status-row">
              <span class="signed-pill pending">
                <i class="fa fa-clock"></i> Signed copy uploaded
                <?php if (!empty($loa['signed_uploaded_at'])): ?>
                  on <?= h((new DateTime($loa['signed_uploaded_at']))->format('j M Y')) ?>
                <?php endif; ?>
                &middot; awaiting confirmation
              </span>
              <a href="/includes/grant-process.php?action=download_signed_loa&id=<?= (int)$loa['id'] ?>" target="_blank" rel="noopener" class="btn btn-outline btn-sm">
                <i class="fa fa-eye"></i> View What You Uploaded
              </a>
            </div>
            <form method="POST" action="/includes/grant-process.php" enctype="multipart/form-data" class="signed-upload-form">
              <input type="hidden" name="action" value="upload_signed_loa">
              <input type="hidden" name="loa_id" value="<?= (int)$loa['id'] ?>">
              <input type="file" name="signed_file" accept=".pdf,.jpg,.jpeg,.png" required>
              <button type="submit" class="btn btn-outline btn-sm"><i class="fa fa-rotate"></i> Replace Upload</button>
            </form>
          <?php else: ?>
            <p style="font-size:13px;color:#34495e;margin-bottom:10px">
              Once you've signed the downloaded agreement, upload the signed copy here for the team to confirm.
            </p>
            <form method="POST" action="/includes/grant-process.php" enctype="multipart/form-data" class="signed-upload-form">
              <input type="hidden" name="action" value="upload_signed_loa">
              <input type="hidden" name="loa_id" value="<?= (int)$loa['id'] ?>">
              <input type="file" name="signed_file" accept=".pdf,.jpg,.jpeg,.png" required>
              <button type="submit" class="btn btn-gold btn-sm"><i class="fa fa-upload"></i> Upload Signed Agreement</button>
            </form>
            <div class="signed-help">Accepts a signed PDF, or a clear photo/scan (JPG or PNG) of the signed pages.</div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="loa-card">
      <div>Your Letter of Agreement has not yet been generated. Your Business Analyst will share this once your onboarding details are confirmed.</div>
    </div>
  <?php endif; ?>

  <h3 style="margin:24px 0 12px">Tranche Milestones</h3>

  <div class="tranche-tabs-wrap">

    <div class="tranche-tabs" role="tablist">
      <?php foreach ($tranches as $i => $t): ?>
        <?php $tid = (int)$t['id']; ?>
        <button type="button"
                class="tranche-tab<?= $i === 0 ? ' active' : '' ?>"
                id="tranche-tab-btn-<?= $tid ?>"
                role="tab"
                aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"
                aria-controls="tranche-panel-<?= $tid ?>"
                onclick="showTranchePanel(<?= $tid ?>)">
          <span class="tab-label"><?= h($t['label']) ?></span>
          <span class="tab-amount">$<?= number_format((float)$t['amount']) ?></span>
          <span class="badge <?= h($status_badges[$t['status']] ?? 'badge-muted') ?>">
            <?= h($status_labels[$t['status']] ?? $t['status']) ?>
          </span>
        </button>
      <?php endforeach; ?>
    </div>

    <?php foreach ($tranches as $i => $t): ?>
      <?php
        $tid = (int)$t['id'];
        $latest = $t['latest_submission'];
        $canSubmit = in_array($t['status'], ['open', 'changes_requested', 'rejected'], true);
      ?>
      <div class="tranche-panel<?= $i === 0 ? ' active' : '' ?>" id="tranche-panel-<?= $tid ?>" role="tabpanel">

        <div class="tranche-panel-top">
          <div>
            <h4 class="tranche-title"><?= h($t['label']) ?></h4>
            <div class="tranche-meta">
              <?= h($t['phase']) ?>
              <?php if (!empty($t['period_start'])): ?>
                &middot; <?= h((new DateTime($t['period_start']))->format('M Y')) ?> - <?= h((new DateTime($t['period_end']))->format('M Y')) ?>
              <?php endif; ?>
            </div>
          </div>
          <div style="text-align:right">
            <div class="tranche-amount">$<?= number_format((float)$t['amount']) ?></div>
            <span class="badge <?= h($status_badges[$t['status']] ?? 'badge-muted') ?>">
              <?= h($status_labels[$t['status']] ?? $t['status']) ?>
            </span>
          </div>
        </div>

        <?php if (!empty($t['requirements'])): ?>
          <ul class="req-list">
            <?php foreach ($t['requirements'] as $req): ?>
              <li><i class="fa fa-check-circle"></i> <?= h($req['requirement_text']) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if ($latest && in_array($latest['review_status'], ['changes_requested', 'rejected'], true) && !empty($latest['review_notes'])): ?>
          <div class="review-note">
            <strong><?= $latest['review_status'] === 'rejected' ? 'Submission rejected:' : 'Changes requested:' ?></strong>
            <?= nl2br(h($latest['review_notes'])) ?>
            <?php if ($latest['review_status'] === 'rejected'): ?>
              <div style="margin-top:6px;font-weight:600">
                You may correct the issues above and resubmit this tranche for review.
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($t['disbursement']): ?>
          <div class="disbursed-note">
            <i class="fa fa-check"></i> Disbursed $<?= number_format((float)$t['disbursement']['amount']) ?>
            on <?= h((new DateTime($t['disbursement']['disbursed_date']))->format('j M Y')) ?>
            <?php if (!empty($t['disbursement']['transaction_ref'])): ?>
              (Ref: <?= h($t['disbursement']['transaction_ref']) ?>)
            <?php endif; ?>
            <?php if (!$t['disbursement']['confirmation_received']): ?>
              <form method="POST" action="/includes/grant-process.php" style="display:inline;margin-left:10px">
                <input type="hidden" name="action" value="confirm_disbursement_receipt">
                <input type="hidden" name="disbursement_id" value="<?= (int)$t['disbursement']['id'] ?>">
                <button type="submit" class="btn btn-outline btn-sm">Confirm Receipt</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($canSubmit): ?>
          <button type="button" class="btn btn-primary btn-sm" style="margin-top:12px" onclick="toggleTrancheForm(<?= $tid ?>)">
            <i class="fa fa-upload"></i> <?= in_array($t['status'], ['changes_requested', 'rejected'], true) ? 'Resubmit' : 'Submit Requirements' ?>
          </button>

          <form method="POST" action="/includes/grant-process.php" enctype="multipart/form-data" class="tranche-form" id="tranche-form-<?= $tid ?>">
            <input type="hidden" name="action" value="submit_tranche">
            <input type="hidden" name="tranche_id" value="<?= $tid ?>">
            <input type="hidden" name="venture_id" value="<?= $venture_id ?>">

            <?php foreach ($t['requirements'] as $req): ?>
              <div class="req-response-block">
                <label><?= h($req['requirement_text']) ?></label>
                <textarea name="responses[<?= (int)$req['id'] ?>]" rows="2" placeholder="Describe how this requirement is met..."></textarea>
              </div>
            <?php endforeach; ?>

            <label style="display:block;font-size:12.5px;font-weight:600;color:#34495e;margin-bottom:6px">Overall narrative (optional)</label>
            <textarea name="narrative" rows="3" placeholder="Any additional context for the review team..."></textarea>

            <label style="display:block;font-size:12.5px;font-weight:600;color:#34495e;margin-bottom:6px">Supporting documents</label>
            <input type="file" name="evidence[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png">
            <small style="color:#7f8c8d;font-size:11px;display:block;margin-bottom:10px">Work plans, budgets, reports, receipts, etc.</small>

            <button type="submit" class="btn btn-gold btn-sm"><i class="fa fa-paper-plane"></i> Submit for Review</button>
          </form>
        <?php endif; ?>

      </div>
    <?php endforeach; ?>

  </div>

</div>

<script>
function showTranchePanel(id) {
  document.querySelectorAll('.tranche-panel').forEach(function(panel) {
    panel.classList.remove('active');
  });
  document.querySelectorAll('.tranche-tab').forEach(function(tab) {
    tab.classList.remove('active');
    tab.setAttribute('aria-selected', 'false');
  });

  const panel = document.getElementById('tranche-panel-' + id);
  const tabBtn = document.getElementById('tranche-tab-btn-' + id);
  if (panel) panel.classList.add('active');
  if (tabBtn) {
    tabBtn.classList.add('active');
    tabBtn.setAttribute('aria-selected', 'true');
  }
}

function toggleTrancheForm(id) {
  const el = document.getElementById('tranche-form-' + id);
  if (el) el.classList.toggle('open');
}
</script>