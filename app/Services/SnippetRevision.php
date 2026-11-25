<?php

namespace FluentSnippets\App\Services;

class SnippetRevision
{
    public static function parse($fileContent, $codeOnly = false)
    {
        // get content from // <Internal Doc Start> to // <Internal Doc End>
        $fileContent = explode('// <Internal Doc Start>', $fileContent, 2);

        if (count($fileContent) < 2) {
            if ($codeOnly) {
                return '';
            }
            return [null, null];
        }

        // Try different possible formats of the end marker
        $endMarkers = [
            '// <Internal Doc End> ?>' . PHP_EOL,
            '// <Internal Doc End> ?>',
            '<?php if (!defined("ABSPATH")) { return;} // <Internal Doc End> ?>' . PHP_EOL,
            '<?php if (!defined("ABSPATH")) { return;} // <Internal Doc End> ?>'
        ];

        $docBlock = null;
        $code = null;

        foreach ($endMarkers as $marker) {
            $parts = explode($marker, $fileContent[1], 2);
            if (count($parts) > 1) {
                $docBlock = $parts[0];
                $code = $parts[1];
                break;
            }
        }

        if ($docBlock === null || $docBlock === '' || $code === null) {
            if ($codeOnly) {
                return '';
            }
            return [null, null];
        }

        if ($codeOnly) {
            return $code;
        }

        $docBlock = explode('*', $docBlock);
        // Explode by : and get the key and value
        $docBlockArray = [
            'name'         => '',
            'status'       => '',
            'tags'         => '',
            'description'  => '',
            'type'         => '',
            'run_at'       => '',
            'group'        => '',
            // Defaulted because a hand-edited or legacy snippet file may have no
            // @priority line, and cacheSnippetIndex() sorts on it — a missing key warned
            // on every rebuild under PHP 8. Matches getMetaData()'s own default.
            'priority'     => 10,
            'condition'    => '',
            'load_as_file' => '',
            'load_in_block_editor' => ''
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

        if (isset($docBlockArray['network_sites'])) {
            $scope = json_decode($docBlockArray['network_sites'], true);
            // An unreadable restriction must not turn into an unrestricted snippet.
            $docBlockArray['network_sites'] = is_array($scope) ? $scope : [];
        }

        if (!empty($docBlockArray['condition'])) {
            $data = json_decode($docBlockArray['condition'], true);
            if ($data && is_array($data)) {
                $docBlockArray['condition'] = $data;
            }
        } else {
            $docBlockArray['condition'] = [
                'status' => 'no',
                'run_if' => 'assertive',
                'items'  => [[]]
            ];
        }

        if (empty($docBlockArray['condition'])) {
            $docBlockArray['condition'] = [
                'status' => 'no',
                'run_if' => 'assertive',
                'items'  => [[]]
            ];
        }

        return [$docBlockArray, $code];
    }

    public static function path($sourcePath, $revision)
    {
        return dirname($sourcePath) . '/.fluent-rev-' . $revision . '.php';
    }

    // A copied PHP snippet must still resolve relative resources as its author intended.
    public static function executable($source, $canonical, $temporary = false)
    {
        if (stripos($source, '__FILE__') === false && (!$temporary || stripos($source, '__DIR__') === false)) {
            return $source;
        }
        $result = '';
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                $result .= $token;
            } elseif ($token[0] === T_FILE) {
                $result .= $temporary ? var_export($canonical, true) : '(__DIR__ . ' . var_export('/' . basename($canonical), true) . ')';
            } elseif ($temporary && $token[0] === T_DIR) {
                $result .= var_export(dirname($canonical), true);
            } else {
                $result .= $token[1];
            }
        }
        return $result;
    }

    // Keep the temporary file alive through WordPress's last callback in this request.
    private static $legacyFiles = [];

    public static function resolve($canonical, &$meta)
    {
        if (isset($meta['revision'])) {
            if (!is_string($meta['revision']) || !preg_match('/^[a-f0-9]{64}$/D', $meta['revision'])) {
                return false;
            }
            $path = self::path($canonical, $meta['revision']);
            return is_file($path) ? $path : false;
        }

        // A read-only network may still have a pre-upgrade index. Freeze one read of
        // the authored file so its metadata and code cannot come from different saves.
        $source = @file_get_contents($canonical);
        if ($source === false) {
            return false;
        }
        if (!is_multisite() && !isset($meta['network_sites'])) {
            // A copied library may still carry a network selection in its authoring
            // file even when the old index predates this field.
            if (strpos($source, 'network_sites') !== false) {
                [$header] = self::parse($source);
                if (isset($header['network_sites'])) {
                    return false;
                }
            }
            return $canonical;
        }
        [$current] = self::parse($source);
        if (!$current || $current['status'] !== 'published') {
            return false;
        }
        $meta = array_merge($meta, $current);
        $meta['load_as_file'] = 'no';
        $key = $canonical . ':' . hash('sha256', $source);
        if (!isset(self::$legacyFiles[$key])) {
            $temp = tmpfile();
            if (!$temp) {
                return false;
            }
            $bytes = self::executable($source, realpath($canonical) ?: $canonical, true);
            if (fwrite($temp, $bytes) !== strlen($bytes) || !fflush($temp)) {
                fclose($temp);
                return false;
            }
            self::$legacyFiles[$key] = $temp;
        }
        return stream_get_meta_data(self::$legacyFiles[$key])['uri'];
    }
}
