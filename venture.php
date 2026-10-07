<?php
require_once 'includes/config.php';

$slug = esc($conn, $_GET['slug'] ?? '');
if (!$slug) { header('Location: cohorts.php'); exit; }

$startup = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT v.*, c.name AS cohort_name, c.slug AS cohort_slug FROM ventures v
     LEFT JOIN cohorts c ON c.id=v.cohort_id
     WHERE v.slug='$slug' AND v.status='active' LIMIT 1"
));
if (!$startup) { header('Location: cohorts.php'); exit; }

$sid = $startup['id'];


$team = mysqli_query($conn,
    "SELECT * FROM venture_team WHERE venture_id=$sid ORDER BY is_founder DESC, sort_order ASC, full_name"
);


$assigned_staff = mysqli_query($conn,
    "SELECT st.*, ss.role_description FROM staff st
     JOIN venture_staff ss ON ss.staff_id=st.id
     WHERE ss.venture_id=$sid AND st.status=1
     ORDER BY st.category, st.sort_order, st.full_name"
);


$staff_by_cat = [];
$all_assigned = [];
while ($sf = mysqli_fetch_assoc($assigned_staff)) {
    $staff_by_cat[$sf['category']][] = $sf;
    $all_assigned[] = $sf;
}

$team_rows = [];
while ($tm = mysqli_fetch_assoc($team)) $team_rows[] = $tm;

include 'includes/header.php';
?>

<div style="padding-top:72px">

<!-- ── STARTUP HERO ───────────────────────────────────────── -->
<section class="startup-hero" style="background:linear-gradient(135deg,#0d1117,#1a2332);padding:64px 0 48px">
  <div class="container">
    <div style="margin-bottom:18px">
      <a href="cohorts.php" style="color:rgba(255,255,255,.6);font-size:.875rem">
        <i class="fa fa-arrow-left"></i> All Cohorts
      </a>
      <?php if ($startup['cohort_name']): ?>
      <span style="color:rgba(255,255,255,.4);margin:0 8px">›</span>
      <a href="cohorts.php#cohort-<?= $startup['cohort_id'] ?>" style="color:rgba(255,255,255,.6);font-size:.875rem"><?= h($startup['cohort_name']) ?></a>
      <?php endif; ?>
    </div>

    <div class="startup-hero-content">
      <!-- Logo -->
      <div class="startup-hero-logo">
        <?php if ($startup['logo'] && file_exists($startup['logo'])): ?>
          <img src="<?= SITE_URL . '/' . h($startup['logo']) ?>" alt="<?= h($startup['name']) ?>">
        <?php else: ?>
          <div class="startup-hero-initial"><?= strtoupper(substr($startup['name'],0,1)) ?></div>
        <?php endif; ?>
      </div>

      <!-- Info -->
      <div class="startup-hero-info">
        <div class="startup-hero-tags">
          <?php if ($startup['sector']): ?><span class="tag-orange"><?= h($startup['sector']) ?></span><?php endif; ?>
        
          <?php if ($startup['cohort_name']): ?><span class="tag-white"><?= h($startup['cohort_name']) ?></span><?php endif; ?>
        </div>
        <h1 style="color:#fff;margin:10px 0 8px;font-family:'Playfair Display',serif"><?= h($startup['name']) ?></h1>
        <?php if ($startup['tagline']): ?>
          <p style="color:rgba(255,255,255,.85);font-size:1.1rem;margin-bottom:14px"><?= h($startup['tagline']) ?></p>
        <?php endif; ?>
        <div class="startup-hero-meta">
          <?php if ($startup['location']): ?><span><i class="fa fa-map-marker-alt"></i> <?= h($startup['location']) ?></span><?php endif; ?>
          <?php if ($startup['founded_year']): ?><span><i class="fa fa-calendar"></i> Founded <?= h($startup['founded_year']) ?></span><?php endif; ?>
          <?php if ($startup['impact_metric']): ?><span><i class="fa fa-chart-line"></i> <?= h($startup['impact_metric']) ?></span><?php endif; ?>
        </div>
        <div class="startup-hero-links" style="margin-top:16px">
          <?php if ($startup['website']): ?>
            <a href="<?= h($startup['website']) ?>" target="_blank" class="btn btn-primary btn-sm"><i class="fa fa-globe"></i> Website</a>
          <?php endif; ?>
          <?php if ($startup['cofounder1_email']): ?>
            <a href="mailto:<?= h($startup['email']) ?>" class="btn btn-outline btn-sm" style="color:#fff;border-color:rgba(255,255,255,.4)"><i class="fa fa-envelope"></i> Email</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>


<section style="padding:60px 0;background:#ff98001a">
  <div class="container">
    <div class="startup-body-grid">

      <!-- Left: Description + Team -->
      <div class="startup-main">

        <!-- Featured Image -->
        <?php if ($startup['featured_image'] && file_exists($startup['featured_image'])): ?>
        <div class="content-card" style="padding:0;overflow:hidden">
          <img src="<?= SITE_URL . '/' . h($startup['featured_image']) ?>" alt="<?= h($startup['name']) ?>" style="width:100%;max-height:420px;object-fit:cover;display:block">
       
        <?php endif; ?>
