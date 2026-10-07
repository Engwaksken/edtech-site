<?php
require_once __DIR__ . '/includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

function nlv_setting(mysqli $conn, string $key, string $default = ''): string {
    if (function_exists('get_setting')) {
        return (string)get_setting($conn, $key, $default);
    }

    $stmt = $conn->prepare("SELECT setting_value FROM site_settings WHERE setting_key=? LIMIT 1");
    if (!$stmt) return $default;

    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (string)($row['setting_value'] ?? $default);
}

function nlv_public_url(string $path): string {
    $path = trim($path);
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    return rtrim(defined('SITE_URL') ? SITE_URL : '', '/') . '/' . ltrim($path, '/');
}

function nlv_make_assets_absolute(string $html): string {
    if ($html === '' || !defined('SITE_URL')) return $html;
    $base = rtrim(SITE_URL, '/') . '/';

    $html = preg_replace_callback('/\s(src|href)=("|\')(\/?uploads\/[^"\']+)("|\')/i', function ($m) use ($base) {
        return ' ' . $m[1] . '=' . $m[2] . $base . ltrim($m[3], '/') . $m[4];
    }, $html);

    $html = preg_replace_callback('/url\(("|\')?(\/?uploads\/[^)"\']+)("|\')?\)/i', function ($m) use ($base) {
        $quote = $m[1] ?: "'";
        return 'url(' . $quote . $base . ltrim($m[2], '/') . $quote . ')';
    }, $html);

    return $html;
}

function nlv_extract_body(string $html): string {
    if ($html === '') return '';
    if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $m)) {
        return $m[1];
    }
    return $html;
}

function nlv_pdf_path(array $nl): string {
    if (!empty($nl['pdf_path'])) return (string)$nl['pdf_path'];
    if (!empty($nl['pdf_file'])) return (string)$nl['pdf_file'];
    return '';
}

$site_name = nlv_setting($conn, 'site_name', 'Newsletter');
$site_logo = nlv_setting($conn, 'site_logo', '');

$id = (int)($_GET['id'] ?? 0);

