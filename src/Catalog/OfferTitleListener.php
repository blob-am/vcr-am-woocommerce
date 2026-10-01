<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog;

use WC_Product;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Turns "the merchant edited a receipt-name box" into the set of catalogue
 * offers that now disagree with it.
 *
 * It is one-to-many because of inheritance: a variation whose own box is empty
 * prints the parent's name, so editing the parent's box changes what every one
 * of those variations should be filed under -- and each variation is its own
 * offer, filed under its own id.
 *
 * Listening on an action rather than being called by {@see ReceiptNameField}
 * keeps the field ignorant of the catalogue: the field's job is a text box, and
 * a store with no API key configured still wants the box to work.
 */
final class OfferTitleListener
{
    public function __construct(
        private readonly OfferTitleQueue $queue,
        private readonly ReceiptName $receiptName = new ReceiptName(),
    ) {
    }

    public function register(): void
    {
        add_action(ReceiptNameField::CHANGED_ACTION, [$this, 'handle']);
    }

    public function handle(mixed $postId): void
    {
        if (! is_int($postId)) {
            return;
        }

        $this->queue->enqueue($postId);

        foreach ($this->inheritingChildren($postId) as $childId) {
            $this->queue->enqueue($childId);
        }
    }

    /**
     * The variations that print this product's name because they carry no name
     * of their own. A variation with its own override is unaffected by the
     * parent's box and is left out.
     *
     * @return list<int>
     */
    private function inheritingChildren(int $postId): array
    {
        $product = wc_get_product($postId);
        if (! $product instanceof WC_Product) {
            return [];
        }

        $inheriting = [];

        foreach ($product->get_children() as $childId) {
            if (! is_int($childId)) {
                continue;
            }

            if ($this->receiptName->storedOverride($childId) === null) {
                $inheriting[] = $childId;
            }
        }

        return $inheriting;
    }
}
