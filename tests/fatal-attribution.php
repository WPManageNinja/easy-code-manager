<?php
/**
 * Behaviour check for the fatal-error auto-disable (H2).
 *
 * "Automatically Disable Script on fatal error" ships on by default, but the handler
 * used to match `$error['type'] === 1` (E_ERROR alone) and to identify the snippet by
 * `dirname($error['file'])`. That missed two whole classes of real failure:
 *
 *   - a truncated or hand-edited snippet produces E_PARSE / E_COMPILE_ERROR, never
 *     E_ERROR, so it was never quarantined and the site stayed broken on every request;
 *   - a snippet calling a WordPress or third-party function that then fatals reports
 *     that function's file, so the snippet was never blamed.
 *
 * The fix widens the type list and tracks the executing snippet on a stack in
 * CodeRunner. The load-bearing assumption is that **a fatal error does not unwind the
 * stack**, so the `finally` that pops never runs and the snippet is still on the list
 * at shutdown — while an exception *does* unwind, so the pop runs and the stack cannot
 * be left dirty. Neither half can be tested in-process, because a fatal ends the
 * process. The scenarios below therefore run in child processes that really do fatal,
 * and the parent asserts on what ended up quarantined in index.php.
 *
 *     php tests/fatal-attribution.php
 *
 * Exit code 0 = pass, 1 = failure.
 *
 * Not shipped: build.sh only copies app/, dist/, language/ and the root files.
 */

$base = dirname(__DIR__) . '/';

// ---------------------------------------------------------------------------
// Child modes:
//   --build-index <storage>            build index.php the way the plugin does
//   --scenario <name> <storage>        run one failing request to completion
// ---------------------------------------------------------------------------
if (isset($argv[1]) && ($argv[1] === '--scenario' || $argv[1] === '--build-index')) {

    $storage = ($argv[1] === '--build-index') ? $argv[2] : $argv[3];

    define('ABSPATH', $base);
    define('WP_CONTENT_DIR', $storage);
    define('FLUENT_SNIPPETS_STORAGE_DIR', $storage);
    define('FLUENT_SNIPPETS_PLUGIN_VERSION', '10.55');

    // Minimal WordPress surface.
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
    function current_user_can($cap) { return false; }
    function is_admin() { return false; }
    function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
    function wp_kses_post($text) { return $text; }
    function __($text, $domain = '') { return $text; }
    function add_shortcode() {}
    function add_filter() {}

    /*
     * add_action() has to be real for 'shutdown': the mechanism under test only fires
     * as the process dies. Every other hook is discarded.
     */
    function add_action($hook, $callback, $priority = 10, $args = 1)
    {
        if ($hook === 'shutdown') {
            register_shutdown_function($callback);
        }
    }

    class WP_Error
    {
        public $message;
        public function __construct($code = '', $message = '') { $this->message = $message; }
    }

    require $base . 'app/Helpers/Arr.php';
    require $base . 'app/Helpers/Helper.php';
    require $base . 'app/Model/Snippet.php';

    if ($argv[1] === '--build-index') {
        \FluentSnippets\App\Helpers\Helper::cacheSnippetIndex();
        exit(0);
    }

    require $base . 'app/Services/FluentSnippetCondition.php';
    require $base . 'app/Services/CodeRunner.php';
    require $base . 'app/Hooks/Handlers/CodeHandler.php';

    (new \FluentSnippets\App\Hooks\Handlers\CodeHandler())->register();

    /**
     * Stands in for any WordPress or third-party function a snippet might call. The
     * point is that the fatal is raised in *this* file, not in the snippet file, so
     * $error['file'] alone cannot identify the snippet.
     */
    function some_wordpress_function()
    {
        undefined_function_from_core_xyz();
    }

    switch ($argv[2]) {
        case 'fatal-downstream':
            // The headline case: a snippet is running, the fatal surfaces elsewhere.
            \FluentSnippets\App\Services\CodeRunner::pushRunningSnippet('hello-world.php');
            some_wordpress_function();
            break;

        case 'fatal-in-snippet':
            // Parse error inside the snippet file itself — E_COMPILE_ERROR, not E_ERROR.
            require_once $storage . '/broken-one.php';
            break;

        case 'exception-downstream':
            // An exception unwinds, so runSnippetFile's finally pops and the stack is
            // clean. A later unrelated fatal must not be pinned on that snippet.
            try {
                \FluentSnippets\App\Services\CodeRunner::pushRunningSnippet('hello-world.php');
                try {
                    throw new \RuntimeException('thrown from a snippet');
                } finally {
                    \FluentSnippets\App\Services\CodeRunner::popRunningSnippet();
                }
            } catch (\RuntimeException $e) {
                // Swallowed, as an upstream error-monitoring plugin would.
            }
            some_wordpress_function();
            break;

        case 'warning-only':
            // A request that finished fine but left a warning as the last error.
            trigger_error('just a warning', E_USER_WARNING);
            break;

        case 'fatal-untracked':
            // A fatal with no snippet running, nowhere near the storage directory.
            some_wordpress_function();
            break;

        case 'fatal-unknown-snippet':
            // A tracked name the index does not know — must not be written.
            \FluentSnippets\App\Services\CodeRunner::pushRunningSnippet('not-a-real-snippet.php');
            some_wordpress_function();
            break;
    }

    exit(0);
}

