#!/usr/bin/env bash
#
# Build a WP.org-ready plugin ZIP from the current working tree.
#
# What this does, step by step:
#
#   1. Computes the plugin slug from the entry-file basename (sans .php).
#   2. rsyncs the working tree into build/<slug>/ excluding everything
#      listed in .distignore. This is the same exclusion list `wp dist-archive`
#      consumes — keeping a single source of truth.
#   3. Runs `composer install --no-dev --optimize-autoloader --classmap-authoritative`
#      inside the staging directory. The post-install hook re-runs Strauss so
#      vendor-prefixed/ is regenerated against the prod-only dependency tree.
#   4. Strips the dev-only Strauss tooling (composer-bin, vendor-bin) from
#      the staged copy — they're needed *during* install but not at runtime.
#   5. Zips build/<slug>/ into dist/<slug>.zip.
#
# Why a shell script and not a composer script: composer scripts run inside
# the repo's vendor environment, so `composer install --no-dev` would mutate
# the developer's own working tree. Doing the work in a copied staging dir
# isolates the build from the dev environment.
#
# Usage:
#
#   bin/build-wporg-zip.sh
#
# Requires: bash 4+, rsync, composer, zip. Optional: wp-cli (only if you
# want to validate the resulting ZIP with `wp plugin check`).

set -euo pipefail

# ---------------------------------------------------------------------------
# Locate repo root from the script's own path so the script works regardless
# of the caller's PWD.
# ---------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

# Plugin slug = entry file basename without .php. We grep for the canonical
# header so a renamed entry file is detected automatically rather than
# silently producing a wrong-named ZIP.
ENTRY_FILE="$(grep -lE '^\s*\*\s*Plugin Name:' ./*.php | head -n1 || true)"
if [[ -z "$ENTRY_FILE" ]]; then
    echo "error: no PHP file in repo root contains a 'Plugin Name:' header" >&2
    exit 1
fi
SLUG="$(basename "$ENTRY_FILE" .php)"

BUILD_DIR="build"
STAGING_DIR="$BUILD_DIR/$SLUG"
DIST_DIR="dist"
ZIP_PATH="$DIST_DIR/$SLUG.zip"

echo "==> Plugin slug: $SLUG"
echo "==> Entry file:  $ENTRY_FILE"

# ---------------------------------------------------------------------------
# Clean previous build artefacts so a stale leftover can never make it into
# a release.
# ---------------------------------------------------------------------------
echo "==> Cleaning $BUILD_DIR/ and $DIST_DIR/"
rm -rf "$BUILD_DIR" "$DIST_DIR"
mkdir -p "$STAGING_DIR" "$DIST_DIR"

# ---------------------------------------------------------------------------
# Build rsync exclude list from .distignore. Lines starting with '#' or
# blank are skipped. Each remaining entry is passed as --exclude.
# ---------------------------------------------------------------------------
if [[ ! -f .distignore ]]; then
    echo "error: .distignore is missing — refusing to build a ZIP without an exclusion list" >&2
    exit 1
fi

