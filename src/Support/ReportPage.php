<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Support;

use WC_Order;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The "Report a problem" screen: shows the merchant the exact report, and lets
 * them send it to VCR.AM support, copy it or save it as a file.
 *
 * Showing it sends nothing. {@see SendForm} is the only way out of the site,
 * and it takes a press of its button.
 */
final class ReportPage
{
    public const SLUG = 'vcr-problem-report';

    public const CAPABILITY = 'manage_woocommerce';

    public const DOWNLOAD_ACTION = 'vcr_download_report';

    public const NONCE_ACTION = 'vcr-download-report';

    public const SCRIPT_HANDLE = 'vcr-report';

    private const PARENT_SLUG = 'woocommerce';

    public function __construct(
        private readonly ReportBuilder $builder,
        private readonly SendForm $sendForm,
        private readonly string $pluginFile,
        private readonly string $version,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addPage'], 99);
        add_action('admin_head', [$this, 'hideFromMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueScript']);
        add_action('admin_post_' . self::DOWNLOAD_ACTION, [$this, 'download']);
    }

    /**
     * Where the screen lives, for links from elsewhere in the admin.
     */
    public static function url(?int $orderId = null): string
    {
        $args = ['page' => self::SLUG];

        if ($orderId !== null) {
            $args['order_id'] = $orderId;
        }

        return add_query_arg($args, admin_url('admin.php'));
    }

    /**
     * Registered under WooCommerce so the capability check and the admin
     * chrome come with it.
     */
    public function addPage(): void
    {
        add_submenu_page(
            self::PARENT_SLUG,
            __('VCR problem report', 'vcr-am-fiscal-receipts'),
            __('VCR problem report', 'vcr-am-fiscal-receipts'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'render'],
        );
    }

    /**
     * The screen is reached from an order or from the plugin's links, not
     * browsed to, so it has no menu entry.
     *
     * Removed on `admin_head` and not right after registering it: WordPress
     * decides whether the current user may open a screen by looking it up in
     * the menu, and a page removed during `admin_menu` answers "not allowed"
     * to everyone, administrators included.
     */
    public function hideFromMenu(): void
    {
        remove_submenu_page(self::PARENT_SLUG, self::SLUG);
    }

    public function enqueueScript(mixed $hook): void
    {
        if (! is_string($hook) || ! str_ends_with($hook, '_page_' . self::SLUG)) {
            return;
        }

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            plugins_url('assets/js/report.js', $this->pluginFile),
            [],
            $this->version,
            true,
        );

        wp_localize_script(self::SCRIPT_HANDLE, 'vcrReport', [
            'copied' => __('Copied.', 'vcr-am-fiscal-receipts'),
            'copyFailed' => __('Could not copy. Select the text and copy it by hand.', 'vcr-am-fiscal-receipts'),
        ]);
    }

    public function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(
                esc_html(__('You do not have permission to view this report.', 'vcr-am-fiscal-receipts')),
                '',
                ['response' => 403],
            );
        }

        $orderId = $this->requestedOrderId();
        $order = $orderId === null ? null : wc_get_order($orderId);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html(__('VCR problem report', 'vcr-am-fiscal-receipts')) . '</h1>';

        $this->sendForm->renderNotice();

        if ($orderId !== null && ! $order instanceof WC_Order) {
            echo '<div class="notice notice-error inline"><p>'
                . esc_html(__('This order does not exist. The report below is about the store as a whole.', 'vcr-am-fiscal-receipts'))
                . '</p></div>';
            $orderId = null;
        }

        try {
            $json = $this->builder->json($order instanceof WC_Order ? $order : null);
        } catch (ReportUnavailable $e) {
            echo '<div class="notice notice-error inline"><p>'
                . esc_html(sprintf(
                    /* translators: %s: the reason, in English. */
                    __('The report could not be built. %s', 'vcr-am-fiscal-receipts'),
                    $e->getMessage(),
                ))
                . '</p></div></div>';

            return;
        }

        echo '<p>' . esc_html(
            $orderId === null
                ? __('This is everything the plugin knows about the state of this store: versions, settings, the register it is connected to, and its own journal of recent events.', 'vcr-am-fiscal-receipts')
                : __('This is everything the plugin knows about this order: its state, the receipt\'s state, and the plugin\'s own journal of what happened to it.', 'vcr-am-fiscal-receipts'),
        ) . '</p>';
        echo '<p>' . esc_html(__(
            'It holds nothing about your customer: no name, address, phone number or email. Nothing leaves this site unless you send it yourself: read the report first.',
            'vcr-am-fiscal-receipts',
        )) . '</p>';

        $this->sendForm->render($orderId);

        echo '<h2>' . esc_html(__('The report', 'vcr-am-fiscal-receipts')) . '</h2>';

        printf(
            '<p><button type="button" class="button" id="vcr-report-copy">%s</button> '
            . '<a class="button" href="%s">%s</a> '
            . '<span id="vcr-report-status" role="status" aria-live="polite"></span></p>',
            esc_html(__('Copy', 'vcr-am-fiscal-receipts')),
            esc_url($this->downloadUrl($orderId)),
            esc_html(__('Download as a file', 'vcr-am-fiscal-receipts')),
        );

        printf(
            '<textarea id="vcr-report-text" readonly rows="30" class="large-text code" aria-label="%s">%s</textarea>',
            esc_attr(__('The report', 'vcr-am-fiscal-receipts')),
            esc_textarea($json),
        );
        echo '</div>';
    }

    public function download(): void
    {
        $orderId = $this->requestedOrderId();

        check_admin_referer(self::NONCE_ACTION . '_' . ($orderId ?? 0));

        if (! current_user_can(self::CAPABILITY)) {
            wp_die(
                esc_html(__('You do not have permission to view this report.', 'vcr-am-fiscal-receipts')),
                '',
                ['response' => 403, 'back_link' => true],
            );
        }

        $order = $orderId === null ? null : wc_get_order($orderId);

        try {
            $json = $this->builder->json($order instanceof WC_Order ? $order : null);
        } catch (ReportUnavailable $e) {
            wp_die(esc_html($e->getMessage()), '', ['response' => 500, 'back_link' => true]);
        }

        $name = $order instanceof WC_Order
            ? sprintf('vcr-report-order-%d-%s.json', $order->get_id(), gmdate('Ymd-His'))
            : sprintf('vcr-report-%s.json', gmdate('Ymd-His'));

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('X-Content-Type-Options: nosniff');

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JSON file download, not HTML; escaping would corrupt it.
        echo $json;
        exit;
    }

    private function downloadUrl(?int $orderId): string
    {
        $args = ['action' => self::DOWNLOAD_ACTION];

        if ($orderId !== null) {
            $args['order_id'] = $orderId;
        }

        return wp_nonce_url(
            add_query_arg($args, admin_url('admin-post.php')),
            self::NONCE_ACTION . '_' . ($orderId ?? 0),
        );
    }

    private function requestedOrderId(): ?int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: picks which order a report is shown for; the download path verifies its nonce against this same id.
        $raw = isset($_GET['order_id']) ? wp_unslash($_GET['order_id']) : null;

        return is_string($raw) && $raw !== '' && ctype_digit($raw) ? (int) $raw : null;
    }
}
