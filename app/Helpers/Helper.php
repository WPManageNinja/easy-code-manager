<?php

namespace FluentSnippets\App\Helpers;

use FluentSnippets\App\Services\PhpValidator;

class Helper
{
    public static function getStorageDir()
    {
        return WP_CONTENT_DIR . '/fluent-snippet-storage';
    }

    public static function getCachedDir()
    {
        $dir = self::getStorageDir() . '/cached';

        // check if the directory exists
        if (!is_file($dir)) {
            wp_mkdir_p($dir);
            // add an empty index.php file to that dir
            file_put_contents($dir.'/index.php', '<?php // silence is golden');
        }

        return $dir;
    }

    public static function validateCode($language, $code)
    {
        if (!$code) {
            return true;
        }

        if ($language == 'PHP') {
            $validator = (new PhpValidator($code));
            $result = $validator->validate();
            if (is_wp_error($result)) {
                return $result;
            }
            return $validator->checkRunTimeError();
        }

        return true;
    }

    public static function cacheSnippetIndex($fileName = '', $isForced = false, $extraArgs = [])
    {
        $data = [
            'published' => [],
            'draft'     => [],
            'hooks'     => []
        ];

        $previousConfig = self::getIndexedConfig(false);

        if (!$previousConfig || empty($previousConfig['meta'])) {
            $previousConfig = [
                'meta'        => [
                    'auto_disable'        => 'yes',
                    'auto_publish'        => 'no',
                    'remove_on_uninstall' => 'no',
                    'force_disabled'      => 'no',
                    'legacy_status'       => 'new',
                    'secret_key'          => bin2hex(random_bytes(16))
                ],
                'error_files' => []
            ];
        }

        if (empty($previousConfig['meta']['secret_key'])) {
            $previousConfig['meta']['secret_key'] = bin2hex(random_bytes(16));
        }

        $data['meta'] = [
            'secret_key'          => $previousConfig['meta']['secret_key'],
            'force_disabled'      => $previousConfig['meta']['force_disabled'],
            'cached_at'           => date('Y-m-d H:i:s'),
            'cached_version'      => FLUENT_SNIPPETS_PLUGIN_VERSION,
            'cashed_domain'       => site_url(),
            'legacy_status'       => Arr::get($previousConfig['meta'], 'legacy_status'),
            'auto_disable'        => $previousConfig['meta']['auto_disable'],
            'auto_publish'        => $previousConfig['meta']['auto_publish'],
            'remove_on_uninstall' => $previousConfig['meta']['remove_on_uninstall']
        ];

        if ($extraArgs) {
            $data['meta'] = wp_parse_args($extraArgs, $data['meta']);
        }

        $errorFiles = $previousConfig['error_files'];

        $metaKeys = [
            'name',
            'description',
            'type',
            'status',
            'tags',
            'created_at',
            'updated_at',
            'run_at',
            'priority',
            'group',
            'condition',
            'load_as_file'
        ];

        $snippets = (new \FluentSnippets\App\Model\Snippet())->get();

        if ($snippets) {
            usort($snippets, function ($a, $b) {
                return $a['meta']['priority'] <=> $b['meta']['priority'];
            });
        }

        foreach ($snippets as $snippet) {
            $meta = Arr::only($snippet['meta'], $metaKeys);
            $fileName = basename($snippet['file']);

            // remove new line from $meta['description'] and limit to it 101 chars
            $meta['description'] = substr(str_replace(PHP_EOL, ". ", $meta['description']), 0, 101);

            if ($snippet['status'] != 'published') {
                $snippet['status'] = 'draft';
            }

            if (!is_numeric($meta['priority']) || $meta['priority'] < 1) {
                $meta['priority'] = 10;
            }

            $meta['priority'] = (int)$meta['priority'];
            $meta['file_name'] = $fileName;
            $meta['status'] = $snippet['status'];

            if ($meta['status'] == 'published') {
                $runningHook = self::getRunAtHook($meta);
                if (empty($data['hooks'][$runningHook])) {
                    $data['hooks'][$runningHook] = [];
                }

                $data['hooks'][$runningHook][] = $fileName;
            }

            $data[$snippet['status']][$fileName] = $meta;
        }

        $data['error_files'] = $errorFiles;

        return self::saveIndexedConfig($data);
    }

