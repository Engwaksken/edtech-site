
(function () {
  'use strict';

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email || '').trim());
  }

  function showNotification(message, type = 'success') {
    $('.notification')?.remove();

    const note = document.createElement('div');
    note.className = `notification ${type}`;
    note.setAttribute('role', type === 'error' ? 'alert' : 'status');
    note.textContent = message;

    document.body.appendChild(note);
    setTimeout(() => {
      note.classList.add('is-leaving');
      note.addEventListener('animationend', () => note.remove(), { once: true });
      setTimeout(() => note.remove(), 400);
    }, 5000);
  }

  function initNavbar() {
    const navbar = $('#navbar');
    const toggle = $('#mobileToggle') || $('#navToggle');
    const menu = $('#navMenu');

    if (navbar) {
      const updateNavbar = () => navbar.classList.toggle('scrolled', window.scrollY > 40);
      updateNavbar();
      window.addEventListener('scroll', updateNavbar, { passive: true });
    }

    if (!toggle || !menu) return;

    const navLinks = $$('a', menu);
    const submenuToggles = $$('.submenu-toggle');
    let lastFocusedElement = null;
    let focusTrapBound = false;

    function closeAllSubmenus() {
      submenuToggles.forEach((btn) => {
        btn.setAttribute('aria-expanded', 'false');
        btn.closest('.has-submenu')?.classList.remove('submenu-open');
      });
    }

    function getFocusableElements(container) {
      return $$(
        'a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
        container
      ).filter((el) => el.offsetParent !== null || el === document.activeElement);
    }

    function handleFocusTrap(e) {
      if (e.key !== 'Tab' || !menu.classList.contains('active')) return;

      const focusable = getFocusableElements(menu);
      if (!focusable.length) return;

      const first = focusable[0];
      const last = focusable[focusable.length - 1];

      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    }

    function openMenu() {
      lastFocusedElement = document.activeElement;
      toggle.classList.add('active');
      menu.classList.add('active', 'open');
      toggle.setAttribute('aria-expanded', 'true');
      document.body.classList.add('nav-open');
      document.body.style.overflow = 'hidden';

      if (!focusTrapBound) {
        menu.addEventListener('keydown', handleFocusTrap);
        focusTrapBound = true;
      }

      const firstFocusable = getFocusableElements(menu)[0];
      firstFocusable?.focus();
    }

    function closeMenu() {
      toggle.classList.remove('active');
      menu.classList.remove('active', 'open');
      toggle.setAttribute('aria-expanded', 'false');
      document.body.classList.remove('nav-open');
      document.body.style.overflow = '';
      closeAllSubmenus();

      if (focusTrapBound) {
        menu.removeEventListener('keydown', handleFocusTrap);
        focusTrapBound = false;
      }

      if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
        lastFocusedElement.focus();
      }
    }

    toggle.addEventListener('click', () => {
      if (menu.classList.contains('active') || menu.classList.contains('open')) closeMenu();
      else openMenu();
    });

    submenuToggles.forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();

        const parent = btn.closest('.has-submenu');
        const expanded = btn.getAttribute('aria-expanded') === 'true';

        closeAllSubmenus();

        if (!expanded && parent) {
          btn.setAttribute('aria-expanded', 'true');
          parent.classList.add('submenu-open');
        }
      });
    });

    document.addEventListener('click', (e) => {
      if (!e.target.closest('.has-submenu')) closeAllSubmenus();

      if (
        menu.classList.contains('active') &&
        !e.target.closest('#navMenu') &&
        !e.target.closest('#mobileToggle') &&
        !e.target.closest('#navToggle')
      ) {
        closeMenu();
      }
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        if (menu.classList.contains('active') || menu.classList.contains('open')) closeMenu();
        closeAllSubmenus();
      }
    });

    navLinks.forEach((link) => {
      link.addEventListener('click', (e) => {
        const href = link.getAttribute('href') || '';

        if (href.startsWith('#') && href.length > 1) {
          const target = document.querySelector(href);
          if (target) {
            e.preventDefault();
            const offset = navbar ? navbar.offsetHeight : 0;
            const top = target.getBoundingClientRect().top + window.scrollY - offset;
            window.scrollTo({ top, behavior: 'smooth' });
          }
        }

        if (menu.classList.contains('active') || menu.classList.contains('open')) {
          closeMenu();
        }
      });
    });

    function setActiveLink() {
      const sections = $$('section[id]');
      const offset = (navbar ? navbar.offsetHeight : 0) + 100;

      let activeId = '';
      sections.forEach((section) => {
        const top = section.offsetTop - offset;
        const bottom = top + section.offsetHeight;
        if (window.scrollY >= top && window.scrollY < bottom) activeId = section.id;
      });

      navLinks.forEach((link) => {
        const href = link.getAttribute('href') || '';
        const url = new URL(href, window.location.href);
        if (url.origin === window.location.origin && url.pathname === window.location.pathname && url.hash) {
          link.classList.toggle('active', Boolean(activeId) && url.hash === `#${activeId}`);
        }
      });
    }

    setActiveLink();
    window.addEventListener('scroll', setActiveLink, { passive: true });
  }

  /* ============================================================
     FAQ Accordion
  ============================================================ */
  function initFaq() {
    $$('.faq-item').forEach((item) => {
      const question = $('.faq-question', item);
      const answer = $('.faq-answer', item);
      const content = $('.faq-answer-content', item);

      if (!question || !answer || !content) return;

      question.addEventListener('click', () => {
        const open = item.classList.contains('active');

        $$('.faq-item').forEach((i) => {
          i.classList.remove('active');
          const a = $('.faq-answer', i);
          if (a) a.style.maxHeight = null;
        });

        if (!open) {
          item.classList.add('active');
          answer.style.maxHeight = `${content.scrollHeight}px`;
        }
      });
    });
  }

  function initContactForm() {
    const form = $('#contactForm');
    if (!form) return;

    const msgEl = $('#formMsg');
    const sendBtn = $('#sendBtn') || form.querySelector('button[type="submit"]');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      const name = ($('#name')?.value || form.querySelector('[name="name"]')?.value || '').trim();
      const email = ($('#email')?.value || form.querySelector('[name="email"]')?.value || '').trim();
      const subject = ($('#subject')?.value || form.querySelector('[name="subject"]')?.value || '').trim();
      const message = ($('#message')?.value || form.querySelector('[name="message"]')?.value || '').trim();

      if (!name || !email || !subject || !message) {
        showNotification('Please fill in all fields.', 'error');
        return;
      }

      if (!isValidEmail(email)) {
        showNotification('Please enter a valid email address.', 'error');
        return;
      }

      const originalBtnHtml = sendBtn ? sendBtn.innerHTML : '';

      if (sendBtn) {
        sendBtn.disabled = true;
        sendBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Sending...';
      }

      try {
        const response = await fetch(form.action, {
          method: 'POST',
          body: new FormData(form),
          headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
        });

        const text = await response.text();
        let data = null;

        try {
          data = JSON.parse(text);
        } catch (_) {
          data = { success: false, message: 'We could not confirm your message was sent. Please try again.' };
        }

        if (msgEl) {
          msgEl.style.display = 'block';
          msgEl.className = 'alert ' + (data.success ? 'alert-success' : 'alert-error');
          msgEl.textContent = data.message || (data.success ? 'Message sent successfully!' : 'Unable to send message.');
        }

        showNotification(data.message || (data.success ? 'Message sent successfully!' : 'Unable to send message.'), data.success ? 'success' : 'error');

        if (data.success) {
          form.reset();
          if (msgEl) setTimeout(() => { msgEl.style.display = 'none'; }, 7000);
        }
      } catch (err) {
        if (msgEl) {
          msgEl.style.display = 'block';
          msgEl.className = 'alert alert-error';
          msgEl.textContent = 'Network error. Please try again.';
        }
        showNotification('Network error. Please try again.', 'error');
      } finally {
        if (sendBtn) {
          sendBtn.disabled = false;
          sendBtn.innerHTML = originalBtnHtml || '<i class="fa fa-paper-plane"></i> Send Message';
        }
      }
    });
  }

  /* ============================================================
     Newsletter Reader Responsiveness
     Fixes squeezed two-column email sections on phones.
  ============================================================ */
  function initNewsletterReader() {
    const frame = $('#emailFrame');

    function patchNewsletterDocument(doc) {
      if (!doc || !doc.head) return;

      if (doc.getElementById('nl-mobile-reader-fix')) return;

      const style = doc.createElement('style');
      style.id = 'nl-mobile-reader-fix';
      style.textContent = `
        html, body {
          width: 100% !important;
          max-width: 100% !important;
          overflow-x: hidden !important;
        }

        img {
          max-width: 100% !important;
          height: auto !important;
        }

        @media (max-width: 700px) {
          body {
            margin: 0 !important;
            padding: 0 !important;
          }

          .wrap {
            width: 100% !important;
            max-width: 100% !important;
            margin: 0 auto !important;
          }

          .nl-block {
            width: 100% !important;
            box-sizing: border-box !important;
          }

          .nl-section-card {
            padding: 16px !important;
            box-sizing: border-box !important;
          }

          .nl-section-card > div,
          .nl-responsive-section-grid,
          .nl-column-wrap,
          div[style*="grid-template-columns"] {
            display: block !important;
            grid-template-columns: 1fr !important;
            width: 100% !important;
          }

          .nl-section-card img,
          .nl-img-el {
            width: 100% !important;
            max-width: 100% !important;
            display: block !important;
            margin: 0 0 14px 0 !important;
          }

          h1, h2, h3 {
            word-break: normal !important;
            overflow-wrap: break-word !important;
          }

          .nl-button-link {
            width: auto !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
          }
        }
      `;
      doc.head.appendChild(style);
    }

    function resizeFrame() {
      if (!frame) return;

      try {
        const doc = frame.contentDocument || frame.contentWindow.document;
        patchNewsletterDocument(doc);

        const height = Math.max(
          doc.documentElement ? doc.documentElement.scrollHeight : 0,
          doc.body ? doc.body.scrollHeight : 0
        );

        frame.style.height = `${height + 10}px`;
      } catch (e) {
        frame.style.height = '900px';
      }
    }

    if (frame) {
      frame.addEventListener('load', () => {
        resizeFrame();
        setTimeout(resizeFrame, 250);
        setTimeout(resizeFrame, 900);
      });
      window.addEventListener('resize', resizeFrame);
    }

    window.resizeIframe = function (iframe) {
      if (iframe) {
        try {
          const doc = iframe.contentDocument || iframe.contentWindow.document;
          patchNewsletterDocument(doc);

          const height = Math.max(
            doc.documentElement ? doc.documentElement.scrollHeight : 0,
            doc.body ? doc.body.scrollHeight : 0
          );

          iframe.style.height = `${height + 10}px`;
        } catch (e) {
          iframe.style.height = '900px';
        }
      }
    };

    window.copyLink = function (url) {
      const setCopied = (copied) => {
        const topIcon = $('#copyTopIcon');
        const sideIcon = $('#sidebarCopyIcon');
        const sideText = $('#sidebarCopyText');
        const topBtn = $('#copyTopBtn');
        const sideBtn = $('#sidebarCopyBtn');

        if (topIcon) topIcon.className = copied ? 'fa fa-check' : 'fa fa-link';
        if (sideIcon) sideIcon.className = copied ? 'fa fa-check' : 'fa fa-link';
        if (sideText) sideText.textContent = copied ? 'Copied!' : 'Copy Link';
        topBtn?.classList.toggle('is-copied', copied);
        sideBtn?.classList.toggle('is-copied', copied);
      };

      const fallbackCopy = () => {
        const textarea = document.createElement('textarea');
        textarea.value = url;
        textarea.style.cssText = 'position:fixed;left:-9999px;top:-9999px;opacity:0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        textarea.remove();
      };

      const done = () => {
        setCopied(true);
        setTimeout(() => setCopied(false), 2200);
      };

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(done).catch(() => {
          fallbackCopy();
          done();
        });
      } else {
        fallbackCopy();
        done();
      }
    };
  }

  /* ============================================================
     Intersection Animations
  ============================================================ */
  function initAnimations() {
    if (!('IntersectionObserver' in window)) {
      $$('section').forEach((section) => section.classList.add('animate-in'));
      return;
    }

    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) entry.target.classList.add('animate-in');
      });
    }, { threshold: 0.1 });

    $$('section').forEach((section) => observer.observe(section));
  }

  /* ============================================================
     Shared professional UI interactions
  ============================================================ */
  function initProfessionalUI() {
    document.documentElement.classList.add('js-enabled');

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const revealItems = $$('.card, .content-card, .dashboard-card, .stat-card, .form-card, .table-card, .panel, .widget');

    revealItems.forEach((element) => element.classList.add('ui-reveal'));

    if (reduceMotion || !('IntersectionObserver' in window)) {
      revealItems.forEach((element) => element.classList.add('ui-visible'));
    } else {
      const revealObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          entry.target.classList.add('ui-visible');
          observer.unobserve(entry.target);
        });
      }, { rootMargin: '0px 0px -30px', threshold: 0.08 });

      revealItems.forEach((element) => revealObserver.observe(element));
    }

    $$('form').forEach((form) => {
      form.addEventListener('submit', () => {
        if (form.dataset.noLoading !== undefined || !form.checkValidity()) return;

        const submitter = form.querySelector('button[type="submit"], input[type="submit"]');
        if (!submitter || submitter.classList.contains('is-loading')) return;

        submitter.classList.add('is-loading');
        submitter.setAttribute('aria-busy', 'true');

        // Safety fallback for AJAX validation failures or interrupted requests.
        window.setTimeout(() => {
          submitter.classList.remove('is-loading');
          submitter.removeAttribute('aria-busy');
        }, 15000);
      });
    });

    $$('button, .btn, [role="button"]').forEach((control) => {
      control.addEventListener('pointerdown', () => control.classList.add('is-pressed'));
      ['pointerup', 'pointercancel', 'pointerleave'].forEach((eventName) => {
        control.addEventListener(eventName, () => control.classList.remove('is-pressed'));
      });
    });
  }

  ready(() => {
    initProfessionalUI();
    initNavbar();
    initFaq();
    initContactForm();
    initNewsletterReader();
    initAnimations();

    document.body.classList.add('loaded');

    console.log(
      '%c Mastercard Foundation EdTech Fellowship ',
      'background:#fc7f10;color:#fff;font-size:16px;font-weight:bold;padding:8px;'
    );
  });
})();
