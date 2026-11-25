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

function header(type, location, eol) {
    return [
        '<?php', '// <Internal Doc Start>', '/*', '* @name: Restore regression',
        '* @status: published', '* @type: ' + type, '* @run_at: ' + location, '*/', '?>',
        '<?php if (!defined("ABSPATH")) { return;} // <Internal Doc End> ?>', ''
    ].join(eol);
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
            const filename = path.join(auth.storageDir, name);
            fs.writeFileSync(filename, header('css', 'wp_head', eol) + body, {flag: 'wx'});
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

test('one-byte content survives indexing, shortcode rendering and export', async () => {
    const {browser, context, auth} = await openAdminSession();
    const files = [];
    try {
        const page = await context.newPage();
        await page.goto(auth.adminUrl + 'admin.php?page=fluent-snippets');
        for (const eol of ['\n', '\r\n']) {
            const id = 'restore-' + randomUUID();
            const file = path.join(auth.storageDir, id + '.php');
            fs.writeFileSync(file, header('php_content', 'shortcode', eol) + '0', {flag: 'wx'});
            files.push(file);
            syncIndex();
            const output = execFileSync('wp', ['eval',
                '$id = getenv("FSNIP_TEST_ID"); $config = FluentSnippets\\App\\Helpers\\Helper::getIndexedConfig(); ' +
                'echo json_encode([isset($config["published"][$id . ".php"]), ' +
                'do_shortcode("[fluent_snippet id=\\"" . $id . "\\"]")]);'],
                {cwd: WP_ROOT, encoding: 'utf8', env: {...process.env, FSNIP_TEST_ID: id}});
            const result = JSON.parse(output.trim().split('\n').pop());
            const exported = await page.evaluate(async id => {
                const url = new URL(window.ajaxurl, location.href);
                url.searchParams.set('action', 'fluent_snippets_export_snippets');
                url.searchParams.set('_nonce', window.fluentSnippetAdmin.nonce);
                url.searchParams.append('snippets[]', id);
                const response = await fetch(url);
                const data = await response.json();
                return data.snippets.map(snippet => atob(snippet.code));
            }, id);
            assert.deepEqual([result, exported], [[true, '0'], ['0']], 'zero body differs');
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
