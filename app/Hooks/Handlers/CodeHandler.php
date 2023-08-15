<?php

namespace FluentSnippets\App\Hooks\Handlers;

use FluentSnippets\App\Helpers\Arr;
use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Model\Snippet;
use FluentSnippets\App\Services\FluentSnippetCondition;

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

        if ($config['meta']['force_disabled'] == 'yes') {
            return; // this forcefully disabled via URL
        }

        $errorFiles = Arr::get($config, 'error_files', []);

        $snippets = $config['published'];
        $storageDir = Helper::getStorageDir();

        $hasInvalidFiles = false;

        $conditionalClass = new FluentSnippetCondition();

        $filterMaps = [
            'before_content' => [
                'hook'      => 'the_content',
                'insert'    => 'before',
                'is_single' => true
            ],
            'after_content'  => [
                'hook'      => 'the_content',
                'insert'    => 'after',
                'is_single' => true
            ],
        ];

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

            $file = $storageDir . '/' . sanitize_file_name($fileName);
            if (!file_exists($file)) {
                $hasInvalidFiles = true;
                continue;
            }

            $type = $snippet['type'];

            switch ($type) {
                case 'PHP':
                    add_action('init', function () use ($file, $snippet, $conditionalClass) {
                        if (!$conditionalClass->evaluate($snippet['condition'])) {
                            return;
                        }
                        $runAt = Arr::get($snippet, 'run_at', 'all');
                        if ($runAt == 'backend') {
                            if (is_admin()) {
                                require_once $file;
                            }
                            return;
                        }
                        require_once $file;
                    }, $snippet['priority']);
                    break;
                case 'js':
                    $runAt = Arr::get($snippet, 'run_at', 'wp_footer');
                    if (in_array($runAt, ['wp_head', 'wp_footer'])) {
                        add_action($runAt, function () use ($file, $snippet, $conditionalClass) {
                            if (!$conditionalClass->evaluate($snippet['condition'])) {
                                return;
                            }
                            $code = (new Snippet())->parseBlock(file_get_contents($file), true);
                            ?>
                            <script><?php echo Helper::escCssJs($code); // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
                            <?php
                        }, 99);
                    }
                    break;
                case 'css':
                    if ($runAt == 'everywehere' && is_admin()) {
                        $runAt = 'admin_head';
                    } else {
                        $runAt = 'wp_head';
                    }

                    add_action($runAt, function () use ($file, $snippet, $conditionalClass) {
                        if (!$conditionalClass->evaluate($snippet['condition'])) {
                            return;
                        }
                        $code = (new Snippet())->parseBlock(file_get_contents($file), true);
                        ?>
                        <style><?php echo Helper::escCssJs($code); // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped ?></style>
                        <?php
                    }, $snippet['priority']);
                    break;
                case 'php_content':
                    $runAt = $snippet['run_at'];
                    if (in_array($runAt, ['wp_footer', 'wp_head', 'wp_body_open'])) {
                        add_action($runAt, function () use ($file, $snippet, $conditionalClass) {
                            if (!$conditionalClass->evaluate($snippet['condition'])) {
                                return;
                            }
                            require_once $file;
                        }, $snippet['priority']);
                    }
                    if (isset($filterMaps[$runAt])) {
                        $filter = $filterMaps[$runAt];
                        add_filter($filter['hook'], function ($content) use ($file, $snippet, $conditionalClass, $filter) {
                            if (!empty($filter['is_single'])) {
                                if (!is_single() || !in_the_loop() || !is_main_query()) {
                                    return $content;
                                }
                            }

                            if (!$conditionalClass->evaluate($snippet['condition'])) {
                                return $content;
                            }

                            ob_start();
                            require_once $file;
                            $result = ob_get_clean();
                            if ($result) {
                                if ($filter['insert'] == 'before') {
                                    return $result . $content;
                                } else {
                                    return $content . $result;
                                }
                            }
                            return $content;
                        }, $snippet['priority']);
                    }
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
            return $this->shortCodeError(__('Snippets are disabled', 'fluent-snippets'));
        }

        $fileName = sanitize_file_name($fileName . '.php');
        if (isset($config['error_files'][$fileName])) {
            return $this->shortCodeError(__('Snippet has an error', 'fluent-snippets'));
        }

        $snippet = (new Snippet())->findByFileName($fileName);

        if (is_wp_error($snippet)) {
            return $this->shortCodeError($snippet->get_error_message());
        }

        if (Arr::get($snippet, 'meta.type') !== 'php_content') {
            return $this->shortCodeError(__('Snippet type is not PHP Content', 'fluent-snippets'));
        }

        if (Arr::get($snippet, 'meta.run_at') != 'shortcode') {
            return $this->shortCodeError(__('Snippet run at is not shortcode', 'fluent-snippets'));
        }

        if (Arr::get($snippet, 'meta.status') != 'published') {
            return $this->shortCodeError(__('Snippet status is not published', 'fluent-snippets'));
        }

        // check condition
        $conditionalClass = new FluentSnippetCondition();
        if (!$conditionalClass->evaluate($snippet['condition'])) {
            return $this->shortCodeError(__('Snippet condition is not valid', 'fluent-snippets'));
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

        return $this->shortCodeError(__('Return Data is not valid. Return data need to be string or number', 'fluent-snippets'));
    }

    private function shortCodeError($message)
    {

        if (!current_user_can('manage_options')) {
            return "";
        }

        if (!$message) {
            $message = __('Shortcode could not be rendered', 'fluent-snippets');
        }
        $message .= '. ' . __('This message is only visible to site admin role', 'fluent-snippets');

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
