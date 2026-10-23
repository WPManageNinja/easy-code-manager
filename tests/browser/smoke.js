/**
 * Browser smoke test for the admin app: every screen mounts, styled, without errors.
 *
 * A blank admin screen still answers 200, and nothing else in tests/ loads the bundle in
 * a browser. This opens each route in Chromium, logged in as the site's first
 * administrator, and checks what a person would see:
 *
 *   - the app mounts and app.css is applied (the app bar is laid out, not raw markup)
 *   - each screen renders the component it routes to
 *   - the code editor takes typing and highlights it (two copies of
 *     @codemirror/state in the bundle break exactly this)
 *   - an Element Plus select opens its dropdown
 *   - no console error, uncaught exception or failed request comes from this plugin
 *
 * It changes nothing on the site. Any request that would write - a REST POST other than
 * the index resync the app sends on boot, or a snippet save over admin-ajax - is aborted
 * and fails the run. The snippet files and the saved settings are compared before and
 * after, and a difference fails the run as well.
 *
 * Needs a WordPress site with the plugin active and a fresh `npm run build`:
 *
 *     FSNIP_WP_ROOT=/path/to/wordpress npm run test:browser
 *     FSNIP_WP_ROOT=/path/to/wordpress npm run test:browser -- --headed
 *
 * Exit code 0 = pass, 1 = failure.
 *
 * Not shipped: build.sh only copies app/, dist/, language/ and the root files.
 */

const session = require('./lib/session');

const PAGE_TIMEOUT = 20000;

// The settings a person can change. The rest of the index meta is cache, rewritten
// whenever the index resyncs.
const USER_SETTINGS = ['secret_key', 'force_disabled', 'auto_disable', 'auto_publish', 'remove_on_uninstall', 'enable_line_wrap', 'rest_authoring'];

let checks = 0;
let failures = 0;

function check(label, passed, detail = '') {
    checks++;
    if (passed) {
        console.log(`  ok    ${label}`);
        return;
    }
    failures++;
    console.log(`  FAIL  ${label}${detail ? ' — ' + detail : ''}`);
}

function skip(label, reason) {
    console.log(`  skip  ${label} — ${reason}`);
}

function pick(object, keys) {
    return Object.fromEntries(keys.map(key => [key, object ? object[key] : undefined]));
}

// Names only: secret_key is the Safe Mode secret and does not belong in a test log.
function changedKeys(before, after) {
    return Object.keys(before).filter(key => JSON.stringify(before[key]) !== JSON.stringify(after[key]));
}

/**
 * Collects everything that went wrong on the page, from the moment it opened.
 */
function watch(page) {
    const problems = [];
    const blockedWrites = [];

    page.on('console', message => {
        if (message.type() !== 'error') {
            return;
        }
        const url = message.location().url;
        if (session.isOurConsoleError(message.text(), url)) {
            problems.push(`console: ${message.text().slice(0, 200)}${url ? ` (${url})` : ''}`);
        }
    });

    page.on('pageerror', error => problems.push(`uncaught: ${error.message.slice(0, 200)}`));

    page.on('requestfailed', request => {
        const url = request.url();
        if (url.includes(`/plugins/${session.PLUGIN_SLUG}/`) || url.includes('/fluent-snippets/')) {
            if (!blockedWrites.includes(url)) {
                problems.push(`request failed: ${url} (${request.failure() ? request.failure().errorText : '?'})`);
            }
        }
    });

    page.on('response', response => {
        const url = response.url();
        if ((url.includes(`/plugins/${session.PLUGIN_SLUG}/`) || url.includes('/fluent-snippets/')) && response.status() >= 400) {
            problems.push(`HTTP ${response.status()}: ${url}`);
        }
    });

    return {problems, blockedWrites};
}

/**
 * Abort every request that could change the site. The one write the app makes on its
 * own, snippets/sync-index, only rebuilds the index cache from the files on disk.
 */
async function guardWrites(context, blockedWrites) {
    await context.route(/fluent-snippets|admin-ajax\.php/, async route => {
        const request = route.request();
        const url = request.url();
        const method = request.method();
        const body = request.postData() || '';

        const restWrite = url.includes('/fluent-snippets/') && method !== 'GET' && !/\/snippets\/sync-index\b/.test(url);
        const ajaxWrite = url.includes('admin-ajax.php') && method !== 'GET' && /(^|&)action=fluent_snippet/.test(body);

        if (restWrite || ajaxWrite) {
            blockedWrites.push(url);
            return route.abort('blockedbyclient');
        }

        return route.continue();
    });
}

async function goToRoute(page, hash) {
    await page.evaluate(target => {
        window.location.hash = target;
    }, hash);
    await page.waitForFunction(target => window.location.hash === target, hash);
}

