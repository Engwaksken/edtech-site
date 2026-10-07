<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

/**
 * Save/update a site setting.
 */
function partner_save_setting(mysqli $conn, string $key, string $value): bool
{
    $k = esc($conn, $key);
    $v = esc($conn, $value);

    return (bool) mysqli_query($conn, "
        INSERT INTO site_settings (setting_key, setting_value)
        VALUES ('$k', '$v')
        ON DUPLICATE KEY UPDATE setting_value = '$v'
    ");
}

/**
 * Get a setting safely.
 */
function partner_setting(mysqli $conn, string $key, string $default = ''): string
{
    if (function_exists('get_setting')) {
        return (string) get_setting($conn, $key, $default);
    }

    $k = esc($conn, $key);
    $q = mysqli_query($conn, "SELECT setting_value FROM site_settings WHERE setting_key='$k' LIMIT 1");

    if ($q && ($row = mysqli_fetch_assoc($q))) {
        return (string) $row['setting_value'];
    }

    return $default;
}

/**
 * Upload video for section background.
 */
function partner_upload_video(string $fileKey, string &$err = ''): string
{
    if (empty($_FILES[$fileKey]['name'])) {
        return '';
    }

    $allowed = ['mp4', 'webm', 'ogg'];
    $ext = strtolower(pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed, true)) {
        $err = 'Invalid video format. Upload MP4, WebM, or OGG.';
        return '';
    }

    $destDir = UPLOAD_PATH . '/settings/backgrounds/';
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    $filename = uniqid('partners_bg_', true) . '.' . $ext;
    $target = $destDir . $filename;

    if (!move_uploaded_file($_FILES[$fileKey]['tmp_name'], $target)) {
        $err = 'Video upload failed.';
        return '';
    }

    return 'uploads/settings/backgrounds/' . $filename;
}

