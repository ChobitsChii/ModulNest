(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var bannerContainer = document.getElementById('admin-update-banner-container');
        var badge = document.getElementById('admin-update-badge');

        if (!bannerContainer && !badge) {
            return;
        }

        var storageKey = 'modulnest_update_status_cache';
        var DISMISS_STORAGE_KEY = 'modulnest_update_dismissed_fingerprint';

        // Clean up legacy dismissal keys from older versions
        try {
            for (var k = sessionStorage.length - 1; k >= 0; k--) {
                var sk = sessionStorage.key(k);
                if (sk && sk.indexOf('modulnest_update_dismissed_') === 0) {
                    sessionStorage.removeItem(sk);
                }
            }
        } catch (e) {}

        function computeUpdateFingerprint(data) {
            if (!data || !data.has_updates) {
                return '';
            }
            var parts = [];
            if (data.core_update_available && data.core_latest_version) {
                parts.push('core:' + data.core_latest_version);
            }
            if (Array.isArray(data.module_updates) && data.module_updates.length > 0) {
                var sorted = data.module_updates.slice().sort(function (a, b) {
                    return String(a.id || '').localeCompare(String(b.id || ''));
                });
                for (var i = 0; i < sorted.length; i++) {
                    var item = sorted[i];
                    if (item && item.id) {
                        parts.push('mod:' + item.id + '@' + (item.available_version || ''));
                    }
                }
            } else if (data.module_updates_count > 0) {
                parts.push('mods:' + data.module_updates_count);
            }
            return parts.join(';');
        }

        function updateUI(data) {
            if (!data || !data.has_updates) {
                if (badge) {
                    badge.classList.add('d-none');
                    badge.textContent = '!';
                }
                var itemBadges = document.querySelectorAll('.admin-updates-item-badge, .admin-catalog-item-badge');
                for (var b = 0; b < itemBadges.length; b++) {
                    itemBadges[b].classList.add('d-none');
                    itemBadges[b].textContent = '!';
                }
                if (bannerContainer) {
                    bannerContainer.innerHTML = '';
                }
                try {
                    sessionStorage.removeItem(storageKey);
                    localStorage.removeItem(DISMISS_STORAGE_KEY);
                    sessionStorage.removeItem(DISMISS_STORAGE_KEY);
                } catch (e) {}
                return;
            }

            var coreCount = data.core_update_available ? 1 : 0;
            var moduleCount = parseInt(data.module_updates_count, 10) || 0;
            var totalUpdates = coreCount + moduleCount;

            // 1. Admin Header badge
            if (badge) {
                badge.textContent = totalUpdates > 0 ? String(totalUpdates) : '!';
                badge.classList.remove('d-none');
            }

            // 2. Badges inside Admin navigation (dropdown, tabs, sidebar)
            var updatesBadges = document.querySelectorAll('.admin-updates-item-badge');
            var updatesBadgeCount = coreCount > 0 ? coreCount : (moduleCount > 0 ? moduleCount : 1);
            for (var u = 0; u < updatesBadges.length; u++) {
                updatesBadges[u].textContent = String(updatesBadgeCount);
                updatesBadges[u].classList.remove('d-none');
            }

            var catalogBadges = document.querySelectorAll('.admin-catalog-item-badge');
            for (var c = 0; c < catalogBadges.length; c++) {
                if (moduleCount > 0) {
                    catalogBadges[c].textContent = String(moduleCount);
                    catalogBadges[c].classList.remove('d-none');
                } else {
                    catalogBadges[c].classList.add('d-none');
                }
            }

            // 3. Don't render banner if user is already on updates pages
            var currentPath = window.location.pathname || '';
            if (currentPath === '/admin/updates' || currentPath.startsWith('/admin/updates/')) {
                if (bannerContainer) {
                    bannerContainer.innerHTML = '';
                }
                return;
            }

            // 4. Version-specific banner dismissal
            var currentFingerprint = computeUpdateFingerprint(data);
            var dismissedFingerprint = '';
            try {
                dismissedFingerprint = localStorage.getItem(DISMISS_STORAGE_KEY) || sessionStorage.getItem(DISMISS_STORAGE_KEY) || '';
            } catch (e) {}

            if (dismissedFingerprint && dismissedFingerprint === currentFingerprint) {
                if (bannerContainer) {
                    bannerContainer.innerHTML = '';
                }
                return;
            }

            if (!bannerContainer) {
                return;
            }

            var parts = [];
            if (data.core_update_available && data.core_latest_version) {
                parts.push('ModulNest Core <strong>' + escapeHtml(data.core_latest_version) + '</strong>');
            }
            if (data.module_updates_count > 0) {
                parts.push('<strong>' + data.module_updates_count + '</strong> Modul-Update' + (data.module_updates_count > 1 ? 's' : ''));
            }

            var updateText = parts.join(' und ');
            var linkUrl = data.core_update_available ? '/admin/updates' : '/admin/module-catalog?bereich=updates';
            var linkText = data.core_update_available ? 'Zu den Core-Updates' : 'Zum Modul-Katalog';

            var barHtml =
                '<div class=\"admin-update-bar bg-info-subtle border-bottom border-info-subtle py-2 px-3\" role=\"region\" aria-label=\"Update-Benachrichtigung\">' +
                    '<div class=\"container app-container d-flex flex-wrap align-items-center justify-content-between gap-2\">' +
                        '<div class=\"d-flex align-items-center gap-2 small text-body\">' +
                            '<i class=\"bi bi-arrow-up-circle-fill text-info flex-shrink-0 fs-6\"></i>' +
                            '<div>' +
                                '<strong>Update verfügbar:</strong> ' + updateText + ' stehen zur Installation bereit.' +
                            '</div>' +
                        '</div>' +
                        '<div class=\"d-flex align-items-center gap-2 ms-auto\">' +
                            '<a href=\"' + linkUrl + '\" class=\"btn btn-sm btn-info text-white text-nowrap py-0 px-2\" style=\"font-size:0.8rem;line-height:1.8;\">' +
                                '<i class=\"bi bi-cloud-arrow-down me-1\"></i>' + linkText +
                            '</a>' +
                            '<button type=\"button\" class=\"btn-close p-1\" style=\"font-size:0.65rem;\" aria-label=\"Schließen\" id=\"admin-update-dismiss-btn\" title=\"Hinweis für diese Version ausblenden\"></button>' +
                        '</div>' +
                    '</div>' +
                '</div>';

            bannerContainer.innerHTML = barHtml;

            var dismissBtn = document.getElementById('admin-update-dismiss-btn');
            if (dismissBtn) {
                dismissBtn.addEventListener('click', function () {
                    try {
                        localStorage.setItem(DISMISS_STORAGE_KEY, currentFingerprint);
                        sessionStorage.setItem(DISMISS_STORAGE_KEY, currentFingerprint);
                    } catch (e) {}
                    bannerContainer.innerHTML = '';
                });
            }
        }

        // 1. Instant rendering from session cache (0ms delay, prevents layout shift / sliding animation on navigation)
        try {
            var cached = sessionStorage.getItem(storageKey);
            if (cached) {
                var cachedData = JSON.parse(cached);
                if (cachedData && typeof cachedData === 'object') {
                    updateUI(cachedData);
                }
            }
        } catch (e) {}

        // 2. Background fresh verification
        fetch('/admin/api/updates/status', {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function (response) {
            if (!response.ok) {
                return null;
            }
            return response.json();
        })
        .then(function (data) {
            if (data && data.has_updates) {
                try {
                    sessionStorage.setItem(storageKey, JSON.stringify(data));
                } catch (e) {}
                updateUI(data);
            } else {
                updateUI(null);
            }
        })
        .catch(function () {
            // Suppress background check errors silently
        });

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    });
})();
