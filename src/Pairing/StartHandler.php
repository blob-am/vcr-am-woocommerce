<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Pairing;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Logging\Logger;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\RegisterPairingRequestInput;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Pairing\CodeVerifier;
use Throwable;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Step one of the connect button: register the store's intent and send the
 * merchant to vcr.am to approve it.
 *
 * Nothing is granted here. The call returns a URL naming a request row that
 * stays inert unless a signed-in merchant approves it against a register they
 * already manage.
 *
 * Not declared `final` so unit tests can mock it.
 */
class StartHandler
{
    public const ACTION = 'vcr_pair_start';

    public const NONCE_ACTION = 'vcr-pair-start';

    /**
     * Bytes of entropy behind `state`. It is not a secret — it travels through
     * the merchant's browser — but it has to be unguessable, because matching
     * it on the way back is what tells our own pairing from a forged redirect.
     */
    private const STATE_BYTES = 16;

    public function __construct(
        private readonly Configuration $config,
        private readonly PairingSession $session,
        private readonly PairingClientFactory $clientFactory,
        private readonly Logger $logger = new Logger(),
    ) {
    }

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
    }

    public function handle(): void
    {
        check_admin_referer(self::NONCE_ACTION);

        if (! current_user_can('manage_woocommerce')) {
            wp_die(
                esc_html__('You do not have permission to connect this store to VCR.AM.', 'vcr-am-fiscal-receipts'),
                '',
                ['response' => 403, 'back_link' => true],
            );
        }

        $codeVerifier = CodeVerifier::generate();
        $state = bin2hex(random_bytes(self::STATE_BYTES));

        // Stored before the call, not after: if the request succeeds and the
        // store then fails to remember the verifier, the merchant approves a
        // pairing that can never be exchanged.
        $this->session->start(get_current_user_id(), $state, $codeVerifier);

        try {
            $registered = $this->clientFactory->create($this->config->baseUrl())->registerRequest(
                new RegisterPairingRequestInput(
                    redirectUri: SettingsUrl::plain(),
                    storeName: $this->storeName(),
                    state: $state,
                    codeChallenge: CodeVerifier::challengeFor($codeVerifier),
                ),
            );
        } catch (Throwable $e) {
            $this->session->forget(get_current_user_id());
            $this->logger->error('Could not start pairing with VCR.AM', ['error' => $e->getMessage()]);

            $this->backToSettings(SettingsUrl::NOTICE_FAILED);
        }

        $this->sendToApprovalScreen($registered->connectUrl);
    }

    /**
     * What the merchant sees named on the consent screen. `blogname` is the
     * shop's own title, which is the one string they will recognise; it is
     * stored raw, so the entities WordPress keeps in it are decoded here
     * rather than shown as `&amp;`.
     */
    private function storeName(): string
    {
        $name = trim(wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));

        if ($name === '') {
            $host = wp_parse_url(home_url(), PHP_URL_HOST);

            return is_string($host) && $host !== '' ? $host : 'WooCommerce store';
        }

        return $name;
    }

    /**
     * `wp_redirect`, not `wp_safe_redirect`: the destination is vcr.am, and
     * the safe variant allows only this host.
     *
     * The URL is not taken on trust for that reason. It has to sit on the
     * host the plugin is already configured to talk to, so neither a
     * misconfigured base URL nor a compromised API can use the connect button
     * to bounce a logged-in administrator somewhere else.
     */
    private function sendToApprovalScreen(string $connectUrl): never
    {
        if (! $this->isConfiguredHost($connectUrl)) {
            $this->logger->error(
                'Refused a pairing redirect to an unexpected host',
                ['url' => $connectUrl],
            );

            $this->backToSettings(SettingsUrl::NOTICE_FAILED);
        }

        wp_redirect($connectUrl);
        exit;
    }

    private function backToSettings(string $notice): never
    {
        wp_safe_redirect(SettingsUrl::withNotice($notice));
        exit;
    }

    private function isConfiguredHost(string $url): bool
    {
        $expected = wp_parse_url($this->config->baseUrl(), PHP_URL_HOST);
        $actual = wp_parse_url($url, PHP_URL_HOST);

        return is_string($expected) && is_string($actual) && strcasecmp($expected, $actual) === 0;
    }
}
