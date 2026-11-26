<?php
/** Seed an actual incomplete rename for browser recovery checks on synthetic local drafts. */
if (!defined('WP_CLI') || !WP_CLI) exit;

use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Model\Snippet;
use FluentSnippets\App\Http\Controllers\GroupsController;

$host = parse_url(home_url(), PHP_URL_HOST);
if ((!in_array($host, ['localhost', '127.0.0.1'], true) && substr($host, -5) !== '.test')
    || !current_user_can('install_plugins') || !current_user_can('unfiltered_html') || !wp_is_file_mod_allowed('capability_update_core')) {
    throw new RuntimeException('Use a writable local synthetic fixture.');
}
$suffix = getmypid() . '-' . bin2hex(random_bytes(3));
$source = 'WPMN 13596 Retry Source ' . $suffix;
$target = 'WPMN 13596 Retry Target ' . $suffix;
$model = new Snippet();
$files = [];
foreach (['first', 'second'] as $part) {
    $file = $model->createSnippet('/* WPMN 13596 retry fixture */', ['name'=>'WPMN 13596 retry ' . $part . ' ' . $suffix, 'type'=>'js', 'status'=>'draft', 'group'=>$source, 'run_at'=>'wp_footer']);
    if (is_wp_error($file)) throw new RuntimeException('Could not seed retry draft.');
    $files[] = $file;
}
Helper::cacheSnippetIndex();
$storage = Helper::getStorageDir();
$blocked = $storage . '/' . $files[1];
$directoryMode = fileperms($storage) & 0777;
$fileMode = fileperms($blocked) & 0777;
$restore = function () use ($storage, $blocked, $directoryMode, $fileMode) {
    chmod($storage, $directoryMode);
    chmod($blocked, $fileMode);
};
register_shutdown_function($restore);
try {
    chmod($blocked, 0444);
    chmod($storage, 0555);
    $request = new WP_REST_Request('POST');
    $request->set_body_params(['name'=>$source, 'new_name'=>$target]);
    $result = GroupsController::rename($request);
} finally {
    $restore();
}
$data = is_wp_error($result) ? $result->get_error_data() : [];
if (($data['changed'] ?? null) !== 1 || ($data['failed'] ?? null) !== 1 || empty($data['partial'])) throw new RuntimeException('Expected one actual failed member write.');
echo wp_json_encode(['source'=>$source, 'target'=>$target, 'files'=>$files]) . PHP_EOL;
