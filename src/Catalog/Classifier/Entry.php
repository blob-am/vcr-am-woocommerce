<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog\Classifier;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * One classifier code the tax service accepts on a receipt line, with its
 * name in the admin's language when the classifier has one.
 */
final readonly class Entry
{
    public function __construct(
        public string $code,
        public ?string $title = null,
    ) {
    }

    /** What a dropdown or a sentence shows: "6109 — T-shirts…", or the bare code. */
    public function label(): string
    {
        return $this->title === null ? $this->code : $this->code . ' — ' . $this->title;
    }
}
