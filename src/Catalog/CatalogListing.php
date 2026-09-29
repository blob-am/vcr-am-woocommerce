<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog;

use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionFailure;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * What a catalog fetch came back with: the dropdown entries, or the reason
 * there are none.
 *
 * The distinction is the point. Until 0.1.7 both catalogs returned a bare
 * array and "the API refused us", "the host cannot reach VCR" and "this
 * register genuinely has no cashiers yet" were all the same empty array —
 * so the screen had to guess, and it guessed "check your API key
 * permissions" for all three. An empty listing that is `available` means
 * the register really is empty, and that is a different sentence and a
 * different link.
 */
final readonly class CatalogListing
{
    /**
     * @param array<int, string> $entries Internal id => human-readable label.
     */
    private function __construct(
        public array $entries,
        public ?ConnectionFailure $failure,
    ) {
    }

    /**
     * @param array<int, string> $entries
     */
    public static function of(array $entries): self
    {
        return new self($entries, null);
    }

    public static function unavailable(ConnectionFailure $failure): self
    {
        return new self([], $failure);
    }

    /** True when the fetch succeeded, whatever it found. */
    public function isAvailable(): bool
    {
        return $this->failure === null;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }
}
