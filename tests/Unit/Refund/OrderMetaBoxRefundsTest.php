<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Admin\OrderMetaBox;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundFiscalizeNowHandler;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundStatusMeta;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('admin_url')->alias(fn (string $path = '') => 'https://example.test/wp-admin/' . $path);
    Functions\when('wp_nonce_field')->alias(function (string $action): string {
        echo '<input type="hidden" name="_wpnonce" value="nonce-' . $action . '">';

        return '';
    });
    Functions\when('sanitize_key')->returnArg();
    Functions\when('wc_price')->alias(fn (float $amount) => '$' . number_format($amount, 2));

    $_GET = [];
});

afterEach(function (): void {
    $_GET = [];
});

/**
 * Build an order with a fiscal status of Success (so the renderer
 * proceeds past the early-return) and the given list of refunds.
 *
 * @param array<int, WC_Order_Refund> $refunds
 */
function orderWithRefunds(array $refunds): WC_Order
{
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_refunds')->andReturn($refunds);

    return $order;
}

function captureMetaBoxRender(OrderMetaBox $box, mixed $arg): string
{
    ob_start();
    $box->render($arg);

    return (string) ob_get_clean();
}

function buildBoxFor(WC_Order $order, FiscalStatus $orderStatus, RefundStatusMeta $refundMeta): OrderMetaBox
{
    $meta = Mockery::mock(FiscalStatusMeta::class);
    $meta->allows('status')->with($order)->andReturn($orderStatus);
    // Stub success-render reads with sane defaults (renderSuccess hits these)
    $meta->allows('fiscal')->andReturn('FISC-1');
    $meta->allows('crn')->andReturn('CRN-1');
    $meta->allows('urlId')->andReturn('rcpt-1');

    return new OrderMetaBox($meta, $refundMeta);
}

it('renders nothing for the refund section when the order has no refunds', function (): void {
    $order = orderWithRefunds([]);
    $refundMeta = Mockery::mock(RefundStatusMeta::class);

    $box = buildBoxFor($order, FiscalStatus::Success, $refundMeta);
    $html = captureMetaBoxRender($box, $order);

    expect($html)
        ->not->toContain('Refunds:')
        ->not->toContain('Refund #');
});

it('renders a refund block in not-yet-enqueued state when status meta is null', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_id')->andReturn(99);
    $refund->allows('get_amount')->andReturn('25.00');

    $order = orderWithRefunds([$refund]);
    $refundMeta = Mockery::mock(RefundStatusMeta::class);
    $refundMeta->expects('status')->with($refund)->andReturn(null);

    $box = buildBoxFor($order, FiscalStatus::Success, $refundMeta);
    $html = captureMetaBoxRender($box, $order);

    expect($html)
        ->toContain('Refunds:')
        ->toContain('Refund')
        ->toContain('#99')
        ->toContain('not yet enqueued');
});

it('renders Pending refund without the Register-Refund-Now button', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_id')->andReturn(99);
    $refund->allows('get_amount')->andReturn('25');

    $order = orderWithRefunds([$refund]);
    $refundMeta = Mockery::mock(RefundStatusMeta::class);
    $refundMeta->expects('status')->with($refund)->andReturn(FiscalStatus::Pending);
    $refundMeta->expects('lastError')->with($refund)->andReturn(null);

    $box = buildBoxFor($order, FiscalStatus::Success, $refundMeta);
    $html = captureMetaBoxRender($box, $order);

    expect($html)
        ->toContain('Queued for SRC registration')
        ->not->toContain('Register refund now');
});

it('renders Success refund with fiscal/crn/receipt-id row', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_id')->andReturn(99);
    $refund->allows('get_amount')->andReturn('25');

    $order = orderWithRefunds([$refund]);
    $refundMeta = Mockery::mock(RefundStatusMeta::class);
    $refundMeta->expects('status')->with($refund)->andReturn(FiscalStatus::Success);
    $refundMeta->expects('lastError')->with($refund)->andReturn(null);
    $refundMeta->expects('fiscal')->with($refund)->andReturn('REF-FISC-7');
    $refundMeta->expects('crn')->with($refund)->andReturn('REF-CRN-7');
    $refundMeta->expects('urlId')->with($refund)->andReturn('rfd-7');

    $box = buildBoxFor($order, FiscalStatus::Success, $refundMeta);
    $html = captureMetaBoxRender($box, $order);

    expect($html)
        ->toContain('Registered with SRC')
        ->toContain('REF-FISC-7')
        ->toContain('REF-CRN-7')
        ->toContain('rfd-7');
});

