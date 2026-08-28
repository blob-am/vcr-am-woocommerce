<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\DepartmentListItem;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\TaxRegime;
use Throwable;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Cached, dropdown-friendly view of the departments the admin's API key
 * can fiscalise against.
 *
 * Structurally a copy of {@see CashierCatalog} — same transient, same
 * TTL, same "every failure collapses to an empty list so a dropdown
 * render can't kill the admin page" contract. What differs is the label:
 * **every option leads with the tax regime.**
 *
 * That is the whole point of this class. The department, not the offer,
 * decides the tax regime printed on the receipt, and a VCR register is
 * created with one department per regime numbered in a fixed order — so
 * department 1 is the VAT one on every register, including registers
 * that owe no VAT. This field used to be a bare number input, and an
 * admin typing "1" into it because it looks like a sensible first id
 * puts a VAT line on every receipt the store issues. Nothing rejects it,
 * and a fiscal receipt can only be refunded and reissued, never
 * corrected. Showing the regime is what makes the wrong choice visible
 * before it is saved.
 *
 * Not declared `final` so unit tests can mock it — there's no production
 * extension point.
 */
class DepartmentCatalog
{
    private const TRANSIENT_KEY = 'vcr_departments_cache';

    private const TTL_SECONDS = HOUR_IN_SECONDS;

    /** Language preferred when picking a department's display title. */
    private const PRIMARY_LANGUAGE = 'hy';

    public function __construct(
        private readonly Configuration $config,
        private readonly DepartmentListerFactory $listerFactory,
    ) {
    }

    /**
     * @return array<int, string> Map of department internal id → human-readable label.
     */
    public function list(): array
    {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached)) {
            // We trust the shape we wrote ourselves on the way in — the
            // only writer is `shapeForDropdown()` below.
            /** @var array<int, string> $cached */
            return $cached;
        }

        $apiKey = $this->config->apiKey();
        if ($apiKey === null) {
            return [];
        }

        try {
            $departments = $this->listerFactory->create($apiKey)->listDepartments();
        } catch (Throwable $e) {
            // Don't cache failures — a blip shouldn't hide the list for
            // the next hour.
            return [];
        }

        $shaped = $this->shapeForDropdown($departments);

        // Don't cache an empty result either; see CashierCatalog for the
        // "just set up VCR, list is still empty" bootstrap rationale.
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
     * @param  list<DepartmentListItem> $departments
     * @return array<int, string>
     */
    private function shapeForDropdown(array $departments): array
    {
        $list = [];
        foreach ($departments as $department) {
            $list[$department->internalId] = $this->labelFor($department);
        }

        return $list;
    }

    private function labelFor(DepartmentListItem $department): string
    {
        $regime = $this->regimeLabel($department->taxRegime);
        $title = $this->titleFor($department);

        if ($title === null) {
            /* translators: 1: tax regime name, 2: department internal id. */
            return sprintf(__('%1$s (#%2$d)', 'vcr-am-fiscal-receipts'), $regime, $department->internalId);
        }

        /* translators: 1: tax regime name, 2: department title, 3: department internal id. */
        return sprintf(__('%1$s — %2$s (#%3$d)', 'vcr-am-fiscal-receipts'), $regime, $title, $department->internalId);
    }

    /**
     * Departments created before titles were mandatory come back with an
     * empty map. Returning null (rather than an invented placeholder)
     * lets the caller render the id-only variant.
     */
    private function titleFor(DepartmentListItem $department): ?string
    {
        $primary = $department->title[self::PRIMARY_LANGUAGE] ?? null;
        if ($primary !== null) {
            return $primary->content;
        }

        foreach ($department->title as $localized) {
            return $localized->content;
        }

        return null;
    }

    /**
     * No `default` arm on purpose: if the SDK ever grows a fifth regime,
     * static analysis fails here rather than the dropdown quietly
     * labelling it as something it isn't.
     */
    private function regimeLabel(TaxRegime $regime): string
    {
        return match ($regime) {
            TaxRegime::Vat => __('VAT', 'vcr-am-fiscal-receipts'),
            TaxRegime::VatExempt => __('VAT-exempt', 'vcr-am-fiscal-receipts'),
            TaxRegime::TurnoverTax => __('Turnover tax', 'vcr-am-fiscal-receipts'),
            TaxRegime::MicroEnterprise => __('Micro-enterprise', 'vcr-am-fiscal-receipts'),
        };
    }
}
