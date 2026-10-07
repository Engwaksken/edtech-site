<?php
require_once '../includes/config.php';
require_once 'auth.php';

$page_title  = 'Mentors';
$current_nav = 'mentors.php';


$mentors_rs = $conn->query("
    SELECT DISTINCT m.*,
      (SELECT COUNT(*) FROM mentor_sessions ms WHERE ms.mentor_id=m.id AND ms.venture_id=$venture_id AND ms.status='completed') AS sessions_with_us,
      (SELECT ROUND(AVG(ms.venture_rating),1) FROM mentor_sessions ms WHERE ms.mentor_id=m.id AND ms.venture_rating IS NOT NULL) AS avg_rating
    FROM mentors m
    JOIN mentor_assignments ma ON ma.mentor_id=m.id
    WHERE m.status='active'
      AND (ma.venture_id=$venture_id OR ma.cohort_id=".((int)($VENTURE['cohort_id']??0)).")
    ORDER BY m.is_featured DESC, m.full_name ASC
");

// Active sessions with each mentor
$active_sessions = [];
$as_rs = $conn->query("SELECT mentor_id, COUNT(*) AS cnt FROM mentor_sessions WHERE venture_id=$venture_id AND status IN('requested','scheduled','confirmed') GROUP BY mentor_id");
while ($r=$as_rs->fetch_assoc()) $active_sessions[$r['mentor_id']]=(int)$r['cnt'];

$expertise_opts=['fundraising'=>'Fundraising','product'=>'Product','marketing'=>'Marketing','tech'=>'Technology','ops'=>'Operations','legal'=>'Legal','finance'=>'Finance','sales'=>'Sales','strategy'=>'Strategy','impact'=>'Impact','design'=>'Design','hr'=>'HR','growth'=>'Growth Hacking'];
$platform_opts=['zoom'=>'Zoom','google_meet'=>'Google Meet','teams'=>'MS Teams','phone'=>'Phone','in_person'=>'In Person','other'=>'Other'];

include 'layout.php';
?>

<style>
  .mentor-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:18px}
  .mentor-card{background:var(--white);border:1.5px solid var(--border);border-radius:14px;overflow:hidden;transition:border-color .14s,box-shadow .14s;display:flex;flex-direction:column}
  .mentor-card:hover{border-color:var(--ink-3);box-shadow:0 4px 20px rgba(12,12,14,.08)}
  .mentor-card-hero{height:6px;background:linear-gradient(90deg,var(--gold),#e8c56e)}
  .mentor-card-body{padding:18px;flex:1}
  .mentor-avatar-wrap{display:flex;align-items:flex-start;gap:14px;margin-bottom:14px}
  .m-photo{width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid var(--border);flex-shrink:0}
  .m-initials{width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#1a1a2e,#3b4a8a);color:var(--white);display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-size:18px;font-weight:800;flex-shrink:0}
  .m-name{font-family:'Syne',sans-serif;font-size:15px;font-weight:700;color:var(--ink)}
  .m-role{font-size:12.5px;color:var(--muted);margin-top:2px}
  .m-bio{font-size:13px;color:var(--ink-3);line-height:1.6;margin-bottom:12px}
  .m-tags{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:12px}
  .m-tag{font-size:10.5px;font-weight:600;padding:2px 8px;border-radius:8px;background:var(--surface);color:var(--muted);border:1px solid var(--border)}
  .m-stats{display:flex;gap:14px;padding:10px 18px;background:var(--surface);border-top:1px solid var(--border)}
  .m-stat{display:flex;flex-direction:column;align-items:center;gap:2px}
  .m-stat-num{font-family:'Syne',sans-serif;font-size:16px;font-weight:800;color:var(--ink)}
  .m-stat-lbl{font-size:10.5px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.04em}
  .star-row{display:flex;align-items:center;gap:2px;color:#f59e0b;font-size:11px}
  .m-card-footer{padding:14px 18px;border-top:1px solid var(--border);display:flex;gap:8px}
  .pending-badge{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:3px 8px;border-radius:8px;background:#fef3c7;color:#92400e}
  /* Modal */
  .vp-modal-overlay{position:fixed;inset:0;background:rgba(12,12,14,.5);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;z-index:200;opacity:0;pointer-events:none;transition:opacity .2s}
  .vp-modal-overlay.open{opacity:1;pointer-events:all}
  .vp-modal{background:var(--white);border-radius:14px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;margin:20px}
  .vp-modal-header{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;border-bottom:1px solid var(--border)}
  .vp-modal-title{font-family:'Syne',sans-serif;font-size:16px;font-weight:800;color:var(--ink)}
  .vp-modal-close{background:none;border:none;font-size:20px;color:var(--muted);cursor:pointer;padding:0;line-height:1}
  .vp-modal-body{padding:20px}
  .vp-modal-footer{padding:14px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px}
</style>

<?php $has=false; ?>
<div style="margin-bottom:20px">
  <h2 style="font-family:'Syne',sans-serif;font-size:19px;font-weight:800;color:var(--ink)">Your Mentors</h2>
  <p style="font-size:13px;color:var(--muted);margin-top:2px">Mentors assigned to your cohort and venture. Request sessions directly.</p>
</div>

<?php if($mentors_rs->num_rows===0): ?>
<div style="text-align:center;padding:60px 20px">
  <i class="fa fa-chalkboard-teacher" style="font-size:48px;color:var(--border);margin-bottom:14px;display:block"></i>
  <h3 style="font-family:'Syne',sans-serif;font-size:18px;font-weight:700;margin-bottom:6px">No mentors assigned yet</h3>
  <p style="color:var(--muted);font-size:13.5px">The programme team will assign mentors to your cohort soon.</p>
</div>
<?php else: ?>
<div class="mentor-grid">
<?php while($m=$mentors_rs->fetch_assoc()): $has=true; ?>
<div class="mentor-card">
  <div class="mentor-card-hero"></div>
  <div class="mentor-card-body">
    <div class="mentor-avatar-wrap">
      <?php if(!empty($m['photo'])): ?>
        <img src="<?= SITE_URL.'/'.h($m['photo']) ?>" class="m-photo" alt="">
      <?php else: ?>
        <div class="m-initials"><?= strtoupper(substr($m['full_name'],0,2)) ?></div>
      <?php endif; ?>
      <div style="min-width:0">
        <div class="m-name">
          <?= h($m['full_name']) ?>
          <?php if($m['is_featured']): ?><i class="fa fa-star" style="color:#f59e0b;font-size:12px;margin-left:4px"></i><?php endif; ?>
        </div>
        <div class="m-role"><?= h($m['job_title']??'') ?><?= $m['organisation']?' · '.h($m['organisation']):'' ?></div>
        <?php if($m['avg_rating']): ?>
          <div class="star-row" style="margin-top:4px">
            <?php for($i=1;$i<=5;$i++) echo '<i class="fa fa-star'.($i<=$m['avg_rating']?'':'-o').'"></i>'; ?>
            <span style="font-size:11px;color:var(--muted);margin-left:4px"><?= $m['avg_rating'] ?></span>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if(!empty($m['bio'])): ?>
      <p class="m-bio"><?= h(mb_strimwidth($m['bio'],0,120,'…')) ?></p>
    <?php endif; ?>

    <?php if(!empty($m['expertise'])): ?>
      <div class="m-tags">
        <?php foreach(array_slice(explode(',',$m['expertise']),0,5) as $e): $e=trim($e); if(!$e) continue; ?>
          <span class="m-tag"><?= h($expertise_opts[$e]??$e) ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if($m['location']||$m['linkedin_url']): ?>
      <div style="display:flex;gap:10px;font-size:12px;color:var(--muted);flex-wrap:wrap">
        <?php if($m['location']): ?><span><i class="fa fa-map-marker-alt"></i> <?= h($m['location']) ?></span><?php endif; ?>
        <?php if($m['linkedin_url']): ?><a href="<?= h($m['linkedin_url']) ?>" target="_blank" style="color:var(--ink);font-weight:600"><i class="fa fa-linkedin"></i> LinkedIn</a><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="m-stats">
    <div class="m-stat"><div class="m-stat-num"><?= (int)$m['sessions_with_us'] ?></div><div class="m-stat-lbl">Sessions</div></div>
    <div class="m-stat"><div class="m-stat-num"><?= (int)$m['weekly_hours'] ?>h</div><div class="m-stat-lbl">Weekly</div></div>
    <?php if(isset($active_sessions[$m['id']])): ?>
      <div class="m-stat"><span class="pending-badge"><i class="fa fa-clock"></i> <?= $active_sessions[$m['id']] ?> pending</span></div>
    <?php endif; ?>
  </div>

  <div class="m-card-footer">
    <button class="btn btn-primary" style="flex:1"
            onclick='openRequestModal(<?= (int)$m['id'] ?>, "<?= h(addslashes($m['full_name'])) ?>")'>
      <i class="fa fa-calendar-plus"></i> Request Session
    </button>
    <a href="mentor-messages.php?mentor_id=<?= (int)$m['id'] ?>" class="btn btn-outline">
      <i class="fa fa-comment-dots"></i>
    </a>
  </div>
</div>
<?php endwhile; ?>
</div>
<?php endif; ?>

<!-- ------ REQUEST SESSION MODAL ------ -->
<div class="vp-modal-overlay" id="requestModal">
<div class="vp-modal">
  <div class="vp-modal-header">
    <div class="vp-modal-title" id="reqModalTitle">Request a Session</div>
    <button type="button" class="vp-modal-close" onclick="closeRequestModal()">x</button>
  </div>
  <form method="POST" action="includes/portal-process.php">
    <input type="hidden" name="action" value="request_session">
    <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
    <input type="hidden" name="mentor_id" id="req_mentor_id">
    <div class="vp-modal-body">
    <div style="display:flex;flex-direction:column;gap:16px">
      <div class="form-group">
        <label class="form-label">Session Title <span class="req">*</span></label>
        <input type="text" name="title" class="form-control" placeholder="e.g. Fundraising Strategy Review" required>
      </div>
      <div class="form-group">
        <label class="form-label">What would you like to discuss? <span class="req">*</span></label>
        <textarea name="description" class="form-control" rows="4" placeholder="Briefly describe your goals for this session, questions you have, or challenges you're facing…" required></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Preferred Date &amp; Time</label>
        <input type="datetime-local" name="preferred_at" class="form-control">
        <span class="form-hint">Optional - the programme team will confirm the final time.</span>
      </div>
      <div class="form-group">
        <label class="form-label">Session Type</label>
        <select name="session_type" class="form-control">
          <option value="one_on_one">1-on-1 Session</option>
          <option value="review">Review / Feedback</option>
          <option value="workshop">Workshop / Deep Dive</option>
          <option value="ad_hoc">Quick Check-in</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Preferred Duration</label>
        <select name="duration_minutes" class="form-control">
          <option value="30">30 minutes</option>
          <option value="45">45 minutes</option>
          <option value="60" selected>60 minutes</option>
          <option value="90">90 minutes</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Preferred Platform</label>
        <select name="meeting_platform" class="form-control">
          <?php foreach($platform_opts as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    </div>
    <div class="vp-modal-footer">
      <button type="button" class="btn btn-outline" onclick="closeRequestModal()">Cancel</button>
      <button type="submit" class="btn btn-primary"><i class="fa fa-paper-plane"></i> Submit Request</button>
    </div>
  </form>
</div>
</div>

<script>
function toggleSidebar(){document.getElementById('vpSidebar').classList.toggle('open');}
const reqM=document.getElementById('requestModal');
function openRequestModal(id,name){
  document.getElementById('req_mentor_id').value=id;
  document.getElementById('reqModalTitle').textContent='Request Session with '+name;
  reqM.classList.add('open');
}
function closeRequestModal(){reqM.classList.remove('open');}
reqM.addEventListener('click',e=>{if(e.target===reqM)closeRequestModal();});
</script>

  </div></div>
</body>
</html>