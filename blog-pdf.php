<?php

require_once 'includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) die('Database error.');
$conn->set_charset('utf8mb4');

// -- Fetch blog ------------------------------------------------
$blog = null;

if (!empty($_GET['slug'])) {
    $slug = $conn->real_escape_string(trim($_GET['slug']));
    $res  = $conn->query("SELECT * FROM blogs WHERE slug='{$slug}' AND status='published' LIMIT 1");
    if ($res) $blog = $res->fetch_assoc();
} elseif (!empty($_GET['id'])) {
    $id  = (int)$_GET['id'];
    $res = $conn->query("SELECT * FROM blogs WHERE id={$id} LIMIT 1");
    if ($res) $blog = $res->fetch_assoc();
}

if (!$blog) {
    http_response_code(404);
    die('<!doctype html><html><head><title>Not Found</title></head><body style="font-family:sans-serif;padding:40px;text-align:center"><h2>Article not found.</h2><p><a href="javascript:history.back()">? Go back</a></p></body></html>');
}

// -- Build sections from content_builder ----------------------
$sections = [];
if (!empty($blog['content_builder'])) {
    $decoded = json_decode($blog['content_builder'], true);
    if (is_array($decoded)) $sections = $decoded;
}

$site_name    = function_exists('get_setting') ? get_setting($conn, 'site_name', 'EdTech Fellowship') : 'EdTech Fellowship';
$site_logo    = function_exists('get_setting') ? get_setting($conn, 'site_logo', '') : '';
$author       = h($blog['author']   ?: $site_name);
$category     = h($blog['category'] ?: '');
$published    = $blog['published_at'] ? date('F j, Y', strtotime($blog['published_at'])) : '';
$featured_img = !empty($blog['featured_image']) && file_exists($blog['featured_image'])
              ? SITE_URL . '/' . h($blog['featured_image'])
              : '';

/* -- Helpers ----------------------------------------------- */
function pdf_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/**
 * Builds an inline `style="..."` attribute (including the leading space)
 * from a section's fontSize / fontWeight / fontStyle choices, as set in the
 * admin Article Builder. Mirrors blog_style_attr() used by the front-end
 * article template so the PDF matches what was configured.
 */
