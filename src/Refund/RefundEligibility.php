<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Refund;

/**
 * Outcome of {@see RefundEligibilityChecker::check()}: either
 * "register this as a full refund" or "do not auto-register, route to
 * ManualRequired with this human-readable reason".
 *
 * Phase 3e v0.1 deliberately supports only full refunds because the
 * SDK's `RegisterSaleResponse` does not return per-item `srcId`s, so we
 * have no way to address individual sale items in a partial refund.
 * Adding partial-refund support requires either an SDK change to expose
 * per-item srcIds at registration time, or a separate "fetch sale by
 * saleId" call to retrieve them — both out of scope for this milestone.
 *
 * The class is a tiny tagged union so callers can `match` cleanly:
 *
 *   match (true) {
 *     $eligibility->isFullRefund() => $queue->enqueue(...),
 *     default => $meta->markManualRequired($refund, $eligibility->reason),
 *   }
 *
 * Tagged-union via boolean rather than enum because the `Full` case
 * carries no extra payload and the `Ineligible` case carries only a
 * reason string — a discriminated enum here would be ceremony for no
 * additional type safety.
 */

if (! defined('ABSPATH')) {
    exit;
}

final readonly class RefundEligibility
{
    private function __construct(
        public bool $isFullRefund,
        public string $reason,
    ) {
    }

    public static function full(): self
    {
        return new self(isFullRefund: true, reason: '');
    }

    public static function ineligible(string $reason): self
    {
        return new self(isFullRefund: false, reason: $reason);
    }
}
