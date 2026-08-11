<?php
/**
 * Checks for the behavioural half of the cleanup pass (H3/I7, M9, M14, L1, L5).
 *
 * Most of that pass was mechanical, but four items change what the code actually does
 * and are worth pinning:
 *
 *   H3/I7 — error_files used to grow forever. The unset() calls in handleFileDelete()
 *           mutated a local copy that cacheSnippetIndex() then discarded, so a deleted
 *           snippet's error entry survived every rebuild. Pruning now happens inside
 *           the rebuild, which also heals entries already stranded on disk.
 *   M9    — a config written by an older version, or a snippet file with no @priority
 *           line, must not warn on rebuild.
 *   M14   — getIndexedConfig()'s static cache has to hold for an *empty* config, which
 *           is the normal state on a fresh install.
 *   L1    — escCssJs() has to catch the closing-tag spellings HTML parsers accept.
 *   L5    — search has to be case-insensitive.
 *
 *     php tests/cleanup-pass.php
 *
 * Exit code 0 = pass, 1 = failure.
 *
 * Not shipped: build.sh only copies app/, dist/, language/ and the root files.
 */

$base = dirname(__DIR__) . '/';

$storage = sys_get_temp_dir() . '/fluent-snippets-cleanup-' . getmypid();

if (!is_dir($storage)) {
    mkdir($storage, 0777, true);
}

register_shutdown_function(function () use ($storage) {
    foreach (glob($storage . '/{,.}*', GLOB_BRACE) as $file) {
        if (basename($file) === '.' || basename($file) === '..') {
            continue;
        }
        is_dir($file) ? @rmdir($file) : @unlink($file);
    }
    @rmdir($storage);
});

define('ABSPATH', $base);
define('WP_CONTENT_DIR', $storage);
define('FLUENT_SNIPPETS_STORAGE_DIR', $storage);
define('FLUENT_SNIPPETS_PLUGIN_VERSION', '10.55');

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
function get_current_user_id() { return 1; }
function __($text, $domain = '') { return $text; }

class WP_Error
{
    public $message;
    public function __construct($code = '', $message = '') { $this->message = $message; }
}

require $base . 'app/Helpers/Arr.php';
require $base . 'app/Helpers/Helper.php';
require $base . 'app/Model/Snippet.php';
require $base . 'app/Services/SnippetErrors.php';

use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Model\Snippet;

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

function readIndex($storage)
{
    clearstatcache();
    return is_file($storage . '/index.php') ? include $storage . '/index.php' : [];
}

function makeSnippet($storage, $name, $extraMeta = [])
{
    $model = new Snippet();
    return $model->createSnippet('<?php echo 1;', array_merge([
        'name'   => $name,
        'type'   => 'PHP',
        'run_at' => 'wp',
        'status' => 'published',
    ], $extraMeta));
}

// Anything raised while rebuilding is a failure in its own right — several of these
// items exist purely to stop PHP 8 warnings on the rebuild path.
$warnings = [];
set_error_handler(function ($no, $str, $file, $line) use (&$warnings) {
    $warnings[] = "$str ($file:$line)";
    return true;
});

echo "error_files is pruned to snippets that still exist (H3 / I7)\n";

$keep = makeSnippet($storage, 'Keeper');
$doomed = makeSnippet($storage, 'Doomed');
Helper::cacheSnippetIndex();

// Quarantine both, the way the fatal handler does.
$config = readIndex($storage);
$config['error_files'][$keep] = 'Fatal error in the keeper';
$config['error_files'][$doomed] = 'Fatal error in the doomed one';
Helper::saveIndexedConfig($config);

$config = readIndex($storage);
check('both snippets start out quarantined', count($config['error_files']) === 2);

// Delete one the way the UI does, then rebuild.
(new Snippet())->deleteSnippet($doomed);
Helper::cacheSnippetIndex();

$config = readIndex($storage);
check('the deleted snippet\'s error entry is gone', !isset($config['error_files'][$doomed]));
check('the surviving snippet keeps its error entry', isset($config['error_files'][$keep]), 'a live quarantine must not be cleared');
check('the deleted snippet is out of published too', !isset($config['published'][$doomed]));

// An entry for a file that never existed — the state already stranded on disk across
// every site that has ever deleted a quarantined snippet.
$config = readIndex($storage);
$config['error_files']['long-gone-snippet.php'] = 'Fatal from a previous life';
Helper::saveIndexedConfig($config);
Helper::cacheSnippetIndex();

$config = readIndex($storage);
check('a pre-existing orphan is healed on the next rebuild', !isset($config['error_files']['long-gone-snippet.php']));

echo "\nA draft snippet's quarantine survives (it can still be published)\n";
$draft = makeSnippet($storage, 'Draft One', ['status' => 'draft']);
Helper::cacheSnippetIndex();
$config = readIndex($storage);
$config['error_files'][$draft] = 'Fatal in a draft';
Helper::saveIndexedConfig($config);
Helper::cacheSnippetIndex();
$config = readIndex($storage);
check('drafts are not treated as deleted', isset($config['error_files'][$draft]));

echo "\nLegacy and empty configs do not warn (M9)\n";

