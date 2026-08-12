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

    /**
     * Error types that end the request.
     *
     * error_get_last() reports the last error of any severity, including notices on a
     * request that finished perfectly well, so the shutdown handler has to filter by
     * type or it would quarantine snippets over deprecation warnings. This previously
     * matched E_ERROR alone, which missed the parse and compile errors a truncated or
     * hand-edited snippet file actually produces.
     *
     * E_RECOVERABLE_ERROR is deliberately absent: a custom error handler can recover
     * from it, and acting on one that was handled would disable a working snippet.
     */
    const FATAL_ERROR_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    public function register()
    {
        $this->storageDir = Helper::getStorageDir();

        if (!$this->isDisabled()) {
            add_action('shutdown', function () {
                $error = error_get_last();

                if ($error && in_array($error['type'], self::FATAL_ERROR_TYPES, true)) {
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

        // The action still fires with two arguments from CodeRunner for backwards
        // compatibility with anything hooking it; cacheSnippetIndex() never used either.
        add_action('fluent_snippets/rebuild_index', function () {
            Helper::cacheSnippetIndex();
        }, 10, 0);

    }

    public function runSnippets()
    {
        (new CodeRunner())->runSnippets();
    }

    public function handleShortcode($atts, $content = null)
    {
        $original = (array)$atts;
        
        $atts = shortcode_atts([
            'id' => '',
        ], $original);

        // Unlike the core function, keep any user arguments in the array. 
        // This allows [fluent_snippet id="3-snippet" type="test"] such that a snippet can look up the argument in $atts['type'].
        // Note: The below is to cover if the 3rd parameter to shortcode_atts was added i.e. to allow filtering
        unset( $original['id'] );
        $atts = array_merge( $atts, $original );
        
        $fileName = $atts['id'];
        if (empty($fileName)) {
            return '';
        }

        $config = Helper::getIndexedConfig();

        if (Arr::get($config, 'meta.force_disabled') == 'yes') {
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

        // Tracked like the hook-run snippets, so a shortcode snippet that fatals inside a
        // function it calls is attributed to the snippet rather than to that function.
        CodeRunner::pushRunningSnippet($fileName);

        try {
            $maybeReturn = include $snippet['file'];
        } finally {
            CodeRunner::popRunningSnippet();
        }

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

        return "<div class='fluent-snippet-error'>".wp_kses_post($message)."</div>";
    }

    public function rebuildCache($snippetFile)
    {
        Helper::cacheSnippetIndex();
    }

    public function handleFileDelete($fileName)
    {
        /*
         * This used to unset the deleted file from published, draft and error_files
         * first. All three were dead: they mutated a local copy that cacheSnippetIndex()
         * then discarded, because it rebuilds published/draft from disk and re-reads
         * error_files from the file it is about to overwrite. The published/draft entries
         * disappeared anyway (the file is gone, so the rebuild cannot find it), but the
         * error_files entry survived forever.
         *
         * cacheSnippetIndex() now prunes error_files to snippets that still exist, which
         * is where the work actually belongs — it also heals entries already stranded on
         * disk, which an unset() here never could.
         */
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

        $isSnippetFile = $this->isSnippetFile($file);

        if ($isSnippetFile) {
            $fileName = basename($file);
        } else {
            /*
             * The fatal surfaced outside the storage directory, so $error['file'] is of
             * no use on its own — the common real-world failure is a snippet calling a
             * WordPress or third-party function that then fatals, which reports
             * wp-includes/… and left the snippet unblamed and the site broken on every
             * subsequent request.
             *
             * A snippet still on the running stack at shutdown never finished, because a
             * fatal does not unwind. That snippet is the one that led here.
             */
            $fileName = CodeRunner::getRunningSnippet();
        }

        if (!$fileName) {
            return $args;
        }

        // let's get the indexed config
        $config = Helper::getIndexedConfig();

        if (empty($config)) {
            return $args;
        }

        // Only ever quarantine something the index actually knows about. Matters for the
        // tracked path, where the name comes from runtime state rather than from the file
        // that faulted.
        if (!isset($config['published'][$fileName]) && !isset($config['draft'][$fileName])) {
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

            if ($isSnippetFile) {
                $message = str_replace($file, 'SNIPPET', $message);
            } else {
                // Keep the location — it is the useful part when the fault is downstream —
                // but trim the absolute path so the admin screen shows wp-includes/… rather
                // than the full server path.
                $message = str_replace(ABSPATH, '', $message);
                $message = 'Fatal error while this snippet was running: ' . $message;
            }
        }

        $config['error_files'][$fileName] = $message;

        Helper::saveIndexedConfig($config, $this->storageDir . '/index.php');

        return $args;
    }

    /**
     * Does this path point at a file in the snippet storage directory?
     *
     * A plain string comparison is not enough. PHP reports the *resolved* path in
     * $error['file'], while getStorageDir() hands back whatever WP_CONTENT_DIR — or the
     * FLUENT_SNIPPETS_STORAGE_DIR override — was configured as. On a host that serves
     * the site through a symlinked directory, which release-based deploys do routinely,
     * those are two different strings for the same file, and a snippet that fataled was
     * silently never blamed.
     *
     * @param string $file
     * @return bool
     */
    private function isSnippetFile($file)
    {
        $dir = dirname($file);

        if ($this->storageDir === $dir) {
            return true;
        }

        $realStorageDir = realpath($this->storageDir);
        $realDir = realpath($dir);

        return $realStorageDir && $realDir && $realStorageDir === $realDir;
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
