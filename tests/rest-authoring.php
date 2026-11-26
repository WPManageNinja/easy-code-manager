<?php
/**
 * Pins the REST authoring routes restored in 10.57.
 *
 * They were removed in 10.56 because nothing in this plugin called them, and an outside
 * integration broke. What has to hold now:
 *
 *   - the switch is off by default, can be turned on in settings, and wp-config.php's
 *     FLUENT_SNIPPETS_REST_AUTHORING beats both
 *   - an index rebuild does not quietly turn it off again (it used to drop every setting
 *     it did not list, which is how enable_line_wrap kept resetting)
 *   - each refusal says which of its four reasons it was, with the right status
 *   - update changes only what it is sent: status survives a code edit, authorship
 *     cannot be forged from the request body
 *   - a stale expected_hash writes nothing and answers 409
 *   - the pre-10.56 request format still works
 *   - Router passes a 409 through instead of flattening it to 422
 *
 *     php tests/rest-authoring.php
 *
 * Exit code 0 = pass, 1 = failure.
 *
 * Not shipped: build.sh only copies app/, dist/, language/ and the root files.
 */

$base = dirname(__DIR__) . '/';

$storage = sys_get_temp_dir() . '/fluent-snippets-rest-authoring-' . getmypid();

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
define('FLUENT_SNIPPETS_PLUGIN_VERSION', '10.57');

// What the current request is allowed to do. Each check below sets the parts it is about.
$GLOBALS['fs_test'] = [
    'logged_in' => true,
    'caps'      => ['install_plugins' => true, 'unfiltered_html' => true],
    'file_mods' => true,
    'actions'   => [],
];

function untrailingslashit($string) { return rtrim($string, '/\\'); }
function wp_parse_args($args, $defaults = []) { return array_merge($defaults, (array)$args); }
function wp_mkdir_p($dir) { return is_dir($dir) || mkdir($dir, 0777, true); }
function site_url() { return 'https://example.test'; }
function content_url($path = '') { return 'https://example.test/wp-content' . $path; }
function wp_upload_dir() { return ['basedir' => '/tmp/uploads', 'baseurl' => 'https://example.test/uploads']; }
function apply_filters($tag, $value) { return $value; }
function do_action($tag, ...$args) {
    $GLOBALS['fs_test']['actions'][] = [$tag, $args];
    if (isset($GLOBALS['fs_test']['listener'][$tag])) {
        $GLOBALS['fs_test']['listener'][$tag](...$args);
    }
}
function add_action() {}
function is_wp_error($thing) { return $thing instanceof \WP_Error; }
function sanitize_file_name($name) { return preg_replace('/[^a-zA-Z0-9._-]/', '', $name); }
function sanitize_title($title) { return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title)); }
function current_time($type) { return date('Y-m-d H:i:s'); }
function get_current_user_id() { return 7; }
function __($text, $domain = '') { return $text; }
function is_user_logged_in() { return $GLOBALS['fs_test']['logged_in']; }
function current_user_can($cap) { return !empty($GLOBALS['fs_test']['caps'][$cap]); }
function is_multisite() { return !empty($GLOBALS['fs_test']['network']); }
function is_super_admin() { return !empty($GLOBALS['fs_test']['super_admin']); }
function get_site() { return (object) ['network_id' => 1]; }
function get_current_blog_id() { return $GLOBALS['fs_test']['site_id'] ?? 3; }
function wp_is_file_mod_allowed($context) { return $GLOBALS['fs_test']['file_mods']; }
function rest_authorization_required_code() { return 403; }
function rest_get_authenticated_app_password() { return array_key_exists('app_password', $GLOBALS['fs_test']) ? $GLOBALS['fs_test']['app_password'] : 'app-pass-uuid'; }
function sanitize_text_field($text) { return trim((string)$text); }
function rest_ensure_response($data) { return new WP_REST_Response($data, 200); }

$GLOBALS['fs_routes'] = [];
function register_rest_route($namespace, $route, $args) { $GLOBALS['fs_routes'][$route] = $args; }

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
    public function add_data($data) { $this->data = $data; }
}

class WP_REST_Request
{
    private $params;

    public function __construct($params = []) { $this->params = $params; }

