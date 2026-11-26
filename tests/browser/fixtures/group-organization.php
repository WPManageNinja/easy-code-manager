<?php
/** Synthetic draft for groups.js. Only a writable, local WP-CLI test installation. */
if (!defined('WP_CLI') || !WP_CLI) exit;

use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Model\Snippet;

$host = parse_url(home_url(), PHP_URL_HOST);
if ((!in_array($host, ['localhost', '127.0.0.1'], true) && substr($host, -5) !== '.test') || !current_user_can('install_plugins') || !current_user_can('unfiltered_html')) {
    throw new RuntimeException('Use a writable local fixture, not a protected or remote installation.');
}
$model = new Snippet();
$matches = array_values(array_filter($model->get(), function ($snippet) { return $snippet['meta']['name'] === 'WPMN 13596 A'; }));
if (count($matches) > 1) throw new RuntimeException('Duplicate synthetic fixture names; use a fresh test installation.');
if ($matches) {
    $snippet = $matches[0];
    if ($snippet['meta']['type'] !== 'js' || $snippet['status'] !== 'draft' || $snippet['code'] !== '/* WPMN 13596 legacy A */') {
        throw new RuntimeException('Existing fixture differs; it will not be overwritten.');
    }
    $file = basename($snippet['file']);
} else {
    $file = $model->createSnippet('/* WPMN 13596 legacy A */', [
        'name'=>'WPMN 13596 A', 'type'=>'js', 'status'=>'draft',
        'group'=>'WPMN 13596 Source', 'run_at'=>'wp_footer', 'priority'=>17,
    ]);
    if (is_wp_error($file)) throw new RuntimeException('Could not create synthetic draft.');
    Helper::cacheSnippetIndex();
}
if (!preg_match('/^[a-z0-9_-]+\.php$/i', $file)) throw new RuntimeException('Unexpected synthetic fixture filename.');
echo wp_json_encode(['file'=>$file]) . PHP_EOL;
