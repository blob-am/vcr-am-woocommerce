<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Refund;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Fiscal\Exception\FiscalBuildException;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalJob;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalJobOutcome;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Logging\Logger;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrApiException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrNetworkException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrValidationException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\CashierId;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\RegisterSaleRefundInput;
use Throwable;
use WC_Order;
use WC_Order_Refund;

/**
 * One end-to-end attempt at registering a WooCommerce refund as a
 * sale-refund against the VCR.AM API. Mirrors {@see FiscalJob}'s
 * orchestration pattern but with refund-specific gates:
 *
 *   - **Eligibility check** ({@see RefundEligibilityChecker}) routes
 *     anything that's not a clean full refund to ManualRequired with
 *     a clear admin-facing message. v0.1 does not auto-register
 *     partial refunds; see {@see RefundEligibility} class doc-block
 *     for why.
 *
 *   - **Parent-sale dependency.** The refund payload references the
 *     parent sale by `saleId`. If the parent isn't in
 *     {@see FiscalStatus::Success}, we route to ManualRequired —
 *     retrying a refund whose sale never registered is a wasted
 *     budget burn. The admin "Fiscalize refund now" button can be
 *     pressed once the parent sale registers.
 *
 *   - **No stale-skip.** Unlike sale registrations (which auto-skip
 *     after 2 hours to avoid late-registration compliance risk),
 *     refunds always retry within MAX_ATTEMPTS. Dropping a refund
 *     creates audit-visible asymmetry: the customer received money
 *     back but our SRC fiscal record shows full revenue, which looks
 *     like under-reporting in audit. Better to exhaust retries and
 *     route to ManualRequired than auto-skip silently.
 *
 *   - **Idempotent on Success.** Re-running a job for a refund that's
 *     already Success short-circuits without contacting the API.
 *
 * Reuses {@see FiscalJobOutcome} since refund and sale outcomes share
 * the exact same state shape.
 *
 * Not declared `final` so {@see RefundQueue} unit tests can mock it.
 */
class RefundJob
{
    /**
     * Same retry budget as sale jobs — refunds are no more time-
     * sensitive than sales, but no less either, and a mismatched
     * budget would surprise admins reading "max attempts" in docs.
     */
    public const MAX_ATTEMPTS = FiscalJob::MAX_ATTEMPTS;

    public function __construct(
        private readonly Configuration $configuration,
        private readonly SaleRefundRegistrarFactory $registrarFactory,
        private readonly RefundPaymentMapper $paymentMapper,
        private readonly RefundReasonMapper $reasonMapper,
        private readonly RefundEligibilityChecker $eligibilityChecker,
        private readonly RefundStatusMeta $refundMeta,
        private readonly FiscalStatusMeta $fiscalMeta,
        private readonly Logger $logger = new Logger(),
    ) {
    }

    public function run(int $refundId): FiscalJobOutcome
    {
        $refund = wc_get_order($refundId);

        if (! $refund instanceof WC_Order_Refund) {
            return FiscalJobOutcome::failed(sprintf(
                'Refund #%d not found or is not a WC_Order_Refund.',
                $refundId,
            ));
        }

        $existing = $this->refundMeta->status($refund);

        if ($existing === FiscalStatus::Success) {
            // Already done in a prior attempt — short-circuit.
            return FiscalJobOutcome::success();
        }

        $parent = wc_get_order($refund->get_parent_id());

        if (! $parent instanceof WC_Order || $parent->get_type() !== 'shop_order') {
            $reason = sprintf(
                'Parent order #%d for refund #%d not found or is not a shop order.',
                $refund->get_parent_id(),
                $refundId,
            );
            $this->refundMeta->markFailed($refund, $reason);

            return FiscalJobOutcome::failed($reason);
        }

        // Configuration gate — same defensive re-read as FiscalJob.
        $apiKey = $this->configuration->apiKey();

        if (! $this->configuration->isFullyConfigured() || $apiKey === null) {
            $reason = __(
                'VCR plugin is not fully configured (missing API key, cashier, or department). Open WooCommerce → Settings → VCR to finish setup, then retry this refund.',
                'vcr',
            );
            $this->refundMeta->markManualRequired($refund, $reason);

            return FiscalJobOutcome::manualRequired($reason);
        }

        // Eligibility — full refund vs partial vs parent-not-ready.
        $eligibility = $this->eligibilityChecker->check($refund, $parent);
        if (! $eligibility->isFullRefund) {
            $this->refundMeta->markManualRequired($refund, $eligibility->reason);

            return FiscalJobOutcome::manualRequired($eligibility->reason);
        }

        try {
            $payload = $this->buildPayload($parent, $refund);
        } catch (FiscalBuildException $e) {
            $this->refundMeta->markManualRequired($refund, $e->getMessage());

            return FiscalJobOutcome::manualRequired($e->getMessage());
        }

        $this->refundMeta->recordAttempt($refund);
        $attempt = $this->refundMeta->attemptCount($refund);

        try {
            $registrar = $this->registrarFactory->create($apiKey);
            $response = $registrar->registerSaleRefund($payload);
        } catch (Throwable $e) {
            return $this->handleFailure($refund, $parent, $e, $attempt);
        }

        $this->refundMeta->markSuccess($refund, $response);

        $parent->add_order_note(sprintf(
            /* translators: 1: refund id, 2: SRC fiscal serial (or "n/a"), 3: SRC receipt url id */
            __('VCR sale refund registered for refund #%1$d. Fiscal: %2$s. Receipt id: %3$s.', 'vcr'),
            $refund->get_id(),
            $response->fiscal ?? 'n/a',
            $response->urlId,
        ));

        return FiscalJobOutcome::success();
    }

