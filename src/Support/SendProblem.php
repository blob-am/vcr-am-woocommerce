<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Support;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Why a report did not reach VCR.AM. The value travels in the redirect back
 * to the report screen, which turns it into words.
 */
enum SendProblem: string
{
    case MessageMissing = 'message_missing';
    case NoApiKey = 'no_api_key';
    case ReportUnavailable = 'report_unavailable';
    case TooManyReports = 'too_many_reports';
    case TooLarge = 'too_large';
    case Rejected = 'rejected';
    case Unreachable = 'unreachable';
}
