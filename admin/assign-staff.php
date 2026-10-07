<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $venture_id = (int)($_POST['venture_id'] ?? 0);

    if ($action === 'assign' && $venture_id) {
        $staff_ids = $_POST['staff_ids'] ?? [];
        $roles     = $_POST['roles'] ?? [];

   
        mysqli_query($conn, "DELETE FROM venture_staff WHERE venture_id=$venture_id");

        foreach ($staff_ids as $i => $staff_id) {
            $staff_id = (int)$staff_id;
            if (!$staff_id) continue;
            $role_desc = esc($conn, $roles[$i] ?? '');
            mysqli_query($conn, "INSERT IGNORE INTO venture_staff (venture_id,staff_id,role_description,assigned_date)
                VALUES($venture_id,$staff_id,'$role_desc',NOW())");
        }
        flash('assign', 'Staff assignments saved successfully.');
        header("Location: assign-staff.php?venture_id=$venture_id"); exit;
    }

    if ($action === 'remove') {
        $id = (int)$_POST['assignment_id'];
        mysqli_query($conn, "DELETE FROM venture_staff WHERE id=$id");
        flash('assign', 'Assignment removed.');
        header('Location: assign-staff.php?venture_id=' . $venture_id); exit;
    }
}


$venture_id  = (int)($_GET['venture_id'] ?? 0);
$staff_id_f  = (int)($_GET['staff_id'] ?? 0);
$venture_row = null;
if ($venture_id) {
    $r = mysqli_query($conn, "SELECT s.*,c.name AS cohort_name FROM ventures s LEFT JOIN cohorts c ON c.id=s.cohort_id WHERE s.id=$venture_id LIMIT 1");
    $venture_row = $r ? mysqli_fetch_assoc($r) : null;
}


$ventures_list = mysqli_query($conn,
    "SELECT s.id, CONCAT(c.name,' -',s.name) AS label FROM ventures s
     LEFT JOIN cohorts c ON c.id=s.cohort_id ORDER BY c.sort_order,s.sort_order,s.name"
);

$all_staff = mysqli_query($conn, "SELECT * FROM staff WHERE status=1 ORDER BY category,sort_order,full_name");


$assigned = [];
if ($venture_id) {
    $ra = mysqli_query($conn,
        "SELECT ss.*, st.full_name, st.title, st.organization, st.photo, st.category, st.expertise
         FROM venture_staff ss
         JOIN staff st ON st.id=ss.staff_id
         WHERE ss.venture_id=$venture_id ORDER BY st.category,st.full_name"
    );
    while ($row = mysqli_fetch_assoc($ra)) $assigned[$row['staff_id']] = $row;
}


$staff_assignments = [];
if ($staff_id_f) {
    $rs = mysqli_query($conn,
        "SELECT ss.*, s.name AS venture_name, s.slug, s.logo, c.name AS cohort_name
         FROM venture_staff ss
         JOIN ventures s ON s.id=ss.venture_id
         LEFT JOIN cohorts c ON c.id=s.cohort_id
         WHERE ss.staff_id=$staff_id_f ORDER BY c.sort_order,s.sort_order"
    );
    while ($row = mysqli_fetch_assoc($rs)) $staff_assignments[] = $row;
}

$categories = [
    'program_staff' => ['label'=>'Program Staff', 'color'=>'badge-info'],
    'mentor'        => ['label'=>'Mentor',         'color'=>'badge-orange'],
    'advisor'       => ['label'=>'Advisor',        'color'=>'badge-gray'],
    'partner_staff' => ['label'=>'Partner Staff',  'color'=>'badge-gray'],
];

$site_name = get_setting($conn, 'site_name', 'EdTech Fellowship');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Assign Staff - <?= h($site_name) ?> Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="assets/css/admin.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/admin.css') ?>">
  
