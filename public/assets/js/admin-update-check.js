(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var bannerContainer = document.getElementById('admin-update-banner-container');
        var badge = document.getElementById('admin-update-badge');

        if (!bannerContainer && !badge) {
            return;
        }

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
            if (!data || !data.has_updates) {
                return;
            }

            var totalUpdates = (data.core_update_available ? 1 : 0) + (data.module_updates_count || 0);

            if (badge) {
                badge.textContent = totalUpdates > 0 ? String(totalUpdates) : '!';
                badge.classList.remove('d-none');
            }

            var dismissKey = 'modulnest_update_dismissed_' + (data.core_latest_version || '') + '_' + (data.module_updates_count || 0);
            if (sessionStorage.getItem(dismissKey) === 'true') {
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

            var alertHtml =
                '<div class="alert alert-info alert-dismissible fade show d-flex flex-wrap align-items-center justify-content-between gap-3 py-2 px-3 shadow-sm border-info mt-2 mb-3" role="alert">' +
                    '<div class="d-flex align-items-center gap-2">' +
                        '<i class="bi bi-arrow-up-circle-fill text-info fs-5 flex-shrink-0"></i>' +
                        '<div>' +
                            '<strong>Update verfügbar:</strong> ' + updateText + ' stehen zur Installation bereit.' +
                        '</div>' +
                    '</div>' +
                    '<div class="d-flex align-items-center gap-2 ms-auto">' +
                        '<a href="' + linkUrl + '" class="btn btn-sm btn-info text-white text-nowrap">' +
                            '<i class="bi bi-cloud-arrow-down me-1"></i>' + linkText +
                        '</a>' +
                        '<button type="button" class="btn-close position-relative p-2" aria-label="Schließen" id="admin-update-dismiss-btn"></button>' +
                    '</div>' +
                '</div>';

            bannerContainer.innerHTML = alertHtml;

            var dismissBtn = document.getElementById('admin-update-dismiss-btn');
            if (dismissBtn) {
                dismissBtn.addEventListener('click', function () {
                    sessionStorage.setItem(dismissKey, 'true');
                    bannerContainer.innerHTML = '';
                });
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