// ---------------------------------------------------------------------------
// Parent
// ---------------------------------------------------------------------------

// Every generated index.php opens with `if (!defined("ABSPATH")) { return; }`, so
// without this the parent's own reads come back empty and every assertion below
// passes vacuously.
define('ABSPATH', $base);

require $base . 'app/Helpers/Arr.php';
require $base . 'app/Helpers/Helper.php';
require $base . 'app/Services/CodeRunner.php';
require $base . 'app/Hooks/Handlers/CodeHandler.php';

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
 * A fresh storage directory holding two published snippets — one healthy, one with a
 * deliberate parse error in its code section. The docblock still parses, so it is
 * indexed normally, which is exactly how a truncated file behaves.
 */
function makeStorage($label)
{
    $storage = sys_get_temp_dir() . '/fluent-snippets-fatal-' . getmypid() . '-' . $label;

    if (!is_dir($storage)) {
        mkdir($storage, 0777, true);
    }

    writeSnippet($storage, 'hello-world.php', 'Hello World');
    writeSnippet($storage, 'broken-one.php', 'Broken One', 'this is not ( valid php');

    exec(PHP_BINARY . ' ' . escapeshellarg(__FILE__) . ' --build-index '
        . escapeshellarg($storage) . ' 2>&1');

    return $storage;
}

function cleanup($storage)
{
    foreach (glob($storage . '/*') as $file) {
        is_dir($file) ? @rmdir($file) : @unlink($file);
    }
    @rmdir($storage);
}

/**
 * Run one scenario to its (fatal) conclusion in a child process and return whatever it
 * left in error_files.
 */
function runScenario($label)
{
    $storage = makeStorage($label);

    $indexed = is_file($storage . '/index.php') ? include $storage . '/index.php' : [];
    if (empty($indexed['published']['hello-world.php'])) {
        echo "  FAIL  fixture for '$label' was not indexed — the rest of this block is meaningless\n";
        global $failures;
        $failures++;
    }

    exec(PHP_BINARY . ' ' . escapeshellarg(__FILE__) . ' --scenario '
        . escapeshellarg($label) . ' ' . escapeshellarg($storage) . ' 2>&1');

    clearstatcache();
    $config = is_file($storage . '/index.php') ? include $storage . '/index.php' : [];
    $errorFiles = isset($config['error_files']) ? $config['error_files'] : [];

    cleanup($storage);

    return $errorFiles;
}

echo "Fatal inside a function the snippet called (the case that was missed entirely)\n";
$errors = runScenario('fatal-downstream');
check('the running snippet was quarantined', isset($errors['hello-world.php']));
check('nothing else was quarantined', count($errors) === 1, 'got: ' . implode(', ', array_keys($errors)));
check(
    'the message says the fault was downstream',
    isset($errors['hello-world.php']) && strpos($errors['hello-world.php'], 'while this snippet was running') !== false,
    isset($errors['hello-world.php']) ? $errors['hello-world.php'] : '(nothing recorded)'
);
check(
    'the absolute server path was trimmed out of the message',
    isset($errors['hello-world.php']) && strpos($errors['hello-world.php'], $base) === false
);

