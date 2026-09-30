<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Settings;

use BlobSolutions\WooCommerceVcrAm\Pairing\StartHandler;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The "Connect to VCR.AM" button, and the paragraph telling a merchant what
 * it will do before they press it.
 *
 * It sits above the API key field because for most stores it replaces that
 * field entirely: pressing it hands the store a key without the merchant
 * finding, creating and pasting one. The field stays for merchants who
 * already have a key, or who cannot use the browser flow.
 *
 * Rendered as part of the section's description rather than as a settings
 * field of its own: the plugin registers no `woocommerce_admin_field_*`
 * handlers, and the section description is the existing surface for HTML on
 * this screen — the same one the readiness checklist uses.
 */
final class ConnectButton
{
    public function __construct(private readonly KeyStore $keyStore)
    {
    }

    public function render(): string
    {
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=' . StartHandler::ACTION),
            StartHandler::NONCE_ACTION,
        );

        $label = $this->keyStore->isSet()
            ? __('Reconnect to VCR.AM', 'vcr-am-fiscal-receipts')
            : __('Connect to VCR.AM', 'vcr-am-fiscal-receipts');

        $explanation = $this->keyStore->isSet()
            ? __(
                'Connecting again replaces the stored key. Use this if the current key stopped working, or to move this store to a different cash register.',
                'vcr-am-fiscal-receipts',
            )
            : __(
                'Opens VCR.AM, where you sign in and choose which cash register this store should file receipts to. The key is created for you — there is nothing to copy. You can also paste a key below instead.',
                'vcr-am-fiscal-receipts',
            );

        return sprintf(
            '<p class="vcr-connect"><a href="%1$s" class="button button-primary">%2$s</a></p><p class="description">%3$s</p>',
            esc_url($url),
            esc_html($label),
            esc_html($explanation),
        );
    }
}
