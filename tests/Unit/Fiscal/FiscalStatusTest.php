<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Tests\Unit\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;

it('treats Pending as the only non-terminal state', function (): void {
    expect(FiscalStatus::Pending->isTerminal())->toBeFalse()
        ->and(FiscalStatus::Success->isTerminal())->toBeTrue()
        ->and(FiscalStatus::Failed->isTerminal())->toBeTrue()
        ->and(FiscalStatus::ManualRequired->isTerminal())->toBeTrue();
});

it('round-trips through tryFrom for every case (no silent gaps)', function (): void {
    foreach (FiscalStatus::cases() as $case) {
        expect(FiscalStatus::tryFrom($case->value))->toBe($case);
    }
});

it('returns null from tryFrom for an unknown wire value', function (): void {
    expect(FiscalStatus::tryFrom('exploded'))->toBeNull();
});
