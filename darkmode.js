// Dark Mode Toggle with LocalStorage Persistence
(function() {
  'use strict';

  // Check for saved theme preference or default to light mode
  const currentTheme = localStorage.getItem('theme') || 'light';

  // Apply theme on page load
  document.documentElement.setAttribute('data-theme', currentTheme);

  // Wait for DOM to load
  document.addEventListener('DOMContentLoaded', function() {
    // Check if dark mode toggle already exists in navigation
    const existingToggle = document.getElementById('darkModeToggle');
    
    if (existingToggle) {
      // Use existing toggle from navigation
      const icon = existingToggle.querySelector('#darkModeIcon');
      if (icon) {
        icon.className = currentTheme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-fill';
      }
      
      // Add click event listener
      existingToggle.addEventListener('click', function() {
        const currentTheme = document.documentElement.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

        document.documentElement.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);

        // Update icon
        if (icon) {
          icon.className = newTheme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-fill';
        }

        // Optional: Add a subtle animation
        document.body.style.transition = 'background-color 0.3s ease, color 0.3s ease';
        setTimeout(() => {
          document.body.style.transition = '';
        }, 300);
      });
    } else {
      // Only create fallback toggle if no navigation toggle exists AND we're not on a page with navigation
      // Check if we're on a page that should have navigation (like homepage, browse, etc.)
      const hasNavigation = document.querySelector('nav.topFixedBar, nav.topbar');
      
      if (!hasNavigation) {
        // Fallback: Create toggle button only for pages without navigation
        const toggleButton = document.createElement('button');
        toggleButton.className = 'dark-mode-toggle';
        toggleButton.setAttribute('aria-label', 'Toggle dark mode');
        toggleButton.innerHTML = `
          <span class="icon-moon">🌙</span>
          <span class="icon-sun">☀️</span>
        `;

        // Position toggle as fixed element
        toggleButton.style.position = 'fixed';
        toggleButton.style.top = '20px';
        toggleButton.style.right = '20px';
        toggleButton.style.zIndex = '9999';
        toggleButton.style.boxShadow = '0 4px 12px rgba(0, 0, 0, 0.15)';
        
        // Append to body for fixed positioning
        document.body.appendChild(toggleButton);

        // Add click event listener
        toggleButton.addEventListener('click', function() {
          const currentTheme = document.documentElement.getAttribute('data-theme');
          const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

          document.documentElement.setAttribute('data-theme', newTheme);
          localStorage.setItem('theme', newTheme);

          // Optional: Add a subtle animation
          document.body.style.transition = 'background-color 0.3s ease, color 0.3s ease';
          setTimeout(() => {
            document.body.style.transition = '';
          }, 300);
        });
      }
    }

    // Optional: Keyboard shortcut (Ctrl/Cmd + Shift + D)
    document.addEventListener('keydown', function(e) {
      if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'D') {
        e.preventDefault();
        const currentTheme = document.documentElement.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);
      }
    });
  });
})();
