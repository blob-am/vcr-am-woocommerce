<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Receipt\CustomerReceiptDisplay;
use BlobSolutions\WooCommerceVcrAm\Receipt\ReceiptUrlBuilder;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundReceiptUrlBuilder;
use Brain\Monkey\Actions;

/**
 * Build a CustomerReceiptDisplay with both URL-builder deps. Optional
 * refund builder lets refund-aware tests inject a strict double; the
 * default below is permissive so the existing sale-receipt tests keep
 * exercising sale logic without caring about refund behaviour.
 *
 * Companion to {@see makeBox()} in OrderMetaBoxTest.php — same idiom
 * for hiding the constructor change from tests that don't care.
 */
function makeDisplay(ReceiptUrlBuilder $builder, ?RefundReceiptUrlBuilder $refundBuilder = null): CustomerReceiptDisplay
{
    return new CustomerReceiptDisplay(
        $builder,
        $refundBuilder ?? Mockery::mock(RefundReceiptUrlBuilder::class),
    );
}

/**
 * WC_Order mock pre-stubbed for the receipt-display flow: returns no
 * refunds by default. Sale-only tests use this; refund-aware tests
 * override `get_refunds` after constructing.
 */
function mockOrder(): WC_Order
{
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_refunds')->andReturn([])->byDefault();

    return $order;
}

function captureDisplayOutput(callable $fn): string
{
    ob_start();
    $fn();

    return (string) ob_get_clean();
}

it('register hooks all three customer surfaces', function (): void {
    Actions\expectAdded('woocommerce_email_order_meta')->once();
    Actions\expectAdded('woocommerce_thankyou')->once();
    Actions\expectAdded('woocommerce_order_details_after_order_table')->once();

    $builder = Mockery::mock(ReceiptUrlBuilder::class);
    (makeDisplay($builder))->register();
});

// ---------- Email ----------

it('email: skips render when sent_to_admin is true', function (): void {
    $order = mockOrder();
    $builder = Mockery::mock(ReceiptUrlBuilder::class);
    // build() must NOT be called — admin emails are uninteresting.
    $builder->shouldNotReceive('build');

    $html = captureDisplayOutput(fn () => (makeDisplay($builder))
        ->renderInEmail($order, sentToAdmin: true));

    expect($html)->toBe('');
});

it('email: skips render when arg is not a WC_Order', function (): void {
    $builder = Mockery::mock(ReceiptUrlBuilder::class);
    $builder->shouldNotReceive('build');

    $html = captureDisplayOutput(fn () => (makeDisplay($builder))
        ->renderInEmail('not-an-order'));

    expect($html)->toBe('');
});

it('email: renders nothing when builder returns null (order not yet fiscalised)', function (): void {
    $order = mockOrder();
    $builder = Mockery::mock(ReceiptUrlBuilder::class);
    $builder->expects('build')->with($order)->andReturn(null);

    $html = captureDisplayOutput(fn () => (makeDisplay($builder))->renderInEmail($order));

    expect($html)->toBe('');
});

it('email: renders an HTML link block when URL is available', function (): void {
    $order = mockOrder();
    $builder = Mockery::mock(ReceiptUrlBuilder::class);
    $builder->expects('build')->with($order)->andReturn('https://vcr.am/hy/r/CRN-123/rcpt-abc');

    $html = captureDisplayOutput(fn () => (makeDisplay($builder))
        ->renderInEmail($order, sentToAdmin: false, plainText: false));

    expect($html)
        ->toContain('https://vcr.am/hy/r/CRN-123/rcpt-abc')
        ->toContain('Fiscal receipt')
        ->toContain('<a ');  // it's actually an anchor, not just text
});

it('email: renders plain-text format when plain_text flag is true', function (): void {
    $order = mockOrder();
    $builder = Mockery::mock(ReceiptUrlBuilder::class);
    $builder->expects('build')->with($order)->andReturn('https://vcr.am/hy/r/CRN-123/rcpt-abc');

    $html = captureDisplayOutput(fn () => (makeDisplay($builder))
        ->renderInEmail($order, sentToAdmin: false, plainText: true));

    expect($html)
        ->toContain('https://vcr.am/hy/r/CRN-123/rcpt-abc')
        ->not->toContain('<a ')
        ->not->toContain('<p ');
});

