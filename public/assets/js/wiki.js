/* Optional UI helper only. Server-side validation remains authoritative. */
const WikiSourceUrl = (() => {
    const validSegment = (value) => /^[A-Za-z0-9][A-Za-z0-9._-]*$/.test(value);
    const validPath = (value) => value.split('/').every(validSegment);
    const encodedPath = (value) => value.split('/').filter(Boolean).map(encodeURIComponent).join('/');

    const build = ({ owner, repository, ref, docsRoot }) => {
        const ownerValue = (owner || '').trim();
        const repoValue = (repository || '').trim();
        const refValue = (ref || '').trim();
        const rootValue = (docsRoot || '').trim().replace(/^\/+|\/+$/g, '');
        if (!validSegment(ownerValue) || !validSegment(repoValue)) return null;
        let value = `https://github.com/${encodeURIComponent(ownerValue)}/${encodeURIComponent(repoValue)}`;
        if (refValue && validPath(refValue)) {
            value += `/tree/${encodedPath(refValue)}`;
            if (rootValue && validPath(rootValue)) value += `/${encodedPath(rootValue)}`;
        }
        return value;
    };

    const parse = (raw, fallback = {}) => {
        let parsed;
        try { parsed = new URL((raw || '').trim()); } catch (_) { return { ok: false, error: 'Bitte eine gültige GitHub-URL eingeben.' }; }
        if (parsed.protocol !== 'https:' || parsed.hostname.toLowerCase() !== 'github.com' || parsed.username || parsed.password || parsed.port || parsed.search || parsed.hash) {
            return { ok: false, error: 'Unterstützt werden nur sichere https://github.com-Repository-URLs.' };
        }
        const parts = parsed.pathname.split('/').filter(Boolean).map(decodeURIComponent);
        if (!validSegment(parts[0] || '') || !validSegment(parts[1] || '')) {
            return { ok: false, error: 'Bitte eine Repository-URL oder eine URL zum Branch/Tag und Dokumentationsordner verwenden.' };
        }
        if (parts.length === 2) return { ok: true, owner: parts[0], repository: parts[1], ref: fallback.ref || 'main', docsRoot: fallback.docsRoot || 'docs' };
        if (parts.length < 5 || parts[2] !== 'tree') {
            return { ok: false, error: 'Bitte eine Repository-URL oder eine URL zum Branch/Tag und Dokumentationsordner verwenden.' };
        }
        const ref = parts[3];
        const docsRoot = parts.slice(4).join('/');
        if (!validSegment(ref) || !validPath(docsRoot)) {
            return { ok: false, error: 'Die URL benötigt einen einfachen Branch- oder Tag-Namen und einen gültigen Dokumentationsordner.' };
        }
        return { ok: true, owner: parts[0], repository: parts[1], ref, docsRoot };
    };

    const bind = () => {
        const form = document.querySelector('[data-wiki-source-form]');
        if (!form) return;
        const urlField = form.querySelector('[data-wiki-url]');
        const feedback = form.querySelector('[data-wiki-url-feedback]');
        const owner = form.querySelector('#wiki_owner');
        const repository = form.querySelector('#wiki_repository');
        const ref = form.querySelector('#wiki_ref');
        const docsRoot = form.querySelector('#wiki_docs_root');
        if (!urlField || !feedback || !owner || !repository || !ref || !docsRoot) return;
        const clearFeedback = () => { urlField.classList.remove('is-invalid'); feedback.textContent = ''; };
        const showFeedback = (message) => { urlField.classList.add('is-invalid'); feedback.textContent = message; };
        const updateUrl = () => { const next = build({ owner: owner.value, repository: repository.value, ref: ref.value, docsRoot: docsRoot.value }); if (next !== null) { urlField.value = next; clearFeedback(); } };
        const applyUrl = () => {
            if (urlField.value.trim() === '') { clearFeedback(); return; }
            const next = parse(urlField.value, { ref: ref.value.trim(), docsRoot: docsRoot.value.trim() });
            if (!next.ok) { showFeedback(next.error); return; }
            owner.value = next.owner; repository.value = next.repository; ref.value = next.ref; docsRoot.value = next.docsRoot;
            updateUrl();
        };
        urlField.addEventListener('change', applyUrl);
        urlField.addEventListener('paste', () => window.setTimeout(applyUrl, 0));
        [owner, repository, ref, docsRoot].forEach((field) => field.addEventListener('input', updateUrl));
        updateUrl();
    };
    return { build, parse, bind };
})();

const WikiNavigation = (() => {
    const storagePrefix = 'modulnest.wiki.nav.';

    const bind = () => {
        document.querySelectorAll('[data-wiki-nav-group]').forEach((group) => {
            const toggle = group.querySelector('[data-wiki-nav-toggle]');
            const content = toggle ? document.getElementById(toggle.getAttribute('aria-controls') || '') : null;
            const key = group.dataset.wikiNavGroup || '';
            if (!toggle || !content || !key) return;

            const containsActivePage = group.classList.contains('is-active-group');
            let expanded = containsActivePage;
            if (!containsActivePage) {
                try {
                    const saved = window.sessionStorage.getItem(storagePrefix + key);
                    if (saved !== null) expanded = saved === 'open';
                } catch (_) { /* Private mode or blocked storage: retain the safe default. */ }
            }

            const apply = () => {
                content.hidden = !expanded;
                toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                group.classList.toggle('is-collapsed', !expanded);
            };
            toggle.addEventListener('click', () => {
                expanded = !expanded;
                try { window.sessionStorage.setItem(storagePrefix + key, expanded ? 'open' : 'closed'); } catch (_) { /* no persistent preference */ }
                apply();
            });
            apply();
        });
    };
    return { bind };
})();

if (typeof module !== 'undefined' && module.exports) module.exports = WikiSourceUrl;
if (typeof document !== 'undefined') {
    WikiSourceUrl.bind();
    WikiNavigation.bind();
}
