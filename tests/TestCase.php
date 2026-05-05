<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test case for unit tests that need to call code which references WP
 * core / WC functions. Brain Monkey installs no-op stubs for the entire
 * `add_action` / `add_filter` / `apply_filters` / `do_action` family on
 * `setUp` and tears them down on `tearDown`, so tests can exercise plugin
 * bootstrap without booting WordPress.
 *
 * Tests that don't touch WP at all (pure data structures, validation
 * helpers, etc.) can skip extending this and use Pest's plain function
 * style.
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // WP translation helpers — Brain Monkey doesn't pre-stub these.
        // Echo the original string so assertions on output remain stable
        // and tests don't blow up with "undefined function __()".
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();

        // `wc_get_logger` is referenced by Logging\Logger and indirectly
        // by KeyStore::logFailure on decryption failure. Stubbed to a
        // no-op object so any code that triggers a log line in tests
        // (decryption errors, fiscal/refund job retries) doesn't crash
        // with "undefined function wc_get_logger". Tests that need to
        // assert on log routing override this with a Mockery double.
        Functions\when('wc_get_logger')->justReturn(new class () {
            public function log(string $level, string $message, array $context): void
            {
            }
        });

        // `get_user_by` is used by Privacy\PrivacyHandler::ordersForEmail
        // for the customer_user lookup. Default to "no user found" so
        // tests that don't care about the registered-user branch don't
        // fail with "undefined function". Tests that exercise the
        // user-lookup branch override with a (object) ['ID' => N] return.
        Functions\when('get_user_by')->justReturn(false);

        // wp_unslash strips magic-quote escapes from superglobal values.
        // Brain Monkey doesn't pre-stub it. The unit-test environment
        // never has magic quotes active, so a pass-through is the
        // correct production-equivalent behaviour.
        Functions\when('wp_unslash')->returnArg(1);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}
