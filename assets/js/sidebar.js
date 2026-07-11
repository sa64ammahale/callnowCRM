(function () {
    'use strict';

    var STORAGE_KEY = 'callnow-sidebar';
    var MQ_MOBILE = '(max-width: 991.98px)';
    var STATES = { EXPANDED: 'expanded', COLLAPSED: 'collapsed', MOBILE: 'mobile-open' };

    function isMobile() {
        return window.matchMedia && window.matchMedia(MQ_MOBILE).matches;
    }

    function getStored() {
        try { return localStorage.getItem(STORAGE_KEY); } catch (e) { return null; }
    }

    function setStored(value) {
        try { localStorage.setItem(STORAGE_KEY, value); } catch (e) {}
    }

    function apply(state) {
        document.body.setAttribute('data-sidebar', state);
    }

    function init() {
        var stored = getStored();
        if (isMobile()) {
            apply(STATES.MOBILE);
        } else if (stored === STATES.COLLAPSED) {
            apply(STATES.COLLAPSED);
        } else {
            apply(STATES.EXPANDED);
        }

        var btn = document.getElementById('sidebarToggle');
        if (btn) {
            btn.addEventListener('click', function () {
                var current = document.body.getAttribute('data-sidebar');
                var next;
                if (isMobile()) {
                    next = (current === STATES.MOBILE) ? STATES.EXPANDED : STATES.MOBILE;
                } else {
                    next = (current === STATES.COLLAPSED) ? STATES.EXPANDED : STATES.COLLAPSED;
                }
                apply(next);
                if (!isMobile()) setStored(next);
            });
        }

        document.addEventListener('click', function (e) {
            if (!isMobile()) return;
            if (document.body.getAttribute('data-sidebar') !== STATES.MOBILE) return;
            var sidebar = document.getElementById('appSidebar');
            var toggle = document.getElementById('sidebarToggle');
            if (!sidebar) return;
            if (sidebar.contains(e.target) || (toggle && toggle.contains(e.target))) return;
            apply(STATES.EXPANDED);
        });

        if (window.matchMedia) {
            var mq = window.matchMedia(MQ_MOBILE);
            var onChange = function () {
                if (isMobile()) {
                    apply(STATES.EXPANDED);
                } else {
                    var storedNow = getStored();
                    apply(storedNow === STATES.COLLAPSED ? STATES.COLLAPSED : STATES.EXPANDED);
                }
            };
            if (mq.addEventListener) mq.addEventListener('change', onChange);
            else if (mq.addListener) mq.addListener(onChange);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
