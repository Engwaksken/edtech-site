<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

$edit = null;

if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];

    $stmt = $conn->prepare("
        SELECT *
        FROM cohorts
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $editId);
    $stmt->execute();

    $edit = $stmt->get_result()->fetch_assoc();

    $stmt->close();
}

$cohorts = $conn->query("
    SELECT
        c.*,
        (
            SELECT COUNT(*)
            FROM startups s
            WHERE s.cohort_id = c.id
        ) AS startup_count
    FROM cohorts c
    ORDER BY c.sort_order ASC, c.created_at DESC
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
  <title>Cohorts - <?= h($site_name) ?> Admin</title>
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
      <a href="index.php">Dashboard</a> > <strong>Cohorts</strong>
    </div>
  </div>
  <div class="topbar-right">
    <div class="admin-avatar">
      <div class="avatar-circle">
        <?= h(strtoupper(substr($ADMIN['full_name'] ?? 'A', 0, 1))) ?>
      </div>
      <div class="avatar-info">
        <span class="avatar-name"><?= h(explode(' ', $ADMIN['full_name'] ?? 'Admin')[0]) ?></span>
        <span class="avatar-role"><?= h(str_replace('_', ' ', $ADMIN['role'] ?? 'admin')) ?></span>
      </div>
    </div>
  </div>
</header>

<div class="admin-content">

<?php show_flash('cohorts'); ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Cohorts</h1>
    <p class="page-subtitle">Manage fellowship cohorts and their details.</p>
  </div>
  <div class="page-actions">
  <a href="referrals" class="btn btn-primary" >
      <i class="fa fa-hand-shake"></i> Venture Referrals
    </a>
    <button type="button" class="btn btn-primary" onclick="openModal()">
      <i class="fa fa-plus"></i> Add Cohort
    </button>
  </div>
</div>

<div class="card">
<div class="table-wrap">
<table>
<thead>
<tr>
  <th>Order</th>
  <th>Cohort</th>
  <th>Dates</th>
  <th>Startups</th>
  <th>Applications</th>
  <th>Status</th>
  <th>Background</th>
  <th>Actions</th>
</tr>
</thead>
<tbody>

<?php $has = false; ?>
<?php if ($cohorts): ?>
<?php while ($c = $cohorts->fetch_assoc()): $has = true; ?>

<tr>
  <td>
    <span class="drag-handle"><i class="fa fa-grip-vertical"></i></span>
    <?= (int)$c['sort_order'] ?>
  </td>

  <td>
    <?php if (!empty($c['image']) && file_exists('../' . $c['image'])): ?>
      <img src="<?= SITE_URL . '/' . h($c['image']) ?>" class="tbl-img"
           style="margin-right:10px;float:left" alt="">
    <?php endif; ?>
    <div>
      <strong><?= h($c['name']) ?></strong><br>
      <small class="legacy-style-5872de20d5">
        <?= h(function_exists('truncate')
            ? truncate($c['tagline'] ?? '', 60)
            : mb_strimwidth($c['tagline'] ?? '', 0, 60, '...')) ?>
      </small>
    </div>
  </td>

  <td class="legacy-style-aedede40ce">
    <?= !empty($c['start_date']) ? date('M j, Y', strtotime($c['start_date'])) : '-' ?><br>
    <?= !empty($c['end_date']) ? '→ ' . date('M j, Y', strtotime($c['end_date'])) : '' ?>
  </td>

  <td>
    <span class="badge badge-info"><?= (int)$c['startup_count'] ?> startups</span>
  </td>

  <td>
    <?php
    $appStatus = $c['application_status'] ?? 'closed';
    $appBadge  = $appStatus === 'open' ? 'badge-success'
               : ($appStatus === 'coming_soon' ? 'badge-warning' : 'badge-danger');
    ?>
    <span class="badge <?= h($appBadge) ?>">
      <?= h(str_replace('_', ' ', ucfirst($appStatus))) ?>
    </span>
  </td>

  <td>
    <?php
    $statusClass = $c['status'] === 'active' ? 'badge-success'
                 : ($c['status'] === 'draft' ? 'badge-warning' : 'badge-gray');
    ?>
    <span class="badge <?= h($statusClass) ?>"><?= h(ucfirst($c['status'])) ?></span>
  </td>

  <!-- Background type indicator -->
  <td>
    <?php
    $bgType = $c['bg_type'] ?? 'none';
    if ($bgType === 'color' && !empty($c['bg_color'])):
    ?>
      <span class="bg-type-badge color">
        <i class="fa fa-circle" style="color:<?= h($c['bg_color']) ?>;font-size:9px"></i>
        Color
      </span>
    <?php elseif ($bgType === 'image' && !empty($c['bg_image'])): ?>
      <span class="bg-type-badge image"><i class="fa fa-image"></i> Image</span>
    <?php elseif ($bgType === 'video' && !empty($c['bg_video'])): ?>
      <span class="bg-type-badge video"><i class="fa fa-film"></i> Video</span>
    <?php else: ?>
      <span class="legacy-style-2bb5163fc3">-</span>
    <?php endif; ?>
  </td>

  <td>
    <div class="tbl-actions">
      <a href="startups.php?cohort_id=<?= (int)$c['id'] ?>"
         class="btn btn-sm btn-teal" title="View startups">
        <i class="fa fa-rocket"></i>
      </a>

      <button type="button"
              onclick="editCohort(<?= h(json_encode($c, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>)"
              class="btn btn-sm btn-secondary">
        <i class="fa fa-edit"></i>
      </button>

      <form method="POST" action="includes/process-cohorts.php"
           
            onsubmit="return confirm('Delete this cohort and ALL its startups?')" class="legacy-style-cccfa4560d">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
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
  <td colspan="8">
    <div class="empty-state">
      <i class="fa fa-layer-group"></i>
      <h3>No cohorts yet</h3>
      <p>Create your first fellowship cohort to get started.</p>
      <button type="button" class="btn btn-primary" onclick="openModal()">
        <i class="fa fa-plus"></i> Add Cohort
      </button>
    </div>
  </td>
</tr>
<?php endif; ?>

</tbody>
</table>
</div>
</div>

</div><!-- /admin-content -->
</div><!-- /admin-main -->


<!-- ══════════════════════════════════════════════════════════
     COHORT MODAL
══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="cohortModal">
<div class="modal modal-lg">

  <div class="modal-header">
    <h2 class="modal-title" id="modalTitle">Add Cohort</h2>
    <button type="button" class="modal-close" onclick="closeModal()">x</button>
  </div>

  <form method="POST" action="includes/process-cohorts.php" enctype="multipart/form-data">

    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id"               id="f_id">
    <input type="hidden" name="existing_image"   id="f_existing_image">
    <input type="hidden" name="existing_banner"  id="f_existing_banner">
    <!-- bg hidden carry-overs (updated by JS) -->
    <input type="hidden" name="existing_bg_image" id="f_existing_bg_image">
    <input type="hidden" name="existing_bg_video" id="f_existing_bg_video">

    <div class="modal-body">
    <div class="form-grid form-grid-2">

      <!-- ── Core fields ── -->
      <div class="form-group full">
        <label>Cohort Name <span class="req">*</span></label>
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
        <label>Start Date</label>
        <input type="date" name="start_date" id="f_start_date" class="form-control">
      </div>

      <div class="form-group">
        <label>End Date</label>
        <input type="date" name="end_date" id="f_end_date" class="form-control">
      </div>

      <div class="form-group">
        <label>Application Status</label>
        <select name="application_status" id="f_app_status" class="form-control">
          <option value="closed">Closed</option>
          <option value="open">Open</option>
          <option value="coming_soon">Coming Soon</option>
        </select>
      </div>

      <div class="form-group">
        <label>Application Link</label>
        <input type="url" name="application_link" id="f_app_link" class="form-control" placeholder="https://...">
      </div>

      <div class="form-group">
        <label>Status</label>
        <select name="status" id="f_status" class="form-control">
          <option value="active">Active</option>
          <option value="draft">Draft</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>

      <div class="form-group">
        <label>Sort Order</label>
        <input type="number" name="sort_order" id="f_sort_order" class="form-control" value="0" min="0">
      </div>

      <div class="form-group">
        <label>Cohort Image</label>
        <input type="file" name="image" class="form-control" accept="image/*"
               onchange="previewImg(this,'prev_img')">
        <img id="prev_img" class="img-preview legacy-style-66253c00ea" alt="">
      </div>

      <div class="form-group">
        <label>Banner Image</label>
        <input type="file" name="banner_image" class="form-control" accept="image/*"
               onchange="previewImg(this,'prev_banner')">
        <img id="prev_banner" class="img-preview legacy-style-66253c00ea" alt="">
      </div>

      <!-- ════════════════════════════════════════
           SECTION BACKGROUND PANEL
      ════════════════════════════════════════ -->
      <div class="bg-section-divider">
        <span class="bsd-label"><span class="bsd-icon"><i class="fa fa-paint-brush"></i></span> Section Background</span>
      </div>

      <!-- Hidden: bg_type -->
      <input type="hidden" name="bg_type" id="f_bg_type" value="none">

      <!-- Type pills -->
      <div class="bg-type-pills">
        <button type="button" class="bg-type-pill active" id="pill_none"
                onclick="setCohortBgType('none')">
          <i class="fa fa-ban"></i> None
        </button>
        <button type="button" class="bg-type-pill" id="pill_color"
                onclick="setCohortBgType('color')">
          <i class="fa fa-palette"></i> Solid Color
        </button>
        <button type="button" class="bg-type-pill" id="pill_image"
                onclick="setCohortBgType('image')">
          <i class="fa fa-image"></i> Image
        </button>
        <button type="button" class="bg-type-pill" id="pill_video"
                onclick="setCohortBgType('video')">
          <i class="fa fa-film"></i> Video
        </button>
      </div>

      <!-- ── Sub-panel: COLOR ── -->
      <div class="cbg-sub" id="cbg_sub_color">
        <div class="form-group full">
          <label>Background Colour</label>
          <div class="color-swatch-row">
            <input type="color" id="cbg_colorpicker" value="#ffffff"
                   oninput="syncCbgColor()">
            <input type="text" class="form-control" id="cbg_colorhex"
                   name="bg_color" value="#ffffff" placeholder="#ffffff"
                   oninput="syncCbgColorHex()">
          </div>
          <div class="bg-preview-strip legacy-style-60b34d48e4" id="cbg_color_preview">
            <span>Colour Preview</span>
          </div>
        </div>
      </div>

      <!-- ── Sub-panel: IMAGE ── -->
      <div class="cbg-sub" id="cbg_sub_image">
        <div class="form-group full">
          <label>Background Image</label>
          <div id="cbg_img_current_wrap" class="legacy-style-92fa2f5a0c">
            <div class="bg-preview-strip" id="cbg_img_current_preview">
              <span>Current</span>
            </div>
            <button type="button" class="remove-bg-btn" onclick="removeCbgAsset('image')">
              <i class="fa fa-times"></i> Remove image
            </button>
          </div>
          <input type="file" name="bg_image_file" id="cbg_img_file" class="form-control"
                 accept="image/*" onchange="previewCbgImage(this)">
          <span class="form-hint">Recommended: 1920x1080px or wider · JPG / PNG / WebP</span>
          <div class="bg-preview-strip legacy-style-6b99de8b69" id="cbg_img_new_preview">
            <span>New Image Preview</span>
          </div>
        </div>
      </div>

      <!-- ── Sub-panel: VIDEO ── -->
      <div class="cbg-sub" id="cbg_sub_video">
        <div class="form-group full">
          <label>Background Video</label>
          <div id="cbg_vid_current_wrap" class="legacy-style-92fa2f5a0c">
            <div class="bg-preview-strip" id="cbg_vid_current_preview">
              <video id="cbg_vid_current_el" autoplay muted loop playsinline class="legacy-style-6b99de8b69"></video>
              <span>Current Video</span>
            </div>
            <button type="button" class="remove-bg-btn" onclick="removeCbgAsset('video')">
              <i class="fa fa-times"></i> Remove video
            </button>
          </div>
          <input type="file" name="bg_video_file" id="cbg_vid_file" class="form-control"
                 accept="video/mp4,video/webm,video/ogg"
                 onchange="previewCbgVideo(this)">
          <span class="form-hint">MP4 / WebM / OGG · Keep under 20 MB for best performance</span>
        </div>
      </div>

      <!-- ── Overlay (shared for image + video) ── -->
      <div id="cbg_overlay_section" class="legacy-style-e37c752d49">
        <div class="legacy-style-acb0f9ecee">
          <input type="hidden" name="bg_overlay" id="f_bg_overlay" value="0">
          <label class="overlay-toggle-row">
            <input type="checkbox" id="cbg_overlay_cb" onchange="toggleCbgOverlay()">
            Enable colour overlay (darkens or tints the background)
          </label>

          <div class="overlay-opts hidden" id="cbg_overlay_opts">
            <input type="hidden" name="bg_overlay_color" id="f_bg_overlay_color" value="#000000">
            <div class="color-swatch-row legacy-style-2f7ab6d1f3">
              <input type="color" id="cbg_ov_picker" value="#000000"
                     oninput="syncCbgOverlayColor()">
              <input type="text" class="form-control" id="cbg_ov_hex"
                     value="#000000" placeholder="#000000"
                     oninput="syncCbgOverlayHex()">
            </div>

            <input type="hidden" name="bg_overlay_opacity" id="f_bg_overlay_opacity" value="40">
            <div class="legacy-style-6975bf8bf3">
              <label class="legacy-style-14290e4dac">Opacity</label>
              <input type="range" min="0" max="100" id="cbg_ov_range" value="40"
                     oninput="syncCbgOverlayRange()" class="legacy-style-97445a8d93">
              <input type="number" class="form-control legacy-style-6d96246bd4" id="cbg_ov_num"
                     value="40" min="0" max="100"
                     oninput="syncCbgOverlayNum()">
              <span class="legacy-style-5e0faad207">%</span>
            </div>
          </div>
        </div>
      </div>

    </div><!-- /form-grid -->
    </div><!-- /modal-body -->

    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
      <button type="submit" class="btn btn-primary">
        <i class="fa fa-save"></i> Save Cohort
      </button>
    </div>

  </form>
</div>
</div><!-- /modal-overlay -->


<script>
/* ── Sidebar ── */
const sidebar = document.getElementById('adminSidebar');
const main    = document.getElementById('adminMain');
document.getElementById('sidebarToggle')?.addEventListener('click', () => {
  sidebar?.classList.toggle('collapsed');
  main?.classList.toggle('collapsed');
});

const modal = document.getElementById('cohortModal');

/* ── Modal show/hide (single source of truth) ──
   Keeps the overlay's 'open' class and the body's scroll-lock class
   ('modal-open' — already defined in admin.css) in sync everywhere the
   modal is opened or closed, so neither one can be missed. */
function showModal() {
  modal.classList.add('open');
  document.body.classList.add('modal-open');
}
function hideModal() {
  modal.classList.remove('open');
  document.body.classList.remove('modal-open');
}

/* ── Modal open/close ── */
function resetForm() {
  document.getElementById('modalTitle').textContent = 'Add Cohort';
  document.querySelector('#cohortModal form').reset();

  ['f_id','f_existing_image','f_existing_banner',
   'f_existing_bg_image','f_existing_bg_video'].forEach(id => {
    document.getElementById(id).value = '';
  });

  document.getElementById('f_app_status').value = 'closed';
  document.getElementById('f_status').value     = 'active';
  document.getElementById('f_sort_order').value = '0';

  ['prev_img','prev_banner'].forEach(id => {
    const el = document.getElementById(id);
    if (el) { el.style.display = 'none'; el.src = ''; }
  });

  // Reset bg panel
  setCohortBgType('none');
  document.getElementById('cbg_img_current_wrap').style.display = 'none';
  document.getElementById('cbg_vid_current_wrap').style.display = 'none';
  document.getElementById('cbg_colorpicker').value = '#ffffff';
  document.getElementById('cbg_colorhex').value    = '#ffffff';
  document.getElementById('cbg_color_preview').style.background = '#ffffff';
  document.getElementById('cbg_img_new_preview').style.display = 'none';
  document.getElementById('cbg_overlay_cb').checked = false;
  document.getElementById('cbg_overlay_opts').classList.add('hidden');
  document.getElementById('f_bg_overlay').value = '0';
}

function openModal()  { resetForm(); showModal(); }
function closeModal() { hideModal(); }
modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });

