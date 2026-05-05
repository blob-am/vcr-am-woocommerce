<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Fiscal\Exception\FiscalBuildException;
use BlobSolutions\WooCommerceVcrAm\Logging\Logger;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrApiException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrNetworkException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrValidationException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\Buyer;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\CashierId;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\Department;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\RegisterSaleInput;
use Throwable;
use WC_Order;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * One end-to-end attempt at fiscalising a WooCommerce order against the
 * VCR.AM API. Pure orchestration — no scheduling logic (that lives in
 * {@see FiscalQueue}).
 *
 * Invariants:
 *
 *   - **Idempotent on Success.** If the order already has
 *     {@see FiscalStatus::Success} in meta, {@see self::run()} short-circuits
 *     without contacting the API. Combined with the WC order hooks (which
 *     can fire multiple times per order during the payment flow) this
 *     prevents duplicate registrations even if the queue mis-routes.
 *
 *   - **Meta is always written before throwing.** The order's status meta
 *     reflects the result of *this* attempt before the function returns,
 *     regardless of which branch the call took. The queue layer can rely
 *     on the returned {@see FiscalJobOutcome} alone (no second meta read).
 *
 *   - **Retry classification is centralised here.** HTTP 5xx, 429, network
 *     timeouts -> retriable. HTTP 4xx (other than 429), schema validation
 *     errors, and build errors -> terminal. {@see self::isRetriableApiError()}
 *     is the single source of truth.
 *
 *   - **Max-attempts is enforced here, not in the queue.** Once the job
 *     records the configured number of attempts, it transitions the order
 *     to {@see FiscalStatus::Failed} regardless of error class — even a
 *     persistent 5xx eventually stops being retried.
 */
/**
 * Not declared `final` so the FiscalQueue unit tests can mock the job —
 * there's no production extension point.
 */
class FiscalJob
{
    /**
     * Total number of attempts before the job gives up and marks the
     * order {@see FiscalStatus::Failed}. Includes the initial attempt.
     * The queue's backoff schedule has `MAX_ATTEMPTS - 1` retry delays.
     */
    public const MAX_ATTEMPTS = 6;

    public function __construct(
        private readonly Configuration $configuration,
        private readonly SaleRegistrarFactory $registrarFactory,
        private readonly ItemBuilder $itemBuilder,
        private readonly PaymentMapper $paymentMapper,
        private readonly FiscalStatusMeta $meta,
        private readonly Logger $logger = new Logger(),
    ) {
    }

    public function run(int $orderId): FiscalJobOutcome
    {
        $order = wc_get_order($orderId);

        // wc_get_order() returns WC_Order | WC_Order_Refund | false.
        // WC_Order_Refund extends WC_Order, so a plain `instanceof
        // WC_Order` check would let refunds through — and refunds need
        // the separate `registerSaleRefund` SDK endpoint (Phase 3e), not
        // `registerSale`. Filter on `get_type()` so refunds, draft
        // orders, and any future order subtypes route to the failure
        // branch instead of being mis-fiscalised.
        if (! $order instanceof WC_Order || $order->get_type() !== 'shop_order') {
            return FiscalJobOutcome::failed(sprintf('Order #%d not found or not a fiscalisable shop order.', $orderId));
        }

        $existing = $this->meta->status($order);

        if ($existing === FiscalStatus::Success) {
            return FiscalJobOutcome::success();
        }

        // Configuration gate. We re-read the API key explicitly below
        // because `isFullyConfigured()` is a check against persisted
        // state at THIS moment — the key could be cleared by a parallel
        // request between this gate and the registrar build. Treating
        // that disappearance as "config gap" instead of a transient
        // failure prevents wasting the entire retry budget on something
        // that needs admin intervention.
        $apiKey = $this->configuration->apiKey();

        if (! $this->configuration->isFullyConfigured() || $apiKey === null) {
            $reason = __(
                'VCR plugin is not fully configured (missing API key, cashier, or department). Open WooCommerce → Settings → VCR to finish setup, then retry.',
                'vcr-am-fiscal-receipts',
            );

            $this->meta->markManualRequired($order, $reason);

            return FiscalJobOutcome::manualRequired($reason);
        }

        try {
            $payload = $this->buildPayload($order);
        } catch (FiscalBuildException $e) {
            $this->meta->markManualRequired($order, $e->getMessage());

            return FiscalJobOutcome::manualRequired($e->getMessage());
        }

        $this->meta->recordAttempt($order);
        $attempt = $this->meta->attemptCount($order);

        try {
            $registrar = $this->registrarFactory->create($apiKey);
            $response = $registrar->registerSale($payload);
        } catch (Throwable $e) {
            return $this->handleFailure($order, $e, $attempt);
        }

        $this->meta->markSuccess($order, $response);

        $order->add_order_note(sprintf(
            /* translators: 1: SRC fiscal serial number, 2: customer-facing receipt URL slug. */
            __('VCR fiscal receipt registered. Fiscal: %1$s. Receipt id: %2$s.', 'vcr-am-fiscal-receipts'),
            $response->fiscal,
            $response->urlId,
        ));

        return FiscalJobOutcome::success();
    }

