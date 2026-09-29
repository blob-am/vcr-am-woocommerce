<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog;

use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\OfferListItem;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * The slice of the SDK's `VcrClient` the catalog coverage check needs.
 *
 * Same rationale as {@see CashierLister}: `VcrClient` is `final` in the
 * SDK, so callers talk to this interface and tests inject a Mockery
 * double instead of standing up Guzzle.
 *
 * Archived offers are always included. The coverage check has to tell
 * "this SKU has no offer" from "this SKU's offer was archived", because
 * the first is answered by creating an offer and the second by restoring
 * one, and the API's own default hides the difference. The cost is that
 * archived rows count against the server's row cap — see
 * {@see Coverage\Checker::OFFER_LIST_CAP}.
 */
interface OfferLister
{
    /**
     * @param ?string $externalId Exact-match filter; null lists the whole
     *                            catalogue, up to the server's row cap.
     *
     * @return list<OfferListItem>
     */
    public function listOffers(?string $externalId = null): array;
}
