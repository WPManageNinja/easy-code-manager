<?php

namespace FluentSnippets\App\Http\Controllers;

use FluentSnippets\App\Model\Snippet;
use FluentSnippets\App\Services\Permissions;
use FluentSnippets\App\Services\SnippetGroups;

class GroupsController
{
    public static function index(\WP_REST_Request $request)
    {
        if (!Permissions::canView()) return new \WP_Error('rest_forbidden', __('You cannot view snippet groups.', 'easy-code-manager'), ['status' => 403]);
        $names = SnippetGroups::read();
        if (is_wp_error($names)) return $names;
        return ['groups' => (new Snippet())->getAllSnippetTagsGroups()[1]];
    }

    private static function guard()
    {
        if (!Permissions::canEdit() || !current_user_can('unfiltered_html') || !wp_is_file_mod_allowed('capability_update_core')) {
            return new \WP_Error('rest_forbidden', __('You cannot change snippet groups on this site.', 'easy-code-manager'), ['status' => 403]);
        }
        return null;
    }

    public static function create(\WP_REST_Request $request)
    {
        if ($error = self::guard()) return $error;
        return SnippetGroups::create($request->get_param('name'));
    }

    public static function move(\WP_REST_Request $request)
    {
        if ($error = self::guard()) return $error;
        return SnippetGroups::move($request->get_param('file_name'), $request->get_param('group'), $request->get_param('expected_group'));
    }

    public static function rename(\WP_REST_Request $request)
    {
        if ($error = self::guard()) return $error;
        return SnippetGroups::rename($request->get_param('name'), $request->get_param('new_name'), $request->get_param('retry') === 'yes');
    }
}
