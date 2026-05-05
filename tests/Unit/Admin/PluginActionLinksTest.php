<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Admin\PluginActionLinks;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('plugin_basename')->alias(fn (string $file): string => 'vcr-am-woocommerce/' . basename($file));
    Functions\when('admin_url')->alias(fn (string $path = '') => 'https://example.test/wp-admin/' . $path);
});

it('registers the per-plugin action_links filter using the plugin basename', function (): void {
    Filters\expectAdded('plugin_action_links_vcr-am-woocommerce/vcr-am-fiscal-receipts.php')->once();

    (new PluginActionLinks('/path/to/vcr-am-fiscal-receipts.php'))->register();
});

it('prepends a Settings link pointing at our WC settings tab', function (): void {
    $links = (new PluginActionLinks('/path/to/vcr-am-fiscal-receipts.php'))->addLinks([
        'deactivate' => '<a href="#">Deactivate</a>',
    ]);

    // Settings goes first — convention puts the most-used action leftmost.
    $first = reset($links);
    expect($first)
        ->toContain('Settings')
        ->toContain('admin.php?page=wc-settings&tab=vcr');
});

it('appends a Docs link after WP\'s built-in actions', function (): void {
    $links = (new PluginActionLinks('/path/to/vcr-am-fiscal-receipts.php'))->addLinks([
        'deactivate' => '<a href="#">Deactivate</a>',
    ]);

    $last = end($links);
    expect($last)
        ->toContain('Docs')
        ->toContain('target="_blank"')
        ->toContain('rel="noopener noreferrer"');
});

it('preserves WP-supplied links between Settings and Docs', function (): void {
    $links = (new PluginActionLinks('/path/to/vcr-am-fiscal-receipts.php'))->addLinks([
        'deactivate' => '<a href="#deact">Deactivate</a>',
        'activate' => '<a href="#act">Activate</a>',
    ]);

    expect(array_keys($links))->toBe(['settings', 'deactivate', 'activate', 'docs']);
});

it('is defensive when WP passes a non-array (theoretical legacy callers)', function (): void {
    /** @phpstan-ignore-next-line — verifying defensive narrowing */
    $links = (new PluginActionLinks('/path/to/vcr-am-fiscal-receipts.php'))->addLinks(null);

    expect(array_keys($links))->toBe(['settings', 'docs']);
});