    /**
     * @throws FiscalBuildException
     */
    private function buildPayload(WC_Order $order): RegisterSaleInput
    {
        $cashierId = $this->configuration->defaultCashierId();
        $departmentId = $this->configuration->defaultDepartmentId();

        // isFullyConfigured() guarantees both are non-null at this point —
        // the asserts are belt-and-braces for readers / future refactors.
        assert($cashierId !== null);
        assert($departmentId !== null);

        $department = new Department($departmentId);
        $items = $this->itemBuilder->build(
            $order,
            $department,
            shippingSku: $this->configuration->shippingSku(),
            feeSku: $this->configuration->feeSku(),
        );
        $amount = $this->paymentMapper->map($order);

        return new RegisterSaleInput(
            cashier: CashierId::byInternalId($cashierId),
            items: $items,
            amount: $amount,
            buyer: Buyer::individual(),
        );
    }

    private function handleFailure(WC_Order $order, Throwable $error, int $attempt): FiscalJobOutcome
    {
        $message = $this->describeError($error);
        $isRetriable = $this->isRetriable($error);

        if (! $isRetriable) {
            $this->meta->markFailed($order, $message);
            $this->logAttempt($order, $attempt, $message, terminal: true);

            return FiscalJobOutcome::failed($message);
        }

        if ($attempt >= self::MAX_ATTEMPTS) {
            // Gave it the full retry budget — flip to terminal so we stop
            // taking up queue slots and the order shows up in admin's
            // "needs attention" view.
            $this->meta->markFailed($order, sprintf(
                /* translators: 1: total number of attempts, 2: error message from the last attempt. */
                __('Gave up after %1$d attempts. Last error: %2$s', 'vcr-am-fiscal-receipts'),
                $attempt,
                $message,
            ));
            $this->logAttempt($order, $attempt, $message, terminal: true);

            return FiscalJobOutcome::failed($message);
        }

        $this->meta->markRetriableFailure($order, $message);
        $this->logAttempt($order, $attempt, $message, terminal: false);

        return FiscalJobOutcome::retriable($message);
    }

    private function isRetriable(Throwable $error): bool
    {
        if ($error instanceof VcrApiException) {
            return $this->isRetriableApiError($error);
        }

        if ($error instanceof VcrNetworkException) {
            return true;
        }

        if ($error instanceof VcrValidationException) {
            // Schema mismatch on the response body is not something the
            // server will fix on a retry — treat as terminal so admin can
            // get an SDK update.
            return false;
        }

        if ($error instanceof VcrException) {
            // Future SDK exception subclasses we don't know about — be
            // conservative and treat as terminal so we don't loop on
            // something fundamentally broken.
            return false;
        }

        // Any other throwable (out-of-memory, plugin conflict, etc.) is
        // treated as transient — a fresh worker tick may have a clean slate.
        return true;
    }

    /**
     * 5xx and 429 are the canonical "try again later" responses. Other 4xx
     * codes mean the request itself is broken (bad payload, bad auth) and
     * retrying without changing inputs will fail the same way — terminal.
     */
    private function isRetriableApiError(VcrApiException $error): bool
    {
        if ($error->statusCode >= 500) {
            return true;
        }

        if ($error->statusCode === 429) {
            return true;
        }

        return false;
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
     * Operational log entry routed to `wc_get_logger()` (source: 'vcr',
     * visible at WooCommerce → Status → Logs). NOT an order note — retry
     * mechanics are internal diagnostics, not customer-facing audit
     * trail. The order note channel is reserved for outcomes the customer
     * would care about (Success, ManualRequired requiring admin review).
     *
     * Terminal failures are logged at `error` level so they show up in
     * any "show me only errors" filter; retriable mid-attempts are
     * `warning`-level (worth noting, not an emergency).
     */
    private function logAttempt(WC_Order $order, int $attempt, string $message, bool $terminal): void
    {
        $line = sprintf(
            'Order #%d fiscalisation attempt %d/%d %s: %s',
            $order->get_id(),
            $attempt,
            self::MAX_ATTEMPTS,
            $terminal ? 'TERMINAL' : 'will retry',
            $message,
        );

        if ($terminal) {
            $this->logger->error($line, ['order_id' => $order->get_id(), 'attempt' => $attempt]);
        } else {
            $this->logger->warning($line, ['order_id' => $order->get_id(), 'attempt' => $attempt]);
        }
    }
}
