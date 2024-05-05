<?php

namespace FluentSnippets\App\Hooks\Handlers;

use FluentSnippets\App\Helpers\Arr;
use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Model\Snippet;
use FluentSnippets\App\Services\Trans;

class AdminMenuHandler
{
    public function register()
    {
        add_action('admin_menu', array($this, 'addMenu'));
    }

    public function addMenu()
    {
        $permission = 'install_plugins';

        add_menu_page(
            __('Fluent Snippets', 'easy-code-manager'),
            __('FluentSnippets', 'easy-code-manager'),
            $permission,
            'fluent-snippets',
            array($this, 'render'),
            $this->getMenuIcon(),
            120
        );
    }

    public function render()
    {
        $currentUser = wp_get_current_user();

        [$tags, $groups] = (new Snippet())->getAllSnippetTagsGroups();

        add_action('wp_print_scripts', function () {

            $isSkip = apply_filters('fluent_snippets/skip_no_conflict', false);

            if ($isSkip) {
                return;
            }

            global $wp_scripts;
            if (!$wp_scripts) {
                return;
            }

            $approvedSlugs = apply_filters('fluent_snippets_asset_listed_slugs', [
                '\/gutenberg\/'
            ]);

            $approvedSlugs[] = 'easy-code-manager';

            $approvedSlugs = array_unique($approvedSlugs);

            $approvedSlugs = implode('|', $approvedSlugs);

            $pluginUrl = plugins_url();

            $pluginUrl = str_replace(['http:', 'https:'], '', $pluginUrl);

            foreach ($wp_scripts->queue as $script) {
                if (empty($wp_scripts->registered[$script]) || empty($wp_scripts->registered[$script]->src)) {
                    continue;
                }

                $src = $wp_scripts->registered[$script]->src;
                $isMatched = (strpos($src, $pluginUrl) !== false) && !preg_match('/' . $approvedSlugs . '/', $src);
                if (!$isMatched) {
                    continue;
                }
                wp_dequeue_script($wp_scripts->registered[$script]->handle);
            }
        });

        wp_enqueue_script('fluent_snippets_app', FLUENT_SNIPPETS_PLUGIN_URL . 'dist/app.js', ['jquery'], FLUENT_SNIPPETS_PLUGIN_VERSION, true);

        $indexConfig = Helper::getIndexedConfig();

        if (!$indexConfig) {
            $indexConfig = Helper::getIndexedConfig();
        }

        add_filter('admin_footer_text', function ($content) use ($indexConfig) {
            $ext = '';
            if (defined('FLUENT_SNIPPETS_RUNNING_MU')) {
                $ext = '<b>Standalone (MU Mode) is active</b> ';
            }

            return 'Thank you for using <a rel="noopener"  target="_blank" href="https://fluentsnippets.com">Fluent Snippets</a>.' . ' ' . $ext;
        });

        wp_localize_script('fluent_snippets_app', 'fluentSnippetAdmin', [
            'slug'                       => 'fluent-snippets',
            'nonce'                      => wp_create_nonce('fluent-snippets'),
            'rest'                       => [
                'base_url'  => esc_url_raw(rest_url()),
                'url'       => rest_url('fluent-snippets'),
                'nonce'     => wp_create_nonce('wp_rest'),
                'namespace' => 'fluent-snippets',
                'version'   => '1'
            ],
            'asset_url'                  => FLUENT_SNIPPETS_PLUGIN_URL . 'dist/',
            'me'                         => [
                'id'        => $currentUser->ID,
                'full_name' => trim($currentUser->first_name . ' ' . $currentUser->last_name),
                'email'     => $currentUser->user_email
            ],
            'i18n'                       => Trans::getStrings(),
            'tags'                       => $tags,
            'groups'                     => $groups,
            'safeModes'                  => $this->getSafeModeInfo(),
            'is_standalone'              => defined('FLUENT_SNIPPETS_RUNNING_MU'),
            'advanced_condition_options' => $this->getAdvancedConditionOptions(),
            'snippet_types'              => $this->getSnippetTypes(),
            'has_fluentsmtp'           => defined('FLUENTMAIL_PLUGIN_FILE'),
            'has_fluentcrm'           => defined('FLUENTCRM'),
            'has_fluentform'          => defined('FLUENTFORM'),
            'has_ninja_tables'       => defined('NINJA_TABLES_VERSION'),
            'disable_recommendation' => apply_filters('fluentmail_disable_recommendation', false),
        ]);

        echo '<div id="fluent_snippets_app"><h3 style="text-align: center; margin-top: 100px;">' . __('Loading Snippets..', 'easy-code-manager') . '</h3></div>';
    }