    public static function getRunAtHook($meta)
    {
        $runAt = $meta['run_at'];
        switch ($runAt) {
            case 'before_content':
            case 'after_content':
                return 'the_content';
            default:
                return $runAt;
        }
    }

    public static function saveIndexedConfig($data, $cacheFile = '')
    {
        if (!$cacheFile) {
            $cacheFile = self::getStorageDir() . '/index.php';

            if (!is_file($cacheFile)) {
                wp_mkdir_p(dirname($cacheFile));
            }
        }

        $code = <<<PHP
<?php
if (!defined("ABSPATH")) {return;}
/*
 * This is an auto-generated file by Fluent Snippets plugin.
 * Please do not edit manually.
 */

PHP;

        $code .= 'return ' . var_export($data, true) . ';';

        return file_put_contents($cacheFile, $code);
    }

    public static function getIndexedConfig($cached = true)
    {
        static $config = null;

        if ($config && $cached) {
            return $config;
        }

        $config = self::getConfigFromFile();

        return $config;
    }

    private static function getConfigFromFile()
    {
        $cachedFile = self::getStorageDir() . '/index.php';

        if (!is_file($cachedFile)) {
            return [];
        }

        return include $cachedFile;
    }

    public static function getConfigSettings()
    {
        $config = self::getIndexedConfig();

        $defaults = [
            'auto_disable'        => 'yes',
            'auto_publish'        => 'no',
            'remove_on_uninstall' => 'no',
            'legacy_status'       => 'new'
        ];

        if (!$config) {
            return $defaults;
        }

        $settings = Arr::only($config['meta'], array_keys($defaults));
        $settings = array_filter($settings);

        return wp_parse_args($settings, $defaults);
    }

    public static function getErrorFiles()
    {
        $config = self::getIndexedConfig();

        if (!$config || empty($config['error_files'])) {
            return [];
        }

        return $config['error_files'];
    }

    public static function getSecretKey()
    {
        $config = self::getIndexedConfig();
        return Arr::get($config, 'meta.secret_key');
    }

    public static function enableStandAlone($isForced = false)
    {
        if (defined('FLUENT_SNIPPETS_RUNNING_MU_VERSION') && FLUENT_SNIPPETS_RUNNING_MU_VERSION == FLUENT_SNIPPETS_PLUGIN_VERSION && !$isForced) {
            return true;
        }

        $muDir = WPMU_PLUGIN_DIR;
        if (!is_dir($muDir)) {
            mkdir($muDir, 0755);
        }

        if (!is_dir($muDir)) {
            return new \WP_Error('failed', 'mu-plugins dir could not be created');
        }

        file_put_contents(
            $muDir . '/fluent-snippets-mu.php',
            file_get_contents(FLUENT_SNIPPETS_PLUGIN_PATH . 'app/Services/mu.stub')
        );

        if (!is_file($muDir . '/fluent-snippets-mu.php')) {
            return new \WP_Error('failed', 'file could not be moved to mu-plugins directory');
        }

        return true;
    }

    public static function disableStandAlone()
    {
        $muDir = WPMU_PLUGIN_DIR;

        if (!is_file($muDir . '/fluent-snippets-mu.php')) {
            return true;
        }

        @unlink(WPMU_PLUGIN_DIR . '/fluent-snippets-mu.php');

        return true;

    }

    public static function getUserRoles()
    {
        $roles = get_editable_roles();

        $formattedRoles = [];

        foreach ($roles as $role => $data) {
            $formattedRoles[$role] = $data['name'];
        }

        return $formattedRoles;
    }

    public static function sanitizeMetaValue($value)
    {
        if (is_numeric($value)) {
            return $value;
        }

        if (!$value) {
            return $value;
        }

        if (str_contains($value, '*/')) {
            $value = str_replace('*/', '', $value); // we will not allow */ in meta values
        }

        return $value;
    }

    public static function handleDeactivate()
    {
        if (defined('FLUENT_SNIPPETS_RUNNING_MU_VERSION')) {
            self::enableStandAlone(true);
        }
    }

    public static function escCssJs($code)
    {
        $code = preg_replace('/<script[^>]*>/', '', $code);
        $code = preg_replace('/<\/script>/', '', $code);
        // remove opening js tag and closing js tag maybe <script type="text/javascript"> too
        $code = preg_replace('/<style[^>]*>/', '', $code);
        return preg_replace('/<\/style>/', '', $code);
    }
}
