<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Pairing;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Logging\Logger;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrApiException;
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

    /** Set for the duration of one redirect; see {@see allowApprovalHost()}. */
    private ?string $approvalHost = null;

    public function __construct(
        private readonly Configuration $config,
        private readonly PairingSession $session,
        private readonly PairingClientFactory $clientFactory,
        private readonly FailureDetail $failureDetail = new FailureDetail(),
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

            // A refusal from VCR.AM names the field that was wrong, and the
            // likeliest one by far is a shop on plain http: the API will not
            // accept an http `redirectUri`. Carry that sentence to the notice
            // instead of leaving the merchant with "check the error log".
            if ($e instanceof VcrApiException && $e->apiErrorMessage !== null) {
                $this->failureDetail->remember(get_current_user_id(), $e->apiErrorMessage);
            }

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
     * Sends the merchant to vcr.am's consent screen.
     *
     * The destination is another host, which `wp_safe_redirect` refuses by
     * default — so the host is allowed explicitly, for this one redirect, and
     * only after checking it is the host the plugin is already configured to
     * talk to. That is the point: neither a misconfigured base URL nor a
     * compromised API response can use the connect button to bounce a
     * logged-in administrator somewhere else.
     *
     * Allowing the host through the filter rather than reaching for
     * `wp_redirect` keeps WordPress's own check in the path instead of
     * stepping around it.
     */
    private function sendToApprovalScreen(string $connectUrl): never
    {
        $host = wp_parse_url($connectUrl, PHP_URL_HOST);

        if (! is_string($host) || ! $this->isConfiguredHost($connectUrl)) {
            $this->logger->error(
                'Refused a pairing redirect to an unexpected host',
                ['url' => $connectUrl],
            );

            $this->backToSettings(SettingsUrl::NOTICE_FAILED);
        }

        $this->approvalHost = $host;

        add_filter('allowed_redirect_hosts', [$this, 'allowApprovalHost']);
        wp_safe_redirect($connectUrl);
        remove_filter('allowed_redirect_hosts', [$this, 'allowApprovalHost']);
        exit;
    }

    /**
     * Adds the consent screen's host to the redirect allow-list, for the one
     * redirect that needs it.
     *
     * Public only because WordPress needs a callable; it adds nothing until
     * {@see sendToApprovalScreen()} has set the host, and that only happens
     * after the host has been checked against the configured one.
     *
     * @param list<string> $hosts
     *
     * @return list<string>
     */
    public function allowApprovalHost(array $hosts): array
    {
        if ($this->approvalHost !== null) {
            $hosts[] = $this->approvalHost;
        }

        return $hosts;
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
