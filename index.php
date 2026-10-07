<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

mysqli_set_charset($conn, 'utf8mb4');


if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('truncate')) {
    function truncate(?string $text, int $limit = 120, string $suffix = '...'): string
    {
        $text = trim((string)$text);
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit) . $suffix;
        }

        return strlen($text) <= $limit ? $text : substr($text, 0, $limit) . $suffix;
    }
}

if (!function_exists('site_base_url')) {
    function site_base_url(): string
    {
        if (defined('SITE_URL') && trim((string)SITE_URL) !== '') {
            return rtrim((string)SITE_URL, '/');
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443);
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host;
    }
}

if (!function_exists('asset_url')) {
    function asset_url(?string $path, string $fallback = ''): string
    {
        $path = trim((string)$path);

        if ($path === '') {
            return $fallback !== '' ? asset_url($fallback) : '';
        }

        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?: $path;
        $path = preg_replace('#^(\./|\.\./)+#', '', $path) ?: $path;
        $path = ltrim($path, '/');

        return site_base_url() . '/' . $path;
    }
}

if (!function_exists('img_src')) {
    function img_src(?string $path, string $fallback = 'assets/images/placeholder.png'): string
    {
        return asset_url($path, $fallback);
    }
}

if (!function_exists('home_query')) {
    function home_query(mysqli $db, string $sql): mysqli_result|false
    {
        return mysqli_query($db, $sql);
    }
}

if (!function_exists('home_rows')) {
    function home_rows(mysqli_result|false $result): array
    {
        $rows = [];

        if ($result instanceof mysqli_result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $rows[] = $row;
            }
            mysqli_free_result($result);
        }

        return $rows;
    }
}

if (!function_exists('home_setting')) {
    function home_setting(array $settings, string $key, string $default = ''): string
    {
        $value = trim((string)($settings[$key] ?? ''));
        return $value !== '' ? $value : $default;
    }
}

if (!function_exists('section_bg')) {
    function section_bg(array $settings, string $prefix): array
    {
        $type = strtolower(home_setting($settings, $prefix . '_bg_type', 'color'));
        if (!in_array($type, ['none', 'color', 'image', 'video'], true)) {
            $type = 'color';
        }

        $color = home_setting($settings, $prefix . '_bg_color', '#ffffff');

     
        $legacy_value = home_setting($settings, $prefix . '_bg_value', '');
        $image = home_setting($settings, $prefix . '_bg_image', '');
        $video = home_setting($settings, $prefix . '_bg_video', '');

        if ($image === '' && $type === 'image') {
            $image = $legacy_value;
        }

        if ($video === '' && $type === 'video') {
            $video = $legacy_value;
        }

        $overlay_enabled = home_setting($settings, $prefix . '_bg_overlay', '0');
        $overlay_color = home_setting($settings, $prefix . '_bg_overlay_color', '#000000');
        $overlay_opacity_raw = home_setting($settings, $prefix . '_bg_overlay_opacity', '');

     
        $overlay = 0.0;

        if ($overlay_opacity_raw !== '') {
            $overlay = max(0, min(100, (float)$overlay_opacity_raw)) / 100;
        } else {
            $overlay_num = (float)$overlay_enabled;
            $overlay = $overlay_num > 1 ? max(0, min(100, $overlay_num)) / 100 : max(0, min(1, $overlay_num));
        }

        if ($overlay_enabled !== '1' && $overlay_opacity_raw !== '' && $type !== 'image' && $type !== 'video') {
            $overlay = 0.0;
        }

        if ($overlay_enabled !== '1' && $overlay_opacity_raw !== '' && ($type === 'image' || $type === 'video')) {
            $overlay = 0.0;
        }

        $style = '';

        if ($type === 'color') {
            $style = "background:{$color};";
        } elseif ($type === 'image' && $image !== '') {
            $style = "background-image:url('" . h(asset_url($image)) . "');background-size:cover;background-position:center;background-repeat:no-repeat;";
        }

        return [
            'type' => $type,
            'value' => $legacy_value,
            'color' => $color,
            'image' => $image,
            'video' => $video,
            'style' => $style,
            'overlay' => $overlay,
            'overlay_color' => $overlay_color,
            'overlay_enabled' => $overlay_enabled === '1' || $overlay > 0,
        ];
    }
}

if (!function_exists('render_section_background_layers')) {
    function render_section_background_layers(array $bg): void
    {
        $type = (string)($bg['type'] ?? 'color');

        if ($type === 'video' && trim((string)($bg['video'] ?? '')) !== '') {
            echo '<div class="section-video-bg">';
            echo '<video autoplay muted loop playsinline>';
            echo '<source src="' . h(asset_url((string)$bg['video'])) . '" type="video/mp4">';
            echo '</video>';
            echo '</div>';
        }

        if (($type === 'image' || $type === 'video') && (float)($bg['overlay'] ?? 0) > 0) {
            $overlay_color = h((string)($bg['overlay_color'] ?? '#000000'));
            $overlay_opacity = h((string)max(0, min(1, (float)($bg['overlay'] ?? 0))));
            echo '<div class="section-custom-overlay" style="background:' . $overlay_color . ';opacity:' . $overlay_opacity . ';"></div>';
        }
    }
}

if (!function_exists('safe_icon')) {
    function safe_icon(?string $icon, string $fallback = 'fa-check-circle'): string
    {
        $icon = trim((string)$icon);
        if ($icon === '') {
            return $fallback;
        }

        return preg_match('/^[a-z0-9\-\s]+$/i', $icon) ? $icon : $fallback;
    }
}


$settings = [];
$settingsRows = home_rows(home_query($conn, "SELECT setting_key, setting_value FROM site_settings"));
foreach ($settingsRows as $row) {
    $settings[(string)$row['setting_key']] = (string)$row['setting_value'];
}