async function main() {
    const headed = process.argv.includes('--headed');
    const {browser, context, auth} = await session.openAdminSession({headed});

    const before = {
        files: session.fingerprintSnippets(auth.storageDir),
        settings: pick(auth.settings, USER_SETTINGS)
    };

    const page = await context.newPage();
    page.setDefaultTimeout(PAGE_TIMEOUT);
    const {problems, blockedWrites} = watch(page);
    await guardWrites(context, blockedWrites);

    const reportProblems = (label) => {
        check(`${label}: no errors from the plugin`, problems.length === 0, problems.join(' | '));
        problems.length = 0;
    };

    try {
        console.log(`\nThe app boots (${auth.adminUrl}, FluentSnippets ${auth.version})`);

        const response = await page.goto(`${auth.adminUrl}admin.php?page=fluent-snippets#/`, {waitUntil: 'domcontentloaded'});
        check('the admin page answers 200', response && response.status() === 200, response ? `HTTP ${response.status()}, ${page.url()}` : 'no response');
        check('it is not the login screen', !page.url().includes('wp-login.php'), page.url());

        const bar = page.locator('#fluent_snippets_app .fsnip_app_bar');
        const mounted = await bar.waitFor({state: 'visible'}).then(() => true, () => false);
        check('the Vue app mounts on #fluent_snippets_app', mounted);

        const barStyle = mounted ? await bar.evaluate(el => {
            const style = getComputedStyle(el);
            return {display: style.display, height: style.height};
        }) : {};
        check('app.css is applied (the app bar is a 56px flex row)', barStyle.display === 'flex' && barStyle.height === '56px', JSON.stringify(barStyle));

        // Element Plus's base.css declares its variables on :root, and this app's own styles
        // never set this one, so it is there only if Element Plus's stylesheet loaded.
        const elementPlusBase = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--el-color-white').trim());
        check('Element Plus\'s stylesheet is loaded (--el-color-white is defined)', elementPlusBase !== '', JSON.stringify(elementPlusBase));

        console.log('\nSnippets list');
        await goToRoute(page, '#/');
        const list = await page.locator('#fluent_snippets_app .fsnip_secondary_menu, #fluent_snippets_app .fsnip_empty').first()
            .waitFor({state: 'visible'}).then(() => true, () => false);
        check('the list screen renders its type filter or its empty state', list);
        reportProblems('list');

        console.log('\nCreate a snippet (nothing is saved)');
        await goToRoute(page, '#/create-new');
        const editor = page.locator('#fluent_snippets_app .cm-content');
        const editorShown = await editor.waitFor({state: 'visible'}).then(() => true, () => false);
        check('the code editor renders', editorShown);

        if (editorShown) {
            const typed = "add_action('init', function () { return 'smoke'; });";
            await editor.click();
            await page.keyboard.type(typed);
            const text = await editor.innerText();
            check('the editor takes typing', text.includes("return 'smoke';"), JSON.stringify(text.slice(0, 80)));

            const tokens = await editor.locator('span[class]').count();
            check('the editor highlights PHP', tokens > 5, `${tokens} highlighted tokens`);
        }

        const select = page.locator('#fluent_snippets_app .el-select__wrapper').first();
        if (await select.count()) {
            await select.click();
            const opened = await page.locator('.el-select-dropdown').filter({visible: true}).first()
                .waitFor({state: 'visible', timeout: 5000}).then(() => true, () => false);
            check('an Element Plus select opens its dropdown', opened);
            await page.keyboard.press('Escape');
        } else {
            check('the create screen has an Element Plus select', false, 'no .el-select__wrapper found');
        }
        reportProblems('create');

        console.log('\nEdit a snippet (nothing is saved)');
        if (auth.snippet) {
            await goToRoute(page, `#/snippets/${auth.snippet.file}`);
            const loaded = await page.locator('#fluent_snippets_app .cm-content').waitFor({state: 'visible'}).then(() => true, () => false);
            check(`${auth.snippet.file} opens in the editor`, loaded);

            const nameShown = await page.waitForFunction(name => {
                return [...document.querySelectorAll('#fluent_snippets_app input')].some(input => input.value === name);
            }, auth.snippet.name, {timeout: 5000}).then(() => true, () => false);
            check(`its name, "${auth.snippet.name}", is filled in`, nameShown);
        } else {
            skip('edit screen', 'this site has no snippets to open');
        }
        reportProblems('edit');

        console.log('\nSettings');
        await goToRoute(page, '#/settings');
        const settings = await page.locator('#fluent_snippets_app .fsnip_settings .el-switch').first()
            .waitFor({state: 'visible'}).then(() => true, () => false);
        const switches = settings ? await page.locator('#fluent_snippets_app .fsnip_settings .el-switch').count() : 0;
        check('the settings screen renders its switches', switches >= 5, `${switches} switches`);
        reportProblems('settings');

        console.log('\nAbout');
        await goToRoute(page, '#/about');
        const about = await page.locator('#fluent_snippets_app .fss_about_page .fss_masthead').waitFor({state: 'visible'}).then(() => true, () => false);
        check('the about screen renders', about);
        reportProblems('about');
    } finally {
        await browser.close();
    }

    console.log('\nThe site is unchanged');
    check('no write request was attempted', blockedWrites.length === 0, blockedWrites.join(', '));

    const after = {
        files: session.fingerprintSnippets(auth.storageDir),
        settings: pick(session.readSettings(), USER_SETTINGS)
    };
    check(`the snippet files are untouched (${before.files.count})`, JSON.stringify(after.files) === JSON.stringify(before.files));
    const changed = changedKeys(before.settings, after.settings);
    check('the settings are untouched', changed.length === 0, `changed: ${changed.join(', ')}`);

    console.log(`\n${checks} checks, ${failures} failed`);
    process.exit(failures ? 1 : 0);
}

main().catch(error => {
    console.log(`\n  FAIL  ${error.message}`);
    process.exit(1);
});
