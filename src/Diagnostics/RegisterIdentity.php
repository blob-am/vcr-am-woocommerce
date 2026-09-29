<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Diagnostics;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * The register an API key belongs to, in the plugin's own terms.
 *
 * Deliberately not the SDK's `AccountInfo`. Two reasons, and both have
 * bitten this plugin's neighbours before:
 *
 *   - It gets cached in a WordPress transient. A serialized object from
 *     `…\Vendor\…` is a hostage to Strauss: re-prefix the namespace in a
 *     later build and every cached row unserializes to
 *     `__PHP_Incomplete_Class`. Primitives survive any rename.
 *   - Admin screens should not read API response shapes directly. The
 *     mapping happens once, in {@see IdentityReaderFactory}.
 *
 * `crn` is null until the register is activated with the SRC; `isSandbox`
 * is what the merchant sees, since a sandbox register's receipts carry no
 * legal force.
 */
final readonly class RegisterIdentity
{
    public function __construct(
        public int $vcrId,
        public ?string $crn,
        public bool $isSandbox,
        public string $tradingName,
        public string $entityName,
        public string $tin,
    ) {
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    public function toArray(): array
    {
        return [
            'vcrId' => $this->vcrId,
            'crn' => $this->crn,
            'isSandbox' => $this->isSandbox,
            'tradingName' => $this->tradingName,
            'entityName' => $this->entityName,
            'tin' => $this->tin,
        ];
    }

    /**
     * Rebuild from a cached row, or null when the row is not what we wrote.
     *
     * A transient survives plugin upgrades, so a row written by an older
     * version can be missing a field this version reads. Returning null
     * lets the caller treat that as a cache miss and re-probe, which is
     * both correct and self-healing.
     *
     * @param array<array-key, mixed> $raw
     */
    public static function fromArray(array $raw): ?self
    {
        $vcrId = $raw['vcrId'] ?? null;
        $isSandbox = $raw['isSandbox'] ?? null;
        $tradingName = $raw['tradingName'] ?? null;
        $entityName = $raw['entityName'] ?? null;
        $tin = $raw['tin'] ?? null;
        $crn = $raw['crn'] ?? null;

        if (! is_int($vcrId) || ! is_bool($isSandbox)) {
            return null;
        }

        if (! is_string($tradingName) || ! is_string($entityName) || ! is_string($tin)) {
            return null;
        }

        if ($crn !== null && ! is_string($crn)) {
            return null;
        }

        return new self($vcrId, $crn, $isSandbox, $tradingName, $entityName, $tin);
    }
}
