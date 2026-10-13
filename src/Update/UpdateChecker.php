<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Update;

use stdClass;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Teaches WordPress where this plugin's updates come from.
 *
 * The plugin is distributed as a ZIP from its own repository, not from
 * WordPress.org, so nothing tells a store that a release happened: the
 * merchant installs a version and keeps it until someone emails them a newer
 * one. The store that prompted this was six releases behind and still pressing
 * "Test connection" every few days, because it had no way to know.
 *
 * WordPress has had the mechanism for this since 5.8, and the plugin requires
 * 6.7, so there is nothing to build around it. The entry file declares an
 * `Update URI`; core parses its hostname, calls `update_plugins_{$hostname}`
 * during its own twice-daily check, and from a returned version it does the
 * rest itself — the "new version available" row, the update button, the
 * bulk-update checkbox, and the per-plugin auto-update toggle the merchant
 * opts into. Core also does the version comparison, so this class never
 * decides whether a release is newer; it only says what the newest one is.
 *
 * `plugins_api` is the other half and is not optional. Once an update offer
 * carries a slug, core renders "View version x.y.z details" as a link into the
 * plugin-information modal, which asks that filter what to show. Answering it
 * is the difference between the native changelog screen and an empty modal.
 *
 * Note what this does not do: it does not enable automatic updates. The
 * toggle appears, switched off, and stays the merchant's decision. A fiscal
 * plugin that silently replaced itself on a store filing receipts to the tax
 * service would be making that decision for them.
 *
 * This whole class is incompatible with the WordPress.org directory, and
 * deliberately so. Plugin Check reports the `Update URI` header as an error —
 * `plugin_updater_detected` — because a hosted plugin is updated by the
 * directory and a second source would override it. Submitting means deleting
 * this file, the header, and the wiring in `Plugin::boot()`, and losing nothing:
 * the directory then does all of it. Until that day the plugin is distributed
 * as a ZIP, and a ZIP that cannot announce its own releases is how a store ends
 * up six versions behind.
 */
class UpdateChecker
{
    /**
     * Must match the `Update URI` header in the plugin entry file; the entry
     * file is what core reads, and this is what decides which hook we listen
     * on. `PluginEntryFileTest` holds the two together.
     *
     * The URL is not fetched by core — only its hostname is used — but it is
     * a real page, because it is what a merchant sees if they ever paste it
     * into a browser.
     */
    public const UPDATE_URI = 'https://vcr.am/plugins/vcr-am-fiscal-receipts';

    public function __construct(
        private readonly string $pluginFile,
        private readonly ReleaseFeed $feed = new ReleaseFeed(),
    ) {
    }

    public function register(): void
    {
        $host = wp_parse_url(self::UPDATE_URI, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return;
        }

        add_filter('update_plugins_' . $host, [$this, 'offerUpdate'], 10, 3);
        add_filter('plugins_api', [$this, 'describeRelease'], 10, 3);
    }

    /**
     * The release as it applies to this site. `get_bloginfo` is the only WP
     * call either filter needs, and it is read here so both read it the same.
     */
    private function release(): ?Release
    {
        return $this->feed->latest()?->forWordPressVersion(get_bloginfo('version'));
    }

    /**
     * @param  mixed  $update     what an earlier filter offered, `false` by default
     * @param  mixed  $pluginData the plugin's own headers, as core read them
     * @param  mixed  $pluginFile the plugin being asked about
     * @return mixed
     */
    public function offerUpdate($update, $pluginData, $pluginFile)
    {
        // The hook is keyed by hostname, not by plugin, so another plugin
        // updating from vcr.am would land here too. Only answer for ourselves,
        // and never overwrite an answer someone else already gave.
        if ($update !== false || $pluginFile !== plugin_basename($this->pluginFile)) {
            return $update;
        }

        $release = $this->release();
        if ($release === null) {
            return $update;
        }

        return $release->toUpdateOffer();
    }

    /**
     * @param  mixed $result what an earlier filter resolved, `false` by default
     * @param  mixed $action which plugins_api call this is
     * @param  mixed $args   the query; `slug` is the one that matters
     * @return mixed
     */
    public function describeRelease($result, $action, $args)
    {
        if ($action !== 'plugin_information' || ! is_object($args)) {
            return $result;
        }

        $release = $this->release();
        if ($release === null) {
            return $result;
        }

        $slug = $args->slug ?? null;
        if (! is_string($slug) || $slug !== $release->slug) {
            return $result;
        }

        $information = new stdClass();
        $information->name = $release->name;
        $information->slug = $release->slug;
        $information->version = $release->version;
        $information->homepage = $release->detailsUrl;
        $information->download_link = $release->package;
        $information->trunk = $release->package;
        $information->sections = $release->sections;

        foreach ([
            'requires' => $release->requires,
            'requires_php' => $release->requiresPhp,
            'tested' => $release->tested,
            'last_updated' => $release->lastUpdated,
        ] as $field => $value) {
            if ($value !== null) {
                $information->{$field} = $value;
            }
        }

        // Read off the installed copy's own header block rather than carried
        // in the feed: it is the same answer, and a value that is already in
        // the file cannot disagree with it. Absent outside the admin include
        // set, where this filter does not run anyway.
        if (function_exists('get_plugin_data')) {
            $headers = get_plugin_data($this->pluginFile, false, false);
            $author = $headers['Author'] ?? '';
            if (is_string($author) && $author !== '') {
                $information->author = $author;
            }
        }

        return $information;
    }
}
