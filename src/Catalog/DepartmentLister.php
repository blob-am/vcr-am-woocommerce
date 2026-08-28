<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog;

use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\DepartmentListItem;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * The slice of the SDK's `VcrClient` the department dropdown needs.
 *
 * Same rationale as {@see CashierLister}: `VcrClient` is `final` in the
 * SDK, so the catalog talks to this interface and tests inject a
 * Mockery double instead of standing up Guzzle.
 */
interface DepartmentLister
{
    /**
     * @return list<DepartmentListItem>
     */
    public function listDepartments(): array;
}
