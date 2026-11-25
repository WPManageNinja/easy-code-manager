/**
 * On a local test site with two distinct tags, pass their exact names:
 * node tests/browser/taxonomy-term-options.js "Tag A" "Tag B"
 * Uses an unsaved new snippet, so file modifications can remain disabled.
 */
const assert = require('node:assert/strict');
const {test} = require('node:test');
const {openAdminSession} = require('./lib/session');

async function checkPicker() {
    const names = process.argv.slice(2);
    assert.ok(names.length === 2 && names[0] !== names[1], 'Pass the names of two distinct existing tags');
    const {browser, context, auth} = await openAdminSession();
    try {
        const page = await context.newPage();
        page.setDefaultTimeout(10000);
        await page.goto(auth.adminUrl + 'admin.php?page=fluent-snippets#/create-new');
        await page.getByRole('switch', {name: 'Enable Conditional Logic'}).press('Space');
        await page.getByRole('button', {name: 'Add', exact: true}).click();
        await page.getByRole('menuitem', {name: 'Page', exact: true}).click();
        const loaded = page.waitForResponse(response =>
            response.url().includes('settings/options') && response.url().includes('tax_term_groups')
        );
        await page.getByRole('menuitem', {name: 'Taxonomy Term Page', exact: true}).click();
        assert.equal((await loaded).status(), 200);
        const selector = page.locator('.fc_filter_value .el-select__wrapper');
        await selector.click();
        const tags = page.locator('.el-select-group__wrap').filter({
            has: page.getByText('post_tag', {exact: true}),
        });
        await tags.waitFor({state: 'visible'});
        for (const name of names) {
            assert.ok(await tags.getByRole('option', {name, exact: true}).isVisible(),
                'missing fixture tag from picker: ' + name);
        }
        await tags.getByRole('option', {name: names[0], exact: true}).click();
        await page.keyboard.press('Escape');
        await selector.click();
        await tags.getByRole('option', {name: names[1], exact: true}).click();
        const selection = page.locator('.fc_filter_value .el-tag');
        assert.deepEqual(await selection.allTextContents(), names);
    } finally {
        await browser.close();
    }
}

test('taxonomy term picker', checkPicker);
