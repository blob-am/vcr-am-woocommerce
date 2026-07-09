<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Tests\Unit\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Fiscal\Exception\FiscalBuildException;
use BlobSolutions\WooCommerceVcrAm\Fiscal\PaymentMapper;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\AutoSettleTender;
use Brain\Monkey\Filters;
use Mockery;
use WC_Order;

beforeEach(function (): void {
    $this->mapper = new PaymentMapper();
    // Brain Monkey's default `apply_filters` stub returns the first arg
    // unchanged, which is exactly what we want for tests that don't
    // exercise the filter override.
});

it('maps cod to a cash auto-settle', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_total')->andReturn('1500.00');
    $order->allows('get_payment_method')->andReturn('cod');

    $autoSettle = $this->mapper->map($order);

    expect($autoSettle->tender)->toBe(AutoSettleTender::Cash);
});

it('maps cheque to a cash auto-settle', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_total')->andReturn('250.50');
    $order->allows('get_payment_method')->andReturn('cheque');

    $autoSettle = $this->mapper->map($order);

    expect($autoSettle->tender)->toBe(AutoSettleTender::Cash);
});

it('maps any other gateway id to a non-cash auto-settle', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_total')->andReturn('1000');
    $order->allows('get_payment_method')->andReturn('stripe');

    $autoSettle = $this->mapper->map($order);

    expect($autoSettle->tender)->toBe(AutoSettleTender::NonCash);
});

it('maps an empty payment method (manual order) to non-cash', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_total')->andReturn('500');
    $order->allows('get_payment_method')->andReturn('');

    $autoSettle = $this->mapper->map($order);

    expect($autoSettle->tender)->toBe(AutoSettleTender::NonCash);
});

it('honours custom cash methods declared via the vcr_cash_payment_method_ids filter', function (): void {
    Filters\expectApplied('vcr_cash_payment_method_ids')
        ->once()
        ->andReturn(['cod', 'cheque', 'idram_cash']);

    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_total')->andReturn('1000');
    $order->allows('get_payment_method')->andReturn('idram_cash');

    $autoSettle = $this->mapper->map($order);

    expect($autoSettle->tender)->toBe(AutoSettleTender::Cash);
});

it('serializes to just a tender — no client-side amount on the wire', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_total')->andReturn('1000');
    $order->allows('get_payment_method')->andReturn('cod');

    $autoSettle = $this->mapper->map($order);

    expect($autoSettle->jsonSerialize())->toBe(['tender' => 'cash']);
});

it('is currency-agnostic — a non-AMD order needs no converter (conversion is server-side)', function (): void {
    // The whole point of the auto-settle migration: the plugin no longer
    // converts client-side, so a USD order maps to a plain tender with no
    // CurrencyConverter in sight. The AMD magnitude is derived server-side
    // from the per-item `currency` tags set by ItemBuilder.
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_total')->andReturn('100');
    $order->allows('get_payment_method')->andReturn('stripe');

    $autoSettle = $this->mapper->map($order);

    expect($autoSettle->tender)->toBe(AutoSettleTender::NonCash);
});

it('refuses zero-total orders — nothing to fiscalise', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_total')->andReturn('0');
    $order->allows('get_payment_method')->andReturn('stripe');
    $order->allows('get_id')->andReturn(42);

    $this->mapper->map($order);
})->throws(FiscalBuildException::class, 'non-positive total');
