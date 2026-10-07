<?php
if (!isset($conn)) { require_once __DIR__ . '/config.php'; }
$footer_about = get_setting($conn, 'footer_about', 'Hive Colab is Uganda\'s first innovation hub and a leading entrepreneurship support organisation with over a decade of experience supporting technology-driven ventures.');
$site_name    = get_setting($conn, 'site_name', 'Mastercard Foundation EdTech Fellowship');
$site_email   = get_setting($conn, 'site_email', 'edtech@hivecolab.com');
$site_location= get_setting($conn, 'site_location', 'Kampala, Uganda');
?>


<section class="hive-colab">
  <div class="container">
    <div class="hive-content">
      <h2>About Hive Colab</h2>
      <p><?= h($footer_about) ?></p>
      <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:8px">
        <a href="https://www.hivecolab.org" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm"><i class="fa fa-external-link-alt"></i> Hive Colab Website</a>
      </div>
    </div>
  </div>
</section>


<footer class="footer">
  <div class="container">
    <div class="footer-grid">
      <div class="footer-brand">
       <div class="footer-logos">
    <img 
        src="<?= h(asset_url('assets/images/logo_white.png')) ?>"
        alt="Hive Colab" 
        class="footer-logo"
         loading="lazy" decoding="async" onerror="this.style.display='none'">

    <img 
        src="<?= h(asset_url('assets/images/foundation_white.png')) ?>"
        alt="Mastercard Foundation" 
        class="footer-logo"
         loading="lazy" decoding="async" onerror="this.style.display='none'">
</div>
        <p>The Mastercard Foundation EdTech Fellowship supports growth stage EdTech ventures building inclusive learning solutions in Uganda.</p>
        <div class="footer-social">
          <a href="mailto:<?= h($site_email) ?>" title="Email" aria-label="Email the fellowship team"><i class="fa fa-envelope"></i></a>
          <a href="https://twitter.com/hivecolab" target="_blank" rel="noopener noreferrer" title="Twitter" aria-label="Hive Colab on Twitter"><i class="fab fa-twitter"></i></a>
          <a href="https://linkedin.com/company/hive-colab" target="_blank" rel="noopener noreferrer" title="LinkedIn" aria-label="Hive Colab on LinkedIn"><i class="fab fa-linkedin-in"></i></a>
        </div>
      </div>

      <div class="footer-col">
        <h4>Quick Links</h4>
        <ul>
          <li><a href="<?= SITE_URL ?>/#about">About the Fellowship</a></li>
          <li><a href="<?= SITE_URL ?>/#program">Programme Structure</a></li>
          <li><a href="<?= SITE_URL ?>/#eligibility">Eligibility</a></li>
          <li><a href="<?= SITE_URL ?>/#benefits">What Fellows Receive</a></li>
          <li><a href="<?= SITE_URL ?>/cohorts">Cohorts & Startups</a></li>
          <li><a href="<?= SITE_URL ?>/faqs">FAQs</a></li>
        </ul>
      </div>

      <div class="footer-col">
        <h4>Contact</h4>
        <ul>
          <li><i class="fa fa-envelope"></i> <a href="mailto:<?= h($site_email) ?>"><?= h($site_email) ?></a></li>
          <li><i class="fa fa-map-marker-alt"></i> <?= h($site_location) ?></li>
        </ul>
       
      </div>
    </div>

    <div class="footer-bottom">
      <p>© <?= date('Y') ?> <?= h($site_name) ?>. Implemented by <a href="https://hivecolab.com" target="_blank" rel="noopener noreferrer">Hive Colab</a> in partnership with the <a href="https://mastercardfdn.org" target="_blank" rel="noopener noreferrer">Mastercard Foundation</a>.</p>
     
    </div>
  </div>
</footer>

<!-- JS -->
<script src="<?= h(SITE_URL) ?>/assets/js/script.js?v=<?= (int)filemtime(__DIR__ . '/../assets/js/script.js') ?>" defer></script>
</body>
</html>
