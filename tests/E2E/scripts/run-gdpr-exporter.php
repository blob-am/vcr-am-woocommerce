<?php

/**
 * Test fixture: invoke the WP personal-data exporter chain for the
 * given email and echo the registered VCR exporter's output as JSON.
 *
 * Usage: VCR_E2E_EMAIL=gdpr-test@example.com wp eval-file ...
 *
 * The full WP privacy-export pipeline is heavyweight (creates a
 * request post, schedules a cron, generates a ZIP). For a contract
 * test we just need to exercise OUR exporter callback — that's what
 * the WP machinery would invoke anyway.
 */

// wp-cli's `eval-file` exposes positional args as $args.
// Caller passes the email to look up.
$args = $args ?? [];
$email = $args[0] ?? ($_ENV['VCR_E2E_EMAIL'] ?? 'gdpr-test@example.com');

// Pull the registered exporters via the same filter WP applies in
// `wp-admin/admin.php?page=export_personal_data`.
$exporters = apply_filters('wp_privacy_personal_data_exporters', []);

if (! isset($exporters['vcr-fiscal-receipts'])) {
    fwrite(STDERR, "VCR exporter not registered. Filter returned: " . print_r(array_keys($exporters), true) . "\n");
    exit(1);
}

$exporter = $exporters['vcr-fiscal-receipts'];
if (! isset($exporter['callback']) || ! is_callable($exporter['callback'])) {
    fwrite(STDERR, "VCR exporter missing/invalid callback.\n");
    exit(1);
}

$result = call_user_func($exporter['callback'], $email, 1);

echo wp_json_encode($result, JSON_PRETTY_PRINT) . "\n";
