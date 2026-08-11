# FluentSnippets — Code Review & Remediation Log

**Reviewed version:** 10.55
**Scope:** `easy-code-manager.php`, `app/**` (all PHP), `app/Services/mu.stub`, `src/**` (Vue/JS spot-check), `build.sh`, `readme.txt`
**First pass:** 2026-07-28 · **Last updated:** 2026-08-11

The architecture is sound: flat-file storage, an `index.php` manifest so nothing is parsed per request, zero DB queries on the runtime path, and a standalone MU runner so snippets survive plugin deactivation.

**Working baseline for this log** (agreed with maintainer): this is an admin-only plugin that has run on ~50K sites for years without complaints. A finding only earns a fix if it is *actually broken* in a configuration real users hit, or if the fix is a zero-risk swap with no behaviour change. Theoretical hardening and edge cases get withdrawn, not carried as debt. Findings withdrawn under this rule are recorded at the bottom **with the verification that retired them**, so they don't get re-raised later.

| Status | Count |
|---|---|
| ✅ Fixed | 26 |
| 🔧 Open | 1 |
| ⛔️ Withdrawn | 11 |

*Counted per finding, not per heading — several headings cover more than one (`C3 + C4 + H5`, the cleanup pass). Recounted on 2026-08-11 after the counting scheme was found to be inconsistent: Open had been counted by heading while Fixed was counted by finding.*

**One remains open:** L9 (`Tested up to:`), which needs a human who knows what WordPress has actually shipped. Deferred by the maintainer — no release is planned yet.

---

## ✅ Fixed

### C1. Snippet and index files were written non-atomically — a concurrent frontend request could fatal the site

**Fixed 2026-07-28.** `app/Helpers/Helper.php` (+ 5 call sites)

`file_put_contents()` opens with `O_TRUNC` and then writes. Between those two operations the file on disk is empty or half-written. Every frontend request does `include $storageDir . '/index.php'` and `require_once` for each published snippet — so a visitor landing in that window included a truncated PHP file and got a parse error → **HTTP 500**.

Invisible in support: unreproducible, one request, blamed on the host. Also more reachable than it looks, because `getSnippets()` rewrote `index.php` on every admin list load (M6 — since fixed, so that exposure is gone too).

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

### M6. The snippet index was fully rebuilt on every list request

**Fixed 2026-08-11.** `app/Helpers/Helper.php`, `app/Http/Controllers/SnippetsController.php`, `app/Http/routes.php`, `src/components/Dashboard.vue`, new `tests/index-sync.php`

`SnippetsController::getSnippets()` opened with `Helper::cacheSnippetIndex('', true)` — a full `glob()` + `file_get_contents()` + `parseBlock()` over every snippet, plus a `var_export` and a rewrite of `index.php`, on every list load, **search keystroke, pagination click and filter change**. On a 150-snippet site that was 150 file reads and a write per read request.

That rebuild could not simply be deleted: it is the only thing healing an index that has drifted from disk — an FTP edit, a git deploy, a migration, a hand-deleted `index.php`. Plugin-driven create/update/delete already rebuild through their own `fluent_snippets/snippet_*` hooks, so the read-path rebuild is *purely* a self-heal.

**Fix, in two halves:**

1. **`Helper::syncSnippetIndex()`** — gates the rebuild behind `Helper::getSnippetFilesHash()`, a stat-only fingerprint (`md5` over `basename:mtime:size` for each file). Match → return immediately: no reads, no parse, no write. The idle case drops from *N reads + a write* to *one `glob()` + N `stat()`s*. Returns whether a rebuild actually happened.
2. **A dedicated endpoint** — `POST snippets/sync-index` → `SnippetsController::syncIndex()`, which the admin app calls **once when it boots** rather than on every list request. `getSnippets()` now does no index work at all. The endpoint returns `{changed: bool}` so `Dashboard.vue` only re-fetches the list when the rebuild would actually change what is on screen.

**Two things the fingerprint deliberately handles:**

1. **`index.php` is excluded from the hash.** It lives in the same globbed directory and is the file the hash is *stored in* — counting it would invalidate the value each rebuild had just written, and the gate would never match. That is an infinite-rebuild bug, and `tests/index-sync.php` pins it.
2. **The gate is opt-in, not applied to `cacheSnippetIndex()` itself.** Every write path still calls `cacheSnippetIndex()` and still rebuilds unconditionally. This matters: mtime has 1-second granularity, so two edits inside the same second that also keep the byte count identical (`priority: 10` → `priority: 11`) would be invisible to the fingerprint. That blind spot is unreachable because it is never on the write path.

