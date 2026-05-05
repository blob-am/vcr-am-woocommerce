<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Admin\OrdersListColumn;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

function captureColumnOutput(callable $fn): string
{
    ob_start();
    $fn();

    return (string) ob_get_clean();
}

it('register hooks all four list-table integration points', function (): void {
    Filters\expectAdded('woocommerce_shop_order_list_table_columns')->once();
    Actions\expectAdded('woocommerce_shop_order_list_table_custom_column')->once();
    Filters\expectAdded('manage_edit-shop_order_columns')->once();
    Actions\expectAdded('manage_shop_order_posts_custom_column')->once();

    $meta = Mockery::mock(FiscalStatusMeta::class);
    (new OrdersListColumn($meta))->register();
});

it('addColumn injects the Fiscal column directly after order_status (HPOS naming)', function (): void {
    $meta = Mockery::mock(FiscalStatusMeta::class);
    $columns = [
        'cb' => '',
        'order_number' => 'Order',
        'order_status' => 'Status',
        'order_total' => 'Total',
    ];

    $result = (new OrdersListColumn($meta))->addColumn($columns);

    $keys = array_keys($result);
    expect($keys)->toBe(['cb', 'order_number', 'order_status', OrdersListColumn::COLUMN_KEY, 'order_total']);
    expect($result[OrdersListColumn::COLUMN_KEY])->toBe('Fiscal');
});

it('addColumn injects after legacy "status" key when HPOS naming absent', function (): void {
    $meta = Mockery::mock(FiscalStatusMeta::class);
    $columns = [
        'cb' => '',
        'order_title' => 'Order',
        'status' => 'Status',
        'total' => 'Total',
    ];

    $result = (new OrdersListColumn($meta))->addColumn($columns);

    $keys = array_keys($result);
    $statusIdx = (int) array_search('status', $keys, true);
    $vcrIdx = (int) array_search(OrdersListColumn::COLUMN_KEY, $keys, true);

    expect($vcrIdx)->toBe($statusIdx + 1);
});

it('addColumn appends to the end when no status column found (other plugins rearranged)', function (): void {
    $meta = Mockery::mock(FiscalStatusMeta::class);
    $columns = ['cb' => '', 'something_custom' => 'X', 'something_else' => 'Y'];

    $result = (new OrdersListColumn($meta))->addColumn($columns);

    $keys = array_keys($result);
    expect(end($keys))->toBe(OrdersListColumn::COLUMN_KEY);
});

it('addColumn handles non-array input defensively', function (): void {
    $meta = Mockery::mock(FiscalStatusMeta::class);

    $result = (new OrdersListColumn($meta))->addColumn(null);

    expect($result)->toBe([OrdersListColumn::COLUMN_KEY => 'Fiscal']);
});

// ---------- HPOS cell rendering ----------

it('renderHposCell ignores other column names', function (): void {
    $meta = Mockery::mock(FiscalStatusMeta::class);
    $meta->shouldNotReceive('status');

    $order = Mockery::mock(WC_Order::class);

    $html = captureColumnOutput(fn () => (new OrdersListColumn($meta))->renderHposCell('order_total', $order));

    expect($html)->toBe('');
});

it('renderHposCell renders a placeholder for never-enqueued orders', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $meta = Mockery::mock(FiscalStatusMeta::class);
    $meta->expects('status')->with($order)->andReturn(null);

    $html = captureColumnOutput(fn () => (new OrdersListColumn($meta))->renderHposCell(OrdersListColumn::COLUMN_KEY, $order));

    expect($html)
        ->toContain('order-status')
        ->toContain('—');
});

it('renderHposCell renders a "Registered" badge for Success orders', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $meta = Mockery::mock(FiscalStatusMeta::class);
    $meta->expects('status')->with($order)->andReturn(FiscalStatus::Success);

    $html = captureColumnOutput(fn () => (new OrdersListColumn($meta))->renderHposCell(OrdersListColumn::COLUMN_KEY, $order));

    expect($html)
        ->toContain('Registered')
        ->toContain('order-status');
});

it('renderHposCell renders distinct labels for each status', function (): void {
    $meta = Mockery::mock(FiscalStatusMeta::class);

    $cases = [
        [FiscalStatus::Pending, 'Queued'],
        [FiscalStatus::Success, 'Registered'],
        [FiscalStatus::Failed, 'Failed'],
        [FiscalStatus::ManualRequired, 'Manual'],
    ];

    foreach ($cases as [$status, $expectedLabel]) {
        $order = Mockery::mock(WC_Order::class);
        $meta->expects('status')->with($order)->andReturn($status);
        $html = captureColumnOutput(fn () => (new OrdersListColumn($meta))->renderHposCell(OrdersListColumn::COLUMN_KEY, $order));
        expect($html)->toContain($expectedLabel);
    }
});

// ---------- Legacy cell rendering ----------

it('renderLegacyCell ignores other column names', function (): void {
    $meta = Mockery::mock(FiscalStatusMeta::class);
    $meta->shouldNotReceive('status');

    $html = captureColumnOutput(fn () => (new OrdersListColumn($meta))->renderLegacyCell('order_total', 42));

    expect($html)->toBe('');
});

it('renderLegacyCell ignores non-int post id', function (): void {
    $meta = Mockery::mock(FiscalStatusMeta::class);

    $html = captureColumnOutput(fn () => (new OrdersListColumn($meta))->renderLegacyCell(OrdersListColumn::COLUMN_KEY, 'not-int'));

    expect($html)->toBe('');
});

it('renderLegacyCell resolves post id via wc_get_order then renders the badge', function (): void {
    $order = Mockery::mock(WC_Order::class);
    Functions\when('wc_get_order')->alias(fn (int $id) => $id === 42 ? $order : null);

    $meta = Mockery::mock(FiscalStatusMeta::class);
    $meta->expects('status')->with($order)->andReturn(FiscalStatus::Success);

    $html = captureColumnOutput(fn () => (new OrdersListColumn($meta))->renderLegacyCell(OrdersListColumn::COLUMN_KEY, 42));

    expect($html)->toContain('Registered');
});

it('renderLegacyCell silently skips when wc_get_order returns nothing', function (): void {
    Functions\when('wc_get_order')->justReturn(null);

    $meta = Mockery::mock(FiscalStatusMeta::class);
    $meta->shouldNotReceive('status');

    $html = captureColumnOutput(fn () => (new OrdersListColumn($meta))->renderLegacyCell(OrdersListColumn::COLUMN_KEY, 42));

    expect($html)->toBe('');
});
