#!/usr/bin/env bash
#
# Run WordPress.org's Plugin Check against the plugin installed in wp-env and
# fail on any ERROR. `wp plugin check` itself exits 0 whatever it finds, so the
# CSV it prints is the only signal.
#
# Excluded: `plugin_updater`. It flags the `Update URI` header and the updater
# in src/Update/, which a plugin hosted on WordPress.org may not have and this
# one, distributed from GitHub releases, needs. See the docblock of
# src/Update/UpdateChecker.php.
#
# Warnings are printed, not failed on: the standing ones are
# WordPress.NamingConventions.PrefixAllGlobals on published hook names that
# cannot be renamed without breaking every site that hooks them.
#
# Usage: bin/plugin-check.sh   (wp-env running, plugin active)

set -euo pipefail

SLUG="vcr-am-fiscal-receipts"
# Pinned: each release adds checks, and an unpinned install made CI and a
# local run disagree about the same tree. Raise it on purpose.
PLUGIN_CHECK_VERSION="2.2.0"
WP_ENV="$(dirname "$0")/../node_modules/.bin/wp-env"

# `--` keeps wp-env from taking `--version` as its own flag, which dropped
# the pin silently before.
"$WP_ENV" run cli -- wp plugin install plugin-check --version="$PLUGIN_CHECK_VERSION" --force --activate >/dev/null

installed="$("$WP_ENV" run cli -- wp plugin get plugin-check --field=version)"
if [ "$installed" != "$PLUGIN_CHECK_VERSION" ]; then
    echo "Plugin Check $installed installed, $PLUGIN_CHECK_VERSION asked for" >&2
    exit 1
fi

report="$("$WP_ENV" run cli -- wp plugin check "$SLUG" --exclude-checks=plugin_updater --format=csv)"

errors="$(grep -c ',ERROR,' <<<"$report" || true)"
warnings="$(grep -c ',WARNING,' <<<"$report" || true)"

printf '%s\n' "$report"
echo
echo "Plugin Check: $errors error(s), $warnings warning(s)"

if [ "$errors" -ne 0 ]; then
    exit 1
fi
