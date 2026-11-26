<?php
/** Group storage and metadata operations; standalone temp fixtures, no WP install. */
$base = dirname(__DIR__) . '/';
$tempRoot = sys_get_temp_dir() . '/fluent-snippets-groups-' . getmypid();
$storage = $tempRoot . (getenv('GROUPS_USE_DEFAULT') ? '/fluent-snippet-storage' : '/custom');
mkdir($storage, 0777, true);
register_shutdown_function(function () use ($tempRoot) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) { @chmod($file->getPathname(), 0777); $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname()); }
    @rmdir($tempRoot);
});
define('ABSPATH', $base);
define('WP_CONTENT_DIR', $tempRoot);
if (!getenv('GROUPS_USE_DEFAULT')) define('FLUENT_SNIPPETS_STORAGE_DIR', $storage);
define('FLUENT_SNIPPETS_PLUGIN_VERSION', '10.58');
function untrailingslashit($s) { return rtrim($s, '/\\'); }
function wp_parse_args($a, $b = []) { return array_merge($b, (array)$a); }
function wp_mkdir_p($p) { return is_dir($p) || @mkdir($p, 0777, true); }
function site_url() { return 'http://groups.test'; }
function content_url($p = '') { return 'http://groups.test/wp-content' . $p; }
function wp_upload_dir() { return ['basedir'=>'/tmp/uploads','baseurl'=>'http://groups.test/uploads']; }
function apply_filters($tag, $v) { return $v; }
function do_action() {}
function is_wp_error($v) { return $v instanceof WP_Error; }
function sanitize_file_name($v) { return preg_replace('/[^a-zA-Z0-9._-]/', '', $v); }
function sanitize_title($v) { return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $v)); }
function sanitize_text_field($v) { return trim(strip_tags($v)); }
function current_time($v) { return date('Y-m-d H:i:s'); }
function get_current_user_id() { return 1; }
function __($s, $domain = '') { return $s; }
function current_user_can($cap) { return isset($GLOBALS['caps']) ? !empty($GLOBALS['caps'][$cap]) : !empty($GLOBALS['allow']); }
function wp_is_file_mod_allowed($context) { return !empty($GLOBALS['allow']); }
function is_multisite() { return false; }
class WP_Error {
    private $code; private $message; private $data;
    function __construct($c = '', $m = '', $d = []) { $this->code=$c; $this->message=$m; $this->data=$d; }
    function get_error_code() { return $this->code; }
    function get_error_message() { return $this->message; }
    function get_error_data() { return $this->data; }
}
class WP_REST_Request {
    private $data;
    function __construct($data) { $this->data=$data; }
    function get_param($name) { return $this->data[$name] ?? null; }
}
foreach (['Helpers/Arr','Helpers/Helper','Model/Snippet','Services/SnippetErrors','Services/Permissions'] as $file) require $base . 'app/' . $file . '.php';
$failures = 0;
function check($label, $ok) {
    global $failures;
    if (!$ok) $failures++;
    echo 'CASE ' . ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
}
$service = $base . 'app/Services/SnippetGroups.php';
if (is_file($service)) require $service;
$legacyModel = new \FluentSnippets\App\Model\Snippet();
$legacyFile=$legacyModel->createSnippet('/* legacy organization */', ['name'=>'Legacy fixture','type'=>'PHP','status'=>'draft','group'=>'Legacy','tags'=>'legacy-tag']);
\FluentSnippets\App\Helpers\Helper::cacheSnippetIndex();
$legacy = $legacyModel->getAllSnippetTagsGroups();
check('legacy labels remain visible', $legacy[1] === ['Legacy'] && $legacy[0] === ['legacy-tag']);
if (!is_file($service)) {
    if (getenv('GROUPS_LEGACY_ONLY')) exit($failures ? 1 : 0);
    check('group organization persists without a snippet', false);
    exit(1);
}
require $base . 'app/Http/Controllers/GroupsController.php';
use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Model\Snippet;
use FluentSnippets\App\Services\SnippetGroups;
use FluentSnippets\App\Http\Controllers\GroupsController;
$GLOBALS['allow'] = true;
function request($operation, $data) { return GroupsController::$operation(new WP_REST_Request($data)); }
function names() { return (new Snippet())->getAllSnippetTagsGroups()[1]; }
check('missing group file is legacy empty state', SnippetGroups::read() === []);
rename($storage.'/'.$legacyFile,$storage.'/legacy.saved'); Helper::cacheSnippetIndex();
check('create empty group', !is_wp_error(request('create', ['name'=>'Empty'])));
check('group organization persists without a snippet', in_array('Empty', names(), true) && $legacyModel->getIndexedSnippets()===[]);
rename($storage.'/legacy.saved',$storage.'/'.$legacyFile); Helper::cacheSnippetIndex();
check('duplicate create refused', is_wp_error(request('create', ['name'=>'Empty'])));
foreach (['', "bad\nname", 'bad*name', '<b>bad</b>', [], null] as $name) check('invalid group label ' . json_encode($name), is_wp_error(request('create', ['name'=>$name])));
foreach (['constructor','__proto__','日本語: group','empty','0'] as $name) check('literal label ' . $name, !is_wp_error(request('create', ['name'=>$name])));
$model = new Snippet();
$files=[];
foreach (['PHP','php_content','js','css'] as $type) {
    $file=$model->createSnippet('/* body stays byte-for-byte */', ['name'=>'Fixture '.$type,'type'=>$type,'status'=>$type==='css'?'published':'draft','group'=>'Source','priority'=>19,'run_at'=>'wp_footer']);
    $files[]=$file;
}
Helper::cacheSnippetIndex();
check('legacy derived source group remains visible', in_array('Source', names(), true));
$before=[];
foreach ($files as $file) $before[$file]=$model->findByFileName($file);
foreach ($files as $file) {
    check('move ' . $file, !is_wp_error(request('move', ['file_name'=>$file,'group'=>'Empty','expected_group'=>'Source'])));
    $after=$model->findByFileName($file);
    check('body preserved ' . $file, $after['code']===$before[$file]['code']);
    foreach (['type','status','priority','run_at','condition','created_at','created_by'] as $key) check('preserve ' . $key . ' ' . $file, $after['meta'][$key]===$before[$file]['meta'][$key]);
}
check('stale move refused', is_wp_error(request('move', ['file_name'=>$files[0],'group'=>'Source','expected_group'=>'Source'])));
check('same destination is harmless', !is_wp_error(request('move', ['file_name'=>$files[0],'group'=>'Empty','expected_group'=>'Empty'])));
check('missing expected source refused', is_wp_error(request('move', ['file_name'=>$files[0],'group'=>'Source'])));
foreach (['../index.php','index.php','groups/index.php','missing.php'] as $file) check('non-snippet refused ' . $file, is_wp_error(request('move', ['file_name'=>$file,'group'=>'Empty','expected_group'=>''])));
check('rename duplicate refused', is_wp_error(request('rename', ['name'=>'Empty','new_name'=>'constructor'])));
check('rename group', !is_wp_error(request('rename', ['name'=>'Empty','new_name'=>'Renamed'])));
foreach ($files as $file) check('rename reaches every type ' . $file, $model->findByFileName($file)['meta']['group']==='Renamed');
check('old rename name removed', !in_array('Empty', names(), true));
check('rename empty group', !is_wp_error(request('rename', ['name'=>'constructor','new_name'=>'Renamed empty'])));
check('move to ungrouped', !is_wp_error(request('move', ['file_name'=>$files[0],'group'=>'','expected_group'=>'Renamed'])));
check('ungrouped membership saved', $model->findByFileName($files[0])['meta']['group']==='');
$config=Helper::getIndexedConfig(false); $config['error_files'][$files[1]]='paused fixture'; Helper::saveIndexedConfig($config);
check('paused snippet can move', !is_wp_error(request('move', ['file_name'=>$files[1],'group'=>'Renamed empty','expected_group'=>'Renamed'])));
check('paused error retained', Helper::getIndexedConfig(false)['error_files'][$files[1]]==='paused fixture');
$snapshot=file_get_contents($storage.'/'.$files[0]);
$GLOBALS['allow']=false;
foreach (['create'=>['name'=>'Denied'],'move'=>['file_name'=>$files[0],'group'=>'Renamed','expected_group'=>''],'rename'=>['name'=>'Renamed','new_name'=>'Denied']] as $op=>$data) check('controller refuses ' . $op, is_wp_error(request($op,$data)));
check('denial leaves snippet unchanged', file_get_contents($storage.'/'.$files[0])===$snapshot);
$GLOBALS['allow']=true;
$GLOBALS['caps']=['install_plugins'=>true,'unfiltered_html'=>false];
check('unfiltered_html is required', is_wp_error(request('create',['name'=>'Denied HTML'])));
unset($GLOBALS['caps']);
$groupFile=$storage.'/groups/index.php';
check('group registry follows configured storage', is_file($groupFile) && Helper::getStorageDir()===$storage);
$groupBytes=file_get_contents($groupFile);
Helper::cacheSnippetIndex();
check('group file survives index rebuild', file_get_contents($groupFile)===$groupBytes);
$racePath=$storage.'/'.$files[0];
$old=file_get_contents($racePath);
Helper::atomicPut($racePath,$old . "\n/* newer edit */");
check('metadata compare refuses a concurrent body edit', Helper::atomicPut($racePath,$old,$old)===false);
check('concurrent body is not overwritten', file_get_contents($racePath)===$old . "\n/* newer edit */");
Helper::atomicPut($racePath,$old);
unlink($storage.'/index.php'); Helper::flushIndexedConfigCache(); Helper::cacheSnippetIndex();
check('empty names survive index deletion', in_array('Renamed empty', names(), true));
file_put_contents($groupFile, '<?php exit; ?>' . "\n" . '{broken');
check('corrupt group file reported', is_wp_error(SnippetGroups::read()));
check('corrupt group file not overwritten', is_wp_error(request('create', ['name'=>'Must not overwrite'])) && strpos(file_get_contents($groupFile),'{broken')!==false);
file_put_contents($groupFile,$groupBytes);
chmod($groupFile,0000);
$unreadable=SnippetGroups::read();
check('unreadable registry explains permissions', is_wp_error($unreadable) && strpos($unreadable->get_error_message(),'permissions')!==false);
chmod($groupFile,0444); chmod(dirname($groupFile),0555);
$unwritable=request('create',['name'=>'Cannot write registry']);
check('registry write failure preserves saved names', is_wp_error($unwritable) && file_get_contents($groupFile)===$groupBytes);
chmod(dirname($groupFile),0755); chmod($groupFile,0644);
// Force one actual write failure without introducing an invalid snippet record.
chmod($storage.'/'.$files[3],0444); chmod($storage,0555);
$result=request('rename',['name'=>'Renamed','new_name'=>'Partial']);
check('partial rename reports a failure', is_wp_error($result));
check('partial rename retains source and target', in_array('Renamed',names(),true) && in_array('Partial',names(),true));
chmod($storage,0755); chmod($storage.'/'.$files[3],0644);
check('partial rename retry finishes', !is_wp_error(request('rename',['name'=>'Renamed','new_name'=>'Partial','retry'=>'yes'])));
foreach ([0,1,2] as $failurePosition) {
    $source='Failure order '.$failurePosition;
    $created=[];
    for($i=0;$i<3;$i++) $created[]=$model->createSnippet('/* failure order '.$i.' */',['name'=>$source.' '.$i,'type'=>'js','status'=>'draft','group'=>$source]);
    Helper::cacheSnippetIndex();
    $index=Helper::getIndexedConfig(false);
    $ordered=array_values(array_intersect(array_keys($index['published']+$index['draft']),$created));
    $failPath=$storage.'/'.$ordered[$failurePosition];
    chmod($failPath,0444); chmod($storage,0555);
    $result=request('rename',['name'=>$source,'new_name'=>$source.' done']);
    chmod($storage,0755); chmod($failPath,0644);
    $data=is_wp_error($result)?$result->get_error_data():[];
    check('rename failure count at position '.$failurePosition, ($data['changed']??null)===2 && ($data['failed']??null)===1);
    check('rename retry at position '.$failurePosition, !is_wp_error(request('rename',['name'=>$source,'new_name'=>$source.' done','retry'=>'yes'])));
    foreach($created as $file) check('retry preserves member '.$file,$model->findByFileName($file)['meta']['group']===$source.' done');
}
for ($i=0;$i<205;$i++) $model->createSnippet('/* offscreen '.$i.' */',['name'=>'Paged '.$i,'type'=>'js','status'=>'draft','group'=>'Paged']);
Helper::cacheSnippetIndex();
check('rename reaches records beyond pagination', !is_wp_error(request('rename',['name'=>'Paged','new_name'=>'All pages'])) && count(array_filter($model->get(),function($s){return $s['meta']['group']==='All pages';}))===205);
$crlf=$model->createSnippet('/* body with marker // <Internal Doc Start> and * */', ['name'=>'CRLF','type'=>'js','status'=>'draft','group'=>'0']);
$raw=str_replace("\n","\r\n",file_get_contents($storage.'/'.$crlf)); file_put_contents($storage.'/'.$crlf,$raw); Helper::cacheSnippetIndex();
check('legacy zero group is available', in_array('0',names(),true));
check('CRLF zero group move succeeds', !is_wp_error(request('move',['file_name'=>$crlf,'group'=>'All pages','expected_group'=>'0'])));
$changed=file_get_contents($storage.'/'.$crlf);
check('metadata-only move preserves raw CRLF body', substr($changed,strpos($changed,'// <Internal Doc End>'))===substr($raw,strpos($raw,'// <Internal Doc End>')));
$beforeIndex=Helper::getIndexedConfig(false);
chmod($storage.'/index.php',0444); chmod($storage,0555);
$result=request('move',['file_name'=>$files[0],'group'=>'All pages','expected_group'=>'']);
check('index write failure is reported after a saved move', is_wp_error($result) && $model->findByFileName($files[0])['meta']['group']==='All pages');
$renameFailure=request('rename',['name'=>'All pages','new_name'=>'Index recovery']);
$renameData=is_wp_error($renameFailure)?$renameFailure->get_error_data():[];
check('rename index failure retains recovery names', !empty($renameData['partial']) && ($renameData['failed']??null)===0 && in_array('All pages',names(),true) && in_array('Index recovery',names(),true));
chmod($storage,0755); chmod($storage.'/index.php',0644); Helper::cacheSnippetIndex();
check('rename index failure can retry after repair', !is_wp_error(request('rename',['name'=>'All pages','new_name'=>'Index recovery','retry'=>'yes'])) && !in_array('All pages',names(),true));
exit($failures ? 1 : 0);
