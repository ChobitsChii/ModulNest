(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var bannerContainer = document.getElementById('admin-update-banner-container');
        var badge = document.getElementById('admin-update-badge');

        if (!bannerContainer && !badge) {
            return;
        }

        var storageKey = 'modulnest_update_status_cache';

        function updateUI(data) {
            if (!data || !data.has_updates) {
                if (badge) {
                    badge.classList.add('d-none');
                    badge.textContent = '!';
                }
                if (bannerContainer) {
                    bannerContainer.innerHTML = '';
                }
                try {
                    sessionStorage.removeItem(storageKey);
                } catch (e) {}
                return;
            }

            var totalUpdates = (data.core_update_available ? 1 : 0) + (data.module_updates_count || 0);

            if (badge) {
                badge.textContent = totalUpdates > 0 ? String(totalUpdates) : '!';
                badge.classList.remove('d-none');
            }

            // Don't render banner if user is already on updates pages
            var currentPath = window.location.pathname || '';
            if (currentPath === '/admin/updates' || currentPath.startsWith('/admin/updates/')) {
                if (bannerContainer) {
                    bannerContainer.innerHTML = '';
                }
                return;
            }

            var dismissKey = 'modulnest_update_dismissed_' + (data.core_latest_version || '') + '_' + (data.module_updates_count || 0);
            try {
                if (sessionStorage.getItem(dismissKey) === 'true') {
                    if (bannerContainer) {
                        bannerContainer.innerHTML = '';
                    }
                    return;
                }
            } catch (e) {}

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
                '<div class="admin-update-bar bg-info-subtle border-bottom border-info-subtle py-2 px-3" role="region" aria-label="Update-Benachrichtigung">' +
                    '<div class="container app-container d-flex flex-wrap align-items-center justify-content-between gap-2">' +
                        '<div class="d-flex align-items-center gap-2 small text-body">' +
                            '<i class="bi bi-arrow-up-circle-fill text-info flex-shrink-0 fs-6"></i>' +
                            '<div>' +
                                '<strong>Update verfügbar:</strong> ' + updateText + ' stehen zur Installation bereit.' +
                            '</div>' +
                        '</div>' +
                        '<div class="d-flex align-items-center gap-2 ms-auto">' +
                            '<a href="' + linkUrl + '" class="btn btn-sm btn-info text-white text-nowrap py-0 px-2" style="font-size:0.8rem;line-height:1.8;">' +
                                '<i class="bi bi-cloud-arrow-down me-1"></i>' + linkText +
                            '</a>' +
                            '<button type="button" class="btn-close p-1" style="font-size:0.65rem;" aria-label="Schließen" id="admin-update-dismiss-btn" title="Hinweis für diese Sitzung ausblenden"></button>' +
                        '</div>' +
                    '</div>' +
                '</div>';

            bannerContainer.innerHTML = barHtml;

            var dismissBtn = document.getElementById('admin-update-dismiss-btn');
            if (dismissBtn) {
                dismissBtn.addEventListener('click', function () {
                    try {
                        sessionStorage.setItem(dismissKey, 'true');
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
