#!/usr/bin/env bash
#
# Build FluentSnippets into builds/easy-code-manager.zip.
#
#     ./build.sh                  everything: strings, tests, assets, package
#     ./build.sh --loco           also run Loco extract/sync (needs a WordPress install)
#     ./build.sh --skip-tests     package without running the test suite
#     ./build.sh --skip-assets    package the dist/ that is already on disk
#     ./build.sh --help
#
# The default used to be the least useful thing it could do: without --node-build it
# skipped the asset build entirely and zipped whatever dist/ happened to be lying around,
# so a release could ship the previous release's JavaScript. Everything runs by default
# now, and the flags turn steps off rather than on.

set -euo pipefail

# Run from the plugin root no matter where the script was invoked from — every step below
# uses relative paths.
cd "$(dirname "$0")"

PLUGIN_SLUG="easy-code-manager"
BUILD_DIR="builds/$PLUGIN_SLUG"
ZIP_FILE="builds/$PLUGIN_SLUG.zip"

# What ships. Anything not listed here stays out of the zip, which is why tests/, src/,
# node_modules/ and the build tooling never reach a user.
PACKAGE_ITEMS="app dist language easy-code-manager.php readme.txt index.php"

with_loco=false
run_tests=true
build_assets=true

if [ -t 1 ]; then
    C_BOLD=$'\033[1m'; C_RED=$'\033[31m'; C_YELLOW=$'\033[33m'; C_GREEN=$'\033[32m'; C_OFF=$'\033[0m'
else
    C_BOLD=""; C_RED=""; C_YELLOW=""; C_GREEN=""; C_OFF=""
fi

step() { printf '\n%s==> %s%s\n' "$C_BOLD" "$1" "$C_OFF"; }
info() { printf '    %s\n' "$1"; }
warn() { printf '    %swarning:%s %s\n' "$C_YELLOW" "$C_OFF" "$1" >&2; }
fail() { printf '\n%sBuild failed:%s %s\n\n' "$C_RED" "$C_OFF" "$1" >&2; exit 1; }

usage() {
    # Just the usage block at the top of this file, not the commentary below it.
    sed -n '2,9p' "$0" | sed 's/^# \{0,1\}//'
    exit 0
}

for arg in "$@"; do
    case "$arg" in
        --loco)        with_loco=true ;;
        --skip-tests)  run_tests=false ;;
        --skip-assets) build_assets=false ;;
        # Accepted so an old invocation does not fail. Both are the default now.
        --node-build|--dev_build) ;;
        -h|--help)     usage ;;
        *)             fail "unknown option: $arg (try --help)" ;;
    esac
done

require_tool() {
    command -v "$1" >/dev/null 2>&1 || fail "$1 is required but was not found on PATH."
}

# `pipefail` is on, and a reader that exits early (`head -1`, `grep -q`) sends SIGPIPE to
# the producer, which then reports failure even though the read succeeded. These two keep
# the pipe out of it: take the first line with parameter expansion, and match with a case
# statement over captured text.
first_line() { printf '%s\n' "${1%%$'\n'*}"; }

contains() {
    case "$1" in
        *"$2"*) return 0 ;;
        *)      return 1 ;;
    esac
}

# ---------------------------------------------------------------------------
step "Checking prerequisites"

require_tool node
require_tool php
require_tool rsync
require_tool zip

info "node $(node --version), php $(php -r 'echo PHP_VERSION;')"

if [ ! -d node_modules ]; then
    step "Installing node dependencies"
    require_tool npm
    npm install || fail "npm install failed."
fi

# ---------------------------------------------------------------------------
step "Collecting translatable strings"

# app/Services/Trans.php is the only route a string in the admin app has to a translator,
# and it is generated from the $t() calls in src/. Regenerating here (rather than only
# under --loco, as it used to be) means a release can never ship strings that were never
# offered for translation.
node i18n.node.js || fail "string extraction failed."

if [ "$with_loco" = true ]; then
    step "Syncing Loco translations"
    require_tool wp
    wp loco extract "$PLUGIN_SLUG" || fail "wp loco extract failed."
    wp loco sync "$PLUGIN_SLUG" || fail "wp loco sync failed."
    info "language/$PLUGIN_SLUG.pot updated"
else
    info "skipping Loco extract/sync (pass --loco to refresh language/$PLUGIN_SLUG.pot)"
fi

