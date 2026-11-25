<?php

date_default_timezone_set('UTC');

$fluentSnippetsTestCurrentTime = strtotime('2026-11-25 12:00:00');

function current_time($type)
{
    global $fluentSnippetsTestCurrentTime;

    if ($type === 'timestamp') {
        return $fluentSnippetsTestCurrentTime;
    }

    return date('Y-m-d H:i:s', $fluentSnippetsTestCurrentTime);
}

require_once dirname(__DIR__) . '/app/Services/FluentSnippetCondition.php';

function dateRangeSettings($operator, $range)
{
    return [
        'status' => 'yes',
        'items'  => [
            [
                [
                    'source'   => ['date', 'date_range'],
                    'operator' => $operator,
                    'value'    => $range,
                ],
            ],
        ],
    ];
}

function assertDateRange($label, $expected, $currentTime, $operator, $range)
{
    global $fluentSnippetsTestCurrentTime;

    $fluentSnippetsTestCurrentTime = strtotime($currentTime);

    $condition = new \FluentSnippets\App\Services\FluentSnippetCondition();
    $actual = $condition->evaluate(dateRangeSettings($operator, $range));

    if ($actual !== $expected) {
        fwrite(STDERR, sprintf(
            "%s failed: expected %s, got %s\n",
            $label,
            $expected ? 'true' : 'false',
            $actual ? 'true' : 'false'
        ));
        exit(1);
    }
}

$holidayRange = ['2026-11-24 00:00', '2026-12-30 00:00'];

assertDateRange('inside range', true, '2026-11-25 12:00:00', 'date_within', $holidayRange);
assertDateRange('inside range complement', false, '2026-11-25 12:00:00', 'date_not_within', $holidayRange);
assertDateRange('before range', false, '2026-11-23 23:59:59', 'date_within', $holidayRange);
assertDateRange('before range complement', true, '2026-11-23 23:59:59', 'date_not_within', $holidayRange);
assertDateRange('after range', false, '2026-12-30 00:00:01', 'date_within', $holidayRange);
assertDateRange('after range complement', true, '2026-12-30 00:00:01', 'date_not_within', $holidayRange);
assertDateRange('start boundary', true, '2026-11-24 00:00:00', 'date_within', $holidayRange);
assertDateRange('start boundary complement', false, '2026-11-24 00:00:00', 'date_not_within', $holidayRange);
assertDateRange('end boundary', true, '2026-12-30 00:00:00', 'date_within', $holidayRange);
assertDateRange('end boundary complement', false, '2026-12-30 00:00:00', 'date_not_within', $holidayRange);
assertDateRange('malformed range', false, '2026-11-25 12:00:00', 'date_within', ['invalid', '2026-12-30 00:00']);
assertDateRange('reversed range', false, '2026-11-25 12:00:00', 'date_within', array_reverse($holidayRange));

echo "OK - 12 date-range condition assertions passed.\n";
