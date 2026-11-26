<?php

namespace FluentSnippets\App\Model;

use FluentSnippets\App\Helpers\Arr;
use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Services\SnippetErrors;

class Snippet
{

    const MAX_CODE_BYTES = 1048576;

    private $args = [];

    public function __construct($args = [])
    {
        $this->args = $args;
    }

    public function get($args = [])
    {
        if ($args) {
            $this->args = $args;
        }

        $args = $this->args;

        $snippetDir = Helper::getStorageDir();
        // get the file paths and store them in an array
        $files = glob($snippetDir . '/*.php');

        if (isset($args['order']) && $args['order'] == 'new_first') {
            $files = array_reverse($files);
        }

        $formattedFiles = [];
        foreach ($files as $file) {
            $fileContent = file_get_contents($file);
            $record = $this->parseBlock($fileContent);
            [$docBlockArray, $code] = $record;

            if (!$docBlockArray) {
                continue;
            }

            if (!empty($args['status'])) {
                if ($args['status'] !== $docBlockArray['status']) {
                    continue;
                }
            }

            $formattedFiles[] = (isset($record[2]) ? ['compiled_css' => $record[2]] : []) + [
                'meta'   => $docBlockArray,
                'code'   => $code,
                'file'   => $file,
                'status' => (!empty($docBlockArray['status'])) ? $docBlockArray['status'] : 'draft'
            ];
        }
        return $formattedFiles;
    }

    public function paginate($perPage = null, $page = null)
    {
        if ($perPage === null) {
            if (isset($_GET['per_page'])) {
                $perPage = $_GET['per_page'];
            } else {
                $perPage = 10;
            }
        }

        if ($page === null) {
            if (isset($_GET['page'])) {
                $page = $_GET['page'];
            } else {
                $page = 1;
            }
        }
        $offset = ($page - 1) * $perPage;

        $snippets = $this->get([
            'order' => 'new_first'
        ]);

        $total = count($snippets);
        $snippets = array_slice($snippets, $offset, $perPage);

        return [
            'data'         => $snippets,
            'total'        => $total,
            'per_page'     => (int)$perPage,
            'current_page' => (int)$page,
            'last_page'    => (int)ceil($total / $perPage)
        ];
    }

    public function getIndexedSnippets($perPage = null, $page = null)
    {
        $config = Helper::getIndexedConfig();

        if (!$config || empty($config['meta'])) {
            return $this->emptyIndexedSnippets($perPage, $page);
        }

        if (empty($config['published']) && empty($config['draft'])) {
            return $this->emptyIndexedSnippets($perPage, $page);
        }

        if (!empty($this->args['status'])) {
            if ($this->args['status'] == 'published') {
                $snippets = $config['published'];
            } else if ($this->args['status'] == 'draft') {
                $snippets = $config['draft'];
            } else if ($this->args['status'] == 'paused') {
                $snippets = array_merge($config['published'], $config['draft']);
                $errorFiles = Arr::get($config, 'error_files', []);
                $snippets = Arr::only($snippets, array_keys($errorFiles));
            } else {
                $snippets = array_merge($config['published'], $config['draft']);
            }
        } else {
            $snippets = array_merge($config['published'], $config['draft']);
        }

        if (empty($snippets)) {
            return $this->emptyIndexedSnippets($perPage, $page);
        }

        $errorFiles = Arr::get($config, 'error_files', []);
        if ($errorFiles) {
            foreach ($errorFiles as $fileName => $error) {
                if (isset($snippets[$fileName])) {
                    $snippets[$fileName]['error'] = $error;
                }
            }
        }

        $snippets = array_values($snippets);

        $type = Arr::get($this->args, 'type');

        if ($type && $type != 'all') {
            $snippets = array_filter($snippets, function ($snippet) use ($type) {
                return $snippet['type'] == $type;
            });
        }

        if ($search = Arr::get($this->args, 'search')) {
            $snippets = array_filter($snippets, function ($snippet) use ($search) {
                // stripos, not strpos: searching "Header" should find "header script".
                return (stripos($snippet['name'], $search) !== false) || (stripos($snippet['description'], $search) !== false) || (stripos($snippet['tags'], $search) !== false) || (stripos($snippet['group'], $search) !== false);
            });
        }

        if ($tag = Arr::get($this->args, 'tag')) {
            $snippets = array_filter($snippets, function ($snippet) use ($tag) {
                if (!$snippet['tags']) {
                    return false;
                }
                $tags = array_map('trim', explode(',', $snippet['tags']));
                return in_array($tag, $tags);
            });
        }

        $snippets = $this->sortSnippets($snippets);

        if ($perPage != null && $page != null) {
            $total = count($snippets); // has to be counted before slicing the current page out
            $snippets = array_slice($snippets, ($page - 1) * $perPage, $perPage);
            return [
                'data'      => $snippets,
                'page'      => (int)$page,
                'per_page'  => (int)$perPage,
                'total'     => $total,
                'last_page' => (int)ceil($total / $perPage)
            ];
        }

        return $snippets;
    }

