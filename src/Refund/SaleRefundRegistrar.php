<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Refund;

use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\RegisterSaleRefundInput;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\RegisterSaleRefundResponse;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * The slice of the SDK's {@see \BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\VcrClient}
 * that the refund flow uses. Mirrors the {@see \BlobSolutions\WooCommerceVcrAm\Fiscal\SaleRegistrar}
 * pattern: a one-method interface so {@see RefundJob} can be unit-tested
 * without standing up a real `VcrClient` (final, unmockable).
 *
 * Production wiring goes through {@see SaleRefundRegistrarFactory};
 * tests inject a Mockery double and never touch the SDK.
 */
interface SaleRefundRegistrar
{
    /**
     * @param non-empty-string $idempotencyKey Stable across every retry of the
     *                                         same refund, which is what makes
     *                                         the API replay its stored answer
     *                                         instead of registering a second
     *                                         refund receipt. Required rather than
     *                                         optional: an attempt that
     *                                         forgets it is the attempt that
     *                                         duplicates.
     */
    public function registerSaleRefund(RegisterSaleRefundInput $input, string $idempotencyKey): RegisterSaleRefundResponse;
}
