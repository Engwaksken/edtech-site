<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['venture_id'])) {
    header('Location: login.php');
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection error.');
}
$conn->set_charset('utf8mb4');

$page_title  = 'Mentors';
$current_nav = 'mentors.php';
$venture_id  = (int)$_SESSION['venture_id'];

function mentors_h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function mentor_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $first = $parts[0] ?? '';
    $last  = $parts[1] ?? '';
    return strtoupper(substr($first, 0, 1) . substr($last !== '' ? $last : $first, 0, 1));
}

function mentor_short_text($text, int $limit = 120): string
{
    $text = trim(strip_tags((string)$text));
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $limit, '...', 'UTF-8');
    }
    return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
}

/* Load logged-in venture first. This fixes: Undefined variable $venture_id / $VENTURE. */
$VENTURE = [];
$venture_stmt = $conn->prepare('SELECT * FROM ventures WHERE id = ? LIMIT 1');
$venture_stmt->bind_param('i', $venture_id);
$venture_stmt->execute();
$VENTURE = $venture_stmt->get_result()->fetch_assoc() ?: [];
$venture_stmt->close();

if (!$VENTURE) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$cohort_id = (int)($VENTURE['cohort_id'] ?? 0);

$expertise_opts = [
    'fundraising' => 'Fundraising',
    'product'     => 'Product',
    'marketing'   => 'Marketing',
    'tech'        => 'Technology',
    'ops'         => 'Operations',
    'legal'       => 'Legal',
    'finance'     => 'Finance',
    'sales'       => 'Sales',
    'strategy'    => 'Strategy',
    'impact'      => 'Impact',
    'design'      => 'Design',
    'hr'          => 'HR',
    'growth'      => 'Growth Hacking',
];

$platform_opts = [
    'zoom'         => 'Zoom',
    'google_meet' => 'Google Meet',
    'teams'       => 'MS Teams',
    'phone'       => 'Phone',
    'in_person'   => 'In Person',
    'other'       => 'Other',
];

/* Assigned mentors */
$mentors = [];
$mentors_stmt = $conn->prepare("\n    SELECT DISTINCT\n        m.*,\n        (\n            SELECT COUNT(*)\n            FROM mentor_sessions ms\n            WHERE ms.mentor_id = m.id\n              AND ms.venture_id = ?\n              AND ms.status = 'completed'\n        ) AS sessions_with_us,\n        (\n            SELECT ROUND(AVG(ms.venture_rating), 1)\n            FROM mentor_sessions ms\n            WHERE ms.mentor_id = m.id\n              AND ms.venture_rating IS NOT NULL\n        ) AS avg_rating\n    FROM mentors m\n    INNER JOIN mentor_assignments ma ON ma.mentor_id = m.id\n    WHERE m.status = 'active'\n      AND (ma.venture_id = ? OR ma.cohort_id = ?)\n    ORDER BY m.is_featured DESC, m.full_name ASC\n");
$mentors_stmt->bind_param('iii', $venture_id, $venture_id, $cohort_id);
$mentors_stmt->execute();
$mentors_rs = $mentors_stmt->get_result();
while ($row = $mentors_rs->fetch_assoc()) {
    $mentors[] = $row;
}
$mentors_stmt->close();

/* Active sessions with each mentor */
$active_sessions = [];
$active_stmt = $conn->prepare("\n    SELECT mentor_id, COUNT(*) AS cnt\n    FROM mentor_sessions\n    WHERE venture_id = ?\n      AND status IN ('requested', 'scheduled', 'confirmed')\n    GROUP BY mentor_id\n");
$active_stmt->bind_param('i', $venture_id);
$active_stmt->execute();
$active_rs = $active_stmt->get_result();
while ($r = $active_rs->fetch_assoc()) {
    $active_sessions[(int)$r['mentor_id']] = (int)$r['cnt'];
}
$active_stmt->close();

include 'layout.php';
?>

<div class="mentor-page-head">
    <h2>Your Mentors</h2>
    <p>Mentors assigned to your cohort and venture. Request sessions directly.</p>
</div>

<?php if (count($mentors) === 0): ?>
    <div class="mentor-empty-state">
        <i class="fa fa-chalkboard-teacher"></i>
        <h3>No mentors assigned yet</h3>
        <p>The programme team will assign mentors to your cohort soon.</p>
    </div>
