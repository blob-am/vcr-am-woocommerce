<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Tests\Unit\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Fiscal\Exception\FiscalBuildException;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalJob;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Fiscal\ItemBuilder;
use BlobSolutions\WooCommerceVcrAm\Fiscal\PaymentMapper;
use BlobSolutions\WooCommerceVcrAm\Fiscal\SaleRegistrar;
use BlobSolutions\WooCommerceVcrAm\Fiscal\SaleRegistrarFactory;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrApiException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrNetworkException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrValidationException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\Department;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\Offer;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\SaleAmount;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\SaleItem;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\RegisterSaleResponse;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Unit;
use Brain\Monkey\Functions;
use Mockery;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use WC_Order;

/**
 * Tests cover FiscalJob's branching: success, idempotent re-entry,
 * configuration gap, build error, and the four failure classifications
 * (5xx, 429, 4xx, network, validation, unknown). The non-trivial bit is
 * the retry-budget check — we exercise both the "still has budget"
 * (retriable) and the "exhausted budget" (failed) branches.
 */
beforeEach(function (): void {
    $this->config = Mockery::mock(Configuration::class);
    $this->registrarFactory = Mockery::mock(SaleRegistrarFactory::class);
    $this->itemBuilder = Mockery::mock(ItemBuilder::class);
    $this->paymentMapper = Mockery::mock(PaymentMapper::class);
    $this->meta = Mockery::mock(FiscalStatusMeta::class);

    $this->job = new FiscalJob(
        configuration: $this->config,
        registrarFactory: $this->registrarFactory,
        itemBuilder: $this->itemBuilder,
        paymentMapper: $this->paymentMapper,
        meta: $this->meta,
    );
});

/**
 * Sets up wc_get_order(123) to return a fresh WC_Order mock that the
 * caller can layer additional expectations on. Centralised so individual
 * tests don't repeat the wiring noise.
 */
function makeOrderMockReturnedByWcGetOrder(int $orderId = 123): WC_Order
{
    $order = Mockery::mock(WC_Order::class);
    Functions\when('wc_get_order')->alias(static fn (int $id): ?WC_Order => $id === $orderId ? $order : null);

    return $order;
}

function makeApiException(int $statusCode): VcrApiException
{
    return new VcrApiException(
        statusCode: $statusCode,
        apiErrorCode: 'TEST',
        apiErrorMessage: 'simulated',
        rawBody: '{}',
        request: Mockery::mock(RequestInterface::class),
        response: Mockery::mock(ResponseInterface::class),
    );
}

/**
 * Single-line SaleItem the SDK accepts. Most tests don't care about line
 * contents; they want to push past ItemBuilder and exercise the FiscalJob
 * orchestration around `registerSale`.
 *
 * @return list<SaleItem>
 */
function stubSaleItems(): array
{
    return [new SaleItem(
        offer: Offer::existing('SKU'),
        department: new Department(7),
        quantity: '1',
        price: '100',
        unit: Unit::Piece,
    )];
}

/**
 * Stub the bare minimum the FiscalJob needs to traverse build / payment
 * mapping and reach the registrar. Caller still controls what the
 * registrar returns / throws.
 */
function primeBuildable(): void
{
    /** @var \Mockery\MockInterface $config */
    $config = test()->config;
    $config->allows('isFullyConfigured')->andReturn(true);
    $config->allows('defaultCashierId')->andReturn(5);
    $config->allows('defaultDepartmentId')->andReturn(7);

    /** @var \Mockery\MockInterface $itemBuilder */
    $itemBuilder = test()->itemBuilder;
    $itemBuilder->allows('build')->andReturn(stubSaleItems());

    /** @var \Mockery\MockInterface $paymentMapper */
    $paymentMapper = test()->paymentMapper;
    $paymentMapper->allows('map')->andReturn(new SaleAmount(nonCash: '100'));
}

it('returns failed when wc_get_order returns null', function (): void {
    Functions\when('wc_get_order')->justReturn(null);

    $outcome = $this->job->run(999);

    expect($outcome->status)->toBe(FiscalStatus::Failed)
        ->and($outcome->reason)->toContain('not found');
});

