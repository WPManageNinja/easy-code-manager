<?php

namespace FluentSnippets\App\Http\Controllers;

use FluentSnippets\App\Helpers\Arr;
use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Model\Snippet;

class SnippetsController
{
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

        return [
            'snippets' => $snippetModel->getIndexedSnippets($perPage, $page)
        ];
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
        if ($restricted = self::isBlockedRequest()) {
            return $restricted;
        }

        $meta = json_decode($request->get_param('meta'), true);
        $code = $meta['code'];

        unset($meta['code']);

        $metaValidated = self::validateMeta($meta);

        if (is_wp_error($metaValidated)) {
            return $metaValidated;
        }

        $meta['status'] = 'draft';

        // Check if the php snippet $code is valid or not by validating it

        if ($meta['type'] == 'PHP') {
            // Check if the code starts with <?php
            if (preg_match('/^<\?php/', $code)) {
                return new \WP_Error('invalid_code', 'Please remove <?php from the beginning of the code', [
                    'code' => 'Please remove <?php from the beginning of the code'
                ]);
            }
            $code = rtrim($code, '?>');
            $code = '<?php' . PHP_EOL . $code;
        } else if ($meta['type'] == 'php_content') {
            $code = apply_filters('fluent_snippets/sanitize_mixed_content', $code, $meta);
            if (is_wp_error($code)) {
                return $code;
            }
        }

        // Validate the code
        $validated = Helper::validateCode($meta['type'], $code);

        if (is_wp_error($validated)) {

            $message = $validated->get_error_message();
            $additionalData = $validated->get_error_data();

            if ($lineNumber = Arr::get($additionalData, 'line')) {
                if (is_numeric($lineNumber) && $lineNumber > 1) {
                    $lineNumber = $lineNumber - 1;
                    $additionalData['line'] = $lineNumber;
                }

                $message .= ' on line ' . $lineNumber;
            }

            return new \WP_Error('code', $message, [
                'code'             => $message,
                'code_explanation' => $additionalData
            ]);
        }

        $settings = Helper::getConfigSettings();

        if ($settings['auto_publish'] == 'yes') {
            $meta['status'] = 'published';
        }

        // check if the $code which is a php snippet is valid or not
        $snippetModel = new Snippet();
        $snippet = $snippetModel->createSnippet($code, $meta);
        do_action('fluent_snippets/snippet_created', $snippet);

        return [
            'snippet' => $snippet,
            'message' => __('Snippet created successfully', 'easy-code-manager')
        ];
    }

    public static function updateSnippet(\WP_REST_Request $request)
    {
        if ($restricted = self::isBlockedRequest()) {
            return $restricted;
        }

        $fileName = sanitize_file_name($request->get_param('fluent_saving_snippet_name'));
        $meta = json_decode($request->get_param('meta'), true);
        $code = $meta['code'];
        unset($meta['code']);

        $metaValidated = self::validateMeta($meta);

        if (is_wp_error($metaValidated)) {
            return $metaValidated;
        }

        if ($meta['type'] == 'PHP') {
            // Check if the code starts with <?php
            if (preg_match('/^<\?php/', $code)) {
                return new \WP_Error('invalid_code', 'Please remove <?php from the beginning of the code', [
                    'code' => 'Please remove <?php from the beginning of the code'
                ]);
            }

            $code = rtrim($code, '?>');
            $code = '<?php' . PHP_EOL . $code;
        } else if ($meta['type'] == 'php_content') {
            $code = apply_filters('fluent_snippets/sanitize_mixed_content', $code, $meta);
            if (is_wp_error($code)) {
                return $code;
            }
        } else {
            $sanitizedCode = Helper::escCssJs($code);
            if ($sanitizedCode !== $code) {
                return new \WP_Error('invalid_code', 'Please remove any any style or script tag from the code');
            }
        }

        // Validate the code
        $validated = Helper::validateCode($meta['type'], $code);

        if (is_wp_error($validated)) {

            $message = $validated->get_error_message();
            $additionalData = $validated->get_error_data();

            if ($lineNumber = Arr::get($additionalData, 'line')) {
                if (is_numeric($lineNumber) && $lineNumber > 1) {
                    $lineNumber = $lineNumber - 1;
                    $additionalData['line'] = $lineNumber;
                }

                $message .= ' on line ' . $lineNumber;
            }

            return new \WP_Error('code', $message, [
                'code'             => $message,
                'code_explanation' => $additionalData
            ]);
        }

        // check if the $code which is a php snippet is valid or not
        $snippetModel = new Snippet();
        $snippet = $snippetModel->updateSnippet($fileName, $code, $meta);

        if ($request->get_param('reactivate')) {
            $config = Helper::getIndexedConfig();
            if (isset($config['error_files'][$fileName])) {
                unset($config['error_files'][$fileName]);
            }
            Helper::saveIndexedConfig($config);
        }

        do_action('fluent_snippets/snippet_updated', $snippet);

        return [
            'snippet' => $snippet,
            'message' => 'Snippet updated successfully'
        ];
    }

    public static function updateSnippetStatus(\WP_REST_Request $request)
    {
        if ($restricted = self::isBlockedRequest()) {
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
        if ($restricted = self::isBlockedRequest()) {
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

    private static function validateMeta($meta)
    {
        $required = ['name', 'status', 'type', 'run_at'];

        foreach ($required as $key) {
            if (empty($meta[$key])) {
                return new \WP_Error($key, sprintf(__('%s is required', 'easy-code-manager'), $key), [
                    $key => sprintf(__('%s is required', 'easy-code-manager'), $key)
                ]);
            }
        }

        return true;
    }

    private static function isBlockedRequest()
    {
        if (current_user_can('unfiltered_html')) {
            return false;
        }

        return new \WP_Error('invalid_request', 'You do not have permission to perform this action. Required Permission: unfiltered_html');
    }
}
