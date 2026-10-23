<?php

namespace FluentSnippets\App\Http\Controllers;

use FluentSnippets\App\Helpers\Arr;
use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Model\Snippet;
use FluentSnippets\App\Services\RestAuthoring;

class SnippetsController
{
    /**
     * Well past any real snippet. Here so a runaway request is refused with a reason
     * instead of being test-run and written to wp-content.
     */
    const MAX_CODE_BYTES = 1048576;

    /**
     * The meta a REST caller may set. Authorship and timestamps are not on it: those come
     * from the file and from whoever is authenticated, never from the request body.
     */
    const AUTHORABLE_META = [
        'name',
        'description',
        'tags',
        'group',
        'type',
        'status',
        'run_at',
        'priority',
        'condition',
        'load_as_file',
        'load_in_block_editor',
    ];

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
        return self::presentSnippet(sanitize_file_name($request->get_param('snippet_name')));
    }

    /**
     * Create a snippet over REST. Off unless remote authoring is enabled - see
     * Services/RestAuthoring, which is this route's permission_callback.
     *
     * Takes the same input the pre-10.56 route did (`meta` as a JSON string with `code`
     * inside it) and also `code` and `meta` as separate fields of a JSON body. Either way
     * it goes through Helper::createSnippet(), the same validation and save the editor
     * uses, so the new snippet starts as a draft unless "Publish new snippets straight
     * away" is on - exactly as it would from the editor.
     */
    public static function createSnippet(\WP_REST_Request $request)
    {
        if ($restricted = self::denyUnlessCanAuthorSnippets()) {
            return $restricted;
        }

        $meta = self::requestMeta($request);

        if (is_wp_error($meta)) {
            return $meta;
        }

        $code = self::takeCode($request, $meta);

        if ($code === null) {
            return new \WP_Error('missing_code', __('No code was sent. Pass the snippet body as `code`.', 'easy-code-manager'), ['status' => 400]);
        }

        if ($tooLarge = self::refuseOversizedCode($code)) {
            return $tooLarge;
        }

        $meta = Arr::only($meta, self::AUTHORABLE_META);

        if ($malformed = self::refuseMalformedMeta($meta)) {
            return $malformed;
        }

        // validateMeta() insists on a status even though createSnippet() then decides it
        // for itself, so an integration should not have to send one just to have it
        // ignored.
        if (empty($meta['status'])) {
            $meta['status'] = 'draft';
        }

        $fileName = Helper::createSnippet([
            'meta' => $meta,
            'code' => $code
        ]);

        if (is_wp_error($fileName)) {
            return $fileName;
        }

        do_action('fluent_snippets/rest_snippet_written', $fileName, 'create', RestAuthoring::actor());

        $response = self::presentSnippet($fileName);

        if (!is_wp_error($response)) {
            $response['message'] = __('Snippet created successfully', 'easy-code-manager');
        }

        return $response;
    }

    /**
     * Change an existing snippet in place over REST.
     *
     * The contract, for integrations:
     *
     *   file_name      Which snippet. `fluent_saving_snippet_name` (the pre-10.56 name) and
     *                  `snippet_name` (what snippets/find takes) are accepted too.
     *   code           The new body, exactly as snippets/find returns it. Optional: leave it
     *                  out to change meta alone. May also arrive as meta.code.
     *   meta           Only the fields being changed. Everything not sent keeps its current
     *                  value - in particular status, so editing code never publishes or
     *                  unpublishes anything unless `status` is sent.
     *   expected_hash  Optional. The code_hash snippets/find returned. If the snippet has
     *                  changed since, nothing is written and the reply is a 409 carrying
     *                  the current hash, so a second writer cannot silently undo the first.
     *   reactivate     Optional. Clears a recorded fatal error, as the editor's "save and
     *                  reactivate" does.
     *
     * The reply is the saved snippet, read back from disk, in the same shape snippets/find
     * returns - including the new code_hash. Refusals carry the editor's error_details
     * (title, reason, fix) with a 4xx status.
     */
    public static function updateSnippet(\WP_REST_Request $request)
    {
        if ($restricted = self::denyUnlessCanAuthorSnippets()) {
            return $restricted;
        }

        $fileName = '';
        foreach (['file_name', 'fluent_saving_snippet_name', 'snippet_name'] as $param) {
            $fileName = sanitize_file_name((string)$request->get_param($param));
            if ($fileName) {
                break;
            }
        }

        if (!$fileName) {
            return new \WP_Error('missing_file_name', __('Say which snippet to update by passing its file_name, as snippets/find returns it.', 'easy-code-manager'), ['status' => 400]);
        }

        $meta = self::requestMeta($request);

        if (is_wp_error($meta)) {
            return $meta;
        }

        $code = self::takeCode($request, $meta);

        $existing = (new Snippet())->findByFileName($fileName);

        if (is_wp_error($existing)) {
            return self::withStatus($existing, 404);
        }

        $currentCode = self::editableCode($existing);
        $currentHash = self::hashCode($currentCode);

        $expectedHash = $request->get_param('expected_hash');

        if ($expectedHash && !hash_equals($currentHash, strtolower($expectedHash))) {
            return new \WP_Error('snippet_changed', __('This snippet has changed since you read it, so nothing was saved. Read it again, reapply your change and send the new code_hash as expected_hash.', 'easy-code-manager'), [
                'status'       => 409,
                'current_hash' => $currentHash
            ]);
        }

        if ($code === null) {
            $code = $currentCode;
        } else if ($tooLarge = self::refuseOversizedCode($code)) {
            return $tooLarge;
        }

        // Unknown keys are dropped rather than refused: the pre-10.56 route was usually fed
        // the whole meta array the editor works with, created_at and is_valid included,
        // and those callers have to keep working.
        $meta = Arr::only($meta, self::AUTHORABLE_META);

        if ($malformed = self::refuseMalformedMeta($meta)) {
            return $malformed;
        }

        if (isset($meta['status']) && !in_array($meta['status'], ['published', 'draft'], true)) {
            return new \WP_Error('invalid_status', __('status must be either "published" or "draft".', 'easy-code-manager'), ['status' => 400]);
        }

        $meta = array_merge($existing['meta'], $meta);
        $meta['updated_by'] = get_current_user_id();

        $saved = Helper::updateSnippet([
            'meta'       => $meta,
            'code'       => $code,
            'file_name'  => $fileName,
            'reactivate' => $request->get_param('reactivate')
        ]);

        if (is_wp_error($saved)) {
            return $saved;
        }

        do_action('fluent_snippets/rest_snippet_written', $fileName, 'update', RestAuthoring::actor());

        $response = self::presentSnippet($fileName);

        if (!is_wp_error($response)) {
            $response['message'] = __('Snippet updated successfully', 'easy-code-manager');
        }

        return $response;
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
     * A snippet as snippets/find returns it, read fresh from disk.
     *
     * Shared by find, create and update so that what an integration reads back after a
     * write is byte-for-byte what it would get by asking again.
     */
    private static function presentSnippet($fileName)
    {
        $snippet = (new Snippet())->findByFileName($fileName);

        if (is_wp_error($snippet)) {
            return $snippet;
        }

        $snippet['file_name'] = basename($snippet['file']);
        $snippet['code'] = self::editableCode($snippet);
        $snippet['code_hash'] = self::hashCode($snippet['code']);

        $config = Helper::getIndexedConfig();

        if (!empty($config['error_files']) && !empty($config['error_files'][$snippet['file_name']])) {
            $snippet['error'] = $config['error_files'][$snippet['file_name']];
        }

        return [
            'snippet' => $snippet
        ];
    }

    /**
     * The code as the editor shows it: without the `<?php` line FluentSnippets writes in
     * front of every PHP snippet. This is also the form an update accepts, and the form
     * code_hash is taken over.
     */
    private static function editableCode($snippet)
    {
        $code = (string)$snippet['code'];

        if (Arr::get($snippet, 'meta.type') == 'PHP') {
            $code = preg_replace('/^<\?php/', '', $code);
            $code = ltrim($code, PHP_EOL);
        }

        return $code;
    }

    /**
     * sha256 of the code, so an integration can compute it from what it already holds.
     */
    private static function hashCode($code)
    {
        return hash('sha256', (string)$code);
    }

    /**
     * Read `meta` whether it came as a JSON string (the pre-10.56 form, and what a
     * form-encoded request can send) or as an object in a JSON body.
     *
     * @return array|\WP_Error
     */
    private static function requestMeta(\WP_REST_Request $request)
    {
        $meta = $request->get_param('meta');

        if ($meta === null || $meta === '') {
            return [];
        }

        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }

        if (!is_array($meta)) {
            return new \WP_Error('invalid_meta', __('Snippet meta could not be read. Send it as a JSON object.', 'easy-code-manager'), ['status' => 400]);
        }

        return $meta;
    }

    /**
     * The submitted code, from `code` or from inside meta, and never left in meta.
     *
     * @return string|null Null when none was sent.
     */
    private static function takeCode(\WP_REST_Request $request, &$meta)
    {
        $code = $request->get_param('code');

        if (array_key_exists('code', $meta)) {
            if ($code === null) {
                $code = $meta['code'];
            }
            unset($meta['code']);
        }

        return is_string($code) ? $code : null;
    }

    /**
     * Every meta value is written into the snippet's header as one line of text, so
     * anything but a scalar would be stored as the word "Array". `condition` is the one
     * structured field, and it has to be an object.
     */
    private static function refuseMalformedMeta($meta)
    {
        foreach ($meta as $key => $value) {
            $valid = $key === 'condition' ? is_array($value) : (is_scalar($value) || $value === null);

            if (!$valid) {
                return new \WP_Error('invalid_meta', sprintf(
                    /* translators: %s: meta field name */
                    __('meta.%s has the wrong shape. condition must be an object; every other field must be a string or a number.', 'easy-code-manager'),
                    $key
                ), ['status' => 400, 'field' => $key]);
            }
        }

        return false;
    }

    private static function refuseOversizedCode($code)
    {
        if (strlen($code) <= self::MAX_CODE_BYTES) {
            return false;
        }

        return new \WP_Error('code_too_large', sprintf(
            /* translators: %d: maximum snippet size in kilobytes */
            __('The snippet is larger than %d KB, which is more than any snippet should be. Nothing was saved.', 'easy-code-manager'),
            self::MAX_CODE_BYTES / 1024
        ), ['status' => 413]);
    }

    private static function withStatus(\WP_Error $error, $status)
    {
        $data = $error->get_error_data();
        $error->add_data(array_merge(is_array($data) ? $data : [], ['status' => $status]));

        return $error;
    }

    /**
     * Guard for anything that writes, publishes or removes a snippet — all of which
     * amount to putting code on the site.
     *
     * Deliberately NOT the same test as SettingsController::denyUnlessCanManageSettings(),
     * which also wants manage_options. The two used to share the name isBlockedRequest(),
     * which made the difference look accidental.
     *
     * install_plugins is checked here rather than left to route registration. It used to
     * be safe to assume, because every route in this plugin required it; now that reading
     * and writing have separate gates (see Http/routes.php), assuming it is how a write
     * ends up reachable from a read-only screen the first time somebody moves a route
     * between the two lists.
     */
    private static function denyUnlessCanAuthorSnippets()
    {
        if (current_user_can('install_plugins') && current_user_can('unfiltered_html')) {
            return false;
        }

        return new \WP_Error('invalid_request', 'You do not have permission to perform this action. Required Permission: install_plugins & unfiltered_html');
    }
}
