# Vue / JS string translation

How a string typed into a `.vue` or `.js` file ends up in the `.pot` file and
comes back translated at runtime. This is the shared WPManageNinja shape
(FluentCRM is the reference implementation); the toolkit files are copies
from `fluent-plugins-skills/i18n-unify` and are refreshed with its installer.

## Runtime

`src/i18n.js` defines the helpers and the app's global mixin attaches
them to every component:

| Helper | Use for | Resolves |
|---|---|---|
| `$t('text', ...args)` | ordinary strings; `%s`, `%d`, `%1$s` placeholders filled from `args` | `fluentSnippetAdmin.i18n['text']` |
| `$_n('one', 'many', count)` | plural forms; `count` is also the first placeholder argument | whichever of the two keys applies |
| `$_x('text', 'context', ...args)` | one English word that needs different translations in different places | `fluentSnippetAdmin.i18n["context\x04text"]`, then the plain key |

The map is the PHP array from `app/Services/Trans.php`, resolved through `__()` / `_x()`
on the server and localized by the admin menu / portal handlers.

Lookups are exact string matches. A key that is not in the map returns the
English source text, so nothing breaks visibly; in a Vite dev build the
helper logs `Missing translation: <key>` once per key so you notice.

## The map is generated

`app/Services/Trans.php` is written by `node i18n.node.js` (`pnpm run translate`).
Do not edit it by hand. `i18n.config.json` at the repo root says which source
directories feed which PHP class method. The generator:

1. scans every `.vue` / `.js` file under each target's directories for
   literal `$t()`, `$_n()` and `$_x()` calls (template literals, variables and
   `this.$t(variable)` cannot be extracted);
2. carries a `translators:` comment found on the line above a call, or in
   front of it on the same line, into the PHP map so Loco / WP-CLI copy it to
   the POT;
3. adds every entry of `reserved18n.json`;
4. rewrites each output file, sorted. Strings that left the source leave
   the map.

`pnpm run translate` also runs the lint afterwards. The build script runs
both before regenerating the POT.

### Translator comments

Any string with a placeholder, and any short string whose meaning is not
obvious, gets a note for translators on the line above:

```vue
<!-- translators: %1$s is the ticket title, %2$s the date it was opened -->
<p>{{ $t('%1$s was opened on %2$s', title, date) }}</p>
```

```js
// translators: %s is the number of tickets selected in the table
message: this.$t('%s tickets selected.', count),
```

The generated entry becomes:

```php
/* translators: %s is the number of tickets selected in the table */
'%s tickets selected.' => __('%s tickets selected.', 'easy-code-manager'),
```

### Reserved words (long paragraphs)

A help paragraph does not belong inline in a template. As a rule of thumb,
anything around 120 characters or longer is a paragraph. Put it in
`reserved18n.json` under a short key and call the key:

```json
{
    "Agent_Title_Help": "The job title shown next to the agent's name, for example Developer or Support Staff."
}
```

```vue
<p class="help-text">{{ $t('Agent_Title_Help') }}</p>
```

The map then emits `'Agent_Title_Help' => __('The job title shown ...')`, so
translators see the sentence and the template stays short. Keys follow
`Snake_Case_Words` by convention; the lint uses that shape (no spaces,
contains `_`, or ends in `.desc` / `.title`) to spot a reserved key that has
no definition, which would otherwise print the raw key on screen.

Every entry in the file is always written to the map, whether or not the
scan found it. That is also how keys reached only dynamically stay
translatable: `$t(ticket.status)` resolves `"open": "Open"` from the same file.

### Contextual strings

```vue
<el-tag>{{ $_x('Open', 'Ticket status') }}</el-tag>
```

generates a double-quoted key with gettext's `\x04` separator:

```php
"Ticket status\x04Open" => _x('Open', 'Ticket status', 'easy-code-manager'),
```

## The lint gate

`tests/lint/translation-map.js` runs in the static tier of
`tests/bin/run-all.sh`. It regenerates the map in memory and fails when:

- a committed map file differs from a fresh generation (stale map, run
  `pnpm run translate`);
- a reserved-looking key is not defined in `reserved18n.json`;
- a template literal with `${}` is passed to a helper;
- `$_x()` is called with a non-literal context;
- a key contains an HTML entity such as `&quot;` (Vue decodes it at build
  time, so the runtime key would never match);
- a key with a `%s` / `%d` / `%1$s` placeholder has no `translators:` comment
  at any call site;
- a key or reserved text contains an em dash or en dash (write a period,
  comma, colon or parentheses instead);
- a key starts or ends with whitespace, or contains a double space. That is a
  concatenation fragment such as `$t('Wait until ') + date`; write
  `$t('Wait until %s', date)` so translators can reorder the sentence.

The same rule applies to fragments the scanner cannot see: `{{ $t('Page') }}
{{ page }} {{ $t('of') }} {{ total }}` must become
`$t('Page %1$s of %2$s', page, total)`, and a count followed by a label
(`{{ n }} {{ $t('selected') }}`) becomes `$t('%s selected', n)` or, when the
noun inflects, `$_n('%s ticket', '%s tickets', n)`.

Reserved words that no call references are reported as notes, not failures.
`node tests/lint/translation-map.js --self-test` runs every check against
`tests/lint/fixtures/translation-map/` and is the lint's proof-of-catch.

## Pro add-on

A Pro plugin whose Vue code runs inside this admin app resolves against the
same window global. It gets the same toolkit with its own `i18n.config.json`
and text domain, generates **every** string its own source uses, and appends
its map to the localized array through the admin vars filter with the free
plugin's entries winning on overlap. The two maps are then independent:
regenerating the free map never leaves a Pro screen in English.