    /*
     * Keeps the response shape consistent when there is nothing to return.
     * Without this the paginated callers get a bare array and no data key.
     */
    private function emptyIndexedSnippets($perPage = null, $page = null)
    {
        if ($perPage != null && $page != null) {
            return [
                'data'      => [],
                'page'      => (int)$page,
                'per_page'  => (int)$perPage,
                'total'     => 0,
                'last_page' => 0
            ];
        }

        return [];
    }

    private function sortSnippets($snippets)
    {
        $sortingMaps = [
            'created_at' => 'strtotime',
            'updated_at' => 'strtotime',
            'priority'   => 'intval',
            'name'       => 'strtolower',
        ];

        $sortBy = $this->args['sort_by'] ?? 'created_at';
        $sortOrder = $this->args['sort_order'] ?? 'desc';

        $callback = Arr::get($sortingMaps, $sortBy);

        if (!$callback) {
            return $snippets;
        }

        // Short the snippets by name
        usort($snippets, function ($a, $b) use ($sortBy, $sortOrder, $callback) {
            $value1 = call_user_func($callback, $a[$sortBy]);
            $value2 = call_user_func($callback, $b[$sortBy]);
            if ($sortBy == 'name') {
                return $sortOrder == 'asc' ? strcasecmp(trim($a['name']), trim($b['name'])) : strcasecmp(trim($b['name']), trim($a['name']));
            }
            return $sortOrder == 'asc' ? $value1 <=> $value2 : $value2 <=> $value1;
        });

        return $snippets;
    }

    public function getAllSnippetTagsGroups()
    {
        $config = Helper::getIndexedConfig();

        if (!$config || empty($config['meta'])) {
            return [[], []];
        }

        if (empty($config['published']) && empty($config['draft'])) {
            return [[], []];
        }

        $snippets = array_merge($config['published'], $config['draft']);
        if (!$snippets) {
            return [[], []];
        }

        $allTags = [];
        $allGroups = [];

        foreach ($snippets as $snippet) {
            if (!empty($snippet['tags'])) {
                $tags = array_map('trim', explode(',', $snippet['tags']));
                $allTags = array_merge($allTags, $tags);
            }

            if (!empty($snippet['group'])) {
                $allGroups[] = trim($snippet['group']);
            }
        }

        $allTags = array_unique($allTags);
        asort($allTags);
        $allGroups = array_unique($allGroups);
        asort($allGroups);
        return [array_values($allTags), array_values($allGroups)];
    }

