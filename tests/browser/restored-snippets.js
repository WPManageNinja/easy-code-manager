// Run on a local test installation: node --test tests/browser/restored-snippets.js
// A static stylesheet is restored directly to disk, as it would be from backup.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {randomUUID} = require('node:crypto');
const {execFileSync} = require('node:child_process');
const {openAdminSession, WP_ROOT} = require('./lib/session');

function syncIndex() {
    execFileSync('wp', ['eval', 'FluentSnippets\\App\\Helpers\\Helper::syncSnippetIndex();'], {cwd: WP_ROOT});
}

test('restored CSS applies and opens on its original first line', async () => {
    const {browser, context, auth} = await openAdminSession();
    const files = [];
    try {
        const editor = await context.newPage();
        const visitor = await browser.newPage({ignoreHTTPSErrors: true});
        for (const eol of ['\n', '\r\n']) {
            const id = randomUUID();
            const name = 'restore-' + id + '.php';
            const property = '--restore-' + id;
            const body = ':root { ' + property + ': present; }';
            const header = [
                '<?php', '// <Internal Doc Start>', '/*', '* @name: Restore regression',
                '* @status: published', '* @type: css', '* @run_at: wp_head', '*/', '?>',
                '<?php if (!defined("ABSPATH")) { return;} // <Internal Doc End> ?>', ''
            ].join(eol);
            const filename = path.join(auth.storageDir, name);
            fs.writeFileSync(filename, header + body, {flag: 'wx'});
            files.push(filename);
            syncIndex();
            await editor.goto(auth.adminUrl + 'admin.php?page=fluent-snippets#/snippets/' + name);
            const lines = editor.locator('.cm-content .cm-line');
            await lines.filter({hasText: property}).first().waitFor();
            const home = await editor.locator('#wp-admin-bar-site-name > a').first().getAttribute('href');
            await visitor.goto(home);
            const value = await visitor.evaluate(key => getComputedStyle(document.documentElement).getPropertyValue(key).trim(), property);
            assert.equal(value, 'present', 'restored stylesheet must apply');
            assert.equal(await lines.first().textContent(), body, 'header separator must stay outside the editor');
        }
    } finally {
        try {
            for (const file of files) fs.unlinkSync(file);
            if (files.length) syncIndex();
        } finally {
            await browser.close();
        }
    }
});
