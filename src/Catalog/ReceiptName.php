<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog;

use BlobSolutions\WooCommerceVcrAm\Fiscal\Exception\FiscalBuildException;
use Normalizer;
use WC_Product;

if (! defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are diagnostic, surfaced via Logger or wp_die() (which escape themselves); per-arg esc_html on sprintf args is ritual noise.


/**
 * The name a product goes onto a receipt under.
 *
 * A WooCommerce product title is written for a category page and can run to a
 * paragraph; a receipt line holds {@see self::MAX_LENGTH} characters, which is
 * the tax service's own cap on `goodName`. The plugin does not shorten a title
 * to fit: the line is what the buyer reads to recognise what they bought and
 * what the tax service files, so a silently cut name is a worse outcome than a
 * refused order. Instead the product carries an explicit receipt name, and
 * anything without one has to fit as it stands.
 *
 * Kept separate from {@see OfferBinding} because the name and the identity fail
 * for unrelated reasons and the merchant fixes them in different places.
 */
/**
 * Not declared `final` so unit tests can mock it where it is injected --
 * there's no production extension point.
 */
class ReceiptName
{
    /** Per-product override, set in the "Name on the fiscal receipt" box. */
    public const META_KEY = '_vcr_receipt_name';

    /**
     * The tax service caps a receipt line description at this many characters,
     * and the API enforces the same number, so a longer name is refused after
     * the customer has already paid unless it is caught here.
     */
    public const MAX_LENGTH = 50;

    /**
     * The characters the API accepts in an offer title: letters, decimal
     * digits, punctuation, symbols and plain spaces. Notably absent are
     * combining marks, which is why a decomposed accent is composed away below
     * rather than being sent and refused.
     */
    private const ALLOWED_REGEX = '/^[\p{L}\p{Nd}\p{P}\p{S}\p{Zs}]+$/u';

    private const DISALLOWED_REGEX = '/[^\p{L}\p{Nd}\p{P}\p{S}\p{Zs}]/u';

    /** Any run of whitespace, including the tabs and newlines a paste carries. */
    private const WHITESPACE_REGEX = '/[\s\p{Zs}]+/u';

    /**
     * @throws FiscalBuildException when the product has no name a receipt line
     *                              can carry, and no override supplying one.
     */
    public function forProduct(WC_Product $product): string
    {
        $override = $this->overrideFor($product);
        $name = $this->normalise($override ?? $product->get_name());

        if ($name === '') {
            throw new FiscalBuildException(sprintf(
                'Product #%d has no name to print on a receipt. Give it a title, or set "Name on the fiscal receipt" %s.',
                $product->get_id(),
                $this->whereToSetIt($product),
            ));
        }

        $length = mb_strlen($name, 'UTF-8');
        if ($length > self::MAX_LENGTH) {
            throw new FiscalBuildException(sprintf(
                'The name "%s" is %d characters; a receipt line holds %d. Set a shorter "Name on the fiscal receipt" %s -- the plugin will not shorten it, because that line is what the buyer reads and what the tax service files.',
                $name,
                $length,
                self::MAX_LENGTH,
                $this->whereToSetIt($product),
            ));
        }

        if (preg_match(self::ALLOWED_REGEX, $name) !== 1) {
            throw new FiscalBuildException(sprintf(
                'The name "%s" contains a character a receipt line cannot carry (%s). Set a "Name on the fiscal receipt" without it %s.',
                $name,
                $this->firstDisallowedCharacter($name),
                $this->whereToSetIt($product),
            ));
        }

        return $name;
    }

    /**
     * The override that applies to this product: its own, or -- for a variation
     * whose box is empty -- the parent product's.
     *
     * A variation's name is the parent's plus its attributes, so it is the
     * longest name in the catalogue and the one most likely to overflow the
     * line. A merchant who shortens the parent product and leaves the variation
     * boxes empty has already said what the receipt should read; until 0.1.12
     * that answer was ignored and every variation was refused for being too
     * long -- the exact case this field exists for.
     *
     * Inheritance is one level because that is how deep WooCommerce goes: a
     * variation's parent is always a top-level product.
     */
    public function overrideFor(WC_Product $product): ?string
    {
        $own = $this->storedOverride($product->get_id());
        if ($own !== null) {
            return $own;
        }

        $parentId = $product->get_parent_id();

        return $parentId === 0 ? null : $this->storedOverride($parentId);
    }

    /** The override stored on one post, with no inheritance. */
    public function storedOverride(int $postId): ?string
    {
        $stored = get_post_meta($postId, self::META_KEY, true);

        if (! is_string($stored)) {
            return null;
        }

        $trimmed = trim($stored);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Where the merchant actually finds the box, named the way the screen names
     * it. Until 0.1.12 all three refusals above said "the product's VCR panel",
     * which does not exist: the field is a WooCommerce product field, sitting
     * in the General tab next to Regular price.
     */
    private function whereToSetIt(WC_Product $product): string
    {
        if ($product->get_parent_id() === 0) {
            return 'under Product data -> General';
        }

        return 'on this variation under Product data -> Variations, or on the parent product under Product data -> General, which a variation with an empty box inherits';
    }

    /**
     * Collapse the whitespace a pasted title carries and compose the accents
     * it may have arrived decomposed in. Both are shape, not content: the
     * merchant did not ask for two spaces or for an `e` followed by a combining
     * acute, and sending either gets the line refused with nothing useful said.
     */
    public function normalise(string $name): string
    {
        $collapsed = preg_replace(self::WHITESPACE_REGEX, ' ', $name);

        // preg_replace returns null only on a PCRE failure (bad UTF-8 here);
        // the original string is then the honest thing to carry forward, and
        // the charset check below is what refuses it.
        $trimmed = trim($collapsed ?? $name);

        if ($trimmed === '' || ! extension_loaded('intl')) {
            return $trimmed;
        }

        $composed = Normalizer::normalize($trimmed, Normalizer::FORM_C);

        return $composed === false ? $trimmed : $composed;
    }

    private function firstDisallowedCharacter(string $name): string
    {
        if (preg_match(self::DISALLOWED_REGEX, $name, $matches) === 1) {
            return sprintf('U+%04X', mb_ord($matches[0], 'UTF-8'));
        }

        return 'unknown';
    }
}
