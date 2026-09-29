<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Tests\Unit\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Fiscal\CommentBuilder;
use BlobSolutions\WooCommerceVcrAm\Fiscal\Exception\FiscalBuildException;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalJob;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Fiscal\ItemBuilder;
use BlobSolutions\WooCommerceVcrAm\Fiscal\PaymentMapper;
use BlobSolutions\WooCommerceVcrAm\Fiscal\SaleRegistrar;
use BlobSolutions\WooCommerceVcrAm\Fiscal\SaleRegistrarFactory;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\AutoSettleTender;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrApiException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrNetworkException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrValidationException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\AutoSettle;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\Department;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\Offer;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\RegisterSaleInput;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\SaleItem;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\PendingResource;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\RegisterSaleResponse;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Unit;
use BlobSolutions\WooCommerceVcrAm\Vendor\Psr\Http\Message\RequestInterface;
use BlobSolutions\WooCommerceVcrAm\Vendor\Psr\Http\Message\ResponseInterface;
use Brain\Monkey\Functions;
use InvalidArgumentException;
use Mockery;
use RuntimeException;
use WC_Order;

/**
 * Tests cover FiscalJob's branching: success, idempotent re-entry,
 * configuration gap, build error, and the four failure classifications
 * (5xx, 429, 4xx, network, validation, unknown). The non-trivial bit is
 * the retry-budget check — we exercise both the "still has budget"
 * (retriable) and the "exhausted budget" (failed) branches, for each of
 * the two budgets.
 */
