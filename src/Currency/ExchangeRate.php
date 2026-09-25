<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Currency;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * One AMD conversion rate, as the VCR resolved it.
 *
 * The VCR applies Tax Code art. 16 (ՀՕ-234-Ն): the Central Bank of Armenia
 * mid-market rate published on the **previous business day**. It returns the
 * rate already normalised to AMD per single unit, so nothing here has to know
 * that CBA quotes some currencies per lot of 100 or 1000.
 *
 * {@see self::rateDate} is the Yerevan date whose rate was applied and
 * {@see self::ruleVersion} names the rule that picked it. Both are carried so
 * a receipt can be reconciled later against the number that produced it —
 * that provenance is the reason the conversion lives server-side rather than
 * being recomputed here.
 */
final readonly class ExchangeRate
{
    public function __construct(
        public string $iso,
        public float $amdPerUnit,
        public string $rateDate,
        public string $ruleVersion,
    ) {
    }
}
