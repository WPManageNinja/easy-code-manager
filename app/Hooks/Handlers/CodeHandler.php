<?php

namespace FluentSnippets\App\Hooks\Handlers;

use FluentSnippets\App\Helpers\Arr;
use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Model\Snippet;
use FluentSnippets\App\Services\CodeRunner;
use FluentSnippets\App\Services\FluentSnippetCondition;

class CodeHandler
{
    protected $storageDir;

    public function register()
    {
        $this->storageDir = Helper::getStorageDir();

        // @todo: remove this at mid january 2024
        Helper::getIndexedConfig();

        if (!$this->isDisabled()) {
            add_action('shutdown', function () {
                $error = error_get_last();

                if ($error && $error['type'] === 1) {
                    $this->maybeHandleFatalError([
                        'response' => 500
                    ], $error);
                }
            });

            // This is for the early error handling
            add_filter('wp_php_error_args', array($this, 'maybeHandleFatalError'), 1, 2);

            add_action('plugins_loaded', [$this, 'runSnippets'], 9);
            add_shortcode('fluent_snippet', [$this, 'handleShortcode']);
        }

        add_action('fluent_snippets/snippet_created', [$this, 'rebuildCache']);
        add_action('fluent_snippets/snippet_updated', [$this, 'rebuildCache']);
        add_action('fluent_snippets/snippet_deleted', [$this, 'handleFileDelete']);

        add_action('fluent_snippets/rebuild_index', function ($fileName, $isForced) {
            Helper::cacheSnippetIndex($fileName, $isForced);
        }, 10, 2);

    }

    public function runSnippets()
    {
        (new CodeRunner())->runSnippets();
    }

    public function handleShortcode($atts, $content = null)
    {
        $atts = shortcode_atts([
            'id' => '',
        ], $atts);

        $fileName = $atts['id'];
        if (empty($fileName)) {
            return '';
        }

        $config = Helper::getIndexedConfig();

        if ($config['meta']['force_disabled'] == 'yes') {
            return $this->shortCodeError(__('Snippets are disabled', 'easy-code-manager'));
        }

        $fileName = sanitize_file_name($fileName . '.php');
        if (isset($config['error_files'][$fileName])) {
            return $this->shortCodeError(__('Snippet has an error', 'easy-code-manager'));
        }

        $snippet = (new Snippet())->findByFileName($fileName);

        if (is_wp_error($snippet)) {
            return $this->shortCodeError($snippet->get_error_message());
        }

        if (Arr::get($snippet, 'meta.type') !== 'php_content') {
            return $this->shortCodeError(__('Snippet type is not PHP Content', 'easy-code-manager'));
        }

        if (Arr::get($snippet, 'meta.run_at') != 'shortcode') {
            return $this->shortCodeError(__('Snippet run at is not shortcode', 'easy-code-manager'));
        }

        if (Arr::get($snippet, 'meta.status') != 'published') {
            return $this->shortCodeError(__('Snippet status is not published', 'easy-code-manager'));
        }

        // check condition
        $conditionalClass = new FluentSnippetCondition();
        if (!$conditionalClass->evaluate(Arr::get($snippet, 'condition', []))) {
            return $this->shortCodeError(__('Snippet condition is not valid', 'easy-code-manager'));
        }
        
        ob_start();

        $maybeReturn = include $snippet['file'];

        $result = ob_get_clean();

        if ($result) {
            return $result;
        }

        if (is_string($maybeReturn) || is_numeric($maybeReturn)) {
            return $maybeReturn;
        }

        return $this->shortCodeError(__('Return Data is not valid. Return data need to be string or number', 'easy-code-manager'));
    }

    private function shortCodeError($message)
    {

        if (!current_user_can('manage_options')) {
            return "";
        }

        if (!$message) {
            $message = __('Shortcode could not be rendered', 'easy-code-manager');
        }
        $message .= '. ' . __('This message is only visible to site admin role', 'easy-code-manager');

        return "<div class='fluent-snippet-error'>'.wp_kses_post($message).'</div>";
    }

    public function rebuildCache($snippetFile)
    {
        Helper::cacheSnippetIndex($snippetFile);
    }

    public function handleFileDelete($fileName)
    {
        $config = Helper::getIndexedConfig();

        if (isset($config['published'][$fileName])) {
            unset($config['published'][$fileName]);
        }
        if (isset($config['draft'][$fileName])) {
            unset($config['draft'][$fileName]);
        }

        if (isset($config['error_files'][$fileName])) {
            unset($config['error_files'][$fileName]);
        }

        Helper::cacheSnippetIndex();
    }

    public function maybeHandleFatalError($args, $error)
    {
        error_log('OK');

        if (empty($args['response']) || $args['response'] != 500) {
            return $args;
        }

        if (empty($error['file'])) {
            return $args;
        }

        if (isset($_REQUEST['fluent_saving_snippet_name'])) {
            return $args;
        }

        $file = $error['file'];
        if ($this->storageDir !== dirname($file)) {
            return $args;
        }

        // let's get the indexed config
        $config = Helper::getIndexedConfig();

        $fileName = basename($file);

        if (empty($config)) {
            return $args;
        }

        if (isset($config['error_files'][$fileName])) {
            return $args;
        }

        // this is our script error. Let's disable the whole plugin run
        $message = 'Unknown Error';
        if (!empty($error['message'])) {
            $message = $error['message'];
            // get the first line of the message
            $message = explode("\n", $message)[0];

            $message = str_replace($file, 'SNIPPET', $message);
        }

        $config['error_files'][$fileName] = $message;

        Helper::saveIndexedConfig($config, $this->storageDir . '/index.php');

        return $args;
    }

    private function isDisabled()
    {
        $result = (defined('FLUENT_SNIPPETS_SAFE_MODE') && FLUENT_SNIPPETS_SAFE_MODE) || !apply_filters('fluent_snippets/run_snippets', true);

        if ($result) {
            return true;
        }

        if (isset($_REQUEST['fluent_snippets']) && isset($_REQUEST['snippet_secret'])) {
            $config = Helper::getIndexedConfig();
            if ($config && hash_equals($config['meta']['secret_key'], $_REQUEST['snippet_secret'])) {
                $config['meta']['force_disabled'] = 'yes';
                Helper::saveIndexedConfig($config);
                header('Location: ' . admin_url('admin.php?page=fluent-snippets'));
                die();
            }
        }

        return false;
    }
}
