<?php
require_once __DIR__ . '/includes/process-dashboard.php';
$site_url = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';

/**
 * Additional programme-wide dashboard statistics.
 * These are intentionally calculated here so the existing
 * includes/process-dashboard.php remains backward compatible.
 */
if (!function_exists('dashboard_table_exists')) {
    function dashboard_table_exists(mysqli $conn, string $table): bool
    {
        $table = trim($table);
        if ($table === '') {
            return false;
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $table);
        $stmt->execute();
        $exists = (int)($stmt->get_result()->fetch_row()[0] ?? 0) > 0;
        $stmt->close();

        return $exists;
    }
}

if (!function_exists('dashboard_column_exists')) {
    function dashboard_column_exists(mysqli $conn, string $table, string $column): bool
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $exists = (int)($stmt->get_result()->fetch_row()[0] ?? 0) > 0;
        $stmt->close();

        return $exists;
    }
}

if (!function_exists('dashboard_scalar')) {
    function dashboard_scalar(mysqli $conn, string $sql, float|int $default = 0): float|int
    {
        try {
            $result = $conn->query($sql);

            if (!$result instanceof mysqli_result) {
                return $default;
            }

            $row = $result->fetch_row();
            $value = $row[0] ?? $default;

            return is_numeric($value) ? $value + 0 : $default;
        } catch (Throwable $e) {
            error_log('[dashboard.php] Statistic query failed: ' . $e->getMessage());
            return $default;
        }
    }
}

/* Total learner records reported by all ventures: New + Returning learners. */
$stats['overall_learners'] = dashboard_table_exists($conn, 'venture_participants')
    ? (int)dashboard_scalar($conn, "SELECT COUNT(*) FROM venture_participants", 0)
    : 0;

/* Total schools/institutions reached across all ventures. */
$stats['schools_reached'] = dashboard_table_exists($conn, 'meal_schools')
    ? (int)dashboard_scalar($conn, "SELECT COUNT(*) FROM meal_schools", 0)
    : 0;

/* Cumulative gross revenue reported by all ventures. */
$stats['total_revenue_ugx'] = (
    dashboard_table_exists($conn, 'meal_revenue')
    && dashboard_column_exists($conn, 'meal_revenue', 'gross_revenue_ugx')
)
    ? (float)dashboard_scalar(
        $conn,
        "SELECT COALESCE(SUM(gross_revenue_ugx), 0) FROM meal_revenue",
        0
    )
    : 0.0;

/*
 * Female-owned ventures.
 * The venture form stores venture ownership gender in ventures.owner_gender.
 */
$stats['female_venture_owners'] = (
    dashboard_table_exists($conn, 'ventures')
    && dashboard_column_exists($conn, 'ventures', 'owner_gender')
)
    ? (int)dashboard_scalar(
        $conn,
        "SELECT COUNT(*) FROM ventures
         WHERE LOWER(TRIM(COALESCE(owner_gender, ''))) = 'female'",
        0
    )
    : 0;

if (!function_exists('dashboard_money_short')) {
    function dashboard_money_short(float $amount): string
    {
        if ($amount >= 1000000000) {
            return 'UGX ' . number_format($amount / 1000000000, 1) . 'B';
        }

        if ($amount >= 1000000) {
            return 'UGX ' . number_format($amount / 1000000, 1) . 'M';
        }

        if ($amount >= 1000) {
            return 'UGX ' . number_format($amount / 1000, 1) . 'K';
        }

        return 'UGX ' . number_format($amount, 0);
    }
}


$favicon_path = rtrim(SITE_URL, '/') . '/assets/images/favicon.png';