RSYNC_EXCLUDES=()
while IFS= read -r line; do
    # Strip leading/trailing whitespace + skip comments / blanks.
    trimmed="$(echo "$line" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
    [[ -z "$trimmed" || "$trimmed" =~ ^# ]] && continue
    RSYNC_EXCLUDES+=("--exclude=$trimmed")
done < .distignore

# Always exclude the build/dist directories themselves, otherwise rsync
# would copy our in-progress staging into the staging dir.
RSYNC_EXCLUDES+=("--exclude=$BUILD_DIR" "--exclude=$DIST_DIR")

# Exclude the two dependency trees as well. They are NOT listed in
# .distignore on purpose: that file answers "what must never reach an end
# user", and both trees must reach the end user. What we want here is a
# different thing — the staged tree is built from source rather than
# inheriting the developer's tree, so a dev machine carrying dev
# dependencies, a half-finished Strauss run or a locally patched package
# cannot leak into a release.
RSYNC_EXCLUDES+=("--exclude=/vendor/" "--exclude=/vendor-prefixed/")

echo "==> Staging source into $STAGING_DIR/ ($(echo "${#RSYNC_EXCLUDES[@]} / 2" | bc) excludes)"
rsync -a "${RSYNC_EXCLUDES[@]}" ./ "$STAGING_DIR/"

# ---------------------------------------------------------------------------
# Install production dependencies inside the staging dir.
#
# The lock file is excluded from the staged tree by .distignore (it must not
# ship), but `composer install` without one silently resolves dependencies
# afresh — so the artefact would carry whatever versions happened to be
# newest at build time rather than the versions CI tested. Copy it in for
# the install and remove it again below, so the build is reproducible.
# ---------------------------------------------------------------------------
if [[ ! -f composer.lock ]]; then
    echo "error: composer.lock is missing — refusing to build from an unlocked dependency set" >&2
    exit 1
fi
cp composer.lock "$STAGING_DIR/composer.lock"

echo "==> Installing production dependencies from the lock file"
(
    cd "$STAGING_DIR"
    composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --quiet
)

# Scope the production dependencies inside the staging tree.
#
# This must happen HERE and not be copied in from the source tree. Strauss is
# configured with delete_vendor_packages: true, so running it is what removes
# the unscoped originals from vendor/. Copying a pre-built vendor-prefixed/
# instead leaves the staging vendor/ fully populated, and since the plugin
# require_once's vendor/autoload.php at runtime, the ZIP would register an
# unscoped GuzzleHttp\Client in the global namespace — exactly the
# plugin-vs-plugin collision Strauss exists to prevent.
#
# bin/ is excluded from the staging tree by .distignore, so we invoke the
# wrapper from the repo: __DIR__ inside it resolves against the repo, while
# the CWD (staging) is what Strauss reads composer.json and vendor/ from.
echo "==> Scoping dependencies with Strauss (in the staging tree)"
if [[ ! -f "$REPO_ROOT/vendor-bin/strauss/vendor/autoload.php" ]]; then
    echo "error: Strauss bin context missing — run: composer bin strauss install" >&2
    exit 1
fi
(
    cd "$STAGING_DIR"
    php "$REPO_ROOT/bin/strauss"
)

# Strauss deleted the original packages out of vendor/, which invalidates the
# classmap composer wrote a moment ago. Regenerate it against what remains.
echo "==> Regenerating the production autoloader"
(
    cd "$STAGING_DIR"
    composer dump-autoload \
        --no-dev \
        --optimize \
        --classmap-authoritative \
        --no-interaction \
        --no-scripts \
        --quiet
)

# Strauss leaves its bootstrap (bin/strauss) and the bin-installed tooling
# (vendor-bin/) in place because composer-bin-plugin is a dev dependency and
# was just removed. But the bin/ directory and any leftover dev artefact
# should not ship — strip them defensively even though .distignore already
# excluded them from the initial rsync (a hostile composer plugin could
# recreate them during install).
echo "==> Stripping dev-only tooling that may have been recreated post-install"
rm -rf "$STAGING_DIR/bin" "$STAGING_DIR/vendor-bin" "$STAGING_DIR/composer.lock"

# ---------------------------------------------------------------------------
# Sanity check: verify the staging dir does NOT contain anything that
# .distignore says it shouldn't. A grep-based double-check catches the case
# where composer install secretly resurrected a file (e.g., a node_modules
# from a postinstall script).
# ---------------------------------------------------------------------------
echo "==> Sanity-checking staged tree"
forbidden_paths=("tests" "node_modules" ".github" ".phpunit.cache" "phpstan.neon.dist" "phpunit.xml.dist" "playwright.config.mjs" "package.json" "CLAUDE.md" ".claude" ".cursor")
violations=0
for path in "${forbidden_paths[@]}"; do
    if [[ -e "$STAGING_DIR/$path" ]]; then
        echo "  FAIL: $STAGING_DIR/$path exists — should be excluded" >&2
        violations=$((violations + 1))
    fi
done
if (( violations > 0 )); then
    echo "error: $violations forbidden artefact(s) made it into the staging dir" >&2
    exit 1
fi

# The plugin's entry file refuses to boot unless BOTH autoloaders are present
# and shows "missing composer dependencies" instead. Assert them here, so a
# build can never produce a ZIP that fails on activation.
for autoload in vendor/autoload.php vendor-prefixed/autoload.php; do
    if [[ ! -f "$STAGING_DIR/$autoload" ]]; then
        echo "error: $autoload missing — the plugin would refuse to activate" >&2
        exit 1
    fi
done

# Every production package must exist in exactly one of the two trees. A
# package present in both means Strauss did not delete the unscoped original,
# so the ZIP would load an unprefixed copy into the global namespace and
# collide with any other plugin bundling the same library.
echo "==> Verifying no dependency ships both scoped and unscoped"
duplicates=0
while IFS= read -r pkg; do
    if [[ -d "$STAGING_DIR/vendor/$pkg" ]]; then
        echo "  FAIL: $pkg exists in vendor/ and vendor-prefixed/" >&2
        duplicates=$((duplicates + 1))
    fi
done < <(cd "$STAGING_DIR/vendor-prefixed" && find . -mindepth 2 -maxdepth 2 -type d | sed 's|^\./||')
if (( duplicates > 0 )); then
    echo "error: $duplicates dependency(ies) ship unscoped as well as scoped" >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# Build the ZIP. zip's `-r` is recursive, `-q` is quiet, `-X` strips file
# attributes (we want a deterministic-ish ZIP; macOS's xattr noise breaks
# zip-content equality across platforms).
# ---------------------------------------------------------------------------
echo "==> Zipping into $ZIP_PATH"
(
    cd "$BUILD_DIR"
    zip -rqX "../$ZIP_PATH" "$SLUG"
)

# ---------------------------------------------------------------------------
# Report the final size and a checksum so a release-engineer can verify the
# uploaded ZIP matches what was built locally.
# ---------------------------------------------------------------------------
SIZE_HUMAN="$(du -h "$ZIP_PATH" | cut -f1)"
SHA256="$(shasum -a 256 "$ZIP_PATH" | cut -d' ' -f1)"
echo "==> Built $ZIP_PATH  (size: $SIZE_HUMAN, sha256: $SHA256)"
echo
echo "Next steps:"
echo "  - Test-install in a clean WP: cp $ZIP_PATH ~/Desktop && unzip -d ~/Desktop $ZIP_PATH"
echo "  - Optional: wp plugin check $ZIP_PATH  (requires wp-cli + Plugin Check plugin)"
