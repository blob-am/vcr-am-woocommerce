<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * The "name on the receipt" box on a product, and on each of its variations.
 *
 * It exists for one reason: a receipt line holds
 * {@see ReceiptName::MAX_LENGTH} characters and a product title written for a
 * category page often does not fit. The plugin will not shorten a title itself,
 * so this is where a merchant says what the buyer should see instead -- once,
 * on the product, rather than per order.
 *
 * Variations carry their own box because a variation's name is the parent's
 * plus its attributes, which is exactly the case most likely to overflow.
 */
final class ReceiptNameField
{
    /**
     * Fired with a post id when the receipt-name box on that product or
     * variation actually changed value -- set, edited, or emptied. Emptying it
     * counts: "print the product name instead" is an answer about the receipt,
     * not the absence of one.
     *
     * {@see OfferTitleListener} is what listens, so that a store with no API
     * key configured still gets a working text box.
     */
    public const CHANGED_ACTION = 'vcr_receipt_name_changed';

    public function __construct(
        private readonly ReceiptName $receiptName = new ReceiptName(),
    ) {
    }

    public function register(): void
    {
        add_action('woocommerce_product_options_general_product_data', [$this, 'renderForProduct']);
        add_action('woocommerce_process_product_meta', [$this, 'saveForProduct']);
        add_action('woocommerce_variation_options_pricing', [$this, 'renderForVariation'], 10, 3);
        add_action('woocommerce_save_product_variation', [$this, 'saveForVariation'], 10, 2);
    }

    public function renderForProduct(): void
    {
        $postId = get_the_ID();
        if (! is_int($postId)) {
            return;
        }

        woocommerce_wp_text_input([
            'id' => ReceiptName::META_KEY,
            'value' => $this->stored($postId),
            'label' => __('Name on the fiscal receipt', 'vcr-am-fiscal-receipts'),
            'description' => $this->description(),
            'desc_tip' => true,
            'custom_attributes' => ['maxlength' => (string) ReceiptName::MAX_LENGTH],
        ]);
    }

    /**
     * WooCommerce hands hook callbacks whatever the caller passed, so the id
     * arrives as `mixed` however well documented it is. See
     * project_wc_hook_typed_params.
     */
    public function saveForProduct(mixed $postId): void
    {
        if (! is_int($postId) && ! is_string($postId)) {
            return;
        }

        // Nonce: WooCommerce verifies its own product-save nonce before this
        // hook fires, and re-checking it here would be checking a nonce we did
        // not issue.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $raw = $_POST[ReceiptName::META_KEY] ?? null;

        $this->store((int) $postId, $this->clean($raw));
    }

    public function renderForVariation(mixed $loop, mixed $variationData, mixed $variation): void
    {
        if (! is_object($variation) || ! property_exists($variation, 'ID')) {
            return;
        }

        $variationId = $variation->ID;
        if (! is_int($variationId)) {
            return;
        }

        $inherited = $this->inheritedName($variation);

        woocommerce_wp_text_input([
            'id' => ReceiptName::META_KEY . '[' . (string) $variationId . ']',
            'value' => $this->stored($variationId),
            'label' => __('Name on the fiscal receipt', 'vcr-am-fiscal-receipts'),
            'description' => $this->variationDescription($inherited),
            'desc_tip' => true,
            'placeholder' => $inherited ?? '',
            'wrapper_class' => 'form-row form-row-full',
            'custom_attributes' => ['maxlength' => (string) ReceiptName::MAX_LENGTH],
        ]);
    }

    public function saveForVariation(mixed $variationId, mixed $loop): void
    {
        if (! is_int($variationId) && ! is_string($variationId)) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $submitted = $_POST[ReceiptName::META_KEY] ?? null;
        if (! is_array($submitted)) {
            return;
        }

        $this->store((int) $variationId, $this->clean($submitted[$variationId] ?? null));
    }

    private function description(): string
    {
        return sprintf(
            /* translators: %d: maximum characters a receipt line holds. */
            __('Leave empty to print the product name. Set it when that name is longer than %d characters, which is all a receipt line holds -- the plugin will not shorten it for you, because this is the line the buyer reads to recognise what they bought. Variations whose own box is empty print this name too.', 'vcr-am-fiscal-receipts'),
            ReceiptName::MAX_LENGTH,
        );
    }

    /**
     * A variation's own name is the parent's plus its attributes, so it is the
     * longest name in the catalogue. Which fallback applies decides which
     * sentence is useful here, so the two cases get their own wording rather
     * than one that hedges between them.
     */
    private function variationDescription(?string $inherited): string
    {
        if ($inherited !== null) {
            return sprintf(
                /* translators: 1: receipt name inherited from the parent product, 2: maximum characters a receipt line holds. */
                __('Leave empty to use the parent product\'s receipt name, "%1$s". A receipt line holds %2$d characters.', 'vcr-am-fiscal-receipts'),
                $inherited,
                ReceiptName::MAX_LENGTH,
            );
        }

        return sprintf(
            /* translators: %d: maximum characters a receipt line holds. */
            __('Leave empty to print the variation name -- the product name plus its attributes, which is the longest name in your catalog. A receipt line holds %d characters, so this is the box most likely to need filling in.', 'vcr-am-fiscal-receipts'),
            ReceiptName::MAX_LENGTH,
        );
    }

    /**
     * The parent product's override, which an empty variation box falls back
     * to. Shown as the placeholder so the box says what will actually be
     * printed instead of looking unset.
     */
    private function inheritedName(object $variation): ?string
    {
        if (! property_exists($variation, 'post_parent')) {
            return null;
        }

        $parentId = $variation->post_parent;

        return is_int($parentId) && $parentId > 0
            ? $this->receiptName->storedOverride($parentId)
            : null;
    }

    private function stored(int $postId): string
    {
        return $this->receiptName->storedOverride($postId) ?? '';
    }

    /**
     * Unslashed and sanitized where the request is read, which is both what
     * WordPress's own sniffs look for and where a reader expects it -- a
     * sanitize call a method deeper reads like the raw value is being passed
     * around.
     */
    private function clean(mixed $submitted): ?string
    {
        if (! is_string($submitted)) {
            return null;
        }

        return trim(sanitize_text_field(wp_unslash($submitted)));
    }

    /**
     * An empty box removes the override rather than storing a blank name, so
     * "no override" has one representation and the product falls back to its
     * own title.
     *
     * Writes only on an actual change, and announces the ones it makes. A
     * product save fires this hook whether or not the box was touched, so
     * comparing first is what keeps "the merchant edited the receipt name" from
     * meaning "the merchant pressed Update".
     */
    private function store(int $postId, ?string $value): void
    {
        if ($value === null) {
            return;
        }

        $previous = $this->receiptName->storedOverride($postId);

        if ($value === '') {
            if ($previous === null) {
                return;
            }

            delete_post_meta($postId, ReceiptName::META_KEY);
            do_action(self::CHANGED_ACTION, $postId);

            return;
        }

        if ($previous === $value) {
            return;
        }

        update_post_meta($postId, ReceiptName::META_KEY, $value);
        do_action(self::CHANGED_ACTION, $postId);
    }
}