it('short-circuits on an order already marked Success (no API call)', function (): void {
    $order = makeOrderMockReturnedByWcGetOrder();
    $this->meta->allows('status')->with($order)->andReturn(FiscalStatus::Success);
    $this->registrarFactory->expects('create')->never();

    $outcome = $this->job->run(123);

    expect($outcome->status)->toBe(FiscalStatus::Success);
});

it('flips the order to ManualRequired when configuration is incomplete', function (): void {
    $order = makeOrderMockReturnedByWcGetOrder();
    $this->meta->allows('status')->with($order)->andReturn(null);
    $this->config->allows('isFullyConfigured')->andReturn(false);

    $this->meta->expects('markManualRequired')->with($order, Mockery::type('string'));
    $this->registrarFactory->expects('create')->never();

    $outcome = $this->job->run(123);

    expect($outcome->status)->toBe(FiscalStatus::ManualRequired);
});

it('flips to ManualRequired when ItemBuilder rejects the order', function (): void {
    $order = makeOrderMockReturnedByWcGetOrder();
    $this->meta->allows('status')->with($order)->andReturn(null);
    $this->config->allows('isFullyConfigured')->andReturn(true);
    $this->config->allows('defaultCashierId')->andReturn(5);
    $this->config->allows('defaultDepartmentId')->andReturn(7);

    $this->itemBuilder->expects('build')
        ->with($order, Mockery::type(Department::class))
        ->andThrow(new FiscalBuildException('No SKU on product Foo'));

    $this->meta->expects('markManualRequired')->with($order, Mockery::pattern('/No SKU/'));
    $this->registrarFactory->expects('create')->never();

    $outcome = $this->job->run(123);

    expect($outcome->status)->toBe(FiscalStatus::ManualRequired);
});

it('writes Success meta and adds an order note on a clean registerSale', function (): void {
    $order = makeOrderMockReturnedByWcGetOrder();
    $this->meta->allows('status')->with($order)->andReturn(null);
    primeBuildable();

    $this->meta->expects('recordAttempt')->with($order);
    $this->meta->allows('attemptCount')->with($order)->andReturn(1);

    $response = new RegisterSaleResponse(
        urlId: 'r-1',
        saleId: 1,
        crn: 'C',
        srcReceiptId: 1,
        fiscal: 'F',
    );

    $registrar = Mockery::mock(SaleRegistrar::class);
    $registrar->expects('registerSale')->andReturn($response);
    $this->registrarFactory->expects('create')->andReturn($registrar);

    $this->meta->expects('markSuccess')->with($order, $response);
    $order->expects('add_order_note')->with(Mockery::pattern('/VCR fiscal receipt registered/'));

    $outcome = $this->job->run(123);

    expect($outcome->status)->toBe(FiscalStatus::Success);
});

it('classifies HTTP 5xx as retriable when the budget allows', function (): void {
    $order = makeOrderMockReturnedByWcGetOrder();
    $this->meta->allows('status')->with($order)->andReturn(null);
    primeBuildable();

    $this->meta->expects('recordAttempt')->with($order);
    $this->meta->allows('attemptCount')->with($order)->andReturn(1);

    $registrar = Mockery::mock(SaleRegistrar::class);
    $registrar->expects('registerSale')->andThrow(makeApiException(503));
    $this->registrarFactory->expects('create')->andReturn($registrar);

    $this->meta->expects('markRetriableFailure')->with($order, Mockery::pattern('/HTTP 503/'));
    $order->expects('add_order_note')->with(Mockery::pattern('/will retry/'));

    $outcome = $this->job->run(123);

    expect($outcome->shouldRetry())->toBeTrue();
});

