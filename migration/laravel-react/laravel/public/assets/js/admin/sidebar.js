// assets/js/admin/sidebar_toggle.js

// Apply initial sidebar state before DOMContentLoaded to prevent flash
(function() {
  function isMobile() {
    return window.innerWidth <= 992; // Match CSS breakpoint 991.98px
  }
  
  // Set initial state as early as possible
  if (!isMobile()) {
    const state = localStorage.getItem("adminSidebarState");
    if (state === "closed") {
      document.documentElement.classList.add('sidebar-closed-initial');
    }
  }
})();

document.addEventListener("DOMContentLoaded", function () {
  // Prevent double-binding
  if (window.__ADMIN_SIDEBAR_BOUND) return;
  window.__ADMIN_SIDEBAR_BOUND = 'external';
  
  const sidebar = document.getElementById("sidebar");
  const toggleBtn = document.getElementById("menu-toggle");
  const backdrop = document.getElementById("sidebar-backdrop");
  const homeSection = document.querySelector(".home-section") || document.getElementById("mainContent");
  
  if (!sidebar || !toggleBtn || !backdrop) {
    console.warn('[AdminSidebar] Missing required elements');
    return;
  }

  function isMobile() {
    return window.innerWidth <= 992; // Match CSS breakpoint 991.98px
  }

  const header = document.querySelector('.admin-main-header');
  const adjustLayout = () => {
    if (!header) return;
  const closed = sidebar.classList.contains('close');
  const left = closed ? 70 : 250; // match .sidebar.close { width: 70px; }
    header.style.left = isMobile() ? '0px' : left + 'px';
    if (homeSection) {
      // Remove any inline margin-left so CSS width calc stays accurate
      homeSection.style.marginLeft = isMobile() ? '0px' : '';
      if (isMobile()) homeSection.style.width = '100%'; else homeSection.style.width = '';
    }
  };

  function updateSidebarState() {
    // On mobile, sidebar state is always "open" only when toggled, never saved
    if (isMobile()) {
      // Don't reset if currently open (prevents auto-close on resize/scrollbar changes)
      if (sidebar.classList.contains('open')) {
        backdrop.classList.remove('d-none');
        document.body.style.overflow = 'hidden';
        adjustLayout();
        return;
      }
      sidebar.classList.remove("close", "open");
      backdrop.classList.add("d-none");
      document.body.style.overflow = "";
      if (homeSection) homeSection.classList.remove("expanded");
      adjustLayout();
    } else {
      // Desktop: remember state in localStorage
      const state = localStorage.getItem("adminSidebarState");
      if (state === "closed") {
        sidebar.classList.add("close");
        if (homeSection) homeSection.classList.remove("expanded");
      } else {
        sidebar.classList.remove("close");
        if (homeSection) homeSection.classList.add("expanded");
      }
      sidebar.classList.remove("open");
      backdrop.classList.add("d-none");
      document.body.style.overflow = "";
      adjustLayout();
    }
  }

  // On page load, set sidebar state
  updateSidebarState();

  // === JS Animation for both desktop and mobile ===
  let sidebarAnimFrame = null;
  let sidebarAnimating = false;
  let backdropAnimFrame = null;

  function animateSidebar(expand) {
    if (sidebarAnimating) {
      cancelAnimationFrame(sidebarAnimFrame);
      if (backdropAnimFrame) cancelAnimationFrame(backdropAnimFrame);
      sidebarAnimating = false;
    }

    if (isMobile()) {
      // Mobile: animate slide-in/out with smooth transform
      const startTransform = expand ? -100 : 0;
      const targetTransform = expand ? 0 : -100;
      const startOpacity = expand ? 0 : 1;
      const targetOpacity = expand ? 1 : 0;
      const startTime = performance.now();
      const duration = 400; // ms - smoother with longer duration

      // Setup initial state
      if (expand) {
        sidebar.classList.add("open");
        sidebar.classList.remove("close");
        backdrop.classList.remove("d-none");
        document.body.style.overflow = "hidden";
      }

      sidebarAnimating = true;

      // Smooth easing function
      function easeInOutCubic(t) {
        return t < 0.5 
          ? 4 * t * t * t 
          : 1 - Math.pow(-2 * t + 2, 3) / 2;
      }

      function step(now) {
        const elapsed = now - startTime;
        const progress = Math.min(1, elapsed / duration);
        const eased = easeInOutCubic(progress);
        
        // Animate sidebar transform
        const currentTransform = startTransform + (targetTransform - startTransform) * eased;
        sidebar.style.transform = `translateX(${currentTransform}%)`;
        
        // Animate backdrop opacity
        const currentOpacity = startOpacity + (targetOpacity - startOpacity) * eased;
        backdrop.style.opacity = currentOpacity;

        if (progress < 1) {
          sidebarAnimFrame = requestAnimationFrame(step);
        } else {
          // Finish state
          sidebarAnimating = false;
          sidebar.style.transform = '';
          backdrop.style.opacity = '';
          
          if (!expand) {
            sidebar.classList.remove("open");
            sidebar.classList.add("close");
            backdrop.classList.add("d-none");
            document.body.style.overflow = "";
          }
          adjustLayout();
        }
      }

      requestAnimationFrame(step);
      return;
    }

    // Desktop animation (existing code)
    const startWidth = sidebar.offsetWidth;
    const targetWidth = expand ? 250 : 70; // sync with CSS values
    const startTime = performance.now();
    const duration = 220; // ms

    // Pre-state: add/remove class only AFTER animation so content text appears/disappears at end
    // For collapsing we keep it open (no .close) until the end so labels don't instantly vanish.
    if (!expand) {
      sidebar.classList.remove("close");
    }

    sidebarAnimating = true;

    function easeOutQuad(t) { return 1 - (1 - t) * (1 - t); }

    function step(now) {
      const elapsed = now - startTime;
      const progress = Math.min(1, elapsed / duration);
      const eased = easeOutQuad(progress);
      const current = Math.round(startWidth + (targetWidth - startWidth) * eased);
      sidebar.style.width = current + 'px';

      // Animate header and content shift inline (will be cleaned up after)
      if (header && !isMobile()) header.style.left = current + 'px';
      if (homeSection && !isMobile()) {
        homeSection.style.marginLeft = current + 'px';
        homeSection.style.width = `calc(100% - ${current}px)`;
      }

      if (progress < 1) {
        sidebarAnimFrame = requestAnimationFrame(step);
      } else {
        // Finish state
        sidebarAnimating = false;
        sidebar.style.width = '';
        if (expand) {
          sidebar.classList.remove("close");
          localStorage.setItem("adminSidebarState", "open");
          if (homeSection) homeSection.classList.add("expanded");
        } else {
          sidebar.classList.add("close");
          localStorage.setItem("adminSidebarState", "closed");
          if (homeSection) homeSection.classList.remove("expanded");
        }
        // Clean up inline shifts - let adjustLayout() set final canonical values
        if (homeSection) {
          homeSection.style.marginLeft = '';
          homeSection.style.width = '';
        }
        adjustLayout();
      }
    }

    requestAnimationFrame(step);
  }

  let suppressOutsideClickUntil = 0;
  
  toggleBtn.addEventListener("click", function (e) {
    e.stopPropagation();
    e.preventDefault();
    // On mobile: check 'open' class; on desktop: check 'close' class
    const expanding = isMobile() 
      ? !sidebar.classList.contains("open") 
      : sidebar.classList.contains("close");
    suppressOutsideClickUntil = performance.now() + 500;
    animateSidebar(expanding);
  });

  backdrop.addEventListener("click", function () {
    animateSidebar(false); // Use animation for closing
  });

  // Hide sidebar on mobile when clicking outside of it
  document.addEventListener("click", function (e) {
    if (!isMobile()) return;
    if (!sidebar.classList.contains("open")) return;
    if (sidebarAnimating) return;
    if (performance.now() < suppressOutsideClickUntil) return;
    
    const toggleWrapper = toggleBtn.closest('.sidebar-toggle');
    const isClickInside = sidebar.contains(e.target) || 
                          toggleBtn.contains(e.target) || 
                          (toggleWrapper && toggleWrapper.contains(e.target));
    if (!isClickInside) {
      animateSidebar(false);
    }
  });

  // Update sidebar state on resize, but preserve open state on mobile
  window.addEventListener("resize", () => {
    if (isMobile() && sidebar.classList.contains('open')) {
      adjustLayout();
      return;
    }
    updateSidebarState();
  });

  // (Optional) Force apply state when navigating via SPA or AJAX:
  // window.addEventListener("pageshow", updateSidebarState);

  // For debugging: log localStorage state
  // console.log("Sidebar state:", localStorage.getItem("adminSidebarState"));
});
// This code handles the sidebar toggle functionality for the admin panel.