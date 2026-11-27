<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Support;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionProbe;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionSummary;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Journal\Entry;
use BlobSolutions\WooCommerceVcrAm\Journal\Journal;
use BlobSolutions\WooCommerceVcrAm\Journal\StoreException;
use BlobSolutions\WooCommerceVcrAm\Logging\Logger;
use BlobSolutions\WooCommerceVcrAm\Net\UrlCredentials;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundStatusMeta;
use WC_Order;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Gathers what this store knows about a problem into one array.
 *
 * The same array is what the merchant previews, copies, downloads and sends:
 * there is no second, fuller version that goes to support behind their back.
 *
 * It names the store and describes the order's state. It holds nothing about
 * the buyer: no name, address, phone, email or IP. Free text that the store
 * did not write itself (error messages) goes through the logger's redaction,
 * and journal lines were masked when they were written. The rest is not
 * redacted wholesale: that pattern would also blank dates and receipt numbers.
 *
 * Not declared `final` so unit tests can mock it.
 */
class ReportBuilder
{
    public const FORMAT = 1;

    public const JOURNAL_LINES = 200;

    public function __construct(
        private readonly string $pluginVersion,
        private readonly Configuration $configuration,
        private readonly ConnectionProbe $probe,
        private readonly FiscalStatusMeta $meta,
        private readonly RefundStatusMeta $refundMeta,
        private readonly Journal $journal,
        private readonly ConnectionSummary $summary = new ConnectionSummary(),
        private readonly Logger $logger = new Logger(),
    ) {
    }

    /**
     * @param ?WC_Order $order The order the report is about; null for a report about the store
     *
     * @return array<string, mixed>
     *
     * @throws StoreException When the journal cannot be read
     */
    public function build(?WC_Order $order = null): array
    {
        $report = [
            'format' => self::FORMAT,
            'generatedAt' => gmdate('c'),
            'plugin' => ['slug' => 'vcr-am-fiscal-receipts', 'version' => $this->pluginVersion],
            'environment' => $this->environment(),
            'configuration' => $this->configurationState(),
            'register' => $this->summary->line($this->probe->state()),
        ];

        if ($order !== null) {
            $report['order'] = $this->orderState($order);
        }

        $report['journal'] = array_map(
            static fn (Entry $entry): array => $entry->toArray(),
            $this->journal->recent($order?->get_id(), self::JOURNAL_LINES),
        );

        return $report;
    }

    /**
     * The report as the text the merchant reads, copies, saves and sends.
     *
     * @throws ReportUnavailable
     */
    public function json(?WC_Order $order = null): string
    {
        try {
            $report = $this->build($order);
        } catch (StoreException $e) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not output: the message is logged, or escaped where it is shown (esc_html() in Support\ReportPage).
            throw new ReportUnavailable(
                sprintf('The plugin\'s journal could not be read: %s', $e->getMessage()),
                0,
                $e,
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $json = wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($json)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not output: the message is logged, or escaped where it is shown (esc_html() in Support\ReportPage).
            throw new ReportUnavailable('The report could not be encoded as JSON: ' . json_last_error_msg());
        }

        return $json;
    }

    private function freeText(?string $text): ?string
    {
        return $text === null ? null : $this->logger->redactString($text);
    }

    /**
     * @return array<string, mixed>
     */
    private function environment(): array
    {
        return [
            'site' => UrlCredentials::strip(home_url()),
            'wordpress' => get_bloginfo('version'),
            'woocommerce' => defined('WC_VERSION') ? WC_VERSION : null,
            'php' => PHP_VERSION,
            'locale' => get_locale(),
            'curl' => extension_loaded('curl'),
            'sodium' => extension_loaded('sodium'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function configurationState(): array
    {
        return [
            'apiKeyConfigured' => $this->configuration->hasCredentials(),
            'baseUrl' => UrlCredentials::strip($this->configuration->baseUrl()),
            'defaultCashierId' => $this->configuration->defaultCashierId(),
            'defaultDepartmentId' => $this->configuration->defaultDepartmentId(),
            'shippingSku' => $this->configuration->shippingSku(),
            'feeSku' => $this->configuration->feeSku(),
            'cashFiscalizeOn' => $this->configuration->cashFiscalizeOn(),
            'fullyConfigured' => $this->configuration->isFullyConfigured(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderState(WC_Order $order): array
    {
        $refunds = [];

        foreach ($order->get_refunds() as $refund) {
            $refunds[] = [
                'id' => $refund->get_id(),
                'amount' => $refund->get_amount(),
                'fiscalStatus' => $this->refundMeta->status($refund)?->value,
                'attempts' => $this->refundMeta->attemptCount($refund),
                'lastError' => $this->freeText($this->refundMeta->lastError($refund)),
                'lastAttemptAt' => $this->refundMeta->lastAttemptAt($refund),
            ];
        }

        return [
            'id' => $order->get_id(),
            'number' => $order->get_order_number(),
            'status' => $order->get_status(),
            'createdAt' => $order->get_date_created()?->format('c'),
            'paidAt' => $order->get_date_paid()?->format('c'),
            'createdVia' => $order->get_created_via(),
            'paymentMethod' => $order->get_payment_method(),
            'paymentMethodTitle' => $order->get_payment_method_title(),
            // Whether the gateway recorded a transaction at all, not which one.
            'hasTransactionId' => $order->get_transaction_id() !== '',
            'currency' => $order->get_currency(),
            'total' => $order->get_total(),
            'lineCount' => count($order->get_items()),
            'fiscal' => [
                'status' => $this->meta->status($order)?->value,
                'attempts' => $this->meta->attemptCount($order),
                'lastError' => $this->freeText($this->meta->lastError($order)),
                'lastAttemptAt' => $this->meta->lastAttemptAt($order),
                'registeredAt' => $this->meta->registeredAt($order),
                'saleId' => $this->meta->saleId($order),
                'crn' => $this->meta->crn($order),
                'fiscalNumber' => $this->meta->fiscal($order),
            ],
            'refunds' => $refunds,
        ];
    }
}