/* Close on Escape, but only while the modal is actually open. */
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && modal.classList.contains('open')) {
    closeModal();
  }
});

/* ── Edit populate ── */
function editCohort(d) {
  resetForm();
  document.getElementById('modalTitle').textContent = 'Edit Cohort';

  document.getElementById('f_id').value             = d.id || '';
  document.getElementById('f_name').value           = d.name || '';
  document.getElementById('f_tagline').value        = d.tagline || '';
  document.getElementById('f_description').value    = d.description || '';
  document.getElementById('f_start_date').value     = d.start_date || '';
  document.getElementById('f_end_date').value       = d.end_date || '';
  document.getElementById('f_app_status').value     = d.application_status || 'closed';
  document.getElementById('f_app_link').value       = d.application_link || '';
  document.getElementById('f_status').value         = d.status || 'active';
  document.getElementById('f_sort_order').value     = d.sort_order || 0;
  document.getElementById('f_existing_image').value = d.image || '';
  document.getElementById('f_existing_banner').value= d.banner_image || '';

  if (d.image) {
    const el = document.getElementById('prev_img');
    el.src = '<?= SITE_URL ?>/' + d.image;
    el.style.display = 'block';
  }
  if (d.banner_image) {
    const el = document.getElementById('prev_banner');
    el.src = '<?= SITE_URL ?>/' + d.banner_image;
    el.style.display = 'block';
  }

  // ── Restore background state ──
  const bgType = d.bg_type || 'none';
  setCohortBgType(bgType);

  if (bgType === 'color' && d.bg_color) {
    document.getElementById('cbg_colorpicker').value        = d.bg_color;
    document.getElementById('cbg_colorhex').value           = d.bg_color;
    document.getElementById('cbg_color_preview').style.background = d.bg_color;
  }

  if (bgType === 'image' && d.bg_image) {
    document.getElementById('f_existing_bg_image').value = d.bg_image;
    const wrap = document.getElementById('cbg_img_current_wrap');
    const prev = document.getElementById('cbg_img_current_preview');
    prev.style.backgroundImage = 'url(<?= SITE_URL ?>/' + d.bg_image + ')';
    prev.style.backgroundSize  = 'cover';
    prev.style.backgroundPosition = 'center';
    wrap.style.display = 'block';
  }

  if (bgType === 'video' && d.bg_video) {
    document.getElementById('f_existing_bg_video').value = d.bg_video;
    const wrap = document.getElementById('cbg_vid_current_wrap');
    const vidEl = document.getElementById('cbg_vid_current_el');
    vidEl.src = '<?= SITE_URL ?>/' + d.bg_video;
    vidEl.style.display = 'block';
    wrap.style.display = 'block';
  }

  // Overlay
  if (d.bg_overlay == '1') {
    document.getElementById('cbg_overlay_cb').checked = true;
    document.getElementById('cbg_overlay_opts').classList.remove('hidden');
    document.getElementById('f_bg_overlay').value = '1';
    if (d.bg_overlay_color) {
      document.getElementById('cbg_ov_picker').value = d.bg_overlay_color;
      document.getElementById('cbg_ov_hex').value    = d.bg_overlay_color;
      document.getElementById('f_bg_overlay_color').value = d.bg_overlay_color;
    }
    if (d.bg_overlay_opacity !== undefined) {
      document.getElementById('cbg_ov_range').value = d.bg_overlay_opacity;
      document.getElementById('cbg_ov_num').value   = d.bg_overlay_opacity;
      document.getElementById('f_bg_overlay_opacity').value = d.bg_overlay_opacity;
    }
  }

  showModal();
}

