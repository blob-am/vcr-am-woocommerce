<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Tests\Unit\Catalog;

use BlobSolutions\WooCommerceVcrAm\Catalog\OfferTitleQueue;
use BlobSolutions\WooCommerceVcrAm\Catalog\OfferTitleSync;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;

beforeEach(function (): void {
    $this->sync = Mockery::mock(OfferTitleSync::class);
    $this->queue = new OfferTitleQueue($this->sync);
    Functions\when('as_get_scheduled_actions')->justReturn([]);
});

it('hooks the handler on its own action, not the sale or refund one', function (): void {
    Actions\expectAdded(OfferTitleQueue::ACTION_HOOK)->once();

    $this->queue->register();
});

it('enqueues a first attempt in the shared vcr group', function (): void {
    Functions\expect('as_enqueue_async_action')
        ->once()
        ->with(OfferTitleQueue::ACTION_HOOK, [1423, 0], OfferTitleQueue::ACTION_GROUP);

    $this->queue->enqueue(1423);
});

it('does not queue a second first attempt for the same product', function (): void {
    Functions\when('as_get_scheduled_actions')->justReturn([7]);
    Functions\expect('as_enqueue_async_action')->never();

    $this->queue->enqueue(1423);
});

it('runs the sync and stops when it reports done', function (): void {
    $this->sync->expects('run')->once()->with(1423)->andReturns(false);
    Functions\expect('as_schedule_single_action')->never();

    $this->queue->handle(1423, 0);
});

it('backs off on the next attempt when the sync asks to be retried', function (): void {
    $this->sync->allows('run')->andReturns(true);
    $scheduled = [];
    Functions\when('as_schedule_single_action')->alias(
        function (int $timestamp, string $hook, array $args, string $group) use (&$scheduled): int {
            $scheduled[] = [$timestamp, $hook, $args, $group];

            return 1;
        },
    );

    $before = time();
    $this->queue->handle(1423, 0);

    expect($scheduled)->toHaveCount(1)
        ->and($scheduled[0][1])->toBe(OfferTitleQueue::ACTION_HOOK)
        ->and($scheduled[0][2])->toBe([1423, 1])
        ->and($scheduled[0][3])->toBe(OfferTitleQueue::ACTION_GROUP)
        ->and($scheduled[0][0])->toBeGreaterThanOrEqual($before + 60)
        ->and($scheduled[0][0])->toBeLessThanOrEqual(time() + 60);
});

it('waits longer on each further attempt', function (): void {
    $this->sync->allows('run')->andReturns(true);
    $scheduled = [];
    Functions\when('as_schedule_single_action')->alias(
        function (int $timestamp, string $hook, array $args, string $group) use (&$scheduled): int {
            $scheduled[] = [$timestamp, $args];

            return 1;
        },
    );

    $before = time();
    $this->queue->handle(1423, 1);

    expect($scheduled[0][1])->toBe([1423, 2])
        ->and($scheduled[0][0])->toBeGreaterThanOrEqual($before + 300);
});

it('gives up after the last delay rather than retrying forever', function (): void {
    $this->sync->allows('run')->andReturns(true);
    Functions\expect('as_schedule_single_action')->never();

    $this->queue->handle(1423, 3);
});

it('ignores arguments that are not ids', function (): void {
    $this->sync->expects('run')->never();

    $this->queue->handle('1423', 0);
    $this->queue->handle(1423, 'first');
});