Also forced regardless of the file signature, since neither shows up in it: `cached_version` not matching `FLUENT_SNIPPETS_PLUGIN_VERSION` (the index shape may have changed across an update) and `cashed_domain` not matching `site_url()` (the site was migrated or cloned).

**On the ordering race.** The app fires `sync-index` and the first `getSnippets()` in parallel, so the list renders immediately from the existing index. If the sync reports `changed`, the re-fetch waits for that first request to settle first — otherwise its pre-rebuild response could land last and put the stale list back on screen. A failed sync is swallowed rather than surfaced: the list rendered from the existing index is still usable.

**Verified** — `tests/index-sync.php`, 29 checks against a temp directory with stubbed WP functions. Rebuilds on: fresh install, snippet added, snippet deleted, an in-place edit that keeps the size byte-identical, a version bump, a domain change, a cleared `files_hash`, a deleted `index.php`, and an emptied directory. Does **not** rebuild when nothing changed, or when `index.php` alone is touched. "Did not write" is asserted on the **inode** of `index.php` rather than on mtime — `Helper::atomicPut()` swaps via `rename()`, so a write always moves the inode and cannot be missed. Every scenario also asserts it settles back to no-rebuild on the following call, which is what catches a rebuild loop.

*Not verified:* the browser half. The Vue change is small (one method, one call site) and `dist/app.js` was rebuilt, but the boot sequence has not been exercised in a live wp-admin.

**Worth knowing, not changed:** `getIndexedConfig()` holds a `static` cache that `saveIndexedConfig()` does not refresh, so the old forced rebuild in `getSnippets()` never affected the response it ran in — the current request kept serving the pre-rebuild config and only the *next* request saw the new index. Moving the rebuild to its own request is therefore no loss in freshness. Left alone deliberately: changing static-cache semantics would alter behaviour on the write paths too, which is out of scope here.

### S1 + S2. Import could introduce code without `unfiltered_html`, and its forced "draft" could be overridden

**Fixed 2026-08-11.** `app/Hooks/Handlers/AdminMenuHandler.php`, `app/Helpers/Helper.php`, `app/Model/Snippet.php`, new `tests/docblock-injection.php`

Found in a permissions-focused pass over every entry point (REST routes, admin-ajax actions, the shortcode, and the unauthenticated kill switch). Two defects that compounded.

**S1 — `importSnippets()` required only `install_plugins`.** Every other authoring path requires `unfiltered_html` as well: `saveSnippet()`, `createSnippet()`, and all four REST write routes via `SnippetsController::denyUnlessCanAuthorSnippets()`. Import didn't — and it calls `Helper::validateCode()`, which for a PHP snippet reaches `PhpValidator::checkRunTimeError()` and **`eval()`s the code** to report runtime errors. So the imported code executed at import time, before any publishing decision.

**S2 — the forced `draft` could be overridden from meta.** Import sets `$meta['status'] = 'draft'` on every snippet. But every meta value is written into the file as `* @key: value`, and `parseBlock()` reads the docblock by splitting on `*` and keeping the **last** value for a key. `Helper::sanitizeMetaValue()` stripped `*/` but nothing else, so any value could open a new chunk reading `@status: published` and win.

A newline was never needed — a bare `*` is enough, since the split is on the character, not the line. That also means fields written *before* `status` were just as dangerous as ones after, so switching the parser to first-wins would have swapped the vulnerable set rather than closed it.

Verified against the real `createSnippet()` → `parseBlock()` → `cacheSnippetIndex()` path before fixing: `run_at` of `"wp\n* @status: published"` produced `parsed status: 'published'`, `indexed as published: YES`, `hooked to run: {"wp":["1-imported-snippet.php"]}`.

**Net effect:** `install_plugins` + a valid nonce, with no `unfiltered_html`, could land a published PHP snippet running on every request.

**Severity, stated honestly.** `install_plugins` normally implies code execution anyway — that capability installs plugins. This granted something new only where that isn't true, realistically a site running **`DISALLOW_UNFILTERED_HTML`**, where admins keep `install_plugins`, lose `unfiltered_html`, and every other authoring path correctly closes. So: a bypass of an intentional hardening control, not a break from an unauthenticated or low-privileged user. (`DISALLOW_FILE_MODS` removes `install_plugins` outright and closes every route here.) S2 is a file-format bug worth fixing on its own merits regardless.

**Fixes.** Import now requires `unfiltered_html && install_plugins`, matching every sibling path. `sanitizeMetaValue()` — the single chokepoint every docblock write passes through, so fixing it there closes the injection for all paths at once — now replaces `*`, `\r` and `\n` with spaces after stripping `*/`.

