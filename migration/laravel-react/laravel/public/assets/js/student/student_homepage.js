  function confirmLogout(event) {
      event.preventDefault();
      if (confirm("Are you sure you want to logout?")) {
        window.location.href = 'logout.php';
      }
    }

    // Distribution Carousel Functionality
    document.addEventListener('DOMContentLoaded', function() {
      const carousel = document.getElementById('distributionCarousel');
      if (!carousel) return; // Exit if no carousel found

      const track = document.getElementById('carouselTrack');
      const prevBtn = document.getElementById('carouselPrev');
      const nextBtn = document.getElementById('carouselNext');
      const dots = document.querySelectorAll('.carousel-dot');
      
      let currentPage = 0;
      const totalPages = dots.length;
      let startX = 0;
      let currentX = 0;
      let isDragging = false;
      let dragThreshold = 50;

      // Initialize carousel
      function updateCarousel(animate = true) {
        if (!animate) {
          track.classList.add('carousel-loading');
          track.style.transition = 'none';
        }
        
        const translateX = -currentPage * 100;
        track.style.transform = `translateX(${translateX}%)`;
        
        // Update navigation buttons
        if (prevBtn && nextBtn) {
          prevBtn.disabled = currentPage === 0;
          nextBtn.disabled = currentPage === totalPages - 1;
        }
        
        // Update dot indicators
        dots.forEach((dot, index) => {
          dot.classList.toggle('active', index === currentPage);
        });
        
        setTimeout(() => {
          if (!animate) {
            track.classList.remove('carousel-loading');
            track.style.transition = '';
          }
        }, 50);
      }

      // Navigation functions
      function goToPage(page) {
        if (page >= 0 && page < totalPages) {
          currentPage = page;
          updateCarousel();
        }
      }

      function nextPage() {
        if (currentPage < totalPages - 1) {
          currentPage++;
          updateCarousel();
        }
      }

      function prevPage() {
        if (currentPage > 0) {
          currentPage--;
          updateCarousel();
        }
      }

      // Event listeners for buttons
      if (prevBtn) prevBtn.addEventListener('click', prevPage);
      if (nextBtn) nextBtn.addEventListener('click', nextPage);

      // Dot indicators
      dots.forEach((dot, index) => {
        dot.addEventListener('click', () => goToPage(index));
      });

      // Touch/Mouse events for swipe functionality
      function handleStart(e) {
        isDragging = true;
        startX = e.type === 'mousedown' ? e.clientX : e.touches[0].clientX;
        currentX = startX;
        track.classList.add('swiping');
      }

      function handleMove(e) {
        if (!isDragging) return;
        
        e.preventDefault();
        currentX = e.type === 'mousemove' ? e.clientX : e.touches[0].clientX;
        const deltaX = currentX - startX;
        const currentTranslate = -currentPage * 100;
        const newTranslate = currentTranslate + (deltaX / track.offsetWidth) * 100;
        
        track.style.transform = `translateX(${newTranslate}%)`;
      }

      function handleEnd() {
        if (!isDragging) return;
        
        isDragging = false;
        track.classList.remove('swiping');
        track.classList.add('snapping');
        
        const deltaX = currentX - startX;
        const threshold = dragThreshold;
        
        if (Math.abs(deltaX) > threshold) {
          if (deltaX > 0 && currentPage > 0) {
            prevPage();
          } else if (deltaX < 0 && currentPage < totalPages - 1) {
            nextPage();
          } else {
            updateCarousel();
          }
        } else {
          updateCarousel();
        }
        
        setTimeout(() => {
          track.classList.remove('snapping');
        }, 300);
      }

      // Mouse events
      track.addEventListener('mousedown', handleStart);
      document.addEventListener('mousemove', handleMove);
      document.addEventListener('mouseup', handleEnd);

      // Touch events
      track.addEventListener('touchstart', handleStart, { passive: true });
      track.addEventListener('touchmove', handleMove, { passive: false });
      track.addEventListener('touchend', handleEnd);

      // Keyboard navigation
      document.addEventListener('keydown', function(e) {
        if (!carousel.closest('.custom-card').matches(':hover')) return;
        
        if (e.key === 'ArrowLeft') {
          e.preventDefault();
          prevPage();
        } else if (e.key === 'ArrowRight') {
          e.preventDefault();
          nextPage();
        }
      });

      // Initialize carousel state
      updateCarousel(false);

      // Auto-resize handling
      window.addEventListener('resize', function() {
        updateCarousel(false);
      });

      // Prevent text selection while dragging
      track.addEventListener('selectstart', function(e) {
        if (isDragging) e.preventDefault();
      });
    });