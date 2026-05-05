<?php

declare(strict_types=1);

/**
 * Test-suite bootstrap.
 *
 * Loads BOTH the main composer autoloader (PSR-4 for our own code, dev
 * deps like Pest / Brain Monkey, and PSR contracts left unprefixed for
 * interop) and the Strauss-prefixed autoloader (SDK + Guzzle + php-http
 * under our private namespace). Production loads them the same way from
 * the plugin entry; mirroring that here keeps tests honest about what's
 * actually visible at runtime.
 *
 * Defines the small set of WordPress constants that our `src/` code
 * references but Brain Monkey doesn't stub by default. These aren't
 * function calls (which Brain Monkey *can* override), so we declare them
 * once at suite startup. Brain Monkey then handles the function-level
 * mocking on a per-test basis.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$prefixedAutoload = __DIR__ . '/../vendor-prefixed/autoload.php';
if (! is_file($prefixedAutoload)) {
    fwrite(
        STDERR,
        "tests/bootstrap.php: vendor-prefixed/autoload.php missing. Run `composer install` (Strauss runs as post-install-cmd).\n",
    );
    exit(1);
}
require_once $prefixedAutoload;

// WP time constants — defined as plain integers in wp-includes/default-constants.php.
defined('MINUTE_IN_SECONDS') || define('MINUTE_IN_SECONDS', 60);
defined('HOUR_IN_SECONDS') || define('HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS);
defined('DAY_IN_SECONDS') || define('DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS);
defined('WEEK_IN_SECONDS') || define('WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS);

// $wpdb output-format constants — defined in wp-includes/wp-db.php.
// Required when src/ code calls $wpdb->get_results($sql, ARRAY_A).
defined('OBJECT') || define('OBJECT', 'OBJECT');
defined('OBJECT_K') || define('OBJECT_K', 'OBJECT_K');
defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');
defined('ARRAY_N') || define('ARRAY_N', 'ARRAY_N');

// Minimal WC class stubs so Mockery can satisfy `WC_Order` etc. type
// hints in unit tests. Skipped automatically when real WC is loaded
// (e.g. in wp-env-backed integration tests in Phase 4).
require_once __DIR__ . '/Stubs/wc-classes.php';
require_once __DIR__ . '/Stubs/wc-namespaced-classes.php';
