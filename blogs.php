<?php
require_once 'includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) die('Database connection not found.');
$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}


function blog_asset(string $path): string {
    $path = trim($path);
    if ($path === '') return '';
    if (preg_match('/^https?:\/\//i', $path)) return $path;
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path);
    $path = preg_replace('#^(\.\./)+#', '', $path);
    $path = ltrim($path, '/');
    return rtrim(SITE_URL, '/') . '/' . $path;
}


/**
 * Create a short, clean description for blog listing cards.
 * Full article content remains available only after clicking Read More.
 */
function blog_card_excerpt(array $blog, int $limit = 125): string {
    $text = trim((string)($blog['excerpt'] ?? ''));

    if ($text === '') {
        $text = trim(strip_tags((string)($blog['content'] ?? '')));
    }

    if ($text === '' && !empty($blog['content_builder'])) {
        $text = trim(strip_tags(render_blog_blocks((string)$blog['content_builder'])));
    }

    // Remove repeated whitespace/newlines so every card stays compact.
    $text = (string)preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);

    if ($text === '') {
        return '';
    }

    return mb_strimwidth($text, 0, $limit, '…', 'UTF-8');
}


function blog_style_attr(array $b): string {
    $sizes = [
        'small'  => '14px',
        'normal' => '16px',
        'large'  => '18px',
        'xlarge' => '22px',
        'huge'   => '28px',
    ];
    $weights = [
        'light'    => '300',
        'normal'   => '400',
        'medium'   => '500',
        'semibold' => '600',
        'bold'     => '700',
    ];

    $sizeKey   = $b['fontSize']   ?? 'normal';
    $weightKey = $b['fontWeight'] ?? 'normal';
    $styleVal  = ($b['fontStyle'] ?? 'normal') === 'italic' ? 'italic' : 'normal';

    $fontSize   = $sizes[$sizeKey]     ?? $sizes['normal'];
    $fontWeight = $weights[$weightKey] ?? $weights['normal'];

    // Keep markup clean when everything is default.
    if ($fontSize === $sizes['normal'] && $fontWeight === $weights['normal'] && $styleVal === 'normal') {
        return '';
    }

    return sprintf(
        ' style="font-size:%s;font-weight:%s;font-style:%s;"',
        $fontSize,
        $fontWeight,
        $styleVal
    );
}


function safe_blog_html(string $html): string {
    $allowed = '<div><section><article><h1><h2><h3><h4><h5><h6>'
             . '<p><br><strong><b><em><i><u><s>'
             . '<ul><ol><li>'
             . '<blockquote><cite><footer>'
             . '<figure><figcaption><img>'
             . '<a><span><hr>'
             . '<table><thead><tbody><tfoot><tr><th><td>'
             . '<caption>';
    $html = strip_tags($html, $allowed);

  
    $html = preg_replace_callback(
        '/<img([^>]+)src=["\']([^"\']+)["\']([^>]*)>/i',
        function ($m) {
            $src = trim($m[2]);
            if (!preg_match('/^https?:\/\//i', $src)) $src = blog_asset($src);
            return '<img' . $m[1] . 'src="' . h($src) . '"' . $m[3] . '>';
        },
        $html
    );
    return $html;
}


function render_blog_blocks(?string $json): string {
    if (!$json) return '';
    $blocks = json_decode($json, true);
    if (!is_array($blocks) || empty($blocks)) return '';

    $html = '<div class="elementor-blog-content">';
    foreach ($blocks as $b) {
        $html .= render_blog_block($b);
    }
    $html .= '</div>';
    return $html;
}

