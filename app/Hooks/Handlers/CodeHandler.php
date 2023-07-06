<?php

namespace FluentSnippets\App\Hooks\Handlers;

use FluentSnippets\App\Helpers\Arr;
use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Model\Snippet;

class CodeHandler
{
    protected $storageDir;

    public function register()
    {
        $this->storageDir = Helper::getStorageDir();

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
    }

    public function runSnippets()
    {
        $config = Helper::getIndexedConfig();
        if (empty($config) || empty($config['published']) || !is_array($config['published'])) {
            return; // No config exists
        }

        if (Arr::get($config, 'meta.force_disabled') == 'yes') {
            return; // this forcefully disabled via URL
        }

        $errorFiles = Arr::get($config, 'error_files', []);

        $snippets = $config['published'];
        $storageDir = Helper::getStorageDir();

        $hasInvalidFiles = false;

        foreach ($snippets as $fileName => $snippet) {
            if (isset($_REQUEST['fluent_saving_snippet_name'])) {
                if ($_REQUEST['fluent_saving_snippet_name'] === $fileName && current_user_can('manage_options')) {
                    continue;
                }
            }

            if ($errorFiles && isset($errorFiles[$fileName])) {
                // There has an error. Skip this
                continue;
            }

            $type = $snippet['type'];
            $file = $storageDir . '/' . sanitize_file_name($fileName);

            if (!file_exists($file)) {
                $hasInvalidFiles = true;
                continue;
            }

            switch ($type) {
                case 'PHP':
                    $runAt = Arr::get($snippet, 'run_at', 'all');
                    if ($runAt == 'backend') {
                        if (is_admin()) {
                            require_once $file;
                        }
                    } else {
                        require_once $file;
                    }
                    break;
                case 'js':
                    $runAt = Arr::get($snippet, 'run_at', 'wp_footer');

                    if (in_array($runAt, ['wp_head', 'wp_footer'])) {
                        add_action($runAt, function () use ($file) {
                            if (!file_exists($file)) {
                                return;
                            }
                            $code = (new Snippet())->parseBlock(file_get_contents($file), true);
                            ?>
                            <script><?php echo $code; ?></script>
                            <?php
                        }, 99);
                    }
                    break;
                case 'css':
                    add_action('wp_head', function () use ($file) {
                        if (!file_exists($file)) {
                            return;
                        };
                        $code = (new Snippet())->parseBlock(file_get_contents($file), true);
                        ?>
                        <style><?php echo $code; ?></style>
                        <?php
                    }, 99);
                    break;
                case 'php_content':
                    $runAt = $snippet['run_at'];
                    if ($runAt == 'wp_footer' || $runAt == 'wp_head') {
                        add_action($runAt, function () use ($file) {
                            require_once $file;
                        }, 11);
                    }
                    break;
                default:
                    break;
            }
        }

        if ($hasInvalidFiles) {
            Helper::cacheSnippetIndex(false, true);
        }
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
            return $this->shortCodeError('Snippets are disabled');
        }

        $fileName = sanitize_file_name($fileName . '.php');
        if (isset($config['error_files'][$fileName])) {
            return $this->shortCodeError('Snippet has an error');
        }

        $snippet = (new Snippet())->findByFileName($fileName);

        if (is_wp_error($snippet)) {
            return $this->shortCodeError($snippet->get_error_message());
        }

        if (Arr::get($snippet, 'meta.type') !== 'php_content') {
            return $this->shortCodeError('Snippet type is not PHP Conetnt');
        }

        if (Arr::get($snippet, 'meta.run_at') != 'shortcode') {
            return $this->shortCodeError('Snippet run at is not shortcode');
        }

        if (Arr::get($snippet, 'meta.status') != 'published') {
            return $this->shortCodeError('Snippet status is not published');
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

        return $this->shortCodeError('Return Data is not valid. Return data need to be string or number');
    }

    private function shortCodeError($message)
    {

        if (!current_user_can('manage_options')) {
            return "";
        }

        if (!$message) {
            $message = 'Shortcode could not be rendered';
        }

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
            if (sanitize_text_field($_REQUEST['snippet_secret']) == $config['meta']['secret_key']) {
                $config['meta']['force_disabled'] = 'yes';
                Helper::saveIndexedConfig($config);
                header('Location: ' . admin_url('admin.php?page=fluent-snippets'));
                die();
            }
        }

        return false;
    }
}
