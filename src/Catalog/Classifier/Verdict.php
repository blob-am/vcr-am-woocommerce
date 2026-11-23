<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog\Classifier;

use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionFailure;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * What VCR says about one classifier code: the tax service accepts it, it
 * does not (with the nearest codes it does accept), or the question could
 * not be asked. The third is not the second: a host that cannot reach VCR
 * says nothing about the code, and must not be reported as if it did.
 */
final readonly class Verdict
{
    /**
     * @param list<Entry> $suggestions
     */
    private function __construct(
        public ?Entry $entry,
        public array $suggestions,
        public ?ConnectionFailure $failure,
    ) {
    }

    public static function known(Entry $entry): self
    {
        return new self($entry, [], null);
    }

    /**
     * @param list<Entry> $suggestions
     */
    public static function unknown(array $suggestions): self
    {
        return new self(null, $suggestions, null);
    }

    public static function unavailable(ConnectionFailure $failure): self
    {
        return new self(null, [], $failure);
    }

    public function isKnown(): bool
    {
        return $this->entry !== null;
    }

    /** Asked, and answered no. */
    public function isUnknown(): bool
    {
        return $this->entry === null && $this->failure === null;
    }
}
