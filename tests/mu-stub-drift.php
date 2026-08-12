<?php
/**
 * Drift check for app/Services/mu.stub.
 *
 * mu.stub carries hand-maintained copies of FluentSnippetCondition and CodeRunner,
 * because the standalone runner has to work when the plugin is not loaded and cannot
 * autoload anything. Those copies have silently drifted from the originals before —
 * the "Logged-in" condition was inverted in the stub for several releases.
 *
 * Run this after touching app/Services/CodeRunner.php or
 * app/Services/FluentSnippetCondition.php:
 *
 *     php tests/mu-stub-drift.php
 *
 * Exit code 0 = in sync, 1 = drift found.
 *
 * Not shipped: build.sh only copies app/, dist/, language/ and the root files.
 */

$base = dirname(__DIR__) . '/';

$stub = $base . 'app/Services/mu.stub';

/*
 * Methods that differ on purpose. The stub cannot reach Helper, so it resolves the
 * storage location itself. The same logic lives in Helper::getStorageDir(),
 * Helper::getStorageUrl() and Helper::deriveStorageUrl(), each carrying a "keep this
 * logic in sync" note. This check cannot compare them mechanically — they are listed
 * in the report so the gap stays visible rather than silently ignored.
 */
$exempt = ['__construct', 'resolveStorageDir', 'resolveStorageUrl', 'deriveStorageUrl'];

/**
 * Return the body of a class as a string, excluding the declaration line and the
 * closing brace. Relies on the closing brace of a top-level class being the only `}`
 * at column zero, which holds for every file involved.
 */
function classBody($file, $class)
{
    if (!is_file($file)) {
        fwrite(STDERR, "Missing file: $file\n");
        exit(1);
    }

    $lines = explode("\n", file_get_contents($file));
    $body = [];
    $inside = false;

    foreach ($lines as $line) {
        if (!$inside) {
            if (preg_match('/^class ' . preg_quote($class, '/') . '\b/', $line)) {
                $inside = true;
            }
            continue;
        }

        if ($line === '}') {
            return implode("\n", $body);
        }

        $body[] = rtrim($line);
    }

    fwrite(STDERR, "Could not extract class $class from $file\n");
    exit(1);
}

/**
 * Split a class body into members keyed by method name. Everything before the first
 * method (property declarations) is collected under one pseudo-key so it is compared
 * too rather than dropped.
 */
function members($body)
{
    $members = [];
    $current = '(properties)';
    $buffer = [];

    foreach (explode("\n", $body) as $line) {
        if (preg_match('/^    (?:public|private|protected)(?: static)? function (\w+)/', $line, $match)) {
            $members[$current] = trim(implode("\n", $buffer));
            $current = $match[1];
            $buffer = [];
        }
        $buffer[] = $line;
    }

    $members[$current] = trim(implode("\n", $buffer));

    return array_filter($members, function ($value) {
        return $value !== '';
    });
}

$problems = [];
$skipped = [];
$compared = 0;

foreach ([
    'FluentSnippetCondition' => 'app/Services/FluentSnippetCondition.php',
    'CodeRunner'             => 'app/Services/CodeRunner.php',
] as $class => $sourceFile) {
    $source = members(classBody($base . $sourceFile, $class));
    $copy = members(classBody($stub, $class));

    foreach ($source as $name => $sourceCode) {
        if (in_array($name, $exempt, true)) {
            $skipped[] = "$class::$name";
            continue;
        }

        if (!isset($copy[$name])) {
            $problems[] = "$class::$name is missing from mu.stub";
            continue;
        }

        $compared++;

        if ($copy[$name] !== $sourceCode) {
            $problems[] = "$class::$name differs between $sourceFile and mu.stub";
        }
    }

    foreach ($copy as $name => $copyCode) {
        if (isset($source[$name])) {
            continue;
        }

        if (in_array($name, $exempt, true)) {
            // Stub-only by design (storage resolution). Reported, not compared.
            $skipped[] = "$class::$name";
            continue;
        }

        $problems[] = "$class::$name exists in mu.stub but not in $sourceFile";
    }
}

/*
 * Structural guards. These are cheap and catch the two ways this file breaks a site
 * outright rather than subtly.
 */
$stubSource = file_get_contents($stub);

if (strpos($stubSource, 'namespace FluentSnippets\Mu;') === false) {
    $problems[] = 'mu.stub is missing its namespace declaration. In the global namespace '
        . 'a CodeRunner class declared by another plugin would fatal every request.';
}

if (strpos($stubSource, '{{FLUENT_SNIPPETS_VERSION}}') === false) {
    $problems[] = 'mu.stub is missing the {{FLUENT_SNIPPETS_VERSION}} placeholder, so the '
        . 'plugin can no longer detect and refresh a stale copy.';
}

if (preg_match('/\\\\?FluentSnippets\\\\App\\\\/', $stubSource)) {
    $problems[] = 'mu.stub references a FluentSnippets\App\ class. Nothing in that namespace '
        . 'is autoloadable when the plugin is inactive, so this would be a fatal error.';
}

echo "mu.stub drift check\n";
echo str_repeat('-', 60) . "\n";
printf("compared : %d members\n", $compared);
printf("exempt   : %s\n", implode(', ', $skipped) ?: 'none');
echo "           (storage resolution — mirrored in Helper, verify by hand)\n\n";

if (!$problems) {
    echo "OK — mu.stub is in sync.\n";
    exit(0);
}

echo "DRIFT FOUND:\n";
foreach ($problems as $problem) {
    echo "  - $problem\n";
}
echo "\nUpdate app/Services/mu.stub to match, then re-run.\n";
exit(1);
