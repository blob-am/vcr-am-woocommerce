<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Tests\Unit\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalJob;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalJobOutcome;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalQueue;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Journal\Journal;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use WC_Order;

beforeEach(function (): void {
    $this->job = Mockery::mock(FiscalJob::class);
    $this->meta = Mockery::mock(FiscalStatusMeta::class);
    $this->journal = Mockery::spy(Journal::class);
    $this->journal->allows('during')->andReturnUsing(static fn (?int $orderId, callable $job): mixed => $job());
    $this->queue = new FiscalQueue($this->job, $this->meta, $this->journal);
});

it('register hooks the action handler on the right hook', function (): void {
    Actions\expectAdded(FiscalQueue::ACTION_HOOK)->once();

    $this->queue->register();
});

it('enqueue is a no-op when wc_get_order returns null', function (): void {
    Functions\when('wc_get_order')->justReturn(null);
    Functions\expect('as_enqueue_async_action')->never();

    $this->queue->enqueue(123);
});

it('enqueue skips orders already in a terminal state', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_type')->andReturn('shop_order');
    $order->allows('get_status')->andReturn('processing');
    $order->allows('get_payment_method')->andReturn('vtbam');
    Functions\when('wc_get_order')->justReturn($order);

    $this->meta->allows('status')->with($order)->andReturn(FiscalStatus::Success);

    Functions\expect('as_enqueue_async_action')->never();
    $this->meta->expects('initialize')->never();

    $this->queue->enqueue(123);
});

it('enqueue skips when a pending action is already scheduled (dedup)', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_type')->andReturn('shop_order');
    $order->allows('get_status')->andReturn('processing');
    $order->allows('get_payment_method')->andReturn('vtbam');
    Functions\when('wc_get_order')->justReturn($order);

    $this->meta->allows('status')->with($order)->andReturn(null);
    Functions\when('as_get_scheduled_actions')->justReturn([42]);

    $this->meta->expects('initialize')->never();
    Functions\expect('as_enqueue_async_action')->never();

    $this->queue->enqueue(123);
});

it('enqueue skips when an action is already in-progress — race fix', function (): void {
    // Regression guard for the WC payment_complete() double-fire race:
    //   - hook A fires woocommerce_payment_complete  -> enqueue(123)
    //   - AS picks up the action, marks it in-progress, starts the
    //     network call to SRC
    //   - hook B fires woocommerce_order_status_processing  -> enqueue(123)
    //
    // Without the in-progress half of the dedup check, hook B's enqueue
    // would slip through (status still Pending in our meta because
    // markSuccess hasn't run yet) and we'd transmit the same sale to SRC
    // twice. The mock returns a non-empty result, simulating an
    // in-progress action present at lookup time.
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_type')->andReturn('shop_order');
    $order->allows('get_status')->andReturn('processing');
    $order->allows('get_payment_method')->andReturn('vtbam');
    Functions\when('wc_get_order')->justReturn($order);

    $this->meta->allows('status')->with($order)->andReturn(FiscalStatus::Pending);

    Functions\expect('as_get_scheduled_actions')
        ->once()
        ->with(Mockery::on(static function (array $args): bool {
            // Verify the call asks AS for BOTH pending and in-progress.
            // If the implementation regresses to pending-only, this test
            // catches it.
            return isset($args['status'])
                && is_array($args['status'])
                && in_array('pending', $args['status'], true)
                && in_array('in-progress', $args['status'], true)
                && ($args['hook'] ?? null) === FiscalQueue::ACTION_HOOK
                && ($args['args'] ?? null) === [123];
        }), 'ids')
        ->andReturn([99]); // simulating: in-progress action with id 99 exists

    $this->meta->expects('initialize')->never();
    Functions\expect('as_enqueue_async_action')->never();

    $this->queue->enqueue(123);
});

it('enqueue initialises meta and schedules the action on a fresh order', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_type')->andReturn('shop_order');
    $order->allows('get_status')->andReturn('processing');
    $order->allows('get_payment_method')->andReturn('vtbam');
    Functions\when('wc_get_order')->justReturn($order);

    $this->meta->allows('status')->with($order)->andReturn(null);
    Functions\when('as_get_scheduled_actions')->justReturn([]);

    $this->meta->expects('initialize')->with($order);
    Functions\expect('as_enqueue_async_action')
        ->once()
        ->with(FiscalQueue::ACTION_HOOK, [123], FiscalQueue::ACTION_GROUP);

    $this->queue->enqueue(123);
});

