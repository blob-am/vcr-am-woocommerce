<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Tests\Unit\Refund;

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalJobOutcome;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundJob;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundQueue;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundStatusMeta;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use WC_Order;
use WC_Order_Refund;

beforeEach(function (): void {
    $this->job = Mockery::mock(RefundJob::class);
    $this->meta = Mockery::mock(RefundStatusMeta::class);
    $this->queue = new RefundQueue($this->job, $this->meta);
});

it('register hooks the action handler on the right hook', function (): void {
    Actions\expectAdded(RefundQueue::ACTION_HOOK)->once();

    $this->queue->register();
});

it('enqueue is a no-op when wc_get_order returns null', function (): void {
    Functions\when('wc_get_order')->justReturn(null);
    Functions\expect('as_enqueue_async_action')->never();

    $this->queue->enqueue(123);
});

it('enqueue rejects WC_Order (not refund) — type filter prevents cross-pipeline contamination', function (): void {
    // Critical: a sale-id slipping into the refund queue must NOT
    // initialise refund meta or schedule a refund action. Without the
    // `instanceof WC_Order_Refund` guard, RefundQueue would happily
    // process anything wc_get_order returns and mark sale orders as
    // refund-pending — corrupting both pipelines.
    $sale = Mockery::mock(WC_Order::class);
    Functions\when('wc_get_order')->justReturn($sale);

    $this->meta->expects('initialize')->never();
    Functions\expect('as_enqueue_async_action')->never();

    $this->queue->enqueue(123);
});

it('enqueue skips refunds already in a terminal state (Success)', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    Functions\when('wc_get_order')->justReturn($refund);

    $this->meta->allows('status')->with($refund)->andReturn(FiscalStatus::Success);

    Functions\expect('as_enqueue_async_action')->never();
    $this->meta->expects('initialize')->never();

    $this->queue->enqueue(123);
});

it('enqueue skips refunds in Failed terminal state (admin must press Register Refund Now)', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    Functions\when('wc_get_order')->justReturn($refund);

    $this->meta->allows('status')->with($refund)->andReturn(FiscalStatus::Failed);

    Functions\expect('as_enqueue_async_action')->never();
    $this->meta->expects('initialize')->never();

    $this->queue->enqueue(123);
});

it('enqueue skips when an action is already scheduled (de-dupe)', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    Functions\when('wc_get_order')->justReturn($refund);

    $this->meta->allows('status')->with($refund)->andReturn(null);
    Functions\when('as_has_scheduled_action')->justReturn(true);

    $this->meta->expects('initialize')->never();
    Functions\expect('as_enqueue_async_action')->never();

    $this->queue->enqueue(123);
});

it('enqueue initialises meta and schedules the action on a fresh refund', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);
    Functions\when('wc_get_order')->justReturn($refund);

    $this->meta->allows('status')->with($refund)->andReturn(null);
    Functions\when('as_has_scheduled_action')->justReturn(false);

    $this->meta->expects('initialize')->with($refund);
    Functions\expect('as_enqueue_async_action')
        ->once()
        ->with(RefundQueue::ACTION_HOOK, [123], RefundQueue::ACTION_GROUP);

    $this->queue->enqueue(123);
});

it('handle ignores non-int args', function (): void {
    $this->job->expects('run')->never();

    $this->queue->handle('not-an-int');
});

it('handle does not reschedule on a terminal outcome', function (): void {
    $this->job->expects('run')->with(123)->andReturn(FiscalJobOutcome::success());
    Functions\expect('as_schedule_single_action')->never();

    $this->queue->handle(123);
});

it('handle schedules the next attempt on a retriable outcome', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);

    $this->job->expects('run')->with(123)->andReturn(FiscalJobOutcome::retriable('5xx'));

    Functions\when('wc_get_order')->justReturn($refund);
    $this->meta->allows('attemptCount')->with($refund)->andReturn(1);

    Functions\expect('as_schedule_single_action')
        ->once()
        ->withArgs(function (int $timestamp, string $hook, array $args, string $group): bool {
            // Slot 0 = 15s after the first attempt; allow ±2s scheduling jitter
            return abs($timestamp - (time() + 15)) <= 2
                && $hook === RefundQueue::ACTION_HOOK
                && $args === [123]
                && $group === RefundQueue::ACTION_GROUP;
        });

    $this->queue->handle(123);
});

it('handle does not reschedule when the slot is out of range (last attempt already used)', function (): void {
    $refund = Mockery::mock(WC_Order_Refund::class);

    $this->job->expects('run')->with(123)->andReturn(FiscalJobOutcome::retriable('5xx'));

    Functions\when('wc_get_order')->justReturn($refund);
    // attempt 7 → slot 6 → out of bounds (RETRY_DELAYS_SECONDS has 5)
    $this->meta->allows('attemptCount')->with($refund)->andReturn(7);

    Functions\expect('as_schedule_single_action')->never();

    $this->queue->handle(123);
});
