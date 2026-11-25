<?php
// A site switch can happen after hooks have been registered. Exercise the callback, not just its registration.
if (PHP_SAPI !== 'cli') {
    exit;
}

use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Hooks\Handlers\CodeHandler;
use FluentSnippets\App\Model\Snippet;
use FluentSnippets\App\Services\CodeRunner;

$root = dirname(__DIR__);
$storage = sys_get_temp_dir() . '/fluent-network-sites-' . bin2hex(random_bytes(8));
mkdir($storage, 0700);
define('ABSPATH', $root . '/');
define('WP_CONTENT_DIR', $storage);
define('FLUENT_SNIPPETS_STORAGE_DIR', $storage);
define('FLUENT_SNIPPETS_STORAGE_URL', 'https://example.test/library');
define('FLUENT_SNIPPETS_PLUGIN_VERSION', 'test');
register_shutdown_function(function () use ($storage) {
    if (is_dir($storage . '/cached')) {
        foreach (array_merge(glob($storage . '/cached/*'), glob($storage . '/cached/.fluent-*')) as $file) {
            unlink($file);
        }
        rmdir($storage . '/cached');
    }
    foreach (array_merge(glob($storage . '/*'), glob($storage . '/.htaccess'), glob($storage . '/.fluent-*')) as $file) {
        unlink($file);
    }
    rmdir($storage);
});

function is_multisite() { return $GLOBALS['multisite']; }
function wp_is_file_mod_allowed($context) { return $GLOBALS['canPrepare'] ?? true; }
function is_super_admin() { return $GLOBALS['superAdmin']; }
function get_current_blog_id() { return $GLOBALS['blog']; }
function get_current_network_id() { return $GLOBALS['network']; }
function get_site() { return (object) ['network_id' => get_current_network_id()]; }
function untrailingslashit($value) { return rtrim($value, '/'); }
function get_current_user_id() { return 1; }
function current_time($format) { return '2026-01-01 12:00:00'; }
function sanitize_title($name) { return strtolower(str_replace(' ', '-', $name)); }
function sanitize_file_name($name) { return basename($name); }
function wp_parse_args($value, $defaults) { return array_merge($defaults, $value); }
function site_url() { return 'https://example.test'; }
function wp_mkdir_p($dir) { return is_dir($dir) || mkdir($dir, 0700, true); }
function apply_filters($tag, $value) { return $value; }
function add_action($tag, $callback) { $GLOBALS['hooks'][$tag][] = $callback; }
function add_filter($tag, $callback) { add_action($tag, $callback); }
function do_action() {}
function wp_enqueue_style($handle, $url) { $GLOBALS['enqueued'][] = $url; }
function wp_enqueue_script($handle, $url) { $GLOBALS['enqueued'][] = $url; }
class WP_Error {}
function is_wp_error($value) { return $value instanceof WP_Error; }
function is_admin() { return false; }
function current_user_can($capability) { return $GLOBALS['superAdmin']; }
function wp_kses_post($text) { return $text; }
function shortcode_atts($defaults, $args) { return array_merge($defaults, $args); }
function __($text) { return $text; }

foreach (['Helpers/Arr', 'Helpers/Helper', 'Services/SnippetRevision', 'Model/Snippet', 'Services/FluentSnippetCondition', 'Services/CodeRunner', 'Hooks/Handlers/CodeHandler'] as $file) {
    require $root . '/app/' . $file . '.php';
}
require $root . '/app/Services/mu.stub';

