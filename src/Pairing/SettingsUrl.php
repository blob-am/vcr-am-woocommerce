<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Pairing;

use BlobSolutions\WooCommerceVcrAm\Request\QueryParameter;

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

    /**
     * Our tab on it, and the id Settings\VcrSettingsTab registers it under.
     *
     * Defined here and not on that class, because naming a constant of a
     * class loads the class, and VcrSettingsTab extends WC_Settings_Page,
     * which WooCommerce 9.4 has not loaded outside its own settings screen.
     * Read through VcrSettingsTab, this id took down the Plugins screen (the
     * action link) and the pairing redirect there with "Class
     * WC_Settings_Page not found" (WooCommerce 9.4.5, E2E floor row).
     */
    public const TAB = 'vcr';

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
        return QueryParameter::text('page') === self::PAGE
            && QueryParameter::text('tab') === self::TAB;
    }

    public static function withNotice(string $notice): string
    {
        return add_query_arg(self::NOTICE_QUERY_PARAM, $notice, self::plain());
    }
}