<div class="content-card">
        <!-- Description -->
        <?php if ($startup['description']): ?>
        
          <h2 class="content-card-title"><i class="fa fa-info-circle" style="color:var(--primary-color)"></i> About <?= h($startup['name']) ?></h2>
          <div class="about-text"><?= nl2br(h($startup['description'])) ?></div>
        </div>
       
        <?php endif; ?>
 </div>
        <!-- Team Members -->
        <?php if (!empty($team_rows)): ?>
        <div class="content-card">
          <h2 class="content-card-title"><i class="fa fa-users" style="color:var(--teal-color)"></i> The Team</h2>
          <div class="team-grid">
            <?php foreach ($team_rows as $member): ?>
            <div class="team-member-card">
              <div class="team-photo-wrap">
                <?php if ($member['photo'] && file_exists($member['photo'])): ?>
                  <img src="<?= SITE_URL . '/' . h($member['photo']) ?>" alt="<?= h($member['full_name']) ?>" class="team-photo">
                <?php else: ?>
                  <div class="team-photo team-photo-initial"><?= strtoupper(substr($member['full_name'],0,1)) ?></div>
                <?php endif; ?>
                <?php if ($member['is_founder']): ?>
                  <span class="founder-badge"><i class="fa fa-star"></i> Founder</span>
                <?php endif; ?>
              </div>
              <div class="team-member-info">
                <h4><?= h($member['full_name']) ?></h4>
                <?php if ($member['role']): ?><p class="team-role"><?= h($member['role']) ?></p><?php endif; ?>
                <?php if ($member['bio']): ?><p class="team-bio"><?= h(truncate($member['bio'],120)) ?></p><?php endif; ?>
                <div class="team-socials">
                  <?php if ($member['linkedin']): ?><a href="<?= h($member['linkedin']) ?>" target="_blank"><i class="fab fa-linkedin"></i></a><?php endif; ?>
                  <?php if ($member['twitter']): ?><a href="<?= h($member['twitter']) ?>" target="_blank"><i class="fab fa-twitter"></i></a><?php endif; ?>
                  <?php if ($member['email']): ?><a href="mailto:<?= h($member['email']) ?>"><i class="fa fa-envelope"></i></a><?php endif; ?>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

      </div>

      <!-- Right: Sidebar with details + assigned staff -->
      <div class="startup-sidebar">

        <!-- Quick Info -->
        <div class="content-card">
          <h3 class="content-card-title">Quick Info</h3>
          <ul class="info-list">
            <?php if ($startup['sector']): ?>
            <li><span class="info-label"><i class="fa fa-tag"></i> Sector</span><span class="info-val"><?= h($startup['sector']) ?></span></li>
            <?php endif; ?>
           
            <?php if ($startup['founded_year']): ?>
            <li><span class="info-label"><i class="fa fa-calendar"></i> Founded</span><span class="info-val"><?= h($startup['founded_year']) ?></span></li>
            <?php endif; ?>
            <?php if ($startup['location']): ?>
            <li><span class="info-label"><i class="fa fa-map-marker-alt"></i> Location</span><span class="info-val"><?= h($startup['location']) ?></span></li>
            <?php endif; ?>
            <?php if ($startup['impact_metric']): ?>
            <li><span class="info-label"><i class="fa fa-chart-line"></i> Impact</span><span class="info-val"><?= h($startup['impact_metric']) ?></span></li>
            <?php endif; ?>
            <?php if ($startup['cohort_name']): ?>
            <li><span class="info-label"><i class="fa fa-layer-group"></i> Cohort</span><span class="info-val"><?= h($startup['cohort_name']) ?></span></li>
            <?php endif; ?>
          </ul>
          <?php if ($startup['website']): ?>
          <a href="<?= h($startup['website']) ?>" target="_blank" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:16px">
            <i class="fa fa-globe"></i> Visit Website
          </a>
          <?php endif; ?>
        </div>

        <!-- Assigned Staff -->
        <?php if (!empty($all_assigned)):
          $cat_labels = ['program_staff'=>'Program Staff','mentor'=>'Mentors','advisor'=>'Advisors','partner_staff'=>'Partner Staff'];
        ?>
        <div class="content-card">
          <h3 class="content-card-title"><i class="fa fa-user-tie" style="color:var(--primary-color)"></i> Fellowship Support</h3>
          <?php foreach ($cat_labels as $cat_key => $cat_label):
            if (empty($staff_by_cat[$cat_key])) continue;
          ?>
          <div class="staff-category-group">
            <p class="staff-cat-label"><?= $cat_label ?></p>
            <?php foreach ($staff_by_cat[$cat_key] as $sf): ?>
            <div class="staff-assigned-card">
              <div class="staff-photo-sm">
                <?php if ($sf['photo'] && file_exists($sf['photo'])): ?>
                  <img src="<?= SITE_URL . '/' . h($sf['photo']) ?>" alt="<?= h($sf['full_name']) ?>">
                <?php else: ?>
                  <div class="staff-initial-sm"><?= strtoupper(substr($sf['full_name'],0,1)) ?></div>
                <?php endif; ?>
              </div>
              <div class="staff-info-sm">
                <h5><?= h($sf['full_name']) ?></h5>
                <p><?= h($sf['title'] ?: '') ?><?= ($sf['title']&&$sf['organization']) ? ', ' : '' ?><?= h($sf['organization'] ?: '') ?></p>
                <?php if ($sf['role_description']): ?>
                  <p class="staff-role-desc"><em><?= h($sf['role_description']) ?></em></p>
                <?php endif; ?>
                <div class="staff-socials-sm">
                  <?php if ($sf['linkedin']): ?><a href="<?= h($sf['linkedin']) ?>" target="_blank"><i class="fab fa-linkedin"></i></a><?php endif; ?>
                  <?php if ($sf['email']): ?><a href="mailto:<?= h($sf['email']) ?>"><i class="fa fa-envelope"></i></a><?php endif; ?>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      </div>
    </div>
  </div>
</section>
</div>

<?php include 'includes/footer.php'; ?>
