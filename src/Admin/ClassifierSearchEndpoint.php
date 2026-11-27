<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Admin;

use BlobSolutions\WooCommerceVcrAm\Catalog\Classifier\Directory;
use BlobSolutions\WooCommerceVcrAm\Catalog\Classifier\Entry;
use BlobSolutions\WooCommerceVcrAm\Request\QueryParameter;
use BlobSolutions\WooCommerceVcrAm\Settings\VcrSettingsTab;
use BlobSolutions\WooCommerceVcrAm\VcrClientFactory;
use Throwable;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Feeds the searchable classifier dropdown on the settings tab.
 *
 * The dropdown is the whole fix for a free-text field nobody could fill in
 * correctly: a merchant types a code or a word, picks from what VCR says the
 * tax service accepts, and can no longer store anything else. The answer is
 * shaped for selectWoo, which WooCommerce already ships on this screen.
 *
 * Authorisation: `manage_woocommerce` capability + nonce, as for the
 * connection test.
 *
 * Not declared `final` so unit tests can mock it.
 */
class ClassifierSearchEndpoint
{
    public const AJAX_ACTION = 'vcr_search_classifier';

    private const NONCE_ACTION = 'vcr-search-classifier';

    private const SCRIPT_HANDLE = 'vcr-classifier-select';

    /** selectWoo's own minimum before it asks; matches the API's search floor. */
    private const MINIMUM_QUERY_LENGTH = 2;

    public function __construct(
        private readonly Directory $directory,
        private readonly string $pluginFile,
        private readonly string $version,
    ) {
    }

    public function register(): void
    {
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handle']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    /**
     * Only on WooCommerce → Settings → VCR. `mixed` and defaulted for the
     * reason given on {@see ConnectionTester::enqueue()}.
     */
    public function enqueue(mixed $hook = null): void
    {
        if ($hook !== 'woocommerce_page_wc-settings') {
            return;
        }

        if (QueryParameter::text('tab') !== VcrSettingsTab::ID) {
            return;
        }

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            plugins_url('assets/js/classifier-select.js', $this->pluginFile),
            ['jquery', 'selectWoo'],
            $this->version,
            true,
        );

        wp_localize_script(self::SCRIPT_HANDLE, 'vcrClassifierSelect', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'action' => self::AJAX_ACTION,
            'minimumInputLength' => self::MINIMUM_QUERY_LENGTH,
            'timeoutMs' => (VcrClientFactory::DEFAULT_TIMEOUT_SECONDS + 5) * 1000,
            'i18n' => [
                'placeholder' => __('Search by code or name', 'vcr-am-fiscal-receipts'),
                'inputTooShort' => __('Type at least 2 characters of a code or a name', 'vcr-am-fiscal-receipts'),
                'searching' => __('Searching…', 'vcr-am-fiscal-receipts'),
                'noResults' => __('No code matches. Try a shorter code or another word.', 'vcr-am-fiscal-receipts'),
                'failed' => __('Could not reach VCR to search. Check the checklist above and try again.', 'vcr-am-fiscal-receipts'),
            ],
        ]);
    }

    public function handle(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(
                ['message' => __('You do not have permission to change VCR settings.', 'vcr-am-fiscal-receipts')],
                403,
            );
        }

        $query = isset($_GET['term']) && is_string($_GET['term'])
            ? trim(sanitize_text_field(wp_unslash($_GET['term'])))
            : '';

        if (mb_strlen($query) < self::MINIMUM_QUERY_LENGTH) {
            wp_send_json_success(['results' => []]);
        }

        try {
            $entries = $this->directory->search($query);
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 502);
        }

        wp_send_json_success([
            'results' => array_map(
                static fn (Entry $entry): array => ['id' => $entry->code, 'text' => $entry->label()],
                $entries,
            ),
        ]);
    }
}