    public function get_param($key) { return array_key_exists($key, $this->params) ? $this->params[$key] : null; }
}

class WP_REST_Response
{
    public $data;
    public $status;

    public function __construct($data, $status) { $this->data = $data; $this->status = $status; }
}

class WP_REST_Server
{
    const READABLE = 'GET';
    const CREATABLE = 'POST';
}

require $base . 'app/Services/SnippetRevision.php';
require $base . 'app/Helpers/Arr.php';
require $base . 'app/Helpers/Helper.php';
require $base . 'app/Model/Snippet.php';
require $base . 'app/Services/PhpValidator.php';
require $base . 'app/Services/SnippetErrors.php';
require $base . 'app/Services/Permissions.php';
require $base . 'app/Services/RestAuthoring.php';
require $base . 'app/Services/FluentSnippetCondition.php';
require $base . 'app/Services/Router.php';
require $base . 'app/Http/Controllers/SnippetsController.php';
require $base . 'app/Http/Controllers/SettingsController.php';

use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Http\Controllers\SettingsController;
use FluentSnippets\App\Http\Controllers\SnippetsController;
use FluentSnippets\App\Services\RestAuthoring;

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

function errorStatus($result)
{
    if (!is_wp_error($result)) {
        return null;
    }
    $data = $result->get_error_data();
    return is_array($data) && isset($data['status']) ? $data['status'] : null;
}

function describe($result)
{
    return is_wp_error($result) ? $result->get_error_code() . ': ' . $result->get_error_message() : json_encode($result);
}

function setRestAuthoring($value)
{
    Helper::cacheSnippetIndex();
    $config = Helper::getIndexedConfig(false);
    $config['meta']['rest_authoring'] = $value;
    Helper::saveIndexedConfig($config);
    Helper::flushIndexedConfigCache();
}

function readFile_($fileName)
{
    return file_get_contents(FLUENT_SNIPPETS_STORAGE_DIR . '/' . $fileName);
}

echo "\nThe switch\n";

Helper::cacheSnippetIndex();
check('off on a fresh install', RestAuthoring::isEnabled() === false);

$refused = RestAuthoring::permissionCheck();
check('refused while off', errorStatus($refused) === 403 && $refused->get_error_code() === 'fluent_snippets_rest_authoring_disabled', describe($refused));
check('the refusal says where to turn it on', strpos($refused->get_error_message(), 'Settings') !== false, $refused->get_error_message());

setRestAuthoring('yes');
check('on once the setting says so', RestAuthoring::isEnabled() === true);
check('allowed with both capabilities', RestAuthoring::permissionCheck() === true);

Helper::cacheSnippetIndex();
Helper::flushIndexedConfigCache();
check('an index rebuild keeps it on', RestAuthoring::isEnabled() === true);

$GLOBALS['fs_test']['logged_in'] = false;
check('anonymous request is a 401', errorStatus(RestAuthoring::permissionCheck()) === 401);
$GLOBALS['fs_test']['logged_in'] = true;

$GLOBALS['fs_test']['caps']['unfiltered_html'] = false;
$noHtml = RestAuthoring::permissionCheck();
check('missing unfiltered_html is refused', is_wp_error($noHtml) && $noHtml->get_error_code() === 'rest_forbidden', describe($noHtml));
check('the controller refuses it too', is_wp_error(SnippetsController::updateSnippet(new WP_REST_Request(['file_name' => 'x.php']))));
$GLOBALS['fs_test']['caps']['unfiltered_html'] = true;

$GLOBALS['fs_test']['file_mods'] = false;
$noMods = RestAuthoring::permissionCheck();
check('DISALLOW_FILE_MODS is named, not reported as a permissions problem', is_wp_error($noMods) && $noMods->get_error_code() === 'fluent_snippets_file_mods_disabled', describe($noMods));
$GLOBALS['fs_test']['file_mods'] = true;

echo "\nCreating\n";

$created = SnippetsController::createSnippet(new WP_REST_Request([
    'code' => "add_action('init', function () { \$a = 1; });",
    'meta' => ['name' => 'Rest Probe', 'type' => 'PHP', 'run_at' => 'wp'],
]));

