<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Tests\Unit\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalJob;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalJobOutcome;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalQueue;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use WC_Order;

beforeEach(function (): void {
    $this->job = Mockery::mock(FiscalJob::class);
    $this->meta = Mockery::mock(FiscalStatusMeta::class);
    $this->queue = new FiscalQueue($this->job, $this->meta);
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
    Functions\when('wc_get_order')->justReturn($order);

    $this->meta->allows('status')->with($order)->andReturn(FiscalStatus::Success);

    Functions\expect('as_enqueue_async_action')->never();
    $this->meta->expects('initialize')->never();

    $this->queue->enqueue(123);
});

it('enqueue skips when an action is already scheduled (de-dupe)', function (): void {
    $order = Mockery::mock(WC_Order::class);
    Functions\when('wc_get_order')->justReturn($order);

    $this->meta->allows('status')->with($order)->andReturn(null);
    Functions\when('as_has_scheduled_action')->justReturn(true);

    $this->meta->expects('initialize')->never();
    Functions\expect('as_enqueue_async_action')->never();

    $this->queue->enqueue(123);
});

it('enqueue initialises meta and schedules the action on a fresh order', function (): void {
    $order = Mockery::mock(WC_Order::class);
    Functions\when('wc_get_order')->justReturn($order);

    $this->meta->allows('status')->with($order)->andReturn(null);
    Functions\when('as_has_scheduled_action')->justReturn(false);

    $this->meta->expects('initialize')->with($order);
    Functions\expect('as_enqueue_async_action')
        ->once()
        ->with(FiscalQueue::ACTION_HOOK, [123], FiscalQueue::ACTION_GROUP);

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

    $this->job->expects('run')->with(123)->andReturn(FiscalJobOutcome::retriable('odd'));

    Functions\when('wc_get_order')->justReturn($order);
    $this->meta->allows('attemptCount')->with($order)->andReturn(99);

    Functions\expect('as_schedule_single_action')->never();

    $this->queue->handle(123);
});
