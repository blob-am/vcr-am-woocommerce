<?php

/**
 * Test fixture: count the "translation loading was triggered too early"
 * notices WordPress has logged against this plugin's text domain.
 *
 * WordPress loads a plugin's catalogue the first time one of its strings is
 * translated. A string translated before `init` gets the notice, and is
 * translated for the wrong locale besides: the visitor's language is not
 * known that early. With no catalogue shipped the lookup found nothing and
 * said nothing, so shipping translations is what makes such a call visible.
 *
 * Echoes the count as a bare integer.
 */

$log = WP_CONTENT_DIR . '/debug.log';
$count = 0;

if (is_readable($log)) {
    $handle = fopen($log, 'r');

    if ($handle === false) {
        throw new RuntimeException("Cannot open {$log}");
    }

    while (($line = fgets($handle)) !== false) {
        if (str_contains($line, '_load_textdomain_just_in_time') && str_contains($line, 'vcr-am-fiscal-receipts')) {
            $count++;
        }
    }

    fclose($handle);
}

echo $count . "\n";