function pdf_style_attr(array $s): string {
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

    $sizeKey   = $s['fontSize']   ?? 'normal';
    $weightKey = $s['fontWeight'] ?? 'normal';
    $styleVal  = ($s['fontStyle'] ?? 'normal') === 'italic' ? 'italic' : 'normal';

    $fontSize   = $sizes[$sizeKey]     ?? $sizes['normal'];
    $fontWeight = $weights[$weightKey] ?? $weights['normal'];

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

function render_sections(array $sections): string {
    $out = '';
    foreach ($sections as $s) {
        $type = $s['type'] ?? 'paragraph';
        $out .= render_section($type, $s);
    }
    return $out;
}
function render_section(string $type, array $s): string {
    $out = '';

    if ($type === 'heading') {
        $lvl   = in_array($s['level'] ?? 'h2', ['h1','h2','h3','h4']) ? $s['level'] : 'h2';
        $text  = pdf_h($s['text'] ?? '');
        $style = pdf_style_attr($s);
        if ($text) $out = "<{$lvl} class=\"ah\"{$style}>{$text}</{$lvl}>";
    }

    if ($type === 'paragraph') {
        $text  = trim($s['text'] ?? '');
        $style = pdf_style_attr($s);
        if ($text) {
            $paras = preg_split("/\r\n|\n|\r/", $text);
            $out = '<div class="ap">';
            foreach ($paras as $p) {
                $p = trim($p);
                if ($p) $out .= "<p{$style}>" . nl2br(pdf_h($p)) . '</p>';
            }
            $out .= '</div>';
        }
    }

    if ($type === 'image') {
        $src     = trim($s['src'] ?? '');
        $url     = trim($s['url'] ?? '');
        $caption = pdf_h($s['caption'] ?? '');
        $align   = in_array($s['align'] ?? 'center', ['left','right','center','full']) ? $s['align'] : 'center';
        $img_src = $src ?: $url;
        if ($img_src) {
            $safe = pdf_h($img_src);
            $full = $align === 'full' ? ' ai-full' : '';
            $out  = "<figure class=\"ai ai-{$align}{$full}\">
                       <img src=\"{$safe}\" alt=\"{$caption}\" loading=\"lazy\">
                       " . ($caption ? "<figcaption>{$caption}</figcaption>" : '') . "
                     </figure>";
        }
    }

    if ($type === 'quote') {
        $text   = pdf_h($s['text']   ?? '');
        $author = pdf_h($s['author'] ?? '');
        $source = pdf_h($s['source'] ?? '');
        $style  = pdf_style_attr($s);
        if ($text) {
            $footer = '';
            if ($author || $source) {
                $footer = '<footer>';
                if ($author) $footer .= "<cite class=\"qa\">{$author}</cite>";
                if ($source) $footer .= "<span class=\"qs\">, {$source}</span>";
                $footer .= '</footer>';
            }
            $out = "<blockquote class=\"aq\"><p{$style}>{$text}</p>{$footer}</blockquote>";
        }
    }

    if ($type === 'table') {
        $caption = pdf_h($s['caption'] ?? '');
        $headers = is_array($s['headers'] ?? null) ? $s['headers'] : [];
        $rows    = is_array($s['rows']    ?? null) ? $s['rows']    : [];
        $allCells = array_merge($headers, array_merge(...(array_values($rows) ?: [[]])));
        if (!empty(array_filter($allCells, fn($c) => trim($c) !== ''))) {
            $thead = '';
            if ($headers) {
                $thead = '<thead><tr>' . implode('', array_map(fn($h) => '<th>'.pdf_h($h).'</th>', $headers)) . '</tr></thead>';
            }
            $tbody = '';
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $tbody .= '<tr>' . implode('', array_map(fn($c) => '<td>'.nl2br(pdf_h($c)).'</td>', $row)) . '</tr>';
            }
            $out = '<div class="at-wrap">'
                 . ($caption ? "<p class=\"at-cap\">{$caption}</p>" : '')
                 . '<div class="at-scroll"><table class="at">'
                 . $thead . ($tbody ? "<tbody>{$tbody}</tbody>" : '')
                 . '</table></div></div>';
        }
    }

    if ($type === 'list') {
        $tag   = ($s['style'] ?? 'unordered') === 'ordered' ? 'ol' : 'ul';
        $style = pdf_style_attr($s);
        $items = is_array($s['items'] ?? null) ? array_filter($s['items'], fn($i) => trim($i) !== '') : [];
        if ($items) {
            $lis = implode('', array_map(fn($i) => '<li>'.pdf_h($i).'</li>', $items));
            $out = "<{$tag} class=\"al\"{$style}>{$lis}</{$tag}>";
        }
    }

    if ($type === 'callout') {
        $v     = in_array($s['variant'] ?? 'info', ['info','tip','warning','danger']) ? $s['variant'] : 'info';
        $title = pdf_h($s['title'] ?? '');
        $text  = pdf_h($s['text']  ?? '');
        $style = pdf_style_attr($s);
        $icons = ['info'=>'??','tip'=>'??','warning'=>'??','danger'=>'??'];
        if ($text) {
            $out = "<div class=\"ac ac-{$v}\">"
                 . ($title ? "<div class=\"ac-title\">{$icons[$v]} {$title}</div>" : '')
                 . "<div class=\"ac-body\"{$style}>{$text}</div>"
                 . '</div>';
        }
    }

    if ($type === 'divider') {
        $style = in_array($s['style'] ?? 'solid', ['solid','dashed','decorative']) ? $s['style'] : 'solid';
        $out   = "<div class=\"adiv adiv-{$style}\"></div>";
    }

    if ($type === 'columns') {
        $left  = trim($s['left']  ?? '');
        $right = trim($s['right'] ?? '');
        $style = pdf_style_attr($s);
        if ($left || $right) {
            $out = '<div class="acol">'
                 . "<div class=\"acol-item\"{$style}>" . nl2br(pdf_h($left))  . '</div>'
                 . "<div class=\"acol-item\"{$style}>" . nl2br(pdf_h($right)) . '</div>'
                 . '</div>';
        }
    }

    if ($type === 'button') {
        $text  = pdf_h($s['text'] ?? 'Read More');
        $url   = trim($s['url']   ?? '');
        $style = in_array($s['style'] ?? 'primary', ['primary','secondary','outline']) ? $s['style'] : 'primary';
        $fontStyle = pdf_style_attr($s);
        if ($url) {
            $out = "<div class=\"ab-wrap\"><a href=\"".pdf_h($url)."\" class=\"ab ab-{$style}\" target=\"_blank\" rel=\"noopener\"{$fontStyle}>{$text}</a></div>";
        }
    }

    return $out ? "<div class=\"block-{$type}\">{$out}</div>" : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/includes/favicon.php'; ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= pdf_h($blog['title']) ?> - <?= pdf_h($site_name) ?></title>
<meta name="description" content="<?= pdf_h($blog['excerpt'] ?? '') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Source+Serif+4:ital,wght@0,300;0,400;0,600;1,400&family=Inter:wght@400;500;600;700&display=swap">
<style>
/* ------------------------------------------------
   BASE RESET & VARIABLES
------------------------------------------------ */
:root {
  --primary:   #fc7f10;
  --dark:      #0f172a;
  --muted:     #64748b;
  --border:    #e2e8f0;
  --bg:        #f8fafc;
  --white:     #ffffff;
  --serif:     'Source Serif 4', Georgia, serif;
  --display:   'Playfair Display', Georgia, serif;
  --sans:      'Inter', system-ui, sans-serif;
  --max-w:     760px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 16px; scroll-behavior: smooth; }
body {
  font-family: var(--serif);
  background: var(--bg);
  color: var(--dark);
  line-height: 1.8;
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
}

/* ------------------------------------------------
   SCREEN CHROME (hidden when printing)
------------------------------------------------ */
.pdf-chrome {
  position: sticky;
  top: 0;
  z-index: 100;
  background: var(--dark);
  color: #e2e8f0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 28px;
  height: 56px;
  gap: 16px;
}
.pdf-chrome-brand {
  display: flex;
  align-items: center;
  gap: 12px;
}
.pdf-chrome-logo {
  height: 32px;
  width: auto;
}
.pdf-chrome-logo-icon {
  width: 32px; height: 32px;
  background: var(--primary);
  border-radius: 7px;
  display: flex; align-items: center; justify-content: center;
  font-size: 15px; color: #fff; font-family: var(--sans); font-weight: 700;
}
.pdf-chrome-site { font-size: .85rem; font-family: var(--sans); font-weight: 600; }
.pdf-chrome-actions { display: flex; gap: 10px; }
.btn-pdf {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 8px 18px;
  border-radius: 7px;
  font-size: .82rem; font-weight: 700;
  cursor: pointer; font-family: var(--sans);
  text-decoration: none; border: none;
  transition: all .2s; white-space: nowrap;
}
.btn-pdf-primary {
  background: var(--primary);
  color: #fff;
}
.btn-pdf-primary:hover { background: #e06900; }
.btn-pdf-ghost {
  background: rgba(255,255,255,.1);
  color: #e2e8f0; border: 1px solid rgba(255,255,255,.15);
}
.btn-pdf-ghost:hover { background: rgba(255,255,255,.2); }

/* ------------------------------------------------
   PAGE WRAPPER
------------------------------------------------ */
.pdf-page {
  max-width: 860px;
  margin: 40px auto;
  padding: 0 20px;
}
.pdf-paper {
  background: var(--white);
  border-radius: 12px;
  box-shadow: 0 4px 40px rgba(0,0,0,.1);
  overflow: hidden;
}

/* ------------------------------------------------
   ARTICLE HEADER
------------------------------------------------ */
.art-header {
  padding: 52px 60px 40px;
  border-bottom: 1px solid var(--border);
  position: relative;
}
.art-meta-top {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 18px;
}
.art-category {
  font-family: var(--sans);
  font-size: .72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .1em;
  color: var(--primary);
  background: #fff8f2;
  padding: 3px 10px;
  border-radius: 20px;
  border: 1px solid #fed7aa;
}
.art-date {
  font-family: var(--sans);
  font-size: .8rem;
  color: var(--muted);
}
.art-title {
  font-family: var(--display);
  font-size: 2.4rem;
  font-weight: 800;
  line-height: 1.2;
  color: var(--dark);
  margin-bottom: 18px;
  letter-spacing: -.02em;
}
.art-excerpt {
  font-size: 1.1rem;
  color: var(--muted);
  line-height: 1.7;
  font-style: italic;
  margin-bottom: 24px;
  font-family: var(--serif);
}
.art-author-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding-top: 20px;
  border-top: 1px solid var(--border);
}
.art-author-avatar {
  width: 42px; height: 42px;
  border-radius: 50%;
  background: var(--primary);
  color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-family: var(--sans); font-weight: 700; font-size: 1rem;
  flex-shrink: 0;
}
.art-author-info { line-height: 1.3; }
.art-author-name {
  font-family: var(--sans);
  font-size: .9rem;
  font-weight: 700;
  color: var(--dark);
}
.art-author-label {
  font-family: var(--sans);
  font-size: .75rem;
  color: var(--muted);
}

/* ------------------------------------------------
   FEATURED IMAGE
------------------------------------------------ */
.art-featured {
  width: 100%;
  max-height: 420px;
  object-fit: cover;
  display: block;
  border-top: 1px solid var(--border);
}

/* ------------------------------------------------
   ARTICLE BODY
------------------------------------------------ */
.art-body {
  padding: 52px 60px;
}

/* Block spacing */
.block-heading,
.block-paragraph,
.block-image,
.block-quote,
.block-table,
.block-list,
.block-callout,
.block-divider,
.block-columns,
.block-button {
  margin-bottom: 2.5rem;
}
.block-heading,
.block-divider { margin-bottom: 1.5rem; }

/* HEADINGS */
.ah {
  font-family: var(--display);
  font-weight: 700;
  line-height: 1.25;
  color: var(--dark);
  margin-bottom: .5rem;
}
.block-heading h1.ah { font-size: 2rem; border-bottom: 2px solid var(--primary); padding-bottom: .4rem; }
.block-heading h2.ah { font-size: 1.5rem; padding-left: .75rem; border-left: 4px solid var(--primary); }
.block-heading h3.ah { font-size: 1.2rem; color: #1e3a5f; }
.block-heading h4.ah { font-size: .95rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }

/* PARAGRAPHS */
.ap p { margin-bottom: 1rem; text-align: justify; font-size: 1.05rem; }
.ap p:last-child { margin-bottom: 0; }

/* IMAGE */
.ai { margin: 0; text-align: center; }
.ai img { max-width: 100%; border-radius: 10px; box-shadow: 0 4px 20px rgba(0,0,0,.1); }
.ai-left  { text-align: left; }
.ai-right { text-align: right; }
.ai-full img { width: 100%; border-radius: 0; }
.ai figcaption {
  margin-top: .5rem;
  font-size: .82rem;
  color: var(--muted);
  font-style: italic;
  font-family: var(--sans);
}

/* BLOCKQUOTE */
.aq {
  border-left: 5px solid var(--primary);
  background: #fff8f2;
  padding: 1.5rem 2rem;
  border-radius: 0 12px 12px 0;
  font-style: italic;
  margin: 0;
}
.aq p { font-size: 1.2rem; color: #374151; margin-bottom: .75rem; }
.aq p:last-child { margin-bottom: 0; }
.aq footer { margin-top: .75rem; font-style: normal; font-size: .875rem; font-family: var(--sans); }
.qa { font-weight: 700; color: var(--primary); }
.qs { color: var(--muted); }

/* TABLE */
.at-wrap {}
.at-cap {
  font-family: var(--sans);
  font-size: .78rem;
  font-weight: 700;
  letter-spacing: .05em;
  text-transform: uppercase;
  color: var(--muted);
  text-align: center;
  margin-bottom: .5rem;
}
.at-scroll { overflow-x: auto; border-radius: 10px; border: 1px solid var(--border); }
.at { width: 100%; border-collapse: collapse; font-size: .9rem; font-family: var(--sans); }
.at thead tr { background: #1e293b; color: #fff; }
.at thead th {
  padding: 12px 16px;
  text-align: left;
  font-size: .75rem;
  font-weight: 700;
  letter-spacing: .05em;
  text-transform: uppercase;
  white-space: nowrap;
}
.at tbody td {
  padding: 10px 16px;
  border-bottom: 1px solid #f1f5f9;
  font-family: var(--serif);
}
.at tbody tr:nth-child(even) td { background: #f8fafc; }
.at tbody tr:last-child td { border-bottom: none; }

/* LISTS */
.al { padding-left: 1.75rem; }
.al li { margin-bottom: .5rem; line-height: 1.8; }

/* CALLOUT */
.ac {
  border-radius: 10px;
  padding: 1rem 1.25rem;
  border-left: 5px solid;
  font-family: var(--sans);
}
.ac-info    { background: #eff6ff; border-color: #3b82f6; }
.ac-tip     { background: #f0fdf4; border-color: #22c55e; }
.ac-warning { background: #fffbeb; border-color: #f59e0b; }
.ac-danger  { background: #fef2f2; border-color: #ef4444; }
.ac-title { font-weight: 700; font-size: .9rem; margin-bottom: .4rem; }
.ac-body  { font-size: .9rem; line-height: 1.7; }

/* DIVIDER */
.adiv { margin: .5rem 0; }
.adiv-solid      { border-top: 2px solid #e2e8f0; }
.adiv-dashed     { border-top: 2px dashed #cbd5e1; }
.adiv-decorative {
  text-align: center;
  height: 20px;
  position: relative;
}
.adiv-decorative::after {
  content: '?  ?  ?';
  position: absolute; left: 50%;
  transform: translateX(-50%);
  color: var(--primary);
  font-size: 12px; letter-spacing: .5rem;
}

/* COLUMNS */
.acol { display: grid; grid-template-columns: 1fr 1fr; gap: 2.5rem; }
.acol-item { font-size: 1rem; line-height: 1.8; }

/* BUTTON */
.ab-wrap { text-align: center; }
.ab {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 12px 28px; border-radius: 8px;
  font-family: var(--sans); font-size: .9rem; font-weight: 700;
  text-decoration: none; transition: all .2s;
}
.ab-primary   { background: var(--primary); color: #fff; }
.ab-primary:hover { background: #e06900; }
.ab-secondary { background: #f1f5f9; color: var(--dark); border: 1.5px solid var(--border); }
.ab-outline   { color: var(--primary); border: 2px solid var(--primary); }
.ab-outline:hover { background: var(--primary); color: #fff; }

/* ------------------------------------------------
   FOOTER STRIP
------------------------------------------------ */
.art-footer {
  padding: 24px 60px;
  border-top: 1px solid var(--border);
  background: #f8fafc;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}
.art-footer-brand {
  font-family: var(--sans);
  font-size: .82rem;
  color: var(--muted);
}
.art-footer-brand strong { color: var(--dark); }
.art-footer-url {
  font-family: var(--sans);
  font-size: .75rem;
  color: var(--muted);
  word-break: break-all;
}

/* ------------------------------------------------
   PRINT / PDF STYLES
------------------------------------------------ */
@media print {
  @page {
    size: A4;
    margin: 15mm 18mm 20mm;
  }
  body { background: #fff; font-size: 10.5pt; }
  .pdf-chrome { display: none !important; }
  .pdf-page   { max-width: none; margin: 0; padding: 0; }
  .pdf-paper  { box-shadow: none; border-radius: 0; }
  .art-header { padding: 20pt 0 16pt; }
  .art-body   { padding: 20pt 0; }
  .art-footer { padding: 12pt 0; }
  .art-title  { font-size: 22pt; }
  .ah         { break-after: avoid; }
  .ap p       { orphans: 3; widows: 3; }
  .at-scroll  { overflow-x: visible; }
  .at         { font-size: 9pt; }
  .block-image,
  .block-table { break-inside: avoid; }
  .art-featured { max-height: 280pt; }
  .acol { gap: 1.5rem; }
  .ab-wrap { display: none; } /* Hide buttons in PDF */
  .art-footer-url { display: block; }

  /* Re-apply bg colors for print */
  .ac-info    { background: #eff6ff !important; }
  .ac-tip     { background: #f0fdf4 !important; }
  .ac-warning { background: #fffbeb !important; }
  .ac-danger  { background: #fef2f2 !important; }
  .aq         { background: #fff8f2 !important; }
  .at thead tr { background: #1e293b !important; color: #fff !important; }
  .at tbody tr:nth-child(even) td { background: #f8fafc !important; }
}

/* Responsive screen */
@media screen and (max-width: 680px) {
  .art-header, .art-body, .art-footer { padding-left: 24px; padding-right: 24px; }
  .art-title { font-size: 1.7rem; }
  .acol { grid-template-columns: 1fr; }
  .pdf-chrome { padding: 0 16px; }
  .pdf-chrome-actions .btn-pdf span { display: none; }
}
</style>
</head>
<body>

<!-- -- SCREEN CHROME (print toolbar) ----------------------- -->
<div class="pdf-chrome" aria-hidden="true" id="pdfChrome">
  <div class="pdf-chrome-brand">
    <?php if ($site_logo && file_exists($site_logo)): ?>
      <img src="<?= SITE_URL . '/' . h($site_logo) ?>" class="pdf-chrome-logo" alt="<?= h($site_name) ?>">
    <?php else: ?>
      <div class="pdf-chrome-logo-icon"><?= mb_strtoupper(mb_substr($site_name, 0, 1)) ?></div>
    <?php endif; ?>
    <span class="pdf-chrome-site"><?= h($site_name) ?></span>
  </div>
  <div class="pdf-chrome-actions">
    <a href="blogs.php?slug=<?= h($blog['slug']) ?>" class="btn-pdf btn-pdf-ghost">
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>
      <span>Back to Article</span>
    </a>
    <button class="btn-pdf btn-pdf-primary" onclick="window.print()">
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      <span>Print / Save PDF</span>
    </button>
  </div>
</div>

<!-- -- ARTICLE PAPER ---------------------------------------- -->
<main class="pdf-page" role="main">
<article class="pdf-paper">

  <!-- HEADER -->
  <header class="art-header">
    <div class="art-meta-top">
      <?php if ($category): ?><span class="art-category"><?= $category ?></span><?php endif; ?>
      <?php if ($published): ?><span class="art-date"><?= h($published) ?></span><?php endif; ?>
    </div>
    <h1 class="art-title"><?= pdf_h($blog['title']) ?></h1>
    <?php if (!empty($blog['excerpt'])): ?>
      <p class="art-excerpt"><?= pdf_h($blog['excerpt']) ?></p>
    <?php endif; ?>
    <div class="art-author-row">
      <div class="art-author-avatar"><?= mb_strtoupper(mb_substr(strip_tags($author), 0, 1)) ?></div>
      <div class="art-author-info">
        <div class="art-author-name"><?= $author ?></div>
        <div class="art-author-label"><?= h($site_name) ?></div>
      </div>
    </div>
  </header>

  <!-- FEATURED IMAGE -->
  <?php if ($featured_img): ?>
    <img class="art-featured" src="<?= $featured_img ?>" alt="<?= pdf_h($blog['title']) ?>">
  <?php endif; ?>

  <!-- BODY -->
  <div class="art-body">
    <?php if (!empty($sections)): ?>
      <?= render_sections($sections) ?>
    <?php elseif (!empty($blog['content'])): ?>
      <div class="ap"><?= $blog['content'] ?></div>
    <?php else: ?>
      <p style="color:#64748b;font-style:italic">No content available.</p>
    <?php endif; ?>
  </div>

  <!-- FOOTER -->
  <footer class="art-footer">
    <div class="art-footer-brand">
      Published by <strong><?= h($site_name) ?></strong><?= $published ? ' . ' . h($published) : '' ?>
    </div>
   <!-- <div class="art-footer-url">
      <?= h(rtrim(SITE_URL, '/')) ?>/blog.php?slug=<?= h($blog['slug']) ?>
    </div> -->
  </footer>

</article>
</main>

</body>
</html>
