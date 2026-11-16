<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Support;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * How sending a report ended: a reference to quote to support, or a reason.
 */
final readonly class SendOutcome
{
    private function __construct(
        public ?string $reference,
        public ?SendProblem $problem,
        public ?string $detail,
    ) {
    }

    public static function sent(string $reference): self
    {
        return new self($reference, null, null);
    }

    public static function failed(SendProblem $problem, ?string $detail = null): self
    {
        return new self(null, $problem, $detail);
    }
}
