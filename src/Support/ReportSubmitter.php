<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Support;

use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\SupportReportInput;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\SupportReportReceipt;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The one VCR.AM call that sending a report needs.
 */
interface ReportSubmitter
{
    public function submit(SupportReportInput $input): SupportReportReceipt;
}
