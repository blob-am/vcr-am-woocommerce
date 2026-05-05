<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Refund\OrderRefundedListener;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundQueue;
use Brain\Monkey\Actions;

it('register hooks woocommerce_order_refunded with priority 10 and 2 args', function (): void {
    Actions\expectAdded('woocommerce_order_refunded')
        ->once()
        ->with(Mockery::type('array'), 10, 2);

    $queue = Mockery::mock(RefundQueue::class);
    (new OrderRefundedListener($queue))->register();
});

it('enqueues the refund when fired with valid int refund id', function (): void {
    $queue = Mockery::mock(RefundQueue::class);
    $queue->expects('enqueue')->with(99);

    (new OrderRefundedListener($queue))->onRefunded(50, 99);
});

it('ignores non-int refund id (defensive against custom callers)', function (): void {
    $queue = Mockery::mock(RefundQueue::class);
    $queue->expects('enqueue')->never();

    (new OrderRefundedListener($queue))->onRefunded(50, 'not-int');
    (new OrderRefundedListener($queue))->onRefunded(50, null);
    (new OrderRefundedListener($queue))->onRefunded(50, ['array']);
});

it('does not use the order_id argument (we identify via refund id only)', function (): void {
    // Smoke check: pass garbage as $orderId and verify enqueue still fires.
    $queue = Mockery::mock(RefundQueue::class);
    $queue->expects('enqueue')->with(42);

    (new OrderRefundedListener($queue))->onRefunded('garbage-order-id', 42);
});
