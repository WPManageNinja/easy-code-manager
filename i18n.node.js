#!/usr/bin/env node
/**
 * @fluent-i18n-toolkit v3
 *
 * Builds the PHP translation map(s) behind the Vue app's $t() / $_n() / $_x()
 * helpers, from i18n.config.json in this directory.
 *
 * How the map is produced
 * -----------------------
 * 1. Every .vue/.js file under each target's `sourceDirs` is scanned for
 *    literal $t('text'), $_n('singular', 'plural', n) and $_x('text', 'context')
 *    calls (the helper names come from the config, so an alias such as
 *    transWith() is scanned too). Calls inside comments are ignored. Dynamic
 *    keys ($t(variable)) cannot be extracted; keep those keys alive through
 *    the reserved words file instead.
 * 2. A `translators:` comment on the line above a call (or in front of it on
 *    the same line) is carried into the PHP map as a `/* translators: ... *\/`
 *    comment, so Loco / WP-CLI copy it into the POT for translators.
 * 3. The reserved words file maps a short key to the English text it stands
 *    for ("Agent_Title_Help": "Long paragraph..."). The Vue source uses the
 *    key, the map emits `'key' => __('Long paragraph...')`, and translators
 *    see the real sentence. Every entry in that file is always written to the
 *    map, found in source or not, which also makes it the place to keep keys
 *    that are only reached dynamically ("open": "Open").
 * 4. Each output file is rewritten from scratch on every run: strings that
 *    left the source leave the map. There is no manual merge step.
 *
 * Usage: node i18n.node.js            (or: npm run translate / pnpm run translate)
 *        require('./i18n.node.js')    exposes generate() for the lint gate
 *                                     at tests/lint/translation-map.js.
 *
 * This file is a copy of the shared toolkit (fluent-plugins-skills/i18n-unify).
 * Fix bugs there and re-run the installer with --update so every plugin gets it.
 */
const fs = require('fs');
const path = require('path');

const PROJECT_ROOT = __dirname;
const CONFIG_FILE = path.join(PROJECT_ROOT, 'i18n.config.json');

// gettext's context separator (EOT). PHP's _x() entries are keyed as
// `context + DELIMITER + text`, and $_x() in the runtime helper builds the
// same key.
const CONTEXT_DELIMITER = '\u0004';

// A key with no whitespace and an underscore, or a `.desc`/`.title` suffix,
// is a reserved key by convention. One that is missing from the reserved
// words file would ship the raw key to the screen, so the lint reports it.
const DEFAULT_RESERVED_KEY_PATTERN = '^\\S*_\\S*$|\\.(desc|description|title)$';

const DEFAULT_HELPERS = { t: ['$t'], n: ['$_n'], x: ['$_x'] };

/**
 * Read and validate i18n.config.json. Paths in the config are relative to the
 * project root. Returns the normalized config with every target filled in.
 */
