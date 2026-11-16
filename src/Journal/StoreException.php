<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Journal;

use RuntimeException;

if (! defined('ABSPATH')) {
    exit;
}

final class StoreException extends RuntimeException
{
}
