<?php
/**
 * Run against an active Fluent Snippets installation:
 * wp eval-file tests/integration/taxonomy-term-options.php --user=<administrator>
 *
 * Supply a deterministic term query result without changing any site data.
 */
$request = new WP_REST_Request('GET', '/fluent-snippets/settings/options');
$request->set_param('rest_key', 'tax_term_groups');

$tagA = (object) ['taxonomy' => 'post_tag', 'term_id' => 11, 'name' => 'Alpha'];
$tagB = (object) ['taxonomy' => 'post_tag', 'term_id' => 12, 'name' => 'Beta'];
$category = (object) ['taxonomy' => 'category', 'term_id' => 13, 'name' => 'News'];
$tagOptions = [['id' => '11', 'title' => 'Alpha'], ['id' => '12', 'title' => 'Beta']];
$newsGroup = ['label' => 'category', 'options' => [['id' => '13', 'title' => 'News']]];
$cases = [
    'grouped route with data set interleaved' => [[$tagA, $category, $tagB], [
        'post_tag' => ['label' => 'post_tag', 'options' => $tagOptions],
        'category' => $newsGroup,
    ]],
    'grouped route with data set reversed' => [[$tagB, $category, $tagA], [
        'post_tag' => ['label' => 'post_tag', 'options' => array_reverse($tagOptions)],
        'category' => $newsGroup,
    ]],
    'empty and singleton with data set single term' => [[$category], ['category' => $newsGroup]],
    'empty and singleton with data set empty query' => [[], []],
];

$failed = false;
foreach ($cases as $name => $case) {
    list($terms, $expected) = $case;
    $fixture = static function ($result, $query) use ($terms) {
        return $query->query_vars['number'] == 9000 ? $terms : $result;
    };
    add_filter('terms_pre_query', $fixture, 10, 2);
    try {
        $response = rest_do_request($request);
    } finally {
        remove_filter('terms_pre_query', $fixture, 10);
    }

    if ($response->get_status() !== 200) {
        WP_CLI::error('The administrator must have access to the condition builder.');
    }
    if ($response->get_data() !== ['options' => $expected, 'is_cachable' => true]) {
        echo "CASE FAIL $name -- taxonomy group lost terms or changed its response shape\n";
        $failed = true;
    } else {
        echo "CASE PASS $name\n";
    }
}
if ($failed) {
    WP_CLI::halt(1);
}
