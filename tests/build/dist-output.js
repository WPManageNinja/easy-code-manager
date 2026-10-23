/**
 * Pins the shape of the production build in dist/.
 *
 * AdminMenuHandler enqueues dist/app.js as an ordinary <script> after jQuery, reading
 * window.fluentSnippetAdmin, and dist/app.css next to it. Vite will happily produce
 * something else - an ES module, split chunks, CSS left inside the JS - and the admin
 * screen would then load blank with nothing failing until someone opened it. What has to
 * hold:
 *
 *   - dist/ holds app.js, app.css and the index.php directory guard, and nothing else:
 *     no chunks, no source maps
 *   - app.js is a classic script: it parses outside a module, with no import, export or
 *     import.meta in it
 *   - process.env.NODE_ENV was replaced at build time (there is no `process` in a browser)
 *   - app.css carries the app's own styles, scoped to #fluent_snippets_app, with every
 *     @import inlined and no @apply / @tailwind left from the old build
 *   - the PHP still enqueues exactly these two files
 *
 * Runs after the asset build, not with the other tests, because it checks the build
 * itself. build.sh calls it on its own; by hand:
 *
 *     npm run build && node tests/build/dist-output.js
 *     node tests/build/dist-output.js path/to/dist     check another directory
 *
 * Exit code 0 = pass, 1 = failure.
 *
 * Not shipped: build.sh only copies app/, dist/, language/ and the root files.
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const repo = path.resolve(__dirname, '../..');
const dist = path.resolve(process.argv[2] || path.join(repo, 'dist'));

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

function read(file) {
    const full = path.join(dist, file);
    return fs.existsSync(full) ? fs.readFileSync(full, 'utf8') : null;
}

console.log(`\ndist/ holds the two files PHP loads (${path.relative(repo, dist) || dist})`);

const files = fs.existsSync(dist) ? fs.readdirSync(dist).filter(f => f !== '.DS_Store').sort() : [];
check('dist/ exists', fs.existsSync(dist));
check('it holds app.css, app.js and index.php and nothing else', JSON.stringify(files) === '["app.css","app.js","index.php"]',
    'found: ' + (files.join(', ') || 'nothing') + (files.some(f => f.endsWith('.map')) ? ' (a development build: run npm run build)' : ''));
check('index.php is the "silence is golden" guard every plugin folder has', /^<\?php\s*\n\/\/ Silence is golden\.\s*$/.test(read('index.php') || ''),
    JSON.stringify((read('index.php') || '(missing)').slice(0, 60)));

const js = read('app.js') || '';
const css = read('app.css') || '';

console.log('\napp.js is a classic script');

let parseError = null;
try {
    new vm.Script(js, {filename: 'app.js'});
} catch (e) {
    parseError = e.message;
}
check('it parses as a script, not a module', js && !parseError, parseError || 'app.js is missing or empty');
check('it is wrapped in a function, so nothing leaks onto window', /^\s*(\/\*[\s\S]*?\*\/\s*)?(!?\(function|\(\(\)\s*=>|\(\(\s*\w+\s*\)\s*=>)/.test(js),
    'starts with: ' + JSON.stringify(js.slice(0, 40)));
check('process.env.NODE_ENV was replaced at build time', !/process\.env\.NODE_ENV/.test(js));
check('no source map is referenced', !/sourceMappingURL=/.test(js));
check('it mounts on #fluent_snippets_app', js.includes('#fluent_snippets_app'));
check('it reads window.fluentSnippetAdmin', js.includes('fluentSnippetAdmin'));

console.log('\napp.css carries the app\'s styles');

check('it is not empty', css.length > 10000, `${css.length} bytes`);
check('it is scoped to #fluent_snippets_app', css.includes('#fluent_snippets_app'));
check('it includes Element Plus\'s styles', css.includes('.el-button') && css.includes('.el-notification'));
check('every @import was inlined', !/@import\b/.test(css), (css.match(/@import[^;]*;/) || [''])[0]);
check('no @apply or @tailwind is left over', !/@(apply|tailwind)\b/.test(css), (css.match(/@(apply|tailwind)[^;{]*/) || [''])[0]);

console.log('\nThe PHP enqueues exactly these files');

const handler = fs.readFileSync(path.join(repo, 'app/Hooks/Handlers/AdminMenuHandler.php'), 'utf8');
const enqueued = [...handler.matchAll(/wp_enqueue_(?:script|style)\([^;]*?'(dist\/[^']+)'/g)].map(m => m[1]).sort();
check('AdminMenuHandler enqueues dist/app.css and dist/app.js', JSON.stringify(enqueued) === '["dist/app.css","dist/app.js"]',
    'found: ' + (enqueued.join(', ') || 'nothing'));

console.log(`\n${checks} checks, ${failures} failed`);

process.exit(failures ? 1 : 0);
