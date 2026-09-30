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
    /** Per-product override, set in the product's VCR panel. */
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
        $override = $this->override($product);
        $name = $this->normalise($override ?? $product->get_name());

        if ($name === '') {
            throw new FiscalBuildException(sprintf(
                'Product #%d has no name to print on a receipt. Give it a title, or set a receipt name in its VCR panel.',
                $product->get_id(),
            ));
        }

        $length = mb_strlen($name, 'UTF-8');
        if ($length > self::MAX_LENGTH) {
            throw new FiscalBuildException(sprintf(
                'The name "%s" is %d characters; a receipt line holds %d. Set a shorter receipt name in the product\'s VCR panel -- the plugin will not shorten it, because that line is what the buyer reads and what the tax service files.',
                $name,
                $length,
                self::MAX_LENGTH,
            ));
        }

        if (preg_match(self::ALLOWED_REGEX, $name) !== 1) {
            throw new FiscalBuildException(sprintf(
                'The name "%s" contains a character a receipt line cannot carry (%s). Set a receipt name without it in the product\'s VCR panel.',
                $name,
                $this->firstDisallowedCharacter($name),
            ));
        }

        return $name;
    }

    /** The stored override, or null when the product has none. */
    public function override(WC_Product $product): ?string
    {
        $stored = get_post_meta($product->get_id(), self::META_KEY, true);

        if (! is_string($stored)) {
            return null;
        }

        $trimmed = trim($stored);

        return $trimmed === '' ? null : $trimmed;
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
