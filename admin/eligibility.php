<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';
    if($action==='save'){
        $id=(int)($_POST['id']??0);$icon=esc($conn,$_POST['icon']??'fa-check-circle');$desc=esc($conn,$_POST['description']??'');$sort=(int)($_POST['sort_order']??0);$status=(int)($_POST['status']??1);
        if(!$desc){flash('elig','Description required.','error');}
        else{if($id){mysqli_query($conn,"UPDATE eligibility_criteria SET icon='$icon',description='$desc',sort_order=$sort,status=$status WHERE id=$id");flash('elig','Updated.');}
        else{mysqli_query($conn,"INSERT INTO eligibility_criteria(icon,description,sort_order,status)VALUES('$icon','$desc',$sort,$status)");flash('elig','Added.');}}
        header('Location: eligibility.php');exit;
    }
    if($action==='delete'){$id=(int)$_POST['id'];mysqli_query($conn,"DELETE FROM eligibility_criteria WHERE id=$id");flash('elig','Deleted.');header('Location: eligibility.php');exit;}
}
$edit=null;if(isset($_GET['edit'])){$r=mysqli_query($conn,"SELECT * FROM eligibility_criteria WHERE id=".(int)$_GET['edit']." LIMIT 1");$edit=$r?mysqli_fetch_assoc($r):null;}
$list=mysqli_query($conn,"SELECT * FROM eligibility_criteria ORDER BY sort_order,id");
$site_name=get_setting($conn,'site_name');
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Eligibility - <?=h($site_name)?> Admin</title>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link rel="stylesheet" href="assets/css/admin.css"></head><body>
<?php include 'includes/sidebar.php';?>
<div class="admin-main" id="adminMain">
<header class="admin-topbar"><div class="topbar-left"><div class="topbar-breadcrumb"><a href="index.php">Dashboard</a> › <strong>Eligibility Criteria</strong></div></div><div class="topbar-right"><div class="admin-avatar"><div class="avatar-circle"><?=strtoupper(substr($ADMIN['full_name'],0,1))?></div></div></div></header>
<div class="admin-content"><?php show_flash('elig');?>
<div class="page-header"><div><h1 class="page-title">Eligibility Criteria</h1><p class="page-subtitle">Manage who qualifies for the fellowship</p></div><button class="btn btn-primary" onclick="openModal()"><i class="fa fa-plus"></i> Add Criterion</button></div>
<div class="card"><div class="table-wrap"><table><thead><tr><th>Icon</th><th>Description</th><th>Order</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php $has=false;while($e=mysqli_fetch_assoc($list)):$has=true;?>
<tr><td><i class="fa <?=h($e['icon'])?>" style="font-size:1.2rem;color:var(--primary)"></i></td><td><?=h(truncate($e['description'],100))?></td><td><?=$e['sort_order']?></td>
<td><span class="badge <?=$e['status']?'badge-success':'badge-gray'?>"><?=$e['status']?'Active':'Hidden'?></span></td>
<td><div class="tbl-actions">
<button onclick="editItem(<?=htmlspecialchars(json_encode($e),ENT_QUOTES)?>)" class="btn btn-sm btn-secondary"><i class="fa fa-edit"></i></button>
<form method="POST" onsubmit="return confirm('Delete?')" class="legacy-style-cccfa4560d"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$e['id']?>"><button class="btn btn-sm btn-danger"><i class="fa fa-trash"></i></button></form>
</div></td></tr>
<?php endwhile;if(!$has):?><tr><td colspan="5"><div class="empty-state"><i class="fa fa-check-circle"></i><h3>No criteria yet</h3><button class="btn btn-primary" onclick="openModal()"><i class="fa fa-plus"></i> Add</button></div></td></tr><?php endif;?>
</tbody></table></div></div></div></div>
<div class="modal-overlay" id="eligModal"><div class="modal"><div class="modal-header"><h2 class="modal-title" id="modalTitle">Add Criterion</h2><button class="modal-close" onclick="closeModal()">×</button></div>
<form method="POST"><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="f_id">
<div class="modal-body"><div class="form-grid form-grid-2">
  <div class="form-group"><label>Icon class (FA)</label><input type="text" name="icon" id="f_icon" class="form-control" placeholder="fa-check-circle"></div>
  <div class="form-group"><label>Sort Order</label><input type="number" name="sort_order" id="f_sort" class="form-control" value="0"></div>
  <div class="form-group full"><label>Description <span class="req">*</span></label><textarea name="description" id="f_desc" class="form-control" rows="4" required></textarea></div>
  <div class="form-group"><label>Status</label><select name="status" id="f_status" class="form-control"><option value="1">Active</option><option value="0">Hidden</option></select></div>
</div></div>
<div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button><button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save</button></div>
</form></div></div>
<script>
const sidebar=document.getElementById('adminSidebar'),main=document.getElementById('adminMain');
document.getElementById('sidebarToggle')?.addEventListener('click',()=>{sidebar.classList.toggle('collapsed');main.classList.toggle('collapsed');});
const modal=document.getElementById('eligModal');
function openModal(){modal.classList.add('open');}function closeModal(){modal.classList.remove('open');}
modal.addEventListener('click',e=>{if(e.target===modal)closeModal();});
function editItem(d){document.getElementById('modalTitle').textContent='Edit Criterion';document.getElementById('f_id').value=d.id;document.getElementById('f_icon').value=d.icon||'fa-check-circle';document.getElementById('f_desc').value=d.description||'';document.getElementById('f_sort').value=d.sort_order||0;document.getElementById('f_status').value=d.status;openModal();}
<?php if($edit):?>editItem(<?=json_encode($edit)?>);<?php endif;?>
</script></body></html>
