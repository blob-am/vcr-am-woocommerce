<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Support;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\SupportReportInput;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The part of the report screen that sends: the form, and what came of the
 * last attempt.
 *
 * The form says what will be sent and to whom, and sending takes a press of
 * its button. That press is the consent; there is no other path by which a
 * report leaves the site.
 */
final class SendForm
{
    public const SENT_PARAM = 'vcr_report_sent';

    public const PROBLEM_PARAM = 'vcr_report_problem';

    private const REFERENCE_PATTERN = '/\A[A-Z0-9-]{1,16}\z/';

    public function __construct(
        private readonly Configuration $configuration,
    ) {
    }

    public function renderNotice(): void
    {
        $reference = $this->queryValue(self::SENT_PARAM);

        if ($reference !== null && preg_match(self::REFERENCE_PATTERN, $reference) === 1) {
            printf(
                '<div class="notice notice-success inline"><p>%s</p></div>',
                esc_html(sprintf(
                    /* translators: %s: the report's reference, e.g. 7K4M-2QXD. */
                    __('The report was sent to VCR.AM support. Its reference is %s: quote it if you write to us about this.', 'vcr-am-fiscal-receipts'),
                    $reference,
                )),
            );

            return;
        }

        $problem = SendProblem::tryFrom($this->queryValue(self::PROBLEM_PARAM) ?? '');

        if ($problem === null) {
            return;
        }

        printf(
            '<div class="notice notice-error inline"><p>%s %s</p></div>',
            esc_html($this->problemText($problem)),
            esc_html(__('Nothing was sent. You can still copy the report below and email it to support@vcr.am.', 'vcr-am-fiscal-receipts')),
        );
    }

    public function render(?int $orderId): void
    {
        echo '<h2>' . esc_html(__('Send it to VCR.AM support', 'vcr-am-fiscal-receipts')) . '</h2>';

        if (! $this->configuration->hasCredentials()) {
            echo '<p>' . esc_html(__(
                'This store is not connected to a register yet, so the report cannot be sent from here. Copy it or download it, and email it to support@vcr.am with a description of what went wrong.',
                'vcr-am-fiscal-receipts',
            )) . '</p>';

            return;
        }

        echo '<p>' . esc_html(__(
            'Pressing the button sends the report below, together with what you write here, to VCR.AM (vcr.am), under this store\'s register. Nothing is sent until you press it.',
            'vcr-am-fiscal-receipts',
        )) . '</p>';

        printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
        printf('<input type="hidden" name="action" value="%s" />', esc_attr(SendHandler::ACTION));

        if ($orderId !== null) {
            printf('<input type="hidden" name="order_id" value="%s" />', esc_attr((string) $orderId));
        }

        wp_nonce_field(SendHandler::NONCE_ACTION . '_' . ($orderId ?? 0));

        printf(
            '<p><label for="vcr-report-message"><strong>%s</strong></label><br />'
            . '<textarea id="vcr-report-message" name="vcr_report_message" rows="4" class="large-text" required maxlength="%s"></textarea></p>',
            esc_html(__('What happened, and what did you expect?', 'vcr-am-fiscal-receipts')),
            esc_attr((string) SupportReportInput::MAX_MESSAGE_LENGTH),
        );

        printf(
            '<p><label for="vcr-report-contact"><strong>%s</strong></label><br />'
            . '<input type="text" id="vcr-report-contact" name="vcr_report_contact" class="regular-text" maxlength="%s" value="%s" /></p>',
            esc_html(__('Where should we answer? (email, phone or Telegram)', 'vcr-am-fiscal-receipts')),
            esc_attr((string) SupportReportInput::MAX_CONTACT_LENGTH),
            esc_attr(wp_get_current_user()->user_email),
        );

        printf(
            '<p><button type="submit" class="button button-primary" id="vcr-report-send">%s</button></p></form>',
            esc_html(__('Send to VCR.AM support', 'vcr-am-fiscal-receipts')),
        );
    }

    private function problemText(SendProblem $problem): string
    {
        return match ($problem) {
            SendProblem::MessageMissing => __('Describe what happened before sending.', 'vcr-am-fiscal-receipts'),
            SendProblem::NoApiKey => __('This store is not connected to a register.', 'vcr-am-fiscal-receipts'),
            SendProblem::ReportUnavailable => __('The report could not be built.', 'vcr-am-fiscal-receipts'),
            SendProblem::TooManyReports => __('This register has sent too many reports in the last hour.', 'vcr-am-fiscal-receipts'),
            SendProblem::TooLarge => __('The report is too large for VCR.AM to accept.', 'vcr-am-fiscal-receipts'),
            SendProblem::Rejected => __('VCR.AM did not accept the report. The reason is in the journal below.', 'vcr-am-fiscal-receipts'),
            SendProblem::Unreachable => __('VCR.AM could not be reached from this server.', 'vcr-am-fiscal-receipts'),
        };
    }

    private function queryValue(string $key): ?string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: picks which result line to show after a redirect.
        if (! isset($_GET[$key]) || ! is_string($_GET[$key])) {
            return null;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
        $value = sanitize_text_field(wp_unslash($_GET[$key]));

        return $value === '' ? null : $value;
    }
}
