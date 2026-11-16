<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Cli;

use BlobSolutions\WooCommerceVcrAm\Support\ReportBuilder;
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
     * Print a problem report as JSON. Nothing is sent anywhere.
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
     * ## EXAMPLES
     *
     *     wp vcr report > vcr-report.json
     *     wp vcr report --order=8402
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

        try {
            $json = $this->builder->json($order);
        } catch (ReportUnavailable $e) {
            WP_CLI::error($e->getMessage());

            return;
        }

        WP_CLI::line($json);
    }
}
