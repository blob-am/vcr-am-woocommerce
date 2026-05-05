<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Currency\CurrencyConverter;
use BlobSolutions\WooCommerceVcrAm\Currency\Exception\ExchangeRateUnavailableException;
use BlobSolutions\WooCommerceVcrAm\Currency\ExchangeRate;
use BlobSolutions\WooCommerceVcrAm\Currency\ExchangeRateProvider;

/**
 * In-memory provider so converter tests stay free of HTTP / cache
 * concerns. Dual-purpose: configured rate OR configured exception.
 */
function fakeRateProvider(?ExchangeRate $rate = null, ?Throwable $throw = null): ExchangeRateProvider
{
    return new class ($rate, $throw) implements ExchangeRateProvider {
        public function __construct(private ?ExchangeRate $rate, private ?Throwable $throw)
        {
        }

        public function getRate(string $iso): ExchangeRate
        {
            if ($this->throw !== null) {
                throw $this->throw;
            }
            if ($this->rate === null) {
                throw new ExchangeRateUnavailableException('test: no rate stubbed');
            }

            return $this->rate;
        }
    };
}

it('returns AMD amounts unchanged without contacting the provider', function (): void {
    // Provider would throw if called — proves AMD is the fast-path.
    $provider = fakeRateProvider(throw: new RuntimeException('provider must not be called for AMD'));

    $amd = (new CurrencyConverter($provider))->toAmd(12345.0, 'AMD');

    expect($amd)->toBe(12345.0);
});

it('treats lowercase / whitespace ISO as the same currency', function (): void {
    $provider = fakeRateProvider(throw: new RuntimeException('provider must not be called for AMD'));

    expect((new CurrencyConverter($provider))->toAmd(50.0, '  amd  '))
        ->toBe(50.0);
});

it('multiplies by unitToAmd for non-AMD currencies', function (): void {
    // 1 USD = 388.5 AMD ; 100 USD = 38850 AMD
    $rate = new ExchangeRate(iso: 'USD', rate: 388.5, amount: 1.0, publishedAt: 0);
    $provider = fakeRateProvider(rate: $rate);

    expect((new CurrencyConverter($provider))->toAmd(100.0, 'USD'))
        ->toBe(38850.0);
});

it('handles multi-unit-lot rates (CBA quotes some currencies per 100/1000)', function (): void {
    // 100 JPY = 251.6 AMD ; 50 JPY = 125.8 AMD
    $rate = new ExchangeRate(iso: 'JPY', rate: 251.6, amount: 100.0, publishedAt: 0);
    $provider = fakeRateProvider(rate: $rate);

    expect((new CurrencyConverter($provider))->toAmd(50.0, 'JPY'))
        ->toBe(125.8);
});

it('rounds the converted amount to 2 decimal places (qopiq precision)', function (): void {
    // 1 EUR = 423.7331 AMD ; 7.50 EUR = 3177.99825 AMD -> rounded to 3177.998 -> ...
    // round(7.5 * 423.7331, 2) = round(3177.99825, 2) = 3177.99
    // Wait, 3177.99825 -> rounds to 3178.00 by half-away-from-zero. Let's verify.
    // Actually PHP round() with default half-away-from-zero: 3177.99825 -> 3178.00? No.
    // round(3177.99825, 2) = 3178.00? Let's think: third decimal is 8 (>=5), so round up.
    // 3177.99 + 0.00825 -> rounds to 3178.00. Hmm, but actually 0.99825 -> 1.00 -> total 3178.00.
    $rate = new ExchangeRate(iso: 'EUR', rate: 423.7331, amount: 1.0, publishedAt: 0);
    $provider = fakeRateProvider(rate: $rate);

    $amd = (new CurrencyConverter($provider))->toAmd(7.50, 'EUR');

    // round(7.5 * 423.7331, 2) = round(3177.99825, 2) = 3178.00
    expect($amd)->toBe(3178.00);
});

it('propagates ExchangeRateUnavailableException from the provider', function (): void {
    $provider = fakeRateProvider(throw: new ExchangeRateUnavailableException('CBA timeout'));

    expect(fn () => (new CurrencyConverter($provider))->toAmd(1.0, 'USD'))
        ->toThrow(ExchangeRateUnavailableException::class, 'CBA timeout');
});

it('exposes HOME_CURRENCY as AMD constant', function (): void {
    expect(CurrencyConverter::HOME_CURRENCY)->toBe('AMD');
});
