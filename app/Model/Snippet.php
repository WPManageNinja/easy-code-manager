<?php

namespace FluentSnippets\App\Model;

use FluentSnippets\App\Helpers\Arr;
use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Services\SnippetErrors;

class Snippet
{

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
            if (basename($file) === 'index.php' || !is_file($file)) {
                continue;
            }
            $fileContent = file_get_contents($file);
            [$docBlockArray, $code] = $this->parseBlock($fileContent);

            if (!$docBlockArray) {
                continue;
            }

            if (!empty($args['status'])) {
                if ($args['status'] !== $docBlockArray['status']) {
                    continue;
                }
            }

            $row = [
                'meta'   => $docBlockArray,
                'code'   => $code,
                'file'   => $file,
                'status' => (!empty($docBlockArray['status'])) ? $docBlockArray['status'] : 'draft'
            ];
            if (!empty($args['include_source'])) {
                $row['source'] = $fileContent;
            }
            $formattedFiles[] = $row;
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

        if (!$this->isAuthoringFileName($fileName) || !is_file($file)) {
            return SnippetErrors::fileMissing($fileName);
        }

        $fileContent = @file_get_contents($file);
        if ($fileContent === false) {
            return new \WP_Error('snippet_unreadable', __('WordPress could not read the saved snippet. Check its file permissions and retry.', 'easy-code-manager'), ['status' => 503]);
        }
        [$docBlockArray, $code] = $this->parseBlock($fileContent);
        if ($docBlockArray === null) {
            return new \WP_Error('snippet_header_unreadable', __('The saved snippet header could not be read. Restore it from a backup before editing.', 'easy-code-manager'), ['status' => 422]);
        }

        return [
            'meta'   => $docBlockArray,
            'code'   => $code,
            'file'   => $file,
            'status' => (!empty($docBlockArray['status'])) ? $docBlockArray['status'] : 'draft'
        ];
    }

    public function updateSnippet($fileName, $code, $metaData)
    {
        $metaData['updated_at'] = date('Y-m-d H:i:s');

        $file = Helper::getStorageDir() . '/' . $fileName;

        if (!$this->isAuthoringFileName($fileName) || !is_file($file)) {
            return SnippetErrors::fileMissing($fileName);
        }

        $lease = \FluentSnippets\App\Services\SnippetRevision::lockSource($file, LOCK_EX, $busy);
        if (!$lease) {
            return $busy ? Helper::snippetBusyError() : SnippetErrors::writeFailed($file);
        }

        // Older clients can edit a shared snippet without sending this field. Only
        // an explicit null clears a restriction already present in its file.
        if (!array_key_exists('network_sites', $metaData)) {
            $previous = $this->findByFileName($fileName);
            if (is_wp_error($previous)) {
                return $previous;
            }
            if (isset($previous['meta']['network_sites'])) {
                $metaData['network_sites'] = $previous['meta']['network_sites'];
            }
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

    public function createSnippet($code, $metaData)
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

        $docBlockString = $this->parseInputMeta($metaData, true);

        $fullCode = $docBlockString . $code;

        if (Helper::atomicPut($file, $fullCode) === false) {
            return SnippetErrors::writeFailed($file);
        }

        Helper::invalidateOpcache($file);

        $this->maybeCacheCssJs($fileName, $metaData, $code);

        return $fileName;
    }

    public function deleteSnippet($fileName)
    {
        $snippetDir = Helper::getStorageDir();
        $file = $snippetDir . '/' . $fileName;

        // Removing a damaged snippet does not require parsing its header. Keep
        // library infrastructure outside the set of deletable files.
        if (!$this->isAuthoringFileName($fileName) || !is_file($file)) {
            return new \WP_Error('file_not_found', __('File not found.', 'easy-code-manager'), ['status' => 404]);
        }

        $lease = \FluentSnippets\App\Services\SnippetRevision::lockSource($file, LOCK_EX, $busy);
        if (!$lease) {
            return $busy ? Helper::snippetBusyError() : SnippetErrors::writeFailed($file);
        }
        if (!@unlink($file)) {
            return new \WP_Error('delete_failed', __('WordPress could not remove the snippet file. Check its file permissions and retry.', 'easy-code-manager'), ['status' => 503]);
        }

        Helper::invalidateOpcache($file);

        return true;
    }

    public function parseBlock($fileContent, $codeOnly = false)
    {
        return \FluentSnippets\App\Services\SnippetRevision::parse($fileContent, $codeOnly);
    }

    private function isAuthoringFileName($name)
    {
        return is_string($name) && $name !== '' && $name[0] !== '.' && $name !== 'index.php'
            && basename($name) === $name && substr($name, -4) === '.php';
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
            'network_sites' => null,
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

        if ($metaData['network_sites'] === null) {
            unset($metaData['network_sites']);
        } else {
            $metaData['network_sites'] = json_encode($metaData['network_sites']);
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
        // Network pages keep their original asset URL until they are reloaded.
        // publishRevision() writes the content-addressed asset for the next index.
        if (is_multisite() || isset($metaData['network_sites'])) {
            return false;
        }
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
