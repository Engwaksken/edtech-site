<?php
require_once __DIR__ . '/includes/process-newsletter.php';
include __DIR__ . '/includes/header.php';

if (!function_exists('newsletter_setting')) {
    function newsletter_setting(mysqli $conn, string $key, string $default = ''): string
    {
        if (function_exists('get_setting')) {
            return (string)get_setting($conn, $key, $default);
        }

        $stmt = $conn->prepare("
            SELECT setting_value 
            FROM site_settings 
            WHERE setting_key = ? 
            LIMIT 1
        ");

        if (!$stmt) {
            return $default;
        }

        $stmt->bind_param('s', $key);
        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (string)($row['setting_value'] ?? $default);
    }
}

if (!function_exists('newsletter_asset_url')) {
    function newsletter_asset_url(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        if (function_exists('asset_url')) {
            return asset_url($path);
        }

        return rtrim(SITE_URL, '/') . '/' . ltrim($path, '/');
    }
}

$newsletter_bg_type = newsletter_setting($conn, 'newsletter_hero_bg_type', 'color');

if (!in_array($newsletter_bg_type, ['color', 'image', 'video'], true)) {
    $newsletter_bg_type = 'color';
}

$newsletter_bg_color = newsletter_setting($conn, 'newsletter_hero_bg_color', '#fff7ed');

if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $newsletter_bg_color)) {
    $newsletter_bg_color = '#fff7ed';
}

$newsletter_bg_image = newsletter_setting($conn, 'newsletter_hero_bg_image', '');
$newsletter_bg_video = newsletter_setting($conn, 'newsletter_hero_bg_video', '');

$newsletter_overlay_on = newsletter_setting($conn, 'newsletter_hero_bg_overlay', '1');

$newsletter_overlay_color = newsletter_setting($conn, 'newsletter_hero_bg_overlay_color', '#000000');

if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $newsletter_overlay_color)) {
    $newsletter_overlay_color = '#000000';
}

$newsletter_overlay_opacity = (int)newsletter_setting($conn, 'newsletter_hero_bg_overlay_opacity', '35');
$newsletter_overlay_opacity = max(0, min(100, $newsletter_overlay_opacity));

$newsletter_label = newsletter_setting($conn, 'newsletter_hero_label', 'Newsletter');
$newsletter_title = newsletter_setting($conn, 'newsletter_hero_title', 'Stay in the Loop');
$newsletter_subtitle = newsletter_setting(
    $conn,
    'newsletter_hero_subtitle',
    'Get the latest news, opportunities, and insights from ' . ($site_name ?? 'our community') . ' delivered straight to your inbox.'
);

$newsletter_hero_style = 'background:' . h($newsletter_bg_color) . ';';

if ($newsletter_bg_type === 'image' && $newsletter_bg_image !== '') {
    $newsletter_hero_style = "background-image:url('" . h(newsletter_asset_url($newsletter_bg_image)) . "');background-size:cover;background-position:center;background-repeat:no-repeat;";
}
?>

