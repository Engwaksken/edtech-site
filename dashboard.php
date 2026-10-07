<?php
require_once __DIR__ . '/includes/process-dashboard.php';
include __DIR__ . '/layout.php';
?>

<style>
.dashboard-stat-link{
  min-width:0;
  display:block;
  color:inherit;
  text-decoration:none!important;
  border-radius:inherit;
}
.dashboard-stat-link .stat-tile{
  position:relative;
  height:100%;
  cursor:pointer;
  transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease,background .18s ease;
}
.dashboard-stat-link:hover .stat-tile,
.dashboard-stat-link:focus-visible .stat-tile{
  transform:translateY(-2px);
  border-color:#fdba74;
  background:#fffaf5;
  box-shadow:0 10px 26px rgba(15,23,42,.10);
}
.dashboard-stat-link:focus-visible{
  outline:3px solid rgba(255,87,34,.18);
  outline-offset:3px;
}
.dashboard-stat-arrow{
  margin-left:auto;
  color:#94a3b8;
  font-size:11px;
  transition:color .18s ease,transform .18s ease;
}
.dashboard-stat-link:hover .dashboard-stat-arrow,
.dashboard-stat-link:focus-visible .dashboard-stat-arrow{
  color:#ff5722;
  transform:translateX(2px);
}
@media(max-width:700px){
  .dashboard-stat-arrow{display:none}
}
</style>

<div class="dash-welcome">
  <div class="dash-welcome-text">
    <h2>Welcome back, <?= h($first_name) ?> </h2>
    <p>Here is what is happening with <strong><?= h($VENTURE['name'] ?? 'your venture') ?></strong> today.</p>
  </div>

  <?php if (!empty($VENTURE['cohort_name'])): ?>
    <div class="dash-welcome-cohort">
      <i class="fa fa-layer-group"></i>
      <?= h($VENTURE['cohort_name']) ?>
    </div>
  <?php endif; ?>
</div>


