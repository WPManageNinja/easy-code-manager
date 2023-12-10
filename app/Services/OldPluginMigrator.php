<?php

namespace FluentSnippets\App\Services;

class OldPluginMigrator
{
    public static function getOldSnippets()
    {
        if (!self::hasOldPlugin()) {
            return [];
        }

        global $wpdb;
        $codeBlocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}cjtoolbox_blocks");

        $validBlocks = [];

        foreach ($codeBlocks as $codeBlock) {
            $snippets = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}cjtoolbox_block_files WHERE blockId = {$codeBlock->id}");

            if (!$snippets) {
                continue;
            }

            $data = [
                'priority' => 10,
                'type'     => 'php_content',
                'status'   => ($codeBlock->state == 'active') ? 'published' : 'draft',
                'name'     => $codeBlock->name,
                'run_at'   => ($codeBlock->location == 'header') ? 'wp_head' : 'wp_footer'
            ];

            $code = '';
            foreach ($snippets as $snippet) {

                if ($snippet->description) {
                    $data['description'] = $snippet->description;
                }

                if ($snippet->tag && strpos($snippet->tag, '%s') !== false) {
                    $snippet->code = str_replace('%s', ' ' . $snippet->code . ' ', $snippet->tag);
                }
                $code .= $snippet->code . PHP_EOL . PHP_EOL;
            }

            $data['condition'] = self::getBlockConditions($codeBlock->id);

            if ($codeBlock->pinPoint) {
                $data['condition'] = self::getAuxilaryConditions($codeBlock->pinPoint, $data['condition']);
            }

            $validBlocks[] = [
                'meta' => $data,
                'code' => $code
            ];
        }

        return $validBlocks;
    }

    public static function hasOldPlugin()
    {
        return !!get_option('ecm_db_version');
    }

    private static function getBlockConditions($blockId)
    {
        global $wpdb;
        $conditions = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}cjtoolbox_block_pins WHERE blockId = {$blockId}");

        if (!$conditions) {
            return [
                'status' => 'no',
                'run_if' => 'assertive',
                'items'  => [[]]
            ];
        }

        $formattedConditions = [];

        $translates = [
            'pages'      => 'page_ids',
            'posts'      => 'page_ids',
            'categories' => 'taxonomy_term_page',
            'post_tags'  => 'taxonomy_term_page',
        ];

        foreach ($conditions as $condition) {

            if (isset($translates[$condition->pin])) {
                $condition->pin = $translates[$condition->pin];
            }

            if (!isset($formattedConditions[$condition->pin])) {
                $formattedConditions[$condition->pin] = [];
            }

            $formattedConditions[$condition->pin][] = $condition->value;
        }


        foreach ($formattedConditions as $key => $value) {
            $formattedConditions[$key] = [
                [
                    'source'   => ['page', $key],
                    'operator' => 'in',
                    'value'    => $value
                ]
            ];
        }

        $formattedConditions = array_values($formattedConditions);

        $conditions = [
            'status' => 'yes',
            'run_if' => 'assertive',
            'items'  => $formattedConditions
        ];

        return $conditions;
    }

    private static function getOldTables()
    {
        return [
            'cjtoolbox_authors',
            'cjtoolbox_backups',
            'cjtoolbox_block_files',
            'cjtoolbox_block_pins',
            'cjtoolbox_block_templates',
            'cjtoolbox_blocks',
            'cjtoolbox_form_group_parameters',
            'cjtoolbox_form_group_xfields',
            'cjtoolbox_form_groups',
            'cjtoolbox_forms',
            'cjtoolbox_package_objects',
            'cjtoolbox_packages',
            'cjtoolbox_parameter_typedef',
            'cjtoolbox_parameter_typeparams',
            'cjtoolbox_parameters',
            'cjtoolbox_plus_block_pintype',
            'cjtoolbox_template_revisions',
            'cjtoolbox_templates'
        ];
    }

    private static function getAuxilaryConditions($pinValue, $conditions)
    {
        if (!defined('ECM_PLUS_FILE')) {
            return $conditions;
        }

        $items = [
            'FRONTEND'                  => 0x01,
            'BACKEND'                   => 0x02,
            'PAGES_ALL_PAGES'           => 0x10,
            'PAGES_FRONT_PAGE'          => 0x40,
            'POSTS_ALL_POSTS'           => 0x100,
            'POSTS_RECENT'              => 0x400,
            'POSTS_BLOG_INDEX'          => 0x800,
            'TAGS_CUSTOM_TAG'           => 0x4000,
            'CATEGORIES_ALL_CATEGORIES' => 0x1000,
            'SEARCH'                    => 0x10000,
            'ARCHIVE'                   => 0x20000,
            'TAG'                       => 0x40000,
            'AUTHOR'                    => 0x80000,
            'ATTACHMENT'                => 0x100000,
            'ERROR_404'                 => 0x200000,
            'LINKS'                     => 0x1000000,
            'EXPRESSIONS'               => 0x2000000,
            'LINK_EXPRESSION'           => 0x3000000,
        ];

        $result = [];
        foreach ($items as $key => $value) {
            if ($pinValue & $value) {
                $result[] = $key;
            }
        }

        if (!$result) {
            return [];
        }

        if (in_array('FRONTEND', $result)) {
            return [
                'status' => 'no',
                'run_if' => 'assertive',
                'items'  => [[]]
            ];
        }

        if (in_array('PAGES_ALL_PAGES', $result)) {
            $conditions['status'] = 'yes';
            $conditions['items'][] = [
                [
                    'source'   => ['page', 'post_type'],
                    'operator' => 'in',
                    'value'    => ['page']
                ]
            ];
        }

        if (in_array('POSTS_ALL_POSTS', $result)) {
            $conditions['status'] = 'yes';
            $conditions['items'][] = [
                [
                    'source'   => ['page', 'post_type'],
                    'operator' => 'in',
                    'value'    => ['post']
                ]
            ];
        }

        if (in_array('CATEGORIES_ALL_CATEGORIES', $result)) {
            $conditions['status'] = 'yes';
            $conditions['items'][] = [
                [
                    'source'   => ['page', 'taxonomy_page'],
                    'operator' => 'in',
                    'value'    => ['category']
                ]
            ];
        }

        if (in_array('SEARCH', $result)) {
            $conditions['status'] = 'yes';
            $conditions['items'][] = [
                [
                    'source'   => ['page', 'page_type'],
                    'operator' => 'in',
                    'value'    => ['is_search']
                ]
            ];
        }

        if (in_array('ARCHIVE', $result)) {
            $conditions['status'] = 'yes';
            $conditions['items'][] = [
                [
                    'source'   => ['page', 'page_type'],
                    'operator' => 'in',
                    'value'    => ['is_archive']
                ]
            ];
        }

        if (in_array('AUTHOR', $result)) {
            $conditions['status'] = 'yes';
            $conditions['items'][] = [
                [
                    'source'   => ['page', 'page_type'],
                    'operator' => 'in',
                    'value'    => ['is_author']
                ]
            ];
        }

        if (in_array('ERROR_404', $result)) {
            $conditions['status'] = 'yes';
            $conditions['items'][] = [
                [
                    'source'   => ['page', 'page_type'],
                    'operator' => 'in',
                    'value'    => ['is_404']
                ]
            ];
        }

        return $conditions;
    }
}
