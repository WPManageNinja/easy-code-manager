<?php

namespace FluentSnippets\App\Http\Controllers;

use FluentSnippets\App\Helpers\Arr;
use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Model\Snippet;

class SnippetsController
{
    /**
     * Heal an index that has drifted from the files on disk.
     *
     * The admin app calls this once when it boots, instead of getSnippets() doing it
     * on every list load, search keystroke and pagination click. Returns whether a
     * rebuild happened so the app only re-fetches the list when it would differ.
     */
    public static function syncIndex(\WP_REST_Request $request)
    {
        return [
            'changed' => Helper::syncSnippetIndex()
        ];
    }

    public static function getSnippets(\WP_REST_Request $request)
    {
        $snippetModel = new Snippet([
            'search'     => sanitize_text_field($request->get_param('search')),
            'type'       => sanitize_text_field($request->get_param('type')),
            'tag'        => sanitize_text_field($request->get_param('tag')),
            'sort_by'    => sanitize_text_field($request->get_param('sort_by')),
            'sort_order' => strtolower(sanitize_text_field($request->get_param('sort_order'))),
        ]);

        $perPage = $request->get_param('per_page');
        $page = $request->get_param('page');

        if (!$perPage) {
            $perPage = 10;
        }

        if (!$page) {
            $page = 1;
        }

        $data = [
            'snippets' => $snippetModel->getIndexedSnippets($perPage, $page),
            'time'     => current_time('mysql')
        ];

        if ($page == 1) {
            [$tags, $groups] = (new Snippet())->getAllSnippetTagsGroups();
            $data['tags'] = $tags;
            $data['groups'] = $groups;
        }

        return $data;
    }

    public static function findSnippet(\WP_REST_Request $request)
    {
        $snippetName = sanitize_file_name($request->get_param('snippet_name'));

        $snippetModel = new Snippet();
        $snippet = $snippetModel->findByFileName($snippetName);

        if (is_wp_error($snippet)) {
            return $snippet;
        }

        $snippet['file_name'] = basename($snippet['file']);

        if ($snippet['meta']['type'] == 'PHP') {
            // Remove Beginning php tag
            $snippet['code'] = preg_replace('/^<\?php/', '', $snippet['code']);
            // remove new line at the very first
            $snippet['code'] = ltrim($snippet['code'], PHP_EOL);
        }

        $config = Helper::getIndexedConfig();

        if (!empty($config['error_files']) && !empty($config['error_files'][$snippet['file_name']])) {
            $snippet['error'] = $config['error_files'][$snippet['file_name']];
        }

        return [
            'snippet' => $snippet
        ];
    }

    public static function createSnippet(\WP_REST_Request $request)
    {
        if ($restricted = self::denyUnlessCanAuthorSnippets()) {
            return $restricted;
        }

        // json_decode returns null on malformed input, and indexing that warns before
        // failing further down with nothing useful to say (M9).
        $meta = json_decode($request->get_param('meta'), true);

        if (!is_array($meta)) {
            return new \WP_Error('invalid_meta', __('Snippet meta could not be read', 'easy-code-manager'));
        }

        $code = Arr::get($meta, 'code', '');

        unset($meta['code']);

        $snippet = Helper::createSnippet([
            'meta' => $meta,
            'code' => $code
        ]);

        if (is_wp_error($snippet)) {
            return $snippet;
        }

        return [
            'snippet' => $snippet,
            'message' => __('Snippet created successfully', 'easy-code-manager')
        ];
    }

    public static function updateSnippet(\WP_REST_Request $request)
    {
        if ($restricted = self::denyUnlessCanAuthorSnippets()) {
            return $restricted;
        }

        $fileName = sanitize_file_name($request->get_param('fluent_saving_snippet_name'));

        $meta = json_decode($request->get_param('meta'), true);

        if (!is_array($meta)) {
            return new \WP_Error('invalid_meta', __('Snippet meta could not be read', 'easy-code-manager'));
        }

        $code = Arr::get($meta, 'code', '');
        unset($meta['code']);

        $snippet = Helper::updateSnippet([
            'meta'       => $meta,
            'code'       => $code,
            'file_name'  => $fileName,
            'reactivate' => $request->get_param('reactivate')
        ]);

        if (is_wp_error($snippet)) {
            return $snippet;
        }

        return [
            'snippet' => $snippet,
            'message' => 'Snippet updated successfully'
        ];
    }

    public static function updateSnippetStatus(\WP_REST_Request $request)
    {
        if ($restricted = self::denyUnlessCanAuthorSnippets()) {
            return $restricted;
        }

        $fileName = sanitize_file_name($request->get_param('fluent_saving_snippet_name'));
        $status = sanitize_text_field($request->get_param('status'));

        $snippetModel = new Snippet();
        $snippet = $snippetModel->findByFileName($fileName);

        if (is_wp_error($snippet)) {
            return $snippet;
        }

        if ($status != 'published') {
            $status = 'draft';
        }

        $snippet['meta']['status'] = $status;

        $snippetName = $snippetModel->updateSnippet($fileName, $snippet['code'], $snippet['meta']);

        do_action('fluent_snippets/snippet_status_updated', $snippetName);
        do_action('fluent_snippets/snippet_updated', $snippetName);

        return [
            'snippet' => $snippet,
            'message' => 'Snippet status updated successfully'
        ];
    }

    public static function deleteSnippet(\WP_REST_Request $request)
    {
        if ($restricted = self::denyUnlessCanAuthorSnippets()) {
            return $restricted;
        }

        $fileName = sanitize_file_name($request->get_param('fluent_saving_snippet_name'));

        $snippetModel = new Snippet();
        $snippet = $snippetModel->findByFileName($fileName);

        if (is_wp_error($snippet)) {
            return $snippet;
        }

        $snippetModel->deleteSnippet($fileName);

        do_action('fluent_snippets/snippet_deleted', $fileName);

        return [
            'message' => __('Snippet has been deleted successfully', 'easy-code-manager')
        ];
    }

    /**
     * Kept as an entry point because AdminMenuHandler's import calls it, but the rules
     * live in Helper::validateMeta(). This was a byte-for-byte duplicate of that method,
     * which is exactly how the two drift apart (L7).
     */
    public static function validateMeta($meta)
    {
        return Helper::validateMeta($meta);
    }

    /**
     * Guard for anything that writes, publishes or removes a snippet — all of which
     * amount to putting code on the site.
     *
     * Route registration already requires install_plugins; this is the second half.
     * Deliberately NOT the same test as SettingsController::denyUnlessCanManageSettings(),
     * which also wants manage_options. The two used to share the name isBlockedRequest(),
     * which made the difference look accidental.
     */
    private static function denyUnlessCanAuthorSnippets()
    {
        if (current_user_can('unfiltered_html')) {
            return false;
        }

        return new \WP_Error('invalid_request', 'You do not have permission to perform this action. Required Permission: unfiltered_html');
    }
}
