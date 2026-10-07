<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

$page_title  = 'Venture Profile';
$current_nav = 'profile.php';

$stages = [
    'idea'     => 'Idea',
    'mvp'      => 'MVP',
    'pre_seed' => 'Pre-Seed',
    'seed'     => 'Seed',
    'series_a' => 'Series A',
    'growth'   => 'Growth'
];

include 'layout.php';
?>

<form method="POST" action="process-profile.php" enctype="multipart/form-data" class="vp-profile-form">
  <input type="hidden" name="action" value="save_profile">

  <style>
    .password-field {
      position: relative;
      display: flex;
      align-items: center;
      width: 100%;
    }

    .password-field .form-control {
      width: 100%;
      padding-right: 48px;
    }

    .password-toggle {
      position: absolute;
      right: 14px;
      top: 50%;
      transform: translateY(-50%);
      border: 0;
      background: transparent;
      color: #07132f;
      cursor: pointer;
      padding: 0;
      font-size: 15px;
      line-height: 1;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .password-toggle:focus {
      outline: none;
      box-shadow: none;
    }
  </style>

  <div class="vp-profile-hero">
    <div class="vp-profile-hero-left">
      <label class="vp-profile-logo-box">
        <?php if (!empty($VENTURE['logo'])): ?>
          <img src="<?= SITE_URL . '/' . h($VENTURE['logo']) ?>" id="logoPreview" alt="Logo">
        <?php else: ?>
          <div class="vp-profile-logo-initials" id="logoInitials">
            <?= strtoupper(substr($VENTURE['name'] ?? 'V', 0, 2)) ?>
          </div>
          <img id="logoPreview" style="display:none" alt="">
        <?php endif; ?>

        <span class="vp-profile-logo-overlay">
          <i class="fa fa-camera"></i>
          Change Logo
        </span>

        <input type="file" name="logo" accept="image/*" hidden onchange="previewLogo(this)">
      </label>

      <div>
        <h2><?= h($VENTURE['name'] ?? 'Venture Profile') ?></h2>
        <p><?= h($VENTURE['tagline'] ?? 'Update your venture profile and public showcase details.') ?></p>

        <div class="vp-profile-badges">
          <span class="badge badge-gold">
            <?= h($stages[$VENTURE['stage'] ?? ''] ?? ($VENTURE['stage'] ?? 'Stage')) ?>
          </span>
          <span class="badge badge-muted">
            <?= h(ucfirst($VENTURE['status'] ?? 'draft')) ?>
          </span>
        </div>
      </div>
    </div>

    <div class="vp-profile-hero-actions">
      <a href="dashboard.php" class="btn btn-outline">Cancel</a>
      <button type="submit" class="btn btn-primary">
        <i class="fa fa-save"></i> Save Changes
      </button>
    </div>
  </div>

  <div class="vp-profile-tabs-wrap">
    <button type="button" class="vp-profile-tab active" data-tab="overview">
      <i class="fa fa-building"></i> Overview
    </button>
    <button type="button" class="vp-profile-tab" data-tab="founders">
      <i class="fa fa-users"></i> Founders
    </button>
    <button type="button" class="vp-profile-tab" data-tab="links">
      <i class="fa fa-link"></i> Links & Funding
    </button>
    <button type="button" class="vp-profile-tab" data-tab="media">
      <i class="fa fa-image"></i> Media
    </button>
    <button type="button" class="vp-profile-tab" data-tab="security">
      <i class="fa fa-lock"></i> Security
    </button>
  </div>

  <div class="vp-profile-tab-panel active" id="tab-overview">
    <div class="vp-profile-section-head">
      <h3>Basic Venture Information</h3>
      <p>These details describe your venture on the platform.</p>
    </div>

    <div class="form-grid-2">
      <div class="form-group">
        <label class="form-label">Venture Name <span class="req">*</span></label>
        <input type="text" name="name" class="form-control" required value="<?= h($VENTURE['name'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label class="form-label">Primary Email</label>
        <input type="email" class="form-control" readonly value="<?= h($VENTURE['email'] ?? '') ?>">
        <span class="form-hint">Primary email cannot be changed from this page.</span>
      </div>

      <div class="form-group full">
        <label class="form-label">Tagline</label>
        <input type="text" name="tagline" class="form-control" value="<?= h($VENTURE['tagline'] ?? '') ?>">
      </div>

      <div class="form-group full">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="6"><?= h($VENTURE['description'] ?? '') ?></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Founded Year</label>
        <input type="number" name="founded_year" class="form-control" min="1900" max="<?= date('Y') ?>" value="<?= h($VENTURE['founded_year'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label class="form-label">Stage</label>
        <select name="stage" class="form-control">
          <?php foreach ($stages as $key => $label): ?>
            <option value="<?= h($key) ?>" <?= (($VENTURE['stage'] ?? '') === $key) ? 'selected' : '' ?>>
              <?= h($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Sector</label>
        <input type="text" name="sector" class="form-control" value="<?= h($VENTURE['sector'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label class="form-label">Country</label>
        <input type="text" name="country" class="form-control" value="<?= h($VENTURE['country'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label class="form-label">Location</label>
        <input type="text" name="location" class="form-control" value="<?= h($VENTURE['location'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label class="form-label">Impact Metric</label>
        <input type="text" name="impact_metric" class="form-control" value="<?= h($VENTURE['impact_metric'] ?? '') ?>">
      </div>
    </div>
  </div>

  <div class="vp-profile-tab-panel" id="tab-founders">
    <div class="vp-profile-section-head">
      <h3>Co-founder Details</h3>
      <p>Add founder contact details.</p>
    </div>

    <div class="form-grid-2">
      <div class="form-group">
        <label class="form-label">Co-founder 1 Name</label>
        <input type="text" name="cofounder1_name" class="form-control" value="<?= h($VENTURE['cofounder1_name'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label class="form-label">Co-founder 1 Email</label>
        <input type="email" name="cofounder1_email" class="form-control" value="<?= h($VENTURE['cofounder1_email'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label class="form-label">Co-founder 1 Contact</label>
        <input type="text" name="cofounder1_contact" class="form-control" value="<?= h($VENTURE['cofounder1_contact'] ?? '') ?>">
      </div>

      <div></div>

      <div class="form-group">
        <label class="form-label">Co-founder 2 Name</label>
        <input type="text" name="cofounder2_name" class="form-control" value="<?= h($VENTURE['cofounder2_name'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label class="form-label">Co-founder 2 Email</label>
        <input type="email" name="cofounder2_email" class="form-control" value="<?= h($VENTURE['cofounder2_email'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label class="form-label">Co-founder 2 Contact</label>
        <input type="text" name="cofounder2_contact" class="form-control" value="<?= h($VENTURE['cofounder2_contact'] ?? '') ?>">
      </div>
    </div>
  </div>

  <div class="vp-profile-tab-panel" id="tab-links">
    <div class="vp-profile-section-head">
      <h3>Links & Funding</h3>
      <p>Update website, LinkedIn and funding information.</p>
    </div>

    <div class="form-grid-2">
      <div class="form-group">
        <label class="form-label">Website</label>
        <input type="url" name="website" class="form-control" value="<?= h($VENTURE['website'] ?? '') ?>" placeholder="https://example.com">
      </div>

      <div class="form-group">
        <label class="form-label">LinkedIn URL</label>
        <input type="url" name="linkedin_url" class="form-control" value="<?= h($VENTURE['linkedin_url'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label class="form-label">Funding Raised</label>
        <input type="number" step="0.01" min="0" name="funding_raised" class="form-control" value="<?= h($VENTURE['funding_raised'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label class="form-label">Funding Sought</label>
        <input type="number" step="0.01" min="0" name="funding_sought" class="form-control" value="<?= h($VENTURE['funding_sought'] ?? '') ?>">
      </div>
    </div>
  </div>

  <div class="vp-profile-tab-panel" id="tab-media">
    <div class="vp-profile-section-head">
      <h3>Media & Showcase</h3>
      <p>Upload your venture logo and featured showcase image.</p>
    </div>

    <div class="vp-media-grid">
      <div class="vp-media-upload">
        <h4>Logo</h4>
        <p>Use a square logo for best display quality.</p>
        <input type="file" name="logo_alt" class="form-control" accept="image/*" disabled>
        <span class="form-hint">Use the logo uploader at the top of the page.</span>
      </div>

      <div class="vp-media-upload">
        <h4>Featured Image</h4>
        <p>This image can be used on public venture showcase cards.</p>
        <input type="file" name="featured_image" class="form-control" accept="image/*">
        <?php if (!empty($VENTURE['featured_image'])): ?>
          <span class="form-hint">Current: <?= h($VENTURE['featured_image']) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="vp-profile-tab-panel" id="tab-security">
    <div class="vp-profile-section-head">
      <h3>Change Password</h3>
      <p>Leave these fields blank if you do not want to change your password.</p>
    </div>

    <div class="form-grid-2">
      <div class="form-group">
        <label class="form-label">Current Password</label>
        <div class="password-field">
          <input type="password" name="current_password" id="current_password" class="form-control" autocomplete="current-password">
          <button type="button" class="password-toggle" onclick="togglePassword('current_password', this)">
            <i class="fa fa-eye"></i>
          </button>
        </div>
      </div>

      <div></div>

      <div class="form-group">
        <label class="form-label">New Password</label>
        <div class="password-field">
          <input type="password" name="new_password" id="new_password" class="form-control" minlength="8" autocomplete="new-password">
          <button type="button" class="password-toggle" onclick="togglePassword('new_password', this)">
            <i class="fa fa-eye"></i>
          </button>
        </div>
        <span class="form-hint">Use at least 8 characters.</span>
      </div>

      <div class="form-group">
        <label class="form-label">Confirm New Password</label>
        <div class="password-field">
          <input type="password" name="confirm_password" id="confirm_password" class="form-control" minlength="8" autocomplete="new-password">
          <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', this)">
            <i class="fa fa-eye"></i>
          </button>
        </div>
      </div>
    </div>
  </div>

  <div class="vp-profile-bottom-actions">
    <a href="dashboard.php" class="btn btn-outline">Cancel</a>
    <button type="submit" class="btn btn-primary">
      <i class="fa fa-save"></i> Save Changes
    </button>
  </div>
</form>

</div>
</div>

<script>
function previewLogo(input) {
  if (input.files && input.files[0]) {
    const reader = new FileReader();

    reader.onload = function(e) {
      const preview = document.getElementById('logoPreview');
      const initials = document.getElementById('logoInitials');

      if (preview) {
        preview.src = e.target.result;
        preview.style.display = 'block';
      }

      if (initials) {
        initials.style.display = 'none';
      }
    };

    reader.readAsDataURL(input.files[0]);
  }
}

document.querySelectorAll('.vp-profile-tab').forEach(button => {
  button.addEventListener('click', function() {
    const tab = this.dataset.tab;

    document.querySelectorAll('.vp-profile-tab').forEach(btn => {
      btn.classList.remove('active');
    });

    document.querySelectorAll('.vp-profile-tab-panel').forEach(panel => {
      panel.classList.remove('active');
    });

    this.classList.add('active');

    const activePanel = document.getElementById('tab-' + tab);
    if (activePanel) {
      activePanel.classList.add('active');
    }
  });
});

function togglePassword(inputId, btn) {
  const input = document.getElementById(inputId);
  const icon = btn.querySelector('i');

  if (!input || !icon) return;

  if (input.type === 'password') {
    input.type = 'text';
    icon.className = 'fa fa-eye-slash';
  } else {
    input.type = 'password';
    icon.className = 'fa fa-eye';
  }
}

function toggleSidebar() {
  const sidebar = document.getElementById('vpSidebar');
  if (sidebar) {
    sidebar.classList.toggle('open');
  }
}
</script>

</body>
</html>