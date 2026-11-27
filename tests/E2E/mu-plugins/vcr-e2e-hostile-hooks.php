<?php

declare(strict_types=1);

/**
 * E2E: the hostile return values WooCommerce's QIT activation test forces.
 *
 * QIT's "hook resilience check" makes `rest_pre_serve_request` return null and
 * `pre_http_request` and `rest_authentication_errors` return a WP_Error, then
 * checks that the front page, wp-admin, the REST API, the WooCommerce settings
 * and wp_mail() still work with the plugin active
 * (https://qit.woo.com/docs/managed-tests/activation/). The values are forced
 * at the lowest priority, so every callback of ours on those hooks receives
 * them, the way it would behind a neighbouring plugin that returns them.
 *
 * Off unless the `vcr_e2e_hostile_hooks` option is set, so the rest of the
 * suite never sees it. tests/E2E/hook-resilience.spec.mjs switches it on and
 * off.
 *
 * @package BlobSolutions\WooCommerceVcrAm
 */

if (! defined('ABSPATH')) {
    exit;
}

if (get_option('vcr_e2e_hostile_hooks') === '1') {
    add_filter('rest_pre_serve_request', static fn (): mixed => null, PHP_INT_MIN);
    add_filter(
        'pre_http_request',
        static fn (): WP_Error => new WP_Error('vcr_e2e_hostile', 'Outbound HTTP refused by the hook resilience test.'),
        PHP_INT_MIN,
    );
    add_filter(
        'rest_authentication_errors',
        static fn (): WP_Error => new WP_Error('vcr_e2e_hostile', 'REST authentication refused by the hook resilience test.', ['status' => 401]),
        PHP_INT_MIN,
    );
}
