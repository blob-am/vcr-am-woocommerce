<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog\Coverage;

use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionFailure;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * What one pass over the store's SKUs and the register's offers found.
 *
 * Four buckets, because each one is a different thing to go and do:
 * give the product a SKU, onboard an offer, restore an archived offer, or
 * -- the honest fourth -- nothing, because the answer is not knowable from
 * what the API was willing to list. See {@see Checker::OFFER_LIST_CAP}.
 *
 * A report is never partially true: if the fetch failed at all, every
 * bucket is empty and `failure` says why. An available report with no
 * findings means the store really can fiscalise every product it sells,
 * which is the whole question the check exists to answer.
 */
final readonly class Report
{
    /**
     * @param list<StoreSku> $withoutSku  Products carrying no SKU at all.
     * @param list<StoreSku> $missing     SKUs with no offer on the register.
     * @param list<StoreSku> $archived    SKUs whose only offer is archived.
     * @param list<StoreSku> $unverified  SKUs the row cap left undecided.
     * @param list<string>   $orphanOffers External ids no product claims.
     */
    private function __construct(
        public array $withoutSku,
        public array $missing,
        public array $archived,
        public array $unverified,
        public array $orphanOffers,
        public int $checkedCount,
        public int $coveredCount,
        public bool $catalogTruncated,
        public ?ConnectionFailure $failure,
    ) {
    }

    /**
     * @param list<StoreSku> $withoutSku
     * @param list<StoreSku> $missing
     * @param list<StoreSku> $archived
     * @param list<StoreSku> $unverified
     * @param list<string>   $orphanOffers
     */
    public static function of(
        array $withoutSku,
        array $missing,
        array $archived,
        array $unverified,
        array $orphanOffers,
        int $checkedCount,
        int $coveredCount,
        bool $catalogTruncated,
    ): self {
        return new self(
            withoutSku: $withoutSku,
            missing: $missing,
            archived: $archived,
            unverified: $unverified,
            orphanOffers: $orphanOffers,
            checkedCount: $checkedCount,
            coveredCount: $coveredCount,
            catalogTruncated: $catalogTruncated,
            failure: null,
        );
    }

    public static function unavailable(ConnectionFailure $failure): self
    {
        return new self(
            withoutSku: [],
            missing: [],
            archived: [],
            unverified: [],
            orphanOffers: [],
            checkedCount: 0,
            coveredCount: 0,
            catalogTruncated: false,
            failure: $failure,
        );
    }

    /** True when the check ran to completion, whatever it found. */
    public function isAvailable(): bool
    {
        return $this->failure === null;
    }

    /**
     * True when something would stop a receipt today. `unverified` is not a
     * finding -- it is the absence of one -- and `orphanOffers` is
     * informational, so neither counts here.
     */
    public function hasBlockers(): bool
    {
        return $this->withoutSku !== [] || $this->missing !== [] || $this->archived !== [];
    }
}
