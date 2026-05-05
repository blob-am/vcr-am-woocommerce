<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundEligibilityChecker;

/**
 * Build a fully-stubbed parent order + refund for eligibility tests.
 *
 * @param array{
 *     parent_status?: ?FiscalStatus,
 *     sale_id?: ?int,
 *     refund_count?: int,
 *     parent_total?: string,
 *     refund_amount?: string,
 * } $opts
 * @return array{0: \BlobSolutions\WooCommerceVcrAm\Refund\RefundEligibilityChecker, 1: WC_Order_Refund, 2: WC_Order}
 */
function makeEligibilitySetup(array $opts = []): array
{
    $parentStatus = array_key_exists('parent_status', $opts) ? $opts['parent_status'] : FiscalStatus::Success;
    $saleId = array_key_exists('sale_id', $opts) ? $opts['sale_id'] : 12345;
    $refundCount = $opts['refund_count'] ?? 1;
    $parentTotal = $opts['parent_total'] ?? '100.00';
    $refundAmount = $opts['refund_amount'] ?? '100.00';

    $fiscalMeta = Mockery::mock(FiscalStatusMeta::class);
    $fiscalMeta->allows('status')->andReturn($parentStatus);
    $fiscalMeta->allows('saleId')->andReturn($saleId);

    $parent = Mockery::mock(WC_Order::class);
    $parent->allows('get_total')->andReturn($parentTotal);

    // Build $refundCount refund mocks; first one is the one under test.
    $refunds = [];
    for ($i = 0; $i < $refundCount; $i++) {
        $r = Mockery::mock(WC_Order_Refund::class);
        $r->allows('get_amount')->andReturn($i === 0 ? $refundAmount : '0');
        $refunds[] = $r;
    }

    $parent->allows('get_refunds')->andReturn($refunds);

    $checker = new RefundEligibilityChecker($fiscalMeta);

    return [$checker, $refunds[0], $parent];
}

it('Eligible: parent Success + saleId + first refund + amount equals total', function (): void {
    [$checker, $refund, $parent] = makeEligibilitySetup();

    $result = $checker->check($refund, $parent);

    expect($result->isFullRefund)->toBeTrue();
});

it('Ineligible when parent is Pending', function (): void {
    [$checker, $refund, $parent] = makeEligibilitySetup(['parent_status' => FiscalStatus::Pending]);

    $result = $checker->check($refund, $parent);

    expect($result->isFullRefund)->toBeFalse()
        ->and($result->reason)->toContain('pending');
});

it('Ineligible when parent is Failed', function (): void {
    [$checker, $refund, $parent] = makeEligibilitySetup(['parent_status' => FiscalStatus::Failed]);

    $result = $checker->check($refund, $parent);

    expect($result->isFullRefund)->toBeFalse()
        ->and($result->reason)->toContain('failed');
});

it('Ineligible when parent has no fiscal status (never enqueued)', function (): void {
    [$checker, $refund, $parent] = makeEligibilitySetup(['parent_status' => null]);

    $result = $checker->check($refund, $parent);

    expect($result->isFullRefund)->toBeFalse()
        ->and($result->reason)->toContain('not_enqueued');
});

it('Ineligible when parent is Success but saleId is missing (corrupted state)', function (): void {
    [$checker, $refund, $parent] = makeEligibilitySetup(['sale_id' => null]);

    $result = $checker->check($refund, $parent);

    expect($result->isFullRefund)->toBeFalse()
        ->and($result->reason)->toContain('no SRC saleId');
});

it('Ineligible when this is the SECOND refund (cumulative refunds not supported)', function (): void {
    [$checker, $refund, $parent] = makeEligibilitySetup(['refund_count' => 2]);

    $result = $checker->check($refund, $parent);

    expect($result->isFullRefund)->toBeFalse()
        ->and($result->reason)->toContain('already has another refund');
});

it('Ineligible when refund amount is less than parent total (partial)', function (): void {
    [$checker, $refund, $parent] = makeEligibilitySetup([
        'parent_total' => '100.00',
        'refund_amount' => '40.00',
    ]);

    $result = $checker->check($refund, $parent);

    expect($result->isFullRefund)->toBeFalse()
        ->and($result->reason)->toContain('does not equal');
});

it('Eligible despite trailing-zero precision drift between strings', function (): void {
    // "100" vs "100.00" — same float value, different string repr.
    // Should not be rejected; the AMOUNT_EPSILON tolerance covers this.
    [$checker, $refund, $parent] = makeEligibilitySetup([
        'parent_total' => '100',
        'refund_amount' => '100.00',
    ]);

    $result = $checker->check($refund, $parent);

    expect($result->isFullRefund)->toBeTrue();
});

it('Ineligible when refund amount exceeds total by more than half a cent', function (): void {
    // Pathological case — admin somehow refunded 100.01 on a 100.00
    // order. Beyond epsilon, so we bail rather than auto-register
    // (could be a data-entry bug we don't want to silently mask).
    [$checker, $refund, $parent] = makeEligibilitySetup([
        'parent_total' => '100.00',
        'refund_amount' => '100.50',
    ]);

    $result = $checker->check($refund, $parent);

    expect($result->isFullRefund)->toBeFalse();
});
