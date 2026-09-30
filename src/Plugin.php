<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm;

// Above the imports on purpose: WP.org's Plugin Check only reads a file's
// first 50 lines when it looks for this guard, and the imports below run past
// that.
if (! defined('ABSPATH')) {
    exit;
}

use BlobSolutions\WooCommerceVcrAm\Admin\ConnectionTester;
use BlobSolutions\WooCommerceVcrAm\Admin\FiscalizeNowHandler;
use BlobSolutions\WooCommerceVcrAm\Admin\OrderMetaBox;
use BlobSolutions\WooCommerceVcrAm\Admin\OrdersBulkAction;
use BlobSolutions\WooCommerceVcrAm\Admin\OrdersListColumn;
use BlobSolutions\WooCommerceVcrAm\Admin\OrdersListFilter;
use BlobSolutions\WooCommerceVcrAm\Admin\PluginActionLinks;
use BlobSolutions\WooCommerceVcrAm\Admin\ReadinessPanel;
use BlobSolutions\WooCommerceVcrAm\Admin\SystemStatusReport;
use BlobSolutions\WooCommerceVcrAm\Catalog\CashierCatalog;
use BlobSolutions\WooCommerceVcrAm\Catalog\CashierListerFactory;
use BlobSolutions\WooCommerceVcrAm\Catalog\Coverage\Checker;
use BlobSolutions\WooCommerceVcrAm\Catalog\Coverage\StoreSkuReader;
use BlobSolutions\WooCommerceVcrAm\Catalog\DepartmentCatalog;
use BlobSolutions\WooCommerceVcrAm\Catalog\DepartmentListerFactory;
use BlobSolutions\WooCommerceVcrAm\Catalog\OfferListerFactory;
use BlobSolutions\WooCommerceVcrAm\Cli\CliCommands;
use BlobSolutions\WooCommerceVcrAm\Currency\CurrencyConverter;
use BlobSolutions\WooCommerceVcrAm\Currency\VcrExchangeRateProvider;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionProbe;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\IdentityReaderFactory;
use BlobSolutions\WooCommerceVcrAm\Fiscal\CashPaymentResolver;
use BlobSolutions\WooCommerceVcrAm\Fiscal\CommentBuilder;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalJob;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalQueue;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Fiscal\ItemBuilder;
use BlobSolutions\WooCommerceVcrAm\Fiscal\OrderListener;
use BlobSolutions\WooCommerceVcrAm\Fiscal\PaymentMapper;
use BlobSolutions\WooCommerceVcrAm\Fiscal\SaleRegistrarFactory;
use BlobSolutions\WooCommerceVcrAm\Migration\Migrator;
use BlobSolutions\WooCommerceVcrAm\Pairing\FailureDetail;
use BlobSolutions\WooCommerceVcrAm\Pairing\PairingClientFactory;
use BlobSolutions\WooCommerceVcrAm\Pairing\PairingNotices;
use BlobSolutions\WooCommerceVcrAm\Pairing\PairingSession;
use BlobSolutions\WooCommerceVcrAm\Pairing\ReturnHandler;
use BlobSolutions\WooCommerceVcrAm\Pairing\StartHandler;
use BlobSolutions\WooCommerceVcrAm\Privacy\PrivacyHandler;
use BlobSolutions\WooCommerceVcrAm\Receipt\CustomerReceiptDisplay;
use BlobSolutions\WooCommerceVcrAm\Receipt\ReceiptUrlBuilder;
use BlobSolutions\WooCommerceVcrAm\Refund\OrderRefundedListener;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundEligibilityChecker;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundFiscalizeNowHandler;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundJob;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundPaymentMapper;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundQueue;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundReasonMapper;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundReceiptUrlBuilder;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Refund\SaleRefundRegistrarFactory;
use BlobSolutions\WooCommerceVcrAm\Settings\AdvancedFields;
use BlobSolutions\WooCommerceVcrAm\Settings\GeneralFields;
use BlobSolutions\WooCommerceVcrAm\Settings\KeyStore;
use BlobSolutions\WooCommerceVcrAm\Settings\SettingsPage;

