<?php
require_once __DIR__ . '/includes/process-site-settings.php';

$tabs = [
    'general'      => ['General', 'fa-cog'],
    'theme'        => ['Theme Colours', 'fa-palette'],
    'fonts'        => ['Typography', 'fa-font'],
    'popup'        => ['Frontend Popup', 'fa-window-restore'],
    'legal'        => ['Legal Pages', 'fa-balance-scale'],
    'backups'      => ['Backups', 'fa-database'],
    'integrations' => ['Integrations', 'fa-plug'],
    'security'     => ['UAT & Security', 'fa-shield-alt'],
    'golive'       => ['AWS Go-Live', 'fa-rocket'],
];

$check_icon = ['ok' => ['fa-check-circle', 'badge-success'], 'warn' => ['fa-exclamation-triangle', 'badge-warning'], 'fail' => ['fa-times-circle', 'badge-danger']];

function ss_tab_open(string $key, string $active): void
{
    $is = $key === $active;
    echo '<section class="settings-tab-panel' . ($is ? ' active' : '') . '" role="tabpanel" id="panel-' . h($key) . '" aria-labelledby="tab-' . h($key) . '"' . ($is ? '' : ' hidden') . '>';
}

function ss_form_open(string $action, string $tab, bool $multipart = false, string $extra = ''): void
{
    echo '<form method="POST" action="site-settings.php?tab=' . h($tab) . '"' . ($multipart ? ' enctype="multipart/form-data"' : '') . ' ' . $extra . '>';
    echo '<input type="hidden" name="ss_action" value="' . h($action) . '">';
    echo '<input type="hidden" name="settings_active_tab" value="' . h($tab) . '">';
}

function ss_render_checks(array $checks, array $check_icon): void
{
    echo '<ul class="ss-checks">';
    foreach ($checks as $c) {
        [$icon, $badge] = $check_icon[$c['status']] ?? $check_icon['warn'];
        echo '<li><span class="ss-check-icon ' . h($badge) . '" title="' . h(ucfirst($c['status'])) . '"><i class="fa ' . h($icon) . '"></i></span>'
            . '<div><strong>' . h($c['label']) . '</strong><small>' . h($c['detail']) . '</small></div></li>';
    }
    echo '</ul>';
}

function ss_render_checklist(array $items, array $state): void
{
    echo '<div class="ss-checklist">';
    foreach ($items as $key => $label) {
        $row = $state[$key] ?? [];
        $done = !empty($row['done']);
        echo '<div class="ss-checklist-row' . ($done ? ' done' : '') . '">';
        echo '<label class="ss-checklist-label"><input type="checkbox" name="checklist[' . h($key) . '][done]" value="1"' . ($done ? ' checked' : '') . '> <span>' . h($label) . '</span></label>';
        echo '<div class="ss-checklist-meta">';
        echo '<input type="date" class="form-control" name="checklist[' . h($key) . '][date]" value="' . h($row['date'] ?? '') . '" aria-label="Completed on">';
        echo '<input type="text" class="form-control" name="checklist[' . h($key) . '][note]" value="' . h($row['note'] ?? '') . '" placeholder="Evidence / notes" maxlength="500">';
        if ($done && !empty($row['by'])) {
            echo '<small>by ' . h($row['by']) . '</small>';
        }
        echo '</div></div>';
    }
    echo '</div>';
}

