<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Privacy;

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundStatusMeta;
use WC_Order;
use WC_Order_Refund;

/**
 * GDPR personal-data exporter + eraser registration. Required for any
 * WooCommerce plugin that handles customer-linked records — and our
 * plugin attaches fiscal identifiers (urlId, crn, fiscal serial,
 * SRC receipt id) to orders that carry customer email/name/address.
 *
 * Exporter behaviour:
 *
 *   - For each order belonging to the requested email address, emit a
 *     data group "VCR Fiscal Receipts" with one item per order
 *     containing the SRC identifiers we hold on it (sale-level + each
 *     refund's identifiers).
 *   - Standard WP pagination via $page (1-indexed). We process up to
 *     50 orders per page so even sites with thousands of orders per
 *     customer don't time out.
 *
 * Eraser behaviour:
 *
 *   - **We do NOT delete fiscal data.** Armenian tax law (Art 380.1 of
 *     the Tax Code, plus general bookkeeping retention requirements)
 *     mandates retention of fiscal records for years after issuance.
 *     A GDPR right-to-erasure request that would destroy fiscal
 *     records conflicts with the data retention obligation — and
 *     compliance with the tax law overrides erasure under Armenian
 *     and EU GDPR Art 17(3)(b) ("processing necessary for compliance
 *     with a legal obligation").
 *   - We therefore report `items_retained: true` for every fiscal
 *     record found, with a clear message explaining the retention
 *     basis. The admin / data protection officer can review and act
 *     accordingly (e.g., manual case-by-case deletion if the records
 *     are past their statutory retention window).
 */
class PrivacyHandler
{
    /**
     * Group id used in the exporter result. WP groups per-row-output
     * by this id in the final export ZIP — keeping it stable means
     * subsequent runs don't fragment the customer's data into
     * different sections.
     */
    public const EXPORTER_GROUP_ID = 'vcr-fiscal-receipts';

    /** Eraser id (registered with WP's eraser registry). */
    public const ERASER_ID = 'vcr-fiscal-receipts';

    /** Page size for paginated processing — same default WC core uses. */
    private const PAGE_SIZE = 50;

    public function __construct(
        private readonly FiscalStatusMeta $fiscalMeta,
        private readonly RefundStatusMeta $refundMeta,
    ) {
    }

