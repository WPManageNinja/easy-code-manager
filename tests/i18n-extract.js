/**
 * Pins what the translation extractor does and does not collect.
 *
 * i18n.node.js writes app/Services/Trans.php, which is the only route a string in the
 * admin app has to a translator. Everything it fails to match disappears silently — the
 * string still renders in English forever and nobody finds out. The cases below are the
 * ones that were actually broken:
 *
 *   - $t("Don't do this")  registered the key "Don", because any quote could close the
 *     literal. Wrong key, no warning.
 *   - $t('Has "quotes"')   was dropped outright for the same reason.
 *   - a comment that merely mentioned $t( had its prose captured as a key.
 *   - nothing was escaped on the way into PHP, so the first apostrophe to get through
 *     would have made Trans.php a syntax error.
 *
 *     node tests/i18n-extract.js
 *
 * Exit code 0 = pass, 1 = failure.
 *
 * Not shipped: build.sh only copies app/, dist/, language/ and the root files.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync, spawnSync } = require('child_process');

const repo = path.dirname(__dirname);
const work = fs.mkdtempSync(path.join(os.tmpdir(), 'fluent-snippets-i18n-'));

process.on('exit', () => fs.rmSync(work, { recursive: true, force: true }));

let failures = 0;
let checks = 0;

function check(label, passed, detail = '') {
    checks++;
    if (passed) {
        console.log(`  ok    ${label}`);
        return;
    }
    failures++;
    console.log(`  FAIL  ${label}${detail ? ' — ' + detail : ''}`);
}

/**
 * Run the real extractor over a throwaway src/ tree and return what it produced.
 *
 * @param {Object<string,string>} files Relative path under src/ => contents.
 * @param {Object} reserved reserved18n.json contents.
 * @returns {{php: string, keys: string[], stdout: string}}
 */
function extract(files, reserved = {}) {
    fs.rmSync(path.join(work, 'src'), { recursive: true, force: true });
    fs.mkdirSync(path.join(work, 'src'), { recursive: true });
    fs.mkdirSync(path.join(work, 'app', 'Services'), { recursive: true });

    for (const [name, contents] of Object.entries(files)) {
        const target = path.join(work, 'src', name);
        fs.mkdirSync(path.dirname(target), { recursive: true });
        fs.writeFileSync(target, contents);
    }

    fs.writeFileSync(path.join(work, 'reserved18n.json'), JSON.stringify(reserved));
    fs.copyFileSync(path.join(repo, 'i18n.node.js'), path.join(work, 'i18n.node.js'));

    // Warnings go to stderr, which is where a build log wants them, so both streams are
    // collected here.
    const run = spawnSync(process.execPath, ['i18n.node.js'], { cwd: work, encoding: 'utf8' });

    if (run.status !== 0) {
        throw new Error(`extractor exited ${run.status}: ${run.stderr}`);
    }

    const stdout = run.stdout + run.stderr;

    const php = fs.readFileSync(path.join(work, 'app', 'Services', 'Trans.php'), 'utf8');

    // Read the keys back the way PHP will, so an escaping mistake shows up as a wrong
    // key rather than being invisible in the generated source.
    const keys = JSON.parse(execFileSync('php', [
        '-r',
        'function __($t,$d=""){return $t;} require "app/Services/Trans.php"; ' +
        'echo json_encode(array_keys(\\FluentSnippets\\App\\Services\\Trans::getStrings()));'
    ], { cwd: work, encoding: 'utf8' }));

    return { php, keys, stdout };
}

console.log('\nQuotes inside strings survive the round trip to PHP');

const quoted = extract({
    'probe.vue': [
        '<template><div>',
        "  {{ $t('Plain string') }}",
        '  {{ $t("Don\'t do this") }}',
        "  {{ $t('It\\'s escaped') }}",
        '  {{ $t(\'Has "double quotes" inside\') }}',
        '  {{ $t(`Backtick static`) }}',
        "  {{ $t('Back\\\\slash') }}",
        '</div></template>'
    ].join('\n')
});

check('a plain string is collected', quoted.keys.includes('Plain string'));
check('an apostrophe inside double quotes is not truncated', quoted.keys.includes("Don't do this"), JSON.stringify(quoted.keys));
check('"Don" is not registered as a key', !quoted.keys.includes('Don'));
check('an escaped apostrophe decodes to the runtime value', quoted.keys.includes("It's escaped"), JSON.stringify(quoted.keys));
check('a string containing double quotes is collected', quoted.keys.includes('Has "double quotes" inside'), JSON.stringify(quoted.keys));
check('a plain template literal is collected', quoted.keys.includes('Backtick static'));
check('a backslash survives as one backslash', quoted.keys.includes('Back\\slash'), JSON.stringify(quoted.keys));

console.log('\nComments are not a source of strings');

const commented = extract({
    'probe.js': [
        '// Strings are collected by scanning for `$t(` in the source.',
        '// This second line must not become part of a key.',
        "const a = $t('Real string');",
        '/*',
        " * $t('Commented out string')",
        ' */',
        "const b = $t('Another real string');",
        '<!-- $t(\'Markup comment string\') -->',
        "const c = $t('Third real string');"
    ].join('\n')
});

check('a call after a mentioning comment still works', commented.keys.includes('Real string'), JSON.stringify(commented.keys));
check('comment prose is not captured', !commented.keys.some(k => k.includes('must not become part of a key')), JSON.stringify(commented.keys));
check('a block-commented call is ignored', !commented.keys.includes('Commented out string'));
check('an HTML-commented call is ignored', !commented.keys.includes('Markup comment string'));
check('calls after every comment style are still found',
    commented.keys.includes('Another real string') && commented.keys.includes('Third real string'),
    JSON.stringify(commented.keys));

console.log('\nStrings that cannot be collected are reported, not dropped in silence');

const dynamic = extract({
    'probe.js': [
        'const label = whatever;',
        'const a = $t(label);',
        'const b = $t(`Hi ${name}`);',
        '$t(string) {',
        '    return string;',
        '}'
    ].join('\n')
});

check('a variable argument is reported', /\$t\(label\)/.test(dynamic.stdout), JSON.stringify(dynamic.stdout));
check('an interpolated template literal is reported', /interpolation/.test(dynamic.stdout), JSON.stringify(dynamic.stdout));
check('the $t method definition is not reported', !/\$t\(string\)/.test(dynamic.stdout), JSON.stringify(dynamic.stdout));

console.log('\nreserved18n.json carries strings the scan cannot see');

const reserved = extract(
    { 'probe.js': "const a = $t('Visible');" },
    { '__PLACEHOLDER__': 'The full sentence.', 'Prop default text': 'Prop default text' }
);

check('a placeholder key maps to its English text', /'__PLACEHOLDER__' => __\('The full sentence\.'/.test(reserved.php));
check('an unseen literal is still emitted', reserved.keys.includes('Prop default text'));
check('scanned strings are kept alongside', reserved.keys.includes('Visible'));

console.log(`\n${checks} checks, ${failures} failed`);

process.exit(failures ? 1 : 0);
