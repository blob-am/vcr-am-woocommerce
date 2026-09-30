<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Fiscal\Exception\FiscalBuildException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\Department;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\Offer;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\OfferTitle;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\OfferType;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Unit;
use Throwable;
use WC_Product;

if (! defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are diagnostic, surfaced via Logger or wp_die() (which escape themselves); per-arg esc_html on sprintf args is ritual noise.


/**
 * Which catalogue offer each order line points at, and how one comes to exist.
 *
 * Until 0.1.10 the answer was "the merchant onboards every product in VCR by
 * hand, and an order naming anything else is refused after the customer has
 * paid". That is the wall this class removes: a product the register has never
 * seen is described inline on the sale, and the API creates the offer while
 * filing the receipt -- one call, no pre-seeding, no second system to keep in
 * step.
 *
 * Three rules make that safe to repeat:
 *
 *   - **A product is described once.** The external id it was filed under is
 *     remembered on the product ({@see self::META_KEY}) the moment a sale
 *     carrying it succeeds, and every later sale references that id instead of
 *     re-describing it. So a merchant who refines the classifier code of an
 *     auto-created offer in VCR keeps that code: the plugin never sends a
 *     competing declaration, which the API would refuse as a conflict.
 *   - **A catalogue built by hand is adopted, not duplicated.** Before minting
 *     an id for a product carrying a SKU, the register is asked whether it
 *     already has a live offer under that SKU. Stores that did the manual work
 *     the old way keep their offers, with their own codes and titles.
 *   - **Identity is not the SKU.** See {@see OfferIdentity} for why.
 *
 * An unarmed {@see CatalogPolicy} leaves the pre-0.1.11 behaviour exactly as it
 * was, refusal message included.
 */
/**
 * Not declared `final` so unit tests can mock it where it is injected --
 * there's no production extension point.
 */
class OfferBinding
{
    /** The external id this product's offer lives under, once one exists. */
    public const META_KEY = '_vcr_offer_external_id';

    /** Ids for the two lines WooCommerce ships that are not products. */
    public const SHIPPING_EXTERNAL_ID = 'wc-shipping';

    public const FEE_EXTERNAL_ID = 'wc-fee';

    /**
     * Which synthetic ids the register is known to hold. A store option rather
     * than product meta, because neither line belongs to a product.
     */
    public const SYNTHETIC_OPTION = 'vcr_synthetic_offers';

    /**
     * Ids described on the sale being built and not yet confirmed by it.
     * Keyed by product id; the two synthetic lines use their own id as the key.
     *
     * @var array<int|string, string>
     */
    private array $described = [];

    public function __construct(
        private readonly Configuration $configuration,
        private readonly OfferListerFactory $listerFactory,
        private readonly DepartmentCatalog $departments,
        private readonly ReceiptName $receiptName = new ReceiptName(),
    ) {
    }

    /**
     * @throws FiscalBuildException when the line cannot be put on a receipt at
     *                              all -- see the messages, each of which names
     *                              the one thing to change.
     */
    public function forProduct(WC_Product $product, CatalogPolicy $policy): Offer
    {
        $bound = $this->boundExternalId($product);
        if ($bound !== null) {
            return Offer::existing($bound);
        }

        $sku = OfferIdentity::adoptableSku($product);
        if ($sku !== null && $this->registerHasLiveOffer($sku)) {
            // Already in the catalogue under the merchant's own id: adopt that
            // row rather than describing a second one beside it.
            $this->remember($product->get_id(), $sku);

            return Offer::existing($sku);
        }

        if (! $policy->armed()) {
            throw new FiscalBuildException(sprintf(
                'Product "%s" is not in the register\'s catalog%s, and the plugin has no classifier code to create it with. Set "Classifier code for new catalog items" in WooCommerce -> Settings -> VCR, or onboard the offer in VCR yourself.',
                $product->get_name(),
                $sku === null ? '' : sprintf(' (looked for SKU "%s")', $sku),
            ));
        }

        $externalId = OfferIdentity::mint($product);
        $this->describe($product->get_id(), $externalId);

        return Offer::createNew(
            externalId: $externalId,
            title: OfferTitle::universal($this->receiptName->forProduct($product)),
            type: $this->typeOf($product),
            classifierCode: $this->classifierCode($policy),
            defaultMeasureUnit: Unit::Piece,
            defaultDepartment: new Department($this->departmentFor($policy)),
        );
    }

    /**
     * The shipping line. A configured SKU still wins, so a store that onboarded
     * a "Shipping" offer the old way keeps using it.
     *
     * @throws FiscalBuildException
     */
    public function forShipping(CatalogPolicy $policy): Offer
    {
        return $this->forSynthetic(
            $policy->shippingSku,
            self::SHIPPING_EXTERNAL_ID,
            __('Shipping', 'vcr-am-fiscal-receipts'),
            __('Order has shipping charges, and the plugin has no classifier code to create a shipping line with. Set "Classifier code for new catalog items" in WooCommerce -> Settings -> VCR, or set a Shipping SKU pointing at an offer you onboarded in VCR.', 'vcr-am-fiscal-receipts'),
            $policy,
        );
    }

    /**
     * @throws FiscalBuildException
     */
    public function forFee(CatalogPolicy $policy): Offer
    {
        return $this->forSynthetic(
            $policy->feeSku,
            self::FEE_EXTERNAL_ID,
            __('Service fee', 'vcr-am-fiscal-receipts'),
            __('Order has fee lines (handling, surcharge, etc.), and the plugin has no classifier code to create a fee line with. Set "Classifier code for new catalog items" in WooCommerce -> Settings -> VCR, or set a Fee SKU pointing at an offer you onboarded in VCR.', 'vcr-am-fiscal-receipts'),
            $policy,
        );
    }

    /**
     * Record what the register now holds, once a sale carrying these
     * descriptions has been filed. Called by the fiscal job on success only:
     * a description that never reached the API must be sent again, not skipped.
     */
    public function confirm(): void
    {
        $synthetic = $this->syntheticIds();
        $syntheticGrew = false;

        foreach ($this->described as $key => $externalId) {
            if (is_int($key)) {
                $this->remember($key, $externalId);

                continue;
            }

            $synthetic[$externalId] = true;
            $syntheticGrew = true;
        }

        if ($syntheticGrew) {
            update_option(self::SYNTHETIC_OPTION, $synthetic);
        }

        $this->described = [];
    }

    private function forSynthetic(
        ?string $configuredSku,
        string $syntheticId,
        string $title,
        string $refusal,
        CatalogPolicy $policy,
    ): Offer {
        if ($configuredSku !== null) {
            return Offer::existing($configuredSku);
        }

        if (isset($this->syntheticIds()[$syntheticId])) {
            return Offer::existing($syntheticId);
        }

        if (! $policy->armed()) {
            throw new FiscalBuildException($refusal);
        }

        $this->describe($syntheticId, $syntheticId);

        return Offer::createNew(
            externalId: $syntheticId,
            title: OfferTitle::universal($title),
            type: OfferType::Service,
            classifierCode: $this->classifierCode($policy),
            defaultMeasureUnit: Unit::Other,
            defaultDepartment: new Department($this->departmentFor($policy)),
        );
    }

    /**
     * A virtual or downloadable product is a service; anything shippable is
     * goods. The API accepts either kind of classifier code on either type, so
     * this only decides what the receipt calls the line.
     */
    private function typeOf(WC_Product $product): OfferType
    {
        return $product->is_virtual() || $product->is_downloadable()
            ? OfferType::Service
            : OfferType::Product;
    }

    private function classifierCode(CatalogPolicy $policy): string
    {
        $code = $policy->classifierCode;

        // `armed()` is checked by every caller before reaching here.
        assert($code !== null);

        return $code;
    }

    /**
     * The department a new catalogue entry is filed under: the configured one,
     * or the register's only department when it has just one. With several and
     * nothing configured there is a real choice to make, and it is the
     * merchant's -- the same line the dashboard's offer form draws.
     *
     * @throws FiscalBuildException
     */
    private function departmentFor(CatalogPolicy $policy): int
    {
        if ($policy->departmentInternalId !== null) {
            return $policy->departmentInternalId;
        }

        $listing = $this->departments->list();

        // An unreadable list is not a list of several: saying "pick one of your
        // departments" to someone whose register we could not reach sends them
        // to the wrong screen. This one is transient and the order can be
        // retried; the other one needs a decision.
        if (! $listing->isAvailable()) {
            throw new FiscalBuildException(
                'Could not read this register\'s departments, so the plugin cannot file a new catalog item yet. The order stays unfiscalised; retry it once VCR is reachable, or pick a department for new catalog items in WooCommerce -> Settings -> VCR.',
            );
        }

        if (count($listing->entries) === 1) {
            $only = array_key_first($listing->entries);
            assert(is_int($only));

            return $only;
        }

        throw new FiscalBuildException(
            'This register has more than one department, so the plugin cannot tell which tax regime a new catalog item belongs to. Pick one under "Department for new catalog items" in WooCommerce -> Settings -> VCR.',
        );
    }

    /**
     * @throws FiscalBuildException when the catalogue cannot be read at all --
     *                              minting an id on a failed lookup would
     *                              duplicate an offer that does exist.
     */
    private function registerHasLiveOffer(string $externalId): bool
    {
        $apiKey = $this->configuration->apiKey();
        if ($apiKey === null) {
            throw new FiscalBuildException('No VCR API key is configured.');
        }

        try {
            $offers = $this->listerFactory->create($apiKey)->listOffers($externalId);
        } catch (Throwable $e) {
            throw new FiscalBuildException(sprintf(
                'Could not read the register\'s catalog to check SKU "%s" (%s). The order stays unfiscalised; retry it once VCR is reachable.',
                $externalId,
                $e->getMessage(),
            ), previous: $e);
        }

        foreach ($offers as $offer) {
            if ($offer->externalId === $externalId && $offer->archivedAt === null) {
                return true;
            }
        }

        return false;
    }

    private function boundExternalId(WC_Product $product): ?string
    {
        $stored = get_post_meta($product->get_id(), self::META_KEY, true);

        if (! is_string($stored)) {
            return null;
        }

        $trimmed = trim($stored);

        return $trimmed === '' ? null : $trimmed;
    }

    private function describe(int|string $key, string $externalId): void
    {
        $this->described[$key] = $externalId;
    }

    private function remember(int $productId, string $externalId): void
    {
        update_post_meta($productId, self::META_KEY, $externalId);
    }

    /**
     * @return array<string, bool>
     */
    private function syntheticIds(): array
    {
        $stored = get_option(self::SYNTHETIC_OPTION, []);

        if (! is_array($stored)) {
            return [];
        }

        $ids = [];
        foreach ($stored as $id => $present) {
            if (is_string($id) && $present === true) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }
}
