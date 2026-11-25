<?php
// Run with php tests/crm-list-conditions.php. Each dependency state needs a fresh process.
require dirname(__DIR__) . '/app/Services/FluentSnippetCondition.php';

use FluentSnippets\App\Services\FluentSnippetCondition;

class CrmListFixtureIds
{
    private $ids;

    public function __construct(array $ids)
    {
        $this->ids = $ids;
    }

    public function pluck($field)
    {
        if ($field !== 'id') {
            throw new RuntimeException('Expected membership IDs');
        }
        return $this;
    }

    public function toArray()
    {
        return $this->ids;
    }
}

function crmRule($key, $operator, $ids)
{
    return ['source' => ['fluentcrm', $key], 'operator' => $operator, 'value' => $ids];
}

function crmMatches(...$rules)
{
    return (new FluentSnippetCondition())->evaluate(['status' => 'yes', 'items' => [$rules]]);
}

$mode = $argv[1] ?? '';
$include = crmRule('tags_list_ids', 'in', ['15', '23']);
$exclude = crmRule('tags_list_ids', 'not_in', ['99']);
$tag = crmRule('tags_ids', 'in', ['17']);
$legacy = crmRule('list_ids', 'in', ['23']);
if ($mode !== 'crm-absent') {
    define('FLUENTCRM', true);
}
if ($mode === 'crm-absent' || $mode === 'helper-absent') {
    exit(crmMatches($include) || crmMatches($exclude) ? 1 : 0);
}

if (defined('FLUENTCRM')) {
    function fluentcrm_get_current_contact()
    {
        return $GLOBALS['crmFixtureContact'];
    }
}

$contact = (object) ['tags' => new CrmListFixtureIds([17]), 'lists' => new CrmListFixtureIds([23])];
$GLOBALS['crmFixtureContact'] = $contact;
$cases = [];
$cases['assigned list'] = crmMatches($include) && crmMatches($tag, $include) &&
    !crmMatches(crmRule('tags_list_ids', 'not_in', [23]));
$cases['list exclusion'] = crmMatches($exclude) && !crmMatches(crmRule('tags_list_ids', 'in', ['99']));
$cases['legacy and tag'] = crmMatches($legacy) && crmMatches($tag);
$contact->lists = new CrmListFixtureIds([]);
$cases['list exclusion'] = $cases['list exclusion'] && crmMatches($exclude) && !crmMatches($include);
$invalid = !crmMatches(crmRule('tags_list_ids', 'not_in', [])) &&
    !crmMatches(crmRule('tags_list_ids', 'not_in', ['bad'])) &&
    !crmMatches(crmRule('tags_list_ids', 'not_in', ['23', null])) &&
    !crmMatches(crmRule('tags_list_ids', 'not_in', '99')) &&
    !crmMatches(crmRule('tags_list_ids', '!=', ['23']));
foreach (['crm-absent', 'helper-absent'] as $dependency) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . $dependency, $status);
    $invalid = $invalid && $status === 0;
}
$cases['invalid editor rule'] = $invalid;
$GLOBALS['crmFixtureContact'] = null;
$cases['unknown contact'] = !crmMatches($include) && !crmMatches($exclude);
$cases['legacy and tag'] = $cases['legacy and tag'] && !crmMatches($legacy) &&
    crmMatches(crmRule('list_ids', 'not_in', ['23'])) && !crmMatches($tag);

$messages = ['assigned list' => 'matching editor list was rejected', 'list exclusion' => 'identified nonmember was rejected'];
$failures = 0;
foreach ($cases as $name => $passed) {
    if ($mode !== '' && $mode !== $name) {
        continue;
    }
    echo 'CASE ' . ($passed ? 'PASS ' : 'FAIL ') . $name .
        ($passed ? '' : ' -- ' . ($messages[$name] ?? 'unexpected condition result')) . PHP_EOL;
    $failures += (int) !$passed;
}
exit($failures || ($mode !== '' && !isset($cases[$mode])) ? 1 : 0);
