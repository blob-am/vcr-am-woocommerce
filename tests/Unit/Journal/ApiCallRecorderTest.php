<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Journal\ApiCallRecorder;
use BlobSolutions\WooCommerceVcrAm\Journal\Journal;
use BlobSolutions\WooCommerceVcrAm\Journal\Store;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Diagnostics\RequestAttempt;

/**
 * A store that keeps lines in memory, so these tests run the real Journal.
 */
function memoryStore(): Store
{
    return new class () implements Store {
        /** @var list<array{event: string, orderId: ?int, traceId: ?string, data: array<array-key, mixed>}> */
        public array $lines = [];

        public function append(string $event, ?int $orderId, ?string $traceId, array $data): void
        {
            $this->lines[] = ['event' => $event, 'orderId' => $orderId, 'traceId' => $traceId, 'data' => $data];
        }

        public function recent(?int $orderId, int $limit): array
        {
            return [];
        }

        public function prune(string $beforeUtc): int
        {
            return 0;
        }
    };
}

function attempt(?int $status, ?string $traceId, ?string $failure = null): RequestAttempt
{
    return new RequestAttempt(
        startedAt: new DateTimeImmutable('2026-11-16T14:00:00Z'),
        method: 'POST',
        path: '/sales',
        durationMs: 412,
        statusCode: $status,
        traceId: $traceId,
        idempotencyKey: 'order-8402-r1',
        failure: $failure,
    );
}

it('writes a call as one line carrying the trace id', function (): void {
    $store = memoryStore();
    $recorder = new ApiCallRecorder(new Journal($store));

    $recorder->onRequestAttempt(attempt(200, '640e22a83ff5df99b8bda3c661e781cd'));

    expect($store->lines)->toBe([[
        'event' => 'api.call',
        'orderId' => null,
        'traceId' => '640e22a83ff5df99b8bda3c661e781cd',
        'data' => ['method' => 'POST', 'path' => '/sales', 'status' => 200, 'duration_ms' => 412, 'failure' => null],
    ]]);
});

it('files a call under the order whose job made it, and stops once the job is over', function (): void {
    $store = memoryStore();
    $journal = new Journal($store);
    $recorder = new ApiCallRecorder($journal);

    $result = $journal->during(8402, function () use ($recorder): string {
        $recorder->onRequestAttempt(attempt(503, null, 'HTTP 503'));

        return 'done';
    });
    $recorder->onRequestAttempt(attempt(200, null));

    expect($result)->toBe('done')
        ->and(array_column($store->lines, 'orderId'))->toBe([8402, null])
        ->and($store->lines[0]['data']['failure'])->toBe('HTTP 503');
});

it('stops filing under the order even when the job throws', function (): void {
    $store = memoryStore();
    $journal = new Journal($store);

    expect(fn () => $journal->during(8402, fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class);

    $journal->record('pairing.started');

    expect($store->lines[0]['orderId'])->toBeNull();
});

it('keeps a line\'s own order over the running job\'s', function (): void {
    $store = memoryStore();
    $journal = new Journal($store);

    $journal->during(8402, fn () => $journal->record('order.hook', 9000));

    expect($store->lines[0]['orderId'])->toBe(9000);
});
