<?php

namespace FluentSnippets\App\Hooks\Handlers;

use FluentSnippets\App\Helpers\Arr;
use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Model\Snippet;

class AdminMenuHandler
{
    public function register()
    {
        add_action('admin_menu', array($this, 'addMenu'));
    }

    public function addMenu()
    {
        $permission = 'manage_options';

        add_menu_page(
            __('Fluent Snippets', 'fluent-snippets'),
            __('FluentSnippets', 'fluent-snippets'),
            $permission,
            'fluent-snippets',
            array($this, 'render'),
            $this->getMenuIcon(),
            120
        );

    }

    public function render()
    {

        add_filter('admin_footer_text', function ($content) {
            return 'Thank you for using <a rel="noopener"  target="_blank" href="https://fluentauth.com">Fluent Snippets</a> | Write a <a target="_blank" rel="noopener" href="https://wordpress.org/support/plugin/fluent-snippets/reviews/?filter=5">review for Fluent Snippets</a>';
        });

        $currentUser = wp_get_current_user();

        [$tags, $groups] = (new Snippet())->getAllSnippetTagsGroups();

        wp_enqueue_script('fluent_snippets_app', FLUENT_SNIPPETS_PLUGIN_URL . 'dist/app.js', ['jquery'], '1.0', true);

        wp_localize_script('fluent_snippets_app', 'fluentSnippetAdmin', [
            'slug'      => 'fluent-snippets',
            'nonce'     => wp_create_nonce('fluent-snippets'),
            'rest'      => [
                'base_url'  => esc_url_raw(rest_url()),
                'url'       => rest_url('fluent-snippets'),
                'nonce'     => wp_create_nonce('wp_rest'),
                'namespace' => 'fluent-snippets',
                'version'   => '1'
            ],
            'asset_url' => FLUENT_SNIPPETS_PLUGIN_URL . 'dist/',
            'me'        => [
                'id'        => $currentUser->ID,
                'full_name' => trim($currentUser->first_name . ' ' . $currentUser->last_name),
                'email'     => $currentUser->user_email
            ],
            'i18n'      => [
                'Dashboard' => __('Dashboard', 'fluent-security'),
            ],
            'tags'      => $tags,
            'groups'    => $groups,
            'safeModes' => $this->getSafeModeInfo()
        ]);

        echo '<div id="fluent_snippets_app"><h3 style="text-align: center; margin-top: 100px;">Loading Snippets..</h3></div>';
    }

    public function getSafeModeInfo()
    {
        $config = Helper::getIndexedConfig();

        return [
            'is_defined_disabled' => defined('FLUENT_SNIPPETS_SAFE_MODE') && FLUENT_SNIPPETS_SAFE_MODE,
            'is_filtered_disabled' => !apply_filters('fluent_snippets/run_snippets', true),
            'is_forced_disabled' => Arr::get($config, 'meta.force_disabled') == 'yes'
        ];
    }

    private function getMenuIcon()
    {
        return 'dashicons-editor-code';
        // return 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 182.8 203.1"><defs><style>.cls-1{fill:#fff;}</style></defs><g id="Layer_2" data-name="Layer 2"><g id="Layer_1-2" data-name="Layer 1"><path class="cls-1" d="M182.75,23.08S171.7,19.83,140.16,11.7C106.78,3.1,91.4,0,91.4,0S76,3.1,42.64,11.7C11.11,19.83.06,23.08.06,23.08s-1.63,32.5,12.68,91.34c11.54,47.5,54.61,73.79,78.66,88.68,24.06-14.89,67.12-41.18,78.67-88.68C184.37,55.58,182.75,23.08,182.75,23.08ZM90.89,125.68,39.63,139.41V128a17,17,0,0,1,12.58-16.39l62.3-16.71A31.9,31.9,0,0,1,90.89,125.68Zm46.66-50.45L39.63,101.46V90a17,17,0,0,1,12.58-16.4l109-29.2A31.94,31.94,0,0,1,137.55,75.23Z"/></g></g></svg>');
    }
}
