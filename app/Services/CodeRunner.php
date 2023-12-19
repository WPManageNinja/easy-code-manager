<?php

namespace FluentSnippets\App\Services;

class CodeRunner
{

    private $storageDir = '';

    public function __construct()
    {
        $this->storageDir = WP_CONTENT_DIR . '/fluent-snippet-storage';
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

        if ($config['meta']['force_disabled'] == 'yes') {
            return; // this forcefully disabled via URL
        }

        $errorFiles = $this->get($config, 'error_files', []);

        $snippets = $config['published'];
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

            $type = $snippet['type'];

            switch ($type) {
                case 'PHP':
                    $conditionSettings = $snippet['condition'];
                    $hookName = 'init';
                    if (empty($conditionSettings) || empty($conditionSettings['status']) || $conditionSettings['status'] != 'yes' || empty($conditionSettings['items'])) {
                        $hookName = 'after_setup_theme';
                    }

                    add_action($hookName, function () use ($file, $snippet, $conditionalClass) {
                        if (!$conditionalClass->evaluate($snippet['condition'])) {
                            return;
                        }

                        $runAt = $this->get($snippet, 'run_at', 'all');
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
                    $runAt = $this->get($snippet, 'run_at', 'wp_footer');
                    if (in_array($runAt, ['wp_head', 'wp_footer'])) {
                        add_action($runAt, function () use ($file, $snippet, $conditionalClass) {
                            if (!$conditionalClass->evaluate($snippet['condition'])) {
                                return;
                            }

                            $code = $this->parseBlock(file_get_contents($file), true);
                            ?>
                            <script><?php echo $this->escCssJs($code); // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
                            <?php
                        }, 99);
                    }
                    break;
                case 'css':
                    $runAt = $this->get($snippet, 'run_at', 'wp_footer');
                    if ($runAt == 'everywehere' && is_admin()) {
                        $runAt = 'admin_head';
                    } else {
                        $runAt = 'wp_head';
                    }

                    add_action($runAt, function () use ($file, $snippet, $conditionalClass) {
                        if (!$conditionalClass->evaluate($snippet['condition'])) {
                            return;
                        }
                        $code = $this->parseBlock(file_get_contents($file), true);
                        ?>
                        <style><?php echo $this->escCssJs($code); // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped ?></style>
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
                                if (!is_singular() || !in_the_loop() || !is_main_query()) {
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
                                }

                                return $content . $result;
                            }
                            return $content;
                        }, $snippet['priority']);
                    }
                default:
                    break;
            }
        }

        if ($hasInvalidFiles) {
            do_action('fluent_snippets/rebuild_index', false, true);
        }

        do_action('fluent_snippets/after_run_snippets');
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


    private function escCssJs($code)
    {
        $code = preg_replace('/<script[^>]*>/', '', $code);
        $code = preg_replace('/<\/script>/', '', $code);
        // remove opening js tag and closing js tag maybe <script type="text/javascript"> too
        $code = preg_replace('/<style[^>]*>/', '', $code);
        return preg_replace('/<\/style>/', '', $code);
    }
}
