<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Fiscal\Exception\FiscalBuildException;
use BlobSolutions\WooCommerceVcrAm\Logging\Logger;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Language;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\OfferListItem;
use Throwable;
use WC_Product;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Carries a changed receipt name through to the catalogue offer it was filed
 * under.
 *
 * Before 0.1.12 the name a product was first fiscalised with was the name it
 * kept forever: {@see OfferBinding::forProduct()} references an already-filed
 * offer by external id and sends no title with it, so editing "Name on the
 * fiscal receipt" afterwards changed nothing and said nothing. The merchant's
 * only route was to rename the item in VCR, which the readme had to tell them
 * to do.
 *
 * Receipts already issued are never touched -- each one froze its own copy of
 * the name at sale time -- so this is about what the *next* receipt prints.
 *
 * Three things it deliberately will not do:
 *
 *   - **It does not react to a product title change.** Only an edit to the
 *     receipt-name box triggers a sync (see {@see ReceiptNameField::CHANGED_ACTION}).
 *     Renaming a product is as often an SEO tweak as a decision about the
 *     receipt, and guessing which is a heuristic nobody asked for. Clearing the
 *     box is still an edit to it, and still syncs -- back to the product name.
 *   - **It does not overwrite a title localised in VCR.** The plugin only ever
 *     writes a universal title, which the API stores as a single `multi` row; a
 *     merchant who translated the title has three rows (hy/en/ru) and a rename
 *     would flatten them. Several rows means they took ownership, so the plugin
 *     leaves it alone.
 *   - **It does not resurrect an archived offer.** Archiving means they stopped
 *     selling it; renaming it would be a write to something retired.
 *
 * A failure here holds nothing up, which is the whole reason it runs off the
 * order path: the register keeps the name that already works, and the queue
 * retries.
 */
/**
 * Not declared `final` so {@see OfferTitleQueue} unit tests can mock the job;
 * there's no production extension point.
 */
class OfferTitleSync
{
    public function __construct(
        private readonly Configuration $configuration,
        private readonly OfferListerFactory $listerFactory,
        private readonly OfferRenamerFactory $renamerFactory,
        private readonly ReceiptName $receiptName = new ReceiptName(),
        private readonly Logger $logger = new Logger(),
    ) {
    }

    /**
     * @return bool true when the attempt failed for a reason that may pass --
     *              the queue decides whether another attempt is left. False
     *              means done, whether or not anything was renamed.
     */
    public function run(int $productId): bool
    {
        $product = wc_get_product($productId);
        if (! $product instanceof WC_Product) {
            return false;
        }

        $externalId = $this->boundExternalId($productId);
        if ($externalId === null) {
            // Never filed. Whatever the box says now is what the first receipt
            // will create the offer with, so there is nothing to carry over.
            return false;
        }

        $apiKey = $this->configuration->apiKey();
        if ($apiKey === null) {
            return false;
        }

        $name = $this->receiptNameOf($product);
        if ($name === null) {
            return false;
        }

        try {
            $offers = $this->listerFactory->create($apiKey)->listOffers($externalId);
        } catch (Throwable $e) {
            $this->logger->warning('Could not read the catalog to sync a receipt name', [
                'product_id' => $productId,
                'external_id' => $externalId,
                'error' => $e->getMessage(),
            ]);

            return true;
        }

        $offer = $this->liveOffer($offers, $externalId);
        if ($offer === null) {
            return false;
        }

        $stored = $this->universalTitle($offer);
        if ($stored === null) {
            $this->logger->info('Catalog item title is localised in VCR; leaving it alone', [
                'product_id' => $productId,
                'external_id' => $externalId,
                'offer_id' => $offer->id,
            ]);

            return false;
        }

        if ($stored === $name) {
            return false;
        }

        try {
            $this->renamerFactory->create($apiKey)->rename($offer->id, $name);
        } catch (Throwable $e) {
            $this->logger->warning('Could not rename a catalog item', [
                'product_id' => $productId,
                'external_id' => $externalId,
                'offer_id' => $offer->id,
                'error' => $e->getMessage(),
            ]);

            return true;
        }

        $this->logger->info('Renamed a catalog item to match the receipt name', [
            'product_id' => $productId,
            'external_id' => $externalId,
            'offer_id' => $offer->id,
        ]);

        return false;
    }

    /**
     * The name that should be on the receipt now, or null when there isn't one
     * a receipt line can carry.
     *
     * A name too long or carrying a character the line cannot hold is refused
     * on the order path, where the merchant sees it against the order they are
     * waiting on. Repeating that refusal here would only fail a background
     * action, so the register keeps the name that works.
     */
    private function receiptNameOf(WC_Product $product): ?string
    {
        try {
            return $this->receiptName->forProduct($product);
        } catch (FiscalBuildException $e) {
            $this->logger->info('Receipt name cannot go on a receipt line, so it was not synced', [
                'product_id' => $product->get_id(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param list<OfferListItem> $offers
     */
    private function liveOffer(array $offers, string $externalId): ?OfferListItem
    {
        foreach ($offers as $offer) {
            if ($offer->externalId === $externalId && $offer->archivedAt === null) {
                return $offer;
            }
        }

        return null;
    }

    /**
     * The offer's title if it is still the single language-agnostic row the
     * plugin wrote, and null once it is anything else.
     */
    private function universalTitle(OfferListItem $offer): ?string
    {
        if (count($offer->title) !== 1) {
            return null;
        }

        $only = $offer->title[0];

        return $only->language === Language::Multi ? $only->content : null;
    }

    private function boundExternalId(int $productId): ?string
    {
        $stored = get_post_meta($productId, OfferBinding::META_KEY, true);

        if (! is_string($stored)) {
            return null;
        }

        $trimmed = trim($stored);

        return $trimmed === '' ? null : $trimmed;
    }
}
