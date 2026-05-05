<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Fiscal\CashPaymentResolver;
use BlobSolutions\WooCommerceVcrAm\Fiscal\Exception\FiscalBuildException;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundPaymentMapper;

/**
 * @return array{0: WC_Order, 1: WC_Order_Refund}
 */
function refundPair(string $paymentMethod = 'stripe', string $refundAmount = '50.00'): array
{
    $parent = Mockery::mock(WC_Order::class);
    $parent->allows('get_payment_method')->andReturn($paymentMethod);

    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_amount')->andReturn($refundAmount);
    $refund->allows('get_id')->andReturn(99);

    return [$parent, $refund];
}

it('maps a cash-tender parent to RefundAmount(cash)', function (): void {
    [$parent, $refund] = refundPair(paymentMethod: 'cod', refundAmount: '50.00');

    $amount = (new RefundPaymentMapper())->map($parent, $refund);

    expect($amount->cash)->toBe('50')
        ->and($amount->nonCash)->toBeNull();
});

it('maps a non-cash parent to RefundAmount(nonCash)', function (): void {
    [$parent, $refund] = refundPair(paymentMethod: 'stripe', refundAmount: '99.99');

    $amount = (new RefundPaymentMapper())->map($parent, $refund);

    expect($amount->nonCash)->toBe('99.99')
        ->and($amount->cash)->toBeNull();
});

it('strips trailing-zero decimals so "50.00" becomes "50"', function (): void {
    // Mirrors PaymentMapper's number-formatting contract: SDK accepts
    // both shapes, we canonicalise to the minimal representation so
    // payload diffing in audit logs is stable.
    [$parent, $refund] = refundPair(refundAmount: '50.00');

    $amount = (new RefundPaymentMapper())->map($parent, $refund);

    expect($amount->nonCash)->toBe('50');
});

it('preserves non-zero decimals', function (): void {
    [$parent, $refund] = refundPair(refundAmount: '50.50');

    $amount = (new RefundPaymentMapper())->map($parent, $refund);

    expect($amount->nonCash)->toBe('50.5');
});

it('throws FiscalBuildException for zero-amount refunds', function (): void {
    [$parent, $refund] = refundPair(refundAmount: '0');

    expect(fn () => (new RefundPaymentMapper())->map($parent, $refund))
        ->toThrow(FiscalBuildException::class, 'non-positive amount');
});

it('throws FiscalBuildException for negative-amount refunds', function (): void {
    // WC normally returns absolute values from get_amount(), but a
    // misconfigured plugin could route a signed value through.
    [$parent, $refund] = refundPair(refundAmount: '-25');

    expect(fn () => (new RefundPaymentMapper())->map($parent, $refund))
        ->toThrow(FiscalBuildException::class, 'non-positive amount');
});

it('uses the injected CashPaymentResolver for tender classification', function (): void {
    $resolver = Mockery::mock(CashPaymentResolver::class);
    $resolver->expects('isCash')->with('custom_gateway')->andReturn(true);

    $parent = Mockery::mock(WC_Order::class);
    $parent->allows('get_payment_method')->andReturn('custom_gateway');
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_amount')->andReturn('10');

    $amount = (new RefundPaymentMapper($resolver))->map($parent, $refund);

    expect($amount->cash)->toBe('10');
});
