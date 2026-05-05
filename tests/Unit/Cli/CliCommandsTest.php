<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Cli\CliCommands;
use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalQueue;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundQueue;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundStatusMeta;
use Brain\Monkey\Functions;

function makeCli(): array
{
    $config = Mockery::mock(Configuration::class);
    $fiscalMeta = Mockery::mock(FiscalStatusMeta::class);
    $fiscalQueue = Mockery::mock(FiscalQueue::class);
    $refundMeta = Mockery::mock(RefundStatusMeta::class);
    $refundQueue = Mockery::mock(RefundQueue::class);
    $cli = new CliCommands($config, $fiscalMeta, $fiscalQueue, $refundMeta, $refundQueue);

    return [$cli, $config, $fiscalMeta, $fiscalQueue, $refundMeta, $refundQueue];
}

function captureCliOutput(callable $fn): string
{
    ob_start();
    $fn();

    return (string) ob_get_clean();
}

// ---------- fiscalize ----------

it('fiscalize errors when no order id is supplied', function (): void {
    [$cli] = makeCli();

    expect(fn () => $cli->fiscalize([]))->toThrow(RuntimeException::class, 'Usage:');
});

it('fiscalize errors when wc_get_order returns nothing', function (): void {
    Functions\when('wc_get_order')->justReturn(null);

    [$cli] = makeCli();

    expect(fn () => $cli->fiscalize(['999']))->toThrow(RuntimeException::class, 'not found');
});

it('fiscalize errors on a refund (wrong order type)', function (): void {
    $refund = Mockery::mock(WC_Order::class);
    $refund->allows('get_type')->andReturn('shop_order_refund');
    Functions\when('wc_get_order')->justReturn($refund);

    [$cli] = makeCli();

    expect(fn () => $cli->fiscalize(['42']))->toThrow(RuntimeException::class, 'not a shop order');
});

it('fiscalize enqueues a Pending order without resetting meta', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_type')->andReturn('shop_order');
    Functions\when('wc_get_order')->justReturn($order);

    [$cli, , $meta, $queue] = makeCli();
    $meta->expects('status')->with($order)->andReturn(FiscalStatus::Pending);
    $meta->shouldNotReceive('resetForRetry');
    $queue->expects('enqueue')->with(42);

    $output = captureCliOutput(fn () => $cli->fiscalize(['42']));

    expect($output)->toContain('enqueued');
});

it('fiscalize resets meta then enqueues a Failed order', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_type')->andReturn('shop_order');
    Functions\when('wc_get_order')->justReturn($order);

    [$cli, , $meta, $queue] = makeCli();
    $meta->expects('status')->with($order)->andReturn(FiscalStatus::Failed);
    $meta->expects('resetForRetry')->with($order);
    $queue->expects('enqueue')->with(42);

    captureCliOutput(fn () => $cli->fiscalize(['42']));
});

// ---------- fiscalize-refund ----------

it('fiscalize-refund errors on missing arg', function (): void {
    [$cli] = makeCli();

    expect(fn () => $cli->fiscalizeRefund([]))->toThrow(RuntimeException::class, 'Usage:');
});

it('fiscalize-refund errors when arg resolves to a sale (not a refund)', function (): void {
    $sale = Mockery::mock(WC_Order::class);
    Functions\when('wc_get_order')->justReturn($sale);

    [$cli] = makeCli();

    expect(fn () => $cli->fiscalizeRefund(['42']))->toThrow(RuntimeException::class, 'not a refund');
});

it('fiscalize-refund enqueues a refund (Pending case)', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    Functions\when('wc_get_order')->justReturn($refund);

    [$cli, , , , $refundMeta, $refundQueue] = makeCli();
    $refundMeta->expects('status')->with($refund)->andReturn(FiscalStatus::Pending);
    $refundMeta->shouldNotReceive('resetForRetry');
    $refundQueue->expects('enqueue')->with(99);

    captureCliOutput(fn () => $cli->fiscalizeRefund(['99']));
});

it('fiscalize-refund resets and enqueues a Failed refund', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    Functions\when('wc_get_order')->justReturn($refund);

    [$cli, , , , $refundMeta, $refundQueue] = makeCli();
    $refundMeta->expects('status')->with($refund)->andReturn(FiscalStatus::Failed);
    $refundMeta->expects('resetForRetry')->with($refund);
    $refundQueue->expects('enqueue')->with(99);

    captureCliOutput(fn () => $cli->fiscalizeRefund(['99']));
});

// ---------- retry-failed ----------

it('retry-failed reports nothing to do when no orders match', function (): void {
    Functions\when('wc_get_orders')->justReturn([]);

    [$cli] = makeCli();
    $output = captureCliOutput(fn () => $cli->retryFailed([], []));

    expect($output)->toContain('No Failed or ManualRequired');
});

it('retry-failed re-queues every matching order in non-dry-run mode', function (): void {
    $o1 = Mockery::mock(WC_Order::class);
    $o1->allows('get_id')->andReturn(1);
    $o2 = Mockery::mock(WC_Order::class);
    $o2->allows('get_id')->andReturn(2);
    Functions\when('wc_get_orders')->justReturn([$o1, $o2]);

    [$cli, , $meta, $queue] = makeCli();
    $meta->expects('resetForRetry')->with($o1);
    $meta->expects('resetForRetry')->with($o2);
    $queue->expects('enqueue')->with(1);
    $queue->expects('enqueue')->with(2);

    $output = captureCliOutput(fn () => $cli->retryFailed([], []));

    expect($output)->toContain('2 order(s) re-queued');
});

it('retry-failed in dry-run mode logs but does not enqueue', function (): void {
    $o1 = Mockery::mock(WC_Order::class);
    $o1->allows('get_id')->andReturn(1);
    Functions\when('wc_get_orders')->justReturn([$o1]);

    [$cli, , $meta, $queue] = makeCli();
    $meta->shouldNotReceive('resetForRetry');
    $queue->shouldNotReceive('enqueue');

    $output = captureCliOutput(fn () => $cli->retryFailed([], ['dry-run' => '']));

    expect($output)
        ->toContain('Would re-queue order #1')
        ->toContain('1 order(s) would be re-queued (dry-run)');
});

// ---------- status ----------

it('status emits the configured fields in the chosen format', function (): void {
    [$cli, $config] = makeCli();
    $config->allows('hasCredentials')->andReturn(true);
    $config->allows('baseUrl')->andReturn('https://vcr.am/api/v1');
    $config->allows('isTestMode')->andReturn(false);
    $config->allows('defaultCashierId')->andReturn(5);
    $config->allows('defaultDepartmentId')->andReturn(7);
    $config->allows('shippingSku')->andReturn('SHIP-1');
    $config->allows('feeSku')->andReturn(null);
    $config->allows('isFullyConfigured')->andReturn(true);

    $output = captureCliOutput(fn () => $cli->status([], []));

    // Fallback prints "key: value" per line; the `format_items` path
    // is identical content under different formatting. Either way the
    // labels and values must surface.
    expect($output)
        ->toContain('API key configured')
        ->toContain('https://vcr.am/api/v1')
        ->toContain('SHIP-1');
});
