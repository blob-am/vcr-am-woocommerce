<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Admin\OrdersBulkAction;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalQueue;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('current_user_can')->justReturn(true);
    Functions\when('add_query_arg')->alias(function ($args, string $url): string {
        $sep = str_contains($url, '?') ? '&' : '?';

        return $url . $sep . http_build_query($args);
    });
    $_GET = [];
});

afterEach(function (): void {
    $_GET = [];
});

function makeBulk(): array
{
    $meta = Mockery::mock(FiscalStatusMeta::class);
    $queue = Mockery::mock(FiscalQueue::class);
    $action = new OrdersBulkAction($meta, $queue);

    return [$action, $meta, $queue];
}

it('register hooks bulk-action filters on both list-table screens + admin_notices', function (): void {
    Filters\expectAdded('bulk_actions-woocommerce_page_wc-orders')->once();
    Filters\expectAdded('handle_bulk_actions-woocommerce_page_wc-orders')->once();
    Filters\expectAdded('bulk_actions-edit-shop_order')->once();
    Filters\expectAdded('handle_bulk_actions-edit-shop_order')->once();
    Actions\expectAdded('admin_notices')->once();

    [$action] = makeBulk();
    $action->register();
});

it('defineActions appends our action to whatever WC supplied', function (): void {
    [$action] = makeBulk();

    $result = $action->defineActions(['trash' => 'Move to Trash', 'mark_completed' => 'Complete']);

    expect($result)->toHaveKey(OrdersBulkAction::ACTION);
    expect($result[OrdersBulkAction::ACTION])->toBe('Retry VCR fiscalisation');
    // Existing actions preserved.
    expect($result['trash'])->toBe('Move to Trash');
});

it('defineActions handles non-array input defensively', function (): void {
    [$action] = makeBulk();

    /** @phpstan-ignore-next-line — verifying defensive narrowing */
    $result = $action->defineActions(null);

    expect($result)->toBe([OrdersBulkAction::ACTION => 'Retry VCR fiscalisation']);
});

it('handle leaves the redirect URL alone for unrelated actions', function (): void {
    [$action] = makeBulk();

    $result = $action->handle('https://example.test/wp-admin/orders', 'trash', [1, 2, 3]);

    expect($result)->toBe('https://example.test/wp-admin/orders');
});

it('handle refuses without edit_shop_orders capability', function (): void {
    Functions\when('current_user_can')->justReturn(false);

    [$action, $meta, $queue] = makeBulk();
    $meta->shouldNotReceive('resetForRetry');
    $queue->shouldNotReceive('enqueue');

    $result = $action->handle('https://example.test/orders', OrdersBulkAction::ACTION, [42]);

    expect($result)->toBe('https://example.test/orders');
});

it('handle queues Failed orders and skips already-Success ones', function (): void {
    $failed = Mockery::mock(WC_Order::class);
    $failed->allows('get_type')->andReturn('shop_order');

    $success = Mockery::mock(WC_Order::class);
    $success->allows('get_type')->andReturn('shop_order');

    Functions\when('wc_get_order')->alias(fn (int $id) => match ($id) {
        100 => $failed,
        101 => $success,
        default => null,
    });

    [$action, $meta, $queue] = makeBulk();

    $meta->expects('status')->with($failed)->andReturn(FiscalStatus::Failed);
    $meta->expects('status')->with($success)->andReturn(FiscalStatus::Success);

    $meta->expects('resetForRetry')->with($failed);
    $queue->expects('enqueue')->with(100);

    $result = $action->handle('https://example.test/orders', OrdersBulkAction::ACTION, [100, 101]);

    expect($result)
        ->toContain('vcr_bulk_queued=1')
        ->toContain('vcr_bulk_skipped=1');
});

it('handle queues ManualRequired orders too', function (): void {
    $manual = Mockery::mock(WC_Order::class);
    $manual->allows('get_type')->andReturn('shop_order');
    Functions\when('wc_get_order')->justReturn($manual);

    [$action, $meta, $queue] = makeBulk();
    $meta->expects('status')->with($manual)->andReturn(FiscalStatus::ManualRequired);
    $meta->expects('resetForRetry')->with($manual);
    $queue->expects('enqueue')->with(50);

    $result = $action->handle('https://example.test/orders', OrdersBulkAction::ACTION, [50]);

    expect($result)->toContain('vcr_bulk_queued=1');
});

