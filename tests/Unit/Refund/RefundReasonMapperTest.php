<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Refund\RefundReasonMapper;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\RefundReason;
use Brain\Monkey\Filters;

function refundWithReason(string $reasonText): WC_Order_Refund
{
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_reason')->andReturn($reasonText);

    return $refund;
}

it('defaults to CustomerRequest for empty reason text', function (): void {
    $reason = (new RefundReasonMapper())->map(refundWithReason(''));

    expect($reason)->toBe(RefundReason::CustomerRequest);
});

it('defaults to CustomerRequest for arbitrary text with no keyword match', function (): void {
    $reason = (new RefundReasonMapper())->map(refundWithReason('client changed their mind'));

    expect($reason)->toBe(RefundReason::CustomerRequest);
});

it('classifies "defective" / "broken" / "damaged" as DefectiveGoods', function (): void {
    $mapper = new RefundReasonMapper();

    expect($mapper->map(refundWithReason('item is defective')))->toBe(RefundReason::DefectiveGoods)
        ->and($mapper->map(refundWithReason('arrived broken')))->toBe(RefundReason::DefectiveGoods)
        ->and($mapper->map(refundWithReason('product was damaged in transit')))->toBe(RefundReason::DefectiveGoods);
});

it('classifies Russian "брак" / "сломан" as DefectiveGoods', function (): void {
    $mapper = new RefundReasonMapper();

    expect($mapper->map(refundWithReason('заводской брак')))->toBe(RefundReason::DefectiveGoods)
        ->and($mapper->map(refundWithReason('пришёл сломанным')))->toBe(RefundReason::DefectiveGoods);
});

it('classifies "wrong" / "incorrect" as WrongGoods', function (): void {
    $mapper = new RefundReasonMapper();

    expect($mapper->map(refundWithReason('wrong color shipped')))->toBe(RefundReason::WrongGoods)
        ->and($mapper->map(refundWithReason('incorrect size')))->toBe(RefundReason::WrongGoods);
});

it('classifies "duplicate" as DuplicateReceipt', function (): void {
    $mapper = new RefundReasonMapper();

    expect($mapper->map(refundWithReason('duplicate charge from gateway')))->toBe(RefundReason::DuplicateReceipt);
});

it('classifies "cashier error" as CashierError', function (): void {
    $mapper = new RefundReasonMapper();

    expect($mapper->map(refundWithReason('cashier error during checkout')))->toBe(RefundReason::CashierError);
});

it('is case-insensitive', function (): void {
    $mapper = new RefundReasonMapper();

    expect($mapper->map(refundWithReason('DEFECTIVE PRODUCT')))->toBe(RefundReason::DefectiveGoods)
        ->and($mapper->map(refundWithReason('Wrong Item')))->toBe(RefundReason::WrongGoods);
});

it('honours the vcr_refund_reason filter to override classification', function (): void {
    Filters\expectApplied('vcr_refund_reason')
        ->andReturn(RefundReason::Other);

    $reason = (new RefundReasonMapper())->map(refundWithReason('defective'));

    expect($reason)->toBe(RefundReason::Other);
});

it('ignores filter return values that are not RefundReason instances', function (): void {
    // Defensive — a misbehaving filter shouldn't break the SDK call.
    Filters\expectApplied('vcr_refund_reason')->andReturn('not-a-reason');

    $reason = (new RefundReasonMapper())->map(refundWithReason('defective'));

    expect($reason)->toBe(RefundReason::DefectiveGoods);
});
