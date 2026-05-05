<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Tests\Unit\Refund;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundEligibility;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundEligibilityChecker;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundJob;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundPaymentMapper;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundReasonMapper;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Refund\SaleRefundRegistrar;
use BlobSolutions\WooCommerceVcrAm\Refund\SaleRefundRegistrarFactory;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrApiException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrNetworkException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\RefundAmount;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\RegisterSaleRefundResponse;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\RefundReason;
use Brain\Monkey\Functions;
use Mockery;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use WC_Order;
use WC_Order_Refund;

/**
 * Mirrors {@see \BlobSolutions\WooCommerceVcrAm\Tests\Unit\Fiscal\FiscalJobTest}'s
 * structure but exercises refund-specific branches: parent-not-Success
 * gate, eligibility ineligibility routing to ManualRequired, the SDK
 * payload assembly, and shared retry classification.
 */
beforeEach(function (): void {
    $this->config = Mockery::mock(Configuration::class);
    $this->registrarFactory = Mockery::mock(SaleRefundRegistrarFactory::class);
    $this->paymentMapper = Mockery::mock(RefundPaymentMapper::class);
    $this->reasonMapper = Mockery::mock(RefundReasonMapper::class);
    $this->eligibility = Mockery::mock(RefundEligibilityChecker::class);
    $this->refundMeta = Mockery::mock(RefundStatusMeta::class);
    $this->fiscalMeta = Mockery::mock(FiscalStatusMeta::class);
    // Permissive logger; tests assert logger calls when relevant.
    // `byDefault()` lets per-test expects() override the permissive allows
    // (otherwise allows consumes the call and expects fails its count).
    $this->logger = Mockery::mock(\BlobSolutions\WooCommerceVcrAm\Logging\Logger::class);
    $this->logger->allows('warning')->byDefault();
    $this->logger->allows('error')->byDefault();
    $this->logger->allows('info')->byDefault();

    $this->job = new RefundJob(
        configuration: $this->config,
        registrarFactory: $this->registrarFactory,
        paymentMapper: $this->paymentMapper,
        reasonMapper: $this->reasonMapper,
        eligibilityChecker: $this->eligibility,
        refundMeta: $this->refundMeta,
        fiscalMeta: $this->fiscalMeta,
        logger: $this->logger,
    );
});

/**
 * Wire wc_get_order to resolve the given refund + parent ids. The job
 * calls wc_get_order twice: once for the refund itself, once for its
 * parent. Centralising the dispatch here keeps tests readable.
 */
function wireGetOrder(int $refundId, ?WC_Order_Refund $refund, ?int $parentId = null, ?WC_Order $parent = null): void
{
    Functions\when('wc_get_order')->alias(static function (int $id) use ($refundId, $refund, $parentId, $parent) {
        if ($id === $refundId) {
            return $refund;
        }
        if ($parentId !== null && $id === $parentId) {
            return $parent;
        }

        return null;
    });
}

function makeRefundApiException(int $statusCode): VcrApiException
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

it('returns failed when wc_get_order does not return a WC_Order_Refund', function (): void {
    Functions\when('wc_get_order')->justReturn(null);

    $outcome = $this->job->run(404);

    expect($outcome->status)->toBe(FiscalStatus::Failed)
        ->and($outcome->reason)->toContain('not found');
});

it('short-circuits to success when refund is already registered (idempotent)', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    wireGetOrder(123, $refund);

    $this->refundMeta->expects('status')->with($refund)->andReturn(FiscalStatus::Success);
    // No registrar call expected.
    $this->registrarFactory->shouldNotReceive('create');

    $outcome = $this->job->run(123);

    expect($outcome->status)->toBe(FiscalStatus::Success);
});

it('marks failed when parent order cannot be resolved', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_parent_id')->andReturn(999);
    wireGetOrder(123, $refund, parentId: 999, parent: null);

    $this->refundMeta->allows('status')->andReturn(null);
    $this->refundMeta->expects('markFailed')
        ->withArgs(fn ($r, $msg) => $r === $refund && str_contains($msg, 'Parent order #999'));

    $outcome = $this->job->run(123);

    expect($outcome->status)->toBe(FiscalStatus::Failed);
});

