<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Admin;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * How badly a checklist line wants the merchant's attention.
 *
 * `Blocked` means no receipt can be issued at all in the current state;
 * `Attention` means receipts are issued but something is set up in a way
 * worth knowing about (a sandbox register, a department override, a
 * shipping charge nothing can file). The panel's own colour is the worst
 * level on it, so one blocked line is never lost among green ones.
 */
enum ReadinessLevel: int
{
    case Ready = 0;
    case Attention = 1;
    case Blocked = 2;

    /** WordPress admin notice suffix, so the panel inherits core styling. */
    public function noticeClass(): string
    {
        return match ($this) {
            self::Ready => 'notice-success',
            self::Attention => 'notice-warning',
            self::Blocked => 'notice-error',
        };
    }
}
