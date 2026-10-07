<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) die('Database connection not found.');
$conn->set_charset('utf8mb4');

// -- Filters ---------------------------------------------------
$filter_status   = $_GET['status']   ?? '';
$filter_type     = $_GET['type']     ?? '';
$filter_stage    = $_GET['stage']    ?? '';
$search          = trim($_GET['q']   ?? '');
$page            = max(1, (int)($_GET['page'] ?? 1));
$per_page        = 20;
$offset          = ($page - 1) * $per_page;

$where  = ['1=1'];
$params = [];
$types  = '';

if ($filter_status !== '') {
    $where[]  = 'i.status = ?';
    $params[] = $filter_status;
    $types   .= 's';
}
if ($filter_type !== '') {
    $where[]  = 'i.investor_type = ?';
    $params[] = $filter_type;
    $types   .= 's';
}
if ($filter_stage !== '') {
    $where[]  = 'FIND_IN_SET(?, i.investment_stages)';
    $params[] = $filter_stage;
    $types   .= 's';
}
if ($search !== '') {
    $where[]  = '(i.name LIKE ? OR i.firm_name LIKE ? OR i.email LIKE ?)';
    $s         = '%' . $search . '%';
    $params   = array_merge($params, [$s, $s, $s]);
    $types   .= 'sss';
}

$where_sql = implode(' AND ', $where);

// Count
$count_sql  = "SELECT COUNT(*) FROM investors i WHERE $where_sql";
$count_stmt = $conn->prepare($count_sql);
if ($types) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total = $count_stmt->get_result()->fetch_row()[0];
$count_stmt->close();
$total_pages = max(1, ceil($total / $per_page));

// Fetch
$list_sql  = "SELECT i.*,
                (SELECT COUNT(*) FROM investor_matches m WHERE m.investor_id = i.id) AS match_count
              FROM investors i
              WHERE $where_sql
              ORDER BY i.created_at DESC
              LIMIT ? OFFSET ?";
$list_stmt = $conn->prepare($list_sql);
$all_params = array_merge($params, [$per_page, $offset]);
$all_types  = $types . 'ii';
$list_stmt->bind_param($all_types, ...$all_params);
$list_stmt->execute();
$investors = $list_stmt->get_result();
$list_stmt->close();

$site_name = function_exists('get_setting') ? get_setting($conn, 'site_name', 'EdTech Fellowship') : 'EdTech Fellowship';

$investor_types  = ['angel' => 'Angel', 'vc' => 'VC', 'corporate' => 'Corporate', 'family_office' => 'Family Office', 'impact' => 'Impact Fund', 'accelerator' => 'Accelerator'];
$stages          = ['pre_seed' => 'Pre-Seed', 'seed' => 'Seed', 'series_a' => 'Series A', 'series_b' => 'Series B', 'growth' => 'Growth'];
$statuses        = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'suspended' => 'Suspended'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Investors - <?= h($site_name) ?> Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="assets/css/admin.css">
  
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="admin-main" id="adminMain">

<header class="admin-topbar">
  <div class="topbar-left">
    <div class="topbar-breadcrumb"><a href="index.php">Dashboard</a> > <strong>Investor Directory</strong></div>
  </div>
  <div class="topbar-right">
    <a href="<?= SITE_URL ?>" target="_blank" class="btn btn-secondary btn-sm"><i class="fa fa-eye"></i> View Site</a>
    <div class="admin-avatar"><div class="avatar-circle"><?= strtoupper(substr($ADMIN['full_name'],0,1)) ?></div></div>
  </div>
</header>

<div class="admin-content">
<?php show_flash('investors'); ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Investor Directory</h1>
    <p class="page-subtitle">Profiles, moderation & venture matchmaking &nbsp;.&nbsp; <?= number_format($total) ?> investors</p>
  </div>
  <div class="page-actions">
    <a href="investor-matches.php" class="btn btn-secondary"><i class="fa fa-magic"></i> Matchmaking</a>
    <button type="button" class="btn btn-primary" onclick="openInvestorModal()"><i class="fa fa-plus"></i> Add Investor</button>
  </div>
</div>