$checklist_progress = static function (array $items, array $state): array {
    $done = 0;
    foreach ($items as $key => $_) {
        if (!empty($state[$key]['done'])) $done++;
    }
    return [$done, count($items)];
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Site Settings - Admin</title>
  <link rel="stylesheet" href="assets/css/admin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body>

<?php include 'includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">
  <header class="admin-topbar">
    <div class="topbar-left">
      <div class="topbar-breadcrumb"><a href="index.php">Dashboard</a> &rsaquo; <strong>Site Settings</strong></div>
    </div>
  </header>

  <div class="admin-content">

    <?php if ($success !== ''): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

    <div class="card">
      <div class="card-header">
        <h3><i class="fa fa-cog"></i> Site Settings</h3>
        <?php $env = $settings['app_environment'] ?? 'development'; ?>
        <span class="badge <?= $env === 'production' ? 'badge-success' : ($env === 'staging' ? 'badge-warning' : 'badge-gray') ?>" title="Environment"><i class="fa fa-server"></i> <?= h(ucfirst($env)) ?></span>
      </div>

      <div class="card-body">
        <nav class="settings-tabs" role="tablist" aria-label="Site settings sections">
          <?php foreach ($tabs as $key => [$label, $icon]): ?>
            <a href="site-settings.php?tab=<?= h($key) ?>" class="settings-tab <?= $active_tab === $key ? 'active' : '' ?>" role="tab" id="tab-<?= h($key) ?>"
               aria-selected="<?= $active_tab === $key ? 'true' : 'false' ?>" aria-controls="panel-<?= h($key) ?>" data-settings-tab="<?= h($key) ?>">
              <i class="fa <?= h($icon) ?>"></i> <?= h($label) ?>
            </a>
          <?php endforeach; ?>
        </nav>

        <?php /* ================================================= GENERAL */ ?>
        <?php ss_tab_open('general', $active_tab); ss_form_open('save_general', 'general', true); ?>
          <div class="form-grid form-grid-2">
            <div class="form-group"><label for="siteName">Site Name</label>
              <input id="siteName" type="text" name="site_name" class="form-control" maxlength="190" value="<?= h($settings['site_name'] ?? '') ?>"></div>
            <div class="form-group"><label for="siteEmail">Site Email</label>
              <input id="siteEmail" type="email" name="site_email" class="form-control" value="<?= h($settings['site_email'] ?? '') ?>"></div>
            <div class="form-group"><label for="sitePhone">Site Phone</label>
              <input id="sitePhone" type="text" name="site_phone" class="form-control" value="<?= h($settings['site_phone'] ?? '') ?>"></div>
            <div class="form-group"><label for="siteAddress">Site Address</label>
              <input id="siteAddress" type="text" name="site_address" class="form-control" value="<?= h($settings['site_address'] ?? '') ?>"></div>
          </div>

          <div class="settings-upload-grid">
            <div class="settings-upload-card">
              <label>Site Logo</label>
              <div class="settings-preview logo-preview">
                <?php if (!empty($settings['site_logo'])): ?>
                  <img src="../<?= h($settings['site_logo']) ?>" alt="Site Logo">
                <?php else: ?>
                  <div class="preview-placeholder"><i class="fa fa-image"></i><span>No logo uploaded</span></div>
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
                  <div class="preview-placeholder"><i class="fa fa-star"></i><span>No favicon</span></div>
                <?php endif; ?>
              </div>
              <input type="file" name="site_favicon" class="form-control" accept="image/*,.ico">
              <small>Recommended size: 32 x 32px or 64 x 64px. Allowed: ICO, PNG, JPG, WEBP</small>
            </div>
          </div>
          <div class="ss-actions"><button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save General Settings</button></div>
        </form></section>

        <?php /* ================================================= THEME */ ?>
        <?php ss_tab_open('theme', $active_tab); ss_form_open('save_theme', 'theme'); ?>
          <p class="settings-tab-help">Brand colours used across the public site, venture portal and (optionally) this admin panel. Pick a preset or set each colour.</p>
          <div class="ss-presets">
            <?php foreach (ss_theme_presets() as $name => $colors): ?>
              <button type="button" class="ss-preset" data-preset='<?= h(json_encode($colors)) ?>' title="Apply <?= h($name) ?> preset">
                <span class="ss-swatches"><?php foreach (array_slice($colors, 0, 4) as $c): ?><i style="background:<?= h($c) ?>"></i><?php endforeach; ?></span>
                <?= h($name) ?>
              </button>
            <?php endforeach; ?>
          </div>

          <div class="ss-theme-layout">
            <div class="form-grid form-grid-2">
              <?php foreach ([
                  'theme_primary' => ['Primary', 'Buttons, links, highlights'],
                  'theme_primary_dark' => ['Primary (hover / dark)', 'Hover and pressed states'],
                  'theme_accent' => ['Accent', 'Secondary highlights and badges'],
                  'theme_secondary' => ['Secondary', 'Supporting colour (teal by default)'],
                  'theme_text' => ['Body text', 'Main text colour'],
                  'theme_sidebar_bg' => ['Admin sidebar', 'Admin navigation background'],
              ] as $key => [$label, $hint]): ?>
                <div class="form-group">
                  <label for="<?= h($key) ?>"><?= h($label) ?></label>
                  <div class="ss-color-input">
                    <input type="color" id="<?= h($key) ?>_picker" value="<?= h($theme[$key]) ?>" data-color-for="<?= h($key) ?>" aria-label="<?= h($label) ?> picker">
                    <input type="text" id="<?= h($key) ?>" name="<?= h($key) ?>" class="form-control" value="<?= h($theme[$key]) ?>" pattern="#[0-9a-fA-F]{6}" maxlength="7" required data-theme-var="<?= h($key) ?>">
                  </div>
                  <small><?= h($hint) ?></small>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="ss-preview" id="themePreview" aria-label="Theme preview">
              <div class="ss-preview-side"><span></span><span class="on"></span><span></span></div>
              <div class="ss-preview-main">
                <h4>Preview heading</h4>
                <p>Body text appears in this colour. <a href="#" onclick="return false">A sample link</a>.</p>
                <div class="ss-preview-btns">
                  <span class="ss-pv-btn">Primary</span>
                  <span class="ss-pv-btn dark">Hover</span>
                  <span class="ss-pv-chip accent">Accent</span>
                  <span class="ss-pv-chip secondary">Secondary</span>
                </div>
              </div>
            </div>
          </div>

          <div class="form-group" style="margin-top:18px">
            <label class="ss-inline"><input type="checkbox" name="theme_apply_frontend" value="1" <?= ($settings['theme_apply_frontend'] ?? '1') === '1' ? 'checked' : '' ?>> Apply to the public website and venture portal</label>
            <label class="ss-inline"><input type="checkbox" name="theme_apply_admin" value="1" <?= ($settings['theme_apply_admin'] ?? '1') === '1' ? 'checked' : '' ?>> Apply to this admin panel</label>
          </div>
          <div class="ss-actions">
            <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Colours</button>
            <button type="submit" name="reset_theme" value="1" class="btn btn-secondary" onclick="return confirm('Reset all theme colours to the defaults?')"><i class="fa fa-undo"></i> Reset to Defaults</button>
          </div>
        </form></section>

        <?php /* ================================================= FONTS */ ?>
        <?php ss_tab_open('fonts', $active_tab); ss_form_open('save_fonts', 'fonts'); ?>
          <p class="settings-tab-help">Fonts load from Google Fonts. Changes apply wherever the theme is applied (see Theme Colours).</p>
          <div class="form-grid form-grid-2">
            <div class="form-group">
              <label for="fontBody">Body font</label>
              <select id="fontBody" name="font_body" class="form-control" data-font-preview="body">
                <?php foreach (ss_font_choices() as $font => $_): ?>
                  <option value="<?= h($font) ?>" <?= $theme['font_body'] === $font ? 'selected' : '' ?>><?= h($font) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="fontHeading">Heading font</label>
              <select id="fontHeading" name="font_heading" class="form-control" data-font-preview="heading">
                <?php foreach (ss_font_choices() as $font => $_): ?>
                  <option value="<?= h($font) ?>" <?= $theme['font_heading'] === $font ? 'selected' : '' ?>><?= h($font) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="fontSize">Base font size (<span id="fontSizeLabel"><?= (int)$theme['font_base_size'] ?></span>px)</label>
              <input id="fontSize" type="range" name="font_base_size" min="12" max="20" step="1" value="<?= (int)$theme['font_base_size'] ?>">
              <small>Scales text sized in rem. 16px is the default.</small>
            </div>
            <div class="form-group">
              <label for="fontWeight">Heading weight</label>
              <select id="fontWeight" name="font_heading_weight" class="form-control">
                <?php foreach (['400' => 'Regular (400)', '500' => 'Medium (500)', '600' => 'Semi-bold (600)', '700' => 'Bold (700)', '800' => 'Extra-bold (800)'] as $w => $l): ?>
                  <option value="<?= $w ?>" <?= $theme['font_heading_weight'] === (string)$w ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="ss-font-preview" id="fontPreview">
            <h2>The quick brown fox jumps over the lazy dog</h2>
            <p>EdTech fellows build products that improve learning across Africa. This paragraph previews the body font at the chosen base size.</p>
          </div>
          <div class="ss-actions">
            <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Typography</button>
            <button type="submit" name="reset_fonts" value="1" class="btn btn-secondary" onclick="return confirm('Reset fonts to the defaults?')"><i class="fa fa-undo"></i> Reset to Defaults</button>
          </div>
        </form></section>

        <?php /* ================================================= LEGAL */ ?>
        <?php ss_tab_open('legal', $active_tab); ss_form_open('save_legal', 'legal'); ?>
          <p class="settings-tab-help">Public pages for your privacy policy and terms of service. Google requires both links on the OAuth consent screen (Branding) before Google Calendar can be used by everyone. You can use HTML: headings, paragraphs, bold, italics, lists, links, quotes and tables are kept; scripts, styles, images and forms are removed when you save.</p>
          <?php
            $legal_site_name = $settings['site_name'] ?? 'Mastercard Foundation EdTech Fellowship';
            $legal_site_email = $settings['site_email'] ?? 'edtech@hivecolab.com';
          ?>
          <?php foreach (LEGAL_PAGES as $legal_key => $legal_meta): ?>
            <?php
              $legal_url = SITE_URL . '/' . $legal_meta['path'];
              $legal_value = (string)($settings[$legal_meta['setting']] ?? '');
              $legal_updated = (string)($settings[$legal_meta['updated']] ?? '');
            ?>
            <div class="ss-legal-block">
              <div class="ss-legal-head">
                <h4><i class="fa <?= h($legal_meta['icon']) ?>"></i> <?= h($legal_meta['title']) ?></h4>
                <span class="badge <?= $legal_value !== '' ? 'badge-success' : 'badge-warning' ?>"><?= $legal_value !== '' ? 'Published' : 'Not written yet' ?></span>
              </div>
              <div class="ss-legal-url">
                <label for="legalUrl<?= h($legal_key) ?>">Public link (paste this into Google Cloud Console)</label>
                <div class="ss-legal-url-row">
                  <input id="legalUrl<?= h($legal_key) ?>" type="text" class="form-control" readonly value="<?= h($legal_url) ?>" onclick="this.select()">
                  <button type="button" class="btn btn-secondary" data-copy-target="legalUrl<?= h($legal_key) ?>"><i class="fa fa-copy"></i> Copy</button>
                  <a class="btn btn-secondary" href="<?= h($legal_url) ?>" target="_blank" rel="noopener"><i class="fa fa-external-link-alt"></i> View</a>
                </div>
                <?php if ($legal_updated !== ''): ?><small>Last updated <?= h(date('j M Y', strtotime($legal_updated) ?: time())) ?></small><?php endif; ?>
              </div>
              <div class="form-group">
                <label for="legalHtml<?= h($legal_key) ?>">Page content (HTML)</label>
                <textarea id="legalHtml<?= h($legal_key) ?>" name="legal_<?= h($legal_key) ?>_html" class="form-control ss-legal-editor" rows="18" spellcheck="true"><?= h($legal_value) ?></textarea>
                <template id="legalStarter<?= h($legal_key) ?>"><?= legal_page_starter($legal_key, $legal_site_name, $legal_site_email) ?></template>
                <div class="ss-legal-tools">
                  <button type="button" class="btn btn-secondary btn-sm" data-legal-starter="<?= h($legal_key) ?>"><i class="fa fa-magic"></i> Insert starter text</button>
                  <button type="button" class="btn btn-secondary btn-sm" data-legal-preview="<?= h($legal_key) ?>"><i class="fa fa-eye"></i> Preview</button>
                  <small>Starter text is a draft: review it with whoever handles legal matters before publishing.</small>
                </div>
                <iframe class="ss-legal-preview" id="legalPreview<?= h($legal_key) ?>" title="<?= h($legal_meta['title']) ?> preview" sandbox hidden></iframe>
              </div>
            </div>
          <?php endforeach; ?>
          <div class="ss-actions"><button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Legal Pages</button></div>
        </form></section>

        <?php /* ================================================= POPUP */ ?>
        <?php ss_tab_open('popup', $active_tab); ss_form_open('save_popup', 'popup', true); ?>
          <p class="settings-tab-help">Upload a designed popup graphic, or build a popup using text and an optional action button. The popup appears on public pages while enabled.</p>
          <div class="form-grid form-grid-2">
            <div class="form-group full">
              <label class="ss-inline"><input type="checkbox" name="frontend_popup_enabled" value="1" <?= ($settings['frontend_popup_enabled'] ?? '0') === '1' ? 'checked' : '' ?>> Show popup on the frontend</label>
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
              <input id="popupPriority" type="number" name="frontend_popup_priority" class="form-control" min="0" max="1000" step="1" value="<?= h($settings['frontend_popup_priority'] ?? '0') ?>">
              <small>Higher values indicate higher priority when multiple popup campaigns are added.</small>
            </div>
            <div class="form-group">
              <label for="popupFrequency">Show How Often</label>
              <?php $popup_frequency_value = $settings['frontend_popup_frequency'] ?? 'session'; ?>
              <select id="popupFrequency" name="frontend_popup_frequency" class="form-control">
                <option value="every_page" <?= $popup_frequency_value === 'every_page' ? 'selected' : '' ?>>Every page view</option>
                <option value="session" <?= $popup_frequency_value === 'session' ? 'selected' : '' ?>>Once per browser session</option>
                <option value="daily" <?= $popup_frequency_value === 'daily' ? 'selected' : '' ?>>Once per day</option>
                <option value="once" <?= $popup_frequency_value === 'once' ? 'selected' : '' ?>>Once per browser</option>
              </select>
            </div>
            <div class="form-group">
              <label for="popupDelay">Delay (seconds)</label>
              <input id="popupDelay" type="number" name="frontend_popup_delay" class="form-control" min="0" max="3600" step="1" value="<?= h($settings['frontend_popup_delay'] ?? '0') ?>">
              <small>Choose 0 to show immediately.</small>
            </div>
            <div class="form-group">
              <label for="popupScope">Show on</label>
              <?php $popup_scope_value = $settings['frontend_popup_scope'] ?? 'all'; ?>
              <select id="popupScope" name="frontend_popup_scope" class="form-control">
                <option value="all" <?= $popup_scope_value === 'all' ? 'selected' : '' ?>>All frontend pages</option>
                <option value="selected" <?= $popup_scope_value === 'selected' ? 'selected' : '' ?>>Selected pages only</option>
              </select>
            </div>
            <div class="form-group full">
              <label for="popupPages">Selected Page Paths</label>
              <textarea id="popupPages" name="frontend_popup_pages" class="form-control" rows="3" placeholder="One path per line, e.g. / and /apply"><?= h($settings['frontend_popup_pages'] ?? '') ?></textarea>
              <small>Used when “Selected pages only” is chosen. Enter one frontend path per line (e.g. /, /apply, /register). A trailing “.php” is optional and full URLs are accepted.</small>
            </div>
            <div class="form-group full">
              <label class="ss-inline"><input type="checkbox" name="frontend_popup_new_tab" value="1" <?= ($settings['frontend_popup_new_tab'] ?? '0') === '1' ? 'checked' : '' ?>> Open popup button link in a new tab</label>
            </div>
            <div class="form-group"><label for="popupTitle">Popup Title</label>
              <input id="popupTitle" type="text" name="frontend_popup_title" class="form-control" maxlength="160" value="<?= h($settings['frontend_popup_title'] ?? '') ?>" placeholder="A message for your visitors"></div>
            <div class="form-group"><label for="popupBtnText">Button Text (optional)</label>
              <input id="popupBtnText" type="text" name="frontend_popup_button_text" class="form-control" maxlength="60" value="<?= h($settings['frontend_popup_button_text'] ?? '') ?>" placeholder="Learn more"></div>
            <div class="form-group full"><label for="popupMessage">Popup Message</label>
              <textarea id="popupMessage" name="frontend_popup_message" class="form-control" rows="4" maxlength="2000" placeholder="Write the message visitors should see."><?= h($settings['frontend_popup_message'] ?? '') ?></textarea></div>
            <div class="form-group full"><label for="popupBtnUrl">Button Link (optional)</label>
              <input id="popupBtnUrl" type="text" name="frontend_popup_button_url" class="form-control" value="<?= h($settings['frontend_popup_button_url'] ?? '') ?>" placeholder="https://example.com or /apply"></div>
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
                <label class="ss-inline" style="margin-top:10px"><input type="checkbox" name="frontend_popup_remove_image" value="1"> Remove current popup image</label>
              <?php endif; ?>
            </div>
          </div>
          <div class="ss-actions"><button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Popup</button></div>
        </form></section>

        <?php /* ================================================= BACKUPS */ ?>
        <?php ss_tab_open('backups', $active_tab); ?>
          <?php
            $last_status = $settings['backup_last_status'] ?? '';
            $remote_on = ($settings['backup_remote_enabled'] ?? '0') === '1';
          ?>
          <div class="ss-stats">
            <div class="ss-stat"><span>Last backup</span><strong><?= !empty($settings['backup_last_run_at']) ? h(date('j M Y, g:i a', strtotime($settings['backup_last_run_at']))) : 'Never' ?></strong>
              <?php if ($last_status !== ''): ?><small class="<?= $last_status === 'success' ? 'ss-ok' : 'ss-bad' ?>"><?= h(ucfirst($last_status)) ?></small><?php endif; ?></div>
            <div class="ss-stat"><span>Schedule</span><strong><?= h(ucfirst($settings['backup_schedule'] ?? 'off')) ?></strong></div>
            <div class="ss-stat"><span>Local copies</span><strong><?= count($backups) ?> file(s)</strong></div>
            <div class="ss-stat"><span>Remote (S3)</span><strong><?= $remote_on ? 'Enabled' : 'Off' ?></strong>
              <?php if ($remote_on): ?><small><?= h($settings['backup_remote_bucket'] ?? '') ?></small><?php endif; ?></div>
          </div>

          <div class="ss-panel">
            <h4><i class="fa fa-play-circle"></i> Run a backup now</h4>
            <?php ss_form_open('run_backup', 'backups', false, 'onsubmit="this.querySelector(\'button\').disabled=true;this.querySelector(\'button\').innerHTML=\'<i class=&quot;fa fa-spinner fa-spin&quot;></i> Backing up…\'"'); ?>
              <div class="ss-inline-group">
                <label class="ss-inline"><input type="checkbox" name="include_db" value="1" checked> Database</label>
                <label class="ss-inline"><input type="checkbox" name="include_uploads" value="1" <?= ($settings['backup_include_uploads'] ?? '0') === '1' ? 'checked' : '' ?>> Uploaded files</label>
                <label class="ss-inline"><input type="checkbox" name="push_remote" value="1" <?= $remote_on ? 'checked' : '' ?>> Also copy to remote storage</label>
                <button type="submit" class="btn btn-primary"><i class="fa fa-download"></i> Create Backup</button>
              </div>
              <small class="ss-muted">Large upload folders can take several minutes. Keep this tab open until the page reloads.</small>
            </form>
          </div>

          <?php ss_form_open('save_backup_settings', 'backups'); ?>
            <div class="ss-two-col">
              <div class="ss-panel">
                <h4><i class="fa fa-hdd"></i> Local backups</h4>
                <div class="form-grid">
                  <div class="form-group">
                    <label for="backupPath">Backup folder (server path)</label>
                    <input id="backupPath" type="text" name="backup_local_path" class="form-control" value="<?= h($settings['backup_local_path'] ?? '') ?>" placeholder="<?= h(ss_default_backup_dir()) ?>">
                    <small>Must be outside the public web root. Leave blank for the default shown.</small>
                    <?php if ($backup_dir_error !== ''): ?><small class="ss-bad"><?= h($backup_dir_error) ?></small><?php endif; ?>
                  </div>
                  <div class="form-grid form-grid-2">
                    <div class="form-group">
                      <label for="backupSchedule">Automatic schedule</label>
                      <?php $sched = $settings['backup_schedule'] ?? 'off'; ?>
                      <select id="backupSchedule" name="backup_schedule" class="form-control">
                        <?php foreach (['off' => 'Off (manual only)', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $k => $l): ?>
                          <option value="<?= $k ?>" <?= $sched === $k ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="form-group">
                      <label for="backupKeep">Copies to keep (per type)</label>
                      <input id="backupKeep" type="number" name="backup_retention_count" class="form-control" min="1" max="365" value="<?= h($settings['backup_retention_count'] ?? '7') ?>">
                    </div>
                  </div>
                  <label class="ss-inline"><input type="checkbox" name="backup_include_uploads" value="1" <?= ($settings['backup_include_uploads'] ?? '0') === '1' ? 'checked' : '' ?>> Include uploaded files in scheduled backups</label>
                  <label class="ss-inline"><input type="checkbox" name="backup_encrypt" value="1" <?= ($settings['backup_encrypt'] ?? '1') === '1' ? 'checked' : '' ?>> Encrypt backup files (AES-256-GCM)</label>
                  <?php if (!$encryption_ok): ?><small class="ss-bad">APP_ENCRYPTION_KEY is not set, so encrypted backups will fail until it is configured.</small><?php endif; ?>
                  <small class="ss-muted">Scheduled backups need this cron job on the server:<br><code>0 2 * * * php <?= h(ss_public_root() . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'run-backup.php') ?></code></small>
                </div>
              </div>

              <div class="ss-panel">
                <h4><i class="fa fa-cloud-upload-alt"></i> Remote backups (Amazon S3)</h4>
                <div class="form-grid">
                  <label class="ss-inline"><input type="checkbox" name="backup_remote_enabled" value="1" <?= $remote_on ? 'checked' : '' ?>> Copy every backup to remote storage</label>
                  <div class="form-grid form-grid-2">
                    <div class="form-group"><label for="rbBucket">Bucket</label>
                      <input id="rbBucket" type="text" name="backup_remote_bucket" class="form-control" value="<?= h($settings['backup_remote_bucket'] ?? '') ?>" placeholder="edtech-backups"></div>
                    <div class="form-group"><label for="rbRegion">Region</label>
                      <input id="rbRegion" type="text" name="backup_remote_region" class="form-control" value="<?= h($settings['backup_remote_region'] ?? '') ?>" placeholder="Same as AWS Go-Live"></div>
                    <div class="form-group"><label for="rbPrefix">Folder prefix</label>
                      <input id="rbPrefix" type="text" name="backup_remote_prefix" class="form-control" value="<?= h($settings['backup_remote_prefix'] ?? 'backups') ?>"></div>
                    <div class="form-group"><label for="rbEndpoint">Custom endpoint (optional)</label>
                      <input id="rbEndpoint" type="url" name="backup_remote_endpoint" class="form-control" value="<?= h($settings['backup_remote_endpoint'] ?? '') ?>" placeholder="For S3-compatible storage"></div>
                  </div>
                  <?php $use_aws = ($settings['backup_remote_use_aws_creds'] ?? '1') === '1'; ?>
                  <label class="ss-inline"><input type="checkbox" name="backup_remote_use_aws_creds" value="1" id="rbUseAws" <?= $use_aws ? 'checked' : '' ?>> Use the AWS credentials from the AWS Go-Live tab</label>
                  <div class="form-grid form-grid-2" id="rbCreds" <?= $use_aws ? 'hidden' : '' ?>>
                    <div class="form-group"><label for="rbAccess">Access key ID</label>
                      <input id="rbAccess" type="text" name="backup_remote_access_key" class="form-control" autocomplete="off" placeholder="<?= h(ss_mask(ss_secret($settings, 'backup_remote_access_key')) ?: 'AKIA…') ?>"></div>
                    <div class="form-group"><label for="rbSecret">Secret access key</label>
                      <input id="rbSecret" type="password" name="backup_remote_secret_key" class="form-control" autocomplete="new-password" placeholder="<?= ss_secret($settings, 'backup_remote_secret_key') !== '' ? 'Saved - leave blank to keep' : '' ?>"></div>
                  </div>
                </div>
              </div>
            </div>
            <div class="ss-actions">
              <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Backup Settings</button>
            </div>
          </form>
          <?php ss_form_open('test_backup_remote', 'backups', false, 'class="ss-inline-form"'); ?>
            <button type="submit" class="btn btn-secondary"><i class="fa fa-plug"></i> Test Remote Connection</button>
          </form>

          <div class="ss-panel">
            <h4><i class="fa fa-archive"></i> Local backup files</h4>
            <div class="table-wrap">
              <table>
                <thead><tr><th>File</th><th>Type</th><th>Size</th><th>Created</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (!$backups): ?>
                  <tr><td colspan="5" class="ss-muted">No backups yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($backups as $b): ?>
                  <tr>
                    <td><code><?= h($b['name']) ?></code></td>
                    <td><?= h($b['type']) ?> <?php if ($b['encrypted']): ?><i class="fa fa-lock" title="Encrypted"></i><?php endif; ?></td>
                    <td><?= h(ss_format_bytes($b['size'])) ?></td>
                    <td><?= h(date('j M Y, g:i a', $b['mtime'])) ?></td>
                    <td>
                      <div class="tbl-actions">
                        <a class="btn btn-sm btn-secondary" href="site-settings.php?tab=backups&amp;download_backup=<?= h(rawurlencode($b['name'])) ?>" title="Download" aria-label="Download"><i class="fa fa-download"></i></a>
                        <?php if ($remote_on || ($settings['backup_remote_bucket'] ?? '') !== ''): ?>
                          <?php ss_form_open('upload_backup_remote', 'backups', false, 'class="ss-inline-form"'); ?>
                            <input type="hidden" name="backup_name" value="<?= h($b['name']) ?>">
                            <button class="btn btn-sm btn-secondary" title="Copy to remote storage" aria-label="Copy to remote storage"><i class="fa fa-cloud-upload-alt"></i></button>
                          </form>
                        <?php endif; ?>
                        <?php ss_form_open('delete_backup', 'backups', false, 'class="ss-inline-form" onsubmit="return confirm(\'Delete this backup file permanently?\')"'); ?>
                          <input type="hidden" name="backup_name" value="<?= h($b['name']) ?>">
                          <button class="btn btn-sm btn-danger" title="Delete" aria-label="Delete"><i class="fa fa-trash"></i></button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <small class="ss-muted">Encrypted files (<code>.edenc</code>) are decrypted with <code>php bin/secure-storage.php</code> using the same APP_ENCRYPTION_KEY.</small>
          </div>

          <?php if ($backup_log): ?>
          <div class="ss-panel">
            <h4><i class="fa fa-history"></i> Backup history</h4>
            <div class="table-wrap">
              <table>
                <thead><tr><th></th><th>When</th><th>Trigger</th><th>Result</th></tr></thead>
                <tbody>
                <?php foreach ($backup_log as $entry): ?>
                  <tr>
                    <td><span class="ss-check-icon <?= !empty($entry['ok']) ? 'badge-success' : 'badge-danger' ?>" title="<?= !empty($entry['ok']) ? 'Success' : 'Failed' ?>"><i class="fa <?= !empty($entry['ok']) ? 'fa-check' : 'fa-times' ?>"></i></span></td>
                    <td><?= h(date('j M Y, g:i a', strtotime((string)$entry['at']))) ?></td>
                    <td><?= h((string)($entry['trigger'] ?? '')) ?></td>
                    <td><?= h((string)($entry['message'] ?? '')) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
          <?php endif; ?>
        </section>

        <?php /* ================================================= INTEGRATIONS */ ?>
        <?php ss_tab_open('integrations', $active_tab); ?>
          <?php
            $smtp_ok = !empty($email_row['smtp_host']);
            $gcal_ok = (getenv('GOOGLE_CALENDAR_CLIENT_ID') ?: ($settings['google_calendar_client_id'] ?? '')) !== '' && (getenv('GOOGLE_CALENDAR_CLIENT_SECRET') ?: ss_secret($settings, 'google_calendar_client_secret')) !== '';
            $s3_ok = $aws_cfg['bucket'] !== '' && $aws_cfg['access_key'] !== '' && $aws_cfg['secret_key'] !== '';
            $ga_value = array_key_exists('analytics_ga_id', $settings) ? $settings['analytics_ga_id'] : 'G-QY3FG79QBV';
            $integrations = [
                ['Email (SMTP)', 'fa-envelope', $smtp_ok, $smtp_ok ? h($email_row['smtp_host']) : 'Not configured'],
                ['Google Calendar', 'fa-calendar-alt', $gcal_ok, $gcal_ok ? 'OAuth client configured' : 'Configure OAuth credentials below'],
                ['Amazon S3', 'fa-aws', $s3_ok, $s3_ok ? h($aws_cfg['bucket']) : 'Configure in AWS Go-Live'],
                ['Google Analytics', 'fa-chart-line', $ga_value !== '', $ga_value !== '' ? h($ga_value) : 'Off'],
                ['Application portal (IMS)', 'fa-file-signature', true, 'External embed on /apply'],
            ];
          ?>
          <div class="ss-integrations">
            <?php foreach ($integrations as [$name, $icon, $ok, $detail]): ?>
              <div class="ss-integration">
                <i class="<?= $icon === 'fa-aws' ? 'fab' : 'fa' ?> <?= h($icon) ?> ss-int-icon"></i>
                <div><strong><?= h($name) ?></strong><small><?= $detail ?></small></div>
                <span class="ss-check-icon <?= $ok ? 'badge-success' : 'badge-warning' ?>" title="<?= $ok ? 'Connected' : 'Needs setup' ?>"><i class="fa <?= $ok ? 'fa-check' : 'fa-exclamation' ?>"></i></span>
              </div>
            <?php endforeach; ?>
          </div>

          <?php ss_form_open('save_integrations', 'integrations'); ?>
            <nav class="ss-subtabs" role="tablist" aria-label="Integration settings">
              <button type="button" class="ss-subtab active" role="tab" aria-selected="true" aria-controls="integration-panel-email" data-subtab="integration-panel-email"><i class="fa fa-envelope"></i> Outgoing email</button>
              <button type="button" class="ss-subtab" role="tab" aria-selected="false" aria-controls="integration-panel-services" data-subtab="integration-panel-services"><i class="fa fa-plug"></i> Other services</button>
            </nav>
            <div class="ss-subtab-panel" id="integration-panel-email" role="tabpanel">
              <div class="ss-panel">
                <h4><i class="fa fa-envelope"></i> Outgoing email (SMTP)</h4>
                <?php if (!$email_cols): ?>
                  <p class="ss-bad">The <code>email_settings</code> table was not found, so SMTP cannot be configured here.</p>
                <?php else: ?>
                  <div class="form-grid form-grid-2">
                    <div class="form-group"><label for="smtpHost">SMTP host</label>
                      <input id="smtpHost" type="text" name="smtp_host" class="form-control" value="<?= h($email_row['smtp_host'] ?? '') ?>" placeholder="smtp.sendgrid.net"></div>
                    <div class="form-group"><label for="smtpPort">Port</label>
                      <input id="smtpPort" type="number" name="smtp_port" class="form-control" min="1" max="65535" value="<?= h($email_row['smtp_port'] ?? '587') ?>"></div>
                    <div class="form-group"><label for="smtpUser">Username</label>
                      <input id="smtpUser" type="text" name="smtp_user" class="form-control" autocomplete="off" value="<?= h($email_row['smtp_user'] ?? '') ?>"></div>
                    <div class="form-group"><label for="smtpPass">Password / API key</label>
                      <input id="smtpPass" type="password" name="smtp_password" class="form-control" autocomplete="new-password" placeholder="<?= !empty($email_row['smtp_password']) ? 'Saved - leave blank to keep' : '' ?>"></div>
                    <div class="form-group"><label for="smtpSecure">Encryption</label>
                      <?php $sec = (string)($email_row['smtp_secure'] ?? 'tls'); ?>
                      <select id="smtpSecure" name="smtp_secure" class="form-control">
                        <option value="tls" <?= $sec === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS, port 587)</option>
                        <option value="ssl" <?= $sec === 'ssl' ? 'selected' : '' ?>>SSL (port 465)</option>
                        <option value="" <?= $sec === '' ? 'selected' : '' ?>>None</option>
                      </select></div>
                    <div class="form-group"><label for="fromEmail">Sender email</label>
                      <input id="fromEmail" type="email" name="from_email" class="form-control" value="<?= h($email_row['from_email'] ?? '') ?>"></div>
                    <div class="form-group full"><label for="fromName">Sender name</label>
                      <input id="fromName" type="text" name="from_name" class="form-control" value="<?= h($email_row['from_name'] ?? '') ?>"></div>
                  </div>
                  <?php if (!$encryption_ok): ?><small class="ss-bad">APP_ENCRYPTION_KEY is not set; a new SMTP password cannot be saved until it is.</small><?php endif; ?>
                  <?php if (!empty($settings['smtp_last_test'])): ?><small class="ss-muted">Last successful test: <?= h(substr($settings['smtp_last_test'], 3)) ?></small><?php endif; ?>
                <?php endif; ?>
              </div>
            </div>

            <div class="ss-subtab-panel" id="integration-panel-services" role="tabpanel" hidden>
              <div class="ss-panel">
                <h4><i class="fa fa-link"></i> Other integrations</h4>
                <div class="form-grid">
                  <div class="form-group"><label for="gaId">Google Analytics measurement ID</label>
                    <input id="gaId" type="text" name="analytics_ga_id" class="form-control" value="<?= h($ga_value) ?>" placeholder="G-XXXXXXXXXX">
                    <small>Leave blank to turn analytics off on the public site.</small></div>
                  <div class="form-group"><label for="imsUrl">Application portal (IMS) URL</label>
                    <input id="imsUrl" type="url" name="integration_ims_url" class="form-control" value="<?= h($settings['integration_ims_url'] ?? 'https://ims.hivecolab.com/opportunity-embed.php?id=1') ?>">
                    <small>Reference for the external application system embedded on /apply.</small></div>
                  <div class="form-group"><label for="imsContact">IMS owner / support contact</label>
                    <input id="imsContact" type="text" name="integration_ims_contact" class="form-control" value="<?= h($settings['integration_ims_contact'] ?? '') ?>" placeholder="Name, email or phone"></div>
                  <div class="form-group full"><label for="gcalClientId">Google Calendar OAuth client ID</label>
                    <input id="gcalClientId" type="text" name="google_calendar_client_id" class="form-control" value="<?= h($settings['google_calendar_client_id'] ?? '') ?>" autocomplete="off" placeholder="000000000000-….apps.googleusercontent.com">
                    <small>Used globally by calendar connections. A value in the server environment takes precedence.</small></div>
                  <div class="form-group full"><label for="gcalClientSecret">Google Calendar OAuth client secret</label>
                    <input id="gcalClientSecret" type="password" name="google_calendar_client_secret" class="form-control" autocomplete="new-password" placeholder="<?= ss_secret($settings, 'google_calendar_client_secret') !== '' ? 'Saved - leave blank to keep' : '' ?>">
                    <small>Stored encrypted. Leave blank to keep the saved secret. <a href="google-calendar-connect.php">Manage calendar connection</a>.</small></div>
                </div>
              </div>
            </div>
            <div class="ss-actions"><button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Integrations</button></div>
          </form>
          <?php if ($email_cols): ?>
            <?php ss_form_open('test_email', 'integrations', false, 'class="ss-inline-form"'); ?>
              <button type="submit" class="btn btn-secondary"><i class="fa fa-paper-plane"></i> Send Test Email to <?= h($_SESSION['admin_email'] ?? 'me') ?></button>
            </form>
          <?php endif; ?>
        </section>

        <?php /* ================================================= UAT & SECURITY */ ?>
        <?php ss_tab_open('security', $active_tab); ?>
          <?php $sec_sum = ss_check_summary($security_checks); ?>
          <div class="ss-panel">
            <h4><i class="fa fa-shield-alt"></i> Automated security checks
              <span class="ss-summary"><span class="badge badge-success"><?= $sec_sum['ok'] ?> passed</span> <span class="badge badge-warning"><?= $sec_sum['warn'] ?> warnings</span> <span class="badge badge-danger"><?= $sec_sum['fail'] ?> failed</span></span></h4>
            <?php ss_render_checks($security_checks, $check_icon); ?>
          </div>

          <?php ss_form_open('save_security', 'security'); ?>
            <div class="ss-panel">
              <h4><i class="fa fa-user-lock"></i> Security settings</h4>
              <div class="form-grid form-grid-2">
                <div class="form-group">
                  <label for="idleMinutes">Admin idle timeout (minutes)</label>
                  <input id="idleMinutes" type="number" name="security_admin_idle_minutes" class="form-control" min="0" max="1440" value="<?= h($settings['security_admin_idle_minutes'] ?? '0') ?>">
                  <small>Signs admins out after this long without activity. 0 turns it off.</small>
                </div>
              </div>
            </div>
            <?php [$sd, $st] = $checklist_progress(ss_security_checklist_items(), ss_checklist_state($settings, 'security_checklist')); ?>
            <div class="ss-panel">
              <h4><i class="fa fa-clipboard-check"></i> Security testing checklist <span class="ss-summary"><?= $sd ?> / <?= $st ?> complete</span></h4>
              <?php ss_render_checklist(ss_security_checklist_items(), ss_checklist_state($settings, 'security_checklist')); ?>
            </div>
            <div class="ss-actions"><button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Security</button></div>
          </form>

          <div class="ss-panel">
            <h4><i class="fa fa-vial"></i> User acceptance testing (UAT)
              <span class="ss-summary">
                <?php foreach (ss_uat_statuses() as $k => [$l, $b, $i]): ?>
                  <span class="badge <?= h($b) ?>" title="<?= h($l) ?>"><i class="fa <?= h($i) ?>"></i> <?= (int)$uat_summary[$k] ?></span>
                <?php endforeach; ?>
              </span></h4>

            <?php if (!$uat_cases): ?>
              <?php ss_form_open('uat_seed', 'security', false, 'class="ss-inline-form"'); ?>
                <button type="submit" class="btn btn-secondary btn-sm"><i class="fa fa-magic"></i> Add one starter case per module</button>
              </form>
            <?php endif; ?>

            <?php ss_form_open('uat_add', 'security', false, 'class="ss-uat-add"'); ?>
              <select name="module" class="form-control" required aria-label="Module">
                <?php foreach (ss_uat_modules() as $m): ?><option value="<?= h($m) ?>"><?= h($m) ?></option><?php endforeach; ?>
              </select>
              <input type="text" name="title" class="form-control" placeholder="Test case, e.g. Venture uploads a document and sees it in the list" maxlength="255" required aria-label="Test case">
              <button type="submit" class="btn btn-primary"><i class="fa fa-plus"></i> Add</button>
            </form>

            <div class="table-wrap">
              <table class="ss-uat-table">
                <thead><tr><th>Module</th><th>Test case</th><th>Status</th><th>Tester</th><th>Date</th><th></th></tr></thead>
                <tbody>
                <?php if (!$uat_cases): ?><tr><td colspan="6" class="ss-muted">No test cases yet.</td></tr><?php endif; ?>
                <?php foreach ($uat_cases as $case): [$sl, $sb, $si] = ss_uat_statuses()[$case['status']] ?? ss_uat_statuses()['not_tested']; ?>
                  <tr>
                    <td><small><?= h($case['module']) ?></small></td>
                    <td><?= h($case['title']) ?><?php if (!empty($case['notes'])): ?><br><small class="ss-muted"><?= h($case['notes']) ?></small><?php endif; ?></td>
                    <td><span class="ss-check-icon <?= h($sb) ?>" title="<?= h($sl) ?>" aria-label="<?= h($sl) ?>"><i class="fa <?= h($si) ?>"></i></span></td>
                    <td><?= h($case['tester'] ?? '') ?></td>
                    <td><?= !empty($case['tested_at']) ? h(date('j M Y', strtotime($case['tested_at']))) : '' ?></td>
                    <td>
                      <div class="tbl-actions">
                        <button type="button" class="btn btn-sm btn-secondary" title="Record result" aria-label="Record result"
                          data-uat='<?= h(json_encode(['id' => (int)$case['id'], 'title' => $case['title'], 'status' => $case['status'], 'tester' => $case['tester'] ?? '', 'tested_at' => $case['tested_at'] ?? '', 'notes' => $case['notes'] ?? ''])) ?>'><i class="fa fa-edit"></i></button>
                        <?php ss_form_open('uat_delete', 'security', false, 'class="ss-inline-form" onsubmit="return confirm(\'Delete this test case?\')"'); ?>
                          <input type="hidden" name="id" value="<?= (int)$case['id'] ?>">
                          <button class="btn btn-sm btn-danger" title="Delete" aria-label="Delete"><i class="fa fa-trash"></i></button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="ss-panel">
            <h4><i class="fa fa-signature"></i> UAT sign-off</h4>
            <?php if (!empty($settings['uat_signoff_name'])): ?>
              <div class="alert alert-success">Signed off by <strong><?= h($settings['uat_signoff_name']) ?></strong><?= !empty($settings['uat_signoff_role']) ? ' (' . h($settings['uat_signoff_role']) . ')' : '' ?> on <?= h(date('j M Y', strtotime($settings['uat_signoff_date']))) ?>.
                <?php if (!empty($settings['uat_signoff_recorded_by'])): ?>Recorded by <?= h($settings['uat_signoff_recorded_by']) ?>.<?php endif; ?></div>
              <?php if (!empty($settings['uat_signoff_notes'])): ?><p class="ss-muted"><?= nl2br(h($settings['uat_signoff_notes'])) ?></p><?php endif; ?>
              <?php ss_form_open('uat_signoff_clear', 'security', false, 'onsubmit="return confirm(\'Clear the recorded UAT sign-off?\')"'); ?>
                <button type="submit" class="btn btn-secondary btn-sm"><i class="fa fa-eraser"></i> Clear sign-off</button>
              </form>
            <?php else: ?>
              <?php if ($uat_summary['failed'] > 0 || $uat_summary['not_tested'] > 0): ?>
                <div class="alert alert-warning"><?= (int)$uat_summary['failed'] ?> failed and <?= (int)$uat_summary['not_tested'] ?> untested case(s) remain. Record sign-off only once the client accepts.</div>
              <?php endif; ?>
              <?php ss_form_open('uat_signoff', 'security'); ?>
                <div class="form-grid form-grid-3">
                  <div class="form-group"><label for="soName">Signed off by</label><input id="soName" type="text" name="uat_signoff_name" class="form-control" required maxlength="150"></div>
                  <div class="form-group"><label for="soRole">Role / organisation</label><input id="soRole" type="text" name="uat_signoff_role" class="form-control" maxlength="150"></div>
                  <div class="form-group"><label for="soDate">Date</label><input id="soDate" type="date" name="uat_signoff_date" class="form-control" required value="<?= date('Y-m-d') ?>"></div>
                  <div class="form-group full"><label for="soNotes">Notes / conditions</label><textarea id="soNotes" name="uat_signoff_notes" class="form-control" rows="2" maxlength="2000"></textarea></div>
                </div>
                <div class="ss-actions"><button type="submit" class="btn btn-primary"><i class="fa fa-signature"></i> Record Sign-off</button></div>
              </form>
            <?php endif; ?>
          </div>
        </section>

        <?php /* ================================================= AWS GO-LIVE */ ?>
        <?php ss_tab_open('golive', $active_tab); ?>
          <?php $gl_sum = ss_check_summary($golive_checks); $gl_total = max(1, count($golive_checks)); ?>
          <div class="ss-panel">
            <h4><i class="fa fa-rocket"></i> Go-live readiness
              <span class="ss-summary"><span class="badge badge-success"><?= $gl_sum['ok'] ?> ready</span> <span class="badge badge-warning"><?= $gl_sum['warn'] ?> warnings</span> <span class="badge badge-danger"><?= $gl_sum['fail'] ?> blocking</span></span></h4>
            <div class="ss-readiness"><span style="width:<?= round($gl_sum['ok'] / $gl_total * 100) ?>%"></span></div>
            <?php ss_render_checks($golive_checks, $check_icon); ?>
          </div>

          <?php ss_form_open('save_golive', 'golive'); ?>
            <div class="ss-two-col">
              <div class="ss-panel">
                <h4><i class="fab fa-aws"></i> AWS S3 storage</h4>
                <?php if ($aws_env_keys): ?><div class="alert alert-info">AWS keys are set in the server environment and take precedence over keys saved here.</div><?php endif; ?>
                <div class="form-grid form-grid-2">
                  <div class="form-group"><label for="awsBucket">S3 bucket</label>
                    <input id="awsBucket" type="text" name="aws_s3_bucket" class="form-control" value="<?= h($settings['aws_s3_bucket'] ?? '') ?>" placeholder="edtech-documents"></div>
                  <div class="form-group"><label for="awsRegion">Region</label>
                    <input id="awsRegion" type="text" name="aws_region" class="form-control" value="<?= h($settings['aws_region'] ?? '') ?>" placeholder="eu-west-1" list="awsRegions">
                    <datalist id="awsRegions"><?php foreach (['af-south-1', 'eu-west-1', 'eu-west-2', 'eu-central-1', 'us-east-1', 'us-west-2', 'me-south-1'] as $r): ?><option value="<?= $r ?>"><?php endforeach; ?></datalist></div>
                  <div class="form-group"><label for="awsAccess">Access key ID</label>
                    <input id="awsAccess" type="text" name="aws_access_key" class="form-control" autocomplete="off" placeholder="<?= h(ss_mask(ss_secret($settings, 'aws_access_key')) ?: 'AKIA…') ?>">
                    <small>Leave blank to keep the saved key.</small></div>
                  <div class="form-group"><label for="awsSecret">Secret access key</label>
                    <input id="awsSecret" type="password" name="aws_secret_key" class="form-control" autocomplete="new-password" placeholder="<?= ss_secret($settings, 'aws_secret_key') !== '' ? 'Saved - leave blank to keep' : '' ?>"></div>
                  <div class="form-group full">
                    <label class="ss-inline"><input type="checkbox" name="aws_s3_documents_enabled" value="1" <?= ($settings['aws_s3_documents_enabled'] ?? '0') === '1' ? 'checked' : '' ?>> Store new venture documents in S3 (up to 500 MB each)</label>
                    <small>Existing local documents stay where they are. Run the connection test before switching this on.</small>
                  </div>
                </div>
                <?php if (!empty($settings['aws_s3_last_test'])): ?>
                  <small class="<?= str_starts_with($settings['aws_s3_last_test'], 'ok') ? 'ss-ok' : 'ss-bad' ?>">Last test: <?= h($settings['aws_s3_last_test']) ?></small>
                <?php endif; ?>
              </div>

              <div class="ss-panel">
                <h4><i class="fa fa-calendar-check"></i> Deployment</h4>
                <div class="form-grid form-grid-2">
                  <div class="form-group"><label for="appEnv">Environment</label>
                    <select id="appEnv" name="app_environment" class="form-control">
                      <?php foreach (['development', 'staging', 'production'] as $e): ?><option value="<?= $e ?>" <?= ($settings['app_environment'] ?? 'development') === $e ? 'selected' : '' ?>><?= ucfirst($e) ?></option><?php endforeach; ?>
                    </select></div>
                  <div class="form-group"><label for="prodUrl">Production URL</label>
                    <input id="prodUrl" type="url" name="golive_production_url" class="form-control" value="<?= h($settings['golive_production_url'] ?? (defined('SITE_URL') ? SITE_URL : '')) ?>"></div>
                  <div class="form-group"><label for="plannedDate">Planned go-live</label>
                    <input id="plannedDate" type="date" name="golive_planned_date" class="form-control" value="<?= h($settings['golive_planned_date'] ?? '') ?>"></div>
                  <div class="form-group"><label for="actualDate">Actual go-live</label>
                    <input id="actualDate" type="date" name="golive_actual_date" class="form-control" value="<?= h($settings['golive_actual_date'] ?? '') ?>"></div>
                  <div class="form-group"><label for="hypercare">Hypercare ends</label>
                    <input id="hypercare" type="date" name="golive_hypercare_end" class="form-control" value="<?= h($settings['golive_hypercare_end'] ?? '') ?>"></div>
                  <div class="form-group"><label for="supportContact">Support contact</label>
                    <input id="supportContact" type="text" name="golive_support_contact" class="form-control" value="<?= h($settings['golive_support_contact'] ?? '') ?>" placeholder="Name / email / phone"></div>
                </div>
              </div>
            </div>

            <?php [$gd, $gt] = $checklist_progress(ss_golive_checklist_items(), ss_checklist_state($settings, 'golive_checklist')); ?>
            <div class="ss-panel">
              <h4><i class="fa fa-tasks"></i> Go-live checklist <span class="ss-summary"><?= $gd ?> / <?= $gt ?> complete</span></h4>
              <?php ss_render_checklist(ss_golive_checklist_items(), ss_checklist_state($settings, 'golive_checklist')); ?>
            </div>
            <div class="ss-actions"><button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Go-Live Settings</button></div>
          </form>
          <?php ss_form_open('test_aws', 'golive', false, 'class="ss-inline-form"'); ?>
            <button type="submit" class="btn btn-secondary"><i class="fa fa-plug"></i> Test S3 Connection</button>
          </form>
        </section>

      </div>
    </div>
  </div>
</div>

<?php /* UAT result modal */ ?>
<div class="ss-modal" id="uatModal" aria-hidden="true" hidden>
  <div class="ss-modal-box" role="dialog" aria-modal="true" aria-labelledby="uatModalTitle">
    <div class="ss-modal-head">
      <h3 id="uatModalTitle">Record test result</h3>
      <button type="button" class="ss-modal-close" data-close-modal aria-label="Close"><i class="fa fa-times"></i></button>
    </div>
    <?php ss_form_open('uat_update', 'security'); ?>
      <input type="hidden" name="id" id="uatId">
      <p id="uatTitle" class="ss-muted"></p>
      <div class="form-grid form-grid-2">
        <div class="form-group"><label for="uatStatus">Status</label>
          <select id="uatStatus" name="status" class="form-control">
            <?php foreach (ss_uat_statuses() as $k => [$l]): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?>
          </select></div>
        <div class="form-group"><label for="uatDate">Tested on</label><input id="uatDate" type="date" name="tested_at" class="form-control"></div>
        <div class="form-group full"><label for="uatTester">Tester</label><input id="uatTester" type="text" name="tester" class="form-control" maxlength="150" placeholder="<?= h($_SESSION['admin_name'] ?? '') ?>"></div>
        <div class="form-group full"><label for="uatNotes">Notes / defects</label><textarea id="uatNotes" name="notes" class="form-control" rows="4" maxlength="2000"></textarea></div>
      </div>
      <div class="ss-actions">
        <button type="button" class="btn btn-secondary" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Result</button>
      </div>
    </form>
  </div>
</div>

<style>
.card-header{display:flex;align-items:center;justify-content:space-between;gap:12px}
.settings-tabs{display:flex;gap:4px;flex-wrap:wrap;margin:0 0 22px;border-bottom:1px solid #e2e8f0}
.settings-tab{padding:11px 14px;border-bottom:3px solid transparent;color:#475569;font-weight:700;text-decoration:none;white-space:nowrap}
.settings-tab:hover{color:#172033}
.settings-tab.active{border-bottom-color:var(--primary,#fc7f10);color:#172033}
.settings-tab-panel[hidden]{display:none}
.ss-legal-block{border:1px solid #e2e8f0;border-radius:12px;padding:18px;margin-bottom:20px}
.ss-legal-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
.ss-legal-head h4{margin:0}
.ss-legal-url{margin-bottom:14px}
.ss-legal-url label{display:block;font-weight:600;margin-bottom:6px}
.ss-legal-url-row{display:flex;gap:8px;flex-wrap:wrap}
.ss-legal-url-row .form-control{flex:1 1 260px;min-width:0}
.ss-legal-editor{font-family:Consolas,Menlo,monospace;font-size:13px;line-height:1.5}
.ss-legal-tools{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:8px}
.ss-legal-tools small{color:#64748b}
.ss-legal-preview{display:block;width:100%;height:480px;margin-top:12px;border:1px dashed #cbd5e1;border-radius:10px;background:#fff}
.ss-legal-preview[hidden]{display:none}
.ss-subtabs{display:flex;gap:4px;flex-wrap:wrap;border-bottom:1px solid #e2e8f0;margin:4px 0 18px}
.ss-subtab{border:0;border-bottom:3px solid transparent;background:transparent;padding:10px 14px;color:#475569;font:inherit;font-weight:650;cursor:pointer}
.ss-subtab:hover,.ss-subtab.active{color:#172033}
.ss-subtab.active{border-bottom-color:var(--primary,#fc7f10)}
.ss-subtab-panel[hidden]{display:none}
.settings-tab-help{margin:0 0 20px;color:#64748b;line-height:1.6}
.ss-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}
.ss-inline-form{display:inline-block;margin:0}
form + .ss-inline-form{margin-top:10px}
.ss-inline{display:flex !important;align-items:center;gap:8px;font-weight:500;cursor:pointer}
.ss-inline-group{display:flex;align-items:center;gap:18px;flex-wrap:wrap;margin-bottom:6px}
.ss-muted{color:#64748b}
.ss-ok{color:var(--success,#1a7f37);font-weight:600}
.ss-bad{color:var(--danger,#cf222e)}
.ss-panel{border:1px solid #e2e8f0;border-radius:12px;padding:18px 20px;margin-top:18px;background:#fff}
.ss-panel h4{margin:0 0 14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:15px}
.ss-summary{margin-left:auto;display:inline-flex;gap:6px;flex-wrap:wrap;font-weight:500;font-size:13px;color:#64748b}
.ss-two-col{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.ss-two-col > .ss-panel{margin-top:18px}
.ss-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.ss-stat{border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;display:flex;flex-direction:column;gap:4px}
.ss-stat span{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#64748b}
.ss-stat strong{font-size:16px}
.ss-check-icon{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;flex:0 0 28px;padding:0;cursor:help}
.ss-checks{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:1fr 1fr;gap:10px 22px}
.ss-checks li{display:flex;gap:10px;align-items:flex-start}
.ss-checks li div{display:flex;flex-direction:column}
.ss-checks small{color:#64748b;word-break:break-word}
.ss-checklist{display:flex;flex-direction:column;gap:8px}
.ss-checklist-row{display:grid;grid-template-columns:minmax(260px,1.2fr) 2fr;gap:12px;align-items:center;padding:8px 10px;border-radius:8px;background:#f8fafc}
.ss-checklist-row.done{background:#f0fdf4}
.ss-checklist-label{display:flex;gap:10px;align-items:flex-start;cursor:pointer;font-weight:500}
.ss-checklist-label input{margin-top:3px}
.ss-checklist-meta{display:grid;grid-template-columns:150px 1fr auto;gap:8px;align-items:center}
.ss-checklist-meta small{color:#64748b;white-space:nowrap}
.ss-readiness{height:10px;background:#e2e8f0;border-radius:99px;overflow:hidden;margin-bottom:16px}
.ss-readiness span{display:block;height:100%;background:var(--success,#1a7f37)}
.ss-presets{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px}
.ss-preset{display:flex;flex-direction:column;align-items:flex-start;gap:6px;border:1px solid #e2e8f0;background:#fff;border-radius:10px;padding:10px 12px;cursor:pointer;font:inherit;font-weight:600;font-size:13px}
.ss-preset:hover{border-color:#94a3b8}
.ss-swatches{display:flex}
.ss-swatches i{width:20px;height:20px;border-radius:50%;border:2px solid #fff;margin-left:-5px;box-shadow:0 0 0 1px #e2e8f0}
.ss-swatches i:first-child{margin-left:0}
.ss-theme-layout{display:grid;grid-template-columns:1.4fr 1fr;gap:24px;align-items:start}
.ss-color-input{display:flex;gap:8px;align-items:center}
.ss-color-input input[type=color]{width:44px;height:40px;padding:2px;border:1px solid #d0d7de;border-radius:8px;background:#fff;cursor:pointer;flex:0 0 44px}
.ss-preview{display:flex;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;min-height:200px;position:sticky;top:80px}
.ss-preview-side{width:60px;background:var(--pv-sidebar);display:flex;flex-direction:column;gap:10px;padding:16px 12px}
.ss-preview-side span{height:8px;border-radius:4px;background:rgba(255,255,255,.25)}
.ss-preview-side span.on{background:var(--pv-primary)}
.ss-preview-main{flex:1;padding:18px;color:var(--pv-text)}
.ss-preview-main h4{margin:0 0 8px;color:var(--pv-text)}
.ss-preview-main a{color:var(--pv-primary);font-weight:600}
.ss-preview-btns{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.ss-pv-btn{background:var(--pv-primary);color:#fff;padding:7px 14px;border-radius:8px;font-weight:600;font-size:13px}
.ss-pv-btn.dark{background:var(--pv-primary-dark)}
.ss-pv-chip{padding:5px 10px;border-radius:99px;font-size:12px;font-weight:600;color:#fff}
.ss-pv-chip.accent{background:var(--pv-accent)}
.ss-pv-chip.secondary{background:var(--pv-secondary)}
.ss-font-preview{margin-top:20px;border:1px dashed #cbd5e1;border-radius:12px;padding:20px}
.ss-font-preview h2{margin:0 0 8px}
.ss-integrations{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}
.ss-integration{display:flex;align-items:center;gap:12px;border:1px solid #e2e8f0;border-radius:12px;padding:14px}
.ss-integration div{flex:1;display:flex;flex-direction:column;min-width:0}
.ss-integration small{color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ss-int-icon{font-size:22px;color:var(--primary,#fc7f10);width:28px;text-align:center}
.ss-uat-add{display:grid;grid-template-columns:220px 1fr auto;gap:8px;margin:12px 0}
.ss-uat-table td{vertical-align:top}
.ss-modal[hidden]{display:none}
.ss-modal{position:fixed;inset:0;background:rgba(15,23,42,.5);display:flex;align-items:center;justify-content:center;z-index:2000;padding:16px}
.ss-modal-box{background:#fff;border-radius:14px;width:min(560px,100%);max-height:90vh;overflow:auto;padding:22px}
.ss-modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.ss-modal-head h3{margin:0}
.ss-modal-close{border:0;background:transparent;font-size:18px;cursor:pointer;color:#64748b}
@media (max-width:1100px){.ss-two-col,.ss-theme-layout{grid-template-columns:1fr}.ss-stats{grid-template-columns:1fr 1fr}.ss-checks{grid-template-columns:1fr}.ss-preview{position:static}}
@media (max-width:700px){.ss-checklist-row{grid-template-columns:1fr}.ss-checklist-meta{grid-template-columns:1fr}.ss-uat-add{grid-template-columns:1fr}.ss-stats{grid-template-columns:1fr}.form-grid-2,.form-grid-3{grid-template-columns:1fr}}
</style>
<script>
(function () {
  // Tabs: switch in place and keep the URL in sync so reloads land on the same tab.
  document.querySelectorAll('[data-settings-tab]').forEach(function (tab) {
    tab.addEventListener('click', function (e) {
      e.preventDefault();
      var key = tab.dataset.settingsTab;
      document.querySelectorAll('[data-settings-tab]').forEach(function (t) {
        var on = t === tab;
        t.classList.toggle('active', on);
        t.setAttribute('aria-selected', String(on));
      });
      document.querySelectorAll('.settings-tab-panel').forEach(function (p) {
        p.hidden = p.id !== 'panel-' + key;
        p.classList.toggle('active', !p.hidden);
      });
      history.replaceState(null, '', 'site-settings.php?tab=' + encodeURIComponent(key));
    });
  });

  document.querySelectorAll('[data-subtab]').forEach(function (tab) {
    tab.addEventListener('click', function () {
      var container = tab.closest('section');
      var target = tab.dataset.subtab;
      container.querySelectorAll('[data-subtab]').forEach(function (t) {
        var on = t === tab;
        t.classList.toggle('active', on);
        t.setAttribute('aria-selected', String(on));
      });
      container.querySelectorAll('.ss-subtab-panel').forEach(function (panel) {
        panel.hidden = panel.id !== target;
      });
    });
  });

  // Theme colours: picker <-> text sync and live preview.
  var preview = document.getElementById('themePreview');
  var varMap = {theme_primary: '--pv-primary', theme_primary_dark: '--pv-primary-dark', theme_accent: '--pv-accent', theme_secondary: '--pv-secondary', theme_text: '--pv-text', theme_sidebar_bg: '--pv-sidebar'};
  function setColor(key, value) {
    var text = document.getElementById(key);
    var picker = document.getElementById(key + '_picker');
    if (!text) return;
    text.value = value;
    if (/^#[0-9a-fA-F]{6}$/.test(value)) {
      if (picker) picker.value = value;
      if (preview) preview.style.setProperty(varMap[key], value);
    }
  }
  Object.keys(varMap).forEach(function (key) {
    var text = document.getElementById(key);
    var picker = document.getElementById(key + '_picker');
    if (!text) return;
    setColor(key, text.value);
    text.addEventListener('input', function () { setColor(key, text.value.trim()); });
    if (picker) picker.addEventListener('input', function () { setColor(key, picker.value); });
  });
  document.querySelectorAll('[data-preset]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var colors = JSON.parse(btn.dataset.preset);
      Object.keys(varMap).forEach(function (key, i) { if (colors[i]) setColor(key, colors[i]); });
    });
  });

  // Typography preview: load the chosen Google Font on demand.
  var fontPreview = document.getElementById('fontPreview');
  var loaded = {};
  function loadFont(name) {
    if (name === 'System UI' || loaded[name]) return;
    loaded[name] = true;
    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'https://fonts.googleapis.com/css2?family=' + encodeURIComponent(name).replace(/%20/g, '+') + ':wght@400;500;600;700;800&display=swap';
    document.head.appendChild(link);
  }
  function stack(name) { return name === 'System UI' ? 'system-ui, sans-serif' : '"' + name + '", system-ui, sans-serif'; }
  function updateFonts() {
    if (!fontPreview) return;
    var body = document.getElementById('fontBody').value;
    var heading = document.getElementById('fontHeading').value;
    var size = document.getElementById('fontSize').value;
    loadFont(body); loadFont(heading);
    fontPreview.querySelector('p').style.fontFamily = stack(body);
    fontPreview.querySelector('p').style.fontSize = size + 'px';
    fontPreview.querySelector('h2').style.fontFamily = stack(heading);
    fontPreview.querySelector('h2').style.fontWeight = document.getElementById('fontWeight').value;
    document.getElementById('fontSizeLabel').textContent = size;
  }
  ['fontBody', 'fontHeading', 'fontSize', 'fontWeight'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', updateFonts);
  });
  updateFonts();

  // Remote backup credentials toggle.
  var useAws = document.getElementById('rbUseAws');
  if (useAws) useAws.addEventListener('change', function () { document.getElementById('rbCreds').hidden = useAws.checked; });

  // UAT result modal.
  var modal = document.getElementById('uatModal');
  function closeModal() { modal.hidden = true; modal.setAttribute('aria-hidden', 'true'); }
  document.querySelectorAll('[data-uat]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var d = JSON.parse(btn.dataset.uat);
      document.getElementById('uatId').value = d.id;
      document.getElementById('uatTitle').textContent = d.title;
      document.getElementById('uatStatus').value = d.status;
      document.getElementById('uatTester').value = d.tester || '';
      document.getElementById('uatDate').value = d.tested_at || '';
      document.getElementById('uatNotes').value = d.notes || '';
      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      document.getElementById('uatStatus').focus();
    });
  });
  modal.querySelectorAll('[data-close-modal]').forEach(function (b) { b.addEventListener('click', closeModal); });
  modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) closeModal(); });
})();

// Legal pages: copy link, starter text, sandboxed preview.
(function () {
  document.querySelectorAll('[data-copy-target]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.getAttribute('data-copy-target'));
      if (!input) return;
      input.select();
      var done = function () { btn.innerHTML = '<i class="fa fa-check"></i> Copied'; setTimeout(function () { btn.innerHTML = '<i class="fa fa-copy"></i> Copy'; }, 1800); };
      if (navigator.clipboard) { navigator.clipboard.writeText(input.value).then(done, function () { document.execCommand('copy'); done(); }); }
      else { document.execCommand('copy'); done(); }
    });
  });

  document.querySelectorAll('[data-legal-starter]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var key = btn.getAttribute('data-legal-starter');
      var box = document.getElementById('legalHtml' + key);
      var tpl = document.getElementById('legalStarter' + key);
      if (!box || !tpl) return;
      if (box.value.trim() !== '' && !confirm('Replace the current text with the starter text?')) return;
      box.value = tpl.innerHTML.trim();
      box.focus();
    });
  });

  var previewCss = '<style>body{font-family:Arial,sans-serif;color:#374151;line-height:1.75;padding:18px;margin:0}'
    + 'h2,h3,h4{color:#111;margin:1.4em 0 .4em}ul,ol{padding-left:1.4em}a{color:#c85f00}'
    + 'blockquote{margin:0 0 1em;padding:10px 16px;border-left:4px solid #fc7f10;background:#fff7ed}'
    + 'table{border-collapse:collapse}th,td{border:1px solid #e5e7eb;padding:6px 10px}</style>';

  document.querySelectorAll('[data-legal-preview]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var key = btn.getAttribute('data-legal-preview');
      var box = document.getElementById('legalHtml' + key);
      var frame = document.getElementById('legalPreview' + key);
      if (!box || !frame) return;
      if (!frame.hidden && btn.dataset.open === '1') {
        frame.hidden = true; btn.dataset.open = '0';
        btn.innerHTML = '<i class="fa fa-eye"></i> Preview';
        return;
      }
      // sandbox (no allow-scripts): nothing in the preview can run.
      frame.srcdoc = '<!doctype html><meta charset="utf-8">' + previewCss + box.value;
      frame.hidden = false; btn.dataset.open = '1';
      btn.innerHTML = '<i class="fa fa-eye-slash"></i> Hide preview';
    });
  });
})();
</script>

</body>
</html>
