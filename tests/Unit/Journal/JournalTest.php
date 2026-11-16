<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Journal\Entry;
use BlobSolutions\WooCommerceVcrAm\Journal\Journal;
use BlobSolutions\WooCommerceVcrAm\Journal\Store;
use BlobSolutions\WooCommerceVcrAm\Journal\StoreException;
use BlobSolutions\WooCommerceVcrAm\Logging\Logger;

if (! defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

/**
 * Keeps lines in memory, or refuses everything when told to.
 */
final class InMemoryStore implements Store
{
    /** @var list<array{event: string, orderId: ?int, traceId: ?string, data: array<array-key, mixed>}> */
    public array $lines = [];

    public ?string $prunedBefore = null;

    public function __construct(private readonly bool $broken = false)
    {
    }

    public function append(string $event, ?int $orderId, ?string $traceId, array $data): void
    {
        if ($this->broken) {
            throw new StoreException('table is missing');
        }

        $this->lines[] = ['event' => $event, 'orderId' => $orderId, 'traceId' => $traceId, 'data' => $data];
    }

    public function recent(?int $orderId, int $limit): array
    {
        if ($this->broken) {
            throw new StoreException('table is missing');
        }

        return [new Entry(1, '2026-11-16 12:00:00', 'order.queued', $orderId, null, [])];
    }

    public function prune(string $beforeUtc): int
    {
        if ($this->broken) {
            throw new StoreException('table is missing');
        }

        $this->prunedBefore = $beforeUtc;

        return 3;
    }
}

it('stores a line with its order and trace id', function (): void {
    $store = new InMemoryStore();

    (new Journal($store))->record('api.call', 8402, ['status' => 201], 'f05e07ec81806aa151500c414d14d53b');

    expect($store->lines)->toBe([[
        'event' => 'api.call',
        'orderId' => 8402,
        'traceId' => 'f05e07ec81806aa151500c414d14d53b',
        'data' => ['status' => 201],
    ]]);
});

it('masks buyer details before they reach the store', function (): void {
    $store = new InMemoryStore();

    (new Journal($store))->record('order.seen', 8402, [
        'billing_email' => 'buyer@example.com',
        'note' => 'call +374 91 123456 or write to buyer@example.com',
        'payment_method' => 'vtbam',
        'nested' => ['first_name' => 'Anna', 'total' => '100'],
    ]);

    expect($store->lines[0]['data'] ?? null)->toBe([
        'billing_email' => '[REDACTED]',
        'note' => 'call [PHONE_REDACTED] or write to [EMAIL_REDACTED]',
        'payment_method' => 'vtbam',
        'nested' => ['first_name' => '[REDACTED]', 'total' => '100'],
    ]);
});

it('logs and carries on when the line cannot be written', function (): void {
    $logger = Mockery::mock(Logger::class)->makePartial();
    $logger->shouldReceive('error')->once()->with(
        'Journal line was not written: table is missing',
        ['event' => 'order.queued', 'order_id' => 8402],
    );

    (new Journal(new InMemoryStore(broken: true), $logger))->record('order.queued', 8402);
});

it('lets a failed read through, so a report cannot claim an empty history', function (): void {
    $journal = new Journal(new InMemoryStore(broken: true));

    expect(fn () => $journal->recent())->toThrow(StoreException::class, 'table is missing');
});

it('prunes what is older than a year', function (): void {
    $store = new InMemoryStore();
    $before = time();

    (new Journal($store))->prune();

    $cutoff = strtotime((string) $store->prunedBefore . ' UTC');
    expect($cutoff)->toBeGreaterThanOrEqual($before - 365 * 86400)
        ->and($cutoff)->toBeLessThanOrEqual(time() - 365 * 86400);
});

it('logs when pruning fails', function (): void {
    $logger = Mockery::mock(Logger::class)->makePartial();
    $logger->shouldReceive('error')->once()->with('Journal was not pruned: table is missing');

    (new Journal(new InMemoryStore(broken: true), $logger))->prune();
});
