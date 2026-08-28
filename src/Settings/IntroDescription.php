<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Settings;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * The blurb rendered at the top of the settings tab.
 *
 * It is legal copy, not UI plumbing, which is why it lives apart from
 * {@see VcrSettingsTab}'s field definitions: it doubles as the
 * merchant-facing GDPR / data-flow disclosure. The merchant needs to
 * know that activating the plugin sets up an EU → Armenia transfer
 * (when they are GDPR-subject) *before* they paste an API key, so it
 * sits in their primary configuration surface where they can't miss it.
 *
 * The text is allow-listed `wp_kses_post` HTML — `<a>`, `<strong>`,
 * `<p>`, `<em>` survive; everything else is stripped by WC's settings
 * renderer. The DPA / SCC links are informational; we don't ship
 * hard-coded merchant-side legal documents with the plugin.
 */
final class IntroDescription
{
    public function render(): string
    {
        $body = __(
            'Connect your store to the VCR.AM gateway. Fiscal receipts (e-HDM) are issued directly to the Armenian State Revenue Committee (SRC) on every paid order.',
            'vcr-am-fiscal-receipts',
        );

        $disclosure = __(
            'GDPR / data-flow notice: activating this plugin transmits order line items, totals, and payment-method classification (cash / non-cash) to the VCR.AM gateway, which forwards them to the Armenian SRC. Customer name, email, address, and phone number are NOT transmitted. VCR.AM is established in the Republic of Armenia, which is not on the European Commission\'s adequacy list — when this site is GDPR-subject, the transfer is governed by Standard Contractual Clauses (Commission Implementing Decision (EU) 2021/914).',
            'vcr-am-fiscal-receipts',
        );

        $links = sprintf(
            /* translators: 1: VCR.AM Privacy Policy URL, 2: VCR.AM Data Processing Addendum URL, 3: Standard Contractual Clauses (Commission Decision) URL */
            __('Reference links: %1$s · %2$s · %3$s.', 'vcr-am-fiscal-receipts'),
            sprintf('<a href="https://vcr.am/privacy" target="_blank" rel="noopener noreferrer">%s</a>', esc_html__('VCR.AM Privacy Policy', 'vcr-am-fiscal-receipts')),
            sprintf('<a href="https://vcr.am/dpa" target="_blank" rel="noopener noreferrer">%s</a>', esc_html__('Data Processing Addendum (request from VCR.AM)', 'vcr-am-fiscal-receipts')),
            sprintf('<a href="https://eur-lex.europa.eu/eli/dec_impl/2021/914/oj" target="_blank" rel="noopener noreferrer">%s</a>', esc_html__('Standard Contractual Clauses (EU 2021/914)', 'vcr-am-fiscal-receipts')),
        );

        return wp_kses_post(
            '<p>' . $body . '</p>'
            . '<p><em>' . $disclosure . '</em></p>'
            . '<p>' . $links . '</p>',
        );
    }
}