    public function findByFileName($fileName)
    {
        $snippetDir = Helper::getStorageDir();
        $file = $snippetDir . '/' . $fileName;

        if (!is_file($file) || $fileName === 'index.php') {
            return SnippetErrors::fileMissing($fileName);
        }

        $fileContent = file_get_contents($snippetDir . '/' . $fileName);
        $record = $this->parseBlock($fileContent);
        [$docBlockArray, $code] = $record;
        if (!$docBlockArray && strpos($fileContent, '<?php // SCSS-1 ') === 0) {
            return SnippetErrors::fileMissing($fileName);
        }

        return (isset($record[2]) ? ['compiled_css' => $record[2]] : []) + [
            'meta'   => $docBlockArray,
            'code'   => $code,
            'file'   => $file,
            'status' => (!empty($docBlockArray['status'])) ? $docBlockArray['status'] : 'draft'
        ];
    }

    public function updateSnippet($fileName, $code, $metaData, $compiledCss = null)
    {
        $metaData['updated_at'] = date('Y-m-d H:i:s');

        $file = Helper::getStorageDir() . '/' . $fileName;

        if (!is_file($file)) {
            return SnippetErrors::fileMissing($fileName);
        }

        if (($metaData['type'] ?? '') === 'scss') {
            return $this->writeScss($fileName, $code, $compiledCss, $metaData);
        }

        $docBlockString = $this->parseInputMeta($metaData, true);
        $fullCode = $docBlockString . $code;

        // atomicPut() returns false when the storage directory is not writable. Ignoring
        // that was the one failure mode where the plugin actively lied: the editor said
        // "Snippet has been updated successfully" and the old code came back on reload.
        if (Helper::atomicPut($file, $fullCode) === false) {
            return SnippetErrors::writeFailed($file);
        }

        Helper::invalidateOpcache($file);

        $type = Arr::get($metaData, 'type');

        if ($type == 'css' || $type == 'js') {
            $this->maybeCacheCssJs($fileName, $metaData, $code);
        }

        return $fileName;
    }

    public function createSnippet($code, $metaData, $compiledCss = null)
    {
        $storageDir = Helper::getStorageDir();
        $fileCount = count(glob($storageDir . '/*.php'));

        if (!$fileCount) {
            Helper::cacheSnippetIndex();
            $fileCount = 1;
        }

        // get the first 4 words of the snippet name
        $fileTitle = $metaData['name'];
        $nameArr = explode(' ', $fileTitle);
        if (count($nameArr) > 4) {
            $nameArr = array_slice($nameArr, 0, 4);
            $fileTitle = implode(' ', $nameArr);
        }

        $fileTitle = sanitize_title($fileTitle, 'snippet');

        $fileName = $fileCount . '-' . $fileTitle . '.php';

        $fileName = sanitize_file_name($fileName);

        $file = $storageDir . '/' . $fileName;

        if (is_file($file)) {
            return SnippetErrors::make('file_exists', [
                'title'  => __('A snippet file with this name already exists', 'easy-code-manager'),
                'reason' => sprintf(
                    /* translators: %s: snippet file name */
                    __('Snippet files are named after the snippet, and %s is taken. This normally happens when a snippet was deleted and recreated, so the numbering no longer lines up.', 'easy-code-manager'),
                    $fileName
                ),
                'fix'    => __('Change the snippet name slightly and save again.', 'easy-code-manager'),
            ]);
        }

        if (($metaData['type'] ?? '') === 'scss') {
            return $this->writeScss($fileName, $code, $compiledCss, $metaData);
        }

        $docBlockString = $this->parseInputMeta($metaData, true);

        $fullCode = $docBlockString . $code;

        if (Helper::atomicPut($file, $fullCode) === false) {
            return SnippetErrors::writeFailed($file);
        }

        Helper::invalidateOpcache($file);

        $this->maybeCacheCssJs($fileName, $metaData, $code);

        return $fileName;
    }

