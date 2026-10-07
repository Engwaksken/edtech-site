<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

$page_title  = 'Our Team';
$current_nav = 'team.php';

// -- Load existing team members --------------------------------
$members = [];
$res = $conn->prepare("
    SELECT * FROM venture_team
    WHERE venture_id = ?
    ORDER BY sort_order ASC, is_founder DESC, created_at ASC
");
$res->bind_param('i', $venture_id);
$res->execute();
$members_rs = $res->get_result();
while ($row = $members_rs->fetch_assoc()) $members[] = $row;
$res->close();

$member_count  = count($members);
$slots_left    = max(0, 4 - $member_count);
$at_capacity   = $member_count >= 4;

// -- Handle flash ---------------------------------------------
$flash_msg  = $_SESSION['vp_flash_msg']  ?? '';
$flash_type = $_SESSION['vp_flash_type'] ?? 'success';
unset($_SESSION['vp_flash_msg'], $_SESSION['vp_flash_type']);

// -- Layout shared header --------------------------------------
include 'layout.php';
?>
<?php if (!empty($flash_msg)): ?>
<div class="vp-flash <?= h($flash_type) ?>">
  <i class="fa <?= $flash_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
  <?= h($flash_msg) ?>
</div>
<?php endif; ?>

<div class="team-page">

  <!-- Hero header -->
  <div class="team-hero">
    <div class="team-hero-text">
      <h1>The <em>people</em> behind the venture.</h1>
      <p>
        Add up to 4 team members - the faces that make
        <strong style="color:rgba(255,255,255,.85)"><?= h($VENTURE['name']) ?></strong> possible.
      </p>
    </div>
    <div class="slot-counter">
      <div class="slot-pips">
        <?php for ($i = 1; $i <= 4; $i++): ?>
          <div class="slot-pip <?= $i <= $member_count ? 'filled' : 'empty' ?>"></div>
        <?php endfor; ?>
      </div>
      <div class="slot-label"><?= $member_count ?> of 4 members</div>
    </div>
  </div>

  <?php if ($at_capacity): ?>
  <div class="capacity-notice">
    <i class="fa fa-users"></i>
    <div>
      <strong>Team is full.</strong> You've reached the maximum of 4 team members.
      Remove a member to add someone new.
    </div>
  </div>
  <?php endif; ?>

  <!-- Team grid -->
  <div class="team-grid" id="teamGrid">

    <?php foreach ($members as $member): ?>
    <div class="member-card <?= $member['is_founder'] ? 'is-founder' : '' ?>"
         data-id="<?= (int)$member['id'] ?>">
      <div class="member-card-body">

        <!-- Photo + name row -->
        <div style="display:flex;align-items:flex-start;gap:14px;margin-bottom:12px">
          <div class="member-photo-wrap">
            <?php if (!empty($member['photo'])): ?>
              <img src="<?= SITE_URL . '/' . h($member['photo']) ?>"
                   class="member-photo" alt="<?= h($member['full_name']) ?>">
            <?php else: ?>
              <div class="member-initials"><?= strtoupper(substr($member['full_name'], 0, 1)) ?></div>
            <?php endif; ?>
            <?php if ($member['is_founder']): ?>
              <div class="founder-crown" title="Founder"><i class="fa fa-star" style="font-size:8px"></i></div>
            <?php endif; ?>
          </div>
          <div style="min-width:0;flex:1">
            <div class="member-name"><?= h($member['full_name']) ?></div>
            <div class="member-role"><?= h($member['role'] ?? '') ?></div>
            <?php if (!empty($member['email'])): ?>
              <div style="font-size:11.5px;color:var(--muted)">
                <i class="fa fa-envelope" style="font-size:10px"></i>
                <?= h($member['email']) ?>
              </div>
            <?php endif; ?>
          </div>
          <!-- Drag handle -->
          <div class="drag-handle" title="Drag to reorder"><i class="fa fa-grip-vertical"></i></div>
        </div>

        <?php if (!empty($member['bio'])): ?>
          <p class="member-bio"><?= h($member['bio']) ?></p>
        <?php endif; ?>

        <!-- Social links -->
        <?php if (!empty($member['linkedin']) || !empty($member['twitter']) || !empty($member['email'])): ?>
        <div class="member-socials">
          <?php if (!empty($member['linkedin'])): ?>
            <a href="<?= h($member['linkedin']) ?>" target="_blank" class="social-link linkedin" title="LinkedIn">
              <i class="fa fa-linkedin"></i>
            </a>
          <?php endif; ?>
          <?php if (!empty($member['twitter'])): ?>
            <a href="https://twitter.com/<?= h(ltrim($member['twitter'],'@')) ?>" target="_blank" class="social-link twitter" title="Twitter">
              <i class="fa fa-twitter"></i>
            </a>
          <?php endif; ?>
          <?php if (!empty($member['email'])): ?>
            <a href="mailto:<?= h($member['email']) ?>" class="social-link email" title="Email">
              <i class="fa fa-envelope"></i>
            </a>
          <?php endif; ?>
        </div>
        <?php endif; ?>

      </div>

      <div class="member-card-footer">
        <?php if ($member['is_founder']): ?>
          <span class="founder-badge"><i class="fa fa-star" style="font-size:9px"></i> Founder</span>
        <?php else: ?>
          <span></span>
        <?php endif; ?>
        <div style="display:flex;gap:7px">
          <button class="btn btn-sm btn-outline" title="Edit"
                  onclick='openEditModal(<?= json_encode($member, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'>
            <i class="fa fa-edit"></i>
          </button>
          <form method="POST" action="includes/portal-process.php" style="display:inline"
                onsubmit="return confirm('Remove <?= h(addslashes($member['full_name'])) ?> from the team?')">
            <input type="hidden" name="action" value="remove_team_member">
            <input type="hidden" name="id" value="<?= (int)$member['id'] ?>">
            <button class="btn btn-sm btn-danger" title="Remove"><i class="fa fa-trash"></i></button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Empty slot cards -->
    <?php for ($s = 0; $s < $slots_left; $s++): ?>
    <div class="slot-card" onclick="openAddModal()" title="Add team member">
      <div class="slot-card-icon"><i class="fa fa-user-plus"></i></div>
      <div class="slot-card-label">Add Team Member</div>
      <div class="slot-card-sub"><?= $slots_left - $s ?> slot<?= ($slots_left - $s) !== 1 ? 's' : '' ?> available</div>
    </div>
    <?php endfor; ?>

  </div><!-- /team-grid -->

  <?php if ($at_capacity): ?>
  <p style="text-align:center;font-size:13px;color:var(--muted)">
    <i class="fa fa-lock" style="margin-right:5px"></i>
    Maximum team size of 4 reached.
  </p>
  <?php elseif ($member_count < 4): ?>
  <div style="text-align:center;margin-top:4px">
    <button class="btn btn-primary" onclick="openAddModal()">
      <i class="fa fa-user-plus"></i> Add Team Member
    </button>
  </div>
  <?php endif; ?>

</div><!-- /team-page -->


<!-- ------------------------------------------------
     ADD / EDIT TEAM MEMBER MODAL
------------------------------------------------ -->
<div class="team-modal-overlay" id="teamModal">
<div class="team-modal">

  <div class="tm-header">
    <div class="tm-header-icon" id="tmHeaderIcon"><i class="fa fa-user-plus"></i></div>
    <div class="tm-header-title" id="tmHeaderTitle">Add Team Member</div>
    <button type="button" class="tm-close" onclick="closeModal()">x</button>
  </div>

  <form method="POST" action="includes/portal-process.php" enctype="multipart/form-data" id="teamForm">
    <input type="hidden" name="action" id="tm_action" value="add_team_member">
    <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
    <input type="hidden" name="id" id="tm_id">
    <input type="hidden" name="existing_photo" id="tm_existing_photo">

    <div class="tm-body">

      <!-- Photo upload -->
      <div class="photo-upload-zone">
        <label class="photo-preview-wrap" for="tm_photo_input">
          <img id="tmPhotoImg" class="photo-preview"
               src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='72' height='72'%3E%3C/svg%3E"
               style="display:none" alt="">
          <div class="member-initials photo-preview-placeholder" id="tmPhotoPlaceholder">
            <i class="fa fa-camera" style="font-size:20px;color:rgba(255,255,255,.5)"></i>
          </div>
          <div class="photo-upload-overlay"><i class="fa fa-camera"></i></div>
          <input type="file" name="photo" id="tm_photo_input" accept="image/*"
                 style="display:none" onchange="previewPhoto(this)">
        </label>
        <div class="photo-upload-info">
          <strong>Profile Photo</strong>
          Click to upload a photo.<br>
          JPG or PNG, max 2 MB.<br>
          <span style="font-size:11px;color:rgba(122,122,140,.6)">Square images work best.</span>
        </div>
      </div>

      <!-- Founder toggle -->
      <div class="founder-toggle-wrap">
        <div>
          <div class="founder-toggle-label">Mark as Founder</div>
          <div class="founder-toggle-sub">Adds a gold founder badge to this team member's card</div>
        </div>
        <label class="toggle-switch">
          <input type="checkbox" name="is_founder" id="tm_is_founder" value="1">
          <span class="toggle-track"></span>
          <span class="toggle-knob"></span>
        </label>
      </div>

      <!-- Core fields -->
      <div class="tm-field-grid">
        <div class="tm-field">
          <label class="tm-label" for="tm_full_name">Full Name <span style="color:#dc2626">*</span></label>
          <input type="text" class="tm-input" name="full_name" id="tm_full_name"
                 placeholder="Jane Nakato" required
                 oninput="updateInitial(this.value)">
        </div>
        <div class="tm-field">
          <label class="tm-label" for="tm_role">Role / Title <span style="color:#dc2626">*</span></label>
          <input type="text" class="tm-input" name="role" id="tm_role"
                 placeholder="Co-founder &amp; CTO" required>
        </div>
      </div>

      <div class="tm-field">
        <label class="tm-label" for="tm_email">Email Address</label>
        <input type="email" class="tm-input" name="email" id="tm_email"
               placeholder="jane@venture.com">
      </div>

      <div class="tm-field">
        <label class="tm-label" for="tm_bio">Short Bio</label>
        <textarea class="tm-input tm-textarea" name="bio" id="tm_bio"
                  placeholder="A brief description of this team member's background and what they bring to the venture..."
                  maxlength="400"></textarea>
        <div style="font-size:11.5px;color:var(--muted);margin-top:4px;text-align:right">
          <span id="bioCount">0</span> / 400 characters
        </div>
      </div>

      <div class="tm-section">Social Links</div>

      <div class="tm-field">
        <label class="tm-label">LinkedIn</label>
        <div class="tm-social-row">
          <span class="tm-social-prefix"><i class="fa fa-linkedin"></i> linkedin.com/in/</span>
          <input type="text" class="tm-input" name="linkedin" id="tm_linkedin"
                 placeholder="janenkato">
        </div>
      </div>

      <div class="tm-field">
        <label class="tm-label">Twitter / X</label>
        <div class="tm-social-row">
          <span class="tm-social-prefix"><i class="fa fa-twitter"></i> @</span>
          <input type="text" class="tm-input" name="twitter" id="tm_twitter"
                 placeholder="janenkato">
        </div>
      </div>

    </div><!-- /tm-body -->

    <div class="tm-footer">
      <div class="tm-footer-left">
        <div id="tmDeleteWrap" style="display:none">
          <button type="button" class="btn btn-danger btn-sm" onclick="confirmDelete()">
            <i class="fa fa-trash"></i> Remove Member
          </button>
        </div>
      </div>
      <div class="tm-footer-right">
        <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" id="tmSubmitBtn">
          <i class="fa fa-save"></i>
          <span id="tmSubmitLabel">Add Member</span>
        </button>
      </div>
    </div>

  </form>
</div>
</div>


<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
function toggleSidebar(){
  document.getElementById('vpSidebar')?.classList.toggle('open');
}

/* -- Modal state ---------------------------------- */
const modal = document.getElementById('teamModal');
let _editMode = false;
let _deleteId = null;

function openAddModal() {
  _editMode = false; _deleteId = null;
  resetForm();
  document.getElementById('tm_action').value    = 'add_team_member';
  document.getElementById('tmHeaderTitle').textContent = 'Add Team Member';
  document.getElementById('tmHeaderIcon').innerHTML   = '<i class="fa fa-user-plus"></i>';
  document.getElementById('tmSubmitLabel').textContent = 'Add Member';
  document.getElementById('tmDeleteWrap').style.display = 'none';
  modal.classList.add('open');
}

function openEditModal(d) {
  _editMode = true; _deleteId = d.id;
  resetForm();
  document.getElementById('tm_action').value    = 'edit_team_member';
  document.getElementById('tmHeaderTitle').textContent = 'Edit: ' + (d.full_name || 'Member');
  document.getElementById('tmHeaderIcon').innerHTML   = '<i class="fa fa-pencil"></i>';
  document.getElementById('tmSubmitLabel').textContent = 'Save Changes';
  document.getElementById('tmDeleteWrap').style.display = 'block';

  document.getElementById('tm_id').value             = d.id           || '';
  document.getElementById('tm_existing_photo').value = d.photo        || '';
  document.getElementById('tm_full_name').value       = d.full_name    || '';
  document.getElementById('tm_role').value            = d.role         || '';
  document.getElementById('tm_email').value           = d.email        || '';
  document.getElementById('tm_bio').value             = d.bio          || '';
  document.getElementById('tm_linkedin').value        = stripLiPrefix(d.linkedin || '');
  document.getElementById('tm_twitter').value         = (d.twitter || '').replace(/^@/, '');
  document.getElementById('tm_is_founder').checked    = String(d.is_founder) === '1';

  // Update bio counter
  document.getElementById('bioCount').textContent = (d.bio || '').length;

  // Preview existing photo
  if (d.photo) {
    const img = document.getElementById('tmPhotoImg');
    img.src = '<?= SITE_URL ?>/' + d.photo;
    img.style.display = 'block';
    document.getElementById('tmPhotoPlaceholder').style.display = 'none';
  }
  // Update initials placeholder
  updateInitial(d.full_name || '');

  modal.classList.add('open');
}

function closeModal() { modal.classList.remove('open'); }
modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });

