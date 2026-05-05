<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Tests\Unit\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Currency\CurrencyConverter;
use BlobSolutions\WooCommerceVcrAm\Currency\Exception\ExchangeRateUnavailableException;
use BlobSolutions\WooCommerceVcrAm\Currency\ExchangeRate;
use BlobSolutions\WooCommerceVcrAm\Currency\ExchangeRateProvider;
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
    $order->allows('get_currency')->andReturn('AMD');
    $order->allows('get_total')->andReturn('1500.00');
    $order->allows('get_payment_method')->andReturn('cod');

    $amount = $this->mapper->map($order);

    expect($amount->cash)->toBe('1500')
        ->and($amount->nonCash)->toBeNull();
});

it('maps cheque to the cash bucket', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_currency')->andReturn('AMD');
    $order->allows('get_total')->andReturn('250.50');
    $order->allows('get_payment_method')->andReturn('cheque');

    $amount = $this->mapper->map($order);

    expect($amount->cash)->toBe('250.5');
});

it('maps any other gateway id to nonCash', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_currency')->andReturn('AMD');
    $order->allows('get_total')->andReturn('1000');
    $order->allows('get_payment_method')->andReturn('stripe');

    $amount = $this->mapper->map($order);

    expect($amount->nonCash)->toBe('1000')
        ->and($amount->cash)->toBeNull();
});

it('maps an empty payment method (manual order) to nonCash', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_currency')->andReturn('AMD');
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
    $order->allows('get_currency')->andReturn('AMD');
    $order->allows('get_total')->andReturn('1000');
    $order->allows('get_payment_method')->andReturn('idram_cash');

    $amount = $this->mapper->map($order);

    expect($amount->cash)->toBe('1000');
});

it('refuses zero-total orders — nothing to fiscalise', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_currency')->andReturn('AMD');
    $order->allows('get_total')->andReturn('0');
    $order->allows('get_payment_method')->andReturn('stripe');
    $order->allows('get_id')->andReturn(42);

    $this->mapper->map($order);
})->throws(FiscalBuildException::class, 'non-positive total');

it('strips trailing zeros from the formatted amount', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_currency')->andReturn('AMD');
    $order->allows('get_total')->andReturn('100.00');
    $order->allows('get_payment_method')->andReturn('stripe');

    $amount = $this->mapper->map($order);

    expect($amount->nonCash)->toBe('100');
});

/**
 * Multi-currency: USD order with explicit converter that returns rate
 * 388.5 -> 100 USD = 38850 AMD on the wire.
 */
it('converts non-AMD totals via the injected CurrencyConverter', function (): void {
    $rate = new ExchangeRate(iso: 'USD', rate: 388.5, amount: 1.0, publishedAt: 0);
    $provider = new class ($rate) implements ExchangeRateProvider {
        public function __construct(private ExchangeRate $rate)
        {
        }

        public function getRate(string $iso): ExchangeRate
        {
            return $this->rate;
        }
    };
    $mapper = new PaymentMapper(new CurrencyConverter($provider));

    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_currency')->andReturn('USD');
    $order->allows('get_total')->andReturn('100');
    $order->allows('get_payment_method')->andReturn('stripe');

    $amount = $mapper->map($order);

    // 100 USD * 388.5 AMD/USD = 38850 AMD on the wire.
    expect($amount->nonCash)->toBe('38850');
});

/**
 * Without a converter, an AMD-currency order still works (passthrough).
 * This guards the legacy / single-currency-store branch.
 */
it('passes AMD totals through when no converter is injected', function (): void {
    $mapper = new PaymentMapper(converter: null);

    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_currency')->andReturn('AMD');
    $order->allows('get_total')->andReturn('1500');
    $order->allows('get_payment_method')->andReturn('stripe');

    $amount = $mapper->map($order);

    expect($amount->nonCash)->toBe('1500');
});

/**
 * Critical guard: a non-AMD order routed to a converter-less mapper MUST
 * throw, never pass-through. Letting USD totals reach SRC as if they were
 * AMD would be the under-reporting bug that motivated the multi-currency
 * work in the first place.
 */
it('refuses to fiscalize a non-AMD order when no converter is wired', function (): void {
    $mapper = new PaymentMapper(converter: null);

    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_currency')->andReturn('USD');
    $order->allows('get_total')->andReturn('100');
    $order->allows('get_payment_method')->andReturn('stripe');
    $order->allows('get_id')->andReturn(99);

    expect(fn () => $mapper->map($order))
        ->toThrow(FiscalBuildException::class, 'no CurrencyConverter is configured');
});

it('translates ExchangeRateUnavailableException into FiscalBuildException → ManualRequired', function (): void {
    $provider = new class () implements ExchangeRateProvider {
        public function getRate(string $iso): ExchangeRate
        {
            throw new ExchangeRateUnavailableException('CBA unreachable for 50h');
        }
    };
    $mapper = new PaymentMapper(new CurrencyConverter($provider));

    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_currency')->andReturn('USD');
    $order->allows('get_total')->andReturn('100');
    $order->allows('get_payment_method')->andReturn('stripe');
    $order->allows('get_id')->andReturn(42);

    // The build exception's message must reference the underlying CBA
    // problem so the operator can diagnose without digging through logs.
    expect(fn () => $mapper->map($order))
        ->toThrow(FiscalBuildException::class, 'CBA unreachable for 50h');
});
