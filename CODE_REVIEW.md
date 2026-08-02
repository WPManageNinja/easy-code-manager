# FluentSnippets — Code Review & Remediation Log

**Reviewed version:** 10.55
**Scope:** `easy-code-manager.php`, `app/**` (all PHP), `app/Services/mu.stub`, `src/**` (Vue/JS spot-check), `build.sh`, `readme.txt`
**First pass:** 2026-07-28 · **Last updated:** 2026-07-28

The architecture is sound: flat-file storage, an `index.php` manifest so nothing is parsed per request, zero DB queries on the runtime path, and a standalone MU runner so snippets survive plugin deactivation.

**Working baseline for this log** (agreed with maintainer): this is an admin-only plugin that has run on ~50K sites for years without complaints. A finding only earns a fix if it is *actually broken* in a configuration real users hit, or if the fix is a zero-risk swap with no behaviour change. Theoretical hardening and edge cases get withdrawn, not carried as debt. Findings withdrawn under this rule are recorded at the bottom **with the verification that retired them**, so they don't get re-raised later.

| Status | Count |
|---|---|
| ✅ Fixed | 6 |
| 🔧 Open | 16 |
| ⛔️ Withdrawn | 11 |

---

## ✅ Fixed

### C1. Snippet and index files were written non-atomically — a concurrent frontend request could fatal the site

**Fixed 2026-07-28.** `app/Helpers/Helper.php` (+ 5 call sites)

`file_put_contents()` opens with `O_TRUNC` and then writes. Between those two operations the file on disk is empty or half-written. Every frontend request does `include $storageDir . '/index.php'` and `require_once` for each published snippet — so a visitor landing in that window included a truncated PHP file and got a parse error → **HTTP 500**.

Invisible in support: unreproducible, one request, blamed on the host. Also more reachable than it looks, because `getSnippets()` rewrites `index.php` on every admin list load (see M6, still open).

**Fix:** added `Helper::atomicPut()` — write to a temp file in the same directory, then `rename()` over the target. `rename()` is atomic on POSIX within a filesystem, so a reader sees either the whole old file or the whole new one.

Routed through it:

| Call site | File |
|---|---|
| `index.php` manifest | `Helper::saveIndexedConfig()` |
| Snippet update | `Snippet::updateSnippet()` |
| Snippet create | `Snippet::createSnippet()` |
| Cached CSS/JS asset | `Snippet::maybeCacheCssJs()` |
| `fluent-snippets-mu.php` | `Helper::enableStandAlone()` |

The mu-plugin write turned out to matter most — WordPress loads that file on **every** request, so a torn write there is worse than `index.php`.

Deliberately left alone: the `<?php // silence is golden` write in `getCachedDir()` (static content, nothing includes it).

**Two non-obvious details the implementation handles:**

1. `tempnam()` **silently falls back to the system temp dir** when the target directory isn't writable, and a cross-device `rename()` is not atomic. The helper verifies the temp file actually landed next to the target before taking the atomic path.
2. `tempnam()` creates files as `0600`. Without carrying the target's existing mode forward, the swap would have silently tightened permissions on hosts that rely on group-writable files. The helper preserves `fileperms()` of the existing target, defaulting to `0644`.

**Verified** (isolated harness, not a live WP install): new file gets correct content and `0644`; overwrite of a `0664` file preserves `0664`; no temp files left behind on any path; read-only directory returns `false` exactly as before and does **not** write to the system temp dir; the target's **inode changes on every write**, proving the file is swapped rather than truncated in place. `php -l` clean.

**Behaviour is unchanged in every normal case** — same return value, same callers, same `invalidateOpcache()` afterwards. Every failure path falls through to the original `file_put_contents` call, so the worst case is status quo.

*Not verified:* concurrent load against a live install. To reproduce the original bug and confirm the fix end-to-end: save a snippet in a loop while hammering the frontend with `ab`/`hey`, watch for 500s.

### C2b. `PhpValidator` only caught `\ParseError`, so any other throwable was an uncaught fatal on save

**Fixed 2026-07-28.** `app/Services/PhpValidator.php:289-305`

