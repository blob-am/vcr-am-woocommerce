<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Admin\OrderMetaBox;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundStatusMeta;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('admin_url')->alias(fn (string $path = '') => 'https://example.test/wp-admin/' . $path);
    Functions\when('wp_nonce_field')->alias(function (string $action): string {
        echo '<input type="hidden" name="_wpnonce" value="nonce-' . $action . '">';

        return '';
    });
    Functions\when('sanitize_key')->returnArg();

    $_GET = [];
});

afterEach(function (): void {
    $_GET = [];
});

/**
 * Default refund-meta mock for tests that don't care about refund
 * rendering — a permissive double that won't choke on any call.
 * Phase-3e Refund test file exercises the refund render paths
 * directly with a strict double; this helper is for the sale-status
 * tests that just need OrderMetaBox to *not* crash on the new
 * second constructor arg.
 */
function defaultRefundMeta(): RefundStatusMeta
{
    return Mockery::mock(RefundStatusMeta::class);
}

/**
 * Build an OrderMetaBox with both meta deps. Optional refund meta lets
 * a test inject a strict double to assert refund-section behaviour;
 * default is the permissive helper above.
 */
function makeBox(FiscalStatusMeta $meta, ?RefundStatusMeta $refundMeta = null): OrderMetaBox
{
    return new OrderMetaBox($meta, $refundMeta ?? defaultRefundMeta());
}

/**
 * Captures the meta box HTML output via ob_start/ob_get_clean so tests
 * can assert against it as a string. Yes, asserting HTML substrings is
 * brittle; for v1 it's the cheapest signal that "the right status
 * fragment got rendered for the right state". A future refactor to
 * template files would let us assert template-name selection instead.
 *
 * If `$arg` is a WC_Order mock without a `get_refunds` stub, we add a
 * default empty-array stub here — the Phase-3e additions to OrderMetaBox
 * always iterate refunds, so any sale-status test that doesn't care
 * about refunds would otherwise blow up on a missing-method call.
 */
function captureRender(OrderMetaBox $box, mixed $arg): string
{
    if ($arg instanceof WC_Order) {
        $arg->allows('get_refunds')->andReturn([])->byDefault();
    }

    ob_start();
    $box->render($arg);

    return (string) ob_get_clean();
}

it('register hooks add_meta_boxes once', function (): void {
    Actions\expectAdded('add_meta_boxes')->once();

    $meta = Mockery::mock(FiscalStatusMeta::class);
    (makeBox($meta))->register();
});

it('addMetaBox calls add_meta_box with the dual-screen target list', function (): void {
    $captured = null;
    Functions\when('add_meta_box')->alias(function (
        string $id,
        string $title,
        callable $callback,
        $screens,
        string $context,
        string $priority,
    ) use (&$captured): void {
        $captured = compact('id', 'title', 'screens', 'context', 'priority');
    });

    $meta = Mockery::mock(FiscalStatusMeta::class);
    (makeBox($meta))->addMetaBox();

    expect($captured['id'])->toBe(OrderMetaBox::META_BOX_ID)
        // HPOS first, legacy second — keeps the new path primary.
        ->and($captured['screens'])->toBe(['woocommerce_page_wc-orders', 'shop_order'])
        ->and($captured['context'])->toBe('side')
        ->and($captured['priority'])->toBe('default');
});

it('renders an "order not available" message when given a non-order argument', function (): void {
    $meta = Mockery::mock(FiscalStatusMeta::class);
    $box = makeBox($meta);

    $html = captureRender($box, 'not-an-order');

    expect($html)->toContain('Order not available');
});

it('resolves a WP_Post via wc_get_order on the legacy edit screen', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $meta = Mockery::mock(FiscalStatusMeta::class);
    $meta->expects('status')->with($order)->andReturn(null);

    Functions\when('wc_get_order')->alias(fn (int $id) => $id === 42 ? $order : null);

    $post = new WP_Post(ID: 42);
    $html = captureRender(makeBox($meta), $post);

    expect($html)->toContain('Not yet fiscalised');
});

it('renders the "not yet fiscalised" placeholder when meta status is null', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $meta = Mockery::mock(FiscalStatusMeta::class);
    $meta->expects('status')->with($order)->andReturn(null);

    $html = captureRender(makeBox($meta), $order);

    expect($html)
        ->toContain('Not yet fiscalised')
        ->not->toContain('Fiscalize now');  // no button until terminal-failure
});

