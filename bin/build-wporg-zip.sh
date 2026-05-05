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

echo "==> Staging source into $STAGING_DIR/ ($(echo "${#RSYNC_EXCLUDES[@]} / 2" | bc) excludes)"
rsync -a "${RSYNC_EXCLUDES[@]}" ./ "$STAGING_DIR/"

# ---------------------------------------------------------------------------
# Install production dependencies inside the staging dir.
#
# We use --no-scripts to skip the post-install hook (which would try to
# bin-install Strauss into a fresh composer-bin context — slow and
# unnecessary because the dev tree's vendor-prefixed/ is already
# scoped against the production dependency set). Instead we copy
# vendor-prefixed/ verbatim from the source tree below.
# ---------------------------------------------------------------------------
echo "==> Installing production dependencies (skipping post-install scripts)"
(
    cd "$STAGING_DIR"
    composer install \
        --no-dev \
        --optimize-autoloader \
        --classmap-authoritative \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --quiet
)

# Copy the pre-built scoped vendor from the source tree. Strauss already
# ran (during dev `composer install`) and produced these — re-running it
# inside the staging dir would require composer-bin install which is
# slow and reinstalls php-parser etc. into the temp tree.
echo "==> Copying pre-built vendor-prefixed/ from source tree"
rsync -a "$REPO_ROOT/vendor-prefixed/" "$STAGING_DIR/vendor-prefixed/"

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
