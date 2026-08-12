<?php

namespace FluentSnippets\App\Helpers;

use FluentSnippets\App\Model\Snippet;
use FluentSnippets\App\Services\PhpValidator;
use FluentSnippets\App\Services\SnippetErrors;

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
        if (!is_dir($dir)) {
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

    /**
     * Write a file atomically.
     *
     * The runtime include()s index.php on every request and require()s each published
     * snippet file. A plain file_put_contents() truncates the target first and then
     * writes, so for the duration of the write the file on disk is empty or half
     * written. A concurrent request that includes it in that window hits a parse error
     * and fatals.
     *
     * Writing to a temp file in the same directory and rename()-ing it over the target
     * makes the swap atomic: a reader sees either the whole old file or the whole new
     * one, never a torn one.
     *
     * Falls back to a direct write whenever the atomic path is unavailable, so a save
     * can never start failing because of this.
     *
     * @param string $file Absolute path of the destination file.
     * @param string $contents
     * @return int|false Number of bytes written, or false on failure.
     *
     * The writes are silenced because every caller now acts on the false return. Left
     * unsilenced, a permission warning is printed straight into the AJAX response and
     * the JSON the editor is waiting for arrives unparseable — turning "the folder is
     * not writable" into "the server sent back an unexpected response".
     */
    public static function atomicPut($file, $contents)
    {
        $dir = dirname($file);

        $tmpFile = @tempnam($dir, '.fluent-snippets-tmp');

        // tempnam() silently falls back to the system temp dir when $dir is not
        // writable, and a cross-device rename() is not atomic. Only take the atomic
        // path when the temp file really landed next to the target.
        if ($tmpFile === false || dirname($tmpFile) !== $dir) {
            if ($tmpFile !== false) {
                @unlink($tmpFile);
            }
            return @file_put_contents($file, $contents);
        }

        $bytesWritten = @file_put_contents($tmpFile, $contents);

        if ($bytesWritten === false) {
            @unlink($tmpFile);
            return false;
        }

        // tempnam() creates the file as 0600. Keep whatever the target already used so
        // host-specific setups (group-writable files, for instance) survive the swap.
        $perms = is_file($file) ? (@fileperms($file) & 0777) : 0;
        @chmod($tmpFile, $perms ? $perms : 0644);

        // rename() cannot overwrite an existing file on Windows.
        if (DIRECTORY_SEPARATOR === '\\' && is_file($file)) {
            @unlink($file);
        }

        if (!@rename($tmpFile, $file)) {
            @unlink($tmpFile);
            return @file_put_contents($file, $contents);
        }

        return $bytesWritten;
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

    /**
     * Drop an .htaccess into the storage directory denying direct access to its .php
     * files.
     *
     * Defence in depth, not a fix for a live hole. Every snippet file and index.php
     * already opens with an ABSPATH guard, so a request that PHP actually processes
     * returns nothing. This covers the case where it stops being processed — an
     * .htaccess lost in a migration, an nginx location block that never matched, a host
     * serving .php as text/plain. In that state the directory hands out every snippet's
     * source and the index's kill-switch secret.
     *
     * Scoped to .php deliberately: cached/ serves .css and .js to visitors and inherits
     * this file, so it has to stay publicly readable. Both Apache generations are
     * covered via IfModule so an older server does not 500 on an unknown directive.
     * Written once — an existing file is never overwritten — and skippable entirely
     * with the filter for anyone whose host dislikes it.
     */
    public static function protectStorageDir()
    {
        if (!apply_filters('fluent_snippets/protect_storage_dir', true)) {
            return;
        }

        $dir = self::getStorageDir();

        if (!is_dir($dir)) {
            return;
        }

        $file = $dir . '/.htaccess';

        if (file_exists($file)) {
            return;
        }

        $rules = '# Added by Fluent Snippets. Snippet source and the kill-switch secret live here.' . PHP_EOL
            . '<FilesMatch "\.php$">' . PHP_EOL
            . '    <IfModule mod_authz_core.c>' . PHP_EOL
            . '        Require all denied' . PHP_EOL
            . '    </IfModule>' . PHP_EOL
            . '    <IfModule !mod_authz_core.c>' . PHP_EOL
            . '        Order allow,deny' . PHP_EOL
            . '        Deny from all' . PHP_EOL
            . '    </IfModule>' . PHP_EOL
            . '</FilesMatch>' . PHP_EOL;

        @file_put_contents($file, $rules);
    }

    /**
     * A cheap signature of the snippet files currently on disk.
     *
     * Stat only — no file is opened. Covers create, delete, rename and in-place
     * edits, since changing a snippet moves its mtime. The one blind spot is an
     * edit that lands in the same second AND keeps the byte count identical; that
     * never matters here because every write path calls cacheSnippetIndex()
     * directly rather than going through the gate.
     *
     * index.php is excluded on purpose: it is the file this hash is stored in, so
     * counting it would change the signature on every rebuild and the gate would
     * never match.
     *
     * @return string
     */
    public static function getSnippetFilesHash()
    {
        $files = glob(self::getStorageDir() . '/*.php');

        if (!is_array($files)) {
            $files = [];
        }

        sort($files);

        $signature = '';

        foreach ($files as $file) {
            if (basename($file) === 'index.php') {
                continue;
            }

            $signature .= basename($file) . ':' . @filemtime($file) . ':' . @filesize($file) . '|';
        }

        return md5($signature);
    }

    /**
     * Rebuild the index only when the storage directory has actually changed.
     *
     * cacheSnippetIndex() re-reads and re-parses every snippet file and rewrites
     * index.php. That is correct after a write, but the admin also needs to heal
     * drift caused by changes made outside the plugin — FTP edits, a deploy, a
     * migration, a deleted index.php. Doing that with a full rebuild costs N file
     * reads plus a write every time it is checked. This gates it behind the
     * stat-only fingerprint above, so the overwhelmingly common "nothing changed"
     * case costs one glob and one stat per file.
     *
     * @return bool true when a rebuild was actually performed
     */
    public static function syncSnippetIndex()
    {
        $config = self::getIndexedConfig(false);

        if (!$config || empty($config['meta'])) {
            self::cacheSnippetIndex();
            return true;
        }

        $meta = $config['meta'];

        // A different plugin version may have changed the shape of the index, and a
        // different domain means the site was migrated or cloned. Neither shows up
        // in the file signature, so check them separately.
        $isStale = Arr::get($meta, 'cached_version') !== FLUENT_SNIPPETS_PLUGIN_VERSION
            || Arr::get($meta, 'cashed_domain') !== site_url();

        if (!$isStale && Arr::get($meta, 'files_hash') === self::getSnippetFilesHash()) {
            return false;
        }

        self::cacheSnippetIndex();

        return true;
    }

    /**
     * Rebuild index.php from the snippet files on disk.
     *
     * Took ($fileName, $isForced, $extraArgs) until 2026-08-11 and used none of them:
     * $fileName was overwritten by the loop below, $isForced was never read, and
     * $extraArgs was never passed by any caller. PHP allows extra arguments to a
     * user-defined function, so anything still calling the old signature keeps working.
     */
    public static function cacheSnippetIndex()
    {
        $data = [
            'published' => [],
            'draft'     => [],
            'hooks'     => []
        ];

        // Hooked here rather than only at install so existing sites pick it up: a rebuild
        // happens on every create, update, delete and import. No-ops once the file exists.
        self::protectStorageDir();

        // Taken before the snippets are read, not after. If a file changes midway
        // through the rebuild, the stored signature describes the older state and the
        // next sync rebuilds again — a wasted rebuild rather than a missed one.
        $filesHash = self::getSnippetFilesHash();

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

        // Arr::get throughout (M9): a config written by an older version, or one that
        // only just came into existence, can be missing any of these keys, and a direct
        // index would warn on every rebuild under PHP 8.
        $data['meta'] = [
            'secret_key'          => $previousConfig['meta']['secret_key'],
            'force_disabled'      => Arr::get($previousConfig['meta'], 'force_disabled', 'no'),
            'cached_at'           => date('Y-m-d H:i:s'),
            'cached_version'      => FLUENT_SNIPPETS_PLUGIN_VERSION,
            'cashed_domain'       => site_url(),
            'files_hash'          => $filesHash,
            'legacy_status'       => Arr::get($previousConfig['meta'], 'legacy_status'),
            'auto_disable'        => Arr::get($previousConfig['meta'], 'auto_disable', 'yes'),
            'auto_publish'        => Arr::get($previousConfig['meta'], 'auto_publish', 'no'),
            'remove_on_uninstall' => Arr::get($previousConfig['meta'], 'remove_on_uninstall', 'no')
        ];

        $errorFiles = Arr::get($previousConfig, 'error_files', []);

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
                return Arr::get($a, 'meta.priority', 10) <=> Arr::get($b, 'meta.priority', 10);
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

        /*
         * Prune to snippets that still exist (H3 / I7). Nothing else ever removed an
         * entry: CodeHandler::handleFileDelete() tried, but it unset() a local copy that
         * this method then discarded, so a deleted snippet's error stayed in the map
         * forever and the map only grew. Doing it here also self-heals every stale entry
         * already sitting on disk.
         *
         * This matters more since H2 — the auto-disable now catches parse errors and
         * downstream fatals it used to miss entirely, so error_files is written far more
         * often than it used to be.
         */
        $data['error_files'] = array_intersect_key($errorFiles, $data['published'] + $data['draft']);

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

        $bytesWritten = self::atomicPut($cacheFile, $code);

        self::invalidateOpcache($cacheFile);

        // The in-memory copy is now behind the file.
        self::flushIndexedConfigCache();

        return $bytesWritten;
    }

    private static $indexedConfig = null;

    private static $indexedConfigLoaded = false;

    public static function getIndexedConfig($cached = true)
    {
        // Keyed on a separate flag rather than on the config being truthy: an empty
        // config is the normal state on a fresh install, and `if ($config && $cached)`
        // is false for [], so every call re-ran is_file() plus an include for nothing.
        if (self::$indexedConfigLoaded && $cached) {
            return self::$indexedConfig;
        }

        self::$indexedConfig = self::getConfigFromFile();
        self::$indexedConfigLoaded = true;

        return self::$indexedConfig;
    }

    /**
     * Drop the in-memory copy so the next read comes from disk.
     *
     * Called after every write. Without it the cache goes stale the moment the index is
     * rebuilt: anything reading the config later in the same request would still see the
     * pre-rebuild copy. That was already true before M14 — it just never bit, because an
     * empty config is falsy and the old condition happened to re-read every time. Fixing
     * the cache made the staleness reachable, so the invalidation has to come with it.
     */
    public static function flushIndexedConfigCache()
    {
        self::$indexedConfig = null;
        self::$indexedConfigLoaded = false;
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

        // 'has_line_wrap' used to be declared here and nothing ever wrote or read it —
        // the setting is stored as 'enable_line_wrap'. Dropped rather than renamed,
        // because getConfigSettings()'s callers do not use it.
        $defaults = [
            'auto_disable'        => 'yes',
            'auto_publish'        => 'no',
            'remove_on_uninstall' => 'no',
            'legacy_status'       => 'new',
        ];

        if (!$config || empty($config['meta'])) {
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

    /**
     * The Safe Mode URL: the way back in when a snippet has locked you out of wp-admin.
     *
     * The key is a parameter rather than always read back from the config, because
     * regenerating it has to be able to build the new URL in the same request that wrote
     * it - getIndexedConfig() caches in a static, so reading it there would hand back the
     * key that was just replaced.
     *
     * @param string $secret Defaults to the key currently stored.
     * @return string
     */
    public static function getSafeModeUrl($secret = '')
    {
        if (!$secret) {
            $secret = self::getSecretKey();
        }

        return site_url('index.php?fluent_snippets=1&snippet_secret=' . $secret);
    }

    /**
     * Issue a new Safe Mode key, invalidating the old URL.
     *
     * Wanted whenever the URL has been somewhere it should not have been - a shared
     * document, a support thread, a screenshot, or an ex-colleague's bookmarks. It is a
     * password in URL form, and there was previously no way to change it short of
     * editing the generated index.php by hand.
     *
     * @return string|\WP_Error The new URL.
     */
    public static function regenerateSecretKey()
    {
        $config = self::getIndexedConfig();

        if (!$config || empty($config['meta'])) {
            return new \WP_Error('invalid_config', __('The snippet index could not be read, so a new Safe Mode URL could not be issued.', 'easy-code-manager'));
        }

        $secret = bin2hex(random_bytes(16));

        $config['meta']['secret_key'] = $secret;

        $saved = self::saveIndexedConfig($config);

        if (is_wp_error($saved)) {
            return $saved;
        }

        return self::getSafeModeUrl($secret);
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

        $stub = file_get_contents(FLUENT_SNIPPETS_PLUGIN_PATH . 'app/Services/mu.stub');

        if (!$stub) {
            return new \WP_Error('failed', 'mu.stub could not be read');
        }

        // Stamp the current plugin version into the copy so a later release can tell
        // that the installed runner is stale and refresh it. See maybeUpdateStandAlone().
        $stub = str_replace('{{FLUENT_SNIPPETS_VERSION}}', FLUENT_SNIPPETS_PLUGIN_VERSION, $stub);

        self::atomicPut($muDir . '/fluent-snippets-mu.php', $stub);

        if (!is_file($muDir . '/fluent-snippets-mu.php')) {
            return new \WP_Error('failed', 'file could not be moved to mu-plugins directory');
        }

        return true;
    }

    /**
     * Refresh the standalone runner when it is older than the plugin.
     *
     * The mu-plugin is only written when standalone mode is toggled or the plugin is
     * deactivated, so without this a site that enabled standalone on an older release
     * keeps running that release's runner forever — and that stale copy is exactly what
     * takes over the moment the plugin is deactivated.
     *
     * Hooked to admin_init: the check is two defined() calls, and a rewrite only happens
     * on the first admin request after an update.
     *
     * @return void
     */
    public static function maybeUpdateStandAlone()
    {
        if (!defined('FLUENT_SNIPPETS_RUNNING_MU')) {
            return; // Standalone mode is not enabled, nothing to refresh.
        }

        // A runner predating the version constant has no FLUENT_SNIPPETS_RUNNING_MU_VERSION
        // at all, so an undefined constant also means "stale".
        if (defined('FLUENT_SNIPPETS_RUNNING_MU_VERSION') && FLUENT_SNIPPETS_RUNNING_MU_VERSION === FLUENT_SNIPPETS_PLUGIN_VERSION) {
            return;
        }

        // The constants above come from the copy already loaded this request, so the
        // refreshed file takes effect from the next request. No rewrite loop.
        self::enableStandAlone(true);
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

    /**
     * Make a meta value safe to write into a snippet's docblock.
     *
     * Every value ends up in the file as `* @key: value`, and Snippet::parseBlock()
     * reads that block by splitting on `*` and keeping the LAST value it sees for any
     * key. So a value containing a `*` opens a new chunk, and a chunk that reads
     * `@status: published` overrides the status the server itself just wrote. That is
     * not theoretical: it is how an import, which forces every snippet to 'draft',
     * could produce a published — and therefore executing — PHP snippet.
     *
     * A newline is not required for this; a bare `*` is enough. Both are neutralised.
     *
     * `*` is turned into a space rather than dropped so values stay readable. The one
     * value where that would lose meaning is `condition`, which is JSON: Snippet::
     * getMetaData() escapes any `*` there as * before this runs, so it round-trips
     * through json_decode() untouched.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function sanitizeMetaValue($value)
    {
        if (is_numeric($value)) {
            return $value;
        }

        if (!$value) {
            return $value;
        }

        // `*/` first, so a closing comment marker cannot survive as `/` next to a
        // space and be reassembled by anything downstream.
        $value = str_replace('*/', '', $value);

        return str_replace(["\r\n", "\r", "\n", '*'], ' ', $value);
    }

    public static function handleDeactivate()
    {
        // Checks RUNNING_MU rather than RUNNING_MU_VERSION: a runner written before the
        // version constant existed still needs refreshing on deactivation, and that is
        // precisely when the standalone copy takes over.
        if (defined('FLUENT_SNIPPETS_RUNNING_MU')) {
            self::enableStandAlone(true);
        }
    }

    /**
     * Make snippet code safe to print inside an inline <script> or <style> block.
     *
     * Kept byte-identical to CodeRunner::escCssJs() (and its mu.stub copy).
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
    public static function escCssJs($code)
    {
        return preg_replace_callback('#</(\s*)(script|style)#i', function ($matches) {
            return '<\\/' . $matches[1] . $matches[2];
        }, $code);
    }

    /**
     * Reject CSS or JS that has been pasted wrapped in its own <script>/<style> tag.
     *
     * The editor already emits the surrounding element, so a leading tag ends up as
     * literal text inside it and breaks the snippet. That is a genuine mistake worth
     * refusing at save time.
     *
     * What is no longer refused: code that merely *mentions* a tag.
     * `document.write('<script src="...">')` is valid JavaScript and used to be rejected,
     * because the old check compared the code against escCssJs() output and treated any
     * difference as an error. Mentions are escaped on output instead.
     *
     * @param string $code
     * @return \WP_Error|false
     */
    public static function detectWrappingTag($code)
    {
        if (!preg_match('/^\s*<\s*(script|style)\b/i', $code, $matches)) {
            return false;
        }

        $tag = strtolower(trim($matches[1]));

        return SnippetErrors::make('invalid_code', [
            'title'  => sprintf(
                /* translators: %s: script or style */
                __('Remove the wrapping <%s> tag', 'easy-code-manager'),
                $tag
            ),
            'reason' => sprintf(
                /* translators: 1: script or style, 2: script or style */
                __('FluentSnippets outputs the <%1$s> element for you and puts this snippet inside it. A second <%2$s> tag in the code ends up printed as literal text on the page, and the snippet does nothing.', 'easy-code-manager'),
                $tag,
                $tag
            ),
            'fix'    => sprintf(
                /* translators: 1: opening tag, 2: closing tag */
                __('Delete the opening %1$s and its matching %2$s, and keep only what was between them.', 'easy-code-manager'),
                '<' . $tag . '>',
                '</' . $tag . '>'
            ),
        ], [
            'original' => $code
        ]);
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

        $code = self::prepareCodeForStorage($code, $meta);

        if (is_wp_error($code)) {
            return $code;
        }

        // check if the $code which is a php snippet is valid or not
        $snippetModel = new Snippet();
        $snippet = $snippetModel->updateSnippet($fileName, $code, $meta);

        if (is_wp_error($snippet)) {
            return $snippet;
        }

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

        $code = self::prepareCodeForStorage($code, $meta);

        if (is_wp_error($code)) {
            return $code;
        }

        $settings = self::getConfigSettings();

        if ($settings['auto_publish'] == 'yes') {
            $meta['status'] = 'published';
        }

        // check if the $code which is a php snippet is valid or not
        $snippetModel = new Snippet();
        $snippet = $snippetModel->createSnippet($code, $meta);

        if (is_wp_error($snippet)) {
            return $snippet;
        }

        do_action('fluent_snippets/snippet_created', $snippet);

        return $snippet;
    }

    /**
     * Normalise a snippet body and refuse it if it cannot safely be written.
     *
     * updateSnippet() and createSnippet() each carried their own copy of this, which is
     * how the wrapping-tag check ended up on the update path alone: a CSS snippet pasted
     * with its own <style> tag was rejected when edited but accepted when first created,
     * and quietly rendered the tag as text on the site. One copy, both paths.
     *
     * @param string $code Raw code as typed in the editor.
     * @param array $meta Snippet meta; only `type` is read here.
     * @return string|\WP_Error The code to write, or why it cannot be written.
     */
    private static function prepareCodeForStorage($code, $meta)
    {
        $type = Arr::get($meta, 'type');

        if ($type == 'PHP') {
            if (preg_match('/^<\?php/', $code)) {
                return SnippetErrors::make('invalid_code', [
                    'title'  => __('Remove the opening <?php tag', 'easy-code-manager'),
                    'reason' => __('FluentSnippets writes the opening PHP tag itself when it saves the file, so a second one in your code would be printed onto the page as text.', 'easy-code-manager'),
                    'fix'    => __('Delete the <?php line at the very top of the snippet and keep the rest as it is.', 'easy-code-manager'),
                ]);
            }

            $code = rtrim($code, '?>');
            $code = '<?php' . PHP_EOL . $code;
        } else if ($type == 'php_content') {
            $code = apply_filters('fluent_snippets/sanitize_mixed_content', $code, $meta);
            if (is_wp_error($code)) {
                return $code;
            }
        } else {
            $wrappingTag = self::detectWrappingTag($code);
            if ($wrappingTag) {
                return $wrappingTag;
            }
        }

        $validated = self::validateCode($type, $code);

        if (is_wp_error($validated)) {
            return SnippetErrors::fromValidator($validated);
        }

        return $code;
    }

    public static function validateMeta($meta)
    {
        $labels = [
            'name'   => __('Snippet Name', 'easy-code-manager'),
            'status' => __('Status', 'easy-code-manager'),
            'type'   => __('Snippet Type', 'easy-code-manager'),
            'run_at' => __('Where to run', 'easy-code-manager'),
        ];

        foreach ($labels as $key => $label) {
            if (!empty($meta[$key])) {
                continue;
            }

            /* translators: %s: name of the field that was left empty */
            $title = sprintf(__('%s is required', 'easy-code-manager'), $label);

            // The per-field key is kept in the data so the form can still highlight the
            // offending input; `error_details` is what the panel renders.
            return SnippetErrors::make($key, [
                'title'  => $title,
                'reason' => __('A snippet cannot be stored without this. It is written into the file header and is what tells FluentSnippets when and how to run your code.', 'easy-code-manager'),
                'fix'    => sprintf(
                    /* translators: %s: name of the field that was left empty */
                    __('Fill in the %s field on the right and save again.', 'easy-code-manager'),
                    $label
                ),
            ], [
                $key => $title
            ]);
        }

        return true;
    }
}
