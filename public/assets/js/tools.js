(function () {
    'use strict';

    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

    function setText(selector, value) {
        const node = $(selector);
        if (node) {
            node.textContent = value;
        }
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function escapeHtmlWithBreaks(value) {
        return escapeHtml(value).replace(/\n/g, '<br>');
    }

    function initSearch() {
        const input = $('#tools-search');
        if (!input) {
            return;
        }
        input.addEventListener('input', () => {
            const query = input.value.trim().toLowerCase();
            $$('[data-tool-card]').forEach((card) => {
                const haystack = String(card.dataset.toolSearch || '');
                card.hidden = query !== '' && !haystack.includes(query);
            });
        });
    }

    function initTextCounter() {
        const input = $('#tools-text-input');
        if (!input) {
            return;
        }
        const update = () => {
            const text = input.value;
            setText('#tools-count-chars', String(text.length));
            setText('#tools-count-no-space', String(text.replace(/\s/g, '').length));
            setText('#tools-count-words', String((text.trim().match(/\S+/g) || []).length));
            setText('#tools-count-lines', String(text === '' ? 0 : text.split(/\r\n|\r|\n/).length));
            setText('#tools-count-paragraphs', String(text.trim() === '' ? 0 : text.trim().split(/\n\s*\n/).filter(Boolean).length));
        };
        input.addEventListener('input', update);
        update();
    }

    function initTextCleaner() {
        const input = $('#tools-clean-input');
        if (!input) {
            return;
        }
        $$('[data-clean-action]').forEach((button) => {
            button.addEventListener('click', () => {
                const action = button.dataset.cleanAction;
                if (action === 'spaces') {
                    input.value = input.value.replace(/[ \t]{2,}/g, ' ');
                }
                if (action === 'blank-lines') {
                    input.value = input.value.replace(/(?:\r?\n\s*){2,}/g, '\n');
                }
                if (action === 'trim') {
                    input.value = input.value.trim();
                }
                input.dispatchEvent(new Event('input'));
            });
        });
    }

    function utf8ToBase64(value) {
        const bytes = new TextEncoder().encode(value);
        let binary = '';
        bytes.forEach((byte) => { binary += String.fromCharCode(byte); });
        return btoa(binary);
    }

    function base64ToUtf8(value) {
        const binary = atob(value);
        const bytes = Uint8Array.from(binary, (char) => char.charCodeAt(0));
        return new TextDecoder().decode(bytes);
    }

    function initCodecs() {
        const base64Input = $('#tools-base64-input');
        if (base64Input) {
            $('#tools-base64-encode')?.addEventListener('click', () => {
                try {
                    setText('#tools-base64-output', utf8ToBase64(base64Input.value));
                } catch (error) {
                    setText('#tools-base64-output', 'Fehler: ' + error.message);
                }
            });
            $('#tools-base64-decode')?.addEventListener('click', () => {
                try {
                    setText('#tools-base64-output', base64ToUtf8(base64Input.value.trim()));
                } catch (error) {
                    setText('#tools-base64-output', 'Fehler: Base64 konnte nicht dekodiert werden.');
                }
            });
        }

        const urlInput = $('#tools-url-input');
        if (urlInput) {
            $('#tools-url-encode')?.addEventListener('click', () => setText('#tools-url-output', encodeURIComponent(urlInput.value)));
            $('#tools-url-decode')?.addEventListener('click', () => {
                try {
                    setText('#tools-url-output', decodeURIComponent(urlInput.value));
                } catch (error) {
                    setText('#tools-url-output', 'Fehler: URL konnte nicht dekodiert werden.');
                }
            });
        }
    }

    function initJson() {
        const input = $('#tools-json-input');
        const status = $('#tools-json-status');
        if (!input || !status) {
            return;
        }
        function parse() {
            return JSON.parse(input.value);
        }
        $('#tools-json-format')?.addEventListener('click', () => {
            try {
                input.value = JSON.stringify(parse(), null, 2);
                status.className = 'small mt-2 text-success';
                status.textContent = 'JSON ist gültig.';
            } catch (error) {
                status.className = 'small mt-2 text-danger';
                status.textContent = 'Ungültiges JSON: ' + error.message;
            }
        });
        $('#tools-json-minify')?.addEventListener('click', () => {
            try {
                input.value = JSON.stringify(parse());
                status.className = 'small mt-2 text-success';
                status.textContent = 'JSON ist gültig und minimiert.';
            } catch (error) {
                status.className = 'small mt-2 text-danger';
                status.textContent = 'Ungültiges JSON: ' + error.message;
            }
        });
    }

    function initGenerators() {
        $('#tools-uuid-generate')?.addEventListener('click', () => {
            const uuid = crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
                const random = Math.random() * 16 | 0;
                const value = char === 'x' ? random : (random & 0x3 | 0x8);
                return value.toString(16);
            });
            setText('#tools-uuid-output', uuid);
        });

        $('#tools-password-generate')?.addEventListener('click', () => {
            const length = Math.max(8, Math.min(128, parseInt($('#tools-password-length')?.value || '24', 10)));
            const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%&*+-_=.?';
            const bytes = new Uint32Array(length);
            crypto.getRandomValues(bytes);
            const password = Array.from(bytes, (value) => alphabet[value % alphabet.length]).join('');
            setText('#tools-password-output', password);
        });
    }

    function initTimestamp() {
        const timestamp = $('#tools-timestamp');
        const date = $('#tools-date');
        if (!timestamp || !date) {
            return;
        }
        $('#tools-timestamp-to-date')?.addEventListener('click', () => {
            const seconds = parseInt(timestamp.value, 10);
            if (Number.isNaN(seconds)) {
                return;
            }
            const local = new Date(seconds * 1000);
            date.value = new Date(local.getTime() - local.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
        });
        $('#tools-date-to-timestamp')?.addEventListener('click', () => {
            const local = new Date(date.value);
            if (!Number.isNaN(local.getTime())) {
                timestamp.value = String(Math.floor(local.getTime() / 1000));
            }
        });
        $('#tools-timestamp-now')?.addEventListener('click', () => {
            timestamp.value = String(Math.floor(Date.now() / 1000));
            $('#tools-timestamp-to-date')?.dispatchEvent(new Event('click'));
        });
    }

    async function digestHex(algorithm, value) {
        const buffer = await crypto.subtle.digest(algorithm, new TextEncoder().encode(value));
        return Array.from(new Uint8Array(buffer), (byte) => byte.toString(16).padStart(2, '0')).join('');
    }

    function initHash() {
        const input = $('#tools-hash-input');
        if (!input || !crypto.subtle) {
            return;
        }
        $('#tools-hash-generate')?.addEventListener('click', async () => {
            const sha256 = await digestHex('SHA-256', input.value);
            const sha512 = await digestHex('SHA-512', input.value);
            setText('#tools-hash-output', 'SHA-256:\n' + sha256 + '\n\nSHA-512:\n' + sha512);
        });
    }

    function initQr() {
        const input = $('#tools-qr-input');
        const canvas = $('#tools-qr-canvas');
        const status = $('#tools-qr-status');
        if (!input || !canvas) {
            return;
        }
        $('#tools-qr-generate')?.addEventListener('click', () => {
            const text = input.value.trim();
            if (text === '') {
                if (status) status.textContent = 'Bitte Text oder URL eingeben.';
                return;
            }
            try {
                if (window.QRCode && typeof window.QRCode.toCanvas === 'function') {
                    window.QRCode.toCanvas(canvas, text, { width: 220, margin: 1 }, (error) => {
                        if (status) status.textContent = error ? 'QR-Code konnte nicht erzeugt werden.' : 'QR-Code erzeugt.';
                    });
                    return;
                }
                if (typeof window.qrcode !== 'function') {
                    if (status) status.textContent = 'QR-Bibliothek konnte nicht geladen werden.';
                    return;
                }

                const qr = window.qrcode(0, 'M');
                qr.addData(text);
                qr.make();

                const context = canvas.getContext('2d');
                if (!context) {
                    if (status) status.textContent = 'QR-Code konnte nicht gezeichnet werden.';
                    return;
                }

                const modules = qr.getModuleCount();
                const margin = 8;
                const size = Math.min(canvas.width, canvas.height);
                const cell = Math.floor((size - margin * 2) / modules);
                const qrSize = cell * modules;
                const offset = Math.floor((size - qrSize) / 2);

                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.fillStyle = '#000000';
                for (let row = 0; row < modules; row += 1) {
                    for (let col = 0; col < modules; col += 1) {
                        if (qr.isDark(row, col)) {
                            context.fillRect(offset + col * cell, offset + row * cell, cell, cell);
                        }
                    }
                }
                if (status) status.textContent = 'QR-Code erzeugt.';
            } catch (error) {
                if (status) status.textContent = 'QR-Code konnte nicht erzeugt werden.';
            }
        });
    }

    function markdownToHtml(value) {
        let text = escapeHtml(value);
        text = text.replace(/^### (.*)$/gm, '<h3>$1</h3>');
        text = text.replace(/^## (.*)$/gm, '<h2>$1</h2>');
        text = text.replace(/^# (.*)$/gm, '<h1>$1</h1>');
        text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        text = text.replace(/\*(.*?)\*/g, '<em>$1</em>');
        text = text.replace(/`([^`]+)`/g, '<code>$1</code>');
        text = text.replace(/\[([^\]]+)]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" rel="noopener noreferrer" target="_blank">$1</a>');
        return text.split(/\n{2,}/).map((block) => {
            if (/^<h[1-3]>/.test(block)) {
                return block;
            }
            return '<p>' + block.replace(/\n/g, '<br>') + '</p>';
        }).join('');
    }

    function initMarkdown() {
        const input = $('#tools-markdown-input');
        const preview = $('#tools-markdown-preview');
        if (!input || !preview) {
            return;
        }
        const update = () => {
            preview.innerHTML = markdownToHtml(input.value);
        };
        input.addEventListener('input', update);
        update();
    }

    function initRegex() {
        const pattern = $('#tools-regex-pattern');
        const flags = $('#tools-regex-flags');
        const text = $('#tools-regex-text');
        const output = $('#tools-regex-output');
        if (!pattern || !flags || !text || !output) {
            return;
        }
        const update = () => {
            try {
                const regex = new RegExp(pattern.value, flags.value);
                const matches = Array.from(text.value.matchAll(regex));
                output.textContent = matches.length === 0
                    ? 'Keine Treffer.'
                    : matches.map((match, index) => '#' + (index + 1) + ' [' + match.index + ']: ' + match[0]).join('\n');
            } catch (error) {
                output.textContent = 'Regex-Fehler: ' + error.message;
            }
        };
        [pattern, flags, text].forEach((node) => node.addEventListener('input', update));
        update();
    }

    function renderAdminResult(data) {
        const target = $('#tools-admin-result');
        if (!target) {
            return;
        }
        const ok = Boolean(data.ok);
        const output = data.output || JSON.stringify(data.job || data.records || {}, null, 2);
        target.innerHTML = [
            '<div class="' + (ok ? 'text-success' : 'text-danger') + ' fw-semibold mb-1">' + escapeHtml(data.title || (ok ? 'OK' : 'Fehler')) + '</div>',
            '<div class="mb-2">' + escapeHtml(data.summary || '') + '</div>',
            output ? '<pre class="tools-output mb-0">' + escapeHtml(output) + '</pre>' : ''
        ].join('');
        const resultCard = $('#tools-admin-result-card');
        if (resultCard) {
            resultCard.classList.add('tools-admin-result-card-active');
            const rect = resultCard.getBoundingClientRect();
            const visible = rect.top >= 0 && rect.bottom <= window.innerHeight;
            if (!visible) {
                resultCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }

    function statusClass(status) {
        if (status === 'done') return 'text-bg-success';
        if (status === 'running') return 'text-bg-primary';
        if (status === 'queued') return 'text-bg-warning';
        if (status === 'error') return 'text-bg-danger';
        return 'text-bg-secondary';
    }

    function renderSpeechJobs(jobs) {
        const target = $('#tools-speech-jobs');
        if (!target) {
            return;
        }
        if (!Array.isArray(jobs) || jobs.length === 0) {
            target.innerHTML = '<div class="alert alert-secondary mb-0">Noch keine Speech-to-Text-Jobs vorhanden.</div>';
            return;
        }
        const deleteUrl = target.dataset.deleteUrl || '/admin/tools/speech/delete';
        const csrfToken = target.dataset.csrfToken || '';
        const openRawDetails = new Set($$('.tools-speech-job details[open]', target)
            .map((details) => details.closest('.tools-speech-job')?.dataset.jobId || '')
            .filter(Boolean));
        target.innerHTML = '<div class="vstack gap-2">' + jobs.map((job) => {
            const status = String(job.status || '');
            const jobId = String(job.id || '');
            const results = job.results || {};
            const links = ['txt', 'srt', 'vtt'].map((format) => {
                const result = results[format] || {};
                if (!result.available || !result.url) {
                    return '';
                }
                return '<a class="btn btn-outline-secondary btn-sm" href="' + escapeHtml(result.url) + '">' + format.toUpperCase() + ' herunterladen</a>';
            }).join('');
            const modelName = job.model_name || String(job.model_path || '').split('/').pop();
            const prettyTranscript = String(job.transcript_pretty || '');
            const rawTranscript = String(job.transcript || '');
            const visibleTranscript = prettyTranscript || rawTranscript;
            const transcript = visibleTranscript
                ? '<div class="tools-transcript-flow mt-2 mb-2">' + escapeHtmlWithBreaks(visibleTranscript) + '</div>'
                    + (rawTranscript && rawTranscript !== prettyTranscript
                        ? '<details class="small mb-2"' + (openRawDetails.has(jobId) ? ' open' : '') + '><summary>Rohtext anzeigen</summary><pre class="tools-output mt-2 mb-0">' + escapeHtml(rawTranscript) + '</pre></details>'
                        : '')
                : '';
            const error = job.error
                ? '<div class="text-danger small mt-2">' + escapeHtml(job.error) + '</div>'
                : '';
            const deleteForm = status !== 'running'
                ? '<form method="post" action="' + escapeHtml(deleteUrl) + '" onsubmit="return confirm(&quot;Speech-to-Text-Job wirklich löschen?&quot;);">'
                    + '<input type="hidden" name="csrf_token" value="' + escapeHtml(csrfToken) + '">'
                    + '<input type="hidden" name="job_id" value="' + escapeHtml(job.id || '') + '">'
                    + '<button class="btn btn-outline-danger btn-sm" type="submit">Löschen</button>'
                    + '</form>'
                : '';

            return [
                '<article class="tools-speech-job border rounded-3 p-3" data-job-id="' + escapeHtml(jobId) + '">',
                '<div class="d-flex flex-wrap justify-content-between gap-2">',
                '<div><strong>' + escapeHtml(job.original_name || 'Audio') + '</strong>',
                '<div class="text-muted small">' + escapeHtml(job.created_at_local || job.created_at || '') + (job.timezone_name ? ' · ' + escapeHtml(job.timezone_name) : '') + ' · ' + escapeHtml(String(job.language || '').toUpperCase()) + '</div>',
                '<div class="text-muted small">Modell: ' + escapeHtml(modelName || '') + '</div>',
                job.duration_label ? '<div class="text-muted small">Laufzeit: ' + escapeHtml(job.duration_label) + '</div>' : '',
                '</div>',
                '<span class="badge ' + statusClass(status) + '">' + escapeHtml(status) + '</span>',
                '</div>',
                '<div class="text-muted small mt-2">Originaldatei: ' + (job.source_file_available ? 'vorhanden' : 'entfernt') + ' · WAV: ' + (job.wav_file_available ? 'vorhanden' : 'entfernt') + '</div>',
                error,
                transcript,
                (links || deleteForm) ? '<div class="d-flex flex-wrap gap-2 mt-2">' + links + deleteForm + '</div>' : '',
                '</article>'
            ].join('');
        }).join('') + '</div>';
    }

    async function refreshSpeechJobs() {
        const target = $('#tools-speech-jobs');
        if (!target || !target.dataset.statusUrl) {
            return;
        }
        try {
            const response = await fetch(target.dataset.statusUrl, { credentials: 'same-origin' });
            const data = await response.json();
            if (data && Array.isArray(data.jobs)) {
                renderSpeechJobs(data.jobs);
            }
        } catch (error) {
            // Keep the previous visible state; polling should not be noisy.
        }
    }

    function hasActiveSpeechJobs() {
        return $$('.tools-speech-job .badge').some((badge) => ['queued', 'running'].includes(badge.textContent.trim()));
    }

    function initAdminForms() {
        const speechModelSelect = $('#tools-speech-model-select');
        const speechModelInput = $('#tools-speech-model');
        if (speechModelSelect && speechModelInput) {
            speechModelSelect.addEventListener('change', () => {
                if (speechModelSelect.value !== '') {
                    speechModelInput.value = speechModelSelect.value;
                }
            });
        }

        $$('.js-tools-admin-form').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                renderAdminResult({ ok: true, title: 'Läuft', summary: 'Tool wird ausgeführt...' });
                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form),
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    renderAdminResult(await response.json());
                } catch (error) {
                    renderAdminResult({ ok: false, title: 'Fehler', summary: 'Request fehlgeschlagen.' });
                }
            });
        });

        $$('.js-tools-speech-form').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                renderAdminResult({ ok: true, title: 'Upload', summary: 'Upload wird gespeichert und Worker wird gestartet...' });
                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form),
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    const data = await response.json();
                    renderAdminResult(data);
                    if (data && data.job) {
                        await refreshSpeechJobs();
                    }
                } catch (error) {
                    renderAdminResult({ ok: false, title: 'Fehler', summary: 'Upload fehlgeschlagen.' });
                }
            });
        });

        $('#tools-speech-refresh')?.addEventListener('click', refreshSpeechJobs);

        const jobsTarget = $('#tools-speech-jobs');
        if (jobsTarget) {
            setInterval(() => {
                if (document.hidden) {
                    return;
                }
                refreshSpeechJobs();
            }, hasActiveSpeechJobs() ? 2500 : 5000);
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        initSearch();
        initTextCounter();
        initTextCleaner();
        initCodecs();
        initJson();
        initGenerators();
        initTimestamp();
        initHash();
        initQr();
        initMarkdown();
        initRegex();
        initAdminForms();
    });
})();
