<?php
/**
 * Behaviour check for Helper::syncSnippetIndex() and Helper::getSnippetFilesHash().
 *
 * The admin used to rebuild index.php on every list request — a full re-read and
 * re-parse of every snippet file plus a rewrite of index.php, on every search
 * keystroke and pagination click. That rebuild exists to heal an index that has
 * drifted from disk (an FTP edit, a deploy, a migration), so it cannot simply be
 * deleted; it is now gated behind a stat-only fingerprint.
 *
 * The two properties that matter, and that this file pins down:
 *
 *   1. When nothing on disk changed, syncSnippetIndex() must not write. Proven by
 *      the inode of index.php: Helper::atomicPut() swaps the file via rename(), so
 *      a write always changes the inode.
 *   2. When anything did change, it must rebuild — including changes that keep the
 *      file size identical, and including the two things no file signature can see
 *      (a plugin version bump and a domain change).
 *
 * Runs against a temp directory with stubbed WordPress functions. No WP install
 * needed:
 *
 *     php tests/index-sync.php
 *
 * Exit code 0 = pass, 1 = failure.
 *
 * Not shipped: build.sh only copies app/, dist/, language/ and the root files.
 */

$base = dirname(__DIR__) . '/';

$storage = sys_get_temp_dir() . '/fluent-snippets-index-sync-' . getmypid();

if (!is_dir($storage)) {
    mkdir($storage, 0777, true);
}

register_shutdown_function(function () use ($storage) {
    foreach (glob($storage . '/{,.}*', GLOB_BRACE) as $file) {
        if (basename($file) === '.' || basename($file) === '..') { continue; }
        is_dir($file) ? @rmdir($file) : @unlink($file);
    }
    @rmdir($storage);
});

define('ABSPATH', $base);
define('WP_CONTENT_DIR', $storage);
define('FLUENT_SNIPPETS_STORAGE_DIR', $storage);
define('FLUENT_SNIPPETS_PLUGIN_VERSION', '10.55');

// Minimal WordPress surface. Only the functions Helper and Snippet actually reach
// on the code paths under test.
function untrailingslashit($string) { return rtrim($string, '/\\'); }
function wp_parse_args($args, $defaults = []) { return array_merge($defaults, (array)$args); }
function wp_mkdir_p($dir) { return is_dir($dir) || mkdir($dir, 0777, true); }
function site_url() { return 'https://example.test'; }
function content_url($path = '') { return 'https://example.test/wp-content' . $path; }
function wp_upload_dir() { return ['basedir' => '/tmp/uploads', 'baseurl' => 'https://example.test/uploads']; }
function apply_filters($tag, $value) { return $value; }
function do_action() {}
function is_wp_error($thing) { return $thing instanceof \WP_Error; }
function sanitize_file_name($name) { return preg_replace('/[^a-zA-Z0-9._-]/', '', $name); }
function sanitize_title($title) { return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title)); }
function current_time($type) { return date('Y-m-d H:i:s'); }
function __($text) { return $text; }

class WP_Error
{
    public $message;
    public function __construct($code = '', $message = '') { $this->message = $message; }
}

require $base . 'app/Helpers/Arr.php';
require $base . 'app/Helpers/Helper.php';
require $base . 'app/Model/Snippet.php';

use FluentSnippets\App\Helpers\Helper;

$failures = 0;
$checks = 0;

function check($label, $passed, $detail = '')
{
    global $failures, $checks;
    $checks++;
    if ($passed) {
        echo "  ok    $label\n";
        return;
    }
    $failures++;
    echo "  FAIL  $label" . ($detail ? " — $detail" : '') . "\n";
}

/**
 * Write a snippet file in the exact format Snippet::getMetaData() produces, so
 * parseBlock() accepts it.
 */
function writeSnippet($storage, $fileName, $name, $code = 'echo 1;')
{
    $meta = [
        'name'                 => $name,
        'status'               => 'published',
        'tags'                 => '',
        'description'          => 'test snippet',
        'type'                 => 'PHP',
        'run_at'               => 'plugins_loaded',
        'priority'             => 10,
        'group'                => 'Uncategorized',
        'condition'            => '[]',
        'load_as_file'         => 'no',
        'load_in_block_editor' => 'no',
        'created_at'           => '2026-01-01 00:00:00',
        'updated_at'           => '2026-01-01 00:00:00',
    ];

    $content = '<?php' . PHP_EOL . '// <Internal Doc Start>' . PHP_EOL . '/*' . PHP_EOL . '*';
    foreach ($meta as $key => $value) {
        $content .= PHP_EOL . '* @' . $key . ': ' . $value;
    }
    $content .= PHP_EOL . '*/' . PHP_EOL . '?>' . PHP_EOL;
    $content .= '<?php if (!defined("ABSPATH")) { return;} // <Internal Doc End> ?>' . PHP_EOL;
    $content .= '<?php ' . $code . PHP_EOL;

    file_put_contents($storage . '/' . $fileName, $content);
}

