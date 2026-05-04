<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Admin\FiscalizeNowHandler;
use BlobSolutions\WooCommerceVcrAm\Admin\OrderMetaBox;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalQueue;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
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

    // wp_safe_redirect + exit can't run in tests; throw a recognisable
    // exception instead so we can intercept the destination and assert.
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

/**
 * Build a handler wired to fresh mocks. The mocks are returned alongside
 * so individual tests can layer expectations.
 */
function makeHandler(): array
{
    $meta = Mockery::mock(FiscalStatusMeta::class);
    $queue = Mockery::mock(FiscalQueue::class);
    $handler = new FiscalizeNowHandler($meta, $queue);

    return [$handler, $meta, $queue];
}

it('register hooks the admin_post action with the configured action name', function (): void {
    Actions\expectAdded('admin_post_' . FiscalizeNowHandler::ACTION)->once();

    [$handler] = makeHandler();
    $handler->register();
});

it('refuses without edit_shop_orders capability (403 wp_die)', function (): void {
    Functions\when('current_user_can')->justReturn(false);

    [$handler] = makeHandler();

    expect(fn () => $handler->handle())->toThrow(RuntimeException::class, 'wp_die:403');
});

it('redirects with invalid_order notice when order_id is missing or non-numeric', function (): void {
    [$handler] = makeHandler();

    expect(fn () => $handler->handle())
        ->toThrow(RuntimeException::class, 'fiscalize_invalid_order');

    $_POST['order_id'] = 'not-a-number';
    expect(fn () => $handler->handle())
        ->toThrow(RuntimeException::class, 'fiscalize_invalid_order');
});

it('redirects with invalid_order when wc_get_order returns nothing', function (): void {
    $_POST['order_id'] = '999';
    Functions\when('wc_get_order')->justReturn(null);

    [$handler] = makeHandler();

    expect(fn () => $handler->handle())
        ->toThrow(RuntimeException::class, 'fiscalize_invalid_order');
});

it('redirects with skipped_success when the order is already Success', function (): void {
    $_POST['order_id'] = '123';

    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_edit_order_url')->andReturn('https://example.test/wp-admin/post.php?post=123');
    Functions\when('wc_get_order')->justReturn($order);

    [$handler, $meta, $queue] = makeHandler();
    $meta->expects('status')->with($order)->andReturn(FiscalStatus::Success);
    $meta->expects('resetForRetry')->never();
    $queue->expects('enqueue')->never();

    expect(fn () => $handler->handle())
        ->toThrow(RuntimeException::class, 'fiscalize_skipped_success');
});

it('redirects with skipped_state for a Pending order (queue still owns it)', function (): void {
    $_POST['order_id'] = '123';

    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_edit_order_url')->andReturn('https://example.test/wp-admin/post.php?post=123');
    Functions\when('wc_get_order')->justReturn($order);

    [$handler, $meta, $queue] = makeHandler();
    $meta->expects('status')->with($order)->andReturn(FiscalStatus::Pending);
    $meta->expects('resetForRetry')->never();
    $queue->expects('enqueue')->never();

    expect(fn () => $handler->handle())
        ->toThrow(RuntimeException::class, 'fiscalize_skipped_state');
});

it('redirects with skipped_state when the order has no fiscal meta yet', function (): void {
    $_POST['order_id'] = '123';

    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_edit_order_url')->andReturn('https://example.test/wp-admin/post.php?post=123');
    Functions\when('wc_get_order')->justReturn($order);

    [$handler, $meta, $queue] = makeHandler();
    $meta->expects('status')->with($order)->andReturn(null);
    $queue->expects('enqueue')->never();

    expect(fn () => $handler->handle())
        ->toThrow(RuntimeException::class, 'fiscalize_skipped_state');
});

it('resets meta and re-enqueues a Failed order, then redirects with success notice', function (): void {
    $_POST['order_id'] = '123';

    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_edit_order_url')->andReturn('https://example.test/wp-admin/post.php?post=123');
    Functions\when('wc_get_order')->justReturn($order);

    [$handler, $meta, $queue] = makeHandler();
    $meta->expects('status')->with($order)->andReturn(FiscalStatus::Failed);
    $meta->expects('resetForRetry')->with($order);
    $queue->expects('enqueue')->with(123);

    expect(fn () => $handler->handle())
        ->toThrow(RuntimeException::class, 'fiscalize_enqueued');
});

it('resets meta and re-enqueues a ManualRequired order', function (): void {
    $_POST['order_id'] = '123';

    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_edit_order_url')->andReturn('https://example.test/wp-admin/post.php?post=123');
    Functions\when('wc_get_order')->justReturn($order);

    [$handler, $meta, $queue] = makeHandler();
    $meta->expects('status')->with($order)->andReturn(FiscalStatus::ManualRequired);
    $meta->expects('resetForRetry')->with($order);
    $queue->expects('enqueue')->with(123);

    expect(fn () => $handler->handle())
        ->toThrow(RuntimeException::class, 'fiscalize_enqueued');
});

it('verifies the per-order nonce (passes through check_admin_referer)', function (): void {
    $_POST['order_id'] = '123';

    $captured = null;
    Functions\when('check_admin_referer')->alias(function (string $action) use (&$captured): int {
        $captured = $action;

        return 1;
    });

    Functions\when('wc_get_order')->justReturn(null);

    [$handler] = makeHandler();
    try {
        $handler->handle();
    } catch (RuntimeException) {
        // expected — handler redirects to invalid_order after the nonce check.
    }

    // Per-order nonce: action name carries the order id so a leaked
    // nonce can't be replayed against an unrelated order.
    expect($captured)->toBe(FiscalizeNowHandler::NONCE_ACTION . '_123');
});

it('appends the OrderMetaBox notice query param to the redirect URL', function (): void {
    $_POST['order_id'] = '123';

    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_edit_order_url')->andReturn('https://example.test/wp-admin/post.php?post=123');
    Functions\when('wc_get_order')->justReturn($order);

    [$handler, $meta, $queue] = makeHandler();
    $meta->expects('status')->with($order)->andReturn(FiscalStatus::Failed);
    $meta->allows('resetForRetry');
    $queue->allows('enqueue');

    try {
        $handler->handle();
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    expect($message)
        ->toStartWith('redirect:')
        ->toContain(OrderMetaBox::NOTICE_QUERY_PARAM . '=fiscalize_enqueued');
});
