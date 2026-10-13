<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Update;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One release of this plugin, as the update feed describes it.
 *
 * Everything here arrives over the network and ends up in front of WordPress's
 * installer, so the constructor is private and {@see fromFeed} is the only way
 * in: a field that is missing, empty or the wrong type produces no release at
 * all rather than a half-built one. A store that learns nothing about a new
 * version keeps running the version it has, which is the harmless end of this;
 * a store handed a malformed package URL has its working plugin replaced by
 * whatever answered.
 *
 * The download host is pinned here rather than trusted from the feed. The feed
 * exists so the artefacts can move without reissuing the `Update URI` header
 * every installed copy carries — but "can move" means to another host we
 * publish from, and that list belongs in the code being updated, not in the
 * document telling it to update.
 */
final class Release
{
    /**
     * Hosts a package may be downloaded from. Exact match, or a subdomain of
     * one of these.
     */
    private const PACKAGE_HOSTS = ['github.com', 'vcr.am'];

    /**
     * @param array<string, string> $sections readme section HTML, keyed the
     *                                        way the plugin-information modal
     *                                        addresses them ("changelog", …)
     */
    private function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly string $version,
        public readonly string $package,
        public readonly string $detailsUrl,
        public readonly ?string $requires,
        public readonly ?string $requiresPhp,
        public readonly ?string $tested,
        public readonly ?string $lastUpdated,
        public readonly array $sections,
    ) {
    }

    /**
     * @param mixed $decoded the JSON body of the feed, already decoded
     */
    public static function fromFeed($decoded): ?self
    {
        if (! is_array($decoded)) {
            return null;
        }

        $name = self::requiredString($decoded, 'name');
        $slug = self::requiredString($decoded, 'slug');
        $version = self::requiredString($decoded, 'version');
        $package = self::requiredString($decoded, 'package');
        $detailsUrl = self::requiredString($decoded, 'url');

        if ($name === null || $slug === null || $version === null || $package === null || $detailsUrl === null) {
            return null;
        }

        if (! self::isPublishedPackageUrl($package)) {
            return null;
        }

        return new self(
            name: $name,
            slug: $slug,
            version: $version,
            package: $package,
            detailsUrl: $detailsUrl,
            requires: self::optionalString($decoded, 'requires'),
            requiresPhp: self::optionalString($decoded, 'requires_php'),
            tested: self::optionalString($decoded, 'tested'),
            lastUpdated: self::optionalString($decoded, 'last_updated'),
            sections: self::sections($decoded),
        );
    }

    /**
     * The same release, with `Tested up to` read as the branch it names.
     *
     * The readme standard treats `Tested up to: 7.1` as covering the 7.1 line,
     * which is why nobody re-releases a plugin for 7.1.1. Core does not read it
     * that way: `install_plugin_information()` compares the running version to
     * this one with `<=`, so a bare branch prints "this plugin has not been
     * tested with your current version of WordPress" on every site past .0.
     *
     * WordPress.org papers over this for the plugins it hosts — asked about
     * WooCommerce from a 7.1.3 site it answers `tested: 7.1.3`, not the 7.1 in
     * the readme. This does the same, and only inside the branch the readme
     * actually named: a site on 7.2 still gets the warning, because that is a
     * branch nobody tested against.
     */
    public function forWordPressVersion(string $wordPressVersion): self
    {
        if ($this->tested === null || ! str_starts_with($wordPressVersion, $this->tested . '.')) {
            return $this;
        }

        return new self(
            name: $this->name,
            slug: $this->slug,
            version: $this->version,
            package: $this->package,
            detailsUrl: $this->detailsUrl,
            requires: $this->requires,
            requiresPhp: $this->requiresPhp,
            tested: $wordPressVersion,
            lastUpdated: $this->lastUpdated,
            sections: $this->sections,
        );
    }

    /**
     * The slim shape `update_plugins_{$hostname}` wants. Core compares the
     * version against the installed one itself and files the result under
     * `response` or `no_update`, so this says nothing about whether the
     * release is newer — and the readme sections stay out, because every entry
     * here is stored in a site transient on every site, twice a day, forever.
     *
     * @return array<string, string>
     */
    public function toUpdateOffer(): array
    {
        $offer = [
            'slug' => $this->slug,
            'version' => $this->version,
            'url' => $this->detailsUrl,
            'package' => $this->package,
        ];

        foreach (['requires' => $this->requires, 'requires_php' => $this->requiresPhp, 'tested' => $this->tested] as $key => $value) {
            if ($value !== null) {
                $offer[$key] = $value;
            }
        }

        return $offer;
    }

    /**
     * Rejects anything that is not an https URL on a host we publish from.
     * Plain http is refused outright: this URL names code that is about to be
     * unpacked over a live plugin, and on http any network between the two can
     * choose what that code is.
     */
    private static function isPublishedPackageUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $host = isset($parts['host']) && is_string($parts['host']) ? strtolower($parts['host']) : '';

        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        foreach (self::PACKAGE_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function requiredString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function optionalString(array $data, string $key): ?string
    {
        return self::requiredString($data, $key);
    }

    /**
     * @param  array<array-key, mixed> $data
     * @return array<string, string>
     */
    private static function sections(array $data): array
    {
        $sections = $data['sections'] ?? null;
        if (! is_array($sections)) {
            return [];
        }

        $clean = [];
        foreach ($sections as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