it('routes to ManualRequired when configuration is incomplete', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_parent_id')->andReturn(50);

    $parent = Mockery::mock(WC_Order::class);
    $parent->allows('get_type')->andReturn('shop_order');

    wireGetOrder(123, $refund, parentId: 50, parent: $parent);

    $this->refundMeta->allows('status')->andReturn(null);
    $this->config->expects('apiKey')->andReturn(null);
    $this->config->expects('isFullyConfigured')->andReturn(false);
    $this->refundMeta->expects('markManualRequired')
        ->withArgs(fn ($r, $msg) => $r === $refund && str_contains($msg, 'not fully configured'));

    $outcome = $this->job->run(123);

    expect($outcome->status)->toBe(FiscalStatus::ManualRequired);
});

it('routes to ManualRequired when eligibility check fails (partial refund)', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_parent_id')->andReturn(50);
    $parent = Mockery::mock(WC_Order::class);
    $parent->allows('get_type')->andReturn('shop_order');
    wireGetOrder(123, $refund, parentId: 50, parent: $parent);

    $this->refundMeta->allows('status')->andReturn(null);
    $this->config->allows('apiKey')->andReturn('key-abc');
    $this->config->allows('isFullyConfigured')->andReturn(true);

    $this->eligibility->expects('check')
        ->with($refund, $parent)
        ->andReturn(RefundEligibility::ineligible('Partial refund — manual handling required.'));

    $this->refundMeta->expects('markManualRequired')
        ->withArgs(fn ($r, $msg) => $r === $refund && str_contains($msg, 'Partial refund'));

    $outcome = $this->job->run(123);

    expect($outcome->status)->toBe(FiscalStatus::ManualRequired);
});

it('happy path: registers a full refund and marks success', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_parent_id')->andReturn(50);
    $refund->allows('get_id')->andReturn(123);
    $refund->allows('get_reason')->andReturn('customer changed mind');

    $parent = Mockery::mock(WC_Order::class);
    $parent->allows('get_type')->andReturn('shop_order');
    $parent->allows('get_id')->andReturn(50);
    $parent->allows('add_order_note');

    wireGetOrder(123, $refund, parentId: 50, parent: $parent);

    $this->refundMeta->expects('status')->with($refund)->andReturn(null);
    $this->config->expects('apiKey')->andReturn('key-abc');
    $this->config->expects('isFullyConfigured')->andReturn(true);
    $this->config->expects('defaultCashierId')->andReturn(7);
    $this->fiscalMeta->expects('saleId')->with($parent)->andReturn(54321);

    $this->eligibility->expects('check')->andReturn(RefundEligibility::full());
    $this->reasonMapper->expects('map')->andReturn(RefundReason::CustomerRequest);
    $this->paymentMapper->expects('map')->with($parent, $refund)
        ->andReturn(new RefundAmount(nonCash: '100'));

    $this->refundMeta->expects('recordAttempt');
    $this->refundMeta->expects('attemptCount')->andReturn(1);

    $registrar = Mockery::mock(SaleRefundRegistrar::class);
    $this->registrarFactory->expects('create')->with('key-abc')->andReturn($registrar);

    $response = new RegisterSaleRefundResponse(
        urlId: 'rfd-1',
        saleRefundId: 999,
        crn: 'REF-CRN',
        receiptId: 5050,
        fiscal: 'REF-FISC',
    );
    $registrar->expects('registerSaleRefund')->andReturn($response);

    $this->refundMeta->expects('markSuccess')->with($refund, $response);

    $outcome = $this->job->run(123);

    expect($outcome->status)->toBe(FiscalStatus::Success);
});

it('routes 5xx error to retriable when budget remains', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_parent_id')->andReturn(50);
    $refund->allows('get_id')->andReturn(123);
    $refund->allows('get_reason')->andReturn('');

    $parent = Mockery::mock(WC_Order::class);
    $parent->allows('get_type')->andReturn('shop_order');
    $parent->allows('get_id')->andReturn(50);
    $parent->allows('add_order_note');

    wireGetOrder(123, $refund, parentId: 50, parent: $parent);

    $this->refundMeta->allows('status')->andReturn(null);
    $this->config->allows('apiKey')->andReturn('key-abc');
    $this->config->allows('isFullyConfigured')->andReturn(true);
    $this->config->allows('defaultCashierId')->andReturn(7);
    $this->fiscalMeta->allows('saleId')->andReturn(54321);

    $this->eligibility->allows('check')->andReturn(RefundEligibility::full());
    $this->reasonMapper->allows('map')->andReturn(RefundReason::CustomerRequest);
    $this->paymentMapper->allows('map')->andReturn(new RefundAmount(nonCash: '100'));

    $this->refundMeta->allows('recordAttempt');
    // Attempt 2 of MAX_ATTEMPTS=6 — still has budget
    $this->refundMeta->allows('attemptCount')->andReturn(2);

    $registrar = Mockery::mock(SaleRefundRegistrar::class);
    $registrar->allows('registerSaleRefund')->andThrow(makeRefundApiException(503));
    $this->registrarFactory->allows('create')->andReturn($registrar);

    $this->refundMeta->expects('markRetriableFailure');

    $outcome = $this->job->run(123);

    expect($outcome->status)->toBe(FiscalStatus::Pending)
        ->and($outcome->shouldRetry())->toBeTrue();
});

