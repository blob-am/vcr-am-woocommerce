<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Journal;

use BlobSolutions\WooCommerceVcrAm\Logging\Logger;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The plugin's own record of what it saw and what it decided.
 *
 * It exists for one question that nothing else answers: when a merchant says
 * "the customer paid and no receipt came out", did WooCommerce ever tell the
 * plugin about the payment, and if it did, what did the plugin do next. The
 * server only knows about requests that reached it, and the commonest failure
 * is one where none was ever sent.
 *
 * Kept for a year in a table of its own. Masked on the way in, so what is
 * stored is what may be shown to the merchant and sent to support: no buyer
 * names, addresses, phones or emails.
 *
 * Not declared `final` so unit tests can mock it.
 */
class Journal
{
    public const RETENTION_DAYS = 365;

    public function __construct(
        private readonly Store $store,
        private readonly Logger $logger = new Logger(),
    ) {
    }

    /**
     * Never throws. A journal that cannot be written must not stop a receipt
     * from being registered, so a failure goes to the WooCommerce log and the
     * caller carries on.
     *
     * @param string               $event   Dotted name, e.g. `order.queued`
     * @param array<string, mixed> $data    Facts about the event; masked before storing
     * @param ?string              $traceId The server's id of the API call this line is about
     */
    public function record(string $event, ?int $orderId = null, array $data = [], ?string $traceId = null): void
    {
        try {
            $this->store->append($event, $orderId, $traceId, $this->logger->redactContext($data));
        } catch (StoreException $e) {
            $this->logger->error('Journal line was not written: ' . $e->getMessage(), [
                'event' => $event,
                'order_id' => $orderId,
            ]);
        }
    }

    /**
     * Newest first.
     *
     * @return list<Entry>
     *
     * @throws StoreException A report built from a journal that could not be
     *                        read would claim nothing happened
     */
    public function recent(?int $orderId = null, int $limit = 200): array
    {
        return $this->store->recent($orderId, $limit);
    }

    public function prune(): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS);

        try {
            $this->store->prune($cutoff);
        } catch (StoreException $e) {
            $this->logger->error('Journal was not pruned: ' . $e->getMessage());
        }
    }
}
