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
                    'id'    => (string) $term->term_id,
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
                'post_type'   => array_keys($publicPostTypes),
                'numberposts' => 200,
                'post_status' => ['publish', 'draft', 'private'],
                's'           => sanitize_text_field($request->get_param('search')),
                'search_columns' => ['post_title']
            ]);

            $requestValues = $request->get_param('values');

            if(empty($requestValues) || !is_array($requestValues)) {
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
                    'id'    => (string) $post->ID,
                    'title' => $post->post_title,
                ];
            }

            $restIds = array_diff($requestValues, $includedIds);

            if($restIds) {
                $restPosts = get_posts([
                    'post_type' => 'any',
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
                        'id'    => (string) $post->ID,
                        'title' => $post->post_title,
                    ];
                }
            }


            return [
                'options'     => $options,
                'is_cachable' => false,
            ];
        }

        if($optionKey == 'fluentcrm_tags') {
            if(!defined('FLUENTCRM')) {
                return [
                    'options' => [],
                    'is_cachable' => true
                ];
            }

            $tags = \FluentCrm\App\Models\Tag::orderBy('title', 'ASC')->get();
            foreach ($tags as $tag) {
                $options[] = [
                    'id'    => (string) $tag->id,
                    'title' => $tag->title,
                ];
            }

            return [
                'options' => $options,
                'is_cachable' => true
            ];

        }

        if($optionKey == 'fluentcrm_lists') {
            if(!defined('FLUENTCRM')) {
                return [
                    'options' => [],
                    'is_cachable' => true
                ];
            }

            $tags = \FluentCrm\App\Models\Lists::orderBy('title', 'ASC')->get();
            foreach ($tags as $tag) {
                $options[] = [
                    'id'    => (string) $tag->id,
                    'title' => $tag->title,
                ];
            }

            return [
                'options' => $options,
                'is_cachable' => true
            ];

        }

        return [
            'options' => $options
        ];

    }
}