Verified on PHP 8.4: `eval('undefined_fn_xyz();')` throws `\Error`, **not** `\ParseError`, and escaped the catch. Any undefined function/class, `TypeError`, `DivisionByZeroError`, or exception in a snippet's top-level code became an uncaught fatal on the save request.

Never generated a complaint because the UI degrades semi-gracefully — `SnippetEditView.vue` catches the string response and renders the raw 500 in a shadow-DOM box, so users saw *"Call to undefined function foo()"*, which is arguably useful.

**Fix:** added a `\Throwable` catch **after** the `\ParseError` one (order matters — `ParseError extends CompileError extends Error extends Throwable`, so a leading `\Throwable` catch would have swallowed parse errors and lost their handling).

Also added a refinement not in the original proposal: the line number is only reported when `$throwable->getFile()` contains `eval()'d code`. A throwable raised *inside* a WordPress function has a `getLine()` pointing at core, which `Helper::updateSnippet()` would have rendered as a confusing *"…on line 3420"*.

**Verified:** `ParseError` still routes to the first catch; `undefined_fn_xyz()` is now caught as `Error` with its message intact instead of escaping; line `3` correctly reported for an error on the snippet's third line; line correctly **omitted** (message preserved) when the throw originates inside a non-eval function; valid code returns unchanged.

**No regression:** code that fails here was already unsaveable — validation runs before the file is written. Those users were blocked by a 500 and are now blocked by a readable message.

**Note this does not address C2a** — that `eval()` *executes* the snippet at save time. A snippet whose top-level code depends on another snippet, or on a plugin not loaded during the admin-ajax request, still fails validation despite being perfectly valid in production. No evidence anyone hits this, so it stays unfixed under the working baseline; recorded here so the limitation is known.

### M7. Debug leftover: `error_log('OK')` on the fatal-error path

**Fixed 2026-07-28.** `app/Hooks/Handlers/CodeHandler.php:161`

Wrote a context-free `OK` into the site's error log whenever WP's fatal handler ran. Line deleted. The only remaining `error_log()` calls in the codebase are the intentional `WP_DEBUG`-guarded storage-URL warnings.

Still outstanding in the same category: `console.log()` at `src/App.vue:91` and `:110`. Those live in Vue source, so clearing them requires regenerating the committed `dist/app.js` — a build action, not a one-line edit. Worth folding into the next scheduled rebuild rather than doing standalone.

### C3 + C4 + H5. The standalone runner was stale, drifted, and declared global classes

**Fixed 2026-07-28.** `app/Services/mu.stub` (regenerated), `app/Helpers/Helper.php`, `app/Hooks/Handlers/AdminMenuHandler.php:17`, new `tests/mu-stub-drift.php`

Three findings with one root cause: `mu.stub` is a hand-maintained copy of `FluentSnippetCondition` and `CodeRunner`, and nothing kept it honest or shipped updates to it.

**What the diff actually showed.** The two `CodeRunner` classes had **zero logic drift** — the only differences were the constructor (`Helper::getStorageDir()` vs three inlined `resolve*()` methods, which is deliberate since the stub cannot autoload `Helper`) and pure whitespace. All real drift was in `FluentSnippetCondition`: the C4 `authenticated` bug.

**C4 — inverted "Logged-in" condition.** Fixed by rebuilding the stub from the source classes, so it inherited the correct `return $value == (is_user_logged_in() ? 'yes' : 'no')`.

**H5 — global `CodeRunner` / `FluentSnippetCondition` class names.** Solved by wrapping the stub in `namespace FluentSnippets\Mu;` rather than renaming anything. Verified beforehand that this is safe: PHP falls back to the global namespace for **functions and constants**, so `add_action()`, `WP_CONTENT_DIR`, `PHP_EOL` etc. keep working untouched; neither class references a global class (`new FluentSnippetCondition()` is same-namespace, and the only `Str::` hits are inside comments). Also confirmed a top-level `return` is legal inside a namespaced file, and that `define()` in a namespace still creates a **global** constant — so every external `defined('FLUENT_SNIPPETS_RUNNING_MU')` check still works.