<main class="nl-main" style="padding-top:72px">

    <section class="nl-hero nl-hero--dynamic" style="<?= $newsletter_hero_style ?>">
        <?php if ($newsletter_bg_type === 'video' && $newsletter_bg_video !== ''): ?>
            <div class="nl-hero__video-bg">
                <video autoplay muted loop playsinline>
                    <source src="<?= h(newsletter_asset_url($newsletter_bg_video)) ?>" type="video/mp4">
                </video>
            </div>
        <?php endif; ?>

        <?php if (($newsletter_bg_type === 'image' || $newsletter_bg_type === 'video') && $newsletter_overlay_on === '1'): ?>
            <div 
                class="nl-hero__overlay"
                style="background:<?= h($newsletter_overlay_color) ?>;opacity:<?= h((string)($newsletter_overlay_opacity / 100)) ?>;">
            </div>
        <?php endif; ?>

        <div class="container nl-hero__layer">
            <div class="nl-hero__inner">

               

                <h1 class="nl-hero__title"><?= h($newsletter_title) ?></h1>

                <?php if ($newsletter_subtitle !== ''): ?>
                    <p class="nl-hero__sub">
                        <?= h($newsletter_subtitle) ?>
                    </p>
                <?php endif; ?>

                <?php if ($flash_msg): ?>
                    <div class="nl-alert nl-alert-<?= h($flash_type) ?>">
                        <i class="fas fa-info-circle"></i>
                        <?= h($flash_msg) ?>
                    </div>
                <?php endif; ?>

                <form class="nl-subscribe-form" method="POST" action="newsletter.php">
                    <input type="hidden" name="_form" value="subscribe">

                    <div class="nl-sub-row">
                        <input
                            type="text"
                            name="name"
                            class="nl-input"
                            placeholder="Your name optional"
                            autocomplete="name"
                        >

                        <input
                            type="email"
                            name="email"
                            class="nl-input"
                            placeholder="Your email address"
                            required
                            autocomplete="email"
                        >

                        <button type="submit" class="btn btn-primary nl-sub-btn">
                            <i class="fas fa-paper-plane"></i> Subscribe
                        </button>
                    </div>

                    <p class="nl-privacy">
                        <i class="fas fa-lock"></i>
                        No spam, ever. Unsubscribe anytime.
                    </p>
                </form>

            </div>
        </div>
    </section>

    <section class="nl-benefits">
        <div class="container">
            <div class="nl-benefits__grid">

                <div class="nl-benefit-card">
                    <div class="nl-benefit-icon">
                        <i class="fas fa-lightbulb"></i>
                    </div>
                    <h3>Opportunities</h3>
                    <p>Funding calls, grants, and accelerator programs curated for ventures like yours.</p>
                </div>

                <div class="nl-benefit-card">
                    <div class="nl-benefit-icon">
                        <i class="fas fa-newspaper"></i>
                    </div>
                    <h3>EdTech Insights</h3>
                    <p>Industry news, research, and trends shaping the future of education technology.</p>
                </div>

                <div class="nl-benefit-card">
                    <div class="nl-benefit-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <h3>Events &amp; Workshops</h3>
                    <p>Invitations to community events, webinars, and networking sessions.</p>
                </div>

            </div>
        </div>
    </section>

    <?php if ($archive && $archive->num_rows > 0): ?>
        <section class="nl-archive">
            <div class="container">

                <div class="section-header">
                    <span class="section-label">
                        <i class="fas fa-history"></i> Past Issues
                    </span>

                    <h2 class="section-title">Newsletter Archive</h2>

                    <p class="section-description">
                        Browse our previous newsletters.
                    </p>
                </div>

                <div class="nl-archive-grid">
                    <?php while ($nl = $archive->fetch_assoc()): ?>
                        <div class="nl-archive-card">

                            <div class="nl-archive-card__icon">
                                <i class="fas fa-envelope"></i>
                            </div>

                            <div class="nl-archive-card__body">
                                <h3 class="nl-archive-card__title">
                                    <?= h($nl['subject']) ?>
                                </h3>

                                <?php if (!empty($nl['preheader'])): ?>
                                    <p class="nl-archive-card__preview">
                                        <?= h(mb_strimwidth((string)$nl['preheader'], 0, 90, '...')) ?>
                                    </p>
                                <?php endif; ?>

                                <div class="nl-archive-card__meta">
                                    <?php if (!empty($nl['sent_at'])): ?>
                                        <span>
                                            <i class="fas fa-calendar-alt"></i>
                                            <?= h(date('F j, Y', strtotime((string)$nl['sent_at']))) ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if (!empty($nl['from_name'])): ?>
                                        <span>
                                            <i class="fas fa-user"></i>
                                            <?= h($nl['from_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <a href="newsletter-view.php?id=<?= (int)$nl['id'] ?>" class="nl-archive-card__link">
                                Read <i class="fas fa-arrow-right"></i>
                            </a>

                        </div>
                    <?php endwhile; ?>
                </div>

            </div>
        </section>
    <?php endif; ?>

    <section class="nl-cta-bottom">
        <div class="container">
            <div class="nl-cta-bottom__inner">

                <h2>Don't miss out</h2>

                <p>
                    Join hundreds of founders, educators, and innovators receiving our newsletter.
                </p>

                <form
                    method="POST"
                    action="newsletter.php"
                    style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center;margin-top:20px"
                >
                    <input type="hidden" name="_form" value="subscribe">

                    <input
                        type="email"
                        name="email"
                        class="nl-input nl-input--dark"
                        placeholder="Enter your email"
                        required
                        style="max-width:300px"
                    >

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Subscribe Free
                    </button>
                </form>

            </div>
        </div>
    </section>

</main>

<style>
.nl-hero--dynamic {
    position: relative;
    min-height: 520px;
    display: flex;
    align-items: center;
    overflow: hidden;
    isolation: isolate;
}

.nl-hero--dynamic::before {
    content: "";
    position: absolute;
    inset: 0;
    background:
        radial-gradient(circle at 18% 22%, rgba(252,127,16,.22), transparent 34%),
        radial-gradient(circle at 86% 8%, rgba(47,123,107,.18), transparent 30%),
        linear-gradient(135deg, rgba(15,23,42,.30), rgba(15,23,42,.08));
    z-index: 0;
    pointer-events: none;
}

.nl-hero__video-bg {
    position: absolute;
    inset: 0;
    z-index: 0;
    overflow: hidden;
}

.nl-hero__video-bg video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.nl-hero__overlay {
    position: absolute;
    inset: 0;
    z-index: 1;
    pointer-events: none;
}

.nl-hero__layer {
    position: relative;
    z-index: 2;
}

.nl-hero--dynamic .nl-hero__inner {
    max-width: 860px;
    padding: 92px 0;
}

.nl-hero--dynamic .section-label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(255,255,255,.15);
    color: #fff;
    border: 1px solid rgba(255,255,255,.22);
    backdrop-filter: blur(10px);
    padding: 8px 16px;
    border-radius: 999px;
    font-size: .78rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .08em;
}

.nl-hero--dynamic .nl-hero__title {
    color: #fff;
    font-size: clamp(2.5rem, 6vw, 5rem);
    line-height: 1.04;
    margin: 14px 0 18px;
    font-weight: 950;
    letter-spacing: -.05em;
}

.nl-hero--dynamic .nl-hero__sub {
    max-width: 720px;
    color: rgba(255,255,255,.92);
    font-size: 1.12rem;
    line-height: 1.75;
    margin-bottom: 28px;
}

.nl-hero--dynamic .nl-subscribe-form {
    max-width: 820px;
    background: rgba(255,255,255,.13);
    border: 1px solid rgba(255,255,255,.24);
    backdrop-filter: blur(12px);
    border-radius: 22px;
    padding: 12px;
    box-shadow: 0 18px 45px rgba(15,23,42,.20);
}

.nl-hero--dynamic .nl-sub-row {
    display: grid;
    grid-template-columns: 1fr 1fr auto;
    gap: 10px;
}

.nl-hero--dynamic .nl-input {
    width: 100%;
    border: 0;
    outline: none;
    border-radius: 14px;
    padding: 14px 16px;
    background: rgba(255,255,255,.96);
    color: #111827;
    font-size: .95rem;
}

.nl-hero--dynamic .nl-sub-btn {
    white-space: nowrap;
    justify-content: center;
}

.nl-hero--dynamic .nl-privacy {
    margin: 10px 4px 0;
    color: rgba(255,255,255,.82);
    font-size: .86rem;
}

.nl-hero--dynamic .nl-alert {
    max-width: 760px;
    margin-bottom: 18px;
    border-radius: 14px;
}

@media (max-width: 768px) {
    .nl-hero--dynamic {
        min-height: auto;
    }

    .nl-hero--dynamic .nl-hero__inner {
        padding: 70px 0;
    }

    .nl-hero--dynamic .nl-sub-row {
        grid-template-columns: 1fr;
    }

    .nl-hero--dynamic .nl-sub-btn {
        width: 100%;
    }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
