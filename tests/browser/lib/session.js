/**
 * An authenticated wp-admin browser session for the FluentSnippets smoke test.
 *
 * Authentication is minted through WP-CLI, so the suite never stores a password or
 * drives wp-login.php. Both LOGGED_IN_COOKIE and SECURE_AUTH_COOKIE are set: with
 * FORCE_SSL_ADMIN on, sending only the first redirects to wp-login with no error.
 *
 * Adapted from the FluentCRM browser suite (wp-plugin-test-suite).
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const {spawnSync} = require('child_process');
const {chromium} = require('playwright');

const PLUGIN_SLUG = 'easy-code-manager';

/**
 * The WordPress root: FSNIP_WP_ROOT, or the first directory above the plugin that holds
 * wp-load.php. A plugin symlinked in from a repository has no WordPress above it, so
 * that case needs the variable.
 */
function resolveWpRoot() {
    const override = process.env.FSNIP_WP_ROOT;

    if (override) {
        if (!fs.existsSync(path.join(override, 'wp-load.php'))) {
            throw new Error(`FSNIP_WP_ROOT is '${override}', but there is no wp-load.php there.`);
        }
        return override.replace(/\/+$/, '');
    }

    let dir = path.resolve(__dirname, '..', '..', '..');
    while (dir !== path.parse(dir).root) {
        if (fs.existsSync(path.join(dir, 'wp-load.php'))) {
            return dir;
        }
        dir = path.dirname(dir);
    }

    throw new Error(
        'Could not find WordPress above this plugin. Point the test at a site with the plugin active:\n' +
        '    FSNIP_WP_ROOT=/path/to/wordpress npm run test:browser'
    );
}

const WP_ROOT = resolveWpRoot();

function wpEval(php) {
    const result = spawnSync('wp', ['eval', php], {
        cwd: WP_ROOT,
        encoding: 'utf8',
        maxBuffer: 10 * 1024 * 1024
    });
    const out = result.stdout || '';

    // Other plugins print deprecation notices around our output, and a PHP error can
    // contain braces of its own, so the answer is the last line that parses as JSON.
    const lines = out.split('\n').map(line => line.trim()).filter(line => line.startsWith('{'));
    for (let i = lines.length - 1; i >= 0; i--) {
        try {
            return JSON.parse(lines[i]);
        } catch (e) {
            // Not ours; keep looking.
        }
    }

    // Most of what WP-CLI printed is other plugins' deprecation notices; what explains a
    // failure is whatever is not one of those.
    const noise = /^(PHP )?Deprecated:|^Notice: Function _load_textdomain_just_in_time/;
    const said = (out + '\n' + (result.stderr || '')).split('\n').filter(line => line.trim() && !noise.test(line));
    throw new Error(`WP-CLI did not answer with JSON (exit ${result.status}).\n` + (said.slice(-8).join('\n') || '(no output)'));
}

/**
 * What the run needs from the site, read in one WP-CLI boot: cookies for the first
 * administrator, the first snippet to open read-only, and the saved settings so the run
 * can prove it left them alone. Creates nothing.
 */
function mintAdminContext() {
    return wpEval(`
        if (!defined("FLUENT_SNIPPETS_PLUGIN_VERSION")) { echo json_encode(["error" => "FluentSnippets is not active on this site."]); return; }

        $users = get_users(["role" => "administrator", "number" => 1, "orderby" => "ID"]);
        if (!$users) { echo json_encode(["error" => "This site has no administrator."]); return; }
        $user = $users[0];
        $expiry = time() + 3600;

        $dir = \\FluentSnippets\\App\\Helpers\\Helper::getStorageDir();
        $files = array_values(array_filter(array_map("basename", glob($dir . "/*.php") ?: []), function ($f) { return $f !== "index.php"; }));
        sort($files, SORT_NATURAL);

        $snippet = null;
        if ($files) {
            $found = (new \\FluentSnippets\\App\\Model\\Snippet())->findByFileName($files[0]);
            if (!is_wp_error($found)) {
                $snippet = ["file" => $files[0], "name" => $found["meta"]["name"]];
            }
        }

        $config = \\FluentSnippets\\App\\Helpers\\Helper::getIndexedConfig();

        echo json_encode([
            "domain"     => parse_url(home_url(), PHP_URL_HOST),
            "adminUrl"   => admin_url(),
            "version"    => FLUENT_SNIPPETS_PLUGIN_VERSION,
            "storageDir" => $dir,
            "snippet"    => $snippet,
            "settings"   => $config && isset($config["meta"]) ? $config["meta"] : null,
            "cookies"    => [
                ["name" => LOGGED_IN_COOKIE, "value" => wp_generate_auth_cookie($user->ID, $expiry, "logged_in")],
                ["name" => SECURE_AUTH_COOKIE, "value" => wp_generate_auth_cookie($user->ID, $expiry, "secure_auth")],
            ],
        ]);
    `);
}

function readSettings() {
    return wpEval(`
        $config = \\FluentSnippets\\App\\Helpers\\Helper::getIndexedConfig();
        echo json_encode(["settings" => $config && isset($config["meta"]) ? $config["meta"] : null]);
    `).settings;
}

/**
 * A fingerprint of every snippet file: name and content. index.php is left out: it is
 * the plugin's cache of these files, and the app is allowed to resync it on boot.
 */
function fingerprintSnippets(dir) {
    const hash = crypto.createHash('sha256');
    const files = fs.readdirSync(dir).filter(f => f.endsWith('.php') && f !== 'index.php').sort();

    for (const file of files) {
        hash.update(file + '\0');
        hash.update(fs.readFileSync(path.join(dir, file)));
    }

    return {count: files.length, hash: hash.digest('hex')};
}

async function openAdminSession(options = {}) {
    const auth = mintAdminContext();

    if (auth.error) {
        throw new Error(auth.error);
    }

    const browser = await chromium.launch({headless: !options.headed});
    const context = await browser.newContext({
        ignoreHTTPSErrors: true,
        viewport: {width: 1440, height: 900}
    });

    await context.addCookies(auth.cookies.map(cookie => ({
        name: cookie.name,
        value: cookie.value,
        domain: auth.domain,
        path: '/',
        httpOnly: true,
        secure: true
    })));

    return {browser, context, auth};
}

/**
 * Whether a console error belongs to this plugin.
 *
 * Narrow on purpose: a failed load from another plugin, or another plugin's dev server
 * that is not running, is not ours. Everything else - including any error from
 * WordPress itself - counts, because this screen dequeues other plugins' scripts and
 * should be quiet.
 */
function isOurConsoleError(text, url) {
    const fromOtherPlugin = /\/plugins\//.test(url || '') && !url.includes(`/plugins/${PLUGIN_SLUG}/`);
    const fromDevServer = /^https?:\/\/(localhost|127\.0\.0\.1):\d+\//.test(url || '');

    if (fromOtherPlugin || fromDevServer) {
        return false;
    }

    return true;
}

module.exports = {
    PLUGIN_SLUG,
    WP_ROOT,
    openAdminSession,
    readSettings,
    fingerprintSnippets,
    isOurConsoleError
};
