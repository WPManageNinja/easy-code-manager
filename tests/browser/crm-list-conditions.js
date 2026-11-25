/**
 * Run against a local WP page with two saved footer snippets. Give both snippets
 * unique, visible text: one unconditional and one with a matching Contact Lists
 * rule. Supply a Playwright storage state for a signed-in contact on that list.
 *
 * node tests/browser/crm-list-conditions.js URL CONTACT_STATE CONTROL_TEXT LIST_TEXT
 */
const assert = require('node:assert/strict');
const {test} = require('node:test');
const {chromium} = require('playwright');

test('a saved Contact Lists rule renders for a matching contact', async () => {
    const [url, contactState, controlText, listText] = process.argv.slice(2);
    assert.ok(url && contactState && controlText && listText && controlText !== listText,
        'Pass a local fixture URL, a contact storage state, and two distinct sample texts');
    const target = new URL(url);
    assert.ok(['http:', 'https:'].includes(target.protocol) &&
        (target.hostname.endsWith('.test') || ['localhost', '127.0.0.1'].includes(target.hostname)),
        'Use a local WordPress test site');

    const browser = await chromium.launch();
    try {
        for (const storageState of [undefined, contactState]) {
            const context = await browser.newContext({storageState, ignoreHTTPSErrors: true});
            const page = await context.newPage();
            const response = await page.goto(target.href);
            assert.equal(response.status(), 200);
            assert.equal(await page.getByText(controlText, {exact: true}).count(), 1, 'control snippet missing');
            assert.equal(await page.getByText(listText, {exact: true}).count(), storageState ? 1 : 0,
                storageState ? 'matching contact did not see the list snippet' : 'guest saw the list snippet');
            await context.close();
        }
    } finally {
        await browser.close();
    }
});