function resetForm() {
  document.getElementById('teamForm').reset();
  document.getElementById('tm_id').value = '';
  document.getElementById('tm_existing_photo').value = '';
  document.getElementById('tmPhotoImg').style.display = 'none';
  document.getElementById('tmPhotoImg').src = '';
  document.getElementById('tmPhotoPlaceholder').style.display = 'flex';
  document.getElementById('tmPhotoPlaceholder').innerHTML =
    '<i class="fa fa-camera" style="font-size:20px;color:rgba(255,255,255,.5)"></i>';
  document.getElementById('bioCount').textContent = '0';
}

/* -- Photo preview -------------------------------- */
function previewPhoto(input) {
  if (input.files && input.files[0]) {
    const r = new FileReader();
    r.onload = e => {
      const img = document.getElementById('tmPhotoImg');
      const ph  = document.getElementById('tmPhotoPlaceholder');
      img.src = e.target.result;
      img.style.display = 'block';
      ph.style.display  = 'none';
    };
    r.readAsDataURL(input.files[0]);
  }
}

/* -- Live initials in placeholder ----------------- */
function updateInitial(name) {
  const ph = document.getElementById('tmPhotoPlaceholder');
  if (ph.style.display !== 'none') {
    const initial = name.trim().charAt(0).toUpperCase() || '?';
    ph.innerHTML = `<span style="font-family:'Playfair Display',serif;font-size:26px;font-weight:700;color:#fff">${initial}</span>`;
  }
}