<!-- Filters -->
<div class="filter-bar">
  <form method="GET" class="legacy-style-ae125572d5">
    <input type="text" name="q" placeholder="Search name, firm, email..." value="<?= h($search) ?>">
    <select name="status" onchange="this.form.submit()">
      <option value="">All Statuses</option>
      <?php foreach ($statuses as $k=>$v): ?>
        <option value="<?= $k ?>" <?= $filter_status===$k?'selected':'' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
    <select name="type" onchange="this.form.submit()">
      <option value="">All Types</option>
      <?php foreach ($investor_types as $k=>$v): ?>
        <option value="<?= $k ?>" <?= $filter_type===$k?'selected':'' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
    <select name="stage" onchange="this.form.submit()">
      <option value="">All Stages</option>
      <?php foreach ($stages as $k=>$v): ?>
        <option value="<?= $k ?>" <?= $filter_stage===$k?'selected':'' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-secondary btn-sm"><i class="fa fa-search"></i></button>
    <?php if ($search||$filter_status||$filter_type||$filter_stage): ?>
      <a href="investors.php" class="btn btn-sm legacy-style-2af7847e65"><i class="fa fa-times"></i> Clear</a>
    <?php endif; ?>
  </form>
</div>

<div class="card">
<div class="table-wrap">
<table>
<thead>
<tr>
  <th>Investor</th>
  <th>Type</th>
  <th>Focus / Stages</th>
  <th>Ticket Size</th>
  <th>Matches</th>
  <th>Status</th>
  <th>Joined</th>
  <th>Actions</th>
</tr>
</thead>
<tbody>
<?php $has = false; while ($inv = $investors->fetch_assoc()): $has = true; ?>
<tr>
  <td>
    <div class="legacy-style-b87ca3241d">
      <?php if (!empty($inv['photo'])): ?>
        <img src="<?= SITE_URL.'/'.h($inv['photo']) ?>" class="investor-avatar" alt="">
      <?php else: ?>
        <div class="investor-initials"><?= strtoupper(substr($inv['name'],0,2)) ?></div>
      <?php endif; ?>
      <div>
        <strong><?= h($inv['name']) ?></strong><br>
        <small class="legacy-style-5872de20d5"><?= h($inv['firm_name'] ?? '') ?></small><br>
        <small class="legacy-style-5872de20d5"><?= h($inv['email']) ?></small>
      </div>
    </div>
  </td>
  <td><span class="badge badge-info"><?= h($investor_types[$inv['investor_type']] ?? $inv['investor_type']) ?></span></td>
  <td>
    <div class="stage-tags">
      <?php foreach (explode(',', $inv['investment_stages'] ?? '') as $s):
        $s = trim($s); if (!$s) continue; ?>
        <span class="stage-tag"><?= h($stages[$s] ?? $s) ?></span>
      <?php endforeach; ?>
    </div>
    <?php if (!empty($inv['sector_focus'])): ?>
      <small class="legacy-style-4058b6fdd1"><?= h(mb_strimwidth($inv['sector_focus'],0,50,'...')) ?></small>
    <?php endif; ?>
  </td>
  <td class="legacy-style-0ea2eb29e2">
    <?= !empty($inv['min_ticket']) ? '$'.number_format($inv['min_ticket']) : '-' ?>
    <?= !empty($inv['max_ticket']) ? ' - $'.number_format($inv['max_ticket']) : '' ?>
  </td>
  <td><span class="match-chip"><i class="fa fa-link"></i> <?= (int)$inv['match_count'] ?></span></td>
  <td>
    <?php $sc = ['pending'=>'badge-warning','approved'=>'badge-success','rejected'=>'badge-danger','suspended'=>'badge-gray']; ?>
    <span class="badge <?= $sc[$inv['status']] ?? 'badge-gray' ?>">
      <span class="status-dot dot-<?= $inv['status'] ?>"></span><?= ucfirst($inv['status']) ?>
    </span>
  </td>
  <td class="legacy-style-c85ef718a8"><?= date('M j, Y', strtotime($inv['created_at'])) ?></td>
  <td>
    <div class="tbl-actions">
      <!-- Moderation quick-actions -->
      <?php if ($inv['status'] === 'pending'): ?>
        <form method="POST" action="includes/process-investors.php" class="legacy-style-cccfa4560d">
          <input type="hidden" name="action" value="moderate">
          <input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
          <input type="hidden" name="status" value="approved">
          <button class="btn btn-sm btn-success" title="Approve"><i class="fa fa-check"></i></button>
        </form>
        <form method="POST" action="includes/process-investors.php" class="legacy-style-cccfa4560d">
          <input type="hidden" name="action" value="moderate">
          <input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
          <input type="hidden" name="status" value="rejected">
          <button class="btn btn-sm btn-danger" title="Reject"><i class="fa fa-times"></i></button>
        </form>
      <?php elseif ($inv['status'] === 'approved'): ?>
        <form method="POST" action="includes/process-investors.php" class="legacy-style-cccfa4560d">
          <input type="hidden" name="action" value="moderate">
          <input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
          <input type="hidden" name="status" value="suspended">
          <button class="btn btn-sm btn-warning" title="Suspend"><i class="fa fa-pause"></i></button>
        </form>
      <?php endif; ?>
      <button class="btn btn-sm btn-secondary" title="Edit"
              onclick='editInvestor(<?= json_encode($inv, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'>
        <i class="fa fa-edit"></i>
      </button>
      <a href="investor-matches.php?investor_id=<?= (int)$inv['id'] ?>" class="btn btn-sm btn-teal" title="View matches">
        <i class="fa fa-magic"></i>
      </a>
      <form method="POST" action="includes/process-investors.php"
            onsubmit="return confirm('Delete this investor profile?')" class="legacy-style-cccfa4560d">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
        <button class="btn btn-sm btn-danger"><i class="fa fa-trash"></i></button>
      </form>
    </div>
  </td>
