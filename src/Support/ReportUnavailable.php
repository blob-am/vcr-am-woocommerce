<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Support;

use RuntimeException;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The report could not be produced. The message says why, in words fit to
 * show the person who asked for it.
 */
final class ReportUnavailable extends RuntimeException
{
}