Order matters there: stripping `*/` alone is defeatable, because `**//` collapses back into a terminator in one `str_replace` pass. It is the `*` → space pass running *afterwards* that makes it safe, and the test pins that specific input.

One value would have been corrupted by blanking `*`: `condition`, which is JSON and can legitimately contain one in a URL pattern. `Snippet::getMetaData()` now escapes it as a `*` unicode escape before sanitising, so `json_decode()` restores it exactly. That also fixes a pre-existing bug — a `*` anywhere in a condition previously broke docblock parsing for the whole snippet.

**Verified** — `tests/docblock-injection.php`, 27 checks: forged `@status` via bare `*`, via `\n`, via `\r\n`, and from a field written before `status`; forging a different key; `**//` not collapsing; the generated file still passing `php -l`; the code section untouched; ordinary names and descriptions round-tripping unchanged; a `*` in a condition URL surviving intact; plus the delete guard and the storage rules below.

### H6. `deleteSnippet()`'s guard protected nothing

**Fixed 2026-08-11.** `app/Model/Snippet.php` — *previously withdrawn as unreachable; the withdrawal reasoning was right, the code was still wrong.*

```php
if (!is_file($file) && ($fileName === 'index.php' || $fileName === 'cached')) {
```

`index.php` exists, so `!is_file()` is false, the whole condition is false, and it falls through to `unlink()`. The guard never fired for either file it names. Still unreachable — `SnippetsController::deleteSnippet()` calls `findByFileName()` first, which rejects `index.php` — so the withdrawal was correct that nothing was exploitable. But it read as protection while providing none, one `&&` away from mattering. Now `||`.

### L6. Two guards with the same name and different meanings

**Fixed 2026-08-11.** `app/Http/Controllers/SnippetsController.php`, `app/Http/Controllers/SettingsController.php`

Both controllers had a private `isBlockedRequest()`. `SnippetsController`'s checked `unfiltered_html`; `SettingsController`'s checked `unfiltered_html` **and** `manage_options`. Same name, same codebase, different contract — which made a deliberate difference look accidental. Renamed to `denyUnlessCanAuthorSnippets()` and `denyUnlessCanManageSettings()`, each documenting why it differs from the other.

`getRestOptions()` was the one method in `SettingsController` with no guard at all. It returns titles of **draft and private** posts across every public post type, plus every taxonomy term. Nothing was exposed that an `install_plugins` user couldn't already reach, and it only serves the condition builder on the edit screen — unusable without those capabilities anyway — so the guard is now applied for consistency.

*Behaviour change worth knowing:* on a site where someone holds `install_plugins` but not `unfiltered_html` + `manage_options`, the condition-builder dropdowns will now return 422 instead of data. That user could never save a snippet, so the screen was already read-only for them.

### I2. The storage directory now carries its own access rules

**Implemented 2026-08-11.** `app/Helpers/Helper.php` — promoted from "improvement idea" after the security pass.

`wp-content/fluent-snippet-storage/` holds every snippet's source **and** the kill-switch secret inside `index.php`. Every file already opens with an `ABSPATH` guard, so a request PHP actually processes returns nothing — that guard is real and this does not replace it. What it covers is the server no longer *processing* PHP there: an `.htaccess` lost in a migration, an nginx `location` that never matched, a host serving `.php` as `text/plain`. In that state the directory hands out all snippet source and the secret.

`Helper::protectStorageDir()` writes an `.htaccess` denying direct access, called from `cacheSnippetIndex()` so existing installs pick it up on their next rebuild rather than only on fresh ones. Three deliberate constraints:

- **Scoped to `.php`.** `cached/` serves `.css` and `.js` to visitors and inherits this file, so it has to stay publicly readable.
- **Both Apache generations, each behind `IfModule`.** An unknown directive in `.htaccess` returns 500 for the directory, and on this directory that would break cached asset delivery. `AllowOverride None` ignores the file entirely, which is harmless.
- **Never overwrites an existing file,** and the whole thing is skippable via `apply_filters('fluent_snippets/protect_storage_dir', ...)` for hosts that dislike it.

This is the one change in this pass with any real-world risk attached, which is why it is bounded this tightly.

### H2. Fatal-error auto-disable missed most real-world fatals

**Fixed 2026-08-11.** `app/Hooks/Handlers/CodeHandler.php`, `app/Services/CodeRunner.php`, `app/Services/mu.stub`, new `tests/fatal-attribution.php`

"Automatically Disable Script on fatal error" is on by default, so this is the safety net users actually rely on. Three separate things stopped it firing:

