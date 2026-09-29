<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Settings;

use BlobSolutions\WooCommerceVcrAm\Catalog\CatalogListing;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * The shape shared by the two dropdowns that are filled from the VCR API.
 *
 * Three states, and the empty ones no longer guess why they are empty:
 * the checklist above the form has already classified that, and says so in
 * one sentence with a link. A dropdown that repeats the diagnosis is a
 * dropdown that can contradict it — which is exactly what 0.1.6 did, where
 * "check your API key permissions" appeared under a perfectly good key
 * whose register simply had no cashiers.
 */
final class CatalogSelect
{
    /**
     * @return array<string, mixed>
     */
    public function build(
        string $name,
        string $optionId,
        CatalogListing $listing,
        string $placeholder,
        string $desc,
        string $descTip,
    ): array {
        if ($listing->entries === []) {
            return [
                'name' => $name,
                'type' => 'select',
                'id' => $optionId,
                'options' => ['' => $this->emptyLabel($listing)],
                'desc' => $desc,
                'custom_attributes' => ['disabled' => 'disabled'],
                'default' => '',
            ];
        }

        return [
            'name' => $name,
            'type' => 'select',
            'id' => $optionId,
            'options' => ['' => $placeholder] + $listing->entries,
            'desc' => $desc,
            'desc_tip' => $descTip,
            'default' => '',
        ];
    }

    private function emptyLabel(CatalogListing $listing): string
    {
        if (! $listing->isAvailable()) {
            return __('Nothing loaded — see the checklist above', 'vcr-am-fiscal-receipts');
        }

        return __('None on this register yet — see the checklist above', 'vcr-am-fiscal-receipts');
    }
}
