<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

$filter_cohort = (int)($_GET['cohort_id'] ?? 0);
$search = trim($_GET['q'] ?? '');

$whereParts = ['1=1'];
$params = [];
$types = '';

if ($filter_cohort > 0) {
    $whereParts[] = 's.cohort_id = ?';
    $params[] = $filter_cohort;
    $types .= 'i';
}

if ($search !== '') {
    $like = '%' . $search . '%';
    $whereParts[] = '(s.name LIKE ? OR s.tagline LIKE ? OR s.sector LIKE ? OR s.stage LIKE ?)';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}

$where = implode(' AND ', $whereParts);

$sql = "
    SELECT s.*, c.name AS cohort_name
    FROM startups s
    LEFT JOIN cohorts c ON c.id = s.cohort_id
    WHERE $where
    ORDER BY s.sort_order ASC, s.created_at DESC
";

$stmt = $conn->prepare($sql);

if ($stmt && $params) {
    $stmt->bind_param($types, ...$params);
}

if ($stmt) {
    $stmt->execute();
    $startups = $stmt->get_result();
} else {
    $startups = false;
}

$cohorts_list = $conn->query("SELECT id, name FROM cohorts ORDER BY sort_order ASC, name ASC");

$edit = null;

if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];

    $editStmt = $conn->prepare("SELECT * FROM startups WHERE id = ? LIMIT 1");
    $editStmt->bind_param("i", $editId);
    $editStmt->execute();
    $edit = $editStmt->get_result()->fetch_assoc();
    $editStmt->close();
}

$site_name = function_exists('get_setting')
    ? get_setting($conn, 'site_name', 'EdTech Fellowship')
    : 'EdTech Fellowship';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <title>Startups - <?= h($site_name) ?> Admin</title>

  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>

