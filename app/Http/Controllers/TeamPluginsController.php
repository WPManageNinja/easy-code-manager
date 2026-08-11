<?php

namespace FluentSnippets\App\Http\Controllers;

use FluentSnippets\App\Services\TeamPlugins;

/**
 * Installing and activating the other free plugins by the same team, from the card at
 * the bottom of the About screen.
 *
 * Both routes are thin on purpose: the capability checks, the slug allowlist and the
 * download all live in TeamPlugins, because the same rules have to hold wherever they
 * are called from. What is here is the translation between a request and that service.
 */
class TeamPluginsController
{
    public static function installPlugin(\WP_REST_Request $request)
    {
        $slug = sanitize_key($request->get_param('slug'));

        if (!$slug) {
            return new \WP_Error('no_plugin_specified', __('No plugin was specified.', 'easy-code-manager'));
        }

        $installed = TeamPlugins::install($slug);

        if (is_wp_error($installed)) {
            return $installed;
        }

        /*
         * One click was offered as "Install & Activate", so activation is part of the
         * same request rather than a second round trip - the browser cannot show a
         * half-finished state it was never promised.
         *
         * A failure here is reported as a success with the state attached, not as an
         * error: the plugin genuinely is installed, and saying otherwise would send the
         * user off to reinstall something that is already on disk. The card reads the
         * returned state and offers Activate.
         */
        $activated = TeamPlugins::activate($slug);

        if (is_wp_error($activated)) {
            return [
                'plugin'  => $installed,
                'message' => sprintf(
                    /* translators: %s: the reason activation failed. */
                    __('The plugin was installed, but could not be activated: %s', 'easy-code-manager'),
                    $activated->get_error_message()
                )
            ];
        }

        return [
            'plugin'  => $activated,
            'message' => __('The plugin has been installed and activated.', 'easy-code-manager')
        ];
    }

    public static function activatePlugin(\WP_REST_Request $request)
    {
        $slug = sanitize_key($request->get_param('slug'));

        if (!$slug) {
            return new \WP_Error('no_plugin_specified', __('No plugin was specified.', 'easy-code-manager'));
        }

        $activated = TeamPlugins::activate($slug);

        if (is_wp_error($activated)) {
            return $activated;
        }

        return [
            'plugin'  => $activated,
            'message' => __('The plugin has been activated.', 'easy-code-manager')
        ];
    }
}
