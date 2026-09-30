<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Pairing;

use BlobSolutions\WooCommerceVcrAm\Settings\VcrSettingsTab;

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
    /** WooCommerce's settings page, which hosts every tab. */
    private const PAGE = 'wc-settings';

    /** Our tab on it, named by the class that registers it. */
    private const TAB = VcrSettingsTab::ID;

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
        return admin_url('admin.php?page=' . self::PAGE . '&tab=' . self::TAB);
    }

    /**
     * Whether the request being served is a load of this screen.
     *
     * Lives here rather than in the two classes that ask, because this is where
     * the same two names build the URL: a screen check that drifted from
     * {@see plain()} would quietly stop recognising our own redirects.
     */
    public static function isCurrentScreen(): bool
    {
        return self::queryValue('page') === self::PAGE
            && self::queryValue('tab') === self::TAB;
    }

    public static function withNotice(string $notice): string
    {
        return add_query_arg(self::NOTICE_QUERY_PARAM, $notice, self::plain());
    }

    private static function queryValue(string $key): ?string
    {
        if (! isset($_GET[$key]) || ! is_string($_GET[$key])) {
            return null;
        }

        return sanitize_text_field(wp_unslash($_GET[$key]));
    }
}
