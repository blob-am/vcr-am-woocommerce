<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Fiscal\Exception;

use RuntimeException;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * The order can't be turned into a valid SDK payload — typically a missing
 * SKU on a line item, an empty product list, or a configuration gap that
 * can only be fixed by the admin.
 *
 * Distinct from {@see \BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrException}
 * so {@see \BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalJob} can route this
 * to {@see \BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus::ManualRequired}
 * instead of treating it as a transient API failure.
 */
final class FiscalBuildException extends RuntimeException
{
}
