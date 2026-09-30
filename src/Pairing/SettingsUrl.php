<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Pairing;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The plugin's settings screen, which is also where a pairing comes back to.
 *
 * It doubles as the `redirectUri` the store registers, and the merchant is
 * shown that URI on the consent screen before approving — so it is deliberately
 * the page they already know rather than a bare `admin-post.php?action=...`,
 * which would read like something else asking for their register.
 */
final class SettingsUrl
{
    /**
     * Marks a request as carrying a pairing outcome, so the notice survives
     * the redirect that strips `code` and `state` from the address bar.
     */
    public const NOTICE_QUERY_PARAM = 'vcr_pairing';

    public const NOTICE_CONNECTED = 'connected';
    public const NOTICE_DENIED = 'denied';
    public const NOTICE_EXPIRED = 'expired';
    public const NOTICE_FAILED = 'failed';

    public static function plain(): string
    {
        return admin_url('admin.php?page=wc-settings&tab=vcr');
    }

    public static function withNotice(string $notice): string
    {
        return add_query_arg(self::NOTICE_QUERY_PARAM, $notice, self::plain());
    }
}
