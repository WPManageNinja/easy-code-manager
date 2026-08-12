<?php

namespace FluentSnippets\App\Services;

class CodeRunner
{

    private $storageDir = '';
    private $storageUrl = '';

    /**
     * File names of the snippets currently mid-execution, innermost last.
     *
     * A fatal error does not unwind the stack, so a snippet that fatals never reaches
     * its pop and stays on this list. Whatever is left here when the request dies is
     * the snippet that was running — which is how the shutdown handler can blame the
     * right snippet for a fatal raised deep inside WordPress or another plugin, where
     * $error['file'] points at core rather than at the snippet.
     *
     * A stack rather than a single value because a snippet can fire a hook that runs
     * another snippet; the innermost one is the culprit.
     */
    private static $runningSnippets = [];

    /**
     * The snippet that was executing when the request died, or '' if none was.
     *
     * @return string
     */
    public static function getRunningSnippet()
    {
        if (!self::$runningSnippets) {
            return '';
        }

        return end(self::$runningSnippets);
    }

    public static function pushRunningSnippet($fileName)
    {
        self::$runningSnippets[] = $fileName;
    }

    public static function popRunningSnippet()
    {
        array_pop(self::$runningSnippets);
    }

    public function __construct()
    {
        $this->storageDir = \FluentSnippets\App\Helpers\Helper::getStorageDir();
        $this->storageUrl = \FluentSnippets\App\Helpers\Helper::getStorageUrl();
    }