**1. Only `E_ERROR` counted.** `$error['type'] === 1` missed `E_PARSE`, `E_COMPILE_ERROR`, `E_CORE_ERROR` and `E_USER_ERROR`. A truncated or hand-edited snippet file produces a *parse* error, never `E_ERROR` — so the one failure mode most likely to need quarantining was the one guaranteed not to trigger it, and the site stayed broken on every subsequent request. Now matched against `CodeHandler::FATAL_ERROR_TYPES`.

`E_RECOVERABLE_ERROR` is deliberately **not** in that list: a custom error handler can recover from one, and acting on a handled error would disable working code. The type filter has to stay narrow for a different reason too — `error_get_last()` returns the last error of *any* severity, including a deprecation notice on a request that finished perfectly well.

**2. The snippet was identified by `dirname($error['file'])`.** That only matches when the fatal's deepest frame *is* the snippet file. The common real failure — a snippet calling a WordPress or third-party function that then fatals — reports `wp-includes/…`, so the snippet was never blamed and the site stayed down.

Fixed by tracking rather than inferring: `CodeRunner` keeps a stack of the snippets currently mid-execution, pushed and popped around every `require_once` (and around the shortcode `include` in `CodeHandler`). Anything still on that stack at shutdown never finished.

**3. A symlinked storage directory defeated the path comparison** — found while writing the test, not in the first review pass. PHP reports the **resolved** path in `$error['file']`, while `Helper::getStorageDir()` returns whatever `WP_CONTENT_DIR` (or `FLUENT_SNIPPETS_STORAGE_DIR`) was set to. On a host serving the site through a symlinked directory — release-based deploys do this as a matter of course — those are two different strings for the same file, and the existing check silently failed for *every* fatal, including the ones it used to catch. `CodeHandler::isSnippetFile()` now falls back to comparing `realpath()`. macOS made this visible because `/tmp` and `/var` are themselves symlinks; on a Linux host with a conventional layout it would have stayed hidden.

**The load-bearing detail: the pop is in a `finally`, deliberately.** A fatal error does not unwind the stack, so `finally` does **not** run and the snippet stays on the list to be blamed — which is the entire point. An exception *does* unwind, so the pop runs and the stack cannot be left dirty by a throw that something upstream swallows.

That trade is intentional and it does cost something: a snippet whose *uncaught exception* is thrown inside core rather than in the snippet itself is no longer attributable. Taken knowingly — a wrongly quarantined working snippet is a silent, confusing regression on ~50K sites, while missing that case only preserves today's behaviour. Fatals proper (`E_ERROR`, memory exhaustion, timeouts) are unaffected, and an exception thrown *in* the snippet is still caught by the path check.

Two further guards on the new path: the tracked name is only quarantined if the index actually knows it (`published` or `draft`), since it now comes from runtime state rather than from the file that faulted; and the message keeps the downstream location — trimmed of `ABSPATH` so the admin screen shows `wp-includes/…` rather than a full server path — prefixed with *"Fatal error while this snippet was running"*.

**Verified** — `tests/fatal-attribution.php`, 26 checks. The core assumption cannot be tested in-process, because a fatal ends the process, so six scenarios run in **child processes that really do fatal** and the parent asserts on what ended up in `error_files`: a fatal downstream of a running snippet (quarantined, with the right message), a parse error in a snippet file (quarantined), a caught exception followed by an unrelated fatal (**not** quarantined — proves the stack is not left dirty), a warning-only request (nothing), a fatal with nothing running (nothing), and a tracked name absent from the index (nothing). Plus the stack's own nesting behaviour and the full type list in both directions.

*Test note:* the first run failed on the fixture guard rather than on an assertion — the parent process had not defined `ABSPATH`, so every `include` of a generated `index.php` hit its own `if (!defined("ABSPATH")) { return; }` guard and came back empty, which would have made several checks pass vacuously. Worth keeping in mind for any future test that reads a generated index.

**`mu.stub` carries the same `CodeRunner` changes** even though nothing there reads them — the standalone runner has no fatal handler at all (H1, withdrawn). Mirroring keeps `tests/mu-stub-drift.php` comparing member bodies byte for byte; an exemption would have hidden real drift. It now compares 20 members, up from 16.

**Release note:** `mu.stub` changed, and `Helper::maybeUpdateStandAlone()` only rewrites an installed runner when its version differs from `FLUENT_SNIPPETS_PLUGIN_VERSION`. Standalone sites therefore pick this up on the next **version bump**, not on this commit.

### Cleanup pass — H3/I7, M3, M8, M9, M11, M12, M14, M15, L1, L5, L8, L10