</head>
<body class="admin-system-page">
<?php include 'includes/sidebar.php'; ?>
<div class="admin-main" id="adminMain">
  <header class="admin-topbar">
    <div class="topbar-left">
      <div class="topbar-breadcrumb"><a href="index.php">Dashboard</a> › <strong>Assign Staff to ventures</strong></div>
    </div>
    <div class="topbar-right">
      <div class="admin-avatar">
        <div class="avatar-circle"><?= strtoupper(substr($ADMIN['full_name'], 0, 1)) ?></div>
        <div class="avatar-info">
          <span class="avatar-name"><?= h(explode(' ', $ADMIN['full_name'])[0]) ?></span>
          <span class="avatar-role"><?= h(str_replace('_', ' ', $ADMIN['role'])) ?></span>
        </div>
      </div>
    </div>
  </header>

  <div class="admin-content">
    <?php show_flash('assign'); ?>

    <div class="page-header">
      <div>
        <h1 class="page-title">Staff Assignments</h1>
        <p class="page-subtitle">Assign mentors, staff, and advisors to ventures</p>
      </div>
      <div class="page-actions">
        <a href="staff.php" class="btn btn-secondary"><i class="fa fa-user-tie"></i> Manage Staff</a>
        <a href="ventures.php" class="btn btn-secondary"><i class="fa fa-rocket"></i> Manage ventures</a>
      </div>
    </div>

    <!-- venture Selector -->
    <div class="card legacy-style-13f24e7378">
      <div class="card-header"><h3 class="card-title">Select a venture to Manage Assignments</h3></div>
      <div class="card-body">
        <form method="GET" class="legacy-style-7d43cadda1">
          <div class="form-group legacy-style-dd21d53e8b">
            <label>venture</label>
            <select name="venture_id" class="form-control" onchange="this.form.submit()">
              <option value="">Choose a venture </option>
              <?php
              while ($s = mysqli_fetch_assoc($ventures_list)):
              ?>
              <option value="<?= $s['id'] ?>" <?= $venture_id == $s['id'] ? 'selected' : '' ?>><?= h($s['label']) ?></option>
              <?php endwhile; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-primary"><i class="fa fa-arrow-right"></i> Load</button>
        </form>
      </div>
    </div>

    <?php if ($venture_row): ?>
    <!-- venture Info Banner -->
    <div class="legacy-style-945d5b5423">
      <?php if ($venture_row['logo'] && file_exists('../' . $venture_row['logo'])): ?>
        <img src="<?= SITE_URL . '/' . h($venture_row['logo']) ?>" style="width:56px;height:56px;border-radius:10px;object-fit:cover;border:2px solid rgba(255,255,255,.3)" alt="">
      <?php else: ?>
        <div class="legacy-style-cdcff2e686">
          <?= strtoupper(substr($venture_row['name'], 0, 1)) ?>
        </div>
      <?php endif; ?>
      <div>
        <h2 class="legacy-style-968fb8bfe3"><?= h($venture_row['name']) ?></h2>
        <p class="legacy-style-f4313b9e05"><?= h($venture_row['cohort_name'] ?? '') ?> · <?= count($assigned) ?> staff assigned</p>
      </div>
      <div class="legacy-style-ff2ab46b1a">
        <a href="venture-team.php?venture_id=<?= $venture_id ?>" class="btn btn-secondary btn-sm"><i class="fa fa-users"></i> Team</a>
        <a href="../venture.php?slug=<?= h($venture_row['slug']) ?>" target="_blank" class="btn btn-sm" style="background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.4)"><i class="fa fa-eye"></i> View</a>
      </div>
    </div>

    <!-- Assignment Form -->
    <form method="POST">
      <input type="hidden" name="action" value="assign">
      <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
      <div class="assign-main-grid legacy-style-2c98f092fe">

        <!-- Left: All Staff -->
        <div class="card">
          <div class="card-header">
            <h3 class="card-title"><i class="fa fa-users legacy-style-ac3e88a561"></i> Available Staff</h3>
            <div class="search-bar legacy-style-532b403970">
              <i class="fa fa-search"></i>
              <input type="text" id="staffSearch" placeholder="Search...">
            </div>
          </div>
          <div class="card-body legacy-style-17c3e49129">
            <?php
            $current_cat = '';
            $staff_arr = [];
            // Reload all staff
            $all_staff2 = mysqli_query($conn, "SELECT * FROM staff WHERE status=1 ORDER BY category,sort_order,full_name");
            while ($sf = mysqli_fetch_assoc($all_staff2)):
              if ($sf['category'] !== $current_cat):
                $current_cat = $sf['category'];
                $cat_info = $categories[$current_cat] ?? ['label'=>ucfirst($current_cat),'color'=>'badge-gray'];
            ?>
              <div class="nav-section-label legacy-style-f1f33fcd3f"><?= h($cat_info['label']) ?></div>
            <?php endif;
              $is_assigned = isset($assigned[$sf['id']]);
            ?>
            <label class="staff-check-item <?= $is_assigned ? 'is-assigned' : '' ?>" data-name="<?= strtolower(h($sf['full_name'])) ?>">
              <input type="checkbox" name="staff_ids[]" value="<?= $sf['id'] ?>"
                     <?= $is_assigned ? 'checked' : '' ?> onchange="toggleRole(this,<?= $sf['id'] ?>)">
              <?php if ($sf['photo'] && file_exists('../' . $sf['photo'])): ?>
                <img src="<?= SITE_URL . '/' . h($sf['photo']) ?>" class="sav-photo" alt="">
              <?php else: ?>
                <div class="sav-photo"><?= strtoupper(substr($sf['full_name'], 0, 1)) ?></div>
              <?php endif; ?>
              <div class="sav-info">
                <h4><?= h($sf['full_name']) ?></h4>
                <p><?= h($sf['title'] ?: '') ?><?= ($sf['title'] && $sf['organization']) ? ', ' : '' ?><?= h($sf['organization'] ?: '') ?></p>
                <input type="text" name="roles[]"
                       id="role_<?= $sf['id'] ?>"
                       class="form-control role-input"
                       placeholder="Role / focus area for this venture"
                       value="<?= h($assigned[$sf['id']]['role_description'] ?? '') ?>"
                       style="display:<?= $is_assigned ? 'block' : 'none' ?>">
              </div>
            </label>
            <?php endwhile; ?>
          </div>
        </div>

        <!-- Right: Currently Assigned -->
        <div class="card">
          <div class="card-header">
            <h3 class="card-title"><i class="fa fa-link legacy-style-12559ae15e"></i> Currently Assigned (<?= count($assigned) ?>)</h3>
          </div>
          <div class="card-body legacy-style-17c3e49129">
            <?php if (empty($assigned)): ?>
              <div class="empty-state legacy-style-e60a353027">
                <i class="fa fa-user-plus"></i>
                <h3>No staff assigned yet</h3>
                <p>Check staff members on the left to assign them.</p>
              </div>
            <?php else: ?>
              <?php foreach ($assigned as $a): ?>
              <div class="staff-check-item is-assigned legacy-style-fdf33f2304">
                <?php if ($a['photo'] && file_exists('../' . $a['photo'])): ?>
                  <img src="<?= SITE_URL . '/' . h($a['photo']) ?>" class="sav-photo" alt="">
                <?php else: ?>
                  <div class="sav-photo"><?= strtoupper(substr($a['full_name'], 0, 1)) ?></div>
                <?php endif; ?>
                <div class="sav-info">
                  <h4><?= h($a['full_name']) ?></h4>
                  <p><?= h($a['title'] ?: '') ?></p>
                  <?php if ($a['role_description']): ?>
                    <p><strong>Role:</strong> <?= h($a['role_description']) ?></p>
                  <?php endif; ?>
                  <span class="badge <?= $categories[$a['category']]['color'] ?? 'badge-gray' ?>">
                    <?= h($categories[$a['category']]['label'] ?? ucfirst($a['category'])) ?>
                  </span>
                </div>
                <form method="POST" onsubmit="return confirm('Remove this assignment?')" class="legacy-style-6d00061700">
                  <input type="hidden" name="action" value="remove">
                  <input type="hidden" name="assignment_id" value="<?= $a['id'] ?>">
                  <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
                  <button class="btn btn-sm btn-danger btn-icon"><i class="fa fa-unlink"></i></button>
                </form>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="legacy-style-cea3b88b3a">
        <button type="submit" class="btn btn-primary btn-lg">
          <i class="fa fa-save"></i> Save All Assignments
        </button>
      </div>
    </form>

    <?php elseif (!$venture_id): ?>
    <!-- Overview: All assignments -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">All venture–Staff Assignments</h3>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>venture</th><th>Cohort</th><th>Assigned Staff</th><th>Count</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php
            $overview = mysqli_query($conn,
                "SELECT s.id, s.name, s.slug, s.logo, c.name AS cohort_name,
                 GROUP_CONCAT(st.full_name ORDER BY st.full_name SEPARATOR ', ') AS staff_names,
                 COUNT(ss.id) AS staff_count
                 FROM ventures s
                 LEFT JOIN cohorts c ON c.id=s.cohort_id
                 LEFT JOIN venture_staff ss ON ss.venture_id=s.id
                 LEFT JOIN staff st ON st.id=ss.staff_id
                 WHERE s.status='active'
                 GROUP BY s.id ORDER BY c.sort_order, s.sort_order"
            );
            $ov_has = false;
            while ($ov = mysqli_fetch_assoc($overview)): $ov_has = true; ?>
            <tr>
              <td>
                <div class="legacy-style-b87ca3241d">
                  <?php if ($ov['logo'] && file_exists('../' . $ov['logo'])): ?>
                    <img src="<?= SITE_URL . '/' . h($ov['logo']) ?>" class="tbl-avatar" alt="">
                  <?php else: ?>
                    <div class="tbl-avatar legacy-style-89bad37616">
                      <?= strtoupper(substr($ov['name'], 0, 1)) ?>
                    </div>
                  <?php endif; ?>
                  <strong><?= h($ov['name']) ?></strong>
                </div>
              </td>
              <td><?= h($ov['cohort_name'] ?: '-') ?></td>
              <td><small class="legacy-style-5872de20d5"><?= h($ov['staff_names'] ?: 'No staff assigned') ?></small></td>
              <td><span class="badge <?= $ov['staff_count'] > 0 ? 'badge-success' : 'badge-warning' ?>"><?= $ov['staff_count'] ?> staff</span></td>
              <td>
                <a href="assign-staff.php?venture_id=<?= $ov['id'] ?>" class="btn btn-sm btn-primary"><i class="fa fa-link"></i> Manage</a>
              </td>
            </tr>
            <?php endwhile; ?>
            <?php if (!$ov_has): ?>
            <tr><td colspan="5">
              <div class="empty-state"><i class="fa fa-link"></i><h3>No ventures found</h3><p>Add ventures first.</p></div>
            </td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<script>
const sidebar = document.getElementById('adminSidebar');
const main    = document.getElementById('adminMain');
document.getElementById('sidebarToggle')?.addEventListener('click', () => {
  sidebar.classList.toggle('collapsed'); main.classList.toggle('collapsed');
});

function toggleRole(checkbox, staffId){
  const roleInput = document.getElementById('role_'+staffId);
  const item = checkbox.closest('.staff-check-item');
  if(checkbox.checked){
    roleInput.style.display='block'; item.classList.add('is-assigned');
  } else {
    roleInput.style.display='none'; item.classList.remove('is-assigned');
  }
}

// Search filter
const searchInput = document.getElementById('staffSearch');
if(searchInput){
  searchInput.addEventListener('input', function(){
    const q = this.value.toLowerCase();
    document.querySelectorAll('.staff-check-item[data-name]').forEach(el=>{
      el.style.display = el.dataset.name.includes(q) ? '' : 'none';
    });
  });
}

// Responsive
if(window.innerWidth < 900){
  const grid = document.querySelector('.assign-main-grid');
  if(grid) grid.style.gridTemplateColumns='1fr';
}
</script>
</body>
</html>
