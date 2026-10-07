<?php
require_once 'includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection failed.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('truncate')) {
    function truncate(string $text, int $limit = 90): string {
        $text = trim(strip_tags($text));
        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit) . '...';
    }
}

function cohort_asset_url(string $path): string
{
    $path = trim($path);
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;

    return rtrim(SITE_URL, '/') . '/' . ltrim($path, '/');
}

function cohort_asset_exists(string $path): bool
{
    $path = trim($path);
    if ($path === '' || preg_match('#^https?://#i', $path)) return false;

    return is_file(__DIR__ . '/' . ltrim($path, '/'));
}

function table_has_column(mysqli $conn, string $table, string $column): bool
{
    $table  = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);

    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

$searchFilter   = trim($_GET['search'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$levelFilter    = trim($_GET['level'] ?? '');

$categoryColumn = table_has_column($conn, 'ventures', 'category') ? 'category'
    : (table_has_column($conn, 'ventures', 'sector') ? 'sector' : '');

$levelColumn = table_has_column($conn, 'ventures', 'startup_level') ? 'startup_level'
    : (table_has_column($conn, 'ventures', 'venture_level') ? 'venture_level'
    : (table_has_column($conn, 'ventures', 'stage') ? 'stage' : ''));

$categories = [];
if ($categoryColumn !== '') {
    $res = $conn->query("
        SELECT DISTINCT `$categoryColumn` AS item
        FROM ventures
        WHERE status = 'active'
          AND `$categoryColumn` IS NOT NULL
          AND `$categoryColumn` != ''
        ORDER BY `$categoryColumn` ASC
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $categories[] = $row['item'];
        }
    }
}

$levels = [];
if ($levelColumn !== '') {
    $res = $conn->query("
        SELECT DISTINCT `$levelColumn` AS item
        FROM ventures
        WHERE status = 'active'
          AND `$levelColumn` IS NOT NULL
          AND `$levelColumn` != ''
        ORDER BY `$levelColumn` ASC
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $levels[] = $row['item'];
        }
    }
}

