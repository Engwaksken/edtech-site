<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) die('Database connection not found.');
$conn->set_charset('utf8mb4');

$investor_id = (int)($_GET['investor_id'] ?? 0);
$venture_id  = (int)($_GET['venture_id']  ?? 0);

// Load approved investors for dropdown
$investors_rs = $conn->query("SELECT id, name, firm_name FROM investors WHERE status='approved' ORDER BY name");
// Load active ventures for dropdown
$ventures_rs  = $conn->query("SELECT id, name FROM ventures ORDER BY name");

// Filters
$where  = ['1=1'];
$params = [];
$types  = '';
if ($investor_id > 0) { $where[] = 'm.investor_id = ?'; $params[] = $investor_id; $types .= 'i'; }
if ($venture_id  > 0) { $where[] = 'm.venture_id  = ?'; $params[] = $venture_id;  $types .= 'i'; }
$filter_status = $_GET['match_status'] ?? '';
if ($filter_status !== '') { $where[] = 'm.status = ?'; $params[] = $filter_status; $types .= 's'; }
$ws = implode(' AND ', $where);

$matches_stmt = $conn->prepare("
    SELECT m.*,
           i.name AS investor_name, i.firm_name, i.investor_type,
           v.name AS venture_name,  v.sector,    v.stage
    FROM investor_matches m
    JOIN investors i ON i.id = m.investor_id
    JOIN ventures  v ON v.id = m.venture_id
    WHERE $ws
    ORDER BY m.created_at DESC
");
if ($types) $matches_stmt->bind_param($types, ...$params);
$matches_stmt->execute();
$matches = $matches_stmt->get_result();
$matches_stmt->close();

$match_statuses = ['pending'=>'Pending','interested'=>'Interested','meeting_scheduled'=>'Meeting Scheduled','passed'=>'Passed','invested'=>'Invested'];
$site_name = function_exists('get_setting') ? get_setting($conn, 'site_name', 'EdTech Fellowship') : 'EdTech Fellowship';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Matchmaking - <?= h($site_name) ?> Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="assets/css/admin.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/admin.css') ?>">
  
</head>
<body class="admin-system-page">
<?php include 'includes/sidebar.php'; ?>
<div class="admin-main" id="adminMain">

<header class="admin-topbar">
  <div class="topbar-left">
    <div class="topbar-breadcrumb"><a href="index.php">Dashboard</a> > <a href="investors.php">Investors</a> > <strong>Matchmaking</strong></div>
  </div>
  <div class="topbar-right">
    <div class="admin-avatar"><div class="avatar-circle"><?= strtoupper(substr($ADMIN['full_name'],0,1)) ?></div></div>
  </div>
</header>

<div class="admin-content">
<?php show_flash('matches'); ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Investor Matchmaking</h1>
    <p class="page-subtitle">Link investors to ventures and track deal pipeline</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" onclick="openMatchModal()"><i class="fa fa-plus"></i> Create Match</button>
  </div>
</div>

<!-- Filters -->
<div class="filter-bar">
  <form method="GET" class="legacy-style-ae125572d5">
    <select name="investor_id" onchange="this.form.submit()">
      <option value="">All Investors</option>
      <?php $investors_rs->data_seek(0); while($r=$investors_rs->fetch_assoc()): ?>
        <option value="<?= $r['id'] ?>" <?= $investor_id==$r['id']?'selected':'' ?>>
          <?= h($r['name']) ?><?= $r['firm_name']?' ('.h($r['firm_name']).')':'' ?>
        </option>
      <?php endwhile; ?>
    </select>
    <select name="venture_id" onchange="this.form.submit()">
      <option value="">All Ventures</option>
      <?php $ventures_rs->data_seek(0); while($r=$ventures_rs->fetch_assoc()): ?>
        <option value="<?= $r['id'] ?>" <?= $venture_id==$r['id']?'selected':'' ?>><?= h($r['name']) ?></option>
      <?php endwhile; ?>
    </select>
    <select name="match_status" onchange="this.form.submit()">
      <option value="">All Statuses</option>
      <?php foreach ($match_statuses as $k=>$v): ?>
        <option value="<?= $k ?>" <?= $filter_status===$k?'selected':'' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($investor_id||$venture_id||$filter_status): ?>
      <a href="investor-matches.php" class="btn btn-sm legacy-style-2af7847e65"><i class="fa fa-times"></i> Clear</a>
    <?php endif; ?>
  </form>
</div>

<?php $has = false; while ($m = $matches->fetch_assoc()): $has = true; ?>
<div class="match-card">
  <div class="match-party">
    <strong><?= h($m['investor_name']) ?></strong>
    <small><?= h($m['firm_name'] ?? '') ?></small>
    <small><?= ucfirst($m['investor_type'] ?? '') ?></small>
  </div>
  <div class="match-arrow"><i class="fa fa-exchange-alt"></i></div>
  <div class="match-party">
    <strong><?= h($m['venture_name']) ?></strong>
    <small><?= h($m['sector'] ?? '') ?></small>
    <small><?= ucfirst($m['stage'] ?? '') ?></small>
  </div>
  <div class="match-actions">
    <?php if (!empty($m['match_score'])): ?>
      <span class="score-pill"><i class="fa fa-star"></i> <?= $m['match_score'] ?>%</span>
    <?php endif; ?>
    <?php
    $msc=['pending'=>'badge-warning','interested'=>'badge-info','meeting_scheduled'=>'badge-primary','passed'=>'badge-gray','invested'=>'badge-success'];
    ?>
    <span class="badge <?= $msc[$m['status']]??'badge-gray' ?>"><?= h($match_statuses[$m['status']]??$m['status']) ?></span>
    <button class="btn btn-sm btn-secondary" onclick='editMatch(<?= json_encode($m,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'><i class="fa fa-edit"></i></button>
    <form method="POST" action="includes/process-investors.php"
          onsubmit="return confirm('Remove this match?')" class="legacy-style-cccfa4560d">
      <input type="hidden" name="action" value="delete_match">
      <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
      <button class="btn btn-sm btn-danger"><i class="fa fa-unlink"></i></button>
    </form>
  </div>
</div>
<?php endwhile; ?>
<?php if (!$has): ?>
<div class="empty-state">
  <i class="fa fa-magic"></i>
  <h3>No matches yet</h3>
  <p>Create a match to start tracking investor-venture relationships.</p>
  <button class="btn btn-primary" onclick="openMatchModal()"> Create Match</button>
</div>
<?php endif; ?>

</div>
</div>

<!-- Match Modal -->
<div class="modal-overlay" id="matchModal">
<div class="modal">
  <div class="modal-header">
    <h2 class="modal-title" id="matchModalTitle">Create Match</h2>
    <button type="button" class="modal-close" onclick="closeMatchModal()">x</button>
  </div>
  <form method="POST" action="includes/process-investors.php">
    <input type="hidden" name="action" value="save_match">
    <input type="hidden" name="id" id="match_id">
    <div class="modal-body">
    <div class="form-grid form-grid-2">
      <div class="form-group">
        <label>Investor <span class="req">*</span></label>
        <select name="investor_id" id="match_investor_id" class="form-control" required>
          <option value="">- Select Investor -</option>
          <?php $investors_rs->data_seek(0); while($r=$investors_rs->fetch_assoc()): ?>
            <option value="<?= $r['id'] ?>"><?= h($r['name']) ?><?= $r['firm_name']?' ('.h($r['firm_name']).')':'' ?></option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Venture <span class="req">*</span></label>
        <select name="venture_id" id="match_venture_id" class="form-control" required>
          <option value="">- Select Venture -</option>
          <?php $ventures_rs->data_seek(0); while($r=$ventures_rs->fetch_assoc()): ?>
            <option value="<?= $r['id'] ?>"><?= h($r['name']) ?></option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Match Status</label>
        <select name="status" id="match_status_sel" class="form-control">
          <?php foreach ($match_statuses as $k=>$v): ?>
            <option value="<?= $k ?>"><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Match Score (0-100)</label>
        <input type="number" name="match_score" id="match_score" class="form-control" min="0" max="100">
      </div>
      <div class="form-group full">
        <label>Notes</label>
        <textarea name="notes" id="match_notes" class="form-control" rows="3"></textarea>
      </div>
    </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeMatchModal()">Cancel</button>
      <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Match</button>
    </div>
  </form>
</div>
</div>

<script>
document.getElementById('sidebarToggle')?.addEventListener('click',()=>{
  document.getElementById('adminSidebar')?.classList.toggle('collapsed');
  document.getElementById('adminMain')?.classList.toggle('collapsed');
});
const matchModal=document.getElementById('matchModal');
function openMatchModal(){resetMatchForm();matchModal.classList.add('open');}
function closeMatchModal(){matchModal.classList.remove('open');}
matchModal.addEventListener('click',e=>{if(e.target===matchModal)closeMatchModal();});
function resetMatchForm(){
  document.getElementById('matchModalTitle').textContent='Create Match';
  matchModal.querySelector('form').reset();
  document.getElementById('match_id').value='';
}
function editMatch(d){
  resetMatchForm();
  document.getElementById('matchModalTitle').textContent='Edit Match';
  document.getElementById('match_id').value=d.id||'';
  document.getElementById('match_investor_id').value=d.investor_id||'';
  document.getElementById('match_venture_id').value=d.venture_id||'';
  document.getElementById('match_status_sel').value=d.status||'pending';
  document.getElementById('match_score').value=d.match_score||'';
  document.getElementById('match_notes').value=d.notes||'';
  matchModal.classList.add('open');
}
</script>
</body>
</html>
