<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Diagnostics;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * "Which register does this key belong to?" — the one API capability the
 * diagnostics need and the fiscal path never uses.
 *
 * Its own interface, like {@see \BlobSolutions\WooCommerceVcrAm\Catalog\CashierLister},
 * because the SDK's `VcrClient` is `final` and cannot be doubled. The
 * production implementation is an anonymous class in
 * {@see IdentityReaderFactory}, which also does the API-to-domain mapping.
 */
interface IdentityReader
{
    /**
     * @throws \BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrException
     */
    public function identify(): RegisterIdentity;
}
