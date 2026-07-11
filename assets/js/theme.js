(function () {
    'use strict';

    var STORAGE_KEY = 'callnow-theme';
    var VALID = { light: 'light', dark: 'dark' };

    function getStoredTheme() {
        try {
            var t = localStorage.getItem(STORAGE_KEY);
            if (VALID[t]) return t;
        } catch (e) {}
        return null;
    }

    function getSystemTheme() {
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            return 'dark';
        }
        return 'light';
    }

    function applyTheme(theme) {
        if (!VALID[theme]) theme = 'light';
        document.documentElement.setAttribute('data-bs-theme', theme);
        var icon = document.getElementById('themeIcon');
        if (icon) {
            icon.className = theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
        }
    }

    function setTheme(theme, persist) {
        if (!VALID[theme]) theme = 'light';
        applyTheme(theme);
        if (persist) {
            try { localStorage.setItem(STORAGE_KEY, theme); } catch (e) {}
            var base = (document.querySelector('script[data-app-base]') || {}).dataset;
            var url = (window.APP_BASE || '') + 'php_scripts/set_theme.php';
            try {
                fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'theme=' + encodeURIComponent(theme) + '&csrf_token=' + encodeURIComponent(window.APP_CSRF || '')
                }).catch(function () {});
            } catch (e) {}
        }
    }

    function init() {
        var stored = getStoredTheme();
        var initial = stored || (document.documentElement.getAttribute('data-bs-theme')) || getSystemTheme();
        applyTheme(initial);

        var btn = document.getElementById('themeToggle');
        if (btn) {
            btn.addEventListener('click', function () {
                var current = document.documentElement.getAttribute('data-bs-theme') || 'light';
                var next = current === 'dark' ? 'light' : 'dark';
                setTheme(next, true);
            });
        }

        if (!stored && window.matchMedia) {
            var mq = window.matchMedia('(prefers-color-scheme: dark)');
            var listener = function (e) {
                if (!getStoredTheme()) applyTheme(e.matches ? 'dark' : 'light');
            };
            if (mq.addEventListener) mq.addEventListener('change', listener);
            else if (mq.addListener) mq.addListener(listener);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
