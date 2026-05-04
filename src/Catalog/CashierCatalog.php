<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\CashierListItem;
use Throwable;

/**
 * Cached, dropdown-friendly view of the cashiers the admin's API key
 * has access to.
 *
 * Backed by a WP transient so the settings page doesn't hit the API on
 * every render. The settings save handler must call `refresh()` so a
 * fresh fetch happens after credentials change. TTL is intentionally
 * conservative (1h) to bound staleness when a cashier is added or
 * renamed in the VCR dashboard between explicit refreshes.
 *
 * Failure modes — missing credentials, network error, API rejection —
 * all collapse to "empty list". The settings UI surfaces this as a
 * helpful "save your API key first" or "couldn't reach VCR" message;
 * we don't propagate exceptions because the dropdown render path
 * shouldn't kill the entire admin page.
 */
/**
 * Not declared `final` so unit tests can mock — there's no production
 * extension point. (Same convention as our other DI-injected services.)
 */
class CashierCatalog
{
    private const TRANSIENT_KEY = 'vcr_cashiers_cache';

    private const TTL_SECONDS = HOUR_IN_SECONDS;

    /** Language code preferred when picking a cashier's display name. */
    private const PRIMARY_LANGUAGE = 'hy';

    public function __construct(
        private readonly Configuration $config,
        private readonly CashierListerFactory $listerFactory,
    ) {
    }

    /**
     * @return array<int, string> Map of cashier internal id → human-readable label.
     */
    public function list(): array
    {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached)) {
            // We trust the shape we wrote ourselves on the way in. The
            // PHPDoc declaration above is the contract; the in-memory
            // cache obeys it because `set_transient` only ever sees
            // values produced by `shapeForDropdown()`.
            /** @var array<int, string> $cached */
            return $cached;
        }

        $apiKey = $this->config->apiKey();
        if ($apiKey === null) {
            return [];
        }

        try {
            $cashiers = $this->listerFactory->create($apiKey)->listCashiers();
        } catch (Throwable $e) {
            // Don't cache failures — a transient failure shouldn't lock
            // the admin out of seeing cashiers for the next hour.
            return [];
        }

        $shaped = $this->shapeForDropdown($cashiers);

        // Don't cache empty results either. The "I just set up VCR but
        // haven't created my first cashier yet" workflow is real: admin
        // saves API key, sees empty dropdown, hops over to VCR to create
        // a cashier, comes back. With the empty result cached for an
        // hour they'd be staring at "no cashiers" until they think to
        // re-save settings to invalidate the cache. Hitting the API on
        // every settings render in this state is fine — it's a one-off
        // bootstrap window, not a steady-state path.
        if ($shaped !== []) {
            set_transient(self::TRANSIENT_KEY, $shaped, self::TTL_SECONDS);
        }

        return $shaped;
    }

    public function refresh(): void
    {
        delete_transient(self::TRANSIENT_KEY);
    }

    /**
     * @param  list<CashierListItem> $cashiers
     * @return array<int, string>
     */
    private function shapeForDropdown(array $cashiers): array
    {
        $list = [];
        foreach ($cashiers as $cashier) {
            $list[$cashier->internalId] = $this->labelFor($cashier);
        }

        return $list;
    }

    private function labelFor(CashierListItem $cashier): string
    {
        $primary = $cashier->name[self::PRIMARY_LANGUAGE] ?? null;
        $name = $primary !== null ? $primary->content : $this->fallbackName($cashier);

        return sprintf('%s (desk %s)', $name, $cashier->deskId);
    }

    private function fallbackName(CashierListItem $cashier): string
    {
        foreach ($cashier->name as $localized) {
            return $localized->content;
        }

        return '#' . $cashier->internalId;
    }
}
