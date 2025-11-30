<?php
/**
 * Student Settings Navigation Sidebar
 * Modular component for settings pages
 */

// Prevent duplicate inclusion
if (defined('STUDENT_SETTINGS_SIDEBAR_LOADED')) {
    return;
}
define('STUDENT_SETTINGS_SIDEBAR_LOADED', true);

// Get current page to highlight active item (only for separate pages, not anchor links)
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!-- Settings Navigation Sidebar -->
<div class="col-12 col-lg-3">
  <div class="settings-nav sticky-top" style="top: 100px;" id="settingsNav">
    <a href="student_settings.php#account" class="settings-nav-item" data-section="account">
        <i class="bi bi-person-circle me-2"></i>
        Account
    </a>
    <a href="security_privacy.php" class="settings-nav-item <?= $current_page === 'security_privacy.php' ? 'active' : '' ?>" data-section="security">
        <i class="bi bi-key me-2"></i>
        Password
    </a>
    
    <a href="accessibility.php" class="settings-nav-item <?= $current_page === 'accessibility.php' ? 'active' : '' ?>">
        <i class="bi bi-universal-access me-2"></i>
        Accessibility
    </a>
    <a href="active_sessions.php" class="settings-nav-item <?= $current_page === 'active_sessions.php' ? 'active' : '' ?>">
        <i class="bi bi-laptop me-2"></i>
        Active Sessions
    </a>
    <a href="security_activity.php" class="settings-nav-item <?= $current_page === 'security_activity.php' ? 'active' : '' ?>">
        <i class="bi bi-clock-history me-2"></i>
        Security Activity
    </a>
  </div>
</div>

<script>
// Scroll Spy for Settings Navigation (only on student_settings.php)
<?php if ($current_page === 'student_settings.php'): ?>
document.addEventListener('DOMContentLoaded', function() {
    const sections = document.querySelectorAll('.settings-content-section');
    const navItems = document.querySelectorAll('#settingsNav .settings-nav-item[data-section]');
    
    if (sections.length === 0 || navItems.length === 0) return;
    
    function setActiveNav() {
        let currentSection = '';
        const scrollPosition = window.scrollY + 150; // Offset for fixed header
        const windowHeight = window.innerHeight;
        const documentHeight = document.documentElement.scrollHeight;
        
        // Check if user is at the bottom of the page
        const isAtBottom = (window.scrollY + windowHeight) >= documentHeight - 50;
        
        if (isAtBottom) {
            // If at bottom, highlight the last section
            const lastSection = sections[sections.length - 1];
            currentSection = lastSection.getAttribute('id');
        } else {
            // Find which section is currently in view
            sections.forEach(section => {
                const sectionTop = section.offsetTop;
                const sectionHeight = section.offsetHeight;
                
                if (scrollPosition >= sectionTop && scrollPosition < sectionTop + sectionHeight) {
                    currentSection = section.getAttribute('id');
                }
            });
        }
        
        // Update active state on nav items
        navItems.forEach(item => {
            item.classList.remove('active');
            if (item.getAttribute('data-section') === currentSection) {
                item.classList.add('active');
            }
        });
    }
    
    // Run on scroll
    window.addEventListener('scroll', setActiveNav);
    
    // Run on page load
    setActiveNav();
    
    // Also run after a short delay to handle any dynamic content
    setTimeout(setActiveNav, 100);
});
<?php endif; ?>
</script>

<script>
// Scroll Spy for Settings Navigation (only on student_settings.php)
<?php if ($current_page === 'student_settings.php'): ?>
document.addEventListener('DOMContentLoaded', function() {
    const sections = document.querySelectorAll('.settings-content-section');
    const navItems = document.querySelectorAll('#settingsNav .settings-nav-item[data-section]');
    
    if (sections.length === 0 || navItems.length === 0) return;
    
    function setActiveNav() {
        let currentSection = '';
        const scrollPosition = window.scrollY + 150; // Offset for fixed header
        
        // Find which section is currently in view
        sections.forEach(section => {
            const sectionTop = section.offsetTop;
            const sectionHeight = section.offsetHeight;
            
            if (scrollPosition >= sectionTop && scrollPosition < sectionTop + sectionHeight) {
                currentSection = section.getAttribute('id');
            }
        });
        
        // Update active state on nav items
        navItems.forEach(item => {
            item.classList.remove('active');
            if (item.getAttribute('data-section') === currentSection) {
                item.classList.add('active');
            }
        });
    }
    
    // Run on scroll
    window.addEventListener('scroll', setActiveNav);
    
    // Run on page load
    setActiveNav();
    
    // Also run after a short delay to handle any dynamic content
    setTimeout(setActiveNav, 100);
});
<?php endif; ?>
</script>
