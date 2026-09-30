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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw = $_GET[SettingsUrl::NOTICE_QUERY_PARAM] ?? null;

        if (! is_string($raw)) {
            return null;
        }

        $marker = sanitize_text_field(wp_unslash($raw));

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
