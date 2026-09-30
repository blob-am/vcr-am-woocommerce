<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Admin;

use BlobSolutions\WooCommerceVcrAm\Catalog\CashierCatalog;
use BlobSolutions\WooCommerceVcrAm\Catalog\CatalogListing;
use BlobSolutions\WooCommerceVcrAm\Catalog\DepartmentCatalog;
use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionProbe;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionProblem;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionState;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * The checklist at the top of the VCR settings tab: what state this store
 * is in, and the next thing to do about it.
 *
 * It exists because the flat form did not answer the only question a
 * merchant has on this screen — "will my next order get a receipt?" — and
 * answering it by email does not scale. Everything on it is measured, not
 * assumed: the register's identity comes from the API, the cashier list
 * from the API, and a line only claims something is wrong when the
 * classified reason says so.
 *
 * All the diagnostic wording lives here, one sentence per state, so the
 * dropdowns below can point at this panel instead of guessing their own
 * explanation. The panel's colour is the worst level on it.
 */
final class ReadinessPanel
{
    private const DASHBOARD_URL = 'https://vcr.am/dashboard';

    public function __construct(
        private readonly ConnectionProbe $probe,
        private readonly CashierCatalog $cashierCatalog,
        private readonly DepartmentCatalog $departmentCatalog,
        private readonly Configuration $configuration,
    ) {
    }

    public function render(): string
    {
        $steps = $this->steps();

        $worst = ReadinessLevel::Ready;
        $items = '';
        foreach ($steps as $step) {
            if ($step->level->value > $worst->value) {
                $worst = $step->level;
            }

            $items .= sprintf(
                '<li><strong>%s.</strong> %s</li>',
                esc_html($step->label),
                $step->text,
            );
        }

        return sprintf(
            '<div class="notice %s inline"><ul>%s</ul></div>',
            esc_attr($worst->noticeClass()),
            $items,
        );
    }

    /**
     * @return list<ReadinessStep>
     */
    private function steps(): array
    {
        $state = $this->probe->state();
        $steps = [$this->connectionStep($state)];

        // With no connection nothing below it is knowable, and inventing
        // lines like "no cashier selected" on top of a rejected key is how
        // the old screen sent people to check the wrong thing.
        if (! $state->isConnected()) {
            return $steps;
        }

        $steps[] = $this->cashierStep($this->cashierCatalog->list());

        $departments = $this->departmentCatalog->list();

        $departmentStep = $this->departmentStep($departments);
        if ($departmentStep !== null) {
            $steps[] = $departmentStep;
        }

        $steps[] = $this->catalogStep($departments);

        $shippingStep = $this->shippingStep();
        if ($shippingStep !== null) {
            $steps[] = $shippingStep;
        }

        return $steps;
    }

