<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Fiscal\Exception\FiscalBuildException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\Department;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\Offer;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\SaleItem;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Unit;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

/**
 * Convert a {@see WC_Order}'s line items into the SDK's {@see SaleItem}
 * shape.
 *
 * Phase 3b first cut intentionally narrows the surface:
 *
 *   - **SKU is mandatory.** Each line item's product must carry a non-empty
 *     SKU; we reference offers by `Offer::existing(externalId=sku)` and
 *     leave catalog onboarding (uploading offers with classifierCode +
 *     defaultMeasureUnit + type) to the admin's regular VCR catalog flow,
 *     not to the WC plugin. Lines without a SKU raise {@see FiscalBuildException}
 *     so the order goes to {@see FiscalStatus::ManualRequired} — the admin
 *     fixes the product, then re-triggers fiscalisation from the order
 *     meta box (Phase 3c).
 *   - **Default Unit is `Piece`.** Per-product unit overrides come in a
 *     later phase via product meta.
 *   - **No item-level discounts.** Coupons and per-line discounts are
 *     baked into the unit price (`(line total + tax) / qty`), so the sum
 *     of line totals matches the WC order total exactly. The SaleItem's
 *     `Discounts` field stays unset.
 *   - **No emarks (excise marks).** Excise-marked goods (alcohol, tobacco,
 *     pharma — Govt Decision 1976-N) need per-product mark codes captured
 *     somewhere in WC; that capture UI is a separate phase. Stores that
 *     don't sell marked goods aren't affected.
 *
 * Tax handling: Armenian retail prices are quoted **VAT-inclusive**. We
 * pass the inclusive unit price to the SDK; the SRC infers the VAT split
 * from the cashier's tax regime. Sending `(total + total_tax) / qty`
 * matches that convention regardless of how WC displays prices.
 */
/**
 * Not declared `final` so unit tests can mock this builder when testing
 * downstream orchestrators (FiscalJob) — there's no production extension
 * point.
 */
class ItemBuilder
{
    /**
     * Largest precision the SDK's decimal-string regex tolerates while
     * staying well within typical Armenian retail accuracy (AMD has no
     * fractional unit, but multi-currency stores and weighted goods do
     * benefit from the room).
     */
    private const DECIMAL_PRECISION = 8;

    /**
     * @return list<SaleItem>
     *
     * @throws FiscalBuildException
     */
    public function build(WC_Order $order, Department $department): array
    {
        $built = [];

        foreach ($order->get_items() as $item) {
            if (! $item instanceof WC_Order_Item_Product) {
                // Fees, shipping lines, taxes — not fiscalisable as items.
                // (Shipping and fees show on the receipt total, not as line
                // items. Phase 3+ may add explicit "service" line items.)
                continue;
            }

            $built[] = $this->buildOne($item, $department);
        }

        if ($built === []) {
            throw new FiscalBuildException(
                'Order has no fiscalisable line items — only fees, shipping, or empty.',
            );
        }

        return $built;
    }

    private function buildOne(WC_Order_Item_Product $item, Department $department): SaleItem
    {
        $product = $item->get_product();

        // WC_Order_Item_Product::get_product() returns the product on
        // success and either false (product missing) or — historically —
        // true on certain edge cases. Anything that isn't a real product
        // means we can't build a SaleItem for this line.
        if (! $product instanceof WC_Product) {
            throw new FiscalBuildException(sprintf(
                'Order item "%s" references a product that no longer exists. Restore the product or remove the line before fiscalising.',
                $item->get_name(),
            ));
        }

        $sku = trim($product->get_sku());

        if ($sku === '') {
            throw new FiscalBuildException(sprintf(
                'Product "%s" has no SKU. The VCR plugin uses the SKU as the catalog reference; assign one (and onboard the offer in VCR) before fiscalising.',
                $product->get_name(),
            ));
        }

        $quantity = $this->normaliseQuantity($item->get_quantity());

        if ($quantity === '0') {
            throw new FiscalBuildException(sprintf(
                'Product "%s" has zero quantity on this order.',
                $product->get_name(),
            ));
        }

        $price = $this->unitPriceInclusive($item, $quantity);

        return new SaleItem(
            offer: Offer::existing($sku),
            department: $department,
            quantity: $quantity,
            price: $price,
            unit: Unit::Piece,
        );
    }

    /**
     * Compute unit price from the line total. We use `total + total_tax`
     * (post-coupon, VAT-inclusive) rather than `WC_Product::get_price()` so
     * coupons that apply at line scope are honoured and the receipt total
     * lines up with what the buyer was charged.
     *
     * Quantity has already been validated as a positive decimal string by
     * {@see self::normaliseQuantity()}.
     */
    private function unitPriceInclusive(WC_Order_Item_Product $item, string $quantity): string
    {
        $lineTotalInclusive = (float) $item->get_total() + (float) $item->get_total_tax();
        $qty = (float) $quantity;

        if ($qty <= 0.0) {
            throw new FiscalBuildException(sprintf(
                'Order item "%s" has invalid quantity "%s".',
                $item->get_name(),
                $quantity,
            ));
        }

        return $this->formatDecimal($lineTotalInclusive / $qty);
    }

    private function normaliseQuantity(int|float $quantity): string
    {
        if ($quantity < 0) {
            throw new FiscalBuildException(sprintf(
                'Negative line quantity (%s) is not fiscalisable as a sale.',
                (string) $quantity,
            ));
        }

        return $this->formatDecimal((float) $quantity);
    }

    /**
     * Format any non-negative number as a decimal string the SDK accepts
     * (`/^\d+(\.\d+)?$/`). Strips trailing zeros and a dangling decimal
     * point so quantity `1.0` becomes `"1"`, not `"1.00000000"`.
     */
    private function formatDecimal(float $value): string
    {
        if ($value <= 0.0) {
            return '0';
        }

        $formatted = number_format($value, self::DECIMAL_PRECISION, '.', '');

        if (str_contains($formatted, '.')) {
            $formatted = rtrim($formatted, '0');
            $formatted = rtrim($formatted, '.');
        }

        return $formatted === '' ? '0' : $formatted;
    }
}
