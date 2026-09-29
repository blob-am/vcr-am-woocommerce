<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Settings;

use BlobSolutions\WooCommerceVcrAm\Catalog\DepartmentCatalog;
use BlobSolutions\WooCommerceVcrAm\Configuration;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * The "Advanced" section: the two settings whose correct value is almost
 * always "empty".
 *
 * Both were on the main screen until 0.1.7, where they read as things to
 * fill in. The base URL sends the API key wherever it points, and the
 * department override replaces the tax regime on every line of every
 * receipt — one wrong pick there cannot be corrected, only refunded and
 * reissued. Neither belongs in the path a first-time merchant walks.
 */
final class AdvancedFields
{
    public function __construct(
        private readonly DepartmentCatalog $departmentCatalog,
        private readonly CatalogSelect $select = new CatalogSelect(),
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        return [
            [
                'name' => __('Advanced', 'vcr-am-fiscal-receipts'),
                'type' => 'title',
                'desc' => __(
                    'Leave both of these empty unless you have a specific reason not to. A normal store never needs either.',
                    'vcr-am-fiscal-receipts',
                ),
                'id' => 'vcr_advanced_section',
            ],
            [
                'name' => __('Base URL', 'vcr-am-fiscal-receipts'),
                'type' => 'text',
                'id' => Configuration::OPT_BASE_URL,
                'desc_tip' => __(
                    'Override only for staging or self-hosted VCR deployments. Leave empty to use the production endpoint. Whatever is here receives your API key on every request.',
                    'vcr-am-fiscal-receipts',
                ),
                'default' => '',
                'placeholder' => 'https://vcr.am/api/v1',
            ],
            $this->select->build(
                name: __('Override department', 'vcr-am-fiscal-receipts'),
                optionId: Configuration::OPT_DEFAULT_DEPARTMENT_ID,
                listing: $this->departmentCatalog->list(),
                placeholder: __('— use each offer\'s own department —', 'vcr-am-fiscal-receipts'),
                desc: __('Leave empty unless you know you need it. Re-saving these settings reloads the list.', 'vcr-am-fiscal-receipts'),
                descTip: __('The department sets the tax regime printed on the receipt. Each offer already has one, chosen when you onboarded it in VCR, and orders use it automatically. Picking a department here overrides every line of every order — including offers registered under a different regime. Nothing rejects a mismatch, and a fiscal receipt can only be refunded and reissued, never corrected.', 'vcr-am-fiscal-receipts'),
            ),
            [
                'type' => 'sectionend',
                'id' => 'vcr_advanced_section',
            ],
        ];
    }
}
