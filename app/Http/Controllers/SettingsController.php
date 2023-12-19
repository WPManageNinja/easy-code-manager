<?php

namespace FluentSnippets\App\Http\Controllers;


use FluentSnippets\App\Helpers\Arr;
use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Model\Snippet;

class SettingsController
{
    public static function getSettings(\WP_REST_Request $request)
    {
        if ($restricted = self::isBlockedRequest()) {
            return $restricted;
        }

        $config = Helper::getIndexedConfig();

        $defaults = [
            'auto_disable'        => 'yes',
            'auto_publish'        => 'no',
            'remove_on_uninstall' => 'no'
        ];

        if (!$config || !is_array($config) || empty($config['meta'])) {
            $config = $defaults;
        } else {
            $config = \FluentSnippets\App\Helpers\Arr::only($config['meta'], array_keys($defaults));
            $config = array_filter($config);
        }

        $settings = wp_parse_args($config, $defaults);

        return [
            'settings'      => $settings,
            'is_standalone' => defined('FLUENT_SNIPPETS_RUNNING_MU'),
            'secret_url'    => site_url('index.php?fluent_snippets=1&snippet_secret=' . Helper::getSecretKey())
        ];
    }

    public static function saveSettings(\WP_REST_Request $request)
    {
        if ($restricted = self::isBlockedRequest()) {
            return $restricted;
        }

        $settings = $request->get_param('settings');

        if (!is_array($settings)) {
            return new \WP_Error('invalid_settings', 'Invalid settings');
        }

        $defaults = [
            'auto_disable'        => 'yes',
            'auto_publish'        => 'no',
            'remove_on_uninstall' => 'no'
        ];

        $settings = Arr::only($settings, array_keys($defaults));
        $settings = array_filter($settings);

        $config = Helper::getIndexedConfig();

        if (!$config) {
            Helper::cacheSnippetIndex();
        }

        $config = Helper::getIndexedConfig();

        if (!$config) {
            return new \WP_Error('invalid_config', 'Config file could not be generated');
        }

        $config['meta']['auto_disable'] = sanitize_text_field($settings['auto_disable']);
        $config['meta']['auto_publish'] = sanitize_text_field($settings['auto_publish']);
        $config['meta']['remove_on_uninstall'] = sanitize_text_field($settings['remove_on_uninstall']);

        $config = Helper::saveIndexedConfig($config);

        if (is_wp_error($config)) {
            return $config;
        }

        return [
            'message'  => __('Settings has been successfully updated', 'easy-code-manager'),
            'settings' => $settings
        ];
    }

    public static function disableSafeMode(\WP_REST_Request $request)
    {
        if ($restricted = self::isBlockedRequest()) {
            return $restricted;
        }

        $config = Helper::getIndexedConfig();

        if (!$config) {
            return new \WP_Error('invalid_config', 'Config file could not be generated');
        }

        $config['meta']['force_disabled'] = 'no';

        $config = Helper::saveIndexedConfig($config);

        return [
            'message' => __('Safe mode has been disabled', 'easy-code-manager')
        ];
    }

