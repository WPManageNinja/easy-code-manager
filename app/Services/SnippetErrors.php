<?php

namespace FluentSnippets\App\Services;

use FluentSnippets\App\Helpers\Arr;

/**
 * Turns a bare failure into an error the editor can actually explain.
 *
 * Every refusal to save used to surface as a single sentence — often PHP's own wording
 * ("Cannot redeclare function foo.") — with no indication of *why* FluentSnippets cared
 * or what the user was supposed to do about it. The support load that generated was the
 * whole reason this class exists.
 *
 * Each error carries three parts:
 *   title  — one line, safe to show in a toast
 *   reason — why the save was refused, in terms of what it would have done to the site
 *   fix    — the concrete next action, with an `example` snippet where one helps
 *
 * They travel inside the WP_Error data under `error_details`, which the editor renders as
 * a panel under the code box. `code` and `code_explanation` are kept alongside because
 * older builds of the admin app read those two keys directly.
 */
class SnippetErrors
{
    /**
     * Maximum number of characters of captured output / raw engine text we echo back.
     * A snippet that printed a whole page should not push the panel off screen.
     */
    const MAX_EXCERPT = 1200;

    /**
     * @param string $code WP_Error code.
     * @param array $details title/reason/fix, plus optional example, line, output, raw.
     * @param array $extraData Additional top-level keys to merge into the error data.
     * @return \WP_Error
     */
    public static function make($code, $details, $extraData = [])
    {
        $details = array_merge([
            'title'   => '',
            'reason'  => '',
            'fix'     => '',
            'example' => '',
            'line'    => null,
            'output'  => '',
            'raw'     => '',
        ], $details);

        foreach (['output', 'raw'] as $key) {
            $details[$key] = self::excerpt($details[$key]);
        }

        $message = $details['title'];

        if ($details['line']) {
            /* translators: %d: line number within the snippet */
            $message .= sprintf(__(' on line %d', 'easy-code-manager'), $details['line']);
        }

        $details['message'] = $message;

        // `code` and `code_explanation` are the legacy pair. Keep `code_explanation` a
        // readable sentence rather than the raw array it used to be — it was rendered
        // straight into a <pre> and showed the user `{"line":12}`.
        $data = array_merge([
            'code'             => $message,
            'code_explanation' => trim($details['reason'] . ' ' . $details['fix']),
            'error_details'    => $details,
        ], $extraData);

        return new \WP_Error($code, $message, $data);
    }

    /**
     * Re-describe the WP_Error that came out of PhpValidator.
     *
     * The validator reports what PHP told it. This maps each of its outcomes onto an
     * explanation of what FluentSnippets was doing at the time — it lints the snippet and
     * then runs it once, so a snippet that cannot survive that never reaches disk.
     *
     * @param \WP_Error $error
     * @return \WP_Error
     */
    public static function fromValidator($error)
    {
        $data = $error->get_error_data();
        $raw = $error->get_error_message();

        // The validator sees the code with our own `<?php` line prepended, so every line
        // number it reports is one further down than what the user is looking at.
        $line = Arr::get((array)$data, 'line');
        if (is_numeric($line) && $line > 1) {
            $line = $line - 1;
        } else {
            $line = null;
        }

        switch ($error->get_error_code()) {
            case 'parse_error':
            case 'syntax_error':
                return self::make('code', [
                    'title'  => __('This snippet has a PHP syntax error', 'easy-code-manager'),
                    /*
                     * PHP's own wording is not repeated here: the panel prints it above
                     * this paragraph, where it is the first thing read.
                     */
                    'reason' => __('PHP could not parse the snippet, so nothing was saved. It would have taken the site down on the very next page load, because snippets run on every request.', 'easy-code-manager'),
                    'fix'    => __('Look at the reported line and the one above it for a missing closing brace, semicolon, bracket or quote.', 'easy-code-manager'),
                    'line'   => $line,
                    'raw'    => $raw,
                ]);

            case 'duplicate_error':
                return self::describeDuplicate($data, $line);

            case 'runtime_error':
                return self::describeRuntime($raw, $line);

            case 'has_output':
            // `has_buffer` is what both of these were called before they were split
            // apart; it is still matched so an older cached build cannot slip through
            // to the generic branch.
            case 'has_buffer':
                return self::describeOutput($data);

            case 'has_return':
                return self::describeReturn();
        }

        return self::make('code', [
            'title'  => $raw,
            'reason' => __('FluentSnippets checks every PHP snippet before saving it, and this one did not pass.', 'easy-code-manager'),
            'fix'    => __('Correct the reported problem and save again.', 'easy-code-manager'),
            'line'   => $line,
        ]);
    }

