<?php
require_once 'includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) die('Database connection not found.');
$conn->set_charset('utf8mb4');

/* -- Helpers ----------------------------------------------- */
if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

function ba(string $path): string {               // blog_asset shorthand
    $path = trim($path);
    if ($path === '') return '';
    if (preg_match('/^https?:\/\//i', $path)) return $path;
    $path = ltrim(preg_replace(['#\\\\#','#/+#','#^(\.\./)+#'], ['/','/',''], $path), '/');
    return rtrim(SITE_URL, '/') . '/' . $path;
}

function safe_html(string $html): string {
    $ok = '<div><section><h1><h2><h3><h4><h5><h6><p><br>'
        . '<strong><b><em><i><u><s><ul><ol><li>'
        . '<blockquote><cite><footer><figure><figcaption>'
        . '<img><a><span><hr><table><thead><tbody><tfoot>'
        . '<tr><th><td><caption>';
    $html = strip_tags($html, $ok);
    return preg_replace_callback(
        '/<img([^>]+)src=["\']([^"\']+)["\']([^>]*)>/i',
        fn($m) => '<img' . $m[1] . 'src="' . h(preg_match('/^https?:\/\//i', $m[2]) ? $m[2] : ba($m[2])) . '"' . $m[3] . '>',
        $html
    );
}

/* -- Block renderer ---------------------------------------- */
function render_blocks(?string $json): string {
    if (!$json) return '';
    $blocks = json_decode($json, true);
    if (!is_array($blocks) || empty($blocks)) return '';
    $out = '<div class="elementor-blog-content">';
    foreach ($blocks as $b) $out .= render_block($b);
    return $out . '</div>';
}