**Fixed 2026-08-11.** New `tests/cleanup-pass.php` (20 checks) covers the four items that change behaviour; the rest are mechanical.

**H3 + I7 — `error_files` grew forever.** `handleFileDelete()`'s three `unset()` calls mutated a local copy that `cacheSnippetIndex()` then discarded: it rebuilds `published`/`draft` from disk and re-reads `error_files` from the file it is about to overwrite. The published/draft entries vanished anyway (the file is gone, so the rebuild can't find it) — the `error_files` entry was the one that survived, forever. Pruning now happens inside the rebuild via `array_intersect_key()` against `published + draft`, which also heals entries already stranded on disk. The dead `unset()`s are gone.

More urgent since **H2**: the auto-disable now catches parse errors and downstream fatals it used to miss entirely, so `error_files` is written far more often than a map that only grows could tolerate. Drafts are deliberately *not* pruned — a draft can still be published.

*I7's other half (a timestamp so the UI could say "paused 3 days ago") is a feature, not a fix, and would need a `dist` rebuild. Not done.*

**M8 — `Router` destroyed other plugins' output buffers.** A bare `ob_get_clean()` ran after every REST callback with nothing having opened a buffer. Harmless when none is active; when *another plugin* had one open it silently ate their output. Now a buffer is opened in the same scope and torn down only to the depth it was at on entry, inside a `finally` so an early error return can't leak it.

**M9 — unguarded array access.** `Arr::get()` with sane defaults across the rebuild path, the shortcode handler, and both REST decode sites (which now reject a `null` from `json_decode` with a real message instead of warning). The recurring one was `priority`: `parseBlock()`'s defaults omitted it while `cacheSnippetIndex()` sorts on it, so any legacy or hand-edited file without an `@priority` line warned on every rebuild. It's now defaulted in `parseBlock()` *and* read through `Arr::get()` in the sort.

**M14 — and the cache-coherence bug it exposed.** `getIndexedConfig()`'s `if ($config && $cached)` was false for `[]`, so a fresh install re-ran `is_file()` + `include` on every call. Fixed with a separate `$loaded` flag — which promptly broke the test suite, and correctly so.

The static cache was *never* invalidated by `saveIndexedConfig()`. That had always been true (noted under M6 as the reason the old forced rebuild never affected its own response), but it never bit, because an empty config is falsy and the old condition happened to re-read every time. Making the cache actually work made the staleness reachable: a read after a rebuild returned the pre-rebuild copy. So `flushIndexedConfigCache()` now runs on every write. The fix and the invalidation are one change; shipping the first without the second would have been a regression.

**M15 — `cacheSnippetIndex()` takes no parameters.** `$fileName` was overwritten by its own loop, `$isForced` was never read, `$extraArgs` was never passed by anything. PHP allows extra arguments to a user-defined function, so any external caller on the old signature keeps working. The `fluent_snippets/rebuild_index` action still *fires* with two arguments for compatibility; its callback just ignores them.

**L1 — `escCssJs()` closing tags.** `<\/script>` and `<\/style>` missed `</script >`, `</script\n>` and `</SCRIPT>`, all of which HTML parsers treat as terminators, so one could survive into the page and break out of the block. Now `/<\/\s*(script|style)[^>]*>/i`, applied identically in `Helper` and `CodeRunner` (and mirrored into `mu.stub`).

**Revised the same day — the lossy half is fixed too.** See "L1 revisited" below.

**M3** — dropped the dead `has_line_wrap` default (the setting is stored as `enable_line_wrap`) and settled the three-way disagreement on its default. `getSettings()` said `'yes'` while `saveSettings()` and the editor bootstrap both said `'no'`, so the settings screen showed the toggle on while wrapping was off. Aligned to `'no'`. **A second, live bug behind the same key surfaced afterwards — see "M3 revisited" below.**

**M11** — import now checks `$_FILES['file']` exists, that `error` is `UPLOAD_ERR_OK`, and that `tmp_name` passes `is_uploaded_file()`; export coerces a non-array `snippets` parameter instead of fataling in `array_map()`. Confirmed during the permissions pass that none of this was a traversal risk — PHP populates `$_FILES` itself. The gain is a real error message instead of a warning cascade.

**M12** — `php_content` was falling through into an empty `default:`. Harmless today, a trap for the next case added. Mirrored into `mu.stub`.

**L5** — `strpos` → `stripos` in snippet search, so "Header" finds "header script".

**L8** — `$isEnable == 'yes'` compared a bool to a string and worked by accident under loose comparison. Now `if ($isEnable)`.