# ---------------------------------------------------------------------------
if [ "$run_tests" = true ]; then
    step "Running tests"

    test_failures=0

    for test_file in tests/*.php tests/*.js; do
        [ -e "$test_file" ] || continue

        case "$test_file" in
            *.php) runner=php ;;
            *.js)  runner=node ;;
        esac

        if output=$("$runner" "$test_file" 2>&1); then
            info "${C_GREEN}ok${C_OFF}    $(basename "$test_file")"
        else
            info "${C_RED}FAIL${C_OFF}  $(basename "$test_file")"
            printf '%s\n' "$output" | sed 's/^/          /'
            test_failures=$((test_failures + 1))
        fi
    done

    [ "$test_failures" -eq 0 ] || fail "$test_failures test file(s) failed. Nothing was packaged."
else
    step "Skipping tests (--skip-tests)"
fi

# ---------------------------------------------------------------------------
if [ "$build_assets" = true ]; then
    step "Building admin assets"
    npx mix --production || fail "asset build failed."
else
    step "Skipping asset build (--skip-assets)"

    # Shipping last release's JavaScript is the mistake this script used to make by
    # default, so taking the shortcut has to prove the bundle is not behind the source.
    #
    # Compared against mix-manifest.json, not app.js: webpack leaves an output file
    # untouched when the new bytes are identical, so app.js can be older than a source
    # file that was only re-saved, and the check would then fail forever. The manifest is
    # rewritten on every run, which is exactly the question being asked — has a build
    # happened since the sources last changed.
    [ -f dist/mix-manifest.json ] || fail "dist/ has never been built. Run without --skip-assets."

    stale_sources=$(find src -type f -newer dist/mix-manifest.json 2>/dev/null || true)

    if [ -n "$stale_sources" ]; then
        fail "$(first_line "$stale_sources") changed after the last asset build. Run without --skip-assets."
    fi
fi

[ -f dist/app.js ] || fail "dist/app.js does not exist. Run without --skip-assets."

# ---------------------------------------------------------------------------
step "Checking version numbers"

header_version=$(first_line "$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([0-9.]*\).*/\1/p' "$PLUGIN_SLUG.php")")
const_version=$(first_line "$(sed -n "s/.*FLUENT_SNIPPETS_PLUGIN_VERSION',[[:space:]]*'\([0-9.]*\)'.*/\1/p" "$PLUGIN_SLUG.php")")
readme_version=$(first_line "$(sed -n 's/^Stable tag:[[:space:]]*\([0-9.]*\).*/\1/p' readme.txt)")

[ -n "$header_version" ] || fail "could not read the Version header from $PLUGIN_SLUG.php."

if [ "$header_version" != "$const_version" ] || [ "$header_version" != "$readme_version" ]; then
    fail "version mismatch — plugin header $header_version, FLUENT_SNIPPETS_PLUGIN_VERSION $const_version, readme.txt stable tag $readme_version."
fi

info "version $header_version"

# ---------------------------------------------------------------------------
step "Packaging"

# The old copy step took an "exclude psr/fakerphp/symfony" flag that was passed as an
# empty string, so `if "$exclude_folders"` tried to run the empty command and always fell
# through. The plugin has no vendor directory, so the whole branch is gone rather than
# fixed; add it back the day composer dependencies arrive.
rm -rf "$BUILD_DIR" "$ZIP_FILE"
mkdir -p "$BUILD_DIR"

for item in $PACKAGE_ITEMS; do
    [ -e "$item" ] || fail "$item is missing from the source tree."
    rsync -a --exclude='.DS_Store' "$item" "$BUILD_DIR/"
    info "packaged $item"
done

# `cd` inside a subshell so the rest of the script keeps its footing, and the exit status
# of zip is actually tested. It used to be checked after a `cd`, which meant $? described
# the directory change and a failed zip reported success.
(cd builds && zip -rq "$PLUGIN_SLUG.zip" "$PLUGIN_SLUG" -x "*.DS_Store") || fail "zip failed."

[ -f "$ZIP_FILE" ] || fail "$ZIP_FILE was not produced."

# Cheap proof the archive is not missing its two halves.
require_tool unzip
archive_list=$(unzip -l "$ZIP_FILE")

for required in "$PLUGIN_SLUG/dist/app.js" "$PLUGIN_SLUG/$PLUGIN_SLUG.php"; do
    contains "$archive_list" "$required" || fail "$required is missing from the archive."
done

zip_size=$(du -h "$ZIP_FILE" | awk '{print $1}')

printf '\n%sBuilt %s v%s — %s (%s)%s\n\n' \
    "$C_GREEN" "$PLUGIN_SLUG" "$header_version" "$ZIP_FILE" "$zip_size" "$C_OFF"
