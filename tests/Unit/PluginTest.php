<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalQueue;
use BlobSolutions\WooCommerceVcrAm\Plugin;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    // Plugin::onPluginsLoaded reaches into wp_salt() via KeyStore wiring.
    Functions\when('wp_salt')->justReturn(str_repeat('x', 64));
});

it('registers the bootstrap hooks exactly once on boot', function (): void {
    Actions\expectAdded('before_woocommerce_init')->once();
    Actions\expectAdded('plugins_loaded')->once();

    (new Plugin('/tmp/plugin.php', '0.1.0'))->boot();
});

it('is a no-op on a second boot call (idempotent)', function (): void {
    Actions\expectAdded('before_woocommerce_init')->once();
    Actions\expectAdded('plugins_loaded')->once();

    $plugin = new Plugin('/tmp/plugin.php', '0.1.0');
    $plugin->boot();
    $plugin->boot();
});

it('exposes the plugin file path and version constructor args', function (): void {
    $plugin = new Plugin('/tmp/plugin.php', '1.2.3');

    expect($plugin->getPluginFile())->toBe('/tmp/plugin.php');
    expect($plugin->getVersion())->toBe('1.2.3');
});

it('shows an admin notice on plugins_loaded when WooCommerce is missing', function (): void {
    // class_exists('\WooCommerce') will be false in unit-test scope —
    // the WC stubs are loaded but the WC bootstrap class isn't.
    Actions\expectAdded('admin_notices')->once();

    (new Plugin('/tmp/plugin.php', '0.1.0'))->onPluginsLoaded();
});

it('emits the WooCommerce-missing notice with an actionable message', function (): void {
    Functions\when('esc_html')->returnArg();

    ob_start();
    (new Plugin('/tmp/plugin.php', '0.1.0'))->showWooCommerceMissingNotice();
    $output = (string) ob_get_clean();

    expect($output)
        ->toContain('notice notice-error')
        ->toContain('requires WooCommerce');
});

it('declareWooCommerceCompatibility is a safe no-op when FeaturesUtil is absent', function (): void {
    // The class doesn't exist in the unit-test environment, so the
    // method should bail quietly rather than fatal-error.
    $plugin = new Plugin('/tmp/plugin.php', '0.1.0');

    // Just calling it shouldn't throw — no expectation framework hook
    // needed. The lack of class_exists short-circuits the whole body.
    $plugin->declareWooCommerceCompatibility();

    expect(true)->toBeTrue();
});

it('boot wires the before_woocommerce_init hook to declareWooCommerceCompatibility', function (): void {
    // Pin the callable shape so a refactor that accidentally points the
    // hook at a typo'd method name fails loudly here.
    Actions\expectAdded('before_woocommerce_init')
        ->once()
        ->whenHappen(function ($callback): void {
            expect($callback)->toBeArray()
                ->and($callback[1])->toBe('declareWooCommerceCompatibility');
        });
    Actions\expectAdded('plugins_loaded')->once();

    (new Plugin('/tmp/plugin.php', '0.1.0'))->boot();
});

it('exposes the FiscalQueue action hook constant for downstream wiring', function (): void {
    // Phase 3b's contract: the plugin's fiscal flow listens on this
    // hook name. Pinning it as a test prevents an accidental rename
    // from silently dropping the queue handler off the schedule.
    expect(FiscalQueue::ACTION_HOOK)->toBe('vcr_fiscalize_order')
        ->and(FiscalQueue::ACTION_GROUP)->toBe('vcr');
});
