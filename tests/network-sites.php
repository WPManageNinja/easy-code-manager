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
        foreach (glob($storage . '/cached/*') as $file) {
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
class WP_Error {}
function is_wp_error($value) { return $value instanceof WP_Error; }
function is_admin() { return false; }
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
    $model->updateSnippet($file, '/* next revision */', $meta + ['network_sites' => $except]);
    Helper::cacheSnippetIndex();
    $next = Helper::getIndexedConfig()['published'][$file];
    expectNetworkResult($first['revision'] !== $next['revision'], 'changed publications have distinct identities');
    foreach ([\FluentSnippets\App\Services\SnippetRevision::class, \FluentSnippets\Mu\SnippetRevision::class] as $revision) {
        $previousFile = $revision::resolve($path, $first);
        $currentFile = $revision::resolve($path, $next);
        expectNetworkResult($revision::parse(file_get_contents($previousFile), true) === '/* first revision */', 'an in-flight request retains its original body');
        expectNetworkResult($revision::parse(file_get_contents($currentFile), true) === '/* next revision */', 'new requests use the new revision');
        $legacy = ['file_name' => $file];
        file_put_contents($path, str_replace('@network_sites:', '@network_sites :', file_get_contents($path)));
        $frozen = $revision::resolve($path, $legacy);
        expectNetworkResult($legacy['network_sites'] === $except && $frozen !== $path, 'legacy header grammar and snapshot selection match');
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
    echo "ok - publication\n";
}
