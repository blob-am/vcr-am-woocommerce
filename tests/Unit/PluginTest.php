<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Plugin;
use Brain\Monkey\Actions;

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
