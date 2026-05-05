<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Admin;

/**
 * Adds the "Settings" shortcut to our plugin row on `/wp-admin/plugins.php`,
 * plus a "Docs" link to the upstream readme/repo.
 *
 * Every mature WooCommerce plugin ships this — its absence is a tell
 * that the plugin is either very young or wasn't authored by someone
 * who's lived in the WC ecosystem. The "Settings" link in particular
 * is muscle memory for shop admins: open Plugins → click Settings on
 * the plugin row → land in the right place. Without it, admins hunt
 * around the WC admin menu looking for our tab.
 *
 * Hook is `plugin_action_links_<plugin_basename>` (per-plugin variant)
 * rather than the generic `plugin_action_links` filter — the per-plugin
 * form fires only for our row, so we don't have to identity-check on
 * every plugin's render.
 */

if (! defined('ABSPATH')) {
    exit;
}

class PluginActionLinks
{
    /**
     * URL fragment for the Documentation / GitHub link in the plugins
     * row. Points at the plugin's README on the upstream repo so
     * shop admins can find install / config / troubleshooting docs
     * without leaving WP admin.
     */
    private const DOCS_URL = 'https://github.com/blob-am/vcr-am-woocommerce#readme';

    public function __construct(
        private readonly string $pluginFile,
    ) {
    }

    public function register(): void
    {
        $basename = plugin_basename($this->pluginFile);
        add_filter('plugin_action_links_' . $basename, [$this, 'addLinks']);
    }

    /**
     * @param  mixed $links  WP passes an array<string, string> of HTML
     *                       anchor tags keyed by action slug, but the
     *                       core filter is documented as `mixed` for
     *                       backwards compatibility.
     * @return array<int|string, string>
     */
    public function addLinks($links): array
    {
        // Defensive narrowing — WP filter spec is array, but the
        // public type-hint is `mixed` for legacy callers; we accept
        // any input and normalise to a string-keyed array.
        $normalised = [];
        if (is_array($links)) {
            foreach ($links as $key => $value) {
                if (is_string($value)) {
                    $normalised[$key] = $value;
                }
            }
        }

        // Settings link — pre-pend so it shows leftmost (the position
        // shop admins look at first; matches WC's own convention for
        // its bundled extensions).
        $settingsUrl = admin_url('admin.php?page=wc-settings&tab=vcr');
        $settingsLink = sprintf(
            '<a href="%s">%s</a>',
            esc_url($settingsUrl),
            esc_html(__('Settings', 'vcr-am-fiscal-receipts')),
        );

        // Docs link — append after WP's "Activate / Deactivate / Delete"
        // entries (the trailing slot is the docs/support convention).
        $docsLink = sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url(self::DOCS_URL),
            esc_html(__('Docs', 'vcr-am-fiscal-receipts')),
        );

        return array_merge(
            ['settings' => $settingsLink],
            $normalised,
            ['docs' => $docsLink],
        );
    }
}
