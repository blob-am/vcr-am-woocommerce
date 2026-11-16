<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Support;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Journal\Journal;
use BlobSolutions\WooCommerceVcrAm\Journal\StoreException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrApiException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\SupportReportInput;
use InvalidArgumentException;
use WC_Order;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Sends the report the merchant has just read to VCR.AM support, with what
 * they wrote about the problem.
 *
 * Called only from an explicit action: a button on the report screen, or
 * `wp vcr report --send`. Nothing in the plugin sends a report on its own.
 *
 * Not declared `final` so unit tests can mock it.
 */
class ReportSender
{
    private const HTTP_PAYLOAD_TOO_LARGE = 413;

    private const HTTP_TOO_MANY_REQUESTS = 429;

    private const TRACE_ID_PATTERN = '/\A[0-9a-f]{32}\z/';

    public function __construct(
        private readonly Configuration $configuration,
        private readonly ReportBuilder $builder,
        private readonly ReportSubmitterFactory $submitterFactory,
        private readonly Journal $journal,
    ) {
    }

    /**
     * @param string  $message What the person saw and expected
     * @param ?string $contact Where they want the answer
     */
    public function send(?WC_Order $order, string $message, ?string $contact): SendOutcome
    {
        $orderId = $order?->get_id();

        if (trim($message) === '') {
            return SendOutcome::failed(SendProblem::MessageMissing);
        }

        $apiKey = $this->configuration->apiKey();

        if ($apiKey === null || $apiKey === '') {
            return SendOutcome::failed(SendProblem::NoApiKey);
        }

        try {
            $report = $this->builder->build($order);
        } catch (StoreException $e) {
            return SendOutcome::failed(SendProblem::ReportUnavailable, $e->getMessage());
        }

        try {
            $receipt = $this->submitterFactory->create($apiKey)->submit(new SupportReportInput(
                message: $message,
                context: $report,
                traceIds: $this->traceIds($report),
                contact: $contact === null || trim($contact) === '' ? null : trim($contact),
            ));
        } catch (InvalidArgumentException $e) {
            return $this->failed($orderId, SendProblem::Rejected, $e->getMessage());
        } catch (VcrApiException $e) {
            $problem = match ($e->statusCode) {
                self::HTTP_TOO_MANY_REQUESTS => SendProblem::TooManyReports,
                self::HTTP_PAYLOAD_TOO_LARGE => SendProblem::TooLarge,
                default => SendProblem::Rejected,
            };

            return $this->failed(
                $orderId,
                $problem,
                $e->apiErrorMessage ?? sprintf('HTTP %d', $e->statusCode),
            );
        } catch (VcrException $e) {
            return $this->failed($orderId, SendProblem::Unreachable, $e->getMessage());
        }

        $this->journal->record('report.sent', $orderId, ['reference' => $receipt->reference]);

        return SendOutcome::sent($receipt->reference);
    }

    private function failed(?int $orderId, SendProblem $problem, string $detail): SendOutcome
    {
        $this->journal->record('report.send_failed', $orderId, [
            'problem' => $problem->value,
            'error' => $detail,
        ]);

        return SendOutcome::failed($problem, $detail);
    }

    /**
     * The server's ids of the calls in the report's journal, newest first,
     * so support opens the report already pointed at them.
     *
     * @param array<string, mixed> $report
     *
     * @return list<string>
     */
    private function traceIds(array $report): array
    {
        $journal = $report['journal'] ?? [];
        $ids = [];

        if (! is_array($journal)) {
            return [];
        }

        foreach ($journal as $line) {
            $id = is_array($line) ? ($line['traceId'] ?? null) : null;

            if (is_string($id) && preg_match(self::TRACE_ID_PATTERN, $id) === 1) {
                $ids[$id] = true;
            }
        }

        return array_slice(array_map(strval(...), array_keys($ids)), 0, SupportReportInput::MAX_TRACE_IDS);
    }
}
