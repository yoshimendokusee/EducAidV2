const menuToggle = document.getElementById("menu-toggle");
const wrapper = document.getElementById("wrapper");
const backdrop = document.getElementById("sidebarBackdrop");

menuToggle.addEventListener("click", function () {
  wrapper.classList.toggle("toggled");
});

backdrop.addEventListener("click", function () {
  wrapper.classList.remove("toggled");
});