<?php else: ?>
    <div class="mentor-grid">
        <?php foreach ($mentors as $m): ?>
            <?php
                $mentor_id = (int)($m['id'] ?? 0);
                $mentor_name = (string)($m['full_name'] ?? 'Mentor');
                $photo = trim((string)($m['photo'] ?? ''));
                $avg_rating = (float)($m['avg_rating'] ?? 0);
                $expertise = array_filter(array_map('trim', explode(',', (string)($m['expertise'] ?? ''))));
            ?>
            <div class="mentor-card">
                <div class="mentor-card-hero"></div>

                <div class="mentor-card-body">
                    <div class="mentor-avatar-wrap">
                        <?php if ($photo !== ''): ?>
                            <img src="<?= mentors_h(rtrim(SITE_URL, '/') . '/' . ltrim($photo, '/')) ?>" class="m-photo" alt="<?= mentors_h($mentor_name) ?>">
                        <?php else: ?>
                            <div class="m-initials"><?= mentors_h(mentor_initials($mentor_name)) ?></div>
                        <?php endif; ?>

                        <div class="mentor-main-info">
                            <div class="m-name">
                                <?= mentors_h($mentor_name) ?>
                                <?php if (!empty($m['is_featured'])): ?>
                                    <i class="fa fa-star m-featured-star"></i>
                                <?php endif; ?>
                            </div>

                            <div class="m-role">
                                <?= mentors_h($m['job_title'] ?? '') ?><?= !empty($m['organisation']) ? ' . ' . mentors_h($m['organisation']) : '' ?>
                            </div>

                            <?php if ($avg_rating > 0): ?>
                                <div class="star-row">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fa <?= $i <= round($avg_rating) ? 'fa-star' : 'fa-star-o' ?>"></i>
                                    <?php endfor; ?>
                                    <span><?= mentors_h(number_format($avg_rating, 1)) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($m['bio'])): ?>
                        <p class="m-bio"><?= mentors_h(mentor_short_text($m['bio'], 120)) ?></p>
                    <?php endif; ?>

                    <?php if ($expertise): ?>
                        <div class="m-tags">
                            <?php foreach (array_slice($expertise, 0, 5) as $e): ?>
                                <span class="m-tag"><?= mentors_h($expertise_opts[$e] ?? $e) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($m['location']) || !empty($m['linkedin_url'])): ?>
                        <div class="mentor-links-row">
                            <?php if (!empty($m['location'])): ?>
                                <span><i class="fa fa-map-marker-alt"></i> <?= mentors_h($m['location']) ?></span>
                            <?php endif; ?>

                            <?php if (!empty($m['linkedin_url'])): ?>
                                <a href="<?= mentors_h($m['linkedin_url']) ?>" target="_blank" rel="noopener">
                                    <i class="fa fa-linkedin"></i> LinkedIn
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="m-stats">
                    <div class="m-stat">
                        <div class="m-stat-num"><?= (int)($m['sessions_with_us'] ?? 0) ?></div>
                        <div class="m-stat-lbl">Sessions</div>
                    </div>
                    <div class="m-stat">
                        <div class="m-stat-num"><?= (int)($m['weekly_hours'] ?? 0) ?>h</div>
                        <div class="m-stat-lbl">Weekly</div>
                    </div>
                    <?php if (isset($active_sessions[$mentor_id])): ?>
                        <div class="m-stat">
                            <span class="pending-badge"><i class="fa fa-clock"></i> <?= $active_sessions[$mentor_id] ?> pending</span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="m-card-footer">
                    <button class="btn btn-primary mentor-request-btn"
                            type="button"
                            data-mentor-id="<?= $mentor_id ?>"
                            data-mentor-name="<?= mentors_h($mentor_name) ?>">
                        <i class="fa fa-calendar-plus"></i> Request Session
                    </button>
                    <a href="messages.php?mentor_id=<?= $mentor_id ?>" class="btn btn-outline">
                        <i class="fa fa-comment-dots"></i>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="vp-modal-overlay" id="requestModal">
    <div class="vp-modal">
        <div class="vp-modal-header">
            <div class="vp-modal-title" id="reqModalTitle">Request a Session</div>
            <button type="button" class="vp-modal-close" onclick="closeRequestModal()">&times;</button>
        </div>

        <form method="POST" action="includes/portal-process.php">
            <input type="hidden" name="action" value="request_session">
            <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
            <input type="hidden" name="mentor_id" id="req_mentor_id">

            <div class="vp-modal-body">
                <div class="mentor-form-stack">
                    <div class="form-group">
                        <label class="form-label">Session Title <span class="req">*</span></label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Fundraising Strategy Review" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">What would you like to discuss? <span class="req">*</span></label>
                        <textarea name="description" class="form-control" rows="4" placeholder="Briefly describe your goals for this session, questions you have, or challenges you're facing..." required></textarea>
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
                            <?php foreach ($platform_opts as $k => $v): ?>
                                <option value="<?= mentors_h($k) ?>"><?= mentors_h($v) ?></option>
                            <?php endforeach; ?>
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
function toggleSidebar() {
    const sidebar = document.getElementById('vpSidebar');
    if (sidebar) sidebar.classList.toggle('open');
}

const reqM = document.getElementById('requestModal');
const reqMentorInput = document.getElementById('req_mentor_id');
const reqModalTitle = document.getElementById('reqModalTitle');

function openRequestModal(id, name) {
    reqMentorInput.value = id || '';
    reqModalTitle.textContent = name ? 'Request Session with ' + name : 'Request a Session';
    reqM.classList.add('open');
}

function closeRequestModal() {
    reqM.classList.remove('open');
}

document.querySelectorAll('.mentor-request-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
        openRequestModal(btn.dataset.mentorId, btn.dataset.mentorName);
    });
});

if (reqM) {
    reqM.addEventListener('click', (e) => {
        if (e.target === reqM) closeRequestModal();
    });
}
</script>
