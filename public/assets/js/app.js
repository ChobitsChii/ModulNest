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

(function () {
    'use strict';

    function initHomepagePreviewTabs() {
        var tabs = document.querySelectorAll('[data-homepage-preview-tab]');
        var panels = document.querySelectorAll('[data-homepage-preview-panel]');
        if (tabs.length === 0 || panels.length === 0) {
            return;
        }

        function activate(mode, updateHistory) {
            tabs.forEach(function (tab) {
                var active = tab.getAttribute('data-homepage-preview-tab') === mode;
                tab.classList.toggle('btn-primary', active);
                tab.classList.toggle('btn-outline-secondary', !active);
                tab.setAttribute('aria-pressed', active ? 'true' : 'false');
            });

            panels.forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-homepage-preview-panel') !== mode;
            });

            if (updateHistory && window.history && window.history.replaceState) {
                var url = new URL(window.location.href);
                url.searchParams.set('preview', mode);
                url.hash = 'homepage-preview';
                window.history.replaceState(null, '', url.toString());
            }
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function (event) {
                var mode = tab.getAttribute('data-homepage-preview-tab') || 'all';
                event.preventDefault();
                activate(mode, true);
            });
        });
    }

    function initHomepageBlockForms() {
        document.querySelectorAll('[data-homepage-block-form]').forEach(function (form) {
            var typeSelect = form.querySelector('[data-homepage-block-type]');
            if (!(typeSelect instanceof HTMLSelectElement)) {
                return;
            }

            var contentLabel = form.querySelector('[data-homepage-content-label]');
            var contentHelp = form.querySelector('[data-homepage-content-help]');
            var markdownHelp = form.querySelector('[data-homepage-markdown-help]');
            var moduleListHint = form.querySelector('[data-homepage-module-list-hint]');
            var featureListHint = form.querySelector('[data-homepage-feature-list-hint]');
            var buttonFieldset = form.querySelector('[data-homepage-button-fieldset]');
            var buttonLayoutField = form.querySelector('[data-homepage-button-layout-field]');
            var itemsFieldset = form.querySelector('[data-homepage-items-fieldset]');

            function setFieldsetVisible(fieldset, visible) {
                if (!(fieldset instanceof Element)) {
                    return;
                }
                fieldset.classList.toggle('d-none', !visible);
                fieldset.querySelectorAll('input, textarea, select').forEach(function (input) {
                    input.disabled = !visible;
                });
            }

            function update() {
                var type = typeSelect.value;
                var isModuleList = type === 'module_list';
                var isFeatureList = type === 'feature_list';

                if (contentLabel !== null) {
                    contentLabel.textContent = (isModuleList || isFeatureList) ? 'Optionale Einleitung' : 'Markdown-Inhalt';
                }
                if (contentHelp !== null) {
                    contentHelp.textContent = (isModuleList || isFeatureList)
                        ? 'Optionaler Markdown-Text oberhalb der automatisch erzeugten Inhalte. HTML wird aus Sicherheitsgründen entfernt.'
                        : 'Markdown-Inhalt für diesen Block. HTML wird aus Sicherheitsgründen entfernt.';
                }
                if (markdownHelp !== null) {
                    markdownHelp.classList.toggle('d-none', false);
                }
                if (moduleListHint !== null) {
                    moduleListHint.classList.toggle('d-none', !isModuleList);
                }
                if (featureListHint !== null) {
                    featureListHint.classList.toggle('d-none', !isFeatureList);
                }

                setFieldsetVisible(buttonFieldset, type === 'custom_content');
                if (buttonLayoutField instanceof Element) {
                    buttonLayoutField.classList.toggle('d-none', type !== 'custom_content');
                    buttonLayoutField.querySelectorAll('input, textarea, select').forEach(function (input) {
                        input.disabled = type !== 'custom_content';
                    });
                }
                setFieldsetVisible(itemsFieldset, isFeatureList);
            }

            typeSelect.addEventListener('change', update);
            update();
        });
    }

    function nextIndex(container, rowSelector) {
        return container.querySelectorAll(rowSelector).length;
    }

    function initHomepageRepeaters() {
        document.querySelectorAll('[data-homepage-add-button]').forEach(function (button) {
            button.addEventListener('click', function () {
                var container = document.querySelector('[data-homepage-buttons]');
                if (!(container instanceof Element)) {
                    return;
                }
                var index = nextIndex(container, '[data-homepage-button-row]');
                var row = document.createElement('div');
                row.className = 'row g-2 align-items-end homepage-repeater-row';
                row.setAttribute('data-homepage-button-row', '');
                row.innerHTML = '' +
                    '<div class="col-12 col-md-3"><label class="form-label small">Text</label><input class="form-control" type="text" name="buttons[' + index + '][label]" maxlength="120"></div>' +
                    '<div class="col-12 col-md-5"><label class="form-label small">URL</label><input class="form-control" type="text" name="buttons[' + index + '][url]" maxlength="255" placeholder="/login oder https://example.com"></div>' +
                    '<div class="col-8 col-md-3"><label class="form-label small">Variante</label><select class="form-select" name="buttons[' + index + '][variant]"><option value="primary">Primär</option><option value="secondary">Sekundär</option></select></div>' +
                    '<div class="col-12 col-md-auto homepage-repeater-action"><button class="btn btn-outline-danger w-100" type="button" data-homepage-remove-row>Entfernen</button></div>';
                container.appendChild(row);
            });
        });

        document.querySelectorAll('[data-homepage-add-item]').forEach(function (button) {
            button.addEventListener('click', function () {
                var container = document.querySelector('[data-homepage-items]');
                if (!(container instanceof Element)) {
                    return;
                }
                var index = nextIndex(container, '[data-homepage-item-row]');
                var row = document.createElement('div');
                row.className = 'row g-2 align-items-end homepage-repeater-row';
                row.setAttribute('data-homepage-item-row', '');
                row.innerHTML = '' +
                    '<div class="col-12 col-md-4"><label class="form-label small">Titel</label><input class="form-control" type="text" name="items[' + index + '][title]" maxlength="190"></div>' +
                    '<div class="col-12 col-md-7"><label class="form-label small">Text</label><textarea class="form-control" name="items[' + index + '][content_markdown]" rows="2"></textarea></div>' +
                    '<div class="col-12 col-md-auto homepage-repeater-action"><button class="btn btn-outline-danger w-100" type="button" data-homepage-remove-row>Entfernen</button></div>';
                container.appendChild(row);
            });
        });

        document.addEventListener('click', function (event) {
            var button = event.target instanceof Element ? event.target.closest('[data-homepage-remove-row]') : null;
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }
            var row = button.closest('.homepage-repeater-row');
            if (row instanceof Element) {
                row.remove();
            }
        });
    }

    function showHomepageMessage(message, ok) {
        var target = document.querySelector('[data-homepage-message]');
        if (!(target instanceof Element)) {
            return;
        }
        target.textContent = message;
        target.className = 'homepage-ajax-message mt-3 alert ' + (ok ? 'alert-success' : 'alert-danger');
    }

    function currentHomepagePreviewMode() {
        var activeTab = document.querySelector('[data-homepage-preview-tab].btn-primary');
        return activeTab instanceof Element ? (activeTab.getAttribute('data-homepage-preview-tab') || 'all') : 'all';
    }

    function refreshHomepagePreview() {
        var current = document.querySelector('[data-homepage-preview-fragment]');
        if (!(current instanceof Element)) {
            return Promise.resolve();
        }

        var url = new URL(window.location.href);
        url.searchParams.set('preview', currentHomepagePreviewMode());
        url.hash = '';

        return fetch(url.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'text/html',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            var contentType = response.headers.get('content-type') || '';
            if (response.redirected || !response.ok || contentType.indexOf('text/html') === -1) {
                throw new Error('Vorschau konnte nicht aktualisiert werden. Bitte lade die Seite neu.');
            }
            return response.text();
        }).then(function (html) {
            var parser = new DOMParser();
            var documentFragment = parser.parseFromString(html, 'text/html');
            var next = documentFragment.querySelector('[data-homepage-preview-fragment]');
            if (!(next instanceof Element)) {
                throw new Error('Vorschau konnte nicht gelesen werden. Bitte lade die Seite neu.');
            }
            current.replaceWith(next);
            initHomepagePreviewTabs();
        });
    }

    function updateMoveButtons() {
        var rows = document.querySelectorAll('[data-homepage-block-row]');
        rows.forEach(function (row, index) {
            var up = row.querySelector('input[name="direction"][value="up"]');
            var down = row.querySelector('input[name="direction"][value="down"]');
            if (up !== null) {
                var button = up.closest('form').querySelector('button');
                if (button instanceof HTMLButtonElement) {
                    button.disabled = index === 0;
                }
            }
            if (down !== null) {
                var downButton = down.closest('form').querySelector('button');
                if (downButton instanceof HTMLButtonElement) {
                    downButton.disabled = index === rows.length - 1;
                }
            }
        });
    }

    function updateHomepageActiveCount() {
        var target = document.querySelector('[data-homepage-active-count]');
        if (!(target instanceof Element)) {
            return;
        }
        var count = 0;
        document.querySelectorAll('[data-homepage-status-badge]').forEach(function (badge) {
            if (badge.textContent.trim() === 'Aktiv') {
                count += 1;
            }
        });
        target.textContent = String(count);
    }

    function initHomepageAjaxActions() {
        document.querySelectorAll('[data-homepage-ajax-form]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!(form instanceof HTMLFormElement)) {
                    return;
                }
                event.preventDefault();
                var submitter = event.submitter instanceof HTMLButtonElement ? event.submitter : null;
                if (submitter !== null) {
                    submitter.disabled = true;
                }

                var data = new FormData(form);
                data.set('ajax', '1');
                var csrfToken = String(data.get('_csrf') || '');
                fetch(form.action, {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-Token': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }).then(function (response) {
                    var contentType = response.headers.get('content-type') || '';
                    if (response.redirected || response.status === 401 || response.status === 403 || response.status === 419 || contentType.indexOf('application/json') === -1) {
                        throw new Error('Deine Sitzung ist abgelaufen oder der Server hat keine JSON-Antwort geliefert. Bitte lade die Seite neu und melde dich bei Bedarf erneut an.');
                    }

                    return response.json().catch(function () {
                        throw new Error('Die Serverantwort konnte nicht gelesen werden. Bitte lade die Seite neu.');
                    }).then(function (payload) {
                        if (!response.ok || !payload.ok) {
                            throw new Error(payload.message || 'Aktion konnte nicht ausgeführt werden.');
                        }
                        return payload;
                    });
                }).then(function (payload) {
                    var action = form.getAttribute('data-homepage-action') || '';
                    var row = form.closest('[data-homepage-block-row]');
                    if (action === 'move' && row instanceof Element) {
                        var direction = String(payload.direction || data.get('direction') || '');
                        var sibling = direction === 'up' ? row.previousElementSibling : row.nextElementSibling;
                        if (sibling !== null && row.parentNode !== null) {
                            if (direction === 'up') {
                                row.parentNode.insertBefore(row, sibling);
                            } else {
                                row.parentNode.insertBefore(sibling, row);
                            }
                            updateMoveButtons();
                        }
                    } else if (action === 'toggle' && row instanceof Element) {
                        var enabled = Boolean(payload.is_enabled);
                        var badge = row.querySelector('[data-homepage-status-badge]');
                        var toggleButton = row.querySelector('[data-homepage-toggle-button]');
                        var input = form.querySelector('input[name="is_enabled"]');
                        if (badge instanceof Element) {
                            badge.textContent = payload.status_label || (enabled ? 'Aktiv' : 'Inaktiv');
                            badge.classList.toggle('text-bg-success', enabled);
                            badge.classList.toggle('text-bg-secondary', !enabled);
                        }
                        if (toggleButton instanceof HTMLButtonElement) {
                            toggleButton.textContent = payload.button_label || (enabled ? 'Deaktivieren' : 'Aktivieren');
                        }
                        if (input instanceof HTMLInputElement) {
                            input.value = enabled ? '0' : '1';
                        }
                        updateHomepageActiveCount();
                    } else if (action === 'visibility' && row instanceof Element) {
                        var visible = Boolean(payload.visible);
                        var field = String(payload.field || '');
                        var badgeButton = row.querySelector('[data-homepage-visibility-badge][data-field="' + CSS.escape(field) + '"]');
                        var visibleInput = form.querySelector('input[name="visible"]');
                        if (badgeButton instanceof Element) {
                            badgeButton.classList.toggle('text-bg-primary', visible);
                            badgeButton.classList.toggle('text-bg-secondary', !visible);
                        }
                        if (visibleInput instanceof HTMLInputElement) {
                            visibleInput.value = visible ? '0' : '1';
                        }
                    } else if (action === 'delete' && row instanceof Element) {
                        row.remove();
                        updateMoveButtons();
                        updateHomepageActiveCount();
                    } else if (action === 'publish') {
                        var published = Boolean(payload.is_published);
                        var badge = document.querySelector('[data-homepage-published-badge]');
                        var publishInput = document.querySelector('[data-homepage-published-input]');
                        var publishButton = document.querySelector('[data-homepage-published-button]');
                        if (badge instanceof Element) {
                            badge.textContent = payload.label || (published ? 'Konfigurierte Homepage veröffentlicht' : 'Standard-Startseite aktiv');
                            badge.classList.toggle('text-bg-success', published);
                            badge.classList.toggle('text-bg-secondary', !published);
                        }
                        if (publishInput instanceof HTMLInputElement) {
                            publishInput.value = published ? '0' : '1';
                        }
                        if (publishButton instanceof HTMLButtonElement) {
                            publishButton.textContent = payload.button_label || (published ? 'Standard-Startseite verwenden' : 'Konfigurierte Startseite veröffentlichen');
                            publishButton.classList.toggle('btn-primary', !published);
                            publishButton.classList.toggle('btn-outline-secondary', published);
                        }
                    }
                    return refreshHomepagePreview().then(function () {
                        showHomepageMessage(payload.message || 'Aktion ausgeführt.', true);
                    }).catch(function (error) {
                        showHomepageMessage((payload.message || 'Aktion ausgeführt.') + ' ' + (error.message || ''), false);
                    });
                }).catch(function (error) {
                    var message = error && error.message === 'Failed to fetch'
                        ? 'Netzwerkfehler: Die Aktion konnte nicht abgeschlossen werden. Bitte prüfe deine Verbindung und lade die Seite bei Bedarf neu.'
                        : ((error && error.message) || 'Aktion konnte nicht ausgeführt werden. Bitte prüfe deine Verbindung und lade die Seite bei Bedarf neu.');
                    showHomepageMessage(message, false);
                }).finally(function () {
                    if (submitter !== null) {
                        submitter.disabled = false;
                    }
                });
            });
        });
        updateMoveButtons();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initHomepagePreviewTabs();
            initHomepageBlockForms();
            initHomepageRepeaters();
            initHomepageAjaxActions();
        });
    } else {
        initHomepagePreviewTabs();
        initHomepageBlockForms();
        initHomepageRepeaters();
        initHomepageAjaxActions();
    }
})();