it('flips to Failed when MAX_ATTEMPTS exhausted on retriable error (no stale-skip for refunds)', function (): void {
    // Critical refund-specific behaviour: we DO NOT silently skip on a
    // stale window — only MAX_ATTEMPTS exhaustion can give up. This
    // test pins that contract: exhausted budget → Failed, period.
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_parent_id')->andReturn(50);
    $refund->allows('get_id')->andReturn(123);
    $refund->allows('get_reason')->andReturn('');

    $parent = Mockery::mock(WC_Order::class);
    $parent->allows('get_type')->andReturn('shop_order');
    $parent->allows('get_id')->andReturn(50);
    $parent->allows('add_order_note');

    wireGetOrder(123, $refund, parentId: 50, parent: $parent);

    $this->refundMeta->allows('status')->andReturn(null);
    $this->config->allows('apiKey')->andReturn('key-abc');
    $this->config->allows('isFullyConfigured')->andReturn(true);
    $this->config->allows('defaultCashierId')->andReturn(7);
    $this->fiscalMeta->allows('saleId')->andReturn(54321);
    $this->eligibility->allows('check')->andReturn(RefundEligibility::full());
    $this->reasonMapper->allows('map')->andReturn(RefundReason::CustomerRequest);
    $this->paymentMapper->allows('map')->andReturn(new RefundAmount(nonCash: '100'));
    $this->refundMeta->allows('recordAttempt');
    $this->refundMeta->allows('attemptCount')->andReturn(RefundJob::MAX_ATTEMPTS);

    $registrar = Mockery::mock(SaleRefundRegistrar::class);
    $registrar->allows('registerSaleRefund')->andThrow(makeRefundApiException(503));
    $this->registrarFactory->allows('create')->andReturn($registrar);

    $this->refundMeta->expects('markFailed')
        ->withArgs(fn ($r, $msg) => $r === $refund && str_contains($msg, 'Gave up after'));

    $outcome = $this->job->run(123);

    expect($outcome->status)->toBe(FiscalStatus::Failed);
});

it('routes 4xx to terminal failure on first attempt (non-retriable)', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_parent_id')->andReturn(50);
    $refund->allows('get_id')->andReturn(123);
    $refund->allows('get_reason')->andReturn('');

    $parent = Mockery::mock(WC_Order::class);
    $parent->allows('get_type')->andReturn('shop_order');
    $parent->allows('get_id')->andReturn(50);
    $parent->allows('add_order_note');

    wireGetOrder(123, $refund, parentId: 50, parent: $parent);

    $this->refundMeta->allows('status')->andReturn(null);
    $this->config->allows('apiKey')->andReturn('key-abc');
    $this->config->allows('isFullyConfigured')->andReturn(true);
    $this->config->allows('defaultCashierId')->andReturn(7);
    $this->fiscalMeta->allows('saleId')->andReturn(54321);
    $this->eligibility->allows('check')->andReturn(RefundEligibility::full());
    $this->reasonMapper->allows('map')->andReturn(RefundReason::CustomerRequest);
    $this->paymentMapper->allows('map')->andReturn(new RefundAmount(nonCash: '100'));
    $this->refundMeta->allows('recordAttempt');
    $this->refundMeta->allows('attemptCount')->andReturn(1);

    $registrar = Mockery::mock(SaleRefundRegistrar::class);
    $registrar->allows('registerSaleRefund')->andThrow(makeRefundApiException(400));
    $this->registrarFactory->allows('create')->andReturn($registrar);

    $this->refundMeta->expects('markFailed');

    $outcome = $this->job->run(123);

    expect($outcome->status)->toBe(FiscalStatus::Failed);
});

