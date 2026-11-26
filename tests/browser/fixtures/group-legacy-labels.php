<?php
/** Real WordPress/controller regression; run via wp --skip-plugins --skip-themes eval-file. */
if (!defined('WP_CLI') || !WP_CLI) exit;

use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Model\Snippet;
use FluentSnippets\App\Http\Controllers\GroupsController;

$host = parse_url(home_url(), PHP_URL_HOST);
if ((!in_array($host, ['localhost', '127.0.0.1'], true) && substr($host, -5) !== '.test')
    || !current_user_can('install_plugins') || !current_user_can('unfiltered_html')
    || !wp_is_file_mod_allowed('capability_update_core') || defined('FLUENT_SNIPPETS_STORAGE_DIR')
    || class_exists(Helper::class, false)) {
    throw new RuntimeException('Use a writable local fixture with plugins skipped and no storage override.');
}
$temporary = sys_get_temp_dir() . '/fluent-snippets-core-groups-' . getmypid();
if (!mkdir($temporary, 0700)) throw new RuntimeException('Could not isolate test storage');
define('FLUENT_SNIPPETS_STORAGE_DIR', $temporary);
if (!defined('FLUENT_SNIPPETS_PLUGIN_VERSION')) define('FLUENT_SNIPPETS_PLUGIN_VERSION', '10.58');
register_shutdown_function(function () use ($temporary) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($temporary);
});
$repo = dirname(__DIR__, 3);
foreach (['Helpers/Arr', 'Helpers/Helper', 'Model/Snippet', 'Services/SnippetErrors', 'Services/Permissions', 'Services/SnippetGroups', 'Http/Controllers/GroupsController'] as $class) require $repo . '/app/' . $class . '.php';
$GLOBALS['wpmn_legacy_failures'] = 0;
function legacyCheck($name, $passed) {
    if (!$passed) {
        $GLOBALS['wpmn_legacy_failures']++;
        if (!isset($GLOBALS['wpmn_legacy_first_failure'])) $GLOBALS['wpmn_legacy_first_failure'] = $name;
    }
    echo 'CASE ' . ($passed ? 'PASS ' : 'FAIL ') . $name . ($passed ? '' : ' -- ' . $GLOBALS['wpmn_legacy_first_failure']) . PHP_EOL;
}
function legacyRequest($operation, $data) {
    $request = new WP_REST_Request('POST');
    $request->set_body_params($data);
    return GroupsController::$operation($request);
}
$model = new Snippet();
foreach (['Sales%20Team', '<b>Legacy label</b>', "Legacy\t\tTeam"] as $number => $label) {
    legacyCheck('core sanitizer changes legacy label ' . $number, sanitize_text_field($label) !== $label && Helper::sanitizeMetaValue($label) === $label);
    $file = $model->createSnippet('/* legacy identity body */', ['name'=>'Core legacy label ' . $number, 'type'=>'js', 'status'=>'draft', 'group'=>$label]);
    $other = $model->createSnippet('/* other identity body */', ['name'=>'Core legacy other ' . $number, 'type'=>'js', 'status'=>'draft', 'group'=>'']);
    Helper::cacheSnippetIndex();
    $before = $model->findByFileName($file);
    legacyCheck('legacy label discovered ' . $number, in_array($label, $model->getAllSnippetTagsGroups()[1], true));
    legacyCheck('legacy same destination ' . $number, !is_wp_error(legacyRequest('move', ['file_name'=>$file, 'group'=>$label, 'expected_group'=>$label])));
    legacyCheck('legacy move destination ' . $number, !is_wp_error(legacyRequest('move', ['file_name'=>$other, 'group'=>$label, 'expected_group'=>''])) && $model->findByFileName($other)['meta']['group'] === $label);
    legacyCheck('legacy no-op rename ' . $number, !is_wp_error(legacyRequest('rename', ['name'=>$label, 'new_name'=>$label])));
    $renamed = 'Legacy identity renamed ' . $number;
    legacyCheck('legacy rename source ' . $number, !is_wp_error(legacyRequest('rename', ['name'=>$label, 'new_name'=>$renamed])));
    foreach ([$file, $other] as $changed) {
        $after = $model->findByFileName($changed);
        legacyCheck('legacy renamed membership ' . $number . ' ' . $changed, $after['meta']['group'] === $renamed && $after['status'] === 'draft');
    }
    legacyCheck('legacy body unchanged ' . $number, $model->findByFileName($file)['code'] === $before['code']);
}
legacyCheck('new markup remains invalid', is_wp_error(legacyRequest('create', ['name'=>'<i>New</i>'])));
legacyCheck('unknown legacy-looking destination refused', is_wp_error(legacyRequest('move', ['file_name'=>$file, 'group'=>'<b>Unknown</b>', 'expected_group'=>$renamed])));
legacyCheck('legacy WordPress labels remain addressable', $GLOBALS['wpmn_legacy_failures'] === 0);
if ($GLOBALS['wpmn_legacy_failures']) WP_CLI::halt(1);
