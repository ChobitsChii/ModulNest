(function () {
    'use strict';

    var root = document.documentElement;
    var guestStorageKey = 'modulon_guest_theme';
    var media = window.matchMedia('(prefers-color-scheme: dark)');
    var authenticated = root.dataset.themeAuthenticated === 'true';
    var allowed = ['system', 'light', 'dark'];

    function valid(mode) {
        return allowed.indexOf(mode) !== -1;
    }

    function storedGuestMode() {
        try {
            var stored = window.localStorage.getItem(guestStorageKey);
            return valid(stored) ? stored : 'system';
        } catch (_) {
            return 'system';
        }
    }

    var mode = authenticated
        ? (valid(root.dataset.themeMode) ? root.dataset.themeMode : 'system')
        : storedGuestMode();

    function apply(nextMode) {
        mode = valid(nextMode) ? nextMode : 'system';
        var resolved = mode === 'system' ? (media.matches ? 'dark' : 'light') : mode;
        root.dataset.themeMode = mode;
        root.dataset.theme = resolved;
        root.dataset.bsTheme = resolved;
        root.style.colorScheme = resolved;
        window.dispatchEvent(new CustomEvent('modulon:themechange', {
            detail: { mode: mode, resolved: resolved }
        }));
        return resolved;
    }

    function setMode(nextMode, persistGuest) {
        if (!valid(nextMode)) {
            return false;
        }
        if (!authenticated && persistGuest !== false) {
            try {
                window.localStorage.setItem(guestStorageKey, nextMode);
            } catch (_) {
                // Storage can be disabled; the live theme still works for this page.
            }
        }
        apply(nextMode);
        return true;
    }

    function handleSystemChange() {
        if (mode === 'system') {
            apply(mode);
        }
    }

    if (typeof media.addEventListener === 'function') {
        media.addEventListener('change', handleSystemChange);
    } else if (typeof media.addListener === 'function') {
        media.addListener(handleSystemChange);
    }

    window.ModulonTheme = {
        getMode: function () { return mode; },
        getResolvedTheme: function () { return root.dataset.theme || 'light'; },
        isAuthenticated: function () { return authenticated; },
        setMode: setMode
    };

    apply(mode);
})();
