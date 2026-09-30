<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * What the plugin is allowed to put on a catalogue entry it creates itself.
 *
 * Both values are a merchant decision the plugin cannot make for them: the
 * classifier code says what kind of thing is being sold, and the department
 * says which tax regime it is sold under. Neither is derivable from a
 * WooCommerce product, which is why an unarmed policy makes the plugin behave
 * exactly as it did before -- reference offers the register already has, and
 * refuse the order otherwise -- rather than guess.
 *
 * The department may be left unset when the register has only one, which is the
 * single case where there is nothing to decide; {@see OfferBinding} resolves it
 * then, and refuses when a register with several departments leaves it empty.
 * The dashboard's own offer form draws the same line.
 *
 * One code for the whole store is not a compromise we invented: every
 * catalogue built through the API in production carries exactly one classifier
 * code across all of its offers, whatever the merchant chose once. A store that
 * wants a per-product code still gets it, by editing that offer in VCR
 * afterwards -- the plugin references an offer it has already created and never
 * re-declares its code.
 */
final readonly class CatalogPolicy
{
    public function __construct(
        public ?string $classifierCode = null,
        public ?int $departmentInternalId = null,
        public ?string $shippingSku = null,
        public ?string $feeSku = null,
    ) {
    }

    /** Whether the plugin may create a catalogue entry it does not find. */
    public function armed(): bool
    {
        return $this->classifierCode !== null;
    }
}