it('renders Failed refund with error and the Register-Refund-Now button', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_id')->andReturn(99);
    $refund->allows('get_amount')->andReturn('25');

    $order = orderWithRefunds([$refund]);
    $refundMeta = Mockery::mock(RefundStatusMeta::class);
    $refundMeta->expects('status')->with($refund)->andReturn(FiscalStatus::Failed);
    $refundMeta->expects('lastError')->with($refund)->andReturn('Gave up after 6 attempts');

    $box = buildBoxFor($order, FiscalStatus::Success, $refundMeta);
    $html = captureMetaBoxRender($box, $order);

    expect($html)
        ->toContain('Failed (retries exhausted)')
        ->toContain('Gave up after 6 attempts')
        ->toContain('Register refund now')
        ->toContain('value="' . RefundFiscalizeNowHandler::ACTION . '"');
});

it('renders ManualRequired refund with admin guidance and the retry button', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_id')->andReturn(99);
    $refund->allows('get_amount')->andReturn('25');

    $order = orderWithRefunds([$refund]);
    $refundMeta = Mockery::mock(RefundStatusMeta::class);
    $refundMeta->expects('status')->with($refund)->andReturn(FiscalStatus::ManualRequired);
    $refundMeta->expects('lastError')->with($refund)->andReturn('partial refund — admin must handle');

    $box = buildBoxFor($order, FiscalStatus::Success, $refundMeta);
    $html = captureMetaBoxRender($box, $order);

    expect($html)
        ->toContain('Needs your attention')
        ->toContain('partial refund — admin must handle')
        ->toContain('Register refund now');
});

it('iterates multiple refunds, rendering one block per refund', function (): void {
    $refund1 = Mockery::mock(WC_Order_Refund::class);
    $refund1->allows('get_id')->andReturn(98);
    $refund1->allows('get_amount')->andReturn('10');

    $refund2 = Mockery::mock(WC_Order_Refund::class);
    $refund2->allows('get_id')->andReturn(99);
    $refund2->allows('get_amount')->andReturn('20');

    $order = orderWithRefunds([$refund1, $refund2]);
    $refundMeta = Mockery::mock(RefundStatusMeta::class);
    $refundMeta->expects('status')->with($refund1)->andReturn(null);
    $refundMeta->expects('status')->with($refund2)->andReturn(null);

    $box = buildBoxFor($order, FiscalStatus::Success, $refundMeta);
    $html = captureMetaBoxRender($box, $order);

    expect($html)
        ->toContain('#98')
        ->toContain('#99');
});

it('renders refund_enqueued notice when redirect param is present', function (): void {
    $_GET[OrderMetaBox::NOTICE_QUERY_PARAM] = 'refund_enqueued';

    $order = orderWithRefunds([]);
    $refundMeta = Mockery::mock(RefundStatusMeta::class);

    $box = buildBoxFor($order, FiscalStatus::Success, $refundMeta);
    $html = captureMetaBoxRender($box, $order);

    expect($html)
        ->toContain('Refund registration re-queued')
        ->toContain('notice-success');
});

it('renders refund_skipped_state notice with warning class', function (): void {
    $_GET[OrderMetaBox::NOTICE_QUERY_PARAM] = 'refund_skipped_state';

    $order = orderWithRefunds([]);
    $refundMeta = Mockery::mock(RefundStatusMeta::class);

    $box = buildBoxFor($order, FiscalStatus::Success, $refundMeta);
    $html = captureMetaBoxRender($box, $order);

    expect($html)
        ->toContain('not in a state that can be retried')
        ->toContain('notice-warning');
});