**C3 — stale runner.** The version is now a `{{FLUENT_SNIPPETS_VERSION}}` placeholder substituted by `enableStandAlone()` at write time, so it can never fall out of step with the plugin header the way the hardcoded `10.32` did. `Helper::maybeUpdateStandAlone()` on `admin_init` rewrites the runner when its version doesn't match. An *undefined* version constant also counts as stale, which covers runners predating the constant. No rewrite loop: the constants come from the copy already loaded this request, so the refreshed file takes effect next request.

Also changed `handleDeactivate()` to check `FLUENT_SNIPPETS_RUNNING_MU` instead of `FLUENT_SNIPPETS_RUNNING_MU_VERSION` — an old runner without the version constant still needs refreshing on deactivation, which is exactly when it takes over.

**Drift protection — `tests/mu-stub-drift.php`** *(maintainer chose hand-maintained + test over a build-time generator)*. Compares `FluentSnippetCondition` in full and all `CodeRunner` members except the four storage-resolution ones, which are exempt and **listed in the output** so the gap stays visible rather than silently ignored. Plus three structural guards: the namespace must be present, the version placeholder must be present, and no `FluentSnippets\App\` reference may leak in (nothing in that namespace is autoloadable when the plugin is inactive, so it would be a fatal).

Not shipped — `build.sh` only copies `app/`, `dist/`, `language/` and the root files.

**Verified the test actually fails**, since one that can't is worthless. Five mutations, each caught with exit 1, baseline exit 0:

| Mutation | Caught as |
|---|---|
| Re-introduce the real C4 bug in the stub | `FluentSnippetCondition::evaluateUserCondition differs` |
| Remove the namespace | missing namespace declaration |
| Remove the version placeholder | missing `{{FLUENT_SNIPPETS_VERSION}}` |
| Leak a `FluentSnippets\App\Helpers\Helper` reference | unautoloadable reference |
| Add a method to the source only | `CodeRunner::brandNewThing is missing from mu.stub` |

**Verified the runner itself** against a WordPress-function harness: file written, placeholder substituted, `FLUENT_SNIPPETS_RUNNING_MU` defined globally, version constant `10.55`, both namespaced classes exist, no global `CodeRunner` or `FluentSnippetCondition` declared, bootstrap hooked to `plugins_loaded`, and the C4 case behaves correctly in both directions (`logged-in = no` → `true` for a logged-out visitor, `logged-in = yes` → `false`). A foreign plugin declaring a global `CodeRunner` coexists without a fatal.

*Caveat on one check:* my first harness asserted "no global `CodeRunner` declared" in a file that itself declared one later — PHP hoists unconditional class declarations, so that assertion was meaningless. Re-run in isolation, it passes properly.

---

## 🔧 Open — worth doing

### H1. Standalone mode has no fatal-error recovery at all

**Files:** `app/Services/mu.stub` vs `app/Hooks/Handlers/CodeHandler.php:19-45, 212-231`

Both safety nets — the `shutdown` handler that quarantines a failing snippet into `error_files`, and the secret kill-switch URL — live in `CodeHandler`, which only loads while the plugin is active. `mu.stub` has neither. So: standalone on → plugin deactivated → a snippet fatals → **white screen with no recovery except editing `wp-config.php`** to set `FLUENT_SNIPPETS_SAFE_MODE`.

~40 lines to port. The secret key is already in the `meta` block the stub loads.

**Now unblocked.** Before the C3 fix this wasn't worth doing — a recovery mechanism added to the stub would never have reached the sites already running an older copy. With `maybeUpdateStandAlone()` in place, a stub change now propagates on the first admin request after an update, and `tests/mu-stub-drift.php` keeps the addition from silently rotting. This is the natural next item.

### H2. Fatal-error auto-disable misses most real-world fatals

**File:** `app/Hooks/Handlers/CodeHandler.php:20-31, 177-180`

Two over-narrow filters:

1. `$error['type'] === 1` is `E_ERROR` only — misses `E_PARSE`, `E_COMPILE_ERROR`, `E_CORE_ERROR`, `E_USER_ERROR`, `E_RECOVERABLE_ERROR`. A truncated or hand-edited snippet file produces `E_PARSE` and is never quarantined, so the site stays broken on every subsequent request.
2. `dirname($error['file']) === storageDir` only catches fatals whose *deepest frame* is the snippet file. The common case — a snippet calling a WP or third-party function that then fatals — reports `wp-includes/…`, so the snippet is never blamed, never disabled, and the site stays down.

**Fix for (2):** track the currently-executing snippet instead of inferring it from the stack — set `$GLOBALS['fluent_snippets_running_file']` before the `require` and unset after; if it's still set at shutdown, that snippet was mid-execution. Attributes fatals anywhere in a snippet's call stack.

This is the feature users actually rely on ("Automatically Disable Script on fatal error" is on by default), so the gap between what it promises and what it catches matters.

### H3. Deleting a snippet leaves a permanent orphan in `error_files`

**Files:** `app/Hooks/Handlers/CodeHandler.php:143-159`, `app/Helpers/Helper.php:227, 263, 320`

```php
if (isset($config['error_files'][$fileName])) {
    unset($config['error_files'][$fileName]);   // mutates a LOCAL copy...
}
Helper::cacheSnippetIndex();                    // ...which is then thrown away
```

`cacheSnippetIndex()` re-reads `error_files` from disk, so all three `unset()`s are dead code and the entry survives forever.

**Correction to my first pass:** I claimed this combines with count-based filenames to silently kill a *later* snippet. I traced it properly and overstated it — `glob()` counts `index.php` too, so numbering works out in the normal create/delete flow. A collision needs deletions to land the count exactly on an existing same-named file, and the user-visible result is an actionable *"Please try a different name"*. Real but uncommon.

So the live issue is just the unbounded orphan map. **Fix:** prune `error_files` inside `cacheSnippetIndex()` to keys that still exist in `published` + `draft` — self-heals every stale entry already on disk.

### M1. `getCachedDir()` uses `is_file()` to test for a directory

**File:** `app/Helpers/Helper.php:96-108` — `is_file()` on a directory is always `false` (verified), so every call re-runs `wp_mkdir_p()` and rewrites the silence file. Should be `is_dir()`. One word.

### M6. The snippet index is fully rebuilt on every list request

**File:** `app/Http/Controllers/SnippetsController.php:14`

`Helper::cacheSnippetIndex('', true)` on every list load, search keystroke, pagination click and filter change → `glob()` + `file_get_contents()` + `parseBlock()` for all N snippets, plus a full rewrite of `index.php`. On a 150-snippet site that's 150 file reads and a write **per read request**.

Admin-only, so low urgency — but it's what made C1 reachable in the first place, and the index is already rebuilt on create/update/delete via the `fluent_snippets/snippet_*` actions.

Also: `cacheSnippetIndex($fileName = '', $isForced = false, ...)` — **both parameters are unused**. `$fileName` is overwritten in the loop at `Helper.php:291`; `$isForced` is never referenced.

### M8. `Router` calls `ob_get_clean()` unconditionally on every REST response

**File:** `app/Services/Router.php:31` — nothing in the callback calls `ob_start()`. Harmless when no buffer is active, but when **another plugin** has one open this silently destroys their buffered output. If the intent is to swallow stray snippet output, open the buffer in the same scope.

### M9. Unguarded array access on config that may be empty

PHP 8 warnings on a fresh install (before the first snippet) or on configs written by older versions:

| File:line | Expression |
|---|---|
| `CodeHandler.php:73` | `$config['meta']['force_disabled']` |
| `Helper.php:249-256` | `['force_disabled']`, `['auto_disable']`, `['auto_publish']`, `['remove_on_uninstall']` |
| `Helper.php:263` | `$previousConfig['error_files']` |
| `Helper.php:285, 300` | `$a['meta']['priority']` — `parseBlock()`'s defaults (`Snippet.php:416-427`) **omit `priority`**, so any hand-edited or legacy snippet file without an `@priority` line warns on every index rebuild |
| `SnippetsController.php:87, 114` | `$meta['code']` when `json_decode()` returns `null` |

`Arr::get()` already exists — use it consistently.

### M11. Import/export input handling is unguarded

**File:** `app/Hooks/Handlers/AdminMenuHandler.php:139-144, 59-63` — `$_FILES['file']` read with no `isset()`, no `UPLOAD_ERR_OK` check, no `is_uploaded_file()`, no size cap; and `array_map()` over `$_REQUEST['snippets']` fatals if it arrives as a scalar. Both are nonce + capability gated, so the impact is a broken response rather than a hole.

*(Credit: the export path's `array_intersect()` against the real `glob()` result is a genuinely effective path-traversal defence.)*

### M12. Missing `break` in the `php_content` case

**File:** `app/Services/CodeRunner.php:238-241` (and `mu.stub:665-668`) — falls through into an empty `default:`. Harmless today, a trap for the next case added.

### M14. `getIndexedConfig()`'s static cache misses when the config is empty

**File:** `app/Helpers/Helper.php:366-377` — `if ($config && $cached)` is falsy for `[]` (the fresh-install case), so every call re-runs `is_file()` + `include`. Needs a separate `$loaded` flag.

### M3. Dead config key: `has_line_wrap`

**File:** `app/Helpers/Helper.php:399` — `getConfigSettings()` declares a `has_line_wrap` default that nothing writes or reads; the setting is stored as `enable_line_wrap`. Also, the same setting has three different defaults: `getSettings()` says `'yes'` (`SettingsController.php:24`), `saveSettings()` says `'no'` (`:59`), `render()` says `'no'` (`AdminMenuHandler.php:356`).

### L1. `escCssJs()` is both leaky and lossy

**Files:** `Helper.php:508`, `CodeRunner.php:313`, `mu.stub:740`

`preg_replace('/<\/script>/', '', $code)` misses `</script >` and `</script\n>`, which HTML parsers *do* treat as terminators — and it mangles legitimate JS like `document.write('<script src=…>')`. Not a privilege boundary (only `unfiltered_html` users author snippets), but an output-integrity bug. Use `/<\/\s*script[^>]*>/i`.

### L5. Snippet search is case-sensitive

**File:** `app/Model/Snippet.php:145-149` — `strpos()` means searching "Header" won't find "header script". Use `stripos()`.

### L6 / L7 / L8. Consistency

- `installPlugin()` and `getRestOptions()` skip the `isBlockedRequest()` guard every other `SettingsController` method starts with (`SettingsController.php:313, 151`). No actual hole — the route-level `install_plugins` capability covers them — but the inconsistency invites one.
- Snippet create/update exist **twice**: REST routes (`routes.php:8-11`) and admin-ajax (`AdminMenuHandler::saveSnippet/createSnippet`). The UI uses admin-ajax; the REST routes appear unused. `SnippetsController::validateMeta()` is a byte-for-byte duplicate of `Helper::validateMeta()`.
- `SettingsController.php:122-124` compares a bool to a string (`$isEnable == 'yes'`). Works by accident under loose comparison (verified) — just use `if ($isEnable)`.

### L9 / L10. Housekeeping

- `readme.txt:9` — `Tested up to: 7.0`; confirm this matches a released WordPress version before the next release, or the compatibility badge degrades. Consider adding `Update URI:` to the plugin header.
- `.DS_Store` files in the tree; `build.sh` excludes them from the zip but they shouldn't be committed. Add to `.gitignore`.

---

## ⛔️ Withdrawn

Investigated and retired — **not** carried as debt. Recorded so they don't get re-raised.

| # | Finding | Why it was retired |
|---|---|---|
| — | `startsWith` / `endsWith` operators are commented out and silently return `false` | **Unreachable.** Those operators only render for `itemConfig.type == 'extended_text'` (`FilterItem.vue:119`), and no condition in this plugin uses that type — `grep` for `extended_text` hits only the Vue file. Inherited from FluentCRM. |
| H4 | `CodeRunner::parseBlock()` breaks on CRLF | **Self-consistent.** `PHP_EOL` is used for both the write (`parseInputMeta`) and the read (`explode`), so it matches on any single server, Windows included. Only breaks if files move between platforms. |
| H6 | `deleteSnippet()` guard uses `&&` where it needs `||`, could `unlink()` `index.php` | **Unreachable.** `SnippetsController::deleteSnippet()` calls `findByFileName()` first, which rejects `index.php` (`Snippet.php:271`). |
| M2 | `saveSettings()` reads keys `array_filter()` may have removed | **Not hit.** `ConfigSettings.vue` always sends all four keys. |
| M4 | Text domain loaded from `fluent-snippets/language`, wrong folder | **Inert.** No `.mo` files ship — `language/` contains only the `.pot`. WP.org's just-in-time loading from `wp-content/languages/plugins/` covers translated languages regardless. |
| M5 | No `uninstall.php`; mu-plugin survives plugin deletion | **By design.** The README sells it: *"keep running your snippets in stand-alone mode. No lock-in."* The `remove_on_uninstall` checkbox is knowingly disabled in the UI (`ConfigSettings.vue:40`). |
| M10 | `hash_equals()` fatals on an array `snippet_secret` | **Self-DoS only.** `?snippet_secret[]=x` 500s that one request; nothing persists and no other visitor is affected. |
| M13 | `Snippet::paginate()` is dead code reading `$_GET['page']` | Dead and harmless — no callers. Cleanup at best. |
| L2 | `v-html` on post/term titles | WP's `kses` filtering on save strips event handlers for users without `unfiltered_html`. Cosmetic. |
| L3 | `Storage.clear()` wipes the whole origin's localStorage | **Never called.** |
| L4 | Autoloader `require`s without an existence check | Needs a third party to `class_exists('FluentSnippets\…')` on a class that doesn't exist. Theoretical. |

---

## Improvement ideas (not bugs)

**I1. ~~Generate `mu.stub` at build time~~ — resolved a different way.** The maintainer chose to keep `mu.stub` hand-maintained and add `tests/mu-stub-drift.php` instead of introducing build machinery, on the grounds that it doesn't disturb the release process. That retired **C3, C4 and H5**, and unblocked **H1**. Worth revisiting only if the drift test starts failing routinely — that would be the signal that hand-maintenance isn't holding. Wire it into CI if one ever exists.

**I2. Harden the storage directory.** Ship `.htaccess` / `web.config` alongside the existing `index.php`. The `if (!defined("ABSPATH")) return;` guard in each snippet already handles the normal case; this covers a server misconfigured to serve `.php` as `text/plain`. `cached/` must stay publicly readable.

**I3. Snippet revisions.** A save overwrites in place, so a bad edit is unrecoverable. Keeping the last N versions in `storage/revisions/` composes naturally with the new `Helper::atomicPut()`.

**I4. A capability filter.** `install_plugins` is hardcoded in six places. `apply_filters('fluent_snippets/manage_capability', 'install_plugins')` would let agencies grant snippet access without granting plugin installation.

**I5. WP-CLI commands.** `wp fluent-snippets list|activate|deactivate|rebuild-index|safe-mode` gives users a recovery path that doesn't require editing `wp-config.php` — directly addresses **H1**.

**I6. Static analysis.** No tests, no lint config. PHPStan level 5 would have caught most of M9 and the H6 inverted condition; PHPCS with `WordPress-Extra` for the rest. Highest-value unit tests: `parseBlock()` round-tripping (all four marker variants, empty docblock), `PhpValidator` (valid / invalid / side-effecting code), and `FluentSnippetCondition::checkValues()` across all 25 operators.

**I7. Bound the `error_files` map.** Follows from H3 — prune to existing snippets on every rebuild, and consider a timestamp so the UI can show *"paused 3 days ago"*.

---

## Suggested order of work

| # | Item | Status | Why here |
|---|---|---|---|
| 1 | **C1** atomic writes | ✅ done | Only finding whose failure mode was a frontend 500 |
| 2 | **M7** `error_log('OK')` | ✅ done | Free win, one line |
| 3 | **C2b** `catch \Throwable` | ✅ done | Turns a raw 500 on save into a real message |
| 4 | **C3 + C4 + H5** + drift test | ✅ done | One change, three findings; unblocked H1 |
| 5 | **H1** port recovery into the stub | next | Now that stub changes actually reach existing sites |
| 6 | **H2** fatal attribution | | Restores the auto-disable guarantee the plugin ships on by default |
| 7 | **H3 + I7** prune `error_files` | | Self-heals stale entries already on disk |
| 8 | **M6** stop rebuilding on read | | Removes the write-on-read that made C1 reachable |
| 9 | **M1, M3, M8, M9, M11, M12, M14, L1, L5–L10** | | Cleanup pass |
| 10 | **I6** static analysis | | Locks the fixes in |
