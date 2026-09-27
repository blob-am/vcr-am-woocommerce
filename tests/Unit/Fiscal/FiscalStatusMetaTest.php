<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Tests\Unit\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\RegisterSaleResponse;
use Mockery;
use WC_Order;

beforeEach(function (): void {
    $this->meta = new FiscalStatusMeta();
});

it('returns null status when no meta is set', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_meta')->with(FiscalStatusMeta::META_STATUS, true)->andReturn('');

    expect($this->meta->status($order))->toBeNull();
});

it('hydrates a known status string', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_meta')->with(FiscalStatusMeta::META_STATUS, true)->andReturn('success');

    expect($this->meta->status($order))->toBe(FiscalStatus::Success);
});

it('returns null on an unknown status string (no silent fallback)', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_meta')->with(FiscalStatusMeta::META_STATUS, true)->andReturn('alien');

    expect($this->meta->status($order))->toBeNull();
});

it('parses attempt count from a string meta value', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_meta')->with(FiscalStatusMeta::META_ATTEMPT_COUNT, true)->andReturn('3');

    expect($this->meta->attemptCount($order))->toBe(3);
});

it('returns 0 attempt count for empty / non-numeric meta', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_meta')->with(FiscalStatusMeta::META_ATTEMPT_COUNT, true)->andReturn('');

    expect($this->meta->attemptCount($order))->toBe(0);
});

it('builds a deterministic external id from the order id', function (): void {
    expect(FiscalStatusMeta::buildExternalId(42))->toBe('order_42');
});

it('initialize sets pending status, zero attempts, and the external id when no prior meta exists', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_meta')->with(FiscalStatusMeta::META_STATUS, true)->andReturn('');
    $order->allows('get_id')->andReturn(7);

    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_EXTERNAL_ID, 'order_7');
    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_STATUS, 'pending');
    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_ATTEMPT_COUNT, '0');
    $order->expects('save')->once();

    $this->meta->initialize($order);
});

it('initialize is a no-op when status meta already exists', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_meta')->with(FiscalStatusMeta::META_STATUS, true)->andReturn('pending');
    $order->expects('update_meta_data')->never();
    $order->expects('save')->never();

    $this->meta->initialize($order);
});

it('recordAttempt increments the count and timestamps the attempt', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_meta')->with(FiscalStatusMeta::META_ATTEMPT_COUNT, true)->andReturn('2');

    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_ATTEMPT_COUNT, '3');
    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_LAST_ATTEMPT_AT, Mockery::type('string'));
    $order->expects('save')->once();

    $this->meta->recordAttempt($order);
});

it('markSuccess writes the SRC identifiers and clears the last error', function (): void {
    $response = new RegisterSaleResponse(
        urlId: 'abc-123',
        saleId: 99,
        crn: 'CRN-7',
        srcReceiptId: 5050,
        fiscal: '99-AB-XX',
    );

    $order = Mockery::mock(WC_Order::class);

    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_STATUS, 'success');
    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_URL_ID, 'abc-123');
    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_CRN, 'CRN-7');
    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_FISCAL, '99-AB-XX');
    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_SALE_ID, '99');
    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_SRC_RECEIPT_ID, '5050');
    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_REGISTERED_AT, Mockery::type('string'));
    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_LAST_ERROR, '');
    $order->expects('save')->once();

    $this->meta->markSuccess($order, $response);
});

it('markRetriableFailure keeps status pending and records the error', function (): void {
    $order = Mockery::mock(WC_Order::class);

    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_STATUS, 'pending');
    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_LAST_ERROR, 'temporary glitch');
    $order->expects('save')->once();

    $this->meta->markRetriableFailure($order, 'temporary glitch');
});

it('markFailed flips the status to failed', function (): void {
    $order = Mockery::mock(WC_Order::class);

    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_STATUS, 'failed');
    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_LAST_ERROR, 'rejected');
    $order->expects('save')->once();

    $this->meta->markFailed($order, 'rejected');
});

it('markManualRequired flips status and stores the operator-readable reason', function (): void {
    $order = Mockery::mock(WC_Order::class);

    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_STATUS, 'manual_required');
    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_LAST_ERROR, 'no SKU on product 5');
    $order->expects('save')->once();

    $this->meta->markManualRequired($order, 'no SKU on product 5');
});

it('resetForRetry deletes the status meta and zeroes the attempt counters', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_meta')->with(FiscalStatusMeta::META_IDEMPOTENCY_REVISION, true)->andReturn('');

    $order->expects('delete_meta_data')->with(FiscalStatusMeta::META_STATUS);
    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_ATTEMPT_COUNT, '0');
    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_LAST_ERROR, '');
    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_IDEMPOTENCY_REVISION, '1');
    $order->expects('save')->once();

    $this->meta->resetForRetry($order);
});

it('starts the idempotency key at the external id', function (): void {
    // Same string as the external id on purpose: support is handed an order
    // number, and `order_42` is what they can reconstruct from it.
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_id')->andReturn(42);
    $order->allows('get_meta')->with(FiscalStatusMeta::META_IDEMPOTENCY_REVISION, true)->andReturn('');

    expect($this->meta->idempotencyKey($order))->toBe('order_42')
        ->and($this->meta->idempotencyKey($order))->toBe(FiscalStatusMeta::buildExternalId(42));
});

it('moves the idempotency key to the next revision once the order has been re-fiscalised', function (): void {
    // A key the API has already seen is bound to the body it saw. After an
    // admin fixes whatever failed, the body differs — so the key has to.
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_id')->andReturn(42);
    $order->allows('get_meta')->with(FiscalStatusMeta::META_IDEMPOTENCY_REVISION, true)->andReturn('2');

    expect($this->meta->idempotencyRevision($order))->toBe(2)
        ->and($this->meta->idempotencyKey($order))->toBe('order_42_r2');
});

it('bumps the idempotency revision from an existing value on reset', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_meta')->with(FiscalStatusMeta::META_IDEMPOTENCY_REVISION, true)->andReturn('3');
    $order->allows('delete_meta_data');
    // `byDefault()` so the catch-all yields to the specific expectation below;
    // declared first, it would otherwise consume the call itself.
    $order->allows('update_meta_data')->byDefault();
    $order->allows('save');

    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_IDEMPOTENCY_REVISION, '4');

    $this->meta->resetForRetry($order);
});

it('keeps the key stable across attempts within one round', function (): void {
    // The property the whole mechanism rests on: a value minted per attempt
    // protects nothing. Two reads with no reset between them must agree.
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_id')->andReturn(9);
    $order->allows('get_meta')->with(FiscalStatusMeta::META_IDEMPOTENCY_REVISION, true)->andReturn('');

    $first = $this->meta->idempotencyKey($order);
    $second = $this->meta->idempotencyKey($order);

    expect($second)->toBe($first);
});
