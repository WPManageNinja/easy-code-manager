<?php
/**
 * Pins the explanations attached to a refused save.
 *
 * Every failure now carries `error_details` with a title, the reason the save was
 * refused, and the fix — the editor renders that panel and nothing else, so if the
 * wiring between PhpValidator, SnippetErrors and Helper breaks, the user is back to a
 * bare sentence from PHP. These checks cover the paths a user actually hits:
 *
 *   - a name that is already declared elsewhere (the most common refusal)
 *   - calling something a not-yet-loaded plugin provides
 *   - printing at load time, and returning at the top level, which are now separate
 *   - a syntax error, including the off-by-one from our own prepended `<?php` line
 *   - a leading `<?php`, and a wrapping <style> tag on the *create* path, which used
 *     to be checked only when editing
 *   - a missing required field
 *   - an unwritable storage directory, which used to report success and save nothing
 *
 *     php tests/save-errors.php
 *
 * Exit code 0 = pass, 1 = failure.
 *
 * Not shipped: build.sh only copies app/, dist/, language/ and the root files.
 */

$base = dirname(__DIR__) . '/';

$storage = sys_get_temp_dir() . '/fluent-snippets-save-errors-' . getmypid();

if (!is_dir($storage)) {
    mkdir($storage, 0777, true);
}