function render_blog_block(array $b): string {
    $type = $b['type'] ?? '';
    $out  = '';

    if ($type === 'heading') {
        $allowed = ['h1','h2','h3','h4'];
        $lvl   = in_array($b['level'] ?? 'h2', $allowed, true) ? $b['level'] : 'h2';
        $text  = h($b['text'] ?? '');
        $style = blog_style_attr($b);
        if ($text !== '') {
            $out = "<{$lvl} class=\"article-heading\"{$style}>{$text}</{$lvl}>";
        }
    }

   
    if ($type === 'paragraph') {
        $text  = trim((string)($b['text'] ?? ''));
        $style = blog_style_attr($b);
        if ($text !== '') {
            $paras = preg_split("/\r\n|\n|\r/", $text);
            $inner = '';
            foreach ($paras as $p) {
                $p = trim($p);
                if ($p !== '') $inner .= "<p{$style}>" . nl2br(h($p)) . '</p>';
            }
            if ($inner !== '') $out = $inner;
        }
    }

    if ($type === 'image') {
        $src     = trim((string)($b['src'] ?? ''));
        $url     = trim((string)($b['url'] ?? ''));
        $caption = h($b['caption'] ?? '');
        $align   = in_array($b['align'] ?? 'center', ['left','right','center','full'], true) ? $b['align'] : 'center';
        $img_src = $src !== '' ? blog_asset($src) : $url;

        if ($img_src !== '') {
            $safe = h($img_src);
            $out  = "<figure class=\"article-figure img-align-{$align}\">"
                  . "<img src=\"{$safe}\" alt=\"{$caption}\" loading=\"lazy\" class=\"article-img\">"
                  . ($caption !== '' ? "<figcaption>{$caption}</figcaption>" : '')
                  . '</figure>';
        }
    }

  
    if ($type === 'quote') {
        $text   = h($b['text']   ?? '');
        $author = h($b['author'] ?? '');
        $source = h($b['source'] ?? '');
        $style  = blog_style_attr($b);
        if ($text !== '') {
            $footer = '';
            if ($author !== '' || $source !== '') {
                $footer  = '<footer class="blockquote-footer">';
                $footer .= $author !== '' ? "<cite class=\"quote-author\">— {$author}</cite>" : '';
                $footer .= $source !== '' ? "<span class=\"quote-source\">, <em>{$source}</em></span>" : '';
                $footer .= '</footer>';
            }
            $out = "<blockquote class=\"article-blockquote\"><p{$style}>{$text}</p>{$footer}</blockquote>";
        }
    }

    
    if ($type === 'table') {
        $caption = h($b['caption'] ?? '');
        $headers = is_array($b['headers'] ?? null) ? $b['headers'] : [];
        $rows    = is_array($b['rows']    ?? null) ? $b['rows']    : [];

        $allCells = array_merge($headers, array_merge(...(array_values($rows) ?: [[]])));
        $hasData  = !empty(array_filter($allCells, fn($c) => trim((string)$c) !== ''));

        if ($hasData) {
            $thead = '';
            if (!empty($headers)) {
                $thead = '<thead><tr>'
                       . implode('', array_map(fn($h) => '<th>' . h($h) . '</th>', $headers))
                       . '</tr></thead>';
            }
            $tbody = '';
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $tbody .= '<tr>' . implode('', array_map(fn($c) => '<td>' . nl2br(h($c)) . '</td>', $row)) . '</tr>';
            }
            $out = ($caption !== '' ? "<p class=\"table-caption\">{$caption}</p>" : '')
                 . '<div class="table-responsive">'
                 . '<table class="article-table">'
                 . $thead
                 . ($tbody !== '' ? "<tbody>{$tbody}</tbody>" : '')
                 . '</table></div>';
        }
    }


    if ($type === 'list') {
        $tag   = ($b['style'] ?? 'unordered') === 'ordered' ? 'ol' : 'ul';
        $style = blog_style_attr($b);
        $items = is_array($b['items'] ?? null)
               ? array_values(array_filter($b['items'], fn($i) => trim((string)$i) !== ''))
               : [];
        if (!empty($items)) {
            $lis = implode('', array_map(fn($i) => '<li>' . h($i) . '</li>', $items));
            $out = "<{$tag} class=\"article-list\"{$style}>{$lis}</{$tag}>";
        }
    }

    if ($type === 'callout') {
        $variant = in_array($b['variant'] ?? 'info', ['info','tip','warning','danger'], true) ? $b['variant'] : 'info';
        $title   = h($b['title'] ?? '');
        $text    = h($b['text']  ?? '');
        $style   = blog_style_attr($b);
        $icons   = ['info' => '??', 'tip' => '??', 'warning' => '??', 'danger' => '??'];
        $icon    = $icons[$variant];
        if ($text !== '') {
            $out = "<div class=\"blog-callout callout-{$variant}\">"
                 . ($title !== '' ? "<div class=\"callout-title\">{$icon} {$title}</div>" : '')
                 . "<div class=\"callout-body\"{$style}>{$text}</div>"
                 . '</div>';
        }
    }

    if ($type === 'divider') {
        $style = in_array($b['style'] ?? 'solid', ['solid','dashed','decorative'], true) ? $b['style'] : 'solid';
        $out   = "<div class=\"blog-divider\"><hr class=\"divider-{$style}\"></div>";
    }

  
    if ($type === 'columns') {
        $left  = trim((string)($b['left']  ?? ''));
        $right = trim((string)($b['right'] ?? ''));
        $style = blog_style_attr($b);
        if ($left !== '' || $right !== '') {
            $out = '<div class="blog-columns"><div class="columns-wrap">'
                 . "<div class=\"column-item\"{$style}>" . nl2br(h($left))  . '</div>'
                 . "<div class=\"column-item\"{$style}>" . nl2br(h($right)) . '</div>'
                 . '</div></div>';
        }
    }

    
    if ($type === 'button') {
        $text  = h($b['text']  ?? 'Read More');
        $url   = trim((string)($b['url'] ?? ''));
        $style = in_array($b['style'] ?? 'primary', ['primary','secondary','outline'], true) ? $b['style'] : 'primary';
        $fontStyle = blog_style_attr($b);
        if ($url !== '') {
            $safe = h($url);
            $out  = "<div class=\"blog-button\">"
                  . "<a href=\"{$safe}\" class=\"blog-btn blog-btn-{$style}\" target=\"_blank\" rel=\"noopener\"{$fontStyle}>{$text}</a>"
                  . '</div>';
        }
    }

    return $out !== '' ? "<div class=\"blog-block blog-{$type}\">{$out}</div>" : '';
}

