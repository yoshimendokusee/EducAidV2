/**
 * Mobile Navbar Handler
 * Handles burger menu toggle and mobile-specific behaviors
 */
class MobileNavbar {
  constructor() {
    this.navbar = document.querySelector('.navbar');
    this.navbarToggler = document.querySelector('.navbar-toggler');
    this.navbarCollapse = document.querySelector('.navbar-collapse');
    this.navLinks = document.querySelectorAll('.navbar-nav .nav-link');
    this.isAnimating = false;
    
    this.init();
  }

  init() {
    this.disableBootstrapCollapse();
    this.ensureInitialState(); // Ensure burger starts in correct state
    this.setupEventListeners();
    this.setupAutoClose();
    this.setupMobileOptimization();
  }

  disableBootstrapCollapse() {
    // Remove Bootstrap's data attributes to prevent native collapse behavior
    if (this.navbarToggler) {
      this.navbarToggler.removeAttribute('data-bs-toggle');
      this.navbarToggler.removeAttribute('data-bs-target');
    }
    
    if (this.navbarCollapse) {
      this.navbarCollapse.classList.remove('collapse');
    }
  }

  ensureInitialState() {
    // Ensure burger icon starts in collapsed (closed) state
    if (this.navbarToggler) {
      this.navbarToggler.classList.add('collapsed');
      this.navbarToggler.setAttribute('aria-expanded', 'false');
    }
    
    if (this.navbarCollapse) {
      this.navbarCollapse.classList.remove('show');
    }
  }

