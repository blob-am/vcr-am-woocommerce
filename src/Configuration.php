<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm;

use BlobSolutions\WooCommerceVcrAm\Settings\KeyStore;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\VcrClient;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Read-only view of the plugin's persisted configuration.
 *
 * Centralises the "what did the admin save?" question into one object so
 * downstream code (catalog fetchers, fiscal jobs, refunds) doesn't have
 * to know that the API key lives in a `KeyStore`-managed encrypted
 * `wp_option` while the base URL lives in a plain `wp_option` written by
 * WC's settings save flow.
 *
 * Pure read API. Writes still flow through their respective channels —
 * KeyStore for the API key (via the WC settings filter intercept),
 * WooCommerce's settings save for the base URL and toggles.
 */
/**
 * Not declared `final` so the FiscalJob and CashierCatalog unit tests can
 * mock this class via Mockery — there's no production extension point.
 */
class Configuration
{
    public const OPT_BASE_URL = 'vcr_base_url';

    public const OPT_TEST_MODE = 'vcr_test_mode';

    public const OPT_DEFAULT_CASHIER_ID = 'vcr_default_cashier_id';

    public const OPT_DEFAULT_DEPARTMENT_ID = 'vcr_default_department_id';

    public const OPT_SHIPPING_SKU = 'vcr_shipping_sku';

    public const OPT_FEE_SKU = 'vcr_fee_sku';

    /**
     * What the plugin writes into the sale's merchant-internal `comment` so the
     * VCR receipt can be reconciled back to the WooCommerce order. The comment
     * is internal only — VCR never prints it on the buyer's receipt nor sends
     * it to the tax authority. {@see Fiscal\CommentBuilder} turns the chosen
     * source into the actual string.
     */
    public const OPT_COMMENT_SOURCE = 'vcr_comment_source';

    public const COMMENT_SOURCE_OFF = 'off';

    public const COMMENT_SOURCE_ORDER_NUMBER = 'order_number';

    public const COMMENT_SOURCE_TRANSACTION_ID = 'transaction_id';

    public const COMMENT_SOURCE_ORDER_AND_TRANSACTION = 'order_and_transaction';

    /**
     * The WooCommerce order number is always present and is already the
     * plugin's reconciliation key (see FiscalStatusMeta's external id), so it's
     * the safe, useful default. A gateway transaction id is only set once the
     * payment clears and some gateways never set one, so it isn't the default.
     */
    public const DEFAULT_COMMENT_SOURCE = self::COMMENT_SOURCE_ORDER_NUMBER;

    public function __construct(
        private readonly KeyStore $keyStore,
    ) {
    }

    public function apiKey(): ?string
    {
        return $this->keyStore->get();
    }

    /**
     * Returns the configured base URL, or the SDK's built-in production
     * URL when the option is empty / whitespace. Always a non-empty
     * string suitable for direct use as a `VcrClient::$baseUrl`.
     */
    public function baseUrl(): string
    {
        $stored = get_option(self::OPT_BASE_URL, '');
        if (! is_string($stored)) {
            return VcrClient::DEFAULT_BASE_URL;
        }

        $trimmed = trim($stored);

        return $trimmed === '' ? VcrClient::DEFAULT_BASE_URL : $trimmed;
    }

    public function isTestMode(): bool
    {
        return get_option(self::OPT_TEST_MODE, 'no') === 'yes';
    }

    /**
     * Internal id of the cashier the admin picked for fiscalization.
     * `null` if nothing has been picked yet — fiscal jobs should refuse
     * to run in that state rather than guessing.
     */
    public function defaultCashierId(): ?int
    {
        $stored = get_option(self::OPT_DEFAULT_CASHIER_ID, '');
        if (! is_string($stored) && ! is_numeric($stored)) {
            return null;
        }

        $value = (int) $stored;

        return $value > 0 ? $value : null;
    }

    /**
     * Internal id of the department every line of every order is booked
     * under, overriding the one each offer carries in VCR.
     *
     * `null` — the normal case — means no override: each line inherits its
     * offer's own department, so a catalog that spans two tax regimes stays
     * expressible. Set this only when the store deliberately sells its whole
     * catalog out of one department that is not the offers' own.
     */
    public function defaultDepartmentId(): ?int
    {
        $stored = get_option(self::OPT_DEFAULT_DEPARTMENT_ID, '');
        if (! is_string($stored) && ! is_numeric($stored)) {
            return null;
        }

        $value = (int) $stored;

        return $value > 0 ? $value : null;
    }

    /**
     * SKU of the offer (pre-onboarded in the VCR catalog with its own
     * classifier code, type, and unit) that the plugin should reference
     * for shipping line items synthesised from `WC_Order::get_shipping_total()`.
     *
     * `null` when unset → {@see Fiscal\ItemBuilder} fails loudly on any
     * order with shipping > 0, routing the order to ManualRequired with
     * a clear admin message. The plugin deliberately does NOT pick a
     * classifier code on the admin's behalf — that's a compliance call
     * that belongs in the catalog onboarding flow inside VCR proper,
     * not buried in plugin code.
     */
    public function shippingSku(): ?string
    {
        return $this->nonEmptyStringOption(self::OPT_SHIPPING_SKU);
    }

    /**
     * Same contract as {@see self::shippingSku()} but for `WC_Order_Item_Fee`
     * lines (handling charges, surcharges, etc.). All fee lines on a
     * single order share this SKU; per-fee SKU mapping is a future
     * elaboration if real stores need it.
     */
    public function feeSku(): ?string
    {
        return $this->nonEmptyStringOption(self::OPT_FEE_SKU);
    }

    /**
     * Which reference goes into the fiscal comment. Always one of the
     * `COMMENT_SOURCE_*` values — an unset or stray stored value falls back to
     * {@see self::DEFAULT_COMMENT_SOURCE}, so callers never have to re-validate.
     */
    public function commentSource(): string
    {
        $stored = get_option(self::OPT_COMMENT_SOURCE, self::DEFAULT_COMMENT_SOURCE);

        $allowed = [
            self::COMMENT_SOURCE_OFF,
            self::COMMENT_SOURCE_ORDER_NUMBER,
            self::COMMENT_SOURCE_TRANSACTION_ID,
            self::COMMENT_SOURCE_ORDER_AND_TRANSACTION,
        ];

        return in_array($stored, $allowed, true) ? $stored : self::DEFAULT_COMMENT_SOURCE;
    }

    public function hasCredentials(): bool
    {
        return $this->apiKey() !== null;
    }

    private function nonEmptyStringOption(string $key): ?string
    {
        $stored = get_option($key, '');

        if (! is_string($stored)) {
            return null;
        }

        $trimmed = trim($stored);

        return $trimmed === '' ? null : $trimmed;
    }

    public function isFullyConfigured(): bool
    {
        // The department is deliberately absent: it is an optional override,
        // not a prerequisite. Requiring it was what forced every admin to
        // type a department id, and a guessed one books the store's receipts
        // under a tax regime it may not owe.
        return $this->hasCredentials()
            && $this->defaultCashierId() !== null;
    }
}
