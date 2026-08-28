<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Settings;

use BlobSolutions\WooCommerceVcrAm\Catalog\CashierCatalog;
use BlobSolutions\WooCommerceVcrAm\Catalog\DepartmentCatalog;
use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Net\SafeUrlValidator;
use WC_Settings_Page;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * The "VCR" tab inside WooCommerce → Settings.
 *
 * Renders seven fields across two sections:
 *
 * Connection:
 *   - **API Key** — sensitive; intercepted on save and routed to KeyStore
 *     for at-rest encryption. The stored `wp_options` row stays empty so
 *     the value never leaks back into the form on subsequent renders.
 *   - **Base URL** — optional override for staging / self-hosted VCR.
 *   - **Test mode** — toggles between test and production cashiers.
 *   - **Default cashier** — dropdown populated from `listCashiers()` via
 *     {@see CashierCatalog}. Required before fiscal jobs will run.
 *   - **Default department** — dropdown populated from `listDepartments()`
 *     via {@see DepartmentCatalog}, every option labelled with its tax
 *     regime. It was a bare number input until the regime a stray "1"
 *     selects turned out to be VAT on every register — see
 *     {@see DepartmentCatalog} for why the label carries the weight here.
 *
 * Order line synthesis (optional — only needed for stores using WC's
 * built-in shipping or fee features):
 *   - **Shipping SKU** — references a pre-onboarded "shipping" offer in
 *     the VCR catalog. Without it, every order with shipping > 0 is
 *     blocked at fiscalisation time (ManualRequired).
 *   - **Fee SKU** — same idea for `WC_Order_Item_Fee` lines.
 *
 * Loaded only when WooCommerce is active (gated by
 * `Plugin::onPluginsLoaded`), so it's safe to extend `WC_Settings_Page`
 * directly without a class-exists guard at definition time.
 */
final class VcrSettingsTab extends WC_Settings_Page
{
    public function __construct(
        private readonly KeyStore $keyStore,
        private readonly CashierCatalog $cashierCatalog,
        private readonly DepartmentCatalog $departmentCatalog,
        private readonly SafeUrlValidator $urlValidator = new SafeUrlValidator(),
    ) {
        $this->id = 'vcr';
        $this->label = __('VCR', 'vcr-am-fiscal-receipts');

        parent::__construct();

        add_filter(
            'woocommerce_admin_settings_sanitize_option_vcr_api_key',
            [$this, 'interceptApiKeySave'],
            10,
            3,
        );

        // Sanitize + SSRF-validate the base URL on save. Without this the
        // typed value flows straight into wp_options, and any subsequent
        // fiscal job sends the API key to whatever URL was stored —
        // including loopback / cloud-metadata / RFC1918 if the admin
        // (or a hostile shop manager) typed one.
        add_filter(
            'woocommerce_admin_settings_sanitize_option_' . Configuration::OPT_BASE_URL,
            [$this, 'sanitizeBaseUrlSave'],
            10,
            3,
        );

        // Settings save flow: WC fires `woocommerce_update_options_<id>`
        // after persisting fields. Drop the cashier-cache transient so
        // a credentials change picks up a fresh list on the next render
        // instead of serving up to an hour of stale data.
        add_action('woocommerce_update_options_' . $this->id, [$this, 'invalidateCaches']);
    }

    /**
     * WC `sanitize_option_<id>` filter for the base URL field. Empty
     * input is allowed (means "use SDK default"). Non-empty values are
     * normalised through `esc_url_raw` (CRLF / scheme stripping) AND
     * checked against {@see SafeUrlValidator}; rejected URLs are
     * persisted as the empty string and an admin notice is queued.
     *
     * Returning the empty string on rejection (rather than throwing) is
     * the WC convention — settings filters can't gracefully halt a save
     * mid-flight, and we'd rather end up at the SDK default than at a
     * malicious URL with the API key already in the wp_options row.
     *
     * @param  mixed                $value
     * @param  array<string, mixed> $option
     * @param  mixed                $rawValue
     */
    public function sanitizeBaseUrlSave($value, array $option, $rawValue): string
    {
        $candidate = is_string($value) ? trim(esc_url_raw($value)) : '';
        if ($candidate === '') {
            return '';
        }

        $rejection = $this->urlValidator->reject($candidate);
        if ($rejection !== null) {
            add_action('admin_notices', static function () use ($rejection): void {
                printf(
                    '<div class="notice notice-error is-dismissible"><p><strong>%s</strong> %s</p></div>',
                    esc_html__('VCR base URL rejected:', 'vcr-am-fiscal-receipts'),
                    esc_html($rejection),
                );
            });

            return '';
        }

        return $candidate;
    }