echo "\nParse error inside a snippet file (E_COMPILE_ERROR, not E_ERROR)\n";
$errors = runScenario('fatal-in-snippet');
check('the broken snippet was quarantined', isset($errors['broken-one.php']));
check('the healthy snippet was left alone', !isset($errors['hello-world.php']));

echo "\nAn exception that something upstream caught\n";
$errors = runScenario('exception-downstream');
check(
    'the snippet is not blamed for a later, unrelated fatal',
    !isset($errors['hello-world.php']),
    'the running-snippet stack was left dirty by a throw'
);

echo "\nA request whose last error was only a warning\n";
$errors = runScenario('warning-only');
check('nothing was quarantined', empty($errors), 'got: ' . implode(', ', array_keys($errors)));

echo "\nA fatal with no snippet running\n";
$errors = runScenario('fatal-untracked');
check('nothing was quarantined', empty($errors), 'got: ' . implode(', ', array_keys($errors)));

echo "\nA tracked name the index does not know\n";
$errors = runScenario('fatal-unknown-snippet');
check('nothing was quarantined', empty($errors), 'got: ' . implode(', ', array_keys($errors)));

echo "\nThe running-snippet stack itself\n";
check('an empty stack reports nothing running', \FluentSnippets\App\Services\CodeRunner::getRunningSnippet() === '');
\FluentSnippets\App\Services\CodeRunner::pushRunningSnippet('outer.php');
check('reports the running snippet', \FluentSnippets\App\Services\CodeRunner::getRunningSnippet() === 'outer.php');
\FluentSnippets\App\Services\CodeRunner::pushRunningSnippet('inner.php');
check('a nested snippet takes precedence', \FluentSnippets\App\Services\CodeRunner::getRunningSnippet() === 'inner.php');
\FluentSnippets\App\Services\CodeRunner::popRunningSnippet();
check('popping the inner one restores the outer', \FluentSnippets\App\Services\CodeRunner::getRunningSnippet() === 'outer.php');
\FluentSnippets\App\Services\CodeRunner::popRunningSnippet();
check('popping the last one empties the stack', \FluentSnippets\App\Services\CodeRunner::getRunningSnippet() === '');
\FluentSnippets\App\Services\CodeRunner::popRunningSnippet();
check('popping an empty stack is harmless', \FluentSnippets\App\Services\CodeRunner::getRunningSnippet() === '');

echo "\nFatal error type list\n";
$types = \FluentSnippets\App\Hooks\Handlers\CodeHandler::FATAL_ERROR_TYPES;

$fatal = [
    'E_ERROR'         => E_ERROR,
    'E_PARSE'         => E_PARSE,
    'E_CORE_ERROR'    => E_CORE_ERROR,
    'E_COMPILE_ERROR' => E_COMPILE_ERROR,
    'E_USER_ERROR'    => E_USER_ERROR,
];

foreach ($fatal as $name => $type) {
    check("$name is treated as fatal", in_array($type, $types, true));
}

// E_RECOVERABLE_ERROR is excluded on purpose: a custom error handler can recover from
// one, and quarantining a snippet over a handled error would disable working code.
$notFatal = [
    'E_WARNING'           => E_WARNING,
    'E_NOTICE'            => E_NOTICE,
    'E_DEPRECATED'        => E_DEPRECATED,
    'E_USER_WARNING'      => E_USER_WARNING,
    'E_RECOVERABLE_ERROR' => E_RECOVERABLE_ERROR,
];

foreach ($notFatal as $name => $type) {
    check("$name is not treated as fatal", !in_array($type, $types, true));
}

echo "\n";
if ($failures) {
    echo "$failures of $checks checks FAILED\n";
    exit(1);
}

echo "All $checks checks passed\n";
exit(0);