<div class="dashboard-tab-content active" id="tab-overview">
  <div class="stats-row">
    <a href="documents.php" class="dashboard-stat-link" aria-label="View venture documents" title="View Documents">
      <div class="stat-tile">
        <div class="stat-tile-icon icon-doc"><i class="fa fa-folder-open"></i></div>
        <div>
          <div class="stat-tile-num"><?= (int)$doc_count ?></div>
          <div class="stat-tile-lbl">Documents</div>
        </div>
        <i class="fa fa-chevron-right dashboard-stat-arrow" aria-hidden="true"></i>
      </div>
    </a>

    <a href="team.php" class="dashboard-stat-link" aria-label="View team members" title="View Team Members">
      <div class="stat-tile">
        <div class="stat-tile-icon icon-team"><i class="fa fa-users"></i></div>
        <div>
          <div class="stat-tile-num"><?= (int)$team_count ?></div>
          <div class="stat-tile-lbl">Team Members</div>
        </div>
        <i class="fa fa-chevron-right dashboard-stat-arrow" aria-hidden="true"></i>
      </div>
    </a>

    <a href="investors.php" class="dashboard-stat-link" aria-label="View investor connections" title="View Investor Connections">
      <div class="stat-tile">
        <div class="stat-tile-icon icon-investor"><i class="fa fa-handshake"></i></div>
        <div>
          <div class="stat-tile-num"><?= (int)$investor_count ?></div>
          <div class="stat-tile-lbl">Investor Connections</div>
        </div>
        <i class="fa fa-chevron-right dashboard-stat-arrow" aria-hidden="true"></i>
      </div>
    </a>

    <a href="events.php" class="dashboard-stat-link" aria-label="View upcoming events" title="View Upcoming Events">
      <div class="stat-tile">
        <div class="stat-tile-icon icon-event"><i class="fa fa-calendar-alt"></i></div>
        <div>
          <div class="stat-tile-num"><?= (int)$event_count ?></div>
          <div class="stat-tile-lbl">Upcoming Events</div>
        </div>
        <i class="fa fa-chevron-right dashboard-stat-arrow" aria-hidden="true"></i>
      </div>
    </a>

    <a href="survey.php" class="dashboard-stat-link" aria-label="View pending surveys" title="View Pending Surveys">
      <div class="stat-tile">
        <div class="stat-tile-icon icon-survey"><i class="fa fa-poll-h"></i></div>
        <div>
          <div class="stat-tile-num"><?= (int)($pending_survey_count ?? 0) ?></div>
          <div class="stat-tile-lbl">Pending Surveys</div>
        </div>
        <i class="fa fa-chevron-right dashboard-stat-arrow" aria-hidden="true"></i>
      </div>
    </a>
  </div>

  <div class="tab-panel">
    <div class="tab-panel-header panel-between">
      <div>
        <h3>Completed Surveys</h3>
        <p>Every survey <?= h($VENTURE['name'] ?? 'your venture') ?> has submitted a response for.</p>
      </div>
      <span class="badge badge-success"><?= count($completed_surveys ?? []) ?> completed</span>
    </div>

    <?php if (!empty($completed_surveys)): ?>
      <div class="survey-table">
        <div class="survey-table-head">
          <span>Survey</span>
          <span>Submitted</span>
          <span>Status</span>
          <span></span>
        </div>
        <?php foreach ($completed_surveys as $sv): ?>
          <div class="survey-table-row">
            <div class="survey-table-title">
              <div class="survey-row-icon survey-row-icon-done">
                <i class="fa fa-check"></i>
              </div>
              <?= h($sv['title']) ?>
            </div>
            <div class="survey-table-date">
              <?= !empty($sv['submitted_at']) ? date('M j, Y g:i A', strtotime($sv['submitted_at'])) : '-' ?>
            </div>
            <div>
              <span class="badge badge-success">Completed</span>
            </div>
            <div class="survey-table-actions">
              <?php if (!empty($sv['response_id'])): ?>
                <a href="survey.php?action=view_response&id=<?= (int)$sv['response_id'] ?>" class="btn btn-outline btn-sm">
                  <i class="fa fa-eye"></i> View
                </a>
              <?php endif; ?>
              <?php if (!empty($sv['allow_multiple_responses'])): ?>
                <a href="survey.php?t=<?= h($sv['external_token']) ?>" class="btn btn-outline btn-sm">
                  <i class="fa fa-redo"></i> Retake
                </a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state">
        <i class="fa fa-clipboard-check"></i>
        <p>No completed surveys yet.</p>
        <a href="survey.php">Browse available surveys.</a>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="dashboard-tab-content" id="tab-actions">
  <div class="tab-panel">
    <div class="tab-panel-header">
      <h3>Quick Actions</h3>
      <p>Access the most used venture tools.</p>
    </div>

    <div class="qa-grid">
      <a href="documents.php?action=upload" class="qa-btn"><i class="fa fa-cloud-upload-alt"></i>Upload Doc</a>
      <a href="profile.php" class="qa-btn"><i class="fa fa-edit"></i>Edit Profile</a>
      <a href="team.php?action=add" class="qa-btn"><i class="fa fa-user-plus"></i>Add Team Member</a>
      <a href="investors.php" class="qa-btn"><i class="fa fa-user-tie"></i>View Investors</a>
      <a href="events.php" class="qa-btn"><i class="fa fa-calendar-check"></i>Browse Events</a>
      <a href="messages.php" class="qa-btn"><i class="fa fa-comment-dots"></i>Messages</a>
      <a href="survey.php" class="qa-btn"><i class="fa fa-poll-h"></i>Surveys</a>
    </div>
  </div>
</div>

<div class="dashboard-tab-content" id="tab-profile">
  <div class="tab-panel">
    <div class="tab-panel-header panel-between">
      <div>
        <h3>Profile Completeness</h3>
        <p>Complete your profile to increase investor visibility.</p>
      </div>
      <span class="badge <?= $profile_pct >= 80 ? 'badge-success' : ($profile_pct >= 50 ? 'badge-warning' : 'badge-danger') ?>">
        <?= (int)$profile_pct ?>%
      </span>
    </div>

    <div class="profile-bar-wrap">
      <div class="profile-bar-fill" style="width:<?= (int)$profile_pct ?>%"></div>
    </div>

    <div class="completeness-items">
      <?php foreach ($profile_checks as $item): ?>
        <span class="comp-item <?= $item['done'] ? 'done' : 'miss' ?>">
          <i class="fa <?= $item['done'] ? 'fa-check-circle' : 'fa-circle' ?>"></i>
          <?= h($item['label']) ?>
        </span>
      <?php endforeach; ?>
    </div>

    <?php if ($profile_pct < 100): ?>
      <a href="profile.php" class="btn btn-outline btn-sm mt-14">
        <i class="fa fa-edit"></i> Complete Profile
      </a>
    <?php endif; ?>
  </div>
