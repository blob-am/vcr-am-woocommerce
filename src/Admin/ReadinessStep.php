<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Admin;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * One line of the setup checklist: what it is about, what is true, and how
 * much that matters.
 *
 * `$label` is a short noun ("Connection", "Cashier"); `$text` is already-
 * escaped HTML, because several steps end in a link. Building it as HTML
 * here rather than escaping in the renderer keeps the link text and its
 * sentence in one place — {@see ReadinessPanel} escapes every value it
 * interpolates as it goes.
 */
final readonly class ReadinessStep
{
    public function __construct(
        public string $label,
        public string $text,
        public ReadinessLevel $level,
    ) {
    }
}
