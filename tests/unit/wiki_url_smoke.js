const assert = require('assert');
const sourceUrl = require('../../public/assets/js/wiki.js');

let result = sourceUrl.parse('https://github.com/ChobitsChii/ModulNest', { ref: 'main', docsRoot: 'docs' });
assert.deepStrictEqual(result, { ok: true, owner: 'ChobitsChii', repository: 'ModulNest', ref: 'main', docsRoot: 'docs' });
result = sourceUrl.parse('https://github.com/ChobitsChii/ModulNest/tree/main/docs/development');
assert.deepStrictEqual(result, { ok: true, owner: 'ChobitsChii', repository: 'ModulNest', ref: 'main', docsRoot: 'docs/development' });
assert.strictEqual(sourceUrl.parse('https://example.invalid/owner/repo').ok, false);
assert.strictEqual(sourceUrl.parse('https://github.com/owner/repo/tree/main/../../private').ok, false);
assert.strictEqual(sourceUrl.build({ owner: 'ChobitsChii', repository: 'ModulNest', ref: 'main', docsRoot: 'docs' }), 'https://github.com/ChobitsChii/ModulNest/tree/main/docs');
console.log('Wiki URL smoke passed.');
