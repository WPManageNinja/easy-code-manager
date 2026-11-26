<?php

namespace FluentSnippets\App\Services;

use FluentSnippets\App\Helpers\Helper;

/** Admin-only organization. Membership stays in each snippet, not in this registry. */
class SnippetGroups
{
    const HEADER = "<?php exit; ?>\n";
    private static $mutating = false;

    private static function path()
    {
        return Helper::getStorageDir() . '/groups/index.php';
    }

    private static function error($message, $status = 422, $data = [])
    {
        return new \WP_Error('snippet_groups', $message, array_merge(['status' => $status], $data));
    }

    private static function decode($raw)
    {
        if (!is_string($raw) || strpos($raw, self::HEADER) !== 0) {
            return self::error(__('The saved group list cannot be read. Restore the group metadata file from a backup before changing groups.', 'easy-code-manager'), 500);
        }
        $names = json_decode(substr($raw, strlen(self::HEADER)), true);
        if (!is_array($names) || array_values($names) !== $names || count(array_filter($names, 'is_string')) !== count($names)) {
            return self::error(__('The saved group list is invalid. Restore the group metadata file from a backup before changing groups.', 'easy-code-manager'), 500);
        }
        return $names;
    }

    public static function read()
    {
        if (!file_exists(self::path())) {
            return [];
        }
        // A storage-protection filter may read groups while an index is rebuilt.
        if (self::$mutating) return self::decode(@file_get_contents(self::path()));
        $handle = @fopen(dirname(self::path()) . '/.lock.php', 'rb');
        if (!$handle || !@flock($handle, LOCK_SH)) {
            if ($handle) fclose($handle);
            return self::error(__('The group list could not be opened. Check the snippet storage permissions.', 'easy-code-manager'), 500);
        }
        try {
            return self::decode(@file_get_contents(self::path()));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function name($value, $allowEmpty = false)
    {
        if (!is_string($value)) {
            return self::error(__('Enter a group name as plain text.', 'easy-code-manager'));
        }
        $name = trim($value);
        if ((!$allowEmpty && $name === '') || $name !== sanitize_text_field($name) || $name !== (string) Helper::sanitizeMetaValue($name)) {
            return self::error(__('Use a nonempty group name without markup, asterisks or line breaks.', 'easy-code-manager'));
        }
        return $name;
    }

    private static function save($handle, $names)
    {
        $names = array_values(array_unique(array_filter($names, function ($name) { return $name !== ''; })));
        sort($names, SORT_STRING);
        $json = json_encode($names, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }
        $data = self::HEADER . $json;
        return Helper::atomicPut(self::path(), $data) !== false;
    }

    private static function mutate($callback)
    {
        if (self::$mutating) return self::error(__('Another group change is already in progress.', 'easy-code-manager'), 409);
        $path = self::path();
        if (!wp_mkdir_p(dirname($path))) {
            return self::error(__('The group list could not be saved. Check the snippet storage permissions.', 'easy-code-manager'), 500);
        }
        // The stable, guarded lock stays separate from atomically replaced metadata.
        $handle = @fopen(dirname($path) . '/.lock.php', 'c+b');
        if (!$handle || !@flock($handle, LOCK_EX)) {
            if ($handle) fclose($handle);
            return self::error(__('The group list could not be saved. Check the snippet storage permissions.', 'easy-code-manager'), 500);
        }
        try {
            self::$mutating = true;
            if (fstat($handle)['size'] === 0 && @fwrite($handle, self::HEADER) !== strlen(self::HEADER)) return self::error(__('The group list could not be saved. Check the snippet storage permissions.', 'easy-code-manager'), 500);
            $exists = file_exists($path);
            $names = $exists ? self::decode(@file_get_contents($path)) : [];
            if (is_wp_error($names)) return $names;
            // Initialize before any mutation, so even a failed operation leaves a guarded file.
            if (!$exists && !self::save($handle, $names)) return self::error(__('The group list could not be saved. Check the snippet storage permissions.', 'easy-code-manager'), 500);
            return $callback($handle, $names);
        } finally {
            self::$mutating = false;
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function indexed()
    {
        $config = Helper::getIndexedConfig(false);
        return ($config['published'] ?? []) + ($config['draft'] ?? []);
    }

    private static function allNames($names)
    {
        foreach (self::indexed() as $snippet) {
            if (isset($snippet['group']) && (string) $snippet['group'] !== '') $names[] = trim($snippet['group']);
        }
        return array_values(array_unique($names));
    }

    public static function create($value)
    {
        $name = self::name($value);
        if (is_wp_error($name)) return $name;
        return self::mutate(function ($handle, $names) use ($name) {
            $names = self::allNames($names);
            if (in_array($name, $names, true)) return self::error(__('A group with this name already exists.', 'easy-code-manager'), 409);
            $names[] = $name;
            return self::save($handle, $names) ? ['message' => __('Group created.', 'easy-code-manager')] : self::error(__('The group list could not be saved. Check the snippet storage permissions.', 'easy-code-manager'), 500);
        });
    }

    /** Read only the metadata envelope; never parse or reserialize the snippet body. */
    private static function envelope($file)
    {
        if (!is_string($file) || $file === 'index.php' || basename($file) !== $file || sanitize_file_name($file) !== $file || substr($file, -4) !== '.php') return false;
        $path = Helper::getStorageDir() . '/' . $file;
        if (!is_file($path) || is_link($path)) return false;
        $raw = @file_get_contents($path);
        if (!is_string($raw) || !preg_match('/\A<\?php\s*\/\/ <Internal Doc Start>\s*\/\*.*?\*\//s', $raw, $match) || strpos($raw, '// <Internal Doc End>', strlen($match[0])) === false) return false;
        $header = $match[0];
        if (!preg_match('/^[ \t]*\*[ \t]*@type:[ \t]*\S+/m', $header)) return false;
        preg_match('/^[ \t]*\*[ \t]*@group:[ \t]*([^\r\n]*)/m', $header, $group);
        return ['path' => $path, 'raw' => $raw, 'header' => $header, 'group' => trim($group[1] ?? '')];
    }

    private static function change($entry, $name)
    {
        $header = preg_replace_callback('/(^[ \t]*\*[ \t]*@group:[ \t]*)[^\r\n]*/m', function ($match) use ($name) { return $match[1] . $name; }, $entry['header'], -1, $count);
        if (!$count) {
            $eol = strpos($header, "\r\n") !== false ? "\r\n" : "\n";
            $header = substr($header, 0, -2) . '* @group: ' . $name . $eol . '*/';
        }
        $header = preg_replace_callback('/(^[ \t]*\*[ \t]*@updated_at:[ \t]*)[^\r\n]*/m', function ($match) { return $match[1] . gmdate('Y-m-d H:i:s'); }, $header);
        // Compare while holding the same replacement lock ordinary editor saves use.
        $result = Helper::atomicPut($entry['path'], $header . substr($entry['raw'], strlen($entry['header'])), $entry['raw']);
        if ($result === false) return false;
        Helper::invalidateOpcache($entry['path']);
        return true;
    }

    public static function move($file, $value, $expected)
    {
        $name = self::name($value, true);
        if (is_wp_error($name)) return $name;
        if (!is_string($expected)) return self::error(__('The current group is required. Reload the snippet list and try again.', 'easy-code-manager'), 409);
        return self::mutate(function ($handle, $names) use ($file, $name, $expected) {
            $entry = self::envelope($file);
            if (!$entry) return self::error(__('The snippet file could not be read. Reload the list and try again.', 'easy-code-manager'), 404);
            if ($entry['group'] !== $expected) return self::error(__('This snippet has changed groups. Reload the list and try again.', 'easy-code-manager'), 409);
            $names = self::allNames($names);
            if ($name !== '' && !in_array($name, $names, true)) return self::error(__('The destination group no longer exists. Reload the list and try again.', 'easy-code-manager'), 409);
            if ($entry['group'] === $name) return ['message' => __('Snippet moved.', 'easy-code-manager')];
            $names[] = $entry['group'];
            if (!self::save($handle, $names)) return self::error(__('The group list could not be saved. Check the snippet storage permissions.', 'easy-code-manager'), 500);
            if (!self::change($entry, $name)) return self::error(__('The snippet could not be moved. Check permissions, reload the list and try again.', 'easy-code-manager'), 409);
            if (Helper::cacheSnippetIndex() === false) return self::error(__('The snippet file changed, but its index could not be updated. Reload the list and retry the index sync.', 'easy-code-manager'), 500);
            return ['message' => __('Snippet moved.', 'easy-code-manager')];
        });
    }

    public static function rename($value, $newValue, $retry = false)
    {
        $from = self::name($value);
        $to = self::name($newValue);
        if (is_wp_error($from)) return $from;
        if (is_wp_error($to)) return $to;
        return self::mutate(function ($handle, $names) use ($from, $to, $retry) {
            $names = self::allNames($names);
            if (!in_array($from, $names, true)) return self::error(__('The source group no longer exists. Reload the list and try again.', 'easy-code-manager'), 409);
            if ($from === $to) return ['message' => __('Group renamed.', 'easy-code-manager')];
            if (!$retry && in_array($to, $names, true)) return self::error(__('A group with this name already exists.', 'easy-code-manager'), 409);
            $names[] = $to;
            if (!self::save($handle, $names)) return self::error(__('The group list could not be saved. Check the snippet storage permissions.', 'easy-code-manager'), 500);
            $indexed = self::indexed();
            $files = array_unique(array_merge(array_keys($indexed), array_map('basename', glob(Helper::getStorageDir() . '/*.php') ?: [])));
            $changed = 0;
            $failed = 0;
            foreach ($files as $file) {
                $entry = self::envelope($file);
                if (!$entry) {
                    if (($indexed[$file]['group'] ?? '') === $from) $failed++;
                    continue;
                }
                if ($entry['group'] !== $from) continue;
                if (self::change($entry, $to)) $changed++; else $failed++;
            }
            $indexedOk = Helper::cacheSnippetIndex() !== false;
            if ($failed || !$indexedOk) {
                return self::error(sprintf(
                    /* translators: 1: changed snippet count, 2: failed snippet count */
                    __('Group rename is incomplete: %1$d snippets changed, %2$d failed. Both group names were kept. Check storage permissions, reload the list and retry the rename.', 'easy-code-manager'), $changed, $failed
                ), 409, ['partial' => true, 'changed' => $changed, 'failed' => $failed]);
            }
            $names = array_values(array_diff($names, [$from]));
            if (!self::save($handle, $names)) return self::error(__('The snippets were renamed, but the old group could not be removed. Reload and retry the rename.', 'easy-code-manager'), 500, ['partial' => true]);
            return ['message' => __('Group renamed.', 'easy-code-manager')];
        });
    }
}