    public function runSnippets()
    {
        if (!is_file($this->storageDir . '/index.php')) {
            return;
        }

        $config = include $this->storageDir . '/index.php';

        if (empty($config) || empty($config['published']) || !is_array($config['published'])) {
            return; // No config or published scripts exist exists
        }

        if (isset($config['meta']['force_disabled']) && $config['meta']['force_disabled'] == 'yes') {
            return; // this forcefully disabled via URL
        }

        $errorFiles = $this->get($config, 'error_files', []);

        $snippets = $config['published'];

        if (!$snippets) {
            return;
        }

        $storageDir = $this->storageDir;

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
            if (!is_file($file)) {
                $hasInvalidFiles = true;
                continue;
            }

            $type = $this->get($snippet, 'type');

            switch ($type) {
                case 'PHP':
                    $conditionSettings = $snippet['condition'];
                    $hookName = 'wp';

                    if (empty($conditionSettings) || empty($conditionSettings['status']) || $conditionSettings['status'] != 'yes' || empty($conditionSettings['items'])) {
                        $hookName = 'setup_theme';
                    }

                    add_action($hookName, function () use ($file, $fileName, $snippet, $conditionalClass) {
                        if (!$conditionalClass->evaluate($snippet['condition'])) {
                            return;
                        }

                        $runAt = $this->get($snippet, 'run_at', 'all');
                        if ($runAt == 'backend') {
                            if (is_admin()) {
                                $this->runSnippetFile($file, $fileName);
                            }
                            return;
                        }

                        $this->runSnippetFile($file, $fileName);
                    }, $this->get($snippet, 'priority', 10));

                    break;
                case 'js':
                    $runAt = $this->get($snippet, 'run_at', 'wp_footer');
                    if (in_array($runAt, ['wp_head', 'wp_footer', 'admin_head', 'admin_footer'])) {

                        $loadUrl = '';
                        $isFooter = false;

                        if ($this->get($snippet, 'load_as_file') == 'yes') {
                            $cachedFile = str_replace('.php', '.js', $fileName);
                            $loadUrl = $this->getCachedFileUrl($cachedFile);
                            if ($loadUrl) {
                                $isFooter = ($runAt == 'wp_footer' || $runAt == 'admin_footer');
                                $runAt = ($runAt == 'admin_head' || $runAt == 'admin_footer') ? 'admin_enqueue_scripts' : 'wp_enqueue_scripts';
                            }
                        }

                        add_action($runAt, function () use ($file, $snippet, $conditionalClass, $loadUrl, $isFooter) {
                            if (!$conditionalClass->evaluate($snippet['condition'])) {
                                return;
                            }

                            if ($loadUrl) {
                                $snippetScriptName = str_replace('.php', '', $snippet['file_name']);
                                wp_enqueue_script('fluent_snippet_' . $snippetScriptName, $loadUrl, [], strtotime($snippet['updated_at']), $isFooter);
                            } else {
                                $code = $this->parseBlock(file_get_contents($file), true);
                                ?>
                                <script><?php echo $this->escCssJs($code); // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
                                <?php
                            }
                        }, $this->get($snippet, 'priority', 10));
                    }
                    break;
                case 'css':
                    $runAt = $this->get($snippet, 'run_at', 'wp_head');

                    $isBlockStyle = $this->get($snippet, 'load_in_block_editor', '') === 'yes';
                    if ($isBlockStyle) {
                        add_filter('block_editor_settings_all', function ($settings) use ($snippet, $file) {
                            $code = $this->parseBlock(file_get_contents($file), true);
                            if ($code) {
                                $settings['styles'][] = array(
                                    'css'            => $code,
                                    '__unstableType' => 'plugin',
                                    'source'         => 'easy_code_manager'
                                );
                            }
                            return $settings;
                        }, $this->get($snippet, 'priority', 10));
                    }

                    // 'everywhere' was misspelled 'everywehere' here, so the CSS type's
                    // "Both Backend and Frontend" option never matched and always fell
                    // through to wp_head — which does not fire in the admin, so that
                    // option silently behaved as frontend-only.
                    if (($runAt == 'everywhere' && is_admin()) || $runAt == 'admin_head') {
                        $runAt = 'admin_head';
                    } else {
                        $runAt = 'wp_head';
                    }

                    $isAdminCss = ($runAt == 'admin_head');

                    $loadUrl = '';
                    if ($this->get($snippet, 'load_as_file') == 'yes') {
                        $cachedFile = str_replace('.php', '.css', $fileName);
                        $loadUrl = $this->getCachedFileUrl($cachedFile);
                        if ($loadUrl) {
                            $runAt = ($runAt == 'admin_head') ? 'admin_enqueue_scripts' : 'wp_enqueue_scripts';
                        }
                    }

                    if ($isAdminCss) {
                        add_action('enqueue_block_editor_assets', function () use ($file, $snippet, $conditionalClass, $loadUrl) {
                            if (!$conditionalClass->evaluate($snippet['condition'])) {
                                return;
                            }

                            $code = $this->parseBlock(file_get_contents($file), true);
                            wp_add_inline_style('wp-edit-blocks', $code);
                        });
                    }

                    add_action($runAt, function () use ($file, $snippet, $conditionalClass, $loadUrl) {
                        if (!$conditionalClass->evaluate($snippet['condition'])) {
                            return;
                        }

                        if ($loadUrl) {
                            $snippetScriptName = str_replace('.php', '', $snippet['file_name']);
                            wp_enqueue_style('fluent_snippet_' . $snippetScriptName, $loadUrl, [], strtotime($snippet['updated_at']));
                        } else {
                            $code = $this->parseBlock(file_get_contents($file), true);
                            ?>
                            <style><?php echo $this->escCssJs($code); // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped ?></style>
                            <?php
                        }

                    }, $this->get($snippet, 'priority', 10));

                    break;
                case 'php_content':
                    $runAt = $snippet['run_at'];
                    if (in_array($runAt, ['wp_footer', 'wp_head', 'wp_body_open'])) {
                        add_action($runAt, function () use ($file, $fileName, $snippet, $conditionalClass) {
                            if (!$conditionalClass->evaluate($snippet['condition'])) {
                                return;
                            }
                            $this->runSnippetFile($file, $fileName);
                        }, $snippet['priority']);
                    }
                    if (isset($filterMaps[$runAt])) {
                        $filter = $filterMaps[$runAt];
                        add_filter($filter['hook'], function ($content) use ($file, $fileName, $snippet, $conditionalClass, $filter) {
                            if (!empty($filter['is_single'])) {
                                if (!is_singular() || !in_the_loop() || !is_main_query()) {
                                    return $content;
                                }
                            }

                            if (!$conditionalClass->evaluate($snippet['condition'])) {
                                return $content;
                            }

                            ob_start();
                            $this->runSnippetFile($file, $fileName);
                            $result = ob_get_clean();
                            if ($result) {
                                if ($filter['insert'] == 'before') {
                                    return $result . $content;
                                }

                                return $content . $result;
                            }
                            return $content;
                        }, $this->get($snippet, 'priority', 10));
                    }

                    // Was falling through into an empty default. Harmless while default
                    // is empty, a trap for whoever adds the next case (M12).
                    break;
                default:
                    break;
            }
        }