it('classifies HTTP 4xx (other than 429) as terminal failure', function (): void {
    $order = makeOrderMockReturnedByWcGetOrder();
    $this->meta->allows('status')->with($order)->andReturn(null);
    primeBuildable();

    $this->meta->expects('recordAttempt')->with($order);
    $this->meta->allows('attemptCount')->with($order)->andReturn(1);

    $registrar = Mockery::mock(SaleRegistrar::class);
    $registrar->expects('registerSale')->andThrow(makeApiException(422));
    $this->registrarFactory->expects('create')->andReturn($registrar);

    $this->meta->expects('markFailed')->with($order, Mockery::pattern('/HTTP 422/'));
    $order->expects('add_order_note')->with(Mockery::pattern('/terminal/'));

    $outcome = $this->job->run(123);

    expect($outcome->status)->toBe(FiscalStatus::Failed);
});

it('classifies 429 as retriable', function (): void {
    $order = makeOrderMockReturnedByWcGetOrder();
    $this->meta->allows('status')->with($order)->andReturn(null);
    primeBuildable();

    $this->meta->expects('recordAttempt')->with($order);
    $this->meta->allows('attemptCount')->with($order)->andReturn(2);

    $registrar = Mockery::mock(SaleRegistrar::class);
    $registrar->expects('registerSale')->andThrow(makeApiException(429));
    $this->registrarFactory->expects('create')->andReturn($registrar);

    $this->meta->expects('markRetriableFailure')->with($order, Mockery::any());
    $order->allows('add_order_note');

    $outcome = $this->job->run(123);

    expect($outcome->shouldRetry())->toBeTrue();
});

it('classifies network errors as retriable', function (): void {
    $order = makeOrderMockReturnedByWcGetOrder();
    $this->meta->allows('status')->with($order)->andReturn(null);
    primeBuildable();

    $this->meta->expects('recordAttempt')->with($order);
    $this->meta->allows('attemptCount')->with($order)->andReturn(1);

    $registrar = Mockery::mock(SaleRegistrar::class);
    $registrar->expects('registerSale')->andThrow(new VcrNetworkException(
        request: Mockery::mock(RequestInterface::class),
        previous: new RuntimeException('connection refused'),
    ));
    $this->registrarFactory->expects('create')->andReturn($registrar);

    $this->meta->expects('markRetriableFailure')->with($order, Mockery::any());
    $order->allows('add_order_note');

    $outcome = $this->job->run(123);

    expect($outcome->shouldRetry())->toBeTrue();
});

it('classifies validation errors as terminal', function (): void {
    $order = makeOrderMockReturnedByWcGetOrder();
    $this->meta->allows('status')->with($order)->andReturn(null);
    primeBuildable();

    $this->meta->expects('recordAttempt')->with($order);
    $this->meta->allows('attemptCount')->with($order)->andReturn(1);

    $registrar = Mockery::mock(SaleRegistrar::class);
    $registrar->expects('registerSale')->andThrow(new VcrValidationException(
        rawBody: '{}',
        request: Mockery::mock(RequestInterface::class),
        response: Mockery::mock(ResponseInterface::class),
        detail: 'schema mismatch on field x',
        previous: new RuntimeException('bad'),
    ));
    $this->registrarFactory->expects('create')->andReturn($registrar);

    $this->meta->expects('markFailed')->with($order, Mockery::any());
    $order->allows('add_order_note');

    $outcome = $this->job->run(123);

    expect($outcome->status)->toBe(FiscalStatus::Failed);
});

it('flips to Failed once the retry budget is exhausted, regardless of error class', function (): void {
    $order = makeOrderMockReturnedByWcGetOrder();
    $this->meta->allows('status')->with($order)->andReturn(null);
    primeBuildable();

    $this->meta->expects('recordAttempt')->with($order);
    $this->meta->allows('attemptCount')->with($order)->andReturn(FiscalJob::MAX_ATTEMPTS);

    $registrar = Mockery::mock(SaleRegistrar::class);
    $registrar->expects('registerSale')->andThrow(makeApiException(503));
    $this->registrarFactory->expects('create')->andReturn($registrar);

    $this->meta->expects('markFailed')->with($order, Mockery::pattern('/Gave up after 6 attempts/'));
    $order->allows('add_order_note');

    $outcome = $this->job->run(123);

    expect($outcome->status)->toBe(FiscalStatus::Failed);
});