$current_url = $site_url . ($_SERVER['REQUEST_URI'] ?? '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard - <?= h($site_name) ?> Admin</title>
 <link rel="icon" href="<?= h($favicon_path) ?>" type="image/png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="assets/css/admin.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/admin.css') ?>">
</head>
<body class="admin-system-page">

<?php include 'includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">

  <header class="admin-topbar">
    <div class="topbar-left">
      <div class="topbar-breadcrumb"><strong>Dashboard</strong></div>
    </div>

    <div class="topbar-right">
      <a href="messages.php" class="topbar-btn" title="Messages">
        <i class="fa fa-envelope"></i>
        <?php if ($stats['messages'] > 0): ?>
          <span class="badge-count"><?= (int)$stats['messages'] ?></span>
        <?php endif; ?>
      </a>

      <a href="<?= h(SITE_URL) ?>" target="_blank" class="topbar-btn" title="View site">
        <i class="fa fa-external-link-alt"></i>
      </a>

      <div class="admin-avatar">
        <div class="avatar-circle">
          <?php if (!empty($ADMIN['photo']) && file_exists('../' . $ADMIN['photo'])): ?>
            <img src="<?= h(SITE_URL . '/' . $ADMIN['photo']) ?>" alt="">
          <?php else: ?>
            <?= h(strtoupper(substr($ADMIN['full_name'] ?? 'A', 0, 1))) ?>
          <?php endif; ?>
        </div>

        <div class="avatar-info">
          <span class="avatar-name"><?= h($admin_first_name) ?></span>
          <span class="avatar-role"><?= h(str_replace('_', ' ', $ADMIN['role'] ?? 'Admin')) ?></span>
        </div>
      </div>
    </div>
  </header>

  <div class="admin-content">

    <?php if (function_exists('show_flash')) show_flash('global'); ?>

    <div class="legacy-style-4be5d83aab">
      <div>
        <h2 class="legacy-style-f05d08de38">
          Good <?= h($greeting) ?>, <?= h($admin_first_name) ?>!
        </h2>
        <p class="legacy-style-879ec047be">
          <?= h(date('l, F j, Y')) ?> . Managing <?= h($site_name) ?>
        </p>
      </div>

      <div class="legacy-style-6002dd784e">
        <a href="cohorts.php" class="btn btn-secondary btn-sm">
          <i class="fa fa-plus"></i> Add Cohort
        </a>
        <a href="ventures.php" class="btn btn-sm legacy-style-b4f7e237ad">
          <i class="fa fa-rocket"></i> Add Venture
        </a>
      </div>
    </div>

    <div class="stats-grid">
      <?php
      $cards = [
          [
              'url'   => 'meal-report.php?tab=participants',
              'color' => 'blue',
              'icon'  => 'fa-user-graduate',
              'value' => number_format((int)$stats['overall_learners']),
              'label' => 'Overall Learners',
          ],
          [
              'url'   => 'meal-report.php?tab=schools',
              'color' => 'green',
              'icon'  => 'fa-school',
              'value' => number_format((int)$stats['schools_reached']),
              'label' => 'Schools Reached',
          ],
          [
              'url'   => 'meal-report.php?tab=revenue',
              'color' => 'orange',
              'icon'  => 'fa-chart-line',
              'value' => dashboard_money_short((float)$stats['total_revenue_ugx']),
              'label' => 'Total Revenue',
          ],
          [
              'url'   => 'ventures.php',
              'color' => 'purple',
              'icon'  => 'fa-female',
              'value' => number_format((int)$stats['female_venture_owners']),
              'label' => 'Female Venture Owners',
          ],

          // Existing administration cards.
          [
              'url'   => 'cohorts.php',
              'color' => 'orange',
              'icon'  => 'fa-layer-group',
              'value' => number_format((int)$stats['cohorts']),
              'label' => 'Cohorts',
          ],
          [
              'url'   => 'ventures.php',
              'color' => 'teal',
              'icon'  => 'fa-rocket',
              'value' => number_format((int)$stats['ventures']),
              'label' => 'Ventures',
          ],
          [
              'url'   => 'venture-team.php',
              'color' => 'blue',
              'icon'  => 'fa-users',
              'value' => number_format((int)$stats['team']),
              'label' => 'Team Members',
          ],
          [
              'url'   => 'staff.php',
              'color' => 'purple',
              'icon'  => 'fa-user-tie',
              'value' => number_format((int)$stats['staff']),
              'label' => 'Staff & Mentors',
          ],
          [
              'url'   => 'assign-staff.php',
              'color' => 'green',
              'icon'  => 'fa-link',
              'value' => number_format((int)$stats['assignments']),
              'label' => 'Assignments',
          ],
          [
              'url'   => 'messages.php',
              'color' => $stats['messages'] > 0 ? 'red' : 'gray',
              'icon'  => 'fa-envelope',
              'value' => number_format((int)$stats['messages']),
              'label' => 'Unread Messages',
          ],
      ];
      ?>

      <?php foreach ($cards as $card): ?>
        <a href="<?= h($card['url']) ?>" style="text-decoration:none">
          <div class="stat-card">
            <div class="stat-icon <?= h($card['color']) ?>">
              <i class="fa <?= h($card['icon']) ?>"></i>
            </div>
            <div>
              <div class="stat-val"><?= h((string)$card['value']) ?></div>
              <div class="stat-label"><?= h($card['label']) ?></div>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="card dashboard-tabs-card">
      <div class="card-header">
        <div class="tabs" role="tablist" aria-label="Dashboard sections">
          <button type="button" class="tab-btn active" data-tab="tab-cohorts" role="tab" aria-selected="true">
            <i class="fa fa-layer-group"></i> Cohorts
          </button>

          <button type="button" class="tab-btn" data-tab="tab-messages" role="tab" aria-selected="false">
            <i class="fa fa-envelope"></i> Messages
            <?php if ($stats['messages'] > 0): ?>
              <span class="badge badge-orange"><?= (int)$stats['messages'] ?></span>
            <?php endif; ?>
          </button>

          <button type="button" class="tab-btn" data-tab="tab-ventures" role="tab" aria-selected="false">
            <i class="fa fa-rocket"></i> Recent ventures
          </button>
        </div>

        <div class="tab-actions">
          <a href="cohorts.php" class="btn btn-sm btn-secondary dashboard-tab-link" data-link="tab-cohorts">Manage All</a>
          <a href="messages.php" class="btn btn-sm btn-secondary dashboard-tab-link legacy-style-6b99de8b69" data-link="tab-messages">View All</a>
          <a href="ventures.php" class="btn btn-sm btn-primary dashboard-tab-link legacy-style-6b99de8b69" data-link="tab-ventures">
            <i class="fa fa-plus"></i> Add Venture
          </a>
        </div>
      </div>

      <div id="tab-cohorts" class="tab-content active" role="tabpanel">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Cohort Name</th>
                <th>ventures</th>
                <th>Status</th>
                <th>Applications</th>
              </tr>
            </thead>
            <tbody>
              <?php $has_cohorts = false; ?>
              <?php if ($recent_cohorts): ?>
                <?php while ($row = $recent_cohorts->fetch_assoc()): $has_cohorts = true; ?>
                  <tr>
                    <td><strong><?= h($row['name'] ?? '') ?></strong></td>
                    <td><span class="badge badge-info"><?= (int)($row['startup_count'] ?? 0) ?></span></td>
                    <td>
                      <?php
                      $s = $row['status'] ?? 'draft';
                      $cls = $s === 'active' ? 'badge-success' : ($s === 'draft' ? 'badge-warning' : 'badge-gray');
                      ?>
                      <span class="badge <?= h($cls) ?>"><?= h(ucfirst($s)) ?></span>
                    </td>
                    <td>
                      <?php
                      $a = $row['application_status'] ?? 'closed';
                      $ac = $a === 'open' ? 'badge-success' : ($a === 'coming_soon' ? 'badge-warning' : 'badge-danger');
                      ?>
                      <span class="badge <?= h($ac) ?>"><?= h(ucwords(str_replace('_', ' ', $a))) ?></span>
                    </td>
                  </tr>
                <?php endwhile; ?>
              <?php endif; ?>

              <?php if (!$has_cohorts): ?>
                <tr>
                  <td colspan="4">
                    <div class="empty-state legacy-style-e60a353027">
                      <i class="fa fa-layer-group"></i>
                      <h3>No cohorts yet</h3>
                      <p>No cohorts have been created.</p>
                      <a href="cohorts.php" class="btn btn-primary btn-sm">
                        <i class="fa fa-plus"></i> Add Cohort
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div id="tab-messages" class="tab-content" role="tabpanel">
        <div class="card-body legacy-style-41d7d7e25b">
          <?php $has_msg = false; ?>

          <?php if ($recent_msgs): ?>
            <?php while ($msg = $recent_msgs->fetch_assoc()): $has_msg = true; ?>
              <div class="message-list-item">
                <div class="message-avatar">
                  <?= h(strtoupper(substr(($msg['full_name'] ?? '') ?: 'A', 0, 1))) ?>
                </div>

                <div class="message-main">
                  <div class="message-head">
                    <strong class="message-name"><?= h($msg['full_name'] ?? 'Anonymous') ?></strong>

                    <?php if (($msg['status'] ?? '') === 'unread'): ?>
                      <span class="badge badge-orange legacy-style-6ee0661ec0">New</span>
                    <?php endif; ?>
                  </div>

                  <p class="message-subject">
                    <?= h(truncate($msg['subject'] ?? 'No subject', 70)) ?>
                  </p>

                  <?php if (!empty($msg['created_at'])): ?>
                    <small class="legacy-style-5872de20d5">
                      <?= h(date('M j, Y g:i A', strtotime($msg['created_at']))) ?>
                    </small>
                  <?php endif; ?>
                </div>
              </div>
            <?php endwhile; ?>
          <?php endif; ?>

          <?php if (!$has_msg): ?>
            <div class="empty-state legacy-style-a19bf6120d">
              <i class="fa fa-inbox"></i>
              <h3>No messages yet</h3>
              <p>New contact messages will appear here.</p>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div id="tab-ventures" class="tab-content" role="tabpanel">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Logo</th>
                <th>Venture Name</th>
                <th>Cohort</th>
                <th>Sector</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php $has_ventures = false; ?>

              <?php if ($recent_ventures): ?>
                <?php while ($st = $recent_ventures->fetch_assoc()): $has_ventures = true; ?>
                  <tr>
                    <td>
                      <?php if (!empty($st['logo']) && file_exists('../' . $st['logo'])): ?>
                        <img src="<?= h(SITE_URL . '/' . $st['logo']) ?>" class="tbl-img" alt="">
                      <?php else: ?>
                        <div class="tbl-img legacy-style-89bad37616">
                          <?= h(strtoupper(substr($st['name'] ?? 'S', 0, 1))) ?>
                        </div>
                      <?php endif; ?>
                    </td>

                    <td>
                      <strong><?= h($st['name'] ?? '') ?></strong><br>
                      <small class="legacy-style-5872de20d5">
                        <?= h(truncate($st['tagline'] ?? '', 60)) ?>
                      </small>
                    </td>

                    <td><?= h($st['cohort_name'] ?? '—') ?></td>
                    <td><?= h(($st['sector'] ?? '') ?: '—') ?></td>

                    <td>
                      <?php
                      $status = $st['status'] ?? 'draft';
                      $sc = $status === 'active' ? 'badge-success' : ($status === 'draft' ? 'badge-warning' : 'badge-gray');
                      ?>
                      <span class="badge <?= h($sc) ?>"><?= h(ucfirst($status)) ?></span>
                    </td>

                    <td>
                      <div class="tbl-actions">
                        <a href="ventures.php?edit=<?= (int)$st['id'] ?>" class="btn btn-sm btn-secondary" title="Edit">
                          <i class="fa fa-edit"></i>
                        </a>

                        <?php if (!empty($st['slug'])): ?>
                          <a href="../venture.php?slug=<?= h($st['slug']) ?>" target="_blank" class="btn btn-sm btn-secondary" title="View">
                            <i class="fa fa-eye"></i>
                          </a>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endwhile; ?>
              <?php endif; ?>

              <?php if (!$has_ventures): ?>
                <tr>
                  <td colspan="6">
                    <div class="empty-state legacy-style-a19bf6120d">
                      <i class="fa fa-rocket"></i>
                      <h3>No ventures yet</h3>
                      <p>Add cohorts first, then Add Ventures.</p>
                      <a href="ventures.php" class="btn btn-primary btn-sm">
                        Add Venture
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>

  </div>
</div>

<script src="assets/js/admin.js"></script>

</body>
</html>
