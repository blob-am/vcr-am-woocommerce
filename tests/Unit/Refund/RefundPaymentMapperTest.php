<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Currency\CurrencyConverter;
use BlobSolutions\WooCommerceVcrAm\Currency\Exception\ExchangeRateUnavailableException;
use BlobSolutions\WooCommerceVcrAm\Currency\ExchangeRate;
use BlobSolutions\WooCommerceVcrAm\Currency\ExchangeRateProvider;
use BlobSolutions\WooCommerceVcrAm\Fiscal\CashPaymentResolver;
use BlobSolutions\WooCommerceVcrAm\Fiscal\Exception\FiscalBuildException;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundPaymentMapper;

/**
 * @return array{0: WC_Order, 1: WC_Order_Refund}
 */
function refundPair(string $paymentMethod = 'stripe', string $refundAmount = '50.00', string $currency = 'AMD'): array
{
    $parent = Mockery::mock(WC_Order::class);
    $parent->allows('get_payment_method')->andReturn($paymentMethod);
    $parent->allows('get_currency')->andReturn($currency);
    $parent->allows('get_id')->andReturn(7);

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

it('converts non-AMD refund amounts via the injected CurrencyConverter', function (): void {
    [$parent, $refund] = refundPair(paymentMethod: 'stripe', refundAmount: '50.00', currency: 'USD');

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

    $mapper = new RefundPaymentMapper(new CurrencyConverter($provider));
    $amount = $mapper->map($parent, $refund);

    // 50 USD * 388.5 = 19425 AMD on the wire.
    expect($amount->nonCash)->toBe('19425');
});

it('refuses non-AMD refunds when no converter is wired', function (): void {
    [$parent, $refund] = refundPair(currency: 'USD');

    expect(fn () => (new RefundPaymentMapper())->map($parent, $refund))
        ->toThrow(FiscalBuildException::class, 'no CurrencyConverter is configured');
});

it('translates ExchangeRateUnavailableException for refunds the same way sales do', function (): void {
    [$parent, $refund] = refundPair(currency: 'EUR');

    $provider = new class () implements ExchangeRateProvider {
        public function getRate(string $iso): ExchangeRate
        {
            throw new ExchangeRateUnavailableException('rate unavailable for EUR');
        }
    };
    $mapper = new RefundPaymentMapper(new CurrencyConverter($provider));

    expect(fn () => $mapper->map($parent, $refund))
        ->toThrow(FiscalBuildException::class, 'rate unavailable for EUR');
});

it('uses the injected CashPaymentResolver for tender classification', function (): void {
    $resolver = Mockery::mock(CashPaymentResolver::class);
    $resolver->expects('isCash')->with('custom_gateway')->andReturn(true);

    $parent = Mockery::mock(WC_Order::class);
    $parent->allows('get_payment_method')->andReturn('custom_gateway');
    $parent->allows('get_currency')->andReturn('AMD');
    $parent->allows('get_id')->andReturn(7);
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_amount')->andReturn('10');

    // Constructor signature: (?CurrencyConverter, CashPaymentResolver) —
    // null converter keeps the AMD passthrough so this test focuses on
    // the cash classification override.
    $amount = (new RefundPaymentMapper(converter: null, cashResolver: $resolver))->map($parent, $refund);

    expect($amount->cash)->toBe('10');
});
