<?php

declare(strict_types=1);

/**
 * The E2E fixtures under `tests/E2E/scripts/` are run through
 * `wp eval-file`, which wraps the file body in `eval()` -- where
 * `declare(strict_types=1)` is a parse error ("must be the very first
 * statement"), not a hint. A fixture carrying one fails every test that uses
 * it, and WordPress reports it as "There has been a critical error on this
 * website", which sends you looking at the plugin.
 *
 * `pint.json` excludes that directory, but an explicit path argument
 * (`pint tests/`) overrides the exclude list and inserts the declaration into
 * all twenty of them at once. That is how this cost a full E2E run: nothing
 * else notices, because the fixtures are not loaded by the unit suite and they
 * pass every linter. This test is the fast way to be told.
 */
it('leaves the E2E fixtures without a strict_types declaration', function (): void {
    $dir = dirname(__DIR__) . '/E2E/scripts';
    $fixtures = glob($dir . '/*.php');

    expect($fixtures)->toBeArray()->not->toBeEmpty();

    // The statement, not the word: every one of these files carries a docblock
    // line saying the declaration is deliberately absent, and matching that
    // would make this test fail on the comment that explains it.
    $offenders = [];
    foreach ($fixtures as $fixture) {
        $body = file_get_contents($fixture);
        if (is_string($body) && preg_match('/^\s*declare\s*\(\s*strict_types/m', $body) === 1) {
            $offenders[] = basename($fixture);
        }
    }

    expect($offenders)->toBe([]);
});