    private function writeScss($fileName, $source, $css, $meta)
    {
        if (!is_string($source) || !is_string($css) || strlen($source) > self::MAX_CODE_BYTES || strlen($css) > self::MAX_CODE_BYTES
            || strpos($css, '<?') !== false || stripos($css, '</style') !== false) {
            return SnippetErrors::make('invalid_scss_pair', [
                'title' => __('Invalid SCSS source or compiled CSS', 'easy-code-manager'),
                'fix' => __('Send source and compiled CSS together, each within 1 MB, with no PHP or style tags in the stylesheet.', 'easy-code-manager')
            ]);
        }
        $wrapping = Helper::detectWrappingTag($css);
        if ($wrapping) {
            return $wrapping;
        }

        // Keep original source in a PHP comment. One rename publishes it and the CSS body together.
        $header = $this->parseInputMeta($meta, true);
        $record = '<?php // SCSS-1 ' . base64_encode($source) . substr($header, 5) . $css;
        $file = Helper::getStorageDir() . '/' . $fileName;
        $previousCache = '';
        if (is_file($file)) {
            $previous = $this->findByFileName($fileName);
            if (!is_wp_error($previous) && isset($previous['compiled_css']) && ($previous['meta']['load_as_file'] ?? '') === 'yes') {
                $previousCache = $this->scssCachePath(dirname($file) . '/cached', $fileName, $previous['compiled_css']);
            }
        }
        if (Helper::atomicPut($file, $record, true) === false) {
            return SnippetErrors::writeFailed($file);
        }
        Helper::invalidateOpcache($file);
        // A missing derivative is safe: playback reads the compiled body from the committed record.
        $cached = '';
        if (($meta['load_as_file'] ?? '') === 'yes' && ($meta['status'] ?? '') === 'published') {
            $cached = $this->scssCachePath(Helper::getCachedDir(), $fileName, $css);
            Helper::atomicPut($cached, $css, true);
        }
        if ($previousCache && $previousCache !== $cached && is_file($previousCache)) {
            // Begin the retention period when this revision stops being published.
            touch($previousCache);
        }
        $this->cleanScssCache($fileName, $cached);
        return $fileName;
    }

    private function scssCachePath($directory, $fileName, $css)
    {
        return $directory . '/' . basename($fileName, '.php') . '-scss-' . hash('sha256', $css) . '.css';
    }

    private function cleanScssCache($fileName, $keep = '')
    {
        $dir = Helper::getStorageDir();
        $prefix = $dir . '/cached/' . basename($fileName, '.php') . '-scss-';
        $deleted = !is_file($dir . '/' . $fileName);
        foreach (glob($prefix . '*.css') ?: [] as $path) {
            if ($path === $keep || !preg_match('/^[a-f0-9]{64}\\.css$/D', substr($path, strlen($prefix)))) {
                continue;
            }
            // Published pages or concurrent writers may still refer to a recent revision.
            if ($deleted || filemtime($path) < time() - 86400) {
                @unlink($path);
            }
        }
    }

    public function deleteSnippet($fileName)
    {
        $snippetDir = Helper::getStorageDir();
        $file = $snippetDir . '/' . $fileName;

        // `&&` here meant the guard never fired for the files it names: index.php exists,
        // so !is_file() was false and the whole condition collapsed to false, falling
        // through to unlink(). Unreachable in practice — the only caller runs
        // findByFileName() first, which rejects index.php — but the protection this reads
        // as providing was not there at all.
        if (!is_file($file) || $fileName === 'index.php' || $fileName === 'cached') {
            return new \WP_Error('file_not_found', 'File not found');
        }

        unlink($file);
        $this->cleanScssCache($fileName);

        Helper::invalidateOpcache($file);

        return true;
    }

