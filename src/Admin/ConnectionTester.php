<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Admin;

use BlobSolutions\WooCommerceVcrAm\Catalog\CashierListerFactory;
use BlobSolutions\WooCommerceVcrAm\Net\SafeUrlValidator;
use BlobSolutions\WooCommerceVcrAm\Settings\KeyStore;
use BlobSolutions\WooCommerceVcrAm\VcrClientFactory;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrException;
use Throwable;

/**
 * "Test connection" AJAX endpoint surfaced under the API key field on the
 * settings page. Constructs a one-shot {@see VcrClient} from the values
 * the admin currently has in the form (or the stored KeyStore key, if the
 * field was left empty), calls `listCashiers()`, and returns the
 * cashier count plus a friendly success/failure message.
 *
 * AJAX is the right transport here:
 *
 *   - The settings form may not have been saved yet (admin is still
 *     entering credentials), so we have to test against the *typed*
 *     values rather than what's persisted.
 *   - The test is round-trip blocking (we want immediate feedback), but
 *     short-lived (single request) — no need for Action Scheduler.
 *
 * Authorisation: `manage_woocommerce` capability + nonce. Both are
 * required; either alone is insufficient.
 */
/**
 * Not declared `final` so unit tests can mock — there's no production
 * extension point.
 */
class ConnectionTester
{
    private const NONCE_ACTION = 'vcr-test-connection';

    private const SCRIPT_HANDLE = 'vcr-settings';

    private const AJAX_ACTION = 'vcr_test_connection';

    public function __construct(
        private readonly KeyStore $keyStore,
        private readonly CashierListerFactory $listerFactory,
        private readonly string $pluginFile,
        private readonly string $version,
        private readonly SafeUrlValidator $urlValidator = new SafeUrlValidator(),
    ) {
    }

    public function register(): void
    {
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handle']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    /**
     * Enqueue the small vanilla-JS asset that injects the "Test connection"
     * button under the API key field. Loaded only on the WC settings page,
     * VCR tab — never elsewhere in the admin.
     */
    public function enqueue(string $hook): void
    {
        if ($hook !== 'woocommerce_page_wc-settings') {
            return;
        }

        $tab = isset($_GET['tab']) && is_string($_GET['tab'])
            ? sanitize_text_field(wp_unslash($_GET['tab']))
            : '';

        if ($tab !== 'vcr') {
            return;
        }

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            plugins_url('assets/js/settings.js', $this->pluginFile),
            [],
            $this->version,
            true,
        );

        wp_localize_script(self::SCRIPT_HANDLE, 'vcrSettings', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'action' => self::AJAX_ACTION,
            // Browser ceiling — must exceed the server-side timeout so the
            // server's actual error response wins the race against the
            // client's AbortController, otherwise admins always see a
            // generic "timed out" instead of the real error message.
            'timeoutMs' => (VcrClientFactory::DEFAULT_TIMEOUT_SECONDS + 5) * 1000,
            'i18n' => [
                'testButton' => __('Test connection', 'vcr'),
                'testing' => __('Testing…', 'vcr'),
                'apiKeyRequired' => __('Enter an API key first.', 'vcr'),
                'connectionFailed' => __('Connection failed.', 'vcr'),
                'networkError' => __('Network error — could not reach the server.', 'vcr'),
                'timedOut' => __('Timed out — VCR did not respond in time.', 'vcr'),
            ],
        ]);
    }

    public function handle(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(
                ['message' => __('You do not have permission to test the VCR connection.', 'vcr')],
                403,
            );
        }

        $apiKey = isset($_POST['api_key']) && is_string($_POST['api_key'])
            ? trim(sanitize_text_field(wp_unslash($_POST['api_key'])))
            : '';

        // Empty form field → fall back to the stored key. Lets the admin
        // re-test an existing key without re-typing it.
        if ($apiKey === '') {
            $apiKey = $this->keyStore->get() ?? '';
        }

        if ($apiKey === '') {
            wp_send_json_error([
                'message' => __('No API key provided and no saved key found.', 'vcr'),
            ]);
        }

        $baseUrl = isset($_POST['base_url']) && is_string($_POST['base_url'])
            ? trim(esc_url_raw(wp_unslash($_POST['base_url'])))
            : '';

        // SSRF guard. The base URL the admin typed will get the API key
        // attached to every outbound request. Refuse to proceed if the
        // URL points at cloud-metadata services, loopback, or RFC1918
        // ranges — see {@see SafeUrlValidator} for the full ruleset.
        if ($baseUrl !== '') {
            $rejection = $this->urlValidator->reject($baseUrl);
            if ($rejection !== null) {
                wp_send_json_error([
                    'message' => sprintf(
                        /* translators: %s is the SafeUrlValidator rejection reason. */
                        __('Refusing to send your API key to that URL: %s', 'vcr'),
                        $rejection,
                    ),
                ]);
            }
        }

        try {
            // The form's base URL field — if non-empty — overrides the
            // saved one for this single AJAX call. Lets admins probe a
            // not-yet-saved staging endpoint before persisting.
            $cashiers = $this->listerFactory
                ->create($apiKey, $baseUrl !== '' ? $baseUrl : null)
                ->listCashiers();
            $count = count($cashiers);

            wp_send_json_success([
                /* translators: %d is the number of cashiers visible to this API key. */
                'message' => sprintf(
                    _n(
                        'Connected. %d cashier visible to this API key.',
                        'Connected. %d cashiers visible to this API key.',
                        $count,
                        'vcr',
                    ),
                    $count,
                ),
                'count' => $count,
            ]);
        } catch (VcrException $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        } catch (Throwable $e) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s is the technical error message. */
                    __('Unexpected error: %s', 'vcr'),
                    $e->getMessage(),
                ),
            ]);
        }
    }
}