check('creates from a JSON body without a status', !is_wp_error($created) && !empty($created['snippet']['file_name']), describe($created));

$fileName = $created['snippet']['file_name'];
$snippet = $created['snippet'];

check('starts as a draft, as it would from the editor', $snippet['status'] === 'draft', $snippet['status']);
check('reply carries the code without the <?php line', $snippet['code'] === "add_action('init', function () { \$a = 1; });", json_encode($snippet['code']));
check('reply carries code_hash over that code', $snippet['code_hash'] === hash('sha256', $snippet['code']));

$found = SnippetsController::findSnippet(new WP_REST_Request(['snippet_name' => $fileName]));
check('find returns the same hash', $found['snippet']['code_hash'] === $snippet['code_hash']);

$audit = end($GLOBALS['fs_test']['actions']);
check('the write is announced with who made it', $audit[0] === 'fluent_snippets/rest_snippet_written' && $audit[1][1] === 'create' && $audit[1][2]['app_password_uuid'] === 'app-pass-uuid', json_encode($audit));

$legacyCreate = SnippetsController::createSnippet(new WP_REST_Request([
    'meta' => json_encode(['name' => 'Legacy Probe', 'type' => 'PHP', 'run_at' => 'wp', 'status' => 'draft', 'code' => "add_action('init', '__return_true');"]),
]));
check('pre-10.56 create (code inside a meta JSON string) still works', !is_wp_error($legacyCreate), describe($legacyCreate));

echo "\nUpdating\n";

// Publish it first, so the next edit has a status worth preserving.
$published = SnippetsController::updateSnippet(new WP_REST_Request([
    'file_name' => $fileName,
    'meta'      => ['status' => 'published'],
]));
check('meta-only update publishes', !is_wp_error($published) && $published['snippet']['status'] === 'published', describe($published));
check('meta-only update leaves the code alone', $published['snippet']['code'] === $snippet['code']);

$hash = $published['snippet']['code_hash'];

$edited = SnippetsController::updateSnippet(new WP_REST_Request([
    'file_name'     => $fileName,
    'code'          => "add_action('init', function () { \$a = 2; });",
    'expected_hash' => $hash,
]));
check('code-only update with the current hash saves', !is_wp_error($edited), describe($edited));
check('status survives a code edit', $edited['snippet']['status'] === 'published', $edited['snippet']['status']);
check('name survives a code edit', $edited['snippet']['meta']['name'] === 'Rest Probe');
check('reply is the new code, read back', $edited['snippet']['code'] === "add_action('init', function () { \$a = 2; });");
check('reply carries the new hash', $edited['snippet']['code_hash'] !== $hash && $edited['snippet']['code_hash'] === hash('sha256', $edited['snippet']['code']));

$before = readFile_($fileName);
$stale = SnippetsController::updateSnippet(new WP_REST_Request([
    'file_name'     => $fileName,
    'code'          => "add_action('init', function () { \$a = 3; });",
    'expected_hash' => $hash,
]));
check('a stale hash is a 409', errorStatus($stale) === 409, describe($stale));
check('the 409 hands back the current hash', is_wp_error($stale) && $stale->get_error_data()['current_hash'] === $edited['snippet']['code_hash']);
check('a stale hash writes nothing', readFile_($fileName) === $before);

$legacy = SnippetsController::updateSnippet(new WP_REST_Request([
    'fluent_saving_snippet_name' => $fileName,
    'meta'                       => json_encode([
        'name'       => 'Rest Probe Renamed',
        'type'       => 'PHP',
        'run_at'     => 'wp',
        'status'     => 'published',
        'created_by' => 999,
        'code'       => "add_action('init', function () { \$a = 4; });",
    ]),
]));
check('pre-10.56 update (full meta JSON, code inside) still works', !is_wp_error($legacy) && $legacy['snippet']['meta']['name'] === 'Rest Probe Renamed', describe($legacy));
check('created_by cannot be set from the request', strpos(readFile_($fileName), '@created_by: 999') === false);
check('updated_by is whoever is authenticated', strpos(readFile_($fileName), '@updated_by: 7') !== false);

