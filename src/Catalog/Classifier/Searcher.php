<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog\Classifier;

use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Language;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\ClassifierSearchItem;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\OfferType;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * The one SDK call {@see Directory} needs, behind an interface so tests can
 * answer it without a network.
 */
interface Searcher
{
    /**
     * @return list<ClassifierSearchItem>
     */
    public function search(string $query, OfferType $type, Language $language): array;
}
