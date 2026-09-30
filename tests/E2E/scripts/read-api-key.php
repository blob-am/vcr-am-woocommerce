<?php

/**
 * Test fixture: report the decrypted API key, the way every consumer reads it.
 *
 * `read-api-key-options.php` reports the raw wp_options rows, which answers
 * "was anything written in plaintext?". This answers the other question —
 * "which key would the plugin use for the next receipt?" — which is what a
 * pairing has to change and a failed pairing has to leave alone.
 *
 * Echoes JSON: `{"apiKey": "..."|null}`.
 */

$config = new BlobSolutions\WooCommerceVcrAm\Configuration(
    new BlobSolutions\WooCommerceVcrAm\Settings\KeyStore('vcr_api_key_encrypted'),
);

echo wp_json_encode(['apiKey' => $config->apiKey()]) . "\n";
