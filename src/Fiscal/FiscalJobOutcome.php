<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Fiscal;

/**
 * Result of one {@see FiscalJob::run()} attempt. Carries the status the job
 * persisted to the order meta plus a human-readable reason (used in WC
 * order notes and admin-facing diagnostics).
 *
 * The queue side reads {@see self::$status} to decide whether to schedule
 * another attempt — if it equals {@see FiscalStatus::Pending}, the job
 * itself decided to keep trying.
 */
final readonly class FiscalJobOutcome
{
    public function __construct(
        public FiscalStatus $status,
        public ?string $reason = null,
    ) {
    }

    public static function success(): self
    {
        return new self(FiscalStatus::Success);
    }

    public static function retriable(string $reason): self
    {
        return new self(FiscalStatus::Pending, $reason);
    }

    public static function failed(string $reason): self
    {
        return new self(FiscalStatus::Failed, $reason);
    }

    public static function manualRequired(string $reason): self
    {
        return new self(FiscalStatus::ManualRequired, $reason);
    }

    public function shouldRetry(): bool
    {
        return $this->status === FiscalStatus::Pending;
    }
}