/**
 * Top-level plugin bootstrap.
 *
 * Wired from the plugin entry file (`vcr-am-fiscal-receipts.php`). Performs
 * three jobs:
 *
 *   1. Declares HPOS + Cart/Checkout Blocks compatibility on
 *      `before_woocommerce_init` (must run before WC's own bootstrap).
 *   2. Validates that WooCommerce is active on `plugins_loaded`. If not,
 *      surfaces an admin notice instead of fatal-erroring on missing WC
 *      classes.
 *   3. Boots subordinate services (settings page, AJAX connection tester)
 *      — only when the WC active check passes.
 *
 * Singletons / global state are deliberately avoided. The single instance
 * is held by the plugin entry file's local scope.
 */
final class Plugin
{
    /**
     * Option name under which KeyStore writes the encrypted API key
     * ciphertext. Kept here (not on KeyStore itself) so the plugin
     * controls the option namespace and KeyStore stays storage-agnostic.
     */
    private const API_KEY_OPTION = 'vcr_api_key_encrypted';

    private bool $booted = false;

    public function __construct(
        private readonly string $pluginFile,
        private readonly string $version,
    ) {
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        add_action('before_woocommerce_init', [$this, 'declareWooCommerceCompatibility']);
        add_action('plugins_loaded', [$this, 'onPluginsLoaded']);
    }

    /**
     * Tell WooCommerce we are HPOS-compatible (custom order tables, default
     * for new stores since WC 8.2) and Cart/Checkout Blocks compatible
     * (default since WC 8.3). Stores incompat on either declaration breaks
     * activation in modern WC.
     *
     * Guarded by `class_exists` so the plugin loads safely when WC is
     * absent (we surface a separate notice in that case from
     * `onPluginsLoaded`).
     */
    public function declareWooCommerceCompatibility(): void
    {
        $featuresUtil = '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil';

        if (! class_exists($featuresUtil)) {
            return;
        }

        $featuresUtil::declare_compatibility('custom_order_tables', $this->pluginFile, true);
        $featuresUtil::declare_compatibility('cart_checkout_blocks', $this->pluginFile, true);
    }