/* ── Standard img preview ── */
function previewImg(input, previewId) {
  const prev = document.getElementById(previewId);
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = e => { prev.src = e.target.result; prev.style.display = 'block'; };
    reader.readAsDataURL(input.files[0]);
  }
}

/* ════════════════════════════════════════
   Cohort Background Helpers
════════════════════════════════════════ */
function setCohortBgType(type) {
  document.getElementById('f_bg_type').value = type;

  // Update pills
  ['none','color','image','video'].forEach(t => {
    const p = document.getElementById('pill_' + t);
    if (p) p.classList.toggle('active', t === type);
  });

  // Show/hide sub-panels
  ['color','image','video'].forEach(t => {
    const el = document.getElementById('cbg_sub_' + t);
    if (el) el.classList.toggle('active', t === type);
  });

  // Overlay section only for image/video
  const overlaySection = document.getElementById('cbg_overlay_section');
  overlaySection.style.display = (type === 'image' || type === 'video') ? '' : 'none';
}

/* Color sync */
function syncCbgColor() {
  const v = document.getElementById('cbg_colorpicker').value;
  document.getElementById('cbg_colorhex').value = v;
  document.getElementById('cbg_color_preview').style.background = v;
}
function syncCbgColorHex() {
  const v = document.getElementById('cbg_colorhex').value;
  if (/^#[0-9A-Fa-f]{6}$/.test(v)) {
    document.getElementById('cbg_colorpicker').value = v;
    document.getElementById('cbg_color_preview').style.background = v;
  }
}

