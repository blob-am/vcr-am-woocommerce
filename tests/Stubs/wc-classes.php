<?php

declare(strict_types=1);

/**
 * Minimal runtime stubs of the WooCommerce classes we mock in unit tests.
 *
 * Why this file exists:
 *
 *   - PHPStan reads `vendor/php-stubs/woocommerce-stubs/`, which gives
 *     static type info but is *not* loaded at runtime — the class
 *     declarations there are wrapped in `if (!class_exists())` blocks
 *     guarded by IDE tooling.
 *   - Mockery needs the actual class to exist at runtime to create a mock
 *     that satisfies a `WC_Order $order` parameter type hint.
 *
 * The stubs declare only the methods we exercise, with the same
 * signatures as upstream WooCommerce. They have empty bodies because
 * Mockery overrides every call site we care about.
 *
 * This file is loaded only when the real WC classes are absent (i.e. in
 * the unit test environment). In a wp-env / Playwright integration suite
 * (Phase 4), the real WooCommerce will be present and these stubs are
 * skipped — keeps the path forward clean.
 */

if (! class_exists('WC_Data', false)) {
    abstract class WC_Data
    {
        /**
         * @return mixed
         */
        public function get_meta(string $key = '', bool $single = true, string $context = 'view')
        {
            return '';
        }

        /**
         * @param mixed $value
         */
        public function update_meta_data(string $key, $value, int|string $meta_id = 0): void
        {
        }

        public function delete_meta_data(string $key): void
        {
        }

        public function save(): int
        {
            return 0;
        }
    }
}

if (! class_exists('WC_Order', false)) {
    class WC_Order extends WC_Data
    {
        public function get_id(): int
        {
            return 0;
        }

        /**
         * @return array<int, WC_Order_Item>
         */
        public function get_items(string|array $types = 'line_item'): array
        {
            return [];
        }

        public function get_total(string $context = 'view'): string
        {
            return '0';
        }

        public function get_shipping_total(string $context = 'view'): string
        {
            return '0';
        }

        public function get_shipping_tax(string $context = 'view'): string
        {
            return '0';
        }

        public function get_payment_method(string $context = 'view'): string
        {
            return '';
        }

        public function get_type(): string
        {
            return 'shop_order';
        }

        public function add_order_note(string $note, int $is_customer_note = 0, bool $added_by_user = false): int
        {
            return 0;
        }

        /**
         * @return array<int, WC_Order_Refund>
         */
        public function get_refunds(): array
        {
            return [];
        }

        public function get_remaining_refund_amount(): float
        {
            return 0.0;
        }
    }
}

if (! class_exists('WC_Order_Item', false)) {
    abstract class WC_Order_Item extends WC_Data
    {
        public function get_name(string $context = 'view'): string
        {
            return '';
        }
    }
}

if (! class_exists('WC_Order_Item_Product', false)) {
    class WC_Order_Item_Product extends WC_Order_Item
    {
        /**
         * @return WC_Product|bool
         */
        public function get_product()
        {
            return false;
        }

        public function get_quantity(string $context = 'view'): int|float
        {
            return 0;
        }

        public function get_total(string $context = 'view'): string
        {
            return '0';
        }

        public function get_total_tax(string $context = 'view'): string
        {
            return '0';
        }
    }
}

if (! class_exists('WC_Order_Item_Fee', false)) {
    class WC_Order_Item_Fee extends WC_Order_Item
    {
        public function get_total(string $context = 'view'): string
        {
            return '0';
        }

        public function get_total_tax(string $context = 'view'): string
        {
            return '0';
        }
    }
}

if (! class_exists('WC_Product', false)) {
    class WC_Product extends WC_Data
    {
        public function get_sku(string $context = 'view'): string
        {
            return '';
        }

        public function get_name(string $context = 'view'): string
        {
            return '';
        }
    }
}

if (! class_exists('WC_Order_Refund', false)) {
    /**
     * Real WooCommerce ships this as `class WC_Order_Refund extends WC_Abstract_Order`
     * — NOT a subclass of `WC_Order`. We mirror that shape so unit tests
     * catch any code path that wrongly assumes refunds satisfy `instanceof
     * WC_Order` (the rigor lesson from Phase 3b).
     *
     * In our stub, both `WC_Order` and `WC_Order_Refund` extend `WC_Data`
     * directly (we don't model `WC_Abstract_Order` — that intermediate
     * abstract class adds nothing the tests can exercise). The
     * non-WC_Order pedigree is what matters.
     */
    class WC_Order_Refund extends WC_Data
    {
        public function get_id(): int
        {
            return 0;
        }

        public function get_type(): string
        {
            return 'shop_order_refund';
        }

        /**
         * @return array<int, WC_Order_Item>
         */
        public function get_items(string|array $types = 'line_item'): array
        {
            return [];
        }

        /** Refund total — stored as `_refund_amount` post-meta on real WC. */
        public function get_amount(string $context = 'view'): string
        {
            return '0';
        }

        /** Free-text reason an admin entered when creating the refund. */
        public function get_reason(string $context = 'view'): string
        {
            return '';
        }

        public function get_parent_id(string $context = 'view'): int
        {
            return 0;
        }
    }
}

if (! class_exists('WP_Post', false)) {
    /**
     * Bare-bones stub of the WP_Post class. Used by OrderMetaBox tests
     * to exercise the legacy (non-HPOS) order edit screen path where
     * the meta box callback receives a WP_Post instead of a WC_Order.
     */
    class WP_Post
    {
        public function __construct(
            public int $ID = 0,
            public string $post_type = 'shop_order',
        ) {
        }
    }
}

if (! class_exists('WC_Settings_Page', false)) {
    /**
     * Bare-bones stub of WC's settings-page base class. Real WC fires a
     * couple of actions in its constructor; the stub stays inert so unit
     * tests can instantiate VcrSettingsTab without a hooks frenzy.
     */
    class WC_Settings_Page
    {
        public string $id = '';

        public string $label = '';

        public function __construct()
        {
        }
    }
}
