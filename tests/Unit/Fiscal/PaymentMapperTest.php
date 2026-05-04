<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Tests\Unit\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Fiscal\Exception\FiscalBuildException;
use BlobSolutions\WooCommerceVcrAm\Fiscal\PaymentMapper;
use Brain\Monkey\Filters;
use Mockery;
use WC_Order;

beforeEach(function (): void {
    $this->mapper = new PaymentMapper();
    // Brain Monkey's default `apply_filters` stub returns the first arg
    // unchanged, which is exactly what we want for tests that don't
    // exercise the filter override.
});

it('maps cod to the cash bucket', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_total')->andReturn('1500.00');
    $order->allows('get_payment_method')->andReturn('cod');

    $amount = $this->mapper->map($order);

    expect($amount->cash)->toBe('1500')
        ->and($amount->nonCash)->toBeNull();
});

it('maps cheque to the cash bucket', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_total')->andReturn('250.50');
    $order->allows('get_payment_method')->andReturn('cheque');

    $amount = $this->mapper->map($order);

    expect($amount->cash)->toBe('250.5');
});

it('maps any other gateway id to nonCash', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_total')->andReturn('1000');
    $order->allows('get_payment_method')->andReturn('stripe');

    $amount = $this->mapper->map($order);

    expect($amount->nonCash)->toBe('1000')
        ->and($amount->cash)->toBeNull();
});

it('maps an empty payment method (manual order) to nonCash', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_total')->andReturn('500');
    $order->allows('get_payment_method')->andReturn('');

    $amount = $this->mapper->map($order);

    expect($amount->nonCash)->toBe('500');
});

it('honours custom cash methods declared via the vcr_cash_payment_method_ids filter', function (): void {
    Filters\expectApplied('vcr_cash_payment_method_ids')
        ->once()
        ->andReturn(['cod', 'cheque', 'idram_cash']);

    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_total')->andReturn('1000');
    $order->allows('get_payment_method')->andReturn('idram_cash');

    $amount = $this->mapper->map($order);

    expect($amount->cash)->toBe('1000');
});

it('refuses zero-total orders — nothing to fiscalise', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_total')->andReturn('0');
    $order->allows('get_payment_method')->andReturn('stripe');
    $order->allows('get_id')->andReturn(42);

    $this->mapper->map($order);
})->throws(FiscalBuildException::class, 'non-positive total');

it('strips trailing zeros from the formatted amount', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_total')->andReturn('100.00');
    $order->allows('get_payment_method')->andReturn('stripe');

    $amount = $this->mapper->map($order);

    expect($amount->nonCash)->toBe('100');
});