    private function connectionStep(ConnectionState $state): ReadinessStep
    {
        $identity = $state->identity();
        if ($identity !== null) {
            $who = sprintf(
                /* translators: 1: business name, 2: TIN, 3: register id, 4: register name. */
                __('Connected to %1$s (TIN %2$s), register #%3$d %4$s.', 'vcr-am-fiscal-receipts'),
                '<strong>' . esc_html($identity->entityName) . '</strong>',
                esc_html($identity->tin),
                $identity->vcrId,
                esc_html($identity->tradingName),
            );

            if ($identity->isSandbox) {
                return new ReadinessStep(
                    __('Connection', 'vcr-am-fiscal-receipts'),
                    $who . ' ' . __(
                        'This is a sandbox register: receipts issued from it are test receipts and have no legal force. Save the key of your production register when you have finished testing.',
                        'vcr-am-fiscal-receipts',
                    ),
                    ReadinessLevel::Attention,
                );
            }

            if ($identity->crn === null) {
                return new ReadinessStep(
                    __('Connection', 'vcr-am-fiscal-receipts'),
                    $who . ' ' . __(
                        'The register has no registration number from the tax service yet, so receipts cannot be filed. Finish its activation in the VCR dashboard.',
                        'vcr-am-fiscal-receipts',
                    ),
                    ReadinessLevel::Blocked,
                );
            }

            return new ReadinessStep(
                __('Connection', 'vcr-am-fiscal-receipts'),
                $who . ' ' . __('Receipts are filed with the tax service.', 'vcr-am-fiscal-receipts'),
                ReadinessLevel::Ready,
            );
        }

        $failure = $state->failure();
        $problem = $failure === null ? ConnectionProblem::Unexpected : $failure->problem;

        $text = match ($problem) {
            ConnectionProblem::NoApiKey => __(
                'No API key saved yet. Paste the key from your VCR dashboard into the field below and press Save changes.',
                'vcr-am-fiscal-receipts',
            ) . ' ' . $this->externalLink(self::DASHBOARD_URL, __('Open the VCR dashboard', 'vcr-am-fiscal-receipts')),

            ConnectionProblem::Unreachable => __(
                'This server could not reach vcr.am — the request failed before any answer came back. Outbound HTTPS is usually blocked by the hosting firewall; ask your host to allow it, then reload this page.',
                'vcr-am-fiscal-receipts',
            ) . ' ' . $this->internalLink(
                admin_url('admin.php?page=wc-status'),
                __('The status report shows whether PHP can make HTTP requests at all', 'vcr-am-fiscal-receipts'),
            ),

            ConnectionProblem::KeyRejected => __(
                'vcr.am does not recognise this API key. It may have been revoked, or it may belong to a register that no longer exists — create a new key in the dashboard and save it here.',
                'vcr-am-fiscal-receipts',
            ) . ' ' . $this->externalLink(self::DASHBOARD_URL, __('Open the VCR dashboard', 'vcr-am-fiscal-receipts')),

            ConnectionProblem::RegisterNotActivated => __(
                'The key works, but its register has not finished activation with the tax service and cannot issue anything yet. Finish activation in the dashboard — or create a sandbox register and use its key to test the integration first.',
                'vcr-am-fiscal-receipts',
            ) . ' ' . $this->externalLink(self::DASHBOARD_URL, __('Open the VCR dashboard', 'vcr-am-fiscal-receipts')),

            ConnectionProblem::AccessDenied => __(
                'vcr.am refused this API key. It belongs to a different register, or its access was withdrawn.',
                'vcr-am-fiscal-receipts',
            ),

            ConnectionProblem::ServerError => $this->serverErrorText($failure?->requestId),

            ConnectionProblem::Unexpected => __(
                'vcr.am answered in a way this plugin could not read. If it keeps happening, update the plugin — and send support the message below.',
                'vcr-am-fiscal-receipts',
            ),
        };

        $detail = $failure?->technicalDetail;
        if ($detail !== null && $problem !== ConnectionProblem::NoApiKey) {
            $text .= sprintf(' <em>%s</em>', esc_html($detail));
        }

        return new ReadinessStep(
            __('Connection', 'vcr-am-fiscal-receipts'),
            $text,
            $problem === ConnectionProblem::NoApiKey ? ReadinessLevel::Attention : ReadinessLevel::Blocked,
        );
    }

    private function serverErrorText(?string $requestId): string
    {
        if ($requestId === null) {
            return __(
                'vcr.am answered with an error of its own. Try again in a minute; if it keeps happening, write to support.',
                'vcr-am-fiscal-receipts',
            );
        }

        return sprintf(
            /* translators: %s is a request id to quote to support. */
            __(
                'vcr.am answered with an error of its own. Try again in a minute; if it keeps happening, send support this request id: %s.',
                'vcr-am-fiscal-receipts',
            ),
            '<code>' . esc_html($requestId) . '</code>',
        );
    }