it('handle skips Pending orders (queue still owns them)', function (): void {
    $pending = Mockery::mock(WC_Order::class);
    $pending->allows('get_type')->andReturn('shop_order');
    Functions\when('wc_get_order')->justReturn($pending);

    [$action, $meta, $queue] = makeBulk();
    $meta->expects('status')->with($pending)->andReturn(FiscalStatus::Pending);
    $meta->shouldNotReceive('resetForRetry');
    $queue->shouldNotReceive('enqueue');

    $result = $action->handle('https://example.test/orders', OrdersBulkAction::ACTION, [42]);

    expect($result)->toContain('vcr_bulk_skipped=1');
});

it('handle skips refunds (get_type !== shop_order)', function (): void {
    $refund = Mockery::mock(WC_Order::class);
    $refund->allows('get_type')->andReturn('shop_order_refund');
    Functions\when('wc_get_order')->justReturn($refund);

    [$action, $meta, $queue] = makeBulk();
    $meta->shouldNotReceive('status');
    $queue->shouldNotReceive('enqueue');

    $result = $action->handle('https://example.test/orders', OrdersBulkAction::ACTION, [42]);

    expect($result)->toContain('vcr_bulk_skipped=1');
});

it('handle accepts numeric string ids (WC sometimes passes strings)', function (): void {
    $failed = Mockery::mock(WC_Order::class);
    $failed->allows('get_type')->andReturn('shop_order');
    Functions\when('wc_get_order')->alias(fn (int $id) => $id === 42 ? $failed : null);

    [$action, $meta, $queue] = makeBulk();
    $meta->expects('status')->with($failed)->andReturn(FiscalStatus::Failed);
    $meta->expects('resetForRetry')->with($failed);
    $queue->expects('enqueue')->with(42);

    $result = $action->handle('https://example.test/orders', OrdersBulkAction::ACTION, ['42']);

    expect($result)->toContain('vcr_bulk_queued=1');
});

// ---------- Result notice rendering ----------

it('renderResultNotice does nothing when neither query param is present', function (): void {
    Functions\when('get_current_screen')->justReturn((object) ['id' => 'edit-shop_order']);

    [$action] = makeBulk();

    ob_start();
    $action->renderResultNotice();
    $html = (string) ob_get_clean();

    expect($html)->toBe('');
});

it('renderResultNotice emits success notice when queued > 0', function (): void {
    $_GET['vcr_bulk_queued'] = '3';
    $_GET['vcr_bulk_skipped'] = '0';
    Functions\when('_n')->alias(fn ($s, $p, int $n) => $n === 1 ? $s : $p);
    // Stub the screen check — production gates the notice to the orders
    // list screen to prevent crafted-URL spoofing on other admin pages.
    Functions\when('get_current_screen')->justReturn((object) ['id' => 'edit-shop_order']);

    [$action] = makeBulk();
    ob_start();
    $action->renderResultNotice();
    $html = (string) ob_get_clean();

    expect($html)
        ->toContain('notice-success')
        ->toContain('3 orders re-queued');
});

it('renderResultNotice emits warning notice when only skipped > 0', function (): void {
    $_GET['vcr_bulk_queued'] = '0';
    $_GET['vcr_bulk_skipped'] = '5';
    Functions\when('_n')->alias(fn ($s, $p, int $n) => $n === 1 ? $s : $p);
    Functions\when('get_current_screen')->justReturn((object) ['id' => 'woocommerce_page_wc-orders']);

    [$action] = makeBulk();
    ob_start();
    $action->renderResultNotice();
    $html = (string) ob_get_clean();

    expect($html)
        ->toContain('notice-warning')
        ->toContain('5 orders skipped');
});

it('renderResultNotice is suppressed on non-orders admin screens (spoof guard)', function (): void {
    // The crafted-URL attack: hit /wp-admin/?vcr_bulk_queued=999 from
    // any page. Without screen scoping our notice would render. With
    // it, we silently ignore the params off-screen.
    $_GET['vcr_bulk_queued'] = '999';
    Functions\when('_n')->alias(fn ($s, $p, int $n) => $n === 1 ? $s : $p);
    Functions\when('get_current_screen')->justReturn((object) ['id' => 'dashboard']);

    [$action] = makeBulk();
    ob_start();
    $action->renderResultNotice();
    $html = (string) ob_get_clean();

    expect($html)->toBe('');
});