function render_block(array $b): string {
    $type = $b['type'] ?? '';
    $o    = '';

    if ($type === 'heading') {
        $lvl  = in_array($b['level'] ?? 'h2', ['h1','h2','h3','h4'], true) ? $b['level'] : 'h2';
        $text = h($b['text'] ?? '');
        if ($text) $o = "<{$lvl} class=\"article-heading\">{$text}</{$lvl}>";
    }

    if ($type === 'paragraph') {
        $text = trim($b['text'] ?? '');
        if ($text) {
            $o = implode('', array_filter(
                array_map(fn($p) => ($p = trim($p)) ? '<p>' . nl2br(h($p)) . '</p>' : '',
                preg_split("/\r\n|\n|\r/", $text))
            ));
        }
    }

    if ($type === 'image') {
        $src  = trim($b['src'] ?? '');
        $url  = trim($b['url'] ?? '');
        $cap  = h($b['caption'] ?? '');
        $aln  = in_array($b['align'] ?? 'center', ['left','right','center','full'], true) ? $b['align'] : 'center';
        $img  = $src ? ba($src) : $url;
        if ($img) {
            $o = "<figure class=\"article-figure img-align-{$aln}\">"
               . "<img src=\"" . h($img) . "\" alt=\"{$cap}\" loading=\"lazy\" class=\"article-img\">"
               . ($cap ? "<figcaption>{$cap}</figcaption>" : '')
               . '</figure>';
        }
    }

    if ($type === 'quote') {
        $text   = h($b['text']   ?? '');
        $author = h($b['author'] ?? '');
        $source = h($b['source'] ?? '');
        if ($text) {
            $footer = ($author || $source)
                ? '<footer class="blockquote-footer">'
                  . ($author ? "<cite class=\"quote-author\">— {$author}</cite>" : '')
                  . ($source ? "<span class=\"quote-source\">, <em>{$source}</em></span>" : '')
                  . '</footer>'
                : '';
            $o = "<blockquote class=\"article-blockquote\"><p>{$text}</p>{$footer}</blockquote>";
        }
    }

    if ($type === 'table') {
        $headers = is_array($b['headers'] ?? null) ? $b['headers'] : [];
        $rows    = is_array($b['rows']    ?? null) ? $b['rows']    : [];
        $caption = h($b['caption'] ?? '');
        $all     = array_merge($headers, array_merge(...(array_values($rows) ?: [[]])));
        if (!empty(array_filter($all, fn($c) => trim((string)$c) !== ''))) {
            $thead = $headers
                ? '<thead><tr>' . implode('', array_map(fn($c) => '<th>' . h($c) . '</th>', $headers)) . '</tr></thead>'
                : '';
            $tbody = '';
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $tbody .= '<tr>' . implode('', array_map(fn($c) => '<td>' . nl2br(h($c)) . '</td>', $row)) . '</tr>';
            }
            $o = ($caption ? "<p class=\"table-caption\">{$caption}</p>" : '')
               . '<div class="table-responsive"><table class="article-table">'
               . $thead . ($tbody ? "<tbody>{$tbody}</tbody>" : '')
               . '</table></div>';
        }
    }

    if ($type === 'list') {
        $tag   = ($b['style'] ?? 'unordered') === 'ordered' ? 'ol' : 'ul';
        $items = array_values(array_filter(is_array($b['items'] ?? null) ? $b['items'] : [], fn($i) => trim((string)$i) !== ''));
        if ($items) $o = "<{$tag} class=\"article-list\">" . implode('', array_map(fn($i) => '<li>' . h($i) . '</li>', $items)) . "</{$tag}>";
    }

    if ($type === 'callout') {
        $v     = in_array($b['variant'] ?? 'info', ['info','tip','warning','danger'], true) ? $b['variant'] : 'info';
        $title = h($b['title'] ?? '');
        $text  = h($b['text']  ?? '');
        $icons = ['info' => '??', 'tip' => '??', 'warning' => '??', 'danger' => '??'];
        if ($text) {
            $o = "<div class=\"blog-callout callout-{$v}\">"
               . ($title ? "<div class=\"callout-title\">{$icons[$v]} {$title}</div>" : '')
               . "<div class=\"callout-body\">{$text}</div></div>";
        }
    }

    if ($type === 'divider') {
        $s = in_array($b['style'] ?? 'solid', ['solid','dashed','decorative'], true) ? $b['style'] : 'solid';
        $o = "<div class=\"blog-divider\"><hr class=\"divider-{$s}\"></div>";
    }

    if ($type === 'columns') {
        $l = trim($b['left']  ?? '');
        $r = trim($b['right'] ?? '');
        if ($l || $r) {
            $o = '<div class="blog-columns"><div class="columns-wrap">'
               . '<div class="column-item">' . nl2br(h($l)) . '</div>'
               . '<div class="column-item">' . nl2br(h($r)) . '</div>'
               . '</div></div>';
        }
    }

    if ($type === 'button') {
        $text  = h($b['text']  ?? 'Read More');
        $url   = trim($b['url'] ?? '');
        $style = in_array($b['style'] ?? 'primary', ['primary','secondary','outline'], true) ? $b['style'] : 'primary';
        if ($url) {
            $o = "<div class=\"blog-button\">"
               . "<a href=\"" . h($url) . "\" class=\"blog-btn blog-btn-{$style}\" target=\"_blank\" rel=\"noopener\">{$text}</a>"
               . "</div>";
        }
    }

    return $o ? "<div class=\"blog-block blog-{$type}\">{$o}</div>" : '';
}

/* -- Fetch article ----------------------------------------- */
$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    header('Location: blogs.php');
    exit;
}

$stmt = $conn->prepare("SELECT * FROM blogs WHERE slug=? AND status='published' LIMIT 1");
if (!$stmt) die('DB error: ' . h($conn->error));
$stmt->bind_param('s', $slug);
$stmt->execute();
$blog = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* -- 404 --------------------------------------------------- */
if (!$blog) {
    http_response_code(404);
    include 'includes/header.php';
?>
<main class="blog-main" style="padding-top:72px">
  <section class="blog-not-found">
    <div class="container">
      <div class="blog-not-found__inner">
        <i class="fa fa-file-slash"></i>
        <h1>Article Not Found</h1>
        <p>The article you're looking for is unavailable or has been removed.</p>
        <a href="blogs.php" class="btn btn-primary"><i class="fa fa-arrow-left"></i> Back to Articles</a>
      </div>
    </div>
  </section>
</main>
<?php
    include 'includes/footer.php';
    exit;
}