**L10** — `.DS_Store` added to `.gitignore`. It was never tracked; `build.sh` already excluded it from the zip.

### L1 revisited, M3 revisited, L7 — the second pass over the three left half-done

**Fixed 2026-08-11**, after the maintainer asked for all three to be finished. No release was pending, which is what made the API removal in L7 reasonable to do now.

**L1 revisited — the lossy half.** The first pass fixed the leaky half (the closing-tag patterns) and left the lossy half alone, on the grounds that a literal `</script>` in a JS string really does end the block. That reasoning was sound but the conclusion was too pessimistic, because it missed a simpler fact:

*Inside a `<script>` or `<style>` element the HTML parser looks for nothing but the closing tag — an **opening** `<script>` is ordinary text.* So stripping opening tags was never needed for correctness, and it was the half that corrupted `document.write('<script src="..."></script>')`. That stripping is now gone entirely.

The closing tag is **escaped rather than deleted**: `<\/script` is identical to `</script` everywhere it can legally appear in JS or CSS — string literals, regex literals, comments — so the code keeps working *and* the block cannot be terminated early. Deleting changed behaviour; escaping preserves it.

That left the save-time rejection, which used `escCssJs()` as a detector and refused anything it would alter. Replaced with `Helper::detectWrappingTag()`, which refuses only what is actually a mistake: code pasted **wrapped** in its own `<script>`/`<style>` tag, where the editor already emits the surrounding element and the pasted tag would land as literal text. A mere *mention* of a tag is now accepted and escaped on output.

*Behaviour change:* inline CSS/JS snippets containing a closing tag are emitted escaped instead of stripped. Previously such a snippet could not be saved at all, so no existing saved snippet is affected — only hand-edited or legacy files, which are now emitted more faithfully than before.

**M3 revisited — the dead key was hiding a live bug.** Dropping the unused `has_line_wrap` default was only half the story. `AdminMenuHandler::render()` was localising the setting as `has_line_wrap`, while `_CodeEditor.vue:159` reads `appVars.enable_line_wrap`. Confirmed against the built bundle: `dist/app.js` contains `enable_line_wrap` once and `has_line_wrap` never.

So **the editor never received the setting on page load.** Turning line wrap on, reloading, and finding it off again was the visible symptom; it only ever took effect after visiting the Settings screen, which sets the key client-side. Renamed to what the editor actually reads. No `dist` rebuild needed — the fix is entirely on the PHP side, since the Vue code was already correct.

This also settles the default question the first pass raised: because the value never reached the editor, "no wrap on load" is what every site has always experienced, so keeping `'no'` as the unset default changes nothing anyone has seen.

**L7 — one way in per operation.** Removed three REST routes that nothing called: `POST snippets`, `snippets/create` and `snippets/update`. The editor uses admin-ajax (`fluent_snippet_create` / `fluent_snippet_update`) for both operations — verified by enumerating every `$get`/`$post`/`$ajax` call in `src/`. `SnippetsController::createSnippet()` and `updateSnippet()` went with them; both were thin wrappers over `Helper::createSnippet()`/`updateSnippet()`, which the admin-ajax handlers already call, so no logic was lost and no behaviour changed.

**This is a public API removal**, which is why the first pass declined to do it unprompted: a third-party integration could have been calling those routes. Done on the maintainer's explicit instruction, with no release pending. `routes.php` carries a comment saying what was removed and how to restore it.

`SnippetsController::validateMeta()` stays as an entry point because the importer calls it, but it now delegates to `Helper::validateMeta()` instead of duplicating it byte for byte.

**Verified** — `tests/cleanup-pass.php` grew to 38 checks: every closing-tag spelling neutralised *and* confirmed escaped rather than deleted; a `document.write()` script loader surviving untouched; opening tags no longer stripped; and the save-time detector accepting a loader, plain JS, plain CSS and a mention of a closing tag while refusing wrapped `<script>`/`<style>` with or without leading whitespace.

### M1 + REST permission callback

**Fixed 2026-08-02** (commit `12ff007`), recorded here after the fact.

- **M1** — `Helper::getCachedDir()` used `is_file()` to test for a directory, which is always `false`, so every call re-ran `wp_mkdir_p()` and rewrote the silence file. Now `is_dir()`.
- **`Router` permission callback now fails closed.** A route registered with an **empty** capability array previously returned `true` — i.e. was served to anyone. No route in `routes.php` is registered that way, so nothing was actually exposed; the change removes the trapdoor rather than closing a live hole. Not one of the numbered findings.

---

## 🔧 Open — worth doing

One finding left, and it needs a person rather than a patch. The 2026-08-11 passes
cleared everything else — see **Fixed** above.