$GLOBALS['fs_test']['network'] = $GLOBALS['fs_test']['super_admin'] = true;
$scope = ['mode' => 'only', 'network' => 1, 'sites' => [3]];
$lock = fopen($storage . '/.fluent-snippets-write.lock', 'c');
flock($lock, LOCK_EX);
$busy = SnippetsController::updateSnippet(new WP_REST_Request(['file_name' => $fileName, 'meta' => ['description' => 'Competing edit']]));
check('a competing edit is refused before reading and merging', errorStatus($busy) === 409, describe($busy));
$previousCaps = $GLOBALS['fs_test']['caps'];
$GLOBALS['fs_test']['caps']['manage_options'] = true;
$busySettings = SettingsController::saveSettings(new WP_REST_Request(['settings' => ['auto_publish' => 'no']]));
$GLOBALS['fs_test']['caps'] = $previousCaps;
check('network settings share the publication lock', errorStatus($busySettings) === 409, describe($busySettings));
$busySync = SnippetsController::syncIndex(new WP_REST_Request([]));
// A clean sync is allowed to return unchanged without acquiring a lock.
check('a concurrent sync either needs no write or reports contention', is_wp_error($busySync) ? errorStatus($busySync) === 409 : $busySync['changed'] === false);
fclose($lock);
$GLOBALS['fs_test']['site_id'] = 1;
$refused = SnippetsController::updateSnippet(new WP_REST_Request([
    'file_name' => $fileName, 'meta' => ['network_sites' => $scope], 'code' => '/* scope check */',
]));
check('restricted PHP explains where to save', is_wp_error($refused) && strpos($refused->get_error_message(), 'included') !== false, describe($refused));
$GLOBALS['fs_test']['site_id'] = 3;
foreach ([['network_sites' => $scope], ['description' => 'Partial update'], ['network_sites' => null]] as $patch) {
    $response = SnippetsController::updateSnippet(new WP_REST_Request(['file_name' => $fileName, 'meta' => $patch]));
    $expected = $patch === ['network_sites' => null] ? null : $scope;
    check('REST preserves or explicitly clears site scope: ' . json_encode($patch),
        !is_wp_error($response) && ($response['snippet']['meta']['network_sites'] ?? null) === $expected, describe($response));
}
$GLOBALS['fs_test']['network'] = $GLOBALS['fs_test']['super_admin'] = false;

$createData = ['code' => '/* publication retry */', 'meta' => ['name' => 'Publication retry', 'type' => 'js', 'run_at' => 'wp_footer', 'status' => 'draft']];
$prior = Helper::getIndexedConfig(false);
$prior['error_files'][$fileName] = 'Paused by fixture';
Helper::saveIndexedConfig($prior);
$GLOBALS['fs_test']['listener']['fluent_snippets/snippet_updated'] = function () use ($fileName, $storage) {
    check('the old revision remains paused while the replacement is pending', isset(Helper::getRuntimeConfig()['error_files'][$fileName]));
    rename($storage . '/index.php', $storage . '/prior-index');
    mkdir($storage . '/index.php');
};
$reactivate = ['file_name' => $fileName, 'reactivate' => true, 'code' => '/* updated fixture */'];
$failedUpdate = SnippetsController::updateSnippet(new WP_REST_Request($reactivate));
unset($GLOBALS['fs_test']['listener']['fluent_snippets/snippet_updated']);
rmdir($storage . '/index.php');
rename($storage . '/prior-index', $storage . '/index.php');
check('a failed reactivation leaves the published revision paused', errorStatus($failedUpdate) === 503 && isset(Helper::getIndexedConfig(false)['error_files'][$fileName]));
$restored = SnippetsController::updateSnippet(new WP_REST_Request($reactivate));
check('a successful retry clears the pause', !is_wp_error($restored) && !isset(Helper::getRuntimeConfig()['error_files'][$fileName]));
$markerForCurrent = function () use ($fileName, $storage) {
    $record = Helper::getIndexedConfig(false)['published'][$fileName];
    return $storage . '/cached/.fluent-quarantine-' . $record['revision'];
};
touch($markerForCurrent());
$GLOBALS['fs_test']['listener']['fluent_snippets/snippet_updated'] = function () use ($markerForCurrent, $fileName) {
    check('marker-only reactivation keeps a pause in the index during publication', isset(Helper::getIndexedConfig(false)['error_files'][$fileName]));
    Helper::cacheSnippetIndex();
    touch($markerForCurrent());
};
$reactivate['code'] = '/* another harmless replacement */';
$replacement = SnippetsController::updateSnippet(new WP_REST_Request($reactivate));
unset($GLOBALS['fs_test']['listener']['fluent_snippets/snippet_updated']);
check('a new pause recorded at publication survives reactivation', !is_wp_error($replacement) && isset(Helper::getRuntimeConfig($fileName)['error_files'][$fileName]));
unlink($markerForCurrent());
$beforeFiles = glob($storage . '/*.php');
$GLOBALS['fs_test']['listener']['fluent_snippets/snippet_created'] = function () use ($storage) {
    rename($storage . '/index.php', $storage . '/prior-index');
    mkdir($storage . '/index.php');
};
$failedCreate = Helper::createSnippet($createData);
unset($GLOBALS['fs_test']['listener']['fluent_snippets/snippet_created']);
rmdir($storage . '/index.php');
rename($storage . '/prior-index', $storage . '/index.php');
check('failed creation removes its new file', is_wp_error($failedCreate) && glob($storage . '/*.php') === $beforeFiles);
$retry = Helper::createSnippet($createData);
check('retry creates a single snippet', !is_wp_error($retry) && count(glob($storage . '/*.php')) === count($beforeFiles) + 1);

