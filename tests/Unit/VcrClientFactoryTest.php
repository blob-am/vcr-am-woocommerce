<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\VcrClientFactory;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\VcrClient;

it('produces a VcrClient using the SDK default base URL when none is given', function (): void {
    $factory = new VcrClientFactory();

    $client = $factory->create(apiKey: 'test-key');

    expect($client)->toBeInstanceOf(VcrClient::class)
        ->and($client->baseUrl)->toBe(VcrClient::DEFAULT_BASE_URL);
});

it('honours an explicit base URL override', function (): void {
    $factory = new VcrClientFactory();

    $client = $factory->create(
        apiKey: 'test-key',
        baseUrl: 'https://staging.vcr.am/api/v1',
    );

    expect($client->baseUrl)->toBe('https://staging.vcr.am/api/v1');
});

it('exposes timeout constants matching documented production defaults', function (): void {
    // These are part of the cross-system contract: ConnectionTester's
    // JS asset reads DEFAULT_TIMEOUT_SECONDS to size its AbortController
    // ceiling. If you change them, also update the localised i18n
    // budget in ConnectionTester and document the rollout.
    expect(VcrClientFactory::DEFAULT_TIMEOUT_SECONDS)->toBe(30)
        ->and(VcrClientFactory::DEFAULT_CONNECT_TIMEOUT_SECONDS)->toBe(10);
});

it('builds a fresh VcrClient instance on each call (no internal caching)', function (): void {
    $factory = new VcrClientFactory();

    $first = $factory->create(apiKey: 'a');
    $second = $factory->create(apiKey: 'a');

    // Both functional, distinct instances — important for the per-job
    // construction pattern used by SaleRegistrarFactory.
    expect($first)->not->toBe($second);
});