    public function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerEraser']);
        // The privacy-policy editor is a post-type screen that's loaded
        // on `admin_init`; calling `wp_add_privacy_policy_content` any
        // earlier is a no-op (the registry hash isn't ready yet) and
        // any later misses the editor's render. Hook on `admin_init`,
        // not on `init`, per the WP Privacy Handbook example.
        add_action('admin_init', [$this, 'registerPolicyContent']);
    }

    /**
     * Inject suggested privacy-policy text into WP's Privacy Policy
     * editor (Tools -> Privacy). Merchants can copy any/all of it into
     * their published policy with one click.
     *
     * Why this matters legally:
     *
     *   - GDPR Art 13(1)(e) requires the controller (the merchant) to
     *     disclose recipients of personal data at collection. The
     *     plugin makes vcr.am a sub-processor; the merchant cannot
     *     disclose what they don't know without our help.
     *   - Armenia is NOT on the EU adequacy list (verified May 2026),
     *     so a transfer-mechanism note (SCCs) is mandatory under
     *     GDPR Art 46.
     *   - The retention statement is the merchant-facing companion to
     *     {@see self::eraseFor()}'s `items_retained: true` response —
     *     they have to match in fact and in legal basis.
     *
     * Note we do NOT call this if the helper is missing (multisite
     * subsites pre-WP-4.9.6, very old installs). The hook is
     * idempotent — WP keys policy content by `(plugin_name, content_hash)`
     * so re-registering on every admin_init does not duplicate rows.
     */
    public function registerPolicyContent(): void
    {
        if (! function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $intro = __(
            'This site uses the VCR plugin to issue fiscal receipts (eHDM) to the Armenian State Revenue Committee (SRC) via the VCR.AM gateway. The text below describes the data flow and lawful basis. Edit and paste the parts relevant to your store into your published privacy policy.',
            'vcr',
        );

        $sections = [
            '<strong class="privacy-policy-tutorial">' . esc_html__('Suggested text:', 'vcr') . '</strong>',

            '<p>' . esc_html__(
                'When you place an order, our store transmits the order line items, totals, taxes, payment method (cash / non-cash), currency, and the corresponding refund records (if any) to the VCR.AM fiscal-receipt operator (vcr.am, registered in the Republic of Armenia), which forwards them to the Armenian State Revenue Committee (SRC) so a fiscal receipt can be issued in your name.',
                'vcr',
            ) . '</p>',

            '<p>' . esc_html__(
                'We do NOT transmit your name, email address, phone number, billing address, or IP address to VCR.AM or to the SRC. Fiscal receipts are issued to an anonymised buyer record. The personal data we collect at checkout (name, address, etc.) stays in this store for order fulfilment under our usual policies.',
                'vcr',
            ) . '</p>',

            '<p>' . esc_html__(
                'Lawful basis: GDPR Article 6(1)(c) — processing necessary for compliance with a legal obligation. The obligation is set out in the Armenian Tax Code Article 380.1 (HO-280-N) and Government Decision 1976-N, which require electronic Cash Register (e-HDM) registration of every taxable sale.',
                'vcr',
            ) . '</p>',

            '<p>' . esc_html__(
                'International transfer: VCR.AM operates from the Republic of Armenia, which is not on the European Commission\'s list of countries with an adequate level of data protection. The transfer is governed by the Standard Contractual Clauses (Commission Implementing Decision (EU) 2021/914), Module Two (controller-to-processor); ask vcr.am for a signed Data Processing Addendum incorporating those clauses before activating the plugin in production.',
                'vcr',
            ) . '</p>',

            '<p>' . esc_html__(
                'Retention: fiscal records are retained for the period required by the Armenian Tax Code (typically 5 years from the year-end in which the receipt was issued, per Tax Code Article 56). Erasure requests covering fiscal records are refused on legal-obligation grounds under GDPR Article 17(3)(b); local plugin metadata for orders that never resulted in an SRC submission is erased on request.',
                'vcr',
            ) . '</p>',

            '<p>' . esc_html__(
                'Sub-processor: VCR.AM acts as a processor on the merchant\'s behalf for the purpose of fiscal-receipt issuance. The Armenian SRC is an independent controller receiving the data under national tax law. Both are listed here so that a data subject can identify recipients per GDPR Article 13(1)(e).',
                'vcr',
            ) . '</p>',
        ];

        $body = '<div class="wp-suggested-text">'
            . '<p class="privacy-policy-tutorial">' . esc_html($intro) . '</p>'
            . implode('', $sections)
            . '</div>';

        wp_add_privacy_policy_content(
            __('VCR — Fiscal Receipts (Armenia)', 'vcr'),
            wp_kses_post($body),
        );
    }

    /**
     * Append our exporter to whatever the privacy framework's filter
     * chain has accumulated so far. **Preserve the original key types**
     * — WP core uses string slugs for exporters/erasers, but third-party
     * plugins are not contractually forbidden from injecting integer
     * keys, and stringifying them would silently shift them in the
     * registry and break their lookup. Pass through unchanged.
     *
     * @param  mixed $exporters
     * @return array<int|string, mixed>
     */
    public function registerExporter($exporters): array
    {
        $normalised = is_array($exporters) ? $exporters : [];
        $normalised[self::EXPORTER_GROUP_ID] = [
            'exporter_friendly_name' => __('VCR Fiscal Receipts', 'vcr'),
            'callback' => [$this, 'exportFor'],
        ];

        return $normalised;
    }

    /**
     * Same shape-preserving append as {@see self::registerExporter()}.
     *
     * @param  mixed $erasers
     * @return array<int|string, mixed>
     */
    public function registerEraser($erasers): array
    {
        $normalised = is_array($erasers) ? $erasers : [];
        $normalised[self::ERASER_ID] = [
            'eraser_friendly_name' => __('VCR Fiscal Receipts', 'vcr'),
            'callback' => [$this, 'eraseFor'],
        ];

        return $normalised;
    }

    /**
     * Exporter callback.
     *
     * @return array{data: list<array<string, mixed>>, done: bool}
     */
    public function exportFor(string $emailAddress, int $page = 1): array
    {
        $data = [];
        $orders = $this->ordersForEmail($emailAddress, $page);

        foreach ($orders as $order) {
            $rows = $this->fiscalRowsFor($order);
            if ($rows === []) {
                continue;
            }

            $data[] = [
                'group_id' => self::EXPORTER_GROUP_ID,
                'group_label' => __('VCR Fiscal Receipts', 'vcr'),
                'item_id' => 'order-' . $order->get_id(),
                'data' => $rows,
            ];

            // Each refund gets its own row group — keeps the export
            // legible when an order has multiple partial refunds.
            foreach ($order->get_refunds() as $refund) {
                $refundRows = $this->refundRowsFor($refund);
                if ($refundRows === []) {
                    continue;
                }

                $data[] = [
                    'group_id' => self::EXPORTER_GROUP_ID,
                    'group_label' => __('VCR Fiscal Receipts', 'vcr'),
                    'item_id' => 'refund-' . $refund->get_id(),
                    'data' => $refundRows,
                ];
            }
        }

        return [
            'data' => $data,
            'done' => count($orders) < self::PAGE_SIZE,
        ];
    }

    /**
     * Eraser callback. Branches on the per-order fiscal status:
     *
     *   - **Success** -> retain. SRC has a registered fiscal record;
     *     erasure on our side would not delete it from SRC, and our
     *     local meta is required to operate the customer-facing
     *     receipt link / refund flow against that record. Lawful basis
     *     for retention: GDPR Article 17(3)(b) — processing necessary
     *     for compliance with a legal obligation (Armenian Tax Code
     *     Article 380.1 + the Article 56 retention period).
     *
     *   - **Pending / Failed / ManualRequired / no status at all** ->
     *     actually erase. There is no SRC-side fiscal record to
     *     reconcile against; the local `_vcr_*` meta is just stale
     *     state and qualifies as personal data (transitively, via the
     *     order id). Same logic for any refund attached to the order
     *     that is in a non-Success state.
     *
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public function eraseFor(string $emailAddress, int $page = 1): array
    {
        $orders = $this->ordersForEmail($emailAddress, $page);
        $messages = [];
        $retainedAny = false;
        $removedAny = false;

        foreach ($orders as $order) {
            $outcome = $this->processOrderForErasure($order);

            if ($outcome['retained']) {
                $retainedAny = true;
                $messages[] = sprintf(
                    /* translators: %d: WC order id */
                    __('VCR fiscal record for order #%d retained on legal-obligation grounds: GDPR Article 17(3)(b) — processing necessary for compliance with Armenian Tax Code Article 380.1 (HO-280-N), retention period per Tax Code Article 56 (typically 5 years from year-end). The SRC-side fiscal receipt is unaffected by this request.', 'vcr'),
                    $order->get_id(),
                );
            }
            if ($outcome['removed']) {
                $removedAny = true;
            }
        }

        return [
            'items_removed' => $removedAny,
            'items_retained' => $retainedAny,
            'messages' => $messages,
            'done' => count($orders) < self::PAGE_SIZE,
        ];
    }

    /**
     * Per-order erasure decision + execution. Returns a `{retained,
     * removed}` pair so the caller can set BOTH flags when an order
     * mixes states (e.g. parent Success retained, refund Failed
     * purged). The flags are independent: an order can be both
     * retained AND removed in the same erasure pass when it carries
     * both kinds of records.
     *
     * @return array{retained: bool, removed: bool}
     */
    private function processOrderForErasure(WC_Order $order): array
    {
        $orderStatus = $this->fiscalMeta->status($order);
        $refunds = $order->get_refunds();

        // Fast-path: no plugin meta on the order and no refunds either —
        // the order is invisible to the plugin, nothing to do.
        if ($orderStatus === null && $refunds === []) {
            return ['retained' => false, 'removed' => false];
        }

        $retainOrder = $orderStatus === FiscalStatus::Success;
        $retainAnyRefund = false;
        $deletedAny = false;

        foreach ($refunds as $refund) {
            if (! $refund instanceof WC_Order_Refund) {
                continue;
            }
            $refundStatus = $this->refundMeta->status($refund);
            if ($refundStatus === FiscalStatus::Success) {
                $retainAnyRefund = true;
            } elseif ($refundStatus !== null) {
                // Refund had plugin meta but never got registered with
                // SRC. Wipe the orphan meta.
                $this->refundMeta->purgeAll($refund);
                $deletedAny = true;
            }
        }

        $retained = $retainOrder || $retainAnyRefund;

        // Order-level meta: purge ONLY when neither the order nor any
        // attached refund needs to be retained. If a Success refund
        // references the parent's external id / sale id, deleting the
        // parent meta would orphan that refund's audit trail — keep
        // the umbrella record around even though the parent's own
        // status was Pending/Failed.
        if (! $retained && $orderStatus !== null) {
            $this->fiscalMeta->purgeAll($order);
            $deletedAny = true;
        }

        return ['retained' => $retained, 'removed' => $deletedAny];
    }

    /**
     * Return orders for the given email, page-paginated.
     *
     * Matches both:
     *
     *   - orders whose `billing_email` equals the address (covers
     *     guest-checkout customers and logged-in customers who used
     *     their account email);
     *   - orders whose `customer_user` is the WP user-id whose email
     *     equals the address (covers logged-in customers who later
     *     changed their email — billing_email on the order is a
     *     historical snapshot, customer_user is the live account
     *     reference).
     *
     * Without the `customer_user` lookup, an SAR for a registered
     * customer who once changed their email would silently miss
     * orders placed under the old account-email reference. WC core's
     * own personal-data exporter does the same dual-lookup.
     *
     * @return list<WC_Order>
     */
    private function ordersForEmail(string $emailAddress, int $page): array
    {
        if ($emailAddress === '') {
            return [];
        }

        $byEmail = $this->safeGetOrders([
            'billing_email' => $emailAddress,
            'limit' => self::PAGE_SIZE,
            'paged' => max(1, $page),
            'type' => 'shop_order',
            'orderby' => 'date',
            'order' => 'ASC',
        ]);

        $byUser = [];
        $user = get_user_by('email', $emailAddress);
        if ($user !== false && $user->ID > 0) {
            $byUser = $this->safeGetOrders([
                'customer_id' => (int) $user->ID,
                'limit' => self::PAGE_SIZE,
                'paged' => max(1, $page),
                'type' => 'shop_order',
                'orderby' => 'date',
                'order' => 'ASC',
            ]);
        }

        // Dedupe by order id — a customer who placed orders with their
        // account email both as guest and as a registered user will
        // appear in both result sets.
        $merged = [];
        foreach (array_merge($byEmail, $byUser) as $candidate) {
            $merged[$candidate->get_id()] = $candidate;
        }

        return array_values($merged);
    }

    /**
     * Wrap `wc_get_orders` with the same defensive filter the original
     * `ordersForEmail` had: `wc_get_orders` is filterable by third
     * parties (`woocommerce_order_data_store_cpt_get_orders_query` et
     * al.), so the result set may contain non-WC_Order entries. Keep
     * the narrowing in one place so both lookup branches share it.
     *
     * @param  array<string, mixed> $args
     * @return list<WC_Order>
     */
    private function safeGetOrders(array $args): array
    {
        $orders = wc_get_orders($args);
        if (! is_array($orders)) {
            return [];
        }

        $narrowed = [];
        foreach ($orders as $candidate) {
            if ($candidate instanceof WC_Order) {
                $narrowed[] = $candidate;
            }
        }

        return $narrowed;
    }

    /**
     * Build the per-order export rows for the data subject. We surface
     * EVERY `_vcr_*` meta key tied to the order, not just the SRC
     * identifiers, so the export honours GDPR Article 15 (right of
     * access) — the data subject is entitled to ALL personal data
     * processed about them, including timestamps and operational state
     * (attempt count, last error). The audit team can verify
     * completeness against the meta-key constants on
     * {@see FiscalStatusMeta} via the static analysis tools.
     *
     * @return list<array{name: string, value: string}>
     */
    private function fiscalRowsFor(WC_Order $order): array
    {
        $status = $this->fiscalMeta->status($order);
        if ($status === null) {
            return [];
        }

        $rows = [
            ['name' => __('Fiscal status', 'vcr'), 'value' => $status->value],
            ['name' => __('External id', 'vcr'), 'value' => $this->fiscalMeta->externalId($order)],
            ['name' => __('Attempt count', 'vcr'), 'value' => (string) $this->fiscalMeta->attemptCount($order)],
        ];

        if (($lastAttempt = $this->fiscalMeta->lastAttemptAt($order)) !== null) {
            $rows[] = ['name' => __('Last attempt at', 'vcr'), 'value' => $lastAttempt];
        }
        if (($lastError = $this->fiscalMeta->lastError($order)) !== null) {
            $rows[] = ['name' => __('Last error', 'vcr'), 'value' => $lastError];
        }
        if (($registeredAt = $this->fiscalMeta->registeredAt($order)) !== null) {
            $rows[] = ['name' => __('Registered at', 'vcr'), 'value' => $registeredAt];
        }
        if (($crn = $this->fiscalMeta->crn($order)) !== null) {
            $rows[] = ['name' => __('SRC CRN', 'vcr'), 'value' => $crn];
        }
        if (($fiscal = $this->fiscalMeta->fiscal($order)) !== null) {
            $rows[] = ['name' => __('SRC fiscal serial', 'vcr'), 'value' => $fiscal];
        }
        if (($urlId = $this->fiscalMeta->urlId($order)) !== null) {
            $rows[] = ['name' => __('SRC receipt url id', 'vcr'), 'value' => $urlId];
        }
        if (($saleId = $this->fiscalMeta->saleId($order)) !== null) {
            $rows[] = ['name' => __('SRC sale id', 'vcr'), 'value' => (string) $saleId];
        }
        if (($srcReceiptId = $this->fiscalMeta->srcReceiptId($order)) !== null) {
            $rows[] = ['name' => __('SRC receipt id', 'vcr'), 'value' => (string) $srcReceiptId];
        }

        return $rows;
    }

    /**
     * @param mixed $refund
     * @return list<array{name: string, value: string}>
     */
    private function refundRowsFor($refund): array
    {
        if (! $refund instanceof \WC_Order_Refund) {
            return [];
        }

        $status = $this->refundMeta->status($refund);
        if ($status === null) {
            return [];
        }

        $rows = [
            ['name' => __('Refund fiscal status', 'vcr'), 'value' => $status->value],
            ['name' => __('Refund external id', 'vcr'), 'value' => $this->refundMeta->externalId($refund)],
            ['name' => __('Refund attempt count', 'vcr'), 'value' => (string) $this->refundMeta->attemptCount($refund)],
        ];

        if (($lastAttempt = $this->refundMeta->lastAttemptAt($refund)) !== null) {
            $rows[] = ['name' => __('Refund last attempt at', 'vcr'), 'value' => $lastAttempt];
        }
        if (($lastError = $this->refundMeta->lastError($refund)) !== null) {
            $rows[] = ['name' => __('Refund last error', 'vcr'), 'value' => $lastError];
        }
        if (($registeredAt = $this->refundMeta->registeredAt($refund)) !== null) {
            $rows[] = ['name' => __('Refund registered at', 'vcr'), 'value' => $registeredAt];
        }
        if (($crn = $this->refundMeta->crn($refund)) !== null) {
            $rows[] = ['name' => __('SRC refund CRN', 'vcr'), 'value' => $crn];
        }
        if (($fiscal = $this->refundMeta->fiscal($refund)) !== null) {
            $rows[] = ['name' => __('SRC refund fiscal serial', 'vcr'), 'value' => $fiscal];
        }
        if (($urlId = $this->refundMeta->urlId($refund)) !== null) {
            $rows[] = ['name' => __('SRC refund url id', 'vcr'), 'value' => $urlId];
        }
        if (($saleRefundId = $this->refundMeta->saleRefundId($refund)) !== null) {
            $rows[] = ['name' => __('SRC sale-refund id', 'vcr'), 'value' => (string) $saleRefundId];
        }
        if (($receiptId = $this->refundMeta->receiptId($refund)) !== null) {
            $rows[] = ['name' => __('SRC refund receipt id', 'vcr'), 'value' => (string) $receiptId];
        }

        return $rows;
    }
}