function loadConfig(file = CONFIG_FILE) {
    const raw = JSON.parse(fs.readFileSync(file, 'utf8'));
    const root = path.dirname(file);

    if (!raw.textDomain) {
        throw new Error('i18n.config.json: "textDomain" is required');
    }
    if (!raw.namespace) {
        throw new Error('i18n.config.json: "namespace" is required (the PHP namespace of the map class)');
    }
    if (!Array.isArray(raw.targets) || raw.targets.length === 0) {
        throw new Error('i18n.config.json: "targets" must list at least one target');
    }

    const helpers = Object.assign({}, DEFAULT_HELPERS, raw.helpers || {});
    ['t', 'n', 'x'].forEach((kind) => {
        if (!Array.isArray(helpers[kind])) {
            throw new Error(`i18n.config.json: helpers.${kind} must be an array of function names`);
        }
    });

    const targets = raw.targets.map((target, index) => {
        if (!target.name) {
            throw new Error(`i18n.config.json: targets[${index}] needs a "name"`);
        }
        if (!Array.isArray(target.sourceDirs) || target.sourceDirs.length === 0) {
            throw new Error(`i18n.config.json: target "${target.name}" needs "sourceDirs"`);
        }
        if (!target.output) {
            throw new Error(`i18n.config.json: target "${target.name}" needs an "output" PHP file`);
        }

        const reservedWords = Object.prototype.hasOwnProperty.call(target, 'reservedWords')
            ? target.reservedWords
            : (raw.reservedWords || null);

        return {
            name: target.name,
            sourceDirs: target.sourceDirs.map((dir) => path.resolve(root, dir)),
            exclude: [...(raw.exclude || []), ...(target.exclude || [])].map((dir) => path.resolve(root, dir)),
            output: path.resolve(root, target.output),
            className: target.className || path.basename(target.output, '.php'),
            method: target.method || 'getStrings',
            reservedWordsFile: reservedWords ? path.resolve(root, reservedWords) : null,
            // Reserved entries no target's source references are written to
            // the first target that reads that file, unless one is marked.
            reservedFallback: target.reservedFallback === true
        };
    });

    const mergeMethods = raw.mergeMethods || {};
    Object.keys(mergeMethods).forEach((method) => {
        const names = mergeMethods[method];
        if (!Array.isArray(names) || names.some((name) => !targets.find((t) => t.name === name))) {
            throw new Error(`i18n.config.json: mergeMethods.${method} must list target names`);
        }
        const outputs = new Set(names.map((name) => targets.find((t) => t.name === name).output));
        if (outputs.size !== 1) {
            throw new Error(`i18n.config.json: mergeMethods.${method} must merge targets that share one output file`);
        }
    });

    return {
        root,
        textDomain: raw.textDomain,
        namespace: raw.namespace,
        abspathGuard: raw.abspathGuard === true,
        helpers,
        reservedKeyPattern: new RegExp(raw.reservedKeyPattern || DEFAULT_RESERVED_KEY_PATTERN),
        extensions: raw.extensions || ['.vue', '.js'],
        mergeMethods,
        targets
    };
}

/**
 * Collect every source file under a directory, recursively, skipping the
 * excluded directories.
 */
function listSourceFiles(dir, extensions, exclude = [], allFiles = []) {
    if (!fs.existsSync(dir)) {
        return allFiles;
    }

    fs.readdirSync(dir).forEach((file) => {
        const filepath = path.join(dir, file);
        if (exclude.some((excluded) => filepath === excluded || filepath.startsWith(excluded + path.sep))) {
            return;
        }
        if (fs.statSync(filepath).isDirectory()) {
            if (file === 'node_modules') {
                return;
            }
            listSourceFiles(filepath, extensions, exclude, allFiles);
        } else if (extensions.includes(path.extname(file))) {
            allFiles.push(filepath);
        }
    });

    return allFiles;
}

/**
 * Read a JavaScript string literal starting right after its opening quote.
 * Handles backslash escapes; returns null when the literal never closes.
 * A template literal with `${}` interpolation is reported as such so the
 * caller can flag it: its runtime value can never match a static key.
 */
function readStringLiteral(content, startIndex, quote) {
    let value = '';

    for (let i = startIndex; i < content.length; i++) {
        const char = content[i];

        if (char === '\\') {
            const next = content[i + 1];
            if (typeof next === 'undefined') {
                return null;
            }
            value += unescapeCharacter(next);
            i++;
            continue;
        }

        if (quote === '`' && char === '$' && content[i + 1] === '{') {
            return { value, endIndex: i, interpolated: true };
        }

        if (char === quote) {
            return { value, endIndex: i + 1, interpolated: false };
        }

        value += char;
    }

    return null;
}

function unescapeCharacter(char) {
    const simpleEscapes = { n: '\n', r: '\r', t: '\t', b: '\b', f: '\f', v: '\v', 0: '\0' };

    return Object.prototype.hasOwnProperty.call(simpleEscapes, char) ? simpleEscapes[char] : char;
}

/**
 * Read `, 'next literal'` following a parsed argument. Returns null when the
 * next argument is not a string literal.
 */
