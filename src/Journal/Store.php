<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Journal;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Where journal lines are kept.
 */
interface Store
{
    /**
     * @param array<array-key, mixed> $data Already masked
     *
     * @throws StoreException When the line could not be written
     */
    public function append(string $event, ?int $orderId, ?string $traceId, array $data): void;

    /**
     * Newest first.
     *
     * @param ?int $orderId Only lines about this order; null for all
     *
     * @return list<Entry>
     *
     * @throws StoreException When the journal could not be read
     */
    public function recent(?int $orderId, int $limit): array;

    /**
     * Deletes lines created before the given moment.
     *
     * @param string $beforeUtc `Y-m-d H:i:s`
     *
     * @return int How many lines went
     *
     * @throws StoreException
     */
    public function prune(string $beforeUtc): int;
}
