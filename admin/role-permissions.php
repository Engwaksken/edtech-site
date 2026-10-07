<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) die('Database connection not found.');
$conn->set_charset('utf8mb4');

if (!admin_can_manage_permissions($conn)) {
    http_response_code(403);
    exit('Access denied.');
}

$site_name = function_exists('get_setting')
    ? get_setting($conn, 'site_name', 'EdTech Fellowship')
    : 'EdTech Fellowship';

function rp_is_super(string $role): bool {
    return in_array(strtolower(trim(str_replace('_','-',$role))), ['super-admin','superadmin','sup-admin'], true);
}
function rp_checked(array $permissions, string $role_key, string $page_key): string {
    return !empty($permissions[$role_key][$page_key]) ? 'checked' : '';
}

// -- Roles
$roles = [];
$res = $conn->query("SELECT role_key, role_name, can_manage_permissions, status FROM roles WHERE status='active' ORDER BY CASE WHEN role_key='super_admin' THEN 1 WHEN role_key='admin' THEN 2 ELSE 3 END, role_name ASC");
while ($res && $row = $res->fetch_assoc()) $roles[] = $row;

// -- Pages
$pages = [];
$res = $conn->query("SELECT page_key, page_title, page_url, group_name, icon, sort_order, section_order, is_active, created_at FROM admin_pages ORDER BY section_order ASC, group_name ASC, sort_order ASC, page_title ASC");
while ($res && $row = $res->fetch_assoc()) $pages[] = $row;

// -- Permissions map
$permissions = [];
$res = $conn->query("SELECT role_key, page_key, can_access FROM role_permissions");
while ($res && $row = $res->fetch_assoc()) $permissions[$row['role_key']][$row['page_key']] = (int)$row['can_access'];

// -- Group pages
$groups = [];
foreach ($pages as $page) {
    $g = trim($page['group_name'] ?? '') ?: 'General';
    $groups[$g][] = $page;
}

