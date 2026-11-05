<?php
include __DIR__ . '/includes/header.php';


$ims_default = 'https://ims.hivecolab.com/opportunity-embed.php?id=5';
$ims_url = function_exists('get_setting') ? trim(get_setting($conn, 'integration_ims_url', $ims_default)) : $ims_default;
if (!filter_var($ims_url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($ims_url, PHP_URL_SCHEME)) !== 'https') {
    $ims_url = $ims_default;
}
$ims_origin = 'https://' . strtolower((string) parse_url($ims_url, PHP_URL_HOST))
    . (parse_url($ims_url, PHP_URL_PORT) ? ':' . parse_url($ims_url, PHP_URL_PORT) : '');
?>

<section class="apply-embed" id="applyEmbed" data-state="loading">
    <div class="apply-embed__status" id="applyLoading" role="status" aria-live="polite">
        <span class="apply-embed__spinner" aria-hidden="true"></span>
        <p>Loading the application form&hellip;</p>
    </div>

    <div class="apply-embed__status apply-embed__fallback" id="applyFallback" hidden>
        <i class="fas fa-file-pen apply-embed__icon" aria-hidden="true"></i>
        <h2>Apply for the fellowship</h2>
        <p>The application form opens on our application portal. Select the button below to view the details and apply.</p>
        <a class="apply-embed__btn" href="<?= h($ims_url) ?>" target="_blank" rel="noopener">
            <i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i>
            Open the application form
        </a>
        <p class="apply-embed__help">Having trouble? Email us at <a href="mailto:<?= h($site_email) ?>"><?= h($site_email) ?></a>.</p>
    </div>

    <iframe
        id="applyFrame"
        class="apply-embed__frame"
        src="<?= h($ims_url) ?>"
        title="Fellowship application form"
        loading="eager"
        aria-hidden="true"
        tabindex="-1"></iframe>
</section>

<style>
.apply-embed { position:relative; max-width:1200px; margin:0 auto; padding:24px 16px 48px; min-height:420px; }
.apply-embed__frame { display:block; width:100%; height:900px; border:0; }
.apply-embed[data-state="loading"] .apply-embed__frame,
.apply-embed[data-state="fallback"] .apply-embed__frame { height:0 !important; visibility:hidden; }
.apply-embed[data-state="ready"] .apply-embed__status { display:none; }
.apply-embed__status { text-align:center; padding:64px 16px; color:#555; }
.apply-embed__spinner { display:inline-block; width:40px; height:40px; border:4px solid #fde3cc; border-top-color:#fc7f10; border-radius:50%; animation:applySpin 1s linear infinite; }
@keyframes applySpin { to { transform:rotate(360deg); } }
.apply-embed__fallback { max-width:560px; margin:0 auto; background:#fff; border-radius:16px; box-shadow:0 8px 30px rgba(0,0,0,.08); padding:48px 32px; }
.apply-embed__icon { font-size:40px; color:#fc7f10; margin-bottom:16px; }
.apply-embed__fallback h2 { margin:0 0 12px; color:#222; }
.apply-embed__fallback p { line-height:1.7; margin:0 0 24px; }
.apply-embed__btn { display:inline-flex; align-items:center; gap:8px; background:#fc7f10; color:#fff; padding:14px 28px; border-radius:10px; font-weight:600; text-decoration:none; }
.apply-embed__btn:hover, .apply-embed__btn:focus { background:#e06d05; color:#fff; }
.apply-embed__help { font-size:14px; margin:24px 0 0 !important; }
@media (prefers-reduced-motion: reduce) { .apply-embed__spinner { animation:none; } }
</style>

<script>
(function () {
    var wrap = document.getElementById('applyEmbed');
    var frame = document.getElementById('applyFrame');
    var loading = document.getElementById('applyLoading');
    var fallback = document.getElementById('applyFallback');
    var imsOrigin = <?= json_encode($ims_origin) ?>;
    var settled = false;

    function showForm(height) {
        if (height > 200) frame.style.height = Math.min(height + 20, 20000) + 'px';
        if (settled) return;
        settled = true;
        wrap.setAttribute('data-state', 'ready');
        frame.removeAttribute('aria-hidden');
        frame.removeAttribute('tabindex');
    }

    function showFallback() {
        if (settled) return;
        settled = true;
        wrap.setAttribute('data-state', 'fallback');
        loading.hidden = true;
        fallback.hidden = false;
        frame.removeAttribute('src');
    }

    // The form announces itself once it has loaded. If the browser blocks
    // the frame or the portal is down, that message never arrives and the
    // visitor gets a link to open the form directly instead of an error.
    window.addEventListener('message', function (e) {
        if (e.origin !== imsOrigin || e.source !== frame.contentWindow) return;
        var d = e.data || {};
        if (d.type === 'ims-embed' && d.status === 'ready') showForm(Number(d.height) || 0);
    });

    setTimeout(showFallback, 8000);
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
