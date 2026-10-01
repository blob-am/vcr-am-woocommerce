<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * The one write the plugin makes to an existing catalogue offer.
 *
 * Separate from {@see OfferLister} on purpose: reading the catalogue happens on
 * the order path, where a failure holds a receipt, and renaming happens on the
 * product-save path, where a failure is cosmetic and retried later. Keeping the
 * two ports apart is what lets a test stand up one without the other.
 *
 * Same `final`-SDK-class rationale as the listers: callers talk to this
 * interface and tests inject a Mockery double instead of standing up Guzzle.
 */
interface OfferRenamer
{
    /**
     * Replace the offer's title. Affects future receipts only -- every issued
     * receipt froze its own copy of the name at sale time.
     *
     * @throws \Throwable when the register cannot be reached or refuses the
     *                    title; the caller decides whether that is worth a
     *                    retry.
     */
    public function rename(int $offerId, string $title): void;
}
