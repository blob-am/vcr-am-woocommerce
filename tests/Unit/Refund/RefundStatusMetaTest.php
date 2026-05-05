<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Tests\Unit\Refund;

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\RegisterSaleRefundResponse;
use Mockery;
use WC_Order_Refund;

beforeEach(function (): void {
    $this->meta = new RefundStatusMeta();
});

it('returns null status when no meta is set', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_meta')->with(RefundStatusMeta::META_STATUS, true)->andReturn('');

    expect($this->meta->status($refund))->toBeNull();
});

it('hydrates a known status string', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_meta')->with(RefundStatusMeta::META_STATUS, true)->andReturn('manual_required');

    expect($this->meta->status($refund))->toBe(FiscalStatus::ManualRequired);
});

it('returns null on an unknown status string (no silent fallback)', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_meta')->with(RefundStatusMeta::META_STATUS, true)->andReturn('alien-state');

    expect($this->meta->status($refund))->toBeNull();
});

it('parses attempt count from a string meta value', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_meta')->with(RefundStatusMeta::META_ATTEMPT_COUNT, true)->andReturn('4');

    expect($this->meta->attemptCount($refund))->toBe(4);
});

it('builds a deterministic external id namespaced under refund_', function (): void {
    // Critical: must NOT collide with FiscalStatusMeta's `order_<id>`
    // namespace — refunds and parent orders share the WC posts table
    // ID space, so a refund#42 and order#42 could otherwise stamp the
    // same external_id.
    expect(RefundStatusMeta::buildExternalId(42))->toBe('refund_42');
});

it('initialize sets pending status, zero attempts, and the external id when no prior meta exists', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_meta')->with(RefundStatusMeta::META_STATUS, true)->andReturn('');
    $refund->allows('get_id')->andReturn(11);

    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_EXTERNAL_ID, 'refund_11');
    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_STATUS, 'pending');
    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_ATTEMPT_COUNT, '0');
    $refund->expects('save')->once();

    $this->meta->initialize($refund);
});

it('initialize is a no-op when status meta already exists', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_meta')->with(RefundStatusMeta::META_STATUS, true)->andReturn('pending');
    $refund->expects('update_meta_data')->never();
    $refund->expects('save')->never();

    $this->meta->initialize($refund);
});

it('recordAttempt increments the count and timestamps the attempt', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_meta')->with(RefundStatusMeta::META_ATTEMPT_COUNT, true)->andReturn('1');

    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_ATTEMPT_COUNT, '2');
    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_LAST_ATTEMPT_AT, Mockery::type('string'));
    $refund->expects('save')->once();

    $this->meta->recordAttempt($refund);
});

it('markSuccess writes the SRC refund identifiers and clears the last error', function (): void {
    $response = new RegisterSaleRefundResponse(
        urlId: 'rfd-abc-1',
        saleRefundId: 77,
        crn: 'REFUND-CRN-1',
        receiptId: 9001,
        fiscal: 'RF-FISC-1',
    );

    $refund = Mockery::mock(WC_Order_Refund::class);

    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_STATUS, 'success');
    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_URL_ID, 'rfd-abc-1');
    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_CRN, 'REFUND-CRN-1');
    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_FISCAL, 'RF-FISC-1');
    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_SALE_REFUND_ID, '77');
    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_RECEIPT_ID, '9001');
    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_REGISTERED_AT, Mockery::type('string'));
    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_LAST_ERROR, '');
    $refund->expects('save')->once();

    $this->meta->markSuccess($refund, $response);
});

it('markSuccess gracefully stores empty string when SRC returns null crn / fiscal', function (): void {
    // Per RegisterSaleRefundResponse contract, SRC may issue a refund
    // urlId without yet having allocated crn or fiscal. We persist
    // empty string so downstream consumers see "absent" via the
    // existing get_meta-empty-check.
    $response = new RegisterSaleRefundResponse(
        urlId: 'rfd-no-fiscal',
        saleRefundId: 78,
        crn: null,
        receiptId: 9002,
        fiscal: null,
    );

    $refund = Mockery::mock(WC_Order_Refund::class);

    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_STATUS, 'success');
    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_URL_ID, 'rfd-no-fiscal');
    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_CRN, '');
    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_FISCAL, '');
    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_SALE_REFUND_ID, '78');
    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_RECEIPT_ID, '9002');
    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_REGISTERED_AT, Mockery::type('string'));
    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_LAST_ERROR, '');
    $refund->expects('save')->once();

    $this->meta->markSuccess($refund, $response);
});

it('markRetriableFailure keeps status pending and records the error', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);

    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_STATUS, 'pending');
    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_LAST_ERROR, 'transient SRC 503');
    $refund->expects('save')->once();

    $this->meta->markRetriableFailure($refund, 'transient SRC 503');
});

it('markFailed flips status to failed', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);

    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_STATUS, 'failed');
    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_LAST_ERROR, 'gave up');
    $refund->expects('save')->once();

    $this->meta->markFailed($refund, 'gave up');
});

it('markManualRequired flips status to manual_required', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);

    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_STATUS, 'manual_required');
    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_LAST_ERROR, 'partial refund — admin must handle');
    $refund->expects('save')->once();

    $this->meta->markManualRequired($refund, 'partial refund — admin must handle');
});

it('resetForRetry deletes status and zeroes attempt count', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);

    $refund->expects('delete_meta_data')->with(RefundStatusMeta::META_STATUS)->once();
    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_ATTEMPT_COUNT, '0');
    $refund->expects('update_meta_data')->with(RefundStatusMeta::META_LAST_ERROR, '');
    $refund->expects('save')->once();

    $this->meta->resetForRetry($refund);
});

it('externalId returns stored value when present', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_meta')->with(RefundStatusMeta::META_EXTERNAL_ID, true)->andReturn('refund_99');

    expect($this->meta->externalId($refund))->toBe('refund_99');
});

it('externalId derives from refund id when meta is empty (lazy allocation)', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_meta')->with(RefundStatusMeta::META_EXTERNAL_ID, true)->andReturn('');
    $refund->allows('get_id')->andReturn(123);

    expect($this->meta->externalId($refund))->toBe('refund_123');
});
