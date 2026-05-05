<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundFiscalizeNowHandler;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundQueue;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundStatusMeta;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('current_user_can')->justReturn(true);
    Functions\when('check_admin_referer')->justReturn(1);
    Functions\when('admin_url')->alias(fn (string $path = '') => 'https://example.test/wp-admin/' . $path);
    Functions\when('add_query_arg')->alias(function (string $key, string $value, string $url): string {
        $sep = str_contains($url, '?') ? '&' : '?';

        return $url . $sep . $key . '=' . $value;
    });
    Functions\when('wp_safe_redirect')->alias(function (string $url): void {
        throw new RuntimeException('redirect:' . $url);
    });
    Functions\when('wp_die')->alias(function ($msg = '', $title = '', $args = []): void {
        $code = is_array($args) && isset($args['response']) ? $args['response'] : 0;
        throw new RuntimeException('wp_die:' . $code . ':' . (is_string($msg) ? $msg : ''));
    });

    $_POST = [];
});

afterEach(function (): void {
    $_POST = [];
});

function makeRefundHandler(): array
{
    $meta = Mockery::mock(RefundStatusMeta::class);
    $queue = Mockery::mock(RefundQueue::class);
    $handler = new RefundFiscalizeNowHandler($meta, $queue);

    return [$handler, $meta, $queue];
}

it('register hooks the admin_post action with the configured action name', function (): void {
    Actions\expectAdded('admin_post_' . RefundFiscalizeNowHandler::ACTION)->once();

    [$handler] = makeRefundHandler();
    $handler->register();
});

it('refuses without edit_shop_orders capability (403 wp_die)', function (): void {
    Functions\when('current_user_can')->justReturn(false);

    [$handler] = makeRefundHandler();

    expect(fn () => $handler->handle())->toThrow(RuntimeException::class, 'wp_die:403');
});

it('redirects with refund_invalid notice when refund_id is missing or non-numeric', function (): void {
    [$handler] = makeRefundHandler();

    expect(fn () => $handler->handle())
        ->toThrow(RuntimeException::class, 'refund_invalid');

    $_POST['refund_id'] = 'not-a-number';
    expect(fn () => $handler->handle())
        ->toThrow(RuntimeException::class, 'refund_invalid');
});

it('redirects with refund_invalid when wc_get_order returns nothing', function (): void {
    $_POST['refund_id'] = '999';
    Functions\when('wc_get_order')->justReturn(null);

    [$handler] = makeRefundHandler();

    expect(fn () => $handler->handle())
        ->toThrow(RuntimeException::class, 'refund_invalid');
});

it('redirects with refund_invalid when the resolved order is not a WC_Order_Refund', function (): void {
    // Defensive: a sale id passed via leaked nonce shouldn't be retried
    // through the refund handler — distinct retry surface from the sale's
    // FiscalizeNowHandler.
    $_POST['refund_id'] = '123';
    $sale = Mockery::mock(WC_Order::class);
    Functions\when('wc_get_order')->justReturn($sale);

    [$handler] = makeRefundHandler();

    expect(fn () => $handler->handle())
        ->toThrow(RuntimeException::class, 'refund_invalid');
});

it('redirects with refund_skipped_success when refund is already Success', function (): void {
    $_POST['refund_id'] = '123';

    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_parent_id')->andReturn(50);
    $parent = Mockery::mock(WC_Order::class);
    $parent->allows('get_edit_order_url')->andReturn('https://example.test/wp-admin/order/50');
    Functions\when('wc_get_order')->alias(fn (int $id) => $id === 123 ? $refund : ($id === 50 ? $parent : null));

    [$handler, $meta] = makeRefundHandler();
    $meta->expects('status')->with($refund)->andReturn(FiscalStatus::Success);

    expect(fn () => $handler->handle())
        ->toThrow(RuntimeException::class, 'refund_skipped_success');
});

it('redirects with refund_skipped_state for Pending refunds (queue still owns them)', function (): void {
    $_POST['refund_id'] = '123';

    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_parent_id')->andReturn(50);
    $parent = Mockery::mock(WC_Order::class);
    $parent->allows('get_edit_order_url')->andReturn('https://example.test/wp-admin/order/50');
    Functions\when('wc_get_order')->alias(fn (int $id) => $id === 123 ? $refund : ($id === 50 ? $parent : null));

    [$handler, $meta] = makeRefundHandler();
    $meta->expects('status')->with($refund)->andReturn(FiscalStatus::Pending);

    expect(fn () => $handler->handle())
        ->toThrow(RuntimeException::class, 'refund_skipped_state');
});

it('resets meta and re-enqueues a Failed refund, then redirects with refund_enqueued notice', function (): void {
    $_POST['refund_id'] = '123';

    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_parent_id')->andReturn(50);
    $parent = Mockery::mock(WC_Order::class);
    $parent->allows('get_edit_order_url')->andReturn('https://example.test/wp-admin/order/50');
    Functions\when('wc_get_order')->alias(fn (int $id) => $id === 123 ? $refund : ($id === 50 ? $parent : null));

    [$handler, $meta, $queue] = makeRefundHandler();
    $meta->expects('status')->with($refund)->andReturn(FiscalStatus::Failed);
    $meta->expects('resetForRetry')->with($refund);
    $queue->expects('enqueue')->with(123);

    expect(fn () => $handler->handle())
        ->toThrow(RuntimeException::class, 'refund_enqueued');
});

it('resets meta and re-enqueues a ManualRequired refund', function (): void {
    $_POST['refund_id'] = '123';

    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_parent_id')->andReturn(50);
    $parent = Mockery::mock(WC_Order::class);
    $parent->allows('get_edit_order_url')->andReturn('https://example.test/wp-admin/order/50');
    Functions\when('wc_get_order')->alias(fn (int $id) => $id === 123 ? $refund : ($id === 50 ? $parent : null));

    [$handler, $meta, $queue] = makeRefundHandler();
    $meta->expects('status')->with($refund)->andReturn(FiscalStatus::ManualRequired);
    $meta->expects('resetForRetry')->with($refund);
    $queue->expects('enqueue')->with(123);

    expect(fn () => $handler->handle())
        ->toThrow(RuntimeException::class, 'refund_enqueued');
});

it('redirects to parent order edit screen (refunds have no standalone admin URL)', function (): void {
    // Pin the redirect target — the URL must be the PARENT's edit
    // screen, not admin.php?page=wc-orders.
    $_POST['refund_id'] = '123';

    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_parent_id')->andReturn(50);
    $parent = Mockery::mock(WC_Order::class);
    $parent->allows('get_edit_order_url')->andReturn('https://example.test/wp-admin/order/50/edit');
    Functions\when('wc_get_order')->alias(fn (int $id) => $id === 123 ? $refund : ($id === 50 ? $parent : null));

    [$handler, $meta, $queue] = makeRefundHandler();
    $meta->expects('status')->with($refund)->andReturn(FiscalStatus::Failed);
    $meta->expects('resetForRetry')->with($refund);
    $queue->expects('enqueue')->with(123);

    try {
        $handler->handle();
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('https://example.test/wp-admin/order/50/edit');

        return;
    }
    throw new RuntimeException('expected redirect did not fire');
});
