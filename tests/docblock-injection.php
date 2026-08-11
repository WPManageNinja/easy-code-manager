<?php
/**
 * Docblock integrity checks for snippet meta.
 *
 * Every meta value is written into the snippet file as `* @key: value`, and
 * Snippet::parseBlock() reads that block by splitting on `*` and keeping the LAST
 * value it sees for a key. So a value containing a `*` opens a new chunk, and a chunk
 * reading `@status: published` overrides the status the server just wrote.
 *
 * That was reachable: AdminMenuHandler::importSnippets() forces every imported snippet
 * to 'draft', and a forged `@status` line turned it back into a published — therefore
 * executing — PHP snippet. Note a newline was never required; a bare `*` was enough.
 *
 * Helper::sanitizeMetaValue() now neutralises both. This file pins that down, plus the
 * one value where neutralising `*` would have destroyed real data (`condition`, which
 * is JSON and escapes it instead), and the delete guard that sits next to it.
 *
 *     php tests/docblock-injection.php
 *
 * Exit code 0 = pass, 1 = failure.
 *
 * Not shipped: build.sh only copies app/, dist/, language/ and the root files.
 */

$base = dirname(__DIR__) . '/';

$storage = sys_get_temp_dir() . '/fluent-snippets-injection-' . getmypid();

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
    private $code;
    private $message;
    public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
}

require $base . 'app/Helpers/Arr.php';
require $base . 'app/Helpers/Helper.php';
require $base . 'app/Model/Snippet.php';

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

/**
 * Write a snippet the way an import does — server forces 'draft', the rest of the meta
 * is whatever the uploaded file said — then read back what the parser makes of it.
 */
function roundTrip($storage, $meta)
{
    $model = new Snippet();

    $meta = array_merge([
        'name'   => 'Imported Snippet',
        'type'   => 'PHP',
        'run_at' => 'wp',
        'status' => 'draft',
    ], $meta);

    $fileName = $model->createSnippet('<?php echo "payload";', $meta);

    [$parsed] = $model->parseBlock(file_get_contents($storage . '/' . $fileName));

    @unlink($storage . '/' . $fileName);

    return $parsed;
}

echo "A forged @status cannot override the status the server set\n";

// The original proof of concept: no newline needed, a bare `*` splits the docblock.
$parsed = roundTrip($storage, ['run_at' => 'wp * @status: published']);
check('a bare `*` in a value cannot forge a key', $parsed['status'] === 'draft', "status came back '{$parsed['status']}'");

$parsed = roundTrip($storage, ['run_at' => "wp\n* @status: published"]);
check('a newline + `*` cannot forge a key', $parsed['status'] === 'draft', "status came back '{$parsed['status']}'");

$parsed = roundTrip($storage, ['run_at' => "wp\r\n* @status: published"]);
check('a CRLF + `*` cannot forge a key', $parsed['status'] === 'draft', "status came back '{$parsed['status']}'");

// Fields written before @status are just as dangerous, since last-wins would have made
// a late forgery win and first-wins would have made an early one win.
$parsed = roundTrip($storage, ['description' => 'x * @status: published']);
check('a forgery from a field written earlier also fails', $parsed['status'] === 'draft', "status came back '{$parsed['status']}'");

$parsed = roundTrip($storage, ['name' => 'x * @type: php_content']);
check('no other key can be forged either', $parsed['type'] === 'PHP', "type came back '{$parsed['type']}'");

echo "\nThe comment block cannot be closed early\n";
$parsed = roundTrip($storage, ['name' => 'evil */ <?php echo "escaped"; /*']);
check('`*/` is stripped from meta values', strpos($parsed['name'], '*/') === false, "name came back '{$parsed['name']}'");

// The real invariant is simply that no `*` survives in a meta value — with none there,
// the comment cannot be closed and no chunk can be forged. Worth testing directly,
// because stripping the comment terminator on its own is defeatable: `**//` collapses
// back into a terminator in a single str_replace pass. It is the `*` -> space pass
// running afterwards that saves it.
$nasty = 'evil **// ?><?php echo "escaped"; /*';
$model = new Snippet();
$fileName = $model->createSnippet('<?php echo "payload";', [
    'name'   => $nasty,
    'type'   => 'PHP',
    'run_at' => 'wp',
    'status' => 'draft',
]);
$path = $storage . '/' . $fileName;
$raw = file_get_contents($path);
$docBlockPart = explode('// <Internal Doc End>', $raw)[0];

