(function () {
    'use strict';

    var STORAGE_KEY = 'callnow-sidebar';
    var MQ_MOBILE = '(max-width: 991.98px)';
    var STATES = { EXPANDED: 'expanded', COLLAPSED: 'collapsed', MOBILE: 'mobile-open' };

    var backdrop = null;

    function createBackdrop() {
        if (backdrop) return;
        backdrop = document.createElement('div');
        backdrop.className = 'sidebar-backdrop';
        backdrop.addEventListener('click', closeMobile);
        document.body.appendChild(backdrop);
    }

    function removeBackdrop() {
        if (backdrop) {
            backdrop.remove();
            backdrop = null;
        }
    }

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
        if (isMobile()) {
            if (state === STATES.MOBILE) {
                createBackdrop();
                document.body.style.overflow = 'hidden';
            } else {
                removeBackdrop();
                document.body.style.overflow = '';
            }
        } else {
            removeBackdrop();
            document.body.style.overflow = '';
        }
    }

    function closeMobile() {
        if (isMobile()) {
            apply(STATES.EXPANDED);
        }
    }

    function initAccordion() {
        // Section headers use onclick="toggleSidebarSection(this, event)" in sidebar.php.
        // We do not bind duplicate click listeners here to prevent double-toggle cancellations.
    }

    window.toggleSidebarSection = function (btn, e) {
        if (e) {
            try { e.preventDefault(); e.stopPropagation(); } catch (err) {}
        }
        if (!btn) return;

        if (document.body.getAttribute('data-sidebar') === STATES.COLLAPSED) {
            apply(STATES.EXPANDED);
            setStored(STATES.EXPANDED);
        }

        var section = btn.closest('.app-sidebar-section');
        if (!section) return;

        var isCurrentlyOpen = section.classList.contains('is-open');

        // Accordion behavior: close all other sections
        var allSections = document.querySelectorAll('.app-sidebar-section');
        for (var i = 0; i < allSections.length; i++) {
            var s = allSections[i];
            if (s !== section) {
                s.classList.remove('is-open');
                s.classList.add('is-collapsed');
                var b = s.querySelector('.app-sidebar-section-header');
                if (b) b.setAttribute('aria-expanded', 'false');
                var c = s.querySelector('.app-sidebar-section-collapse');
                if (c) c.style.setProperty('display', 'none', 'important');
            }
        }

        // Toggle the clicked section
        var collapse = section.querySelector('.app-sidebar-section-collapse');
        if (isCurrentlyOpen) {
            section.classList.remove('is-open');
            section.classList.add('is-collapsed');
            btn.setAttribute('aria-expanded', 'false');
            if (collapse) collapse.style.setProperty('display', 'none', 'important');
        } else {
            section.classList.remove('is-collapsed');
            section.classList.add('is-open');
            btn.setAttribute('aria-expanded', 'true');
            if (collapse) collapse.style.setProperty('display', 'block', 'important');
        }
    };

    function init() {
        if (isMobile()) {
            apply(STATES.EXPANDED);
        } else {
            var stored = getStored();
            apply(stored === STATES.COLLAPSED ? STATES.COLLAPSED : STATES.EXPANDED);
        }

        initAccordion();

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

        if (window.matchMedia) {
            var mq = window.matchMedia(MQ_MOBILE);
            var onChange = function () {
                if (isMobile()) {
                    removeBackdrop();
                    document.body.style.overflow = '';
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
