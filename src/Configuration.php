<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm;

use BlobSolutions\WooCommerceVcrAm\Settings\KeyStore;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\VcrClient;

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
final class Configuration
{
    public const OPT_BASE_URL = 'vcr_base_url';

    public const OPT_TEST_MODE = 'vcr_test_mode';

    public const OPT_DEFAULT_CASHIER_ID = 'vcr_default_cashier_id';

    public const OPT_DEFAULT_DEPARTMENT_ID = 'vcr_default_department_id';

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

    public function defaultDepartmentId(): ?int
    {
        $stored = get_option(self::OPT_DEFAULT_DEPARTMENT_ID, '');
        if (! is_string($stored) && ! is_numeric($stored)) {
            return null;
        }

        $value = (int) $stored;

        return $value > 0 ? $value : null;
    }

    public function hasCredentials(): bool
    {
        return $this->apiKey() !== null;
    }

    public function isFullyConfigured(): bool
    {
        return $this->hasCredentials()
            && $this->defaultCashierId() !== null
            && $this->defaultDepartmentId() !== null;
    }
}