/**
 * The inode of index.php. atomicPut() renames a temp file over the target, so this
 * changes on every write and never changes without one.
 */
function indexInode($storage)
{
    clearstatcache();
    $stat = @stat($storage . '/index.php');
    return $stat ? $stat['ino'] : null;
}

function readIndex($storage)
{
    clearstatcache();
    if (!is_file($storage . '/index.php')) {
        return [];
    }
    return include $storage . '/index.php';
}

/** Rewrite index.php with a mutated meta value, to simulate a stale index. */
function mutateIndexMeta($storage, $key, $value)
{
    $config = readIndex($storage);
    $config['meta'][$key] = $value;
    Helper::saveIndexedConfig($config);
}

echo "Storage: $storage\n\n";

echo "Fresh install\n";
writeSnippet($storage, 'hello-world.php', 'Hello World');
check('rebuilds when index.php does not exist', Helper::syncSnippetIndex() === true);
check('index.php was created', is_file($storage . '/index.php'));
$config = readIndex($storage);
check('the snippet made it into the index', isset($config['published']['hello-world.php']));
check('a files_hash was stored', !empty($config['meta']['files_hash']));

echo "\nNothing changed\n";
$inode = indexInode($storage);
check('second call reports no rebuild', Helper::syncSnippetIndex() === false);
check('index.php was not rewritten', indexInode($storage) === $inode, 'inode changed, so a write happened');

echo "\nindex.php is excluded from the signature\n";
// index.php itself lives in the globbed directory. If it counted toward the hash,
// every rebuild would invalidate the value it had just stored and the gate would
// never match — so this is really a regression test for an infinite rebuild loop.
$hashBefore = Helper::getSnippetFilesHash();
touch($storage . '/index.php', time() + 60);
clearstatcache();
check('touching index.php does not move the hash', Helper::getSnippetFilesHash() === $hashBefore);
check('touching index.php does not trigger a rebuild', Helper::syncSnippetIndex() === false);

echo "\nA snippet is added\n";
writeSnippet($storage, 'second-one.php', 'Second One');
check('rebuild triggered', Helper::syncSnippetIndex() === true);
$config = readIndex($storage);
check('the new snippet is in the index', isset($config['published']['second-one.php']));
check('settles back to no-rebuild', Helper::syncSnippetIndex() === false);

echo "\nA snippet is edited in place, keeping the byte count identical\n";
// The size-only half of the signature would miss this; mtime is what catches it.
$target = $storage . '/second-one.php';
$sizeBefore = filesize($target);
$content = file_get_contents($target);
file_put_contents($target, str_replace('echo 1;', 'echo 2;', $content));
touch($target, time() + 120);
clearstatcache();
check('the edit really did keep the size identical', filesize($target) === $sizeBefore);
check('rebuild triggered', Helper::syncSnippetIndex() === true);
check('settles back to no-rebuild', Helper::syncSnippetIndex() === false);

echo "\nA snippet is deleted\n";
unlink($storage . '/second-one.php');
check('rebuild triggered', Helper::syncSnippetIndex() === true);
$config = readIndex($storage);
check('the deleted snippet is gone from the index', !isset($config['published']['second-one.php']));
check('settles back to no-rebuild', Helper::syncSnippetIndex() === false);

echo "\nStale index that no file signature can detect\n";
mutateIndexMeta($storage, 'cached_version', '10.40');
check('a plugin version bump forces a rebuild', Helper::syncSnippetIndex() === true);
check('settles back to no-rebuild', Helper::syncSnippetIndex() === false);

mutateIndexMeta($storage, 'cashed_domain', 'https://old-domain.test');
check('a domain change forces a rebuild', Helper::syncSnippetIndex() === true);
check('settles back to no-rebuild', Helper::syncSnippetIndex() === false);

mutateIndexMeta($storage, 'files_hash', '');
check('a missing files_hash forces a rebuild', Helper::syncSnippetIndex() === true);
check('settles back to no-rebuild', Helper::syncSnippetIndex() === false);

echo "\nindex.php is deleted outright\n";
unlink($storage . '/index.php');
check('rebuild triggered', Helper::syncSnippetIndex() === true);
check('index.php is back', is_file($storage . '/index.php'));
check('settles back to no-rebuild', Helper::syncSnippetIndex() === false);

echo "\nEmpty storage directory\n";
foreach (glob($storage . '/*.php') as $file) {
    unlink($file);
}
check('rebuild triggered after everything was removed', Helper::syncSnippetIndex() === true);
$config = readIndex($storage);
check('the index is empty', empty($config['published']) && empty($config['draft']));
check('settles back to no-rebuild with zero snippets', Helper::syncSnippetIndex() === false);

// Backups can have a different header separator from this host. Exercise the
// rebuilt index, model and registered callbacks against the same on-disk files.
function add_action($hook, $callback) { $GLOBALS['snippetHooks'][$hook][] = $callback; }
function add_filter($hook, $callback) { add_action($hook, $callback); }
function wp_add_inline_style($handle, $code) { $GLOBALS['editorCss'][$handle] = $code; }

