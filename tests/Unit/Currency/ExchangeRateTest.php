<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Currency\ExchangeRate;

it('exposes the constructor fields verbatim', function (): void {
    $rate = new ExchangeRate(
        iso: 'USD',
        amdPerUnit: 363.38,
        rateDate: '2026-09-24',
        ruleVersion: 'HO-234-N',
    );

    expect($rate->iso)->toBe('USD')
        ->and($rate->amdPerUnit)->toBe(363.38)
        ->and($rate->rateDate)->toBe('2026-09-24')
        ->and($rate->ruleVersion)->toBe('HO-234-N');
});

it('carries the rate already normalised to one unit, lot size and all', function (): void {
    // CBA quotes JPY per lot of 100; the VCR divides that out before it
    // answers, which is why nothing downstream needs to know about lots.
    $rate = new ExchangeRate(
        iso: 'JPY',
        amdPerUnit: 2.516,
        rateDate: '2026-09-24',
        ruleVersion: 'HO-234-N',
    );

    expect($rate->amdPerUnit)->toBe(2.516);
});
