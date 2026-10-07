document.addEventListener('DOMContentLoaded', () => {
  initSidebarToggle();
  initDashboardTabs();
  initModalBodyLock();
  initPasswordToggle();
});

function initSidebarToggle() {
  const sidebar = document.getElementById('adminSidebar');
  const main = document.getElementById('adminMain');
  const toggle = document.getElementById('sidebarToggle');

  if (!toggle || !sidebar || !main) return;

  toggle.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    main.classList.toggle('collapsed');
  });
}

function initDashboardTabs() {
  const tabButtons = document.querySelectorAll('.dashboard-tabs-card .tab-btn');
  const tabContents = document.querySelectorAll('.dashboard-tabs-card .tab-content');
  const tabLinks = document.querySelectorAll('.dashboard-tab-link');

  if (!tabButtons.length) return;

  tabButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      const target = btn.dataset.tab;

      tabButtons.forEach((b) => {
        b.classList.remove('active');
        b.setAttribute('aria-selected', 'false');
      });

      btn.classList.add('active');
      btn.setAttribute('aria-selected', 'true');

      tabContents.forEach((panel) => {
        panel.classList.toggle('active', panel.id === target);
      });

      tabLinks.forEach((link) => {
        link.style.display = link.dataset.link === target ? 'inline-flex' : 'none';
      });
    });
  });
}

function initModalBodyLock() {
  if (typeof MutationObserver === 'undefined') return;

  const modals = document.querySelectorAll('.modal-overlay');

  function syncModalBodyLock() {
    const hasOpenModal = document.querySelector('.modal-overlay.open');
    document.body.classList.toggle('modal-open', !!hasOpenModal);
  }

  modals.forEach((modal) => {
    const modalObserver = new MutationObserver(syncModalBodyLock);

    modalObserver.observe(modal, {
      attributes: true,
      attributeFilter: ['class']
    });
  });

  syncModalBodyLock();
}

function initPasswordToggle() {
  window.togglePwd = function () {
    const input = document.getElementById('password');
    const icon = document.getElementById('pwdIcon');

    if (!input || !icon) return;

    if (input.type === 'password') {
      input.type = 'text';
      icon.className = 'fa fa-eye-slash';
    } else {
      input.type = 'password';
      icon.className = 'fa fa-eye';
    }
  };
}