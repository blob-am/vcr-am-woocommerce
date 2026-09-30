<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Pairing;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Tells the merchant how the connect attempt ended.
 *
 * The outcome arrives as a query-string marker rather than as state, because
 * {@see ReturnHandler} redirects to strip the code out of the address bar and
 * nothing survives that but the URL. Same mechanism the order screen uses for
 * its own retry notices.
 */
final class PairingNotices
{
    public function __construct(
        private readonly FailureDetail $failureDetail = new FailureDetail(),
    ) {
    }

    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    public function render(): void
    {
        $notice = $this->notice();

        if ($notice === null) {
            return;
        }

        [$class, $message] = $notice;

        // Only the failure branch has anything more to say, and only when
        // VCR.AM said it. Read on any pairing outcome, not just the failing
        // one, so a detail cannot outlive the attempt it belongs to and turn up
        // beside a later one.
        $detail = $this->failureDetail->take(get_current_user_id());

        if ($detail !== null && $class === 'notice-error') {
            $message .= ' ' . $detail;
        }

        printf(
            '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($class),
            esc_html($message),
        );
    }

    /**
     * @return ?array{0: string, 1: string}
     */
    private function notice(): ?array
    {
        // Scoped to the settings screen because that is the only place our own
        // redirect puts the marker. Without it, any admin URL somebody hands an
        // administrator can print "Connected" on a store that never paired.
        if (! SettingsUrl::isCurrentScreen()) {
            return null;
        }

        // No nonce to verify: this marker is put here by our own redirect and
        // decides nothing — it selects which sentence to print. Every value
        // that is not one of the four below prints nothing at all.
        if (! isset($_GET[SettingsUrl::NOTICE_QUERY_PARAM])
            || ! is_string($_GET[SettingsUrl::NOTICE_QUERY_PARAM])
        ) {
            return null;
        }

        $marker = sanitize_text_field(wp_unslash($_GET[SettingsUrl::NOTICE_QUERY_PARAM]));

        return match ($marker) {
            SettingsUrl::NOTICE_CONNECTED => [
                'notice-success',
                __('Connected. This store will now file its receipts to the register you chose.', 'vcr-am-fiscal-receipts'),
            ],
            SettingsUrl::NOTICE_DENIED => [
                'notice-warning',
                __('The connection was not approved, so nothing changed.', 'vcr-am-fiscal-receipts'),
            ],
            SettingsUrl::NOTICE_EXPIRED => [
                'notice-warning',
                __('That connection attempt has expired. Press Connect to VCR.AM again.', 'vcr-am-fiscal-receipts'),
            ],
            SettingsUrl::NOTICE_FAILED => [
                'notice-error',
                __('Could not connect to VCR.AM. Check the error log, then try again.', 'vcr-am-fiscal-receipts'),
            ],
            default => null,
        };
    }
}