</tr>
<?php endwhile; ?>
<?php if (!$has): ?>
<tr><td colspan="8">
  <div class="empty-state">
    <i class="fa fa-user-tie"></i>
    <h3>No investors found</h3>
    <p>Add your first investor or adjust your filters.</p>
    <button class="btn btn-primary" onclick="openInvestorModal()"> Add Investor</button>
  </div>
</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<div class="pagination">
  <?php for ($p = 1; $p <= $total_pages; $p++):
    $qs = http_build_query(array_merge($_GET, ['page' => $p])); ?>
    <?php if ($p === $page): ?>
      <span class="current"><?= $p ?></span>
    <?php else: ?>
      <a href="?<?= $qs ?>"><?= $p ?></a>
    <?php endif; ?>
  <?php endfor; ?>
</div>
<?php endif; ?>
</div><!-- /card -->

</div><!-- /admin-content -->
</div><!-- /admin-main -->

<!-- ---------------------- INVESTOR MODAL ---------------------- -->
<div class="modal-overlay" id="investorModal">
<div class="modal modal-lg">
  <div class="modal-header">
    <h2 class="modal-title" id="investorModalTitle">Add Investor</h2>
    <button type="button" class="modal-close" onclick="closeInvestorModal()">x</button>
  </div>
  <form method="POST" action="includes/process-investors.php" enctype="multipart/form-data">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" id="inv_id">
    <input type="hidden" name="existing_photo" id="inv_existing_photo">

    <div class="modal-body">
    <div class="form-grid form-grid-2">

      <div class="form-group">
        <label>Full Name <span class="req">*</span></label>
        <input type="text" name="name" id="inv_name" class="form-control" required>
      </div>
      <div class="form-group">
        <label>Email <span class="req">*</span></label>
        <input type="email" name="email" id="inv_email" class="form-control" required>
      </div>
      <div class="form-group">
        <label>Firm / Organisation</label>
        <input type="text" name="firm_name" id="inv_firm" class="form-control">
      </div>
      <div class="form-group">
        <label>Job Title / Role</label>
        <input type="text" name="job_title" id="inv_title" class="form-control">
      </div>
      <div class="form-group">
        <label>Investor Type</label>
        <select name="investor_type" id="inv_type" class="form-control">
          <?php foreach ($investor_types as $k=>$v): ?>
            <option value="<?= $k ?>"><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Status</label>
        <select name="status" id="inv_status" class="form-control">
          <?php foreach ($statuses as $k=>$v): ?>
            <option value="<?= $k ?>"><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group full">
        <label>Investment Stages (check all that apply)</label>
        <div id="inv_stages_wrap" class="legacy-style-183c813566">
          <?php foreach ($stages as $k=>$v): ?>
            <label class="legacy-style-fbdb057441">
              <input type="checkbox" name="investment_stages[]" value="<?= $k ?>" class="inv-stage-cb"> <?= $v ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="form-group full">
        <label>Sector Focus</label>
        <input type="text" name="sector_focus" id="inv_sector" class="form-control" placeholder="e.g. EdTech, FinTech, AgriTech">
      </div>
      <div class="form-group">
        <label>Min Ticket (USD)</label>
        <input type="number" name="min_ticket" id="inv_min" class="form-control" min="0" step="1000">
      </div>
      <div class="form-group">
        <label>Max Ticket (USD)</label>
        <input type="number" name="max_ticket" id="inv_max" class="form-control" min="0" step="1000">
      </div>
      <div class="form-group full">
        <label>Bio / Description</label>
        <textarea name="bio" id="inv_bio" class="form-control" rows="4"></textarea>
      </div>
      <div class="form-group">
        <label>LinkedIn URL</label>
        <input type="url" name="linkedin_url" id="inv_linkedin" class="form-control">
      </div>
      <div class="form-group">
        <label>Website</label>
        <input type="url" name="website" id="inv_website" class="form-control">
      </div>
      <div class="form-group">
        <label>Phone</label>
        <input type="text" name="phone" id="inv_phone" class="form-control">
      </div>
      <div class="form-group">
        <label>Location / Country</label>
        <input type="text" name="location" id="inv_location" class="form-control">
      </div>
      <div class="form-group full">
        <label>Profile Photo</label>
        <input type="file" name="photo" class="form-control" accept="image/*"
               onchange="previewInvPhoto(this)">
        <img id="inv_photo_preview" class="img-preview legacy-style-9714b43b47" alt="">
      </div>
      <div class="form-group full">
        <label>Admin Notes (internal)</label>
        <textarea name="admin_notes" id="inv_notes" class="form-control" rows="2"></textarea>
      </div>

    </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeInvestorModal()">Cancel</button>
      <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Investor</button>
    </div>
  </form>