    public function onPluginsLoaded(): void
    {
        if (! class_exists('\\WooCommerce')) {
            add_action('admin_notices', [$this, 'showWooCommerceMissingNotice']);

            return;
        }

        // load_plugin_textdomain() intentionally NOT called here. Since
        // WP 4.6, plugins hosted on WordPress.org get translations
        // auto-loaded by core based on the slug + Text Domain header.
        // Calling it here would just re-do work and trigger a
        // PluginCheck.CodeAnalysis.DiscouragedFunctions warning.
        // Self-hosted distributions can drop a .mo into /languages/
        // and WP will still find it via Domain Path.

        // Migration handler runs first so any schema/option changes
        // a future version needs are in place before downstream
        // services touch them.
        $migrator = new Migrator($this->version);
        // 0.1.7 removed the "Test mode" checkbox. It wrote `vcr_test_mode`
        // and nothing ever read it: whether receipts are test receipts is a
        // property of the register, which the API reports, not something a
        // store can choose from here. The literal option name is spelled out
        // because a migration names what used to exist — there is no
        // constant for it any more, and there should not be one.
        $migrator->addMigration('0.1.7', static function (): void {
            delete_option('vcr_test_mode');
        });
        $migrator->maybeMigrate();

        // Credential storage needs libsodium — the native extension, or the
        // pure-PHP one WordPress bundles for hosts built without it. If
        // neither answers, go inert with a notice rather than let KeyStore's
        // constructor throw: this runs on `plugins_loaded`, so an uncaught
        // throw here takes down the storefront, not just the admin. Same
        // treatment the plugin entry file gives a missing autoloader.
        if (! function_exists('sodium_crypto_secretbox')) {
            add_action('admin_notices', [$this, 'showLibsodiumMissingNotice']);

            return;
        }

        $keyStore = new KeyStore(self::API_KEY_OPTION);
        $config = new Configuration($keyStore);
        $clientFactory = new VcrClientFactory(IntegrationToken::forPlugin($this->version));
        $listerFactory = new CashierListerFactory($config, $clientFactory);
        $cashierCatalog = new CashierCatalog($config, $listerFactory);
        $departmentCatalog = new DepartmentCatalog(
            $config,
            new DepartmentListerFactory($config, $clientFactory),
        );

        // Diagnostics: one probe, shared by every screen that says
        // something about the connection, so the settings checklist, the
        // status report and `wp vcr status` cannot disagree — and so a
        // single admin page costs at most one /whoami call.
        $identityFactory = new IdentityReaderFactory($config, $clientFactory);
        $probe = new ConnectionProbe($config, $identityFactory);
        $panel = new ReadinessPanel($probe, $cashierCatalog, $departmentCatalog, $config);

        (new PluginActionLinks($this->pluginFile))->register();

        (new SettingsPage(
            $keyStore,
            $cashierCatalog,
            $departmentCatalog,
            $probe,
            new GeneralFields($keyStore, $cashierCatalog, $panel),
            new AdvancedFields($departmentCatalog),
        ))->register();
        (new ConnectionTester(
            $keyStore,
            $listerFactory,
            $identityFactory,
            $this->pluginFile,
            $this->version,
        ))->register();

        // Pairing: the connect button and the two halves of the handshake it
        // runs. Registered next to ConnectionTester because they answer the
        // same question — "is this store talking to a register?" — from the
        // two ends, one testing a key the merchant typed and one fetching a
        // key so they do not have to.
        $pairingSession = new PairingSession();
        $pairingClientFactory = new PairingClientFactory(IntegrationToken::forPlugin($this->version));
        // One instance across all three: whichever half of the handshake fails
        // writes the reason, and the notice is what reads it back.
        $pairingFailure = new FailureDetail();

        (new StartHandler($config, $pairingSession, $pairingClientFactory, $pairingFailure))->register();
        (new ReturnHandler(
            $config,
            $keyStore,
            $pairingSession,
            $pairingClientFactory,
            $probe,
            $cashierCatalog,
            $departmentCatalog,
            $pairingFailure,
        ))->register();
        (new PairingNotices($pairingFailure))->register();

        // Currency converter wired once per request for the REFUND path only.
        // The sale path never converts here: foreign-currency sales send
        // per-item `currency` and the VCR converts and records the HO-234-N
        // trail server-side (see ItemBuilder / PaymentMapper). A refund
        // reverses an already-AMD receipt through the AMD-based refund
        // endpoint, so it has to name the AMD magnitude — and it asks the
        // same service for the rate, so both halves of the transaction are
        // governed by one implementation of the rule.
        $currencyConverter = new CurrencyConverter(
            new VcrExchangeRateProvider($config, $clientFactory),
        );

        $meta = new FiscalStatusMeta();
        $registrarFactory = new SaleRegistrarFactory($config, $clientFactory);
        $job = new FiscalJob(
            configuration: $config,
            registrarFactory: $registrarFactory,
            itemBuilder: new ItemBuilder(),
            paymentMapper: new PaymentMapper(),
            commentBuilder: new CommentBuilder(),
            meta: $meta,
        );
        $queue = new FiscalQueue($job, $meta);
        $queue->register();

        (new OrderListener($queue, $config, new CashPaymentResolver()))->register();
        (new FiscalizeNowHandler($meta, $queue))->register();

        // Refund flow (Phase 3e) — separate but parallel pipeline.
        $refundMeta = new RefundStatusMeta();
        $refundRegistrarFactory = new SaleRefundRegistrarFactory($config, $clientFactory);
        $refundJob = new RefundJob(
            configuration: $config,
            registrarFactory: $refundRegistrarFactory,
            paymentMapper: new RefundPaymentMapper($currencyConverter),
            reasonMapper: new RefundReasonMapper(),
            eligibilityChecker: new RefundEligibilityChecker($meta),
            refundMeta: $refundMeta,
            fiscalMeta: $meta,
        );
        $refundQueue = new RefundQueue($refundJob, $refundMeta);
        $refundQueue->register();
        (new OrderRefundedListener($refundQueue))->register();
        (new RefundFiscalizeNowHandler($refundMeta, $refundQueue))->register();

        // OrderMetaBox renders BOTH sale and refund fiscal status — wired
        // after refund meta is constructed so the per-refund block has data.
        (new OrderMetaBox($meta, $refundMeta))->register();

        // Orders list table — fiscal status column visible at WC → Orders.
        (new OrdersListColumn($meta))->register();
        (new OrdersListFilter())->register();
        (new OrdersBulkAction($meta, $queue))->register();

        // WooCommerce → Status → System Status — surface plugin config +
        // queue health for support staff.
        (new SystemStatusReport($this->version, $config, $probe))->register();

        // GDPR personal-data exporter + eraser. We always retain fiscal
        // records (Armenian tax law mandates retention) and report
        // accordingly via WP's privacy tooling.
        (new PrivacyHandler($meta, $refundMeta))->register();

        // WP-CLI commands (only loaded under wp-cli). The coverage check is
        // built here rather than above because walking the product catalogue
        // is a command, not something a page render should be able to start.
        if (defined('WP_CLI') && WP_CLI) {
            $coverage = new Checker(
                $config,
                new OfferListerFactory($config, $clientFactory),
                new StoreSkuReader($config),
            );
            (new CliCommands($config, $probe, $meta, $queue, $refundMeta, $refundQueue, $coverage))->register();
        }

        $receiptUrlBuilder = new ReceiptUrlBuilder($config, $meta);
        $refundReceiptUrlBuilder = new RefundReceiptUrlBuilder($receiptUrlBuilder, $refundMeta);
        (new CustomerReceiptDisplay($receiptUrlBuilder, $refundReceiptUrlBuilder))->register();
    }

