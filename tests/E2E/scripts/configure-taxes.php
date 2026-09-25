<?php

/**
 * Test fixture: turn WooCommerce tax calculation on or off, with one
 * VAT-inclusive rate.
 *
 * Usage: wp eval-file configure-taxes.php <on|off>
 *
 * Prices include tax, which is how an Armenian store is set up: VAT is
 * extracted from the displayed price, never added to it (Tax Code art. 63).
 * So an order's total does not change when tax is switched on — what changes
 * is that each line now carries a tax portion, and the plugin has to fold it
 * back in. `ItemBuilder::unitPriceInclusive()` adds `get_total_tax()` to
 * `get_total()` for exactly that reason; without the fold the receipt would
 * report the ex-VAT figure and under-report the sale.
 *
 * Idempotent: the rate is created once and reused.
 *
 * No `declare(strict_types=1)` — see create-paid-order.php.
 */

if (! class_exists('WC_Tax')) {
    fwrite(STDERR, "WooCommerce isn't loaded — bailing.\n");
    exit(1);
}

$mode = isset($args[0]) ? (string) $args[0] : '';

if (! in_array($mode, ['on', 'off'], true)) {
    fwrite(STDERR, "Usage: wp eval-file configure-taxes.php <on|off>\n");
    exit(1);
}

if ($mode === 'off') {
    update_option('woocommerce_calc_taxes', 'no');
    echo "off\n";
    exit(0);
}

update_option('woocommerce_calc_taxes', 'yes');
update_option('woocommerce_prices_include_tax', 'yes');
update_option('woocommerce_tax_based_on', 'base');

$existing = WC_Tax::get_rates_for_tax_class('');
$hasRate = false;

foreach ($existing as $rate) {
    if ((string) $rate->tax_rate_name === 'E2E VAT') {
        $hasRate = true;
        break;
    }
}

if (! $hasRate) {
    WC_Tax::_insert_tax_rate([
        'tax_rate_country' => '',
        'tax_rate_state' => '',
        'tax_rate' => '20.0000',
        'tax_rate_name' => 'E2E VAT',
        'tax_rate_priority' => 1,
        'tax_rate_compound' => 0,
        'tax_rate_shipping' => 1,
        'tax_rate_order' => 0,
        'tax_rate_class' => '',
    ]);
}

echo "on\n";