    public static function configStandAloneSystem(\WP_REST_Request $request)
    {
        if ($restricted = self::isBlockedRequest()) {
            return $restricted;
        }

        $isEnable = $request->get_param('enable') == 'yes';

        if ($isEnable == 'yes') {
            $result = Helper::enableStandAlone();
            $message = __('Standalone mode has been activated', 'easy-code-manager');
        } else {
            $message = __('Standalone mode has been deactivated', 'easy-code-manager');
            $result = Helper::disableStandAlone();
        }

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'message'       => $message,
            'is_standalone' => defined('FLUENT_SNIPPETS_RUNNING_MU'),
        ];
    }

    private static function isBlockedRequest()
    {
        if (current_user_can('unfiltered_html') && current_user_can('manage_options')) {
            return false;
        }

        return new \WP_Error('invalid_request', 'You do not have permission to perform this action. Required Permission: unfiltered_html & manage_options');
    }

    public static function getRestOptions(\WP_REST_Request $request)
    {
        $optionKey = $request->get_param('rest_key');
        $options = [];

        if ($optionKey == 'tax_term_groups') {
            // Get public taxonomies
            $taxonomies = get_taxonomies([
                'public' => true
            ]);

            $taxonomies = array_keys($taxonomies);
            $terms = get_terms([
                'taxonomy'        => $taxonomies,
                'suppress_filter' => true,
                'hide_empty'      => false,
                'number'          => 9000
            ]);

            foreach ($terms as $term) {
                if (!isset($formattedTaxGroups[$term->taxonomy])) {
                    $options[$term->taxonomy] = [
                        'label'   => $term->taxonomy,
                        'options' => [],
                    ];
                }

                $options[$term->taxonomy]['options'][] = [
                    'id'    => (string)$term->term_id,
                    'title' => $term->name,
                ];
            }

            return [
                'options'     => $options,
                'is_cachable' => true,
            ];
        }

        if ($optionKey == 'post_cpt_groups') {

            $publicPostTypes = get_post_types([
                'public' => true
            ]);

            $posts = get_posts([
                'post_type'      => array_keys($publicPostTypes),
                'numberposts'    => 200,
                'post_status'    => ['publish', 'draft', 'private'],
                's'              => sanitize_text_field($request->get_param('search')),
                'search_columns' => ['post_title']
            ]);

            $requestValues = $request->get_param('values');

            if (empty($requestValues) || !is_array($requestValues)) {
                $requestValues = [];
            }

            $includedIds = [];

            foreach ($posts as $post) {
                if (!isset($options[$post->post_type])) {
                    $options[$post->post_type] = [
                        'label'   => ucfirst($post->post_type),
                        'options' => [],
                    ];
                }

                $includedIds[] = $post->ID;

                $options[$post->post_type]['options'][] = [
                    'id'    => (string)$post->ID,
                    'title' => $post->post_title,
                ];
            }

            $restIds = array_diff($requestValues, $includedIds);
            $restIds = array_filter($restIds, 'is_int');
            
            if ($restIds) {
                $restPosts = get_posts([
                    'post_type'   => 'any',
                    'numberposts' => 200,
                    'post_status' => ['publish', 'draft', 'private'],
                    'post__in'    => $restIds
                ]);

                foreach ($restPosts as $post) {
                    if (!isset($options[$post->post_type])) {
                        $options[$post->post_type] = [
                            'label'   => ucfirst($post->post_type),
                            'options' => [],
                        ];
                    }

                    $options[$post->post_type]['options'][] = [
                        'id'    => (string)$post->ID,
                        'title' => $post->post_title,
                    ];
                }
            }


            return [
                'options'     => $options,
                'is_cachable' => false,
            ];
        }

        if ($optionKey == 'fluentcrm_tags') {
            if (!defined('FLUENTCRM')) {
                return [
                    'options'     => [],
                    'is_cachable' => true
                ];
            }

            $tags = \FluentCrm\App\Models\Tag::orderBy('title', 'ASC')->get();
            foreach ($tags as $tag) {
                $options[] = [
                    'id'    => (string)$tag->id,
                    'title' => $tag->title,
                ];
            }

            return [
                'options'     => $options,
                'is_cachable' => true
            ];

        }

        if ($optionKey == 'fluentcrm_lists') {
            if (!defined('FLUENTCRM')) {
                return [
                    'options'     => [],
                    'is_cachable' => true
                ];
            }

            $tags = \FluentCrm\App\Models\Lists::orderBy('title', 'ASC')->get();
            foreach ($tags as $tag) {
                $options[] = [
                    'id'    => (string)$tag->id,
                    'title' => $tag->title,
                ];
            }

            return [
                'options'     => $options,
                'is_cachable' => true
            ];

        }

        return [
            'options' => $options
        ];

    }

    public static function installPlugin(\WP_REST_Request $request)
    {
        $pluginSlug = $request->get_param('plugin_slug');
        $plugin = [
            'name'      => $pluginSlug,
            'repo-slug' => $pluginSlug,
            'file'      => $pluginSlug . '.php'
        ];

        $UrlMaps = [
            'fluent-smtp'  => [
                'admin_url' => admin_url('options-general.php?page=fluent-mail#/'),
                'title'     => __('Go to FluentSMTP Dashboard', 'fluent-smtp')
            ],
            'fluentform'   => [
                'admin_url' => admin_url('admin.php?page=fluent_forms'),
                'title'     => __('Go to Fluent Forms Dashboard', 'fluent-smtp')
            ],
            'fluent-crm'   => [
                'admin_url' => admin_url('admin.php?page=fluentcrm-admin'),
                'title'     => __('Go to FluentCRM Dashboard', 'fluent-smtp')
            ],
            'ninja-tables' => [
                'admin_url' => admin_url('admin.php?page=ninja_tables#/'),
                'title'     => __('Go to Ninja Tables Dashboard', 'fluent-smtp')
            ]
        ];

        if (!isset($UrlMaps[$pluginSlug]) || (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS)) {

            return new \WP_Error('permission_error', 'Sorry, You can not install this plugin');
        }

        try {
            self::backgroundInstaller($plugin);
            return [
                'message' => __('Plugin has been successfully installed.', 'fluent-smtp'),
                'info'    => $UrlMaps[$pluginSlug]
            ];
        } catch (\Exception $exception) {
            return new \WP_Error('install_error', $exception->getMessage());
        }
    }

    private static function backgroundInstaller($plugin_to_install)
    {
        if (!empty($plugin_to_install['repo-slug'])) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/plugin.php';

            WP_Filesystem();

            $skin = new \Automatic_Upgrader_Skin();
            $upgrader = new \WP_Upgrader($skin);
            $installed_plugins = array_reduce(array_keys(\get_plugins()), array(__CLASS__, 'associate_plugin_file'), array());
            $plugin_slug = $plugin_to_install['repo-slug'];
            $plugin_file = isset($plugin_to_install['file']) ? $plugin_to_install['file'] : $plugin_slug . '.php';
            $installed = false;
            $activate = false;

            // See if the plugin is installed already.
            if (isset($installed_plugins[$plugin_file])) {
                $installed = true;
                $activate = !is_plugin_active($installed_plugins[$plugin_file]);
            }

            // Install this thing!
            if (!$installed) {
                // Suppress feedback.
                ob_start();

                try {
                    $plugin_information = plugins_api(
                        'plugin_information',
                        array(
                            'slug'   => $plugin_slug,
                            'fields' => array(
                                'short_description' => false,
                                'sections'          => false,
                                'requires'          => false,
                                'rating'            => false,
                                'ratings'           => false,
                                'downloaded'        => false,
                                'last_updated'      => false,
                                'added'             => false,
                                'tags'              => false,
                                'homepage'          => false,
                                'donate_link'       => false,
                                'author_profile'    => false,
                                'author'            => false,
                            ),
                        )
                    );

                    if (is_wp_error($plugin_information)) {
                        throw new \Exception($plugin_information->get_error_message());
                    }

                    $package = $plugin_information->download_link;
                    $download = $upgrader->download_package($package);

                    if (is_wp_error($download)) {
                        throw new \Exception($download->get_error_message());
                    }

                    $working_dir = $upgrader->unpack_package($download, true);

                    if (is_wp_error($working_dir)) {
                        throw new \Exception($working_dir->get_error_message());
                    }

                    $result = $upgrader->install_package(
                        array(
                            'source'                      => $working_dir,
                            'destination'                 => WP_PLUGIN_DIR,
                            'clear_destination'           => false,
                            'abort_if_destination_exists' => false,
                            'clear_working'               => true,
                            'hook_extra'                  => array(
                                'type'   => 'plugin',
                                'action' => 'install',
                            ),
                        )
                    );

                    if (is_wp_error($result)) {
                        throw new \Exception($result->get_error_message());
                    }

                    $activate = true;

                } catch (\Exception $e) {
                    throw new \Exception($e->getMessage());
                }

                // Discard feedback.
                ob_end_clean();
            }

            wp_clean_plugins_cache();

            // Activate this thing.
            if ($activate) {
                try {
                    $result = activate_plugin($installed ? $installed_plugins[$plugin_file] : $plugin_slug . '/' . $plugin_file);

                    if (is_wp_error($result)) {
                        throw new \Exception($result->get_error_message());
                    }
                } catch (\Exception $e) {
                    throw new \Exception($e->getMessage());
                }
            }
        }
    }

    public static function associate_plugin_file($plugins, $key)
    {
        $path = explode('/', $key);
        $filename = end($path);
        $plugins[$filename] = $key;
        return $plugins;
    }
}
