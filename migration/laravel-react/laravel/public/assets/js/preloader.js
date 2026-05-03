window.addEventListener("load", function () {
  const preloader = document.getElementById("preloader");
  if (preloader) {
    // Wait 3 seconds before hiding
    setTimeout(() => {
      preloader.style.opacity = '0';
      preloader.style.transition = 'opacity 0.5s ease';
      
      // Fully remove after fade-out
      setTimeout(() => {
        preloader.style.display = 'none';
        document.body.style.overflow = 'auto';
      }, 500); // fade-out duration
    }, 3000); // 3 seconds visible
  }
});
