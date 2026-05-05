<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Fiscal;

/**
 * Decides whether a given WC payment-method id should be classified as
 * "cash tender" (vs `nonCash`) for SRC reporting. Extracted so both
 * {@see PaymentMapper} (sales) and {@see \BlobSolutions\WooCommerceVcrAm\Refund\RefundPaymentMapper}
 * (refunds) share one source of truth — and admins setting the
 * `vcr_cash_payment_method_ids` filter only configure it once for the
 * whole plugin, not per direction.
 *
 * Defaults: WC core's two offline-tender gateways (`cod`, `cheque`).
 * Anything else (Stripe / PayPal / WooPayments / BACS bank-transfer /
 * any 3rd-party online gateway) -> `nonCash`. Empty payment method
 * (manual admin-created orders) -> `nonCash` so we err toward the
 * more common online case.
 */
class CashPaymentResolver
{
    /** @var list<string> Built-in WC gateway ids that settle as cash. */
    private const DEFAULT_CASH_METHODS = ['cod', 'cheque'];

    public function isCash(string $methodId): bool
    {
        if ($methodId === '') {
            return false;
        }

        $cashMethods = self::DEFAULT_CASH_METHODS;

        $filtered = apply_filters('vcr_cash_payment_method_ids', $cashMethods);

        if (! is_array($filtered)) {
            return in_array($methodId, $cashMethods, true);
        }

        // Defensive narrowing — misbehaving filter returning mixed
        // values shouldn't escalate into a TypeError downstream.
        $cashMethods = array_values(array_filter(
            $filtered,
            static fn ($value): bool => is_string($value) && $value !== '',
        ));

        return in_array($methodId, $cashMethods, true);
    }
}
