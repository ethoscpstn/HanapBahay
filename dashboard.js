// dashboard.js

document.addEventListener("DOMContentLoaded", function () {
    const toggleSidebar = document.querySelector('.toggle-sidebar');
    const sidebar = document.getElementById('sidebar');
  
    // Sidebar collapse toggle
    if (toggleSidebar && sidebar) {
      toggleSidebar.addEventListener('click', () => {
        sidebar.classList.toggle('collapsed-sidebar');
      });
    }
  });

  document.addEventListener("DOMContentLoaded", function () {
    const toggleBtn = document.querySelector(".toggle-sidebar");
    const sidebar = document.getElementById("sidebar");

    toggleBtn.addEventListener("click", () => {
      sidebar.classList.toggle("collapsed-sidebar");
    });
  });

