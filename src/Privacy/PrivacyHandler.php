<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Privacy;

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundStatusMeta;
use WC_Order;

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
    }

    /**
     * @param  mixed $exporters
     * @return array<string, mixed>
     */
    public function registerExporter($exporters): array
    {
        $normalised = [];
        if (is_array($exporters)) {
            foreach ($exporters as $key => $value) {
                $normalised[(string) $key] = $value;
            }
        }

        $normalised[self::EXPORTER_GROUP_ID] = [
            'exporter_friendly_name' => __('VCR Fiscal Receipts', 'vcr'),
            'callback' => [$this, 'exportFor'],
        ];

        return $normalised;
    }

    /**
     * @param  mixed $erasers
     * @return array<string, mixed>
     */
    public function registerEraser($erasers): array
    {
        $normalised = [];
        if (is_array($erasers)) {
            foreach ($erasers as $key => $value) {
                $normalised[(string) $key] = $value;
            }
        }

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
     * Eraser callback. We always retain — see class doc-block for the
     * legal rationale.
     *
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public function eraseFor(string $emailAddress, int $page = 1): array
    {
        $orders = $this->ordersForEmail($emailAddress, $page);
        $messages = [];
        $retainedAny = false;

        foreach ($orders as $order) {
            if ($this->fiscalMeta->status($order) === null && $order->get_refunds() === []) {
                // No VCR fiscal records on this order — nothing to retain.
                continue;
            }

            $retainedAny = true;
            $messages[] = sprintf(
                /* translators: %d: WC order id */
                __('VCR fiscal records for order #%d retained per Armenian tax law (statutory retention applies to all SRC-registered receipts). Manual review required for case-specific deletion requests.', 'vcr'),
                $order->get_id(),
            );
        }

        return [
            'items_removed' => false,
            'items_retained' => $retainedAny,
            'messages' => $messages,
            'done' => count($orders) < self::PAGE_SIZE,
        ];
    }

    /**
     * Return orders for the given email, page-paginated.
     *
     * @return list<WC_Order>
     */
    private function ordersForEmail(string $emailAddress, int $page): array
    {
        if ($emailAddress === '') {
            return [];
        }

        $orders = wc_get_orders([
            'billing_email' => $emailAddress,
            'limit' => self::PAGE_SIZE,
            'paged' => max(1, $page),
            'type' => 'shop_order',
            'orderby' => 'date',
            'order' => 'ASC',
        ]);

        if (! is_array($orders)) {
            return [];
        }

        // We pass `type=shop_order` above, so every entry SHOULD be a
        // WC_Order — but `woocommerce_order_data_store_cpt_get_orders_query`
        // and friends let third-party plugins replace the result set
        // entirely. Keep a defensive filter so a misbehaving plugin can't
        // route non-orders into our fiscalRowsFor() type-hinted callsite.
        $narrowed = [];
        foreach ($orders as $candidate) {
            if ($candidate instanceof WC_Order) {
                $narrowed[] = $candidate;
            }
        }

        return $narrowed;
    }

    /**
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
        ];

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
        ];

        if (($crn = $this->refundMeta->crn($refund)) !== null) {
            $rows[] = ['name' => __('SRC refund CRN', 'vcr'), 'value' => $crn];
        }
        if (($fiscal = $this->refundMeta->fiscal($refund)) !== null) {
            $rows[] = ['name' => __('SRC refund fiscal serial', 'vcr'), 'value' => $fiscal];
        }
        if (($urlId = $this->refundMeta->urlId($refund)) !== null) {
            $rows[] = ['name' => __('SRC refund url id', 'vcr'), 'value' => $urlId];
        }

        return $rows;
    }
}