$stmt = $conn->prepare("SELECT * FROM newsletters WHERE id=? AND status='sent' LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    die('DB error.');
}
$stmt->bind_param('i', $id);
$stmt->execute();
$newsletter = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$newsletter) {
    http_response_code(404);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Newsletter Not Found</title>
<?php require_once __DIR__ . '/includes/favicon.php'; ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<style>
body{margin:0;font-family:Arial,sans-serif;background:#f6f8fc;color:#111827}.not-found{min-height:100vh;display:flex;align-items:center;justify-content:center;text-align:center;padding:24px}.box{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:42px;max-width:520px;box-shadow:0 12px 38px rgba(15,23,42,.08)}.box i{font-size:46px;color:#cbd5e1;margin-bottom:15px}.box h1{margin:0 0 10px;font-size:28px}.box p{color:#64748b;line-height:1.6}.btn{display:inline-flex;gap:8px;align-items:center;background:#f97316;color:#fff;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:700;margin-top:10px}
</style>
</head>
<body>
<div class="not-found"><div class="box"><i class="fa fa-envelope-open"></i><h1>Newsletter Not Found</h1><p>This newsletter is unavailable or has not been published yet.</p><a class="btn" href="newsletter.php"><i class="fa fa-arrow-left"></i> Back to Newsletters</a></div></div>
</body>
</html>
<?php
    exit;
}

$subject   = trim((string)($newsletter['subject'] ?? 'Newsletter')) ?: 'Newsletter';
$preheader = trim((string)($newsletter['preheader'] ?? ''));
$sent_at   = !empty($newsletter['sent_at']) ? date('F j, Y', strtotime((string)$newsletter['sent_at'])) : '';
$canonical = rtrim(defined('SITE_URL') ? SITE_URL : '', '/') . '/newsletter-view.php?id=' . $id;
$pdf_path  = nlv_pdf_path($newsletter);
$pdf_url   = $pdf_path !== '' ? nlv_public_url($pdf_path) : '';

$body_html = (string)($newsletter['body_html'] ?? '');
$body_html = nlv_make_assets_absolute($body_html);
$body_html = nlv_extract_body($body_html);

// Remove unsubscribe/footer text that belongs to email clients only, when possible.
$body_html = preg_replace('/<div[^>]*>\s*<p>[^<]*receiving this because[^<]*<\/p>\s*<p>\s*<a[^>]*>Unsubscribe<\/a>\s*<\/p>\s*<\/div>/is', '', $body_html);

$logo_url = $site_logo !== '' ? nlv_public_url($site_logo) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($subject) ?> - <?= h($site_name) ?></title>
<?php require_once __DIR__ . '/includes/favicon.php'; ?>
<meta name="description" content="<?= h($preheader ?: $subject) ?>">
<meta property="og:title" content="<?= h($subject) ?>">
<meta property="og:description" content="<?= h($preheader ?: $subject) ?>">
<meta property="og:url" content="<?= h($canonical) ?>">
<meta property="og:type" content="article">
<link rel="canonical" href="<?= h($canonical) ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<style>
:root{--accent:#f97316;--ink:#111827;--muted:#64748b;--line:#e5e7eb;--bg:#f3f4f6;--card:#ffffff}
*{box-sizing:border-box}html,body{margin:0;min-height:100%;scroll-behavior:smooth}body{font-family:Arial,Helvetica,sans-serif;background:var(--bg);color:var(--ink)}
.reader-top{position:sticky;top:0;z-index:50;background:rgba(255,255,255,.96);backdrop-filter:blur(12px);border-bottom:1px solid var(--line)}
.reader-top-inner{max-width:1040px;margin:0 auto;padding:12px 18px;display:flex;align-items:center;justify-content:space-between;gap:14px}
.reader-brand{display:flex;align-items:center;gap:10px;min-width:0;text-decoration:none;color:var(--ink)}
.reader-brand img{width:34px;height:34px;object-fit:contain}.reader-brand-mark{width:34px;height:34px;border-radius:10px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900}.reader-brand span{font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.reader-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.reader-btn{border:1px solid var(--line);background:#fff;color:var(--ink);border-radius:999px;padding:8px 12px;font-size:13px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:7px;cursor:pointer}.reader-btn:hover{border-color:var(--accent);color:var(--accent)}
.reader-btn-primary{background:var(--accent);border-color:var(--accent);color:#fff}.reader-btn-primary:hover{color:#fff;filter:brightness(1.05)}
.reader-main{padding:26px 14px 54px}.reader-shell{max-width:900px;margin:0 auto}.reader-title-card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:22px 24px;margin-bottom:18px;box-shadow:0 12px 32px rgba(15,23,42,.06)}
.reader-kicker{font-size:12px;color:var(--accent);font-weight:900;text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px}.reader-title-card h1{font-size:clamp(24px,4vw,42px);line-height:1.12;margin:0 0 10px}.reader-preheader{font-size:16px;line-height:1.6;color:var(--muted);margin:0 0 12px}.reader-date{font-size:13px;color:#94a3b8;display:flex;gap:8px;align-items:center}
.newsletter-paper{background:#fff;min-height:100vh;box-shadow:0 16px 45px rgba(15,23,42,.10);overflow:visible;border-radius:4px}.newsletter-paper img{max-width:100%;height:auto}.newsletter-paper a{word-break:break-word}.newsletter-paper table{max-width:100%}
.pdf-reader{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px;text-align:center}.pdf-reader h2{margin-top:0}.pdf-actions{display:flex;justify-content:center;gap:10px;flex-wrap:wrap;margin:18px 0}.pdf-frame{width:100%;height:78vh;border:1px solid var(--line);border-radius:12px;background:#f8fafc}
.reader-nav{max-width:900px;margin:20px auto 0;display:flex;justify-content:center;gap:10px;flex-wrap:wrap}.copy-ok{background:#16a34a!important;border-color:#16a34a!important;color:#fff!important}
@media(max-width:700px){.reader-top-inner{align-items:flex-start;flex-direction:column}.reader-actions{width:100%}.reader-btn{flex:1;justify-content:center}.reader-main{padding:14px 0 34px}.reader-title-card{border-radius:0;margin-bottom:0}.newsletter-paper{border-radius:0;box-shadow:none}.reader-shell{max-width:100%}.pdf-frame{height:70vh}}
@media print{.reader-top,.reader-title-card,.reader-nav{display:none!important}.reader-main{padding:0;background:#fff}.newsletter-paper{box-shadow:none}.pdf-frame{height:100vh}}
</style>
</head>
<body>
<header class="reader-top">
  <div class="reader-top-inner">
    <a class="reader-brand" href="newsletter.php">
      <?php if ($logo_url): ?><img src="<?= h($logo_url) ?>" alt="<?= h($site_name) ?>"><?php else: ?><span class="reader-brand-mark"><?= h(mb_strtoupper(mb_substr($site_name,0,1))) ?></span><?php endif; ?>
      <span><?= h($site_name) ?></span>
    </a>
    <div class="reader-actions">
      <a class="reader-btn" href="newsletter.php"><i class="fa fa-arrow-left"></i> Archive</a>
      <button class="reader-btn" type="button" onclick="copyNewsletterLink(this)"><i class="fa fa-link"></i> Copy Link</button>
      <button class="reader-btn" type="button" onclick="window.print()"><i class="fa fa-print"></i> Print</button>
      <?php if ($pdf_url): ?><a class="reader-btn reader-btn-primary" href="<?= h($pdf_url) ?>" target="_blank"><i class="fa fa-file-pdf"></i> Open PDF</a><?php endif; ?>
    </div>
  </div>
</header>

<main class="reader-main">
  <div class="reader-shell">
    <section class="reader-title-card">
      <div class="reader-kicker">Newsletter</div>
      <h1><?= h($subject) ?></h1>
      <?php if ($preheader): ?><p class="reader-preheader"><?= h($preheader) ?></p><?php endif; ?>
      <?php if ($sent_at): ?><div class="reader-date"><i class="fa fa-calendar-alt"></i> <?= h($sent_at) ?></div><?php endif; ?>
    </section>

    <?php if ($pdf_url): ?>
      <section class="pdf-reader">
        <h2><?= h($subject) ?></h2>
        <?php if ($preheader): ?><p><?= h($preheader) ?></p><?php endif; ?>
        <div class="pdf-actions">
          <a class="reader-btn reader-btn-primary" href="<?= h($pdf_url) ?>" target="_blank"><i class="fa fa-file-pdf"></i> Open PDF</a>
          <a class="reader-btn" href="<?= h($pdf_url) ?>" download><i class="fa fa-download"></i> Download</a>
        </div>
        <iframe class="pdf-frame" src="<?= h($pdf_url) ?>" title="<?= h($subject) ?>"></iframe>
      </section>
    <?php else: ?>
      <article class="newsletter-paper">
        <?php if (trim($body_html) !== ''): ?>
          <?= $body_html ?>
        <?php else: ?>
          <div style="padding:60px 24px;text-align:center;color:#64748b"><i class="fa fa-envelope-open" style="font-size:42px;color:#cbd5e1"></i><p>This newsletter has no content to display.</p></div>
        <?php endif; ?>
      </article>
    <?php endif; ?>
  </div>
</main>

<nav class="reader-nav">
<?php
$prev = null;
$next = null;
$pstmt = $conn->prepare("SELECT id, subject FROM newsletters WHERE status='sent' AND id < ? ORDER BY id DESC LIMIT 1");
if ($pstmt) { $pstmt->bind_param('i', $id); $pstmt->execute(); $prev = $pstmt->get_result()->fetch_assoc(); $pstmt->close(); }
$nstmt = $conn->prepare("SELECT id, subject FROM newsletters WHERE status='sent' AND id > ? ORDER BY id ASC LIMIT 1");
if ($nstmt) { $nstmt->bind_param('i', $id); $nstmt->execute(); $next = $nstmt->get_result()->fetch_assoc(); $nstmt->close(); }
?>
  <?php if ($prev): ?><a class="reader-btn" href="newsletter-view.php?id=<?= (int)$prev['id'] ?>"><i class="fa fa-arrow-left"></i> Previous</a><?php endif; ?>
  <?php if ($next): ?><a class="reader-btn" href="newsletter-view.php?id=<?= (int)$next['id'] ?>">Next <i class="fa fa-arrow-right"></i></a><?php endif; ?>
</nav>

<script>
const NEWSLETTER_URL = <?= json_encode($canonical) ?>;
function copyNewsletterLink(btn){
  const old = btn.innerHTML;
  function done(){ btn.classList.add('copy-ok'); btn.innerHTML='<i class="fa fa-check"></i> Copied'; setTimeout(()=>{btn.classList.remove('copy-ok'); btn.innerHTML=old;},1800); }
  if(navigator.clipboard && navigator.clipboard.writeText){ navigator.clipboard.writeText(NEWSLETTER_URL).then(done).catch(fallback); } else { fallback(); }
  function fallback(){ const t=document.createElement('textarea'); t.value=NEWSLETTER_URL; t.style.position='fixed'; t.style.opacity='0'; document.body.appendChild(t); t.select(); document.execCommand('copy'); document.body.removeChild(t); done(); }
}
</script>
</body>
</html>
