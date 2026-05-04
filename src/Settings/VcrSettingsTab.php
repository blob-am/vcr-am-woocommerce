<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Settings;

use BlobSolutions\WooCommerceVcrAm\Catalog\CashierCatalog;
use BlobSolutions\WooCommerceVcrAm\Configuration;
use WC_Settings_Page;

/**
 * The "VCR" tab inside WooCommerce → Settings.
 *
 * Renders five fields:
 *
 *   - **API Key** — sensitive; intercepted on save and routed to KeyStore
 *     for at-rest encryption. The stored `wp_options` row stays empty so
 *     the value never leaks back into the form on subsequent renders.
 *   - **Base URL** — optional override for staging / self-hosted VCR.
 *   - **Test mode** — toggles between test and production cashiers.
 *   - **Default cashier** — dropdown populated from `listCashiers()` via
 *     {@see CashierCatalog}. Required before fiscal jobs will run.
 *   - **Default department ID** — numeric input. The SDK does not yet
 *     expose `listDepartments`, so the admin enters the integer id from
 *     the VCR dashboard manually until that endpoint is published.
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
    ) {
        $this->id = 'vcr';
        $this->label = __('VCR', 'vcr');

        parent::__construct();

        add_filter(
            'woocommerce_admin_settings_sanitize_option_vcr_api_key',
            [$this, 'interceptApiKeySave'],
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
     * @param  string  $current_section
     * @return array<int, array<string, mixed>>
     */
    public function get_settings($current_section = ''): array
    {
        $apiKeyPlaceholder = $this->keyStore->isSet()
            ? __('Saved — leave empty to keep current key', 'vcr')
            : __('Required', 'vcr');

        $cashiers = $this->cashierCatalog->list();
        $cashierField = $this->buildCashierField($cashiers);

        return [
            [
                'name' => __('VCR — Fiscal Receipts for Armenia', 'vcr'),
                'type' => 'title',
                'desc' => __(
                    'Connect your store to the VCR.AM gateway. Fiscal receipts (e-HDM) are issued directly to the Armenian State Revenue Committee on every paid order.',
                    'vcr',
                ),
                'id' => 'vcr_section',
            ],
            [
                'name' => __('API Key', 'vcr'),
                'type' => 'password',
                'id' => 'vcr_api_key',
                'desc_tip' => __(
                    'Your VCR.AM API key. Stored encrypted at rest using your WordPress auth salt; never written to disk in plaintext.',
                    'vcr',
                ),
                'placeholder' => $apiKeyPlaceholder,
            ],
            [
                'name' => __('Base URL', 'vcr'),
                'type' => 'text',
                'id' => Configuration::OPT_BASE_URL,
                'desc_tip' => __(
                    'Override only for staging or self-hosted VCR deployments. Leave empty to use the production endpoint.',
                    'vcr',
                ),
                'default' => '',
                'placeholder' => 'https://vcr.am/api/v1',
            ],
            [
                'name' => __('Test mode', 'vcr'),
                'type' => 'checkbox',
                'id' => Configuration::OPT_TEST_MODE,
                'desc' => __('Use test cashiers instead of production. Receipts issued in this mode are not legally valid.', 'vcr'),
                'default' => 'no',
            ],
            $cashierField,
            [
                'name' => __('Default department ID', 'vcr'),
                'type' => 'number',
                'id' => Configuration::OPT_DEFAULT_DEPARTMENT_ID,
                'desc_tip' => __(
                    'Internal id of the department to fiscalize against by default. Find it in the VCR dashboard under your cashier configuration. The SDK does not yet expose a department-listing endpoint, so this value is entered manually.',
                    'vcr',
                ),
                'custom_attributes' => ['min' => 1, 'step' => 1],
                'default' => '',
            ],
            [
                'type' => 'sectionend',
                'id' => 'vcr_section',
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
        if (is_string($value) && trim($value) !== '') {
            $this->keyStore->put($value);
        }

        return '';
    }

    public function invalidateCaches(): void
    {
        $this->cashierCatalog->refresh();
    }

    /**
     * Build the WC settings field for the cashier dropdown. Three states:
     *
     *   1. Credentials missing → show a disabled placeholder pointing the
     *      admin at the API key field above.
     *   2. Credentials present, API call returned no cashiers → show a
     *      disabled placeholder hinting at the cause.
     *   3. Cashiers available → render the dropdown.
     *
     * @param  array<int, string> $cashiers
     * @return array<string, mixed>
     */
    private function buildCashierField(array $cashiers): array
    {
        if ($cashiers === []) {
            $reason = $this->keyStore->isSet()
                ? __('No cashiers found — check your API key permissions or create one in the VCR dashboard.', 'vcr')
                : __('Save your API key first; the cashier list loads from the VCR API.', 'vcr');

            return [
                'name' => __('Default cashier', 'vcr'),
                'type' => 'select',
                'id' => Configuration::OPT_DEFAULT_CASHIER_ID,
                'options' => ['' => $reason],
                'desc' => __('Loaded from listCashiers() and cached for one hour. Re-saving these settings forces a refresh.', 'vcr'),
                'custom_attributes' => ['disabled' => 'disabled'],
                'default' => '',
            ];
        }

        return [
            'name' => __('Default cashier', 'vcr'),
            'type' => 'select',
            'id' => Configuration::OPT_DEFAULT_CASHIER_ID,
            'options' => ['' => __('— select a cashier —', 'vcr')] + $cashiers,
            'desc' => __('Loaded from listCashiers() and cached for one hour. Re-saving these settings forces a refresh.', 'vcr'),
            'desc_tip' => __('Required before fiscal jobs will run.', 'vcr'),
            'default' => '',
        ];
    }
}
