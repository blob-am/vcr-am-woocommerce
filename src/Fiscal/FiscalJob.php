<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Catalog\OfferBinding;
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
use InvalidArgumentException;
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
 *   - **Idempotent on the wire too.** Every call carries the order's
 *     `Idempotency-Key` ({@see FiscalStatusMeta::idempotencyKey()}), so the
 *     two cases the meta check cannot cover are covered by the API instead:
 *     two hooks that race past {@see FiscalQueue}'s dedup before either has
 *     committed a scheduler row, and a retry after an attempt whose outcome we
 *     never learned (a timeout mid-request may well have registered the sale).
 *     In both, the second request replays the first one's answer rather than
 *     printing a second fiscal receipt — which is a real tax document that can
 *     only be undone with a refund.
 *
 *   - **Meta is always written before throwing.** The order's status meta
 *     reflects the result of *this* attempt before the function returns,
 *     regardless of which branch the call took. The queue layer can rely
 *     on the returned {@see FiscalJobOutcome} alone (no second meta read).
 *
 *   - **Retry classification is centralised here.** HTTP 5xx, 429, network
 *     timeouts -> retriable, and so is the one 409 that means "another request
 *     is holding your idempotency key". Every other 4xx, schema validation
 *     errors, and build errors -> terminal. {@see self::isRetriableApiError()}
 *     is the single source of truth.
 *
 *   - **Max-attempts is enforced here, not in the queue.** Once the job
 *     records the attempts that error class is worth, it transitions the
 *     order to {@see FiscalStatus::Failed} — even a persistent 5xx
 *     eventually stops being retried. The budget is the full
 *     {@see self::MAX_ATTEMPTS} for a failure the SDK named and
 *     {@see self::UNCLASSIFIED_ERROR_MAX_ATTEMPTS} for one it did not.
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

    /**
     * Attempts allowed for a failure the SDK did not classify.
     *
     * The full budget spans about two and a half hours (15s, 60s, 5m, 30m, 2h),
     * which is the right shape for a tax-authority outage and the wrong shape
     * for a bug. A `TypeError` from a collaborator, a fatal from a neighbouring
     * plugin's filter, the SDK refusing an argument — none of those get better
     * on the sixth try, and while they repeat the order reads `pending` to the
     * merchant and nobody is told. One retry rides out a worker that died on a
     * memory spike; after that the order goes to the needs-attention list,
     * where re-fiscalising it is one click.
     */
    public const UNCLASSIFIED_ERROR_MAX_ATTEMPTS = 2;

    public function __construct(
        private readonly Configuration $configuration,
        private readonly SaleRegistrarFactory $registrarFactory,
        private readonly ItemBuilder $itemBuilder,
        private readonly OfferBinding $offers,
        private readonly PaymentMapper $paymentMapper,
        private readonly CommentBuilder $commentBuilder,
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
                'VCR plugin is not fully configured (missing API key or cashier). Open WooCommerce → Settings → VCR to finish setup, then retry.',
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

        // The same value on every attempt in this round — that is the
        // mechanism, not an optimisation. See
        // FiscalStatusMeta::idempotencyKey().
        $idempotencyKey = $this->meta->idempotencyKey($order);

        try {
            $registrar = $this->registrarFactory->create($apiKey);
            $response = $registrar->registerSale($payload, $idempotencyKey);
        } catch (Throwable $e) {
            return $this->handleFailure($order, $e, $attempt);
        }

        $this->meta->markSuccess($order, $response);

        // The register now holds whatever this sale described, so the products
        // on it stop being described and start being referenced. Deliberately
        // after the call, not before: a description that never reached the API
        // has to be sent again.
        $this->offers->confirm();

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

        // isFullyConfigured() guarantees the cashier is non-null at this
        // point — the assert is belt-and-braces for readers / future
        // refactors.
        assert($cashierId !== null);

        // Unset is the normal case: every line then inherits the department
        // of the offer it references, which is the one the merchant chose
        // when onboarding that offer in VCR. Stamping a single department on
        // the whole order is the override, and it makes a mixed-regime
        // catalog inexpressible — every line goes out under one regime no
        // matter what its offer says.
        $department = $departmentId === null ? null : new Department($departmentId);

        $items = $this->itemBuilder->build(
            $order,
            $department,
            $this->configuration->catalogPolicy(),
        );

        // Auto-settle: the VCR derives the whole AMD cart total (converting any
        // foreign-currency lines server-side) and charges it to the resolved
        // tender. The plugin never computes the AMD total itself.
        $autoSettle = $this->paymentMapper->map($order);

        // Merchant-internal reconciliation note (order number / gateway txn id,
        // admin-configurable). `null` when disabled or unavailable — the VCR
        // never shows it to the buyer nor forwards it to the tax authority.
        $comment = $this->commentBuilder->build($order, $this->configuration->commentSource());

        return RegisterSaleInput::withAutoSettle(
            cashier: CashierId::byInternalId($cashierId),
            items: $items,
            autoSettle: $autoSettle,
            buyer: Buyer::individual(),
            comment: $comment,
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

        if ($attempt >= $this->attemptBudget($error)) {
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

        if ($error instanceof InvalidArgumentException) {
            // The SDK (or Guzzle) refusing one of our own inputs: an
            // idempotency key over the cap, a base URL that is not a URL, a
            // User-Agent token some filter mangled. The next attempt sends the
            // same thing and earns the same refusal.
            return false;
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

        // Any other throwable (out-of-memory, plugin conflict, etc.) gets a
        // fresh worker tick to prove itself, on the short budget: see
        // UNCLASSIFIED_ERROR_MAX_ATTEMPTS.
        return true;
    }

    /**
     * How many attempts a failure of this kind is worth. Only errors the SDK
     * named — an HTTP status, a transport failure — earn the full schedule;
     * see {@see UNCLASSIFIED_ERROR_MAX_ATTEMPTS} for why.
     */
    private function attemptBudget(Throwable $error): int
    {
        return $error instanceof VcrException
            ? self::MAX_ATTEMPTS
            : self::UNCLASSIFIED_ERROR_MAX_ATTEMPTS;
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

        // 409 is two different answers on this endpoint and only the body
        // separates them. A rejection SRC actually issued always carries a
        // `pending` document — "we answered in full, and we would answer the
        // same way again" — and stays terminal. A 409 without one comes from
        // the idempotency layer: some other request holds this key right now,
        // which is precisely the double-fire the key exists to collapse, or an
        // earlier attempt died mid-request and left its claim behind. Both
        // want what a 5xx wants — come back later with the same key — and the
        // API releases an abandoned claim after a few minutes, which the later
        // slots of our backoff outlast. Calling it terminal instead would turn
        // every duplicate we successfully collapsed into an order someone has
        // to rescue by hand.
        if ($error->statusCode === 409) {
            return $error->pending === null;
        }

        return false;
    }

    private function describeError(Throwable $error): string
    {
        if ($error instanceof VcrApiException) {
            $detail = sprintf(
                'VCR API HTTP %d%s%s',
                $error->statusCode,
                // Was `apiErrorCode` until SDK 0.7.0 established there is no
                // top-level code on the wire — the branch had never fired.
                // `requestId` does arrive, and support can look it up.
                $error->requestId !== null ? ' [request ' . $error->requestId . ']' : '',
                $error->apiErrorMessage !== null ? ': ' . $error->apiErrorMessage : '',
            );

            if ($error->statusCode === 422) {
                return $this->describeIdempotencyConflict($detail);
            }

            return $detail;
        }

        return $error->getMessage();
    }

    /**
     * The API binds an idempotency key to the body it first saw and answers
     * 422 when the same key comes back with a different one; nothing else on
     * this endpoint answers 422. So this is an order that changed between the
     * first attempt and a retry — the admin edited a line, or the plugin
     * started building the payload differently across an update.
     *
     * Worth its own wording because the API's own ("use a new key per distinct
     * operation") is addressed to an integrator, and the person reading this
     * is a shop owner looking at a failed order. Re-fiscalising is the remedy
     * and it genuinely works: {@see FiscalStatusMeta::resetForRetry()} starts a
     * new attempt round, which sends a key the API has never seen.
     */
    private function describeIdempotencyConflict(string $detail): string
    {
        return sprintf(
            /* translators: 1: technical detail — HTTP status, API request id, server message. */
            __(
                'This order changed after its first fiscalisation attempt, so it no longer matches the receipt the tax service was asked to register. Use "Fiscalize now" on the order to register it as it stands. (%1$s)',
                'vcr-am-fiscal-receipts',
            ),
            $detail,
        );
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