function readNextStringArgument(content, fromIndex) {
    const separator = /^\s*,\s*(['"`])/.exec(content.slice(fromIndex));
    if (!separator) {
        return null;
    }

    return readStringLiteral(content, fromIndex + separator[0].length, separator[1]);
}

/**
 * Build a sorted list of line-start offsets so a match index can be turned
 * into a 1-based line number with a binary search.
 */
function buildLineIndex(content) {
    const starts = [0];
    for (let i = 0; i < content.length; i++) {
        if (content[i] === '\n') {
            starts.push(i + 1);
        }
    }

    return starts;
}

function lineNumberAt(lineStarts, index) {
    let low = 0;
    let high = lineStarts.length - 1;
    while (low < high) {
        const mid = (low + high + 1) >> 1;
        if (lineStarts[mid] <= index) {
            low = mid;
        } else {
            high = mid - 1;
        }
    }

    return low + 1;
}

const TRANSLATOR_COMMENT = /(?:\/\*|<!--|\/\/)\s*(translators?\s*:\s*[\s\S]*?)\s*(?:\*\/|-->)?\s*$/i;

/**
 * Find a `translators:` note for the call that starts at `callIndex`.
 *
 * Looks at the text in front of the call on the same line, then at the
 * nearest preceding non-blank line. Both forms Vue templates and scripts
 * can carry are accepted: `<!-- translators: ... -->`, `/* translators: ... *\/`
 * and `// translators: ...`.
 */
function findTranslatorComment(lines, lineNumber, callIndex, lineStarts) {
    const current = lines[lineNumber - 1] || '';
    const beforeCall = current.slice(0, callIndex - lineStarts[lineNumber - 1]);
    const inline = TRANSLATOR_COMMENT.exec(beforeCall);
    if (inline) {
        return normalizeComment(inline[1]);
    }

    for (let i = lineNumber - 2; i >= 0 && i >= lineNumber - 4; i--) {
        const text = lines[i].trim();
        if (text === '') {
            continue;
        }
        const match = TRANSLATOR_COMMENT.exec(text);
        return match ? normalizeComment(match[1]) : null;
    }

    return null;
}

/**
 * True when `index` sits inside a comment: a `//` line comment, a `/* *\/`
 * block (including docblocks), or an HTML `<!-- -->` comment. Example calls
 * written in documentation must not become map entries.
 */
function isInsideComment(content, index, lineStarts) {
    const lineStart = lineStarts[lineNumberAt(lineStarts, index) - 1];
    const linePrefix = content.slice(lineStart, index);

    if (/(^|[^:'"`])\/\//.test(linePrefix)) {
        return true;
    }

    const before = content.slice(0, index);
    const blockOpen = lastOpenerOutsideString(content, before, '/*', lineStarts);
    if (blockOpen !== -1 && before.lastIndexOf('*/') < blockOpen) {
        return true;
    }

    const htmlOpen = lastOpenerOutsideString(content, before, '<!--', lineStarts);

    return htmlOpen !== -1 && before.lastIndexOf('-->') < htmlOpen;
}

/**
 * Last position of a comment opener in `before` that is not inside a quoted
 * string on its own line, so a MIME glob literal such as '*' + '/' + '*'
 * does not swallow every translation call after it.
 */
function lastOpenerOutsideString(content, before, opener, lineStarts) {
    let position = before.lastIndexOf(opener);

    while (position !== -1) {
        const lineStart = lineStarts[lineNumberAt(lineStarts, position) - 1];
        if (!isInsideQuotes(content.slice(lineStart, position))) {
            return position;
        }
        position = position === 0 ? -1 : before.lastIndexOf(opener, position - 1);
    }

    return -1;
}

/** True when a line prefix ends inside an unterminated ', " or ` string. */
function isInsideQuotes(prefix) {
    let quote = null;

    for (let i = 0; i < prefix.length; i++) {
        const char = prefix[i];
        if (quote) {
            if (char === '\\') {
                i++;
            } else if (char === quote) {
                quote = null;
            }
        } else if (char === "'" || char === '"' || char === '`') {
            quote = char;
        }
    }

    return quote !== null;
}

function normalizeComment(text) {
    return text
        .replace(/\s*\*\/\s*$|\s*-->\s*$/, '')
        .replace(/\*\//g, '* /') // a stray close token would end the PHP comment early
        .replace(/\s+/g, ' ')
        .trim();
}

/** True when a string has a %s, %d or numbered %1$s placeholder. */
function hasPlaceholder(text) {
    return /%(?:\d+\$)?[sd]/.test(text);
}

function escapeRegExp(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * The regex that finds the start of any translation call. The helper name is
 * captured so the caller knows whether to expect one, two, or a contextual
 * pair of string arguments. A name must not be preceded by an identifier
 * character, so `fooTransWith(` is not `transWith(`; a `.` is fine
 * (`this.$t(`), and so is optional-call syntax (`translate?.(`).
 */
function buildCallPattern(helpers) {
    const names = [...helpers.t, ...helpers.n, ...helpers.x].map(escapeRegExp).sort((a, b) => b.length - a.length);

    return new RegExp(`(?<![\\w$])(${names.join('|')})\\s*(?:\\?\\.)?\\(\\s*(['"\`])`, 'g');
}

function helperKind(helpers, name) {
    if (helpers.n.includes(name)) {
        return 'n';
    }
    if (helpers.x.includes(name)) {
        return 'x';
    }

    return 't';
}

/**
 * Extract every translation call from one file.
 *
 * Returns `entries`, one per string argument (`$_n` yields two), each with
 * the message `text`, an optional `context` (from $_x), an optional
 * translator `comment`, and `file`/`line` for diagnostics; and `problems`,
 * call sites that can never resolve at runtime.
 */
function extractCalls(content, file, helpers = DEFAULT_HELPERS) {
    const entries = [];
    const problems = [];
    const lines = content.split('\n');
    const lineStarts = buildLineIndex(content);
    const callStart = buildCallPattern(helpers);
    let match;

    while ((match = callStart.exec(content)) !== null) {
        if (isInsideComment(content, match.index, lineStarts)) {
            continue;
        }

        const kind = helperKind(helpers, match[1]);
        const line = lineNumberAt(lineStarts, match.index);
        const first = readStringLiteral(content, callStart.lastIndex, match[2]);

        if (!first) {
            continue;
        }

        if (first.interpolated) {
            problems.push({ file, line, type: 'template-literal', text: first.value + '$' + '{...}' });
            callStart.lastIndex = first.endIndex;
            continue;
        }

        const note = findTranslatorComment(lines, line, match.index, lineStarts);
        callStart.lastIndex = first.endIndex;

        // A note describes placeholders, so it only attaches to a call that has
        // one; another call on the same line must not inherit it.
        if (kind === 't') {
            const comment = hasPlaceholder(first.value) ? note : null;
            entries.push({ text: first.value, context: null, comment, file, line });
            continue;
        }

        const second = readNextStringArgument(content, first.endIndex);

        if (kind === 'n') {
            // Both plural forms share the note (WordPress convention), as long as either has a placeholder.
            const comment = hasPlaceholder(first.value) || (second && hasPlaceholder(second.value)) ? note : null;
            entries.push({ text: first.value, context: null, comment, file, line });
            if (second && !second.interpolated) {
                entries.push({ text: second.value, context: null, comment, file, line });
                callStart.lastIndex = second.endIndex;
            }
            continue;
        }

        // $_x('text', 'context'): without a literal context the key cannot be
        // built, so the call falls back to the plain text at runtime.
        if (second && !second.interpolated) {
            entries.push({ text: first.value, context: second.value, comment: hasPlaceholder(first.value) ? note : null, file, line });
            callStart.lastIndex = second.endIndex;
        } else {
            problems.push({ file, line, type: 'dynamic-context', text: first.value });
        }
    }

    return { entries, problems };
}

function loadReservedWords(file) {
    if (!file) {
        return {};
    }

    const reserved = JSON.parse(fs.readFileSync(file, 'utf8'));

    Object.keys(reserved).forEach((key) => {
        if (typeof reserved[key] !== 'string' || reserved[key] === '') {
            throw new Error(`${path.basename(file)}: "${key}" must map to a non-empty string`);
        }
    });

    return reserved;
}

function mapKey(entry) {
    return entry.context === null ? entry.text : entry.context + CONTEXT_DELIMITER + entry.text;
}

/**
 * Turn scanned entries plus the reserved words into the ordered rows of one
 * map method. The first translator comment seen for a key wins. Only the
 * reserved keys in `alwaysInclude` are added when no call references them.
 */
function buildRows(entries, reserved, alwaysInclude) {
    const rows = {};

    entries.forEach((entry) => {
        const key = mapKey(entry);
        if (!rows[key]) {
            rows[key] = {
                key,
                text: Object.prototype.hasOwnProperty.call(reserved, key) ? reserved[key] : entry.text,
                context: entry.context,
                comment: null,
                reserved: Object.prototype.hasOwnProperty.call(reserved, key)
            };
        }
        if (entry.comment && !rows[key].comment) {
            rows[key].comment = entry.comment;
        }
    });

    alwaysInclude.forEach((key) => {
        if (!rows[key]) {
            rows[key] = { key, text: reserved[key], context: null, comment: null, reserved: true };
        }
    });

    return Object.keys(rows).sort().map((key) => rows[key]);
}

function escapeSingleQuoted(value) {
    return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

// Only a double-quoted PHP string can carry the \x04 context delimiter.
function escapeDoubleQuoted(value) {
    return String(value)
        .replace(/\\/g, '\\\\')
        .replace(/"/g, '\\"')
        .replace(/\$/g, '\\$')
        .replace(/\n/g, '\\n')
        .replace(/\t/g, '\\t');
}

function renderRow(row, textDomain) {
    const lines = [];

    if (row.comment) {
        lines.push(`            /* ${row.comment} */`);
    }

    if (row.context !== null) {
        const key = escapeDoubleQuoted(row.context) + '\\x04' + escapeDoubleQuoted(row.text);
        lines.push(`            "${key}" => _x('${escapeSingleQuoted(row.text)}', '${escapeSingleQuoted(row.context)}', '${textDomain}'),`);
    } else {
        lines.push(`            '${escapeSingleQuoted(row.key)}' => __('${escapeSingleQuoted(row.text)}', '${textDomain}'),`);
    }

    return lines.join('\n');
}

function renderMethod(target, rows, textDomain, root) {
    const dirs = target.sourceDirs.map((dir) => path.relative(root, dir).split(path.sep).join('/')).join(', ');

    return [
        `    // ${target.name}: ${dirs}`,
        `    public static function ${target.method}()`,
        '    {',
        '        return [',
        ...rows.map((row) => renderRow(row, textDomain)),
        '        ];',
        '    }'
    ].join('\n');
}

function renderMergeMethod(method, targets) {
    const calls = targets.map((target) => `self::${target.method}()`).join(', ');

    return [
        `    public static function ${method}()`,
        '    {',
        `        return array_merge(${calls});`,
        '    }'
    ].join('\n');
}

/**
 * Render one output PHP file: a class with one method per target that writes
 * to it, plus any merge methods configured for those targets.
 */
function renderPhp(config, outputTargets, rowsByTarget) {
    const className = outputTargets[0].className;
    const reservedNote = outputTargets.some((t) => t.reservedWordsFile)
        ? ', plus ' + [...new Set(outputTargets.filter((t) => t.reservedWordsFile).map((t) => path.basename(t.reservedWordsFile)))].join(' / ')
        : '';
    const methods = outputTargets.map((target) => renderMethod(target, rowsByTarget[target.name], config.textDomain, config.root));

    Object.keys(config.mergeMethods).forEach((method) => {
        const names = config.mergeMethods[method];
        if (outputTargets.some((target) => names.includes(target.name))) {
            methods.push(renderMergeMethod(method, names.map((name) => outputTargets.find((t) => t.name === name))));
        }
    });

    return [
        '<?php',
        '',
        `namespace ${config.namespace};`,
        '',
        ...(config.abspathGuard ? ["if (!defined('ABSPATH')) {", '    exit;', '}', ''] : []),
        '// This is an auto-generated file (node i18n.node.js). Please do not edit manually.',
        `// Source: literal ${[...config.helpers.t, ...config.helpers.n, ...config.helpers.x].map((h) => h + '()').join(' / ')} calls in the directories listed in i18n.config.json${reservedNote}.`,
        `class ${className}`,
        '{',
        methods.join('\n\n'),
        '}',
        ''
    ].join('\n');
}

/**
 * Run the whole pipeline without touching the disk.
 *
 * Returns `outputs`, one per PHP file (`{ file, php, targets }`), plus the
 * flattened `rows`, scanned `entries`, call-site `problems` and
 * `undefinedReservedKeys`: scanned keys that look reserved but have no
 * definition. `options.config` replaces the on-disk config (the lint's
 * self-test uses this).
 */
function generate(options = {}) {
    const config = options.config || loadConfig(options.configFile);
    const reservedByFile = {};
    const referencedReserved = new Set();
    const perTarget = {};
    const entries = [];
    const problems = [];

    config.targets.forEach((target) => {
        const files = [];
        target.sourceDirs.forEach((dir) => listSourceFiles(dir, config.extensions, target.exclude, files));

        const targetEntries = [];
        files.forEach((file) => {
            const result = extractCalls(fs.readFileSync(file, 'utf8'), path.relative(config.root, file).split(path.sep).join('/'), config.helpers);
            targetEntries.push(...result.entries);
            problems.push(...result.problems);
        });

        if (target.reservedWordsFile && !reservedByFile[target.reservedWordsFile]) {
            reservedByFile[target.reservedWordsFile] = loadReservedWords(target.reservedWordsFile);
        }
        const reserved = target.reservedWordsFile ? reservedByFile[target.reservedWordsFile] : {};
        targetEntries.forEach((entry) => {
            if (entry.context === null && Object.prototype.hasOwnProperty.call(reserved, entry.text)) {
                referencedReserved.add(target.reservedWordsFile + '\u0000' + entry.text);
            }
        });

        perTarget[target.name] = { target, entries: targetEntries, reserved };
        entries.push(...targetEntries);
    });

    // Reserved entries nothing references still belong in exactly one map:
    // the target marked reservedFallback, else the first one reading the file.
    const fallbackFor = {};
    config.targets.forEach((target) => {
        if (!target.reservedWordsFile) {
            return;
        }
        if (!fallbackFor[target.reservedWordsFile] || target.reservedFallback) {
            fallbackFor[target.reservedWordsFile] = target.name;
        }
    });

    const rowsByTarget = {};
    const rows = [];
    config.targets.forEach((target) => {
        const { entries: targetEntries, reserved } = perTarget[target.name];
        const alwaysInclude = target.reservedWordsFile && fallbackFor[target.reservedWordsFile] === target.name
            ? Object.keys(reserved).filter((key) => !referencedReserved.has(target.reservedWordsFile + '\u0000' + key))
            : [];
        rowsByTarget[target.name] = buildRows(targetEntries, reserved, alwaysInclude);
        rows.push(...rowsByTarget[target.name]);
    });

    const undefinedReservedKeys = [];
    const seen = new Set();
    config.targets.forEach((target) => {
        const { entries: targetEntries, reserved } = perTarget[target.name];
        targetEntries.forEach((entry) => {
            if (entry.context !== null || seen.has(entry.text)) {
                return;
            }
            seen.add(entry.text);
            if (config.reservedKeyPattern.test(entry.text) && !Object.prototype.hasOwnProperty.call(reserved, entry.text)) {
                undefinedReservedKeys.push({ key: entry.text, file: entry.file, line: entry.line });
            }
        });
    });

    const outputs = [];
    const byOutput = {};
    config.targets.forEach((target) => {
        (byOutput[target.output] = byOutput[target.output] || []).push(target);
    });
    Object.keys(byOutput).forEach((file) => {
        outputs.push({ file, targets: byOutput[file], php: renderPhp(config, byOutput[file], rowsByTarget) });
    });

    const reserved = {};
    Object.values(reservedByFile).forEach((words) => Object.assign(reserved, words));
    const unreferencedReserved = [];
    Object.keys(reservedByFile).forEach((file) => {
        Object.keys(reservedByFile[file]).forEach((key) => {
            if (!referencedReserved.has(file + '\u0000' + key)) {
                unreferencedReserved.push(key);
            }
        });
    });

    return { config, outputs, rows, rowsByTarget, entries, problems, undefinedReservedKeys, reserved, unreferencedReserved };
}

function main() {
    const result = generate();

    result.outputs.forEach((output) => {
        fs.mkdirSync(path.dirname(output.file), { recursive: true });
        fs.writeFileSync(output.file, output.php, 'utf8');
        const rows = output.targets.flatMap((target) => result.rowsByTarget[target.name]);
        const withComments = rows.filter((row) => row.comment).length;
        const contextual = rows.filter((row) => row.context !== null).length;
        const reserved = rows.filter((row) => row.reserved).length;
        console.log(`Saved ${rows.length} strings to ${path.relative(result.config.root, output.file)} (${reserved} reserved, ${contextual} contextual, ${withComments} with translator comments)`);
    });

    result.problems.forEach((problem) => {
        console.warn(`Warning: ${problem.file}:${problem.line} ${problem.type}: "${problem.text}" cannot be extracted`);
    });
    result.undefinedReservedKeys.forEach((item) => {
        console.warn(`Warning: ${item.file}:${item.line} reserved key "${item.key}" has no entry in the reserved words file`);
    });
}

module.exports = {
    CONFIG_FILE,
    CONTEXT_DELIMITER,
    DEFAULT_HELPERS,
    DEFAULT_RESERVED_KEY_PATTERN,
    buildCallPattern,
    extractCalls,
    generate,
    listSourceFiles,
    loadConfig,
    loadReservedWords,
    renderPhp
};

if (require.main === module) {
    main();
}
