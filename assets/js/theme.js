(function () {
    'use strict';

    try {
        localStorage.removeItem('callnow-theme');
        localStorage.setItem('callnow-theme', 'light');
    } catch (e) {}

    document.documentElement.setAttribute('data-bs-theme', 'light');

    function applyPermanentLightTheme() {
        document.documentElement.setAttribute('data-bs-theme', 'light');
        var icon = document.getElementById('themeIcon');
        if (icon) {
            icon.className = 'bi bi-sun';
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyPermanentLightTheme);
    } else {
        applyPermanentLightTheme();
    }
})();