        if ($hasInvalidFiles) {
            do_action('fluent_snippets/rebuild_index', false, true);
        }

        do_action('fluent_snippets/after_run_snippets');
    }


    /**
     * Run a snippet file with the running-snippet stack maintained around it.
     *
     * The pop is in a finally deliberately. A fatal error (E_ERROR and friends) does
     * not unwind, so finally does NOT run and the snippet stays on the stack to be
     * blamed — which is the case this whole mechanism exists for. An exception does
     * unwind, so the pop runs and the stack cannot be left dirty by a throw that
     * something upstream catches. That trade costs attribution for a snippet whose
     * uncaught exception is thrown inside core rather than in the snippet itself; a
     * wrongly quarantined working snippet is the worse outcome of the two.
     */
    private function runSnippetFile($file, $fileName)
    {
        self::pushRunningSnippet($fileName);

        try {
            require_once $file;
        } finally {
            self::popRunningSnippet();
        }
    }

    private function get($array, $key, $default = null)
    {
        if (isset($array[$key])) {
            return $array[$key];
        }

        return $default;
    }

    private function parseBlock($fileContent, $codeOnly = false)
    {
        // get content from // <Internal Doc Start> to // <Internal Doc End>
        $fileContent = explode('// <Internal Doc Start>', $fileContent);

        if (count($fileContent) < 2) {
            if ($codeOnly) {
                return '';
            }
            return [null, null];
        }

        $fileContent = explode('// <Internal Doc End> ?>' . PHP_EOL, $fileContent[1]);
        $docBlock = $fileContent[0];
        $code = $fileContent[1];

        if ($codeOnly) {
            return $code;
        }

        $docBlock = explode('*', $docBlock);
        // Explode by : and get the key and value
        $docBlockArray = [
            'name'        => '',
            'status'      => '',
            'tags'        => '',
            'description' => '',
            'type'        => '',
            'run_at'      => '',
            'group'       => ''
        ];

        foreach ($docBlock as $key => $value) {
            $value = trim($value);
            $arr = explode(':', $value);
            if (count($arr) < 2) {
                continue;
            }

            // get the first item from the array and remove it from $arr
            $key = array_shift($arr);
            $key = trim(str_replace('@', '', $key));
            if (!$key) {
                continue;
            }
            $docBlockArray[$key] = trim(implode(':', $arr));
        }

        return [$docBlockArray, $code];
    }


    /**
     * Make snippet code safe to print inside an inline <script> or <style> block.
     *
     * Kept byte-identical to Helper::escCssJs().
     *
     * Inside those two elements the HTML parser looks for nothing but the closing tag —
     * an *opening* `<script>` is ordinary text. Stripping opening tags was therefore
     * never needed for correctness, and it silently corrupted legitimate code such as
     * `document.write('<script src="..."></script>')`. That half is gone.
     *
     * The closing tag is now escaped rather than deleted. `<\/script` is identical to
     * `</script` everywhere it can legally appear in JS or CSS — string literals, regex
     * literals, comments — so the code keeps working *and* the block cannot be
     * terminated early. Deleting it changed behaviour; escaping preserves it.
     *
     * Case-insensitive and whitespace-tolerant because `</script >`, `</script\n>` and
     * `</SCRIPT>` are all terminators as far as an HTML parser is concerned (L1).
     */
    private function escCssJs($code)
    {
        return preg_replace_callback('#</(\s*)(script|style)#i', function ($matches) {
            return '<\\/' . $matches[1] . $matches[2];
        }, $code);
    }

    private function getCachedFileUrl($fileName)
    {
        $file = $this->storageDir . '/cached/' . $fileName;

        if (!file_exists($file)) {
            return false;
        }

        return $this->storageUrl . '/cached/' . $fileName;
    }
}
