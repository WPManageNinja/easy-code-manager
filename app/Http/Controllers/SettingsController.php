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
            'message'  => __('Settings has been successfully updated', 'fluent-snippets'),
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
            'message' => __('Safe mode has been disabled', 'fluent-snippets')
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
            $message = __('Standalone mode has been activated', 'fluent-snippets');
        } else {
            $message = __('Standalone mode has been deactivated', 'fluent-snippets');
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
        $optionKet = $request->get_param('rest_key');
        $options = [];

        if ($optionKet == 'tax_term_groups') {
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
                    'id'    => $term->term_id,
                    'title' => $term->name,
                ];
            }

            return [
                'options' => $options,
                'is_cachable' => true,
            ];
        }

        return [
            'options' => $options
        ];

    }
}