beforeEach(function (): void {
    $this->config = Mockery::mock(Configuration::class);
    $this->registrarFactory = Mockery::mock(SaleRegistrarFactory::class);
    $this->itemBuilder = Mockery::mock(ItemBuilder::class);
    $this->paymentMapper = Mockery::mock(PaymentMapper::class);
    $this->commentBuilder = Mockery::mock(CommentBuilder::class);
    $this->meta = Mockery::mock(FiscalStatusMeta::class);
    // Logger is permissive by default — tests that assert on log
    // routing layer their own expects() on top. `byDefault()` makes
    // the allows yield to per-test expects() of the same method
    // (otherwise the allows consumes the call and the expects fails
    // its count check).
    $this->logger = Mockery::mock(\BlobSolutions\WooCommerceVcrAm\Logging\Logger::class);
    $this->logger->allows('warning')->byDefault();
    $this->logger->allows('error')->byDefault();
    $this->logger->allows('info')->byDefault();

    $this->job = new FiscalJob(
        configuration: $this->config,
        registrarFactory: $this->registrarFactory,
        itemBuilder: $this->itemBuilder,
        paymentMapper: $this->paymentMapper,
        commentBuilder: $this->commentBuilder,
        meta: $this->meta,
        logger: $this->logger,
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
    // Default: a real shop order (not a refund). Tests that want to
    // exercise the refund-filter branch override this allow().
    $order->allows('get_type')->andReturn('shop_order');
    // get_id() is read by FiscalJob::logAttempt for the wc_get_logger
    // line context. Stubbed by default so failure-path tests don't
    // need to wire it individually.
    $order->allows('get_id')->andReturn($orderId);
    Functions\when('wc_get_order')->alias(static fn (int $id): ?WC_Order => $id === $orderId ? $order : null);

    return $order;
}

function makeApiException(int $statusCode, ?PendingResource $pending = null): VcrApiException
{
    return new VcrApiException(
        statusCode: $statusCode,
        apiErrorMessage: 'simulated',
        rawBody: '{}',
        request: Mockery::mock(RequestInterface::class),
        response: Mockery::mock(ResponseInterface::class),
        requestId: 'req-test',
        pending: $pending,
    );
}

/**
 * The `pending` document the API attaches to an error when the sale survived
 * it. Its presence is what separates an SRC rejection from an idempotency
 * conflict on a 409 — see {@see FiscalJob::isRetriableApiError()}.
 */
function makePendingSale(): PendingResource
{
    return new PendingResource(
        type: 'sale',
        id: 5122,
        statusUrl: '/api/v1/sales/5122',
        mayResubmit: false,
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
function primeBuildable(?string $comment = null): void
{
    /** @var \Mockery\MockInterface $config */
    $config = test()->config;
    $config->allows('isFullyConfigured')->andReturn(true);
    $config->allows('apiKey')->andReturn('test-key');
    $config->allows('defaultCashierId')->andReturn(5);
    $config->allows('defaultDepartmentId')->andReturn(7);
    $config->allows('shippingSku')->andReturn(null);
    $config->allows('feeSku')->andReturn(null);
    $config->allows('commentSource')->andReturn(Configuration::COMMENT_SOURCE_ORDER_NUMBER);

    /** @var \Mockery\MockInterface $itemBuilder */
    $itemBuilder = test()->itemBuilder;
    $itemBuilder->allows('build')->andReturn(stubSaleItems());

    /** @var \Mockery\MockInterface $paymentMapper */
    $paymentMapper = test()->paymentMapper;
    $paymentMapper->allows('map')->andReturn(new AutoSettle(AutoSettleTender::NonCash));

    /** @var \Mockery\MockInterface $commentBuilder */
    $commentBuilder = test()->commentBuilder;
    $commentBuilder->allows('build')->andReturn($comment);

    // Permissive by default; `byDefault()` yields to a per-test expects() of
    // the same method. The tests that care what the key is set their own.
    /** @var \Mockery\MockInterface $meta */
    $meta = test()->meta;
    $meta->allows('idempotencyKey')->andReturn('order_123')->byDefault();
}

it('returns failed when wc_get_order returns null', function (): void {
    Functions\when('wc_get_order')->justReturn(null);

    $outcome = $this->job->run(999);

    expect($outcome->status)->toBe(FiscalStatus::Failed)
        ->and($outcome->reason)->toContain('not found');
});

it('refuses to fiscalise refunds (WC_Order_Refund extends WC_Order)', function (): void {
    // The crux: a bare `instanceof WC_Order` check would let refunds
    // through, because WC_Order_Refund extends WC_Order upstream. This
    // test pins the order-type filter that catches them.
    $refund = Mockery::mock(WC_Order::class);
    $refund->allows('get_type')->andReturn('shop_order_refund');
    Functions\when('wc_get_order')->justReturn($refund);

    $this->meta->expects('status')->never();
    $this->registrarFactory->expects('create')->never();

    $outcome = $this->job->run(456);

    expect($outcome->status)->toBe(FiscalStatus::Failed);
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
    $this->config->allows('apiKey')->andReturn(null);
    $this->config->allows('isFullyConfigured')->andReturn(false);

    $this->meta->expects('markManualRequired')->with($order, Mockery::type('string'));
    $this->registrarFactory->expects('create')->never();

    $outcome = $this->job->run(123);

    expect($outcome->status)->toBe(FiscalStatus::ManualRequired);
});

it('flips to ManualRequired when isFullyConfigured passes but apiKey vanished mid-flight', function (): void {
    // Simulates the race where the admin clears the API key between the
    // gate check and the registrar build. Without the explicit-apiKey
    // refactor this would be a generic RuntimeException routed to the
    // retry path — wasting the entire 6-attempt budget.
    $order = makeOrderMockReturnedByWcGetOrder();
    $this->meta->allows('status')->with($order)->andReturn(null);
    $this->config->allows('isFullyConfigured')->andReturn(true);
    $this->config->allows('apiKey')->andReturn(null);

    $this->meta->expects('markManualRequired')->with($order, Mockery::type('string'));
    $this->registrarFactory->expects('create')->never();

    $outcome = $this->job->run(123);

    expect($outcome->status)->toBe(FiscalStatus::ManualRequired);
});

it('flips to ManualRequired when ItemBuilder rejects the order', function (): void {
    $order = makeOrderMockReturnedByWcGetOrder();
    $this->meta->allows('status')->with($order)->andReturn(null);
    $this->config->allows('isFullyConfigured')->andReturn(true);
    $this->config->allows('apiKey')->andReturn('k');
    $this->config->allows('defaultCashierId')->andReturn(5);
    $this->config->allows('defaultDepartmentId')->andReturn(7);
    $this->config->allows('shippingSku')->andReturn(null);
    $this->config->allows('feeSku')->andReturn(null);

    $this->itemBuilder->expects('build')
        ->with($order, Mockery::type(Department::class), null, null)
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

it('passes the built comment through to the sale payload', function (): void {
    $order = makeOrderMockReturnedByWcGetOrder();
    $this->meta->allows('status')->with($order)->andReturn(null);
    primeBuildable('WooCommerce #123');

    $this->meta->allows('recordAttempt')->with($order);
    $this->meta->allows('attemptCount')->with($order)->andReturn(1);
    $this->meta->allows('markSuccess');
    $order->allows('add_order_note');

    $response = new RegisterSaleResponse(
        urlId: 'r-1',
        saleId: 1,
        crn: 'C',
        srcReceiptId: 1,
        fiscal: 'F',
    );

    $captured = null;
    $registrar = Mockery::mock(SaleRegistrar::class);
    $registrar->expects('registerSale')
        ->with(Mockery::on(function ($input) use (&$captured): bool {
            $captured = $input;

            return true;
        }), Mockery::type('string'))
        ->andReturn($response);
    $this->registrarFactory->expects('create')->andReturn($registrar);

    $this->job->run(123);

    expect($captured)->not->toBeNull()
        ->and($captured->comment)->toBe('WooCommerce #123');
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
    // Retry mechanics go to wc_get_logger (source: vcr), NOT to order notes —
    // notes are reserved for customer-relevant outcomes.
    $this->logger->expects('warning')->with(Mockery::pattern('/will retry/'), Mockery::type('array'));

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
    // 400, not 422: 422 is the idempotency-key conflict and carries its own
    // message, so it stopped being the representative "generic 4xx" case.
    $registrar->expects('registerSale')->andThrow(makeApiException(400));
    $this->registrarFactory->expects('create')->andReturn($registrar);

    // The request id is the only handle support has on a specific failed
    // call, so it has to survive into the message stored on the order.
    $this->meta->expects('markFailed')->with($order, Mockery::pattern('/HTTP 400 \[request req-test\]/'));
    // Terminal failures go to logger at error level (vs warning for retriable).
    $this->logger->expects('error')->with(Mockery::pattern('/TERMINAL/'), Mockery::type('array'));

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

it('flips to Failed once a classified error has used the full budget', function (): void {
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

it('gives an error the SDK did not classify one retry, not the full budget', function (): void {
    // A TypeError from a collaborator or a fatal from a neighbouring plugin's
    // filter arrives here as a bare Throwable. Retrying it for two and a half
    // hours keeps the order reading `pending` while nothing gets better.
    $order = makeOrderMockReturnedByWcGetOrder();
    $this->meta->allows('status')->with($order)->andReturn(null);
    primeBuildable();

    $this->meta->expects('recordAttempt')->with($order);
    $this->meta->allows('attemptCount')->with($order)->andReturn(1);

    $registrar = Mockery::mock(SaleRegistrar::class);
    $registrar->expects('registerSale')->andThrow(new RuntimeException('some other plugin exploded'));
    $this->registrarFactory->expects('create')->andReturn($registrar);

    $this->meta->expects('markRetriableFailure')->with($order, Mockery::any());
    $order->allows('add_order_note');

    $outcome = $this->job->run(123);

    expect($outcome->shouldRetry())->toBeTrue();
});

it('stops an unclassified error after its second attempt, while a 5xx keeps going', function (): void {
    $order = makeOrderMockReturnedByWcGetOrder();
    $this->meta->allows('status')->with($order)->andReturn(null);
    primeBuildable();

    $this->meta->expects('recordAttempt')->with($order);
    $this->meta->allows('attemptCount')->with($order)->andReturn(FiscalJob::UNCLASSIFIED_ERROR_MAX_ATTEMPTS);

    $registrar = Mockery::mock(SaleRegistrar::class);
    $registrar->expects('registerSale')->andThrow(new RuntimeException('still exploding'));
    $this->registrarFactory->expects('create')->andReturn($registrar);

    $this->meta->expects('markFailed')->with($order, Mockery::pattern('/Gave up after 2 attempts/'));
    $order->allows('add_order_note');

    $outcome = $this->job->run(123);

    expect($outcome->status)->toBe(FiscalStatus::Failed)
        // The budgets are different numbers on purpose: an outage at the tax
        // authority is worth the whole schedule, a bug is not.
        ->and(FiscalJob::UNCLASSIFIED_ERROR_MAX_ATTEMPTS)->toBeLessThan(FiscalJob::MAX_ATTEMPTS);
});

it('keeps retrying a 5xx at the attempt where an unclassified error would have stopped', function (): void {
    $order = makeOrderMockReturnedByWcGetOrder();
    $this->meta->allows('status')->with($order)->andReturn(null);
    primeBuildable();

    $this->meta->expects('recordAttempt')->with($order);
    $this->meta->allows('attemptCount')->with($order)->andReturn(FiscalJob::UNCLASSIFIED_ERROR_MAX_ATTEMPTS);

    $registrar = Mockery::mock(SaleRegistrar::class);
    $registrar->expects('registerSale')->andThrow(makeApiException(503));
    $this->registrarFactory->expects('create')->andReturn($registrar);

    $this->meta->expects('markRetriableFailure')->with($order, Mockery::any());
    $order->allows('add_order_note');

    $outcome = $this->job->run(123);

    expect($outcome->shouldRetry())->toBeTrue();
});

it('treats the SDK refusing one of our arguments as terminal', function (): void {
    // Nothing about the next attempt differs, so retrying spends the budget on
    // a refusal that needs a settings change or a plugin fix.
    $order = makeOrderMockReturnedByWcGetOrder();
    $this->meta->allows('status')->with($order)->andReturn(null);
    primeBuildable();

    $this->meta->expects('recordAttempt')->with($order);
    $this->meta->allows('attemptCount')->with($order)->andReturn(1);

    $registrar = Mockery::mock(SaleRegistrar::class);
    $registrar->expects('registerSale')->andThrow(new InvalidArgumentException('integration must contain printable ASCII only'));
    $this->registrarFactory->expects('create')->andReturn($registrar);

    $this->meta->expects('markFailed')->with($order, Mockery::any());
    $order->allows('add_order_note');

    $outcome = $this->job->run(123);

    expect($outcome->status)->toBe(FiscalStatus::Failed);
});

it('sends the order idempotency key with the sale', function (): void {
    // The point of the whole mechanism: if this header is missing on the one
    // attempt that times out, the retry prints a second fiscal receipt.
    $order = makeOrderMockReturnedByWcGetOrder();
    $this->meta->allows('status')->with($order)->andReturn(null);
    primeBuildable();

    $this->meta->allows('recordAttempt')->with($order);
    $this->meta->allows('attemptCount')->with($order)->andReturn(1);
    $this->meta->expects('idempotencyKey')->with($order)->andReturn('order_123');
    $this->meta->allows('markSuccess');
    $order->allows('add_order_note');

    $captured = null;
    $registrar = Mockery::mock(SaleRegistrar::class);
    $registrar->expects('registerSale')
        ->with(Mockery::type(RegisterSaleInput::class), Mockery::on(function ($key) use (&$captured): bool {
            $captured = $key;

            return true;
        }))
        ->andReturn(new RegisterSaleResponse(
            urlId: 'r-1',
            saleId: 1,
            crn: 'C',
            srcReceiptId: 1,
            fiscal: 'F',
        ));
    $this->registrarFactory->expects('create')->andReturn($registrar);

    $this->job->run(123);

    expect($captured)->toBe('order_123');
});

it('retries a 409 that carries no pending document (another request holds the key)', function (): void {
    // This is the collapsed duplicate: two hooks raced, the other one is
    // mid-flight under our key. Terminal here would turn every duplicate the
    // key successfully caught into an order someone has to rescue by hand.
    $order = makeOrderMockReturnedByWcGetOrder();
    $this->meta->allows('status')->with($order)->andReturn(null);
    primeBuildable();

    $this->meta->expects('recordAttempt')->with($order);
    $this->meta->allows('attemptCount')->with($order)->andReturn(1);

    $registrar = Mockery::mock(SaleRegistrar::class);
    $registrar->expects('registerSale')->andThrow(makeApiException(409));
    $this->registrarFactory->expects('create')->andReturn($registrar);

    $this->meta->expects('markRetriableFailure')->with($order, Mockery::pattern('/HTTP 409/'));

    $outcome = $this->job->run(123);

    expect($outcome->shouldRetry())->toBeTrue();
});

it('keeps a 409 that carries a pending document terminal (SRC rejected the sale)', function (): void {
    // Same status code, opposite meaning: SRC answered in full and would
    // answer the same way to the same payload. Retrying burns the budget and
    // the admin never hears about it.
    $order = makeOrderMockReturnedByWcGetOrder();
    $this->meta->allows('status')->with($order)->andReturn(null);
    primeBuildable();

    $this->meta->expects('recordAttempt')->with($order);
    $this->meta->allows('attemptCount')->with($order)->andReturn(1);

    $registrar = Mockery::mock(SaleRegistrar::class);
    $registrar->expects('registerSale')->andThrow(makeApiException(409, makePendingSale()));
    $this->registrarFactory->expects('create')->andReturn($registrar);

    $this->meta->expects('markFailed')->with($order, Mockery::pattern('/HTTP 409/'));

    $outcome = $this->job->run(123);

    expect($outcome->status)->toBe(FiscalStatus::Failed);
});

it('explains a 422 in shop-owner terms and keeps the technical detail', function (): void {
    // The API's own wording ("use a new key per distinct operation") is
    // addressed to an integrator; the person reading the order is not one.
    $order = makeOrderMockReturnedByWcGetOrder();
    $this->meta->allows('status')->with($order)->andReturn(null);
    primeBuildable();

    $this->meta->expects('recordAttempt')->with($order);
    $this->meta->allows('attemptCount')->with($order)->andReturn(1);

    $registrar = Mockery::mock(SaleRegistrar::class);
    $registrar->expects('registerSale')->andThrow(makeApiException(422));
    $this->registrarFactory->expects('create')->andReturn($registrar);

    $captured = null;
    $this->meta->expects('markFailed')
        ->with($order, Mockery::on(function ($message) use (&$captured): bool {
            $captured = $message;

            return true;
        }));

    $outcome = $this->job->run(123);

    expect($outcome->status)->toBe(FiscalStatus::Failed)
        ->and($captured)->toContain('Fiscalize now')
        ->and($captured)->toContain('HTTP 422 [request req-test]');
});
