<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Migration;

use BlobSolutions\WooCommerceVcrAm\Logging\Logger;

/**
 * Tracks the installed plugin version (in `vcr_plugin_version`) and
 * runs registered migration callbacks when an upgrade jumps across
 * a migration boundary.
 *
 * Why this exists, even if v0.1 has zero migrations:
 *
 *   - Every plugin that ships v0.x → v1.x WILL eventually need a
 *     schema or option migration. Setting up the version-tracking
 *     scaffolding from day one means migration #1 doesn't also
 *     have to invent the migrator.
 *   - Stamps `vcr_plugin_version` so admins / support staff can see
 *     the installed version in `wp options get vcr_plugin_version`
 *     without parsing the plugin header file.
 *   - Hooks into `plugins_loaded` (NOT activation) so that admins
 *     who upload via FTP — bypassing the activation hook — still get
 *     migrations applied on the first request after upgrade. Real
 *     WC core does this too.
 *
 * Each migration is declared as a key-value pair: target version →
 * callback. A callback runs IFF stored version is below the target.
 * Callbacks are responsible for being idempotent (safe to re-run if
 * the migration crashed midway). Versions are compared via
 * `version_compare()`, so semver-ish strings work naturally.
 *
 * Not declared `final` so unit tests can mock the migrator.
 */
class Migrator
{
    public const OPTION_INSTALLED_VERSION = 'vcr_plugin_version';

    /**
     * @var array<string, callable>  target version => callable
     */
    private array $migrations = [];

    public function __construct(
        private readonly string $currentPluginVersion,
        private readonly Logger $logger = new Logger(),
    ) {
    }

    /**
     * Register a migration callback. Callbacks are run in the order
     * registered IF their target version is strictly newer than the
     * stored version AND <= the currently-running plugin version.
     */
    public function addMigration(string $targetVersion, callable $callback): void
    {
        $this->migrations[$targetVersion] = $callback;
    }

    /**
     * Compare stored version to current plugin version. If they
     * differ, run any pending migrations and stamp the new version.
     * Idempotent: multiple invocations on the same request are a
     * cheap option-read each.
     */
    public function maybeMigrate(): void
    {
        $stored = get_option(self::OPTION_INSTALLED_VERSION, '');
        if (! is_string($stored)) {
            $stored = '';
        }

        // Fresh install (no stored version) — stamp current and bail;
        // there's nothing to migrate FROM.
        if ($stored === '') {
            update_option(self::OPTION_INSTALLED_VERSION, $this->currentPluginVersion, false);

            return;
        }

        // Same version — no migration needed.
        if (version_compare($stored, $this->currentPluginVersion, '>=')) {
            return;
        }

        // Run each pending migration in target-version order.
        $applied = [];
        foreach ($this->migrations as $targetVersion => $callback) {
            if (version_compare($stored, $targetVersion, '<')
                && version_compare($targetVersion, $this->currentPluginVersion, '<=')) {
                try {
                    $callback();
                    $applied[] = $targetVersion;
                } catch (\Throwable $e) {
                    // Don't update the stored version — next request
                    // will retry. Log the failure so admins know
                    // there's a problem.
                    $this->logger->error(sprintf(
                        'Migration to %s failed: %s. Stored version stays at %s.',
                        $targetVersion,
                        $e->getMessage(),
                        $stored,
                    ), ['target_version' => $targetVersion, 'stored_version' => $stored]);

                    return;
                }
            }
        }

        // All applicable migrations succeeded — stamp the new version.
        update_option(self::OPTION_INSTALLED_VERSION, $this->currentPluginVersion, false);

        if ($applied !== []) {
            $this->logger->info(sprintf(
                'Plugin migrated from %s to %s (applied: %s).',
                $stored,
                $this->currentPluginVersion,
                implode(', ', $applied),
            ));
        }
    }
}