    public function parseBlock($fileContent, $codeOnly = false)
    {
        if (strpos($fileContent, '<?php // SCSS-1 ') === 0) {
            $parts = preg_split('~^<\?php if \(!defined\("ABSPATH"\)\) \{ return;\} // <Internal Doc End> \?>\r?\n~m', $fileContent, 2);
            if (count($parts) !== 2 || !preg_match('~^<\?php // SCSS-1 ([A-Za-z0-9+/=]*)\r?\n~', $parts[0], $matches)) {
                return $codeOnly ? '' : [null, null];
            }
            if ($codeOnly) {
                return $parts[1];
            }
            $source = base64_decode($matches[1], true);
            $meta = $this->parseMetadata(substr($parts[0], strlen($matches[0])));
            if ($source === false || ($meta['type'] ?? '') !== 'scss') {
                return [null, null];
            }
            return [$meta, $source, $parts[1]];
        }
        // get content from // <Internal Doc Start> to // <Internal Doc End>
        $fileContent = explode('// <Internal Doc Start>', $fileContent);

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
            $parts = explode($marker, $fileContent[1]);
            if (count($parts) > 1) {
                $docBlock = $parts[0];
                $code = $parts[1];
                break;
            }
        }

        if (!$docBlock || !$code) {
            if ($codeOnly) {
                return '';
            }
            return [null, null];
        }

        if ($codeOnly) {
            return $code;
        }

        return [$this->parseMetadata($docBlock), $code];
    }

    private function parseMetadata($docBlock)
    {
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

        return $docBlockArray;
    }

    private function parseInputMeta($metaData, $convertString = false)
    {
        $metaDefaults = [
            'description'  => '',
            'tags'         => '',
            'group'        => '',
            'name'         => 'Snippet Created @ ' . current_time('mysql'),
            'type'         => 'PHP',
            'status'       => 'draft',
            'created_by'   => get_current_user_id(),
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
            'is_valid'     => 1,
            'updated_by'   => get_current_user_id(),
            'priority'     => 10,
            'run_at'       => '',
            'load_as_file' => '',
            'load_in_block_editor' => '',
            'condition'    => [
                'status' => 'no',
                'run_if' => 'assertive',
                'items'  => [[]]
            ]
        ];

        $metaData = Arr::only($metaData, array_keys($metaDefaults));
        $metaData = wp_parse_args($metaData, $metaDefaults);

        if (!is_numeric($metaData['priority']) || $metaData['priority'] < 1) {
            $metaData['priority'] = 10;
        }

        if (!$convertString) {
            return $metaData;
        }

        $metaData['condition'] = json_encode($metaData['condition']);

        // Helper::sanitizeMetaValue() has to neutralise `*` in every meta value, because
        // parseBlock() splits the docblock on it. For this one value that would corrupt
        // real data, so escape it as a JSON unicode escape instead — json_decode() turns
        // * back into `*`, and the docblock never sees a literal one.
        $metaData['condition'] = str_replace('*', '\\u002a', $metaData['condition']);

        $docBlockString = '<?php' . PHP_EOL . '// <Internal Doc Start>' . PHP_EOL . '/*' . PHP_EOL . '*';

        foreach ($metaData as $key => $value) {
            $docBlockString .= PHP_EOL . '* @' . $key . ': ' . Helper::sanitizeMetaValue($value);
        }

        $docBlockString .= PHP_EOL . '*/' . PHP_EOL . '?>' . PHP_EOL . '<?php if (!defined("ABSPATH")) { return;} // <Internal Doc End> ?>' . PHP_EOL;

        return $docBlockString;
    }

    private function maybeCacheCssJs($fileName, $metaData = [], $code = '')
    {
        // type
        $type = Arr::get($metaData, 'type');
        if ($type == 'css' || $type == 'js') {
            // get file name without extension
            $cacheFileName = str_replace('.php', '.' . $type, $fileName);
            $fullFileName = Helper::getCachedDir() . '/' . $cacheFileName;
            if (Arr::get($metaData, 'load_as_file') == 'yes') {
                Helper::atomicPut($fullFileName, $code);
                return $cacheFileName;
            }

            if (file_exists($fullFileName)) {
                @unlink($fullFileName);
            }
        }

        return false;
    }
}
