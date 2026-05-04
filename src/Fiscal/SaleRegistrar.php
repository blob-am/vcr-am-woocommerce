<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\RegisterSaleInput;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\RegisterSaleResponse;

/**
 * The slice of the SDK's {@see \BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\VcrClient}
 * that the fiscalisation flow actually uses. Carved out as an interface
 * so {@see FiscalJob} can be unit-tested without standing up a real
 * `VcrClient` (which is `final` in the SDK and impossible to mock
 * directly).
 *
 * Production wiring goes through {@see SaleRegistrarFactory} which builds
 * an adapter around the SDK client. Tests inject a Mockery double of
 * this interface and skip the SDK / Guzzle stack entirely.
 *
 * Keep this interface minimal: only methods that {@see FiscalJob} calls
 * belong here. {@see \BlobSolutions\WooCommerceVcrAm\Catalog\CashierCatalog}
 * and the connection tester still use the full SDK client directly via
 * {@see \BlobSolutions\WooCommerceVcrAm\VcrClientFactory}.
 */
interface SaleRegistrar
{
    public function registerSale(RegisterSaleInput $input): RegisterSaleResponse;
}