<?php include 'includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">
  <header class="admin-topbar">
    <div class="topbar-left">
      <div class="topbar-breadcrumb">
        <a href="index.php">Dashboard</a> › <strong>Startups</strong>
      </div>
    </div>

    <div class="topbar-right">
      <div class="admin-avatar">
        <div class="avatar-circle">
          <?= h(strtoupper(substr($ADMIN['full_name'] ?? 'A', 0, 1))) ?>
        </div>

        <div class="avatar-info">
          <span class="avatar-name">
            <?= h(explode(' ', $ADMIN['full_name'] ?? 'Admin')[0]) ?>
          </span>
          <span class="avatar-role">
            <?= h(str_replace('_', ' ', $ADMIN['role'] ?? 'admin')) ?>
          </span>
        </div>
      </div>
    </div>
  </header>

  <div class="admin-content">
    <?php show_flash('startups'); ?>

    <div class="page-header">
      <div>
        <h1 class="page-title">Startups</h1>
        <p class="page-subtitle">Manage EdTech startups across all cohorts.</p>
      </div>

      <button type="button" class="btn btn-primary" onclick="openModal()">
        <i class="fa fa-plus"></i> Add Startup
      </button>
    </div>

    <form method="GET" class="filter-bar">
      <div class="search-bar">
        <i class="fa fa-search"></i>
        <input type="text" name="q" placeholder="Search startups…" value="<?= h($search) ?>">
      </div>

      <select name="cohort_id" class="form-control legacy-style-0fac9183d5" onchange="this.form.submit()">
        <option value="">All Cohorts</option>

        <?php
        $filterCohorts = $conn->query("SELECT id, name FROM cohorts ORDER BY sort_order ASC, name ASC");
        while ($c = $filterCohorts->fetch_assoc()):
        ?>
          <option value="<?= (int)$c['id'] ?>" <?= $filter_cohort === (int)$c['id'] ? 'selected' : '' ?>>
            <?= h($c['name']) ?>
          </option>
        <?php endwhile; ?>
      </select>

      <button type="submit" class="btn btn-secondary">
        <i class="fa fa-filter"></i> Filter
      </button>

      <a href="startups.php" class="btn btn-secondary">
        <i class="fa fa-times"></i> Clear
      </a>
    </form>

    <div class="card">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Logo</th>
              <th>Startup</th>
              <th>Cohort</th>
              <th>Sector / Stage</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>

          <tbody>
          <?php $has = false; ?>

          <?php if ($startups): ?>
            <?php while ($st = $startups->fetch_assoc()): $has = true; ?>
              <tr>
                <td>
                  <?php if (!empty($st['logo']) && file_exists('../' . $st['logo'])): ?>
                    <img src="<?= SITE_URL . '/' . h($st['logo']) ?>" class="tbl-img" alt="">
                  <?php else: ?>
                    <div class="tbl-img legacy-style-c058fa1c3c">
                      <?= h(strtoupper(substr($st['name'] ?? 'S', 0, 1))) ?>
                    </div>
                  <?php endif; ?>
                </td>

                <td>
                  <strong><?= h($st['name']) ?></strong><br>
                  <small class="legacy-style-5872de20d5">
                    <?= h(function_exists('truncate') ? truncate($st['tagline'] ?? '', 55) : mb_strimwidth($st['tagline'] ?? '', 0, 55, '…')) ?>
                  </small><br>
                  <small class="legacy-style-5872de20d5">
                    <i class="fa fa-globe legacy-style-652db75ef3"></i>
                    <?= h($st['website'] ?: '-') ?>
                  </small>
                </td>

                <td><?= h($st['cohort_name'] ?: '-') ?></td>

                <td>
                  <?php if (!empty($st['sector'])): ?>
                    <span class="badge badge-info"><?= h($st['sector']) ?></span><br>
                  <?php endif; ?>

                  <?php if (!empty($st['stage'])): ?>
                    <small class="legacy-style-5872de20d5"><?= h($st['stage']) ?></small>
                  <?php endif; ?>
                </td>

                <td>
                  <?php
                    $sc = $st['status'] === 'active'
                        ? 'badge-success'
                        : ($st['status'] === 'draft' ? 'badge-warning' : 'badge-gray');
                  ?>
                  <span class="badge <?= h($sc) ?>">
                    <?= h(ucfirst($st['status'])) ?>
                  </span>
                </td>

                <td>
                  <div class="tbl-actions">
                    <a href="startup-team.php?startup_id=<?= (int)$st['id'] ?>" class="btn btn-sm btn-teal" title="Manage team">
                      <i class="fa fa-users"></i>
                    </a>

                    <a href="assign-staff.php?startup_id=<?= (int)$st['id'] ?>" class="btn btn-sm btn-secondary" title="Assign staff">
                      <i class="fa fa-link"></i>
                    </a>

                    <button type="button"
                            onclick="editStartup(<?= h(json_encode($st, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>)"
                            class="btn btn-sm btn-secondary">
                      <i class="fa fa-edit"></i>
                    </button>

                    <form method="POST" action="includes/process-startups.php" onsubmit="return confirm('Delete this startup?')" class="legacy-style-cccfa4560d">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$st['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-danger">
                        <i class="fa fa-trash"></i>
                      </button>
                    </form>

                    <a href="../startup.php?slug=<?= h($st['slug']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-secondary">
                      <i class="fa fa-eye"></i>
                    </a>
                  </div>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php endif; ?>

          <?php if (!$has): ?>
            <tr>
              <td colspan="6">
                <div class="empty-state">
                  <i class="fa fa-rocket"></i>
                  <h3>No startups found</h3>
                  <p>Add startups to cohorts to get started.</p>
                  <button type="button" class="btn btn-primary" onclick="openModal()">
                    <i class="fa fa-plus"></i> Add Startup
                  </button>
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

<div class="modal-overlay" id="startupModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h2 class="modal-title" id="modalTitle">Add Startup</h2>
      <button type="button" class="modal-close" onclick="closeModal()">×</button>
    </div>

    <form method="POST" action="includes/process-startups.php" enctype="multipart/form-data">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="f_id">
      <input type="hidden" name="existing_logo" id="f_existing_logo">
      <input type="hidden" name="existing_featured" id="f_existing_featured">

      <div class="modal-body">
        <div class="form-grid form-grid-2">
          <div class="form-group">
            <label>Cohort <span class="req">*</span></label>
            <select name="cohort_id" id="f_cohort_id" class="form-control" required>
              <option value="">Select Cohort</option>
              <?php if ($cohorts_list): ?>
                <?php while ($c = $cohorts_list->fetch_assoc()): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
                <?php endwhile; ?>
              <?php endif; ?>
            </select>
          </div>

          <div class="form-group">
            <label>Status</label>
            <select name="status" id="f_status" class="form-control">
              <option value="active">Active</option>
              <option value="draft">Draft</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>

          <div class="form-group full">
            <label>Startup Name <span class="req">*</span></label>
            <input type="text" name="name" id="f_name" class="form-control" required>
          </div>

          <div class="form-group full">
            <label>Tagline</label>
            <input type="text" name="tagline" id="f_tagline" class="form-control">
          </div>

          <div class="form-group full">
            <label>Description</label>
            <textarea name="description" id="f_description" class="form-control" rows="4"></textarea>
          </div>

          <div class="form-group">
            <label>Sector / Focus Area</label>
            <input type="text" name="sector" id="f_sector" class="form-control">
          </div>

          <div class="form-group">
            <label>Stage</label>
            <input type="text" name="stage" id="f_stage" class="form-control">
          </div>

          <div class="form-group">
            <label>Website</label>
            <input type="url" name="website" id="f_website" class="form-control">
          </div>

          <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" id="f_email" class="form-control">
          </div>

          <div class="form-group">
            <label>Phone</label>
            <input type="text" name="phone" id="f_phone" class="form-control">
          </div>

          <div class="form-group">
            <label>Location</label>
            <input type="text" name="location" id="f_location" class="form-control" value="Uganda">
          </div>

          <div class="form-group">
            <label>Founded Year</label>
            <input type="number" name="founded_year" id="f_founded_year" class="form-control" min="1900" max="2100">
          </div>

          <div class="form-group">
            <label>Sort Order</label>
            <input type="number" name="sort_order" id="f_sort_order" class="form-control" value="0">
          </div>

          <div class="form-group full">
            <label>Impact Metric</label>
            <input type="text" name="impact_metric" id="f_impact" class="form-control">
          </div>

          <div class="form-group">
            <label>Logo</label>
            <input type="file" name="logo" class="form-control" accept="image/*" onchange="previewImg(this,'prev_logo')">
            <img id="prev_logo" class="img-preview legacy-style-66253c00ea" alt="">
          </div>

          <div class="form-group">
            <label>Featured Image</label>
            <input type="file" name="featured_image" class="form-control" accept="image/*" onchange="previewImg(this,'prev_feat')">
            <img id="prev_feat" class="img-preview legacy-style-66253c00ea" alt="">
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">
          <i class="fa fa-save"></i> Save Startup
        </button>
      </div>
    </form>
  </div>
</div>

<script>
const sidebar = document.getElementById('adminSidebar');
const main = document.getElementById('adminMain');

document.getElementById('sidebarToggle')?.addEventListener('click', () => {
  sidebar?.classList.toggle('collapsed');
  main?.classList.toggle('collapsed');
});

const modal = document.getElementById('startupModal');

function resetStartupForm() {
  document.getElementById('modalTitle').textContent = 'Add Startup';
  document.querySelector('#startupModal form').reset();

  document.getElementById('f_id').value = '';
  document.getElementById('f_existing_logo').value = '';
  document.getElementById('f_existing_featured').value = '';
  document.getElementById('f_location').value = 'Uganda';
  document.getElementById('f_status').value = 'active';
  document.getElementById('f_sort_order').value = '0';

  const logo = document.getElementById('prev_logo');
  logo.src = '';
  logo.style.display = 'none';

  const feat = document.getElementById('prev_feat');
  feat.src = '';
  feat.style.display = 'none';
}

function openModal() {
  resetStartupForm();
  modal.classList.add('open');
}

function closeModal() {
  modal.classList.remove('open');
}

modal.addEventListener('click', e => {
  if (e.target === modal) closeModal();
});

function editStartup(d) {
  resetStartupForm();

  document.getElementById('modalTitle').textContent = 'Edit Startup';

  document.getElementById('f_id').value = d.id || '';
  document.getElementById('f_cohort_id').value = d.cohort_id || '';
  document.getElementById('f_name').value = d.name || '';
  document.getElementById('f_tagline').value = d.tagline || '';
  document.getElementById('f_description').value = d.description || '';
  document.getElementById('f_sector').value = d.sector || '';
  document.getElementById('f_stage').value = d.stage || '';
  document.getElementById('f_website').value = d.website || '';
  document.getElementById('f_email').value = d.email || '';
  document.getElementById('f_phone').value = d.phone || '';
  document.getElementById('f_location').value = d.location || 'Uganda';
  document.getElementById('f_founded_year').value = d.founded_year || '';
  document.getElementById('f_impact').value = d.impact_metric || '';
  document.getElementById('f_status').value = d.status || 'active';
  document.getElementById('f_sort_order').value = d.sort_order || 0;
  document.getElementById('f_existing_logo').value = d.logo || '';
  document.getElementById('f_existing_featured').value = d.featured_image || '';

  if (d.logo) {
    const el = document.getElementById('prev_logo');
    el.src = '<?= SITE_URL ?>/' + d.logo;
    el.style.display = 'block';
  }

  if (d.featured_image) {
    const el = document.getElementById('prev_feat');
    el.src = '<?= SITE_URL ?>/' + d.featured_image;
    el.style.display = 'block';
  }

  modal.classList.add('open');
}

function previewImg(input, previewId) {
  const prev = document.getElementById(previewId);

  if (input.files && input.files[0]) {
    const reader = new FileReader();

    reader.onload = e => {
      prev.src = e.target.result;
      prev.style.display = 'block';
    };

    reader.readAsDataURL(input.files[0]);
  }
}

<?php if ($edit): ?>
editStartup(<?= json_encode($edit, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>);
<?php endif; ?>
</script>

</body>
</html>