### L9. `readme.txt` says `Tested up to: 7.0`

Confirm this matches a released WordPress version before the next release, or the
compatibility badge degrades on wp.org. **Not touched in the cleanup pass** — picking a
version number without checking what has actually shipped risks claiming compatibility
with a release that does not exist, which violates wp.org's guidelines. Needs a human
with the release calendar in front of them. Consider adding `Update URI:` to the plugin
header at the same time.

---

## 🔐 Permissions pass — checked and clean

A pass over every entry point on 2026-08-11, prompted by the plugin's install base. Recorded so the *absence* of a finding is traceable and nobody re-audits the same ground blind. Findings it produced are S1, S2, H6 and L6 above.

**Entry points enumerated:** 13 REST routes (`app/Http/routes.php`), 4 admin-ajax actions, `admin_menu` / `admin_init`, the `fluent_snippet` shortcode, and the unauthenticated kill switch in `CodeHandler::isDisabled()`.

| Area | Verdict |
|---|---|
| **The unauthenticated kill switch** | **Sound.** `isDisabled()` runs on every request and can set `force_disabled`, but compares with `hash_equals()` against a 128-bit `random_bytes` secret — constant-time, adequate entropy. No CSRF delta: forging the request requires already knowing the secret, which *is* the authorisation. The secret is emitted only by `getSettings()`, behind `unfiltered_html + manage_options`; it is never localised to JS and never appears in list or find responses. |
| **REST CSRF** | Handled by core — cookie auth requires `X-WP-Nonce`. Every route has a `permission_callback`, and since `12ff007` an empty capability array fails closed instead of returning `true`. |
| **admin-ajax** | All four handlers check capability **first**, then nonce. `wp_ajax_*` fires for any logged-in user, so both are required; both are present. |
| **Path traversal** | `sanitize_file_name()` on every filename parameter (it strips `/`, so `..` cannot compose). Export intersects against the real `glob()` result — a genuinely effective defence. `findByFileName()` rejects `index.php`. |
| **Docblock code injection** | Blocked. Meta cannot inject *executable* code even before S2 — `sanitizeMetaValue()` prevented closing the comment. S2 was forged *meta*, not forged code, which mattered only because `@status` decides whether attacker-supplied code runs. |
| **`$_FILES` handling** | PHP populates `$_FILES` from the multipart body, so `tmp_name` cannot be aimed at an arbitrary local file. The missing `isset` / `UPLOAD_ERR_OK` checks (**M11**, still open) are robustness, not a hole. |
| **`snippets/sync-index`** | Gated at `install_plugins` like the other reads, though it does write `index.php`. Deliberate: gating it at `unfiltered_html` would stop a read-only admin's list from ever self-healing, and it accepts no input — it only reflects what is already on disk. |

**Known and accepted, not defects:**

- **`install_plugins` is the floor, and it already implies code execution.** Requiring `unfiltered_html` on top is defence in depth. The one setup where it is a real boundary is `DISALLOW_UNFILTERED_HTML`, which is exactly what S1 broke.
- **Shortcode attribute pass-through.** `handleShortcode()` deliberately forwards unknown attributes into `$atts` (documented in the code). Anyone who can insert a shortcode — a contributor — can therefore feed arbitrary values to an admin-authored `php_content` snippet with `run_at = shortcode`. Only snippets explicitly created that way are reachable, so this is design. But it means snippet authors are handling untrusted input, and nothing in the UI says so. Worth a line in the docs rather than a code change.
- **M10** (`hash_equals()` fatals on an array `snippet_secret`) stays withdrawn as a self-DoS. Re-checked against H2: the running-snippet stack is empty at `register()` time, so this cannot be steered into quarantining a snippet.

---

## ⛔️ Withdrawn

Investigated and retired — **not** carried as debt. Recorded so they don't get re-raised.