it('hasScheduledAction tolerates a non-array AS return (defensive)', function (): void {
    // Some AS storage backends have historically returned 0/false on
    // empty result. The dedup check must not double-enqueue if AS hands
    // back an unexpected falsy non-array.
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_type')->andReturn('shop_order');
    $order->allows('get_status')->andReturn('processing');
    $order->allows('get_payment_method')->andReturn('vtbam');
    Functions\when('wc_get_order')->justReturn($order);

    $this->meta->allows('status')->with($order)->andReturn(null);
    Functions\when('as_get_scheduled_actions')->justReturn(0);

    $this->meta->expects('initialize')->with($order);
    Functions\expect('as_enqueue_async_action')->once();

    $this->queue->enqueue(123);
});

it('handle ignores non-int args (defensive against misregistered actions)', function (): void {
    $this->job->expects('run')->never();

    $this->queue->handle('not-an-int');
});

it('handle does not reschedule on a terminal outcome', function (): void {
    $this->job->expects('run')->with(123)->andReturn(FiscalJobOutcome::success());
    Functions\expect('as_schedule_single_action')->never();

    $this->queue->handle(123);
});

it('handle schedules the next attempt with the configured delay on a retriable outcome', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_type')->andReturn('shop_order');
    $order->allows('get_status')->andReturn('processing');
    $order->allows('get_payment_method')->andReturn('vtbam');

    $this->job->expects('run')->with(123)->andReturn(FiscalJobOutcome::retriable('try again'));

    Functions\when('wc_get_order')->justReturn($order);
    $this->meta->allows('attemptCount')->with($order)->andReturn(1);

    Functions\expect('as_schedule_single_action')
        ->once()
        ->with(
            // First retry slot is 15 seconds.
            Mockery::on(static fn (int $ts): bool => $ts >= time() + 14 && $ts <= time() + 16),
            FiscalQueue::ACTION_HOOK,
            [123],
            FiscalQueue::ACTION_GROUP,
        );

    $this->queue->handle(123);
});

it('handle does not reschedule when the attempt index is out of bounds', function (): void {
    // FiscalJob should mark Failed itself in this case — but if it ever
    // returns retriable beyond the budget, the queue must NOT loop.
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_type')->andReturn('shop_order');
    $order->allows('get_status')->andReturn('processing');
    $order->allows('get_payment_method')->andReturn('vtbam');

    $this->job->expects('run')->with(123)->andReturn(FiscalJobOutcome::retriable('odd'));

    Functions\when('wc_get_order')->justReturn($order);
    $this->meta->allows('attemptCount')->with($order)->andReturn(99);

    Functions\expect('as_schedule_single_action')->never();

    $this->queue->handle(123);
});

describe('journal', function (): void {
    it('records why an already settled order was not queued again', function (): void {
        $order = Mockery::mock(WC_Order::class);
        $order->allows('get_type')->andReturn('shop_order');
        $order->allows('get_status')->andReturn('completed');
        $order->allows('get_payment_method')->andReturn('vtbam');
        Functions\when('wc_get_order')->justReturn($order);
        $this->meta->allows('status')->with($order)->andReturn(FiscalStatus::Success);

        $this->queue->enqueue(8402);

        $this->journal->shouldHaveReceived('record')->once()->with('order.skipped', 8402, [
            'order_status' => 'completed',
            'payment_method' => 'vtbam',
            'reason' => 'already_settled',
            'fiscal_status' => 'success',
        ]);
    });

    it('records a queued order with the state it was seen in', function (): void {
        $order = Mockery::mock(WC_Order::class);
        $order->allows('get_type')->andReturn('shop_order');
        $order->allows('get_status')->andReturn('processing');
        $order->allows('get_payment_method')->andReturn('vtbam');
        Functions\when('wc_get_order')->justReturn($order);
        Functions\when('as_get_scheduled_actions')->justReturn([]);
        Functions\when('as_enqueue_async_action')->justReturn(1);
        $this->meta->allows('status')->with($order)->andReturn(null);
        $this->meta->allows('initialize');

        $this->queue->enqueue(8402);

        $this->journal->shouldHaveReceived('record')->once()
            ->with('order.queued', 8402, ['order_status' => 'processing', 'payment_method' => 'vtbam']);
    });

    it('records the outcome of every attempt', function (): void {
        $this->job->allows('run')->with(8402)->andReturn(FiscalJobOutcome::failed('Cashier 3 does not exist'));

        $this->queue->handle(8402);

        $this->journal->shouldHaveReceived('record')->once()->with('order.attempt', 8402, [
            'fiscal_status' => 'failed',
            'reason' => 'Cashier 3 does not exist',
        ]);
    });
});

it('runs the job inside the order it belongs to, so its API calls are filed there', function (): void {
    $this->job->allows('run')->andReturn(FiscalJobOutcome::success());

    $this->queue->handle(8402);

    $this->journal->shouldHaveReceived('during')->once()->with(8402, Mockery::type('callable'));
});
