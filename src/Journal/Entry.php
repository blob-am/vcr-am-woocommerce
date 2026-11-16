<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Journal;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One line of the journal, as read back.
 */
final readonly class Entry
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public int $id,
        /** UTC, `Y-m-d H:i:s`. */
        public string $createdAt,
        public string $event,
        public ?int $orderId,
        public ?string $traceId,
        public array $data,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'at' => $this->createdAt,
            'event' => $this->event,
            'orderId' => $this->orderId,
            'traceId' => $this->traceId,
            'data' => $this->data,
        ];
    }
}
