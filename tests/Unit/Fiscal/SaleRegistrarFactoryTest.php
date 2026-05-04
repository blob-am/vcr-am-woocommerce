<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Tests\Unit\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Fiscal\SaleRegistrar;
use BlobSolutions\WooCommerceVcrAm\Fiscal\SaleRegistrarFactory;
use BlobSolutions\WooCommerceVcrAm\VcrClientFactory;
use Mockery;

beforeEach(function (): void {
    $this->config = Mockery::mock(Configuration::class);
    $this->clientFactory = Mockery::mock(VcrClientFactory::class);
});

it('builds a SaleRegistrar bound to the explicit api key and configured base URL', function (): void {
    $this->config->expects('baseUrl')->andReturn('https://vcr.am/api/v1');

    // We assert the call into VcrClientFactory carries the key/baseUrl
    // we asked for. The actual VcrClient is final and side-effect-free
    // until you call registerSale() on it, so a real instance is the
    // cheapest assertion target.
    $captured = null;
    $this->clientFactory->expects('create')->andReturnUsing(function (string $apiKey, ?string $baseUrl) use (&$captured) {
        $captured = ['apiKey' => $apiKey, 'baseUrl' => $baseUrl];

        return (new VcrClientFactory())->create($apiKey, $baseUrl);
    });

    $factory = new SaleRegistrarFactory($this->config, $this->clientFactory);
    $registrar = $factory->create('test-key');

    expect($registrar)->toBeInstanceOf(SaleRegistrar::class);
    expect($captured)->toBe([
        'apiKey' => 'test-key',
        'baseUrl' => 'https://vcr.am/api/v1',
    ]);
});

it('produces a fresh registrar on each call (no caching)', function (): void {
    $this->config->allows('baseUrl')->andReturn('https://vcr.am/api/v1');
    $this->clientFactory->allows('create')->andReturnUsing(
        fn (string $k, ?string $b) => (new VcrClientFactory())->create($k, $b),
    );

    $factory = new SaleRegistrarFactory($this->config, $this->clientFactory);

    $first = $factory->create('k');
    $second = $factory->create('k');

    expect($first)->not->toBe($second);
});
