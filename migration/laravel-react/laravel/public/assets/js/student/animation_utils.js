// animation_utils.js — provide a simple-animation mode that simplifies effects
(function() {
  'use strict';

  function applySimpleAnimations() {
    document.documentElement.classList.add('simple-animations');
    // Persist choice
    try { localStorage.setItem('simpleAnimations', 'true'); } catch(e) {}
  }

  function removeSimpleAnimations() {
    document.documentElement.classList.remove('simple-animations');
    try { localStorage.removeItem('simpleAnimations'); } catch(e) {}
  }

  // Expose globally
  window.applySimpleAnimations = applySimpleAnimations;
  window.removeSimpleAnimations = removeSimpleAnimations;

  // Apply on load if set
  if (localStorage.getItem('simpleAnimations') === 'true') {
    applySimpleAnimations();
  }
})();
