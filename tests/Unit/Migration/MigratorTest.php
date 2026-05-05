<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Logging\Logger;
use BlobSolutions\WooCommerceVcrAm\Migration\Migrator;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    // Per-test option store — replaces wp_options for the duration.
    $this->optionStore = [];
    Functions\when('get_option')->alias(function (string $key, $default = '') {
        return $this->optionStore[$key] ?? $default;
    });
    Functions\when('update_option')->alias(function (string $key, $value, $autoload = null): bool {
        $this->optionStore[$key] = $value;

        return true;
    });

    $this->logger = Mockery::mock(Logger::class);
    $this->logger->allows('info')->byDefault();
    $this->logger->allows('warning')->byDefault();
    $this->logger->allows('error')->byDefault();
});

it('stamps the current version on a fresh install (no stored version)', function (): void {
    $migrator = new Migrator('1.0.0', $this->logger);
    $migrator->maybeMigrate();

    expect($this->optionStore[Migrator::OPTION_INSTALLED_VERSION])->toBe('1.0.0');
});

it('does nothing when stored version equals current', function (): void {
    $this->optionStore[Migrator::OPTION_INSTALLED_VERSION] = '1.0.0';
    $callbackFired = false;

    $migrator = new Migrator('1.0.0', $this->logger);
    $migrator->addMigration('1.0.0', function () use (&$callbackFired) {
        $callbackFired = true;
    });

    $migrator->maybeMigrate();

    expect($callbackFired)->toBeFalse();
    expect($this->optionStore[Migrator::OPTION_INSTALLED_VERSION])->toBe('1.0.0');
});

it('does nothing when stored version is newer than current (e.g., downgrade)', function (): void {
    $this->optionStore[Migrator::OPTION_INSTALLED_VERSION] = '2.0.0';
    $callbackFired = false;

    $migrator = new Migrator('1.0.0', $this->logger);
    $migrator->addMigration('1.5.0', function () use (&$callbackFired) {
        $callbackFired = true;
    });

    $migrator->maybeMigrate();

    expect($callbackFired)->toBeFalse();
    expect($this->optionStore[Migrator::OPTION_INSTALLED_VERSION])->toBe('2.0.0');
});

it('runs a single applicable migration and bumps the stored version', function (): void {
    $this->optionStore[Migrator::OPTION_INSTALLED_VERSION] = '1.0.0';
    $callbackFired = false;

    $migrator = new Migrator('1.5.0', $this->logger);
    $migrator->addMigration('1.5.0', function () use (&$callbackFired) {
        $callbackFired = true;
    });

    $migrator->maybeMigrate();

    expect($callbackFired)->toBeTrue();
    expect($this->optionStore[Migrator::OPTION_INSTALLED_VERSION])->toBe('1.5.0');
});

it('runs multiple pending migrations in registration order on a multi-version jump', function (): void {
    $this->optionStore[Migrator::OPTION_INSTALLED_VERSION] = '1.0.0';
    $applied = [];

    $migrator = new Migrator('1.3.0', $this->logger);
    $migrator->addMigration('1.1.0', function () use (&$applied) {
        $applied[] = '1.1.0';
    });
    $migrator->addMigration('1.2.0', function () use (&$applied) {
        $applied[] = '1.2.0';
    });
    $migrator->addMigration('1.3.0', function () use (&$applied) {
        $applied[] = '1.3.0';
    });

    $migrator->maybeMigrate();

    expect($applied)->toBe(['1.1.0', '1.2.0', '1.3.0']);
    expect($this->optionStore[Migrator::OPTION_INSTALLED_VERSION])->toBe('1.3.0');
});

it('skips migrations whose target is newer than the current plugin version', function (): void {
    $this->optionStore[Migrator::OPTION_INSTALLED_VERSION] = '1.0.0';
    $futureFired = false;

    $migrator = new Migrator('1.1.0', $this->logger);
    // A future migration registered but not yet relevant — must NOT fire.
    $migrator->addMigration('2.0.0', function () use (&$futureFired) {
        $futureFired = true;
    });

    $migrator->maybeMigrate();

    expect($futureFired)->toBeFalse();
    expect($this->optionStore[Migrator::OPTION_INSTALLED_VERSION])->toBe('1.1.0');
});

it('skips already-applied migrations on partial-jump runs', function (): void {
    // Stored=1.1.0, current=1.3.0, migrations=[1.1.0, 1.2.0, 1.3.0].
    // The 1.1.0 migration must NOT re-fire (already applied).
    $this->optionStore[Migrator::OPTION_INSTALLED_VERSION] = '1.1.0';
    $applied = [];

    $migrator = new Migrator('1.3.0', $this->logger);
    $migrator->addMigration('1.1.0', function () use (&$applied) {
        $applied[] = '1.1.0';
    });
    $migrator->addMigration('1.2.0', function () use (&$applied) {
        $applied[] = '1.2.0';
    });
    $migrator->addMigration('1.3.0', function () use (&$applied) {
        $applied[] = '1.3.0';
    });

    $migrator->maybeMigrate();

    expect($applied)->toBe(['1.2.0', '1.3.0']);
});

it('keeps stored version unchanged when a migration throws', function (): void {
    $this->optionStore[Migrator::OPTION_INSTALLED_VERSION] = '1.0.0';
    $applied = [];

    $migrator = new Migrator('1.3.0', $this->logger);
    $migrator->addMigration('1.1.0', function () use (&$applied) {
        $applied[] = '1.1.0';
    });
    $migrator->addMigration('1.2.0', function () {
        throw new RuntimeException('migration boom');
    });
    $migrator->addMigration('1.3.0', function () use (&$applied) {
        $applied[] = '1.3.0';  // should NOT fire after the throw above
    });

    $this->logger->expects('error')->withArgs(fn (string $msg) => str_contains($msg, '1.2.0'));

    $migrator->maybeMigrate();

    // 1.1.0 ran, 1.2.0 threw, 1.3.0 didn't run.
    expect($applied)->toBe(['1.1.0']);
    // Stored stays at original — next request will retry from 1.0.0.
    expect($this->optionStore[Migrator::OPTION_INSTALLED_VERSION])->toBe('1.0.0');
});

it('logs the successful migration summary at info level', function (): void {
    $this->optionStore[Migrator::OPTION_INSTALLED_VERSION] = '1.0.0';

    $migrator = new Migrator('1.5.0', $this->logger);
    $migrator->addMigration('1.5.0', fn () => null);

    $this->logger->expects('info')->withArgs(
        fn (string $msg) =>
        str_contains($msg, '1.0.0')
        && str_contains($msg, '1.5.0'),
    );

    $migrator->maybeMigrate();
});

it('handles a non-string stored option defensively (corrupted state)', function (): void {
    $this->optionStore[Migrator::OPTION_INSTALLED_VERSION] = 12345; // not a string

    $migrator = new Migrator('1.0.0', $this->logger);
    $migrator->maybeMigrate();

    // Treated as fresh install — stamps current.
    expect($this->optionStore[Migrator::OPTION_INSTALLED_VERSION])->toBe('1.0.0');
});
