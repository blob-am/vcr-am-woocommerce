<?php

declare(strict_types=1);

/**
 * Namespaced WooCommerce class stubs for the unit-test environment.
 *
 * Lives in a separate file from `wc-classes.php` because mixing
 * bracketed and unbracketed `namespace` declarations in one file is a
 * fatal PHP parse error. `wc-classes.php` declares everything in the
 * global namespace via plain `class Foo` blocks; this file declares
 * `Automattic\WooCommerce\Utilities\OrderUtil` (and any other future
 * namespaced WC stub) under a single top-of-file namespace.
 *
 * Loaded from `tests/bootstrap.php`.
 */

namespace Automattic\WooCommerce\Utilities;

if (! class_exists(__NAMESPACE__ . '\\OrderUtil', false)) {
    /**
     * Stub of WC's `OrderUtil`. We currently only model the static
     * predicate used by `SystemStatusReport::orderMetaTable()` —
     * `custom_orders_table_usage_is_enabled()`. The real class returns
     * the result of WC's HPOS feature flag; the stub uses a public
     * static toggle so individual test cases can flip the branch.
     *
     * Tests must reset `$hposEnabled = false` in their teardown to
     * avoid bleeding the toggle across cases (Pest groups can run in
     * arbitrary order under random-seed mode).
     */
    class OrderUtil
    {
        public static bool $hposEnabled = false;

        public static function custom_orders_table_usage_is_enabled(): bool
        {
            return self::$hposEnabled;
        }
    }
}
