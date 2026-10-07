<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$pages = [
    'home'         => 'Home Page',
    'about'        => 'About Page',
    'blogs'        => 'Blogs Page',
    'contact'      => 'Contact Page',
    'programs'     => 'Programs Page',
    'startups'     => 'Startups Page',
    'mentors'      => 'Mentors Page',
    'partners'     => 'Partners Page',
    'faqs'         => 'FAQs Page',
];

$edit = null;

if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];

    $stmt = $conn->prepare("SELECT * FROM slides WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();

    $edit = $stmt->get_result()->fetch_assoc();

    $stmt->close();
}

$slides = $conn->query("
    SELECT *
    FROM slides
    ORDER BY page_name ASC, sort_order ASC, id DESC
");

$site_name = function_exists('get_setting')
    ? get_setting($conn, 'site_name', 'Website')
    : 'Website';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Slides - <?= h($site_name) ?> Admin</title>

<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

<link rel="stylesheet" href="assets/css/admin.css">
</head>

<body>

<?php include 'includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">

<header class="admin-topbar">
  <div class="topbar-left">
    <div class="topbar-breadcrumb">
      <a href="index.php">Dashboard</a> > <strong>Slides</strong>
    </div>
  </div>
</header>

<div class="admin-content">

<?php if (function_exists('show_flash')) show_flash('slides'); ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Hero Slides</h1>
    <p class="page-subtitle">
      Manage hero banners, images, and videos for different pages.
    </p>
  </div>

  <button type="button"
          class="btn btn-primary"
          onclick="openModal()">
    <i class="fa fa-plus"></i> Add Slide
  </button>
</div>

<div class="card">
<div class="table-wrap">

<table>
<thead>
<tr>
  <th>Preview</th>
  <th>Title</th>
  <th>Page</th>
  <th>Media</th>
  <th>Status</th>
  <th>Order</th>
  <th>Actions</th>
</tr>
</thead>

<tbody>

<?php $has=false; ?>

<?php if ($slides): ?>
<?php while ($s = $slides->fetch_assoc()): $has=true; ?>

<tr>

<td>
<?php if ($s['media_type'] === 'image' && !empty($s['image_path'])): ?>

  <img src="<?= SITE_URL . '/' . h($s['image_path']) ?>"
       class="tbl-img"
       alt="">

<?php elseif ($s['media_type'] === 'video'): ?>

  <div class="tbl-img legacy-style-cf0fafc53b"
      >
    <i class="fa fa-play"></i>
  </div>

<?php endif; ?>
</td>

<td>
  <strong><?= h($s['title']) ?></strong><br>

  <small class="legacy-style-5872de20d5">
    <?= h($s['subtitle']) ?>
  </small>
</td>

<td>
  <span class="badge badge-info">
    <?= h($pages[$s['page_name']] ?? $s['page_name']) ?>
  </span>
</td>

<td>
  <span class="badge <?= $s['media_type']==='video' ? 'badge-orange' : 'badge-success' ?>">
    <?= h(ucfirst($s['media_type'])) ?>
  </span>
</td>

<td>
  <span class="badge <?= (int)$s['status'] ? 'badge-success' : 'badge-danger' ?>">
    <?= (int)$s['status'] ? 'Active' : 'Inactive' ?>
  </span>
</td>

<td><?= (int)$s['sort_order'] ?></td>

<td>
<div class="tbl-actions">

<button type="button"
        class="btn btn-sm btn-secondary"
        onclick="editSlide(<?= h(json_encode($s, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)) ?>)">
  <i class="fa fa-edit"></i>
</button>

<form method="POST"
      action="includes/process-slides.php"
     
      onsubmit="return confirm('Delete this slide?')" class="legacy-style-cccfa4560d">

  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">

  <button class="btn btn-sm btn-danger">
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
    <i class="fa fa-images"></i>
    <h3>No slides yet</h3>

    <button type="button"
            class="btn btn-primary"
            onclick="openModal()">
      Add Slide
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

<div class="modal-overlay" id="slideModal">
<div class="modal modal-lg">

<div class="modal-header">
  <h2 class="modal-title" id="modalTitle">Add Slide</h2>

  <button type="button"
          class="modal-close"
          onclick="closeModal()">x</button>
</div>

<form method="POST"
      action="includes/process-slides.php"
      enctype="multipart/form-data">

<input type="hidden" name="action" value="save">
<input type="hidden" name="id" id="f_id">
<input type="hidden" name="existing_image" id="f_existing_image">
<input type="hidden" name="existing_video" id="f_existing_video">

<div class="modal-body">

<div class="form-grid form-grid-2">

<div class="form-group full">
  <label>Title</label>
  <input type="text" name="title" id="f_title" class="form-control">
</div>

<div class="form-group full">
  <label>Subtitle</label>
  <textarea name="subtitle" id="f_subtitle" class="form-control" rows="3"></textarea>
</div>

<div class="form-group">
  <label>Button Text</label>
  <input type="text" name="button_text" id="f_button_text" class="form-control">
</div>

<div class="form-group">
  <label>Button Link</label>
  <input type="text" name="button_link" id="f_button_link" class="form-control">
</div>

<div class="form-group">
  <label>Page</label>

  <select name="page_name" id="f_page_name" class="form-control" required>
    <?php foreach ($pages as $k=>$v): ?>
      <option value="<?= h($k) ?>"><?= h($v) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<div class="form-group">
  <label>Media Type</label>

  <select name="media_type"
          id="f_media_type"
          class="form-control"
          onchange="toggleMediaFields()">

    <option value="image">Image</option>
    <option value="video">Video</option>
  </select>
</div>

<div class="form-group image-field">
  <label>Upload Image</label>

  <input type="file"
         name="image"
         class="form-control"
         accept="image/*"
         onchange="previewImg(this,'prev_img')">

  <img id="prev_img"
       class="img-preview legacy-style-66253c00ea"
      
       alt="">
</div>

<div class="form-group video-field legacy-style-6b99de8b69">
  <label>Upload Video</label>

  <input type="file"
         name="video"
         class="form-control"
         accept="video/*">
</div>

<div class="form-group video-field legacy-style-6b99de8b69">
  <label>External Video URL</label>

  <input type="url"
         name="video_url"
         id="f_video_url"
         class="form-control"
         placeholder="https://youtube.com/...">
</div>

<div class="form-group">
  <label>Text Align</label>

  <select name="text_align" id="f_text_align" class="form-control">
    <option value="left">Left</option>
    <option value="center">Center</option>
    <option value="right">Right</option>
  </select>
</div>

<div class="form-group">
  <label>Overlay Opacity</label>

  <input type="number"
         step="0.05"
         min="0"
         max="1"
         name="overlay_opacity"
         id="f_overlay_opacity"
         class="form-control"
         value="0.45">
</div>

<div class="form-group">
  <label>Sort Order</label>

  <input type="number"
         name="sort_order"
         id="f_sort_order"
         class="form-control"
         value="0">
</div>

<div class="form-group legacy-style-9162a24597">
  <label class="legacy-style-c92fd9467d">
    <input type="checkbox"
           name="status"
           id="f_status"
           value="1"
           checked
           class="legacy-style-d3b80ea4fe">
    Active
  </label>
</div>

</div>
</div>

<div class="modal-footer">
  <button type="button"
          class="btn btn-secondary"
          onclick="closeModal()">
    Cancel
  </button>

  <button type="submit"
          class="btn btn-primary">
    <i class="fa fa-save"></i> Save Slide
  </button>
</div>

</form>
</div>
</div>

<script>
const modal = document.getElementById('slideModal');

function resetForm() {
  document.querySelector('#slideModal form').reset();

  document.getElementById('modalTitle').textContent='Add Slide';

  document.getElementById('f_id').value='';
  document.getElementById('f_existing_image').value='';
  document.getElementById('f_existing_video').value='';

  document.getElementById('f_status').checked=true;
  document.getElementById('f_overlay_opacity').value='0.45';
  document.getElementById('f_sort_order').value='0';

  toggleMediaFields();

  const img=document.getElementById('prev_img');
  img.style.display='none';
  img.src='';
}

function openModal() {
  resetForm();
  modal.classList.add('open');
}

function closeModal() {
  modal.classList.remove('open');
}

modal.addEventListener('click',e=>{
  if(e.target===modal) closeModal();
});

function toggleMediaFields() {

  const type=document.getElementById('f_media_type').value;

  document.querySelectorAll('.image-field').forEach(el=>{
    el.style.display=type==='image'?'block':'none';
  });

  document.querySelectorAll('.video-field').forEach(el=>{
    el.style.display=type==='video'?'block':'none';
  });
}

function previewImg(input,previewId) {

  const prev=document.getElementById(previewId);

  if(input.files && input.files[0]) {

    const reader=new FileReader();

    reader.onload=e=>{
      prev.src=e.target.result;
      prev.style.display='block';
    };

    reader.readAsDataURL(input.files[0]);
  }
}

function editSlide(d) {

  resetForm();

  document.getElementById('modalTitle').textContent='Edit Slide';

  document.getElementById('f_id').value=d.id || '';
  document.getElementById('f_title').value=d.title || '';
  document.getElementById('f_subtitle').value=d.subtitle || '';
  document.getElementById('f_button_text').value=d.button_text || '';
  document.getElementById('f_button_link').value=d.button_link || '';
  document.getElementById('f_page_name').value=d.page_name || 'home';
  document.getElementById('f_media_type').value=d.media_type || 'image';
  document.getElementById('f_video_url').value=d.video_url || '';
  document.getElementById('f_text_align').value=d.text_align || 'center';
  document.getElementById('f_overlay_opacity').value=d.overlay_opacity || '0.45';
  document.getElementById('f_sort_order').value=d.sort_order || 0;
  document.getElementById('f_status').checked=String(d.status)==='1';

  document.getElementById('f_existing_image').value=d.image_path || '';
  document.getElementById('f_existing_video').value=d.video_path || '';

  toggleMediaFields();

  if(d.image_path) {
    const img=document.getElementById('prev_img');
    img.src='<?= SITE_URL ?>/'+d.image_path;
    img.style.display='block';
  }

  modal.classList.add('open');
}

<?php if($edit): ?>
editSlide(<?= json_encode($edit, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>);
<?php endif; ?>
</script>

</body>
</html>
