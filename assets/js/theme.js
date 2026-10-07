/* Theme toggle - persists in localStorage + cookie */
(function() {
    'use strict';

    const THEME_KEY = 'osint-theme';
    const stored = localStorage.getItem(THEME_KEY) || window.OSINT?.theme || 'dark';

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        const icon = document.querySelector('#theme-toggle i');
        if (icon) {
            icon.className = theme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
        }
        // Update cookie for server-side rendering
        document.cookie = `theme=${theme}; path=/; max-age=${365 * 24 * 3600}`;
        localStorage.setItem(THEME_KEY, theme);
    }

    document.addEventListener('DOMContentLoaded', function() {
        applyTheme(stored);

        const btn = document.getElementById('theme-toggle');
        if (btn) {
            btn.addEventListener('click', function() {
                const current = document.documentElement.getAttribute('data-theme');
                applyTheme(current === 'dark' ? 'light' : 'dark');
            });
        }
    });

    // Apply immediately to avoid flash
    applyTheme(stored);
})();
