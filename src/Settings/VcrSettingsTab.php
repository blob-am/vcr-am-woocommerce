<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Settings;

use BlobSolutions\WooCommerceVcrAm\Catalog\CashierCatalog;
use BlobSolutions\WooCommerceVcrAm\Catalog\DepartmentCatalog;
use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionProbe;
use BlobSolutions\WooCommerceVcrAm\Net\SafeUrlValidator;
use WC_Settings_Page;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * The "VCR" tab inside WooCommerce → Settings.
 *
 * This class is the WooCommerce adapter and nothing else: it owns the tab
 * id, the two sections, the save-path filters and the cache invalidation.
 * The fields themselves live in {@see GeneralFields} and
 * {@see AdvancedFields}, and the checklist above them in
 * {@see \BlobSolutions\WooCommerceVcrAm\Admin\ReadinessPanel}.
 *
 * Loaded only when WooCommerce is active (gated by
 * `Plugin::onPluginsLoaded`), so it's safe to extend `WC_Settings_Page`
 * directly without a class-exists guard at definition time.
 */
final class VcrSettingsTab extends WC_Settings_Page
{
    public const SECTION_ADVANCED = 'advanced';

    public function __construct(
        private readonly KeyStore $keyStore,
        private readonly CashierCatalog $cashierCatalog,
        private readonly DepartmentCatalog $departmentCatalog,
        private readonly ConnectionProbe $probe,
        private readonly GeneralFields $general,
        private readonly AdvancedFields $advanced,
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
        // after persisting fields — for every section, since it is fired by
        // `WC_Admin_Settings::save()` on the tab, not by the section save.
        // Drop the cached catalogs and the cached register identity so a
        // credentials change is re-read instead of serving up to an hour of
        // stale truth on the screen that just changed it.
        add_action('woocommerce_update_options_' . $this->id, [$this, 'invalidateCaches']);
    }

    /**
     * Two sections. The advanced one exists to get two dangerous-but-rarely
     * needed fields out of the first-run path; see {@see AdvancedFields}.
     *
     * @return array<string, string>
     */
    protected function get_own_sections(): array
    {
        return [
            '' => __('General', 'vcr-am-fiscal-receipts'),
            self::SECTION_ADVANCED => __('Advanced', 'vcr-am-fiscal-receipts'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function get_settings_for_default_section(): array
    {
        return $this->general->fields();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function get_settings_for_advanced_section(): array
    {
        return $this->advanced->fields();
    }

    /**
     * WooCommerce calls this from both `output()` and the save path, with
     * the `$current_section` global — which is `null` rather than `''` when
     * a save is driven from code (WP-CLI, our own E2E fixtures) instead of
     * from the settings form. The parent's dispatch turns a null into a
     * lookup for `get_settings_for__section`, finds nothing, and saves
     * nothing at all, so normalize it here.
     *
     * Everything then goes through the parent's `get_settings_for_section`,
     * which is what applies `woocommerce_get_settings_vcr` — the filter
     * other plugins use to add a field to this tab. Returning fields
     * directly from here, as earlier versions did, silently skipped it.
     *
     * @param  string $current_section
     * @return array<int, mixed>
     */
    public function get_settings($current_section = ''): array
    {
        $section = is_string($current_section) ? $current_section : '';

        // array_values, because the filter applied inside that call is
        // public: a neighbouring plugin may hand back a keyed array, and
        // WooCommerce's renderer only ever iterates. The element type stays
        // `mixed` for the same reason — whatever another plugin appended is
        // its own business, and WC's renderer skips what it cannot read.
        return array_values($this->get_settings_for_section($section));
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
     * Null is different from the empty string here, and the difference is
     * load-bearing: WC skips an option whose filtered value is null
     * (`if ( is_null( $value ) ) { continue; }`), which is how it leaves
     * a field that never reached the server alone. Returning '' for that
     * case would silently move a staging or self-hosted store back to the
     * production endpoint — and since 0.1.7 this field lives in another
     * section, so it is absent from every save of the general one.
     *
     * @param  mixed                $value
     * @param  array<string, mixed> $option
     * @param  mixed                $rawValue
     */
    public function sanitizeBaseUrlSave($value, array $option, $rawValue): ?string
    {
        if ($value === null) {
            return null;
        }

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
     * `$rawValue` is typed `mixed` deliberately. WooCommerce computes it as
     * `isset($data[$id]) ? wp_unslash($data[$id]) : null` and passes it to
     * this filter either way, so a field absent from the POST body — a
     * disabled input, a truncated form, a save driven from code — hands us
     * null. Under `strict_types` a `string` parameter would turn that into
     * an uncaught TypeError, which WooCommerce does not catch anywhere in
     * its save path: the merchant gets a WordPress critical error instead
     * of saved settings.
     *
     * @param  mixed              $value
     * @param  array<string,mixed> $option
     * @param  mixed              $rawValue
     */
    public function interceptApiKeySave(mixed $value, array $option, mixed $rawValue): string
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
        $this->probe->refresh();
    }
}