// ---------- Thank-you page ----------

it('thankyou: renders nothing for an invalid order id (string non-digit)', function (): void {
    $builder = Mockery::mock(ReceiptUrlBuilder::class);
    $builder->shouldNotReceive('build');

    $html = captureDisplayOutput(fn () => (makeDisplay($builder))
        ->renderOnThankYou('not-a-number'));

    expect($html)->toBe('');
});

it('thankyou: renders nothing when wc_get_order returns falsy', function (): void {
    Brain\Monkey\Functions\when('wc_get_order')->justReturn(null);

    $builder = Mockery::mock(ReceiptUrlBuilder::class);
    $builder->shouldNotReceive('build');

    $html = captureDisplayOutput(fn () => (makeDisplay($builder))->renderOnThankYou(42));

    expect($html)->toBe('');
});

it('thankyou: renders nothing when order is fiscalised but builder returns null', function (): void {
    $order = mockOrder();
    Brain\Monkey\Functions\when('wc_get_order')->justReturn($order);

    $builder = Mockery::mock(ReceiptUrlBuilder::class);
    $builder->expects('build')->with($order)->andReturn(null);

    $html = captureDisplayOutput(fn () => (makeDisplay($builder))->renderOnThankYou(42));

    expect($html)->toBe('');
});

it('thankyou: renders the call-out section with the receipt link', function (): void {
    $order = mockOrder();
    Brain\Monkey\Functions\when('wc_get_order')->justReturn($order);

    $builder = Mockery::mock(ReceiptUrlBuilder::class);
    $builder->expects('build')->with($order)->andReturn('https://vcr.am/hy/r/CRN-123/rcpt-abc');

    $html = captureDisplayOutput(fn () => (makeDisplay($builder))->renderOnThankYou(42));

    expect($html)
        ->toContain('vcr-receipt-callout')
        ->toContain('https://vcr.am/hy/r/CRN-123/rcpt-abc')
        ->toContain('Fiscal receipt');
});

it('thankyou: accepts a numeric string order id (WC sometimes passes strings)', function (): void {
    $order = mockOrder();
    Brain\Monkey\Functions\when('wc_get_order')->justReturn($order);

    $builder = Mockery::mock(ReceiptUrlBuilder::class);
    $builder->expects('build')->with($order)->andReturn('https://vcr.am/hy/r/CRN-123/rcpt-abc');

    $html = captureDisplayOutput(fn () => (makeDisplay($builder))->renderOnThankYou('42'));

    expect($html)->toContain('vcr-receipt-callout');
});

// ---------- My Account → order details ----------

it('orderDetails: renders nothing for non-WC_Order argument', function (): void {
    $builder = Mockery::mock(ReceiptUrlBuilder::class);
    $builder->shouldNotReceive('build');

    $html = captureDisplayOutput(fn () => (makeDisplay($builder))->renderInOrderDetails('whatever'));

    expect($html)->toBe('');
});

it('orderDetails: renders nothing when builder returns null', function (): void {
    $order = mockOrder();
    $builder = Mockery::mock(ReceiptUrlBuilder::class);
    $builder->expects('build')->with($order)->andReturn(null);

    $html = captureDisplayOutput(fn () => (makeDisplay($builder))->renderInOrderDetails($order));

    expect($html)->toBe('');
});

it('orderDetails: renders a paragraph link when URL is available', function (): void {
    $order = mockOrder();
    $builder = Mockery::mock(ReceiptUrlBuilder::class);
    $builder->expects('build')->with($order)->andReturn('https://vcr.am/hy/r/CRN-123/rcpt-abc');

    $html = captureDisplayOutput(fn () => (makeDisplay($builder))->renderInOrderDetails($order));

    expect($html)
        ->toContain('vcr-receipt-link')
        ->toContain('https://vcr.am/hy/r/CRN-123/rcpt-abc');
});
