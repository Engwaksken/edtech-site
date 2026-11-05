<?php
declare(strict_types=1);

/*
 * Shared layout for /privacy-policy and /terms-of-service.
 * The including page sets $legal_page to a key of LEGAL_PAGES.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/legal-pages.php';
require_once __DIR__ . '/rich-html.php';

$legal_meta = LEGAL_PAGES[$legal_page ?? 'privacy'] ?? LEGAL_PAGES['privacy'];
$legal_body = site_rich_html(get_setting($conn, $legal_meta['setting'], ''));
$legal_updated = get_setting($conn, $legal_meta['updated'], '');

include __DIR__ . '/header.php';
?>
<div style="padding-top:72px">
  <section style="background:linear-gradient(135deg,#000000,#222222);padding:72px 0 56px">
    <div class="container">
      <span class="section-tag" style="background:rgba(252,127,16,.2);color:#fc7f10">Legal</span>
      <h1 style="margin:14px 0 10px;font-family:'Outfit',serif;color:#fff;font-size:clamp(2rem,calc(5vw / var(--screen-zoom, 1)),3.5rem)"><?= h($legal_meta['title']) ?></h1>
      <?php if ($legal_updated !== '' && strtotime($legal_updated)): ?>
        <p style="color:rgba(255,255,255,.8);margin:0">Last updated <?= h(date('j F Y', strtotime($legal_updated))) ?></p>
      <?php endif; ?>
    </div>
  </section>

  <section style="padding:56px 0;background:#f8f9fa">
    <div class="container">
      <article class="legal-content">
        <?php if ($legal_body !== ''): ?>
          <?= $legal_body ?>
        <?php else: ?>
          <p>This page is being prepared. For questions, contact us at <a href="mailto:<?= h($site_email) ?>"><?= h($site_email) ?></a>.</p>
        <?php endif; ?>
      </article>
    </div>
  </section>
</div>

<style>
.legal-content{max-width:860px;margin:0 auto;background:#fff;border-radius:16px;box-shadow:0 8px 30px rgba(0,0,0,.06);padding:clamp(24px,5vw,56px);color:#374151;line-height:1.8;font-size:1rem;overflow-wrap:anywhere}
.legal-content > :first-child{margin-top:0}
.legal-content > :last-child{margin-bottom:0}
.legal-content h2,.legal-content h3,.legal-content h4,.legal-content h5,.legal-content h6{font-family:'Outfit',serif;color:#111;line-height:1.3;margin:2em 0 .6em}
.legal-content h2{font-size:1.5rem}.legal-content h3{font-size:1.25rem}.legal-content h4{font-size:1.1rem}
.legal-content p{margin:0 0 1em}
.legal-content ul,.legal-content ol{margin:0 0 1em;padding-left:1.5em}
.legal-content li{margin:.35em 0}
.legal-content a{color:#c85f00;text-decoration:underline}
.legal-content blockquote{margin:0 0 1em;padding:12px 18px;border-left:4px solid #fc7f10;background:#fff7ed;border-radius:6px}
.legal-content hr{border:0;border-top:1px solid #e5e7eb;margin:2em 0}
.legal-content pre{background:#f3f4f6;padding:12px;border-radius:8px;overflow-x:auto}
.legal-content table{width:100%;border-collapse:collapse;margin:0 0 1em;display:block;overflow-x:auto}
.legal-content th,.legal-content td{border:1px solid #e5e7eb;padding:8px 12px;text-align:left;vertical-align:top}
.legal-content th{background:#f9fafb}
</style>

<?php include __DIR__ . '/footer.php'; ?>
