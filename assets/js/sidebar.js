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
        var headers = document.querySelectorAll('.app-sidebar-section-header');
        headers.forEach(function (header) {
            header.addEventListener('click', function (e) {
                e.preventDefault();
                var section = header.closest('.app-sidebar-section');
                if (!section) return;

                var isAlreadyOpen = section.classList.contains('is-open');

                // If sidebar is currently collapsed, clicking any section expands it
                if (document.body.getAttribute('data-sidebar') === STATES.COLLAPSED) {
                    apply(STATES.EXPANDED);
                    setStored(STATES.EXPANDED);
                }

                // Accordion behavior: close all other sections (keep only active menu open)
                document.querySelectorAll('.app-sidebar-section').forEach(function (sec) {
                    if (sec !== section) {
                        sec.classList.remove('is-open');
                        sec.classList.add('is-collapsed');
                        var btn = sec.querySelector('.app-sidebar-section-header');
                        if (btn) btn.setAttribute('aria-expanded', 'false');
                        var collapse = sec.querySelector('.app-sidebar-section-collapse');
                        if (collapse) {
                            if (window.jQuery) {
                                window.jQuery(collapse).slideUp(180);
                            } else {
                                collapse.style.display = 'none';
                            }
                        }
                    }
                });

                // Toggle the clicked section
                if (isAlreadyOpen) {
                    section.classList.remove('is-open');
                    section.classList.add('is-collapsed');
                    header.setAttribute('aria-expanded', 'false');
                    var collapse = section.querySelector('.app-sidebar-section-collapse');
                    if (collapse) {
                        if (window.jQuery) {
                            window.jQuery(collapse).slideUp(180);
                        } else {
                            collapse.style.display = 'none';
                        }
                    }
                } else {
                    section.classList.remove('is-collapsed');
                    section.classList.add('is-open');
                    header.setAttribute('aria-expanded', 'true');
                    var collapse = section.querySelector('.app-sidebar-section-collapse');
                    if (collapse) {
                        if (window.jQuery) {
                            window.jQuery(collapse).slideDown(180);
                        } else {
                            collapse.style.display = 'block';
                        }
                    }
                }
            });
        });
    }

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
