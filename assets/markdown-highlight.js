import hljs from 'highlight.js/lib/common';

const aliases = {
    js: 'javascript',
    shell: 'bash',
    sh: 'bash',
    html: 'xml',
    yml: 'yaml',
    md: 'markdown',
};

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.mn-code-block code[class*="language-"]').forEach((code) => {
        const matched = [...code.classList].find((name) => name.startsWith('language-'));
        const requested = matched ? matched.slice('language-'.length).toLowerCase() : '';
        const language = aliases[requested] || requested;
        if (language === '' || !hljs.getLanguage(language)) return;
        code.classList.remove(matched);
        code.classList.add(`language-${language}`);
        hljs.highlightElement(code);
    });
});