  setupEventListeners() {
    // Toggle burger menu
    if (this.navbarToggler) {
      this.navbarToggler.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        this.toggleMenu();
      });
    }

    // Close menu when clicking outside
    document.addEventListener('click', (e) => {
      if (this.isMenuOpen() && !this.navbar.contains(e.target)) {
        this.closeMenu();
      }
    });

    // Close menu on escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && this.isMenuOpen()) {
        this.closeMenu();
      }
    });

    // Handle window resize
    window.addEventListener('resize', () => {
      this.handleResize();
    });
  }

  setupAutoClose() {
    // Close menu when clicking nav links (for single-page navigation)
    this.navLinks.forEach(link => {
      link.addEventListener('click', (e) => {
        // Only auto-close for anchor links (same page navigation)
        if (link.getAttribute('href').startsWith('#')) {
          setTimeout(() => {
            this.closeMenu();
          }, 150);
        }
      });
    });
  }

  setupMobileOptimization() {
    // Add mobile-specific classes and behaviors
    this.optimizeForMobile();
    
    // Re-optimize on orientation change
    window.addEventListener('orientationchange', () => {
      setTimeout(() => {
        this.optimizeForMobile();
      }, 100);
    });
  }

  toggleMenu() {
    // Prevent multiple toggles during animation
    if (this.isAnimating) return;
    
    // Check current state based on our custom tracking
    const isCurrentlyOpen = this.isMenuOpen();
    
    if (isCurrentlyOpen) {
      this.closeMenu();
    } else {
      this.openMenu();
    }
  }

  openMenu() {
    // Prevent multiple openings or opening during animation
    if (this.isMenuOpen() || this.isAnimating) return;
    
    this.isAnimating = true;
    
    // Set states immediately
    this.navbarCollapse.classList.add('show');
    this.navbarToggler.setAttribute('aria-expanded', 'true');
    this.navbarToggler.classList.remove('collapsed'); // Remove collapsed when opening
    
    // Add body class to prevent scrolling on mobile
    if (window.innerWidth < 992) {
      document.body.classList.add('navbar-open');
    }
    
    // Animate menu appearance
    this.animateMenuOpen(() => {
      this.isAnimating = false;
    });
  }

  closeMenu() {
    // Prevent multiple closings or closing during animation
    if (!this.isMenuOpen() || this.isAnimating) return;
    
    this.isAnimating = true;
    
    // Start close animation first, then update states
    this.animateMenuClose(() => {
      // Remove classes after animation completes
      this.navbarCollapse.classList.remove('show');
      this.navbarToggler.setAttribute('aria-expanded', 'false');
      this.navbarToggler.classList.add('collapsed'); // Add collapsed when closing
      
      // Remove body class
      document.body.classList.remove('navbar-open');
      
      this.isAnimating = false;
    });
  }

  isMenuOpen() {
    return this.navbarCollapse.classList.contains('show') && 
           this.navbarToggler.getAttribute('aria-expanded') === 'true';
  }

  handleResize() {
    // Close menu on desktop
    if (window.innerWidth >= 992 && this.isMenuOpen()) {
      this.closeMenu();
    }
    
    // Re-optimize for current screen size
    this.optimizeForMobile();
  }

  optimizeForMobile() {
    const isMobile = window.innerWidth < 992;
    
    if (isMobile) {
      // Add mobile-specific optimizations
      this.navbar.classList.add('navbar-mobile');
      
      // Ensure proper spacing
      if (this.navbarCollapse) {
        this.navbarCollapse.style.maxHeight = `${window.innerHeight - 100}px`;
      }
    } else {
      // Remove mobile-specific classes
      this.navbar.classList.remove('navbar-mobile');
      
      if (this.navbarCollapse) {
        this.navbarCollapse.style.maxHeight = '';
      }
    }
  }

  animateMenuOpen(callback) {
    // Reset any existing transitions and styles
    this.navbarCollapse.style.transition = '';
    this.navbarCollapse.style.transform = '';
    this.navbarCollapse.style.opacity = '';
    this.navbarCollapse.style.filter = '';
    
    // Set initial state for animation
    this.navbarCollapse.style.opacity = '0';
    this.navbarCollapse.style.transform = 'translateY(-20px) scale(0.95)';
    this.navbarCollapse.style.filter = 'blur(2px)';
    
    // Force reflow
    this.navbarCollapse.offsetHeight;
    
    // Apply transition and animate to final state
    this.navbarCollapse.style.transition = 'all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94)';
    
    requestAnimationFrame(() => {
      this.navbarCollapse.style.opacity = '1';
      this.navbarCollapse.style.transform = 'translateY(0) scale(1)';
      this.navbarCollapse.style.filter = 'blur(0px)';
    });
    
    // Add staggered animation for menu items
    setTimeout(() => {
      this.animateMenuItems('in');
      
      // Execute callback after items animation
      if (callback) {
        setTimeout(callback, 400);
      }
    }, 100);
  }

  animateMenuClose(callback) {
    // Animate menu items out first
    this.animateMenuItems('out');
    
    // Add closing class for smooth transition
    this.navbarCollapse.classList.add('navbar-closing');
    
    // Set transition for closing with smoother easing
    this.navbarCollapse.style.transition = 'all 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94)';
    
    // Animate to closed state with smoother movement
    requestAnimationFrame(() => {
      this.navbarCollapse.style.opacity = '0';
      this.navbarCollapse.style.transform = 'translateY(-20px) scale(0.95)';
      this.navbarCollapse.style.filter = 'blur(2px)';
    });
    
    // Execute callback after animation completes
    setTimeout(() => {
      // Remove closing class
      this.navbarCollapse.classList.remove('navbar-closing');
      
      // Reset all styles
      this.navbarCollapse.style.transition = '';
      this.navbarCollapse.style.opacity = '';
      this.navbarCollapse.style.transform = '';
      this.navbarCollapse.style.filter = '';
      
      // Execute callback
      if (callback) callback();
    }, 350);
  }

  animateMenuItems(direction) {
    const menuItems = this.navbarCollapse.querySelectorAll('.nav-item, .navbar-nav > div');
    
    menuItems.forEach((item, index) => {
      if (direction === 'in') {
        // Reset any existing styles
        item.style.transition = '';
        item.style.opacity = '';
        item.style.transform = '';
        
        // Set initial state
        item.style.opacity = '0';
        item.style.transform = 'translateX(-30px)';
        
        // Animate items in with stagger
        setTimeout(() => {
          item.style.transition = `all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94) ${index * 0.08}s`;
          item.style.opacity = '1';
          item.style.transform = 'translateX(0)';
        }, 50);
        
      } else {
        // Animate items out with reverse stagger
        item.style.transition = `all 0.3s cubic-bezier(0.55, 0.055, 0.675, 0.19) ${(menuItems.length - index - 1) * 0.04}s`;
        item.style.opacity = '0';
        item.style.transform = 'translateX(-20px)';
        
        // Reset after animation
        setTimeout(() => {
          item.style.transition = '';
          item.style.opacity = '';
          item.style.transform = '';
        }, 300 + (menuItems.length - index - 1) * 40);
      }
    });
  }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
  new MobileNavbar();
});

// Smooth scrolling for anchor links
document.addEventListener('DOMContentLoaded', () => {
  const links = document.querySelectorAll('a[href^="#"]');
  
  links.forEach(link => {
    link.addEventListener('click', (e) => {
      const href = link.getAttribute('href');
      
      if (href !== '#' && href.length > 1) {
        const target = document.querySelector(href);
        
        if (target) {
          e.preventDefault();
          
          const navbarHeight = document.querySelector('.navbar').offsetHeight;
          const targetPosition = target.offsetTop - navbarHeight - 20;
          
          window.scrollTo({
            top: targetPosition,
            behavior: 'smooth'
          });
        }
      }
    });
  });
});