/**
 * Collect every translatable string the admin app asks for and write them into
 * app/Services/Trans.php, which is handed to the browser as window.fluentSnippetAdmin.i18n
 * and read back by the $t() helper in src/app.js.
 *
 * Run it before `wp loco extract`, which is what turns the __() calls in the generated
 * file into .pot entries:
 *
 *     node i18n.node.js            # regenerate app/Services/Trans.php
 *     node i18n.node.js --check    # exit 1 if the committed file is out of date
 *
 * The key in the PHP array has to be byte-for-byte what $t() is called with at runtime,
 * so a string is decoded from its JavaScript source form and then re-encoded for PHP.
 * Skipping either half is how "Don't" ends up as a key of "Don" — see decodeJsString()
 * and phpQuote().
 */

const fs = require('fs');
const path = require('path');

/**
 * reserved18n.json does two jobs:
 *
 *   1. Maps a short placeholder key to the English text it should show, so a paragraph
 *      of copy does not have to live inside a template ('__SNIPPET_FATAL_ERROR__').
 *   2. Carries strings this script cannot see. RichFilters.vue calls $t(add_label) with
 *      a prop default, and no amount of regex will find that; listing the literal here
 *      keeps it translatable. Entries of that kind look like a no-op (key === value)
 *      and are meant to.
 */
const reservedWords = require('./reserved18n.json');

const targetDir = 'src';
const namespace = 'easy-code-manager';
const finalFile = 'app/Services/Trans.php';

const checkOnly = process.argv.includes('--check');

/**
 * Match $t('...') / $t("...") / $t(`...`).
 *
 * The opening quote is captured and back-referenced as the closing one. The previous
 * pattern accepted any quote as the terminator, so $t("Don't do this") matched up to the
 * apostrophe and silently registered "Don" — a wrong key, no warning, and a string that
 * could never be translated.
 */
