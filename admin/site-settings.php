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
