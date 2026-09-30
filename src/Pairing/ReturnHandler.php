<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Pairing;

use BlobSolutions\WooCommerceVcrAm\Catalog\CashierCatalog;
use BlobSolutions\WooCommerceVcrAm\Catalog\DepartmentCatalog;
use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionProbe;
use BlobSolutions\WooCommerceVcrAm\Logging\Logger;
use BlobSolutions\WooCommerceVcrAm\Settings\KeyStore;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrApiException;
use Throwable;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Step two of the connect button: the merchant is back from vcr.am, so turn
 * the code they carried into a key and store it.
 *
 * This runs on the settings screen's own URL rather than on `admin-post.php`,
 * because that URL is what the merchant was shown and asked to approve. The
 * handler therefore has to recognise its own return rather than owning the
 * request: anything without a `code` is just somebody opening the settings.
 *
 * Not declared `final` so unit tests can mock it.
 */
class ReturnHandler
{
    public function __construct(
        private readonly Configuration $config,
        private readonly KeyStore $keyStore,
        private readonly PairingSession $session,
        private readonly PairingClientFactory $clientFactory,
        private readonly ConnectionProbe $probe,
        private readonly CashierCatalog $cashierCatalog,
        private readonly DepartmentCatalog $departmentCatalog,
        private readonly FailureDetail $failureDetail = new FailureDetail(),
        private readonly Logger $logger = new Logger(),
    ) {
    }

    public function register(): void
    {
        add_action('admin_init', [$this, 'handle']);
    }

    public function handle(): void
    {
        if (! $this->isOurReturn()) {
            return;
        }

        // No nonce here, and none is possible: this request is issued by
        // vcr.am's redirect, not by a form this site rendered. The `state`
        // check below is the equivalent guarantee — it is a value only this
        // server generated and only this user's session holds.
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $userId = get_current_user_id();
        $pending = $this->session->take($userId);

        $error = $this->queryValue('error');
        if ($error !== null) {
            // The merchant declined, or vcr.am refused. Either way there is
            // nothing to exchange, and the session is already cleared.
            $this->finish(SettingsUrl::NOTICE_DENIED);
        }

        $code = $this->queryValue('code');
        $state = $this->queryValue('state');

        if ($code === null || $state === null) {
            // isOurReturn() already established that one of `code` or `error`
            // is present, and `error` was handled above — so reaching here
            // means a return that is missing half of itself. The session is
            // spent either way; say so rather than leaving a blank screen.
            $this->logger->error('Pairing returned without both a code and a state');

            $this->finish(SettingsUrl::NOTICE_FAILED);
        }

        if ($pending === null) {
            // Nothing pending for this user: the attempt timed out, was
            // already completed, or was started in another browser.
            $this->finish(SettingsUrl::NOTICE_EXPIRED);
        }

        // hash_equals, not ===: this is the check standing in for a nonce.
        if (! hash_equals($pending['state'], $state)) {
            $this->logger->error('Pairing returned with a state that does not match the pending one');

            $this->finish(SettingsUrl::NOTICE_FAILED);
        }

        try {
            $paired = $this->clientFactory
                ->create($this->config->baseUrl())
                ->exchangeCode($code, $pending['codeVerifier']);
        } catch (Throwable $e) {
            $this->logger->error('Could not exchange the pairing code', ['error' => $e->getMessage()]);

            if ($e instanceof VcrApiException && $e->apiErrorMessage !== null) {
                $this->failureDetail->remember($userId, $e->apiErrorMessage);
            }

            $this->finish(SettingsUrl::NOTICE_FAILED);
        }

        try {
            $this->keyStore->put($paired->apiKey);
        } catch (Throwable $e) {
            // The key is minted and will never be shown again, so a failed
            // write is the one outcome worth logging loudly: the merchant has
            // to pair a second time, and the first key is now orphaned.
            $this->logger->error('Paired but could not store the key', ['error' => $e->getMessage()]);

            $this->finish(SettingsUrl::NOTICE_FAILED);
        }

        // The probe caches "no API key" and both catalogs cached their own
        // emptiness while there was none. Without this the merchant lands back
        // on a screen still telling them they are not connected.
        $this->probe->refresh();
        $this->cashierCatalog->refresh();
        $this->departmentCatalog->refresh();

        $this->logger->info('Paired with a VCR.AM register', [
            'vcrId' => $paired->vcrId,
            'registerName' => $paired->registerName,
        ]);

        $this->finish(SettingsUrl::NOTICE_CONNECTED);
    }

    /**
     * Whether this request is a merchant coming back from vcr.am, as opposed
     * to any other load of the settings screen.
     */
    private function isOurReturn(): bool
    {
        if (! SettingsUrl::isCurrentScreen()) {
            return false;
        }

        return $this->queryValue('code') !== null || $this->queryValue('error') !== null;
    }

    /**
     * Reads one query parameter, or null when it is absent or empty.
     *
     * Nonce verification is not possible on this request by construction —
     * see the note in {@see handle()} — so the checks that replace it are the
     * capability test and the `state` comparison.
     */
    private function queryValue(string $key): ?string
    {
        if (! isset($_GET[$key]) || ! is_string($_GET[$key])) {
            return null;
        }

        $value = trim(sanitize_text_field(wp_unslash($_GET[$key])));

        return $value === '' ? null : $value;
    }

    /**
     * Back to the settings screen with `code` and `state` stripped from the
     * address bar, so a refresh cannot replay a spent code and the secrets do
     * not sit in browser history.
     */
    private function finish(string $notice): never
    {
        wp_safe_redirect(SettingsUrl::withNotice($notice));
        exit;
    }
}
