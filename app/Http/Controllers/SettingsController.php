<?php

namespace FluentSnippets\App\Http\Controllers;


use FluentSnippets\App\Helpers\Arr;
use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Model\Snippet;

class SettingsController
{
    public static function getSettings(\WP_REST_Request $request)
    {
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
            'settings' => $settings
        ];
    }

    public static function saveSettings(\WP_REST_Request $request)
    {
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

}
