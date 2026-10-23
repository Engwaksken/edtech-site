<?php
require_once __DIR__ . '/includes/process-site-settings.php';
$active_settings_tab = ($_POST['settings_active_tab'] ?? 'general') === 'popup' ? 'popup' : 'general';
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
          <input type="hidden" name="settings_active_tab" id="settingsActiveTab" value="<?= h($active_settings_tab) ?>">

          <div class="settings-tabs" role="tablist" aria-label="Site settings sections">
            <button type="button" class="settings-tab <?= $active_settings_tab === 'general' ? 'active' : '' ?>" role="tab" id="generalTab" aria-selected="<?= $active_settings_tab === 'general' ? 'true' : 'false' ?>" aria-controls="generalPanel" data-settings-tab="generalPanel"><i class="fa fa-cog"></i> General</button>
            <button type="button" class="settings-tab <?= $active_settings_tab === 'popup' ? 'active' : '' ?>" role="tab" id="popupTab" aria-selected="<?= $active_settings_tab === 'popup' ? 'true' : 'false' ?>" aria-controls="popupPanel" data-settings-tab="popupPanel"><i class="fa fa-window-restore"></i> Frontend Popup</button>
          </div>

          <section class="settings-tab-panel <?= $active_settings_tab === 'general' ? 'active' : '' ?>" role="tabpanel" id="generalPanel" aria-labelledby="generalTab" <?= $active_settings_tab !== 'general' ? 'hidden' : '' ?>>
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
          </section>

          <section class="settings-tab-panel <?= $active_settings_tab === 'popup' ? 'active' : '' ?>" role="tabpanel" id="popupPanel" aria-labelledby="popupTab" <?= $active_settings_tab !== 'popup' ? 'hidden' : '' ?>>
            <p class="settings-tab-help">Upload a designed popup graphic, or build a popup using text and an optional action button. The popup appears on public pages while enabled.</p>
            <div class="form-grid">
              <div class="form-group" style="grid-column:1/-1">
                <label style="display:flex;align-items:center;gap:10px">
                  <input type="checkbox" name="frontend_popup_enabled" value="1" <?= ($settings['frontend_popup_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                  Show popup on the frontend
                </label>
              </div>

              <div class="form-group">
                <label for="popupStart">Start Showing</label>
                <input id="popupStart" type="datetime-local" name="frontend_popup_start" class="form-control"
                       value="<?= !empty($settings['frontend_popup_start']) ? h(date('Y-m-d\\TH:i', strtotime($settings['frontend_popup_start']))) : '' ?>">
                <small>Leave blank to show immediately.</small>
              </div>
              <div class="form-group">
                <label for="popupStop">Stop Showing</label>
                <input id="popupStop" type="datetime-local" name="frontend_popup_stop" class="form-control"
                       value="<?= !empty($settings['frontend_popup_stop']) ? h(date('Y-m-d\\TH:i', strtotime($settings['frontend_popup_stop']))) : '' ?>">
                <small>Leave blank to keep the popup active until disabled.</small>
              </div>
              <div class="form-group">
                <label for="popupPriority">Priority</label>
                <input id="popupPriority" type="number" name="frontend_popup_priority" class="form-control" min="0" max="1000" step="1"
                       value="<?= h($settings['frontend_popup_priority'] ?? '0') ?>">
                <small>Higher values indicate higher priority when multiple popup campaigns are added.</small>
              </div>
              <div class="form-group">
                <label for="popupFrequency">Show How Often</label>
                <select id="popupFrequency" name="frontend_popup_frequency" class="form-control">
                  <?php $popup_frequency_value = $settings['frontend_popup_frequency'] ?? 'session'; ?>
                  <option value="every_page" <?= $popup_frequency_value === 'every_page' ? 'selected' : '' ?>>Every page view</option>
                  <option value="session" <?= $popup_frequency_value === 'session' ? 'selected' : '' ?>>Once per browser session</option>
                  <option value="daily" <?= $popup_frequency_value === 'daily' ? 'selected' : '' ?>>Once per day</option>
                  <option value="once" <?= $popup_frequency_value === 'once' ? 'selected' : '' ?>>Once per browser</option>
                </select>
              </div>
              <div class="form-group">
                <label for="popupDelay">Delay (seconds)</label>
                <input id="popupDelay" type="number" name="frontend_popup_delay" class="form-control" min="0" max="3600" step="1"
                       value="<?= h($settings['frontend_popup_delay'] ?? '0') ?>">
                <small>Choose 0 to show immediately.</small>
              </div>
              <div class="form-group" style="grid-column:1/-1">
                <label style="display:flex;align-items:center;gap:8px">
                  <input type="checkbox" name="frontend_popup_new_tab" value="1" <?= ($settings['frontend_popup_new_tab'] ?? '0') === '1' ? 'checked' : '' ?>>
                  Open popup button link in a new tab
                </label>
              </div>
              <div class="form-group">
                <label for="popupScope">Show on</label>
                <select id="popupScope" name="frontend_popup_scope" class="form-control">
                  <?php $popup_scope_value = $settings['frontend_popup_scope'] ?? 'all'; ?>
                  <option value="all" <?= $popup_scope_value === 'all' ? 'selected' : '' ?>>All frontend pages</option>
                  <option value="selected" <?= $popup_scope_value === 'selected' ? 'selected' : '' ?>>Selected pages only</option>
                </select>
              </div>
              <div class="form-group" style="grid-column:1/-1">
                <label for="popupPages">Selected Page Paths</label>
                <textarea id="popupPages" name="frontend_popup_pages" class="form-control" rows="3" placeholder="One path per line, e.g. / and /apply"><?= h($settings['frontend_popup_pages'] ?? '') ?></textarea>
                <small>Used when “Selected pages only” is chosen. Enter one frontend path per line (e.g. /, /apply, /register). A trailing “.php” is optional and full URLs are accepted.</small>
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

              <div class="settings-upload-card" style="grid-column:1/-1">
                <label for="frontendPopupImage">Designed Popup Image (optional)</label>
                <div class="settings-preview" style="max-width:520px;min-height:120px">
                  <?php if (!empty($settings['frontend_popup_image'])): ?>
                    <img src="../<?= h($settings['frontend_popup_image']) ?>" alt="Current popup design" style="max-width:100%;max-height:280px;object-fit:contain">
                  <?php else: ?>
                    <div class="preview-placeholder"><i class="fa fa-image"></i><span>No popup design uploaded</span></div>
                  <?php endif; ?>
                </div>
                <input id="frontendPopupImage" type="file" name="frontend_popup_image" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
                <small>JPG, PNG, WEBP, or GIF. Maximum file size: 5 MB.</small>
                <?php if (!empty($settings['frontend_popup_image'])): ?>
                  <label style="display:flex;align-items:center;gap:8px;margin-top:10px"><input type="checkbox" name="frontend_popup_remove_image" value="1"> Remove current popup image</label>
                <?php endif; ?>
              </div>
            </div>
          </section>

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

<style>
.settings-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 22px;border-bottom:1px solid #e2e8f0}
.settings-tab{padding:12px 16px;border:0;border-bottom:3px solid transparent;background:transparent;color:#475569;font:inherit;font-weight:700;cursor:pointer}
.settings-tab.active{border-bottom-color:#fc7f10;color:#172033}
.settings-tab-panel[hidden]{display:none}
.settings-tab-help{margin:0 0 20px;color:#64748b;line-height:1.6}
</style>
<script>
document.querySelectorAll('[data-settings-tab]').forEach(tab => {
  tab.addEventListener('click', () => {
    document.getElementById('settingsActiveTab').value = tab.dataset.settingsTab === 'popupPanel' ? 'popup' : 'general';
    document.querySelectorAll('[data-settings-tab]').forEach(item => {
      const active = item === tab;
      item.classList.toggle('active', active);
      item.setAttribute('aria-selected', String(active));
    });
    document.querySelectorAll('.settings-tab-panel').forEach(panel => {
      panel.hidden = panel.id !== tab.dataset.settingsTab;
      panel.classList.toggle('active', !panel.hidden);
    });
  });
});
</script>

</body>
</html>
