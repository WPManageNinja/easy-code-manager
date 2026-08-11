<?php

namespace FluentSnippets\App\Http\Controllers;


use FluentSnippets\App\Helpers\Arr;
use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Model\Snippet;

class SettingsController
{
    public static function getSettings(\WP_REST_Request $request)
    {
        if ($restricted = self::denyUnlessCanManageSettings()) {
            return $restricted;
        }

        $config = Helper::getIndexedConfig();

        // enable_line_wrap defaulted to 'yes' here while saveSettings() and the editor
        // bootstrap in AdminMenuHandler::render() both defaulted to 'no'. The editor is
        // what actually decides the behaviour, so the settings screen was showing the
        // toggle on while wrapping was off. Aligned to 'no'; the editor is unchanged.
        $defaults = [
            'auto_disable'        => 'yes',
            'auto_publish'        => 'no',
            'remove_on_uninstall' => 'no',
            'enable_line_wrap'    => 'no'
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
            'secret_url'    => Helper::getSafeModeUrl()
        ];
    }

    /**
     * Issue a new Safe Mode URL and invalidate the one before it.
     *
     * Behind denyUnlessCanChangeSettings() like every other write here: the key lives in
     * the generated index.php, so replacing it is a file modification.
     */
    public static function regenerateSecretUrl(\WP_REST_Request $request)
    {
        if ($restricted = self::denyUnlessCanChangeSettings()) {
            return $restricted;
        }

        $url = Helper::regenerateSecretKey();

        if (is_wp_error($url)) {
            return $url;
        }

        return [
            'message'    => __('A new Safe Mode URL has been generated. The previous one no longer works.', 'easy-code-manager'),
            'secret_url' => $url
        ];
    }

    public static function saveSettings(\WP_REST_Request $request)
    {
        if ($restricted = self::denyUnlessCanChangeSettings()) {
            return $restricted;
        }

        $settings = $request->get_param('settings');

        if (!is_array($settings)) {
            return new \WP_Error('invalid_settings', 'Invalid settings');
        }

        $defaults = [
            'auto_disable'        => 'yes',
            'auto_publish'        => 'no',
            'remove_on_uninstall' => 'no',
            'enable_line_wrap'    => 'no'

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
        $config['meta']['enable_line_wrap'] = sanitize_text_field($settings['enable_line_wrap']);

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
        if ($restricted = self::denyUnlessCanChangeSettings()) {
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
        if ($restricted = self::denyUnlessCanChangeSettings()) {
            return $restricted;
        }

        $isEnable = $request->get_param('enable') == 'yes';

        // $isEnable is already a bool; the old `$isEnable == 'yes'` compared a bool to a
        // string and happened to work under loose comparison (L8).
        if ($isEnable) {
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

    /**
     * Guard for reading the settings surface: plugin-wide behaviour, safe mode,
     * standalone mode, and the kill-switch URL. Stricter than
     * SnippetsController::denyUnlessCanAuthorSnippets() on purpose — it also wants
     * manage_options.
     *
     * Reading is all this covers. Everything that saves goes through
     * denyUnlessCanChangeSettings() below, so the settings screen still opens on a site
     * where file modifications are off — the toggles are just not yours to move.
     */
    private static function denyUnlessCanManageSettings()
    {
        if (current_user_can('unfiltered_html') && current_user_can('manage_options')) {
            return false;
        }

        return new \WP_Error('invalid_request', 'You do not have permission to perform this action. Required Permission: unfiltered_html & manage_options');
    }

    /**
     * Guard for changing any of it.
     *
     * Every setting here ends up written to a file in wp-content — the index config, the
     * must-use plugin that standalone mode installs — so this is a write in the sense
     * DISALLOW_FILE_MODS means, and install_plugins is the capability that says so.
     */
    private static function denyUnlessCanChangeSettings()
    {
        if ($restricted = self::denyUnlessCanManageSettings()) {
            return $restricted;
        }

        if (current_user_can('install_plugins')) {
            return false;
        }

        return new \WP_Error('invalid_request', 'You do not have permission to perform this action. Required Permission: install_plugins');
    }

    public static function getRestOptions(\WP_REST_Request $request)
    {
        /*
         * This was the one method here without the guard. It returns titles of draft and
         * private posts across every public post type, plus every taxonomy term — content
         * an install_plugins user can already reach, so nothing was exposed that should
         * not have been. It only serves the condition builder on the snippet edit screen,
         * which is unusable without the capabilities below anyway.
         */
        if ($restricted = self::denyUnlessCanManageSettings()) {
            return $restricted;
        }

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
}