$slug = trim((string)($_GET['slug'] ?? ''));

/* ============================================================
   PRELOAD SELECTED ARTICLE BEFORE HEADER
   Needed so social-share metadata can use the article image.
============================================================ */
$blog = null;

if ($slug !== '') {
    $blogStmt = $conn->prepare("
        SELECT *
        FROM blogs
        WHERE slug = ?
          AND status = 'published'
        LIMIT 1
    ");

    if ($blogStmt) {
        $blogStmt->bind_param('s', $slug);
        $blogStmt->execute();
        $blog = $blogStmt->get_result()->fetch_assoc() ?: null;
        $blogStmt->close();
    }
}

$hero = null;

if ($slug === '') {
    $heroStmt = $conn->prepare("
        SELECT *
        FROM slides
        WHERE page_name='blogs'
          AND status=1
        ORDER BY sort_order ASC, id DESC
        LIMIT 1
    ");

    if ($heroStmt) {
        $heroStmt->execute();
        $hero = $heroStmt->get_result()->fetch_assoc();
        $heroStmt->close();
    }
}

/* ============================================================
   ARTICLE SOCIAL SHARE DATA
============================================================ */
$share_site_name = function_exists('get_setting')
    ? get_setting($conn, 'site_name', 'Mastercard Foundation EdTech Fellowship')
    : 'Mastercard Foundation EdTech Fellowship';

$share_title       = $share_site_name . ' - Blogs';
$share_description = 'Latest stories, updates and insights from the Mastercard Foundation EdTech Fellowship.';
$share_image       = '';
$share_url         = rtrim((string)SITE_URL, '/') . '/blogs.php';
$share_type        = 'website';

if ($blog) {
    $share_type = 'article';

    $share_title = trim((string)($blog['title'] ?? '')) !== ''
        ? trim((string)$blog['title'])
        : $share_title;

    $share_description = trim((string)($blog['excerpt'] ?? ''));

    if ($share_description === '') {
        $plain = trim(strip_tags((string)($blog['content'] ?? '')));

        if ($plain === '' && !empty($blog['content_builder'])) {
            $plain = trim(strip_tags(render_blog_blocks((string)$blog['content_builder'])));
        }

        $plain = preg_replace('/\s+/', ' ', $plain);

        if ($plain !== '') {
            $share_description = mb_strimwidth($plain, 0, 220, '…');
        }
    }

    if ($share_description === '') {
        $share_description = 'Read this article from the Mastercard Foundation EdTech Fellowship.';
    }

    $featured_image = trim((string)($blog['featured_image'] ?? ''));

    if ($featured_image !== '') {
        $share_image = blog_asset($featured_image);
    }

    $share_url = rtrim((string)SITE_URL, '/')
        . '/blogs.php?slug='
        . rawurlencode((string)$blog['slug']);
}

/*
 * These variables can also be consumed by includes/header.php if it
 * supports dynamic metadata.
 */
$page_title       = $share_title;
$meta_description = $share_description;
$og_title         = $share_title;
$og_description   = $share_description;
$og_image         = $share_image;
$og_url           = $share_url;
$og_type          = $share_type;

/*
 * Render the shared site header into a buffer so article pages can replace
 * the site's generic logo-based social metadata with article-specific tags
 * BEFORE the HTML is sent to Facebook, WhatsApp, LinkedIn, X, etc.
 */
ob_start();
include 'includes/header.php';
$header_html = (string)ob_get_clean();

if ($blog) {
    /*
     * Remove generic metadata already printed by includes/header.php.
     * Social crawlers commonly use the first og:image they encounter, so
     * leaving the site-logo tag before the article featured-image tag can
     * cause exactly the wrong preview shown on WhatsApp.
     */
    $header_html = preg_replace(
        '#<meta\s+(?:property|name)=["\'](?:og:(?:type|site_name|title|description|url|image|image:secure_url|image:alt|image:type|image:width|image:height)|twitter:(?:card|title|description|image|image:alt))["\'][^>]*>\s*#i',
        '',
        $header_html
    );

    $header_html = preg_replace(
        '#<link\s+rel=["\']canonical["\'][^>]*>\s*#i',
        '',
        $header_html
    );

    $meta  = "\n<!-- Article social preview: featured image -->\n";
    $meta .= '<meta property="og:type" content="article">' . "\n";
    $meta .= '<meta property="og:site_name" content="' . h($share_site_name) . '">' . "\n";
    $meta .= '<meta property="og:title" content="' . h($share_title) . '">' . "\n";
    $meta .= '<meta property="og:description" content="' . h($share_description) . '">' . "\n";
    $meta .= '<meta property="og:url" content="' . h($share_url) . '">' . "\n";

    if ($share_image !== '') {
        $meta .= '<meta property="og:image" content="' . h($share_image) . '">' . "\n";
        $meta .= '<meta property="og:image:secure_url" content="' . h($share_image) . '">' . "\n";
        $meta .= '<meta property="og:image:alt" content="' . h($share_title) . '">' . "\n";

        /*
         * Add image MIME type when it can be inferred from the featured
         * image URL. This helps strict social crawlers.
         */
        $img_path = (string)(parse_url($share_image, PHP_URL_PATH) ?? '');
        $img_ext  = strtolower(pathinfo($img_path, PATHINFO_EXTENSION));

        $mime_map = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
        ];

        if (isset($mime_map[$img_ext])) {
            $meta .= '<meta property="og:image:type" content="' . h($mime_map[$img_ext]) . '">' . "\n";
        }
    }

    $meta .= '<meta name="twitter:card" content="' . ($share_image !== '' ? 'summary_large_image' : 'summary') . '">' . "\n";
    $meta .= '<meta name="twitter:title" content="' . h($share_title) . '">' . "\n";
    $meta .= '<meta name="twitter:description" content="' . h($share_description) . '">' . "\n";

    if ($share_image !== '') {
        $meta .= '<meta name="twitter:image" content="' . h($share_image) . '">' . "\n";
        $meta .= '<meta name="twitter:image:alt" content="' . h($share_title) . '">' . "\n";
    }

    $meta .= '<link rel="canonical" href="' . h($share_url) . '">' . "\n";

    /*
     * Insert immediately before </head>. If the shared header unexpectedly
     * has no closing head tag, prepend the metadata rather than placing it
     * in the body.
     */
    if (stripos($header_html, '</head>') !== false) {
        $header_html = preg_replace(
            '#</head>#i',
            $meta . '</head>',
            $header_html,
            1
        );
    } else {
        $header_html = $meta . $header_html;
    }
}

echo $header_html;
?>


<style>
/* ==========================================================
   BLOG LISTING CARDS — compact preview descriptions
========================================================== */
.blog-card {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
}

.blog-card__body {
    display: flex;
    flex-direction: column;
    flex: 1;
}

.blog-card__excerpt {
    margin: 10px 0 16px;
    color: #64748b;
    line-height: 1.6;
    font-size: .95rem;

    /* Visual safeguard: never let a description dominate the card. */
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 3;
    overflow: hidden;
}

.blog-card__read-more {
    margin-top: auto;
}

@media (max-width: 767px) {
    .blog-card__excerpt {
        -webkit-line-clamp: 2;
        font-size: .92rem;
    }
}
</style>

<main class="blog-main">

<?php
if ($slug === ''): ?>

    <?php
    if ($hero): ?>
    <section class="blog-listing-hero">

        <?php if (($hero['media_type'] ?? '') === 'image' && !empty($hero['image_path'])): ?>
            <div class="blog-listing-hero__bg"
                 style="background-image:url('<?= h(blog_asset($hero['image_path'])) ?>')"></div>

        <?php elseif (($hero['media_type'] ?? '') === 'video'): ?>
            <div class="blog-listing-hero__bg blog-listing-hero__bg--video">
                <?php if (!empty($hero['video_path'])): ?>
                    <video autoplay muted loop playsinline>
                        <source src="<?= h(blog_asset($hero['video_path'])) ?>" type="video/mp4">
                    </video>
                <?php elseif (!empty($hero['video_url'])): ?>
                    <iframe src="<?= h($hero['video_url']) ?>" allowfullscreen></iframe>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="blog-listing-hero__overlay"
             style="--overlay-opacity:<?= h((string)($hero['overlay_opacity'] ?? 0.45)) ?>"></div>

        <div class="container">
            <div class="blog-listing-hero__content">
               
                <h1 class="blog-listing-hero__title">
                    <?= h($hero['title'] ?: 'Latest Articles') ?>
                </h1>
                <?php if (!empty($hero['subtitle'])): ?>
                    <p class="blog-listing-hero__subtitle"><?= nl2br(h($hero['subtitle'])) ?></p>
                <?php endif; ?>
                <?php if (!empty($hero['button_text']) && !empty($hero['button_link'])): ?>
                    <a href="<?= h($hero['button_link']) ?>" class="btn btn-primary">
                        <?= h($hero['button_text']) ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php 
    $stmt = $conn->prepare("
        SELECT id, title, slug, excerpt, content, content_builder,
               featured_image, author, category, status, published_at, created_at
        FROM blogs
        WHERE status = 'published'
        ORDER BY sort_order ASC, published_at DESC, created_at DESC
    ");
    if (!$stmt) die('Database error: ' . h($conn->error));
    $stmt->execute();
    $blogs = $stmt->get_result();
    ?>

    <section class="blog-listing-section">
        <div class="container">

            <?php if ($blogs && $blogs->num_rows > 0): ?>
            <div class="blogs-grid">

                <?php while ($b = $blogs->fetch_assoc()):
                    $img_url  = trim((string)($b['featured_image'] ?? ''));
                    $img_url  = $img_url !== '' ? blog_asset($img_url) : '';

                    // Keep listing cards brief. The full description/content
                    // is displayed only on the article page after Read More.
                    $excerpt  = blog_card_excerpt($b, 125);
                    $card_url = 'blogs.php?slug=' . urlencode($b['slug']);
                ?>

                <article class="blog-card">

                    <a href="<?= h($card_url) ?>" class="blog-card__thumb" aria-label="<?= h($b['title']) ?>">
                        <?php if ($img_url !== ''): ?>
                            <img src="<?= h($img_url) ?>"
                                 alt="<?= h($b['title']) ?>"
                                 loading="lazy"
                                 class="blog-card__img"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                            <span class="blog-card__img-fallback" aria-hidden="true">
                                <i class="fa fa-image"></i>
                            </span>
                        <?php else: ?>
                            <span class="blog-card__img-placeholder" aria-hidden="true">
                                <i class="fa fa-newspaper"></i>
                            </span>
                        <?php endif; ?>
                    </a>

                    <div class="blog-card__body">

                        <?php if (!empty($b['category'])): ?>
                            <span class="blog-card__category"><?= h($b['category']) ?></span>
                        <?php endif; ?>

                        <h2 class="blog-card__title">
                            <a href="<?= h($card_url) ?>"><?= h($b['title']) ?></a>
                        </h2>

                        <div class="blog-card__meta">
                            <!--<span><i class="fa fa-user"></i> <?= h($b['author'] ?: 'Admin') ?></span> -->
                            <?php if (!empty($b['published_at'])): ?>
                                <span>
                                    <i class="fa fa-calendar"></i>
                                    <?= date('F j, Y', strtotime($b['published_at'])) ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($excerpt !== ''): ?>
                            <p class="blog-card__excerpt"><?= h($excerpt) ?></p>
                        <?php endif; ?>

                        <a href="<?= h($card_url) ?>" class="blog-card__read-more">
                            Read More <i class="fa fa-arrow-right"></i>
                        </a>

                    </div>
                </article>

                <?php endwhile; ?>
            </div>

            <?php else: ?>
            <div class="empty-cohorts">
                <i class="fa fa-newspaper"></i>
                <h3>No published articles yet</h3>
                <p>Please check again later.</p>
            </div>
            <?php endif; ?>

        </div>
    </section>
    <?php $stmt->close(); ?>

<?php 
else:
    if (!$blog): ?>

    <section class="blog-not-found">
        <div class="container">
            <div class="blog-not-found__inner">
                <i class="fa fa-file-slash"></i>
                <h1>Article Not Found</h1>
                <p>The article you're looking for is unavailable or has been removed.</p>
                <a href="blogs.php" class="btn btn-primary">
                    <i class="fa fa-arrow-left"></i> Back to Articles
                </a>
            </div>
        </div>
    </section>

    <?php else:

        $featured_image = trim((string)($blog['featured_image'] ?? ''));
        $image_url      = $featured_image !== '' ? blog_asset($featured_image) : '';

       
        $hasBuilder = !empty($blog['content_builder']);
        if ($hasBuilder) {
            $decoded = json_decode($blog['content_builder'], true);
            $hasBuilder = is_array($decoded) && !empty($decoded);
        }
    ?>

    <?php 
    if ($image_url !== ''): ?>
    <div class="blog-detail-hero">
        <div class="blog-detail-hero__img-wrap">
            <img src="<?= h($image_url) ?>"
                 alt="<?= h($blog['title']) ?>"
                 loading="eager"
                 onerror="this.closest('.blog-detail-hero').classList.add('blog-detail-hero--no-img')">
        </div>
        <div class="blog-detail-hero__overlay"></div>
        <div class="container">
            <div class="blog-detail-hero__content">

                <a href="blogs.php" class="blog-detail-hero__back">
                    <i class="fa fa-arrow-left"></i> All Articles
                </a>

                <?php if (!empty($blog['category'])): ?>
                    <span class="blog-detail-hero__category"><?= h($blog['category']) ?></span>
                <?php endif; ?>

                <h1 class="blog-detail-hero__title"><?= h($blog['title']) ?></h1>

                <div class="blog-detail-hero__meta">
                    <!--<span><i class="fa fa-user"></i> <?= h($blog['author'] ?: 'Admin') ?></span>-->
                    <?php if (!empty($blog['published_at'])): ?>
                        <span>
                            <i class="fa fa-calendar"></i>
                            <?= date('F j, Y', strtotime($blog['published_at'])) ?>
                        </span>
                    <?php endif; ?>
                    <span><i class="fa fa-clock"></i>
                        <?php
                        $wordCount = str_word_count(strip_tags((string)($blog['content'] ?? '')));
                        echo max(1, ceil($wordCount / 200)) . ' min read';
                        ?>
                    </span>
                </div>

            </div>
        </div>
    </div>

    <?php 
    else: ?>
    <div class="blog-detail-header">
        <div class="container">
            <a href="blogs.php" class="blog-detail-header__back">
                <i class="fa fa-arrow-left"></i> All Articles
            </a>
            <?php if (!empty($blog['category'])): ?>
                <span class="blog-detail-header__category"><?= h($blog['category']) ?></span>
            <?php endif; ?>
            <h1 class="blog-detail-header__title"><?= h($blog['title']) ?></h1>
            <div class="blog-detail-header__meta">
                <span><i class="fa fa-user"></i> <?= h($blog['author'] ?: 'Admin') ?></span>
                <?php if (!empty($blog['published_at'])): ?>
                    <span>
                        <i class="fa fa-calendar"></i>
                        <?= date('F j, Y', strtotime($blog['published_at'])) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    
    <article class="blog-detail-body">
        <div class="container">
            <div class="blog-detail-body__inner">

                <?php
                if (!empty($blog['excerpt'])): ?>
                    <p class="blog-detail-excerpt"><?= h($blog['excerpt']) ?></p>
                <?php endif; ?>

          
                <div class="blog-article-toolbar">
                    <div class="blog-article-toolbar__left">
                        <?php if (!empty($blog['category'])): ?>
                            <span class="blog-cat-chip"><?= h($blog['category']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($blog['published_at'])): ?>
                            <span class="blog-date-chip">
                                <i class="fa fa-calendar-alt"></i>
                                <?= date('F j, Y', strtotime($blog['published_at'])) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="blog-article-toolbar__right">
                        <a href="blog-pdf.php?slug=<?= h($blog['slug']) ?>"
                           target="_blank"
                           class="btn-article-pdf"
                           title="View &amp; Download PDF">
                            <i class="fa fa-file-pdf"></i>
                            <span>PDF Version</span>
                        </a>
                    </div>
                </div>

                <?php 
                if ($hasBuilder): ?>
                    <div class="blog-content">
                        <?= render_blog_blocks($blog['content_builder']) ?>
                    </div>
                <?php else:
                    $rawContent = trim((string)($blog['content'] ?? ''));
                    if ($rawContent !== ''): ?>
                        <div class="blog-content">
                            <?= safe_blog_html($rawContent) ?>
                        </div>
                    <?php else: ?>
                        <p style="color:#9ca3af;font-style:italic">This article has no content yet.</p>
                    <?php endif; ?>
                <?php endif; ?>

                           <!-- <footer class="blog-detail-footer">
                    <div class="blog-detail-footer__actions">
                        <a href="blogs.php" class="btn btn-secondary">
                            <i class="fa fa-arrow-left"></i> Back to Articles
                        </a>
                        <a href="blog-pdf.php?slug=<?= h($blog['slug']) ?>"
                           target="_blank"
                           class="btn-article-pdf btn-article-pdf--lg">
                            <i class="fa fa-file-pdf"></i> Download PDF
                        </a>
                    </div>
                </footer> -->

            </div>
        </div>
    </article>

    <?php endif; ?>
<?php endif; ?>

</main>

<?php include 'includes/footer.php'; ?>