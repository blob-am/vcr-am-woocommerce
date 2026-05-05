<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Refund\RefundEligibility;

it('full() builds the eligible variant with empty reason', function (): void {
    $eligibility = RefundEligibility::full();

    expect($eligibility->isFullRefund)->toBeTrue()
        ->and($eligibility->reason)->toBe('');
});

it('ineligible() builds the not-eligible variant carrying the reason', function (): void {
    $eligibility = RefundEligibility::ineligible('parent not registered');

    expect($eligibility->isFullRefund)->toBeFalse()
        ->and($eligibility->reason)->toBe('parent not registered');
});
