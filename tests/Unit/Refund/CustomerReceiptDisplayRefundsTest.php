<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Receipt\CustomerReceiptDisplay;
use BlobSolutions\WooCommerceVcrAm\Receipt\ReceiptUrlBuilder;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundReceiptUrlBuilder;

/**
 * @param array<int, WC_Order_Refund> $refunds
 */
function orderWithRefundList(array $refunds): WC_Order
{
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_refunds')->andReturn($refunds);

    return $order;
}

function captureRefundDisplayOutput(callable $fn): string
{
    ob_start();
    $fn();

    return (string) ob_get_clean();
}

it('email: renders both sale receipt link AND refund receipt links', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_id')->andReturn(99);

    $order = orderWithRefundList([$refund]);

    $saleBuilder = Mockery::mock(ReceiptUrlBuilder::class);
    $saleBuilder->expects('build')->with($order)->andReturn('https://vcr.am/hy/r/CRN-1/rcpt-1');

    $refundBuilder = Mockery::mock(RefundReceiptUrlBuilder::class);
    $refundBuilder->expects('build')->with($refund)->andReturn('https://vcr.am/hy/r/REF-CRN-1/rfd-1');

    $display = new CustomerReceiptDisplay($saleBuilder, $refundBuilder);

    $html = captureRefundDisplayOutput(fn () => $display->renderInEmail($order));

    expect($html)
        ->toContain('https://vcr.am/hy/r/CRN-1/rcpt-1')
        ->toContain('https://vcr.am/hy/r/REF-CRN-1/rfd-1')
        ->toContain('Refund receipt')
        ->toContain('#99');
});

it('email: renders refund receipt link in plain-text format when plain_text flag is set', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_id')->andReturn(99);

    $order = orderWithRefundList([$refund]);

    $saleBuilder = Mockery::mock(ReceiptUrlBuilder::class);
    $saleBuilder->expects('build')->with($order)->andReturn(null);

    $refundBuilder = Mockery::mock(RefundReceiptUrlBuilder::class);
    $refundBuilder->expects('build')->with($refund)->andReturn('https://vcr.am/hy/r/REF-CRN-1/rfd-1');

    $display = new CustomerReceiptDisplay($saleBuilder, $refundBuilder);

    $html = captureRefundDisplayOutput(fn () => $display->renderInEmail($order, plainText: true));

    expect($html)
        ->toContain('https://vcr.am/hy/r/REF-CRN-1/rfd-1')
        ->toContain('refund #99')
        ->not->toContain('<a ')
        ->not->toContain('<p ');
});

it('email: renders sale-only when there are no refunds (no extra noise)', function (): void {
    $order = orderWithRefundList([]);

    $saleBuilder = Mockery::mock(ReceiptUrlBuilder::class);
    $saleBuilder->expects('build')->with($order)->andReturn('https://vcr.am/hy/r/CRN-1/rcpt-1');

    $refundBuilder = Mockery::mock(RefundReceiptUrlBuilder::class);
    $refundBuilder->shouldNotReceive('build');

    $display = new CustomerReceiptDisplay($saleBuilder, $refundBuilder);

    $html = captureRefundDisplayOutput(fn () => $display->renderInEmail($order));

    expect($html)
        ->toContain('https://vcr.am/hy/r/CRN-1/rcpt-1')
        ->not->toContain('Refund receipt');
});

it('email: skips refund links when builder returns null (refund not yet registered)', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);

    $order = orderWithRefundList([$refund]);

    $saleBuilder = Mockery::mock(ReceiptUrlBuilder::class);
    $saleBuilder->expects('build')->andReturn(null);

    $refundBuilder = Mockery::mock(RefundReceiptUrlBuilder::class);
    $refundBuilder->expects('build')->with($refund)->andReturn(null);

    $display = new CustomerReceiptDisplay($saleBuilder, $refundBuilder);

    $html = captureRefundDisplayOutput(fn () => $display->renderInEmail($order));

    expect($html)->toBe('');
});

