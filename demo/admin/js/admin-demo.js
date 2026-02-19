/* admin-demo.js — Admin demo sidebar + layout */
(function () {
  'use strict';

  function isMobile() { return window.innerWidth < 992; }

  const sidebar   = document.getElementById('sidebar');
  const backdrop   = document.getElementById('sidebar-backdrop');
  const toggle     = document.getElementById('menu-toggle');

  function openSidebar() {
    if (isMobile()) {
      sidebar.classList.add('open');
      sidebar.classList.remove('close');
      backdrop.classList.add('show');
      backdrop.classList.remove('d-none');
    } else {
      sidebar.classList.remove('close');
      localStorage.setItem('adminSidebarState', 'open');
      adjustDesktopLayout();
    }
  }

  function closeSidebar() {
    if (isMobile()) {
      sidebar.classList.remove('open');
      backdrop.classList.remove('show');
      setTimeout(() => backdrop.classList.add('d-none'), 300);
    } else {
      sidebar.classList.add('close');
      localStorage.setItem('adminSidebarState', 'closed');
      adjustDesktopLayout();
    }
  }

  function adjustDesktopLayout() {
    if (isMobile()) return;
    const w = sidebar.classList.contains('close') ? 70 : 250;
    const header = document.querySelector('.admin-main-header');
    const content = document.querySelector('.home-section') || document.getElementById('mainContent');
    if (header) { header.style.left = w + 'px'; }
    if (content) {
      content.style.marginLeft = w + 'px';
      content.style.width = 'calc(100% - ' + w + 'px)';
    }
  }

  if (toggle) {
    toggle.addEventListener('click', function () {
      if (isMobile()) {
        sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
      } else {
        sidebar.classList.contains('close') ? openSidebar() : closeSidebar();
      }
    });
  }

  if (backdrop) {
    backdrop.addEventListener('click', closeSidebar);
  }

  // Restore desktop state
  if (!isMobile()) {
    var saved = localStorage.getItem('adminSidebarState');
    if (saved === 'closed') { sidebar.classList.add('close'); }
    adjustDesktopLayout();
  }

  window.addEventListener('resize', function () {
    if (isMobile()) {
      sidebar.classList.remove('close');
      sidebar.classList.remove('open');
      backdrop.classList.add('d-none');
      var header = document.querySelector('.admin-main-header');
      var content = document.querySelector('.home-section') || document.getElementById('mainContent');
      if (header) { header.style.left = ''; }
      if (content) { content.style.marginLeft = ''; content.style.width = ''; }
    } else {
      adjustDesktopLayout();
    }
  });

  // Anti-FOUC
  document.body.classList.add('ready');
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) document.body.classList.add('ready');
  });

  // Submenu collapse toggles
  document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      e.preventDefault();
      var target = document.querySelector(el.getAttribute('href'));
      if (target) {
        target.classList.toggle('show');
        var chevron = el.querySelector('.bi-chevron-down');
        if (chevron) chevron.style.transform = target.classList.contains('show') ? 'rotate(180deg)' : '';
      }
    });
  });
})();
