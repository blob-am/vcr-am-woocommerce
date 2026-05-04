=== VCR — Fiscal Receipts for Armenia (eHDM) ===
Contributors: blobsolutions
Tags: woocommerce, armenia, fiscal, receipts, ehdm
Requires at least: 6.7
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Issue fiscal receipts (eHDM) to the Armenian tax service directly from WooCommerce orders. Multi-currency, refunds, customer-facing QR. Direct SRC integration — no third-party gateway.

== Description ==

The official WooCommerce plugin for the [VCR.AM](https://vcr.am) Virtual Cash Register. Issues Armenian fiscal receipts (e-HDM) to the State Revenue Committee directly from WooCommerce orders, refunds, and prepayments — fulfilling the obligation in Tax Code Article 380.1 (HO-280-N) and Government Decision 1976-N.

= Why this plugin =

* **Direct SRC integration.** Talks to the official VCR.AM gateway, not a third-party reseller.
* **Asynchronous fiscalization.** Uses WooCommerce Action Scheduler — customer checkout is never blocked by SRC slowness; failed transmissions retry automatically.
* **Multi-currency first-class.** Orders in USD/EUR/RUB convert to AMD using the Central Bank of Armenia rate at fiscalization time.
* **Refund-aware.** Issues partial-reversal receipts automatically when WooCommerce refunds happen.
* **Customer-facing receipt.** QR code and verification URL on the thank-you page and in transactional emails.
* **HPOS + Cart/Checkout Blocks compatible.**
* **Encrypted credentials at rest** (libsodium).

= Requirements =

* WordPress 6.7 or newer
* WooCommerce 9.4 or newer
* PHP 8.2 or newer
* A VCR.AM account and API key — sign up at [vcr.am](https://vcr.am)

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install via the WordPress plugin directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **WooCommerce → Settings → VCR** and paste your VCR.AM API key.
4. Configure per-payment-method fiscalization timing (online gateways default to "on payment confirmed"; cash-on-delivery defaults to "on order completed").

== Frequently Asked Questions ==

= Do I need a VCR.AM account? =

Yes. The plugin issues receipts through the VCR.AM gateway, which talks to the State Revenue Committee on your behalf. Sign up at [vcr.am](https://vcr.am).

= Does it support multi-currency stores? =

Yes. Orders in non-AMD currencies are converted to AMD using the Central Bank of Armenia daily rate. The rate is cached and refreshed daily; if the cache is stale (more than 48 hours), the order is flagged for manual attention rather than fiscalized at an outdated rate.

= What happens if SRC is down? =

Nothing customer-facing. The fiscal job is queued via Action Scheduler and retries with exponential backoff. The order is marked as `pending` until the receipt issues. Persistent failures surface in the admin as a notice.

= Is High-Performance Order Storage (HPOS) supported? =

Yes — declared compatible. Works with both the legacy `wp_posts` storage and the new `wc_orders` tables.

= Does it support the new block-based Checkout? =

Yes — declared compatible.

== Changelog ==

= 0.1.0 =
* Initial scaffold release. No customer-facing functionality yet — see the project roadmap on GitHub.
