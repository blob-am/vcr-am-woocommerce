<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Refund;

use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\RefundReason;
use WC_Order_Refund;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Translates the WC admin's free-text refund reason field into the SDK's
 * {@see RefundReason} enum (which SRC validates against a closed set).
 *
 * WC stores `WC_Order_Refund::get_reason()` as a free-text string the
 * admin types in. SRC requires one of six closed-vocabulary codes
 * (`customer_request`, `defective_goods`, `wrong_goods`, `cashier_error`,
 * `duplicate_receipt`, `other`).
 *
 * Strategy: keyword sniffing on the reason text against per-language
 * substring lists, with `customer_request` as the safe default (the
 * dominant real-world case). This is heuristic and intentionally
 * conservative — wrong category is annoying but legal; missing a category
 * change altogether would force the admin to edit refunds in the SRC
 * portal directly. Stores can override entirely via the `vcr_refund_reason`
 * filter.
 *
 * Why we don't ship a UI dropdown bound to the SDK enum: WC's refund
 * dialog is part of WC core, not us; injecting a dropdown there is a
 * theme/hook fight that gets ugly. A future Phase could add a separate
 * "VCR refund metadata" panel; for v0.1 we infer.
 */
class RefundReasonMapper
{
    /**
     * Substring patterns (lowercase, multilingual) that route to each
     * non-default RefundReason. First match wins, scanned in array order.
     *
     * @var array<string, list<string>>
     */
    private const KEYWORDS = [
        RefundReason::DefectiveGoods->value => [
            'defect', 'broken', 'damaged', 'faulty', 'malfunction',
            'неисправн', 'брак', 'дефект', 'сломан', 'поврежд',
            'փչացած', 'թերի',
        ],
        RefundReason::WrongGoods->value => [
            'wrong', 'incorrect', 'mistake', 'mix-up', 'mixup',
            'не тот', 'неверн', 'ошибк', 'путани',
            'սխալ', 'ոչ ճիշտ',
        ],
        RefundReason::DuplicateReceipt->value => [
            'duplicate', 'double charge',
            'дубликат', 'двойн',
            'կրկնակի',
        ],
        RefundReason::CashierError->value => [
            'cashier error', 'operator error', 'staff error',
            'ошибка кассир', 'ошибка оператор',
            'գանձապահի սխալ',
        ],
    ];

    public function map(WC_Order_Refund $refund): RefundReason
    {
        $rawReason = $refund->get_reason();

        $derived = $this->classify($rawReason);

        /**
         * Filter: stores may override the inferred reason — the second
         * arg gives them the original text to make their own decision
         * (e.g., parse a refund-reason picklist from a custom plugin).
         *
         * Returning a non-RefundReason from the filter is silently
         * ignored — the inferred value stays.
         */
        $filtered = apply_filters('vcr_refund_reason', $derived, $rawReason, $refund);

        return $filtered instanceof RefundReason ? $filtered : $derived;
    }

    private function classify(string $rawReason): RefundReason
    {
        $needle = strtolower(trim($rawReason));

        if ($needle === '') {
            return RefundReason::CustomerRequest;
        }

        foreach (self::KEYWORDS as $reasonValue => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($needle, $pattern)) {
                    $matched = RefundReason::tryFrom($reasonValue);
                    if ($matched !== null) {
                        return $matched;
                    }
                }
            }
        }

        return RefundReason::CustomerRequest;
    }
}
