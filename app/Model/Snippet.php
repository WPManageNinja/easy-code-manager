<?php

namespace FluentSnippets\App\Model;

use FluentSnippets\App\Helpers\Arr;
use FluentSnippets\App\Helpers\Helper;

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

            $formattedFiles[] = [
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
            return [];
        }

        if (empty($config['published']) && empty($config['draft'])) {
            return [];
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
            return [];
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
                return (strpos($snippet['name'], $search) !== false) || (strpos($snippet['description'], $search) !== false) || (strpos($snippet['tags'], $search) !== false) || (strpos($snippet['group'], $search) !== false);
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
            $snippets = array_slice($snippets, ($page - 1) * $perPage, $perPage);
            return [
                'data'      => $snippets,
                'page'      => (int)$page,
                'per_page'  => (int)$perPage,
                'total'     => count($snippets),
                'last_page' => (int)ceil(count($snippets) / $perPage)
            ];
        }

        return $snippets;
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
            return new \WP_Error('file_not_found', 'File not found');
        }

        $fileContent = file_get_contents($snippetDir . '/' . $fileName);
        [$docBlockArray, $code] = $this->parseBlock($fileContent);

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

        if (!is_file($file)) {
            return new \WP_Error('file_not_found', 'File not found');
        }

        $docBlockString = $this->parseInputMeta($metaData, true);
        $fullCode = $docBlockString . $code;

        file_put_contents($file, $fullCode);

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
            return new \WP_Error('file_exists', 'Please try a different name');
        }

        $docBlockString = $this->parseInputMeta($metaData, true);

        $fullCode = $docBlockString . $code;

        file_put_contents($file, $fullCode);

        $this->maybeCacheCssJs($fileName, $metaData, $code);

        return $fileName;
    }

    public function deleteSnippet($fileName)
    {
        $snippetDir = Helper::getStorageDir();
        $file = $snippetDir . '/' . $fileName;

        if (!is_file($file) && $fileName === 'index.php') {
            return new \WP_Error('file_not_found', 'File not found');
        }

        unlink($file);

        return true;
    }

    public function parseBlock($fileContent, $codeOnly = false)
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
        $code = $fileContent[1];
        if ($codeOnly) {
            return $code;
        }
        $docBlock = $fileContent[0];


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
            'condition'    => '',
            'load_as_file' => ''
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

        return [$docBlockArray, $code];
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
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
            'is_valid'     => 1,
            'updated_by'   => get_current_user_id(),
            'priority'     => 10,
            'run_at'       => '',
            'load_as_file' => '',
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
                file_put_contents($fullFileName, $code);
                return $cacheFileName;
            }

            if (file_exists($fullFileName)) {
                @unlink($fullFileName);
            }
        }

        return false;
    }
}
