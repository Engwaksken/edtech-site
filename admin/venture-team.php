<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

$venture_id   = (int)($_GET['venture_id'] ?? 0);
$page         = max(1, (int)($_GET['page'] ?? 1));
$per_page     = (int)($_GET['per_page'] ?? 10);
$allowed_pp   = [10, 25, 50, 100];

if (!in_array($per_page, $allowed_pp, true)) {
    $per_page = 10;
}

$offset       = ($page - 1) * $per_page;
$total_rows   = 0;
$total_pages  = 1;

$venture_row  = null;
$venture_name = 'All ventures';

function pagination_url(array $extra = []): string
{
    $query = array_merge($_GET, $extra);

    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }

    return '?' . http_build_query($query);
}

if ($venture_id > 0) {
    $stmt = $conn->prepare("
        SELECT v.*, c.name AS cohort_name
        FROM ventures v
        LEFT JOIN cohorts c ON c.id = v.cohort_id
        WHERE v.id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param("i", $venture_id);
        $stmt->execute();
        $venture_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($venture_row) {
            $venture_name = $venture_row['name'];
        }
    }
}

$edit = null;

if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];

    if ($editId > 0) {
        $stmt = $conn->prepare("SELECT * FROM venture_team WHERE id = ? LIMIT 1");

        if ($stmt) {
            $stmt->bind_param("i", $editId);
            $stmt->execute();
            $edit = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($edit && $venture_id <= 0) {
                $venture_id = (int)$edit['venture_id'];
            }
        }
    }
}

$where  = '1=1';
$params = [];
$types  = '';

if ($venture_id > 0) {
    $where = 't.venture_id = ?';
    $params[] = $venture_id;
    $types .= 'i';
}

/* Count rows for pagination */
$count_sql = "
    SELECT COUNT(*) AS total
    FROM venture_team t
    LEFT JOIN ventures s ON s.id = t.venture_id
    WHERE $where
";

$count_stmt = $conn->prepare($count_sql);

if ($count_stmt) {
    if ($params) {
        $count_stmt->bind_param($types, ...$params);
    }

    $count_stmt->execute();
    $count_row = $count_stmt->get_result()->fetch_assoc();
    $count_stmt->close();

    $total_rows = (int)($count_row['total'] ?? 0);
}

$total_pages = max(1, (int)ceil($total_rows / $per_page));

if ($page > $total_pages) {
    $page   = $total_pages;
    $offset = ($page - 1) * $per_page;
}

$start_row = $total_rows > 0 ? ($offset + 1) : 0;
$end_row   = min($offset + $per_page, $total_rows);

/* Paginated list */
$sql = "
    SELECT t.*, s.name AS venture_name
    FROM venture_team t
    LEFT JOIN ventures s ON s.id = t.venture_id
    WHERE $where
    ORDER BY t.sort_order ASC, t.is_founder DESC, t.full_name ASC
    LIMIT ? OFFSET ?
";

$list_params = $params;
$list_types  = $types . 'ii';
$list_params[] = $per_page;
$list_params[] = $offset;

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param($list_types, ...$list_params);
    $stmt->execute();
    $team_members = $stmt->get_result();
} else {
    $team_members = false;
}