</div>
</div>

<script>
document.getElementById('sidebarToggle')?.addEventListener('click',()=>{
  document.getElementById('adminSidebar')?.classList.toggle('collapsed');
  document.getElementById('adminMain')?.classList.toggle('collapsed');
});
const invModal = document.getElementById('investorModal');
function openInvestorModal(){resetInvForm();invModal.classList.add('open');}
function closeInvestorModal(){invModal.classList.remove('open');}
invModal.addEventListener('click',e=>{if(e.target===invModal)closeInvestorModal();});

function resetInvForm(){
  document.getElementById('investorModalTitle').textContent='Add Investor';
  invModal.querySelector('form').reset();
  document.getElementById('inv_id').value='';
  document.getElementById('inv_existing_photo').value='';
  document.getElementById('inv_photo_preview').style.display='none';
}

function editInvestor(d){
  resetInvForm();
  document.getElementById('investorModalTitle').textContent='Edit Investor';
  document.getElementById('inv_id').value=d.id||'';
  document.getElementById('inv_name').value=d.name||'';
  document.getElementById('inv_email').value=d.email||'';
  document.getElementById('inv_firm').value=d.firm_name||'';
  document.getElementById('inv_title').value=d.job_title||'';
  document.getElementById('inv_type').value=d.investor_type||'angel';
  document.getElementById('inv_status').value=d.status||'pending';
  document.getElementById('inv_sector').value=d.sector_focus||'';
  document.getElementById('inv_min').value=d.min_ticket||'';
  document.getElementById('inv_max').value=d.max_ticket||'';
  document.getElementById('inv_bio').value=d.bio||'';
  document.getElementById('inv_linkedin').value=d.linkedin_url||'';
  document.getElementById('inv_website').value=d.website||'';
  document.getElementById('inv_phone').value=d.phone||'';
  document.getElementById('inv_location').value=d.location||'';
  document.getElementById('inv_notes').value=d.admin_notes||'';
  document.getElementById('inv_existing_photo').value=d.photo||'';
  if(d.photo){const p=document.getElementById('inv_photo_preview');p.src='<?= SITE_URL ?>/'+d.photo;p.style.display='block';}
  // Restore stage checkboxes
  const stages=(d.investment_stages||'').split(',').map(s=>s.trim());
  document.querySelectorAll('.inv-stage-cb').forEach(cb=>{cb.checked=stages.includes(cb.value);});
  invModal.classList.add('open');
}

function previewInvPhoto(input){
  if(input.files&&input.files[0]){
    const r=new FileReader();
    r.onload=e=>{const p=document.getElementById('inv_photo_preview');p.src=e.target.result;p.style.display='block';};
    r.readAsDataURL(input.files[0]);
  }
}
</script>
</body>
</html>