    public function getSafeModeInfo()
    {
        $config = Helper::getIndexedConfig();

        return [
            'is_defined_disabled'  => defined('FLUENT_SNIPPETS_SAFE_MODE') && FLUENT_SNIPPETS_SAFE_MODE,
            'is_filtered_disabled' => !apply_filters('fluent_snippets/run_snippets', true),
            'is_forced_disabled'   => Arr::get($config, 'meta.force_disabled') == 'yes'
        ];
    }

    public function getSnippetTypes()
    {
        return apply_filters('fluent_snippets/snippet_types', [
            'PHP'         => [
                'label'             => __('Functions', 'easy-code-manager'),
                'value'             => 'PHP',
                'inline_tag'        => 'PHP',
                'running_locations' => [
                    'all'      => [
                        'label'       => __('Run Everywhere', 'easy-code-manager'),
                        'description' => __('Snippet gets executed everywhere (both frontend and admin area)', 'easy-code-manager')
                    ],
                    'backend'  => [
                        'label'       => __('Admin Only', 'easy-code-manager'),
                        'description' => __('Snippet gets executed only in admin area (/wp-admin/)', 'easy-code-manager')
                    ],
                    'frontend' => [
                        'label'       => __('Frontend Only', 'easy-code-manager'),
                        'description' => __('Snippet gets executed only in frontend area', 'easy-code-manager')
                    ]
                ]
            ],
            'php_content' => [
                'label'             => __('Content', 'easy-code-manager'),
                'value'             => 'php_content',
                'inline_tag'        => 'PHP + HTML',
                'running_locations' => [
                    'shortcode'      => [
                        'label'       => __('Shortcode', 'easy-code-manager'),
                        'description' => __('Only display when inserted into a post or page using shortcode', 'easy-code-manager')
                    ],
                    'wp_head'        => [
                        'label'       => __('Site Wide Header', 'easy-code-manager'),
                        'description' => __('Insert snippet between the head tags of your website (frontend).', 'easy-code-manager')
                    ],
                    'wp_body_open'   => [
                        'label'       => __('Site Wide Body Open', 'easy-code-manager'),
                        'description' => __('Insert snippet after the opening body tag of your website (frontend).', 'easy-code-manager')
                    ],
                    'wp_footer'      => [
                        'label'       => __('Site Wide Footer', 'easy-code-manager'),
                        'description' => __('Insert snippet before the closing body tag of your website on all pages (frontend).', 'easy-code-manager')
                    ],
                    'before_content' => [
                        'label'       => __('Before Content', 'easy-code-manager'),
                        'description' => __('Insert snippet at the beginning of single post/page content.', 'easy-code-manager')
                    ],
                    'after_content'  => [
                        'label'       => __('After Content', 'easy-code-manager'),
                        'description' => __('Insert snippet at the end of single post/page content.', 'easy-code-manager')
                    ]
                ]
            ],
            'css'         => [
                'label'             => __('Styles', 'easy-code-manager'),
                'value'             => 'css',
                'inline_tag'        => 'CSS',
                'running_locations' => [
                    'wp_head'    => [
                        'label'       => __('Frontend', 'easy-code-manager'),
                        'description' => __('Add CSS on all pages (frontend).', 'easy-code-manager')
                    ],
                    'admin_head' => [
                        'label'       => __('Backend', 'easy-code-manager'),
                        'description' => __('Apply this snippet CSS to backend (/wp-admin/).', 'easy-code-manager')
                    ],
                    'everywhere' => [
                        'label'       => __('Both Backend and Frontend', 'easy-code-manager'),
                        'description' => __('Apply this snippet CSS to both backend and frontend.', 'easy-code-manager')
                    ]
                ]
            ],
            'js'          => [
                'label'             => __('Scripts', 'easy-code-manager'),
                'value'             => 'js',
                'inline_tag'        => 'JS',
                'running_locations' => [
                    'wp_head'   => [
                        'label'       => __('Site Wide Header', 'easy-code-manager'),
                        'description' => __('Run Javascript between the head tags of your website on all pages (frontend).', 'easy-code-manager')
                    ],
                    'wp_footer' => [
                        'label'       => __('Site Wide Footer', 'easy-code-manager'),
                        'description' => __('Run Javascript before the closing body tag of your website on all pages (frontend).', 'easy-code-manager')
                    ],
                    'admin_head' => [
                        'label'       => __('Admin Area Header', 'easy-code-manager'),
                        'description' => __('Run Javascript in admin area (/wp-admin/).', 'easy-code-manager')
                    ],
                    'admin_footer' => [
                        'label'       => __('Admin Area Footer', 'easy-code-manager'),
                        'description' => __('Run Javascript in admin area (/wp-admin/) before the closing body tag.', 'easy-code-manager')
                    ]
                ]
            ],
        ]);
    }

