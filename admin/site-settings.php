<?php
require_once __DIR__ . '/includes/process-site-settings.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
  <meta charset="UTF-8">
  <title>Site Settings - Admin</title>
  <link rel="stylesheet" href="assets/css/admin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body>

<?php include 'includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">
  <header class="admin-topbar">
    <div class="topbar-left">
      <div class="topbar-breadcrumb"><strong>Site Settings</strong></div>
    </div>
  </header>

  <div class="admin-content">

    <?php if (!empty($success)): ?>
      <div class="alert alert-success"><?= h($success) ?></div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
      <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header">
        <h3><i class="fa fa-cog"></i> Manage Site Settings</h3>
      </div>

      <div class="card-body">
        <form method="POST" enctype="multipart/form-data">

          <div class="form-grid">

            <div class="form-group">
              <label>Site Name</label>
              <input type="text" name="site_name" class="form-control"
                     value="<?= h($settings['site_name'] ?? '') ?>">
            </div>

            <div class="form-group">
              <label>Site Email</label>
              <input type="email" name="site_email" class="form-control"
                     value="<?= h($settings['site_email'] ?? '') ?>">
            </div>

            <div class="form-group">
              <label>Site Phone</label>
              <input type="text" name="site_phone" class="form-control"
                     value="<?= h($settings['site_phone'] ?? '') ?>">
            </div>

            <div class="form-group">
              <label>Site Address</label>
              <input type="text" name="site_address" class="form-control"
                     value="<?= h($settings['site_address'] ?? '') ?>">
            </div>

            <div class="settings-section-heading" style="grid-column:1/-1;border-top:1px solid #e2e8f0;padding-top:20px;margin-top:8px">
              <h3><i class="fa fa-window-restore"></i> Frontend Popup</h3>
              <p>Configure a site-wide popup for public-facing pages.</p>
            </div>

            <div class="form-group" style="grid-column:1/-1">
              <label style="display:flex;align-items:center;gap:10px">
                <input type="checkbox" name="frontend_popup_enabled" value="1" <?= ($settings['frontend_popup_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                Show popup on the frontend
              </label>
            </div>

            <div class="form-group">
              <label>Popup Title</label>
              <input type="text" name="frontend_popup_title" class="form-control" maxlength="160"
                     value="<?= h($settings['frontend_popup_title'] ?? '') ?>" placeholder="A message for your visitors">
            </div>

            <div class="form-group">
              <label>Button Text (optional)</label>
              <input type="text" name="frontend_popup_button_text" class="form-control" maxlength="60"
                     value="<?= h($settings['frontend_popup_button_text'] ?? '') ?>" placeholder="Learn more">
            </div>

            <div class="form-group" style="grid-column:1/-1">
              <label>Popup Message</label>
              <textarea name="frontend_popup_message" class="form-control" rows="4" maxlength="2000" placeholder="Write the message visitors should see."><?= h($settings['frontend_popup_message'] ?? '') ?></textarea>
            </div>

            <div class="form-group" style="grid-column:1/-1">
              <label>Button Link (optional)</label>
              <input type="text" name="frontend_popup_button_url" class="form-control"
                     value="<?= h($settings['frontend_popup_button_url'] ?? '') ?>" placeholder="https://example.com or /apply">
            </div>

<div class="settings-upload-grid">

  <div class="settings-upload-card">
    <label>Site Logo</label>

    <div class="settings-preview logo-preview">
      <?php if (!empty($settings['site_logo'])): ?>
        <img src="../<?= h($settings['site_logo']) ?>" alt="Site Logo">
      <?php else: ?>
        <div class="preview-placeholder">
          <i class="fa fa-image"></i>
          <span>No logo uploaded</span>
        </div>
      <?php endif; ?>
    </div>

    <input type="file" name="site_logo" class="form-control" accept="image/*">
    <small>Recommended size: 220 x 80px. Allowed: JPG, PNG, WEBP, GIF</small>
  </div>

  <div class="settings-upload-card">
    <label>Site Favicon</label>

    <div class="settings-preview favicon-preview">
      <?php if (!empty($settings['site_favicon'])): ?>
        <img src="../<?= h($settings['site_favicon']) ?>" alt="Site Favicon">
      <?php else: ?>
        <div class="preview-placeholder">
          <i class="fa fa-star"></i>
          <span>No favicon</span>
        </div>
      <?php endif; ?>
    </div>

    <input type="file" name="site_favicon" class="form-control" accept="image/*,.ico">
    <small>Recommended size: 32 x 32px or 64 x 64px. Allowed: ICO, PNG, JPG, WEBP</small>
  </div>

</div>

          </div>

          <div class="legacy-style-9eb125f52f">
            <button type="submit" name="save_settings" class="btn btn-primary">
              <i class="fa fa-save"></i> Save Settings
            </button>
          </div>

        </form>
      </div>
    </div>

  </div>
</div>

</body>
</html>
