<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Cli;

use BlobSolutions\WooCommerceVcrAm\Support\ReportBuilder;
use BlobSolutions\WooCommerceVcrAm\Support\ReportSender;
use BlobSolutions\WooCommerceVcrAm\Support\ReportUnavailable;
use WC_Order;
use WP_CLI;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * `wp vcr report`: the problem report of the admin screen, on standard output.
 *
 * For a host or an agency with shell access and no wp-admin login, and for a
 * site whose admin does not load.
 */
final class ReportCommand
{
    public function __construct(
        private readonly ReportBuilder $builder,
        private readonly ReportSender $sender,
    ) {
    }

    public function register(): void
    {
        if (! class_exists(WP_CLI::class)) {
            return;
        }

        WP_CLI::add_command(CliCommands::COMMAND_NAMESPACE . ' report', [$this, 'report']);
    }

    /**
     * Print a problem report as JSON, or send it to VCR.AM support.
     *
     * Without --send nothing leaves the site.
     *
     * The report describes the plugin, the site, the settings and the
     * plugin's own journal. It holds nothing about customers.
     *
     * ## OPTIONS
     *
     * [--order=<id>]
     * : Report on this order: its state, its receipt's state, and the
     * journal lines that belong to it.
     *
     * [--send]
     * : Send the report to VCR.AM support instead of printing it. Needs
     * --message.
     *
     * [--message=<text>]
     * : What happened and what you expected. Sent with the report.
     *
     * [--contact=<text>]
     * : Where support should answer: an email, a phone or a Telegram name.
     *
     * ## EXAMPLES
     *
     *     wp vcr report > vcr-report.json
     *     wp vcr report --order=8402
     *     wp vcr report --order=8402 --send --message="Paid at the bank, no receipt"
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assoc
     */
    public function report(array $args, array $assoc): void
    {
        $order = null;

        if (isset($assoc['order'])) {
            $raw = $assoc['order'];

            if (! ctype_digit($raw)) {
                WP_CLI::error(sprintf('--order must be an order id, got "%s".', $raw));

                return;
            }

            $found = wc_get_order((int) $raw);

            if (! $found instanceof WC_Order) {
                WP_CLI::error(sprintf('Order %s not found.', $raw));

                return;
            }

            $order = $found;
        }

        if (isset($assoc['send'])) {
            $this->send($order, $assoc['message'] ?? '', $assoc['contact'] ?? null);

            return;
        }

        try {
            $json = $this->builder->json($order);
        } catch (ReportUnavailable $e) {
            WP_CLI::error($e->getMessage());

            return;
        }

        WP_CLI::line($json);
    }

    private function send(?WC_Order $order, string $message, ?string $contact): void
    {
        if (trim($message) === '') {
            WP_CLI::error('--send needs --message="what happened and what you expected".');

            return;
        }

        $outcome = $this->sender->send($order, $message, $contact);

        if ($outcome->reference === null) {
            WP_CLI::error(sprintf(
                'The report was not sent (%s)%s',
                $outcome->problem->value ?? 'unknown',
                $outcome->detail === null ? '.' : ': ' . $outcome->detail,
            ));

            return;
        }

        WP_CLI::success(sprintf('Sent to VCR.AM support. Reference: %s', $outcome->reference));
    }
}
