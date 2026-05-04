<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Settings;

use WC_Settings_Page;

/**
 * The "VCR" tab inside WooCommerce → Settings.
 *
 * Renders three fields:
 *
 *   - **API Key** — sensitive; intercepted on save and routed to KeyStore
 *     for at-rest encryption. The stored `wp_options` row stays empty so
 *     the value never leaks back into the form on subsequent renders.
 *   - **Base URL** — optional override for staging / self-hosted VCR.
 *     Empty (the default) means the SDK uses its built-in production URL.
 *   - **Test mode** — toggles between test and production cashiers.
 *
 * The class is loaded only when WooCommerce is active (gated by
 * `Plugin::onPluginsLoaded`), so it's safe to extend `WC_Settings_Page`
 * directly without a class-exists guard at definition time.
 */
final class VcrSettingsTab extends WC_Settings_Page
{
    public function __construct(
        private readonly KeyStore $keyStore,
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
                'id' => 'vcr_base_url',
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
                'id' => 'vcr_test_mode',
                'desc' => __('Use test cashiers instead of production. Receipts issued in this mode are not legally valid.', 'vcr'),
                'default' => 'no',
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
        unset($option, $rawValue);

        if (is_string($value) && trim($value) !== '') {
            $this->keyStore->put($value);
        }

        return '';
    }
}