    /**
     * @param  string  $current_section
     * @return array<int, array<string, mixed>>
     */
    public function get_settings($current_section = ''): array
    {
        $apiKeyPlaceholder = $this->keyStore->isSet()
            ? __('Saved — leave empty to keep current key', 'vcr-am-fiscal-receipts')
            : __('Required', 'vcr-am-fiscal-receipts');

        $cashierField = $this->buildCashierField();
        $departmentField = $this->buildDepartmentField();

        return [
            [
                'name' => __('VCR — Fiscal Receipts for Armenia', 'vcr-am-fiscal-receipts'),
                'type' => 'title',
                'desc' => (new IntroDescription())->render(),
                'id' => 'vcr_section',
            ],
            [
                'name' => __('API Key', 'vcr-am-fiscal-receipts'),
                'type' => 'password',
                'id' => 'vcr_api_key',
                'desc_tip' => __(
                    'Your VCR.AM API key. Stored encrypted at rest using your WordPress auth salt; never written to disk in plaintext.',
                    'vcr-am-fiscal-receipts',
                ),
                'placeholder' => $apiKeyPlaceholder,
            ],
            [
                'name' => __('Base URL', 'vcr-am-fiscal-receipts'),
                'type' => 'text',
                'id' => Configuration::OPT_BASE_URL,
                'desc_tip' => __(
                    'Override only for staging or self-hosted VCR deployments. Leave empty to use the production endpoint.',
                    'vcr-am-fiscal-receipts',
                ),
                'default' => '',
                'placeholder' => 'https://vcr.am/api/v1',
            ],
            [
                'name' => __('Test mode', 'vcr-am-fiscal-receipts'),
                'type' => 'checkbox',
                'id' => Configuration::OPT_TEST_MODE,
                'desc' => __('Use test cashiers instead of production. Receipts issued in this mode are not legally valid.', 'vcr-am-fiscal-receipts'),
                'default' => 'no',
            ],
            $cashierField,
            $departmentField,
            [
                'type' => 'sectionend',
                'id' => 'vcr_section',
            ],
            [
                'name' => __('Order line synthesis', 'vcr-am-fiscal-receipts'),
                'type' => 'title',
                'desc' => __(
                    'WooCommerce ships shipping and fees as separate order items. The fiscal receipt needs every line to reference a catalog offer with its own classifier code, so the plugin synthesises a SaleItem against an SKU you onboard once in the VCR dashboard. Without these SKUs configured, any order with shipping or fees is blocked from fiscalisation.',
                    'vcr-am-fiscal-receipts',
                ),
                'id' => 'vcr_synthesis_section',
            ],
            [
                'name' => __('Shipping SKU', 'vcr-am-fiscal-receipts'),
                'type' => 'text',
                'id' => Configuration::OPT_SHIPPING_SKU,
                'desc_tip' => __(
                    'External id (SKU) of a pre-onboarded "Shipping" offer in your VCR catalog. The plugin references this offer for every shipping line item; you control its classifier code, unit, and tax treatment in VCR proper.',
                    'vcr-am-fiscal-receipts',
                ),
                'default' => '',
                'placeholder' => 'shipping',
            ],
            [
                'name' => __('Fee SKU', 'vcr-am-fiscal-receipts'),
                'type' => 'text',
                'id' => Configuration::OPT_FEE_SKU,
                'desc_tip' => __(
                    'External id (SKU) of a pre-onboarded "Fee" offer in your VCR catalog. Used for every WooCommerce fee line (handling charges, surcharges, etc.).',
                    'vcr-am-fiscal-receipts',
                ),
                'default' => '',
                'placeholder' => 'service-fee',
            ],
            [
                'type' => 'sectionend',
                'id' => 'vcr_synthesis_section',
            ],
            [
                'name' => __('Reconciliation', 'vcr-am-fiscal-receipts'),
                'type' => 'title',
                'desc' => __(
                    'Attach a reference to each fiscal receipt so you can match it back to the WooCommerce order from your VCR dashboard. This note is internal to you — it is never shown to the customer and never sent to the tax authority.',
                    'vcr-am-fiscal-receipts',
                ),
                'id' => 'vcr_reconciliation_section',
            ],
            [
                'name' => __('Receipt comment', 'vcr-am-fiscal-receipts'),
                'type' => 'select',
                'id' => Configuration::OPT_COMMENT_SOURCE,
                'options' => [
                    Configuration::COMMENT_SOURCE_ORDER_NUMBER => __('WooCommerce order number', 'vcr-am-fiscal-receipts'),
                    Configuration::COMMENT_SOURCE_TRANSACTION_ID => __('Payment transaction ID', 'vcr-am-fiscal-receipts'),
                    Configuration::COMMENT_SOURCE_ORDER_AND_TRANSACTION => __('Order number + transaction ID', 'vcr-am-fiscal-receipts'),
                    Configuration::COMMENT_SOURCE_OFF => __('No comment', 'vcr-am-fiscal-receipts'),
                ],
                'desc_tip' => __(
                    'The transaction ID is your payment gateway\'s own reference (e.g. a Stripe pi_… id) and is only available once the payment has cleared.',
                    'vcr-am-fiscal-receipts',
                ),
                'default' => Configuration::DEFAULT_COMMENT_SOURCE,
            ],
            [
                'type' => 'sectionend',
                'id' => 'vcr_reconciliation_section',
            ],
        ];
    }

