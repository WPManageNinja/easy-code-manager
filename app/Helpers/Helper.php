<?php

namespace FluentSnippets\App\Helpers;

use FluentSnippets\App\Model\Snippet;
use FluentSnippets\App\Services\PhpValidator;

class Helper
{
    /**
     * Get the storage directory path for snippets.
     *
     * The location can be overridden with the FLUENT_SNIPPETS_STORAGE_DIR constant in
     * wp-config.php. A constant is used (rather than a filter) on purpose: the standalone
     * mu-plugin runner loads before themes and regular plugins, so only a value available
     * at config-load time stays consistent between where snippets are written and where
     * they are read and executed.
     *
     * Example for wp-config.php:
     * define('FLUENT_SNIPPETS_STORAGE_DIR', WP_CONTENT_DIR . '/uploads/fluent-snippets');
     *
     * NOTE: keep this logic in sync with CodeRunner::resolveStorageDir() in app/Services/mu.stub
     *
     * @return string The storage directory path (no trailing slash)
     */
    public static function getStorageDir()
    {
        if (defined('FLUENT_SNIPPETS_STORAGE_DIR') && FLUENT_SNIPPETS_STORAGE_DIR) {
            return untrailingslashit(FLUENT_SNIPPETS_STORAGE_DIR);
        }

        return WP_CONTENT_DIR . '/fluent-snippet-storage';
    }

    /**
     * Get the URL for the storage directory.
     *
     * The URL can be overridden with the FLUENT_SNIPPETS_STORAGE_URL constant in
     * wp-config.php. When not set, it is derived from the storage directory path.
     *
     * NOTE: keep this logic in sync with CodeRunner::resolveStorageUrl() in app/Services/mu.stub
     *
     * @return string The storage directory URL (no trailing slash)
     */
    public static function getStorageUrl()
    {
        if (defined('FLUENT_SNIPPETS_STORAGE_URL') && FLUENT_SNIPPETS_STORAGE_URL) {
            return untrailingslashit(FLUENT_SNIPPETS_STORAGE_URL);
        }

        return self::deriveStorageUrl(self::getStorageDir());
    }

    /**
     * Derive a public URL for a storage directory that lives inside a known WordPress root.
     *
     * NOTE: keep this logic in sync with CodeRunner::deriveStorageUrl() in app/Services/mu.stub
     *
     * @param string $storageDir Absolute path, no trailing slash.
     * @return string
     */
    protected static function deriveStorageUrl($storageDir)
    {
        $defaultDir = WP_CONTENT_DIR . '/fluent-snippet-storage';
        if ($storageDir === $defaultDir) {
            return content_url('/fluent-snippet-storage');
        }

        // Path inside uploads/ (the recommended target for locked-down hosts).
        $uploadsDir = wp_upload_dir();
        $uploadsBasedir = untrailingslashit($uploadsDir['basedir']);
        if (strpos($storageDir, $uploadsBasedir) === 0) {
            return untrailingslashit($uploadsDir['baseurl']) . substr($storageDir, strlen($uploadsBasedir));
        }

        // Path inside wp-content/.
        if (strpos($storageDir, WP_CONTENT_DIR) === 0) {
            return content_url(substr($storageDir, strlen(WP_CONTENT_DIR)));
        }

        // Path inside the WordPress root.
        if (strpos($storageDir, untrailingslashit(ABSPATH)) === 0) {
            return site_url(substr($storageDir, strlen(untrailingslashit(ABSPATH))));
        }

        // Path is outside every known root: the URL cannot be derived. Fail loud so the
        // misconfiguration is visible instead of silently serving broken cached-asset URLs.
        // The site keeps working; only cached CSS/JS asset URLs are affected.
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FluentSnippets: cannot derive a URL for storage dir "' . $storageDir . '". Define FLUENT_SNIPPETS_STORAGE_URL in wp-config.php.');
        }

        return content_url('/fluent-snippet-storage');
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

    /**
     * Invalidate the OPcache entry for a snippet/engine file we just wrote.
     *
     * Our runtime include()s these PHP files. On hosts where OPcache caches
     * compiled bytecode (common in Docker/Alpine images, especially with
     * opcache.validate_timestamps=0), edits stay invisible until PHP-FPM is
     * restarted. Force-invalidating the exact path makes changes apply on the
     * next request without a restart.
     *
     * This is intentionally a best-effort, no-throw no-op: it does nothing when
     * OPcache is unavailable, disabled, or restricted via opcache.restrict_api,
     * so it is safe to call on every write across every hosting environment.
     *
     * @param string $file Absolute path to the file that was just written/removed.
     * @return void
     */
    public static function invalidateOpcache($file)
    {
        if (!$file || !function_exists('opcache_invalidate')) {
            return;
        }

        // force = true so it also invalidates when opcache.validate_timestamps=0.
        // Silenced because opcache.restrict_api can emit a warning for callers
        // outside the allowed path prefix; in that case it is simply a no-op.
        @opcache_invalidate($file, true);

        clearstatcache(true, $file);
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
            'load_as_file',
            'load_in_block_editor'
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

        $bytesWritten = file_put_contents($cacheFile, $code);

        self::invalidateOpcache($cacheFile);

        return $bytesWritten;
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
            'legacy_status'       => 'new',
            'has_line_wrap'       => 'no',

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

    public static function updateSnippet($data)
    {
        $meta = Arr::get($data, 'meta');
        $code = $data['code'];
        $fileName = $data['file_name'];
        $reactivate = $data['reactivate'];

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
                return new \WP_Error('invalid_code', 'Please remove any any style or script tag from the code', [
                    'santized' => $sanitizedCode,
                    'original'  => $code
                ]);
            }
        }

        // Validate the code
        $validated = self::validateCode($meta['type'], $code);

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

        if ($reactivate) {
            $config = Helper::getIndexedConfig();
            if (isset($config['error_files'][$fileName])) {
                unset($config['error_files'][$fileName]);
            }
            self::saveIndexedConfig($config);
        }

        do_action('fluent_snippets/snippet_updated', $snippet);

        return $snippet;
    }

    public static function createSnippet($data)
    {

        $meta = Arr::get($data, 'meta', []);
        $code = $data['code'];

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
        $validated = self::validateCode($meta['type'], $code);

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

        $settings = self::getConfigSettings();

        if ($settings['auto_publish'] == 'yes') {
            $meta['status'] = 'published';
        }

        // check if the $code which is a php snippet is valid or not
        $snippetModel = new Snippet();
        $snippet = $snippetModel->createSnippet($code, $meta);
        do_action('fluent_snippets/snippet_created', $snippet);

        return $snippet;
    }

    public static function validateMeta($meta)
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
}