$hero_slides = [];
$slides_stmt = $conn->prepare("
    SELECT *
    FROM slides
    WHERE page_name = 'home'
      AND status = 1
    ORDER BY sort_order ASC, id ASC
");

if ($slides_stmt) {
    $slides_stmt->execute();
    $result = $slides_stmt->get_result();
    if ($result instanceof mysqli_result) {
        $hero_slides = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }
    $slides_stmt->close();
}

if (empty($hero_slides)) {
    $hero_slides = [[
        'id'              => 0,
        'media_type'      => home_setting($settings, 'hero_bg_type', 'image'),
        'image_path'      => home_setting($settings, 'hero_image', 'assets/images/edtech-hero.jpg'),
        'video_path'      => home_setting($settings, 'hero_video_path', ''),
        'video_url'       => home_setting($settings, 'hero_video_url', ''),
        'bg_color'        => home_setting($settings, 'hero_bg_color', ''),
        'overlay_opacity' => (float)home_setting($settings, 'hero_overlay', '0.55'),
        'title'           => home_setting($settings, 'hero_title', 'Mastercard Foundation EdTech Fellowship'),
        'subtitle'        => home_setting($settings, 'hero_subtitle', ''),
        'description'     => home_setting($settings, 'hero_description', ''),
        'button_text'     => home_setting($settings, 'hero_btn_text', ''),
        'button_link'     => home_setting($settings, 'hero_application_link', ''),
        'button2_text'    => home_setting($settings, 'hero_btn2_text', ''),
        'button2_link'    => home_setting($settings, 'hero_btn2_link', ''),
        'label'           => home_setting($settings, 'hero_label', ''),
    ]];
}


$benefits = home_rows(home_query($conn, "SELECT * FROM benefits WHERE status = 1 ORDER BY sort_order ASC, id ASC"));
$stages = home_rows(home_query($conn, "SELECT * FROM program_stages WHERE status = 1 ORDER BY sort_order ASC, id ASC"));
$eligibility = home_rows(home_query($conn, "SELECT * FROM eligibility_criteria WHERE status = 1 ORDER BY sort_order ASC, id ASC"));
$impact_stats = home_rows(home_query($conn, "SELECT * FROM impact_stats WHERE status = 1 ORDER BY sort_order ASC, id ASC"));
$partner_rows = home_rows(home_query($conn, "SELECT * FROM partners WHERE status = 1 AND logo <> '' ORDER BY sort_order ASC, id ASC"));

$bento_items = !empty($benefits) ? $benefits : [
    ['icon' => 'fa-heart', 'title' => 'Inclusive product and delivery design', 'image' => ''],
    ['icon' => 'fa-puzzle-piece', 'title' => 'Evidence-driven learning impact', 'image' => ''],
    ['icon' => 'fa-globe', 'title' => 'Partnership readiness with schools and systems actors', 'image' => ''],
    ['icon' => 'fa-microphone', 'title' => 'Sustainable and affordable business models', 'image' => ''],
];

$show_cohorts = home_setting($settings, 'show_cohorts_section', '0') === '1';
$cohorts = [];

if ($show_cohorts) {
    $cohorts = home_rows(home_query($conn, "
        SELECT c.*,
               (
                   SELECT COUNT(*)
                   FROM ventures v
                   WHERE v.cohort_id = c.id
                     AND v.status = 'active'
               ) AS startup_count
        FROM cohorts c
        WHERE c.status = 'active'
        ORDER BY c.sort_order ASC, c.id ASC
    "));
}

$app_status = home_setting($settings, 'hero_application_status', 'closed');
$app_link = home_setting($settings, 'hero_application_link', 'https://edtech.hivecolab.com/apply');
$elig_img = img_src(home_setting($settings, 'eligibility_image', 'assets/images/edtech-hero.jpg'), 'assets/images/edtech-hero.jpg');

$bg = [
    'about'       => section_bg($settings, 'about'),
    'program'     => section_bg($settings, 'program'),
    'eligibility' => section_bg($settings, 'eligibility'),
    'benefits'    => section_bg($settings, 'benefits'),
    'cohorts'     => section_bg($settings, 'cohorts'),
    'partners'    => section_bg($settings, 'partners'),
    'process'     => section_bg($settings, 'process'),
    'contact'     => section_bg($settings, 'contact'),
];

include __DIR__ . '/includes/header.php';
?>

<style>

.hero-slides {
    position: relative;
    overflow: hidden;
}

.hero-slides__controls,
.hero-slides__dots {
    display: none !important;
}

.hero-slide-arrow {
    position: absolute;
    top: 50%;
    z-index: 20;
    width: 54px;
    height: 54px;
    border: 0;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, 0.92);
    color: #111827;
    box-shadow: 0 14px 35px rgba(0, 0, 0, 0.20);
    transform: translateY(-50%);
    cursor: pointer;
    transition: transform 0.22s ease, background 0.22s ease, color 0.22s ease, box-shadow 0.22s ease;
}

.hero-slide-arrow i {
    font-size: 32px;
    line-height: 1;
}

.hero-slide-arrow--prev {
    left: 28px;
}

.hero-slide-arrow--next {
    right: 28px;
}

.hero-slide-arrow:hover,
.hero-slide-arrow:focus {
    background: #ffffff;
    color: #f86505;
    box-shadow: 0 18px 42px rgba(0, 0, 0, 0.28);
    transform: translateY(-50%) scale(1.06);
    outline: none;
}

.startups-preview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 22px;
    align-items: stretch;
    justify-items: center;
}

.startup-card-preview {
    width: 100%;
    max-width: 290px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-start;
    text-align: center;
}

.startup-card-logo {
    width: 150px;
    height: 105px;
    margin: 0 auto 14px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.startup-card-logo img {
    max-width: 135px;
    max-height: 90px;
    width: auto;
    height: auto;
    object-fit: contain;
    display: block;
    margin: 0 auto;
}

.startup-logo-placeholder {
    width: 105px;
    height: 105px;
    border-radius: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
    font-size: 34px;
    font-weight: 800;
}

.startup-card-info {
    width: 100%;
    text-align: center;
}

.startup-card-info h4,
.startup-card-info p {
    text-align: center;
}

.startup-sector {
    display: inline-flex;
    justify-content: center;
    margin-left: auto;
    margin-right: auto;
}

@media (max-width: 768px) {
    .hero-slide-arrow {
        width: 44px;
        height: 44px;
    }

    .hero-slide-arrow i {
        font-size: 26px;
    }

    .hero-slide-arrow--prev {
        left: 12px;
    }

    .hero-slide-arrow--next {
        right: 12px;
    }

    .startup-card-preview {
        max-width: 100%;
    }

    .startup-card-logo {
        width: 135px;
        height: 96px;
    }

    .startup-card-logo img {
        max-width: 122px;
        max-height: 82px;
    }
}
</style>


<section id="home" class="hero-slides" aria-label="Homepage hero">
    <?php foreach ($hero_slides as $i => $slide): ?>
        <?php
        $active = $i === 0 ? ' hero-slide--active' : '';
        $media_type = strtolower((string)($slide['media_type'] ?? 'image'));
        $image_path = trim((string)($slide['image_path'] ?? ''));
        $video_path = trim((string)($slide['video_path'] ?? ''));
        $video_url = trim((string)($slide['video_url'] ?? ''));
        $bg_color = trim((string)($slide['bg_color'] ?? ''));
        $opacity = number_format(max(0, min(1, (float)($slide['overlay_opacity'] ?? 0.55))), 2);
        ?>
        <article class="hero-slide<?= $active ?>" data-slide="<?= (int)$i ?>">
            <?php if ($media_type === 'video' && ($video_path !== '' || $video_url !== '')): ?>
                <div class="hero-slide__bg hero-slide__bg--video">
                    <?php if ($video_path !== ''): ?>
                        <video autoplay muted loop playsinline>
                            <source src="<?= h(asset_url($video_path)) ?>" type="video/mp4">
                        </video>
                    <?php else: ?>
                        <iframe src="<?= h($video_url) ?>" title="Hero video" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>
                    <?php endif; ?>
                </div>
            <?php elseif ($media_type === 'color' && $bg_color !== ''): ?>
                <div class="hero-slide__bg" style="background:<?= h($bg_color) ?>"></div>
            <?php elseif ($image_path !== ''): ?>
                <div class="hero-slide__bg" style="background-image:url('<?= h(asset_url($image_path)) ?>')"></div>
            <?php else: ?>
                <div class="hero-slide__bg hero-slide__bg--fallback"></div>
            <?php endif; ?>

            <div class="hero-slide__overlay" style="--ov:<?= h($opacity) ?>"></div>

            <div class="container">
                <div class="hero-slide__content">
                    <?php if (trim((string)($slide['label'] ?? '')) !== ''): ?>
                        <span class="hero-slide__label"><?= h($slide['label']) ?></span>
                    <?php endif; ?>

                    <?php if (trim((string)($slide['title'] ?? '')) !== ''): ?>
                        <h1 class="hero-slide__title"><?= h($slide['title']) ?></h1>
                    <?php endif; ?>

                    <?php if (trim((string)($slide['subtitle'] ?? '')) !== ''): ?>
                        <p class="hero-slide__subtitle"><?= h($slide['subtitle']) ?></p>
                    <?php endif; ?>

                    <?php if (trim((string)($slide['description'] ?? '')) !== ''): ?>
                        <p class="hero-slide__desc"><?= nl2br(h($slide['description'])) ?></p>
                    <?php endif; ?>

                    <?php if (trim((string)($slide['button_text'] ?? '')) !== '' && trim((string)($slide['button_link'] ?? '')) !== ''): ?>
                        <div class="hero-slide__cta">
                            <a href="<?= h($slide['button_link']) ?>" class="btn btn-primary">
                                <?= h($slide['button_text']) ?>
                            </a>

                            <?php if (trim((string)($slide['button2_text'] ?? '')) !== '' && trim((string)($slide['button2_link'] ?? '')) !== ''): ?>
                                <a href="<?= h($slide['button2_link']) ?>" class="btn btn-outline-light">
                                    <?= h($slide['button2_text']) ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </article>
    <?php endforeach; ?>

    <?php if (count($hero_slides) > 1): ?>
        <button type="button" class="hero-slide-arrow hero-slide-arrow--prev hero-slides__prev" aria-label="Previous slide">
            <i class="fas fa-angle-left" aria-hidden="true"></i>
        </button>

        <button type="button" class="hero-slide-arrow hero-slide-arrow--next hero-slides__next" aria-label="Next slide">
            <i class="fas fa-angle-right" aria-hidden="true"></i>
        </button>
    <?php endif; ?>
</section>
<?php
$about_bg = $bg['about'];
$about_has_video = $about_bg['type'] === 'video' && $about_bg['value'] !== '';

$about_img = home_setting($settings, 'about_image');
if ($about_img === '') {
    $about_img = home_setting($settings, 'about_photo');
}
?>

<section id="about" class="about about-program-grid-section" style="<?= h($about_bg['style']) ?>">
    <?php if ($about_has_video): ?>
        <div class="section-video-bg">
            <video autoplay muted loop playsinline>
                <source src="<?= h(asset_url($about_bg['value'])) ?>" type="video/mp4">
            </video>
            <?php if ($about_bg['overlay'] > 0): ?>
                <div class="section-video-overlay" style="--ov:<?= h((string)$about_bg['overlay']) ?>"></div>
            <?php endif; ?>
        </div>
    <?php elseif ($about_bg['type'] === 'image' && $about_bg['overlay'] > 0): ?>
        <div class="section-img-overlay" style="--ov:<?= h((string)$about_bg['overlay']) ?>"></div>
    <?php endif; ?>

    <div class="container section-layer">

        <div class="about-program-grid">
            <div class="about-program-image">
                <img 
                    src="<?= h(asset_url($about_img !== '' ? $about_img : 'assets/images/edtech-hero.jpg')) ?>"
                    alt="About the programme"
                >
            </div>

            <div class="about-program-content">
                <span class="section-tag">
                    <?= h(home_setting($settings, 'about_tag', 'About the Programme')) ?>
                </span>

               
                <?php if (home_setting($settings, 'about_description') !== ''): ?>
                    <p><?= nl2br(h(home_setting($settings, 'about_description'))) ?></p>
                <?php endif; ?>

                <?php if (home_setting($settings, 'about_body') !== ''): ?>
                    <p><?= nl2br(h(home_setting($settings, 'Through the Mastercard Foundation EdTech Fellowship, implemented by Hive Colab in partnership with the Mastercard Foundation, we support growth stage EdTech ventures that have traction and are ready to scale responsibly.'))) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="program-overview-block">
            <span class="section-tag"><?= h(home_setting($settings, 'program_overview_title', 'Programme Overview')) ?></span>
<p class="mb-5 program-overview-text">
    <?= nl2br(h(home_setting(
        $settings,
        'program_overview_description',
        'The Fellowship supports growth stage EdTech ventures operating in Uganda or with a strong Uganda focus. Ventures receive structured support that strengthens product readiness, implementation excellence, partnerships, business resilience, and learning outcomes measurement.'
    ))) ?>
</p>
        </div>

        <?php if (!empty($bento_items)): ?>
    <div class="about-bento">
        <div class="bento-cta-card">
            <div class="bento-cta-card__inner">
                <h2 class="bento-cta-card__title">
                    <?= h(home_setting($settings, 'hero_title', 'Mastercard Foundation EdTech Fellowship')) ?>
                </h2>

                <?php if (home_setting($settings, 'about_cta_sub', 'Ready to start your journey?') !== ''): ?>
                    <p class="bento-cta-card__sub">
                        <?= h(home_setting($settings, 'about_cta_sub', 'Ready to start your journey?')) ?>
                    </p>
                <?php endif; ?>

                <?php if ($app_status === 'open' && $app_link !== ''): ?>
                  <!--  <a href="<?= h($app_link) ?>" target="_blank" rel="noopener" class="bento-cta-btn">
                        <?= h(home_setting($settings, 'about_cta_btn', 'WAITING LIST')) ?>
                    </a> -->
                <?php elseif ($app_status === 'coming_soon'): ?>
                    <span class="bento-cta-btn bento-cta-btn--soon">Opening Soon</span>
                <?php else: ?>
                    <span class="bento-cta-btn bento-cta-btn--closed">Applications Closed</span>
                <?php endif; ?>
                
                 <a href="https://www.edtech.hivecolab.com/referral" target="_blank" rel="noopener" class="bento-cta-btn">
                        <?= h(home_setting($settings, 'about_cta_btn', 'Refer a Venture')) ?>
                    </a>
            </div>
        </div>

        <div class="bento-cards-grid">
            <?php foreach (array_slice($bento_items, 0, 4) as $idx => $item): ?>
                <?php
                $card_no = $idx + 1;

                $card_image = trim((string) home_setting($settings, 'about_card_' . $card_no . '_bg', ''));

                if ($card_image === '') {
                    $card_image = trim((string)($item['image'] ?? ''));
                }

                $card_title = trim((string)($item['title'] ?? ''));
                $card_icon  = safe_icon($item['icon'] ?? '', 'fa-check-circle');

                $card_style = '';
                if ($card_image !== '') {
                    $card_style = "background-image: url('" . h(asset_url($card_image)) . "');";
                }
                ?>

                <div class="bento-feature-card <?= $card_image !== '' ? 'has-bg-image' : '' ?>"
                     style="<?= $card_style ?>">

                    <?php if ($card_image === ''): ?>
                        <div class="bento-feature-card__placeholder bento-grad-<?= (int)(($idx % 4) + 1) ?>"></div>
                    <?php endif; ?>

                    <div class="bento-feature-card__overlay"></div>

                    <div class="bento-feature-card__content">
                        <span class="bento-feature-card__icon">
                            <i class="fa <?= h($card_icon) ?>"></i>
                        </span>

                        <h3 class="bento-feature-card__title">
                            <?= h($card_title) ?>
                        </h3>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
        <?php if (home_setting($settings, 'impact_description') !== '' || !empty($impact_stats)): ?>
            <div class="about-impact">
                <span class="section-tag">Why this fellowship matters in Uganda</span>

                <?php if (home_setting($settings, 'impact_description') !== ''): ?>
                    <p><?= h(home_setting($settings, 'impact_description')) ?></p>
                <?php endif; ?>

                <?php if (!empty($impact_stats)): ?>
                    <div class="impact-stats">
                        <?php foreach ($impact_stats as $stat): ?>
                            <div class="stat-item">
                                <span class="stat-icon">
                                    <i class="fa <?= h(safe_icon($stat['icon'] ?? '', 'fa-chart-line')) ?>"></i>
                                </span>
                                <p><?= h($stat['label'] ?? '') ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>
</section>
<?php
$program_banner_image = home_setting($settings, 'program_stages_banner_image', '');
$program_banner_title = home_setting($settings, 'program_stages_banner_title', 'Programme Structure');
$program_banner_desc  = home_setting($settings, 'program_stages_banner_subtitle', home_setting($settings, 'program_description', ''));
$program_overlay      = (int) home_setting($settings, 'program_stages_banner_overlay', '45');

$program_style = $bg['program']['style'] ?? '';

if ($program_banner_image !== '') {
    $program_style .= " background-image:url('" . h(asset_url($program_banner_image)) . "'); background-size:cover; background-position:center; background-repeat:no-repeat;";
}
?>

<section id="program" class="program program-banner-section" style="<?= $program_style ?>">
    <?php if ($program_banner_image !== ''): ?>
        <div class="section-img-overlay" style="--ov:<?= h((string)($program_overlay / 100)) ?>"></div>
    <?php elseif (($bg['program']['type'] ?? '') === 'image' && ($bg['program']['overlay'] ?? 0) > 0): ?>
        <div class="section-img-overlay" style="--ov:<?= h((string)$bg['program']['overlay']) ?>"></div>
    <?php endif; ?>

    <div class="container section-layer">
        <div class="section-header centered">
            <span class="section-tag"><?= h($program_banner_title) ?></span>

            <?php if ($program_banner_desc !== ''): ?>
                <p class="section-desc"><?= h($program_banner_desc) ?></p>
            <?php endif; ?>
        </div>

        <?php if (!empty($stages)): ?>
            <div class="program-stages">
                <?php foreach ($stages as $index => $stage): ?>
                    <?php
                    $features = json_decode((string)($stage['features'] ?? '[]'), true);
                    $features = is_array($features) ? $features : [];
                    $stage_class = $index === 0 ? 'stage-one' : 'stage-two';
                    ?>

                    <?php if ($index > 0): ?>
                        <div class="stage-connector"></div>
                    <?php endif; ?>

                    <div class="stage-card <?= h($stage_class) ?>">
                        <div class="stage-number">
                            <?= h($stage['stage_number'] ?? ($index + 1)) ?>
                        </div>

                        <div class="stage-icon">
                            <i class="fa <?= h(safe_icon($stage['icon'] ?? '', 'fa-star')) ?>"></i>
                        </div>

                        <h3 class="stage-title">
                            <?= h($stage['title'] ?? '') ?>
                        </h3>

                        <p class="stage-description">
                            <?= h($stage['description'] ?? '') ?>
                        </p>

                        <?php if (!empty($features)): ?>
                            <ul class="stage-features">
                                <?php foreach ($features as $feature): ?>
                                    <li>
                                        <i class="fa fa-check-circle"></i>
                                        <?= h((string)$feature) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="empty-msg">Programme stages will be published soon.</p>
        <?php endif; ?>
    </div>
</section>

<section id="eligibility" class="eligibility" style="<?= h($bg['eligibility']['style']) ?>">
    <?php if ($bg['eligibility']['type'] === 'image' && $bg['eligibility']['overlay'] > 0): ?>
        <div class="section-img-overlay" style="--ov:<?= h((string)$bg['eligibility']['overlay']) ?>"></div>
    <?php endif; ?>

    <div class="container section-layer">
        <div class="eligibility-wrapper">
            <div class="eligibility-image">
                <img src="<?= h($elig_img) ?>" alt="Eligibility Criteria" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='<?= h(asset_url('assets/images/edtech-hero.jpg')) ?>';">

                <?php if (home_setting($settings, 'eligibility_quote') !== ''): ?>
                    <div class="eligibility-quote">
                        <p><?= h(home_setting($settings, 'eligibility_quote')) ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="eligibility-content">
                <div class="section-header section-header--left">
                    <span class="section-tag"><?= h(home_setting($settings, 'eligibility_tag', 'Eligibility')) ?></span>

                    <?php if (home_setting($settings, 'eligibility_title') !== ''): ?>
                        <h2 class="section-title"><?= h(home_setting($settings, 'eligibility_title')) ?></h2>
                    <?php endif; ?>

                    <?php if (home_setting($settings, 'eligibility_description') !== ''): ?>
                        <p><?= h(home_setting($settings, 'eligibility_description')) ?></p>
                    <?php endif; ?>
                </div>

                <?php if (!empty($eligibility)): ?>
                    <div class="eligibility-list">
                        <?php foreach ($eligibility as $criterion): ?>
                            <div class="eligibility-item">
                                <div class="eligibility-icon"><i class="fa <?= h(safe_icon($criterion['icon'] ?? '', 'fa-check')) ?>"></i></div>
                                <div class="eligibility-detail">
                                    <?php if (trim((string)($criterion['title'] ?? '')) !== ''): ?>
                                        <h4><?= h($criterion['title']) ?></h4>
                                    <?php endif; ?>
                                    <p><?= h($criterion['description'] ?? '') ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="empty-msg empty-msg--light">Eligibility criteria will be published soon.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
<?php
$benefits_bg_type    = home_setting($settings, 'benefits_bg_type', $bg['benefits']['type'] ?? 'color');
$benefits_bg_color   = home_setting($settings, 'benefits_bg_color', '#ffffff');
$benefits_bg_image   = home_setting($settings, 'benefits_bg_image', '');
$benefits_bg_video   = home_setting($settings, 'benefits_bg_video', '');
$benefits_overlay_on = home_setting($settings, 'benefits_bg_overlay', '0');
$benefits_ov_color   = home_setting($settings, 'benefits_bg_overlay_color', '#000000');
$benefits_ov_opacity = (int) home_setting($settings, 'benefits_bg_overlay_opacity', '35');

$benefits_title = home_setting($settings, 'benefits_section_title', 'What Fellows Receive');
$benefits_desc  = home_setting($settings, 'benefits_section_description', home_setting($settings, 'benefits_description', ''));

$benefits_style = '';

if ($benefits_bg_type === 'color') {
    $benefits_style = 'background:' . h($benefits_bg_color) . ';';
} elseif ($benefits_bg_type === 'image' && $benefits_bg_image !== '') {
    $benefits_style = "background-image:url('" . h(asset_url($benefits_bg_image)) . "');background-size:cover;background-position:center;background-repeat:no-repeat;";
} else {
    $benefits_style = $bg['benefits']['style'] ?? '';
}
?>

<section id="benefits" class="benefits benefits-dynamic-bg" style="<?= $benefits_style ?>">
    <?php if ($benefits_bg_type === 'video' && $benefits_bg_video !== ''): ?>
        <div class="section-video-bg">
            <video autoplay muted loop playsinline>
                <source src="<?= h(asset_url($benefits_bg_video)) ?>" type="video/mp4">
            </video>
        </div>
    <?php endif; ?>

    <?php if (($benefits_bg_type === 'image' || $benefits_bg_type === 'video') && $benefits_overlay_on === '1'): ?>
        <div 
            class="section-custom-overlay" 
            style="background:<?= h($benefits_ov_color) ?>;opacity:<?= h((string)($benefits_ov_opacity / 100)) ?>;">
        </div>
    <?php elseif (($bg['benefits']['type'] ?? '') === 'image' && ($bg['benefits']['overlay'] ?? 0) > 0): ?>
        <div class="section-img-overlay" style="--ov:<?= h((string)$bg['benefits']['overlay']) ?>"></div>
    <?php endif; ?>

    <div class="container section-layer">
        <div class="section-header centered">
            <span class="section-tag"><?= h($benefits_title) ?></span>

            <?php if ($benefits_desc !== ''): ?>
                <p class="section-desc"><?= h($benefits_desc) ?></p>
            <?php endif; ?>
        </div>

        <?php if (!empty($benefits)): ?>
            <div class="benefits-grid">
                <?php foreach ($benefits as $benefit): ?>
                    <div class="benefit-card">
                        <div class="benefit-icon">
                            <i class="fa <?= h(safe_icon($benefit['icon'] ?? '', 'fa-check-circle')) ?>"></i>
                        </div>

                        <h3><?= h($benefit['title'] ?? '') ?></h3>
                        <p><?= h($benefit['description'] ?? '') ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="empty-msg">Benefits will be published soon.</p>
        <?php endif; ?>
    </div>
</section>
<?php if ($show_cohorts && !empty($cohorts)): ?>
<?php
$cohorts_bg_type    = home_setting($settings, 'cohorts_sec_bg_type', 'color');
$cohorts_bg_color   = home_setting($settings, 'cohorts_sec_bg_color', '#ffffff');
$cohorts_bg_image   = home_setting($settings, 'cohorts_sec_bg_image', '');
$cohorts_bg_video   = home_setting($settings, 'cohorts_sec_bg_video', '');
$cohorts_overlay_on = home_setting($settings, 'cohorts_sec_bg_overlay', '0');
$cohorts_ov_color   = home_setting($settings, 'cohorts_sec_bg_overlay_color', '#000000');
$cohorts_ov_opacity = (int) home_setting($settings, 'cohorts_sec_bg_overlay_opacity', '35');

$cohorts_style = '';

if ($cohorts_bg_type === 'color') {
    $cohorts_style = 'background:' . h($cohorts_bg_color) . ';';
} elseif ($cohorts_bg_type === 'image' && $cohorts_bg_image !== '') {
    $cohorts_style = "background-image:url('" . h(asset_url($cohorts_bg_image)) . "');background-size:cover;background-position:center;background-repeat:no-repeat;";
}
?>

<section id="cohorts" class="cohorts-section cohorts-dynamic-bg" style="<?= $cohorts_style ?>">
    <?php if ($cohorts_bg_type === 'video' && $cohorts_bg_video !== ''): ?>
        <div class="section-video-bg">
            <video autoplay muted loop playsinline>
                <source src="<?= h(asset_url($cohorts_bg_video)) ?>" type="video/mp4">
            </video>
        </div>
    <?php endif; ?>

    <?php if (($cohorts_bg_type === 'image' || $cohorts_bg_type === 'video') && $cohorts_overlay_on === '1'): ?>
        <div 
            class="section-custom-overlay"
            style="background:<?= h($cohorts_ov_color) ?>;opacity:<?= h((string)($cohorts_ov_opacity / 100)) ?>;">
        </div>
    <?php endif; ?>

    <div class="container section-layer">
        <div class="section-header centered">
            <span class="section-tag">
                <?= h(home_setting($settings, 'cohorts_section_title', 'Meet Our Ventures')) ?>
            </span>

           <!-- <?php if (home_setting($settings, 'cohorts_section_description') !== ''): ?>
                <p class="section-desc"><?= h(home_setting($settings, 'cohorts_section_description')) ?></p>
            <?php endif; ?> -->
        </div>

        <?php foreach ($cohorts as $cohort): ?>
            <?php
            $cohort_id = (int)($cohort['id'] ?? 0);
            $cohort_image = trim((string)($cohort['image'] ?? ''));

            $startup_rows = $cohort_id > 0
                ? home_rows(home_query($conn, "
                    SELECT *
                    FROM ventures
                    WHERE cohort_id = {$cohort_id}
                      AND status = 'active'
                    ORDER BY sort_order ASC, name ASC
                    LIMIT 12
                "))
                : [];

            $application_status = (string)($cohort['application_status'] ?? 'closed');
            $status_class = $application_status === 'open'
                ? 'badge-app-open'
                : ($application_status === 'coming_soon' ? 'badge-app-soon' : 'badge-app-closed');
            ?>

            <div class="cohort-block" id="cohort-<?= $cohort_id ?>">
                <div class="cohort-header-bar">
                    <div class="cohort-header-left">
                        <?php if ($cohort_image !== ''): ?>
                            <img src="<?= h(img_src($cohort_image, 'assets/images/edtech-hero.jpg')) ?>" class="cohort-thumb" alt="<?= h($cohort['name'] ?? 'Cohort') ?>" loading="lazy" decoding="async">
                        <?php endif; ?>

                        <div>
                            <h3><?= h($cohort['name'] ?? '') ?></h3>
                            <?php if (trim((string)($cohort['tagline'] ?? '')) !== ''): ?>
                                <p><?= h($cohort['tagline']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="cohort-header-right">
                       
                        <a href="<?= h(asset_url('cohorts.php#cohort-' . $cohort_id)) ?>" class="btn btn-outline-dark btn-sm">
                            View All Ventures <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                </div>

                <?php if (!empty($startup_rows)): ?>
                    <div class="startups-preview-grid">
                        <?php foreach ($startup_rows as $startup): ?>
                            <?php
                            $startup_name = (string)($startup['name'] ?? 'Startup');
                            $startup_logo = trim((string)($startup['logo'] ?? ''));
                            $startup_slug = trim((string)($startup['slug'] ?? ''));
                            $startup_link = $startup_slug !== '' ? asset_url('venture.php?slug=' . rawurlencode($startup_slug)) : '#';
                            $startup_initial = strtoupper(substr($startup_name, 0, 1));
                            ?>

                            <a href="<?= h($startup_link) ?>" class="startup-card-preview">
                                <div class="startup-card-logo">
                                    <?php if ($startup_logo !== ''): ?>
                                        <img src="<?= h(img_src($startup_logo, 'assets/images/logo.png')) ?>" alt="<?= h($startup_name) ?>" loading="lazy" decoding="async">
                                    <?php else: ?>
                                        <div class="startup-logo-placeholder"><?= h($startup_initial) ?></div>
                                    <?php endif; ?>
                                </div>

                                <div class="startup-card-info">
                                    <h4><?= h($startup_name) ?></h4>
                                    <p><?= h(truncate((string)($startup['tagline'] ?? $startup['description'] ?? ''), 70)) ?></p>

                                    <?php if (trim((string)($startup['sector'] ?? '')) !== ''): ?>
                                        <span class="startup-sector"><?= h($startup['sector']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="no-startups-msg">Ventures coming soon for this cohort.</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
<?php
$partners_bg_type    = home_setting($settings, 'partners_bg_type', $bg['partners']['type'] ?? 'color');
$partners_bg_color   = home_setting($settings, 'partners_bg_color', '#ffffff');
$partners_bg_image   = home_setting($settings, 'partners_bg_image', '');
$partners_bg_video   = home_setting($settings, 'partners_bg_video', '');
$partners_overlay_on = home_setting($settings, 'partners_bg_overlay', '0');
$partners_ov_color   = home_setting($settings, 'partners_bg_overlay_color', '#000000');
$partners_ov_opacity = (int) home_setting($settings, 'partners_bg_overlay_opacity', '35');

$partners_tag  = home_setting($settings, 'partners_section_tag', 'Partners and ecosystem collaboration');
$partners_desc = home_setting($settings, 'partners_description', '');

$partners_style = '';

if ($partners_bg_type === 'color') {
    $partners_style = 'background:' . h($partners_bg_color) . ';';
} elseif ($partners_bg_type === 'image' && $partners_bg_image !== '') {
    $partners_style = "background-image:url('" . h(asset_url($partners_bg_image)) . "');background-size:cover;background-position:center;background-repeat:no-repeat;";
} else {
    $partners_style = $bg['partners']['style'] ?? '';
}
?>

<section id="partners" class="partners partners-dynamic-bg" style="<?= $partners_style ?>">
    <?php if ($partners_bg_type === 'video' && $partners_bg_video !== ''): ?>
        <div class="section-video-bg">
            <video autoplay muted loop playsinline>
                <source src="<?= h(asset_url($partners_bg_video)) ?>" type="video/mp4">
            </video>
        </div>
    <?php endif; ?>

    <?php if (($partners_bg_type === 'image' || $partners_bg_type === 'video') && $partners_overlay_on === '1'): ?>
        <div 
            class="section-custom-overlay"
            style="background:<?= h($partners_ov_color) ?>;opacity:<?= h((string)($partners_ov_opacity / 100)) ?>;">
        </div>
    <?php elseif (($bg['partners']['type'] ?? '') === 'image' && ($bg['partners']['overlay'] ?? 0) > 0): ?>
        <div class="section-img-overlay" style="--ov:<?= h((string)$bg['partners']['overlay']) ?>"></div>
    <?php endif; ?>

    <div class="container section-layer">
        <div class="section-header centered">
            <span class="section-tag"><?= h($partners_tag) ?></span>
        </div>

        <div class="partners-content">
            <?php if ($partners_desc !== ''): ?>
                <p class="partners-description">
                    <?= h($partners_desc) ?>
                </p>
            <?php endif; ?>

            <?php if (!empty($partner_rows)): ?>
                <div class="partners-logos">
                    <?php foreach ($partner_rows as $partner): ?>
                        <?php
                        $partner_name = trim((string)($partner['name'] ?? 'Partner'));
                        $partner_logo = trim((string)($partner['logo'] ?? ''));
                        $partner_url  = trim((string)($partner['website'] ?? ''));

                        if ($partner_url !== '' && !preg_match('#^https?://#i', $partner_url)) {
                            $partner_url = 'https://' . $partner_url;
                        }

                        $logo_src = img_src($partner_logo, 'assets/images/logo.png');
                        ?>

                        <?php if ($partner_url !== ''): ?>
                            <a 
                                href="<?= h($partner_url) ?>" 
                                target="_blank" 
                                rel="noopener noreferrer" 
                                class="partner-logo-item"
                                title="<?= h($partner_name) ?>"
                                aria-label="Open <?= h($partner_name) ?> website"
                            >
                                <img 
                                    src="<?= h($logo_src) ?>" 
                                    alt="<?= h($partner_name) ?>" 
                                    loading="lazy"
                                    onerror="this.onerror=null;this.src='<?= h(asset_url('assets/images/placeholder-logo.png')) ?>';"
                                >
                            </a>
                        <?php else: ?>
                            <div 
                                class="partner-logo-item partner-logo-item--disabled"
                                title="<?= h($partner_name) ?>"
                                aria-label="<?= h($partner_name) ?>"
                            >
                                <img 
                                    src="<?= h($logo_src) ?>" 
                                    alt="<?= h($partner_name) ?>" 
                                    loading="lazy"
                                    onerror="this.onerror=null;this.src='<?= h(asset_url('assets/images/placeholder-logo.png')) ?>';"
                                >
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="partners-cta">
                <a href="#contact" class="btn btn-primary">
                    <i class="fa fa-handshake"></i> Become a Partner
                </a>
            </div>
        </div>
    </div>
</section>
<section class="hive-org">
    <div class="container">
        <div class="hive-content">
            <p>Key information <br>about the Mastercard EdTech Fellowship <br>and the application process.</p>
            <a href="<?= h(asset_url('faqs.php')) ?>" class="btn btn-secondary">
                <i class="fa fa-question-circle"></i> Frequently Asked Questions
            </a>
        </div>
    </div>
</section>

<?php
$process_bg = $bg['process'] ?? section_bg($settings, 'process');
$contact_bg = $bg['contact'] ?? section_bg($settings, 'contact');

$process_style = $process_bg['style'] ?? '';
$contact_style = $contact_bg['style'] ?? '';
?>

<section id="process" class="process-section section-dynamic-bg" style="<?= h($process_style) ?>">
    <?php render_section_background_layers($process_bg); ?>

    <div class="container section-layer">
        <div class="section-header centered">
            <span class="section-tag">
                <?= h(home_setting($settings, 'process_tag', 'How to Apply')) ?>
            </span>

            <?php if (home_setting($settings, 'process_title') !== ''): ?>
                <h2 class="section-title">
                    <?= h(home_setting($settings, 'process_title')) ?>
                </h2>
            <?php endif; ?>

           
        </div>

        <div class="process-timeline">
            <?php
            $default_titles = [
                1 => 'Online Application',
                2 => 'Shortlisting & Interviews',
                3 => 'Selection & Onboarding',
            ];

            $default_descriptions = [
                1 => 'Complete the online form with details about your venture, traction, inclusion approach, and learning impact.',
                2 => 'Selected ventures will be invited for interviews and readiness review.',
                3 => 'Successful ventures join the cohort and begin the fellowship journey.',
            ];
            ?>

            <?php for ($i = 1; $i <= 3; $i++): ?>
                <?php
                $step_title = home_setting($settings, 'process_step_' . $i . '_title', $default_titles[$i]);
                $step_desc  = home_setting($settings, 'process_step_' . $i . '_description', $default_descriptions[$i]);
                $step_icon  = safe_icon(home_setting($settings, 'process_step_' . $i . '_icon', 'fa-check-circle'), 'fa-check-circle');
                ?>

                <div class="process-step">
                    <div class="step-content">
                       <!-- <div class="step-icon">
                            <i class="fa <?= h($step_icon) ?>"></i>
                        </div> -->

                        <h3>
                        <!--<?= (int)$i ?>. -->
                        
                        <?= h($step_title) ?></h3>
                        <p><?= nl2br(h($step_desc)) ?></p>

                        <?php if ($i === 1): ?>
                            <?php if ($app_status === 'open' && $app_link !== ''): ?>
                                <a href="<?= h($app_link) ?>" target="_blank" rel="noopener" class="btn btn-primary btn-sm">
                                    <i class="fa fa-rocket"></i> Apply Now
                                </a>
                            <?php elseif ($app_status === 'closed'): ?>
                                <p><strong>Applications for this Cohort are closed.</strong></p>
                            <?php else: ?>
                                <p><strong>Applications opening soon!</strong></p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <div class="step-number"><?= (int)$i ?></div>
                </div>
            <?php endfor; ?>
        </div>
    </div>
</section>
<section id="contact" class="contact section-dynamic-bg" style="<?= h($contact_style) ?>">
    <?php render_section_background_layers($contact_bg); ?>

    <div class="container section-layer">
        <div class="contact-content contact-pro-layout">

            <div class="contact-info contact-pro-info">
                <div class="section-header contact-pro-header">
                    <span class="section-tag">
                        <?= h(home_setting($settings, 'contact_tag', 'Get in Touch')) ?>
                    </span>

                    <?php if (home_setting($settings, 'contact_title') !== ''): ?>
                        <h2 class="section-title">
                            <?= h(home_setting($settings, 'contact_title')) ?>
                        </h2>
                    <?php endif; ?>

                    <?php if (home_setting($settings, 'contact_description') !== ''): ?>
                        <p><?= nl2br(h(home_setting($settings, 'contact_description'))) ?></p>
                    <?php endif; ?>
                </div>

                <div class="contact-cards">
                    <?php if (home_setting($settings, 'site_email') !== ''): ?>
                        <a class="contact-card" href="mailto:<?= h(home_setting($settings, 'site_email')) ?>">
                            <span class="contact-card-icon">
                                <i class="fa fa-envelope"></i>
                            </span>
                            <span class="contact-card-body">
                                <small>Email us</small>
                                <strong><?= h(home_setting($settings, 'site_email')) ?></strong>
                            </span>
                            <i class="fa fa-arrow-right contact-card-arrow"></i>
                        </a>
                    <?php endif; ?>

                    <?php if (home_setting($settings, 'site_phone') !== ''): ?>
                        <a class="contact-card" href="tel:<?= h(preg_replace('/\s+/', '', home_setting($settings, 'site_phone'))) ?>">
                            <span class="contact-card-icon">
                                <i class="fa fa-phone"></i>
                            </span>
                            <span class="contact-card-body">
                                <small>Call us</small>
                                <strong><?= h(home_setting($settings, 'site_phone')) ?></strong>
                            </span>
                            <i class="fa fa-arrow-right contact-card-arrow"></i>
                        </a>
                    <?php endif; ?>

                    <div class="contact-card">
                        <span class="contact-card-icon">
                            <i class="fa fa-map-marker-alt"></i>
                        </span>
                        <span class="contact-card-body">
                            <small>Visit us</small>
                            <strong><?= h(home_setting($settings, 'site_location', 'Kampala, Uganda')) ?></strong>
                        </span>
                    </div>
                </div>
            </div>

            <div class="contact-form-wrapper contact-pro-form-card">
                <div class="contact-form-title">
                    <span><i class="fa fa-paper-plane"></i></span>
                    <div>
                        <h3>Send us a message</h3>
                        <p>Fill in the form and our team will get back to you.</p>
                    </div>
                </div>

                <div id="formMsg" class="alert" style="display:none;"></div>

                <form class="contact-form" id="contactForm" method="post" action="<?= h(SITE_URL) ?>/includes/contact_process.php">
                    <input type="hidden" name="site_csrf_token" value="<?= h(site_csrf_token()) ?>">
             <div class="form-row">
    <div class="form-group">
        <label for="name">Full Name</label>
        <input type="text" id="name" name="name" placeholder="e.g. Surname Given" required>
    </div>

    <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" placeholder="you@example.com" required>
    </div>
</div>

<div class="form-group">
    <label for="subject">Subject</label>
    <input type="text" id="subject" name="subject" placeholder="What's this about?" required>
</div>

<div class="form-group">
    <label for="message">Message</label>
    <textarea id="message" name="message" rows="5" placeholder="Describe your project, question, or idea..." required></textarea>
</div>

                    <div class="form-footer">
                        <button type="submit" class="btn btn-send" id="sendBtn">
                            Send Message <i class="fa fa-paper-plane"></i>
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</section>

<script>
(function () {
    const slides = Array.from(document.querySelectorAll('.hero-slide'));
    if (slides.length <= 1) return;

    const dots = Array.from(document.querySelectorAll('.hero-dot'));
    const prev = document.querySelector('.hero-slides__prev');
    const next = document.querySelector('.hero-slides__next');
    let current = 0;
    let timer = null;

    function showSlide(index) {
        current = (index + slides.length) % slides.length;

        slides.forEach((slide, i) => slide.classList.toggle('hero-slide--active', i === current));
        dots.forEach((dot, i) => dot.classList.toggle('active', i === current));
    }

    function autoPlay() {
        clearInterval(timer);
        timer = setInterval(() => showSlide(current + 1), 7000);
    }

    prev && prev.addEventListener('click', function () {
        showSlide(current - 1);
        autoPlay();
    });

    next && next.addEventListener('click', function () {
        showSlide(current + 1);
        autoPlay();
    });

    dots.forEach((dot) => {
        dot.addEventListener('click', function () {
            showSlide(parseInt(this.dataset.target || '0', 10));
            autoPlay();
        });
    });

    autoPlay();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