    /**
     * A function/class/interface name that is already taken.
     *
     * By far the most common save failure, and the least self-explanatory: the conflict
     * is usually with something the user never looked at — another active snippet, the
     * theme's functions.php, or a plugin.
     */
    private static function describeDuplicate($data, $line)
    {
        $identifier = Arr::get((array)$data, 'identifier');
        $structure = Arr::get((array)$data, 'structure', 'function');

        $name = $identifier ? $identifier : __('this name', 'easy-code-manager');

        if ($structure === 'function') {
            /* translators: %s: PHP function name */
            $title = sprintf(__('The function %s is already declared elsewhere', 'easy-code-manager'), $name);
            $example = "if (!function_exists('" . ($identifier ? $identifier : 'your_function_name') . "')) {\n"
                . "    function " . ($identifier ? $identifier : 'your_function_name') . "() {\n"
                . "        // your code\n"
                . "    }\n"
                . "}";
        } else {
            /* translators: 1: class/interface, 2: name */
            $title = sprintf(__('The %1$s %2$s is already declared elsewhere', 'easy-code-manager'), $structure, $name);
            $example = "if (!class_exists('" . ($identifier ? $identifier : 'Your_Class_Name') . "')) {\n"
                . "    class " . ($identifier ? $identifier : 'Your_Class_Name') . " {\n"
                . "        // your code\n"
                . "    }\n"
                . "}";
        }

        return self::make('code', [
            'title'   => $title,
            'reason'  => __('Something on this site already declares that name: another active snippet, your theme\'s functions.php, or a plugin. PHP cannot declare the same function or class twice, so activating this snippet would end in a fatal error.', 'easy-code-manager'),
            'fix'     => __('Give it a name nobody else is likely to use (prefix it, for example my_site_), or guard the declaration so it only happens once:', 'easy-code-manager'),
            'example' => $example,
            'line'    => $line,
            /*
             * No `raw`. The validator's wording here is "Cannot redeclare function foo.",
             * which is the title again in different words - and the panel prints `raw` in
             * its loudest position, which is not somewhere to repeat yourself.
             */
        ]);
    }

    /**
     * The snippet threw while being test-run.
     *
     * "Call to undefined function" dominates this bucket and almost never means the
     * function is missing — it means the plugin that defines it had not loaded yet at the
     * moment we evaluated the snippet. Saying so is the difference between a two-minute
     * fix and a support ticket.
     */
    private static function describeRuntime($raw, $line)
    {
        /*
         * What PHP stopped with is not quoted here. The panel prints it as the first
         * thing under the headline, so a paragraph that repeated it just pushed the
         * useful half of this explanation further down the screen.
         */
        $reason = __('FluentSnippets runs every PHP snippet once before saving it, so that broken code can never reach your site. This one did not survive that run.', 'easy-code-manager');

        if (preg_match('/Call to undefined function\s+([^\s(]+)/i', $raw, $matches)) {
            return self::make('code', [
                'title'   => __('The snippet calls something that is not available yet', 'easy-code-manager'),
                'reason'  => $reason . ' ' . sprintf(
                    /* translators: %s: PHP function name */
                    __('Usually %s does exist, but the plugin or theme that defines it loads after the snippet is checked.', 'easy-code-manager'),
                    rtrim($matches[1], '()')
                ),
                'fix'     => __('Run your code on a hook instead of at the top level, so it fires once WordPress has finished loading:', 'easy-code-manager'),
                'example' => "add_action('init', function () {\n    // your code here\n});",
                'line'    => $line,
                'raw'     => $raw,
            ]);
        }

        if (preg_match('/Class ["\']?([^"\'\s]+)["\']? not found/i', $raw, $matches)) {
            return self::make('code', [
                'title'   => __('The snippet uses a class that is not loaded yet', 'easy-code-manager'),
                'reason'  => $reason . ' ' . sprintf(
                    /* translators: %s: PHP class name */
                    __('The class %s is normally provided by a plugin that loads after the snippet is checked.', 'easy-code-manager'),
                    $matches[1]
                ),
                'fix'     => __('Move the code onto a hook, and check the class is really there before using it:', 'easy-code-manager'),
                'example' => "add_action('init', function () {\n    if (!class_exists('" . $matches[1] . "')) {\n        return;\n    }\n\n    // your code here\n});",
                'line'    => $line,
                'raw'     => $raw,
            ]);
        }

        return self::make('code', [
            'title'   => __('The snippet failed when it was test-run', 'easy-code-manager'),
            'reason'  => $reason,
            'fix'     => __('Fix the reported error. If the code is only meant to run later (on a page load, an order, a form submission), put it on a hook rather than at the top level of the snippet:', 'easy-code-manager'),
            'example' => "add_action('init', function () {\n    // your code here\n});",
            'line'    => $line,
            'raw'     => $raw,
        ]);
    }

