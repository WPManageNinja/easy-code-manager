<?php

/*
 * Init Direct Classes Here
 */
(new \FluentSnippets\App\Hooks\Handlers\AdminMenuHandler())->register();
(new \FluentSnippets\App\Hooks\Handlers\CodeHandler())->register();

add_action('wp', function () {
    if(!isset($_REQUEST['t'])) {
        return;
    }

    $object = get_queried_object();

    // Get public taxonomies
    $taxonomies = get_taxonomies([
        'public' => true
    ]);

    $taxonomies = array_keys($taxonomies);
    $terms = get_terms([
        'taxonomy' => $taxonomies,
        'suppress_filter' => true,
        'hide_empty' => false,
        'number' => 9000
    ]);

    $formattedTaxGroups = [];

    foreach ($terms as $term) {
        if(!isset($formattedTaxGroups[$term->taxonomy])) {
            $formattedTaxGroups[$term->taxonomy] = [
                'label' => $term->taxonomy,
                'options' => [],
            ];
        }

        $formattedTaxGroups[$term->taxonomy]['options'][] = [
            'id' => $term->term_id,
            'label' => $term->name,
        ];
    }

    dd($formattedTaxGroups);

});
