<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Fiscal\CashPaymentResolver;
use Brain\Monkey\Filters;

it('classifies built-in WC cash gateways (cod, cheque) as cash', function (): void {
    $resolver = new CashPaymentResolver();

    expect($resolver->isCash('cod'))->toBeTrue()
        ->and($resolver->isCash('cheque'))->toBeTrue();
});

it('classifies online gateways as nonCash by default', function (): void {
    $resolver = new CashPaymentResolver();

    expect($resolver->isCash('stripe'))->toBeFalse()
        ->and($resolver->isCash('paypal'))->toBeFalse()
        ->and($resolver->isCash('woocommerce_payments'))->toBeFalse()
        ->and($resolver->isCash('bacs'))->toBeFalse();
});

it('treats empty payment method as nonCash (manual orders)', function (): void {
    expect((new CashPaymentResolver())->isCash(''))->toBeFalse();
});

it('honours the vcr_cash_payment_method_ids filter to extend cash gateways', function (): void {
    Filters\expectApplied('vcr_cash_payment_method_ids')
        ->andReturn(['cod', 'cheque', 'idram_cash']);

    expect((new CashPaymentResolver())->isCash('idram_cash'))->toBeTrue();
});

it('discards non-string entries from a misbehaving filter', function (): void {
    // Defensive — a buggy filter shouldn't escalate into a TypeError.
    Filters\expectApplied('vcr_cash_payment_method_ids')
        ->andReturn(['cod', 42, '', null, 'idram_cash']);

    $resolver = new CashPaymentResolver();
    expect($resolver->isCash('idram_cash'))->toBeTrue()
        ->and($resolver->isCash('cod'))->toBeTrue();
});

it('falls back to default cash list when the filter returns a non-array', function (): void {
    Filters\expectApplied('vcr_cash_payment_method_ids')->andReturn('garbage');

    $resolver = new CashPaymentResolver();
    expect($resolver->isCash('cod'))->toBeTrue()
        ->and($resolver->isCash('stripe'))->toBeFalse();
});