| # | Finding | Why it was retired |
|---|---|---|
| — | `startsWith` / `endsWith` operators are commented out and silently return `false` | **Unreachable.** Those operators only render for `itemConfig.type == 'extended_text'` (`FilterItem.vue:119`), and no condition in this plugin uses that type — `grep` for `extended_text` hits only the Vue file. Inherited from FluentCRM. |
| H1 | Standalone (MU) mode has no fatal-error recovery — neither the `shutdown` quarantine nor the secret kill-switch URL exists in `mu.stub`, so standalone on → plugin deactivated → a snippet fatals → white screen, recoverable only by setting `FLUENT_SNIPPETS_SAFE_MODE` in `wp-config.php` | **Retired by the maintainer, 2026-08-11:** enabling standalone mode is a deliberate opt-in that happens *after* the snippets have been running and proving themselves under the active plugin, so a snippet that fatals the moment the plugin is deactivated is not a case real users land in. **Residual, recorded so it is not re-raised as "impossible":** a snippet can still start fatalling without being edited — a PHP version upgrade, a plugin or theme being removed out from under a snippet that calls its functions, a core deprecation. Those are the only paths left, they need no bad edit, and `wp-config.php` remains the only way out. **I5** (WP-CLI `safe-mode`) would give that residual a recovery path without touching the stub at all |
| H4 | `CodeRunner::parseBlock()` breaks on CRLF | **Self-consistent.** `PHP_EOL` is used for both the write (`parseInputMeta`) and the read (`explode`), so it matches on any single server, Windows included. Only breaks if files move between platforms. |
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

**I1. ~~Generate `mu.stub` at build time~~ — resolved a different way.** The maintainer chose to keep `mu.stub` hand-maintained and add `tests/mu-stub-drift.php` instead of introducing build machinery, on the grounds that it doesn't disturb the release process. That retired **C3, C4 and H5**. Worth revisiting only if the drift test starts failing routinely — that would be the signal that hand-maintenance isn't holding. Wire it into CI if one ever exists.

**I2. Harden the storage directory.** Ship `.htaccess` / `web.config` alongside the existing `index.php`. The `if (!defined("ABSPATH")) return;` guard in each snippet already handles the normal case; this covers a server misconfigured to serve `.php` as `text/plain`. `cached/` must stay publicly readable.

**I3. Snippet revisions.** A save overwrites in place, so a bad edit is unrecoverable. Keeping the last N versions in `storage/revisions/` composes naturally with the new `Helper::atomicPut()`.

**I4. A capability filter.** `install_plugins` is hardcoded in six places. `apply_filters('fluent_snippets/manage_capability', 'install_plugins')` would let agencies grant snippet access without granting plugin installation.

**I5. WP-CLI commands.** `wp fluent-snippets list|activate|deactivate|rebuild-index|safe-mode` gives users a recovery path that doesn't require editing `wp-config.php`. Now the most useful idea on this list: it is the only thing that covers the residual left by withdrawing **H1** (a snippet that starts fatalling in standalone mode because the environment changed under it, not because anyone edited it), and it does so without adding recovery machinery to `mu.stub` at all — the CLI runs with the plugin's own code available.

**I6. Static analysis.** No tests, no lint config. PHPStan level 5 would have caught most of M9 and the H6 inverted condition; PHPCS with `WordPress-Extra` for the rest. Highest-value unit tests: `parseBlock()` round-tripping (all four marker variants, empty docblock), `PhpValidator` (valid / invalid / side-effecting code), and `FluentSnippetCondition::checkValues()` across all 25 operators.

**I7. Bound the `error_files` map.** Follows from H3 — prune to existing snippets on every rebuild, and consider a timestamp so the UI can show *"paused 3 days ago"*.

---

## Suggested order of work

| # | Item | Status | Why here |
|---|---|---|---|
| 1 | **C1** atomic writes | ✅ done | Only finding whose failure mode was a frontend 500 |
| 2 | **M7** `error_log('OK')` | ✅ done | Free win, one line |
| 3 | **C2b** `catch \Throwable` | ✅ done | Turns a raw 500 on save into a real message |
| 4 | **C3 + C4 + H5** + drift test | ✅ done | One change, three findings |
| 5 | ~~**H1** port recovery into the stub~~ | ⛔️ withdrawn | Standalone mode is opted into after the snippets have proven themselves — see Withdrawn |
| 6 | **H2** fatal attribution | ✅ done | Restores the auto-disable guarantee the plugin ships on by default |
| 7 | **H3 + I7** prune `error_files` | ✅ done | Self-heals stale entries already on disk |
| 8 | **M6** stop rebuilding on read | ✅ done | Removes the write-on-read that made C1 reachable |
| 9 | **M3, M8, M9, M11, M12, M14, M15, L1, L5, L8, L10** | ✅ done | Cleanup pass (**M1** done early with M6; **L6** with the permissions pass) |
| — | **L1, M3, L7** second pass | ✅ done | Finished the three the cleanup pass left half-done; L7 removed public routes, so it needed the maintainer's call |
| 10 | **I6** static analysis | next | Locks the fixes in — 5 harness suites exist now, but nothing runs them automatically |
| — | **S1 + S2, H6, L6, I2** permissions pass | ✅ done | Import could introduce code without `unfiltered_html`; forged `@status` defeated its forced draft |