    private function cashierStep(CatalogListing $listing): ReadinessStep
    {
        $label = __('Cashier', 'vcr-am-fiscal-receipts');

        if (! $listing->isAvailable()) {
            return new ReadinessStep(
                $label,
                __(
                    'The cashier list could not be loaded just now. Reload the page; the connection itself is fine.',
                    'vcr-am-fiscal-receipts',
                ),
                ReadinessLevel::Attention,
            );
        }

        // The register answered, and answered "none". This is the state that
        // produced the support email 0.1.7 was written for: the old screen
        // said "check your API key permissions", which had nothing to do
        // with it. A receipt has to name a cashier, so nothing can be
        // issued until the register has one. Since 2026-09-29 a register is
        // given its first cashier the moment it becomes able to file, so
        // this state means a register older than that — and any role on the
        // business can fix it, integrator included.
        if ($listing->isEmpty()) {
            return new ReadinessStep(
                $label,
                __(
                    'This register has no cashiers yet, and every receipt has to name one. Add one in the VCR dashboard — owner, accountant and developer accounts can all do it, and for a one-person shop opening the register\'s desk once is enough — after which this page will find it.',
                    'vcr-am-fiscal-receipts',
                ) . ' ' . $this->externalLink(self::DASHBOARD_URL, __('Open the VCR dashboard', 'vcr-am-fiscal-receipts')),
                ReadinessLevel::Blocked,
            );
        }

        $selected = $this->configuration->defaultCashierId();
        if ($selected === null) {
            return new ReadinessStep(
                $label,
                __('Choose the cashier receipts will be issued by, below, and press Save changes.', 'vcr-am-fiscal-receipts'),
                ReadinessLevel::Blocked,
            );
        }

        $selectedLabel = $listing->entries[$selected] ?? null;
        if ($selectedLabel === null) {
            return new ReadinessStep(
                $label,
                sprintf(
                    /* translators: %d is the stored cashier number. */
                    __(
                        'The saved cashier (#%d) is not on this register any more, so receipts are being refused. Choose another one below.',
                        'vcr-am-fiscal-receipts',
                    ),
                    $selected,
                ),
                ReadinessLevel::Blocked,
            );
        }

        return new ReadinessStep(
            $label,
            sprintf(
                /* translators: %s is the chosen cashier's label. */
                __('Receipts will be issued by %s.', 'vcr-am-fiscal-receipts'),
                '<strong>' . esc_html($selectedLabel) . '</strong>',
            ),
            ReadinessLevel::Ready,
        );
    }

    /**
     * Departments only earn a line when they need one: a register with none
     * (which cannot fiscalise anything) or an override in place, which
     * silently replaces every offer's own tax regime and is otherwise
     * invisible from this screen.
     */
    private function departmentStep(CatalogListing $listing): ?ReadinessStep
    {
        if ($listing->isAvailable() && $listing->isEmpty()) {
            return new ReadinessStep(
                __('Departments', 'vcr-am-fiscal-receipts'),
                __(
                    'This register has no departments, so nothing can be fiscalised. Add one in the VCR dashboard.',
                    'vcr-am-fiscal-receipts',
                ) . ' ' . $this->externalLink(self::DASHBOARD_URL, __('Open the VCR dashboard', 'vcr-am-fiscal-receipts')),
                ReadinessLevel::Blocked,
            );
        }

        $override = $this->configuration->defaultDepartmentId();
        if ($override === null) {
            return null;
        }

        $overrideLabel = $listing->entries[$override] ?? null;

        if ($overrideLabel === null && $listing->isAvailable()) {
            // The cashier step says this for its own stale selection; the
            // override has the same failure and is the more expensive one,
            // because the department decides the tax regime. Reachable in one
            // click now: "Reconnect to VCR.AM" is offered as the way to move a
            // store to a different register, and the override does not move
            // with it.
            return new ReadinessStep(
                __('Department override', 'vcr-am-fiscal-receipts'),
                sprintf(
                    /* translators: %d is the stored department number. */
                    __(
                        'The saved department override (#%d) is not on this register any more, so every receipt is being refused. Choose another one below, or clear the field to file each offer under its own department.',
                        'vcr-am-fiscal-receipts',
                    ),
                    $override,
                ),
                ReadinessLevel::Blocked,
            );
        }

        return new ReadinessStep(
            __('Department override', 'vcr-am-fiscal-receipts'),
            sprintf(
                /* translators: %s is the department label, or its number when the list is unavailable. */
                __(
                    'Every line of every receipt will be filed under %s, ignoring the department each offer was registered with. The department decides the tax regime printed on the receipt, and a receipt can only be refunded, never corrected.',
                    'vcr-am-fiscal-receipts',
                ),
                '<strong>' . esc_html($overrideLabel ?? '#' . (string) $override) . '</strong>',
            ),
            ReadinessLevel::Attention,
        );
    }

