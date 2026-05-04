<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog;

use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\CashierListItem;

/**
 * The slice of the SDK's `VcrClient` that the cashier flows actually
 * use. Carved out as an interface so {@see CashierCatalog} and
 * {@see \BlobSolutions\WooCommerceVcrAm\Admin\ConnectionTester} can be
 * unit-tested without standing up a real `VcrClient` (which is `final`
 * in the SDK and impossible to mock directly).
 *
 * Production wiring goes through {@see CashierListerFactory}, which
 * builds an adapter around the SDK client. Tests inject a Mockery
 * double of this interface and skip the SDK / Guzzle stack entirely.
 *
 * Mirrors the pattern used by {@see \BlobSolutions\WooCommerceVcrAm\Fiscal\SaleRegistrar}
 * for the `registerSale` capability — same rationale, same shape.
 */
interface CashierLister
{
    /**
     * @return list<CashierListItem>
     */
    public function listCashiers(): array;
}
