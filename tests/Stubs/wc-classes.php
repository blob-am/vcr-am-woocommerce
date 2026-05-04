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
        public function get_items(string $type = 'line_item'): array
        {
            return [];
        }

        public function get_total(string $context = 'view'): string
        {
            return '0';
        }

        public function get_payment_method(string $context = 'view'): string
        {
            return '';
        }

        public function add_order_note(string $note, int $is_customer_note = 0, bool $added_by_user = false): int
        {
            return 0;
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
    class WC_Order_Refund extends WC_Order
    {
    }
}