    /**
     * @throws FiscalBuildException
     */
    private function buildPayload(WC_Order $parent, WC_Order_Refund $refund): RegisterSaleRefundInput
    {
        $cashierId = $this->configuration->defaultCashierId();
        assert($cashierId !== null); // isFullyConfigured() guarantees this

        $parentSaleId = $this->fiscalMeta->saleId($parent);
        // Eligibility check guarantees a non-null saleId at this point —
        // belt-and-braces in case the eligibility check is ever changed.
        if ($parentSaleId === null) {
            throw new FiscalBuildException(sprintf(
                'Parent order #%d has no SRC saleId; cannot reference for refund.',
                $parent->get_id(),
            ));
        }

        $reasonText = trim($refund->get_reason());

        return new RegisterSaleRefundInput(
            cashier: CashierId::byInternalId($cashierId),
            saleId: $parentSaleId,
            // items=null → full refund. Partial refunds are routed to
            // ManualRequired by RefundEligibilityChecker.
            reason: $this->reasonMapper->map($refund),
            reasonNote: $reasonText !== '' ? $reasonText : null,
            refundAmounts: $this->paymentMapper->map($parent, $refund),
            items: null,
        );
    }

    private function handleFailure(WC_Order_Refund $refund, WC_Order $parent, Throwable $error, int $attempt): FiscalJobOutcome
    {
        $message = $this->describeError($error);
        $isRetriable = $this->isRetriable($error);

        if (! $isRetriable) {
            $this->refundMeta->markFailed($refund, $message);
            $this->logAttempt($parent, $refund, $attempt, $message, terminal: true);

            return FiscalJobOutcome::failed($message);
        }

        if ($attempt >= self::MAX_ATTEMPTS) {
            // No stale-skip for refunds — the only auto-give-up trigger
            // is exhausting MAX_ATTEMPTS. See class doc-block.
            $this->refundMeta->markFailed($refund, sprintf(
                /* translators: 1: total attempts, 2: last error */
                __('Gave up after %1$d attempts. Last error: %2$s', 'vcr'),
                $attempt,
                $message,
            ));
            $this->logAttempt($parent, $refund, $attempt, $message, terminal: true);

            return FiscalJobOutcome::failed($message);
        }

        $this->refundMeta->markRetriableFailure($refund, $message);
        $this->logAttempt($parent, $refund, $attempt, $message, terminal: false);

        return FiscalJobOutcome::retriable($message);
    }

    /**
     * Same retry classification as {@see FiscalJob::isRetriable()}:
     * 5xx + 429 + network = retry; everything else = terminal.
     */
    private function isRetriable(Throwable $error): bool
    {
        if ($error instanceof VcrApiException) {
            return $error->statusCode >= 500 || $error->statusCode === 429;
        }

        if ($error instanceof VcrNetworkException) {
            return true;
        }

        if ($error instanceof VcrValidationException) {
            return false;
        }

        if ($error instanceof VcrException) {
            return false;
        }

        return true;
    }

    private function describeError(Throwable $error): string
    {
        if ($error instanceof VcrApiException) {
            return sprintf(
                'VCR API HTTP %d%s%s',
                $error->statusCode,
                $error->apiErrorCode !== null ? ' [' . $error->apiErrorCode . ']' : '',
                $error->apiErrorMessage !== null ? ': ' . $error->apiErrorMessage : '',
            );
        }

        return $error->getMessage();
    }

    /**
     * Operational log entry routed to `wc_get_logger()` (source: 'vcr').
     * Same rationale as {@see FiscalJob::logAttempt()} — internal retry
     * mechanics belong in the WC Status → Logs view, not in the
     * customer-visible order notes channel.
     */
    private function logAttempt(WC_Order $parent, WC_Order_Refund $refund, int $attempt, string $message, bool $terminal): void
    {
        $line = sprintf(
            'Refund #%d (parent order #%d) registration attempt %d/%d %s: %s',
            $refund->get_id(),
            $parent->get_id(),
            $attempt,
            self::MAX_ATTEMPTS,
            $terminal ? 'TERMINAL' : 'will retry',
            $message,
        );

        $context = [
            'order_id' => $parent->get_id(),
            'refund_id' => $refund->get_id(),
            'attempt' => $attempt,
        ];

        if ($terminal) {
            $this->logger->error($line, $context);
        } else {
            $this->logger->warning($line, $context);
        }
    }
}