register_shutdown_function(function () use ($storage) {
    @chmod($storage, 0777);
    foreach (glob($storage . '/*') as $file) {
        @chmod($file, 0666);
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
// Snippets are test-run for real by the validator, so the hook a valid one registers
// against has to exist here too.
function add_action() {}

class WP_Error
{
    private $code;
    private $message;
    private $data;

    public function __construct($code = '', $message = '', $data = [])
    {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }

    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}

require $base . 'app/Helpers/Arr.php';
require $base . 'app/Helpers/Helper.php';
require $base . 'app/Model/Snippet.php';
require $base . 'app/Services/PhpValidator.php';
require $base . 'app/Services/SnippetErrors.php';

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
 * Run a create through the real entry point and hand back its error details.
 */
function detailsForCreate($code, $meta = [])
{
    $result = Helper::createSnippet([
        'code' => $code,
        'meta' => array_merge([
            'name'   => 'Save Error Probe ' . substr(md5($code . serialize($meta)), 0, 6),
            'type'   => 'PHP',
            'run_at' => 'wp',
            'status' => 'published',
        ], $meta)
    ]);

    if (!is_wp_error($result)) {
        return null;
    }

    $data = $result->get_error_data();

    return isset($data['error_details']) ? $data['error_details'] : false;
}

function contains($haystack, $needle)
{
    return is_string($haystack) && strpos($haystack, $needle) !== false;
}

echo "\nEvery refusal carries a usable explanation\n";

// 1. A name PHP already knows about. `str_replace` is an internal function, so this is
//    the same collision a user hits when another snippet got there first.
$duplicate = detailsForCreate("function str_replace() {\n    return 1;\n}");
check('duplicate name is refused', is_array($duplicate));
check('duplicate names the identifier', contains(Arr_get($duplicate, 'title'), 'str_replace'), json_encode(Arr_get($duplicate, 'title')));
check('duplicate explains where the conflict comes from', contains(Arr_get($duplicate, 'reason'), 'functions.php'));
check('duplicate offers the function_exists guard', contains(Arr_get($duplicate, 'example'), "function_exists('str_replace')"), json_encode(Arr_get($duplicate, 'example')));

// 2. A guarded declaration of the same name has to survive — the guard is the fix we
//    hand out above, so it must actually work.
$guarded = detailsForCreate("if (!function_exists('str_replace')) {\n    function str_replace() {}\n}");
check('the suggested function_exists guard is accepted', $guarded === null, 'guarded code was still refused');

// 3. Something a later-loading plugin provides.
$undefined = detailsForCreate("fluent_snippets_probe_missing_fn();");
check('undefined call is refused', is_array($undefined));
check('undefined call blames load order, not the user', contains(Arr_get($undefined, 'reason'), 'loads after'), json_encode(Arr_get($undefined, 'reason')));
check('undefined call suggests a hook', contains(Arr_get($undefined, 'example'), "add_action('init'"), json_encode(Arr_get($undefined, 'example')));

// 4. Printing at load time, with the output handed back so the user can see what it was.
$printed = detailsForCreate("echo 'hello from the snippet';");
check('printing at load time is refused', is_array($printed));
check('printing suggests PHP Content', contains(Arr_get($printed, 'fix'), 'PHP Content'), json_encode(Arr_get($printed, 'fix')));
check('printing shows what was printed', contains(Arr_get($printed, 'output'), 'hello from the snippet'), json_encode(Arr_get($printed, 'output')));

// 5. A top-level return used to be reported as an echo statement.
$returned = detailsForCreate("return 'nope';");
check('top-level return is refused', is_array($returned));
check('top-level return is not called an echo', !contains(Arr_get($returned, 'title'), 'print'), json_encode(Arr_get($returned, 'title')));
check('top-level return says the rest is dead code', contains(Arr_get($returned, 'reason'), 'never runs'), json_encode(Arr_get($returned, 'reason')));

// 6. Syntax error. The validator sees our prepended `<?php` line, so the number it
//    reports is one further down than what the user is looking at.
$syntax = detailsForCreate("\$a = 1;\n\$b = 2;\n\$c = ;");
check('syntax error is refused', is_array($syntax));
// Most syntax mistakes reach eval() rather than the token walk, so they arrive as a
// ParseError. They must still be described as a syntax error and not as a snippet that
// should be moved onto a hook.
check('syntax error is called a syntax error', contains(Arr_get($syntax, 'title'), 'syntax error'), json_encode(Arr_get($syntax, 'title')));
check('syntax error advises checking the braces', contains(Arr_get($syntax, 'fix'), 'closing brace'), json_encode(Arr_get($syntax, 'fix')));
check('syntax error points at the user line, not ours', Arr_get($syntax, 'line') === 3, 'line reported: ' . json_encode(Arr_get($syntax, 'line')));
check('syntax error message carries the line', contains(Arr_get($syntax, 'message'), 'line 3'), json_encode(Arr_get($syntax, 'message')));

// 7. Leading tag.
$leading = detailsForCreate("<?php\necho 1;");
check('leading <?php is refused', is_array($leading));
check('leading <?php says why the tag is unwanted', contains(Arr_get($leading, 'reason'), 'writes the opening PHP tag'), json_encode(Arr_get($leading, 'reason')));

// 8. A wrapping <style> tag. Only the update path used to check this, so a CSS snippet
//    pasted with its own tag was accepted on first save and rendered as text on the site.
$wrapped = detailsForCreate("<style>\n.a { color: red; }\n</style>", ['type' => 'css']);
check('wrapping <style> is refused when creating', is_array($wrapped));
check('wrapping <style> names the tag to delete', contains(Arr_get($wrapped, 'fix'), '</style>'), json_encode(Arr_get($wrapped, 'fix')));

// 9. A required field.
$missing = Helper::createSnippet([
    'code' => 'echo_nothing();',
    'meta' => ['name' => '', 'type' => 'PHP', 'run_at' => 'wp', 'status' => 'published']
]);
check('empty name is refused', is_wp_error($missing));
$missingData = is_wp_error($missing) ? $missing->get_error_data() : [];
check('empty name still flags the field for the form', isset($missingData['name']), json_encode(array_keys((array)$missingData)));
check('empty name explains itself', contains(Arr_get(Arr_get($missingData, 'error_details', []), 'title'), 'Snippet Name'), json_encode($missingData));

echo "\nA save that cannot reach the disk says so\n";

// A valid snippet, saved once so there is a file to update.
$fileName = Helper::createSnippet([
    'code' => "add_action('init', function () {});",
    'meta' => ['name' => 'Writable Probe', 'type' => 'PHP', 'run_at' => 'wp', 'status' => 'published']
]);

check('baseline snippet saves', !is_wp_error($fileName), is_wp_error($fileName) ? $fileName->get_error_message() : '');

if (!is_wp_error($fileName)) {
    $path = $storage . '/' . $fileName;

    // Both have to be locked: an existing file stays writable through a read-only
    // directory, which is why atomicPut()'s fallback would otherwise still succeed.
    @chmod($path, 0444);
    @chmod($storage, 0555);

    $stillWritable = @file_put_contents($path, 'probe') !== false;

    if ($stillWritable) {
        // Running as root, or on a filesystem that ignores the mode. Nothing to assert.
        echo "  skip  unwritable storage (permissions not enforced for this user)\n";
    } else {
        $blocked = Helper::updateSnippet([
            'code'       => "add_action('init', function () { \$x = 2; });",
            'file_name'  => $fileName,
            'reactivate' => '',
            'meta'       => ['name' => 'Writable Probe', 'type' => 'PHP', 'run_at' => 'wp', 'status' => 'published']
        ]);

        check('an unwritable directory is reported instead of faked', is_wp_error($blocked), 'update claimed success');

        if (is_wp_error($blocked)) {
            $details = Arr_get($blocked->get_error_data(), 'error_details', []);
            check('write failure names the directory', contains(Arr_get($details, 'reason'), $storage), json_encode(Arr_get($details, 'reason')));
            check('write failure says the old version is still live', contains(Arr_get($details, 'reason'), 'still live'));
            check('write failure talks about permissions', contains(Arr_get($details, 'fix'), 'writable'));
        }
    }

    @chmod($storage, 0777);
    @chmod($path, 0644);
}

echo "\n$checks checks, $failures failed\n";

exit($failures ? 1 : 0);

/**
 * Local reader so the assertions above stay readable when a lookup misses.
 */
function Arr_get($array, $key, $default = null)
{
    if (!is_array($array)) {
        return $default;
    }

    return array_key_exists($key, $array) ? $array[$key] : $default;
}
