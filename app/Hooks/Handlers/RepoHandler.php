<?php

namespace FluentSnippets\App\Hooks\Handlers;

use FluentSnippets\App\Helpers\Arr;

class RepoHandler
{
    public function register()
    {
        add_filter('fluent_snippets/snippet_types', function ($types) {

            if(apply_filters('fluent_snippet/allow_php', false)) {
                return $types;
            }

            unset($types['PHP']);
            $types['php_content']['inline_tag'] = 'HTML CONTENT';
            return $types;
        });

        add_action('admin_footer', function () {
            if (Arr::get($_GET, 'page') != 'fluent-snippets' || apply_filters('fluent_snippet/allow_php', false)) {
                return false;
            }
            ?>
            <style>
                .fsnip_code_php_content .cm-editor::before {
                    content: "<!-- begin content (HTML) -->" !important;
                }
            </style>

            <?php
        });

        add_filter('fluent_snippets/sanitize_mixed_content', function ($code) {

            if(apply_filters('fluent_snippet/allow_php', false)) {
                return $code;
            }

            // don't allow php tags. Check if there has any php opening tags
            if (strpos($code, '<?php') !== false || strpos($code, '<?=') !== false) {
                return new \WP_Error('invalid_code', 'PHP tags are not allowed in this snippet type.');
            }
            return $code;
        });
    }
}
