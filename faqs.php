<?php
require_once 'includes/config.php';
$faqs = mysqli_query($conn, "SELECT * FROM faqs WHERE status=1 ORDER BY sort_order ASC, id ASC");
include 'includes/header.php';
?>
<div style="padding-top:72px">
  <!-- Hero -->
  <section style="background:linear-gradient(135deg,#000000,#222222);padding:72px 0 56px">
    <div class="container">
      <span class="section-tag" style="background:rgba(252,127,16,.2);color:#fc7f10">Support</span>
      <h1 style="margin:14px 0 10px;font-family:'Outfit',serif;color:#fff;font-size:clamp(2rem,5vw,3.5rem)">Frequently Asked Questions</h1>
      <p style="max-width:580px;color:rgba(255,255,255,.8);font-size:1.05rem">Key information about the Mastercard Foundation EdTech Fellowship and the application process.</p>
    </div>
  </section>

  <section style="padding:60px 0;background:#f8f9fa">
    <div class="container" style="max-width:800px">
      <?php
      $has = false;
      while ($faq = mysqli_fetch_assoc($faqs)):
        $has = true;
      ?>
      <div class="faq-item">
        <button type="button" class="faq-question" aria-expanded="false" aria-controls="faq-answer-<?= (int)$faq['id'] ?>" onclick="toggleFaq(this)">
          <?= h($faq['question']) ?>
          <span class="faq-icon"><i class="fa fa-plus"></i></span>
        </button>
        <div class="faq-answer" id="faq-answer-<?= (int)$faq['id'] ?>" hidden>
          <p><?= nl2br(h($faq['answer'])) ?></p>
        </div>
      </div>
      <?php endwhile; ?>

      <?php if (!$has): ?>
      <div style="text-align:center;padding:60px 20px;color:#6c757d">
        <i class="fa fa-question-circle" style="font-size:3rem;opacity:.3;display:block;margin-bottom:14px"></i>
        <h3>No FAQs available yet</h3>
        <p>Check back soon or <a href="index.php#contact" style="color:var(--primary-color)">contact us</a> directly.</p>
      </div>
      <?php endif; ?>

      <div style="background:linear-gradient(135deg,#fc7f10,#e06900);border-radius:12px;padding:32px;margin-top:40px;text-align:center;color:#fff">
        <h3 style="margin:0 0 10px">Still have questions?</h3>
        <p style="opacity:.9;margin-bottom:18px">Our team is happy to help you understand the fellowship program.</p>
        <a href="index.php#contact" class="btn btn-secondary"><i class="fa fa-envelope"></i> Contact Us</a>
      </div>
    </div>
  </section>
</div>

<style>
.faq-item { background:#fff;border-radius:10px;margin-bottom:12px;border:1px solid #e9ecef;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.05); }
.faq-question { width:100%;background:none;border:none;text-align:left;padding:20px 24px;font-size:1rem;font-weight:600;font-family:inherit;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:16px;transition:background .2s; }
.faq-question:hover { background:#fafafa; }
.faq-question.open { color:#fc7f10; }
.faq-icon { width:24px;height:24px;border-radius:50%;background:#f0f0f0;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;transition:all .3s; }
.faq-question.open .faq-icon { background:#fc7f10;color:#fff;transform:rotate(45deg); }
.faq-answer { max-height:0;overflow:hidden;transition:max-height .3s ease,padding .3s; }
.faq-answer.open { max-height:none;padding:0 24px 20px; }
.faq-answer p { color:#555;line-height:1.8;margin:0; }
</style>
<script>
function toggleFaq(btn){
  const answer=btn.nextElementSibling;
  const isOpen=btn.classList.contains('open');
  document.querySelectorAll('.faq-question.open').forEach(b=>{b.classList.remove('open');b.setAttribute('aria-expanded','false');b.nextElementSibling.classList.remove('open');b.nextElementSibling.hidden=true;});
  if(!isOpen){btn.classList.add('open');btn.setAttribute('aria-expanded','true');answer.hidden=false;answer.classList.add('open');}
}
</script>

<?php include 'includes/footer.php'; ?>
