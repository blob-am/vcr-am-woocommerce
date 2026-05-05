<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Settings;

use BlobSolutions\WooCommerceVcrAm\Catalog\CashierCatalog;
use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Net\SafeUrlValidator;
use WC_Settings_Page;

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
 *   - **Default department ID** — numeric input. The SDK does not yet
 *     expose `listDepartments`, so the admin enters the integer id from
 *     the VCR dashboard manually until that endpoint is published.
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
        private readonly SafeUrlValidator $urlValidator = new SafeUrlValidator(),
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
                    esc_html__('VCR base URL rejected:', 'vcr'),
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
            ? __('Saved — leave empty to keep current key', 'vcr')
            : __('Required', 'vcr');

        $cashiers = $this->cashierCatalog->list();
        $cashierField = $this->buildCashierField($cashiers);

        return [
            [
                'name' => __('VCR — Fiscal Receipts for Armenia', 'vcr'),
                'type' => 'title',
                'desc' => $this->buildIntroDescription(),
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
            [
                'name' => __('Order line synthesis', 'vcr'),
                'type' => 'title',
                'desc' => __(
                    'WooCommerce ships shipping and fees as separate order items. The fiscal receipt needs every line to reference a catalog offer with its own classifier code, so the plugin synthesises a SaleItem against an SKU you onboard once in the VCR dashboard. Without these SKUs configured, any order with shipping or fees is blocked from fiscalisation.',
                    'vcr',
                ),
                'id' => 'vcr_synthesis_section',
            ],
            [
                'name' => __('Shipping SKU', 'vcr'),
                'type' => 'text',
                'id' => Configuration::OPT_SHIPPING_SKU,
                'desc_tip' => __(
                    'External id (SKU) of a pre-onboarded "Shipping" offer in your VCR catalog. The plugin references this offer for every shipping line item; you control its classifier code, unit, and tax treatment in VCR proper.',
                    'vcr',
                ),
                'default' => '',
                'placeholder' => 'shipping',
            ],
            [
                'name' => __('Fee SKU', 'vcr'),
                'type' => 'text',
                'id' => Configuration::OPT_FEE_SKU,
                'desc_tip' => __(
                    'External id (SKU) of a pre-onboarded "Fee" offer in your VCR catalog. Used for every WooCommerce fee line (handling charges, surcharges, etc.).',
                    'vcr',
                ),
                'default' => '',
                'placeholder' => 'service-fee',
            ],
            [
                'type' => 'sectionend',
                'id' => 'vcr_synthesis_section',
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
    }

    /**
     * Description rendered at the top of the settings tab. Doubles as
     * the merchant-facing GDPR / data-flow disclosure: the merchant
     * needs to know that activating the plugin sets up an EU → Armenia
     * data transfer (when the merchant is GDPR-subject) before they
     * paste their API key. Keeps the legal text in the merchant's
     * primary configuration surface so they can't miss it.
     *
     * The text is allow-listed `wp_kses_post` HTML — `<a>`, `<strong>`,
     * `<p>`, `<em>` are kept; everything else is stripped by WC's
     * settings renderer. The DPA / SCC links are intentionally
     * informational; we don't ship hard-coded merchant-side legal docs
     * with the plugin.
     */
    private function buildIntroDescription(): string
    {
        $body = __(
            'Connect your store to the VCR.AM gateway. Fiscal receipts (e-HDM) are issued directly to the Armenian State Revenue Committee (SRC) on every paid order.',
            'vcr',
        );

        $disclosure = __(
            'GDPR / data-flow notice: activating this plugin transmits order line items, totals, and payment-method classification (cash / non-cash) to the VCR.AM gateway, which forwards them to the Armenian SRC. Customer name, email, address, and phone number are NOT transmitted. VCR.AM is established in the Republic of Armenia, which is not on the European Commission\'s adequacy list — when this site is GDPR-subject, the transfer is governed by Standard Contractual Clauses (Commission Implementing Decision (EU) 2021/914).',
            'vcr',
        );

        $links = sprintf(
            /* translators: 1: VCR.AM Privacy Policy URL, 2: VCR.AM Data Processing Addendum URL, 3: Standard Contractual Clauses (Commission Decision) URL */
            __('Reference links: %1$s · %2$s · %3$s.', 'vcr'),
            sprintf('<a href="https://vcr.am/privacy" target="_blank" rel="noopener noreferrer">%s</a>', esc_html__('VCR.AM Privacy Policy', 'vcr')),
            sprintf('<a href="https://vcr.am/dpa" target="_blank" rel="noopener noreferrer">%s</a>', esc_html__('Data Processing Addendum (request from VCR.AM)', 'vcr')),
            sprintf('<a href="https://eur-lex.europa.eu/eli/dec_impl/2021/914/oj" target="_blank" rel="noopener noreferrer">%s</a>', esc_html__('Standard Contractual Clauses (EU 2021/914)', 'vcr')),
        );

        return wp_kses_post(
            '<p>' . $body . '</p>'
            . '<p><em>' . $disclosure . '</em></p>'
            . '<p>' . $links . '</p>',
        );
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