    private function getAdvancedConditionOptions()
    {
        $postTypes = get_post_types(array('public' => true), 'objects');

        $formattedPostTypes = array();
        foreach ($postTypes as $postType) {
            $formattedPostTypes[$postType->name] = $postType->label;
        }

        $taxonomies = get_taxonomies(['public' => true], 'objects');
        $formattedTaxonomies = array();
        foreach ($taxonomies as $taxonomy) {
            if ('post_format' === $taxonomy->name) {
                continue;
            }
            $formattedTaxonomies[$taxonomy->name] = $taxonomy->labels->singular_name;
        }

        $options = [
            'user' => [
                'label'    => __('User', 'easy-code-manager'),
                'value'    => 'user',
                'children' => [
                    [
                        'label'       => __('Logged-in', 'easy-code-manager'),
                        'value'       => 'authenticated',
                        'type'        => 'single_assert_option',
                        'is_multiple' => false,
                        'options'     => [
                            'yes' => 'True',
                            'no'  => 'False'
                        ]
                    ],
                    [
                        'label'             => __('User Role', 'easy-code-manager'),
                        'value'             => 'role',
                        'is_multiple'       => true,
                        'is_singular_value' => true,
                        'type'              => 'selections',
                        'options'           => Helper::getUserRoles()
                    ]
                ],
            ],
            'page' => [
                'label'    => __('Page', 'easy-code-manager'),
                'value'    => 'page',
                'children' => [
                    [
                        'label'             => __('Type of page', 'easy-code-manager'),
                        'value'             => 'page_type',
                        'type'              => 'selections',
                        'is_multiple'       => true,
                        'is_singular_value' => true,
                        'options'           => array(
                            'is_front_page' => __('Homepage', 'easy-code-manager'),
                            'is_archive'    => __('Archive', 'easy-code-manager'),
                            'is_singular'   => __('Single Post/Page/CPT', 'easy-code-manager'),
                            'is_search'     => __('Search page', 'easy-code-manager'),
                            'is_404'        => __('404 page', 'easy-code-manager'),
                            'is_author'     => __('Author page', 'easy-code-manager')
                        )
                    ],
                    [
                        'label'       => __('Post Type', 'easy-code-manager'),
                        'value'       => 'post_type',
                        'type'        => 'selections',
                        'is_multiple' => true,
                        'options'     => $formattedPostTypes
                    ],
                    [
                        'label'       => __('Taxonomy Page', 'easy-code-manager'),
                        'value'       => 'taxonomy_page',
                        'type'        => 'selections',
                        'is_multiple' => true,
                        'options'     => $formattedTaxonomies
                    ],
                    [
                        'label'       => __('Taxonomy Term Page', 'easy-code-manager'),
                        'value'       => 'taxonomy_term_page',
                        'type'        => 'rest_selections',
                        'is_multiple' => true,
                        'rest_key'    => 'tax_term_groups',
                        'is_grouped'  => true
                    ],
                    [
                        'label' => __('URL', 'easy-code-manager'),
                        'value' => 'url',
                        'type'  => 'text'
                    ],
                    [
                        'label'       => __('Single Post/Page/CPT', 'easy-code-manager'),
                        'value'       => 'page_ids',
                        'type'        => 'rest_selections',
                        'is_multiple' => true,
                        'rest_key'    => 'post_cpt_groups',
                        'is_grouped'  => true,
                        'show_id'     => true
                    ]
                ]
            ],
            'date' => [
                'label'    => __('Date', 'easy-code-manager'),
                'value'    => 'date',
                'is_pro'   => true,
                'children' => [
                    [
                        'label'        => __('Date Range', 'easy-code-manager'),
                        'value'        => 'date_range',
                        'type'         => 'dates',
                        'date_type'    => 'datetimerange',
                        'is_range'     => true,
                        'value_format' => 'YYYY-MM-DD HH:mm'
                    ],
                    [
                        'label'        => __('Time Range', 'easy-code-manager'),
                        'value'        => 'time_range',
                        'type'         => 'time_range',
                        'is_range'     => true,
                        'value_format' => 'HH:mm:ss'
                    ],
                    [
                        'label'             => __('Day of the week', 'easy-code-manager'),
                        'value'             => 'day_of_week',
                        'type'              => 'selections',
                        'options'           => [
                            'mon' => __('Monday', 'easy-code-manager'),
                            'tue' => __('Tuesday', 'easy-code-manager'),
                            'wed' => __('Wednesday', 'easy-code-manager'),
                            'thu' => __('Thursday', 'easy-code-manager'),
                            'fri' => __('Friday', 'easy-code-manager'),
                            'sat' => __('Saturday', 'easy-code-manager'),
                            'sun' => __('Sunday', 'easy-code-manager'),
                        ],
                        'is_multiple'       => true,
                        'is_singular_value' => true,
                    ]
                ]
            ]
        ];

        if (defined('FLUENTCRM')) {
            $options['fluentcrm'] = [
                'label'    => __('FluentCRM', 'easy-code-manager'),
                'value'    => 'fluentcrm',
                'children' => [
                    [
                        'label'       => __('Is a CRM Contact?', 'easy-code-manager'),
                        'value'       => 'exists',
                        'type'        => 'single_assert_option',
                        'is_multiple' => false,
                        'options'     => [
                            'yes' => 'True',
                            'no'  => 'False'
                        ]
                    ],
                    [
                        'label'       => __('Contact Tags', 'easy-code-manager'),
                        'value'       => 'tags_ids',
                        'type'        => 'rest_selections',
                        'is_multiple' => true,
                        'rest_key'    => 'fluentcrm_tags',
                        'is_grouped'  => false,
                        'show_id'     => false
                    ],
                    [
                        'label'       => __('Contact Lists', 'easy-code-manager'),
                        'value'       => 'tags_list_ids',
                        'type'        => 'rest_selections',
                        'is_multiple' => true,
                        'rest_key'    => 'fluentcrm_lists',
                        'is_grouped'  => false,
                        'show_id'     => false
                    ]
                ],
            ];
        }

        return array_values($options);

    }