$ventures_list = $conn->query("
    SELECT v.id, CONCAT(COALESCE(c.name, 'No Cohort'), ' - ', v.name) AS label
    FROM ventures v
    LEFT JOIN cohorts c ON c.id = v.cohort_id
    ORDER BY c.sort_order ASC, v.sort_order ASC, v.name ASC
");

$filterventures = $conn->query("
    SELECT v.id, CONCAT(COALESCE(c.name, 'No Cohort'), ' - ', v.name) AS label
    FROM ventures v
    LEFT JOIN cohorts c ON c.id = v.cohort_id
    ORDER BY c.sort_order ASC, v.sort_order ASC, v.name ASC
");

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

  <title>Venture Team - <?= h($site_name) ?> Admin</title>

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
      <a href="index.php">Dashboard</a> ›
      <a href="ventures.php">Ventures</a> ›
      <strong>Team: <?= h($venture_name) ?></strong>
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

<?php show_flash('team'); ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Team Members</h1>

    <p class="page-subtitle">
      <?php if ($venture_row): ?>
        Managing team for <strong><?= h($venture_row['name']) ?></strong>
        <?= !empty($venture_row['cohort_name']) ? '(' . h($venture_row['cohort_name']) . ')' : '' ?>
      <?php else: ?>
        All venture team members.
      <?php endif; ?>
    </p>
  </div>

  <div class="page-actions">
    <?php if ($venture_id > 0): ?>
      <a href="ventures.php" class="btn btn-secondary">
        <i class="fa fa-arrow-left"></i> Back to ventures
      </a>

      <a href="assign-staff.php?venture_id=<?= (int)$venture_id ?>" class="btn btn-teal">
        <i class="fa fa-link"></i> Assign Staff
      </a>
    <?php endif; ?>

    <button type="button" class="btn btn-primary" onclick="openModal()">
      <i class="fa fa-plus"></i> Add Member
    </button>
  </div>
</div>

<form method="GET" class="filter-bar">
  <select name="venture_id" class="form-control legacy-style-bb082fd844" onchange="this.form.page.value='1'; this.form.submit()">
    <option value="">Filter by venture</option>

    <?php if ($filterventures): ?>
      <?php while ($s = $filterventures->fetch_assoc()): ?>
        <option value="<?= (int)$s['id'] ?>" <?= $venture_id === (int)$s['id'] ? 'selected' : '' ?>>
          <?= h($s['label']) ?>
        </option>
      <?php endwhile; ?>
    <?php endif; ?>
  </select>

  <select name="per_page" class="form-control legacy-style-c6fffdfe96" onchange="this.form.page.value='1'; this.form.submit()">
    <?php foreach ($allowed_pp as $pp): ?>
      <option value="<?= (int)$pp ?>" <?= $per_page === (int)$pp ? 'selected' : '' ?>>
        <?= (int)$pp ?> per page
      </option>
    <?php endforeach; ?>
  </select>

  <input type="hidden" name="page" value="<?= (int)$page ?>">

  <button type="submit" class="btn btn-secondary">
    <i class="fa fa-filter"></i> Filter
  </button>

  <a href="venture-team.php" class="btn btn-secondary">
    <i class="fa fa-times"></i> Clear
  </a>
</form>

<div class="card">
<div class="table-wrap">

<table>
<thead>
<tr>
  <th>Photo</th>
  <th>Name</th>
  <th>Role</th>
  <th>Venture</th>
  <th>Founder</th>
  <th>Contact</th>
  <th>Actions</th>
</tr>
</thead>

<tbody>
<?php $has = false; ?>

<?php if ($team_members): ?>
<?php while ($m = $team_members->fetch_assoc()): $has = true; ?>

<tr>
  <td>
    <?php if (!empty($m['photo']) && file_exists('../' . $m['photo'])): ?>
      <img src="<?= SITE_URL . '/' . h($m['photo']) ?>" class="tbl-avatar" alt="">
    <?php else: ?>
      <div class="tbl-avatar legacy-style-89aa9d0609">
        <?= h(strtoupper(substr($m['full_name'] ?? 'T', 0, 1))) ?>
      </div>
    <?php endif; ?>
  </td>

  <td><strong><?= h($m['full_name']) ?></strong></td>

  <td><?= h($m['role'] ?: '-') ?></td>

  <td><?= h($m['venture_name'] ?: '-') ?></td>

  <td>
    <?php if ((int)$m['is_founder'] === 1): ?>
      <span class="badge badge-orange">
        <i class="fa fa-star"></i> Founder
      </span>
    <?php else: ?>
      <span class="badge badge-gray">Team</span>
    <?php endif; ?>
  </td>

  <td class="legacy-style-f63147b2ed">
    <?php if (!empty($m['email'])): ?>
      <div>
        <i class="fa fa-envelope legacy-style-5872de20d5"></i>
        <?= h($m['email']) ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($m['linkedin'])): ?>
      <div>
        <a href="<?= h($m['linkedin']) ?>" target="_blank" rel="noopener">
          <i class="fab fa-linkedin legacy-style-9671975b6c"></i> LinkedIn
        </a>
      </div>
    <?php endif; ?>
  </td>

  <td>
    <div class="tbl-actions">
      <button type="button"
              onclick="editMember(<?= h(json_encode($m, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>)"
              class="btn btn-sm btn-secondary">
        <i class="fa fa-edit"></i>
      </button>

      <form method="POST"
            action="includes/process-venture-team.php"
           
            onsubmit="return confirm('Delete this team member?')" class="legacy-style-cccfa4560d">

        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
        <input type="hidden" name="venture_id" value="<?= (int)$m['venture_id'] ?>">

        <button type="submit" class="btn btn-sm btn-danger">
          <i class="fa fa-trash"></i>
        </button>
      </form>
    </div>
  </td>
</tr>

<?php endwhile; ?>
<?php endif; ?>

<?php if (!$has): ?>
<tr>
  <td colspan="7">
    <div class="empty-state">
      <i class="fa fa-users"></i>
      <h3>No team members found</h3>
      <p>Add founders and team members to ventures.</p>

      <button type="button" class="btn btn-primary" onclick="openModal()">
       Add Member
      </button>
    </div>
  </td>
</tr>
<?php endif; ?>

</tbody>
</table>

</div>

<?php if ($total_rows > 0): ?>
<div class="pagination-wrap">
  <div class="pagination-info">
    Showing <?= (int)$start_row ?> to <?= (int)$end_row ?> of <?= (int)$total_rows ?> team members
  </div>

  <div class="pagination-links">
    <a class="page-link <?= $page <= 1 ? 'disabled' : '' ?>"
       href="<?= h(pagination_url(['page' => 1])) ?>"
       title="First page">
      <i class="fa fa-angle-double-left"></i>
    </a>

    <a class="page-link <?= $page <= 1 ? 'disabled' : '' ?>"
       href="<?= h(pagination_url(['page' => max(1, $page - 1)])) ?>"
       title="Previous page">
      <i class="fa fa-angle-left"></i>
    </a>

    <?php
      $range = 2;
      $from  = max(1, $page - $range);
      $to    = min($total_pages, $page + $range);

      if ($from > 1) {
          echo '<a class="page-link" href="' . h(pagination_url(['page' => 1])) . '">1</a>';
          if ($from > 2) {
              echo '<span class="page-link disabled">...</span>';
          }
      }

      for ($i = $from; $i <= $to; $i++) {
          $active = $i === $page ? 'active' : '';
          echo '<a class="page-link ' . $active . '" href="' . h(pagination_url(['page' => $i])) . '">' . (int)$i . '</a>';
      }

      if ($to < $total_pages) {
          if ($to < $total_pages - 1) {
              echo '<span class="page-link disabled">...</span>';
          }
          echo '<a class="page-link" href="' . h(pagination_url(['page' => $total_pages])) . '">' . (int)$total_pages . '</a>';
      }
    ?>

    <a class="page-link <?= $page >= $total_pages ? 'disabled' : '' ?>"
       href="<?= h(pagination_url(['page' => min($total_pages, $page + 1)])) ?>"
       title="Next page">
      <i class="fa fa-angle-right"></i>
    </a>

    <a class="page-link <?= $page >= $total_pages ? 'disabled' : '' ?>"
       href="<?= h(pagination_url(['page' => $total_pages])) ?>"
       title="Last page">
      <i class="fa fa-angle-double-right"></i>
    </a>
  </div>
</div>
<?php endif; ?>

</div>

</div>
</div>

<div class="modal-overlay" id="teamModal">
<div class="modal">

<div class="modal-header">
  <h2 class="modal-title" id="modalTitle">Add Team Member</h2>
  <button type="button" class="modal-close" onclick="closeModal()">x</button>
</div>

<form method="POST" action="includes/process-venture-team.php" enctype="multipart/form-data">
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" id="f_id">
  <input type="hidden" name="existing_photo" id="f_existing_photo">

  <div class="modal-body">
    <div class="form-grid form-grid-2">

      <div class="form-group full">
        <label>Venture <span class="req">*</span></label>

        <select name="venture_id" id="f_venture_id" class="form-control" required>
          <option value="">Select venture</option>

          <?php if ($ventures_list): ?>
          <?php while ($s = $ventures_list->fetch_assoc()): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $venture_id === (int)$s['id'] ? 'selected' : '' ?>>
              <?= h($s['label']) ?>
            </option>
          <?php endwhile; ?>
          <?php endif; ?>
        </select>
      </div>

      <div class="form-group">
        <label>Full Name <span class="req">*</span></label>
        <input type="text" name="full_name" id="f_full_name" class="form-control" required>
      </div>

      <div class="form-group">
        <label>Role / Title</label>
        <input type="text" name="role" id="f_role" class="form-control">
      </div>

      <div class="form-group full">
        <label>Bio</label>
        <textarea name="bio" id="f_bio" class="form-control" rows="3"></textarea>
      </div>

      <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" id="f_email" class="form-control">
      </div>

      <div class="form-group">
        <label>LinkedIn URL</label>
        <input type="url" name="linkedin" id="f_linkedin" class="form-control" placeholder="https://linkedin.com/in/...">
      </div>

      <div class="form-group">
        <label>Twitter URL</label>
        <input type="url" name="twitter" id="f_twitter" class="form-control" placeholder="https://twitter.com/...">
      </div>

      <div class="form-group">
        <label>Sort Order</label>
        <input type="number" name="sort_order" id="f_sort_order" class="form-control" value="0">
      </div>

      <div class="form-group">
        <label>Photo</label>
        <input type="file" name="photo" class="form-control" accept="image/*" onchange="previewImg(this,'prev_photo')">
        <img id="prev_photo" class="img-preview circle legacy-style-66253c00ea" alt="">
      </div>

      <div class="form-group legacy-style-9162a24597">
        <label class="legacy-style-1405249936">
          <input type="checkbox" name="is_founder" id="f_is_founder" value="1" class="legacy-style-0e44bafd1c">
          Mark as Founder
        </label>
      </div>

    </div>
  </div>

  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
    <button type="submit" class="btn btn-primary">
      <i class="fa fa-save"></i> Save Member
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

const modal = document.getElementById('teamModal');

function resetMemberForm() {
  document.getElementById('modalTitle').textContent = 'Add Team Member';
  document.querySelector('#teamModal form').reset();

  document.getElementById('f_id').value = '';
  document.getElementById('f_existing_photo').value = '';
  document.getElementById('f_sort_order').value = '0';
  document.getElementById('f_is_founder').checked = false;

  const photo = document.getElementById('prev_photo');
  photo.src = '';
  photo.style.display = 'none';

  <?php if ($venture_id > 0): ?>
  document.getElementById('f_venture_id').value = '<?= (int)$venture_id ?>';
  <?php endif; ?>
}

function openModal() {
  resetMemberForm();
  modal.classList.add('open');
}

function closeModal() {
  modal.classList.remove('open');
}

modal.addEventListener('click', e => {
  if (e.target === modal) closeModal();
});

function editMember(d) {
  resetMemberForm();

  document.getElementById('modalTitle').textContent = 'Edit Team Member';

  document.getElementById('f_id').value = d.id || '';
  document.getElementById('f_venture_id').value = d.venture_id || '';
  document.getElementById('f_full_name').value = d.full_name || '';
  document.getElementById('f_role').value = d.role || '';
  document.getElementById('f_bio').value = d.bio || '';
  document.getElementById('f_email').value = d.email || '';
  document.getElementById('f_linkedin').value = d.linkedin || '';
  document.getElementById('f_twitter').value = d.twitter || '';
  document.getElementById('f_sort_order').value = d.sort_order || 0;
  document.getElementById('f_is_founder').checked = String(d.is_founder) === '1';
  document.getElementById('f_existing_photo').value = d.photo || '';

  if (d.photo) {
    const el = document.getElementById('prev_photo');
    el.src = '<?= rtrim(SITE_URL, '/') ?>/' + d.photo.replace(/^\/+/, '');
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
editMember(<?= json_encode($edit, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>);
<?php endif; ?>
</script>

</body>
</html>