    /**
     * The snippet printed something while merely being loaded.
     */
    private static function describeOutput($data)
    {
        $output = Arr::get((array)$data, 'output', '');

        return self::make('code', [
            'title'   => __('This snippet prints output while it loads', 'easy-code-manager'),
            'reason'  => __('PHP snippets are loaded on every request, before the page has decided what to send. Anything echoed at that point lands at the very top of the site and breaks headers, redirects and REST responses, so FluentSnippets refuses to save it.', 'easy-code-manager'),
            'fix'     => __('Print inside a hook or a shortcode instead of at the top level, or change the snippet type to PHP Content, which is built for producing HTML. Also check for stray text or a blank line outside the PHP code.', 'easy-code-manager'),
            'example' => "add_action('wp_footer', function () {\n    echo '<p>Hello</p>';\n});",
            /*
             * The output itself is the technical detail worth showing, and it has its own
             * block below. `raw` here would be the validator's "PHP code should not have
             * print / echo statement" - our own sentence, untranslated, saying what the
             * title already says.
             */
            'output'  => is_string($output) ? $output : '',
        ]);
    }

    /**
     * The snippet handed a value back at the top level.
     *
     * Nearly always a `return` that was meant to sit inside a callback and ended up
     * outside it — which also means everything below it is dead code.
     */
    private static function describeReturn()
    {
        return self::make('code', [
            'title'  => __('This snippet returns a value at the top level', 'easy-code-manager'),
            'reason' => __('A return statement outside of any function ends the snippet right there, so anything written below it never runs, and the returned value has nowhere to go.', 'easy-code-manager'),
            'fix'    => __('Move the return inside the function or hook callback it belongs to. If you meant to stop early, do that inside a hook callback instead.', 'easy-code-manager'),
            // As above: the validator's own wording restates the title, so it is not
            // worth the panel's most prominent block.
        ]);
    }

    /**
     * The snippet passed validation but could not be written to disk.
     *
     * This used to be invisible: Snippet::updateSnippet() ignored what atomicPut()
     * returned, so an unwritable storage directory produced "Snippet has been updated
     * successfully" and code that reverted on the next page load.
     *
     * @param string $file Absolute path we tried to write.
     * @return \WP_Error
     */
    public static function writeFailed($file)
    {
        return self::make('write_failed', [
            'title'  => __('The snippet could not be written to disk', 'easy-code-manager'),
            'reason' => sprintf(
                /* translators: %s: absolute path of the storage directory */
                __('FluentSnippets keeps each snippet as a PHP file inside %s. The web server was not allowed to write there, so nothing was saved. The previous version of this snippet is still live.', 'easy-code-manager'),
                dirname($file)
            ),
            'fix'    => __('Check that the folder exists and is writable by the user PHP runs as (folders 755, files 644 is the usual setup). A read-only filesystem, a container without a writable wp-content, or a security plugin that locks file changes will also cause this.', 'easy-code-manager'),
            'raw'    => $file,
        ]);
    }

    /**
     * @param string $fileName
     * @return \WP_Error
     */
    public static function fileMissing($fileName)
    {
        return self::make('file_not_found', [
            'title'  => __('This snippet no longer exists', 'easy-code-manager'),
            'reason' => sprintf(
                /* translators: %s: snippet file name */
                __('The file %s is not in the snippet storage directory. It was most likely deleted in another browser tab, removed directly over FTP, or lost during a migration.', 'easy-code-manager'),
                $fileName
            ),
            'fix'    => __('Copy your code somewhere safe, go back to the snippet list and create it again.', 'easy-code-manager'),
        ]);
    }

    /**
     * Cut long excerpts down so the error panel stays readable.
     *
     * @param mixed $text
     * @return string
     */
    private static function excerpt($text)
    {
        if (!is_string($text) || $text === '') {
            return '';
        }

        if (strlen($text) <= self::MAX_EXCERPT) {
            return $text;
        }

        return substr($text, 0, self::MAX_EXCERPT) . '…';
    }
}