// A config as an older version would have written it: no priority on the snippet file,
// and meta missing the keys added since.
file_put_contents($storage . '/legacy-one.php', str_replace(
    PHP_EOL . '* @priority: 10',
    '',
    file_get_contents($storage . '/' . $keep)
));

$config = readIndex($storage);
unset($config['meta']['auto_disable'], $config['meta']['auto_publish'], $config['meta']['remove_on_uninstall'], $config['meta']['force_disabled']);
unset($config['error_files']);
Helper::saveIndexedConfig($config);

$warnings = [];
Helper::cacheSnippetIndex();
check('rebuilding over a legacy config raises nothing', empty($warnings), implode(' | ', $warnings));

$config = readIndex($storage);
check('the snippet with no @priority still indexed', isset($config['published']['legacy-one.php']));
check('meta defaults were restored', $config['meta']['auto_disable'] === 'yes' && $config['meta']['force_disabled'] === 'no');

restore_error_handler();

echo "\nThe config cache is coherent with the file (M14)\n";

// The cache now holds for an empty config, which is what M14 asked for. That makes the
// staleness that was always latent reachable, so the invalidation on write is part of
// the same fix and is what this actually pins.
$before = Helper::getIndexedConfig();
$fresh = makeSnippet($storage, 'Cache Coherence');
Helper::cacheSnippetIndex();

check(
    'a read after a rebuild sees the new snippet, not the cached copy',
    isset(Helper::getIndexedConfig()['published'][$fresh]),
    'getIndexedConfig() returned a pre-rebuild copy'
);

Helper::flushIndexedConfigCache();
check(
    'and it matches what is actually on disk',
    Helper::getIndexedConfig() == readIndex($storage)
);

echo "\nescCssJs neutralises every closing tag a browser accepts (L1)\n";

// Each of these ends an inline <script>/<style> block as far as an HTML parser is
// concerned, so none may survive verbatim in the output.
$closers = [
    '</script>'   => 'the plain form',
    '</script >'  => 'a trailing space',
    "</script\n>" => 'a newline',
    '</SCRIPT>'   => 'uppercase',
    '</ script>'  => 'a space after the slash',
    '</style >'   => 'a style tag with a space',
    '</STYLE>'    => 'an uppercase style tag',
];

foreach ($closers as $tag => $label) {
    $code = 'alert(1); ' . $tag . ' more';
    $escaped = Helper::escCssJs($code);
    check("neutralised: $label", stripos($escaped, $tag) === false, 'survived: ' . $tag);
    check("  ...by escaping, not deleting: $label", strpos($escaped, '<\\/') !== false, 'got: ' . $escaped);
}

// The lossy half. An *opening* tag does not end the block — inside <script> it is
// ordinary text — so stripping it only ever corrupted valid code.
$loader = 'document.write(\'<script src="https://example.test/a.js"><\/script>\');';
check(
    'a document.write() script loader survives untouched',
    Helper::escCssJs($loader) === $loader,
    'got: ' . Helper::escCssJs($loader)
);

check(
    'an opening tag is no longer stripped',
    Helper::escCssJs('var t = "<script src=x>";') === 'var t = "<script src=x>";',
    'got: ' . Helper::escCssJs('var t = "<script src=x>";')
);

check('ordinary code is left alone', Helper::escCssJs('var a = 1 < 2;') === 'var a = 1 < 2;');
check('CSS is left alone', Helper::escCssJs('.a { color: red; }') === '.a { color: red; }');

echo "\nSaving only rejects a wrapping tag, not a mention of one (L1)\n";
check('code wrapped in <script> is rejected', is_wp_error(Helper::detectWrappingTag("<script>\nalert(1);\n</script>")));
check('code wrapped in <style> is rejected', is_wp_error(Helper::detectWrappingTag('<style>.a{color:red}</style>')));
check('leading whitespace does not hide it', is_wp_error(Helper::detectWrappingTag("\n  <script >alert(1);</script>")));
check('a document.write() loader is accepted', Helper::detectWrappingTag($loader) === false);
check('plain JS is accepted', Helper::detectWrappingTag('alert(1);') === false);
check('plain CSS is accepted', Helper::detectWrappingTag('.a { color: red; }') === false);
check('a mention of a closing tag is accepted', Helper::detectWrappingTag('var s = "<\\/script>";') === false);

echo "\nSearch is case-insensitive (L5)\n";
makeSnippet($storage, 'header script');
Helper::cacheSnippetIndex();

$found = (new Snippet(['search' => 'Header']))->getIndexedSnippets(50, 1);
$names = array_column($found['data'], 'name');
check('searching "Header" finds "header script"', in_array('header script', $names, true), 'got: ' . implode(', ', $names));

$found = (new Snippet(['search' => 'KEEPER']))->getIndexedSnippets(50, 1);
$names = array_column($found['data'], 'name');
check('searching "KEEPER" finds "Keeper"', in_array('Keeper', $names, true), 'got: ' . implode(', ', $names));

echo "\n";
if ($failures) {
    echo "$failures of $checks checks FAILED\n";
    exit(1);
}

echo "All $checks checks passed\n";
exit(0);
