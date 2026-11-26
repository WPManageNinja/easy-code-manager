// Run on a local installation after building: npm run test:heredoc
// Supply draft responses to the real edit screen without writing any snippet files.
const assert = require('node:assert/strict');
const {test} = require('node:test');
const {openAdminSession} = require('./lib/session');

const sample = 'const sample = "blue"; // note';
const prefix = 'JSON.stringify("blue"); // prefix';
const php = [
    '$a = <<<JS', sample, 'JS;',
    '$b = <<<"JS"', '  ' + sample, '  JS;',
    "$c = <<<'JS'", sample, 'const greeting = `Hi ${name}`;', 'JS;',
    '$d = <<<TEXT', sample, 'TEXT;',
    '$e = <<<JS', 'const value = "$name";', 'JS;',
    '$after = 42;',
    '$json = <<<JS', prefix, sample, 'JS;', '$tail = 42;'
].join('\n');

async function color(line, token) {
    return line.evaluate((element, token) => {
        const offset = element.textContent.indexOf(token);
        if (offset < 0) throw new Error('Missing token ' + token);
        const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
        let start = 0;
        while (walker.nextNode()) {
            const node = walker.currentNode;
            if (start + node.length > offset) return getComputedStyle(node.parentElement).color;
            start += node.length;
        }
        throw new Error('Missing rendered text');
    }, token);
}

test('edit screen language highlighting', async t => {
    const {browser, context, auth} = await openAdminSession();
    try {
        const page = await context.newPage();
        let draft;
        await page.route(url => decodeURIComponent(url.href).includes('/snippets/find'), route =>
            route.fulfill({json: {snippet: draft}})
        );
        async function open(type, code) {
            draft = {code, meta: {name: 'Highlighting example', type, status: 'draft', condition: {}}};
            const file = 'highlight-' + type + '.php';
            await page.goto(auth.adminUrl + 'admin.php?page=fluent-snippets#/snippets/' + file, {waitUntil: 'commit', timeout: 90000});
            await page.waitForFunction(expected =>
                Array.from(document.querySelectorAll('.cm-content .cm-line'), line => line.textContent).join('\n') === expected,
                code
            );
            return page.locator('.cm-content .cm-line');
        }
        const tokens = ['const', '"blue"', '// note'];
        const colors = line => Promise.all(tokens.map(token => color(line, token)));
        const jsLines = await open('js', sample + '\n' + prefix);
        const reference = await colors(jsLines.first());
        const prefixColors = line => Promise.all(['JSON', 'stringify', '"blue"', '// prefix'].map(token => color(line, token)));
        const prefixReference = await prefixColors(jsLines.last());
        assert.equal(new Set(reference).size, 3, 'reference tokens must be distinguishable');

        const bodies = [];
        const controls = [];
        const prefixes = [];
        for (const type of ['PHP', 'php_content']) {
            const mixed = type === 'php_content';
            const offset = mixed ? 1 : 0;
            // Authors can pause at EOF after typing a prefix of the closing label.
            // Earlier complete blocks must still be highlighted in that state.
            const document = mixed ? '<?php\n' + php + '\n?>\n<script>' + sample + '\n</script>' : php + '\n$unfinished = <<<JS\nJSON';
            const lines = await open(type, document);
            const ordinary = await color(lines.nth(offset + 11), 'const');
            controls.push({
                ordinary,
                interpolated: await color(lines.nth(offset + 14), 'const'),
                followingNumber: await color(lines.nth(offset + 16), '42'),
                script: mixed ? await colors(lines.filter({hasText: /^<script>/})) : null
            });
            for (const index of [1, 4, 7]) bodies.push(await colors(lines.nth(offset + index)));
            prefixes.push([await prefixColors(lines.nth(offset + 18)), await colors(lines.nth(offset + 19))]);
        }
        await t.test('ordinary PHP and inline scripts keep their colors', () => {
            for (const {ordinary, interpolated, followingNumber, script} of controls) {
                assert.notEqual(ordinary, reference[0], 'generic strings keep PHP styling');
                assert.equal(interpolated, ordinary, 'interpolation keeps PHP styling');
                assert.notEqual(followingNumber, ordinary, 'PHP resumes after the label');
                if (script) assert.deepEqual(script, reference);
            }
        });
        await t.test('JS-labeled bodies use JavaScript token colors', () => {
            for (const actual of bodies) {
                assert.deepEqual(actual, reference, 'JS-labeled body must match JavaScript keyword, string and comment colors');
            }
        });
        await t.test('a longer identifier does not close the heredoc', () => {
            for (const [identifier, next] of prefixes) {
                assert.deepEqual(identifier, prefixReference, 'JSON.stringify must retain JavaScript colors');
                assert.deepEqual(next, reference, 'the line after JSON.stringify is still JavaScript');
            }
        });
    } finally {
        await browser.close();
    }
});