    /**
     * Diverts the API key away from `wp_options` and into KeyStore. Called
     * by WC's settings save flow via the
     * `woocommerce_admin_settings_sanitize_option_<id>` filter.
     *
     * Returning an empty string ensures `wp_options.vcr_api_key` is always
     * blank — the encrypted ciphertext lives only in the option managed by
     * KeyStore (`vcr_api_key_encrypted`).
     *
     * Empty submission is treated as "leave existing value alone" (the
     * common case where the admin opens the page without intending to
     * change the key).
     *
     * @param  mixed              $value
     * @param  array<string,mixed> $option
     * @param  string             $rawValue
     */
    public function interceptApiKeySave(mixed $value, array $option, string $rawValue): string
    {
        if (is_string($value)) {
            // Trim before persisting. Pasted-from-clipboard credentials
            // routinely carry leading/trailing whitespace (browsers add
            // newlines, terminals add tabs). The SRC API rejects those
            // verbatim, so without this every fiscal job would terminal-fail
            // until the admin notices and re-pastes. Trim once at the
            // boundary; downstream code can trust the stored value.
            $trimmed = trim($value);

            if ($trimmed !== '') {
                $this->keyStore->put($trimmed);
            }
        }

        return '';
    }

    public function invalidateCaches(): void
    {
        $this->cashierCatalog->refresh();
        $this->departmentCatalog->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCashierField(): array
    {
        return $this->buildCatalogSelect(
            name: __('Default cashier', 'vcr-am-fiscal-receipts'),
            optionId: Configuration::OPT_DEFAULT_CASHIER_ID,
            options: $this->cashierCatalog->list(),
            placeholder: __('— select a cashier —', 'vcr-am-fiscal-receipts'),
            emptyReason: $this->keyStore->isSet()
                ? __('No cashiers found — check your API key permissions or create one in the VCR dashboard.', 'vcr-am-fiscal-receipts')
                : __('Save your API key first; the cashier list loads from the VCR API.', 'vcr-am-fiscal-receipts'),
            desc: __('Loaded from listCashiers() and cached for one hour. Re-saving these settings forces a refresh.', 'vcr-am-fiscal-receipts'),
            descTip: __('Required before fiscal jobs will run.', 'vcr-am-fiscal-receipts'),
        );
    }

    /**
     * Every option is labelled with its tax regime, because that — not
     * the department's name or its position in the list — is what ends
     * up printed on the receipt. See {@see DepartmentCatalog}.
     *
     * @return array<string, mixed>
     */
    private function buildDepartmentField(): array
    {
        return $this->buildCatalogSelect(
            name: __('Default department', 'vcr-am-fiscal-receipts'),
            optionId: Configuration::OPT_DEFAULT_DEPARTMENT_ID,
            options: $this->departmentCatalog->list(),
            placeholder: __('— select a department —', 'vcr-am-fiscal-receipts'),
            emptyReason: $this->keyStore->isSet()
                ? __('No departments found — check your API key permissions or create one in the VCR dashboard.', 'vcr-am-fiscal-receipts')
                : __('Save your API key first; the department list loads from the VCR API.', 'vcr-am-fiscal-receipts'),
            desc: __('Loaded from listDepartments() and cached for one hour. Re-saving these settings forces a refresh.', 'vcr-am-fiscal-receipts'),
            descTip: __('The department sets the tax regime printed on every receipt this store issues. Pick the one matching how the business is registered — a mismatch is not rejected by anything, and a fiscal receipt can only be refunded and reissued, never corrected.', 'vcr-am-fiscal-receipts'),
        );
    }

    /**
     * Shared shape for the two dropdowns that are populated from the VCR
     * API. Three states:
     *
     *   1. Credentials missing → disabled placeholder pointing the admin
     *      at the API key field above.
     *   2. Credentials present, API returned nothing → disabled
     *      placeholder hinting at the cause.
     *   3. Entries available → render the dropdown.
     *
     * @param  array<int, string> $options
     * @return array<string, mixed>
     */
    private function buildCatalogSelect(
        string $name,
        string $optionId,
        array $options,
        string $placeholder,
        string $emptyReason,
        string $desc,
        string $descTip,
    ): array {
        if ($options === []) {
            return [
                'name' => $name,
                'type' => 'select',
                'id' => $optionId,
                'options' => ['' => $emptyReason],
                'desc' => $desc,
                'custom_attributes' => ['disabled' => 'disabled'],
                'default' => '',
            ];
        }

        return [
            'name' => $name,
            'type' => 'select',
            'id' => $optionId,
            'options' => ['' => $placeholder] + $options,
            'desc' => $desc,
            'desc_tip' => $descTip,
            'default' => '',
        ];
    }
}
