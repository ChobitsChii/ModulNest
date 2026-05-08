(function () {
    var root = document.querySelector('[data-modulon-overlay-root]');
    if (!root) {
        return;
    }

    var toggle = root.querySelector('[data-modulon-overlay-toggle]');
    var panel = root.querySelector('[data-modulon-overlay-panel]');
    if (!toggle || !panel) {
        return;
    }

    var close = function () {
        root.classList.remove('modulon-overlay-open');
        panel.setAttribute('aria-hidden', 'true');
    };

    var open = function () {
        root.classList.add('modulon-overlay-open');
        panel.setAttribute('aria-hidden', 'false');
    };

    toggle.addEventListener('click', function (event) {
        event.preventDefault();
        if (root.classList.contains('modulon-overlay-open')) {
            close();
            return;
        }
        open();
    });

    document.addEventListener('click', function (event) {
        if (!root.classList.contains('modulon-overlay-open')) {
            return;
        }
        if (root.contains(event.target)) {
            return;
        }
        close();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            close();
        }
    });
})();
