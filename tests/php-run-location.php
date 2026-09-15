<?php
/**
 * Behaviour check for PHP snippet run locations.
 *
 * Run with:
 *
 *     php tests/php-run-location.php
 *
 * Exit code 0 = pass, 1 = failure.
 */

$isAdmin = false;

function is_admin()
{
    global $isAdmin;
    return $isAdmin;
}

require dirname(__DIR__) . '/app/Services/CodeRunner.php';

$reflection = new ReflectionClass(FluentSnippets\App\Services\CodeRunner::class);
$runner = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod('shouldRunPhpSnippet');
$method->setAccessible(true);

$failures = 0;
$checks = 0;

function checkRunLocation($label, $actual, $expected)
{
    global $failures, $checks;
    $checks++;

    if ($actual === $expected) {
        echo "  ok    $label\n";
        return;
    }

    $failures++;
    echo "  FAIL  $label — expected " . var_export($expected, true)
        . ', got ' . var_export($actual, true) . "\n";
}

echo "Frontend request\n";
$isAdmin = false;
checkRunLocation('everywhere runs', $method->invoke($runner, 'all'), true);
checkRunLocation('frontend-only runs', $method->invoke($runner, 'frontend'), true);
checkRunLocation('admin-only does not run', $method->invoke($runner, 'backend'), false);

echo "\nAdmin request\n";
$isAdmin = true;
checkRunLocation('everywhere runs', $method->invoke($runner, 'all'), true);
checkRunLocation('frontend-only does not run', $method->invoke($runner, 'frontend'), false);
checkRunLocation('admin-only runs', $method->invoke($runner, 'backend'), true);

echo "\nLegacy/unknown location\n";
checkRunLocation('keeps the previous run-everywhere fallback', $method->invoke($runner, 'custom_hook'), true);

echo "\n";
if ($failures) {
    echo "$failures of $checks checks FAILED\n";
    exit(1);
}

echo "All $checks checks passed\n";