it('treats network exceptions as retriable', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_parent_id')->andReturn(50);
    $refund->allows('get_id')->andReturn(123);
    $refund->allows('get_reason')->andReturn('');

    $parent = Mockery::mock(WC_Order::class);
    $parent->allows('get_type')->andReturn('shop_order');
    $parent->allows('get_id')->andReturn(50);
    $parent->allows('add_order_note');

    wireGetOrder(123, $refund, parentId: 50, parent: $parent);

    $this->refundMeta->allows('status')->andReturn(null);
    $this->config->allows('apiKey')->andReturn('key-abc');
    $this->config->allows('isFullyConfigured')->andReturn(true);
    $this->config->allows('defaultCashierId')->andReturn(7);
    $this->fiscalMeta->allows('saleId')->andReturn(54321);
    $this->eligibility->allows('check')->andReturn(RefundEligibility::full());
    $this->reasonMapper->allows('map')->andReturn(RefundReason::CustomerRequest);
    $this->paymentMapper->allows('map')->andReturn(new RefundAmount(nonCash: '100'));
    $this->refundMeta->allows('recordAttempt');
    $this->refundMeta->allows('attemptCount')->andReturn(1);

    $registrar = Mockery::mock(SaleRefundRegistrar::class);
    $registrar->allows('registerSaleRefund')
        ->andThrow(new VcrNetworkException(
            Mockery::mock(RequestInterface::class),
            new RuntimeException('connection timeout'),
        ));
    $this->registrarFactory->allows('create')->andReturn($registrar);

    $this->refundMeta->expects('markRetriableFailure');

    $outcome = $this->job->run(123);

    expect($outcome->status)->toBe(FiscalStatus::Pending);
});

it('treats RuntimeException (unknown) as retriable for fresh-worker resilience', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_parent_id')->andReturn(50);
    $refund->allows('get_id')->andReturn(123);
    $refund->allows('get_reason')->andReturn('');

    $parent = Mockery::mock(WC_Order::class);
    $parent->allows('get_type')->andReturn('shop_order');
    $parent->allows('get_id')->andReturn(50);
    $parent->allows('add_order_note');

    wireGetOrder(123, $refund, parentId: 50, parent: $parent);

    $this->refundMeta->allows('status')->andReturn(null);
    $this->config->allows('apiKey')->andReturn('key-abc');
    $this->config->allows('isFullyConfigured')->andReturn(true);
    $this->config->allows('defaultCashierId')->andReturn(7);
    $this->fiscalMeta->allows('saleId')->andReturn(54321);
    $this->eligibility->allows('check')->andReturn(RefundEligibility::full());
    $this->reasonMapper->allows('map')->andReturn(RefundReason::CustomerRequest);
    $this->paymentMapper->allows('map')->andReturn(new RefundAmount(nonCash: '100'));
    $this->refundMeta->allows('recordAttempt');
    $this->refundMeta->allows('attemptCount')->andReturn(1);

    $registrar = Mockery::mock(SaleRefundRegistrar::class);
    $registrar->allows('registerSaleRefund')->andThrow(new RuntimeException('worker oom'));
    $this->registrarFactory->allows('create')->andReturn($registrar);

    $this->refundMeta->expects('markRetriableFailure');

    $outcome = $this->job->run(123);

    expect($outcome->status)->toBe(FiscalStatus::Pending);
});

it('routes FiscalBuildException to ManualRequired (saleId disappeared between gates)', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_parent_id')->andReturn(50);
    $refund->allows('get_id')->andReturn(123);
    $refund->allows('get_reason')->andReturn('');

    $parent = Mockery::mock(WC_Order::class);
    $parent->allows('get_type')->andReturn('shop_order');
    $parent->allows('get_id')->andReturn(50);

    wireGetOrder(123, $refund, parentId: 50, parent: $parent);

    $this->refundMeta->allows('status')->andReturn(null);
    $this->config->allows('apiKey')->andReturn('key-abc');
    $this->config->allows('isFullyConfigured')->andReturn(true);
    $this->config->allows('defaultCashierId')->andReturn(7);
    // Eligibility passed but fiscalMeta now returns null saleId
    // (corrupted state, race, etc.) — buildPayload throws.
    $this->fiscalMeta->allows('saleId')->andReturn(null);
    $this->eligibility->allows('check')->andReturn(RefundEligibility::full());

    $this->refundMeta->expects('markManualRequired')
        ->withArgs(fn ($r, $msg) => str_contains($msg, 'no SRC saleId'));

    $outcome = $this->job->run(123);

    expect($outcome->status)->toBe(FiscalStatus::ManualRequired);
});
