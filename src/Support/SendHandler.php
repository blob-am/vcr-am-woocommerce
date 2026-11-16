<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Support;

use WC_Order;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Receives the "Send to VCR.AM support" form of the report screen.
 */
final class SendHandler
{
    public const ACTION = 'vcr_send_report';

    public const NONCE_ACTION = 'vcr-send-report';

    public function __construct(
        private readonly ReportSender $sender,
    ) {
    }

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
    }

    public function handle(): void
    {
        $orderId = $this->postedOrderId();

        check_admin_referer(self::NONCE_ACTION . '_' . ($orderId ?? 0));

        if (! current_user_can(ReportPage::CAPABILITY)) {
            wp_die(
                esc_html(__('You do not have permission to send this report.', 'vcr-am-fiscal-receipts')),
                '',
                ['response' => 403, 'back_link' => true],
            );
        }

        $order = $orderId === null ? null : wc_get_order($orderId);

        if ($orderId !== null && ! $order instanceof WC_Order) {
            // The form named an order that is gone. Sending the store-wide
            // report in its place would send something the person never read.
            $this->back(null, [SendForm::PROBLEM_PARAM => SendProblem::ReportUnavailable->value]);
        }

        $outcome = $this->sender->send(
            $order instanceof WC_Order ? $order : null,
            $this->postedText('vcr_report_message', true),
            $this->postedText('vcr_report_contact', false),
        );

        if ($outcome->reference !== null) {
            $this->back($orderId, [SendForm::SENT_PARAM => $outcome->reference]);
        }

        $this->back($orderId, [
            SendForm::PROBLEM_PARAM => ($outcome->problem ?? SendProblem::Rejected)->value,
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    private function back(?int $orderId, array $args): never
    {
        wp_safe_redirect(add_query_arg($args, ReportPage::url($orderId)));
        exit;
    }

    private function postedOrderId(): ?int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is bound to this id and is verified by the caller right after reading it.
        $raw = isset($_POST['order_id']) ? wp_unslash($_POST['order_id']) : null;

        return is_string($raw) && $raw !== '' && ctype_digit($raw) ? (int) $raw : null;
    }

    private function postedText(string $field, bool $multiline): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle() verifies the nonce before calling this.
        $raw = isset($_POST[$field]) ? wp_unslash($_POST[$field]) : '';

        if (! is_string($raw)) {
            return '';
        }

        return trim($multiline ? sanitize_textarea_field($raw) : sanitize_text_field($raw));
    }
}
