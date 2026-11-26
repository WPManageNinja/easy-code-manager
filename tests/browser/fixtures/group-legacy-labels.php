<?php
/** Real WordPress/controller regression; run via wp --skip-plugins --skip-themes eval-file. */
if (!defined('WP_CLI') || !WP_CLI) exit;

use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Model\Snippet;
use FluentSnippets\App\Http\Controllers\GroupsController;
use FluentSnippets\App\Http\Controllers\SnippetsController;
use FluentSnippets\App\Services\SnippetGroups;

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
foreach (['Helpers/Arr', 'Helpers/Helper', 'Model/Snippet', 'Services/SnippetErrors', 'Services/Permissions', 'Services/SnippetGroups', 'Http/Controllers/GroupsController', 'Http/Controllers/SnippetsController'] as $class) require $repo . '/app/' . $class . '.php';
$GLOBALS['wpmn_legacy_failures'] = 0;
function legacyCheck($name, $passed, $reason = null) {
    if (!$passed) {
        $GLOBALS['wpmn_legacy_failures']++;
        if (!isset($GLOBALS['wpmn_legacy_first_failure'])) $GLOBALS['wpmn_legacy_first_failure'] = $name;
    }
    echo 'CASE ' . ($passed ? 'PASS ' : 'FAIL ') . $name . ($passed ? '' : ' -- ' . ($reason ?? $name)) . PHP_EOL;
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
legacyCheck('legacy WordPress labels remain addressable', $GLOBALS['wpmn_legacy_failures'] === 0, $GLOBALS['wpmn_legacy_first_failure'] ?? null);
$GLOBALS['wpmn_delete_events'] = [];
add_action('fluent_snippets/snippet_deleted', function ($file) { $GLOBALS['wpmn_delete_events'][] = $file; });
$doomed = $model->createSnippet('/* legacy deletion */', ['name'=>'Core retained deletion', 'type'=>'js', 'status'=>'draft', 'group'=>'Legacy deletion only', 'run_at'=>'wp_footer']);
Helper::cacheSnippetIndex();
$deleteRequest = new WP_REST_Request('POST');
$deleteRequest->set_body_params(['fluent_saving_snippet_name'=>$doomed]);
$deleted = SnippetsController::deleteSnippet($deleteRequest);
Helper::cacheSnippetIndex();
$retainedDeletion = !is_wp_error($deleted) && !is_file($temporary . '/' . $doomed) && in_array('Legacy deletion only', $model->getAllSnippetTagsGroups()[1], true);
legacyCheck('last legacy member deletion retains group', $retainedDeletion);
legacyCheck('successful deletion keeps its existing hook', $GLOBALS['wpmn_delete_events'] === [$doomed]);
$edited = $model->createSnippet('/* legacy editor */', ['name'=>'Core retained editor', 'type'=>'js', 'status'=>'draft', 'group'=>'Legacy editor source', 'run_at'=>'wp_footer']);
Helper::cacheSnippetIndex();
$beforeEdit = $model->findByFileName($edited);
$editMeta = $beforeEdit['meta'];
$editMeta['group'] = 'Editor-created%20label';
$updated = Helper::updateSnippet(['file_name'=>$edited, 'code'=>$beforeEdit['code'], 'meta'=>$editMeta, 'reactivate'=>'']);
Helper::cacheSnippetIndex();
$retainedEdit = !is_wp_error($updated) && in_array('Legacy editor source', $model->getAllSnippetTagsGroups()[1], true) && $model->findByFileName($edited)['code'] === $beforeEdit['code'];
legacyCheck('editor reassignment retains previous group', $retainedEdit);
$deleteRequest->set_body_params(['fluent_saving_snippet_name'=>$edited]);
$deleted = SnippetsController::deleteSnippet($deleteRequest);
Helper::cacheSnippetIndex();
$retainedEditorDeletion = !is_wp_error($deleted) && in_array('Editor-created%20label', $model->getAllSnippetTagsGroups()[1], true);
legacyCheck('editor-created group survives deletion', $retainedEditorDeletion);
$damaged = $model->createSnippet('/* damaged fixture */', ['name'=>'Core damaged header', 'type'=>'js', 'status'=>'draft', 'group'=>'Legacy damaged header']);
Helper::cacheSnippetIndex();
file_put_contents($temporary . '/' . $damaged, '<?php // damaged synthetic draft header');
$deleteRequest->set_body_params(['fluent_saving_snippet_name'=>$damaged]);
$deleted = SnippetsController::deleteSnippet($deleteRequest);
Helper::cacheSnippetIndex();
$retainedDamaged = !is_wp_error($deleted) && in_array('Legacy damaged header', $model->getAllSnippetTagsGroups()[1], true);
legacyCheck('damaged file retains its indexed group on deletion', $retainedDamaged);
legacyCheck('groups survive existing membership removal paths', $retainedDeletion && $retainedEdit && $retainedEditorDeletion && $retainedDamaged, 'legacy group disappeared after membership removal');
$source = $model->createSnippet('/* retry source */', ['name'=>'Core retry source', 'type'=>'js', 'status'=>'draft', 'group'=>'Unrecorded retry source', 'run_at'=>'wp_footer']);
$target = $model->createSnippet('/* retry target */', ['name'=>'Core retry target', 'type'=>'js', 'status'=>'published', 'group'=>'Unrecorded retry target', 'run_at'=>'wp_footer']);
Helper::cacheSnippetIndex();
$retried = legacyRequest('rename', ['name'=>'Unrecorded retry source', 'new_name'=>'Unrecorded retry target', 'retry'=>'yes']);
$retryRefused = is_wp_error($retried) && $model->findByFileName($source)['meta']['group'] === 'Unrecorded retry source' && $model->findByFileName($target)['meta']['group'] === 'Unrecorded retry target';
legacyCheck('unrecorded retry cannot merge existing groups', $retryRefused, 'unrecorded retry merged existing groups');
$registry = $temporary . '/groups/index.php';
$savedRegistry = file_get_contents($registry);
$corruptRegistry = "<?php exit; ?>\n" . '{unreadable';
file_put_contents($registry, $corruptRegistry);
$sameGroup = $model->findByFileName($target);
$bodySaved = Helper::updateSnippet(['file_name'=>$target, 'code'=>'/* same-group storage recovery */', 'meta'=>$sameGroup['meta'], 'reactivate'=>'']);
$bodySurvives = !is_wp_error($bodySaved) && $model->findByFileName($target)['code'] === '/* same-group storage recovery */';
$inactiveMeta = $model->findByFileName($target)['meta'];
$inactiveMeta['status'] = 'draft';
$deactivated = Helper::updateSnippet(['file_name'=>$target, 'code'=>'/* same-group storage recovery */', 'meta'=>$inactiveMeta, 'reactivate'=>'']);
$deactivationSurvives = !is_wp_error($deactivated) && $model->findByFileName($target)['meta']['status'] === 'draft';
legacyCheck('same-group deactivation bypasses unavailable group storage', $deactivationSurvives);
legacyCheck('code-only saves bypass unavailable group storage', $bodySurvives && $deactivationSurvives);
$beforeRefusal = file_get_contents($temporary . '/' . $target);
$changedMeta = $sameGroup['meta'];
$changedMeta['group'] = 'Another group';
$groupChange = Helper::updateSnippet(['file_name'=>$target, 'code'=>'/* must not replace body */', 'meta'=>$changedMeta, 'reactivate'=>'']);
$changeRefused = is_wp_error($groupChange) && $groupChange->get_error_code() === 'snippet_groups' && file_get_contents($temporary . '/' . $target) === $beforeRefusal;
$deleteRequest->set_body_params(['fluent_saving_snippet_name'=>$target]);
$eventsBefore = count($GLOBALS['wpmn_delete_events']);
$deleteError = SnippetsController::deleteSnippet($deleteRequest);
$deletionRefused = is_wp_error($deleteError) && $deleteError->get_error_code() === 'snippet_groups' && is_file($temporary . '/' . $target) && file_get_contents($temporary . '/' . $target) === $beforeRefusal && count($GLOBALS['wpmn_delete_events']) === $eventsBefore;
legacyCheck('failed retention leaves membership unchanged', $changeRefused && $deletionRefused && file_get_contents($registry) === $corruptRegistry, 'retention failure changed a snippet');
file_put_contents($registry, $savedRegistry);
if ($GLOBALS['wpmn_legacy_failures']) WP_CLI::halt(1);
