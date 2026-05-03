/**
 * Mobile Navbar Collapse Fix
 * Ensures Bootstrap navbar toggler works properly on mobile devices
 * by explicitly initializing the collapse component
 */
document.addEventListener('DOMContentLoaded', function() {
    const navbarToggler = document.querySelector('.navbar-toggler');
    const navbarCollapse = document.getElementById('nav');
    
    if (navbarToggler && navbarCollapse) {
        // Remove any existing click handlers to prevent duplicates
        navbarToggler.replaceWith(navbarToggler.cloneNode(true));
        const newToggler = document.querySelector('.navbar-toggler');
        
        // Manually handle toggle click for mobile
        newToggler.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Toggle collapse using Bootstrap's API
            const bsCollapse = bootstrap.Collapse.getOrCreateInstance(navbarCollapse, {
                toggle: false
            });
            
            if (navbarCollapse.classList.contains('show')) {
                bsCollapse.hide();
            } else {
                bsCollapse.show();
            }
        });
        
        console.log('✅ Mobile navbar collapse initialized');
    } else {
        console.warn('⚠️ Navbar elements not found');
    }
});
