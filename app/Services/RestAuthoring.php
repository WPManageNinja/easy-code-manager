<?php

namespace FluentSnippets\App\Services;

use FluentSnippets\App\Helpers\Arr;
use FluentSnippets\App\Helpers\Helper;

/**
 * Whether snippets may be created and edited over the REST API, and who may do it.
 *
 * The editor never comes through here - it saves over admin-ajax. These routes exist for
 * integrations: deploy scripts, site managers, anything authenticating with an
 * Application Password. That is also why they are off until someone turns them on.
 * Core's REST API lets an administrator's Application Password install plugins from the
 * wordpress.org directory, but never upload arbitrary PHP. A snippet route does exactly
 * that, so enabling it changes what a leaked Application Password can do to the site,
 * and the administrator should make that call knowingly rather than inherit it.
 *
 * Status changes and deletion stay outside this switch on purpose. The editor calls both
 * over REST, neither puts new code on the site, and gating them would break integrations
 * that already rely on them.
 *
 * FLUENT_SNIPPETS_REST_AUTHORING in wp-config.php overrides the setting in either
 * direction, for hosts and agencies that manage this in configuration rather than in a
 * screen anybody with manage_options can change.
 */
class RestAuthoring
{
    const CONSTANT = 'FLUENT_SNIPPETS_REST_AUTHORING';

    /**
     * @return bool
     */
    public static function isEnabled()
    {
        $locked = self::lockedValue();

        if ($locked !== null) {
            return $locked;
        }

        return Arr::get(Helper::getIndexedConfig(), 'meta.rest_authoring') === 'yes';
    }

    /**
     * What wp-config.php has decided, or null if it has not.
     *
     * @return bool|null
     */
    public static function lockedValue()
    {
        if (!defined(self::CONSTANT)) {
            return null;
        }

        return (bool)constant(self::CONSTANT);
    }

    /**
     * permission_callback for the authoring routes.
     *
     * Everything the controller would otherwise have to refuse is refused here, before
     * the request body is even looked at, and each refusal says which of the four it was.
     * The controllers check the capabilities again anyway (see SnippetsController), so
     * loosening this cannot open a write path on its own.
     *
     * @return true|\WP_Error
     */
    public static function permissionCheck()
    {
        if (!is_user_logged_in()) {
            return new \WP_Error('rest_not_logged_in', __('You need to be signed in to add or edit snippets. Use an Application Password.', 'easy-code-manager'), [
                'status' => 401
            ]);
        }

        if (!self::isEnabled()) {
            $how = self::lockedValue() === false
                ? __('It is turned off in wp-config.php (FLUENT_SNIPPETS_REST_AUTHORING), so it can only be changed there.', 'easy-code-manager')
                : __('An admin can turn it on in FluentSnippets → Settings. See https://fluentsnippets.com/docs/rest-api', 'easy-code-manager');

            return new \WP_Error('fluent_snippets_rest_authoring_disabled', __('Adding and editing snippets through the REST API is turned off on this site.', 'easy-code-manager') . ' ' . $how, [
                'status' => 403
            ]);
        }

        if (!wp_is_file_mod_allowed('capability_update_core')) {
            return new \WP_Error('fluent_snippets_file_mods_disabled', __('Snippets can\'t be changed on this site because DISALLOW_FILE_MODS is set in wp-config.php.', 'easy-code-manager'), [
                'status' => 403
            ]);
        }

        if (!current_user_can('install_plugins') || !current_user_can('unfiltered_html')) {
            return new \WP_Error('rest_forbidden', __('You need the install_plugins and unfiltered_html permissions to add or edit snippets.', 'easy-code-manager'), [
                'status' => rest_authorization_required_code()
            ]);
        }

        return true;
    }

    /**
     * Who made a REST write, for the fluent_snippets/rest_snippet_written action.
     *
     * The Application Password's UUID is what lets a log tell "the admin, in a browser"
     * apart from "the deploy key the admin created last year".
     *
     * @return array{user_id: int, app_password_uuid: string|null}
     */
    public static function actor()
    {
        $uuid = function_exists('rest_get_authenticated_app_password') ? rest_get_authenticated_app_password() : null;

        return [
            'user_id'           => get_current_user_id(),
            'app_password_uuid' => $uuid ?: null,
        ];
    }
}