it('email: still renders sale receipt link if any individual refund link is null', function (): void {
    // Two refunds: one registered, one still pending. Sale link renders,
    // first refund link renders, pending refund quietly skipped.
    $registeredRefund = Mockery::mock(WC_Order_Refund::class);
    $registeredRefund->allows('get_id')->andReturn(98);
    $pendingRefund = Mockery::mock(WC_Order_Refund::class);
    $pendingRefund->allows('get_id')->andReturn(99);

    $order = orderWithRefundList([$registeredRefund, $pendingRefund]);

    $saleBuilder = Mockery::mock(ReceiptUrlBuilder::class);
    $saleBuilder->expects('build')->with($order)->andReturn('https://vcr.am/hy/r/CRN-1/rcpt-1');

    $refundBuilder = Mockery::mock(RefundReceiptUrlBuilder::class);
    $refundBuilder->expects('build')->with($registeredRefund)->andReturn('https://vcr.am/hy/r/REF-CRN-1/rfd-98');
    $refundBuilder->expects('build')->with($pendingRefund)->andReturn(null);

    $display = new CustomerReceiptDisplay($saleBuilder, $refundBuilder);

    $html = captureRefundDisplayOutput(fn () => $display->renderInEmail($order));

    expect($html)
        ->toContain('https://vcr.am/hy/r/CRN-1/rcpt-1')
        ->toContain('https://vcr.am/hy/r/REF-CRN-1/rfd-98')
        ->toContain('#98')
        ->not->toContain('#99');
});

it('orderDetails: renders both sale and refund receipt links', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_id')->andReturn(99);

    $order = orderWithRefundList([$refund]);

    $saleBuilder = Mockery::mock(ReceiptUrlBuilder::class);
    $saleBuilder->expects('build')->with($order)->andReturn('https://vcr.am/hy/r/CRN-1/rcpt-1');

    $refundBuilder = Mockery::mock(RefundReceiptUrlBuilder::class);
    $refundBuilder->expects('build')->with($refund)->andReturn('https://vcr.am/hy/r/REF-CRN-1/rfd-1');

    $display = new CustomerReceiptDisplay($saleBuilder, $refundBuilder);

    $html = captureRefundDisplayOutput(fn () => $display->renderInOrderDetails($order));

    expect($html)
        ->toContain('vcr-receipt-link')
        ->toContain('vcr-refund-receipt-link')
        ->toContain('https://vcr.am/hy/r/CRN-1/rcpt-1')
        ->toContain('https://vcr.am/hy/r/REF-CRN-1/rfd-1');
});

it('admin email is still suppressed even if refunds exist (sent_to_admin guard)', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $order = orderWithRefundList([$refund]);

    $saleBuilder = Mockery::mock(ReceiptUrlBuilder::class);
    $saleBuilder->shouldNotReceive('build');

    $refundBuilder = Mockery::mock(RefundReceiptUrlBuilder::class);
    $refundBuilder->shouldNotReceive('build');

    $display = new CustomerReceiptDisplay($saleBuilder, $refundBuilder);

    $html = captureRefundDisplayOutput(fn () => $display->renderInEmail($order, sentToAdmin: true));

    expect($html)->toBe('');
});

it('iterates refunds skipping any non-WC_Order_Refund entry (defensive)', function (): void {
    // Defensive against custom plugins shoehorning non-refund objects
    // into get_refunds() — would be a violation but we don't crash.
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_refunds')->andReturn(['not-a-refund-object']);

    $saleBuilder = Mockery::mock(ReceiptUrlBuilder::class);
    $saleBuilder->expects('build')->with($order)->andReturn(null);

    $refundBuilder = Mockery::mock(RefundReceiptUrlBuilder::class);
    $refundBuilder->shouldNotReceive('build');

    $display = new CustomerReceiptDisplay($saleBuilder, $refundBuilder);

    $html = captureRefundDisplayOutput(fn () => $display->renderInEmail($order));

    expect($html)->toBe('');
});