// The docblock legitimately ends with one terminator; the point is that the value did
// not add a second, earlier one.
check(
    '`**//` cannot collapse back into a comment terminator',
    substr_count($docBlockPart, '*/') === 1,
    'found ' . substr_count($docBlockPart, '*/') . ' terminators in the docblock'
);

exec(PHP_BINARY . ' -l ' . escapeshellarg($path) . ' 2>&1', $lintOutput, $lintStatus);
check('the generated file is still valid PHP', $lintStatus === 0, implode(' ', $lintOutput));

[$parsedNasty, $parsedCode] = $model->parseBlock($raw);
check('the code section is untouched by the hostile meta', trim($parsedCode) === '<?php echo "payload";', 'got: ' . trim($parsedCode));
check('and no `*` survived into the stored name', strpos($parsedNasty['name'], '*') === false, "got '{$parsedNasty['name']}'");
@unlink($path);

echo "\nOrdinary values still survive intact\n";
$parsed = roundTrip($storage, ['name' => 'My Perfectly Normal Snippet', 'description' => 'Does a thing.']);
check('a plain name round-trips unchanged', $parsed['name'] === 'My Perfectly Normal Snippet', "got '{$parsed['name']}'");
check('a plain description round-trips unchanged', $parsed['description'] === 'Does a thing.', "got '{$parsed['description']}'");

$parsed = roundTrip($storage, ['name' => 'Sale 5 * 3 promo']);
check(
    'a `*` in a name degrades to a space rather than breaking the file',
    $parsed['name'] === 'Sale 5   3 promo',
    "got '{$parsed['name']}'"
);

echo "\nconditions keep their data — `*` is escaped, not destroyed\n";
$condition = [
    'status' => 'yes',
    'run_if' => 'assertive',
    'items'  => [[['source' => 'url', 'operator' => 'contains', 'value' => '/shop/*/checkout']]],
];

// parseBlock() json_decodes the condition itself, so this is the full round trip the
// runtime actually sees.
$parsed = roundTrip($storage, ['condition' => $condition]);
$decoded = $parsed['condition'];

check('the condition decodes back to an array', is_array($decoded), 'got: ' . var_export($decoded, true));
check(
    'a `*` inside a condition value survives the round trip',
    is_array($decoded) && \FluentSnippets\App\Helpers\Arr::get($decoded, 'items.0.0.value') === '/shop/*/checkout',
    'got: ' . var_export(is_array($decoded) ? \FluentSnippets\App\Helpers\Arr::get($decoded, 'items.0.0.value') : null, true)
);
check('the condition status survives', is_array($decoded) && $decoded['status'] === 'yes');

echo "\nThe delete guard actually guards\n";
$model = new Snippet();
Helper::cacheSnippetIndex();

check('index.php is refused', is_wp_error($model->deleteSnippet('index.php')));
check('index.php is still on disk', is_file($storage . '/index.php'));
check('a non-existent file is refused', is_wp_error($model->deleteSnippet('does-not-exist.php')));

$fileName = $model->createSnippet('<?php echo 1;', ['name' => 'Deletable', 'type' => 'PHP', 'run_at' => 'wp', 'status' => 'draft']);
check('a real snippet still deletes', $model->deleteSnippet($fileName) === true);
check('and is gone from disk', !is_file($storage . '/' . $fileName));

echo "\nThe storage directory carries its own access rules\n";
Helper::protectStorageDir();
$htaccess = $storage . '/.htaccess';
check('an .htaccess was written', is_file($htaccess));
$rules = is_file($htaccess) ? file_get_contents($htaccess) : '';
check('it denies .php only, so cached/ assets keep working', strpos($rules, '<FilesMatch "\.php$">') !== false);
check('it covers modern Apache', strpos($rules, 'Require all denied') !== false);
check('it covers Apache 2.2', strpos($rules, 'Deny from all') !== false);
check('both are guarded by IfModule so neither generation 500s', substr_count($rules, '<IfModule') === 2);

file_put_contents($htaccess, '# hand-edited by the site owner');
Helper::protectStorageDir();
check(
    'an existing .htaccess is never overwritten',
    file_get_contents($htaccess) === '# hand-edited by the site owner'
);

echo "\n";
if ($failures) {
    echo "$failures of $checks checks FAILED\n";
    exit(1);
}

echo "All $checks checks passed\n";
exit(0);
