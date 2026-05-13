(function () {
    'use strict';

    function isDesktopNavbar() {
        var toggler = document.querySelector('.app-navbar .navbar-toggler');
        if (!toggler) {
            return window.matchMedia('(min-width: 992px)').matches;
        }

        return window.getComputedStyle(toggler).display === 'none';
    }

    document.addEventListener('click', function (event) {
        var link = event.target instanceof Element
            ? event.target.closest('.app-navbar [data-app-nav-dropdown-link]')
            : null;

        if (!(link instanceof HTMLAnchorElement)) {
            return;
        }

        if (!isDesktopNavbar()) {
            return;
        }

        var href = link.getAttribute('href') || '';
        if (href === '' || href === '#') {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        window.location.assign(link.href);
    }, true);
})();