function expectNetworkResult($matches, $message) {
    if (!$matches) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

$blog = 1;
$network = 1;
$multisite = true;
$superAdmin = true;
$model = new Snippet();
$only = ['mode' => 'only', 'network' => 1, 'sites' => [2]];
$except = ['mode' => 'except', 'network' => 1, 'sites' => [2]];
$meta = ['name' => 'Network marker', 'type' => 'php_content', 'run_at' => 'wp_footer', 'status' => 'published'];
$file = $model->createSnippet('network-marker', $meta + ['network_sites' => $only]);
Helper::cacheSnippetIndex();
$case = $argv[1] ?? 'all';
if ($case === 'storage' || $case === 'all') {
    $stored = $model->findByFileName($file);
    expectNetworkResult(($stored['meta']['network_sites'] ?? null) === $only, 'site scope survives saving and reading the file');
    $index = Helper::getIndexedConfig();
    expectNetworkResult(($index['published'][$file]['network_sites'] ?? null) === $only, 'site scope survives rebuilding the published index');
    $model->updateSnippet($file, 'updated marker', $meta);
    expectNetworkResult($model->findByFileName($file)['meta']['network_sites'] === $only, 'older clients preserve site targeting');
    $model->updateSnippet($file, 'updated marker', $meta + ['network_sites' => null]);
    expectNetworkResult(!isset($model->findByFileName($file)['meta']['network_sites']), 'explicit all-sites selection clears the restriction');
    $model->updateSnippet($file, '0', $meta + ['network_sites' => $only]);
    $model->updateSnippet($file, 'updated marker', $meta);
    expectNetworkResult($model->findByFileName($file)['meta']['network_sites'] === $only, 'a zero-valued body preserves its saved selection');
    expectNetworkResult(is_wp_error(Helper::validateCode('PHP', '<?php /* validation only */', $only)), 'PHP validation requires an included site');
    $superAdmin = false;
    expectNetworkResult(is_wp_error(Helper::validateMeta($meta + ['network_sites' => $except])), 'site administrators cannot change targeting');
    $superAdmin = true;
    foreach ([[], ['mode'=>'except','network'=>1,'sites'=>[]], ['mode'=>'except','network'=>1,'sites'=>[true]]] as $invalid) {
        expectNetworkResult(is_wp_error(Helper::validateMeta($meta + ['network_sites' => $invalid])), 'invalid selections are refused before writing');
        expectNetworkResult(!\FluentSnippets\App\Services\FluentSnippetCondition::matchesSiteScope($invalid), 'invalid deployed restrictions never match');
    }
    echo "ok - storage\n";
}

if ($case === 'targeting' || $case === 'all') {
    $meta['type'] = 'js';
    foreach ([CodeRunner::class, \FluentSnippets\Mu\CodeRunner::class] as $class) {
        foreach ([$only, $except] as $scope) {
            $model->updateSnippet($file, 'network-marker', $meta + ['network_sites' => $scope]);
            Helper::cacheSnippetIndex();
            $hooks = [];
            (new $class())->runSnippets();
            foreach ([[1, 1, true], [2, 1, true], [3, 2, true], [1, 1, false]] as [$blog, $network, $multisite]) {
                ob_start();
                foreach ($hooks['wp_footer'] ?? [] as $callback) {
                    $callback();
                }
                $shown = strpos(ob_get_clean(), 'network-marker') !== false;
                $expected = $multisite && $network === 1 && (($scope['mode'] === 'only') === ($blog === 2));
                expectNetworkResult($shown === $expected, "targeting {$scope['mode']} at site $blog in $class");
            }
            $multisite = true;
            $network = 1;
            $blog = 1;
        }
    }
    echo "ok - targeting\n";
}

if ($case === 'surfaces' || $case === 'all') {
    $meta['type'] = 'php_content';
    $meta['run_at'] = 'shortcode';
    $model->updateSnippet($file, 'network-marker', $meta + ['network_sites' => $only]);
    Helper::cacheSnippetIndex();
    foreach ([1, 2] as $blog) {
        $html = (new CodeHandler())->handleShortcode(['id' => basename($file, '.php')]);
        expectNetworkResult((strpos($html, 'network-marker') !== false) === ($blog === 2), 'shortcode follows the selected site');
    }
    $meta['type'] = 'css';
    $meta['run_at'] = 'wp_head';
    $meta['load_in_block_editor'] = 'yes';
    $model->updateSnippet($file, '.network-marker {}', $meta + ['network_sites' => $only]);
    Helper::cacheSnippetIndex();
    foreach ([CodeRunner::class, \FluentSnippets\Mu\CodeRunner::class] as $class) {
        $hooks = [];
        (new $class())->runSnippets();
        foreach ([1, 2] as $blog) {
            $settings = ['styles' => []];
            foreach ($hooks['block_editor_settings_all'] ?? [] as $callback) {
                $settings = $callback($settings);
            }
            expectNetworkResult(count($settings['styles']) === ($blog === 2 ? 1 : 0), 'block editor follows selected sites');
        }
    }
    echo "ok - surfaces\n";
}

if ($case === 'publication' || $case === 'all') {
    $meta['type'] = 'js';
    $meta['run_at'] = 'wp_footer';
    $model->updateSnippet($file, '/* first revision */', $meta + ['network_sites' => $only]);
    Helper::cacheSnippetIndex();
    $first = Helper::getIndexedConfig()['published'][$file];
    $path = $storage . '/' . $file;
    $copy = \FluentSnippets\App\Services\SnippetRevision::path($path, $first['revision']);
    chmod($path, 0600);
    Helper::cacheSnippetIndex();
    clearstatcache(true, $copy);
    expectNetworkResult((fileperms($copy) & 0777) === 0600, 'published source is private after republication');
    chmod($path, 0640);
    Helper::cacheSnippetIndex();
    clearstatcache(true, $copy);
    expectNetworkResult((fileperms($copy) & 0777) === 0640 && filegroup($copy) === filegroup($path), 'published revision preserves shared-group read access');
    $model->updateSnippet($file, '/* next revision */', $meta + ['network_sites' => $except]);
    Helper::cacheSnippetIndex();
    $next = Helper::getIndexedConfig()['published'][$file];
    expectNetworkResult($first['revision'] !== $next['revision'], 'changed publications have distinct identities');
    foreach ([\FluentSnippets\App\Services\SnippetRevision::class, \FluentSnippets\Mu\SnippetRevision::class] as $revision) {
        $previousFile = $revision::resolve($path, $first);
        $currentFile = $revision::resolve($path, $next);
        expectNetworkResult($revision::parse(file_get_contents($previousFile), true) === '/* first revision */', 'an in-flight request retains its original body');
        expectNetworkResult($revision::parse(file_get_contents($currentFile), true) === '/* next revision */', 'new requests use the new revision');
        $legacy = ['file_name' => $file, 'type' => 'js', 'run_at' => 'wp_footer'];
        file_put_contents($path, str_replace('@network_sites:', '@network_sites :', file_get_contents($path)));
        $native = $revision::resolve($path, $legacy, $body);
        expectNetworkResult($legacy['network_sites'] === $except && $native === $path, 'legacy selection preserves the native source directory');
        expectNetworkResult($body === '/* next revision */', 'legacy asset uses the bytes parsed with its site selection');
    }
    $meta['type'] = 'css';
    $meta['load_as_file'] = 'yes';
    $model->updateSnippet($file, '/* first sheet */', $meta + ['network_sites' => $only]);
    Helper::cacheSnippetIndex();
    $original = Helper::getIndexedConfig()['published'][$file]['revision'];
    $oldAsset = $storage . '/cached/rev-' . $original . '.css';
    touch($oldAsset, time() - 15 * 86400);
    $model->updateSnippet($file, '/* next sheet */', $meta + ['network_sites' => $except]);
    $newHash = hash('sha256', $file . "\0" . file_get_contents($path));
    $newAsset = $storage . '/cached/rev-' . $newHash . '.css';
    mkdir($newAsset);
    expectNetworkResult(Helper::cacheSnippetIndex() === false, 'asset publication errors are reported');
    expectNetworkResult(Helper::getIndexedConfig(false)['published'][$file]['revision'] === $original, 'failed asset publication retains previous index');
    rmdir($newAsset);
    expectNetworkResult(Helper::cacheSnippetIndex() !== false && file_get_contents($newAsset) === '/* next sheet */', 'retry publishes the new immutable asset');
    expectNetworkResult(file_get_contents($oldAsset) === '/* first sheet */', 'old pages retain their asset after publication');
    $meta['type'] = 'PHP';
    $model->updateSnippet($file, '<?php return [__FILE__, __DIR__];', $meta + ['network_sites' => $only]);
    Helper::cacheSnippetIndex();
    $record = Helper::getIndexedConfig()['published'][$file];
    $executable = \FluentSnippets\App\Services\SnippetRevision::resolve($path, $record);
    expectNetworkResult((include $executable) === [realpath($path), realpath(dirname($path))], 'PHP resolves magic paths to the authoring file');
    rename($storage . '/index.php', $storage . '/saved-index');
    mkdir($storage . '/index.php');
    expectNetworkResult(is_wp_error(Helper::publishSnippetWrite($file, 'fluent_snippets/snippet_updated')), 'a failed index publication returns an error');
    rmdir($storage . '/index.php');
    rename($storage . '/saved-index', $storage . '/index.php');
    unlink($path);
    foreach ([\FluentSnippets\App\Services\SnippetRevision::class, \FluentSnippets\Mu\SnippetRevision::class] as $reader) {
        expectNetworkResult($reader::resolve($path, $record) === false, 'deleted authoring files stop on an older index');
    }
    echo "ok - publication\n";
}

if ($case === 'compatibility' || $case === 'all') {
    file_put_contents($storage . '/dependency.inc', '<?php return "sibling resolved";');
    foreach ([CodeRunner::class, \FluentSnippets\Mu\CodeRunner::class] as $runner) {
        $native = $model->createSnippet('<?php echo require "dependency.inc";', [
            'name' => 'Native source', 'type' => 'php_content', 'run_at' => 'wp_footer', 'status' => 'published'
        ]);
        $external = [];
        foreach (['css' => 'wp_head', 'js' => 'wp_footer'] as $type => $hook) {
            $asset = $model->createSnippet('/* external */', [
                'name' => 'External ' . $type, 'type' => $type, 'run_at' => $hook, 'load_as_file' => 'yes', 'status' => 'published'
            ]);
            $cache = basename($asset, '.php') . '.' . $type;
            file_put_contents(Helper::getCachedDir() . '/' . $cache, '/* external */');
            $external[$asset] = FLUENT_SNIPPETS_STORAGE_URL . '/cached/' . $cache;
        }
        Helper::cacheSnippetIndex();
        $config = Helper::getIndexedConfig(false);
        $config['published'] = array_intersect_key($config['published'], [$native => true] + $external);
        foreach ($config['published'] as &$published) {
            unset($published['revision']);
        }
        unset($published);
        Helper::saveIndexedConfig($config);
        $hooks = $enqueued = [];
        chmod($storage, 0555);
        try {
            (new $runner())->runSnippets();
            ob_start();
            foreach ($hooks['wp_footer'] ?? [] as $callback) $callback();
            $rendered = ob_get_clean();
            foreach ($hooks['wp_enqueue_scripts'] ?? [] as $callback) $callback();
        } finally {
            chmod($storage, 0700);
        }
        expectNetworkResult($rendered === '', 'unprepared PHP pauses in a read-only library');
        expectNetworkResult(count($enqueued) === 2 && !array_diff($external, $enqueued), 'legacy CSS and JS retain their external URL');
        $config['meta']['snippet_revisions'] = false;
        Helper::saveIndexedConfig($config);
        $canPrepare = false;
        Helper::prepareRuntime();
        expectNetworkResult(Helper::hasUnpreparedPhp(Helper::getIndexedConfig(false)), 'read-only policy leaves legacy PHP paused');
        $canPrepare = true;
        Helper::prepareRuntime();
        expectNetworkResult(!Helper::hasUnpreparedPhp(Helper::getIndexedConfig(false)), 'writable library automatically prepares PHP');
        $hooks = [];
        (new $runner())->runSnippets();
        ob_start();
        foreach ($hooks['wp_footer'] ?? [] as $callback) $callback();
        expectNetworkResult(strpos(ob_get_clean(), 'sibling resolved') !== false, 'prepared PHP resolves its sibling from the library directory');
    }
    $multisite = false;
    $handler = new CodeHandler();
    $directory = new ReflectionProperty(CodeHandler::class, 'storageDir');
    $directory->setAccessible(true);
    $directory->setValue($handler, $storage);
    $draft = $model->createSnippet('draft body', ['name' => 'Draft shortcode', 'type' => 'php_content', 'run_at' => 'shortcode', 'status' => 'draft']);
    Helper::cacheSnippetIndex();
    $draftOutput = $handler->handleShortcode(['id' => basename($draft, '.php')]);
    expectNetworkResult(strpos($draftOutput, 'Snippet status is not published') !== false && strpos($draftOutput, 'draft body') === false, 'administrators see the draft shortcode status');
    chmod($storage . '/' . $draft, 0000);
    expectNetworkResult($model->deleteSnippet($draft) === true, 'an unreadable snippet can be removed from a writable directory');
    $config = Helper::getIndexedConfig(false);
    $priorIndex = file_get_contents($storage . '/index.php');
    chmod($storage, 0555);
    try {
        expectNetworkResult(Helper::enableSafeMode($config['meta']['secret_key']) === true, 'Safe Mode persists when the index cannot be replaced');
        expectNetworkResult(Helper::getRuntimeConfig()['meta']['force_disabled'] === 'yes', 'runtime observes the emergency flag');
        expectNetworkResult(file_get_contents($storage . '/index.php') === $priorIndex, 'emergency recovery preserves the complete index');
        $handler->maybeHandleFatalError(['response' => 500], ['file' => $storage . '/' . $native, 'message' => 'Compatibility check']);
        expectNetworkResult(isset(Helper::getRuntimeConfig()['error_files'][$native]), 'single-site quarantine persists with a read-only parent');
    } finally {
        chmod($storage, 0700);
    }
    $multisite = true;
    Helper::cacheSnippetIndex();
    $published = Helper::getIndexedConfig(false)['published'][$native];
    $before = \FluentSnippets\App\Services\SnippetRevision::path($storage . '/' . $native, $published['revision']);
    $model->updateSnippet($native, 'replacement', $published);
    Helper::cacheSnippetIndex();
    $config = Helper::getIndexedConfig(false);
    unset($config['error_files'][$native]);
    Helper::saveIndexedConfig($config);
    CodeRunner::pushRunningSnippet($native, $before);
    $handler->maybeHandleFatalError(['response' => 500], ['file' => $before, 'message' => 'Retired file']);
    $handler->maybeHandleFatalError(['response' => 500], ['file' => __FILE__, 'message' => 'Retired callback']);
    CodeRunner::popRunningSnippet();
    expectNetworkResult(!isset(Helper::getIndexedConfig(false)['error_files'][$native]), 'retired code cannot quarantine its replacement');
    $current = Helper::getIndexedConfig(false)['published'][$native]['revision'];
    $currentFile = \FluentSnippets\App\Services\SnippetRevision::path($storage . '/' . $native, $current);
    $hold = '$f = fopen($argv[1], "c"); flock($f, LOCK_EX); echo "locked\n"; flush(); usleep(250000);';
    $command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($hold) . ' ' . escapeshellarg($storage . '/.fluent-snippets-write.lock');
    $process = proc_open($command, [1 => ['pipe', 'w']], $pipes);
    expectNetworkResult(is_resource($process) && trim(fgets($pipes[1])) === 'locked', 'independent library writer acquired the lock');
    $handler->maybeHandleFatalError(['response' => 500], ['file' => $currentFile, 'message' => 'Current callback']);
    fclose($pipes[1]);
    proc_close($process);
    expectNetworkResult(isset(Helper::getRuntimeConfig()['error_files'][$native]), 'current revision quarantine survives writer contention');
    echo "ok - compatibility\n";
}