/**
 * Handle partners section background settings.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_section_bg') {
        $err = '';

        $bgType = $_POST['partners_bg_type'] ?? 'color';
        if (!in_array($bgType, ['color', 'image', 'video'], true)) {
            $bgType = 'color';
        }

        $bgColor = trim($_POST['partners_bg_color'] ?? '#ffffff');
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $bgColor)) {
            $bgColor = '#ffffff';
        }

        $overlayEnabled = isset($_POST['partners_bg_overlay']) ? '1' : '0';

        $overlayColor = trim($_POST['partners_bg_overlay_color'] ?? '#000000');
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $overlayColor)) {
            $overlayColor = '#000000';
        }

        $overlayOpacity = (int)($_POST['partners_bg_overlay_opacity'] ?? 35);
        $overlayOpacity = max(0, min(100, $overlayOpacity));

        $sectionTag = trim($_POST['partners_section_tag'] ?? 'Partners and ecosystem collaboration');
        $sectionDescription = trim($_POST['partners_description'] ?? '');

        partner_save_setting($conn, 'partners_bg_type', $bgType);
        partner_save_setting($conn, 'partners_bg_color', $bgColor);
        partner_save_setting($conn, 'partners_bg_overlay', $overlayEnabled);
        partner_save_setting($conn, 'partners_bg_overlay_color', $overlayColor);
        partner_save_setting($conn, 'partners_bg_overlay_opacity', (string)$overlayOpacity);
        partner_save_setting($conn, 'partners_section_tag', $sectionTag);
        partner_save_setting($conn, 'partners_description', $sectionDescription);

        if (!empty($_FILES['partners_bg_image_file']['name'])) {
            $newImage = upload_image('partners_bg_image_file', 'settings/backgrounds', $err);

            if ($newImage) {
                partner_save_setting($conn, 'partners_bg_image', $newImage);
                partner_save_setting($conn, 'partners_bg_type', 'image');
            } else {
                flash('partners', $err ?: 'Background image upload failed.', 'error');
                header('Location: partners.php');
                exit;
            }
        }

        if (!empty($_FILES['partners_bg_video_file']['name'])) {
            $newVideo = partner_upload_video('partners_bg_video_file', $err);

            if ($newVideo) {
                partner_save_setting($conn, 'partners_bg_video', $newVideo);
                partner_save_setting($conn, 'partners_bg_type', 'video');
            } else {
                flash('partners', $err ?: 'Background video upload failed.', 'error');
                header('Location: partners.php');
                exit;
            }
        }

        flash('partners', 'Partners section background updated successfully.');
        header('Location: partners.php');
        exit;
    }

    if ($action === 'remove_bg_image') {
        partner_save_setting($conn, 'partners_bg_image', '');

        if (partner_setting($conn, 'partners_bg_type', 'color') === 'image') {
            partner_save_setting($conn, 'partners_bg_type', 'color');
        }

        flash('partners', 'Partners background image removed.');
        header('Location: partners.php');
        exit;
    }

    if ($action === 'remove_bg_video') {
        partner_save_setting($conn, 'partners_bg_video', '');

        if (partner_setting($conn, 'partners_bg_type', 'color') === 'video') {
            partner_save_setting($conn, 'partners_bg_type', 'color');
        }

        flash('partners', 'Partners background video removed.');
        header('Location: partners.php');
        exit;
    }
}

$edit = null;

if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];

    $stmt = $conn->prepare("SELECT * FROM partners WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $editId);
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$list = $conn->query("SELECT * FROM partners ORDER BY sort_order ASC, name ASC");

$site_name = partner_setting($conn, 'site_name', 'EdTech Fellowship');

$partners_section_tag         = partner_setting($conn, 'partners_section_tag', 'Partners and ecosystem collaboration');
$partners_description         = partner_setting($conn, 'partners_description', '');
$partners_bg_type             = partner_setting($conn, 'partners_bg_type', 'color');
$partners_bg_color            = partner_setting($conn, 'partners_bg_color', '#ffffff');
$partners_bg_image            = partner_setting($conn, 'partners_bg_image', '');
$partners_bg_video            = partner_setting($conn, 'partners_bg_video', '');
$partners_bg_overlay          = partner_setting($conn, 'partners_bg_overlay', '0');
$partners_bg_overlay_color    = partner_setting($conn, 'partners_bg_overlay_color', '#000000');
$partners_bg_overlay_opacity  = partner_setting($conn, 'partners_bg_overlay_opacity', '35');

$previewStyle = 'background:' . h($partners_bg_color) . ';';
if ($partners_bg_type === 'image' && $partners_bg_image !== '') {
    $previewStyle = "background-image:url('" . SITE_URL . '/' . h($partners_bg_image) . "');background-size:cover;background-position:center;";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Partners - <?= h($site_name) ?> Admin</title>

  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="assets/css/admin.css">

  <style>
    .partners-bg-card {
      margin-bottom: 24px;
    }

    .partners-bg-preview {
      position: relative;
      min-height: 230px;
      border-radius: 18px;
      overflow: hidden;
      border: 1px solid var(--border);
      display: flex;
      align-items: flex-end;
      padding: 28px;
      color: #fff;
      box-shadow: var(--shadow);
      margin-bottom: 20px;
    }

    .partners-bg-preview video {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .partners-bg-preview__overlay {
      position: absolute;
      inset: 0;
      background: <?= h($partners_bg_overlay_color) ?>;
      opacity: <?= $partners_bg_overlay === '1' ? ((int)$partners_bg_overlay_opacity / 100) : 0 ?>;
      z-index: 1;
    }

    .partners-bg-preview__content {
      position: relative;
      z-index: 2;
      max-width: 720px;
    }

    .partners-bg-preview__badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(252, 127, 16, .95);
      color: #fff;
      padding: 7px 14px;
      border-radius: 999px;
      font-size: .74rem;
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: .06em;
      margin-bottom: 12px;
    }

    .partners-bg-preview__content h2 {
      color: #fff;
      margin: 0 0 8px;
      font-size: clamp(1.55rem, 3vw, 2.7rem);
      line-height: 1.08;
    }

    .partners-bg-preview__content p {
      margin: 0;
      color: rgba(255,255,255,.9);
      line-height: 1.6;
    }

    .partners-bg-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 18px;
    }

    .partners-bg-grid .full {
      grid-column: 1 / -1;
    }

    .bg-type-options {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
    }

    .bg-type-option {
      position: relative;
      cursor: pointer;
    }

    .bg-type-option input {
      position: absolute;
      opacity: 0;
      pointer-events: none;
    }

    .bg-type-option span {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border: 1.5px solid var(--border);
      border-radius: 999px;
      padding: 9px 16px;
      background: #fff;
      color: var(--text-muted);
      font-weight: 700;
      font-size: .85rem;
      transition: var(--transition);
    }

    .bg-type-option input:checked + span {
      background: var(--primary);
      border-color: var(--primary);
      color: #fff;
    }

    .color-row {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .color-row input[type="color"] {
      width: 48px;
      height: 42px;
      border: 0;
      padding: 2px;
      background: transparent;
      cursor: pointer;
    }

    .range-row {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .range-row input[type="range"] {
      flex: 1;
    }

    .range-row input[type="number"] {
      max-width: 85px;
    }

    .mini-preview {
      width: 160px;
      height: 95px;
      object-fit: cover;
      border-radius: 10px;
      border: 1px solid var(--border);
      background: var(--light);
      margin-bottom: 10px;
      display: block;
    }

    .video-preview-box {
      width: 220px;
      height: 120px;
      border-radius: 10px;
      overflow: hidden;
      border: 1px solid var(--border);
      background: #111827;
      margin-bottom: 10px;
    }

    .video-preview-box video {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .partner-logo-preview {
      width: 120px;
      height: 90px;
      object-fit: contain;
      border: 1px dashed var(--border);
      border-radius: 10px;
      background: #f8f9fa;
      padding: 10px;
      margin-top: 8px;
      display: none;
    }

    @media (max-width: 768px) {
      .partners-bg-grid {
        grid-template-columns: 1fr;
      }

      .partners-bg-grid .full {
        grid-column: auto;
      }

      .partners-bg-preview {
        padding: 22px;
      }
    }
  </style>
</head>
<body>

<?php include 'includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">
  <header class="admin-topbar">
    <div class="topbar-left">
      <div class="topbar-breadcrumb">
        <a href="index.php">Dashboard</a> › <strong>Partners</strong>
      </div>
    </div>

    <div class="topbar-right">
      <a href="<?= SITE_URL ?>" target="_blank" class="btn btn-secondary btn-sm">
        <i class="fa fa-eye"></i> View Site
      </a>

      <div class="admin-avatar">
        <div class="avatar-circle">
          <?= h(strtoupper(substr($ADMIN['full_name'] ?? 'A', 0, 1))) ?>
        </div>
      </div>
    </div>
  </header>

  <div class="admin-content">
    <?php show_flash('partners'); ?>

    <div class="page-header">
      <div>
        <h1 class="page-title">Partners</h1>
        <p class="page-subtitle">Manage ecosystem partners, collaborators, and the partners section background.</p>
      </div>

      <button class="btn btn-primary" type="button" onclick="openModal()">
        <i class="fa fa-plus"></i> Add Partner
      </button>
    </div>

    <div class="card partners-bg-card">
      <div class="card-header">
        <h3 class="card-title">
          <i class="fa fa-image legacy-style-7d9b548184"></i>
          Partners Section Background
        </h3>
      </div>

      <div class="card-body">
        <div class="partners-bg-preview" style="<?= $previewStyle ?>">
          <?php if ($partners_bg_type === 'video' && $partners_bg_video !== ''): ?>
            <video autoplay muted loop playsinline>
              <source src="<?= SITE_URL . '/' . h($partners_bg_video) ?>">
            </video>
          <?php endif; ?>

          <div class="partners-bg-preview__overlay"></div>

          <div class="partners-bg-preview__content">
            <span class="partners-bg-preview__badge">
              <i class="fa fa-handshake"></i>
              <?= h(strtoupper($partners_bg_type)) ?> Background
            </span>

            <h2><?= h($partners_section_tag ?: 'Partners and ecosystem collaboration') ?></h2>

            <?php if ($partners_description !== ''): ?>
              <p><?= h($partners_description) ?></p>
            <?php else: ?>
              <p>Preview the partners section background as it will appear on the frontend.</p>
            <?php endif; ?>
          </div>
        </div>

        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="action" value="save_section_bg">

          <div class="partners-bg-grid">
            <div class="form-group full">
              <label>Background Type</label>
              <div class="bg-type-options">
                <label class="bg-type-option">
                  <input type="radio" name="partners_bg_type" value="color" <?= $partners_bg_type === 'color' ? 'checked' : '' ?>>
                  <span><i class="fa fa-palette"></i> Solid Color</span>
                </label>

                <label class="bg-type-option">
                  <input type="radio" name="partners_bg_type" value="image" <?= $partners_bg_type === 'image' ? 'checked' : '' ?>>
                  <span><i class="fa fa-image"></i> Image</span>
                </label>

                <label class="bg-type-option">
                  <input type="radio" name="partners_bg_type" value="video" <?= $partners_bg_type === 'video' ? 'checked' : '' ?>>
                  <span><i class="fa fa-film"></i> Video</span>
                </label>
              </div>
            </div>

            <div class="form-group">
              <label>Section Tag / Heading</label>
              <input type="text" name="partners_section_tag" class="form-control" value="<?= h($partners_section_tag) ?>">
            </div>

            <div class="form-group">
              <label>Solid Background Color</label>
              <div class="color-row">
                <input type="color" value="<?= h($partners_bg_color) ?>" id="bgColorPicker" oninput="syncBgColor()">
                <input type="text" name="partners_bg_color" id="bgColorText" class="form-control" value="<?= h($partners_bg_color) ?>" oninput="syncBgColorText()">
              </div>
            </div>

            <div class="form-group full">
              <label>Partners Description</label>
              <textarea name="partners_description" class="form-control" rows="3"><?= h($partners_description) ?></textarea>
            </div>

            <div class="form-group">
              <label>Upload Background Image</label>

              <?php if ($partners_bg_image !== ''): ?>
                <img src="<?= SITE_URL . '/' . h($partners_bg_image) ?>" class="mini-preview" alt="Current partners background">
              <?php endif; ?>

              <input type="file" name="partners_bg_image_file" class="form-control" accept="image/*">
              <span class="form-hint">Recommended: 1920×900px or wider.</span>
            </div>

            <div class="form-group">
              <label>Upload Background Video</label>

              <?php if ($partners_bg_video !== ''): ?>
                <div class="video-preview-box">
                  <video autoplay muted loop playsinline>
                    <source src="<?= SITE_URL . '/' . h($partners_bg_video) ?>">
                  </video>
                </div>
              <?php endif; ?>

              <input type="file" name="partners_bg_video_file" class="form-control" accept="video/mp4,video/webm,video/ogg">
              <span class="form-hint">MP4, WebM or OGG. Keep it lightweight for fast loading.</span>
            </div>

            <div class="form-group">
              <label>Overlay</label>
              <label class="legacy-style-cda9eb406d">
                <input type="checkbox" name="partners_bg_overlay" value="1" <?= $partners_bg_overlay === '1' ? 'checked' : '' ?>>
                Enable overlay for image/video
              </label>
            </div>

            <div class="form-group">
              <label>Overlay Color</label>
              <div class="color-row">
                <input type="color" value="<?= h($partners_bg_overlay_color) ?>" id="overlayColorPicker" oninput="syncOverlayColor()">
                <input type="text" name="partners_bg_overlay_color" id="overlayColorText" class="form-control" value="<?= h($partners_bg_overlay_color) ?>" oninput="syncOverlayColorText()">
              </div>
            </div>

            <div class="form-group full">
              <label>Overlay Opacity (%)</label>
              <div class="range-row">
                <input type="range" min="0" max="100" step="1" value="<?= h($partners_bg_overlay_opacity) ?>" id="overlayRange" oninput="syncOverlayOpacity()">
                <input type="number" min="0" max="100" name="partners_bg_overlay_opacity" class="form-control" value="<?= h($partners_bg_overlay_opacity) ?>" id="overlayNumber" oninput="syncOverlayOpacityNumber()">
              </div>
            </div>
          </div>

          <div class="modal-footer legacy-style-d2755826fa">
            <?php if ($partners_bg_image !== ''): ?>
              <button type="submit" name="action" value="remove_bg_image" class="btn btn-danger" onclick="return confirm('Remove background image?')">
                <i class="fa fa-times"></i> Remove Image
              </button>
            <?php endif; ?>

            <?php if ($partners_bg_video !== ''): ?>
              <button type="submit" name="action" value="remove_bg_video" class="btn btn-danger" onclick="return confirm('Remove background video?')">
                <i class="fa fa-times"></i> Remove Video
              </button>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary">
              <i class="fa fa-save"></i> Save Section Background
            </button>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Partner List</h3>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Logo</th>
              <th>Name</th>
              <th>Website</th>
              <th>Description</th>
              <th>Order</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>

          <tbody>
          <?php $has = false; ?>
          <?php if ($list): ?>
            <?php while ($p = $list->fetch_assoc()): $has = true; ?>
              <tr>
                <td>
                  <?php if (!empty($p['logo']) && file_exists('../' . $p['logo'])): ?>
                    <img src="<?= SITE_URL . '/' . h($p['logo']) ?>" class="tbl-img" style="object-fit:contain;background:#f8f9fa" alt="">
                  <?php else: ?>
                    <div class="tbl-img legacy-style-0bc547e344">
                      <i class="fa fa-handshake"></i>
                    </div>
                  <?php endif; ?>
                </td>

                <td><strong><?= h($p['name']) ?></strong></td>

                <td>
                  <?php if (!empty($p['website'])): ?>
                    <?php
                    $siteUrl = trim((string)$p['website']);
                    if ($siteUrl !== '' && !preg_match('#^https?://#i', $siteUrl)) {
                        $siteUrl = 'https://' . $siteUrl;
                    }
                    ?>
                    <a href="<?= h($siteUrl) ?>" target="_blank" rel="noopener noreferrer" style="color:var(--primary)">
                      <?= h(function_exists('truncate') ? truncate($p['website'], 30) : mb_strimwidth($p['website'], 0, 30, '…')) ?>
                    </a>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>

                <td>
                  <?= h(function_exists('truncate') ? truncate($p['description'] ?? '', 70) : mb_strimwidth($p['description'] ?? '', 0, 70, '…')) ?>
                </td>

                <td><?= (int)($p['sort_order'] ?? 0) ?></td>

                <td>
                  <span class="badge <?= (int)$p['status'] === 1 ? 'badge-success' : 'badge-gray' ?>">
                    <?= (int)$p['status'] === 1 ? 'Active' : 'Hidden' ?>
                  </span>
                </td>

                <td>
                  <div class="tbl-actions">
                    <button type="button"
                            onclick="editPartner(<?= h(json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>)"
                            class="btn btn-sm btn-secondary">
                      <i class="fa fa-edit"></i>
                    </button>

                    <form method="POST" action="includes/process-partners.php" onsubmit="return confirm('Delete this partner?')" class="legacy-style-cccfa4560d">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <button class="btn btn-sm btn-danger" type="submit">
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
                  <i class="fa fa-handshake"></i>
                  <h3>No partners yet</h3>
                  <button type="button" class="btn btn-primary" onclick="openModal()">
                    <i class="fa fa-plus"></i> Add Partner
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

<div class="modal-overlay" id="pModal">
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title" id="modalTitle">Add Partner</h2>
      <button class="modal-close" type="button" onclick="closeModal()">×</button>
    </div>

    <form method="POST" action="includes/process-partners.php" enctype="multipart/form-data">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="f_id">
      <input type="hidden" name="existing_logo" id="f_existing_logo">

      <div class="modal-body">
        <div class="form-grid form-grid-2">
          <div class="form-group full">
            <label>Partner Name <span class="req">*</span></label>
            <input type="text" name="name" id="f_name" class="form-control" required>
          </div>

          <div class="form-group">
            <label>Website</label>
            <input type="url" name="website" id="f_website" class="form-control" placeholder="https://example.org">
          </div>

          <div class="form-group">
            <label>Sort Order</label>
            <input type="number" name="sort_order" id="f_sort" class="form-control" value="0">
          </div>

          <div class="form-group full">
            <label>Description</label>
            <textarea name="description" id="f_desc" class="form-control" rows="3"></textarea>
          </div>

          <div class="form-group">
            <label>Logo</label>
            <input type="file" name="logo" class="form-control" accept="image/*" onchange="previewImg(this,'prev_logo')">
            <img id="prev_logo" class="partner-logo-preview" alt="">
          </div>

          <div class="form-group">
            <label>Status</label>
            <select name="status" id="f_status" class="form-control">
              <option value="1">Active</option>
              <option value="0">Hidden</option>
            </select>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">
          <i class="fa fa-save"></i> Save Partner
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

const modal = document.getElementById('pModal');

function resetPartnerForm() {
  document.getElementById('modalTitle').textContent = 'Add Partner';
  document.querySelector('#pModal form').reset();

  document.getElementById('f_id').value = '';
  document.getElementById('f_existing_logo').value = '';

  const prev = document.getElementById('prev_logo');
  prev.src = '';
  prev.style.display = 'none';

  document.getElementById('f_status').value = '1';
  document.getElementById('f_sort').value = '0';
}

function openModal() {
  resetPartnerForm();
  modal.classList.add('open');
}

function closeModal() {
  modal.classList.remove('open');
}

modal.addEventListener('click', e => {
  if (e.target === modal) closeModal();
});

function editPartner(d) {
  resetPartnerForm();

  document.getElementById('modalTitle').textContent = 'Edit Partner';
  document.getElementById('f_id').value = d.id || '';
  document.getElementById('f_name').value = d.name || '';
  document.getElementById('f_website').value = d.website || '';
  document.getElementById('f_desc').value = d.description || '';
  document.getElementById('f_sort').value = d.sort_order || 0;
  document.getElementById('f_status').value = String(d.status ?? 1);
  document.getElementById('f_existing_logo').value = d.logo || '';

  if (d.logo) {
    const el = document.getElementById('prev_logo');
    el.src = '<?= SITE_URL ?>/' + d.logo;
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

function syncBgColor() {
  const picker = document.getElementById('bgColorPicker');
  const text = document.getElementById('bgColorText');
  text.value = picker.value;
}

function syncBgColorText() {
  const picker = document.getElementById('bgColorPicker');
  const text = document.getElementById('bgColorText');

  if (/^#[0-9A-Fa-f]{6}$/.test(text.value)) {
    picker.value = text.value;
  }
}

function syncOverlayColor() {
  const picker = document.getElementById('overlayColorPicker');
  const text = document.getElementById('overlayColorText');
  text.value = picker.value;
}

function syncOverlayColorText() {
  const picker = document.getElementById('overlayColorPicker');
  const text = document.getElementById('overlayColorText');

  if (/^#[0-9A-Fa-f]{6}$/.test(text.value)) {
    picker.value = text.value;
  }
}

function syncOverlayOpacity() {
  const range = document.getElementById('overlayRange');
  const number = document.getElementById('overlayNumber');
  number.value = range.value;
}

function syncOverlayOpacityNumber() {
  const range = document.getElementById('overlayRange');
  const number = document.getElementById('overlayNumber');
  range.value = number.value;
}

<?php if ($edit): ?>
editPartner(<?= json_encode($edit, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>);
<?php endif; ?>
</script>

</body>
</html>