it('renders pending status with attempt count and last error', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $meta = Mockery::mock(FiscalStatusMeta::class);
    $meta->expects('status')->with($order)->andReturn(FiscalStatus::Pending);
    $meta->allows('attemptCount')->with($order)->andReturn(2);
    $meta->allows('lastError')->with($order)->andReturn('VCR API HTTP 503');

    $html = captureRender(makeBox($meta), $order);

    expect($html)
        ->toContain('Queued for fiscalisation')
        ->toContain('Attempts so far')
        ->toContain('VCR API HTTP 503')
        ->not->toContain('Fiscalize now');  // pending = queue still owns it
});

it('renders success with fiscal serial, CRN, and receipt id', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $meta = Mockery::mock(FiscalStatusMeta::class);
    $meta->expects('status')->with($order)->andReturn(FiscalStatus::Success);
    $meta->allows('fiscal')->with($order)->andReturn('99-AB-XX');
    $meta->allows('crn')->with($order)->andReturn('CRN-7');
    $meta->allows('urlId')->with($order)->andReturn('rcpt-abc-123');

    $html = captureRender(makeBox($meta), $order);

    expect($html)
        ->toContain('Registered with SRC')
        ->toContain('99-AB-XX')
        ->toContain('CRN-7')
        ->toContain('rcpt-abc-123')
        ->not->toContain('Fiscalize now');  // success = nothing to retry
});

it('renders failed status with the Fiscalize-now button', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_id')->andReturn(123);
    $meta = Mockery::mock(FiscalStatusMeta::class);
    $meta->expects('status')->with($order)->andReturn(FiscalStatus::Failed);
    $meta->allows('lastError')->with($order)->andReturn('Gave up after 6 attempts.');

    $html = captureRender(makeBox($meta), $order);

    expect($html)
        ->toContain('Failed')
        ->toContain('Gave up after 6 attempts')
        ->toContain('Fiscalize now')
        ->toContain('vcr_fiscalize_now')      // the form action name
        ->toContain('value="123"')            // the order id hidden field
        ->toContain('vcr-fiscalize-now_123'); // per-order nonce
});

it('renders manual_required with the Fiscalize-now button', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_id')->andReturn(123);
    $meta = Mockery::mock(FiscalStatusMeta::class);
    $meta->expects('status')->with($order)->andReturn(FiscalStatus::ManualRequired);
    $meta->allows('lastError')->with($order)->andReturn('Product "Foo" has no SKU.');

    $html = captureRender(makeBox($meta), $order);

    expect($html)
        ->toContain('Needs your attention')
        ->toContain('no SKU')
        ->toContain('Fiscalize now');
});

it('renders the success notice when the redirect query param is present', function (): void {
    $_GET[OrderMetaBox::NOTICE_QUERY_PARAM] = 'fiscalize_enqueued';

    $order = Mockery::mock(WC_Order::class);
    $meta = Mockery::mock(FiscalStatusMeta::class);
    $meta->allows('status')->with($order)->andReturn(null);

    $html = captureRender(makeBox($meta), $order);

    expect($html)
        ->toContain('notice-success')
        ->toContain('re-queued');
});

it('renders the warning notice when the retry was skipped', function (): void {
    $_GET[OrderMetaBox::NOTICE_QUERY_PARAM] = 'fiscalize_skipped_success';

    $order = Mockery::mock(WC_Order::class);
    $meta = Mockery::mock(FiscalStatusMeta::class);
    $meta->allows('status')->with($order)->andReturn(FiscalStatus::Success);
    $meta->allows('fiscal')->with($order)->andReturn(null);
    $meta->allows('crn')->with($order)->andReturn(null);
    $meta->allows('urlId')->with($order)->andReturn(null);

    $html = captureRender(makeBox($meta), $order);

    expect($html)
        ->toContain('notice-warning')
        ->toContain('already fiscalised');
});

it('ignores unknown notice values silently', function (): void {
    $_GET[OrderMetaBox::NOTICE_QUERY_PARAM] = 'utterly_unknown_notice';

    $order = Mockery::mock(WC_Order::class);
    $meta = Mockery::mock(FiscalStatusMeta::class);
    $meta->allows('status')->with($order)->andReturn(null);

    $html = captureRender(makeBox($meta), $order);

    // No notice-* div, just the regular content.
    expect($html)->not->toContain('notice-');
});