$brokenFile = '900-damaged-fixture.php';
file_put_contents($storage . '/' . $brokenFile, "Incomplete fixture header\n");
$editError = SnippetsController::updateSnippet(new WP_REST_Request(['file_name' => $brokenFile, 'meta' => ['description' => 'Update']]));
check('a damaged file is reported as unreadable metadata', errorStatus($editError) === 422, describe($editError));
$removed = SnippetsController::deleteSnippet(new WP_REST_Request(['fluent_saving_snippet_name' => $brokenFile]));
check('a damaged snippet can still be removed', !is_wp_error($removed) && !is_file($storage . '/' . $brokenFile), describe($removed));

$deleteFixture = Helper::createSnippet($createData);
$GLOBALS['fs_test']['listener']['fluent_snippets/snippet_deleted'] = function () use ($storage) {
    rename($storage . '/index.php', $storage . '/before-delete-index');
    mkdir($storage . '/index.php');
};
$deleteResult = SnippetsController::deleteSnippet(new WP_REST_Request(['fluent_saving_snippet_name' => $deleteFixture]));
unset($GLOBALS['fs_test']['listener']['fluent_snippets/snippet_deleted']);
rmdir($storage . '/index.php');
rename($storage . '/before-delete-index', $storage . '/index.php');
check('a partial deletion reports the failed index refresh', errorStatus($deleteResult) === 503 && !is_file($storage . '/' . $deleteFixture));
Helper::cacheSnippetIndex();

$before = readFile_($fileName);
$broken = SnippetsController::updateSnippet(new WP_REST_Request([
    'file_name' => $fileName,
    'code'      => "\$a = ;",
]));
$details = is_wp_error($broken) ? $broken->get_error_data()['error_details'] : [];
check('a syntax error is refused with the editor\'s explanation', is_wp_error($broken) && !empty($details['title']) && !empty($details['fix']), describe($broken));
check('a refused update writes nothing', readFile_($fileName) === $before);

check('an unknown status is a 400', errorStatus(SnippetsController::updateSnippet(new WP_REST_Request(['file_name' => $fileName, 'meta' => ['status' => 'live']]))) === 400);
check('an array where text belongs is a 400', errorStatus(SnippetsController::updateSnippet(new WP_REST_Request(['file_name' => $fileName, 'meta' => ['tags' => ['a', 'b']]]))) === 400);
check('unreadable meta is a 400', errorStatus(SnippetsController::updateSnippet(new WP_REST_Request(['file_name' => $fileName, 'meta' => '{not json']))) === 400);
check('a missing snippet is a 404', errorStatus(SnippetsController::updateSnippet(new WP_REST_Request(['file_name' => '999-nope.php']))) === 404);
check('index.php is not a snippet', errorStatus(SnippetsController::updateSnippet(new WP_REST_Request(['file_name' => 'index.php']))) === 404);
check('no file name is a 400', errorStatus(SnippetsController::updateSnippet(new WP_REST_Request([]))) === 400);
check('oversized code is a 413', errorStatus(SnippetsController::updateSnippet(new WP_REST_Request(['file_name' => $fileName, 'code' => str_repeat('a', SnippetsController::MAX_CODE_BYTES + 1)]))) === 413);