require $base . 'app/Services/FluentSnippetCondition.php';
require $base . 'app/Services/CodeRunner.php';
require $base . 'app/Services/mu.stub';

function restoredCheck($name, $passed, $message)
{
    global $checks, $failures;
    $checks++;
    if (!$passed) {
        $failures++;
    }
    echo $passed ? "CASE PASS $name\n" : "CASE FAIL $name -- $message\n";
}

function restoredHeader($type, $description, $eol)
{
    return implode($eol, [
        '<?php', '// <Internal Doc Start>', '/*', '* @name: Restore',
        '* @status: published', '* @type: ' . $type, '* @run_at: admin_head',
        '* @description: ' . $description, '* @load_in_block_editor: yes',
        '*/', '?>', '<?php if (!defined("ABSPATH")) { return;} // <Internal Doc End> ?>'
    ]);
}

function runtimeMatches($runner, $body)
{
    $GLOBALS['snippetHooks'] = [];
    $GLOBALS['editorCss'] = [];
    $runner->runSnippets();
    ob_start();
    foreach ($GLOBALS['snippetHooks']['admin_head'] as $render) {
        $render();
    }
    $html = ob_get_clean();
    $styles = $GLOBALS['snippetHooks']['block_editor_settings_all'][0]([]);
    $GLOBALS['snippetHooks']['enqueue_block_editor_assets'][0]();
    return strpos($html, '<style>' . $body . '</style>') !== false
        && strpos($html, '<script>' . $body . '</script>') !== false
        && ($styles['styles'][0]['css'] ?? null) === $body
        && ($GLOBALS['editorCss']['wp-edit-blocks'] ?? null) === $body;
}

$model = new \FluentSnippets\App\Model\Snippet();
$runners = [new \FluentSnippets\App\Services\CodeRunner(), new \FluentSnippets\Mu\CodeRunner()];
$body = "\r\n/* restored */\n";
foreach (['native' => PHP_EOL, 'foreign' => PHP_EOL === "\n" ? "\r\n" : "\n"] as $kind => $eol) {
    $suffix = str_repeat('x', 110);
    foreach (['css', 'js'] as $type) {
        $header = restoredHeader($type, 'First' . $eol . $suffix, $eol);
        file_put_contents($storage . '/' . $type . '.php', $header . $eol . $body);
    }
    Helper::cacheSnippetIndex();
    $config = readIndex($storage);
    restoredCheck("$kind description", $config['published']['css.php']['description'] === substr('First. ' . $suffix, 0, 101), 'description differs');
    $found = $model->findByFileName('css.php');
    if ($kind === 'native') {
        restoredCheck('native and bare marker', $found['code'] === $body && $model->parseBlock($header . 'same line', true) === 'same line', 'stored body differs');
    } else {
        restoredCheck('foreign stored body', $found['code'] === $body, 'stored body differs');
    }
    foreach ($runners as $runner) {
        restoredCheck("$kind runtime output with data set " . get_class($runner), runtimeMatches($runner, $body), 'runtime output differs');
    }
}

$markerBody = "/* // <Internal Doc End> ?>" . PHP_EOL . "// <Internal Doc Start> */";
foreach (['css', 'js'] as $type) {
    file_put_contents($storage . '/' . $type . '.php', restoredHeader($type, 'Marker text', PHP_EOL) . PHP_EOL . $markerBody);
}
restoredCheck('marker text in body with data set model', $model->findByFileName('css.php')['code'] === $markerBody, 'body was truncated');
foreach ($runners as $runner) {
    restoredCheck('marker text in body with data set ' . get_class($runner), runtimeMatches($runner, $markerBody), 'body was truncated');
}
restoredCheck('empty or missing header', $model->parseBlock('plain body') === [null, null]
    && $model->parseBlock($header . PHP_EOL) === [null, null]
    && $model->parseBlock('plain body', true) === '', 'unexpected empty result');

// A restore can leave an indexed file incomplete. No source from a partial header
// should reach the page, and a missing separator must not raise a PHP warning.
file_put_contents($storage . '/css.php', "<?php\n// <Internal Doc Start>\n/* incomplete */");
foreach ($runners as $runner) {
    $GLOBALS['snippetHooks'] = [];
    $runner->runSnippets();
    $warnings = [];
    set_error_handler(function ($level, $message) use (&$warnings) {
        $warnings[] = $message;
        return true;
    });
    try {
        $settings = $GLOBALS['snippetHooks']['block_editor_settings_all'][0]([]);
    } finally {
        restore_error_handler();
    }
    restoredCheck('incomplete runtime header with data set ' . get_class($runner), $settings === [] && !$warnings, 'incomplete header warned or emitted code');
}

echo "\n";
if ($failures) {
    echo "$failures of $checks checks FAILED\n";
    exit(1);
}

echo "All $checks checks passed\n";
exit(0);
