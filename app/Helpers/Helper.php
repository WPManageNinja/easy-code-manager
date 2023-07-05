<?php

namespace FluentSnippets\App\Helpers;

use FluentSnippets\App\Services\PhpValidator;

class Helper
{
    public static function getStorageDir()
    {
        $upload_dir = wp_upload_dir();
        $baseDir = $upload_dir['basedir'];
        return $baseDir . '/fluent-snippet-storage';
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

    public static function cacheSnippetIndex($fileName = '', $isForced = false)
    {
        $data = [
            'published' => [],
            'draft'     => []
        ];

        $previousConfig = self::getIndexedConfig();

        if (!$previousConfig || empty($previousConfig['meta'])) {
            $previousConfig = [
                'meta'        => [
                    'auto_disable'        => 'yes',
                    'auto_publish'        => 'no',
                    'remove_on_uninstall' => 'no',
                    'force_disabled'      => 'no',
                    'secret_key'          => md5(wp_generate_uuid4() . time() . random_int(1000, 10000))
                ],
                'error_files' => []
            ];
        }

        if (empty($previousConfig['meta']['secret_key'])) {
            $previousConfig['meta']['secret_key'] = md5(wp_generate_uuid4() . time() . random_int(1000, 10000));
        }

        $data['meta'] = [
            'secret_key' => $previousConfig['meta']['secret_key'],
            'force_disabled'      => $previousConfig['meta']['force_disabled'],
            'cached_at'           => date('Y-m-d H:i:s'),
            'cached_version'      => FLUENT_SNIPPETS_PLUGIN_VERSION,
            'cashed_domain'       => site_url(),
            'auto_disable'        => $previousConfig['meta']['auto_disable'],
            'auto_publish'        => $previousConfig['meta']['auto_publish'],
            'remove_on_uninstall' => $previousConfig['meta']['remove_on_uninstall']
        ];

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
            'group'
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

            $data[$snippet['status']][$fileName] = $meta;
        }

        if ($errorFiles) {
//            // remove deleted snippets from cache
//            $errorIndexes = array_intersect(array_keys($errorFiles), array_keys(array_merge(array_keys($data['published']), array_keys($data['draft']))));
//
//            if ($errorIndexes) {
//                $errorFiles = Arr::only($errorFiles, $errorIndexes);
//            } else {
//                $errorFiles = [];
//            }
        }

        $data['error_files'] = $errorFiles;

        return self::saveIndexedConfig($data);
    }

    public static function saveIndexedConfig($data, $cacheFile = '')
    {
        if (!$cacheFile) {
            $cacheFile = self::getStorageDir() . '/index.php';

            if (!file_exists($cacheFile)) {
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

    public static function getIndexedConfig()
    {
        $cachedFile = self::getStorageDir() . '/index.php';
        if (!file_exists($cachedFile)) {
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
            'remove_on_uninstall' => 'no'
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

}