echo "\nRouter\n";

$router = new \FluentSnippets\App\Services\Router('fluent-snippets');
$router->post('probe/conflict', function () { return new WP_Error('c', 'conflict', ['status' => 409]); }, function () { return true; })
    ->post('probe/field', function () { return new WP_Error('status', 'Status is required', ['status' => 'Status is required']); }, function () { return true; });

$conflict = call_user_func($GLOBALS['fs_routes']['probe/conflict']['callback'], new WP_REST_Request());
check('an error carrying a status keeps it', $conflict->status === 409, (string)$conflict->status);

$field = call_user_func($GLOBALS['fs_routes']['probe/field']['callback'], new WP_REST_Request());
check('a field message under the same key falls back to 422', $field->status === 422, (string)$field->status);

echo "\nAn Application Password cannot flip the switch\n";

$GLOBALS['fs_test']['caps']['manage_options'] = true;
$allSettings = ['auto_disable' => 'yes', 'auto_publish' => 'no', 'remove_on_uninstall' => 'no', 'enable_line_wrap' => 'no'];
$lockPath = $storage . '/.fluent-snippets-write.lock';
$markerPath = $storage . '/cached/.fluent-safe-mode';
touch($markerPath);
chmod($lockPath, 0400);
try {
    $recovery = SettingsController::disableSafeMode(new WP_REST_Request());
    check('an authorized administrator can clear marker-only Safe Mode with a read-only library lock', !is_wp_error($recovery) && !file_exists($markerPath), describe($recovery));
} finally {
    chmod($lockPath, 0600);
}

setRestAuthoring('no');
$flip = SettingsController::saveSettings(new WP_REST_Request(['settings' => $allSettings + ['rest_authoring' => 'yes']]));
Helper::flushIndexedConfigCache();
check('turning it on with an Application Password is a 403', errorStatus($flip) === 403, describe($flip));
check('and the setting stays off', RestAuthoring::isEnabled() === false);

$same = SettingsController::saveSettings(new WP_REST_Request(['settings' => $allSettings + ['rest_authoring' => 'no']]));
check('other settings still save over an Application Password', !is_wp_error($same), describe($same));

$GLOBALS['fs_test']['app_password'] = null;
$admin = SettingsController::saveSettings(new WP_REST_Request(['settings' => $allSettings + ['rest_authoring' => 'yes']]));
Helper::flushIndexedConfigCache();
check('the admin screen can turn it on', !is_wp_error($admin) && RestAuthoring::isEnabled() === true, describe($admin));
unset($GLOBALS['fs_test']['app_password']);

echo "\nThe constant is read as a boolean\n";

foreach ([[true, true], [1, true], ['1', true], ['yes', true], ['true', true], ['on', true],
          [false, false], [0, false], ['0', false], ['false', false], ['no', false], ['off', false], ['', false], ['nonsense', false]] as $case) {
    check('constant ' . var_export($case[0], true) . ' means ' . ($case[1] ? 'on' : 'off'), RestAuthoring::constantMeansOn($case[0]) === $case[1]);
}

echo "\nwp-config.php wins\n";

// Last, because a constant cannot be undefined again.
define('FLUENT_SNIPPETS_REST_AUTHORING', false);
check('the constant turns it off over the setting', RestAuthoring::isEnabled() === false && RestAuthoring::lockedValue() === false);
$locked = RestAuthoring::permissionCheck();
check('the refusal points at wp-config.php instead of Settings', is_wp_error($locked) && strpos($locked->get_error_message(), 'wp-config.php') !== false, describe($locked));

echo "\n$checks checks, $failures failed\n";

exit($failures ? 1 : 0);
