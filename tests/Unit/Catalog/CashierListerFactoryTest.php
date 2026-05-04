<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Catalog\CashierLister;
use BlobSolutions\WooCommerceVcrAm\Catalog\CashierListerFactory;
use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\VcrClientFactory;

beforeEach(function (): void {
    $this->config = Mockery::mock(Configuration::class);
    $this->clientFactory = Mockery::mock(VcrClientFactory::class);
});

it('uses the configured base URL when no override is provided', function (): void {
    $this->config->expects('baseUrl')->andReturn('https://vcr.am/api/v1');

    $captured = null;
    $this->clientFactory->expects('create')->andReturnUsing(function (string $apiKey, ?string $baseUrl) use (&$captured) {
        $captured = ['apiKey' => $apiKey, 'baseUrl' => $baseUrl];

        return (new VcrClientFactory())->create($apiKey, $baseUrl);
    });

    $factory = new CashierListerFactory($this->config, $this->clientFactory);
    $lister = $factory->create('test-key');

    expect($lister)->toBeInstanceOf(CashierLister::class);
    expect($captured)->toBe([
        'apiKey' => 'test-key',
        'baseUrl' => 'https://vcr.am/api/v1',
    ]);
});

it('passes through an explicit baseUrlOverride for connection-test probes', function (): void {
    // Configuration.baseUrl() must NOT be consulted when an override is
    // provided — that's the whole point of the override (admin probes
    // a not-yet-saved URL via the AJAX test button).
    $this->config->expects('baseUrl')->never();

    $captured = null;
    $this->clientFactory->expects('create')->andReturnUsing(function (string $apiKey, ?string $baseUrl) use (&$captured) {
        $captured = ['apiKey' => $apiKey, 'baseUrl' => $baseUrl];

        return (new VcrClientFactory())->create($apiKey, $baseUrl);
    });

    $factory = new CashierListerFactory($this->config, $this->clientFactory);
    $factory->create('test-key', 'https://staging.vcr.am/api/v1');

    expect($captured['baseUrl'])->toBe('https://staging.vcr.am/api/v1');
});

it('produces a fresh lister on each call (no caching)', function (): void {
    $this->config->allows('baseUrl')->andReturn('https://vcr.am/api/v1');
    $this->clientFactory->allows('create')->andReturnUsing(
        fn (string $k, ?string $b) => (new VcrClientFactory())->create($k, $b),
    );

    $factory = new CashierListerFactory($this->config, $this->clientFactory);

    $first = $factory->create('k');
    $second = $factory->create('k');

    expect($first)->not->toBe($second);
});
