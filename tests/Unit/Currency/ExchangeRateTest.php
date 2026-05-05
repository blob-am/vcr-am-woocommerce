<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Currency\ExchangeRate;

it('exposes the constructor fields verbatim', function (): void {
    $rate = new ExchangeRate(iso: 'USD', rate: 388.5, amount: 1.0, publishedAt: 1735689600);

    expect($rate->iso)->toBe('USD')
        ->and($rate->rate)->toBe(388.5)
        ->and($rate->amount)->toBe(1.0)
        ->and($rate->publishedAt)->toBe(1735689600);
});

it('unitToAmd divides rate by amount for 1-unit currencies', function (): void {
    // USD: 1 USD = 388.5 AMD; rate=388.5, amount=1
    $rate = new ExchangeRate(iso: 'USD', rate: 388.5, amount: 1.0, publishedAt: 0);

    expect($rate->unitToAmd())->toBe(388.5);
});

it('unitToAmd handles multi-unit lots correctly', function (): void {
    // CBA quotes JPY per 100: rate=251.6, amount=100 means 1 JPY = 2.516 AMD.
    $rate = new ExchangeRate(iso: 'JPY', rate: 251.6, amount: 100.0, publishedAt: 0);

    expect($rate->unitToAmd())->toBe(2.516);
});
