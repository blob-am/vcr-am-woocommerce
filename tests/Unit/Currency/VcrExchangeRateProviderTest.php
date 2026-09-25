<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Tests\Unit\Currency;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Currency\Exception\ExchangeRateUnavailableException;
use BlobSolutions\WooCommerceVcrAm\Currency\VcrExchangeRateProvider;
use BlobSolutions\WooCommerceVcrAm\VcrClientFactory;
use Mockery;
use RuntimeException;

beforeEach(function (): void {
    $this->config = Mockery::mock(Configuration::class);
    $this->clientFactory = Mockery::mock(VcrClientFactory::class);
});

it('refuses without an API key and never reaches for a client', function (): void {
    $this->config->expects('apiKey')->andReturn(null);
    $this->clientFactory->expects('create')->never();

    $provider = new VcrExchangeRateProvider($this->config, $this->clientFactory);

    $provider->getRate('USD');
})->throws(ExchangeRateUnavailableException::class, 'no VCR API key is configured');

it('asks the VCR with the configured credentials and base URL', function (): void {
    $this->config->expects('apiKey')->andReturn('test-key');
    $this->config->expects('baseUrl')->andReturn('https://vcr.am/api/v1');

    // Capture the call and stop before the network: the SDK client is final,
    // so the factory boundary is the last seam a unit test can hold.
    $captured = null;
    $this->clientFactory->expects('create')->andReturnUsing(
        function (string $apiKey, ?string $baseUrl) use (&$captured) {
            $captured = ['apiKey' => $apiKey, 'baseUrl' => $baseUrl];

            throw new RuntimeException('stop before the network');
        },
    );

    $provider = new VcrExchangeRateProvider($this->config, $this->clientFactory);

    try {
        $provider->getRate('usd');
    } catch (ExchangeRateUnavailableException) {
        // asserted below
    }

    expect($captured)->toBe([
        'apiKey' => 'test-key',
        'baseUrl' => 'https://vcr.am/api/v1',
    ]);
});

it('reports any failure to obtain a rate as ExchangeRateUnavailable', function (): void {
    // RefundJob routes this exception to manual registration. Anything else
    // escaping here would surface as a fatal instead.
    $this->config->expects('apiKey')->andReturn('test-key');
    $this->config->expects('baseUrl')->andReturn('https://vcr.am/api/v1');
    $this->clientFactory->expects('create')->andThrow(new RuntimeException('connection refused'));

    $provider = new VcrExchangeRateProvider($this->config, $this->clientFactory);

    $provider->getRate('EUR');
})->throws(ExchangeRateUnavailableException::class, 'connection refused');

it('normalises the requested code before asking', function (): void {
    $this->config->expects('apiKey')->andReturn(null);

    $provider = new VcrExchangeRateProvider($this->config, $this->clientFactory);

    $provider->getRate('  usd  ');
})->throws(ExchangeRateUnavailableException::class, 'for USD');