    public function showWooCommerceMissingNotice(): void
    {
        echo '<div class="notice notice-error"><p>';
        echo esc_html(
            __(
                'VCR — Fiscal Receipts for Armenia requires WooCommerce to be installed and active.',
                'vcr-am-fiscal-receipts',
            ),
        );
        echo '</p></div>';
    }

    public function showLibsodiumMissingNotice(): void
    {
        echo '<div class="notice notice-error"><p>';
        echo esc_html(
            __(
                'VCR — Fiscal Receipts for Armenia cannot store your API key: this server has no libsodium. Ask your host to enable the PHP sodium extension.',
                'vcr-am-fiscal-receipts',
            ),
        );
        echo '</p></div>';
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getPluginFile(): string
    {
        return $this->pluginFile;
    }

    /**
     * Plugin deactivation hook callback. Drains pending Action Scheduler
     * jobs in our `vcr` group so they don't fire after the plugin's
     * action callbacks have been unregistered (which would mark them
     * `failed` indefinitely and bloat `wp_actionscheduler_*` tables).
     *
     * Static so `register_deactivation_hook` can reference it without
     * needing the plugin instance — the hook fires from a separate
     * sub-request that doesn't have our bootstrap state.
     *
     * Does NOT touch:
     *   - Plugin options (uninstall.php handles that on actual delete).
     *   - Order meta (statutory retention applies — see PrivacyHandler).
     *   - Action Scheduler tables themselves (cleanup is per-action).
     */
    public static function onDeactivation(): void
    {
        if (! function_exists('as_unschedule_all_actions')) {
            return;
        }

        // Drain both pipelines explicitly. AS's `null` hook + group
        // scoping is documented but the function signature insists on a
        // string, so we walk our two known hooks rather than fight the
        // type. Better in any case — a future hook that should NOT be
        // drained on deactivation has to be added explicitly.
        as_unschedule_all_actions(FiscalQueue::ACTION_HOOK, [], FiscalQueue::ACTION_GROUP);
        as_unschedule_all_actions(RefundQueue::ACTION_HOOK, [], RefundQueue::ACTION_GROUP);
    }
}