/* -- Bio character counter ------------------------ */
document.getElementById('tm_bio').addEventListener('input', function() {
  document.getElementById('bioCount').textContent = this.value.length;
});

/* -- LinkedIn prefix strip ------------------------ */
function stripLiPrefix(url) {
  return url.replace(/^https?:\/\/(www\.)?linkedin\.com\/in\//i, '').replace(/\/$/, '');
}

/* -- Delete confirmation -------------------------- */
function confirmDelete() {
  if (!_deleteId) return;
  if (!confirm('Remove this team member? This cannot be undone.')) return;

  const fd = new FormData();
  fd.append('action',     'remove_team_member');
  fd.append('id',         _deleteId);

  fetch('includes/portal-process.php', { method: 'POST', body: fd })
    .then(() => { closeModal(); location.reload(); })
    .catch(() => alert('Failed to remove. Please try again.'));
}

/* -- Save sidebar order on drag ------------------- */
if (typeof Sortable !== 'undefined') {
  new Sortable(document.getElementById('teamGrid'), {
    handle:      '.drag-handle',
    animation:   200,
    ghostClass:  'sortable-ghost',
    chosenClass: 'sortable-chosen',
    filter:      '.slot-card',
    onEnd: function() {
      const ids = [];
      document.querySelectorAll('#teamGrid .member-card[data-id]').forEach(c => ids.push(c.dataset.id));
      if (ids.length === 0) return;

      const fd = new FormData();
      fd.append('action',     'reorder_team');
      fd.append('venture_id', '<?= $venture_id ?>');
      fd.append('ids',        JSON.stringify(ids));
      fetch('includes/portal-process.php', { method: 'POST', body: fd });
    }
  });
}
</script>

  </div><!-- /vp-content -->
</div><!-- /vp-main -->

</body>
</html>