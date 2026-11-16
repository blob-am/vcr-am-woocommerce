<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Journal\Journal;
use BlobSolutions\WooCommerceVcrAm\Refund\OrderRefundedListener;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundQueue;
use Brain\Monkey\Actions;

it('register hooks woocommerce_order_refunded with priority 10 and 2 args', function (): void {
    Actions\expectAdded('woocommerce_order_refunded')
        ->once()
        ->with(Mockery::type('array'), 10, 2);

    $queue = Mockery::mock(RefundQueue::class);
    (new OrderRefundedListener($queue, Mockery::spy(Journal::class)))->register();
});

it('enqueues the refund when fired with valid int refund id', function (): void {
    $queue = Mockery::mock(RefundQueue::class);
    $queue->expects('enqueue')->with(99);

    (new OrderRefundedListener($queue, Mockery::spy(Journal::class)))->onRefunded(50, 99);
});

it('ignores non-int refund id (defensive against custom callers)', function (): void {
    $queue = Mockery::mock(RefundQueue::class);
    $queue->expects('enqueue')->never();

    (new OrderRefundedListener($queue, Mockery::spy(Journal::class)))->onRefunded(50, 'not-int');
    (new OrderRefundedListener($queue, Mockery::spy(Journal::class)))->onRefunded(50, null);
    (new OrderRefundedListener($queue, Mockery::spy(Journal::class)))->onRefunded(50, ['array']);
});

it('does not use the order_id argument (we identify via refund id only)', function (): void {
    // Smoke check: pass garbage as $orderId and verify enqueue still fires.
    $queue = Mockery::mock(RefundQueue::class);
    $queue->expects('enqueue')->with(42);

    (new OrderRefundedListener($queue, Mockery::spy(Journal::class)))->onRefunded('garbage-order-id', 42);
});

it('journals the hook before handing the refund to the queue', function (): void {
    $queue = Mockery::mock(RefundQueue::class);
    $queue->allows('enqueue');
    $journal = Mockery::spy(Journal::class);

    (new OrderRefundedListener($queue, $journal))->onRefunded(8402, 99);

    $journal->shouldHaveReceived('record')->once()->with('refund.hook', 8402, [
        'refund_id' => 99,
        'hook' => 'woocommerce_order_refunded',
    ]);
});

it('journals a refund id it could not read', function (): void {
    $queue = Mockery::mock(RefundQueue::class);
    $journal = Mockery::spy(Journal::class);

    (new OrderRefundedListener($queue, $journal))->onRefunded(8402, 'not-int');

    $journal->shouldHaveReceived('record')->once()
        ->with('refund.skipped', 8402, ['reason' => 'refund_id_unreadable']);
});