    /**
     * Whether a product the register has never seen can be fiscalised at all.
     *
     * This is the step that decides whether the merchant maintains a second
     * catalogue by hand. Unarmed is not broken -- a store whose catalogue is
     * fully onboarded works -- but it is the state where a newly added product
     * holds its own order, and nothing else on this screen would say so.
     */
    private function catalogStep(CatalogListing $departments): ReadinessStep
    {
        $label = __('Catalog', 'vcr-am-fiscal-receipts');
        $policy = $this->configuration->catalogPolicy();

        if (! $policy->armed()) {
            return new ReadinessStep(
                $label,
                __(
                    'A product your register has no catalog item for cannot be fiscalised: that order is held for manual review until you add the item in VCR. Set a classifier code below and the plugin creates the item itself, as it issues the receipt.',
                    'vcr-am-fiscal-receipts',
                ),
                ReadinessLevel::Attention,
            );
        }

        if ($policy->departmentInternalId === null && count($departments->entries) !== 1) {
            return new ReadinessStep(
                $label,
                __(
                    'This register has more than one department, so the plugin cannot tell which tax regime a new catalog item belongs to. Pick one under "Department for new catalog items" below; until then a product not already in the catalog holds its order.',
                    'vcr-am-fiscal-receipts',
                ),
                ReadinessLevel::Attention,
            );
        }

        return new ReadinessStep(
            $label,
            sprintf(
                /* translators: %s: the configured classifier code. */
                __('New products are added to the register\'s catalog under code %s as their first receipt is issued. Change a code per item in VCR any time — the plugin never overwrites one.', 'vcr-am-fiscal-receipts'),
                '<code>' . esc_html($policy->classifierCode ?? '') . '</code>',
            ),
            ReadinessLevel::Ready,
        );
    }

    /**
     * A store that charges shipping and has no shipping SKU cannot file the
     * shipping line, and the whole order is held. Worth saying here because
     * the merchant finds out otherwise on their first delivered order.
     */
    private function shippingStep(): ?ReadinessStep
    {
        if ($this->configuration->shippingSku() !== null) {
            return null;
        }

        // An armed catalog policy files the shipping line itself, so there is
        // nothing to warn about.
        if ($this->configuration->catalogPolicy()->armed()) {
            return null;
        }

        if (! function_exists('wc_shipping_enabled') || wc_shipping_enabled() !== true) {
            return null;
        }

        return new ReadinessStep(
            __('Shipping line', 'vcr-am-fiscal-receipts'),
            __(
                'Shipping is enabled in this store and no Shipping SKU is set, so an order that charges for delivery will be held for manual review instead of getting a receipt. Onboard one "shipping" offer in the VCR catalog and name its SKU below.',
                'vcr-am-fiscal-receipts',
            ),
            ReadinessLevel::Attention,
        );
    }

    private function externalLink(string $url, string $text): string
    {
        return sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url($url),
            esc_html($text),
        );
    }

    private function internalLink(string $url, string $text): string
    {
        return sprintf('<a href="%s">%s</a>', esc_url($url), esc_html($text));
    }
}
