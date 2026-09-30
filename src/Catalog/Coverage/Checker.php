<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog\Coverage;

use BlobSolutions\WooCommerceVcrAm\Catalog\OfferListerFactory;
use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionFailure;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionProblem;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ProblemClassifier;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\OfferListItem;
use Throwable;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Answers, before an order arrives, which products this store cannot
 * fiscalise yet.
 *
 * The failure it exists to pre-empt is silent: a product with no SKU stops
 * its order at {@see \BlobSolutions\WooCommerceVcrAm\Fiscal\ItemBuilder},
 * and a SKU with no offer behind it is only refused by the API once the
 * customer has already paid. Both leave the order sitting unfiscalised
 * with nobody told. Reading the two catalogues against each other turns
 * that into a list the merchant can work through at their own pace.
 */
/**
 * Not declared `final` so unit tests can mock it where it is injected --
 * there's no production extension point. (Same convention as our other
 * DI-injected services.)
 */
class Checker
{
    /**
     * The row cap `GET /offers` applies server-side. The API returns a bare
     * array with no total and no cursor, so a full catalogue and a
     * catalogue cut off at this many rows are indistinguishable in the
     * response -- which is exactly why hitting it has to change the answer
     * rather than be ignored.
     */
    public const OFFER_LIST_CAP = 500;

    /**
     * How many exact-match lookups the check will spend resolving SKUs the
     * cap left undecided. A bounded budget, because the alternative on a
     * large store is one HTTP request per product.
     */
    public const MAX_EXACT_LOOKUPS = 100;

    public function __construct(
        private readonly Configuration $configuration,
        private readonly OfferListerFactory $listerFactory,
        private readonly StoreSkuReader $skuReader,
        private readonly ProblemClassifier $classifier = new ProblemClassifier(),
    ) {
    }

    /**
     * @param bool $includeOrphans Also report offers no product claims. Off
     *                             by default: a register's catalogue
     *                             legitimately holds offers this store does
     *                             not sell -- walk-in goods, services, items
     *                             sold on another channel -- so for most
     *                             merchants the list is noise, not a finding.
     */
    public function check(bool $includeOrphans = false): Report
    {
        $apiKey = $this->configuration->apiKey();
        if ($apiKey === null) {
            return Report::unavailable(
                new ConnectionFailure(ConnectionProblem::NoApiKey),
            );
        }

        $references = $this->skuReader->read();

        try {
            $lister = $this->listerFactory->create($apiKey);
            $offers = $lister->listOffers();
        } catch (Throwable $e) {
            return Report::unavailable($this->classifier->classify($e));
        }

        $truncated = count($offers) >= self::OFFER_LIST_CAP;
        $index = $this->indexByExternalId($offers);

        $withoutSku = [];
        $distinct = [];
        $seen = [];
        foreach ($references as $reference) {
            if ($reference->sku === '') {
                $withoutSku[] = $reference;

                continue;
            }

            // A variation with no SKU of its own reports its parent's, so
            // one SKU can arrive many times. It is still one offer.
            if (isset($seen[$reference->sku])) {
                continue;
            }

            $seen[$reference->sku] = $reference->sku;
            $distinct[] = $reference;
        }

        $missing = [];
        $archived = [];
        $unverified = [];
        $covered = 0;
        $lookupsSpent = 0;

        foreach ($distinct as $reference) {
            if (isset($index['live'][$reference->sku])) {
                $covered++;

                continue;
            }

            if (isset($index['archived'][$reference->sku])) {
                $archived[] = $reference;

                continue;
            }

            if (! $truncated) {
                $missing[] = $reference;

                continue;
            }

            // The listing was cut off, so it is a subset of the real
            // catalogue: absence from it proves nothing. Presence still
            // does, which is why only this branch needs a second call.
            if ($lookupsSpent >= self::MAX_EXACT_LOOKUPS) {
                $unverified[] = $reference;

                continue;
            }

            $lookupsSpent++;

            try {
                $exact = $this->indexByExternalId($lister->listOffers($reference->sku));
            } catch (Throwable $e) {
                return Report::unavailable($this->classifier->classify($e));
            }

            if (isset($exact['live'][$reference->sku])) {
                $covered++;
            } elseif (isset($exact['archived'][$reference->sku])) {
                $archived[] = $reference;
            } else {
                $missing[] = $reference;
            }
        }

        return Report::of(
            withoutSku: $withoutSku,
            missing: $missing,
            archived: $archived,
            unverified: $unverified,
            orphanOffers: $this->orphans($includeOrphans, $truncated, $index['live'], $seen),
            checkedCount: count($distinct),
            coveredCount: $covered,
            catalogTruncated: $truncated,
            catalogArmed: $this->configuration->catalogPolicy()->armed(),
        );
    }

    /**
     * @param  array<array-key, string> $live
     * @param  array<array-key, string> $seen
     * @return list<string>
     */
    private function orphans(bool $requested, bool $truncated, array $live, array $seen): array
    {
        // Under truncation the live set is itself incomplete, so "no
        // product claims this" would be the one claim we cannot make.
        if (! $requested || $truncated) {
            return [];
        }

        return array_values(array_diff_key($live, $seen));
    }

    /**
     * Splits the offers into the ones a SKU can be fiscalised against and
     * the ones that would have to be restored first. A live offer always
     * wins: the API keeps `externalId` unique per register, so the two
     * cannot both hold, and if that ever changed the answer the merchant
     * needs is still "this one works".
     *
     * Keys carry the external id as their value too, because PHP turns a
     * numeric-string key into an int and a SKU is often all digits -- the
     * value is the only place the original string survives.
     *
     * @param  list<OfferListItem>                                                 $offers
     * @return array{live: array<array-key, string>, archived: array<array-key, string>}
     */
    private function indexByExternalId(array $offers): array
    {
        $live = [];
        $archived = [];

        foreach ($offers as $offer) {
            $externalId = $offer->externalId;
            if ($externalId === null || $externalId === '') {
                // An offer carrying no external id is unreachable by SKU.
                continue;
            }

            if ($offer->archivedAt === null) {
                $live[$externalId] = $externalId;
                unset($archived[$externalId]);

                continue;
            }

            if (! isset($live[$externalId])) {
                $archived[$externalId] = $externalId;
            }
        }

        return ['live' => $live, 'archived' => $archived];
    }
}
