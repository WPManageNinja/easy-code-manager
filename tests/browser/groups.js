/**
 * Group organization through the real admin app, on a writable LOCAL fixture only.
 * Build first; activate the plugin and provide the wpmn_administrator test user with
 * install_plugins and unfiltered_html. WP-CLI and installed Chromium are required.
 * FSNIP_WP_ROOT=/path/to/disposable/wordpress node --test tests/browser/groups.js
 * Creates/reuses A, seeds two synthetic retry drafts, and retains test data for inspection.
 * Existing matching legacy data is reused without saving its body. Never use a live site.
 */
const {test} = require('node:test');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const path = require('node:path');
const {chromium} = require('playwright');

test('empty groups, keyboard moves, drag/drop and rename persist', async () => {
    const root = process.env.FSNIP_WP_ROOT;
    assert.ok(root, 'Set FSNIP_WP_ROOT to a writable local fixture');
    const wp = code => execFileSync('wp', ['--path=' + root, '--user=wpmn_administrator', 'eval', code], {encoding: 'utf8'}).trim();
    const auth = JSON.parse(wp(`
        $end=time()+600; $id=get_current_user_id(); $token=WP_Session_Tokens::get_instance($id)->create($end);
        echo wp_json_encode(['url'=>home_url(), 'edit'=>current_user_can('install_plugins'), 'author'=>current_user_can('unfiltered_html'), 'cookies'=>[
            ['name'=>AUTH_COOKIE,'value'=>wp_generate_auth_cookie($id,$end,'auth',$token)],
            ['name'=>SECURE_AUTH_COOKIE,'value'=>wp_generate_auth_cookie($id,$end,'secure_auth',$token)],
            ['name'=>LOGGED_IN_COOKIE,'value'=>wp_generate_auth_cookie($id,$end,'logged_in',$token)]
        ]]);
    `));
    const url = new URL(auth.url);
    assert.ok(url.hostname.endsWith('.test') || ['localhost', '127.0.0.1'].includes(url.hostname), 'Local site only');
    assert.equal(auth.edit, true, 'Writable fixture required; do not weaken site policy');
    assert.equal(auth.author, true, 'Fixture authoring capability required');
    const fixture = JSON.parse(execFileSync('wp', ['--path=' + root, '--user=wpmn_administrator', 'eval-file', path.join(__dirname,'fixtures/group-organization.php')], {encoding:'utf8'}).trim());
    const file = fixture.file;
    const mapping = process.env.FSNIP_GROUPS_MAP;
    const browser = await chromium.launch({args: mapping ? ['--no-proxy-server', '--host-resolver-rules=MAP ' + url.hostname + ' ' + mapping] : []});
    let page;
    try {
        const context = await browser.newContext({ignoreHTTPSErrors: true});
        await context.route('**/*', route => new URL(route.request().url()).origin === url.origin ? route.continue() : route.abort());
        await context.addCookies(auth.cookies.map(c => ({...c, url: url.origin})));
        page = await context.newPage();
        const errors = [];
        page.on('pageerror', err => errors.push(err.message));
        await page.goto(url.origin + '/wp-admin/admin.php?page=fluent-snippets#/');
        await page.getByRole('button', {name: 'Create group', exact: true}).waitFor({timeout: 5000});
        await page.waitForLoadState('networkidle');
        const retry = JSON.parse(execFileSync('wp', ['--path=' + root, '--user=wpmn_administrator', 'eval-file', path.join(__dirname,'fixtures/group-retry.php')], {encoding:'utf8'}).trim());
        const noNonce = await page.evaluate(async () => (await fetch('/wp-json/fluent-snippets/groups/create', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name:'WPMN must not create without nonce'})})).status);
        assert.ok([401,403].includes(noNonce),'cookie-authenticated group writes require a REST nonce');
        const invalidNonce = await page.evaluate(async () => (await fetch('/wp-json/fluent-snippets/groups/create', {method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':'invalid'},body:JSON.stringify({name:'WPMN must not create with invalid nonce'})})).status);
        assert.equal(invalidNonce,403,'invalid REST nonce is refused');
        const unique = 'WPMN Group ' + Date.now();
        async function createGroup(name) {
            await page.getByRole('button', {name: 'Create group', exact: true}).click();
            const dialog = page.getByRole('dialog');
            await dialog.getByRole('textbox', {name: 'Group name', exact: true}).fill(name);
            await dialog.getByRole('button', {name: 'Create group', exact: true}).click();
            await dialog.waitFor({state: 'hidden'});
        }
        await createGroup(unique);
        await page.reload();
        let group = page.locator('.fsnip_group').filter({has: page.getByRole('button', {name: unique, exact: true})});
        await group.waitFor();
        assert.equal(await group.locator('.group_file').count(), 0);
        const row = page.locator('.group_file').filter({has: page.locator('a.group_file_name[href$="/' + file + '"]')});
        const read = () => JSON.parse(wp('echo wp_json_encode((new \\FluentSnippets\\App\\Model\\Snippet())->findByFileName(' + JSON.stringify(file) + '));'));
        const before = read();
        await row.locator('a.group_file_name').focus();
        await row.getByRole('button', {name: 'Move to group', exact: true}).focus();
        await page.keyboard.press('Enter');
        let dialog = page.getByRole('dialog');
        await dialog.getByRole('combobox').click();
        await page.getByRole('option', {name: unique, exact: true}).click();
        await dialog.getByRole('button', {name: 'Move snippet', exact: true}).click();
        await dialog.waitFor({state: 'hidden'});
        await group.locator('a.group_file_name[href$="/' + file + '"]').waitFor();
        let after = read();
        assert.equal(after.meta.group, unique);
        assert.equal(after.code, before.code);
        for (const key of ['type','status','priority','run_at','condition','created_at','created_by']) assert.deepEqual(after.meta[key], before.meta[key], key);
        await group.getByRole('button', {name: 'Rename group', exact: true}).click();
        dialog = page.getByRole('dialog');
        await dialog.getByRole('textbox', {name: 'Group name', exact: true}).fill(unique + ' renamed');
        await dialog.getByRole('button', {name: 'Rename group', exact: true}).click();
        await dialog.waitFor({state: 'hidden'});
        await page.reload();
        assert.equal(read().meta.group, unique + ' renamed');
        const second = unique + ' empty';
        await createGroup(second);
        const source = page.locator('.group_file').filter({has: page.locator('a.group_file_name[href$="/' + file + '"]')});
        const target = page.locator('.fsnip_group').filter({has: page.getByRole('button', {name: second, exact: true})});
        await source.dragTo(target.locator('.group_name'));
        await target.locator('a.group_file_name[href$="/' + file + '"]').waitFor();
        assert.equal(read().meta.group, second);
        assert.equal(read().code, before.code);
        // A known duplicate is a local validation error, without a rejected request.
        const renameRequests = [];
        const trackRename = request => {
            if (request.method() === 'POST' && request.url().includes('/groups/rename')) renameRequests.push(request);
        };
        page.on('request', trackRename);
        const renamedGroup = page.locator('.fsnip_group').filter({has: page.getByRole('button', {name: unique + ' renamed', exact: true})});
        await renamedGroup.getByRole('button', {name: 'Rename group', exact: true}).click();
        dialog = page.getByRole('dialog');
        await dialog.getByRole('textbox', {name: 'Group name', exact: true}).fill(' ' + second + ' ');
        await dialog.getByRole('button', {name: 'Rename group', exact: true}).click();
        await dialog.getByRole('alert').filter({hasText: 'A group with this name already exists.'}).waitFor();
        assert.equal(await dialog.getByRole('textbox', {name: 'Group name', exact: true}).inputValue(), ' ' + second + ' ');
        assert.equal(renameRequests.length, 0, 'known duplicate rename is refused before POST');
        assert.equal(read().meta.group, second);
        await dialog.getByRole('button', {name: 'Cancel', exact: true}).click();
        page.off('request', trackRename);
        const protectedResponse = await page.evaluate(async () => { const response = await fetch('/wp-content/fluent-snippet-storage/groups/index.php'); return {status:response.status,body:await response.text()}; });
        assert.equal(protectedResponse.status,200);
        assert.equal(protectedResponse.body, '', 'group metadata is not disclosed by direct web reads');
        for (const special of ['constructor', '__proto__']) {
            if (!await page.getByRole('button', {name:special,exact:true}).count()) await createGroup(special);
            await page.getByRole('button', {name:special,exact:true}).waitFor();
        }
        // Hold an old list response across a successful create; it must not erase it.
        let held = false;
        let staleReady;
        let releaseStale;
        const oldGroups = await page.evaluate(async () => (await fetch('/wp-json/fluent-snippets/groups', {headers:{'X-WP-Nonce':window.fluentSnippetAdmin.rest.nonce}})).json());
        const ready = new Promise(resolve => { staleReady = resolve; });
        const release = new Promise(resolve => { releaseStale = resolve; });
        const groupGet = /\/fluent-snippets\/groups(?:\?|$)/;
        await context.route(groupGet, async route => {
            if (held || route.request().method() !== 'GET') return route.continue();
            held = true;
            staleReady();
            await release;
            await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(oldGroups)});
        });
        try {
            await page.reload();
            await ready;
            await createGroup(unique + ' newer response');
            await page.getByRole('button',{name:unique + ' newer response',exact:true}).waitFor();
        } finally {
            releaseStale();
        }
        await page.waitForLoadState('networkidle');
        assert.equal(await page.getByRole('button',{name:unique + ' newer response',exact:true}).count(),1,'old group response cannot erase the created group');
        await context.unroute(groupGet);
        // The editor's existing plus button persists a group without saving a snippet.
        await page.goto(url.origin + '/wp-admin/admin.php?page=fluent-snippets#/create-new');
        await page.getByRole('button',{name:'Create new group',exact:true}).click();
        await page.getByPlaceholder('Create new group',{exact:true}).fill(unique + ' editor');
        await page.getByRole('button',{name:'Add',exact:true}).click();
        await page.getByPlaceholder('Create new group',{exact:true}).waitFor({state:'hidden'});
        await page.goto(url.origin + '/wp-admin/admin.php?page=fluent-snippets#/');
        await page.getByRole('button',{name:unique + ' editor',exact:true}).waitFor();
        const beforeRouteChange = await page.evaluate(async () => (await fetch('/wp-json/fluent-snippets/groups', {headers:{'X-WP-Nonce':window.fluentSnippetAdmin.rest.nonce}})).json());
        let editorHeld = false;
        let editorReady;
        let releaseEditor;
        const editorPending = new Promise(resolve => { editorReady = resolve; });
        const editorRelease = new Promise(resolve => { releaseEditor = resolve; });
        await context.route(groupGet, async route => {
            if (editorHeld || route.request().method() !== 'GET') return route.continue();
            editorHeld = true;
            editorReady();
            await editorRelease;
            await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(beforeRouteChange)});
        });
        try {
            // Client-side route changes leave an old component's request alive.
            await page.evaluate(() => { window.location.hash='#/create-new'; });
            await editorPending;
            await page.evaluate(() => { window.location.hash='#/'; });
            await createGroup(unique + ' after route change');
            await page.getByRole('button',{name:unique + ' after route change',exact:true}).waitFor();
        } finally {
            releaseEditor();
        }
        await page.waitForLoadState('networkidle');
        await page.getByRole('radio',{name:'Table',exact:true}).locator('xpath=ancestor::label').click();
        await page.getByRole('radio',{name:'Grouped',exact:true}).locator('xpath=ancestor::label').click();
        assert.equal(await page.getByRole('button',{name:unique + ' after route change',exact:true}).count(),1,'unmounted editor response cannot erase a newly created group');
        await context.unroute(groupGet);
        const oldDashboardGroups = await page.evaluate(async () => (await fetch('/wp-json/fluent-snippets/groups', {headers:{'X-WP-Nonce':window.fluentSnippetAdmin.rest.nonce}})).json());
        let dashboardHeld = false;
        let dashboardReady;
        let releaseDashboard;
        const dashboardPending = new Promise(resolve => { dashboardReady = resolve; });
        const dashboardRelease = new Promise(resolve => { releaseDashboard = resolve; });
        await context.route(groupGet, async route => {
            if (dashboardHeld || route.request().method() !== 'GET') return route.continue();
            dashboardHeld = true;
            dashboardReady();
            await dashboardRelease;
            await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(oldDashboardGroups)});
        });
        try {
            await page.reload();
            await dashboardPending;
            await page.evaluate(() => { window.location.hash='#/create-new'; });
            await page.getByRole('button',{name:'Create new group',exact:true}).click();
            await page.getByPlaceholder('Create new group',{exact:true}).fill(unique + ' after dashboard leave');
            await page.getByRole('button',{name:'Add',exact:true}).click();
            await page.getByPlaceholder('Create new group',{exact:true}).waitFor({state:'hidden'});
            await page.evaluate(() => { window.location.hash='#/'; });
            await page.getByRole('button',{name:unique + ' after dashboard leave',exact:true}).waitFor();
        } finally { releaseDashboard(); }
        await page.waitForLoadState('networkidle');
        await page.getByRole('radio',{name:'Table',exact:true}).locator('xpath=ancestor::label').click();
        await page.getByRole('radio',{name:'Grouped',exact:true}).locator('xpath=ancestor::label').click();
        assert.equal(await page.getByRole('button',{name:unique + ' after dashboard leave',exact:true}).count(),1,'unmounted dashboard response cannot erase an editor-created group');
        await context.unroute(groupGet);
        await page.setViewportSize({width:390,height:844});
        await page.evaluate(() => { document.body.classList.add('fluent_theme_dark'); document.documentElement.dir='rtl'; });
        await page.getByRole('button', {name:'Create group',exact:true}).click();
        const narrowDialog = page.getByRole('dialog');
        const box = await narrowDialog.locator('.el-dialog').count() ? await narrowDialog.locator('.el-dialog').boundingBox() : await narrowDialog.boundingBox();
        assert.ok(box.x>=0 && box.x+box.width<=390,'group dialog fits narrow RTL viewport');
        await narrowDialog.getByRole('textbox',{name:'Group name',exact:true}).fill('Unsaved narrow check');
        await narrowDialog.getByRole('button',{name:'Cancel',exact:true}).click();
        await narrowDialog.waitFor({state:'hidden'});
        assert.notEqual(await page.evaluate(()=>document.activeElement.tagName),'BODY','dialog restores keyboard focus');
        // A real partial rename is recoverable after the client state is discarded.
        await page.setViewportSize({width:1440,height:900});
        await page.reload();
        const retrySource = page.locator('.fsnip_group').filter({has: page.getByRole('button',{name:retry.source,exact:true})});
        await retrySource.getByRole('button',{name:'Rename group',exact:true}).click();
        dialog = page.getByRole('dialog');
        assert.equal(await dialog.getByRole('textbox',{name:'Group name',exact:true}).inputValue(),retry.target,'recorded retry target survives reload');
        await dialog.getByRole('button',{name:'Retry rename',exact:true}).click();
        await dialog.waitFor({state:'hidden'});
        await retrySource.waitFor({state:'hidden'});
        for (const retryFile of retry.files) {
            const item = JSON.parse(wp('echo wp_json_encode((new \\FluentSnippets\\App\\Model\\Snippet())->findByFileName(' + JSON.stringify(retryFile) + '));'));
            assert.equal(item.meta.group,retry.target);
            assert.equal(item.code,'/* WPMN 13596 retry fixture */');
            assert.equal(item.status,'draft');
        }
        await page.reload();
        const retryTarget = page.locator('.fsnip_group').filter({has: page.getByRole('button',{name:retry.target,exact:true})});
        const retryState = await page.evaluate(async () => {
            const response = await fetch('/wp-json/fluent-snippets/groups',{headers:{'X-WP-Nonce':window.fluentSnippetAdmin.rest.nonce}});
            return {status:response.status,data:await response.json()};
        });
        assert.equal(retryState.status,200);
        assert.equal(retryState.data.renames.some(rename=>rename.from===retry.source),false,'successful retry removes its saved operation');
        await retryTarget.getByRole('button',{name:'Rename group',exact:true}).click();
        dialog = page.getByRole('dialog');
        await dialog.getByRole('button',{name:'Rename group',exact:true}).waitFor();
        assert.equal(await dialog.getByRole('button',{name:'Retry rename',exact:true}).count(),0,'completed retry record is removed');
        await dialog.getByRole('button',{name:'Cancel',exact:true}).click();
        // Inject the controller's read-error shape with no snippets/groups at bootstrap.
        // This checks UI error handling without corrupting the fixture's saved registry.
        await page.addInitScript(() => {
            Object.defineProperty(window,'fluentSnippetAdmin',{configurable:true,set(data){
                data.groups=[];
                Object.defineProperty(window,'fluentSnippetAdmin',{value:data,writable:true,configurable:true});
            }});
        });
        await context.route(/\/fluent-snippets\/snippets(?:\?|$)/, route => route.fulfill({json:{snippets:{data:[],total:0,page:1,per_page:200,last_page:0},groups:[],tags:[]}}));
        await context.route(groupGet, route => route.fulfill({status:500,json:{code:'snippet_groups',message:'WPMN group registry read failed',data:{status:500}}}));
        await page.reload();
        await page.getByText('Thanks for installing FluentSnippets',{exact:true}).waitFor();
        await page.getByRole('alert').filter({hasText:'WPMN group registry read failed'}).waitFor({timeout:5000});
        assert.deepEqual(errors, []);
    } catch (error) {
        if (page && process.env.FSNIP_GROUPS_SCREENSHOT) await page.screenshot({path: process.env.FSNIP_GROUPS_SCREENSHOT, fullPage: true});
        throw error;
    } finally {
        await browser.close();
    }
});