// -- Per-role permission count for badges
$role_perm_counts = [];
foreach ($roles as $role) {
    if (rp_is_super($role['role_key'])) {
        $role_perm_counts[$role['role_key']] = count($pages);
    } else {
        $cnt = 0;
        foreach ($pages as $page) {
            if (!empty($permissions[$role['role_key']][$page['page_key']])) $cnt++;
        }
        $role_perm_counts[$role['role_key']] = $cnt;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Role Permissions - <?= h($site_name) ?> Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="admin-main" id="adminMain">

  <header class="admin-topbar">
    <div class="topbar-left">
      <div class="topbar-breadcrumb">
        <a href="index.php">Dashboard</a> > <strong>Role Permissions</strong>
      </div>
    </div>
    <div class="topbar-right">
      <button class="btn btn-primary" onclick="openAddRoleModal()">
        <i class="fa fa-plus"></i> Add Role
      </button>
      <div class="admin-avatar">
        <div class="avatar-circle"><?= strtoupper(substr($ADMIN['full_name'] ?? 'A', 0, 1)) ?></div>
      </div>
    </div>
  </header>

  <div class="admin-content">
    <?php if (function_exists('show_flash')) show_flash('permissions'); ?>

    <div class="page-header">
      <div>
        <h1 class="page-title">Role Permissions</h1>
        <p class="page-subtitle">Control admin access per role &amp; manage sidebar navigation</p>
      </div>
      <div class="page-actions">
        <button class="btn btn-secondary" onclick="openAddPageModal()">
          <i class="fa fa-plus-circle"></i> Add Page
        </button>
      </div>
    </div>


    <div class="rp-shell">

  
      <div class="rp-role-sidebar">
        <div class="rp-sidebar-heading">Roles</div>

        <?php foreach ($roles as $i => $role):
          $is_super = rp_is_super($role['role_key']);
          $pct = count($pages) > 0 ? round($role_perm_counts[$role['role_key']] / count($pages) * 100) : 0;
        ?>
        <button class="rp-role-btn <?= $i === 0 ? 'active' : '' ?> <?= $is_super ? 'super-locked' : '' ?>"
                data-role="<?= h($role['role_key']) ?>"
                onclick="switchRole(this)">
          <div class="rp-role-icon">
            <i class="fa <?= $is_super ? 'fa-crown' : 'fa-user-shield' ?>"></i>
          </div>
          <div class="rp-role-copy">
            <div><?= h($role['role_name']) ?></div>
            <div class="rp-role-meta">
              <?= $is_super ? 'Full Access' : ($role_perm_counts[$role['role_key']] . ' / ' . count($pages) . ' pages') ?>
            </div>
          </div>
          <span class="rp-role-count"><?= $pct ?>%</span>
        </button>
        <?php endforeach; ?>

        <button class="rp-add-role-btn" onclick="openAddRoleModal()">
          <i class="fa fa-plus"></i> Add Role
        </button>

        <!-- Separator -->
        <div class="rp-sidebar-summary">
          <div class="rp-sidebar-heading">Summary</div>
          <div class="rp-sidebar-summary-body">
            <div><?= count($roles) ?> roles configured</div>
            <div><?= count($pages) ?> admin pages</div>
            <div><?= count($groups) ?> navigation groups</div>
          </div>
        </div>
      </div>

      <div class="rp-main">

        <!-- Tab nav -->
        <div class="rp-tab-header">
          <button class="rp-main-tab active" data-tab="permissions" onclick="switchMainTab(this)">
            <i class="fa fa-shield-alt"></i>
            Permissions
            <span class="tab-badge" id="perm_tab_count"><?= count($pages) ?> pages</span>
          </button>
          <button class="rp-main-tab" data-tab="sidebar" onclick="switchMainTab(this)">
            <i class="fa fa-sort"></i>
            Sidebar Order
            <span class="tab-badge"><?= count($groups) ?> groups</span>
          </button>
          <button class="rp-main-tab" data-tab="roles" onclick="switchMainTab(this)">
            <i class="fa fa-users-cog"></i>
            Role Settings
            <span class="tab-badge"><?= count($roles) ?> roles</span>
          </button>
        </div>

        <!-- -------- TAB: PERMISSIONS -------- -->
        <div class="rp-tab-panel active" id="tab_permissions">

          <!-- Save bar (appears when changes made) -->
          <div class="perm-save-bar" id="permSaveBar">
            <div class="perm-save-bar-text">
              <strong>Unsaved changes</strong> - permissions have been modified
            </div>
            <div class="perm-save-actions">
              <button type="button" class="btn btn-secondary btn-sm" onclick="revertPermissions()">
                <i class="fa fa-undo"></i> Revert
              </button>
              <button type="button" class="btn btn-primary btn-sm" onclick="savePermissions()">
                <i class="fa fa-save"></i> Save Permissions
              </button>
            </div>
          </div>

          <?php foreach ($roles as $i => $role):
            $role_key = $role['role_key'];
            $is_super = rp_is_super($role_key);
          ?>
          <div class="role-perm-panel <?= $i === 0 ? 'active' : 'is-hidden' ?>"
               id="rpp_<?= h($role_key) ?>">

            <?php if ($is_super): ?>
            <div class="super-locked-banner">
              <i class="fa fa-crown"></i>
              <div>
                <strong><?= h($role['role_name']) ?></strong> has unrestricted access to all admin pages.
                Permissions cannot be modified for super-admin roles.
              </div>
            </div>
            <?php endif; ?>

            <!-- Toolbar -->
            <div class="perm-toolbar">
              <div class="perm-search-wrap">
                <i class="fa fa-search"></i>
                <input type="text" class="perm-search"
                       id="search_<?= h($role_key) ?>"
                       placeholder="Search pages..."
                       oninput="filterPages('<?= h($role_key) ?>', this.value)">
              </div>
              <?php if (!$is_super): ?>
              <div class="perm-toggle-btns">
                <button type="button" class="perm-toggle-btn on"
                        onclick="toggleAll('<?= h($role_key) ?>', true)">
                  <i class="fa fa-check-double"></i> Enable All
                </button>
                <button type="button" class="perm-toggle-btn off"
                        onclick="toggleAll('<?= h($role_key) ?>', false)">
                  <i class="fa fa-ban"></i> Disable All
                </button>
              </div>
              <?php endif; ?>
            </div>

            <!-- Groups -->
            <?php foreach ($groups as $group_name => $group_pages): ?>
            <div class="perm-group" id="grp_<?= h($role_key) ?>_<?= h(preg_replace('/[^a-z0-9]/i','_',$group_name)) ?>">
              <div class="perm-group-header" onclick="toggleGroup(this)">
                <i class="fa fa-folder rp-icon-sm"></i>
                <?= h($group_name) ?>
                <span class="group-count"><?= count($group_pages) ?></span>
                <?php if (!$is_super): ?>
                <button type="button" class="group-toggle-all"
                        onclick="event.stopPropagation();toggleGroupPerms('<?= h($role_key) ?>','<?= h(addslashes($group_name)) ?>',true)">
                  <i class="fa fa-check"></i> All
                </button>
                <button type="button" class="group-toggle-all"
                        onclick="event.stopPropagation();toggleGroupPerms('<?= h($role_key) ?>','<?= h(addslashes($group_name)) ?>',false)">
                  <i class="fa fa-times"></i> None
                </button>
                <?php endif; ?>
                <i class="fa fa-chevron-down perm-group-chevron"></i>
              </div>
              <div class="perm-group-body">
                <?php foreach ($group_pages as $page):
                  $checked = $is_super ? true : !empty($permissions[$role_key][$page['page_key']]);
                  $is_active = (int)$page['is_active'] === 1;
                ?>
                <div class="perm-page-row"
                     data-group="<?= h($group_name) ?>"
                     data-title="<?= h(strtolower($page['page_title'])) ?>"
                     data-url="<?= h(strtolower($page['page_url'])) ?>"
                     data-key="<?= h($page['page_key']) ?>">

                  <div class="perm-page-icon">
                    <i class="fa <?= h($page['icon'] ?: 'fa-circle') ?>"></i>
                  </div>

                  <div class="perm-page-info">
                    <div class="perm-page-name">
                      <span class="perm-status-dot <?= $is_active ? 'active' : 'inactive' ?>"
                            title="<?= $is_active ? 'Active' : 'Inactive' ?>"></span>
                      <?= h($page['page_title']) ?>
                    </div>
                    <div class="perm-page-url">
                      <?= h($page['page_url']) ?>
                      <span class="perm-url-sep">.</span>
                      <code><?= h($page['page_key']) ?></code>
                    </div>
                  </div>

                  <div class="perm-switch-wrap">
                    <label class="perm-switch">
                      <input type="checkbox"
                             class="perm-cb"
                             data-role="<?= h($role_key) ?>"
                             data-page="<?= h($page['page_key']) ?>"
                             <?= $checked ? 'checked' : '' ?>
                             <?= $is_super ? 'disabled' : '' ?>
                             onchange="markDirty()">
                      <span class="perm-switch-track"></span>
                      <span class="perm-switch-knob"></span>
                    </label>
                    <span class="perm-switch-label"><?= $checked ? 'On' : 'Off' ?></span>
                  </div>

                  <button type="button" class="perm-edit-btn" title="Edit page"
                          onclick='openEditPageModal(<?= json_encode($page, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'>
                    <i class="fa fa-edit"></i>
                  </button>

                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endforeach; ?>

          </div><!-- /role-perm-panel -->
          <?php endforeach; ?>

        </div><!-- /tab_permissions -->

        <!-- -------- TAB: SIDEBAR ORDER -------- -->
        <div class="rp-tab-panel" id="tab_sidebar">
          <form method="POST" action="includes/process-role-permissions.php" id="sidebarOrderForm">
            <input type="hidden" name="action" value="save_sidebar_order">
            <input type="hidden" name="sidebar_order" id="sidebar_order_input">

            <div class="sidebar-order-toolbar">
              <div>
                <div class="sidebar-order-title">Drag sections and pages to reorder the admin sidebar</div>
                <div class="sidebar-order-subtitle">Changes take effect immediately after saving</div>
              </div>
              <button type="submit" class="btn btn-primary" onclick="prepareSidebarOrder()">
                <i class="fa fa-save"></i> Save Order
              </button>
            </div>

            <div id="sectionSortable">
              <?php foreach ($groups as $group => $items): ?>
              <div class="sort-section" data-group="<?= h($group) ?>">
                <div class="sort-section-header">
                  <i class="fa fa-grip-vertical drag-icon"></i>
                  <i class="fa fa-folder sort-muted-icon"></i>
                  <strong><?= h($group) ?></strong>
                  <span class="sort-page-count"><?= count($items) ?> pages</span>
                </div>
                <div class="sort-pages-list">
                  <?php foreach ($items as $page): ?>
                  <div class="sort-page-item" data-page="<?= h($page['page_key']) ?>">
                    <i class="fa fa-grip-lines sort-drag-handle"></i>
                    <i class="fa <?= h($page['icon'] ?: 'fa-circle') ?> sort-muted-icon"></i>
                    <span><?= h($page['page_title']) ?></span>
                    <span class="sort-page-url"><?= h($page['page_url']) ?></span>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </form>
        </div><!-- /tab_sidebar -->

        <!-- -------- TAB: ROLE SETTINGS -------- -->
        <div class="rp-tab-panel" id="tab_roles">
          <div class="role-settings-list">
            <?php foreach ($roles as $role):
              $is_super = rp_is_super($role['role_key']);
              $pct = count($pages) > 0 ? round($role_perm_counts[$role['role_key']] / count($pages) * 100) : 0;
            ?>
            <div class="role-settings-card">
              <div class="role-settings-row">
                <div class="role-settings-icon <?= $is_super ? 'super' : 'normal' ?>">
                  <i class="fa <?= $is_super ? 'fa-crown' : 'fa-user-shield' ?>"></i>
                </div>
                <div class="role-settings-copy">
                  <div class="role-settings-name"><?= h($role['role_name']) ?></div>
                  <div class="role-settings-meta">
                    <span><code><?= h($role['role_key']) ?></code></span>
                    <span><?= $role_perm_counts[$role['role_key']] ?> / <?= count($pages) ?> pages (<?= $pct ?>%)</span>
                    <?php if ($role['can_manage_permissions']): ?>
                      <span class="role-manage-badge"><i class="fa fa-key"></i> Can manage permissions</span>
                    <?php endif; ?>
                  </div>
                </div>
                <!-- Access bar -->
                <div class="role-access">
                  <div class="role-access-track">
                    <div class="role-access-fill <?= $is_super ? 'super' : 'normal' ?>" data-percent="<?= (int)$pct ?>"></div>
                  </div>
                  <div class="role-access-label"><?= $pct ?>% access</div>
                </div>
                <?php if (!$is_super): ?>
                <button class="btn btn-secondary btn-sm"
                        onclick='openEditRoleModal(<?= json_encode($role, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'>
                  <i class="fa fa-edit"></i> Edit
                </button>
                <?php else: ?>
                <span class="role-protected-badge">
                  <i class="fa fa-lock"></i> Protected
                </span>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div><!-- /tab_roles -->

      </div><!-- /rp-main -->
    </div><!-- /rp-shell -->

  </div><!-- /admin-content -->
</div><!-- /admin-main -->


<!-- ------------------------------------------------------
     MODAL: ADD / EDIT PAGE
------------------------------------------------------ -->
<div class="rp-modal-overlay" id="pageModal">
<div class="rp-modal wide">
  <div class="rp-modal-hdr">
    <div class="rp-modal-icon blue" id="pageModalIcon"><i class="fa fa-plus-circle"></i></div>
    <div class="rp-modal-title" id="pageModalTitle">Add Admin Page</div>
    <button class="rp-modal-close" onclick="closePageModal()">x</button>
  </div>
  <form id="pageModalForm">
    <div class="rp-modal-body">
      <input type="hidden" id="pm_old_key">

      <div class="rp-field-grid">
        <div class="rp-field">
          <label>Page Title <span class="req">*</span></label>
          <input type="text" id="pm_title" placeholder="e.g. Users" required>
        </div>
        <div class="rp-field">
          <label>Page Key <span class="req">*</span></label>
          <input type="text" id="pm_key" placeholder="e.g. users">
          <div class="rp-field-hint">Auto-generated from title if blank</div>
        </div>
      </div>

      <div class="rp-field-grid">
        <div class="rp-field">
          <label>Page URL <span class="req">*</span></label>
          <input type="text" id="pm_url" placeholder="e.g. users.php">
        </div>
        <div class="rp-field">
          <label>Group / Section</label>
          <input type="text" id="pm_group" placeholder="e.g. Settings &amp; Account"
                 list="groupSuggestions">
          <datalist id="groupSuggestions">
            <?php foreach (array_keys($groups) as $g): ?>
              <option value="<?= h($g) ?>">
            <?php endforeach; ?>
          </datalist>
        </div>
      </div>

      <div class="rp-field-grid">
        <div class="rp-field">
          <label>Font Awesome Icon</label>
          <input type="text" id="pm_icon" placeholder="e.g. fa-users-cog">
          <div class="rp-field-hint">Without the <code>fa</code> prefix: <code>fa-circle</code></div>
        </div>
        <div class="rp-field">
          <label>Sort Order</label>
          <input type="number" id="pm_sort" value="100" min="0">
        </div>
      </div>

      <div class="rp-field">
        <label>Active in Sidebar</label>
        <div class="rp-toggle-row">
          <div>
            <div class="rp-toggle-row-label">Show in admin sidebar navigation</div>
            <div class="rp-toggle-row-sub">Inactive pages are hidden from the sidebar but still accessible via direct URL</div>
          </div>
          <label class="perm-switch perm-switch-fixed">
            <input type="checkbox" id="pm_active" checked>
            <span class="perm-switch-track"></span>
            <span class="perm-switch-knob"></span>
          </label>
        </div>
      </div>

      <div class="rp-field">
        <label>Grant Access To Roles</label>
        <div class="rp-roles-checklist" id="pm_roles_list">
          <?php foreach ($roles as $role): ?>
          <label class="rp-roles-check-item">
            <input type="checkbox" class="pm-role-cb"
                   value="<?= h($role['role_key']) ?>"
                   <?= rp_is_super($role['role_key']) ? 'checked disabled' : '' ?>>
            <span><?= h($role['role_name']) ?></span>
            <?php if (rp_is_super($role['role_key'])): ?>
              <span class="role-always-badge"><i class="fa fa-crown"></i> Always</span>
            <?php endif; ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Danger zone (edit mode only) -->
      <div class="rp-danger-zone is-hidden" id="pm_danger_zone">
        <div class="rp-danger-zone-title"><i class="fa fa-exclamation-triangle"></i> Danger Zone</div>
        <div class="rp-danger-text">
          Deleting this page will remove it from the sidebar and delete all associated permissions. This cannot be undone.
        </div>
        <button type="button" class="btn btn-danger btn-sm" onclick="deletePage()" id="pm_delete_btn">
          <i class="fa fa-trash"></i> Delete This Page
        </button>
      </div>

    </div>
    <div class="rp-modal-footer">
      <div id="pm_ajax_status" class="rp-ajax-spinner rp-ajax-left is-hidden">
        <i class="fa fa-circle-notch rp-spinner"></i> Saving...
      </div>
      <button type="button" class="btn btn-secondary" onclick="closePageModal()">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="submitPageModal()" id="pm_submit_btn">
        <i class="fa fa-save"></i> <span id="pm_submit_label">Save Page</span>
      </button>
    </div>
  </form>
</div>
</div>


<!-- ------------------------------------------------------
     MODAL: ADD / EDIT ROLE
------------------------------------------------------ -->
<div class="rp-modal-overlay" id="roleModal">
<div class="rp-modal">
  <div class="rp-modal-hdr">
    <div class="rp-modal-icon green" id="roleModalIcon"><i class="fa fa-user-shield"></i></div>
    <div class="rp-modal-title" id="roleModalTitle">Add Role</div>
    <button class="rp-modal-close" onclick="closeRoleModal()">x</button>
  </div>
  <div class="rp-modal-body">
    <input type="hidden" id="rm_old_key">

    <div class="rp-field">
      <label>Role Name <span class="req">*</span></label>
      <input type="text" id="rm_name" placeholder="e.g. Content Editor">
    </div>
    <div class="rp-field">
      <label>Role Key <span class="req">*</span></label>
      <input type="text" id="rm_key" placeholder="e.g. content_editor">
      <div class="rp-field-hint">Lowercase, underscores only. Auto-generated from name if blank.</div>
    </div>
    <div class="rp-field">
      <label>Can Manage Permissions</label>
      <div class="rp-toggle-row">
        <div>
          <div class="rp-toggle-row-label">Allow this role to access the Role Permissions page</div>
          <div class="rp-toggle-row-sub">Only grant this to highly trusted admin users</div>
        </div>
        <label class="perm-switch perm-switch-fixed">
          <input type="checkbox" id="rm_can_manage">
          <span class="perm-switch-track"></span>
          <span class="perm-switch-knob"></span>
        </label>
      </div>
    </div>

    <!-- Delete zone (edit mode) -->
    <div class="rp-danger-zone is-hidden" id="rm_danger_zone">
      <div class="rp-danger-zone-title"><i class="fa fa-exclamation-triangle"></i> Danger Zone</div>
      <div class="rp-danger-text">
        Deleting this role will remove all admin users from this role and delete all associated permissions.
      </div>
      <button type="button" class="btn btn-danger btn-sm" onclick="deleteRole()">
        <i class="fa fa-trash"></i> Delete This Role
      </button>
    </div>
  </div>
  <div class="rp-modal-footer">
    <div id="rm_ajax_status" class="rp-ajax-spinner rp-ajax-left is-hidden">
      <i class="fa fa-circle-notch rp-spinner"></i> Saving...
    </div>
    <button type="button" class="btn btn-secondary" onclick="closeRoleModal()">Cancel</button>
    <button type="button" class="btn btn-primary" onclick="submitRoleModal()">
      <i class="fa fa-save"></i> Save Role
    </button>
  </div>
</div>
</div>


<!-- Toast container -->
<div class="rp-toast-wrap" id="toastWrap"></div>


<!-- Sortable JS -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>

<script>
/* -----------------------------------------------------------
   BOOT
----------------------------------------------------------- */
document.getElementById('sidebarToggle')?.addEventListener('click', () => {
  document.getElementById('adminSidebar')?.classList.toggle('collapsed');
  document.getElementById('adminMain')?.classList.toggle('collapsed');
});

let _dirty = false;

// Apply progress bar percentages without inline CSS
function initRoleProgressBars() {
  document.querySelectorAll('.role-access-fill[data-percent]').forEach(el => {
    const pct = Math.max(0, Math.min(100, parseInt(el.dataset.percent || '0', 10)));
    el.style.width = pct + '%';
  });
}
initRoleProgressBars();


/* -----------------------------------------------------------
   ROLE SWITCHER
----------------------------------------------------------- */
function switchRole(btn) {
  document.querySelectorAll('.rp-role-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');

  const role = btn.dataset.role;
  document.querySelectorAll('.role-perm-panel').forEach(p => { p.classList.remove('active'); p.classList.add('is-hidden'); });
  const panel = document.getElementById('rpp_' + role);
  if (panel) { panel.classList.add('active'); panel.classList.remove('is-hidden'); }
}

/* -----------------------------------------------------------
   MAIN TAB SWITCHER
----------------------------------------------------------- */
function switchMainTab(btn) {
  document.querySelectorAll('.rp-main-tab').forEach(t => t.classList.remove('active'));
  btn.classList.add('active');
  const tab = btn.dataset.tab;
  document.querySelectorAll('.rp-tab-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('tab_' + tab).classList.add('active');
}

/* -----------------------------------------------------------
   PERMISSION TOGGLES & SEARCH
----------------------------------------------------------- */
function markDirty() {
  _dirty = true;
  document.getElementById('permSaveBar').classList.add('visible');
  // Update switch label
  document.querySelectorAll('.perm-switch-wrap').forEach(wrap => {
    const cb  = wrap.querySelector('.perm-cb');
    const lbl = wrap.querySelector('.perm-switch-label');
    if (cb && lbl) lbl.textContent = cb.checked ? 'On' : 'Off';
  });
}

function filterPages(roleKey, q) {
  q = q.toLowerCase().trim();
  const panel = document.getElementById('rpp_' + roleKey);
  if (!panel) return;
  panel.querySelectorAll('.perm-page-row').forEach(row => {
    const match = !q ||
      row.dataset.title.includes(q) ||
      row.dataset.url.includes(q) ||
      row.dataset.key.includes(q);
    row.classList.toggle('perm-hidden', !match);
  });
}

function toggleAll(roleKey, state) {
  const panel = document.getElementById('rpp_' + roleKey);
  if (!panel) return;
  panel.querySelectorAll('.perm-cb:not(:disabled)').forEach(cb => { cb.checked = state; });
  markDirty();
}

function toggleGroup(headerEl) {
  const body    = headerEl.nextElementSibling;
  const chevron = headerEl.querySelector('.fa-chevron-down, .fa-chevron-up');
  const isCollapsed = body.classList.contains('collapsed');
  body.classList.toggle('collapsed', !isCollapsed);
  if (chevron) chevron.classList.toggle('rotated', !isCollapsed);
}

function toggleGroupPerms(roleKey, groupName, state) {
  const panel = document.getElementById('rpp_' + roleKey);
  if (!panel) return;
  panel.querySelectorAll('.perm-page-row').forEach(row => {
    if (row.dataset.group === groupName) {
      const cb = row.querySelector('.perm-cb:not(:disabled)');
      if (cb) cb.checked = state;
    }
  });
  markDirty();
}


async function savePermissions() {
  const bar = document.getElementById('permSaveBar');
  bar.querySelector('button:last-child').innerHTML = '<i class="fa fa-circle-notch fa-spin"></i> Saving...';

  const payload = {};
  document.querySelectorAll('.perm-cb:not(:disabled)').forEach(cb => {
    const role = cb.dataset.role;
    const page = cb.dataset.page;
    if (!payload[role]) payload[role] = {};
    payload[role][page] = cb.checked ? 1 : 0;
  });

  try {
    const fd = new FormData();
    fd.append('action', 'save_permissions_ajax');
    fd.append('permissions', JSON.stringify(payload));
    const res = await fetch('includes/process-role-permissions.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      toast('Permissions saved successfully', 'success');
      _dirty = false;
      bar.classList.remove('visible');
    } else {
      toast(data.message || 'Save failed', 'error');
    }
  } catch (e) {
    toast('Network error - could not save', 'error');
  }
  bar.querySelector('button:last-child').innerHTML = '<i class="fa fa-save"></i> Save Permissions';
}

function revertPermissions() {
  location.reload();
}


if (document.getElementById('sectionSortable')) {
  new Sortable(document.getElementById('sectionSortable'), {
    animation: 150,
    handle: '.sort-section-header',
    ghostClass: 'sortable-ghost',
    chosenClass: 'sortable-chosen',
  });
  document.querySelectorAll('.sort-pages-list').forEach(list => {
    new Sortable(list, {
      group: 'sidebar-pages',
      animation: 150,
      ghostClass: 'sortable-ghost',
      chosenClass: 'sortable-chosen',
    });
  });
}

function prepareSidebarOrder() {
  const data = [];
  document.querySelectorAll('#sectionSortable .sort-section').forEach(section => {
    const pages = [];
    section.querySelectorAll('.sort-page-item').forEach(page => pages.push(page.dataset.page));
    data.push({ group_name: section.dataset.group, pages });
  });
  document.getElementById('sidebar_order_input').value = JSON.stringify(data);
}

document.getElementById('sidebarOrderForm')?.addEventListener('submit', function(e) {
  e.preventDefault();
  prepareSidebarOrder();
  saveSidebarOrderAjax();
});

async function saveSidebarOrderAjax() {
  prepareSidebarOrder();
  const fd = new FormData(document.getElementById('sidebarOrderForm'));
  try {
    const res  = await fetch('includes/process-role-permissions.php', { method: 'POST', body: fd });
    const data = await res.json();
    toast(data.success ? 'Sidebar order saved!' : (data.message || 'Save failed'), data.success ? 'success' : 'error');
  } catch(e) {
    toast('Network error', 'error');
  }
}


const pageModal  = document.getElementById('pageModal');
let _editPageKey = null;

function openAddPageModal() {
  _editPageKey = null;
  document.getElementById('pageModalTitle').textContent = 'Add Admin Page';
  document.getElementById('pageModalIcon').innerHTML    = '<i class="fa fa-plus-circle"></i>';
  document.getElementById('pm_submit_label').textContent = 'Save Page';
  document.getElementById('pm_old_key').value  = '';
  document.getElementById('pm_title').value    = '';
  document.getElementById('pm_key').value      = '';
  document.getElementById('pm_url').value      = '';
  document.getElementById('pm_group').value    = '';
  document.getElementById('pm_icon').value     = '';
  document.getElementById('pm_sort').value     = '100';
  document.getElementById('pm_active').checked = true;
  document.getElementById('pm_danger_zone').classList.add('is-hidden');
  document.querySelectorAll('.pm-role-cb:not(:disabled)').forEach(cb => cb.checked = false);
  pageModal.classList.add('open');
}

function openEditPageModal(page) {
  _editPageKey = page.page_key;
  document.getElementById('pageModalTitle').textContent  = 'Edit Page - ' + page.page_title;
  document.getElementById('pageModalIcon').innerHTML     = '<i class="fa fa-edit"></i>';
  document.getElementById('pm_submit_label').textContent = 'Update Page';
  document.getElementById('pm_old_key').value  = page.page_key;
  document.getElementById('pm_title').value    = page.page_title    || '';
  document.getElementById('pm_key').value      = page.page_key      || '';
  document.getElementById('pm_url').value      = page.page_url      || '';
  document.getElementById('pm_group').value    = page.group_name    || '';
  document.getElementById('pm_icon').value     = page.icon          || '';
  document.getElementById('pm_sort').value     = page.sort_order    || 100;
  document.getElementById('pm_active').checked = String(page.is_active) === '1';
  document.getElementById('pm_danger_zone').classList.remove('is-hidden');
  pageModal.classList.add('open');
}

function closePageModal() { pageModal.classList.remove('open'); }
pageModal.addEventListener('click', e => { if (e.target === pageModal) closePageModal(); });

// Auto-generate key from title
document.getElementById('pm_title').addEventListener('input', function() {
  const keyField = document.getElementById('pm_key');
  if (!_editPageKey) {
    keyField.value = this.value.toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,'');
  }
});

async function submitPageModal() {
  const title = document.getElementById('pm_title').value.trim();
  const url   = document.getElementById('pm_url').value.trim();
  if (!title || !url) { toast('Title and URL are required', 'error'); return; }

  const btn    = document.getElementById('pm_submit_btn');
  const status = document.getElementById('pm_ajax_status');
  btn.disabled = true; status.classList.remove('is-hidden');

  const roles = [];
  document.querySelectorAll('.pm-role-cb:checked').forEach(cb => roles.push(cb.value));

  const fd = new FormData();
  fd.append('action',     _editPageKey ? 'update_page' : 'add_page');
  fd.append('old_page_key', document.getElementById('pm_old_key').value);
  fd.append('page_title', title);
  fd.append('page_key',   document.getElementById('pm_key').value.trim() || title.toLowerCase().replace(/[^a-z0-9]+/g,'_'));
  fd.append('page_url',   url);
  fd.append('group_name', document.getElementById('pm_group').value.trim());
  fd.append('icon',       document.getElementById('pm_icon').value.trim());
  fd.append('sort_order', document.getElementById('pm_sort').value);
  fd.append('is_active',  document.getElementById('pm_active').checked ? '1' : '0');
  roles.forEach(r => fd.append('roles[]', r));

  try {
    const res  = await fetch('includes/process-role-permissions.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      toast(_editPageKey ? 'Page updated successfully' : 'Page added successfully', 'success');
      closePageModal();
      setTimeout(() => location.reload(), 800);
    } else {
      toast(data.message || 'Save failed', 'error');
    }
  } catch(e) {
    toast('Network error', 'error');
  }
  btn.disabled = false; status.classList.add('is-hidden');
}

async function deletePage() {
  if (!_editPageKey || !confirm('Delete "' + _editPageKey + '" and all its permissions? This cannot be undone.')) return;
  const fd = new FormData();
  fd.append('action', 'delete_page');
  fd.append('page_key', _editPageKey);
  try {
    const res  = await fetch('includes/process-role-permissions.php', { method: 'POST', body: fd });
    const data = await res.json();
    toast(data.success ? 'Page deleted' : (data.message || 'Delete failed'), data.success ? 'success' : 'error');
    if (data.success) { closePageModal(); setTimeout(() => location.reload(), 800); }
  } catch(e) { toast('Network error', 'error'); }
}


const roleModal  = document.getElementById('roleModal');
let _editRoleKey = null;

function openAddRoleModal() {
  _editRoleKey = null;
  document.getElementById('roleModalTitle').textContent = 'Add Role';
  document.getElementById('rm_old_key').value  = '';
  document.getElementById('rm_name').value     = '';
  document.getElementById('rm_key').value      = '';
  document.getElementById('rm_can_manage').checked = false;
  document.getElementById('rm_danger_zone').classList.add('is-hidden');
  roleModal.classList.add('open');
}

function openEditRoleModal(role) {
  _editRoleKey = role.role_key;
  document.getElementById('roleModalTitle').textContent = 'Edit Role - ' + role.role_name;
  document.getElementById('rm_old_key').value  = role.role_key;
  document.getElementById('rm_name').value     = role.role_name || '';
  document.getElementById('rm_key').value      = role.role_key  || '';
  document.getElementById('rm_can_manage').checked = String(role.can_manage_permissions) === '1';
  document.getElementById('rm_danger_zone').classList.remove('is-hidden');
  roleModal.classList.add('open');
}

function closeRoleModal() { roleModal.classList.remove('open'); }
roleModal.addEventListener('click', e => { if (e.target === roleModal) closeRoleModal(); });

document.getElementById('rm_name').addEventListener('input', function() {
  if (!_editRoleKey) {
    document.getElementById('rm_key').value =
      this.value.toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,'');
  }
});

async function submitRoleModal() {
  const name = document.getElementById('rm_name').value.trim();
  const key  = document.getElementById('rm_key').value.trim() ||
               name.toLowerCase().replace(/[^a-z0-9]+/g,'_');
  if (!name || !key) { toast('Name and key are required', 'error'); return; }

  const status = document.getElementById('rm_ajax_status');
  status.classList.remove('is-hidden');

  const fd = new FormData();
  fd.append('action',               _editRoleKey ? 'update_role' : 'add_role');
  fd.append('old_role_key',         document.getElementById('rm_old_key').value);
  fd.append('role_name',            name);
  fd.append('role_key',             key);
  fd.append('can_manage_permissions', document.getElementById('rm_can_manage').checked ? '1' : '0');

  try {
    const res  = await fetch('includes/process-role-permissions.php', { method: 'POST', body: fd });
    const data = await res.json();
    toast(data.success ? (_editRoleKey ? 'Role updated' : 'Role created') : (data.message || 'Save failed'),
          data.success ? 'success' : 'error');
    if (data.success) { closeRoleModal(); setTimeout(() => location.reload(), 800); }
  } catch(e) { toast('Network error', 'error'); }
  status.classList.add('is-hidden');
}

async function deleteRole() {
  if (!_editRoleKey || !confirm('Delete role "' + _editRoleKey + '"? This cannot be undone.')) return;
  const fd = new FormData();
  fd.append('action', 'delete_role'); fd.append('role_key', _editRoleKey);
  try {
    const res  = await fetch('includes/process-role-permissions.php', { method: 'POST', body: fd });
    const data = await res.json();
    toast(data.success ? 'Role deleted' : (data.message || 'Delete failed'), data.success ? 'success' : 'error');
    if (data.success) { closeRoleModal(); setTimeout(() => location.reload(), 800); }
  } catch(e) { toast('Network error', 'error'); }
}


function toast(msg, type = 'info') {
  const wrap = document.getElementById('toastWrap');
  const el   = document.createElement('div');
  const icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', info: 'fa-info-circle' };
  el.className = `rp-toast ${type}`;
  el.innerHTML = `<i class="fa ${icons[type] || 'fa-info-circle'}"></i> ${msg}`;
  wrap.appendChild(el);
  setTimeout(() => { el.classList.add('fade'); setTimeout(() => el.remove(), 400); }, 3200);
}


window.addEventListener('beforeunload', e => {
  if (_dirty) {
    e.preventDefault();
    e.returnValue = '';
  }
});
</script>
</body>
</html>
