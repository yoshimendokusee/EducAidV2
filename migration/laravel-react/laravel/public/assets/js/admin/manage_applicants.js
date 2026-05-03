// Manage Applicants - Simple orientation detection for portrait mode
document.addEventListener("DOMContentLoaded", function() {
    console.log('Manage applicants page loaded - forms ready');
    
    // Detect orientation and apply dynamic classes
    function handleOrientationChange() {
        const body = document.body;
        const isPortrait = window.matchMedia("(orientation: portrait)").matches;
        const isMobile = window.innerWidth <= 768;
        
        if (isMobile && isPortrait) {
            body.classList.add('mobile-portrait');
            body.classList.remove('mobile-landscape');
        } else if (isMobile) {
            body.classList.add('mobile-landscape');
            body.classList.remove('mobile-portrait');
        } else {
            body.classList.remove('mobile-portrait', 'mobile-landscape');
        }
    }
    
    // Run on load
    handleOrientationChange();
    
    // Listen for orientation changes
    window.addEventListener('orientationchange', function() {
        setTimeout(handleOrientationChange, 100);
    });
    
    // Listen for resize events
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(handleOrientationChange, 250);
    });
    
    // Add smooth scroll for table on mobile
    if (window.innerWidth <= 600) {
        const tableWrapper = document.querySelector('.table-responsive');
        if (tableWrapper) {
            tableWrapper.style.scrollBehavior = 'smooth';
        }
    }
    
    // Enhance touch interaction for mobile
    if ('ontouchstart' in window) {
        const actionButtons = document.querySelectorAll('.btn-info.btn-sm');
        actionButtons.forEach(button => {
            button.addEventListener('touchstart', function() {
                this.style.transform = 'scale(0.98)';
            });
            button.addEventListener('touchend', function() {
                this.style.transform = 'scale(1)';
            });
        });
    }
});
