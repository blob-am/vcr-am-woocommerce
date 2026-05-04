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

    $order->expects('delete_meta_data')->with(FiscalStatusMeta::META_STATUS);
    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_ATTEMPT_COUNT, '0');
    $order->expects('update_meta_data')->with(FiscalStatusMeta::META_LAST_ERROR, '');
    $order->expects('save')->once();

    $this->meta->resetForRetry($order);
});