    private function getMenuIcon()
    {
        return 'data:image/svg+xml;base64,' . base64_encode('<svg width="202" height="204" viewBox="0 0 202 204" fill="none" xmlns="http://www.w3.org/2000/svg">
<path d="M182.746 23.08C182.746 23.08 171.696 19.83 140.156 11.7C106.776 3.1 91.3961 0 91.3961 0C91.3961 0 75.9961 3.1 42.6361 11.7C11.1061 19.83 0.0561151 23.08 0.0561151 23.08C0.0561151 23.08 -1.57389 55.58 12.7361 114.42C24.2761 161.92 67.3461 188.21 91.3961 203.1C115.456 188.21 158.516 161.92 170.066 114.42C184.366 55.58 182.746 23.08 182.746 23.08Z" fill="white" fill-opacity="0.21"/>
<path d="M46.4413 87.5247L46.4413 87.5247C45.6523 88.3139 45.2091 89.3841 45.2091 90.5C45.2091 91.6159 45.6523 92.6861 46.4413 93.4753L46.4413 93.4754L61.2716 108.306C61.6592 108.706 62.1225 109.026 62.6346 109.246C63.148 109.466 63.7002 109.582 64.259 109.587C64.8178 109.592 65.3719 109.485 65.8891 109.274C66.4063 109.062 66.8762 108.75 67.2713 108.355C67.6665 107.96 67.9789 107.49 68.1905 106.972C68.4021 106.455 68.5086 105.901 68.5038 105.342C68.4989 104.784 68.3828 104.231 68.1623 103.718C67.9423 103.206 67.6228 102.743 67.2223 102.355L55.3674 90.5L67.2223 78.6451C67.6228 78.2575 67.9423 77.7942 68.1623 77.2821C68.3828 76.7687 68.4989 76.2165 68.5038 75.6577C68.5086 75.0989 68.4021 74.5447 68.1905 74.0276C67.9789 73.5104 67.6665 73.0405 67.2713 72.6454C66.8762 72.2502 66.4063 71.9378 65.8891 71.7262C65.3719 71.5146 64.8178 71.4081 64.259 71.4129C63.7002 71.4178 63.148 71.5339 62.6346 71.7544C62.1225 71.9744 61.6593 72.2939 61.2717 72.6943L46.4413 87.5247ZM126.559 93.4754L126.559 93.4753C127.348 92.6861 127.791 91.6159 127.791 90.5C127.791 89.3841 127.348 88.3139 126.559 87.5247L126.559 87.5247L111.731 72.6975C111.73 72.6964 111.729 72.6953 111.728 72.6943C111.341 72.2938 110.877 71.9744 110.365 71.7544C109.852 71.5339 109.3 71.4178 108.741 71.4129C108.182 71.4081 107.628 71.5146 107.111 71.7262C106.594 71.9378 106.124 72.2502 105.729 72.6454C105.334 73.0405 105.021 73.5104 104.809 74.0276C104.598 74.5447 104.491 75.0989 104.496 75.6577C104.501 76.2165 104.617 76.7687 104.838 77.2821C105.058 77.7941 105.377 78.2574 105.778 78.6449C105.779 78.646 105.78 78.6471 105.781 78.6481L117.633 90.5L105.781 102.352C105.78 102.353 105.779 102.354 105.778 102.355C105.377 102.743 105.058 103.206 104.838 103.718C104.617 104.231 104.501 104.784 104.496 105.342C104.491 105.901 104.598 106.455 104.809 106.972C105.021 107.49 105.334 107.96 105.729 108.355C106.124 108.75 106.594 109.062 107.111 109.274C107.628 109.485 108.182 109.592 108.741 109.587C109.3 109.582 109.852 109.466 110.365 109.246C110.877 109.026 111.341 108.706 111.728 108.306C111.729 108.305 111.73 108.304 111.731 108.302L126.559 93.4754ZM77.9258 120.506L77.9304 120.508C78.3052 120.611 78.692 120.665 79.0808 120.667H79.0837C79.9982 120.666 80.8877 120.367 81.6174 119.816C82.3471 119.265 82.8773 118.491 83.1279 117.611L97.9587 65.7038C98.1319 65.1684 98.196 64.6037 98.1472 64.0431C98.0981 63.4793 97.9359 62.9312 97.6701 62.4316C97.4043 61.932 97.0404 61.4911 96.6003 61.1353C96.1602 60.7796 95.6529 60.5162 95.1087 60.3611C94.5644 60.2059 93.9945 60.1621 93.4329 60.2322C92.8714 60.3024 92.3298 60.4851 91.8405 60.7694C91.3512 61.0538 90.9242 61.4339 90.5852 61.887C90.2481 62.3375 90.0048 62.851 89.8697 63.397L75.0389 115.305C74.7324 116.377 74.8644 117.528 75.4057 118.503C75.947 119.479 76.8535 120.199 77.9258 120.506Z" fill="white" style="mix-blend-mode:darken"/>
<path d="M46.4413 87.5247L46.4413 87.5247C45.6523 88.3139 45.2091 89.3841 45.2091 90.5C45.2091 91.6159 45.6523 92.6861 46.4413 93.4753L46.4413 93.4754L61.2716 108.306C61.6592 108.706 62.1225 109.026 62.6346 109.246C63.148 109.466 63.7002 109.582 64.259 109.587C64.8178 109.592 65.3719 109.485 65.8891 109.274C66.4063 109.062 66.8762 108.75 67.2713 108.355C67.6665 107.96 67.9789 107.49 68.1905 106.972C68.4021 106.455 68.5086 105.901 68.5038 105.342C68.4989 104.784 68.3828 104.231 68.1623 103.718C67.9423 103.206 67.6228 102.743 67.2223 102.355L55.3674 90.5L67.2223 78.6451C67.6228 78.2575 67.9423 77.7942 68.1623 77.2821C68.3828 76.7687 68.4989 76.2165 68.5038 75.6577C68.5086 75.0989 68.4021 74.5447 68.1905 74.0276C67.9789 73.5104 67.6665 73.0405 67.2713 72.6454C66.8762 72.2502 66.4063 71.9378 65.8891 71.7262C65.3719 71.5146 64.8178 71.4081 64.259 71.4129C63.7002 71.4178 63.148 71.5339 62.6346 71.7544C62.1225 71.9744 61.6593 72.2939 61.2717 72.6943L46.4413 87.5247ZM126.559 93.4754L126.559 93.4753C127.348 92.6861 127.791 91.6159 127.791 90.5C127.791 89.3841 127.348 88.3139 126.559 87.5247L126.559 87.5247L111.731 72.6975C111.73 72.6964 111.729 72.6953 111.728 72.6943C111.341 72.2938 110.877 71.9744 110.365 71.7544C109.852 71.5339 109.3 71.4178 108.741 71.4129C108.182 71.4081 107.628 71.5146 107.111 71.7262C106.594 71.9378 106.124 72.2502 105.729 72.6454C105.334 73.0405 105.021 73.5104 104.809 74.0276C104.598 74.5447 104.491 75.0989 104.496 75.6577C104.501 76.2165 104.617 76.7687 104.838 77.2821C105.058 77.7941 105.377 78.2574 105.778 78.6449C105.779 78.646 105.78 78.6471 105.781 78.6481L117.633 90.5L105.781 102.352C105.78 102.353 105.779 102.354 105.778 102.355C105.377 102.743 105.058 103.206 104.838 103.718C104.617 104.231 104.501 104.784 104.496 105.342C104.491 105.901 104.598 106.455 104.809 106.972C105.021 107.49 105.334 107.96 105.729 108.355C106.124 108.75 106.594 109.062 107.111 109.274C107.628 109.485 108.182 109.592 108.741 109.587C109.3 109.582 109.852 109.466 110.365 109.246C110.877 109.026 111.341 108.706 111.728 108.306C111.729 108.305 111.73 108.304 111.731 108.302L126.559 93.4754ZM77.9258 120.506L77.9304 120.508C78.3052 120.611 78.692 120.665 79.0808 120.667H79.0837C79.9982 120.666 80.8877 120.367 81.6174 119.816C82.3471 119.265 82.8773 118.491 83.1279 117.611L97.9587 65.7038C98.1319 65.1684 98.196 64.6037 98.1472 64.0431C98.0981 63.4793 97.9359 62.9312 97.6701 62.4316C97.4043 61.932 97.0404 61.4911 96.6003 61.1353C96.1602 60.7796 95.6529 60.5162 95.1087 60.3611C94.5644 60.2059 93.9945 60.1621 93.4329 60.2322C92.8714 60.3024 92.3298 60.4851 91.8405 60.7694C91.3512 61.0538 90.9242 61.4339 90.5852 61.887C90.2481 62.3375 90.0048 62.851 89.8697 63.397L75.0389 115.305C74.7324 116.377 74.8644 117.528 75.4057 118.503C75.947 119.479 76.8535 120.199 77.9258 120.506Z" stroke="white"/>
</svg>');
    }
}
