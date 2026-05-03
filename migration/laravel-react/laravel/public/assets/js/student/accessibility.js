  // Global Accessibility Settings Loader
// This script should be included on every page to apply saved accessibility preferences

(function() {
  'use strict';

  // Load and apply saved accessibility preferences (without High Contrast)
  function loadAccessibilityPreferences() {
    const savedTextSize = localStorage.getItem('textSize') || 'normal';
    const savedReduceAnimations = localStorage.getItem('reduceAnimations') === 'true';

    // Apply text size
    document.documentElement.classList.remove('text-small', 'text-normal', 'text-large');
    document.documentElement.classList.add('text-' + savedTextSize);

    // Always disable/remove high contrast mode
    document.documentElement.classList.remove('high-contrast');
    try { localStorage.removeItem('highContrast'); } catch(e) {}

    // Apply reduced animations (also includes simple animations for maximum reduction)
    if (savedReduceAnimations) {
      document.documentElement.classList.add('reduce-animations');
      document.documentElement.classList.add('simple-animations');
    } else {
      document.documentElement.classList.remove('reduce-animations');
      document.documentElement.classList.remove('simple-animations');
    }
  }

  // Apply immediately (before DOM loads to prevent flash)
  loadAccessibilityPreferences();

  // Also apply when DOM is ready (in case script loads late)
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadAccessibilityPreferences);
  }
})();
