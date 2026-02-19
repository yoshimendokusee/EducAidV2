// EducAid Static Demo - Sidebar & Layout JS
// No database connectivity

document.addEventListener("DOMContentLoaded", function () {
  document.body.classList.add("js-ready");
  document.body.classList.add("ready");

  const sidebar = document.getElementById("sidebar");
  const toggleBtn = document.getElementById("menu-toggle");
  let backdrop = document.getElementById("sidebar-backdrop");

  if (!backdrop) {
    backdrop = document.createElement('div');
    backdrop.id = 'sidebar-backdrop';
    backdrop.className = 'sidebar-backdrop d-none';
    if (sidebar && sidebar.parentElement) {
      sidebar.parentElement.insertBefore(backdrop, sidebar.nextSibling);
    } else {
      document.body.appendChild(backdrop);
    }
  }

  const homeSection = document.querySelector(".home-section");
  const header = document.querySelector('.student-main-header');

  function isMobile() {
    return window.innerWidth <= 992;
  }

  function adjustLayout() {
    if (!header) return;
    const closed = sidebar && sidebar.classList.contains('close');
    const sidebarWidth = closed ? 70 : 250;

    if (isMobile()) {
      header.style.left = '0px';
      header.style.width = '100%';
    } else {
      header.style.left = sidebarWidth + 'px';
      header.style.width = `calc(100% - ${sidebarWidth}px)`;
    }

    if (homeSection) {
      homeSection.style.marginLeft = isMobile() ? '0px' : '';
      if (isMobile()) homeSection.style.width = '100%'; else homeSection.style.width = '';
    }
  }

  function updateSidebarState() {
    if (!sidebar) return;
    if (isMobile()) {
      sidebar.classList.remove("close", "open");
      if (backdrop) backdrop.classList.add("d-none");
      document.body.style.overflow = "";
      adjustLayout();
    } else {
      const state = localStorage.getItem("demoSidebarState");
      if (state === "closed") {
        sidebar.classList.add("close");
      } else {
        sidebar.classList.remove("close");
      }
      sidebar.classList.remove("open");
      if (backdrop) backdrop.classList.add("d-none");
      document.body.style.overflow = "";
      adjustLayout();
    }
  }

  updateSidebarState();

  if (toggleBtn && sidebar) {
    toggleBtn.addEventListener("click", function () {
      if (isMobile()) {
        const isOpen = sidebar.classList.contains("open");
        if (isOpen) {
          sidebar.classList.remove("open");
          sidebar.classList.add("close");
          if (backdrop) { backdrop.style.opacity = "0"; }
          document.body.style.overflow = "";
          setTimeout(() => {
            if (!sidebar.classList.contains("open") && backdrop) {
              backdrop.classList.add("d-none");
              backdrop.style.opacity = "";
            }
          }, 300);
        } else {
          if (backdrop) {
            backdrop.classList.remove("d-none");
            void backdrop.offsetWidth;
          }
          sidebar.classList.add("open");
          sidebar.classList.remove("close");
          if (backdrop) backdrop.style.opacity = "1";
          document.body.style.overflow = "hidden";
        }
      } else {
        sidebar.classList.toggle("close");
        localStorage.setItem("demoSidebarState", sidebar.classList.contains("close") ? "closed" : "open");
      }
      adjustLayout();
    });
  }

  // Close sidebar when clicking backdrop
  if (backdrop) {
    backdrop.addEventListener("click", function () {
      if (sidebar) {
        sidebar.classList.remove("open");
        sidebar.classList.add("close");
      }
      backdrop.style.opacity = "0";
      document.body.style.overflow = "";
      setTimeout(() => {
        backdrop.classList.add("d-none");
        backdrop.style.opacity = "";
      }, 300);
      adjustLayout();
    });
  }

  // Handle resize
  let resizeTimer;
  window.addEventListener("resize", function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(updateSidebarState, 150);
  });

  // Handle back/forward cache
  window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
      document.body.classList.add('ready');
    }
  });

  // Carousel functionality
  initCarousel();
});

function initCarousel() {
  const track = document.getElementById('carouselTrack');
  const prevBtn = document.getElementById('carouselPrev');
  const nextBtn = document.getElementById('carouselNext');
  const dots = document.querySelectorAll('.carousel-dot');

  if (!track) return;

  let currentPage = 0;
  const pages = track.querySelectorAll('.carousel-page');
  const totalPages = pages.length;

  function goToPage(page) {
    if (page < 0 || page >= totalPages) return;
    currentPage = page;
    track.style.transform = `translateX(-${currentPage * 100}%)`;
    dots.forEach((d, i) => d.classList.toggle('active', i === currentPage));
    if (prevBtn) prevBtn.disabled = currentPage === 0;
    if (nextBtn) nextBtn.disabled = currentPage === totalPages - 1;
  }

  if (prevBtn) prevBtn.addEventListener('click', () => goToPage(currentPage - 1));
  if (nextBtn) nextBtn.addEventListener('click', () => goToPage(currentPage + 1));
  dots.forEach(dot => {
    dot.addEventListener('click', () => goToPage(parseInt(dot.dataset.page)));
  });

  goToPage(0);
}
