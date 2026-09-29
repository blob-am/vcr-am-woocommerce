<?php

/**
 * Test fixture: report the two wp_options rows the API key touches.
 *
 * `vcr_api_key` is the field WooCommerce knows about and must always be
 * blank — our sanitize filter diverts the value before WC persists it.
 * `vcr_api_key_encrypted` is KeyStore's ciphertext.
 *
 * Echoes JSON: `{"plainOption": "...", "encryptedOption": "..."}`.
 */

$plain = get_option('vcr_api_key', '');
$encrypted = get_option('vcr_api_key_encrypted', '');

echo wp_json_encode([
    'plainOption' => is_string($plain) ? $plain : '',
    'encryptedOption' => is_string($encrypted) ? $encrypted : '',
]) . "\n";
