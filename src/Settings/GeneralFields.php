<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Settings;

use BlobSolutions\WooCommerceVcrAm\Admin\ReadinessPanel;
use BlobSolutions\WooCommerceVcrAm\Catalog\CashierCatalog;
use BlobSolutions\WooCommerceVcrAm\Catalog\DepartmentCatalog;
use BlobSolutions\WooCommerceVcrAm\Configuration;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * The default section of the VCR tab: everything a store needs to issue
 * receipts, and nothing else.
 *
 * What is NOT here is the point. The base URL and the department override
 * both have "leave it alone" as the right answer for virtually every store,
 * and the department one silently rewrites the tax regime on every line of
 * every receipt — so they moved to {@see AdvancedFields}, one click away
 * instead of in the middle of the path. The "Test mode" checkbox is gone
 * altogether: it wrote an option nothing read, while the register itself
 * knows whether it is a sandbox, and the checklist now says so.
 */
final class GeneralFields
{
    /** Where a merchant finds a classifier code without asking us. */
    private const CLASSIFIER_SEARCH_URL = 'https://vcr.am/classifier';

    public function __construct(
        private readonly KeyStore $keyStore,
        private readonly CashierCatalog $cashierCatalog,
        private readonly DepartmentCatalog $departmentCatalog,
        private readonly ReadinessPanel $panel,
        private readonly CatalogSelect $select = new CatalogSelect(),
        private readonly IntroDescription $intro = new IntroDescription(),
        private readonly ?ConnectButton $connect = null,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        return [
            [
                'name' => __('VCR — Fiscal Receipts for Armenia', 'vcr-am-fiscal-receipts'),
                'type' => 'title',
                // The checklist goes first: it is the answer to "will my
                // next order get a receipt?", which is why anyone opens
                // this screen. Then the connect button, because for most
                // stores it is the answer to the checklist. The legal
                // disclosure follows and still sits above the API key
                // field, which is what it has to.
                'desc' => $this->panel->render()
                    . $this->connectButton()->render()
                    . $this->intro->render(),
                'id' => 'vcr_section',
            ],
            [
                'name' => __('API Key', 'vcr-am-fiscal-receipts'),
                'type' => 'password',
                'id' => 'vcr_api_key',
                'desc_tip' => __(
                    'Your VCR.AM API key. Stored encrypted at rest using your WordPress auth salt; never written to disk in plaintext.',
                    'vcr-am-fiscal-receipts',
                ),
                'placeholder' => $this->keyStore->isSet()
                    ? __('Saved — leave empty to keep current key', 'vcr-am-fiscal-receipts')
                    : __('Required', 'vcr-am-fiscal-receipts'),
            ],
            $this->select->build(
                name: __('Cashier', 'vcr-am-fiscal-receipts'),
                optionId: Configuration::OPT_DEFAULT_CASHIER_ID,
                listing: $this->cashierCatalog->list(),
                placeholder: __('— select a cashier —', 'vcr-am-fiscal-receipts'),
                desc: __('Every receipt names the cashier who issued it. Re-saving these settings reloads the list.', 'vcr-am-fiscal-receipts'),
                descTip: __('Required before any receipt can be issued.', 'vcr-am-fiscal-receipts'),
            ),
            [
                'type' => 'sectionend',
                'id' => 'vcr_section',
            ],
            [
                'name' => __('Catalog', 'vcr-am-fiscal-receipts'),
                'type' => 'title',
                'desc' => __(
                    'Every receipt line names a catalog item, and a fiscal receipt has to say what kind of thing was sold and under which tax regime. Fill these two in and the plugin creates the catalog item for any product your register has not seen yet, as it files that receipt — nothing to prepare, and nothing to keep in step by hand. Leave them empty and it only references items you onboarded in VCR yourself, refusing orders for anything else. Either way it never rewrites an item that already exists, so a code you refine in VCR stays yours.',
                    'vcr-am-fiscal-receipts',
                ),
                'id' => 'vcr_catalog_section',
            ],
            [
                'name' => __('Classifier code for new catalog items', 'vcr-am-fiscal-receipts'),
                'type' => 'text',
                'id' => Configuration::OPT_CATALOG_CLASSIFIER_CODE,
                'desc' => sprintf(
                    /* translators: %s: link to the classifier search page, already wrapped in an anchor. */
                    __('One code for everything this store sells. %s', 'vcr-am-fiscal-receipts'),
                    sprintf(
                        '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                        esc_url(self::CLASSIFIER_SEARCH_URL),
                        esc_html__('Look one up', 'vcr-am-fiscal-receipts'),
                    ),
                ),
                'desc_tip' => __(
                    'A goods code (ТН ВЭД, digits) or an activity code (КВЭД, like 56.10) — the tax service accepts either on a receipt line. Refine it per item in VCR afterwards if you need to; the plugin will not overwrite what you set there.',
                    'vcr-am-fiscal-receipts',
                ),
                'default' => '',
                'placeholder' => '56.10',
            ],
            $this->select->build(
                name: __('Department for new catalog items', 'vcr-am-fiscal-receipts'),
                optionId: Configuration::OPT_CATALOG_DEPARTMENT_ID,
                listing: $this->departmentCatalog->list(),
                placeholder: __('— select a department —', 'vcr-am-fiscal-receipts'),
                desc: __('Which tax regime a newly created catalog item is filed under. A register with only one department needs no answer here.', 'vcr-am-fiscal-receipts'),
                descTip: __('This is the item\'s own department, not an override: existing items keep theirs, and each line is still sold from the department its item carries.', 'vcr-am-fiscal-receipts'),
            ),
            [
                'name' => __('Shipping SKU', 'vcr-am-fiscal-receipts'),
                'type' => 'text',
                'id' => Configuration::OPT_SHIPPING_SKU,
                'desc_tip' => __(
                    'Optional. External id of a "Shipping" offer you onboarded in VCR, to use instead of the one the plugin would create. Leave empty unless you already have one.',
                    'vcr-am-fiscal-receipts',
                ),
                'default' => '',
                'placeholder' => 'shipping',
            ],
            [
                'name' => __('Fee SKU', 'vcr-am-fiscal-receipts'),
                'type' => 'text',
                'id' => Configuration::OPT_FEE_SKU,
                'desc_tip' => __(
                    'Optional. Same thing for WooCommerce fee lines (handling charges, surcharges).',
                    'vcr-am-fiscal-receipts',
                ),
                'default' => '',
                'placeholder' => 'service-fee',
            ],
            [
                'type' => 'sectionend',
                'id' => 'vcr_catalog_section',
            ],
            [
                'name' => __('Cash on delivery', 'vcr-am-fiscal-receipts'),
                'type' => 'title',
                'desc' => __(
                    'When to issue the fiscal receipt for orders paid in cash on delivery. Orders paid online are unaffected — their receipt is always issued the moment the payment clears.',
                    'vcr-am-fiscal-receipts',
                ),
                'id' => 'vcr_cod_section',
            ],
            [
                'name' => __('Issue the receipt', 'vcr-am-fiscal-receipts'),
                'type' => 'select',
                'id' => Configuration::OPT_CASH_FISCALIZE_ON,
                'options' => [
                    Configuration::CASH_FISCALIZE_ON_PROCESSING => __('When the order is placed', 'vcr-am-fiscal-receipts'),
                    Configuration::CASH_FISCALIZE_ON_COMPLETED => __('When the order is marked Completed', 'vcr-am-fiscal-receipts'),
                ],
                'desc_tip' => __(
                    'Both are lawful: the law lets a delivery seller issue the receipt in advance, as long as it exists before the goods leave you. Issuing it when the order is placed means a refused delivery needs a refund receipt, because a fiscal receipt can never be corrected. Waiting until Completed avoids that, but an order nobody marks Completed is never fiscalised at all.',
                    'vcr-am-fiscal-receipts',
                ),
                'default' => Configuration::DEFAULT_CASH_FISCALIZE_ON,
            ],
            [
                'type' => 'sectionend',
                'id' => 'vcr_cod_section',
            ],
            [
                'name' => __('Reconciliation', 'vcr-am-fiscal-receipts'),
                'type' => 'title',
                'desc' => __(
                    'Attach a reference to each fiscal receipt so you can match it back to the WooCommerce order from your VCR dashboard. This note is internal to you — it is never shown to the customer and never sent to the tax authority.',
                    'vcr-am-fiscal-receipts',
                ),
                'id' => 'vcr_reconciliation_section',
            ],
            [
                'name' => __('Receipt comment', 'vcr-am-fiscal-receipts'),
                'type' => 'select',
                'id' => Configuration::OPT_COMMENT_SOURCE,
                'options' => [
                    Configuration::COMMENT_SOURCE_ORDER_NUMBER => __('WooCommerce order number', 'vcr-am-fiscal-receipts'),
                    Configuration::COMMENT_SOURCE_TRANSACTION_ID => __('Payment transaction ID', 'vcr-am-fiscal-receipts'),
                    Configuration::COMMENT_SOURCE_ORDER_AND_TRANSACTION => __('Order number + transaction ID', 'vcr-am-fiscal-receipts'),
                    Configuration::COMMENT_SOURCE_OFF => __('No comment', 'vcr-am-fiscal-receipts'),
                ],
                'desc_tip' => __(
                    'The transaction ID is your payment gateway\'s own reference (e.g. a Stripe pi_… id) and is only available once the payment has cleared.',
                    'vcr-am-fiscal-receipts',
                ),
                'default' => Configuration::DEFAULT_COMMENT_SOURCE,
            ],
            [
                'type' => 'sectionend',
                'id' => 'vcr_reconciliation_section',
            ],
        ];
    }

    /**
     * Defaulted rather than required so the existing construction sites — and
     * the tests that build this class directly — need no change; the button
     * only needs the same KeyStore this class already holds.
     */
    private function connectButton(): ConnectButton
    {
        return $this->connect ?? new ConnectButton($this->keyStore);
    }
}