</div>

<div class="dashboard-tab-content" id="tab-documents">
  <div class="tab-panel">
    <div class="tab-panel-header panel-between">
      <div>
        <h3>Recent Documents</h3>
        <p>Your latest uploaded venture documents.</p>
      </div>
      <a href="documents.php" class="btn btn-outline btn-sm"><i class="fa fa-arrow-right"></i> All</a>
    </div>

    <?php if (!empty($recent_docs)): ?>
      <?php foreach ($recent_docs as $doc): ?>
        <div class="doc-row">
          <div class="doc-row-icon" style="background:<?= h($doc_colors[$doc['category']] ?? '#6b7280') ?>">
            <i class="fa <?= h($doc_icons[$doc['category']] ?? 'fa-file-alt') ?>"></i>
          </div>

          <div class="row-flex">
            <div class="doc-row-name"><?= h($doc['doc_name']) ?></div>
            <div class="doc-row-meta">
              <?= h($doc_categories[$doc['category']] ?? $doc['category']) ?>
              · <?= !empty($doc['uploaded_at']) ? date('M j, Y', strtotime($doc['uploaded_at'])) : '-' ?>
            </div>
          </div>

          <a href="includes/portal-process.php?action=download_doc&id=<?= (int)$doc['id'] ?>" class="btn btn-outline btn-sm">
            <i class="fa fa-download"></i>
          </a>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="empty-state">
        <i class="fa fa-folder-open"></i>
        <p>No documents yet.</p>
        <a href="documents.php?action=upload">Upload your first document.</a>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="dashboard-tab-content" id="tab-events">
  <div class="tab-panel">
    <div class="tab-panel-header panel-between">
      <div>
        <h3>Upcoming Events</h3>
        <p>Events you have registered for.</p>
      </div>
      <a href="events.php" class="btn btn-outline btn-sm"><i class="fa fa-arrow-right"></i> All</a>
    </div>

    <?php if (!empty($upcoming_events)): ?>
      <?php foreach ($upcoming_events as $ev): ?>
        <?php $dt = new DateTime($ev['event_date']); ?>
        <div class="ev-row">
          <div class="ev-date-box">
            <span class="d"><?= $dt->format('j') ?></span>
            <span class="m"><?= $dt->format('M') ?></span>
          </div>

          <div>
            <div class="ev-title"><?= h($ev['title']) ?></div>
            <div class="ev-meta">
              <?= $dt->format('g:i A') ?> · <?= h($ev['location'] ?? 'Online') ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="empty-state">
        <i class="fa fa-calendar-alt"></i>
        <p>No upcoming events.</p>
        <a href="events.php">Browse all events.</a>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="dashboard-tab-content" id="tab-investors">
  <div class="tab-panel">
    <div class="tab-panel-header panel-between">
      <div>
        <h3>Investor Connections</h3>
        <p>Your current investor pipeline.</p>
      </div>
      <a href="investors.php" class="btn btn-outline btn-sm"><i class="fa fa-arrow-right"></i> All</a>
    </div>

    <?php if (!empty($investor_pipe)): ?>
      <?php foreach ($investor_pipe as $inv): ?>
        <div class="inv-row">
          <div>
            <div class="inv-name">
              <?= h($inv['inv_name']) ?>
              <?php if (!empty($inv['firm_name'])): ?>
                <small>— <?= h($inv['firm_name']) ?></small>
              <?php endif; ?>
            </div>
            <div class="inv-meta"><?= h(ucfirst($inv['investor_type'] ?? '-')) ?></div>
          </div>

          <span class="badge <?= h($ms_badge[$inv['status']] ?? 'badge-muted') ?>">
            <?= h($match_statuses[$inv['status']] ?? $inv['status']) ?>
          </span>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="empty-state">
        <i class="fa fa-handshake"></i>
        <p>No investor connections yet.</p>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="dashboard-tab-content" id="tab-surveys">
  <div class="tab-panel">
    <div class="tab-panel-header panel-between">
      <div>
        <h3>Surveys</h3>
        <p>Pending survey requests and your past responses.</p>
      </div>
      <a href="survey.php" class="btn btn-outline btn-sm"><i class="fa fa-arrow-right"></i> All</a>
    </div>

    <div class="survey-subnav">
      <button type="button" class="survey-subtab active" data-survey-tab="pending">
        Pending
        <?php if (!empty($pending_surveys)): ?>
          <span class="survey-subtab-count"><?= count($pending_surveys) ?></span>
        <?php endif; ?>
      </button>
      <button type="button" class="survey-subtab" data-survey-tab="completed">
        Completed
        <?php if (!empty($completed_surveys)): ?>
          <span class="survey-subtab-count"><?= count($completed_surveys) ?></span>
        <?php endif; ?>
      </button>
    </div>

    <div class="survey-subpanel active" id="survey-pending">
      <?php if (!empty($pending_surveys)): ?>
        <?php foreach ($pending_surveys as $sv): ?>
          <?php
            $due = !empty($sv['ends_at']) ? strtotime((string)$sv['ends_at']) : null;
            $is_overdue = $due && $due < time();
          ?>
          <div class="survey-row">
            <div class="survey-row-icon" style="background:<?= h($sv['theme_color'] ?? '#0f172a') ?>">
              <i class="fa fa-poll-h"></i>
            </div>

            <div class="row-flex">
              <div class="survey-row-name"><?= h($sv['title']) ?></div>
              <div class="survey-row-meta">
                <?php if (!empty($sv['question_count'])): ?>
                  <?= (int)$sv['question_count'] ?> question<?= (int)$sv['question_count'] === 1 ? '' : 's' ?>
                <?php endif; ?>
                <?php if ($due): ?>
                  · <?= $is_overdue ? 'Closes soon' : ('Due ' . date('M j, Y', $due)) ?>
                <?php endif; ?>
              </div>
            </div>

            <span class="badge <?= $is_overdue ? 'badge-danger' : 'badge-warning' ?>">
              Pending
            </span>

            <a href="survey.php?t=<?= h($sv['external_token']) ?>" class="btn btn-primary btn-sm">
              <i class="fa fa-arrow-right"></i> Start
            </a>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty-state">
          <i class="fa fa-poll-h"></i>
          <p>No pending surveys.</p>
          <p class="empty-state-sub">You're all caught up — new surveys will show up here.</p>
        </div>
      <?php endif; ?>
    </div>

    <div class="survey-subpanel" id="survey-completed">
      <?php if (!empty($completed_surveys)): ?>
        <?php foreach ($completed_surveys as $sv): ?>
          <div class="survey-row">
            <div class="survey-row-icon survey-row-icon-done">
              <i class="fa fa-check"></i>
            </div>

            <div class="row-flex">
              <div class="survey-row-name"><?= h($sv['title']) ?></div>
              <div class="survey-row-meta">
                Submitted <?= !empty($sv['submitted_at']) ? date('M j, Y', strtotime($sv['submitted_at'])) : '-' ?>
              </div>
            </div>

            <span class="badge badge-success">Completed</span>

            <?php if (!empty($sv['allow_multiple_responses'])): ?>
              <a href="survey.php?t=<?= h($sv['external_token']) ?>" class="btn btn-outline btn-sm">
                <i class="fa fa-redo"></i> Retake
              </a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty-state">
          <i class="fa fa-clipboard-check"></i>
          <p>No completed surveys yet.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

</div>
</div>

<script>
document.querySelectorAll('.dashboard-tab').forEach(btn => {
  btn.addEventListener('click', () => {
    const tab = btn.dataset.tab;

    document.querySelectorAll('.dashboard-tab').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.dashboard-tab-content').forEach(c => c.classList.remove('active'));

    btn.classList.add('active');
    document.getElementById('tab-' + tab).classList.add('active');
  });
});

document.querySelectorAll('.survey-subtab').forEach(btn => {
  btn.addEventListener('click', () => {
    const target = btn.dataset.surveyTab;

    document.querySelectorAll('.survey-subtab').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.survey-subpanel').forEach(p => p.classList.remove('active'));

    btn.classList.add('active');
    document.getElementById('survey-' + target).classList.add('active');
  });
});

function toggleSidebar(){
  const sidebar = document.getElementById('vpSidebar');
  if (sidebar) sidebar.classList.toggle('open');
}

document.addEventListener('click', e => {
  const sb = document.getElementById('vpSidebar');
  if (window.innerWidth <= 900 && sb && sb.classList.contains('open') && !sb.contains(e.target)) {
    sb.classList.remove('open');
  }
});
</script>

</body>
</html>