$cohorts = $conn->query("
    SELECT *
    FROM cohorts
    WHERE status = 'active'
    ORDER BY sort_order ASC, id DESC
");

include 'includes/header.php';
?>

<div class="cohorts-page">

    <section class="cohorts-hero">
        <div class="container">
            <span class="section-tag">
                <i class="fa fa-layer-group"></i> Fellowship Cohorts
            </span>

            <h1>Meet Our Ventures</h1>

            <p>
                Meet the growth-stage EdTech ventures selected for the Mastercard Foundation EdTech Fellowship Uganda.
            </p>

            <div class="cohorts-hero-actions">
                <a href="index.php#eligibility" class="btn btn-primary">
                    <i class="fa fa-check-circle"></i> Eligibility Criteria
                </a>

                <a href="index.php#contact" class="btn btn-outline cohorts-outline-btn">
                    <i class="fa fa-phone"></i> Contact Us
                </a>
            </div>
        </div>
    </section>

    <section class="cohorts-list-section">
        <div class="container">

            <form method="GET" class="ventures-filter-bar">
                <div class="venture-filter-group">
                    <label>Search</label>
                    <input type="text"
                           name="search"
                           value="<?= h($searchFilter) ?>"
                           placeholder="Search venture name, location, description...">
                </div>

                <div class="venture-filter-group">
                    <label>Category</label>
                    <select name="category">
                        <option value="">- Any -</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= h($cat) ?>" <?= $categoryFilter === $cat ? 'selected' : '' ?>>
                                <?= h($cat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="venture-filter-group">
                    <label>Venture Level</label>
                    <select name="level">
                        <option value="">- Any -</option>
                        <?php foreach ($levels as $lvl): ?>
                            <option value="<?= h($lvl) ?>" <?= $levelFilter === $lvl ? 'selected' : '' ?>>
                                <?= h($lvl) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="venture-filter-actions">
                    <button type="submit" title="Filter">
                        <i class="fa fa-search"></i>
                    </button>

                    <a href="cohorts.php" title="Clear filters">
                        <i class="fa fa-trash"></i>
                    </a>
                </div>
            </form>

            <?php
            $has_cohorts = false;
            $has_any_ventures = false;

            if ($cohorts):
                while ($cohort = $cohorts->fetch_assoc()):
                    $cohort_id = (int)$cohort['id'];

                    $where  = ["cohort_id = ?", "status = 'active'"];
                    $types  = 'i';
                    $params = [$cohort_id];

                    if ($searchFilter !== '') {
                        $where[] = "(
                            name LIKE ?
                            OR tagline LIKE ?
                            OR description LIKE ?
                            OR location LIKE ?
                            OR country LIKE ?
                        )";

                        $like = '%' . $searchFilter . '%';
                        $types .= 'sssss';
                        array_push($params, $like, $like, $like, $like, $like);
                    }

                    if ($categoryFilter !== '' && $categoryColumn !== '') {
                        $where[] = "`$categoryColumn` = ?";
                        $types .= 's';
                        $params[] = $categoryFilter;
                    }

                    if ($levelFilter !== '' && $levelColumn !== '') {
                        $where[] = "`$levelColumn` = ?";
                        $types .= 's';
                        $params[] = $levelFilter;
                    }

                    $sql = "
                        SELECT *
                        FROM ventures
                        WHERE " . implode(' AND ', $where) . "
                        ORDER BY sort_order ASC, name ASC
                    ";

                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                    $ventures = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();

                    if (empty($ventures) && ($searchFilter !== '' || $categoryFilter !== '' || $levelFilter !== '')) {
                        continue;
                    }

                    $has_cohorts = true;
                    if (!empty($ventures)) $has_any_ventures = true;

                    $application_status = $cohort['application_status'] ?? 'closed';
                    $status_class = $application_status === 'open'
                        ? 'app-open'
                        : ($application_status === 'coming_soon' ? 'app-soon' : 'app-closed');
            ?>

            <article id="cohort-<?= $cohort_id ?>" class="cohort-full-block">

                <?php if (!empty($cohort['banner_image']) && cohort_asset_exists($cohort['banner_image'])): ?>
                    <div class="cohort-banner"
                         style="background-image:url('<?= h(cohort_asset_url($cohort['banner_image'])) ?>')">
                    </div>
                <?php endif; ?>

                <div class="cohort-full-header">
                    <div class="cohort-header-content">
                        <div class="cohort-meta-left">

                            <?php if (!empty($cohort['image']) && cohort_asset_exists($cohort['image'])): ?>
                                <img src="<?= h(cohort_asset_url($cohort['image'])) ?>"
                                     alt="<?= h($cohort['name']) ?>"
                                     class="cohort-logo-lg">
                            <?php else: ?>
                                <div class="cohort-logo-placeholder">
                                    <?= h(strtoupper(substr((string)$cohort['name'], 0, 2))) ?>
                                </div>
                            <?php endif; ?>

                            <div>
                                <h2><?= h($cohort['name']) ?></h2>

                                <?php if (!empty($cohort['tagline'])): ?>
                                    <p class="cohort-tagline"><?= h($cohort['tagline']) ?></p>
                                <?php endif; ?>

                                <?php if (!empty($cohort['start_date']) || !empty($cohort['end_date'])): ?>
                                    <p class="cohort-dates">
                                        <i class="fa fa-calendar"></i>
                                        <?= !empty($cohort['start_date']) ? h(date('M Y', strtotime($cohort['start_date']))) : '' ?>
                                        <?= (!empty($cohort['start_date']) && !empty($cohort['end_date'])) ? ' -' : '' ?>
                                        <?= !empty($cohort['end_date']) ? h(date('M Y', strtotime($cohort['end_date']))) : '' ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($cohort['description'])): ?>
                        <div class="cohort-description-text">
                            <p><?= h($cohort['description']) ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($ventures)): ?>
                    <div class="ventures-section">
                        <div class="ventures-section-header">
                            <h3>
                               
                                Ventures
                                <span class="count-badge"><?= count($ventures) ?></span>
                            </h3>
                        </div>

                        <div class="ventures-full-grid">
                            <?php foreach ($ventures as $venture): ?>
                                <?php
                                $venture_name = (string)($venture['name'] ?? 'Venture');
                                $venture_slug = (string)($venture['slug'] ?? '');
                                $venture_url = $venture_slug !== ''
                                    ? SITE_URL . '/venture.php?slug=' . urlencode($venture_slug)
                                    : '#';

                                $logo = trim((string)($venture['logo'] ?? ''));
                                $featured_image = trim((string)($venture['featured_image'] ?? ''));

                                $tagline = trim((string)($venture['tagline'] ?? ''));
                                $description = trim((string)($venture['description'] ?? ''));

                                $categoryText = $categoryColumn !== '' ? trim((string)($venture[$categoryColumn] ?? '')) : '';
                                $levelText    = $levelColumn !== '' ? trim((string)($venture[$levelColumn] ?? '')) : '';
                                ?>

                                <a href="<?= h($venture_url) ?>" class="startup-full-card">
                                    <!--<div class="startup-card-top">
                                        <div class="startup-logo-wrap">
                                            <?php if ($logo !== '' && cohort_asset_exists($logo)): ?>
                                                <img src="<?= h(cohort_asset_url($logo)) ?>" alt="<?= h($venture_name) ?>">
                                            <?php else: ?>
                                                <div class="startup-logo-initial">
                                                    <?= h(strtoupper(substr($venture_name, 0, 1))) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div> -->

                                    <?php if ($featured_image !== '' && cohort_asset_exists($featured_image)): ?>
                                        <div class="startup-feat-img">
                                            <img src="<?= h(cohort_asset_url($featured_image)) ?>" alt="<?= h($venture_name) ?>">
                                        </div>
                                    <?php endif; ?> 

                                    <div class="startup-card-body">
                                        <h4><?= h($venture_name) ?></h4>

                                        <p><?= h(truncate($tagline !== '' ? $tagline : $description, 95)) ?></p>

                                        <?php if ($categoryText !== '' || $levelText !== ''): ?>
                                            <div class="venture-card-tags">
                                                <?php if ($categoryText !== ''): ?>
                                                    <span><i class="fa fa-tag"></i> <?= h($categoryText) ?></span>
                                                <?php endif; ?>

                                              <!--  <?php if ($levelText !== ''): ?>
                                                    <span><i class="fa fa-signal"></i> <?= h($levelText) ?></span>
                                                <?php endif; ?> -->
                                            </div>
                                        <?php endif; ?>

                                        <div class="startup-card-footer">
                                            <span>
                                                <i class="fa fa-map-marker-alt"></i>
                                                <?= h($venture['location'] ?: ($venture['country'] ?: 'Uganda')) ?>
                                            </span>

                                            <span class="view-link">
                                                View Details <i class="fa fa-arrow-right"></i>
                                            </span>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="no-ventures-card">
                        <i class="fa fa-rocket"></i>
                        <h3>Ventures Coming Soon</h3>
                        <p>Fellowship ventures for <?= h($cohort['name']) ?> will be announced soon.</p>
                    </div>
                <?php endif; ?>

            </article>

            <?php
                endwhile;
            endif;
            ?>

            <?php if (!$has_cohorts): ?>
                <div class="empty-cohorts">
                    <i class="fa fa-search"></i>
                    <h2>No Ventures Found</h2>
                    <p>No ventures match your current search filters.</p>
                    <a href="cohorts.php" class="btn btn-primary">Clear Filters</a>
                </div>
            <?php endif; ?>

        </div>
    </section>

</div>

<?php include 'includes/footer.php'; ?>