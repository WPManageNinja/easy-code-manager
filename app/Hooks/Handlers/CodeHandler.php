<?php

namespace FluentSnippets\App\Hooks\Handlers;

use FluentSnippets\App\Helpers\Arr;
use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Model\Snippet;

class CodeHandler
{
    public function register()
    {
        add_action('plugins_loaded', [$this, 'runSnippets'], 9);
        add_shortcode('fluent_snippet', [$this, 'handleShortcode']);

        add_action('fluent_snippets/snippet_created', [$this, 'rebuildCache']);
        add_action('fluent_snippets/snippet_updated', [$this, 'rebuildCache']);
        add_action('fluent_snippets/snippet_deleted', [$this, 'rebuildCache']);
    }

    public function runSnippets()
    {
        if(defined('FLUENT_SNIPPET_SAFE_MODE') && FLUENT_SNIPPET_SAFE_MODE) {
            return;
        }
        
        if (!apply_filters('fluent_snippets/run_snippets', true)) {
            return;
        }

        $snippets = Helper::getPublishedSnippets();

        if (empty($snippets)) {
            return;
        }

        $storageDir = Helper::getStorageDir();

        $hasInvalidFiles = false;

        foreach ($snippets as $fileName => $snippet) {
            if (isset($_REQUEST['fluent_saving_snippet_name'])) {
                if ($_REQUEST['fluent_saving_snippet_name'] === $fileName && current_user_can('manage_options')) {
                    continue;
                }
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
                            ?>
                            <script><?php require_once $file; ?></script>
                            <?php
                        }, 99);
                    }
                    break;
                case 'css':
                    add_action('wp_head', function () use ($file) {
                        ?>
                        <style><?php require_once $file; ?></style>
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

        $fileName = sanitize_file_name($fileName . '.php');

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
}