/* -- Prep vars --------------------------------------------- */
$featured_image  = trim((string)($blog['featured_image'] ?? ''));
$image_url       = $featured_image !== '' ? ba($featured_image) : '';
$title           = $blog['title'] ?? '';
$author          = $blog['author'] ?: 'Admin';
$category        = $blog['category'] ?? '';
$excerpt         = $blog['excerpt']  ?? '';
$published_at    = $blog['published_at'] ?? '';
$pub_display     = $published_at ? date('F j, Y', strtotime($published_at)) : '';
$pub_machine     = $published_at ? date('c', strtotime($published_at))       : '';
$word_count      = str_word_count(strip_tags((string)($blog['content'] ?? '')));
$read_time       = max(1, ceil($word_count / 200));

// Decide render strategy
$builder_json = $blog['content_builder'] ?? '';
$has_builder  = $builder_json && is_array(json_decode($builder_json, true)) && !empty(json_decode($builder_json, true));

// Canonical & share URLs
$canonical = rtrim(SITE_URL, '/') . '/blog.php?slug=' . rawurlencode($slug);
$pdf_url   = rtrim(SITE_URL, '/') . '/blog-pdf.php?slug=' . rawurlencode($slug);

/* -- Related posts (same category, exclude current) ------- */
$related = [];
if ($category !== '') {
    $rs = $conn->prepare("
        SELECT id, title, slug, excerpt, featured_image, published_at, author, category
        FROM blogs
        WHERE status='published' AND category=? AND slug!=?
        ORDER BY published_at DESC LIMIT 3
    ");
    if ($rs) {
        $rs->bind_param('ss', $category, $slug);
        $rs->execute();
        $related = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
        $rs->close();
    }
}
// Fill up to 3 from any category if not enough
if (count($related) < 3) {
    $have  = count($related);
    $need  = 3 - $have;
    $slugs = array_merge([$slug], array_column($related, 'slug'));
    $ph    = implode(',', array_fill(0, count($slugs), '?'));
    $types = str_repeat('s', count($slugs));
    $rf    = $conn->prepare("
        SELECT id, title, slug, excerpt, featured_image, published_at, author, category
        FROM blogs
        WHERE status='published' AND slug NOT IN ({$ph})
        ORDER BY published_at DESC LIMIT {$need}
    ");
    if ($rf) {
        $rf->bind_param($types, ...$slugs);
        $rf->execute();
        $related = array_merge($related, $rf->get_result()->fetch_all(MYSQLI_ASSOC));
        $rf->close();
    }
}

include 'includes/header.php';
?>
<!– Inline meta for OG / Twitter cards –>
<style>
/* -- page-specific overrides only ----------------------- */
.article-share-bar a:hover { color: var(--primary-color); }


/* ==========================================================
   BLOG HERO ALIGNMENT / RESPONSIVE FIX
   Keeps banner text inside the normal page gutter instead of
   touching or overflowing the left edge.
========================================================== */
.blog-detail-hero {
    position: relative;
    width: 100%;
    min-height: 460px;
    overflow: hidden;
}

.blog-detail-hero__img-wrap,
.blog-detail-hero__overlay {
    position: absolute;
    inset: 0;
}

.blog-detail-hero__img-wrap img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center;
    display: block;
}

.blog-detail-hero__overlay {
    z-index: 1;
    background: linear-gradient(
        90deg,
        rgba(5, 16, 27, .88) 0%,
        rgba(5, 16, 27, .70) 42%,
        rgba(5, 16, 27, .30) 72%,
        rgba(5, 16, 27, .16) 100%
    );
}

.blog-detail-hero > .container {
    position: relative;
    z-index: 2;
    width: min(100% - 48px, 1200px);
    max-width: 1200px;
    min-height: 460px;
    margin-inline: auto;
    padding-inline: 0;
}

.blog-detail-hero__content {
    width: min(760px, 68%);
    min-height: 460px;
    padding: 64px 0 48px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: flex-start;
    box-sizing: border-box;
}

.blog-detail-hero__back {
    margin-bottom: 18px;
}

.blog-detail-hero__category {
    display: inline-flex;
    align-items: center;
    width: fit-content;
    margin-bottom: 16px;
}

.blog-detail-hero__title {
    width: 100%;
    max-width: 760px;
    margin: 0;
    font-size: clamp(2.5rem, 4.5vw, 4.6rem);
    line-height: 1.04;
    overflow-wrap: break-word;
    word-break: normal;
}

.blog-detail-hero__meta {
    margin-top: 22px;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px 18px;
}

@media (max-width: 991px) {
    .blog-detail-hero,
    .blog-detail-hero > .container,
    .blog-detail-hero__content {
        min-height: 420px;
    }

    .blog-detail-hero > .container {
        width: min(100% - 40px, 1200px);
    }

    .blog-detail-hero__content {
        width: min(760px, 82%);
        padding: 56px 0 42px;
    }

    .blog-detail-hero__title {
        font-size: clamp(2.25rem, 6vw, 3.8rem);
    }
}

@media (max-width: 767px) {
    .blog-detail-hero,
    .blog-detail-hero > .container,
    .blog-detail-hero__content {
        min-height: 390px;
    }

    .blog-detail-hero > .container {
        width: calc(100% - 32px);
    }

    .blog-detail-hero__content {
        width: 100%;
        padding: 44px 0 34px;
    }

    .blog-detail-hero__title {
        max-width: 100%;
        font-size: clamp(2rem, 9vw, 3rem);
        line-height: 1.08;
    }

    .blog-detail-hero__meta {
        margin-top: 18px;
        gap: 9px 14px;
        font-size: .9rem;
    }

    .blog-detail-hero__overlay {
        background: linear-gradient(
            90deg,
            rgba(5, 16, 27, .90) 0%,
            rgba(5, 16, 27, .72) 72%,
            rgba(5, 16, 27, .48) 100%
        );
    }
}

@media (max-width: 480px) {
    .blog-detail-hero > .container {
        width: calc(100% - 24px);
    }

    .blog-detail-hero__content {
        padding: 38px 0 30px;
    }

    .blog-detail-hero__title {
        font-size: clamp(1.85rem, 10vw, 2.55rem);
    }

    .blog-detail-hero__meta {
        align-items: flex-start;
    }
}

</style>

<main class="blog-main" itemscope itemtype="https://schema.org/Article">

  <!-- ---------------------------------------------------
       HERO — featured image OR flat header
  --------------------------------------------------- -->
  <?php if ($image_url !== ''): ?>

  <div class="blog-detail-hero">
    <div class="blog-detail-hero__img-wrap">
      <img src="<?= h($image_url) ?>"
           alt="<?= h($title) ?>"
           itemprop="image"
           loading="eager"
           onerror="this.closest('.blog-detail-hero').classList.add('blog-detail-hero--no-img')">
    </div>
    <div class="blog-detail-hero__overlay"></div>

    <div class="container">
      <div class="blog-detail-hero__content">

        <a href="blogs.php" class="blog-detail-hero__back">
          <i class="fa fa-arrow-left"></i> All Articles
        </a>

        <?php if ($category): ?>
          <span class="blog-detail-hero__category"><?= h($category) ?></span>
        <?php endif; ?>

        <h1 class="blog-detail-hero__title" itemprop="headline">
          <?= h($title) ?>
        </h1>

        <div class="blog-detail-hero__meta">
          <span><i class="fa fa-user-pen"></i> <?= h($author) ?></span>
          <?php if ($pub_display): ?>
            <time datetime="<?= h($pub_machine) ?>">
              <i class="fa fa-calendar"></i> <?= h($pub_display) ?>
            </time>
          <?php endif; ?>
          <span><i class="fa fa-clock"></i> <?= $read_time ?> min read</span>
        </div>

      </div>
    </div>
  </div>

  <?php else: ?>

  <!-- Flat header (no image) -->
  <div class="blog-detail-header">
    <div class="container">

      <a href="blogs.php" class="blog-detail-header__back">
        <i class="fa fa-arrow-left"></i> All Articles
      </a>

      <?php if ($category): ?>
        <span class="blog-detail-header__category"><?= h($category) ?></span>
      <?php endif; ?>

      <h1 class="blog-detail-header__title" itemprop="headline">
        <?= h($title) ?>
      </h1>

      <div class="blog-detail-header__meta">
        <span><i class="fa fa-user-pen"></i> <?= h($author) ?></span>
        <?php if ($pub_display): ?>
          <time datetime="<?= h($pub_machine) ?>">
            <i class="fa fa-calendar"></i> <?= h($pub_display) ?>
          </time>
        <?php endif; ?>
        <span><i class="fa fa-clock"></i> <?= $read_time ?> min read</span>
      </div>

    </div>
  </div>

  <?php endif; ?>

  <!-- ---------------------------------------------------
       ARTICLE BODY
  --------------------------------------------------- -->
  <article class="blog-detail-body" itemprop="articleBody">
    <div class="container">
      <div class="blog-detail-body__inner">

        <!-- EXCERPT intro -->
        <?php if ($excerpt !== ''): ?>
          <p class="blog-detail-excerpt"><?= h($excerpt) ?></p>
        <?php endif; ?>

        <!-- TOOLBAR: meta + PDF + share -->
        <div class="blog-article-toolbar">
          <div class="blog-article-toolbar__left">
            <?php if ($category): ?>
              <span class="blog-cat-chip"><?= h($category) ?></span>
            <?php endif; ?>
            <?php if ($pub_display): ?>
              <span class="blog-date-chip">
                <i class="fa fa-calendar-alt"></i> <?= h($pub_display) ?>
              </span>
            <?php endif; ?>
            <span class="blog-date-chip">
              <i class="fa fa-clock"></i> <?= $read_time ?> min read
            </span>
          </div>
          <div class="blog-article-toolbar__right">
            <!-- Social share -->
            <div class="article-share-bar">
              <a href="https://twitter.com/intent/tweet?url=<?= rawurlencode($canonical) ?>&text=<?= rawurlencode($title) ?>"
                 target="_blank" rel="noopener" aria-label="Share on X/Twitter" class="share-btn share-x">
                <i class="fab fa-x-twitter"></i>
              </a>
              <a href="https://www.facebook.com/sharer/sharer.php?u=<?= rawurlencode($canonical) ?>"
                 target="_blank" rel="noopener" aria-label="Share on Facebook" class="share-btn share-fb">
                <i class="fab fa-facebook-f"></i>
              </a>
              <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= rawurlencode($canonical) ?>"
                 target="_blank" rel="noopener" aria-label="Share on LinkedIn" class="share-btn share-li">
                <i class="fab fa-linkedin-in"></i>
              </a>
              <button type="button"
                      class="share-btn share-copy"
                      onclick="copyLink('<?= h($canonical) ?>')"
                      aria-label="Copy link"
                      title="Copy link">
                <i class="fa fa-link" id="copyIcon_<?= (int)$blog['id'] ?>"></i>
              </button>
            </div>
            <!-- PDF -->
            <a href="<?= h($pdf_url) ?>"
               target="_blank"
               class="btn-article-pdf"
               title="View &amp; Download as PDF">
              <i class="fa fa-file-pdf"></i>
              <span>PDF Version</span>
            </a>
          </div>
        </div>

        <!-- CONTENT -->
        <div class="blog-content">
          <?php if ($has_builder): ?>
            <?= render_blocks($builder_json) ?>
          <?php else:
              $raw = trim((string)($blog['content'] ?? ''));
              echo $raw !== '' ? safe_html($raw) : '<p style="color:#9ca3af;font-style:italic">No content available.</p>';
          ?>
          <?php endif; ?>
        </div>

        <!-- AUTHOR CARD -->
        <div class="blog-author-card">
          <div class="blog-author-card__avatar" aria-hidden="true">
            <?= mb_strtoupper(mb_substr(strip_tags($author), 0, 1)) ?>
          </div>
          <div class="blog-author-card__info">
            <p class="blog-author-card__label">Written by</p>
            <p class="blog-author-card__name" itemprop="author"><?= h($author) ?></p>
          </div>
        </div>

        <!-- FOOTER ACTIONS -->
        <footer class="blog-detail-footer">
          <div class="blog-detail-footer__actions">
            <a href="blogs.php" class="btn btn-secondary">
              <i class="fa fa-arrow-left"></i> All Articles
            </a>
            <a href="<?= h($pdf_url) ?>"
               target="_blank"
               class="btn-article-pdf btn-article-pdf--lg">
              <i class="fa fa-file-pdf"></i> Download PDF
            </a>
          </div>
        </footer>

      </div><!-- /.blog-detail-body__inner -->
    </div><!-- /.container -->
  </article>

  <!-- ---------------------------------------------------
       RELATED ARTICLES
  --------------------------------------------------- -->
  <?php if (!empty($related)): ?>
  <section class="blog-related">
    <div class="container">
      <div class="blog-related__header">
        <span class="section-label"><i class="fa fa-newspaper"></i> More Articles</span>
        <h2 class="blog-related__title">You May Also Like</h2>
      </div>

      <div class="blogs-grid">
        <?php foreach ($related as $r):
          $r_img = trim((string)($r['featured_image'] ?? ''));
          $r_img = $r_img !== '' ? ba($r_img) : '';
          $r_exc = h(mb_strimwidth(strip_tags($r['excerpt'] ?? ''), 0, 120, '…'));
          $r_url = h('blog.php?slug=' . rawurlencode($r['slug']));
        ?>
        <article class="blog-card">
          <a href="<?= $r_url ?>" class="blog-card__thumb" aria-label="<?= h($r['title']) ?>">
            <?php if ($r_img): ?>
              <img src="<?= h($r_img) ?>"
                   alt="<?= h($r['title']) ?>"
                   loading="lazy"
                   class="blog-card__img"
                   onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
              <span class="blog-card__img-fallback" aria-hidden="true"><i class="fa fa-image"></i></span>
            <?php else: ?>
              <span class="blog-card__img-placeholder" aria-hidden="true"><i class="fa fa-newspaper"></i></span>
            <?php endif; ?>
          </a>
          <div class="blog-card__body">
            <?php if (!empty($r['category'])): ?>
              <span class="blog-card__category"><?= h($r['category']) ?></span>
            <?php endif; ?>
            <h3 class="blog-card__title">
              <a href="<?= $r_url ?>"><?= h($r['title']) ?></a>
            </h3>
            <div class="blog-card__meta">
              <span><i class="fa fa-user"></i> <?= h($r['author'] ?: 'Admin') ?></span>
              <?php if (!empty($r['published_at'])): ?>
                <span><i class="fa fa-calendar"></i> <?= date('M j, Y', strtotime($r['published_at'])) ?></span>
              <?php endif; ?>
            </div>
            <?php if ($r_exc): ?>
              <p class="blog-card__excerpt"><?= $r_exc ?></p>
            <?php endif; ?>
            <a href="<?= $r_url ?>" class="blog-card__read-more">Read More <i class="fa fa-arrow-right"></i></a>
          </div>
        </article>
        <?php endforeach; ?>
      </div>

    </div>
  </section>
  <?php endif; ?>

</main>

<script>
function copyLink(url) {
  navigator.clipboard.writeText(url).then(function() {
    var icon = document.querySelector('.share-copy i');
    if (!icon) return;
    icon.className = 'fa fa-check';
    icon.closest('.share-copy').classList.add('share-copy--copied');
    setTimeout(function() {
      icon.className = 'fa fa-link';
      icon.closest('.share-copy').classList.remove('share-copy--copied');
    }, 2000);
  }).catch(function() {
    // Fallback for older browsers
    var ta = document.createElement('textarea');
    ta.value = url;
    ta.style.position = 'fixed';
    ta.style.opacity  = '0';
    document.body.appendChild(ta);
    ta.focus(); ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
  });
}
</script>

<?php include 'includes/footer.php'; ?>