const CALL_PATTERN = /\$t\(\s*(['"`])((?:\\[\s\S]|(?!\1)[^\\])*)\1/g;

/** Every `$t(` in the source, so the ones the pattern above misses can be reported. */
const ANY_CALL_PATTERN = /\$t\(\s*([\s\S]{0,40})/g;

/**
 * Blank out comments so prose is never mistaken for a call.
 *
 * A comment that merely mentions `$t(` used to be extracted: the pattern locked onto the
 * mention, took the next backtick or quote as an opening delimiter, and registered a
 * chunk of English prose as a translation key. Commented-out code did the same, keeping
 * dead strings alive in the .pot forever.
 *
 * Deliberately not a JavaScript tokenizer. Block comments and HTML comments are stripped
 * by their delimiter pairs, but a `//` comment is only recognised when it begins the
 * line. A .vue file is part markup, where an apostrophe is just an apostrophe and a URL
 * is full of slashes, so anything that tried to track string state across a template
 * would eat real calls. Every comment in this codebase sits on its own line; a trailing
 * `// note` is left alone, and the worst it can do is contribute a string nobody uses.
 *
 * @param {string} source
 * @returns {string} Same length semantics are not preserved; only used for scanning.
 */
function stripComments(source) {
    // Newlines are kept so the line numbers in warnings still point at the real file.
    const blank = match => match.replace(/[^\n]/g, ' ');

    return source
        .replace(/\/\*[\s\S]*?\*\//g, blank)
        .replace(/<!--[\s\S]*?-->/g, blank)
        .replace(/^[ \t]*\/\/.*$/gm, blank);
}

/**
 * Turn a JavaScript string literal's source text into the value it evaluates to.
 *
 * @param {string} raw Source text between the quotes.
 * @returns {string}
 */
function decodeJsString(raw) {
    return raw.replace(/\\(u\{[0-9a-fA-F]+\}|u[0-9a-fA-F]{4}|x[0-9a-fA-F]{2}|[\s\S])/g, (match, escape) => {
        switch (escape[0]) {
            case 'n': return '\n';
            case 'r': return '\r';
            case 't': return '\t';
            case 'b': return '\b';
            case 'f': return '\f';
            case 'v': return '\v';
            case '\n': return '';
            case 'u': {
                const hex = escape[1] === '{' ? escape.slice(2, -1) : escape.slice(1);
                return String.fromCodePoint(parseInt(hex, 16));
            }
            case 'x':
                return String.fromCharCode(parseInt(escape.slice(1), 16));
            default:
                // \' \" \` \\ \/ and anything else stands for itself.
                return escape;
        }
    });
}

/**
 * Render a value as a PHP single-quoted string.
 *
 * @param {string} value
 * @returns {string}
 */
function phpQuote(value) {
    return "'" + value.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
}

/**
 * A $t( that carries a variable rather than a literal. The method definition in
 * src/app.js reads as one, so it is excluded by shape.
 *
 * @param {string} tail Source immediately after the opening parenthesis.
 * @returns {boolean}
 */
function isMethodDefinition(tail) {
    return /^\w+\)\s*\{/.test(tail);
}

function readDirRecursively(dir, allFiles = []) {
    for (const file of fs.readdirSync(dir)) {
        const filepath = path.join(dir, file);

        if (fs.statSync(filepath).isDirectory()) {
            readDirRecursively(filepath, allFiles);
        } else if (path.extname(file) === '.vue' || path.extname(file) === '.js') {
            allFiles.push(filepath);
        }
    }

    return allFiles;
}

/**
 * @param {string[]} files
 * @returns {{strings: Set<string>, warnings: string[]}}
 */
function extractStrings(files) {
    const strings = new Set();
    const warnings = [];

    for (const file of files) {
        const content = stripComments(fs.readFileSync(file, 'utf8'));
        const extracted = new Set();

        let match;

        CALL_PATTERN.lastIndex = 0;
        while ((match = CALL_PATTERN.exec(content)) !== null) {
            const [, quote, raw] = match;

            if (quote === '`' && raw.includes('${')) {
                warnings.push(
                    `${file}:${lineOf(content, match.index)} — $t(\`...\`) with interpolation cannot be translated. ` +
                    `Move the fixed text into its own $t() call.`
                );
                continue;
            }

            extracted.add(match.index);
            strings.add(decodeJsString(raw));
        }

        // Anything that looks like a call but produced no string would previously vanish
        // without a trace. Now it is named, with the line to look at.
        ANY_CALL_PATTERN.lastIndex = 0;
        while ((match = ANY_CALL_PATTERN.exec(content)) !== null) {
            if (extracted.has(match.index) || isMethodDefinition(match[1])) {
                continue;
            }

            const preview = match[1].split('\n')[0].split(')')[0].trim();

            warnings.push(
                `${file}:${lineOf(content, match.index)} — $t(${preview}) is not a plain string, ` +
                `so it was not collected. Add the literal to reserved18n.json if it needs translating.`
            );
        }
    }

    return { strings, warnings };
}

/**
 * @param {string} content
 * @param {number} index
 * @returns {number} 1-based line number.
 */
function lineOf(content, index) {
    return content.slice(0, index).split('\n').length;
}

/**
 * @param {Set<string>} strings
 * @returns {string} Contents of the generated PHP file.
 */
function renderPhp(strings) {
    const all = new Set(strings);

    for (const key of Object.keys(reservedWords)) {
        all.add(key);
    }

    const lines = [...all].sort().map(key => {
        const value = Object.prototype.hasOwnProperty.call(reservedWords, key) ? reservedWords[key] : key;

        return `            ${phpQuote(key)} => __(${phpQuote(value)}, '${namespace}')`;
    });

    return `<?php

namespace FluentSnippets\\App\\Services;

// This is an auto-generated file. Do not edit it by hand — run \`node i18n.node.js\`.
class Trans
{
    public static function getStrings()
    {
        return [
${lines.join(',\n')}
        ];
    }
}
`;
}

function main() {
    const files = readDirRecursively(targetDir);
    const { strings, warnings } = extractStrings(files);
    const php = renderPhp(strings);

    for (const warning of warnings) {
        console.warn('  warning: ' + warning);
    }

    if (checkOnly) {
        const current = fs.existsSync(finalFile) ? fs.readFileSync(finalFile, 'utf8') : '';

        if (current !== php) {
            console.error(`${finalFile} is out of date. Run: node i18n.node.js`);
            process.exitCode = 1;
            return;
        }

        console.log(`${finalFile} is up to date (${strings.size} strings from ${files.length} files).`);
        return;
    }

    // Synchronous on purpose: build.sh runs this immediately before `wp loco extract`,
    // and a write that failed after the process had moved on would be extracted from a
    // stale file without anyone noticing.
    fs.writeFileSync(finalFile, php);

    console.log(`Saved ${strings.size} strings from ${files.length} files to ${finalFile}.`);
}

main();
