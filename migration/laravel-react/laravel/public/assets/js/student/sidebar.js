// assets/js/student/sidebar.js

document.addEventListener("DOMContentLoaded", function () {
  // Mark body ready so CSS stops hiding the sidebar during script initialization
  document.body.classList.add("js-ready");
  const sidebar = document.getElementById("sidebar");
  const toggleBtn = document.getElementById("menu-toggle");
  let backdrop = document.getElementById("sidebar-backdrop");
  // Fallback: if some pages forgot to include the backdrop, create it
  if (!backdrop) {
    backdrop = document.createElement('div');
    backdrop.id = 'sidebar-backdrop';
    backdrop.className = 'sidebar-backdrop d-none';
    // Prefer placing after sidebar in DOM if available, else append to body
    if (sidebar && sidebar.parentElement) {
      sidebar.parentElement.insertBefore(backdrop, sidebar.nextSibling);
    } else {
      document.body.appendChild(backdrop);
    }
  }
  const homeSection = document.querySelector(".home-section") || document.getElementById("mainContent");

  function isMobile() {
    return window.innerWidth <= 992;
  }

  const header = document.querySelector('.student-main-header') || document.querySelector('.main-header');
  const adjustLayout = () => {
    if (!header) return;
    const closed = sidebar.classList.contains('close');
    const sidebarWidth = closed ? 70 : 250; // match .sidebar.close { width: 70px; }
    
    if (isMobile()) {
      header.style.left = '0px';
      header.style.width = '100%';
    } else {
      header.style.left = sidebarWidth + 'px';
      header.style.width = `calc(100% - ${sidebarWidth}px)`;
    }
    
    if (homeSection) {
      // Remove any inline margin-left so CSS width calc stays accurate
      homeSection.style.marginLeft = isMobile() ? '0px' : '';
      if (isMobile()) homeSection.style.width = '100%'; else homeSection.style.width = '';
    }
  };

  function updateSidebarState() {
    // On mobile, sidebar state is always "open" only when toggled, never saved
    if (isMobile()) {
      sidebar.classList.remove("close", "open");
      backdrop.classList.add("d-none");
      document.body.style.overflow = "";
      if (homeSection) homeSection.classList.remove("expanded");
      adjustLayout();
    } else {
      // Desktop: remember state in localStorage
      const state = localStorage.getItem("studentSidebarState");
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
      // Mobile: overlay behavior with backdrop (like admin)
      if (expand) {
        if (backdrop) {
          backdrop.classList.remove("d-none");
          // force reflow to apply transition
          void backdrop.offsetWidth;
        }
        sidebar.classList.add("open");
        sidebar.classList.remove("close");
        if (backdrop) backdrop.style.opacity = "1";
        document.body.style.overflow = "hidden";
      } else {
        sidebar.classList.remove("open");
        sidebar.classList.add("close");
        if (backdrop) backdrop.style.opacity = "0";
        document.body.style.overflow = "";
        setTimeout(() => {
          if (!sidebar.classList.contains("open") && backdrop) {
            backdrop.classList.add("d-none");
            backdrop.style.opacity = "";
          }
        }, 300);
      }
      adjustLayout();
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
      if (header && !isMobile()) {
        header.style.left = current + 'px';
        header.style.width = `calc(100% - ${current}px)`;
      }
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
          localStorage.setItem("studentSidebarState", "open");
          if (homeSection) homeSection.classList.add("expanded");
        } else {
          sidebar.classList.add("close");
          localStorage.setItem("studentSidebarState", "closed");
          if (homeSection) homeSection.classList.remove("expanded");
        }
        // Clean up inline shifts - let adjustLayout() set final canonical values
        if (header) {
          header.style.left = '';
          header.style.width = '';
        }
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
    const expanding = isMobile() 
      ? !sidebar.classList.contains("open") 
      : sidebar.classList.contains("close");
    suppressOutsideClickUntil = performance.now() + 500; // Suppress for 500ms
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

  // Always update sidebar state on resize to keep in sync
  window.addEventListener("resize", () => { updateSidebarState(); });

  // (Optional) Force apply state when navigating via SPA or AJAX:
  // window.addEventListener("pageshow", updateSidebarState);

  // For debugging: log localStorage state
  // console.log("Student Sidebar state:", localStorage.getItem("studentSidebarState"));
});