/* Overlay */
function toggleCbgOverlay() {
  const on = document.getElementById('cbg_overlay_cb').checked;
  document.getElementById('cbg_overlay_opts').classList.toggle('hidden', !on);
  document.getElementById('f_bg_overlay').value = on ? '1' : '0';
}
function syncCbgOverlayColor() {
  const v = document.getElementById('cbg_ov_picker').value;
  document.getElementById('cbg_ov_hex').value = v;
  document.getElementById('f_bg_overlay_color').value = v;
}
function syncCbgOverlayHex() {
  const v = document.getElementById('cbg_ov_hex').value;
  if (/^#[0-9A-Fa-f]{6}$/.test(v)) {
    document.getElementById('cbg_ov_picker').value = v;
    document.getElementById('f_bg_overlay_color').value = v;
  }
}
function syncCbgOverlayRange() {
  const v = document.getElementById('cbg_ov_range').value;
  document.getElementById('cbg_ov_num').value = v;
  document.getElementById('f_bg_overlay_opacity').value = v;
}
function syncCbgOverlayNum() {
  const v = document.getElementById('cbg_ov_num').value;
  document.getElementById('cbg_ov_range').value = v;
  document.getElementById('f_bg_overlay_opacity').value = v;
}

/* Image/video preview */
function previewCbgImage(input) {
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = e => {
      const el = document.getElementById('cbg_img_new_preview');
      el.style.backgroundImage    = 'url(' + e.target.result + ')';
      el.style.backgroundSize     = 'cover';
      el.style.backgroundPosition = 'center';
      el.style.display = 'flex';
      el.querySelector('span').textContent = 'New Image Preview';
    };
    reader.readAsDataURL(input.files[0]);
  }
}
function previewCbgVideo(input) {
  // No inline preview for video (file may be large); just show filename
}

/* Remove existing asset */
function removeCbgAsset(type) {
  if (!confirm('Remove current background ' + type + '?')) return;
  if (type === 'image') {
    document.getElementById('f_existing_bg_image').value = '';
    document.getElementById('cbg_img_current_wrap').style.display = 'none';
  } else {
    document.getElementById('f_existing_bg_video').value = '';
    const vidEl = document.getElementById('cbg_vid_current_el');
    vidEl.src = '';
    vidEl.style.display = 'none';
    document.getElementById('cbg_vid_current_wrap').style.display = 'none';
  }
}

/* ── Auto-open edit if URL param given ── */
<?php if ($edit): ?>
editCohort(<?= json_encode($edit, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>);
<?php endif; ?>
</script>

</body>
</html>
